<?php
/**
 * leave_lib.php — 請假系統 商業邏輯共用庫
 * 規範文件：ai-rules/12-請假系統製作說明.md、ai-rules/11-代理系統設計.md
 *
 * 鐵律：
 *  - 代理解析唯一入口 delegate_lib.php 的 eg_resolve_signer()／eg_person_delegate_candidates()，
 *    禁用 leave_agent_setting（廢棄空表）、禁自寫 user_delegate SQL。
 *  - 兩張簽核表職責分開：leave_approval=流程狀態（每層應簽/已決行，送審時一次建好）；
 *    leave_sign_record=只增不改的簽章軌跡。同一 transaction 各寫各的，不互相推導。
 *  - 代理人不參與簽核（2026-07-28 使用者定案）：leave_type.agent=1 ＝「送單時必須指定代理人」，
 *    核准後通知代理人接手；leave_sign_record.step_no=0 保留不用。
 *  - 行事曆：送審即寫「請假申請中」事件（申請中章），核准後同一筆改為「休假」正式顯示；
 *    原有休假行事曆事件完全不動。leave_request.evenement_id 記事件 id，銷假據此撤除。
 *  - 特休額度：src/common/annual_leave_lib.php 與員工列表同一套算法；超額送審時擋下。
 *
 * 主要入口：
 *   eg_leave_submit()   送審（transaction：主檔＋簽核列＋行事曆＋附件轉正＋通知）
 *   eg_leave_sign()     簽核（核准/退回；全過→核准收尾）
 *   eg_leave_cancel()   撤回（pending）/ 銷假（approved，直接生效並通知簽核者）
 *   eg_leave_preview_signers()  申請頁「將由誰簽核」預覽
 *   eg_leave_pending_for()      某人的待簽清單
 *   eg_leave_annual_summary()   特休額度/已用/剩餘
 */

require_once __DIR__ . '/delegate_lib.php';
require_once __DIR__ . '/annual_leave_lib.php';

// ============================== 設定 ==============================

if (!function_exists('eg_leave_settings')) {
    /** 讀 system_settings 的 leave_* 設定（一次載入，靜態快取） */
    function eg_leave_settings(PDO $db): array {
        static $cache = null;
        if ($cache !== null) return $cache;
        $def = [
            'leave_attach_base'         => '',
            'leave_final_decider_id'    => '',
            'leave_backdate_limit_days' => '7',
            'leave_hours_per_day'       => '8',
            'leave_halfday_hours'       => '4',
            'leave_print_header'        => '',   // 列印表頭（空=用公司抬頭）
            'leave_print_footer'        => '',   // 列印表尾（表單編號等）
        ];
        try {
            $st = $db->query("SELECT setting_key, setting_value FROM system_settings WHERE setting_key LIKE 'leave\\_%'");
            foreach ($st->fetchAll(PDO::FETCH_KEY_PAIR) as $k => $v) $def[$k] = $v;
        } catch (Throwable $e) {}
        $cache = $def;
        return $cache;
    }
}

if (!function_exists('eg_leave_pending_category_id')) {
    /** 「請假申請中」事件類別 id（查名稱不寫死 id） */
    function eg_leave_pending_category_id(PDO $db): ?int {
        static $id = false;
        if ($id !== false) return $id;
        try {
            $st = $db->prepare("SELECT id FROM event_category WHERE category_name = '請假申請中' LIMIT 1");
            $st->execute();
            $v = $st->fetchColumn();
            $id = $v ? (int)$v : null;
        } catch (Throwable $e) { $id = null; }
        return $id;
    }
}

// ============================== 工作日與時數 ==============================

