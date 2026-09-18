<?php
/**
 * leave_stats_lib.php — 請假統計（月／年／趨勢／部門・人員）的唯一實作點
 *
 * 為什麼獨立一支：leave_lib.php 已近 1700 行，統計是純讀取、與交易流程無關，
 * 分開才不會讓交易邏輯檔繼續長大（CLAUDE.md 鐵律2 巨檔保護）。
 *
 * 三個原則：
 *  1. 一律後端全量計算。任何總計／排行／趨勢都在這裡對「全部符合條件的資料」算完才回傳，
 *     前端只負責畫，不可拿已載入的那一頁自己加總（ai-rules/08 資料列表規範）。
 *  2. 年度／月份歸屬一律以請假「起日」計，與 eg_leave_year_usage()、特休額度同一口徑。
 *     跨月／跨年的長假（留職停薪等）整筆算在起日那個月，不做逐日攤分——攤分會讓
 *     「這個月誰請假」變成「這個月有人在放假」，兩者語意不同，人事看的是前者。
 *     長假天數很大會壓過其他假別，因此前端一定要提供假別篩選讓人事排除。
 *  3. 假別顏色固定（暖色系調色盤，ai-rules/10），同一假別跨頁同色，不用亂數／HSL。
 */

// 在職狀態文字（離職/留職停薪/育嬰留停…）走共用庫，別在這裡自己對照數字
require_once __DIR__ . '/user_active_lib.php';

if (!function_exists('eg_leave_palette')) {
    /**
     * 假別分類色盤（全暖色系：珊瑚紅／琥珀橘／赭棕／砂／暖棕／陶土／芥黃／磚紅）。
     * 依 leave_type.id 由小到大取用，新增假別只會往後拿新色，既有假別的顏色不會被洗掉。
     * 對比：淺底(#E8C07A/#EBD3A8/#C9A227)配深棕字，其餘深底配白字。
     */
    function eg_leave_palette(): array {
        return [
            ['bg' => '#DD5138', 'tx' => '#FFFFFF'],   // 珊瑚紅
            ['bg' => '#F0A24B', 'tx' => '#4E2C0B'],   // 琥珀橘
            ['bg' => '#B06F27', 'tx' => '#FFFFFF'],   // 赭棕
            ['bg' => '#E8C07A', 'tx' => '#4E2C0B'],   // 淺琥珀
            ['bg' => '#8A5A2B', 'tx' => '#FFFFFF'],   // 暖棕
            ['bg' => '#D98A5F', 'tx' => '#3A2C1A'],   // 陶土
            ['bg' => '#C9A227', 'tx' => '#3A2C1A'],   // 芥黃（暖）
            ['bg' => '#A34E2A', 'tx' => '#FFFFFF'],   // 磚紅
            ['bg' => '#EBD3A8', 'tx' => '#6B471A'],   // 砂
            ['bg' => '#7A4A34', 'tx' => '#FFFFFF'],   // 深可可
        ];
    }
}

if (!function_exists('eg_leave_type_colors')) {
    /** leave_type.id => ['bg'=>..,'tx'=>..]（依 id 遞增指派，跨頁一致） */
    function eg_leave_type_colors(PDO $db): array {
        static $cache = null;
        if ($cache !== null) return $cache;
        $pal = eg_leave_palette();
        $out = [];
        try {
            $ids = $db->query("SELECT id FROM leave_type ORDER BY id ASC")->fetchAll(PDO::FETCH_COLUMN);
            foreach (array_values($ids) as $i => $id) $out[(int)$id] = $pal[$i % count($pal)];
        } catch (Throwable $e) {}
        $cache = $out;
        return $out;
    }
}

