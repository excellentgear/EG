<?php
/**
 * tool_inq_tick.php — 刀具詢價交期提醒的「順路觸發」（做法同 comm_ctrl_tick.php，免工作排程器）
 * 頁面請求時呼叫 eg_tool_inq_tick()：距上次檢查超過 300 秒才背景啟動檢查腳本，立刻返回、不阻塞頁面。
 * 發送條件是「今天 >= 交期 − 提醒天數」而不是剛好等於，所以半夜沒人用系統、隔天才觸發也不會漏發。
 */
if (!function_exists('eg_tool_inq_tick')) {
    function eg_tool_inq_tick(): void
    {
        static $done = false;
        if ($done) return;
        $done = true;
        try {
            $stateFile = __DIR__ . '/tool_inq_last_check.txt';
            $last = @filemtime($stateFile);
            if ($last && (time() - $last) < 300) return;
            $php = 'C:\MAMP\bin\php\php8.3.1\php.exe';
            $script = realpath(__DIR__ . '/tool_inq_remind_run.php');
            if (!is_file($php) || !$script) return;
            @touch($stateFile);
            clearstatcache(true, $stateFile);
            $h = @popen('start /B "" "' . $php . '" "' . $script . '" >NUL 2>&1', 'r');
            if ($h) @pclose($h);
        } catch (\Throwable $e) {
            error_log('[tool_inq] tick failed: ' . $e->getMessage());
        }
    }
}
