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
require_once __DIR__ . '/eng_log_lib.php';   // 工程處理紀錄的提醒共用同一次背景執行

try {
    $conn = new DBConnection();
    $db = $conn->getPDO();
    $n = personal_task_process_due_reminders($db);
    echo "personal_task reminders sent: {$n}\n";
} catch (\Throwable $e) {
    error_log('[ptask] remind run failed: ' . $e->getMessage());
    echo 'failed: ' . $e->getMessage() . "\n";
}

/* 工程處理紀錄（案件期限＋問題項催回覆）刻意掛在同一支背景腳本，
   而不是另外開一個全站 tick：兩者節流條件、推播管線完全相同，
   多開一個 hook 只會讓每個頁面請求多啟動一個程序。
   分開 try/catch，其中一邊失敗不影響另一邊。 */
try {
    $conn2 = new DBConnection();
    $db2 = $conn2->getPDO();
    $m = el_process_due_reminders($db2);
    echo "eng_log reminders sent: {$m}\n";
} catch (\Throwable $e) {
    error_log('[eng_log] remind run failed: ' . $e->getMessage());
    echo 'failed: ' . $e->getMessage() . "\n";
}