if (!function_exists('eg_leave_stats_people')) {
    /**
     * 統計用的人員資料（id => 姓名/部門/職稱/在職狀態）。
     *
     * 注意：這裡**不能**用 eg_people_list()。人員列表鐵則規範的是「要挑誰、要通知誰」的名單
     * （下拉、勾選、簽核對象），那種名單本來就該濾掉離職者；統計看的是歷史事實——
     * 某人去年請了 10 天假，今年離職了，那 10 天仍然發生過，濾掉會讓年度總計對不起來。
     * 因此這裡含全部狀態，並帶 state_label 讓畫面標「已離職」。
     * 篩選用的部門／人員下拉仍走 eg_people_list()（見 Leave_API 的 stats_options）。
     */
    function eg_leave_stats_people(PDO $db, array $userIds, array $scopeDeptIds = []): array {
        $userIds = array_values(array_unique(array_filter(array_map('intval', $userIds))));
        if (!$userIds) return [];
        $in = implode(',', $userIds);
        /* 一人可能掛多個部門/職稱：這裡挑「統計要算到哪個部門」的那一筆。
           優先取**命中目前部門篩選的那一筆**，沒篩選才退回主要職務(is_main)、再退回最小 id。
           為什麼（2026-09-18 使用者回報「上面的篩選似乎跟底下圖面沒有關係」）：
           篩「資材課（含下轄）」時，命中的人裡有兼任者——何沐桐主職技術課、兼任生管組——
           只看 is_main 就把他的假算進「技術課」，於是部門統計圖上冒出**根本不在篩選範圍內的技術課**，
           使用者看到的就是「我明明選了資材課，圖上卻有技術課、管理課、業務課」。 */
        $scopeDeptIds = array_values(array_filter(array_map('intval', $scopeDeptIds)));
        $scopeOrder = $scopeDeptIds
                    ? "(m2.department_id IN (" . implode(',', $scopeDeptIds) . ")) DESC, "
                    : '';
        $pick = "SELECT m2.id FROM user_department_position_map m2 WHERE m2.user_id = u.id
                 ORDER BY {$scopeOrder}m2.is_main DESC, m2.id ASC LIMIT 1";
        $out = [];
        try {
            $sql = "SELECT u.id, u.user_cname, u.state,
                           m.department_id AS dept_id, d.name AS dept_name,
                           COALESCE(d.sort_order, 999) AS dept_sort,
                           p.name AS position_name, COALESCE(p.sort_order, 999) AS position_sort
                    FROM `user` u
                    LEFT JOIN user_department_position_map m ON m.id = ({$pick})
                    LEFT JOIN department d ON d.id = m.department_id
                    LEFT JOIN position p ON p.id = m.position_id
                    WHERE u.id IN ({$in})";
            foreach ($db->query($sql)->fetchAll(PDO::FETCH_ASSOC) as $r) {
                $st = (int)$r['state'];
                $out[(int)$r['id']] = [
                    'name'          => (string)$r['user_cname'],
                    'dept_id'       => $r['dept_id'] === null ? 0 : (int)$r['dept_id'],
                    'dept_name'     => (string)($r['dept_name'] ?? ''),
                    'dept_sort'     => (int)$r['dept_sort'],
                    'position_name' => (string)($r['position_name'] ?? ''),
                    'position_sort' => (int)$r['position_sort'],
                    'state'         => $st,
                    'state_label'   => function_exists('eg_user_state_label') ? eg_user_state_label($st) : '',
                ];
            }
        } catch (Throwable $e) {}
        return $out;
    }
}

