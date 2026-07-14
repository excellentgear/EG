<?php
// 設置報錯
ini_set('display_errors', 1);
error_reporting(E_ALL);

// 返回JSON格式
header('Content-Type: application/json');

// 輸出測試信息
echo json_encode([
    'status' => 'success',
    'time' => date('Y-m-d H:i:s'),
    'php_version' => phpversion(),
    'server_software' => $_SERVER['SERVER_SOFTWARE'] ?? 'Unknown',
    'message' => 'PHP測試文件正常執行'
]);
?> 