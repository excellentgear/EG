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

// SQL 查詢語法
$query = "SELECT 
              order_list.*,
              CONCAT(DATE_FORMAT(order_list.Order_date, '%y'), 'y/', DATE_FORMAT(order_list.Order_date, '%c/%e')) AS Order_date,
              CONCAT(DATE_FORMAT(order_list.Delivery_date, '%y'), 'y/', DATE_FORMAT(order_list.Delivery_date, '%c/%e')) AS Delivery_date_T,
              DATE_FORMAT(order_list.ateGet, '%c/%e') AS ateGet,DATE_FORMAT(ot.Created_At, '%c/%e') AS Created_At,
              DATE_FORMAT(order_list.pmGet, '%c/%e') AS pmGet,
              user.user_cname
          FROM order_list
          LEFT JOIN user ON user.id = order_list.ate
          ORDER BY order_list.Order_date DESC, order_list.Client_name ASC;";

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