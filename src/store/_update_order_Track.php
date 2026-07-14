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

// 檢查是否有訂單 ID
if (!isset($_POST['Order_id']) || empty($_POST['Order_id'])) {
    echo 'error: 缺少訂單 ID';
    exit;
}

// 包含數據庫連接
include '../common/DBConnection.php';
include '../common/_config.php';

try {
    $conn = new DBConnection();
    $order_id = trim($_POST['Order_id']);
    
    // 收集表單數據
    $order_oo = isset($_POST['OrderNo']) ? trim($_POST['OrderNo']) : '';
    $order_date = isset($_POST['orderindate']) ? trim($_POST['orderindate']) : '';
    $delivery_date = isset($_POST['orderDdate']) ? trim($_POST['orderDdate']) : '';
    $client_name = isset($_POST['Client_Name']) ? trim($_POST['Client_Name']) : '';
    $c_order = isset($_POST['Client_OrderNo']) ? trim($_POST['Client_OrderNo']) : '';
    $d_id = isset($_POST['d_id']) ? trim($_POST['d_id']) : '';
    $processing_items = isset($_POST['Process']) ? trim($_POST['Process']) : '';
    $qty = isset($_POST['Qty']) ? trim($_POST['Qty']) : '';
    $datepicker_ate = isset($_POST['datepicker_ate']) ? trim($_POST['datepicker_ate']) : '';
    $ate = isset($_POST['ate']) ? trim($_POST['ate']) : '';
    $drop_zone = isset($_POST['drop_zone']) ? trim($_POST['drop_zone']) : '';
    $containers = isset($_POST['Containers']) ? trim($_POST['Containers']) : '';
    $sample = isset($_POST['sample']) ? trim($_POST['sample']) : '';
    $jig = isset($_POST['jig']) ? trim($_POST['jig']) : '';
    $order_ps = isset($_POST['Order_ps']) ? trim($_POST['Order_ps']) : '';
    
    // 日期格式檢查與轉換
    $order_date_sql = !empty($order_date) ? date('Y-m-d', strtotime($order_date)) : null;
    $delivery_date_sql = !empty($delivery_date) ? date('Y-m-d', strtotime($delivery_date)) : null;
    $datepicker_ate_sql = !empty($datepicker_ate) ? date('Y-m-d', strtotime($datepicker_ate)) : null;
    
    // 更新訂單
    $sql = "UPDATE order_track SET 
            Order_oo = ?, 
            Order_date = ?, 
            Delivery_date = ?, 
            Client_name = ?, 
            C_order = ?, 
            d_id = ?, 
            Processing_items = ?, 
            Qty = ?, 
            ateGet = ?, 
            ate = ?, 
            drop_zone = ?, 
            Containers = ?, 
            Sample = ?, 
            JIG = ?, 
            Order_ps = ?
            WHERE Order_id = ?";
    
    $params = [
        $order_oo, 
        $order_date_sql, 
        $delivery_date_sql, 
        $client_name, 
        $c_order, 
        $d_id, 
        $processing_items, 
        $qty, 
        $datepicker_ate_sql, 
        $ate, 
        $drop_zone, 
        $containers, 
        $sample, 
        $jig, 
        $order_ps, 
        $order_id
    ];
    
    $result = $conn->execute($sql, $params);
    
    if ($result) {
        echo 'success: 訂單更新成功';
    } else {
        echo 'error: 訂單更新失敗';
    }
    
} catch (Exception $e) {
    echo 'error: ' . $e->getMessage();
    exit;
}
?>
