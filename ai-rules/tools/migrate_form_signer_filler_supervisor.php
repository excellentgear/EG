<?php
/**
 * 表單簽核設計器：簽核人來源新增「填表人上一階主管」(filler_supervisor)（可重複執行）
 *
 * fsd_stage_signer.mode 是 ENUM，沒把新值加進去的話存階段設定會直接 SQL 失敗（畫面上是 500）。
 * 執行：& C:\MAMP\bin\php\php8.3.1\php.exe C:\MAMP\htdocs\EGsystem\ai-rules\tools\migrate_form_signer_filler_supervisor.php
 */
$document_root = 'C:/MAMP/htdocs';
include_once $document_root . '/EGsystem/src/common/_config.php';
include_once $document_root . '/EGsystem/src/common/DBConnection.php';

$db = (new DBConnection())->getPDO();

$st = $db->prepare("SELECT COLUMN_TYPE FROM information_schema.COLUMNS
                    WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='fsd_stage_signer' AND COLUMN_NAME='mode'");
$st->execute();
$type = (string)$st->fetchColumn();

if (strpos($type, "'filler_supervisor'") !== false) {
    echo "略過（已存在）：fsd_stage_signer.mode 已含 filler_supervisor\n";
} else {
    $db->exec("ALTER TABLE fsd_stage_signer MODIFY COLUMN mode
               ENUM('user','dept_auto_manager','submitter_supervisor','filler_supervisor','top_approver','filler')
               NOT NULL DEFAULT 'top_approver'");
    echo "已擴充：fsd_stage_signer.mode 加入 filler_supervisor\n";
}
echo "完成。\n";
