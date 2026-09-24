<?php
/**
 * 單位主管解析共用庫（2026-09-16 建立）——「審核層級規範」`ai-rules/24-審核層級規範.md` 的**唯一實作點**
 *
 * 要解決什麼（使用者 2026-09-16 回報，以文件制修申請單為例）：
 *   品管組 組長 蔣騏竹 自己開單時，「單位主管」欄蓋的是他自己的章——但組織圖上他上面還有品管課課長，
 *   單位主管本來就應該往上追溯到上級單位的主管才對。
 *
 * 規則（使用者定調，全站所有「單位主管」欄位／關卡一體適用）：
 *   1. 單位主管＝**申請人所屬單位的最高主管**（該單位職級最高的那一位）。
 *   2. 申請人自己就是該單位最高主管時 → **往上一層單位**（department.parent_id）找該單位的最高主管，
 *      逐層往上。
 *   3. **天花板＝課級**（department.level <= EG_UNIT_SUP_TOP_LEVEL，本系統 3＝部門／課室）：
 *      直線單位最高到「課」，再上去（總經理室／董事長室）是所有單位的共同上級，不是誰的單位主管。
 *      所以**課級單位內的最高主管就是申請人本人時，單位主管從缺、由申請人自己簽**（vacant=true、is_top=true），
 *      不可以再往上追到廠長／總經理。
 *
 * 為什麼收斂成一支：這個判定原本散在 doc_apply / eng_change / business_trip / delegate_lib / form_signer
 * 各寫一份，而且各自有各自的殘缺——最典型的是「往上一層找主管」清一色只認
 * `department_position.primary_user_id`（指定負責人），而**那個欄位全站一筆都沒有設定**，
 * 所以那段程式從來沒有成功過，一律回 null、再各自退回本人或最高決策者（＝使用者看到的「自己簽自己」）。
 * 要改判定規則請只改這支，不要在別處再刻一份（鐵律4）。
 *
 * 日期：有業務日期的單據一律把 $asof 傳進來（ai-rules/22），內部走 people_lib 的 eg_people_posts_asof()
 * 回推「當時」的部門／職稱／在職狀態；$asof 空字串＝用現況（eg_people_posts()）。
 *
 * 代理（本人請假由代理人代簽）不在這一層處理：解析出「該誰簽」之後，呼叫端一律再過
 * delegate_lib 的 eg_resolve_signer()（ai-rules/11）。
 */

require_once __DIR__ . '/people_lib.php';

if (!defined('EG_UNIT_SUP_TOP_LEVEL')) {
    // department.level：1=董事長室 2=總經理室 3=部門/課室 4=組別 5=小組（見 department.level 欄位註解）
    // 「單位主管」最多只追溯到 3（課級）。
    define('EG_UNIT_SUP_TOP_LEVEL', 3);
}

