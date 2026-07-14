<?php
// update_ps.php

// 啟動 session 以便獲取使用者資訊
session_start();

// 開啟錯誤回報（除錯階段）
ini_set('display_errors', 0); // Suppress errors from breaking JSON output
error_reporting(E_ALL);

// 載入必要的設定與資料庫連線檔案
include '../../src/common/DBConnection.php';
// include '../../src/store/_setting.php'; // This include might cause issues if it outputs HTML or tries to redirect.
include '../../src/common/_config.php';

// Set content type to JSON and clean output buffer
header('Content-Type: application/json');
ob_clean();

$response = ['success' => false, 'message' => ''];

// 確認資料庫連線物件 $db 是否存在（$db 為 PDO 物件）
if (!isset($db)) {
    $response['message'] = '資料庫連線失敗';
    echo json_encode($response);
    exit;
}

// 檢查是否有正確傳入所需參數
if (!isset($_POST['bom']) || !array_key_exists('bom_ps', $_POST)) { // bom_ps can be an empty string
    $response['message'] = '缺少必要的更新參數 (BOM 或 BOM備註)';
    echo json_encode($response);
    exit;
}

$bom_identifier_from_post = trim($_POST['bom']); // 修改：接收 bom
$bom_ps_value_from_post = $_POST['bom_ps'];      // 修改：接收 bom_ps
$modified_by_user_id = $_SESSION['id'] ?? 'system_bom_ps_update'; // 修改：調整預設值以反映目標

// 準備 SQL 語法，採用命名參數方式
try {
    // 修改：更新 bom 資料表的 bom_ps 欄位
    $query = "UPDATE bom SET bom_ps = :bom_ps_val, Modified_At = NOW(), Modified_By = :modified_by_val WHERE bom = :bom_id_val";
    $stmt = $db->prepare($query);

    // 修改：綁定新的參數
    $stmt->bindValue(':bom_ps_val', $bom_ps_value_from_post, PDO::PARAM_STR);
    $stmt->bindValue(':bom_id_val', $bom_identifier_from_post, PDO::PARAM_STR); // 假設 bom 欄位是字串類型
    $stmt->bindValue(':modified_by_val', $modified_by_user_id, PDO::PARAM_STR);

    // 執行更新
    if ($stmt->execute()) {
        $rowCount = $stmt->rowCount();
        if ($rowCount > 0) {
            $response['success'] = true;
            $response['message'] = 'BOM備註已更新';
        } else {
            // 修改：檢查 BOM 是否存在，以提供更精確的「無變更」或「未找到」訊息
            $checkExistStmt = $db->prepare("SELECT COUNT(*) FROM bom WHERE bom = :bom_check");
            $checkExistStmt->bindParam(':bom_check', $bom_identifier_from_post, PDO::PARAM_STR);
            $checkExistStmt->execute();
            if ($checkExistStmt->fetchColumn() == 0) {
                $response['message'] = "BOM備註更新失敗：找不到指定的 BOM (" . htmlspecialchars($bom_identifier_from_post) . ")。";
            } else {
                $response['success'] = true; // Treat as success if data is same
                $response['message'] = 'BOM備註資料相同，未進行更新';
            }
        }
    } else {
        $errorInfo = $stmt->errorInfo();
        $response['message'] = "BOM備註更新失敗: " . $errorInfo[2]; // 修改：回應訊息
        error_log("BOM_PS Update SQL Error: " . $errorInfo[2] . " for BOM: " . $bom_identifier_from_post);
    }
} catch (PDOException $e) {
    $response['message'] = "資料庫錯誤: " . $e->getMessage();
    error_log("BOM_PS Update PDOException: " . $e->getMessage() . " for BOM: " . $bom_identifier_from_post);
}

echo json_encode($response);
exit;
?>
