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
                   AND lr.status IN ('approved','pending') AND YEAR(lr.start_datetime) = ?
                 GROUP BY lr.status");
            $st->execute([$userId, $year]);
            foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $r) {
                if ($r['status'] === 'approved') $used = (float)$r['d']; else $pending = (float)$r['d'];
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
    function eg_leave_notify(PDO $db, int $requestId, string $title, string $content, array $userIds, int $actorId = 0, string $reasonText = ''): ?int {
        $userIds = array_values(array_unique(array_filter(array_map('intval', $userIds),
            function ($u) use ($actorId) { return $u > 0 && $u !== $actorId; })));
        if (!$userIds) return null;
        try {
            $db->prepare(
                "INSERT INTO live_event (eventdate, title, content, status, created_by, source, ref_type, ref_id, show_status_to_others)
                 VALUES (CURDATE(), ?, ?, 0, ?, '請假系統', 'LEAVE', ?, 1)")
               ->execute([$title, $content, ($actorId ?: null), $requestId]);
            $eventId = (int)$db->lastInsertId();
            $tg = $db->prepare("INSERT INTO live_event_target (live_event_id, target_type, target_id, mode) VALUES (?, 'user', ?, 'read')");
            foreach ($userIds as $u) { $tg->execute([$eventId, $u]); }
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
                            WHERE employee_id = ? AND status IN ('pending','approved')
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

        // 代理人：假別 agent=1 必填且須為候選之一（唯讀候選來自 delegate_lib，禁自查 user_delegate）
        if ((int)$type['agent'] === 1) {
            if ($agentId <= 0) return ['ok' => false, 'msg' => '此假別須指定職務代理人'];
            $cands = eg_person_delegate_candidates($db, $uid);
            $ok = false;
            foreach ($cands as $c) { if ((int)$c['user_id'] === $agentId) { $ok = true; break; } }
            if (!$ok) return ['ok' => false, 'msg' => '指定的代理人不在您的代理人設定中，請洽人事於「人事設定」維護代理人'];
        } else {
            $agentId = $agentId > 0 ? $agentId : null;
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

            // 行事曆：送審即寫「請假申請中」事件（核准時轉正）；免簽假別直接寫正式休假
            // 申請中的可見對象＝申請人＋簽核鏈主管（讓主管在行事曆上就看到有人要請假）
            $viewers = [];
            foreach ($chain as $c) $viewers[] = (int)$c['user_id'];
            if ($agentId) $viewers[] = (int)$agentId;
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
            eg_leave_notify($db, $reqId, "📋 請假單 #{$reqId} 待您簽核", $body . "\n（請至 請假系統 頁面簽核）",
                            [$r['signer_id']], $uid, $reason);
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
                            WHERE leave_request_id = ? AND status = 'pending'
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
                $st = $db->prepare("SELECT COUNT(*) FROM leave_approval WHERE leave_request_id = ? AND status = 'pending'");
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
            // 全過：通知申請人＋代理人（代理人此刻才被告知接手，2026-07-28 定案）
            $targets = [(int)$req['employee_id']];
            if (!empty($req['agent_user_id'])) $targets[] = (int)$req['agent_user_id'];
            $agentLine = '';
            if (!empty($req['agent_user_id'])) {
                $names->execute([(int)$req['agent_user_id']]);
                $agentLine = "\n職務代理人：" . (string)$names->fetchColumn() . "（請假期間請協助代理職務）";
            }
            eg_leave_notify($db, $requestId, "✅ 請假單 #{$requestId} 已核准", $baseBody . $agentLine
                            . ((string)$req['attach_status'] === 'pending' ? "\n【提醒】證明文件尚未補上傳" : ''),
                            $targets, 0, $reason);
        } else {
            // 過一層還有下一層：通知下一層簽核人
            $st = $db->prepare("SELECT * FROM leave_approval WHERE leave_request_id = ? AND status = 'pending'
                                ORDER BY approval_level ASC LIMIT 1");
            $st->execute([$requestId]);
            if ($next = $st->fetch(PDO::FETCH_ASSOC)) {
                $r = eg_resolve_signer($db, (int)$next['approver_id'],
                    ['applicant_id' => (int)$req['employee_id'], 'flow_key' => 'leave', 'doc_id' => $requestId]);
                eg_leave_notify($db, $requestId, "📋 請假單 #{$requestId} 待您簽核（第 {$next['approval_level']} 層）",
                                $baseBody . "\n（請至 請假系統 頁面簽核）", [$r['signer_id']], $userId, $reason);
            }
        }

        return ['ok' => true, 'msg' => $action === 'rejected' ? '已退回' : ($final ? '已核准（流程完成）' : '已簽核，送下一層'), 'final' => $final];
    }
}

// ============================== 撤回 / 銷假 ==============================

if (!function_exists('eg_leave_cancel')) {
    /**
     * pending=撤回（限本人）；approved=銷假（限本人，直接生效並通知已簽核者與代理人，2026-07-28 定案）。
     * 管理員（$isAdmin）可代任何人操作。
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
            if (!empty($req['agent_user_id'])) $targets[] = (int)$req['agent_user_id'];
            eg_leave_notify($db, $requestId, "↩️ 請假單 #{$requestId} 已銷假", $body, $targets, $userId, (string)$req['reason']);
        } else {
            try {
                $st = $db->prepare("SELECT approver_id FROM leave_approval
                                    WHERE leave_request_id = ? AND status = 'pending' ORDER BY approval_level ASC LIMIT 1");
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

// ============================== 待簽清單 ==============================

if (!function_exists('eg_leave_pending_for')) {
    /**
     * 某人的待簽清單：所有 pending 單中「目前輪到的層」，且此人＝該層主管本人或當下解析出的代理。
     * 回傳含申請人/假別/時段與 as_delegate 標記。
     */
    function eg_leave_pending_for(PDO $db, int $userId): array {
        $rows = [];
        try {
            // 每張 pending 單目前輪到的層
            $st = $db->query(
                "SELECT la.*, lr.employee_id, lr.start_datetime, lr.end_datetime, lr.total_hours, lr.total_days,
                        lr.reason, lr.is_backdated, lr.attach_status, lr.submit_time,
                        lt.leave_name, u.user_cname AS applicant_name
                 FROM leave_approval la
                 JOIN leave_request lr ON lr.id = la.leave_request_id AND lr.status = 'pending'
                 JOIN leave_type lt ON lt.id = lr.leave_type_id
                 JOIN user u ON u.id = lr.employee_id
                 WHERE la.status = 'pending'
                   AND la.approval_level = (SELECT MIN(la2.approval_level) FROM leave_approval la2
                                            WHERE la2.leave_request_id = la.leave_request_id AND la2.status = 'pending')
                 ORDER BY lr.submit_time ASC");
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
