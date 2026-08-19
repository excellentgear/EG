<?php
/**
 * 文件制、修申請單（2-DC-01-01）共用庫
 * ------------------------------------------------------------------
 * 紙本版面（FOR CODEING 說明文件/…/2-DC-01-01-文件制、修申請單.xls）：
 *   文件狀況(制訂/修正/廢止/增發/補發)｜文件類別(手冊/程序/標準書/表單)｜文件名稱｜申請部門｜申請人
 *   文件編碼｜版本｜首次發行日期｜版本變更日期｜制修訂內容(頁次/項目/修訂前/修訂後)
 *   □同時更改「文件管制總覽表」或「品質記錄一覽表」｜是否需會簽｜會簽單位表(簽名/同意/不同意/意見)
 *   核准｜管理代表｜單位主管｜申請人 四格簽章｜文件核發、回收記錄欄
 * 規則來源：
 *   - 文件編碼自動產生＝完全比照 AS_Document_API.php 的 suggest_doc_no（階級+部門代碼／母文件遞增）
 *   - 版次：手冊/程序/標準書必填；表單制訂時無版本，之後 A→B→C 遞增
 *   - 自動簽核時間戳：ai-rules/21（業務日期與精確時間分離、錯開 5~30 分且不跨日）
 *   - 代理：一律走 delegate_lib 的 eg_resolve_signer()（ai-rules/11）
 *   - 時間戳一律取 DB 時間（PHP date() 是 UTC、MySQL NOW() 是本地，混用會差 8 小時）
 */

require_once __DIR__ . '/org_role_lib.php';
require_once __DIR__ . '/delegate_lib.php';
require_once __DIR__ . '/asdoc_lib.php';
require_once __DIR__ . '/people_lib.php';

const DA_ASDOC_MODULE = 'doc_apply';

/** 文件類別 → 文件階級（編碼首碼）；表單走母文件遞增，其餘走 階級+部門代碼 */
const DA_TYPE_LEVEL = ['手冊'=>'一階', '程序'=>'二階', '標準書'=>'三階', '表單'=>'四階'];
const DA_DOC_STATUS = ['制訂', '修正', '廢止', '增發', '補發'];

const DA_SETTING_KEYS = ['da_stamp_tpl_id', 'da_cosign_stamp_tpl_id', 'da_dist_stamp_tpl_id',
                         'da_sign_approve', 'da_sign_mgmt', 'da_sign_sup', 'da_sign_applicant',
                         'da_default_need_cosign', 'da_default_cosign_depts'];

/** 四格簽章的來源選項（值存設定，不在別處寫死人名，鐵律4） */
const DA_SIGN_SOURCES = [
    ''               => '（留白，紙本手蓋）',
    'top'            => '最高核准人員（組織角色綁定）',
    'mgmt_rep'       => '管理代表（組織角色綁定）',
    'hr_dept_mgr'    => '人事／管理部門主管（＝管理課主管）',
    'doc_dept_mgr'   => '文管中心負責人',
    'apply_dept_mgr' => '申請部門主管',
    'applicant_sup'  => '申請人的上一級主管',
    'applicant'      => '申請人（填表人）',
];

