<?php
/**
 * check_php_lint_apache.php — 用「Apache 實際使用的設定」重新 lint 全部 PHP 檔
 *
 * ── 為什麼需要這一支（2026-10-13 真實事故）────────────────────────────────
 * 砂輪干涉 3D 的共用元件在 JS 字串裡寫了 XML 宣告的開頭，整個頁面變成**空白**，
 * 而 `php -l` 從頭到尾都說「No syntax errors detected」。
 *
 * 真因：**CLI 與 Apache 吃的是兩份不同的 php.ini，而且 short_open_tag 設定相反**
 *     CLI（php -l 走這支）  C:\MAMP\bin\php\php8.3.1\php.ini   short_open_tag = Off
 *     Apache（實際出頁面）  C:\MAMP\conf\php8.3.1\php.ini      short_open_tag = On
 * 所以在 Apache 眼中「<」加「?」就是 PHP 開始標籤，即使它寫在 JS 字串或 HTML 裡；
 * 檔案因此整份解析失敗。CLI 因為設定相反，完全驗不出來。
 *
 * 症狀最難查的地方：**頁面不是報錯，是安靜地少掉一截**——HTML 直接斷在出事的
 * 位置，後面的 script 根本沒被送出來，所以瀏覽器 console 也不會有任何例外，
 * 看起來就只是「畫面空白」。
 *
 * 做法：逐檔以 `php -d short_open_tag=1 -l` 重新檢查（＝Apache 的設定）。
 * 這樣零誤報——舊程式真的在用短標籤（views/pm/BomList.php 等）照樣會通過，
 * 只有「在 Apache 下真的會壞」的檔案才會被抓出來。
 *
 * 用法：
 *   & C:\MAMP\bin\php\php8.3.1\php.exe C:\MAMP\htdocs\EGsystem\ai-rules\tools\check_php_lint_apache.php
 *   加 --changed 只檢查 git 工作目錄有改動的檔案（快很多，日常收尾用這個）
 *
 * 要在 JS 字串裡寫出 XML 宣告或 PHP 範例時，把「<」與「?」分開拼接即可。
 */
$root = dirname(__DIR__, 2);
$php  = PHP_BINARY;
$onlyChanged = in_array('--changed', $argv, true);

$files = [];
if ($onlyChanged) {
    $out = [];
    exec('git -C ' . escapeshellarg($root) . ' status --porcelain', $out);
    foreach ($out as $line) {
        $f = trim(substr($line, 2));
        if ($f === '' || !preg_match('~\.(php|inc)$~i', $f)) continue;
        $p = $root . DIRECTORY_SEPARATOR . str_replace('/', DIRECTORY_SEPARATOR, trim($f, '"'));
        if (is_file($p)) $files[] = $p;
    }
} else {
    foreach (['views', 'src', 'ai-rules'] as $d) {
        $base = $root . DIRECTORY_SEPARATOR . $d;
        if (!is_dir($base)) continue;
        $it = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($base,
            FilesystemIterator::SKIP_DOTS));
        foreach ($it as $f) {
            if (!$f->isFile()) continue;
            $p = $f->getPathname();
            if (!preg_match('~\.(php|inc)$~i', $p)) continue;
            if (strpos($p, '_封存_') !== false) continue;
            $files[] = $p;
        }
    }
}

$bad = [];
$gone = 0;
foreach ($files as $p) {
    /* 平行 session 的暫存檔可能在掃描途中被刪掉，那不是錯誤，不可以報成不合格
       （2026-10-13 實際撞到一次：列檔案時還在、真的要 lint 時已經不存在）。 */
    if (!is_file($p)) { $gone++; continue; }
    $out = [];
    $rc  = 0;
    exec(escapeshellarg($php) . ' -d short_open_tag=1 -n -l ' . escapeshellarg($p) . ' 2>&1', $out, $rc);
    if ($rc !== 0) {
        $msg = '';
        foreach ($out as $l) {
            if (stripos($l, 'error') !== false) { $msg = trim($l); break; }
        }
        $bad[] = str_replace($root . DIRECTORY_SEPARATOR, '', $p) . "\n      " . $msg;
    }
}

echo '已用 Apache 的設定（short_open_tag=1）檢查 ' . (count($files) - $gone) . ' 支 PHP 檔案'
   . ($gone ? "（另有 $gone 支在掃描途中被刪除，已略過）" : '') . "\n\n";
echo "在 Apache 下會解析失敗、但 php -l 驗不出來的檔案：\n";
if (!$bad) {
    echo "  （無）\n\n結果：全部通過。\n";
    exit(0);
}
foreach ($bad as $b) echo "  - $b\n";
echo "\n共 " . count($bad) . " 支。\n結果：不合格。\n";
exit(1);
