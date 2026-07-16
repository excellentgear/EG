<?php
// personal_task_remind_run.php — 個人工作紀錄提醒檢查腳本（CLI 專用）
// 由 personal_task_tick.php 順路觸發以 start /B 背景啟動；也可手動執行測試：
//   & C:\MAMP\bin\php\php8.3.1\php.exe C:\MAMP\htdocs\EGsystem\src\common\personal_task_remind_run.php
// 掃描到期未發送的提醒並推播（Web Push + Telegram），發完即結束。

if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit('cli only');
}

require_once __DIR__ . '/DBConnection.php';
require_once __DIR__ . '/personal_task_notify.php';

try {
    $conn = new DBConnection();
    $db = $conn->getPDO();
    $n = personal_task_process_due_reminders($db);
    echo "personal_task reminders sent: {$n}\n";
} catch (\Throwable $e) {
    error_log('[ptask] remind run failed: ' . $e->getMessage());
    echo 'failed: ' . $e->getMessage() . "\n";
}
