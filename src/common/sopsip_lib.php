<?php
/**
 * sopsip_lib.php — 作業標準書(SOP)／標準檢驗指導書(SIP) 唯一實作
 * 建立：2026-09-21
 *
 * 本模組一頁兩分頁：SOP（生產課為主）／SIP（品管課為主），資料結構共用。
 *
 * ── 三個版面（kind）＝照現行紙本，版面決定欄位與列印長相（使用者 2026-09-21 拍板）
 *   equip   設備操作說明書 3-TD-02-01：機台 SOP。機器編號＝machine_list.asset_no，綁 machine_id。
 *   process 製造製程說明書 3-TD-02-02：通用 SOP 與料號 SOP 都走這個版面，差別只在有沒有綁料號。
 *   sip     標準檢驗指導書 2-QA-02-01：通用 SIP 與料號 SIP。
 *
 * ── 適用範圍（scope）
 *   machine 綁機台（只有 equip 用）／general 通用（不綁）／part 綁特定料號
 *   **料號一律綁 d_setting.d_id（料號主檔 ID）不是料號文字**——同一個料號文字在 d_setting
 *   常常有好幾筆、分屬不同客戶（記憶 bom_client_name_cache 同一條）；part_no_text 只是顯示用快取。
 *   **機台一律綁 machine_list.machine_id**，畫面顯示用 asset_no（機器編號）與 field_no（現場編號）。
 *
 * ── 版次（使用者拍板：同一份文件保留版次歷程）
 *   ss_doc 是「一份文件」，ss_ver 是它的每一個版次。改版＝新增一個 ss_ver（內容由上一版帶過來），
 *   舊版查得到也印得出來，doc.cur_ver_id 指向現行版。紙本上的「修改記錄／修訂履歷」表不另外手打，
 *   由各版次的 ver_no＋form_date＋rev_note＋三個簽章組出來（鐵律4：同一份資訊只有一個來源）。
 *
 * ── 簽核（使用者拍板：三種表單統一成 製表→審核→核准 三關）
 *   關卡代碼與順序的唯一登記處＝ss_slots()。製造製程說明書紙本上的「初審」格因此不印。
 *   簽核人必須：①在**表單日期**當時在職（ai-rules/22 依業務日期回推當時職務與部門職稱）
 *              ②**簽章日期當天沒有請整天假**（半天假仍可簽）＝ss_person_day_ok()
 *   兩條都在前端擋一次、後端存檔時用同一支函式再擋一次（鐵律8）。
 */

require_once __DIR__ . '/people_lib.php';
require_once __DIR__ . '/position_history_lib.php';
require_once __DIR__ . '/asdoc_lib.php';

/** 版面（kind）＝紙本的三種表單。唯一登記處，新增版面只改這裡 */
function ss_kinds(): array
{
    return [
        'equip'   => ['label' => '設備操作說明書', 'tab' => 'sop', 'as_no' => '3-TD-02-01', 'module' => 'sop_equip'],
        'process' => ['label' => '製造製程說明書', 'tab' => 'sop', 'as_no' => '3-TD-02-02', 'module' => 'sop_process'],
        'sip'     => ['label' => '標準檢驗指導書', 'tab' => 'sip', 'as_no' => '2-QA-02-01', 'module' => 'sip'],
    ];
}

/** 適用範圍 */
function ss_scopes(): array
{
    return ['machine' => '機台', 'tool' => '量具／檢驗設備', 'general' => '通用', 'part' => '特定料號'];
}

/** 每個版面允許哪些適用範圍：機台 SOP 只能綁機台，其餘可通用或綁料號 */
function ss_kind_scopes(string $kind): array
{
    // 設備操作說明書除了機台，也要能綁「檢驗設備一覽表」裡的量具（使用者 2026-09-22 要求）
    return $kind === 'equip' ? ['machine', 'tool'] : ['general', 'part'];
}

/**
 * 簽核關卡（唯一登記處）。陣列順序就是必須依序完成的順序。
 * maker＝製表（填表人本人，送出當下即成立），review＝審核，approve＝核准。
 */
function ss_slots(): array
{
    return [
        'maker'   => ['label' => '製表', 'seq' => 1],
        'review'  => ['label' => '審核', 'seq' => 2],
        'approve' => ['label' => '核准', 'seq' => 3],
    ];
}

function ss_statuses(): array
{
    return ['draft' => '草稿', 'submitted' => '簽核中', 'approved' => '已核准', 'obsolete' => '已作廢'];
}

/* ════════════════════════════ schema ════════════════════════════ */

function ss_ensure_col(PDO $db, string $table, string $col, string $ddl): void
{
    try {
        $st = $db->prepare("SHOW COLUMNS FROM `$table` LIKE ?");
        $st->execute([$col]);
        if (!$st->fetchColumn()) $db->exec("ALTER TABLE `$table` ADD COLUMN `$col` $ddl");
    } catch (Throwable $e) { /* 表還沒建時交給 CREATE TABLE */ }
}

/**
 * 建表。**一定要先確認表不存在、且不在交易中才下 DDL**——MySQL 的 CREATE TABLE 會造成隱式
 * commit，包在交易裡會讓外層 commit() 爆 "There is no active transaction"，而資料其實已經寫進去，
 * 畫面卻顯示失敗（本專案已在 eg_org_save() 與資料稽核踩過兩次）。
 */
function ss_ensure_schema(PDO $db): void
{
    static $done = false;
    if ($done) return;
    $done = true;
    try {
        if ($db->inTransaction()) return;
        $have = [];
        foreach ($db->query("SHOW TABLES LIKE 'ss\\_%'")->fetchAll(PDO::FETCH_COLUMN) as $t) $have[$t] = 1;

        if (empty($have['ss_doc'])) $db->exec("CREATE TABLE ss_doc (
            doc_id INT AUTO_INCREMENT PRIMARY KEY,
            kind VARCHAR(10) NOT NULL COMMENT 'equip/process/sip 三種版面',
            scope VARCHAR(10) NOT NULL COMMENT 'machine/general/part',
            machine_id INT NULL COMMENT '綁 machine_list.machine_id',
            part_d_id INT NULL COMMENT '綁 d_setting.d_id 料號主檔ID，不是料號文字',
            part_no_text VARCHAR(120) NULL COMMENT '料號文字，顯示用快取',
            title VARCHAR(200) NOT NULL DEFAULT '' COMMENT '文件名稱',
            proc_name VARCHAR(120) NULL COMMENT '製程（工程名稱）',
            cur_ver_id INT NULL COMMENT '現行版次',
            is_deleted TINYINT NOT NULL DEFAULT 0,
            created_at DATETIME NULL, created_by INT NULL, created_by_name VARCHAR(60) NULL,
            modified_at DATETIME NULL, modified_by INT NULL,
            INDEX idx_kind (kind, scope), INDEX idx_part (part_d_id), INDEX idx_machine (machine_id)
        ) DEFAULT CHARSET=utf8mb4 COMMENT='SOP／SIP 文件主檔'");

        if (empty($have['ss_ver'])) $db->exec("CREATE TABLE ss_ver (
            ver_id INT AUTO_INCREMENT PRIMARY KEY,
            doc_id INT NOT NULL,
            ver_no VARCHAR(16) NOT NULL DEFAULT '01' COMMENT '版次，照紙本可為 01/02 或 A/D 或 0',
            form_date DATE NULL COMMENT '表單日期（製表日期）＝業務日期',
            rev_note VARCHAR(255) NULL COMMENT '制/修訂事項',
            status VARCHAR(12) NOT NULL DEFAULT 'draft',
            customer_id VARCHAR(20) NULL, customer_name VARCHAR(120) NULL,
            order_no VARCHAR(60) NULL COMMENT '製令單號（SIP 表頭）',
            qty VARCHAR(40) NULL COMMENT '數量（SIP 表頭）',
            m_maker VARCHAR(120) NULL COMMENT '機器製造商（equip）',
            m_name VARCHAR(120) NULL COMMENT '機器名稱（equip）',
            m_spec VARCHAR(200) NULL COMMENT '型式規格（equip）',
            m_range VARCHAR(200) NULL COMMENT '加工適用範圍（equip）',
            op_method TEXT NULL COMMENT '操作方法（equip）',
            cautions TEXT NULL COMMENT '使用注意事項（equip）',
            maintain TEXT NULL COMMENT '保養維修要點（equip）',
            use_equip VARCHAR(200) NULL COMMENT '使用設備（process）',
            est_hours VARCHAR(60) NULL COMMENT '預計工時（process）',
            notice TEXT NULL COMMENT '注意事項（sip）',
            draw_file_id INT NULL COMMENT '帶入的圖面 ss_file.file_id',
            as_doc_id INT NULL COMMENT '綁定的 AS 文件 id，空＝用版面預設',
            created_at DATETIME NULL, created_by INT NULL,
            modified_at DATETIME NULL, modified_by INT NULL,
            INDEX idx_doc (doc_id, ver_id), INDEX idx_status (status)
        ) DEFAULT CHARSET=utf8mb4 COMMENT='SOP／SIP 版次'");

        if (empty($have['ss_step'])) $db->exec("CREATE TABLE ss_step (
            step_id INT AUTO_INCREMENT PRIMARY KEY,
            ver_id INT NOT NULL, seq INT NOT NULL DEFAULT 1,
            step_name VARCHAR(120) NULL COMMENT '項次名稱',
            img_file_id INT NULL COMMENT '參考圖示 ss_file.file_id',
            step_text TEXT NULL COMMENT '操作步驟',
            note TEXT NULL COMMENT '說明',
            INDEX idx_ver (ver_id, seq)
        ) DEFAULT CHARSET=utf8mb4 COMMENT='製造製程說明書操作步驟'");

        if (empty($have['ss_item'])) $db->exec("CREATE TABLE ss_item (
            item_id INT AUTO_INCREMENT PRIMARY KEY,
            ver_id INT NOT NULL, seq INT NOT NULL DEFAULT 1,
            ctrl_point VARCHAR(160) NULL COMMENT '管理重點',
            q_char VARCHAR(200) NULL COMMENT '品質特性',
            up_limit VARCHAR(40) NULL COMMENT '上限',
            lo_limit VARCHAR(40) NULL COMMENT '下限',
            owner VARCHAR(40) NULL COMMENT '擔當者',
            method VARCHAR(120) NULL COMMENT '檢驗方法',
            tool_no VARCHAR(60) NULL COMMENT '檢具編號',
            freq VARCHAR(80) NULL COMMENT '檢驗頻率',
            note VARCHAR(255) NULL COMMENT '備註',
            INDEX idx_ver (ver_id, seq)
        ) DEFAULT CHARSET=utf8mb4 COMMENT='標準檢驗指導書檢驗項目'");

        if (empty($have['ss_sign'])) $db->exec("CREATE TABLE ss_sign (
            sign_id INT AUTO_INCREMENT PRIMARY KEY,
            ver_id INT NOT NULL,
            slot VARCHAR(12) NOT NULL COMMENT 'maker/review/approve',
            user_id INT NULL, user_name VARCHAR(60) NULL,
            dept_name VARCHAR(60) NULL COMMENT '簽核當時的部門',
            position_name VARCHAR(60) NULL COMMENT '簽核當時的職稱',
            sign_date DATE NULL COMMENT '印章日期＝業務日期',
            signed_at DATETIME NULL COMMENT '實際按下的時刻，與業務日期分開存',
            is_auto TINYINT NOT NULL DEFAULT 0 COMMENT '1＝自動簽核，畫面與列印不顯示此字樣',
            by_deputy INT NULL COMMENT '代理人代簽時記被代理人 id',
            note VARCHAR(255) NULL,
            UNIQUE KEY uk_slot (ver_id, slot)
        ) DEFAULT CHARSET=utf8mb4 COMMENT='SOP／SIP 三個簽章格'");

        if (empty($have['ss_file'])) $db->exec("CREATE TABLE ss_file (
            file_id INT AUTO_INCREMENT PRIMARY KEY,
            doc_id INT NOT NULL, ver_id INT NULL,
            usage_kind VARCHAR(10) NOT NULL DEFAULT 'other' COMMENT 'draw/step/scan/other',
            src VARCHAR(10) NOT NULL DEFAULT 'upload' COMMENT 'upload 或 part（料號附件帶入）',
            part_attach_id INT NULL COMMENT 'src=part 時的 part_attachments.id，只記關聯不複製檔',
            file_name VARCHAR(255) NULL COMMENT '實體檔名，只存檔名不存路徑（鐵律5）',
            orig_name VARCHAR(255) NULL,
            mime VARCHAR(80) NULL, file_size INT NULL,
            uploaded_at DATETIME NULL, uploaded_by INT NULL,
            INDEX idx_doc (doc_id), INDEX idx_ver (ver_id, usage_kind)
        ) DEFAULT CHARSET=utf8mb4 COMMENT='SOP／SIP 圖面、步驟圖與紙本掃描檔'");

        /* ── 2026-09-21（二次）使用者交辦那一批的追加結構 ── */

        // 一份設備操作說明書綁的是「機台型號」（同型號好幾台共用一份 SOP），底下掛一對多的機器編號
        if (empty($have['ss_doc_machine'])) $db->exec("CREATE TABLE ss_doc_machine (
            id INT AUTO_INCREMENT PRIMARY KEY,
            doc_id INT NOT NULL, machine_id INT NOT NULL,
            UNIQUE KEY uk_dm (doc_id, machine_id), INDEX idx_m (machine_id)
        ) DEFAULT CHARSET=utf8mb4 COMMENT='文件↔機器編號（同型號多台）'");

        // 檢驗項目的預設值：std＝全站標準項目（精度等級／外觀／包裝那幾列），proc＝某個製程專屬
        if (empty($have['ss_item_tpl'])) $db->exec("CREATE TABLE ss_item_tpl (
            tpl_id INT AUTO_INCREMENT PRIMARY KEY,
            tpl_kind VARCHAR(8) NOT NULL DEFAULT 'std' COMMENT 'std 標準項目／proc 製程專屬',
            process_no INT NULL COMMENT 'tpl_kind=proc 時的製程',
            seq INT NOT NULL DEFAULT 1,
            ctrl_point VARCHAR(160) NULL, q_char VARCHAR(200) NULL,
            up_limit VARCHAR(40) NULL, lo_limit VARCHAR(40) NULL,
            owner_dept_id INT NULL, owner VARCHAR(40) NULL,
            method VARCHAR(120) NULL, tool_type_id INT NULL, tool_no VARCHAR(60) NULL,
            freq VARCHAR(80) NULL, note VARCHAR(255) NULL,
            is_active TINYINT NOT NULL DEFAULT 1,
            modified_at DATETIME NULL, modified_by INT NULL,
            INDEX idx_k (tpl_kind, process_no, seq)
        ) DEFAULT CHARSET=utf8mb4 COMMENT='SIP 檢驗項目預設值'");

        // 逐製程：要不要自動代入、以及代入時要不要連標準項目一起帶
        if (empty($have['ss_proc_cfg'])) $db->exec("CREATE TABLE ss_proc_cfg (
            process_no INT NOT NULL PRIMARY KEY,
            auto_apply TINYINT NOT NULL DEFAULT 1 COMMENT '新文件綁到這個製程時自動代入專屬項目',
            with_std TINYINT NOT NULL DEFAULT 1 COMMENT '代入時一併帶標準項目',
            modified_at DATETIME NULL, modified_by INT NULL
        ) DEFAULT CHARSET=utf8mb4 COMMENT='製程的檢驗項目代入設定'");

        ss_ensure_col($db, 'ss_doc', 'tool_id', "INT NULL COMMENT '綁 qc_tool.Tool_id（檢驗設備一覽表的量具）'");
        ss_ensure_col($db, 'ss_ver', 'paper', "VARCHAR(4) NULL COMMENT '列印紙張 A4/A3，空＝用預設'");
        ss_ensure_col($db, 'ss_ver', 'orient', "VARCHAR(10) NULL COMMENT '列印方向 portrait/landscape，空＝用預設'");
        ss_ensure_col($db, 'ss_doc', 'process_no', "INT NULL COMMENT '綁定製程 process_no.ProcessNo（工程名稱就是它）'");
        ss_ensure_col($db, 'ss_doc', 'machine_model', "VARCHAR(100) NULL COMMENT '設備SOP綁的機台型號'");
        ss_ensure_col($db, 'ss_doc', 'customer_id', "VARCHAR(20) NULL COMMENT '客戶（綁料號時由料號主檔帶入）'");
        ss_ensure_col($db, 'ss_doc', 'customer_name', "VARCHAR(120) NULL");
        ss_ensure_col($db, 'ss_file', 'rot', "SMALLINT NOT NULL DEFAULT 0 COMMENT '顯示旋轉 0/90/180/270，只影響本文件不動原檔'");
        ss_ensure_col($db, 'ss_file', 'sec_key', "VARCHAR(20) NULL COMMENT 'usage_kind=sec 時屬於哪一個段落'");
        ss_ensure_col($db, 'ss_item', 'owner_dept_id', "INT NULL COMMENT '擔當者部門 id（owner 只是顯示文字）'");
        ss_ensure_col($db, 'ss_item', 'tool_type_id', "INT NULL COMMENT '檢具類型 qc_tool_list.QC_Tool_List_id'");
    } catch (Throwable $e) { /* 交給呼叫端失敗得明確一點 */ }
}

