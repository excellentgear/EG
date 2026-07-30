<?php
// user_leave_tick.php — 預定離職日到期自動轉離職的順路觸發（做法同 quotation_attach_tick.php，免工作排程器）。
//
// 為什麼要有這支：人事可在員工還在職時先填「預定離職日」，但如果只是存個日期、
// 等當事人自己記得當天來改狀態，就會變成 ai-rules/14 那種「幾個月後才發現沒人處理」的事故。
//
// 分工（兩層都要，缺一不可）：
//   - 封鎖本身由 eg_user_blocked_state() 判斷時即時生效（leave_date < 今天就擋），排程晚跑也不影響安全；
//   - 這支只負責把「事實」補寫進資料：state 改 0、寫在職狀態歷程與稽核，讓人事清單看得到、查得到。
//
// 生效時點：離職日「當天仍可使用系統」（要交接、結案），隔天 0 點起封鎖。
// 半夜無人使用時不會執行，等隔天有人開任何頁面時補做（與其他 tick 一致）。

if (!function_exists('eg_user_leave_tick')) {
    function eg_user_leave_tick(PDO $pdo): void
    {
        static $done = false;
        if ($done) return;
        $done = true;

        try {
            $stateFile = __DIR__ . '/user_leave_last_check.txt';
            $last = @filemtime($stateFile);
            if ($last && (time() - $last) < 3600) return;   // 1 小時內跑過就跳過
            @touch($stateFile);                              // 先佔位避免並發重複
            clearstatcache(true, $stateFile);

            require_once __DIR__ . '/user_active_lib.php';

            // 還沒被標成離職/留停，但預定離職日已經過了的人
            $rows = $pdo->query(
                "SELECT id, user_cname, leave_date FROM `user`
                  WHERE leave_date IS NOT NULL AND leave_date < CURDATE()
                    AND (state IS NULL OR state NOT IN (" . EG_BLOCKED_USER_STATES . "))"
            )->fetchAll(PDO::FETCH_ASSOC);
            if (!$rows) return;

            $upd  = $pdo->prepare("UPDATE `user` SET state = 0 WHERE id = ?");
            $hist = $pdo->prepare("INSERT INTO user_status_history (user_id, status, start_date, end_date, remark)
                                   VALUES (?, 0, ?, NULL, '預定離職日到期，系統自動轉為離職')");
            $log  = $pdo->prepare("INSERT INTO audit_log
                                   (action_type, target_type, target_id, target_name, changes, user_id, operator, created_at)
                                   VALUES ('LEAVE_AUTO', 'user', ?, ?, ?, NULL, 'system', NOW())");

            foreach ($rows as $r) {
                try {
                    $pdo->beginTransaction();
                    $upd->execute([$r['id']]);
                    $hist->execute([$r['id'], $r['leave_date']]);
                    $log->execute([(string)$r['id'], (string)$r['user_cname'],
                        json_encode(['leave_date' => $r['leave_date'], 'state' => '→0(離職)',
                                     'note' => '預定離職日已過，自動轉離職；權限設定未自動刪除，需人事於員工管理頁按「清除權限設定」'],
                                    JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE)]);
                    $pdo->commit();
                } catch (\Throwable $e) {
                    if ($pdo->inTransaction()) $pdo->rollBack();
                    error_log('[user_leave] auto-resign failed for ' . $r['id'] . ': ' . $e->getMessage());
                }
            }
        } catch (\Throwable $e) {
            error_log('[user_leave] tick failed: ' . $e->getMessage());
        }
    }
}
