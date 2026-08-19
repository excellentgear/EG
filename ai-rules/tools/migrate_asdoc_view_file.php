<?php
/**
 * AS 文件管理：版本檔案「雙版本」欄位（可重複執行）。
 * 同一版可以有兩個檔案：
 *   file_name      ＝下載版（可修改的原檔，具下載權限者下載這個）
 *   view_file_name ＝檢視版（線上預覽用，例如加浮水印或轉好的 PDF）
 * 只上傳其中一種時，另一種自動退回用同一個檔（缺哪一種都可事後補傳）。
 * 用法： php ai-rules/tools/migrate_asdoc_view_file.php
 */
error_reporting(E_ALL & ~E_NOTICE & ~E_DEPRECATED);
$_SERVER['DOCUMENT_ROOT'] = 'C:/MAMP/htdocs';
require_once 'C:/MAMP/htdocs/EGsystem/src/common/_config.php';
require_once 'C:/MAMP/htdocs/EGsystem/src/common/DBConnection.php';
$db = (new DBConnection())->getPDO();

$cols = [
    'view_file_name'     => "VARCHAR(255) NULL COMMENT '檢視版檔名(線上預覽用)；NULL=用 file_name'",
    'view_original_name' => "VARCHAR(255) NULL COMMENT '檢視版原始檔名'",
];
$have = [];
foreach ($db->query("SHOW COLUMNS FROM as_document_version")->fetchAll(PDO::FETCH_ASSOC) as $c) $have[$c['Field']] = true;

$added = 0;
foreach ($cols as $name => $ddl) {
    if (isset($have[$name])) { echo "  已存在 as_document_version.$name\n"; continue; }
    $db->exec("ALTER TABLE as_document_version ADD COLUMN `$name` $ddl AFTER original_name");
    echo "  已新增 as_document_version.$name\n"; $added++;
}
echo $added ? "完成，新增 $added 個欄位。\n" : "完成，無需異動。\n";
