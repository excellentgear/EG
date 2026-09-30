<?php
/**
 * asdoc_schedule_notify.php — AS 文件週期排程的到期／逾期提醒發送
 * ══════════════════════════════════════════════════════════════════════════════
 * 由 asdoc_schedule_run.php（順路觸發背景啟動，見 asdoc_schedule_tick.php）呼叫
 * asched_process_due()。做法比照 comm_ctrl_notify.php：站內通知（live_event）
 * ＋ Web Push ＋ Telegram 三軌並行。
 *
 * **為什麼一定要有站內通知**：沒綁手機／Telegram 的人本來就收不到那兩種，
 * 站內通知是他們唯一收得到的管道（溝通管理那次使用者已經明確要求過同一件事）。
 *
 * 發送條件：
 *   ①提醒總開關開著（settings.notify_enabled）
 *   ②該排程點的狀態是 due（到期前 lead_days 內）或 overdue
 *   ③這個階段還沒發過（as_sched_notify_log 的 doc_id+period_key+phase）
 *      逾期的可依 settings.overdue_repeat 每 N 天再發一輪（0＝只發一次）
 *
 * ★刻意不對 state='unknown' 的排程發提醒：那些是「系統查不到完成紀錄」的文件
 *   （沒有網頁、沒有紙本上傳、沒有人工登記），系統不知道≠沒做。對它們發逾期提醒
 *   等於每個月都在吵一件可能早就做完的事，收件人會直接把整個提醒當成雜訊。
 *   這些缺口改由排程頁的「缺口盤點」列出來，請管理員補人工登記或補接來源。
 *
 * ★同一份文件的同一個週期，同一個階段只發一次（用 INSERT 搶佔，多個背景程序同時
 *   跑也不會重複發）。重發比漏發更難察覺，寧可這一期只發一次。
 */

require_once __DIR__ . '/asdoc_schedule_lib.php';

