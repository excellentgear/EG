<?php
// 表單簽核設計器：新增A4/A3裁切框功能欄位(paper_size+crop_x/y/w/h)，讓使用者用固定比例的A4/A3框
// 框住上傳文件的實際內容範圍，取代直接信任原始像素量測出的寬高比(掃描/拍照常有多餘留白或些微失真)。
// 可重複執行。
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
foreach (['fsd_template_page', 'fsd_case_page'] as $t) {
    addCol($db, $done, $t, 'paper_size', "ENUM('A4','A3') NULL COMMENT 'NULL=未使用裁切框,沿用原始量測尺寸' AFTER rotation");
    addCol($db, $done, $t, 'crop_x', "DECIMAL(8,6) NOT NULL DEFAULT 0 COMMENT '裁切框左上角x(旋轉後來源圖的0~1分數)' AFTER paper_size");
    addCol($db, $done, $t, 'crop_y', "DECIMAL(8,6) NOT NULL DEFAULT 0 AFTER crop_x");
    addCol($db, $done, $t, 'crop_w', "DECIMAL(8,6) NOT NULL DEFAULT 1 COMMENT '裁切框寬(0~1分數,預設1=不裁切)' AFTER crop_y");
    addCol($db, $done, $t, 'crop_h', "DECIMAL(8,6) NOT NULL DEFAULT 1 AFTER crop_w");
}
echo $done ? ("已異動：\n  " . implode("\n  ", $done) . "\n") : "無異動（皆已存在）\n";
