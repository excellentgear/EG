<?php
/**
 * 工程變更申請／審查／通知單（2-TD-01-01）共用庫
 * ------------------------------------------------------------------
 * 紙本版面（FOR CODEING 說明文件/2-TD-01-01-工程變更申請單-D.xls）由上而下：
 *   表頭：客戶名稱｜料號｜文件編號｜日期｜申請單位
 *   變更方式：□客戶通知變更(包含新訂單版次變更) □客戶藍圖有誤，通知客戶之建議變更 □其他變更
 *   設變事由說明（僅其他變更須填寫）
 *   申請人（簽章）→ 單位主管（簽章）
 *   確認庫存：庫存數量／已完工待入庫數量 → 倉管組（簽章）
 *   設計分析：□僅修改圖面(修改後結案) □需修改圖面與會審 → 技術課（簽章）
 *   1.庫存舊料：□可修改 □無法修改(轉業務確認客戶收貨或報廢)
 *   核示：□准予變更 □暫緩變更 □其他＋補充意見 → 核准（簽章）
 *   ↓以下僅技術課判定需會審才填寫↓ 相關單位會審（生產課／品保課／倉管組／生管組／採購組／業務課）
 *   管制：需修改文件資料 □圖面 □BOM □操作手冊 → 管制員（簽章）
 *   頁尾：※此表單底稿由技術課存查　2-TD-01-01D
 *   ※文件編號以西元年月日加流水號，例如：20220101001
 *
 * 規則來源：
 *   - 簽章一律走 eg_stamp.js 帶日期圖章（ai-rules/18），不印純文字姓名
 *   - 解析人／職稱一律以**該單據的業務日期**回推當時職務（ai-rules/22），回推不到不退回現況
 *   - 代理一律走 delegate_lib 的 eg_resolve_signer()（ai-rules/11），禁自己猜人
 *   - 各關卡簽核人來源可設定，未設定時退回組織角色綁定（ai-rules/19，禁寫死人名＝鐵律4）
 *   - 簽核一律寫 approval_record（ai-rules/23），不另開一張自己的簽核表
 *   - 列印一律呼叫 eg_print_log_add()（ai-rules/23），不另開一張自己的列印紀錄表
 *   - 時間戳一律取 DB 時間（PHP date() 是 UTC、MySQL NOW() 是本地，混用會差 8 小時）
 */

require_once __DIR__ . '/org_role_lib.php';
require_once __DIR__ . '/delegate_lib.php';
require_once __DIR__ . '/asdoc_lib.php';
require_once __DIR__ . '/people_lib.php';
require_once __DIR__ . '/position_history_lib.php';
require_once __DIR__ . '/approval_lib.php';
require_once __DIR__ . '/date_fmt_lib.php';

const EC_ASDOC_MODULE = 'eng_change';
const EC_APPROVAL_MODULE = 'eng_change';

/** 變更方式（紙本三個勾選項，值存 DB、顯示文字只有這一份＝鐵律4） */
const EC_CHANGE_TYPES = [
    'customer_notify' => '客戶通知變更（包含新訂單版次變更）',
    'blueprint_error' => '客戶藍圖有誤，通知客戶之建議變更（客戶同意後需附上新版客戶藍圖）',
    'other'           => '其他變更（請於設變事由說明內詳述）',
];

/** 技術課設計分析結論 */
const EC_DESIGN_RESULTS = [
    'drawing_only' => '僅修改圖面（修改後結案）',
    'need_review'  => '需修改圖面與會審',
];

/** 庫存舊料處置 */
const EC_OLD_STOCK = [
    'can'    => '可修改',
    'cannot' => '無法修改（轉業務確認客戶收貨或報廢）',
];

/** 核示結果 */
const EC_VERDICTS = [
    'approve' => '准予變更',
    'hold'    => '暫緩變更',
    'other'   => '其他',
];

/**
 * 簽核關卡（紙本流程：申請單位↓倉管↓技術↓其他單位(僅需會審者)↓技術）。
 * order 是流程順序；sign_key 是 eng_change 上那一組簽章欄位的前綴。
 * src 是「這一關預設找誰簽」的來源代碼，可在模組設定改（見 EC_SIGN_SOURCES）。
 */
const EC_STAGES = [
    'SUP'     => ['order' => 1, 'label' => '單位主管',     'sign_key' => 'sup',  'setting' => 'ec_sign_sup',  'default_src' => 'apply_dept_mgr'],
    'WH'      => ['order' => 2, 'label' => '倉管組',       'sign_key' => 'wh',   'setting' => 'ec_sign_wh',   'default_src' => 'wh_dept_mgr'],
    'TD'      => ['order' => 3, 'label' => '技術課',       'sign_key' => 'td',   'setting' => 'ec_sign_td',   'default_src' => 'rd_dept_mgr'],
    'APPROVE' => ['order' => 4, 'label' => '核准',         'sign_key' => 'appr', 'setting' => 'ec_sign_appr', 'default_src' => 'top'],
    'REVIEW'  => ['order' => 5, 'label' => '相關單位會審', 'sign_key' => '',     'setting' => '',             'default_src' => ''],
    'CTRL'    => ['order' => 6, 'label' => '管制員',       'sign_key' => 'ctrl', 'setting' => 'ec_sign_ctrl', 'default_src' => 'rd_dept_mgr'],
];

/** 簽章人來源選項（值存設定；不在別處寫死人名，鐵律4） */
const EC_SIGN_SOURCES = [
    ''                => '（留白，紙本手蓋）',
    'apply_dept_mgr'  => '申請部門主管',
    'applicant_sup'   => '申請人的上一級主管',
    'wh_dept_mgr'     => '倉管部門主管（組織角色綁定）',
    'rd_dept_mgr'     => '設計／技術部門主管（組織角色綁定）',
    'qc_dept_mgr'     => '品管部門主管（組織角色綁定）',
    'pm_dept_mgr'     => '生管部門主管（組織角色綁定）',
    'sales_dept_mgr'  => '業務部門主管（組織角色綁定）',
    'mgmt_rep'        => '管理代表（組織角色綁定）',
    'top'             => '最高核准人員（組織角色綁定）',
    // 使用者要求 2026-08-25：管制員要能指定某個課室底下的特定幾個人（複選）。
    // 這個來源做成全關卡通用，不是只給管制員——其他關卡有同樣需求時直接選就好。
    'users'           => '指定人員（複選，其中任一人都可簽）',
];

/**
 * 相關單位會審的六個單位（紙本順序）。
 * org＝對應的組織角色綁定鍵（部門是哪一個一律查綁定，不寫死部門 id）。
 * checks＝該單位自己的勾選項；extras＝該單位額外要填的欄位。
 */
const EC_REVIEW_UNITS = [
    'prod'  => ['label' => '生產課', 'org' => 'prod_dept',
                'checks' => ['received' => '已收到設變通知', 'data_fixed' => '生產相關資料已修改（僅需修改者）'],
                'extras' => []],
    'qa'    => ['label' => '品保課', 'org' => 'qc_dept',
                'checks' => ['received' => '已收到設變通知', 'data_fixed' => '檢驗用資料已修改（僅需修改者）'],
                'extras' => []],
    'wh'    => ['label' => '倉管組', 'org' => 'wh_dept',
                'checks' => ['stock_issued' => '庫存已領出（僅需修改者）'],
                'extras' => []],
    'pmc'   => ['label' => '生管組', 'org' => 'pm_dept',
                'checks' => ['bom_added' => '已增加BOM修改製程', 'stock_sent' => '庫存已送修改（僅需修改者）'],
                'extras' => ['out_qty' => '發包中數量', 'bom_no' => 'BOM編號（B-）', 'cur_process' => '目前製程']],
    'pur'   => ['label' => '採購組', 'org' => 'purchase_dept',
                'checks' => ['no_purchase' => '無相關採購件（有採購件者請列出清單、數量與價格，可附件提供）',
                             'repurchased' => '已重新購買／更換零件'],
                'extras' => ['po_note' => '備註日期／單號']],
    'sales' => ['label' => '業務課', 'org' => 'sales_dept',
                'checks' => ['stock_accept' => '庫存可允收',
                             'stock_scrap'  => '庫存報廢（可歸責於客戶者需與主管確認請款方式）'],
                'extras' => []],
];

/**
 * 哪一關是哪個課室在填（使用者要求 2026-08-25：申請人本身部門可以填的欄位要能直接填，
 * 例如技術課的人開單就可以順手把「設計分析」填掉、倉管開單可以直接確認庫存）。
 * 值是組織角色綁定的 key，實際是哪個部門一律即時查綁定，不寫死部門 id（鐵律4）。
 * SUP／APPROVE 沒有欄位可填，故不列入。
 */
const EC_STAGE_DEPT = ['WH' => 'wh_dept', 'TD' => 'rd_dept', 'CTRL' => 'rd_dept'];

const EC_SETTING_KEYS = ['ec_stamp_tpl_id', 'ec_review_stamp_tpl_id',
                         'ec_sign_sup', 'ec_sign_wh', 'ec_sign_td', 'ec_sign_appr', 'ec_sign_ctrl',
                         // 來源選「指定人員」時用的：_users＝勾選的 user.id（逗號字串）、
                         // _dept＝挑人跳窗當時篩的課室（只是記住上次選哪一課，判定不看它）
                         'ec_sign_sup_users', 'ec_sign_wh_users', 'ec_sign_td_users',
                         'ec_sign_appr_users', 'ec_sign_ctrl_users',
                         'ec_sign_sup_dept', 'ec_sign_wh_dept', 'ec_sign_td_dept',
                         'ec_sign_appr_dept', 'ec_sign_ctrl_dept',
                         'ec_auto_from_dwg'];

/* ============================ Schema ============================ */

