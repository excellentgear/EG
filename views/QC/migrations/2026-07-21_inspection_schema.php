<?php
// =============================================================================
// views/QC/migrations/2026-07-21_inspection_schema.php
// 線上檢驗（inspection_combined_prototype.php）一次性 schema migration
//  - 目的：把原本散落在 ensureSchema()（每支 AJAX 熱路徑都跑 SHOW COLUMNS + ALTER）
//    的欄位/表建置，一次補齊，讓正式請求路徑完全不碰 schema（見實作說明 #2）。
//  - 冪等：可重複執行，已存在的欄位/表會略過。
//  - 執行：& C:\MAMP\bin\php\php8.3.1\php.exe C:\MAMP\htdocs\EGsystem\views\QC\migrations\2026-07-21_inspection_schema.php
//  - 新增後請把新欄位補進 MYSQL 資料字典.txt。
// =============================================================================
include_once __DIR__ . '/../../../src/common/_config.php';
include_once __DIR__ . '/../../../src/common/DBConnection.php';

$conn = new DBConnection();
$pdo  = $conn->getPDO();
$pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

$done = [];
$skip = [];

// 若欄位不存在才 ADD
$addCol = function ($table, $col, $ddl) use ($pdo, &$done, &$skip) {
    $c = $pdo->query("SHOW COLUMNS FROM `$table` LIKE '$col'");
    if ($c->rowCount() == 0) {
        $pdo->exec("ALTER TABLE `$table` ADD COLUMN $ddl");
        $done[] = "$table.$col";
    } else {
        $skip[] = "$table.$col";
    }
};

// ---- qc_check_form：編輯鎖定 / 稽核 / NG 決定（原 ensureSchema 內容，補齊確保存在）----
$addCol('qc_check_form', 'batch_no',        "batch_no INT DEFAULT 1 COMMENT '到貨批次序'");
$addCol('qc_check_form', 'round_no',        "round_no INT DEFAULT 1 COMMENT '複驗次數(退回重做)'");
$addCol('qc_check_form', 'edit_unlocked',   "edit_unlocked TINYINT(1) DEFAULT 0 COMMENT '主管是否已開放此筆修改(1=可改)'");
$addCol('qc_check_form', 'unlocked_by',     "unlocked_by CHAR(11) NULL COMMENT '開放修改的主管'");
$addCol('qc_check_form', 'unlocked_at',     "unlocked_at DATETIME NULL COMMENT '開放修改時間'");
$addCol('qc_check_form', 'last_edited_by',  "last_edited_by CHAR(11) NULL COMMENT '最後修改人'");
$addCol('qc_check_form', 'last_edited_at',  "last_edited_at DATETIME NULL COMMENT '最後修改時間'");
$addCol('qc_check_form', 'pcs_verdicts',    "pcs_verdicts TEXT NULL COMMENT '各PCS判定結果JSON [{v:OK/NG, m:是否手動0/1}]'");
$addCol('qc_check_form', 'ncr_decision',    "ncr_decision VARCHAR(10) NULL COMMENT 'NG後決定：OPEN=已開異常單/SKIP=不開單'");
$addCol('qc_check_form', 'ncr_skip_reason', "ncr_skip_reason VARCHAR(255) NULL COMMENT '不開異常單的原因'");
$addCol('qc_check_form', 'abnormal_order_id', "abnormal_order_id INT NULL COMMENT '對應 qa_abnormal_order.id'");

// ---- qc_measurement：逐列判定 + 【多量具/多次量測】新顆粒度欄位（實作說明 §多量具 (a)）----
$addCol('qc_measurement', 'item_verdict',   "item_verdict VARCHAR(10) NULL COMMENT '項目判定 OK/NG/AOD'");
$addCol('qc_measurement', 'measure_method', "measure_method VARCHAR(20) NULL COMMENT '量測方法(三次元/投影機/手動/其他)'");
$addCol('qc_measurement', 'reading_seq',    "reading_seq TINYINT NOT NULL DEFAULT 1 COMMENT '同(item,sample,method,tool)第幾次讀值'");
// tool_id 已存在，續用為「該筆讀值實際使用的量具實例(→qc_tool.Tool_id)」

// ---- qc_tool：校驗到期（AS9100 校期管控，#10 第二階段可選；先建欄位不強制使用）----
$addCol('qc_tool', 'calibration_due', "calibration_due DATE NULL COMMENT '校驗到期日(逾期量具警示，選作)'");

// ---- 稽核紀錄表（原 ensureSchema 的 CREATE IF NOT EXISTS）----
$pdo->exec("CREATE TABLE IF NOT EXISTS qc_inspection_edit_log (
    log_id INT AUTO_INCREMENT PRIMARY KEY,
    qc_form_id INT NOT NULL COMMENT '對應 qc_check_form.qc_form_id',
    action ENUM('UNLOCK','EDIT','RELOCK') NOT NULL COMMENT '行為',
    reason VARCHAR(255) NULL COMMENT '原因/說明',
    changes_json LONGTEXT NULL COMMENT '改前→改後快照(JSON)',
    changed_by CHAR(11) NOT NULL,
    changed_at DATETIME DEFAULT CURRENT_TIMESTAMP,
    INDEX(qc_form_id)
) COMMENT='QC 檢驗歷程修改稽核'");

echo "== inspection schema migration ==\n";
echo "ADDED : " . (count($done) ? implode(', ', $done) : '(none)') . "\n";
echo "EXISTS: " . (count($skip) ? implode(', ', $skip) : '(none)') . "\n";
echo "qc_inspection_edit_log ensured.\n";
echo "DONE.\n";
