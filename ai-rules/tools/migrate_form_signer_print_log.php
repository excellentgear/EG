<?php
/**
 * 表單簽核設計器：列印紀錄（可重複執行）
 * 記錄誰在什麼時候把案件印出來／開啟或下載合成PDF，列表與詳情預設顯示最新一次的列印日期。
 * 執行：& C:\MAMP\bin\php\php8.3.1\php.exe C:\MAMP\htdocs\EGsystem\ai-rules\tools\migrate_form_signer_print_log.php
 */
$document_root = 'C:/MAMP/htdocs';
include_once $document_root . '/EGsystem/src/common/_config.php';
include_once $document_root . '/EGsystem/src/common/DBConnection.php';
$db = (new DBConnection())->getPDO();
$exists = (int)$db->query("SELECT COUNT(*) FROM information_schema.TABLES
                           WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='fsd_case_print_log'")->fetchColumn();
if ($exists) { echo "略過（已存在）：fsd_case_print_log\n"; }
else {
    $db->exec("CREATE TABLE fsd_case_print_log (
        id INT AUTO_INCREMENT PRIMARY KEY,
        case_id INT NOT NULL,
        kind VARCHAR(16) NOT NULL DEFAULT 'print' COMMENT 'print=瀏覽器列印 / pdf_open=開啟合成PDF / pdf_download=下載合成PDF',
        printed_by INT NULL,
        printed_by_name VARCHAR(50) NULL,
        printed_at DATETIME NOT NULL,
        INDEX idx_case (case_id, printed_at)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COMMENT='表單簽核案件列印紀錄'");
    echo "已建立：fsd_case_print_log\n";
}
echo "完成。\n";
