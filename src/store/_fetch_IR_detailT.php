<?php
// _fatch_order_detail.php
session_start();
include '../../src/common/DBConnection.php';
$conn = new DBConnection();

// 檢查是否傳入 Order_id
if (!isset($_GET['oi'])) {
    echo json_encode(array('error' => '缺少 IR_id 參數'));
    exit;
}
$IR_id = intval($_GET['oi']);

// 查資料
$results = $conn->getAll("SELECT * FROM `ir_track` WHERE `IR_id` = " . $IR_id);

// 檢查是否有結果
if (empty($results)) {
    echo json_encode(array('error' => '找不到訂單'));
    exit;
}

// 取得第一筆資料
$orderData = $results[0];

// 將資料中的 null 值替換為空字串
foreach ($orderData as $key => $value) {
    if (is_null($value)) {
        $orderData[$key] = ""; // 將 null 替換為空字串
    }
}

// 準備回傳的響應資料
$response = array(
    'Order_id'           => $orderId,
    'OrderNo'            => $orderData['Order_oo'],
    'orderindate'        => $orderData['Order_date'],
    'orderDdate'         => $orderData['Delivery_date'],
    'Client_Name'        => $orderData['Client_name'],
    'Client_OrderNo'     => $orderData['C_order'],
    'd_id'               => $orderData['d_id'],
    'Process'            => $orderData['Processing_items'],
    'Qty'                => $orderData['Qty'],
    'datepicker_ate'     => $orderData['ateGet'],
    'drop_zone'          => $orderData['drop_zone'],
    'Containers'         => $orderData['Containers'],
    'sample'             => $orderData['Sample'],
    'jig'                => $orderData['JIG'],
    'Order_ps'           => $orderData['Order_ps'],
    'ateNote'            => $orderData['ateNote'],
    'Created_At'         => $orderData['Created_At'],
    'ate'                => $orderData['ate']
);

// 設定回應的 Content-Type 並輸出 JSON 資料
header('Content-Type: application/json');
echo json_encode($response);
exit;
?>