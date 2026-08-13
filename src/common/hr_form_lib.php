<?php
/**
 * 人資職務表單共用函式庫 — 2026-08-13 新增
 * 涵蓋三張固定表單：2-MM-01-01 職務說明書(job_desc)／2-MM-01-09 專業技能鑑定考核表(skill_assess)／
 * 2-MM-01-10 員工職能鑑定表(competency)。
 *
 * 設計比照已驗證過的 review_form_lib.php（rvf_ 前綴）手法，但角色從「模板可設定的優先序鏈」簡化成
 * 固定角色：核准＝總經理(org_role_lib 的 top_approver)、確認＝該員工直屬主管(delegate_lib 的
 * eg_resolve_supervisor())。job_desc 不需要簽核，只留記錄。
 *
 * 資料表前綴 hr_form_ / hr_equipment_whitelist；PHP 函式一律 hrf_ 前綴。
 * 重用不新建：AS 文件綁定走 asdoc_lib.php（module code 依表單類型固定三選一）；
 * 簽核走共用 approval_record（module='hr_form', level='confirm'/'approve'，approval_lib.php）；
 * 通知走 live_event/live_event_target；超管補簽核走 confirm_password_lib.php 的操作確認密碼。
 *
 * 業務日期規則（跟 review_form 不同，使用者明確要求）：本模組的「業務日期」在建立/批次建立當下就可
 * 由操作者指定，不是送出時系統鎖定；超管補簽核時另外可指定「簽核日期」(decided_at 業務日)，比照
 * ai-rules/21 三鐵則（精確時間戳與業務日期分離、僅超管可調、決行時間隨機錯開5~30分鐘不跨天）。
 */

require_once __DIR__ . '/asdoc_lib.php';
require_once __DIR__ . '/approval_lib.php';
require_once __DIR__ . '/delegate_lib.php';
require_once __DIR__ . '/org_role_lib.php';
require_once __DIR__ . '/people_lib.php';
require_once __DIR__ . '/confirm_password_lib.php';

const HRF_FEATURES = [
    ['code' => 'hrf_view',           'group' => 'view', 'label' => '檢閱人資職務表單列表（沒勾也看得到跟自己有關的表單）'],
    ['code' => 'hrf_view_all',       'group' => 'view', 'label' => '檢視全部人員的表單'],
    ['code' => 'hrf_create',         'group' => 'op',   'label' => '建立/批次建立/複製/編輯表單內容'],
    ['code' => 'hrf_print',          'group' => 'op',   'label' => '列印'],
    ['code' => 'hrf_template_admin', 'group' => 'op',   'label' => '範本管理（職位範本、機型/量具白名單、部門表單資格、AS文件綁定、圖章綁定）'],
];

const HRF_FORM_TYPES = [
    'job_desc'     => '職務說明書',
    'skill_assess' => '專業技能鑑定考核表',
    'competency'   => '員工職能鑑定表',
];

const HRF_ASDOC_MODULE = [
    'job_desc'     => 'hr_form_job_desc',
    'skill_assess' => 'hr_form_skill_assess',
    'competency'   => 'hr_form_competency',
];

/* ============================================================ 資料表 ============================================================ */

function hrf_ensure_schema(PDO $db): void {
    static $done = false;
    if ($done) return;
    $done = true;
    try {
        $db->exec("CREATE TABLE IF NOT EXISTS hr_form_template (
            id INT AUTO_INCREMENT PRIMARY KEY,
            form_type VARCHAR(20) NOT NULL,
            name VARCHAR(100) NOT NULL,
            is_active TINYINT NOT NULL DEFAULT 1,
            list_stamp_tpl_id INT NULL,
            footer_stamp_tpl_id INT NULL,
            created_by INT NULL, created_by_name VARCHAR(50) NULL, created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            updated_at DATETIME NULL,
            KEY(form_type)
        ) DEFAULT CHARSET=utf8mb4 COMMENT='人資職務表單-職位範本主檔'");

        $db->exec("CREATE TABLE IF NOT EXISTS hr_form_template_item (
            id INT AUTO_INCREMENT PRIMARY KEY,
            template_id INT NOT NULL,
            seq INT NOT NULL DEFAULT 0,
            item_no VARCHAR(20) NULL,
            data_json TEXT NULL,
            KEY(template_id)
        ) DEFAULT CHARSET=utf8mb4 COMMENT='01職務說明書/10職能鑑定 範本內容列'");

        $db->exec("CREATE TABLE IF NOT EXISTS hr_form_template_machine (
            id INT AUTO_INCREMENT PRIMARY KEY,
            template_id INT NOT NULL,
            whitelist_id INT NOT NULL,
            sort_order INT NOT NULL DEFAULT 0,
            KEY(template_id)
        ) DEFAULT CHARSET=utf8mb4 COMMENT='09技能鑑定 範本適用機型清單'");

        $db->exec("CREATE TABLE IF NOT EXISTS hr_form_template_scope (
            id INT AUTO_INCREMENT PRIMARY KEY,
            template_id INT NOT NULL,
            department_id INT NULL COMMENT 'NULL=不限部門',
            position_id INT NOT NULL,
            KEY(template_id), KEY(department_id, position_id)
        ) DEFAULT CHARSET=utf8mb4 COMMENT='範本綁定的部門x職位'");

        $db->exec("CREATE TABLE IF NOT EXISTS hr_form_dept_type_setting (
            department_id INT NOT NULL PRIMARY KEY,
            produce_skill_assess TINYINT NOT NULL DEFAULT 0,
            produce_competency TINYINT NOT NULL DEFAULT 0
        ) DEFAULT CHARSET=utf8mb4 COMMENT='部門是否產生09/10表單(01全員適用不受此表限制)'");

        $db->exec("CREATE TABLE IF NOT EXISTS hr_equipment_whitelist (
            id INT AUTO_INCREMENT PRIMARY KEY,
            source_type VARCHAR(10) NOT NULL COMMENT 'machine=machine_list, tool=qc_tool',
            source_id INT NOT NULL,
            display_name VARCHAR(100) NOT NULL,
            item_name VARCHAR(100) NULL COMMENT '評鑑項目名稱(如:投影機)，空則用display_name',
            sort_order INT NOT NULL DEFAULT 0,
            is_active TINYINT NOT NULL DEFAULT 1,
            created_by VARCHAR(50) NULL, created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            UNIQUE KEY uq_src(source_type, source_id)
        ) DEFAULT CHARSET=utf8mb4 COMMENT='09技能鑑定 機型/量具白名單(管理員從既有主檔勾選)'");

        $db->exec("CREATE TABLE IF NOT EXISTS hr_form_instance (
            id INT AUTO_INCREMENT PRIMARY KEY,
            form_type VARCHAR(20) NOT NULL,
            user_id INT NOT NULL,
            dept_id INT NULL, position_id INT NULL, template_id INT NULL,
            business_date DATE NOT NULL,
            status VARCHAR(20) NOT NULL DEFAULT 'draft',
            created_by INT NULL, created_by_name VARCHAR(50) NULL,
            created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP, updated_at DATETIME NULL,
            user_no VARCHAR(30) NULL, user_cname VARCHAR(50) NULL,
            dept_name VARCHAR(50) NULL, position_name VARCHAR(50) NULL,
            supervisor_name VARCHAR(50) NULL, onboard_date DATE NULL,
            whitelist_id INT NULL, machine_display_name VARCHAR(100) NULL, item_name VARCHAR(100) NULL,
            score_quality_gm TINYINT NULL, score_quality_mgr TINYINT NULL,
            score_efficiency_gm TINYINT NULL, score_efficiency_mgr TINYINT NULL,
            score_proficiency_gm TINYINT NULL, score_proficiency_mgr TINYINT NULL,
            confirm_user_id INT NULL, confirm_user_name VARCHAR(50) NULL, confirm_at DATETIME NULL,
            approve_user_id INT NULL, approve_user_name VARCHAR(50) NULL, approve_at DATETIME NULL,
            KEY(form_type, user_id), KEY(dept_id), KEY(status)
        ) DEFAULT CHARSET=utf8mb4 COMMENT='人資職務表單-表單本體(建立當下snapshot員工資料)'");

        $db->exec("CREATE TABLE IF NOT EXISTS hr_form_instance_item (
            id INT AUTO_INCREMENT PRIMARY KEY,
            instance_id INT NOT NULL,
            seq INT NOT NULL DEFAULT 0,
            item_no VARCHAR(20) NULL,
            data_json TEXT NULL,
            KEY(instance_id)
        ) DEFAULT CHARSET=utf8mb4 COMMENT='01/10 表單內容列'");
    } catch (Throwable $e) {}
}

