<?php
/**
 * 出貨／退貨單價改成保留小數：is_list.Unit_price、ir_track.Unit_price（INT → DECIMAL(12,2)）
 * ──────────────────────────────────────────────────────────────────────
 * 【為什麼】ERP 的單價有 10.3 這種小數（使用者確認「某些欄位真的有小數」），而這兩個欄位是
 *           INT，寫進去就被四捨五入成 10，金額跟著差。報價那邊（quotation_item.unit_price）
 *           本來就是 DECIMAL(12,2)，沒有這個問題。
 *
 * 【已經先做好的事】匯入程式（_upload_For_List.php）的單價已改成保留小數送出。
 *           欄位還是 INT 時，MySQL 收到小數會自己四捨五入（實測不報錯），行為與改動前完全相同；
 *           所以「程式先上、欄位晚點再改」不會壞，這支工具可以等你方便的時候再跑。
 *
 * 【這支工具做什麼】只改欄位型別，**一筆資料都不動**。已經被四捨五入掉的舊單價救不回來
 *           （10.3 存成 10 之後跟「本來就是 10」分不出來），要正確值一樣得從 ERP 原始檔重匯，
 *           或用 2026-09-21_fix_erp_quote_qty.php 那種比對更新的做法。
 *
 * 【用法】cd 到本檔所在目錄後：
 *   php 2026-09-21_unit_price_decimal.php            ← 試算（預設，只看現況不改）
 *   php 2026-09-21_unit_price_decimal.php --run      ← 實際變更欄位型別
 *   可重複執行：已經是 DECIMAL 的就跳過。
 */

if (PHP_SAPI !== 'cli') { exit("這支工具只能用指令列執行\n"); }
$doRun = in_array('--run', array_slice($argv, 1), true);

require_once __DIR__ . '/../../../src/common/DBConnection.php';
$db = (new DBConnection())->getPDO();

$targets = [
    ['table' => 'is_list',  'col' => 'Unit_price', 'what' => '出貨單價'],
    ['table' => 'ir_track', 'col' => 'Unit_price', 'what' => '退貨單價'],
];

echo "模式：" . ($doRun ? "★ 實際變更（--run）" : "試算（不做任何變更）") . "\n";
echo str_repeat('─', 72) . "\n";

$todo = [];
foreach ($targets as $t) {
    $st = $db->prepare("SELECT DATA_TYPE, COLUMN_TYPE, IS_NULLABLE, COLUMN_DEFAULT
                          FROM information_schema.COLUMNS
                         WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND COLUMN_NAME = ?");
    $st->execute([$t['table'], $t['col']]);
    $info = $st->fetch(PDO::FETCH_ASSOC);
    if (!$info) { printf("%-10s %-12s ⚠ 找不到這個欄位，跳過\n", $t['table'], $t['col']); continue; }

    $cnt = (int)$db->query("SELECT COUNT(*) FROM `{$t['table']}`")->fetchColumn();
    $cur = $info['COLUMN_TYPE'];
    if (stripos($info['DATA_TYPE'], 'decimal') !== false) {
        printf("%-10s %-12s 目前 %-14s 已經是 DECIMAL，跳過（%s 筆）\n", $t['table'], $t['col'], $cur, number_format($cnt));
        continue;
    }
    printf("%-10s %-12s 目前 %-14s → decimal(12,2)　（%s 筆資料，%s）\n",
        $t['table'], $t['col'], $cur, number_format($cnt), $t['what']);
    $todo[] = $t + ['null' => $info['IS_NULLABLE'] === 'YES', 'cur' => $cur];
}

if (!$todo) { echo "\n沒有需要變更的欄位。\n"; exit(0); }

if (!$doRun) {
    echo "\n" . str_repeat('─', 72) . "\n";
    echo "以上為試算，欄位沒有變更。確認後加 --run 實際執行。\n";
    echo "提醒：變更後 PDO 讀回來的值會是字串（\"10.30\"），顯示端若有用 === 比較數字要留意；\n";
    echo "      本專案既有的讀取端多半是 (float) 轉型或 SQL 端 ROUND，實測不受影響。\n";
    exit(0);
}

echo "\n開始變更…\n";
foreach ($todo as $t) {
    // NOT NULL／預設值沿用原本的設定，只換型別（不可順手改成 NULL，既有程式可能沒處理 null）
    $nullSql = $t['null'] ? 'NULL' : 'NOT NULL DEFAULT 0';
    $sql = "ALTER TABLE `{$t['table']}` MODIFY `{$t['col']}` DECIMAL(12,2) $nullSql";
    try {
        $db->exec($sql);
        printf("  ✓ %s.%s 已改成 decimal(12,2)\n", $t['table'], $t['col']);
    } catch (Exception $e) {
        printf("  ✗ %s.%s 失敗：%s\n", $t['table'], $t['col'], $e->getMessage());
    }
}
echo "\n完成。既有數值不受影響（原本就是整數，只是型別變成可存小數）。\n";
echo "往後 ERP 匯入的 10.3 就會原樣存成 10.30，不再被四捨五入成 10。\n";