/* ════════════════════════ 設定（system_parameters） ════════════════════════ */

const SS_PARAM_GROUP = 'SOP_SIP';

function ss_setting_get(PDO $db, string $key, $default = null)
{
    try {
        $st = $db->prepare("SELECT param_value FROM system_parameters WHERE param_group=? AND param_key=? LIMIT 1");
        $st->execute([SS_PARAM_GROUP, $key]);
        $v = $st->fetchColumn();
        if ($v === false || $v === null || $v === '') return $default;
        $d = json_decode((string)$v, true);
        return $d === null ? $default : $d;
    } catch (Throwable $e) { return $default; }
}

function ss_setting_set(PDO $db, string $key, $val): void
{
    $st = $db->prepare("INSERT INTO system_parameters (param_group, param_key, param_value)
                        VALUES (?,?,?) ON DUPLICATE KEY UPDATE param_value=VALUES(param_value)");
    $st->execute([SS_PARAM_GROUP, $key, json_encode($val, JSON_UNESCAPED_UNICODE)]);
}

/**
 * 圖章模板：逐關卡可各自綁一個模板（ai-rules/18）。
 * 沒綁＝用預設回墨印；**有模板時前端一定要連 eg_stamp_tpl.js 一起載**，只載 eg_stamp.js 會靜默退回預設章。
 */
function ss_stamp_tpl(PDO $db, string $slot): ?array
{
    $id = (int)ss_setting_get($db, 'stamp_tpl_' . $slot, 0);
    if ($id <= 0) return null;
    try {
        $st = $db->prepare("SELECT id, tpl_name, schema_json FROM stamp_template WHERE id=? AND is_active=1");
        $st->execute([$id]);
        $r = $st->fetch(PDO::FETCH_ASSOC);
        return $r ?: null;
    } catch (Throwable $e) { return null; }
}

/** 自動簽核是否開啟（逐版面各自可設；ai-rules/21） */
function ss_auto_sign_on(PDO $db, string $kind): bool
{
    return (int)ss_setting_get($db, 'auto_sign_' . $kind, 0) === 1;
}

/** 各關卡的預設簽核人（逐版面各自可設，存 user_id；0＝未設定） */
function ss_default_signer(PDO $db, string $kind, string $slot): int
{
    return (int)ss_setting_get($db, 'signer_' . $kind . '_' . $slot, 0);
}

/* ════════════════════════════ 權限 ════════════════════════════ */

function ss_user_role_codes(PDO $db, int $uid): array
{
    static $cache = [];
    if (isset($cache[$uid])) return $cache[$uid];
    $codes = [];
    try {
        $st = $db->prepare("SELECT DISTINCT r.role_code FROM user_roles ur JOIN roles r ON r.role_id=ur.role_id WHERE ur.user_id=?");
        $st->execute([$uid]);
        $codes = array_map('strval', $st->fetchAll(PDO::FETCH_COLUMN) ?: []);
    } catch (Throwable $e) {}
    try {
        $st = $db->prepare("SELECT DISTINCT rf.feature_code FROM user_roles ur JOIN role_features rf ON rf.role_id=ur.role_id WHERE ur.user_id=?");
        $st->execute([$uid]);
        foreach ($st->fetchAll(PDO::FETCH_COLUMN) ?: [] as $f) $codes[] = (string)$f;
    } catch (Throwable $e) {}
    return $cache[$uid] = array_values(array_unique($codes));
}

function ss_user_dept_ids(PDO $db, int $uid): array
{
    static $cache = [];
    if (isset($cache[$uid])) return $cache[$uid];
    $ids = [];
    try {
        // 人與部門的對照表是 user_department_position_map（**不是** user_position，那張表不存在；
        // 查錯表名會被 try/catch 吞掉，變成「部門判定永遠 false」而且完全不報錯）
        $st = $db->prepare("SELECT DISTINCT department_id FROM user_department_position_map
                            WHERE user_id=? AND department_id IS NOT NULL");
        $st->execute([$uid]);
        $ids = array_map('intval', $st->fetchAll(PDO::FETCH_COLUMN) ?: []);
    } catch (Throwable $e) {}
    return $cache[$uid] = $ids;
}

/** 這個人是不是屬於某個全站組織角色綁定的部門（含子部門） */
function ss_in_org_dept(PDO $db, int $uid, string $key): bool
{
    require_once __DIR__ . '/org_role_lib.php';
    $ids = eg_org_dept_ids($db, $key);
    if (!$ids) return false;
    foreach (ss_user_dept_ids($db, $uid) as $d) if (in_array($d, $ids, true)) return true;
    return false;
}

/**
 * 權限。SOP（生產課）與 SIP（品管課）分開授權——兩個分頁是不同課室在用，
 * 生產課不該改得動檢驗指導書、品管課也不該改設備操作說明書。
 * 組織角色的部門綁定與 RBAC 角色並用：本來就在那個課的人不必再指派角色。
 */
function ss_perms(PDO $db, int $uid): array
{
    $none = ['uid' => 0, 'name' => '', 'isAdmin' => false, 'canAdmin' => false,
             'canViewSop' => false, 'canEditSop' => false, 'canSignSop' => false,
             'canViewSip' => false, 'canEditSip' => false, 'canSignSip' => false, 'canView' => false];
    if ($uid <= 0) return $none;
    $st = $db->prepare("SELECT id, user_cname, user_uname, state, user_status FROM `user` WHERE id=?");
    $st->execute([$uid]);
    $u = $st->fetch(PDO::FETCH_ASSOC);
    if (!$u) return $none;
    if ((int)$u['state'] === 0 || (int)$u['user_status'] === 90) return $none;   // 離職／特殊帳號 fail-closed

    $codes = ss_user_role_codes($db, $uid);
    $has = function (array $c) use ($codes) { return (bool)array_intersect($c, $codes); };
    $isAdmin  = $uid === 1 || $has(['admin', 'superadmin']);
    $canAdmin = $isAdmin || $has(['sopsip_admin']);

    $prodDept = ss_in_org_dept($db, $uid, 'prod_dept') || ss_in_org_dept($db, $uid, 'pm_dept');
    $qcDept   = ss_in_org_dept($db, $uid, 'qc_dept');

    // 技術課也算數：設備操作說明書與製造製程說明書是 3-TD-* 的文件，本來就由技術課制訂
    $rdDept   = ss_in_org_dept($db, $uid, 'rd_dept');
    $canEditSop = $canAdmin || $has(['sop_edit']) || $prodDept || $rdDept;
    $canSignSop = $canAdmin || $has(['sop_sign']) || $prodDept || $rdDept;
    $canViewSop = $canEditSop || $canSignSop || $has(['sop_view', 'sip_view', 'sopsip_view']) || $qcDept;

    $canEditSip = $canAdmin || $has(['sip_edit']) || $qcDept || $has(['qc_manage_settings']);
    $canSignSip = $canAdmin || $has(['sip_sign']) || $qcDept || $has(['qc_manage_settings']);
    $canViewSip = $canEditSip || $canSignSip || $has(['sip_view', 'sop_view', 'sopsip_view']) || $prodDept;

    return ['uid' => $uid, 'name' => (string)($u['user_cname'] ?: $u['user_uname']),
            'isAdmin' => $isAdmin, 'canAdmin' => $canAdmin,
            'canViewSop' => $canViewSop, 'canEditSop' => $canEditSop, 'canSignSop' => $canSignSop,
            'canViewSip' => $canViewSip, 'canEditSip' => $canEditSip, 'canSignSip' => $canSignSip,
            'canView' => $canViewSop || $canViewSip];
}

/** 這個版面屬於哪一個分頁的權限（equip/process→sop，sip→sip） */
function ss_perm_for_kind(array $P, string $kind, string $what): bool
{
    $tab = ss_kinds()[$kind]['tab'] ?? 'sop';
    $key = ($what === 'view' ? 'canView' : ($what === 'edit' ? 'canEdit' : 'canSign')) . ($tab === 'sip' ? 'Sip' : 'Sop');
    return !empty($P[$key]);
}

/* ══════════════════ 人員：當時在職 ＋ 當天沒請整天假 ══════════════════ */

/** 上班時段（判定「請整天假」用，管理員可改）。預設 08:00~17:00 */
function ss_work_window(PDO $db): array
{
    $s = (string)ss_setting_get($db, 'work_start', '08:00');
    $e = (string)ss_setting_get($db, 'work_end', '17:00');
    if (!preg_match('/^\d{2}:\d{2}$/', $s)) $s = '08:00';
    if (!preg_match('/^\d{2}:\d{2}$/', $e)) $e = '17:00';
    return [$s, $e];
}

/**
 * 一批人在某一天「有沒有請整天假」。
 *
 * 判定走全站共用的 person_schedule_lib，不自己查 leave_request（假別、時數、代理的規則都在那裡）。
 * **但它的 `allday` 只有跨日的假才會是 1**——單日的整天假（08:00~17:30）回來的是時間區間，
 * 只看 allday 會把整天請假的人判成可以簽章。所以這裡改判「這筆假有沒有涵蓋整個上班時段」，
 * 半天假不涵蓋、仍可簽（現場半天假那半天還是有來）。
 *
 * @return array user_id => 原因文字（沒請整天假的人不會出現在回傳裡）
 */
function ss_leave_allday_map(PDO $db, array $userIds, string $date): array
{
    $out = [];
    $ids = array_values(array_unique(array_filter(array_map('intval', $userIds))));
    if (!$ids || !preg_match('/^\d{4}-\d{2}-\d{2}$/', $date)) return $out;
    require_once __DIR__ . '/person_schedule_lib.php';
    [$ws, $we] = ss_work_window($db);
    try {
        $map = eg_psched_for_users($db, $ids, $date, $ws, $we);
    } catch (Throwable $e) { return $out; }
    foreach ($map as $uid => $items) {
        foreach ($items as $it) {
            if (($it['source'] ?? '') !== 'leave') continue;
            $txt = (string)($it['text'] ?? '請假');
            if (!empty($it['allday'])) { $out[(int)$uid] = $txt; break; }
            if (preg_match('/^(\d{2}:\d{2})~(\d{2}:\d{2})/', (string)($it['time'] ?? ''), $m)
                && $m[1] <= $ws && $m[2] >= $we) {
                $out[(int)$uid] = $txt;
                break;
            }
        }
    }
    return $out;
}

/**
 * 某個日期當天，這個人是不是「有上班」＝沒有請整天假（使用者要求）。
 * @return array [ok, why]  ok=false 時 why 講清楚是哪一天請了什麼假
 */
function ss_person_day_ok(PDO $db, int $uid, string $date): array
{
    if ($uid <= 0) return [false, '未指定人員'];
    if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $date)) return [true, ''];   // 沒有日期就不判定
    $m = ss_leave_allday_map($db, [$uid], $date);
    if (isset($m[$uid])) return [false, $date . ' 請整天假（' . $m[$uid] . '）'];
    return [true, ''];
}

/**
 * 某個表單日期當時「可以簽這一格的人」。
 * ①以表單日期回推當時在職者與當時的部門職稱（ai-rules/22 第5坑：用現況清單會讓補歷史文件時
 *   當時在職、現已離職的人一個都挑不到，而且完全不報錯）
 * ②再把「簽章日期當天請整天假」的標成不可選（仍列出來並寫明原因，不是安靜消失）
 *
 * @param string $formDate 表單日期（決定在職與職稱）
 * @param string $signDate 簽章日期（決定當天有沒有上班），空＝同表單日期
 */
function ss_signer_candidates(PDO $db, string $formDate, string $signDate = ''): array
{
    $formDate = preg_match('/^\d{4}-\d{2}-\d{2}$/', $formDate) ? $formDate : date('Y-m-d');
    $signDate = preg_match('/^\d{4}-\d{2}-\d{2}$/', $signDate) ? $signDate : $formDate;
    $rows = eg_people_posts_asof($db, [], $formDate);
    $ids  = [];
    foreach ($rows as $r) $ids[] = (int)$r['id'];
    $ids = array_values(array_unique(array_filter($ids)));

    $leave = ss_leave_allday_map($db, $ids, $signDate);

    $out = [];
    foreach ($rows as $r) {
        $uid = (int)$r['id'];
        $out[] = [
            'id'        => $uid,
            'post_key'  => (string)($r['post_key'] ?? ($uid . ':0')),
            'name'      => (string)($r['user_cname'] ?? $r['name'] ?? ''),
            'dept_id'   => (int)($r['dept_id'] ?? 0),
            'is_former' => (int)($r['is_former'] ?? 0),
            'dept'      => (string)($r['dept_name'] ?? ''),
            'position'  => (string)($r['position_name'] ?? ''),
            'blocked'   => isset($leave[$uid]) ? 1 : 0,
            'block_why' => $leave[$uid] ?? '',
        ];
    }
    return $out;
}

/**
 * 存檔前驗一次「這個人那一天能不能簽這一格」（鐵律8：前端擋過了後端仍要再擋）。
 * @return array [ok, why, snap]  snap＝當時的部門職稱，直接寫進 ss_sign 當圖章快照
 */
function ss_signer_check(PDO $db, int $uid, string $formDate, string $signDate, int $deptId = 0): array
{
    if ($uid <= 0) return [false, '未指定簽核人員', []];
    $hit = null; $any = null;
    foreach (ss_signer_candidates($db, $formDate, $signDate) as $c) {
        if ($c['id'] !== $uid) continue;
        $any = $any ?: $c;
        // 兼任者有好幾列（一個職務一列），呼叫端有指定是哪一個部門就取那一列，
        // 否則取第一列——**絕不可回推時自己挑職級最高的那個兼任**（ai-rules/22 第3坑）
        if ($deptId > 0 && (int)($c['dept_id'] ?? 0) === $deptId) { $hit = $c; break; }
    }
    $c = $hit ?: $any;
    if (!$c) return [false, '該人員在表單日期 ' . $formDate . ' 當時不在職，無法列為簽核人', []];
    if ($c['blocked']) return [false, $c['name'] . ' 在 ' . $signDate . ' ' . $c['block_why'] . '，不可簽章', []];
    return [true, '', ['dept_name' => $c['dept'], 'position_name' => $c['position'], 'user_cname' => $c['name']]];
}

/* ════════════════════════════ 讀取 ════════════════════════════ */

function ss_doc_get(PDO $db, int $docId): ?array
{
    $st = $db->prepare("SELECT * FROM ss_doc WHERE doc_id=? AND is_deleted=0");
    $st->execute([$docId]);
    $d = $st->fetch(PDO::FETCH_ASSOC);
    return $d ?: null;
}

function ss_ver_get(PDO $db, int $verId): ?array
{
    $st = $db->prepare("SELECT * FROM ss_ver WHERE ver_id=?");
    $st->execute([$verId]);
    $v = $st->fetch(PDO::FETCH_ASSOC);
    return $v ?: null;
}

/**
 * 修改記錄／修訂履歷的「說明」怎麼印（唯一實作，畫面與列印共用同一份說法）。
 * 使用者 2026-09-22 指定：**第一筆（最舊的那一版）固定是「制訂」，其餘一律「修訂」＋說明內容**，
 * 而且**不可以印出「紙本匯入」**——那是匯入程式自己寫的備註，不是修訂事項
 * （實測全庫 53 筆 rev_note 全是系統寫的「紙本匯入／紙本掃描匯入／初訂」，沒有一筆是人填的）。
 */
function ss_rev_text(bool $isFirst, string $note): string
{
    $note = trim($note);
    // 系統自己填的字樣一律不印；使用者自己打的內容才留下來
    if (preg_match('/^(紙本匯入|紙本掃描匯入|匯入|初訂|制訂|制定|修訂)$/u', $note)) $note = '';
    if ($isFirst) return '制訂';
    // 使用者自己已經寫了「修訂…」就不要再前綴一次
    if ($note !== '' && preg_match('/^修訂/u', $note)) return $note;
    return $note !== '' ? '修訂　' . $note : '修訂';
}

