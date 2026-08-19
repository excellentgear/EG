<?php
/**
 * AS 文件管理：文件「廢止」狀態欄位（可重複執行）。
 * 廢止與既有的「刪除」（is_deleted，誤建文件用的軟刪除）是兩種獨立狀態：
 *   - 廢止＝這份文件正式停用，仍是品質紀錄的一部分，要能在文件管制總覽表上印出廢止日期
 *   - 刪除＝建錯的文件，永遠不進結構總覽
 * 用法： php ai-rules/tools/migrate_asdoc_obsolete.php
 */
error_reporting(E_ALL & ~E_NOTICE & ~E_DEPRECATED);
$_SERVER['DOCUMENT_ROOT'] = 'C:/MAMP/htdocs';
require_once 'C:/MAMP/htdocs/EGsystem/src/common/_config.php';
require_once 'C:/MAMP/htdocs/EGsystem/src/common/DBConnection.php';
$db = (new DBConnection())->getPDO();

$cols = [
    'is_obsolete'     => "TINYINT(1) NOT NULL DEFAULT 0 COMMENT '已廢止(1=廢止，與 is_deleted 獨立)'",
    'obsolete_date'   => "DATE NULL COMMENT '廢止日期(業務日期，列印總覽表備註欄用)'",
    'obsolete_reason' => "VARCHAR(255) NULL COMMENT '廢止原因'",
    'obsolete_by'     => "VARCHAR(30) NULL COMMENT '廢止操作者'",
    'obsolete_at'     => "DATETIME NULL COMMENT '廢止時間戳(與業務日期分離，ai-rules/21)'",
];
$have = [];
foreach ($db->query("SHOW COLUMNS FROM as_document")->fetchAll(PDO::FETCH_ASSOC) as $c) $have[$c['Field']] = true;

$added = 0;
foreach ($cols as $name => $ddl) {
    if (isset($have[$name])) { echo "  已存在 as_document.$name\n"; continue; }
    $db->exec("ALTER TABLE as_document ADD COLUMN `$name` $ddl");
    echo "  已新增 as_document.$name\n"; $added++;
}
if (!isset($have['is_obsolete'])) {
    $db->exec("ALTER TABLE as_document ADD INDEX idx_as_document_obsolete (is_obsolete)");
    echo "  已新增索引 idx_as_document_obsolete\n";
}
echo $added ? "完成，新增 $added 個欄位。\n" : "完成，無需異動。\n";
