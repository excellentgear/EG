<?php
/**
 * 溝通管理（3-GM-01）共用庫 —— 2026-09-14 建立
 * ------------------------------------------------------------------
 * 一頁三分頁，對應 3-GM-01 溝通管理辦法底下的三份表單：
 *   3-GM-01-01 利害關係者溝通記錄表     單據型，一次溝通一張；**唯一需要簽章的表單**
 *   3-GM-01-02 回應利害關係者措施追蹤表 清單型，只盯「還沒做完的事」
 *   3-GM-01-03 溝通管制表               清單型，常態性溝通機制的「遊戲規則」
 *
 * 三份表單的關係（使用者提供的漏斗說明，程式設計依此）：
 *   管制表＝計畫好要跟誰溝通、多久一次（只寫常態機制，不寫單一事件）
 *   記錄表＝事件發生當下的紀錄；當下就解決的到此為止，需要後續行動的才往下走
 *   追蹤表＝只列「有預計完成日、要指派負責人」的項目，結案後下次審查即可移出
 * 所以記錄表的每一列溝通問題都能一鍵「轉入追蹤表」，轉過的留下對照（comm_record_item.track_id）。
 *
 * 使用者拍板（2026-09-14 AskUserQuestion）：
 *   ①部門主管確認：同部門找不到職位編號更小的主管時，**沿部門樹往上層部門找**，
 *     一路找到最上層都沒有才免簽（不是同部門找不到就直接免簽）
 *   ②簽核序列：填表人送出 → 部門主管確認 → 總經理確認 → 結案（免簽時直接跳到總經理）
 *   ③一人身兼多部門職稱時，**建單時自己選填表身分**（預設主要職務 is_main=1）
 *   ④記錄表支援上傳佐證附件
 *
 * 主管解析規則（使用者指定，依 views/ADM/department_job_title_settings.php 的兩個欄位）：
 *   `position.sort_order`  ＝職位編號，**數字愈小職位愈高**
 *   `position_level.level` ＝職稱階級（0=最高決策者、1/2/3=一~三階主管、NULL=非主管）
 *   候選＝「階級不低於管理員設定的門檻」且「職位編號小於填表人」的在職者；
 *   同部門優先，找不到才往上層部門找。門檻設 3（三階主管以上）＝ level 0/1/2/3 都可簽。
 *
 * 相關規則：ai-rules/16(列印)、18(圖章)、19(核准人解析)、20(日期顯示)、22(當時職務)、23(列印與簽核紀錄)
 */

require_once __DIR__ . '/org_role_lib.php';
require_once __DIR__ . '/approval_lib.php';
require_once __DIR__ . '/delegate_lib.php';
require_once __DIR__ . '/asdoc_lib.php';
require_once __DIR__ . '/position_history_lib.php';
require_once __DIR__ . '/date_fmt_lib.php';
require_once __DIR__ . '/attach_lib.php';
require_once __DIR__ . '/unit_supervisor_lib.php';   // 只借用「往上追溯的課級天花板」常數，見 cm_dept_manager_pool()

/** AS 文件綁定模組代碼（asdoc_lib）——一份表單一個代碼，設定值只存 as_document.id */
const CM_ASDOC_MODULES = [
    'record' => ['module' => 'cm_record', 'label' => '利害關係者溝通記錄表',     'fallback' => '3-GM-01-01'],
    'track'  => ['module' => 'cm_track',  'label' => '回應利害關係者措施追蹤表', 'fallback' => '3-GM-01-02'],
    'ctrl'   => ['module' => 'cm_ctrl',   'label' => '溝通管制表',               'fallback' => '3-GM-01-03'],
];

/** 型態（紙本：□定期 □不定期） */
const CM_TYPES = ['regular' => '定期', 'irregular' => '不定期'];

/** 類別（紙本：□客戶 □供應商 □員工 □其他：＿＿） */
const CM_PARTY_KINDS = ['customer' => '客戶', 'supplier' => '供應商', 'employee' => '員工', 'other' => '其他'];

/** 管道（紙本：□電話 □面談 □email □會議 □其他:＿＿）；可複選 */
const CM_CHANNELS = ['phone' => '電話', 'face' => '面談', 'email' => 'email', 'meeting' => '會議', 'other' => '其他'];

/** 溝通管制表的頻率單位（畫面下拉與顯示字串共用同一份，不在別處再寫一次＝鐵律4） */
const CM_FREQ_UNITS = ['day' => '天', 'week' => '週', 'month' => '月', 'halfyear' => '半年', 'year' => '年'];

/** 單據狀態機（送出後依序跑兩關，見檔頭拍板②） */
const CM_STATUS = [
    'draft'    => '草稿',
    'mgr_wait' => '待部門主管確認',
    'gm_wait'  => '待總經理確認',
    'closed'   => '已完成',
];

/** 簽核關卡代碼（approval_record.level） */
const CM_LEVEL_MGR = 'dept_mgr';
const CM_LEVEL_GM  = 'gm';

const CM_SETTING_GROUP = 'COMM_MGMT';

/**
 * 模組設定鍵 => 預設值。
 * 【重要，沿用內部稽核踩過的坑】`system_parameters.param_value` 是 **JSON NOT NULL** 欄位，
 * 直接塞 `auto` 這種裸字串 MySQL 會回 3140 把寫入整筆擋下、而 `3` 剛好是合法 JSON 存得進去，
 * 於是出現「有些設定存得起來、有些按了說成功卻是空的」這種極難查的症狀。
 * 所以寫入一律 json_encode，讀取一律過 cm_setting_decode()。
 */
const CM_SETTINGS_DEFAULT = [
    'cm_need_sign'    => '1',     // 是否需要簽核；0＝送出當下自動簽核完成（兩格都由系統蓋章並直接結案）
    'cm_stamp_tpl_id' => '',      // 簽章圖章模板（stamp_template.id；空＝用系統預設回墨印）
    'cm_mgr_rank_max' => '3',     // 部門主管確認：階級門檻（三階主管以上都可簽）
    'cm_mgr_source'   => 'auto',  // auto=依填表人部門自動解析／users=固定指定人員
    'cm_mgr_users'    => '[]',    // cm_mgr_source=users 時的人員 id 清單（JSON）
    'cm_gm_source'    => 'top',   // top=組織角色綁定的最高核准人員／users=指定人員／rank=指定階級以上
    'cm_gm_users'     => '[]',
    'cm_gm_rank_max'  => '0',     // cm_gm_source=rank 時的階級門檻（0＝最高決策者）
];

