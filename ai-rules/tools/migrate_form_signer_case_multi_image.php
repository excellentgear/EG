<?php
// 表單簽核設計器：案件改為只能上傳圖片(不可PDF)、且一次可多張各自成一頁。
// fsd_case_page 新增 file_name 欄位(該頁自己的圖片檔名)；沒有值時向下相容退回 fsd_case.file_name(舊版單檔/PDF案件)。可重複執行。
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
addCol($db, $done, 'fsd_case_page', 'file_name', "VARCHAR(255) NULL COMMENT '該頁自己的圖片檔名(新版多圖案件);NULL則向下相容退回fsd_case.file_name(舊版單檔/PDF案件)' AFTER height_pt");
echo $done ? ("已異動：\n  " . implode("\n  ", $done) . "\n") : "無異動（皆已存在）\n";
