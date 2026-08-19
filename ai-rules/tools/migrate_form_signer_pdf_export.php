<?php
/**
 * 表單簽核設計器：PDF 匯出/存檔欄位（可重複執行）
 *
 * 用途：案件完成後把「文件＋所有圖章」合成成一份定版 PDF 存進 NAS，列表可重複開啟列印/下載。
 *  - fsd_case.export_pdf_name  存檔名（絕不存絕對路徑，鐵律5）
 *  - fsd_case.export_pdf_at    產生時間
 *  - fsd_case.export_mode      產生方式（vector=PDF原檔向量疊章；image=圖片案件合成）
 *
 * 執行：& C:\MAMP\bin\php\php8.3.1\php.exe C:\MAMP\htdocs\EGsystem\ai-rules\tools\migrate_form_signer_pdf_export.php
 */
$document_root = 'C:/MAMP/htdocs';
include_once $document_root . '/EGsystem/src/common/_config.php';
include_once $document_root . '/EGsystem/src/common/DBConnection.php';

$db = (new DBConnection())->getPDO();

function colExists(PDO $db, string $table, string $col): bool {
    $st = $db->prepare("SELECT COUNT(*) FROM information_schema.COLUMNS
                        WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME=? AND COLUMN_NAME=?");
    $st->execute([$table, $col]);
    return (int)$st->fetchColumn() > 0;
}

$adds = [
    'export_pdf_name' => "ALTER TABLE fsd_case ADD COLUMN export_pdf_name VARCHAR(255) NULL COMMENT '合成PDF檔名(不存絕對路徑)' AFTER file_name",
    'export_pdf_at'   => "ALTER TABLE fsd_case ADD COLUMN export_pdf_at DATETIME NULL COMMENT '合成PDF產生時間' AFTER export_pdf_name",
    'export_mode'     => "ALTER TABLE fsd_case ADD COLUMN export_mode VARCHAR(10) NULL COMMENT 'vector=PDF原檔無損疊章 / image=圖片合成' AFTER export_pdf_at",
];
foreach ($adds as $col => $sql) {
    if (colExists($db, 'fsd_case', $col)) { echo "略過（已存在）：fsd_case.$col\n"; continue; }
    $db->exec($sql);
    echo "已新增：fsd_case.$col\n";
}

echo "完成。\n";
