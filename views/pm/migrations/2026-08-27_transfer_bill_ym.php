<?php
/**
 * 2026-08-27_transfer_bill_ym.php — 製程移轉「帳款月份」建欄位＋回填
 * ---------------------------------------------------------------------------
 * 用法（CLI）：
 *   php 2026-08-27_transfer_bill_ym.php            預設試算（不寫入），列出將會怎麼算
 *   php 2026-08-27_transfer_bill_ym.php --run      實際建欄位/角色並回填
 *   php 2026-08-27_transfer_bill_ym.php --verify   檢查結果（含抽樣核對與異常清單）
 *
 * 可重複執行：--run 只會補「沒有帳款月份或算出來不一樣」的列，且**手動指定過的列一律不動**。
 * 規則見 src/common/billing_month_lib.php 檔頭。
 */

if (PHP_SAPI !== 'cli') { http_response_code(403); exit("僅限 CLI 執行\n"); }

require_once __DIR__ . '/../../../src/common/DBConnection.php';
require_once __DIR__ . '/../../../src/common/billing_month_lib.php';

$conn = new DBConnection();
$db   = $conn->getPDO();

$mode = 'dry';
foreach ($argv as $a) {
    if ($a === '--run')    $mode = 'run';
    if ($a === '--verify') $mode = 'verify';
}

echo "=== 製程移轉 帳款月份 回填工具（模式：{$mode}）===\n\n";

/* ── 目前設定 ───────────────────────────────────────────── */
$def = eg_bm_default_settlement($db);
$map = eg_bm_settlement_map($db);
echo "全域廠商預設：結帳模式 {$def['mode']}、結帳日 {$def['day']} 號\n";
$ownDay = array_filter($map, function ($s) { return $s['day'] !== null; });
echo "廠商主檔自訂結帳日：" . count($ownDay) . " 家"
   . "（另有 " . (count($map) - count($ownDay)) . " 家只設了結帳模式，結帳日沿用預設）\n";
foreach ($map as $no => $s) {
    if ($s['day'] === null) continue;
    echo "  - {$no}：{$s['day']} 號（{$s['mode']}）\n";
}
echo "\n";

if ($mode === 'run') {
    eg_bm_ensure_schema($db);
    echo "欄位與角色已確認（bill_ym / bill_ym_manual / bill_ym_by / bill_ym_at；roles: ptl_admin, ptl_bill_edit）\n\n";
}

/* 欄位還沒建時（試算模式）就只做計算不碰 DB */
$hasCol = false;
try {
    $db->query("SELECT bill_ym FROM bom_ing_transfer_log LIMIT 1");
    $hasCol = true;
} catch (Throwable $e) {}

