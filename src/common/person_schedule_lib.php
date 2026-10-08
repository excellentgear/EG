<?php
/**
 * 人員當日行程共用庫（2026-08-26 建立）——「挑人的時候要看得到這個人當天有沒有別的事」的唯一實作點
 *
 * 使用者要求（2026-08-26，會議紀錄管理挑出席人員時提出，訂為全站作法）：
 *   ①人員選單要抓「該表單日期當日」的狀態（還沒入職／已離職的都不可出現）→ 走 people_lib 的 eg_people_list_asof()
 *   ②當天請假、外出（公出單）、外訓（教育訓練）、已排定的其他會議，要顯示在人員名稱右方
 *     例：陳俊宏（總經理）10:00~11:00 會議
 *   ③自動判定跟表單的「開始時間~結束時間」有沒有重疊，有重疊要提示
 *   ④**哪些來源要列出提示、哪些不顯示，一律做成設定不可寫死**
 *
 * 為什麼設定不放在「行事曆設定」（使用者問過）：
 *   行事曆（evenement）是**全公司的事件表、沒有 user_id**，設定頁管的是事件類別與顏色；
 *   這裡要的是「某個人當天有什麼事」，資料分別來自請假單／公出單／訓練簽到／會議出席四張表，
 *   行事曆根本沒有這份資料。故設定收在本檔（全站共用一份），設定 UI 掛在會議紀錄管理的模組設定。
 *
 * 阻擋規則（使用者拍板）：只有「請假」會擋（全天假、或假別時段涵蓋到會議時段者不可勾選），
 *   公出／外訓／其他會議一律只提示重疊、仍可勾選——現場常有人先開完前一場再過來。
 *
 * 新增來源的作法：在 eg_psched_sources() 加一列，並在下面照樣補一段查詢即可，
 *   設定畫面與前端提示都是依這份定義動態長出來的，不必再改 UI（鐵律4：不要在別處寫死一份對照表）。
 */

if (!function_exists('eg_psched_sources')) {
    /**
     * 行程來源定義（唯一一份；設定畫面與查詢都吃這裡）
     * blocks=1 表示此來源與表單時段重疊時「不可勾選」，0＝只提示
     */
    function eg_psched_sources(): array {
        return [
            'leave'    => ['label'=>'請假',      'desc'=>'請假單（含申請中、留職停薪等長期假）', 'blocks'=>1, 'default'=>1],
            'trip'     => ['label'=>'公出/外出', 'desc'=>'公出單（已送簽核／已核准）',            'blocks'=>0, 'default'=>1],
            'training' => ['label'=>'教育訓練',  'desc'=>'內訓／外訓已排定的受訓人員',            'blocks'=>0, 'default'=>1],
            'meeting'  => ['label'=>'其他會議',  'desc'=>'同一天已被排入的其他會議',              'blocks'=>0, 'default'=>1],
            // 2026-10-08 新增（使用者回報：行事曆上明明有「AS稽核-復評」這種會議類別事件，
            // 逐人每日工作量卻沒顯示）——查證發現現場有兩條平行路徑在記「這個人那天有
            // 會議」：走正式會議紀錄管理模組的（上面 'meeting' 來源，讀 meeting_record）、
            // 跟直接在行事曆（evenement，走 category_id=2「會議」等分類）記一筆事件、挑
            // 參與人員（evenement_actor）的——後者完全沒有來源涵蓋到。'calendar' 一般
            // 行事曆事件：**刻意排除 category_id=1（休假）**，那一類已經由上面的 'leave'
            // 來源讀 leave_request 涵蓋（leave_calendar_lib.php 已把行事曆的休假事件雙向
            // 同步成請假單），這裡再讀一次會重複顯示同一件事。
            'calendar' => ['label'=>'行事曆事件', 'desc'=>'一般行事曆事件，例如會議／課程／公出／客戶來訪等分類（休假類別已由「請假」來源涵蓋，不重複）', 'blocks'=>0, 'default'=>1],
        ];
    }
}

