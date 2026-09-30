<?php
/**
 * asdoc_schedule_run.php — AS 文件排程提醒的檢查腳本（CLI 專用）
 * ------------------------------------------------------------------
 * 由 asdoc_schedule_tick.php 順路觸發、以 start /B 背景啟動；也可手動執行測試：
 *   & C:\MAMP\bin\php\php8.3.1\php.exe C:\MAMP\htdocs\EGsystem\src\common\asdoc_schedule_run.php
 * 掃描本年度到期／逾期的排程並發提醒（站內通知 live_event ＋ Web Push ＋ Telegram），發完即結束。
 */

if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit('cli only');
}

require_once __DIR__ . '/DBConnection.php';
require_once __DIR__ . '/asdoc_schedule_notify.php';

try {
    $conn = new DBConnection();
    $db = $conn->getPDO();
    $n = asched_process_due($db);
    echo "as_schedule reminders sent: {$n}\n";
} catch (\Throwable $e) {
    error_log('[asched] remind run failed: ' . $e->getMessage());
    echo 'failed: ' . $e->getMessage() . "\n";
}
