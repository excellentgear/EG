<?php
/**
 * 人員列表共用庫（2026-07-30 建立）——「人員列表鐵則」的唯一實作點
 *
 * 鐵則（使用者 2026-07-30 定調，全站所有會列出人員的地方一體適用）：
 *   1. 只列未離職者：`user.state` 0(離職)／90(特殊帳號)／99(最高權限帳號，如超級管理員 id=1) 一律不列
 *      （2026-08-13 使用者明確要求擴大排除 99：這類帳號不是真人，不該出現在「選負責人/簽核對象」這種
 *      人員挑選清單裡；真的要管理這些帳號本身，走各自專屬頁面 `employee_management.php`/`user_permissions.php`，
 *      那兩頁本來就不經過 `eg_people_list()`，不受影響）。
 *   2. 留職停薪(2)／育嬰留停(3)／其他長期請假者仍要列出，但必須「標記假別＋請假期間」。
 *   3. 一律依職稱排序（position.sort_order），並顯示職稱。
 *   4. 若同一份列表會出現不同部門的人 → 必須連部門一起顯示（用 eg_people_multi_dept() 判斷）。
 *
 * 為什麼收斂成一支：離職判定欄位是 `user.state`（不是 user_status！），
 * 各頁自己寫 WHERE 遲早寫錯（已發生過：量測儀器校驗人員資格誤用 user_status<>90）。
 * 要列人員一律呼叫 eg_people_list()，不要自己拼 SQL。
 *
 * 狀態碼：1=在職 2=留職停薪 3=育嬰留停 0=離職 90=特殊帳號 99=最高權限帳號
 *        （對照 views/ADM/employee_management.php 與 src/common/user_active_lib.php）
 */

if (!defined('EG_PEOPLE_EXCLUDE_STATES')) {
    define('EG_PEOPLE_EXCLUDE_STATES', '0,90,99');   // 離職、特殊帳號、最高權限帳號：任何人員列表都不列
}
if (!defined('EG_LONG_LEAVE_MIN_DAYS')) {
    define('EG_LONG_LEAVE_MIN_DAYS', 15);          // 連續請假 ≥ 15 天視為長期請假（標記假別+期間）
}

if (!function_exists('eg_people_state_label')) {
    function eg_people_state_label($state): string {
        $map = [0=>'離職', 1=>'在職', 2=>'留職停薪', 3=>'育嬰留停', 90=>'特殊帳號', 99=>'在職'];
        $s = (int)$state;
        return $map[$s] ?? ('狀態' . $s);
    }
}

if (!function_exists('eg_people_long_leave_map')) {
    /**
     * 目前生效中的長期請假（已核准、期間涵蓋今天、天數 ≥ EG_LONG_LEAVE_MIN_DAYS）
     * @return array user_id => ['label'=>假別, 'start'=>'Y-m-d', 'end'=>'Y-m-d', 'days'=>float]
     */
    function eg_people_long_leave_map(PDO $db, array $userIds = []): array {
        $out = [];
        try {
            $sql = "SELECT lr.employee_id, lt.leave_name, lr.start_datetime, lr.end_datetime, lr.total_days
                    FROM leave_request lr
                    JOIN leave_type lt ON lt.id = lr.leave_type_id
                    WHERE lr.status='approved' AND lr.canceled_at IS NULL
                      AND lr.start_datetime <= NOW() AND lr.end_datetime >= NOW()
                      AND COALESCE(lr.total_days,0) >= " . (int)EG_LONG_LEAVE_MIN_DAYS;
            $ids = array_values(array_filter(array_map('intval', $userIds)));
            if ($ids) $sql .= " AND lr.employee_id IN (" . implode(',', $ids) . ")";
            $sql .= " ORDER BY lr.end_datetime DESC";
            foreach ($db->query($sql)->fetchAll(PDO::FETCH_ASSOC) as $r) {
                $uid = (int)$r['employee_id'];
                if (isset($out[$uid])) continue;                       // 取結束日最晚的那張
                $out[$uid] = ['label'=>(string)$r['leave_name'],
                              'start'=>substr((string)$r['start_datetime'], 0, 10),
                              'end'  =>substr((string)$r['end_datetime'], 0, 10),
                              'days' =>(float)$r['total_days']];
            }
        } catch (Throwable $e) { /* 請假表不存在或查詢失敗 → 不標記，不影響人員列表 */ }
        return $out;
    }
}