if (!function_exists('cm_ensure_schema')) {

/* ============================ 建表 ============================ */

function cm_ensure_schema(PDO $db): void
{
    static $done = false;
    if ($done) return;
    $done = true;
    try {
        /* ---- 3-GM-01-01 利害關係者溝通記錄表 ---- */
        $db->exec("CREATE TABLE IF NOT EXISTS comm_record (
            rec_id           INT AUTO_INCREMENT PRIMARY KEY,
            rec_no           VARCHAR(20) NULL COMMENT '單號＝溝通日期YYYYMMDD+3位流水（依表單上的日期產生，不是建檔日）',
            comm_type        VARCHAR(12) NOT NULL DEFAULT 'irregular' COMMENT 'regular=定期／irregular=不定期',
            comm_date        DATE NULL COMMENT '溝通日期＝本單業務日期（AS版次回推、圖章日期都用它）',
            party_kind       VARCHAR(12) NULL COMMENT 'customer/supplier/employee/other',
            party_kind_other VARCHAR(100) NULL COMMENT '類別選「其他」時的說明',
            party_name       VARCHAR(200) NULL COMMENT '利害關係者公司/代表人',
            ch_phone         TINYINT NOT NULL DEFAULT 0,
            ch_face          TINYINT NOT NULL DEFAULT 0,
            ch_email         TINYINT NOT NULL DEFAULT 0,
            ch_meeting       TINYINT NOT NULL DEFAULT 0,
            ch_other         TINYINT NOT NULL DEFAULT 0,
            ch_other_text    VARCHAR(100) NULL,
            maker_id         INT NULL, maker_name VARCHAR(60) NULL,
            maker_dept_id    INT NULL, maker_dept_name VARCHAR(100) NULL,
            maker_pos_id     INT NULL, maker_pos_name  VARCHAR(60) NULL,
            maker_pos_sort   INT NULL COMMENT '填表當下的職位編號快照（主管解析與日後查核用）',
            status           VARCHAR(12) NOT NULL DEFAULT 'draft',
            submit_date      DATE NULL COMMENT '送出日（業務日期）', submitted_at DATETIME NULL,
            mgr_skip         TINYINT NOT NULL DEFAULT 0 COMMENT '1=往上找到最上層都沒有合格主管，本格免簽',
            mgr_user_id      INT NULL, mgr_name VARCHAR(60) NULL,
            mgr_dept_name    VARCHAR(100) NULL, mgr_pos_name VARCHAR(60) NULL,
            mgr_date         DATE NULL, mgr_at DATETIME NULL, mgr_note VARCHAR(500) NULL,
            mgr_deputy       TINYINT NOT NULL DEFAULT 0 COMMENT '1=代理人代簽，圖章右下角加「代」',
            mgr_for_name     VARCHAR(60) NULL COMMENT '代簽時被代理的主管姓名',
            gm_user_id       INT NULL, gm_name VARCHAR(60) NULL,
            gm_date          DATE NULL, gm_at DATETIME NULL, gm_note VARCHAR(500) NULL,
            gm_deputy        TINYINT NOT NULL DEFAULT 0, gm_for_name VARCHAR(60) NULL,
            reject_by        VARCHAR(60) NULL, reject_at DATETIME NULL, reject_note VARCHAR(500) NULL,
            remark           VARCHAR(500) NULL,
            created_by       INT NULL, created_by_name VARCHAR(60) NULL,
            created_at       DATETIME NULL, updated_at DATETIME NULL,
            is_deleted       TINYINT NOT NULL DEFAULT 0,
            KEY idx_date (comm_date), KEY idx_status (status), KEY idx_maker (maker_id), KEY idx_no (rec_no)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COMMENT='利害關係者溝通記錄表 3-GM-01-01'");

        $db->exec("CREATE TABLE IF NOT EXISTS comm_record_item (
            item_id  INT AUTO_INCREMENT PRIMARY KEY,
            rec_id   INT NOT NULL,
            seq      INT NOT NULL DEFAULT 1 COMMENT '紙本的 1~5 項次，可依實際需求增列',
            question TEXT NULL COMMENT '溝通問題',
            reply    TEXT NULL COMMENT '回覆內容',
            track_id INT NULL COMMENT '已轉入措施追蹤表的 comm_track.track_id（NULL＝當下解決、不需追蹤）',
            KEY idx_rec (rec_id), KEY idx_track (track_id)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COMMENT='溝通記錄表的溝通問題/回覆內容明細'");

        $db->exec("CREATE TABLE IF NOT EXISTS comm_record_attach (
            att_id      INT AUTO_INCREMENT PRIMARY KEY,
            rec_id      INT NULL COMMENT '新增中尚未存檔時為 NULL，靠 temp_key 認領（鐵律5 的 temp/active 暫存機制）',
            temp_key    VARCHAR(40) NULL,
            file_name   VARCHAR(200) NOT NULL COMMENT '實際存在 NAS 的檔名；**只存檔名，完整路徑讀取當下現場組**',
            orig_name   VARCHAR(200) NULL,
            file_size   INT NULL, mime VARCHAR(100) NULL,
            note        VARCHAR(200) NULL,
            status      VARCHAR(10) NOT NULL DEFAULT 'temp' COMMENT 'temp=尚未隨單據存檔／active=正式',
            uploaded_by INT NULL, uploaded_by_name VARCHAR(60) NULL, uploaded_at DATETIME NULL,
            KEY idx_rec (rec_id), KEY idx_temp (temp_key), KEY idx_status (status)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COMMENT='溝通記錄表佐證附件'");

        /* ---- 3-GM-01-02 回應利害關係者措施追蹤表 ---- */
        $db->exec("CREATE TABLE IF NOT EXISTS comm_track (
            track_id    INT AUTO_INCREMENT PRIMARY KEY,
            party       VARCHAR(200) NULL COMMENT '利害關係者',
            content     TEXT NULL COMMENT '反應內容',
            react_date  DATE NULL COMMENT '反應日期',
            action      TEXT NULL COMMENT '回應措施',
            owner_id    INT NULL, owner_name VARCHAR(60) NULL COMMENT '負責人',
            due_date    DATE NULL COMMENT '預計完成日',
            is_closed   TINYINT NOT NULL DEFAULT 0 COMMENT '是否結案',
            closed_date DATE NULL, close_note VARCHAR(500) NULL,
            src_rec_id  INT NULL COMMENT '由哪張溝通記錄表轉入', src_item_id INT NULL,
            sort_order  INT NOT NULL DEFAULT 0,
            created_by  INT NULL, created_by_name VARCHAR(60) NULL,
            created_at  DATETIME NULL, updated_at DATETIME NULL,
            is_deleted  TINYINT NOT NULL DEFAULT 0,
            KEY idx_closed (is_closed), KEY idx_due (due_date), KEY idx_src (src_rec_id)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COMMENT='回應利害關係者措施追蹤表 3-GM-01-02'");

        /* ---- 3-GM-01-03 溝通管制表 ---- */
        $db->exec("CREATE TABLE IF NOT EXISTS comm_ctrl (
            ctrl_id    INT AUTO_INCREMENT PRIMARY KEY,
            maker_id   INT NULL, maker_name VARCHAR(60) NULL COMMENT '填表人',
            party      VARCHAR(200) NULL COMMENT '利害關係人',
            content    TEXT NULL COMMENT '溝通內容',
            channel    VARCHAR(200) NULL COMMENT '溝通管道',
            freq       VARCHAR(100) NULL COMMENT '頻率（紙本範例：半年/次）',
            remark     VARCHAR(500) NULL,
            sort_order INT NOT NULL DEFAULT 0,
            created_by INT NULL, created_by_name VARCHAR(60) NULL,
            created_at DATETIME NULL, updated_at DATETIME NULL,
            is_deleted TINYINT NOT NULL DEFAULT 0
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COMMENT='溝通管制表 3-GM-01-03'");

        /* ---- 管制項目的提醒對象（人員或部門，可複數） ---- */
        $db->exec("CREATE TABLE IF NOT EXISTS comm_ctrl_remind_target (
            id          INT AUTO_INCREMENT PRIMARY KEY,
            ctrl_id     INT NOT NULL,
            target_type VARCHAR(10) NOT NULL COMMENT 'user=指定人員／dept=整個部門（含子部門）',
            target_id   INT NOT NULL,
            UNIQUE KEY uk_t (ctrl_id, target_type, target_id), KEY idx_c (ctrl_id)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COMMENT='溝通管制表的提醒對象'");
    } catch (Throwable $e) {}

    /* ---- 後續追加的欄位（既有資料不可重建，一律 ALTER；重複執行時各自 try/catch 吃掉） ---- */
    $alters = [
        // 記錄表：利害關係者改成「先選類別再依類別連動挑對象」，除了顯示字串還要存得住是挑到哪一筆
        "ALTER TABLE comm_record ADD COLUMN party_ref_id VARCHAR(40) NULL COMMENT '客戶=customer_list.customer_id／供應商=maker_list.maker_id_no／員工=department.id' AFTER party_name",
        "ALTER TABLE comm_record ADD COLUMN party_user_id INT NULL COMMENT '類別=員工時的 user.id' AFTER party_ref_id",
        "ALTER TABLE comm_record ADD COLUMN party_contact_id INT NULL COMMENT '客戶/供應商聯絡人 contact_id（手動輸入時為 NULL）' AFTER party_user_id",
        "ALTER TABLE comm_record ADD COLUMN party_contact_name VARCHAR(100) NULL COMMENT '代表人（顯示用；可由聯絡人挑或手動填）' AFTER party_contact_id",
        // 管制表：對象比照記錄表、頻率結構化、提醒
        "ALTER TABLE comm_ctrl ADD COLUMN party_kind VARCHAR(12) NULL COMMENT 'customer/supplier/employee/other' AFTER maker_name",
        "ALTER TABLE comm_ctrl ADD COLUMN party_kind_other VARCHAR(100) NULL AFTER party_kind",
        "ALTER TABLE comm_ctrl ADD COLUMN party_ref_id VARCHAR(40) NULL AFTER party",
        "ALTER TABLE comm_ctrl ADD COLUMN party_user_id INT NULL AFTER party_ref_id",
        "ALTER TABLE comm_ctrl ADD COLUMN maker_dept_id INT NULL AFTER maker_name",
        "ALTER TABLE comm_ctrl ADD COLUMN maker_dept_name VARCHAR(100) NULL AFTER maker_dept_id",
        "ALTER TABLE comm_ctrl ADD COLUMN maker_pos_name VARCHAR(60) NULL AFTER maker_dept_name",
        "ALTER TABLE comm_ctrl ADD COLUMN freq_n INT NOT NULL DEFAULT 1 COMMENT '每 N 個單位' AFTER freq",
        "ALTER TABLE comm_ctrl ADD COLUMN freq_unit VARCHAR(10) NOT NULL DEFAULT 'month' COMMENT 'day/week/month/halfyear/year' AFTER freq_n",
        "ALTER TABLE comm_ctrl ADD COLUMN freq_times INT NOT NULL DEFAULT 1 COMMENT '幾次' AFTER freq_unit",
        "ALTER TABLE comm_ctrl ADD COLUMN next_due_date DATE NULL COMMENT '下次應溝通日（提醒依它推算；轉出溝通記錄時可自動往後推一個週期）' AFTER freq_times",
        "ALTER TABLE comm_ctrl ADD COLUMN remind_enabled TINYINT NOT NULL DEFAULT 0 AFTER next_due_date",
        "ALTER TABLE comm_ctrl ADD COLUMN remind_lead_days INT NOT NULL DEFAULT 0 COMMENT '提前幾天提醒（0＝當天）' AFTER remind_enabled",
        "ALTER TABLE comm_ctrl ADD COLUMN remind_time TIME NULL COMMENT '當天幾點提醒' AFTER remind_lead_days",
        "ALTER TABLE comm_ctrl ADD COLUMN remind_sent_for DATE NULL COMMENT '已針對哪一個 next_due_date 發過提醒（避免同一期重複發）' AFTER remind_time",
        "ALTER TABLE comm_ctrl ADD COLUMN remind_last_at DATETIME NULL AFTER remind_sent_for",
    ];
    foreach ($alters as $sql) { try { $db->exec($sql); } catch (Throwable $e) {} }
}

/* ============================ 使用者與權限 ============================ */

function cm_current_user(PDO $db): ?array
{
    $uname = $_SESSION['userName'] ?? '';
    if ($uname === '') return null;
    $st = $db->prepare("SELECT id, user_cname, user_uname, user_status, state FROM `user` WHERE user_uname=?");
    $st->execute([$uname]);
    return $st->fetch(PDO::FETCH_ASSOC) ?: null;
}

function cm_has_role(PDO $db, int $uid, array $codes): bool
{
    if (!$codes) return false;
    $in = implode(',', array_fill(0, count($codes), '?'));
    try {
        $st = $db->prepare("SELECT 1 FROM user_roles ur JOIN roles r ON r.role_id=ur.role_id
                            WHERE ur.user_id=? AND r.module='comm_mgmt' AND r.role_code IN ($in) LIMIT 1");
        $st->execute(array_merge([$uid], $codes));
        return (bool)$st->fetchColumn();
    } catch (Throwable $e) { return false; }
}

/**
 * 權限：
 *   isAdmin  系統管理者（固定全權）
 *   canAdmin 溝通管理員：模組設定、管制表/追蹤表維護、刪除、代其他人建單、看全部
 *   canView  檢閱：看得到全部溝通記錄（唯讀）
 *   canUse   全體在職員工：建立/編輯自己的溝通記錄、確認指派給自己的單
 * 刻意讓全體員工都能建單——「誰溝通誰記錄」是這份表單的本質，卡角色會讓現場根本沒人填。
 */
function cm_perms(PDO $db, ?array $u): array
{
    $none = ['isAdmin'=>false,'canAdmin'=>false,'canView'=>false,'canUse'=>false,'uid'=>0,'name'=>''];
    if (!$u) return $none;
    $uid = (int)$u['id'];
    if ((int)($u['state'] ?? 0) === 0 || (int)($u['user_status'] ?? 0) === 90) return $none;  // 離職/特殊帳號 fail-closed

    $isAdmin = false;
    try {
        $st = $db->prepare("SELECT 1 FROM user_roles ur JOIN roles r ON r.role_id=ur.role_id
                            WHERE ur.user_id=? AND r.role_code IN ('admin','superadmin') LIMIT 1");
        $st->execute([$uid]);
        $isAdmin = (bool)$st->fetchColumn();
    } catch (Throwable $e) {}
    if (!$isAdmin && $uid === 1) $isAdmin = true;

    $canAdmin = $isAdmin || cm_has_role($db, $uid, ['cm_admin']);
    $canView  = $canAdmin || cm_has_role($db, $uid, ['cm_view']);
    return ['isAdmin'=>$isAdmin, 'canAdmin'=>$canAdmin, 'canView'=>$canView, 'canUse'=>true,
            'uid'=>$uid, 'name'=>(string)$u['user_cname']];
}

function cm_role_label(array $p): string
{
    if ($p['isAdmin'])  return '管理者';
    if ($p['canAdmin']) return '溝通管理員';
    if ($p['canView'])  return '溝通紀錄檢閱';
    return '一般員工（可建立與確認自己的溝通記錄）';
}

/* ============================ 模組設定 ============================ */

function cm_setting_decode($raw): string
{
    if ($raw === null) return '';
    $s = (string)$raw;
    $d = json_decode($s, true);
    if ($d === null && strtolower(trim($s)) !== 'null') return $s;   // 不是合法 JSON＝舊的裸值，原樣回
    if (is_bool($d)) return $d ? '1' : '';
    if (is_scalar($d)) return (string)$d;
    return $s;                                                        // 陣列/物件交給呼叫端 decode
}

function cm_settings(PDO $db): array
{
    $out = CM_SETTINGS_DEFAULT;
    try {
        $st = $db->prepare("SELECT param_key, param_value FROM system_parameters WHERE param_group=?");
        $st->execute([CM_SETTING_GROUP]);
        foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $r) {
            if (array_key_exists($r['param_key'], $out)) $out[$r['param_key']] = cm_setting_decode($r['param_value']);
        }
    } catch (Throwable $e) {}
    return $out;
}

function cm_setting_save(PDO $db, string $key, string $val, string $by = ''): void
{
    if (!array_key_exists($key, CM_SETTINGS_DEFAULT)) return;
    try {
        $st = $db->prepare("SELECT id FROM system_parameters WHERE param_group=? AND param_key=? LIMIT 1");
        $st->execute([CM_SETTING_GROUP, $key]);
        $rid  = $st->fetchColumn();
        $json = json_encode($val, JSON_UNESCAPED_UNICODE);            // 見 CM_SETTINGS_DEFAULT 上方說明
        if ($rid) {
            $db->prepare("UPDATE system_parameters SET param_value=?, updated_by=? WHERE id=?")->execute([$json, $by, $rid]);
        } else {
            $db->prepare("INSERT INTO system_parameters (param_group, param_key, param_value, description, updated_by)
                          VALUES (?,?,?,?,?)")
               ->execute([CM_SETTING_GROUP, $key, $json, '溝通管理：' . $key, $by]);
        }
    } catch (Throwable $e) {}
}

/** 設定的人員清單（JSON 陣列字串 → int[]） */
function cm_setting_uids(array $settings, string $key): array
{
    $a = json_decode((string)($settings[$key] ?? '[]'), true);
    return is_array($a) ? array_values(array_unique(array_filter(array_map('intval', $a)))) : [];
}

/** 簽章圖章模板（id=0 或查無回 null，呼叫端拿不到就退回 EGStamp 預設回墨印） */
function cm_stamp_template(PDO $db): ?array
{
    $id = (int)(cm_settings($db)['cm_stamp_tpl_id'] ?? 0);
    if ($id <= 0) return null;
    try {
        $st = $db->prepare("SELECT id, tpl_name, schema_json FROM stamp_template WHERE id=? AND is_active=1");
        $st->execute([$id]);
        $r = $st->fetch(PDO::FETCH_ASSOC);
        if (!$r) return null;
        return ['id'=>(int)$r['id'], 'name'=>(string)$r['tpl_name'], 'schema'=>json_decode((string)$r['schema_json'], true)];
    } catch (Throwable $e) { return null; }
}

/** 可選的圖章模板清單（設定跳窗用） */
function cm_stamp_template_list(PDO $db): array
{
    try {
        return $db->query("SELECT p.id, p.tpl_name, t.type_name
                           FROM stamp_template p LEFT JOIN stamp_type t ON t.id=p.type_id
                           WHERE p.is_active=1 ORDER BY t.type_name, p.tpl_name")->fetchAll(PDO::FETCH_ASSOC);
    } catch (Throwable $e) { return []; }
}

/** 職稱階級清單（設定跳窗的「哪些層級以上才可簽章」下拉；不寫死一/二/三階＝鐵律4） */
function cm_rank_list(PDO $db): array
{
    try {
        return $db->query("SELECT id, name, rank_order FROM position_rank WHERE rank_order < 90 ORDER BY rank_order")
                  ->fetchAll(PDO::FETCH_ASSOC);
    } catch (Throwable $e) { return []; }
}

/* ============================ 填表身分（兼任者自己選） ============================ */

/**
 * 某人可選的填表身分（部門/職稱），主職排最前。
 * $bizDate 有給就依該日期回推當時職務（ai-rules/22）；沒給＝現況。
 * 一併帶出 pos_sort（職位編號）與 level（階級），前端顯示與後端主管解析共用同一份。
 */
function cm_identities(PDO $db, int $uid, ?string $bizDate = null): array
{
    if ($uid <= 0) return [];
    $snap = ($bizDate && $bizDate < date('Y-m-d'))
        ? eg_position_snapshot_at($db, $uid, $bizDate)
        : eg_position_snapshot_now($db, $uid);
    if (!$snap) return [];
    $out = [];
    foreach ($snap as $s) {
        $deptId = (int)($s['department_id'] ?? 0);
        $posId  = (int)($s['position_id'] ?? 0);
        if (!$deptId && !$posId) continue;
        $meta = cm_position_meta($db, $posId);
        $out[] = [
            'department_id'   => $deptId,
            'department_name' => ((string)($s['department_name'] ?? '')) ?: cm_dept_name($db, $deptId),
            'position_id'     => $posId,
            'position_name'   => ((string)($s['position_name'] ?? '')) ?: (string)$meta['name'],
            'pos_sort'        => (int)$meta['sort_order'],
            'level'           => $meta['level'],
            'is_main'         => (int)($s['is_main'] ?? 0),
        ];
    }
    return $out;
}

/** 職稱的編號與階級（現況表；職稱屬性不隨人員異動回推，故不做 as-of） */
function cm_position_meta(PDO $db, int $posId): array
{
    static $cache = [];
    if ($posId <= 0) return ['name'=>'', 'sort_order'=>9999, 'level'=>null];
    if (isset($cache[$posId])) return $cache[$posId];
    try {
        $st = $db->prepare("SELECT p.name, p.sort_order, pl.level FROM position p
                            LEFT JOIN position_level pl ON pl.position_id=p.id WHERE p.id=?");
        $st->execute([$posId]);
        $r = $st->fetch(PDO::FETCH_ASSOC);
    } catch (Throwable $e) { $r = null; }
    return $cache[$posId] = $r
        ? ['name'=>(string)$r['name'], 'sort_order'=>(int)$r['sort_order'],
           'level'=>($r['level'] === null ? null : (int)$r['level'])]
        : ['name'=>'', 'sort_order'=>9999, 'level'=>null];
}

function cm_dept_name(PDO $db, int $deptId): string
{
    static $cache = [];
    if ($deptId <= 0) return '';
    if (isset($cache[$deptId])) return $cache[$deptId];
    try {
        $st = $db->prepare("SELECT name FROM department WHERE id=?");
        $st->execute([$deptId]);
        return $cache[$deptId] = (string)$st->fetchColumn();
    } catch (Throwable $e) { return ''; }
}

/** 部門層級（department.level：1=董事長室 2=總經理室 3=部門/課室 4=組別 5=小組） */
function cm_dept_level(PDO $db, int $deptId): int
{
    static $cache = [];
    if ($deptId <= 0) return 0;
    if (isset($cache[$deptId])) return $cache[$deptId];
    try {
        $st = $db->prepare("SELECT level FROM department WHERE id=?");
        $st->execute([$deptId]);
        return $cache[$deptId] = (int)$st->fetchColumn();
    } catch (Throwable $e) { return 0; }
}

function cm_dept_parent(PDO $db, int $deptId): int
{
    static $cache = [];
    if ($deptId <= 0) return 0;
    if (isset($cache[$deptId])) return $cache[$deptId];
    try {
        $st = $db->prepare("SELECT parent_id FROM department WHERE id=?");
        $st->execute([$deptId]);
        return $cache[$deptId] = (int)$st->fetchColumn();
    } catch (Throwable $e) { return 0; }
}

/* ============================ 簽核候選人解析 ============================ */

/**
 * 解析「部門主管確認」的合格簽核池（使用者指定的規則，見檔頭）。
 *
 * @param int    $makerUid 填表人 user.id（一律排除本人，不能自己簽自己）
 * @param int    $deptId   填表身分的部門
 * @param int    $posSort  填表身分的職位編號（愈小愈高）
 * @param string $bizDate  業務日期＝溝通日期（依此回推當時職務與在職狀態，ai-rules/22）
 * @return array ['pool'=>[...], 'skip'=>bool, 'rank_max'=>int]
 *
 * skip=true＝往上找到最上層都沒有合格主管，該格免簽（紙本「若由主管填寫則此格免簽」）。
 * 每位候選帶 scope：'same'＝同部門找到／'up'＝往上層部門找到／'fixed'＝管理員指定名單。
 * 畫面上要講清楚是哪一種，否則使用者會以為系統抓錯人。
 */
function cm_dept_manager_pool(PDO $db, int $makerUid, int $deptId, int $posSort, string $bizDate): array
{
    $set     = cm_settings($db);
    $rankMax = (int)($set['cm_mgr_rank_max'] ?? 3);

    // 管理員指定固定人員時，直接用那份名單（不做部門/編號判定）
    if (($set['cm_mgr_source'] ?? 'auto') === 'users') {
        $pool = cm_users_by_ids($db, cm_setting_uids($set, 'cm_mgr_users'), $bizDate, $makerUid);
        foreach ($pool as $i => $p) $pool[$i]['scope'] = 'fixed';
        return ['pool'=>$pool, 'skip'=>empty($pool), 'rank_max'=>$rankMax];
    }

    $people = cm_people_asof($db, $bizDate);          // 全員（含兼任）在該日期的部門×職稱
    $seen   = [];
    $pool   = [];

    // 第一輪：同部門、職位編號小於填表人
    foreach ($people as $p) {
        if ($p['uid'] === $makerUid) continue;
        if ($p['dept_id'] !== $deptId) continue;
        if ($p['level'] === null || $p['level'] > $rankMax) continue;
        if ($p['pos_sort'] >= $posSort) continue;
        if (isset($seen[$p['uid']])) continue;
        $seen[$p['uid']] = 1;
        $p['scope'] = 'same';
        $pool[] = $p;
    }

    // 第二輪（使用者拍板①）：同部門找不到 → 沿 department.parent_id 一路往上層部門找，
    // 每一層都要求「職位編號小於填表人」＋「階級不低於門檻」，找到就停在那一層。
    //
    // 【天花板＝課級】ai-rules/24 的全站規則：課級以上（總經理室／董事長室）是所有單位的共同上級、
    // 不是誰的單位主管，所以往上只追到 department.level = EG_UNIT_SUP_TOP_LEVEL（3＝部門/課室）為止。
    // 不設這個上限的話，課級最高主管（例：品管課課長）開的單會往上抓到總經理，而總經理本來就要簽
    // 「總經理確認」那一格——同一個人蓋兩格章，紙本上不會這樣簽。超過上限即免簽、直接送總經理確認。
    //
    // 【為什麼不直接呼叫 eg_unit_supervisor()】那支回的是「該單位的最高主管」單一人選；
    // 本表單的規則是使用者另外指定的：候選＝「職位編號小於填表人」且「階級不低於管理員設定門檻」的
    // **全部**人（任一人簽即可，門檻可在模組設定調整）。兩者語意不同，故本模組自行解析，
    // 但**天花板常數共用同一個**，避免兩邊各寫一個 3 之後走鐘。
    if (!$pool) {
        $up = cm_dept_parent($db, $deptId);
        $guard = 0;
        while ($up && cm_dept_level($db, $up) >= EG_UNIT_SUP_TOP_LEVEL && $guard++ < 20) {
            foreach ($people as $p) {
                if ($p['uid'] === $makerUid) continue;
                if ($p['dept_id'] !== $up) continue;
                if ($p['level'] === null || $p['level'] > $rankMax) continue;
                if ($p['pos_sort'] >= $posSort) continue;
                if (isset($seen[$p['uid']])) continue;
                $seen[$p['uid']] = 1;
                $p['scope'] = 'up';
                $pool[] = $p;
            }
            if ($pool) break;
            $up = cm_dept_parent($db, $up);
        }
    }

    usort($pool, function ($a, $b) {
        return [$a['level'], $a['pos_sort'], $a['id']] <=> [$b['level'], $b['pos_sort'], $b['id']];
    });
    return ['pool'=>$pool, 'skip'=>empty($pool), 'rank_max'=>$rankMax];
}

/** 「總經理確認」的合格簽核池（預設＝組織角色綁定的最高核准人員，使用者指定） */
function cm_gm_pool(PDO $db, string $bizDate): array
{
    $set = cm_settings($db);
    $src = $set['cm_gm_source'] ?? 'top';
    if ($src === 'users') {
        return cm_users_by_ids($db, cm_setting_uids($set, 'cm_gm_users'), $bizDate, 0);
    }
    if ($src === 'rank') {
        $rankMax = (int)($set['cm_gm_rank_max'] ?? 0);
        $pool = [];
        $seen = [];
        foreach (cm_people_asof($db, $bizDate) as $p) {
            if ($p['level'] === null || $p['level'] > $rankMax) continue;
            if (isset($seen[$p['uid']])) continue;
            $seen[$p['uid']] = 1;
            $pool[] = $p;
        }
        usort($pool, function ($a, $b) {
            return [$a['level'], $a['pos_sort'], $a['id']] <=> [$b['level'], $b['pos_sort'], $b['id']];
        });
        return $pool;
    }
    // 預設：組織角色綁定的「最高核准人員」（禁止寫死人名＝ai-rules/18 鐵則5）
    $u = eg_org_user($db, 'top_approver');
    return $u ? cm_users_by_ids($db, [(int)$u['id']], $bizDate, 0) : [];
}

/** 依 user id 清單組出簽核池格式（帶當時部門/職稱，供圖章顯示） */
function cm_users_by_ids(PDO $db, array $ids, string $bizDate, int $excludeUid = 0): array
{
    $ids = array_values(array_filter(array_map('intval', $ids), function ($v) use ($excludeUid) {
        return $v > 0 && $v !== $excludeUid;
    }));
    if (!$ids) return [];
    $out  = [];
    $seen = [];
    foreach (cm_people_asof($db, $bizDate) as $p) {
        if (!in_array($p['uid'], $ids, true) || isset($seen[$p['uid']])) continue;
        $seen[$p['uid']] = 1;
        $out[] = $p;
    }
    // 名單內但當天沒有任何職務對應（罕見）也要列出來，否則管理員指定了卻永遠簽不了
    foreach ($ids as $id) {
        if (isset($seen[$id])) continue;
        $n = cm_user_name($db, $id);
        if ($n === '') continue;
        $out[] = ['id'=>$id, 'uid'=>$id, 'name'=>$n, 'dept_id'=>0, 'dept_name'=>'', 'pos_name'=>'',
                  'pos_sort'=>9999, 'level'=>null, 'scope'=>'fixed'];
    }
    return $out;
}

function cm_user_name(PDO $db, int $uid): string
{
    try {
        $st = $db->prepare("SELECT user_cname FROM `user` WHERE id=?");
        $st->execute([$uid]);
        return (string)$st->fetchColumn();
    } catch (Throwable $e) { return ''; }
}

/**
 * 全員在某業務日期的「部門×職稱」展開清單（一人兼多職＝多列）。
 * 在職判定（ai-rules/22 第四坑）：業務日期在過去時放行「當時還在職、現在已離職」的人；
 * 業務日期是今天或未來時只列在職者。特殊帳號(user_status=90)一律排除。
 */
function cm_people_asof(PDO $db, string $bizDate): array
{
    static $cache = [];
    $key = $bizDate ?: date('Y-m-d');
    if (isset($cache[$key])) return $cache[$key];

    $isPast = $key < date('Y-m-d');
    try {
        $rows = $db->query("SELECT id, user_cname, state, user_status, hire_date, leave_date FROM `user`")
                   ->fetchAll(PDO::FETCH_ASSOC);
    } catch (Throwable $e) { return $cache[$key] = []; }

    $snapAll = $isPast ? eg_position_snapshot_at_bulk($db, $key) : eg_position_snapshot_now_bulk($db);

    $out = [];
    foreach ($rows as $u) {
        $uid = (int)$u['id'];
        if ((int)($u['user_status'] ?? 0) === 90) continue;
        if ((int)($u['state'] ?? 0) === 0) {
            // 已離職者：只有「該業務日期當時還在職」才放行（沒登錄離職日就不放行，免得混進舊名單）
            $ld = (string)($u['leave_date'] ?? '');
            if (!$isPast || $ld === '' || $ld < $key) continue;
        }
        $hd = (string)($u['hire_date'] ?? '');
        if ($hd !== '' && $hd > $key) continue;                        // 該日期還沒到職
        foreach (($snapAll[$uid] ?? []) as $s) {
            $posId = (int)($s['position_id'] ?? 0);
            $meta  = cm_position_meta($db, $posId);
            $did   = (int)($s['department_id'] ?? 0);
            $out[] = [
                'id'        => $uid,
                'uid'       => $uid,
                'state'     => (int)($u['state'] ?? 0),
                'name'      => (string)$u['user_cname'],
                'dept_id'   => $did,
                'dept_name' => ((string)($s['department_name'] ?? '')) ?: cm_dept_name($db, $did),
                'pos_name'  => ((string)($s['position_name'] ?? '')) ?: $meta['name'],
                'pos_sort'  => $meta['sort_order'],
                'level'     => $meta['level'],
                'scope'     => '',
            ];
        }
    }
    return $cache[$key] = $out;
}

/**
 * 這個人能不能簽某一關：池內本人，或池內某位的代理人（代理＝圖章加「代」，ai-rules/11、18）。
 * @return array ['ok'=>bool,'deputy'=>bool,'for_name'=>string,'as'=>array|null]
 */
function cm_can_sign(PDO $db, array $pool, int $uid): array
{
    foreach ($pool as $p) {
        if ((int)$p['id'] === $uid) return ['ok'=>true, 'deputy'=>false, 'for_name'=>'', 'as'=>$p];
    }
    foreach ($pool as $p) {
        try { $cands = eg_person_delegates($db, (int)$p['id'], null, null); }
        catch (Throwable $e) { $cands = []; }
        if (in_array($uid, array_map('intval', $cands), true)) {
            return ['ok'=>true, 'deputy'=>true, 'for_name'=>(string)$p['name'], 'as'=>$p];
        }
    }
    return ['ok'=>false, 'deputy'=>false, 'for_name'=>'', 'as'=>null];
}

/* ============================ 單號（依表單上的日期產生） ============================ */

/**
 * 單號＝溝通日期 YYYYMMDD + 3 位流水。
 * **刻意用表單上的日期而不是建檔當天**：補歷史紙本時編號才跟日期對得起來（同 internal_audit 的做法）。
 */
function cm_next_rec_no(PDO $db, string $commDate): string
{
    $d = preg_replace('/\D/', '', substr($commDate, 0, 10));
    if (strlen($d) !== 8) $d = date('Ymd');
    try {
        $st = $db->prepare("SELECT rec_no FROM comm_record WHERE rec_no LIKE ? ORDER BY rec_no DESC LIMIT 1");
        $st->execute([$d . '%']);
        $last = (string)$st->fetchColumn();
        $n = $last !== '' ? (int)substr($last, 8) : 0;
    } catch (Throwable $e) { $n = 0; }
    return $d . str_pad((string)($n + 1), 3, '0', STR_PAD_LEFT);
}

/* ============================ 附件（鐵律5） ============================ */

/** 附件實體目錄（設定鍵優先，否則 AS9100 根目錄\溝通管理\） */
function cm_attach_dir(PDO $db): string
{
    return eg_attach_dir($db, 'comm_mgmt_nas_dir', '溝通管理');
}

/* ============================ 利害關係者對象（依類別連動挑選） ============================ */

/**
 * 類別＝客戶／供應商時的模糊搜尋（打名稱或編號都找得到）。
 * 客戶回 customer_list（id＝customer_id）、供應商回 maker_list（id＝maker_id_no）。
 */
function cm_party_search(PDO $db, string $kind, string $kw, int $limit = 30): array
{
    $kw = trim($kw);
    $like = '%' . $kw . '%';
    try {
        if ($kind === 'customer') {
            $st = $db->prepare("SELECT customer_id AS id, customer AS name, customer_full AS full_name
                                FROM customer_list
                                WHERE COALESCE(is_inactive,0)=0
                                  AND (customer_id LIKE ? OR customer LIKE ? OR customer_full LIKE ?)
                                ORDER BY (customer_id=?) DESC, (customer=?) DESC, customer
                                LIMIT " . (int)$limit);
            $st->execute([$like, $like, $like, $kw, $kw]);
            return $st->fetchAll(PDO::FETCH_ASSOC);
        }
        if ($kind === 'supplier') {
            $st = $db->prepare("SELECT maker_id_no AS id, maker_id AS name, maker_id_all AS full_name
                                FROM maker_list
                                WHERE (maker_id_no LIKE ? OR maker_id LIKE ? OR maker_id_all LIKE ?)
                                ORDER BY (maker_id_no=?) DESC, (maker_id=?) DESC, maker_id
                                LIMIT " . (int)$limit);
            $st->execute([$like, $like, $like, $kw, $kw]);
            return $st->fetchAll(PDO::FETCH_ASSOC);
        }
    } catch (Throwable $e) {}
    return [];
}

/** 某客戶／供應商底下的聯絡人（代表人下拉用；沒有登錄聯絡人時回空陣列，前端仍可手動輸入） */
function cm_party_contacts(PDO $db, string $kind, string $refId): array
{
    if ($refId === '') return [];
    try {
        if ($kind === 'customer') {
            $st = $db->prepare("SELECT contact_id, name, title, department, mobile, phone_ext
                                FROM customer_contacts WHERE customer_id=?
                                ORDER BY is_primary DESC, sort_order, contact_id");
            $st->execute([$refId]);
            return $st->fetchAll(PDO::FETCH_ASSOC);
        }
        if ($kind === 'supplier') {
            $st = $db->prepare("SELECT contact_id, name, title, department, mobile, phone_ext
                                FROM maker_contacts WHERE maker_id_no=?
                                ORDER BY is_primary DESC, sort_order, contact_id");
            $st->execute([$refId]);
            return $st->fetchAll(PDO::FETCH_ASSOC);
        }
    } catch (Throwable $e) {}
    return [];
}

/** 部門清單（挑人用的第一層；含層級縮排資訊） */
function cm_dept_list(PDO $db): array
{
    try {
        return $db->query("SELECT id, name, parent_id, level, sort_order FROM department ORDER BY sort_order, id")
                  ->fetchAll(PDO::FETCH_ASSOC);
    } catch (Throwable $e) { return []; }
}

/**
 * 某部門底下的人員（依業務日期回推當時職務＝ai-rules/22）。
 * **主職與兼任都要列**：一個人在這個部門掛幾個職稱就出現幾列，職稱不同是兩種身分。
 * 排序依 ai-rules/08 人員列表鐵則：職稱 sort_order 由高到低（數字小＝職位高）。
 * $includeSub=true 時連子部門一起列（提醒對象選部門時用得到）。
 *
 * **這是「給人挑的名單」，所以要套 ai-rules/08 人員列表鐵則的排除清單**（0離職／90特殊帳號／99最高權限帳號）——
 * 超級管理員那種不是真人的帳號不該出現在「挑利害關係者／挑負責人／挑提醒對象」裡。
 * 注意不可把這個排除做進 cm_people_asof()：那支同時是簽核池與「我自己的填表身分」的來源，
 * 排掉 99 會讓超級管理員連自己的單都建不了。
 */
function cm_dept_people(PDO $db, int $deptId, string $bizDate, bool $includeSub = false): array
{
    if ($deptId <= 0) return [];
    $ids = $includeSub ? eg_dept_subtree_ids($db, $deptId) : [$deptId];
    $out = [];
    foreach (cm_people_asof($db, $bizDate) as $p) {
        if (!in_array($p['dept_id'], $ids, true)) continue;
        // 只排 90/99（特殊帳號、最高權限帳號）；state=0 的離職者交給 cm_people_asof() 自己判斷——
        // 它只在「業務日期是過去、且那天此人還在職」時才放行，補歷史單據要選得到當時的人。
        if (in_array((int)($p['state'] ?? 0), [90, 99], true)) continue;
        $out[] = $p;
    }
    usort($out, function ($a, $b) {
        return [$a['pos_sort'], $a['name'], $a['id']] <=> [$b['pos_sort'], $b['name'], $b['id']];
    });
    return $out;
}

/* ============================ 溝通管制表：頻率與提醒 ============================ */

/** 頻率顯示字串：每 2 週 1 次 */
function cm_freq_text(int $n, string $unit, int $times): string
{
    $u = CM_FREQ_UNITS[$unit] ?? '月';
    return '每 ' . max(1, $n) . ' ' . $u . ' ' . max(1, $times) . ' 次';
}

/** 依頻率把日期往後推一個週期（轉出溝通記錄後推算下次應溝通日用） */
function cm_freq_advance(string $date, int $n, string $unit): string
{
    $n = max(1, $n);
    $map = ['day' => "+{$n} day", 'week' => "+{$n} week", 'month' => "+{$n} month",
            'halfyear' => '+' . ($n * 6) . ' month', 'year' => "+{$n} year"];
    $ts = strtotime($date . ' ' . ($map[$unit] ?? "+{$n} month"));
    return $ts ? date('Y-m-d', $ts) : $date;
}

/** 某管制項目的提醒對象設定（原樣回傳，供設定畫面回填） */
function cm_ctrl_targets(PDO $db, int $ctrlId): array
{
    try {
        $st = $db->prepare("SELECT target_type, target_id FROM comm_ctrl_remind_target WHERE ctrl_id=? ORDER BY id");
        $st->execute([$ctrlId]);
        return $st->fetchAll(PDO::FETCH_ASSOC);
    } catch (Throwable $e) { return []; }
}

/**
 * 把提醒對象展開成實際要收到通知的 user id。
 * 選部門＝該部門**含子部門**的在職人員（生管組屬資材課，設資材課時生管組的人也要收到）。
 */
function cm_ctrl_target_uids(PDO $db, int $ctrlId): array
{
    $uids = [];
    foreach (cm_ctrl_targets($db, $ctrlId) as $t) {
        if ($t['target_type'] === 'user') { $uids[] = (int)$t['target_id']; continue; }
        foreach (cm_dept_people($db, (int)$t['target_id'], date('Y-m-d'), true) as $p) $uids[] = (int)$p['id'];
    }
    return array_values(array_unique(array_filter($uids)));
}

/** 提醒對象的顯示字串（清單與設定畫面共用） */
function cm_ctrl_target_labels(PDO $db, int $ctrlId): array
{
    $out = [];
    foreach (cm_ctrl_targets($db, $ctrlId) as $t) {
        $out[] = $t['target_type'] === 'user'
            ? cm_user_name($db, (int)$t['target_id'])
            : (cm_dept_name($db, (int)$t['target_id']) . '（含子部門）');
    }
    return $out;
}

/* ============================ 列印用 meta ============================ */

/**
 * 列印表頭／頁尾要的資料（ai-rules/16）：
 *   公司全名一律動態取（禁寫死）；表頭＝綁定 AS 文件的 doc_name；頁尾右下＝文件編號。
 * $bizDate 有給＝單一單據列印，版次依該單業務日期回推（第三之四節）；
 * null＝清單型列印（追蹤表/管制表），本來就該印現況最新版。
 */
function cm_print_meta(PDO $db, string $which, ?string $bizDate = null): array
{
    $cfg   = CM_ASDOC_MODULES[$which] ?? null;
    $docId = $cfg ? eg_asdoc_id($db, $cfg['module']) : 0;
    $doc   = $cfg ? eg_asdoc_get($db, $cfg['module']) : null;
    return [
        'company'  => eg_company_full_name($db),
        'doc_id'   => $docId,
        'doc_no'   => $docId ? ($bizDate !== null ? eg_asdoc_no_asof_id($db, $docId, $bizDate) : eg_asdoc_no($doc)) : '',
        'doc_name' => $doc['doc_name'] ?? ($cfg['label'] ?? ''),
        'bound'    => (bool)$doc,
    ];
}

/** 三份表單目前的 AS 綁定（設定跳窗與前端列印共用） */
function cm_asdoc_all(PDO $db): array
{
    $out = [];
    foreach (CM_ASDOC_MODULES as $k => $cfg) {
        $doc = eg_asdoc_get($db, $cfg['module']);
        $out[$k] = ['module'=>$cfg['module'], 'label'=>$cfg['label'], 'fallback'=>$cfg['fallback'],
                    'doc'=>$doc, 'doc_id'=>(int)($doc['id'] ?? 0)];
    }
    return $out;
}

}
