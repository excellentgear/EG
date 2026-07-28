<?php
/**
 * roster_lib.php — 通用輪值排班引擎（掃地/值日/現場班別皆共用）
 *
 * 設計重點：
 *  - 「日期 × 職務欄(lane)」的格子模型：男廁/女廁是兩欄、早班/晚班是兩欄，單欄則退化成單純輪值。
 *  - 排班結果「物化」寫入 roster_assignment；過去(<今天)一律凍結，離職/改人只重算未來，不動過去。
 *  - 工作天判定比照 views/pages/calendar.php：evenement + event_category.day_type（s=休假、m=補班）。
 *  - 路徑/設定不寫死；純函式，$pdo 由呼叫端(API/頁面)帶入。
 *
 * 主要函式：
 *  roster_workday_context($pdo,$from,$to)   取區間內休假/補班集合
 *  roster_is_workday($d,$ctx)               某日是否上班日
 *  roster_generate_duty_dates($board,$from,$to,$ctx)  依週期算出所有勤務日
 *  roster_regenerate($pdo,$boardId,$fromDate=null)    重算並物化未來排班（凍結過去、保留調班/簽核）
 *  roster_visibility_user_ids($pdo,$boardId)          公開對象展開成 user.id 集合（'*'=全體）
 *  roster_can_view_board($pdo,$board,$uid,$features)   可見性判定
 */

if (!function_exists('roster_current_user')) {
    function roster_current_user(PDO $pdo): array {
        $uid = (int)($_SESSION['id'] ?? 0);
        $name = $_SESSION['user_cname'] ?? ($_SESSION['userName'] ?? '');
        if ($uid && $name === '') {
            try {
                $st = $pdo->prepare("SELECT user_cname FROM user WHERE id=?");
                $st->execute([$uid]);
                $name = (string)$st->fetchColumn();
            } catch (Exception $e) {}
        }
        return ['id' => $uid, 'name' => $name];
    }
}

/* ───────────────────────── 工作天判定 ───────────────────────── */

if (!function_exists('roster_workday_context')) {
    /**
     * 取 [from,to] 期間內的休假日(s)/補班日(m)集合。
     * 回傳 ['holidays'=>set(YYYY-MM-DD=>1), 'makeup'=>set]
     */
    function roster_workday_context(PDO $pdo, string $from, string $to): array {
        $holidays = [];
        $makeup   = [];
        try {
            $st = $pdo->prepare("
                SELECT e.start, e.end, ec.day_type
                FROM evenement e
                JOIN event_category ec ON e.category_id = ec.id
                WHERE ec.day_type IN ('s','m')
                  AND DATE(e.start) <= :to AND DATE(e.end) >= :from");
            $st->execute([':from' => $from, ':to' => $to]);
            foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $ev) {
                $cur = new DateTime(substr($ev['start'], 0, 10));
                $end = new DateTime(substr($ev['end'], 0, 10));
                // 逐日展開事件涵蓋的每一天
                while ($cur <= $end) {
                    $d = $cur->format('Y-m-d');
                    if ($ev['day_type'] === 's')      $holidays[$d] = 1;
                    elseif ($ev['day_type'] === 'm')  $makeup[$d]   = 1;
                    $cur->modify('+1 day');
                }
            }
        } catch (Exception $e) { /* 表缺失時退化成僅週末判定 */ }
        return ['holidays' => $holidays, 'makeup' => $makeup];
    }
}

if (!function_exists('roster_is_workday')) {
    function roster_is_workday(string $ymd, array $ctx): bool {
        $dow = (int)(new DateTime($ymd))->format('w'); // 0=日..6=六
        $isWeekend = ($dow === 0 || $dow === 6);
        $isHoliday = isset($ctx['holidays'][$ymd]);
        $isMakeup  = isset($ctx['makeup'][$ymd]);
        return $isMakeup || (!$isWeekend && !$isHoliday);
    }
}