if (!function_exists('eg_people_list')) {
    /**
     * 人員列表（已套用上述四條鐵則）
     *
     * @param array $opt  dept_ids  只列這些部門（含指定的部門本身；要含子部門請自行展開後傳入）
     *                    user_ids  只列這些人
     *                    states    允許的 state（預設 [1,2,3]；EG_PEOPLE_EXCLUDE_STATES 永遠排除，
     *                    含 99 最高權限帳號——即使呼叫端明確傳 states 內含 99 也一樣會被濾掉）
     *                    keyword   姓名/帳號模糊搜尋
     *                    prefer_main 兼任者的顯示職稱改取「主要職務(is_main)」而不是職級最高的那筆
     *                                （預設 false＝維持職級優先）。只用在沒有部門情境的名單，見下方 $pick 註解。
     * @return array 每列：id, user_cname, user_uname, state, state_label, hire_date, gender(M/F/null), highest_education(代碼/null),
     *               position_id, position_name, position_sort, dept_id, dept_name, dept_sort, dept_ids(含兼任的所有部門id),
     *               on_leave(0/1), leave_label, leave_start, leave_end, leave_note, display
     *               排序：職稱 → 部門 → id
     */
    function eg_people_list(PDO $db, array $opt = []): array {
        $exclude = array_map('intval', explode(',', EG_PEOPLE_EXCLUDE_STATES));
        $states  = isset($opt['states']) && is_array($opt['states']) ? array_map('intval', $opt['states']) : [1,2,3];
        $states  = array_values(array_diff($states, $exclude));

        // asof_date：以「某個日期當時」為準判斷在不在職（ai-rules/22 的同一套精神）。
        // 有帶日期時：①該日之後才入職的人不列 ②該日已離職的人不列 ③該日還在職、之後才離職的人「要列」
        // （補歷史單據時才選得到當時的人）。離職日沒登錄的離職者一律不列——不知道他哪天走的，
        // 列出來會把早就離職的人混進近期名單（2026-08-26 使用者拍板）。
        $asof = isset($opt['asof_date']) ? trim((string)$opt['asof_date']) : '';
        if ($asof !== '' && !preg_match('/^[0-9]{4}-[0-9]{2}-[0-9]{2}$/', $asof)) $asof = '';
        if ($asof !== '') $states[] = 0;   // 只有帶 asof 時才把離職者納入候選，下面再依離職日逐一過濾
        $states = array_values(array_unique($states));
        if (!$states) return [];

        $deptIds = isset($opt['dept_ids']) && is_array($opt['dept_ids'])
                 ? array_values(array_filter(array_map('intval', $opt['dept_ids']))) : [];
        $userIds = isset($opt['user_ids']) && is_array($opt['user_ids'])
                 ? array_values(array_filter(array_map('intval', $opt['user_ids']))) : [];
        $deptIn  = $deptIds ? implode(',', $deptIds) : '';

        // 一人可能掛多個部門/職稱（兼任）：這裡挑出「顯示＋排序要用哪一筆」。
        // 優先序：符合部門篩選的 → **職級最高的那筆** → 主要職務(is_main) → 最小 id。
        //
        // 為什麼職級要排在 is_main 前面（2026-08-21 使用者要求「務必要把兼任放進去排序」）：
        // 例如某人主職是「技術部 工程師」、兼任「生管組 組長」，只看 is_main 會把他顯示成工程師
        // 並排到工程師那一群裡，但他在簽核/名單上的身分其實是組長——兼任常常才是真正的職務身分
        // （同 ai-rules/22「兼任常才是簽核身分，取職級最高那筆而非主職」）。
        // 有指定 dept_ids 時仍以「該部門的那筆」優先，因為那份名單本來就是在講那個部門。
        //
        // prefer_main（2026-09-01 使用者明確要求；預設關閉，不影響任何既有呼叫端）：把 is_main 排到職級前面，
        // 也就是顯示這個人「原本的」部門職稱。用在**沒有部門情境**的名單——例如會議紀錄從群組／行事曆帶入
        // 出席人員：那裡沒有人選過部門，套用職級優先會把「技術部 工程師」顯示成兼任的「生管組 組長」，跟
        // calendar.php（一律 is_main=1）與群組原先設定的職稱對不起來。依部門挑選的名單**不要**開這個選項，
        // 那種名單本來就是在講那個部門，顯示該部門的職稱才對。
        $preferMain = !empty($opt['prefer_main']);
        $pick = "SELECT m2.id FROM user_department_position_map m2
                 LEFT JOIN position p2 ON p2.id = m2.position_id
                 WHERE m2.user_id = u.id ORDER BY "
              . ($deptIn ? "(m2.department_id IN ({$deptIn})) DESC, " : "")
              . ($preferMain ? "m2.is_main DESC, COALESCE(p2.sort_order, 999) ASC, "
                             : "COALESCE(p2.sort_order, 999) ASC, m2.is_main DESC, ")
              . "m2.id ASC LIMIT 1";

        $where = ["u.state IN (" . implode(',', $states) . ")"];
        $params = [];
        if ($deptIn) $where[] = "EXISTS(SELECT 1 FROM user_department_position_map m3
                                        WHERE m3.user_id = u.id AND m3.department_id IN ({$deptIn}))";
        if ($userIds) $where[] = "u.id IN (" . implode(',', $userIds) . ")";
        if ($asof !== '') {
            $where[] = "(u.hire_date IS NULL OR u.hire_date <= ?)";                          // 該日之前已入職
            $params[] = $asof;
            $where[] = "(u.state <> 0 OR (u.leave_date IS NOT NULL AND u.leave_date >= ?))";  // 該日還沒離職
            $params[] = $asof;
        }
        if (!empty($opt['keyword'])) {
            // user 表為 utf8mb3，中文比對一律 CONVERT 成 utf8mb4（見 ai-rules/00 陷阱表）
            $where[] = "(CONVERT(u.user_cname USING utf8mb4) LIKE ? OR CONVERT(u.user_uname USING utf8mb4) LIKE ?)";
            $like = '%' . $opt['keyword'] . '%';
            $params[] = $like; $params[] = $like;
        }

        $sql = "SELECT u.id, u.user_cname, u.user_uname, u.state, u.hire_date, u.gender, u.highest_education,
                       m.department_id AS dept_id, d.name AS dept_name, COALESCE(d.sort_order, 999) AS dept_sort,
                       m.position_id, p.name AS position_name, COALESCE(p.sort_order, 999) AS position_sort
                FROM `user` u
                LEFT JOIN user_department_position_map m ON m.id = ({$pick})
                LEFT JOIN department d ON d.id = m.department_id
                LEFT JOIN position p ON p.id = m.position_id
                WHERE " . implode(' AND ', $where) . "
                ORDER BY dept_sort, position_sort, CONVERT(u.user_cname USING utf8mb4), u.id";
        $st = $db->prepare($sql);
        $st->execute($params);
        $rows = $st->fetchAll(PDO::FETCH_ASSOC);
        if (!$rows) return [];

        // 一人可能兼任多個部門（user_department_position_map 不只一列）：
        // 上面 $pick 只選「顯示用」的那一列 dept_id/position，這裡另外撈出該人「所有」掛的部門，
        // 讓呼叫端做部門篩選時能把兼任該部門的人也篩進來（不只是主要部門）。
        $deptIdsMap = [];
        if ($rows) {
            $uidIn = implode(',', array_column($rows, 'id'));
            $dRows = $db->query("SELECT user_id, department_id FROM user_department_position_map WHERE user_id IN ({$uidIn})")->fetchAll(PDO::FETCH_ASSOC);
            foreach ($dRows as $dr) {
                $deptIdsMap[(int)$dr['user_id']][] = (int)$dr['department_id'];
            }
        }

        $leave = eg_people_long_leave_map($db, array_column($rows, 'id'));
        foreach ($rows as &$r) {
            $r['id'] = (int)$r['id'];
            $r['state'] = (int)$r['state'];
            $r['state_label'] = eg_people_state_label($r['state']);
            $r['position_id'] = $r['position_id'] === null ? null : (int)$r['position_id'];
            $r['position_sort'] = (int)$r['position_sort'];
            $r['dept_id'] = $r['dept_id'] === null ? null : (int)$r['dept_id'];
            $r['dept_sort'] = (int)$r['dept_sort'];
            // 含兼任的完整部門清單（給需要「選部門篩人員」的頁面用；沒有掛任何部門時退回單一 dept_id）
            $r['dept_ids'] = isset($deptIdsMap[$r['id']]) ? array_values(array_unique($deptIdsMap[$r['id']]))
                           : ($r['dept_id'] !== null ? [$r['dept_id']] : []);
            $r['position_name'] = $r['position_name'] ?: '';
            $r['dept_name'] = $r['dept_name'] ?: '';

            // 長期請假標記：有核准假單用假單（有期間）；否則用 state 2/3（無期間）
            $lv = $leave[$r['id']] ?? null;
            if ($lv) {
                $r['on_leave'] = 1;
                $r['leave_label'] = $lv['label'];
                $r['leave_start'] = $lv['start'];
                $r['leave_end']   = $lv['end'];
                $r['leave_note']  = $lv['label'] . '（' . $lv['start'] . ' ~ ' . $lv['end'] . '）';
            } elseif (in_array($r['state'], [2, 3], true)) {
                $r['on_leave'] = 1;
                $r['leave_label'] = $r['state_label'];
                $r['leave_start'] = null; $r['leave_end'] = null;
                $r['leave_note']  = $r['state_label'] . '（期間未登錄請假單）';
            } else {
                $r['on_leave'] = 0;
                $r['leave_label'] = ''; $r['leave_start'] = null; $r['leave_end'] = null; $r['leave_note'] = '';
            }
            // 下拉/單行顯示用字串（職稱必顯示；部門由呼叫端依 eg_people_multi_dept 決定要不要用）
            $r['display'] = $r['user_cname']
                          . ($r['position_name'] !== '' ? '（' . $r['position_name'] . '）' : '')
                          . ($r['on_leave'] ? '［' . $r['leave_note'] . '］' : '');
        }
        return $rows;
    }
}

