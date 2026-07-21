<?php
// BomTrack_API.php — BOM 追蹤功能後端 API
// 群組/規則/通知範圍/訂閱者/分享 的 CRUD，以及依規則計算匹配BOM清單、進度時間軸查詢。
session_start();
header('Content-Type: application/json; charset=utf-8');

require_once __DIR__ . '/../common/DBConnection.php';
require_once __DIR__ . '/../common/role_features_helper.php';
require_once __DIR__ . '/../common/bom_track_notify.php';

if (!isset($_SESSION['id'])) {
    echo json_encode(['success' => false, 'message' => '尚未登入']);
    exit;
}
$user_id = (int)$_SESSION['id'];

$conn = new DBConnection();
$db = $conn->getPDO();

// 全站二元權限：module='bom_track'
if (!rf_has_module_role($db, $user_id, 'bom_track')) {
    echo json_encode(['success' => false, 'message' => '請先申請權限', 'no_access' => true]);
    exit;
}
$is_admin = rf_has_feature(rf_load_user_features($db, $user_id), 'all');

$action = $_POST['action'] ?? $_GET['action'] ?? '';
$response = ['success' => false, 'message' => '未知的 action: ' . $action];

// ── 共用小工具 ──────────────────────────────────────────────────────────
function bt_paginate_params() {
    $page = max(1, (int)($_POST['page'] ?? $_GET['page'] ?? 1));
    $pageSize = (int)($_POST['pageSize'] ?? $_GET['pageSize'] ?? 10);
    if (!in_array($pageSize, [5, 10, 20, 50], true)) $pageSize = 10;
    return [$page, $pageSize];
}

// 單一規則 → [SQL片段, 參數陣列]；match_mode='pattern' 時 rule_value 視為 LIKE 樣式(呼叫端已補好 % )
function bt_rule_condition(array $r) {
    $pattern = $r['match_mode'] === 'pattern';
    switch ($r['rule_type']) {
        case 'part':
            if ($pattern) {
                return ["bom.d_setting_id IN (SELECT d_id FROM d_setting WHERE D_Setting_Id LIKE ? OR Drawing_No LIKE ?)", [$r['rule_value'], $r['rule_value']]];
            }
            return ["bom.d_setting_id = ?", [(int)$r['rule_value']]];
        case 'bom':
            return [$pattern ? "bom.bom LIKE ?" : "bom.bom = ?", [$r['rule_value']]];
        case 'customer':
            $op = $pattern ? 'LIKE' : '=';
            $valCol = $pattern ? 'cl.customer' : 'ot.Client_name_ID';
            if ($pattern) {
                return ["(EXISTS (
                    SELECT 1 FROM bom_order_process_map bopm
                    JOIN order_track ot ON ot.Order_id = bopm.order_id
                    JOIN customer_list cl ON cl.customer_id = ot.Client_name_ID
                    WHERE bopm.bom = bom.bom AND cl.customer LIKE ?
                ) OR EXISTS (
                    SELECT 1 FROM order_track ot2
                    JOIN customer_list cl2 ON cl2.customer_id = ot2.Client_name_ID
                    WHERE ot2.Order_id = bom.o_order_id AND cl2.customer LIKE ?
                ))", [$r['rule_value'], $r['rule_value']]];
            }
            return ["(EXISTS (
                SELECT 1 FROM bom_order_process_map bopm
                JOIN order_track ot ON ot.Order_id = bopm.order_id
                WHERE bopm.bom = bom.bom AND ot.Client_name_ID = ?
            ) OR EXISTS (
                SELECT 1 FROM order_track ot2
                WHERE ot2.Order_id = bom.o_order_id AND ot2.Client_name_ID = ?
            ))", [$r['rule_value'], $r['rule_value']]];
        case 'sales':
            if ($pattern) {
                // user.user_cname 是舊 latin1 欄位，中文樣式比對需 CONVERT
                return ["(EXISTS (
                    SELECT 1 FROM bom_order_process_map bopm
                    JOIN order_track ot ON ot.Order_id = bopm.order_id
                    JOIN customer_sales cs ON cs.customer_id = ot.Client_name_ID AND cs.role='primary' AND cs.is_active=1
                    JOIN user u ON u.id = cs.user_id
                    WHERE bopm.bom = bom.bom AND CONVERT(u.user_cname USING utf8mb4) LIKE ?
                ) OR EXISTS (
                    SELECT 1 FROM order_track ot2
                    JOIN customer_sales cs2 ON cs2.customer_id = ot2.Client_name_ID AND cs2.role='primary' AND cs2.is_active=1
                    JOIN user u2 ON u2.id = cs2.user_id
                    WHERE ot2.Order_id = bom.o_order_id AND CONVERT(u2.user_cname USING utf8mb4) LIKE ?
                ))", [$r['rule_value'], $r['rule_value']]];
            }
            return ["(EXISTS (
                SELECT 1 FROM bom_order_process_map bopm
                JOIN order_track ot ON ot.Order_id = bopm.order_id
                JOIN customer_sales cs ON cs.customer_id = ot.Client_name_ID AND cs.role='primary' AND cs.is_active=1
                WHERE bopm.bom = bom.bom AND cs.user_id = ?
            ) OR EXISTS (
                SELECT 1 FROM order_track ot2
                JOIN customer_sales cs2 ON cs2.customer_id = ot2.Client_name_ID AND cs2.role='primary' AND cs2.is_active=1
                WHERE ot2.Order_id = bom.o_order_id AND cs2.user_id = ?
            ))", [(int)$r['rule_value'], (int)$r['rule_value']]];
        case 'due_range':
            if ($r['due_range_type'] === 'fixed' && $r['due_from'] && $r['due_to']) {
                return ["bom.Delivery_date BETWEEN ? AND ?", [$r['due_from'], $r['due_to']]];
            } elseif ($r['due_range_type'] === 'relative'
                && $r['due_relative_from_days'] !== null && $r['due_relative_to_days'] !== null) {
                return ["bom.Delivery_date BETWEEN DATE_ADD(CURDATE(), INTERVAL ? DAY) AND DATE_ADD(CURDATE(), INTERVAL ? DAY)", [(int)$r['due_relative_from_days'], (int)$r['due_relative_to_days']]];
            }
            return [null, []];
    }
    return [null, []];
}

