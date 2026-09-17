<?php
/**
 * comm_ctrl_remind_run.php — 溝通管制表到期提醒的檢查腳本（CLI 專用）
 * ------------------------------------------------------------------
 * 由 comm_ctrl_tick.php 順路觸發、以 start /B 背景啟動；也可手動執行測試：
 *   & C:\MAMP\bin\php\php8.3.1\php.exe C:\MAMP\htdocs\EGsystem\src\common\comm_ctrl_remind_run.php
 * 掃描到期未發送的提醒並發送（站內通知 live_event ＋ Web Push ＋ Telegram），發完即結束。
 */

if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit('cli only');
}

require_once __DIR__ . '/DBConnection.php';
require_once __DIR__ . '/comm_ctrl_notify.php';

try {
    $conn = new DBConnection();
    $db = $conn->getPDO();
    $n = comm_ctrl_process_due_reminders($db);
    echo "comm_ctrl reminders sent: {$n}\n";
} catch (\Throwable $e) {
    error_log('[comm_ctrl] remind run failed: ' . $e->getMessage());
    echo 'failed: ' . $e->getMessage() . "\n";
}
