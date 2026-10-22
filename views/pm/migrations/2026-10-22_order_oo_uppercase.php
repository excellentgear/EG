<?php
/**
 * 訂單追蹤 單號(Order_oo) 小寫（oo…）改大寫（OO…）— 2026-10-22 使用者要求
 * 只改 order_track.Order_oo（同時去掉前後空白），不動任何其他欄位（含 Modified_At／Modified_By）。
 * 原值記在 order_oo_fix_log，可還原。用法：php 本檔 試算；php 本檔 --run 寫入；php 本檔 --rollback 還原。
 */
if (PHP_SAPI !== 'cli') { exit('CLI only'); }
require_once __DIR__ . '/../../../src/common/DBConnection.php';
$db = (new DBConnection())->getPDO();
$db->exec("CREATE TABLE IF NOT EXISTS order_oo_fix_log (
  id INT AUTO_INCREMENT PRIMARY KEY, order_id INT NOT NULL, old_oo VARCHAR(60) NULL, new_oo VARCHAR(60) NULL, fixed_at DATETIME NOT NULL,
  KEY idx_order (order_id)) CHARACTER SET utf8mb4 COMMENT='訂單單號大小寫修正紀錄'");
$rows = $db->query("SELECT Order_id, Order_oo FROM order_track WHERE Order_oo IS NOT NULL AND (BINARY Order_oo <> BINARY UPPER(Order_oo) OR BINARY Order_oo <> BINARY TRIM(Order_oo))")->fetchAll(PDO::FETCH_ASSOC);
if (in_array('--rollback', $argv, true)) {
    $db->beginTransaction();
    $n = 0; $u = $db->prepare("UPDATE order_track SET Order_oo=? WHERE Order_id=? AND BINARY Order_oo = BINARY ?");
    foreach ($db->query("SELECT order_id,old_oo,new_oo FROM order_oo_fix_log ORDER BY id DESC")->fetchAll(PDO::FETCH_ASSOC) as $l) { $u->execute([$l['old_oo'], $l['order_id'], $l['new_oo']]); $n += $u->rowCount(); }
    $db->commit(); echo "已還原 $n 筆\n"; exit;
}
echo "需修正 " . count($rows) . " 筆\n";
if (!in_array('--run', $argv, true)) { foreach (array_slice($rows, 0, 10) as $r) echo "  #{$r['Order_id']} [{$r['Order_oo']}] → [" . strtoupper(trim($r['Order_oo'])) . "]\n"; exit; }
$db->beginTransaction();
$up = $db->prepare("UPDATE order_track SET Order_oo=? WHERE Order_id=?");
$lg = $db->prepare("INSERT INTO order_oo_fix_log (order_id,old_oo,new_oo,fixed_at) VALUES (?,?,?,NOW())");
$n = 0;
foreach ($rows as $r) { $new = strtoupper(trim($r['Order_oo'])); $up->execute([$new, $r['Order_id']]); $lg->execute([$r['Order_id'], $r['Order_oo'], $new]); $n++; }
$db->commit();
echo "已修正 $n 筆\n";