// 依群組已存規則，組出 bom 表的篩選條件：
// 同一種規則類型內是 OR 聯集(例如一次勾選多個料號 = 符合任一個即可)，
// 不同規則類型之間是 AND 交集(例如「料號」+「客戶」= 兩者都要符合才算)，
// 最後再 AND NOT(排除規則1) AND NOT(排除規則2)... 扣除
function bt_build_rule_where(PDO $db, int $groupId) {
    $st = $db->prepare("SELECT * FROM bom_watch_rule WHERE group_id = ?");
    $st->execute([$groupId]);
    $rules = $st->fetchAll(PDO::FETCH_ASSOC);
    if (!$rules) return [null, []]; // 沒有規則 → 不匹配任何BOM

    $includeByType = []; // rule_type => ['sql'=>[], 'params'=>[]]
    $excludeParts = []; $excludeParams = [];
    foreach ($rules as $r) {
        [$sql, $params] = bt_rule_condition($r);
        if ($sql === null) continue;
        if (!empty($r['is_exclude'])) {
            $excludeParts[] = "NOT ($sql)";
            $excludeParams = array_merge($excludeParams, $params);
        } else {
            $t = $r['rule_type'];
            if (!isset($includeByType[$t])) $includeByType[$t] = ['sql' => [], 'params' => []];
            $includeByType[$t]['sql'][] = $sql;
            $includeByType[$t]['params'] = array_merge($includeByType[$t]['params'], $params);
        }
    }
    if (!$includeByType) return [null, []]; // 排除規則沒有納入規則可扣，等同無規則

    $andParts = []; $params = [];
    foreach ($includeByType as $grp) {
        $andParts[] = '(' . implode(' OR ', $grp['sql']) . ')';
        $params = array_merge($params, $grp['params']);
    }
    $where = implode(' AND ', $andParts);
    if ($excludeParts) {
        $where .= ' AND ' . implode(' AND ', $excludeParts);
        $params = array_merge($params, $excludeParams);
    }
    return [$where, $params];
}

// 使用者目前所屬的所有部門ID（含主要+兼職）
function bt_user_dept_ids(PDO $db, int $userId) {
    $st = $db->prepare("SELECT DISTINCT department_id FROM user_department_position_map WHERE user_id = ?");
    $st->execute([$userId]);
    return array_map('intval', $st->fetchAll(PDO::FETCH_COLUMN));
}

// 群組存取檢查：擁有者、被分享者(個人或所屬部門)、或全域管理員，回傳 bool
function bt_can_access_group(PDO $db, int $groupId, int $userId, bool $isAdmin) {
    if ($isAdmin) return true;
    $st = $db->prepare("SELECT owner_user_id FROM bom_watch_group WHERE group_id = ?");
    $st->execute([$groupId]);
    $owner = $st->fetchColumn();
    if ($owner === false) return false;
    if ((int)$owner === $userId) return true;
    $st2 = $db->prepare("SELECT 1 FROM bom_watch_share WHERE group_id = ? AND target_type='user' AND target_id = ? LIMIT 1");
    $st2->execute([$groupId, $userId]);
    if ($st2->fetchColumn()) return true;
    $deptIds = bt_user_dept_ids($db, $userId);
    if ($deptIds) {
        $in = implode(',', array_fill(0, count($deptIds), '?'));
        $st3 = $db->prepare("SELECT 1 FROM bom_watch_share WHERE group_id = ? AND target_type='dept' AND target_id IN ($in) LIMIT 1");
        $st3->execute(array_merge([$groupId], $deptIds));
        if ($st3->fetchColumn()) return true;
    }
    return false;
}

// 展開一組 target 代碼(user-123 / dept-5)為使用者ID清單(部門會展開為目前成員)
function bt_expand_targets(PDO $db, array $codes) {
    $userIds = [];
    foreach ($codes as $code) {
        if (!preg_match('/^(user|dept)-(\d+)$/', $code, $m)) continue;
        if ($m[1] === 'user') {
            $userIds[] = (int)$m[2];
        } else {
            $st = $db->prepare("SELECT DISTINCT user_id FROM user_department_position_map WHERE department_id = ?");
            $st->execute([(int)$m[2]]);
            foreach ($st->fetchAll(PDO::FETCH_COLUMN) as $uid) $userIds[] = (int)$uid;
        }
    }
    return array_values(array_unique(array_filter($userIds)));
}

