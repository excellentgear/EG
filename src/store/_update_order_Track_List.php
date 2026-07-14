<?php
session_start();
// 錯誤報告設置
error_reporting(E_ALL);
ini_set('display_errors', 1);

// 檢查用戶是否已登錄
if (!isset($_SESSION['userName'])) {
    echo json_encode(['success' => false, 'message' => '未授權的訪問']);
    exit;
}

// 包含數據庫連接
include '../common/DBConnection.php';
include '../common/_config.php';

try {
    $conn = new DBConnection();
    
    // 確保請求包含訂單ID
    if (!isset($_POST['Order_id']) || empty($_POST['Order_id'])) {
        echo json_encode(['success' => false, 'message' => '缺少訂單ID參數']);
        exit;
    }
    
    // 安全地轉換訂單ID為整數
    $order_id = intval($_POST['Order_id']);
    
    // 檢查ID是否為有效數字
    if ($order_id <= 0) {
        echo json_encode(['success' => false, 'message' => '無效的訂單ID']);
        exit;
    }
    
    // 獲取當前用戶ID
    $userId = isset($_SESSION['id']) ? $_SESSION['id'] : null;
    
    // 檢查是否為取消操作
    $action = isset($_POST['action']) ? $_POST['action'] : 'update';
    
    // 獲取其他欄位的值
    $drop_zone = isset($_POST['drop_zone']) ? $_POST['drop_zone'] : null;
    $containers = isset($_POST['Containers']) ? $_POST['Containers'] : null;
    $sample = isset($_POST['Sample']) ? $_POST['Sample'] : null;
    $jig = isset($_POST['JIG']) ? $_POST['JIG'] : null;
    
    // 記錄接收到的數據，方便除錯
    error_log("接收到的數據: Order_id={$order_id}, drop_zone={$drop_zone}, Containers={$containers}, Sample={$sample}, JIG={$jig}");
    
    if ($action === 'cancel') {
        // 清除轉生管日期
        $sql = "UPDATE order_track SET pmGet = NULL WHERE Order_id = :order_id";
        $params = [':order_id' => $order_id];
        $result = $conn->execute($sql, $params);
        
        if ($result !== false) {
            echo json_encode(['success' => true, 'message' => '轉生管標記已成功取消']);
        } else {
            echo json_encode(['success' => false, 'message' => '取消轉生管失敗']);
        }
    } else {
        // 設置當前日期為轉生管日期，並更新其他欄位
        $currentDate = date('Y-m-d H:i:s');
        
        // 構建 SQL 查詢
        $sql = "UPDATE order_track SET pmGet = :pmGet";
        $params = [':pmGet' => $currentDate, ':order_id' => $order_id];
        
        // 添加其他欄位的更新
        if ($drop_zone !== null) {
            $sql .= ", drop_zone = :drop_zone";
            $params[':drop_zone'] = $drop_zone;
        }
        
        if ($containers !== null) {
            $sql .= ", Containers = :containers";
            $params[':containers'] = $containers;
        }
        
        if ($sample !== null) {
            $sql .= ", Sample = :sample";
            $params[':sample'] = $sample;
        }
        
        if ($jig !== null) {
            $sql .= ", JIG = :jig";
            $params[':jig'] = $jig;
        }
        
        // 添加 WHERE 條件
        $sql .= " WHERE Order_id = :order_id";
        
        // 執行查詢
        $result = $conn->execute($sql, $params);
        
        if ($result !== false) {
            echo json_encode(['success' => true, 'message' => '轉生管日期和其他欄位已成功更新']);
        } else {
            echo json_encode(['success' => false, 'message' => '更新失敗']);
        }
    }
    
} catch (Exception $e) {
    // 記錄錯誤但不向客戶端暴露詳細資訊
    error_log('Error in _update_order_Track_List.php: ' . $e->getMessage());
    echo json_encode(['success' => false, 'message' => '處理請求時發生錯誤']);
    exit;
}
?> 