<?php
/**
 * 潛在失效模式及效應分析 PFMEA（AS 3-TD-01-02）—— 共用庫
 * 每個料號一份分析表，逐列記錄一個潛在失效模式；風險優先指數 RPN = 嚴重度(S) × 發生度(O) × 偵測度(D)，
 * 一律由系統計算不給手填（鐵律：推導欄位改了來源就要重算）。嚴重度/發生度/偵測度/RPN 分級對照表
 * 為固定顯示的參考資訊（見頁面 PFMEA_RATING_TABLE 常數），不隨每張分析表個別修改。
 * AS 文件範本本身的改版紀錄（as_document_version）跟這裡的 pfmea_revision 是兩件事：pfmea_revision
 * 是「這一筆填寫紀錄自己」的新增/修改履歷（比照官方表單右上角小表，2026-08-13 新增），範本改版仍走
 * 全站既有 AS 文件版本管理，不在此另建。
 */

/** 官方紙本表單(F-11210-UE2-0001)固定的「相關部門」勾選清單，單一來源(Pfmea_API.php/pfmea_lib.php共用) */
const PFMEA_DEPT_LIST_LIB = ['管理課','技術課','業務組','品保組','倉管組','採購組','生管組','生產課'];

function pfmea_ensure_schema(PDO $db): void {
    $db->exec("CREATE TABLE IF NOT EXISTS pfmea_doc (
        id INT AUTO_INCREMENT PRIMARY KEY,
        doc_no VARCHAR(20) NOT NULL COMMENT '本表文件編號(YYYYMMDD+3位流水號)',
        part_d_id INT NULL COMMENT '產品編號(料號)，對應d_setting.d_id',
        part_no_text VARCHAR(60) NULL COMMENT '料號顯示字串，未建料號時可手動輸入',
        team_of_work VARCHAR(200) NULL COMMENT '工作團隊成員',
        created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
        created_by INT NULL,
        created_by_name VARCHAR(50) NULL,
        updated_at TIMESTAMP NULL,
        updated_by INT NULL,
        updated_by_name VARCHAR(50) NULL,
        is_deleted TINYINT(1) NOT NULL DEFAULT 0,
        UNIQUE KEY uq_doc_no (doc_no),
        KEY idx_part (part_d_id)
    ) DEFAULT CHARSET=utf8mb4 COMMENT='PFMEA潛在失效模式及效應分析(3-TD-01-02)-表頭'");

    $db->exec("CREATE TABLE IF NOT EXISTS pfmea_item (
        id INT AUTO_INCREMENT PRIMARY KEY,
        doc_id INT NOT NULL,
        seq INT NOT NULL COMMENT '項次(顯示排序)',
        process_desc VARCHAR(200) NULL COMMENT '製程說明',
        function_desc VARCHAR(200) NULL COMMENT '功能',
        requirement VARCHAR(200) NULL COMMENT '要求',
        failure_mode VARCHAR(300) NULL COMMENT '潛在失效模式',
        failure_effect VARCHAR(300) NULL COMMENT '潛在失效效應',
        severity TINYINT NULL COMMENT '嚴重度S(1-10)',
        classification VARCHAR(100) NULL COMMENT '分類/特殊特性標記',
        failure_cause VARCHAR(300) NULL COMMENT '潛在失效原因',
        occurrence TINYINT NULL COMMENT '發生度O(1-10)',
        current_controls VARCHAR(300) NULL COMMENT '現行製程管制',
        detection TINYINT NULL COMMENT '偵測度D(1-10)',
        rpn SMALLINT NULL COMMENT '風險優先指數=S×O×D，系統自動計算',
        recommended_actions VARCHAR(300) NULL COMMENT '建議改善措施',
        responsibility VARCHAR(100) NULL COMMENT '責任者',
        target_date DATE NULL COMMENT '目標完成日',
        action_taken VARCHAR(300) NULL COMMENT '已採取措施',
        action_date DATE NULL COMMENT '措施生效日',
        new_severity TINYINT NULL COMMENT '改善後嚴重度',
        new_occurrence TINYINT NULL COMMENT '改善後發生度',
        new_detection TINYINT NULL COMMENT '改善後偵測度',
        new_rpn SMALLINT NULL COMMENT '改善後RPN，系統自動計算',
        prevention_controls VARCHAR(300) NULL COMMENT '預防管制',
        detection_controls VARCHAR(300) NULL COMMENT '偵測管制',
        created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
        updated_at TIMESTAMP NULL,
        is_deleted TINYINT(1) NOT NULL DEFAULT 0,
        KEY idx_doc (doc_id)
    ) DEFAULT CHARSET=utf8mb4 COMMENT='PFMEA-失效模式分析列'");

    // 2026-08-13 使用者要求表頭欄位比照官方紙本表單(F-11210-UE2-0001)：分類(零件/組合件，可從
    // 料號的d_setting.Is_Assembly自動帶入)、規格描述、產品名稱、相關部門(勾選清單，逗號分隔存字串)；
    // 「工作團隊」不是官方表單欄位，改用這些取代，欄位保留在DB但UI不再使用。
    foreach ([
        "ALTER TABLE pfmea_doc ADD COLUMN item_type VARCHAR(10) NOT NULL DEFAULT 'part' COMMENT '分類:part=零件/assembly=組合件，可從料號Is_Assembly自動帶入' AFTER part_no_text",
        "ALTER TABLE pfmea_doc ADD COLUMN spec_desc VARCHAR(200) NULL COMMENT '規格描述' AFTER item_type",
        "ALTER TABLE pfmea_doc ADD COLUMN product_name VARCHAR(200) NULL COMMENT '產品名稱' AFTER spec_desc",
        "ALTER TABLE pfmea_doc ADD COLUMN related_depts VARCHAR(300) NULL COMMENT '相關部門(逗號分隔部門名稱)' AFTER product_name",
        // 2026-08-13 使用者要求：業務日期——由「建議建立清單」轉入者沿用td_dev_eval該筆的建立日期；
        // 手動新增者綁定料號後比照td_dev_eval_suggest.php的建議日期機制(BOM/報工/訂單日期快速套用)。
        // 項目列的目標完成日/生效日期新建時預設帶入這個日期。
        "ALTER TABLE pfmea_doc ADD COLUMN biz_date DATE NULL COMMENT '業務日期(轉入沿用td_dev_eval建立日期/手動比照suggest建議日期機制)' AFTER related_depts",
    ] as $alter) {
        try { $db->exec($alter); } catch (Throwable $e) {}
    }

    // 2026-08-13 使用者要求：項目列補「製程代號」欄位，僅供畫面下拉輔助(帶出失效模式/整組樣板選項用)，
    // 不是官方表單欄位、不列印。
    try { $db->exec("ALTER TABLE pfmea_item ADD COLUMN process_code VARCHAR(20) NULL COMMENT '製程代號(僅畫面輔助，不列印)' AFTER seq"); } catch (Throwable $e) {}

    // 2026-08-13 使用者要求：表頭修訂履歷比照官方表單右上角「新增文件/修改文件」記錄(編號/日期/
    // 修訂內容/準備)，取消批准/檢查欄位；存檔時第一次一律記1筆「新增文件」，之後每次修改由使用者
    // 自行決定是否要記為新版本(存檔時詢問，選否就不新增列，避免版次因小幅調整一直往上跳)。
    $db->exec("CREATE TABLE IF NOT EXISTS pfmea_revision (
        id INT AUTO_INCREMENT PRIMARY KEY,
        doc_id INT NOT NULL,
        rev_no INT NOT NULL COMMENT '編號(1,2,3...)',
        rev_date DATE NOT NULL COMMENT '日期',
        rev_content VARCHAR(20) NOT NULL COMMENT '修訂內容:新增文件/修改文件',
        prepared_by_name VARCHAR(50) NULL COMMENT '準備:新增文件時為製表人,修改文件時為修改人',
        created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
        KEY idx_doc (doc_id)
    ) DEFAULT CHARSET=utf8mb4 COMMENT='PFMEA-修訂履歷(比照官方表單，僅新增文件/修改文件，無批准/檢查)'");

    // 2026-08-13 使用者要求：製程代號→潛在失效模式清單、控制預防/控制偵測固定選項、製程整組樣板
    // （來源 3-TD-01-02-潛在失效模式及效應分析.xlsm 的「資料庫」「項目異常」工作表），供編輯畫面
    // 製程代號欄位自動帶出下拉選單/整組樣板套用。可填表人(canEdit)可新增，僅管理員(canAdmin)可刪除。
    $db->exec("CREATE TABLE IF NOT EXISTS pfmea_process (
        id INT AUTO_INCREMENT PRIMARY KEY,
        process_code VARCHAR(20) NOT NULL COMMENT '製程代號',
        process_name VARCHAR(100) NOT NULL COMMENT '製程名稱',
        sort_order INT NOT NULL DEFAULT 0,
        is_active TINYINT(1) NOT NULL DEFAULT 1,
        created_by INT NULL, created_by_name VARCHAR(50) NULL,
        created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
        UNIQUE KEY uq_code (process_code)
    ) DEFAULT CHARSET=utf8mb4 COMMENT='PFMEA-製程代號主檔'");

    $db->exec("CREATE TABLE IF NOT EXISTS pfmea_process_failure_mode (
        id INT AUTO_INCREMENT PRIMARY KEY,
        process_id INT NOT NULL,
        failure_mode VARCHAR(200) NOT NULL COMMENT '潛在失效模式',
        item_option_id INT NULL COMMENT '2026-08-13新增：可選精確到項目層級(NULL=製程層級通用，向下相容既有148筆)',
        function_option_id INT NULL COMMENT '2026-08-13新增：可選精確到功能層級(NULL=項目/製程層級通用)',
        sort_order INT NOT NULL DEFAULT 0,
        is_active TINYINT(1) NOT NULL DEFAULT 1,
        created_by INT NULL, created_by_name VARCHAR(50) NULL,
        created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
        KEY idx_process (process_id)
    ) DEFAULT CHARSET=utf8mb4 COMMENT='PFMEA-製程對應潛在失效模式清單'");
    // 既有表(先前已建立148筆)補上這兩個新欄位，CREATE TABLE IF NOT EXISTS 對既有表不會生效
    foreach ([
        "ALTER TABLE pfmea_process_failure_mode ADD COLUMN item_option_id INT NULL COMMENT '2026-08-13新增：可選精確到項目層級(NULL=製程層級通用，向下相容既有148筆)' AFTER failure_mode",
        "ALTER TABLE pfmea_process_failure_mode ADD COLUMN function_option_id INT NULL COMMENT '2026-08-13新增：可選精確到功能層級(NULL=項目/製程層級通用)' AFTER item_option_id",
    ] as $alter) { try { $db->exec($alter); } catch (Throwable $e) {} }

    // 2026-08-13 使用者要求：料號-製程-項目-功能-要求 完整階層式連動（項目→功能逐層往下），
    // 潛在失效模式改為優先套用功能層級清單、無則退回項目層級、再無則退回製程層級(上面那張表)。
    // 要求(requirement)因為要依綁定的料號而不同，另立一張表，可選綁特定料號、留空=該功能通用預設值。
    $db->exec("CREATE TABLE IF NOT EXISTS pfmea_item_option (
        id INT AUTO_INCREMENT PRIMARY KEY,
        process_id INT NOT NULL,
        item_name VARCHAR(150) NOT NULL COMMENT '項目',
        sort_order INT NOT NULL DEFAULT 0,
        is_active TINYINT(1) NOT NULL DEFAULT 1,
        created_by INT NULL, created_by_name VARCHAR(50) NULL,
        created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
        KEY idx_process (process_id)
    ) DEFAULT CHARSET=utf8mb4 COMMENT='PFMEA-製程對應項目清單'");

    $db->exec("CREATE TABLE IF NOT EXISTS pfmea_function_option (
        id INT AUTO_INCREMENT PRIMARY KEY,
        item_option_id INT NOT NULL,
        function_desc VARCHAR(200) NOT NULL COMMENT '功能',
        sort_order INT NOT NULL DEFAULT 0,
        is_active TINYINT(1) NOT NULL DEFAULT 1,
        created_by INT NULL, created_by_name VARCHAR(50) NULL,
        created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
        KEY idx_item (item_option_id)
    ) DEFAULT CHARSET=utf8mb4 COMMENT='PFMEA-項目對應功能清單'");

    $db->exec("CREATE TABLE IF NOT EXISTS pfmea_requirement_option (
        id INT AUTO_INCREMENT PRIMARY KEY,
        function_option_id INT NOT NULL,
        part_d_id INT NULL COMMENT '綁特定料號時填此;留空且part_no_text也空=此功能通用預設值',
        part_no_text VARCHAR(100) NULL COMMENT '無d_setting主鍵的手動輸入料號才用這欄',
        requirement_text VARCHAR(300) NOT NULL COMMENT '要求',
        sort_order INT NOT NULL DEFAULT 0,
        is_active TINYINT(1) NOT NULL DEFAULT 1,
        created_by INT NULL, created_by_name VARCHAR(50) NULL,
        created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
        KEY idx_func (function_option_id)
    ) DEFAULT CHARSET=utf8mb4 COMMENT='PFMEA-功能+料號對應要求清單'");

    $db->exec("CREATE TABLE IF NOT EXISTS pfmea_control_option (
        id INT AUTO_INCREMENT PRIMARY KEY,
        option_type VARCHAR(20) NOT NULL COMMENT 'prevention=控制預防/detection=控制偵測',
        option_text VARCHAR(100) NOT NULL,
        sort_order INT NOT NULL DEFAULT 0,
        is_active TINYINT(1) NOT NULL DEFAULT 1,
        created_by INT NULL, created_by_name VARCHAR(50) NULL,
        created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
        UNIQUE KEY uq_opt (option_type, option_text)
    ) DEFAULT CHARSET=utf8mb4 COMMENT='PFMEA-控制預防/控制偵測固定選項清單'");

    $db->exec("CREATE TABLE IF NOT EXISTS pfmea_item_template (
        id INT AUTO_INCREMENT PRIMARY KEY,
        process_id INT NOT NULL,
        template_key VARCHAR(150) NOT NULL COMMENT '製程_潛在失效模式(組名)',
        item_name VARCHAR(150) NULL,
        failure_mode VARCHAR(200) NULL,
        function_desc VARCHAR(200) NULL,
        failure_effect VARCHAR(200) NULL,
        severity TINYINT NULL,
        failure_cause VARCHAR(200) NULL,
        occurrence TINYINT NULL,
        prevention_controls VARCHAR(100) NULL,
        detection_controls VARCHAR(100) NULL,
        detection TINYINT NULL,
        recommended_actions VARCHAR(300) NULL,
        new_severity TINYINT NULL,
        new_occurrence TINYINT NULL,
        new_detection TINYINT NULL,
        sort_order INT NOT NULL DEFAULT 0,
        is_active TINYINT(1) NOT NULL DEFAULT 1,
        created_by INT NULL, created_by_name VARCHAR(50) NULL,
        created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
        KEY idx_process (process_id)
    ) DEFAULT CHARSET=utf8mb4 COMMENT='PFMEA-製程整組樣板(項目異常)'");

    foreach ([['pfmea_view','PFMEA檢閱'],['pfmea_edit','PFMEA登錄'],['pfmea_admin','PFMEA管理員']] as $r) {
        $st = $db->prepare("SELECT 1 FROM roles WHERE role_code=? AND module='pfmea' LIMIT 1");
        $st->execute([$r[0]]);
        if (!$st->fetchColumn()) {
            $db->prepare("INSERT INTO roles (role_code, role_name, module) VALUES (?,?, 'pfmea')")
               ->execute([$r[0], $r[1]]);
        }
    }
}

