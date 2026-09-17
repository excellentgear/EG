<?php
/**
 * comm_ctrl_notify.php — 溝通管制表「下次應溝通日」到期提醒的發送
 * ------------------------------------------------------------------
 * 由 comm_ctrl_remind_run.php（順路觸發背景啟動，見 comm_ctrl_tick.php）呼叫
 * comm_ctrl_process_due_reminders()。
 *
 * **與 personal_task_notify.php 的關鍵差異（使用者明確要求）**：
 *   personal_task 刻意「不寫 live_event」（避免公告過亂），只走 Web Push＋Telegram；
 *   本模組則**一定要另外寫 live_event（站內通知）**——使用者原話是
 *   「提醒要可以以通知方式，不限定要綁定手機或 telegram 才能提醒」。
 *   沒綁推播／Telegram 的人本來就收不到那兩種，站內通知是他們唯一收得到的管道，
 *   所以這裡是 live_event ＋ Web Push 並行（Telegram 有綁的人也照發）。
 *
 * 發送條件（三個都成立才發）：
 *   ① remind_enabled = 1 且有 next_due_date
 *   ② 現在時間 >= (next_due_date - remind_lead_days 天) 當天的 remind_time
 *   ③ remind_sent_for <> next_due_date（這一期還沒發過）
 * 發送前先用 UPDATE ... WHERE 搶佔把 remind_sent_for 寫成本期的 next_due_date，
 * 搶不到就跳過——多個背景程序同時跑也不會重複發（做法同 personal_task 的 remind_sent）。
 *
 * 「這一期」的界定是 next_due_date 本身：轉出溝通記錄時 ctrl_to_record 會把
 * next_due_date 往後推一期並把 remind_sent_for 清成 NULL，所以新的一期會重新提醒。
 */

require_once __DIR__ . '/comm_mgmt_lib.php';

if (!function_exists('comm_ctrl_remind_targets')) {
    /**
     * 展開提醒對象。刻意直接用共用庫的 cm_ctrl_target_uids()（選部門＝含子部門），
     * 不要在這裡自己再寫一次部門展開——兩份規則遲早走鐘（鐵律4）。
     */
    function comm_ctrl_remind_targets(PDO $db, int $ctrlId): array
    {
        return cm_ctrl_target_uids($db, $ctrlId);
    }
}

