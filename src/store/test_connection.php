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
    // 檢查會話狀態
    $session_status = [
        'session_active' => session_status() === PHP_SESSION_ACTIVE,
        'user_logged_in' => isset($_SESSION['userName']),
        'username' => isset($_SESSION['userName']) ? $_SESSION['userName'] : null,
        'user_id' => isset($_SESSION['id']) ? $_SESSION['id'] : null
    ];
    
    // 測試數據庫連接
    $conn = new DBConnection();
    $db_test = [
        'connection_successful' => true,
        'message' => '數據庫連接成功'
    ];
    
    // 嘗試執行簡單查詢
    $result = $conn->getOne("SELECT COUNT(*) as count FROM order_track");
    $db_test['order_count'] = $result['count'];
    
    // 輸出結果
    echo json_encode([
        'status' => 'success',
        'session' => $session_status,
        'database' => $db_test,
        'php_version' => PHP_VERSION,
        'server_info' => $_SERVER['SERVER_SOFTWARE']
    ], JSON_PRETTY_PRINT);
    
} catch (Exception $e) {
    echo json_encode([
        'status' => 'error',
        'message' => $e->getMessage(),
        'session' => $session_status ?? null,
        'trace' => $e->getTraceAsString()
    ], JSON_PRETTY_PRINT);
}
?> 