<?php
if (!isset($_SESSION)){
    session_start();
}

include_once '../common/DBConnection.php';
include '../common/_config.php';

header('Content-Type: application/json'); // 設定回應類型為 JSON

$response = ['success' => false, 'message' => '']; // 初始化回應陣列

if (isset($_POST['bif'])) {
    $bif = $_POST['bif'];

    try {
        // 查詢目前狀態及 qc_completed 資訊
        $stateStmt = $db->prepare("SELECT processing_state, qc_completed, qc_completed_at FROM bom_ing WHERE bom_ing_fid = ?");
        $stateStmt->execute([$bif]);
        $currentRow = $stateStmt->fetch(PDO::FETCH_ASSOC);

        // 建立 SET 子句；若 qc_completed=1 同步補寫 QC_check_date 與 QC_check
        $setClauses = ["processing_state = 'E'", "Modified_At = NOW()"];
        $params = [':bif' => $bif];

        if ($currentRow && (int)$currentRow['qc_completed'] === 1 && !empty($currentRow['qc_completed_at'])) {
            $setClauses[] = 'QC_check_date = :qcd';
            $params[':qcd'] = $currentRow['qc_completed_at'];

            // 取 qc_check 最新一筆的 QC_check 值
            $qcStmt = $db->prepare("SELECT QC_check FROM qc_check WHERE bom_ing_fid_ref = ? ORDER BY QC_check_date DESC LIMIT 1");
            $qcStmt->execute([$bif]);
            $qcRow = $qcStmt->fetch(PDO::FETCH_ASSOC);
            if ($qcRow && !empty($qcRow['QC_check'])) {
                $setClauses[] = 'QC_check = :qc';
                $params[':qc'] = $qcRow['QC_check'];
            }
        }

        $sth = $db->prepare("UPDATE bom_ing SET " . implode(', ', $setClauses) . " WHERE bom_ing_fid = :bif");

        if ($sth->execute($params)) {
            if ($sth->rowCount() > 0) {
                $response['success'] = true;
                $response['message'] = "記錄已成功更新，狀態已設為 'E' (已移轉)。";
            } else {
                // 檢查是否狀態本來就是 'E'
                $checkSth = $db->prepare("SELECT COUNT(*) FROM bom_ing WHERE bom_ing_fid = :bif AND processing_state = 'E'");
                $checkSth->bindParam(':bif', $bif, PDO::PARAM_STR);
                $checkSth->execute();
                if ($checkSth->fetchColumn() > 0) {
                    $response['success'] = true;
                    $response['message'] = "記錄狀態已是 'E' (已移轉)，無需更新。";
                } else {
                    $response['message'] = "沒有記錄被更新。可能原因：找不到對應的 bom_ing_fid '" . htmlspecialchars($bif) . "'。";
                }
            }
        } else {
            $errorInfo = $sth->errorInfo();
            $response['message'] = "SQL 執行失敗: " . htmlspecialchars($errorInfo[2]);
        }
    } catch (PDOException $e) {
        $response['message'] = "資料庫操作錯誤: " . htmlspecialchars($e->getMessage());
    }
} else {
    $response['message'] = "錯誤：未提供 bom_ing_fid (bif) 參數。";
}

echo json_encode($response); // 輸出 JSON 回應
exit;
?>
