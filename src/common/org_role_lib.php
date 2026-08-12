<?php
/**
 * 組織角色綁定 —— 全站共用（2026-07-31 建立，使用者明確要求「統一設定」）
 *
 * 解決的問題：很多頁面都需要知道「哪一個部門是人事部門／品管部門／業務部門」、
 * 「最高核准人員是誰」、「管理代表是誰」，過去每頁各自寫死部門 id 或人名，
 * 一調整組織就要翻全站改程式。**一律改用本庫查，設定集中在 `views/admin/org_role_setting.php`。**
 *
 * 用法：
 *   include_once __DIR__.'/org_role_lib.php';
 *   $hrDeptId = eg_org_dept($db, 'hr_dept');            // 人事部門 department.id（未設定回 null）
 *   $approver = eg_org_user($db, 'top_approver');       // 最高核准人員 ['id','user_cname',...]
 *   $mgr      = eg_org_dept_manager($db, $hrDeptId);    // 該部門的部門主管（職級最高者，優先指定負責人）
 *
 * 綁定值存 `org_role_binding`（role_key 唯一）；只存 id，不存名稱。
 */
/** 可綁定的角色目錄：type=dept 綁部門、type=user 綁人員 */
if (!defined('EG_ORG_ROLES')) define('EG_ORG_ROLES', [
    // ── 部門類（「系統認定的某某部門」）──
    'hr_dept'       => ['label'=>'人事／管理部門', 'type'=>'dept', 'desc'=>'教育訓練、人事表單的主辦部門；訓練計畫表的「審核」＝此部門主管'],
    'qc_dept'       => ['label'=>'品管部門',       'type'=>'dept', 'desc'=>'檢驗、異常、供應商稽核等品質作業'],
    'sales_dept'    => ['label'=>'業務部門',       'type'=>'dept', 'desc'=>'報價、訂單、客訴'],
    'pm_dept'       => ['label'=>'生管部門',       'type'=>'dept', 'desc'=>'排程、發包、交期'],
    'purchase_dept' => ['label'=>'採購部門',       'type'=>'dept', 'desc'=>'請購、採購、供應商'],
    'acc_dept'      => ['label'=>'會計部門',       'type'=>'dept', 'desc'=>'應收應付、對帳、發票'],
    'wh_dept'       => ['label'=>'倉管部門',       'type'=>'dept', 'desc'=>'入庫、出庫、庫存盤點、料件保管'],
    'prod_dept'     => ['label'=>'生產部門',       'type'=>'dept', 'desc'=>'現場製造'],
    'rd_dept'       => ['label'=>'設計／技術部門', 'type'=>'dept', 'desc'=>'圖面、技術文件'],
    'doc_dept'      => ['label'=>'文管中心',       'type'=>'dept', 'desc'=>'AS9100 文件管制'],
    'material_dept' => ['label'=>'資材部門',       'type'=>'dept', 'desc'=>'資材課（生管／採購／倉管彙總）；產品開發評估表等表單的「資材課」欄使用，2026-08-12 新增'],
    // ── 人員類（「系統認定的某某人」）──
    'top_approver'  => ['label'=>'最高核准人員', 'type'=>'user', 'desc'=>'各式表單最後一關「核准」欄的簽章人（多數表單都是同一人）'],
    'mgmt_rep'      => ['label'=>'管理代表',     'type'=>'user', 'desc'=>'AS9100 管理代表'],
    'hr_signer'     => ['label'=>'人事簽章人員', 'type'=>'user', 'desc'=>'人事表單「人事」欄的簽章人；應同時具備 HR 相關頁面的管理員權限'],
    'hr_reviewer'   => ['label'=>'人事表單審核者', 'type'=>'user', 'desc'=>'人事表單「審核」欄；留空＝自動取「人事／管理部門」的部門主管'],
    // ── 部門或人員擇一類（「部門內任一主管，或固定某關鍵人員，都未設定則自動判斷」）──
    'vendor_audit_plan_approver' => ['label'=>'供應商稽核計劃核准', 'type'=>'dept_or_user',
        'desc'=>'年度稽核計劃送出後的核准人。綁部門＝該部門(含子部門)內任一主管皆可核准(核准人職級不可低於送出者)；綁人員＝固定該人核准；兩者都未設定＝自動依「供應商稽核計劃」目前綁定的 AS 文件(2-PH-01-06)所屬部門，套用同一套「部門內任一主管」規則。'],
]);