if (!function_exists('eg_people_list_asof')) {
    /**
     * 「某個日期當時」的人員列表（ai-rules/22：表單一律以業務日期當時的在職狀態與職務為準）
     *
     * 與 eg_people_list() 的三點差異：
     *   1. 在職判定改看該日期——該日之後才入職的不列、該日之前已離職的不列、
     *      該日還在職但之後才離職的**要列**（補歷史單據時選得到當時的人）。
     *   2. 部門／職稱改用 user_position_history 回推**當時**的職務（沒補登過異動的人＝現況）。
     *   3. 因此 dept_ids 的篩選也是比對「當時」的部門，不是現在的部門——
     *      當時在業務部、現在調到管理部的人，補當時的單據時要在業務部底下找得到。
     *
     * @param string $date Y-m-d；格式不合法時退回 eg_people_list()（現況），不擋流程
     */
    function eg_people_list_asof(PDO $db, array $opt, string $date): array {
        $date = trim($date);
        if (!preg_match('/^[0-9]{4}-[0-9]{2}-[0-9]{2}$/', $date)) return eg_people_list($db, $opt);
        require_once __DIR__ . '/position_history_lib.php';

        // 部門篩選改由「當時的職務快照」比對，所以先從 opt 拿掉不讓 SQL 用現況篩
        $deptIds = isset($opt['dept_ids']) && is_array($opt['dept_ids'])
                 ? array_values(array_filter(array_map('intval', $opt['dept_ids']))) : [];
        unset($opt['dept_ids']);
        $opt['asof_date'] = $date;
        $preferMain = !empty($opt['prefer_main']);
        $rows = eg_people_list($db, $opt);
        if (!$rows) return [];

        $snapAll = eg_position_snapshot_at_bulk($db, $date);
        // sort_order 一律取目前設定值（快照只存 id 與當時名稱，沒有排序值）
        $posSort = $deptSort = [];
        foreach ($db->query("SELECT id, COALESCE(sort_order,999) s FROM position")->fetchAll(PDO::FETCH_ASSOC) as $x)
            $posSort[(int)$x['id']] = (int)$x['s'];
        foreach ($db->query("SELECT id, COALESCE(sort_order,999) s FROM department")->fetchAll(PDO::FETCH_ASSOC) as $x)
            $deptSort[(int)$x['id']] = (int)$x['s'];

        $out = [];
        foreach ($rows as $r) {
            $snap = $snapAll[$r['id']] ?? [];
            if ($snap) {
                // 顯示用挑哪一筆：優先符合部門篩選的 → 職級最高 → 主要職務
                // （與 eg_people_list 同一套優先序；兼任常才是真正的職務身分，見 ai-rules/22）
                // prefer_main 時把「主要職務」提到職級前面，與 eg_people_list 的 $pick 保持同一套規則
                // （兩邊規則走鐘的話，同一份名單有沒有帶會議日期就會顯示出不同職稱）。
                $best = null; $bestKey = null;
                foreach ($snap as $sp) {
                    $sp['_hit']  = ($deptIds && in_array((int)$sp['department_id'], $deptIds, true)) ? 1 : 0;
                    $sp['_psrt'] = $posSort[(int)$sp['position_id']] ?? 999;
                    $key = $preferMain
                         ? [-$sp['_hit'], -(int)$sp['is_main'], $sp['_psrt']]
                         : [-$sp['_hit'], $sp['_psrt'], -(int)$sp['is_main']];
                    if ($best === null || $key < $bestKey) { $best = $sp; $bestKey = $key; }
                }
                $r['dept_ids']      = array_values(array_unique(array_map(fn($x) => (int)$x['department_id'], $snap)));
                $r['dept_id']       = (int)$best['department_id'];
                $r['dept_name']     = (string)($best['department_name'] ?? '');
                $r['dept_sort']     = $deptSort[(int)$best['department_id']] ?? 999;
                $r['position_id']   = (int)$best['position_id'];
                $r['position_name'] = (string)($best['position_name'] ?? '');
                $r['position_sort'] = (int)$best['_psrt'];
                $r['display']       = $r['user_cname']
                                    . ($r['position_name'] !== '' ? '（' . $r['position_name'] . '）' : '')
                                    . ($r['on_leave'] ? '［' . $r['leave_note'] . '］' : '');
            }
            if ($deptIds && !array_intersect($deptIds, $r['dept_ids'])) continue;
            $r['asof_date'] = $date;
            $out[] = $r;
        }
        // 鐵則 5：排序依部門/職稱 sort_order，不是姓名筆畫
        usort($out, function ($a, $b) {
            return [$a['dept_sort'], $a['position_sort'], $a['user_cname'], $a['id']]
               <=> [$b['dept_sort'], $b['position_sort'], $b['user_cname'], $b['id']];
        });
        return $out;
    }
}

