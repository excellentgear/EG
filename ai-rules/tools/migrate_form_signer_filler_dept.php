<?php
/**
 * 表單簽核設計器：填表人要連「以哪個部門身分」一起記（可重複執行）
 * 兼任者選不同部門，往上找到的簽核主管不同，所以填表人不能只記人。
 * 執行：& C:\MAMP\bin\php\php8.3.1\php.exe C:\MAMP\htdocs\EGsystem\ai-rules\tools\migrate_form_signer_filler_dept.php
 */
$document_root = 'C:/MAMP/htdocs';
include_once $document_root . '/EGsystem/src/common/_config.php';
include_once $document_root . '/EGsystem/src/common/DBConnection.php';
$db = (new DBConnection())->getPDO();
$st = $db->prepare("SELECT COUNT(*) FROM information_schema.COLUMNS
                    WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='fsd_case' AND COLUMN_NAME='filler_dept_id'");
$st->execute();
if ((int)$st->fetchColumn()) { echo "略過（已存在）：fsd_case.filler_dept_id\n"; }
else {
    $db->exec("ALTER TABLE fsd_case ADD COLUMN filler_dept_id INT NULL
               COMMENT '填表人是以哪個部門的身分填這張表(兼任者不同部門→往上簽核的主管不同)' AFTER filler_name");
    echo "已新增：fsd_case.filler_dept_id\n";
}
echo "完成。\n";