function pfmea_current_user(PDO $db): ?array {
    $uname = $_SESSION['userName'] ?? '';
    if ($uname === '') return null;
    $st = $db->prepare("SELECT id, user_cname, user_status FROM user WHERE user_uname=?");
    $st->execute([$uname]);
    return $st->fetch(PDO::FETCH_ASSOC) ?: null;
}

function pfmea_has_role(PDO $db, int $uid, array $codes): bool {
    $in = implode(',', array_fill(0, count($codes), '?'));
    $st = $db->prepare("SELECT 1 FROM user_roles ur JOIN roles r ON r.role_id=ur.role_id
                        WHERE ur.user_id=? AND r.module='pfmea' AND r.role_code IN ($in) LIMIT 1");
    $st->execute(array_merge([$uid], $codes));
    if ($st->fetchColumn()) return true;
    $st = $db->prepare("SELECT 1 FROM user_department_position_map m
                        JOIN position_roles pr ON pr.position_id=m.position_id
                        JOIN roles r ON r.role_id=pr.role_id
                        WHERE m.user_id=? AND r.module='pfmea' AND r.role_code IN ($in) LIMIT 1");
    $st->execute(array_merge([$uid], $codes));
    return (bool)$st->fetchColumn();
}

function pfmea_perms(PDO $db, ?array $u): array {
    if (!$u) return ['isAdmin'=>false,'canAdmin'=>false,'canEdit'=>false,'canView'=>false];
    $uid = (int)$u['id'];
    $isAdmin = in_array((int)$u['user_status'], [9, 90], true) || $uid === 1;
    if (!$isAdmin) {
        $st = $db->prepare("SELECT 1 FROM user_roles ur JOIN roles r ON r.role_id=ur.role_id
                            WHERE ur.user_id=? AND r.role_code='admin' AND r.is_system=1 LIMIT 1");
        $st->execute([$uid]);
        $isAdmin = (bool)$st->fetchColumn();
    }
    $canAdmin = $isAdmin || pfmea_has_role($db, $uid, ['pfmea_admin']);
    $canEdit  = $canAdmin || pfmea_has_role($db, $uid, ['pfmea_edit']);
    $canView  = $canEdit  || pfmea_has_role($db, $uid, ['pfmea_view']);
    return ['isAdmin'=>$isAdmin,'canAdmin'=>$canAdmin,'canEdit'=>$canEdit,'canView'=>$canView];
}

/** 產生本表文件編號：YYYYMMDD + 3位流水號（以 DB 日期為準） */
function pfmea_next_doc_no(PDO $db): string {
    $today = $db->query("SELECT DATE_FORMAT(CURDATE(),'%Y%m%d')")->fetchColumn();
    $like = $today . '%';
    $st = $db->prepare("SELECT doc_no FROM pfmea_doc WHERE doc_no LIKE ? ORDER BY doc_no DESC LIMIT 1");
    $st->execute([$like]);
    $last = $st->fetchColumn();
    $seq = $last ? ((int)substr((string)$last, 8, 3) + 1) : 1;
    return $today . str_pad((string)$seq, 3, '0', STR_PAD_LEFT);
}

/** 「相關部門」預設勾選值（管理員設定，存 system_parameters，新建文件時自動帶入） */
function pfmea_dept_defaults_get(PDO $db): array {
    $st = $db->prepare("SELECT param_value FROM system_parameters WHERE param_group='PFMEA' AND param_key='default_depts' LIMIT 1");
    $st->execute();
    $v = $st->fetchColumn();
    if (!$v) return [];
    $arr = json_decode((string)$v, true);
    return is_array($arr) ? $arr : [];
}

function pfmea_dept_defaults_save(PDO $db, array $depts, int $uid): void {
    $depts = array_values(array_intersect(PFMEA_DEPT_LIST_LIB, $depts));
    $json = json_encode($depts, JSON_UNESCAPED_UNICODE);
    $st = $db->prepare("SELECT id FROM system_parameters WHERE param_group='PFMEA' AND param_key='default_depts' LIMIT 1");
    $st->execute();
    $id = $st->fetchColumn();
    if ($id) {
        $db->prepare("UPDATE system_parameters SET param_value=?, updated_by=? WHERE id=?")->execute([$json, $uid, $id]);
    } else {
        $db->prepare("INSERT INTO system_parameters (param_group, param_key, param_value, description, updated_by)
                      VALUES ('PFMEA','default_depts',?,'PFMEA新增文件時「相關部門」預設勾選值(JSON部門名稱陣列)',?)")->execute([$json, $uid]);
    }
}

/** 新增一筆修訂履歷（新增文件/修改文件），rev_no 自動接續 */
function pfmea_revision_add(PDO $db, int $docId, string $content, string $preparedByName): int {
    $st = $db->prepare("SELECT COALESCE(MAX(rev_no),0)+1 FROM pfmea_revision WHERE doc_id=?");
    $st->execute([$docId]);
    $revNo = (int)$st->fetchColumn();
    $st = $db->prepare("INSERT INTO pfmea_revision (doc_id, rev_no, rev_date, rev_content, prepared_by_name) VALUES (?,?,CURDATE(),?,?)");
    $st->execute([$docId, $revNo, $content, $preparedByName]);
    return (int)$db->lastInsertId();
}

function pfmea_revision_list(PDO $db, int $docId): array {
    $st = $db->prepare("SELECT rev_no, rev_date, rev_content, prepared_by_name FROM pfmea_revision WHERE doc_id=? ORDER BY rev_no");
    $st->execute([$docId]);
    return $st->fetchAll(PDO::FETCH_ASSOC);
}

/** 1-10 或空值範圍檢查；不合法回 null（讓 RPN 計算時該值視為未評分） */
function pfmea_clamp_rating($v): ?int {
    if ($v === null || $v === '') return null;
    $v = (int)$v;
    if ($v < 1) $v = 1; if ($v > 10) $v = 10;
    return $v;
}
