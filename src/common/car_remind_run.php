<?php
// car_remind_run.php — 異常矯正處理單逾期提醒檢查腳本（CLI 專用）
// 由 car_remind_tick.php 順路觸發以 start /B 背景啟動；也可手動執行測試：
//   & C:\MAMP\bin\php\php8.3.1\php.exe C:\MAMP\htdocs\EGsystem\src\common\car_remind_run.php
// 掃描卡關逾期的單據並依狀態發送提醒（Web Push + Telegram 隨 car_notify 一併嘗試），發完即結束。

if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit('cli only');
}

require_once __DIR__ . '/DBConnection.php';
require_once __DIR__ . '/car_lib.php';
require_once __DIR__ . '/car_notify.php';
require_once __DIR__ . '/car_remind.php';

try {
    $db = (new DBConnection())->getPDO();
    $n = car_remind_scan_and_send($db);
    echo "car reminders sent: {$n}\n";
} catch (\Throwable $e) {
    error_log('[car_remind] run failed: ' . $e->getMessage());
    echo 'failed: ' . $e->getMessage() . "\n";
}
