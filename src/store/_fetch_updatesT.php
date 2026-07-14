<?php
//_fetch_updates.php

// 啟用錯誤報告（僅限除錯階段）
ini_set('display_errors', 1);
error_reporting(E_ALL);

// 引入資料庫連線檔案
include '../../src/common/DBConnection.php';
$conn = new DBConnection();

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
        $orderIdsFilter = "AND Order_id IN (" . implode(',', $filteredIds) . ")";
    }
}

// 如果沒有有效的訂單ID，返回空數組
if (empty($orderIdsFilter) && isset($_GET['orderIds'])) {
    header('Content-Type: application/json');
    echo json_encode([]);
    exit;
}

// 撰寫查詢語句：僅選擇需要更新的欄位和條件
$query = "
    SELECT 
        Order_id, 
        DATE_FORMAT(pmGet, '%c/%e') AS pmGet 
    FROM 
        order_track 
    WHERE 
        pmGet IS NOT NULL -- 僅返回有轉生管日期的訂單
        $orderIdsFilter -- 添加訂單ID過濾條件
    ORDER BY 
        Order_id DESC -- 根據需要排序
";

// 查詢資料並取得結果
$result = $conn->getAll($query);

// 檢查是否有查詢結果
if ($result === false) {
    // 資料庫錯誤時返回空數組，避免前端解析錯誤
    header('Content-Type: application/json');
    echo json_encode([]);
    exit;
}

// 如果沒有符合條件的記錄，返回空數組
if (empty($result)) {
    header('Content-Type: application/json');
    echo json_encode([]);
    exit;
}

// 處理結果，將 null 值替換為空字串
foreach ($result as &$row) {
    foreach ($row as $key => $value) {
        if (is_null($value)) {
            $row[$key] = ""; // 將 null 替換為空字串
        }
    }
}

// 將結果格式化為 JSON 並輸出
header('Content-Type: application/json');
echo json_encode($result);
exit;