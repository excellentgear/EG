<?php
// 啟用錯誤報告
error_reporting(E_ALL);
ini_set('display_errors', 1);

// 開始會話
session_start();

// 包含必要文件
include '../common/DBConnection.php';
include '../common/_config.php';

// 輸出結果
header('Content-Type: application/json');

try {
    // 檢查用戶是否已登錄
    if (!isset($_SESSION['userName'])) {
        echo json_encode(['success' => false, 'message' => '未授權的訪問']);
        exit;
    }

    // 獲取數據庫連接
    $conn = new DBConnection();
    $pdo = $conn->getPDO(); // Get PDO instance for prepared statements
    
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
    
    // 檢查是否為取消操作
    $action = isset($_POST['action']) ? $_POST['action'] : 'update';
    
    if ($action === 'cancel') {
        // 清除轉生管日期
        $stmt = $pdo->prepare("UPDATE order_track SET pmGet = NULL WHERE Order_id = ?");
        $result = $stmt->execute([$order_id]);
        
        if ($result && $stmt->rowCount() > 0) {
            // Fetch the current in_review_date to return it formatted
            $stmt_fetch_ir = $pdo->prepare("SELECT DATE_FORMAT(in_review, '%c/%e') AS in_review_date FROM order_track WHERE Order_id = ?");
            $stmt_fetch_ir->execute([$order_id]);
            $ir_date_result = $stmt_fetch_ir->fetch(PDO::FETCH_ASSOC);

            echo json_encode([
                'success' => true,
                'message' => '轉生管標記已成功取消',
                'in_review_date' => $ir_date_result ? $ir_date_result['in_review_date'] : null
            ]);
        } else {
            echo json_encode(['success' => false, 'message' => '取消轉生管失敗或無變更。']);
        }
    } else {
        // 設置當前日期為轉生管日期
        // $currentDate = date('Y-m-d H:i:s'); // Using CURDATE() in SQL is better
        $stmt = $pdo->prepare("UPDATE order_track SET pmGet = CURDATE() WHERE Order_id = ?");
        $result = $stmt->execute([$order_id]);
        
        if ($result) {
            // Fetch the newly set date to return it formatted
            $stmt_fetch = $pdo->prepare("SELECT DATE_FORMAT(pmGet, '%c/%e') AS pmGet_date FROM order_track WHERE Order_id = ?");
            $stmt_fetch->execute([$order_id]);
            $date_result = $stmt_fetch->fetch(PDO::FETCH_ASSOC);

            if ($date_result) {
                echo json_encode([
                    'success' => true, 
                    'message' => '轉生管日期已成功更新', 
                    'pmGet_date' => $date_result['pmGet_date']
                ]);
            } else {
                // Should not happen if update was successful, but as a fallback
                echo json_encode(['success' => true, 'message' => '轉生管日期已成功更新，但無法獲取日期。']);
            }
        } else {
            echo json_encode(['success' => false, 'message' => '更新失敗']);
        }
    }
    
} catch (Exception $e) {
    // 記錄錯誤但不向客戶端暴露詳細資訊
    error_log('Error in simple_update_pmGet.php: ' . $e->getMessage());
    echo json_encode(['success' => false, 'message' => '處理請求時發生錯誤: ' . $e->getMessage()]);
    exit;
}
?> 