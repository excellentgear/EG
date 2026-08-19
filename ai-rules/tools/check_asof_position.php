<?php
/**
 * ai-rules/22 驗收：找出「會解析人員／顯示職稱，但沒有依業務日期回推」的嫌疑模組。
 *
 * 只能提示嫌疑點，不能證明正確——真正的驗收見 ai-rules/22 收尾段。
 * 執行：& C:\MAMP\bin\php\php8.3.1\php.exe C:\MAMP\htdocs\EGsystem\ai-rules\tools\check_asof_position.php
 */
$root = 'C:/MAMP/htdocs/EGsystem';
$scan = ['src/common', 'src/store', 'views'];

// 會「拿現況組織解析人」的呼叫
$nowCalls  = ['eg_org_dept_manager', 'eg_resolve_supervisor', 'eg_org_user'];
// 有依日期回推就會出現這些
$asOfCalls = ['eg_position_snapshot_at', 'eg_position_snapshot_at_bulk', 'fsd_pos_snapshot_at',
              'fsd_user_job_at', 'fsd_dept_manager_at', 'fsd_supervisor_at', 'as_doc_editor_term', 'editor_terms'];
// 表示這個模組確實有「業務日期」概念
$bizDate   = ['business_date', 'apply_date', 'submit_date', 'revised_date', 'audit_date', 'day_date', 'done_date'];

$files = [];
foreach ($scan as $d) {
    $it = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root . '/' . $d, FilesystemIterator::SKIP_DOTS));
    foreach ($it as $f) {
        $p = str_replace('\\', '/', $f->getPathname());
        if (substr($p, -4) !== '.php') continue;
        if (strpos($p, '/_封存') !== false || strpos($p, '/vendor/') !== false) continue;
        $files[] = $p;
    }
}

$hit = [];  $ok = [];
foreach ($files as $p) {
    $src = @file_get_contents($p);
    if ($src === false) continue;
    $hasNow = false; foreach ($nowCalls as $c) if (strpos($src, $c . '(') !== false) { $hasNow = true; break; }
    if (!$hasNow) continue;
    $hasDate = false; foreach ($bizDate as $c) if (strpos($src, $c) !== false) { $hasDate = true; break; }
    if (!$hasDate) continue;   // 沒有業務日期概念的模組不在規範範圍
    $hasAsOf = false; foreach ($asOfCalls as $c) if (strpos($src, $c) !== false) { $hasAsOf = true; break; }
    $rel = substr($p, strlen($root) + 1);
    if ($hasAsOf) $ok[] = $rel; else $hit[] = $rel;
}

echo "=== ai-rules/22 檢查：有業務日期又會解析人員的模組 ===\n\n";
echo "A. 尚未依業務日期回推職務（待檢查）：\n";
if (!$hit) echo "  （無）\n";
foreach ($hit as $r) echo "  - $r\n";
echo "\nB. 已有依日期回推的痕跡（僅供參考，仍需人工確認四個坑）：\n";
if (!$ok) echo "  （無）\n";
foreach ($ok as $r) echo "  - $r\n";
echo "\n共掃描 " . count($files) . " 支 PHP。\n";
echo "提醒：本工具只比對呼叫痕跡，抓不出「回推不到人就退回現況」這種邏輯錯誤（ai-rules/22 第1坑），\n";
echo "      該項一律要用「異動前後各建一張單」實測。\n";