if (!function_exists('eg_psched_setting_get')) {
    /** 目前啟用的來源：key => 0/1（沒設定過＝用各來源的預設值） */
    function eg_psched_setting_get(PDO $db): array {
        $on = [];
        foreach (eg_psched_sources() as $k => $d) $on[$k] = (int)$d['default'];
        try {
            $st = $db->prepare("SELECT setting_value FROM system_settings WHERE setting_key='person_schedule_sources'");
            $st->execute();
            $v = $st->fetchColumn();
            $j = ($v === false || $v === null) ? null : json_decode((string)$v, true);
            if (is_array($j)) foreach ($on as $k => $_) if (array_key_exists($k, $j)) $on[$k] = ((int)$j[$k] === 1) ? 1 : 0;
        } catch (Throwable $e) { /* 設定表讀不到＝用預設，不擋流程 */ }
        return $on;
    }
}

if (!function_exists('eg_psched_setting_save')) {
    /** 存設定（只收 eg_psched_sources() 認得的 key，其餘忽略） */
    function eg_psched_setting_save(PDO $db, array $on): array {
        $save = [];
        foreach (eg_psched_sources() as $k => $d) $save[$k] = (isset($on[$k]) && (int)$on[$k] === 1) ? 1 : 0;
        $db->prepare("INSERT INTO system_settings (setting_key, setting_value) VALUES ('person_schedule_sources', ?)
                      ON DUPLICATE KEY UPDATE setting_value=VALUES(setting_value)")
           ->execute([json_encode($save, JSON_UNESCAPED_UNICODE)]);
        return $save;
    }
}

if (!function_exists('eg_psched_hhmm')) {
    /** 各表的時間欄位格式不一（'09:00'、'09:00:00'、datetime），統一成 HH:MM */
    function eg_psched_hhmm($v, string $fallback = ''): string {
        $v = trim((string)$v);
        if ($v === '') return $fallback;
        if (preg_match('/([0-9]{1,2}):([0-9]{2})/', $v, $m)) return sprintf('%02d:%02d', (int)$m[1], (int)$m[2]);
        return $fallback;
    }
}

if (!function_exists('eg_psched_for_users')) {
    /**
     * 查一批人在某一天的行程
     *
     * @param array  $userIds
     * @param string $date   Y-m-d
     * @param string $start  表單開始時間 HH:MM（空＝視同全天 00:00）
     * @param string $end    表單結束時間 HH:MM（空＝視同全天 23:59）
     * @param array  $opt    exclude_meeting_id：編輯既有會議時，不要把「這場會議自己」算成衝突
     * @return array user_id => [ ['source','label','time','text','allday','overlap','blocks'], ... ]
     *               overlap=1 表示與表單時段重疊；blocks=1 表示重疊且此來源不可勾選
     */
    function eg_psched_for_users(PDO $db, array $userIds, string $date, string $start = '', string $end = '', array $opt = []): array {
        $out = [];
        $ids = array_values(array_unique(array_filter(array_map('intval', $userIds))));
        if (!$ids || !preg_match('/^[0-9]{4}-[0-9]{2}-[0-9]{2}$/', $date)) return $out;

        $on   = eg_psched_setting_get($db);
        $defs = eg_psched_sources();
        $mS   = eg_psched_hhmm($start, '00:00');
        $mE   = eg_psched_hhmm($end,   '23:59');
        if ($mE <= $mS) $mE = '23:59';                    // 時間填反／只填一邊時當全天，寧可多提示不要漏提示
        $in   = implode(',', $ids);

        $push = function (int $uid, string $src, string $s, string $e, bool $allday, string $what) use (&$out, $defs, $mS, $mE) {
            $s = eg_psched_hhmm($s, '00:00'); $e = eg_psched_hhmm($e, '23:59');
            if ($allday) { $s = '00:00'; $e = '23:59'; }
            $overlap = ($s < $mE && $e > $mS) ? 1 : 0;     // 半開區間：09:00~10:00 與 10:00~11:00 不算重疊
            $time    = $allday ? '全天' : ($s . '~' . $e);
            $out[$uid][] = [
                'source'  => $src,
                'label'   => $defs[$src]['label'],
                'time'    => $time,
                'text'    => $time . ' ' . $what,          // 例：10:00~11:00 會議（產銷協調會議）
                'allday'  => $allday ? 1 : 0,
                'overlap' => $overlap,
                'blocks'  => ($overlap && (int)$defs[$src]['blocks'] === 1) ? 1 : 0,
            ];
        };

        // ── 請假：沿用既有判定（pending／cancel_pending／approved 都算，與代理系統同一套口徑）
        if (!empty($on['leave'])) {
            try {
                $st = $db->prepare("SELECT lr.employee_id, lr.start_datetime, lr.end_datetime, lt.leave_name
                                    FROM leave_request lr LEFT JOIN leave_type lt ON lt.id = lr.leave_type_id
                                    WHERE lr.employee_id IN ($in)
                                      AND lr.status IN ('pending','cancel_pending','approved')
                                      AND lr.start_datetime < ? AND lr.end_datetime > ?
                                    ORDER BY lr.start_datetime");
                $st->execute([$date . ' 23:59:59', $date . ' 00:00:00']);
                foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $r) {
                    $ds = substr((string)$r['start_datetime'], 0, 10);
                    $de = substr((string)$r['end_datetime'],   0, 10);
                    $allday = ($ds < $date || $de > $date);   // 跨日的假＝這一天整天都不在
                    $push((int)$r['employee_id'], 'leave',
                          $ds === $date ? substr((string)$r['start_datetime'], 11, 5) : '00:00',
                          $de === $date ? substr((string)$r['end_datetime'],   11, 5) : '23:59',
                          $allday, (string)($r['leave_name'] ?: '請假'));
                }
            } catch (Throwable $e) { /* 表不存在／查詢失敗＝這個來源不提示，不擋挑人 */ }
        }

        // ── 公出單（外出）：只算已送簽核／已核准的，草稿不提示
        if (!empty($on['trip'])) {
            try {
                $st = $db->prepare("SELECT t.user_id, t.location, t.date_from, t.date_to, t.time_from, t.time_to,
                                           d.start_time AS d_start, d.end_time AS d_end
                                    FROM business_trip t
                                    LEFT JOIN business_trip_day d ON d.trip_id = t.trip_id AND d.day_date = ?
                                    WHERE t.user_id IN ($in) AND COALESCE(t.is_deleted,0)=0
                                      AND t.status IN ('submitted','approved')
                                      AND t.date_from <= ? AND t.date_to >= ?");
                $st->execute([$date, $date, $date]);
                foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $r) {
                    $s = (string)($r['d_start'] ?: (((string)$r['date_from'] === $date) ? $r['time_from'] : ''));
                    $e = (string)($r['d_end']   ?: (((string)$r['date_to']   === $date) ? $r['time_to']   : ''));
                    $loc = trim((string)$r['location']);
                    $push((int)$r['user_id'], 'trip', $s, $e, ($s === '' && $e === ''),
                          '公出' . ($loc !== '' ? '（' . $loc . '）' : ''));
                }
            } catch (Throwable $e) { }
        }

        // ── 教育訓練（外訓／內訓）：已排定或已完成的場次，取當天那一列的時間
        if (!empty($on['training'])) {
            try {
                $st = $db->prepare("SELECT a.user_id, s.course_name, s.train_type,
                                           COALESCE(d.start_time, s.start_time) AS st, COALESCE(d.end_time, s.end_time) AS et
                                    FROM training_attendee a
                                    JOIN training_session s ON s.session_id = a.session_id
                                    JOIN training_session_day d ON d.session_id = s.session_id AND d.day_date = ?
                                    WHERE a.user_id IN ($in) AND s.status IN ('scheduled','done')");
                $st->execute([$date]);
                foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $r) {
                    $kind = ((string)$r['train_type'] === 'external') ? '外訓' : '教育訓練';
                    $s = (string)$r['st']; $e = (string)$r['et'];
                    $push((int)$r['user_id'], 'training', $s, $e, ($s === '' && $e === ''),
                          $kind . '（' . (string)$r['course_name'] . '）');
                }
            } catch (Throwable $e) { }
        }

        // ── 其他會議：同一天已被排入出席的其他會議（編輯中的這一場不算自己衝突）
        if (!empty($on['meeting'])) {
            try {
                $ex  = (int)($opt['exclude_meeting_id'] ?? 0);
                $sql = "SELECT a.user_id, m.subject, m.start_time, m.end_time
                        FROM meeting_attendee a
                        JOIN meeting_record m ON m.meeting_id = a.meeting_id
                        WHERE a.user_id IN ($in) AND m.meeting_date = ?";
                $args = [$date];
                if ($ex > 0) { $sql .= " AND m.meeting_id <> ?"; $args[] = $ex; }
                $st = $db->prepare($sql);
                $st->execute($args);
                foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $r) {
                    $s = (string)$r['start_time']; $e = (string)$r['end_time'];
                    $subj = trim((string)$r['subject']);
                    $push((int)$r['user_id'], 'meeting', $s, $e, ($s === '' && $e === ''),
                          '會議' . ($subj !== '' ? '（' . $subj . '）' : ''));
                }
            } catch (Throwable $e) { }
        }

        // ── 一般行事曆事件（2026-10-08 新增，見 eg_psched_sources() 的 'calendar' 註解）：
        // evenement 多日事件的 start/end 可能橫跨好幾天且是 datetime，判斷「這一天是不是
        // 整天都在」的邏輯跟上面請假那段同一個道理——起訖日不等於當天的，那一天視同全天。
        if (!empty($on['calendar'])) {
            try {
                $st = $db->prepare("SELECT ea.user_id, e.title, e.start, e.end, e.allday, ec.category_name
                                    FROM evenement_actor ea
                                    JOIN evenement e ON e.id = ea.event_id
                                    LEFT JOIN event_category ec ON ec.id = e.category_id
                                    WHERE ea.user_id IN ($in) AND (e.category_id IS NULL OR e.category_id <> 1)
                                      AND e.start < ? AND e.end > ?");
                $st->execute([$date . ' 23:59:59', $date . ' 00:00:00']);
                foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $r) {
                    $ds = substr((string)$r['start'], 0, 10);
                    $de = substr((string)$r['end'], 0, 10);
                    $allday = !empty($r['allday']) || $ds < $date || $de > $date;
                    $cat = trim((string)($r['category_name'] ?: '行事曆'));
                    $title = trim((string)$r['title']);
                    $push((int)$r['user_id'], 'calendar',
                          $ds === $date ? substr((string)$r['start'], 11, 5) : '00:00',
                          $de === $date ? substr((string)$r['end'],   11, 5) : '23:59',
                          $allday, $cat . ($title !== '' ? '：' . $title : ''));
                }
            } catch (Throwable $e) { }
        }

        // 同一人多筆：先列與表單時段重疊的，再依開始時間排序（讓最該注意的排最前面）
        foreach ($out as $uid => $list) {
            usort($list, function ($a, $b) {
                return [$b['overlap'], $a['time']] <=> [$a['overlap'], $b['time']];
            });
            $out[$uid] = $list;
        }
        return $out;
    }
}

if (!function_exists('eg_psched_blocked')) {
    /** 這個人是否因行程而「不可勾選」（目前只有請假會擋，見檔頭） */
    function eg_psched_blocked(array $items): bool {
        foreach ($items as $it) if (!empty($it['blocks'])) return true;
        return false;
    }
}
