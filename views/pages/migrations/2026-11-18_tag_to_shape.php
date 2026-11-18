<?php
/**
 * 2026-11-18 把料號標籤「最大外徑」(label_id=21)、「內孔」(label_id=43) 改存到外形／內孔欄位（d_setting_shape）。
 *  - 最大外徑：每料號剛好一個標籤、且 shape.max_od 還沒值 → 寫入 max_od 並刪標籤；其餘略過並列出
 *  - 內孔：每料號剛好一個標籤、且 shape 還沒有內孔型態 → 設為「單一通孔」孔徑＝標籤值並刪標籤；其餘略過並列出
 * 預設只試算，加 --run 才寫入（單一交易）。略過的標籤原樣保留，不會遺失資料。
 */
require_once __DIR__ . '/../../../src/common/DBConnection.php';
require_once __DIR__ . '/../../../src/common/part_shape_lib.php';
$pdo = (new DBConnection())->getPDO();
$run = in_array('--run', $argv ?? [], true);
eg_shape_ensure_table($pdo);

$rows = $pdo->query("SELECT map_id,d_id,label_id,input_value FROM item_label_map WHERE label_id IN (21,43) ORDER BY d_id,map_id")->fetchAll(PDO::FETCH_ASSOC);
$by = [21 => [], 43 => []];
foreach ($rows as $r) $by[(int)$r['label_id']][(int)$r['d_id']][] = $r;

$shape = function ($d) use ($pdo) {
    $s = $pdo->prepare("SELECT max_od,bore_type FROM d_setting_shape WHERE d_id=?");
    $s->execute([$d]);
    return $s->fetch(PDO::FETCH_ASSOC);
};
$ok = ['od' => 0, 'bore' => 0]; $skip = [];
if ($run) $pdo->beginTransaction();
try {
    foreach ([21 => 'od', 43 => 'bore'] as $lid => $k) {
        foreach ($by[$lid] as $d => $list) {
            $nm = $lid === 21 ? '最大外徑' : '內孔';
            if (count($list) !== 1) { $skip[] = "料號 {$d}：{$nm}標籤有 " . count($list) . ' 個（' . implode('、', array_column($list, 'input_value')) . '），保留'; continue; }
            $v = trim((string)$list[0]['input_value']);
            $n = eg_shape_num($v);
            if ($n === false || $n === null) { $skip[] = "料號 {$d}：{$nm}標籤值「{$v}」不是正數，保留"; continue; }
            $s = $shape($d);
            if ($k === 'od' && $s && $s['max_od'] !== null) { $skip[] = "料號 {$d}：外形欄位已有最大外徑 {$s['max_od']}（標籤 {$v}），保留"; continue; }
            if ($k === 'bore' && $s && $s['bore_type'] !== null) { $skip[] = "料號 {$d}：已設定內孔型態 {$s['bore_type']}（標籤 {$v}），保留"; continue; }
            if ($run) {
                if ($k === 'od') {
                    $pdo->prepare("INSERT INTO d_setting_shape (d_id,max_od,modified_by,modified_at) VALUES (?,?,?,NOW()) ON DUPLICATE KEY UPDATE max_od=VALUES(max_od),modified_by=VALUES(modified_by),modified_at=NOW()")->execute([$d, $n, '標籤遷移']);
                } else {
                    $js = json_encode([['d' => $n, 'l' => null]], JSON_UNESCAPED_UNICODE);
                    $pdo->prepare("INSERT INTO d_setting_shape (d_id,bore_type,bore_steps,bore_json,bore_max,bore_min,modified_by,modified_at) VALUES (?, 'through', NULL, ?, ?, ?, ?, NOW()) ON DUPLICATE KEY UPDATE bore_type='through',bore_steps=NULL,bore_json=VALUES(bore_json),bore_max=VALUES(bore_max),bore_min=VALUES(bore_min),modified_by=VALUES(modified_by),modified_at=NOW()")->execute([$d, $js, $n, $n, '標籤遷移']);
                }
                $pdo->prepare("DELETE FROM item_sub_label_map WHERE parent_map_id=?")->execute([$list[0]['map_id']]);
                $pdo->prepare("DELETE FROM item_label_map WHERE map_id=?")->execute([$list[0]['map_id']]);
            }
            $ok[$k]++;
        }
    }
    if ($run) $pdo->commit();
} catch (Throwable $e) { if ($run && $pdo->inTransaction()) $pdo->rollBack(); die('失敗：' . $e->getMessage() . "\n"); }
echo ($run ? '【已寫入】' : '【試算】') . "最大外徑可轉 {$ok['od']} 筆、內孔可轉 {$ok['bore']} 筆；略過 " . count($skip) . " 筆\n";
foreach ($skip as $s) echo " - $s\n";