if ($mode === 'verify') {
    if (!$hasCol) exit("尚未建立 bill_ym 欄位，請先執行 --run\n");
    $r = $db->query("SELECT COUNT(*) total,
                            SUM(bill_ym IS NULL OR bill_ym='') empty_ym,
                            SUM(bill_ym_manual=1) AS manual_cnt,
                            MIN(bill_ym) mn, MAX(bill_ym) mx
                     FROM bom_ing_transfer_log")->fetch(PDO::FETCH_ASSOC);
    echo "總筆數 {$r['total']}、無帳款月份 {$r['empty_ym']}、手動指定 {$r['manual_cnt']}、範圍 {$r['mn']} ~ {$r['mx']}\n\n";

    echo "各帳款月份筆數（最近 12 個月）：\n";
    foreach ($db->query("SELECT bill_ym, COUNT(*) c FROM bom_ing_transfer_log
                         WHERE bill_ym IS NOT NULL AND bill_ym<>''
                         GROUP BY bill_ym ORDER BY bill_ym DESC LIMIT 12")->fetchAll(PDO::FETCH_ASSOC) as $x) {
        echo "  " . eg_bm_ym_label($x['bill_ym']) . "：{$x['c']} 筆\n";
    }

    echo "\n抽樣核對（非手動列，重算一次比對是否一致）：\n";
    $bad = 0; $n = 0;
    $st = $db->query("SELECT transfer_id, transfer_no, transfer_date, maker_from, bill_ym
                      FROM bom_ing_transfer_log WHERE bill_ym_manual=0 ORDER BY RAND() LIMIT 300");
    foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $row) {
        $n++;
        $calc = eg_bm_calc_row($db, $row);
        if ((string)$calc !== (string)$row['bill_ym']) {
            $bad++;
            if ($bad <= 10) echo "  ✗ {$row['transfer_no']} 存 {$row['bill_ym']} / 算 {$calc}\n";
        }
    }
    echo "  抽樣 {$n} 筆，不一致 {$bad} 筆\n";

    $nd = $db->query("SELECT COUNT(*) FROM bom_ing_transfer_log
                      WHERE (bill_ym IS NULL OR bill_ym='') AND bill_ym_manual=0")->fetchColumn();
    if ($nd > 0) {
        echo "\n⚠ 仍有 {$nd} 筆算不出帳款月份（單號非 J- 格式且沒有日期）：\n";
        foreach ($db->query("SELECT transfer_id, transfer_no, transfer_date FROM bom_ing_transfer_log
                             WHERE (bill_ym IS NULL OR bill_ym='') AND bill_ym_manual=0 LIMIT 20")->fetchAll(PDO::FETCH_ASSOC) as $x) {
            echo "  - id={$x['transfer_id']} 單號[{$x['transfer_no']}] 日期[{$x['transfer_date']}]\n";
        }
    }
    exit(0);
}

/* ── 試算／回填 ─────────────────────────────────────────── */
$sel = $db->query("SELECT transfer_id, transfer_no, transfer_date, maker_from"
                . ($hasCol ? ", bill_ym, bill_ym_manual" : "") . "
                  FROM bom_ing_transfer_log ORDER BY transfer_id");

$stat = ['total'=>0, 'calc'=>0, 'no_date'=>0, 'manual'=>0, 'changed'=>0, 'same'=>0];
$dist = [];      // 帳款月份分佈
$sample = [];    // 前 10 筆試算樣本
$noDateList = [];

$upd = null;
if ($mode === 'run') {
    $db->beginTransaction();
    $upd = $db->prepare("UPDATE bom_ing_transfer_log SET bill_ym = ? WHERE transfer_id = ? AND bill_ym_manual = 0");
}

while ($row = $sel->fetch(PDO::FETCH_ASSOC)) {
    $stat['total']++;
    if ($hasCol && (int)($row['bill_ym_manual'] ?? 0) === 1) { $stat['manual']++; continue; }

    $base = eg_bm_base_date($row['transfer_no'], $row['transfer_date']);
    if ($base === null) {
        $stat['no_date']++;
        if (count($noDateList) < 20) $noDateList[] = $row;
        continue;
    }
    $s  = eg_bm_settlement_for($db, $row['maker_from']);
    $ym = eg_bm_calc($base, (int)$s['day'], (string)$s['mode']);
    $stat['calc']++;
    $dist[$ym] = ($dist[$ym] ?? 0) + 1;

    if (count($sample) < 10) {
        $sample[] = "{$row['transfer_no']}（{$row['maker_from']}，結帳日 {$s['day']}"
                  . ($s['source'] === 'vendor' ? '·廠商自設' : '·預設') . "）"
                  . " {$base} → " . eg_bm_ym_label($ym);
    }

    if ($hasCol && (string)($row['bill_ym'] ?? '') === (string)$ym) { $stat['same']++; continue; }
    $stat['changed']++;
    if ($mode === 'run') $upd->execute([$ym, $row['transfer_id']]);
}

if ($mode === 'run') { $db->commit(); }

echo "掃描 {$stat['total']} 筆：算得出 {$stat['calc']}、算不出（無日期）{$stat['no_date']}、手動指定跳過 {$stat['manual']}\n";
echo ($mode === 'run' ? "寫入" : "將寫入") . " {$stat['changed']} 筆，內容相同不動 {$stat['same']} 筆\n\n";

echo "試算樣本：\n";
foreach ($sample as $s) echo "  {$s}\n";

krsort($dist);
echo "\n帳款月份分佈（最近 12 個月）：\n";
$i = 0;
foreach ($dist as $ym => $c) {
    if ($i++ >= 12) break;
    echo "  " . eg_bm_ym_label($ym) . "：{$c} 筆\n";
}

if ($noDateList) {
    echo "\n算不出帳款月份的列（單號非 J- 格式且沒有日期）：\n";
    foreach ($noDateList as $x) echo "  - id={$x['transfer_id']} 單號[{$x['transfer_no']}] 日期[{$x['transfer_date']}]\n";
}

if ($mode === 'dry') echo "\n（這是試算，沒有寫入任何資料。要實際執行請加 --run）\n";
