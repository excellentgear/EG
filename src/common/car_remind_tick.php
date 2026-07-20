<?php
// car_remind_tick.php — 矯正單逾期提醒的順路觸發（做法同 personal_task_tick.php / telegram_tick.php，免工作排程器）。
// 一般頁面請求時呼叫 eg_car_remind_tick()：距上次檢查超過 900 秒才背景啟動掃描腳本，
// start /B 啟動獨立程序立即返回，不阻塞頁面。半夜無人使用系統時不會檢查，
// 到期的提醒會等到隔天有人開任何頁面時補發（比照個人工作紀錄的已定案取捨）。

if (!function_exists('eg_car_remind_tick')) {
    function eg_car_remind_tick(): void
    {
        static $done = false;
        if ($done) return;
        $done = true;

        try {
            $stateFile = __DIR__ . '/car_remind_last_check.txt';
            $last = @filemtime($stateFile);
            if ($last && (time() - $last) < 900) return; // 900 秒(15分)內檢查過就跳過

            $php = 'C:\\MAMP\\bin\\php\\php8.3.1\\php.exe'; // MAMP 的 PHP CLI（換 PHP 版本時需同步修改）
            $script = realpath(__DIR__ . '/car_remind_run.php');
            if (!is_file($php) || !$script) return;

            @touch($stateFile); // 先佔位，避免多個請求同時觸發
            clearstatcache(true, $stateFile);

            $cmd = 'start /B "" "' . $php . '" "' . $script . '" >NUL 2>&1';
            $h = @popen($cmd, 'r');
            if ($h) @pclose($h);
        } catch (\Throwable $e) {
            error_log('[car_remind] tick failed: ' . $e->getMessage());
        }
    }
}