function da_ensure_schema(PDO $db): void
{
    static $done = false;
    if ($done) return;
    $done = true;
    try {
        $db->exec("CREATE TABLE IF NOT EXISTS doc_apply (
            apply_id      INT AUTO_INCREMENT PRIMARY KEY,
            apply_no      VARCHAR(30) NULL COMMENT '單號 DA-YYYYMM-nnn（給人看的，不當唯一鍵）',
            apply_date    DATE NOT NULL COMMENT '申請日期＝業務日期（列印右上角 年月日）',
            doc_status    VARCHAR(10) NOT NULL DEFAULT '制訂' COMMENT '制訂/修正/廢止/增發/補發',
            doc_type      VARCHAR(10) NOT NULL DEFAULT '表單' COMMENT '手冊/程序/標準書/表單',
            doc_name      VARCHAR(200) NULL,
            doc_no        VARCHAR(80) NULL COMMENT '文件編碼（制訂時自動產生）',
            as_doc_id     INT NULL COMMENT '對應 as_document.id（自動連結）',
            as_version_id INT NULL COMMENT '對應 as_document_version.id（自動連結）',
            version       VARCHAR(30) NULL,
            first_issue_date DATE NULL COMMENT '首次發行日期（修正時自動由 AS 文件帶入）',
            change_date   DATE NULL COMMENT '版本變更日期＝本次申請日',
            dept_id       INT NULL COMMENT '申請部門',
            dept_name     VARCHAR(100) NULL,
            applicant_id  INT NULL,
            applicant_name VARCHAR(60) NULL,
            need_overview TINYINT NOT NULL DEFAULT 0 COMMENT '核准後需同時更改文件管制總覽表/品質記錄一覽表',
            need_cosign   TINYINT NOT NULL DEFAULT 0 COMMENT '是否需會簽',
            status        VARCHAR(20) NOT NULL DEFAULT 'draft' COMMENT 'draft/submitted/approved/rejected',
            sign_approve_id INT NULL,   sign_approve_name VARCHAR(60) NULL,   sign_approve_date DATE NULL,   sign_approve_dep TINYINT NOT NULL DEFAULT 0,
            sign_mgmt_id INT NULL,      sign_mgmt_name VARCHAR(60) NULL,      sign_mgmt_date DATE NULL,      sign_mgmt_dep TINYINT NOT NULL DEFAULT 0,
            sign_sup_id INT NULL,       sign_sup_name VARCHAR(60) NULL,       sign_sup_date DATE NULL,       sign_sup_dep TINYINT NOT NULL DEFAULT 0,
            sign_applicant_id INT NULL, sign_applicant_name VARCHAR(60) NULL, sign_applicant_date DATE NULL, sign_applicant_dep TINYINT NOT NULL DEFAULT 0,
            is_auto       TINYINT NOT NULL DEFAULT 0 COMMENT '1=管理員自動簽核',
            auto_note     VARCHAR(200) NULL,
            source        VARCHAR(20) NOT NULL DEFAULT 'manual' COMMENT 'manual/suggest（由建議建立掃描產生）',
            submit_date   DATE NULL,   submitted_at DATETIME NULL,
            approved_date DATE NULL,   approved_at  DATETIME NULL,
            decide_note   VARCHAR(500) NULL,
            created_by    INT NULL,    created_at DATETIME NULL, updated_at DATETIME NULL,
            is_deleted    TINYINT NOT NULL DEFAULT 0,
            KEY idx_date (apply_date), KEY idx_status (status),
            KEY idx_asdoc (as_doc_id, as_version_id)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COMMENT='文件制、修申請單 2-DC-01-01'");

        $db->exec("CREATE TABLE IF NOT EXISTS doc_apply_change (
            row_id   INT AUTO_INCREMENT PRIMARY KEY,
            apply_id INT NOT NULL,
            row_no   INT NOT NULL,
            page_no  VARCHAR(60) NULL  COMMENT '頁次',
            item     VARCHAR(120) NULL COMMENT '項目',
            before_txt TEXT NULL       COMMENT '修訂前',
            after_txt  TEXT NULL       COMMENT '修訂後',
            KEY idx_apply (apply_id)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COMMENT='制修訂內容明細'");

        $db->exec("CREATE TABLE IF NOT EXISTS doc_apply_cosign (
            cos_id      INT AUTO_INCREMENT PRIMARY KEY,
            apply_id    INT NOT NULL,
            row_no      INT NOT NULL,
            dept_id     INT NULL,
            dept_name   VARCHAR(100) NOT NULL COMMENT '會簽單位（快照）',
            is_checked  TINYINT NOT NULL DEFAULT 0 COMMENT '文管中心/管理員勾選＝本單位採用並簽',
            signer_id   INT NULL,
            signer_name VARCHAR(60) NULL,
            agree       TINYINT NULL COMMENT '1=同意 0=不同意 NULL=未表示',
            opinion     VARCHAR(500) NULL COMMENT '會簽意見（非必填）',
            signed_date DATE NULL COMMENT '會簽業務日期（圖章日期）',
            signed_at   DATETIME NULL,
            is_delegated TINYINT NOT NULL DEFAULT 0,
            is_auto     TINYINT NOT NULL DEFAULT 0,
            notice_id   INT NULL,
            KEY idx_apply (apply_id), KEY idx_signer (signer_id)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COMMENT='會簽單位（右側簽名/同意/不同意/意見）'");

        $db->exec("CREATE TABLE IF NOT EXISTS doc_apply_dist (
            dist_id     INT AUTO_INCREMENT PRIMARY KEY,
            apply_id    INT NOT NULL,
            row_no      INT NOT NULL,
            dept_id     INT NULL,
            dept_name   VARCHAR(100) NULL COMMENT '分發部門＝填寫單位',
            issue_qty   VARCHAR(20) NULL, issue_date DATE NULL,
            receiver_id INT NULL, receiver_name VARCHAR(60) NULL COMMENT '簽收者＝填寫單位（非申請人）',
            recall_qty  VARCHAR(20) NULL, recall_date DATE NULL,
            recaller_id INT NULL, recaller_name VARCHAR(60) NULL COMMENT '回收者＝文管中心負責人',
            note        VARCHAR(200) NULL,
            KEY idx_apply (apply_id)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COMMENT='文件核發、回收記錄欄'");

        $db->exec("CREATE TABLE IF NOT EXISTS doc_apply_print_log (
            log_id       INT AUTO_INCREMENT PRIMARY KEY,
            apply_id     INT NOT NULL,
            printed_by   INT NULL, printed_name VARCHAR(60) NULL,
            printed_at   DATETIME NULL,
            KEY idx_apply (apply_id)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COMMENT='列印紀錄（清單顯示最新列印日期與次數）'");

        // 會簽預設：以部門分類設定，也可對單一 AS 文件（含表單）個別覆寫
        $db->exec("CREATE TABLE IF NOT EXISTS doc_apply_cosign_default (
            def_id      INT AUTO_INCREMENT PRIMARY KEY,
            scope_type  VARCHAR(10) NOT NULL COMMENT 'dept=部門預設 / doc=單一 AS 文件覆寫',
            scope_id    INT NOT NULL COMMENT 'department.id 或 as_document.id',
            need_cosign TINYINT NOT NULL DEFAULT 0,
            dept_ids    VARCHAR(255) NULL COMMENT '預設會簽單位 department.id 逗號串',
            updated_by  VARCHAR(60) NULL, updated_at DATETIME NULL,
            UNIQUE KEY uk_scope (scope_type, scope_id)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COMMENT='會簽預設（部門分類＋單一文件覆寫）'");

        // 制修訂內容預設組：管理員預先建好幾組常用內容，填單時一鍵帶入再自行修改
        $db->exec("CREATE TABLE IF NOT EXISTS doc_apply_change_preset (
            preset_id   INT AUTO_INCREMENT PRIMARY KEY,
            preset_name VARCHAR(100) NOT NULL COMMENT '預設組名稱（填單時的下拉選項）',
            rows_json   TEXT NULL COMMENT '[{page_no,item,before_txt,after_txt},…]',
            sort_order  INT NOT NULL DEFAULT 0,
            is_active   TINYINT NOT NULL DEFAULT 1,
            updated_by  VARCHAR(60) NULL, updated_at DATETIME NULL
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COMMENT='制修訂內容預設組'");

        foreach ([['doc_apply_view', '文件制修申請單檢閱'], ['doc_apply_edit', '文件制修申請單申請'],
                  ['doc_apply_admin', '文件制修申請單管理員']] as $r) {
            $st = $db->prepare("SELECT 1 FROM roles WHERE role_code=? AND module='doc_apply' LIMIT 1");
            $st->execute([$r[0]]);
            if (!$st->fetchColumn()) {
                $db->prepare("INSERT INTO roles (role_code, role_name, module) VALUES (?,?, 'doc_apply')")->execute([$r[0], $r[1]]);
            }
        }
    } catch (Throwable $e) {}
}

/* ============================ DB 時間（PHP date() 是 UTC，禁混用） ============================ */

function da_db_now(PDO $db): array
{
    try {
        $r = $db->query("SELECT NOW() AS dt, CURDATE() AS d")->fetch(PDO::FETCH_ASSOC);
        if ($r) return ['dt'=>(string)$r['dt'], 'd'=>(string)$r['d']];
    } catch (Throwable $e) {}
    return ['dt'=>date('Y-m-d H:i:s'), 'd'=>date('Y-m-d')];
}

/* ============================ 使用者與權限 ============================ */

function da_current_user(PDO $db): ?array
{
    $uname = $_SESSION['userName'] ?? '';
    if ($uname === '') return null;
    $st = $db->prepare("SELECT id, user_cname, user_status, state FROM `user` WHERE user_uname=?");
    $st->execute([$uname]);
    return $st->fetch(PDO::FETCH_ASSOC) ?: null;
}

function da_has_role(PDO $db, int $uid, array $codes): bool
{
    if (!$codes) return false;
    $in = implode(',', array_fill(0, count($codes), '?'));
    try {
        $st = $db->prepare("SELECT 1 FROM user_roles ur JOIN roles r ON r.role_id=ur.role_id
                            WHERE ur.user_id=? AND r.module='doc_apply' AND r.role_code IN ($in) LIMIT 1");
        $st->execute(array_merge([$uid], $codes));
        return (bool)$st->fetchColumn();
    } catch (Throwable $e) { return false; }
}

/**
 * canAdmin 管理員：查全部、代開、刪除、勾選會簽單位採用、自動簽核、批次列印/刪除、模組設定、AS 綁定
 * canEdit  可開立/編輯自己的申請單
 * canView  唯讀檢閱全部
 * 會簽權：不靠角色，由「該申請單的某一列會簽指派給你」決定（見 da_cosign_rows_for_user）
 */
function da_perms(PDO $db, ?array $u): array
{
    if (!$u) return ['isAdmin'=>false, 'canAdmin'=>false, 'canEdit'=>false, 'canView'=>false, 'uid'=>0, 'name'=>''];
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
    $canAdmin = $isAdmin || da_has_role($db, $uid, ['doc_apply_admin']);
    $canEdit  = $canAdmin || da_has_role($db, $uid, ['doc_apply_edit']);
    $canView  = $canEdit  || da_has_role($db, $uid, ['doc_apply_view']);
    return ['isAdmin'=>$isAdmin, 'canAdmin'=>$canAdmin, 'canEdit'=>$canEdit, 'canView'=>$canView,
            'uid'=>$uid, 'name'=>(string)$u['user_cname']];
}

/* ============================ 設定 ============================ */

function da_settings(PDO $db): array
{
    $out = ['da_stamp_tpl_id'=>null, 'da_cosign_stamp_tpl_id'=>null, 'da_dist_stamp_tpl_id'=>null,
            'da_sign_approve'=>'top', 'da_sign_mgmt'=>'hr_dept_mgr',
            'da_sign_sup'=>'applicant_sup', 'da_sign_applicant'=>'applicant',
            'da_default_need_cosign'=>0, 'da_default_cosign_depts'=>''];
    try {
        $in = implode(',', array_fill(0, count(DA_SETTING_KEYS), '?'));
        $st = $db->prepare("SELECT setting_key, setting_value FROM system_settings WHERE setting_key IN ($in)");
        $st->execute(DA_SETTING_KEYS);
        foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $r) {
            $k = $r['setting_key']; $v = $r['setting_value'];
            if (substr($k, -7) === '_tpl_id')        $out[$k] = ($v === '' || $v === null) ? null : (int)$v;
            elseif ($k === 'da_default_need_cosign') $out[$k] = (int)$v;
            else                                     $out[$k] = (string)$v;
        }
    } catch (Throwable $e) {}
    return $out;
}

function da_save_setting(PDO $db, string $key, $val): void
{
    if (!in_array($key, DA_SETTING_KEYS, true)) return;
    $db->prepare("INSERT INTO system_settings (setting_key, setting_value) VALUES (?,?)
                  ON DUPLICATE KEY UPDATE setting_value=VALUES(setting_value)")->execute([$key, (string)$val]);
}

/** 圖章模板（未設定或已停用回 null，消費端退回預設回墨印） */
function da_stamp_template(PDO $db, string $key): ?array
{
    $id = (int)(da_settings($db)[$key] ?? 0);
    if (!$id) return null;
    try {
        $st = $db->prepare("SELECT id, tpl_name, schema_json FROM stamp_template WHERE id=? AND is_active=1");
        $st->execute([$id]);
        $r = $st->fetch(PDO::FETCH_ASSOC);
        if (!$r) return null;
        return ['id'=>(int)$r['id'], 'tpl_name'=>$r['tpl_name'], 'schema'=>json_decode((string)$r['schema_json'], true)];
    } catch (Throwable $e) { return null; }
}

/* ============================ 文件編碼／版次（比照 as_document_management） ============================ */

/**
 * 自動產生文件編碼——**與 AS_Document_API.php 的 suggest_doc_no 同一套規則**：
 *   有母文件（表單）→ 母編號 + '-' + 兩位流水（該母文件底下現有最大值 +1）
 *   無母文件         → 階級數字 + '-' + 部門代碼 + '-' + 兩位流水
 * 回傳 ['status'=>'success','doc_no'=>…] / ['status'=>'choose','options'=>[…]] / ['status'=>'error','message'=>…]
 */
function da_suggest_doc_no(PDO $db, string $level, int $deptId, int $parentId, string $code = ''): array
{
    $levelMap = ['一階'=>'1', '二階'=>'2', '三階'=>'3', '四階'=>'4'];

    if ($parentId > 0) {
        $st = $db->prepare("SELECT doc_no FROM as_document WHERE id=?");
        $st->execute([$parentId]);
        $pNo = $st->fetchColumn();
        if (!$pNo) return ['status'=>'error', 'message'=>'母文件不存在'];
        $st = $db->prepare("SELECT doc_no FROM as_document WHERE parent_doc_id=?");
        $st->execute([$parentId]);
        $max = 0;
        foreach ($st->fetchAll(PDO::FETCH_COLUMN) as $no) {
            if (preg_match('/^' . preg_quote($pNo, '/') . '-(\d+)$/', (string)$no, $m)) $max = max($max, (int)$m[1]);
        }
        return ['status'=>'success', 'doc_no'=>$pNo . '-' . str_pad((string)($max + 1), 2, '0', STR_PAD_LEFT)];
    }

    $digit = $levelMap[$level] ?? '';
    if ($digit === '') return ['status'=>'error', 'message'=>'請先選擇文件類別（決定文件階級）'];
    if ($deptId <= 0)  return ['status'=>'error', 'message'=>'請先選擇申請部門'];

    $st = $db->prepare("SELECT code, label FROM as_dept_code WHERE department_id=? ORDER BY sort_order, id");
    $st->execute([$deptId]);
    $codes = $st->fetchAll(PDO::FETCH_ASSOC);
    if (!$codes) return ['status'=>'error', 'message'=>'此部門尚未設定文件代碼（請至 AS 文件管理 → 系統設定 → 部門文件代碼 設定）'];

    $codeParam = strtoupper(trim($code));
    if ($codeParam !== '') {
        $filtered = array_values(array_filter($codes, fn($c) => $c['code'] === $codeParam));
        if ($filtered) $codes = $filtered;
    }

    $nextFor = function (string $c) use ($db, $digit): string {
        $prefix = $digit . '-' . $c . '-';
        $st = $db->prepare("SELECT doc_no FROM as_document WHERE doc_no LIKE ?");
        $st->execute([$prefix . '%']);
        $max = 0;
        foreach ($st->fetchAll(PDO::FETCH_COLUMN) as $no) {
            // 只算直屬序號（排除 -01-01 表單）
            if (preg_match('/^' . preg_quote($prefix, '/') . '(\d+)$/', (string)$no, $m)) $max = max($max, (int)$m[1]);
        }
        return $prefix . str_pad((string)($max + 1), 2, '0', STR_PAD_LEFT);
    };

    if (count($codes) > 1) {
        return ['status'=>'choose',
                'options'=>array_map(fn($c) => ['code'=>$c['code'], 'label'=>$c['label'], 'doc_no'=>$nextFor($c['code'])], $codes)];
    }
    return ['status'=>'success', 'doc_no'=>$nextFor($codes[0]['code'])];
}

/** 表單的下一個版次：無版次→A，A→B…；Z 之後進位成 AA（極端情況，避免產生非法字元） */
function da_next_version(?string $cur): string
{
    $cur = strtoupper(trim((string)$cur));
    if ($cur === '') return 'A';
    if (!preg_match('/^[A-Z]+$/', $cur)) return 'A';
    $chars = str_split($cur);
    $i = count($chars) - 1;
    while ($i >= 0) {
        if ($chars[$i] !== 'Z') { $chars[$i] = chr(ord($chars[$i]) + 1); return implode('', $chars); }
        $chars[$i] = 'A'; $i--;
    }
    return 'A' . implode('', $chars);
}

/** 版本是否必填：手冊／程序／標準書一律必填；表單只有「制訂」不可填，其餘必填 */
function da_version_required(string $docType, string $docStatus): bool
{
    if ($docType === '表單') return $docStatus !== '制訂';
    return true;
}

/** 版本是否禁止填寫（前端反灰、後端擋）：表單的「制訂」沒有版次 */
function da_version_forbidden(string $docType, string $docStatus): bool
{
    return $docType === '表單' && $docStatus === '制訂';
}

/** 某 AS 文件的首次發行日期＝版本履歷最早一筆 revised_date（無履歷回 null） */
function da_first_issue_date(PDO $db, int $docId): ?string
{
    if ($docId <= 0) return null;
    try {
        $st = $db->prepare("SELECT revised_date FROM as_document_version WHERE doc_id=? AND revised_date IS NOT NULL
                            ORDER BY revised_date ASC, id ASC LIMIT 1");
        $st->execute([$docId]);
        $v = $st->fetchColumn();
        return $v === false ? null : (string)$v;
    } catch (Throwable $e) { return null; }
}

/** AS 文件現況（帶首次發行日期、目前版次、所屬部門） */
function da_asdoc_info(PDO $db, int $docId): ?array
{
    if ($docId <= 0) return null;
    try {
        $st = $db->prepare("SELECT d.id, d.doc_no, d.doc_name, d.doc_type, d.doc_level, d.department_id,
                                   d.parent_doc_id, d.current_version, dp.name AS dept_name
                            FROM as_document d LEFT JOIN department dp ON dp.id=d.department_id
                            WHERE d.id=? AND d.is_deleted=0");
        $st->execute([$docId]);
        $r = $st->fetch(PDO::FETCH_ASSOC);
        if (!$r) return null;
        $r['first_issue_date'] = da_first_issue_date($db, $docId);
        $r['next_version']     = da_next_version((string)($r['current_version'] ?? ''));
        return $r;
    } catch (Throwable $e) { return null; }
}

/* ============================ 單據讀取 ============================ */

function da_row(PDO $db, int $applyId): ?array
{
    $st = $db->prepare("SELECT * FROM doc_apply WHERE apply_id=? AND COALESCE(is_deleted,0)=0");
    $st->execute([$applyId]);
    $r = $st->fetch(PDO::FETCH_ASSOC);
    if (!$r) return null;
    $q = $db->prepare("SELECT row_no, page_no, item, before_txt, after_txt FROM doc_apply_change WHERE apply_id=? ORDER BY row_no");
    $q->execute([$applyId]); $r['changes'] = $q->fetchAll(PDO::FETCH_ASSOC);
    $q = $db->prepare("SELECT * FROM doc_apply_cosign WHERE apply_id=? ORDER BY row_no");
    $q->execute([$applyId]); $r['cosigns'] = $q->fetchAll(PDO::FETCH_ASSOC);
    $q = $db->prepare("SELECT * FROM doc_apply_dist WHERE apply_id=? ORDER BY row_no");
    $q->execute([$applyId]); $r['dists'] = $q->fetchAll(PDO::FETCH_ASSOC);
    return $r;
}

/** 單號：DA-YYYYMM-nnn（同月流水，缺號不補） */
function da_next_no(PDO $db, string $applyDate): string
{
    $ym = str_replace('-', '', substr($applyDate, 0, 7));
    try {
        $st = $db->prepare("SELECT COUNT(*) FROM doc_apply WHERE DATE_FORMAT(apply_date,'%Y%m')=?");
        $st->execute([$ym]);
        $n = (int)$st->fetchColumn() + 1;
    } catch (Throwable $e) { $n = 1; }
    return 'DA-' . $ym . '-' . str_pad((string)$n, 3, '0', STR_PAD_LEFT);
}

/** 申請人的部門/職稱（主要職務身分） */
function da_user_identity(PDO $db, int $uid): array
{
    $out = ['user_name'=>'', 'dept_id'=>null, 'dept_name'=>'', 'position_name'=>''];
    try {
        $st = $db->prepare("SELECT u.user_cname, m.department_id, d.name AS dept_name, p.name AS position_name
                            FROM `user` u
                            LEFT JOIN user_department_position_map m ON m.user_id=u.id AND m.is_main=1
                            LEFT JOIN department d ON d.id=m.department_id
                            LEFT JOIN position p ON p.id=m.position_id
                            WHERE u.id=? LIMIT 1");
        $st->execute([$uid]);
        $r = $st->fetch(PDO::FETCH_ASSOC);
        if ($r) {
            $out['user_name']     = (string)$r['user_cname'];
            $out['dept_id']       = $r['department_id'] !== null ? (int)$r['department_id'] : null;
            $out['dept_name']     = (string)($r['dept_name'] ?? '');
            $out['position_name'] = (string)($r['position_name'] ?? '');
        }
    } catch (Throwable $e) {}
    return $out;
}

function da_user_name(PDO $db, ?int $uid): string
{
    if (!$uid) return '';
    try {
        $st = $db->prepare("SELECT user_cname FROM `user` WHERE id=?");
        $st->execute([$uid]);
        return (string)$st->fetchColumn();
    } catch (Throwable $e) { return ''; }
}

/* ============================ 四格簽章解析 ============================ */

/**
 * 依設定解析單一簽章格的人員（不寫死人名，鐵律4）。
 * 回傳 ['id'=>int|null,'name'=>string]；留白設定或查無人回 ['id'=>null,'name'=>'']。
 */
function da_resolve_signer_src(PDO $db, string $src, array $row): array
{
    $none = ['id'=>null, 'name'=>''];
    // 業務日期＝本單申請日期：部門主管一律回推「當時」是誰（使用者要求：表單要注意日期與當時職務與在職人員）
    $asof  = (string)($row['apply_date'] ?? '');
    $byMgr = function ($deptIds) use ($db, $none, $asof) {
        if (!$deptIds) return $none;
        $m = da_dept_manager_asof($db, (array)$deptIds, $asof);
        return $m ? ['id'=>(int)$m['id'], 'name'=>(string)$m['user_cname']] : $none;
    };
    switch ($src) {
        case 'top':
            $t = eg_org_user($db, 'top_approver');
            return $t ? ['id'=>(int)$t['id'], 'name'=>(string)$t['user_cname']] : $none;
        case 'mgmt_rep':
            $t = eg_org_user($db, 'mgmt_rep');
            return $t ? ['id'=>(int)$t['id'], 'name'=>(string)$t['user_cname']] : $none;
        case 'hr_dept_mgr':
            return $byMgr(eg_org_dept_ids($db, 'hr_dept'));
        case 'doc_dept_mgr':
            return $byMgr(eg_org_dept_ids($db, 'doc_dept'));
        case 'apply_dept_mgr':
            return $byMgr($row['dept_id'] ? [(int)$row['dept_id']] : []);
        case 'applicant_sup':
            $aid = (int)($row['applicant_id'] ?? 0);
            $did = $row['dept_id'] !== null ? (int)$row['dept_id'] : null;
            // 申請人本身就是該單位的主管時，「單位主管」欄一樣蓋他自己的章
            // （使用者明確要求：不做權責迴避，不要為了避嫌往上找人或留白）
            if ($aid && $did) {
                $m = da_dept_manager_asof($db, [$did], $asof);
                if ($m && (int)$m['id'] === $aid) {
                    return ['id'=>$aid, 'name'=>(string)($row['applicant_name'] ?? da_user_name($db, $aid))];
                }
            }
            // 申請人不是該單位主管 → 蓋當時該單位主管的章；查不到才退回現況的上一級主管解析
            $m2 = $did ? da_dept_manager_asof($db, [$did], $asof) : null;
            if ($m2 && (int)$m2['id'] !== $aid) return ['id'=>(int)$m2['id'], 'name'=>(string)$m2['user_cname']];
            $sup = eg_resolve_supervisor($db, $aid, $did);
            return $sup ? ['id'=>(int)$sup, 'name'=>da_user_name($db, (int)$sup)] : $none;
        case 'applicant':
            return ['id'=>$row['applicant_id'] ? (int)$row['applicant_id'] : null,
                    'name'=>(string)($row['applicant_name'] ?? '')];
    }
    return $none;
}

/**
 * 四格簽章（核准／管理代表／單位主管／申請人）解析結果。
 * 申請人格不套代理（本人填表本人簽）；其餘三格經 delegate_lib 轉代理（ai-rules/11）。
 */
function da_resolve_signers(PDO $db, array $row, bool $autoSign = false): array
{
    $set = da_settings($db);
    $map = ['approve'=>'da_sign_approve', 'mgmt'=>'da_sign_mgmt', 'sup'=>'da_sign_sup', 'applicant'=>'da_sign_applicant'];
    $out = [];
    foreach ($map as $slot => $key) {
        $base = da_resolve_signer_src($db, (string)$set[$key], $row);
        $out[$slot] = ['id'=>$base['id'], 'name'=>$base['name'], 'is_delegated'=>0, 'src'=>(string)$set[$key]];
        if ($slot === 'applicant' || !$base['id']) continue;
        // 解析結果就是申請人本人（例：申請人自己就是單位主管）→ 直接蓋他的章，
        // 不進代理/權責迴避流程（使用者明確要求：不須迴避）
        if ((int)$base['id'] === (int)($row['applicant_id'] ?? 0)) continue;
        try {
            $rs = eg_resolve_signer($db, (int)$base['id'], [
                'applicant_id'        => (int)($row['applicant_id'] ?? 0),
                'scope_department_id' => $row['dept_id'] !== null ? (int)$row['dept_id'] : null,
                'flow_key'            => 'doc_apply',
                'auto_sign'           => $autoSign,
            ]);
            if (!empty($rs['signer_id']) && (int)$rs['signer_id'] !== (int)$base['id']) {
                $out[$slot]['id']           = (int)$rs['signer_id'];
                $out[$slot]['name']         = da_user_name($db, (int)$rs['signer_id']);
                $out[$slot]['is_delegated'] = 1;
            }
        } catch (Throwable $e) {}
    }
    return $out;
}

/* ============================ 會簽 ============================ */

/** 可當會簽單位的部門（層級 <= 3 的主要單位，依 sort_order） */
function da_cosign_dept_options(PDO $db): array
{
    try {
        return $db->query("SELECT id, name, level FROM department WHERE COALESCE(level,9)<=3 ORDER BY sort_order, id")
                  ->fetchAll(PDO::FETCH_ASSOC);
    } catch (Throwable $e) { return []; }
}

/**
 * 會簽預設：單一 AS 文件覆寫 > 該文件所屬部門預設 > 全站預設（設定頁）。
 * 回傳 ['need_cosign'=>0|1, 'dept_ids'=>int[], 'from'=>'doc|dept|global']
 */
function da_cosign_default(PDO $db, int $asDocId, ?int $deptId): array
{
    $parse = fn($s) => array_values(array_filter(array_map('intval', explode(',', (string)$s))));
    $read = function (string $type, int $id) use ($db) {
        try {
            $st = $db->prepare("SELECT need_cosign, dept_ids FROM doc_apply_cosign_default WHERE scope_type=? AND scope_id=? LIMIT 1");
            $st->execute([$type, $id]);
            return $st->fetch(PDO::FETCH_ASSOC) ?: null;
        } catch (Throwable $e) { return null; }
    };
    if ($asDocId > 0) {
        $r = $read('doc', $asDocId);
        if ($r) return ['need_cosign'=>(int)$r['need_cosign'], 'dept_ids'=>$parse($r['dept_ids']), 'from'=>'doc'];
        // 文件自己沒設定 → 用該文件所屬部門
        $info = da_asdoc_info($db, $asDocId);
        if ($info && $info['department_id']) $deptId = (int)$info['department_id'];
    }
    if ($deptId) {
        $r = $read('dept', (int)$deptId);
        if ($r) return ['need_cosign'=>(int)$r['need_cosign'], 'dept_ids'=>$parse($r['dept_ids']), 'from'=>'dept'];
    }
    $set = da_settings($db);
    return ['need_cosign'=>(int)$set['da_default_need_cosign'],
            'dept_ids'=>$parse($set['da_default_cosign_depts']), 'from'=>'global'];
}

function da_save_cosign_default(PDO $db, string $type, int $id, int $need, array $deptIds, string $byName): void
{
    if (!in_array($type, ['dept', 'doc'], true) || $id <= 0) return;
    $ids = implode(',', array_values(array_unique(array_filter(array_map('intval', $deptIds)))));
    $db->prepare("INSERT INTO doc_apply_cosign_default (scope_type, scope_id, need_cosign, dept_ids, updated_by, updated_at)
                  VALUES (?,?,?,?,?,NOW())
                  ON DUPLICATE KEY UPDATE need_cosign=VALUES(need_cosign), dept_ids=VALUES(dept_ids),
                      updated_by=VALUES(updated_by), updated_at=NOW()")
       ->execute([$type, $id, $need ? 1 : 0, $ids, $byName]);
}

/** 某會簽列的簽核人＝該部門主管，再經代理解析（ai-rules/11） */
function da_cosign_signer(PDO $db, int $deptId, int $applicantId, bool $autoSign = false, string $asof = ''): array
{
    $none = ['id'=>null, 'name'=>'', 'is_delegated'=>0];
    if ($deptId <= 0) return $none;
    // 會簽單位主管同樣依單據業務日期回推當時是誰（補歷史單據才不會蓋成現任者的章）
    $m = da_dept_manager_asof($db, eg_dept_subtree_ids($db, $deptId) ?: [$deptId], $asof);
    if (!$m) return $none;
    $base = (int)$m['id'];
    $out  = ['id'=>$base, 'name'=>(string)$m['user_cname'], 'is_delegated'=>0];
    try {
        $rs = eg_resolve_signer($db, $base, ['applicant_id'=>$applicantId, 'scope_department_id'=>$deptId,
                                             'flow_key'=>'doc_apply_cosign', 'auto_sign'=>$autoSign]);
        if (!empty($rs['signer_id']) && (int)$rs['signer_id'] !== $base) {
            $out['id'] = (int)$rs['signer_id'];
            $out['name'] = da_user_name($db, (int)$rs['signer_id']);
            $out['is_delegated'] = 1;
        }
    } catch (Throwable $e) {}
    return $out;
}

/** 該使用者在這張單上待會簽的列（未表示意見者） */
function da_cosign_rows_for_user(PDO $db, int $applyId, int $uid): array
{
    try {
        $st = $db->prepare("SELECT * FROM doc_apply_cosign WHERE apply_id=? AND signer_id=? ORDER BY row_no");
        $st->execute([$applyId, $uid]);
        return $st->fetchAll(PDO::FETCH_ASSOC);
    } catch (Throwable $e) { return []; }
}

/** 會簽狀態文字：未會簽／會簽中 n/m／全部同意／有不同意 */
function da_cosign_status(array $row): array
{
    if (!(int)$row['need_cosign']) return ['code'=>'none', 'text'=>'不需會簽', 'done'=>0, 'total'=>0];
    $rows = array_values(array_filter($row['cosigns'] ?? [], fn($c) => (int)$c['is_checked'] === 1));
    $total = count($rows);
    $done  = count(array_filter($rows, fn($c) => $c['agree'] !== null));
    $bad   = count(array_filter($rows, fn($c) => (int)$c['agree'] === 0));
    if ($total === 0)   return ['code'=>'unset', 'text'=>'尚未指定會簽單位', 'done'=>0, 'total'=>0];
    if ($done < $total) return ['code'=>'doing', 'text'=>'會簽中 ' . $done . '/' . $total, 'done'=>$done, 'total'=>$total];
    if ($bad > 0)       return ['code'=>'disagree', 'text'=>'有不同意（' . $bad . '）', 'done'=>$done, 'total'=>$total];
    return ['code'=>'agreed', 'text'=>'全部同意 ' . $done . '/' . $total, 'done'=>$done, 'total'=>$total];
}

/* ============================ 自動簽核時間（ai-rules/21） ============================ */

/** 業務日期＝送出日；精確時間隨機錯開 5~30 分鐘且不跨日 */
function da_auto_sign_time(string $submittedAt): string
{
    $base = strtotime($submittedAt);
    $ts   = $base + random_int(5, 30) * 60;
    if (date('Y-m-d', $ts) !== date('Y-m-d', $base)) $ts = strtotime(date('Y-m-d', $base) . ' 23:59:00');
    return date('Y-m-d H:i:s', $ts);
}

/* ============================ 通知（ai-rules/17） ============================ */

function da_notify_cosign(PDO $db, array $row, array $cos, int $toUid, int $fromUid): int
{
    if (!$toUid) return 0;
    $title = '文件制修申請單待會簽：' . ($row['doc_no'] ?: '') . '　' . ($row['doc_name'] ?: '');
    $content = '申請單號：' . ($row['apply_no'] ?: ('#' . $row['apply_id'])) . "\n"
             . '文件狀況：' . $row['doc_status'] . '　文件類別：' . $row['doc_type'] . "\n"
             . '文件編碼：' . ($row['doc_no'] ?: '（制訂中）') . '　版本：' . ($row['version'] ?: '－') . "\n"
             . '文件名稱：' . $row['doc_name'] . "\n"
             . '申請部門：' . $row['dept_name'] . '　申請人：' . $row['applicant_name'] . "\n"
             . '會簽單位：' . $cos['dept_name'] . "\n"
             . '點此開啟會簽，請先選擇同意／不同意，再填寫會簽意見（非必填）後簽名。';
    try {
        $db->prepare("INSERT INTO live_event (eventdate, enddate, title, content, status, created_by, source, show_status_to_others, ref_type, ref_id)
                      VALUES (CURDATE(), NULL, ?, ?, 0, ?, '文件制修申請單', 1, 'DOC_APPLY_COSIGN', ?)")
           ->execute([$title, $content, $fromUid, (int)$cos['cos_id']]);
        $eid = (int)$db->lastInsertId();
        $db->prepare("INSERT INTO live_event_target (live_event_id, target_type, target_id, mode) VALUES (?, 'user', ?, 'sign')")
           ->execute([$eid, $toUid]);
        try {
            require_once __DIR__ . '/../push/push_send.php';
            eg_push_send_to_users($db, eg_push_event_recipients($db, $eid), ['title'=>$title, 'body'=>mb_substr($content, 0, 480)]);
        } catch (Throwable $e) {}
        return $eid;
    } catch (Throwable $e) { return 0; }
}

function da_close_cosign_notice(PDO $db, int $cosId): void
{
    try {
        $db->prepare("UPDATE live_event SET enddate=DATE_SUB(CURDATE(), INTERVAL 1 DAY)
                      WHERE ref_type='DOC_APPLY_COSIGN' AND ref_id=? AND (enddate IS NULL OR enddate>=CURDATE())")
           ->execute([$cosId]);
    } catch (Throwable $e) {}
}

function da_notify_result(PDO $db, array $row, int $toUid, string $text, int $fromUid): void
{
    if (!$toUid) return;
    $title = '文件制修申請單：' . ($row['doc_no'] ?: $row['doc_name']);
    try {
        $db->prepare("INSERT INTO live_event (eventdate, enddate, title, content, status, created_by, source, show_status_to_others, ref_type, ref_id)
                      VALUES (CURDATE(), NULL, ?, ?, 0, ?, '文件制修申請單', 1, 'DOC_APPLY_RESULT', ?)")
           ->execute([$title, $text, $fromUid, (int)$row['apply_id']]);
        $eid = (int)$db->lastInsertId();
        $db->prepare("INSERT INTO live_event_target (live_event_id, target_type, target_id, mode) VALUES (?, 'user', ?, 'read')")
           ->execute([$eid, $toUid]);
        try {
            require_once __DIR__ . '/../push/push_send.php';
            eg_push_send_to_users($db, eg_push_event_recipients($db, $eid), ['title'=>$title, 'body'=>mb_substr($text, 0, 480)]);
        } catch (Throwable $e) {}
    } catch (Throwable $e) {}
}

/* ============================ 送出前必填檢查（前後端同一套規則，鐵律8） ============================ */

/**
 * 回傳 [欄位key => 錯誤原因]；空陣列＝通過。
 * 前端送出前跳提示標紅、後端 API 再擋一次（不可只做半套）。
 */
function da_validate(PDO $db, array $r, array $changes): array
{
    $e = [];
    if (!in_array((string)($r['doc_status'] ?? ''), DA_DOC_STATUS, true)) $e['doc_status'] = '請選擇文件狀況';
    if (!isset(DA_TYPE_LEVEL[(string)($r['doc_type'] ?? '')]))            $e['doc_type']   = '請選擇文件類別';
    if (trim((string)($r['doc_name'] ?? '')) === '')                      $e['doc_name']   = '請填寫文件名稱';
    if (!(int)($r['dept_id'] ?? 0))                                       $e['dept_id']    = '請選擇申請部門';
    if (!(int)($r['applicant_id'] ?? 0))                                  $e['applicant_id'] = '請選擇申請人';
    if (trim((string)($r['apply_date'] ?? '')) === '')                    $e['apply_date'] = '請填寫申請日期';

    $type = (string)($r['doc_type'] ?? '');
    $stat = (string)($r['doc_status'] ?? '');
    $ver  = trim((string)($r['version'] ?? ''));
    if (da_version_forbidden($type, $stat)) {
        if ($ver !== '') $e['version'] = '表單「制訂」時沒有版本，不可填寫';
    } elseif (da_version_required($type, $stat) && $ver === '') {
        $e['version'] = $type === '表單' ? '表單改版必須填寫版本（A、B、C…）' : $type . '必須填寫版本';
    }

    // 制訂＝要有文件編碼（自動產生後帶入）；其餘狀況要指到既有 AS 文件
    if ($stat === '制訂') {
        if (trim((string)($r['doc_no'] ?? '')) === '') $e['doc_no'] = '請按「自動產生」取得文件編碼';
    } else {
        if (!(int)($r['as_doc_id'] ?? 0))              $e['as_doc_id'] = '請選擇要' . $stat . '的 AS 文件';
        if ($stat === '修正' && trim((string)($r['first_issue_date'] ?? '')) === '') {
            $e['first_issue_date'] = '查無此文件的首次發行日期（請先於 AS 文件管理補建版本履歷）';
        }
    }

    // 修正一定要寫制修訂內容（至少一列有填東西）
    if ($stat === '修正') {
        $has = false;
        foreach ($changes as $c) {
            if (trim((string)($c['page_no'] ?? '')) !== '' || trim((string)($c['item'] ?? '')) !== ''
             || trim((string)($c['before_txt'] ?? '')) !== '' || trim((string)($c['after_txt'] ?? '')) !== '') { $has = true; break; }
        }
        if (!$has) $e['changes'] = '「修正」必須填寫制修訂內容（至少一列）';
    }

    if ((int)($r['need_cosign'] ?? 0) === 1) {
        $picked = array_filter((array)($r['cosign_dept_ids'] ?? []));
        if (!$picked) $e['cosign'] = '已勾選「需會簽」，請至少指定一個會簽單位';
    }
    return $e;
}

/* ============================ 與 AS 文件管理的自動連結 ============================ */

/**
 * 依「文件編碼＋版本」把申請單自動接到 as_document / as_document_version。
 * 找不到就留 NULL（例如制訂案的文件還沒在 AS 文件管理建檔），不阻擋存檔；
 * 之後 AS 文件建好、再次存檔或掃描時會自動補上。
 */
function da_link_asdoc(PDO $db, int $applyId): array
{
    $r = da_row($db, $applyId);
    if (!$r) return ['doc_id'=>0, 'version_id'=>0];
    $docId = (int)($r['as_doc_id'] ?? 0);
    if (!$docId && trim((string)$r['doc_no']) !== '') {
        try {
            $st = $db->prepare("SELECT id FROM as_document WHERE doc_no=? AND is_deleted=0 LIMIT 1");
            $st->execute([trim((string)$r['doc_no'])]);
            $docId = (int)$st->fetchColumn();
        } catch (Throwable $e) {}
    }
    $verId = (int)($r['as_version_id'] ?? 0);
    if ($docId && !$verId) {
        try {
            $ver = trim((string)$r['version']);
            if ($ver !== '') {
                $st = $db->prepare("SELECT id FROM as_document_version WHERE doc_id=? AND version=? ORDER BY id DESC LIMIT 1");
                $st->execute([$docId, $ver]);
            } else {
                // 表單制訂案沒有版次 → 接該文件最早一筆版本履歷
                $st = $db->prepare("SELECT id FROM as_document_version WHERE doc_id=? ORDER BY revised_date ASC, id ASC LIMIT 1");
                $st->execute([$docId]);
            }
            $verId = (int)$st->fetchColumn();
        } catch (Throwable $e) {}
    }
    if ($docId !== (int)($r['as_doc_id'] ?? 0) || $verId !== (int)($r['as_version_id'] ?? 0)) {
        try {
            $db->prepare("UPDATE doc_apply SET as_doc_id=?, as_version_id=?, updated_at=NOW() WHERE apply_id=?")
               ->execute([$docId ?: null, $verId ?: null, $applyId]);
        } catch (Throwable $e) {}
    }
    return ['doc_id'=>$docId, 'version_id'=>$verId];
}

/** 某 AS 文件版本已連結的線上申請單（給 as_document_management 歷史版本「申請單」欄用） */
function da_apply_for_version(PDO $db, int $versionId): ?array
{
    if ($versionId <= 0) return null;
    try {
        $st = $db->prepare("SELECT apply_id, apply_no, apply_date, status FROM doc_apply
                            WHERE as_version_id=? AND COALESCE(is_deleted,0)=0 ORDER BY apply_id DESC LIMIT 1");
        $st->execute([$versionId]);
        return $st->fetch(PDO::FETCH_ASSOC) ?: null;
    } catch (Throwable $e) { return null; }
}

/* ============================ 建議建立（掃描缺申請單的文件／改版） ============================ */

/**
 * 掃出「AS 文件管理有新文件或改版，但沒有線上文件制修申請單」的版本列。
 * $sinceDate 只掃該日之後（含）修訂的版本；空＝全部。
 * 回傳每列：version_id, doc_id, doc_no, doc_name, doc_type, doc_level, dept_id, dept_name,
 *          version, revised_date, revised_pages, revised_summary, change_status, is_first,
 *          suggest_status（制訂/修正）, uploaded_by, has_paper（是否已有掃描紙本申請單）
 */
function da_suggest_scan(PDO $db, string $sinceDate = '', int $limit = 500): array
{
    $where = "d.is_deleted=0 AND NOT EXISTS (SELECT 1 FROM doc_apply a WHERE a.as_version_id=v.id AND COALESCE(a.is_deleted,0)=0)";
    $args  = [];
    if ($sinceDate !== '') { $where .= " AND v.revised_date >= ?"; $args[] = $sinceDate; }
    $sql = "SELECT v.id AS version_id, v.doc_id, v.version, v.revised_date, v.revised_pages, v.revised_summary,
                   v.change_status, v.apply_form_file_name, v.uploaded_by,
                   d.doc_no, d.doc_name, d.doc_type, d.doc_level, d.department_id, dp.name AS dept_name,
                   (SELECT MIN(v2.revised_date) FROM as_document_version v2 WHERE v2.doc_id=v.doc_id) AS first_date,
                   (SELECT v3.id FROM as_document_version v3 WHERE v3.doc_id=v.doc_id
                     ORDER BY v3.revised_date ASC, v3.id ASC LIMIT 1) AS first_ver_id
            FROM as_document_version v
            JOIN as_document d ON d.id=v.doc_id
            LEFT JOIN department dp ON dp.id=d.department_id
            WHERE $where
            ORDER BY v.revised_date DESC, v.id DESC
            LIMIT " . max(1, min(2000, $limit));
    try {
        $st = $db->prepare($sql);
        $st->execute($args);
        $rows = $st->fetchAll(PDO::FETCH_ASSOC);
    } catch (Throwable $e) { return []; }

    foreach ($rows as &$r) {
        $isFirst = ((int)$r['first_ver_id'] === (int)$r['version_id']);
        $r['is_first']        = $isFirst ? 1 : 0;
        // change_status 是 AS 文件自己記的狀況；沒記時用「是不是第一筆版本」推導
        $cs = trim((string)($r['change_status'] ?? ''));
        $r['suggest_status']  = in_array($cs, DA_DOC_STATUS, true) ? $cs : ($isFirst ? '制訂' : '修正');
        $r['first_issue_date']= (string)($r['first_date'] ?? '');
        $r['has_paper']       = $r['apply_form_file_name'] ? 1 : 0;
        unset($r['first_ver_id'], $r['first_date'], $r['apply_form_file_name']);
    }
    return $rows;
}

/** 依掃描結果建立一張申請單（草稿）；回傳 apply_id，失敗回 0。呼叫端自行控制 transaction。 */
function da_create_from_version(PDO $db, array $v, int $createdBy, string $createdName): int
{
    $applyDate = (string)($v['revised_date'] ?? '') ?: da_db_now($db)['d'];
    $docType   = (string)($v['doc_type'] ?? '');
    if (!isset(DA_TYPE_LEVEL[$docType])) {
        // AS 文件的 doc_type 可能是「程序書」「作業標準書」之類 → 用階級回推類別
        $byLevel = array_flip(DA_TYPE_LEVEL);
        $docType = $byLevel[(string)($v['doc_level'] ?? '')] ?? '表單';
    }
    // 申請人：優先用該版本的上傳者，查不到用該文件所屬部門主管
    $applicantId = 0;
    $up = trim((string)($v['uploaded_by'] ?? ''));
    if ($up !== '') {
        try {
            $st = $db->prepare("SELECT id FROM `user` WHERE user_cname=? OR user_uname=? LIMIT 1");
            $st->execute([$up, $up]);
            $applicantId = (int)$st->fetchColumn();
        } catch (Throwable $e) {}
    }
    $deptId = (int)($v['department_id'] ?? 0);
    if (!$applicantId && $deptId) {
        // 建議建立是補歷史單據 → 一律取「該版本修訂日當時」的部門主管，不是現任
        $m = da_dept_manager_asof($db, [$deptId], $applyDate);
        if ($m) $applicantId = (int)$m['id'];
    }
    $ident = $applicantId ? da_user_identity_asof($db, $applicantId, $applyDate, $deptId)
                          : ['user_name'=>'', 'dept_id'=>null, 'dept_name'=>''];
    if (!$deptId && $ident['dept_id']) $deptId = (int)$ident['dept_id'];
    $deptName = (string)($v['dept_name'] ?? '') ?: (string)$ident['dept_name'];

    $status  = (string)($v['suggest_status'] ?? '修正');
    $version = (string)($v['version'] ?? '');
    if (da_version_forbidden($docType, $status)) $version = '';

    $def = da_cosign_default($db, (int)$v['doc_id'], $deptId ?: null);
    $now = da_db_now($db);

    try {
        $db->prepare("INSERT INTO doc_apply
            (apply_no, apply_date, doc_status, doc_type, doc_name, doc_no, as_doc_id, as_version_id, version,
             first_issue_date, change_date, dept_id, dept_name, applicant_id, applicant_name,
             need_overview, need_cosign, status, source, created_by, created_at, updated_at)
            VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,'draft','suggest',?,?,?)")
           ->execute([da_next_no($db, $applyDate), $applyDate, $status, $docType,
                      (string)($v['doc_name'] ?? ''), (string)($v['doc_no'] ?? ''),
                      (int)$v['doc_id'] ?: null, (int)$v['version_id'] ?: null, $version,
                      ($v['first_issue_date'] ?? '') ?: null, $applyDate,
                      $deptId ?: null, $deptName, $applicantId ?: null, (string)$ident['user_name'],
                      1, $def['need_cosign'], $createdBy ?: null, $now['dt'], $now['dt']]);
        $applyId = (int)$db->lastInsertId();
    } catch (Throwable $e) { return 0; }

    // 制修訂內容：帶入 AS 版本履歷的頁次／摘要（使用者要求「自動顯示相關資料」）
    $pages = trim((string)($v['revised_pages'] ?? ''));
    $sum   = trim((string)($v['revised_summary'] ?? ''));
    if ($pages !== '' || $sum !== '') {
        $db->prepare("INSERT INTO doc_apply_change (apply_id,row_no,page_no,item,before_txt,after_txt) VALUES (?,1,?,?,?,?)")
           ->execute([$applyId, $pages, $sum !== '' ? mb_substr($sum, 0, 120) : '', '', $sum]);
    }
    da_sync_cosign_rows($db, $applyId, $def['dept_ids'], $applicantId, $applyDate);
    return $applyId;
}

/** 依指定的會簽單位重建會簽列（保留已簽的意見，不覆蓋） */
function da_sync_cosign_rows(PDO $db, int $applyId, array $deptIds, int $applicantId, string $asof = ''): void
{
    $deptIds = array_values(array_unique(array_filter(array_map('intval', $deptIds))));
    $keep = [];
    try {
        $st = $db->prepare("SELECT * FROM doc_apply_cosign WHERE apply_id=?");
        $st->execute([$applyId]);
        foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $c) $keep[(int)$c['dept_id']] = $c;
    } catch (Throwable $e) {}

    $names = [];
    if ($deptIds) {
        $in = implode(',', array_fill(0, count($deptIds), '?'));
        $st = $db->prepare("SELECT id, name FROM department WHERE id IN ($in)");
        $st->execute($deptIds);
        foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $d) $names[(int)$d['id']] = (string)$d['name'];
    }

    $db->prepare("DELETE FROM doc_apply_cosign WHERE apply_id=?")->execute([$applyId]);
    $no = 0;
    foreach ($deptIds as $did) {
        $no++;
        $old = $keep[$did] ?? null;
        if ($old && $old['agree'] !== null) {
            $db->prepare("INSERT INTO doc_apply_cosign
                (apply_id,row_no,dept_id,dept_name,is_checked,signer_id,signer_name,agree,opinion,signed_date,signed_at,is_delegated,is_auto,notice_id)
                VALUES (?,?,?,?,1,?,?,?,?,?,?,?,?,?)")
               ->execute([$applyId, $no, $did, $names[$did] ?? (string)$old['dept_name'],
                          $old['signer_id'], $old['signer_name'], $old['agree'], $old['opinion'],
                          $old['signed_date'], $old['signed_at'], $old['is_delegated'], $old['is_auto'], $old['notice_id']]);
            continue;
        }
        $sg = da_cosign_signer($db, $did, $applicantId, false, $asof);
        $db->prepare("INSERT INTO doc_apply_cosign (apply_id,row_no,dept_id,dept_name,is_checked,signer_id,signer_name,is_delegated)
                      VALUES (?,?,?,?,1,?,?,?)")
           ->execute([$applyId, $no, $did, $names[$did] ?? '', $sg['id'], $sg['name'], $sg['is_delegated']]);
    }
}

/* ============================ 制修訂內容預設組 ============================ */

/** 可用的預設組（填單時的下拉；$all=true 連停用的也列給管理員維護） */
function da_change_presets(PDO $db, bool $all = false): array
{
    try {
        $sql = "SELECT preset_id, preset_name, rows_json, sort_order, is_active
                FROM doc_apply_change_preset " . ($all ? '' : 'WHERE is_active=1 ') . "
                ORDER BY sort_order, preset_id";
        $rows = $db->query($sql)->fetchAll(PDO::FETCH_ASSOC);
    } catch (Throwable $e) { return []; }
    foreach ($rows as &$r) {
        $d = json_decode((string)$r['rows_json'], true);
        $r['rows'] = is_array($d) ? $d : [];
        unset($r['rows_json']);
    }
    return $rows;
}

/** 新增／修改一組預設（$presetId=0＝新增）；回傳 preset_id */
function da_save_change_preset(PDO $db, int $presetId, string $name, array $rows, int $sort, int $active, string $byName): int
{
    $clean = [];
    foreach ($rows as $r) {
        $x = ['page_no'    => mb_substr(trim((string)($r['page_no'] ?? '')), 0, 60),
              'item'       => mb_substr(trim((string)($r['item'] ?? '')), 0, 120),
              'before_txt' => trim((string)($r['before_txt'] ?? '')),
              'after_txt'  => trim((string)($r['after_txt'] ?? ''))];
        if ($x['page_no'] === '' && $x['item'] === '' && $x['before_txt'] === '' && $x['after_txt'] === '') continue;
        $clean[] = $x;
    }
    $json = json_encode($clean, JSON_UNESCAPED_UNICODE);
    if ($presetId > 0) {
        $db->prepare("UPDATE doc_apply_change_preset SET preset_name=?, rows_json=?, sort_order=?, is_active=?,
                        updated_by=?, updated_at=NOW() WHERE preset_id=?")
           ->execute([$name, $json, $sort, $active ? 1 : 0, $byName, $presetId]);
        return $presetId;
    }
    $db->prepare("INSERT INTO doc_apply_change_preset (preset_name, rows_json, sort_order, is_active, updated_by, updated_at)
                  VALUES (?,?,?,?,?,NOW())")
       ->execute([$name, $json, $sort, $active ? 1 : 0, $byName]);
    return (int)$db->lastInsertId();
}

function da_delete_change_preset(PDO $db, int $presetId): void
{
    if ($presetId > 0) $db->prepare("DELETE FROM doc_apply_change_preset WHERE preset_id=?")->execute([$presetId]);
}

/* ============================ 依「業務日期」回推當時的人與職務 ============================ */
/*
 * 為什麼要這一段（使用者明確要求：「表單都要注意日期與當時職務與在職人員」）：
 * 本頁大量用於**補歷史單據**（建議建立會掃出好幾年前的改版），若人員候選與簽章人一律用「現況」解析，
 * 會出現三種不實：①當時的申請人已離職 → 選不到人 ②當時他還沒到職 → 卻被列為候選
 * ③當時的單位主管不是現在這位 → 印出來的章是別人。做法比照 AS 文件管理「文件管制總覽表」依任期回推製表人。
 * 職務回推走既有共用庫 position_history_lib.php 的 eg_position_snapshot_at_bulk()（ai-rules/14），不自己發明。
 */

/** 該員在指定日期是否在職：到職日已到、且尚未離職（離職當天仍算在職）；state=90 特殊帳號一律不列 */
function da_in_service_asof(array $u, string $date): bool
{
    if ((int)($u['state'] ?? 1) === 90) return false;
    $hire  = (string)($u['hire_date'] ?? '');
    $leave = (string)($u['leave_date'] ?? '');
    if ($hire !== '' && $hire > $date) return false;
    if ($leave !== '' && $leave < $date) return false;
    // 已離職但沒填離職日：只能用現況判斷，維持「不列」以免印出早已不在的人
    if ($leave === '' && (int)($u['state'] ?? 1) === 0) return false;
    return true;
}

/**
 * 依業務日期回推的「逐職務」人員清單（含兼任），排序依 部門 → 職稱 sort_order。
 * 與 eg_people_posts() 的差別：這支的部門/職稱來自 user_position_history 在該日期的快照，
 * 在職與否也是用該日期判定，所以**當時在職、現已離職的人也會列出**（標 is_former=1）。
 * $date 空字串＝退回現況（等同 eg_people_posts()）。
 */
function da_people_posts_asof(PDO $db, string $date): array
{
    if ($date === '') { try { return eg_people_posts($db, []); } catch (Throwable $e) { return []; } }
    require_once __DIR__ . '/position_history_lib.php';

    $users = [];
    try {
        $users = $db->query("SELECT id, user_cname, state, hire_date, leave_date FROM `user`")->fetchAll(PDO::FETCH_ASSOC);
    } catch (Throwable $e) { return []; }

    $deptMap = []; $posMap = [];
    try {
        foreach ($db->query("SELECT id, name, COALESCE(sort_order,999) s FROM department")->fetchAll(PDO::FETCH_ASSOC) as $d)
            $deptMap[(int)$d['id']] = ['name'=>(string)$d['name'], 'sort'=>(int)$d['s']];
        foreach ($db->query("SELECT id, name, COALESCE(sort_order,999) s FROM position")->fetchAll(PDO::FETCH_ASSOC) as $p)
            $posMap[(int)$p['id']] = ['name'=>(string)$p['name'], 'sort'=>(int)$p['s']];
    } catch (Throwable $e) {}

    $snapAll = eg_position_snapshot_at_bulk($db, $date);
    $out = [];
    foreach ($users as $u) {
        if (!da_in_service_asof($u, $date)) continue;
        $uid = (int)$u['id'];
        foreach (($snapAll[$uid] ?? []) as $s) {
            $did = (int)($s['department_id'] ?? 0);
            $pid = (int)($s['position_id'] ?? 0);
            $dn  = $deptMap[$did]['name'] ?? (string)($s['department_name'] ?? '');
            $pn  = $posMap[$pid]['name']  ?? (string)($s['position_name'] ?? '');
            $isFormer = (int)($u['state'] ?? 1) === 0 ? 1 : 0;
            $out[] = [
                'id'            => $uid,
                'user_cname'    => (string)$u['user_cname'],
                'dept_id'       => $did ?: null,
                'dept_name'     => $dn,
                'dept_sort'     => $deptMap[$did]['sort'] ?? 999,
                'position_id'   => $pid ?: null,
                'position_name' => $pn,
                'position_sort' => $posMap[$pid]['sort'] ?? 999,
                'is_main'       => (int)($s['is_main'] ?? 0),
                'is_former'     => $isFormer,
                'post_key'      => $uid . ':' . $did,
                'display'       => trim($dn . '　' . $pn . '　' . $u['user_cname'])
                                 . ((int)($s['is_main'] ?? 0) ? '' : '（兼任）')
                                 . ($isFormer ? '（已離職）' : ''),
            ];
        }
    }
    // 欄位順序固定「部門/職稱/姓名」，排序依 sort_order（人員列表鐵則第 5 條）
    usort($out, function ($a, $b) {
        return [$a['dept_sort'], $a['dept_id'], $a['position_sort'], $a['id']]
           <=> [$b['dept_sort'], $b['dept_id'], $b['position_sort'], $b['id']];
    });
    return $out;
}

/**
 * 某部門（可多個，含子部門請由呼叫端展開）在該日期的主管。
 * 判定「主管」＝該職稱在 position_level 有設 level；同部門多位取 level 最小（職級最高）者。
 * 與 eg_org_dept_manager() 的差別：這支用當時的職務快照＋當時的在職判定，
 * 所以當時在職、現已離職的主管也找得到（補歷史單據時才不會蓋成現任者的章）。
 * $date 空字串＝退回 eg_org_dept_manager()（現況）。
 */
function da_dept_manager_asof(PDO $db, array $deptIds, string $date): ?array
{
    $deptIds = array_values(array_filter(array_map('intval', $deptIds)));
    if (!$deptIds) return null;
    if ($date === '') {
        $m = eg_org_dept_manager($db, $deptIds);
        return $m ? ['id'=>(int)$m['id'], 'user_cname'=>(string)$m['user_cname']] : null;
    }
    $levels = [];
    try {
        foreach ($db->query("SELECT position_id, level FROM position_level WHERE level IS NOT NULL")->fetchAll(PDO::FETCH_ASSOC) as $l)
            $levels[(int)$l['position_id']] = (int)$l['level'];
    } catch (Throwable $e) { return null; }
    if (!$levels) return null;

    $best = null;
    foreach (da_people_posts_asof($db, $date) as $p) {
        if (!in_array((int)$p['dept_id'], $deptIds, true)) continue;
        $lv = $levels[(int)$p['position_id']] ?? null;
        if ($lv === null) continue;
        if ($best === null || $lv < $best['level']) {
            $best = ['id'=>(int)$p['id'], 'user_cname'=>(string)$p['user_cname'], 'level'=>$lv];
        }
    }
    return $best ? ['id'=>$best['id'], 'user_cname'=>$best['user_cname']] : null;
}

/**
 * 某人在指定業務日期「當時」的身分：姓名＋當時所屬部門／職稱。
 * $preferDeptId 有值時優先取他當時在該部門的那個職務（兼任的人有多個職務，要挑對申請部門的那一個）。
 * $date 空字串＝退回現況（等同 da_user_identity()）。
 */
function da_user_identity_asof(PDO $db, int $uid, string $date, int $preferDeptId = 0): array
{
    $out = da_user_identity($db, $uid);          // 先取現況當底（姓名一定拿得到）
    if (!$uid || $date === '') return $out;
    $posts = array_values(array_filter(da_people_posts_asof($db, $date), fn($p) => (int)$p['id'] === $uid));
    if (!$posts) return $out;                    // 當時不在職／查無職務 → 維持現況值，由呼叫端自行判斷
    $pick = null;
    if ($preferDeptId) {
        foreach ($posts as $p) { if ((int)$p['dept_id'] === $preferDeptId) { $pick = $p; break; } }
    }
    if (!$pick) {                                // 沒指定部門或指定的當時沒有 → 取主職，再不然第一個
        foreach ($posts as $p) { if ((int)$p['is_main'] === 1) { $pick = $p; break; } }
        $pick = $pick ?: $posts[0];
    }
    return ['user_name'     => (string)$pick['user_cname'],
            'dept_id'       => $pick['dept_id'] !== null ? (int)$pick['dept_id'] : null,
            'dept_name'     => (string)$pick['dept_name'],
            'position_name' => (string)$pick['position_name']];
}
