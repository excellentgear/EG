<?php
/**
 * comm_ctrl_tick.php — 溝通管制表到期提醒的「順路觸發」（做法同 personal_task_tick.php，免工作排程器）
 * ------------------------------------------------------------------
 * 頁面請求時呼叫 eg_comm_ctrl_tick()：距上次檢查超過 120 秒才背景啟動提醒腳本，
 * 用 start /B 啟動獨立程序後立刻返回，**不阻塞頁面**。
 *
 * 已知限制（與 personal_task 相同，使用者已知悉並選擇此方案）：
 * 半夜沒有人使用系統時不會檢查，到期的提醒會等到隔天有人開任何頁面時補發。
 * 因為發送條件是「現在時間 >= 提醒時刻」而不是「剛好等於」，晚一點觸發仍然發得出去、不會漏掉。
 */

if (!function_exists('eg_comm_ctrl_tick')) {
    function eg_comm_ctrl_tick(): void
    {
        static $done = false;
        if ($done) return;
        $done = true;

        try {
            $stateFile = __DIR__ . '/comm_ctrl_last_check.txt';
            $last = @filemtime($stateFile);
            if ($last && (time() - $last) < 120) return;   // 120 秒內檢查過就跳過

            $php = 'C:\\MAMP\\bin\\php\\php8.3.1\\php.exe'; // MAMP 的 PHP CLI（換 PHP 版本時需同步修改）
            $script = realpath(__DIR__ . '/comm_ctrl_remind_run.php');
            if (!is_file($php) || !$script) return;

            @touch($stateFile);                            // 先佔位，避免多個請求同時觸發
            clearstatcache(true, $stateFile);

            $cmd = 'start /B "" "' . $php . '" "' . $script . '" >NUL 2>&1';
            $h = @popen($cmd, 'r');
            if ($h) @pclose($h);
        } catch (\Throwable $e) {
            error_log('[comm_ctrl] tick failed: ' . $e->getMessage());
        }
    }
}