/**
 * 一份文件的全部版次，新→舊（修訂履歷表就是它反過來印）。
 * 每一列附上 rev_text＝該列在修改記錄上要印的說明，畫面與列印一律讀它，不要各自再組一次。
 */
function ss_ver_rows(PDO $db, int $docId): array
{
    $st = $db->prepare("SELECT * FROM ss_ver WHERE doc_id=? ORDER BY ver_id DESC");
    $st->execute([$docId]);
    $rows = $st->fetchAll(PDO::FETCH_ASSOC) ?: [];
    // 新→舊，所以最後一列才是最舊的那一版＝修改記錄上印在第一行的那一筆
    $last = count($rows) - 1;
    foreach ($rows as $i => $r) $rows[$i]['rev_text'] = ss_rev_text($i === $last, (string)($r['rev_note'] ?? ''));
    return $rows;
}

function ss_sign_map(PDO $db, int $verId): array
{
    $st = $db->prepare("SELECT * FROM ss_sign WHERE ver_id=?");
    $st->execute([$verId]);
    $out = [];
    foreach ($st->fetchAll(PDO::FETCH_ASSOC) ?: [] as $r) $out[(string)$r['slot']] = $r;
    return $out;
}

function ss_step_rows(PDO $db, int $verId): array
{
    $st = $db->prepare("SELECT * FROM ss_step WHERE ver_id=? ORDER BY seq, step_id");
    $st->execute([$verId]);
    return $st->fetchAll(PDO::FETCH_ASSOC) ?: [];
}

function ss_item_rows(PDO $db, int $verId): array
{
    $st = $db->prepare("SELECT * FROM ss_item WHERE ver_id=? ORDER BY seq, item_id");
    $st->execute([$verId]);
    return $st->fetchAll(PDO::FETCH_ASSOC) ?: [];
}

function ss_file_rows(PDO $db, int $docId, int $verId = 0): array
{
    if ($verId > 0) {
        $st = $db->prepare("SELECT * FROM ss_file WHERE doc_id=? AND (ver_id IS NULL OR ver_id=?) ORDER BY file_id");
        $st->execute([$docId, $verId]);
    } else {
        $st = $db->prepare("SELECT * FROM ss_file WHERE doc_id=? ORDER BY file_id");
        $st->execute([$docId]);
    }
    return $st->fetchAll(PDO::FETCH_ASSOC) ?: [];
}

/** 一個版次的完整內容（表頭＋明細＋簽章＋檔案），畫面與列印共用同一份 */
function ss_ver_full(PDO $db, int $verId): ?array
{
    $v = ss_ver_get($db, $verId);
    if (!$v) return null;
    $d = ss_doc_get($db, (int)$v['doc_id']);
    if (!$d) return null;
    $kind  = (string)$d['kind'];
    $docId = (int)$d['doc_id'];
    $items = $kind === 'sip' ? ss_item_rows($db, $verId) : [];
    // 擔當者顯示文字一律由部門 id 重算（管理員改了別名，既有文件跟著改，不會留著舊字）
    foreach ($items as &$it) {
        $it['owner_label'] = ss_owner_label($db, (int)($it['owner_dept_id'] ?? 0), (string)($it['owner'] ?? ''));
    }
    unset($it);
    $meta = $kind === 'equip' ? ss_equip_meta($db, $d) : null;
    return [
        'doc'   => $d,
        'ver'   => $v,
        'kind'  => $kind,
        'steps' => $kind === 'sip' ? [] : ss_step_rows($db, $verId),
        'items' => $items,
        'signs' => ss_sign_map($db, $verId),
        'files' => ss_file_rows($db, $docId, $verId),
        'machine'  => ss_machine_row($db, (int)($d['machine_id'] ?? 0)),
        'machines' => $kind === 'equip' ? ss_doc_machines($db, $docId) : [],
        'machine_missing' => $kind === 'equip' ? ss_doc_machines_missing($db, $d) : [],
        'machine_meta'    => $meta,
        'sections'      => ss_sections($kind),
        'section_files' => ss_section_files($db, $docId, $verId),
    ];
}

/** 機台資料（機器編號一律取 asset_no，現場編號 field_no 只是輔助顯示） */
function ss_machine_row(PDO $db, int $machineId): ?array
{
    if ($machineId <= 0) return null;
    try {
        $st = $db->prepare("SELECT machine_id, machine, field_no, asset_no, machine_model, manufacturer, spec
                            FROM machine_list WHERE machine_id=?");
        $st->execute([$machineId]);
        $r = $st->fetch(PDO::FETCH_ASSOC);
        return $r ?: null;
    } catch (Throwable $e) { return null; }
}

/* ════════════════════════════ 版次 ════════════════════════════ */

/**
 * 下一個版次號。紙本上版次寫法不統一（01/02、A/D、0），所以規則是「看得懂就遞增，看不懂就照抄加1」：
 * 純數字 → 補零遞增（01→02）；單一英文字母 → A→B；其餘 → 後面接 -2、-3。
 */
function ss_next_ver_no(string $cur): string
{
    $cur = trim($cur);
    if ($cur === '') return '01';
    if (preg_match('/^\d+$/', $cur)) {
        $n = (int)$cur + 1;
        return str_pad((string)$n, max(2, strlen($cur)), '0', STR_PAD_LEFT);
    }
    if (preg_match('/^[A-Za-z]$/', $cur)) {
        return strtoupper($cur) === 'Z' ? 'AA' : chr(ord(strtoupper($cur)) + 1);
    }
    if (preg_match('/^(.*)-(\d+)$/', $cur, $m)) return $m[1] . '-' . ((int)$m[2] + 1);
    return $cur . '-2';
}

/** 現行版次＝最新一個已核准的版次；一份都還沒核准時退回最新的那一版（草稿也看得到） */
function ss_current_ver(PDO $db, int $docId): ?array
{
    $st = $db->prepare("SELECT * FROM ss_ver WHERE doc_id=? AND status='approved' ORDER BY ver_id DESC LIMIT 1");
    $st->execute([$docId]);
    $v = $st->fetch(PDO::FETCH_ASSOC);
    if ($v) return $v;
    $st = $db->prepare("SELECT * FROM ss_ver WHERE doc_id=? ORDER BY ver_id DESC LIMIT 1");
    $st->execute([$docId]);
    return $st->fetch(PDO::FETCH_ASSOC) ?: null;
}

/** 重算 doc.cur_ver_id（任何會改變版次狀態的動作之後都要呼叫，唯一實作） */
function ss_refresh_cur_ver(PDO $db, int $docId): void
{
    $v = ss_current_ver($db, $docId);
    $st = $db->prepare("UPDATE ss_doc SET cur_ver_id=? WHERE doc_id=?");
    $st->execute([$v ? (int)$v['ver_id'] : null, $docId]);
}

/* ════════════════════════════ 簽核 ════════════════════════════ */

/**
 * 自動簽核的時間戳（ai-rules/21）：業務日期＝sign_date，實際時刻刻意錯開 5~30 分、**不跨日**，
 * 所以不會印出「核准早於製表」。$prev＝前一格的時刻（Y-m-d H:i:s），空＝從 08:30 起算。
 */
function ss_auto_time(string $signDate, string $prev = ''): string
{
    $base = ($prev !== '' && strpos($prev, $signDate) === 0) ? strtotime($prev) : strtotime($signDate . ' 08:30:00');
    $t    = $base + random_int(5, 30) * 60;
    $end  = strtotime($signDate . ' 23:59:00');
    if ($t > $end) $t = $end;
    return date('Y-m-d H:i:s', $t);
}

/**
 * 寫一格簽章（唯一寫入點）。人員與日期一律先過 ss_signer_check()，前端擋過了這裡仍要再擋（鐵律8）。
 * @throws RuntimeException 人員當時不在職、或當天請整天假
 */
function ss_sign_set(PDO $db, int $verId, string $slot, int $uid, string $signDate, bool $isAuto = false,
                     string $note = '', int $byDeputy = 0, int $deptId = 0): void
{
    if (!isset(ss_slots()[$slot])) throw new RuntimeException('簽核關卡代碼不正確');
    $v = ss_ver_get($db, $verId);
    if (!$v) throw new RuntimeException('找不到這個版次');
    $formDate = (string)($v['form_date'] ?: date('Y-m-d'));
    $signDate = preg_match('/^\d{4}-\d{2}-\d{2}$/', $signDate) ? $signDate : $formDate;
    if ($signDate < $formDate) throw new RuntimeException('簽章日期不可早於表單日期 ' . $formDate);
    if ($signDate > date('Y-m-d')) throw new RuntimeException('簽章日期不可以是未來');

    [$ok, $why, $snap] = ss_signer_check($db, $uid, $formDate, $signDate, $deptId);
    if (!$ok) throw new RuntimeException($why);

    // 同一天多格時接在前一格之後，避免印出「核准早於製表」
    $prev = '';
    foreach (ss_slots() as $k => $def) {
        if ($k === $slot) break;
        $s = ss_sign_map($db, $verId)[$k] ?? null;
        if ($s && !empty($s['signed_at'])) $prev = (string)$s['signed_at'];
    }
    $signedAt = $isAuto ? ss_auto_time($signDate, $prev) : date('Y-m-d H:i:s');

    $st = $db->prepare("INSERT INTO ss_sign (ver_id, slot, user_id, user_name, dept_name, position_name,
                            sign_date, signed_at, is_auto, by_deputy, note)
                        VALUES (?,?,?,?,?,?,?,?,?,?,?)
                        ON DUPLICATE KEY UPDATE user_id=VALUES(user_id), user_name=VALUES(user_name),
                            dept_name=VALUES(dept_name), position_name=VALUES(position_name),
                            sign_date=VALUES(sign_date), signed_at=VALUES(signed_at),
                            is_auto=VALUES(is_auto), by_deputy=VALUES(by_deputy), note=VALUES(note)");
    $st->execute([$verId, $slot, $uid, (string)($snap['user_cname'] ?? ''), (string)($snap['dept_name'] ?? ''),
                  (string)($snap['position_name'] ?? ''), $signDate, $signedAt, $isAuto ? 1 : 0,
                  $byDeputy > 0 ? $byDeputy : null, $note !== '' ? $note : null]);
}

/** 三格都簽完了嗎 */
function ss_sign_complete(PDO $db, int $verId): bool
{
    $map = ss_sign_map($db, $verId);
    foreach (array_keys(ss_slots()) as $k) if (empty($map[$k]['user_id'])) return false;
    return true;
}

/** 下一個還沒簽的關卡代碼；全簽完回空字串 */
function ss_next_slot(PDO $db, int $verId): string
{
    $map = ss_sign_map($db, $verId);
    foreach (array_keys(ss_slots()) as $k) if (empty($map[$k]['user_id'])) return $k;
    return '';
}

/** 簽完三格就把版次改成已核准，並重算現行版 */
function ss_maybe_approve(PDO $db, int $verId): void
{
    if (!ss_sign_complete($db, $verId)) return;
    $st = $db->prepare("UPDATE ss_ver SET status='approved' WHERE ver_id=? AND status<>'obsolete'");
    $st->execute([$verId]);
    $v = ss_ver_get($db, $verId);
    if ($v) ss_refresh_cur_ver($db, (int)$v['doc_id']);
}

/* ════════════════════════════ 寫入 ════════════════════════════ */

/** 版次表頭可寫入的欄位（唯一登記處；不在這份清單上的欄位一律不給前端寫） */
function ss_ver_fields(): array
{
    /* 2026-09-21（二次）：使用者指定「不需要設定製令單號與數量」，所以 order_no／qty 不再開放寫入
       （欄位留著不刪，既有匯入資料還在，只是畫面與列印都不再出現）。
       客戶改由 ss_doc 決定（綁料號時自動帶、通用型才可自己挑），所以也不在這裡寫。
       機器那四欄**維持可寫**：實測匯入的紙本比 machine_list 完整（製造商 KAPP NILES／LUREN
       在主檔多半是空的），強制改成唯讀會把紙本上的資料洗掉；改成「建立時自動代入、之後仍可改」。 */
    return ['ver_no', 'form_date', 'rev_note', 'paper', 'orient',
            'm_maker', 'm_name', 'm_spec', 'm_range', 'op_method', 'cautions', 'maintain',
            'use_equip', 'est_hours', 'notice', 'draw_file_id', 'as_doc_id'];
}

/**
 * 建立／更新文件主檔。
 * 料號一律存 d_setting.d_id，part_no_text 由主檔即時回查（不採信前端送的料號文字）；
 * 機台一律存 machine_list.machine_id。綁定對象不存在就擋下（鐵律8）。
 */
function ss_doc_save(PDO $db, array $in, int $uid, string $uname): int
{
    $docId = (int)($in['doc_id'] ?? 0);
    $kind  = (string)($in['kind'] ?? '');
    if (!isset(ss_kinds()[$kind])) throw new RuntimeException('表單版面代碼不正確');
    $scope = (string)($in['scope'] ?? '');
    if (!in_array($scope, ss_kind_scopes($kind), true)) {
        throw new RuntimeException(ss_kinds()[$kind]['label'] . ' 不適用「' . (ss_scopes()[$scope] ?? $scope) . '」這個適用範圍');
    }

    $machineId = 0; $partDId = 0; $partNo = null; $model = null; $toolId = 0;
    $machineIds = [];
    if ($scope === 'tool') {
        $toolId = (int)($in['tool_id'] ?? 0);
        if (!ss_tool_row($db, $toolId)) throw new RuntimeException('請選擇量具（在檢驗設備一覽表裡找不到這一支）');
    } elseif ($scope === 'machine') {
        // 綁的是**型號**（同型號好幾台共用一份 SOP），機器編號是底下的一對多明細
        $model = trim((string)($in['machine_model'] ?? ''));
        $machineIds = $in['machine_ids'] ?? [];
        if (is_string($machineIds)) { $d = json_decode($machineIds, true); $machineIds = is_array($d) ? $d : []; }
        $machineIds = array_values(array_unique(array_filter(array_map('intval', (array)$machineIds))));
        if ($model === '' && $machineIds) {
            $one = ss_machine_row($db, (int)$machineIds[0]);
            $model = (string)($one['machine_model'] ?? '');
        }
        if ($model === '' && !$machineIds) throw new RuntimeException('請選擇機台型號');
        if ($model !== '' && !ss_machines_by_model($db, $model)) throw new RuntimeException('找不到這個機台型號（可能已全部停用）');
        // 主檔上的 machine_id 降為「代表機台」快取，方便既有查詢沿用；真正的清單在 ss_doc_machine
        $machineId = $machineIds ? (int)$machineIds[0] : 0;
    } elseif ($scope === 'part') {
        $partDId = (int)($in['part_d_id'] ?? 0);
        $st = $db->prepare("SELECT d_id, D_Setting_Id FROM d_setting WHERE d_id=?");
        $st->execute([$partDId]);
        $p = $st->fetch(PDO::FETCH_ASSOC);
        if (!$p) throw new RuntimeException('請選擇料號（找不到這筆料號主檔）');
        $partNo = (string)$p['D_Setting_Id'];
    }

    // 製程＝紙本上的「工程名稱」（同一件事，只留一欄）。存編號，名稱只是顯示用快取
    $procNo = (int)($in['process_no'] ?? 0);
    $procNm = null;
    if ($procNo > 0) {
        $pr = ss_proc_row($db, $procNo);
        if (!$pr) throw new RuntimeException('請從清單挑製程（找不到這個製程編號）');
        $procNm = (string)$pr['process_name'];
    }

    // 客戶：綁料號就由料號主檔決定（使用者要求不給手打）；通用型才採用送進來的值
    $cusId = null; $cusNm = null;
    if ($scope === 'part') {
        $c = ss_customer_of_part($db, $partDId);
        $cusId = $c['id'] !== '' ? $c['id'] : null;
        $cusNm = $c['name'] !== '' ? $c['name'] : null;
    } else {
        $cid = trim((string)($in['customer_id'] ?? ''));
        if ($cid !== '') {
            $st = $db->prepare("SELECT customer_id, customer FROM customer_list WHERE customer_id=?");
            $st->execute([$cid]);
            $c = $st->fetch(PDO::FETCH_ASSOC);
            if (!$c) throw new RuntimeException('請從清單挑客戶（找不到這個客戶編號）');
            $cusId = (string)$c['customer_id']; $cusNm = (string)$c['customer'];
        }
    }

    // 文件名稱自動產生，但使用者自己打過就以他打的為準
    $title = trim((string)($in['title'] ?? ''));
    if ($title === '') {
        $title = ss_auto_title($db, $kind, $scope, [
            'process_no' => $procNo, 'machine_model' => $model, 'machine_id' => $machineId,
            'part_d_id' => $partDId, 'tool_id' => $toolId,
        ]);
    }
    if ($title === '') throw new RuntimeException('請填寫文件名稱');

    // 「同一個料號／機台不可以有兩份」（使用者要求）：版面＋綁定對象＋製程。
    // **只有新建、或綁定真的被改過時才檢查**——匯入的資料裡本來就有重複（EG-002／EG-027 各兩份），
    // 每次存檔都擋的話那幾份會變成連改都改不動，使用者只會覺得系統壞了。
    $keyChanged = true;
    if ($docId > 0) {
        $old = ss_doc_get($db, $docId);
        $keyChanged = !$old || (string)($old['scope'] ?? '') !== $scope
            || trim((string)($old['machine_model'] ?? '')) !== (string)$model
            || (int)($old['part_d_id'] ?? 0) !== $partDId
            || (int)($old['tool_id'] ?? 0) !== $toolId
            || (int)($old['process_no'] ?? 0) !== $procNo;
    }
    $dup = $keyChanged ? ss_dup_find($db, $kind, $scope, [
        'machine_model' => $model, 'machine_id' => $machineId, 'part_d_id' => $partDId,
        'tool_id' => $toolId, 'process_no' => $procNo,
    ], $docId) : [];
    if ($dup && empty($in['_dup_ok'])) {
        $d0 = $dup[0];
        throw new RuntimeException('已經有一份同樣的文件了（' . (string)$d0['title'] . '，版次 '
            . (string)$d0['ver_no'] . '、' . (string)$d0['status_label'] . '）。'
            . '同一個對象＋同一個製程只能有一份，請直接更新那一份（doc:' . (int)$d0['doc_id'] . '）');
    }

    if ($docId > 0) {
        if (!ss_doc_get($db, $docId)) throw new RuntimeException('找不到這份文件');
        $st = $db->prepare("UPDATE ss_doc SET scope=?, machine_id=?, machine_model=?, tool_id=?, part_d_id=?, part_no_text=?,
                                title=?, process_no=?, proc_name=?, customer_id=?, customer_name=?,
                                modified_at=NOW(), modified_by=? WHERE doc_id=?");
        $st->execute([$scope, $machineId ?: null, $model ?: null, $toolId ?: null, $partDId ?: null, $partNo, $title,
                      $procNo ?: null, $procNm, $cusId, $cusNm, $uid, $docId]);
        if ($scope === 'machine' && array_key_exists('machine_ids', $in)) ss_doc_machines_set($db, $docId, $machineIds);
        return $docId;
    }
    $st = $db->prepare("INSERT INTO ss_doc (kind, scope, machine_id, machine_model, tool_id, part_d_id, part_no_text, title,
                            process_no, proc_name, customer_id, customer_name, created_at, created_by, created_by_name)
                        VALUES (?,?,?,?,?,?,?,?,?,?,?,?,NOW(),?,?)");
    $st->execute([$kind, $scope, $machineId ?: null, $model ?: null, $toolId ?: null, $partDId ?: null, $partNo, $title,
                  $procNo ?: null, $procNm, $cusId, $cusNm, $uid, $uname]);
    $newId = (int)$db->lastInsertId();
    if ($scope === 'machine') ss_doc_machines_set($db, $newId, $machineIds);
    return $newId;
}

/**
 * 建立一個版次。$in 沒送的欄位一律不動（array_key_exists 判「有沒有送這個欄位」，
 * 送空字串才是清空——本專案已踩過好幾次「沒送的欄位被一起寫成 NULL」）。
 */
function ss_ver_save(PDO $db, int $verId, array $in, int $uid): void
{
    $v = ss_ver_get($db, $verId);
    if (!$v) throw new RuntimeException('找不到這個版次');
    if ((string)$v['status'] === 'approved') throw new RuntimeException('已核准的版次不可修改，請建立新版次');
    if ((string)$v['status'] === 'obsolete') throw new RuntimeException('已作廢的版次不可修改');

    $set = []; $par = [];
    foreach (ss_ver_fields() as $f) {
        if (!array_key_exists($f, $in)) continue;
        $val = $in[$f];
        if ($f === 'form_date') {
            $val = trim((string)$val);
            if ($val !== '' && !preg_match('/^\d{4}-\d{2}-\d{2}$/', $val)) throw new RuntimeException('表單日期格式不正確');
            if ($val !== '' && $val > date('Y-m-d')) throw new RuntimeException('表單日期不可以是未來');
            $val = $val !== '' ? $val : null;
        } elseif ($f === 'draw_file_id' || $f === 'as_doc_id') {
            $val = (int)$val ?: null;
        } else {
            $val = is_string($val) ? trim($val) : $val;
            if ($val === '') $val = null;
        }
        $set[] = "`$f`=?"; $par[] = $val;
    }
    if (!$set) return;
    $set[] = 'modified_at=NOW()'; $set[] = 'modified_by=?'; $par[] = $uid;
    $par[] = $verId;
    $db->prepare("UPDATE ss_ver SET " . implode(',', $set) . " WHERE ver_id=?")->execute($par);
}

/** 建立第一個版次（新文件用） */
function ss_ver_create(PDO $db, int $docId, array $in, int $uid): int
{
    $formDate = trim((string)($in['form_date'] ?? date('Y-m-d')));
    if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $formDate)) $formDate = date('Y-m-d');
    $verNo = trim((string)($in['ver_no'] ?? '')) ?: '01';
    $st = $db->prepare("INSERT INTO ss_ver (doc_id, ver_no, form_date, rev_note, status, created_at, created_by)
                        VALUES (?,?,?,?, 'draft', NOW(), ?)");
    $st->execute([$docId, $verNo, $formDate, trim((string)($in['rev_note'] ?? '初訂')) ?: null, $uid]);
    $verId = (int)$db->lastInsertId();

    $doc = ss_doc_get($db, $docId);
    // 設備操作說明書：機台那四欄建立當下就由機台主檔帶進來（使用者要求），之後仍可自己改
    if ($doc && (string)$doc['kind'] === 'equip') {
        $meta = ss_equip_meta($db, $doc);
        foreach (['m_maker', 'm_name', 'm_spec', 'm_range'] as $k) {
            if (!array_key_exists($k, $in) && ($meta[$k] ?? '') !== '') $in[$k] = $meta[$k];
        }
    }
    ss_ver_save($db, $verId, $in, $uid);

    // 標準檢驗指導書：綁到的製程有設「自動代入」時，檢驗項目直接帶一份進來（代入後仍可逐列刪）
    if ($doc && (string)$doc['kind'] === 'sip' && empty($in['_no_default_items'])) {
        $pno = (int)($doc['process_no'] ?? 0);
        $cfg = ss_proc_cfg($db, $pno);
        $want = array_key_exists('apply_default', $in) ? (int)$in['apply_default'] : (int)$cfg['auto_apply'];
        if ($want === 1) {
            $rows = ss_default_items($db, $pno, null);
            if ($rows) ss_items_replace($db, $verId, $rows);
        }
    }
    ss_refresh_cur_ver($db, $docId);
    return $verId;
}

/**
 * 改版：以某一版為底複製出新版次（內容全部帶過來，明細也複製），狀態回到草稿。
 * 舊版不動——修訂履歷就是靠舊版一列一列留著才印得出來。
 */
function ss_ver_clone(PDO $db, int $fromVerId, array $in, int $uid): int
{
    $src = ss_ver_get($db, $fromVerId);
    if (!$src) throw new RuntimeException('找不到要改版的版次');
    $docId  = (int)$src['doc_id'];
    $verNo  = trim((string)($in['ver_no'] ?? '')) ?: ss_next_ver_no((string)$src['ver_no']);
    $formDate = trim((string)($in['form_date'] ?? date('Y-m-d')));
    if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $formDate)) $formDate = date('Y-m-d');
    $revNote = trim((string)($in['rev_note'] ?? ''));

    $cols = ss_ver_fields();
    $copy = array_values(array_diff($cols, ['ver_no', 'form_date', 'rev_note']));
    $names = implode(',', array_map(fn($c) => "`$c`", $copy));
    $sel   = [];
    foreach ($copy as $c) $sel[] = $src[$c] ?? null;

    $st = $db->prepare("INSERT INTO ss_ver (doc_id, ver_no, form_date, rev_note, status, created_at, created_by, $names)
                        VALUES (?,?,?,?, 'draft', NOW(), ?" . str_repeat(',?', count($copy)) . ")");
    $st->execute(array_merge([$docId, $verNo, $formDate, $revNote ?: null, $uid], $sel));
    $newId = (int)$db->lastInsertId();

    foreach (ss_step_rows($db, $fromVerId) as $s) {
        $db->prepare("INSERT INTO ss_step (ver_id, seq, step_name, img_file_id, step_text, note) VALUES (?,?,?,?,?,?)")
           ->execute([$newId, (int)$s['seq'], $s['step_name'], $s['img_file_id'], $s['step_text'], $s['note']]);
    }
    foreach (ss_item_rows($db, $fromVerId) as $i) {
        $db->prepare("INSERT INTO ss_item (ver_id, seq, ctrl_point, q_char, up_limit, lo_limit, owner, owner_dept_id,
                          method, tool_type_id, tool_no, freq, note)
                      VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?)")
           ->execute([$newId, (int)$i['seq'], $i['ctrl_point'], $i['q_char'], $i['up_limit'], $i['lo_limit'],
                      $i['owner'], $i['owner_dept_id'] ?? null, $i['method'], $i['tool_type_id'] ?? null,
                      $i['tool_no'], $i['freq'], $i['note']]);
    }
    // 段落附件與圖面：新版次要看得到同一批圖（ver_id 指到新版，舊版原本掛的那幾列不動）
    foreach (ss_file_rows($db, $docId, $fromVerId) as $fl) {
        if (!in_array((string)$fl['usage_kind'], ['sec', 'draw'], true)) continue;
        if ((int)($fl['ver_id'] ?? 0) !== $fromVerId) continue;
        $db->prepare("INSERT INTO ss_file (doc_id, ver_id, usage_kind, sec_key, src, part_attach_id, file_name,
                          orig_name, mime, file_size, rot, uploaded_at, uploaded_by)
                      VALUES (?,?,?,?,?,?,?,?,?,?,?,NOW(),?)")
           ->execute([$docId, $newId, $fl['usage_kind'], $fl['sec_key'] ?? null, $fl['src'], $fl['part_attach_id'],
                      $fl['file_name'], $fl['orig_name'], $fl['mime'], $fl['file_size'], (int)($fl['rot'] ?? 0), $uid]);
        if ((int)($src['draw_file_id'] ?? 0) === (int)$fl['file_id']) {
            $db->prepare("UPDATE ss_ver SET draw_file_id=? WHERE ver_id=?")->execute([(int)$db->lastInsertId(), $newId]);
        }
    }
    ss_refresh_cur_ver($db, $docId);
    return $newId;
}