if (!function_exists('roster_shift_workday')) {
    /** 從 $ymd 起，往 $dir(+1/-1) 找最近的上班日；找不到回 null。 */
    function roster_shift_workday(string $ymd, array $ctx, int $dir, int $maxStep = 21): ?string {
        $d = new DateTime($ymd);
        for ($i = 0; $i <= $maxStep; $i++) {
            $s = $d->format('Y-m-d');
            if (roster_is_workday($s, $ctx)) return $s;
            $d->modify(($dir >= 0 ? '+' : '-') . '1 day');
        }
        return null;
    }
}

/* ───────────────────── 勤務日期產生 ───────────────────── */

if (!function_exists('roster_generate_duty_dates')) {
    /**
     * 依 board 週期設定，算出 [from,to] 內所有勤務日（已排序去重）。
     * board: exec_cadence(daily|weekly|monthly), exec_count, exec_weekdays, exec_monthdays,
     *        holiday_policy(skip|postpone|advance), start_date
     */
    function roster_generate_duty_dates(array $board, string $from, string $to, array $ctx): array {
        $start = $board['start_date'];
        if ($from < $start) $from = $start;
        if ($from > $to) return [];

        $cadence = $board['exec_cadence'];
        $policy  = $board['holiday_policy'] ?: 'skip';
        $set = [];

        $applyPolicy = function (string $ymd) use ($ctx, $policy, $start, &$set) {
            if (roster_is_workday($ymd, $ctx)) { $set[$ymd] = 1; return; }
            if ($policy === 'postpone') {
                $n = roster_shift_workday($ymd, $ctx, +1);
                if ($n !== null) $set[$n] = 1;
            } elseif ($policy === 'advance') {
                $n = roster_shift_workday($ymd, $ctx, -1);
                if ($n !== null && $n >= $start) $set[$n] = 1;
            }
            // skip：直接不排
        };

        if ($cadence === 'daily') {
            $d = new DateTime($from); $e = new DateTime($to);
            while ($d <= $e) {
                $s = $d->format('Y-m-d');
                if (roster_is_workday($s, $ctx)) $set[$s] = 1;
                $d->modify('+1 day');
            }
        } elseif ($cadence === 'weekly') {
            $weekdays = array_filter(array_map('intval', explode(',', $board['exec_weekdays'] ?? '')));
            if (!empty($weekdays)) {
                // 指定星期幾（1=一..7=日）→ 遇假日套 holiday_policy
                $d = new DateTime($from); $e = new DateTime($to);
                while ($d <= $e) {
                    $iso = (int)$d->format('N'); // 1..7
                    if (in_array($iso, $weekdays, true)) $applyPolicy($d->format('Y-m-d'));
                    $d->modify('+1 day');
                }
            } else {
                // 未指定 → 每週自動平均分散 exec_count 次（次數隨當週工作天自動增減）
                $n = max(1, (int)$board['exec_count']);
                foreach (roster_week_ranges($from, $to) as $wk) {
                    $work = roster_workdays_in_range($wk[0], $wk[1], $ctx);
                    foreach (roster_even_pick($work, $n) as $s) $set[$s] = 1;
                }
            }
        } elseif ($cadence === 'monthly') {
            $monthdays = array_filter(array_map('intval', explode(',', $board['exec_monthdays'] ?? '')));
            foreach (roster_month_ranges($from, $to) as $mo) {
                if (!empty($monthdays)) {
                    $y = (int)substr($mo[0], 0, 4); $m = (int)substr($mo[0], 5, 2);
                    $dim = (int)date('t', mktime(0, 0, 0, $m, 1, $y));
                    foreach ($monthdays as $md) {
                        if ($md < 1 || $md > $dim) continue;
                        $ymd = sprintf('%04d-%02d-%02d', $y, $m, $md);
                        if ($ymd < $from || $ymd > $to) continue;
                        $applyPolicy($ymd);
                    }
                } else {
                    $n = max(1, (int)$board['exec_count']);
                    $work = roster_workdays_in_range($mo[0], $mo[1], $ctx);
                    foreach (roster_even_pick($work, $n) as $s) $set[$s] = 1;
                }
            }
        }

        $dates = array_keys($set);
        sort($dates);
        return $dates;
    }
}

