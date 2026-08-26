<?php
/**
 * 專案管理（2-GM-02 專案管理程序）共用庫
 * ------------------------------------------------------------------
 * 涵蓋表單：
 *   2-GM-02-02 專案執行規劃表（目標／主要任務／預計・實際完成日／負責人＋周期甘特）
 *   2-GM-02-03 專案管理卡（項次／各項目標名稱／主辦單位／承辦人／目前應達成基準／現階段問題／後續辦理方法／備註）
 *   ※ 2-GM-02-01 專案計劃需求表：依使用者決定不建置（改由「訂單轉專案」立案，程序書另行改版廢止該表）
 *
 * 設計拍板（使用者以 AskUserQuestion 決定，2026-08-20）：
 *   - 單一主頁＋分頁；訂單為主軸（料號由訂單帶出，亦可在還沒訂單時先掛料號）
 *   - 專案代號 7 碼＝類型1碼(D/C/P/S)＋西元年後2碼＋月2碼＋流水2碼（例 C260801），流水依「同一年月」遞增
 *   - 立案走完整線上會簽＋核准（原掛在 2-GM-02-01 的會簽移到專案本身，對應程序書 §6.9.1「權責主管核可之後」）
 *   - 管理卡一專案多張（每次檢討一張）；目標/主辦單位/承辦人自動帶入，「目前應達成基準」由甘特日程自動判定
 *   - BOM 開立時自動帶入製程，另有手動「同步 BOM」鈕；BOM 製程有變更要主動提示專案管理人
 *   - 四個文件頁（產品開發評估表/型態識別文件管制表/PFMEA/外來文件清單）的既有偵測鈕增加「專案」來源
 *
 * 規則遵循：
 *   - 日期顯示 YYYY.MM.DD 走 eg_fmt_date()（ai-rules/20）
 *   - AS 文件綁定只走 asdoc_lib.php，版次依業務日期回推（ai-rules/16 第一之三、第三之四節）
 *   - 簽章走 eg_stamp.js，職稱依業務日期回推當時職務（ai-rules/18、22）
 *   - 核准人解析三段式＋強制 SoD 迴避（ai-rules/19）
 *   - 自動簽核業務日期與時間戳分離、錯開 5~30 分不跨日（ai-rules/21）
 *   - 時間戳一律取 DB 時間（PHP date() 是 UTC、MySQL NOW() 是本地，混用會差 8 小時）
 *   - 人員清單一律走 people_lib.php（ai-rules/08 第五節）
 */

require_once __DIR__ . '/org_role_lib.php';
require_once __DIR__ . '/delegate_lib.php';
require_once __DIR__ . '/asdoc_lib.php';
require_once __DIR__ . '/people_lib.php';
require_once __DIR__ . '/date_fmt_lib.php';
require_once __DIR__ . '/position_history_lib.php';
require_once __DIR__ . '/car_lib.php';   // 工作日行事曆唯一來源：car_holiday_sets()／car_working_days_between()

/** AS 文件綁定模組代碼（一表一碼，值只存 id，見 ai-rules/16 第一之三節） */
const PRJ_ASDOC_PLAN = 'project_plan';   // 2-GM-02-02 專案執行規劃表
const PRJ_ASDOC_CARD = 'project_card';   // 2-GM-02-03 專案管理卡

/** 專案類型（程序書 §6.13：開發D／客製C／生產P／服務S；固定四種，是編碼的一部分不可自訂） */
const PRJ_TYPES = ['D' => '開發', 'C' => '客製', 'P' => '生產', 'S' => '服務'];

/** 專案生命週期（程序書 §6.7.1 五個作業流程） */
const PRJ_PHASES = [
    'initiating'  => '籌備',
    'planning'    => '規劃',
    'executing'   => '執行',
    'controlling' => '控制',
    'closing'     => '結案',
];

/** 標籤種類（自訂標籤，可按標籤篩選；名稱/顏色全部由使用者維護，不在別處寫死對照表＝鐵律4） */
const PRJ_TAG_KINDS = ['project' => '專案分類', 'goal' => '目標分類', 'task' => '任務分類'];

/** 專案內容欄位（§6.8 籌備階段的提案內容；每個欄位各有一組可自訂的常用語句） */
const PRJ_PHRASE_FIELDS = ['purpose' => '專案目的', 'goal_desc' => '專案目標'];

/** 文件檢核的四個項目（key => [顯示名稱, 頁面路徑]） */
const PRJ_DOC_CHECKS = [
    'dev_eval' => ['產品開發評估表',     '/EGsystem/views/TD/td_dev_eval.php'],
    'type_id'  => ['型態識別文件管制表', '/EGsystem/views/TD/type_id_ctrl_doc.php'],
    'pfmea'    => ['PFMEA',              '/EGsystem/views/TD/pfmea.php'],
    'ext_doc'  => ['外來文件清單',       '/EGsystem/views/Sales/external_doc_list.php'],
];

const PRJ_SETTING_GROUP = 'PROJECT_MGMT';

/* ══════════════════════════════ Schema ══════════════════════════════ */