if (!function_exists('eg_unit_dept_map')) {
    /** 全部部門：id => ['id','name','level','parent_id','sort']（同一次請求內快取） */
    function eg_unit_dept_map(PDO $db): array {
        static $cache = null;
        if ($cache !== null) return $cache;
        $cache = [];
        try {
            $rows = $db->query("SELECT id, name, COALESCE(level,9) AS lv, parent_id, COALESCE(sort_order,999) AS s
                                FROM department")->fetchAll(PDO::FETCH_ASSOC);
            foreach ($rows as $d) {
                $cache[(int)$d['id']] = [
                    'id'        => (int)$d['id'],
                    'name'      => (string)$d['name'],
                    'level'     => (int)$d['lv'],
                    'parent_id' => $d['parent_id'] !== null ? (int)$d['parent_id'] : null,
                    'sort'      => (int)$d['s'],
                ];
            }
        } catch (Throwable $e) { $cache = []; }
        return $cache;
    }
}

if (!function_exists('eg_unit_position_levels')) {
    /** position_id => level（數字小＝職級高）。沒設 level 的職稱＝不是主管，不會出現在這份 map */
    function eg_unit_position_levels(PDO $db): array {
        static $cache = null;
        if ($cache !== null) return $cache;
        $cache = [];
        try {
            $rows = $db->query("SELECT position_id, level FROM position_level WHERE level IS NOT NULL")
                       ->fetchAll(PDO::FETCH_ASSOC);
            foreach ($rows as $r) $cache[(int)$r['position_id']] = (int)$r['level'];
        } catch (Throwable $e) { $cache = []; }
        return $cache;
    }
}

if (!function_exists('eg_unit_posts')) {
    /**
     * 逐職務清單（含兼任）。$asof 有值＝回推當時，空＝現況。
     * 一律走 people_lib（人員列表鐵則），不自己拼人員 SQL。同一次請求內依日期快取。
     * **只收 state=1 在職者**：留職停薪／育嬰留停的主管不該被自動解析成簽核人（他人不在，
     * 單子會卡住），他的下屬依規則往上一層單位找即可。這與舊版 eg_resolve_supervisor 的 `u.state = 1` 同口徑。
     */
    function eg_unit_posts(PDO $db, string $asof = ''): array {
        static $cache = [];
        $key = ($asof !== '' && preg_match('/^\d{4}-\d{2}-\d{2}$/', $asof)) ? $asof : '_now';
        if (isset($cache[$key])) return $cache[$key];
        try {
            $rows = $key === '_now' ? eg_people_posts($db, ['states' => [1]])
                                    : eg_people_posts_asof($db, ['states' => [1]], $asof);
        } catch (Throwable $e) { $rows = []; }
        return $cache[$key] = $rows;
    }
}

if (!function_exists('eg_unit_user_level')) {
    /**
     * 某人在某單位的職級（數字小＝高）。兼任同一單位多個職務時取最高的那一個。
     * 該單位查不到他的職務時回 99（＝非該單位主管），不拿別單位的職級來比。
     */
    function eg_unit_user_level(PDO $db, int $userId, int $deptId, string $asof = ''): int {
        if ($userId <= 0 || $deptId <= 0) return 99;
        $lvMap = eg_unit_position_levels($db);
        $best  = 99;
        foreach (eg_unit_posts($db, $asof) as $p) {
            if ((int)$p['id'] !== $userId || (int)($p['dept_id'] ?? 0) !== $deptId) continue;
            $l = $lvMap[(int)($p['position_id'] ?? 0)] ?? 99;
            if ($l < $best) $best = $l;
        }
        return $best;
    }
}

if (!function_exists('eg_unit_user_dept')) {
    /** 某人（當時的）主職單位 id；沒有主職就取第一個兼任單位。查不到回 0 */
    function eg_unit_user_dept(PDO $db, int $userId, string $asof = ''): int {
        if ($userId <= 0) return 0;
        $fallback = 0;
        foreach (eg_unit_posts($db, $asof) as $p) {
            if ((int)$p['id'] !== $userId) continue;
            $d = (int)($p['dept_id'] ?? 0);
            if (!$d) continue;
            if ((int)($p['is_main'] ?? 0) === 1) return $d;
            if (!$fallback) $fallback = $d;
        }
        return $fallback;
    }
}

if (!function_exists('eg_unit_dept_primary_users')) {
    /** 部門×職稱的「指定負責人」：deptId => [position_id => user_id]（同職級多人時優先採計） */
    function eg_unit_dept_primary_users(PDO $db): array {
        static $cache = null;
        if ($cache !== null) return $cache;
        $cache = [];
        try {
            $rows = $db->query("SELECT department_id, position_id, primary_user_id FROM department_position
                                WHERE primary_user_id IS NOT NULL")->fetchAll(PDO::FETCH_ASSOC);
            foreach ($rows as $r) $cache[(int)$r['department_id']][(int)$r['position_id']] = (int)$r['primary_user_id'];
        } catch (Throwable $e) { $cache = []; }
        return $cache;
    }
}

if (!function_exists('eg_unit_dept_head')) {
    /**
     * 某單位的**最高主管**（只看該單位本身，不含子單位）。
     * 排序：職級 level 升冪 → 職稱 sort_order 升冪（課長先於副課長、組長先於副組長）
     *      → 該部門×職稱的指定負責人優先 → 主職優先 → id。
     * $excludeUid 傳入時排除該人。
     * 回 ['id','name','dept_id','dept_name','position_id','position_name','level'] 或 null。
     */
    function eg_unit_dept_head(PDO $db, int $deptId, string $asof = '', int $excludeUid = 0): ?array {
        if ($deptId <= 0) return null;
        $lvMap   = eg_unit_position_levels($db);
        $primary = eg_unit_dept_primary_users($db)[$deptId] ?? [];
        $best = null; $bestKey = null;
        foreach (eg_unit_posts($db, $asof) as $p) {
            if ((int)($p['dept_id'] ?? 0) !== $deptId) continue;
            if ($excludeUid > 0 && (int)$p['id'] === $excludeUid) continue;
            $pid = (int)($p['position_id'] ?? 0);
            if (!isset($lvMap[$pid])) continue;                 // 沒有職級＝不是主管
            $key = [$lvMap[$pid], (int)($p['position_sort'] ?? 999),
                    ((isset($primary[$pid]) && $primary[$pid] === (int)$p['id']) ? 0 : 1),
                    ((int)($p['is_main'] ?? 0) === 1 ? 0 : 1), (int)$p['id']];
            if ($bestKey === null || $key < $bestKey) {
                $bestKey = $key;
                $best = ['id'            => (int)$p['id'],
                         'name'          => (string)$p['user_cname'],
                         'dept_id'       => $deptId,
                         'dept_name'     => (string)($p['dept_name'] ?? ''),
                         'position_id'   => $pid,
                         'position_name' => (string)($p['position_name'] ?? ''),
                         'level'         => $lvMap[$pid]];
            }
        }
        return $best;
    }
}

if (!function_exists('eg_unit_supervisor')) {
    /**
     * 「單位主管」唯一解析入口（規則見本檔頂端與 ai-rules/24）。
     *
     * @param int    $userId 申請人／填表人 user id（0＝不指定人，等同單純取該單位最高主管）
     * @param ?int   $deptId 以哪個單位的身分申請（兼任者一定要傳，否則用他當時的主職單位）
     * @param string $asof   單據業務日期 YYYY-MM-DD（空＝現況）
     * @param array  $opt    ['top_level'=>int 追溯天花板，預設課級 EG_UNIT_SUP_TOP_LEVEL]
     * @return array [
     *   'id'=>?int,'name'=>string,'dept_id'=>?int,'dept_name'=>string,'position_name'=>string,'level'=>?int,
     *   'vacant'=>bool,   // true＝依規則從缺（課級單位內的最高主管就是申請人本人，或整條路徑都沒有主管）
     *   'is_top'=>bool,   // true＝申請人本人就是停在那一層單位的最高主管（呼叫端可改蓋申請人的章）
     *   'base_dept_id'=>?int,'stop_dept_id'=>?int,'stop_dept_name'=>string,'hops'=>int,'reason'=>string ]
     */
    function eg_unit_supervisor(PDO $db, int $userId, ?int $deptId = null, string $asof = '', array $opt = []): array {
        $topLevel = (int)($opt['top_level'] ?? EG_UNIT_SUP_TOP_LEVEL);
        $depts    = eg_unit_dept_map($db);
        $start    = $deptId ? (int)$deptId : eg_unit_user_dept($db, $userId, $asof);

        $out = ['id' => null, 'name' => '', 'dept_id' => null, 'dept_name' => '', 'position_name' => '',
                'level' => null, 'vacant' => true, 'is_top' => false, 'base_dept_id' => $start ?: null,
                'stop_dept_id' => null, 'stop_dept_name' => '', 'hops' => 0, 'reason' => ''];

        if (!$start || !isset($depts[$start])) {
            $out['reason'] = '查無申請單位，無法解析單位主管';
            return $out;
        }

        $cursor = $start;
        for ($hop = 0; $hop < 6; $hop++) {
            $head   = eg_unit_dept_head($db, $cursor, $asof, 0);            // 含本人的最高主管
            $iAmTop = ($userId > 0 && $head && (int)$head['id'] === $userId);
            if ($head && !$iAmTop) {
                // 本人不是這個單位的最高主管 → 由這個單位的最高主管當單位主管
                return ['id' => $head['id'], 'name' => $head['name'],
                        'dept_id' => $head['dept_id'], 'dept_name' => $head['dept_name'],
                        'position_name' => $head['position_name'], 'level' => $head['level'],
                        'vacant' => false, 'is_top' => false,
                        'base_dept_id' => $start, 'stop_dept_id' => $cursor,
                        'stop_dept_name' => $depts[$cursor]['name'] ?? '', 'hops' => $hop,
                        'reason' => $hop === 0 ? '本單位最高主管'
                                               : '申請人為原單位最高主管，往上一層單位（' . ($depts[$cursor]['name'] ?? '') . '）取得'];
            }
            // 本人就是這個單位的最高主管，或這個單位根本沒有主管 → 看能不能再往上一層
            $curLevel = $depts[$cursor]['level'] ?? 9;
            $parent   = $depts[$cursor]['parent_id'] ?? null;
            $out['hops']           = $hop;
            $out['is_top']         = (bool)$iAmTop;
            $out['stop_dept_id']   = $cursor;
            $out['stop_dept_name'] = $depts[$cursor]['name'] ?? '';
            if ($curLevel <= $topLevel) {
                $out['reason'] = $iAmTop
                    ? '申請人即該課級單位的最高主管，單位主管從缺（由申請人簽章）'
                    : '該課級單位查無主管，且依規則不往課級以上追溯';
                return $out;
            }
            if (!$parent || !isset($depts[$parent]) || ($depts[$parent]['level'] ?? 9) < $topLevel) {
                $out['reason'] = '已達課級上限，不往課級以上的共同上級（總經理室／董事長室）追溯';
                return $out;
            }
            $cursor = $parent;
        }
        $out['reason'] = '單位層級過深（超過 6 層），停止追溯';
        return $out;
    }
}

if (!function_exists('eg_unit_supervisor_id')) {
    /** 便利版：只要 user id，從缺回 null（呼叫端自行決定留白或退回申請人） */
    function eg_unit_supervisor_id(PDO $db, int $userId, ?int $deptId = null, string $asof = '', array $opt = []): ?int {
        $r = eg_unit_supervisor($db, $userId, $deptId, $asof, $opt);
        return $r['id'] ?? null;
    }
}

if (!function_exists('eg_unit_dept_candidates')) {
    /**
     * 某單位「所有」夠格當主管的候選人，依 eg_unit_dept_head() 同一套排序由高到低整份列出
     * （eg_unit_dept_head() 只回傳第一名；`eg_unit_supervisor_available()` 需要在第一名當天不在時
     * 換下一位，所以要完整名單）。**不改 eg_unit_dept_head() 的既有簽章**，避免影響它原本的呼叫端。
     */
    function eg_unit_dept_candidates(PDO $db, int $deptId, string $asof = ''): array {
        if ($deptId <= 0) return [];
        $lvMap   = eg_unit_position_levels($db);
        $primary = eg_unit_dept_primary_users($db)[$deptId] ?? [];
        $rows = [];
        foreach (eg_unit_posts($db, $asof) as $p) {
            if ((int)($p['dept_id'] ?? 0) !== $deptId) continue;
            $pid = (int)($p['position_id'] ?? 0);
            if (!isset($lvMap[$pid])) continue;   // 沒有職級＝不是主管
            $rows[] = [
                'key' => [$lvMap[$pid], (int)($p['position_sort'] ?? 999),
                          ((isset($primary[$pid]) && $primary[$pid] === (int)$p['id']) ? 0 : 1),
                          ((int)($p['is_main'] ?? 0) === 1 ? 0 : 1), (int)$p['id']],
                'cand' => ['id' => (int)$p['id'], 'name' => (string)$p['user_cname'],
                           'dept_id' => $deptId, 'dept_name' => (string)($p['dept_name'] ?? ''),
                           'position_id' => $pid, 'position_name' => (string)($p['position_name'] ?? ''),
                           'level' => $lvMap[$pid]],
            ];
        }
        usort($rows, function ($a, $b) { return $a['key'] <=> $b['key']; });
        return array_map(function ($r) { return $r['cand']; }, $rows);
    }
}

if (!function_exists('eg_unit_supervisor_available')) {
    /**
     * 「現場主管／當天實際可用的最高層級主管」解析——與 `eg_unit_supervisor()`（ai-rules/24）
     * 是**不同用途的變體**，兩者刻意分開：
     *   `eg_unit_supervisor()` 是給簽核關卡用的，卡在課級（避免課級以上共同上級同一張單簽兩格）；
     *   這支是給「這件事需要立刻有人承接、找不到人也不能什麼都不做」用的（例：報工NG要自動開立
     *   品質異常單），所以**不卡課級**，一路往上爬到最頂層部門，且每一階都先確認候選人
     *   「當天真的在」（`da_user_on_leave_asof()`，doc_apply_lib.php 既有的 asof 版請假判定，
     *   不是 delegate_lib 那支只認「今天」的版本）；不在就換同單位下一位，同單位沒人再往上一層；
     *   全部爬完仍找不到人，最後退回全站「最高決策者」（org_role_setting 的 top_approver，
     *   與 `qab_gm_person()` 解析總經理裁示同一個函式 `eg_org_user()`）。
     * 若之後別的模組也需要「找一個當天真的在、逐層往上、最後保底一定找得到人」，一律呼叫這支
     * （鐵律4：不要再各自寫一份請假判定＋爬部門樹）。
     *
     * @param int    $userId 觸發這件事的人（例：報工人員）；本人不會被選為自己的承接者
     * @param ?int   $deptId 以哪個單位起算（缺省＝該人當天的主職單位）
     * @param string $onDate 業務日期 YYYY-MM-DD（必填，不是「今天」——是這件事實際發生的那一天）
     * @return array ['id'=>?int,'name'=>string,'dept_id'=>?int,'dept_name'=>string,'position_name'=>string,
     *                'source'=>'dept'|'top_approver'|'', 'trail'=>string[]（依序被跳過的人與原因）]
     *                連 top_approver 都沒綁定時 id=null（呼叫端自行決定要不要擋下）。
     */
    function eg_unit_supervisor_available(PDO $db, int $userId, ?int $deptId, string $onDate): array {
        require_once __DIR__ . '/doc_apply_lib.php';
        require_once __DIR__ . '/org_role_lib.php';
        $depts  = eg_unit_dept_map($db);
        $cursor = $deptId ? (int)$deptId : eg_unit_user_dept($db, $userId, $onDate);
        $trail  = [];
        $hop    = 0;
        while ($cursor && isset($depts[$cursor]) && $hop < 10) {
            foreach (eg_unit_dept_candidates($db, $cursor, $onDate) as $cand) {
                if ($userId > 0 && $cand['id'] === $userId) continue; // 不開自己觸發的單
                if (function_exists('da_user_on_leave_asof') && da_user_on_leave_asof($db, $cand['id'], $onDate)) {
                    $trail[] = $cand['name'] . '（' . $onDate . ' 不在，略過）';
                    continue;
                }
                return ['id' => $cand['id'], 'name' => $cand['name'], 'dept_id' => $cand['dept_id'],
                        'dept_name' => $cand['dept_name'], 'position_name' => $cand['position_name'],
                        'source' => 'dept', 'trail' => $trail];
            }
            $cursor = $depts[$cursor]['parent_id'] ?? null;
            $hop++;
        }
        if (function_exists('eg_org_user')) {
            $top = eg_org_user($db, 'top_approver');
            if ($top && (int)($top['id'] ?? 0) > 0) {
                return ['id' => (int)$top['id'], 'name' => (string)($top['user_cname'] ?? ''),
                        'dept_id' => null, 'dept_name' => '', 'position_name' => '最高決策者',
                        'source' => 'top_approver', 'trail' => $trail];
            }
        }
        return ['id' => null, 'name' => '', 'dept_id' => null, 'dept_name' => '', 'position_name' => '',
                'source' => '', 'trail' => $trail];
    }
}