// 顯示用：單一使用者的姓名+主要部門/職稱+兼職部門/職稱
function bt_user_display(PDO $db, int $userId) {
    $st = $db->prepare("
        SELECT u.user_cname,
               d.name AS dept_name, p.name AS pos_name
        FROM user u
        LEFT JOIN user_department_position_map udpm ON udpm.user_id = u.id AND udpm.is_main = 1
        LEFT JOIN department d ON d.id = udpm.department_id
        LEFT JOIN position p ON p.id = udpm.position_id
        WHERE u.id = ?
    ");
    $st->execute([$userId]);
    $row = $st->fetch(PDO::FETCH_ASSOC);
    if (!$row) return null;
    $st2 = $db->prepare("
        SELECT d.name AS dept_name, p.name AS pos_name
        FROM user_department_position_map udpm
        LEFT JOIN department d ON d.id = udpm.department_id
        LEFT JOIN position p ON p.id = udpm.position_id
        WHERE udpm.user_id = ? AND udpm.is_main = 0
    ");
    $st2->execute([$userId]);
    $concurrent = $st2->fetchAll(PDO::FETCH_ASSOC);
    return [
        'user_cname' => $row['user_cname'],
        'dept_name' => $row['dept_name'],
        'pos_name' => $row['pos_name'],
        'concurrent' => array_map(function ($c) { return trim(($c['dept_name'] ?: '') . '/' . ($c['pos_name'] ?: '')); }, $concurrent),
    ];
}

switch ($action) {

    case 'get_access': {
        $response = ['success' => true, 'has_access' => true, 'is_admin' => $is_admin];
        break;
    }

    // ── 群組 ─────────────────────────────────────────────────────────
    case 'list_groups': {
        try {
            if ($is_admin) {
                $rows = $db->prepare("
                    SELECT g.group_id, g.group_name, g.owner_user_id, u.user_cname AS owner_name,
                           'owner' AS relation
                    FROM bom_watch_group g
                    JOIN user u ON u.id = g.owner_user_id
                    ORDER BY g.created_at DESC
                ");
                $rows->execute();
            } else {
                // 分享對象存在 bom_watch_share.target_type/target_id（user=個人、dept=整個部門），
                // 而非 shared_with_user_id（該欄不存在）。可見群組 = 自己擁有 OR 被個人分享 OR 所屬部門被分享，
                // 與 bt_can_access_group() 判斷邏輯一致。
                $rows = $db->prepare("
                    SELECT g.group_id, g.group_name, g.owner_user_id, u.user_cname AS owner_name,
                           CASE WHEN g.owner_user_id = ? THEN 'owner' ELSE 'shared' END AS relation
                    FROM bom_watch_group g
                    JOIN user u ON u.id = g.owner_user_id
                    WHERE g.owner_user_id = ?
                       OR EXISTS (SELECT 1 FROM bom_watch_share s
                                  WHERE s.group_id = g.group_id AND s.target_type='user' AND s.target_id = ?)
                       OR EXISTS (SELECT 1 FROM bom_watch_share s2
                                  JOIN user_department_position_map udpm ON udpm.department_id = s2.target_id
                                  WHERE s2.group_id = g.group_id AND s2.target_type='dept' AND udpm.user_id = ?)
                    ORDER BY g.created_at DESC
                ");
                $rows->execute([$user_id, $user_id, $user_id, $user_id]);
            }
            $response = ['success' => true, 'data' => $rows->fetchAll(PDO::FETCH_ASSOC)];
        } catch (Throwable $e) { $response = ['success' => false, 'message' => $e->getMessage()]; }
        break;
    }

    case 'save_group': {
        $groupId = (int)($_POST['group_id'] ?? 0);
        $name = trim($_POST['group_name'] ?? '');
        if ($name === '') { $response = ['success' => false, 'message' => '請輸入群組名稱']; break; }
        try {
            if ($groupId) {
                if (!bt_can_access_group($db, $groupId, $user_id, $is_admin)) {
                    $response = ['success' => false, 'message' => '無權限修改此群組']; break;
                }
                $db->prepare("UPDATE bom_watch_group SET group_name=? WHERE group_id=?")->execute([$name, $groupId]);
                $response = ['success' => true, 'group_id' => $groupId];
            } else {
                $db->prepare("INSERT INTO bom_watch_group (group_name, owner_user_id) VALUES (?, ?)")
                    ->execute([$name, $user_id]);
                $response = ['success' => true, 'group_id' => (int)$db->lastInsertId()];
            }
        } catch (Throwable $e) { $response = ['success' => false, 'message' => $e->getMessage()]; }
        break;
    }

    case 'delete_group': {
        $groupId = (int)($_POST['group_id'] ?? 0);
        try {
            $st = $db->prepare("SELECT owner_user_id FROM bom_watch_group WHERE group_id=?");
            $st->execute([$groupId]);
            $owner = $st->fetchColumn();
            if ($owner === false) { $response = ['success' => false, 'message' => '找不到群組']; break; }
            if (!$is_admin && (int)$owner !== $user_id) { $response = ['success' => false, 'message' => '只有擁有者或管理員可以刪除']; break; }

            $db->beginTransaction();
            $scopeIds = $db->prepare("SELECT scope_id FROM bom_watch_notify_scope WHERE group_id=?");
            $scopeIds->execute([$groupId]);
            $ids = $scopeIds->fetchAll(PDO::FETCH_COLUMN);
            if ($ids) {
                $in = implode(',', array_fill(0, count($ids), '?'));
                $db->prepare("DELETE FROM bom_watch_subscriber WHERE scope_id IN ($in)")->execute($ids);
            }
            $db->prepare("DELETE FROM bom_watch_notify_scope WHERE group_id=?")->execute([$groupId]);
            $db->prepare("DELETE FROM bom_watch_rule WHERE group_id=?")->execute([$groupId]);
            $db->prepare("DELETE FROM bom_watch_share WHERE group_id=?")->execute([$groupId]);
            $db->prepare("DELETE FROM bom_watch_group WHERE group_id=?")->execute([$groupId]);
            $db->commit();
            $response = ['success' => true];
        } catch (Throwable $e) {
            if ($db->inTransaction()) $db->rollBack();
            $response = ['success' => false, 'message' => $e->getMessage()];
        }
        break;
    }

    // ── 規則 ─────────────────────────────────────────────────────────
    case 'get_rules': {
        $groupId = (int)($_GET['group_id'] ?? 0);
        if (!bt_can_access_group($db, $groupId, $user_id, $is_admin)) { $response = ['success' => false, 'message' => '無權限']; break; }
        try {
            $st = $db->prepare("SELECT * FROM bom_watch_rule WHERE group_id=? ORDER BY rule_id ASC");
            $st->execute([$groupId]);
            $rules = $st->fetchAll(PDO::FETCH_ASSOC);
            foreach ($rules as &$r) {
                $r['rule_value_label'] = null;
                if ($r['match_mode'] === 'pattern') {
                    // 模糊比對：規則值本身就是使用者輸入的樣式文字，不需再查名稱
                    $r['rule_value_label'] = $r['rule_value'] . '（模糊比對）';
                    continue;
                }
                switch ($r['rule_type']) {
                    case 'part':
                        $q = $db->prepare("SELECT D_Setting_Id FROM d_setting WHERE d_id=?");
                        $q->execute([(int)$r['rule_value']]);
                        $r['rule_value_label'] = $q->fetchColumn() ?: $r['rule_value'];
                        break;
                    case 'bom':
                        $r['rule_value_label'] = $r['rule_value'];
                        break;
                    case 'customer':
                        $q = $db->prepare("SELECT customer FROM customer_list WHERE customer_id=?");
                        $q->execute([$r['rule_value']]);
                        $r['rule_value_label'] = $q->fetchColumn() ?: $r['rule_value'];
                        break;
                    case 'sales':
                        $q = $db->prepare("SELECT user_cname FROM user WHERE id=?");
                        $q->execute([(int)$r['rule_value']]);
                        $r['rule_value_label'] = $q->fetchColumn() ?: $r['rule_value'];
                        break;
                }
            }
            unset($r);
            $response = ['success' => true, 'data' => $rules];
        } catch (Throwable $e) { $response = ['success' => false, 'message' => $e->getMessage()]; }
        break;
    }

    // 新增規則。exact模式支援一次多選：rule_values 傳 JSON 陣列（["12","34",...]），逐一各建一筆；
    // pattern模式一次一個樣式：rule_value 傳文字（前端已包好 % 萬用字元）。is_exclude=1 代表這是排除規則。
    case 'save_rule': {
        $groupId = (int)($_POST['group_id'] ?? 0);
        if (!bt_can_access_group($db, $groupId, $user_id, $is_admin)) { $response = ['success' => false, 'message' => '無權限']; break; }
        $ruleType = $_POST['rule_type'] ?? '';
        if (!in_array($ruleType, ['part', 'bom', 'customer', 'sales', 'due_range'], true)) {
            $response = ['success' => false, 'message' => '不支援的規則類型']; break;
        }
        $matchMode = ($_POST['match_mode'] ?? '') === 'pattern' ? 'pattern' : 'exact';
        $isExclude = !empty($_POST['is_exclude']) ? 1 : 0;
        try {
            if ($ruleType === 'due_range') {
                $rangeType = ($_POST['due_range_type'] ?? '') === 'relative' ? 'relative' : 'fixed';
                $db->prepare("INSERT INTO bom_watch_rule
                    (group_id, rule_type, is_exclude, due_range_type, due_from, due_to, due_relative_from_days, due_relative_to_days)
                    VALUES (?, 'due_range', ?, ?, ?, ?, ?, ?)")
                    ->execute([
                        $groupId, $isExclude, $rangeType,
                        $rangeType === 'fixed' ? ($_POST['due_from'] ?? null) : null,
                        $rangeType === 'fixed' ? ($_POST['due_to'] ?? null) : null,
                        $rangeType === 'relative' ? (int)($_POST['due_relative_from_days'] ?? 0) : null,
                        $rangeType === 'relative' ? (int)($_POST['due_relative_to_days'] ?? 0) : null,
                    ]);
                $response = ['success' => true, 'rule_id' => (int)$db->lastInsertId()];
            } elseif ($matchMode === 'pattern') {
                $val = trim($_POST['rule_value'] ?? '');
                if ($val === '') { $response = ['success' => false, 'message' => '請輸入模糊比對樣式']; break; }
                $db->prepare("INSERT INTO bom_watch_rule (group_id, rule_type, rule_value, match_mode, is_exclude) VALUES (?, ?, ?, 'pattern', ?)")
                    ->execute([$groupId, $ruleType, '%' . $val . '%', $isExclude]);
                $response = ['success' => true, 'rule_id' => (int)$db->lastInsertId()];
            } else {
                $values = json_decode($_POST['rule_values'] ?? '[]', true);
                if (!is_array($values) || !$values) {
                    $single = trim($_POST['rule_value'] ?? '');
                    if ($single === '') { $response = ['success' => false, 'message' => '請選擇至少一筆']; break; }
                    $values = [$single];
                }
                $ins = $db->prepare("INSERT INTO bom_watch_rule (group_id, rule_type, rule_value, match_mode, is_exclude) VALUES (?, ?, ?, 'exact', ?)");
                $lastId = 0;
                foreach ($values as $v) {
                    $v = trim((string)$v);
                    if ($v === '') continue;
                    $ins->execute([$groupId, $ruleType, $v, $isExclude]);
                    $lastId = (int)$db->lastInsertId();
                }
                $response = ['success' => true, 'rule_id' => $lastId, 'count' => count($values)];
            }
        } catch (Throwable $e) { $response = ['success' => false, 'message' => $e->getMessage()]; }
        break;
    }

    case 'delete_rule': {
        $ruleId = (int)($_POST['rule_id'] ?? 0);
        try {
            $st = $db->prepare("SELECT group_id FROM bom_watch_rule WHERE rule_id=?");
            $st->execute([$ruleId]);
            $groupId = $st->fetchColumn();
            if ($groupId === false) { $response = ['success' => false, 'message' => '找不到規則']; break; }
            if (!bt_can_access_group($db, (int)$groupId, $user_id, $is_admin)) { $response = ['success' => false, 'message' => '無權限']; break; }
            $db->prepare("DELETE FROM bom_watch_rule WHERE rule_id=?")->execute([$ruleId]);
            $response = ['success' => true];
        } catch (Throwable $e) { $response = ['success' => false, 'message' => $e->getMessage()]; }
        break;
    }

    // ── 匹配清單 ─────────────────────────────────────────────────────
    case 'get_matched_boms': {
        $groupId = (int)($_GET['group_id'] ?? $_POST['group_id'] ?? 0);
        if (!bt_can_access_group($db, $groupId, $user_id, $is_admin)) { $response = ['success' => false, 'message' => '無權限']; break; }
        try {
            [$whereRule, $params] = bt_build_rule_where($db, $groupId);
            if ($whereRule === null) {
                $response = ['success' => true, 'data' => [], 'total' => 0, 'message' => '此群組尚未設定任何追蹤規則'];
                break;
            }

            $extraWhere = [];
            $kw = trim($_GET['bom_kw'] ?? $_POST['bom_kw'] ?? '');
            if ($kw !== '') { $extraWhere[] = "bom.bom LIKE ?"; $params[] = "%{$kw}%"; }
            $status = trim($_GET['status'] ?? $_POST['status'] ?? '');
            if ($status === 'open') $extraWhere[] = "bom.processing_state IS NULL";
            elseif ($status === 'closed') $extraWhere[] = "bom.processing_state = 1";

            $whereSql = "WHERE $whereRule" . ($extraWhere ? ' AND ' . implode(' AND ', $extraWhere) : '');

            // skip_count=1：規則編輯中即時預覽用，只抓第一頁列表、跳過COUNT(*)全量統計(規則多時EXISTS子查詢COUNT很慢)。
            // 真正的統計數字(卡片)與總頁數，留到使用者實際篩選/翻頁時才重新計算，避免每加一筆規則就整個變慢。
            $skipCount = !empty($_GET['skip_count']) || !empty($_POST['skip_count']);
            $total = null; $totalOpen = null; $totalClosed = null;
            if (!$skipCount) {
                $countStmt = $db->prepare("SELECT COUNT(*) FROM bom $whereSql");
                $countStmt->execute($params);
                $total = (int)$countStmt->fetchColumn();

                // 開/關狀態統計一律對全量資料計算，不能只算當頁
                $openWhere = "WHERE $whereRule AND bom.processing_state IS NULL" . ($extraWhere ? ' AND ' . implode(' AND ', $extraWhere) : '');
                $openStmt = $db->prepare("SELECT COUNT(*) FROM bom $openWhere");
                $openStmt->execute($params);
                $totalOpen = (int)$openStmt->fetchColumn();
                $totalClosed = $total - $totalOpen;
            }

            [$page, $pageSize] = bt_paginate_params();
            if ($skipCount) $page = 1;
            $offset = ($page - 1) * $pageSize;

            // 進度%即時從bom_ing算(不依賴bom_summary，該表已無人維護/準備淘汰)：
            // 公式比照原本 rebuild_bom_summary.php 的邏輯(目前所在關卡序位÷總關卡數)，
            // 差別是排除 processing_state='skip'（生管明確標記不加工的製程）不計入分子分母
            $sql = "
                SELECT bom.bom, bom.d_id, bom.Client_Name, bom.Delivery_date, bom.processing_state,
                       (SELECT COUNT(DISTINCT bi.bom_sn) FROM bom_ing bi WHERE bi.bom = bom.bom AND bi.processing_state != 'skip') AS process_count,
                       (SELECT COUNT(DISTINCT bi.bom_sn) FROM bom_ing bi WHERE bi.bom = bom.bom AND bi.processing_state != 'skip' AND bi.bom_sn <= (
                           SELECT bi2.bom_sn FROM bom_ing bi2 WHERE bi2.bom = bom.bom AND bi2.processing_state != 'skip'
                           ORDER BY GREATEST(COALESCE(bi2.outsource_date,'0000-00-00'), COALESCE(bi2.QC_check_date,'0000-00-00')) DESC, bi2.bom_sn DESC LIMIT 1
                       )) AS current_step,
                       (SELECT pn.ProcessName FROM bom_ing bi3 LEFT JOIN process_no pn ON pn.ProcessNo = bi3.process_no
                          WHERE bi3.bom = bom.bom AND bi3.processing_state != 'skip'
                          ORDER BY GREATEST(COALESCE(bi3.outsource_date,'0000-00-00'), COALESCE(bi3.QC_check_date,'0000-00-00')) DESC, bi3.bom_sn DESC LIMIT 1) AS latest_process_name,
                       COALESCE(
                         (SELECT ot.Order_oo FROM bom_order_process_map bopm JOIN order_track ot ON ot.Order_id = bopm.order_id WHERE bopm.bom = bom.bom LIMIT 1),
                         (SELECT ot2.Order_oo FROM order_track ot2 WHERE ot2.Order_id = bom.o_order_id LIMIT 1)
                       ) AS order_no,
                       COALESCE(
                         (SELECT u.user_cname FROM bom_order_process_map bopm JOIN order_track ot ON ot.Order_id = bopm.order_id
                            JOIN customer_sales cs ON cs.customer_id = ot.Client_name_ID AND cs.role='primary' AND cs.is_active=1
                            JOIN user u ON u.id = cs.user_id WHERE bopm.bom = bom.bom LIMIT 1),
                         (SELECT u2.user_cname FROM order_track ot2
                            JOIN customer_sales cs2 ON cs2.customer_id = ot2.Client_name_ID AND cs2.role='primary' AND cs2.is_active=1
                            JOIN user u2 ON u2.id = cs2.user_id WHERE ot2.Order_id = bom.o_order_id LIMIT 1)
                       ) AS sales_name
                FROM bom
                $whereSql
                ORDER BY bom.Delivery_date ASC
                LIMIT $pageSize OFFSET $offset
            ";
            $st = $db->prepare($sql);
            $st->execute($params);
            $rows = $st->fetchAll(PDO::FETCH_ASSOC);
            foreach ($rows as &$row) {
                $pc = (int)($row['process_count'] ?? 0);
                $cs = $row['current_step'];
                $row['progress_pct'] = ($pc > 0 && $cs !== null) ? round(((int)$cs / $pc) * 100, 2) : null;
            }
            unset($row);
            $response = ['success' => true, 'data' => $rows, 'total' => $total, 'total_open' => $totalOpen, 'total_closed' => $totalClosed, 'page' => $page, 'pageSize' => $pageSize];
        } catch (Throwable $e) { $response = ['success' => false, 'message' => $e->getMessage()]; }
        break;
    }

    // ── 進度時間軸 ────────────────────────────────────────────────────
    case 'get_bom_timeline': {
        $bom = trim($_GET['bom'] ?? $_POST['bom'] ?? '');
        if ($bom === '') { $response = ['success' => false, 'message' => '缺少 bom']; break; }
        try {
            $events = [];

            $st1 = $db->prepare("
                SELECT bi.bom_ing_fid, bi.outsource_date, bi.return_date,
                       pn.ProcessName, m.maker_id AS maker_name
                FROM bom_ing bi
                LEFT JOIN process_no pn ON pn.ProcessNo = bi.process_no
                LEFT JOIN maker_list m ON m.maker_id_no = bi.maker_id_no
                WHERE bi.bom = ?
            ");
            $st1->execute([$bom]);
            foreach ($st1->fetchAll(PDO::FETCH_ASSOC) as $r) {
                $pname = $r['ProcessName'] ?: '（未知製程）';
                if ($r['outsource_date']) $events[] = ['time' => $r['outsource_date'], 'type' => '發包', 'category' => 'outsource', 'is_ng' => false, 'note' => $pname . ($r['maker_name'] ? '（' . $r['maker_name'] . '）' : '')];
                if ($r['return_date']) $events[] = ['time' => $r['return_date'], 'type' => '回廠', 'category' => 'outsource', 'is_ng' => false, 'note' => $pname];
            }

            $st2 = $db->prepare("
                SELECT e.event_type, e.event_note, e.Created_At
                FROM bom_ing_event e JOIN bom_ing bi ON bi.bom_ing_fid = e.bom_ing_fid
                WHERE bi.bom = ?
            ");
            $st2->execute([$bom]);
            foreach ($st2->fetchAll(PDO::FETCH_ASSOC) as $r) {
                $events[] = ['time' => $r['Created_At'], 'type' => '事件:' . $r['event_type'], 'category' => 'event', 'is_ng' => false, 'note' => (string)$r['event_note']];
            }

            $st3 = $db->prepare("
                SELECT pdr.production_start_time, pdr.production_end_time, pdr.produced_qty, pdr.is_finished, pdr.remark, pn.ProcessName
                FROM pm_process_daily_report pdr
                JOIN bom_ing bi ON bi.bom_ing_fid = pdr.bom_ing_fid
                LEFT JOIN process_no pn ON pn.ProcessNo = pdr.process_no
                WHERE bi.bom = ?
            ");
            $st3->execute([$bom]);
            foreach ($st3->fetchAll(PDO::FETCH_ASSOC) as $r) {
                if ($r['production_end_time']) {
                    $note = ($r['ProcessName'] ?: '（未知製程）') . ' 完成數' . $r['produced_qty'] . ($r['is_finished'] ? '（本站完工）' : '');
                    if (trim((string)$r['remark']) !== '') $note .= '｜備註：' . $r['remark'];
                    $events[] = ['time' => $r['production_end_time'], 'type' => '報工', 'category' => 'report', 'is_ng' => false, 'note' => $note];
                }
            }

            $st4 = $db->prepare("
                SELECT qc.QC_check, qc.QC_check_date, qc.QC_ps, qc.QC_ps2, qc.QC_ps_aod, qc.QC_ps_ok,
                       qc.QC_ng_sqty, qc.QC_QQ_sqty, qc.QC_aod_sqty, qc.QC_ok_sqty
                FROM qc_check qc JOIN bom_ing bi ON bi.bom_ing_fid = qc.bom_ing_fid_ref
                WHERE bi.bom = ?
            ");
            $st4->execute([$bom]);
            foreach ($st4->fetchAll(PDO::FETCH_ASSOC) as $r) {
                if (!$r['QC_check_date']) continue;
                $checkLabel = ['ng' => '驗退', 'QQ' => '異常', 'ok' => '允收', 'AOD' => '特採'][$r['QC_check']] ?? (string)$r['QC_check'];
                $parts = [];
                if ($r['QC_ok_sqty']) $parts[] = '允收' . $r['QC_ok_sqty'];
                if ($r['QC_ng_sqty']) $parts[] = '驗退' . $r['QC_ng_sqty'];
                if ($r['QC_QQ_sqty']) $parts[] = '異常' . $r['QC_QQ_sqty'];
                if ($r['QC_aod_sqty']) $parts[] = '特採' . $r['QC_aod_sqty'];
                $note = $checkLabel . ($parts ? '（' . implode('、', $parts) . '）' : '');
                $remarkTxt = trim((string)($r['QC_ps2'] ?: $r['QC_ps'] ?: $r['QC_ps_aod'] ?: $r['QC_ps_ok'] ?: ''));
                if ($remarkTxt !== '') $note .= '｜備註：' . $remarkTxt;
                // NG判定：驗退狀態、或驗退數量>0，即使目前狀態欄位不是ng也視為需要特別標示
                $isNg = ($r['QC_check'] === 'ng') || ((int)$r['QC_ng_sqty'] > 0);
                $events[] = ['time' => $r['QC_check_date'], 'type' => 'QC檢驗', 'category' => 'qc', 'is_ng' => $isNg, 'note' => $note];
            }

            usort($events, function ($a, $b) { return strcmp((string)$a['time'], (string)$b['time']); });
            $response = ['success' => true, 'data' => $events];
        } catch (Throwable $e) { $response = ['success' => false, 'message' => $e->getMessage()]; }
        break;
    }

    // ── 多選搜尋（chip picker 用）──────────────────────────────────────
    case 'search_parts': {
        $kw = trim($_GET['kw'] ?? '');
        $st = $db->prepare("SELECT d_id, D_Setting_Id, Drawing_No FROM d_setting WHERE D_Setting_Id LIKE ? OR Drawing_No LIKE ? LIMIT 20");
        $st->execute(["%{$kw}%", "%{$kw}%"]);
        $response = ['success' => true, 'data' => $st->fetchAll(PDO::FETCH_ASSOC)];
        break;
    }

    case 'search_boms': {
        $kw = trim($_GET['kw'] ?? '');
        $st = $db->prepare("SELECT bom, d_id, Client_Name FROM bom WHERE bom LIKE ? ORDER BY bom DESC LIMIT 20");
        $st->execute(["%{$kw}%"]);
        $response = ['success' => true, 'data' => $st->fetchAll(PDO::FETCH_ASSOC)];
        break;
    }

    case 'search_customers': {
        $kw = trim($_GET['kw'] ?? '');
        $st = $db->prepare("SELECT customer_id, customer FROM customer_list WHERE (customer LIKE ? OR customer_id LIKE ?) AND is_inactive=0 LIMIT 20");
        $st->execute(["%{$kw}%", "%{$kw}%"]);
        $response = ['success' => true, 'data' => $st->fetchAll(PDO::FETCH_ASSOC)];
        break;
    }

    case 'search_sales_users':
    case 'search_users': {
        // user.user_cname/user_uname 是舊 latin1 欄位，中文關鍵字比對需先 CONVERT 否則報 3854 錯誤（專案已知限制）
        try {
            $kw = trim($_GET['kw'] ?? '');
            $st = $db->prepare("
                SELECT id, user_cname, user_uname FROM user
                WHERE state != 0 AND (CONVERT(user_cname USING utf8mb4) LIKE ? OR CONVERT(user_uname USING utf8mb4) LIKE ?)
                LIMIT 20
            ");
            $st->execute(["%{$kw}%", "%{$kw}%"]);
            $response = ['success' => true, 'data' => $st->fetchAll(PDO::FETCH_ASSOC)];
        } catch (Throwable $e) { $response = ['success' => false, 'message' => $e->getMessage()]; }
        break;
    }

    // ── 通知範圍 + 訂閱者 ────────────────────────────────────────────
    case 'get_notify_scopes': {
        $groupId = (int)($_GET['group_id'] ?? 0);
        if (!bt_can_access_group($db, $groupId, $user_id, $is_admin)) { $response = ['success' => false, 'message' => '無權限']; break; }
        $st = $db->prepare("SELECT * FROM bom_watch_notify_scope WHERE group_id=?");
        $st->execute([$groupId]);
        $response = ['success' => true, 'data' => $st->fetchAll(PDO::FETCH_ASSOC)];
        break;
    }

    // 開關「整個群組」或「單一BOM」的通知範圍，回傳 scope_id
    case 'toggle_notify_scope': {
        $groupId = (int)($_POST['group_id'] ?? 0);
        if (!bt_can_access_group($db, $groupId, $user_id, $is_admin)) { $response = ['success' => false, 'message' => '無權限']; break; }
        $scopeType = ($_POST['scope_type'] ?? '') === 'bom' ? 'bom' : 'group';
        $scopeBom = $scopeType === 'bom' ? trim($_POST['scope_bom'] ?? '') : null;
        $enable = !empty($_POST['enable']);
        try {
            if ($scopeType === 'bom') {
                $chk = $db->prepare("SELECT scope_id FROM bom_watch_notify_scope WHERE group_id=? AND scope_type='bom' AND scope_bom=?");
                $chk->execute([$groupId, $scopeBom]);
            } else {
                $chk = $db->prepare("SELECT scope_id FROM bom_watch_notify_scope WHERE group_id=? AND scope_type='group'");
                $chk->execute([$groupId]);
            }
            $existing = $chk->fetchColumn();
            if ($enable) {
                if ($existing) { $response = ['success' => true, 'scope_id' => (int)$existing]; break; }
                $db->prepare("INSERT INTO bom_watch_notify_scope (group_id, scope_type, scope_bom) VALUES (?,?,?)")
                    ->execute([$groupId, $scopeType, $scopeBom]);
                $response = ['success' => true, 'scope_id' => (int)$db->lastInsertId()];
            } else {
                if ($existing) {
                    $db->prepare("DELETE FROM bom_watch_subscriber WHERE scope_id=?")->execute([$existing]);
                    $db->prepare("DELETE FROM bom_watch_notify_scope WHERE scope_id=?")->execute([$existing]);
                }
                $response = ['success' => true];
            }
        } catch (Throwable $e) { $response = ['success' => false, 'message' => $e->getMessage()]; }
        break;
    }

    case 'get_subscribers': {
        $scopeId = (int)($_GET['scope_id'] ?? 0);
        $st = $db->prepare("SELECT id, target_type, user_id AS target_id FROM bom_watch_subscriber WHERE scope_id = ?");
        $st->execute([$scopeId]);
        $rows = $st->fetchAll(PDO::FETCH_ASSOC);
        $data = [];
        foreach ($rows as $r) {
            if ($r['target_type'] === 'dept') {
                $q = $db->prepare("SELECT name FROM department WHERE id=?");
                $q->execute([(int)$r['target_id']]);
                $data[] = ['code' => 'dept-' . $r['target_id'], 'type' => 'dept', 'label' => ($q->fetchColumn() ?: '（部門已刪除）') . '（整個部門）'];
            } else {
                $info = bt_user_display($db, (int)$r['target_id']);
                $label = $info ? ($info['user_cname'] . '（' . ($info['dept_name'] ?: '未指定') . '/' . ($info['pos_name'] ?: '未指定') . ($info['concurrent'] ? '，兼：' . implode('、', $info['concurrent']) : '') . '）') : ('使用者#' . $r['target_id']);
                $data[] = ['code' => 'user-' . $r['target_id'], 'type' => 'user', 'label' => $label];
            }
        }
        $response = ['success' => true, 'data' => $data];
        break;
    }

    // 整批覆蓋某 scope 的訂閱者（select2 送出時整包覆蓋，簡化前端邏輯）；codes 為 ["user-12","dept-3",...]
    case 'save_subscribers': {
        $scopeId = (int)($_POST['scope_id'] ?? 0);
        $codes = json_decode($_POST['codes'] ?? '[]', true);
        if (!is_array($codes)) { $response = ['success' => false, 'message' => 'codes 格式錯誤']; break; }
        try {
            $db->beginTransaction();
            $db->prepare("DELETE FROM bom_watch_subscriber WHERE scope_id=?")->execute([$scopeId]);
            $ins = $db->prepare("INSERT IGNORE INTO bom_watch_subscriber (scope_id, target_type, user_id) VALUES (?,?,?)");
            foreach ($codes as $code) {
                if (!preg_match('/^(user|dept)-(\d+)$/', $code, $m)) continue;
                $ins->execute([$scopeId, $m[1], (int)$m[2]]);
            }
            $db->commit();
            $response = ['success' => true];
        } catch (Throwable $e) {
            if ($db->inTransaction()) $db->rollBack();
            $response = ['success' => false, 'message' => $e->getMessage()];
        }
        break;
    }

    // ── 分享 ─────────────────────────────────────────────────────────
    case 'get_shares': {
        $groupId = (int)($_GET['group_id'] ?? 0);
        if (!bt_can_access_group($db, $groupId, $user_id, $is_admin)) { $response = ['success' => false, 'message' => '無權限']; break; }
        $st = $db->prepare("SELECT share_id, target_type, target_id FROM bom_watch_share WHERE group_id=?");
        $st->execute([$groupId]);
        $rows = $st->fetchAll(PDO::FETCH_ASSOC);
        $data = [];
        foreach ($rows as $r) {
            if ($r['target_type'] === 'dept') {
                $q = $db->prepare("SELECT name FROM department WHERE id=?");
                $q->execute([(int)$r['target_id']]);
                $deptName = $q->fetchColumn() ?: '（部門已刪除）';
                // 部門分享的「權限」以部門內是否至少一人有 bom_track 權限來判斷，僅供提示用
                $memberChk = $db->prepare("SELECT user_id FROM user_department_position_map WHERE department_id=?");
                $memberChk->execute([(int)$r['target_id']]);
                $hasAccess = false;
                foreach ($memberChk->fetchAll(PDO::FETCH_COLUMN) as $mid) {
                    if (rf_has_module_role($db, (int)$mid, 'bom_track')) { $hasAccess = true; break; }
                }
                $data[] = ['share_id' => $r['share_id'], 'code' => 'dept-' . $r['target_id'], 'type' => 'dept', 'label' => $deptName . '（整個部門）', 'has_access' => $hasAccess];
            } else {
                $info = bt_user_display($db, (int)$r['target_id']);
                $label = $info ? ($info['user_cname'] . '（' . ($info['dept_name'] ?: '未指定') . '/' . ($info['pos_name'] ?: '未指定') . ($info['concurrent'] ? '，兼：' . implode('、', $info['concurrent']) : '') . '）') : ('使用者#' . $r['target_id']);
                $data[] = ['share_id' => $r['share_id'], 'code' => 'user-' . $r['target_id'], 'type' => 'user', 'label' => $label, 'has_access' => rf_has_module_role($db, (int)$r['target_id'], 'bom_track')];
            }
        }
        $response = ['success' => true, 'data' => $data];
        break;
    }

    // 整批覆蓋群組的分享對象（select2 送出時整包覆蓋，與 save_subscribers 邏輯一致）；codes 為 ["user-12","dept-3",...]
    case 'save_share': {
        $groupId = (int)($_POST['group_id'] ?? 0);
        $codes = json_decode($_POST['codes'] ?? '[]', true);
        if (!bt_can_access_group($db, $groupId, $user_id, $is_admin)) { $response = ['success' => false, 'message' => '無權限']; break; }
        if (!is_array($codes)) { $response = ['success' => false, 'message' => 'codes 格式錯誤']; break; }
        try {
            $db->beginTransaction();
            $db->prepare("DELETE FROM bom_watch_share WHERE group_id=?")->execute([$groupId]);
            $ins = $db->prepare("INSERT IGNORE INTO bom_watch_share (group_id, target_type, target_id, shared_by) VALUES (?,?,?,?)");
            foreach ($codes as $code) {
                if (!preg_match('/^(user|dept)-(\d+)$/', $code, $m)) continue;
                $ins->execute([$groupId, $m[1], (int)$m[2], $user_id]);
            }
            $db->commit();
            $response = ['success' => true];
        } catch (Throwable $e) {
            if ($db->inTransaction()) $db->rollBack();
            $response = ['success' => false, 'message' => $e->getMessage()];
        }
        break;
    }

    case 'remove_share': {
        $shareId = (int)($_POST['share_id'] ?? 0);
        try {
            $st = $db->prepare("SELECT group_id FROM bom_watch_share WHERE share_id=?");
            $st->execute([$shareId]);
            $groupId = $st->fetchColumn();
            if ($groupId === false) { $response = ['success' => false, 'message' => '找不到分享紀錄']; break; }
            if (!bt_can_access_group($db, (int)$groupId, $user_id, $is_admin)) { $response = ['success' => false, 'message' => '無權限']; break; }
            $db->prepare("DELETE FROM bom_watch_share WHERE share_id=?")->execute([$shareId]);
            $response = ['success' => true];
        } catch (Throwable $e) { $response = ['success' => false, 'message' => $e->getMessage()]; }
        break;
    }

    default:
        $response = ['success' => false, 'message' => "未知的 action: {$action}"];
}

echo json_encode($response, JSON_UNESCAPED_UNICODE);
