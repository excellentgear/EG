<?php
// _end_bom_manual.php
session_start();

// Basic security check
if (!isset($_SESSION['userName'])) {
    header('HTTP/1.1 401 Unauthorized');
    echo json_encode(['success' => false, 'message' => '未授權的操作。']);
    exit;
}

include '../../src/common/DBConnection.php';
include '../../src/common/_config.php';

header('Content-Type: application/json');
ob_clean(); // Clean any previous output

$response = ['success' => false, 'message' => ''];

if (!isset($db)) {
    $response['message'] = '資料庫連線失敗。';
    echo json_encode($response);
    exit;
}

if (!isset($_POST['bom']) || empty(trim($_POST['bom']))) {
    $response['message'] = '錯誤：缺少 BOM 參數。';
    echo json_encode($response);
    exit;
}

$bom_to_update = trim($_POST['bom']);
$close_reason  = isset($_POST['close_reason']) ? trim($_POST['close_reason']) : '';
$operator_id   = isset($_SESSION['id']) && is_numeric($_SESSION['id']) ? (int)$_SESSION['id'] : null;
$modified_by   = $_SESSION['id'] ?? 'system_manual_close';

try {
    // 結案前檢查：是否有尚未轉移完成、也未標記跳過的製程（不自動補轉移，只攔一道+要求填原因）
    $chkStmt = $db->prepare("SELECT bi.bom_sn, bi.process_no, bi.processing_state, pn.ProcessName
            FROM bom_ing bi
            LEFT JOIN process_no pn ON bi.process_no = pn.ProcessNo
            WHERE bi.bom = :bom AND bi.is_consumed = 0
              AND bi.processing_state NOT IN ('E','1','skip')
            ORDER BY bi.bom_sn");
    $chkStmt->bindParam(':bom', $bom_to_update, PDO::PARAM_STR);
    $chkStmt->execute();
    $unfinished = $chkStmt->fetchAll(PDO::FETCH_ASSOC);

    if (!empty($unfinished) && $close_reason === '') {
        $response['success'] = false;
        $response['need_confirmation'] = true;
        $response['unfinished'] = $unfinished;
        $response['message'] = '此 BOM 尚有製程未轉移完成或標記跳過，請確認清單並填寫原因後再結案。';
        echo json_encode($response);
        exit;
    }

    $stmt = $db->prepare("UPDATE bom
            SET processing_state = '1',
                bom_ing_id       = '1',
                closed_by        = :closed_by,
                closed_at        = NOW(),
                Modified_At      = NOW(),
                Modified_By      = :modified_by
            WHERE bom = :bom");
    $stmt->bindValue(':closed_by',   $operator_id,   $operator_id ? PDO::PARAM_INT : PDO::PARAM_NULL);
    $stmt->bindParam(':modified_by', $modified_by,   PDO::PARAM_STR);
    $stmt->bindParam(':bom',         $bom_to_update, PDO::PARAM_STR);

    if ($stmt->execute()) {
        if ($stmt->rowCount() > 0) {
            // 寫入操作流水帳
            try {
                $logDetails = ['操作' => '人工結案'];
                if (!empty($unfinished)) {
                    $logDetails['尚未完成製程'] = array_map(function($u) {
                        return ($u['ProcessName'] ?: $u['process_no']) . '(' . $u['processing_state'] . ')';
                    }, $unfinished);
                    $logDetails['結案原因'] = $close_reason;
                }
                $logStmt = $db->prepare("INSERT INTO bom_operation_log (bom, operation_type, operator_id, details_json) VALUES (?, 'manual_close', ?, ?)");
                $logStmt->execute([$bom_to_update, $operator_id, json_encode($logDetails, JSON_UNESCAPED_UNICODE)]);
            } catch (PDOException $le) {
                error_log("bom_operation_log insert error: " . $le->getMessage());
            }
            $response['success'] = true;
            $response['message'] = 'BOM ' . htmlspecialchars($bom_to_update) . ' 已手動結案。';
        } else {
            $response['message'] = '結案失敗：找不到指定的 BOM 或資料無變更。';
        }
    } else {
        $errorInfo = $stmt->errorInfo();
        $response['message'] = '資料庫更新失敗：' . $errorInfo[2];
        error_log("Manual BOM close error: " . $errorInfo[2]);
    }

} catch (PDOException $e) {
    $response['message'] = '資料庫操作錯誤：' . $e->getMessage();
    error_log("Manual BOM close PDOException: " . $e->getMessage());
}

echo json_encode($response);
exit;
?>