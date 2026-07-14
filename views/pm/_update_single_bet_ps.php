<?php
// _update_single_bet_ps.php

// 啟動 session 以便獲取使用者資訊
session_start();

// 載入必要的設定與資料庫連線檔案
include '../../src/common/DBConnection.php';
include '../../src/common/_config.php';

// 設定內容類型為 JSON 並清理輸出緩衝區
header('Content-Type: application/json');
ob_clean();

$response = ['success' => false, 'message' => ''];

// 確認資料庫連線物件 $db 是否存在
if (!isset($db)) {
    $response['message'] = '資料庫連線失敗';
    echo json_encode($response);
    exit;
}

// 檢查是否有正確傳入所需參數
// 前端應傳送 'bom_ing_fid' 和 'single_bet_ps'
if (!isset($_POST['bom_ing_fid']) || !array_key_exists('single_bet_ps', $_POST)) {
    $response['message'] = '缺少必要的更新參數 (bom_ing_fid 或 single_bet_ps)';
    echo json_encode($response);
    exit;
}

$bom_ing_fid_from_post = trim($_POST['bom_ing_fid']);
$ps_value_from_post = $_POST['single_bet_ps'];
$modified_by_user_id = $_SESSION['id'] ?? 'system_single_bet_ps_update';

// 準備 SQL 語法，採用命名參數方式
try {
    // 更新 bom_ing 資料表的 single_bet_ps 欄位
    $query = "UPDATE bom_ing SET single_bet_ps = :ps_val, Modified_At = NOW(), Modified_By = :modified_by_val WHERE bom_ing_fid = :fid_val";
    $stmt = $db->prepare($query);

    // 綁定新的參數
    $stmt->bindValue(':ps_val', $ps_value_from_post, PDO::PARAM_STR);
    $stmt->bindValue(':fid_val', $bom_ing_fid_from_post, PDO::PARAM_INT); // bom_ing_fid 應為整數
    $stmt->bindValue(':modified_by_val', $modified_by_user_id, PDO::PARAM_STR);

    // 執行更新
    if ($stmt->execute()) {
        $rowCount = $stmt->rowCount();
        if ($rowCount > 0) {
            $response['success'] = true;
            $response['message'] = '製程備註已更新';
        } else {
            // 檢查 bom_ing_fid 是否存在，以提供更精確的「無變更」或「未找到」訊息
            $checkExistStmt = $db->prepare("SELECT COUNT(*) FROM bom_ing WHERE bom_ing_fid = :fid_check");
            $checkExistStmt->bindParam(':fid_check', $bom_ing_fid_from_post, PDO::PARAM_INT);
            $checkExistStmt->execute();
            if ($checkExistStmt->fetchColumn() == 0) {
                $response['message'] = "製程備註更新失敗：找不到指定的製程項目 (ID: " . htmlspecialchars($bom_ing_fid_from_post) . ")。";
            } else {
                $response['success'] = true; // 資料相同也視為成功
                $response['message'] = '製程備註資料相同，未進行更新';
            }
        }
    } else {
        $errorInfo = $stmt->errorInfo();
        $response['message'] = "製程備註更新失敗: " . $errorInfo[2];
        error_log("SINGLE_BET_PS Update SQL Error: " . $errorInfo[2] . " for bom_ing_fid: " . $bom_ing_fid_from_post);
    }
} catch (PDOException $e) {
    $response['message'] = "資料庫錯誤: " . $e->getMessage();
    error_log("SINGLE_BET_PS Update PDOException: " . $e->getMessage() . " for bom_ing_fid: " . $bom_ing_fid_from_post);
}

echo json_encode($response);
exit;
?>