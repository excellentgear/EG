<?php
// 表單簽核設計器：新增「填表人」概念(fsd_case.filler_id/filler_name)，與applicant_id(誰技術上按下建立)分開存放。
// 使用者明確要求：常見情境是管理員代為建立案件，但表單實際歸屬/簽核解析基準要以「填表人」為準，
// 建立時預設=建立者本人，之後僅超級管理員(id=1)可從案件檢視畫面回改(比照ai-rules/21鐵則2 送出日回改
// 的精神:一般人不可事後竄改業務身分基準，只有超級管理員能補登)。可重複執行。
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
addCol($db, $done, 'fsd_case', 'filler_id', "INT NULL COMMENT '填表人(表單實際歸屬者,簽核解析基準;預設=applicant_id,僅超級管理員可事後回改)' AFTER applicant_name");
addCol($db, $done, 'fsd_case', 'filler_name', "VARCHAR(60) NULL AFTER filler_id");
// 既有案件回填：預設填表人=建立者本人
$db->exec("UPDATE fsd_case SET filler_id=applicant_id, filler_name=applicant_name WHERE filler_id IS NULL");
echo $done ? ("已異動：\n  " . implode("\n  ", $done) . "\n（既有案件已回填filler_id=applicant_id）\n") : "無異動（皆已存在）\n";
