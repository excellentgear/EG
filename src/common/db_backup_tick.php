<?php
// db_backup_tick.php — 資料庫自動備份的順路觸發（做法同 car_remind_tick.php / personal_task_tick.php）。
// 一般頁面請求時呼叫 eg_db_backup_tick()：距上次檢查超過 600 秒才背景啟動備份工人，
// start /B 啟動獨立程序立即返回，不阻塞頁面。真正的「每 N 天備份一次」間隔由工人
// (db_backup_run.php) 依 db_backup_config.interval_days 判斷；本 tick 只是節流避免每次
// 頁面請求都去啟動程序。半夜無人使用系統時不備份，到期會等隔天有人開頁面時補跑。

if (!function_exists('eg_db_backup_tick')) {
    function eg_db_backup_tick(): void
    {
        static $done = false;
        if ($done) return;
        $done = true;

        try {
            $stateFile = __DIR__ . '/db_backup_last_check.txt';
            $last = @filemtime($stateFile);
            if ($last && (time() - $last) < 600) return; // 600 秒內檢查過就跳過

            $php = 'C:\\MAMP\\bin\\php\\php8.3.1\\php.exe'; // MAMP 的 PHP CLI（換版本時需同步修改）
            $script = realpath(__DIR__ . '/db_backup_run.php');
            if (!is_file($php) || !$script) return;

            @touch($stateFile); // 先佔位，避免多個請求同時觸發
            clearstatcache(true, $stateFile);

            $cmd = 'start /B "" "' . $php . '" "' . $script . '" auto system >NUL 2>&1';
            $h = @popen($cmd, 'r');
            if ($h) @pclose($h);
        } catch (\Throwable $e) {
            error_log('[db_backup] tick failed: ' . $e->getMessage());
        }
    }
}
