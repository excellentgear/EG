<?php
/**
 * 輸入欄位互動規則健檢 —— 新頁面收尾必跑（CLAUDE.md「UI 規則」）
 *
 * 用法：& C:\MAMP\bin\php\php8.3.1\php.exe C:\MAMP\htdocs\EGsystem\ai-rules\tools\check_input_rules.php
 * 通過條件：兩項全部為「（無）」。
 *
 * 為什麼要有這支：「有值雙擊清空／聚焦全選／Enter 跳下一欄／表格↑↓換列／
 * 數字欄無增減鈕、小數尾 0 省略」這些規則 CLAUDE.md 早就寫了，
 * 但過去每頁自己手刻，結果會計 7 頁裡有 4 頁完全沒做、有做的也只綁了一部分欄位
 * （例如只綁跳窗內、沒綁篩選列）。規則只寫「怎麼寫」不寫「怎麼驗收」就會失守，
 * 所以改成「共用檔 resource/js/eg_input_rules.js」＋這支掃描。
 *
 * 兩種不合格：
 *   A. 有輸入欄位的頁面沒載 resource/js/eg_input_rules.js
 *   B. 載了但位置在 custom.min.js 之前（應放在版型 JS 之後）
 */

$root = dirname(__DIR__, 2) . DIRECTORY_SEPARATOR . 'views';
if (!is_dir($root)) { fwrite(STDERR, "找不到 views 目錄：$root\n"); exit(2); }

$scriptPos = function (string $src, string $file) {
    $re = '/<script\b[^>]*\bsrc\s*=\s*["\'][^"\']*\/' . preg_quote($file, '/') . '["\'?]/i';
    return preg_match($re, $src, $m, PREG_OFFSET_CAPTURE) ? $m[0][1] : false;
};

/* 基準線：2026-07-30 當下尚未導入的既有頁面。清單內的頁面只列為「待導入」，
   清單外（＝之後新增的頁面）漏載就算不合格。這是棘輪機制：鎖住現況不再退步，
   同時不強迫把共用檔硬塞進 76 支既有頁面而改變它們的行為。 */
$baselineFile = __DIR__ . DIRECTORY_SEPARATOR . 'input_rules_baseline.txt';
$baseline = [];
if (is_readable($baselineFile)) {
    foreach (file($baselineFile, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) as $line) {
        $line = trim($line);
        if ($line === '' || $line[0] === '#') continue;
        $baseline[$line] = true;
    }
}

$ARCHIVE = '_' . '封存';
$missing = [];
$backlog = [];
$order   = [];
$total   = 0;

$it = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root, FilesystemIterator::SKIP_DOTS));
foreach ($it as $f) {
    if (strtolower($f->getExtension()) !== 'php') continue;
    $path = str_replace('\\', '/', $f->getPathname());
    if (strpos($path, $ARCHIVE) !== false) continue;              // 封存的舊校務檔不列入
    $base = basename($path);
    if ($base !== '' && $base[0] === '_') continue;               // 共用片段由主頁面負責載入

    $src = file_get_contents($path);
    if (strpos($src, 'sideAndTopBarMenu') === false) continue;    // 只驗有版型的實際頁面

    // 沒有可輸入欄位的純報表頁不強求
    $hasInput = preg_match('/<input\b[^>]*type\s*=\s*["\'](?:text|search|number|date|month|tel|email|url)/i', $src)
             || stripos($src, '<textarea') !== false;
    if (!$hasInput) continue;

    $total++;
    $rel = substr($path, strpos($path, '/views/') + 1);

    $p = $scriptPos($src, 'eg_input_rules.js');
    if ($p === false) {
        if (isset($baseline[$rel])) $backlog[] = $rel;   // 既有頁面：待導入，不算不合格
        else                        $missing[] = $rel;   // 新頁面漏載：不合格
        continue;
    }
    $c = $scriptPos($src, 'custom.min.js');
    if ($c !== false && $p < $c) $order[] = $rel;
}

$show = function (string $title, array $rows): bool {
    echo $title . "\n";
    echo $rows ? '  - ' . implode("\n  - ", $rows) . "\n" : "  （無）\n";
    return (bool)$rows;
};

$done = $total - count($missing) - count($backlog) - 0;
printf("輸入欄位規則健檢：掃描有輸入欄位的頁面共 %d 支（已導入 %d、待導入 %d）\n\n",
       $total, $total - count($missing) - count($backlog), count($backlog));
$bad  = $show('A. 新頁面沒載入 resource/js/eg_input_rules.js（雙擊清空等規則失效）：', $missing);
echo "\n";
$bad |= $show('B. eg_input_rules.js 載在 custom.min.js 之前（應放在版型 JS 之後）：', $order);

if ($backlog) {
    printf("\n（參考）既有頁面待導入 %d 支，在 input_rules_baseline.txt 清單內，不算不合格：\n", count($backlog));
    echo '  ' . implode("\n  ", array_slice($backlog, 0, 8)) . "\n";
    if (count($backlog) > 8) echo '  …其餘 ' . (count($backlog) - 8) . " 支見基準線檔\n";
}

echo "\n" . ($bad ? "結果：不合格，上列頁面的輸入欄位規則沒生效。\n"
                  : "結果：全部通過。\n");
exit($bad ? 1 : 0);
