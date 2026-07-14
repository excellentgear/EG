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
    IR_track.*,
    CONCAT(DATE_FORMAT(IR_track.IR_date, '%y'), 'y/', DATE_FORMAT(IR_track.IR_date, '%c/%e')) AS IR_date_T,
    DATE_FORMAT(IR_track.QCGet, '%c/%e') AS QCGet_T,
    DATE_FORMAT(IR_track.pmGet, '%c/%e') AS pmGet_T,
    DATE_FORMAT(IR_track.ateGet, '%c/%e') AS ateGet_T,
    DATE_FORMAT(IR_track.bossGet, '%c/%e') AS bossGet_T,
    DATE_FORMAT(IR_track.Closed_At, '%c/%e') AS Closed_T,
    DATE_FORMAT(IR_track.Created_At, '%c/%e') AS Created_At_T,
    DATE_FORMAT(IR_track.in_review, '%c/%e') AS in_review_T,
    user.user_cname
FROM IR_track
LEFT JOIN user ON user.id = IR_track.QC_Assignee
WHERE YEAR(IR_track.IR_date) = {$selectedYear}
ORDER BY
    COALESCE(IR_track.Modified_At, IR_track.Created_At) DESC;";

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