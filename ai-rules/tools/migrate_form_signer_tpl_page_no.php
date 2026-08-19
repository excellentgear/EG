<?php
/**
 * 表單簽核設計器：樣板可設定「建立案件時預設顯示頁碼」（可重複執行）
 * 案件建立時沿用此預設值寫進 fsd_case.show_page_no，之後仍可逐案修改。
 * 執行：& C:\MAMP\bin\php\php8.3.1\php.exe C:\MAMP\htdocs\EGsystem\ai-rules\tools\migrate_form_signer_tpl_page_no.php
 */
$document_root = 'C:/MAMP/htdocs';
include_once $document_root . '/EGsystem/src/common/_config.php';
include_once $document_root . '/EGsystem/src/common/DBConnection.php';
$db = (new DBConnection())->getPDO();
$st = $db->prepare("SELECT COUNT(*) FROM information_schema.COLUMNS
                    WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='fsd_template' AND COLUMN_NAME='default_show_page_no'");
$st->execute();
if ((int)$st->fetchColumn()) { echo "略過（已存在）：fsd_template.default_show_page_no\n"; }
else {
    $db->exec("ALTER TABLE fsd_template ADD COLUMN default_show_page_no TINYINT(1) NOT NULL DEFAULT 1
               COMMENT '用此樣板建立案件時，預設要不要顯示頁碼（案件仍可逐案修改）'");
    echo "已新增：fsd_template.default_show_page_no\n";
}
echo "完成。\n";
