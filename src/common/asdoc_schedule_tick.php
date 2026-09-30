<?php
/**
 * asdoc_schedule_tick.php — AS 文件排程提醒的「順路觸發」（做法同 comm_ctrl_tick.php，免工作排程器）
 * ------------------------------------------------------------------
 * 頁面請求時呼叫 eg_asched_tick()：距上次檢查超過 3600 秒才背景啟動提醒腳本，
 * 用 start /B 啟動獨立程序後立刻返回，**不阻塞頁面**。
 *
 * 間隔刻意設 1 小時（不是 120 秒）：排程是以「天」為單位的東西，沒有必要每兩分鐘掃一次；
 * 而 asched_plan() 要跑 33 份文件的來源查詢，掃太密只是白費資源。
 *
 * 已知限制（與其他 tick 相同，使用者已知悉並選擇此方案）：
 * 半夜沒有人使用系統時不會檢查，到期的提醒會等到隔天有人開任何頁面時補發。
 * 因為發送條件是「狀態已經是 due/overdue」而不是「剛好今天到期」，晚一點觸發仍然發得出去。
 */

if (!function_exists('eg_asched_tick')) {
    function eg_asched_tick(): void
    {
        static $done = false;
        if ($done) return;
        $done = true;

        try {
            $stateFile = __DIR__ . '/asdoc_schedule_last_check.txt';
            $last = @filemtime($stateFile);
            if ($last && (time() - $last) < 3600) return;   // 1 小時內檢查過就跳過

            $php = 'C:\MAMP\bin\php\php8.3.1\php.exe'; // MAMP 的 PHP CLI（換 PHP 版本時需同步修改）
            $script = realpath(__DIR__ . '/asdoc_schedule_run.php');
            if (!is_file($php) || !$script) return;

            @touch($stateFile);                            // 先佔位，避免多個請求同時觸發
            clearstatcache(true, $stateFile);

            $cmd = 'start /B "" "' . $php . '" "' . $script . '" >NUL 2>&1';
            $h = @popen($cmd, 'r');
            if ($h) @pclose($h);
        } catch (\Throwable $e) {
            error_log('[asched] tick failed: ' . $e->getMessage());
        }
    }
}
