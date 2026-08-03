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
    'prod_dept'     => ['label'=>'生產部門',       'type'=>'dept', 'desc'=>'現場製造'],
    'rd_dept'       => ['label'=>'設計／技術部門', 'type'=>'dept', 'desc'=>'圖面、技術文件'],
    'doc_dept'      => ['label'=>'文管中心',       'type'=>'dept', 'desc'=>'AS9100 文件管制'],
    // ── 人員類（「系統認定的某某人」）──
    'top_approver'  => ['label'=>'最高核准人員', 'type'=>'user', 'desc'=>'各式表單最後一關「核准」欄的簽章人（多數表單都是同一人）'],
    'mgmt_rep'      => ['label'=>'管理代表',     'type'=>'user', 'desc'=>'AS9100 管理代表'],
    'hr_signer'     => ['label'=>'人事簽章人員', 'type'=>'user', 'desc'=>'人事表單「人事」欄的簽章人；應同時具備 HR 相關頁面的管理員權限'],
    'hr_reviewer'   => ['label'=>'人事表單審核者', 'type'=>'user', 'desc'=>'人事表單「審核」欄；留空＝自動取「人事／管理部門」的部門主管'],
]);

if (!function_exists('eg_org_ensure_schema')) {

function eg_org_ensure_schema(PDO $db): void {
    try {
        $db->exec("CREATE TABLE IF NOT EXISTS org_role_binding (
            role_key VARCHAR(40) NOT NULL PRIMARY KEY COMMENT '見 EG_ORG_ROLES',
            dept_id INT NULL COMMENT 'type=dept 時的 department.id',
            user_id INT NULL COMMENT 'type=user 時的 user.id',
            note VARCHAR(150) NULL,
            updated_at DATETIME NULL,
            updated_by VARCHAR(50) NULL
        ) DEFAULT CHARSET=utf8mb4 COMMENT='全站組織角色綁定(哪個部門是人事部門/誰是最高核准人)'");
    } catch (Throwable $e) {}
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
 */
function eg_org_dept_manager(PDO $db, ?int $deptId): ?array {
    if (!$deptId) return null;
    try {
        $st = $db->prepare("SELECT u.id, u.user_cname, p.name AS position_name, pl.level,
                                   (SELECT dp.primary_user_id FROM department_position dp
                                     WHERE dp.department_id=m.department_id AND dp.position_id=m.position_id LIMIT 1) AS primary_uid
                            FROM user_department_position_map m
                            JOIN user u ON u.id=m.user_id
                            LEFT JOIN position p ON p.id=m.position_id
                            LEFT JOIN position_level pl ON pl.position_id=m.position_id
                            WHERE m.department_id=? AND COALESCE(u.state,1) NOT IN (0,90)
                              AND pl.level IS NOT NULL
                            ORDER BY pl.level ASC, (primary_uid = u.id) DESC, u.id
                            LIMIT 1");
        $st->execute([$deptId]);
        return $st->fetch(PDO::FETCH_ASSOC) ?: null;
    } catch (Throwable $e) { return null; }
}

/** 儲存一筆綁定（$deptId/$userId 依角色 type 擇一；傳 null＝清除設定） */
function eg_org_save(PDO $db, string $key, ?int $deptId, ?int $userId, string $byName): void {
    if (!isset(EG_ORG_ROLES[$key])) return;
    eg_org_ensure_schema($db);
    $type = EG_ORG_ROLES[$key]['type'];
    if ($type === 'dept') $userId = null; else $deptId = null;
    if ($deptId === null && $userId === null) {
        $db->prepare("DELETE FROM org_role_binding WHERE role_key=?")->execute([$key]);
        return;
    }
    $db->prepare("INSERT INTO org_role_binding (role_key, dept_id, user_id, updated_at, updated_by)
                  VALUES (?,?,?,NOW(),?)
                  ON DUPLICATE KEY UPDATE dept_id=VALUES(dept_id), user_id=VALUES(user_id),
                      updated_at=NOW(), updated_by=VALUES(updated_by)")
       ->execute([$key, $deptId, $userId, $byName]);
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
