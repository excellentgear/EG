<?php
/**
 * 2026-10-02 把 `d_setting_gear.Workpiece_Length` 的工件總長回填到料號標籤「工件總長」
 *
 * 背景：報價單管理「新增料號」原本把工件總長寫進齒輪表的 Workpiece_Length，主檔管理則是
 * 寫進料號標籤——**同一件事兩邊各存一份**，實測 52 個料號有齒輪表的值、252 個有標籤的值，
 * 其中 27 個兩邊都有、而且已經有 1 筆數字對不起來。使用者拍板**以標籤為唯一來源**，
 * 報價單那支已改成讀寫標籤，這支負責把齒輪表那份補進標籤。
 *
 * 規則（保守，不覆蓋任何既有的標籤值）：
 *   ⑴ 標籤已經有值 → 一律不動，只在報告裡列出兩邊不同的那幾筆讓人自己判斷；
 *   ⑵ 標籤沒有值（或根本沒掛這個標籤）→ 把齒輪表的值寫進標籤；
 *   ⑶ 同一支料號有多列齒型且各有工件總長時，取**第一列**（gear_id 最小）並列入報告；
 *   ⑷ **刻意不清掉 `d_setting_gear.Workpiece_Length`**——製程排程、檢驗標準設定、
 *      ERP 成本分析那幾頁各自的齒輪跳窗還在寫這一欄，清掉會讓那些頁面的既有資料憑空消失。
 *      報價單與主檔管理這兩條路從此只認標籤。
 *
 * 用法：
 *   php views/pages/migrations/2026-10-02_workpiece_length_to_label.php          ← 只試算
 *   php views/pages/migrations/2026-10-02_workpiece_length_to_label.php --run    ← 實際寫入
 * 可重複執行。
 */

require_once __DIR__ . '/../../../src/common/DBConnection.php';
require_once __DIR__ . '/../../../src/common/part_label_lib.php';

$run = in_array('--run', $argv, true);
$pdo = (new DBConnection())->getPDO();
$pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

$labelId = eg_workpiece_length_label_id($pdo);
if ($labelId <= 0) { echo "✗ 找不到啟用中的「工件總長」標籤，請先執行 2026-10-02_merge_workpiece_length.php\n"; exit(1); }
echo "工件總長標籤 label_id = {$labelId}\n";

$rows = $pdo->query(
    "SELECT g.d_setting_id AS d_id, MIN(g.gear_id) AS first_gear_id, COUNT(*) AS n_rows
       FROM d_setting_gear g
      WHERE g.Workpiece_Length IS NOT NULL
      GROUP BY g.d_setting_id"
)->fetchAll(PDO::FETCH_ASSOC);

$valOf = $pdo->prepare("SELECT Workpiece_Length FROM d_setting_gear WHERE d_setting_id=? AND Workpiece_Length IS NOT NULL ORDER BY gear_id LIMIT 1");
$allOf = $pdo->prepare("SELECT GROUP_CONCAT(DISTINCT Workpiece_Length ORDER BY gear_id) FROM d_setting_gear WHERE d_setting_id=? AND Workpiece_Length IS NOT NULL");

$toFill = []; $sameV = []; $diff = []; $multi = [];
foreach ($rows as $r) {
    $did = (int)$r['d_id'];
    $valOf->execute([$did]);
    $gv = rtrim(rtrim((string)$valOf->fetchColumn(), '0'), '.');   // 15.50 → 15.5、25.00 → 25
    if ($gv === '') continue;
    $allOf->execute([$did]);
    $all = (string)$allOf->fetchColumn();
    if (strpos($all, ',') !== false) $multi[] = "d_id={$did} 齒輪表有多個值（{$all}），取第一列 {$gv}";

    $lv = eg_part_label_value($pdo, $did, $labelId);
    if ($lv === null || trim((string)$lv) === '') { $toFill[$did] = $gv; continue; }
    if (abs(floatval($lv) - floatval($gv)) < 0.0001) $sameV[] = $did;
    else $diff[] = "d_id={$did} 標籤={$lv} 齒輪表={$gv}（以標籤為準，不動）";
}

echo "── 試算 ──────────────────────────────────\n";
echo "  齒輪表有工件總長的料號：" . count($rows) . " 支\n";
echo "  標籤還沒有值、要回填的：" . count($toFill) . " 支\n";
echo "  兩邊都有且數字相同的：" . count($sameV) . " 支\n";
echo "  兩邊都有但數字不同的：" . count($diff) . " 支（一律以標籤為準，只列出來）\n";
foreach ($diff as $d)  echo "     ⚠ {$d}\n";
foreach ($multi as $m) echo "     ℹ {$m}\n";

if (!$run) { echo "\n（這是試算，沒有寫入。要實際執行請加 --run）\n"; exit(0); }

$pdo->beginTransaction();
try {
    $ok = 0;
    foreach ($toFill as $did => $v) { if (eg_part_label_set($pdo, $did, $labelId, $v)) $ok++; }
    $pdo->commit();
    echo "\n✓ 已回填 {$ok} 支料號的工件總長標籤\n";
} catch (Throwable $e) {
    $pdo->rollBack();
    echo "✗ 失敗並已回滾：" . $e->getMessage() . "\n";
    exit(1);
}

$n = $pdo->prepare("SELECT COUNT(*) FROM item_label_map WHERE label_id=? AND input_value IS NOT NULL AND input_value<>''");
$n->execute([$labelId]);
echo "  目前有工件總長的料號共 " . $n->fetchColumn() . " 支\n";
