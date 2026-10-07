<?php
// 主檔管理頁 CLI 測試執行器（比照 _api_runner.php 同一套做法：CLI 直接 include，
// 手動塞 $_SESSION 模擬登入者，不走真正的 session 檔/cookie）。
// 用法：php _md_api_runner.php <uid> <base64_post_json>
error_reporting(E_ALL & ~E_NOTICE & ~E_DEPRECATED & ~E_WARNING);
ini_set('display_errors', '0'); // 避免 session_start() 二次呼叫的警告字樣混進 stdout 污染 JSON 輸出
$_SERVER['DOCUMENT_ROOT'] = 'C:/MAMP/htdocs';
$_SERVER['REQUEST_METHOD'] = 'POST';
$_SERVER['SCRIPT_NAME'] = '/EGsystem/views/pages/master_data_management.php';
$_SERVER['PHP_SELF']    = '/EGsystem/views/pages/master_data_management.php';
session_start();
$_SESSION['id'] = (int)$argv[1];
$_SESSION['userName'] = 'test';
$post = json_decode(base64_decode($argv[2]), true) ?: [];
$_GET = []; $_POST = $post; $_REQUEST = $post;
include 'C:/MAMP/htdocs/EGsystem/views/pages/master_data_management.php';
