<?php
/**
 * stock_roles_lib.php — 庫存管理（views/pages/stock.php）的角色制：功能清單、預設角色、通知對象設定
 *
 * 2026-10-16 建立；同日使用者指派完角色後，庫存頁的權限判定與通知對象已全面改成角色制
 * （stock_user_features()／stock_notify_targets()），不再讀 user_module_permissions 的 A/C/R/U/D。
 *
 * 角色（roles.module='stock'）：
 *   - 本站管理員  ＝全站既有的系統角色 admin（roles.is_system=1、功能碼 'all'），每個模組都會自動列出、
 *                   自動擁有全部功能，這裡不重複建立（重複建一個會讓管理員要指派兩次）。
 *   - 本頁管理員  stock_admin（ai-rules/26：<module>_admin 就是本頁管理員）
 *   - 生管        stock_pm
 *   - 業務        stock_sales
 *   - 唯讀        stock_viewer
 *   後四個建立時都是空的功能清單（使用者要求「其他角色都需要設定權限」），由本站管理員在庫存頁的
 *   「角色與通知設定」勾選。
 */

if (!function_exists('stock_feature_catalog')) {

/**
 * 功能清單（依種類分組）。code 一律 stock_ 開頭。
 * legacy＝目前（ACRUD 制）這項功能需要的權限，僅供「之後改成角色判定」時對照，也顯示在設定畫面方便挑選。
 */
function stock_feature_catalog(): array {
    $g = function (string $group, array $items): array {
        $o = [];
        foreach ($items as $i) $o[] = ['group' => $group, 'code' => $i[0], 'label' => $i[1], 'legacy' => $i[2] ?? ''];
        return $o;
    };
    return array_merge(
        $g('瀏覽與查詢', [
            ['stock_view',          '檢視庫存列表與品項明細', 'R'],
            ['stock_analysis',      '分析報表', 'R'],
            ['stock_report',        '入出庫紀錄', 'R'],
            ['stock_view_setting',  '檢視主資料設定頁（唯讀）', 'R'],
        ]),
        $g('庫存異動', [
            ['stock_add',           '新增庫存', 'C'],
            ['stock_batch_add',     '批次新增庫存', 'C'],
            ['stock_in',            '入庫', 'C'],
            ['stock_out',           '出庫', 'C'],
            ['stock_move',          '移位（庫存搬到其他儲位）', 'U'],
            ['stock_edit',          '編輯庫存品項', 'U'],
            ['stock_delete_txn',    '刪除入／出庫異動紀錄', 'D'],
            ['stock_purge',         '強制刪除庫存品項', 'A'],
            ['stock_split_txn',     '拆分異動歷程', 'A'],
        ]),
        $g('組合件與批次', [
            ['stock_group_create',  '新增組合件', 'C'],
            ['stock_group_adjust',  '組合件調整', 'U'],
            ['stock_batch_ops',     '多選批次：合併為組合件、設定單位', 'U'],
        ]),
        $g('庫存盤點', [
            ['stock_count',         '進入盤點分頁、建立盤點單與盤點登錄', 'A 或 CRUD 全有'],
            ['stock_count_apply',   '套用盤點差異調整庫存', 'A 或 CRUD 全有'],
            ['stock_count_delete',  '刪除盤點單', 'A 或 CRUD 全有'],
        ]),
        $g('主資料設定－儲位', [
            ['stock_loc_add',       '新增儲位', 'C'],
            ['stock_loc_edit',      '編輯／整批移動儲位', 'U'],
            ['stock_loc_delete',    '刪除儲位', 'D'],
        ]),
        $g('主資料設定－種類', [
            ['stock_cat_add',       '新增庫存種類', 'C'],
            ['stock_cat_edit',      '編輯庫存種類（含種類的儲位設定）', 'U'],
            ['stock_cat_delete',    '刪除庫存種類', 'D'],
        ]),
        $g('主資料設定－單位', [
            ['stock_unit_add',      '新增單位', 'C'],
            ['stock_unit_edit',     '編輯單位／品項單位對照', 'U'],
            ['stock_unit_delete',   '刪除單位', 'D'],
        ]),
        $g('主資料設定－安全庫存', [
            ['stock_safety_add',    '新增安全庫存', 'C'],
            ['stock_safety_edit',   '編輯安全庫存', 'U'],
            ['stock_safety_delete', '刪除安全庫存', 'D'],
        ]),
        $g('主資料設定－廠區', [
            ['stock_area_add',      '新增廠區', 'C'],
            ['stock_area_edit',     '編輯廠區', 'U'],
            ['stock_area_delete',   '刪除廠區', 'D'],
        ]),
        $g('主資料設定－檢查工具', [
            ['stock_unbound_check', '料號綁定檢查（批次補綁庫存品項的料號主檔）', 'A'],
        ]),
        $g('領庫需求單', [
            ['stock_req_view',      '檢視領庫需求單', 'R'],
            ['stock_req_create',    '新增領庫需求單', 'C'],
            ['stock_req_edit_all',  '修改／刪除他人建立的需求單（自己建立的一律可改）', 'A 或 CRUD 全有'],
            ['stock_req_issue',     '依需求單出庫（發料）', 'A 或 CRUD 全有'],
            ['stock_req_view_deleted', '查看已刪除的需求單', 'R'],
            ['stock_req_print',     '列印需求單、清單彙總', 'R'],
            ['stock_req_print_setting', '列印設定（AS 文件編號綁定、倉管圖章模板）', 'A'],
        ]),
        $g('列印', [
            ['stock_doc_print',     '列印倉庫庫存表、入庫單', 'R'],
        ]),
        $g('通知設定', [
            ['stock_notify_setting','設定各項通知要通知哪些角色', '（新功能）'],
        ])
    );
}

/** 預設要建立的角色（本站管理員是全站系統角色，不在此列） */
function stock_default_roles(): array {
    return [
        ['stock_admin',  '本頁管理員', '庫存管理頁的管理員（與全站管理員權力相同範圍僅限本頁，見 ai-rules/26）'],
        ['stock_pm',     '生管',       '生管：預設無任何功能，由本站管理員設定'],
        ['stock_sales',  '業務',       '業務：預設無任何功能，由本站管理員設定'],
        ['stock_purchase','採購',      '採購：初始功能同生管，由本站管理員設定'],
        ['stock_viewer', '唯讀',       '唯讀：預設無任何功能，由本站管理員設定'],
    ];
}

/**
 * 確保預設角色存在（可重複執行；已存在的一律不動，不覆寫名稱與功能設定，免得蓋掉管理員的調整）。
 * 只新增 roles 列，不寫 role_features，也不指派給任何人，所以對現有使用者零影響。
 */
function stock_ensure_roles(PDO $pdo): void {
    try {
        $sel = $pdo->prepare("SELECT role_id FROM roles WHERE role_code=? LIMIT 1");
        $ins = $pdo->prepare("INSERT INTO roles (role_code, role_name, module, is_system, note) VALUES (?,?,?,0,?)");
        foreach (stock_default_roles() as $r) {
            $sel->execute([$r[0]]);
            if (!$sel->fetchColumn()) $ins->execute([$r[0], $r[1], 'stock', $r[2]]);
        }
    } catch (Throwable $e) {}
}

/** 是不是本站管理員（持有功能碼 'all' 的角色；與 Roles_API 的 isAdmin 同一個判定） */
function stock_is_site_admin(PDO $pdo, int $uid): bool {
    if ($uid <= 0) return false;
    try {
        if ($uid === 1) return true;
        $st = $pdo->prepare("SELECT 1 FROM user_roles ur JOIN role_features rf ON rf.role_id=ur.role_id
                             WHERE ur.user_id=? AND rf.feature_code='all' LIMIT 1");
        $st->execute([$uid]);
        return (bool)$st->fetchColumn();
    } catch (Throwable $e) { return false; }
}

/** 這個人有沒有某個庫存功能碼（本站管理員視為全有）。目前僅用在新增的「通知設定」，不影響既有功能。 */
function stock_has_feature(PDO $pdo, int $uid, string $code): bool {
    if (stock_is_site_admin($pdo, $uid)) return true;
    try {
        $f = __DIR__ . '/role_features_helper.php';
        if (is_file($f)) require_once $f;
        if (function_exists('rf_load_user_features_all')) {
            $feat = rf_load_user_features_all($pdo, $uid);
            return in_array('all', $feat, true) || in_array($code, $feat, true);
        }
    } catch (Throwable $e) {}
    return false;
}

// ── 通知對象設定 ───────────────────────────────────────────────────────

/** 本頁會發出的通知種類（key 固定，新增通知時在這裡加一列即可） */
function stock_notify_types(): array {
    // creator＝是否提供「通知該需求單建立者」選項；creator_default＝沒設定過時的預設（刪除單原本就一律通知建立者，維持原行為）
    return [
        ['key' => 'req_new',      'label' => '新增領庫需求單', 'creator' => false, 'creator_default' => false],
        ['key' => 'req_modified', 'label' => '修改領庫需求單', 'creator' => true,  'creator_default' => false],
        ['key' => 'req_deleted',  'label' => '刪除領庫需求單', 'creator' => true,  'creator_default' => true],
    ];
}

function stock_notify_cfg_load(PDO $pdo): array {
    $out = [];
    foreach (stock_notify_types() as $t) $out[$t['key']] = ['roles' => [], 'creator' => (bool)$t['creator_default']];
    try {
        $st = $pdo->prepare("SELECT param_key, param_value FROM system_parameters WHERE param_group='STOCK_NOTIFY'");
        $st->execute();
        foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $r) {
            if (strpos($r['param_key'], 'roles_') === 0) {
                $k = substr($r['param_key'], 6);
                if (!isset($out[$k])) continue;
                $ids = json_decode((string)$r['param_value'], true);
                $out[$k]['roles'] = is_array($ids) ? array_values(array_unique(array_filter(array_map('intval', $ids)))) : [];
            } elseif (strpos($r['param_key'], 'creator_') === 0) {
                $k = substr($r['param_key'], 8);
                if (isset($out[$k])) $out[$k]['creator'] = ((string)$r['param_value'] === '1');
            }
        }
    } catch (Throwable $e) {}
    return $out;
}

/** 儲存；只收「庫存模組的角色＋系統管理員角色」，其他 id 一律丟掉。cfg[key] = {roles:[], creator:bool}（也相容舊的純陣列） */
function stock_notify_cfg_save(PDO $pdo, array $cfg, string $by): void {
    $valid = [];
    foreach (stock_assignable_roles($pdo) as $r) $valid[(int)$r['role_id']] = true;
    $sel = $pdo->prepare("SELECT id FROM system_parameters WHERE param_group='STOCK_NOTIFY' AND param_key=? LIMIT 1");
    $upd = $pdo->prepare("UPDATE system_parameters SET param_value=?, updated_by=? WHERE id=?");
    $ins = $pdo->prepare("INSERT INTO system_parameters (param_group,param_key,param_value,description,updated_by) VALUES ('STOCK_NOTIFY',?,?,?,?)");
    $put = function (string $key, string $val, string $desc) use ($sel, $upd, $ins, $by) {
        $sel->execute([$key]);
        $id = $sel->fetchColumn();
        if ($id) $upd->execute([$val, $by, $id]); else $ins->execute([$key, $val, $desc, $by]);
    };
    foreach (stock_notify_types() as $t) {
        $one   = $cfg[$t['key']] ?? [];
        $roles = (isset($one['roles']) && is_array($one['roles'])) ? $one['roles'] : (is_array($one) && !isset($one['creator']) ? $one : []);
        $ids = [];
        foreach ($roles as $rid) { $rid = (int)$rid; if ($rid > 0 && isset($valid[$rid])) $ids[] = $rid; }
        $put('roles_' . $t['key'], json_encode(array_values(array_unique($ids))), '庫存通知對象角色：' . $t['label']);
        if ($t['creator']) $put('creator_' . $t['key'], !empty($one['creator']) ? '1' : '0', '庫存通知是否通知建立者：' . $t['label']);
    }
}

/** 通知時可勾選的角色：全站系統角色（本站管理員）＋庫存模組角色 */
function stock_assignable_roles(PDO $pdo): array {
    try {
        return $pdo->query("SELECT role_id, role_name, is_system FROM roles
                            WHERE is_system=1 OR module='stock' ORDER BY is_system DESC, role_id ASC")->fetchAll(PDO::FETCH_ASSOC);
    } catch (Throwable $e) { return []; }
}

/**
 * 角色 → 在職人員 id（個人指派 ∪ 部門＋職稱指派；只列 user.state=1）。
 * 用在「預覽目前設定會通知到誰」，以及日後把通知改成角色制時的收件人解析。
 */
function stock_notify_role_users(PDO $pdo, array $roleIds): array {
    $roleIds = array_values(array_unique(array_filter(array_map('intval', $roleIds))));
    if (!$roleIds) return [];
    $ph = implode(',', array_fill(0, count($roleIds), '?'));
    try {
        $sql = "SELECT ur.user_id AS uid FROM user_roles ur JOIN `user` u ON u.id=ur.user_id AND u.state=1
                WHERE ur.role_id IN ($ph)
                UNION
                SELECT m.user_id AS uid FROM user_department_position_map m
                JOIN position_roles pr ON pr.position_id=m.position_id AND (pr.department_id=0 OR pr.department_id=m.department_id)
                JOIN `user` u ON u.id=m.user_id AND u.state=1
                WHERE pr.role_id IN ($ph)";
        $st = $pdo->prepare($sql);
        $st->execute(array_merge($roleIds, $roleIds));
        return array_map('intval', $st->fetchAll(PDO::FETCH_COLUMN));
    } catch (Throwable $e) { return []; }
}

/** 預覽用：把 user id 轉成「部門 職稱 姓名」，依 部門→職稱→姓名 排序（人員列表鐵則） */
function stock_notify_user_labels(PDO $pdo, array $uids): array {
    $uids = array_values(array_unique(array_filter(array_map('intval', $uids))));
    if (!$uids) return [];
    $ph = implode(',', array_fill(0, count($uids), '?'));
    try {
        $st = $pdo->prepare(
            "SELECT u.id, u.user_cname, d.name AS dept, p.name AS pos,
                    COALESCE(d.sort_order,999) ds, COALESCE(p.sort_order,999) ps
             FROM `user` u
             LEFT JOIN user_department_position_map m ON m.id = (
                 SELECT m2.id FROM user_department_position_map m2 LEFT JOIN position p2 ON p2.id=m2.position_id
                 WHERE m2.user_id=u.id ORDER BY COALESCE(p2.sort_order,999) ASC, m2.is_main DESC, m2.id ASC LIMIT 1)
             LEFT JOIN department d ON d.id=m.department_id
             LEFT JOIN position p ON p.id=m.position_id
             WHERE u.id IN ($ph)
             ORDER BY ds, ps, CONVERT(u.user_cname USING utf8mb4), u.id");
        $st->execute($uids);
        $o = [];
        foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $r)
            $o[] = trim(((string)$r['dept']) . ' ' . ((string)$r['pos']) . ' ' . ((string)$r['user_cname']));
        return $o;
    } catch (Throwable $e) { return []; }
}

// ── 角色制權限判定（2026-10-16 起庫存頁一律用這裡，不再讀 user_module_permissions）──────

/** 這個人的庫存功能碼：本站管理員＝['all']；否則取個人指派∪部門職稱指派（含代理承接），只留 stock_ 開頭 */
function stock_user_features(PDO $pdo, int $uid): array {
    if ($uid <= 0) return [];
    if (stock_is_site_admin($pdo, $uid)) return ['all'];
    try {
        $f = __DIR__ . '/role_features_helper.php';
        if (is_file($f)) require_once $f;
        if (!function_exists('rf_load_user_features_all')) return [];
        $out = [];
        foreach (rf_load_user_features_all($pdo, $uid) as $c) {
            if ($c === 'all' || strpos($c, 'stock_') === 0) $out[] = $c;
        }
        return array_values(array_unique($out));
    } catch (Throwable $e) { return []; }
}

function stock_feat_has(array $feats, string $code): bool {
    return in_array('all', $feats, true) || in_array($code, $feats, true);
}
function stock_feat_any(array $feats, array $codes): bool {
    foreach ($codes as $c) if (stock_feat_has($feats, $c)) return true;
    return false;
}

/** 顯示用：這個人持有的庫存角色名稱（個人指派＋部門職稱指派；本站管理員顯示為「本站管理員」） */
function stock_user_role_labels(PDO $pdo, int $uid): array {
    $o = [];
    try {
        $st = $pdo->prepare("SELECT r.role_name, r.is_system FROM user_roles ur JOIN roles r ON r.role_id=ur.role_id
                             WHERE ur.user_id=? AND (r.module='stock' OR r.is_system=1)");
        $st->execute([$uid]);
        foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $r) $o[] = ((int)$r['is_system'] === 1) ? '本站管理員' : $r['role_name'];
        $st = $pdo->prepare("SELECT DISTINCT r.role_name FROM user_department_position_map m
                             JOIN position_roles pr ON pr.position_id=m.position_id AND (pr.department_id=0 OR pr.department_id=m.department_id)
                             JOIN roles r ON r.role_id=pr.role_id AND r.module='stock' WHERE m.user_id=?");
        $st->execute([$uid]);
        foreach ($st->fetchAll(PDO::FETCH_COLUMN) as $n) $o[] = $n;
    } catch (Throwable $e) {}
    return array_values(array_unique($o));
}

/**
 * 通知收件人（依「通知對象」設定）：勾選的角色（在職者）＋（有開啟且有給建立者時）需求單建立者。
 * 操作者本人由 stock_req_notify() 統一剔除。
 */
function stock_notify_targets(PDO $pdo, string $key, ?int $creatorId = null): array {
    $cfg = stock_notify_cfg_load($pdo);
    $c = $cfg[$key] ?? ['roles' => [], 'creator' => false];
    $uids = stock_notify_role_users($pdo, $c['roles']);
    if (!empty($c['creator']) && $creatorId) {
        try {
            $st = $pdo->prepare("SELECT 1 FROM `user` WHERE id=? AND state=1");
            $st->execute([$creatorId]);
            if ($st->fetchColumn()) $uids[] = (int)$creatorId;
        } catch (Throwable $e) {}
    }
    return array_values(array_unique(array_map('intval', $uids)));
}

/**
 * 各 AJAX 動作需要的功能碼（任一即可）。回傳 [] ＝只要能進本頁（stock_view）即可（查詢、搜尋、下拉資料等）。
 * 需求單的「建立者自己可改自己的」例外在各動作內部另外判斷，不放在這裡。
 */
function stock_action_need(string $a, array $p): array {
    $id = function (string $k) use ($p) { return (int)($p[$k] ?? 0) > 0; };
    switch ($a) {
        case 'merge_stock': case 'batch_update_unit': case 'merge_existing_to_group': return ['stock_batch_ops'];
        case 'purge_stock_item': case 'delete_stock_item': return ['stock_purge'];
        case 'save_stock_item':  return [$id('stock_item_id') ? 'stock_edit' : 'stock_add'];
        case 'batch_save_stock': return ['stock_batch_add'];
        case 'quick_in':   return ['stock_in'];
        case 'quick_out':  return ['stock_out'];
        case 'quick_move': return ['stock_move'];
        case 'delete_transaction': return ['stock_delete_txn'];
        case 'split_txn_items':    return ['stock_split_txn'];
        case 'save_cat_locations': return ['stock_cat_edit'];
        case 'save_location':  return [$id('location_id') ? 'stock_loc_edit' : 'stock_loc_add'];
        case 'move_location':  return ['stock_loc_edit'];
        case 'delete_location':return ['stock_loc_delete'];
        case 'save_category':  return [$id('category_id') ? 'stock_cat_edit' : 'stock_cat_add'];
        case 'delete_category':return ['stock_cat_delete'];
        case 'save_unit':      return [$id('unit_id') ? 'stock_unit_edit' : 'stock_unit_add'];
        case 'save_item_units':return ['stock_unit_edit'];
        case 'delete_unit':    return ['stock_unit_delete'];
        case 'save_safety_stock':   return ['stock_safety_add', 'stock_safety_edit'];   // 以料號為鍵的新增或更新
        case 'delete_safety_stock': return ['stock_safety_delete'];
        case 'save_area':   return [$id('area_id') ? 'stock_area_edit' : 'stock_area_add'];
        case 'delete_area': return ['stock_area_delete'];
        case 'unbound_parts_list': case 'unbound_parts_bind': return ['stock_unbound_check'];
        case 'create_count_session': case 'get_count_sessions': case 'get_count_details':
        case 'save_count_detail': case 'random_count_sample': return ['stock_count'];
        case 'apply_count_adjustments': return ['stock_count_apply'];
        case 'delete_count_session':    return ['stock_count_delete'];
        case 'get_analysis': case 'get_aging_detail': return ['stock_analysis'];
        case 'get_daily_report': return ['stock_report'];
        case 'save_stock_group': return ['stock_group_create'];
        case 'group_adjust':     return ['stock_group_adjust'];
        case 'group_txn':        return ['stock_in', 'stock_out', 'stock_group_adjust'];
        case 'create_requisition': return ['stock_req_create'];
        case 'get_requisitions': case 'get_req_detail': return ['stock_req_view'];
        case 'get_deleted_requisitions': return ['stock_req_view_deleted'];
        case 'issue_requisition': return ['stock_req_issue'];
        case 'req_get_batches':   return ['stock_req_issue', 'stock_req_view'];
        case 'print_requisitions': case 'req_print_meta': return ['stock_req_print'];
        case 'req_print_setting_get': case 'req_print_setting_save': return ['stock_req_print_setting'];
        case 'stock_doc_print_meta': case 'get_stock_in_sheet': case 'get_stock_in_days': return ['stock_doc_print'];
    }
    return [];
}

}
