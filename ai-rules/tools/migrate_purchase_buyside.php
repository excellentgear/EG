<?php
// 申請採購「採購側欄位」：schema 遷移（可重跑）
// 用法：php migrate_purchase_buyside.php
// 目的：採購人員不得直接修改申請單。申請人填的 item_name / spec_text / qty_requested / spec_id
//       送出後就凍結；採購實際買到什麼寫在另一組 buy_* 欄位，兩者並列可對照。
//       （原本 bind_spec 會直接覆寫 item_name / spec_text，申請人原始寫法會被抹掉）
$db = new PDO("mysql:host=127.0.0.1;dbname=EGsystem;port=3306", "EG-TS2024", "excell30367593");
$db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
$db->exec("set names utf8mb4");

function colExists(PDO $db, string $table, string $col): bool {
    $st = $db->prepare("SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME=? AND COLUMN_NAME=?");
    $st->execute([$table, $col]);
    return (bool)$st->fetchColumn();
}
function idxExists(PDO $db, string $table, string $idx): bool {
    $st = $db->prepare("SELECT COUNT(*) FROM information_schema.STATISTICS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME=? AND INDEX_NAME=?");
    $st->execute([$table, $idx]);
    return (bool)$st->fetchColumn();
}
function addCol(PDO $db, array &$done, string $table, string $col, string $ddl): void {
    if (colExists($db, $table, $col)) return;
    $db->exec("ALTER TABLE `$table` ADD COLUMN `$col` $ddl");
    $done[] = "$table.$col";
}

$done = [];
$t = 'purchase_request_item';
addCol($db, $done, $t, 'buy_spec_id',
    "INT NULL COMMENT '採購實際綁的採購料號 purchase_spec.spec_id（申請人的 spec_id 不動）' AFTER `spec_id`");
addCol($db, $done, $t, 'buy_item_name',
    "VARCHAR(120) NULL COMMENT '採購實際品名；NULL=同申請' AFTER `buy_spec_id`");
addCol($db, $done, $t, 'buy_spec_text',
    "VARCHAR(150) NULL COMMENT '採購實際規格；NULL=同申請' AFTER `buy_item_name`");
addCol($db, $done, $t, 'buy_qty',
    "DECIMAL(15,4) NULL COMMENT '採購實際數量；NULL=同申請數量' AFTER `qty_requested`");
addCol($db, $done, $t, 'buy_unit_id',
    "INT NULL COMMENT '採購實際單位；NULL=同申請' AFTER `unit_id`");
addCol($db, $done, $t, 'buy_remark',
    "VARCHAR(300) NULL COMMENT '採購備註（與申請人的 remark 分開，不覆蓋）' AFTER `remark`");
if (!idxExists($db, $t, 'idx_pri_buy_spec')) {
    $db->exec("ALTER TABLE `$t` ADD KEY `idx_pri_buy_spec` (`buy_spec_id`)");
    $done[] = "$t:idx_pri_buy_spec";
}

echo $done ? ("已新增：\n  " . implode("\n  ", $done) . "\n") : "無異動（欄位皆已存在）\n";
