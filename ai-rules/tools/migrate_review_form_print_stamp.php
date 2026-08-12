<?php
// 審核表單模板：新增列印方向(orientation)＋兩種圖章模板綁定(逐列簽章/製表審核核准) 欄位。可重跑，先查 information_schema 防重。
// 用法：php migrate_review_form_print_stamp.php
$db = new PDO("mysql:host=127.0.0.1;dbname=EGsystem;port=3306", "EG-TS2024", "excell30367593");
$db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
$db->exec("set names utf8mb4");

function colExists(PDO $db, string $table, string $col): bool {
    $st = $db->prepare("SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME=? AND COLUMN_NAME=?");
    $st->execute([$table, $col]);
    return (bool)$st->fetchColumn();
}
function addCol(PDO $db, array &$done, string $table, string $col, string $ddl): void {
    if (colExists($db, $table, $col)) return;
    $db->exec("ALTER TABLE `$table` ADD COLUMN `$col` $ddl");
    $done[] = "$table.$col";
}

$done = [];
addCol($db, $done, 'rf_template', 'orientation',
    "ENUM('portrait','landscape') NOT NULL DEFAULT 'landscape' COMMENT '列印方向，預設橫式' AFTER paper_size");
addCol($db, $done, 'rf_template', 'list_stamp_tpl_id',
    "INT NULL COMMENT '逐列簽章用的圖章模板(stamp_template.id)；NULL=預設樣式' AFTER orientation");
addCol($db, $done, 'rf_template', 'footer_stamp_tpl_id',
    "INT NULL COMMENT '製表/審核/核准頁尾簽章用的圖章模板(stamp_template.id)；NULL=預設樣式' AFTER list_stamp_tpl_id");

echo $done ? ("已新增：\n  " . implode("\n  ", $done) . "\n") : "無異動（欄位皆已存在）\n";

// 既有兩個模板明確要求預設橫式（本來欄位DEFAULT就是landscape，這裡是保險起見對既有列顯式補值)
$db->exec("UPDATE rf_template SET orientation='landscape' WHERE orientation IS NULL OR orientation=''");
echo "既有模板 orientation 已確保為 landscape\n";