if (!function_exists('roster_workdays_in_range')) {
    function roster_workdays_in_range(string $from, string $to, array $ctx): array {
        $out = [];
        $d = new DateTime($from); $e = new DateTime($to);
        while ($d <= $e) {
            $s = $d->format('Y-m-d');
            if (roster_is_workday($s, $ctx)) $out[] = $s;
            $d->modify('+1 day');
        }
        return $out;
    }
}

if (!function_exists('roster_even_pick')) {
    /** 從清單中平均挑 n 個（保序）。n>=len 全取。 */
    function roster_even_pick(array $items, int $n): array {
        $len = count($items);
        if ($len === 0 || $n <= 0) return [];
        if ($n >= $len) return $items;
        $out = [];
        for ($i = 0; $i < $n; $i++) {
            $idx = (int)floor(($i + 0.5) * $len / $n);
            if ($idx >= $len) $idx = $len - 1;
            $out[] = $items[$idx];
        }
        return array_values(array_unique($out));
    }
}

if (!function_exists('roster_week_ranges')) {
    /** 把 [from,to] 切成一週(週一~週日)一段，回傳 [[wkFrom,wkTo],...]（已裁到 from/to 邊界）。 */
    function roster_week_ranges(string $from, string $to): array {
        $out = [];
        $d = new DateTime($from);
        // 回退到本週週一
        $iso = (int)$d->format('N');
        if ($iso > 1) $d->modify('-' . ($iso - 1) . ' day');
        $e = new DateTime($to);
        while ($d <= $e) {
            $wkStart = $d->format('Y-m-d');
            $wkEndObj = (clone $d)->modify('+6 day');
            $wkEnd = $wkEndObj->format('Y-m-d');
            $lo = max($wkStart, $from);
            $hi = min($wkEnd, $to);
            if ($lo <= $hi) $out[] = [$lo, $hi];
            $d->modify('+7 day');
        }
        return $out;
    }
}

if (!function_exists('roster_month_ranges')) {
    /** 把 [from,to] 切成每月一段，回傳 [[moFrom,moTo],...]（已裁到邊界）。 */
    function roster_month_ranges(string $from, string $to): array {
        $out = [];
        $y = (int)substr($from, 0, 4); $m = (int)substr($from, 5, 2);
        $endY = (int)substr($to, 0, 4); $endM = (int)substr($to, 5, 2);
        while ($y < $endY || ($y === $endY && $m <= $endM)) {
            $first = sprintf('%04d-%02d-01', $y, $m);
            $last  = sprintf('%04d-%02d-%02d', $y, $m, (int)date('t', mktime(0, 0, 0, $m, 1, $y)));
            $lo = max($first, $from);
            $hi = min($last, $to);
            if ($lo <= $hi) $out[] = [$lo, $hi];
            $m++; if ($m > 12) { $m = 1; $y++; }
        }
        return $out;
    }
}

/* ───────────────────── 輪值分配 ＋ 物化 ───────────────────── */