/** 覆寫某一版的步驟明細（整批取代；呼叫端已在交易中） */
function ss_steps_replace(PDO $db, int $verId, array $rows): void
{
    $db->prepare("DELETE FROM ss_step WHERE ver_id=?")->execute([$verId]);
    $seq = 0;
    foreach ($rows as $r) {
        $txt = trim((string)($r['step_text'] ?? ''));
        $nm  = trim((string)($r['step_name'] ?? ''));
        if ($txt === '' && $nm === '') continue;           // 整列空白的不存（可增列表格的末列）
        $seq++;
        $db->prepare("INSERT INTO ss_step (ver_id, seq, step_name, img_file_id, step_text, note) VALUES (?,?,?,?,?,?)")
           ->execute([$verId, $seq, $nm ?: null, (int)($r['img_file_id'] ?? 0) ?: null, $txt ?: null,
                      trim((string)($r['note'] ?? '')) ?: null]);
    }
}

/** 覆寫某一版的檢驗項目明細 */
function ss_items_replace(PDO $db, int $verId, array $rows): void
{
    $db->prepare("DELETE FROM ss_item WHERE ver_id=?")->execute([$verId]);
    $seq = 0;
    $f = fn($k, $r) => (($s = trim((string)($r[$k] ?? ''))) !== '' ? $s : null);
    foreach ($rows as $r) {
        if (trim((string)($r['ctrl_point'] ?? '')) === '' && trim((string)($r['q_char'] ?? '')) === '') continue;
        $seq++;
        // 擔當者存部門 id，owner 只是當下的顯示文字快取（管理員改別名之後由 ss_owner_label 重算）
        $deptId = (int)($r['owner_dept_id'] ?? 0);
        $owner  = $deptId > 0 ? ss_owner_label($db, $deptId, (string)($r['owner'] ?? '')) : $f('owner', $r);
        $db->prepare("INSERT INTO ss_item (ver_id, seq, ctrl_point, q_char, up_limit, lo_limit, owner, owner_dept_id,
                          method, tool_type_id, tool_no, freq, note)
                      VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?)")
           ->execute([$verId, $seq, $f('ctrl_point', $r), $f('q_char', $r), $f('up_limit', $r), $f('lo_limit', $r),
                      $owner ?: null, $deptId ?: null, $f('method', $r), (int)($r['tool_type_id'] ?? 0) ?: null,
                      $f('tool_no', $r), $f('freq', $r), $f('note', $r)]);
    }
}

/* ════════════════════════════ 送簽 ════════════════════════════ */

/**
 * 送簽前的必填檢查。前端會即時標紅，這裡是後端同規則再擋一次（鐵律8）。
 * @return array 缺什麼的清單，空陣列＝可以送
 */
function ss_submit_check(PDO $db, int $verId): array
{
    $full = ss_ver_full($db, $verId);
    if (!$full) return ['找不到這個版次'];
    $v = $full['ver']; $d = $full['doc']; $bad = [];
    if (empty($v['form_date'])) $bad[] = '表單日期';
    if (trim((string)$d['title']) === '') $bad[] = '文件名稱';
    if (trim((string)$v['ver_no']) === '') $bad[] = '版次';

    // 紙本掃描檔就是這份文件的全部內容（品保課那幾份量測儀器操作說明只有 PDF），
    // 這種文件本來就沒有逐列資料，不放行的話它永遠送不出去、也就永遠簽不了核
    $hasScan = false;
    foreach ($full['files'] as $f) if ((string)$f['usage_kind'] === 'scan') { $hasScan = true; break; }

    if ($full['kind'] === 'equip') {
        if (!$hasScan && trim((string)$v['op_method']) === '') $bad[] = '操作方法（或上傳紙本掃描檔）';
    } elseif ($full['kind'] === 'process') {
        if (!$hasScan && !$full['steps']) $bad[] = '至少一列操作步驟（或上傳紙本掃描檔）';
    } else {
        if (!$hasScan && !$full['items']) $bad[] = '至少一列檢驗項目（或上傳紙本掃描檔）';
    }
    /* 綁料號的一律要有圖面才送得出去（使用者 2026-09-22 指定：圖面是送簽前的必填欄位，
       但**存檔時可以先不放**——所以只在這裡擋，不在 ss_ver_save 擋）。 */
    if ((string)$d['scope'] === 'part' && !$hasScan && (int)($v['draw_file_id'] ?? 0) <= 0) {
        $bad[] = '圖面（綁料號的文件一定要帶一張圖才送得出去）';
    }
    return $bad;
}

/**
 * 送簽。maker（製表）在送出當下就成立，業務日期＝表單日期（ai-rules/21：業務日期與精確時間戳分離）。
 * 管理員可在 $opt 指定填表人與各關簽核人、以及自動簽核（$opt['auto']）。
 * 自動簽核會把 review／approve 一起簽完，時間依 ss_auto_time() 錯開且不跨日。
 *
 * @param array $opt maker_id / review_id / approve_id / sign_date / auto(bool)
 */
