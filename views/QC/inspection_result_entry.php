<?php
// c:\MAMP\htdocs\EGsystem\views\QC\inspection_result_entry.php
include_once '../../src/common/_config.php';
include "../../src/common/DBConnection.php";

// 啟用錯誤顯示，方便偵錯
ini_set('display_errors', 1);
error_reporting(E_ALL);

if (session_status() == PHP_SESSION_NONE) {
    session_start();
}

// 檢查登入狀態
if (!isset($_SESSION['user_id']) && !isset($_SESSION['id'])) {
    echo "<script>alert('連線逾時，請重新登入'); window.location.href='../../index.php';</script>";
    exit;
}

$db = new DBConnection();
$pdo = $db->getPDO();

// 1. 強制淨化並取得使用者識別碼，避免空白字元汙染
$user_id = null;
if (isset($_SESSION['user_id'])) {
    $user_id = trim($_SESSION['user_id']);
} elseif (isset($_SESSION['id'])) {
    $user_id = trim($_SESSION['id']);
}

// 2. 初始化變數
$user_cname = '';
$user_id_for_display = $user_id ?: 'N/A'; // 用於畫面上顯示的 ID
$debug_info = "";

// 3. 如果有識別碼，則嘗試從資料庫獲取最新、最正確的資料
if ($user_id) {
    try {
        // 查詢時只用 id 欄位，並只取中文名稱
        $stmt_u = $pdo->prepare(
            "SELECT user_cname FROM user WHERE id = ? LIMIT 1"
        );
        $stmt_u->execute([$user_id]);
        $user_record = $stmt_u->fetch(PDO::FETCH_ASSOC);
        
        if ($user_record && !empty(trim($user_record['user_cname']))) {
            $user_cname = trim($user_record['user_cname']);
            // user_id_for_display 保持從 session 取得的值
        } else {
            // 資料庫中找不到，或名稱為空，退回使用 Session 中的備份值
            $user_cname = trim($_SESSION['user_cname'] ?? $_SESSION['userName'] ?? '');
            $debug_info = "(DB Miss)";
        }
    } catch (Exception $e) { 
        $debug_info = "(DB Error)";
        // 發生錯誤時，也嘗試從 session 取值，確保至少有名字
        $user_cname = trim($_SESSION['user_cname'] ?? $_SESSION['userName'] ?? '');
    }
} else {
    $debug_info = "(No Session ID)";
    // 沒有 ID 時，也嘗試從 session 取值
    $user_cname = trim($_SESSION['user_cname'] ?? $_SESSION['userName'] ?? '');
}

// 4. 最後的防呆機制：如果名稱仍然是空的，提供一個預設值
if (empty($user_cname)) {
    // 如果連 Session 都沒有名字，但有 ID，表示資料不一致
    if ($user_id) {
        $user_cname = "未知使用者";
        // 增加更明確的偵錯訊息
        if ($debug_info === "(DB Miss)") {
            $debug_info = "(DB/Session Miss)";
        } elseif ($debug_info === "(DB Error)") {
            $debug_info = "(DB Err/Session Miss)";
        }
    } else {
        // 連 ID 都沒有，顯示通用未知
        $user_cname = "未知";
    }
}
// =============================================================================
// 資料表初始化 (若不存在則建立)
// =============================================================================

// 0. 檢驗版本 (New)
$pdo->exec("CREATE TABLE IF NOT EXISTS qc_inspection_version (
    version_id INT AUTO_INCREMENT PRIMARY KEY COMMENT '檢驗版本主鍵',
    d_id INT NOT NULL COMMENT '對應 d_setting.d_id',
    version_label VARCHAR(30) NOT NULL COMMENT '版次或發行日識別',
    source_type ENUM('REVISION','ISSUE_DATE') NOT NULL COMMENT '版本來源',
    is_active TINYINT(1) DEFAULT 1 COMMENT '是否啟用',
    UNIQUE KEY uq_did_version (d_id, version_label)
) COMMENT='【新制】QC 檢驗版本主檔'");

// 0. 檢驗表種類 (New)
$pdo->exec("CREATE TABLE IF NOT EXISTS qc_inspection_form_type (
    form_type_id INT AUTO_INCREMENT PRIMARY KEY COMMENT '檢驗表類型主鍵',
    form_code VARCHAR(20) NOT NULL COMMENT '表單代碼：LATHE / GENERAL / OTHER',
    form_name VARCHAR(50) NOT NULL COMMENT '表單名稱：一般進貨檢 / 成品檢驗',
    inspection_stage ENUM('IQC','IPQC','FQC') COMMENT '檢驗階段：IQC=進料檢 IPQC=一般製程檢 FQC=成品檢',
    description VARCHAR(100) COMMENT '用途說明',
    is_active TINYINT(1) DEFAULT 1 COMMENT '是否啟用'
) COMMENT='QC 檢驗表單類型設定'");

// 檢查並新增 inspection_stage 欄位 (若不存在)
try {
    $colChk = $pdo->query("SHOW COLUMNS FROM qc_inspection_form_type LIKE 'inspection_stage'");
    if ($colChk->rowCount() == 0) {
        $pdo->exec("ALTER TABLE qc_inspection_form_type ADD COLUMN inspection_stage ENUM('IQC','IPQC','FQC') COMMENT '檢驗階段：IQC=進料檢 IPQC=一般製程檢 FQC=成品檢'");
    } else {
        // 檢查是否包含 PKG，若無則修改
        $row = $colChk->fetch(PDO::FETCH_ASSOC);
        if (strpos($row['Type'], "'PKG'") === false) {
            $pdo->exec("ALTER TABLE qc_inspection_form_type MODIFY COLUMN inspection_stage ENUM('IQC','IPQC','FQC','PKG') COMMENT '檢驗階段：IQC=進料檢 IPQC=一般製程檢 FQC=成品檢 PKG=包裝檢'");
        }
    }
} catch (Exception $e) { /* 忽略錯誤 */ }

// 1. 抽驗規則 (Updated)
$pdo->exec("CREATE TABLE IF NOT EXISTS qc_sampling_rule (
    rule_id INT AUTO_INCREMENT PRIMARY KEY COMMENT '抽驗規則主鍵',
    min_qty INT NOT NULL COMMENT '最小進貨數量',
    max_qty INT NOT NULL COMMENT '最大進貨數量',
    sample_qty INT NOT NULL COMMENT '抽驗數量',
    is_active TINYINT(1) DEFAULT 1
) COMMENT='【新制】QC 抽驗規則'");

// 2. 檢驗表主檔 (表頭) (Updated)
$pdo->exec("CREATE TABLE IF NOT EXISTS qc_check_form (
    qc_form_id INT AUTO_INCREMENT PRIMARY KEY COMMENT '新QC檢驗表主鍵',
    bom_ing_fid INT NOT NULL COMMENT '對應 bom_ing.bom_ing_fid',
    d_id INT NOT NULL COMMENT '料號（對應 d_setting.d_id）',
    version_id INT NOT NULL COMMENT '檢驗版本（qc_inspection_version.version_id）',
    form_type_id INT NOT NULL COMMENT '檢驗表類型（車床/一般）',
    incoming_qty INT NOT NULL COMMENT '本次進貨數量',
    sample_qty INT NOT NULL COMMENT '抽驗數量',
    ng_qty INT DEFAULT 0 COMMENT 'NG數量',
    check_result ENUM('OK','NG','HOLD') DEFAULT 'HOLD' COMMENT '整體檢驗結果',
    status ENUM('DRAFT','SUBMITTED','LOCKED') DEFAULT 'DRAFT' COMMENT '填寫狀態：草稿/送出/鎖定',
    process_name VARCHAR(100) NULL COMMENT '檢驗分頁名稱，對應 qc_bom_process_page_map.process_name',
    check_date DATE NULL COMMENT '檢驗日期',
    created_by CHAR(11) NOT NULL COMMENT '建立人員',
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP COMMENT '建立時間'
) COMMENT='【新制】QC 進貨檢驗表（不影響 qc_check）'");

// 檢查並新增 packaging_data 欄位 (若不存在)
try {
    $colChk = $pdo->query("SHOW COLUMNS FROM qc_check_form LIKE 'packaging_data'");
    if ($colChk->rowCount() == 0) {
        $pdo->exec("ALTER TABLE qc_check_form ADD COLUMN packaging_data TEXT NULL COMMENT '包裝方式詳細資料(JSON)'");
    }
} catch (Exception $e) { /* 忽略錯誤 */ }

// 檢查並新增 ng_qty 欄位 (若不存在)
try {
    $colChk = $pdo->query("SHOW COLUMNS FROM qc_check_form LIKE 'ng_qty'");
    if ($colChk->rowCount() == 0) {
        $pdo->exec("ALTER TABLE qc_check_form ADD COLUMN ng_qty INT DEFAULT 0 COMMENT 'NG數量'");
    }
} catch (Exception $e) { /* 忽略錯誤 */ }

// 3. 實測資料 (明細) (Updated)
$pdo->exec("CREATE TABLE IF NOT EXISTS qc_measurement (
    measurement_id INT AUTO_INCREMENT PRIMARY KEY COMMENT '實測主鍵',
    qc_form_id INT NOT NULL COMMENT '對應 qc_check_form.qc_form_id',
    item_id INT NOT NULL COMMENT '對應 qc_inspection_item.item_id',
    sample_no INT NOT NULL COMMENT '第幾抽',
    measured_value VARCHAR(50) NOT NULL COMMENT '實測值',
    result ENUM('OK','NG') NOT NULL,
    tool_id INT NULL COMMENT '實際使用的量具ID (qc_tool.Tool_id)',
    created_by CHAR(11) NOT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
) COMMENT='【新制】QC 實測資料（多筆）'");

// 檢查並新增 tool_id 欄位 (若不存在)
try {
    $colChk = $pdo->query("SHOW COLUMNS FROM qc_measurement LIKE 'tool_id'");
    if ($colChk->rowCount() == 0) {
        $pdo->exec("ALTER TABLE qc_measurement ADD COLUMN tool_id INT NULL COMMENT '實際使用的量具ID (qc_tool.Tool_id)'");
    }
} catch (Exception $e) { /* 忽略錯誤 */ }

// 檢查並新增 remark 欄位 (若不存在，用於儲存處置狀況)
try {
    $colChk = $pdo->query("SHOW COLUMNS FROM qc_measurement LIKE 'remark'");
    if ($colChk->rowCount() == 0) {
        $pdo->exec("ALTER TABLE qc_measurement ADD COLUMN remark VARCHAR(255) NULL COMMENT '單項備註/處置狀況'");
    }
} catch (Exception $e) { /* 忽略錯誤 */ }

// 12. 包裝檢驗主檔 (New - User Request)
$pdo->exec("CREATE TABLE IF NOT EXISTS qc_packing_inspection (
    packing_inspection_id INT AUTO_INCREMENT PRIMARY KEY COMMENT '包裝/出貨檢驗主檔ID',
    bom_ing_fid INT NOT NULL COMMENT '主要關聯BOM',
    inspection_date DATE NOT NULL COMMENT '檢驗日期',
    customer_name VARCHAR(100) NULL COMMENT '客戶名稱',
    order_qty INT NULL COMMENT '訂單數量',
    inspected_qty INT NULL COMMENT '實際全檢數量',
    ok_qty INT NULL COMMENT '合格數量',
    ng_qty INT NOT NULL DEFAULT 0 COMMENT 'NG總數',
    judgement ENUM('PASS','FAIL','PENDING') NOT NULL DEFAULT 'PENDING' COMMENT '判定結果',
    inspector VARCHAR(50) NULL COMMENT '品檢人員',
    packer VARCHAR(50) NULL COMMENT '包裝人員',
    remark TEXT NULL COMMENT '整張表備註',
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP COMMENT '建立時間'
) COMMENT='包裝/出貨檢驗主紀錄'");

// 13. 包裝檢驗明細 (JSON)
$pdo->exec("CREATE TABLE IF NOT EXISTS qc_packing_inspection_data (
    data_id INT AUTO_INCREMENT PRIMARY KEY,
    packing_inspection_id INT NOT NULL,
    data_json JSON NOT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (packing_inspection_id) REFERENCES qc_packing_inspection(packing_inspection_id) ON DELETE CASCADE
) COMMENT='包裝/出貨檢驗明細(JSON)'");

// 4. 檢驗項目與標準 (New)
$pdo->exec("CREATE TABLE IF NOT EXISTS qc_inspection_item (
    item_id INT AUTO_INCREMENT PRIMARY KEY COMMENT '檢驗項目主鍵',
    version_id INT NOT NULL COMMENT '對應 qc_inspection_version.version_id',
    form_type_id INT NOT NULL COMMENT '對應 qc_inspection_form_type.form_type_id',
    item_code VARCHAR(10) NOT NULL COMMENT 'A / B / 1 / 2',
    item_name VARCHAR(100) NOT NULL COMMENT '檢驗項目',
    standard_text VARCHAR(100) NOT NULL COMMENT '檢驗標準',
    min_value DECIMAL(10,3) NULL,
    max_value DECIMAL(10,3) NULL,
    plus_tolerance DECIMAL(10,3) NULL,
    minus_tolerance DECIMAL(10,3) NULL,
    result_type ENUM('NUMERIC','OKNG') NOT NULL,
    sort_order INT DEFAULT 1,
    process_name VARCHAR(100) NULL COMMENT '製程名稱',
    is_active TINYINT(1) DEFAULT 1
) COMMENT='【新制】QC 檢驗項目與標準'");

// 5. 備註紀錄表 (Updated)
$pdo->exec("CREATE TABLE IF NOT EXISTS qc_check_form_remark (
    remark_id INT AUTO_INCREMENT PRIMARY KEY COMMENT '備註紀錄主鍵',
    qc_form_id INT NOT NULL COMMENT '對應 qc_check_form.qc_form_id',
    remark TEXT NOT NULL COMMENT '備註內容',
    action_type ENUM('CREATE','UPDATE','APPEND') NOT NULL COMMENT '備註行為',
    created_by CHAR(11) NOT NULL COMMENT '備註人員',
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP COMMENT '備註時間',
    CONSTRAINT fk_qc_remark_form
        FOREIGN KEY (qc_form_id)
        REFERENCES qc_check_form(qc_form_id)
        ON DELETE CASCADE
) COMMENT='QC檢驗備註歷程表'");

// 6. BOM 製程 → 檢驗分頁對應表 (Updated)
$pdo->exec("CREATE TABLE IF NOT EXISTS qc_bom_process_page_map (
    id INT AUTO_INCREMENT PRIMARY KEY COMMENT 'BOM製程分頁對應主鍵',
    bom VARCHAR(30) NOT NULL COMMENT '對應 bom.bom',
    process_no INT NOT NULL COMMENT '製程代號',
    version_id INT NOT NULL COMMENT '對應 qc_inspection_version.version_id',
    form_type_id INT NOT NULL COMMENT '對應 qc_form_type.form_type_id',
    process_name VARCHAR(100) NOT NULL COMMENT '檢驗分頁名稱，對應 qc_inspection_item.process_name',
    created_by CHAR(11) NOT NULL COMMENT '建檔人員',
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP COMMENT '建檔時間',
    updated_by CHAR(11) COMMENT '最後修改人員',
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP COMMENT '最後修改時間',
    CONSTRAINT uq_bom_process UNIQUE (bom, process_no, process_name),
    FOREIGN KEY (version_id) REFERENCES qc_inspection_version(version_id),
    FOREIGN KEY (form_type_id) REFERENCES qc_inspection_form_type(form_type_id)
) COMMENT='BOM + 製程對應 IPQC 檢驗分頁（穩定不受批次影響）'");

// 自動修復 Foreign Key 指向錯誤的問題 (qc_form_type -> qc_inspection_form_type)
try {
    $sqlFK = "SELECT CONSTRAINT_NAME 
              FROM INFORMATION_SCHEMA.KEY_COLUMN_USAGE 
              WHERE TABLE_SCHEMA = DATABASE() 
                AND TABLE_NAME = 'qc_bom_process_page_map' 
                AND COLUMN_NAME = 'form_type_id' 
                AND REFERENCED_TABLE_NAME = 'qc_form_type'";
    $stmtFK = $pdo->query($sqlFK);
    $fkName = $stmtFK->fetchColumn();
    if ($fkName) {
        $pdo->exec("ALTER TABLE qc_bom_process_page_map DROP FOREIGN KEY `$fkName`");
        $pdo->exec("ALTER TABLE qc_bom_process_page_map ADD CONSTRAINT fk_qc_bom_map_form_type FOREIGN KEY (form_type_id) REFERENCES qc_inspection_form_type(form_type_id)");
    }
} catch (Exception $e) { /* 忽略錯誤 */ }

// 7. 進貨批次 (New)
$pdo->exec("CREATE TABLE IF NOT EXISTS qc_incoming_batch (
    batch_id INT AUTO_INCREMENT PRIMARY KEY COMMENT '進貨批次主鍵',
    bom_ing_fid INT NOT NULL COMMENT '對應 bom_ing.bom_ing_fid',
    version_id INT NOT NULL COMMENT '對應 qc_inspection_version.version_id',
    form_type_id INT NOT NULL COMMENT '本次使用的檢驗表類型',
    incoming_qty INT NOT NULL COMMENT '本次進貨數量',
    sample_qty INT NOT NULL COMMENT '抽驗數量（由抽驗規則計算）',
    supplier_id VARCHAR(30) COMMENT '廠商ID（對應 bom_ing.maker_id）',
    created_by CHAR(11) NOT NULL COMMENT '建立人員',
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP COMMENT '建立時間'
) COMMENT='QC 進貨檢驗批次'");

// 8. 幾何公差 / 特殊項目字典 (New)
$pdo->exec("CREATE TABLE IF NOT EXISTS qc_special_characteristic (
    characteristic_id INT AUTO_INCREMENT PRIMARY KEY COMMENT '特殊特性主鍵',
    name VARCHAR(50) NOT NULL COMMENT '幾何公差名稱（如：圓度、同心度）',
    symbol VARCHAR(20) COMMENT '符號（如 ⌀、⏊，前端顯示用）',
    description VARCHAR(100) COMMENT '說明',
    icon_path VARCHAR(100) COMMENT '圖示路徑（前端可用）',
    is_active TINYINT(1) DEFAULT 1 COMMENT '是否啟用'
) COMMENT='QC 幾何公差與特殊檢驗項目字典'");

// 9. 版本 × 可用表單 (New)
$pdo->exec("CREATE TABLE IF NOT EXISTS qc_version_form_map (
    id INT AUTO_INCREMENT PRIMARY KEY COMMENT '主鍵',
    version_id INT NOT NULL COMMENT '對應 qc_inspection_version.version_id',
    form_type_id INT NOT NULL COMMENT '對應 qc_inspection_form_type.form_type_id',
    is_enabled TINYINT(1) DEFAULT 1 COMMENT '是否允許此料號版本使用該檢驗表'
) COMMENT='料號版本可使用的檢驗表設定'");

// 10. 檢驗項目可用量具類型 (New)
$pdo->exec("CREATE TABLE IF NOT EXISTS qc_inspection_item_tool_type (
    item_tool_type_id INT AUTO_INCREMENT PRIMARY KEY COMMENT '檢驗項目可用量具類型',
    item_id INT NOT NULL COMMENT '對應 qc_inspection_item.item_id',
    QC_Tool_List_id INT NOT NULL COMMENT '對應 qc_tool_list.QC_Tool_List_id',
    is_primary TINYINT(1) DEFAULT 1 COMMENT '是否主要建議量具',
    remark VARCHAR(100) NULL COMMENT '補充說明',
    FOREIGN KEY (item_id) REFERENCES qc_inspection_item(item_id),
    FOREIGN KEY (QC_Tool_List_id) REFERENCES qc_tool_list(QC_Tool_List_id),
    UNIQUE KEY uk_item_tool_type (item_id, QC_Tool_List_id)
) COMMENT='QC 檢驗項目允許使用的量具類型'");

// 11. 異常單 (僅建立結構，後續邏輯使用)
$pdo->exec("CREATE TABLE IF NOT EXISTS qc_abnormal_order (
    id INT AUTO_INCREMENT PRIMARY KEY,
    qc_check_id INT NOT NULL COMMENT '對應 qc_check_form.qc_form_id',
    abnormal_order_no VARCHAR(20) NOT NULL,
    occurrence_date DATE NOT NULL,
    defect_type ENUM('人', '機器', '材料', '方法', '其他') NOT NULL,
    qc_check_status ENUM('ng', 'QQ', 'ok', 'AOD') NOT NULL,
    sqty MEDIUMINT NOT NULL,
    is_closed TINYINT(1) DEFAULT 0,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
)");

