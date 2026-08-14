<?php
// 表單簽核設計器（Form Signer Designer）：初始建表，8 張新表，前綴 fsd_。
// 可重複執行（CREATE TABLE IF NOT EXISTS）。用法：php migrate_form_signer_init.php
$db = new PDO("mysql:host=127.0.0.1;dbname=EGsystem;port=3306", "EG-TS2024", "excell30367593");
$db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
$db->exec("set names utf8mb4");

$stmts = [];

$stmts['fsd_template'] = "CREATE TABLE IF NOT EXISTS fsd_template (
    id INT AUTO_INCREMENT PRIMARY KEY,
    name VARCHAR(120) NOT NULL,
    file_type ENUM('image','pdf') NOT NULL DEFAULT 'image',
    file_name VARCHAR(255) NULL COMMENT '原始檔檔名(不存路徑,鐵律5),eg_attach_dir(fsd_nas_dir,表單簽核設計器)底下',
    page_count INT NOT NULL DEFAULT 1,
    status ENUM('active','inactive') NOT NULL DEFAULT 'active',
    published_version INT NOT NULL DEFAULT 0,
    current_schema_json LONGTEXT NULL COMMENT '存檔即發布的最新schema快照(stages+signers+fields+pages)',
    created_by VARCHAR(60) NULL,
    updated_by VARCHAR(60) NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COMMENT='表單簽核設計器-樣板'";

$stmts['fsd_template_page'] = "CREATE TABLE IF NOT EXISTS fsd_template_page (
    id INT AUTO_INCREMENT PRIMARY KEY,
    template_id INT NOT NULL,
    page_no INT NOT NULL,
    width_pt DECIMAL(10,2) NOT NULL COMMENT '頁面寬(pt,1pt=1/72in；圖片以96dpi換算)',
    height_pt DECIMAL(10,2) NOT NULL,
    UNIQUE KEY uq_tpl_page (template_id, page_no)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COMMENT='表單簽核設計器-頁面尺寸(框選座標換算基準)'";

$stmts['fsd_stage'] = "CREATE TABLE IF NOT EXISTS fsd_stage (
    id INT AUTO_INCREMENT PRIMARY KEY,
    template_id INT NOT NULL,
    seq INT NOT NULL,
    stage_type ENUM('advisory','decision') NOT NULL,
    name VARCHAR(100) NOT NULL,
    auto_sign TINYINT(1) NOT NULL DEFAULT 0 COMMENT '免人工,依ai-rules/21三鐵則自動簽核',
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    KEY idx_tpl (template_id, seq)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COMMENT='表單簽核設計器-簽核階段(設計時用,存檔即發布快照見fsd_template_version)'";

$stmts['fsd_stage_signer'] = "CREATE TABLE IF NOT EXISTS fsd_stage_signer (
    id INT AUTO_INCREMENT PRIMARY KEY,
    stage_id INT NOT NULL,
    seq INT NOT NULL,
    mode ENUM('user','dept_auto_manager','submitter_supervisor','top_approver') NOT NULL,
    user_id INT NULL COMMENT 'mode=user時',
    dept_id INT NULL COMMENT 'mode=dept_auto_manager時',
    label VARCHAR(100) NULL COMMENT '設計時顯示用文字(如:品管部主管)',
    KEY idx_stage (stage_id, seq)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COMMENT='表單簽核設計器-階段槽位(一階段可掛多槽位=多位簽核人)'";

$stmts['fsd_field'] = "CREATE TABLE IF NOT EXISTS fsd_field (
    id INT AUTO_INCREMENT PRIMARY KEY,
    template_id INT NOT NULL,
    stage_signer_id INT NOT NULL COMMENT '1:1綁定槽位,決定內容來源',
    page_no INT NOT NULL,
    box_type ENUM('stamp','reply') NOT NULL,
    x DECIMAL(8,6) NOT NULL COMMENT '相對頁面寬度的0~1分數',
    y DECIMAL(8,6) NOT NULL,
    w DECIMAL(8,6) NOT NULL,
    h DECIMAL(8,6) NOT NULL,
    KEY idx_tpl (template_id),
    KEY idx_signer (stage_signer_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COMMENT='表單簽核設計器-框選區塊(圖章框/回覆內容框)'";

$stmts['fsd_template_version'] = "CREATE TABLE IF NOT EXISTS fsd_template_version (
    id INT AUTO_INCREMENT PRIMARY KEY,
    template_id INT NOT NULL,
    version INT NOT NULL,
    schema_json LONGTEXT NOT NULL COMMENT '整包快照:file+pages+stages+signers+fields',
    bumped_as_doc_version_id INT NULL,
    created_by VARCHAR(60) NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    UNIQUE KEY uq_tpl_ver (template_id, version)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COMMENT='表單簽核設計器-樣板版本快照(比照rf_template_version,存檔即發布)'";

$stmts['fsd_case'] = "CREATE TABLE IF NOT EXISTS fsd_case (
    id INT AUTO_INCREMENT PRIMARY KEY,
    template_id INT NOT NULL,
    template_version INT NOT NULL COMMENT '建立時pin住的版本,樣板改版不影響已建立案件',
    title VARCHAR(200) NULL,
    applicant_id INT NOT NULL,
    applicant_name VARCHAR(60) NOT NULL,
    business_date DATE NOT NULL,
    submitted_at DATETIME NULL,
    status ENUM('in_progress','approved','rejected','void') NOT NULL DEFAULT 'in_progress',
    current_stage_seq INT NOT NULL DEFAULT 0 COMMENT '目前跑到schema.stages[]的第幾關(1起算,0=尚未開始)',
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    KEY idx_tpl (template_id),
    KEY idx_applicant (applicant_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COMMENT='表單簽核設計器-案件'";

$stmts['fsd_case_response'] = "CREATE TABLE IF NOT EXISTS fsd_case_response (
    id INT AUTO_INCREMENT PRIMARY KEY,
    case_id INT NOT NULL,
    stage_seq INT NOT NULL,
    slot_key VARCHAR(40) NOT NULL COMMENT '對應schema快照內的槽位識別鍵,如 s2_g1',
    resolved_user_id INT NULL,
    resolved_user_name VARCHAR(60) NULL,
    resolved_dept_name VARCHAR(60) NULL,
    is_delegated TINYINT(1) NOT NULL DEFAULT 0,
    decision ENUM('agree','disagree','approved','rejected','skipped_sod') NULL,
    reply_text TEXT NULL,
    responded_at DATETIME NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    UNIQUE KEY uq_case_slot (case_id, slot_key),
    KEY idx_case (case_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COMMENT='表單簽核設計器-每階段每槽位的回應記錄'";

$done = [];
foreach ($stmts as $table => $sql) {
    $exists = (bool)$db->query("SELECT 1 FROM information_schema.TABLES WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='$table'")->fetchColumn();
    $db->exec($sql);
    if (!$exists) $done[] = $table;
}

echo $done ? ("已建立：\n  " . implode("\n  ", $done) . "\n") : "無異動（資料表皆已存在）\n";
