<?php
/**
 * stock_roles_lib.php — 庫存管理（views/pages/stock.php）的角色制：功能清單、預設角色、通知對象設定
 *
 * 2026-10-16 建立。**目前只建立角色與設定，尚未用來判定權限**：頁面與 API 仍沿用
 * user_module_permissions 的 A/C/R/U/D（使用者指派完角色後，會再一次改成角色判定）。
 * 所以本檔的任何東西都不可以影響現有使用者的操作與通知。
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
            ['stock_recalc',        '重算成本／售價快照', 'A'],
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
    return [
        ['key' => 'req_new',      'label' => '新增領庫需求單',
         'legacy' => '庫存權限為 A，或同時有 C、R、U、D 的人（不含操作者本人）', 'creator' => false],
        ['key' => 'req_modified', 'label' => '修改領庫需求單',
         'legacy' => '庫存權限為 A，或同時有 C、R、U、D 的人（不含操作者本人）', 'creator' => false],
        ['key' => 'req_deleted',  'label' => '刪除領庫需求單',
         'legacy' => '只通知該需求單的建立者（建立者自己刪除時不通知）', 'creator' => true],
    ];
}

function stock_notify_cfg_load(PDO $pdo): array {
    $out = [];
    foreach (stock_notify_types() as $t) $out[$t['key']] = [];
    try {
        $st = $pdo->prepare("SELECT param_key, param_value FROM system_parameters WHERE param_group='STOCK_NOTIFY'");
        $st->execute();
        foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $r) {
            if (strpos($r['param_key'], 'roles_') !== 0) continue;
            $k = substr($r['param_key'], 6);
            if (!array_key_exists($k, $out)) continue;
            $ids = json_decode((string)$r['param_value'], true);
            $out[$k] = is_array($ids) ? array_values(array_unique(array_filter(array_map('intval', $ids)))) : [];
        }
    } catch (Throwable $e) {}
    return $out;
}

/** 儲存；只收「庫存模組的角色＋系統管理員角色」，其他 id 一律丟掉 */
function stock_notify_cfg_save(PDO $pdo, array $cfg, string $by): void {
    $valid = [];
    foreach (stock_assignable_roles($pdo) as $r) $valid[(int)$r['role_id']] = true;
    $sel = $pdo->prepare("SELECT id FROM system_parameters WHERE param_group='STOCK_NOTIFY' AND param_key=? LIMIT 1");
    $upd = $pdo->prepare("UPDATE system_parameters SET param_value=?, updated_by=? WHERE id=?");
    $ins = $pdo->prepare("INSERT INTO system_parameters (param_group,param_key,param_value,description,updated_by) VALUES ('STOCK_NOTIFY',?,?,?,?)");
    foreach (stock_notify_types() as $t) {
        $ids = [];
        foreach ((array)($cfg[$t['key']] ?? []) as $rid) { $rid = (int)$rid; if ($rid > 0 && isset($valid[$rid])) $ids[] = $rid; }
        $json = json_encode(array_values(array_unique($ids)));
        $key  = 'roles_' . $t['key'];
        $sel->execute([$key]);
        $id = $sel->fetchColumn();
        if ($id) $upd->execute([$json, $by, $id]);
        else $ins->execute([$key, $json, '庫存通知對象角色：' . $t['label'], $by]);
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

}
