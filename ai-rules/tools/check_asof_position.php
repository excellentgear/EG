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

/**
 * 去掉 PHP 註解（token_get_all，準確）＋ inline HTML 裡明顯是 JS 註解的整行。
 * 只給掃描用，不追求完美——目的是不要把「註解裡寫到函式名」誤判成呼叫。
 */
function strip_comments_for_scan(string $src): string {
    $out = '';
    try {
        foreach (@token_get_all($src) as $t) {
            if (is_array($t)) {
                if ($t[0] === T_COMMENT || $t[0] === T_DOC_COMMENT) continue;
                $out .= $t[1];
            } else $out .= $t;
        }
    } catch (Throwable $e) { $out = $src; }
    // 頁面裡的 JS 區塊註解（/* … */）token_get_all 看不到，它整段是 T_INLINE_HTML
    $out = preg_replace('~/\*.*?\*/~s', '', $out) ?? $out;
    $lines = preg_split('/\R/', $out);
    foreach ($lines as $i => $ln) {
        $t = ltrim($ln);
        if ($t !== '' && (strncmp($t, '//', 2) === 0 || strncmp($t, '*', 1) === 0
                          || strncmp($t, '/*', 2) === 0)) $lines[$i] = '';
    }
    return implode("\n", $lines);
}

$hit = [];  $ok = [];  $pick = [];
foreach ($files as $p) {
    $src = @file_get_contents($p);
    if ($src === false) continue;
    $rel = substr($p, strlen($root) + 1);
    $hasDate = false; foreach ($bizDate as $c) if (strpos($src, $c) !== false) { $hasDate = true; break; }

    /* C. 「挑人清單」也算解析人員（2026-09-16 補）——這一類原本完全掃不到。
       eg_people_list()／eg_people_posts() 一律用**今天**的在職狀態與職務，
       所以有業務日期的模組如果只呼叫它們、沒呼叫 *_asof 版本，補歷史單據時
       「當時在職、現已離職的人」一個都挑不到、職稱也印成現在的。
       這正是內部稽核 2026-09-16 被使用者回報的同一個坑（文件制修申請單更早就踩過一次），
       所以把它變成一行指令擋得住，不要再靠記得。 */
    // 註解裡提到函式名不算呼叫（本專案註解寫得很細，不濾掉的話嫌疑名單會被自己的說明文字灌爆）
    $code    = strip_comments_for_scan($src);
    $listNow = (strpos($code, 'eg_people_list(') !== false) || (strpos($code, 'eg_people_posts(') !== false);
    $listAs  = (strpos($code, 'eg_people_list_asof(') !== false) || (strpos($code, 'eg_people_posts_asof(') !== false);
    if ($hasDate && $listNow && !$listAs
        && strpos($rel, 'src/common/people_lib.php') === false) {
        $pick[] = $rel;
    }

    $hasNow = false; foreach ($nowCalls as $c) if (strpos($src, $c . '(') !== false) { $hasNow = true; break; }
    if (!$hasNow) continue;
    if (!$hasDate) continue;   // 沒有業務日期概念的模組不在規範範圍
    $hasAsOf = false; foreach ($asOfCalls as $c) if (strpos($src, $c) !== false) { $hasAsOf = true; break; }
    if ($hasAsOf) $ok[] = $rel; else $hit[] = $rel;
}

echo "=== ai-rules/22 檢查：有業務日期又會解析人員的模組 ===\n\n";
echo "A. 尚未依業務日期回推職務（待檢查）：\n";
if (!$hit) echo "  （無）\n";
foreach ($hit as $r) echo "  - $r\n";
echo "\nB. 已有依日期回推的痕跡（僅供參考，仍需人工確認四個坑）：\n";
if (!$ok) echo "  （無）\n";
foreach ($ok as $r) echo "  - $r\n";

echo "\nC. 有業務日期，但「挑人清單」只用現況（eg_people_list／eg_people_posts，沒有 *_asof）：\n";
echo "   → 補歷史單據時當時在職、現已離職的人挑不到，職稱也會印成現在的。\n";
if (!$pick) echo "  （無）\n";
foreach ($pick as $r) echo "  - $r\n";

echo "\n共掃描 " . count($files) . " 支 PHP。\n";
echo "提醒：本工具只比對呼叫痕跡，抓不出「回推不到人就退回現況」這種邏輯錯誤（ai-rules/22 第1坑），\n";
echo "      該項一律要用「異動前後各建一張單」實測。\n";
echo "      C 區是嫌疑名單不是錯誤清單：清單本來就只給「今天」用的頁面（現場簽到、即時通知對象）不必改。\n";
