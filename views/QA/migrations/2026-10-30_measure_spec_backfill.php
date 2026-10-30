<?php
/**
 * 2026-10-30 把線上檢驗自動開立的舊異常單量測列（dim_name 內含「　標準 63.5（公差 +0.03 / 0）」文字）
 * 拆成結構化規格（spec／lim_lo／lim_hi），畫面才能顯示標準值與公差、並把超出範圍的實測值標紅。
 * 預設試算，加 --run 才寫入；可重複執行（已有 spec 的列不動）。
 */
chdir(__DIR__ . '/../../..');
require_once 'src/common/DBConnection.php';
require_once 'src/common/qa_abnormal_lib.php';
$db = (new DBConnection())->getPDO();
qab_ensure_schema($db);
$run = in_array('--run', $argv ?? [], true);
$rows = $db->query("SELECT m.id, m.order_id, m.dim_name FROM qa_abnormal_measure m JOIN qa_abnormal_order o ON o.id=m.order_id
                    WHERE m.spec IS NULL AND o.src_qc_form_id IS NOT NULL AND m.dim_name LIKE '%標準 %'")->fetchAll(PDO::FETCH_ASSOC);
$upd = $db->prepare("UPDATE qa_abnormal_measure SET dim_name=?, spec=?, lim_lo=?, lim_hi=? WHERE id=?");
$n = 0;
foreach ($rows as $r) {
    if (!preg_match('/^(.*?)　標準 (\S+?)(?:（公差 ([^\/）]*?)(?: \/ ([^）]*))?）)?(（加量測\d+）)?$/u', (string)$r['dim_name'], $mm)) { echo "略過 #{$r['id']}：{$r['dim_name']}\n"; continue; }
    $std = $mm[2] === '-' ? '' : $mm[2];
    $up = ltrim(trim($mm[3] ?? ''), '+');
    $lo = trim($mm[4] ?? '');
    [$spec, $lim_lo, $lim_hi] = qab_measure_spec(['spec' => ['mode' => 'TOL', 'type' => ($mm[2] === '-' ? 'OKNG' : 'NUM'), 'std' => $std, 'up' => $up, 'lo' => $lo]]);
    $name = $mm[1] . ($mm[5] ?? '');
    echo ($run ? '更新' : '試算') . " #{$r['id']} 訂單{$r['order_id']}：{$r['dim_name']} → {$name} | std={$std} up={$up} lo={$lo} 範圍=" . ($lim_lo ?? '-') . '~' . ($lim_hi ?? '-') . "\n";
    if ($run) $upd->execute([$name, $spec, $lim_lo, $lim_hi, $r['id']]);
    $n++;
}
echo "共 {$n} 列" . ($run ? '已更新' : '（試算，加 --run 寫入）') . "\n";
