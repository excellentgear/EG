<?php
// 表單簽核設計器：樣板綁定圖章模板(尺寸依模板公分數,不可縮小)＋頁面旋轉(人工修正掃描歪斜方向)。可重複執行。
$db = new PDO("mysql:host=127.0.0.1;dbname=EGsystem;port=3306", "EG-TS2024", "excell30367593");
$db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
$db->exec("set names utf8mb4");
function colExists(PDO $db, string $t, string $c): bool {
    $st = $db->prepare("SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME=? AND COLUMN_NAME=?");
    $st->execute([$t, $c]); return (bool)$st->fetchColumn();
}
function addCol(PDO $db, array &$done, string $t, string $c, string $ddl): void {
    if (colExists($db, $t, $c)) return;
    $db->exec("ALTER TABLE `$t` ADD COLUMN `$c` $ddl"); $done[] = "$t.$c";
}
$done = [];
addCol($db, $done, 'fsd_template', 'stamp_tpl_id', "INT NULL COMMENT '綁定圖章管理的stamp_template,圖章尺寸依此模板設定的公分數,不可縮小(noScale)' AFTER page_count");
addCol($db, $done, 'fsd_template_page', 'rotation', "SMALLINT NOT NULL DEFAULT 0 COMMENT '人工旋轉角度(0/90/180/270),修正掃描歪斜方向' AFTER height_pt");
addCol($db, $done, 'fsd_case_page', 'rotation', "SMALLINT NOT NULL DEFAULT 0 COMMENT '人工旋轉角度(0/90/180/270)' AFTER height_pt");
echo $done ? ("已異動：\n  " . implode("\n  ", $done) . "\n") : "無異動（皆已存在）\n";
