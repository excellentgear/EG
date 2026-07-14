<?php
// Telegram 輪詢腳本（CLI 專用，spec 規範不可從瀏覽器開啟）。
// 由 src/common/telegram_tick.php 於一般頁面請求時背景啟動（做法A，免工作排程器）；
// 也可手動執行：C:\MAMP\bin\php\php8.3.1\php.exe telegram\poll_replies.php
if (php_sapi_name() !== 'cli') {
    http_response_code(403);
    exit('CLI only');
}
// CLI 預設時區為 UTC：訊息顯示時間（回簽完成標記、附件浮水印等）一律以台北時間呈現
date_default_timezone_set('Asia/Taipei');

require_once __DIR__ . '/../src/common/DBConnection.php';
require_once __DIR__ . '/poll_core.php';

// 參數1＝駐留秒數：0（預設）收一輪就結束；telegram_tick 以 300 啟動（駐留5分鐘長輪詢，按鈕約1秒有反應）
$runSeconds = isset($argv[1]) ? max(0, (int)$argv[1]) : 0;

$conn = new DBConnection();
$r = tg_poll_process($conn->getPDO(), $runSeconds);
echo json_encode($r, JSON_UNESCAPED_UNICODE) . "\n";
