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
    } catch (Throwable $e) {}
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
    if (!$pool) {
        $up = cm_dept_parent($db, $deptId);
        $guard = 0;
        while ($up && $guard++ < 20) {
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
