<?php
/**
 * 表單簽核設計器：案件可設定「是否顯示頁碼」（可重複執行）
 * 預設 1＝維持現況（多頁時列印/PDF 左下角顯示頁碼）；取消勾選則一律不顯示。
 * 執行：& C:\MAMP\bin\php\php8.3.1\php.exe C:\MAMP\htdocs\EGsystem\ai-rules\tools\migrate_form_signer_page_no.php
 */
$document_root = 'C:/MAMP/htdocs';
include_once $document_root . '/EGsystem/src/common/_config.php';
include_once $document_root . '/EGsystem/src/common/DBConnection.php';
$db = (new DBConnection())->getPDO();
$st = $db->prepare("SELECT COUNT(*) FROM information_schema.COLUMNS
                    WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='fsd_case' AND COLUMN_NAME='show_page_no'");
$st->execute();
if ((int)$st->fetchColumn()) { echo "略過（已存在）：fsd_case.show_page_no\n"; }
else {
    $db->exec("ALTER TABLE fsd_case ADD COLUMN show_page_no TINYINT(1) NOT NULL DEFAULT 1
               COMMENT '列印/匯出PDF是否顯示頁碼(多頁時左下角)' AFTER business_date");
    echo "已新增：fsd_case.show_page_no\n";
}
echo "完成。\n";