if (!function_exists('eg_leave_stats')) {
    /**
     * 請假統計主體。一次撈出符合條件的全部請假單，再於 PHP 端彙總成各分頁要的資料。
     *
     * 為什麼一次撈完再彙總，而不是下 6 支 GROUP BY：
     *   月報／年報／趨勢／部門／人員看的是同一批資料的不同切法，分開下 SQL 一旦條件寫歪
     *   就會出現「月報加總 ≠ 年報」這種對不起來的數字。單一資料源彙總可以杜絕。
     *   請假單資料量以人數×年為級距（數千列頂天），一次撈完不是問題。
     *
     * @param array $opt
     *   scope_user_ids  array|null 可視人員白名單（null＝不限，人事/管理員用）
     *   year            int|'all'  選定年度（影響 kpi/by_month/by_type/by_dept/by_person）
     *   dept_id         int        只看某部門（0＝全部）
     *   user_id         int        只看某人（0＝全部）
     *   type_ids        array      只看這些假別（空＝全部）
     *   statuses        array      納入的狀態（預設 ['approved']）
     */
    function eg_leave_stats(PDO $db, array $opt = []): array {
        $year     = $opt['year'] ?? (int)date('Y');
        $deptId   = (int)($opt['dept_id'] ?? 0);
        $userId   = (int)($opt['user_id'] ?? 0);
        $typeIds  = array_values(array_filter(array_map('intval', (array)($opt['type_ids'] ?? []))));
        $statuses = (array)($opt['statuses'] ?? ['approved']);
        $statuses = array_values(array_intersect($statuses, ['approved', 'pending', 'cancel_pending', 'rejected', 'canceled']));
        if (!$statuses) $statuses = ['approved'];
        $scopeIds = $opt['scope_user_ids'] ?? null;   // null = 不限

        // ── 假別（含固定色）──
        $types = [];
        $colors = eg_leave_type_colors($db);
        foreach ($db->query("SELECT id, leave_name, unit_type FROM leave_type ORDER BY sort_order, id")
                    ->fetchAll(PDO::FETCH_ASSOC) as $t) {
            $id = (int)$t['id'];
            $types[$id] = ['id' => $id, 'leave_name' => (string)$t['leave_name'],
                           'unit_type' => (string)$t['unit_type'],
                           'color' => $colors[$id]['bg'] ?? '#B06F27',
                           'text_color' => $colors[$id]['tx'] ?? '#FFFFFF'];
        }

        // ── 條件 ──
        $where = []; $args = [];
        $where[] = 'lr.status IN (' . implode(',', array_fill(0, count($statuses), '?')) . ')';
        foreach ($statuses as $s) $args[] = $s;
        if (is_array($scopeIds)) {
            $ids = array_values(array_filter(array_map('intval', $scopeIds)));
            if (!$ids) {   // 可視範圍是空的 → 直接回空結果，不要漏成「全部」
                return eg_leave_stats_empty($year, $types);
            }
            $where[] = 'lr.employee_id IN (' . implode(',', $ids) . ')';
        }
        if ($userId > 0) { $where[] = 'lr.employee_id = ?'; $args[] = $userId; }
        if ($typeIds)    { $where[] = 'lr.leave_type_id IN (' . implode(',', $typeIds) . ')'; }
        /* 部門篩選的範圍。with_sub（預設 1）＝含下轄部門：選「資材課」要算得到生管組／採購組／倉管組。
           但**組織是一棵樹、根是董事長室**，選根部門時「含下轄」就等於全公司——使用者選了董事長室
           卻看到全公司的圖，會以為篩選根本沒作用（2026-09-18 回報）。所以這個選項要能關掉，
           畫面上也必須把實際範圍講出來（回傳 scope_depts 讓前端顯示）。 */
        $withSub = !isset($opt['with_sub']) || !empty($opt['with_sub']);
        $scopeDeptIds = [];
        if ($deptId > 0) {
            if ($withSub) {
                require_once __DIR__ . '/org_role_lib.php';
                $scopeDeptIds = eg_dept_subtree_ids($db, $deptId);
            }
            if (!$scopeDeptIds) $scopeDeptIds = [$deptId];
            /* 這裡**故意不在 SQL 過濾部門**。部門歸屬要看「請假當時」那個人在哪個部門
               （2026-09-18 使用者回報：年中調部門的人，整年的假全被算在最新部門）。
               用現況會籍過濾的話，陳喬惠 2026-03-09 由業務課調到生產3廠之後，
               她 3 月以前在業務課請的假就再也篩不出來了。改成先全撈、下面逐筆解析當時部門再篩。 */
        }
        $w = 'WHERE ' . implode(' AND ', $where);

        // 一次撈全部年度（年報／趨勢要跨年，月報再於 PHP 端依 year 篩）
        $rows = [];
        try {
            $st = $db->prepare(
                "SELECT lr.id, lr.employee_id, lr.leave_type_id, lr.status,
                        YEAR(lr.start_datetime) AS y, MONTH(lr.start_datetime) AS m,
                        DATE(lr.start_datetime) AS sd,
                        COALESCE(lr.total_hours,0) AS hrs, COALESCE(lr.total_days,0) AS dys
                 FROM leave_request lr $w");
            $st->execute($args);
            $rows = $st->fetchAll(PDO::FETCH_ASSOC);
        } catch (Throwable $e) { $rows = []; }

        $people = eg_leave_stats_people($db, array_column($rows, 'employee_id'), $scopeDeptIds);

        /* ── 依「請假起日當時」解析部門（ai-rules/22 的同一套精神）──
           為什麼要這樣做：部門統計問的是「這個部門那一年請掉多少假」，
           而不是「現在在這個部門的人，這輩子請過多少假」。年中調部門的人若整年都算在新部門，
           舊部門的數字會憑空少一截、新部門憑空多一截，兩邊都不對。
           沒有補登異動紀錄的人一律回現況（與既有行為相同，不會因為導入這段而變動）。 */
        require_once __DIR__ . '/position_history_lib.php';
        $histByUser = [];
        try {
            $uidsIn = implode(',', array_map('intval', array_unique(array_column($rows, 'employee_id')))) ?: '0';
            foreach ($db->query("SELECT user_id, effective_date, before_json, after_json
                                 FROM user_position_history WHERE user_id IN ({$uidsIn})
                                 ORDER BY user_id, effective_date, id")->fetchAll(PDO::FETCH_ASSOC) as $h) {
                $histByUser[(int)$h['user_id']][] = $h;
            }
        } catch (Throwable $e) {}
        $deptMeta = [];   // id => [name, sort]
        try {
            foreach ($db->query("SELECT id, name, COALESCE(sort_order,999) s FROM department")->fetchAll(PDO::FETCH_ASSOC) as $d) {
                $deptMeta[(int)$d['id']] = ['name' => (string)$d['name'], 'sort' => (int)$d['s']];
            }
        } catch (Throwable $e) {}

        $asofCache = [];
        $deptAt = function (int $uid, string $date) use (&$asofCache, $histByUser, $people, $deptMeta, $scopeDeptIds) {
            $key = $uid . '|' . $date;
            if (isset($asofCache[$key])) return $asofCache[$key];
            $posts = [];
            if (!empty($histByUser[$uid])) {
                $posts = eg_position_resolve_from_rows($histByUser[$uid], $date);
            }
            $did = 0;
            if ($posts) {
                // 有部門篩選時優先取命中篩選範圍的那一筆職務（兼任者才不會被算到範圍外的部門）
                if ($scopeDeptIds) {
                    foreach ($posts as $po) {
                        if (in_array((int)$po['department_id'], $scopeDeptIds, true)) { $did = (int)$po['department_id']; break; }
                    }
                }
                if (!$did) {
                    foreach ($posts as $po) { if (!empty($po['is_main'])) { $did = (int)$po['department_id']; break; } }
                }
                if (!$did) $did = (int)($posts[0]['department_id'] ?? 0);
            }
            if (!$did) $did = (int)($people[$uid]['dept_id'] ?? 0);   // 沒有異動紀錄＝用現況
            $out = ['id' => $did,
                    'name' => $deptMeta[$did]['name'] ?? ($people[$uid]['dept_name'] ?? '（未設部門）'),
                    'sort' => $deptMeta[$did]['sort'] ?? 999];
            if ($out['name'] === '') $out['name'] = '（未設部門）';
            $asofCache[$key] = $out;
            return $out;
        };

        // 部門篩選：用「請假當時的部門」判定，不是現在掛在哪個部門
        if ($scopeDeptIds) {
            $rows = array_values(array_filter($rows, function ($r) use ($deptAt, $scopeDeptIds) {
                return in_array($deptAt((int)$r['employee_id'], (string)$r['sd'])['id'], $scopeDeptIds, true);
            }));
        }

        // ── 有資料的年度 ──
        $years = [];
        foreach ($rows as $r) { $y = (int)$r['y']; if ($y && !in_array($y, $years, true)) $years[] = $y; }
        rsort($years);
        $curY = (int)date('Y');
        if (!in_array($curY, $years, true)) { $years[] = $curY; rsort($years); }

        $isAll = ($year === 'all' || $year === 'ALL');
        $selY  = $isAll ? null : (int)$year;
        $inSel = function ($r) use ($isAll, $selY) { return $isAll || (int)$r['y'] === $selY; };

        // ── 彙總容器 ──
        $byMonth = [];   // 1..12
        for ($m = 1; $m <= 12; $m++) $byMonth[$m] = ['month' => $m, 'total_days' => 0.0, 'total_hours' => 0.0,
                                                     'req_count' => 0, 'by_type' => []];
        $byType = [];    // type_id => [...]
        $byYear = [];    // year => [...]
        $trend  = [];    // 'Y-m' => [...]
        $byDept = [];    // dept_id => [...]
        $byPerson = [];  // user_id => [...]
        $selPeople = []; // 選定年度內有請假的人（算人次／平均）
        $selReq = 0; $selDays = 0.0; $selHours = 0.0;

        foreach ($rows as $r) {
            $y = (int)$r['y']; $m = (int)$r['m'];
            $tid = (int)$r['leave_type_id']; $uid = (int)$r['employee_id'];
            $d = (float)$r['dys']; $h = (float)$r['hrs'];

            // 年報（不受年度篩選影響，這樣才叫「跨年度比較」）
            if (!isset($byYear[$y])) $byYear[$y] = ['year' => $y, 'total_days' => 0.0, 'total_hours' => 0.0,
                                                    'req_count' => 0, 'by_type' => []];
            $byYear[$y]['total_days'] += $d; $byYear[$y]['total_hours'] += $h; $byYear[$y]['req_count']++;
            $byYear[$y]['by_type'][$tid] = ($byYear[$y]['by_type'][$tid] ?? 0) + $d;

            // 趨勢（逐月，跨年度連續；同樣不受年度篩選影響）
            $ym = sprintf('%04d-%02d', $y, $m);
            if (!isset($trend[$ym])) $trend[$ym] = ['ym' => $ym, 'total_days' => 0.0, 'total_hours' => 0.0,
                                                    'req_count' => 0, 'by_type' => []];
            $trend[$ym]['total_days'] += $d; $trend[$ym]['total_hours'] += $h; $trend[$ym]['req_count']++;
            $trend[$ym]['by_type'][$tid] = ($trend[$ym]['by_type'][$tid] ?? 0) + $d;

            if (!$inSel($r)) continue;

            // 以下皆為「選定年度」的切法
            $selReq++; $selDays += $d; $selHours += $h;
            $selPeople[$uid] = true;

            if ($m >= 1 && $m <= 12) {
                $byMonth[$m]['total_days'] += $d; $byMonth[$m]['total_hours'] += $h; $byMonth[$m]['req_count']++;
                $byMonth[$m]['by_type'][$tid] = ($byMonth[$m]['by_type'][$tid] ?? 0) + $d;
            }

            if (!isset($byType[$tid])) $byType[$tid] = ['leave_type_id' => $tid,
                'leave_name' => $types[$tid]['leave_name'] ?? ('#' . $tid),
                'color' => $types[$tid]['color'] ?? '#B06F27',
                'text_color' => $types[$tid]['text_color'] ?? '#FFFFFF',
                'days' => 0.0, 'hours' => 0.0, 'req_count' => 0, '_p' => []];
            $byType[$tid]['days'] += $d; $byType[$tid]['hours'] += $h; $byType[$tid]['req_count']++;
            $byType[$tid]['_p'][$uid] = true;

            $pi = $people[$uid] ?? null;
            // 部門一律用「這張假單起日當時」的部門，不是這個人現在掛在哪裡
            $da = $deptAt($uid, (string)$r['sd']);
            $did = (int)$da['id'];
            $dname = $da['name'];
            if (!isset($byDept[$did])) $byDept[$did] = ['dept_id' => $did, 'dept_name' => $dname,
                'dept_sort' => (int)$da['sort'],
                'days' => 0.0, 'hours' => 0.0, 'req_count' => 0, '_p' => []];
            $byDept[$did]['days'] += $d; $byDept[$did]['hours'] += $h; $byDept[$did]['req_count']++;
            $byDept[$did]['_p'][$uid] = true;

            if (!isset($byPerson[$uid])) $byPerson[$uid] = [
                'user_id' => $uid,
                'name' => $pi ? $pi['name'] : ('#' . $uid),
                'dept_id' => $did, 'dept_name' => $dname, '_depts' => [],
                'position_name' => $pi ? $pi['position_name'] : '',
                'position_sort' => $pi ? (int)$pi['position_sort'] : 999,
                'state' => $pi ? (int)$pi['state'] : 0,
                'state_label' => $pi ? $pi['state_label'] : '',
                'left_company' => $pi ? ((int)$pi['state'] === 0 ? 1 : 0) : 0,
                'days' => 0.0, 'hours' => 0.0, 'req_count' => 0, 'by_type' => []];
            $byPerson[$uid]['days'] += $d; $byPerson[$uid]['hours'] += $h; $byPerson[$uid]['req_count']++;
            $byPerson[$uid]['by_type'][$tid] = ($byPerson[$uid]['by_type'][$tid] ?? 0) + $d;
            // 期間內待過的部門（依起日排序，用來顯示「業務課→生產3廠」）
            $byPerson[$uid]['_depts'][(string)$r['sd']] = $dname;
        }

        // ── 收尾整形 ──
        $rd = function ($v) { return round((float)$v, 2); };
        foreach ($byMonth as &$mm) { $mm['total_days'] = $rd($mm['total_days']); $mm['total_hours'] = $rd($mm['total_hours']); }
        unset($mm);

        $byTypeOut = [];
        foreach ($byType as $t) {
            $t['people'] = count($t['_p']); unset($t['_p']);
            $t['days'] = $rd($t['days']); $t['hours'] = $rd($t['hours']);
            $byTypeOut[] = $t;
        }
        usort($byTypeOut, fn($a, $b) => $b['days'] <=> $a['days']);

        $byDeptOut = [];
        foreach ($byDept as $d0) {
            $d0['people'] = count($d0['_p']); unset($d0['_p']);
            $d0['days'] = $rd($d0['days']); $d0['hours'] = $rd($d0['hours']);
            $d0['avg_days'] = $d0['people'] > 0 ? $rd($d0['days'] / $d0['people']) : 0;
            $byDeptOut[] = $d0;
        }
        usort($byDeptOut, fn($a, $b) => $b['days'] <=> $a['days']);

        $byPersonOut = array_values($byPerson);
        foreach ($byPersonOut as &$p) {
            $p['days'] = $rd($p['days']); $p['hours'] = $rd($p['hours']);
            /* 這段期間調過部門的人：把待過的部門依時間序串起來（「業務課→生產3廠」），
               一個人在人員明細只有一列，只印其中一個部門會讓人以為我們算錯邊。 */
            $ds = $p['_depts'] ?? [];
            ksort($ds);
            $seq = [];
            foreach ($ds as $n) { if (!$seq || end($seq) !== $n) $seq[] = $n; }
            $p['dept_name'] = $seq ? implode('→', $seq) : $p['dept_name'];
            $p['dept_changed'] = count($seq) > 1 ? 1 : 0;
            unset($p['_depts']);
        }
        unset($p);
        usort($byPersonOut, fn($a, $b) => $b['days'] <=> $a['days']);

        krsort($byYear);
        $byYearOut = array_values($byYear);
        foreach ($byYearOut as &$yy) { $yy['total_days'] = $rd($yy['total_days']); $yy['total_hours'] = $rd($yy['total_hours']); }
        unset($yy);

        // 趨勢補零：中間沒人請假的月份也要有點，折線才不會把 3 月直接連到 7 月
        ksort($trend);
        $trendOut = [];
        if ($trend) {
            $keys = array_keys($trend);
            $cur = $keys[0]; $last = end($keys); $guard = 0;
            while ($cur <= $last && $guard++ < 600) {
                $trendOut[] = $trend[$cur] ?? ['ym' => $cur, 'total_days' => 0.0, 'total_hours' => 0.0,
                                               'req_count' => 0, 'by_type' => []];
                $cur = date('Y-m', strtotime($cur . '-01 +1 month'));
            }
            foreach ($trendOut as &$tt) { $tt['total_days'] = $rd($tt['total_days']); $tt['total_hours'] = $rd($tt['total_hours']); }
            unset($tt);
        }

        $peopleCnt = count($selPeople);
        $top = $byTypeOut[0] ?? null;
        $kpi = [
            'total_days'   => $rd($selDays),
            'total_hours'  => $rd($selHours),
            'req_count'    => $selReq,
            'people_count' => $peopleCnt,
            'avg_days'     => $peopleCnt > 0 ? $rd($selDays / $peopleCnt) : 0,
            'top_type'     => $top ? $top['leave_name'] : '',
            'top_type_days' => $top ? $top['days'] : 0,
            'busiest_month' => 0, 'busiest_month_days' => 0,
        ];
        foreach ($byMonth as $mm2) {
            if ($mm2['total_days'] > $kpi['busiest_month_days']) {
                $kpi['busiest_month'] = $mm2['month']; $kpi['busiest_month_days'] = $mm2['total_days'];
            }
        }

        // 目前部門篩選實際涵蓋哪些部門（畫面上要寫出來，不然使用者看不出「含下轄」把範圍放到多大）
        $scopeDeptNames = [];
        if ($scopeDeptIds) {
            try {
                $sn = $db->query("SELECT name FROM department WHERE id IN (" . implode(',', array_map('intval', $scopeDeptIds)) . ")
                                  ORDER BY COALESCE(sort_order,999), id")->fetchAll(PDO::FETCH_COLUMN);
                $scopeDeptNames = array_map('strval', $sn);
            } catch (Throwable $e) {}
        }

        return [
            'year'        => $isAll ? 'all' : $selY,
            'years'       => $years,
            'types'       => array_values($types),
            'with_sub'    => $withSub ? 1 : 0,
            'scope_depts' => $scopeDeptNames,
            'kpi'       => $kpi,
            'by_month'  => array_values($byMonth),
            'by_type'   => $byTypeOut,
            'by_year'   => $byYearOut,
            'trend'     => $trendOut,
            'by_dept'   => $byDeptOut,
            'by_person' => $byPersonOut,
        ];
    }
}

if (!function_exists('eg_leave_stats_empty')) {
    /** 可視範圍為空時的空結果（結構與 eg_leave_stats 一致，前端不必特判） */
    function eg_leave_stats_empty($year, array $types): array {
        $byMonth = [];
        for ($m = 1; $m <= 12; $m++) $byMonth[] = ['month' => $m, 'total_days' => 0, 'total_hours' => 0,
                                                   'req_count' => 0, 'by_type' => []];
        return [
            'year' => $year, 'years' => [(int)date('Y')], 'types' => array_values($types),
            'with_sub' => 1, 'scope_depts' => [],
            'kpi' => ['total_days' => 0, 'total_hours' => 0, 'req_count' => 0, 'people_count' => 0,
                      'avg_days' => 0, 'top_type' => '', 'top_type_days' => 0,
                      'busiest_month' => 0, 'busiest_month_days' => 0],
            'by_month' => $byMonth, 'by_type' => [], 'by_year' => [], 'trend' => [],
            'by_dept' => [], 'by_person' => [],
        ];
    }
}