// 檢查並修改 form_type_id 欄位型態以支援多選 (若尚未修改)
try {
    $colMeta = $pdo->query("SELECT DATA_TYPE FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'qc_check_form' AND COLUMN_NAME = 'form_type_id'")->fetchColumn();
    if (strtoupper($colMeta) === 'INT') {
        $pdo->exec("ALTER TABLE qc_check_form MODIFY form_type_id VARCHAR(255) NOT NULL COMMENT '檢驗表類型(可多選)'");
    }
} catch (Exception $e) { /* 忽略錯誤 */ }

// 檢查並新增 check_date 欄位 (若不存在)
try {
    $colChk = $pdo->query("SHOW COLUMNS FROM qc_check_form LIKE 'check_date'");
    if ($colChk->rowCount() == 0) {
        $pdo->exec("ALTER TABLE qc_check_form ADD COLUMN check_date DATE NULL COMMENT '檢驗日期'");
    }
} catch (Exception $e) { /* 忽略錯誤 */ }

// 12. 建立檢視表 (Views) - 方便查詢詳情與報表
$pdo->exec("CREATE OR REPLACE VIEW vw_qc_record_header AS
    SELECT 
        q.qc_form_id,
        q.bom_ing_fid,
        q.d_id,
        q.version_id,
        q.form_type_id,
        q.incoming_qty,
        q.sample_qty,
        q.check_result,
        q.status,
        q.process_name,
        q.check_date,
        q.created_by,
        q.created_at,
        u.user_cname,
        b.bom AS bom_no,
        b.maker_id AS maker_name,
        b.process_no,
        b.sqty AS order_qty,
        d.D_Setting_Id AS part_no,
        c.customer AS client_name,
        v.version_label,
        (SELECT GROUP_CONCAT(form_name SEPARATOR ', ') FROM qc_inspection_form_type WHERE FIND_IN_SET(form_type_id, q.form_type_id)) as form_name
    FROM qc_check_form q
    LEFT JOIN user u ON (TRIM(q.created_by) = u.id OR TRIM(q.created_by) = u.user_cname)
    LEFT JOIN bom_ing b ON q.bom_ing_fid = b.bom_ing_fid
    LEFT JOIN d_setting d ON q.d_id = d.d_id
    LEFT JOIN customer_list c ON d.Customer_Id = c.customer_id
    LEFT JOIN qc_inspection_version v ON q.version_id = v.version_id
");

$pdo->exec("CREATE OR REPLACE VIEW vw_qc_record_details AS
    SELECT 
        m.measurement_id,
        m.qc_form_id,
        m.item_id,
        m.sample_no,
        m.measured_value,
        m.result,
        m.tool_id,
        m.remark,
        m.created_by,
        m.created_at,
        i.item_code AS i_code,
        i.item_name AS i_name,
        i.standard_text AS i_std,
        i.min_value,
        i.max_value,
        i.result_type,
        i.sort_order,
        t.Tool_No,
        tl.QC_Tool,
        b.bom AS bom_no,
        b.maker_id AS maker_name,
        d.D_Setting_Id AS part_no,
        c.customer AS client_name
    FROM qc_measurement m
    LEFT JOIN qc_inspection_item i ON m.item_id = i.item_id
    LEFT JOIN qc_tool t ON m.tool_id = t.Tool_id
    LEFT JOIN qc_tool_list tl ON t.QC_Tool_List_id = tl.QC_Tool_List_id
    LEFT JOIN qc_check_form q ON m.qc_form_id = q.qc_form_id
    LEFT JOIN bom_ing b ON q.bom_ing_fid = b.bom_ing_fid
    LEFT JOIN d_setting d ON q.d_id = d.d_id
    LEFT JOIN customer_list c ON d.Customer_Id = c.customer_id
");

// =============================================================================
// 後端 API 處理區塊
// =============================================================================
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action'])) {
    header('Content-Type: application/json');

    try {
        // 1. 搜尋 BOM / 料號
        if ($_POST['action'] === 'search_bom') {
            $kw = $_POST['keyword'] ?? '';
            $sql = "SELECT bi.bom_ing_fid, bi.bom_ing_id, bi.bom, bi.sqty, bi.process_no, d.d_id, d.D_Setting_Id, c.customer AS Client_Name, d.Revision, d.Issue_Date 
                    FROM vw_bom_ing bi 
                    LEFT JOIN bom b ON bi.bom = b.bom 
                    LEFT JOIN d_setting d ON b.d_id = d.D_Setting_Id 
                    LEFT JOIN customer_list c ON d.Customer_Id = c.customer_id 
                    WHERE bi.bom_ing_id LIKE :kw 
                       OR bi.bom LIKE :kw 
                       OR d.D_Setting_Id LIKE :kw 
                       OR c.customer LIKE :kw 
                    ORDER BY bi.bom_ing_id DESC LIMIT 20";
            $stmt = $pdo->prepare($sql);
            $stmt->execute([':kw' => "%$kw%"]);
            echo json_encode(['success' => true, 'data' => $stmt->fetchAll(PDO::FETCH_ASSOC)]);
            exit;
        }

        // 1.5 獲取 BOM 對應的製程列表
        if ($_POST['action'] === 'get_bom_processes') {
            $bom = $_POST['bom'];
            $sql = "SELECT bi.bom_ing_fid, bi.bom_sn, bi.process_no, pn.ProcessName, bi.maker_id,
                    (SELECT MAX(created_at) FROM qc_check_form WHERE bom_ing_fid = bi.bom_ing_fid) as last_check_date
                    FROM bom_ing bi
                    LEFT JOIN process_no pn ON bi.process_no = pn.ProcessNo
                    WHERE bi.bom = ?
                    ORDER BY bi.bom_sn ASC";
            $stmt = $pdo->prepare($sql);
            $stmt->execute([$bom]);
            echo json_encode(['success' => true, 'data' => $stmt->fetchAll(PDO::FETCH_ASSOC)]);
            exit;
        }

        // 1.6 獲取 BOM 圖檔
        if ($_POST['action'] === 'get_bom_files') {
            $bom = $_POST['bom'];
            $scan_dir = 'Z:/BOM/'; // 實體路徑 (NAS 映射，供 PHP 掃描用)
            $url_dir = '/nas/';    // 網頁讀取路徑 (Apache Alias，供前端顯示用)
            $files = [];
            if (is_dir($scan_dir)) {
                $allFiles = scandir($scan_dir);
                foreach ($allFiles as $f) {
                    if ($f === '.' || $f === '..') continue;
                    // 檢查是否以 BOM 開頭
                    if (strpos($f, $bom) === 0) {
                        $ext = strtolower(pathinfo($f, PATHINFO_EXTENSION));
                        if (in_array($ext, ['jpg', 'jpeg', 'png', 'pdf'])) {
                            $files[] = [
                                'name' => $f,
                                'path' => $url_dir . $f,
                                'mtime' => filemtime($scan_dir . $f),
                                'is_exact' => (pathinfo($f, PATHINFO_FILENAME) === $bom)
                            ];
                        }
                    }
                }
            }
            
            // 排序: 完全符合優先，其次按時間新到舊
            usort($files, function($a, $b) {
                if ($a['is_exact'] !== $b['is_exact']) {
                    return $b['is_exact'] ? 1 : -1; // exact first
                }
                return $b['mtime'] - $a['mtime'];
            });

            echo json_encode(['success' => true, 'files' => $files]);
            exit;
        }

        // 1.7 獲取 BOM 製程對應設定 (Process Map)
        if ($_POST['action'] === 'get_process_map') {
            $bom = $_POST['bom'];
            $stmt = $pdo->prepare("SELECT process_no, process_name FROM qc_bom_process_page_map WHERE bom = ?");
            $stmt->execute([$bom]);
            echo json_encode(['success' => true, 'data' => $stmt->fetchAll(PDO::FETCH_ASSOC)]);
            exit;
        }

        // 1.8 儲存 BOM 製程對應設定
        if ($_POST['action'] === 'save_process_map') {
            $bom = $_POST['bom'];
            $pNo = $_POST['process_no'];
            $pName = $_POST['process_name'];
            $verId = $_POST['version_id'];
            $formTypeId = $_POST['form_type_id']; // 必填，否則資料庫會報錯
            
            // 使用 Transaction 確保原子性：先刪除舊對應，再新增新對應 (避免 Unique Key 衝突或邏輯錯誤)
            $pdo->beginTransaction();
            $delSql = "DELETE FROM qc_bom_process_page_map WHERE bom = ? AND process_no = ?";
            $pdo->prepare($delSql)->execute([$bom, $pNo]);
            
            $insSql = "INSERT INTO qc_bom_process_page_map (bom, process_no, version_id, form_type_id, process_name, created_by) VALUES (?, ?, ?, ?, ?, ?)";
            $pdo->prepare($insSql)->execute([$bom, $pNo, $verId, $formTypeId, $pName, $user_id]);
            $pdo->commit();
            
            echo json_encode(['success' => true]);
            exit;
        }

        // 1.9 解除 BOM 製程對應設定 (Delete Process Map)
        if ($_POST['action'] === 'delete_process_map') {
            $bom = $_POST['bom'];
            $pNo = $_POST['process_no'];
            $stmt = $pdo->prepare("DELETE FROM qc_bom_process_page_map WHERE bom = ? AND process_no = ?");
            $stmt->execute([$bom, $pNo]);
            echo json_encode(['success' => true]);
            exit;
        }

        // 2. 獲取料號的版本與檢驗表類型
        if ($_POST['action'] === 'get_part_options') {
            $d_id = $_POST['d_id'];

            // 版本
            $stmt = $pdo->prepare("SELECT * FROM qc_inspection_version WHERE d_id = ? AND is_active = 1 ORDER BY version_id DESC");
            $stmt->execute([$d_id]);
            $versions = $stmt->fetchAll(PDO::FETCH_ASSOC);

            // 檢驗表類型 (全部)
            $stmt = $pdo->query("SELECT * FROM qc_inspection_form_type WHERE is_active = 1");
            $formTypes = $stmt->fetchAll(PDO::FETCH_ASSOC);

            // 版本對應的啟用表單
            $stmt = $pdo->prepare("SELECT version_id, form_type_id FROM qc_version_form_map WHERE is_enabled = 1");
            $stmt->execute();
            $map = $stmt->fetchAll(PDO::FETCH_ASSOC);

            echo json_encode(['success' => true, 'versions' => $versions, 'formTypes' => $formTypes, 'map' => $map]);
            exit;
        }

        // 3. 計算抽樣數
        if ($_POST['action'] === 'calculate_sample') {
            $qty = (int)$_POST['qty'];
            $stmt = $pdo->prepare("SELECT sample_qty FROM qc_sampling_rule WHERE ? BETWEEN min_qty AND max_qty ORDER BY min_qty DESC LIMIT 1");
            $stmt->execute([$qty]);
            $res = $stmt->fetch(PDO::FETCH_ASSOC);
            $sample = $res ? $res['sample_qty'] : 0; // 若無規則預設 0 (或全檢?)
            echo json_encode(['success' => true, 'sample_qty' => $sample]);
            exit;
        }

        // 4. 獲取檢驗項目與所需量具
        if ($_POST['action'] === 'get_inspection_data') {
            $verId = $_POST['version_id'];
            $formIds = $_POST['form_type_id'] ?? []; // 可能是陣列

            // 若未指定 form_type_id，則撈取該版本下所有啟用的 form_type
            if (empty($formIds)) {
                $stmtMap = $pdo->prepare("SELECT form_type_id FROM qc_version_form_map WHERE version_id = ? AND is_enabled = 1");
                $stmtMap->execute([$verId]);
                $formIds = $stmtMap->fetchAll(PDO::FETCH_COLUMN);
            }

            if (empty($formIds)) {
                echo json_encode(['success' => true, 'items' => [], 'tools' => []]);
                exit;
            }

            $formIds = array_map('intval', $formIds); // 安全過濾
            $inQuery = implode(',', array_fill(0, count($formIds), '?'));
            
            $params = array_merge([$verId], $formIds);

            // 取得項目
            $sql = "SELECT i.*, itt.QC_Tool_List_id, itt.is_primary, ft.form_name, ft.inspection_stage
                    FROM qc_inspection_item i
                    LEFT JOIN qc_inspection_item_tool_type itt ON i.item_id = itt.item_id
                    LEFT JOIN qc_inspection_form_type ft ON i.form_type_id = ft.form_type_id
                    WHERE i.version_id = ? AND i.form_type_id IN ($inQuery)
                    ORDER BY i.form_type_id ASC, i.sort_order ASC, itt.is_primary DESC";
            $stmt = $pdo->prepare($sql);

            $stmt->execute($params);
            $raw = $stmt->fetchAll(PDO::FETCH_ASSOC);

            // 整理項目與量具需求
            $items = [];
            $requiredToolCats = []; // 紀錄此表單需要哪些量具種類

            foreach ($raw as $row) {
                $iid = $row['item_id'];
                if (!isset($items[$iid])) {
                    // 格式化數值
                    if ($row['min_value'] !== null) $row['min_value'] = (float)$row['min_value'];
                    if ($row['max_value'] !== null) $row['max_value'] = (float)$row['max_value'];
                    if ($row['plus_tolerance'] !== null) $row['plus_tolerance'] = (float)$row['plus_tolerance'];
                    if ($row['minus_tolerance'] !== null) $row['minus_tolerance'] = (float)$row['minus_tolerance'];

                    $items[$iid] = $row;
                    $items[$iid]['tool_cats'] = [];
                }
                if ($row['QC_Tool_List_id']) {
                    $items[$iid]['tool_cats'][] = [
                        'id' => $row['QC_Tool_List_id'],
                        'is_primary' => $row['is_primary']
                    ];
                    if (!in_array($row['QC_Tool_List_id'], $requiredToolCats)) {
                        $requiredToolCats[] = $row['QC_Tool_List_id'];
                    }
                }
            }

            // 獲取所需量具種類的詳細資訊與可用實體編號
            $toolData = [];
            if (!empty($requiredToolCats)) {
                $inQuery = implode(',', array_fill(0, count($requiredToolCats), '?'));

                // 種類資訊
                $stmt = $pdo->prepare("SELECT * FROM qc_tool_list WHERE QC_Tool_List_id IN ($inQuery)");
                $stmt->execute($requiredToolCats);
                $cats = $stmt->fetchAll(PDO::FETCH_ASSOC);

                // 實體編號
                $stmt = $pdo->prepare("SELECT * FROM qc_tool WHERE QC_Tool_List_id IN ($inQuery) ORDER BY Tool_No ASC");
                $stmt->execute($requiredToolCats);
                $instances = $stmt->fetchAll(PDO::FETCH_ASSOC);

                foreach ($cats as $c) {
                    $cId = $c['QC_Tool_List_id'];
                    $myInstances = array_filter($instances, function ($t) use ($cId) {
                        return $t['QC_Tool_List_id'] == $cId;
                    });
                    $toolData[] = [
                        'cat_id' => $cId,
                        'cat_name' => $c['QC_Tool'],
                        'instances' => array_values($myInstances)
                    ];
                }
            }

            echo json_encode(['success' => true, 'items' => array_values($items), 'tools' => $toolData]);
            exit;
        }

        // 4.5 獲取已儲存狀態 (用於顯示圖示與歷史)
        if ($_POST['action'] === 'get_saved_status') {
            $bom = $_POST['bom'];
            
            // 1. 一般檢驗紀錄
            $sql = "SELECT CAST(q.qc_form_id AS CHAR) as id, q.bom_ing_fid, q.process_name, q.form_type_id, q.check_result, q.check_date, q.created_at, q.incoming_qty, bi.process_no, 'STD' as type, COALESCE(u.user_cname, q.created_by) as inspector 
                    FROM qc_check_form q
                    JOIN bom_ing bi ON q.bom_ing_fid = bi.bom_ing_fid
                    LEFT JOIN user u ON TRIM(q.created_by) = u.id
                    WHERE bi.bom = ? AND q.status != 'DRAFT'";
            
            // 2. 包裝檢驗紀錄 (UNION)
            $sql .= " UNION ALL
                      SELECT CONCAT('pkg_', p.packing_inspection_id) as id, p.bom_ing_fid, 'Packaging' as process_name, 'PKG' as form_type_id, p.judgement as check_result, p.inspection_date as check_date, p.created_at, p.order_qty as incoming_qty, bi.process_no, 'PKG' as type, p.inspector as inspector
                      FROM qc_packing_inspection p
                      JOIN bom_ing bi ON p.bom_ing_fid = bi.bom_ing_fid
                      WHERE bi.bom = ?
                      ORDER BY created_at DESC";

            $stmt = $pdo->prepare($sql);
            $stmt->execute([$bom, $bom]);
            echo json_encode(['success' => true, 'data' => $stmt->fetchAll(PDO::FETCH_ASSOC)]);
            exit;
        }

        // 5. 儲存檢驗結果
        if ($_POST['action'] === 'save_result') {
            $pdo->beginTransaction();

            $header = $_POST['header'];
            $details = $_POST['details']; // Array of measurements
            $remark = $_POST['remark'] ?? '';
            $packagingData = $_POST['packaging_data'] ?? null; // New: 包裝資料
            $ngQty = $_POST['ng_qty'] ?? 0; // New: NG 數量
            $userId = $user_id ?? 'System';
            $qcFormId = $_POST['qc_form_id'] ?? null; // New parameter

            // --- 包裝檢驗 (PKG) 獨立儲存邏輯 ---
            if ($packagingData) {
                // 判斷是否為更新 (若 qcFormId 帶有 'pkg_' 前綴或純數字但邏輯上是PKG)
                // 這裡簡化：若有 packagingData 就存入新表
                
                // 1. 寫入主表 qc_packing_inspection
                $sql = "INSERT INTO qc_packing_inspection 
                        (bom_ing_fid, inspection_date, order_qty, inspected_qty, ok_qty, ng_qty, judgement, inspector, remark)
                        VALUES (?, NOW(), ?, ?, ?, ?, ?, ?, ?)";
                $stmt = $pdo->prepare($sql);
                // 計算合格數 (訂單數 - NG數)
                $orderQty = intval($header['incoming_qty']);
                $ngQtyVal = intval($ngQty);
                $okQty = $orderQty - $ngQtyVal;
                $judgement = ($ngQtyVal > 0) ? 'FAIL' : 'PASS';

                $stmt->execute([
                    $header['bom_ing_fid'],
                    $orderQty,
                    $orderQty, // 全檢，所以檢驗數=訂單數
                    $okQty,
                    $ngQtyVal,
                    $judgement,
                    $userId,
                    $remark
                ]);
                $pkgId = $pdo->lastInsertId();

                // 2. 寫入明細表 qc_packing_inspection_data (JSON)
                $sqlJson = "INSERT INTO qc_packing_inspection_data (packing_inspection_id, data_json) VALUES (?, ?)";
                $pdo->prepare($sqlJson)->execute([$pkgId, json_encode($packagingData)]);

                $pdo->commit();
                echo json_encode(['success' => true, 'message' => '包裝檢驗紀錄已儲存', 'form_id' => 'pkg_' . $pkgId]);
                exit;
            }
            // --- 一般檢驗 (IPQC/FQC) 邏輯 ---

            $formTypeIds = $header['form_type_id'];
            if (is_array($formTypeIds)) $formTypeIds = implode(',', $formTypeIds); // 雖然現在可能是單一，但保持相容

            if ($qcFormId) {
                // Update existing record
                $sql = "UPDATE qc_check_form SET 
                        incoming_qty = ?, sample_qty = ?, ng_qty = ?, check_result = ?, status = 'SUBMITTED', check_date = NOW(), created_by = ?, packaging_data = ?
                        WHERE qc_form_id = ?";
                $stmt = $pdo->prepare($sql);
                $stmt->execute([
                    $header['incoming_qty'],
                    $header['sample_qty'],
                    $ngQty,
                    $header['check_result'],
                    $userId,
                    $packagingData ? json_encode($packagingData) : null,
                    $qcFormId
                ]);
                $formId = $qcFormId;
                
                // Clear old measurements to replace them
                $pdo->prepare("DELETE FROM qc_measurement WHERE qc_form_id = ?")->execute([$formId]);
            } else {
                // Insert Header
                $sql = "INSERT INTO qc_check_form 
                        (bom_ing_fid, d_id, version_id, form_type_id, process_name, incoming_qty, sample_qty, ng_qty, check_result, status, check_date, created_by, packaging_data)
                        VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, 'SUBMITTED', NOW(), ?, ?)";
                $stmt = $pdo->prepare($sql);
                $stmt->execute([
                    $header['bom_ing_fid'],
                    $header['d_id'],
                    $header['version_id'],
                    $formTypeIds,
                    $header['process_name'] ?? null,
                    $header['incoming_qty'],
                    $header['sample_qty'],
                    $ngQty,
                    $header['check_result'], // OK, NG, HOLD
                    $userId,
                    $packagingData ? json_encode($packagingData) : null
                ]);
                $formId = $pdo->lastInsertId();
            }

            // Insert Details
            $sqlDetail = "INSERT INTO qc_measurement (qc_form_id, item_id, sample_no, measured_value, result, tool_id, remark, created_by) VALUES (?, ?, ?, ?, ?, ?, ?, ?)";
            $stmtDetail = $pdo->prepare($sqlDetail);

            foreach ($details as $d) {
                $stmtDetail->execute([
                    $formId,
                    $d['item_id'],
                    $d['sample_no'],
                    $d['value'],
                    $d['result'], // OK/NG
                    $d['tool_id'] ?: null,
                    $d['remark'] ?? null, // 處置狀況
                    $userId
                ]);
            }

            // 若結果為 NG，這裡可以預留建立異常單的邏輯
            if (!empty($remark)) {
                $remark_sql = "INSERT INTO qc_check_form_remark (qc_form_id, remark, action_type, created_by) VALUES (?, ?, 'CREATE', ?)";
                $pdo->prepare($remark_sql)->execute([$formId, $remark, $userId]);
            }
            
            $pdo->commit();
            echo json_encode(['success' => true, 'message' => '檢驗結果已儲存', 'form_id' => $formId]);
            exit;
        }

        // 5.5 獲取單筆檢驗紀錄詳情
        if ($_POST['action'] === 'get_record_details') {
            $qcFormId = $_POST['qc_form_id'];

            // 判斷是否為包裝檢驗 (ID 以 pkg_ 開頭)
            if (strpos($qcFormId, 'pkg_') === 0) {
                $realId = substr($qcFormId, 4);
                $stmt = $pdo->prepare("SELECT * FROM qc_packing_inspection WHERE packing_inspection_id = ?");
                $stmt->execute([$realId]);
                $header = $stmt->fetch(PDO::FETCH_ASSOC);

                $stmtData = $pdo->prepare("SELECT data_json FROM qc_packing_inspection_data WHERE packing_inspection_id = ?");
                $stmtData->execute([$realId]);
                $jsonData = $stmtData->fetchColumn();

                // 構造符合前端預期的格式
                $header['qc_form_id'] = $qcFormId;
                $header['check_result'] = $header['judgement']; // Mapping
                $header['incoming_qty'] = $header['order_qty'];
                $header['sample_qty'] = $header['inspected_qty'];
                $header['packaging_data'] = $jsonData; // 將 JSON 放回 packaging_data 欄位供前端解析

                echo json_encode(['success' => true, 'header' => $header, 'measurements' => [], 'remark' => $header['remark']]);
                exit;
            }
            
            // Header from View
            $stmt = $pdo->prepare("SELECT * FROM vw_qc_record_header WHERE qc_form_id = ?");
            $stmt->execute([$qcFormId]);
            $header = $stmt->fetch(PDO::FETCH_ASSOC);
            
            // Measurements from View
            $stmt = $pdo->prepare("SELECT * FROM vw_qc_record_details WHERE qc_form_id = ? ORDER BY sort_order ASC, sample_no ASC");
            $stmt->execute([$qcFormId]);
            $measurements = $stmt->fetchAll(PDO::FETCH_ASSOC);
            
            // Latest Remark
            $stmt = $pdo->prepare("SELECT remark FROM qc_check_form_remark WHERE qc_form_id = ? ORDER BY created_at DESC LIMIT 1");
            $stmt->execute([$qcFormId]);
            $remark = $stmt->fetchColumn();

            echo json_encode(['success' => true, 'header' => $header, 'measurements' => $measurements, 'remark' => $remark]);
            exit;
        }

        // 6. 抽驗規則管理 (CRUD)
        if ($_POST['action'] === 'manage_sampling_rules') {
            $sub = $_POST['sub_action'];
            if ($sub === 'list') {
                $stmt = $pdo->query("SELECT * FROM qc_sampling_rule ORDER BY min_qty ASC");
                echo json_encode(['success' => true, 'data' => $stmt->fetchAll(PDO::FETCH_ASSOC)]);
            } elseif ($sub === 'save') {
                $min = $_POST['min'];
                $max = $_POST['max'];
                $sample = $_POST['sample'];
                $id = $_POST['id'] ?? '';
                if ($id) {
                    $pdo->prepare("UPDATE qc_sampling_rule SET min_qty=?, max_qty=?, sample_qty=? WHERE rule_id=?")->execute([$min, $max, $sample, $id]);
                } else {
                    $pdo->prepare("INSERT INTO qc_sampling_rule (min_qty, max_qty, sample_qty) VALUES (?, ?, ?)")->execute([$min, $max, $sample]);
                }
                echo json_encode(['success' => true]);
            } elseif ($sub === 'delete') {
                $pdo->prepare("DELETE FROM qc_sampling_rule WHERE rule_id=?")->execute([$_POST['id']]);
                echo json_encode(['success' => true]);
            }
            exit;
        }

        // 6.5 刪除歷史紀錄
        if ($_POST['action'] === 'delete_history') {
            $qcFormId = $_POST['qc_form_id'];
            $pdo->beginTransaction();
            // 刪除明細與備註 (手動刪除以防 FK 未設定 Cascade)
            $pdo->prepare("DELETE FROM qc_measurement WHERE qc_form_id = ?")->execute([$qcFormId]);
            $pdo->prepare("DELETE FROM qc_check_form_remark WHERE qc_form_id = ?")->execute([$qcFormId]);
            // 刪除主檔
            $pdo->prepare("DELETE FROM qc_check_form WHERE qc_form_id = ?")->execute([$qcFormId]);
            $pdo->commit();
            echo json_encode(['success' => true]);
            exit;
        }

        // 7. 歷史紀錄查詢
        if ($_POST['action'] === 'search_history') {
            $kw = $_POST['keyword'] ?? '';
            // 改用 View 查詢，簡化邏輯並包含所有欄位
            $sql = "SELECT v.*, 
                    (SELECT SUM(q.incoming_qty) 
                     FROM qc_check_form q 
                     WHERE q.bom_ing_fid = v.bom_ing_fid 
                       AND q.qc_form_id <= v.qc_form_id
                    ) as acc_incoming_qty
                    FROM vw_qc_record_header v
                    WHERE v.bom_no LIKE :kw OR v.part_no LIKE :kw 
                    ORDER BY v.created_at DESC LIMIT 50";
            $stmt = $pdo->prepare($sql);
            $stmt->execute([':kw' => "%$kw%"]);
            echo json_encode(['success' => true, 'data' => $stmt->fetchAll(PDO::FETCH_ASSOC)]);
            exit;
        }

        // 1.95 獲取同料號的其他 BOM (用於包裝檢驗關聯批次)
        if ($_POST['action'] === 'get_related_boms') {
            $part_no = $_POST['part_no']; // D_Setting_Id (料號字串)
            $current_bom = $_POST['current_bom'];
            // 排除 processing_state=1 或 processing_state IS NOT NULL => 只取 NULL
            // 2024-04-15 Update: 加入數量資訊
            $sql = "SELECT b.bom, SUM(bi.sqty) as total_sqty,
                        (SELECT SUM(p.inspected_qty) 
                         FROM qc_packing_inspection p 
                         JOIN bom_ing bi2 ON p.bom_ing_fid = bi2.bom_ing_fid 
                         WHERE bi2.bom = b.bom) as packed_qty
                    FROM bom b
                    LEFT JOIN bom_ing bi ON b.bom = bi.bom
                    WHERE b.d_id = ? AND b.processing_state IS NULL AND b.bom != ?
                    GROUP BY b.bom
                    ORDER BY b.bom DESC";
            $stmt = $pdo->prepare($sql);
            $stmt->execute([$part_no, $current_bom]);
            echo json_encode(['success' => true, 'data' => $stmt->fetchAll(PDO::FETCH_ASSOC)]);
            exit;
        }
    } catch (Exception $e) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        echo json_encode(['success' => false, 'message' => $e->getMessage()]);
        exit;
    }
}
?>
<!DOCTYPE html>
<html lang="zh-Hant">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>品管檢驗結果輸入</title>
    <link href="../../resource/css/bootstrap.css" rel="stylesheet">
    <link href="../../resource/css/font-awesome.css" rel="stylesheet">
    <link href="../../resource/css/custom.css" rel="stylesheet">
    <style>
        .step-container {
            border-bottom: 1px solid #eee;
            padding-bottom: 15px;
            margin-bottom: 15px;
        }

        .search-result-item {
            cursor: pointer;
            padding: 8px;
            border-bottom: 1px solid #eee;
        }

        .search-result-item:hover {
            background-color: #f5f5f5;
        }

        .search-result-item.active {
            background-color: #d9edf7;
            border-left: 4px solid #31708f;
        }

        .ng-value {
            background-color: #f2dede !important;
            color: #a94442;
            font-weight: bold;
        }

        .ok-value {
            color: #3c763d;
        }

        .tool-select-row {
            margin-bottom: 5px;
        }

        .table-input {
            width: 100%;
            min-width: 60px;
            border: 1px solid #ccc;
            padding: 4px;
            border-radius: 3px;
        }

        /* 固定表頭 */
        .sticky-header th {
            position: sticky;
            top: 0;
            background: #f9f9f9;
            z-index: 10;
        }

        #inspection-table-container {
            max-height: 600px;
            overflow-y: auto;
        }

        .qty-warning {
            color: #a94442;
            font-size: 0.9em;
        }

        /* 隱藏數字輸入框的上下箭頭 */
        input[type=number]::-webkit-inner-spin-button, 
        input[type=number]::-webkit-outer-spin-button { 
            -webkit-appearance: none; 
            margin: 0; 
        }
        input[type=number] {
            -moz-appearance: textfield;
        }

        /* OK/NG 輸入框樣式 */
        input[data-type="OKNG"] {
            cursor: pointer;
            user-select: none;
        }

        /* 焦點欄位底色 */
        .focused-input {
            background-color: #d9edf7 !important; /* 淺藍色 */
        }
        
        /* Tab Icons */
        .tab-icon {
            margin-left: 5px;
        }
        .icon-saved { color: green; }
        .icon-dirty { color: #f0ad4e; } /* Orange pen */
        .nav-tabs > li > a { font-weight: bold; color: #555; }
        .nav-tabs > li.active > a { color: #333; }

        #process-tabs > li.active > a,
        #process-tabs > li.active > a:focus,
        #process-tabs > li.active > a:hover {
            background-color: #e6f7ff;
            border-color: #91d5ff #91d5ff transparent;
        }

        /* Image Editor Styles */
        #canvas-container {
            width: 100%;
            height: 100%;
            overflow: auto;
            background-color: #555; /* 深色背景凸顯圖片 */
            position: relative;
            cursor: crosshair;
            /* 讓內容置中 */
            display: flex;
            justify-content: center;
            align-items: flex-start; 
        }
        #paint-canvas {
            background-color: #fff;
            display: block;
            box-shadow: 0 0 10px rgba(0,0,0,0.5);
            /* 預設不設寬高，由 JS 控制 */
        }
        .editor-toolbar {
            padding: 8px;
            background: #f5f5f5;
            border-top: 1px solid #ddd;
            user-select: none;
        }
        .tool-btn {
            margin-right: 5px;
        }
        .tool-btn.active {
            background-color: #337ab7;
            color: white;
            border-color: #2e6da4;
        }
        #text-input-overlay {
            position: absolute;
            display: none;
            z-index: 1000;
            background: rgba(255, 255, 255, 0.8);
            border: 1px dashed #337ab7;
            padding: 2px;
            margin: 0;
            outline: none;
            font-family: Arial, sans-serif;
            font-size: 16px;
            color: red;
            cursor: move;
        }
        #imageEditModal {
            z-index: 10050; /* Ensure it is above sidebar */
        }
        #selection-box {
            position: absolute;
            border: 2px dashed red;
            background-color: rgba(255, 255, 255, 0.3);
            display: none;
            pointer-events: none;
            z-index: 999;
        }
        /* Packaging Section Styles */
        .pkg-row { margin-bottom: 5px; padding: 5px; background: #f9f9f9; border: 1px solid #eee; border-radius: 4px; }
        .pkg-row .form-control { display: inline-block; width: auto; }
        .pkg-remove { color: #d9534f; cursor: pointer; margin-left: 5px; }
        
        /* Custom Packaging Form Styles */
        .pkg-section-title { background: #eee; padding: 5px 10px; font-weight: bold; margin-top: 15px; margin-bottom: 10px; border-left: 3px solid #337ab7; }
        .pkg-table td { vertical-align: middle !important; }
        .pkg-checkbox-group label { margin-right: 10px; cursor: pointer; }
        .pkg-other-input { display: inline-block; width: auto; margin-left: 5px; }

        /* RWD Tabs: Ensure tabs stay on one line on wider screens */
        @media (min-width: 768px) {
            #process-tabs-container {
                width: 100%;
                overflow-x: auto;
                overflow-y: hidden;
                white-space: nowrap;
                -webkit-overflow-scrolling: touch;
            }
            #process-tabs {
                display: inline-flex;
                min-width: 100%;
                flex-wrap: nowrap;
                border-bottom: 1px solid #ddd;
            }
            #process-tabs > li {
                float: none;
                display: inline-block;
                flex: 0 0 auto;
            }
        }
    </style>
