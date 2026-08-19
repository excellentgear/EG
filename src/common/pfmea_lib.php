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
    // 列印紀錄（2026-08-18 使用者要求：清單要看得到列印紀錄與最新列印日期）。
    // 只記「真的送去列印」的動作；唯讀檢視(viewDoc)不算，兩者雖共用 print_get 取資料。
    $db->exec("CREATE TABLE IF NOT EXISTS pfmea_print_log (
        id INT AUTO_INCREMENT PRIMARY KEY,
        doc_id INT NOT NULL,
        print_kind VARCHAR(10) NOT NULL DEFAULT 'single' COMMENT 'single=單筆列印/batch=批次列印',
        printed_by INT NULL, printed_by_name VARCHAR(50) NULL,
        printed_at DATETIME NOT NULL,
        KEY idx_doc (doc_id, printed_at)
    ) DEFAULT CHARSET=utf8mb4 COMMENT='PFMEA-列印紀錄'");

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
    // 2026-08-14 使用者要求：製程代號改從全站製程主檔(process_no/process_type)同步帶入，不再只靠
    // xlsm匯入；同步進來的每一筆預設不開放使用(is_enabled=0)，管理員在參考資料設定畫面逐一或整個
    // 大項分類批次開放，避免全公司205筆製程一次全部塞進PFMEA選單造成難以選擇。
    foreach ([
        "ALTER TABLE pfmea_process ADD COLUMN master_process_no_id INT NULL COMMENT '連結全站製程主檔process_no.ProcessNo，NULL=舊xlsm匯入尚未對應' AFTER process_name",
        "ALTER TABLE pfmea_process ADD COLUMN master_type_id INT NULL COMMENT '連結process_type.process_type_id(製程大項分類)，供批次開放用' AFTER master_process_no_id",
        "ALTER TABLE pfmea_process ADD COLUMN category_name VARCHAR(100) NULL COMMENT '製程大項分類名稱(從process_type同步的顯示用文字)' AFTER master_type_id",
        "ALTER TABLE pfmea_process ADD COLUMN is_enabled TINYINT(1) NOT NULL DEFAULT 1 COMMENT '是否開放本頁(PFMEA)使用；既有資料預設開放，新同步進來的預設關閉待管理員確認' AFTER is_active",
    ] as $alter) { try { $db->exec($alter); } catch (Throwable $e) {} }

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
        function_option_id INT NULL,
        process_id INT NULL COMMENT '製程層級的要求(無功能細分資料時用，如製作表單.xlsm匯入的舊資料)',
        part_d_id INT NULL COMMENT '綁特定料號時填此;留空且part_no_text也空=此功能/製程通用預設值',
        part_no_text VARCHAR(100) NULL COMMENT '無d_setting主鍵的手動輸入料號才用這欄',
        requirement_text VARCHAR(300) NOT NULL COMMENT '要求',
        sort_order INT NOT NULL DEFAULT 0,
        is_active TINYINT(1) NOT NULL DEFAULT 1,
        created_by INT NULL, created_by_name VARCHAR(50) NULL,
        created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
        KEY idx_func (function_option_id),
        KEY idx_proc (process_id)
    ) DEFAULT CHARSET=utf8mb4 COMMENT='PFMEA-功能/製程+料號對應要求清單'");
    // 既有表(function_option_id原本是NOT NULL)要放寬成可空，並補process_id欄位——CREATE TABLE IF NOT
    // EXISTS對已存在的表不生效，2026-08-14使用者要求匯入「製作表單」工作表的料號->製程->要求舊資料，
    // 那份資料沒有功能細分，只能存到製程層級
    foreach ([
        "ALTER TABLE pfmea_requirement_option MODIFY COLUMN function_option_id INT NULL",
        "ALTER TABLE pfmea_requirement_option ADD COLUMN process_id INT NULL COMMENT '製程層級的要求(無功能細分資料時用，如製作表單.xlsm匯入的舊資料)' AFTER function_option_id",
    ] as $alter) { try { $db->exec($alter); } catch (Throwable $e) {} }

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

    // 2026-08-18 使用者要求：AI 產生的整組樣板要在「選擇時」標示出來（列印不印），
    // 才知道哪些內容是 AI 擬的、需要人工複核；空值＝人工建立。
    try { $db->exec("ALTER TABLE pfmea_item_template ADD COLUMN source_tag VARCHAR(20) NULL COMMENT '內容來源標記，如 AI；空＝人工建立' AFTER template_key"); } catch (Throwable $e) {}
    // 2026-08-18：整組樣板原本沒有「要求」欄，導致依樣板帶入分析列時要求永遠空白（使用者回報）
    try { $db->exec("ALTER TABLE pfmea_item_template ADD COLUMN requirement VARCHAR(300) NULL COMMENT '要求' AFTER function_desc"); } catch (Throwable $e) {}

    // 2026-08-14 使用者要求：基本資料內欄位可個別設定對應到其他欄位(如潛在失效模式->失效模式潛在
    // 後果/分類/失效潛在原因、產品名稱->規格描述)，選了來源值就連動帶出對應建議清單；通用單一張表
    // (source_field+source_value+target_field+target_value)取代逐一欄位各建一張表，可填表人新增
    // 自行輸入新值即註冊，僅管理員可刪除，範圍不限於固定的欄位組合。
    $db->exec("CREATE TABLE IF NOT EXISTS pfmea_field_link (
        id INT AUTO_INCREMENT PRIMARY KEY,
        source_field VARCHAR(30) NOT NULL,
        source_value VARCHAR(200) NOT NULL,
        target_field VARCHAR(30) NOT NULL,
        target_value VARCHAR(300) NOT NULL,
        sort_order INT NOT NULL DEFAULT 0,
        is_active TINYINT(1) NOT NULL DEFAULT 1,
        created_by INT NULL, created_by_name VARCHAR(50) NULL,
        created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
        KEY idx_lookup (source_field, source_value(50), target_field)
    ) DEFAULT CHARSET=utf8mb4 COMMENT='PFMEA-欄位個別設定對應清單(基本資料內某欄位值->建議帶出的其他欄位值)'");

    // 2026-08-18 使用者要求：對應組合要一條龍串成階層（像縣市地址）。失效模式潛在後果／分類／
    // 失效潛在原因不再只認「潛在失效模式的文字」全域對應，而是掛在「哪一筆失效模式」（已含製程／
    // 項目／功能層級）底下。scope_fm_id 留空 = 舊的全域純文字對應，查不到專屬值時的退回層，
    // 既有全域對應資料原封不動仍然有效。
    try { $db->exec("ALTER TABLE pfmea_field_link ADD COLUMN scope_fm_id INT NULL COMMENT '掛在哪一筆潛在失效模式(pfmea_process_failure_mode.id)底下；NULL=舊的全域純文字對應(退回層)' AFTER source_value"); } catch (Throwable $e) {}
    try { $db->exec("ALTER TABLE pfmea_field_link ADD KEY idx_scope_fm (scope_fm_id, target_field)"); } catch (Throwable $e) {}

    // 2026-08-18 使用者拍板：料號只管「要求／圖面要求」，其餘層級一律通用。料號先綁定製程代號，
    // 系統自動帶出該製程整套選項(項目/功能)，可快速建多組綁到同一個料號底下，每組再自行輸入
    // 多筆要求（前端卡片下拉列出供挑本次要用的）。組合本身要單獨存一筆——否則「已綁定但還沒輸入
    // 要求」的組合會從畫面上消失，使用者會以為沒存到。
    $db->exec("CREATE TABLE IF NOT EXISTS pfmea_part_binding (
        id INT AUTO_INCREMENT PRIMARY KEY,
        part_d_id INT NULL COMMENT '綁定到 d_setting 主鍵；與 part_no_text 二選一',
        part_no_text VARCHAR(100) NULL COMMENT '無 d_setting 主鍵的手動輸入料號才用這欄',
        process_id INT NOT NULL,
        item_option_id INT NULL COMMENT '組合可只到製程層，也可精確到項目／功能層',
        function_option_id INT NULL,
        sort_order INT NOT NULL DEFAULT 0,
        is_active TINYINT(1) NOT NULL DEFAULT 1,
        created_by INT NULL, created_by_name VARCHAR(50) NULL,
        created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
        KEY idx_part (part_d_id, part_no_text(30)),
        KEY idx_proc (process_id)
    ) DEFAULT CHARSET=utf8mb4 COMMENT='PFMEA-料號綁定的製程／項目／功能組合(要求掛在這下面)'");

    // 要求補上「項目層級」與「所屬料號組合」——原本只能掛在功能或製程兩端，中間的項目層
    // 沒辦法設；bind_id 只用於設定畫面分組顯示，實際查詢仍走 process/item/function + 料號欄位。
    foreach ([
        "ALTER TABLE pfmea_requirement_option ADD COLUMN item_option_id INT NULL COMMENT '項目層級的要求(功能層與製程層之間)' AFTER function_option_id",
        "ALTER TABLE pfmea_requirement_option ADD COLUMN bind_id INT NULL COMMENT '來自哪一筆料號綁定組合(pfmea_part_binding.id)；NULL=舊資料或填表時自動註冊' AFTER part_no_text",
        "ALTER TABLE pfmea_requirement_option ADD KEY idx_item (item_option_id)",
    ] as $alter) { try { $db->exec($alter); } catch (Throwable $e) {} }

    // 2026-08-14 使用者要求(第7段)：評價S/評價O/評價D 依「製程+項目+功能+潛在失效模式+失效模式
    // 潛在效果+嚴重度+失效潛在原因」完整組合建議值，只在新增列時自動帶入(auto-varies)，存檔後鎖定
    // 不再回頭覆蓋；評價S/O/D本身仍要落在1~10(評級對照表整體有效範圍)才允許存成規則。
    $db->exec("CREATE TABLE IF NOT EXISTS pfmea_rating_rule (
        id INT AUTO_INCREMENT PRIMARY KEY,
        process_id INT NOT NULL,
        item_option_id INT NULL,
        function_option_id INT NULL,
        failure_mode VARCHAR(200) NOT NULL,
        failure_effect VARCHAR(200) NOT NULL,
        severity TINYINT NOT NULL,
        failure_cause VARCHAR(200) NOT NULL,
        new_severity TINYINT NOT NULL,
        new_occurrence TINYINT NOT NULL,
        new_detection TINYINT NOT NULL,
        sort_order INT NOT NULL DEFAULT 0,
        is_active TINYINT(1) NOT NULL DEFAULT 1,
        created_by INT NULL, created_by_name VARCHAR(50) NULL,
        created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
        KEY idx_lookup (process_id, failure_mode(50), failure_cause(50))
    ) DEFAULT CHARSET=utf8mb4 COMMENT='PFMEA-評價S/O/D建議規則(組合鍵->建議評價值，新記錄自動帶入)'");

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
/* 分類自動判定（2026-08-18 使用者要求）：嚴重度S 或 發生率O 落在設定的區間內就自動帶「重要特性」，
   否則帶「一般特性」。門檻不可寫死（鐵律4：可自訂的設定不得在別處寫死一份），一律存 system_parameters
   讓管理員在分類欄旁的按鈕改；未設定過時回傳使用者當初指定的初始值 S 5~8 或 O 4~10。 */
