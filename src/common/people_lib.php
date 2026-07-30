<?php
/**
 * 人員列表共用庫（2026-07-30 建立）——「人員列表鐵則」的唯一實作點
 *
 * 鐵則（使用者 2026-07-30 定調，全站所有會列出人員的地方一體適用）：
 *   1. 只列未離職者：`user.state` 0(離職) 與 90(特殊帳號，不列入員工) 一律不列。
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
    define('EG_PEOPLE_EXCLUDE_STATES', '0,90');   // 離職、特殊帳號：任何人員列表都不列
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
     *                    states    允許的 state（預設 [1,2,3,99]；EG_PEOPLE_EXCLUDE_STATES 永遠排除）
     *                    keyword   姓名/帳號模糊搜尋
     * @return array 每列：id, user_cname, user_uname, state, state_label,
     *               position_id, position_name, position_sort, dept_id, dept_name, dept_sort,
     *               on_leave(0/1), leave_label, leave_start, leave_end, leave_note, display
     *               排序：職稱 → 部門 → id
     */
    function eg_people_list(PDO $db, array $opt = []): array {
        $exclude = array_map('intval', explode(',', EG_PEOPLE_EXCLUDE_STATES));
        $states  = isset($opt['states']) && is_array($opt['states']) ? array_map('intval', $opt['states']) : [1,2,3,99];
        $states  = array_values(array_diff($states, $exclude));
        if (!$states) return [];

        $deptIds = isset($opt['dept_ids']) && is_array($opt['dept_ids'])
                 ? array_values(array_filter(array_map('intval', $opt['dept_ids']))) : [];
        $userIds = isset($opt['user_ids']) && is_array($opt['user_ids'])
                 ? array_values(array_filter(array_map('intval', $opt['user_ids']))) : [];
        $deptIn  = $deptIds ? implode(',', $deptIds) : '';

        // 一人可能掛多個部門/職稱：優先取「符合部門篩選的」→ 主要職務(is_main) → 最小 id
        $pick = "SELECT m2.id FROM user_department_position_map m2 WHERE m2.user_id = u.id ORDER BY "
              . ($deptIn ? "(m2.department_id IN ({$deptIn})) DESC, " : "")
              . "m2.is_main DESC, m2.id ASC LIMIT 1";

        $where = ["u.state IN (" . implode(',', $states) . ")"];
        $params = [];
        if ($deptIn) $where[] = "EXISTS(SELECT 1 FROM user_department_position_map m3
                                        WHERE m3.user_id = u.id AND m3.department_id IN ({$deptIn}))";
        if ($userIds) $where[] = "u.id IN (" . implode(',', $userIds) . ")";
        if (!empty($opt['keyword'])) {
            // user 表為 utf8mb3，中文比對一律 CONVERT 成 utf8mb4（見 ai-rules/00 陷阱表）
            $where[] = "(CONVERT(u.user_cname USING utf8mb4) LIKE ? OR CONVERT(u.user_uname USING utf8mb4) LIKE ?)";
            $like = '%' . $opt['keyword'] . '%';
            $params[] = $like; $params[] = $like;
        }

        $sql = "SELECT u.id, u.user_cname, u.user_uname, u.state,
                       m.department_id AS dept_id, d.name AS dept_name, COALESCE(d.sort_order, 999) AS dept_sort,
                       m.position_id, p.name AS position_name, COALESCE(p.sort_order, 999) AS position_sort
                FROM `user` u
                LEFT JOIN user_department_position_map m ON m.id = ({$pick})
                LEFT JOIN department d ON d.id = m.department_id
                LEFT JOIN position p ON p.id = m.position_id
                WHERE " . implode(' AND ', $where) . "
                ORDER BY position_sort, dept_sort, u.id";
        $st = $db->prepare($sql);
        $st->execute($params);
        $rows = $st->fetchAll(PDO::FETCH_ASSOC);
        if (!$rows) return [];

        $leave = eg_people_long_leave_map($db, array_column($rows, 'id'));
        foreach ($rows as &$r) {
            $r['id'] = (int)$r['id'];
            $r['state'] = (int)$r['state'];
            $r['state_label'] = eg_people_state_label($r['state']);
            $r['position_id'] = $r['position_id'] === null ? null : (int)$r['position_id'];
            $r['position_sort'] = (int)$r['position_sort'];
            $r['dept_id'] = $r['dept_id'] === null ? null : (int)$r['dept_id'];
            $r['dept_sort'] = (int)$r['dept_sort'];
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
