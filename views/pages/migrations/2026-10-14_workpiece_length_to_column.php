<?php
/**
 * 2026-10-14 把料號標籤「工件總長」的資料搬進 d_setting.workpiece_length（主檔管理「客戶」右側新欄位）
 *
 * 規則：
 *   ⑴ 欄位已有值的不動（以欄位為準，可重複執行）
 *   ⑵ 標籤上沒有值的不動
 *   ⑶ **標籤那一筆不刪**（使用者之後自己移除標籤；這支只搬資料）
 *
 *   php views/pages/migrations/2026-10-14_workpiece_length_to_column.php          ← 只試算
 *   php views/pages/migrations/2026-10-14_workpiece_length_to_column.php --run    ← 實際寫入
 */
require_once __DIR__ . '/../../../src/common/DBConnection.php';
require_once __DIR__ . '/../../../src/common/part_label_lib.php';

$run = in_array('--run', $argv, true);
$pdo = (new DBConnection())->getPDO();
$pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

eg_workpiece_length_ensure($pdo);
if (!$pdo->query("SHOW COLUMNS FROM d_setting LIKE 'workpiece_length'")->fetch()) { echo "✗ 欄位建立失敗\n"; exit(1); }

$labelId = eg_workpiece_length_label_id($pdo);
if ($labelId <= 0) { echo "✗ 找不到啟用中的「工件總長」標籤（可能已被移除；欄位已建好，無資料可搬）\n"; exit(0); }
echo "工件總長標籤 label_id = {$labelId}\n";

$rows = $pdo->prepare(
    "SELECT m.d_id, MIN(m.map_id) AS map_id FROM item_label_map m
       JOIN d_setting d ON d.d_id = m.d_id
      WHERE m.label_id = ? AND m.input_value IS NOT NULL AND TRIM(m.input_value) <> ''
      GROUP BY m.d_id"
);
$rows->execute([$labelId]);
$list = $rows->fetchAll(PDO::FETCH_ASSOC);

$valOf = $pdo->prepare("SELECT input_value FROM item_label_map WHERE map_id=?");
$cur   = $pdo->prepare("SELECT workpiece_length FROM d_setting WHERE d_id=?");
$toFill = []; $kept = 0; $nonNum = [];
foreach ($list as $r) {
    $valOf->execute([$r['map_id']]);
    $v = eg_workpiece_length_norm($valOf->fetchColumn());
    if ($v === null) continue;
    $cur->execute([$r['d_id']]);
    $c = $cur->fetchColumn();
    if ($c !== false && $c !== null && trim((string)$c) !== '') { $kept++; continue; }
    if (!is_numeric($v)) $nonNum[] = "d_id={$r['d_id']}「{$v}」";
    $toFill[(int)$r['d_id']] = $v;
}
echo "  標籤上有值的料號：" . count($list) . " 支\n";
echo "  欄位已有值、略過：{$kept} 支\n";
echo "  將搬入欄位：" . count($toFill) . " 支\n";
if ($nonNum) echo "  非數字內容（照原樣搬）：" . implode('、', $nonNum) . "\n";

if (!$run) { echo "\n（只試算，加 --run 才寫入）\n"; exit(0); }
$pdo->beginTransaction();
$up = $pdo->prepare("UPDATE d_setting SET workpiece_length=? WHERE d_id=? AND (workpiece_length IS NULL OR TRIM(workpiece_length)='')");
$ok = 0;
foreach ($toFill as $did => $v) { $up->execute([$v, $did]); $ok += $up->rowCount(); }
$pdo->commit();
echo "\n✓ 已搬入 {$ok} 支料號\n";
$n = $pdo->query("SELECT COUNT(*) FROM d_setting WHERE workpiece_length IS NOT NULL AND TRIM(workpiece_length)<>''")->fetchColumn();
echo "  目前欄位有工件總長的料號共 {$n} 支\n";
