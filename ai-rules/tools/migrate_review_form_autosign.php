<?php
// 審核表單：新增自動簽核設定(auto_review/auto_approval)＋表單送出日欄位(submit_date/submitted_at)。
// ai-rules/21-自動簽核記錄邏輯.md 三條鐵則的資料表基礎。可重跑，先查 information_schema 防重。
// 用法：php migrate_review_form_autosign.php
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
addCol($db, $done, 'rf_template', 'auto_review',
    "TINYINT(1) NOT NULL DEFAULT 0 COMMENT '需要審核時,是否自動簽核(不等真人)；need_review=0時本欄無意義' AFTER review_dept_id");
addCol($db, $done, 'rf_template', 'auto_approval',
    "TINYINT(1) NOT NULL DEFAULT 0 COMMENT '需要核准時,是否自動簽核；need_approval=0時本欄無意義' AFTER approver_dept_id");
addCol($db, $done, 'rf_instance', 'submit_date',
    "DATE NULL COMMENT '送出日(業務日期)，一般使用者不可自選=按下送出當下的日期；僅超級管理員可回改' AFTER business_date");
addCol($db, $done, 'rf_instance', 'submitted_at',
    "DATETIME NULL COMMENT '實際點擊送出的精確時間戳，不因回改送出日而變動' AFTER submit_date");

echo $done ? ("已新增：\n  " . implode("\n  ", $done) . "\n") : "無異動（欄位皆已存在）\n";
