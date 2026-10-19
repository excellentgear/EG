<?php
/**
 * leave_event_stats_lib.php — 行事曆「非請假」事件統計的唯一實作點
 *
 * 補請假統計看不到的那一半：國定假日、颱風假、補班/調班、調配休假（日曆型，整家公司的日子）
 * 以及會議、內訓、外訓、客戶來訪、公出、客戶/廠商（活動型，有參與人員）。
 *
 * 原則：
 *  1. 類別一律讀 event_category，不寫死名稱。分型依 event_category.day_type：
 *     's'／'m'（休息日/補班日）＝日曆型；其餘＝活動型。
 *  2. 排除「休假」（那是請假統計）、「通知」（使用者指定不統計）、「請假申請中」（暫態）。
 *     排除靠類別名稱關鍵字清單 eg_evstat_excluded_names()，集中一處改。
 *  3. 一律後端全量計算；年度/月份以事件「起日」歸屬（與請假統計同口徑）。
 *  4. 日曆型事件沒有參與人員（evenement_actor 為空），是全公司的日子，
 *     部門/人員篩選時照樣列出（只篩有參與人員的活動型事件）。
 *  5. 顏色用 eg_leave_palette() 暖色系固定色盤，不沿用 event_category.color（那是行事曆自己的冷暖混雜色）。
 */
require_once __DIR__ . '/leave_stats_lib.php';

if (!function_exists('eg_evstat_excluded_names')) {
    function eg_evstat_excluded_names(): array {
        return ['休假', '通知', '請假申請中'];
    }
}