function pfmea_classify_rule_get(PDO $db): array {
    $st = $db->prepare("SELECT param_value FROM system_parameters WHERE param_group='PFMEA' AND param_key='classify_rule' LIMIT 1");
    $st->execute();
    $v = $st->fetchColumn();
    $d = $v ? json_decode((string)$v, true) : null;
    if (!is_array($d)) $d = [];
    return [
        'enabled'   => array_key_exists('enabled', $d) ? (int)$d['enabled'] : 1,
        's_min'     => isset($d['s_min']) ? (int)$d['s_min'] : 5,
        's_max'     => isset($d['s_max']) ? (int)$d['s_max'] : 8,
        'o_min'     => isset($d['o_min']) ? (int)$d['o_min'] : 4,
        'o_max'     => isset($d['o_max']) ? (int)$d['o_max'] : 10,
        'hit_text'  => isset($d['hit_text'])  ? (string)$d['hit_text']  : '重要特性',
        'else_text' => isset($d['else_text']) ? (string)$d['else_text'] : '一般特性',
    ];
}
function pfmea_classify_rule_save(PDO $db, array $r, int $uid): void {
    $clean = [
        'enabled'   => !empty($r['enabled']) ? 1 : 0,
        's_min'     => max(1, min(10, (int)($r['s_min'] ?? 5))),
        's_max'     => max(1, min(10, (int)($r['s_max'] ?? 8))),
        'o_min'     => max(1, min(10, (int)($r['o_min'] ?? 4))),
        'o_max'     => max(1, min(10, (int)($r['o_max'] ?? 10))),
        'hit_text'  => trim((string)($r['hit_text'] ?? '重要特性')),
        'else_text' => trim((string)($r['else_text'] ?? '一般特性')),
    ];
    if ($clean['s_min'] > $clean['s_max']) [$clean['s_min'], $clean['s_max']] = [$clean['s_max'], $clean['s_min']];
    if ($clean['o_min'] > $clean['o_max']) [$clean['o_min'], $clean['o_max']] = [$clean['o_max'], $clean['o_min']];
    $json = json_encode($clean, JSON_UNESCAPED_UNICODE);
    $st = $db->prepare("SELECT id FROM system_parameters WHERE param_group='PFMEA' AND param_key='classify_rule' LIMIT 1");
    $st->execute();
    if ($st->fetchColumn()) $db->prepare("UPDATE system_parameters SET param_value=? WHERE param_group='PFMEA' AND param_key='classify_rule'")->execute([$json]);
    else $db->prepare("INSERT INTO system_parameters (param_group, param_key, param_value) VALUES ('PFMEA','classify_rule',?)")->execute([$json]);
}

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
/* $revDate（2026-08-18 使用者要求）：列印修訂履歷第一列「新增文件」的日期要等於這份分析表的
   業務日期，不是它被建進系統那天；後續「修改文件」維持原本邏輯用當天日期，不受影響。 */