if (!function_exists('roster_regenerate')) {
    /**
     * 重算並物化 board 的未來排班。
     *  - 過去(< regenFrom，預設今天)一律不動（凍結）。
     *  - is_adjusted=1 的格子(手動調班)一律保留，不覆寫。
     *  - 未來非調班格子：依現行在職人員與週期重新指派；不再需要的未來格子刪除。
     * 回傳 ['generated'=>寫入格數, 'from'=>, 'to'=>]
     */
    function roster_regenerate(PDO $pdo, int $boardId, ?string $fromDate = null, ?string $throughDate = null): array {
        $st = $pdo->prepare("SELECT * FROM roster_board WHERE id=?");
        $st->execute([$boardId]);
        $board = $st->fetch(PDO::FETCH_ASSOC);
        if (!$board) return ['generated' => 0];

        $today = (new DateTime('today'))->format('Y-m-d');
        $regenFrom = $fromDate ?: $today;
        if ($regenFrom < $board['start_date']) $regenFrom = $board['start_date'];

        // 排班無終止日：預設先物化到「今日所在月 +2 個月」月底（兩月月曆夠用）；
        // 若指定 throughDate（使用者翻到更後面的月份）則補算到那個月底。上限今日 +24 個月防暴衝。
        $horizon = (new DateTime('first day of this month'))->modify('+2 month')
                    ->modify('last day of this month')->format('Y-m-d');
        if ($throughDate && $throughDate > $horizon) {
            $cap = (new DateTime('first day of this month'))->modify('+24 month')->modify('last day of this month')->format('Y-m-d');
            $horizon = min($throughDate, $cap);
        }
        // 有終止日則不排到終止日之後
        if (!empty($board['end_date']) && $horizon > $board['end_date']) $horizon = $board['end_date'];
        if ($horizon < $regenFrom) return ['generated' => 0, 'from' => $regenFrom, 'to' => $horizon];

        // lanes（依排序）
        $lanes = $pdo->prepare("SELECT * FROM roster_lane WHERE board_id=? ORDER BY sort_order, id");
        $lanes->execute([$boardId]);
        $lanes = $lanes->fetchAll(PDO::FETCH_ASSOC);
        if (empty($lanes)) return ['generated' => 0];
        $laneCount = count($lanes);

        // 人員名單
        $poolShared = null;
        $loadMembers = function ($laneId) use ($pdo, $boardId, $board, &$poolShared) {
            if ($board['member_mode'] === 'shared_pool') {
                if ($poolShared === null) {
                    $q = $pdo->prepare("SELECT user_id FROM roster_member WHERE board_id=? AND lane_id IS NULL AND active=1 ORDER BY sort_order, id");
                    $q->execute([$boardId]);
                    $poolShared = $q->fetchAll(PDO::FETCH_COLUMN);
                }
                return $poolShared;
            }
            $q = $pdo->prepare("SELECT user_id FROM roster_member WHERE board_id=? AND lane_id=? AND active=1 ORDER BY sort_order, id");
            $q->execute([$boardId, $laneId]);
            return $q->fetchAll(PDO::FETCH_COLUMN);
        };

        // 勤務日：需從 start_date 全序列以維持輪值連續性（bucket 由日期決定，與過去用誰無關）
        $ctxFull = roster_workday_context($pdo, $board['start_date'], $horizon);
        $allDates = roster_generate_duty_dates($board, $board['start_date'], $horizon, $ctxFull);
        if (empty($allDates)) return ['generated' => 0];

        // 建立 occurrence / week / month bucket 對照
        $occ = array_flip($allDates); // date => index
        $weekBucket = []; $weekSeen = []; $wc = 0;
        $monBucket  = []; $monSeen  = []; $mc = 0;
        foreach ($allDates as $d) {
            $wk = (new DateTime($d))->format('o-W');
            if (!isset($weekSeen[$wk])) { $weekSeen[$wk] = $wc++; }
            $weekBucket[$d] = $weekSeen[$wk];
            $mo = substr($d, 0, 7);
            if (!isset($monSeen[$mo])) { $monSeen[$mo] = $mc++; }
            $monBucket[$d] = $monSeen[$mo];
        }
        $rotate = $board['rotate_unit'] ?: 'each';
        $rn = max(1, (int)($board['rotate_n'] ?? 1)); // 連續 N 個單位才換手
        $bucketOf = function ($d, $laneIdx) use ($rotate, $rn, $occ, $weekBucket, $monBucket, $board, $laneCount) {
            if ($rotate === 'week' || $rotate === 'weekly')       $b = intdiv($weekBucket[$d], $rn);
            elseif ($rotate === 'month' || $rotate === 'monthly') $b = intdiv($monBucket[$d], $rn);
            elseif ($rotate === 'day')                            $b = intdiv($occ[$d], $rn);
            else                                                  $b = $occ[$d]; // each＝每次執行就換
            // 共用池：跨欄再加位移，讓同組人輪流換到不同欄
            if ($board['member_mode'] === 'shared_pool') return $b * $laneCount + $laneIdx;
            return $b;
        };

        // 只處理 regenFrom 之後的日期
        $futureDates = array_values(array_filter($allDates, fn($d) => $d >= $regenFrom));

        // 既有未來格子（保留調班/簽核狀態）
        $ex = $pdo->prepare("SELECT id, lane_id, duty_date, is_adjusted FROM roster_assignment WHERE board_id=? AND duty_date>=?");
        $ex->execute([$boardId, $regenFrom]);
        $existing = [];
        foreach ($ex->fetchAll(PDO::FETCH_ASSOC) as $r) {
            $existing[$r['lane_id'] . '|' . $r['duty_date']] = $r;
        }

        $pdo->beginTransaction();
        try {
            $ins = $pdo->prepare("INSERT INTO roster_assignment (board_id, lane_id, duty_date, user_id, orig_user_id) VALUES (?,?,?,?,?)");
            $upd = $pdo->prepare("UPDATE roster_assignment SET user_id=?, orig_user_id=?, sign_status=IF(user_id=?,sign_status,0), signed_at=IF(user_id=?,signed_at,NULL), signed_by=IF(user_id=?,signed_by,NULL) WHERE id=?");
            $del = $pdo->prepare("DELETE FROM roster_assignment WHERE id=?");
            $written = 0;
            $wanted = []; // lane|date => true

            foreach ($lanes as $li => $lane) {
                $members = $loadMembers($lane['id']);
                $poolSize = count($members);
                foreach ($futureDates as $d) {
                    $key = $lane['id'] . '|' . $d;
                    $wanted[$key] = true;
                    $prev = $existing[$key] ?? null;
                    if ($prev && (int)$prev['is_adjusted'] === 1) continue; // 手動調班保留
                    if ($poolSize === 0) { // 無人可排 → 移除既有自動格
                        if ($prev) $del->execute([$prev['id']]);
                        continue;
                    }
                    $pos = $bucketOf($d, $li) % $poolSize;
                    $uid = (int)$members[$pos];
                    if ($prev) {
                        $upd->execute([$uid, $uid, $uid, $uid, $uid, $prev['id']]);
                    } else {
                        $ins->execute([$boardId, $lane['id'], $d, $uid, $uid]);
                    }
                    $written++;
                }
            }
            // 刪除不再需要的未來自動格（週期改變等）；調班格保留
            foreach ($existing as $key => $r) {
                if (!isset($wanted[$key]) && (int)$r['is_adjusted'] === 0) {
                    $del->execute([$r['id']]);
                }
            }
            // 終止日縮短時，清掉終止日之後的未來排班
            if (!empty($board['end_date'])) {
                $pdo->prepare("DELETE FROM roster_assignment WHERE board_id=? AND duty_date>? AND duty_date>=?")
                    ->execute([$boardId, $board['end_date'], $regenFrom]);
            }
            $pdo->commit();
            return ['generated' => $written, 'from' => $regenFrom, 'to' => $horizon];
        } catch (Exception $e) {
            $pdo->rollBack();
            throw $e;
        }
    }
}