</head>

<body class="nav-sm">
    <div class="container body">
        <div class="main_container">
            <?php include '../partPage/sideAndTopBarMenu.html' ?>

            <div class="right_col" role="main">
                <div class="">
                    <div class="page-title">
                        <div class="title_left">
                            <h3>品管檢驗結果輸入 <small>QC Inspection Entry</small></h3>
                        </div>
                        <div class="title_right">
                            <button class="btn btn-default pull-right" id="btn-history"><i class="fa fa-history"></i> 歷史紀錄</button>
                            <button class="btn btn-default pull-right" id="btn-sampling-rule"><i class="fa fa-list-ol"></i> 抽驗規則設定</button>
                        </div>
                    </div>
                    <div class="clearfix"></div>

                    <div class="row">
                        <div class="col-md-12 col-sm-12 col-xs-12">
                            <div class="x_panel">
                                <div class="x_content">

                                    <!-- 步驟 1: 搜尋與選擇 BOM -->
                                    <div class="step-container" id="step-1">
                                        <h4>1. 選擇待驗項目 (BOM、料號、客戶 皆可篩選)</h4>
                                        <div class="row">
                                            <div class="col-md-4">
                                                <div class="input-group">
                                                    <input type="text" id="search-kw" class="form-control" placeholder="輸入 BOM號 / 料號 / 客戶...">
                                                    <span class="input-group-btn">
                                                        <button class="btn btn-primary" id="btn-search">搜尋</button>
                                                    </span>
                                                </div>
                                                <div id="search-results" style="max-height: 200px; overflow-y: auto; border: 1px solid #eee; display:none;"></div>
                                            </div>
                                            <div class="col-md-8">
                                                <div class="well well-sm" id="selected-bom-info" style="display:none; background-color: #f9f9f9; border-left: 5px solid #337ab7;">
                                                    <div class="row">
                                                        <div class="col-md-3 col-sm-6">
                                                            <h5 class="text-muted" style="margin-bottom: 5px;">BOM / 圖檔</h5>
                                                            <strong id="disp-bom-id" class="text-primary" style="font-size: 1.2em;"></strong>
                                                        </div>
                                                        <div class="col-md-3 col-sm-6">
                                                            <h5 class="text-muted" style="margin-bottom: 5px;">料號</h5>
                                                            <strong id="disp-part-no" style="font-size: 1.2em;"></strong>
                                                        </div>
                                                        <div class="col-md-3 col-sm-6">
                                                            <h5 class="text-muted" style="margin-bottom: 5px;">客戶</h5>
                                                            <strong id="disp-client" style="font-size: 1.2em;"></strong>
                                                        </div>
                                                        <div class="col-md-3 col-sm-6">
                                                            <h5 class="text-muted" style="margin-bottom: 5px;">發包數</h5>
                                                            <strong id="disp-sqty" style="font-size: 1.2em;"></strong>
                                                        </div>
                                                    </div>
                                                    <div class="row" style="margin-top: 10px;">
                                                        <div class="col-md-3 col-sm-6">
                                                            <h5 class="text-muted" style="margin-bottom: 5px;">版次</h5>
                                                            <strong id="disp-rev"></strong>
                                                        </div>
                                                        <div class="col-md-3 col-sm-6">
                                                            <h5 class="text-muted" style="margin-bottom: 5px;">發行日</h5>
                                                            <strong id="disp-date"></strong>
                                                        </div>
                                                    </div>
                                                </div>
                                            </div>
                                        </div>
                                    </div>

                                    <!-- 步驟 2: 設定檢驗參數 -->
                                    <div class="step-container" id="step-2" style="display:none; background: #f0f0f0; padding: 10px; border-radius: 5px;">
                                        <div class="row">
                                            <div class="col-md-4">
                                                <label>2. 選擇檢驗版本</label>
                                                <select id="sel-version" class="form-control input-sm"></select>
                                            </div>
                                            <div class="col-md-3">
                                                <label>進貨數量</label>
                                                <input type="number" id="inp-incoming-qty" class="form-control input-sm" placeholder="輸入數量">
                                                <div id="qty-diff-msg" class="qty-warning" style="display:none;"><i class="fa fa-exclamation-triangle"></i> <span id="qty-diff-text"></span></div>
                                            </div>
                                            <div class="col-md-3">
                                                <label>抽驗數量 (自動)</label>
                                                <input type="text" id="inp-sample-qty" class="form-control input-sm" readonly style="background-color: #eee;">
                                            </div>
                                        </div>
                                    </div>
                                    
                                    <!-- 製程選擇區 (動態顯示) -->
                                    <!-- 已移除舊版 Checkbox 選擇區 -->

                                    <!-- 步驟 3: 量具與檢驗輸入 -->
                                    <div id="step-3" style="display:none;">
                                        <hr>
                                        
                                        <!-- 新版製程分頁 -->
                                        <div id="process-tabs-container" style="margin-bottom: 15px;">
                                            <ul class="nav nav-tabs" id="process-tabs"></ul>
                                        </div>

                                        <!-- 製程對應設定 (若未對應) -->
                                        <div id="mapping-config-area" class="alert alert-info" style="display:none;">
                                            <form class="form-inline">
                                                <div class="form-group">
                                                    <label><i class="fa fa-link"></i> 此製程 (BOM Process) 對應到哪一個檢驗分頁 (IPQC Page)？</label>
                                                    <select id="sel-mapping-target" class="form-control input-sm" style="min-width: 200px;"></select>
                                                </div>
                                                <button type="button" class="btn btn-primary btn-sm" id="btn-save-mapping">確認對應</button>
                                                <span class="help-block" style="display:inline; margin-left:10px;">(設定後將自動載入檢驗項目)</span>
                                            </form>
                                        </div>

                                        <!-- 檢驗表格 -->
                                        <h4>3. 檢驗數據輸入 
                                            <small style="margin-left: 10px; font-size: 14px; color: #333;" id="inspector-display">檢驗者：<?php echo htmlspecialchars((string)$user_cname) . ' (' . htmlspecialchars((string)$user_id_for_display) . ')'; ?> <span style="color:red; font-size:12px;"><?php echo $debug_info; ?></span></small>
                                            <button class="btn btn-success btn-sm" id="btn-new-record" style="margin-left: 10px;"><i class="fa fa-plus"></i> 新紀錄</button>
                                        </h4>
                                        <div id="inspection-table-container" class="table-responsive">
                                            <table class="table table-bordered table-striped" id="inspection-table">
                                                <thead>
                                                    <tr class="sticky-header">
                                                        <th width="50" class="text-center">編號</th>
                                                        <th width="15%">檢驗項目</th>
                                                        <th width="15%">標準 / 公差</th>
                                                        <th width="250">使用量具</th>
                                                        <!-- 動態抽樣欄位 -->
                                                    </tr>
                                                </thead>
                                                <tbody></tbody>
                                            </table>
                                        </div>

                                        <!-- 歷史紀錄列表 -->
                                        <div id="process-history-container" style="margin-top: 20px; display:none; border-top: 1px solid #ddd; padding-top: 10px;">
                                            <h5 class="text-muted"><i class="fa fa-history"></i> 本製程歷史紀錄 (點擊檢視)</h5>
                                            <div class="table-responsive">
                                                <table class="table table-hover table-condensed" id="process-history-table" style="background:#f9f9f9;">
                                                    <thead><tr><th>日期</th><th>結果</th><th>數量</th><th>檢驗者</th><th>操作</th></tr></thead>
                                                    <tbody></tbody>
                                                </table>
                                            </div>
                                        </div>

                                        <!-- 量具設定區 (移至下方或摺疊) -->
                                        <div class="panel panel-default" style="margin-top: 15px;">
                                            <div class="panel-heading" role="tab" id="headingTools">
                                                <h4 class="panel-title">
                                                    <a role="button" data-toggle="collapse" href="#collapseTools" aria-expanded="false"><i class="fa fa-wrench"></i> 量具選用設定 (點擊展開)</a>
                                                </h4>
                                            </div>
                                            <div id="collapseTools" class="panel-collapse collapse" role="tabpanel">
                                                <div class="panel-body" id="tool-config-area"></div>
                                            </div>
                                        </div>

                                        <!-- 專屬包裝出貨檢驗表單 (僅 PKG 顯示) -->
                                        <div id="pkg-custom-form" style="display:none;">
                                            <div class="alert alert-info" style="margin-top: 10px; padding: 5px 10px;">
                                                <i class="fa fa-info-circle"></i> 此頁面為 <strong>成品包裝與出貨檢驗</strong> 專用格式。
                                            </div>

                                            <!-- 1. 外觀檢驗 -->
                                            <div class="pkg-section-title">
                                                1. 外觀檢驗
                                                <button class="btn btn-xs btn-default pull-right" onclick="window.open('inspection_standard_setting.php', '_blank')"><i class="fa fa-cog"></i> 管理檢驗項目(資料庫)</button>
                                            </div>
                                            <table class="table table-bordered pkg-table">
                                                <thead>
                                                    <tr>
                                                        <th width="15%">項目</th>
                                                        <th width="10%">方式/工具</th>
                                                        <th width="10%">異常數量</th>
                                                        <th>處置狀況 / 備註</th>
                                                    </tr>
                                                </thead>
                                                <tbody id="pkg-appearance-tbody">
                                                    <!-- JS 動態生成 -->
                                                </tbody>
                                                <tfoot id="pkg-appearance-tfoot" style="background-color: #f9f9f9; font-weight: bold;">
                                                    <!-- JS 動態生成 -->
                                                </tfoot>
                                            </table>

                                            <!-- 2. 備註與防護 -->
                                            <div class="pkg-section-title">2. 防護與備註</div>
                                            <div class="row">
                                                <div class="col-md-6">
                                                    <label>加強防銹：</label>
                                                    <div class="pkg-checkbox-group">
                                                        <label><input type="checkbox" class="pkg-rust" value="防銹袋"> 防銹袋</label>
                                                        <label><input type="checkbox" class="pkg-rust" value="防銹油"> 防銹油</label>
                                                        <div style="display:inline-block;">
                                                            <label><input type="checkbox" class="pkg-rust" value="其他"> 其他</label>
                                                            <input type="text" class="form-control input-sm pkg-other-input pkg-rust-other" placeholder="說明" style="display:none;">
                                                        </div>
                                                    </div>
                                                </div>
                                                <div class="col-md-6">
                                                    <label>確認防撞：</label>
                                                    <div class="pkg-checkbox-group">
                                                        <div style="margin-bottom: 5px;">
                                                            <label><input type="checkbox" class="pkg-collision" value="泡殼"> 泡殼</label>
                                                            (<input type="number" class="form-control input-sm pkg-collision-detail" style="width:50px; display:inline-block;" placeholder="入"> 入 x <input type="number" class="form-control input-sm pkg-collision-detail-2" style="width:50px; display:inline-block;" placeholder="個"> 個)
                                                        </div>
                                                        <label><input type="checkbox" class="pkg-collision" value="隔板"> 隔板</label>
                                                        <label><input type="checkbox" class="pkg-collision" value="氣泡紙"> 氣泡紙</label>
                                                        <label><input type="checkbox" class="pkg-collision" value="報紙"> 報紙</label>
                                                        <div style="display:inline-block;">
                                                            <label><input type="checkbox" class="pkg-collision" value="其他"> 其他</label>
                                                            <input type="text" class="form-control input-sm pkg-other-input pkg-collision-other" placeholder="說明" style="display:none;">
                                                        </div>
                                                    </div>
                                                </div>
                                            </div>
                                            <div class="row" style="margin-top: 10px; background: #f9f9f9; padding: 10px; border-radius: 4px;">
                                                <div class="col-md-4">
                                                    <div class="input-group input-group-sm">
                                                        <span class="input-group-addon">治具/模具/量具 歸還</span>
                                                        <input type="number" id="pkg-return-jig" class="form-control" placeholder="數量">
                                                        <span class="input-group-addon">個</span>
                                                    </div>
                                                </div>
                                                <div class="col-md-4">
                                                    <div class="input-group input-group-sm">
                                                        <span class="input-group-addon">樣品 歸還</span>
                                                        <input type="number" id="pkg-return-sample" class="form-control" placeholder="數量">
                                                        <span class="input-group-addon">個</span>
                                                    </div>
                                                </div>
                                                <div class="col-md-4">
                                                    <div class="input-group input-group-sm">
                                                        <span class="input-group-addon">關聯批次</span>
                                                        <input type="text" id="pkg-related-batches" class="form-control" placeholder="多批包裝時填寫" readonly>
                                                        <span class="input-group-btn">
                                                            <button class="btn btn-default" type="button" id="btn-select-related-bom" title="選擇其他批次"><i class="fa fa-list"></i></button>
                                                        </span>
                                                    </div>
                                                </div>
                                            </div>

                                            <!-- 3. 容器與出貨 -->
                                            <div class="pkg-section-title">3. 容器與出貨</div>
                                            
                                            <div id="pkg-rows-container">
                                                <!-- 動態加入包裝列 -->
                                            </div>
                                            <button class="btn btn-default btn-sm" id="btn-add-pkg-row"><i class="fa fa-plus"></i> 新增容器</button>
                                            
                                            <div class="row">
                                                <div class="col-md-6">
                                                    <label>實際出貨數量說明:</label>
                                                    <input type="text" id="pkg-shipment-desc" class="form-control input-sm" placeholder="例如: 100 x 5 桶 + 20 = 520">
                                                </div>
                                                <div class="col-md-6">
                                                    <label>成品入庫方式:</label>
                                                    <div class="form-inline">
                                                        <label class="radio-inline"><input type="radio" name="pkg-storage-method" value="direct" checked> 直接入庫</label>
                                                        <label class="radio-inline"><input type="radio" name="pkg-storage-method" value="pallet"> 棧板+膠膜</label>
                                                        <input type="number" id="pkg-pallet-qty" class="form-control input-sm" style="width:80px; display:inline-block;" placeholder="棧板數">
                                                    </div>
                                                    <div class="form-inline" style="margin-top: 5px;">
                                                        <label>實際入庫數:</label>
                                                        <input type="number" id="pkg-actual-qty" class="form-control input-sm" style="width:100px;" placeholder="實際數量">
                                                        <span class="text-muted small">(預設: 訂單數 - NG數)</span>
                                                    </div>
                                                </div>
                                            </div>
                                        </div>

                                        <!-- 備註區 -->
                                        <div id="remarks-section" style="margin-top: 10px;">
                                            <textarea id="inp-remark" class="form-control" rows="3" placeholder="輸入此檢驗的相關備註..."></textarea>
                                        </div>

                                        <div class="ln_solid"></div>
                                        <div class="form-group text-center form-inline">
                                            <label style="font-size: 16px; margin-right: 5px;">本頁判定:</label>
                                            <select id="sel-final-result" class="form-control" style="font-weight: bold; width: auto;">
                                                <option value="OK" class="text-success">OK</option>
                                                <option value="NG" class="text-danger">NG</option>
                                            </select>                                            
                                            <button class="btn btn-primary btn-lg" id="btn-save-current-tab" style="margin-left: 20px;"><i class="fa fa-save"></i> 儲存目前分頁結果</button>
                                            <button class="btn btn-warning btn-lg" id="btn-clear-page" style="margin-left: 5px;"><i class="fa fa-eraser"></i> 清除本頁</button>
                                            <button class="btn btn-default btn-lg" id="btn-print"><i class="fa fa-print"></i> 列印</button>
                                            <button class="btn btn-default btn-lg" id="btn-pdf"><i class="fa fa-file-pdf-o"></i> 轉PDF</button>
                                        </div>
                                    </div>

                                </div>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
            <?php include '../partPage/footer.html' ?>
        </div>
    </div>

    <!-- 抽驗規則 Modal -->
    <div class="modal fade" id="samplingRuleModal" tabindex="-1" role="dialog">
        <div class="modal-dialog">
            <div class="modal-content">
                <div class="modal-header">
                    <button type="button" class="close" data-dismiss="modal"><span>&times;</span></button>
                    <h4 class="modal-title">抽驗規則設定</h4>
                </div>
                <div class="modal-body">
                    <form id="rule-form" class="form-inline well well-sm">
                        <input type="hidden" id="rule-id">
                        <input type="number" id="rule-min" class="form-control input-sm" placeholder="最小數量" style="width:80px" required>
                        ~
                        <input type="number" id="rule-max" class="form-control input-sm" placeholder="最大數量" style="width:80px" required>
                        : 抽
                        <input type="number" id="rule-sample" class="form-control input-sm" placeholder="數量" style="width:60px" required>
                        <button type="submit" class="btn btn-primary btn-sm">儲存</button>
                    </form>
                    <table class="table table-striped">
                        <thead>
                            <tr>
                                <th>範圍</th>
                                <th>抽驗數</th>
                                <th>操作</th>
                            </tr>
                        </thead>
                        <tbody id="rule-list"></tbody>
                    </table>
                </div>
            </div>
        </div>
    </div>

    <!-- 歷史紀錄 Modal -->
    <div class="modal fade" id="historyModal" tabindex="-1" role="dialog">
        <div class="modal-dialog modal-lg">
            <div class="modal-content">
                <div class="modal-header">
                    <button type="button" class="close" data-dismiss="modal"><span>&times;</span></button>
                    <h4 class="modal-title">歷史檢驗紀錄查詢</h4>
                </div>
                <div class="modal-body">
                    <div class="input-group" style="margin-bottom: 10px;">
                        <input type="text" id="hist-kw" class="form-control" placeholder="輸入 BOM / 料號...">
                        <span class="input-group-btn"><button class="btn btn-default" id="btn-hist-search">查詢</button></span>
                    </div>
                    <table class="table table-bordered">
                        <thead>
                            <tr>
                                <th>日期</th>
                                <th>BOM</th>
                                <th>料號</th>
                                <th>製程</th>
                                <th>廠商</th>
                                <th>檢驗表</th>
                                <th>進貨數</th>
                                <th>累積進貨 /<br>發單</th>
                                <th>結果</th>
                                <th>人員</th>
                                <th width="100"></th>
                            </tr>
                        </thead>
                        <tbody id="hist-list"></tbody>
                    </table>
                </div>
            </div>
        </div>
    </div>

    <!-- BOM 圖檔 Modal -->
    <div class="modal fade" id="bomFileModal" tabindex="-1" role="dialog">
        <div class="modal-dialog modal-lg" style="width: 90%;">
            <div class="modal-content">
                <div class="modal-header">
                    <button type="button" class="close" data-dismiss="modal"><span>&times;</span></button>
                    <h4 class="modal-title">BOM 圖檔: <span id="modal-bom-title"></span></h4>
                </div>
                <div class="modal-body">
                    <div class="text-right" style="margin-bottom: 10px;" id="bom-file-actions">
                        <!-- 按鈕將由 JS 動態生成 -->
                    </div>
                    <div class="row">
                        <div class="col-md-3">
                            <div class="list-group" id="bom-file-list"></div>
                        </div>
                        <div class="col-md-9" id="bom-file-viewer" style="min-height: 500px; text-align: center; background: #eee;">
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <!-- 關聯批次選擇 Modal -->
    <div class="modal fade" id="relatedBomModal" tabindex="-1" role="dialog">
        <div class="modal-dialog modal-sm">
            <div class="modal-content">
                <div class="modal-header">
                    <button type="button" class="close" data-dismiss="modal"><span>&times;</span></button>
                    <h4 class="modal-title">選擇關聯 BOM</h4>
                </div>
                <div class="modal-body">
                    <div id="related-bom-list" class="list-group" style="max-height: 300px; overflow-y: auto;"></div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-primary" id="btn-confirm-related-bom">確認選擇</button>
                </div>
            </div>
        </div>
    </div>

    <!-- 簡易繪圖 Modal -->
    <div class="modal fade" id="imageEditModal" tabindex="-1" role="dialog" data-backdrop="static" data-keyboard="false">
        <div class="modal-dialog modal-lg" style="width: 90%; height: 90%; margin: 30px auto;">
            <div class="modal-content" style="height: 100%; display: flex; flex-direction: column;">
                <div class="modal-header" style="flex: 0 0 auto;">
                    <button type="button" class="close" data-dismiss="modal"><span>&times;</span></button>
                    <h4 class="modal-title">圖檔標記</h4>
                </div>
                
                <div class="modal-body" style="flex: 1 1 auto; padding: 0; overflow: hidden; position: relative;">
                    <div id="canvas-container">
                        <canvas id="paint-canvas"></canvas>
                        <div id="selection-box"></div>
                        <input type="text" id="text-input-overlay" placeholder="輸入文字...">
                    </div>
                </div>
                
                <div class="modal-footer editor-toolbar" style="flex: 0 0 auto; text-align: left;">
                    <div class="row">
                        <div class="col-md-12 form-inline">
                            <!-- Tools -->
                            <div class="btn-group" role="group" style="margin-right: 10px;">
                                <button type="button" class="btn btn-default tool-btn active" data-tool="pen" title="畫筆"><i class="fa fa-pencil"></i></button>
                                <button type="button" class="btn btn-default tool-btn" data-tool="rect" title="方框"><i class="fa fa-square-o"></i></button>
                                <button type="button" class="btn btn-default tool-btn" data-tool="circle" title="圓圈"><i class="fa fa-circle-o"></i></button>
                                <button type="button" class="btn btn-default tool-btn" data-tool="eraser_rect" title="選取刪除 (框選清除)"><i class="fa fa-eraser"></i></button>
                                <button type="button" class="btn btn-default tool-btn" data-tool="pan" title="拖移 (按住左鍵拖動)"><i class="fa fa-arrows"></i></button>
                            </div>

                            <!-- Properties -->
                            <label>顏色:</label> 
                            <input type="color" id="pen-color" value="#ff0000" class="form-control input-sm" style="width: 40px; padding: 2px; height: 30px;">
                            
                            <label style="margin-left: 5px;">粗細:</label> 
                            <input type="number" id="pen-width" min="1" max="50" value="3" class="form-control input-sm" style="width: 60px;">

                            <!-- Zoom -->
                            <div class="btn-group" role="group" style="margin-left: 10px; margin-right: 10px;">
                                <button type="button" class="btn btn-default" id="btn-zoom-out" title="縮小"><i class="fa fa-minus"></i></button>
                                <span class="btn btn-default disabled" id="zoom-level" style="width: 60px;">100%</span>
                                <button type="button" class="btn btn-default" id="btn-zoom-in" title="放大"><i class="fa fa-plus"></i></button>
                            </div>

                            <!-- Actions -->
                            <button class="btn btn-warning btn-sm" id="btn-undo-canvas" title="復原"><i class="fa fa-undo"></i></button>
                            <button class="btn btn-danger btn-sm" id="btn-clear-canvas" title="全部清除"><i class="fa fa-trash"></i></button>
                            
                            <div class="pull-right">
                                <span class="text-muted" style="margin-right: 10px; font-size: 0.9em;"><i class="fa fa-info-circle"></i> 請直接對圖片按右鍵選擇「複製圖片」</span>
                                <a href="#" class="btn btn-success" id="btn-save-img" download="marked_image.jpg"><i class="fa fa-save"></i> 儲存</a>
                                <button class="btn btn-default" id="btn-print-canvas"><i class="fa fa-print"></i> 列印</button>
                                <button type="button" class="btn btn-default" data-dismiss="modal">關閉</button>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <script src="../../resource/js/jquery.min.js"></script>
    <script src="../../resource/js/bootstrap.min.js"></script>
    <script src="../../resource/js/custom.min.js"></script>
    <script>
        var inspectorName = <?php echo @json_encode(htmlspecialchars($user_cname, ENT_QUOTES, 'UTF-8'), JSON_UNESCAPED_UNICODE) ?: '""'; ?>;
        $(document).ready(function() {
            var currentBom = null;
            var currentBomProcesses = []; // Store BOM processes for auto-matching
            var currentMap = []; // Version -> FormType mapping
            var processPageMap = []; // BOM Process -> Inspection Page Name mapping
            var originalItems = []; // Store all fetched items (Master list)
            var currentItems = []; // Inspection items
            var currentTools = []; // Tool definitions
            var selectedToolMap = {}; // CatID -> InstanceID
            var sampleQty = 0;
            var currentTabId = null; // Current active tab ID (e.g., 'proc-10', 'fqc')
            var savedStatusMap = {}; // Key: bom_ing_fid + process_name (or special key for FQC)
            var searchTimer = null;
            var inspectionDataCache = {}; // itemId -> { samples: {}, toolId: null, manualTool: false }
            var processMapPromise = $.Deferred().resolve(); // Promise for process map loading
            var currentQcFormId = null; // Track current editing record ID
            var savedStatusList = []; // Store raw saved records

            // 搜尋邏輯
            function performSearch() {
                var kw = $('#search-kw').val().trim();
                if (!kw) {
                    $('#search-results').hide().empty();
                    return;
                }

                $.post('inspection_result_entry.php', {
                    action: 'search_bom',
                    keyword: kw
                }, function(res) {
                    if (res.success) {
                        var html = '';
                        if (res.data.length === 0) html = '<div class="padding-10 text-center">無資料</div>';
                        res.data.forEach(function(d) {
                            html += `<div class="search-result-item" data-json='${JSON.stringify(d)}'>
                                [${d.Client_Name || '-'}] ${d.bom} - ${d.D_Setting_Id}
                            </div>`;
                        });
                        $('#search-results').html(html).show();
                    }
                }, 'json');
            }

            // 1. 搜尋 BOM 按鈕
            $('#btn-search').click(function() {
                performSearch();
            });

            // 即時搜尋 (Input)
            $('#search-kw').on('input', function() {
                clearTimeout(searchTimer);
                searchTimer = setTimeout(performSearch, 300);
            });

            // 雙擊清除
            $('#search-kw').dblclick(function() {
                $(this).val('');
                $('#search-results').hide().empty();
            });

            // 新紀錄按鈕
            $('#btn-new-record').click(function() {
                resetForm();
            });

            $(document).on('click', '.search-result-item', function() {
                var d = $(this).data('json');
                currentBom = d;
                inspectionDataCache = {}; // 切換 BOM 時才重置快取
                $('#search-results').hide();

                // 顯示 BOM 資訊
                $('#disp-bom-id').text(d.bom);
                $('#disp-part-no').html(`<a href="#" class="bom-link" data-bom="${d.bom}">${d.D_Setting_Id}</a>`); // d_setting_id
                $('#disp-client').text(d.Client_Name);
                $('#disp-sqty').text(d.sqty);
                $('#disp-rev').text(d.Revision || '-');
                $('#disp-date').text(d.Issue_Date || '-');
                $('#selected-bom-info').show();

                // 載入版本與表單選項
                loadPartOptions(d.d_id);
                loadBomProcesses(d.bom); // 載入製程選項
                loadProcessMap(d.bom);   // 載入製程對應
                loadSavedStatus(d.bom);  // 載入已儲存狀態
                $('#step-2').show();
                $('#step-3').hide();
            });

            function loadPartOptions(dId) {
                $.post('inspection_result_entry.php', {
                    action: 'get_part_options',
                    d_id: dId
                }, function(res) {
                    if (res.success) {
                        currentMap = res.map;
                        var $v = $('#sel-version').empty().append('<option value="">請選擇</option>');
                        res.versions.forEach(function(v) {
                            $v.append(`<option value="${v.version_id}">${v.version_label}</option>`);
                        });

                        // 自動選擇最新版本
                        if (res.versions.length > 0) {
                            $v.val(res.versions[0].version_id).trigger('change');
                        }
                    }
                }, 'json');
            }

            function loadProcessMap(bom) {
                processMapPromise = $.post('inspection_result_entry.php', { action: 'get_process_map', bom: bom }, function(res) {
                    if (res.success) processPageMap = res.data;
                }, 'json');
                return processMapPromise;
            }

            function loadSavedStatus(bom) {
                $.post('inspection_result_entry.php', { action: 'get_saved_status', bom: bom }, function(res) {
                    if (res.success) {
                        console.log("Saved Status Data:", res.data); // Debug log
                        savedStatusList = res.data; // Store raw list
                        savedStatusMap = {};
                        res.data.forEach(function(s) {
                            // Key logic: bom_ing_fid + process_name
                            // For FQC, process_name might be null or specific
                            var key = s.bom_ing_fid + '_' + (s.process_name || 'NULL');
                            savedStatusMap[key] = s;
                        });
                        // If tabs are already rendered, update icons
                        updateTabIcons();

                        // FIX: Refresh history for active tab immediately when data arrives
                        var $active = $('#process-tabs > li.active > a');
                        if ($active.length) {
                            var type = $active.data('type');
                            var idx = $active.data('idx');
                            updateHistoryView(type, idx);
                        }
                    }
                }, 'json');
            }

            function loadBomProcesses(bom) {
                $.post('inspection_result_entry.php', {
                    action: 'get_bom_processes',
                    bom: bom
                }, function(res) {
                    currentBomProcesses = res.data || [];
                    // Tabs will be rendered after inspection data is loaded
                }, 'json');
            }

            // 版本變更連動表單類型
            $('#sel-version').change(function() {
                checkAndLoadInspectionTable(); // 版本變更時觸發一次以重置狀態
            });

            // 進貨數量變更 -> 計算抽樣 & 驗證
            $('#inp-incoming-qty').on('input', function() {
                var qty = parseFloat($(this).val());
                var orderQty = parseFloat(currentBom.sqty);

                if (qty > 0) {
                    // 驗證數量
                    if (qty > orderQty) {
                        $(this).css({'background-color': '#f2dede', 'color': '#a94442'});
                        $('#qty-diff-text').text('超收 ' + (qty - orderQty));
                        $('#qty-diff-msg').show();
                    } else {
                        $(this).css({'background-color': '', 'color': ''});
                        $('#qty-diff-msg').hide();
                    }

                    $.post('inspection_result_entry.php', {
                        action: 'calculate_sample',
                        qty: qty
                    }, function(res) {
                        if (res.success) {
                            // 修正：抽驗數不可大於進貨數
                            var ruleSample = parseInt(res.sample_qty);
                            var finalSample = (ruleSample > qty) ? qty : ruleSample;
                            
                            $('#inp-sample-qty').val(finalSample);
                            sampleQty = finalSample;

                            renderTable(); // 僅重新渲染表格，不重新載入分頁結構
                        }
                    }, 'json');
                } else {
                    $('#inp-sample-qty').val('0');
                    sampleQty = 0;
                    $(this).css({'background-color': '', 'color': ''});
                    $('#qty-diff-msg').hide();
                    renderTable(); // 僅重新渲染表格
                }
            });

            // 進貨數量雙擊清除
            $('#inp-incoming-qty').dblclick(function() {
                if ($(this).val() !== '') {
                    $(this).val('').trigger('input');
                } else if (currentBom && currentBom.sqty) {
                    $(this).val(currentBom.sqty).trigger('input');
                }
            });

            // 自動載入檢驗表
            function checkAndLoadInspectionTable() {
                var vId = $('#sel-version').val();

                if (!vId) {
                    $('#step-3').hide();
                    return;
                }

                // Ensure process map is loaded before rendering tabs (which uses it for filtering)
                processMapPromise.always(function() {
                    $.post('inspection_result_entry.php', {
                        action: 'get_inspection_data',
                        version_id: vId,
                        // form_type_id: fIds // Removed, backend fetches all enabled
                    }, function(res) {
                        if (res.success) {
                            originalItems = res.items; // Store master list
                            currentTools = res.tools;
                            
                            renderTabs();
                            $('#step-3').show();
                        } else {
                            alert('載入檢驗表失敗: ' + (res.message || '未知錯誤'));
                        }
                    }, 'json');
                });
            }

            // 渲染製程分頁
            function renderTabs() {
                var $ul = $('#process-tabs').empty();

                // 0. IQC Tab (進貨檢) - 顯示在最左邊
                var iqcTabId = 'iqc';
                $ul.append(`<li role="presentation">
                    <a href="#" class="proc-tab-link" data-id="${iqcTabId}" data-type="IQC">
                        進貨檢 (IQC) <i class="fa fa-circle-o tab-icon" id="icon-${iqcTabId}"></i>
                    </a>
                </li>`);
                
                // 計算最大 BOM_SN
                var maxSn = 0;
                currentBomProcesses.forEach(function(p) {
                    var sn = parseInt(p.bom_sn);
                    if (!isNaN(sn) && sn > maxSn) maxSn = sn;
                });

                // 1. BOM Processes (過濾掉 材料/客供料 及 最後的包裝製程)
                currentBomProcesses.forEach(function(p, idx) {
                    var sn = parseInt(p.bom_sn);
                    var name = p.ProcessName;

                    // 過濾條件 1: BOM_SN=10 且 製程名稱=材料 或 客供料
                    if (sn === 10 && (name === '材料' || name === '客供料')) return;

                    // 過濾條件 2: BOM_SN為最大值 且 製程名稱=包裝
                    if (sn === maxSn && name === '包裝') return;

                    var tabId = 'proc-' + idx;
                    var label = `[${p.bom_sn}] ${p.ProcessName}`;
                    $ul.append(`<li role="presentation">
                        <a href="#" class="proc-tab-link" data-id="${tabId}" data-type="IPQC" data-idx="${idx}">
                            ${label} <i class="fa fa-circle-o tab-icon" id="icon-${tabId}"></i>
                        </a>
                    </li>`);
                });

                // 2. FQC Tab
                var fqcTabId = 'fqc';
                $ul.append(`<li role="presentation">
                    <a href="#" class="proc-tab-link" data-id="${fqcTabId}" data-type="FQC">
                        FQC (成品) <i class="fa fa-circle-o tab-icon" id="icon-${fqcTabId}"></i>
                    </a>
                </li>`);

                // 3. PKG Tab (包裝)
                var pkgTabId = 'pkg';
                $ul.append(`<li role="presentation">
                    <a href="#" class="proc-tab-link" data-id="${pkgTabId}" data-type="PKG">
                        包裝檢驗 <i class="fa fa-circle-o tab-icon" id="icon-${pkgTabId}"></i>
                    </a>
                </li>`);

                // Restore active tab if exists, otherwise trigger first
                var $targetTab = null;
                if (currentTabId) {
                    $targetTab = $('.proc-tab-link[data-id="' + currentTabId + '"]');
                }

                if ($targetTab && $targetTab.length > 0) {
                    $targetTab.click();
                } else if (currentBomProcesses.length > 0) {
                    $('.proc-tab-link').first().click();
                } else {
                    $('.proc-tab-link[data-type="FQC"]').click();
                }
                
                updateTabIcons();
            }

            function updateTabIcons() {
                // Update icons based on savedStatusMap and dirty state
                // Dirty state logic needs to be implemented (checking inspectionDataCache vs original)
                // For now, just Saved vs Empty
                
                // IPQC Tabs
                currentBomProcesses.forEach(function(p, idx) {
                    var tabId = 'proc-' + idx;
                    var map = processPageMap.find(m => m.process_no == p.process_no);
                    var pName = map ? map.process_name : null;
                    
                    // Check if saved
                    // Note: bom_ing_fid is unique per process in BOM_ING.
                    // We need to find the bom_ing_fid for this process.
                    // currentBomProcesses doesn't have bom_ing_fid directly in get_bom_processes result?
                    // Wait, get_bom_processes returns bom_sn, process_no.
                    // We need bom_ing_fid to check saved status accurately.
                    // Let's assume we can match by process_no if bom_ing_fid is not available, 
                    // OR update get_bom_processes to return bom_ing_fid.
                    // Actually, savedStatusMap uses bom_ing_fid.
                    // Let's assume we can match via process_no in savedStatusMap (I added process_no to get_saved_status output).
                    
                    var isSaved = false;
                    for (var key in savedStatusMap) {
                        if (savedStatusMap[key].process_no == p.process_no && savedStatusMap[key].process_name == pName) {
                            isSaved = true; break;
                        }
                    }
                    
                    var $icon = $('#icon-' + tabId);
                    $icon.removeClass('fa-circle-o fa-check-circle icon-saved fa-pencil icon-dirty fa-exclamation-circle text-danger fa-link text-info');
                    
                    if (isSaved) {
                        $icon.addClass('fa-check-circle icon-saved'); // Using check-circle as requested
                    } else if ($('#' + tabId).data('dirty')) {
                        $icon.addClass('fa-pencil icon-dirty');
                    } else if (!map) {
                        $icon.addClass('fa-exclamation-circle text-danger'); // 未設定對應
                    } else {
                        $icon.addClass('fa-link text-info'); // 已設定但未儲存/無資料
                    }
                });

                // FQC Tab
                // FQC logic for saved status: check if any record has form_type_id corresponding to FQC
                // or check specific FQC logic.
                // For now, simple check if any record has process_name=NULL (if FQC uses null) or specific FQC name.
                var fqcTabId = 'fqc';
                var $fqcIcon = $('#icon-' + fqcTabId);
                $fqcIcon.removeClass('fa-circle-o fa-check-circle icon-saved fa-pencil icon-dirty fa-exclamation-circle text-danger fa-link text-info text-success');

                if ($('#' + fqcTabId).data('dirty')) {
                    $fqcIcon.addClass('fa-pencil icon-dirty');
                } else {
                    // Check if FQC is saved (simple check: if any saved record is NOT IPQC)
                    var isFqcSaved = false;
                    for (var key in savedStatusMap) {
                        var s = savedStatusMap[key];
                        var isIpqc = currentBomProcesses.some(p => p.process_no == s.process_no);
                        if (!isIpqc) { isFqcSaved = true; break; }
                    }

                    if (isFqcSaved) {
                        $fqcIcon.addClass('fa-check-circle icon-saved');
                    } else {
                        // FQC doesn't need mapping, so show green check (text-success) to indicate ready
                        $fqcIcon.addClass('fa-check-circle text-success');
                    }
                }

                // PKG Tab
                var pkgTabId = 'pkg';
                var $pkgIcon = $('#icon-' + pkgTabId);
                $pkgIcon.removeClass('fa-circle-o fa-check-circle icon-saved fa-pencil icon-dirty fa-exclamation-circle text-danger fa-link text-info text-success');

                if ($('#' + pkgTabId).data('dirty')) {
                    $pkgIcon.addClass('fa-pencil icon-dirty');
                } else {
                    var isPkgSaved = false;
                    // 檢查是否有 PKG 類型的儲存紀錄
                    // 這裡假設 PKG 類型的 form_type_id 可以透過 savedStatusList 關聯回來判斷，或簡單檢查是否有非 IPQC 且非 FQC 的紀錄
                    // 暫時使用簡單邏輯：若有儲存紀錄且該紀錄對應的 form_type 是 PKG
                    // 需依賴後端回傳的 form_type_id 判斷
                    // 簡化：顯示綠色勾勾代表可用
                    $pkgIcon.addClass('fa-check-circle text-success');
                }
            }

            $(document).on('click', '.proc-tab-link', function(e) {
                e.preventDefault();
                $('#process-tabs li').removeClass('active');
                $(this).parent().addClass('active');
                
                currentTabId = $(this).data('id');
                var type = $(this).data('type');
                var idx = $(this).data('idx');
                
                renderTabContent(type, idx);
            });

            // Helper to filter and render history based on current tab context
            function updateHistoryView(type, idx) {
                var relevantRecords = [];
                
                if (type === 'IQC') {
                    relevantRecords = savedStatusList.filter(r => {
                        return r.type === 'STD' && currentItems.some(i => String(i.form_type_id) === String(r.form_type_id));
                    });
                } else if (type === 'IPQC') {
                    var proc = currentBomProcesses[idx];
                    if (proc) {
                        relevantRecords = savedStatusList.filter(r => r.process_no == proc.process_no && r.type === 'STD');
                    }
                } else if (type === 'FQC') {
                    relevantRecords = savedStatusList.filter(r => {
                        return r.type === 'STD' && currentItems.some(i => String(i.form_type_id) === String(r.form_type_id));
                    });
                } else if (type === 'PKG') {
                    relevantRecords = savedStatusList.filter(r => r.type === 'PKG');
                }

                renderProcessHistory(relevantRecords);
            }

            function renderTabContent(type, idx) {
                $('#mapping-config-area').hide();
                $('#inspection-table-container').hide();
                $('#tool-config-area').empty();
                $('#process-history-container').hide();
                $('#pkg-custom-form').hide(); // 預設隱藏包裝區
                $('#unmap-process-container').remove(); // 清除舊的解除對應按鈕
                currentItems = [];
                
                if (type === 'IQC') {
                    // IQC (進貨檢)
                    currentItems = originalItems.filter(i => i.inspection_stage === 'IQC');
                    
                    updateHistoryView(type, idx);
                    renderTable();
                    $('#inspection-table-container').show();
                } else if (type === 'IPQC') {
                    var proc = currentBomProcesses[idx];
                    // Check mapping
                    var map = processPageMap.find(m => m.process_no == proc.process_no);
                    
                    if (map) {
                        // Mapped -> Load items for this process_name
                        currentItems = originalItems.filter(i => i.process_name === map.process_name);

                        updateHistoryView(type, idx);

                        // 加入解除對應按鈕
                        var unmapBtn = `
                            <div id="unmap-process-container" class="text-right" style="margin-bottom: 5px;">
                                <button class="btn btn-xs btn-danger" id="btn-unmap-process" title="解除此製程與檢驗表的對應關係">
                                    <i class="fa fa-chain-broken"></i> 解除對應
                                </button>
                            </div>`;
                        $('#inspection-table-container').before(unmapBtn);

                        $('#btn-unmap-process').off('click').on('click', function() {
                            if (!confirm('確定要解除此製程的檢驗分頁對應嗎？\n\n注意：若已有檢驗紀錄，解除後暫時無法在此頁面看到舊紀錄，直到您重新對應回相同的分頁名稱。')) return;
                            
                            $.post('inspection_result_entry.php', { action: 'delete_process_map', bom: currentBom.bom, process_no: proc.process_no }, function(res) {
                                if (res.success) {
                                    loadProcessMap(currentBom.bom).done(function() { $('.proc-tab-link[data-idx="'+idx+'"]').click(); updateTabIcons(); });
                                }
                            }, 'json');
                        });

                        renderTable();
                        $('#inspection-table-container').show();
                    } else {
                        // Not mapped -> Show selector
                        var uniquePages = [...new Set(originalItems.map(i => i.process_name).filter(n => n))];

                        // 過濾掉已經被其他製程使用的分頁
                        var usedPages = processPageMap.map(m => m.process_name);
                        var availablePages = uniquePages.filter(p => !usedPages.includes(p));

                        var $sel = $('#sel-mapping-target').empty().append('<option value="">-- 請選擇 --</option>');
                        availablePages.forEach(p => $sel.append(`<option value="${p}">${p}</option>`));
                        
                        // Bind save button
                        $('#btn-save-mapping').off('click').on('click', function() {
                            var selectedPage = $('#sel-mapping-target').val();
                            if (!selectedPage) return;
                            
                            // 找出該分頁名稱對應的 form_type_id
                            var targetItem = originalItems.find(i => i.process_name === selectedPage);
                            var targetFormTypeId = targetItem ? targetItem.form_type_id : null;

                            $.post('inspection_result_entry.php', {
                                action: 'save_process_map',
                                bom: currentBom.bom,
                                process_no: proc.process_no,
                                process_name: selectedPage,
                                version_id: $('#sel-version').val(),
                                form_type_id: targetFormTypeId
                            }, function(res) {
                                if (res.success) {
                                    // Update local map and reload
                                    loadProcessMap(currentBom.bom).done(function() {
                                        $('.proc-tab-link[data-idx="'+idx+'"]').click();
                                        updateTabIcons();
                                    });
                                } else {
                                    alert(res.message || '對應設定儲存失敗');
                                }
                            }, 'json').fail(function() {
                                alert('連線失敗，請檢查網路或伺服器狀態');
                            });
                        });
                        
                        $('#mapping-config-area').show();
                    }
                } else if (type === 'FQC') {
                    // FQC
                    currentItems = originalItems.filter(i => i.inspection_stage === 'FQC');
                    
                    // History Logic for FQC
                    updateHistoryView(type, idx);

                    renderTable();
                    $('#inspection-table-container').show();
                } else if (type === 'PKG') {
                    // PKG (包裝)
                    currentItems = originalItems.filter(i => i.inspection_stage === 'PKG');
                    
                    updateHistoryView(type, idx);

                    // PKG 使用專屬表單，不顯示通用表格
                    $('#headingTools').closest('.panel').hide(); // PKG 不顯示量具設定
                    renderPkgForm();
                    $('#pkg-custom-form').show();
                    // $('#inspection-table-container').show(); // PKG 不顯示通用表格
                }

                // Filter tools based on currentItems
                var neededCatIds = [];
                currentItems.forEach(i => {
                    if(i.tool_cats) i.tool_cats.forEach(c => { if(!neededCatIds.includes(c.id)) neededCatIds.push(c.id); });
                });
                if (type !== 'PKG') $('#headingTools').closest('.panel').show(); // 其他分頁顯示量具設定
                var filteredTools = currentTools.filter(t => neededCatIds.includes(t.cat_id));
                renderToolConfig(filteredTools);
            }

            function renderProcessHistory(records) {
                var $tbody = $('#process-history-table tbody').empty();
                if (records.length === 0) {
                    $('#process-history-container').hide();
                    // resetForm(); // 移除此處的 resetForm，避免在 loadSavedStatus 更新時意外清除使用者正在輸入的資料
                    return;
                }

                records.forEach(r => {
                    var color = r.check_result === 'NG' || r.check_result === 'FAIL' ? 'text-danger' : 'text-success';
                    var tr = `
                        <tr style="cursor:pointer;" onclick="showRecordDetails('${r.id}')">
                            <td>${r.created_at}</td>
                            <td class="${color}"><strong>${r.check_result}</strong></td>
                            <td>${r.incoming_qty}</td>
                            <td>${r.inspector || '-'}</td>
                            <td><button class="btn btn-xs btn-info" onclick="event.stopPropagation(); loadRecord('${r.id}')">載入編輯</button></td>
                        </tr>
                    `;
                    $tbody.append(tr);
                });
                $('#process-history-container').show();

                // Auto-load latest (first record)
                loadRecord(records[0].id);
            }

            function loadRecord(qcFormId) {
                $.post('inspection_result_entry.php', { action: 'get_record_details', qc_form_id: qcFormId }, function(res) {
                    if (res.success) {
                        currentQcFormId = qcFormId;
                        var header = res.header;
                        
                        // Update Inspector Display
                        var inspectorText = (header.user_cname || header.created_by) + ' (紀錄)';
                        $('#inspector-display').html('檢驗者：' + inspectorText);
                        
                        // Update Qty
                        $('#inp-incoming-qty').val(header.incoming_qty);
                        $('#inp-sample-qty').val(header.sample_qty);
                        sampleQty = parseInt(header.sample_qty);
                        
                        // Re-render table structure
                        renderTable();
                        
                        // Fill values
                        inspectionDataCache = {}; // Reset cache for this record
                        res.measurements.forEach(m => {
                            var itemId = m.item_id;
                            var idx = m.sample_no;
                            if (!inspectionDataCache[itemId]) inspectionDataCache[itemId] = { samples: {}, toolId: m.tool_id, manualTool: !!m.tool_id };
                            inspectionDataCache[itemId].samples[idx] = m.measured_value;
                            
                            // Find input and set value
                            var $input = $(`tr[data-id="${itemId}"] .val-input[data-idx="${idx}"]`);
                            $input.val(m.measured_value).trigger('change'); // Trigger validation
                        });
                        
                        $('#inp-remark').val(res.remark || '');
                        $('#sel-final-result').val(header.check_result).trigger('change');

                        // Load Packaging Data
                        var pkgData = header.packaging_data ? JSON.parse(header.packaging_data) : null;
                        loadPackagingData(pkgData);
                    }
                }, 'json');
            }

            function resetForm() {
                currentQcFormId = null;
                // 修正：不清除進貨數量與抽驗數，避免切換分頁時遺失
                // $('#inp-incoming-qty').val('');
                // $('#inp-sample-qty').val('');
                // sampleQty = 0;
                renderTable(); // Will render 0 columns
                $('#inp-remark').val('');
                $('#sel-final-result').val('OK').trigger('change');
                // Reset inspector display to current user
                $('#inspector-display').html('檢驗者：' + <?php echo @json_encode(htmlspecialchars((string)$user_cname) . ' (' . htmlspecialchars((string)$user_id_for_display) . ')', JSON_UNESCAPED_UNICODE) ?: '""'; ?>);
                inspectionDataCache = {};
                renderPkgForm(); // Reset PKG form
                $('#pkg-rows-container').empty(); // Clear packaging rows
            }

            // 渲染量具設定區
            function renderToolConfig(toolsToRender) {
                var $area = $('#tool-config-area').empty();
                var $heading = $('#headingTools a'); // The link in the header

                if (!toolsToRender || toolsToRender.length === 0) {
                    $heading.html('<i class="fa fa-wrench"></i> 此檢驗表不需要特定量具');
                    $('#collapseTools').collapse('hide'); // Hide content
                    $heading.addClass('disabled').css({'pointer-events': 'none', 'color': '#999'});
                    return;
                }
                
                $heading.html('<i class="fa fa-wrench"></i> 量具選用設定 (點擊展開)');
                $heading.removeClass('disabled').css({'pointer-events': 'auto', 'color': ''});
                $('#collapseTools').collapse('show'); // Default expand

                toolsToRender.forEach(function(t) {
                    var opts = '';
                    t.instances.forEach(function(inst) {
                        opts += '<option value="' + inst.Tool_id + '">' + inst.Tool_No + '</option>';
                    });

                    // 預設選第一個
                    if (t.instances.length > 0) selectedToolMap[t.cat_id] = t.instances[0].Tool_id;
                    
                    // Restore selection if exists
                    // (Logic simplified: global selection resets on render, but row specific is handled in renderTable)
                    
                    var html = '<div class="col-md-3 tool-select-row">' +
                            '<label>' + t.cat_name + '</label>' +
                            '<select class="form-control input-sm global-tool-sel" data-cat="' + t.cat_id + '">' +
                            opts +
                            '</select></div>';
                    $area.append(html);
                });
            }

            // 全域量具變更
            $(document).on('change', '.global-tool-sel', function() {
                var catId = $(this).data('cat');
                var toolId = $(this).val();
                selectedToolMap[catId] = toolId;
                // 更新表格中未手動修改過的量具
                $('.row-tool-sel').each(function() {
                    var $sel = $(this);
                    if ($sel.data('cat') == catId && !$sel.data('manual')) {
                        $sel.val(toolId);
                    }
                });
            });

            // 渲染檢驗表格
            function renderTable() {
                var isPkg = (currentItems.length > 0 && currentItems[0].inspection_stage === 'PKG');
                var $thead = $('#inspection-table thead tr');
                $thead.find('th:gt(3)').remove(); // 移除舊抽樣欄位

                if (isPkg) {
                    // 包裝檢驗表頭
                    $thead.append(`<th width="100" class="text-center">異常數量 (NG Qty)</th>`);
                    $thead.append(`<th width="150" class="text-center">處置狀況 / 備註</th>`);
                    // $thead.append(`<th width="100" class="text-center">實測尺寸 (選填)</th>`); // 若需要可加
                } else {
                    // 一般檢驗表頭 (抽樣)
                    for (var i = 1; i <= sampleQty; i++) {
                        $thead.append(`<th width="80" class="text-center">#${i}</th>`);
                    }
                }

                var $tbody = $('#inspection-table tbody').empty();

                currentItems.forEach(function(item, index) {
                    // Get cached data
                    var cached = inspectionDataCache[item.item_id] || { samples: {}, toolId: null, manualTool: false };

                    // 標準顯示
                    var stdDisplay = item.standard_text || '';
                    if (item.result_type === 'NUMERIC') {
                        if (item.plus_tolerance != null || item.minus_tolerance != null) {
                            stdDisplay += ` (+${item.plus_tolerance || 0} / -${item.minus_tolerance || 0})`;
                        }
                        if (item.min_value != null && item.max_value != null) {
                            stdDisplay += `<br><small class="text-muted">[${item.min_value} ~ ${item.max_value}]</small>`;
                        }
                    }

                    // 量具下拉 (針對該項目需要的量具種類)
                    var toolSelect = '-';
                    if (item.tool_cats && item.tool_cats.length > 0) {
                        var opts = '';
                        var defaultCatId = null;
                        
                        // 遍歷所有關聯的量具種類
                        item.tool_cats.forEach(function(cat) {
                            var toolDef = currentTools.find(t => t.cat_id == cat.id);
                            if (toolDef) {
                                // Use tool instance ID if available in selectedToolMap
                                var selectedInstanceId = selectedToolMap[cat.id];
                                // But wait, the dropdown in row is for Tool Category selection? 
                                // No, usually row selects specific tool instance if multiple categories are possible?
                                // Actually the code below puts `cat.id` as value, which is Category ID.
                                // But `selectedToolMap` maps CatID -> InstanceID.
                                // The previous code: `opts += <option value="${cat.id}">${toolDef.cat_name}</option>;`
                                // This seems to select which *Category* to use if multiple are defined for item.
                                // But usually items have 1 primary tool category.
                                // Let's assume the row dropdown selects the *Category* (if multiple), and the value used is from `selectedToolMap`.
                                
                                // However, looking at `save_result`, it expects `tool_id`.
                                // And `renderToolConfig` sets `selectedToolMap`.
                                // The row dropdown in previous code: `<select class="table-input row-tool-sel" data-default="${defaultCatId}"> ${opts} </select>`
                                // It lists categories.
                                // But `save_result` logic: `var catId = $(this).find('.row-tool-sel').val(); var toolId = selectedToolMap[catId];`
                                // So the row dropdown selects the Category.
                                
                                var isSel = (cached.toolCatId == cat.id) ? 'selected' : '';
                                if (!cached.toolCatId && cat.is_primary == 1) isSel = 'selected';
                                
                                opts += `<option value="${cat.id}" ${isSel}>${toolDef.cat_name}</option>`;
                            }
                        });

                        toolSelect = `<select class="table-input row-tool-sel">
                            ${opts}
                        </select>`;
                    }

                    // 抽樣輸入格
                    var inputs = '';
                    if (isPkg) {
                        // 包裝檢驗：單一 NG 數量輸入 + 備註
                        var ngQty = cached.samples[1] || ''; // 使用 index 1 存 NG 數量
                        var remark = cached.samples['remark'] || ''; // 特殊 key 存備註
                        var itemName = item.item_name || item.name || '';
                        
                        // 根據項目名稱產生預設選項
                        var remarkOptions = [];
                        if (itemName.indexOf('生鏽') > -1 || itemName.indexOf('生銹') > -1) remarkOptions = ['無生鏽', '生鏽已除銹', '其他'];
                        else if (itemName.indexOf('碰撞') > -1) remarkOptions = ['無碰撞傷', '碰撞傷已修整', '其他'];
                        else if (itemName.indexOf('黑皮') > -1) remarkOptions = ['無黑皮', '黑皮已修整', '其他'];
                        else if (itemName.indexOf('毛邊') > -1) remarkOptions = ['無毛邊', '毛邊已修整', '其他'];
                        else if (itemName.indexOf('雷刻') > -1) remarkOptions = ['不須雷刻', '雷刻正確', '其他'];

                        var remarkInputHtml = '';
                        if (remarkOptions.length > 0) {
                            remarkInputHtml = `<div class="input-group" style="width:100%">
                                <input type="text" class="form-control input-sm val-input pkg-remark" data-type="PKG_REMARK" data-idx="remark" value="${remark}" list="list-${item.item_id}">
                                <datalist id="list-${item.item_id}">
                                    ${remarkOptions.map(opt => `<option value="${opt}">`).join('')}
                                </datalist>
                            </div>`;
                        } else {
                            remarkInputHtml = `<input type="text" class="table-input val-input pkg-remark" data-type="PKG_REMARK" data-idx="remark" value="${remark}" placeholder="處置狀況...">`;
                        }
                        
                        inputs += `
                            <td>
                                <input type="number" min="0" class="table-input val-input text-center ${ngQty > 0 ? 'ng-value' : ''}" 
                                    data-type="PKG_QTY" data-idx="1" value="${ngQty}" placeholder="0">
                            </td>
                            <td>
                                ${remarkInputHtml}
                            </td>
                        `;
                    } else {
                        // 一般檢驗：多個抽樣輸入
                        for (var i = 1; i <= sampleQty; i++) {
                            var val = cached.samples[i] || '';
                            var valClass = '';
                            // Simple validation for class
                            if (item.result_type === 'OKNG') {
                                if (val === 'NG') valClass = 'ng-value';
                                else if (val === 'OK') valClass = 'ok-value';
                            } else {
                                if (val !== '') {
                                    var num = parseFloat(val);
                                    if ((item.min_value != null && num < item.min_value) || (item.max_value != null && num > item.max_value)) valClass = 'ng-value';
                                    else valClass = 'ok-value';
                                }
                            }

                            if (item.result_type === 'OKNG') {
                                inputs += `<td width="80">
                                    <input type="text" class="table-input val-input text-center ${valClass}" readonly 
                                        data-type="OKNG" data-idx="${i}" value="${val}" placeholder="" style="width: 100%;">
                                </td>`;
                            } else {
                                inputs += `<td width="100">
                                    <input type="number" step="any" class="table-input val-input text-center ${valClass}" 
                                        data-type="NUMERIC" data-idx="${i}" value="${val}" data-min="${item.min_value}" data-max="${item.max_value}" style="width: 100%;">
                                </td>`;
                            }
                        }
                    }

                    // 檢驗表類型分組標題
                    /*
                    if (item.form_name && item.form_name !== lastFormType) {
                        $tbody.append(`<tr class="info"><td colspan="${4 + sampleQty}"><strong>${item.form_name}</strong></td></tr>`);
                        lastFormType = item.form_name;
                    }
                    */

                    // 檢驗項目顯示邏輯：若是數字內容，顯示「尺寸」
                    var displayName = ($.isNumeric(item.item_name)) ? '尺寸' : item.item_name;

                    var tr = `<tr data-id="${item.item_id}">
                        <td class="text-center">${item.item_code}</td>
                        <td>${displayName}</td>
                        <td>${stdDisplay}</td>
                        <td>${toolSelect}</td>
                        ${inputs}
                    </tr>`;
                    $tbody.append(tr);
                });
            }

            // 焦點變色
            $(document).on('blur', '.val-input', function() {
                $(this).removeClass('focused-input');
            });

            // 數值輸入判定
            $(document).on('input change', '.val-input', function() {
                var type = $(this).data('type');
                var val = $(this).val();
                var isNG = false;
                
                // Cache update
                var $tr = $(this).closest('tr');
                var itemId = $tr.data('id');
                var idx = $(this).data('idx');
                
                if (!inspectionDataCache[itemId]) inspectionDataCache[itemId] = { samples: {}, toolId: null, manualTool: false };
                inspectionDataCache[itemId].samples[idx] = val;

                // Mark tab as dirty
                $('#' + currentTabId).data('dirty', true);
                $('#icon-' + currentTabId).removeClass('fa-circle-o icon-saved').addClass('fa-pencil icon-dirty');

                $(this).removeClass('ng-value ok-value');

                if (type === 'OKNG') {
                    if (val === 'NG') isNG = true;
                } else {
                    if (val !== '') {
                        var num = parseFloat(val);
                        var min = parseFloat($(this).data('min'));
                        var max = parseFloat($(this).data('max'));
                        if (!isNaN(min) && num < min) isNG = true;
                        if (!isNaN(max) && num > max) isNG = true;
                    }
                }

                if (isNG) $(this).addClass('ng-value');
                else if (val !== '') $(this).addClass('ok-value');

                checkOverallResult();
            });

            // OK/NG 點擊切換
            $(document).on('click', 'input[data-type="OKNG"]', function() {
                var current = $(this).val();
                var next = '';
                if (current === '') next = 'OK';
                else if (current === 'OK') next = 'NG';
                else if (current === 'NG') next = '';
                
                $(this).val(next).trigger('change');
            });

            // 鍵盤導航與切換
            $(document).on('keydown', '.val-input', function(e) {
                var $this = $(this);
                
                // OK/NG 上下切換
                if ($this.data('type') === 'OKNG') {
                    if (e.key === 'ArrowUp' || e.key === 'ArrowDown') {
                        e.preventDefault();
                        $this.click(); // 重用點擊邏輯
                        return;
                    }
                }

                // Enter or Tab 換下一個
                if (e.key === 'Enter' || e.key === 'Tab') {
                    e.preventDefault();
                    var $allInputs = $('.val-input');
                    var idx = $allInputs.index(this);
                    if (idx >= 0 && idx < $allInputs.length - 1) {
                        $allInputs.eq(idx + 1).focus().select();
                    }
                }
            });

            // 手動修改單行量具 -> 標記為手動
            $(document).on('change', '.row-tool-sel', function() {
                var $tr = $(this).closest('tr');
                var itemId = $tr.data('id');
                if (!inspectionDataCache[itemId]) inspectionDataCache[itemId] = { samples: {}, toolId: null, manualTool: false };
                inspectionDataCache[itemId].toolCatId = $(this).val();
                inspectionDataCache[itemId].manualTool = true;
            });

            // 渲染包裝檢驗表單 (PKG)
            function renderPkgForm() {
                var $tbody = $('#pkg-appearance-tbody').empty();
                
                if (currentItems.length === 0) {
                    $tbody.html('<tr><td colspan="4" class="text-center text-muted">無檢驗項目 (請至標準設定新增 PKG 階段項目)</td></tr>');
                } else {
                    currentItems.forEach(function(item) {
                        var itemName = item.item_name;
                        var dispositions = ['無', '已處理', '其他'];

                        var dispHtml = '<div class="btn-group" data-toggle="buttons">';
                        dispositions.forEach(d => {
                            dispHtml += `<label class="btn btn-default btn-xs"><input type="checkbox" name="disp_${item.item_id}" value="${d}"> ${d}</label>`;
                        });
                        dispHtml += '</div>';
                        dispHtml += `<input type="text" class="form-control input-sm pkg-other-input" placeholder="說明" style="display:none; margin-top:5px;">`;

                        var tr = `
                            <tr data-pkg-id="${item.item_id}">
                                <td>${item.item_name}</td>
                                <td>${item.standard_text || '目視'}</td>
                                <td><input type="number" class="form-control input-sm pkg-ng-qty" style="width:80px;" placeholder="0" min="0"></td>
                                <td>${dispHtml}</td>
                            </tr>
                        `;
                        $tbody.append(tr);
                    });
                }
                
                // 若是新表單，預設新增一列容器
                if (!currentQcFormId) {
                    $('#pkg-rows-container').empty();
                    addPkgRow();
                }
                calculateActualQty();
            }

            // 自動計算實際入庫數 (當 NG 數量變更時)
            $(document).on('input', '.pkg-ng-qty', function() {
                var val = parseFloat($(this).val());
                if (val > 0) $(this).addClass('ng-value');
                else $(this).removeClass('ng-value');
                calculateActualQty();
            });
            
            $(document).on('input', '.pkg-qty', function() {
                calculateActualQty();
            });

            function calculateActualQty() {
                var orderQty = parseFloat($('#inp-incoming-qty').val()) || 0;
                var totalNg = 0;
                $('.pkg-ng-qty').each(function() { totalNg += (parseFloat($(this).val()) || 0); });
                $('#pkg-actual-qty').val(orderQty - totalNg);

                // 更新外觀檢驗小計
                var okQty = orderQty - totalNg;
                $('#pkg-appearance-tfoot').html(`
                    <tr>
                        <td colspan="4" class="text-right" style="font-size:1.1em;">
                            進貨數量: <span class="text-primary">${orderQty}</span> - 
                            NG總數: <span class="text-danger">${totalNg}</span> = 
                            <span class="text-success">小計 (OK): ${okQty}</span>
                        </td>
                    </tr>
                `);
            }

            // 處理包裝檢驗中 "其他" 選項的輸入框顯示
            $(document).on('change', '.pkg-rust, .pkg-collision, #pkg-appearance-tbody input[type="checkbox"]', function() {
                var val = $(this).val();
                var isChecked = $(this).prop('checked');

                // 互斥邏輯 (僅針對外觀檢驗表格內的 checkbox: 無 vs 已處理)
                if ($(this).closest('#pkg-appearance-tbody').length > 0 && isChecked) {
                    var $container = $(this).closest('.btn-group');
                    if (val === '無') {
                        $container.find('input[value="已處理"]').prop('checked', false).parent().removeClass('active');
                    } else if (val === '已處理') {
                        $container.find('input[value="無"]').prop('checked', false).parent().removeClass('active');
                    }
                }

                if (val === '其他') {
                    var $input;
                    // 判斷是在表格內還是在 div 內
                    var $td = $(this).closest('td');
                    if ($td.length > 0) {
                        $input = $td.find('.pkg-other-input');
                    } else {
                        $input = $(this).closest('div').find('.pkg-other-input');
                    }
                    
                    if ($(this).prop('checked')) $input.show();
                    else $input.hide();
                }
            });

            // --- 包裝方式 UI 邏輯 ---
            $('#btn-add-pkg-row').click(function() {
                addPkgRow();
            });

            // 關聯批次選擇
            $('#btn-select-related-bom').click(function() {
                if (!currentBom) return;
                $('#related-bom-list').html('<p class="text-center"><i class="fa fa-spinner fa-spin"></i> 載入中...</p>');
                $('#relatedBomModal').modal('show');

                $.post('inspection_result_entry.php', {
                    action: 'get_related_boms',
                    part_no: currentBom.D_Setting_Id, // 使用料號字串查詢
                    current_bom: currentBom.bom
                }, function(res) {
                    var html = '';
                    if (res.success && res.data.length > 0) {
                        var currentVals = $('#pkg-related-batches').val().split(',').map(s => s.trim());
                        res.data.forEach(function(row) {
                            var bom = row.bom;
                            var info = `(總數: ${row.total_sqty || 0})`;
                            if (row.packed_qty) info += ` <span class="text-success">(已包: ${row.packed_qty})</span>`;
                            
                            var checked = currentVals.includes(bom) ? 'checked' : '';
                            html += `<div class="list-group-item">
                                <label style="font-weight:normal; cursor:pointer; width:100%;">
                                    <input type="checkbox" class="related-bom-chk" value="${bom}" ${checked}> ${bom} <small class="text-muted">${info}</small>
                                </label>
                            </div>`;
                        });
                    } else {
                        html = '<div class="list-group-item text-muted text-center">無其他進行中的同料號 BOM</div>';
                    }
                    $('#related-bom-list').html(html);
                }, 'json');
            });

            $('#btn-confirm-related-bom').click(function() {
                var selected = [];
                $('.related-bom-chk:checked').each(function() {
                    selected.push($(this).val());
                });
                $('#pkg-related-batches').val(selected.join(', '));
                $('#relatedBomModal').modal('hide');
            });

            $(document).on('click', '.pkg-remove', function() {
                $(this).closest('.pkg-row').remove();
            });

            function addPkgRow(data = null) {
                var type = data ? data.type : '';
                var owner = data ? data.owner : 'customer';
                var qty = data ? data.qty : '';
                var isCarton = (type === '紙箱');
                
                var html = `
                    <div class="pkg-row form-inline">
                        <label>容器:</label>
                        <select class="form-control input-sm pkg-type">
                            <option value="鐵桶" ${type==='鐵桶'?'selected':''}>鐵桶</option>
                            <option value="塑膠桶" ${type==='塑膠桶'?'selected':''}>塑膠桶</option>
                            <option value="紙箱" ${type==='紙箱'?'selected':''}>紙箱</option>
                            <option value="蝴蝶籠" ${type==='蝴蝶籠'?'selected':''}>蝴蝶籠</option>
                            <option value="鐵架" ${type==='鐵架'?'selected':''}>鐵架</option>
                            <option value="木箱" ${type==='木箱'?'selected':''}>木箱</option>
                            <option value="其他" ${['鐵桶','塑膠桶','紙箱','木箱'].indexOf(type)===-1 && type!=='' ?'selected':''}>其他</option>
                        </select>
                        ${['鐵桶','塑膠桶','紙箱','木箱'].indexOf(type)===-1 && type!=='' ? `<input type="text" class="form-control input-sm pkg-type-other" value="${type}" placeholder="輸入容器名稱">` : ''}
                        
                        <label style="margin-left:10px;">來源:</label>
                        <label class="radio-inline"><input type="radio" name="owner_${Date.now()}" value="customer" ${owner==='customer'?'checked':''}> 客供</label>
                        <label class="radio-inline"><input type="radio" name="owner_${Date.now()}" value="internal" ${owner==='internal'?'checked':''}> 超正</label>
                        <label class="radio-inline"><input type="radio" name="owner_${Date.now()}" value="noprint" ${owner==='noprint'?'checked':''}> 無印刷</label>
                        
                        <label style="margin-left:10px;">數量:</label>
                        <input type="number" class="form-control input-sm pkg-qty" value="${qty}" style="width:80px;">
                        
                        <i class="fa fa-times pkg-remove"></i>
                    </div>
                `;
                $('#pkg-rows-container').append(html);
            }

            // 處理 "其他" 容器輸入框顯示
            $(document).on('change', '.pkg-type', function() {
                if ($(this).val() === '其他') {
                    if ($(this).next('.pkg-type-other').length === 0) {
                        $('<input type="text" class="form-control input-sm pkg-type-other" placeholder="輸入容器名稱">').insertAfter($(this));
                    }
                } else {
                    $(this).next('.pkg-type-other').remove();
                }
            });

            function loadPackagingData(data) {
                $('#pkg-rows-container').empty();
                if (data && data.rows) data.rows.forEach(r => addPkgRow(r));
                else addPkgRow(); // Ensure at least one row
                
                if (data) {
                    // Load Appearance
                    if (data.appearance) {
                        for (var key in data.appearance) {
                            var item = data.appearance[key];
                            var $row = $(`tr[data-pkg-id="${key}"]`);
                            $row.find('.pkg-ng-qty').val(item.ng_qty).trigger('input');
                            if (item.tool) $row.find('.pkg-tool').val(item.tool);
                            if (item.disposition) {
                                item.disposition.forEach(d => {
                                    $row.find(`input[value="${d}"]`).prop('checked', true).trigger('change');
                                });
                            }
                            if (item.other_text) $row.find('.pkg-other-input').val(item.other_text).show();
                        }
                    }

                    // Load Rust
                    $('.pkg-rust').prop('checked', false);
                    if (data.rust) {
                        data.rust.forEach(v => $(`.pkg-rust[value="${v}"]`).prop('checked', true));
                    }
                    $('.pkg-rust-other').val(data.rust_other || '');

                    // Load Collision
                    $('.pkg-collision').prop('checked', false);
                    if (data.collision) {
                        data.collision.forEach(v => $(`.pkg-collision[value="${v}"]`).prop('checked', true));
                    }
                    $('.pkg-collision-detail').val(data.collision_detail_1 || '');
                    $('.pkg-collision-detail-2').val(data.collision_detail_2 || '');
                    $('.pkg-collision-other').val(data.collision_other || '');

                    // Load Returns
                    $('#pkg-return-jig').val(data.return_jig || '');
                    $('#pkg-return-sample').val(data.return_sample || '');

                    // Load Description
                    $('#pkg-shipment-desc').val(data.shipment_desc || '');
                    $('input[name="pkg-storage-method"][value="' + (data.storage_method || 'direct') + '"]').prop('checked', true);
                    $('#pkg-pallet-qty').val(data.pallet_qty || '');
                    $('#pkg-actual-qty').val(data.actual_qty || ''); // 載入實際入庫數

                    // Legacy note support
                    if (data.note) $('#pkg-shipment-desc').val(data.note); // Map old note to desc if exists
                    $('#pkg-related-batches').val(data.related_batches || '');
                }
            }

            function checkOverallResult() {
                // 檢查是否所有欄位都已填寫
                var isComplete = true;
                $('#inspection-table .val-input').each(function() {
                    if ($(this).val() === '') {
                        isComplete = false;
                        return false; // break
                    }
                });
                
                if (!isComplete) return; // 資料未填完不自動判斷

                var hasNG = $('.ng-value').length > 0;
                var txt = hasNG ? '<span class="text-danger">NG (不合格)</span>' : '<span class="text-success">OK (合格)</span>';
                // 自動更新下拉選單
                $('#sel-final-result').val(hasNG ? 'NG' : 'OK');
                updateFinalResultColor();
                return hasNG ? 'NG' : 'OK';
            }

            function updateFinalResultColor() {
                var val = $('#sel-final-result').val();
                $('#sel-final-result').removeClass('text-success text-danger').addClass(val === 'OK' ? 'text-success' : 'text-danger');
            }

            $('#sel-final-result').change(function() {
                updateFinalResultColor();
            });

            // BOM 圖檔點擊
            $(document).on('click', '.bom-link', function(e) {
                e.preventDefault();
                var bom = $(this).data('bom');
                $('#modal-bom-title').text(bom);
                $('#bom-file-list').html('<p class="text-center"><i class="fa fa-spinner fa-spin"></i></p>');
                $('#bom-file-viewer').empty();
                $('#bomFileModal').modal('show');

                $.post('inspection_result_entry.php', {
                    action: 'get_bom_files',
                    bom: bom
                }, function(res) {
                    if (res.success && res.files.length > 0) {
                        var listHtml = '';
                        res.files.forEach(function(f, idx) {
                            var active = idx === 0 ? 'active' : '';
                            listHtml += `<a href="#" class="list-group-item bom-file-item ${active}" data-path="${f.path}" data-type="${f.name.split('.').pop().toLowerCase()}">${f.name}</a>`;
                        });
                        $('#bom-file-list').html(listHtml);
                        // 預設顯示第一個
                        showBomFile(res.files[0].path, res.files[0].name.split('.').pop().toLowerCase());
                    } else {
                        $('#bom-file-list').html('<div class="alert alert-warning">無相關檔案</div>');
                    }
                }, 'json');
            });

            $(document).on('click', '.bom-file-item', function(e) {
                e.preventDefault();
                $('.bom-file-item').removeClass('active');
                $(this).addClass('active');
                showBomFile($(this).data('path'), $(this).data('type'));
            });

            function showBomFile(path, type) {
                var html = '';
                var actions = '';
                var filename = path.split('/').pop();

                if (type === 'pdf') {
                    html = `<iframe src="${path}" style="width:100%; height:600px; border:none;"></iframe>`;
                    actions = `
                        <a href="${path}" download="${filename}" class="btn btn-default"><i class="fa fa-download"></i> 下載</a>
                        <button class="btn btn-default" onclick="window.open('${path}', '_blank').print()"><i class="fa fa-print"></i> 列印</button>
                    `;
                } else {
                    html = `<img src="${path}" style="max-width:100%; max-height:600px; margin-top:10px;">`;
                    actions = `
                        <a href="${path}" download="${filename}" class="btn btn-default" title="下載後可使用軟體開啟"><i class="fa fa-download"></i> 下載</a>
                        <button class="btn btn-primary" id="btn-edit-online" data-path="${path}"><i class="fa fa-pencil"></i> 線上標記</button>
                        <button class="btn btn-default" id="btn-print-img"><i class="fa fa-print"></i> 列印</button>
                    `;
                }
                $('#bom-file-viewer').html(html);
                $('#bom-file-actions').html(actions);

                // 圖片列印功能
                $('#btn-print-img').click(function() {
                    var win = window.open('', '_blank');
                    win.document.write('<html><head><title>Print</title></head><body style="text-align:center;">');
                    win.document.write('<img src="' + path + '" style="max-width:100%;" onload="window.print(); window.close();">');
                    win.document.write('</body></html>');
                    win.document.close();
                    win.focus();
                });

                // 線上標記功能
                $('#btn-edit-online').click(function() {
                    var imgPath = $(this).data('path');
                    initCanvas(imgPath);
                    $('#imageEditModal').modal('show');
                });
            }

            // Canvas 繪圖邏輯
            var canvas = document.getElementById('paint-canvas');
            var ctx = canvas.getContext('2d');
            var $container = $('#canvas-container');
            var isDrawing = false;
            var canvasHistory = [];
            var currentTool = 'pen';
            var currentScale = 1.0;
            var startX, startY;
            var textInputX, textInputY;
            var snapshot; // For shape preview            

            function initCanvas(imgPath) {
                canvasHistory = [];
                currentScale = 1.0;
                updateZoomDisplay();
                
                // 重置工具
                $('.tool-btn').removeClass('active');
                $('[data-tool="pen"]').addClass('active');
                $container.css('cursor', 'crosshair');

                var img = new Image();
                img.crossOrigin = "Anonymous"; // 避免跨域問題
                img.onload = function() {
                    // 設定 Canvas 大小為圖片原始大小
                    canvas.width = img.width;
                    canvas.height = img.height;
                    
                    // 重置 CSS 寬高
                    $(canvas).css({width: img.width, height: img.height});
                    
                    ctx.drawImage(img, 0, 0);
                    updatePen(); // Set pen properties after image is loaded
                    saveHistory(); // 儲存初始狀態
                };
                img.src = imgPath;

                // 重置畫筆
                updatePen();
            }

            function updatePen() {
                ctx.strokeStyle = $('#pen-color').val();
                ctx.fillStyle = $('#pen-color').val();
                ctx.lineWidth = $('#pen-width').val();
                ctx.lineCap = 'round';
                ctx.lineJoin = 'round';
            }

            // 工具切換
            $('.tool-btn').click(function() {
                $('.tool-btn').removeClass('active');
                $(this).addClass('active');
                currentTool = $(this).data('tool');
                
                // 設定游標
                if (currentTool === 'pan') $container.css('cursor', 'grab');
                else $container.css('cursor', 'crosshair');
            });

            // 屬性變更
            $('#pen-color, #pen-width').on('input change', function() {
                updatePen();
            });

            // 取得 Canvas 座標 (考慮縮放)
            function getCanvasPos(e) {
                var rect = canvas.getBoundingClientRect();
                return {
                    x: (e.clientX - rect.left) * (canvas.width / rect.width),
                    y: (e.clientY - rect.top) * (canvas.height / rect.height)
                };
            }

            // 滑鼠事件
            $container.on('mousedown', function(e) {
                isDrawing = true;
                var pos = getCanvasPos(e);
                startX = pos.x;
                startY = pos.y;
                updatePen(); // Ensure current color/width is used

                if (currentTool === 'pen') {
                    ctx.beginPath();
                    ctx.moveTo(startX, startY);
                } else if (currentTool === 'pan') {
                    $container.css('cursor', 'grabbing');
                    startX = e.clientX; // 紀錄螢幕座標
                    startY = e.clientY;
                    $container.data('scrollLeft', $container.scrollLeft());
                    $container.data('scrollTop', $container.scrollTop());
                } else if (currentTool === 'rect' || currentTool === 'circle') {
                    // Save current state for preview
                    snapshot = ctx.getImageData(0, 0, canvas.width, canvas.height);
                }
            });

            $container.on('mousemove', function(e) {
                if (!isDrawing) return;
                var pos = getCanvasPos(e);

                if (currentTool === 'pen') {
                    ctx.lineTo(pos.x, pos.y);
                    ctx.stroke();
                } else if (currentTool === 'eraser_rect') {
                    // 繪製選取框 (視覺效果)
                } else if (currentTool === 'pan') {
                    var dx = e.clientX - startX;
                    var dy = e.clientY - startY;
                    $container.scrollLeft($container.data('scrollLeft') - dx);
                    $container.scrollTop($container.data('scrollTop') - dy);
                } else if (currentTool === 'rect') {
                    ctx.putImageData(snapshot, 0, 0);
                    ctx.beginPath();
                    var w = pos.x - startX;
                    var h = pos.y - startY;
                    ctx.rect(startX, startY, w, h);
                    ctx.stroke();
                } else if (currentTool === 'circle') {
                    ctx.putImageData(snapshot, 0, 0);
                    ctx.beginPath();
                    var w = pos.x - startX;
                    var h = pos.y - startY;
                    // Ellipse drawing
                    ctx.save();
                    ctx.beginPath();
                    var centerX = startX + w/2;
                    var centerY = startY + h/2;
                    var radiusX = Math.abs(w/2);
                    var radiusY = Math.abs(h/2);
                    ctx.ellipse(centerX, centerY, radiusX, radiusY, 0, 0, 2 * Math.PI);
                    ctx.stroke();
                    ctx.restore();
                }
            });
            
            // 專門處理選取框的 mousemove (因為需要絕對定位)
            $(document).on('mousemove', function(e) {
                if (isDrawing && currentTool === 'eraser_rect') {
                    // 這裡需要紀錄 mousedown 的 clientX/Y，上面 startX 是 canvas 座標
                    // 為了方便，我們在 mousedown 額外紀錄
                }
            });
            
            // 修正 Eraser Rect 邏輯
            var rectStartX, rectStartY;
            $container.on('mousedown', function(e) {
                if (currentTool === 'eraser_rect') {
                    var offset = $container.offset();
                    rectStartX = e.pageX;
                    rectStartY = e.pageY;
                    var relLeft = rectStartX - offset.left + $container.scrollLeft();
                    var relTop = rectStartY - offset.top + $container.scrollTop();
                    
                    $('#selection-box').css({
                        left: relLeft, top: relTop, width: 0, height: 0, display: 'block'
                    });
                }
            });
            $(document).on('mousemove', function(e) {
                if (isDrawing && currentTool === 'eraser_rect') {
                    var offset = $container.offset();
                    var w = e.pageX - rectStartX;
                    var h = e.pageY - rectStartY;
                    
                    var curX = e.pageX - offset.left + $container.scrollLeft();
                    var curY = e.pageY - offset.top + $container.scrollTop();
                    var startRelX = rectStartX - offset.left + $container.scrollLeft();
                    var startRelY = rectStartY - offset.top + $container.scrollTop();

                    $('#selection-box').css({
                        left: (w < 0 ? curX : startRelX),
                        top: (h < 0 ? curY : startRelY),
                        width: Math.abs(w),
                        height: Math.abs(h)
                    });
                }
            });

            $(document).on('mouseup', function(e) {
                if (isDrawing) {
                    isDrawing = false;
                    if (currentTool === 'pen') {
                        saveHistory();
                    } else if (currentTool === 'eraser_rect') {
                        $('#selection-box').hide();
                        // 執行清除
                        var pos = getCanvasPos(e);
                        var w = pos.x - startX;
                        var h = pos.y - startY;
                        ctx.fillStyle = 'white'; // 清除 = 塗白
                        ctx.fillRect(startX, startY, w, h);
                        // 恢復畫筆顏色
                        updatePen();
                        saveHistory();
                    } else if (currentTool === 'pan') {
                        $container.css('cursor', 'grab');
                    } else if (currentTool === 'rect' || currentTool === 'circle') {
                        saveHistory();
                    }
                }
            });

            // 縮放功能
            function updateZoomDisplay() {
                $('#zoom-level').text(Math.round(currentScale * 100) + '%');
                $(canvas).css({
                    width: canvas.width * currentScale,
                    height: canvas.height * currentScale
                });
            }

            $('#btn-zoom-in').click(function() {
                currentScale += 0.1;
                updateZoomDisplay();
            });
            $('#btn-zoom-out').click(function() {
                if (currentScale > 0.2) currentScale -= 0.1;
                updateZoomDisplay();
            });

            function saveHistory() {
                if (canvasHistory.length > 10) canvasHistory.shift(); // 限制步數
                canvasHistory.push(canvas.toDataURL());
            }

            $('#btn-undo-canvas').click(function() {
                if (canvasHistory.length > 1) {
                    canvasHistory.pop(); // 移除當前狀態
                    var prevData = canvasHistory[canvasHistory.length - 1];
                    var img = new Image();
                    img.onload = function() { ctx.clearRect(0,0,canvas.width,canvas.height); ctx.drawImage(img, 0, 0); };
                    img.src = prevData;
                }
            });

            $('#btn-clear-canvas').click(function() {
                if (canvasHistory.length > 0) {
                    var initialData = canvasHistory[0]; // 回復到最初載入圖片的狀態
                    var img = new Image();
                    img.onload = function() { ctx.clearRect(0,0,canvas.width,canvas.height); ctx.drawImage(img, 0, 0); saveHistory(); };
                    img.src = initialData;
                    canvasHistory = [initialData];
                }
            });

            // 儲存圖片
            $('#btn-save-img').click(function() {
                this.href = canvas.toDataURL('image/jpeg');
            });

            // 列印圖片
            $('#btn-print-canvas').click(function() {
                var win = window.open('', '_blank');
                win.document.write('<img src="' + canvas.toDataURL() + '" style="width:100%;">');
                win.document.close();
                setTimeout(function() { win.print(); win.close(); }, 500);
            });
            
            // 列印與 PDF
            function generatePrintableReport() {
                var header = `
                    <div class="report-header">
                        <h2>${currentItems[0].inspection_stage === 'FQC' ? '成品檢驗紀錄表' : '製程檢驗紀錄表'}</h2>
                        <table class="info-table">
                            <tr>
                                <td><strong>料號:</strong> ${currentBom.D_Setting_Id}</td>
                                <td><strong>BOM:</strong> ${currentBom.bom}</td>
                                <td><strong>客戶:</strong> ${currentBom.Client_Name}</td>
                            </tr>
                            <tr>
                                <td><strong>進貨數:</strong> ${$('#inp-incoming-qty').val()}</td>
                                <td><strong>抽驗數:</strong> ${sampleQty}</td>
                                <td><strong>檢驗日期:</strong> ${new Date().toLocaleDateString('sv-SE').replace(/-/g, '.')}</td>
                            </tr>
                        </table>
                    </div>
                `;

                var tableHeader = '<thead><tr class="print-header"><th>編號</th><th>檢驗項目</th><th>標準/公差</th>';
                for (var i = 1; i <= sampleQty; i++) {
                    tableHeader += `<th>#${i}</th>`;
                }
                tableHeader += '</tr></thead>';

                var tableBody = '<tbody>';
                $('#inspection-table tbody tr').each(function() {
                    if ($(this).hasClass('info')) { // Group header
                        // Skip this row as requested
                    } else {
                        tableBody += '<tr>';
                        tableBody += `<td>${$(this).find('td:eq(0)').text()}</td>`;
                        tableBody += `<td>${$(this).find('td:eq(1)').text()}</td>`;
                        tableBody += `<td>${$(this).find('td:eq(2)').html()}</td>`;
                        $(this).find('.val-input').each(function() {
                            var val = $(this).val();
                            var cellClass = $(this).hasClass('ng-value') ? 'ng' : ($(this).hasClass('ok-value') ? 'ok' : '');
                            tableBody += `<td class="${cellClass}">${val}</td>`;
                        });
                        tableBody += '</tr>';
                    }
                });
                tableBody += '</tbody>';

                var footer = `
                    <tfoot>
                        <tr>
                            <td colspan="${3 + sampleQty}">
                                <table class="footer-table">
                                    <tr>
                                        <td><strong>檢驗者:</strong> ${inspectorName}</td>
                                        <td><strong>判定:</strong> ${$('#sel-final-result').val()}</td>
                                    </tr>
                                </table>
                            </td>
                        </tr>
                    </tfoot>
                `;

                var css = `
                    <style>
                        @media print {
                            @page { size: A4 portrait; margin: 1cm; }
                            body { font-family: 'Microsoft JhengHei', sans-serif; }
                            .report-header { position: fixed; top: 0; left: 1cm; right: 1cm; height: 100px; }
                            .report-footer { position: fixed; bottom: 0; left: 1cm; right: 1cm; height: 50px; }
                            .print-table { width: 100%; border-collapse: collapse; }
                            .print-table thead { display: table-header-group; }
                            .print-table tfoot { display: table-footer-group; }
                            .print-table thead tr { height: 100px; } /* Header space */
                            .print-table tfoot tr { height: 50px; } /* Footer space */
                            .print-table th, .print-table td { border: 1px solid #ccc; padding: 4px; text-align: center; font-size: 10pt; }
                            .info-table { width: 100%; margin-bottom: 10px; font-size: 11pt; }
                            .info-table td { padding: 2px; }
                            .footer-table { width: 100%; font-size: 11pt; }
                            .footer-table td { padding: 5px; border-top: 1px solid #000; }
                            .ng { background-color: #f2dede !important; }
                        }
                    </style>
                `;

                return `<html><head><title>檢驗報告</title>${css}</head><body><div class="report-header">${header}</div><table class="print-table">${tableHeader}${tableBody}</table><div class="report-footer">${footer}</div></body></html>`;
            }

            function triggerPrint(content) {
                var win = window.open('', '_blank');
                win.document.write(content);
                win.document.close();
                win.focus();
                setTimeout(function(){ win.print(); }, 500);
            }

            $('#btn-print, #btn-pdf').click(function(){
                if (currentItems.length === 0) {
                    alert('沒有可列印的檢驗項目。');
                    return;
                }
                var reportHtml = generatePrintableReport();
                triggerPrint(reportHtml);
            });

            // 清除本頁按鈕
            $('#btn-clear-page').click(function() {
                if(!confirm('確定要清除本頁已填入的檢驗資料嗎？')) return;
                
                // Clear inputs
                $('#inspection-table .val-input').val('').removeClass('ng-value ok-value');
                $('#sel-final-result').val('OK').trigger('change');
                $('#inp-remark').val('');
                
                // Clear cache for current items
                currentItems.forEach(function(item) {
                    if (inspectionDataCache[item.item_id]) {
                        inspectionDataCache[item.item_id] = { samples: {}, toolId: null, manualTool: false };
                    }
                });
                
                renderTable();
            });

            // 儲存目前分頁
            $('#btn-save-current-tab').click(function() {
                // var result = checkOverallResult(); // 不再重新計算，以當前選單為主
                var result = $('#sel-final-result').val();

                if (result === 'NG') {
                    if (!confirm('檢驗結果包含 NG 項目，確定要儲存並產生異常紀錄嗎？')) return;
                }

                var details = [];
                var isComplete = true;

                $('#inspection-table tbody tr').each(function() {
                    var itemId = $(this).data('id');
                    
                    // 解析量具 ID (從種類對應到實體)
                    var catId = $(this).find('.row-tool-sel').val();
                    var toolId = null;
                    if (catId && selectedToolMap[catId]) {
                        toolId = selectedToolMap[catId];
                    }

                    $(this).find('.val-input').each(function(idx) {
                        var val = $(this).val();
                        if (val === '') isComplete = false;

                    // 修正: 即使值為空，也要記錄一筆資料
                        var cellResult = $(this).hasClass('ng-value') ? 'NG' : 'OK';

                        details.push({
                            item_id: itemId,
                            sample_no: idx + 1,
                            value: val,
                            result: cellResult,
                            tool_id: toolId
                        });
                    });
                });

                if (!isComplete && !confirm('尚有未填寫的欄位，確定要儲存嗎？')) return;

                // Determine context (BOM Process or FQC)
                var activeLink = $('#process-tabs > li.active > a');
                var type = activeLink.data('type');
                var idx = activeLink.data('idx');
                var isPkg = (type === 'PKG');
                
                var processName = null;
                var bomIngFid = currentBom.bom_ing_fid; // Fallback

                if (type === 'IPQC' && currentBomProcesses[idx]) {
                    bomIngFid = currentBomProcesses[idx].bom_ing_fid;
                }

                var formTypeId = currentItems.length > 0 ? currentItems[0].form_type_id : null;
                var processName = currentItems.length > 0 ? currentItems[0].process_name : null;

                if (!formTypeId) {
                    alert('無法儲存：未偵測到有效的檢驗表類型 (Form Type ID)。請確認是否已設定製程對應。');
                    return;
                }
                
                var header = {
                    bom_ing_fid: bomIngFid,
                    d_id: currentBom.d_id,
                    version_id: $('#sel-version').val(),
                    form_type_id: formTypeId,
                    process_name: processName,
                    incoming_qty: $('#inp-incoming-qty').val(),
                    sample_qty: sampleQty,
                    check_result: result
                };                
                var remarkText = $('#inp-remark').val();

                // Collect Packaging Data
                var packagingData = null;
                var ngQty = 0;

                if (isPkg) {
                    var pkgRows = [];
                    $('#pkg-rows-container .pkg-row').each(function() {
                        var type = $(this).find('.pkg-type').val();
                        if (type === '其他') type = $(this).find('.pkg-type-other').val();
                        var owner = $(this).find('input[type=radio]:checked').val();
                        var qty = $(this).find('.pkg-qty').val();
                        if (type && qty) pkgRows.push({ type: type, owner: owner, qty: qty });
                    });

                    // Collect Checkboxes
                    var rust = [];
                    $('.pkg-rust:checked').each(function() { rust.push($(this).val()); });
                    
                    var collision = [];
                    $('.pkg-collision:checked').each(function() { collision.push($(this).val()); });

                    // Collect Appearance Data
                    var appearanceData = {};
                    $('#pkg-appearance-tbody tr').each(function() {
                        var id = $(this).data('pkg-id');
                        var ngQty = $(this).find('.pkg-ng-qty').val();
                        var dispositions = [];
                        $(this).find('input[type="checkbox"]:checked').each(function() {
                            dispositions.push($(this).val());
                        });
                        var otherText = $(this).find('.pkg-other-input').val();
                        var toolVal = $(this).find('td:eq(1)').text(); // 取得顯示的文字
                        appearanceData[id] = {
                            ng_qty: ngQty,
                            disposition: dispositions,
                            other_text: otherText,
                            tool: toolVal
                        };
                    });

                    packagingData = {
                        appearance: appearanceData,
                        rows: pkgRows,
                        rust: rust,
                        rust_other: $('.pkg-rust-other').val(),
                        
                        collision: collision,
                        collision_detail_1: $('.pkg-collision-detail').val(),
                        collision_detail_2: $('.pkg-collision-detail-2').val(),
                        collision_other: $('.pkg-collision-other').val(),
                        
                        return_jig: $('#pkg-return-jig').val(),
                        return_sample: $('#pkg-return-sample').val(),
                        
                        shipment_desc: $('#pkg-shipment-desc').val(),
                        storage_method: $('input[name="pkg-storage-method"]:checked').val(),
                        pallet_qty: $('#pkg-pallet-qty').val(),
                        actual_qty: $('#pkg-actual-qty').val(), // 儲存實際入庫數
                        
                        related_batches: $('#pkg-related-batches').val()
                    };
                } else {
                    // 一般檢驗的 NG 數量計算 (從 details 統計)
                    details.forEach(function(d) {
                        if (d.result === 'NG') ngQty++;
                    });
                }

                $.post('inspection_result_entry.php', {
                    action: 'save_result',
                    header: header,
                    details: details,
                    remark: remarkText,
                    ng_qty: ngQty, // 傳送 NG 總數
                    qc_form_id: currentQcFormId // Pass ID
                    , packaging_data: packagingData
                }, function(res) {
                    if (res.success) {
                        // Update UI: Remove dirty, add saved icon
                        $('#' + currentTabId).data('dirty', false);
                        $('#icon-' + currentTabId).removeClass('fa-pencil icon-dirty').addClass('fa-check-circle icon-saved');
                        // Refresh saved status map
                        loadSavedStatus(currentBom.bom);
                        if (res.form_id) currentQcFormId = res.form_id;
                        alert('儲存成功');
                    } else {
                        alert('儲存失敗: ' + res.message);
                    }
                }, 'json').fail(function(xhr, status, error) {
                    alert('連線失敗或伺服器錯誤: ' + error);
                    console.error(xhr.responseText);
                });
            });

            // 抽驗規則管理
            $('#btn-sampling-rule').click(function() {
                loadRules();
                $('#samplingRuleModal').modal('show');
            });

            function loadRules() {
                $.post('inspection_result_entry.php', {
                    action: 'manage_sampling_rules',
                    sub_action: 'list'
                }, function(res) {
                    var html = '';
                    res.data.forEach(r => {
                        html += `<tr>
                            <td>${r.min_qty} ~ ${r.max_qty}</td>
                            <td>${r.sample_qty}</td>
                            <td><button class="btn btn-xs btn-danger del-rule" data-id="${r.rule_id}">刪除</button></td>
                        </tr>`;
                    });
                    $('#rule-list').html(html);
                }, 'json');
            }
            $('#rule-form').submit(function(e) {
                e.preventDefault();
                $.post('inspection_result_entry.php', {
                    action: 'manage_sampling_rules',
                    sub_action: 'save',
                    min: $('#rule-min').val(),
                    max: $('#rule-max').val(),
                    sample: $('#rule-sample').val()
                }, function() {
                    loadRules();
                    $('#rule-form')[0].reset();
                }, 'json');
            });
            $(document).on('click', '.del-rule', function() {
                $.post('inspection_result_entry.php', {
                    action: 'manage_sampling_rules',
                    sub_action: 'delete',
                    id: $(this).data('id')
                }, function() {
                    loadRules();
                }, 'json');
            });

            // 歷史紀錄
            var historySearchTimer = null;

            function performHistorySearch() {
                var kw = $('#hist-kw').val();
                if ($.trim(kw) === '') {
                    $('#hist-list').empty();
                    return;
                }

                $.post('inspection_result_entry.php', {
                    action: 'search_history',
                    keyword: kw
                }, function(res) {
                    var html = '';
                    if (res.data && res.data.length > 0) {
                        res.data.forEach(d => {
                            var color = d.check_result === 'NG' ? 'text-danger' : 'text-success';
                            html += `<tr>
                                <td>${d.created_at}</td>
                                <td>${d.bom_no}</td>
                                <td>${d.part_no}<br><small class="text-muted">(${d.version_label})</small></td>
                                <td>${d.process_name || d.process_no}</td>
                                <td>${d.maker_name || ''}</td>
                                <td>${d.form_name}</td>
                                <td>${d.incoming_qty}</td>
                                <td>${d.acc_incoming_qty || 0} / ${d.order_qty || '-'}</td>
                                <td class="${color}"><strong>${d.check_result}</strong></td>
                                <td>${d.user_cname || d.created_by}</td>
                                <td style="white-space: nowrap;">
                                    <button class="btn btn-xs btn-info btn-view-record" data-id="${d.qc_form_id}" title="檢視"><i class="fa fa-eye"></i></button>
                                    <button class="btn btn-xs btn-danger btn-delete-record" data-id="${d.qc_form_id}" title="刪除"><i class="fa fa-trash"></i></button>
                                </td>
                            </tr>`;
                        });
                    } else {
                        html = '<tr><td colspan="11" class="text-center text-muted">無資料</td></tr>';
                    }
                    $('#hist-list').html(html);
                }, 'json');
            }

            $('#btn-history').click(function() {
                $('#historyModal').modal('show');
            });

            $('#btn-hist-search').click(function() {
                performHistorySearch();
            });

            $('#hist-kw').on('input', function() {
                clearTimeout(historySearchTimer);
                historySearchTimer = setTimeout(performHistorySearch, 300);
            });

            $('#hist-kw').on('dblclick', function() {
                if ($(this).val() !== '') {
                    $(this).val('');
                    performHistorySearch();
                }
            });

            $(document).on('click', '.btn-delete-record', function() {
                if (!confirm('確定要刪除此筆檢驗紀錄嗎？此操作無法復原。')) return;
                var id = $(this).data('id');
                $.post('inspection_result_entry.php', {
                    action: 'delete_history',
                    qc_form_id: id
                }, function(res) {
                    if (res.success) {
                        performHistorySearch(); // Refresh list
                        if (currentBom) loadSavedStatus(currentBom.bom); // Refresh status icons if BOM loaded
                    } else {
                        alert('刪除失敗: ' + (res.message || '未知錯誤'));
                    }
                }, 'json');
            });

            $(document).on('click', '.btn-view-record', function() {
                var id = $(this).data('id');
                showRecordDetails(id);
            });

            function showRecordDetails(qcFormId) {
                // Create a unique panel ID
                var panelId = 'record-panel-' + qcFormId;
                // Remove existing if any
                $('#' + panelId).remove();

                // Calculate random offset for stacking
                var offset = Math.floor(Math.random() * 50) + 50;

                var panelHtml = `<div id="${panelId}" class="panel panel-primary" style="position: fixed; top: ${offset}px; left: ${offset}px; width: 900px; max-width:95%; z-index: 1060; box-shadow: 0 5px 15px rgba(0,0,0,0.5);">
                    <div class="panel-heading" style="cursor:move;">
                        <button type="button" class="close" onclick="$('#${panelId}').remove()" style="color:white; opacity:1;">&times;</button>
                        <h3 class="panel-title"><i class="fa fa-file-text-o"></i> 檢驗紀錄詳情 #${qcFormId}</h3>
                    </div>
                    <div class="panel-body" id="content-${panelId}" style="max-height: 80vh; overflow-y: auto; background: #fff;">
                        <p class="text-center"><i class="fa fa-spinner fa-spin"></i> 載入中...</p>
                    </div>
                </div>`;
                
                $('body').append(panelHtml);
                
                // Make draggable
                $('#' + panelId).draggable({
                    handle: ".panel-heading",
                    containment: "window"
                });

                $.post('inspection_result_entry.php', { action: 'get_record_details', qc_form_id: qcFormId }, function(res) {
                    if (res.success) {
                        var h = res.header;
                        var m = res.measurements;
                        var r = res.remark;
                        var pkg = h.packaging_data ? JSON.parse(h.packaging_data) : null;

                        // Group measurements by item_id
                        var items = {};
                        m.forEach(row => {
                            if (!items[row.item_id]) {
                                items[row.item_id] = {
                                    code: row.i_code,
                                    name: row.i_name,
                                    std: row.i_std,
                                    tool: (row.QC_Tool || '') + ' ' + (row.Tool_No || ''),
                                    samples: {}
                                };
                            }
                            // For PKG, sample_no might be 1, but we also need remark
                            items[row.item_id].samples[row.sample_no] = { 
                                val: row.measured_value,
                                res: row.result
                            };
                        });

                        var html = `
                            <div class="well well-sm">
                                <div class="row">
                                    <div class="col-md-3"><strong>單號:</strong> ${h.qc_form_id}</div>
                                    <div class="col-md-3"><strong>BOM:</strong> ${h.bom_no || ''}</div>
                                    <div class="col-md-3"><strong>料號:</strong> ${h.part_no || ''}</div>
                                    <div class="col-md-3"><strong>客戶:</strong> ${h.client_name || ''}</div>
                                    <div class="col-md-3"><strong>製程:</strong> ${h.process_name || ''}</div>
                                    <div class="col-md-3"><strong>廠商:</strong> ${h.maker_name || ''}</div>
                                    <div class="col-md-3"><strong>檢驗日期:</strong> ${h.check_date}</div>
                                    <div class="col-md-3"><strong>檢驗人員:</strong> ${h.user_cname || h.created_by}</div>
                                    <div class="col-md-3"><strong>進貨數:</strong> ${h.incoming_qty}</div>
                                    <div class="col-md-3"><strong>抽驗數:</strong> ${h.sample_qty}</div>
                                    <div class="col-md-3"><strong>結果:</strong> <span class="${h.check_result==='NG'?'text-danger':'text-success'}"><strong>${h.check_result}</strong></span></div>
                                </div>
                            </div>
                            <table class="table table-bordered table-striped"><thead><tr><th>編號</th><th>項目</th><th>標準</th><th>量具</th>`;
                        
                        for(var i=1; i<=h.sample_qty; i++) html += `<th>#${i}</th>`;
                        html += `</tr></thead><tbody>`;
                        
                        // Check if it's PKG type (simple check via form_type_id or just check if sample_qty is small and has remarks)
                        // Better to rely on inspection_stage from header if available, but view doesn't have it easily.
                        // We can infer from data structure.

                        for (var itemId in items) {
                            var item = items[itemId];
                            html += `<tr><td>${item.code || ''}</td><td>${item.name || ''}</td><td>${item.std || ''}</td><td>${item.tool.trim() || '-'}</td>`;
                            for (var i = 1; i <= h.sample_qty; i++) {
                                var s = item.samples[i] || {val: '', res: ''};
                                // If PKG, show remark if available (need to fetch remark from measurement)
                                // The current view `vw_qc_record_details` doesn't select `remark`.
                                // We need to update the view or select it.
                                // Assuming we updated the view or select query:
                                // Let's assume `m` has `remark`.
                                var measurement = m.find(x => x.item_id == itemId && x.sample_no == i);
                                if (measurement && measurement.remark) {
                                    s.val += ` <span class="text-muted">(${measurement.remark})</span>`;
                                }
                                
                                var cls = s.res === 'NG' ? 'text-danger font-bold' : '';
                                html += `<td class="${cls}">${s.val}</td>`;
                            }
                            html += `</tr>`;
                        }
                        html += `</tbody></table>`;
                        
                        if (pkg) {
                            html += `<div class="well well-sm"><h5><i class="fa fa-cubes"></i> 包裝資訊</h5>`;
                            
                            // Show Appearance NG
                            if (pkg.appearance) {
                                var ngItems = [];
                                for (var k in pkg.appearance) {
                                    if (pkg.appearance[k].ng_qty > 0) ngItems.push(k + ': ' + pkg.appearance[k].ng_qty);
                                }
                                if (ngItems.length > 0) html += `<div><strong>外觀異常:</strong> ${ngItems.join(', ')}</div>`;
                            }

                            if (pkg.rows) {
                                pkg.rows.forEach(p => {
                                    var owner = p.owner === 'customer' ? '客供' : (p.owner === 'noprint' ? '無印刷' : '超正');
                                    html += `<div>${p.type} x ${p.qty} (${owner})</div>`;
                                });
                            }
                            
                            if (pkg.rust && pkg.rust.length > 0) html += `<div><strong>防銹:</strong> ${pkg.rust.join(', ')} ${pkg.rust_other ? '('+pkg.rust_other+')' : ''}</div>`;
                            
                            if (pkg.collision && pkg.collision.length > 0) {
                                var colText = pkg.collision.join(', ');
                                if (pkg.collision.includes('泡殼')) {
                                    colText += ` (${pkg.collision_detail_1 || '?'}入 x ${pkg.collision_detail_2 || '?'}個)`;
                                }
                                if (pkg.collision_other) colText += ` (${pkg.collision_other})`;
                                html += `<div><strong>防撞:</strong> ${colText}</div>`;
                            }

                            if (pkg.return_jig) html += `<div><strong>治具歸還:</strong> ${pkg.return_jig} 個</div>`;
                            if (pkg.return_sample) html += `<div><strong>樣品歸還:</strong> ${pkg.return_sample} 個</div>`;
                            
                            if (pkg.shipment_desc) html += `<div><strong>出貨說明:</strong> ${pkg.shipment_desc}</div>`;
                            if (pkg.storage_method) {
                                var method = pkg.storage_method === 'direct' ? '直接入庫' : '棧板+膠膜';
                                if (pkg.storage_method === 'pallet' && pkg.pallet_qty) method += ` x ${pkg.pallet_qty}`;
                                if (pkg.actual_qty) method += ` (實際入庫: ${pkg.actual_qty})`;
                                html += `<div><strong>入庫:</strong> ${method}</div>`;
                            }

                            if (pkg.related_batches) html += `<div>關聯批次: ${pkg.related_batches}</div>`;
                            html += `</div>`;
                        }

                        if (r) html += `<div class="alert alert-info"><strong>備註:</strong> ${r}</div>`;

                        $('#content-' + panelId).html(html);
                    } else {
                        $('#content-' + panelId).html('<p class="text-danger">載入失敗</p>');
                    }
                }, 'json');
            }
        });
    </script>
    <script src="https://code.jquery.com/ui/1.12.1/jquery-ui.min.js"></script>
</body>

</html>