function pfmea_revision_add(PDO $db, int $docId, string $content, string $preparedByName, ?string $revDate = null): int {
    $st = $db->prepare("SELECT COALESCE(MAX(rev_no),0)+1 FROM pfmea_revision WHERE doc_id=?");
    $st->execute([$docId]);
    $revNo = (int)$st->fetchColumn();
    $revDate = ($revDate !== null && trim($revDate) !== '') ? substr(trim($revDate), 0, 10) : null;
    if ($revDate) {
        $st = $db->prepare("INSERT INTO pfmea_revision (doc_id, rev_no, rev_date, rev_content, prepared_by_name) VALUES (?,?,?,?,?)");
        $st->execute([$docId, $revNo, $revDate, $content, $preparedByName]);
    } else {
        $st = $db->prepare("INSERT INTO pfmea_revision (doc_id, rev_no, rev_date, rev_content, prepared_by_name) VALUES (?,?,CURDATE(),?,?)");
        $st->execute([$docId, $revNo, $content, $preparedByName]);
    }
    return (int)$db->lastInsertId();
}

/** 業務日期後來被改動時，把「新增文件」那一列（rev_no=1）的日期跟著同步；只動第一列，
 *  後續「修改文件」各列維持它們當初實際修改的日期不變。 */
function pfmea_revision_sync_first_date(PDO $db, int $docId, ?string $bizDate): void {
    $bizDate = ($bizDate !== null && trim($bizDate) !== '') ? substr(trim($bizDate), 0, 10) : null;
    if (!$bizDate) return;
    $db->prepare("UPDATE pfmea_revision SET rev_date=? WHERE doc_id=? AND rev_no=1")->execute([$bizDate, $docId]);
}

/** 記一筆列印紀錄。時間戳一律取 DB 時間——PHP date() 是 UTC、MySQL NOW() 是本地，
 *  混用會讓紀錄差 8 小時（CLAUDE.md 已載明的既有踩坑） */
function pfmea_print_log_add(PDO $db, int $docId, string $kind, int $uid, string $uname): void {
    if (!$docId) return;
    $kind = ($kind === 'batch') ? 'batch' : 'single';
    $db->prepare("INSERT INTO pfmea_print_log (doc_id, print_kind, printed_by, printed_by_name, printed_at)
                  VALUES (?,?,?,?,NOW())")->execute([$docId, $kind, $uid ?: null, $uname]);
}

function pfmea_print_log_list(PDO $db, int $docId): array {
    $st = $db->prepare("SELECT print_kind, printed_by_name, printed_at FROM pfmea_print_log
                         WHERE doc_id=? ORDER BY printed_at DESC, id DESC LIMIT 200");
    $st->execute([$docId]);
    return $st->fetchAll(PDO::FETCH_ASSOC);
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