/* ───────────────────── 離職自動移出 ───────────────────── */

if (!function_exists('roster_sync_member_status')) {
    /**
     * 順路同步：依 user.state 自動移出/復入排班成員，並重算受影響 board 的未來排班（過去凍結）。
     *  - 在職＝state IN (1,99)；其餘（0離職/2留停/3育嬰/90公用…）＝非在職。
     *  - 非在職且目前 active=1 → 自動移出(active=0, reason='auto')，未來由剩下的人遞補。
     *  - 已在職且被「自動移出」(reason='auto') → 自動復入(active=1)，回到原順序重新排入。
     *  - 只復「自動移出」的；管理員手動移除(reason='manual')者不會被自動加回。
     * 回傳受影響 board 數。
     */
    function roster_sync_member_status(PDO $pdo): int {
        $rows = $pdo->query("
            SELECT DISTINCT m.board_id
            FROM roster_member m
            JOIN user u ON u.id = m.user_id
            JOIN roster_board b ON b.id = m.board_id
            WHERE b.status='active' AND (
                (m.active=1 AND u.state NOT IN (1,99)) OR
                (m.active=0 AND m.removed_reason='auto' AND u.state IN (1,99))
            )")->fetchAll(PDO::FETCH_COLUMN);
        if (empty($rows)) return 0;
        // 非在職 → 移出
        $pdo->exec("
            UPDATE roster_member m JOIN user u ON u.id = m.user_id
            SET m.active=0, m.removed_at=NOW(), m.removed_reason='auto'
            WHERE m.active=1 AND u.state NOT IN (1,99)");
        // 回到在職且原為自動移出 → 復入（保留原 sort_order）
        $pdo->exec("
            UPDATE roster_member m JOIN user u ON u.id = m.user_id
            SET m.active=1, m.removed_at=NULL, m.removed_reason=''
            WHERE m.active=0 AND m.removed_reason='auto' AND u.state IN (1,99)");
        foreach ($rows as $bid) {
            try { roster_regenerate($pdo, (int)$bid); } catch (Exception $e) {}
        }
        return count($rows);
    }
}

/* ───────────────────── 公開對象 / 可見性 ───────────────────── */

if (!function_exists('roster_visibility_user_ids')) {
    /**
     * 展開 board 公開對象為 user.id 集合。回傳 ['*'] 代表全體。
     * dept=department.id、status=user_status.id(比對 user_status/2/3)、user=user.id。
     */
    function roster_visibility_user_ids(PDO $pdo, int $boardId): array {
        $st = $pdo->prepare("SELECT target_type, target_id FROM roster_visibility WHERE board_id=?");
        $st->execute([$boardId]);
        $ids = [];
        foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $t) {
            $tid = (int)$t['target_id'];
            switch ($t['target_type']) {
                case 'all': return ['*'];
                case 'user': $ids[$tid] = 1; break;
                case 'dept':
                    $q = $pdo->prepare("SELECT DISTINCT user_id FROM user_department_position_map WHERE department_id=?");
                    $q->execute([$tid]);
                    foreach ($q->fetchAll(PDO::FETCH_COLUMN) as $u) $ids[(int)$u] = 1;
                    break;
                case 'status':
                    $q = $pdo->prepare("SELECT id FROM user WHERE user_status=:s OR user_status2=:s OR user_status3=:s");
                    $q->execute([':s' => $tid]);
                    foreach ($q->fetchAll(PDO::FETCH_COLUMN) as $u) $ids[(int)$u] = 1;
                    break;
            }
        }
        return array_keys($ids);
    }
}

if (!function_exists('roster_can_view_board')) {
    function roster_can_view_board(PDO $pdo, array $board, int $uid, array $features = []): bool {
        if ((int)$board['owner_id'] === $uid) return true;
        if (in_array('all', $features, true) || in_array('roster_admin', $features, true)) return true;
        $vis = roster_visibility_user_ids($pdo, (int)$board['id']);
        if (in_array('*', $vis, true)) return true;
        return in_array($uid, array_map('intval', $vis), true);
    }
}

/* ───────────────────── 選單資料 ───────────────────── */

if (!function_exists('roster_load_pickers')) {
    /** 載入公開對象選單所需的 部門/身分別/人員 三清單（比照 createEvent.php）。 */
    function roster_load_pickers(PDO $pdo): array {
        $departments = $pdo->query("SELECT id, name, parent_id, level FROM department ORDER BY level ASC, sort_order ASC, name ASC")->fetchAll(PDO::FETCH_ASSOC);
        $statuses    = $pdo->query("SELECT id, title FROM `user_status` ORDER BY id")->fetchAll(PDO::FETCH_ASSOC);
        $users       = $pdo->query("
            SELECT u.id, u.user_cname, d.name AS department_name, p.name AS position_name
            FROM user u
            LEFT JOIN user_department_position_map udpm ON u.id = udpm.user_id AND udpm.is_main = 1
            LEFT JOIN department d ON udpm.department_id = d.id
            LEFT JOIN position p ON udpm.position_id = p.id
            WHERE u.state NOT IN (0, 90)
            ORDER BY u.user_cname ASC")->fetchAll(PDO::FETCH_ASSOC);
        $shiftTypes  = [];
        try {
            $shiftTypes = $pdo->query("SELECT shift_type_id, shift_name, shift_code, start_time, end_time, color FROM shift_type WHERE is_active=1 ORDER BY sort_order, shift_type_id")->fetchAll(PDO::FETCH_ASSOC);
        } catch (Exception $e) {}
        return ['departments' => $departments, 'statuses' => $statuses, 'users' => $users, 'shift_types' => $shiftTypes];
    }
}

if (!function_exists('roster_leave_map')) {
    /**
     * 查一批 user 在 [from,to] 內「已核准」的請假，展開成 "uid|YYYY-MM-DD" => 假別名稱。
     * 權威來源＝leave_request（employee_id=user.id，status approved，start/end datetime 區間重疊）。
     */
    function roster_leave_map(PDO $pdo, array $userIds, string $from, string $to): array {
        $userIds = array_values(array_unique(array_filter(array_map('intval', $userIds))));
        if (empty($userIds)) return [];
        $map = [];
        $in = implode(',', array_fill(0, count($userIds), '?'));
        $expand = function ($uid, $s, $e, $label) use ($from, $to, &$map) {
            $s = max($from, substr($s, 0, 10)); $e = min($to, substr($e, 0, 10));
            if ($s > $e) return;
            $d = new DateTime($s); $ed = new DateTime($e);
            while ($d <= $ed) { $map[$uid . '|' . $d->format('Y-m-d')] = $label; $d->modify('+1 day'); }
        };
        // A) 行事曆請假（暫用：請假系統未建，用 evenement_actor 綁人＋有 leave_type_id 或分類含「休假」）
        try {
            $st = $pdo->prepare("
                SELECT a.user_id, e.start, e.end, ec.category_name, lt.leave_name
                FROM evenement e
                JOIN evenement_actor a ON a.event_id = e.id
                LEFT JOIN event_category ec ON ec.id = e.category_id
                LEFT JOIN leave_type lt ON lt.id = e.leave_type_id
                WHERE a.user_id IN ($in)
                  AND (e.leave_type_id IS NOT NULL OR ec.category_name LIKE '%休假%')
                  AND DATE(e.start) < DATE_ADD(?, INTERVAL 1 DAY) AND DATE(e.end) >= ?");
            $st->execute(array_merge($userIds, [$to, $from]));
            foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $r) {
                $expand((int)$r['user_id'], $r['start'], $r['end'], $r['leave_name'] ?: ($r['category_name'] ?: '請假'));
            }
        } catch (Exception $e) { /* 行事曆查詢失敗不擋 */ }
        // B) 正式請假單（leave_request；目前尚未有資料，未來上線自動生效）
        try {
            $st = $pdo->prepare("
                SELECT lr.employee_id, lr.start_datetime, lr.end_datetime, lt.leave_name
                FROM leave_request lr LEFT JOIN leave_type lt ON lt.id = lr.leave_type_id
                WHERE lr.employee_id IN ($in) AND lr.status IN ('approved','核准')
                  AND lr.start_datetime < DATE_ADD(?, INTERVAL 1 DAY) AND lr.end_datetime >= ?");
            $st->execute(array_merge($userIds, [$to, $from]));
            foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $r) {
                $expand((int)$r['employee_id'], $r['start_datetime'], $r['end_datetime'], $r['leave_name'] ?: '請假');
            }
        } catch (Exception $e) { /* leave_request 不存在等 */ }
        return $map;
    }
}

if (!function_exists('roster_user_name_map')) {
    /** id => 中文姓名（含已離職，供顯示過去排班）。 */
    function roster_user_name_map(PDO $pdo, array $ids): array {
        $ids = array_values(array_unique(array_map('intval', array_filter($ids))));
        if (empty($ids)) return [];
        $in = implode(',', array_fill(0, count($ids), '?'));
        $st = $pdo->prepare("SELECT id, user_cname, state FROM user WHERE id IN ($in)");
        $st->execute($ids);
        $map = [];
        foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $r) {
            $map[(int)$r['id']] = ['name' => $r['user_cname'], 'left' => ((int)$r['state'] === 0)];
        }
        return $map;
    }
}