if (!function_exists('eg_people_multi_dept')) {
    /** 這批人是否跨部門（true＝列表必須顯示部門欄，鐵則第 4 條） */
    function eg_people_multi_dept(array $rows): bool {
        $seen = [];
        foreach ($rows as $r) {
            $k = (string)($r['dept_id'] ?? '');
            if ($k !== '' && !in_array($k, $seen, true)) $seen[] = $k;
        }
        return count($seen) > 1;
    }
}

if (!function_exists('eg_people_posts')) {
    /**
     * 逐「職務」列出人員（**一人有兼任就會出現多列**，主要職務與兼任職務各一列）。
     * 與 eg_people_list() 的差別：那支是「一人一列」（只取主要/挑一個職務顯示），
     * 這支是「一職務一列」——需要讓使用者「以某個部門的身分」被挑選時用（例：申請單的申請人、
     * 負責人挑選器要選得到兼任該部門的人）。
     *
     * 遵守人員列表鐵則（ai-rules/08 第五節）：只列未離職者（user.state，0/90 不列）、
     * 長期請假者仍列出並帶 leave_note、**排序依 部門 sort_order → 職稱 sort_order → 姓名 id**
     * （不是姓名筆畫），每列都帶 dept_name / position_name 供「部門/職稱/姓名」欄位順序顯示。
     *
     * $opt: ['states'=>[1,2,3], 'dept_ids'=>[..只列這些部門..], 'user_ids'=>[..]]
     * 回傳每列：post_key('uid:deptId')、id、user_cname、dept_id、dept_name、dept_sort、
     *           position_id、position_name、position_sort、is_main、state、state_label、
     *           on_leave、leave_note、display（「部門　職稱　姓名」）
     */
    function eg_people_posts(PDO $db, array $opt = []): array {
        $exclude = array_map('intval', explode(',', EG_PEOPLE_EXCLUDE_STATES));
        $states  = isset($opt['states']) && is_array($opt['states']) ? array_map('intval', $opt['states']) : [1, 2, 3];
        $states  = array_values(array_diff($states, $exclude));
        if (!$states) return [];

        $where  = ["u.state IN (" . implode(',', $states) . ")"];
        $deptIds = isset($opt['dept_ids']) && is_array($opt['dept_ids'])
                 ? array_values(array_filter(array_map('intval', $opt['dept_ids']))) : [];
        if ($deptIds) $where[] = "m.department_id IN (" . implode(',', $deptIds) . ")";
        $userIds = isset($opt['user_ids']) && is_array($opt['user_ids'])
                 ? array_values(array_filter(array_map('intval', $opt['user_ids']))) : [];
        if ($userIds) $where[] = "u.id IN (" . implode(',', $userIds) . ")";

        $sql = "SELECT u.id, u.user_cname, u.user_uname, u.state,
                       m.department_id AS dept_id, d.name AS dept_name, COALESCE(d.sort_order,999) AS dept_sort,
                       m.position_id, p.name AS position_name, COALESCE(p.sort_order,999) AS position_sort,
                       COALESCE(m.is_main,0) AS is_main
                FROM user_department_position_map m
                JOIN `user` u ON u.id = m.user_id
                LEFT JOIN department d ON d.id = m.department_id
                LEFT JOIN position  p ON p.id = m.position_id
                WHERE " . implode(' AND ', $where) . "
                ORDER BY dept_sort, d.id, position_sort, p.id, u.id";
        try {
            $rows = $db->query($sql)->fetchAll(PDO::FETCH_ASSOC);
        } catch (Throwable $e) { return []; }
        if (!$rows) return [];

        $leave = eg_people_long_leave_map($db, array_values(array_unique(array_column($rows, 'id'))));
        foreach ($rows as &$r) {
            $r['id']          = (int)$r['id'];
            $r['state']       = (int)$r['state'];
            $r['state_label'] = eg_people_state_label($r['state']);
            $r['dept_id']     = $r['dept_id'] === null ? null : (int)$r['dept_id'];
            $r['position_id'] = $r['position_id'] === null ? null : (int)$r['position_id'];
            $r['dept_name']     = (string)($r['dept_name'] ?? '');
            $r['position_name'] = (string)($r['position_name'] ?? '');
            $r['is_main']     = (int)$r['is_main'];
            $r['post_key']    = $r['id'] . ':' . (int)$r['dept_id'];
            $lv = $leave[$r['id']] ?? null;
            $r['on_leave']    = $lv ? 1 : 0;
            $r['leave_note']  = $lv['note'] ?? '';
            // 欄位順序固定「部門/職稱/姓名」（鐵則第 5 條）
            $r['display'] = trim($r['dept_name'] . '　' . $r['position_name'] . '　' . $r['user_cname'])
                          . ($r['is_main'] ? '' : '（兼任）')
                          . ($r['on_leave'] ? '［' . $r['leave_note'] . '］' : '');
        }
        return $rows;
    }
}