function ss_submit(PDO $db, int $verId, int $uid, array $opt = []): array
{
    $v = ss_ver_get($db, $verId);
    if (!$v) throw new RuntimeException('找不到這個版次');
    if ((string)$v['status'] !== 'draft') throw new RuntimeException('這個版次已經送簽過了，請重新整理頁面');
    $bad = ss_submit_check($db, $verId);
    if ($bad) throw new RuntimeException('這些欄位還沒填：' . implode('、', $bad));

    $d    = ss_doc_get($db, (int)$v['doc_id']);
    $kind = (string)($d['kind'] ?? '');
    $formDate = (string)$v['form_date'];
    $signDate = trim((string)($opt['sign_date'] ?? '')) ?: $formDate;

    $makerId = (int)($opt['maker_id'] ?? 0) ?: $uid;
    try {
        ss_sign_set($db, $verId, 'maker', $makerId, $signDate, !empty($opt['auto']));
    } catch (RuntimeException $e) {
        /* 最常見的是「用超級管理員（特殊帳號）按送出」——它不是真的員工，不可以列為簽核人。
           原本的訊息只說「當時不在職」，看不出該怎麼辦，所以在這裡講清楚要指定製表人。 */
        if ($makerId === $uid) {
            throw new RuntimeException('你的帳號不能列為製表人（' . $e->getMessage() . '）。'
                . '請在送簽視窗的「製表（填表人）」挑一位當時在職的人員。');
        }
        throw $e;
    }
    $db->prepare("UPDATE ss_ver SET status='submitted' WHERE ver_id=?")->execute([$verId]);

    $done = ['maker'];
    if (!empty($opt['auto']) || ss_auto_sign_on($db, $kind)) {
        foreach (['review', 'approve'] as $slot) {
            $sid = (int)($opt[$slot . '_id'] ?? 0);
            // 沒指定人就依「部門＋職稱」以**簽章日期當時**的職務解析（找不到再用代理）
            if ($sid <= 0) [$sid, ] = ss_resolve_signer($db, $kind, $slot, $signDate);
            if ($sid <= 0) break;                       // 兩邊都找不到就停在這一關，不亂猜人
            ss_sign_set($db, $verId, $slot, $sid, $signDate, true);
            $done[] = $slot;
        }
    }
    ss_maybe_approve($db, $verId);
    $after = ss_ver_get($db, $verId);
    return ['signed' => $done, 'status' => (string)($after['status'] ?? 'submitted')];
}

/** 作廢一個版次（不刪資料；作廢後重算現行版） */
function ss_ver_obsolete(PDO $db, int $verId, int $uid): void
{
    $v = ss_ver_get($db, $verId);
    if (!$v) throw new RuntimeException('找不到這個版次');
    $db->prepare("UPDATE ss_ver SET status='obsolete', modified_at=NOW(), modified_by=? WHERE ver_id=?")
       ->execute([$uid, $verId]);
    ss_refresh_cur_ver($db, (int)$v['doc_id']);
}

/* ════════════════════════════ 清單 ════════════════════════════ */

/**
 * 清單查詢。$f 支援：tab(sop/sip)、kind、scope、status、keyword、machine_id、part_d_id、
 * customer、year、only_current(只列現行版)。
 * 回傳的是「文件」一列一份，帶現行版的版次／日期／狀態／簽核進度。
 */
function ss_list(PDO $db, array $f): array
{
    $tab = (string)($f['tab'] ?? 'sop');
    $kinds = [];
    foreach (ss_kinds() as $k => $def) if ($def['tab'] === $tab) $kinds[] = $k;
    if (!empty($f['kind']) && isset(ss_kinds()[$f['kind']])) $kinds = [(string)$f['kind']];
    if (!$kinds) return [];

    $w = ["d.is_deleted=0", "d.kind IN (" . implode(',', array_fill(0, count($kinds), '?')) . ")"];
    $p = $kinds;
    if (!empty($f['scope']) && isset(ss_scopes()[$f['scope']])) { $w[] = "d.scope=?"; $p[] = (string)$f['scope']; }
    if (!empty($f['machine_id'])) { $w[] = "d.machine_id=?"; $p[] = (int)$f['machine_id']; }
    if (!empty($f['part_d_id'])) { $w[] = "d.part_d_id=?"; $p[] = (int)$f['part_d_id']; }
    if (!empty($f['status']) && isset(ss_statuses()[$f['status']])) { $w[] = "v.status=?"; $p[] = (string)$f['status']; }
    if (!empty($f['year'])) { $w[] = "YEAR(v.form_date)=?"; $p[] = (int)$f['year']; }
    if (!empty($f['process_no'])) { $w[] = "d.process_no=?"; $p[] = (int)$f['process_no']; }
    if (!empty($f['customer'])) {
        $w[] = "(d.customer_name LIKE ? OR v.customer_name LIKE ?)";
        $p[] = '%' . (string)$f['customer'] . '%'; $p[] = '%' . (string)$f['customer'] . '%';
    }

    // 全表搜尋：畫面上看得到的欄位都要搜得到，多個關鍵字每個都要命中（可分散在不同欄位）
    $kw = trim((string)($f['keyword'] ?? ''));
    if ($kw !== '') {
        foreach (preg_split('/\s+/u', $kw) as $word) {
            if ($word === '') continue;
            $w[] = "(d.title LIKE ? OR d.part_no_text LIKE ? OR d.proc_name LIKE ? OR v.ver_no LIKE ?
                     OR d.customer_name LIKE ? OR v.customer_name LIKE ? OR v.m_name LIKE ? OR v.use_equip LIKE ?
                     OR d.machine_model LIKE ? OR m.asset_no LIKE ? OR m.field_no LIKE ? OR m.machine LIKE ?
                     OR EXISTS (SELECT 1 FROM qc_tool qt WHERE qt.Tool_id=d.tool_id AND qt.Tool_No LIKE ?)
                     OR EXISTS (SELECT 1 FROM ss_doc_machine dm JOIN machine_list m2 ON m2.machine_id=dm.machine_id
                                WHERE dm.doc_id=d.doc_id AND (m2.asset_no LIKE ? OR m2.field_no LIKE ?)))";
            for ($i = 0; $i < 15; $i++) $p[] = '%' . $word . '%';
        }
    }

    // v.customer_name 一定要取別名：d.* 現在也有 customer_name，同名欄位在 FETCH_ASSOC 會被後者蓋掉
    $sql = "SELECT d.*, v.ver_id, v.ver_no, v.form_date, v.status, v.customer_name AS ver_customer_name,
                   v.use_equip, v.m_name, v.rev_note,
                   m.asset_no, m.field_no, m.machine AS machine_name,
                   (SELECT COUNT(*) FROM ss_ver v2 WHERE v2.doc_id=d.doc_id) AS ver_cnt,
                   (SELECT COUNT(*) FROM ss_sign s WHERE s.ver_id=v.ver_id AND s.user_id IS NOT NULL) AS sign_cnt
            FROM ss_doc d
            JOIN ss_ver v ON v.ver_id = COALESCE(d.cur_ver_id, (SELECT MAX(ver_id) FROM ss_ver x WHERE x.doc_id=d.doc_id))
            LEFT JOIN machine_list m ON m.machine_id = d.machine_id
            WHERE " . implode(' AND ', $w) . "
            /* 表單日期由新到舊（使用者 2026-09-21 指定）。
               MySQL 的 DESC 會把 NULL 排在最後，所以沒填日期的落在最底下，正合適。
               後面一定要再接一個決定性的排序鍵（doc_id）——同一天好幾份時順序若不穩定，
               翻頁會出現「同一筆在兩頁都看得到、或整筆被跳過」。 */
            ORDER BY v.form_date DESC, d.doc_id DESC";
    $st = $db->prepare($sql);
    $st->execute($p);
    $rows = $st->fetchAll(PDO::FETCH_ASSOC) ?: [];
    $slotCnt = count(ss_slots());
    foreach ($rows as &$r) {
        $r['kind_label']  = ss_kinds()[$r['kind']]['label'] ?? $r['kind'];
        $r['tab']         = ss_kinds()[$r['kind']]['tab'] ?? 'sop';
        $r['tab_label']   = $r['tab'] === 'sip' ? 'SIP' : 'SOP';
        $r['scope_label'] = ss_scopes()[$r['scope']] ?? $r['scope'];
        $r['status_label'] = ss_statuses()[$r['status']] ?? $r['status'];
        $r['sign_total']  = $slotCnt;
        // 客戶以文件主檔為準（綁料號時由料號主檔帶入），舊資料才退回版次上那一欄
        if (($r['customer_name'] ?? '') === '' || $r['customer_name'] === null) {
            $r['customer_name'] = $r['ver_customer_name'] ?? '';
        }
        // 設備操作說明書：機器編號是一對多，清單上要看得到這份文件涵蓋哪幾台
        $r['asset_text'] = (string)($r['asset_no'] ?? '');
        if ((string)$r['scope'] === 'tool') {
            $t = ss_tool_row($db, (int)($r['tool_id'] ?? 0));
            $r['asset_text'] = (string)($t['tool_no'] ?? '');
            $r['tool_type']  = (string)($t['tool_type'] ?? '');
        }
        if ((string)$r['kind'] === 'equip' && (string)$r['scope'] === 'machine') {
            $ms = ss_doc_machines($db, (int)$r['doc_id']);
            if ($ms) {
                $t = [];
                foreach ($ms as $m) $t[] = (string)($m['asset_no'] ?: $m['field_no']);
                $r['asset_text'] = implode('、', array_filter($t));
            }
            $r['machine_missing_cnt'] = count(ss_doc_machines_missing($db, $r));
        }
    }
    return $rows;
}

/* ════════════════════ AS 文件綁定與列印 ════════════════════ */

/**
 * 這一版要印哪一個 AS 編號。逐版次可覆寫綁定（as_doc_id），沒覆寫就用該版面的模組綁定。
 *
 * 版次的基準分兩種（使用者 2026-09-22 拍板，ai-rules/16 第三之四節）：
 *   - **現行版（$isCurrentVer=true）＝印現在最新版**：SOP／SIP 的現行版印出來是要貼到現場當作業標準用的，
 *     它代表「現在該怎麼做」，所以表單版次也該是現在的最新版。若照表單日期回推，一份 2022 年定的現行 SOP
 *     會永遠印著 2022 年的舊表單版次，跟現場手上的空白表單對不起來。
 *   - **已被取代的歷史版＝依表單日期回推**：那是「當時是這樣做的」的歷史紀錄（追溯舊工單附的 SOP 時會用到），
 *     要印當時生效的版次。
 */
function ss_as_no(PDO $db, string $kind, ?int $verAsDocId, ?string $formDate, bool $isCurrentVer = false): string
{
    $docId = (int)($verAsDocId ?: 0);
    if ($docId <= 0) $docId = eg_asdoc_id($db, ss_kinds()[$kind]['module'] ?? '');
    if ($docId > 0) {
        // 現行版傳 null＝視為今天＝印現在最新版（eg_asdoc_no_asof_id 的既有語意）
        $no = eg_asdoc_no_asof_id($db, $docId, $isCurrentVer ? null : $formDate);
        if ($no !== '') return $no;
    }
    return (string)(ss_kinds()[$kind]['as_no'] ?? '');
}

/** 這個版次是不是該文件目前的現行版（ss_doc.cur_ver_id 指向它）；兩個列印呼叫端共用同一個判定。 */
function ss_is_current_ver(array $docRow, array $verRow): bool
{
    $cur = (int)($docRow['cur_ver_id'] ?? 0);
    return $cur > 0 && $cur === (int)($verRow['ver_id'] ?? 0);
}

/** 表頭要印的表單名稱＝綁定 AS 文件的 doc_name（ai-rules/16：禁寫死） */
function ss_as_title(PDO $db, string $kind, ?int $verAsDocId): string
{
    $docId = (int)($verAsDocId ?: 0);
    if ($docId <= 0) $docId = eg_asdoc_id($db, ss_kinds()[$kind]['module'] ?? '');
    if ($docId > 0) {
        try {
            $st = $db->prepare("SELECT doc_name FROM as_document WHERE id=?");
            $st->execute([$docId]);
            $n = (string)$st->fetchColumn();
            if ($n !== '') return $n;
        } catch (Throwable $e) {}
    }
    return (string)(ss_kinds()[$kind]['label'] ?? '');
}

/** 本公司全名（列印大標題；動態取自客戶主檔標記為本公司的那一筆，禁寫死） */
function ss_company_name(PDO $db): string
{
    try {
        $n = (string)$db->query("SELECT customer_full FROM customer_list WHERE is_own_company=1 LIMIT 1")->fetchColumn();
        if ($n !== '') return $n;
    } catch (Throwable $e) {}
    return '';
}

/* ════════════════════════════ 檔案 ════════════════════════════ */

/** 本模組自己上傳的檔案放哪（鐵律5：全站統一根資料夾＋以頁面名稱為子資料夾，只存檔名不存路徑） */
function ss_attach_dir(PDO $db): string
{
    require_once __DIR__ . '/attach_lib.php';
    $dir = eg_attach_dir($db, 'sopsip_attach_dir', 'SOP_SIP');
    eg_attach_ensure_dir($dir);
    return $dir;
}

/**
 * 把 ss_file 一列解析成實體檔案路徑。
 * src=part 的一律轉呼叫 bom_view_file_lib 的既有解析（料號附件的實體位置只有它一個來源，
 * 在這裡再寫一份就會在 NAS 搬家時漏改）；src=upload 才是本模組自己的資料夾。
 */
function ss_file_path(PDO $db, array $f): ?string
{
    if ((string)($f['src'] ?? '') === 'part') {
        require_once __DIR__ . '/bom_view_file_lib.php';
        $r = eg_bvf_resolve($db, ['src' => 'part', 'ref' => ['id' => (int)($f['part_attach_id'] ?? 0)]]);
        return $r['fs'] ?? null;
    }
    $name = (string)($f['file_name'] ?? '');
    if ($name === '' || strpbrk($name, "/\\") !== false || strpos($name, '..') !== false) return null;
    return rtrim(ss_attach_dir($db), "/\\") . DIRECTORY_SEPARATOR . $name;
}

/** 這個副檔名適合當「帶入的圖面」嗎（圖片與 PDF；PDF 列印時轉成連結不內嵌） */
function ss_is_image(string $name): bool
{
    return (bool)preg_match('/\.(jpe?g|png|gif|bmp|webp)$/i', $name);
}

/**
 * 某個料號可以帶入的圖面候選＝該料號的料號附件（使用者要求可自己選要帶哪一個檔案）。
 * 批圖暫存檔一律不列（那不是正式圖面，見 imgedit_visibility）。
 */
