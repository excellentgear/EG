<?php
/**
 * leave_calendar_lib.php — 行事曆「休假」事件 → 自動建立請假單（2026-09-17 建立）
 *
 * 背景：行事曆（views/pages/calendar.php）早就可以挑「休假」類別＋假別＋發生者，
 * 人事實際上都是直接在那裡登錄；但那只是一筆 `evenement`，請假系統裡**一張單都沒有**
 * （實測 `leave_request` 是空的、行事曆卻有 484 筆休假），所以請假統計、特休額度、
 * 代理人通知全部看不到這些假 —— 使用者要求兩邊自動接起來。
 *
 * 設計重點（會影響往後維護，別自行更動）：
 *   1. **唯一建單入口仍是 `eg_leave_submit()`**，這裡只負責「從事件抓出參數、決定放寬哪些前置檢查」。
 *      不要在這裡另外寫一份 INSERT leave_request，否則時數計算／代理人解析／簽核鏈會有兩套。
 *   2. **不去動使用者畫的那筆事件**（不改類別、不改標題）。一筆事件可能掛好幾個人，
 *      改它等於改到別人的。只把 `leave_request.evenement_id` 指過去，銷假時才知道要從哪一筆抽掉這個人。
 *   3. **迴圈保護**：請假系統核准時自己也會建一筆行事曆事件，那種事件一律不可再回頭建單
 *      （判定＝已經有 `leave_request` 指著它，或類別是「請假申請中」）。
 *   4. 判「哪些事件算請假」一律**查類別名稱**（休假），不寫死 category_id＝1（鐵律4）。
 *   5. 全天事件的結束日在行事曆是**含當天**（實測 9/14~9/18 就是請 5 天），
 *      所以換算成假單是 `起日 00:00:00 ~ 迄日 23:59:59`，不可直接拿 `end` 的 00:00:00（會變成 0 小時）。
 */

require_once __DIR__ . '/leave_lib.php';

