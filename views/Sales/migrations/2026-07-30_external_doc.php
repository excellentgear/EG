<?php
// =============================================================================
// 一次性 migration：外來文件清單（AS9100 外來文件管制）
//   quotation_file_categories 加兩欄：
//     is_external_doc   是否列入外來文件清單（1=該標籤的附件會出現在外來文件清單）
//     external_doc_name 外來文件類別名稱（清單/列印顯示用；空=直接用標籤名稱）
// 執行：& C:\MAMP\bin\php\php8.3.1\php.exe C:\MAMP\htdocs\EGsystem\views\Sales\migrations\2026-07-30_external_doc.php
// 可重複執行（欄位已存在時跳過）。
// =============================================================================
include_once __DIR__ . '/../../../src/common/_config.php';
include_once __DIR__ . '/../../../src/common/DBConnection.php';

$pdo = (new DBConnection())->getPDO();
$pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

try {
    $pdo->exec("ALTER TABLE quotation_file_categories ADD COLUMN is_external_doc TINYINT(1) NOT NULL DEFAULT 0 COMMENT '是否列入外來文件清單'");
    echo "is_external_doc OK\n";
} catch (PDOException $e) {
    if (strpos($e->getMessage(), 'Duplicate column') === false) throw $e;
    echo "is_external_doc 已存在，跳過\n";
}
try {
    $pdo->exec("ALTER TABLE quotation_file_categories ADD COLUMN external_doc_name VARCHAR(100) NULL COMMENT '外來文件類別名稱(空=用標籤名)'");
    echo "external_doc_name OK\n";
} catch (PDOException $e) {
    if (strpos($e->getMessage(), 'Duplicate column') === false) throw $e;
    echo "external_doc_name 已存在，跳過\n";
}

// 2026-07-31 二期：報價附件補備註欄（比照 part_attachments.note；外來文件清單可回寫）
try {
    $pdo->exec("ALTER TABLE quotation_attachments ADD COLUMN note TEXT NULL COMMENT '附件備註（外來文件清單等處可回寫）'");
    echo "quotation_attachments.note OK\n";
} catch (PDOException $e) {
    if (strpos($e->getMessage(), 'Duplicate column') === false) throw $e;
    echo "quotation_attachments.note 已存在，跳過\n";
}

// 2026-07-31 二期：外來文件清單排除表（同一份文件料號/報價兩邊都掛附件時，可把重複那筆排除）
$pdo->exec("
CREATE TABLE IF NOT EXISTS external_doc_exclude (
  id          INT AUTO_INCREMENT PRIMARY KEY,
  source      VARCHAR(10)  NOT NULL COMMENT 'part=料號附件 / quote=報價附件',
  attach_id   INT          NOT NULL COMMENT 'part_attachments.id 或 quotation_attachments.id',
  ds_pk       INT          NOT NULL COMMENT 'd_setting.d_id（附件×料號為排除單位）',
  part_no     VARCHAR(100) NULL COMMENT '料號字串（備查）',
  excluded_by VARCHAR(50)  NULL,
  excluded_at DATETIME     NULL,
  UNIQUE KEY uq_item (source, attach_id, ds_pk)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COMMENT='外來文件清單排除項目'");
echo "external_doc_exclude OK\n";
echo "done\n";