if (!function_exists('comm_ctrl_send_reminder')) {
    /**
     * 對一筆管制項目的全部對象發提醒：live_event（站內通知）＋ Web Push ＋ Telegram。
     * 任一條管道失敗只記 log，不影響其他管道，也不影響「已發送」的標記
     * （標記在呼叫端搶佔時就寫了——重發比漏發更難察覺，寧可這一期只發一次）。
     */
    function comm_ctrl_send_reminder(PDO $db, array $c, array $uids): void
    {
        if (!$uids) return;

        $due   = (string)$c['next_due_date'];
        $dueTx = str_replace('-', '.', $due);               // 日期顯示一律 YYYY.MM.DD（ai-rules/20）
        $freq  = cm_freq_text((int)$c['freq_n'], (string)$c['freq_unit'], (int)$c['freq_times']);
        $days  = (int)floor((strtotime($due) - strtotime(date('Y-m-d'))) / 86400);
        $when  = $days > 0 ? ('還有 ' . $days . ' 天') : ($days === 0 ? '就是今天' : ('已逾期 ' . abs($days) . ' 天'));

        $title = '溝通管制表提醒：' . ($c['party'] ?: '（未填對象）') . '　應於 ' . $dueTx . ' 前溝通';
        $body  = '利害關係人：' . ($c['party'] ?: '（未填）') . "\n"
               . '溝通內容：' . mb_substr((string)$c['content'], 0, 200) . "\n"
               . '溝通管道：' . ($c['channel'] ?: '（未填）') . "\n"
               . '頻率：' . $freq . "\n"
               . '下次應溝通日：' . $dueTx . '（' . $when . '）' . "\n"
               . '點此開啟「溝通管理 → 溝通管制表」，溝通完請按該列的「建立溝通記錄」留下紀錄。';

        // ── 站內通知（本模組必須有，否則沒綁手機/Telegram 的人收不到） ──
        $eid = 0;
        try {
            // 同一筆管制項目若還有上一期沒被讀掉的提醒還掛著，先收掉避免通知欄一直疊
            $db->prepare("UPDATE live_event SET enddate=DATE_SUB(CURDATE(), INTERVAL 1 DAY)
                          WHERE ref_type='COMM_CTRL_REMIND' AND ref_id=? AND (enddate IS NULL OR enddate>=CURDATE())")
               ->execute([(int)$c['ctrl_id']]);

            $db->prepare("INSERT INTO live_event (eventdate, enddate, title, content, status, created_by, source,
                            show_status_to_others, ref_type, ref_id)
                          VALUES (CURDATE(), NULL, ?, ?, 0, ?, '溝通管理', 1, 'COMM_CTRL_REMIND', ?)")
               ->execute([$title, $body, (int)($c['created_by'] ?: 0), (int)$c['ctrl_id']]);
            $eid = (int)$db->lastInsertId();
            $ins = $db->prepare("INSERT INTO live_event_target (live_event_id, target_type, target_id, mode)
                                 VALUES (?, 'user', ?, 'read')");
            foreach ($uids as $u) $ins->execute([$eid, (int)$u]);
        } catch (Throwable $e) {
            error_log('[comm_ctrl] live_event failed: ' . $e->getMessage());
        }

        // ── Web Push（有訂閱的人才收得到，屬加分不是必要） ──
        try {
            require_once __DIR__ . '/../push/push_send.php';
            $to = $eid ? eg_push_event_recipients($db, $eid) : $uids;
            eg_push_send_to_users($db, $to, [
                'title' => $title,
                'body'  => mb_substr($body, 0, 480),
                'url'   => '/EGsystem/views/GM/communication_mgmt.php?tab=ctrl',
                'tag'   => 'comm-ctrl-' . (int)$c['ctrl_id'],
            ]);
        } catch (Throwable $e) {
            error_log('[comm_ctrl] push failed: ' . $e->getMessage());
        }

        // ── Telegram（綁定者才有；訊息內不放 URL，依 telegram spec） ──
        try {
            require_once __DIR__ . '/../../telegram/send_message.php';
            if (function_exists('tg_is_configured') && tg_is_configured()) {
                $in = implode(',', array_map('intval', $uids));
                $st = $db->query("SELECT chat_id FROM telegram_users WHERE is_active=1 AND user_id IN ($in)");
                foreach ($st->fetchAll(PDO::FETCH_COLUMN) as $chatId) {
                    tg_send_text($chatId, $title . "\n" . $body, $db);
                }
            }
        } catch (Throwable $e) {
            error_log('[comm_ctrl] telegram failed: ' . $e->getMessage());
        }
    }
}

if (!function_exists('comm_ctrl_process_due_reminders')) {
    /**
     * 掃描到期未發送的管制項目提醒並發送。
     * @return int 實際發送的筆數（有對象、真的發出去的那幾筆）
     */
    function comm_ctrl_process_due_reminders(PDO $db): int
    {
        $sent = 0;
        try {
            /* 提醒時刻 = (next_due_date - lead_days 天) 當天的 remind_time。
               remind_time 沒填時用 09:00（ctrl_save 存檔時也是這個預設，兩邊一致）。
               remind_sent_for <=> next_due_date 用 NULL-safe 比較，NULL（從沒發過）也要被選出來。 */
            $rows = $db->query("
                SELECT * FROM comm_ctrl
                WHERE is_deleted = 0
                  AND remind_enabled = 1
                  AND next_due_date IS NOT NULL
                  AND NOT (remind_sent_for <=> next_due_date)
                  AND NOW() >= TIMESTAMP(
                        DATE_SUB(next_due_date, INTERVAL COALESCE(remind_lead_days, 0) DAY),
                        COALESCE(remind_time, '09:00:00'))
            ")->fetchAll(PDO::FETCH_ASSOC);
        } catch (Throwable $e) {
            error_log('[comm_ctrl] scan failed: ' . $e->getMessage());
            return 0;
        }

        // 搶佔：條件裡再寫一次「這一期還沒發過」，兩個程序同時跑只有一個 rowCount 會是 1
        $claim = $db->prepare("UPDATE comm_ctrl SET remind_sent_for = next_due_date, remind_last_at = NOW()
                               WHERE ctrl_id = ? AND remind_enabled = 1 AND NOT (remind_sent_for <=> next_due_date)");
        foreach ($rows as $c) {
            try {
                $claim->execute([(int)$c['ctrl_id']]);
                if ($claim->rowCount() < 1) continue;        // 已被其他程序搶到
                $uids = comm_ctrl_remind_targets($db, (int)$c['ctrl_id']);
                if (!$uids) continue;                        // 沒有有效對象就不發（標記已留，不會每分鐘重試）
                comm_ctrl_send_reminder($db, $c, $uids);
                $sent++;
            } catch (Throwable $e) {
                error_log('[comm_ctrl] send failed for ctrl_id=' . $c['ctrl_id'] . ': ' . $e->getMessage());
            }
        }
        return $sent;
    }
}