if (!function_exists('eg_leave_cal_ensure_schema')) {
    /** leave_request 補兩個來源欄位（可重複執行） */
    function eg_leave_cal_ensure_schema(PDO $db): void {
        static $done = false;
        if ($done) return;
        $done = true;
        try {
            $cols = $db->query("SHOW COLUMNS FROM leave_request")->fetchAll(PDO::FETCH_COLUMN);
            if (!in_array('source', $cols, true)) {
                $db->exec("ALTER TABLE leave_request
                           ADD COLUMN `source` VARCHAR(20) NOT NULL DEFAULT 'form'
                               COMMENT '單據來源：form=請假系統送審、calendar=行事曆休假事件自動建立'");
            }
            if (!in_array('source_event_id', $cols, true)) {
                $db->exec("ALTER TABLE leave_request
                           ADD COLUMN `source_event_id` INT NULL
                               COMMENT '來源行事曆事件 evenement.id（source=calendar 時才有；同一筆事件可對到多個人的單）',
                           ADD KEY `idx_source_event` (`source_event_id`)");
            }
        } catch (Throwable $e) { /* 已存在或無權限：不擋流程 */ }
    }
}

// ============================== 設定 ==============================

if (!function_exists('eg_leave_cal_settings')) {
    /**
     * 行事曆自動建單設定（system_settings）。預設值＝使用者指定的「自動建立、不需簽核」。
     *   leave_cal_auto_create   1=行事曆存檔時自動建假單
     *   leave_cal_need_approval 1=建出來的單要跑簽核鏈；0=直接核准（預設）
     *   leave_cal_notify        1=建單後通知當事人；0=不通知（預設，人事整批登錄時不洗版）
     */
    function eg_leave_cal_settings(PDO $db): array {
        $def = ['leave_cal_auto_create' => '1', 'leave_cal_need_approval' => '0', 'leave_cal_notify' => '0'];
        try {
            $st = $db->query("SELECT setting_key, setting_value FROM system_settings
                              WHERE setting_key IN ('leave_cal_auto_create','leave_cal_need_approval','leave_cal_notify')");
            foreach ($st->fetchAll(PDO::FETCH_KEY_PAIR) as $k => $v) $def[$k] = (string)$v;
        } catch (Throwable $e) {}
        return $def;
    }
}

if (!function_exists('eg_leave_cal_save_settings')) {
    /** 存設定（呼叫端負責驗管理員權限） */
    function eg_leave_cal_save_settings(PDO $db, array $in, int $uid, string $uname = ''): void {
        $allow = ['leave_cal_auto_create', 'leave_cal_need_approval', 'leave_cal_notify'];
        $st = $db->prepare("INSERT INTO system_settings (setting_key, setting_value, updated_by_id, updated_by, updated_at)
                            VALUES (?,?,?,?,NOW())
                            ON DUPLICATE KEY UPDATE setting_value=VALUES(setting_value),
                                updated_by_id=VALUES(updated_by_id), updated_by=VALUES(updated_by), updated_at=NOW()");
        foreach ($allow as $k) {
            if (!array_key_exists($k, $in)) continue;
            $st->execute([$k, (!empty($in[$k]) ? '1' : '0'), $uid, $uname]);
        }
    }
}

if (!function_exists('eg_leave_cal_category_ids')) {
    /** 算是「請假」的行事曆類別 id（查名稱不寫死 id）。 */
    function eg_leave_cal_category_ids(PDO $db): array {
        static $ids = null;
        if ($ids !== null) return $ids;
        $ids = [];
        try {
            $st = $db->prepare("SELECT id FROM event_category WHERE category_name = '休假'");
            $st->execute();
            $ids = array_map('intval', $st->fetchAll(PDO::FETCH_COLUMN));
        } catch (Throwable $e) {}
        return $ids;
    }
}

// ============================== 事件 → 假單 ==============================

if (!function_exists('eg_leave_cal_period')) {
    /**
     * 事件時間換算成假單起訖。
     * 全天事件：行事曆的結束日是「含當天」，換成 起日 00:00:00 ~ 迄日 23:59:59
     *（時數計算是逐工作日取重疊、單日上限一天工時，所以這樣算出來剛好是整天）。
     */
    function eg_leave_cal_period(array $ev): array {
        $s = (string)$ev['start'];
        $e = (string)$ev['end'];
        if (!empty($ev['allday'])) {
            $sd = substr($s, 0, 10);
            $ed = substr($e ?: $s, 0, 10);
            if ($ed < $sd) $ed = $sd;
            return [$sd . ' 00:00:00', $ed . ' 23:59:59'];
        }
        if (!$e || strtotime($e) <= strtotime($s)) $e = date('Y-m-d H:i:s', strtotime($s) + 3600);
        return [$s, $e];
    }
}

if (!function_exists('eg_leave_cal_sync_event')) {
    /**
     * 把一筆行事曆休假事件同步成假單（事件上的每個發生者各一張）。
     *
     * @param array $opt  force=1 忽略「自動建單」總開關（管理員手動補建時用）
     *                    submit_time_from_event=1 把送單時間寫成事件當時（補舊資料用）
     * @return array ['created'=>[], 'skipped'=>[], 'failed'=>[]]
     */
    function eg_leave_cal_sync_event(PDO $db, int $eventId, array $opt = []): array {
        eg_leave_cal_ensure_schema($db);
        $out = ['created' => [], 'skipped' => [], 'failed' => []];
        $cfg = eg_leave_cal_settings($db);
        if (empty($opt['force']) && $cfg['leave_cal_auto_create'] !== '1') {
            $out['skipped'][] = ['event_id' => $eventId, 'reason' => '自動建單已關閉'];
            return $out;
        }

        $st = $db->prepare("SELECT id, title, category_id, leave_type_id, start, end, allday, remark
                            FROM evenement WHERE id = ? LIMIT 1");
        $st->execute([$eventId]);
        $ev = $st->fetch(PDO::FETCH_ASSOC);
        if (!$ev) { $out['skipped'][] = ['event_id' => $eventId, 'reason' => '事件不存在']; return $out; }

        $catIds = eg_leave_cal_category_ids($db);
        if (!$catIds || !in_array((int)$ev['category_id'], $catIds, true)) {
            $out['skipped'][] = ['event_id' => $eventId, 'reason' => '不是休假類別']; return $out;
        }
        if (!$ev['leave_type_id']) {
            $out['skipped'][] = ['event_id' => $eventId, 'reason' => '沒有指定假別，無法建單']; return $out;
        }
        // 迴圈保護：請假系統核准時自己寫的事件，不可以再回頭建一張單
        $st = $db->prepare("SELECT COUNT(*) FROM leave_request WHERE evenement_id = ? AND (source IS NULL OR source <> 'calendar')");
        $st->execute([$eventId]);
        if ((int)$st->fetchColumn() > 0) {
            $out['skipped'][] = ['event_id' => $eventId, 'reason' => '這筆事件本來就是請假系統建的']; return $out;
        }

        [$start, $end] = eg_leave_cal_period($ev);
        $tid = (int)$ev['leave_type_id'];
        $needApproval = ($cfg['leave_cal_need_approval'] === '1');
        $noNotify = ($cfg['leave_cal_notify'] !== '1');
        $subTime = !empty($opt['submit_time_from_event']) ? substr($start, 0, 10) . ' 09:00:00' : '';

        $st = $db->prepare("SELECT a.user_id, u.user_cname, u.state FROM evenement_actor a
                            JOIN `user` u ON u.id = a.user_id WHERE a.event_id = ?");
        $st->execute([$eventId]);
        $actors = $st->fetchAll(PDO::FETCH_ASSOC);
        if (!$actors) { $out['skipped'][] = ['event_id' => $eventId, 'reason' => '事件沒有指定發生者（不知道是誰請假）']; return $out; }

        foreach ($actors as $a) {
            $uid = (int)$a['user_id'];
            $who = (string)$a['user_cname'];
            // 已經有單就不要重複建（含本事件先前建過的、以及同時段人工送的單）
            $chk = $db->prepare("SELECT id, source_event_id FROM leave_request
                                 WHERE employee_id = ? AND status IN ('pending','cancel_pending','approved')
                                   AND start_datetime < ? AND end_datetime > ? LIMIT 1");
            $chk->execute([$uid, $end, $start]);
            if ($dup = $chk->fetch(PDO::FETCH_ASSOC)) {
                $out['skipped'][] = ['event_id' => $eventId, 'user_id' => $uid, 'name' => $who,
                                     'reason' => '此時段已有請假單 #' . $dup['id']];
                continue;
            }
            $r = eg_leave_submit($db, [
                'employee_id'    => $uid,
                'leave_type_id'  => $tid,
                'start_datetime' => $start,
                'end_datetime'   => $end,
                'reason'         => trim((string)($ev['remark'] ?? '')) !== '' ? (string)$ev['remark'] : '（由行事曆帶入）',
                '_auto' => [
                    'source'               => 'calendar',
                    'event_id'             => $eventId,
                    'force_approval'       => $needApproval,
                    'skip_backdate_limit'  => true,
                    'skip_quota_block'     => true,
                    'skip_rule_block'      => true,
                    'skip_attach_block'    => true,
                    'no_notify'            => $noNotify,
                    'submit_time'          => $subTime,
                ],
            ]);
            if (!empty($r['ok'])) {
                $out['created'][] = ['event_id' => $eventId, 'user_id' => $uid, 'name' => $who,
                                     'request_id' => (int)$r['id'], 'status' => $r['status'] ?? '',
                                     'warns' => $r['warns'] ?? []];
            } else {
                $out['failed'][] = ['event_id' => $eventId, 'user_id' => $uid, 'name' => $who,
                                    'reason' => (string)($r['msg'] ?? '建立失敗')];
            }
        }
        return $out;
    }
}

if (!function_exists('eg_leave_cal_resync_event')) {
    /**
     * 行事曆上那筆休假被改過（時間、假別、發生者、甚至改成別的類別）之後，把假單重新對齊。
     *
     * 作法：先把「已經對不上」的自動建單清掉，再重跑一次 sync 補齊。
     *   · 發生者被移除／類別改成不是休假 → 那個人的自動單刪掉
     *   · 時間或假別被改 → 刪掉重建（時數、額度都要跟著重算，改欄位不會重算）
     *   · 人工送審的單（source<>'calendar'）一律不動——那是本人自己送的，不可以被行事曆蓋掉
     *
     * 刪之前一定要先把 `evenement_id` 清成 NULL：eg_leave_delete() 會連帶把這個人從事件的
     * 發生者名單裡抽掉，而這裡的事件是使用者自己畫的那一筆，抽掉就等於把人家的行事曆改壞了。
     */
    function eg_leave_cal_resync_event(PDO $db, int $eventId, array $opt = []): array {
        eg_leave_cal_ensure_schema($db);
        $out = ['removed' => [], 'created' => [], 'skipped' => [], 'failed' => []];

        $st = $db->prepare("SELECT id, category_id, leave_type_id, start, end, allday FROM evenement WHERE id = ? LIMIT 1");
        $st->execute([$eventId]);
        $ev = $st->fetch(PDO::FETCH_ASSOC);
        $catIds = eg_leave_cal_category_ids($db);
        // deleting=1：呼叫端正要把這筆事件刪掉（必須在刪除前呼叫，刪掉就撈不到了），
        // 一律視同「已經不是休假」＝把這筆事件建出來的假單全部撤掉
        $stillLeave = empty($opt['deleting'])
                   && $ev && $catIds && in_array((int)$ev['category_id'], $catIds, true) && $ev['leave_type_id'];

        $actors = [];
        if ($ev) {
            $st = $db->prepare("SELECT user_id FROM evenement_actor WHERE event_id = ?");
            $st->execute([$eventId]);
            $actors = array_map('intval', $st->fetchAll(PDO::FETCH_COLUMN));
        }
        [$start, $end] = $stillLeave ? eg_leave_cal_period($ev) : ['', ''];

        $st = $db->prepare("SELECT id, employee_id, leave_type_id, start_datetime, end_datetime
                            FROM leave_request WHERE source_event_id = ? AND source = 'calendar'");
        $st->execute([$eventId]);
        foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $r) {
            $keep = $stillLeave
                 && in_array((int)$r['employee_id'], $actors, true)
                 && (int)$r['leave_type_id'] === (int)$ev['leave_type_id']
                 && (string)$r['start_datetime'] === $start
                 && (string)$r['end_datetime'] === $end;
            if ($keep) continue;
            $db->prepare("UPDATE leave_request SET evenement_id = NULL WHERE id = ?")->execute([(int)$r['id']]);
            eg_leave_delete($db, (int)$r['id'], (int)($opt['operator_id'] ?? 0));
            $out['removed'][] = ['request_id' => (int)$r['id'], 'user_id' => (int)$r['employee_id']];
        }

        if ($stillLeave) {
            $r = eg_leave_cal_sync_event($db, $eventId, $opt);
            foreach (['created', 'skipped', 'failed'] as $k) $out[$k] = $r[$k];
        }
        return $out;
    }
}

if (!function_exists('eg_leave_cal_pending_events')) {
    /**
     * 還沒建成假單的行事曆休假事件（補舊資料用）。
     * @param array $opt from/to = 事件起日區間（Y-m-d，可省略）
     */
    function eg_leave_cal_pending_events(PDO $db, array $opt = []): array {
        eg_leave_cal_ensure_schema($db);
        $catIds = eg_leave_cal_category_ids($db);
        if (!$catIds) return [];
        $w = ['e.category_id IN (' . implode(',', $catIds) . ')'];
        $args = [];
        if (!empty($opt['from'])) { $w[] = 'e.start >= ?'; $args[] = substr($opt['from'], 0, 10) . ' 00:00:00'; }
        if (!empty($opt['to']))   { $w[] = 'e.start <= ?'; $args[] = substr($opt['to'], 0, 10) . ' 23:59:59'; }
        $sql = "SELECT e.id, e.title, e.start, e.end, e.allday, e.leave_type_id, lt.leave_name,
                       (SELECT COUNT(*) FROM evenement_actor a WHERE a.event_id = e.id) AS actor_cnt,
                       (SELECT COUNT(*) FROM leave_request lr WHERE lr.source_event_id = e.id) AS req_cnt,
                       (SELECT COUNT(*) FROM leave_request lr2 WHERE lr2.evenement_id = e.id
                          AND (lr2.source IS NULL OR lr2.source <> 'calendar')) AS own_cnt
                FROM evenement e
                LEFT JOIN leave_type lt ON lt.id = e.leave_type_id
                WHERE " . implode(' AND ', $w) . "
                ORDER BY e.start DESC, e.id DESC";
        $st = $db->prepare($sql);
        $st->execute($args);
        $rows = $st->fetchAll(PDO::FETCH_ASSOC);
        $out = [];
        foreach ($rows as $r) {
            if ((int)$r['own_cnt'] > 0) continue;                       // 請假系統自己建的事件
            $blocked = '';
            if (!$r['leave_type_id'])        $blocked = '沒有指定假別';
            elseif ((int)$r['actor_cnt'] === 0) $blocked = '沒有指定發生者';
            else {
                // 整段都落在假日／週末：本來就不會扣到假，建不出單也不該一直留在待補清單上碎念
                [$s0, $e0] = eg_leave_cal_period($r);
                $amt = eg_leave_calc_amount($db, 'day', $s0, $e0);
                if ($amt['workdays'] <= 0) $blocked = '該期間沒有工作日（全公司放假或週末），不需要請假單';
            }
            $r['done'] = ((int)$r['req_cnt'] >= max(1, (int)$r['actor_cnt']));
            $r['blocked'] = $blocked;
            $out[] = $r;
        }
        return $out;
    }
}

if (!function_exists('eg_leave_cal_backfill')) {
    /**
     * 整批把舊的行事曆休假補成假單。
     * @param array $opt from/to 區間；limit 單次上限；dry=1 只試算不寫入
     */
    function eg_leave_cal_backfill(PDO $db, array $opt = []): array {
        $events = eg_leave_cal_pending_events($db, $opt);
        $limit  = (int)($opt['limit'] ?? 0);
        $report = ['events' => 0, 'created' => [], 'skipped' => [], 'failed' => []];
        foreach ($events as $ev) {
            if ($ev['done']) continue;
            if ($ev['blocked'] !== '') {
                $report['skipped'][] = ['event_id' => (int)$ev['id'], 'title' => $ev['title'],
                                        'start' => $ev['start'], 'reason' => $ev['blocked']];
                continue;
            }
            $report['events']++;
            if (!empty($opt['dry'])) {
                $report['created'][] = ['event_id' => (int)$ev['id'], 'title' => $ev['title'],
                                        'start' => $ev['start'], 'dry' => true];
            } else {
                $r = eg_leave_cal_sync_event($db, (int)$ev['id'], ['force' => 1, 'submit_time_from_event' => 1]);
                foreach (['created', 'skipped', 'failed'] as $k) {
                    foreach ($r[$k] as $x) { $x['title'] = $ev['title']; $x['start'] = $ev['start']; $report[$k][] = $x; }
                }
            }
            if ($limit > 0 && $report['events'] >= $limit) break;
        }
        return $report;
    }
}
