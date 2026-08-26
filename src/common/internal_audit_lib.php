<?php
/**
 * 內部稽核（2-GM-06）共用庫
 * ------------------------------------------------------------------
 * 一頁控管整個內稽流程，涵蓋六份 AS 表單：
 *   2-GM-06-01 內部稽核計劃表      年度 月份×部門 ○計畫／◎實際
 *   2-GM-06-02 稽核通知單          每次稽核一張（稽核件號、稽核員、受稽單位、陪檢員、結束會議）
 *   2-GM-06-03 績效執行稽核查檢表  半年一張，題目自動帶 KPI 指標（kpi_as_indicator）
 *   2-GM-06-04 AS稽核查檢表        題目自動帶 AS9100 條文題庫（ia_as_clause）
 *   2-GM-06-06 系統稽核紀錄表      題目自動帶 AS 文件表單清單（as_document）
 *   2-GM-06-07 內稽不符合通知單    IA 編號，三方分段填寫
 *   2-GM-06-08 稽核報告表          年度彙總，缺點數與缺點記錄自動由 IA 單算出
 * 會議紀錄（事前／結束會議）不重複建立，一律走既有 views/ADM/meeting_record.php。
 *
 * 使用者決策（2026-08-25 以 AskUserQuestion 拍板）：
 *   ①不符合通知單自建 IA 單（不併入 CAR），CAR 仍可互相連結
 *   ②三張查檢表題目全部自動帶＋可勾選要查哪幾項
 *   ③會議＝自動建 meeting_record 草稿再新分頁開啟（meeting_record.php?id=）
 *   ④IA 開立即發通知，並在要求完成期限前 N 天與逾期時自動提醒
 *   ⑤IA 分段鎖定（稽核員段／受稽單位段／驗證段），但稽核員可「代填」且留紀錄
 *   ⑥稽核報告表全自動彙總，預定完成改善時間與補充文字可人工調整
 *   ⑦年度計畫表「○計畫」手動排定，「◎實際」由該月是否真的執行稽核自動判定
 *   ⑧單位主管簽核時要能填「核示」
 */

require_once __DIR__ . '/org_role_lib.php';
require_once __DIR__ . '/approval_lib.php';
require_once __DIR__ . '/delegate_lib.php';
require_once __DIR__ . '/asdoc_lib.php';
require_once __DIR__ . '/people_lib.php';
require_once __DIR__ . '/position_history_lib.php';
require_once __DIR__ . '/date_fmt_lib.php';

/** AS 文件綁定模組代碼（asdoc_lib）——一份表單一個代碼，設定值只存 as_document.id */
const IA_ASDOC_MODULES = [
    'plan'   => ['module' => 'ia_plan',   'label' => '內部稽核計劃表',     'fallback' => '2-GM-06-01'],
    'case'   => ['module' => 'ia_case',   'label' => '稽核通知單',         'fallback' => '2-GM-06-02'],
    'kpi'    => ['module' => 'ia_kpi',    'label' => '績效執行稽核查檢表', 'fallback' => '2-GM-06-03'],
    'as'     => ['module' => 'ia_as',     'label' => 'AS稽核查檢表',       'fallback' => '2-GM-06-04'],
    'system' => ['module' => 'ia_system', 'label' => '系統稽核紀錄表',     'fallback' => '2-GM-06-06'],
    'nc'     => ['module' => 'ia_nc',     'label' => '內稽不符合通知單',   'fallback' => '2-GM-06-07'],
    'report' => ['module' => 'ia_report', 'label' => '稽核報告表',         'fallback' => '2-GM-06-08'],
];

/** 查檢表種類 → 顯示名稱／AS 綁定鍵。新增種類只要加在這裡（鐵律4：不在別處再寫一份對照） */
const IA_CHECK_KINDS = [
    'as'     => ['label' => 'AS稽核查檢表',       'asdoc' => 'as'],
    'system' => ['label' => '系統稽核紀錄表',     'asdoc' => 'system'],
    'kpi'    => ['label' => '績效執行稽核查檢表', 'asdoc' => 'kpi'],
];

/** 不符合類型（紙本用語）。值存 DB，顯示一律查這裡 */
const IA_NC_TYPES = [
    'major'   => '主要缺失',
    'minor'   => '次要缺失',
    'observe' => '觀察事項',
];

/** IA 單階段：誰能填哪一段 */
const IA_NC_STAGES = [
    'issued'   => '待受稽單位回覆',
    'replied'  => '待稽核組長驗證',
    'verified' => '待管理代表意見',
    'closed'   => '已結案',
];

const IA_SETTING_GROUP = 'INTERNAL_AUDIT';
const IA_SETTING_KEYS  = [
    'ia_remind_days',
    'ia_stamp_tpl_id',
    'ia_sign_approve',
    'ia_sign_review',
    'ia_meeting_pre_subject',
    'ia_meeting_end_subject',
];

/** 簽章格來源選項（核准／審查）。不寫死人名，一律由組織角色綁定即時解析 */
const IA_SIGN_SOURCES = [
    ''        => '（留白，紙本手蓋）',
    'top'     => '最高核准人員（組織角色綁定）',
    'mgr_rep' => '管理代表（組織角色綁定）',
    'leader'  => '本次稽核組長',
    'maker'   => '製表人（建立者）',
];

/* ============================ 建表 ============================ */

