<?php
/**
 * 左側欄健檢 —— 新頁面收尾必跑（CLAUDE.md 鐵律6）
 *
 * 用法：& C:\MAMP\bin\php\php8.3.1\php.exe C:\MAMP\htdocs\EGsystem\ai-rules\tools\check_sidebar.php
 * 通過條件：三項全部為「（無）」。有任何一項列出檔案＝那些頁面的左側欄是壞的。
 *
 * 為什麼要有這支：側欄失效已重複發生四次，每次都是「規則有寫、但人沒照做」。
 * 靠記憶遵守會再犯，靠這支掃描才擋得住——它只讀檔不改檔，可以隨時跑。
 *
 * 三種已實測的失效根因（詳見 ai-rules/00-診斷.md 陷阱表）：
 *   A. 抄了 CSS #sidebar-menu{visibility:hidden} 卻沒抄 ready 內的 visible 還原 → 側欄整片不見
 *   B. 沒載 custom.min.js → 選單畫得出來但點不開
 *   C. 五支 JS 順序錯 → custom.min.js 找不到 jQuery/Bootstrap，行為不定
 */

$root = dirname(__DIR__, 2) . DIRECTORY_SEPARATOR . 'views';
if (!is_dir($root)) { fwrite(STDERR, "找不到 views 目錄：$root\n"); exit(2); }

$reHid    = '/#sidebar-menu\s*\{[^}]*visibility\s*:\s*hidden/i';
$reVisJs  = '/#sidebar-menu.{0,3}\)\s*\.css\(\s*.{1}visibility.{1}\s*,\s*.{1}visible/i';
$reVisCss = '/#sidebar-menu\s*\{[^}]*visibility\s*:\s*visible/i';
$order    = ['jquery.min.js', 'bootstrap.min.js', 'fastclick.js', 'nprogress.js', 'custom.min.js'];

/**
 * 只認真正的 <script src="…/檔名">，不認註解或別的檔名的子字串。
 * 兩種實測過的誤判：data_console.php 在註解裡提到 custom.min.js；
 * quotation_list_NEW.php 載了 jquery-ui-1.10.2.custom.min.js（尾巴剛好也是 custom.min.js）。
 * 檔名前強制要有 / 才算，就能把這兩種都排除。
 */
$scriptPos = function (string $src, string $file) {
    $re = '/<script\b[^>]*\bsrc\s*=\s*["\'][^"\']*\/' . preg_quote($file, '/') . '["\'?]/i';
    return preg_match($re, $src, $m, PREG_OFFSET_CAPTURE) ? $m[0][1] : false;
};

$brokenA = $brokenB = $brokenC = [];
$total = 0;

$it = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root, FilesystemIterator::SKIP_DOTS));
foreach ($it as $f) {
    if (strtolower($f->getExtension()) !== 'php') continue;
    $path = str_replace('\\', '/', $f->getPathname());
    if (strpos($path, '_封存') !== false) continue;          // 封存的舊校務檔不列入
    $src = file_get_contents($path);
    if (strpos($src, 'sideAndTopBarMenu') === false) continue; // 沒掛側欄的頁面（AJAX端點等）不算
    $total++;
    $rel = substr($path, strpos($path, '/views/') + 1);

    // A：隱藏了卻沒還原
    if (preg_match($reHid, $src) && !preg_match($reVisJs, $src) && !preg_match($reVisCss, $src)) {
        $brokenA[] = $rel;
    }
    // B：缺 custom.min.js
    if ($scriptPos($src, 'custom.min.js') === false && $scriptPos($src, 'custom.js') === false) {
        $brokenB[] = $rel;
        continue;                                             // 缺檔就不必再驗順序
    }
    // C：五支 JS 的實際出現順序（只驗有載的那幾支）
    $pos = [];
    foreach ($order as $js) {
        $p = $scriptPos($src, $js);
        if ($p !== false) $pos[$js] = $p;
    }
    $actual = $pos;
    asort($actual);                                           // 依實際位置排序＝畫面上真正的載入順序
    if (array_keys($actual) !== array_keys($pos)) {
        $brokenC[] = $rel . '（實際順序：' . implode('→', array_keys($actual)) . '）';
    }
}

$show = function (string $title, array $rows): bool {
    echo $title . "\n";
    echo $rows ? '  - ' . implode("\n  - ", $rows) . "\n" : "  （無）\n";
    return (bool)$rows;
};

echo "左側欄健檢：掃描含側欄的頁面共 {$total} 支\n\n";
$bad  = $show('A. 抄了 #sidebar-menu{visibility:hidden} 卻沒有還原 visible（側欄整片不見）：', $brokenA);
echo "\n";
$bad |= $show('B. 有側欄但沒載 custom.min.js（選單畫得出來但點不開）：', $brokenB);
echo "\n";
$bad |= $show('C. 五支 JS 載入順序錯（應為 jquery→bootstrap→fastclick→nprogress→custom）：', $brokenC);

echo "\n" . ($bad ? "結果：不合格，上列頁面的左側欄是壞的，修好再收尾。\n"
                  : "結果：全部通過。\n");
exit($bad ? 1 : 0);
