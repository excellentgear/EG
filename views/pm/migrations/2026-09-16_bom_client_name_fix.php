<?php
/**
 * 2026-09-16_bom_client_name_fix.php
 *
 * 把 `bom.Client_Name`（文字快取）跟「該製令綁定的料號主檔 / 它的訂單」對回去。
 *
 * 為什麼會對不起來：ERP BOM 匯入本來是「用料號文字去查客戶」，而同一個料號文字在
 * `d_setting` 常常有好幾筆（不同客戶），例 OB321500060 → #765 旻成、#19804 松田，
 * 於是匯入會挑到後面那一筆、把別家的客戶寫進這張製令，而且完全不報錯。
 * 匯入端已於同日修正（views/pm/_upload_For_List.php），本檔是把**既有的髒資料**補回來。
 *
 * 用法（CLI）：
 *   php 2026-09-16_bom_client_name_fix.php            ← 只試算，不寫入（預設）
 *   php 2026-09-16_bom_client_name_fix.php --run      ← 只修「訂單也佐證同一家」＋「只差空白字元」的
 *   php 2026-09-16_bom_client_name_fix.php --run-all  ← 連「沒有訂單可佐證」的也一起修（請先看過試算清單）
 *   php 2026-09-16_bom_client_name_fix.php --verify   ← 修完再確認一次還有沒有不一致
 *
 * 只動 `bom.Client_Name` 一個欄位，不碰 d_setting_id / o_order_id / 數量 / 狀態。
 * 可重複執行。
 */

if (PHP_SAPI !== 'cli') { exit("本檔只能用 CLI 執行\n"); }

$db = new PDO("mysql:host=127.0.0.1;dbname=EGsystem;port=3306;charset=utf8mb4", "EG-TS2024", "excell30367593");
$db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

$argvFlags = array_slice($argv, 1);
$doRun     = in_array('--run', $argvFlags, true);
$doRunAll  = in_array('--run-all', $argvFlags, true);
$doVerify  = in_array('--verify', $argvFlags, true);

$SQL = "
    SELECT b.bom, b.d_id, b.d_setting_id, b.Client_Name AS bom_client,
           cl.customer  AS master_client,
           b.o_order_id, ot.Order_oo,
           cl_ord.customer AS order_client
    FROM bom b
    JOIN d_setting ds    ON ds.d_id = b.d_setting_id
    JOIN customer_list cl ON cl.customer_id = ds.Customer_Id
    LEFT JOIN order_track ot ON ot.Order_id = b.o_order_id AND b.o_order_id REGEXP '^[0-9]+$'
    LEFT JOIN d_setting ds2 ON ds2.d_id = ot.d_id_ID
    LEFT JOIN customer_list cl_ord ON cl_ord.customer_id = COALESCE(ds2.Customer_Id, ot.Client_name_ID)
    WHERE b.Client_Name IS NOT NULL AND b.Client_Name <> ''
      AND cl.customer <> b.Client_Name
    ORDER BY b.bom DESC
";

$rows = $db->query($SQL)->fetchAll(PDO::FETCH_ASSOC);

$whitespaceOnly = [];  // 只差前後空白
$orderConfirm   = [];  // 訂單也是同一家
$noEvidence     = [];  // 沒有訂單可佐證
$conflict       = [];  // 訂單跟主檔不一致（一律不自動改）

foreach ($rows as $r) {
    if (trim($r['bom_client']) === trim($r['master_client'])) { $whitespaceOnly[] = $r; continue; }
    if ($r['order_client'] === null)                          { $noEvidence[]     = $r; continue; }
    if (trim($r['order_client']) === trim($r['master_client'])) $orderConfirm[] = $r;
    else                                                        $conflict[]     = $r;
}

function dump(string $title, array $list, int $limit = 200): void {
    echo "\n【{$title}】共 " . count($list) . " 筆\n";
    foreach (array_slice($list, 0, $limit) as $r) {
        printf("  %-14s %-22s 主檔#%-6s  現值[%s] → [%s]%s\n",
            $r['bom'], $r['d_id'], $r['d_setting_id'],
            $r['bom_client'], $r['master_client'],
            $r['order_client'] !== null ? '   訂單 ' . $r['Order_oo'] . '＝' . $r['order_client'] : '   （無訂單）');
    }
    if (count($list) > $limit) echo "  …（其餘 " . (count($list) - $limit) . " 筆略）\n";
}

if ($doVerify) {
    echo "剩餘不一致：" . count($rows) . " 筆\n";
    dump('仍不一致', $rows);
    exit(0);
}

dump('只差前後空白（安全）', $whitespaceOnly);
dump('訂單也佐證同一家（安全）', $orderConfirm);
dump('沒有訂單可佐證（請人工確認，--run 不會動它）', $noEvidence);
dump('訂單與主檔互相矛盾（一律不自動改）', $conflict);

if (!$doRun && !$doRunAll) {
    echo "\n※ 目前是試算模式，一列都沒有寫入。要實際修改請加 --run（安全的 "
       . (count($whitespaceOnly) + count($orderConfirm)) . " 筆）或 --run-all（再加上沒有訂單可佐證的 "
       . count($noEvidence) . " 筆）。\n";
    exit(0);
}

$target = array_merge($whitespaceOnly, $orderConfirm);
if ($doRunAll) $target = array_merge($target, $noEvidence);

$db->beginTransaction();
try {
    $up = $db->prepare("UPDATE bom SET Client_Name = ? WHERE bom = ? AND Client_Name <=> ?");
    $done = 0;
    foreach ($target as $r) {
        $up->execute([$r['master_client'], $r['bom'], $r['bom_client']]);
        $done += $up->rowCount();
    }
    $db->commit();
    echo "\n已更新 {$done} 筆（目標 " . count($target) . " 筆；差額＝期間已被別人改過，略過不覆蓋）。\n";
} catch (Throwable $e) {
    $db->rollBack();
    echo "\n失敗已全部回復：" . $e->getMessage() . "\n";
    exit(1);
}
