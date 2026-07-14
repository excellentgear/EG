<?php
// Telegram 順路輪詢觸發（做法A，免工作排程器；原理同 WordPress wp-cron）。
// 一般頁面請求時呼叫 eg_telegram_tick()：距上次觸發超過 60 秒才背景啟動輪詢腳本，
// 用 start /B 啟動獨立程序、立即返回，完全不阻塞使用者的頁面請求。
// 半夜無人使用系統時不會輪詢，回覆會等到隔天有人開任何頁面時入帳（推播發送不受影響）。

if (!function_exists('eg_telegram_tick')) {
    function eg_telegram_tick(): void
    {
        static $done = false;
        if ($done) return;
        $done = true;

        try {
            $stateFile = __DIR__ . '/../../telegram/last_poll.txt';
            $last = @filemtime($stateFile);
            if ($last && (time() - $last) < 60) return; // 60 秒內觸發過就跳過

            // Token 未設定就不觸發
            require_once __DIR__ . '/../../config/telegram_config.php';
            if (!defined('TELEGRAM_BOT_TOKEN') || TELEGRAM_BOT_TOKEN === '' || TELEGRAM_BOT_TOKEN === 'YOUR_BOT_TOKEN_HERE') return;

            $php = 'C:\\MAMP\\bin\\php\\php8.3.1\\php.exe'; // MAMP 的 PHP CLI（換 PHP 版本時需同步修改）
            $script = realpath(__DIR__ . '/../../telegram/poll_replies.php');
            if (!is_file($php) || !$script) return;

            @touch($stateFile); // 先佔位，避免多個請求同時觸發
            clearstatcache(true, $stateFile);

            // Windows：start /B 背景啟動輪詢（駐留 300 秒長輪詢，期間按鈕點擊約 1 秒有反應），
            // popen 立即返回；輪詢駐留中每輪 touch last_poll.txt，故不會重複啟動
            $cmd = 'start /B "" "' . $php . '" "' . $script . '" 300 >NUL 2>&1';
            $h = @popen($cmd, 'r');
            if ($h) @pclose($h);
        } catch (\Throwable $e) {
            error_log('[telegram] tick failed: ' . $e->getMessage());
        }
    }
}