if (!function_exists('eg_org_ensure_schema')) {

function eg_org_ensure_schema(PDO $db): void {
    try {
        $db->exec("CREATE TABLE IF NOT EXISTS org_role_binding (
            role_key VARCHAR(40) NOT NULL PRIMARY KEY COMMENT '見 EG_ORG_ROLES',
            dept_id INT NULL COMMENT 'type=dept 時的 department.id',
            user_id INT NULL COMMENT 'type=user 時的 user.id',
            include_sub TINYINT NOT NULL DEFAULT 1 COMMENT '1=連同該部門底下所有子部門一併認列',
            note VARCHAR(150) NULL,
            updated_at DATETIME NULL,
            updated_by VARCHAR(50) NULL
        ) DEFAULT CHARSET=utf8mb4 COMMENT='全站組織角色綁定(哪個部門是人事部門/誰是最高核准人)'");
        try { $db->exec("ALTER TABLE org_role_binding ADD COLUMN include_sub TINYINT NOT NULL DEFAULT 1
                         COMMENT '1=連同該部門底下所有子部門一併認列' AFTER user_id"); } catch (Throwable $e) {}
    } catch (Throwable $e) {}
}

/**
 * 某部門＋其底下所有子部門的 id（含自己）。
 * 組織是樹狀（department.parent_id）：品管部底下還有品管組、資材部底下有生管/採購/倉管組，
 * 綁「品管部」時多數情境要連品管組的人一起算，所以判定一律用本函式展開，不要只比對單一 id。
 */
function eg_dept_subtree_ids(PDO $db, ?int $deptId): array {
    if (!$deptId) return [];
    try {
        $st = $db->prepare("WITH RECURSIVE t AS (
                                SELECT id FROM department WHERE id=?
                                UNION ALL SELECT d.id FROM department d JOIN t ON d.parent_id=t.id)
                            SELECT id FROM t");
        $st->execute([$deptId]);
        return array_map('intval', $st->fetchAll(PDO::FETCH_COLUMN));
    } catch (Throwable $e) { return [$deptId]; }
}

/** 全部綁定：role_key => ['dept_id'=>?, 'user_id'=>?, 'note'=>?] */
function eg_org_bindings(PDO $db): array {
    $out = [];
    try {
        foreach ($db->query("SELECT * FROM org_role_binding")->fetchAll(PDO::FETCH_ASSOC) as $r)
            $out[$r['role_key']] = $r;
    } catch (Throwable $e) {}
    return $out;
}

/** 某角色綁定的部門 id（未設定回 null） */
function eg_org_dept(PDO $db, string $key): ?int {
    try {
        $st = $db->prepare("SELECT dept_id FROM org_role_binding WHERE role_key=?");
        $st->execute([$key]);
        $v = $st->fetchColumn();
        return $v ? (int)$v : null;
    } catch (Throwable $e) { return null; }
}

/**
 * 某角色「認列」的部門 id 陣列（未設定回空陣列）。
 * 預設含子部門（org_role_binding.include_sub=1）：綁「品管部」＝品管部＋品管組都算品管部門。
 * **凡是要判斷「這個人／這張單屬不屬於品管部門」一律用本函式，不要用 eg_org_dept() 的單一 id 去比對**，
 * 否則子部門（品管組、生管組、倉管組…）的人會被判成「不是該部門」。
 */
function eg_org_dept_ids(PDO $db, string $key): array {
    try {
        $st = $db->prepare("SELECT dept_id, COALESCE(include_sub,1) AS inc FROM org_role_binding WHERE role_key=?");
        $st->execute([$key]);
        $r = $st->fetch(PDO::FETCH_ASSOC);
        if (!$r || empty($r['dept_id'])) return [];
        return (int)$r['inc'] ? eg_dept_subtree_ids($db, (int)$r['dept_id']) : [(int)$r['dept_id']];
    } catch (Throwable $e) { return []; }
}

/** 某部門 id 是否被認列為該角色（含子部門判定） */
function eg_org_in_dept(PDO $db, string $key, ?int $deptId): bool {
    return $deptId && in_array((int)$deptId, eg_org_dept_ids($db, $key), true);
}

/** 某角色綁定的人員（回 user 列或 null；只回在職者，離職/特殊帳號視同未設定） */
function eg_org_user(PDO $db, string $key): ?array {
    try {
        $st = $db->prepare("SELECT u.id, u.user_cname, u.user_uname, u.state
                            FROM org_role_binding b JOIN user u ON u.id=b.user_id
                            WHERE b.role_key=? AND COALESCE(u.state,1) NOT IN (0,90)");
        $st->execute([$key]);
        return $st->fetch(PDO::FETCH_ASSOC) ?: null;
    } catch (Throwable $e) { return null; }
}

/**
 * 某部門的「部門主管」：該部門內職級最高（position_level.level 最小）者；
 * 同級時優先取 department_position.primary_user_id（指定負責人）。找不到回 null。
 * 判定邏輯比照 delegate_lib 的上一級主管解析，不另外發明規則。
 * $dept 可傳 int（單一部門）或 int[]（含子部門，用 eg_org_dept_ids() 取得）——
 * 傳陣列時取整個子樹裡職級最高的人（例：品管部沒設職級、品管組組長有設，就抓得到組長）。
 */
function eg_org_dept_manager(PDO $db, $dept): ?array {
    $ids = is_array($dept) ? array_values(array_filter(array_map('intval', $dept))) : ($dept ? [(int)$dept] : []);
    if (!$ids) return null;
    $in = implode(',', array_fill(0, count($ids), '?'));
    try {
        $st = $db->prepare("SELECT u.id, u.user_cname, p.name AS position_name, pl.level,
                                   (SELECT dp.primary_user_id FROM department_position dp
                                     WHERE dp.department_id=m.department_id AND dp.position_id=m.position_id LIMIT 1) AS primary_uid
                            FROM user_department_position_map m
                            JOIN user u ON u.id=m.user_id
                            LEFT JOIN position p ON p.id=m.position_id
                            LEFT JOIN position_level pl ON pl.position_id=m.position_id
                            WHERE m.department_id IN ($in) AND COALESCE(u.state,1) NOT IN (0,90)
                              AND pl.level IS NOT NULL
                            ORDER BY pl.level ASC, (primary_uid = u.id) DESC, u.id
                            LIMIT 1");
        $st->execute($ids);
        return $st->fetch(PDO::FETCH_ASSOC) ?: null;
    } catch (Throwable $e) { return null; }
}

/**
 * 某部門（可含子部門）的**所有主管**，依職級由大到小排序（經理→課長→組長）。
 * 「主管」＝該職稱在 position_level 有設 level（目前僅經理/副理/課長/副課長/組長/副組長有），
 * 所以同一個部門會有多位（課長、副課長、組長…）——**要讓使用者自己挑哪一位簽章時用本函式**；
 * 只要一位（自動推算部門主管）時用 eg_org_dept_manager()。
 * $dept 傳 int 或 int[]（含子部門請用 eg_org_dept_ids() 展開）。
 * 回傳每列：id, user_cname, position_id, position_name, level, department_id
 */
function eg_org_dept_managers(PDO $db, $dept): array {
    $ids = is_array($dept) ? array_values(array_filter(array_map('intval', $dept))) : ($dept ? [(int)$dept] : []);
    if (!$ids) return [];
    $in = implode(',', array_fill(0, count($ids), '?'));
    try {
        // 一人可能在多個子部門掛多個主管職稱 → 一律取職級最高的那個（欄位全部聚合，避免 ONLY_FULL_GROUP_BY 報錯）
        $st = $db->prepare("SELECT u.id, u.user_cname,
                                   MIN(pl.level) AS level,
                                   MIN(COALESCE(p.sort_order,999)) AS pos_sort,
                                   SUBSTRING_INDEX(GROUP_CONCAT(p.name ORDER BY pl.level ASC, COALESCE(p.sort_order,999) ASC),',',1) AS position_name,
                                   SUBSTRING_INDEX(GROUP_CONCAT(m.position_id ORDER BY pl.level ASC),',',1) AS position_id,
                                   SUBSTRING_INDEX(GROUP_CONCAT(m.department_id ORDER BY pl.level ASC),',',1) AS department_id
                            FROM user_department_position_map m
                            JOIN user u ON u.id=m.user_id
                            LEFT JOIN position p ON p.id=m.position_id
                            JOIN position_level pl ON pl.position_id=m.position_id AND pl.level IS NOT NULL
                            WHERE m.department_id IN ($in) AND COALESCE(u.state,1) NOT IN (0,90)
                            GROUP BY u.id, u.user_cname
                            ORDER BY level ASC, pos_sort ASC, u.id");
        $st->execute($ids);
        return $st->fetchAll(PDO::FETCH_ASSOC) ?: [];
    } catch (Throwable $e) { return []; }
}

/**
 * 儲存一筆綁定（$deptId/$userId 依角色 type 擇一；傳 null＝清除設定）
 * 注意：本函式**不可**呼叫 eg_org_ensure_schema()——CREATE TABLE 是 DDL，MySQL 會隱式 commit，
 * 呼叫端包在 transaction 裡時就會在 commit() 爆 "There is no active transaction"（2026-08-03 儲存失敗 500 的根因）。
 * 建表請由呼叫端在開 transaction 之前先做。
 */
function eg_org_save(PDO $db, string $key, ?int $deptId, ?int $userId, string $byName, int $includeSub = 1): void {
    $roles = EG_ORG_ROLES;
    if (!isset($roles[$key])) return;
    $type = $roles[$key]['type'];
    if ($type === 'dept') $userId = null;
    elseif ($type === 'user') $deptId = null;
    elseif ($type === 'dept_or_user' && $deptId) $userId = null; // 兩者都填時部門優先，人員視為未設定
    if ($deptId === null && $userId === null) {
        $db->prepare("DELETE FROM org_role_binding WHERE role_key=?")->execute([$key]);
        return;
    }
    $db->prepare("INSERT INTO org_role_binding (role_key, dept_id, user_id, include_sub, updated_at, updated_by)
                  VALUES (?,?,?,?,NOW(),?)
                  ON DUPLICATE KEY UPDATE dept_id=VALUES(dept_id), user_id=VALUES(user_id),
                      include_sub=VALUES(include_sub), updated_at=NOW(), updated_by=VALUES(updated_by)")
       ->execute([$key, $deptId, $userId, $includeSub ? 1 : 0, $byName]);
}

/** 公司全名（列印大標題用；唯一來源 customer_list.is_own_company=1，見 ai-rules/16） */
function eg_company_full_name(PDO $db): string {
    try {
        $r = $db->query("SELECT customer_full, customer FROM customer_list WHERE is_own_company=1 LIMIT 1")
                ->fetch(PDO::FETCH_ASSOC);
        if ($r) return trim((string)($r['customer_full'] ?: $r['customer'])) ?: '超正齒輪科技有限公司';
    } catch (Throwable $e) {}
    return '超正齒輪科技有限公司';
}

}
