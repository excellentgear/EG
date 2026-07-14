<?php
session_start();
// 錯誤報告設置
error_reporting(E_ALL);
ini_set('display_errors', 1);

// 檢查用戶是否已登錄
if (!isset($_SESSION['userName'])) {
    echo 'error: 未授權的訪問';
    exit;
}

// 檢查是否有必要的參數
if (!isset($_POST['Order_id']) || empty($_POST['Order_id']) || 
    !isset($_POST['field']) || empty($_POST['field'])) {
    echo 'error: 缺少必要參數';
    exit;
}

// 包含數據庫連接
include '../common/DBConnection.php';
include '../common/_config.php';

try {
    $conn = new DBConnection();
    $order_id = trim($_POST['Order_id']);
    $field = trim($_POST['field']);
    $value = isset($_POST['value']) ? $_POST['value'] : '';
    
    // 檢查字段是否允許更新
    $allowed_fields = ['Order_ps', 'ateNote', 'Order_oo', 'Client_name', 'C_order', 'd_id', 'Processing_items', 'Qty', 'Containers', 'drop_zone', 'Sample', 'JIG'];
    
    if (!in_array($field, $allowed_fields)) {
        echo 'error: 不允許更新此字段';
        exit;
    }
    
    // 更新字段
    $result = $conn->execute("UPDATE order_track SET $field = ? WHERE Order_id = ?", [$value, $order_id]);
    
    if ($result) {
        echo 'success';
    } else {
        echo 'error: 更新失敗';
    }
    
} catch (Exception $e) {
    echo 'error: ' . $e->getMessage();
    exit;
}
?>