function ss_part_draw_candidates(PDO $db, int $partDId): array
{
    if ($partDId <= 0) return [];
    require_once __DIR__ . '/imgedit_visibility.php';
    try {
        $st = $db->prepare("SELECT pa.id, pa.filename, pa.original_name, pa.category_ids, pa.revision,
                                   DATE(pa.uploaded_at) AS uploaded_on, pa.issue_stamp_date
                            FROM part_attachments pa
                            WHERE pa.d_id=? AND pa.deleted_at IS NULL AND " . imgedit_sql_not_draft('pa') . "
                            ORDER BY pa.uploaded_at DESC, pa.id DESC");
        $st->execute([$partDId]);
        $rows = $st->fetchAll(PDO::FETCH_ASSOC) ?: [];
    } catch (Throwable $e) { return []; }

    $cats = [];
    try {
        foreach ($db->query("SELECT id, category_name FROM quotation_file_categories")->fetchAll(PDO::FETCH_ASSOC) as $c)
            $cats[(int)$c['id']] = (string)$c['category_name'];
    } catch (Throwable $e) {}

    $out = [];
    foreach ($rows as $r) {
        $name = (string)($r['original_name'] ?: $r['filename']);
        if (!ss_is_image($name) && !preg_match('/\.pdf$/i', $name)) continue;
        $tags = [];
        foreach (preg_split('/\s*,\s*/', (string)$r['category_ids']) as $cid) {
            $cid = (int)$cid;
            if ($cid > 0 && isset($cats[$cid])) $tags[] = $cats[$cid];
        }
        $out[] = ['id' => (int)$r['id'], 'name' => $name, 'tags' => $tags,
                  'revision' => (string)($r['revision'] ?? ''),
                  'uploaded_on' => (string)($r['uploaded_on'] ?? ''),
                  'is_image' => ss_is_image($name) ? 1 : 0];
    }
    return $out;
}

/* ════════════════════════════ 搜尋 ════════════════════════════ */

/** 料號搜尋（綁定用；一律回傳料號主檔 id，同名料號用客戶分辨） */
function ss_search_part(PDO $db, string $kw, int $limit = 30): array
{
    $kw = trim($kw);
    if ($kw === '') return [];
    $st = $db->prepare("SELECT d.d_id, d.D_Setting_Id, d.Customer_Id, c.customer
                        FROM d_setting d LEFT JOIN customer_list c ON c.customer_id=d.Customer_Id
                        WHERE d.D_Setting_Id LIKE ? ORDER BY d.D_Setting_Id, d.d_id LIMIT $limit");
    $st->execute(['%' . $kw . '%']);
    return $st->fetchAll(PDO::FETCH_ASSOC) ?: [];
}

/** 機台搜尋（機器編號 asset_no／現場編號 field_no／名稱都搜得到；停用的不列） */
function ss_search_machine(PDO $db, string $kw, int $limit = 30): array
{
    $kw = trim($kw);
    $w  = ["(state IS NULL OR state <> '1')"];   // state='1' 才是停用（見 kpi_main 的機台資產設定）
    $p  = [];
    if ($kw !== '') {
        $w[] = "(asset_no LIKE ? OR field_no LIKE ? OR machine LIKE ? OR machine_model LIKE ?)";
        for ($i = 0; $i < 4; $i++) $p[] = '%' . $kw . '%';
    }
    $st = $db->prepare("SELECT machine_id, machine, field_no, asset_no, machine_model, manufacturer, spec
                        FROM machine_list WHERE " . implode(' AND ', $w) . "
                        ORDER BY asset_no, field_no LIMIT $limit");
    $st->execute($p);
    return $st->fetchAll(PDO::FETCH_ASSOC) ?: [];
}

/* ══════════════════════════════════════════════════════════════════════════
 *  以下是 2026-09-21（二次）使用者交辦那一批
 *  ──────────────────────────────────────────────────────────────────────
 *  ① 製程（＝紙本上的「工程名稱」，同一件事所以只留一欄，唯一來源是 process_no 主檔）
 *  ② 機台：設備操作說明書綁「型號」，底下一對多掛機器編號（同型號好幾台共用一份 SOP）
 *  ③ 客戶：綁料號時一律由料號主檔帶出來，不給手打（通用型才可自己挑）
 *  ④ 重複判定：版面＋綁定對象＋製程（使用者拍板）
 *  ⑤ 檢驗項目的預設值：標準項目＋製程專屬項目兩層
 *  ⑥ 擔當者存部門 id，顯示文字可由管理員改（現場講「包裝」，組織上卻沒有包裝這個部門）
 * ══════════════════════════════════════════════════════════════════════════ */

/* ────────────────────────────── 製程 ────────────────────────────── */

/** 製程一筆（回 null＝這個製程編號不存在，綁定時一律擋下） */
function ss_proc_row(PDO $db, int $no): ?array
{
    if ($no <= 0) return null;
    try {
        $st = $db->prepare("SELECT p.ProcessNo AS process_no, p.ProcessName AS process_name, t.process_type
                            FROM process_no p
                            LEFT JOIN process_type t ON t.process_type_id = p.process_type_id
                            WHERE p.ProcessNo=?");
        $st->execute([$no]);
        $r = $st->fetch(PDO::FETCH_ASSOC);
        return $r ?: null;
    } catch (Throwable $e) { return null; }
}

/**
 * 製程模糊搜尋（編號與名稱都搜得到）。製程有 209 筆，不可以用一個攤開的下拉讓人用眼睛找
 * （CLAUDE.md 的長清單下拉鐵則）。
 */
function ss_search_process(PDO $db, string $kw, int $limit = 40): array
{
    $kw = trim($kw);
    $w = []; $p = [];
    if ($kw !== '') {
        $w[] = "(p.ProcessName LIKE ? OR p.ProcessNo LIKE ? OR t.process_type LIKE ?)";
        for ($i = 0; $i < 3; $i++) $p[] = '%' . $kw . '%';
    }
    $sql = "SELECT p.ProcessNo AS process_no, p.ProcessName AS process_name, t.process_type
            FROM process_no p LEFT JOIN process_type t ON t.process_type_id = p.process_type_id"
         . ($w ? " WHERE " . implode(' AND ', $w) : '')
         . " ORDER BY p.ProcessNo LIMIT $limit";
    try {
        $st = $db->prepare($sql);
        $st->execute($p);
        return $st->fetchAll(PDO::FETCH_ASSOC) ?: [];
    } catch (Throwable $e) { return []; }
}

/* ────────────────────────────── 客戶 ────────────────────────────── */

/**
 * 綁了料號就由**料號主檔**決定客戶（使用者要求：客戶不給手打）。
 * 同一個料號文字在 d_setting 常常分屬好幾家客戶，所以一定是從 d_id 這一筆去查，
 * 不是拿料號文字去猜（記憶 bom_client_name_cache 同一條）。
 */
function ss_customer_of_part(PDO $db, int $partDId): array
{
    if ($partDId <= 0) return ['id' => '', 'name' => ''];
    try {
        $st = $db->prepare("SELECT d.Customer_Id, c.customer
                            FROM d_setting d
                            LEFT JOIN customer_list c ON c.customer_id = d.Customer_Id
                            WHERE d.d_id=?");
        $st->execute([$partDId]);
        $r = $st->fetch(PDO::FETCH_ASSOC);
        if (!$r) return ['id' => '', 'name' => ''];
        return ['id' => (string)($r['Customer_Id'] ?? ''), 'name' => (string)($r['customer'] ?? '')];
    } catch (Throwable $e) { return ['id' => '', 'name' => '']; }
}

/** 客戶模糊搜尋（客戶編號與簡稱都搜得到；只有通用型文件用得到） */
function ss_search_customer(PDO $db, string $kw, int $limit = 30): array
{
    $kw = trim($kw);
    $w = []; $p = [];
    if ($kw !== '') {
        $w[] = "(customer LIKE ? OR customer_id LIKE ? OR customer_full LIKE ?)";
        for ($i = 0; $i < 3; $i++) $p[] = '%' . $kw . '%';
    }
    try {
        $sql = "SELECT customer_id, customer, customer_full FROM customer_list"
             . ($w ? " WHERE " . implode(' AND ', $w) : '')
             . " ORDER BY customer_id LIMIT $limit";
        $st = $db->prepare($sql);
        $st->execute($p);
        return $st->fetchAll(PDO::FETCH_ASSOC) ?: [];
    } catch (Throwable $e) { return []; }
}

/* ────────────────────────────── 機台 ────────────────────────────── */

/** 在用的機台（state='1' 才是停用，見 kpi_main 的機台資產設定） */
const SS_MACHINE_ACTIVE = "(state IS NULL OR state <> '1')";

/** 機台型號清單（同型號幾台一起回；設備 SOP 綁的就是型號） */
function ss_machine_models(PDO $db, string $kw = '', int $limit = 60): array
{
    $kw = trim($kw);
    $w  = [SS_MACHINE_ACTIVE, "machine_model IS NOT NULL", "machine_model <> ''"];
    $p  = [];
    if ($kw !== '') {
        $w[] = "(machine_model LIKE ? OR machine LIKE ? OR asset_no LIKE ? OR field_no LIKE ?)";
        for ($i = 0; $i < 4; $i++) $p[] = '%' . $kw . '%';
    }
    try {
        $st = $db->prepare("SELECT machine_model, COUNT(*) AS cnt,
                                   MIN(machine) AS machine, MIN(manufacturer) AS manufacturer,
                                   GROUP_CONCAT(asset_no ORDER BY asset_no SEPARATOR '、') AS asset_nos
                            FROM machine_list WHERE " . implode(' AND ', $w) . "
                            GROUP BY machine_model ORDER BY machine_model LIMIT $limit");
        $st->execute($p);
        return $st->fetchAll(PDO::FETCH_ASSOC) ?: [];
    } catch (Throwable $e) { return []; }
}

/** 某個型號目前在用的全部機台（使用者拍板：選型號就全部帶進來，再逐台勾掉不要的） */
function ss_machines_by_model(PDO $db, string $model): array
{
    $model = trim($model);
    if ($model === '') return [];
    try {
        $st = $db->prepare("SELECT machine_id, machine, field_no, asset_no, machine_model, manufacturer, spec,
                                   machine_type_id
                            FROM machine_list
                            WHERE machine_model=? AND " . SS_MACHINE_ACTIVE . "
                            ORDER BY asset_no, field_no");
        $st->execute([$model]);
        return $st->fetchAll(PDO::FETCH_ASSOC) ?: [];
    } catch (Throwable $e) { return []; }
}

/** 這份文件掛了哪幾台機器（一對多明細，依機器編號排序） */
function ss_doc_machines(PDO $db, int $docId): array
{
    if ($docId <= 0) return [];
    try {
        $st = $db->prepare("SELECT m.machine_id, m.machine, m.field_no, m.asset_no, m.machine_model,
                                   m.manufacturer, m.spec, m.state
                            FROM ss_doc_machine dm
                            JOIN machine_list m ON m.machine_id = dm.machine_id
                            WHERE dm.doc_id=? ORDER BY m.asset_no, m.field_no");
        $st->execute([$docId]);
        return $st->fetchAll(PDO::FETCH_ASSOC) ?: [];
    } catch (Throwable $e) { return []; }
}

/** 覆寫這份文件掛的機器編號（唯一寫入點；不存在或已停用的機台一律不寫進去） */
function ss_doc_machines_set(PDO $db, int $docId, array $ids): void
{
    $db->prepare("DELETE FROM ss_doc_machine WHERE doc_id=?")->execute([$docId]);
    $seen = [];
    foreach ($ids as $id) {
        $id = (int)$id;
        if ($id <= 0 || isset($seen[$id])) continue;
        $seen[$id] = 1;
        $st = $db->prepare("SELECT 1 FROM machine_list WHERE machine_id=? AND " . SS_MACHINE_ACTIVE);
        $st->execute([$id]);
        if (!$st->fetchColumn()) continue;
        $db->prepare("INSERT IGNORE INTO ss_doc_machine (doc_id, machine_id) VALUES (?,?)")->execute([$docId, $id]);
    }
}

/**
 * 同型號有新機台、這份文件卻還沒掛上去的那幾台。
 * 一定要標出來——不標的話新買的同型號機器永遠不會被納入既有的 SOP，而且完全看不出來。
 */
function ss_doc_machines_missing(PDO $db, array $doc): array
{
    $model = trim((string)($doc['machine_model'] ?? ''));
    if ($model === '') return [];
    $have = [];
    foreach (ss_doc_machines($db, (int)$doc['doc_id']) as $m) $have[(int)$m['machine_id']] = 1;
    $out = [];
    foreach (ss_machines_by_model($db, $model) as $m) if (empty($have[(int)$m['machine_id']])) $out[] = $m;
    return $out;
}

/**
 * 設備操作說明書表頭那四格一律由機台主檔帶出來（使用者要求）。
 * 鐵律4：機器製造商／名稱／型式規格／加工適用範圍在 machine_list 本來就有，
 * 在 ss_ver 再存一份文字，主檔改了這裡不會跟著改而且不報錯。
 */
function ss_machine_meta(PDO $db, array $doc): array
{
    $rows = ss_doc_machines($db, (int)($doc['doc_id'] ?? 0));
    if (!$rows && (int)($doc['machine_id'] ?? 0) > 0) {        // 舊資料：只綁單台
        $one = ss_machine_row($db, (int)$doc['machine_id']);
        if ($one) $rows = [$one];
    }
    $pick = function (string $k) use ($rows) {
        foreach ($rows as $r) { $v = trim((string)($r[$k] ?? '')); if ($v !== '') return $v; }
        return '';
    };
    $assets = [];
    foreach ($rows as $r) {
        $a = trim((string)($r['asset_no'] ?? ''));
        $f = trim((string)($r['field_no'] ?? ''));
        $assets[] = $a !== '' ? ($a . ($f !== '' ? '（' . $f . '）' : '')) : $f;
    }
    return [
        'machines'   => $rows,
        'asset_text' => implode('、', array_filter($assets)),
        'm_maker'    => $pick('manufacturer'),
        'm_name'     => $pick('machine'),
        'm_spec'     => trim((string)($doc['machine_model'] ?? '')) ?: $pick('machine_model'),
        'm_range'    => $pick('spec'),
    ];
}

/* ────────────────────── 文件名稱自動產生 ────────────────────── */

/**
 * 文件名稱自動產生（使用者要求：自動設定、但可手動改）。
 * 規則刻意簡單看得懂；前端只有在「使用者沒有自己打過名稱」時才套用，改過就不再蓋掉。
 */
function ss_auto_title(PDO $db, string $kind, string $scope, array $in): string
{
    $proc = '';
    $pno  = (int)($in['process_no'] ?? 0);
    if ($pno > 0) { $r = ss_proc_row($db, $pno); $proc = (string)($r['process_name'] ?? ''); }

    if ($scope === 'tool') {
        $t = ss_tool_row($db, (int)($in['tool_id'] ?? 0));
        if (!$t) return '';
        return trim(((string)$t['tool_type'] !== '' ? $t['tool_type'] . ' ' : '') . (string)$t['tool_no']);
    }
    if ($kind === 'equip') {
        $model = trim((string)($in['machine_model'] ?? ''));
        $name  = '';
        foreach (ss_machines_by_model($db, $model) as $m) { $name = (string)$m['machine']; break; }
        if ($name === '' && (int)($in['machine_id'] ?? 0) > 0) {
            $one   = ss_machine_row($db, (int)$in['machine_id']);
            $name  = (string)($one['machine'] ?? '');
            $model = $model !== '' ? $model : (string)($one['machine_model'] ?? '');
        }
        return trim($name . ($model !== '' ? ' ' . $model : '')) ?: $model;
    }

    $part = '';
    if ($scope === 'part') {
        $st = $db->prepare("SELECT D_Setting_Id FROM d_setting WHERE d_id=?");
        $st->execute([(int)($in['part_d_id'] ?? 0)]);
        $part = (string)($st->fetchColumn() ?: '');
    }
    if ($kind === 'sip') {
        return trim(($part !== '' ? $part : '通用') . ($proc !== '' ? ' ' . $proc : ''));
    }
    // process：製程為主，綁了料號才把料號接在後面
    if ($proc !== '' && $part !== '') return $proc . ' ' . $part;
    return $proc !== '' ? $proc : $part;
}

/* ────────────────────────── 重複判定 ────────────────────────── */

/**
 * 「同一個料號／機台不可以有兩份」。使用者拍板的判定鍵＝**版面＋綁定對象＋製程**：
 * 同一個料號的「粗滾」與「齒研」可以各有一份，同製程就不行。
 * 綁定對象：equip＝機台型號（型號空白的舊資料退回比 machine_id）、part＝料號主檔 d_id、
 * general＝不綁對象（只比製程）。
 *
 * @return array 撞到的文件（空陣列＝沒撞到）
 */
function ss_dup_find(PDO $db, string $kind, string $scope, array $in, int $exceptDocId = 0): array
{
    $w = ["d.is_deleted=0", "d.kind=?"];
    $p = [$kind];

    if ($scope === 'tool') {
        $tid = (int)($in['tool_id'] ?? 0);
        if ($tid <= 0) return [];
        $w[] = "d.tool_id=?"; $p[] = $tid;
    } elseif ($scope === 'machine') {
        $model = trim((string)($in['machine_model'] ?? ''));
        if ($model !== '')                             { $w[] = "d.machine_model=?"; $p[] = $model; }
        elseif ((int)($in['machine_id'] ?? 0) > 0)     { $w[] = "d.machine_id=?";    $p[] = (int)$in['machine_id']; }
        else return [];
    } elseif ($scope === 'part') {
        $pid = (int)($in['part_d_id'] ?? 0);
        if ($pid <= 0) return [];
        $w[] = "d.part_d_id=?"; $p[] = $pid;
    } else {
        $w[] = "d.scope='general'";
    }

    $pno = (int)($in['process_no'] ?? 0);
    if ($pno > 0) { $w[] = "d.process_no=?"; $p[] = $pno; }
    else          { $w[] = "(d.process_no IS NULL OR d.process_no=0)"; }

    if ($exceptDocId > 0) { $w[] = "d.doc_id<>?"; $p[] = $exceptDocId; }

    try {
        $st = $db->prepare("SELECT d.doc_id, d.title, d.kind, d.scope, d.part_no_text, d.machine_model,
                                   d.proc_name, d.created_by_name, d.created_at,
                                   v.ver_id, v.ver_no, v.form_date, v.status
                            FROM ss_doc d
                            LEFT JOIN ss_ver v ON v.ver_id = COALESCE(d.cur_ver_id,
                                    (SELECT MAX(ver_id) FROM ss_ver x WHERE x.doc_id=d.doc_id))
                            WHERE " . implode(' AND ', $w) . "
                            ORDER BY d.doc_id");
        $st->execute($p);
        $rows = $st->fetchAll(PDO::FETCH_ASSOC) ?: [];
        foreach ($rows as &$r) $r['status_label'] = ss_statuses()[$r['status']] ?? (string)$r['status'];
        return $rows;
    } catch (Throwable $e) { return []; }
}

/* ────────────────── 擔當者部門（顯示文字可改） ────────────────── */

/**
 * 擔當者只能挑部門（使用者要求），但現場講的是「品管／生產／包裝」——
 * 組織上根本沒有「包裝」這個部門，所以顯示文字要能由管理員逐部門改寫。
 * 設定：owner_depts = [{dept_id, label, on}]；沒設定過就退回「課級以下全部部門，顯示部門原名」。
 * 部門名稱一律即時查，**不存第二份**（部門改名時這裡會跟著改）。
 */
function ss_owner_depts(PDO $db): array
{
    $cfg = ss_setting_get($db, 'owner_depts', null);
    $all = [];
    try {
        $st = $db->query("SELECT id, name, level FROM department ORDER BY level, sort_order, id");
        foreach ($st->fetchAll(PDO::FETCH_ASSOC) ?: [] as $r) $all[(int)$r['id']] = $r;
    } catch (Throwable $e) { return []; }

    if (!is_array($cfg) || !$cfg) {
        $out = [];
        foreach ($all as $id => $r) {
            if ((int)$r['level'] < 3) continue;          // 董事長室／總經理室不會是擔當者
            $out[] = ['dept_id' => $id, 'name' => (string)$r['name'], 'label' => (string)$r['name'], 'on' => 1];
        }
        return $out;
    }
    $out = [];
    foreach ($cfg as $c) {
        $id = (int)($c['dept_id'] ?? 0);
        if ($id <= 0 || empty($all[$id])) continue;      // 部門被刪掉了就自動不列
        $lab = trim((string)($c['label'] ?? ''));
        $out[] = ['dept_id' => $id, 'name' => (string)$all[$id]['name'],
                  'label'   => $lab !== '' ? $lab : (string)$all[$id]['name'],
                  'on'      => empty($c['on']) ? 0 : 1];
    }
    return $out;
}

/** 部門 id → 要印出來的擔當者文字（唯一實作，畫面與列印共用） */
function ss_owner_label(PDO $db, int $deptId, string $fallback = ''): string
{
    if ($deptId <= 0) return $fallback;
    foreach (ss_owner_depts($db) as $d) if ((int)$d['dept_id'] === $deptId) return (string)$d['label'];
    return $fallback;
}

/* ─────────────────── 檢驗方法與檢具（量具主檔） ─────────────────── */

/** 量具類型（唯一主檔＝qc_tool_list，本模組絕不自己再存一份類型名稱） */
function ss_tool_types(PDO $db): array
{
    try {
        $st = $db->query("SELECT QC_Tool_List_id AS id, QC_Tool AS name, has_tool_no
                          FROM qc_tool_list ORDER BY sort_order, QC_Tool_List_id");
        return $st->fetchAll(PDO::FETCH_ASSOC) ?: [];
    } catch (Throwable $e) { return []; }
}

/** 某個量具類型底下還在用的編號（qc_tool.state=0 是停用） */
function ss_tools_by_type(PDO $db, int $typeId): array
{
    if ($typeId <= 0) return [];
    try {
        $st = $db->prepare("SELECT Tool_id AS id, Tool_No AS tool_no, spec_desc
                            FROM qc_tool
                            WHERE QC_Tool_List_id=? AND (state IS NULL OR state<>0)
                            ORDER BY Tool_No");
        $st->execute([$typeId]);
        return $st->fetchAll(PDO::FETCH_ASSOC) ?: [];
    } catch (Throwable $e) { return []; }
}

/**
 * 檢驗方法的可選清單（使用者要求：可以指定其中幾個量具類型混合，再加上自建的項目）。
 * 量具類型的名稱一律即時取自 qc_tool_list，這裡只存「挑了哪幾個 id」——
 * 存名稱的話類型改名之後這裡會繼續顯示舊名而且不報錯（鐵律4）。
 */
function ss_method_options(PDO $db): array
{
    $ids   = ss_setting_get($db, 'method_tool_types', null);
    $extra = ss_setting_get($db, 'method_extra', []);
    $extra = is_array($extra) ? $extra : [];

    $types = [];
    foreach (ss_tool_types($db) as $t) $types[(int)$t['id']] = $t;

    // 從來沒設定過就先列出全部量具類型（值域一律取自 qc_tool_list，不在這裡自己發明一份清單）；
    // 管理員在設定頁勾掉不要的之後才會存成明確的清單，那時存的是空陣列也會被尊重。
    if (!is_array($ids)) $ids = array_keys($types);
    else $ids = array_map('intval', $ids);

    $list = [];
    foreach ($ids as $id) {
        if (empty($types[$id])) continue;               // 類型被刪掉就自動不列
        $list[] = ['text' => (string)$types[$id]['name'], 'tool_type_id' => $id,
                   'has_tool_no' => (int)$types[$id]['has_tool_no']];
    }
    foreach ($extra as $e) {
        $e = trim((string)$e);
        if ($e === '') continue;
        $list[] = ['text' => $e, 'tool_type_id' => 0, 'has_tool_no' => 0];
    }
    $clean = [];
    foreach ($extra as $e) { $e = trim((string)$e); if ($e !== '') $clean[] = $e; }
    return ['tool_type_ids' => $ids, 'extra' => $clean, 'list' => $list];
}

/* ─────────────────── 檢驗項目的預設值（兩層） ─────────────────── */

/** 樣板列（tpl_kind=std 全站標準項目／proc 某個製程專屬） */
function ss_tpl_rows(PDO $db, string $kind, int $processNo = 0): array
{
    $kind = $kind === 'proc' ? 'proc' : 'std';
    try {
        if ($kind === 'proc') {
            $st = $db->prepare("SELECT * FROM ss_item_tpl WHERE tpl_kind='proc' AND process_no=? ORDER BY seq, tpl_id");
            $st->execute([$processNo]);
        } else {
            $st = $db->query("SELECT * FROM ss_item_tpl WHERE tpl_kind='std' ORDER BY seq, tpl_id");
        }
        $rows = $st->fetchAll(PDO::FETCH_ASSOC) ?: [];
        foreach ($rows as &$r) {
            $r['owner_label'] = ss_owner_label($db, (int)$r['owner_dept_id'], (string)($r['owner'] ?? ''));
        }
        return $rows;
    } catch (Throwable $e) { return []; }
}

/** 覆寫一組樣板（整批取代，唯一寫入點） */
function ss_tpl_replace(PDO $db, string $kind, int $processNo, array $rows, int $uid): void
{
    $kind = $kind === 'proc' ? 'proc' : 'std';
    if ($kind === 'proc' && $processNo <= 0) throw new RuntimeException('請先選製程');
    if ($kind === 'proc' && !ss_proc_row($db, $processNo)) throw new RuntimeException('找不到這個製程');

    if ($kind === 'proc') $db->prepare("DELETE FROM ss_item_tpl WHERE tpl_kind='proc' AND process_no=?")->execute([$processNo]);
    else                  $db->prepare("DELETE FROM ss_item_tpl WHERE tpl_kind='std'")->execute();

    $f = function ($k, $r) { $s = trim((string)($r[$k] ?? '')); return $s !== '' ? $s : null; };
    $seq = 0;
    foreach ($rows as $r) {
        if (trim((string)($r['ctrl_point'] ?? '')) === '' && trim((string)($r['q_char'] ?? '')) === '') continue;
        $seq++;
        $deptId = (int)($r['owner_dept_id'] ?? 0);
        $db->prepare("INSERT INTO ss_item_tpl (tpl_kind, process_no, seq, ctrl_point, q_char, up_limit, lo_limit,
                          owner_dept_id, owner, method, tool_type_id, tool_no, freq, note, is_active, modified_at, modified_by)
                      VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,1,NOW(),?)")
           ->execute([$kind, $kind === 'proc' ? $processNo : null, $seq,
                      $f('ctrl_point', $r), $f('q_char', $r), $f('up_limit', $r), $f('lo_limit', $r),
                      $deptId ?: null, $deptId > 0 ? ss_owner_label($db, $deptId) : $f('owner', $r),
                      $f('method', $r), (int)($r['tool_type_id'] ?? 0) ?: null, $f('tool_no', $r),
                      $f('freq', $r), $f('note', $r), $uid]);
    }
}

/** 逐製程的代入設定（沒設定過＝預設兩個都開） */
function ss_proc_cfg(PDO $db, int $processNo): array
{
    $out = ['process_no' => $processNo, 'auto_apply' => 1, 'with_std' => 1];
    if ($processNo <= 0) return $out;
    try {
        $st = $db->prepare("SELECT auto_apply, with_std FROM ss_proc_cfg WHERE process_no=?");
        $st->execute([$processNo]);
        $r = $st->fetch(PDO::FETCH_ASSOC);
        if ($r) { $out['auto_apply'] = (int)$r['auto_apply']; $out['with_std'] = (int)$r['with_std']; }
    } catch (Throwable $e) { /* 表還沒建：用預設 */ }
    return $out;
}

function ss_proc_cfg_set(PDO $db, int $processNo, int $autoApply, int $withStd, int $uid): void
{
    if ($processNo <= 0 || !ss_proc_row($db, $processNo)) throw new RuntimeException('找不到這個製程');
    $db->prepare("INSERT INTO ss_proc_cfg (process_no, auto_apply, with_std, modified_at, modified_by)
                  VALUES (?,?,?,NOW(),?)
                  ON DUPLICATE KEY UPDATE auto_apply=VALUES(auto_apply), with_std=VALUES(with_std),
                                          modified_at=NOW(), modified_by=VALUES(modified_by)")
       ->execute([$processNo, $autoApply ? 1 : 0, $withStd ? 1 : 0, $uid]);
}

/** 哪些製程已經設過專屬項目（209 個製程，不標出來沒人知道設過哪幾個） */
function ss_tpl_processes(PDO $db): array
{
    try {
        $st = $db->query("SELECT t.process_no, COUNT(*) AS cnt, p.ProcessName AS process_name,
                                 COALESCE(c.auto_apply,1) AS auto_apply, COALESCE(c.with_std,1) AS with_std
                          FROM ss_item_tpl t
                          LEFT JOIN process_no p ON p.ProcessNo = t.process_no
                          LEFT JOIN ss_proc_cfg c ON c.process_no = t.process_no
                          WHERE t.tpl_kind='proc' AND t.process_no IS NOT NULL
                          GROUP BY t.process_no ORDER BY t.process_no");
        return $st->fetchAll(PDO::FETCH_ASSOC) ?: [];
    } catch (Throwable $e) { return []; }
}

/**
 * 組出「要代入的檢驗項目」＝製程專屬項目（在前）＋標準項目（在後）。
 * $withStd = null 時照該製程的設定（使用者要求：製程設定專屬項目後，也可預設是否代入標準項目）。
 * 代入之後使用者仍然可以逐列刪掉不要的，所以這裡只負責「給一份建議」。
 */
function ss_default_items(PDO $db, int $processNo, ?bool $withStd = null): array
{
    $cfg  = ss_proc_cfg($db, $processNo);
    $std  = $withStd === null ? ((int)$cfg['with_std'] === 1) : $withStd;
    $rows = [];
    foreach (ss_tpl_rows($db, 'proc', $processNo) as $r) $rows[] = $r;
    if ($std) foreach (ss_tpl_rows($db, 'std') as $r) $rows[] = $r;

    $out = [];
    foreach ($rows as $r) {
        $out[] = [
            'ctrl_point' => (string)($r['ctrl_point'] ?? ''), 'q_char' => (string)($r['q_char'] ?? ''),
            'up_limit'   => (string)($r['up_limit'] ?? ''),   'lo_limit' => (string)($r['lo_limit'] ?? ''),
            'owner_dept_id' => (int)($r['owner_dept_id'] ?? 0), 'owner' => (string)($r['owner_label'] ?? ''),
            'method'     => (string)($r['method'] ?? ''),     'tool_type_id' => (int)($r['tool_type_id'] ?? 0),
            'tool_no'    => (string)($r['tool_no'] ?? ''),    'freq' => (string)($r['freq'] ?? ''),
            'note'       => (string)($r['note'] ?? ''),       'from_tpl' => 1,
        ];
    }
    return $out;
}

/* ─────────────────────── 段落附件與旋轉 ─────────────────────── */

/**
 * 哪些段落可以加附件（唯一登記處）。使用者要求「操作方法、使用注意事項…全部都要可以加附件，
 * 上傳後畫面要更新顯示縮圖」，列印時接在該段文字下方。
 */
function ss_sections(string $kind): array
{
    if ($kind === 'equip') {
        return ['op_method' => '操作方法', 'cautions' => '使用注意事項', 'maintain' => '保養維修要點'];
    }
    if ($kind === 'sip') return ['notice' => '注意事項'];
    return ['use_equip' => '使用設備說明'];
}

/** 某一版的段落附件：段落代碼 => 檔案列 */
function ss_section_files(PDO $db, int $docId, int $verId): array
{
    $out = [];
    foreach (ss_file_rows($db, $docId, $verId) as $f) {
        if ((string)$f['usage_kind'] !== 'sec') continue;
        $k = (string)($f['sec_key'] ?? '');
        if ($k === '') continue;
        $out[$k][] = $f;
    }
    return $out;
}

/** 旋轉角度正規化（只收 0/90/180/270） */
function ss_rot_norm(int $deg): int
{
    $d = ((int)$deg % 360 + 360) % 360;
    return in_array($d, [0, 90, 180, 270], true) ? $d : 0;
}

/**
 * 取得「要送給瀏覽器的那個檔案路徑」。
 * 使用者拍板：旋轉**只影響這份文件**，原檔一個位元組都不動——所以這裡是把旋轉後的結果
 * 產生成一份快取檔，原始檔留在原地（同一張圖在圖面查閱、料號主檔仍然是原來的方向）。
 * 快取檔名帶原檔的 mtime，原檔一換就自動失效。
 */
function ss_file_view_path(PDO $db, array $f): ?string
{
    $src = ss_file_path($db, $f);
    if (!$src || !is_file($src)) return null;
    $rot = ss_rot_norm((int)($f['rot'] ?? 0));
    if ($rot === 0) return $src;

    require_once __DIR__ . '/image_rotate_lib.php';
    $ext = strtolower((string)pathinfo($src, PATHINFO_EXTENSION));
    if (!in_array($ext, eg_rotate_exts(), true)) return $src;

    $dir = rtrim(sys_get_temp_dir(), "/\\") . DIRECTORY_SEPARATOR . 'eg_ss_rot';
    if (!is_dir($dir)) @mkdir($dir, 0777, true);
    $dst = $dir . DIRECTORY_SEPARATOR . 'f' . (int)$f['file_id'] . '_' . $rot . '_' . (int)@filemtime($src) . '.' . $ext;
    if (is_file($dst) && filesize($dst) > 0) return $dst;

    $ok = ($ext === 'pdf') ? eg_rotate_pdf_to($src, $dst, $rot) : eg_rotate_image_to($src, $dst, $rot);
    if (!$ok || !is_file($dst)) { @unlink($dst); return $src; }   // 轉不動就照原圖出，不要變成破圖
    return $dst;
}

/* ────────────────────────── 刪除 ────────────────────────── */

/**
 * 這個人可不可以刪掉這份文件。
 * 管理員一律可刪；一般使用者只能刪**自己建立、而且一個版次都還沒核准**的
 * （使用者要求：「使用者可刪除自行建立之未核准之案件」）。
 * @return array [ok, why]
 */
function ss_can_delete_doc(PDO $db, array $doc, int $uid, array $P): array
{
    if (!empty($P['canAdmin'])) return [true, ''];
    if (!ss_perm_for_kind($P, (string)$doc['kind'], 'edit')) return [false, '沒有修改這一類文件的權限'];
    if ((int)($doc['created_by'] ?? 0) !== $uid) {
        return [false, '這份文件是「' . (string)($doc['created_by_name'] ?: '別人') . '」建立的，只有建立者本人或管理員可以刪除'];
    }
    try {
        $st = $db->prepare("SELECT COUNT(*) FROM ss_ver WHERE doc_id=? AND status='approved'");
        $st->execute([(int)$doc['doc_id']]);
        if ((int)$st->fetchColumn() > 0) return [false, '已經有版次核准過了，核准的文件不可以刪除（要停用請把該版次作廢）'];
    } catch (Throwable $e) { return [false, '無法確認核准狀態，請重新整理後再試']; }
    return [true, ''];
}

/**
 * 從既有文件反推「建議的預設項目」。
 * 設定頁一開始是空的，要人工把那幾列標準項目打一遍很不切實際；而既有 18 份匯入的檢驗指導書裡，
 * 精度等級／成品檢驗報告／外觀／包裝那幾列本來就每一份都一樣，直接統計出來當建議最省事。
 *
 * **刻意排除有上下限的列**：那是某個料號自己的尺寸（跨齒厚 21.836），不可能當共用的預設值。
 * 這裡只「建議」，要不要存下來由使用者按儲存決定。
 */
function ss_tpl_suggest(PDO $db, string $kind, int $processNo = 0): array
{
    $w = ["d.is_deleted=0", "d.kind='sip'",
          "(i.up_limit IS NULL OR i.up_limit='')", "(i.lo_limit IS NULL OR i.lo_limit='')",
          "(i.ctrl_point IS NOT NULL AND i.ctrl_point<>'')"];
    $p = [];
    if ($kind === 'proc') {
        if ($processNo <= 0) return [];
        $w[] = "d.process_no=?"; $p[] = $processNo;
    }
    try {
        $st = $db->prepare("SELECT i.ctrl_point, i.q_char, i.owner, i.owner_dept_id, i.method, i.tool_type_id,
                                   i.tool_no, i.freq, i.note, COUNT(DISTINCT d.doc_id) AS docs
                            FROM ss_item i
                            JOIN ss_ver v ON v.ver_id = i.ver_id
                            JOIN ss_doc d ON d.doc_id = v.doc_id
                            WHERE " . implode(' AND ', $w) . "
                            GROUP BY i.ctrl_point, i.q_char, i.owner, i.owner_dept_id, i.method, i.tool_type_id,
                                     i.tool_no, i.freq, i.note
                            HAVING docs >= 2
                            ORDER BY docs DESC, i.ctrl_point LIMIT 40");
        $st->execute($p);
        $rows = $st->fetchAll(PDO::FETCH_ASSOC) ?: [];
        // 擔當者：舊資料只有文字（品管／生產／包裝），對得回部門就順便帶上部門 id
        $byLabel = [];
        foreach (ss_owner_depts($db) as $o) $byLabel[(string)$o['label']] = (int)$o['dept_id'];
        foreach ($rows as &$r) {
            if ((int)($r['owner_dept_id'] ?? 0) <= 0 && isset($byLabel[(string)$r['owner']])) {
                $r['owner_dept_id'] = $byLabel[(string)$r['owner']];
            }
            $r['owner_label'] = (string)($r['owner'] ?? '');
        }
        return $rows;
    } catch (Throwable $e) { return []; }
}

/* ══════════════════════════════════════════════════════════════════════════
 *  2026-09-22 使用者交辦那一批
 *  ① 設備操作說明書除了機台，也要能綁「檢驗設備一覽表」裡的量具
 *  ② 列印紙張大小與方向可預先設定（機台／量具＝A4 直式，料號相關＝A3 橫式）
 *  ③ 自動簽核改成設「部門＋職稱」不是設人——設人的話補歷史單據時那個人可能還沒到職，
 *     之後也可能離職；職稱才是穩定的。另可各設一位「代理部門職稱」。
 *  ④ 管理員可以在核准後補附件，或把「自動核准」的那幾張退回草稿
 * ══════════════════════════════════════════════════════════════════════════ */

/* ────────────────────── 量具（檢驗設備一覽表） ────────────────────── */

/** 一支量具（機器編號欄印 Tool_No，規格印 spec_desc） */
function ss_tool_row(PDO $db, int $toolId): ?array
{
    if ($toolId <= 0) return null;
    try {
        $st = $db->prepare("SELECT t.Tool_id AS tool_id, t.Tool_No AS tool_no, t.manufacturer, t.spec_desc,
                                   t.machine, t.machine_model, t.note, t.state,
                                   l.QC_Tool AS tool_type, l.QC_Tool_List_id AS tool_type_id
                            FROM qc_tool t
                            LEFT JOIN qc_tool_list l ON l.QC_Tool_List_id = t.QC_Tool_List_id
                            WHERE t.Tool_id=?");
        $st->execute([$toolId]);
        $r = $st->fetch(PDO::FETCH_ASSOC);
        return $r ?: null;
    } catch (Throwable $e) { return null; }
}

/** 量具搜尋（編號、種類、製造商、規格都搜得到；停用的不列） */
function ss_search_tool(PDO $db, string $kw, int $limit = 40): array
{
    $kw = trim($kw);
    $w = ["(t.state IS NULL OR t.state<>0)"];
    $p = [];
    if ($kw !== '') {
        $w[] = "(t.Tool_No LIKE ? OR l.QC_Tool LIKE ? OR t.manufacturer LIKE ? OR t.spec_desc LIKE ?)";
        for ($i = 0; $i < 4; $i++) $p[] = '%' . $kw . '%';
    }
    try {
        $st = $db->prepare("SELECT t.Tool_id AS tool_id, t.Tool_No AS tool_no, t.manufacturer, t.spec_desc,
                                   l.QC_Tool AS tool_type
                            FROM qc_tool t
                            LEFT JOIN qc_tool_list l ON l.QC_Tool_List_id = t.QC_Tool_List_id
                            WHERE " . implode(' AND ', $w) . "
                            ORDER BY t.Tool_No LIMIT $limit");
        $st->execute($p);
        return $st->fetchAll(PDO::FETCH_ASSOC) ?: [];
    } catch (Throwable $e) { return []; }
}

/**
 * 設備操作說明書表頭那四格的來源（機台或量具，唯一實作）。
 * 綁量具時：機器名稱＝量具種類、型式規格＝規格說明、機器編號＝量具編號。
 */
function ss_equip_meta(PDO $db, array $doc): array
{
    if ((string)($doc['scope'] ?? '') === 'tool') {
        $t = ss_tool_row($db, (int)($doc['tool_id'] ?? 0));
        return [
            'machines'   => [],
            'asset_text' => (string)($t['tool_no'] ?? ''),
            'm_maker'    => (string)($t['manufacturer'] ?? ''),
            'm_name'     => (string)($t['tool_type'] ?? ''),
            'm_spec'     => (string)($t['spec_desc'] ?? $t['machine_model'] ?? ''),
            'm_range'    => (string)($t['note'] ?? ''),
        ];
    }
    return ss_machine_meta($db, $doc);
}

/* ────────────────────────── 列印紙張 ────────────────────────── */

/** 可選的紙張與方向（唯一登記處） */
function ss_papers(): array   { return ['A4' => 'A4', 'A3' => 'A3']; }
function ss_orients(): array  { return ['portrait' => '直式', 'landscape' => '橫式']; }

/**
 * 這份文件要印在什麼紙上。使用者定的預設（2026-09-22）：
 *   綁機台或量具 → A4 直式；**料號相關 → A3 橫式**（左邊要放圖面，直式塞不下）。
 * 管理員可以逐版面覆寫（設定頁），逐份文件也可以自己指定（ss_ver.paper／orient）。
 */
function ss_paper(PDO $db, array $doc, array $ver = []): array
{
    $size = strtoupper(trim((string)($ver['paper'] ?? '')));
    $ori  = strtolower(trim((string)($ver['orient'] ?? '')));
    if (isset(ss_papers()[$size]) && isset(ss_orients()[$ori])) return ['size' => $size, 'orient' => $ori];

    $kind  = (string)($doc['kind'] ?? '');
    $scope = (string)($doc['scope'] ?? '');
    $cfg   = ss_setting_get($db, 'paper_' . $kind, null);
    if (is_array($cfg) && isset(ss_papers()[strtoupper((string)($cfg['size'] ?? ''))])
        && isset(ss_orients()[strtolower((string)($cfg['orient'] ?? ''))])) {
        // 版面層級的設定只有在「沒有更明確的規則」時才用——綁機台／量具一律 A4 直式
        if ($scope !== 'machine' && $scope !== 'tool') {
            return ['size' => strtoupper((string)$cfg['size']), 'orient' => strtolower((string)$cfg['orient'])];
        }
    }
    if ($scope === 'machine' || $scope === 'tool') return ['size' => 'A4', 'orient' => 'portrait'];
    return ['size' => 'A3', 'orient' => 'landscape'];
}

/** 紙張的可印寬度（mm）；邊界由 .sheet 自己給，這裡回的是整張紙的尺寸 */
function ss_paper_mm(array $paper): array
{
    $w = $paper['size'] === 'A3' ? 297 : 210;
    $h = $paper['size'] === 'A3' ? 420 : 297;
    return $paper['orient'] === 'landscape' ? [$h, $w] : [$w, $h];
}

/* ──────────────── 簽核人：設部門＋職稱，不設人 ──────────────── */

/**
 * 某一關的簽核人設定。存的是 部門＋職稱（可留白＝不限），外加一組「代理」。
 * **刻意不存 user_id**（使用者 2026-09-22 指定）：補歷史單據時那個人可能還沒到職，
 * 之後也可能離職；職稱才是穩定的，人是會換的。
 */
function ss_signer_cfg(PDO $db, string $kind, string $slot): array
{
    $c = ss_setting_get($db, 'signercfg_' . $kind . '_' . $slot, null);
    $out = ['dept_id' => 0, 'position_id' => 0, 'dep_dept_id' => 0, 'dep_position_id' => 0];
    if (is_array($c)) foreach ($out as $k => $_) $out[$k] = (int)($c[$k] ?? 0);
    if ($out['dept_id'] <= 0 && $out['position_id'] <= 0) {
        // 舊設定是直接存 user_id 的，沒轉成部門職稱之前仍然要能用（不然設定會憑空消失）
        $out['legacy_user_id'] = ss_default_signer($db, $kind, $slot);
    } else {
        $out['legacy_user_id'] = 0;
    }
    return $out;
}

function ss_signer_cfg_set(PDO $db, string $kind, string $slot, array $c): void
{
    ss_setting_set($db, 'signercfg_' . $kind . '_' . $slot, [
        'dept_id'         => (int)($c['dept_id'] ?? 0),
        'position_id'     => (int)($c['position_id'] ?? 0),
        'dep_dept_id'     => (int)($c['dep_dept_id'] ?? 0),
        'dep_position_id' => (int)($c['dep_position_id'] ?? 0),
    ]);
}

/**
 * 依「部門＋職稱」在某個日期找出可以簽的人。
 * 一律以**簽章日期當時**的職務回推（ai-rules/22）——用現況清單的話，補歷史單據時
 * 當時在職、現已離職的人一個都挑不到，而且完全不報錯。
 * 同時排除當天請整天假的人（與手動簽章同一條規則）。
 *
 * @return array 候選人（一個職務一列），照職級排序
 */
function ss_people_by_post(PDO $db, int $deptId, int $positionId, string $date): array
{
    if ($deptId <= 0 && $positionId <= 0) return [];
    if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $date)) $date = date('Y-m-d');
    require_once __DIR__ . '/people_lib.php';
    // 以「那一天」的職務回推（ai-rules/22）：當時在職、現已離職的人也要找得到，
    // 不然補歷史單據時自動簽核會一個人都解析不到，而且完全不報錯。
    $rows = eg_people_list_asof($db, ['all_posts' => true], $date);
    $out = [];
    foreach ($rows as $r) {
        if ($positionId > 0 && (int)($r['position_id'] ?? 0) !== $positionId) continue;
        if ($deptId > 0) {
            $ids = is_array($r['dept_ids'] ?? null) ? array_map('intval', $r['dept_ids']) : [];
            if ((int)($r['dept_id'] ?? 0) !== $deptId && !in_array($deptId, $ids, true)) continue;
        }
        $out[] = $r;
    }
    if (!$out) return [];
    $block = ss_leave_allday_map($db, array_map(fn($r) => (int)$r['id'], $out), $date);
    $ok = [];
    foreach ($out as $r) if (empty($block[(int)$r['id']])) $ok[] = $r;
    return $ok ?: $out;      // 全部都請假時仍回原名單，讓呼叫端自己決定要不要擋
}

/**
 * 部門清單，**依組織樹的順序**（董事長室→總經理室→各課→各組），每一列帶 depth 供縮排顯示。
 * 不做 level 過濾——總經理與董事長就掛在最上面那兩個單位，過濾掉就選不到（使用者 2026-09-22 回報）。
 * **前端一定要照這個順序輸出**：把它轉成「id 當鍵」的物件會被 JS 依數字大小重排，
 * 下拉就會變成依 department.id 排序（技術課 1、品管課 2…），完全看不出是哪裡排錯的。
 */
function ss_dept_tree_rows(PDO $db): array
{
    try {
        $rows = $db->query("SELECT id, name, level, parent_id, sort_order FROM department
                            ORDER BY COALESCE(sort_order, 9999), id")->fetchAll(PDO::FETCH_ASSOC);
    } catch (Throwable $e) { return []; }
    $byParent = [];
    foreach ($rows as $r) $byParent[(int)($r['parent_id'] ?? 0)][] = $r;
    $out = [];
    $walk = function ($pid, $depth) use (&$walk, &$out, $byParent) {
        foreach ($byParent[$pid] ?? [] as $r) {
            $r['depth'] = $depth;
            $out[] = $r;
            $walk((int)$r['id'], $depth + 1);
        }
    };
    $walk(0, 0);
    // 掛在不存在的上層底下的孤兒也要列出來，不然那個部門會整個選不到
    if (count($out) < count($rows)) {
        $seen = [];
        foreach ($out as $r) $seen[(int)$r['id']] = true;
        foreach ($rows as $r) if (empty($seen[(int)$r['id']])) { $r['depth'] = 0; $out[] = $r; }
    }
    return $out;
}

/**
 * 每個部門底下**實際登記了哪些職稱**（department_position），職稱依 position.sort_order 排。
 * 用來把「職稱」下拉收斂成「這個部門裡真的存在的職稱」（使用者 2026-09-22 要求）——
 * 一次列出全公司 18 個職稱，挑到部門裡根本沒有的那個，解析當然一個人都找不到。
 */
function ss_dept_position_map(PDO $db): array
{
    try {
        $rows = $db->query("SELECT dp.department_id, dp.position_id
                            FROM department_position dp
                            JOIN position p ON p.id = dp.position_id
                            ORDER BY COALESCE(p.sort_order, 999), p.id")->fetchAll(PDO::FETCH_ASSOC);
    } catch (Throwable $e) { return []; }
    $map = [];
    foreach ($rows as $r) {
        $d = (int)$r['department_id']; $p = (int)$r['position_id'];
        if ($d <= 0 || $p <= 0) continue;
        if (!in_array($p, $map[$d] ?? [], true)) $map[$d][] = $p;
    }
    return $map;
}

/**
 * 自動簽核要蓋誰的章：先用正選（部門＋職稱），找不到人才用代理。
 * 兩個都找不到就回 0——**不亂猜人**，那一關停著等人工簽，比蓋錯人好。
 *
 * @return array [user_id, why] why 講清楚是正選還是代理、或為什麼找不到
 */
function ss_resolve_signer(PDO $db, string $kind, string $slot, string $date): array
{
    $c = ss_signer_cfg($db, $kind, $slot);
    $rows = ss_people_by_post($db, (int)$c['dept_id'], (int)$c['position_id'], $date);
    if ($rows) return [(int)$rows[0]['id'], '正選'];

    $rows = ss_people_by_post($db, (int)$c['dep_dept_id'], (int)$c['dep_position_id'], $date);
    if ($rows) return [(int)$rows[0]['id'], '代理'];

    // 還沒改成部門職稱的舊設定
    if ((int)($c['legacy_user_id'] ?? 0) > 0) return [(int)$c['legacy_user_id'], '舊設定（指定人員）'];
    return [0, '這個日期找不到符合「部門＋職稱」的在職人員'];
}

/* ─────────── 取消送簽：整版退回草稿，沒有「只取消某一格」 ─────────── */

/**
 * 退回草稿（清掉**全部**簽章，人工蓋的也一起清）。呼叫端負責權限（管理員限定）。
 * 2026-09-22 刪掉了原本的 ss_all_auto_signed() 前置判定：那條規則會在管理員清掉其中一格之後
 * 不成立，於是退回鈕跟著消失、版次卡在「簽核中、零個章」再也救不回來（使用者實際踩到）。
 */
function ss_unsubmit(PDO $db, int $verId, int $uid): void
{
    $v = ss_ver_get($db, $verId);
    if (!$v) throw new RuntimeException('找不到這個版次');
    if ((string)$v['status'] === 'obsolete') throw new RuntimeException('已作廢的版次不可退回');
    $db->prepare("DELETE FROM ss_sign WHERE ver_id=?")->execute([$verId]);
    $db->prepare("UPDATE ss_ver SET status='draft', modified_at=NOW(), modified_by=? WHERE ver_id=?")
       ->execute([$uid, $verId]);
    ss_refresh_cur_ver($db, (int)$v['doc_id']);
}