function ia_ensure_schema(PDO $db): void
{
    static $done = false;
    if ($done) return;
    $done = true;
    try {
        /* ---- 2-GM-06-01 年度稽核計劃表 ---- */
        $db->exec("CREATE TABLE IF NOT EXISTS ia_plan (
            plan_id       INT AUTO_INCREMENT PRIMARY KEY,
            year          SMALLINT NOT NULL COMMENT '西元年度',
            title         VARCHAR(150) NULL COMMENT '表頭標題（留空＝自動組）',
            remark        VARCHAR(500) NULL,
            status        VARCHAR(20) NOT NULL DEFAULT 'draft' COMMENT 'draft/submitted/approved',
            maker_id      INT NULL, maker_name VARCHAR(60) NULL, maker_date DATE NULL,
            reviewer_id   INT NULL, reviewer_name VARCHAR(60) NULL, reviewer_date DATE NULL,
            approver_id   INT NULL, approver_name VARCHAR(60) NULL, approver_date DATE NULL,
            submit_date   DATE NULL, submitted_at DATETIME NULL,
            approved_date DATE NULL, approved_at DATETIME NULL,
            decide_note   VARCHAR(500) NULL,
            created_by    INT NULL, created_by_name VARCHAR(60) NULL,
            created_at    DATETIME NULL, updated_at DATETIME NULL,
            is_deleted    TINYINT NOT NULL DEFAULT 0,
            UNIQUE KEY uk_year (year)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COMMENT='內部稽核計劃表 2-GM-06-01'");

        $db->exec("CREATE TABLE IF NOT EXISTS ia_plan_dept (
            pd_id      INT AUTO_INCREMENT PRIMARY KEY,
            plan_id    INT NOT NULL,
            dept_id    INT NOT NULL,
            dept_name  VARCHAR(100) NULL COMMENT '快照，部門改名後舊表仍印當時名稱',
            sort_order INT NOT NULL DEFAULT 0,
            UNIQUE KEY uk_pd (plan_id, dept_id),
            KEY idx_plan (plan_id)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COMMENT='年度計劃表的受稽單位欄'");

        $db->exec("CREATE TABLE IF NOT EXISTS ia_plan_cell (
            cell_id  INT AUTO_INCREMENT PRIMARY KEY,
            plan_id  INT NOT NULL,
            dept_id  INT NOT NULL,
            month    TINYINT NOT NULL COMMENT '1~12',
            planned  TINYINT NOT NULL DEFAULT 1 COMMENT '1=○計畫實施（人工排定）',
            note     VARCHAR(200) NULL,
            UNIQUE KEY uk_cell (plan_id, dept_id, month),
            KEY idx_plan (plan_id)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COMMENT='年度計劃表格子；◎實際實施由稽核案件即時推導不存這裡'");

        /* ---- 2-GM-06-02 稽核通知單（＝一次稽核案件） ---- */
        $db->exec("CREATE TABLE IF NOT EXISTS ia_case (
            case_id        INT AUTO_INCREMENT PRIMARY KEY,
            year           SMALLINT NOT NULL COMMENT '西元年度',
            seq_no         INT NOT NULL DEFAULT 1 COMMENT '該年度第幾次',
            case_no        VARCHAR(30) NULL COMMENT '稽核件號（民國年3碼+MMDD+3位流水）',
            notify_date    DATE NULL COMMENT '通知日期＝本單業務日期',
            audit_from     DATE NULL,
            audit_to       DATE NULL,
            leader_id      INT NULL, leader_name VARCHAR(60) NULL COMMENT '稽核組長',
            end_meet_date  DATE NULL,
            end_meet_start VARCHAR(5) NULL, end_meet_end VARCHAR(5) NULL,
            end_meet_place VARCHAR(150) NULL,
            pre_meeting_id INT NULL COMMENT 'meeting_record.meeting_id（事前會議）',
            end_meeting_id INT NULL COMMENT 'meeting_record.meeting_id（結束會議）',
            remark         TEXT NULL COMMENT '備註（紙本附註，可改）',
            status         VARCHAR(20) NOT NULL DEFAULT 'draft' COMMENT 'draft/issued/executing/closed',
            executed       TINYINT NOT NULL DEFAULT 0 COMMENT '1=實際已執行（年度計劃表◎的依據）',
            executed_date  DATE NULL,
            maker_id       INT NULL, maker_name VARCHAR(60) NULL, maker_date DATE NULL,
            reviewer_id    INT NULL, reviewer_name VARCHAR(60) NULL, reviewer_date DATE NULL,
            approver_id    INT NULL, approver_name VARCHAR(60) NULL, approver_date DATE NULL,
            created_by     INT NULL, created_by_name VARCHAR(60) NULL,
            created_at     DATETIME NULL, updated_at DATETIME NULL,
            is_deleted     TINYINT NOT NULL DEFAULT 0,
            UNIQUE KEY uk_no (case_no),
            KEY idx_year (year, seq_no)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COMMENT='稽核通知單／稽核案件 2-GM-06-02'");

        $db->exec("CREATE TABLE IF NOT EXISTS ia_case_dept (
            cd_id         INT AUTO_INCREMENT PRIMARY KEY,
            case_id       INT NOT NULL,
            sort_order    INT NOT NULL DEFAULT 0,
            start_process VARCHAR(150) NULL COMMENT '稽核起始主過程',
            dept_id       INT NULL, dept_name VARCHAR(100) NULL COMMENT '受稽單位',
            auditor_id    INT NULL, auditor_name VARCHAR(60) NULL COMMENT '稽核員',
            escort_id     INT NULL, escort_name VARCHAR(60) NULL COMMENT '陪檢員',
            audited_date  DATE NULL, audited_time VARCHAR(5) NULL COMMENT '實際受稽時間',
            improve_due   DATE NULL COMMENT '預定完成改善時間（稽核報告表用，可人工調整）',
            KEY idx_case (case_id)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COMMENT='稽核通知單的受稽單位列'");

        /* ---- 查檢表（三種共用一組表） ---- */
        $db->exec("CREATE TABLE IF NOT EXISTS ia_check (
            check_id    INT AUTO_INCREMENT PRIMARY KEY,
            case_id     INT NULL COMMENT '所屬稽核案件（績效查檢表可不綁案件）',
            year        SMALLINT NOT NULL,
            kind        VARCHAR(10) NOT NULL COMMENT 'as/system/kpi',
            half        VARCHAR(2) NULL COMMENT 'H1/H2（績效查檢表用）',
            title       VARCHAR(150) NULL,
            auditor_id  INT NULL, auditor_name VARCHAR(60) NULL,
            check_date  DATE NULL COMMENT '業務日期（版次回推、圖章日期都用它）',
            status      VARCHAR(20) NOT NULL DEFAULT 'draft' COMMENT 'draft/done',
            created_by  INT NULL, created_by_name VARCHAR(60) NULL,
            created_at  DATETIME NULL, updated_at DATETIME NULL,
            is_deleted  TINYINT NOT NULL DEFAULT 0,
            KEY idx_case (case_id), KEY idx_year (year, kind)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COMMENT='稽核查檢表（AS條文／系統表單／績效KPI 三種共用）'");

        $db->exec("CREATE TABLE IF NOT EXISTS ia_check_item (
            item_id     INT AUTO_INCREMENT PRIMARY KEY,
            check_id    INT NOT NULL,
            sort_order  INT NOT NULL DEFAULT 0,
            is_header   TINYINT NOT NULL DEFAULT 0 COMMENT '1=章節標題列，只列不判定',
            col_a       VARCHAR(255) NULL COMMENT 'as:條文／system:表單編號／kpi:部門',
            col_b       TEXT NULL         COMMENT 'as:建立的文件表單／system:表單名稱／kpi:指標內容',
            col_c       VARCHAR(255) NULL COMMENT 'system:受稽人／kpi:目標',
            col_d       VARCHAR(255) NULL COMMENT 'kpi:受稽人',
            ref_kind    VARCHAR(20) NULL COMMENT '題目來源 as_clause/as_document/kpi_indicator',
            ref_id      INT NULL,
            result      VARCHAR(10) NULL COMMENT 'ok=合格/達成、ng=不合格/沒達成、空=未判定',
            evidence    TEXT NULL COMMENT '所見證據或建議',
            remark      VARCHAR(255) NULL COMMENT '備註（IA/CAR 編號會自動寫這裡）',
            nc_id       INT NULL COMMENT '對應的不符合通知單',
            car_id      INT NULL COMMENT '對應的異常矯正處理單（績效查檢表用）',
            KEY idx_check (check_id)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COMMENT='查檢表明細列'");

        /* ---- AS9100 條文題庫 ---- */
        $db->exec("CREATE TABLE IF NOT EXISTS ia_as_clause (
            clause_id   INT AUTO_INCREMENT PRIMARY KEY,
            sort_order  INT NOT NULL DEFAULT 0,
            is_header   TINYINT NOT NULL DEFAULT 0,
            clause_text TEXT NOT NULL COMMENT '品質管理系統要求',
            doc_ref     TEXT NULL COMMENT '建立的文件、表單',
            is_active   TINYINT NOT NULL DEFAULT 1,
            updated_at  DATETIME NULL, updated_by VARCHAR(60) NULL
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COMMENT='AS稽核查檢表條文題庫（可增修，建一次每年沿用）'");

        /* ---- 2-GM-06-07 內稽不符合通知單 ---- */
        $db->exec("CREATE TABLE IF NOT EXISTS ia_nc (
            nc_id        INT AUTO_INCREMENT PRIMARY KEY,
            nc_no        VARCHAR(30) NULL COMMENT 'IA+西元後兩碼+MMDD+2位流水，如 IA24121601',
            case_id      INT NULL, year SMALLINT NOT NULL,
            dept_id      INT NULL, dept_name VARCHAR(100) NULL COMMENT '受稽核單位',
            auditee_id   INT NULL, auditee_name VARCHAR(60) NULL COMMENT '受審核人',
            audit_date   DATE NULL COMMENT '稽核日期＝本單業務日期',
            src_kind     VARCHAR(20) NULL COMMENT '來自哪張查檢表 as/system/kpi',
            src_item_id  INT NULL,
            ref_form_no  VARCHAR(60) NULL COMMENT '相關表單編號',
            fact         TEXT NULL COMMENT '不合格事實描述',
            nc_type      VARCHAR(10) NULL COMMENT 'major/minor/observe',
            clause_ref   VARCHAR(300) NULL COMMENT '違反條文',
            due_date     DATE NULL COMMENT '要求完成期限',
            auditor_id   INT NULL, auditor_name VARCHAR(60) NULL, auditor_date DATE NULL,
            head_id      INT NULL, head_name VARCHAR(60) NULL, head_date DATE NULL COMMENT '受審查單位主管',
            head_note    TEXT NULL COMMENT '單位主管核示（列印版一併印出）',
            cause        TEXT NULL COMMENT '原因分析',
            corrective   TEXT NULL COMMENT '糾正措施及完成時間',
            preventive   TEXT NULL COMMENT '預防措施及完成時間',
            resp_id      INT NULL, resp_name VARCHAR(60) NULL, resp_date DATE NULL COMMENT '責任主管',
            verify_desc  TEXT NULL COMMENT '糾正和預防措施執行狀況驗證描述',
            verify_result VARCHAR(10) NULL COMMENT 'pass/fail',
            close_note   VARCHAR(300) NULL COMMENT '結束',
            leader_id    INT NULL, leader_name VARCHAR(60) NULL, leader_date DATE NULL COMMENT '稽核組長',
            mgr_note     TEXT NULL COMMENT '管理代表意見',
            mgr_id       INT NULL, mgr_name VARCHAR(60) NULL, mgr_date DATE NULL,
            stage        VARCHAR(20) NOT NULL DEFAULT 'issued' COMMENT 'issued/replied/verified/closed',
            car_id       INT NULL COMMENT '若另開了異常矯正處理單，記在這裡互相連結',
            remind_sent  VARCHAR(20) NULL COMMENT '最後一次提醒日期（避免同一天重複發）',
            created_by   INT NULL, created_by_name VARCHAR(60) NULL,
            created_at   DATETIME NULL, updated_at DATETIME NULL,
            is_deleted   TINYINT NOT NULL DEFAULT 0,
            UNIQUE KEY uk_no (nc_no),
            KEY idx_case (case_id), KEY idx_year (year), KEY idx_stage (stage), KEY idx_due (due_date)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COMMENT='內稽不符合通知單 2-GM-06-07'");

        $db->exec("CREATE TABLE IF NOT EXISTS ia_nc_log (
            log_id     INT AUTO_INCREMENT PRIMARY KEY,
            nc_id      INT NOT NULL,
            stage      VARCHAR(20) NULL,
            action     VARCHAR(30) NOT NULL COMMENT 'create/reply/verify/close/edit/proxy',
            is_proxy   TINYINT NOT NULL DEFAULT 0 COMMENT '1=稽核員代填',
            on_behalf_name VARCHAR(60) NULL COMMENT '代誰填',
            note       VARCHAR(500) NULL,
            by_id      INT NULL, by_name VARCHAR(60) NULL, created_at DATETIME NULL,
            KEY idx_nc (nc_id)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COMMENT='IA 單填寫歷程（含代填紀錄）'");

        /* ---- 2-GM-06-08 稽核報告表（年度一張，內容自動彙總） ---- */
        $db->exec("CREATE TABLE IF NOT EXISTS ia_report (
            report_id   INT AUTO_INCREMENT PRIMARY KEY,
            year        SMALLINT NOT NULL,
            extra_note  TEXT NULL COMMENT '缺點記錄的人工補充文字',
            status      VARCHAR(20) NOT NULL DEFAULT 'draft',
            maker_id    INT NULL, maker_name VARCHAR(60) NULL, maker_date DATE NULL,
            approver_id INT NULL, approver_name VARCHAR(60) NULL, approver_date DATE NULL,
            created_by  INT NULL, created_at DATETIME NULL, updated_at DATETIME NULL,
            is_deleted  TINYINT NOT NULL DEFAULT 0,
            UNIQUE KEY uk_year (year)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COMMENT='稽核報告表 2-GM-06-08'");

        /* ---- 附件（路徑一律即時組，DB 只存檔名＝鐵律5） ---- */
        $db->exec("CREATE TABLE IF NOT EXISTS ia_attach (
            att_id      INT AUTO_INCREMENT PRIMARY KEY,
            ref_type    VARCHAR(20) NOT NULL COMMENT 'case/check/nc/plan/report',
            ref_id      INT NOT NULL,
            file_name   VARCHAR(255) NOT NULL COMMENT '只存檔名，不存絕對路徑',
            orig_name   VARCHAR(255) NULL,
            file_size   INT NULL,
            note        VARCHAR(255) NULL,
            uploaded_by INT NULL, uploaded_by_name VARCHAR(60) NULL, uploaded_at DATETIME NULL,
            is_deleted  TINYINT NOT NULL DEFAULT 0,
            KEY idx_ref (ref_type, ref_id)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COMMENT='內稽附件'");

        /* ---- 受稽單位群組（使用者要求：生產部＋生產1/2/3廠 要當成同一個受稽單位）----
           組織樹上是四個部門，但稽核時是一個單位、計畫表上是一欄、報告表上是一列。
           不改動 dept_id 當主鍵的既有結構：群組挑一個「代表部門」(main_dept_id)，
           ia_plan_dept / ia_case_dept / ia_nc 一律存代表部門的 id，顯示名稱走群組名稱；
           成員部門只影響 ①挑選清單合併成一列 ②◎實際實施歸戶 ③誰算「受稽單位的人」。 */
        $db->exec("CREATE TABLE IF NOT EXISTS ia_audit_unit (
            unit_id      INT AUTO_INCREMENT PRIMARY KEY,
            unit_name    VARCHAR(100) NOT NULL COMMENT '受稽單位名稱（計畫表欄位、報告表列名都用它）',
            main_dept_id INT NOT NULL COMMENT '代表部門，資料一律以它為鍵',
            sort_order   INT NOT NULL DEFAULT 0,
            is_active    TINYINT NOT NULL DEFAULT 1,
            updated_at   DATETIME NULL, updated_by VARCHAR(60) NULL,
            UNIQUE KEY uk_main (main_dept_id)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COMMENT='受稽單位群組（多個部門併成一個受稽單位）'");
        $db->exec("CREATE TABLE IF NOT EXISTS ia_audit_unit_dept (
            ud_id   INT AUTO_INCREMENT PRIMARY KEY,
            unit_id INT NOT NULL,
            dept_id INT NOT NULL,
            UNIQUE KEY uk_ud (unit_id, dept_id),
            KEY idx_dept (dept_id)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COMMENT='受稽單位群組的成員部門'");

        /* ---- 稽核員／陪檢員資格名單（使用者要求：管理員指定哪些部門的哪些人有資格）----
           名單是空的時候一律回退成「全體在職員工」，否則剛裝好會一個人都選不到。 */
        $db->exec("CREATE TABLE IF NOT EXISTS ia_qualified_person (
            qp_id      INT AUTO_INCREMENT PRIMARY KEY,
            kind       VARCHAR(10) NOT NULL COMMENT 'auditor=稽核員 / escort=陪檢員',
            user_id    INT NOT NULL,
            dept_id    INT NULL COMMENT '設定當下所屬部門（僅供分組顯示，判定不靠它）',
            sort_order INT NOT NULL DEFAULT 0,
            updated_at DATETIME NULL, updated_by VARCHAR(60) NULL,
            UNIQUE KEY uk_kind_user (kind, user_id)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COMMENT='稽核員／陪檢員資格名單'");
        /* ---- 角色（module='internal_audit'） ---- */
        foreach ([
            ['ia_admin',   '內稽管理員（管理代表）'],
            ['ia_auditor', '稽核員'],
            ['ia_view',    '內稽檢閱'],
        ] as $r) {
            $st = $db->prepare("SELECT 1 FROM roles WHERE role_code=? AND module='internal_audit' LIMIT 1");
            $st->execute([$r[0]]);
            if (!$st->fetchColumn()) {
                $db->prepare("INSERT INTO roles (role_code, role_name, module) VALUES (?,?, 'internal_audit')")
                   ->execute([$r[0], $r[1]]);
            }
        }
    } catch (Throwable $e) {}
}

/* ============================ 使用者與權限 ============================ */

function ia_current_user(PDO $db): ?array
{
    $uname = $_SESSION['userName'] ?? '';
    if ($uname === '') return null;
    $st = $db->prepare("SELECT id, user_cname, user_status, state FROM `user` WHERE user_uname=?");
    $st->execute([$uname]);
    return $st->fetch(PDO::FETCH_ASSOC) ?: null;
}

function ia_has_role(PDO $db, int $uid, array $codes): bool
{
    if (!$codes) return false;
    $in = implode(',', array_fill(0, count($codes), '?'));
    try {
        $st = $db->prepare("SELECT 1 FROM user_roles ur JOIN roles r ON r.role_id=ur.role_id
                            WHERE ur.user_id=? AND r.module='internal_audit' AND r.role_code IN ($in) LIMIT 1");
        $st->execute(array_merge([$uid], $codes));
        return (bool)$st->fetchColumn();
    } catch (Throwable $e) { return false; }
}

/**
 * 權限：
 *   isAdmin    系統管理者（固定全權）
 *   canAdmin   內稽管理員（管理代表）：計畫表、通知單、設定、刪除、代填、結案
 *   canAudit   稽核員：填查檢表、開 IA 單、驗證
 *   canView    檢閱（唯讀）
 *   canReply   受稽單位：能回覆「自己單位的」IA 單——全體在職員工都有，實際能不能填由
 *              ia_nc_can_reply() 逐單判定（是不是該單位的人／主管）
 */
function ia_perms(PDO $db, ?array $u): array
{
    $none = ['isAdmin'=>false,'canAdmin'=>false,'canAudit'=>false,'canView'=>false,'canReply'=>false,'uid'=>0];
    if (!$u) return $none;
    $uid   = (int)$u['id'];
    $state = (int)($u['state'] ?? 0);
    $ustat = (int)($u['user_status'] ?? 0);
    if ($state === 0 || $ustat === 90) return $none;   // 離職／特殊帳號一律擋（fail-closed）

    $isAdmin = false;
    try {
        $st = $db->prepare("SELECT 1 FROM user_roles ur JOIN roles r ON r.role_id=ur.role_id
                            WHERE ur.user_id=? AND r.role_code IN ('admin','superadmin') LIMIT 1");
        $st->execute([$uid]);
        $isAdmin = (bool)$st->fetchColumn();
    } catch (Throwable $e) {}
    if (!$isAdmin && $uid === 1) $isAdmin = true;      // 超級管理員固定 id=1

    $canAdmin = $isAdmin || ia_has_role($db, $uid, ['ia_admin']);
    $canAudit = $canAdmin || ia_has_role($db, $uid, ['ia_auditor']);
    $canView  = $canAudit || ia_has_role($db, $uid, ['ia_view']);
    return ['isAdmin'=>$isAdmin, 'canAdmin'=>$canAdmin, 'canAudit'=>$canAudit,
            'canView'=>$canView, 'canReply'=>true, 'uid'=>$uid];
}

function ia_role_label(array $p): string
{
    if ($p['isAdmin'])  return '管理者';
    if ($p['canAdmin']) return '內稽管理員（管理代表）';
    if ($p['canAudit']) return '稽核員';
    if ($p['canView'])  return '內稽檢閱';
    return '一般員工（可回覆自己單位的不符合通知單）';
}

/* ============================ 模組設定 ============================ */

/**
 * 【重要，全站通用】`system_parameters.param_value` 是 **JSON NOT NULL** 欄位，不是文字欄位。
 * 直接塞 `top` 這種裸字串，MySQL 會回 3140 Invalid JSON text 把整筆寫入擋下來；
 * 而 `7`／`9` 剛好是合法的 JSON 數字所以存得進去——於是會出現「有些設定存得起來、有些按了說成功卻是空的」
 * 這種極難查的症狀（2026-08-25 使用者回報「核准格跟審查格存完又變回預設」就是這個）。
 * 所以：**寫入一律 json_encode，讀取一律用本函式解**（既有資料有裸值與 JSON 兩種，都要吃得下）。
 */
function ia_setting_decode($raw): string
{
    if ($raw === null) return '';
    $s = (string)$raw;
    $d = json_decode($s, true);
    if ($d === null && strtolower(trim($s)) !== 'null') return $s;   // 不是合法 JSON＝舊的裸值，原樣回
    if (is_bool($d)) return $d ? '1' : '';
    if (is_scalar($d)) return (string)$d;
    return $s;                                                        // 陣列/物件的呼叫端自己 decode
}

function ia_settings(PDO $db): array
{
    $out = array_fill_keys(IA_SETTING_KEYS, '');
    try {
        $st = $db->prepare("SELECT param_key, param_value FROM system_parameters WHERE param_group=?");
        $st->execute([IA_SETTING_GROUP]);
        foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $r) {
            if (array_key_exists($r['param_key'], $out)) $out[$r['param_key']] = ia_setting_decode($r['param_value']);
        }
    } catch (Throwable $e) {}
    if ($out['ia_remind_days'] === '') $out['ia_remind_days'] = '7';
    return $out;
}

/**
 * 存一筆設定。**寫入前一定要 json_encode**（param_value 是 JSON 欄位，見 ia_setting_decode 的說明）。
 * 這裡刻意**不吞例外**：存不進去卻回報成功，使用者只會一直重存卻永遠是空的（本模組已踩過一次）。
 */
function ia_setting_save(PDO $db, string $key, string $val, string $byName): void
{
    if (!in_array($key, IA_SETTING_KEYS, true)) {
        throw new RuntimeException('不支援的設定項目：' . $key);
    }
    $json = json_encode($val, JSON_UNESCAPED_UNICODE);
    $st = $db->prepare("SELECT id FROM system_parameters WHERE param_group=? AND param_key=? LIMIT 1");
    $st->execute([IA_SETTING_GROUP, $key]);
    $id = (int)($st->fetchColumn() ?: 0);
    if ($id) {
        $db->prepare("UPDATE system_parameters SET param_value=?, updated_by=?, updated_at=NOW() WHERE id=?")
           ->execute([$json, $byName, $id]);
    } else {
        $db->prepare("INSERT INTO system_parameters (param_group, param_key, param_value, description, updated_by, updated_at)
                      VALUES (?,?,?,?,?,NOW())")
           ->execute([IA_SETTING_GROUP, $key, $json, '內部稽核模組設定', $byName]);
    }
}

/** DB 當天（PHP date() 是 UTC、MySQL 是本地時間，混用會差一天） */
function ia_today(PDO $db): string
{
    try { return (string)$db->query("SELECT CURDATE()")->fetchColumn(); }
    catch (Throwable $e) { return date('Y-m-d'); }
}

/* ============================ 編號 ============================ */

/**
 * 稽核件號：民國年(3碼) + MMDD + 3 位流水，例 1131105001（2024.11.05 第 1 件）
 * 依「通知日期」產生，不是建檔當天——補歷史單據時編號才跟表單上的日期對得起來。
 */
function ia_next_case_no(PDO $db, string $notifyDate): string
{
    $ts = strtotime($notifyDate ?: 'now');
    if (!$ts) $ts = time();
    $prefix = sprintf('%03d%s', (int)date('Y', $ts) - 1911, date('md', $ts));
    try {
        $st = $db->prepare("SELECT case_no FROM ia_case WHERE case_no LIKE ? ORDER BY case_no DESC LIMIT 1");
        $st->execute([$prefix . '%']);
        $last = (string)($st->fetchColumn() ?: '');
        $n = $last !== '' ? ((int)substr($last, -3)) + 1 : 1;
    } catch (Throwable $e) { $n = 1; }
    return $prefix . sprintf('%03d', $n);
}

/**
 * IA 單號：IA + 西元後兩碼 + MMDD + 2 位流水，例 IA24121601
 * 依「稽核日期」產生（同上理由）。
 */
function ia_next_nc_no(PDO $db, string $auditDate): string
{
    $ts = strtotime($auditDate ?: 'now');
    if (!$ts) $ts = time();
    $prefix = 'IA' . date('y', $ts) . date('md', $ts);
    try {
        $st = $db->prepare("SELECT nc_no FROM ia_nc WHERE nc_no LIKE ? ORDER BY nc_no DESC LIMIT 1");
        $st->execute([$prefix . '%']);
        $last = (string)($st->fetchColumn() ?: '');
        $n = $last !== '' ? ((int)substr($last, -2)) + 1 : 1;
    } catch (Throwable $e) { $n = 1; }
    return $prefix . sprintf('%02d', $n);
}

/* ============================ 年度計劃表 ============================ */

/** 該年度「實際實施」＝該部門在該月真的被稽核過（有已執行的案件且該部門在受稽單位列） */
function ia_plan_actual_map(PDO $db, int $year): array
{
    $out = [];
    try {
        $st = $db->prepare(
            "SELECT cd.dept_id,
                    MONTH(COALESCE(cd.audited_date, c.executed_date, c.audit_from, c.notify_date)) AS m
               FROM ia_case_dept cd
               JOIN ia_case c ON c.case_id = cd.case_id
              WHERE c.year=? AND COALESCE(c.is_deleted,0)=0 AND c.executed=1 AND cd.dept_id IS NOT NULL");
        $st->execute([$year]);
        foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $r) {
            $m = (int)$r['m'];
            if ($m < 1 || $m > 12) continue;
            // 受稽單位群組：稽核「生產2廠」也要算成「生產部」那一欄的 ◎（歸戶到代表部門）
            $out[ia_unit_key_of_dept($db, (int)$r['dept_id']) . '-' . $m] = 1;
        }
    } catch (Throwable $e) {}
    return $out;
}

/** 年度計劃表完整內容（部門欄、○格子、◎格子） */
function ia_plan_get(PDO $db, int $year): ?array
{
    $st = $db->prepare("SELECT * FROM ia_plan WHERE year=? AND COALESCE(is_deleted,0)=0 LIMIT 1");
    $st->execute([$year]);
    $plan = $st->fetch(PDO::FETCH_ASSOC);
    if (!$plan) return null;
    $pid = (int)$plan['plan_id'];

    $st = $db->prepare("SELECT pd.*, d.name AS cur_name FROM ia_plan_dept pd
                        LEFT JOIN department d ON d.id=pd.dept_id
                        WHERE pd.plan_id=? ORDER BY pd.sort_order, pd.pd_id");
    $st->execute([$pid]);
    $depts = $st->fetchAll(PDO::FETCH_ASSOC);

    $st = $db->prepare("SELECT dept_id, month, planned, note FROM ia_plan_cell WHERE plan_id=? AND planned=1");
    $st->execute([$pid]);
    $cells = [];
    foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $r) $cells[(int)$r['dept_id'] . '-' . (int)$r['month']] = $r['note'] ?: '1';

    $plan['depts']  = $depts;
    // 這裡一律維持 PHP 陣列（dashboard 會對它 count()／foreach）。
    // 送給前端之前才轉成物件——理由見 API 的 plan_get。
    $plan['cells']  = $cells;
    $plan['actual'] = ia_plan_actual_map($db, $year);
    return $plan;
}

/* ============================ 查檢表題庫 ============================ */

/** AS9100 條文題庫 */
function ia_as_clauses(PDO $db, bool $activeOnly = true): array
{
    try {
        $sql = "SELECT * FROM ia_as_clause" . ($activeOnly ? " WHERE is_active=1" : "")
             . " ORDER BY sort_order, clause_id";
        return $db->query($sql)->fetchAll(PDO::FETCH_ASSOC);
    } catch (Throwable $e) { return []; }
}

/**
 * 系統稽核紀錄表的題庫＝AS 文件裡的「表單」。
 * 直接查 as_document 現況，不另存一份（鐵律4：另存一份會在文件改名／作廢後繼續顯示舊內容）。
 */
function ia_system_forms(PDO $db): array
{
    try {
        $rows = $db->query(
            "SELECT d.id, d.doc_no, d.doc_name, d.doc_type
               FROM as_document d
              WHERE d.doc_type='表單' AND COALESCE(d.is_obsolete,0)=0
              ORDER BY d.doc_no")->fetchAll(PDO::FETCH_ASSOC);
    } catch (Throwable $e) {
        try {
            $rows = $db->query("SELECT d.id, d.doc_no, d.doc_name, d.doc_type FROM as_document d
                                 WHERE d.doc_type='表單' ORDER BY d.doc_no")->fetchAll(PDO::FETCH_ASSOC);
        } catch (Throwable $e2) { $rows = []; }
    }
    // 同一編號可能有多筆（改版另存一列），只留一筆
    $seen = []; $out = [];
    foreach ($rows as $r) {
        $k = (string)$r['doc_no'];
        if (isset($seen[$k])) continue;
        $seen[$k] = 1; $out[] = $r;
    }
    return $out;
}

/**
 * 績效執行稽核查檢表的題庫＝KPI 模組的指標（kpi_as_indicator）＋該年度目標值。
 * 目標值來源 kpi_as_indicator_year（有年度版本），抓不到就退回指標本身的敘述。
 */
function ia_kpi_indicators(PDO $db, int $year): array
{
    $rows = [];
    try {
        $rows = $db->query("SELECT indicator_id, item_no, name, clause, stat_desc, freq
                              FROM kpi_as_indicator WHERE is_active=1
                             ORDER BY sort_order, item_no")->fetchAll(PDO::FETCH_ASSOC);
    } catch (Throwable $e) { return []; }
    $meta = [];
    try {
        $st = $db->prepare("SELECT * FROM kpi_as_indicator_year WHERE `year`=?");
        $st->execute([$year]);
        foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $r) {
            if (isset($r['indicator_id'])) $meta[(int)$r['indicator_id']] = $r;
        }
    } catch (Throwable $e) {}
    foreach ($rows as &$r) {
        $id = (int)$r['indicator_id'];
        $y  = $meta[$id] ?? [];
        $r['dept_name'] = (string)($y['dept_name'] ?? $y['owner_dept'] ?? '');
        $target = '';
        foreach (['target_text', 'target', 'goal', 'target_desc'] as $k) {
            if (isset($y[$k]) && trim((string)$y[$k]) !== '') { $target = trim((string)$y[$k]); break; }
        }
        if ($target === '') $target = trim((string)($r['stat_desc'] ?? ''));
        $r['target_text'] = $target;
    }
    unset($r);
    return $rows;
}

/** 依種類建出查檢表的初始題目列（勾選哪幾題由呼叫端決定，這裡只負責題庫轉成列） */
function ia_check_build_items(PDO $db, string $kind, int $year, array $pick = []): array
{
    $items = [];
    if ($kind === 'as') {
        foreach (ia_as_clauses($db) as $c) {
            $id = (int)$c['clause_id'];
            if ($pick && !in_array($id, $pick, true)) continue;
            $items[] = ['is_header'=>(int)$c['is_header'], 'col_a'=>(string)$c['clause_text'],
                        'col_b'=>(string)($c['doc_ref'] ?? ''), 'col_c'=>null, 'col_d'=>null,
                        'ref_kind'=>'as_clause', 'ref_id'=>$id];
        }
    } elseif ($kind === 'system') {
        foreach (ia_system_forms($db) as $f) {
            $id = (int)$f['id'];
            if ($pick && !in_array($id, $pick, true)) continue;
            $items[] = ['is_header'=>0, 'col_a'=>(string)$f['doc_no'], 'col_b'=>(string)$f['doc_name'],
                        'col_c'=>null, 'col_d'=>null, 'ref_kind'=>'as_document', 'ref_id'=>$id];
        }
    } elseif ($kind === 'kpi') {
        foreach (ia_kpi_indicators($db, $year) as $k) {
            $id = (int)$k['indicator_id'];
            if ($pick && !in_array($id, $pick, true)) continue;
            $items[] = ['is_header'=>0, 'col_a'=>(string)$k['dept_name'], 'col_b'=>(string)$k['name'],
                        'col_c'=>(string)$k['target_text'], 'col_d'=>null,
                        'ref_kind'=>'kpi_indicator', 'ref_id'=>$id];
        }
    }
    foreach ($items as $i => &$it) $it['sort_order'] = $i + 1;
    unset($it);
    return $items;
}

/* ============================ 依業務日期回推當時職務（ai-rules/22） ============================ */

/**
 * 某部門在「該業務日期當時」的主管（受審查單位主管、責任主管都用它）。
 * 規則（ai-rules/22 四坑）：
 *   ①一律以單據業務日期回推，不是 CURDATE()
 *   ②兼任常才是簽核身分 → 取職級最高（level 最小）那一筆，不只看主職
 *   ③業務日期在過去、回推不到人時**不可退回現況解析器**（會把現在才上任的人蓋到舊文件上），
 *     寧可回 null 少一個章；今日／未來的單據才允許退回 eg_org_dept_manager()
 *   ④過去日期要放行已離職者（那天他本來就在職）
 * 回傳 ['id','name','position_name','department_name'] 或 null
 */
function ia_dept_head_asof(PDO $db, ?int $deptId, ?string $bizDate): ?array
{
    if (!$deptId) return null;
    $today = ia_today($db);
    $date  = ($bizDate && preg_match('/^\d{4}-\d{2}-\d{2}$/', $bizDate)) ? $bizDate : $today;
    $isPast = ($date < $today);

    // 受稽單位若是群組，主管要在整個群組（含各成員部門的子部門）裡找
    $deptIds = ia_unit_dept_scope($db, $deptId);
    if (!$deptIds) $deptIds = eg_dept_subtree_ids($db, $deptId) ?: [$deptId];

    if (!$isPast) {
        // 今日／未來：用現況解析（原鏈），回不到再往下試歷史
        $m = eg_org_dept_manager($db, $deptIds);
        if ($m) {
            return ['id'=>(int)$m['id'], 'name'=>(string)$m['user_cname'],
                    'position_name'=>(string)($m['position_name'] ?? ''), 'department_name'=>''];
        }
    }

    // 依 user_position_history 回推當時所有人的職務，挑出當時掛在該部門且有職級的人
    try {
        $snapAll = eg_position_snapshot_at_bulk($db, $date);
    } catch (Throwable $e) { $snapAll = []; }
    if (!$snapAll) return null;

    // position_id → level（職級），沒設 level 的職稱不算主管
    $lvl = [];
    try {
        foreach ($db->query("SELECT position_id, level FROM position_level WHERE level IS NOT NULL")
                    ->fetchAll(PDO::FETCH_ASSOC) as $r) $lvl[(int)$r['position_id']] = (int)$r['level'];
    } catch (Throwable $e) { return null; }
    if (!$lvl) return null;

    // 過去日期要放行已離職者（那天他本來就在職）；今日／未來才排除非在職
    $stateMap = [];
    try {
        foreach ($db->query("SELECT id, user_cname, COALESCE(state,1) AS st, COALESCE(user_status,0) AS us FROM `user`")
                    ->fetchAll(PDO::FETCH_ASSOC) as $r) $stateMap[(int)$r['id']] = $r;
    } catch (Throwable $e) {}

    $best = null;
    foreach ($snapAll as $uid => $snap) {
        $uid = (int)$uid;
        $ur  = $stateMap[$uid] ?? null;
        if (!$ur) continue;
        if ((int)$ur['us'] === 90) continue;                      // 特殊帳號永遠不算
        if (!$isPast && (int)$ur['st'] === 0) continue;           // 今日／未來不列已離職
        foreach ((array)$snap as $s) {
            $pid = (int)($s['position_id'] ?? 0);
            $did = (int)($s['department_id'] ?? 0);
            if (!$pid || !in_array($did, $deptIds, true)) continue;
            if (!isset($lvl[$pid])) continue;                     // 沒職級＝不是主管
            $cand = ['id'=>$uid, 'name'=>(string)$ur['user_cname'],
                     'position_name'=>(string)($s['position_name'] ?? ''),
                     'department_name'=>(string)($s['department_name'] ?? ''),
                     'level'=>$lvl[$pid]];
            if ($best === null || $cand['level'] < $best['level']) $best = $cand;   // 職級最高＝level 最小
        }
    }
    if ($best) { unset($best['level']); return $best; }
    return null;   // 過去日期查不到就回 null，絕不退回現況
}

/** 某人在某業務日期當時的部門／職稱（圖章用） */
function ia_identity_asof(PDO $db, int $uid, ?string $bizDate): array
{
    if ($uid <= 0) return ['dept'=>'', 'position'=>''];
    $date = ($bizDate && preg_match('/^\d{4}-\d{2}-\d{2}$/', $bizDate)) ? $bizDate : ia_today($db);
    try { $snap = eg_position_snapshot_at($db, $uid, $date); } catch (Throwable $e) { $snap = []; }
    if (!$snap) return ['dept'=>'', 'position'=>''];
    $lvl = [];
    try {
        foreach ($db->query("SELECT position_id, level FROM position_level WHERE level IS NOT NULL")
                    ->fetchAll(PDO::FETCH_ASSOC) as $r) $lvl[(int)$r['position_id']] = (int)$r['level'];
    } catch (Throwable $e) {}
    // 兼任常才是簽核身分：有職級的優先，其中取職級最高；都沒職級才取主職
    $pick = null;
    foreach ($snap as $s) {
        $pid = (int)($s['position_id'] ?? 0);
        if (!isset($lvl[$pid])) continue;
        if ($pick === null || $lvl[$pid] < $lvl[(int)$pick['position_id']]) $pick = $s;
    }
    if ($pick === null) {
        foreach ($snap as $s) { if (!empty($s['is_main'])) { $pick = $s; break; } }
        if ($pick === null) $pick = $snap[0];
    }
    return ['dept'=>(string)($pick['department_name'] ?? ''), 'position'=>(string)($pick['position_name'] ?? '')];
}

/* ============================ 列印簽章格解析 ============================ */

/**
 * 列印用的簽章人。來源由模組設定決定（IA_SIGN_SOURCES），不寫死人名。
 * $ctx: ['leader_id','leader_name','maker_id','maker_name','biz_date']
 * 回傳 ['id','name','dept','position'] 或 null（留白）
 */
function ia_sign_person(PDO $db, string $source, array $ctx): ?array
{
    $uid = 0; $name = '';
    switch ($source) {
        case 'top':
        case 'mgr_rep': {
            $key = ($source === 'top') ? 'top_approver' : 'mgmt_rep';
            $u = eg_org_user($db, $key);
            if (!$u) { $u = eg_org_user($db, 'top_approver'); }
            if ($u) { $uid = (int)($u['id'] ?? 0); $name = (string)($u['user_cname'] ?? ''); }
            break;
        }
        case 'leader':
            $uid = (int)($ctx['leader_id'] ?? 0); $name = (string)($ctx['leader_name'] ?? '');
            break;
        case 'maker':
            $uid = (int)($ctx['maker_id'] ?? 0);  $name = (string)($ctx['maker_name'] ?? '');
            break;
        default:
            return null;
    }
    if (!$uid && $name === '') return null;
    if ($name === '' && $uid) {
        try {
            $st = $db->prepare("SELECT user_cname FROM `user` WHERE id=?");
            $st->execute([$uid]); $name = (string)($st->fetchColumn() ?: '');
        } catch (Throwable $e) {}
    }
    $idt = ia_identity_asof($db, $uid, (string)($ctx['biz_date'] ?? ''));
    return ['id'=>$uid, 'name'=>$name, 'dept'=>$idt['dept'], 'position'=>$idt['position']];
}

/* ============================ 不符合通知單：分段權限 ============================ */

/**
 * 這張 IA 單，目前這個人各段能不能填。
 * 段一 稽核員段（不合格事實／類型／違反條文／期限）
 * 段二 受稽單位段（單位主管核示／原因分析／糾正措施／預防措施／責任主管）
 * 段三 驗證段（稽核組長驗證描述／結束）
 * 段四 管理代表意見
 * 使用者拍板：分段鎖定，但內稽管理員／稽核員可「代填」（proxy），代填會寫進 ia_nc_log。
 */
function ia_nc_stage_perm(PDO $db, array $nc, array $perms, int $uid): array
{
    $stage  = (string)($nc['stage'] ?? 'issued');
    $closed = ($stage === 'closed');
    $isAuditor = $perms['canAudit'] || (int)($nc['auditor_id'] ?? 0) === $uid;
    $isAdmin   = $perms['canAdmin'];

    // 受稽單位：本人是受審核人／該單位主管／該單位的人
    $inDept = false;
    $deptId = (int)($nc['dept_id'] ?? 0);
    if ($deptId) {
        try {
            // 受稽單位若是群組（例：生產部＋生產1/2/3廠），四個部門的人都算受稽單位的人
            $ids = ia_unit_dept_scope($db, $deptId) ?: [$deptId];
            $in  = implode(',', array_fill(0, count($ids), '?'));
            $st  = $db->prepare("SELECT 1 FROM user_department_position_map
                                 WHERE user_id=? AND department_id IN ($in) LIMIT 1");
            $st->execute(array_merge([$uid], $ids));
            $inDept = (bool)$st->fetchColumn();
        } catch (Throwable $e) {}
    }
    $isAuditee = ($uid > 0 && ((int)($nc['auditee_id'] ?? 0) === $uid
                 || (int)($nc['head_id'] ?? 0) === $uid || (int)($nc['resp_id'] ?? 0) === $uid || $inDept));

    return [
        'sec1'  => !$closed && ($isAdmin || $isAuditor),
        'sec2'  => !$closed && ($isAdmin || $isAuditee || $isAuditor)   // 稽核員代填
                   && in_array($stage, ['issued', 'replied', 'verified'], true),
        'sec3'  => !$closed && ($isAdmin || $isAuditor) && $stage !== 'issued',
        'sec4'  => !$closed && $isAdmin,
        'proxy' => ($isAdmin || $isAuditor) && !$isAuditee,   // 這個人填段二算代填
        'close' => $isAdmin && $stage === 'verified',
        'del'   => $isAdmin,
        'view'  => $perms['canView'] || $isAuditee || $isAuditor,
    ];
}

function ia_nc_log_add(PDO $db, int $ncId, string $stage, string $action, int $byId, string $byName,
                       string $note = '', int $isProxy = 0, string $onBehalf = ''): void
{
    try {
        $db->prepare("INSERT INTO ia_nc_log (nc_id, stage, action, is_proxy, on_behalf_name, note, by_id, by_name, created_at)
                      VALUES (?,?,?,?,?,?,?,?,NOW())")
           ->execute([$ncId, $stage, $action, $isProxy, $onBehalf ?: null, $note ?: null, $byId ?: null, $byName]);
    } catch (Throwable $e) {}
}

/* ============================ 不符合通知單：通知與提醒 ============================ */

/** 這張 IA 單該通知誰（受稽單位主管；查不到就通知受審核人） */
function ia_nc_notify_targets(PDO $db, array $nc): array
{
    $out = [];
    $head = (int)($nc['head_id'] ?? 0);
    if (!$head) {
        $h = ia_dept_head_asof($db, (int)($nc['dept_id'] ?? 0), (string)($nc['audit_date'] ?? ''));
        if ($h) $head = (int)$h['id'];
    }
    if ($head) $out[] = $head;
    $auditee = (int)($nc['auditee_id'] ?? 0);
    if ($auditee && !in_array($auditee, $out, true)) $out[] = $auditee;
    return $out;
}

function ia_nc_close_notice(PDO $db, int $ncId, string $refType): void
{
    try {
        $db->prepare("UPDATE live_event SET enddate=DATE_SUB(CURDATE(), INTERVAL 1 DAY)
                      WHERE ref_type=? AND ref_id=? AND (enddate IS NULL OR enddate>=CURDATE())")
           ->execute([$refType, $ncId]);
    } catch (Throwable $e) {}
}

function ia_nc_push(PDO $db, int $eventId, string $title, string $content): void
{
    try {
        require_once __DIR__ . '/../push/push_send.php';
        eg_push_send_to_users($db, eg_push_event_recipients($db, $eventId),
                              ['title'=>$title, 'body'=>mb_substr($content, 0, 480)]);
    } catch (Throwable $e) {}
}

/** IA 單開立 → 通知受稽單位主管填原因分析與措施 */
function ia_notify_nc_issued(PDO $db, array $nc, int $fromUid): int
{
    $targets = ia_nc_notify_targets($db, $nc);
    if (!$targets) return 0;
    $ncId  = (int)$nc['nc_id'];
    $title = '內稽不符合通知單待回覆：' . ($nc['nc_no'] ?: ('#' . $ncId)) . '　' . (string)($nc['dept_name'] ?? '');
    $content = '受稽核單位：' . (string)($nc['dept_name'] ?? '') . "\n"
             . '受審核人：' . (string)($nc['auditee_name'] ?? '') . "\n"
             . '稽核日期：' . eg_fmt_date($nc['audit_date'] ?? '') . "\n"
             . '不合格類型：' . (IA_NC_TYPES[(string)($nc['nc_type'] ?? '')] ?? '（未定）') . "\n"
             . '不合格事實：' . mb_substr((string)($nc['fact'] ?? ''), 0, 300) . "\n"
             . '違反條文：' . (string)($nc['clause_ref'] ?? '') . "\n"
             . '要求完成期限：' . (($nc['due_date'] ?? '') ? eg_fmt_date($nc['due_date']) : '（未定）') . "\n"
             . '請點此開啟，填寫「原因分析／糾正措施／預防措施」並由單位主管填核示後送出。';
    try {
        ia_nc_close_notice($db, $ncId, 'IA_NC_REPLY');
        $db->prepare("INSERT INTO live_event (eventdate, enddate, title, content, status, created_by, source,
                          show_status_to_others, ref_type, ref_id)
                      VALUES (CURDATE(), NULL, ?, ?, 0, ?, '內部稽核', 1, 'IA_NC_REPLY', ?)")
           ->execute([$title, $content, $fromUid ?: null, $ncId]);
        $eid = (int)$db->lastInsertId();
        $ins = $db->prepare("INSERT INTO live_event_target (live_event_id, target_type, target_id, mode)
                             VALUES (?, 'user', ?, 'sign')");
        foreach ($targets as $t) $ins->execute([$eid, $t]);
        ia_nc_push($db, $eid, $title, $content);
        return $eid;
    } catch (Throwable $e) { return 0; }
}

/** 受稽單位回覆完 → 通知稽核員／稽核組長驗證 */
function ia_notify_nc_replied(PDO $db, array $nc, int $fromUid, string $byName): int
{
    $to = (int)($nc['auditor_id'] ?? 0) ?: (int)($nc['leader_id'] ?? 0);
    if (!$to) return 0;
    $ncId  = (int)$nc['nc_id'];
    $title = '內稽不符合通知單已回覆，待驗證：' . ($nc['nc_no'] ?: ('#' . $ncId));
    $content = $byName . ' 已填妥原因分析與糾正／預防措施。' . "\n"
             . '受稽核單位：' . (string)($nc['dept_name'] ?? '') . "\n"
             . '原因分析：' . mb_substr((string)($nc['cause'] ?? ''), 0, 200) . "\n"
             . '糾正措施：' . mb_substr((string)($nc['corrective'] ?? ''), 0, 200) . "\n"
             . '請點此開啟，填寫「糾正和預防措施執行狀況驗證描述」。';
    try {
        $db->prepare("INSERT INTO live_event (eventdate, enddate, title, content, status, created_by, source,
                          show_status_to_others, ref_type, ref_id)
                      VALUES (CURDATE(), NULL, ?, ?, 0, ?, '內部稽核', 1, 'IA_NC_VERIFY', ?)")
           ->execute([$title, $content, $fromUid ?: null, $ncId]);
        $eid = (int)$db->lastInsertId();
        $db->prepare("INSERT INTO live_event_target (live_event_id, target_type, target_id, mode) VALUES (?, 'user', ?, 'sign')")
           ->execute([$eid, $to]);
        ia_nc_push($db, $eid, $title, $content);
        return $eid;
    } catch (Throwable $e) { return 0; }
}

/** 結案 → 通知受稽單位 */
function ia_notify_nc_closed(PDO $db, array $nc, int $fromUid, string $byName): void
{
    $targets = ia_nc_notify_targets($db, $nc);
    if (!$targets) return;
    $ncId  = (int)$nc['nc_id'];
    $title = '內稽不符合通知單已結案：' . ($nc['nc_no'] ?: ('#' . $ncId));
    $content = $byName . ' 已驗證並結案。' . "\n"
             . '驗證描述：' . mb_substr((string)($nc['verify_desc'] ?? ''), 0, 300) . "\n"
             . ((string)($nc['mgr_note'] ?? '') !== '' ? ('管理代表意見：' . mb_substr((string)$nc['mgr_note'], 0, 200)) : '');
    try {
        ia_nc_close_notice($db, $ncId, 'IA_NC_REPLY');
        ia_nc_close_notice($db, $ncId, 'IA_NC_VERIFY');
        ia_nc_close_notice($db, $ncId, 'IA_NC_DUE');
        $db->prepare("INSERT INTO live_event (eventdate, enddate, title, content, status, created_by, source,
                          show_status_to_others, ref_type, ref_id)
                      VALUES (CURDATE(), NULL, ?, ?, 0, ?, '內部稽核', 1, 'IA_NC_RESULT', ?)")
           ->execute([$title, $content, $fromUid ?: null, $ncId]);
        $eid = (int)$db->lastInsertId();
        $ins = $db->prepare("INSERT INTO live_event_target (live_event_id, target_type, target_id, mode)
                             VALUES (?, 'user', ?, 'read')");
        foreach ($targets as $t) $ins->execute([$eid, $t]);
        ia_nc_push($db, $eid, $title, $content);
    } catch (Throwable $e) {}
}

/**
 * 到期提醒（順路觸發：有人開內稽頁或打 API 時跑一次，不另開排程）
 * 期限前 N 天內、以及已逾期而尚未結案的 IA 單，每天最多提醒一次（remind_sent 擋重複）。
 */
function ia_nc_remind_tick(PDO $db): int
{
    static $ran = false;
    if ($ran) return 0;
    $ran = true;
    $sent = 0;
    try {
        $today = ia_today($db);
        $days  = max(0, (int)(ia_settings($db)['ia_remind_days'] ?: 7));
        $st = $db->prepare(
            "SELECT * FROM ia_nc
              WHERE COALESCE(is_deleted,0)=0 AND stage <> 'closed' AND due_date IS NOT NULL
                AND due_date <= DATE_ADD(?, INTERVAL ? DAY)
                AND (remind_sent IS NULL OR remind_sent < ?)
              ORDER BY due_date LIMIT 30");
        $st->execute([$today, $days, $today]);
        foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $nc) {
            $targets = ia_nc_notify_targets($db, $nc);
            if (!$targets) continue;
            $ncId    = (int)$nc['nc_id'];
            $overdue = ((string)$nc['due_date'] < $today);
            $title   = $overdue
                ? ('內稽缺失已逾期未結案：' . ($nc['nc_no'] ?: ('#' . $ncId)))
                : ('內稽缺失即將到期：' . ($nc['nc_no'] ?: ('#' . $ncId)));
            $content = '受稽核單位：' . (string)($nc['dept_name'] ?? '') . "\n"
                     . '要求完成期限：' . eg_fmt_date($nc['due_date'])
                     . ($overdue ? '（已逾期）' : '') . "\n"
                     . '目前狀態：' . (IA_NC_STAGES[(string)$nc['stage']] ?? (string)$nc['stage']) . "\n"
                     . '不合格事實：' . mb_substr((string)($nc['fact'] ?? ''), 0, 200) . "\n"
                     . '請點此開啟處理。';
            $db->prepare("INSERT INTO live_event (eventdate, enddate, title, content, status, created_by, source,
                              show_status_to_others, ref_type, ref_id)
                          VALUES (CURDATE(), NULL, ?, ?, 0, NULL, '內部稽核', 1, 'IA_NC_DUE', ?)")
               ->execute([$title, $content, $ncId]);
            $eid = (int)$db->lastInsertId();
            $ins = $db->prepare("INSERT INTO live_event_target (live_event_id, target_type, target_id, mode)
                                 VALUES (?, 'user', ?, 'read')");
            foreach ($targets as $t) $ins->execute([$eid, $t]);
            ia_nc_push($db, $eid, $title, $content);
            $db->prepare("UPDATE ia_nc SET remind_sent=? WHERE nc_id=?")->execute([$today, $ncId]);
            $sent++;
        }
    } catch (Throwable $e) {}
    return $sent;
}

/* ============================ 稽核報告表：自動彙總 ============================ */

/**
 * 稽核報告表（2-GM-06-08）內容全部由該年度的 IA 單算出來：
 *   每個受稽單位一列：主／次／觀 缺點數、受稽時間、稽核員、預定完成改善時間
 *   缺點記錄＝「單位-IA編號 表單編號 表單名稱」逐條列出
 * 預定完成改善時間預設取該單位所有 IA 單的最晚期限，ia_case_dept.improve_due 有填就以它為準。
 */
function ia_report_data(PDO $db, int $year): array
{
    $rows = [];
    try {
        $st = $db->prepare("SELECT * FROM ia_nc WHERE year=? AND COALESCE(is_deleted,0)=0
                            ORDER BY dept_name, nc_no");
        $st->execute([$year]);
        $rows = $st->fetchAll(PDO::FETCH_ASSOC);
    } catch (Throwable $e) {}

    // 該年度各受稽單位的受稽時間／稽核員／人工指定的改善期限
    $caseDept = [];
    try {
        $st = $db->prepare("SELECT cd.* FROM ia_case_dept cd JOIN ia_case c ON c.case_id=cd.case_id
                            WHERE c.year=? AND COALESCE(c.is_deleted,0)=0
                            ORDER BY cd.audited_date, cd.cd_id");
        $st->execute([$year]);
        foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $r) {
            $k = (string)($r['dept_name'] ?? '');
            if ($k === '') continue;
            if (!isset($caseDept[$k])) $caseDept[$k] = $r;
        }
    } catch (Throwable $e) {}

    $byDept = [];
    $records = [];
    foreach ($rows as $r) {
        $d = (string)($r['dept_name'] ?? '（未指定單位）');
        if (!isset($byDept[$d])) {
            $cd = $caseDept[$d] ?? [];
            $byDept[$d] = [
                'dept_name'    => $d,
                'major'        => 0, 'minor' => 0, 'observe' => 0,
                'audited_date' => (string)($cd['audited_date'] ?? ''),
                'audited_time' => (string)($cd['audited_time'] ?? ''),
                'auditor_name' => (string)($cd['auditor_name'] ?? ''),
                'improve_due'  => (string)($cd['improve_due'] ?? ''),
                'auto_due'     => '',
                'closed'       => 0, 'total' => 0,
            ];
        }
        $t = (string)($r['nc_type'] ?? '');
        if     ($t === 'major')   $byDept[$d]['major']++;
        elseif ($t === 'minor')   $byDept[$d]['minor']++;
        elseif ($t === 'observe') $byDept[$d]['observe']++;
        $byDept[$d]['total']++;
        if ((string)$r['stage'] === 'closed') $byDept[$d]['closed']++;
        $due = (string)($r['due_date'] ?? '');
        if ($due !== '' && $due > $byDept[$d]['auto_due']) $byDept[$d]['auto_due'] = $due;
        // 受稽時間／稽核員：IA 單上有就以它為準（同一單位跨案件時較準）
        if ($byDept[$d]['audited_date'] === '' && (string)($r['audit_date'] ?? '') !== '') {
            $byDept[$d]['audited_date'] = (string)$r['audit_date'];
        }
        if ($byDept[$d]['auditor_name'] === '' && (string)($r['auditor_name'] ?? '') !== '') {
            $byDept[$d]['auditor_name'] = (string)$r['auditor_name'];
        }
        $records[] = [
            'dept_name' => $d,
            'nc_no'     => (string)($r['nc_no'] ?? ''),
            'form_no'   => (string)($r['ref_form_no'] ?? ''),
            'fact'      => (string)($r['fact'] ?? ''),
            'nc_id'     => (int)$r['nc_id'],
            'stage'     => (string)$r['stage'],
        ];
    }
    foreach ($byDept as &$d) { if ($d['improve_due'] === '') $d['improve_due'] = $d['auto_due']; }
    unset($d);

    // 沒有任何缺點但確實受稽過的單位也要列出來（缺點數 0）
    foreach ($caseDept as $name => $cd) {
        if (isset($byDept[$name])) continue;
        $byDept[$name] = [
            'dept_name'=>$name, 'major'=>0, 'minor'=>0, 'observe'=>0,
            'audited_date'=>(string)($cd['audited_date'] ?? ''), 'audited_time'=>(string)($cd['audited_time'] ?? ''),
            'auditor_name'=>(string)($cd['auditor_name'] ?? ''), 'improve_due'=>(string)($cd['improve_due'] ?? ''),
            'auto_due'=>'', 'closed'=>0, 'total'=>0,
        ];
    }

    return ['rows'=>array_values($byDept), 'records'=>$records];
}

/* ============================ 附件 ============================ */

/** 內稽附件目錄（鐵律5：路徑即時組，DB 只存檔名） */
function ia_attach_dir(PDO $db): string
{
    require_once __DIR__ . '/attach_lib.php';
    return eg_attach_dir($db, 'ia_attach_dir', '內部稽核');
}

/** 列印用的圖章模板（含 schema，前端 eg_stamp.js 要吃它才畫得出模板章） */
function ia_stamp_template(PDO $db): ?array
{
    $id = (int)(ia_settings($db)['ia_stamp_tpl_id'] ?? 0);
    if (!$id) return null;
    try {
        $st = $db->prepare("SELECT id, tpl_name, schema_json FROM stamp_template WHERE id=? AND is_active=1");
        $st->execute([$id]);
        $r = $st->fetch(PDO::FETCH_ASSOC);
        if (!$r) return null;
        return ['id' => (int)$r['id'], 'tpl_name' => $r['tpl_name'],
                'schema' => json_decode((string)$r['schema_json'], true)];
    } catch (Throwable $e) { return null; }
}

/* ============================ 受稽單位（含群組） ============================ */

/**
 * 全站的「受稽單位」清單＝已設定的群組 ＋ 沒被任何群組收編的單一部門。
 * 使用者要求：生產部、生產1廠、生產2廠、生產3廠 這種要能綁成同一個受稽單位。
 * 回傳每列：
 *   key       代表部門 id（ia_plan_dept / ia_case_dept / ia_nc 一律存這個）
 *   name      顯示名稱（群組用群組名稱，單一部門用部門名稱）
 *   unit_id   群組 id（單一部門為 0）
 *   dept_ids  這個受稽單位涵蓋的所有部門 id（單一部門就是自己一個）
 *   is_group  1=群組
 */
function ia_audit_units(PDO $db): array
{
    $depts = [];
    try {
        foreach ($db->query("SELECT id, name, parent_id, level, sort_order FROM department ORDER BY sort_order, id")
                    ->fetchAll(PDO::FETCH_ASSOC) as $d) $depts[(int)$d['id']] = $d;
    } catch (Throwable $e) { return []; }

    $units = []; $taken = [];
    try {
        $rows = $db->query("SELECT * FROM ia_audit_unit WHERE is_active=1 ORDER BY sort_order, unit_id")
                   ->fetchAll(PDO::FETCH_ASSOC);
        $mem = [];
        foreach ($db->query("SELECT unit_id, dept_id FROM ia_audit_unit_dept")->fetchAll(PDO::FETCH_ASSOC) as $m) {
            $mem[(int)$m['unit_id']][] = (int)$m['dept_id'];
        }
        foreach ($rows as $u) {
            $uid  = (int)$u['unit_id'];
            $main = (int)$u['main_dept_id'];
            $ids  = $mem[$uid] ?? [];
            if (!in_array($main, $ids, true)) $ids[] = $main;          // 代表部門一定算成員
            $ids = array_values(array_filter($ids, function ($i) use ($depts) { return isset($depts[$i]); }));
            if (!$ids || !isset($depts[$main])) continue;              // 部門被刪掉的群組直接跳過
            foreach ($ids as $i) $taken[$i] = 1;
            $units[] = ['key' => $main, 'name' => (string)$u['unit_name'], 'unit_id' => $uid,
                        'dept_ids' => $ids, 'is_group' => 1,
                        'members' => array_map(function ($i) use ($depts) { return $depts[$i]['name']; }, $ids),
                        'sort_order' => (int)$u['sort_order']];
        }
    } catch (Throwable $e) {}

    foreach ($depts as $id => $d) {
        if (isset($taken[$id])) continue;
        $units[] = ['key' => $id, 'name' => (string)$d['name'], 'unit_id' => 0,
                    'dept_ids' => [$id], 'is_group' => 0, 'members' => [(string)$d['name']],
                    'sort_order' => (int)$d['sort_order']];
    }
    usort($units, function ($a, $b) {
        if ($a['sort_order'] !== $b['sort_order']) return $a['sort_order'] <=> $b['sort_order'];
        return $a['key'] <=> $b['key'];
    });
    return $units;
}

/** 部門 id → 它所屬受稽單位的代表部門 id（沒被群組收編就是自己） */
function ia_unit_key_of_dept(PDO $db, int $deptId): int
{
    static $map = null;
    if ($map === null) {
        $map = [];
        foreach (ia_audit_units($db) as $u) foreach ($u['dept_ids'] as $d) $map[$d] = $u['key'];
    }
    return $map[$deptId] ?? $deptId;
}

/** 某受稽單位涵蓋的所有部門 id（含各自子部門），用於判定「這個人是不是受稽單位的人」 */
function ia_unit_dept_scope(PDO $db, int $unitKey): array
{
    $out = [];
    foreach (ia_audit_units($db) as $u) {
        if ($u['key'] !== $unitKey) continue;
        foreach ($u['dept_ids'] as $d) {
            foreach (eg_dept_subtree_ids($db, $d) ?: [$d] as $x) $out[(int)$x] = 1;
        }
        break;
    }
    if (!$out) foreach (eg_dept_subtree_ids($db, $unitKey) ?: [$unitKey] as $x) $out[(int)$x] = 1;
    return array_keys($out);
}

/** 檢查群組設定是否合法（成員不可被別的群組佔走、代表部門必須是成員之一） */
function ia_unit_validate(PDO $db, int $unitId, string $name, int $mainDeptId, array $deptIds): string
{
    $name = trim($name);
    if ($name === '') return '請填受稽單位名稱';
    if (mb_strlen($name) > 50) return '受稽單位名稱過長（上限 50 字）';
    $deptIds = array_values(array_unique(array_filter(array_map('intval', $deptIds))));
    if (count($deptIds) < 2) return '群組至少要有兩個部門（只有一個部門不需要設群組）';
    if (!$mainDeptId || !in_array($mainDeptId, $deptIds, true)) return '代表部門必須是成員之一';
    try {
        $in = implode(',', array_fill(0, count($deptIds), '?'));
        $st = $db->prepare("SELECT COUNT(*) FROM department WHERE id IN ($in)");
        $st->execute($deptIds);
        if ((int)$st->fetchColumn() !== count($deptIds)) return '有部門不存在';
        $st = $db->prepare("SELECT u.unit_name FROM ia_audit_unit_dept ud
                            JOIN ia_audit_unit u ON u.unit_id=ud.unit_id AND u.is_active=1
                            WHERE ud.dept_id IN ($in) AND ud.unit_id<>? LIMIT 1");
        $st->execute(array_merge($deptIds, [$unitId]));
        $dup = $st->fetchColumn();
        if ($dup) return '有部門已經被「' . $dup . '」收編了，一個部門只能屬於一個受稽單位';
    } catch (Throwable $e) { return '檢查失敗：' . $e->getMessage(); }
    return '';
}

/* ============================ 稽核員／陪檢員資格名單 ============================ */

const IA_QUALIFY_KINDS = ['auditor' => '稽核員', 'escort' => '陪檢員'];

/**
 * 某身分的合格人員清單。
 * **名單沒設定時一律回全體在職員工**——否則模組剛上線一個人都挑不到，
 * 使用者會以為壞掉（而且這種「空名單＝什麼都不能選」的設計每次都要被回報一次）。
 * 回傳格式與 eg_people_list() 相同，畫面上的下拉可以直接用。
 */
function ia_qualified_people(PDO $db, string $kind): array
{
    $all = [];
    try { $all = eg_people_list($db, []); } catch (Throwable $e) { $all = []; }
    if (!isset(IA_QUALIFY_KINDS[$kind])) return $all;
    $ids = [];
    try {
        $st = $db->prepare("SELECT user_id FROM ia_qualified_person WHERE kind=?");
        $st->execute([$kind]);
        $ids = array_map('intval', $st->fetchAll(PDO::FETCH_COLUMN));
    } catch (Throwable $e) {}
    if (!$ids) return $all;                       // 沒設定＝不限制
    $set = array_flip($ids);
    // 已離職的人不會出現在 eg_people_list，所以名單裡的離職者自然被濾掉（正確行為）
    return array_values(array_filter($all, function ($p) use ($set) { return isset($set[(int)$p['id']]); }));
}

/** 目前設定的名單（管理畫面用），回 kind => [user_id,...] */
function ia_qualify_map(PDO $db): array
{
    $out = array_fill_keys(array_keys(IA_QUALIFY_KINDS), []);
    try {
        foreach ($db->query("SELECT kind, user_id FROM ia_qualified_person ORDER BY sort_order, qp_id")
                    ->fetchAll(PDO::FETCH_ASSOC) as $r) {
            if (isset($out[$r['kind']])) $out[$r['kind']][] = (int)$r['user_id'];
        }
    } catch (Throwable $e) {}
    return $out;
}

/** 整批覆寫某身分的名單（空陣列＝不限制，全體在職員工都可選） */
function ia_qualify_save(PDO $db, string $kind, array $userIds, string $byName): void
{
    if (!isset(IA_QUALIFY_KINDS[$kind])) throw new RuntimeException('身分別不正確');
    $userIds = array_values(array_unique(array_filter(array_map('intval', $userIds))));
    if ($userIds) {
        $in = implode(',', array_fill(0, count($userIds), '?'));
        $st = $db->prepare("SELECT COUNT(*) FROM `user` WHERE id IN ($in)");
        $st->execute($userIds);
        if ((int)$st->fetchColumn() !== count($userIds)) throw new RuntimeException('有人員不存在');
    }
    $db->prepare("DELETE FROM ia_qualified_person WHERE kind=?")->execute([$kind]);
    if (!$userIds) return;
    $ins = $db->prepare("INSERT INTO ia_qualified_person (kind, user_id, dept_id, sort_order, updated_at, updated_by)
                         VALUES (?,?,?,?,NOW(),?)");
    $dept = $db->prepare("SELECT department_id FROM user_department_position_map WHERE user_id=? LIMIT 1");
    $i = 0;
    foreach ($userIds as $uid) {
        $dept->execute([$uid]);
        $ins->execute([$kind, $uid, ($dept->fetchColumn() ?: null), ++$i * 10, $byName]);
    }
}

/** 年度下拉的選項：已有資料的年度 ＋ 近十年到明年（管理員要補舊年度資料，選單裡就得選得到） */
function ia_year_options(PDO $db): array
{
    $years = [];
    try {
        $years = array_map('intval', $db->query(
            "SELECT DISTINCT year FROM (
                SELECT year FROM ia_plan  WHERE COALESCE(is_deleted,0)=0
                UNION SELECT year FROM ia_case WHERE COALESCE(is_deleted,0)=0
                UNION SELECT year FROM ia_nc   WHERE COALESCE(is_deleted,0)=0
                UNION SELECT year FROM ia_check WHERE COALESCE(is_deleted,0)=0
                UNION SELECT year FROM ia_report) x")->fetchAll(PDO::FETCH_COLUMN));
    } catch (Throwable $e) {}
    $cy = (int)substr(ia_today($db), 0, 4);
    for ($y = $cy + 1; $y >= $cy - 10; $y--) $years[] = $y;
    $years = array_values(array_unique(array_filter($years)));
    rsort($years);
    return $years;
}
