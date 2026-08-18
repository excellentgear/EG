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
require_once __DIR__ . '/position_history_lib.php';
require_once __DIR__ . '/date_fmt_lib.php';   // 日期顯示一律 YYYY.MM.DD（ai-rules/20）

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
    'competency'   => '職能鑑定表',
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
            produce_competency TINYINT NOT NULL DEFAULT 0,
            cp_machine_setup TINYINT NOT NULL DEFAULT 0 COMMENT '10職能鑑定表是否多一欄「機台設定」評分'
        ) DEFAULT CHARSET=utf8mb4 COMMENT='部門是否產生09/10表單(01全員適用不受此表限制)'");
        // 2026-08-18 新增欄位（既有資料庫用 ALTER 補；MySQL 9.4 的 ADD COLUMN 沒有 IF NOT EXISTS，先查 information_schema）
        try {
            $has = (int)$db->query("SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE()
                                    AND TABLE_NAME='hr_form_dept_type_setting' AND COLUMN_NAME='cp_machine_setup'")->fetchColumn();
            if (!$has) {
                $db->exec("ALTER TABLE hr_form_dept_type_setting ADD COLUMN cp_machine_setup TINYINT NOT NULL DEFAULT 0
                           COMMENT '10職能鑑定表是否多一欄「機台設定」評分'");
                // 沿用舊行為：本次改版前，會產生技能鑑定考核表的部門，職能鑑定表的項目名稱欄本來就標成「機台設定」
                // （那時只是換欄位標題、沒有獨立評分欄）。改版後改成獨立評分欄，這些部門預設先勾起來，
                // 管理員可自行在設定頁增減，不寫死。
                $db->exec("UPDATE hr_form_dept_type_setting SET cp_machine_setup=1 WHERE produce_skill_assess=1");
            }
        } catch (Throwable $e) {}

        $db->exec("CREATE TABLE IF NOT EXISTS hr_equipment_whitelist (
            id INT AUTO_INCREMENT PRIMARY KEY,
            source_type VARCHAR(10) NOT NULL COMMENT 'machine=machine_list, tool=qc_tool',
            source_id INT NOT NULL,
            display_name VARCHAR(100) NOT NULL COMMENT '機台名稱(machine_list.machine)，不可手動改字，一律取自來源表',
            item_name VARCHAR(100) NULL COMMENT '(已停用，保留欄位供舊資料相容，機台名稱一律用display_name)',
            machine_model VARCHAR(100) NULL COMMENT '機型(machine_list.machine_model)',
            asset_no VARCHAR(50) NULL COMMENT '機台編號(machine_list.asset_no，禁用現場編號field_no)',
            sort_order INT NOT NULL DEFAULT 0,
            is_active TINYINT NOT NULL DEFAULT 1,
            created_by VARCHAR(50) NULL, created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            UNIQUE KEY uq_src(source_type, source_id)
        ) DEFAULT CHARSET=utf8mb4 COMMENT='09技能鑑定 機型/量具白名單(管理員從既有主檔勾選，比照process_schedule_NOW.php機台設定頁欄位認定)'");

        $db->exec("CREATE TABLE IF NOT EXISTS hr_form_instance (
            id INT AUTO_INCREMENT PRIMARY KEY,
            form_type VARCHAR(20) NOT NULL,
            user_id INT NULL COMMENT '01職務說明書以部門x職位為主固定NULL；09/10仍綁單一員工',
            dept_id INT NULL, position_id INT NULL, template_id INT NULL,
            business_date DATE NOT NULL,
            status VARCHAR(20) NOT NULL DEFAULT 'draft',
            created_by INT NULL, created_by_name VARCHAR(50) NULL,
            created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP, updated_at DATETIME NULL,
            user_no VARCHAR(30) NULL, user_cname VARCHAR(50) NULL,
            dept_name VARCHAR(50) NULL, position_name VARCHAR(50) NULL,
            supervisor_name VARCHAR(50) NULL, onboard_date DATE NULL,
            whitelist_id INT NULL, machine_display_name VARCHAR(100) NULL COMMENT '機台名稱',
            item_name VARCHAR(100) NULL COMMENT '(已停用，保留欄位供舊資料相容)',
            machine_model VARCHAR(100) NULL COMMENT '機型',
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
function hrf_supervisor_pool(PDO $db, int $targetUid, ?int $deptIdOverride = null): array {
    $posId = hrf_confirmer_position_get($db);
    if ($posId) {
        // $deptIdOverride：補歷史表單時傳入「該業務日期當時」的部門，才不會用現在的部門去找當時的課長
        $main = eg_user_main_identity($db, $targetUid);
        $deptId = $deptIdOverride ?: ($main['department_id'] ?? null);
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

/** $asOfDate：表單的業務日期。填過去的日期＝補歷史表單，部門/職稱一律回推「當時」的（user_position_history，
 *  ai-rules/14；沒有異動紀錄的人回現況），不可用現在的部門去存一張 2025 年的表單。傳 null／今天＝用現況。 */
/**
 * 某人在某業務日期「所有」的部門×職位（含兼任）。職能鑑定表是**一個人一種職務一張**（使用者明確要求，
 * 2026-08-18），挑人時要逐職務列出、逐職務各建一張，不能只看主要職務。
 * 過去日期走職務調動紀錄回推（ai-rules/14），今天以後直接讀現況 user_department_position_map。
 * 回傳每列：department_id/dept_name/position_id/position_name/is_main，主職排前、其餘依職稱 sort_order。
 */
/**
 * 10職能鑑定表是**一年一次**評鑑（使用者明確要求，2026-08-18）：判斷「這個人的這個職務要不要再建一張」
 * 一律看業務日期的前後 11 個月內有沒有既有表單——有＝這次不用建（提早/延後一個月做都算同一年度那次），
 * 沒有＝缺件要建。11 個月而不是 12 個月，是為了讓「去年 12/24 做、今年 11/24 做」這種提早一個月的情況
 * 仍判定成新的一年度（12 個月會把它當成同一次）。
 */
const HRF_CP_ANNUAL_MONTHS = 11;

function hrf_cp_window(string $date): array {
    $t = strtotime($date);
    if ($t === false) $t = time();
    return [date('Y-m-d', strtotime('-' . HRF_CP_ANNUAL_MONTHS . ' months', $t)),
            date('Y-m-d', strtotime('+' . HRF_CP_ANNUAL_MONTHS . ' months', $t))];
}

/** 既有職能鑑定表索引：'user-dept-pos' => [['id'=>,'date'=>], ...]（日期新到舊） */
function hrf_cp_existing_map(PDO $db): array {
    hrf_ensure_schema($db);
    $out = [];
    $rows = $db->query("SELECT id, user_id, dept_id, position_id, business_date, status
                        FROM hr_form_instance WHERE form_type='competency'
                        ORDER BY business_date DESC, id DESC")->fetchAll(PDO::FETCH_ASSOC);
    foreach ($rows as $r) {
        $out[(int)$r['user_id'] . '-' . (int)$r['dept_id'] . '-' . (int)$r['position_id']][] =
            ['id'=>(int)$r['id'], 'date'=>(string)$r['business_date'], 'status'=>(string)$r['status']];
    }
    return $out;
}

/** 該職務在指定業務日期的前後 11 個月內是否已有表單；有就回傳那一筆，沒有回傳 null。 */
function hrf_cp_hit_in_window(array $existingMap, int $uid, ?int $deptId, ?int $posId, string $date): ?array {
    [$from, $to] = hrf_cp_window($date);
    foreach ($existingMap[$uid . '-' . (int)$deptId . '-' . (int)$posId] ?? [] as $e) {
        if ($e['date'] >= $from && $e['date'] <= $to) return $e;
    }
    return null;
}

/**
 * 缺件偵測（指定業務日期，畫面輸入日期後即時重算）。
 * 10職能鑑定表：逐「員工×職務」比對，前後 11 個月內沒有表單就算缺件（附上「上次是哪一張、哪一天」）。
 * 09技能鑑定考核表：不是年度制，維持原本判定＝這個人完全沒有任何一張就算缺件。
 * 人員一律用該日期當時的組織（含當時在職、現已離職者），比照建立表單的挑選器。
 */
function hrf_missing_report(PDO $db, string $formType, string $date): array {
    hrf_ensure_schema($db);
    if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $date)) $date = date('Y-m-d');
    $col = $formType === 'skill_assess' ? 'produce_skill_assess' : 'produce_competency';
    $eligible = [];
    foreach (hrf_dept_type_setting_list($db) as $d) if (!empty($d[$col])) $eligible[(int)$d['department_id']] = true;

    $people = hrf_people_asof($db, $date);
    // 排序鍵一律部門/職稱 sort_order（ai-rules/08 第五節鐵則6），不是名稱筆畫
    $dSort = []; $pSort = [];
    foreach ($db->query("SELECT id, COALESCE(sort_order,999) so FROM department")->fetchAll(PDO::FETCH_ASSOC) as $d) $dSort[(int)$d['id']] = (int)$d['so'];
    foreach ($db->query("SELECT id, COALESCE(sort_order,999) so FROM position")->fetchAll(PDO::FETCH_ASSOC) as $pp) $pSort[(int)$pp['id']] = (int)$pp['so'];
    $sortKey = fn(array $r) => [$dSort[(int)$r['dept_id']] ?? 999, (string)$r['dept_name'],
                                $pSort[(int)$r['position_id']] ?? 999, (string)$r['position_name'], (string)$r['user_cname']];
    $rows = [];
    if ($formType === 'competency') {
        $map = hrf_cp_existing_map($db);
        [$from, $to] = hrf_cp_window($date);
        $done = [];
        foreach ($people as $p) {
            foreach (($p['posts'] ?? []) as $po) {
                $did = (int)($po['department_id'] ?? 0);
                $pid = (int)($po['position_id'] ?? 0);
                if (!isset($eligible[$did])) continue;
                if ($hit = hrf_cp_hit_in_window($map, (int)$p['id'], $did, $pid, $date)) {
                    // 已在年度視窗內建立過：建立表單挑選器要據此標示灰底不可再勾，所以一併回傳
                    $done[] = ['pick_value'=>(int)$p['id'] . ':' . $did . ':' . $pid,
                               'instance_id'=>$hit['id'], 'business_date'=>$hit['date']];
                    continue;
                }
                $all = $map[(int)$p['id'] . '-' . $did . '-' . $pid] ?? [];
                $rows[] = [
                    'user_id'=>(int)$p['id'], 'user_cname'=>$p['user_cname'],
                    'dept_id'=>$did, 'dept_name'=>$po['dept_name'],
                    'position_id'=>$pid, 'position_name'=>$po['position_name'],
                    'is_main'=>(int)($po['is_main'] ?? 0),
                    'resigned'=>(int)($p['resigned'] ?? 0), 'leave_note'=>(string)($p['leave_note'] ?? ''),
                    'last_id'=>$all ? $all[0]['id'] : null, 'last_date'=>$all ? $all[0]['date'] : null,
                    'pick_value'=>(int)$p['id'] . ':' . $did . ':' . $pid,
                ];
            }
        }
        usort($rows, fn($a, $b) => $sortKey($a) <=> $sortKey($b));
        return ['date'=>$date, 'window_from'=>$from, 'window_to'=>$to, 'annual'=>1, 'rows'=>$rows, 'done'=>$done];
    }

    $have = [];
    foreach ($db->query("SELECT DISTINCT user_id FROM hr_form_instance WHERE form_type='skill_assess'")->fetchAll(PDO::FETCH_COLUMN) as $u)
        $have[(int)$u] = true;
    foreach ($people as $p) {
        $inElig = false;
        foreach (($p['dept_ids'] ?? []) as $d) if (isset($eligible[(int)$d])) { $inElig = true; break; }
        if (!$inElig || isset($have[(int)$p['id']])) continue;
        $rows[] = [
            'user_id'=>(int)$p['id'], 'user_cname'=>$p['user_cname'],
            'dept_id'=>(int)($p['dept_id'] ?? 0), 'dept_name'=>$p['dept_name'],
            'position_id'=>(int)($p['position_id'] ?? 0), 'position_name'=>$p['position_name'],
            'is_main'=>1, 'resigned'=>(int)($p['resigned'] ?? 0), 'leave_note'=>(string)($p['leave_note'] ?? ''),
            'last_id'=>null, 'last_date'=>null, 'pick_value'=>(int)$p['id'],
        ];
    }
    usort($rows, fn($a, $b) => $sortKey($a) <=> $sortKey($b));
    return ['date'=>$date, 'window_from'=>null, 'window_to'=>null, 'annual'=>0, 'rows'=>$rows];
}

function hrf_user_posts(PDO $db, int $uid, ?string $asOfDate = null): array {
    $asOf = ($asOfDate && preg_match('/^\d{4}-\d{2}-\d{2}$/', $asOfDate) && $asOfDate < date('Y-m-d')) ? $asOfDate : null;
    $rows = $asOf ? eg_position_snapshot_at($db, $uid, $asOf) : eg_position_snapshot_now($db, $uid);
    static $deptMap = null, $posMap = null;
    if ($deptMap === null) {
        $deptMap = []; $posMap = [];
        foreach ($db->query("SELECT id,name,COALESCE(sort_order,999) so FROM department")->fetchAll(PDO::FETCH_ASSOC) as $d)
            $deptMap[(int)$d['id']] = ['name'=>(string)$d['name'], 'so'=>(int)$d['so']];
        foreach ($db->query("SELECT id,name,COALESCE(sort_order,999) so FROM position")->fetchAll(PDO::FETCH_ASSOC) as $pp)
            $posMap[(int)$pp['id']] = ['name'=>(string)$pp['name'], 'so'=>(int)$pp['so']];
    }
    $out = [];
    foreach ($rows as $r) {
        $did = (int)($r['department_id'] ?? 0);
        $pid = (int)($r['position_id'] ?? 0);
        if (!$did && !$pid) continue;
        $key = $did . '-' . $pid;
        if (isset($out[$key])) continue;
        $out[$key] = [
            'department_id' => $did ?: null,
            // 名稱優先用主檔現況（改名後看得懂），主檔查不到才用快照當時存的名稱
            'dept_name'     => $deptMap[$did]['name'] ?? (string)($r['department_name'] ?? ''),
            'dept_sort'     => $deptMap[$did]['so'] ?? 999,
            'position_id'   => $pid ?: null,
            'position_name' => $posMap[$pid]['name'] ?? (string)($r['position_name'] ?? ''),
            'position_sort' => $posMap[$pid]['so'] ?? 999,
            'is_main'       => (int)($r['is_main'] ?? 0),
        ];
    }
    $out = array_values($out);
    usort($out, function ($a, $b) {
        return [$b['is_main'], $a['position_sort'], $a['dept_sort']] <=> [$a['is_main'], $b['position_sort'], $b['dept_sort']];
    });
    return $out;
}

function hrf_user_snapshot(PDO $db, int $uid, ?string $asOfDate = null, ?array $post = null): ?array {
    $st = $db->prepare("SELECT u.id, u.user_cname, u.hire_date FROM user u WHERE u.id=?");
    $st->execute([$uid]);
    $u = $st->fetch(PDO::FETCH_ASSOC);
    if (!$u) return null;
    $asOf = ($asOfDate && preg_match('/^\d{4}-\d{2}-\d{2}$/', $asOfDate) && $asOfDate < date('Y-m-d')) ? $asOfDate : null;
    // $post＝指定用哪一個「部門×職位」（職能鑑定表一人一職務一張時由挑選器指定）；沒指定＝主要職務（原行為）
    if ($post !== null) {
        $deptId = ((int)($post['department_id'] ?? 0)) ?: null;
        $posId  = ((int)($post['position_id'] ?? 0)) ?: null;
    } elseif ($asOf) {
        $snapRows = eg_position_snapshot_at($db, $uid, $asOf);
        $pick = null;
        foreach ($snapRows as $srow) { if (!empty($srow['is_main'])) { $pick = $srow; break; } }
        if (!$pick) $pick = $snapRows[0] ?? null;
        $deptId = $pick ? ((int)$pick['department_id'] ?: null) : null;
        $posId  = $pick ? ((int)$pick['position_id'] ?: null) : null;
    } else {
        $main = eg_user_main_identity($db, $uid);
        $deptId = $main['department_id'] ?? null;
        $posId  = $main['position_id'] ?? null;
    }
    $deptName = null; $posName = null;
    if ($deptId) {
        $s = $db->prepare("SELECT name FROM department WHERE id=?"); $s->execute([$deptId]); $deptName = $s->fetchColumn() ?: null;
    }
    if ($posId) {
        $s = $db->prepare("SELECT name FROM position WHERE id=?"); $s->execute([$posId]); $posName = $s->fetchColumn() ?: null;
    }
    $supPool = hrf_supervisor_pool($db, $uid, $asOf ? ($deptId ? (int)$deptId : null) : null);
    $supId = $supPool ? (int)$supPool[0]['id'] : null;
    $supName = $supPool ? $supPool[0]['user_cname'] : null;
    return [
        'user_id' => (int)$u['id'], 'user_cname' => $u['user_cname'], 'user_no' => hrf_user_no_display($db, $u['id']),
        'onboard_date' => $u['hire_date'], 'dept_id' => $deptId ? (int)$deptId : null, 'dept_name' => $deptName,
        'position_id' => $posId ? (int)$posId : null, 'position_name' => $posName, 'supervisor_name' => $supName,
        'supervisor_id' => $supId ? (int)$supId : null,
    ];
}

/**
 * 「某個業務日期當時」的員工清單（建立表單挑選人員用；2026-08-18 使用者明確要求）。
 *
 * 為什麼要有這支：09技能鑑定表／10職能鑑定表常常在補過去的紙本表單，挑人時看到的必須是「那一天」的組織，
 * 不是今天的組織——那天在生產2廠、現在調到生產3廠的人要出現在生產2廠底下；那天還在職、現在已離職的人
 * 也必須挑得到（不然舊表單根本補不進來）。部門/職稱回推走 ai-rules/14 的 user_position_history
 * （position_history_lib.php，沒有異動紀錄的人回現況）。
 *
 * 在職與否的認定（含已離職者）：
 *   ・到職日晚於該日期 → 當時還沒進公司，不列
 *   ・已離職且離職日早於該日期 → 當時已經離開，不列（離職日當天仍算在職）
 *   ・離職日未登錄的離職者 → 無從判斷，一律列出並標「離職日未登錄」，由使用者自己判斷
 *   ・離職日優先取 user.leave_date，沒填才退回 user_status_history 最早一筆轉離職的起日
 *
 * 日期＝今天(或未來) 時完全不走上面這套，直接回全站共用的 eg_people_list()（人員列表鐵則的唯一實作），
 * 行為與 meta 的 people 一模一樣，避免「現況」在兩個地方各算一次而不一致。
 */
/**
 * 「某個日期當時在職」的人：user_id => ['user_cname','state','leave_date']（含現在已離職、當時還在職的人）。
 * 認定規則（hrf_people_asof() 與主管解析共用同一套，避免兩邊各判一次而不一致）：
 *   到職日晚於該日 → 當時還沒進公司；已離職且離職日早於該日 → 當時已離開（離職日/到職日當天都算在職）；
 *   離職日優先取 user.leave_date，沒填才退回 user_status_history 最早一筆轉離職的起日；兩者都沒有＝無從判斷，列入。
 */
function hrf_employed_at_map(PDO $db, string $date): array {
    $resign = [];
    try {
        foreach ($db->query("SELECT user_id, MIN(start_date) sd FROM user_status_history WHERE status=0 GROUP BY user_id")
                    ->fetchAll(PDO::FETCH_ASSOC) as $r) $resign[(int)$r['user_id']] = (string)$r['sd'];
    } catch (Throwable $e) {}
    $out = [];
    $us = $db->query("SELECT id, user_cname, state, hire_date, leave_date FROM `user`
                      WHERE user_cname IS NOT NULL AND user_cname<>''
                        AND COALESCE(is_shared_account,0) <> 1
                        AND COALESCE(state,1) NOT IN (90,99)")->fetchAll(PDO::FETCH_ASSOC);
    foreach ($us as $u) {
        $uid = (int)$u['id'];
        $leaveD = (string)($u['leave_date'] ?? '') ?: (string)($resign[$uid] ?? '');
        if (!empty($u['hire_date']) && (string)$u['hire_date'] > $date) continue;
        if ((int)($u['state'] ?? 1) === 0 && $leaveD !== '' && $leaveD < $date) continue;
        $out[$uid] = ['user_cname'=>(string)$u['user_cname'], 'state'=>(int)($u['state'] ?? 1), 'leave_date'=>$leaveD];
    }
    return $out;
}

/**
 * 「該日期當時」的確認人(課長)：從指定部門開始沿 department.parent_id 往上找，比對**當時**掛著課長對應職位的人
 * （職務快照走 user_position_history，並限縮在當時在職者＝含現已離職者）。找不到回空陣列，由呼叫端退回現況解析。
 * 2026-08-18 使用者回報：補 2025 年的舊表單，自動簽核卻蓋現在的主管章（該員後來調部門），簽章與事實不符。
 */
function hrf_supervisor_pool_at(PDO $db, int $targetUid, ?int $deptId, string $date): array {
    $posId = hrf_confirmer_position_get($db);
    if (!$posId || !$deptId) return [];
    $snaps = eg_position_snapshot_at_bulk($db, $date);
    $emp = hrf_employed_at_map($db, $date);
    $hop = 0; $d = (int)$deptId;
    while ($d && $hop < 8) {
        $best = null;
        foreach ($snaps as $uid2 => $rows) {
            $uid2 = (int)$uid2;
            if ($uid2 === $targetUid || !isset($emp[$uid2])) continue;
            foreach ($rows as $r) {
                if ((int)$r['department_id'] !== $d || (int)$r['position_id'] !== $posId) continue;
                $cand = ['id'=>$uid2, 'user_cname'=>$emp[$uid2]['user_cname'], 'is_main'=>(int)$r['is_main']];
                if (!$best || $cand['is_main'] > $best['is_main']
                    || ($cand['is_main'] === $best['is_main'] && $cand['id'] < $best['id'])) $best = $cand;
            }
        }
        if ($best) return [['id'=>$best['id'], 'user_cname'=>$best['user_cname']]];
        $st = $db->prepare("SELECT parent_id FROM department WHERE id=?");
        $st->execute([$d]);
        $d = (int)($st->fetchColumn() ?: 0);
        $hop++;
    }
    return [];
}

/**
 * 這張表單「應該由誰確認」的人選池。業務日期在過去＝用當時的組織解析（從表單存下來的當時部門往上找當時的課長），
 * 解析不到才退回現況（但仍從當時的部門開始往上找，不會用該員現在的部門）。
 * $activeOnly＝只留現在還能登入操作的人（送通知/判斷簽核權限用）；自動補簽核要記錄當時的主管，傳 false。
 */
function hrf_instance_supervisor_pool(PDO $db, array $inst, bool $activeOnly = false): array {
    $uid = (int)($inst['user_id'] ?? 0);
    $bizDate = substr((string)($inst['business_date'] ?? ''), 0, 10);
    $deptId = (int)($inst['dept_id'] ?? 0) ?: null;
    $isPast = $bizDate !== '' && $bizDate < date('Y-m-d');
    if ($isPast) {
        $pool = hrf_supervisor_pool_at($db, $uid, $deptId, $bizDate);
        if ($pool) {
            if (!$activeOnly) return $pool;
            $ids = implode(',', array_map('intval', array_column($pool, 'id')));
            $st = $db->query("SELECT id, user_cname FROM `user` WHERE id IN ($ids) AND COALESCE(state,1) NOT IN (0,90)");
            $alive = $st->fetchAll(PDO::FETCH_ASSOC);
            if ($alive) return array_map(fn($r) => ['id'=>(int)$r['id'], 'user_cname'=>(string)$r['user_cname']], $alive);
        }
    }
    return hrf_supervisor_pool($db, $uid, $isPast ? $deptId : null);
}

/**
 * 這張表單「確認關卡」的人選池（＝確認欄要蓋誰的章、誰按得下確認、通知寄給誰）。
 *
 * 正常＝hrf_instance_supervisor_pool() 解析出來的直屬主管。但當這個人**本身就是全站最高決策者**
 * （例：技術課課長就是總經理本人），往上已經沒有中間層，supervisor pool 會刻意排除本人而回空——
 * 這時確認章要蓋**最高決策者本人**的章（使用者明確要求，2026-08-18；先前這種情況自動補簽核會退回
 * 「操作補簽的那個人」，結果印出來的確認章是「超級管理員」＝簽章不實）。
 */
function hrf_confirm_pool(PDO $db, array $inst, bool $activeOnly = false): array {
    $pool = hrf_instance_supervisor_pool($db, $inst, $activeOnly);
    if ($pool) return $pool;
    $top = hrf_top_approver_pool($db);
    if (!$top) return [];
    if ($activeOnly) {
        $st = $db->prepare("SELECT id, user_cname FROM `user` WHERE id=? AND COALESCE(state,1) NOT IN (0,90)");
        $st->execute([(int)$top[0]['id']]);
        $u = $st->fetch(PDO::FETCH_ASSOC);
        return $u ? [['id'=>(int)$u['id'], 'user_cname'=>(string)$u['user_cname']]] : [];
    }
    return $top;
}

function hrf_people_asof(PDO $db, string $date): array {
    if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $date)) $date = date('Y-m-d');
    if ($date >= date('Y-m-d')) {
        $people = eg_people_list($db, []);
        foreach ($people as &$pr) $pr['posts'] = hrf_user_posts($db, (int)$pr['id'], null);
        return $people;
    }

    $snaps = eg_position_snapshot_at_bulk($db, $date);
    $deptMap = []; $posMap = [];
    foreach ($db->query("SELECT id, name, COALESCE(sort_order,999) so FROM department")->fetchAll(PDO::FETCH_ASSOC) as $d)
        $deptMap[(int)$d['id']] = ['name'=>(string)$d['name'], 'so'=>(int)$d['so']];
    foreach ($db->query("SELECT id, name, COALESCE(sort_order,999) so FROM position")->fetchAll(PDO::FETCH_ASSOC) as $pp)
        $posMap[(int)$pp['id']] = ['name'=>(string)$pp['name'], 'so'=>(int)$pp['so']];

    $out = [];
    foreach (hrf_employed_at_map($db, $date) as $uid => $u) {
        $resigned = (int)($u['state'] ?? 1) === 0;
        $leaveD = (string)($u['leave_date'] ?? '');
        $rows = $snaps[$uid] ?? [];
        $pick = null;
        foreach ($rows as $r) { if (!empty($r['is_main'])) { $pick = $r; break; } }
        if (!$pick) $pick = $rows[0] ?? null;
        $deptId = $pick ? (int)$pick['department_id'] : 0;
        $posId  = $pick ? (int)$pick['position_id'] : 0;
        $deptIds = [];
        foreach ($rows as $r) { $d = (int)$r['department_id']; if ($d && !in_array($d, $deptIds, true)) $deptIds[] = $d; }
        // 部門/職稱名稱優先用現在主檔的名稱（改名後看得懂），主檔查不到才用快照裡當時存的名稱
        $deptName = $deptMap[$deptId]['name'] ?? ($pick['department_name'] ?? '');
        $posName  = $posMap[$posId]['name']   ?? ($pick['position_name'] ?? '');
        $out[] = [
            'id'=>$uid, 'user_cname'=>(string)$u['user_cname'], 'state'=>(int)($u['state'] ?? 1),
            'state_label'=>eg_people_state_label($u['state'] ?? 1),
            'dept_id'=>$deptId ?: null, 'dept_name'=>$deptName, 'dept_sort'=>$deptMap[$deptId]['so'] ?? 999,
            'dept_ids'=>$deptIds,
            'position_id'=>$posId ?: null, 'position_name'=>$posName, 'position_sort'=>$posMap[$posId]['so'] ?? 999,
            'posts'=>hrf_user_posts($db, $uid, $date),
            'on_leave'=>0, 'leave_note'=>'',
            'resigned'=>$resigned ? 1 : 0,
            'resign_note'=>$resigned ? ('已離職' . ($leaveD !== '' ? '（' . $leaveD . '）' : '（離職日未登錄）')) : '',
        ];
    }
    // 人員列表鐵則5：排序依部門/職稱 sort_order，不是姓名筆畫
    usort($out, function ($a, $b) {
        return [$a['position_sort'], $a['dept_sort'], $a['id']] <=> [$b['position_sort'], $b['dept_sort'], $b['id']];
    });
    return $out;
}

/** 挑選器分組要用的部門名稱對照（含已不在現行清單/已停用的部門，前端查不到 id 時的退路） */
function hrf_dept_name_map(PDO $db): array {
    $m = [];
    foreach ($db->query("SELECT id, name FROM department")->fetchAll(PDO::FETCH_ASSOC) as $d) $m[(int)$d['id']] = (string)$d['name'];
    return $m;
}

/** NA 判定（使用者確認之規則）：確認人(直屬主管)解析到跟核准人(全站最高決策者)是同一人時，
 *  代表這位員工往上已經沒有「中階（課長考核）」這一層可以評，課長考核欄位視為 NA（不可填、印NA）。
 *  不硬編「課長」職稱字串，任何職位遇到相同情況都適用。 */
function hrf_confirm_is_na(PDO $db, int $targetUid, ?array $inst = null): bool {
    // 09 的 NA 判定同樣走確認池：本人就是最高決策者時，確認人＝核准人＝本人，等同「已無中間層可考核」
    $supPool = $inst ? hrf_confirm_pool($db, $inst, false) : hrf_supervisor_pool($db, $targetUid);
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
    $rows = $st->fetchAll(PDO::FETCH_ASSOC);
    // 附上適用部門×職位（新增欄位、不動既有欄位）：範本編輯畫面要在「對應另一種範本」的下拉裡直接顯示
    // 適用範圍，並自動預選 scope 有重疊的那筆，否則管理員得先記住哪個範本對哪個職位。
    foreach ($rows as &$r) $r['scope'] = hrf_template_scope_get($db, (int)$r['id']);
    return $rows;
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

function hrf_template_save(PDO $db, int $id, string $formType, string $name, ?int $listStampId, ?int $footerStampId, string $byName, bool $cpAutoFillDynamic = false, ?int $cpAutoFillSkillTplId = null): int {
    hrf_ensure_schema($db);
    if ($id) {
        $db->prepare("UPDATE hr_form_template SET name=?,list_stamp_tpl_id=?,footer_stamp_tpl_id=?,cp_auto_fill_dynamic=?,cp_auto_fill_skill_tpl_id=?,updated_at=NOW() WHERE id=?")
           ->execute([$name, $listStampId, $footerStampId, $cpAutoFillDynamic?1:0, $cpAutoFillSkillTplId, $id]);
        return $id;
    }
    $db->prepare("INSERT INTO hr_form_template (form_type,name,list_stamp_tpl_id,footer_stamp_tpl_id,created_by_name,cp_auto_fill_dynamic,cp_auto_fill_skill_tpl_id) VALUES (?,?,?,?,?,?,?)")
       ->execute([$formType, $name, $listStampId, $footerStampId, $byName, $cpAutoFillDynamic?1:0, $cpAutoFillSkillTplId]);
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
    $st = $db->prepare("SELECT m.id AS map_id, w.*, COALESCE(mm.machine_name, qt.machine) AS machine_name,
                        COALESCE(w.machine_model, qt.machine_model) AS whitelist_machine_model
                        FROM hr_form_template_machine m
                        JOIN hr_equipment_whitelist w ON w.id=m.whitelist_id
                        LEFT JOIN (SELECT machine_model, MIN(machine) AS machine_name FROM machine_list
                                   WHERE machine_model IS NOT NULL AND machine_model<>'' GROUP BY machine_model) mm
                               ON mm.machine_model = w.machine_model AND w.source_type='machine'
                        LEFT JOIN qc_tool qt ON qt.Tool_id = w.source_id AND w.source_type='tool'
                        WHERE m.template_id=? ORDER BY m.sort_order,m.id");
    $st->execute([$templateId]);
    return $st->fetchAll(PDO::FETCH_ASSOC);
}

/** 機型+機台/量具名稱兩行（機型換行接名稱）；沒機型只顯示名稱；一律不含機台編號/量具編號（10職能鑑定表項目清單格式，使用者明確要求）。 */
function hrf_skill_item_label(array $r): string {
    $model = trim((string)($r['machine_model'] ?? ($r['whitelist_machine_model'] ?? '')));
    $name = trim((string)($r['machine_name'] ?? ''));
    if ($model !== '' && $name !== '' && $name !== $model) return $model . "\n" . $name;
    if ($model !== '') return $model;
    if ($name !== '') return $name;
    return (string)($r['display_name'] ?? '');
}

/**
 * 10員工職能鑑定表「動態帶入」模式：依員工目前已有的09技能鑑定表(機型/量具)即時組項目清單，
 * 每筆技能鑑定表一列，標籤格式比照 hrf_skill_item_label()。建立當下組一次寫入 hr_form_instance_item，
 * 之後跟一般項目一樣可編輯，不會隨09表單增減自動再變動（比照本模組其他「建立當下snapshot」慣例）。
 * hr_form_instance 快照的 machine_display_name 對機台來源其實是 machine_model 的重複值(非真機台名稱)，
 * 名稱一律現查來源主檔(machine_list.machine / qc_tool.machine) 才拿得到真正的機台/量具名稱。
 */
function hrf_cp_dynamic_items_for_user(PDO $db, int $targetUid): array {
    $st = $db->prepare("SELECT i.machine_model, i.machine_display_name, w.source_type, w.source_id
                         FROM hr_form_instance i LEFT JOIN hr_equipment_whitelist w ON w.id=i.whitelist_id
                         WHERE i.form_type='skill_assess' AND i.user_id=? ORDER BY i.id");
    $st->execute([$targetUid]);
    $items = [];
    foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $row) {
        $model = ''; $name = '';
        try {
            if ($row['source_type'] === 'machine' && $row['source_id']) {
                $st2 = $db->prepare("SELECT machine_model, machine FROM machine_list WHERE machine_id=?");
                $st2->execute([$row['source_id']]);
                if ($m = $st2->fetch(PDO::FETCH_ASSOC)) { $model = trim((string)$m['machine_model']); $name = trim((string)$m['machine']); }
            } elseif ($row['source_type'] === 'tool' && $row['source_id']) {
                $st2 = $db->prepare("SELECT machine_model, machine FROM qc_tool WHERE Tool_id=?");
                $st2->execute([$row['source_id']]);
                if ($t = $st2->fetch(PDO::FETCH_ASSOC)) { $model = trim((string)$t['machine_model']); $name = trim((string)$t['machine']); }
            }
        } catch (Throwable $e) {}
        if ($model === '' && $name === '') {
            // 來源主檔已刪除或極舊資料無whitelist_id時才退回表單當下存的快照值
            $model = trim((string)($row['machine_model'] ?? ''));
            if ($model === '') $model = trim((string)($row['machine_display_name'] ?? ''));
        }
        $label = ($model !== '' && $name !== '' && $name !== $model) ? ($model . "\n" . $name) : ($model !== '' ? $model : $name);
        if ($label !== '') $items[] = ['data' => ['skill_name' => $label]];
    }
    return $items;
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
                       COALESCE(s.produce_competency,0) AS produce_competency,
                       COALESCE(s.cp_machine_setup,0) AS cp_machine_setup
                       FROM department d LEFT JOIN hr_form_dept_type_setting s ON s.department_id=d.id
                       ORDER BY d.sort_order, d.id");
    return $st->fetchAll(PDO::FETCH_ASSOC);
}

function hrf_dept_type_setting_save(PDO $db, int $deptId, bool $skillAssess, bool $competency, bool $cpMachineSetup = false): void {
    hrf_ensure_schema($db);
    $db->prepare("INSERT INTO hr_form_dept_type_setting (department_id,produce_skill_assess,produce_competency,cp_machine_setup) VALUES (?,?,?,?)
                  ON DUPLICATE KEY UPDATE produce_skill_assess=VALUES(produce_skill_assess), produce_competency=VALUES(produce_competency),
                                          cp_machine_setup=VALUES(cp_machine_setup)")
       ->execute([$deptId, $skillAssess?1:0, $competency?1:0, $cpMachineSetup?1:0]);
}

/**
 * 這個部門的職能鑑定表評分欄是不是三欄（機台設定／操作／異常排除）；沒設定＝兩欄（操作／異常排除）。
 * 使用者明確要求可逐部門多選（設定入口：人資職務表單設定→部門表單設定）。編號與項目名稱兩欄固定都有。
 */
function hrf_dept_cp_machine_setup(PDO $db, ?int $deptId): bool {
    if (!$deptId) return false;
    hrf_ensure_schema($db);
    $st = $db->prepare("SELECT cp_machine_setup FROM hr_form_dept_type_setting WHERE department_id=?");
    $st->execute([$deptId]);
    return (bool)$st->fetchColumn();
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

/**
 * 供設定頁勾選用：machine_list + qc_tool 來源清單，各自標記是否已在白名單內。
 * 機台來源一律套用 views/pm/process_schedule_NOW.php「機台設定」頁面的同一張表(machine_list)、
 * 同一套欄位認定：機型＝machine_model、機台編號＝asset_no（**禁止使用 field_no 現場編號**）。
 * 使用者明確要求：**技能鑑定考核是針對機型訓練，不是針對實體機台**——同一機型有多台機台編號也只算
 * 一個考核對象，所以這裡直接依 machine_model 去重分組（GROUP BY），不是每台機台各出現一列；
 * 挑一台代表機台的 machine_id 當白名單的 source_id，畫面上額外標出這個機型底下實際有幾台/哪些機台編號
 * 供管理員參考，但白名單本身、以及之後表單上顯示的都只有「機型」，不顯示機台名稱或機台種類。
 * machine_model 目前尚未填值的機台會被排除，並回傳 unmodeled_count 供頁面提示管理員先去機台設定頁補值。
 */
function hrf_whitelist_sources(PDO $db): array {
    $machines = [];
    $unmodeledCount = 0;
    try {
        $machines = $db->query("SELECT MIN(ml.machine_id) AS source_id, ml.machine_model AS display_name,
                                ml.machine_model AS machine_model, MIN(ml.machine) AS machine_name, COUNT(*) AS unit_count,
                                GROUP_CONCAT(DISTINCT ml.asset_no ORDER BY ml.asset_no SEPARATOR '、') AS asset_no,
                                MIN(pt.process_type) AS group_name
                                FROM machine_list ml LEFT JOIN process_type pt ON pt.process_type_id=ml.machine_type_id
                                WHERE (ml.state IS NULL OR ml.state=0) AND ml.machine_model IS NOT NULL AND ml.machine_model<>''
                                GROUP BY ml.machine_model ORDER BY group_name, ml.machine_model")->fetchAll(PDO::FETCH_ASSOC);
        $unmodeledCount = (int)$db->query("SELECT COUNT(*) FROM machine_list WHERE (state IS NULL OR state=0) AND (machine_model IS NULL OR machine_model='')")->fetchColumn();
    } catch (Throwable $e) {}
    $tools = [];
    $unmodeledToolCount = 0;
    try {
        // 量具比照機台：**填了同一個「機型」的量具合併成一筆**（技能鑑定考核是針對機型訓練，同機型的多支量具
        // 只算一個考核對象；2026-08-18 使用者要求，先前是逐支列出），畫面另外標出共幾支與各支量具編號。
        // 尚未填機型的量具沒有可合併的依據，維持逐支列出（機台那邊是直接不列＋提示補值，但量具目前多數
        // 沒填機型，全部不列會讓既有白名單挑不到，故改成照列並另外提示）。量具編號(Tool_No)＝機台編號等同角色。
        $tools = $db->query("SELECT MIN(t.Tool_id) AS source_id,
                             COALESCE(MIN(NULLIF(TRIM(t.machine_model),'')), MIN(t.Tool_No)) AS display_name,
                             GROUP_CONCAT(DISTINCT t.Tool_No ORDER BY t.Tool_No SEPARATOR '、') AS asset_no,
                             COUNT(*) AS unit_count, MIN(t.machine) AS machine_name,
                             MIN(NULLIF(TRIM(t.machine_model),'')) AS machine_model, MIN(l.QC_Tool) AS group_name
                             FROM qc_tool t LEFT JOIN qc_tool_list l ON l.QC_Tool_List_id=t.QC_Tool_List_id
                             WHERE (t.state IS NULL OR t.state=0)
                             GROUP BY CASE WHEN TRIM(COALESCE(t.machine_model,''))<>'' THEN CONCAT('m:',TRIM(t.machine_model))
                                           ELSE CONCAT('t:',t.Tool_id) END
                             ORDER BY MIN(l.sort_order), display_name")->fetchAll(PDO::FETCH_ASSOC);
        $unmodeledToolCount = (int)$db->query("SELECT COUNT(*) FROM qc_tool WHERE (state IS NULL OR state=0)
                                               AND (machine_model IS NULL OR TRIM(machine_model)='')")->fetchColumn();
    } catch (Throwable $e) {}
    $existing = [];
    try {
        $st = $db->query("SELECT source_type, source_id, id FROM hr_equipment_whitelist");
        foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $r) $existing[$r['source_type'] . ':' . $r['source_id']] = $r;
    } catch (Throwable $e) {}
    foreach ($machines as &$m) { $m['unit_count'] = (int)$m['unit_count']; $m['checked'] = isset($existing['machine:' . $m['source_id']]); }
    unset($m);
    // 同機型的量具合併後，代表量具是 MIN(Tool_id)；白名單若是勾到同組的其他支（合併前存的），
    // 這一組一樣要算「已勾選」，否則設定頁會顯示沒勾、一存檔就把既有選取洗掉。
    $toolGroupIds = hrf_tool_group_member_ids($db);
    foreach ($tools as &$t) {
        $t['unit_count'] = (int)$t['unit_count'];
        $t['checked'] = false;
        foreach ($toolGroupIds[(int)$t['source_id']] ?? [(int)$t['source_id']] as $tid) {
            if (isset($existing['tool:' . $tid])) { $t['checked'] = true; break; }
        }
    }
    unset($t);
    return ['machines' => $machines, 'tools' => $tools, 'unmodeled_count' => $unmodeledCount,
            'unmodeled_tool_count' => $unmodeledToolCount];
}

/**
 * machine_name/machine_model 即時對照來源主檔取得（machine 依 machine_model 對 machine_list、tool 直接對
 * qc_tool 該支量具本身），供畫面統一顯示「機型 機台名稱」用，兩種來源顯示格式比照辦理。
 * 另外回傳 group_name（機台類型/量具類別）、unit_count、asset_no_list，讓消費端（範本跳窗的適用機型、
 * 建立表單的手動指定）能跟設定頁一樣「同機台類型放一起並標示」。
 * stale=1＝這筆白名單的來源已停用/機型被清空/已不在設定頁候選清單（2026-08-18 使用者回報 P40 在前端
 * 清單出現兩次、設定頁只有一次：machine_list 的 P40(id=1895)/TTI-300H(id=1896) 已 state=1 停用，設定頁
 * 不列所以取消不掉，白名單舊列卻仍 is_active=1，就跟 qc_tool 的同名量具撞成兩列）。這種列不刪（既有範本
 * 還引用著，直接刪會讓範本內容悄悄變少），改成標記後由前端預設隱藏、只有既有範本已勾選的才顯示並標紅。
 */
function hrf_whitelist_list(PDO $db): array {
    hrf_ensure_schema($db);
    $rows = $db->query("SELECT w.* FROM hr_equipment_whitelist w WHERE w.is_active=1 ORDER BY w.sort_order,w.id")
               ->fetchAll(PDO::FETCH_ASSOC);
    // 設定頁候選清單就是權威畫面（機台依機型去重分組、附機台類型/台數/機台編號；量具逐支附量具類別），
    // 這裡直接沿用同一支函式的結果去對照，前端清單才會跟設定頁長得一樣、也不會各自算出不同分組。
    $src = hrf_whitelist_sources($db);
    $byModel = [];
    foreach ($src['machines'] as $m) $byModel[(string)$m['machine_model']] = $m;
    $byTool = [];
    foreach ($src['tools'] as $t) $byTool[(int)$t['source_id']] = $t;
    // 該組任一支 Tool_id => 該組的代表列（白名單舊列存的可能不是代表那支）
    $toolRep = [];
    foreach (hrf_tool_group_member_ids($db) as $repId => $ids) {
        if (!isset($byTool[$repId])) continue;
        foreach ($ids as $tid) $toolRep[$tid] = $byTool[$repId];
    }
    // 白名單存的是「代表機台」的 machine_id，機台之後可能被停用或機型被清空 → 一律用 machine_list 當下現況回推
    $cur = [];
    try {
        foreach ($db->query("SELECT machine_id, machine, machine_model, state FROM machine_list") as $r) {
            $cur[(int)$r['machine_id']] = $r;
        }
    } catch (Throwable $e) {}

    $out = [];
    $seen = [];
    foreach ($rows as $w) {
        $w['group_name'] = '';
        $w['unit_count'] = 1;
        $w['asset_no_list'] = '';
        $w['stale'] = 0;
        $w['stale_reason'] = '';
        if (($w['source_type'] ?? '') === 'machine') {
            $ml = $cur[(int)$w['source_id']] ?? null;
            $model = $ml ? trim((string)$ml['machine_model']) : '';
            if (!$ml)                                  { $w['stale'] = 1; $w['stale_reason'] = '來源機台資料已不存在'; }
            elseif ((int)($ml['state'] ?? 0) !== 0)    { $w['stale'] = 1; $w['stale_reason'] = '來源機台已停用'; }
            elseif ($model === '')                     { $w['stale'] = 1; $w['stale_reason'] = '來源機台尚未填寫機型'; }
            elseif (!isset($byModel[$model]))          { $w['stale'] = 1; $w['stale_reason'] = '已不在白名單候選清單內'; }
            if ($w['stale']) {
                $w['machine_name'] = $ml['machine'] ?? null;
            } else {
                $m = $byModel[$model];
                $w['group_name'] = (string)($m['group_name'] ?: '未分類');
                $w['unit_count'] = (int)$m['unit_count'];
                $w['asset_no_list'] = (string)($m['asset_no'] ?? '');
                $w['machine_model'] = $model;
                $w['machine_name'] = $m['machine_name'];
                // 同一個機型只留一筆（比照設定頁「同機型只算一個考核對象」的去重），避免同機型的
                // 兩台機台各留過一筆白名單時，前端清單同一個機型印出兩列。
                $key = 'model:' . $model;
                if (isset($seen[$key])) continue;
                $seen[$key] = 1;
            }
        } else {
            // 合併後挑選清單的代表量具是 MIN(Tool_id)：白名單存的若是同組另一支（合併前勾的），
            // 一樣要對到那一組，不能當成失效。
            $t = $byTool[(int)$w['source_id']] ?? ($toolRep[(int)$w['source_id']] ?? null);
            if (!$t) { $w['stale'] = 1; $w['stale_reason'] = '來源量具已停用或已不存在'; }
            else {
                $w['group_name'] = (string)($t['group_name'] ?: '未分類');
                $w['unit_count'] = (int)($t['unit_count'] ?? 1);
                $w['asset_no_list'] = (string)($t['asset_no'] ?? '');
                $w['machine_name'] = $t['machine_name'];
                $w['machine_model'] = $t['machine_model'];
                $w['display_name'] = (string)$t['display_name'];
                // 同機型只留一筆（比照機台）：同組的第二筆白名單不再重複列出
                $key = 'tool:' . (int)$t['source_id'];
                if (isset($seen[$key])) continue;
                $seen[$key] = 1;
            }
        }
        if (!array_key_exists('machine_name', $w)) $w['machine_name'] = null;
        $w['whitelist_machine_model'] = $w['machine_model'] ?? null;   // 相容既有前端欄位名
        $out[] = $w;
    }
    // 排序比照設定頁：先分組（未分類墊底），組內依機型/量具編號；已失效的一律排最後。
    usort($out, function ($a, $b) {
        if ((int)$a['stale'] !== (int)$b['stale']) return (int)$a['stale'] <=> (int)$b['stale'];
        $ea = ($a['group_name'] === '') ? 1 : 0;
        $eb = ($b['group_name'] === '') ? 1 : 0;
        if ($ea !== $eb) return $ea <=> $eb;                        // 沒有分類的墊底
        if ($a['group_name'] !== $b['group_name']) return strcmp((string)$a['group_name'], (string)$b['group_name']);
        return strcmp((string)$a['display_name'], (string)$b['display_name']);
    });
    return $out;
}

/**
 * 整批覆寫白名單（設定頁一次送出全部勾選狀態，比逐筆增刪簡單可靠）。$entries=[['source_type','source_id'],...]，
 * machine 的 source_id 是「代表機台」的 machine_id（由 hrf_whitelist_sources() 依機型分組時挑出）。
 * 機型/機台編號一律當下重新查詢來源表取得權威值，不採信前端送來的文字（使用者明確要求「取消設定項目
 * 名稱」）；display_name 固定＝機型本身（不是機台名稱/機台種類——技能鑑定考核是針對機型訓練，同機型
 * 多台機台編號只算同一個考核對象）。
 */
function hrf_whitelist_save(PDO $db, array $entries, string $byName): void {
    hrf_ensure_schema($db);
    $db->beginTransaction();
    try {
        // 只下架「畫面上這次真的看得到、能勾選」的列：機台類要 machine_list 當下已有 machine_model 才下架，
        // 沒填機型的機台維持原狀不動（避免機型欄位還沒補值時存檔，把既有選取的機台白名單整批誤刪）；
        // 量具沒有機型去重的問題，維持原本整批下架再重新上架的邏輯。
        $db->exec("UPDATE hr_equipment_whitelist w
                   JOIN machine_list ml ON ml.machine_id=w.source_id AND w.source_type='machine'
                   SET w.is_active=0 WHERE ml.machine_model IS NOT NULL AND ml.machine_model<>''");
        $db->exec("UPDATE hr_equipment_whitelist SET is_active=0 WHERE source_type='tool'");
        $insMachine = $db->prepare("INSERT INTO hr_equipment_whitelist (source_type,source_id,display_name,machine_model,asset_no,created_by)
                                    SELECT 'machine', machine_id, machine_model, machine_model, asset_no, ? FROM machine_list WHERE machine_id=?
                                    ON DUPLICATE KEY UPDATE display_name=VALUES(display_name), machine_model=VALUES(machine_model), asset_no=VALUES(asset_no), is_active=1");
        // display_name 比照機台＝「機型」本身（同機型的量具已在挑選清單合併成一筆），沒填機型才退回量具編號
        $insTool = $db->prepare("INSERT INTO hr_equipment_whitelist (source_type,source_id,display_name,machine_model,asset_no,created_by)
                                 SELECT 'tool', Tool_id, COALESCE(NULLIF(TRIM(machine_model),''), Tool_No),
                                        NULLIF(TRIM(machine_model),''), Tool_No, ? FROM qc_tool WHERE Tool_id=?
                                 ON DUPLICATE KEY UPDATE display_name=VALUES(display_name), machine_model=VALUES(machine_model),
                                                         asset_no=VALUES(asset_no), is_active=1");
        foreach ($entries as $e) {
            $type = ($e['source_type'] ?? '') === 'tool' ? 'tool' : 'machine';
            $sid = (int)($e['source_id'] ?? 0);
            if ($sid <= 0) continue;
            if ($type === 'tool') $insTool->execute([$byName, $sid]);
            else $insMachine->execute([$byName, $sid]);
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

/**
 * 依機型(machine_model)即時查 machine_list：代表機台名稱＋目前所有「未停用」機台編號清單。
 * 供09表單頭「機型」列右側附帶顯示機台名稱、下一格列出機台編號，畫面與列印共用同一份資料
 * （view/print 都吃 hrf_instance_get() 回傳值），現查現組不快照，機台若改名/新增編號會自動反映最新狀態。
 */
function hrf_machine_asset_info(PDO $db, ?string $machineModel): array {
    $machineModel = trim((string)($machineModel ?? ''));
    if ($machineModel === '') return ['name' => '', 'asset_nos' => []];
    $name = '';
    $assetNos = [];
    try {
        $st = $db->prepare("SELECT machine, asset_no, state FROM machine_list WHERE machine_model=?
                             ORDER BY (state IS NULL OR state=0) DESC, machine_id");
        $st->execute([$machineModel]);
        foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $row) {
            if ($name === '' && trim((string)$row['machine']) !== '') $name = trim($row['machine']);
            $assetNo = trim((string)($row['asset_no'] ?? ''));
            if (($row['state'] === null || (int)$row['state'] === 0) && $assetNo !== '') $assetNos[] = $assetNo;
        }
    } catch (Throwable $e) {}
    return ['name' => $name, 'asset_nos' => array_values(array_unique($assetNos))];
}

/**
 * 量具(qc_tool)來源的09表單顯示欄位：機型＝qc_tool.machine_model、名稱＝qc_tool.machine、
 * **量具編號(Tool_No)視同機台編號（公司財產編號）**，所以顯示格式與機台來源完全一致
 * （「機型 名稱」＋編號標籤）。白名單舊列的 machine_model/asset_no 是 qc_tool 加欄位之前存的
 * NULL 快照，一律現查 qc_tool 覆蓋，不採信快照（比照本檔其他「名稱一律現查來源主檔」的作法）。
 */
function hrf_apply_tool_display(array &$r, array $t, ?array $groupInfo = null): void {
    $model = trim((string)($t['machine_model'] ?? ''));
    $name  = trim((string)($t['machine'] ?? ''));
    $no    = trim((string)($t['Tool_No'] ?? ''));
    if ($model !== '') {
        $r['machine_model'] = $model;
    } elseif ($name !== '') {
        // 還沒填機型：機型欄留空、改用名稱當顯示主體，避免印出「QC-002 齒輪檢測機 (QC-002)」重複編號
        $r['machine_model'] = null;
        $r['machine_display_name'] = $name;
    }
    $r['machine_real_name'] = ($groupInfo && $groupInfo['name'] !== '') ? $groupInfo['name'] : $name;
    // 有填機型＝同機型的量具是同一個考核對象，編號要全列（比照機台來源列出同機型所有機台編號）
    $r['machine_asset_nos'] = ($model !== '' && $groupInfo && $groupInfo['asset_nos'])
        ? array_values(array_unique($groupInfo['asset_nos']))
        : ($no !== '' ? [$no] : []);
    $r['machine_is_tool'] = 1;
}
/**
 * 量具依機型分組：代表量具 Tool_id => 該組所有 Tool_id（含自己）。沒填機型的量具自成一組。
 * 用途一：白名單勾選狀態比對（合併前可能勾的是同組另一支）。用途二：反查某支量具屬於哪一組。
 */
function hrf_tool_group_member_ids(PDO $db): array {
    $out = [];
    try {
        $rows = $db->query("SELECT Tool_id, NULLIF(TRIM(machine_model),'') AS model FROM qc_tool
                            WHERE (state IS NULL OR state=0) ORDER BY Tool_id")->fetchAll(PDO::FETCH_ASSOC);
        $byModel = [];
        foreach ($rows as $r) {
            $key = ($r['model'] !== null && $r['model'] !== '') ? 'm:' . $r['model'] : 't:' . $r['Tool_id'];
            $byModel[$key][] = (int)$r['Tool_id'];
        }
        foreach ($byModel as $ids) { sort($ids); $out[$ids[0]] = $ids; }
    } catch (Throwable $e) {}
    return $out;
}

/**
 * 某個機型底下所有未停用量具的名稱與量具編號（機型空白＝只有這一支）。
 * 09技能鑑定考核表顯示量具時比照機台：印出「機型 名稱」＋同機型所有量具編號（2026-08-18 使用者要求）。
 * @return array [model => ['name'=>..., 'asset_nos'=>[...]]]
 */
function hrf_tool_model_info_map(PDO $db, array $models): array {
    $models = array_values(array_unique(array_filter(array_map(fn($m) => trim((string)$m), $models))));
    if (!$models) return [];
    $map = [];
    try {
        $in = implode(',', array_fill(0, count($models), '?'));
        $st = $db->prepare("SELECT Tool_No, machine, TRIM(machine_model) AS machine_model FROM qc_tool
                            WHERE (state IS NULL OR state=0) AND TRIM(machine_model) IN ($in) ORDER BY Tool_No");
        $st->execute($models);
        foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $t) {
            $m = (string)$t['machine_model'];
            if (!isset($map[$m])) $map[$m] = ['name' => '', 'asset_nos' => []];
            if ($map[$m]['name'] === '' && trim((string)$t['machine']) !== '') $map[$m]['name'] = trim((string)$t['machine']);
            $no = trim((string)$t['Tool_No']);
            if ($no !== '') $map[$m]['asset_nos'][] = $no;
        }
    } catch (Throwable $e) {}
    return $map;
}

/** 取回這批 Tool_id 的量具資料（Tool_id => row），供列表批次套用顯示欄位。 */
function hrf_tool_info_map(PDO $db, array $toolIds): array {
    $ids = array_values(array_unique(array_filter(array_map('intval', $toolIds))));
    if (!$ids) return [];
    $map = [];
    try {
        $in = implode(',', array_fill(0, count($ids), '?'));
        $st = $db->prepare("SELECT Tool_id, Tool_No, machine, machine_model FROM qc_tool WHERE Tool_id IN ($in)");
        $st->execute($ids);
        foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $t) $map[(int)$t['Tool_id']] = $t;
    } catch (Throwable $e) {}
    return $map;
}

function hrf_instance_get(PDO $db, int $id): ?array {
    hrf_ensure_schema($db);
    $st = $db->prepare("SELECT * FROM hr_form_instance WHERE id=?");
    $st->execute([$id]);
    $r = $st->fetch(PDO::FETCH_ASSOC);
    if (!$r) return null;
    if (in_array($r['form_type'], ['job_desc','competency'], true)) $r['items'] = hrf_instance_items_get($db, $id);
    if ($r['form_type'] === 'skill_assess') {
        $r['confirm_na'] = hrf_confirm_is_na($db, (int)$r['user_id'], $r);
        $wl = null;
        if (!empty($r['whitelist_id'])) {
            $stw = $db->prepare("SELECT source_type, source_id FROM hr_equipment_whitelist WHERE id=?");
            $stw->execute([(int)$r['whitelist_id']]);
            $wl = $stw->fetch(PDO::FETCH_ASSOC) ?: null;
        }
        $tool = ($wl && $wl['source_type'] === 'tool') ? (hrf_tool_info_map($db, [(int)$wl['source_id']])[(int)$wl['source_id']] ?? null) : null;
        if ($tool) {
            $gm = hrf_tool_model_info_map($db, [$tool['machine_model'] ?? '']);
            hrf_apply_tool_display($r, $tool, $gm[trim((string)($tool['machine_model'] ?? ''))] ?? null);
        } else {
            $info = hrf_machine_asset_info($db, $r['machine_model'] ?? null);
            $r['machine_real_name'] = $info['name'];
            $r['machine_asset_nos'] = $info['asset_nos'];
        }
    }
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
    // 排序一律「部門 → 職稱 → 姓名」，且部門/職稱依主檔 sort_order 高低，不是姓名筆畫（ai-rules/08 第五節鐵則6）。
    // 清單與批次列印吃的是同一份結果，所以列印順序自動跟著一致（2026-08-18 使用者要求）。
    $sql = "SELECT i.*, pl.level AS target_level, w.source_type AS wl_source_type, w.source_id AS wl_source_id
            FROM hr_form_instance i
            LEFT JOIN position_level pl ON pl.position_id = i.position_id
            LEFT JOIN hr_equipment_whitelist w ON w.id = i.whitelist_id
            LEFT JOIN department d ON d.id = i.dept_id
            LEFT JOIN position p ON p.id = i.position_id
            WHERE " . implode(' AND ', $where) . "
            ORDER BY COALESCE(d.sort_order, 999), i.dept_name,
                     COALESCE(p.sort_order, 999), i.position_name,
                     i.user_cname, i.whitelist_id, i.id DESC";
    $st = $db->prepare($sql);
    $st->execute($params);
    $rows = $st->fetchAll(PDO::FETCH_ASSOC);
    // 列表用「機型 機台名稱＋機台編號」批次查一次 machine_list / qc_tool，避免逐列各查一次
    $models = []; $toolIds = [];
    foreach ($rows as $r) {
        if ($r['form_type'] !== 'skill_assess') continue;
        if (($r['wl_source_type'] ?? '') === 'tool') { $toolIds[] = (int)$r['wl_source_id']; continue; }
        if (!empty($r['machine_model'])) $models[$r['machine_model']] = true;
    }
    $toolMap = hrf_tool_info_map($db, $toolIds);
    $toolModelMap = hrf_tool_model_info_map($db, array_column($toolMap, 'machine_model'));
    $assetMap = [];
    if ($models) {
        $in = implode(',', array_fill(0, count($models), '?'));
        $st2 = $db->prepare("SELECT machine_model, machine, asset_no, state FROM machine_list WHERE machine_model IN ($in)");
        $st2->execute(array_keys($models));
        foreach ($st2->fetchAll(PDO::FETCH_ASSOC) as $m) {
            $mm = $m['machine_model'];
            if (!isset($assetMap[$mm])) $assetMap[$mm] = ['name' => '', 'asset_nos' => []];
            if ($assetMap[$mm]['name'] === '' && trim((string)$m['machine']) !== '') $assetMap[$mm]['name'] = trim($m['machine']);
            $an = trim((string)($m['asset_no'] ?? ''));
            if (($m['state'] === null || (int)$m['state'] === 0) && $an !== '') $assetMap[$mm]['asset_nos'][] = $an;
        }
    }
    foreach ($rows as &$r) {
        $r['target_level'] = $r['target_level'] === null ? null : (int)$r['target_level'];
        if ($r['form_type'] === 'skill_assess') {
            $r['confirm_na'] = hrf_confirm_is_na($db, (int)$r['user_id'], $r);
            $tool = (($r['wl_source_type'] ?? '') === 'tool') ? ($toolMap[(int)$r['wl_source_id']] ?? null) : null;
            if ($tool) {
                hrf_apply_tool_display($r, $tool, $toolModelMap[trim((string)$tool['machine_model'])] ?? null);
            } else {
                $info = $assetMap[$r['machine_model']] ?? ['name' => '', 'asset_nos' => []];
                $r['machine_real_name'] = $info['name'];
                $r['machine_asset_nos'] = array_values(array_unique($info['asset_nos']));
            }
        }
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
        $data = $it['data'] ?? [];
        // 10 職能鑑定表 機台設定/操作/異常排除 分數範圍後端夾緣(1~4)，前端只是即時提示，真正把關在這裡（其餘欄位如01的文字內容不受影響）。
        if (is_array($data)) {
            foreach (['score_ms', 'score_op', 'score_ex'] as $k) {
                if (array_key_exists($k, $data)) {
                    $v = $data[$k];
                    $data[$k] = ($v === '' || $v === null) ? null : max(1, min(4, (int)$v));
                }
            }
        }
        $dataJson = json_encode($data === [] ? new stdClass() : $data, JSON_UNESCAPED_UNICODE);
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

/** 建立單一員工單一機型(09)或單一表單(10)的一筆表單。01(job_desc) 改走 hrf_instance_create_job_desc()，以部門x職位為主不綁單一員工。 */
function hrf_instance_create_one(PDO $db, string $formType, int $targetUid, ?int $whitelistId, string $bizDate, int $byUid, string $byName, ?array $post = null): array {
    hrf_ensure_schema($db);
    if (!isset(HRF_FORM_TYPES[$formType]) || $formType === 'job_desc') return ['ok'=>false, 'msg'=>'不明的表單類型'];
    // $post＝指定的部門×職位（職能鑑定表一人一職務一張）。前端送什麼都要在這裡對照該員工當時真的有的職務，
    // 不採信前端（可繞過畫面直打 API），對不上就當沒指定不了了之會建錯人，所以直接擋下。
    if ($post !== null) {
        $want = ((int)($post['department_id'] ?? 0)) . '-' . ((int)($post['position_id'] ?? 0));
        $ok = false;
        foreach (hrf_user_posts($db, $targetUid, $bizDate) as $pp) {
            if (((int)$pp['department_id']) . '-' . ((int)$pp['position_id']) === $want) { $ok = true; break; }
        }
        if (!$ok) return ['ok'=>false, 'msg'=>'指定的職務不是該員工當時擔任的職務'];
    }
    $snap = hrf_user_snapshot($db, $targetUid, $bizDate, $post);   // 補歷史表單：部門/職稱依業務日期回推
    if (!$snap) return ['ok'=>false, 'msg'=>'找不到此員工'];
    if (!hrf_dept_can_produce($db, $snap['dept_id'], $formType)) {
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
            // 量具來源的 display_name 是量具編號(QC-002)，訊息改顯示「機型 名稱」與畫面一致
            $wlLabel = (string)$whitelist['display_name'];
            if ($whitelist['source_type'] === 'tool') {
                $t = hrf_tool_info_map($db, [(int)$whitelist['source_id']])[(int)$whitelist['source_id']] ?? null;
                if ($t) $wlLabel = trim(trim((string)$t['machine_model']) . ' ' . trim((string)$t['machine'])) ?: $wlLabel;
            }
            return ['ok'=>false, 'duplicate'=>true, 'existing_id'=>(int)$existingId, 'msg'=>$snap['user_cname'].'（'.$wlLabel.'）已建立過，不重複建立'];
        }
    }
    if ($formType === 'competency') {
        // 一年一次評鑑（2026-08-18 使用者明確要求）：同員工同部門同職位，只有在**業務日期前後 11 個月內**
        // 已有表單時才算重複不再建立；超過這個範圍＝新的一年度，要建新的一張（原本是不分日期只准一張，
        // 那樣隔年就永遠建不出來）。
        [$wFrom, $wTo] = hrf_cp_window($bizDate);
        $dup = $db->prepare("SELECT id, business_date FROM hr_form_instance WHERE form_type='competency'
                             AND user_id=? AND dept_id<=>? AND position_id<=>? AND business_date BETWEEN ? AND ?
                             ORDER BY business_date DESC, id DESC LIMIT 1");
        $dup->execute([$targetUid, $snap['dept_id'], $snap['position_id'], $wFrom, $wTo]);
        if ($ex = $dup->fetch(PDO::FETCH_ASSOC)) {
            return ['ok'=>false, 'duplicate'=>true, 'existing_id'=>(int)$ex['id'],
                    'msg'=>$snap['user_cname'].'（'.($snap['dept_name']?:'').'/'.($snap['position_name']?:'').'）'
                          .eg_fmt_date($ex['business_date']).' 已建立過（前後 '.HRF_CP_ANNUAL_MONTHS.' 個月內視為同一年度那次），不重複建立'];
        }
    }
    $needSign = in_array($formType, ['skill_assess', 'competency'], true);
    $db->prepare("INSERT INTO hr_form_instance
        (form_type,user_id,dept_id,position_id,template_id,business_date,status,created_by,created_by_name,
         user_no,user_cname,dept_name,position_name,supervisor_name,onboard_date,whitelist_id,machine_display_name,machine_model,cp_update_date)
        VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?)")
       ->execute([
            $formType, $targetUid, $snap['dept_id'], $snap['position_id'], $tpl['id'], $bizDate, $needSign ? 'draft' : 'active',
            $byUid, $byName,
            $snap['user_no'], $snap['user_cname'], $snap['dept_name'], $snap['position_name'], $snap['supervisor_name'], $snap['onboard_date'],
            $whitelist['id'] ?? null, $whitelist['display_name'] ?? null, $whitelist['machine_model'] ?? null,
            $formType === 'competency' ? $bizDate : null,
        ]);
    $iid = (int)$db->lastInsertId();
    if ($formType === 'competency' && !empty($tpl['cp_auto_fill_dynamic'])) {
        $items = hrf_cp_dynamic_items_for_user($db, $targetUid);
        if ($items) hrf_instance_items_save($db, $iid, $items);
    } elseif ($formType !== 'skill_assess') {
        $items = [];
        foreach (($tpl['items'] ?? []) as $it) $items[] = ['item_no'=>$it['item_no'], 'data'=>$it['data']];
        if ($items) hrf_instance_items_save($db, $iid, $items);
    }
    return ['ok'=>true, 'id'=>$iid];
}

/** 目前有實際在職人員掛著的「部門×職位」組合(含兼任)，每組附上人數；供01建立表單挑選、也是「還缺誰」建立建議的資料來源。 */
function hrf_dept_position_pairs(PDO $db): array {
    return $db->query("SELECT m.department_id AS dept_id, d.name AS dept_name, m.position_id, p.name AS position_name,
                       COUNT(DISTINCT m.user_id) AS holder_count
                       FROM user_department_position_map m
                       JOIN user u ON u.id=m.user_id AND COALESCE(u.state,1) NOT IN (0,90)
                       JOIN department d ON d.id=m.department_id
                       JOIN position p ON p.id=m.position_id
                       GROUP BY m.department_id, m.position_id
                       ORDER BY d.sort_order, p.sort_order")->fetchAll(PDO::FETCH_ASSOC);
}

/** 全站最高決策者目前的主要部門×職位（唯一不強制要求職務說明書的對象，使用者明確要求）。 */
function hrf_top_approver_dept_position(PDO $db): ?array {
    $top = eg_org_user($db, 'top_approver');
    if (!$top) return null;
    $main = eg_user_main_identity($db, (int)$top['id']);
    if (!$main || !$main['department_id'] || !$main['position_id']) return null;
    return ['dept_id' => (int)$main['department_id'], 'position_id' => (int)$main['position_id']];
}

/**
 * 建立單一「部門×職位」的職務說明書（01 以部門×職位為主，不綁單一員工；使用者明確要求：有人的部門職位就要有說明書，
 * 表單上不顯示工號/姓名/到職日/直屬主管，只顯示部門與職稱）。同一部門×職位已建立過就不重複建立。
 */
function hrf_instance_create_job_desc(PDO $db, int $deptId, int $positionId, string $bizDate, int $byUid, string $byName): array {
    hrf_ensure_schema($db);
    $d = $db->prepare("SELECT name FROM department WHERE id=?"); $d->execute([$deptId]); $deptName = $d->fetchColumn();
    $p = $db->prepare("SELECT name FROM position WHERE id=?"); $p->execute([$positionId]); $posName = $p->fetchColumn();
    if (!$deptName || !$posName) return ['ok'=>false, 'msg'=>'部門或職位不存在'];
    $label = $deptName . '／' . $posName;

    $dup = $db->prepare("SELECT id FROM hr_form_instance WHERE form_type='job_desc' AND dept_id=? AND position_id=? LIMIT 1");
    $dup->execute([$deptId, $positionId]);
    if ($existingId = $dup->fetchColumn()) {
        return ['ok'=>false, 'duplicate'=>true, 'existing_id'=>(int)$existingId, 'msg'=>$label.'已建立過，不重複建立'];
    }
    $tpl = hrf_match_template($db, 'job_desc', $deptId, $positionId);
    if (!$tpl) return ['ok'=>false, 'msg'=>$label.' 尚未建立適用的職位範本，請聯絡管理員'];

    $db->prepare("INSERT INTO hr_form_instance
        (form_type,user_id,dept_id,position_id,template_id,business_date,status,created_by,created_by_name,dept_name,position_name)
        VALUES ('job_desc',NULL,?,?,?,?,'active',?,?,?,?)")
       ->execute([$deptId, $positionId, $tpl['id'], $bizDate, $byUid, $byName, $deptName, $posName]);
    $iid = (int)$db->lastInsertId();
    $items = [];
    foreach (($tpl['items'] ?? []) as $it) $items[] = ['item_no'=>$it['item_no'], 'data'=>$it['data']];
    if ($items) hrf_instance_items_save($db, $iid, $items);
    return ['ok'=>true, 'id'=>$iid];
}

/** 批次建立 01：$pairs=[['dept_id'=>,'position_id'=>],...]。 */
function hrf_instance_create_batch_job_desc(PDO $db, array $pairs, string $bizDate, int $byUid, string $byName): array {
    hrf_ensure_schema($db);
    $created = []; $errors = []; $skipped = [];
    foreach ($pairs as $pair) {
        $deptId = (int)($pair['dept_id'] ?? 0);
        $posId = (int)($pair['position_id'] ?? 0);
        if (!$deptId || !$posId) continue;
        $r = hrf_instance_create_job_desc($db, $deptId, $posId, $bizDate, $byUid, $byName);
        if ($r['ok']) $created[] = $r['id'];
        elseif (!empty($r['duplicate'])) $skipped[] = $r['msg'];
        else $errors[] = $r['msg'];
    }
    return ['created'=>$created, 'errors'=>$errors, 'skipped'=>$skipped];
}

/**
 * 批次建立。09(skill_assess) 會做「員工 x 機型」交叉：$whitelistIds 有值＝手動指定套用到所有選取員工；
 * 空陣列＝依各員工比對到的職位範本機型清單各自展開。01/10 忽略 $whitelistIds。
 * 回傳 ['created'=>[instance_id...], 'errors'=>['員工姓名: 錯誤訊息', ...]]
 */
function hrf_instance_create_batch(PDO $db, string $formType, array $targetUids, array $whitelistIds, string $bizDate, int $byUid, string $byName): array {
    hrf_ensure_schema($db);
    $created = []; $errors = []; $skipped = [];
    // $targetUids 的元素可以是 user_id，也可以是 ['user_id'=>,'department_id'=>,'position_id'=>]（職能鑑定表
    // 一人一職務一張，同一人可能要建多張＝多個職務，所以不能再用 array_unique 把同一人壓成一筆）。
    $targets = [];
    foreach ($targetUids as $t) {
        if (is_array($t)) {
            $uid = (int)($t['user_id'] ?? 0);
            if ($uid <= 0) continue;
            $targets[] = ['uid'=>$uid, 'post'=>['department_id'=>(int)($t['department_id'] ?? 0), 'position_id'=>(int)($t['position_id'] ?? 0)]];
        } else {
            $uid = (int)$t;
            if ($uid > 0) $targets[] = ['uid'=>$uid, 'post'=>null];
        }
    }
    $seen = [];
    foreach ($targets as $tg) {
        $uid = $tg['uid']; $post = $tg['post'];
        $key = $uid . '|' . ($post ? $post['department_id'] . '-' . $post['position_id'] : '*');
        if (isset($seen[$key])) continue;
        $seen[$key] = 1;
        $snap = hrf_user_snapshot($db, $uid, $bizDate, $post);
        $label = $snap['user_cname'] ?? ('#' . $uid);
        if ($post && ($snap['position_name'] ?? '')) $label .= '（' . ($snap['dept_name'] ?: '') . '/' . $snap['position_name'] . '）';
        if ($formType !== 'skill_assess') {
            $r = hrf_instance_create_one($db, $formType, $uid, null, $bizDate, $byUid, $byName, $post);
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
         user_no,user_cname,dept_name,position_name,supervisor_name,onboard_date,whitelist_id,machine_display_name,machine_model,
         score_quality_gm,score_quality_mgr,score_efficiency_gm,score_efficiency_mgr,score_proficiency_gm,score_proficiency_mgr)
        VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?)")
       ->execute([
            $src['form_type'], $src['user_id'], $src['dept_id'], $src['position_id'], $src['template_id'], date('Y-m-d'), $needSign ? 'draft' : 'active',
            $byUid, $byName,
            $src['user_no'], $src['user_cname'], $src['dept_name'], $src['position_name'], $src['supervisor_name'], $src['onboard_date'],
            $src['whitelist_id'], $src['machine_display_name'], $src['machine_model'],
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

/**
 * 超級管理員批次刪除（2026-08-18 使用者要求）：一次刪多筆表單，連同**內容列、簽核紀錄(approval_record)、
 * 相關通知(live_event/live_event_target)** 一併清乾淨——單筆刪除只清內容列，補登資料重做時若留著舊的簽核
 * 紀錄與通知，會在待辦/通知裡留下指向已不存在表單的殘骸。整批同一個 transaction，任一筆失敗全部復原。
 * 呼叫端須先驗超級管理員身分與操作確認密碼。
 */
function hrf_instance_delete_bulk(PDO $db, array $ids): array {
    $ids = array_values(array_unique(array_filter(array_map('intval', $ids))));
    if (!$ids) return ['deleted'=>0, 'labels'=>[], 'errors'=>['沒有指定要刪除的表單']];
    $in = implode(',', $ids);
    $rows = $db->query("SELECT id, form_type, user_cname, dept_name, position_name, machine_display_name, business_date, status
                        FROM hr_form_instance WHERE id IN ($in)")->fetchAll(PDO::FETCH_ASSOC);
    if (!$rows) return ['deleted'=>0, 'labels'=>[], 'errors'=>['找不到要刪除的表單（可能已被刪除）']];
    $labels = [];
    foreach ($rows as $r) {
        $labels[] = (HRF_FORM_TYPES[$r['form_type']] ?? $r['form_type']) . '｜'
                  . ($r['form_type'] === 'job_desc' ? ($r['dept_name'] . '/' . $r['position_name'])
                                                    : ($r['user_cname'] . ($r['machine_display_name'] ? '（' . $r['machine_display_name'] . '）' : '')))
                  . '｜' . $r['business_date'];
    }
    $foundIds = array_map(fn($r) => (int)$r['id'], $rows);
    $inFound = implode(',', $foundIds);
    $own = !$db->inTransaction();
    if ($own) $db->beginTransaction();
    try {
        // 通知：live_event_target 先清，再清 live_event（本模組的通知都帶 ref_type=HRF_*、ref_id=表單id）
        $evs = $db->query("SELECT id FROM live_event WHERE ref_type LIKE 'HRF%' AND ref_id IN ($inFound)")->fetchAll(PDO::FETCH_COLUMN);
        if ($evs) {
            $evIn = implode(',', array_map('intval', $evs));
            $db->exec("DELETE FROM live_event_target WHERE live_event_id IN ($evIn)");
            $db->exec("DELETE FROM live_event WHERE id IN ($evIn)");
        }
        $db->exec("DELETE FROM approval_record WHERE module='hr_form' AND entity_id IN ($inFound)");
        $db->exec("DELETE FROM hr_form_instance_item WHERE instance_id IN ($inFound)");
        $db->exec("DELETE FROM hr_form_instance WHERE id IN ($inFound)");
        if ($own) $db->commit();
    } catch (Throwable $e) {
        if ($own && $db->inTransaction()) $db->rollBack();
        return ['deleted'=>0, 'labels'=>[], 'errors'=>['刪除失敗：' . $e->getMessage()]];
    }
    $missing = array_values(array_diff($ids, $foundIds));
    return ['deleted'=>count($foundIds), 'labels'=>$labels,
            'errors'=>$missing ? ['下列表單已不存在，略過：#' . implode('、#', $missing)] : []];
}

/**
 * 01/10 內容列存檔。01(職務說明書)無簽核概念，隨時可編輯。10(員工職能鑑定表)使用者明確要求送簽後仍可修改：
 * 若目前狀態已不是 draft（代表已送出，可能正在確認中/核准中/已完成/已退回），存檔時自動視為內容有異動，
 * 「最新更新日期」改今天、狀態打回 draft、既有確認/核准紀錄清空，需要重新走一次送出流程（approval_record
 * 舊紀錄保留當歷史軌跡不刪，重新送出時 hrf_instance_submit() 會建立新的一筆，eg_approval_latest() 自然抓到新的）。
 */
function hrf_instance_save_items(PDO $db, int $instanceId, array $items): void {
    hrf_instance_items_save($db, $instanceId, $items);
    $st = $db->prepare("SELECT form_type, status FROM hr_form_instance WHERE id=?");
    $st->execute([$instanceId]);
    $row = $st->fetch(PDO::FETCH_ASSOC);
    if ($row && $row['form_type'] === 'competency' && $row['status'] !== 'draft') {
        $db->prepare("UPDATE hr_form_instance SET cp_update_date=CURDATE(), status='draft',
                       confirm_user_id=NULL, confirm_user_name=NULL, confirm_at=NULL,
                       approve_user_id=NULL, approve_user_name=NULL, approve_at=NULL, updated_at=NOW() WHERE id=?")
           ->execute([$instanceId]);
    } else {
        $db->prepare("UPDATE hr_form_instance SET updated_at=NOW() WHERE id=?")->execute([$instanceId]);
    }
}

/** 超級管理員手動調整員工職能鑑定表「最新更新日期」（業務日期，非精確時間戳，比照 ai-rules/21）。 */
function hrf_cp_set_update_date(PDO $db, int $instanceId, string $date): array {
    $inst = hrf_instance_get($db, $instanceId);
    if (!$inst || $inst['form_type'] !== 'competency') return ['ok'=>false, 'msg'=>'僅職能鑑定表可設定此欄位'];
    $db->prepare("UPDATE hr_form_instance SET cp_update_date=? WHERE id=?")->execute([$date, $instanceId]);
    return ['ok'=>true];
}

/** 01職務說明書「確認完成」：借用 confirm_user_id/confirm_user_name/confirm_at 三欄（01不走簽核流程，這三欄原本恆為NULL，不與09/10衝突）。 */
function hrf_job_desc_confirm(PDO $db, int $instanceId, int $uid, string $uname): array {
    $inst = hrf_instance_get($db, $instanceId);
    if (!$inst || $inst['form_type'] !== 'job_desc') return ['ok'=>false, 'msg'=>'找不到此表單'];
    $db->prepare("UPDATE hr_form_instance SET confirm_user_id=?, confirm_user_name=?, confirm_at=NOW() WHERE id=?")
       ->execute([$uid, $uname, $instanceId]);
    return ['ok'=>true];
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
    $pool = hrf_confirm_pool($db, $inst, true);
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
    // 補歷史表單時「當時的主管」與「現在的主管」可能不同人：兩邊都允許簽（歷史那位已離職時現任才簽得下去），
    // 實際記錄下來的簽章人就是真的按下確認的那一位。
    $poolIds = array_merge(array_column(hrf_confirm_pool($db, $inst, false), 'id'),
                           array_column(hrf_confirm_pool($db, $inst, true), 'id'));
    if (!in_array($uid, array_map('intval', $poolIds), true)) return ['ok'=>false, 'msg'=>'您不是此表單的確認人'];
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
 * skill_assess 適用，未帶入該筆 id 就不動分數，維持原值）；$itemsByInstance＝[instance_id => items[]]（僅
 * competency 適用，比照真正確認流程 hrf_confirm_decide() 允許順便調整操作/異常排除分數，未帶入該筆 id
 * 就不動內容）；簽核前先存分數/項目，NA(課長考核)欄位強制清空。
 */
function hrf_auto_sign_bulk(PDO $db, array $instanceIds, string $signDate, int $byUid, string $byName, array $scoresByInstance = [], array $itemsByInstance = []): array {
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
            if ($inst['form_type'] === 'competency' && isset($itemsByInstance[$iid])) {
                hrf_instance_items_save($db, $iid, $itemsByInstance[$iid]);
            }
            if ($inst['status'] === 'draft') {
                // 補簽核蓋的是「該表單業務日期當時」的主管章（該員後來調部門/主管換人都不影響），見 hrf_instance_supervisor_pool()
                // 這個人本身就是最高決策者時，確認章蓋最高決策者本人（不是操作補簽的那個人＝以前會印成「超級管理員」）
                $pool = hrf_confirm_pool($db, $inst, false);
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
