<?php
// mig_backup_tick.php — 移機快速備份的順路觸發（做法同 db_backup_tick.php,免工作排程器）。
// 一般頁面請求時呼叫 eg_migbk_tick():距上次檢查超過 3600 秒才背景啟動工人,
// 是否真的執行由工人依 migbk_interval_days 判斷(0=使用者未啟用,永不自動跑)。

if (!function_exists('eg_migbk_tick')) {
    function eg_migbk_tick(): void
    {
        static $done = false;
        if ($done) return;
        $done = true;

        try {
            $stateFile = __DIR__ . '/mig_backup_last_check.txt';
            $last = @filemtime($stateFile);
            if ($last && (time() - $last) < 3600) return; // 1小時內檢查過就跳過(移機備份是重活)

            $php = 'C:\\MAMP\\bin\\php\\php8.3.1\\php.exe'; // 換 PHP 版本時需同步修改
            $script = realpath(__DIR__ . '/mig_backup_run.php');
            if (!is_file($php) || !$script) return;

            @touch($stateFile);
            clearstatcache(true, $stateFile);

            $cmd = 'start /B "" "' . $php . '" "' . $script . '" auto >NUL 2>&1';
            $h = @popen($cmd, 'r');
            if ($h) @pclose($h);
        } catch (\Throwable $e) {
            error_log('[mig_backup] tick failed: ' . $e->getMessage());
        }
    }
}
