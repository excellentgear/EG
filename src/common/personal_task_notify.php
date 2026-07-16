<?php
// personal_task_notify.php — 個人工作紀錄提醒發送
// 依使用者需求：提醒「不寫入公告(live_event)」避免公告過亂，只走推播通道、不需按已閱：
//   Web Push（eg_push_send_to_users，可獨立呼叫不需 live_event）＋ Telegram（tg_send_text 底層直發）。
// 由 personal_task_remind_run.php（順路觸發背景啟動）呼叫 personal_task_process_due_reminders()。

if (!function_exists('personal_task_remind_user')) {
    /** 對單一使用者發送提醒（推播失敗只記 log，不阻斷流程） */
    function personal_task_remind_user(PDO $db, int $userId, string $title, string $body): void
    {
        // Web Push：手機 PWA 與電腦瀏覽器有訂閱推播者都會跳系統通知（類似行事曆提醒）
        try {
            require_once __DIR__ . '/../push/push_send.php';
            eg_push_send_to_users($db, [$userId], [
                'title' => $title,
                'body'  => $body,
                'url'   => '/EGsystem/views/user/personal_task.php',
                'tag'   => 'personal-task',
            ]);
        } catch (\Throwable $e) {
            error_log('[ptask] push failed: ' . $e->getMessage());
        }

        // Telegram：綁定者直發文字訊息（訊息內不放 URL，依 telegram spec）
        try {
            require_once __DIR__ . '/../../telegram/send_message.php';
            if (tg_is_configured()) {
                $st = $db->prepare("SELECT chat_id FROM telegram_users WHERE user_id = ? AND is_active = 1");
                $st->execute([$userId]);
                foreach ($st->fetchAll(PDO::FETCH_COLUMN) as $chatId) {
                    tg_send_text($chatId, $title . "\n" . $body, $db);
                }
            }
        } catch (\Throwable $e) {
            error_log('[ptask] telegram failed: ' . $e->getMessage());
        }
    }
}

if (!function_exists('personal_task_fmt_dt')) {
    /** 2026-07-20 14:00:00 → 2026-07-20 14:00；整點 00:00 只留日期 */
    function personal_task_fmt_dt(?string $dt): string
    {
        if (!$dt) return '';
        $s = substr($dt, 0, 16);
        return str_ends_with($s, ' 00:00') ? substr($s, 0, 10) : $s;
    }
}

if (!function_exists('personal_task_process_due_reminders')) {
    /**
     * 掃描「提醒時間已到、尚未發送」的任務期限與進度步驟並發送。
     * 只提醒狀態=未完成(0)的紀錄；暫停/已完成不提醒。
     * 先 UPDATE remind_sent=1 搶佔（WHERE remind_sent=0）再發送，多個程序同時跑也不會重複發。
     * @return int 實際發送的提醒筆數
     */
    function personal_task_process_due_reminders(PDO $db): int
    {
        $sent = 0;

        // ── 任務期限提醒 ──
        $rows = $db->query("
            SELECT t.id, t.user_id, t.title, t.deadline, t.bind_label
            FROM personal_task t
            WHERE t.status = 0 AND t.remind_sent = 0
              AND t.deadline IS NOT NULL AND t.remind_before_minutes IS NOT NULL
              AND NOW() >= DATE_SUB(t.deadline, INTERVAL t.remind_before_minutes MINUTE)
        ")->fetchAll(PDO::FETCH_ASSOC);
        $claim = $db->prepare("UPDATE personal_task SET remind_sent = 1 WHERE id = ? AND remind_sent = 0");
        foreach ($rows as $r) {
            $claim->execute([$r['id']]);
            if ($claim->rowCount() < 1) continue; // 已被其他程序搶到
            $body = '「' . $r['title'] . '」期限 ' . personal_task_fmt_dt($r['deadline'])
                  . ($r['bind_label'] !== null && $r['bind_label'] !== '' ? '（' . $r['bind_label'] . '）' : '');
            personal_task_remind_user($db, (int)$r['user_id'], '⏰ 個人工作期限提醒', $body);
            $sent++;
        }

        // ── 進度步驟提醒 ──
        $rows = $db->query("
            SELECT s.id, s.step_name, s.planned_at, t.user_id, t.title, t.bind_label
            FROM personal_task_step s
            JOIN personal_task t ON t.id = s.task_id
            WHERE t.status = 0 AND s.remind_sent = 0 AND s.reached_at IS NULL
              AND s.planned_at IS NOT NULL AND s.remind_before_minutes IS NOT NULL
              AND NOW() >= DATE_SUB(s.planned_at, INTERVAL s.remind_before_minutes MINUTE)
        ")->fetchAll(PDO::FETCH_ASSOC);
        $claim = $db->prepare("UPDATE personal_task_step SET remind_sent = 1 WHERE id = ? AND remind_sent = 0");
        foreach ($rows as $r) {
            $claim->execute([$r['id']]);
            if ($claim->rowCount() < 1) continue;
            $body = '「' . $r['title'] . '」進度「' . $r['step_name'] . '」預定 ' . personal_task_fmt_dt($r['planned_at'])
                  . ($r['bind_label'] !== null && $r['bind_label'] !== '' ? '（' . $r['bind_label'] . '）' : '');
            personal_task_remind_user($db, (int)$r['user_id'], '⏰ 個人工作進度提醒', $body);
            $sent++;
        }

        return $sent;
    }
}
