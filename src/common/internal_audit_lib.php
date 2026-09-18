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
    'ia_case_remark_tpl',
    'ia_auto_sign',
    'ia_report_notify',   // 稽核報告表送出後要通知誰：[{dept_id, position_id}] JSON（管理員設定）
    'ia_extra_years',     // 管理員登記「要補資料的舊年度」JSON 陣列（選單只列有資料的年度＋今年明年＋這裡登記的）
    'ia_auto_sign_case',  // 稽核通知單按下「完成」時要不要直接簽完（與年度計畫表的 ia_auto_sign 分開設定）
];

/**
 * 稽核通知單「備註」的內建預設文字（＝紙本 2-GM-06-02 上印好的附註）。
 * 只在**這個設定從來沒被存過**時才拿來當預設值（見 ia_settings）；
 * 管理員一旦存過（含刻意存成空白），就一律以設定值為準，不可以再偷偷蓋回這段文字——
 * 否則會出現「清空存檔後又自己跑回來」這種存了卻像沒存的症狀。
 */
const IA_CASE_REMARK_DEFAULT =
      "1.稽核員以過程導向由稽核起始主過程開始循序完成所有相關過程；稽核項目除主過程外，應包含其相關管理及支援過程，但跳過自己的直接職務。\n"
    . "2.主過程:客戶需求檢討→開發→訂單/合約審查→生產→倉儲出貨→客戶回饋\n"
    . "　管理過程:包含但不限文件/記錄管理、人力資源訓練、不符合管理、資料分析、內部稽核、矯正/預防措施管理、持續改善、管理責任…等。\n"
    . "　支援過程:包含但不限採購、供應商管理、IQC/FAI/IPQC/FQC、儀器/量具、機器/治具、生管、型態(鑑別追溯)、特殊特性…等。";

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
            case_no        VARCHAR(30) NULL COMMENT '稽核件號（西元年後兩碼+MMDD+3位流水）',
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

        /* 2026-08-27 使用者要求：一個受稽單位的稽核員／陪檢員都可能不只一位。
           人員一律存在這張子表（唯一來源）；ia_case_dept 上的
           auditor_id/auditor_name/escort_id/escort_name 降級為「顯示用快取」
           （name＝全部姓名以、串接、id/dept/position＝第一位），
           只由 ia_cd_people_set() 一處寫入，不可在別處各自更新。 */
        $db->exec("CREATE TABLE IF NOT EXISTS ia_case_dept_person (
            cdp_id      INT AUTO_INCREMENT PRIMARY KEY,
            cd_id       INT NOT NULL COMMENT 'ia_case_dept.cd_id',
            case_id     INT NOT NULL COMMENT '冗餘，權限判定要直接依案件查',
            kind        VARCHAR(10) NOT NULL COMMENT 'auditor 稽核員／escort 陪檢員',
            sort_order  INT NOT NULL DEFAULT 0,
            user_id     INT NULL COMMENT '解析不到人的舊紙本資料留 NULL，只保姓名',
            user_name   VARCHAR(60) NULL,
            dept_id     INT NULL COMMENT '以哪個部門的職務執行（圖章／資格用）',
            position_id INT NULL,
            KEY idx_cd (cd_id, kind, sort_order),
            KEY idx_case (case_id, kind),
            KEY idx_user (user_id)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COMMENT='受稽單位的稽核員／陪檢員（可多位）'");

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

        /* ---- 條文 ↔ AS 文件的「作業項目」（2026-09-11 使用者交辦）----
           條文是 AS9100 原文（「8.4 外部提供的過程、產品和服務的控制」），看不出實務上在查什麼；
           作業項目（品管檢測／外包加工…）掛在 AS 文件底下（as_doc_task，唯一實作在 asdoc_lib.php），
           這裡記的是「這一條對應到該文件底下的哪幾個項目」——同一份文件常常不只做一件事，
           所以不能只連到文件，要連到項目。 */
        $db->exec("CREATE TABLE IF NOT EXISTS ia_as_clause_task (
            clause_id INT NOT NULL,
            task_id   INT NOT NULL,
            PRIMARY KEY (clause_id, task_id),
            KEY idx_iact_task (task_id)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COMMENT='AS條文對應的作業項目（as_doc_task）'");

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
        /* 資格認到「人員＋部門＋職稱」＝一個職務一筆（使用者要求）。
           理由：兼任的人，主職可能沒有稽核員資格、兼任職才有（或反過來），
           所以不能只認到「人」。挑選稽核員時也是挑「職務」，才知道他是以哪個身分執行稽核。 */
        $db->exec("CREATE TABLE IF NOT EXISTS ia_qualified_person (
            qp_id       INT AUTO_INCREMENT PRIMARY KEY,
            kind        VARCHAR(10) NOT NULL COMMENT 'auditor=稽核員 / escort=陪檢員',
            user_id     INT NOT NULL,
            dept_id     INT NOT NULL DEFAULT 0 COMMENT '該職務的部門（判定的一部分）',
            position_id INT NOT NULL DEFAULT 0 COMMENT '該職務的職稱（判定的一部分）',
            sort_order  INT NOT NULL DEFAULT 0,
            updated_at  DATETIME NULL, updated_by VARCHAR(60) NULL,
            UNIQUE KEY uk_kind_post (kind, user_id, dept_id, position_id)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COMMENT='稽核員／陪檢員資格名單（認到人員＋部門＋職稱）'");
        // 舊版是 UNIQUE(kind,user_id)、沒有 position_id；就地升級（可重複執行）
        try {
            $cols = $db->query("SHOW COLUMNS FROM ia_qualified_person LIKE 'position_id'")->fetchAll();
            if (!$cols) {
                $db->exec("ALTER TABLE ia_qualified_person
                             ADD COLUMN position_id INT NOT NULL DEFAULT 0 COMMENT '該職務的職稱' AFTER dept_id");
                $db->exec("ALTER TABLE ia_qualified_person MODIFY dept_id INT NOT NULL DEFAULT 0");
                try { $db->exec("ALTER TABLE ia_qualified_person DROP INDEX uk_kind_user"); } catch (Throwable $e) {}
                try { $db->exec("ALTER TABLE ia_qualified_person
                                 ADD UNIQUE KEY uk_kind_post (kind, user_id, dept_id, position_id)"); } catch (Throwable $e) {}
            }
            // 舊制是「認到人」，升級後那些列的 position_id 會是 0，比對不到任何職務＝誰都選不到。
            // 語意上「以前這個人有資格」＝他的每一個職務都有資格，所以就地展開成該員的所有職務。
            // 可重複執行：只處理 position_id=0 的殘留列。
            $old = $db->query("SELECT qp_id, kind, user_id FROM ia_qualified_person WHERE position_id=0")
                      ->fetchAll(PDO::FETCH_ASSOC);
            if ($old) {
                $posts = [];
                foreach (eg_people_posts($db, []) as $p) $posts[(int)$p['id']][] = $p;
                $ins = $db->prepare("INSERT IGNORE INTO ia_qualified_person
                                        (kind, user_id, dept_id, position_id, sort_order, updated_at, updated_by)
                                     VALUES (?,?,?,?,0,NOW(),'schema-upgrade')");
                $del = $db->prepare("DELETE FROM ia_qualified_person WHERE qp_id=?");
                foreach ($old as $o) {
                    foreach ($posts[(int)$o['user_id']] ?? [] as $p) {
                        $ins->execute([$o['kind'], (int)$o['user_id'], (int)$p['dept_id'], (int)$p['position_id']]);
                    }
                    $del->execute([(int)$o['qp_id']]);
                }
            }
            /* 2026-09-09 使用者要求：名單只記「部門＋職稱」，不記人名——人員會異動，
               但「這個職稱可以當稽核員」不會變；人名在建通知單的當下即時抓該部門該職稱的在職人員。
               既有的「人員＋部門＋職稱」列已於當時就地換成該職務（user_id=0＝整個職務）。
               ★ 2026-09-16 起這段一次性轉換**必須移除**：新制的「職位＋指定人員」規則正是 user_id<>0
                 的列（代理人臨時具備資格、AS 負責人…），留著這段等於每次開頁面就把使用者剛設的
                 指定人員默默洗成「整個職務都有資格」——同職稱的其他人也會一起變成有資格，
                 而且完全不報錯。要再轉一次請寫一次性 migration，不要放在會反覆執行的建表流程裡。 */

            /* 2026-09-16 使用者要求：資格可以設成「職位」或「職位＋指定人員」，
               指定人員還要能給任期（本人請假由代理人暫代那段期間才有資格）。
               rule_kind: job=部門＋職稱（user_id=0） / user=部門＋職稱＋指定人員（user_id>0）
               start_date/end_date：空＝不限（與 as_doc_editor_term 的任期語意完全一致）。 */
            foreach ([
                ['rule_kind',  "VARCHAR(10) NOT NULL DEFAULT 'job' COMMENT 'job=部門＋職稱 / user=指定人員' AFTER kind"],
                ['start_date', "DATE NULL COMMENT '任期起（空=不限）' AFTER position_id"],
                ['end_date',   "DATE NULL COMMENT '任期迄（空=至今）' AFTER start_date"],
                ['note',       "VARCHAR(100) NULL COMMENT '備註（例：代理葉卿雅）' AFTER end_date"],
            ] as $c) {
                $has = $db->query("SHOW COLUMNS FROM ia_qualified_person LIKE '{$c[0]}'")->fetchAll();
                if (!$has) $db->exec("ALTER TABLE ia_qualified_person ADD COLUMN `{$c[0]}` {$c[1]}");
            }
            // 舊列一律是「部門＋職稱」規則
            $db->exec("UPDATE ia_qualified_person SET rule_kind='job' WHERE user_id=0 AND rule_kind<>'job'");
            // 同一人同一職務可以有多段任期，所以舊的 UNIQUE(kind,user_id,dept_id,position_id) 要放寬
            try { $db->exec("ALTER TABLE ia_qualified_person DROP INDEX uk_kind_post"); } catch (Throwable $e) {}
            try { $db->exec("ALTER TABLE ia_qualified_person ADD KEY idx_kind (kind, rule_kind)"); } catch (Throwable $e) {}
        } catch (Throwable $e) {}

        /* 稽核員／陪檢員／稽核組長是「以哪個職務」執行稽核——存到職務層級，
           圖章的部門職稱才印得對，也才能對得上資格名單。舊資料只有 user_id，這些欄位留空不影響。 */
        foreach ([
            ['ia_case_dept', 'auditor_dept_id',     "INT NULL COMMENT '稽核員的部門'"],
            ['ia_case_dept', 'auditor_position_id', "INT NULL COMMENT '稽核員的職稱'"],
            ['ia_case_dept', 'escort_dept_id',      "INT NULL COMMENT '陪檢員的部門'"],
            ['ia_case_dept', 'escort_position_id',  "INT NULL COMMENT '陪檢員的職稱'"],
            ['ia_case',      'leader_dept_id',      "INT NULL COMMENT '稽核組長的部門'"],
            ['ia_case',      'leader_position_id',  "INT NULL COMMENT '稽核組長的職稱'"],
            ['ia_check',     'auditor_dept_id',     "INT NULL COMMENT '稽核人的部門'"],
            ['ia_check',     'auditor_position_id', "INT NULL COMMENT '稽核人的職稱'"],
            // 稽核通知單「完成」（2026-09-18 使用者要求：完成後不可修改、取消要管理員＋操作確認密碼）
            ['ia_case',      'completed_at',        "DATETIME NULL COMMENT '按下完成的時間'"],
            ['ia_case',      'completed_by',        "INT NULL COMMENT '按下完成的人 user.id'"],
            ['ia_case',      'completed_by_name',   "VARCHAR(60) NULL COMMENT '按下完成的人姓名（顯示用快取）'"],
            ['ia_case',      'reviewer_at',         "DATETIME NULL COMMENT '審查簽核的精確時間（業務日期在 reviewer_date）'"],
            /* 系統稽核紀錄表（2026-09-18 使用者要求）：
               受稽人要存「是誰」而不只是姓名（開 IA 單時要直接帶人，不可以用姓名去猜）；
               表單編號與名稱一律**存快照**，日後改編號／改名／廢止都不影響已建立的紀錄。 */
            ['ia_check_item', 'auditee_id',         "INT NULL COMMENT '受稽人 user.id（姓名仍存在 col_c，這裡是可靠的身分）'"],
            ['ia_check_item', 'auditee_dept_id',    "INT NULL COMMENT '受稽人當時的部門'"],
            ['ia_check_item', 'auditee_position_id',"INT NULL COMMENT '受稽人當時的職稱'"],
            ['ia_check_item', 'doc_no_snap',        "VARCHAR(60) NULL COMMENT 'AS 文件編號快照（建立當下）'"],
            ['ia_check_item', 'doc_name_snap',      "VARCHAR(150) NULL COMMENT 'AS 文件名稱快照（建立當下）'"],
            ['ia_nc',         'ref_form_name',      "VARCHAR(150) NULL COMMENT '相關表單名稱快照（編號存在 ref_form_no）'"],
            ['ia_case',      'approver_at',         "DATETIME NULL COMMENT '核准簽核的精確時間（業務日期在 approver_date）'"],
            // 稽核報告表改成「送出通知」流程（2026-09-17 使用者要求：不要核准、不要製表人）
            ['ia_report',    'submit_date',         "DATE NULL COMMENT '送出的業務日期'"],
            ['ia_report',    'submitted_at',        "DATETIME NULL COMMENT '按下送出的精確時間（與業務日期分開存，ai-rules/21）'"],
            ['ia_report',    'submitted_by',        "INT NULL COMMENT '送出者 user.id'"],
            ['ia_report',    'submitted_by_name',   "VARCHAR(60) NULL COMMENT '送出者姓名（顯示用快取）'"],
        ] as $c) {
            try {
                $has = $db->query("SHOW COLUMNS FROM `{$c[0]}` LIKE '{$c[1]}'")->fetchAll();
                if (!$has) $db->exec("ALTER TABLE `{$c[0]}` ADD COLUMN `{$c[1]}` {$c[2]}");
            } catch (Throwable $e) {}
        }
        /* ---- 稽核範本：稽核起始主過程 ＋ 受稽單位 ＋ 稽核員／陪檢員候選部門 ----
           管理員預先設定好，填稽核通知單時一列一列帶入。
           候選部門是「多選」，實際人員仍由填表人挑；候選範圍內只有一位有資格時自動帶入。 */
        $db->exec("CREATE TABLE IF NOT EXISTS ia_process_template (
            tpl_id       INT AUTO_INCREMENT PRIMARY KEY,
            process_name VARCHAR(150) NOT NULL COMMENT '稽核起始主過程',
            unit_dept_id INT NOT NULL COMMENT '受稽單位（受稽單位群組的代表部門）',
            note         VARCHAR(255) NULL,
            sort_order   INT NOT NULL DEFAULT 0,
            is_active    TINYINT NOT NULL DEFAULT 1,
            updated_at   DATETIME NULL, updated_by VARCHAR(60) NULL,
            KEY idx_active (is_active, sort_order)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COMMENT='稽核範本（起始主過程＋受稽單位＋稽核員/陪檢員候選部門）'");
        $db->exec("CREATE TABLE IF NOT EXISTS ia_process_tpl_dept (
            td_id   INT AUTO_INCREMENT PRIMARY KEY,
            tpl_id  INT NOT NULL,
            kind    VARCHAR(10) NOT NULL COMMENT 'auditor=稽核員候選部門 / escort=陪檢員候選部門',
            dept_id INT NOT NULL,
            UNIQUE KEY uk_td (tpl_id, kind, dept_id)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COMMENT='稽核範本的候選部門（多選）'");
        /* ---- 稽核範本「組合」（2026-09-14 使用者交辦）----
           常常一起稽核的那幾個範本存成一組，填通知單時選一次就整批帶入好幾列，
           不必一列一列挑。組合只是「範本的清單」，本身不存主過程／單位／人員，
           所以範本改了組合帶出來的內容自動跟著改（鐵律4：不複製第二份）。 */
        $db->exec("CREATE TABLE IF NOT EXISTS ia_process_tpl_set (
            set_id     INT AUTO_INCREMENT PRIMARY KEY,
            set_name   VARCHAR(100) NOT NULL COMMENT '組合名稱（例：上半年度全廠稽核）',
            note       VARCHAR(255) NULL,
            sort_order INT NOT NULL DEFAULT 0,
            is_active  TINYINT NOT NULL DEFAULT 1,
            updated_at DATETIME NULL, updated_by VARCHAR(60) NULL,
            KEY idx_set_active (is_active, sort_order)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COMMENT='稽核範本組合（一次帶入多列受稽單位）'");
        $db->exec("CREATE TABLE IF NOT EXISTS ia_process_tpl_set_item (
            si_id      INT AUTO_INCREMENT PRIMARY KEY,
            set_id     INT NOT NULL,
            tpl_id     INT NOT NULL,
            sort_order INT NOT NULL DEFAULT 0,
            UNIQUE KEY uk_si (set_id, tpl_id),
            KEY idx_si_tpl (tpl_id)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COMMENT='稽核範本組合的成員範本'");

        /* ---- 稽核小組（2026-09-16 使用者交辦）----
           「今年的內稽是誰在做」——建稽核通知單前先組好這一年的小組，
           自動建立會議紀錄時**與會人員＝小組成員、主席＝稽核組長**，不必每次重挑。
           小組是逐年的，可以從其他年度整批複製過來再增減。
           存到職務層級（dept_id/position_id），圖章與會議紀錄的部門職稱才印得對。 */
        /* 小組的「基準日」（2026-09-16 使用者要求）：候選人員與他們的部門職稱，一律以這一天為準
           （沒有日期就無從判斷當時是誰在那個職務上）。留空＝沿用系統推算值（過去年度取該年年底、
           當年度以後取今天），所以舊資料不設也不會壞。 */
        $db->exec("CREATE TABLE IF NOT EXISTS ia_team (
            year       INT PRIMARY KEY,
            base_date  DATE NULL COMMENT '判定在職與職務的基準日；空=系統推算',
            updated_at DATETIME NULL, updated_by VARCHAR(60) NULL
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COMMENT='年度稽核小組的設定（基準日）'");
        $db->exec("CREATE TABLE IF NOT EXISTS ia_team_member (
            tm_id       INT AUTO_INCREMENT PRIMARY KEY,
            year        INT NOT NULL,
            role        VARCHAR(10) NOT NULL COMMENT 'leader=稽核組長 / auditor=稽核員 / escort=陪檢員',
            user_id     INT NOT NULL,
            user_name   VARCHAR(60) NULL COMMENT '顯示用快取；姓名一律以 user 表為準',
            dept_id     INT NULL,
            position_id INT NULL,
            note        VARCHAR(100) NULL,
            sort_order  INT NOT NULL DEFAULT 0,
            updated_at  DATETIME NULL, updated_by VARCHAR(60) NULL,
            UNIQUE KEY uk_team (year, user_id, dept_id, position_id),
            KEY idx_year (year, sort_order)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COMMENT='年度稽核小組成員'");

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
    $has = [];
    try {
        $st = $db->prepare("SELECT param_key, param_value FROM system_parameters WHERE param_group=?");
        $st->execute([IA_SETTING_GROUP]);
        foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $r) {
            if (array_key_exists($r['param_key'], $out)) {
                $out[$r['param_key']] = ia_setting_decode($r['param_value']);
                $has[$r['param_key']] = true;
            }
        }
    } catch (Throwable $e) {}
    if ($out['ia_remind_days'] === '') $out['ia_remind_days'] = '7';
    // 從來沒設定過＝沿用紙本印好的那段附註；設定過就一律以設定值為準（存成空白＝不自動帶入）
    if (empty($has['ia_case_remark_tpl'])) $out['ia_case_remark_tpl'] = IA_CASE_REMARK_DEFAULT;
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
 * 稽核件號：西元年後兩碼 + MMDD + 3 位流水，例 241216001（2024.12.16 第 1 件）
 * 依「稽核日期（稽核起）」產生，不是建檔當天——補歷史單據時編號才跟紙本對得起來
 * （2024 兩張紙本 241115001／241216001 就是用稽核日期編的）。沒填稽核起時才退回通知日期。
 */
function ia_next_case_no(PDO $db, string $baseDate, int $exceptCaseId = 0): string
{
    $ts = strtotime($baseDate ?: 'now');
    if (!$ts) $ts = time();
    // 2026-08-27 使用者指定改用「西元年後兩碼＋MMDD＋3 位流水」（例 241216001），
    // 與 IA 單號（IA+西元後兩碼+MMDD+2位）同一套年份寫法，不再混用民國年。
    $prefix = date('ymd', $ts);
    try {
        // 重編時要把「自己」排除掉，否則同一天重算會一直往後跳號
        $sql = "SELECT case_no FROM ia_case WHERE case_no LIKE ?"
             . ($exceptCaseId ? " AND case_id<>?" : "") . " ORDER BY case_no DESC LIMIT 1";
        $st  = $db->prepare($sql);
        $st->execute($exceptCaseId ? [$prefix . '%', $exceptCaseId] : [$prefix . '%']);
        $last = (string)($st->fetchColumn() ?: '');
        $n = $last !== '' ? ((int)substr($last, -3)) + 1 : 1;
    } catch (Throwable $e) { $n = 1; }
    return $prefix . sprintf('%03d', $n);
}

/** 稽核件號的基準日：稽核起，沒填才退回通知日期 */
function ia_case_no_base(array $case): string
{
    $af = trim((string)($case['audit_from'] ?? ''));
    if ($af !== '' && $af !== '0000-00-00') return $af;
    return (string)($case['notify_date'] ?? '');
}

/**
 * 稽核日期被改過（或編號是舊規則留下來的）時把件號重編。
 * 產品開發評估表／PFMEA 的 *_sync_doc_no() 同一套想法：編號前八碼永遠＝表單上的日期。
 * **只重編還是草稿、且未執行的**——已發出／執行中／已結案的紙本上印著舊號，改了會對不起來。
 * 回傳 ['changed'=>bool, 'old'=>string, 'new'=>string]
 */
function ia_case_sync_no(PDO $db, int $caseId): array
{
    $out = ['changed' => false, 'old' => '', 'new' => ''];
    try {
        $st = $db->prepare("SELECT case_id, case_no, notify_date, audit_from, status, executed
                              FROM ia_case WHERE case_id=? AND COALESCE(is_deleted,0)=0");
        $st->execute([$caseId]);
        $c = $st->fetch(PDO::FETCH_ASSOC);
        if (!$c) return $out;
        $out['old'] = $out['new'] = (string)$c['case_no'];
        if ((string)$c['status'] !== 'draft' || (int)$c['executed'] === 1) return $out;

        $base = ia_case_no_base($c);
        $ts   = strtotime($base ?: 'now');
        if (!$ts) return $out;
        $prefix = date('ymd', $ts);
        // 已經是這個日期開頭的就不動（流水號不重排，免得同一天的幾張互相換號）
        if (strncmp((string)$c['case_no'], $prefix, 6) === 0 && strlen((string)$c['case_no']) === 9) return $out;

        $no = ia_next_case_no($db, $base, $caseId);
        $db->prepare("UPDATE ia_case SET case_no=? WHERE case_id=?")->execute([$no, $caseId]);
        $out['new'] = $no;
        $out['changed'] = ($no !== $out['old']);
    } catch (Throwable $e) {}
    return $out;
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

/* ---- 條文的「作業項目」（2026-09-11）------------------------------------------
 * 條文的「建立的文件、表單」是自由文字（`名稱(編號)`，以空白或換行分隔），
 * 所以要先把裡面的**文件編號**撈出來，才知道這一條可以挑哪些作業項目。
 * 編號格式全站一致：`1-GM-01`／`2-GM-06-02`（階-部門碼-序號[-序號]）。
 */
function ia_clause_doc_nos(?string $docRef): array
{
    if (!$docRef) return [];
    preg_match_all('/\d-[A-Za-z]{2,3}-\d{2}(?:-\d{2})*[A-Za-z]?/u', (string)$docRef, $m);
    return array_values(array_unique($m[0] ?? []));
}

/**
 * 這一條可以挑的作業項目，依文件分組：[ ['doc_id','doc_no','doc_name','tasks'=>[['task_id','task_name'],…]], … ]
 * 文件編號打錯／該文件已刪除時那一份自然不會出現（不報錯，題庫本來就允許自由填寫）。
 */
function ia_clause_doc_tasks(PDO $db, ?string $docRef): array
{
    $nos = ia_clause_doc_nos($docRef);
    if (!$nos) return [];
    try {
        $in = implode(',', array_fill(0, count($nos), '?'));
        $st = $db->prepare("SELECT id, doc_no, doc_name FROM as_document
                             WHERE is_deleted=0 AND doc_no IN ($in) ORDER BY doc_no");
        $st->execute($nos);
        $docs = $st->fetchAll(PDO::FETCH_ASSOC);
    } catch (Throwable $e) { return []; }
    if (!$docs) return [];
    $tasks = eg_asdoc_tasks($db, array_column($docs, 'id'));
    $byDoc = [];
    foreach ($tasks as $t) {
        $byDoc[(int)$t['doc_id']][] = ['task_id'=>(int)$t['task_id'], 'task_name'=>(string)$t['task_name']];
    }
    $out = [];
    foreach ($docs as $d) {
        $out[] = ['doc_id'=>(int)$d['id'], 'doc_no'=>(string)$d['doc_no'], 'doc_name'=>(string)$d['doc_name'],
                  'tasks'=>$byDoc[(int)$d['id']] ?? []];
    }
    return $out;
}

/** 已挑選的作業項目：clause_id => [['task_id','task_name','doc_no','doc_name'],…]（$clauseIds 留空＝全部） */
function ia_clause_task_map(PDO $db, array $clauseIds = []): array
{
    $out = [];
    try {
        $sql = "SELECT ct.clause_id, t.task_id, t.task_name, d.doc_no, d.doc_name
                  FROM ia_as_clause_task ct
                  JOIN as_doc_task t ON t.task_id = ct.task_id
                  JOIN as_document d ON d.id = t.doc_id AND d.is_deleted = 0";
        $arg = [];
        $clauseIds = array_values(array_unique(array_filter(array_map('intval', $clauseIds))));
        if ($clauseIds) {
            $sql .= " WHERE ct.clause_id IN (" . implode(',', array_fill(0, count($clauseIds), '?')) . ")";
            $arg = $clauseIds;
        }
        $sql .= " ORDER BY d.doc_no, t.sort_order, t.task_id";
        $st = $db->prepare($sql);
        $st->execute($arg);
        foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $r) {
            $out[(int)$r['clause_id']][] = ['task_id'=>(int)$r['task_id'], 'task_name'=>(string)$r['task_name'],
                                            'doc_no'=>(string)$r['doc_no'], 'doc_name'=>(string)$r['doc_name']];
        }
    } catch (Throwable $e) {}
    return $out;
}

/**
 * 存某一條文挑選的作業項目。
 * 鐵律8：只收「真的屬於這一條所列文件」的項目——否則直打 API 就能把任意文件的項目掛上來，
 * 畫面上會出現跟這條完全無關的用途標籤。回傳實際寫入的筆數。
 */
function ia_clause_tasks_save(PDO $db, int $clauseId, array $taskIds, ?string $docRef = null): int
{
    if ($docRef === null) {
        $st = $db->prepare("SELECT doc_ref FROM ia_as_clause WHERE clause_id=?");
        $st->execute([$clauseId]);
        $docRef = (string)($st->fetchColumn() ?: '');
    }
    $allow = [];
    foreach (ia_clause_doc_tasks($db, $docRef) as $d) {
        foreach ($d['tasks'] as $t) $allow[(int)$t['task_id']] = true;
    }
    $ids = [];
    foreach ($taskIds as $t) { $t = (int)$t; if ($t > 0 && isset($allow[$t])) $ids[$t] = true; }
    $ids = array_keys($ids);

    $db->prepare("DELETE FROM ia_as_clause_task WHERE clause_id=?")->execute([$clauseId]);
    if ($ids) {
        $ins = $db->prepare("INSERT IGNORE INTO ia_as_clause_task (clause_id, task_id) VALUES (?,?)");
        foreach ($ids as $t) $ins->execute([$clauseId, $t]);
    }
    return count($ids);
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

/* ---- 表單 → 品質管理系統要求（AS 條文）反查（2026-09-15 使用者交辦）-------------------
 * 系統稽核紀錄表查的是「某一份表單」，但開不符合通知單時「違反條文」要填的是 AS9100 條文。
 * 條文題庫的 doc_ref 本來就寫著「這一條建立了哪些文件、表單」，所以反過來查就得到
 * 「這份表單對應到哪幾條要求」——**不另外建一張對照表**（鐵律4：兩份對照表遲早走鐘，
 * 而且條文題庫本來就會改）。
 * 回傳 doc_no => [ ['clause_id'=>, 'clause_text'=>], … ]
 */
function ia_clause_map_by_doc_no(PDO $db): array
{
    static $cache = null;
    if ($cache !== null) return $cache;
    $out = [];
    foreach (ia_as_clauses($db) as $c) {
        if ((int)$c['is_header'] === 1) continue;                 // 章節標題列不是要求本身
        foreach (ia_clause_doc_nos($c['doc_ref'] ?? '') as $no) {
            $out[$no][] = ['clause_id' => (int)$c['clause_id'], 'clause_text' => (string)$c['clause_text']];
        }
    }
    return $cache = $out;
}

/** 條文清單 → 不符合通知單「違反條文」欄位文字（一條一行；欄位長度 300） */
function ia_clause_ref_text(array $clauses, int $max = 300): string
{
    $t = [];
    foreach ($clauses as $c) {
        $x = trim((string)($c['clause_text'] ?? ''));
        if ($x !== '' && !in_array($x, $t, true)) $t[] = $x;
    }
    return mb_substr(implode("\n", $t), 0, $max);
}

/** AS 文件編號的部門代碼（編號第二段），如 2-SM-01-02 → SM */
function ia_doc_dept_code(?string $docNo): string
{
    if (!$docNo) return '';
    return preg_match('/^\s*\d-([A-Za-z]{2,3})-/', (string)$docNo, $m) ? strtoupper($m[1]) : '';
}

/**
 * 系統稽核紀錄表的題庫（表單清單）＋「這份表單屬於哪個部門」＋「對應到哪幾條要求」。
 * 部門一律由編號的部門代碼推導（as_dept_code，不寫死），所以新增部門代碼不必回頭改這裡。
 */
function ia_system_forms_full(PDO $db): array
{
    $codes = ia_as_dept_code_names($db);
    $cmap  = ia_clause_map_by_doc_no($db);
    $out = [];
    foreach (ia_system_forms($db) as $f) {
        $code = ia_doc_dept_code($f['doc_no'] ?? '');
        $f['dept_code'] = $code;
        $f['dept_name'] = $codes[$code] ?? ($code !== '' ? $code : '未分類');
        $f['clauses']   = $cmap[(string)$f['doc_no']] ?? [];
        $out[] = $f;
    }
    return $out;
}

/* ---- 績效執行稽核查檢表（2-GM-06-03）：全部自動判定 ----------------------------------
 * 2026-09-15 使用者拍板三件事：
 *   ①這張表稽核的是**去年一整年**（2025 年建立＝稽核 2024 年度），所以不分上／下半年。
 *   ②受稽人＝KPI 頁面（views/news/KPI.php）設定的**擔當者**，且部門／職稱要正確——
 *     擔當者有兼任問題（何沐桐主職技術課工程師、兼生管組組長，KPI 上兩個部門各有指標），
 *     所以職稱一定要用「該指標登記的那個部門」去解析，不可以拿主職或職級最高的那筆。
 *   ③達成／沒達成自動判定：**該年度只要有任一次未達標就算沒達成**。
 * 判定一律走 KPI 模組自己的 kpi_as_display_value()／kpi_as_below_target()，不自己再寫一套。
 */
function ia_kpi_audit_year(?string $checkDate): int
{
    $y = (int)substr((string)($checkDate ?: date('Y-m-d')), 0, 4);
    return $y - 1;                       // 今年建立＝稽核去年整年度
}

/** 擔當者的部門：優先用 owner_dept_id，沒設就從 owner_display「姓名/部門」拆出來 */
function ia_kpi_owner_dept_name(array $iy): string
{
    $disp = (string)($iy['owner_display'] ?? '');
    if (strpos($disp, '/') !== false) {
        $p = explode('/', $disp);
        $d = trim((string)end($p));
        if ($d !== '') return $d;
    }
    return '';
}

/**
 * 該年度每一項 KPI 指標的稽核列（含自動判定結果）。
 * 回傳每列：indicator_id, dept_name, name, target_text, owner_id, owner_name,
 *           owner_dept_name, owner_position_name, result(ok/ng/''), detail, months
 */
function ia_kpi_audit_rows(PDO $db, int $year): array
{
    require_once __DIR__ . '/kpi_as_lib.php';
    $rows = [];
    try {
        $st = $db->prepare(
            "SELECT i.indicator_id, i.item_no, i.name, i.freq, i.value_type, i.stat_desc,
                    y.owner_user_id, y.owner_dept_id, y.owner_position_id, y.owner_display,
                    y.source_mode, y.target_direction, y.target_value, y.target_unit, y.target_text
               FROM kpi_as_indicator i
               JOIN kpi_as_indicator_year y ON y.indicator_id = i.indicator_id AND y.`year` = ?
              WHERE i.is_active = 1 AND COALESCE(y.is_active,1) = 1
              ORDER BY i.sort_order, i.item_no");
        $st->execute([$year]);
        $rows = $st->fetchAll(PDO::FETCH_ASSOC);
    } catch (Throwable $e) { return []; }
    if (!$rows) return [];

    // 該年度所有月值一次撈回來（逐列查會變成 21×12 次查詢）
    $vals = [];
    try {
        $st = $db->prepare("SELECT * FROM kpi_as_monthly_value WHERE `year`=?");
        $st->execute([$year]);
        foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $v) $vals[(int)$v['indicator_id']][(int)$v['month']] = $v;
    } catch (Throwable $e) {}

    // 擔當者的職稱：一定要用「該指標登記的那個部門」的職務（兼任）
    $posts = [];
    try { foreach (eg_people_posts($db, []) as $p) $posts[(int)$p['id']][] = $p; } catch (Throwable $e) {}
    $unitName = [];
    try {
        foreach ($db->query("SELECT id, name FROM department")->fetchAll(PDO::FETCH_ASSOC) as $d)
            $unitName[(int)$d['id']] = (string)$d['name'];
    } catch (Throwable $e) {}

    $out = [];
    foreach ($rows as $r) {
        $iid  = (int)$r['indicator_id'];
        $oid  = (int)($r['owner_user_id'] ?? 0);
        $dept = (int)($r['owner_dept_id'] ?? 0) ? ($unitName[(int)$r['owner_dept_id']] ?? '') : ia_kpi_owner_dept_name($r);
        $oname = ''; $opos = '';
        if ($oid) {
            $cands = $posts[$oid] ?? [];
            foreach ($cands as $p) {
                $oname = (string)$p['user_cname'];
                if (($dept !== '' && (string)$p['dept_name'] === $dept)
                 || ((int)($r['owner_dept_id'] ?? 0) && (int)$p['dept_id'] === (int)$r['owner_dept_id'])) {
                    $opos = (string)$p['position_name'];
                    if ($dept === '') $dept = (string)$p['dept_name'];
                    break;
                }
            }
            if ($oname === '') {
                $q = $db->prepare("SELECT user_cname FROM `user` WHERE id=?"); $q->execute([$oid]);
                $oname = (string)($q->fetchColumn() ?: '');
            }
            if ($opos === '' && $cands) {          // 該部門查不到職務（例如事後調動）→ 退回主職，但不改部門
                foreach ($cands as $p) { if ((int)$p['is_main'] === 1) { $opos = (string)$p['position_name']; break; } }
                if ($opos === '') $opos = (string)$cands[0]['position_name'];
            }
        }
        if ($oname === '') {                        // 連擔當者都沒設：退回 owner_display 的姓名段
            $disp = (string)($r['owner_display'] ?? '');
            $oname = trim(explode('/', $disp)[0] ?? '');
        }

        // 目標文字：優先用設定的 target_text，沒有才用 方向+值+單位 組出來
        $target = trim((string)($r['target_text'] ?? ''));
        if ($target === '' && $r['target_value'] !== null) {
            $dir = ['gte' => '以上', 'lte' => '以下', 'yes' => ''][$r['target_direction']] ?? '';
            $target = rtrim(rtrim(number_format((float)$r['target_value'], 2, '.', ''), '0'), '.')
                    . (string)$r['target_unit'] . $dir;
        }
        if ($target === '') $target = trim((string)($r['stat_desc'] ?? ''));

        // 判定：該年度**任一次**未達標就是沒達成（使用者拍板）
        $iy = ['target_value' => $r['target_value'], 'target_direction' => $r['target_direction'],
               'freq' => $r['freq'], 'source_mode' => $r['source_mode']];
        $months = kpi_as_valid_months($iy);
        $fail = []; $have = 0; $miss = [];
        foreach ($months as $m) {
            $mv = $vals[$iid][$m] ?? null;
            $v  = kpi_as_display_value($mv);
            if ($v === null) { $miss[] = $m; continue; }
            $have++;
            if (kpi_as_below_target($v, $iy)) {
                $fail[] = ['month' => $m, 'value' => $v];
            }
        }
        $result = $fail ? 'ng' : ($have ? 'ok' : '');
        $unit = (string)$r['target_unit'];
        $detail = '';
        if ($fail) {
            $detail = $year . ' 年 ' . implode('、', array_map(function ($f) use ($unit) {
                return $f['month'] . '月（' . rtrim(rtrim(number_format($f['value'], 2, '.', ''), '0'), '.') . $unit . '）';
            }, $fail)) . ' 未達標，目標 ' . $target;
        } elseif ($have) {
            $detail = $year . ' 年共 ' . $have . ' 筆實績全部達標，目標 ' . $target;
        } else {
            $detail = $year . ' 年沒有任何實績資料（KPI 尚未填報），無法自動判定';
        }
        if ($miss && $have) $detail .= '；未填報月份：' . implode('、', $miss);

        $out[] = [
            'indicator_id' => $iid,
            'item_no'      => (int)$r['item_no'],
            'name'         => (string)$r['name'],
            'freq'         => (string)$r['freq'],
            'freq_label'   => ['monthly'=>'每月','quarterly'=>'每季','halfyear'=>'每半年','yearly'=>'每年'][$r['freq']] ?? (string)$r['freq'],
            'dept_name'    => $dept,
            'target_text'  => $target,
            'owner_id'     => $oid ?: null,
            'owner_name'   => $oname,
            'owner_position_name' => $opos,
            'result'       => $result,
            'detail'       => $detail,
            'fail_months'  => array_column($fail, 'month'),
            'have'         => $have,
        ];
    }
    return $out;
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
        // 2026-09-15 起：部門／目標／受稽人（擔當者）／達成與否全部自動帶，
        // 管理員只要填建立日期。判定結果一併寫進 result 與 evidence（所見證據）。
        foreach (ia_kpi_audit_rows($db, $year) as $k) {
            $id = (int)$k['indicator_id'];
            if ($pick && !in_array($id, $pick, true)) continue;
            $items[] = ['is_header'=>0, 'col_a'=>(string)$k['dept_name'], 'col_b'=>(string)$k['name'],
                        'col_c'=>(string)$k['target_text'], 'col_d'=>(string)$k['owner_name'],
                        'ref_kind'=>'kpi_indicator', 'ref_id'=>$id,
                        'result'=>(string)$k['result'], 'evidence'=>(string)$k['detail']];
        }
    }
    foreach ($items as &$it0) {
        if (!array_key_exists('result', $it0))   $it0['result'] = null;
        if (!array_key_exists('evidence', $it0)) $it0['evidence'] = null;
    }
    unset($it0);
    foreach ($items as $i => &$it) $it['sort_order'] = $i + 1;
    unset($it);
    return $items;
}

/* ---- 績效沒達成 → 自動開立異常矯正處理單（CAR）------------------------------------
 * 2026-09-15 使用者交辦：績效執行稽核查檢表「沒達成」的項目要自動開 CAR
 * （views/QA/correction_order.php），單上要寫清楚哪個年度、哪些月份沒達成、達成率／合格條件，
 * 並固定加上一句「請說明原因及確認是否需要調整KPI目標?」。
 * **不自己再寫一套 CAR 流程**：配號、簽章、紀錄、通知一律用 CAR 模組自己的函式，
 * 這裡只負責組出內容並寫入 car_order（CAR 的建立端點吃的是表單 POST，不能直接呼叫）。
 */
function ia_car_create_from_kpi(PDO $db, array $check, array $item, int $uid, string $uname): array
{
    require_once __DIR__ . '/car_lib.php';
    require_once __DIR__ . '/car_notify.php';

    $year    = ia_kpi_audit_year((string)($check['check_date'] ?? ''));
    $bizDate = substr((string)($check['check_date'] ?? ''), 0, 10);
    if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $bizDate)) $bizDate = date('Y-m-d');
    $deptName = trim((string)($item['col_a'] ?? ''));
    $target   = trim((string)($item['col_c'] ?? ''));
    $owner    = trim((string)($item['col_d'] ?? ''));

    // 判定明細：優先用建立查檢表時算好的「所見證據」（那就是未達標月份與數值）
    $detail = trim((string)($item['evidence'] ?? ''));
    $rowNow = null;
    foreach (ia_kpi_audit_rows($db, $year) as $k) {
        if ((int)$k['indicator_id'] === (int)($item['ref_id'] ?? 0)) { $rowNow = $k; break; }
    }
    if ($detail === '' && $rowNow) $detail = (string)$rowNow['detail'];
    $freqLab = $rowNow['freq_label'] ?? '';

    $desc = '【' . $year . ' 年度 績效執行稽核查檢表（2-GM-06-03）】' . "\n"
          . '指標項目：' . trim((string)($item['col_b'] ?? '')) . ($deptName !== '' ? '（' . $deptName . '）' : '') . "\n"
          . '統計週期：' . ($freqLab !== '' ? $freqLab : '—') . "\n"
          . '合格條件（KPI 目標）：' . ($target !== '' ? $target : '—') . "\n"
          . '稽核結果：沒達成' . "\n"
          . '未達成情形：' . ($detail !== '' ? $detail : '—') . "\n"
          . '請說明原因及確認是否需要調整KPI目標?';

    // 責任單位：以指標登記的部門為準（名稱→id；查不到就不綁部門，單子照樣開得出來）
    $deptId = null;
    if ($deptName !== '') {
        $q = $db->prepare("SELECT id FROM department WHERE name=? ORDER BY id LIMIT 1");
        $q->execute([$deptName]);
        $deptId = (int)($q->fetchColumn() ?: 0) ?: null;
    }
    $ownerId = null;
    if ($owner !== '' && $rowNow && !empty($rowNow['owner_id'])) $ownerId = (int)$rowNow['owner_id'];

    $own = !$db->inTransaction();
    if ($own) $db->beginTransaction();
    try {
        $no = car_alloc_numbers($db, 1, date('Ymd', strtotime($bizDate)))[0];
        $db->prepare(
            "INSERT INTO car_order (car_no, group_no, source_type, source_no, source_desc,
                 fill_date, found_date, created_by, created_by_name,
                 resp_type, resp_dept_id, resp_person_id, resp_display,
                 abnormal_desc, status, stage_since, created_at, updated_at)
             VALUES (?,?, 'OTHER', ?,?, ?,?, ?,?, ?,?,?,?, ?, 'open', NOW(), NOW(), NOW())")
           ->execute([$no, $no,
                      mb_substr('2-GM-06-03 ' . $year . '年度績效執行稽核查檢表', 0, 50),
                      mb_substr(trim((string)($item['col_b'] ?? '')), 0, 255),
                      $bizDate, $bizDate, $uid, $uname,
                      $deptId ? 'dept' : null, $deptId, $ownerId,
                      mb_substr(trim($deptName . ($owner !== '' ? ' ' . $owner : '')), 0, 120) ?: null,
                      $desc]);
        $carId = (int)$db->lastInsertId();

        // 異常說明由填表人自動簽章（與 CAR 建立端點同一條規則）
        $db->prepare("INSERT INTO car_signature (car_id, section, signed_by, signed_name, signed_at, signed_date_label)
                      VALUES (?, 'desc', ?, ?, NOW(), ?)")
           ->execute([$carId, $uid, $uname, eg_fmt_date($bizDate)]);
        car_log($db, $carId, 'create', $uid, $uname, '由 ' . $year . ' 年度績效執行稽核查檢表自動開立');

        // 擔當者就是回覆人，免主管再指派一次
        if ($ownerId) {
            $db->prepare("UPDATE car_order SET status='assigned', assigned_to=?, assigned_to_name=?,
                             assigned_by=?, assigned_by_name=?, assigned_at=NOW(), stage_since=NOW() WHERE id=?")
               ->execute([$ownerId, $owner, $uid, $uname, $carId]);
            car_log($db, $carId, 'assign', $uid, $uname, 'KPI 擔當者「' . $owner . '」自動指定為回覆人');
        }
        $db->prepare("UPDATE ia_check_item SET car_id=?, remark=? WHERE item_id=?")
           ->execute([$carId, $no, (int)$item['item_id']]);
        if ($own) $db->commit();
    } catch (Throwable $e) {
        if ($own && $db->inTransaction()) $db->rollBack();
        throw $e;
    }

    try {   // 通知（走 CAR 模組自己的通知，推播失敗不影響開單）
        $ro = $db->prepare("SELECT * FROM car_order WHERE id=?"); $ro->execute([$carId]);
        $o = $ro->fetch(PDO::FETCH_ASSOC) ?: [];
        if ($o) {
            if (!empty($o['assigned_to'])) {
                car_notify($db, $carId, car_notify_title('🔧', $o, '指派您回覆'),
                    car_notify_body($db, $o, '本單由內部稽核（績效執行稽核查檢表）自動開立，請說明原因並確認是否需要調整 KPI 目標。'),
                    [(int)$o['assigned_to']], $uid, 'reply');
                car_notify($db, $carId, car_notify_title('📣', $o, '貴單位被開立矯正單'),
                    car_notify_body($db, $o, '由內部稽核自動開立。'),
                    array_diff(car_primary_recipients($db, $o), [(int)$o['assigned_to']]), $uid);
            } else {
                car_notify($db, $carId, car_notify_title('🔧', $o, '待指派回覆人'),
                    car_notify_body($db, $o, '由內部稽核（績效執行稽核查檢表）自動開立。'),
                    car_primary_recipients($db, $o), $uid);
            }
        }
    } catch (Throwable $e) {}

    return ['car_id' => $carId, 'car_no' => $no];
}

/**
 * AS稽核查檢表依「系統稽核紀錄表」自動判定合格／不合格（2026-09-15 使用者交辦）。
 * 規則：一條要求（條文）底下列了哪些文件表單是題庫本來就寫好的（doc_ref），
 *   ①那些表單只要有任何一份在來源的系統稽核紀錄表被判**不合格** → 這一條就是不合格，
 *     並在「所見證據或建議」列出是哪幾份（編號＋名稱＋IA 單號），方便跟系統稽核紀錄表比對；
 *   ②全部都合格（至少查過一份） → 合格；
 *   ③一份都沒查到 → **不判定**（留白給稽核員自己看），不要亂猜成合格。
 * 回傳 ['ng'=>n, 'ok'=>n, 'skip'=>n]
 */
function ia_as_apply_system_result(PDO $db, int $checkId, int $srcCheckId): array
{
    $st = $db->prepare("SELECT i.col_a, i.col_b, i.result, n.nc_no
                          FROM ia_check_item i LEFT JOIN ia_nc n ON n.nc_id = i.nc_id
                         WHERE i.check_id=? AND i.is_header=0 AND i.ref_kind='as_document'");
    $st->execute([$srcCheckId]);
    $forms = [];
    foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $r) {
        $no = trim((string)$r['col_a']);
        if ($no === '') continue;
        $forms[$no] = ['name' => (string)$r['col_b'], 'result' => (string)$r['result'], 'nc_no' => (string)($r['nc_no'] ?? '')];
    }
    $out = ['ng' => 0, 'ok' => 0, 'skip' => 0];
    if (!$forms) return $out;

    $st = $db->prepare("SELECT item_id, ref_id FROM ia_check_item
                         WHERE check_id=? AND is_header=0 AND ref_kind='as_clause'");
    $st->execute([$checkId]);
    $items = $st->fetchAll(PDO::FETCH_ASSOC);
    if (!$items) return $out;

    $refs = [];
    foreach ($db->query("SELECT clause_id, doc_ref FROM ia_as_clause")->fetchAll(PDO::FETCH_ASSOC) as $c)
        $refs[(int)$c['clause_id']] = (string)($c['doc_ref'] ?? '');

    $upd = $db->prepare("UPDATE ia_check_item SET result=?, evidence=? WHERE item_id=?");
    foreach ($items as $it) {
        $ngs = []; $oks = [];
        foreach (ia_clause_doc_nos($refs[(int)$it['ref_id']] ?? '') as $no) {
            if (!isset($forms[$no])) continue;
            $f = $forms[$no];
            if ($f['result'] === 'ng') $ngs[] = $no . ' ' . $f['name'] . ($f['nc_no'] !== '' ? '（' . $f['nc_no'] . '）' : '');
            elseif ($f['result'] === 'ok') $oks[] = $no . ' ' . $f['name'];
        }
        if ($ngs) {
            $upd->execute(['ng', '系統稽核紀錄表不合格：' . implode('；', $ngs), (int)$it['item_id']]);
            $out['ng']++;
        } elseif ($oks) {
            $upd->execute(['ok', '系統稽核紀錄表已查核：' . implode('；', $oks), (int)$it['item_id']]);
            $out['ok']++;
        } else {
            $out['skip']++;
        }
    }
    return $out;
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
            // 名稱一律用 id 回查現名（快照裡是當時凍結的舊名，部門改過名就會印出已經不用的名字）
            $cand = ['id'=>$uid, 'name'=>(string)$ur['user_cname'],
                     'position_name'=>ia_position_name_now($db, $pid, (string)($s['position_name'] ?? '')),
                     'department_name'=>ia_dept_name_now($db, $did, (string)($s['department_name'] ?? '')),
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
    /* 名稱一律用 department_id／position_id **回查目前的名稱**，查不到才退回快照裡凍結的舊名
       （2026-09-16 使用者回報：會議紀錄印出「技術部」，但 department 表裡根本沒有這個名字）。
       原因：`user_position_history` 的快照把「當時的名稱」也一起存進 JSON，部門後來由
       「技術部」改名成「技術課」，快照裡仍是舊名。**改名不是改組織**——同一個 department_id
       就是同一個單位，要印現在的名字；只有那個 id 真的被刪掉了才退回快照名（至少還看得懂）。
       共用的 eg_people_posts_asof() 本來就是這樣做，這裡沒跟上＝兩份規則走鐘（鐵律4）。 */
    return ['dept'     => ia_dept_name_now($db, (int)($pick['department_id'] ?? 0), (string)($pick['department_name'] ?? '')),
            'position' => ia_position_name_now($db, (int)($pick['position_id'] ?? 0), (string)($pick['position_name'] ?? ''))];
}

/** 部門現名（依 id）；id 不存在才回退快照裡的舊名。整份表只查一次 */
function ia_dept_name_now(PDO $db, int $id, string $fallback = ''): string
{
    static $map = null;
    if ($map === null) {
        $map = [];
        try {
            foreach ($db->query("SELECT id, name FROM department")->fetchAll(PDO::FETCH_ASSOC) as $r)
                $map[(int)$r['id']] = (string)$r['name'];
        } catch (Throwable $e) {}
    }
    return $map[$id] ?? $fallback;
}

/** 職稱現名（依 id）；id 不存在才回退快照裡的舊名 */
function ia_position_name_now(PDO $db, int $id, string $fallback = ''): string
{
    static $map = null;
    if ($map === null) {
        $map = [];
        try {
            foreach ($db->query("SELECT id, name FROM position")->fetchAll(PDO::FETCH_ASSOC) as $r)
                $map[(int)$r['id']] = (string)$r['name'];
        } catch (Throwable $e) {}
    }
    return $map[$id] ?? $fallback;
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

/**
 * 「這一格該由誰簽」——畫面上的審查／核准欄位與列印圖章用**同一支**解析（2026-09-16 使用者回報：
 * 年度計畫上顯示的審查人跟「設定→列印簽章→審查格」設的人不同）。
 * 原本送審時把 reviewer 寫成「按下送審的那個人」，列印卻依設定解析成管理代表，兩邊自然對不起來。
 *
 * $which: 'review'（審查格）／'approve'（核准格）
 * 設定是「（留白，紙本手蓋）」或解析不到人時，退回 $fallback（通常＝操作者），
 * 才不會出現一張「已核准但看不出是誰核准」的單據。
 */
function ia_sign_slot_person(PDO $db, string $which, array $ctx, array $fallback): array
{
    $set    = ia_settings($db);
    $source = (string)($set[$which === 'review' ? 'ia_sign_review' : 'ia_sign_approve'] ?? '');
    $p = $source !== '' ? ia_sign_person($db, $source, $ctx) : null;
    if ($p && (int)($p['id'] ?? 0) > 0 && (string)($p['name'] ?? '') !== '') {
        return ['id' => (int)$p['id'], 'name' => (string)$p['name'], 'from_setting' => true];
    }
    return ['id' => (int)($fallback['id'] ?? 0), 'name' => (string)($fallback['name'] ?? ''), 'from_setting' => false];
}

/** 自動簽核是否開啟（管理員設定；預設關閉＝維持人工按核准） */
/**
 * 自動簽核開關。**每一種表單各自一個開關**（2026-09-18 使用者回報：原本只有一個，
 * 而且畫面上寫的是「年度計畫表按下送審時…」，稽核通知單卻跟著被那個開關控制）。
 *   $what = 'plan'（年度計畫表，設定鍵 ia_auto_sign，保留原鍵名不動舊資料）
 *         | 'case'（稽核通知單，設定鍵 ia_auto_sign_case）
 */
function ia_auto_sign_on(PDO $db, string $what = 'plan'): bool
{
    $key = ($what === 'case') ? 'ia_auto_sign_case' : 'ia_auto_sign';
    return (string)(ia_settings($db)[$key] ?? '') === '1';
}

/**
 * 自動簽核的時間戳（ai-rules/21 鐵則3）：以「上一關卡完成的時間」為基準往後隨機 5～180 分鐘，
 * **加完跨過午夜就鎖回當天 23:59**（日期不可因為隨機偏移跨天）。
 * $baseAt 給 'Y-m-d H:i:s'；$bizDate 是該單據的業務日期（鎖回午夜時用它）。
 */
function ia_auto_sign_at(string $baseAt, string $bizDate): string
{
    $ts = strtotime($baseAt) ?: time();
    $at = $ts + random_int(5, 180) * 60;
    $endOfDay = strtotime(substr($baseAt, 0, 10) . ' 23:59:00');
    if ($at > $endOfDay) $at = $endOfDay;
    return date('Y-m-d H:i:s', $at);
}

/* ============================ 稽核通知單：完成／取消完成（2026-09-18 使用者要求） ============================
 * 使用者回報「狀態一直是草稿」——原因是 `case_status` 這支 API 從頭到尾**沒有任何呼叫端**，
 * 畫面上根本沒有按鈕可以把狀態推進去。現在補上明確的一步：
 *   ①填完按「完成」→ 鎖定不可再修改
 *   ②要改回去只有**內稽管理員**、而且要輸入**操作確認密碼**
 *   ③**完成之後才會送審核**（開了自動簽核就在完成的當下直接簽完）
 */

/** 這張通知單是不是已完成（完成之後一律不可修改） */
function ia_case_is_done(array $c): bool
{
    return in_array((string)($c['status'] ?? ''), ['issued', 'executing', 'closed'], true);
}

/**
 * 完成稽核通知單。
 * 業務日期（簽章要印的那個日期）沿用列印版的認定：製表日期，沒有才用通知日期（ai-rules/22）。
 * 自動簽核開啟時，核准／審查兩格在這一刻依「設定→列印簽章」解析並寫進單據——
 * 不開就留空，之後照紙本手蓋或由人工補。
 *
 * @return array ['status','auto_signed','approver','reviewer']
 */
/**
 * 稽核通知單「送出（完成）」前的必填檢查（2026-09-18 使用者要求）。
 *
 * 每一個受稽單位列都要填齊：**稽核起始主過程／受稽單位／稽核員／陪檢員／受稽日期／時間／預定完成改善**。
 * 草稿存檔刻意不檢查（現場本來就是邊查邊填），只有按「完成」＝這張單要發出去了才擋。
 * 前端 `caseRequiredCheck()` 用同一組規則即時把缺的格子標紅，這裡是後端同規則再擋一次（鐵律8）。
 *
 * @return string[] 缺漏說明，空陣列＝可以送出
 */
function ia_case_required_missing(PDO $db, int $caseId): array
{
    $rows = [];
    try {
        $st = $db->prepare("SELECT * FROM ia_case_dept WHERE case_id=? ORDER BY sort_order, cd_id");
        $st->execute([$caseId]);
        $rows = $st->fetchAll(PDO::FETCH_ASSOC);
    } catch (Throwable $e) { return []; }
    if (!$rows) return ['這張通知單還沒有任何受稽單位'];

    $pmap = ia_cd_people_map($db, array_map(function ($r) { return (int)$r['cd_id']; }, $rows), $rows);
    $out = [];
    foreach ($rows as $n => $r) {
        $miss = [];
        if (trim((string)($r['start_process'] ?? '')) === '')            $miss[] = '稽核起始主過程';
        if (!(int)($r['dept_id'] ?? 0) && trim((string)($r['dept_name'] ?? '')) === '') $miss[] = '受稽單位';
        if (!($pmap[(int)$r['cd_id']]['auditor'] ?? []))                  $miss[] = '稽核員';
        if (!($pmap[(int)$r['cd_id']]['escort'] ?? []))                   $miss[] = '陪檢員';
        if (empty($r['audited_date']))                                    $miss[] = '受稽日期';
        if (trim((string)($r['audited_time'] ?? '')) === '')               $miss[] = '時間';
        if (empty($r['improve_due']))                                      $miss[] = '預定完成改善';
        if ($miss) $out[] = '第 ' . ($n + 1) . ' 列（' . (trim((string)($r['dept_name'] ?? '')) ?: '未填單位') . '）：' . implode('、', $miss);
    }
    return $out;
}

function ia_case_complete(PDO $db, int $caseId, int $uid, string $uname, string $signDate = ''): array
{
    $st = $db->prepare("SELECT * FROM ia_case WHERE case_id=? AND COALESCE(is_deleted,0)=0");
    $st->execute([$caseId]);
    $c = $st->fetch(PDO::FETCH_ASSOC);
    if (!$c) throw new RuntimeException('找不到這張稽核通知單');
    if (ia_case_is_done($c)) throw new RuntimeException('這張通知單已經完成了，請重新整理畫面');

    // 完成的最低要求：至少有一個受稽單位（空單完成了也沒有意義，而且報告表會抓不到東西）
    $q = $db->prepare("SELECT COUNT(*) FROM ia_case_dept WHERE case_id=?");
    $q->execute([$caseId]);
    if ((int)$q->fetchColumn() === 0) throw new RuntimeException('這張通知單還沒有任何受稽單位，不能完成');

    /* 必填欄位要填齊才能送出（2026-09-18 使用者要求）：稽核起始主過程／受稽單位／稽核員／
       陪檢員／受稽日期／時間／預定完成改善。缺一格這張單發出去現場就不知道幾點要被稽核。 */
    $missing = ia_case_required_missing($db, $caseId);
    if ($missing) {
        throw new RuntimeException("受稽單位還有必填欄位沒填，不能完成：
" . implode("
", $missing));
    }

    /* 簽章日期（2026-09-18 使用者要求）：**一律是通知日期或更早**。
       通知單是「先簽好才發出去」的，簽章或製表日期比通知日期還晚，紙本上根本不成立
       （實際看到過通知日期 2025.11.03、章卻蓋 2025.12.08）。
       使用者可以在按「完成」時指定更早的日期（補歷史紙本用），但**不可以晚於通知日期**；
       沒指定就用通知日期。這個日期同時套用到自動簽章與**製表日期**。 */
    $notify = (string)($c['notify_date'] ?: ia_today($db));
    $bizDate = preg_match('/^\d{4}-\d{2}-\d{2}$/', $signDate) ? $signDate : $notify;
    if ($bizDate > $notify) {
        throw new RuntimeException('簽章／製表日期不可晚於通知日期（' . eg_fmt_date($notify) . '）');
    }
    $auto = ia_auto_sign_on($db, 'case');       // 通知單有自己的開關，不跟著年度計畫表走
    $ap = $rv = null;
    if ($auto) {
        $ctx = ['leader_id' => (int)($c['leader_id'] ?? 0), 'leader_name' => (string)($c['leader_name'] ?? ''),
                'maker_id' => (int)($c['maker_id'] ?? 0), 'maker_name' => (string)($c['maker_name'] ?? ''),
                'biz_date' => $bizDate];
        $ap = ia_sign_slot_person($db, 'approve', $ctx, ['id' => $uid, 'name' => $uname]);
        $rv = ia_sign_slot_person($db, 'review',  $ctx, ['id' => $uid, 'name' => $uname]);
    }

    $db->beginTransaction();
    try {
        // 「已發出」＝這張通知單完成、可以發給受稽單位了；executed 旗標維持原樣
        //（年度計畫表的 ◎ 是看 executing／closed，完成本身不代表已經去稽核了）
        /* 製表日期一併校正成這個日期——它跟簽章印在同一張紙上，兩個日期的規則必須一致。
           製表**人**不動（那是誰填的表，與完成無關）；從來沒設過製表人時順手記成按下完成的人。 */
        $db->prepare("UPDATE ia_case SET status='issued', completed_at=NOW(), completed_by=?, completed_by_name=?,
                          maker_date=?, maker_id=COALESCE(maker_id,?), maker_name=COALESCE(maker_name,?),
                          updated_at=NOW() WHERE case_id=?")
           ->execute([$uid ?: null, $uname, $bizDate, $uid ?: null, $uname, $caseId]);
        if ($auto) {
            // 自動簽核的時間戳依 ai-rules/21 錯開且不跨日；日期一律用單據的業務日期
            $base = $bizDate . ' 09:00:00';
            $atR  = ia_auto_sign_at($base, $bizDate);
            $atA  = ia_auto_sign_at($atR,  $bizDate);
            /* ia_case 存的是**業務日期**（reviewer_date／approver_date 是 DATE），
               精確時間戳存在 *_at 兩個新欄位（ai-rules/21：兩者分開存）。 */
            $db->prepare("UPDATE ia_case SET reviewer_id=?, reviewer_name=?, reviewer_date=?, reviewer_at=?,
                              approver_id=?, approver_name=?, approver_date=?, approver_at=? WHERE case_id=?")
               ->execute([($rv['id'] ?? 0) ?: null, $rv['name'] ?? null, $bizDate, $atR,
                          ($ap['id'] ?? 0) ?: null, $ap['name'] ?? null, $bizDate, $atA, $caseId]);
        }
        $db->commit();
    } catch (Throwable $e) { $db->rollBack(); throw new RuntimeException('完成失敗：' . $e->getMessage()); }

    return ['status' => 'issued', 'auto_signed' => $auto ? 1 : 0,
            'approver' => $auto ? (string)($ap['name'] ?? '') : '',
            'reviewer' => $auto ? (string)($rv['name'] ?? '') : ''];
}

/**
 * 已經完成、但核准／審查還空著的通知單，依目前設定補上簽章（2026-09-18 使用者要求）：
 * 「完成的時候還沒開自動簽核，事後才開」的那些單不必一張張重開重按。
 *
 * 只補「**兩格都空**」的單——已經有人簽過的一律不動（不可以覆蓋真人簽的章）。
 * 日期一律用該單自己的業務日期（製表日期，沒有才用通知日期），時間戳依 ai-rules/21 錯開且不跨日。
 *
 * @return array ['filled'=>補了幾張, 'cases'=>[單號…]]
 */
function ia_case_autosign_backfill(PDO $db, int $uid, string $uname): array
{
    if (!ia_auto_sign_on($db, 'case')) return ['filled' => 0, 'cases' => []];
    $rows = [];
    try {
        $rows = $db->query("SELECT * FROM ia_case
                             WHERE COALESCE(is_deleted,0)=0
                               AND status IN ('issued','executing','closed')
                               AND (approver_id IS NULL OR approver_id=0)
                               AND (reviewer_id IS NULL OR reviewer_id=0)
                             ORDER BY case_id")->fetchAll(PDO::FETCH_ASSOC);
    } catch (Throwable $e) { return ['filled' => 0, 'cases' => []]; }
    if (!$rows) return ['filled' => 0, 'cases' => []];

    $done = [];
    $upd = $db->prepare("UPDATE ia_case SET reviewer_id=?, reviewer_name=?, reviewer_date=?, reviewer_at=?,
                             approver_id=?, approver_name=?, approver_date=?, approver_at=?, updated_at=NOW()
                          WHERE case_id=?");
    foreach ($rows as $c) {
        // 補章也守同一條規則：不可晚於通知日期（ia_case_complete 同規則）
        $notify = (string)($c['notify_date'] ?: ia_today($db));
        $biz = (string)($c['maker_date'] ?: $notify);
        if ($biz > $notify) $biz = $notify;
        $ctx = ['leader_id' => (int)($c['leader_id'] ?? 0), 'leader_name' => (string)($c['leader_name'] ?? ''),
                'maker_id' => (int)($c['maker_id'] ?? 0), 'maker_name' => (string)($c['maker_name'] ?? ''),
                'biz_date' => $biz];
        $ap = ia_sign_slot_person($db, 'approve', $ctx, ['id' => $uid, 'name' => $uname]);
        $rv = ia_sign_slot_person($db, 'review',  $ctx, ['id' => $uid, 'name' => $uname]);
        // 兩格都解析不到人就跳過這一張（設定留白＝紙本手蓋，不要硬塞操作者）
        if (empty($ap['id']) && empty($rv['id'])) continue;
        $atR = ia_auto_sign_at($biz . ' 09:00:00', $biz);
        $atA = ia_auto_sign_at($atR, $biz);
        $upd->execute([($rv['id'] ?? 0) ?: null, $rv['name'] ?? null, $biz, $atR,
                       ($ap['id'] ?? 0) ?: null, $ap['name'] ?? null, $biz, $atA, (int)$c['case_id']]);
        $done[] = (string)($c['case_no'] ?: ('#' . $c['case_id']));
    }
    return ['filled' => count($done), 'cases' => $done];
}

/**
 * 取消完成（改回草稿）。呼叫端必須先驗過管理員身分與操作確認密碼。
 * **自動簽核寫進去的核准／審查一併清掉**——單據要回去修改，那兩個章就不成立了；
 * 留著的話會變成「內容改過、章卻還是舊的」，比沒有章更危險。
 */
function ia_case_reopen(PDO $db, int $caseId, int $uid, string $uname): array
{
    $st = $db->prepare("SELECT * FROM ia_case WHERE case_id=? AND COALESCE(is_deleted,0)=0");
    $st->execute([$caseId]);
    $c = $st->fetch(PDO::FETCH_ASSOC);
    if (!$c) throw new RuntimeException('找不到這張稽核通知單');
    if (!ia_case_is_done($c)) throw new RuntimeException('這張通知單目前不是完成狀態');
    if ((string)$c['status'] === 'closed') throw new RuntimeException('已結案的通知單不可取消完成，請先把狀態改回執行中');

    $db->prepare("UPDATE ia_case SET status='draft', completed_at=NULL, completed_by=NULL, completed_by_name=NULL,
                      reviewer_id=NULL, reviewer_name=NULL, reviewer_date=NULL, reviewer_at=NULL,
                      approver_id=NULL, approver_name=NULL, approver_date=NULL, approver_at=NULL,
                      updated_at=NOW() WHERE case_id=?")->execute([$caseId]);
    return ['status' => 'draft'];
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
    // 稽核員身分：有稽核員角色／本單開立者／**這張案件裡被指派的稽核員**（可多位，2026-08-27）
    $isAuditor = $perms['canAudit'] || (int)($nc['auditor_id'] ?? 0) === $uid
                 || (int)($nc['leader_id'] ?? 0) === $uid;
    if (!$isAuditor && $uid > 0 && (int)($nc['case_id'] ?? 0)) {
        $isAuditor = in_array($uid, ia_case_person_ids($db, (int)$nc['case_id'], 'auditor'), true);
    }
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
    // 稽核員可多位，報告表要「一人一列、部門與姓名同一列」，所以連人員清單一起帶出來
    $caseDept = [];
    try {
        $st = $db->prepare("SELECT cd.* FROM ia_case_dept cd JOIN ia_case c ON c.case_id=cd.case_id
                            WHERE c.year=? AND COALESCE(c.is_deleted,0)=0
                            ORDER BY cd.audited_date, cd.cd_id");
        $st->execute([$year]);
        $cdRows = $st->fetchAll(PDO::FETCH_ASSOC);
        $pmap = ia_cd_people_map($db, array_map(function ($r) { return (int)$r['cd_id']; }, $cdRows), $cdRows);
        foreach ($cdRows as $r) {
            $k = (string)($r['dept_name'] ?? '');
            if ($k === '') continue;
            if (!isset($caseDept[$k])) {
                $r['auditors'] = $pmap[(int)$r['cd_id']]['auditor'] ?? [];
                $caseDept[$k] = $r;
            }
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
                'auditors'     => $cd['auditors'] ?? [],
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
            // 只有 IA 單查得到稽核員時，至少讓報告表印得出姓名（部門就留白）
            if (!$byDept[$d]['auditors']) {
                $byDept[$d]['auditors'] = [['user_id' => (int)($r['auditor_id'] ?? 0),
                                            'user_name' => (string)$r['auditor_name'], 'dept_name' => '']];
            }
        }
        $records[] = [
            'dept_name' => $d,
            'nc_no'     => (string)($r['nc_no'] ?? ''),
            'form_no'   => (string)($r['ref_form_no'] ?? ''),
            // 缺點記錄要印**表單的中文名稱**（2026-09-18 使用者要求），編號本身看不出是哪一張表單。
            // 一律即時由 as_document 用編號回查（不存一份在 IA 單上，改名了才不會對不起來＝鐵律4）。
            'form_name' => ia_nc_form_name($db, $r),
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
            'auditor_name'=>(string)($cd['auditor_name'] ?? ''), 'auditors'=>$cd['auditors'] ?? [],
            'improve_due'=>(string)($cd['improve_due'] ?? ''),
            'auto_due'=>'', 'closed'=>0, 'total'=>0,
        ];
    }

    return ['rows'=>array_values($byDept), 'records'=>$records];
}

/**
 * 這張 IA 單指的那份表單叫什麼（缺點記錄要印中文名稱）。
 * **兩條路，先編號再來源**：
 *   ①先用 `ref_form_no` 的編號回查 `as_document`
 *   ②查不到就走這張 IA 單的來源查檢表項目（`src_item_id` → `ia_check_item.ref_id`＝as_document.id）
 * 第二條路是為了**AS 文件日後改編號／改所屬單位**——編號一改，只靠編號回查就會查不到名稱，
 * 但查檢表項目上存的是文件 id，改號也還指得到同一份文件（2026-09-18 使用者問到這件事）。
 */
function ia_nc_form_name(PDO $db, array $nc): string
{
    // ①建立當下存的快照最可靠（日後改編號／改名／廢止都不影響這張已開的單）
    $snap = trim((string)($nc['ref_form_name'] ?? ''));
    if ($snap !== '') return $snap;
    $byNo = ia_asdoc_name_by_no($db, (string)($nc['ref_form_no'] ?? ''));
    if ($byNo !== '') return $byNo;
    $iid = (int)($nc['src_item_id'] ?? 0);
    if (!$iid) return '';
    try {
        $st = $db->prepare("SELECT d.doc_name FROM ia_check_item i
                              JOIN as_document d ON d.id = i.ref_id
                             WHERE i.item_id=? AND i.ref_kind='as_document'");
        $st->execute([$iid]);
        return (string)($st->fetchColumn() ?: '');
    } catch (Throwable $e) { return ''; }
}

/**
 * AS 文件編號 → 中文名稱（缺點記錄要印表單名稱，光看編號看不出是哪一張表）。
 * 一次把 as_document 讀進靜態快取；編號查不到就回空字串，呼叫端自行決定要不要留白。
 * 編號可能帶版次或前後空白，比對前先 trim，並允許「編號 名稱」這種已經合併過的舊資料。
 */
function ia_asdoc_name_by_no(PDO $db, string $docNo): string
{
    static $map = null;
    if ($map === null) {
        $map = [];
        try {
            foreach ($db->query("SELECT doc_no, doc_name FROM as_document")->fetchAll(PDO::FETCH_ASSOC) as $r) {
                $map[trim((string)$r['doc_no'])] = (string)$r['doc_name'];
            }
        } catch (Throwable $e) {}
    }
    $no = trim($docNo);
    if ($no === '') return '';
    if (isset($map[$no])) return $map[$no];
    // 舊資料可能寫成「2-SM-02-01 客戶訂單審查表」或「2-SM-02-01B」，取得出編號那一段再比一次
    if (preg_match('/^\s*([0-9]-[A-Za-z]{2}-[0-9]{2}(?:-[0-9]{2})?)/', $no, $m) && isset($map[$m[1]])) return $map[$m[1]];
    return '';
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
 * 資格認到「人員＋部門＋職稱」＝一個職務一筆（使用者要求 2026-08-26）。
 * 兼任的人可能主職沒有稽核員資格、兼任職才有（或反過來），所以不能只認到「人」。
 * 職務鍵格式一律 'uid:deptId:positionId'，前後端共用同一個字串。
 */
function ia_post_key(int $uid, ?int $deptId, ?int $posId): string
{
    return $uid . ':' . (int)$deptId . ':' . (int)$posId;
}

/** 'uid:deptId:posId' → [uid, deptId, posId]；格式不對回 [0,0,0] */
function ia_post_parse(string $key): array
{
    $p = explode(':', trim($key));
    if (count($p) !== 3) return [0, 0, 0];
    return [(int)$p[0], (int)$p[1], (int)$p[2]];
}

/**
 * 資格名單本身認到的是「部門＋職稱」＝一個職稱一筆（使用者要求 2026-09-09）。
 * 人員會異動、離職、調部門，但「這個職稱可以當稽核員」不會跟著變；
 * 所以名單只記職務，**人名一律在建稽核通知單的當下即時抓**該部門該職稱目前的在職人員。
 * 職務鍵格式 'deptId:positionId'，前後端共用同一個字串（跟人員層級的 post_key3 是兩種東西）。
 */
function ia_job_key(?int $deptId, ?int $posId): string
{
    return (int)$deptId . ':' . (int)$posId;
}

/** 'deptId:posId' → [deptId, posId]；格式不對回 [0,0] */
function ia_job_parse(string $key): array
{
    $p = explode(':', trim($key));
    if (count($p) !== 2) return [0, 0];
    return [(int)$p[0], (int)$p[1]];
}

/**
 * 資格規則（原始列）。回 kind => [ ['rule_kind','user_id','dept_id','position_id','start_date','end_date','note'], ... ]
 * rule_kind: job=部門＋職稱（該職務上的人都有資格）／user=部門＋職稱＋指定人員（只有這個人有資格）
 */
function ia_qualify_rules(PDO $db): array
{
    $out = array_fill_keys(array_keys(IA_QUALIFY_KINDS), []);
    try {
        foreach ($db->query("SELECT kind, rule_kind, user_id, dept_id, position_id, start_date, end_date, note
                             FROM ia_qualified_person ORDER BY sort_order, qp_id")->fetchAll(PDO::FETCH_ASSOC) as $r) {
            if (!isset($out[$r['kind']])) continue;
            $out[$r['kind']][] = [
                'rule_kind'   => ((string)($r['rule_kind'] ?? 'job') === 'user' && (int)$r['user_id'] > 0) ? 'user' : 'job',
                'user_id'     => (int)$r['user_id'],
                'dept_id'     => (int)$r['dept_id'],
                'position_id' => (int)$r['position_id'],
                'start_date'  => $r['start_date'],
                'end_date'    => $r['end_date'],
                'note'        => (string)($r['note'] ?? ''),
            ];
        }
    } catch (Throwable $e) {}
    return $out;
}

/** 任期是否涵蓋某個日期（空起日＝最早、空迄日＝至今；與 as_doc_editor_term 同一套語意） */
function ia_term_covers(?string $start, ?string $end, string $date): bool
{
    if ($date === '') return true;                       // 沒有業務日期就不用任期過濾
    if ($start && $date < $start) return false;
    if ($end   && $date > $end)   return false;
    return true;
}

/**
 * 「AS 文件負責人自動具備稽核員資格」（使用者要求 2026-09-16）。
 * 期間比照 as_document_management.php→系統設定→結構總覽列印 裡設的**任期**，
 * 所以文管中心負責人換人時這裡自動跟著換，不必回來改名單。
 * 回傳 [user_id => 說明文字]；$date 空＝用今天。
 */
function ia_as_owner_auto_users(PDO $db, string $date): array
{
    require_once __DIR__ . '/asdoc_editor_lib.php';
    $d = ($date !== '' && preg_match('/^\d{4}-\d{2}-\d{2}$/', $date)) ? $date : ia_today($db);
    $e = eg_asdoc_editor_at($db, $d);
    if (!$e || !(int)$e['id']) return [];
    return [(int)$e['id'] => 'AS 文件負責人（任期自動帶入）'];
}

/**
 * 某身分「可以挑的人」＝資格名單涵蓋到的職務上、**該業務日期當時在職**的人
 * （一個職務一列，跨部門兼任的人會出現多列）。
 *
 * $asofDate（Y-m-d，空＝現況）是 2026-09-16 使用者交辦的重點：
 *   補歷史單據時要挑得到「當時在職、現在已離職」的人，職稱也要是**當時**的職稱
 *   （例：文管中心負責人葉卿雅在 2025-11-03／2025-12-04 都還在職，補那兩張單時必須挑得到）。
 *   走共用的 eg_people_posts_asof()，不在這裡自己寫一套（鐵律4、ai-rules/22）。
 *
 * 三種資格來源（任一命中即可）：
 *   ①job 規則：該「部門＋職稱」上的人都有資格
 *   ②user 規則：只有指定的那個人、在該職務上、且業務日期落在任期內才有資格
 *     （代理人臨時具備資格用——同部門同職稱的其他人不會跟著有資格）
 *   ③AS 文件負責人：自動具備**稽核員**資格，期間＝AS 任期
 *
 * **明確規則一條都沒設定時一律回全部**——否則模組剛上線一個人都挑不到，使用者會以為壞掉。
 * 每列在 eg_people_posts() 的欄位之外多帶 post_key3 與 qualify_note。
 */
function ia_qualified_posts(PDO $db, string $kind, string $asofDate = ''): array
{
    $all = [];
    try {
        $all = ($asofDate !== '') ? eg_people_posts_asof($db, [], $asofDate) : eg_people_posts($db, []);
    } catch (Throwable $e) { $all = []; }
    foreach ($all as &$p) {
        $p['post_key3']    = ia_post_key((int)$p['id'], $p['dept_id'], $p['position_id']);
        $p['qualify_note'] = '';
    }
    unset($p);
    if (!isset(IA_QUALIFY_KINDS[$kind])) return $all;

    $rules = ia_qualify_rules($db)[$kind] ?? [];
    if (!$rules) return $all;                     // 沒設定＝不限制

    $date = ($asofDate !== '' && preg_match('/^\d{4}-\d{2}-\d{2}$/', $asofDate)) ? $asofDate : '';
    $jobKeys = [];      // 'deptId:posId' => 1
    $userKeys = [];     // 'uid:deptId:posId' => note
    foreach ($rules as $r) {
        if (!ia_term_covers($r['start_date'], $r['end_date'], $date)) continue;
        if ($r['rule_kind'] === 'user') {
            $userKeys[ia_post_key($r['user_id'], $r['dept_id'], $r['position_id'])] =
                ($r['note'] !== '' ? $r['note'] : '指定人員');
        } else {
            $jobKeys[ia_job_key($r['dept_id'], $r['position_id'])] = 1;
        }
    }
    // AS 文件負責人自動具備稽核員資格（該人的每一個職務都算）
    $autoUsers = ($kind === 'auditor') ? ia_as_owner_auto_users($db, $date) : [];

    $out = [];
    foreach ($all as $p) {
        $note = '';
        if (isset($jobKeys[ia_job_key($p['dept_id'], $p['position_id'])])) {
            $note = '';
        } elseif (isset($userKeys[$p['post_key3']])) {
            $note = $userKeys[$p['post_key3']];
        } elseif (isset($autoUsers[(int)$p['id']])) {
            $note = $autoUsers[(int)$p['id']];
        } else {
            continue;
        }
        $p['qualify_note'] = $note;
        $out[] = $p;
    }
    return $out;
}

/**
 * 相容用：某身分的合格「人員」清單（去重）。
 * 有些地方只需要知道「這個人有沒有資格」（例如判斷既有單據上的人還算不算數）。
 */
function ia_qualified_people(PDO $db, string $kind, string $asofDate = ''): array
{
    $seen = []; $out = [];
    foreach (ia_qualified_posts($db, $kind, $asofDate) as $p) {
        $id = (int)$p['id'];
        if (isset($seen[$id])) continue;
        $seen[$id] = 1; $out[] = $p;
    }
    return $out;
}

/** 目前設定的名單（管理畫面用），回 kind => ['deptId:posId', ...]（只含 job 規則） */
function ia_qualify_map(PDO $db): array
{
    $out = array_fill_keys(array_keys(IA_QUALIFY_KINDS), []);
    $seen = [];
    foreach (ia_qualify_rules($db) as $kind => $rules) {
        foreach ($rules as $r) {
            if ($r['rule_kind'] !== 'job') continue;
            $k = ia_job_key($r['dept_id'], $r['position_id']);
            if (isset($seen[$kind . '|' . $k])) continue;
            $seen[$kind . '|' . $k] = 1;
            $out[$kind][] = $k;
        }
    }
    return $out;
}

/** 目前設定的「指定人員」列（管理畫面用），回 kind => [ {post_key3, user_id, user_name, dept/position 名稱, start_date, end_date, note}, ... ] */
function ia_qualify_users(PDO $db): array
{
    $out = array_fill_keys(array_keys(IA_QUALIFY_KINDS), []);
    $rules = ia_qualify_rules($db);
    $uids = [];
    foreach ($rules as $rs) foreach ($rs as $r) if ($r['rule_kind'] === 'user') $uids[$r['user_id']] = 1;
    if (!$uids) return $out;

    $names = $deptN = $posN = [];
    try {
        $in = implode(',', array_map('intval', array_keys($uids)));
        foreach ($db->query("SELECT id, user_cname, state FROM `user` WHERE id IN ($in)")->fetchAll(PDO::FETCH_ASSOC) as $u)
            $names[(int)$u['id']] = ['name' => (string)$u['user_cname'], 'resigned' => ((int)$u['state'] === 0)];
        foreach ($db->query("SELECT id, name FROM department")->fetchAll(PDO::FETCH_ASSOC) as $d) $deptN[(int)$d['id']] = (string)$d['name'];
        foreach ($db->query("SELECT id, name FROM position")->fetchAll(PDO::FETCH_ASSOC)   as $p) $posN[(int)$p['id']]  = (string)$p['name'];
    } catch (Throwable $e) {}

    foreach ($rules as $kind => $rs) {
        foreach ($rs as $r) {
            if ($r['rule_kind'] !== 'user') continue;
            $out[$kind][] = [
                'post_key3'     => ia_post_key($r['user_id'], $r['dept_id'], $r['position_id']),
                'user_id'       => $r['user_id'],
                'user_name'     => $names[$r['user_id']]['name'] ?? ('#' . $r['user_id']),
                'resigned'      => !empty($names[$r['user_id']]['resigned']),
                'dept_id'       => $r['dept_id'],
                'dept_name'     => $deptN[$r['dept_id']] ?? '',
                'position_id'   => $r['position_id'],
                'position_name' => $posN[$r['position_id']] ?? '',
                'start_date'    => $r['start_date'],
                'end_date'      => $r['end_date'],
                'note'          => $r['note'],
            ];
        }
    }
    return $out;
}

/**
 * 資格名單可以挑的「部門＋職稱」清單（管理畫面用）。
 * 來源＝目前**真的有人在任**的職務組合；另外把「已經在名單上、但現在剛好沒有人」的組合也補進來
 * （職缺是暫時的，不補進來使用者會看不到自己設過什麼、也沒得取消）。
 * 每列：job_key/dept_id/dept_name/position_id/position_name/people[]/people_count。
 */
function ia_job_options(PDO $db, array $alsoKeys = []): array
{
    $rows = [];
    $posts = [];
    try { $posts = eg_people_posts($db, []); } catch (Throwable $e) { $posts = []; }
    foreach ($posts as $p) {
        $k = ia_job_key($p['dept_id'], $p['position_id']);
        if (!isset($rows[$k])) {
            $rows[$k] = [
                'job_key'       => $k,
                'dept_id'       => (int)$p['dept_id'],   'dept_name'     => (string)$p['dept_name'],
                'position_id'   => (int)$p['position_id'],'position_name' => (string)$p['position_name'],
                'dept_sort'     => (int)($p['dept_sort'] ?? 999),
                'position_sort' => (int)($p['position_sort'] ?? 999),
                'people'        => [],
            ];
        }
        $rows[$k]['people'][] = (string)$p['user_cname']
            . ((int)$p['is_main'] === 0 ? '（兼任）' : '')
            . (!empty($p['leave_note']) ? '［' . $p['leave_note'] . '］' : '');
    }

    // 名單上但目前沒人在任的職務：查得到部門／職稱名稱就補一列出來（人數 0）
    $need = [];
    foreach ($alsoKeys as $k) {
        $k = trim((string)$k);
        if ($k === '' || isset($rows[$k])) continue;
        list($d, $ps) = ia_job_parse($k);
        if ($d && $ps) $need[$k] = [$d, $ps];
    }
    if ($need) {
        $dn = $pn = $dsort = $psort = [];
        try {
            foreach ($db->query("SELECT id, name, COALESCE(sort_order,999) s FROM department")->fetchAll(PDO::FETCH_ASSOC) as $r) {
                $dn[(int)$r['id']] = (string)$r['name']; $dsort[(int)$r['id']] = (int)$r['s'];
            }
            foreach ($db->query("SELECT id, name, COALESCE(sort_order,999) s FROM position")->fetchAll(PDO::FETCH_ASSOC) as $r) {
                $pn[(int)$r['id']] = (string)$r['name']; $psort[(int)$r['id']] = (int)$r['s'];
            }
        } catch (Throwable $e) {}
        foreach ($need as $k => $dp) {
            list($d, $ps) = $dp;
            if (!isset($dn[$d]) || !isset($pn[$ps])) continue;   // 部門或職稱已被刪掉＝這個組合不存在了
            $rows[$k] = ['job_key' => $k, 'dept_id' => $d, 'dept_name' => $dn[$d],
                         'position_id' => $ps, 'position_name' => $pn[$ps],
                         'dept_sort' => $dsort[$d] ?? 999, 'position_sort' => $psort[$ps] ?? 999, 'people' => []];
        }
    }

    $out = array_values($rows);
    // 欄位順序固定「部門/職稱」，排序依部門與職稱的 sort_order（ai-rules/08 第五節鐵則6）
    usort($out, function ($a, $b) {
        return [$a['dept_sort'], $a['dept_id'], $a['position_sort'], $a['position_id']]
           <=> [$b['dept_sort'], $b['dept_id'], $b['position_sort'], $b['position_id']];
    });
    foreach ($out as &$r) $r['people_count'] = count($r['people']);
    unset($r);
    return $out;
}

/**
 * 整批覆寫某身分的名單。傳入的是職務鍵 'deptId:posId'（部門＋職稱，不含人）。
 * 空陣列＝不限制（全體在職員工的所有職務都可指派）。
 * 只存「部門與職稱都真的存在」的組合——直接打 API 塞一個不存在的組合一樣進不了資料庫（鐵律8）；
 * **不存在的組合略過就好、不整批擋下**：名單是舊資料，部門或職稱遲早會被改，
 * 擋下來的話使用者連一個字都改不了（2026-09-09 使用者回報「一直顯示儲存失敗」的教訓）。
 * 目前沒有人在任的職務**照存**——職缺是暫時的，新人接任就自動有資格。
 * 回傳被略過的職務鍵，讓呼叫端可以回報「清掉了幾筆」。
 */
function ia_qualify_save(PDO $db, string $kind, array $jobKeys, string $byName, ?array $userRules = null): array
{
    if (!isset(IA_QUALIFY_KINDS[$kind])) throw new RuntimeException('身分別不正確');

    $depts = $poss = [];
    try {
        foreach ($db->query("SELECT id FROM department")->fetchAll(PDO::FETCH_COLUMN) as $id) $depts[(int)$id] = 1;
        foreach ($db->query("SELECT id FROM position")->fetchAll(PDO::FETCH_COLUMN)   as $id) $poss[(int)$id]  = 1;
    } catch (Throwable $e) {}
    // 部門或職稱一筆都查不到＝資料讀取失敗，這時候「全部略過」會把整份名單清光，寧可擋下來
    if (!$depts || !$poss) throw new RuntimeException('目前查不到部門或職稱資料，為避免誤刪名單已停止儲存');

    $keys = []; $dropped = [];
    foreach ($jobKeys as $k) {
        $k = trim((string)$k);
        if ($k === '' || isset($keys[$k])) continue;
        list($d, $p) = ia_job_parse($k);
        if (!$d || !$p || !isset($depts[$d]) || !isset($poss[$p])) { $dropped[$k] = 1; continue; }
        $keys[$k] = 1;
    }

    /* 指定人員規則（職位＋這個人；可帶任期）。$userRules 傳 null＝呼叫端這次沒有送這一段，
       維持原本的資料不動（與製表人 iaMakerFromPost() 同一套「有沒有送」的判別法：
       送空陣列＝真的要清光，沒送＝舊呼叫端不要動它）。 */
    $users = [];
    if (is_array($userRules)) {
        $seen = [];
        foreach ($userRules as $r) {
            if (!is_array($r)) continue;
            $uid = (int)($r['user_id'] ?? 0);
            $d   = (int)($r['dept_id'] ?? 0);
            $p   = (int)($r['position_id'] ?? 0);
            // 前端也是送職務鍵，兩種格式都收
            if (!$uid && isset($r['post_key3'])) list($uid, $d, $p) = ia_post_parse((string)$r['post_key3']);
            if (!$uid || !$d || !$p || !isset($depts[$d]) || !isset($poss[$p])) { $dropped['user:' . $uid] = 1; continue; }
            $s = trim((string)($r['start_date'] ?? '')); $e = trim((string)($r['end_date'] ?? ''));
            if ($s !== '' && !preg_match('/^\d{4}-\d{2}-\d{2}$/', $s)) throw new RuntimeException('指定人員的任期起日格式不正確');
            if ($e !== '' && !preg_match('/^\d{4}-\d{2}-\d{2}$/', $e)) throw new RuntimeException('指定人員的任期迄日格式不正確');
            if ($s !== '' && $e !== '' && $s > $e) throw new RuntimeException('指定人員的任期起日不可晚於迄日');
            $sig = $uid . ':' . $d . ':' . $p . ':' . $s . ':' . $e;
            if (isset($seen[$sig])) continue;
            $seen[$sig] = 1;
            $users[] = ['user_id' => $uid, 'dept_id' => $d, 'position_id' => $p,
                        'start' => ($s ?: null), 'end' => ($e ?: null),
                        'note' => mb_substr(trim((string)($r['note'] ?? '')), 0, 100)];
        }
    } else {
        // 沒送＝沿用既有的指定人員列
        foreach ((ia_qualify_rules($db)[$kind] ?? []) as $r) {
            if ($r['rule_kind'] !== 'user') continue;
            $users[] = ['user_id' => $r['user_id'], 'dept_id' => $r['dept_id'], 'position_id' => $r['position_id'],
                        'start' => $r['start_date'], 'end' => $r['end_date'], 'note' => $r['note']];
        }
    }

    $db->prepare("DELETE FROM ia_qualified_person WHERE kind=?")->execute([$kind]);
    $ins = $db->prepare("INSERT INTO ia_qualified_person
                            (kind, rule_kind, user_id, dept_id, position_id, start_date, end_date, note,
                             sort_order, updated_at, updated_by)
                         VALUES (?,?,?,?,?,?,?,?,?,NOW(),?)");
    $i = 0;
    foreach (array_keys($keys) as $k) {
        list($dept, $pos) = ia_job_parse($k);
        $ins->execute([$kind, 'job', 0, $dept, $pos, null, null, null, ++$i * 10, $byName]);
    }
    foreach ($users as $u) {
        $ins->execute([$kind, 'user', $u['user_id'], $u['dept_id'], $u['position_id'],
                       $u['start'], $u['end'], ($u['note'] !== '' ? $u['note'] : null), ++$i * 10, $byName]);
    }
    return array_keys($dropped);
}

/**
 * 某個職務鍵解析成可存檔的欄位（存單據時用）。
 * 回 ['user_id','user_name','dept_id','dept_name','position_id','position_name'] 或 null。
 * $kind 有給就順便驗資格——前端擋一次、後端同規則再擋一次（鐵律8）。
 */
function ia_resolve_post(PDO $db, string $key, ?string $kind = null, string $asofDate = ''): ?array
{
    if (trim($key) === '') return null;
    $posts = ($kind !== null && isset(IA_QUALIFY_KINDS[$kind]))
           ? ia_qualified_posts($db, $kind, $asofDate)
           : (function () use ($db, $asofDate) {
                 $all = [];
                 try {
                     $all = ($asofDate !== '') ? eg_people_posts_asof($db, [], $asofDate) : eg_people_posts($db, []);
                 } catch (Throwable $e) {}
                 foreach ($all as &$p) $p['post_key3'] = ia_post_key((int)$p['id'], $p['dept_id'], $p['position_id']);
                 unset($p);
                 return $all;
             })();
    foreach ($posts as $p) {
        if ($p['post_key3'] !== trim($key)) continue;
        return ['user_id' => (int)$p['id'], 'user_name' => (string)$p['user_cname'],
                'dept_id' => $p['dept_id'], 'dept_name' => (string)$p['dept_name'],
                'position_id' => $p['position_id'], 'position_name' => (string)$p['position_name']];
    }
    return null;
}

/**
 * 確保某年度有稽核報告表（2026-09-18 使用者要求：開不符合通知單時順手把報告表建起來）。
 * 報告表的內容本來就是由該年度的 IA 單即時彙總的，所以這裡只要有一列殼就夠；
 * 已經有了就什麼都不做（不可以覆蓋使用者填的補充文字或送出紀錄）。
 * @return int report_id
 */
function ia_report_ensure(PDO $db, int $year, int $uid, string $uname): int
{
    if ($year < 2000 || $year > 2200) return 0;
    try {
        $st = $db->prepare("SELECT report_id FROM ia_report WHERE year=? AND COALESCE(is_deleted,0)=0");
        $st->execute([$year]);
        $rid = (int)($st->fetchColumn() ?: 0);
        if ($rid) return $rid;
        $db->prepare("INSERT INTO ia_report (year, status, created_by, created_at, updated_at)
                      VALUES (?, 'draft', ?, NOW(), NOW())")->execute([$year, $uid ?: null]);
        return (int)$db->lastInsertId();
    } catch (Throwable $e) { return 0; }
}

/* ============================ 稽核報告表：送出與通知（2026-09-17 使用者要求） ============================
 * 使用者拍板：稽核報告表**不要核准、也不要製表人**，改成一顆「送出」；
 * 送出後自動通知「管理員設定好的那些部門的那些職位」的人。
 */

/** 通知對象設定：[['dept_id'=>int,'position_id'=>int,'with_sub'=>0|1], ...] */
function ia_report_notify_rules(PDO $db): array
{
    $raw = (string)(ia_settings($db)['ia_report_notify'] ?? '');
    $arr = $raw === '' ? [] : json_decode($raw, true);
    if (!is_array($arr)) return [];
    $out = [];
    foreach ($arr as $r) {
        if (!is_array($r)) continue;
        $d = (int)($r['dept_id'] ?? 0); $p = (int)($r['position_id'] ?? 0);
        if (!$d && !$p) continue;                      // 兩個都空＝等於全公司，不允許（會變成全站廣播）
        $out[] = ['dept_id' => $d, 'position_id' => $p, 'with_sub' => !empty($r['with_sub']) ? 1 : 0];
    }
    return $out;
}

/** 存通知對象設定（唯一寫入點；兩個都空的列一律丟掉，避免誤設成全公司廣播） */
function ia_report_notify_save(PDO $db, array $rules, string $byName): int
{
    $clean = [];
    foreach ($rules as $r) {
        $d = (int)($r['dept_id'] ?? 0); $p = (int)($r['position_id'] ?? 0);
        if (!$d && !$p) continue;
        $key = $d . ':' . $p;
        $clean[$key] = ['dept_id' => $d, 'position_id' => $p, 'with_sub' => !empty($r['with_sub']) ? 1 : 0];
    }
    $clean = array_values($clean);
    ia_setting_save($db, 'ia_report_notify', json_encode($clean, JSON_UNESCAPED_UNICODE), $byName);
    return count($clean);
}

/**
 * 依設定解析出「這次要通知誰」。
 * 規則：每一條是「部門 × 職位」——
 *   ・部門與職位都有＝該部門裡掛這個職位的人
 *   ・只有部門＝該部門全部的人
 *   ・只有職位＝全公司掛這個職位的人
 *   ・with_sub=1 時部門連同**子部門**一起算（組織是樹狀的，只比單一 id 會漏掉底下的組）
 * 一律只通知**目前在職**的人（通知是「現在要請他看」，不是歷史簽章，所以不回推職務）。
 * @return array [user_id => ['name','dept_name','position_name']]
 */
function ia_report_notify_users(PDO $db, array $rules = null): array
{
    $rules = $rules === null ? ia_report_notify_rules($db) : $rules;
    if (!$rules) return [];
    // 子部門展開（含自己）
    $children = [];
    try {
        foreach ($db->query("SELECT id, parent_id FROM department")->fetchAll(PDO::FETCH_ASSOC) as $d) {
            $children[(int)$d['parent_id']][] = (int)$d['id'];
        }
    } catch (Throwable $e) {}
    $expand = function (int $id) use (&$expand, $children): array {
        $out = [$id];
        foreach ($children[$id] ?? [] as $c) $out = array_merge($out, $expand($c));
        return $out;
    };

    $out = [];
    foreach ($rules as $r) {
        $depts = $r['dept_id'] ? ($r['with_sub'] ? $expand((int)$r['dept_id']) : [(int)$r['dept_id']]) : [];
        $sql = "SELECT u.id, u.user_cname, d.name AS dept_name, p.name AS position_name
                  FROM user_department_position_map m
                  JOIN `user` u ON u.id = m.user_id
                  LEFT JOIN department d ON d.id = m.department_id
                  LEFT JOIN position p ON p.id = m.position_id
                 WHERE u.state = 1";
        $args = [];
        if ($depts) {
            $sql .= " AND m.department_id IN (" . implode(',', array_fill(0, count($depts), '?')) . ")";
            $args = array_merge($args, $depts);
        }
        if ($r['position_id']) { $sql .= " AND m.position_id = ?"; $args[] = (int)$r['position_id']; }
        try {
            $st = $db->prepare($sql); $st->execute($args);
            foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $u) {
                $out[(int)$u['id']] = ['name' => (string)$u['user_cname'],
                                       'dept_name' => (string)($u['dept_name'] ?? ''),
                                       'position_name' => (string)($u['position_name'] ?? '')];
            }
        } catch (Throwable $e) {}
    }
    return $out;
}

/**
 * 送出稽核報告表：寫 status='submitted' 並發通知。
 * 業務日期與精確時間戳分開存（ai-rules/21）：`submit_date` 是表單上的日期，`submitted_at` 是按下去那一刻。
 * 沒有設定通知對象時**照樣送得出去**，只是回報 0 人——不要因為沒設定就擋住流程。
 */
function ia_report_submit(PDO $db, int $year, string $bizDate, int $uid, string $uname): array
{
    $st = $db->prepare("SELECT * FROM ia_report WHERE year=? AND COALESCE(is_deleted,0)=0");
    $st->execute([$year]);
    $rpt = $st->fetch(PDO::FETCH_ASSOC);
    if (!$rpt) throw new RuntimeException('請先按「儲存」建立這一年的稽核報告表');
    $d = preg_match('/^\d{4}-\d{2}-\d{2}$/', $bizDate) ? $bizDate : ia_today($db);

    $db->prepare("UPDATE ia_report SET status='submitted', submit_date=?, submitted_at=NOW(),
                      submitted_by=?, submitted_by_name=?, updated_at=NOW() WHERE report_id=?")
       ->execute([$d, $uid ?: null, $uname, (int)$rpt['report_id']]);

    $users = ia_report_notify_users($db);
    $sent  = [];
    if ($users) {
        $ncOpen = 0;
        try {
            $q = $db->prepare("SELECT COUNT(*) FROM ia_nc WHERE year=? AND COALESCE(is_deleted,0)=0 AND stage<>'closed'");
            $q->execute([$year]); $ncOpen = (int)$q->fetchColumn();
        } catch (Throwable $e) {}
        $title = $year . ' 年度內部稽核報告表已送出';
        $content = $year . " 年度的內部稽核報告表已由 " . $uname . " 送出（報告日期 " . eg_fmt_date($d) . "）。\n"
                 . ($ncOpen > 0 ? ('目前尚有 ' . $ncOpen . " 張不符合通知單未結案。\n") : "所有不符合通知單都已結案。\n")
                 . '請至「內部稽核 → 稽核報告表」查看本年度的缺點統計與缺點記錄。';
        try {
            $db->prepare("INSERT INTO live_event (eventdate, enddate, title, content, status, created_by, source,
                              show_status_to_others, ref_type, ref_id)
                          VALUES (CURDATE(), NULL, ?, ?, 0, ?, '內部稽核', 1, 'IA_REPORT', ?)")
               ->execute([$title, $content, $uid ?: null, (int)$rpt['report_id']]);
            $eid = (int)$db->lastInsertId();
            $ins = $db->prepare("INSERT INTO live_event_target (live_event_id, target_type, target_id, mode)
                                 VALUES (?, 'user', ?, 'read')");
            foreach (array_keys($users) as $to) { $ins->execute([$eid, $to]); $sent[] = $to; }
            if (function_exists('ia_nc_push')) ia_nc_push($db, $eid, $title, $content);
        } catch (Throwable $e) { /* 通知失敗不影響「已送出」這件事，畫面會顯示通知 0 人 */ }
    }
    return ['submit_date' => $d, 'notified' => count($sent),
            'users' => array_values(array_map(function ($u) { return $u['name']; }, $users))];
}

/**
 * 每個年度的內稽完成狀態（2026-09-17 使用者要求：年度選單要一眼看出哪一年做完了）。
 *
 * 三種狀態（使用者定調）：
 *   none  ＝這一年完全沒有任何內稽資料　→ 畫面不顯示任何圖示
 *   doing ＝有建立但還沒完成　　　　　　→ 進行中圖示
 *   done  ＝有內稽資料**而且完整產出報告**→ 打勾圖示
 *
 * 「完整產出報告」的判定（寫在這裡一處，畫面只負責顯示）：
 *   ①這一年有稽核報告表（ia_report）
 *   ②而且**每一張都已核准**（有一張還在草稿／送審中就還不算做完）
 *   ③而且**沒有未結案的不符合通知單**（IA 單開著就代表這一年的稽核還沒收尾）
 * 三個條件缺哪一個，`why` 會講明白，畫面直接把它當提示文字顯示——
 * 不然使用者只會看到一個沒打勾的圖示，卻不知道還差什麼。
 *
 * @return array [year => ['state','plan','cases','cases_done','checks','reports','reports_approved','nc_open','why']]
 */
function ia_year_status(PDO $db): array
{
    $out = [];
    $touch = function (int $y) use (&$out) {
        if (!isset($out[$y])) $out[$y] = ['state' => 'none', 'plan' => 0, 'cases' => 0, 'cases_done' => 0,
                                          'checks' => 0, 'reports' => 0, 'reports_approved' => 0, 'nc_open' => 0, 'why' => []];
        return $y;
    };
    $q = function (string $sql) use ($db) {
        try { return $db->query($sql)->fetchAll(PDO::FETCH_ASSOC); } catch (Throwable $e) { return []; }
    };

    // **一定要濾掉已刪除的**（2026-09-18 使用者回報：2026／2027 的計畫表早就刪了，
    // 年度旁卻還顯示「進行中」——其他四個查詢都有濾，只有這一句漏掉）
    foreach ($q("SELECT year, COUNT(*) n FROM ia_plan WHERE COALESCE(is_deleted,0)=0 GROUP BY year") as $r) {
        $out[$touch((int)$r['year'])]['plan'] = (int)$r['n'];
    }
    foreach ($q("SELECT year, COUNT(*) n, SUM(status IN ('executed','closed')) d
                   FROM ia_case WHERE COALESCE(is_deleted,0)=0 GROUP BY year") as $r) {
        $y = $touch((int)$r['year']);
        $out[$y]['cases'] = (int)$r['n']; $out[$y]['cases_done'] = (int)$r['d'];
    }
    foreach ($q("SELECT year, COUNT(*) n FROM ia_check WHERE COALESCE(is_deleted,0)=0 GROUP BY year") as $r) {
        $out[$touch((int)$r['year'])]['checks'] = (int)$r['n'];
    }
    foreach ($q("SELECT year, COUNT(*) n, SUM(status IN ('submitted','approved')) a
                   FROM ia_report WHERE COALESCE(is_deleted,0)=0 GROUP BY year") as $r) {
        $y = $touch((int)$r['year']);
        $out[$y]['reports'] = (int)$r['n']; $out[$y]['reports_approved'] = (int)$r['a'];
    }
    foreach ($q("SELECT year, COUNT(*) n FROM ia_nc
                  WHERE COALESCE(is_deleted,0)=0 AND stage <> 'closed' GROUP BY year") as $r) {
        $out[$touch((int)$r['year'])]['nc_open'] = (int)$r['n'];
    }

    foreach ($out as $y => &$s) {
        /* 一年的內稽是**從年度計畫表開始**的，所以「進行中」的前提是這一年有年度計畫表
           （2026-09-18 使用者定調：「有計畫表，但還沒有稽核報告表才顯示進行中」）。
           沒有計畫表就算有零星資料也不標成進行中——那多半是補舊資料補到一半，
           標成進行中會讓人以為這一年的稽核真的在跑。**但不可以安靜地什麼都不說**：
           `why` 會寫「還沒有年度計畫表」，年度旁的說明文字照樣顯示得出來。 */
        $hasAny = ($s['plan'] || $s['cases'] || $s['checks'] || $s['reports'] || $s['nc_open']);
        if (!$hasAny) { $s['state'] = 'none'; continue; }

        $why = [];
        // 報告表 2026-09-17 起走「送出」不走核准（使用者拍板不要核准也不要製表人），
        // 舊資料若還是 approved 也一律算數，不然以前核准過的年度會突然變回進行中。
        if ($s['reports'] === 0)                          $why[] = '還沒有稽核報告表';
        elseif ($s['reports_approved'] < $s['reports'])   $why[] = '稽核報告表還沒送出（' . $s['reports_approved'] . '／' . $s['reports'] . ' 張已送出）';
        if ($s['nc_open'] > 0)                            $why[] = '還有 ' . $s['nc_open'] . ' 張不符合通知單未結案';

        if ($s['plan'] === 0) {
            // 沒有年度計畫表：不算進行中，也不會是已完成（這一年根本還沒正式排稽核）
            $s['state'] = 'none';
            $s['why']   = array_merge(['還沒有年度計畫表（有其他內稽資料，但年度計畫表才是這一年的起點）'], $why);
            continue;
        }
        $s['state'] = $why ? 'doing' : 'done';
        $s['why']   = $why;
    }
    unset($s);
    ksort($out);
    return $out;
}

/** 年度下拉的選項：已有資料的年度 ＋ 近十年到明年（管理員要補舊年度資料，選單裡就得選得到） */
function ia_year_options(PDO $db): array
{
    /* 2026-09-17 使用者要求：**舊年度不要列出沒有資料的**。
       原本一律往前補十年，選單裡永遠有一整排空年度，看起來像有做過其實什麼都沒有。
       現在＝「有資料的年度」＋「今年、明年」（明年是給排未來稽核計畫用的）
             ＋「管理員自己登記要補的舊年度」（設定鍵 ia_extra_years）。 */
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
    $years[] = $cy; $years[] = $cy + 1;
    foreach (ia_extra_years($db) as $y) $years[] = $y;
    $years = array_values(array_unique(array_filter($years)));
    rsort($years);
    return $years;
}

/** 管理員登記「要補資料的舊年度」（本身沒有資料，但要先在選單裡看得到才建得了） */
function ia_extra_years(PDO $db): array
{
    $raw = (string)(ia_settings($db)['ia_extra_years'] ?? '');
    $arr = $raw === '' ? [] : json_decode($raw, true);
    if (!is_array($arr)) return [];
    $out = [];
    foreach ($arr as $y) { $y = (int)$y; if ($y >= 2000 && $y <= 2200) $out[] = $y; }
    return array_values(array_unique($out));
}

/**
 * 新增一個要補的舊年度（管理員限定，呼叫端自行驗權限）。
 * 已經有資料的年度本來就在選單裡，不必也不會重複登記。
 */
function ia_extra_year_add(PDO $db, int $year, string $byName): array
{
    $cy = (int)substr(ia_today($db), 0, 4);
    if ($year < 2000 || $year > $cy + 1) throw new RuntimeException('年度不正確（只能補 2000 年到明年之間）');
    $cur = ia_extra_years($db);
    if (in_array($year, $cur, true)) return ['added' => false, 'years' => $cur];
    $cur[] = $year; sort($cur);
    ia_setting_save($db, 'ia_extra_years', json_encode(array_values($cur)), $byName);
    return ['added' => true, 'years' => $cur];
}

/** 移除登記的舊年度；**該年度已經有資料時不移除**（移掉會變成有資料卻選不到） */
function ia_extra_year_del(PDO $db, int $year, string $byName): array
{
    $cur = array_values(array_filter(ia_extra_years($db), function ($y) use ($year) { return (int)$y !== $year; }));
    ia_setting_save($db, 'ia_extra_years', json_encode($cur), $byName);
    return ['years' => $cur];
}

/**
 * 幫人員清單補上「這個人所有的職務」。
 * 一個人可能跨部門兼任（例：品管部課長 兼 品管組組長），只顯示主職會看不出來，
 * 使用者要求要全部列出來。走共用的 eg_people_posts()（一個職務一列），不自己拼 SQL。
 * 回傳每人多一個 posts 陣列與 posts_text（「品管部 課長／品管組 組長」）。
 */
function ia_annotate_posts(PDO $db, array $people, string $asofDate = ''): array
{
    if (!$people) return $people;
    $byUser = [];
    try {
        foreach (($asofDate !== '' ? eg_people_posts_asof($db, [], $asofDate) : eg_people_posts($db, [])) as $p) {
            $byUser[(int)$p['id']][] = [
                'dept_id'       => $p['dept_id'],
                'dept_name'     => $p['dept_name'],
                'dept_sort'     => (int)($p['dept_sort'] ?? 999),
                'position_id'   => $p['position_id'],
                'position_name' => $p['position_name'],
                'position_sort' => (int)($p['position_sort'] ?? 999),
                'is_main'       => (int)$p['is_main'],
            ];
        }
    } catch (Throwable $e) {}
    foreach ($people as &$u) {
        $ps = $byUser[(int)$u['id']] ?? [];
        $u['posts'] = $ps;
        $u['posts_text'] = implode('／', array_map(function ($x) {
            return trim($x['dept_name'] . ' ' . $x['position_name']) . ($x['is_main'] ? '' : '(兼)');
        }, $ps));
    }
    unset($u);
    return $people;
}

/* ================= 受稽單位的稽核員／陪檢員（可多位，2026-08-27） =================
 * 使用者要求：一個受稽單位的稽核員與陪檢員都可能不只一位（2024 紙本就有兩位稽核員）。
 * 人員的唯一來源是 ia_case_dept_person；ia_case_dept 上的
 * auditor_id/auditor_name/escort_id/escort_name 只是顯示用快取
 * （name＝全部姓名以「、」串接、id/dept/position＝第一位），只由 ia_cd_people_set() 寫。
 * 讀取一律走 ia_cd_people_map()，它對「還沒搬過的舊資料」會即時由快取欄位回推，
 * 所以就算 migration 還沒跑，畫面與列印也不會空白。
 */

/** 一列最多幾位（前端擋一次、後端同規則再擋一次＝鐵律8） */
const IA_CD_PERSON_MAX = 10;

/** 名單→顯示字串（列印、清單、搜尋快取都用這個，不要各處自己 implode） */
function ia_cd_names(array $people): string
{
    $ns = [];
    foreach ($people as $p) {
        $n = trim((string)($p['user_name'] ?? ''));
        if ($n !== '') $ns[] = $n;
    }
    return implode('、', $ns);
}

/**
 * 寫入某個受稽單位列的某一種人員名單（唯一寫入點）。
 * $people 每筆：['user_id','user_name','dept_id','position_id']
 */
function ia_cd_people_set(PDO $db, int $cdId, int $caseId, string $kind, array $people): void
{
    if (!in_array($kind, ['auditor', 'escort'], true)) return;
    $db->prepare("DELETE FROM ia_case_dept_person WHERE cd_id=? AND kind=?")->execute([$cdId, $kind]);
    $ins = $db->prepare("INSERT INTO ia_case_dept_person
                            (cd_id, case_id, kind, sort_order, user_id, user_name, dept_id, position_id)
                         VALUES (?,?,?,?,?,?,?,?)");
    $i = 0; $kept = [];
    foreach ($people as $p) {
        $uid  = (int)($p['user_id'] ?? 0) ?: null;
        $name = mb_substr(trim((string)($p['user_name'] ?? '')), 0, 60);
        if (!$uid && $name === '') continue;
        $ins->execute([$cdId, $caseId, $kind, ++$i * 10, $uid, $name ?: null,
                       ($p['dept_id'] ?? null) ?: null, ($p['position_id'] ?? null) ?: null]);
        $kept[] = ['user_id' => $uid, 'user_name' => $name,
                   'dept_id' => $p['dept_id'] ?? null, 'position_id' => $p['position_id'] ?? null];
    }
    // 顯示用快取（第一位的 id／部門／職稱＋全部姓名）
    $f = $kept[0] ?? [];
    $db->prepare("UPDATE ia_case_dept SET {$kind}_id=?, {$kind}_name=?, {$kind}_dept_id=?, {$kind}_position_id=?
                   WHERE cd_id=?")
       ->execute([($f['user_id'] ?? null) ?: null, ia_cd_names($kept) ?: null,
                  ($f['dept_id'] ?? null) ?: null, ($f['position_id'] ?? null) ?: null, $cdId]);
}

/**
 * 讀取受稽單位列的人員。回 cd_id => ['auditor'=>[...], 'escort'=>[...]]，
 * 每筆含 user_id/user_name/dept_id/position_id/post_key3。
 * $cdRows 給得出來時（case_get 已經撈過 ia_case_dept）就順便當舊資料的回退來源。
 */
function ia_cd_people_map(PDO $db, array $cdIds, array $cdRows = []): array
{
    $out = [];
    foreach ($cdIds as $id) $out[(int)$id] = ['auditor' => [], 'escort' => []];
    if (!$out) return $out;
    // 部門／職稱名稱一併帶出來——列印要印「部門 姓名」，不要讓每個呼叫端各查一次
    static $deptName = null, $posName = null;
    if ($deptName === null) {
        $deptName = []; $posName = [];
        try { foreach ($db->query("SELECT id, name FROM department")->fetchAll(PDO::FETCH_ASSOC) as $d)
                  $deptName[(int)$d['id']] = (string)$d['name']; } catch (Throwable $e) {}
        try { foreach ($db->query("SELECT id, name FROM position")->fetchAll(PDO::FETCH_ASSOC) as $d)
                  $posName[(int)$d['id']] = (string)$d['name']; } catch (Throwable $e) {}
    }
    $nm = function ($map, $id) { return $id ? ($map[(int)$id] ?? '') : ''; };
    $in = implode(',', array_fill(0, count($out), '?'));
    try {
        $st = $db->prepare("SELECT * FROM ia_case_dept_person WHERE cd_id IN ($in)
                            ORDER BY kind, sort_order, cdp_id");
        $st->execute(array_keys($out));
        foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $r) {
            $k = (string)$r['kind'];
            if (!isset($out[(int)$r['cd_id']][$k])) continue;
            $out[(int)$r['cd_id']][$k][] = [
                'user_id'     => (int)$r['user_id'],
                'user_name'   => (string)($r['user_name'] ?? ''),
                'dept_id'     => $r['dept_id'] !== null ? (int)$r['dept_id'] : null,
                'position_id' => $r['position_id'] !== null ? (int)$r['position_id'] : null,
                'dept_name'     => $nm($deptName, $r['dept_id']),
                'position_name' => $nm($posName, $r['position_id']),
                'post_key3'   => ia_post_key((int)$r['user_id'], $r['dept_id'], $r['position_id']),
            ];
        }
    } catch (Throwable $e) {}

    // 還沒搬過的舊資料：由 ia_case_dept 的快取欄位即時回推一位，畫面不會空白
    $need = [];
    foreach ($out as $cd => $v) { if (!$v['auditor'] || !$v['escort']) $need[] = $cd; }
    if ($need) {
        $legacy = [];
        foreach ($cdRows as $r) { if (isset($r['cd_id'])) $legacy[(int)$r['cd_id']] = $r; }
        $miss = array_values(array_filter($need, function ($c) use ($legacy) { return !isset($legacy[$c]); }));
        if ($miss) {
            try {
                $in2 = implode(',', array_fill(0, count($miss), '?'));
                $st = $db->prepare("SELECT * FROM ia_case_dept WHERE cd_id IN ($in2)");
                $st->execute($miss);
                foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $r) $legacy[(int)$r['cd_id']] = $r;
            } catch (Throwable $e) {}
        }
        foreach ($need as $cd) {
            $r = $legacy[$cd] ?? null;
            if (!$r) continue;
            foreach (['auditor', 'escort'] as $k) {
                if ($out[$cd][$k]) continue;
                $uid = (int)($r[$k . '_id'] ?? 0);
                $nm  = trim((string)($r[$k . '_name'] ?? ''));
                if (!$uid && $nm === '') continue;
                $out[$cd][$k][] = [
                    'user_id'     => $uid,
                    'user_name'   => $nm,
                    'dept_id'     => ($r[$k . '_dept_id'] ?? null) !== null ? (int)$r[$k . '_dept_id'] : null,
                    'position_id' => ($r[$k . '_position_id'] ?? null) !== null ? (int)$r[$k . '_position_id'] : null,
                    'dept_name'     => $nm($deptName, $r[$k . '_dept_id'] ?? null),
                    'position_name' => $nm($posName, $r[$k . '_position_id'] ?? null),
                    'post_key3'   => ia_post_key($uid, $r[$k . '_dept_id'] ?? null, $r[$k . '_position_id'] ?? null),
                ];
            }
        }
    }
    return $out;
}

/**
 * 某個稽核案件的稽核員（或陪檢員）有哪些人——IA 單的權限判定要用。
 * $deptId 有給就只看該受稽單位那幾列（受稽單位群組會歸戶到代表部門）。
 * 舊資料只有 ia_case_dept 的單一欄位，這裡一併 UNION 進來。
 */
function ia_case_person_ids(PDO $db, int $caseId, string $kind = 'auditor', ?int $deptId = null): array
{
    if (!$caseId || !in_array($kind, ['auditor', 'escort'], true)) return [];
    $ids = [];
    $w = 'cd.case_id=?'; $p = [$caseId];
    if ($deptId) { $w .= ' AND cd.dept_id=?'; $p[] = $deptId; }
    try {
        $st = $db->prepare("SELECT DISTINCT pr.user_id FROM ia_case_dept_person pr
                            JOIN ia_case_dept cd ON cd.cd_id=pr.cd_id
                            WHERE pr.kind=? AND pr.user_id IS NOT NULL AND $w");
        $st->execute(array_merge([$kind], $p));
        foreach ($st->fetchAll(PDO::FETCH_COLUMN) as $x) $ids[(int)$x] = 1;
    } catch (Throwable $e) {}
    try {
        $st = $db->prepare("SELECT DISTINCT cd.{$kind}_id FROM ia_case_dept cd
                            WHERE cd.{$kind}_id IS NOT NULL AND $w");
        $st->execute($p);
        foreach ($st->fetchAll(PDO::FETCH_COLUMN) as $x) $ids[(int)$x] = 1;
    } catch (Throwable $e) {}
    unset($ids[0]);
    return array_map('intval', array_keys($ids));
}

/* ============================ 稽核小組（年度） ============================
 * 使用者要求（2026-09-16）：建稽核通知單前先組好這一年的稽核小組，可從其他年度複製；
 * 自動建立會議紀錄時**與會人員＝小組成員、主席固定為稽核組長**。
 */

const IA_TEAM_ROLES = ['leader' => '稽核組長', 'auditor' => '稽核員', 'escort' => '陪檢員'];

/**
 * 該年度小組的**基準日**——候選人員、在職與否、部門職稱一律以這一天為準。
 * 使用者可以自己指定（ia_team.base_date）；沒指定時才用系統推算值：
 * 過去年度＝該年 12/31（那一年的組織狀態），當年度以後＝今天。
 * @param bool $explicitOnly true＝只回使用者設定值（沒設回空字串），給設定畫面判斷「是不是預設值」用
 */
function ia_team_base_date(PDO $db, int $year, bool $explicitOnly = false): string
{
    $set = '';
    try {
        $st = $db->prepare("SELECT base_date FROM ia_team WHERE year=?");
        $st->execute([$year]);
        $v = (string)($st->fetchColumn() ?: '');
        if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $v)) $set = $v;
    } catch (Throwable $e) {}
    if ($explicitOnly) return $set;
    if ($set !== '') return $set;
    $today = ia_today($db);
    $cy = (int)substr($today, 0, 4);
    if ($year <= 0 || $year >= $cy) return $today;
    return $year . '-12-31';
}

/** 寫入該年度的基準日（空字串＝清掉，回到系統推算值） */
function ia_team_set_base_date(PDO $db, int $year, string $date, string $byName): void
{
    if ($year < 2000 || $year > 2200) throw new RuntimeException('年度不正確');
    $d = trim($date);
    if ($d !== '' && !preg_match('/^\d{4}-\d{2}-\d{2}$/', $d)) throw new RuntimeException('基準日格式不正確');
    $db->prepare("INSERT INTO ia_team (year, base_date, updated_at, updated_by) VALUES (?,?,NOW(),?)
                  ON DUPLICATE KEY UPDATE base_date=VALUES(base_date), updated_at=NOW(), updated_by=VALUES(updated_by)")
       ->execute([$year, ($d !== '' ? $d : null), $byName]);
}

/** 相容名稱：該年度小組的基準日（舊呼叫端沿用） */
function ia_team_asof(PDO $db, int $year): string
{
    return ia_team_base_date($db, $year);
}

/**
 * 某年度的稽核小組。每列：tm_id/role/role_label/user_id/user_name/dept_id/dept_name/
 *                        position_id/position_name/post_key3/note
 * 部門與職稱名稱一律**依該年度回推**（ai-rules/22），不是印現在的。
 */
function ia_team_get(PDO $db, int $year, string $asofOverride = ''): array
{
    $rows = [];
    try {
        $st = $db->prepare("SELECT * FROM ia_team_member WHERE year=? ORDER BY sort_order, tm_id");
        $st->execute([$year]);
        $rows = $st->fetchAll(PDO::FETCH_ASSOC);
    } catch (Throwable $e) { return []; }
    if (!$rows) return [];

    // 設定畫面改基準日時會帶 $asofOverride 進來「試算」，還沒存也看得到結果
    $asof = (preg_match('/^\d{4}-\d{2}-\d{2}$/', $asofOverride)) ? $asofOverride : ia_team_base_date($db, $year);
    $postByKey = $nameById = [];
    try {
        foreach (eg_people_posts_asof($db, [], $asof) as $p) {
            $postByKey[ia_post_key((int)$p['id'], $p['dept_id'], $p['position_id'])] = $p;
            $nameById[(int)$p['id']] = (string)$p['user_cname'];
        }
    } catch (Throwable $e) {}
    $deptN = $posN = [];
    try {
        foreach ($db->query("SELECT id, name FROM department")->fetchAll(PDO::FETCH_ASSOC) as $d) $deptN[(int)$d['id']] = (string)$d['name'];
        foreach ($db->query("SELECT id, name FROM position")->fetchAll(PDO::FETCH_ASSOC)   as $p) $posN[(int)$p['id']]  = (string)$p['name'];
    } catch (Throwable $e) {}

    $out = [];
    foreach ($rows as $r) {
        $key = ia_post_key((int)$r['user_id'], (int)$r['dept_id'], (int)$r['position_id']);
        $hit = $postByKey[$key] ?? null;
        $out[] = [
            'tm_id'         => (int)$r['tm_id'],
            'role'          => (string)$r['role'],
            'role_label'    => IA_TEAM_ROLES[$r['role']] ?? (string)$r['role'],
            'user_id'       => (int)$r['user_id'],
            'user_name'     => $nameById[(int)$r['user_id']] ?? (string)($r['user_name'] ?? ''),
            'dept_id'       => (int)$r['dept_id'] ?: null,
            'dept_name'     => $hit ? (string)$hit['dept_name'] : ($deptN[(int)$r['dept_id']] ?? ''),
            'position_id'   => (int)$r['position_id'] ?: null,
            'position_name' => $hit ? (string)$hit['position_name'] : ($posN[(int)$r['position_id']] ?? ''),
            'post_key3'     => $key,
            // 主職／兼任要看得出來（名單上同一個人可能在兩個部門各一列）
            'is_main'       => $hit ? (int)($hit['is_main'] ?? 1) : 1,
            'note'          => (string)($r['note'] ?? ''),
            // 當年度已經不在職／職務已異動的成員要標出來，否則使用者看不出為什麼會議帶不到人
            'missing'       => $hit ? 0 : 1,
        ];
    }
    return $out;
}

/* ============================ 會議紀錄的出席人員（唯一實作） ============================
 * 建立會議與「依目前小組重新帶入」共用同一份規則（鐵律4：兩個寫入點規則必定走鐘）。
 */

/** 會議主席＝稽核組長：這張單指定的優先，其次該年度小組裡 role=leader，再沒有才退回操作者 */
function ia_meeting_chair(PDO $db, array $c, array $team, int $fallbackUid, string $fallbackName): array
{
    $teamLead = null;
    foreach ($team as $m) if (($m['role'] ?? '') === 'leader') { $teamLead = $m; break; }
    $chairId = (int)($c['leader_id'] ?? 0) ?: (int)($teamLead['user_id'] ?? 0) ?: $fallbackUid;
    $chairName = '';
    try {
        $q = $db->prepare("SELECT user_cname FROM `user` WHERE id=?");
        $q->execute([$chairId]);
        $chairName = (string)($q->fetchColumn() ?: '');
    } catch (Throwable $e) {}
    if ($chairName === '') $chairName = $fallbackName;
    // 主席「以哪個職務」出席：這張單存的職務優先（圖章與名單的部門職稱才對得起來）
    $chairPost = null;
    if ((int)($c['leader_id'] ?? 0) === $chairId && (int)($c['leader_dept_id'] ?? 0)) {
        $chairPost = ['dept_id' => (int)$c['leader_dept_id'], 'position_id' => (int)$c['leader_position_id']];
    } elseif ($teamLead && (int)$teamLead['user_id'] === $chairId) {
        $chairPost = ['dept_id' => (int)$teamLead['dept_id'], 'position_id' => (int)$teamLead['position_id']];
    }
    return ['id' => $chairId, 'name' => $chairName, 'post' => $chairPost];
}

/**
 * 整批寫入某筆會議紀錄的出席人員（建立會議／重新帶入小組成員共用）。
 *
 * 名單來源：①該年度稽核小組 ②小組還沒建立時退回這張單各受稽單位的稽核員與陪檢員
 *           （否則小組沒建就變成一場沒有人的會議，比帶錯人更難用）。
 *
 * **一人一列、多職務併列**（2026-09-17 使用者拍板）：小組是「一筆職務一列」，同一個人可能掛兩個職務，
 * 但會議出席名單一人只會有一列；第一個職務寫進 dept_name/position_name（**圖章只吃這一組**），
 * 其餘寫進 alt_posts（純顯示用 JSON），名單與簽到表逐行對齊列出。
 *
 * 職務一律依**會議日期**判定（ai-rules/22）：名單上登記的職務當天還不成立，就印他當時真正的身分，
 * 並把換掉的（shifted）與當天還沒兼任因此沒列出的（dropped）都回報給呼叫端，不可以安靜換掉。
 *
 * 已簽到的人保留 signed／signed_at（重新帶入時不可把人家的簽到洗掉）。
 *
 * @return array ['count','from_team','shifted','dropped','removed']
 */
function ia_meeting_attendees_apply(PDO $db, int $mid, int $cid, array $team, array $chair, string $mdate): array
{
    $seen = []; $att = [];
    // 同一個人第二次被 push ＝ 他的另一個職務，併進同一列而不是另開一列
    $push = function ($id, $name, $dept = 0, $pos = 0) use (&$seen, &$att) {
        $id = (int)$id; $dept = (int)$dept; $pos = (int)$pos;
        if (!$id) return;
        if (!isset($seen[$id])) {
            $seen[$id] = count($att);
            $att[] = ['id' => $id, 'name' => (string)$name, 'posts' => []];
        }
        if (!$dept && !$pos) return;                      // 沒指定職務的那一次不占一個職務位
        $i = $seen[$id];
        foreach ($att[$i]['posts'] as $p) if ($p['dept_id'] === $dept && $p['position_id'] === $pos) return;
        $att[$i]['posts'][] = ['dept_id' => $dept, 'position_id' => $pos];
    };

    $push((int)$chair['id'], (string)$chair['name'],
          (int)($chair['post']['dept_id'] ?? 0), (int)($chair['post']['position_id'] ?? 0));
    $fromTeam = false;
    if ($team) {
        $fromTeam = true;
        foreach ($team as $m) $push($m['user_id'], $m['user_name'], (int)$m['dept_id'], (int)$m['position_id']);
    } else {
        $q = $db->prepare("SELECT * FROM ia_case_dept WHERE case_id=? ORDER BY sort_order");
        $q->execute([$cid]);
        $cdRows = $q->fetchAll(PDO::FETCH_ASSOC);
        $pmap = ia_cd_people_map($db, array_map(function ($r) { return (int)$r['cd_id']; }, $cdRows), $cdRows);
        foreach ($cdRows as $r) {
            $pp = $pmap[(int)$r['cd_id']] ?? ['auditor' => [], 'escort' => []];
            foreach (array_merge($pp['auditor'], $pp['escort']) as $x) {
                $push($x['user_id'], $x['user_name'], (int)($x['dept_id'] ?? 0), (int)($x['position_id'] ?? 0));
            }
        }
    }

    // 會議日期當天真正成立的職務（名稱一律用 id 回查現名，不用異動快照裡凍結的舊名）
    $postByKey = $nameById = [];
    foreach (eg_people_posts_asof($db, [], $mdate) as $p) {
        $postByKey[ia_post_key((int)$p['id'], $p['dept_id'], $p['position_id'])] = $p;
        $nameById[(int)$p['id']] = (string)$p['user_cname'];
    }

    // 已簽到的狀態要保留（重新帶入時不可洗掉別人的簽到）
    $old = [];
    $oq = $db->prepare("SELECT user_id, signed, signed_at FROM meeting_attendee WHERE meeting_id=?");
    $oq->execute([$mid]);
    foreach ($oq->fetchAll(PDO::FETCH_ASSOC) as $o) $old[(int)$o['user_id']] = $o;

    $shifted = $dropped = []; $keepUids = [];
    $db->prepare("DELETE FROM meeting_attendee WHERE meeting_id=?")->execute([$mid]);
    $ins = $db->prepare("INSERT INTO meeting_attendee (meeting_id, user_id, user_name, dept_name, position_name,
                             alt_posts, is_chair, signed, signed_at) VALUES (?,?,?,?,?,?,?,?,?)");
    foreach ($att as $a) {
        $nm = $nameById[$a['id']] ?? $a['name'];
        $ok = [];                                          // 當天真的成立的職務（依名單順序）
        foreach ($a['posts'] as $p) {
            $key   = ia_post_key($a['id'], $p['dept_id'], $p['position_id']);
            $label = ['d' => ia_dept_name_now($db, $p['dept_id']), 'p' => ia_position_name_now($db, $p['position_id'])];
            if (isset($postByKey[$key])) $ok[] = $label;
            else $dropped[] = ['name' => $nm, 'post' => trim($label['d'] . ' ' . $label['p'])];
        }
        if ($ok) {
            $dept = $ok[0]['d']; $pos = $ok[0]['p'];
            $alt  = array_slice($ok, 1, 4);
        } else {
            // 名單上的職務當天都不成立 → 印他當時真正的身分，並主動回報（使用者要求）
            $idt  = ia_identity_asof($db, $a['id'], $mdate);
            $dept = $idt['dept']; $pos = $idt['position']; $alt = [];
            if ($a['posts']) {
                $first = $a['posts'][0];
                $want  = trim(ia_dept_name_now($db, $first['dept_id']) . ' ' . ia_position_name_now($db, $first['position_id']));
                $got   = trim($dept . ' ' . $pos);
                if ($want !== '' && $want !== $got) {
                    $shifted[] = ['name' => $nm, 'listed' => $want, 'actual' => ($got !== '' ? $got : '（當時查不到職務）')];
                    // 第一個職務已經用 shifted 講明了，不要在 dropped 再講一次同一件事
                    foreach ($dropped as $k => $d) if ($d['name'] === $nm && $d['post'] === $want) { unset($dropped[$k]); break; }
                }
            }
        }
        $o = $old[$a['id']] ?? null;
        $altJson = $alt ? json_encode($alt, JSON_UNESCAPED_UNICODE) : null;
        if ($altJson !== null && mb_strlen($altJson) > 250) $altJson = null;
        $ins->execute([$mid, $a['id'], $nm, $dept ?: null, $pos ?: null, $altJson,
                       $a['id'] === (int)$chair['id'] ? 1 : 0,
                       $o ? (int)$o['signed'] : 0, $o ? $o['signed_at'] : null]);
        $keepUids[$a['id']] = 1;
    }
    // 重新帶入時被移出名單、但**已經簽到過**的人要講出來（他的簽到紀錄會跟著不見）
    $removed = [];
    foreach ($old as $uidOld => $o) {
        if (!isset($keepUids[$uidOld]) && (int)$o['signed'] === 1) {
            $q = $db->prepare("SELECT user_cname FROM `user` WHERE id=?"); $q->execute([$uidOld]);
            $removed[] = (string)($q->fetchColumn() ?: ('#' . $uidOld));
        }
    }
    return ['count' => count($att), 'from_team' => $fromTeam,
            'shifted' => array_values($shifted), 'dropped' => array_values($dropped), 'removed' => $removed];
}

/** 已經建過小組的年度（複製來源下拉用） */
function ia_team_years(PDO $db): array
{
    try {
        return array_map('intval', $db->query("SELECT DISTINCT year FROM ia_team_member ORDER BY year DESC")
                                      ->fetchAll(PDO::FETCH_COLUMN));
    } catch (Throwable $e) { return []; }
}

/**
 * 整批覆寫某年度的小組成員（唯一寫入點）。
 * $members 每筆：['post_key3'|'user_id'+'dept_id'+'position_id', 'role', 'note']
 * 規則：①稽核組長最多一位（會議主席固定用他）②同一個人在同一年度只算一列
 *       ③職務不存在／人員不存在一律擋下（鐵律8）
 */
function ia_team_save(PDO $db, int $year, array $members, string $byName, string $asofOverride = ''): int
{
    if ($year < 2000 || $year > 2200) throw new RuntimeException('年度不正確');
    $asof = (preg_match('/^\d{4}-\d{2}-\d{2}$/', $asofOverride)) ? $asofOverride : ia_team_base_date($db, $year);
    $valid = [];
    try {
        foreach (eg_people_posts_asof($db, [], $asof) as $p) {
            $valid[ia_post_key((int)$p['id'], $p['dept_id'], $p['position_id'])] = $p;
        }
        // 補上現況的職務：小組常常是「今年新接任的人」，用年底回推會漏掉剛異動的
        foreach (eg_people_posts($db, []) as $p) {
            $valid[ia_post_key((int)$p['id'], $p['dept_id'], $p['position_id'])] = $p;
        }
    } catch (Throwable $e) {}
    if (!$valid) throw new RuntimeException('目前查不到人員職務資料，為避免誤刪小組名單已停止儲存');

    $clean = []; $seenUser = []; $leaders = 0;
    foreach ($members as $m) {
        if (!is_array($m)) continue;
        $key = trim((string)($m['post_key3'] ?? ''));
        if ($key === '') {
            $key = ia_post_key((int)($m['user_id'] ?? 0), (int)($m['dept_id'] ?? 0), (int)($m['position_id'] ?? 0));
        }
        list($u, $d, $p) = ia_post_parse($key);
        if (!$u) continue;
        if (!isset($valid[$key])) throw new RuntimeException('小組成員的職務不存在（可能已異動），請重新挑選：' . $key);
        /* 去重的單位是「職務」不是「人」（2026-09-16 使用者回報修正）。
           同一個人**本來就會用不同職務各掛一筆**：何沐桐主職技術課工程師、兼生管組組長，
           資格名單把「技術課 工程師」單獨指定成陪檢員（他負責技術課的資料），
           那就是兩個不同的身分、兩筆。原本按 user_id 去重＝他只要先以組長身分進了小組，
           技術課工程師那一筆就再也加不進來，而且畫面上連選項都看不到。
           會議紀錄那邊本來就會依 user_id 再去重一次，所以不會變成同一個人出席兩次。 */
        if (isset($seenUser[$key])) continue;               // 同一個「職務」只算一列
        $seenUser[$key] = 1;
        $role = (string)($m['role'] ?? 'auditor');
        if (!isset(IA_TEAM_ROLES[$role])) $role = 'auditor';
        if ($role === 'leader') { $leaders++; if ($leaders > 1) throw new RuntimeException('稽核組長只能有一位'); }
        $clean[] = ['role' => $role, 'user_id' => $u, 'user_name' => (string)$valid[$key]['user_cname'],
                    'dept_id' => $d ?: null, 'position_id' => $p ?: null,
                    'note' => mb_substr(trim((string)($m['note'] ?? '')), 0, 100)];
    }

    $db->prepare("DELETE FROM ia_team_member WHERE year=?")->execute([$year]);
    if (!$clean) return 0;
    $ins = $db->prepare("INSERT INTO ia_team_member
                            (year, role, user_id, user_name, dept_id, position_id, note, sort_order, updated_at, updated_by)
                         VALUES (?,?,?,?,?,?,?,?,NOW(),?)");
    $i = 0;
    foreach ($clean as $c) {
        $ins->execute([$year, $c['role'], $c['user_id'], $c['user_name'], $c['dept_id'], $c['position_id'],
                       ($c['note'] !== '' ? $c['note'] : null), ++$i * 10, $byName]);
    }
    return count($clean);
}

/**
 * 從別的年度整批複製小組成員。
 * **職務已不存在的成員會被略過**（例：當年的組員今年已離職），回傳 [複製筆數, 略過的姓名]。
 */
function ia_team_copy(PDO $db, int $fromYear, int $toYear, string $byName): array
{
    $src = ia_team_get($db, $fromYear);
    if (!$src) throw new RuntimeException($fromYear . ' 年度沒有稽核小組可以複製');
    $valid = [];
    try {
        foreach (eg_people_posts_asof($db, [], ia_team_asof($db, $toYear)) as $p)
            $valid[ia_post_key((int)$p['id'], $p['dept_id'], $p['position_id'])] = 1;
        foreach (eg_people_posts($db, []) as $p)
            $valid[ia_post_key((int)$p['id'], $p['dept_id'], $p['position_id'])] = 1;
    } catch (Throwable $e) {}

    $keep = []; $skipped = [];
    foreach ($src as $m) {
        if (!isset($valid[$m['post_key3']])) { $skipped[] = $m['user_name'] . '（' . trim($m['dept_name'] . ' ' . $m['position_name']) . '）'; continue; }
        $keep[] = ['post_key3' => $m['post_key3'], 'role' => $m['role'], 'note' => $m['note']];
    }
    if (!$keep) throw new RuntimeException('來源年度的成員在 ' . $toYear . ' 年都已不在原職務上，沒有可以複製的人');
    $n = ia_team_save($db, $toYear, $keep, $byName);
    return [$n, $skipped];
}

/* ============================ 稽核範本 ============================ */

/**
 * 某些部門（含子部門）底下、具備某身分資格的職務。
 * 候選部門是多選，這裡把每個部門展開成子樹再取聯集。
 */
function ia_posts_in_depts(PDO $db, string $kind, array $deptIds, string $asofDate = ''): array
{
    $scope = [];
    foreach ($deptIds as $d) {
        $d = (int)$d; if (!$d) continue;
        foreach (eg_dept_subtree_ids($db, $d) ?: [$d] as $x) $scope[(int)$x] = 1;
    }
    if (!$scope) return [];
    return array_values(array_filter(ia_qualified_posts($db, $kind, $asofDate), function ($p) use ($scope) {
        return isset($scope[(int)$p['dept_id']]);
    }));
}

/**
 * 稽核範本清單，每筆都把候選人員一併算好給前端用。
 * 規則（使用者 2026-08-26 指定）：
 *   ①候選部門多選，實際人員仍由填表人挑
 *   ②候選範圍內只有一位有資格 → 自動帶入
 *   ③**先決定稽核員**，陪檢員候選再把稽核員那個人排除掉（同一人不可兼任兩邊）
 *   ④陪檢員可不填
 * 回傳每筆：tpl_id/process_name/unit_dept_id/unit_name/note/
 *           auditor_dept_ids[]/escort_dept_ids[]/auditor_cands[]/escort_cands[]/
 *           auditor_auto（只有一位時的職務鍵，否則空）
 */
function ia_process_templates(PDO $db, bool $activeOnly = true, string $asofDate = ''): array
{
    $rows = [];
    try {
        $sql = "SELECT * FROM ia_process_template" . ($activeOnly ? " WHERE is_active=1" : "")
             . " ORDER BY sort_order, tpl_id";
        $rows = $db->query($sql)->fetchAll(PDO::FETCH_ASSOC);
    } catch (Throwable $e) { return []; }
    if (!$rows) return [];

    $deptsOf = [];
    try {
        foreach ($db->query("SELECT tpl_id, kind, dept_id FROM ia_process_tpl_dept")->fetchAll(PDO::FETCH_ASSOC) as $r) {
            $deptsOf[(int)$r['tpl_id']][$r['kind']][] = (int)$r['dept_id'];
        }
    } catch (Throwable $e) {}

    $unitName = [];
    foreach (ia_audit_units($db) as $u) $unitName[(int)$u['key']] = $u['name'];
    $deptName = [];
    try {
        foreach ($db->query("SELECT id, name FROM department")->fetchAll(PDO::FETCH_ASSOC) as $d) {
            $deptName[(int)$d['id']] = (string)$d['name'];
        }
    } catch (Throwable $e) {}

    $out = [];
    foreach ($rows as $r) {
        $id = (int)$r['tpl_id'];
        $aDepts = $deptsOf[$id]['auditor'] ?? [];
        $eDepts = $deptsOf[$id]['escort']  ?? [];
        $aCands = ia_posts_in_depts($db, 'auditor', $aDepts, $asofDate);
        $eCands = ia_posts_in_depts($db, 'escort',  $eDepts, $asofDate);

        // ③先決定稽核員：只有一位候選就自動帶入，陪檢員候選再把那個人排掉
        $auto = (count($aCands) === 1) ? $aCands[0]['post_key3'] : '';
        $autoUid = (count($aCands) === 1) ? (int)$aCands[0]['id'] : 0;
        $eForAuto = $autoUid
            ? array_values(array_filter($eCands, function ($p) use ($autoUid) { return (int)$p['id'] !== $autoUid; }))
            : $eCands;
        $eAuto = (count($eForAuto) === 1) ? $eForAuto[0]['post_key3'] : '';

        $out[] = [
            'tpl_id'          => $id,
            'process_name'    => (string)$r['process_name'],
            'unit_dept_id'    => (int)$r['unit_dept_id'],
            'unit_name'       => $unitName[(int)$r['unit_dept_id']] ?? ($deptName[(int)$r['unit_dept_id']] ?? ''),
            'note'            => (string)($r['note'] ?? ''),
            'is_active'       => (int)$r['is_active'],
            'sort_order'      => (int)$r['sort_order'],
            'auditor_dept_ids'=> $aDepts,
            'escort_dept_ids' => $eDepts,
            'auditor_dept_names' => array_values(array_map(function ($d) use ($deptName) { return $deptName[$d] ?? ''; }, $aDepts)),
            'escort_dept_names'  => array_values(array_map(function ($d) use ($deptName) { return $deptName[$d] ?? ''; }, $eDepts)),
            'auditor_cands'   => $aCands,
            'escort_cands'    => $eCands,
            'auditor_auto'    => $auto,
            'escort_auto'     => $eAuto,
        ];
    }
    return $out;
}

/** 範本設定驗證（前端擋一次、後端同規則再擋一次＝鐵律8） */
function ia_tpl_validate(PDO $db, int $tplId, string $name, int $unitDeptId, array $aDepts, array $eDepts): string
{
    $name = trim($name);
    if ($name === '') return '請填稽核起始主過程';
    if (mb_strlen($name) > 150) return '稽核起始主過程過長（上限 150 字）';
    if (!$unitDeptId) return '請選擇受稽單位';

    $units = [];
    foreach (ia_audit_units($db) as $u) $units[(int)$u['key']] = 1;
    if (!isset($units[$unitDeptId])) return '受稽單位不存在（可能已被併入其他受稽單位群組）';

    $all = array_values(array_unique(array_merge(array_map('intval', $aDepts), array_map('intval', $eDepts))));
    $all = array_values(array_filter($all));
    if ($all) {
        $in = implode(',', array_fill(0, count($all), '?'));
        $st = $db->prepare("SELECT COUNT(*) FROM department WHERE id IN ($in)");
        $st->execute($all);
        if ((int)$st->fetchColumn() !== count($all)) return '有部門不存在';
    }
    if (!$aDepts) return '請至少選一個稽核員候選部門';

    // 同一個「起始主過程＋受稽單位」不要建兩個範本，否則帶入時會分不清該用哪一個
    try {
        $st = $db->prepare("SELECT COUNT(*) FROM ia_process_template
                            WHERE process_name=? AND unit_dept_id=? AND tpl_id<>? AND is_active=1");
        $st->execute([$name, $unitDeptId, $tplId]);
        if ((int)$st->fetchColumn() > 0) return '已經有相同「起始主過程＋受稽單位」的範本了';
    } catch (Throwable $e) {}
    return '';
}

/* ============================ 稽核範本組合 ============================ */
/**
 * 範本組合清單（2026-09-14 使用者交辦）：常一起稽核的那幾個範本存成一組，
 * 填通知單時選一次就整批帶入好幾列。
 * 組合只存「有哪些範本」，主過程／受稽單位／候選人員一律即時由範本算出來（鐵律4），
 * 所以範本改了、或被停用刪除，組合帶出來的內容自動跟著對。
 * 回傳每筆：set_id/set_name/note/is_active/tpl_ids[]/tpl_names[]（含已失效範本的提示）
 */
function ia_tpl_sets(PDO $db, bool $activeOnly = true): array
{
    $rows = [];
    try {
        $sql = "SELECT * FROM ia_process_tpl_set" . ($activeOnly ? " WHERE is_active=1" : "")
             . " ORDER BY sort_order, set_id";
        $rows = $db->query($sql)->fetchAll(PDO::FETCH_ASSOC);
    } catch (Throwable $e) { return []; }
    if (!$rows) return [];

    // 範本本體（含停用的也撈，才看得出「這一組裡有一個範本已停用」）
    $tpl = [];
    foreach (ia_process_templates($db, false) as $t) $tpl[(int)$t['tpl_id']] = $t;

    $items = [];
    try {
        foreach ($db->query("SELECT set_id, tpl_id, sort_order FROM ia_process_tpl_set_item
                             ORDER BY sort_order, si_id")->fetchAll(PDO::FETCH_ASSOC) as $r) {
            $items[(int)$r['set_id']][] = (int)$r['tpl_id'];
        }
    } catch (Throwable $e) {}

    $out = [];
    foreach ($rows as $r) {
        $sid   = (int)$r['set_id'];
        $ids   = $items[$sid] ?? [];
        $names = [];
        $usable = [];
        foreach ($ids as $tid) {
            $t = $tpl[$tid] ?? null;
            if (!$t) { $names[] = '（範本已刪除）'; continue; }
            $nm = $t['process_name'] . '　→　' . $t['unit_name'];
            if (!(int)$t['is_active']) { $names[] = $nm . '（已停用）'; continue; }
            $names[]  = $nm;
            $usable[] = $tid;
        }
        $out[] = [
            'set_id'    => $sid,
            'set_name'  => (string)$r['set_name'],
            'note'      => (string)($r['note'] ?? ''),
            'is_active' => (int)$r['is_active'],
            'sort_order'=> (int)$r['sort_order'],
            'tpl_ids'   => $usable,      // 帶入通知單時真正會用的（已停用／已刪除的不帶）
            'all_ids'   => $ids,         // 設定畫面用（勾選狀態要看得到原本挑了哪些）
            'tpl_names' => $names,
        ];
    }
    return $out;
}

/** 範本組合設定驗證（前端擋一次、後端同規則再擋一次＝鐵律8） */
function ia_tplset_validate(PDO $db, int $setId, string $name, array $tplIds): string
{
    $name = trim($name);
    if ($name === '') return '請填組合名稱';
    if (mb_strlen($name) > 100) return '組合名稱過長（上限 100 字）';
    if (!$tplIds) return '請至少勾選一個範本';
    if (count($tplIds) > 50) return '一個組合最多 50 個範本';

    $in = implode(',', array_fill(0, count($tplIds), '?'));
    try {
        $st = $db->prepare("SELECT COUNT(*) FROM ia_process_template WHERE tpl_id IN ($in)");
        $st->execute($tplIds);
        if ((int)$st->fetchColumn() !== count($tplIds)) return '有範本不存在（可能剛被刪除，請重新整理）';
    } catch (Throwable $e) { return '範本讀取失敗'; }

    try {
        $st = $db->prepare("SELECT COUNT(*) FROM ia_process_tpl_set WHERE set_name=? AND set_id<>?");
        $st->execute([$name, $setId]);
        if ((int)$st->fetchColumn() > 0) return '已經有同名的範本組合了';
    } catch (Throwable $e) {}
    return '';
}

/* ============================ AS 文件的部門代碼 ============================ */
/**
 * AS 文件編號第二段的部門代碼 → 部門名稱（例 QA→品保部）。
 * 給「建立查檢表」把作業項目標籤分類用——162 個標籤平鋪在一起找不到東西，
 * 依代碼歸到部門底下才有辦法快速挑。代碼本身設在 AS 文件管理（as_dept_code），不寫死。
 */
function ia_as_dept_code_names(PDO $db): array
{
    $out = [];
    try {
        $rows = $db->query("SELECT c.code, c.label, d.name
                              FROM as_dept_code c
                         LEFT JOIN department d ON d.id = c.department_id
                          ORDER BY c.sort_order, c.id")->fetchAll(PDO::FETCH_ASSOC);
        foreach ($rows as $r) {
            $code = strtoupper(trim((string)$r['code']));
            if ($code === '' || isset($out[$code])) continue;   // 同一代碼有多列（例 SM）時只取第一個
            $name = trim((string)($r['name'] ?? ''));
            if ($name === '') $name = trim((string)($r['label'] ?? ''));
            $out[$code] = $name !== '' ? $name : $code;
        }
    } catch (Throwable $e) {}
    // 同一個部門名稱被兩個代碼共用時（例 PD／PH 都是生管組）要標出代碼，
    // 否則分類清單上會出現兩個一模一樣的群組標題，看不出差別
    $cnt = array_count_values($out);
    foreach ($out as $code => $name) {
        if (($cnt[$name] ?? 0) > 1) $out[$code] = $name . '（' . $code . '）';
    }
    return $out;
}
