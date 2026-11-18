<?php
/**
 * tool_inq_remind_run.php — 刀具詢價「交期提醒」檢查腳本（CLI 專用）
 * 由 tool_inq_tick.php 順路觸發、以 start /B 背景啟動；也可手動執行測試：
 *   & C:\MAMP\bin\php\php8.3.1\php.exe C:\MAMP\htdocs\EGsystem\src\common\tool_inq_remind_run.php
 */
if (PHP_SAPI !== 'cli') { http_response_code(403); exit('cli only'); }
require_once __DIR__ . '/DBConnection.php';
require_once __DIR__ . '/tool_inq_lib.php';
try {
    $db = (new DBConnection())->getPDO();
    echo 'tool_inq reminders sent: ' . ti_process_reminders($db) . "\n";
} catch (\Throwable $e) {
    error_log('[tool_inq] remind run failed: ' . $e->getMessage());
    echo 'failed: ' . $e->getMessage() . "\n";
}