function ec_ensure_schema(PDO $db): void
{
    static $done = false;
    if ($done) return;
    $done = true;
    try {
        $db->exec("CREATE TABLE IF NOT EXISTS eng_change (
            ec_id            INT AUTO_INCREMENT PRIMARY KEY,
            doc_no           VARCHAR(20) NULL COMMENT '文件編號 YYYYMMDD+3位流水（紙本註明的格式，例 20220101001）',
            apply_date       DATE NOT NULL COMMENT '日期＝業務日期（列印表頭右上；解析當時職務也用這一天）',
            customer_id      VARCHAR(20) NULL COMMENT '客戶編號＝customer_list.customer_id，是**字串**（例 Z2001A）不是數字',
            customer_name    VARCHAR(120) NULL,
            d_id             INT NULL COMMENT 'd_setting.d_id（綁料號後客戶自動帶出）',
            part_no          VARCHAR(80) NULL,
            apply_dept_id    INT NULL,
            apply_dept_name  VARCHAR(60) NULL,
            applicant_id     INT NULL,
            applicant_name   VARCHAR(60) NULL,
            change_type      VARCHAR(20) NULL COMMENT '見 EC_CHANGE_TYPES',
            change_reason    TEXT NULL COMMENT '設變事由說明（僅其他變更須填寫）',
            stock_qty        VARCHAR(60) NULL COMMENT '倉管填：庫存數量',
            wip_qty          VARCHAR(60) NULL COMMENT '倉管填：已完工待入庫數量',
            design_result    VARCHAR(20) NULL COMMENT '見 EC_DESIGN_RESULTS；need_review 才會跑會審關卡',
            design_note      TEXT NULL COMMENT '技術課設計分析補充',
            old_stock        VARCHAR(20) NULL COMMENT '庫存舊料 見 EC_OLD_STOCK',
            verdict          VARCHAR(20) NULL COMMENT '核示 見 EC_VERDICTS',
            verdict_other    VARCHAR(120) NULL COMMENT '核示選「其他」時填的文字',
            verdict_note     TEXT NULL COMMENT '核示補充意見',
            ctrl_drawing     TINYINT NOT NULL DEFAULT 0 COMMENT '管制：需修改圖面',
            ctrl_bom         TINYINT NOT NULL DEFAULT 0 COMMENT '管制：需修改BOM',
            ctrl_manual      TINYINT NOT NULL DEFAULT 0 COMMENT '管制：需修改操作手冊',
            status           VARCHAR(12) NOT NULL DEFAULT 'DRAFT' COMMENT 'DRAFT/SUP/WH/TD/APPROVE/REVIEW/CTRL/CLOSED/REJECTED',
            reject_stage     VARCHAR(12) NULL COMMENT '退回時停在哪一關',
            reject_reason    TEXT NULL,
            source_change_id INT NULL COMMENT '來源的圖面變更紀錄 qc_drawing_change.id（自動產生時才有）',
            create_source    VARCHAR(12) NOT NULL DEFAULT 'manual' COMMENT 'manual=手動開立 / dwg=圖面變更送出時自動產生',
            submitted_at     DATETIME NULL COMMENT '送出（正式成立）時間；DRAFT 為 NULL',
            closed_at        DATETIME NULL,
            created_by       INT NULL,
            created_by_name  VARCHAR(60) NULL,
            created_at       DATETIME NULL,
            updated_by       INT NULL,
            updated_at       DATETIME NULL,
            UNIQUE KEY uk_doc_no (doc_no),
            KEY idx_status (status, apply_date),
            KEY idx_part (d_id),
            KEY idx_src (source_change_id)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COMMENT='工程變更申請/審查/通知單（2-TD-01-01）'");
    } catch (Throwable $e) {}

    // 客戶編號是字串（d_setting.Customer_Id / customer_list.customer_id 都是 varchar，例 'Z2001A'）。
    // 一開始誤宣告成 INT，那樣會把 'Z2001A' 轉成 0 把客戶編號存丟；本表建立後才發現，故補一道 MODIFY。
    try { $db->exec("ALTER TABLE eng_change MODIFY customer_id VARCHAR(20) NULL COMMENT '客戶編號＝customer_list.customer_id（字串）'"); } catch (Throwable $e) {}

    // 各關卡簽章欄位（誰簽的、什麼時候簽的）。簽核事實同時寫 approval_record（ai-rules/23），
    // 這裡存一份是為了列印時直接取得該格要蓋誰的章、不必每次回頭掃簽核紀錄。
    foreach (['applicant', 'sup', 'wh', 'td', 'appr', 'ctrl'] as $k) {
        try { $db->exec("ALTER TABLE eng_change ADD COLUMN sign_{$k}_id INT NULL"); } catch (Throwable $e) {}
        try { $db->exec("ALTER TABLE eng_change ADD COLUMN sign_{$k}_name VARCHAR(60) NULL"); } catch (Throwable $e) {}
        try { $db->exec("ALTER TABLE eng_change ADD COLUMN sign_{$k}_at DATETIME NULL"); } catch (Throwable $e) {}
        // 代理人代簽時右下角要加「代」字（ai-rules/18），所以要記「本來該誰簽」
        try { $db->exec("ALTER TABLE eng_change ADD COLUMN sign_{$k}_for_id INT NULL COMMENT '被代理人 user.id；有值＝這一格是代簽'"); } catch (Throwable $e) {}
    }

    try {
        $db->exec("CREATE TABLE IF NOT EXISTS eng_change_review (
            id           INT AUTO_INCREMENT PRIMARY KEY,
            ec_id        INT NOT NULL,
            unit_key     VARCHAR(10) NOT NULL COMMENT '見 EC_REVIEW_UNITS',
            dept_id      INT NULL COMMENT '當下解析到的部門（供之後查核用，判定一律即時查綁定）',
            needed       TINYINT NOT NULL DEFAULT 0 COMMENT '1=技術課勾選了這個單位要會審',
            checks_json  TEXT NULL COMMENT '該單位的勾選項 {key:0/1}',
            extras_json  TEXT NULL COMMENT '該單位的額外欄位 {key:值}',
            opinion      TEXT NULL COMMENT '會審意見（非必填）',
            signer_id    INT NULL,
            signer_name  VARCHAR(60) NULL,
            signer_for_id INT NULL COMMENT '被代理人 user.id；有值＝代簽',
            signed_at    DATETIME NULL,
            UNIQUE KEY uk_ec_unit (ec_id, unit_key),
            KEY idx_ec (ec_id)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COMMENT='工程變更申請單：相關單位會審'");
    } catch (Throwable $e) {}
}

/* ============================ 基礎 ============================ */

/** 時間戳一律取 DB 時間（PHP date() 是 UTC、MySQL NOW() 是本地，混用會差 8 小時） */
function ec_db_now(PDO $db): array
{
    try {
        $r = $db->query("SELECT NOW() AS dt, CURDATE() AS d")->fetch(PDO::FETCH_ASSOC);
        if ($r) return ['dt' => (string)$r['dt'], 'd' => (string)$r['d']];
    } catch (Throwable $e) {}
    return ['dt' => date('Y-m-d H:i:s'), 'd' => date('Y-m-d')];
}

function ec_current_user(PDO $db): ?array
{
    $uname = $_SESSION['userName'] ?? '';
    if ($uname === '') return null;
    $st = $db->prepare("SELECT id, user_cname, user_status, state FROM `user` WHERE user_uname=?");
    $st->execute([$uname]);
    return $st->fetch(PDO::FETCH_ASSOC) ?: null;
}

function ec_has_role(PDO $db, int $uid, array $codes): bool
{
    if (!$codes || $uid <= 0) return false;
    $in = implode(',', array_fill(0, count($codes), '?'));
    try {
        $st = $db->prepare("SELECT 1 FROM user_roles ur JOIN roles r ON r.role_id=ur.role_id
                            WHERE ur.user_id=? AND r.module='eng_change' AND r.role_code IN ($in) LIMIT 1");
        $st->execute(array_merge([$uid], $codes));
        return (bool)$st->fetchColumn();
    } catch (Throwable $e) { return false; }
}

/**
 * canAdmin 管理員：查全部、代開、刪除、改他人的單、模組設定、AS 綁定、批次列印/刪除
 * canEdit  可開立／編輯自己的申請單
 * canView  唯讀檢閱全部
 * 簽核權：不靠角色，由「這一關解析到的人是不是你」決定（見 ec_can_sign_stage）——
 *         各單位主管本來就不會特地去申請一個角色，用角色擋只會讓單子卡住。
 */
function ec_perms(PDO $db, ?array $u): array
{
    if (!$u) return ['isAdmin' => false, 'canAdmin' => false, 'canEdit' => false, 'canView' => false, 'uid' => 0, 'name' => ''];
    $uid = (int)$u['id'];
    $isAdmin = in_array((int)($u['user_status'] ?? 0), [9, 90], true);
    if (!$isAdmin) {
        try {
            $st = $db->prepare("SELECT 1 FROM user_roles ur JOIN roles r ON r.role_id=ur.role_id
                                WHERE ur.user_id=? AND r.role_code='admin' AND r.is_system=1 LIMIT 1");
            $st->execute([$uid]);
            $isAdmin = (bool)$st->fetchColumn();
        } catch (Throwable $e) {}
    }
    $canAdmin = $isAdmin || ec_has_role($db, $uid, ['eng_change_admin']);
    $canEdit  = $canAdmin || ec_has_role($db, $uid, ['eng_change_edit']);
    $canView  = $canEdit  || ec_has_role($db, $uid, ['eng_change_view']);
    return ['isAdmin' => $isAdmin, 'canAdmin' => $canAdmin, 'canEdit' => $canEdit, 'canView' => $canView,
            'uid' => $uid, 'name' => (string)$u['user_cname']];
}

/* ============================ 設定 ============================ */

function ec_settings(PDO $db): array
{
    $out = ['ec_stamp_tpl_id' => null, 'ec_review_stamp_tpl_id' => null, 'ec_auto_from_dwg' => 1];
    foreach (EC_STAGES as $st) {
        if ($st['setting'] === '') continue;
        $out[$st['setting']]            = $st['default_src'];
        $out[$st['setting'] . '_users'] = '';
        $out[$st['setting'] . '_dept']  = '';
    }
    try {
        $in = implode(',', array_fill(0, count(EC_SETTING_KEYS), '?'));
        $q = $db->prepare("SELECT setting_key, setting_value FROM system_settings WHERE setting_key IN ($in)");
        $q->execute(EC_SETTING_KEYS);
        foreach ($q->fetchAll(PDO::FETCH_ASSOC) as $r) {
            $k = (string)$r['setting_key']; $v = $r['setting_value'];
            if (substr($k, -7) === '_tpl_id')      $out[$k] = ($v === '' || $v === null) ? null : (int)$v;
            elseif ($k === 'ec_auto_from_dwg')     $out[$k] = (int)$v;
            else                                   $out[$k] = (string)$v;   // _users / _dept 都是字串
        }
    } catch (Throwable $e) {}
    return $out;
}

function ec_save_setting(PDO $db, string $key, $val): void
{
    if (!in_array($key, EC_SETTING_KEYS, true)) return;
    // 簽章來源只收清單內的值（直打 API 繞不過去＝鐵律8）。
    // _users（勾選的人員 id）與 _dept（挑人時的課室）不是來源代碼，走各自的清洗。
    if (substr($key, -6) === '_users') {
        $ids = array_values(array_unique(array_filter(array_map('intval', explode(',', (string)$val)), fn($i) => $i > 0)));
        $val = implode(',', array_slice($ids, 0, 50));   // 上限 50 人，避免一次通知全公司
    } elseif (substr($key, -5) === '_dept') {
        $val = (string)((int)$val ?: '');
    } elseif (strpos($key, 'ec_sign_') === 0 && !array_key_exists((string)$val, EC_SIGN_SOURCES)) {
        return;
    }
    $db->prepare("INSERT INTO system_settings (setting_key, setting_value) VALUES (?,?)
                  ON DUPLICATE KEY UPDATE setting_value=VALUES(setting_value)")->execute([$key, (string)$val]);
}

/** 圖章模板（未設定或已停用回 null，消費端退回預設回墨印） */
function ec_stamp_template(PDO $db, string $key): ?array
{
    $id = (int)(ec_settings($db)[$key] ?? 0);
    if (!$id) return null;
    try {
        $st = $db->prepare("SELECT id, tpl_name, schema_json FROM stamp_template WHERE id=? AND is_active=1");
        $st->execute([$id]);
        $r = $st->fetch(PDO::FETCH_ASSOC);
        if (!$r) return null;
        return ['id' => (int)$r['id'], 'tpl_name' => $r['tpl_name'], 'schema' => json_decode((string)$r['schema_json'], true)];
    } catch (Throwable $e) { return null; }
}

/* ============================ 文件編號 ============================ */

/**
 * 文件編號＝西元年月日＋3 位流水（紙本頁尾明文規定：「例如：20220101001」）。
 *
 * 依「表單上的日期」產生而不是建檔當天——補歷史紙本時編號要跟表單上的日期對得起來
 * （比照 td_dev_eval／pfmea 2026-08-20 的既有決定）。日期事後被改時要呼叫 ec_sync_doc_no()。
 */
function ec_next_doc_no(PDO $db, string $applyDate, int $excludeId = 0): string
{
    ec_ensure_schema($db);
    $d = preg_match('/^\d{4}-\d{2}-\d{2}$/', $applyDate) ? $applyDate : ec_db_now($db)['d'];
    $prefix = str_replace('-', '', $d);
    try {
        $sql = "SELECT doc_no FROM eng_change WHERE doc_no LIKE ?";
        $args = [$prefix . '%'];
        if ($excludeId > 0) { $sql .= " AND ec_id<>?"; $args[] = $excludeId; }
        $sql .= " ORDER BY doc_no DESC LIMIT 1";
        $st = $db->prepare($sql); $st->execute($args);
        $last = (string)$st->fetchColumn();
    } catch (Throwable $e) { $last = ''; }
    $n = $last !== '' ? ((int)substr($last, 8) + 1) : 1;
    return $prefix . str_pad((string)$n, 3, '0', STR_PAD_LEFT);
}

/** 日期被改時重編編號（前八碼永遠＝表單上的日期）。已是正確前綴就不動，避免流水號無謂跳號。 */
function ec_sync_doc_no(PDO $db, int $ecId): void
{
    $row = ec_row($db, $ecId);
    if (!$row) return;
    $want = str_replace('-', '', (string)$row['apply_date']);
    if ($want !== '' && strpos((string)$row['doc_no'], $want) === 0) return;
    $no = ec_next_doc_no($db, (string)$row['apply_date'], $ecId);
    $db->prepare("UPDATE eng_change SET doc_no=? WHERE ec_id=?")->execute([$no, $ecId]);
}

/* ============================ 讀取 ============================ */

function ec_row(PDO $db, int $ecId): ?array
{
    ec_ensure_schema($db);
    $st = $db->prepare("SELECT * FROM eng_change WHERE ec_id=?");
    $st->execute([$ecId]);
    return $st->fetch(PDO::FETCH_ASSOC) ?: null;
}

/** 某張單的會審列（依 EC_REVIEW_UNITS 順序，缺的補空列，畫面才不會少一格） */
function ec_review_rows(PDO $db, int $ecId): array
{
    ec_ensure_schema($db);
    $have = [];
    try {
        $st = $db->prepare("SELECT * FROM eng_change_review WHERE ec_id=?");
        $st->execute([$ecId]);
        foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $r) $have[(string)$r['unit_key']] = $r;
    } catch (Throwable $e) {}
    $out = [];
    foreach (EC_REVIEW_UNITS as $key => $def) {
        $r = $have[$key] ?? null;
        $out[] = [
            'unit_key'    => $key,
            'label'       => $def['label'],
            'needed'      => $r ? (int)$r['needed'] : 0,
            'checks'      => $r ? (json_decode((string)$r['checks_json'], true) ?: []) : [],
            'extras'      => $r ? (json_decode((string)$r['extras_json'], true) ?: []) : [],
            'opinion'     => $r ? (string)$r['opinion'] : '',
            'signer_id'   => $r ? (int)$r['signer_id'] : 0,
            'signer_name' => $r ? (string)$r['signer_name'] : '',
            'signer_for_id' => $r ? (int)$r['signer_for_id'] : 0,
            'signed_at'   => $r ? (string)$r['signed_at'] : '',
        ];
    }
    return $out;
}

/* ============================ 人員解析（ai-rules/19 ＋ 22 ＋ 11） ============================ */

/** 指定日期是否在職（比照 doc_apply 的 da_in_service_asof；過去日期要放行已離職者） */
function ec_in_service_asof(array $u, string $date): bool
{
    if ((int)($u['state'] ?? 1) === 90) return false;
    $hire  = (string)($u['hire_date'] ?? '');
    $leave = (string)($u['leave_date'] ?? '');
    if ($hire !== '' && $hire > $date) return false;
    if ($leave !== '' && $leave < $date) return false;
    if ($leave === '' && (int)($u['state'] ?? 1) === 0) return false;
    return true;
}

/**
 * 依業務日期回推的逐職務人員清單（含兼任）。
 * 與現況清單的差別：部門/職稱取自 user_position_history 在該日期的快照，
 * 在職與否也用該日期判定，所以**當時在職、現已離職的人也會列出**（標 is_former=1）。
 */
function ec_people_posts_asof(PDO $db, string $date): array
{
    static $cache = [];
    if ($date === '') { try { return eg_people_posts($db, []); } catch (Throwable $e) { return []; } }
    if (isset($cache[$date])) return $cache[$date];

    try { $users = $db->query("SELECT id, user_cname, state, hire_date, leave_date FROM `user`")->fetchAll(PDO::FETCH_ASSOC); }
    catch (Throwable $e) { return []; }

    $deptMap = []; $posMap = [];
    try {
        foreach ($db->query("SELECT id, name, COALESCE(sort_order,999) s FROM department")->fetchAll(PDO::FETCH_ASSOC) as $d)
            $deptMap[(int)$d['id']] = ['name' => (string)$d['name'], 'sort' => (int)$d['s']];
        foreach ($db->query("SELECT id, name, COALESCE(sort_order,999) s FROM position")->fetchAll(PDO::FETCH_ASSOC) as $p)
            $posMap[(int)$p['id']] = ['name' => (string)$p['name'], 'sort' => (int)$p['s']];
    } catch (Throwable $e) {}

    $snapAll = eg_position_snapshot_at_bulk($db, $date);
    $out = [];
    foreach ($users as $u) {
        if (!ec_in_service_asof($u, $date)) continue;
        $uid = (int)$u['id'];
        foreach (($snapAll[$uid] ?? []) as $s) {
            $did = (int)($s['department_id'] ?? 0);
            $pid = (int)($s['position_id'] ?? 0);
            $isFormer = (int)($u['state'] ?? 1) === 0 ? 1 : 0;
            $dn = $deptMap[$did]['name'] ?? (string)($s['department_name'] ?? '');
            $pn = $posMap[$pid]['name']  ?? (string)($s['position_name'] ?? '');
            $out[] = [
                'id' => $uid, 'user_cname' => (string)$u['user_cname'],
                'dept_id' => $did ?: null, 'dept_name' => $dn, 'dept_sort' => $deptMap[$did]['sort'] ?? 999,
                'position_id' => $pid ?: null, 'position_name' => $pn, 'position_sort' => $posMap[$pid]['sort'] ?? 999,
                'is_main' => (int)($s['is_main'] ?? 0), 'is_former' => $isFormer,
                'display' => trim($dn . '　' . $pn . '　' . $u['user_cname'])
                           . ((int)($s['is_main'] ?? 0) ? '' : '（兼任）') . ($isFormer ? '（已離職）' : ''),
            ];
        }
    }
    // 欄位順序固定「部門/職稱/姓名」，排序依 sort_order（人員列表鐵則第 5 條）
    usort($out, fn($a, $b) => [$a['dept_sort'], $a['dept_id'], $a['position_sort'], $a['id']]
                          <=> [$b['dept_sort'], $b['dept_id'], $b['position_sort'], $b['id']]);
    $cache[$date] = $out;
    return $out;
}

/**
 * 某部門（含子部門）在指定業務日期當時的主管。
 *
 * ★ai-rules/22 第一坑：$date 有值時**絕對不可**在查不到時退回 eg_org_dept_manager() 這種
 *   「現況」解析器——那會把現在才上任的人蓋到舊文件上，正好抵銷回推的意義。
 *   寧可少一個章（該格留白給紙本手蓋），也不要蓋錯人。
 */
function ec_dept_manager_asof(PDO $db, array $deptIds, string $date): ?array
{
    $deptIds = array_values(array_filter(array_map('intval', $deptIds)));
    if (!$deptIds) return null;
    if ($date === '') {
        $m = eg_org_dept_manager($db, $deptIds);
        return $m ? ['id' => (int)$m['id'], 'user_cname' => (string)$m['user_cname']] : null;
    }
    $levels = [];
    try {
        foreach ($db->query("SELECT position_id, level FROM position_level WHERE level IS NOT NULL")->fetchAll(PDO::FETCH_ASSOC) as $l)
            $levels[(int)$l['position_id']] = (int)$l['level'];
    } catch (Throwable $e) { return null; }
    if (!$levels) return null;

    $best = null;
    foreach (ec_people_posts_asof($db, $date) as $p) {
        if (!in_array((int)$p['dept_id'], $deptIds, true)) continue;
        $lv = $levels[(int)$p['position_id']] ?? null;
        if ($lv === null) continue;                       // 職級沒設定的職稱不算主管
        if ($best === null || $lv < $best['level'])
            $best = ['id' => (int)$p['id'], 'user_cname' => (string)$p['user_cname'], 'level' => $lv];
    }
    return $best ? ['id' => $best['id'], 'user_cname' => $best['user_cname']] : null;
}

/** 某人在指定業務日期當時的身分（姓名＋當時部門／職稱）；回推不到就只回姓名。 */
function ec_user_identity_asof(PDO $db, int $uid, string $date, int $preferDeptId = 0): array
{
    $out = ['user_name' => '', 'dept_id' => null, 'dept_name' => '', 'position_name' => ''];
    if ($uid <= 0) return $out;
    try {
        $st = $db->prepare("SELECT user_cname FROM `user` WHERE id=?");
        $st->execute([$uid]);
        $out['user_name'] = (string)($st->fetchColumn() ?: '');
    } catch (Throwable $e) {}
    if ($date === '') return $out;
    $posts = array_values(array_filter(ec_people_posts_asof($db, $date), fn($p) => (int)$p['id'] === $uid));
    if (!$posts) return $out;
    $pick = null;
    if ($preferDeptId) foreach ($posts as $p) { if ((int)$p['dept_id'] === $preferDeptId) { $pick = $p; break; } }
    if (!$pick) {
        // 兼任常才是簽核身分：同一人有多個職務時取「職級最高」那一筆，不是主職（ai-rules/22 第二坑）
        $levels = [];
        try {
            foreach ($db->query("SELECT position_id, level FROM position_level WHERE level IS NOT NULL")->fetchAll(PDO::FETCH_ASSOC) as $l)
                $levels[(int)$l['position_id']] = (int)$l['level'];
        } catch (Throwable $e) {}
        foreach ($posts as $p) {
            $lv = $levels[(int)$p['position_id']] ?? 9999;
            if ($pick === null || $lv < ($levels[(int)$pick['position_id']] ?? 9999)) $pick = $p;
        }
    }
    if (!$pick) return $out;
    return ['user_name' => (string)$pick['user_cname'],
            'dept_id' => $pick['dept_id'] !== null ? (int)$pick['dept_id'] : null,
            'dept_name' => (string)$pick['dept_name'],
            'position_name' => (string)$pick['position_name']];
}

/**
 * 把「簽章來源代碼」解析成人。回 ['id'=>int,'name'=>string]，解析不到回 id=0。
 * 一律以該單據的業務日期回推當時職務（ai-rules/22）；回推不到不退回現況。
 */
function ec_resolve_src(PDO $db, string $src, array $row): array
{
    $none = ['id' => 0, 'name' => ''];
    $date = (string)($row['apply_date'] ?? '');
    $deptOf = function (string $key) use ($db) { return eg_org_dept_ids($db, $key); };

    switch ($src) {
        case '':
            return $none;
        case 'top':
        case 'mgmt_rep':
            $b = eg_org_bindings($db)[$src] ?? null;
            $uid = (int)($b['user_id'] ?? 0);
            if (!$uid) return $none;
            $idt = ec_user_identity_asof($db, $uid, $date);
            return ['id' => $uid, 'name' => $idt['user_name']];
        case 'apply_dept_mgr':
            $did = (int)($row['apply_dept_id'] ?? 0);
            if (!$did) return $none;
            $m = ec_dept_manager_asof($db, eg_dept_subtree_ids($db, $did), $date);
            return $m ? ['id' => $m['id'], 'name' => $m['user_cname']] : $none;
        case 'applicant_sup':
            $aid = (int)($row['applicant_id'] ?? 0);
            if (!$aid) return $none;
            // 上一級主管沒有「當時」版本可查，只有現況解析器；業務日期在過去時一律不用它
            // （ai-rules/22 第一坑），改退回申請部門主管的回推結果。
            $today = ec_db_now($db)['d'];
            if ($date !== '' && $date < $today) {
                $did = (int)($row['apply_dept_id'] ?? 0);
                if (!$did) return $none;
                $m = ec_dept_manager_asof($db, eg_dept_subtree_ids($db, $did), $date);
                return $m ? ['id' => $m['id'], 'name' => $m['user_cname']] : $none;
            }
            $sup = eg_resolve_supervisor($db, $aid, (int)($row['apply_dept_id'] ?? 0) ?: null);
            if (!$sup) return $none;
            $idt = ec_user_identity_asof($db, (int)$sup, $date);
            return ['id' => (int)$sup, 'name' => $idt['user_name']];
        default:
            // xx_dept_mgr → 對應的組織角色綁定部門主管
            if (substr($src, -9) === '_dept_mgr') {
                $key = substr($src, 0, -4);            // wh_dept_mgr → wh_dept
                $ids = $deptOf($key);
                if (!$ids) return $none;
                $m = ec_dept_manager_asof($db, $ids, $date);
                return $m ? ['id' => $m['id'], 'name' => $m['user_cname']] : $none;
            }
            return $none;
    }
}

/**
 * 某一關的**合格簽核人名單**（OR-gate：其中任何一個人簽了就算過這一關）。
 *
 * 來源＝'users' 時是使用者在設定裡勾選的那幾個人（例：管制員指定技術課底下的三個人）；
 * 其他來源都只會解析出一個人，回傳一人的陣列。每一位都各自跑過代理解析
 * （ai-rules/11：本人不在時換成代理人，並記下「本來該誰簽」供圖章加「代」字）。
 *
 * @return array<int,array{id:int,name:string,for_id:int,for_name:string}>
 */
function ec_stage_signer_pool(PDO $db, array $row, string $stage): array
{
    $def = EC_STAGES[$stage] ?? null;
    if (!$def || $def['setting'] === '') return [];
    $cfg = ec_settings($db);
    $src = (string)($cfg[$def['setting']] ?? $def['default_src']);

    if ($src === 'users') {
        $ids = array_values(array_filter(array_map('intval', explode(',', (string)($cfg[$def['setting'] . '_users'] ?? ''))), fn($i) => $i > 0));
        if (!$ids) return [];
        $date = (string)($row['apply_date'] ?? '');
        $out = [];
        foreach ($ids as $uid) {
            // 姓名一律以本單日期回推當時的稱呼（ai-rules/22）；查不到就退回 user 表的姓名
            $idt = ec_user_identity_asof($db, $uid, $date);
            $nm  = $idt['user_name'];
            if ($nm === '') continue;                    // 帳號已被刪掉就跳過，不要留一個空白的簽核人
            $out[] = ec_apply_delegate($db, $uid, $nm);
        }
        return $out;
    }

    $p = ec_resolve_src($db, $src, $row);
    if (!$p['id']) return [];
    return [ec_apply_delegate($db, (int)$p['id'], (string)$p['name'])];
}

/**
 * 某一關「代表性」的簽核人＝名單第一位（畫面顯示「目前等待：○○○」、
 * 以及沒有人在名單裡時要蓋誰的章時用）。多人名單請改用 ec_stage_signer_pool()。
 */
function ec_stage_signer(PDO $db, array $row, string $stage): array
{
    $pool = ec_stage_signer_pool($db, $row, $stage);
    return $pool ? $pool[0] : ['id' => 0, 'name' => '', 'for_id' => 0, 'for_name' => ''];
}

/** 代理解析：本人不在時換成代理人，並記下「本來該誰簽」供圖章加「代」字 */
function ec_apply_delegate(PDO $db, int $uid, string $name): array
{
    $out = ['id' => $uid, 'name' => $name, 'for_id' => 0, 'for_name' => ''];
    if ($uid <= 0) return $out;
    try {
        $r = eg_resolve_signer($db, $uid, ['scene' => 'approval']);
        $actual = (int)($r['user_id'] ?? $uid);
        if ($actual > 0 && $actual !== $uid) {
            $st = $db->prepare("SELECT user_cname FROM `user` WHERE id=?");
            $st->execute([$actual]);
            $out = ['id' => $actual, 'name' => (string)($st->fetchColumn() ?: ''),
                    'for_id' => $uid, 'for_name' => $name];
        }
    } catch (Throwable $e) {}
    return $out;
}

/** 某個會審單位該由誰簽（部門主管；一樣走業務日期回推＋代理解析） */
function ec_review_signer(PDO $db, array $row, string $unitKey): array
{
    $def = EC_REVIEW_UNITS[$unitKey] ?? null;
    if (!$def) return ['id' => 0, 'name' => '', 'for_id' => 0, 'for_name' => ''];
    $ids = eg_org_dept_ids($db, $def['org']);
    if (!$ids) return ['id' => 0, 'name' => '', 'for_id' => 0, 'for_name' => ''];
    $m = ec_dept_manager_asof($db, $ids, (string)($row['apply_date'] ?? ''));
    if (!$m) return ['id' => 0, 'name' => '', 'for_id' => 0, 'for_name' => ''];
    return ec_apply_delegate($db, (int)$m['id'], (string)$m['user_cname']);
}

/**
 * 這個人能不能簽這一關。
 * 管理員一律可以（補歷史單、當事人休假太久卡住時要有人推得動）；
 * 其他人＝解析到的簽核人本人，或是他的代理人。
 */
function ec_can_sign_stage(PDO $db, array $row, string $stage, int $uid, bool $isAdmin): bool
{
    if ($uid <= 0) return false;
    if ($isAdmin) return true;
    foreach (ec_stage_signer_pool($db, $row, $stage) as $p) {
        if ((int)$p['id'] === $uid) return true;         // OR-gate：名單裡任一人都可以簽
    }
    return false;
}

/**
 * 這個人能不能「提早填寫」某一關的欄位（填但**不簽**，使用者要求 2026-08-25）。
 *
 * 成立條件（任一）：管理員／是那一關的合格簽核人／本身就在那一關負責的課室底下。
 * 但一律不能回頭改**已經簽過**的關卡——那些內容已經蓋章生效，改掉等於偽造。
 * 提早填只是把資料先備好，真正的簽核仍要等單子走到那一關（ec_sign_stage 會擋）。
 */
function ec_can_prefill_stage(PDO $db, array $row, string $stage, int $uid, bool $isAdmin): bool
{
    if ($uid <= 0) return false;
    if (!isset(EC_STAGES[$stage]) || !ec_stage_editable_fields($stage)) return false;
    // 單子已經走過這一關（或已結案／退回）就不能再改
    $order = array_keys(EC_STAGES);
    $cur = array_search((string)$row['status'], $order, true);
    $tgt = array_search($stage, $order, true);
    if ((string)$row['status'] === 'CLOSED') return false;
    if ($cur !== false && $tgt !== false && $tgt < $cur) return false;
    if ((string)$row['status'] === 'REJECTED') return false;

    if ($isAdmin) return true;
    if (ec_can_sign_stage($db, $row, $stage, $uid, false)) return true;
    $key = EC_STAGE_DEPT[$stage] ?? '';
    if ($key === '') return false;
    $deptIds = eg_org_dept_ids($db, $key);
    if (!$deptIds) return false;
    return ec_user_in_depts($db, $uid, $deptIds);
}

/** 這個人（含兼任）是不是掛在這幾個部門底下的任一個 */
function ec_user_in_depts(PDO $db, int $uid, array $deptIds): bool
{
    $deptIds = array_values(array_filter(array_map('intval', $deptIds)));
    if ($uid <= 0 || !$deptIds) return false;
    try {
        $in = implode(',', $deptIds);
        $st = $db->prepare("SELECT 1 FROM user_department_position_map
                             WHERE user_id=? AND department_id IN ($in) LIMIT 1");
        $st->execute([$uid]);
        return (bool)$st->fetchColumn();
    } catch (Throwable $e) { return false; }
}

/**
 * 某人在指定日期「當時」的所有職務（含兼任），供申請人選擇要用哪一個身分申請
 * （使用者要求 2026-08-25：有兼任職務時要能選用哪個部門與職稱申請）。
 *
 * @return array<int,array{dept_id:int,dept_name:string,position_name:string,is_main:int,label:string}>
 */
function ec_applicant_posts(PDO $db, int $uid, string $date): array
{
    $out = [];
    foreach (ec_people_posts_asof($db, $date) as $p) {
        if ((int)$p['id'] !== $uid) continue;
        $out[] = [
            'dept_id'       => (int)($p['dept_id'] ?? 0),
            'dept_name'     => (string)$p['dept_name'],
            'position_name' => (string)$p['position_name'],
            'is_main'       => (int)$p['is_main'],
            'label'         => trim((string)$p['dept_name'] . '　' . (string)$p['position_name'])
                             . ((int)$p['is_main'] ? '' : '（兼任）'),
        ];
    }
    return $out;
}

/**
 * 申請人＋申請部門這一組是不是成立（申請人在該日期真的掛在那個部門底下）。
 * 前端已經只列得出合法的組合，後端照樣再驗一次（直打 API 繞不過去＝鐵律8）。
 */
function ec_valid_applicant_post(PDO $db, int $uid, int $deptId, string $date): bool
{
    if ($uid <= 0) return false;
    $posts = ec_applicant_posts($db, $uid, $date);
    if (!$posts) return $deptId === 0;        // 完全沒有職務紀錄的人，就不強制部門
    if ($deptId === 0) return false;
    foreach ($posts as $p) if ($p['dept_id'] === $deptId) return true;
    return false;
}

function ec_can_sign_review(PDO $db, array $row, string $unitKey, int $uid, bool $isAdmin): bool
{
    if ($uid <= 0) return false;
    if ($isAdmin) return true;
    $s = ec_review_signer($db, $row, $unitKey);
    return $s['id'] > 0 && (int)$s['id'] === $uid;
}

/* ============================ 確認庫存：系統自動帶入 ============================ */

/**
 * 這個料號目前的「庫存數量」與「已完工待入庫數量」（使用者要求 2026-08-25：
 * 倉管那一關要自動帶入讓倉管**確認是否相同**，數字仍可手動修改）。
 *
 * ★ stock_items 的欄位命名是**反的**（很容易寫錯）：
 *     stock_items.d_id        = 料號**字串**（varchar，例 447-000C-820-18）
 *     stock_items.d_setting_id= d_setting.d_id（數字）
 *   跟 d_setting 表剛好相反，所以兩邊都比對才不會漏。
 *
 * 已完工待入庫的定義：**BOM 還沒結案（closed_at IS NULL）、但最後一道製程已經完工
 * （bom_ing.qc_completed=1 且 processing_sequence 是該 BOM 的最大值）**＝東西做完了、還沒進倉。
 * 不能用「所有製程都完工」判定——現場只在最後一道打完工勾，全庫這樣算只有 4 個 BOM 符合，
 * 帶出來永遠是 0 就失去意義了（實測：本定義 247 批 / 23482 件，全部完工定義 4 批）。
 *
 * @return array{stock_qty:int, stock_rows:int, wip_qty:int, wip_boms:int, as_of:string}
 */
function ec_stock_snapshot(PDO $db, int $dId, string $partNo): array
{
    $out = ['stock_qty' => 0, 'stock_rows' => 0, 'wip_qty' => 0, 'wip_boms' => 0,
            'as_of' => ec_db_now($db)['dt']];
    if ($dId <= 0 && trim($partNo) === '') return $out;
    try {
        $st = $db->prepare("SELECT COALESCE(SUM(si.qty),0) qty, COUNT(*) n
                              FROM stock_items si
                             WHERE si.is_active=1 AND (si.group_id IS NULL OR si.group_id=0)
                               AND (si.d_setting_id = ? OR si.d_id = ?)");
        $st->execute([$dId, $partNo]);
        if ($r = $st->fetch(PDO::FETCH_ASSOC)) {
            $out['stock_qty']  = (int)$r['qty'];
            $out['stock_rows'] = (int)$r['n'];
        }
    } catch (Throwable $e) {}
    try {
        $st = $db->prepare("SELECT COALESCE(SUM(b.sqty),0) qty, COUNT(*) n
                              FROM bom b
                             WHERE b.closed_at IS NULL
                               AND (b.d_setting_id = ? OR b.d_id = ?)
                               AND EXISTS (SELECT 1 FROM bom_ing bi
                                            WHERE bi.bom = b.bom AND bi.qc_completed = 1
                                              AND bi.processing_sequence =
                                                  (SELECT MAX(bi2.processing_sequence) FROM bom_ing bi2 WHERE bi2.bom = b.bom))");
        $st->execute([$dId, $partNo]);
        if ($r = $st->fetch(PDO::FETCH_ASSOC)) {
            $out['wip_qty']  = (int)$r['qty'];
            $out['wip_boms'] = (int)$r['n'];
        }
    } catch (Throwable $e) {}
    return $out;
}

/* ============================ 流程 ============================ */

/** 目前這張單卡在哪一關（回 stage key；已結案／退回回空字串） */
function ec_current_stage(array $row): string
{
    $s = (string)($row['status'] ?? 'DRAFT');
    return isset(EC_STAGES[$s]) ? $s : '';
}

/**
 * 這一關簽完之後接下來是哪一關。
 * 技術課判定「僅修改圖面（修改後結案）」時跳過會審關卡直接到管制；
 * 判定「需修改圖面與會審」才會走會審那一段（紙本：↓以下僅技術課判定需會審才填寫↓）。
 */
function ec_next_stage(array $row, string $stage): string
{
    $order = array_keys(EC_STAGES);
    $i = array_search($stage, $order, true);
    // 不在關卡清單裡（DRAFT／REJECTED／打錯字）＝還沒進流程，下一關就是第一關。
    // 這裡絕對不能預設回 'CLOSED'——一個打錯的關卡名會讓整張單直接結案。
    if ($i === false) $i = -1;
    for ($j = $i + 1; $j < count($order); $j++) {
        $next = $order[$j];
        if ($next === 'REVIEW' && (string)($row['design_result'] ?? '') !== 'need_review') continue;
        return $next;
    }
    return 'CLOSED';
}

/** 送出前必填檢查；回 [欄位key => 原因]，空陣列＝通過。前端即時擋、後端同一套再擋（鐵律8） */
function ec_validate(PDO $db, array $r): array
{
    $e = [];
    if (trim((string)($r['apply_date'] ?? '')) === '')      $e['apply_date'] = '請填寫日期';
    if (trim((string)($r['part_no'] ?? '')) === '')          $e['part_no'] = '請填寫料號';
    if (trim((string)($r['customer_name'] ?? '')) === '')    $e['customer_name'] = '請填寫客戶名稱（綁定料號後會自動帶出）';
    if (!(int)($r['apply_dept_id'] ?? 0))                    $e['apply_dept_id'] = '請選擇申請單位';
    if (!(int)($r['applicant_id'] ?? 0))                     $e['applicant_id'] = '請選擇申請人';
    elseif (!ec_valid_applicant_post($db, (int)$r['applicant_id'], (int)($r['apply_dept_id'] ?? 0),
                                     (string)($r['apply_date'] ?? '')))
        $e['apply_dept_id'] = '申請人在這個日期並沒有掛在所選的申請單位底下，請重新選擇職務';
    $ct = (string)($r['change_type'] ?? '');
    if (!array_key_exists($ct, EC_CHANGE_TYPES))             $e['change_type'] = '請選擇變更方式';
    // 紙本明文：「(僅其他變更須填寫) 設變事由說明」
    if ($ct === 'other' && trim((string)($r['change_reason'] ?? '')) === '')
        $e['change_reason'] = '變更方式選「其他變更」時，必須在設變事由說明內詳述變更原因';
    return $e;
}

/** 各關卡簽核前的必填檢查（那一關自己要填的欄位沒填完就不給簽） */
function ec_validate_stage(array $r, string $stage): array
{
    $e = [];
    if ($stage === 'WH') {
        if (trim((string)($r['stock_qty'] ?? '')) === '') $e['stock_qty'] = '請填寫庫存數量';
        if (trim((string)($r['wip_qty'] ?? '')) === '')   $e['wip_qty'] = '請填寫已完工待入庫數量';
    } elseif ($stage === 'TD') {
        if (!array_key_exists((string)($r['design_result'] ?? ''), EC_DESIGN_RESULTS))
            $e['design_result'] = '請選擇設計分析結果';
        if (!array_key_exists((string)($r['old_stock'] ?? ''), EC_OLD_STOCK))
            $e['old_stock'] = '請選擇庫存舊料可否修改';
    } elseif ($stage === 'APPROVE') {
        if (!array_key_exists((string)($r['verdict'] ?? ''), EC_VERDICTS))
            $e['verdict'] = '請選擇核示結果';
        if ((string)($r['verdict'] ?? '') === 'other' && trim((string)($r['verdict_other'] ?? '')) === '')
            $e['verdict_other'] = '核示選「其他」時請填寫內容';
    } elseif ($stage === 'CTRL') {
        if (!(int)($r['ctrl_drawing'] ?? 0) && !(int)($r['ctrl_bom'] ?? 0) && !(int)($r['ctrl_manual'] ?? 0))
            $e['ctrl'] = '請至少勾選一項需修改的文件資料（圖面／BOM／操作手冊）';
    }
    return $e;
}

/* ============================ 通知（ai-rules/17） ============================ */

function ec_notify_stage(PDO $db, array $row, string $stage, int $toUid, int $fromUid): int
{
    if ($toUid <= 0 || $toUid === $fromUid) return 0;
    $label = EC_STAGES[$stage]['label'] ?? $stage;
    $title = '工程變更申請單待簽核（' . $label . '）：' . (string)$row['doc_no'] . '　料號 ' . (string)$row['part_no'];
    $content = ec_notify_body($row) . "\n本關卡：" . $label . "\n點此開啟簽核，可直接核准或退回（退回須填原因）。";
    return ec_push_event($db, 'ENG_CHANGE_APPROVAL', (int)$row['ec_id'], $title, $content, $toUid, $fromUid, 'sign');
}

function ec_notify_review(PDO $db, array $row, string $unitKey, int $toUid, int $fromUid): int
{
    if ($toUid <= 0 || $toUid === $fromUid) return 0;
    $label = EC_REVIEW_UNITS[$unitKey]['label'] ?? $unitKey;
    $title = '工程變更通知單待會審（' . $label . '）：' . (string)$row['doc_no'] . '　料號 ' . (string)$row['part_no'];
    $content = ec_notify_body($row) . "\n會審單位：" . $label
             . "\n點此開啟會審，勾選收到／已修改後填寫意見（意見非必填）並簽名。";
    return ec_push_event($db, 'ENG_CHANGE_REVIEW', (int)$row['ec_id'], $title, $content, $toUid, $fromUid, 'sign');
}

function ec_notify_result(PDO $db, array $row, int $toUid, string $text, int $fromUid): int
{
    if ($toUid <= 0) return 0;
    $title = '工程變更申請單：' . (string)$row['doc_no'] . '　料號 ' . (string)$row['part_no'];
    return ec_push_event($db, 'ENG_CHANGE_RESULT', (int)$row['ec_id'], $title, $text, $toUid, $fromUid, 'read');
}

/** 通知內容主體：ai-rules/17 要求「內容完整可看」，不能只丟一個單號要人自己去查 */
function ec_notify_body(array $row): string
{
    $lines = [
        '文件編號：' . (string)$row['doc_no'],
        '日期：' . eg_fmt_date((string)$row['apply_date']),
        '客戶：' . (string)$row['customer_name'] . '　料號：' . (string)$row['part_no'],
        '申請單位：' . (string)$row['apply_dept_name'] . '　申請人：' . (string)$row['applicant_name'],
        '變更方式：' . (EC_CHANGE_TYPES[(string)$row['change_type']] ?? '（未選）'),
    ];
    if (trim((string)$row['change_reason']) !== '') $lines[] = '設變事由：' . (string)$row['change_reason'];
    if (trim((string)$row['stock_qty']) !== '' || trim((string)$row['wip_qty']) !== '')
        $lines[] = '庫存數量：' . (string)$row['stock_qty'] . '　已完工待入庫：' . (string)$row['wip_qty'];
    if ((string)$row['design_result'] !== '')
        $lines[] = '設計分析：' . (EC_DESIGN_RESULTS[(string)$row['design_result']] ?? '');
    if ((string)$row['old_stock'] !== '')
        $lines[] = '庫存舊料：' . (EC_OLD_STOCK[(string)$row['old_stock']] ?? '');
    if ((string)$row['verdict'] !== '') {
        $v = EC_VERDICTS[(string)$row['verdict']] ?? '';
        if ((string)$row['verdict'] === 'other') $v .= '（' . (string)$row['verdict_other'] . '）';
        $lines[] = '核示：' . $v;
    }
    return implode("\n", $lines);
}

function ec_push_event(PDO $db, string $refType, int $refId, string $title, string $content,
                       int $toUid, int $fromUid, string $mode): int
{
    // live_event.title 是 varchar(100)，料號長一點就會超過 → 先自己截，不要交給 MySQL 決定
    $title = mb_substr($title, 0, 100, 'UTF-8');
    try {
        $db->prepare("INSERT INTO live_event (eventdate, enddate, title, content, status, created_by, source,
                                              show_status_to_others, ref_type, ref_id)
                      VALUES (CURDATE(), NULL, ?, ?, 0, ?, '工程變更申請單', 1, ?, ?)")
           ->execute([$title, $content, $fromUid, $refType, $refId]);
        $eid = (int)$db->lastInsertId();
        $db->prepare("INSERT INTO live_event_target (live_event_id, target_type, target_id, mode) VALUES (?, 'user', ?, ?)")
           ->execute([$eid, $toUid, $mode]);
        try {
            require_once __DIR__ . '/../push/push_send.php';
            eg_push_send_to_users($db, eg_push_event_recipients($db, $eid), ['title' => $title, 'body' => mb_substr($content, 0, 480)]);
        } catch (Throwable $e) {}
        return $eid;
    } catch (Throwable $e) { return 0; }
}

/** 關掉某張單還開著的待辦通知（換關卡／結案／退回時呼叫，免得舊通知一直掛在那裡） */
function ec_close_notices(PDO $db, int $ecId, array $refTypes = ['ENG_CHANGE_APPROVAL', 'ENG_CHANGE_REVIEW']): void
{
    foreach ($refTypes as $t) {
        try {
            $db->prepare("UPDATE live_event SET enddate=DATE_SUB(CURDATE(), INTERVAL 1 DAY)
                          WHERE ref_type=? AND ref_id=? AND (enddate IS NULL OR enddate>=CURDATE())")
               ->execute([$t, $ecId]);
        } catch (Throwable $e) {}
    }
}

/* ============================ 寫入 ============================ */

/** 建立一張草稿；回 ec_id */
function ec_create(PDO $db, array $p, int $uid, string $uname): int
{
    ec_ensure_schema($db);
    $now  = ec_db_now($db);
    $date = preg_match('/^\d{4}-\d{2}-\d{2}$/', (string)($p['apply_date'] ?? '')) ? (string)$p['apply_date'] : $now['d'];
    $docNo = ec_next_doc_no($db, $date);
    $db->prepare("INSERT INTO eng_change
        (doc_no, apply_date, customer_id, customer_name, d_id, part_no,
         apply_dept_id, apply_dept_name, applicant_id, applicant_name,
         change_type, change_reason, source_change_id, create_source,
         status, created_by, created_by_name, created_at)
        VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,'DRAFT',?,?,NOW())")
       ->execute([
            $docNo, $date,
            (trim((string)($p['customer_id'] ?? '')) ?: null), trim((string)($p['customer_name'] ?? '')),
            ((int)($p['d_id'] ?? 0)) ?: null, trim((string)($p['part_no'] ?? '')),
            ((int)($p['apply_dept_id'] ?? 0)) ?: null, trim((string)($p['apply_dept_name'] ?? '')),
            ((int)($p['applicant_id'] ?? 0)) ?: null, trim((string)($p['applicant_name'] ?? '')),
            trim((string)($p['change_type'] ?? '')), trim((string)($p['change_reason'] ?? '')),
            ((int)($p['source_change_id'] ?? 0)) ?: null,
            in_array((string)($p['create_source'] ?? ''), ['dwg'], true) ? 'dwg' : 'manual',
            $uid ?: null, $uname,
       ]);
    return (int)$db->lastInsertId();
}

/**
 * 送出草稿＝正式成立：文件編號依日期重編、通知第一關（單位主管）、寫 approval_record。
 * 申請人那一格的章在這時候蓋（＝送出的人就是申請人）。
 */
function ec_submit(PDO $db, int $ecId, int $uid, string $uname): array
{
    ec_ensure_schema($db);
    $row = ec_row($db, $ecId);
    if (!$row) throw new Exception('查無此申請單');
    if ((string)$row['status'] !== 'DRAFT') throw new Exception('這張申請單已經送出過了');
    $err = ec_validate($db, $row);
    if ($err) throw new Exception('還有必填欄位沒填完：' . implode('、', array_values($err)));

    $now = ec_db_now($db);
    $db->beginTransaction();
    try {
        ec_sync_doc_no($db, $ecId);
        // 申請人的章：蓋「這張單上填的申請人」，不是按下送出的人
        //（管理員代開歷史單時，章要蓋當初真正提出的人）
        $applicantId = (int)$row['applicant_id'];
        $ap = ec_apply_delegate($db, $applicantId, (string)$row['applicant_name']);
        $db->prepare("UPDATE eng_change SET status='SUP', submitted_at=NOW(),
                        sign_applicant_id=?, sign_applicant_name=?, sign_applicant_for_id=?, sign_applicant_at=NOW(),
                        updated_by=?, updated_at=NOW() WHERE ec_id=?")
           ->execute([$ap['id'] ?: null, $ap['name'], $ap['for_id'] ?: null, $uid ?: null, $ecId]);
        $db->commit();
    } catch (Throwable $e) {
        if ($db->inTransaction()) $db->rollBack();
        throw $e;
    }

    $row = ec_row($db, $ecId);
    ec_route_to_stage($db, $row, 'SUP', $uid, $uname);
    return ['ec_id' => $ecId, 'doc_no' => (string)$row['doc_no'], 'status' => 'SUP'];
}

/** 把單子推到某一關：寫一筆待簽核紀錄（approval_record）＋發通知 */
function ec_route_to_stage(PDO $db, array $row, string $stage, int $fromUid, string $fromName): void
{
    $ecId = (int)$row['ec_id'];
    ec_close_notices($db, $ecId, ['ENG_CHANGE_APPROVAL']);
    if ($stage === 'REVIEW') { ec_route_to_review($db, $row, $fromUid, $fromName); return; }
    if (!isset(EC_STAGES[$stage])) return;

    // 一筆待簽核紀錄對應這一關；名單有多人時每個人都發通知（誰先簽就算誰的，OR-gate）
    $pool = ec_stage_signer_pool($db, $row, $stage);
    try {
        $aid = eg_approval_submit($db, EC_APPROVAL_MODULE, $ecId, $stage, $fromUid, $fromName);
        $firstEid = 0;
        foreach ($pool as $p) {
            $eid = ec_notify_stage($db, $row, $stage, (int)$p['id'], $fromUid);
            if ($eid && !$firstEid) $firstEid = $eid;
        }
        if ($firstEid) eg_approval_set_live_event($db, $aid, $firstEid);
    } catch (Throwable $e) {}
}

/** 進入會審關卡：只通知技術課勾選為「需會審」的單位 */
function ec_route_to_review(PDO $db, array $row, int $fromUid, string $fromName): void
{
    $ecId = (int)$row['ec_id'];
    foreach (ec_review_rows($db, $ecId) as $r) {
        if (!$r['needed'] || $r['signed_at']) continue;
        $s = ec_review_signer($db, $row, (string)$r['unit_key']);
        try {
            $aid = eg_approval_submit($db, EC_APPROVAL_MODULE, $ecId, 'REVIEW:' . $r['unit_key'], $fromUid, $fromName);
            $eid = ec_notify_review($db, $row, (string)$r['unit_key'], (int)$s['id'], $fromUid);
            if ($eid) eg_approval_set_live_event($db, $aid, $eid);
        } catch (Throwable $e) {}
    }
}

/**
 * 簽掉某一關並往下推。
 * $fields＝這一關自己要填的欄位（例：倉管的庫存數量、技術的設計分析），先存再驗再簽。
 */
function ec_sign_stage(PDO $db, int $ecId, string $stage, int $uid, string $uname, array $fields = []): array
{
    ec_ensure_schema($db);
    $row = ec_row($db, $ecId);
    if (!$row) throw new Exception('查無此申請單');
    if ((string)$row['status'] !== $stage) {
        throw new Exception('這張單目前在「' . (EC_STAGES[(string)$row['status']]['label'] ?? (string)$row['status'])
                          . '」關卡，不是「' . (EC_STAGES[$stage]['label'] ?? $stage) . '」——請重新整理後再試');
    }
    $def = EC_STAGES[$stage] ?? null;
    if (!$def) throw new Exception('無效的關卡');

    $row = array_merge($row, $fields);
    $err = ec_validate_stage($row, $stage);
    if ($err) throw new Exception(implode('、', array_values($err)));

    // 蓋誰的章：
    //   ① 操作者本人就在合格名單裡 → 蓋他自己的（管制員指定多人時，誰簽就蓋誰）
    //   ② 不在名單裡（管理員代簽補歷史紙本）→ 蓋「這一關本來該簽的人」
    //   ③ 名單是空的（組織角色沒綁好）→ 退回操作者本人，至少留得下紀錄
    $pool   = ec_stage_signer_pool($db, ec_row($db, $ecId), $stage);
    $signer = null;
    foreach ($pool as $p) { if ((int)$p['id'] === $uid) { $signer = $p; break; } }
    $signer = $signer ?: ($pool[0] ?? ['id' => 0, 'name' => '', 'for_id' => 0]);
    $signId   = $signer['id'] ?: $uid;
    $signName = $signer['name'] !== '' ? $signer['name'] : $uname;
    $forId    = (int)$signer['for_id'];

    $now = ec_db_now($db);
    $db->beginTransaction();
    try {
        $sets = []; $args = [];
        foreach (ec_stage_editable_fields($stage) as $f) {
            if (!array_key_exists($f, $fields)) continue;
            $sets[] = "`$f`=?";
            $args[] = in_array($f, ['ctrl_drawing', 'ctrl_bom', 'ctrl_manual'], true)
                    ? ((int)$fields[$f] ? 1 : 0) : (string)$fields[$f];
        }
        $k = $def['sign_key'];
        $sets[] = "sign_{$k}_id=?";      $args[] = $signId ?: null;
        $sets[] = "sign_{$k}_name=?";    $args[] = $signName;
        $sets[] = "sign_{$k}_for_id=?";  $args[] = $forId ?: null;
        $sets[] = "sign_{$k}_at=NOW()";

        // 這一關填完之後才算得出下一關（技術課選了「僅修改圖面」就要跳過會審）
        $after = array_merge(ec_row($db, $ecId), $fields);
        $next  = ec_next_stage($after, $stage);
        $sets[] = "status=?";            $args[] = $next;
        if ($next === 'CLOSED') $sets[] = "closed_at=NOW()";
        $sets[] = "updated_by=?";        $args[] = $uid ?: null;
        $sets[] = "updated_at=NOW()";
        $args[] = $ecId;
        $db->prepare("UPDATE eng_change SET " . implode(',', $sets) . " WHERE ec_id=?")->execute($args);
        $db->commit();
    } catch (Throwable $e) {
        if ($db->inTransaction()) $db->rollBack();
        throw $e;
    }

    // 簽核事實寫進全站共用的 approval_record（ai-rules/23）
    try {
        $rec = eg_approval_latest($db, EC_APPROVAL_MODULE, $ecId, $stage);
        if ($rec && (string)$rec['status'] === 'pending')
            eg_approval_decide($db, (int)$rec['id'], $uid, $uname, 'approved', null);
    } catch (Throwable $e) {}

    $row2 = ec_row($db, $ecId);
    $next = (string)$row2['status'];
    if ($next === 'CLOSED') {
        ec_close_notices($db, $ecId);
        ec_notify_result($db, $row2, (int)$row2['applicant_id'],
            ec_notify_body($row2) . "\n\n此工程變更申請單已全部簽核完成、結案。", $uid);
    } else {
        ec_route_to_stage($db, $row2, $next, $uid, $uname);
    }
    return ['ec_id' => $ecId, 'status' => $next];
}

/**
 * 提早填寫：只把該關卡白名單內的欄位寫進去，**不簽核、不推進狀態、不蓋章**。
 * 權限由呼叫端先用 ec_can_prefill_stage() 判過。
 */
function ec_save_stage_fields(PDO $db, int $ecId, string $stage, array $fields, int $uid): array
{
    ec_ensure_schema($db);
    $allow = ec_stage_editable_fields($stage);
    if (!$allow) throw new Exception('這一關沒有可以填寫的欄位');
    $sets = []; $args = [];
    foreach ($allow as $f) {
        if (!array_key_exists($f, $fields)) continue;
        $sets[] = "`$f`=?";
        $args[] = in_array($f, ['ctrl_drawing', 'ctrl_bom', 'ctrl_manual'], true)
                ? ((int)$fields[$f] ? 1 : 0) : (string)$fields[$f];
    }
    if (!$sets) return ['saved' => 0];
    $sets[] = "updated_by=?"; $args[] = $uid ?: null;
    $sets[] = "updated_at=NOW()";
    $args[] = $ecId;
    $db->prepare("UPDATE eng_change SET " . implode(',', $sets) . " WHERE ec_id=?")->execute($args);
    return ['saved' => count($sets) - 2];
}

/** 各關卡可以編輯的欄位（其他欄位就算前端硬送也不會被寫入＝鐵律8） */
function ec_stage_editable_fields(string $stage): array
{
    switch ($stage) {
        case 'WH':      return ['stock_qty', 'wip_qty'];
        case 'TD':      return ['design_result', 'design_note', 'old_stock'];
        case 'APPROVE': return ['verdict', 'verdict_other', 'verdict_note'];
        case 'CTRL':    return ['ctrl_drawing', 'ctrl_bom', 'ctrl_manual'];
        default:        return [];
    }
}

/** 退回：停在該關卡並通知申請人，必須填原因（ai-rules/17） */
function ec_reject(PDO $db, int $ecId, string $stage, int $uid, string $uname, string $reason): array
{
    ec_ensure_schema($db);
    $reason = trim($reason);
    if ($reason === '') throw new Exception('退回時必須填寫原因');
    $row = ec_row($db, $ecId);
    if (!$row) throw new Exception('查無此申請單');
    if ((string)$row['status'] !== $stage) throw new Exception('這張單目前不在這個關卡，請重新整理後再試');

    $db->prepare("UPDATE eng_change SET status='REJECTED', reject_stage=?, reject_reason=?,
                    updated_by=?, updated_at=NOW() WHERE ec_id=?")
       ->execute([$stage, $reason, $uid ?: null, $ecId]);
    try {
        $rec = eg_approval_latest($db, EC_APPROVAL_MODULE, $ecId, $stage);
        if ($rec && (string)$rec['status'] === 'pending')
            eg_approval_decide($db, (int)$rec['id'], $uid, $uname, 'rejected', $reason);
    } catch (Throwable $e) {}
    ec_close_notices($db, $ecId);
    $row = ec_row($db, $ecId);
    ec_notify_result($db, $row, (int)$row['applicant_id'],
        ec_notify_body($row) . "\n\n【已退回】關卡：" . (EC_STAGES[$stage]['label'] ?? $stage)
        . "　退回人：" . $uname . "\n退回原因：" . $reason . "\n請修正後重新送出。", $uid);
    return ['ec_id' => $ecId, 'status' => 'REJECTED'];
}

/** 退回後修正完重新送出（回到第一關） */
function ec_resubmit(PDO $db, int $ecId, int $uid, string $uname): array
{
    $row = ec_row($db, $ecId);
    if (!$row) throw new Exception('查無此申請單');
    if ((string)$row['status'] !== 'REJECTED') throw new Exception('只有被退回的申請單才需要重新送出');
    $err = ec_validate($db, $row);
    if ($err) throw new Exception('還有必填欄位沒填完：' . implode('、', array_values($err)));
    $db->prepare("UPDATE eng_change SET status='SUP', reject_stage=NULL, reject_reason=NULL,
                    updated_by=?, updated_at=NOW() WHERE ec_id=?")->execute([$uid ?: null, $ecId]);
    $row = ec_row($db, $ecId);
    ec_route_to_stage($db, $row, 'SUP', $uid, $uname);
    return ['ec_id' => $ecId, 'status' => 'SUP'];
}

/** 技術課勾選哪些單位要會審（在 TD 關卡簽核前設定） */
function ec_set_review_units(PDO $db, int $ecId, array $unitKeys): void
{
    ec_ensure_schema($db);
    $keys = array_values(array_intersect(array_map('strval', $unitKeys), array_keys(EC_REVIEW_UNITS)));
    $up = $db->prepare("INSERT INTO eng_change_review (ec_id, unit_key, dept_id, needed) VALUES (?,?,?,?)
                        ON DUPLICATE KEY UPDATE needed=VALUES(needed), dept_id=VALUES(dept_id)");
    foreach (EC_REVIEW_UNITS as $k => $def) {
        $ids = eg_org_dept_ids($db, $def['org']);
        $up->execute([$ecId, $k, $ids ? (int)$ids[0] : null, in_array($k, $keys, true) ? 1 : 0]);
    }
}

/** 某個會審單位填寫並簽名；全部需會審的單位都簽完才往下一關（管制員） */
function ec_sign_review(PDO $db, int $ecId, string $unitKey, int $uid, string $uname, array $p): array
{
    ec_ensure_schema($db);
    if (!isset(EC_REVIEW_UNITS[$unitKey])) throw new Exception('無效的會審單位');
    $row = ec_row($db, $ecId);
    if (!$row) throw new Exception('查無此申請單');
    if ((string)$row['status'] !== 'REVIEW') throw new Exception('這張單目前不在會審關卡，請重新整理後再試');

    $def = EC_REVIEW_UNITS[$unitKey];
    $checks = []; $extras = [];
    foreach (array_keys($def['checks']) as $c) $checks[$c] = !empty($p['checks'][$c]) ? 1 : 0;
    foreach (array_keys($def['extras']) as $x) $extras[$x] = mb_substr(trim((string)($p['extras'][$x] ?? '')), 0, 100, 'UTF-8');
    $opinion = mb_substr(trim((string)($p['opinion'] ?? '')), 0, 1000, 'UTF-8');

    $s = ec_review_signer($db, $row, $unitKey);
    $signId   = $s['id'] ?: $uid;
    $signName = $s['name'] !== '' ? $s['name'] : $uname;

    $db->prepare("INSERT INTO eng_change_review (ec_id, unit_key, needed, checks_json, extras_json, opinion,
                                                 signer_id, signer_name, signer_for_id, signed_at)
                  VALUES (?,?,1,?,?,?,?,?,?,NOW())
                  ON DUPLICATE KEY UPDATE checks_json=VALUES(checks_json), extras_json=VALUES(extras_json),
                                          opinion=VALUES(opinion), signer_id=VALUES(signer_id),
                                          signer_name=VALUES(signer_name), signer_for_id=VALUES(signer_for_id),
                                          signed_at=VALUES(signed_at)")
       ->execute([$ecId, $unitKey, json_encode($checks, JSON_UNESCAPED_UNICODE),
                  json_encode($extras, JSON_UNESCAPED_UNICODE), $opinion,
                  $signId ?: null, $signName, ((int)$s['for_id']) ?: null]);
    try {
        $rec = eg_approval_latest($db, EC_APPROVAL_MODULE, $ecId, 'REVIEW:' . $unitKey);
        if ($rec && (string)$rec['status'] === 'pending')
            eg_approval_decide($db, (int)$rec['id'], $uid, $uname, 'approved', $opinion !== '' ? $opinion : null);
    } catch (Throwable $e) {}

    // 需會審的單位全簽完了嗎？
    $pending = 0;
    foreach (ec_review_rows($db, $ecId) as $r) if ($r['needed'] && !$r['signed_at']) $pending++;
    if ($pending === 0) {
        $db->prepare("UPDATE eng_change SET status='CTRL', updated_by=?, updated_at=NOW() WHERE ec_id=?")
           ->execute([$uid ?: null, $ecId]);
        ec_close_notices($db, $ecId, ['ENG_CHANGE_REVIEW']);
        $row2 = ec_row($db, $ecId);
        ec_route_to_stage($db, $row2, 'CTRL', $uid, $uname);
        return ['ec_id' => $ecId, 'status' => 'CTRL', 'pending' => 0];
    }
    return ['ec_id' => $ecId, 'status' => 'REVIEW', 'pending' => $pending];
}

/* ============================ 由圖面變更單自動產生 ============================ */

/**
 * 圖面變更紀錄送出時，若「變更來源＝客戶」就自動建一張工程變更申請單草稿。
 *
 * 使用者拍板的判定（2026-08-25）：
 *   ① 已經有工程變更單時，以**客戶版次**或**客戶圖面日期**為判定標準——
 *      同料號已有一張單的客戶版次與這次相同、或客戶圖面日期（＝廠內版次的發行章日期）相同，
 *      就視為同一次變更，不重複開單、直接回傳那一張。
 *   ② 兩者都沒有（客戶圖多半沒有版次，見 ai-rules/15）時，由建立者認定有變更＝照開，
 *      但一律建成**草稿**等人確認，不直接送進簽核流程。
 *
 * @return array{ok:bool, ec_id:int, doc_no:string, existed:bool, message:string}
 */
function ec_auto_from_dwg_change(PDO $db, int $changeId, int $uid, string $uname): array
{
    ec_ensure_schema($db);
    $fail = fn($m) => ['ok' => false, 'ec_id' => 0, 'doc_no' => '', 'existed' => false, 'message' => $m];
    try {
        $st = $db->prepare("SELECT * FROM qc_drawing_change WHERE id=?");
        $st->execute([$changeId]);
        $c = $st->fetch(PDO::FETCH_ASSOC);
    } catch (Throwable $e) { return $fail('讀取圖面變更紀錄失敗'); }
    if (!$c) return $fail('查無此圖面變更紀錄');

    // 同一張圖面變更單只會產生一張工程變更單（按兩次不該長出兩張）
    try {
        $q = $db->prepare("SELECT ec_id, doc_no FROM eng_change WHERE source_change_id=? LIMIT 1");
        $q->execute([$changeId]);
        if ($old = $q->fetch(PDO::FETCH_ASSOC)) {
            return ['ok' => true, 'ec_id' => (int)$old['ec_id'], 'doc_no' => (string)$old['doc_no'],
                    'existed' => true, 'message' => '這筆圖面變更已經有工程變更申請單 ' . $old['doc_no'] . '，直接開啟該筆。'];
        }
    } catch (Throwable $e) {}

    $dId = (int)$c['d_id'];
    $info = ['part_no' => '', 'customer' => '', 'customer_id' => ''];
    try {
        $st = $db->prepare("SELECT s.D_Setting_Id, s.Customer_Id, cl.customer
                              FROM d_setting s LEFT JOIN customer_list cl ON cl.customer_id=s.Customer_Id
                             WHERE s.d_id=?");
        $st->execute([$dId]);
        if ($r = $st->fetch(PDO::FETCH_ASSOC)) {
            $info = ['part_no' => (string)$r['D_Setting_Id'], 'customer' => (string)($r['customer'] ?? ''),
                     'customer_id' => (string)($r['Customer_Id'] ?? '')];
        }
    } catch (Throwable $e) {}

    // ① 已有工程變更單時的判定：客戶版次 或 客戶圖面日期（＝廠內版次的發行章日期）
    $newRev  = trim((string)$c['new_revision']);
    $newDate = trim((string)$c['int_new_revision']);
    if ($newRev !== '' || $newDate !== '') {
        try {
            $sql = "SELECT e.ec_id, e.doc_no FROM eng_change e
                      LEFT JOIN qc_drawing_change q ON q.id = e.source_change_id
                     WHERE e.d_id = ? AND e.status <> 'REJECTED' AND (";
            $ors = []; $args = [$dId];
            if ($newRev !== '')  { $ors[] = "q.new_revision = ?";     $args[] = $newRev; }
            if ($newDate !== '') { $ors[] = "q.int_new_revision = ?"; $args[] = $newDate; }
            $sql .= implode(' OR ', $ors) . ") ORDER BY e.ec_id ASC LIMIT 1";
            $q2 = $db->prepare($sql); $q2->execute($args);
            if ($old = $q2->fetch(PDO::FETCH_ASSOC)) {
                $why = $newRev !== '' ? ('客戶版次 ' . $newRev) : ('客戶圖面日期 ' . eg_fmt_date($newDate));
                return ['ok' => true, 'ec_id' => (int)$old['ec_id'], 'doc_no' => (string)$old['doc_no'], 'existed' => true,
                        'message' => '此料號已有同一次變更（' . $why . '）的工程變更申請單 ' . $old['doc_no'] . '，不重複開立。'];
            }
        } catch (Throwable $e) {}
    }

    // ② 判定不出來（客戶圖常常沒有版次也沒有日期）→ 由建立者認定有變更，照開草稿
    $idt = ec_user_identity_asof($db, $uid, (string)($c['change_date'] ?: ''));
    $ecId = ec_create($db, [
        'apply_date'       => (string)($c['change_date'] ?: ec_db_now($db)['d']),
        'customer_id'      => $info['customer_id'],   // 字串，不可轉 int（見 ec_ensure_schema）
        'customer_name'    => $info['customer'],
        'd_id'             => $dId,
        'part_no'          => $info['part_no'],
        'apply_dept_id'    => (int)($idt['dept_id'] ?? 0),
        'apply_dept_name'  => (string)($idt['dept_name'] ?? ''),
        'applicant_id'     => $uid,
        'applicant_name'   => $idt['user_name'] !== '' ? $idt['user_name'] : $uname,
        // 圖面變更來源＝客戶才會走到這裡，所以變更方式預設「客戶通知變更」
        'change_type'      => 'customer_notify',
        'change_reason'    => trim((string)$c['summary']),
        'source_change_id' => $changeId,
        'create_source'    => 'dwg',
    ], $uid, $uname);
    $row = ec_row($db, $ecId);
    return ['ok' => true, 'ec_id' => $ecId, 'doc_no' => (string)$row['doc_no'], 'existed' => false,
            'message' => '已依圖面變更 ' . (string)$c['change_no'] . ' 自動建立工程變更申請單草稿 '
                       . (string)$row['doc_no'] . '（客戶／料號／變更摘要已帶入），請確認內容後送出。'];
}

/* ============================ 列印用資料 ============================ */

/**
 * 列印一張單需要的全部資料（表頭公司全名、AS 編號與版次、各格簽章人與職稱）。
 * 版次依業務日期回推當時生效的那一版（ai-rules/16 第三之四節）。
 */
function ec_print_meta(PDO $db, array $row): array
{
    $date = (string)$row['apply_date'];
    $company = '';
    try {
        $company = (string)$db->query("SELECT customer_full FROM customer_list WHERE is_own_company=1 LIMIT 1")->fetchColumn();
    } catch (Throwable $e) {}

    // 表頭表單名稱一律取自綁定的 AS 文件 doc_name（禁寫死＝ai-rules/16）；
    // 頁尾右下角的編號依業務日期回推當時生效的版次（ai-rules/16 第三之四節）。
    $doc = eg_asdoc_get($db, EC_ASDOC_MODULE);
    $docName = $doc ? (string)$doc['doc_name'] : '';
    $docNo   = '';
    try { $docNo = (string)eg_asdoc_no_asof($db, EC_ASDOC_MODULE, $date); } catch (Throwable $e) {}

    // 各格簽章：姓名＋當時的部門職稱（圖章要印職稱，一律依業務日期回推＝ai-rules/22）
    $signs = [];
    foreach (['applicant' => '申請人'] + array_map(fn($s) => $s['label'], EC_STAGES) as $k => $label) {
        $key = $k === 'applicant' ? 'applicant' : (EC_STAGES[$k]['sign_key'] ?? '');
        if ($key === '') continue;                       // REVIEW 沒有單一簽章格
        $uid = (int)($row['sign_' . $key . '_id'] ?? 0);
        if (!$uid) { $signs[$key] = null; continue; }
        $idt = ec_user_identity_asof($db, $uid, $date);
        $signs[$key] = [
            'label'    => $label,
            'user_id'  => $uid,
            'name'     => (string)($row['sign_' . $key . '_name'] ?? $idt['user_name']),
            'dept'     => (string)$idt['dept_name'],
            'position' => (string)$idt['position_name'],
            'date'     => substr((string)($row['sign_' . $key . '_at'] ?? ''), 0, 10),
            'is_agent' => (int)($row['sign_' . $key . '_for_id'] ?? 0) > 0 ? 1 : 0,
        ];
    }
    // 會審各單位的簽章
    $reviewSigns = [];
    foreach (ec_review_rows($db, (int)$row['ec_id']) as $r) {
        if (!$r['signer_id']) { $reviewSigns[$r['unit_key']] = null; continue; }
        $idt = ec_user_identity_asof($db, (int)$r['signer_id'], $date);
        $reviewSigns[$r['unit_key']] = [
            'label' => $r['label'], 'user_id' => (int)$r['signer_id'], 'name' => (string)$r['signer_name'],
            'dept' => (string)$idt['dept_name'], 'position' => (string)$idt['position_name'],
            'date' => substr((string)$r['signed_at'], 0, 10),
            'is_agent' => (int)$r['signer_for_id'] > 0 ? 1 : 0,
        ];
    }

    return [
        'company'      => $company,
        'as_doc_name'  => $docName,
        'as_doc_no'    => $docNo,
        'signs'        => $signs,
        'review_signs' => $reviewSigns,
        'stamp_tpl'        => ec_stamp_template($db, 'ec_stamp_tpl_id'),
        'review_stamp_tpl' => ec_stamp_template($db, 'ec_review_stamp_tpl_id'),
    ];
}