function prj_ensure_schema(PDO $db): void
{
    static $done = false;
    if ($done) return;
    $done = true;

    $db->exec("CREATE TABLE IF NOT EXISTS project (
        project_id     INT AUTO_INCREMENT PRIMARY KEY,
        project_no     VARCHAR(20) NOT NULL COMMENT '專案代號 7碼：類型1+西元年後2+月2+流水2（例 C260801）',
        project_type   CHAR(1) NOT NULL DEFAULT 'C' COMMENT 'D開發/C客製/P生產/S服務',
        project_name   VARCHAR(200) NOT NULL,
        customer_id    VARCHAR(11) NULL COMMENT 'customer_list.customer_id',
        customer_name  VARCHAR(100) NULL COMMENT '建立當下快照（顯示一律即時查主檔）',
        owner_id       INT NULL COMMENT '專案負責人（＝程序書的專案主管）user.id',
        owner_name     VARCHAR(60) NULL,
        dept_id        INT NULL COMMENT '主辦部門',
        dept_name      VARCHAR(100) NULL,
        phase          VARCHAR(20) NOT NULL DEFAULT 'initiating' COMMENT 'initiating/planning/executing/controlling/closing',
        status         VARCHAR(20) NOT NULL DEFAULT 'draft' COMMENT 'draft/submitted/approved/rejected/closed/terminated',
        purpose        TEXT NULL COMMENT '專案目的',
        background     TEXT NULL COMMENT '專案時空背景',
        contribution   TEXT NULL COMMENT '對本公司貢獻',
        goal_desc      TEXT NULL COMMENT '專案目標（執行規劃表表頭）',
        plan_date      DATE NULL COMMENT '執行規劃表表頭日期（＝該表業務日期）',
        start_date     DATE NULL COMMENT '專案起日（甘特軸左界）',
        end_date       DATE NULL COMMENT '專案迄日（甘特軸右界）',
        budget         DECIMAL(14,2) NULL COMMENT '核定預算（程序書 §6.5）',
        tag_ids        VARCHAR(255) NULL COMMENT 'project_tag.tag_id 逗號串（kind=project）',
        note           TEXT NULL,
        submit_date    DATE NULL COMMENT '送簽業務日期（與 submitted_at 分離，ai-rules/21）',
        submitted_at   DATETIME NULL,
        approved_date  DATE NULL,
        approved_at    DATETIME NULL,
        approver_id    INT NULL, approver_name VARCHAR(60) NULL,
        decide_note    VARCHAR(500) NULL COMMENT '退回原因',
        is_auto        TINYINT NOT NULL DEFAULT 0 COMMENT '1=管理員批次自動簽核',
        close_date     DATE NULL,
        close_summary  TEXT NULL COMMENT '專案總結報告（程序書 §6.11.1 A）',
        source         VARCHAR(20) NOT NULL DEFAULT 'manual' COMMENT 'manual/order（訂單轉專案）',
        is_deleted     TINYINT NOT NULL DEFAULT 0,
        created_by     INT NULL, created_by_name VARCHAR(60) NULL, created_at DATETIME NULL,
        modified_by    INT NULL, modified_at DATETIME NULL,
        UNIQUE KEY uq_no (project_no),
        KEY idx_status (status), KEY idx_phase (phase), KEY idx_cust (customer_id)
    ) DEFAULT CHARSET=utf8mb4 COMMENT='專案主檔（2-GM-02 專案管理程序）'");

    $db->exec("CREATE TABLE IF NOT EXISTS project_order (
        id          INT AUTO_INCREMENT PRIMARY KEY,
        project_id  INT NOT NULL,
        order_id    INT NOT NULL COMMENT 'order_track.Order_id',
        added_by    VARCHAR(60) NULL, added_at DATETIME NULL,
        UNIQUE KEY uq_order (order_id) COMMENT '一張訂單只能屬於一個專案',
        KEY idx_prj (project_id)
    ) DEFAULT CHARSET=utf8mb4 COMMENT='專案綁定的訂單（主軸）'");

    $db->exec("CREATE TABLE IF NOT EXISTS project_part (
        id          INT AUTO_INCREMENT PRIMARY KEY,
        project_id  INT NOT NULL,
        ds_pk       INT NOT NULL COMMENT 'd_setting.d_id',
        part_no     VARCHAR(100) NULL COMMENT '建立當下快照',
        source      VARCHAR(10) NOT NULL DEFAULT 'order' COMMENT 'order=由訂單帶出 / manual=手動掛（尚無訂單的開發型專案）',
        note        VARCHAR(200) NULL,
        added_by    VARCHAR(60) NULL, added_at DATETIME NULL,
        UNIQUE KEY uq_item (project_id, ds_pk),
        KEY idx_ds (ds_pk)
    ) DEFAULT CHARSET=utf8mb4 COMMENT='專案料號（訂單自動帶出＋手動補掛）'");

    $db->exec("CREATE TABLE IF NOT EXISTS project_goal (
        goal_id     INT AUTO_INCREMENT PRIMARY KEY,
        project_id  INT NOT NULL,
        goal_name   VARCHAR(300) NOT NULL COMMENT '執行規劃表的「目標」',
        dept_id     INT NULL COMMENT '主辦單位',
        dept_name   VARCHAR(100) NULL,
        tag_ids     VARCHAR(255) NULL,
        sort_order  INT NOT NULL DEFAULT 0,
        KEY idx_prj (project_id)
    ) DEFAULT CHARSET=utf8mb4 COMMENT='專案目標（2-GM-02-02 表身分組）'");

    $db->exec("CREATE TABLE IF NOT EXISTS project_task (
        task_id      INT AUTO_INCREMENT PRIMARY KEY,
        project_id   INT NOT NULL,
        goal_id      INT NULL,
        task_name    VARCHAR(300) NOT NULL COMMENT '主要任務',
        plan_start   DATE NULL, plan_end DATE NULL COMMENT '預計（列印表的「預計」列）',
        act_start    DATE NULL, act_end  DATE NULL COMMENT '實際（列印表的「實際」列）',
        owner_id     INT NULL, owner_name VARCHAR(60) NULL COMMENT '負責人',
        owner_dept_id INT NULL COMMENT '負責人是以「哪個部門的身分」被指派（先選部門再選人；兼任者靠這欄決定顯示哪個職稱）',
        progress     TINYINT NOT NULL DEFAULT 0 COMMENT '完成百分比 0~100',
        progress_auto TINYINT NOT NULL DEFAULT 1 COMMENT '1=進度仍跟著實際完成日自動算，0=使用者手動改過就不再自動',
        is_milestone TINYINT NOT NULL DEFAULT 0 COMMENT '1=里程碑（甘特上畫菱形）',
        tag_ids      VARCHAR(255) NULL,
        note         VARCHAR(300) NULL,
        sort_order   INT NOT NULL DEFAULT 0,
        KEY idx_prj (project_id), KEY idx_goal (goal_id)
    ) DEFAULT CHARSET=utf8mb4 COMMENT='專案主要任務（2-GM-02-02 表身＋甘特來源）'");

    $db->exec("CREATE TABLE IF NOT EXISTS project_card (
        card_id      INT AUTO_INCREMENT PRIMARY KEY,
        project_id   INT NOT NULL,
        card_no      VARCHAR(30) NULL COMMENT '管理卡編號 專案代號-序號',
        review_date  DATE NOT NULL COMMENT '檢討日期＝該張卡的業務日期',
        status       VARCHAR(20) NOT NULL DEFAULT 'draft' COMMENT 'draft/submitted/approved',
        sign_approve_id INT NULL, sign_approve_name VARCHAR(60) NULL, sign_approve_date DATE NULL, sign_approve_dep TINYINT NOT NULL DEFAULT 0,
        sign_review_id  INT NULL, sign_review_name  VARCHAR(60) NULL, sign_review_date  DATE NULL, sign_review_dep  TINYINT NOT NULL DEFAULT 0,
        sign_maker_id   INT NULL, sign_maker_name   VARCHAR(60) NULL, sign_maker_date   DATE NULL, sign_maker_dep   TINYINT NOT NULL DEFAULT 0,
        is_auto      TINYINT NOT NULL DEFAULT 0,
        submit_date  DATE NULL, submitted_at DATETIME NULL,
        approved_date DATE NULL, approved_at DATETIME NULL,
        decide_note  VARCHAR(500) NULL,
        is_deleted   TINYINT NOT NULL DEFAULT 0,
        created_by   INT NULL, created_by_name VARCHAR(60) NULL, created_at DATETIME NULL,
        modified_by  INT NULL, modified_at DATETIME NULL,
        KEY idx_prj (project_id), KEY idx_date (review_date)
    ) DEFAULT CHARSET=utf8mb4 COMMENT='專案管理卡（2-GM-02-03，一專案多張、每次檢討一張）'");

    $db->exec("CREATE TABLE IF NOT EXISTS project_card_item (
        item_id     INT AUTO_INCREMENT PRIMARY KEY,
        card_id     INT NOT NULL,
        goal_id     INT NULL COMMENT '來源目標（自動帶入用）',
        goal_name   VARCHAR(300) NULL COMMENT '各項目標名稱（帶入當下快照，可覆寫）',
        dept_name   VARCHAR(100) NULL COMMENT '主辦單位',
        owner_name  VARCHAR(60) NULL COMMENT '承辦人',
        baseline    VARCHAR(500) NULL COMMENT '目前應達成基準（由甘特日程自動判定，可覆寫）',
        baseline_auto TINYINT NOT NULL DEFAULT 1 COMMENT '1=仍跟著甘特自動更新，0=使用者已覆寫',
        issue_text  TEXT NULL COMMENT '現階段問題',
        follow_text TEXT NULL COMMENT '後續辦理方法',
        note        VARCHAR(300) NULL,
        on_track    TINYINT NOT NULL DEFAULT 0 COMMENT '1=依計畫進行（一鍵標記，免填問題/辦法）',
        car_id      INT NULL COMMENT '轉開的異常矯正處理單 id（有問題時）',
        sort_order  INT NOT NULL DEFAULT 0,
        KEY idx_card (card_id)
    ) DEFAULT CHARSET=utf8mb4 COMMENT='專案管理卡項次'");

    $db->exec("CREATE TABLE IF NOT EXISTS project_cosign (
        id          INT AUTO_INCREMENT PRIMARY KEY,
        project_id  INT NOT NULL,
        dept_id     INT NULL, dept_name VARCHAR(100) NULL,
        user_id     INT NULL, user_name VARCHAR(60) NULL COMMENT '實際會簽人（送出時解析，含代理）',
        item_text   VARCHAR(300) NULL COMMENT '審查項目',
        result      VARCHAR(10) NULL COMMENT 'agree/disagree（要先選才能填意見）',
        opinion     VARCHAR(1000) NULL COMMENT '審查意見',
        signed_date DATE NULL, signed_at DATETIME NULL,
        is_delegate TINYINT NOT NULL DEFAULT 0 COMMENT '1=代理人代簽（圖章右下角加「代」）',
        is_auto     TINYINT NOT NULL DEFAULT 0,
        sort_order  INT NOT NULL DEFAULT 0,
        KEY idx_prj (project_id)
    ) DEFAULT CHARSET=utf8mb4 COMMENT='專案立案會簽（原 2-GM-02-01 的會簽單位表）'");

    $db->exec("CREATE TABLE IF NOT EXISTS project_process (
        id            INT AUTO_INCREMENT PRIMARY KEY,
        project_id    INT NOT NULL,
        ds_pk         INT NULL COMMENT '料號 d_setting.d_id',
        bom           VARCHAR(30) NOT NULL COMMENT 'bom.bom 製令單號',
        bom_ing_fid   INT NOT NULL DEFAULT 0 COMMENT 'bom_ing.bom_ing_fid（識別鍵）',
        bom_sn        INT NULL COMMENT '生產序號（10/20/30…＝加工順序）',
        process_no    INT NULL, process_name VARCHAR(60) NULL,
        maker_id_no   VARCHAR(11) NULL, maker_name VARCHAR(100) NULL,
        sqty          INT NULL,
        outsource_date DATE NULL, return_date DATE NULL,
        qc_check      VARCHAR(30) NULL,
        state         VARCHAR(10) NULL COMMENT 'bom_ing.processing_state',
        note          VARCHAR(300) NULL COMMENT '專案端自己的註記（同步不覆蓋）',
        is_milestone  TINYINT NOT NULL DEFAULT 0 COMMENT '專案端把這道製程設為里程碑',
        sig           VARCHAR(64) NULL COMMENT '同步比對指紋（欄位變動偵測用）',
        synced_at     DATETIME NULL,
        UNIQUE KEY uq_item (project_id, bom, bom_ing_fid),
        KEY idx_prj (project_id), KEY idx_bom (bom)
    ) DEFAULT CHARSET=utf8mb4 COMMENT='專案製程（由已開立 BOM 帶入，只讀不改 BOM 資料）'");

    $db->exec("CREATE TABLE IF NOT EXISTS project_bom_change (
        id          INT AUTO_INCREMENT PRIMARY KEY,
        project_id  INT NOT NULL,
        bom         VARCHAR(30) NULL,
        change_type VARCHAR(20) NOT NULL COMMENT 'bom_added/added/removed/changed',
        detail      VARCHAR(500) NULL,
        detected_at DATETIME NULL,
        acked_by    VARCHAR(60) NULL, acked_at DATETIME NULL,
        KEY idx_prj_ack (project_id, acked_at)
    ) DEFAULT CHARSET=utf8mb4 COMMENT='BOM 製程變更提示（專案管理人需知悉）'");

    $db->exec("CREATE TABLE IF NOT EXISTS project_tag (
        tag_id     INT AUTO_INCREMENT PRIMARY KEY,
        tag_kind   VARCHAR(20) NOT NULL DEFAULT 'project' COMMENT 'project/goal/task',
        tag_name   VARCHAR(60) NOT NULL,
        color      VARCHAR(20) NULL COMMENT '暖色系色碼（ai-rules/10）',
        sort_order INT NOT NULL DEFAULT 0,
        is_active  TINYINT NOT NULL DEFAULT 1,
        UNIQUE KEY uq_kind_name (tag_kind, tag_name)
    ) DEFAULT CHARSET=utf8mb4 COMMENT='專案自訂標籤'");

    // 常用語句（專案目的／專案目標）：使用者可自行新增、修改、刪除，填表時一鍵帶入。
    // 帶入的是「文字」不是 id，所以語句事後被改名或刪掉不影響任何既有專案（故可直接刪、不必比照標籤停用）。
    $phraseExisted = false;
    try { $phraseExisted = (bool)$db->query("SHOW TABLES LIKE 'project_phrase'")->fetchColumn(); } catch (Throwable $e) {}
    $db->exec("CREATE TABLE IF NOT EXISTS project_phrase (
        phrase_id   INT AUTO_INCREMENT PRIMARY KEY,
        field_key   VARCHAR(20) NOT NULL COMMENT 'purpose=專案目的 / goal_desc=專案目標',
        phrase_text VARCHAR(500) NOT NULL COMMENT '語句內容（帶入時直接複製文字）',
        sort_order  INT NOT NULL DEFAULT 0,
        created_by  VARCHAR(60) NULL, created_at DATETIME NULL,
        modified_by VARCHAR(60) NULL, modified_at DATETIME NULL,
        KEY idx_field (field_key, sort_order)
    ) DEFAULT CHARSET=utf8mb4 COMMENT='專案內容常用語句（可自訂，填表時帶入）'");
    // 預設語句只在「這張表是這次才建出來」時寫入；使用者事後刪掉不可以自己長回來
    if (!$phraseExisted) {
        $ins = $db->prepare("INSERT INTO project_phrase (field_key, phrase_text, sort_order, created_by, created_at)
                             VALUES (?,?,?, '系統預設', NOW())");
        $ins->execute(['purpose',   '因應客戶新產品開發需求，針對特定齒輪進行製程之試作與可行性驗證。', 10]);
        $ins->execute(['goal_desc', '順利完成該開發件之齒面精度、尺寸公差驗證。', 10]);
    }

    $db->exec("CREATE TABLE IF NOT EXISTS project_doc_pending (
        id          INT AUTO_INCREMENT PRIMARY KEY,
        target      VARCHAR(20) NOT NULL COMMENT 'dev_eval/type_id/pfmea/ext_doc',
        ds_pk       INT NOT NULL,
        project_id  INT NULL COMMENT '哪個專案偵測出來的',
        status      VARCHAR(10) NOT NULL DEFAULT 'pending' COMMENT 'pending/done/ignored',
        created_by  VARCHAR(60) NULL, created_at DATETIME NULL,
        UNIQUE KEY uq_item (target, ds_pk)
    ) DEFAULT CHARSET=utf8mb4 COMMENT='「有專案但未建立」的待建項目（供四頁偵測合併使用）'");

    // 既有資料庫補欄位（可重複執行）
    prj_ensure_col($db, 'project_task', 'owner_dept_id', "INT NULL COMMENT '負責人所屬部門（先選部門再選人）' AFTER owner_name");
    prj_ensure_col($db, 'project_task', 'progress_auto', "TINYINT NOT NULL DEFAULT 1 COMMENT '1=進度跟著實際完成日自動算' AFTER progress");

    // 角色（比照 pfmea_lib 慣例自動建立；名稱之後可在角色管理改，這裡只保證存在）
    foreach ([['project_view', '專案檢閱'], ['project_edit', '專案登錄'], ['project_admin', '專案管理員']] as $r) {
        $st = $db->prepare("SELECT 1 FROM roles WHERE role_code=? AND module='project' LIMIT 1");
        $st->execute([$r[0]]);
        if (!$st->fetchColumn()) {
            $db->prepare("INSERT INTO roles (role_code, role_name, module) VALUES (?,?, 'project')")
               ->execute([$r[0], $r[1]]);
        }
    }
}

/* ══════════════════════════ 使用者與權限 ══════════════════════════ */

function prj_current_user(PDO $db): ?array
{
    $uname = $_SESSION['userName'] ?? '';
    if ($uname === '') return null;
    $st = $db->prepare("SELECT id, user_cname, user_status FROM user WHERE user_uname=?");
    $st->execute([$uname]);
    return $st->fetch(PDO::FETCH_ASSOC) ?: null;
}

function prj_has_role(PDO $db, int $uid, array $codes): bool
{
    if (!$codes) return false;
    $in = implode(',', array_fill(0, count($codes), '?'));
    $st = $db->prepare("SELECT 1 FROM user_roles ur JOIN roles r ON r.role_id=ur.role_id
                        WHERE ur.user_id=? AND r.module='project' AND r.role_code IN ($in) LIMIT 1");
    $st->execute(array_merge([$uid], $codes));
    if ($st->fetchColumn()) return true;
    $st = $db->prepare("SELECT 1 FROM user_department_position_map m
                        JOIN position_roles pr ON pr.position_id=m.position_id
                        JOIN roles r ON r.role_id=pr.role_id
                        WHERE m.user_id=? AND r.module='project' AND r.role_code IN ($in) LIMIT 1");
    $st->execute(array_merge([$uid], $codes));
    return (bool)$st->fetchColumn();
}

/**
 * 權限四級：
 *   canView  專案檢閱   ＝看清單/明細/列印
 *   canEdit  專案登錄   ＝檢閱＋建立/編輯專案、規劃表、管理卡、訂單轉專案、同步 BOM
 *   canAdmin 專案管理員 ＝登錄＋刪除、標籤維護、AS 綁定、模組設定、批次自動簽核
 * 另外「專案負責人」是資料層身分（project.owner_id）：即使只有檢閱角色，也能編輯自己負責的專案。
 */
function prj_perms(PDO $db, ?array $u): array
{
    if (!$u) return ['isAdmin' => false, 'canAdmin' => false, 'canEdit' => false, 'canView' => false, 'uid' => 0, 'uname' => ''];
    $uid = (int)$u['id'];
    $isAdmin = in_array((int)$u['user_status'], [9, 90], true) || $uid === 1;
    if (!$isAdmin) {
        $st = $db->prepare("SELECT 1 FROM user_roles ur JOIN roles r ON r.role_id=ur.role_id
                            WHERE ur.user_id=? AND r.role_code='admin' AND r.is_system=1 LIMIT 1");
        $st->execute([$uid]);
        $isAdmin = (bool)$st->fetchColumn();
    }
    $canAdmin = $isAdmin || prj_has_role($db, $uid, ['project_admin']);
    $canEdit  = $canAdmin || prj_has_role($db, $uid, ['project_edit']);
    $canView  = $canEdit  || prj_has_role($db, $uid, ['project_view']);
    return ['isAdmin' => $isAdmin, 'canAdmin' => $canAdmin, 'canEdit' => $canEdit, 'canView' => $canView,
            'uid' => $uid, 'uname' => (string)($u['user_cname'] ?? '')];
}

/** 這個人能不能編輯這一筆專案（角色 or 本人是專案負責人） */
function prj_can_edit_project(array $perms, array $prj): bool
{
    if (!empty($perms['canEdit'])) return true;
    return !empty($perms['canView']) && (int)$prj['owner_id'] === (int)$perms['uid'] && (int)$prj['owner_id'] > 0;
}

/* ══════════════════════════ 基本工具 ══════════════════════════ */

/** DB 現在時間（PHP date() 是 UTC，一律取 DB 的，見 CLAUDE.md 踩坑紀錄） */
function prj_db_now(PDO $db): array
{
    $r = $db->query("SELECT NOW() AS dt, CURDATE() AS d")->fetch(PDO::FETCH_ASSOC);
    return ['dt' => $r['dt'], 'date' => $r['d']];
}

/** 本公司全名（列印大標題唯一來源，禁寫死＝ai-rules/16） */
function prj_company_name(PDO $db): string
{
    try {
        $n = $db->query("SELECT customer_full FROM customer_list WHERE is_own_company=1 LIMIT 1")->fetchColumn();
        if ($n) return (string)$n;
    } catch (Throwable $e) {
    }
    return '';
}

/**
 * 專案代號：類型1碼＋西元年後2碼＋月2碼＋流水2碼（程序書 §6.13，例 S170945）
 * 流水碼依「同一類型＋同一年月」遞增 01~99；業務日期優先用傳入日期（補歷史專案時編號才對得起來）。
 */
function prj_next_no(PDO $db, string $type, ?string $bizDate = null): string
{
    $type = strtoupper(substr(trim($type), 0, 1));
    if (!isset(PRJ_TYPES[$type])) $type = 'C';
    $d  = $bizDate ?: prj_db_now($db)['date'];
    $ts = strtotime((string)$d);
    if ($ts === false) $ts = strtotime(prj_db_now($db)['date']);
    $prefix = $type . date('ym', $ts);
    $st = $db->prepare("SELECT project_no FROM project WHERE project_no LIKE ? ORDER BY project_no DESC LIMIT 1");
    $st->execute([$prefix . '%']);
    $last = (string)$st->fetchColumn();
    $seq  = $last !== '' ? ((int)substr($last, 5, 2) + 1) : 1;
    if ($seq > 99) $seq = 99;   // 程序書定義流水碼 01~99；滿號要人工處理，不自動改長度
    return $prefix . str_pad((string)$seq, 2, '0', STR_PAD_LEFT);
}

/**
 * 模組設定讀寫（走 system_parameters，比照全站慣例）
 *
 * 兩個一定要照做的地方（都是實測踩出來的，寫錯不會有明顯症狀）：
 *  1. 欄位名是 param_group/param_key/param_value，不是 parameter_*。寫錯的話 get 會被 try/catch 吃掉
 *     而永遠回預設值、save 則直接 500，畫面上看起來就是「按儲存沒反應、設定永遠存不進去」。
 *  2. param_value 的欄位型別是 JSON（不是 varchar/text），所以不能塞 '' 或 '3,5' 這種非 JSON 字串，
 *     否則 MySQL 直接丟 3140 Invalid JSON text。這裡一律寫入時 json_encode、讀出時 json_decode，
 *     讓呼叫端維持原本的「值就是字串」語意，不必每個 key 各自處理。
 */
function prj_setting_get(PDO $db, string $key, string $default = ''): string
{
    try {
        $st = $db->prepare("SELECT param_value FROM system_parameters WHERE param_group=? AND param_key=?");
        $st->execute([PRJ_SETTING_GROUP, $key]);
        $v = $st->fetchColumn();
        if ($v === false || $v === null) return $default;
        // 正常情況存進去的是 json_encode 後的字串；decode 不出字串（別處寫進來的原始 JSON）就用原文
        $d = json_decode((string)$v, true);
        return is_string($d) ? $d : (is_scalar($d) ? (string)$d : (string)$v);
    } catch (Throwable $e) {
        return $default;
    }
}

function prj_setting_save(PDO $db, string $key, string $value, string $desc, string $by): void
{
    $st = $db->prepare("INSERT INTO system_parameters (param_group, param_key, param_value, description, updated_by, updated_at)
                        VALUES (?,?,?,?,?,NOW())
                        ON DUPLICATE KEY UPDATE param_value=VALUES(param_value),
                                                description=VALUES(description),
                                                updated_by=VALUES(updated_by), updated_at=NOW()");
    $st->execute([PRJ_SETTING_GROUP, $key, json_encode($value, JSON_UNESCAPED_UNICODE), $desc, $by]);
}

/* ══════════════════════════ 專案負責人資格（部門×職稱） ══════════════════════════ */
/*
 * 管理員可指定「哪些部門的哪些職稱」才能被指派為專案負責人（模組設定 → 專案負責人資格）。
 * 設定值存 system_parameters(PROJECT_MGMT/owner_scope)，格式 JSON：[{"d":部門id,"p":職稱id}, …]，
 * p=0 代表該部門「全部職稱」。一列都沒設＝不限制（全體在職員工皆可），維持既有行為不動既有資料。
 * 一個人可能兼任多個部門/職稱（user_department_position_map 有多列），只要任一組合命中就算合格。
 */

/** 讀出設定，回傳 [['d'=>int,'p'=>int], …]（已去重、已濾掉不合法值） */
function prj_owner_scope(PDO $db): array
{
    $raw = prj_setting_get($db, 'owner_scope', '');
    return prj_owner_scope_parse($raw);
}

/** 把設定字串解析成乾淨的組合清單（存檔前也用同一支，前後端同規則＝鐵律8） */
function prj_owner_scope_parse(string $raw): array
{
    $arr = json_decode($raw, true);
    if (!is_array($arr)) return [];
    $out = [];
    foreach ($arr as $row) {
        if (!is_array($row)) continue;
        $d = (int)($row['d'] ?? 0);
        $pp = (int)($row['p'] ?? 0);
        if ($d <= 0) continue;                       // 一定要指定部門；職稱可以是 0（全部職稱）
        $out[$d . ':' . $pp] = ['d' => $d, 'p' => $pp];
    }
    // 同一部門若已設「全部職稱」，個別職稱那幾列就是多餘的，收斂掉避免清單越積越長
    foreach ($out as $k => $v) {
        if ($v['p'] !== 0 && isset($out[$v['d'] . ':0'])) unset($out[$k]);
    }
    return array_values($out);
}

/** 依設定算出合格的 user.id 清單；未設定時回 null＝不限制 */
function prj_owner_scope_user_ids(PDO $db): ?array
{
    $scope = prj_owner_scope($db);
    if (!$scope) return null;
    $cond = []; $p = [];
    foreach ($scope as $sc) {
        if ($sc['p'] === 0) { $cond[] = '(m.department_id=?)';                    $p[] = $sc['d']; }
        else                { $cond[] = '(m.department_id=? AND m.position_id=?)'; array_push($p, $sc['d'], $sc['p']); }
    }
    try {
        $st = $db->prepare("SELECT DISTINCT m.user_id FROM user_department_position_map m
                            WHERE " . implode(' OR ', $cond));
        $st->execute($p);
        return array_map('intval', $st->fetchAll(PDO::FETCH_COLUMN));
    } catch (Throwable $e) {
        return null;   // 查不出來時寧可不限制，也不要讓整個負責人下拉變空的
    }
}

/**
 * 某人所屬的部門（含兼任），並展開到各部門的子部門。
 * 組織是樹狀的，「資材部的人」在挑人時理應看得到生管／採購／倉管組——
 * 只比單一 id 會把子部門的人判成「不是同部門」（CLAUDE.md 組織綁定那條）。
 */
function prj_user_dept_ids_tree(PDO $db, int $userId): array
{
    if ($userId <= 0) return [];
    try {
        $st = $db->prepare("SELECT DISTINCT department_id FROM user_department_position_map WHERE user_id=?");
        $st->execute([$userId]);
        $own = array_map('intval', $st->fetchAll(PDO::FETCH_COLUMN));
    } catch (Throwable $e) { return []; }
    $out = [];
    foreach ($own as $d) {
        foreach (prj_dept_tree_ids($db, $d) as $x) $out[$x] = true;
    }
    return array_keys($out);
}

/**
 * 可被指派為專案負責人的人員清單（人員本身一律走 people_lib＝ai-rules/08 第五節）。
 *
 * 兩層限制疊起來：
 *  ① 管理員設定的「專案負責人資格」（部門×職稱），未設定＝不限制。
 *  ② 非專案管理員只能挑**自己所屬部門（含兼任，並含各該部門的子部門）內的人**
 *     （2026-08-21 使用者要求）；專案管理員／管理者不受此限，可指派任何人。
 *
 * @param array    $keepIds 即使不合資格也要保留的人（既有專案目前的負責人），
 *                          否則設定改嚴之後，既有專案一打開負責人就變空白、一存檔就被洗掉。
 * @param int|null $actorId 目前操作者；null＝不套用第②層（純粹問「資格」是誰）
 * @param bool     $isAdmin 目前操作者是不是專案管理員
 */
function prj_owner_people(PDO $db, array $keepIds = [], ?int $actorId = null, bool $isAdmin = false): array
{
    $ids  = prj_owner_scope_user_ids($db);                       // null＝資格不限制
    $keep = array_values(array_filter(array_map('intval', $keepIds)));

    if ($actorId !== null && !$isAdmin) {
        $depts = prj_user_dept_ids_tree($db, $actorId);
        if (!$depts) {
            // 沒掛任何部門的人（多半是組織資料未維護）：至少讓他把專案掛在自己名下，不要整個卡死。
            // 「資格」那一層仍然要過，否則這裡列得出來、後端 prj_owner_allowed() 卻擋下來。
            $mine = [$actorId];
            if ($ids !== null && !in_array($actorId, $ids, true)) $mine = [];
            $mine = array_values(array_unique(array_merge($mine, $keep)));
            return $mine ? eg_people_list($db, ['user_ids' => $mine]) : [];
        }
        $rows = eg_people_list($db, ['dept_ids' => $depts]);
        if ($ids !== null) {
            $allow = array_flip(array_merge($ids, $keep));
            $rows = array_values(array_filter($rows, static fn($r) => isset($allow[(int)$r['id']])));
        }
        // 既有專案的負責人若不在自己部門，仍要保留在清單裡（不然一存檔就被洗掉）
        $have = array_flip(array_map(static fn($r) => (int)$r['id'], $rows));
        $add  = array_values(array_filter($keep, static fn($k) => !isset($have[$k])));
        if ($add) $rows = array_merge($rows, eg_people_list($db, ['user_ids' => $add]));
        return $rows;
    }

    if ($ids === null) return eg_people_list($db, []);
    $all = array_values(array_unique(array_merge($ids, $keep)));
    return $all ? eg_people_list($db, ['user_ids' => $all]) : [];
}

/**
 * 這個人現在合不合資格當專案負責人（後端存檔時再驗一次＝鐵律8）。
 * $actorId/$isAdmin 傳進來時會一併檢查「非管理員只能挑自己部門的人」那一層。
 */
function prj_owner_allowed(PDO $db, int $userId, ?int $actorId = null, bool $isAdmin = false): bool
{
    if ($userId <= 0) return false;
    $ids = prj_owner_scope_user_ids($db);
    if ($ids !== null && !in_array($userId, $ids, true)) return false;
    if ($actorId === null || $isAdmin) return true;
    if ($userId === $actorId) return true;                       // 一定可以把專案掛在自己名下
    $depts = prj_user_dept_ids_tree($db, $actorId);
    if (!$depts) return false;
    try {
        $in = implode(',', array_fill(0, count($depts), '?'));
        $st = $db->prepare("SELECT 1 FROM user_department_position_map
                            WHERE user_id=? AND department_id IN ($in) LIMIT 1");
        $st->execute(array_merge([$userId], $depts));
        return (bool)$st->fetchColumn();
    } catch (Throwable $e) { return false; }
}

/** 設定畫面用：把組合清單轉成看得懂的字（部門名／職稱名） */
function prj_owner_scope_labeled(PDO $db): array
{
    $scope = prj_owner_scope($db);
    if (!$scope) return [];
    $dept = []; $pos = [];
    try { foreach ($db->query("SELECT id, name FROM department")->fetchAll(PDO::FETCH_ASSOC) as $r) $dept[(int)$r['id']] = $r['name']; } catch (Throwable $e) {}
    try { foreach ($db->query("SELECT id, name FROM position")->fetchAll(PDO::FETCH_ASSOC) as $r) $pos[(int)$r['id']] = $r['name']; } catch (Throwable $e) {}
    $out = [];
    foreach ($scope as $sc) {
        $out[] = [
            'd'         => $sc['d'],
            'p'         => $sc['p'],
            'dept_name' => $dept[$sc['d']] ?? ('（已刪除的部門 #' . $sc['d'] . '）'),
            'pos_name'  => $sc['p'] === 0 ? '全部職稱' : ($pos[$sc['p']] ?? ('（已刪除的職稱 #' . $sc['p'] . '）')),
        ];
    }
    return $out;
}

/* ══════════════════════════ 標籤 ══════════════════════════ */

function prj_tags_all(PDO $db, ?string $kind = null, bool $activeOnly = true): array
{
    $w = [];
    $p = [];
    if ($kind)       { $w[] = 'tag_kind=?';  $p[] = $kind; }
    if ($activeOnly) { $w[] = 'is_active=1'; }
    $sql = "SELECT tag_id, tag_kind, tag_name, color, sort_order, is_active FROM project_tag";
    if ($w) $sql .= ' WHERE ' . implode(' AND ', $w);
    $sql .= ' ORDER BY tag_kind, sort_order, tag_name';
    $st = $db->prepare($sql);
    $st->execute($p);
    return $st->fetchAll(PDO::FETCH_ASSOC);
}

/** 逗號串 → id 陣列（唯一解析點，避免各處自己 explode 出錯） */
/** 補欄位（欄位已存在就跳過；DDL 會隱式 commit，故一律在 transaction 之外呼叫） */
function prj_ensure_col(PDO $db, string $table, string $col, string $ddl): void
{
    try {
        $st = $db->prepare("SHOW COLUMNS FROM `$table` LIKE ?");
        $st->execute([$col]);
        if (!$st->fetchColumn()) $db->exec("ALTER TABLE `$table` ADD COLUMN `$col` $ddl");
    } catch (Throwable $e) { /* 表還不存在時交給 CREATE TABLE 處理 */ }
}

/**
 * 執行規劃表「負責人」可挑選的部門（模組設定，複選；空陣列＝不限制）。
 * 只存管理員實際勾選的那幾個 id，子部門在使用時才展開（部門樹會變動，展開結果不存檔）。
 */
function prj_task_owner_depts(PDO $db): array
{
    $raw = prj_setting_get($db, 'task_owner_depts', '');
    $out = [];
    foreach (explode(',', $raw) as $v) { $v = (int)trim($v); if ($v > 0) $out[] = $v; }
    return array_values(array_unique($out));
}

/** 部門 id 陣列 → 含所有子孫部門的 id 陣列（組織是樹狀的，只比單一 id 會漏掉底下的組） */
function prj_dept_subtree(PDO $db, array $ids): array
{
    $ids = array_values(array_unique(array_filter(array_map('intval', $ids))));
    if (!$ids) return [];
    static $childMap = null;
    if ($childMap === null) {
        $childMap = [];
        try {
            foreach ($db->query("SELECT id, parent_id FROM department")->fetchAll(PDO::FETCH_ASSOC) as $r) {
                $childMap[(int)$r['parent_id']][] = (int)$r['id'];
            }
        } catch (Throwable $e) { $childMap = []; }
    }
    $out = [];
    $queue = $ids;
    $guard = 0;
    while ($queue && $guard++ < 5000) {
        $cur = array_shift($queue);
        if (isset($out[$cur])) continue;
        $out[$cur] = true;
        foreach ($childMap[$cur] ?? [] as $c) $queue[] = $c;
    }
    return array_map('intval', array_keys($out));
}

/**
 * 每個人「所有」的部門×職稱（兼任者會有多列）。
 * eg_people_list() 一人只回一列（職級最高那筆），所以兼任的另一個職務在下拉裡看不到；
 * 「先選部門再選人」要靠這份才能把人列在正確的部門底下、並顯示該部門的職稱。
 * $userIds 一律傳 eg_people_list() 回來的 id（在職判定、離職排除都以那支為準，不在這裡另寫一套）。
 */
function prj_people_posts(PDO $db, array $userIds): array
{
    $userIds = array_values(array_unique(array_filter(array_map('intval', $userIds))));
    if (!$userIds) return [];
    $in = implode(',', $userIds);
    $sql = "SELECT m.user_id, m.department_id AS dept_id, d.name AS dept_name,
                   COALESCE(d.sort_order, 999) AS dept_sort,
                   m.position_id, p.name AS position_name, COALESCE(p.sort_order, 999) AS position_sort,
                   m.is_main
            FROM user_department_position_map m
            LEFT JOIN department d ON d.id = m.department_id
            LEFT JOIN position   p ON p.id = m.position_id
            WHERE m.user_id IN ($in)
            ORDER BY dept_sort, position_sort, m.user_id";
    $rows = [];
    try { $rows = $db->query($sql)->fetchAll(PDO::FETCH_ASSOC); } catch (Throwable $e) { return []; }
    foreach ($rows as &$r) {
        $r['user_id']       = (int)$r['user_id'];
        $r['dept_id']       = (int)$r['dept_id'];
        $r['dept_sort']     = (int)$r['dept_sort'];
        $r['position_id']   = $r['position_id'] === null ? null : (int)$r['position_id'];
        $r['position_sort'] = (int)$r['position_sort'];
        $r['is_main']       = (int)$r['is_main'];
        $r['dept_name']     = $r['dept_name'] ?: '';
        $r['position_name'] = $r['position_name'] ?: '';
    }
    unset($r);
    return $rows;
}

/* ══════════════════════ 預計日程的「工作天數」 ══════════════════════
   定義（執行規劃表專用，兩個方向都以這裡為準）：
     預計開始當天算第 1 天，之後只算工作日；週六日與休假日(s)不算、補班日(m)算。
     所以「工作天數 1」＝當天來回，預計完成日就等於預計開始日。
   行事曆一律走 car_holiday_sets()（＝views/pages/calendar.php 的 evenement＋event_category.day_type），
   不可改用 calendar_workday（該表有誤）。前端有一份同規則的即時計算，判定資料由 meta 的 workday 帶下去，
   避免兩邊各自維護一份行事曆。 */

/** 預計開始 + 工作天數 → 預計完成日（days<=1 時回傳開始日本身） */
function prj_plan_end_by_days(PDO $db, string $start, int $days): string
{
    $cur = strtotime(substr($start, 0, 10));
    if ($cur === false) return substr($start, 0, 10);
    $left = $days - 1;                       // 開始日已經算第 1 天
    if ($left <= 0) return date('Y-m-d', $cur);
    $sets = car_holiday_sets($db);
    $guard = 0;
    while ($left > 0 && $guard++ < 4000) {
        $cur = strtotime('+1 day', $cur);
        $key = date('Y-m-d', $cur);
        $dow = (int)date('w', $cur);
        $isWeekend = ($dow === 0 || $dow === 6);
        if (isset($sets['makeups'][$key]) || (!$isWeekend && !isset($sets['holidays'][$key]))) $left--;
    }
    return date('Y-m-d', $cur);
}

/** 預計開始～預計完成 → 工作天數（同一天＝1；完成早於開始＝0，代表算不出來） */
function prj_plan_days(PDO $db, string $start, string $end): int
{
    $s = substr($start, 0, 10);
    $e = substr($end, 0, 10);
    if ($s === '' || $e === '' || $e < $s) return 0;
    return car_working_days_between($db, $s, $e) + 1;   // 開始日本身算 1 天
}

/** 給前端即時計算用的行事曆（休假日／補班日；資料量只有幾十筆，隨 meta 帶下去即可） */
function prj_workday_sets(PDO $db): array
{
    $sets = car_holiday_sets($db);
    return [
        'holidays' => array_keys($sets['holidays']),
        'makeups'  => array_keys($sets['makeups']),
    ];
}

/**
 * 任務進度自動判定（使用者拍板 2026-08-26）：
 *   還跟著自動（progress_auto=1）時 ── 填了實際完成日＝100%，否則 0%。
 *   使用者一旦自己動手改過進度，progress_auto 就變 0、之後一律以他填的為準（比照管理卡的「目前應達成基準」）。
 * 前端有一份同規則的即時計算，存檔時後端再算一次，不採信前端送來的數字（鐵律8）。
 */
function prj_task_progress_auto(array $t): int
{
    return trim((string)($t['act_end'] ?? '')) !== '' ? 100 : 0;
}

/**
 * 專案整體進度＝各任務進度依「預計工作天數」加權平均（使用者拍板）。
 * 用簡單平均的話，20 天的大任務跟 1 天的小任務權重一樣，做完大任務只前進 50% 不合現場認知。
 * 沒填預計起迄日的任務權重算 1 天（否則整條會被沒排日程的任務洗掉）。
 */
function prj_progress(PDO $db, int $projectId): int
{
    $st = $db->prepare("SELECT progress, plan_start, plan_end FROM project_task WHERE project_id=?");
    $st->execute([$projectId]);
    $rows = $st->fetchAll(PDO::FETCH_ASSOC);
    if (!$rows) return 0;
    $sum = 0.0; $w = 0.0;
    foreach ($rows as $r) {
        $d = prj_plan_days($db, (string)($r['plan_start'] ?? ''), (string)($r['plan_end'] ?? ''));
        if ($d <= 0) $d = 1;
        $sum += (float)$r['progress'] * $d;
        $w   += $d;
    }
    return $w > 0 ? (int)round($sum / $w) : 0;
}

/**
 * 「實際開始／實際完成」現在可不可以填＝專案立案核准之後才可以（使用者拍板 2026-08-25）。
 * 還在草稿／送簽中／被退回的專案只排預計日程，實際日期留到核准後執行時才填。
 */
function prj_act_dates_open(?array $prj): bool
{
    return in_array((string)($prj['status'] ?? ''), ['approved', 'closed'], true);
}

/** 常用語句清單（field 為 null＝全部欄位一起回） */
function prj_phrases_all(PDO $db, ?string $field = null): array
{
    if ($field !== null && !isset(PRJ_PHRASE_FIELDS[$field])) return [];
    $sql = "SELECT phrase_id, field_key, phrase_text, sort_order FROM project_phrase";
    $arg = [];
    if ($field !== null) { $sql .= " WHERE field_key=?"; $arg[] = $field; }
    $sql .= " ORDER BY field_key, sort_order, phrase_id";
    $st = $db->prepare($sql);
    $st->execute($arg);
    return $st->fetchAll(PDO::FETCH_ASSOC);
}

function prj_tag_ids(?string $csv): array
{
    if (!$csv) return [];
    return array_values(array_unique(array_filter(array_map('intval', explode(',', $csv)))));
}

function prj_tag_csv(array $ids): string
{
    $ids = array_values(array_unique(array_filter(array_map('intval', $ids))));
    return $ids ? implode(',', $ids) : '';
}

/* ══════════════════════════ 專案主檔 ══════════════════════════ */

/** 清單查詢（篩選/搜尋/標籤全部在後端算，總計不可只用前端那一頁＝ai-rules/08） */
function prj_list(PDO $db, array $q): array
{
    $w = ['p.is_deleted=0'];
    $p = [];
    if (!empty($q['status'])) { $w[] = 'p.status=?';       $p[] = $q['status']; }
    if (!empty($q['phase']))  { $w[] = 'p.phase=?';        $p[] = $q['phase']; }
    if (!empty($q['type']))   { $w[] = 'p.project_type=?'; $p[] = $q['type']; }
    if (!empty($q['owner']))  { $w[] = 'p.owner_id=?';     $p[] = (int)$q['owner']; }
    if (!empty($q['from']))   { $w[] = '(p.end_date IS NULL OR p.end_date>=?)';     $p[] = $q['from']; }
    if (!empty($q['to']))     { $w[] = '(p.start_date IS NULL OR p.start_date<=?)'; $p[] = $q['to']; }
    if (!empty($q['kw'])) {
        // 全表搜尋一律 LIKE，禁用 ngram FULLTEXT（料號含「-」會比對不到，見 CLAUDE.md）
        foreach (preg_split('/\s+/', trim((string)$q['kw'])) as $t) {
            if ($t === '') continue;
            $w[] = "(p.project_no LIKE ? OR p.project_name LIKE ? OR p.customer_name LIKE ?
                     OR p.owner_name LIKE ? OR p.goal_desc LIKE ? OR p.purpose LIKE ?
                     OR EXISTS(SELECT 1 FROM project_part pp WHERE pp.project_id=p.project_id AND pp.part_no LIKE ?)
                     OR EXISTS(SELECT 1 FROM project_order po JOIN order_track o ON o.Order_id=po.order_id
                               WHERE po.project_id=p.project_id AND (o.Order_oo LIKE ? OR o.C_order LIKE ?)))";
            $like = '%' . $t . '%';
            array_push($p, $like, $like, $like, $like, $like, $like, $like, $like, $like);
        }
    }
    $tagIds = prj_tag_ids($q['tags'] ?? '');
    if ($tagIds) {
        // 標籤篩選＝命中任一個（OR）；標籤存逗號串故用 FIND_IN_SET
        $or = [];
        foreach ($tagIds as $t) { $or[] = 'FIND_IN_SET(?, p.tag_ids)'; $p[] = $t; }
        $w[] = '(' . implode(' OR ', $or) . ')';
    }
    $sql = "SELECT p.*,
                   (SELECT COUNT(*) FROM project_order po WHERE po.project_id=p.project_id) AS order_cnt,
                   (SELECT COUNT(*) FROM project_part  pp WHERE pp.project_id=p.project_id) AS part_cnt,
                   (SELECT COUNT(*) FROM project_task  pt WHERE pt.project_id=p.project_id) AS task_cnt,
                   (SELECT COUNT(*) FROM project_card  pc WHERE pc.project_id=p.project_id AND pc.is_deleted=0) AS card_cnt,
                   (SELECT COUNT(*) FROM project_bom_change pb WHERE pb.project_id=p.project_id AND pb.acked_at IS NULL) AS bom_alert_cnt
            FROM project p
            WHERE " . implode(' AND ', $w) . "
            ORDER BY p.project_no DESC";
    $st = $db->prepare($sql);
    $st->execute($p);
    $rows = $st->fetchAll(PDO::FETCH_ASSOC);
    foreach ($rows as &$r) {
        $r['type_label']  = PRJ_TYPES[$r['project_type']] ?? '';
        $r['phase_label'] = PRJ_PHASES[$r['phase']] ?? '';
        $r['progress']    = prj_progress($db, (int)$r['project_id']);
    }
    unset($r);
    return $rows;
}

/** 專案整體進度％＝各任務進度的簡單平均（沒有任務時回 0，不猜） */
function prj_get(PDO $db, int $projectId): ?array
{
    $st = $db->prepare("SELECT * FROM project WHERE project_id=? AND is_deleted=0");
    $st->execute([$projectId]);
    $r = $st->fetch(PDO::FETCH_ASSOC);
    if (!$r) return null;
    $r['type_label']  = PRJ_TYPES[$r['project_type']] ?? '';
    $r['phase_label'] = PRJ_PHASES[$r['phase']] ?? '';
    $r['progress']    = prj_progress($db, $projectId);
    return $r;
}

/** 必填檢查（前端即時擋＋後端同規則再擋一次＝鐵律8，不做半套） */
function prj_validate(array $d, array $tasks = []): array
{
    $err = [];
    if (trim((string)($d['project_name'] ?? '')) === '') $err['project_name'] = '請填專案名稱';
    if (!isset(PRJ_TYPES[(string)($d['project_type'] ?? '')])) $err['project_type'] = '請選擇專案類型';
    if ((int)($d['owner_id'] ?? 0) <= 0) $err['owner_id'] = '請選擇專案負責人';
    $s = trim((string)($d['start_date'] ?? ''));
    $e = trim((string)($d['end_date'] ?? ''));
    if ($s !== '' && $e !== '' && $s > $e) $err['end_date'] = '專案迄日不可早於起日';
    foreach ($tasks as $i => $t) {
        $n  = $i + 1;
        $ps = trim((string)($t['plan_start'] ?? ''));
        $pe = trim((string)($t['plan_end'] ?? ''));
        if ($ps !== '' && $pe !== '' && $ps > $pe) $err['task_' . $i] = '第 ' . $n . ' 項任務：預計完成日不可早於預計開始日';
        // 任務排在專案開始之前是不合理的日程（使用者要求，2026-08-26）
        if ($ps !== '' && $s !== '' && $ps < $s) {
            $err['task_' . $i] = '第 ' . $n . ' 項任務：預計開始日（' . eg_fmt_date($ps) . '）不可早於專案起日（' . eg_fmt_date($s) . '）';
        }
        $as = trim((string)($t['act_start'] ?? ''));
        $ae = trim((string)($t['act_end'] ?? ''));
        if ($as !== '' && $ae !== '' && $as > $ae) $err['task_' . $i] = '第 ' . $n . ' 項任務：實際完成日不可早於實際開始日';
    }
    return $err;
}

/* ══════════════════════════ 訂單／料號 ══════════════════════════ */

/** 專案的訂單（含目前狀態，供關聯資料分頁與檢核使用） */
function prj_orders(PDO $db, int $projectId): array
{
    $st = $db->prepare("SELECT po.id AS link_id, o.Order_id, o.Order_oo, o.C_order, o.d_id AS part_no,
                               o.d_id_ID AS ds_pk, o.Client_name, o.Client_name_ID, o.Qty,
                               o.Order_date, o.Delivery_date, o.Order_status, o.Processing_items,
                               o.quote_no, o.unit_price, ds.D_Setting_Id AS master_part_no
                        FROM project_order po
                        JOIN order_track o ON o.Order_id=po.order_id
                        LEFT JOIN d_setting ds ON ds.d_id=o.d_id_ID
                        WHERE po.project_id=?
                        ORDER BY o.Order_date DESC, o.Order_id DESC");
    $st->execute([$projectId]);
    $rows = $st->fetchAll(PDO::FETCH_ASSOC);
    foreach ($rows as &$r) {
        $s = (int)$r['Order_status'];
        $r['status_label'] = $s === 9 ? '已結束' : ($s === 6 ? '暫停' : '進行中');
    }
    unset($r);
    return $rows;
}

/** 專案料號（source: order＝由訂單帶出、manual＝手動補掛） */
function prj_parts(PDO $db, int $projectId): array
{
    $st = $db->prepare("SELECT pp.id AS link_id, pp.ds_pk, pp.source, pp.note,
                               COALESCE(ds.D_Setting_Id, pp.part_no) AS part_no,
                               ds.Drawing_No, ds.Spec_No, ds.Revision, ds.Is_Assembly,
                               ds.Customer_Id, COALESCE(c.customer, '') AS customer_name
                        FROM project_part pp
                        LEFT JOIN d_setting ds ON ds.d_id=pp.ds_pk
                        LEFT JOIN customer_list c ON c.customer_id=ds.Customer_Id
                        WHERE pp.project_id=?
                        ORDER BY part_no");
    $st->execute([$projectId]);
    return $st->fetchAll(PDO::FETCH_ASSOC);
}

/**
 * 把訂單帶出的料號同步進 project_part。
 * 手動掛的（source=manual）永遠不動；由訂單帶進來但訂單已被移出專案的才退場。
 */
function prj_sync_parts_from_orders(PDO $db, int $projectId, string $by): void
{
    $st = $db->prepare("SELECT DISTINCT o.d_id_ID AS ds_pk, o.d_id AS part_no
                        FROM project_order po JOIN order_track o ON o.Order_id=po.order_id
                        WHERE po.project_id=? AND o.d_id_ID IS NOT NULL AND o.d_id_ID>0");
    $st->execute([$projectId]);
    $want = [];
    foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $r) $want[(int)$r['ds_pk']] = (string)$r['part_no'];

    $ins = $db->prepare("INSERT INTO project_part (project_id, ds_pk, part_no, source, added_by, added_at)
                         VALUES (?,?,?,'order',?,NOW())
                         ON DUPLICATE KEY UPDATE part_no=VALUES(part_no)");
    foreach ($want as $dsPk => $partNo) $ins->execute([$projectId, $dsPk, $partNo, $by]);

    $del = $db->prepare("DELETE FROM project_part
                         WHERE project_id=? AND source='order'
                           AND ds_pk NOT IN (SELECT ds_pk FROM (
                                 SELECT DISTINCT o.d_id_ID AS ds_pk FROM project_order po
                                 JOIN order_track o ON o.Order_id=po.order_id
                                 WHERE po.project_id=? AND o.d_id_ID IS NOT NULL AND o.d_id_ID>0) t)");
    $del->execute([$projectId, $projectId]);
}

/** 這些訂單目前已被哪個專案綁走（轉專案時擋重複並說明在哪，回 [order_id => 專案]） */
function prj_orders_taken(PDO $db, array $orderIds): array
{
    $orderIds = array_values(array_filter(array_map('intval', $orderIds)));
    if (!$orderIds) return [];
    $in = implode(',', array_fill(0, count($orderIds), '?'));
    $st = $db->prepare("SELECT po.order_id, p.project_id, p.project_no, p.project_name
                        FROM project_order po JOIN project p ON p.project_id=po.project_id
                        WHERE po.order_id IN ($in) AND p.is_deleted=0");
    $st->execute($orderIds);
    $out = [];
    foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $r) $out[(int)$r['order_id']] = $r;
    return $out;
}

/** 可轉專案的訂單候選（尚未被任何專案綁走者） */
function prj_order_candidates(PDO $db, array $q): array
{
    $w = ["NOT EXISTS(SELECT 1 FROM project_order po JOIN project p2 ON p2.project_id=po.project_id
                      WHERE po.order_id=o.Order_id AND p2.is_deleted=0)"];
    $p = [];
    // 客戶：模糊比對「客戶ID 或 客戶名稱」（使用者要求，不再提供下拉選單）。
    // 訂單上的 Client_name 是建單當下的文字，客戶主檔改過名的舊訂單只靠它會找不到，
    // 所以另外以 Client_name_ID 反查 customer_list 的簡稱與發票全名一起比對。
    // 多個關鍵字以空白分隔＝每個都要命中（可分散在不同欄位），比照全表搜尋鐵則。
    if (trim((string)($q['cust'] ?? '')) !== '') {
        foreach (preg_split('/\s+/', trim((string)$q['cust'])) as $ct) {
            if ($ct === '') continue;
            $w[] = "(o.Client_name_ID LIKE ? OR o.Client_name LIKE ?
                     OR EXISTS(SELECT 1 FROM customer_list cl WHERE cl.customer_id=o.Client_name_ID
                               AND (cl.customer LIKE ? OR cl.customer_full LIKE ?)))";
            $cl = '%' . $ct . '%';
            array_push($p, $cl, $cl, $cl, $cl);
        }
    }
    if (!empty($q['from'])) { $w[] = 'o.Order_date>=?';    $p[] = $q['from']; }
    if (!empty($q['to']))   { $w[] = 'o.Order_date<=?';    $p[] = $q['to']; }
    if (empty($q['include_closed'])) $w[] = '(o.Order_status IS NULL OR o.Order_status<>9)';
    if (!empty($q['kw'])) {
        foreach (preg_split('/\s+/', trim((string)$q['kw'])) as $t) {
            if ($t === '') continue;
            $w[] = "(o.Order_oo LIKE ? OR o.C_order LIKE ? OR o.d_id LIKE ? OR o.Client_name LIKE ?
                     OR o.Specification LIKE ? OR o.Order_ps LIKE ? OR o.Processing_items LIKE ?)";
            $like = '%' . $t . '%';
            array_push($p, $like, $like, $like, $like, $like, $like, $like);
        }
    }
    $sql = "SELECT o.Order_id, o.Order_oo, o.C_order, o.d_id AS part_no, o.d_id_ID AS ds_pk,
                   o.Client_name, o.Client_name_ID, o.Qty, o.Order_date, o.Delivery_date,
                   o.Processing_items, o.Order_status
            FROM order_track o
            WHERE " . implode(' AND ', $w) . "
            ORDER BY o.Order_date DESC, o.Order_id DESC
            LIMIT 500";
    $st = $db->prepare($sql);
    $st->execute($p);
    return $st->fetchAll(PDO::FETCH_ASSOC);
}

/* ══════════════════════════ 目標與任務（2-GM-02-02） ══════════════════════════ */

function prj_goals(PDO $db, int $projectId): array
{
    $st = $db->prepare("SELECT * FROM project_goal WHERE project_id=? ORDER BY sort_order, goal_id");
    $st->execute([$projectId]);
    return $st->fetchAll(PDO::FETCH_ASSOC);
}

function prj_tasks(PDO $db, int $projectId): array
{
    $st = $db->prepare("SELECT t.*, g.goal_name, g.dept_name AS goal_dept_name
                        FROM project_task t
                        LEFT JOIN project_goal g ON g.goal_id=t.goal_id
                        WHERE t.project_id=?
                        ORDER BY g.sort_order, t.goal_id, t.sort_order, t.task_id");
    $st->execute([$projectId]);
    return $st->fetchAll(PDO::FETCH_ASSOC);
}

/**
 * 單一任務相對於某個業務日期的狀態。
 * done=已完成／overdue=逾期未完成／doing=進行中／pending=未開始／noplan=沒排日程
 */
function prj_task_state(array $t, string $asof): string
{
    $pe = (string)($t['plan_end'] ?? '');
    $ps = (string)($t['plan_start'] ?? '');
    $ae = (string)($t['act_end'] ?? '');
    // 有填實際完成日時一律由日期決定：回推過去日期時不可拿「目前的進度％」當已完成
    // （progress 是現況欄位，補開/回看舊卡片會把當時還沒做完的任務算成已完成）
    if ($ae !== '') {
        if ($ae <= $asof) return 'done';
    } elseif ((int)($t['progress'] ?? 0) >= 100) {
        return 'done';                       // 做完了但沒填日期，只能採信進度
    }
    if ($pe === '' && $ps === '')            return 'noplan';
    if ($pe !== '' && $pe < $asof)           return 'overdue';
    if ($ps !== '' && $ps <= $asof)          return 'doing';
    return 'pending';
}

/**
 * 「目前應達成基準」自動判定（2-GM-02-03 該欄由甘特日程算，使用者只需填問題與辦法）。
 * 產出一句人看得懂的話，例：
 *   「至 2026.09.30 應完成 3/5 項；已完成 2 項，逾期 1 項（試作首件）；下一項『量產試跑』預計 2026.10.15」
 */
function prj_baseline_text(array $tasks, string $asof): string
{
    if (!$tasks) return '尚未建立主要任務';
    $due = 0; $done = 0; $over = []; $next = null;
    foreach ($tasks as $t) {
        $pe = (string)($t['plan_end'] ?? '');
        $st = prj_task_state($t, $asof);
        if ($pe !== '' && $pe <= $asof) $due++;
        if ($st === 'done') $done++;
        if ($st === 'overdue') $over[] = (string)$t['task_name'];
        if ($st !== 'done' && $pe !== '' && $pe > $asof) {
            if ($next === null || $pe < (string)$next['plan_end']) $next = $t;
        }
    }
    $parts = [];
    $parts[] = '至 ' . eg_fmt_date($asof) . ' 應完成 ' . $due . '/' . count($tasks) . ' 項';
    $parts[] = '已完成 ' . $done . ' 項';
    if ($over) {
        $show = array_slice($over, 0, 3);
        $parts[] = '逾期 ' . count($over) . ' 項（' . implode('、', $show) . (count($over) > 3 ? '…' : '') . '）';
    }
    if ($next) $parts[] = '下一項「' . $next['task_name'] . '」預計 ' . eg_fmt_date($next['plan_end']);
    return implode('；', $parts);
}

/** 甘特軸範圍：優先用專案起迄，沒填就由任務日期推（兩者都沒有時回 null，畫面顯示提示不硬畫） */
function prj_gantt_range(array $prj, array $tasks): ?array
{
    $min = (string)($prj['start_date'] ?? '');
    $max = (string)($prj['end_date'] ?? '');
    foreach ($tasks as $t) {
        foreach (['plan_start', 'plan_end', 'act_start', 'act_end'] as $k) {
            $v = (string)($t[$k] ?? '');
            if ($v === '' || $v === '0000-00-00') continue;
            if ($min === '' || $v < $min) $min = $v;
            if ($max === '' || $v > $max) $max = $v;
        }
    }
    if ($min === '' || $max === '') return null;
    return ['start' => $min, 'end' => $max];
}

/* ══════════════════════════ 製程（由 BOM 帶入） ══════════════════════════ */

/**
 * 專案訂單目前實際的 BOM 製程（唯讀查詢，不動 BOM 任何資料）。
 * bom.o_order_id 存的是 order_track.Order_id 的數字（資料字典寫「o-oo對應訂單編號」是舊註解，
 * 實測 2026-08-20：B-1150820007 的 o_order_id=9233 對應 Order_id=9233，非 Order_oo），
 * 故一律以 Order_id 比對，並排除非數字值（B=備庫、R=訂單重製）。
 */
function prj_bom_rows(PDO $db, int $projectId): array
{
    /* 訂單 → 製令(bom) 的對應有三種寫法，全站都是「三個都要看」（比照 part_cost_lib 的 ppc_bom_order()
       與 shipping_lib 的 A/B 兩路），只認其中一種一定會漏：
         ①bom_order_process_map.order_id ── 真正的對應表，優先
         ②bom.o_order_id ＝ order_track.Order_id（數字，BomTrack_API 的用法）
         ③bom.o_order_id ＝ order_track.Order_oo（訂單單號字串，shipping_lib 的用法）
       實測：全庫 11810 張製令裡有 10213 張（86%）的 o_order_id 是空的，只靠 ②就會「同步 BOM 抓不到任何資料」，
       使用者回報的正是這一種（製令已出貨結案、o_order_id 從頭到尾沒填，但 bom_order_process_map 有對應）。
       另：**刻意不濾 bom.closed_at／state** ── 已結案（出貨完）的製令，它的製程履歷仍然是這個專案的實績，
       要看得到；只濾掉 bi.is_consumed=1（被合併掉的製程列）。 */
    $sql = "SELECT bi.bom_ing_fid, bi.bom, bi.bom_sn, bi.process_no, bi.maker_id_no,
                   bi.sqty, bi.processing_state, bi.QC_check,
                   DATE(bi.outsource_date) AS outsource_date, DATE(bi.return_date) AS return_date,
                   b.d_setting_id AS ds_pk, b.d_id AS part_no, b.closed_at AS bom_closed_at,
                   pn.ProcessName AS process_name, m.maker_id AS maker_name
            FROM (
                    SELECT m1.bom AS bom
                      FROM bom_order_process_map m1
                      JOIN project_order po1 ON po1.order_id = m1.order_id AND po1.project_id = ?
                    UNION
                    SELECT b2.bom
                      FROM bom b2
                      JOIN project_order po2 ON po2.project_id = ?
                      JOIN order_track ot2 ON ot2.Order_id = po2.order_id
                     WHERE b2.o_order_id IS NOT NULL AND b2.o_order_id <> ''
                       AND (b2.o_order_id = CAST(po2.order_id AS CHAR) OR b2.o_order_id = ot2.Order_oo)
                 ) src
            JOIN bom b      ON b.bom  = src.bom
            JOIN bom_ing bi ON bi.bom = b.bom
            LEFT JOIN process_no pn ON pn.ProcessNo = bi.process_no
            LEFT JOIN maker_list m  ON m.maker_id_no = bi.maker_id_no
            WHERE bi.is_consumed = 0
            ORDER BY b.bom, bi.bom_sn, bi.bom_ing_fid";
    $st = $db->prepare($sql);
    $st->execute([$projectId, $projectId]);
    return $st->fetchAll(PDO::FETCH_ASSOC);
}

/** 同步比對指紋：這些欄位任一變動就算 BOM 製程被改過，要提示專案管理人 */
function prj_bom_sig(array $r): string
{
    return md5(implode('|', [
        (string)($r['bom_sn'] ?? ''), (string)($r['process_no'] ?? ''),
        (string)($r['maker_id_no'] ?? ''), (string)($r['sqty'] ?? ''),
        (string)($r['outsource_date'] ?? ''), (string)($r['return_date'] ?? ''),
        (string)($r['QC_check'] ?? ''), (string)($r['processing_state'] ?? ''),
    ]));
}

/**
 * 把 BOM 製程同步進專案，並記錄變更提示。
 * $silent=true 用於「開啟頁面時的背景同步」（第一次帶入不當成變更，不洗出一堆提示）。
 * 專案端自己加的 note / is_milestone 不會被同步覆蓋。
 */
function prj_bom_sync(PDO $db, int $projectId, string $by, bool $silent = false): array
{
    $live = prj_bom_rows($db, $projectId);
    $st = $db->prepare("SELECT * FROM project_process WHERE project_id=?");
    $st->execute([$projectId]);
    $old = [];
    foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $r) $old[(string)$r['bom'] . '#' . (int)$r['bom_ing_fid']] = $r;
    $oldBoms = [];
    foreach ($old as $r) $oldBoms[(string)$r['bom']] = true;

    $added = 0; $changed = 0; $removed = 0; $newBoms = [];
    $chg = $db->prepare("INSERT INTO project_bom_change (project_id, bom, change_type, detail, detected_at)
                         VALUES (?,?,?,?,NOW())");
    $ins = $db->prepare("INSERT INTO project_process
            (project_id, ds_pk, bom, bom_ing_fid, bom_sn, process_no, process_name, maker_id_no, maker_name,
             sqty, outsource_date, return_date, qc_check, state, sig, synced_at)
            VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,NOW())
            ON DUPLICATE KEY UPDATE bom_sn=VALUES(bom_sn), process_no=VALUES(process_no),
                process_name=VALUES(process_name), maker_id_no=VALUES(maker_id_no), maker_name=VALUES(maker_name),
                sqty=VALUES(sqty), outsource_date=VALUES(outsource_date), return_date=VALUES(return_date),
                qc_check=VALUES(qc_check), state=VALUES(state), ds_pk=VALUES(ds_pk),
                sig=VALUES(sig), synced_at=NOW()");

    $seen = [];
    foreach ($live as $r) {
        $key = (string)$r['bom'] . '#' . (int)$r['bom_ing_fid'];
        $seen[$key] = true;
        $sig = prj_bom_sig($r);
        $prev = $old[$key] ?? null;
        $ins->execute([
            $projectId, $r['ds_pk'] ?: null, $r['bom'], (int)$r['bom_ing_fid'], $r['bom_sn'],
            $r['process_no'], $r['process_name'], $r['maker_id_no'], $r['maker_name'],
            $r['sqty'], $r['outsource_date'] ?: null, $r['return_date'] ?: null,
            $r['QC_check'], $r['processing_state'], $sig,
        ]);
        if (!$prev) {
            $added++;
            if (!isset($oldBoms[(string)$r['bom']])) $newBoms[(string)$r['bom']] = true;
            if (!$silent && isset($oldBoms[(string)$r['bom']])) {
                $chg->execute([$projectId, $r['bom'], 'added',
                    '新增製程：' . ($r['process_name'] ?: ('製程' . $r['process_no'])) . '（序號 ' . $r['bom_sn'] . '）']);
            }
        } elseif ((string)$prev['sig'] !== $sig) {
            $changed++;
            if (!$silent) {
                $chg->execute([$projectId, $r['bom'], 'changed',
                    '製程異動：' . ($r['process_name'] ?: ('製程' . $r['process_no'])) . '（序號 ' . $r['bom_sn'] . '）'
                    . prj_bom_diff_text($prev, $r)]);
            }
        }
    }
    // 整張 BOM 第一次進來（含背景同步）一律提示：這是「BOM 開立後自動帶入」要讓專案管理人知道的事
    foreach (array_keys($newBoms) as $bomNo) {
        $chg->execute([$projectId, $bomNo, 'bom_added', '新的製令單 ' . $bomNo . ' 已開立並帶入製程']);
    }
    // 消失的製程（BOM 被刪列或整張退掉）
    foreach ($old as $key => $r) {
        if (isset($seen[$key])) continue;
        $removed++;
        $db->prepare("DELETE FROM project_process WHERE id=?")->execute([(int)$r['id']]);
        if (!$silent) {
            $chg->execute([$projectId, $r['bom'], 'removed',
                '製程已移除：' . ($r['process_name'] ?: ('製程' . $r['process_no'])) . '（序號 ' . $r['bom_sn'] . '）']);
        }
    }
    return ['added' => $added, 'changed' => $changed, 'removed' => $removed, 'total' => count($live)];
}

/** 變更提示的差異描述（只列真的變了的欄位，讓提示看得懂改了什麼） */
function prj_bom_diff_text(array $prev, array $now): string
{
    $map = [
        'bom_sn'      => '加工順序',
        'process_no'  => '製程',
        'maker_id_no' => '廠商',
        'sqty'        => '發包數',
        'outsource_date' => '發包日',
        'return_date' => '回廠日',
    ];
    $nowMap = $now + ['QC_check' => $now['QC_check'] ?? null];
    $diff = [];
    foreach ($map as $k => $label) {
        $a = (string)($prev[$k] ?? '');
        $b = (string)($nowMap[$k] ?? '');
        if ($a === $b) continue;
        if ($k === 'maker_id_no') { $a = (string)($prev['maker_name'] ?? $a); $b = (string)($now['maker_name'] ?? $b); }
        if ($k === 'process_no')  { $a = (string)($prev['process_name'] ?? $a); $b = (string)($now['process_name'] ?? $b); }
        if (in_array($k, ['outsource_date', 'return_date'], true)) {
            $a = $a !== '' ? eg_fmt_date($a) : '(空)';
            $b = $b !== '' ? eg_fmt_date($b) : '(空)';
        }
        $diff[] = $label . ' ' . ($a === '' ? '(空)' : $a) . '→' . ($b === '' ? '(空)' : $b);
    }
    return $diff ? '：' . implode('、', $diff) : '';
}

function prj_processes(PDO $db, int $projectId): array
{
    $st = $db->prepare("SELECT pp.*, COALESCE(ds.D_Setting_Id, '') AS part_no
                        FROM project_process pp
                        LEFT JOIN d_setting ds ON ds.d_id=pp.ds_pk
                        WHERE pp.project_id=?
                        ORDER BY pp.bom, pp.bom_sn, pp.id");
    $st->execute([$projectId]);
    return $st->fetchAll(PDO::FETCH_ASSOC);
}

/** 未知悉的 BOM 變更提示（專案清單的紅色徽章與詳情頁的提示條都用這支） */
function prj_bom_alerts(PDO $db, int $projectId, bool $unackedOnly = true): array
{
    $sql = "SELECT * FROM project_bom_change WHERE project_id=?";
    if ($unackedOnly) $sql .= " AND acked_at IS NULL";
    $sql .= " ORDER BY detected_at DESC, id DESC LIMIT 200";
    $st = $db->prepare($sql);
    $st->execute([$projectId]);
    return $st->fetchAll(PDO::FETCH_ASSOC);
}

/**
 * BOM 開立後由外部呼叫的掛勾：找出這張 BOM 屬於哪個專案並同步。
 * 放在共用庫，開立 BOM 的頁面只要 require 本檔再呼叫一行即可（不在別處重寫一套判斷）。
 */
function prj_on_bom_created(PDO $db, string $bomNo, string $by = 'system'): void
{
    try {
        prj_ensure_schema($db);
        $st = $db->prepare("SELECT po.project_id FROM bom b
                            JOIN project_order po ON CAST(po.order_id AS CHAR) = b.o_order_id
                            JOIN project p ON p.project_id=po.project_id AND p.is_deleted=0
                            WHERE b.bom=? LIMIT 1");
        $st->execute([$bomNo]);
        $pid = (int)$st->fetchColumn();
        if ($pid) prj_bom_sync($db, $pid, $by, false);
    } catch (Throwable $e) {
        // 掛勾失敗絕不能影響 BOM 開立本身；專案端還有手動「同步 BOM」鈕可補
    }
}

/* ══════════════════════════ 文件檢核（四個頁面） ══════════════════════════ */

/**
 * 專案內每個料號的四項文件有無。
 * 判定基準比照各模組自己的既有寫法：
 *   dev_eval：td_dev_eval（part_d_id 為主，手打料號另比 part_no_text）
 *   pfmea   ：pfmea_doc（同上）
 *   type_id ：type_id_ctrl_doc 的項目列有引用到該料號
 *   ext_doc ：外來文件清單有列（料號附件或報價附件，勾選 is_external_doc 的類別）
 */
function prj_doc_check(PDO $db, int $projectId): array
{
    $parts = prj_parts($db, $projectId);
    if (!$parts) return [];
    $ids = array_map(static fn($r) => (int)$r['ds_pk'], $parts);
    $have = prj_doc_have_map($db, $ids);
    $out = [];
    foreach ($parts as $r) {
        $dsPk = (int)$r['ds_pk'];
        $row = ['ds_pk' => $dsPk, 'part_no' => $r['part_no'], 'source' => $r['source'],
                'customer_name' => $r['customer_name'], 'missing' => 0];
        foreach (array_keys(PRJ_DOC_CHECKS) as $k) {
            $ok = !empty($have[$k][$dsPk]);
            $row[$k] = $ok ? 1 : 0;
            if (!$ok) $row['missing']++;
        }
        $out[] = $row;
    }
    return $out;
}

/** 一次查完四項文件的「哪些料號已有」，避免逐料號逐表 N+1 查詢 */
function prj_doc_have_map(PDO $db, array $dsPks): array
{
    $dsPks = array_values(array_unique(array_filter(array_map('intval', $dsPks))));
    $have = ['dev_eval' => [], 'type_id' => [], 'pfmea' => [], 'ext_doc' => []];
    if (!$dsPks) return $have;
    $in = implode(',', array_fill(0, count($dsPks), '?'));

    // 料號字串（手打料號的表要用字串比對）
    $st = $db->prepare("SELECT d_id, D_Setting_Id FROM d_setting WHERE d_id IN ($in)");
    $st->execute($dsPks);
    $noOf = [];
    foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $r) $noOf[(int)$r['d_id']] = (string)$r['D_Setting_Id'];
    $nos = array_values(array_filter($noOf));
    $inNo = $nos ? implode(',', array_fill(0, count($nos), '?')) : '';

    $byNo = static function (array $rows, array $noOf, array &$bucket) {
        $rev = [];
        foreach ($noOf as $pk => $no) if ($no !== '') $rev[$no][] = $pk;
        foreach ($rows as $r) {
            if (!empty($r['part_d_id'])) { $bucket[(int)$r['part_d_id']] = true; continue; }
            $t = (string)($r['part_no_text'] ?? '');
            if ($t !== '' && isset($rev[$t])) foreach ($rev[$t] as $pk) $bucket[$pk] = true;
        }
    };

    try {
        $sql = "SELECT part_d_id, part_no_text FROM td_dev_eval WHERE is_deleted=0 AND (part_d_id IN ($in)"
             . ($inNo ? " OR part_no_text IN ($inNo)" : '') . ")";
        $st = $db->prepare($sql);
        $st->execute($inNo ? array_merge($dsPks, $nos) : $dsPks);
        $byNo($st->fetchAll(PDO::FETCH_ASSOC), $noOf, $have['dev_eval']);
    } catch (Throwable $e) {
    }

    try {
        $sql = "SELECT part_d_id, part_no_text FROM pfmea_doc WHERE is_deleted=0 AND (part_d_id IN ($in)"
             . ($inNo ? " OR part_no_text IN ($inNo)" : '') . ")";
        $st = $db->prepare($sql);
        $st->execute($inNo ? array_merge($dsPks, $nos) : $dsPks);
        $byNo($st->fetchAll(PDO::FETCH_ASSOC), $noOf, $have['pfmea']);
    } catch (Throwable $e) {
    }

    // 型態識別文件管制表：該料號有被任何一張管制表的項目列引用（欄位是 ref_ds_pk，非 ds_pk）
    try {
        $st = $db->prepare("SELECT DISTINCT i.ref_ds_pk
                            FROM type_id_ctrl_item i
                            JOIN type_id_ctrl_doc d ON d.id=i.doc_id AND d.is_deleted=0
                            WHERE i.ref_ds_pk IN ($in) AND i.is_deleted=0 AND i.is_excluded=0");
        $st->execute($dsPks);
        foreach ($st->fetchAll(PDO::FETCH_COLUMN) as $pk) $have['type_id'][(int)$pk] = true;
    } catch (Throwable $e) {
    }

    // 外來文件：類別來源與外來文件清單同一處（quotation_file_categories.is_external_doc=1），
    // 附件的類別存成 CSV 欄位 category_ids，故用 FIND_IN_SET 比對（比照 type_id_ctrl_lib 既有寫法，
    // 不另外發明一套判定；料號附件與報價附件都算數＝外來文件清單的既有口徑）。
    try {
        $catIds = $db->query("SELECT id FROM quotation_file_categories WHERE is_external_doc=1")
                     ->fetchAll(PDO::FETCH_COLUMN);
        $catIds = array_values(array_filter(array_map('intval', $catIds)));
        if ($catIds) {
            $cond = [];
            foreach ($catIds as $cid) $cond[] = "FIND_IN_SET($cid, REPLACE(COALESCE(pa.category_ids,''),' ',''))";
            // 批圖暫存檔（工作檔與其輸出圖）不算外來文件，否則旗標亮著、外來文件清單卻查無此檔
            require_once __DIR__ . '/imgedit_visibility.php';
            $st = $db->prepare("SELECT DISTINCT pa.d_id FROM part_attachments pa
                                WHERE pa.d_id IN ($in) AND pa.deleted_at IS NULL
                                  AND " . imgedit_sql_not_draft('pa') . "
                                  AND (" . implode(' OR ', $cond) . ")");
            $st->execute($dsPks);
            foreach ($st->fetchAll(PDO::FETCH_COLUMN) as $pk) $have['ext_doc'][(int)$pk] = true;

            // 報價附件：linked_parts 為 NULL＝該報價單的料號共用，否則只認 JSON 陣列裡點名的料號
            $qcond = [];
            foreach ($catIds as $cid) $qcond[] = "FIND_IN_SET($cid, REPLACE(COALESCE(a.category_ids,''),' ',''))";
            $qcond[] = 'a.category_id IN (' . implode(',', $catIds) . ')';
            foreach ($dsPks as $pk) {
                if (!empty($have['ext_doc'][$pk])) continue;
                $st = $db->prepare("SELECT 1 FROM quotation_attachments a
                                    JOIN quotation_item qi ON qi.quote_id=(SELECT quote_id FROM quotation_list WHERE quote_no=a.quote_no)
                                    WHERE a.status='active' AND (" . implode(' OR ', $qcond) . ")
                                      AND ((a.linked_parts IS NULL AND qi.d_setting_d_id=?)
                                           OR (a.linked_parts IS NOT NULL AND JSON_CONTAINS(a.linked_parts, JSON_QUOTE(?))))
                                    LIMIT 1");
                $st->execute([$pk, (string)($noOf[$pk] ?? '')]);
                if ($st->fetchColumn()) $have['ext_doc'][$pk] = true;
            }
        }
    } catch (Throwable $e) {
    }
    return $have;
}

/**
 * 給四個頁面的既有偵測鈕合併使用：「有專案、但這一頁還沒建立」的料號清單。
 * $target 為 PRJ_DOC_CHECKS 的 key；只算未刪除且未結案的專案（結案專案不該再催建文件）。
 */
function prj_missing_for(PDO $db, string $target, bool $includeClosed = false): array
{
    if (!isset(PRJ_DOC_CHECKS[$target])) return [];
    $w = ['p.is_deleted=0'];
    if (!$includeClosed) $w[] = "p.status NOT IN ('closed','terminated','rejected')";
    $st = $db->prepare("SELECT DISTINCT pp.ds_pk, COALESCE(ds.D_Setting_Id, pp.part_no) AS part_no,
                               p.project_id, p.project_no, p.project_name, p.customer_name,
                               ds.Customer_Id, ds.Is_Assembly
                        FROM project_part pp
                        JOIN project p ON p.project_id=pp.project_id
                        LEFT JOIN d_setting ds ON ds.d_id=pp.ds_pk
                        WHERE " . implode(' AND ', $w) . "
                        ORDER BY p.project_no DESC, part_no");
    $st->execute();
    $rows = $st->fetchAll(PDO::FETCH_ASSOC);
    if (!$rows) return [];
    $have = prj_doc_have_map($db, array_map(static fn($r) => (int)$r['ds_pk'], $rows));

    // 已被標記「不列入」的不再出現
    $ig = [];
    try {
        $st = $db->prepare("SELECT ds_pk FROM project_doc_pending WHERE target=? AND status='ignored'");
        $st->execute([$target]);
        foreach ($st->fetchAll(PDO::FETCH_COLUMN) as $pk) $ig[(int)$pk] = true;
    } catch (Throwable $e) {
    }

    $out = [];
    $seen = [];
    foreach ($rows as $r) {
        $pk = (int)$r['ds_pk'];
        if ($pk <= 0 || isset($seen[$pk]) || isset($ig[$pk])) continue;
        if (!empty($have[$target][$pk])) continue;
        $seen[$pk] = true;
        $out[] = $r;
    }
    return $out;
}

/** 四頁頁首提醒用的筆數（沒有專案資料時回 0，絕不讓例外影響原頁面） */
function prj_missing_count(PDO $db, string $target): int
{
    try {
        prj_ensure_schema($db);
        return count(prj_missing_for($db, $target));
    } catch (Throwable $e) {
        return 0;
    }
}

/* ══════════════════════════ 專案管理卡（2-GM-02-03） ══════════════════════════ */

function prj_cards(PDO $db, int $projectId): array
{
    $st = $db->prepare("SELECT c.*, (SELECT COUNT(*) FROM project_card_item i WHERE i.card_id=c.card_id) AS item_cnt
                        FROM project_card c
                        WHERE c.project_id=? AND c.is_deleted=0
                        ORDER BY c.review_date DESC, c.card_id DESC");
    $st->execute([$projectId]);
    return $st->fetchAll(PDO::FETCH_ASSOC);
}

function prj_card_get(PDO $db, int $cardId): ?array
{
    $st = $db->prepare("SELECT * FROM project_card WHERE card_id=? AND is_deleted=0");
    $st->execute([$cardId]);
    $c = $st->fetch(PDO::FETCH_ASSOC);
    if (!$c) return null;
    $st = $db->prepare("SELECT * FROM project_card_item WHERE card_id=? ORDER BY sort_order, item_id");
    $st->execute([$cardId]);
    $c['items'] = $st->fetchAll(PDO::FETCH_ASSOC);
    return $c;
}

/** 管理卡編號＝專案代號-序號（同一專案內遞增，補開歷史卡時仍不重號） */
function prj_card_next_no(PDO $db, int $projectId, string $projectNo): string
{
    $st = $db->prepare("SELECT COUNT(*) FROM project_card WHERE project_id=?");
    $st->execute([$projectId]);
    return $projectNo . '-' . str_pad((string)((int)$st->fetchColumn() + 1), 2, '0', STR_PAD_LEFT);
}

/**
 * 建立管理卡：項次／目標名稱／主辦單位／承辦人自動帶入，「目前應達成基準」由甘特日程算出。
 * 使用者只需填「現階段問題」「後續辦理方法」，沒問題的列可一鍵標「依計畫進行」。
 * $goalIds 空＝納入全部目標；有值＝只納入勾選的目標。
 */
function prj_card_create(PDO $db, int $projectId, string $reviewDate, array $goalIds, array $who): int
{
    $prj = prj_get($db, $projectId);
    if (!$prj) throw new RuntimeException('專案不存在');
    $goals = prj_goals($db, $projectId);
    $tasks = prj_tasks($db, $projectId);
    if ($goalIds) {
        $want = array_flip(array_map('intval', $goalIds));
        $goals = array_values(array_filter($goals, static fn($g) => isset($want[(int)$g['goal_id']])));
    }
    $now = prj_db_now($db);

    $st = $db->prepare("INSERT INTO project_card (project_id, card_no, review_date, status, created_by, created_by_name, created_at)
                        VALUES (?,?,?,'draft',?,?,?)");
    $st->execute([$projectId, prj_card_next_no($db, $projectId, (string)$prj['project_no']),
                  $reviewDate, (int)($who['uid'] ?? 0), (string)($who['uname'] ?? ''), $now['dt']]);
    $cardId = (int)$db->lastInsertId();

    $ins = $db->prepare("INSERT INTO project_card_item
        (card_id, goal_id, goal_name, dept_name, owner_name, baseline, baseline_auto, sort_order)
        VALUES (?,?,?,?,?,?,1,?)");
    $i = 0;
    foreach ($goals as $g) {
        $gid = (int)$g['goal_id'];
        $gTasks = array_values(array_filter($tasks, static fn($t) => (int)$t['goal_id'] === $gid));
        // 承辦人＝該目標底下任務的負責人（去重；一個都沒有就留白讓使用者填）
        $owners = [];
        foreach ($gTasks as $t) if (trim((string)$t['owner_name']) !== '') $owners[(string)$t['owner_name']] = true;
        $ins->execute([$cardId, $gid, (string)$g['goal_name'], (string)$g['dept_name'],
                       implode('、', array_keys($owners)),
                       prj_baseline_text($gTasks, $reviewDate), $i++]);
    }
    return $cardId;
}

/** 檢討日期被改動時，仍為自動的那些列要跟著重算（推導欄位鐵則：來源一改就重算） */
function prj_card_refresh_baseline(PDO $db, int $cardId): void
{
    $c = prj_card_get($db, $cardId);
    if (!$c) return;
    $tasks = prj_tasks($db, (int)$c['project_id']);
    $up = $db->prepare("UPDATE project_card_item SET baseline=? WHERE item_id=? AND baseline_auto=1");
    foreach ($c['items'] as $it) {
        if (!(int)$it['baseline_auto']) continue;
        $gid = (int)$it['goal_id'];
        $gTasks = array_values(array_filter($tasks, static fn($t) => (int)$t['goal_id'] === $gid));
        $up->execute([prj_baseline_text($gTasks, (string)$c['review_date']), (int)$it['item_id']]);
    }
}

/* ══════════════════════════ 會簽與核准 ══════════════════════════ */

function prj_cosigns(PDO $db, int $projectId): array
{
    $st = $db->prepare("SELECT * FROM project_cosign WHERE project_id=? ORDER BY sort_order, id");
    $st->execute([$projectId]);
    return $st->fetchAll(PDO::FETCH_ASSOC);
}

/**
 * 立案核准人解析（ai-rules/19 三段式＋強制 SoD 迴避）：
 *   ①綁部門＝該部門(含子部門)內任一職級不低於送出者的主管 ②綁人員＝固定該人
 *   ③都沒設＝自動抓送出者的上一級主管，再退回全站 top_approver
 * 解析到的人剛好是送出者本人時視同該順位無結果，往下一順位試（球員不可兼裁判）。
 */
function prj_approver_pool(PDO $db, int $submitterId): array
{
    $pool = [];
    $push = static function ($uid) use (&$pool, $submitterId) {
        $uid = (int)$uid;
        if ($uid > 0 && $uid !== $submitterId && !in_array($uid, $pool, true)) $pool[] = $uid;
    };

    // ① / ② 模組設定的部門或人員綁定
    $bindUser = (int)prj_setting_get($db, 'approver_user_id', '0');
    $bindDept = (int)prj_setting_get($db, 'approver_dept_id', '0');
    if ($bindUser) $push($bindUser);
    if (!$pool && $bindDept) {
        // 部門是樹狀的，要含子部門一併認列（CLAUDE.md：不可用單一 id 去 = 比對）
        try {
            $ids = prj_dept_tree_ids($db, $bindDept);
            $in  = implode(',', array_fill(0, count($ids), '?'));
            // 「該部門任一職級不低於送出者的主管」＝有職級設定者；沒設職級的人不算主管（見 ai-rules/19）
            $lv = prj_user_top_level($db, $submitterId);
            $st = $db->prepare("SELECT DISTINCT m.user_id, MIN(pl.level) AS lv
                                FROM user_department_position_map m
                                JOIN position_level pl ON pl.position_id=m.position_id
                                JOIN user u ON u.id=m.user_id
                                WHERE m.department_id IN ($in) AND u.state=1
                                GROUP BY m.user_id
                                ORDER BY lv");
            $st->execute($ids);
            foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $r) {
                // level 數字越小職級越高；核准人職級不可低於送出者
                if ($lv !== null && (int)$r['lv'] > $lv) continue;
                $push($r['user_id']);
            }
        } catch (Throwable $e) {
        }
    }
    // ③ 自動：送出者的上一級主管 → 全站最高核准人員
    if (!$pool && $submitterId > 0) {
        try { $push(eg_resolve_supervisor($db, $submitterId)); } catch (Throwable $e) {}
    }
    if (!$pool) {
        try {
            $top = eg_org_user($db, 'top_approver');
            if ($top) $push($top['id'] ?? 0);
        } catch (Throwable $e) {
        }
    }
    return $pool;
}

/** 某部門及其所有子部門的 id（組織是樹狀，只比單一 id 會把子部門的人判成「不是該部門」） */
function prj_dept_tree_ids(PDO $db, int $rootId): array
{
    $all = $db->query("SELECT id, parent_id FROM department")->fetchAll(PDO::FETCH_ASSOC);
    $child = [];
    foreach ($all as $d) $child[(int)$d['parent_id']][] = (int)$d['id'];
    $out = [];
    $stack = [$rootId];
    while ($stack) {
        $cur = array_pop($stack);
        if (isset($out[$cur])) continue;
        $out[$cur] = true;
        foreach ($child[$cur] ?? [] as $c) $stack[] = $c;
    }
    return array_keys($out);
}

/** 某人目前最高職級（level 數字越小越高）；沒有職級設定回 null＝不是主管 */
function prj_user_top_level(PDO $db, int $uid): ?int
{
    if ($uid <= 0) return null;
    $st = $db->prepare("SELECT MIN(pl.level) FROM user_department_position_map m
                        JOIN position_level pl ON pl.position_id=m.position_id
                        WHERE m.user_id=?");
    $st->execute([$uid]);
    $v = $st->fetchColumn();
    return ($v === null || $v === false) ? null : (int)$v;
}

/** 這個人現在可不可以核准這筆專案 */
function prj_can_approve(PDO $db, array $prj, array $perms): bool
{
    if ((string)$prj['status'] !== 'submitted') return false;
    if (!empty($perms['isAdmin'])) return true;
    $pool = prj_approver_pool($db, (int)$prj['created_by']);
    return in_array((int)$perms['uid'], $pool, true);
}

/**
 * 自動簽核時間戳（ai-rules/21）：業務日期＝送出日，精確時間刻意錯開 5~30 分且不跨日。
 * 回傳 [業務日期, 時間戳]。
 */
function prj_auto_sign_stamp(string $bizDate, string $baseDateTime): array
{
    $base = strtotime($baseDateTime);
    if ($base === false) $base = strtotime($bizDate . ' 09:00:00');
    $ts = $base + random_int(5, 30) * 60;
    if (date('Y-m-d', $ts) !== date('Y-m-d', $base)) $ts = strtotime(date('Y-m-d', $base) . ' 23:50:00');
    return [$bizDate, date('Y-m-d H:i:s', $ts)];
}

/* ══════════════════════════ 列印中繼資料 ══════════════════════════ */

/**
 * 列印表頭/表尾資料（ai-rules/16）：
 *   大標題＝本公司全名（動態取，禁寫死）／表頭表單名稱＝綁定 AS 文件的 doc_name
 *   頁尾右下＝AS 文件編號，且版次依該單據的業務日期回推（第三之四節）
 * $module 為 PRJ_ASDOC_PLAN 或 PRJ_ASDOC_CARD；$bizDate 為該表單自己的業務日期。
 */
function prj_print_meta(PDO $db, string $module, ?string $bizDate): array
{
    $doc = eg_asdoc_get($db, $module);
    $docId = $doc ? (int)$doc['id'] : 0;
    return [
        'company'  => prj_company_name($db),
        'doc_name' => $doc ? (string)$doc['doc_name'] : '',
        'doc_no'   => $docId ? eg_asdoc_no_asof_id($db, $docId, $bizDate) : '',
        'bound'    => $docId > 0,
    ];
}

/**
 * 圖章要顯示的部門/職稱：一律依「該表單的業務日期」回推當時職務（ai-rules/22）。
 * 回推不到時**不退回現況**——寧可少一個章也不要把現在才上任的人蓋到舊文件上（該篇第一坑）；
 * 只有業務日期是今天或未來的單據才允許用現況。
 */
function prj_sign_post(PDO $db, int $userId, ?string $bizDate, string $today): array
{
    if ($userId <= 0) return ['dept' => '', 'post' => '', 'name' => ''];
    $st = $db->prepare("SELECT user_cname FROM user WHERE id=?");
    $st->execute([$userId]);
    $name = (string)$st->fetchColumn();
    $date = $bizDate ?: $today;
    try {
        // 回傳的是「該人所有職務」的清單（含兼任），不是單一筆
        $posts = ($date < $today)
            ? eg_position_snapshot_at($db, $userId, $date)
            : eg_position_snapshot_now($db, $userId);
        if (!$posts) return ['dept' => '', 'post' => '', 'name' => $name];
        // 兼任常才是簽核身分：取職級最高（level 數字最小）那筆，都沒職級才退回主職（ai-rules/22）
        $best = null; $bestLv = null;
        foreach ($posts as $p) {
            $lv = null;
            try {
                $st = $db->prepare("SELECT MIN(level) FROM position_level WHERE position_id=?");
                $st->execute([(int)$p['position_id']]);
                $v = $st->fetchColumn();
                $lv = ($v === null || $v === false) ? null : (int)$v;
            } catch (Throwable $e) {
            }
            if ($lv !== null && ($bestLv === null || $lv < $bestLv)) { $best = $p; $bestLv = $lv; }
        }
        if ($best === null) {
            foreach ($posts as $p) if ((int)($p['is_main'] ?? 0) === 1) { $best = $p; break; }
            if ($best === null) $best = $posts[0];
        }
        return ['dept' => (string)($best['department_name'] ?? ''),
                'post' => (string)($best['position_name'] ?? ''), 'name' => $name];
    } catch (Throwable $e) {
        return ['dept' => '', 'post' => '', 'name' => $name];
    }
}