if (!function_exists('eg_event_stats')) {
    /**
     * @param array $opt year(int|'all') dept_id user_id with_sub scope_user_ids(null=不限)
     */
    function eg_event_stats(PDO $db, array $opt = []): array {
        $year    = $opt['year'] ?? (int)date('Y');
        $deptId  = (int)($opt['dept_id'] ?? 0);
        $userId  = (int)($opt['user_id'] ?? 0);
        $scopeIds = $opt['scope_user_ids'] ?? null;
        $withSub = !isset($opt['with_sub']) || !empty($opt['with_sub']);

        // ── 類別 ──
        $excl = eg_evstat_excluded_names();
        $pal  = eg_leave_palette();
        $cats = []; $i = 0;
        foreach ($db->query("SELECT id, category_name, day_type FROM event_category ORDER BY id")->fetchAll(PDO::FETCH_ASSOC) as $c) {
            if (in_array($c['category_name'], $excl, true)) continue;
            $cid = (int)$c['id'];
            $cats[$cid] = [
                'id' => $cid, 'name' => (string)$c['category_name'],
                'kind' => in_array($c['day_type'], ['s', 'm'], true) ? 'cal' : 'act',
                'color' => $pal[$i % count($pal)]['bg'], 'text_color' => $pal[$i % count($pal)]['tx'],
                'count' => 0, 'days' => 0.0, 'hours' => 0.0, 'people_times' => 0,
                'by_month' => array_fill(0, 12, 0),
            ];
            $i++;
        }
        if (!$cats) return eg_event_stats_empty($year);

        // ── 可視/篩選的人員集合（null＝不限）──
        $allowed = null;
        if (is_array($scopeIds)) $allowed = array_map('intval', $scopeIds);
        if ($deptId > 0) {
            $dids = [$deptId];
            if ($withSub) {
                require_once __DIR__ . '/org_role_lib.php';
                $dids = eg_dept_subtree_ids($db, $deptId) ?: [$deptId];
            }
            $in = implode(',', array_map('intval', $dids));
            $inDept = array_map('intval', $db->query(
                "SELECT DISTINCT user_id FROM user_department_position_map WHERE department_id IN ($in)")->fetchAll(PDO::FETCH_COLUMN));
            $allowed = $allowed === null ? $inDept : array_values(array_intersect($allowed, $inDept));
        }
        if ($userId > 0) {
            $allowed = ($allowed === null || in_array($userId, $allowed, true)) ? [$userId] : [];
        }

        // ── 事件 ──
        $catIn = implode(',', array_keys($cats));
        $rows = $db->query("SELECT id, title, category_id, `start` AS st, `end` AS en, allday
                            FROM evenement WHERE category_id IN ($catIn) ORDER BY `start`")->fetchAll(PDO::FETCH_ASSOC);
        $actors = [];
        foreach ($db->query("SELECT a.event_id, a.user_id FROM evenement_actor a
                             JOIN evenement e ON e.id=a.event_id WHERE e.category_id IN ($catIn)")->fetchAll(PDO::FETCH_ASSOC) as $a) {
            $actors[(int)$a['event_id']][] = (int)$a['user_id'];
        }
        $names = [];
        foreach ($db->query("SELECT id, user_cname FROM user")->fetchAll(PDO::FETCH_ASSOC) as $u) $names[(int)$u['id']] = (string)$u['user_cname'];

        $years = []; $events = []; $person = [];
        foreach ($rows as $r) {
            $y = (int)substr($r['st'], 0, 4);
            $years[$y] = 1;
            if ($year !== 'all' && $y !== (int)$year) continue;
            $eid = (int)$r['id']; $cid = (int)$r['category_id'];
            $act = $actors[$eid] ?? [];
            // 有參與人員的事件才受人員/部門篩選；日曆型（無人）一律保留
            if ($allowed !== null && $act) {
                $act = array_values(array_intersect($act, $allowed));
                if (!$act) continue;
            } elseif ($allowed !== null && !$act && $cats[$cid]['kind'] === 'act') {
                continue;   // 活動型沒有參與人員，無法判斷是否屬於該部門/人員，篩選時不計
            }
            // 天數/時數：全天（或跨日）事件＝日曆天數；同日有時間＝時數
            $s = strtotime($r['st']); $e = strtotime($r['en']) ?: $s;
            $sameDay = date('Y-m-d', $s) === date('Y-m-d', $e);
            if ((int)$r['allday'] === 1 || !$sameDay) {
                $days = (float)(floor((strtotime(date('Y-m-d', $e)) - strtotime(date('Y-m-d', $s))) / 86400) + 1);
                $hours = 0.0;
            } else {
                $days = 0.0; $hours = round(max(0, $e - $s) / 3600, 2);
            }
            $c =& $cats[$cid];
            $c['count']++; $c['days'] += $days; $c['hours'] += $hours;
            $c['people_times'] += count($act);
            $c['by_month'][(int)substr($r['st'], 5, 2) - 1]++;
            unset($c);
            foreach ($act as $u) {
                if (!isset($person[$u])) $person[$u] = ['user_id' => $u, 'name' => $names[$u] ?? ('#' . $u), 'by_cat' => [], 'count' => 0, 'hours' => 0.0, 'days' => 0.0];
                $person[$u]['by_cat'][$cid] = ($person[$u]['by_cat'][$cid] ?? 0) + 1;
                $person[$u]['count']++; $person[$u]['hours'] += $hours; $person[$u]['days'] += $days;
            }
            $events[] = ['id' => $eid, 'date' => substr($r['st'], 0, 10), 'end_date' => substr($r['en'], 0, 10),
                         'time' => ((int)$r['allday'] === 1) ? '全天' : (substr($r['st'], 11, 5) . '~' . substr($r['en'], 11, 5)),
                         'title' => (string)$r['title'], 'category_id' => $cid, 'category' => $cats[$cid]['name'],
                         'days' => $days, 'hours' => $hours, 'people' => count($act),
                         'names' => array_map(function ($u) use ($names) { return $names[$u] ?? ('#' . $u); }, array_slice($act, 0, 12))];
        }
        foreach ($cats as &$c) { $c['days'] = round($c['days'], 2); $c['hours'] = round($c['hours'], 2); }
        unset($c);
        $persons = array_values($person);
        foreach ($persons as &$p) { $p['hours'] = round($p['hours'], 2); }
        unset($p);
        usort($persons, function ($a, $b) { return $b['count'] <=> $a['count'] ?: $b['hours'] <=> $a['hours']; });
        usort($events, function ($a, $b) { return strcmp($b['date'], $a['date']); });
        $yl = array_keys($years); rsort($yl);
        if (!$yl) $yl = [(int)date('Y')];

        $act = array_filter($cats, function ($c) { return $c['kind'] === 'act'; });
        $cal = array_filter($cats, function ($c) { return $c['kind'] === 'cal'; });
        return [
            'year' => $year, 'years' => $yl,
            'cats' => array_values($cats),
            'kpi' => [
                'act_count' => array_sum(array_column($act, 'count')),
                'act_hours' => round(array_sum(array_column($act, 'hours')), 2),
                'act_days'  => round(array_sum(array_column($act, 'days')), 2),
                'act_people_times' => array_sum(array_column($act, 'people_times')),
                'cal_count' => array_sum(array_column($cal, 'count')),
                'cal_days'  => round(array_sum(array_column($cal, 'days')), 2),
            ],
            'by_person' => $persons,
            'events' => array_slice($events, 0, 600),
            'events_total' => count($events),
            'filtered' => $allowed !== null,
        ];
    }
}

if (!function_exists('eg_event_stats_empty')) {
    function eg_event_stats_empty($year): array {
        return ['year' => $year, 'years' => [(int)date('Y')], 'cats' => [], 'kpi' => ['act_count' => 0, 'act_hours' => 0, 'act_days' => 0,
                'act_people_times' => 0, 'cal_count' => 0, 'cal_days' => 0], 'by_person' => [], 'events' => [], 'events_total' => 0, 'filtered' => false];
    }
}