if (!function_exists('asched_send_one')) {

/** 發一則提醒給指定對象：live_event ＋ Web Push ＋ Telegram。任一管道失敗只記 log。 */
function asched_send_one(PDO $db, array $r, array $uids, string $phase, int $round): void
{
    if (!$uids) return;

    $dueTx = $r['due_date'] ? str_replace('-', '.', (string)$r['due_date']) : '（月份未定）';
    $stTx  = asched_state_text((string)$r['state'], $r['days'] ?? null);
    $depts = implode('、', array_map(fn($d) => (string)$d['name'], (array)$r['depts'])) ?: '（未設負責課室）';

    $head  = $phase === 'over' ? 'AS文件逾期提醒' : 'AS文件到期提醒';
    $title = $head . '：' . $r['doc_no'] . ' ' . $r['doc_name'] . '　' . $stTx;

    $body  = '文件編號：' . $r['doc_no'] . "\n"
           . '文件名稱：' . $r['doc_name'] . "\n"
           . '負責課室：' . $depts . "\n"
           . '更新頻率：' . $r['freq_label'] . "\n"
           . '本期應完成：' . $dueTx . '（' . $stTx . '）' . "\n"
           . '上次完成：' . ($r['last_done'] ? str_replace('-', '.', (string)$r['last_done']) : '查無紀錄') . "\n";
    // 月份是推估來的一定要講明，否則收件人會以為系統在亂報
    if (($r['month_src'] ?? '') === 'infer')
        $body .= '※本期月份是由過去實際完成的月份推估的，可在 AS 文件管理的「更新頻率」指定固定月份。' . "\n";
    /* 完成紀錄的來源要講清楚，而且 manual 與 none 的措辭必須分開：
       manual＝靠人工登記（查得到，只是要有人來登記）；none＝四種來源全查不到。
       第一版兩種共用「系統查不到」這句話，對已經有人工登記的文件是錯的說法。 */
    if (($r['src_kind'] ?? '') === 'manual')
        $body .= '※這份文件的完成紀錄是靠人工登記的，做完請到「AS 文件排程」登記完成，否則會一直顯示逾期。' . "\n";
    elseif (($r['src_kind'] ?? '') === 'none')
        $body .= '※系統查不到這份文件的完成紀錄（沒有對應模組也沒有紙本紀錄），請到「AS 文件排程」登記完成。' . "\n";
    if ($round > 1) $body .= '※這是第 ' . $round . ' 次逾期提醒。' . "\n";
    $body .= '點此開啟「AS 文件排程」查看本年度的完整排程與逾期清單。';

    // ── 站內通知（本模組必須有）──
    $eid = 0;
    try {
        /* 同一份文件同一週期若還有上一輪沒被讀掉的提醒掛著，先收掉避免通知欄一直疊。
           ref_id 只能放一個整數，故用 doc_id；同一份文件不同週期的舊提醒一起收掉是可接受的
           （舊週期的提醒本來就沒有繼續掛著的意義）。 */
        $db->prepare("UPDATE live_event SET enddate=DATE_SUB(CURDATE(), INTERVAL 1 DAY)
                      WHERE ref_type='AS_SCHED_REMIND' AND ref_id=? AND (enddate IS NULL OR enddate>=CURDATE())")
           ->execute([(int)$r['doc_id']]);

        $db->prepare("INSERT INTO live_event (eventdate, enddate, title, content, status, created_by, source,
                        show_status_to_others, ref_type, ref_id)
                      VALUES (CURDATE(), NULL, ?, ?, 0, 0, 'AS文件排程', 1, 'AS_SCHED_REMIND', ?)")
           ->execute([$title, $body, (int)$r['doc_id']]);
        $eid = (int)$db->lastInsertId();
        $ins = $db->prepare("INSERT INTO live_event_target (live_event_id, target_type, target_id, mode)
                             VALUES (?, 'user', ?, 'read')");
        foreach ($uids as $u) $ins->execute([$eid, (int)$u]);
    } catch (Throwable $e) {
        error_log('[asched] live_event failed: ' . $e->getMessage());
    }

    // ── Web Push（有訂閱才收得到，屬加分不是必要）──
    try {
        require_once __DIR__ . '/../push/push_send.php';
        $to = $eid && function_exists('eg_push_event_recipients') ? eg_push_event_recipients($db, $eid) : $uids;
        eg_push_send_to_users($db, $to, [
            'title' => $title,
            'body'  => mb_substr($body, 0, 480),
            'url'   => '/EGsystem/views/ADM/as_schedule.php',
            'tag'   => 'as-sched-' . (int)$r['doc_id'] . '-' . $r['period_key'],
        ]);
    } catch (Throwable $e) {
        error_log('[asched] push failed: ' . $e->getMessage());
    }

    // ── Telegram（綁定者才有；訊息內不放 URL，依 telegram spec）──
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
        error_log('[asched] telegram failed: ' . $e->getMessage());
    }
}

/**
 * 掃描本年度到期／逾期的排程並發提醒。
 * @return int 實際發出去的筆數
 */
function asched_process_due(PDO $db): int
{
    asched_ensure($db);
    $set = asched_settings($db);
    if (empty($set['notify_enabled'])) return 0;

    // 業務日期一律取 DB（PHP date() 是 UTC、MySQL 是本地，混用會差 8 小時）
    try { $today = (string)$db->query("SELECT CURDATE()")->fetchColumn(); }
    catch (Throwable $e) { $today = date('Y-m-d'); }
    $year = (int)substr($today, 0, 4);

    $plan = asched_plan($db, $year);
    $sent = 0;

    // 已發送記錄
    $log = [];
    try {
        foreach ($db->query("SELECT doc_id, period_key, phase, sent_at, sent_round FROM as_sched_notify_log")
                    ->fetchAll(PDO::FETCH_ASSOC) as $l) {
            $log[$l['doc_id'] . '|' . $l['period_key'] . '|' . $l['phase']] = $l;
        }
    } catch (Throwable $e) { /* 表剛建＝都沒發過 */ }

    foreach ($plan['rows'] as $r) {
        $state = (string)$r['state'];
        // 只對 due／overdue 發；unknown 與 nomonth 不發（見檔頭說明）
        if ($state !== 'due' && $state !== 'overdue') continue;
        if (!$r['depts']) continue;                  // 沒設負責課室＝不知道要發給誰（缺口盤點會列出來）

        $phase = $state === 'overdue' ? 'over' : 'lead';
        $key   = $r['doc_id'] . '|' . $r['period_key'] . '|' . $phase;
        $round = 1;

        if (isset($log[$key])) {
            // 到期前提醒只發一次
            if ($phase === 'lead') continue;
            // 逾期提醒依設定每 N 天再發一輪（0＝不重複）
            $rep = (int)$set['overdue_repeat'];
            if ($rep <= 0) continue;
            $lastAt = strtotime((string)$log[$key]['sent_at']);
            if ($lastAt === false) continue;
            if ((strtotime($today) - $lastAt) < $rep * 86400) continue;
            $round = (int)$log[$key]['sent_round'] + 1;
        }

        try {
            /* 搶佔：INSERT ... ON DUPLICATE KEY UPDATE 但只在「sent_round 仍是舊值」時才更新，
               兩個背景程序同時跑只有一個 rowCount 會 >0。
               affected rows：新插入=1、真的更新=2、沒變動=0 */
            $st = $db->prepare("INSERT INTO as_sched_notify_log (doc_id, period_key, phase, sent_at, sent_round)
                                VALUES (?,?,?,NOW(),?)
                                ON DUPLICATE KEY UPDATE
                                    sent_at    = IF(sent_round < VALUES(sent_round), NOW(), sent_at),
                                    sent_round = IF(sent_round < VALUES(sent_round), VALUES(sent_round), sent_round)");
            $st->execute([(int)$r['doc_id'], (string)$r['period_key'], $phase, $round]);
            if ($st->rowCount() < 1) continue;       // 已被別的程序搶到

            $uids = asched_notify_targets($db, array_map(fn($d) => (int)$d['id'], (array)$r['depts']));
            if (!$uids) continue;                    // 沒有有效對象（標記已留，不會每分鐘重試）
            asched_send_one($db, $r, $uids, $phase, $round);
            $sent++;
        } catch (Throwable $e) {
            error_log('[asched] send failed for doc_id=' . $r['doc_id'] . ' ' . $r['period_key'] . ': ' . $e->getMessage());
        }
    }
    return $sent;
}

}
