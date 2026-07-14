<?php
// _fetch_order_data.php

// 開啟錯誤回報（除錯階段）
ini_set('display_errors', 1);
error_reporting(E_ALL);

// 載入必要的設定與資料庫連線檔案
include '../../src/common/DBConnection.php';
include '../../src/common/_config.php';

// 建立資料庫連線物件
$conn = new DBConnection();

// 獲取年份參數，如果未提供則使用當前年份
$selectedYear = isset($_GET['year']) ? intval($_GET['year']) : date('Y');
// 確保年份有效（2024年到當前年份）
$selectedYear = ($selectedYear < 2024 || $selectedYear > date('Y')) ? date('Y') : $selectedYear;

// 檢查是否有提供訂單ID列表參數
$orderIdsFilter = "";
if (isset($_GET['orderIds']) && !empty($_GET['orderIds'])) {
    // 分割並過濾訂單ID，防止SQL注入
    $orderIdsArr = explode(',', $_GET['orderIds']);
    $filteredIds = [];
    
    foreach ($orderIdsArr as $id) {
        $id = trim($id);
        if (is_numeric($id)) {
            $filteredIds[] = intval($id);
        }
    }
    
    if (!empty($filteredIds)) {
        $orderIdsFilter = "AND order_track.Order_id IN (" . implode(',', $filteredIds) . ")";
    }
}

// SQL 查詢語法
$query = "SELECT 
            order_track.*,
            CONCAT(DATE_FORMAT(order_track.Order_date, '%y'), 'y/', DATE_FORMAT(order_track.Order_date, '%c/%e')) AS Order_date,
            CONCAT(DATE_FORMAT(order_track.Delivery_date, '%y'), 'y/', DATE_FORMAT(order_track.Delivery_date, '%c/%e')) AS Delivery_date_T,
            DATE_FORMAT(order_track.ateGet, '%c/%e') AS ateGet,DATE_FORMAT(order_track.Created_At, '%c/%e') AS Created_At,
            DATE_FORMAT(order_track.pmGet, '%c/%e') AS pmGet,
            user.user_cname
        FROM order_track
        LEFT JOIN user ON user.id = order_track.ate
        WHERE YEAR(order_track.Order_date) = {$selectedYear}
        {$orderIdsFilter}
        ORDER BY order_track.Order_date DESC, order_track.Client_name ASC;";

// 取得查詢結果（請確保 DBConnection 類別中有 getAll() 方法）
$order_list = $conn->getAll($query);

// 檢查並處理 null 值
foreach ($order_list as &$order) {
    foreach ($order as $key => $value) {
        if (is_null($value)) {
            $order[$key] = ""; // 將 null 替換為空字串
        }
    }
}

// 將結果以 JSON 格式輸出
header('Content-Type: application/json');
echo json_encode($order_list);