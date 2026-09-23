<?php
// order_analysis_run.php — 訂單金額移動平均監控的背景工人（由 order_analysis_tick.php 啟動）
// 一個月只評估一次，達到觸發條件才發通知；判定與通知內容都在 order_analysis_notify.php。
if (PHP_SAPI !== 'cli') { http_response_code(403); exit('CLI only'); }
require_once __DIR__ . '/DBConnection.php';
require_once __DIR__ . '/order_analysis_notify.php';
try {
    $db = (new DBConnection())->getPDO();
    $r = oa_notify_process($db);
    error_log('[order_ma] ' . json_encode($r, JSON_UNESCAPED_UNICODE));
} catch (Throwable $e) {
    error_log('[order_ma] run failed: ' . $e->getMessage());
}
