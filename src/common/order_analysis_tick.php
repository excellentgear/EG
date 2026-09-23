<?php
// order_analysis_tick.php — 訂單金額移動平均監控的順路觸發
// （做法同 car_remind_tick.php / comm_ctrl_tick.php，免工作排程器）。
// 一般頁面請求時呼叫：距上次檢查超過 3600 秒才背景啟動工人，start /B 立即返回不阻塞頁面。
// 判定本身是「每月一次」，所以一小時檢查一次綽綽有餘；半夜沒人用系統時不檢查，
// 到期的評估會等隔天有人開任何頁面時補做（比照其他模組的已定案取捨）。

if (!function_exists('eg_order_analysis_tick')) {
    function eg_order_analysis_tick(): void
    {
        static $done = false;
        if ($done) return;
        $done = true;

        try {
            $stateFile = __DIR__ . '/order_analysis_last_check.txt';
            $last = @filemtime($stateFile);
            if ($last && (time() - $last) < 3600) return;

            $php = 'C:\MAMP\bin\php\php8.3.1\php.exe';   // MAMP 的 PHP CLI（換 PHP 版本時需同步修改）
            $script = realpath(__DIR__ . '/order_analysis_run.php');
            if (!is_file($php) || !$script) return;

            @touch($stateFile);                 // 先佔位，避免多個請求同時觸發
            clearstatcache(true, $stateFile);

            $cmd = 'start /B "" "' . $php . '" "' . $script . '" >NUL 2>&1';
            $h = @popen($cmd, 'r');
            if ($h) @pclose($h);
        } catch (\Throwable $e) {
            error_log('[order_ma] tick failed: ' . $e->getMessage());
        }
    }
}
