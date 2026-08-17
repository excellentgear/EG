<?php
// 表單簽核設計器：新增「補案件」（管理員把已經簽好章的歷史紙本掃描檔補進系統，不需要樣板、固定自動審核，
// 上傳後自己框選要蓋哪些圖章、每個圖章各自指定人員與圖章模板；使用者 2026-08-17 明確要求）。
// 補案件 template_id 固定存 0（沒有樣板），故 fsd_case_list 一律改 LEFT JOIN fsd_template。可重複執行。
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
addCol($db, $done, 'fsd_case', 'case_kind', "ENUM('normal','backfill') NOT NULL DEFAULT 'normal' COMMENT 'normal=依樣板跑簽核流程;backfill=補案件(無樣板,固定自動審核,圖章逐個自訂)' AFTER template_version");
addCol($db, $done, 'fsd_case', 'as_doc_id', "INT NULL COMMENT '補案件自行挑選的AS文件id(列印右下角編號用;一般案件走樣板綁定不用這欄)' AFTER case_kind");

// 補案件的每個圖章各自帶「是誰的章」與「用哪個圖章模板」（一般案件靠 slot_key 對回樣板槽位，不用這三欄）
addCol($db, $done, 'fsd_case_field', 'signer_user_id', "INT NULL COMMENT '補案件:此圖章是哪位人員的章(含已離職者)'");
addCol($db, $done, 'fsd_case_field', 'signer_name', "VARCHAR(60) NULL COMMENT '補案件:蓋章當下的人員姓名快照(人員改名/刪除仍印得出原章)'");
addCol($db, $done, 'fsd_case_field', 'stamp_tpl_id', "INT NULL COMMENT '補案件:此圖章使用的圖章模板(stamp_template.id);NULL=用系統預設回墨印'");

echo $done ? ("已新增欄位：\n - " . implode("\n - ", $done) . "\n") : "沒有需要新增的欄位（先前已執行過）\n";
