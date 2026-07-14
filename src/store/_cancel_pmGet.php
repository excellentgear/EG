<?php
// 啟用錯誤顯示（僅用於開發環境）
ini_set('display_errors', 1);
error_reporting(E_ALL);

// 確保會話開啟
if (!isset($_SESSION)) {
    session_start();
}

// 包含數據庫連接
include_once("../common/_config.php");
require_once("../common/DBConnection.php");

// 確保輸出是JSON格式
header('Content-Type: application/json');

// 檢查用戶是否已登錄
if (!isset($_SESSION['userName'])) {
    echo json_encode(['success' => false, 'message' => '未授權訪問']);
    exit;
}

// 檢查是否有訂單ID
if (!isset($_POST['Order_id']) || empty($_POST['Order_id'])) {
    echo json_encode(['success' => false, 'message' => '未提供訂單ID']);
    exit;
}

// 獲取訂單ID
$orderId = intval($_POST['Order_id']);

try {
    // 使用DBConnection實例連接數據庫
    $conn = new DBConnection();
    
    // 設置pmGet為NULL的SQL查詢
    $sql = "UPDATE order_track SET pmGet = NULL WHERE Order_id = $orderId";
    
    // 執行更新
    $result = $conn->execute($sql);
    
    // 檢查結果
    if ($result !== false) {
        echo json_encode([
            'success' => true, 
            'message' => '已成功取消轉生管日期'
        ]);
    } else {
        echo json_encode([
            'success' => false, 
            'message' => '取消轉生管日期失敗'
        ]);
    }
    
} catch (Exception $e) {
    // 返回錯誤訊息
    $error_msg = $e->getMessage();
    error_log("Error in _cancel_pmGet.php: " . $error_msg);
    echo json_encode(['success' => false, 'message' => $error_msg]);
}
?> 