if (!function_exists('eg_leave_holiday_sets')) {
    /** 休假日(s)/補班日(m) 日期集合。同 car_lib.php car_holiday_sets()（資料源比照 calendar.php）。 */
    function eg_leave_holiday_sets(PDO $db): array {
        static $cache = null;
        if ($cache !== null) return $cache;
        $holidays = []; $makeups = [];
        try {
            $rows = $db->query(
                "SELECT DATE(e.start) AS d1, DATE(COALESCE(e.end, e.start)) AS d2, ec.day_type
                 FROM evenement e JOIN event_category ec ON e.category_id = ec.id
                 WHERE ec.day_type IN ('s','m')")->fetchAll(PDO::FETCH_ASSOC);
            foreach ($rows as $r) {
                $d = strtotime((string)$r['d1']); $end = strtotime((string)$r['d2']);
                if ($d === false || $end === false || $end < $d) continue;
                $guard = 0;
                while ($d <= $end && $guard++ < 400) {
                    $key = date('Y-m-d', $d);
                    if ($r['day_type'] === 's') $holidays[$key] = true; else $makeups[$key] = true;
                    $d = strtotime('+1 day', $d);
                }
            }
        } catch (Throwable $e) {}
        $cache = ['holidays' => $holidays, 'makeups' => $makeups];
        return $cache;
    }
}

if (!function_exists('eg_leave_is_workday')) {
    /** 某日是否工作日：週一~五且非休假日(s)；或補班日(m)（補班優先於週末） */
    function eg_leave_is_workday(PDO $db, string $date): bool {
        $sets = eg_leave_holiday_sets($db);
        $key = substr($date, 0, 10);
        if (isset($sets['makeups'][$key])) return true;
        $dow = (int)date('w', strtotime($key));
        if ($dow === 0 || $dow === 6) return false;
        return !isset($sets['holidays'][$key]);
    }
}

if (!function_exists('eg_leave_calc_amount')) {
    /**
     * 計算請假時數/天數（只計工作日；粒度依假別 unit_type）。
     *  - hour：逐工作日取「請假區間 ∩ 當日 08:00 起算的工時窗」重疊時數，單日上限 hours_per_day。
     *          為避免綁死上下班時間，同日請假直接取起訖差（跨午休不另扣），上限一天工時。
     *  - halfday：先按 hour 算，再向上取整到半天(halfday_hours)的倍數。
     *  - day：起訖涵蓋的每個工作日各計一天工時（忽略時分）。
     * @return array ['hours'=>float,'days'=>float,'workdays'=>int]  起訖無效回 hours=0
     */
    function eg_leave_calc_amount(PDO $db, string $unitType, string $startDt, string $endDt): array {
        $cfg = eg_leave_settings($db);
        $hoursPerDay = max(1, (float)$cfg['leave_hours_per_day']);
        $halfHours   = max(0.5, (float)$cfg['leave_halfday_hours']);

        $s = strtotime($startDt); $e = strtotime($endDt);
        if ($s === false || $e === false || $e <= $s) return ['hours' => 0, 'days' => 0, 'workdays' => 0];

        // 蒐集起訖涵蓋的工作日
        $workdays = [];
        $cur = strtotime(date('Y-m-d', $s)); $guard = 0;
        $lastDay = strtotime(date('Y-m-d', $e));
        while ($cur <= $lastDay && $guard++ < 400) {
            $key = date('Y-m-d', $cur);
            if (eg_leave_is_workday($db, $key)) $workdays[] = $key;
            $cur = strtotime('+1 day', $cur);
        }
        $n = count($workdays);
        if ($n === 0) return ['hours' => 0, 'days' => 0, 'workdays' => 0];

        if ($unitType === 'day') {
            $hours = $n * $hoursPerDay;
        } else {
            // hour / halfday：逐工作日計重疊時數
            $hours = 0.0;
            foreach ($workdays as $day) {
                $dayS = strtotime($day . ' 00:00:00');
                $dayE = strtotime($day . ' 23:59:59');
                $ovS = max($s, $dayS); $ovE = min($e, $dayE);
                if ($ovE <= $ovS) continue;
                $h = ($ovE - $ovS) / 3600;
                $hours += min($h, $hoursPerDay);   // 單日上限一天工時（跨午休不另扣，從寬）
            }
            if ($unitType === 'halfday') {
                // 向上取整到半天倍數
                $hours = ceil($hours / $halfHours) * $halfHours;
            } else {
                // 時假一律以「半小時」為最小單位，無條件進位（2026-07-29 使用者定案）
                // 例：請 40 分鐘算 1 小時、請 10 分鐘算 0.5 小時。
                // 前端 datetime-local 已限制 step=1800 只能選整點/半點，這裡是後端保險。
                $hours = ceil($hours * 2) / 2;
            }
        }
        return [
            'hours'    => round($hours, 2),
            'days'     => round($hours / $hoursPerDay, 2),
            'workdays' => $n,
        ];
    }
}

// ============================== 職務代理人自動解析 ==============================

if (!function_exists('eg_leave_user_busy_in_range')) {
    /**
     * 某人在指定期間內是否也請假（pending 或 approved 皆算，因為 pending 有可能會通過）。
     * 用於「第一順位代理人自己也請假 → 換下一順位」的判定。
     * @return array|null 重疊的請假單摘要；無重疊回 null
     */
    function eg_leave_user_busy_in_range(PDO $db, int $uid, string $start, string $end, int $excludeReqId = 0): ?array {
        try {
            $sql = "SELECT lr.id, lr.start_datetime, lr.end_datetime, lr.status, lt.leave_name
                    FROM leave_request lr LEFT JOIN leave_type lt ON lt.id = lr.leave_type_id
                    WHERE lr.employee_id = ? AND lr.status IN ('pending','cancel_pending','approved')
                      AND lr.start_datetime < ? AND lr.end_datetime > ?";
            $args = [$uid, $end, $start];
            if ($excludeReqId > 0) { $sql .= " AND lr.id <> ?"; $args[] = $excludeReqId; }
            $sql .= " ORDER BY lr.start_datetime LIMIT 1";
            $st = $db->prepare($sql);
            $st->execute($args);
            $r = $st->fetch(PDO::FETCH_ASSOC);
            return $r ?: null;
        } catch (Throwable $e) { return null; }   // 查詢失敗就當沒衝突，不擋流程
    }
}

if (!function_exists('eg_leave_resolve_agents')) {
    /**
     * 自動解析請假期間的職務代理人（2026-07-30 使用者定案：**代理人不由申請人挑選**）。
     *
     * 規則：
     *  1. 人事設定（hr_settings 代理人設定）已有優先順序，就照順序取「第一順位」。
     *  2. 若第一順位代理人在此人請假期間**自己也請假**（pending 或 approved 且時段重疊），
     *     跳過他、改通知第二順位；以此類推。
     *  3. 申請人有多個職務身分（主職＋兼任）時，**每個身分各自解析一位**——
     *     因為請假是整個人都不在，各身分的工作都要有人接，且各身分的代理設定可能是不同人。
     *  4. 某身分所有順位都不可用（都請假）→ 該身分記為無可用代理人，但**不擋請假**，
     *     只在單上標明讓主管知道。
     *
     * @return array 每個身分一列：[
     *   'scope_department_id','scope_position_id','scope_label','is_main',
     *   'agent_user_id'(?int),'agent_name'(string),'priority_used'(?int),
     *   'skipped'(array 被跳過者與原因),'reason'(string 人話說明)
     * ]
     */
    function eg_leave_resolve_agents(PDO $db, int $applicantId, string $start, string $end, int $excludeReqId = 0): array {
        // 取此人所有職務身分的代理候選（已含身分標籤，依 priority 排序）
        $cands = eg_person_delegate_candidates($db, $applicantId);
        if (empty($cands)) return [];

        // 依身分分組（保持 priority 順序）
        $groups = [];
        foreach ($cands as $c) {
            $key = ($c['scope_department_id'] ?? 'g') . '-' . ($c['scope_position_id'] ?? 'g');
            if (!isset($groups[$key])) {
                $groups[$key] = [
                    'scope_department_id' => $c['scope_department_id'],
                    'scope_position_id'   => $c['scope_position_id'],
                    'scope_label'         => $c['scope_label'],
                    'is_main'             => $c['is_main'],
                    'cands'               => [],
                ];
            }
            $groups[$key]['cands'][] = $c;
        }

        $out = [];
        foreach ($groups as $g) {
            $picked = null; $pri = 0; $skipped = [];
            foreach ($g['cands'] as $i => $c) {
                $pri = $i + 1;
                $uid = (int)$c['user_id'];
                if ($uid === $applicantId) {          // 自己不能代理自己
                    $skipped[] = ['name' => $c['user_cname'], 'priority' => $pri, 'why' => '即申請人本人'];
                    continue;
                }
                $busy = eg_leave_user_busy_in_range($db, $uid, $start, $end, $excludeReqId);
                if ($busy) {
                    $skipped[] = ['name' => $c['user_cname'], 'priority' => $pri,
                                  'why' => '同期間也請假（' . ($busy['leave_name'] ?? '請假') . ' #' . $busy['id']
                                           . '，' . substr((string)$busy['start_datetime'], 0, 10) . ' 起）'];
                    continue;
                }
                $picked = $c; break;
            }

            if ($picked) {
                $reason = '第 ' . $pri . ' 順位代理人';
                if ($skipped) {
                    $why = array_map(function ($s) { return $s['name'] . '（' . $s['why'] . '）'; }, $skipped);
                    $reason .= '；已跳過 ' . implode('、', $why);
                }
                if (($picked['source'] ?? '') === 'BY_POSITION') $reason .= '（由職稱代理解析）';
                $out[] = [
                    'scope_department_id' => $g['scope_department_id'],
                    'scope_position_id'   => $g['scope_position_id'],
                    'scope_label'         => $g['scope_label'],
                    'is_main'             => $g['is_main'],
                    'agent_user_id'       => (int)$picked['user_id'],
                    'agent_name'          => (string)$picked['user_cname'],
                    'priority_used'       => $pri,
                    'skipped'             => $skipped,
                    'reason'              => $reason,
                ];
            } else {
                $why = array_map(function ($s) { return $s['name'] . '（' . $s['why'] . '）'; }, $skipped);
                $out[] = [
                    'scope_department_id' => $g['scope_department_id'],
                    'scope_position_id'   => $g['scope_position_id'],
                    'scope_label'         => $g['scope_label'],
                    'is_main'             => $g['is_main'],
                    'agent_user_id'       => null,
                    'agent_name'          => '',
                    'priority_used'       => null,
                    'skipped'             => $skipped,
                    'reason'              => '此身分所有代理人於本期間皆無法代理' . ($why ? '：' . implode('、', $why) : ''),
                ];
            }
        }
        return $out;
    }
}

if (!function_exists('eg_leave_save_agents')) {
    /** 把解析結果寫入 leave_request_agent（先清後寫，供修改單時重算）。須在呼叫端的 transaction 內。 */
    function eg_leave_save_agents(PDO $db, int $requestId, array $agents): void {
        $db->prepare("DELETE FROM leave_request_agent WHERE leave_request_id = ?")->execute([$requestId]);
        if (!$agents) return;
        $ins = $db->prepare("INSERT INTO leave_request_agent
                               (leave_request_id, scope_department_id, scope_position_id, scope_label,
                                is_main, agent_user_id, priority_used, skipped_json, resolve_reason)
                             VALUES (?,?,?,?,?,?,?,?,?)");
        foreach ($agents as $a) {
            $ins->execute([$requestId, $a['scope_department_id'], $a['scope_position_id'],
                           (string)$a['scope_label'],
                           $a['is_main'] === null ? null : ($a['is_main'] ? 1 : 0),
                           $a['agent_user_id'], $a['priority_used'],
                           $a['skipped'] ? json_encode($a['skipped'], JSON_UNESCAPED_UNICODE) : null,
                           (string)$a['reason']]);
        }
    }
}

if (!function_exists('eg_leave_get_agents')) {
    /** 讀某單已存的代理人解析結果（含姓名）。 */
    function eg_leave_get_agents(PDO $db, int $requestId): array {
        try {
            $st = $db->prepare("SELECT ra.*, u.user_cname AS agent_name
                                FROM leave_request_agent ra
                                LEFT JOIN user u ON u.id = ra.agent_user_id
                                WHERE ra.leave_request_id = ?
                                ORDER BY (ra.is_main IS NULL), ra.is_main DESC, ra.id");
            $st->execute([$requestId]);
            return $st->fetchAll(PDO::FETCH_ASSOC);
        } catch (Throwable $e) { return []; }
    }
}

// ============================== 排班連動（固定班別） ==============================

if (!function_exists('eg_leave_roster_shift')) {
    /**
     * 查某人某日的固定班別排班（views/pages/roster.php「固定班別排班」分頁所設）。
     * 資料源：roster_shift_assign（誰在哪天上哪個班）JOIN roster_shift_type（班別起訖時間）。
     * 用途：請假申請時依當日班別自動帶出整天請假的起訖時間，使用者不必自己記幾點到幾點。
     *
     * @return array|null [
     *   'shift_type_id','name','code','start_time'(HH:MM),'end_time'(HH:MM),
     *   'is_overnight'(bool),'break_minutes','start_datetime','end_datetime'  // 跨夜班的 end 已自動 +1 天
     * ]；當日無排班回 null。
     */
    function eg_leave_roster_shift(PDO $db, int $userId, string $date): ?array {
        $d = substr($date, 0, 10);
        if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $d)) return null;
        try {
            $st = $db->prepare(
                "SELECT a.shift_type_id, t.name, t.code, t.start_time, t.end_time,
                        t.is_overnight, t.break_minutes
                 FROM roster_shift_assign a
                 JOIN roster_shift_type t ON t.id = a.shift_type_id
                 WHERE a.user_id = ? AND a.work_date = ?
                 ORDER BY a.id DESC LIMIT 1");
            $st->execute([$userId, $d]);
            $r = $st->fetch(PDO::FETCH_ASSOC);
            if (!$r) return null;

            $startT = substr((string)$r['start_time'], 0, 5);
            $endT   = substr((string)$r['end_time'], 0, 5);
            $overnight = !empty($r['is_overnight']);
            // 跨夜班（例：18:00~03:00）結束時間落在隔天
            $endDate = $overnight ? date('Y-m-d', strtotime($d . ' +1 day')) : $d;
            return [
                'shift_type_id'  => (int)$r['shift_type_id'],
                'name'           => (string)$r['name'],
                'code'           => (string)$r['code'],
                'start_time'     => $startT,
                'end_time'       => $endT,
                'is_overnight'   => $overnight,
                'break_minutes'  => (int)$r['break_minutes'],
                'start_datetime' => $d . ' ' . $startT . ':00',
                'end_datetime'   => $endDate . ' ' . $endT . ':00',
            ];
        } catch (Throwable $e) { return null; }   // 排班表查詢失敗不擋請假流程
    }
}

if (!function_exists('eg_leave_roster_range')) {
    /**
     * 依「請假起日～迄日」推出建議的請假起訖時間（整天請假）。
     * 起日取當日班別的上班時間、迄日取當日班別的下班時間（跨夜自動落到隔天）。
     * 任一天查不到排班就以該端點的預設工時（08:00 / 依 hours_per_day 推算的下班時間）回退，
     * 並在 missing 標明哪一端沒有排班，讓前端提示使用者自行確認。
     *
     * @return array ['start_datetime','end_datetime','start_shift'=>?array,'end_shift'=>?array,'missing'=>string[]]
     */
    function eg_leave_roster_range(PDO $db, int $userId, string $startDate, ?string $endDate = null): array {
        $s = substr($startDate, 0, 10);
        $e = substr((string)($endDate ?: $startDate), 0, 10);
        if ($e < $s) $e = $s;

        $cfg = eg_leave_settings($db);
        $perDay = max(1, (float)$cfg['leave_hours_per_day']);
        $defStart = '08:00';
        // 預設下班時間＝08:00 + 一天工時 + 1 小時休息（與日班 08:00~17:00 的慣例一致）
        $defEnd = date('H:i', strtotime($s . ' ' . $defStart) + (int)round(($perDay + 1) * 3600));

        $ss = eg_leave_roster_shift($db, $userId, $s);
        $es = ($e === $s) ? $ss : eg_leave_roster_shift($db, $userId, $e);

        $missing = [];
        if (!$ss) $missing[] = $s;
        if (!$es && $e !== $s) $missing[] = $e;

        $startDt = $ss ? $ss['start_datetime'] : ($s . ' ' . $defStart . ':00');
        if ($es) {
            $endDt = $es['end_datetime'];
        } else {
            $endDt = $e . ' ' . $defEnd . ':00';
        }
        // 保險：結束一定要晚於開始（例如迄日排到跨夜班又比起日早的異常資料）
        if (strtotime($endDt) <= strtotime($startDt)) {
            $endDt = date('Y-m-d H:i:s', strtotime($startDt) + (int)round($perDay * 3600));
        }
        return [
            'start_datetime' => $startDt,
            'end_datetime'   => $endDt,
            'start_shift'    => $ss,
            'end_shift'      => $es,
            'missing'        => $missing,
        ];
    }
}

// ============================== 特休額度 ==============================

if (!function_exists('eg_leave_annual_summary')) {
    /**
     * 特休額度摘要：['entitlement'=>float,'used'=>float,'pending'=>float,'remaining'=>float]
     * 額度=annual_leave_lib（與員工列表同一套）；used=該年度 approved 特休 total_days 加總；
     * pending=送審中特休（避免重複申請超額）；remaining=額度-used-pending。
     * 年度歸屬以請假起日年份計。
     */
    function eg_leave_annual_summary(PDO $db, int $userId, ?int $year = null): array {
        $year = $year ?? (int)date('Y');
        $ent = 0.0;
        try {
            $st = $db->prepare("SELECT hire_date FROM user WHERE id = ? LIMIT 1");
            $st->execute([$userId]);
            $hire = $st->fetchColumn();
            $ent = eg_annual_leave_entitlement($hire ?: null, $year);
        } catch (Throwable $e) {}
        $used = 0.0; $pending = 0.0;
        try {
            $st = $db->prepare(
                "SELECT lr.status, COALESCE(SUM(lr.total_days),0) AS d
                 FROM leave_request lr JOIN leave_type lt ON lt.id = lr.leave_type_id
                 WHERE lr.employee_id = ? AND lt.leave_name = '特休'
                   AND lr.status IN ('approved','pending','cancel_pending') AND YEAR(lr.start_datetime) = ?
                 GROUP BY lr.status");
            $st->execute([$userId, $year]);
            foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $r) {
                // pending 與 cancel_pending 都算「送審中」，必須累加；
                // 用 = 會讓後一種狀態覆蓋前一種，導致額度算少（2026-07-30 加入 cancel_pending 時發現）
                if ($r['status'] === 'approved') $used += (float)$r['d']; else $pending += (float)$r['d'];
            }
        } catch (Throwable $e) {}
        return [
            'entitlement' => $ent,
            'used'        => $used,
            'pending'     => $pending,
            'remaining'   => round($ent - $used - $pending, 2),
        ];
    }
}

if (!function_exists('eg_leave_fmt_amount')) {
    /**
     * 時數格式化為「N天+M小時」（2026-07-30 使用者指定格式）：
     *   13 小時（一天 8 小時）→ '1天+5小時'
     *   16 小時               → '2天'（整天就不顯示小時）
     *   3.5 小時              → '3.5小時'（不足一天就只顯示小時）
     * 小數尾 0 省略（3.50→3.5、3.00→3），比照全站數字顯示規範。
     */
    function eg_leave_fmt_amount(float $hours, float $hoursPerDay): string {
        if ($hours <= 0) return '0';
        $per = max(1.0, $hoursPerDay);
        $d = (int)floor($hours / $per);
        $rem = round($hours - $d * $per, 2);
        $trim = function (float $v): string {
            $s = rtrim(rtrim(number_format($v, 2, '.', ''), '0'), '.');
            return $s === '' ? '0' : $s;
        };
        if ($d > 0 && $rem > 0.001) return $d . '天+' . $trim($rem) . '小時';
        if ($d > 0) return $d . '天';
        return $trim($rem) . '小時';
    }
}

if (!function_exists('eg_leave_year_usage')) {
    /**
     * 某人某年度「已核准」的各假別累積用量，只回傳確實請過的假別（沒請的不列）。
     * 用途：請假申請頁在特休額度列右側顯示「事假 1天+5小時、病假 3.5小時…」。
     * 年度歸屬以請假起日年份計（與特休額度同一口徑）。
     *
     * @return array [['leave_type_id','leave_name','hours','label'], ...] 依時數多寡排序
     */
    function eg_leave_year_usage(PDO $db, int $userId, ?int $year = null): array {
        $year = $year ?? (int)date('Y');
        $perDay = max(1.0, (float)eg_leave_settings($db)['leave_hours_per_day']);
        try {
            $st = $db->prepare(
                "SELECT lt.id AS leave_type_id, lt.leave_name, COALESCE(SUM(lr.total_hours),0) AS hrs
                 FROM leave_request lr JOIN leave_type lt ON lt.id = lr.leave_type_id
                 WHERE lr.employee_id = ? AND lr.status = 'approved'
                   AND YEAR(lr.start_datetime) = ?
                 GROUP BY lt.id, lt.leave_name
                 HAVING hrs > 0
                 ORDER BY hrs DESC, lt.sort_order, lt.id");
            $st->execute([$userId, $year]);
            $out = [];
            foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $r) {
                $h = (float)$r['hrs'];
                $out[] = [
                    'leave_type_id' => (int)$r['leave_type_id'],
                    'leave_name'    => (string)$r['leave_name'],
                    'hours'         => $h,
                    'label'         => eg_leave_fmt_amount($h, $perDay),
                ];
            }
            return $out;
        } catch (Throwable $e) { return []; }
    }
}

if (!function_exists('eg_leave_years_of')) {
    /**
     * 某人有請假資料的年度清單（新到舊），供申請頁的年度下拉使用。
     * 一定包含今年（就算今年還沒請過假，也要能選回今年）。
     * 年度歸屬以請假起日年份計，與 eg_leave_year_usage／特休額度同一口徑。
     */
    function eg_leave_years_of(PDO $db, int $userId): array {
        $cur = (int)date('Y');
        try {
            $st = $db->prepare("SELECT DISTINCT YEAR(start_datetime) AS y FROM leave_request
                                WHERE employee_id = ? ORDER BY y DESC");
            $st->execute([$userId]);
            $ys = array_values(array_filter(array_map('intval', $st->fetchAll(PDO::FETCH_COLUMN))));
        } catch (Throwable $e) { $ys = []; }
        if (!in_array($cur, $ys, true)) $ys[] = $cur;
        rsort($ys);
        return $ys;
    }
}

// ============================== 主管鏈與簽核人解析 ==============================

if (!function_exists('eg_leave_supervisor_chain')) {
    /**
     * 申請人的主管鏈（上 1..N 級，逐級用 eg_resolve_supervisor 上溯）。
     * 解析不到下一級時以「最終裁決者」補位（leave_final_decider_id）；再無→管理員 id=1。
     * 回傳 [ ['level'=>1,'user_id'=>..,'fallback'=>bool], ... ] 長度 = $maxLevel（去重後可能較短）。
     */
    function eg_leave_supervisor_chain(PDO $db, int $applicantId, int $maxLevel): array {
        $cfg = eg_leave_settings($db);
        $finalDecider = (int)($cfg['leave_final_decider_id'] ?: 0);
        $chain = [];
        $cursor = $applicantId;
        $seen = [$applicantId => true];
        for ($lv = 1; $lv <= $maxLevel; $lv++) {
            $sup = eg_resolve_supervisor($db, $cursor);
            if ($sup && !isset($seen[$sup])) {
                $chain[] = ['level' => $lv, 'user_id' => $sup, 'fallback' => false];
                $seen[$sup] = true;
                $cursor = $sup;
                continue;
            }
            // 解析不到（或繞回已出現者）→ 最終裁決者補位；他已在鏈上就不重複，直接收鏈
            if ($finalDecider > 0 && !isset($seen[$finalDecider])) {
                $chain[] = ['level' => $lv, 'user_id' => $finalDecider, 'fallback' => true];
                $seen[$finalDecider] = true;
            } elseif (empty($chain) && $finalDecider === 0) {
                // 完全無人可簽：暫掛管理員並由呼叫端寫 audit（比照 ai-rules/11 §6）
                $chain[] = ['level' => $lv, 'user_id' => 1, 'fallback' => true];
            }
            break; // 補位後不再往上
        }
        return $chain;
    }
}

if (!function_exists('eg_leave_preview_signers')) {
    /**
     * 申請頁「將由誰簽核」預覽：主管鏈每級套 eg_resolve_signer（行程閘門＋SoD 自動處理）。
     * 回傳每級 ['level','target_id','target_name','signer_id','signer_name','is_delegated','is_sod_escalated','reason','fallback']
     * 純預覽不寫 audit_log（log=false）；實際簽核人以「簽核當下」再解析為準。
     */
    function eg_leave_preview_signers(PDO $db, int $applicantId, int $maxLevel): array {
        $chain = eg_leave_supervisor_chain($db, $applicantId, $maxLevel);
        $out = [];
        $nameSt = $db->prepare("SELECT user_cname FROM user WHERE id = ? LIMIT 1");
        foreach ($chain as $c) {
            $r = eg_resolve_signer($db, $c['user_id'], [
                'applicant_id' => $applicantId, 'flow_key' => 'leave', 'log' => false,
            ]);
            $nameSt->execute([$c['user_id']]);   $tName = (string)$nameSt->fetchColumn();
            $nameSt->execute([$r['signer_id']]); $sName = (string)$nameSt->fetchColumn();
            $out[] = [
                'level' => $c['level'], 'target_id' => $c['user_id'], 'target_name' => $tName,
                'signer_id' => $r['signer_id'], 'signer_name' => $sName,
                'is_delegated' => $r['is_delegated'], 'is_sod_escalated' => $r['is_sod_escalated'],
                'reason' => $r['reason'], 'fallback' => $c['fallback'],
            ];
        }
        return $out;
    }
}

if (!function_exists('eg_leave_can_sign')) {
    /**
     * 某使用者現在可否簽某層：本人（approver_id）可簽；或「簽核當下」eg_resolve_signer(主管)
     * 解析出的代理即為此人也可簽（OR-gate：任一簽即完成該層）。
     * 回傳 ['ok'=>bool,'as_delegate'=>bool,'reason'=>string]
     */
    function eg_leave_can_sign(PDO $db, array $approvalRow, int $userId, int $applicantId): array {
        $target = (int)$approvalRow['approver_id'];
        if ($userId === $target) return ['ok' => true, 'as_delegate' => false, 'reason' => '本人簽核'];
        $r = eg_resolve_signer($db, $target, [
            'applicant_id' => $applicantId, 'flow_key' => 'leave',
            'doc_id' => (int)$approvalRow['leave_request_id'], 'log' => false,
        ]);
        if ((int)$r['signer_id'] === $userId && $userId !== $target) {
            return ['ok' => true, 'as_delegate' => true, 'reason' => $r['reason']];
        }
        return ['ok' => false, 'as_delegate' => false, 'reason' => ''];
    }
}

// ============================== 行事曆 ==============================

if (!function_exists('eg_leave_event_set_targets')) {
    /**
     * 設定行事曆事件的可見對象（比照 src/store/_events_setting.php 的寫法）：
     *   evenement_target 決定廣播對象、evenement_recipient_cache 是 events.php 過濾用的快取。
     * $userIds 為空 → 全體可見（target_type='all'）；否則只有指定使用者看得到。
     */
    function eg_leave_event_set_targets(PDO $db, int $evenementId, array $userIds = []): void {
        try {
            $db->prepare("DELETE FROM evenement_target WHERE event_id = ?")->execute([$evenementId]);
            $db->prepare("DELETE FROM evenement_recipient_cache WHERE event_id = ?")->execute([$evenementId]);
            if (empty($userIds)) {
                $db->prepare("INSERT INTO evenement_target (event_id, target_type, created_at) VALUES (?, 'all', NOW())")
                   ->execute([$evenementId]);
                $ids = $db->query("SELECT id FROM user WHERE state NOT IN (0, 90) AND state IS NOT NULL")
                          ->fetchAll(PDO::FETCH_COLUMN);
            } else {
                $ins = $db->prepare("INSERT INTO evenement_target (event_id, target_type, target_id, created_at) VALUES (?, 'user', ?, NOW())");
                foreach (array_unique($userIds) as $u) $ins->execute([$evenementId, (int)$u]);
                $ids = array_unique($userIds);
            }
            $cache = $db->prepare("INSERT INTO evenement_recipient_cache (event_id, user_id, created_at) VALUES (?, ?, NOW())");
            foreach ($ids as $u) $cache->execute([$evenementId, (int)$u]);
        } catch (Throwable $e) { /* 可見度設定失敗不擋流程 */ }
    }
}

if (!function_exists('eg_leave_event_create_pending')) {
    /**
     * 送審：寫「請假申請中」行事曆事件＋actor，回傳事件 id（失敗回 null，不擋流程）。
     * 可見對象＝申請人＋簽核鏈成員（申請中還沒定案，不對全公司公開）；核准轉正時才改為全體。
     */
    function eg_leave_event_create_pending(PDO $db, int $requestId, int $userId, int $leaveTypeId, string $typeName, string $startDt, string $endDt, array $viewerIds = []): ?int {
        try {
            $catId = eg_leave_pending_category_id($db);
            if (!$catId) return null;
            $st = $db->prepare("SELECT user_cname FROM user WHERE id = ? LIMIT 1");
            $st->execute([$userId]);
            $name = (string)$st->fetchColumn();
            $db->prepare("INSERT INTO evenement (title, category_id, leave_type_id, start, end, allday, remark)
                          VALUES (?, ?, ?, ?, ?, 0, ?)")
               ->execute([$name . ' ' . $typeName . '(申請中)', $catId, $leaveTypeId, $startDt, $endDt,
                          '請假單 #' . $requestId . ' 送審中']);
            $evId = (int)$db->lastInsertId();
            $db->prepare("INSERT INTO evenement_actor (event_id, user_id, created_at) VALUES (?, ?, NOW())")
               ->execute([$evId, $userId]);
            $viewers = array_merge([$userId], $viewerIds);
            eg_leave_event_set_targets($db, $evId, $viewers);
            return $evId;
        } catch (Throwable $e) { return null; }
    }
}

if (!function_exists('eg_leave_event_approve')) {
    /**
     * 核准：同一筆申請中事件轉正為「休假」(category_id=1)，標題去掉(申請中)，
     * 並把可見對象改為全體（與既有休假事件一致 → 行事曆上正式顯示）。
     */
    function eg_leave_event_approve(PDO $db, int $evenementId, int $requestId): void {
        try {
            $db->prepare("UPDATE evenement
                          SET category_id = 1,
                              title = REPLACE(title, '(申請中)', ''),
                              remark = ?
                          WHERE id = ?")
               ->execute(['請假單 #' . $requestId . ' 已核准', $evenementId]);
            eg_leave_event_set_targets($db, $evenementId, []);   // 空陣列＝全體可見
        } catch (Throwable $e) {}
    }
}

if (!function_exists('eg_leave_event_remove')) {
    /** 退回/撤回/銷假：撤掉本單建立的行事曆事件（只刪 leave_request.evenement_id 指到的那筆，不條件反查） */
    function eg_leave_event_remove(PDO $db, ?int $evenementId): void {
        if (!$evenementId) return;
        try {
            $db->prepare("DELETE FROM evenement_recipient_cache WHERE event_id = ?")->execute([$evenementId]);
            $db->prepare("DELETE FROM evenement_target WHERE event_id = ?")->execute([$evenementId]);
            $db->prepare("DELETE FROM evenement_actor WHERE event_id = ?")->execute([$evenementId]);
            $db->prepare("DELETE FROM evenement WHERE id = ?")->execute([$evenementId]);
        } catch (Throwable $e) {}
    }
}

// ============================== 通知（live_event ref_type='LEAVE'） ==============================

if (!function_exists('eg_leave_notify')) {
    /**
     * 發請假通知（模式比照 car_notify）：live_event + live_event_target(user)，Web Push / Telegram 並行。
     * 測試紀律：$reasonText 內含 __xxx__ 樣式（測試單命名）→ 只寫站內通知，不發真實推播。
     * @return int|null live_event id
     */
    function eg_leave_notify(PDO $db, int $requestId, string $title, string $content, array $userIds, int $actorId = 0, string $reasonText = '', string $mode = 'read', string $refType = 'LEAVE'): ?int {
        $userIds = array_values(array_unique(array_filter(array_map('intval', $userIds),
            function ($u) use ($actorId) { return $u > 0 && $u !== $actorId; })));
        if (!$userIds) return null;
        if (!in_array($mode, ['read', 'sign'], true)) $mode = 'read';
        if (!in_array($refType, ['LEAVE', 'LEAVE_APPROVAL'], true)) $refType = 'LEAVE';
        try {
            $db->prepare(
                "INSERT INTO live_event (eventdate, title, content, status, created_by, source, ref_type, ref_id, show_status_to_others)
                 VALUES (CURDATE(), ?, ?, 0, ?, '請假系統', ?, ?, 1)")
               ->execute([$title, $content, ($actorId ?: null), $refType, $requestId]);
            $eventId = (int)$db->lastInsertId();
            // mode=sign：待簽核通知在真正簽核完成前不會從置頂欄未讀清單消失（比照報價單 QUOTATION_APPROVAL）
            $tg = $db->prepare("INSERT INTO live_event_target (live_event_id, target_type, target_id, mode) VALUES (?, 'user', ?, ?)");
            foreach ($userIds as $u) { $tg->execute([$eventId, $u, $mode]); }
        } catch (Throwable $e) {
            return null;   // 通知失敗不阻斷主流程
        }

        // 測試單（__xxx__ 命名）不發真實推播（見記憶 testing_discipline）
        if (preg_match('/__[^_]+__/u', $reasonText . ' ' . $title)) return $eventId;

        try {
            $pushLib = __DIR__ . '/../push/push_send.php';
            if (is_file($pushLib)) {
                require_once $pushLib;
                if (function_exists('eg_push_send_to_users')) {
                    eg_push_send_to_users($db, $userIds, [
                        'title' => $title,
                        'body'  => mb_substr(trim($content) === '' ? '（無內容）' : trim($content), 0, 480),
                        'tag'   => 'leave-' . $requestId,
                        'url'   => '/EGsystem/views/ADM/leave_request.php',
                        'eventId' => $eventId,
                    ]);
                }
            }
        } catch (Throwable $e) {}
        try {
            $tgLib = __DIR__ . '/../../telegram/notify_event.php';
            if (is_file($tgLib)) {
                require_once $tgLib;
                if (function_exists('eg_telegram_for_event')) eg_telegram_for_event($db, $eventId);
            }
        } catch (Throwable $e) {}
        return $eventId;
    }
}

if (!function_exists('eg_leave_notify_done')) {
    /**
     * 把某單「針對此使用者的待簽核通知(mode=sign)」標記為已簽，讓它從置頂欄未讀清單消失。
     * 比照 car_notify_done()。於簽核完成、或該單已決行/撤回時呼叫，避免通知永遠掛著。
     * @param int $userId 0=該單所有待簽對象一併結案（單據已決行時用）
     */
    function eg_leave_notify_done(PDO $db, int $requestId, int $userId = 0): void {
        try {
            $sql = "SELECT DISTINCT e.id, t.target_id FROM live_event e
                    JOIN live_event_target t ON t.live_event_id = e.id AND t.mode = 'sign' AND t.target_type = 'user'
                    WHERE e.ref_type = 'LEAVE_APPROVAL' AND e.ref_id = ?";
            $args = [$requestId];
            if ($userId > 0) { $sql .= " AND t.target_id = ?"; $args[] = $userId; }
            $st = $db->prepare($sql);
            $st->execute($args);
            foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $r) {
                $eid = (int)$r['id']; $uid = (int)$r['target_id'];
                $chk = $db->prepare("SELECT id FROM live_event_response WHERE live_event_id = ? AND user_id = ? LIMIT 1");
                $chk->execute([$eid, $uid]);
                $rid = $chk->fetchColumn();
                if ($rid) {
                    $db->prepare("UPDATE live_event_response
                                  SET read_at = COALESCE(read_at, NOW()), signed_at = COALESCE(signed_at, NOW())
                                  WHERE id = ?")->execute([$rid]);
                } else {
                    $db->prepare("INSERT INTO live_event_response (live_event_id, user_id, read_at, signed_at)
                                  VALUES (?, ?, NOW(), NOW())")->execute([$eid, $uid]);
                }
            }
        } catch (Throwable $e) { /* 通知結案失敗不影響簽核本身 */ }
    }
}

// ============================== 附件 ==============================

if (!function_exists('eg_leave_attach_dir')) {
    /**
     * 附件實體目錄（鐵律5：DB 只存檔名，完整路徑讀取當下用「目前設定＋子資料夾」即時組）。
     * 子資料夾依請假單 id 分層（temp 用 upload_token）。回傳 null=根目錄未設定。
     */
    function eg_leave_attach_dir(PDO $db, string $sub): ?string {
        $base = trim((string)eg_leave_settings($db)['leave_attach_base']);
        if ($base === '') return null;
        return rtrim($base, "/\\") . DIRECTORY_SEPARATOR . $sub;
    }
}

if (!function_exists('eg_leave_attach_needed')) {
    /** 此單依假別設定是否需附證明（考慮 attach_min_days 門檻） */
    function eg_leave_attach_needed(array $type, float $totalDays): bool {
        if (empty($type['require_attachment'])) return false;
        $min = (float)($type['attach_min_days'] ?? 0);
        return $min <= 0 ? true : ($totalDays > $min);
    }
}

// ============================== 送審 ==============================

if (!function_exists('eg_leave_submit')) {
    /**
     * 送審請假單（transaction）。
     * $in = ['employee_id','leave_type_id','start_datetime','end_datetime','reason',
     *        'agent_user_id'(假別 agent=1 必填，須為候選代理人之一)，'upload_token'(暫存附件批次)]
     * 回傳 ['ok'=>bool,'id'=>int,'msg'=>string,'need_attach_later'=>bool]
     */
    function eg_leave_submit(PDO $db, array $in): array {
        $uid   = (int)($in['employee_id'] ?? 0);
        $tid   = (int)($in['leave_type_id'] ?? 0);
        $start = trim((string)($in['start_datetime'] ?? ''));
        $end   = trim((string)($in['end_datetime'] ?? ''));
        $reason = trim((string)($in['reason'] ?? ''));
        $agentId = (int)($in['agent_user_id'] ?? 0);
        $token = trim((string)($in['upload_token'] ?? ''));
        $cfg = eg_leave_settings($db);

        if (!$uid || !$tid || !$start || !$end) return ['ok' => false, 'msg' => '缺少必要欄位'];
        if (strtotime($end) === false || strtotime($start) === false || strtotime($end) <= strtotime($start)) {
            return ['ok' => false, 'msg' => '結束時間必須晚於開始時間'];
        }

        // 假別
        $st = $db->prepare("SELECT * FROM leave_type WHERE id = ? LIMIT 1");
        $st->execute([$tid]);
        $type = $st->fetch(PDO::FETCH_ASSOC);
        if (!$type) return ['ok' => false, 'msg' => '假別不存在'];

        // 補請假限制
        $isBackdated = (substr($start, 0, 10) < date('Y-m-d')) ? 1 : 0;
        if ($isBackdated) {
            $limit = max(0, (int)$cfg['leave_backdate_limit_days']);
            $earliest = date('Y-m-d', strtotime("-{$limit} day"));
            if (substr($start, 0, 10) < $earliest) {
                return ['ok' => false, 'msg' => "補請假僅限起始日在 {$limit} 天內（最早 {$earliest}），更早的請洽人事處理"];
            }
        }

        // 重疊檢查：同人已有 pending/approved 且時段重疊 → 擋下
        $st = $db->prepare("SELECT id FROM leave_request
                            WHERE employee_id = ? AND status IN ('pending','cancel_pending','approved')
                              AND start_datetime < ? AND end_datetime > ? LIMIT 1");
        $st->execute([$uid, $end, $start]);
        if ($dup = $st->fetchColumn()) {
            return ['ok' => false, 'msg' => "此時段與請假單 #{$dup} 重疊，請先處理該單"];
        }

        // 時數計算（只計工作日）
        $amt = eg_leave_calc_amount($db, (string)$type['unit_type'], $start, $end);
        if ($amt['hours'] <= 0) return ['ok' => false, 'msg' => '請假時段內沒有工作日（或時數為 0），請確認起訖'];

        // 特休額度檢查（超額擋下）
        if ($type['leave_name'] === '特休') {
            $sum = eg_leave_annual_summary($db, $uid, (int)substr($start, 0, 4));
            if ($amt['days'] > $sum['remaining'] + 0.001) {
                return ['ok' => false, 'msg' => sprintf('特休額度不足：額度 %.1f 天、已用 %.1f 天、送審中 %.1f 天，剩餘 %.1f 天，本次申請 %.1f 天',
                    $sum['entitlement'], $sum['used'], $sum['pending'], $sum['remaining'], $amt['days'])];
            }
        }

        // 代理人（2026-07-30 使用者定案：**不由申請人挑選**）
        // 人事設定已有優先順序，系統自動取第一順位；該順位若在本期間也請假就往下一位；
        // 多個職務身分（主職＋兼任）各自解析一位。無任何代理設定＝此職務不需代理，放行不擋。
        $agents = ((int)$type['agent'] === 1) ? eg_leave_resolve_agents($db, $uid, $start, $end) : [];
        // leave_request.agent_user_id 保留存「主職身分那位」（相容既有顯示與通知）；
        // 完整的每身分結果存 leave_request_agent。
        $agentId = null;
        foreach ($agents as $a) {
            if (!empty($a['agent_user_id']) && ($a['is_main'] === true || $a['is_main'] === null)) { $agentId = (int)$a['agent_user_id']; break; }
        }
        if ($agentId === null) {
            foreach ($agents as $a) { if (!empty($a['agent_user_id'])) { $agentId = (int)$a['agent_user_id']; break; } }
        }

        // 附件需求（temp 附件是否存在）
        $hasAttach = false;
        if ($token !== '') {
            $st = $db->prepare("SELECT COUNT(*) FROM leave_attachment WHERE upload_token = ? AND status = 'temp'");
            $st->execute([$token]);
            $hasAttach = ((int)$st->fetchColumn()) > 0;
        }
        $needAttach = eg_leave_attach_needed($type, (float)$amt['days']);
        $attachStatus = 'not_required';
        if ($needAttach) {
            if ($hasAttach) $attachStatus = 'done';
            elseif (!empty($type['allow_attach_later'])) $attachStatus = 'pending';   // 先送審、事後補件
            else return ['ok' => false, 'msg' => '此假別須附證明文件（' . $type['leave_name'] . '），請先上傳附件再送出'];
        }

        // 簽核鏈（need_approval=0 → 直接核准）
        $needApproval = (int)$type['need_approval'] === 1;
        $chain = $needApproval ? eg_leave_supervisor_chain($db, $uid, max(1, (int)$type['max_approval_level'])) : [];
        if ($needApproval && empty($chain)) {
            return ['ok' => false, 'msg' => '無法解析簽核主管鏈，請洽管理員確認部門/職稱階級與最終裁決者設定'];
        }

        try {
            $db->beginTransaction();

            $db->prepare("INSERT INTO leave_request
                            (employee_id, leave_type_id, start_datetime, end_datetime, reason, status,
                             agent_user_id, total_hours, total_days, is_backdated, attach_status, submit_time, last_update)
                          VALUES (?,?,?,?,?,?,?,?,?,?,?,NOW(),NOW())")
               ->execute([$uid, $tid, $start, $end, $reason, $needApproval ? 'pending' : 'approved',
                          $agentId ?: null, $amt['hours'], $amt['days'], $isBackdated, $attachStatus]);
            $reqId = (int)$db->lastInsertId();

            // leave_approval：每層一列一次建好（流程狀態表）。approver_id=該層應簽主管（本人），
            // 簽核當下再解析代理（delegate_id 於簽核時回填），確保代理判定以「簽核當下」行程為準。
            if ($needApproval) {
                $ins = $db->prepare("INSERT INTO leave_approval
                                       (leave_request_id, approval_level, approver_level, approver_id, status)
                                     VALUES (?,?,?,?, 'pending')");
                foreach ($chain as $c) $ins->execute([$reqId, $c['level'], $c['level'], $c['user_id']]);
            }

            // temp 附件轉正（只改 DB 狀態與歸屬；實體檔搬移由 API 層處理）
            if ($token !== '' && $hasAttach) {
                $db->prepare("UPDATE leave_attachment SET leave_request_id = ?, status = 'active', upload_token = ''
                              WHERE upload_token = ? AND status = 'temp'")
                   ->execute([$reqId, $token]);
            }

            // 代理人解析結果（每個職務身分一列）
            eg_leave_save_agents($db, $reqId, $agents);

            // 行事曆：送審即寫「請假申請中」事件（核准時轉正）；免簽假別直接寫正式休假
            // 申請中的可見對象＝申請人＋簽核鏈主管（讓主管在行事曆上就看到有人要請假）
            $viewers = [];
            foreach ($chain as $c) $viewers[] = (int)$c['user_id'];
            foreach ($agents as $a) if (!empty($a['agent_user_id'])) $viewers[] = (int)$a['agent_user_id'];
            $evId = eg_leave_event_create_pending($db, $reqId, $uid, $tid, (string)$type['leave_name'], $start, $end, $viewers);
            if ($evId) {
                if (!$needApproval) eg_leave_event_approve($db, $evId, $reqId);
                $db->prepare("UPDATE leave_request SET evenement_id = ? WHERE id = ?")->execute([$evId, $reqId]);
            }
            if (!$needApproval) {
                $db->prepare("UPDATE leave_request SET decided_at = NOW() WHERE id = ?")->execute([$reqId]);
            }

            $db->commit();
        } catch (Throwable $e) {
            if ($db->inTransaction()) $db->rollBack();
            return ['ok' => false, 'msg' => '送審失敗：' . $e->getMessage()];
        }

        // 通知（transaction 外，失敗不影響單據）
        $st = $db->prepare("SELECT user_cname FROM user WHERE id = ? LIMIT 1");
        $st->execute([$uid]);
        $applicantName = (string)$st->fetchColumn();
        $period = substr($start, 0, 16) . ' ~ ' . substr($end, 0, 16);
        $body = "申請人：{$applicantName}\n假　別：{$type['leave_name']}\n時　段：{$period}\n時　數：{$amt['hours']} 小時（{$amt['days']} 天）"
              . ($isBackdated ? "\n【補請假】起始日早於送單日" : '')
              . ($attachStatus === 'pending' ? "\n【待補證明】證明文件尚未上傳" : '');
        if ($needApproval) {
            // 通知第一層簽核人（簽核當下解析實際簽核人）
            $first = $chain[0];
            $r = eg_resolve_signer($db, $first['user_id'], ['applicant_id' => $uid, 'flow_key' => 'leave', 'doc_id' => $reqId]);
            eg_leave_notify($db, $reqId, "📋 請假單 #{$reqId} 待您簽核", $body . "\n（點開通知即可直接簽核）",
                            [$r['signer_id']], $uid, $reason, 'sign', 'LEAVE_APPROVAL');
        } else {
            // 免簽：直接通知申請人與代理人
            $targets = [$uid];
            if ($agentId) $targets[] = $agentId;
            eg_leave_notify($db, $reqId, "✅ 請假單 #{$reqId} 已核准（免簽）", $body, $targets, 0, $reason);
        }

        return ['ok' => true, 'id' => $reqId, 'msg' => $needApproval ? '已送審' : '已核准（此假別免簽）',
                'need_attach_later' => ($attachStatus === 'pending')];
    }
}

// ============================== 修改（審核前） ==============================

if (!function_exists('eg_leave_can_edit')) {
    /**
     * 此單現在可否由申請人直接修改內容。
     * 規則（2026-07-30 定）：狀態 pending 且**還沒有任何人簽核過**才可改。
     * 已有主管簽過就不可直接改（否則已簽的意見會對不上新內容），只能撤回後重送。
     * @return array ['ok'=>bool,'reason'=>string]
     */
    function eg_leave_can_edit(PDO $db, array $req, int $userId, bool $isAdmin = false): array {
        if (!$isAdmin && (int)$req['employee_id'] !== $userId) return ['ok' => false, 'reason' => '僅申請人本人可修改'];
        if ($req['status'] !== 'pending') {
            $m = ['approved' => '此單已核准，如需變更請用「申請修改」（將銷假後重新申請）',
                  'rejected' => '此單已退回，請重新申請', 'canceled' => '此單已取消',
                  'cancel_pending' => '此單的撤回申請正待主管簽核，簽核結果出來後才能再動'];
            return ['ok' => false, 'reason' => $m[$req['status']] ?? '此狀態不可修改'];
        }
        try {
            $st = $db->prepare("SELECT COUNT(*) FROM leave_sign_record
                                WHERE leave_request_id = ? AND action IN ('approved','rejected') AND step_no < 90");
            $st->execute([(int)$req['id']]);
            if ((int)$st->fetchColumn() > 0) {
                return ['ok' => false, 'reason' => '已有主管簽核過，不可直接修改；請先「撤回申請」再重新送出'];
            }
        } catch (Throwable $e) {}
        return ['ok' => true, 'reason' => ''];
    }
}

if (!function_exists('eg_leave_update')) {
    /**
     * 修改審核前的請假單（transaction）：重算時數、重寫行事曆事件時間、重建簽核鏈、通知待簽者內容已變更。
     * 只允許改 假別/起訖/原因/代理人；不可改申請人。
     */
    function eg_leave_update(PDO $db, int $requestId, int $userId, array $in, bool $isAdmin = false): array {
        $st = $db->prepare("SELECT * FROM leave_request WHERE id = ? LIMIT 1");
        $st->execute([$requestId]);
        $req = $st->fetch(PDO::FETCH_ASSOC);
        if (!$req) return ['ok' => false, 'msg' => '請假單不存在'];

        $can = eg_leave_can_edit($db, $req, $userId, $isAdmin);
        if (!$can['ok']) return ['ok' => false, 'msg' => $can['reason']];

        $uid   = (int)$req['employee_id'];   // 申請人不可改
        $tid   = (int)($in['leave_type_id'] ?? 0);
        $start = trim((string)($in['start_datetime'] ?? ''));
        $end   = trim((string)($in['end_datetime'] ?? ''));
        if (!$tid || !$start || !$end) return ['ok' => false, 'msg' => '缺少必要欄位'];
        if (strtotime($end) === false || strtotime($start) === false || strtotime($end) <= strtotime($start)) {
            return ['ok' => false, 'msg' => '結束時間必須晚於開始時間'];
        }

        $st = $db->prepare("SELECT * FROM leave_type WHERE id = ? LIMIT 1");
        $st->execute([$tid]);
        $type = $st->fetch(PDO::FETCH_ASSOC);
        if (!$type) return ['ok' => false, 'msg' => '假別不存在'];

        $cfg = eg_leave_settings($db);
        $isBackdated = (substr($start, 0, 10) < date('Y-m-d')) ? 1 : 0;
        if ($isBackdated) {
            $limit = max(0, (int)$cfg['leave_backdate_limit_days']);
            $earliest = date('Y-m-d', strtotime("-{$limit} day"));
            if (substr($start, 0, 10) < $earliest) {
                return ['ok' => false, 'msg' => "補請假僅限起始日在 {$limit} 天內（最早 {$earliest}）"];
            }
        }
        // 重疊檢查要排除自己這張單
        $st = $db->prepare("SELECT id FROM leave_request
                            WHERE employee_id = ? AND id <> ? AND status IN ('pending','cancel_pending','approved')
                              AND start_datetime < ? AND end_datetime > ? LIMIT 1");
        $st->execute([$uid, $requestId, $end, $start]);
        if ($dup = $st->fetchColumn()) return ['ok' => false, 'msg' => "此時段與請假單 #{$dup} 重疊"];

        $amt = eg_leave_calc_amount($db, (string)$type['unit_type'], $start, $end);
        if ($amt['hours'] <= 0) return ['ok' => false, 'msg' => '請假時段內沒有工作日（或時數為 0）'];

        if ($type['leave_name'] === '特休') {
            $sum = eg_leave_annual_summary($db, $uid, (int)substr($start, 0, 4));
            // 本單原本已列入 pending 合計，改單時要先扣回自己才不會誤判超額
            $selfPending = ($req['status'] === 'pending' && (int)$req['leave_type_id'] === $tid) ? (float)$req['total_days'] : 0;
            $remain = $sum['remaining'] + $selfPending;
            if ($amt['days'] > $remain + 0.001) {
                return ['ok' => false, 'msg' => sprintf('特休額度不足：可用 %.1f 天，本次 %.1f 天', $remain, $amt['days'])];
            }
        }

        // 代理人：改單後期間可能變了，重新自動解析（排除本單自己，否則會判定「代理人也請假」）
        $agents = ((int)$type['agent'] === 1) ? eg_leave_resolve_agents($db, $uid, $start, $end, $requestId) : [];
        $agentId = null;
        foreach ($agents as $a) {
            if (!empty($a['agent_user_id']) && ($a['is_main'] === true || $a['is_main'] === null)) { $agentId = (int)$a['agent_user_id']; break; }
        }
        if ($agentId === null) {
            foreach ($agents as $a) { if (!empty($a['agent_user_id'])) { $agentId = (int)$a['agent_user_id']; break; } }
        }

        // 證明文件需求可能因假別/天數改變而變動
        $st = $db->prepare("SELECT COUNT(*) FROM leave_attachment WHERE leave_request_id = ? AND status = 'active'");
        $st->execute([$requestId]);
        $hasAttach = ((int)$st->fetchColumn()) > 0;
        $needAttach = eg_leave_attach_needed($type, (float)$amt['days']);
        $attachStatus = 'not_required';
        if ($needAttach) {
            if ($hasAttach) $attachStatus = 'done';
            elseif (!empty($type['allow_attach_later'])) $attachStatus = 'pending';
            else return ['ok' => false, 'msg' => '此假別須附證明文件，請先上傳再修改'];
        }

        $needApproval = (int)$type['need_approval'] === 1;
        $chain = $needApproval ? eg_leave_supervisor_chain($db, $uid, max(1, (int)$type['max_approval_level'])) : [];
        if ($needApproval && empty($chain)) return ['ok' => false, 'msg' => '無法解析簽核主管鏈'];

        try {
            $db->beginTransaction();
            $db->prepare("UPDATE leave_request
                          SET leave_type_id = ?, start_datetime = ?, end_datetime = ?, reason = ?,
                              agent_user_id = ?, total_hours = ?, total_days = ?, is_backdated = ?,
                              attach_status = ?, status = ?, last_update = NOW()
                          WHERE id = ?")
               ->execute([$tid, $start, $end, trim((string)($in['reason'] ?? '')), $agentId ?: null,
                          $amt['hours'], $amt['days'], $isBackdated, $attachStatus,
                          $needApproval ? 'pending' : 'approved', $requestId]);

            // 簽核鏈重建（假別可能換成需簽不同層數；此時尚無人簽過，直接重建最單純）
            $db->prepare("DELETE FROM leave_approval WHERE leave_request_id = ?")->execute([$requestId]);
            if ($needApproval) {
                $ins = $db->prepare("INSERT INTO leave_approval (leave_request_id, approval_level, approver_level, approver_id, status)
                                     VALUES (?,?,?,?, 'pending')");
                foreach ($chain as $c) $ins->execute([$requestId, $c['level'], $c['level'], $c['user_id']]);
            }
            eg_leave_save_agents($db, $requestId, $agents);   // 代理人依新期間重算

            // 軌跡留痕：step_no=98 表示「內容修改」
            $db->prepare("INSERT INTO leave_sign_record (leave_request_id, step_no, signer_id, action, remark, signed_at)
                          VALUES (?, 98, ?, 'edited', ?, NOW())")
               ->execute([$requestId, $userId, '修改內容：' . substr($start, 0, 16) . ' ~ ' . substr($end, 0, 16)
                                              . '（' . $type['leave_name'] . '，' . $amt['hours'] . ' 小時）']);

            // 行事曆事件同步（沿用原事件 id，不新建；沒有就補建）
            $evId = $req['evenement_id'] ? (int)$req['evenement_id'] : 0;
            if ($evId) {
                $db->prepare("UPDATE evenement SET start = ?, end = ?, leave_type_id = ? WHERE id = ?")
                   ->execute([$start, $end, $tid, $evId]);
            } else {
                $viewers = [];
                foreach ($chain as $c) $viewers[] = (int)$c['user_id'];
                if ($agentId) $viewers[] = (int)$agentId;
                $evId = eg_leave_event_create_pending($db, $requestId, $uid, $tid, (string)$type['leave_name'], $start, $end, $viewers);
                if ($evId) $db->prepare("UPDATE leave_request SET evenement_id = ? WHERE id = ?")->execute([$evId, $requestId]);
            }
            if (!$needApproval && $evId) eg_leave_event_approve($db, $evId, $requestId);
            $db->commit();
        } catch (Throwable $e) {
            if ($db->inTransaction()) $db->rollBack();
            return ['ok' => false, 'msg' => '修改失敗：' . $e->getMessage()];
        }

        // 舊的待簽通知作廢，重新通知第一層（內容已變更）
        eg_leave_notify_done($db, $requestId, 0);
        $nm = $db->prepare("SELECT user_cname FROM user WHERE id = ? LIMIT 1");
        $nm->execute([$uid]);
        $applicantName = (string)$nm->fetchColumn();
        $body = "申請人：{$applicantName}\n假　別：{$type['leave_name']}\n時　段："
              . substr($start, 0, 16) . ' ~ ' . substr($end, 0, 16)
              . "\n時　數：{$amt['hours']} 小時（{$amt['days']} 天）\n【內容已修改，請重新確認】";
        if ($needApproval) {
            $first = $chain[0];
            $r = eg_resolve_signer($db, $first['user_id'], ['applicant_id' => $uid, 'flow_key' => 'leave', 'doc_id' => $requestId]);
            eg_leave_notify($db, $requestId, "✏️ 請假單 #{$requestId} 已修改，待您簽核", $body,
                            [$r['signer_id']], $uid, (string)($in['reason'] ?? ''), 'sign', 'LEAVE_APPROVAL');
        }
        return ['ok' => true, 'msg' => '已修改並重新送審', 'id' => $requestId];
    }
}

// ============================== 簽核 ==============================

if (!function_exists('eg_leave_sign')) {
    /**
     * 簽核（核准/退回）目前輪到的那一層。
     * @param string $action 'approved'|'rejected'
     * @return array ['ok'=>bool,'msg'=>string,'final'=>bool] final=true 表示整單已決行
     */
    function eg_leave_sign(PDO $db, int $requestId, int $userId, string $action, string $remark = ''): array {
        if (!in_array($action, ['approved', 'rejected'], true)) return ['ok' => false, 'msg' => '無效的動作'];

        $st = $db->prepare("SELECT lr.*, lt.leave_name FROM leave_request lr
                            JOIN leave_type lt ON lt.id = lr.leave_type_id WHERE lr.id = ? LIMIT 1");
        $st->execute([$requestId]);
        $req = $st->fetch(PDO::FETCH_ASSOC);
        if (!$req) return ['ok' => false, 'msg' => '請假單不存在'];
        if ($req['status'] !== 'pending') return ['ok' => false, 'msg' => '此單已決行（' . $req['status'] . '），無法簽核'];

        // 目前輪到的層（最小的 pending 層）
        $st = $db->prepare("SELECT * FROM leave_approval
                            WHERE leave_request_id = ? AND approval_kind = 'leave' AND status = 'pending'
                            ORDER BY approval_level ASC LIMIT 1");
        $st->execute([$requestId]);
        $cur = $st->fetch(PDO::FETCH_ASSOC);
        if (!$cur) return ['ok' => false, 'msg' => '無待簽層級（資料異常，請洽管理員）'];

        $can = eg_leave_can_sign($db, $cur, $userId, (int)$req['employee_id']);
        if (!$can['ok']) return ['ok' => false, 'msg' => '目前輪到的簽核人不是您（或代理未生效）'];

        try {
            $db->beginTransaction();

            // 流程狀態表：更新該層決行結果（OR-gate：本人或代理任一簽即完成該層）
            $db->prepare("UPDATE leave_approval
                          SET status = ?, remark = ?, approval_time = NOW(), delegate_id = ?
                          WHERE id = ?")
               ->execute([$action, $remark, $can['as_delegate'] ? $userId : null, $cur['id']]);

            // 簽章軌跡表：只增不改（step_no=該層級；代理簽也記實際簽的人）
            $db->prepare("INSERT INTO leave_sign_record (leave_request_id, step_no, signer_id, action, remark, signed_at)
                          VALUES (?,?,?,?,?,NOW())")
               ->execute([$requestId, (int)$cur['approval_level'], $userId, $action, $remark]);

            $final = false; $approvedAll = false;
            if ($action === 'rejected') {
                // 任一層退回 → 整單退回；撤掉申請中行事曆事件；後續層自動收回（維持 pending 不動即可，單已決行）
                $db->prepare("UPDATE leave_request SET status = 'rejected', decided_at = NOW(), last_update = NOW() WHERE id = ?")
                   ->execute([$requestId]);
                eg_leave_event_remove($db, $req['evenement_id'] ? (int)$req['evenement_id'] : null);
                $db->prepare("UPDATE leave_request SET evenement_id = NULL WHERE id = ?")->execute([$requestId]);
                $final = true;
            } else {
                // 還有下一層嗎？
                $st = $db->prepare("SELECT COUNT(*) FROM leave_approval
                                    WHERE leave_request_id = ? AND approval_kind = 'leave' AND status = 'pending'");
                $st->execute([$requestId]);
                if ((int)$st->fetchColumn() === 0) {
                    $db->prepare("UPDATE leave_request SET status = 'approved', decided_at = NOW(), last_update = NOW() WHERE id = ?")
                       ->execute([$requestId]);
                    if ($req['evenement_id']) eg_leave_event_approve($db, (int)$req['evenement_id'], $requestId);
                    $final = true; $approvedAll = true;
                }
            }

            $db->commit();
        } catch (Throwable $e) {
            if ($db->inTransaction()) $db->rollBack();
            return ['ok' => false, 'msg' => '簽核失敗：' . $e->getMessage()];
        }

        // 簽核完成 → 把此人的待簽通知結案（mode=sign 的通知否則會一直掛在置頂欄）
        eg_leave_notify_done($db, $requestId, $userId);
        // 整單已決行（核准或退回）→ 其餘同層待簽者的通知也一併收回
        if ($final) eg_leave_notify_done($db, $requestId, 0);

        // ---- 通知（transaction 外） ----
        $names = $db->prepare("SELECT user_cname FROM user WHERE id = ? LIMIT 1");
        $names->execute([(int)$req['employee_id']]);
        $applicantName = (string)$names->fetchColumn();
        $period = substr((string)$req['start_datetime'], 0, 16) . ' ~ ' . substr((string)$req['end_datetime'], 0, 16);
        $baseBody = "申請人：{$applicantName}\n假　別：{$req['leave_name']}\n時　段：{$period}\n時　數：{$req['total_hours']} 小時（{$req['total_days']} 天）";
        $reason = (string)$req['reason'];

        if ($action === 'rejected') {
            eg_leave_notify($db, $requestId, "❌ 請假單 #{$requestId} 已退回", $baseBody . ($remark !== '' ? "\n退回意見：{$remark}" : ''),
                            [(int)$req['employee_id']], $userId, $reason);
        } elseif ($final && $approvedAll) {
            // 全過：通知申請人＋所有職務身分的代理人（代理人此刻才被告知接手，2026-07-28 定案）
            $targets = [(int)$req['employee_id']];
            $agentLine = '';
            $agentRows = eg_leave_get_agents($db, $requestId);
            foreach ($agentRows as $ar) {
                if (empty($ar['agent_user_id'])) {
                    $agentLine .= "\n代理（{$ar['scope_label']}）：⚠ 無可用代理人（" . $ar['resolve_reason'] . '）';
                    continue;
                }
                $targets[] = (int)$ar['agent_user_id'];
                $agentLine .= "\n代理（{$ar['scope_label']}）：" . (string)$ar['agent_name']
                            . '（' . $ar['resolve_reason'] . '）';
            }
            if ($agentRows) $agentLine .= "\n請上列代理人於請假期間協助代理職務。";
            elseif (!empty($req['agent_user_id'])) {   // 相容舊單（沒有 leave_request_agent 資料）
                $targets[] = (int)$req['agent_user_id'];
                $names->execute([(int)$req['agent_user_id']]);
                $agentLine = "\n職務代理人：" . (string)$names->fetchColumn() . "（請假期間請協助代理職務）";
            }
            eg_leave_notify($db, $requestId, "✅ 請假單 #{$requestId} 已核准", $baseBody . $agentLine
                            . ((string)$req['attach_status'] === 'pending' ? "\n【提醒】證明文件尚未補上傳" : ''),
                            $targets, 0, $reason);
        } else {
            // 過一層還有下一層：通知下一層簽核人
            $st = $db->prepare("SELECT * FROM leave_approval WHERE leave_request_id = ? AND approval_kind = 'leave'
                                  AND status = 'pending' ORDER BY approval_level ASC LIMIT 1");
            $st->execute([$requestId]);
            if ($next = $st->fetch(PDO::FETCH_ASSOC)) {
                $r = eg_resolve_signer($db, (int)$next['approver_id'],
                    ['applicant_id' => (int)$req['employee_id'], 'flow_key' => 'leave', 'doc_id' => $requestId]);
                eg_leave_notify($db, $requestId, "📋 請假單 #{$requestId} 待您簽核（第 {$next['approval_level']} 層）",
                                $baseBody . "\n（點開通知即可直接簽核）", [$r['signer_id']], $userId, $reason,
                                'sign', 'LEAVE_APPROVAL');
            }
        }

        return ['ok' => true, 'msg' => $action === 'rejected' ? '已退回' : ($final ? '已核准（流程完成）' : '已簽核，送下一層'), 'final' => $final];
    }
}

// ============================== 撤回 / 銷假 ==============================

if (!function_exists('eg_leave_cancel_mode')) {
    /**
     * 依請假日期判斷撤回／銷假該走哪條路（2026-07-30 使用者定案）：
     *   'direct'   請假起日還沒到（未來）→ 申請人可直接撤回／銷假。
     *   'approval' 請假期間已開始、還沒結束（含請假當日）→ **撤回需主管簽核**，不可自行生效。
     *   'blocked'  請假期間已結束 → **不開放自行撤回**，只能找管理員處理。
     *              理由：避免「已經休完假卻把請假紀錄撤掉」變成有休假無紀錄。
     * 管理者不受限（一律 'direct'）。
     */
    function eg_leave_cancel_mode(array $req, bool $isAdmin = false): string {
        if ($isAdmin) return 'direct';
        $today = date('Y-m-d');
        $sd = substr((string)$req['start_datetime'], 0, 10);
        $ed = substr((string)$req['end_datetime'], 0, 10);
        if ($sd > $today) return 'direct';
        if ($ed < $today) return 'blocked';
        return 'approval';   // 期間內（含當日）
    }
}

if (!function_exists('eg_leave_request_cancel')) {
    /**
     * 提出「撤回申請」：請假期間內（含當日）要撤回必須主管簽核，先轉為 cancel_pending 待簽。
     * 簽核人＝第一層主管（撤回不需走完整條鏈），透過 eg_resolve_signer 解析當下實際簽核人。
     */
    function eg_leave_request_cancel(PDO $db, int $requestId, int $userId, string $reason): array {
        $st = $db->prepare("SELECT lr.*, lt.leave_name, lt.max_approval_level FROM leave_request lr
                            JOIN leave_type lt ON lt.id = lr.leave_type_id WHERE lr.id = ? LIMIT 1");
        $st->execute([$requestId]);
        $req = $st->fetch(PDO::FETCH_ASSOC);
        if (!$req) return ['ok' => false, 'msg' => '請假單不存在'];
        if ((int)$req['employee_id'] !== $userId) return ['ok' => false, 'msg' => '僅申請人本人可提出撤回'];
        if (!in_array($req['status'], ['pending', 'approved'], true)) {
            return ['ok' => false, 'msg' => '此單狀態（' . $req['status'] . '）無法撤回'];
        }
        if (trim($reason) === '') return ['ok' => false, 'msg' => '請假期間內撤回必須填寫原因（將送主管簽核）'];

        $uid = (int)$req['employee_id'];
        $chain = eg_leave_supervisor_chain($db, $uid, 1);
        if (empty($chain)) return ['ok' => false, 'msg' => '無法解析簽核主管，請洽管理員撤回'];
        $target = (int)$chain[0]['user_id'];

        try {
            $db->beginTransaction();
            $db->prepare("UPDATE leave_request SET status = 'cancel_pending', cancel_reason = ?, last_update = NOW()
                          WHERE id = ?")->execute([$reason, $requestId]);
            // 撤回簽核列（approval_kind='cancel'）；同單重複提出時先清掉舊的待簽列
            $db->prepare("DELETE FROM leave_approval WHERE leave_request_id = ? AND approval_kind = 'cancel'")
               ->execute([$requestId]);
            $db->prepare("INSERT INTO leave_approval
                            (leave_request_id, approval_kind, approval_level, approver_level, approver_id, status)
                          VALUES (?, 'cancel', 1, 1, ?, 'pending')")->execute([$requestId, $target]);
            // 軌跡：step_no=97 表示「提出撤回申請」
            $db->prepare("INSERT INTO leave_sign_record (leave_request_id, step_no, signer_id, action, remark, signed_at)
                          VALUES (?, 97, ?, 'cancel_requested', ?, NOW())")
               ->execute([$requestId, $userId, '提出撤回申請：' . $reason]);
            $db->commit();
        } catch (Throwable $e) {
            if ($db->inTransaction()) $db->rollBack();
            return ['ok' => false, 'msg' => '提出撤回失敗：' . $e->getMessage()];
        }

        // 通知主管（mode=sign，簽完前不從未讀清單消失）
        $r = eg_resolve_signer($db, $target, ['applicant_id' => $uid, 'flow_key' => 'leave', 'doc_id' => $requestId]);
        $ns = $db->prepare("SELECT user_cname FROM user WHERE id = ? LIMIT 1");
        $ns->execute([$uid]);
        $body = "申請人：" . (string)$ns->fetchColumn()
              . "\n假　別：{$req['leave_name']}"
              . "\n時　段：" . substr((string)$req['start_datetime'], 0, 16) . ' ~ ' . substr((string)$req['end_datetime'], 0, 16)
              . "\n撤回原因：{$reason}"
              . "\n【此單已在請假期間內，撤回需您簽核】";
        eg_leave_notify($db, $requestId, "↩️ 請假單 #{$requestId} 撤回待您簽核", $body,
                        [$r['signer_id']], $uid, $reason, 'sign', 'LEAVE_APPROVAL');
        return ['ok' => true, 'msg' => '已送出撤回申請，待主管簽核後才會撤除（行事曆暫時保留）', 'mode' => 'approval'];
    }
}

if (!function_exists('eg_leave_sign_cancel')) {
    /**
     * 主管簽核「撤回申請」：核准→真的撤回（狀態 canceled、撤行事曆）；退回→回復原狀態。
     */
    function eg_leave_sign_cancel(PDO $db, int $requestId, int $userId, string $action, string $remark = ''): array {
        if (!in_array($action, ['approved', 'rejected'], true)) return ['ok' => false, 'msg' => '無效的動作'];
        $st = $db->prepare("SELECT lr.*, lt.leave_name FROM leave_request lr
                            JOIN leave_type lt ON lt.id = lr.leave_type_id WHERE lr.id = ? LIMIT 1");
        $st->execute([$requestId]);
        $req = $st->fetch(PDO::FETCH_ASSOC);
        if (!$req) return ['ok' => false, 'msg' => '請假單不存在'];
        if ($req['status'] !== 'cancel_pending') return ['ok' => false, 'msg' => '此單目前不是「撤回待簽核」狀態'];

        $st = $db->prepare("SELECT * FROM leave_approval WHERE leave_request_id = ? AND approval_kind = 'cancel'
                            AND status = 'pending' LIMIT 1");
        $st->execute([$requestId]);
        $cur = $st->fetch(PDO::FETCH_ASSOC);
        if (!$cur) return ['ok' => false, 'msg' => '找不到撤回簽核列（資料異常）'];

        $can = eg_leave_can_sign($db, $cur, $userId, (int)$req['employee_id']);
        if (!$can['ok']) return ['ok' => false, 'msg' => '撤回簽核人不是您（或代理未生效）'];

        // 退回撤回申請時要回到原本狀態：有人簽核過→approved，否則→pending
        $doneN = (int)$db->query("SELECT COUNT(*) FROM leave_approval WHERE leave_request_id = " . (int)$requestId
                                 . " AND approval_kind = 'leave' AND status = 'pending'")->fetchColumn();
        $restore = ($doneN > 0) ? 'pending' : 'approved';

        try {
            $db->beginTransaction();
            $db->prepare("UPDATE leave_approval SET status = ?, remark = ?, approval_time = NOW(), delegate_id = ?
                          WHERE id = ?")
               ->execute([$action, $remark, $can['as_delegate'] ? $userId : null, $cur['id']]);
            $db->prepare("INSERT INTO leave_sign_record (leave_request_id, step_no, signer_id, action, remark, signed_at)
                          VALUES (?, 97, ?, ?, ?, NOW())")
               ->execute([$requestId, $userId, $action === 'approved' ? 'cancel_approved' : 'cancel_rejected',
                          ($action === 'approved' ? '核准撤回' : '駁回撤回') . ($remark !== '' ? '：' . $remark : '')]);

            if ($action === 'approved') {
                $db->prepare("UPDATE leave_request
                              SET status = 'canceled', canceled_at = NOW(), canceled_by = ?, last_update = NOW()
                              WHERE id = ?")->execute([$userId, $requestId]);
                eg_leave_event_remove($db, $req['evenement_id'] ? (int)$req['evenement_id'] : null);
                $db->prepare("UPDATE leave_request SET evenement_id = NULL WHERE id = ?")->execute([$requestId]);
            } else {
                $db->prepare("UPDATE leave_request SET status = ?, last_update = NOW() WHERE id = ?")
                   ->execute([$restore, $requestId]);
            }
            $db->commit();
        } catch (Throwable $e) {
            if ($db->inTransaction()) $db->rollBack();
            return ['ok' => false, 'msg' => '簽核失敗：' . $e->getMessage()];
        }

        eg_leave_notify_done($db, $requestId, $userId);
        eg_leave_notify_done($db, $requestId, 0);

        $ns = $db->prepare("SELECT user_cname FROM user WHERE id = ? LIMIT 1");
        $ns->execute([(int)$req['employee_id']]);
        $body = "假　別：{$req['leave_name']}\n時　段："
              . substr((string)$req['start_datetime'], 0, 16) . ' ~ ' . substr((string)$req['end_datetime'], 0, 16)
              . ($remark !== '' ? "\n簽核意見：{$remark}" : '');
        if ($action === 'approved') {
            $targets = [(int)$req['employee_id']];
            foreach (eg_leave_get_agents($db, $requestId) as $ar) {
                if (!empty($ar['agent_user_id'])) $targets[] = (int)$ar['agent_user_id'];
            }
            eg_leave_notify($db, $requestId, "↩️ 請假單 #{$requestId} 撤回已核准（假已取消）", $body,
                            $targets, $userId, (string)$req['reason']);
        } else {
            eg_leave_notify($db, $requestId, "⛔ 請假單 #{$requestId} 撤回申請被駁回（請假仍有效）", $body,
                            [(int)$req['employee_id']], $userId, (string)$req['reason']);
        }
        return ['ok' => true, 'msg' => $action === 'approved' ? '已核准撤回，假別已取消' : '已駁回撤回，請假仍然有效'];
    }
}

if (!function_exists('eg_leave_cancel')) {
    /**
     * 撤回／銷假。依 eg_leave_cancel_mode() 分三種情形（2026-07-30 使用者定案）：
     *   未來的假        → 直接撤回／銷假（原行為）
     *   請假期間內(含當日) → 轉為「撤回待簽核」，主管核准後才真的撤除
     *   請假已結束      → 不開放自行撤回，回訊息請洽管理員（避免已休假卻無紀錄）
     * 管理者不受限，一律直接撤除。
     */
    function eg_leave_cancel(PDO $db, int $requestId, int $userId, string $reason = '', bool $isAdmin = false): array {
        $st = $db->prepare("SELECT lr.*, lt.leave_name FROM leave_request lr
                            JOIN leave_type lt ON lt.id = lr.leave_type_id WHERE lr.id = ? LIMIT 1");
        $st->execute([$requestId]);
        $req = $st->fetch(PDO::FETCH_ASSOC);
        if (!$req) return ['ok' => false, 'msg' => '請假單不存在'];
        if (!$isAdmin && (int)$req['employee_id'] !== $userId) return ['ok' => false, 'msg' => '僅申請人本人可撤回/銷假'];
        if (!in_array($req['status'], ['pending', 'approved'], true)) {
            return ['ok' => false, 'msg' => '此單狀態（' . $req['status'] . '）無法撤回/銷假'];
        }

        // 依請假日期分流（管理者不受限）
        $mode = eg_leave_cancel_mode($req, $isAdmin);
        if ($mode === 'blocked') {
            return ['ok' => false, 'mode' => 'blocked',
                    'msg' => '請假期間已結束（' . substr((string)$req['end_datetime'], 0, 10)
                             . '），為避免出現「已休假卻無請假紀錄」，不開放自行撤回；如確有需要請洽管理員處理。'];
        }
        if ($mode === 'approval') {
            return eg_leave_request_cancel($db, $requestId, $userId, $reason);
        }

        $wasApproved = ($req['status'] === 'approved');

        try {
            $db->beginTransaction();
            $db->prepare("UPDATE leave_request
                          SET status = 'canceled', cancel_reason = ?, canceled_at = NOW(), canceled_by = ?, last_update = NOW()
                          WHERE id = ?")
               ->execute([$reason, $userId, $requestId]);
            // 軌跡：銷假/撤回也記一筆（step_no=99 表非簽核層動作）
            $db->prepare("INSERT INTO leave_sign_record (leave_request_id, step_no, signer_id, action, remark, signed_at)
                          VALUES (?, 99, ?, 'canceled', ?, NOW())")
               ->execute([$requestId, $userId, ($wasApproved ? '銷假' : '撤回') . ($reason !== '' ? '：' . $reason : '')]);
            eg_leave_event_remove($db, $req['evenement_id'] ? (int)$req['evenement_id'] : null);
            $db->prepare("UPDATE leave_request SET evenement_id = NULL WHERE id = ?")->execute([$requestId]);
            $db->commit();
        } catch (Throwable $e) {
            if ($db->inTransaction()) $db->rollBack();
            return ['ok' => false, 'msg' => '操作失敗：' . $e->getMessage()];
        }

        // 撤回/銷假後單據已不需簽核，收回所有待簽通知（否則主管的通知會一直掛著）
        eg_leave_notify_done($db, $requestId, 0);

        // 銷假：通知所有已簽核者＋代理人；撤回（pending）：通知目前待簽者即可
        $names = $db->prepare("SELECT user_cname FROM user WHERE id = ? LIMIT 1");
        $names->execute([(int)$req['employee_id']]);
        $applicantName = (string)$names->fetchColumn();
        $period = substr((string)$req['start_datetime'], 0, 16) . ' ~ ' . substr((string)$req['end_datetime'], 0, 16);
        $body = "申請人：{$applicantName}\n假　別：{$req['leave_name']}\n時　段：{$period}"
              . ($reason !== '' ? "\n原　因：{$reason}" : '');
        $targets = [];
        if ($wasApproved) {
            try {
                $st = $db->prepare("SELECT DISTINCT signer_id FROM leave_sign_record
                                    WHERE leave_request_id = ? AND action = 'approved'");
                $st->execute([$requestId]);
                $targets = array_map('intval', $st->fetchAll(PDO::FETCH_COLUMN));
            } catch (Throwable $e) {}
            // 通知所有被指派過的代理人（多身分各一位），舊單無資料時退回單一欄位
            foreach (eg_leave_get_agents($db, $requestId) as $ar) {
                if (!empty($ar['agent_user_id'])) $targets[] = (int)$ar['agent_user_id'];
            }
            if (!empty($req['agent_user_id'])) $targets[] = (int)$req['agent_user_id'];
            eg_leave_notify($db, $requestId, "↩️ 請假單 #{$requestId} 已銷假", $body, $targets, $userId, (string)$req['reason']);
        } else {
            try {
                $st = $db->prepare("SELECT approver_id FROM leave_approval
                                    WHERE leave_request_id = ? AND approval_kind = 'leave' AND status = 'pending'
                                    ORDER BY approval_level ASC LIMIT 1");
                $st->execute([$requestId]);
                if ($ap = $st->fetchColumn()) {
                    $r = eg_resolve_signer($db, (int)$ap, ['applicant_id' => (int)$req['employee_id'], 'flow_key' => 'leave', 'log' => false]);
                    $targets = [$r['signer_id']];
                }
            } catch (Throwable $e) {}
            eg_leave_notify($db, $requestId, "↩️ 請假單 #{$requestId} 已由申請人撤回", $body, $targets, $userId, (string)$req['reason']);
        }
        return ['ok' => true, 'msg' => $wasApproved ? '已銷假（行事曆已撤除、相關人員已通知）' : '已撤回'];
    }
}

// ============================== 徹底刪除（僅管理員／測試用） ==============================

if (!function_exists('eg_leave_is_superadmin')) {
    /**
     * 可使用「徹底刪除」的唯一身分：**員工 id=1 且在職狀態為 99（最高權限）**。
     * 2026-07-30 使用者明確要求——一般管理者角色(rf 'all')不足以刪除單據，
     * 因為刪除會連通知與簽核紀錄一起消滅、不可回復，只給超級管理員（帳號 e）測試用。
     * 狀態即時查 DB，不吃 session，避免帳號被降權後仍能刪。
     */
    function eg_leave_is_superadmin(PDO $db, int $uid): bool {
        if ($uid !== 1) return false;
        try {
            $st = $db->prepare("SELECT state FROM user WHERE id = 1 LIMIT 1");
            $st->execute();
            return (int)$st->fetchColumn() === 99;
        } catch (Throwable $e) { return false; }   // 查不到就當沒權限（fail-closed）
    }
}

if (!function_exists('eg_leave_verify_superadmin_password')) {
    /**
     * 徹底刪除前的密碼確認：必須輸入 **員工 id=1 本人的密碼**（2026-07-30 使用者要求）。
     * 比對方式沿用專案既有慣例（user.user_password 明碼欄位 + hash_equals 定時比較），
     * 與共用帳號代簽的 eg_shared_resolve_actor() 一致，不另發明一套。
     * 空密碼一律不放行（fail-closed）。
     */
    function eg_leave_verify_superadmin_password(PDO $db, string $password): array {
        if ($password === '') return ['ok' => false, 'msg' => '請輸入最高權限帳號的密碼'];
        try {
            $st = $db->prepare("SELECT user_password FROM `user` WHERE id = 1 LIMIT 1");
            $st->execute();
            $real = $st->fetchColumn();
            if ($real === false) return ['ok' => false, 'msg' => '查無最高權限帳號'];
            if (!hash_equals((string)$real, $password)) return ['ok' => false, 'msg' => '密碼錯誤，未執行刪除'];
        } catch (Throwable $e) {
            return ['ok' => false, 'msg' => '密碼驗證失敗，未執行刪除'];
        }
        return ['ok' => true, 'msg' => ''];
    }
}

if (!function_exists('eg_leave_delete')) {
    /**
     * 徹底刪除一張請假單及其所有關聯資料（僅管理員可呼叫；呼叫端負責權限與 CSRF）。
     * 用途：測試期間清掉測試單，不留孤兒資料。**這是破壞性操作，不可回復**。
     * 一併刪除：簽核流程 leave_approval、簽章軌跡 leave_sign_record、附件 leave_attachment
     *          （含實體檔）、行事曆事件 evenement（+actor/target/recipient_cache）、
     *          通知 live_event（+target/response/for_user）。
     * 刪除前把整張單的內容寫進 audit_log（action_type='LEAVE_DELETE'），確保仍可追溯做過什麼。
     */
    function eg_leave_delete(PDO $db, int $requestId, int $operatorId): array {
        $st = $db->prepare("SELECT lr.*, lt.leave_name, u.user_cname AS applicant_name
                            FROM leave_request lr
                            LEFT JOIN leave_type lt ON lt.id = lr.leave_type_id
                            LEFT JOIN user u ON u.id = lr.employee_id
                            WHERE lr.id = ? LIMIT 1");
        $st->execute([$requestId]);
        $req = $st->fetch(PDO::FETCH_ASSOC);
        if (!$req) return ['ok' => false, 'msg' => '請假單不存在（可能已被刪除）'];

        // 先蒐集要刪的關聯 id（交易內用）
        $evId = $req['evenement_id'] ? (int)$req['evenement_id'] : 0;
        $atts = [];
        try {
            $st = $db->prepare("SELECT id, stored_name, upload_token, leave_request_id FROM leave_attachment WHERE leave_request_id = ?");
            $st->execute([$requestId]);
            $atts = $st->fetchAll(PDO::FETCH_ASSOC);
        } catch (Throwable $e) {}
        $events = [];
        try {
            $st = $db->prepare("SELECT id FROM live_event WHERE ref_type IN ('LEAVE','LEAVE_APPROVAL') AND ref_id = ?");
            $st->execute([$requestId]);
            $events = array_map('intval', $st->fetchAll(PDO::FETCH_COLUMN));
        } catch (Throwable $e) {}

        // 稽核留痕（刪除前先寫，避免交易失敗後沒紀錄）
        try {
            $opName = '';
            $ns = $db->prepare("SELECT user_cname FROM user WHERE id = ? LIMIT 1");
            $ns->execute([$operatorId]);
            $opName = (string)$ns->fetchColumn();
            $db->prepare("INSERT INTO audit_log (action_type, target_type, target_id, target_name, changes, user_id, operator, created_at)
                          VALUES ('LEAVE_DELETE', 'leave_request', ?, ?, ?, ?, ?, NOW())")
               ->execute([(string)$requestId,
                          '請假單 #' . $requestId . '（' . ($req['applicant_name'] ?? '') . '）',
                          json_encode(['request' => $req, 'attachments' => $atts, 'live_events' => $events],
                                      JSON_UNESCAPED_UNICODE),
                          $operatorId ?: null, $opName ?: 'admin']);
        } catch (Throwable $e) { /* 稽核寫入失敗不擋刪除，但會少一筆紀錄 */ }

        try {
            $db->beginTransaction();
            // 通知（含收件對象、回應、共用帳號轉送紀錄）
            foreach ($events as $eid) {
                $db->prepare("DELETE FROM live_event_response WHERE live_event_id = ?")->execute([$eid]);
                $db->prepare("DELETE FROM live_event_target WHERE live_event_id = ?")->execute([$eid]);
                try { $db->prepare("DELETE FROM live_event_for_user WHERE live_event_id = ?")->execute([$eid]); } catch (Throwable $e) {}
                $db->prepare("DELETE FROM live_event WHERE id = ?")->execute([$eid]);
            }
            // 行事曆事件
            if ($evId) eg_leave_event_remove($db, $evId);
            // 附件（DB 列；實體檔在交易外刪，避免交易回滾後檔案已消失）
            $db->prepare("DELETE FROM leave_attachment WHERE leave_request_id = ?")->execute([$requestId]);
            // 代理人解析結果
            $db->prepare("DELETE FROM leave_request_agent WHERE leave_request_id = ?")->execute([$requestId]);
            // 簽核流程與軌跡
            $db->prepare("DELETE FROM leave_sign_record WHERE leave_request_id = ?")->execute([$requestId]);
            $db->prepare("DELETE FROM leave_approval WHERE leave_request_id = ?")->execute([$requestId]);
            // 主檔
            $db->prepare("DELETE FROM leave_request WHERE id = ?")->execute([$requestId]);
            $db->commit();
        } catch (Throwable $e) {
            if ($db->inTransaction()) $db->rollBack();
            return ['ok' => false, 'msg' => '刪除失敗：' . $e->getMessage()];
        }

        // 實體附件檔（路徑一律即時組，鐵律5）
        $filesDeleted = 0;
        foreach ($atts as $a) {
            $sub = $a['leave_request_id'] ? ('req_' . (int)$a['leave_request_id']) : ('temp_' . $a['upload_token']);
            $dir = eg_leave_attach_dir($db, $sub);
            if ($dir && $a['stored_name'] && is_file($dir . DIRECTORY_SEPARATOR . $a['stored_name'])) {
                if (@unlink($dir . DIRECTORY_SEPARATOR . $a['stored_name'])) $filesDeleted++;
            }
        }
        return ['ok' => true, 'msg' => sprintf('已徹底刪除請假單 #%d（通知 %d 則、附件 %d 個、簽核紀錄與行事曆事件已一併清除）',
                                              $requestId, count($events), $filesDeleted)];
    }
}

// ============================== 待簽清單 ==============================

if (!function_exists('eg_leave_pending_for')) {
    /**
     * 某人的待簽清單：所有 pending 單中「目前輪到的層」，且此人＝該層主管本人或當下解析出的代理。
     * 回傳含申請人/假別/時段與 as_delegate 標記。
     */
    function eg_leave_pending_for(PDO $db, int $userId): array {
        $rows = [];
        try {
            // 兩種待簽都要列：
            //   kind='leave'  → 請假本身待簽（單據 status=pending，取目前輪到的最小層）
            //   kind='cancel' → 撤回待簽（單據 status=cancel_pending，請假期間內撤回需主管簽核）
            $st = $db->query(
                "SELECT la.*, lr.employee_id, lr.start_datetime, lr.end_datetime, lr.total_hours, lr.total_days,
                        lr.reason, lr.is_backdated, lr.attach_status, lr.submit_time, lr.status AS req_status,
                        lr.cancel_reason, lt.leave_name, u.user_cname AS applicant_name
                 FROM leave_approval la
                 JOIN leave_request lr ON lr.id = la.leave_request_id
                 JOIN leave_type lt ON lt.id = lr.leave_type_id
                 JOIN user u ON u.id = lr.employee_id
                 WHERE la.status = 'pending'
                   AND (
                        (la.approval_kind = 'leave'  AND lr.status = 'pending'
                         AND la.approval_level = (SELECT MIN(la2.approval_level) FROM leave_approval la2
                                                  WHERE la2.leave_request_id = la.leave_request_id
                                                    AND la2.approval_kind = 'leave' AND la2.status = 'pending'))
                     OR (la.approval_kind = 'cancel' AND lr.status = 'cancel_pending')
                   )
                 ORDER BY (la.approval_kind = 'cancel') DESC, lr.submit_time ASC");
            foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $row) {
                $can = eg_leave_can_sign($db, $row, $userId, (int)$row['employee_id']);
                if (!$can['ok']) continue;
                $row['as_delegate'] = $can['as_delegate'];
                $row['delegate_reason'] = $can['reason'];
                $rows[] = $row;
            }
        } catch (Throwable $e) {}
        return $rows;
    }
}
