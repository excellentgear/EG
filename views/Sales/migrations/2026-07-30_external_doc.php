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
echo "done\n";