/* ============================================================ 使用者/權限 ============================================================ */

function hrf_current_user(PDO $db): ?array {
    $uname = $_SESSION['userName'] ?? '';
    if ($uname === '') return null;
    $st = $db->prepare("SELECT id, user_cname, user_status FROM user WHERE user_uname=?");
    $st->execute([$uname]);
    return $st->fetch(PDO::FETCH_ASSOC) ?: null;
}

function hrf_perms(PDO $db, ?array $u): array {
    if (!$u) return ['isAdmin'=>false,'isSuperAdmin'=>false,'canAdmin'=>false,'canCreate'=>false,'canView'=>false,'canViewAll'=>false,'canPrint'=>false];
    $uid = (int)$u['id'];
    $isAdmin = in_array((int)$u['user_status'], [9, 90], true);
    if (!$isAdmin) {
        $st = $db->prepare("SELECT 1 FROM user_roles ur JOIN roles r ON r.role_id=ur.role_id
                            WHERE ur.user_id=? AND r.role_code='admin' AND r.is_system=1 LIMIT 1");
        $st->execute([$uid]);
        $isAdmin = (bool)$st->fetchColumn();
    }
    require_once __DIR__ . '/role_features_helper.php';
    $feat = rf_load_user_features_all($db, $uid);
    $codes = array_values(array_intersect($feat, array_column(HRF_FEATURES, 'code')));
    $has = function (string $code) use ($isAdmin, $feat, $codes) { return $isAdmin || in_array('all', $feat, true) || in_array($code, $codes, true); };
    return [
        'isAdmin'      => $isAdmin,
        'isSuperAdmin' => $uid === 1,
        'canAdmin'     => $has('hrf_template_admin'),
        'canCreate'    => $has('hrf_create'),
        'canView'      => true,
        'canViewAll'   => $has('hrf_view_all') || $has('hrf_template_admin'),
        'canPrint'     => $has('hrf_print') || $has('hrf_create') || $has('hrf_template_admin'),
    ];
}

function hrf_csrf_token(): string {
    if (empty($_SESSION['hrf_csrf'])) $_SESSION['hrf_csrf'] = bin2hex(random_bytes(16));
    return $_SESSION['hrf_csrf'];
}
function hrf_csrf_ok(?string $t): bool {
    return $t !== null && hash_equals((string)($_SESSION['hrf_csrf'] ?? ''), (string)$t);
}
function hrf_need_csrf(): void {
    if (!hrf_csrf_ok($_POST['csrf'] ?? null)) {
        header('Content-Type: application/json; charset=utf-8');
        echo json_encode(['ok'=>false, 'error'=>'CSRF token 驗證失敗，請重新整理頁面']);
        exit;
    }
}

/* ============================================================ 固定角色池 ============================================================ */

/** 確認人池＝該員工直屬主管；找不到或本人即主管(頂端)則回傳空陣列。 */
/** 「課長」對應職位設定（使用者明確要求：直接設定課長對應資料庫內哪個職位，系統自行往上比對部門）。 */
function hrf_confirmer_position_get(PDO $db): ?int {
    try {
        $st = $db->prepare("SELECT param_value FROM system_parameters WHERE param_group=? AND param_key='confirmer_position_id' LIMIT 1");
        $st->execute([HRF_PARAM_GROUP]);
        $v = $st->fetchColumn();
        if ($v === false) return null;
        $id = (int)(json_decode((string)$v, true) ?? $v);
        return $id > 0 ? $id : null;
    } catch (Throwable $e) { return null; }
}
function hrf_confirmer_position_save(PDO $db, ?int $positionId, string $byName): void {
    $json = json_encode($positionId ?: 0);
    $st = $db->prepare("SELECT id FROM system_parameters WHERE param_group=? AND param_key='confirmer_position_id' LIMIT 1");
    $st->execute([HRF_PARAM_GROUP]);
    $rid = $st->fetchColumn();
    if ($rid) {
        $db->prepare("UPDATE system_parameters SET param_value=?, updated_by=? WHERE id=?")->execute([$json, $byName, $rid]);
    } else {
        $db->prepare("INSERT INTO system_parameters (param_group, param_key, param_value, description, updated_by) VALUES (?,?,?,?,?)")
           ->execute([HRF_PARAM_GROUP, 'confirmer_position_id', $json, '人資職務表單「課長」對應職位id(0=未設定)', $byName]);
    }
}

/**
 * 確認人池＝該員工「課長」。優先法：從員工所在部門開始，往上逐層比對部門（department.parent_id 鏈，最多8層），
 * 找該部門內掛著「課長對應職位」（hrf_confirmer_position_get）的人（排除員工本人）；找到就回傳。
 * 沒設定課長對應職位，或整條路徑都找不到人，才退回全站共用的 eg_resolve_supervisor()（delegate_lib.php，
 * 不修改該共用函式，只在本模組內優先改用部門x職位比對）。
 */
function hrf_supervisor_pool(PDO $db, int $targetUid): array {
    $posId = hrf_confirmer_position_get($db);
    if ($posId) {
        $main = eg_user_main_identity($db, $targetUid);
        $deptId = $main['department_id'] ?? null;
        $hop = 0;
        while ($deptId && $hop < 8) {
            try {
                $st = $db->prepare("SELECT u.id, u.user_cname FROM user_department_position_map m
                                    JOIN user u ON u.id=m.user_id AND COALESCE(u.state,1) NOT IN (0,90)
                                    WHERE m.department_id=? AND m.position_id=? AND u.id<>?
                                    ORDER BY m.is_main DESC, u.id LIMIT 1");
                $st->execute([$deptId, $posId, $targetUid]);
                $u = $st->fetch(PDO::FETCH_ASSOC);
                if ($u) return [$u];
            } catch (Throwable $e) {}
            $st2 = $db->prepare("SELECT parent_id FROM department WHERE id=?");
            $st2->execute([$deptId]);
            $deptId = $st2->fetchColumn() ?: null;
            $hop++;
        }
    }
    $supId = eg_resolve_supervisor($db, $targetUid);
    if ($supId && (int)$supId !== $targetUid) {
        try {
            $st = $db->prepare("SELECT id, user_cname FROM user WHERE id=? AND COALESCE(state,1) NOT IN (0,90)");
            $st->execute([$supId]);
            $u = $st->fetch(PDO::FETCH_ASSOC);
            if ($u) return [$u];
        } catch (Throwable $e) {}
    }
    // 兩種方法都找不到中間層人選（例如本人剛好是唯一的課長）：退回全站最高決策者，讓 NA 判定與送出流程都能正常運作。
    $top = hrf_top_approver_pool($db);
    return ($top && (int)$top[0]['id'] !== $targetUid) ? $top : [];
}

/** 核准人池＝全站最高決策者(org_role_lib top_approver，多數表單同一人=總經理)。 */
function hrf_top_approver_pool(PDO $db): array {
    $u = eg_org_user($db, 'top_approver');
    return $u ? [['id'=>(int)$u['id'], 'user_cname'=>$u['user_cname']]] : [];
}

/* ============================================================ 員工主要部門/職位/主管 snapshot ============================================================ */

/** 員工編號前綴（全站單一設定值，存 system_parameters；「員工編號」本系統慣例＝ user.id 本身，無獨立欄位）。 */
const HRF_PARAM_GROUP = 'HR_FORM';
function hrf_user_no_prefix_get(PDO $db): string {
    try {
        $st = $db->prepare("SELECT param_value FROM system_parameters WHERE param_group=? AND param_key='user_no_prefix' LIMIT 1");
        $st->execute([HRF_PARAM_GROUP]);
        $v = $st->fetchColumn();
        return $v !== false ? trim((string)(json_decode((string)$v, true) ?? $v), '"') : '';
    } catch (Throwable $e) { return ''; }
}
function hrf_user_no_prefix_save(PDO $db, string $prefix, string $byName): void {
    // param_value 欄位是 JSON 型別，字串一律要 json_encode 過（純數字裸值 MySQL JSON 才接受，字串不加引號會報 3140）
    $json = json_encode($prefix, JSON_UNESCAPED_UNICODE);
    $st = $db->prepare("SELECT id FROM system_parameters WHERE param_group=? AND param_key='user_no_prefix' LIMIT 1");
    $st->execute([HRF_PARAM_GROUP]);
    $rid = $st->fetchColumn();
    if ($rid) {
        $db->prepare("UPDATE system_parameters SET param_value=?, updated_by=? WHERE id=?")->execute([$json, $byName, $rid]);
    } else {
        $db->prepare("INSERT INTO system_parameters (param_group, param_key, param_value, description, updated_by) VALUES (?,?,?,?,?)")
           ->execute([HRF_PARAM_GROUP, 'user_no_prefix', $json, '人資職務表單列印顯示的員工編號前綴', $byName]);
    }
}
function hrf_user_no_display(PDO $db, $rawId): string { return hrf_user_no_prefix_get($db) . $rawId; }

function hrf_user_snapshot(PDO $db, int $uid): ?array {
    $st = $db->prepare("SELECT u.id, u.user_cname, u.hire_date FROM user u WHERE u.id=?");
    $st->execute([$uid]);
    $u = $st->fetch(PDO::FETCH_ASSOC);
    if (!$u) return null;
    $main = eg_user_main_identity($db, $uid);
    $deptId = $main['department_id'] ?? null;
    $posId  = $main['position_id'] ?? null;
    $deptName = null; $posName = null;
    if ($deptId) {
        $s = $db->prepare("SELECT name FROM department WHERE id=?"); $s->execute([$deptId]); $deptName = $s->fetchColumn() ?: null;
    }
    if ($posId) {
        $s = $db->prepare("SELECT name FROM position WHERE id=?"); $s->execute([$posId]); $posName = $s->fetchColumn() ?: null;
    }
    $supPool = hrf_supervisor_pool($db, $uid);
    $supId = $supPool ? (int)$supPool[0]['id'] : null;
    $supName = $supPool ? $supPool[0]['user_cname'] : null;
    return [
        'user_id' => (int)$u['id'], 'user_cname' => $u['user_cname'], 'user_no' => hrf_user_no_display($db, $u['id']),
        'onboard_date' => $u['hire_date'], 'dept_id' => $deptId ? (int)$deptId : null, 'dept_name' => $deptName,
        'position_id' => $posId ? (int)$posId : null, 'position_name' => $posName, 'supervisor_name' => $supName,
        'supervisor_id' => $supId ? (int)$supId : null,
    ];
}

/** NA 判定（使用者確認之規則）：確認人(直屬主管)解析到跟核准人(全站最高決策者)是同一人時，
 *  代表這位員工往上已經沒有「中階（課長考核）」這一層可以評，課長考核欄位視為 NA（不可填、印NA）。
 *  不硬編「課長」職稱字串，任何職位遇到相同情況都適用。 */
function hrf_confirm_is_na(PDO $db, int $targetUid): bool {
    $supPool = hrf_supervisor_pool($db, $targetUid);
    $apPool = hrf_top_approver_pool($db);
    if (!$supPool || !$apPool) return false;
    return (int)$supPool[0]['id'] === (int)$apPool[0]['id'];
}

/* ============================================================ 範本 ============================================================ */

function hrf_asdoc_module(string $formType): string { return HRF_ASDOC_MODULE[$formType] ?? 'hr_form_' . $formType; }

function hrf_template_list(PDO $db, string $formType): array {
    hrf_ensure_schema($db);
    $st = $db->prepare("SELECT * FROM hr_form_template WHERE form_type=? ORDER BY is_active DESC, id DESC");
    $st->execute([$formType]);
    return $st->fetchAll(PDO::FETCH_ASSOC);
}

function hrf_template_get(PDO $db, int $id): ?array {
    hrf_ensure_schema($db);
    $st = $db->prepare("SELECT * FROM hr_form_template WHERE id=?");
    $st->execute([$id]);
    $t = $st->fetch(PDO::FETCH_ASSOC);
    if (!$t) return null;
    $t['list_stamp'] = hrf_stamp_tpl_get($db, (int)($t['list_stamp_tpl_id'] ?? 0));
    $t['footer_stamp'] = hrf_stamp_tpl_get($db, (int)($t['footer_stamp_tpl_id'] ?? 0));
    $t['scope'] = hrf_template_scope_get($db, $id);
    if ($t['form_type'] === 'skill_assess') $t['machines'] = hrf_template_machines_get($db, $id);
    else $t['items'] = hrf_template_items_get($db, $id);
    return $t;
}

function hrf_template_save(PDO $db, int $id, string $formType, string $name, ?int $listStampId, ?int $footerStampId, string $byName): int {
    hrf_ensure_schema($db);
    if ($id) {
        $db->prepare("UPDATE hr_form_template SET name=?,list_stamp_tpl_id=?,footer_stamp_tpl_id=?,updated_at=NOW() WHERE id=?")
           ->execute([$name, $listStampId, $footerStampId, $id]);
        return $id;
    }
    $db->prepare("INSERT INTO hr_form_template (form_type,name,list_stamp_tpl_id,footer_stamp_tpl_id,created_by_name) VALUES (?,?,?,?,?)")
       ->execute([$formType, $name, $listStampId, $footerStampId, $byName]);
    return (int)$db->lastInsertId();
}

function hrf_template_delete(PDO $db, int $id): void {
    hrf_ensure_schema($db);
    $db->prepare("DELETE FROM hr_form_template_item WHERE template_id=?")->execute([$id]);
    $db->prepare("DELETE FROM hr_form_template_machine WHERE template_id=?")->execute([$id]);
    $db->prepare("DELETE FROM hr_form_template_scope WHERE template_id=?")->execute([$id]);
    $db->prepare("DELETE FROM hr_form_template WHERE id=?")->execute([$id]);
}

function hrf_template_items_get(PDO $db, int $templateId): array {
    $st = $db->prepare("SELECT id,seq,item_no,data_json FROM hr_form_template_item WHERE template_id=? ORDER BY seq,id");
    $st->execute([$templateId]);
    $rows = $st->fetchAll(PDO::FETCH_ASSOC);
    foreach ($rows as &$r) $r['data'] = json_decode((string)$r['data_json'], true) ?: new stdClass();
    return $rows;
}

function hrf_template_items_save(PDO $db, int $templateId, array $items): void {
    $db->prepare("DELETE FROM hr_form_template_item WHERE template_id=?")->execute([$templateId]);
    $ins = $db->prepare("INSERT INTO hr_form_template_item (template_id,seq,item_no,data_json) VALUES (?,?,?,?)");
    $seq = 0;
    foreach ($items as $it) {
        $ins->execute([$templateId, $seq, $it['item_no'] ?? null, json_encode($it['data'] ?? new stdClass(), JSON_UNESCAPED_UNICODE)]);
        $seq++;
    }
}

function hrf_template_machines_get(PDO $db, int $templateId): array {
    $st = $db->prepare("SELECT m.id AS map_id, w.* FROM hr_form_template_machine m
                        JOIN hr_equipment_whitelist w ON w.id=m.whitelist_id
                        WHERE m.template_id=? ORDER BY m.sort_order,m.id");
    $st->execute([$templateId]);
    return $st->fetchAll(PDO::FETCH_ASSOC);
}

function hrf_template_machines_save(PDO $db, int $templateId, array $whitelistIds): void {
    $db->prepare("DELETE FROM hr_form_template_machine WHERE template_id=?")->execute([$templateId]);
    $ins = $db->prepare("INSERT INTO hr_form_template_machine (template_id,whitelist_id,sort_order) VALUES (?,?,?)");
    $sort = 0;
    foreach (array_unique(array_map('intval', $whitelistIds)) as $wid) {
        if ($wid > 0) { $ins->execute([$templateId, $wid, $sort]); $sort++; }
    }
}

function hrf_template_scope_get(PDO $db, int $templateId): array {
    $st = $db->prepare("SELECT s.id, s.department_id, d.name AS department_name, s.position_id, p.name AS position_name
                        FROM hr_form_template_scope s
                        LEFT JOIN department d ON d.id=s.department_id
                        JOIN position p ON p.id=s.position_id
                        WHERE s.template_id=? ORDER BY s.id");
    $st->execute([$templateId]);
    return $st->fetchAll(PDO::FETCH_ASSOC);
}

function hrf_template_scope_save(PDO $db, int $templateId, array $scopes): void {
    $db->prepare("DELETE FROM hr_form_template_scope WHERE template_id=?")->execute([$templateId]);
    $ins = $db->prepare("INSERT INTO hr_form_template_scope (template_id,department_id,position_id) VALUES (?,?,?)");
    foreach ($scopes as $s) {
        $posId = (int)($s['position_id'] ?? 0);
        if ($posId <= 0) continue;
        $deptId = !empty($s['department_id']) ? (int)$s['department_id'] : null;
        $ins->execute([$templateId, $deptId, $posId]);
    }
}

/** 依員工的部門x職位比對範本；先找完全指定部門的列，找不到才退回「不限部門」的列。找不到回傳 null。 */
function hrf_match_template(PDO $db, string $formType, ?int $deptId, ?int $posId): ?array {
    hrf_ensure_schema($db);
    if (!$posId) return null;
    if ($deptId) {
        $st = $db->prepare("SELECT t.* FROM hr_form_template_scope s JOIN hr_form_template t ON t.id=s.template_id
                            WHERE t.form_type=? AND t.is_active=1 AND s.position_id=? AND s.department_id=? ORDER BY s.id DESC LIMIT 1");
        $st->execute([$formType, $posId, $deptId]);
        $t = $st->fetch(PDO::FETCH_ASSOC);
        if ($t) return hrf_template_get($db, (int)$t['id']);
    }
    $st = $db->prepare("SELECT t.* FROM hr_form_template_scope s JOIN hr_form_template t ON t.id=s.template_id
                        WHERE t.form_type=? AND t.is_active=1 AND s.position_id=? AND s.department_id IS NULL ORDER BY s.id DESC LIMIT 1");
    $st->execute([$formType, $posId]);
    $t = $st->fetch(PDO::FETCH_ASSOC);
    return $t ? hrf_template_get($db, (int)$t['id']) : null;
}

/* ============================================================ 部門表單資格設定 ============================================================ */

function hrf_dept_type_setting_list(PDO $db): array {
    hrf_ensure_schema($db);
    $st = $db->query("SELECT d.id AS department_id, d.name, COALESCE(s.produce_skill_assess,0) AS produce_skill_assess,
                       COALESCE(s.produce_competency,0) AS produce_competency
                       FROM department d LEFT JOIN hr_form_dept_type_setting s ON s.department_id=d.id
                       ORDER BY d.sort_order, d.id");
    return $st->fetchAll(PDO::FETCH_ASSOC);
}

function hrf_dept_type_setting_save(PDO $db, int $deptId, bool $skillAssess, bool $competency): void {
    hrf_ensure_schema($db);
    $db->prepare("INSERT INTO hr_form_dept_type_setting (department_id,produce_skill_assess,produce_competency) VALUES (?,?,?)
                  ON DUPLICATE KEY UPDATE produce_skill_assess=VALUES(produce_skill_assess), produce_competency=VALUES(produce_competency)")
       ->execute([$deptId, $skillAssess?1:0, $competency?1:0]);
}

function hrf_dept_can_produce(PDO $db, ?int $deptId, string $formType): bool {
    if ($formType === 'job_desc') return true;
    if (!$deptId) return false;
    hrf_ensure_schema($db);
    $col = $formType === 'skill_assess' ? 'produce_skill_assess' : 'produce_competency';
    $st = $db->prepare("SELECT $col FROM hr_form_dept_type_setting WHERE department_id=?");
    $st->execute([$deptId]);
    return (bool)$st->fetchColumn();
}

/* ============================================================ 機型/量具白名單 ============================================================ */

/** 供設定頁勾選用：machine_list + qc_tool 來源清單，各自標記是否已在白名單內。 */
function hrf_whitelist_sources(PDO $db): array {
    $machines = [];
    try {
        $machines = $db->query("SELECT ml.machine_id AS source_id, ml.machine AS display_name, mt.machine_type AS group_name
                                FROM machine_list ml LEFT JOIN machine_type mt ON mt.machine_type_id=ml.machine_type_id
                                WHERE ml.state IS NULL OR ml.state=0 ORDER BY mt.machine_type, ml.machine")->fetchAll(PDO::FETCH_ASSOC);
    } catch (Throwable $e) {}
    $tools = [];
    try {
        $tools = $db->query("SELECT t.Tool_id AS source_id, t.Tool_No AS display_name, l.QC_Tool AS group_name
                             FROM qc_tool t LEFT JOIN qc_tool_list l ON l.QC_Tool_List_id=t.QC_Tool_List_id
                             ORDER BY l.sort_order, t.Tool_No")->fetchAll(PDO::FETCH_ASSOC);
    } catch (Throwable $e) {}
    $existing = [];
    try {
        $st = $db->query("SELECT source_type, source_id, id, item_name FROM hr_equipment_whitelist");
        foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $r) $existing[$r['source_type'] . ':' . $r['source_id']] = $r;
    } catch (Throwable $e) {}
    foreach ($machines as &$m) { $k = 'machine:' . $m['source_id']; $m['checked'] = isset($existing[$k]); $m['item_name'] = $existing[$k]['item_name'] ?? ''; }
    foreach ($tools as &$t) { $k = 'tool:' . $t['source_id']; $t['checked'] = isset($existing[$k]); $t['item_name'] = $existing[$k]['item_name'] ?? ''; }
    return ['machines' => $machines, 'tools' => $tools];
}

function hrf_whitelist_list(PDO $db): array {
    hrf_ensure_schema($db);
    return $db->query("SELECT * FROM hr_equipment_whitelist WHERE is_active=1 ORDER BY sort_order,id")->fetchAll(PDO::FETCH_ASSOC);
}

/** 整批覆寫白名單（設定頁一次送出全部勾選狀態，比逐筆增刪簡單可靠）。$entries=[['source_type','source_id','display_name','item_name'],...] */
function hrf_whitelist_save(PDO $db, array $entries, string $byName): void {
    hrf_ensure_schema($db);
    $db->beginTransaction();
    try {
        $db->exec("UPDATE hr_equipment_whitelist SET is_active=0");
        $ins = $db->prepare("INSERT INTO hr_equipment_whitelist (source_type,source_id,display_name,item_name,created_by) VALUES (?,?,?,?,?)
                             ON DUPLICATE KEY UPDATE display_name=VALUES(display_name), item_name=VALUES(item_name), is_active=1");
        $sort = 0;
        foreach ($entries as $e) {
            $type = ($e['source_type'] ?? '') === 'tool' ? 'tool' : 'machine';
            $sid = (int)($e['source_id'] ?? 0);
            if ($sid <= 0) continue;
            $ins->execute([$type, $sid, (string)($e['display_name'] ?? ''), (string)($e['item_name'] ?? '') ?: null, $byName]);
            $sort++;
        }
        $db->commit();
    } catch (Throwable $e) { $db->rollBack(); throw $e; }
}

/* ============================================================ 圖章模板 ============================================================ */

function hrf_stamp_tpl_get(PDO $db, int $tplId): ?array {
    if (!$tplId) return null;
    try {
        $st = $db->prepare("SELECT id, tpl_name, schema_json FROM stamp_template WHERE id=? AND is_active=1");
        $st->execute([$tplId]);
        $r = $st->fetch(PDO::FETCH_ASSOC);
        return $r ? ['id'=>(int)$r['id'], 'tpl_name'=>$r['tpl_name'], 'schema'=>json_decode((string)$r['schema_json'], true)] : null;
    } catch (Throwable $e) { return null; }
}

function hrf_stamp_tpl_options(PDO $db): array {
    try {
        return $db->query("SELECT p.id, p.tpl_name, t.type_name FROM stamp_template p LEFT JOIN stamp_type t ON t.id=p.type_id
                           WHERE p.is_active=1 ORDER BY p.tpl_name")->fetchAll(PDO::FETCH_ASSOC);
    } catch (Throwable $e) { return []; }
}

/* ============================================================ 表單（instance） ============================================================ */

function hrf_instance_get(PDO $db, int $id): ?array {
    hrf_ensure_schema($db);
    $st = $db->prepare("SELECT * FROM hr_form_instance WHERE id=?");
    $st->execute([$id]);
    $r = $st->fetch(PDO::FETCH_ASSOC);
    if (!$r) return null;
    if (in_array($r['form_type'], ['job_desc','competency'], true)) $r['items'] = hrf_instance_items_get($db, $id);
    if ($r['form_type'] === 'skill_assess') $r['confirm_na'] = hrf_confirm_is_na($db, (int)$r['user_id']);
    return $r;
}

function hrf_instance_list(PDO $db, array $opt = []): array {
    hrf_ensure_schema($db);
    $where = ['1=1']; $params = [];
    if (!empty($opt['form_type'])) { $where[] = 'i.form_type=?'; $params[] = $opt['form_type']; }
    if (!empty($opt['dept_ids'])) {
        $ids = array_values(array_filter(array_map('intval', $opt['dept_ids'])));
        if ($ids) { $where[] = 'i.dept_id IN (' . implode(',', array_fill(0, count($ids), '?')) . ')'; $params = array_merge($params, $ids); }
    }
    if (!empty($opt['user_id'])) { $where[] = 'i.user_id=?'; $params[] = (int)$opt['user_id']; }
    if (!empty($opt['keyword'])) {
        $where[] = '(i.user_cname LIKE ? OR i.user_no LIKE ? OR i.dept_name LIKE ? OR i.position_name LIKE ?)';
        $kw = '%' . $opt['keyword'] . '%'; array_push($params, $kw, $kw, $kw, $kw);
    }
    $sql = "SELECT i.*, pl.level AS target_level FROM hr_form_instance i
            LEFT JOIN position_level pl ON pl.position_id = i.position_id
            WHERE " . implode(' AND ', $where) . " ORDER BY i.user_cname, i.whitelist_id, i.id DESC";
    $st = $db->prepare($sql);
    $st->execute($params);
    $rows = $st->fetchAll(PDO::FETCH_ASSOC);
    foreach ($rows as &$r) {
        $r['target_level'] = $r['target_level'] === null ? null : (int)$r['target_level'];
        if ($r['form_type'] === 'skill_assess') $r['confirm_na'] = hrf_confirm_is_na($db, (int)$r['user_id']);
    }
    return $rows;
}

/** 檢視者本人的職級（主職）。null＝未設定職級（視為最低階，全部人對其而言都是「職位以下」）。 */
function hrf_viewer_level(PDO $db, int $uid): ?int {
    $main = eg_user_main_identity($db, $uid);
    return $main['level'] ?? null;
}

function hrf_instance_items_get(PDO $db, int $instanceId): array {
    $st = $db->prepare("SELECT id,seq,item_no,data_json FROM hr_form_instance_item WHERE instance_id=? ORDER BY seq,id");
    $st->execute([$instanceId]);
    $rows = $st->fetchAll(PDO::FETCH_ASSOC);
    foreach ($rows as &$r) $r['data'] = json_decode((string)$r['data_json'], true) ?: new stdClass();
    return $rows;
}

/** 差異更新（比照 rvf_instance_items_save 手法，避免每次存檔 item id 全部改變）。 */
function hrf_instance_items_save(PDO $db, int $instanceId, array $items): void {
    $st = $db->prepare("SELECT id FROM hr_form_instance_item WHERE instance_id=?");
    $st->execute([$instanceId]);
    $existingIds = array_map('intval', $st->fetchAll(PDO::FETCH_COLUMN));
    $keepIds = [];
    $ins = $db->prepare("INSERT INTO hr_form_instance_item (instance_id,seq,item_no,data_json) VALUES (?,?,?,?)");
    $upd = $db->prepare("UPDATE hr_form_instance_item SET seq=?,item_no=?,data_json=? WHERE id=? AND instance_id=?");
    $seq = 0;
    foreach ($items as $it) {
        $dataJson = json_encode($it['data'] ?? new stdClass(), JSON_UNESCAPED_UNICODE);
        $itemNo = $it['item_no'] ?? null;
        $id = (int)($it['id'] ?? 0);
        if ($id && in_array($id, $existingIds, true)) {
            $upd->execute([$seq, $itemNo, $dataJson, $id, $instanceId]);
            $keepIds[] = $id;
        } else {
            $ins->execute([$instanceId, $seq, $itemNo, $dataJson]);
            $keepIds[] = (int)$db->lastInsertId();
        }
        $seq++;
    }
    $toDelete = array_values(array_diff($existingIds, $keepIds));
    if ($toDelete) {
        $in = implode(',', array_fill(0, count($toDelete), '?'));
        $db->prepare("DELETE FROM hr_form_instance_item WHERE id IN ($in)")->execute($toDelete);
    }
}

/** 建立單一員工單一表單(01/10)或單一機型(09)的一筆表單。 */
function hrf_instance_create_one(PDO $db, string $formType, int $targetUid, ?int $whitelistId, string $bizDate, int $byUid, string $byName): array {
    hrf_ensure_schema($db);
    if (!isset(HRF_FORM_TYPES[$formType])) return ['ok'=>false, 'msg'=>'不明的表單類型'];
    $snap = hrf_user_snapshot($db, $targetUid);
    if (!$snap) return ['ok'=>false, 'msg'=>'找不到此員工'];
    if ($formType !== 'job_desc' && !hrf_dept_can_produce($db, $snap['dept_id'], $formType)) {
        return ['ok'=>false, 'msg'=>$snap['user_cname'] . ' 所屬部門未設定產生「' . HRF_FORM_TYPES[$formType] . '」'];
    }
    $tpl = hrf_match_template($db, $formType, $snap['dept_id'], $snap['position_id']);
    if (!$tpl) return ['ok'=>false, 'msg'=>$snap['user_cname'] . '（' . ($snap['position_name'] ?: '未設定職位') . '）尚未建立適用的職位範本，請聯絡管理員'];

    $whitelist = null;
    if ($formType === 'skill_assess') {
        if (!$whitelistId) return ['ok'=>false, 'msg'=>'請選擇機型'];
        $st = $db->prepare("SELECT * FROM hr_equipment_whitelist WHERE id=? AND is_active=1");
        $st->execute([$whitelistId]);
        $whitelist = $st->fetch(PDO::FETCH_ASSOC);
        if (!$whitelist) return ['ok'=>false, 'msg'=>'機型白名單項目不存在'];
        // 固定「每職位/機台/員工需要一份」：同一員工同一機型已建立過就不重複建立（使用者明確要求）
        $dup = $db->prepare("SELECT id FROM hr_form_instance WHERE form_type='skill_assess' AND user_id=? AND whitelist_id=? LIMIT 1");
        $dup->execute([$targetUid, $whitelistId]);
        if ($existingId = $dup->fetchColumn()) {
            return ['ok'=>false, 'duplicate'=>true, 'existing_id'=>(int)$existingId, 'msg'=>$snap['user_cname'].'（'.$whitelist['display_name'].'）已建立過，不重複建立'];
        }
    }
    if ($formType === 'competency') {
        // 部門與職稱不變只需沿用既有那份（可檢視/列印/更新），不開放同員工同部門同職位有兩份（使用者明確要求）
        $dup = $db->prepare("SELECT id FROM hr_form_instance WHERE form_type='competency' AND user_id=? AND dept_id<=>? AND position_id<=>? LIMIT 1");
        $dup->execute([$targetUid, $snap['dept_id'], $snap['position_id']]);
        if ($existingId = $dup->fetchColumn()) {
            return ['ok'=>false, 'duplicate'=>true, 'existing_id'=>(int)$existingId, 'msg'=>$snap['user_cname'].'（'.($snap['dept_name']?:'').'/'.($snap['position_name']?:'').'）部門職稱未變更，已有既存表單可直接檢視/更新，不重複建立'];
        }
    }
    $needSign = in_array($formType, ['skill_assess', 'competency'], true);
    $db->prepare("INSERT INTO hr_form_instance
        (form_type,user_id,dept_id,position_id,template_id,business_date,status,created_by,created_by_name,
         user_no,user_cname,dept_name,position_name,supervisor_name,onboard_date,whitelist_id,machine_display_name,item_name)
        VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?)")
       ->execute([
            $formType, $targetUid, $snap['dept_id'], $snap['position_id'], $tpl['id'], $bizDate, $needSign ? 'draft' : 'active',
            $byUid, $byName,
            $snap['user_no'], $snap['user_cname'], $snap['dept_name'], $snap['position_name'], $snap['supervisor_name'], $snap['onboard_date'],
            $whitelist['id'] ?? null, $whitelist['display_name'] ?? null, ($whitelist['item_name'] ?? null) ?: ($whitelist['display_name'] ?? null),
        ]);
    $iid = (int)$db->lastInsertId();
    if ($formType !== 'skill_assess') {
        $items = [];
        foreach (($tpl['items'] ?? []) as $it) $items[] = ['item_no'=>$it['item_no'], 'data'=>$it['data']];
        if ($items) hrf_instance_items_save($db, $iid, $items);
    }
    return ['ok'=>true, 'id'=>$iid];
}

/**
 * 批次建立。09(skill_assess) 會做「員工 x 機型」交叉：$whitelistIds 有值＝手動指定套用到所有選取員工；
 * 空陣列＝依各員工比對到的職位範本機型清單各自展開。01/10 忽略 $whitelistIds。
 * 回傳 ['created'=>[instance_id...], 'errors'=>['員工姓名: 錯誤訊息', ...]]
 */
function hrf_instance_create_batch(PDO $db, string $formType, array $targetUids, array $whitelistIds, string $bizDate, int $byUid, string $byName): array {
    hrf_ensure_schema($db);
    $created = []; $errors = []; $skipped = [];
    foreach (array_unique(array_map('intval', $targetUids)) as $uid) {
        if ($uid <= 0) continue;
        $snap = hrf_user_snapshot($db, $uid);
        $label = $snap['user_cname'] ?? ('#' . $uid);
        if ($formType !== 'skill_assess') {
            $r = hrf_instance_create_one($db, $formType, $uid, null, $bizDate, $byUid, $byName);
            if ($r['ok']) $created[] = $r['id'];
            elseif (!empty($r['duplicate'])) $skipped[] = $label . '：' . $r['msg'];
            else $errors[] = $label . '：' . $r['msg'];
            continue;
        }
        $wids = $whitelistIds;
        if (!$wids) {
            $tpl = $snap ? hrf_match_template($db, 'skill_assess', $snap['dept_id'], $snap['position_id']) : null;
            $wids = $tpl ? array_column($tpl['machines'] ?? [], 'id') : [];
        }
        if (!$wids) { $errors[] = $label . '：沒有可用的機型清單（職位範本未設定適用機型，且未手動指定）'; continue; }
        foreach ($wids as $wid) {
            $r = hrf_instance_create_one($db, $formType, $uid, (int)$wid, $bizDate, $byUid, $byName);
            if ($r['ok']) $created[] = $r['id'];
            elseif (!empty($r['duplicate'])) $skipped[] = $label . '：' . $r['msg'];
            else $errors[] = $label . '：' . $r['msg'];
        }
    }
    return ['created'=>$created, 'errors'=>$errors, 'skipped'=>$skipped];
}

/** 複製表單：內容/機型/評分原樣複製，簽核狀態重置為草稿可重新編輯送出。 */
function hrf_instance_copy(PDO $db, int $instanceId, int $byUid, string $byName): array {
    $src = hrf_instance_get($db, $instanceId);
    if (!$src) return ['ok'=>false, 'msg'=>'找不到來源表單'];
    $needSign = in_array($src['form_type'], ['skill_assess', 'competency'], true);
    $db->prepare("INSERT INTO hr_form_instance
        (form_type,user_id,dept_id,position_id,template_id,business_date,status,created_by,created_by_name,
         user_no,user_cname,dept_name,position_name,supervisor_name,onboard_date,whitelist_id,machine_display_name,item_name,
         score_quality_gm,score_quality_mgr,score_efficiency_gm,score_efficiency_mgr,score_proficiency_gm,score_proficiency_mgr)
        VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?)")
       ->execute([
            $src['form_type'], $src['user_id'], $src['dept_id'], $src['position_id'], $src['template_id'], date('Y-m-d'), $needSign ? 'draft' : 'active',
            $byUid, $byName,
            $src['user_no'], $src['user_cname'], $src['dept_name'], $src['position_name'], $src['supervisor_name'], $src['onboard_date'],
            $src['whitelist_id'], $src['machine_display_name'], $src['item_name'],
            $src['score_quality_gm'], $src['score_quality_mgr'], $src['score_efficiency_gm'], $src['score_efficiency_mgr'],
            $src['score_proficiency_gm'], $src['score_proficiency_mgr'],
        ]);
    $newId = (int)$db->lastInsertId();
    if (!empty($src['items'])) {
        $items = [];
        foreach ($src['items'] as $it) $items[] = ['item_no'=>$it['item_no'], 'data'=>$it['data']];
        hrf_instance_items_save($db, $newId, $items);
    }
    return ['ok'=>true, 'id'=>$newId];
}

function hrf_instance_delete(PDO $db, int $id): void {
    $db->prepare("DELETE FROM hr_form_instance_item WHERE instance_id=?")->execute([$id]);
    $db->prepare("DELETE FROM hr_form_instance WHERE id=?")->execute([$id]);
}

/** 01/10 內容列存檔；僅 draft 或(01無簽核概念)未鎖定狀態可編輯，呼叫端先自行判斷狀態。 */
function hrf_instance_save_items(PDO $db, int $instanceId, array $items): void {
    hrf_instance_items_save($db, $instanceId, $items);
    $db->prepare("UPDATE hr_form_instance SET updated_at=NOW() WHERE id=?")->execute([$instanceId]);
}

/** 09 評分存檔：$scores 可含 quality_gm/quality_mgr/efficiency_gm/efficiency_mgr/proficiency_gm/proficiency_mgr（1-4或null）。 */
function hrf_instance_save_scores(PDO $db, int $instanceId, array $scores): void {
    $cols = ['quality_gm'=>'score_quality_gm','quality_mgr'=>'score_quality_mgr','efficiency_gm'=>'score_efficiency_gm',
             'efficiency_mgr'=>'score_efficiency_mgr','proficiency_gm'=>'score_proficiency_gm','proficiency_mgr'=>'score_proficiency_mgr'];
    $sets = []; $params = [];
    foreach ($cols as $k => $col) {
        if (array_key_exists($k, $scores)) {
            $v = $scores[$k];
            $v = ($v === '' || $v === null) ? null : max(1, min(4, (int)$v));
            $sets[] = "$col=?"; $params[] = $v;
        }
    }
    if (!$sets) return;
    $params[] = $instanceId;
    $db->prepare("UPDATE hr_form_instance SET " . implode(',', $sets) . ", updated_at=NOW() WHERE id=?")->execute($params);
}

/* ============================================================ 通知 ============================================================ */

function hrf_notify(PDO $db, int $refId, array $toUids, string $title, string $content, int $fromUid, string $refType, string $mode = 'sign'): int {
    $toUids = array_values(array_unique(array_map('intval', $toUids)));
    if (!$toUids) return 0;
    try {
        $db->prepare("INSERT INTO live_event (eventdate, enddate, title, content, status, created_by, source, show_status_to_others, ref_type, ref_id)
                      VALUES (CURDATE(), NULL, ?, ?, 0, ?, '人資職務表單', 1, ?, ?)")
           ->execute([$title, $content, $fromUid, $refType, $refId]);
        $eid = (int)$db->lastInsertId();
        $ins = $db->prepare("INSERT INTO live_event_target (live_event_id, target_type, target_id, mode) VALUES (?, 'user', ?, ?)");
        foreach ($toUids as $tuid) $ins->execute([$eid, $tuid, $mode]);
        try {
            require_once __DIR__ . '/../push/push_send.php';
            eg_push_send_to_users($db, eg_push_event_recipients($db, $eid), ['title'=>$title, 'body'=>mb_substr($content, 0, 480)]);
        } catch (Throwable $e) {}
        return $eid;
    } catch (Throwable $e) { return 0; }
}

/** 結束某筆表單過去所有還開著的通知(比照 review_form 決行後通知自動結束的手法)。 */
function hrf_notify_close(PDO $db, int $refId, string $refType): void {
    try {
        $db->prepare("UPDATE live_event SET enddate = DATE_SUB(CURDATE(), INTERVAL 1 DAY)
                      WHERE ref_type=? AND ref_id=? AND (enddate IS NULL OR enddate >= CURDATE())")
           ->execute([$refType, $refId]);
    } catch (Throwable $e) {}
}

/* ============================================================ 送出／確認／核准 ============================================================ */

function hrf_instance_submit(PDO $db, int $instanceId, int $uid, string $uname): array {
    $inst = hrf_instance_get($db, $instanceId);
    if (!$inst) return ['ok'=>false, 'msg'=>'找不到此表單'];
    if (!in_array($inst['form_type'], ['skill_assess','competency'], true)) return ['ok'=>false, 'msg'=>'此表單類型不需要送出簽核'];
    if ($inst['status'] !== 'draft') return ['ok'=>false, 'msg'=>'此表單已送出，不可重複送出'];
    $pool = hrf_supervisor_pool($db, (int)$inst['user_id']);
    if (!$pool) return ['ok'=>false, 'msg'=>'解析不到此員工的直屬主管，請聯絡管理員'];
    eg_approval_submit($db, 'hr_form', $instanceId, 'confirm', $uid, $uname);
    $formLabel = HRF_FORM_TYPES[$inst['form_type']];
    hrf_notify($db, $instanceId, array_column($pool, 'id'), '「' . $inst['user_cname'] . '」的「' . $formLabel . '」待您確認',
        $uname . ' 送出了 ' . $inst['user_cname'] . ' 的「' . $formLabel . '」，請確認' . ($inst['form_type']==='skill_assess' ? '並填寫課長考核評分' : '並填寫評鑑') . '。',
        $uid, 'HRF_CONFIRM', 'sign');
    $db->prepare("UPDATE hr_form_instance SET status='confirming',updated_at=NOW() WHERE id=?")->execute([$instanceId]);
    return ['ok'=>true, 'status'=>'confirming'];
}

/** 確認人(直屬主管)決行；skill_assess 可一併存課長考核分數，competency 可一併存操作/異常排除分數。 */
function hrf_confirm_decide(PDO $db, int $instanceId, int $uid, string $uname, string $decision, ?string $note, array $scores = [], array $items = []): array {
    $rec = eg_approval_latest($db, 'hr_form', $instanceId, 'confirm');
    if (!$rec || $rec['status'] !== 'pending') return ['ok'=>false, 'msg'=>'目前沒有待您確認的項目'];
    $inst = hrf_instance_get($db, $instanceId);
    $pool = hrf_supervisor_pool($db, (int)$inst['user_id']);
    if (!in_array($uid, array_column($pool, 'id'), true)) return ['ok'=>false, 'msg'=>'您不是此表單的確認人'];
    if ($decision === 'approved') {
        if ($scores && $inst['form_type'] === 'skill_assess' && !empty($inst['confirm_na'])) {
            // NA：確認人跟核准人是同一人，課長考核這一欄不存在，強制清空、不採信前端送來的值
            $scores = ['quality_mgr'=>null, 'efficiency_mgr'=>null, 'proficiency_mgr'=>null];
        }
        if ($scores) hrf_instance_save_scores($db, $instanceId, $scores);
        if ($items) hrf_instance_items_save($db, $instanceId, $items);
    }
    $r = eg_approval_decide($db, (int)$rec['id'], $uid, $uname, $decision, $note);
    if (!$r['success']) return ['ok'=>false, 'msg'=>$r['message']];
    $formLabel = HRF_FORM_TYPES[$inst['form_type']];
    hrf_notify_close($db, $instanceId, 'HRF_CONFIRM');
    if ($decision === 'rejected') {
        $db->prepare("UPDATE hr_form_instance SET status='rejected',updated_at=NOW() WHERE id=?")->execute([$instanceId]);
        hrf_notify($db, $instanceId, [(int)$inst['created_by']], '「' . $inst['user_cname'] . '」的「' . $formLabel . '」被退回',
            $uname . ' 退回了確認。退回原因：' . $note, $uid, 'HRF_RESULT', 'read');
        return ['ok'=>true, 'status'=>'rejected'];
    }
    $db->prepare("UPDATE hr_form_instance SET confirm_user_id=?,confirm_user_name=?,confirm_at=NOW(),status='approving',updated_at=NOW() WHERE id=?")
       ->execute([$uid, $uname, $instanceId]);
    $apool = hrf_top_approver_pool($db);
    if (!$apool) return ['ok'=>false, 'msg'=>'解析不到核准人(總經理)，請聯絡管理員設定 org_role_setting'];
    eg_approval_submit($db, 'hr_form', $instanceId, 'approve', $uid, $uname);
    hrf_notify($db, $instanceId, array_column($apool, 'id'), '「' . $inst['user_cname'] . '」的「' . $formLabel . '」待您核准',
        $inst['user_cname'] . ' 的「' . $formLabel . '」已完成確認，待您核准。', $uid, 'HRF_APPROVE', 'sign');
    return ['ok'=>true, 'status'=>'approving'];
}

/** 核准人(總經理)決行；skill_assess 可一併存總經理考核分數。 */
function hrf_approve_decide(PDO $db, int $instanceId, int $uid, string $uname, string $decision, ?string $note, array $scores = []): array {
    $rec = eg_approval_latest($db, 'hr_form', $instanceId, 'approve');
    if (!$rec || $rec['status'] !== 'pending') return ['ok'=>false, 'msg'=>'目前沒有待您核准的項目'];
    $inst = hrf_instance_get($db, $instanceId);
    $pool = hrf_top_approver_pool($db);
    if (!in_array($uid, array_column($pool, 'id'), true)) return ['ok'=>false, 'msg'=>'您不是此表單的核准人'];
    if ($decision === 'approved' && $scores) hrf_instance_save_scores($db, $instanceId, $scores);
    $r = eg_approval_decide($db, (int)$rec['id'], $uid, $uname, $decision, $note);
    if (!$r['success']) return ['ok'=>false, 'msg'=>$r['message']];
    $formLabel = HRF_FORM_TYPES[$inst['form_type']];
    hrf_notify_close($db, $instanceId, 'HRF_APPROVE');
    $newStatus = $decision === 'rejected' ? 'rejected' : 'signed';
    if ($decision === 'rejected') {
        $db->prepare("UPDATE hr_form_instance SET status='rejected',updated_at=NOW() WHERE id=?")->execute([$instanceId]);
        hrf_notify($db, $instanceId, [(int)$inst['created_by']], '「' . $inst['user_cname'] . '」的「' . $formLabel . '」被退回',
            $uname . ' 退回了核准。退回原因：' . $note, $uid, 'HRF_RESULT', 'read');
        return ['ok'=>true, 'status'=>'rejected'];
    }
    $db->prepare("UPDATE hr_form_instance SET approve_user_id=?,approve_user_name=?,approve_at=NOW(),status='signed',updated_at=NOW() WHERE id=?")
       ->execute([$uid, $uname, $instanceId]);
    hrf_notify($db, $instanceId, [(int)$inst['created_by']], '「' . $inst['user_cname'] . '」的「' . $formLabel . '」已完成簽核',
        $uname . ' 已核准 ' . $inst['user_cname'] . ' 的「' . $formLabel . '」。', $uid, 'HRF_RESULT', 'read');
    return ['ok'=>true, 'status'=>'signed'];
}

/**
 * 超級管理員(id=1)補簽核：對選取的表單，把還沒簽的關卡(confirm/approve)一次補齊，供補登舊資料用。
 * $signDate＝簽核業務日期(決行時間隨機錯開5~30分鐘、不跨天，比照 ai-rules/21 三鐵則/rvf_auto_sign 手法)。
 * 呼叫端須先過 eg_confirm_password_verify($db,1,$password) 才能呼叫本函式。
 */
/**
 * 超級管理員(id=1)補簽核。$scoresByInstance＝[instance_id => ['quality_gm'=>,'quality_mgr'=>,...]]（僅
 * skill_assess 適用，未帶入該筆 id 就不動分數，維持原值）；簽核前先存分數，NA(課長考核)欄位強制清空。
 */
function hrf_auto_sign_bulk(PDO $db, array $instanceIds, string $signDate, int $byUid, string $byName, array $scoresByInstance = []): array {
    $done = []; $errors = [];
    foreach (array_unique(array_map('intval', $instanceIds)) as $iid) {
        $inst = hrf_instance_get($db, $iid);
        if (!$inst) { $errors[] = "#$iid：找不到此表單"; continue; }
        if (!in_array($inst['form_type'], ['skill_assess','competency'], true)) { $errors[] = $inst['user_cname'] . '：此表單類型不需要簽核'; continue; }
        if (in_array($inst['status'], ['signed'], true)) { continue; }
        try {
            if ($inst['form_type'] === 'skill_assess' && isset($scoresByInstance[$iid])) {
                $sc = $scoresByInstance[$iid];
                if (!empty($inst['confirm_na'])) { $sc['quality_mgr'] = null; $sc['efficiency_mgr'] = null; $sc['proficiency_mgr'] = null; }
                hrf_instance_save_scores($db, $iid, $sc);
            }
            if ($inst['status'] === 'draft') {
                $pool = hrf_supervisor_pool($db, (int)$inst['user_id']);
                $supId = $pool ? (int)$pool[0]['id'] : null; $supName = $pool ? $pool[0]['user_cname'] : null;
                if (!$supName) { $supId = $byUid; $supName = $byName; }
                $aid = eg_approval_submit($db, 'hr_form', $iid, 'confirm', $byUid, $byName);
                eg_approval_decide($db, $aid, $supId, $supName, 'approved', '（超級管理員補簽核）');
                $off = random_int(5, 30);
                $db->prepare("UPDATE approval_record SET decided_at = LEAST(DATE_ADD(submitted_at, INTERVAL ? MINUTE), CONCAT(?, ' 23:59:59')) WHERE id=?")
                   ->execute([$off, $signDate, $aid]);
                $db->prepare("UPDATE hr_form_instance SET confirm_user_id=?,confirm_user_name=?,confirm_at=CONCAT(?, ' ', SEC_TO_TIME(?*60)),status='approving',updated_at=NOW() WHERE id=?")
                   ->execute([$supId, $supName, $signDate, $off, $iid]);
                $inst['status'] = 'approving';
            }
            if ($inst['status'] === 'approving') {
                $apool = hrf_top_approver_pool($db);
                $apId = $apool ? (int)$apool[0]['id'] : $byUid; $apName = $apool ? $apool[0]['user_cname'] : $byName;
                $aid2 = eg_approval_submit($db, 'hr_form', $iid, 'approve', $byUid, $byName);
                eg_approval_decide($db, $aid2, $apId, $apName, 'approved', '（超級管理員補簽核）');
                $off2 = random_int(5, 30);
                $db->prepare("UPDATE approval_record SET decided_at = LEAST(DATE_ADD(submitted_at, INTERVAL ? MINUTE), CONCAT(?, ' 23:59:59')) WHERE id=?")
                   ->execute([$off2, $signDate, $aid2]);
                $db->prepare("UPDATE hr_form_instance SET approve_user_id=?,approve_user_name=?,approve_at=CONCAT(?, ' ', SEC_TO_TIME(?*60)),status='signed',updated_at=NOW() WHERE id=?")
                   ->execute([$apId, $apName, $signDate, $off2, $iid]);
            }
            hrf_notify_close($db, $iid, 'HRF_CONFIRM');
            hrf_notify_close($db, $iid, 'HRF_APPROVE');
            $done[] = $iid;
        } catch (Throwable $e) { $errors[] = $inst['user_cname'] . '：' . $e->getMessage(); }
    }
    return ['done'=>$done, 'errors'=>$errors];
}

/* ============================================================ 列印 ============================================================ */

function hrf_asdoc_no_display(string $formType, PDO $db, ?string $bizDate = null): string {
    return eg_asdoc_no_asof($db, hrf_asdoc_module($formType), $bizDate);
}
