<?php
/**
 * 回歸測試：確認抽出的 eg_annual_leave_raw() 與 git HEAD 版 calculateProratedAnnualLeave() 結果完全一致。
 * 舊版函式由 git show HEAD:src/store/_employee_api.php 取出，改名後同時執行比對。
 */
$root = 'C:/MAMP/htdocs/EGsystem';
require_once $root . '/src/common/annual_leave_lib.php';

// 取出舊版原始碼中的函式並改名
$old = shell_exec('git -C ' . escapeshellarg($root) . ' show HEAD:src/store/_employee_api.php');
if (!$old) { fwrite(STDERR, "無法取得 HEAD 版檔案\n"); exit(1); }
$start = strpos($old, 'function calculateProratedAnnualLeave($hireDate) {');
if ($start === false) { fwrite(STDERR, "找不到舊版函式\n"); exit(1); }
// 由 function 起算，用大括號配對抓出整個函式
$depth = 0; $i = strpos($old, '{', $start); $end = null;
for ($p = $i; $p < strlen($old); $p++) {
    if ($old[$p] === '{') $depth++;
    elseif ($old[$p] === '}') { $depth--; if ($depth === 0) { $end = $p; break; } }
}
$src = substr($old, $start, $end - $start + 1);
$src = str_replace('function calculateProratedAnnualLeave(', 'function OLD_calc(', $src);
eval($src);

// 測試資料：涵蓋各年資級距、週年日邊界、閏年、年初/年末到職
$dates = [];
foreach (['2026','2025','2024','2023','2021','2019','2016','2014','2010','2005','1998'] as $y) {
    foreach (['01-01','02-28','02-29','03-15','06-30','07-01','08-15','12-01','12-31'] as $md) {
        $d = "$y-$md";
        if (!checkdate((int)substr($md,0,2), (int)substr($md,3,2), (int)$y)) continue;
        $dates[] = $d;
    }
}
$dates[] = null;
$dates[] = '';

$fail = 0; $n = 0;
foreach ($dates as $d) {
    $n++;
    $a = OLD_calc($d);
    $b = eg_annual_leave_raw($d);
    if (abs((float)$a - (float)$b) > 0.0000001) {
        $fail++;
        printf("[DIFF] hire=%s  舊=%s  新=%s\n", var_export($d, true), var_export($a, true), var_export($b, true));
    }
}
printf("raw 一致性測試：%d 筆，差異 %d 筆 → %s\n", $n, $fail, $fail === 0 ? 'PASS' : 'FAIL');

// entitlement 閘門測試（未滿6個月應為 0）
$today = date('Y-m-d');
$cases = [
    // [到職日, 基準日, 期望]
    [date('Y-m-d', strtotime('-1 month')),  $today, 0.0],   // 到職1個月 → 0
    [date('Y-m-d', strtotime('-5 month')),  $today, 0.0],   // 到職5個月 → 0
];
$fail2 = 0;
foreach ($cases as [$hire, $asOf, $exp]) {
    $got = eg_annual_leave_entitlement($hire, (int)date('Y', strtotime($asOf)), $asOf);
    if (abs($got - $exp) > 0.0000001) { $fail2++; printf("[DIFF] entitlement hire=%s asOf=%s 期望=%s 實際=%s\n", $hire, $asOf, $exp, $got); }
}
// 到職滿7個月（且在今年內滿6個月）應 > 0
$hire7 = date('Y-m-d', strtotime('-7 month'));
$got7  = eg_annual_leave_entitlement($hire7, (int)date('Y'), $today);
$raw7  = eg_annual_leave_raw($hire7);
if ($raw7 > 0 && $got7 <= 0) { $fail2++; printf("[DIFF] 滿7個月被閘門誤擋 hire=%s raw=%s ent=%s\n", $hire7, $raw7, $got7); }
printf("entitlement 閘門測試：差異 %d 筆 → %s（滿7個月 raw=%s ent=%s）\n", $fail2, $fail2 === 0 ? 'PASS' : 'FAIL', $raw7, $got7);

exit(($fail + $fail2) === 0 ? 0 : 1);
