<?php
/**
 * Word 文件「內容被固定列高裁掉」健檢
 *
 * 用法（文件改版後、或想確認整批文件時手動跑）：
 *   & C:\MAMP\bin\php\php8.3.1\php.exe C:\MAMP\htdocs\EGsystem\ai-rules\tools\check_word_clip.php
 *   & …\check_word_clip.php "D:\某資料夾"      ← 也可指定其他資料夾
 *
 * 這支在抓什麼：
 *   Word 表格列若設成「固定值」列高（XML: <w:trHeight w:hRule="exact">），
 *   放不下的內容會被**直接裁掉不顯示、列印也印不出來**，但文字仍留在檔案裡——
 *   於是「Ctrl+F 搜尋找得到、畫面上卻看不到」，而且系統的線上預覽走 LibreOffice 轉 PDF，
 *   不一定照 Word 的方式裁切，可能變成「Word 看不到、系統預覽看得到」，稽核時兩邊對不上。
 *   實例：3-SM-01 包裝指導書藏了一份舊的「7.使用表單 7.1 出貨標籤(2-WH-01-07)」（編號還是錯的）。
 *   詳見 ai-rules/00-診斷.md 陷阱表。
 *
 * 判斷方式（刻意「高精度、低召回」，寧可漏報也不要誤報）：
 *   A（會擋）：該列的**行內圖片**(wp:inline)實際高度＋文字估算高度 明顯超過固定列高。
 *             行內圖高度是檔案裡的精確值不需估算，命中幾乎必然是真的被裁。
 *   B（僅參考）：純文字型的列，用「每行約 41 字」估算而疑似溢出。
 *             這批 AS 文件每頁都用一個固定高度表格框住整頁，且有巢狀表格，
 *             純文字型的估算不可靠（會把整份文件當成一列），故只列出不擋。
 *   已排除不佔文字流高度者：浮動圖(wp:anchor)、VML 圖案(w:pict)、圖案文字方塊(w:txbxContent)。
 *
 * 注意：.doc 需先用 LibreOffice 轉 docx（第一次跑約數分鐘，轉出的檔放系統暫存不進版控）。
 */

$target = $argv[1] ?? (dirname(__DIR__, 2) . DIRECTORY_SEPARATOR . 'FOR CODEING 說明文件'
                       . DIRECTORY_SEPARATOR . 'AS9100(各組維護版)');
if (!is_dir($target)) { fwrite(STDERR, "找不到資料夾：$target\n"); exit(2); }

$soffice = 'C:\Program Files\LibreOffice\program\soffice.exe';
$cache   = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'eg_word_clip_cache';
if (!is_dir($cache)) { @mkdir($cache, 0777, true); }

$SKIP = '/舊版|勿用|作廢|不使用/u';                       // 舊版/作廢的不掃
$EMU_PER_TWIP = 635;                                      // 914400 EMU/inch ÷ 1440 twip/inch
$LINE_TWIPS   = 280;                                      // 12pt 標楷體單行約 280 twips
$CHARS_PER_LN = 42;                                       // A4 直式 12pt 一行約 40~45 字

// ── 收集現行版檔案 ──
$docs = $docx = [];
$it = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($target, FilesystemIterator::SKIP_DOTS));
foreach ($it as $f) {
    $p = str_replace('\\', '/', $f->getPathname());
    $n = $f->getFilename();
    if (strpos($n, '~$') === 0 || preg_match($SKIP, $p)) { continue; }
    $ext = strtolower($f->getExtension());
    if ($ext === 'doc')  { $docs[] = $f->getPathname(); }
    if ($ext === 'docx') { $docx[] = $p; }
}

// ── .doc → .docx（已轉過且比原檔新就沿用快取）──
$todo = [];
foreach ($docs as $d) {
    $out = $cache . DIRECTORY_SEPARATOR . pathinfo($d, PATHINFO_FILENAME) . '.docx';
    if (!is_file($out) || filemtime($out) < filemtime($d)) { $todo[] = $d; }
}
if ($todo) {
    if (!is_file($soffice)) { fwrite(STDERR, "找不到 LibreOffice：$soffice（無法轉 .doc，只能掃 .docx）\n"); }
    else {
        echo '轉換 ' . count($todo) . " 份 .doc（第一次較久，之後會用快取）…\n";
        foreach (array_chunk($todo, 20) as $chunk) {
            $args = '';
            foreach ($chunk as $c) { $args .= ' "' . $c . '"'; }
            exec('"' . $soffice . '" --headless --convert-to docx --outdir "' . $cache . '"' . $args . ' 2>&1');
        }
    }
}
foreach (glob($cache . DIRECTORY_SEPARATOR . '*.docx') as $g) { $docx[] = str_replace('\\', '/', $g); }
$docx = array_values(array_unique($docx));

// ── 掃描 ──
$hitA = $hitB = [];
$scanned = $rowsExact = 0;
foreach ($docx as $file) {
    $z = new ZipArchive();
    if ($z->open($file) !== true) { continue; }
    $xml = $z->getFromName('word/document.xml');
    $z->close();
    if (!$xml) { continue; }
    $scanned++;

    $offset = 0;
    while (($s = strpos($xml, '<w:tr>', $offset)) !== false) {
        $e = strpos($xml, '</w:tr>', $s);
        if ($e === false) { break; }
        $row    = substr($xml, $s, $e - $s);
        $offset = $e + 7;

        if (!preg_match('/<w:trHeight w:val="(\d+)"\s+w:hRule="exact"/', $row, $m)) { continue; }
        $rowsExact++;
        $h = (int)$m[1];
        if ($h <= 2800) { continue; }                     // 一行高的小格（制修訂紀錄等）不判

        // 行內圖片實際高度（精確值）
        $imgTw = 0; $imgN = 0;
        if (preg_match_all('/<wp:inline\b.*?<wp:extent[^>]*cy="(\d+)"/s', $row, $mm)) {
            foreach ($mm[1] as $cy) { $imgTw += (int)round($cy / $EMU_PER_TWIP); $imgN++; }
        }
        // 拿掉不佔文字流高度的東西再算文字
        $flow = preg_replace('/<mc:AlternateContent\b.*?<\/mc:AlternateContent>/s', '', $row);
        $flow = preg_replace('/<w:drawing\b(?:(?!<\/w:drawing>).)*?<wp:anchor\b.*?<\/w:drawing>/s', '', $flow);
        $flow = preg_replace('/<w:pict\b.*?<\/w:pict>/s', '', $flow);
        $flow = preg_replace('/<w:txbxContent\b.*?<\/w:txbxContent>/s', '', $flow);

        preg_match_all('/<w:p[ >].*?<\/w:p>/s', $flow, $ps);
        $lines = 0; $chars = 0;
        foreach ($ps[0] as $para) {
            preg_match_all('/<w:t[^>]*>(.*?)<\/w:t>/s', $para, $ts);
            $len = mb_strlen(trim(html_entity_decode(implode('', $ts[1]), ENT_QUOTES, 'UTF-8')));
            $chars += $len;
            $lines += max(1, (int)ceil($len / $CHARS_PER_LN));
        }
        $est = $imgTw + $lines * $LINE_TWIPS;
        if ($est <= $h + 1700) { continue; }               // 溢出未達約 3cm 不判

        preg_match_all('/<w:t[^>]*>(.*?)<\/w:t>/s', $flow, $all);
        $tail = mb_substr(trim(preg_replace('/\s+/u', ' ', strip_tags(implode('', $all[1])))), -70);
        $line = sprintf('%s｜列高%.1fcm 內容約%.1fcm 溢出約%.1fcm（行內圖%d張 文字%d字）｜列尾：%s',
            basename($file), $h / 1440 * 2.54, $est / 1440 * 2.54,
            ($est - $h) / 1440 * 2.54, $imgN, $chars, $tail);

        if ($imgN >= 1) { $hitA[] = $line; } else { $hitB[] = $line; }
    }
}

// ── 輸出 ──
echo "\nWord 裁切健檢：掃描 {$scanned} 份現行版文件、固定列高(hRule=exact)的列共 {$rowsExact} 列\n\n";
echo "A. 確定有內容被裁掉（行內圖片高度為精確值，命中即為真）：\n";
echo $hitA ? '  - ' . implode("\n  - ", $hitA) . "\n" : "  （無）\n";
echo "\nB.（參考，不擋）純文字型、估算疑似溢出——這批文件每頁都是固定高度表格框且有巢狀表格，\n"
   . "   純文字估算不可靠，需人工開檔確認；判定方式見本檔頂部說明：\n";
echo $hitB ? '  - ' . implode("\n  - ", array_slice($hitB, 0, 30))
           . (count($hitB) > 30 ? "\n  -（其餘 " . (count($hitB) - 30) . " 筆略）\n" : "\n")
           : "  （無）\n";

echo "\n" . ($hitA
    ? "結果：不合格。上列文件有看不見的內容，請刪除該段或把列高由「固定值」改成「最小值」。\n"
    : "結果：A 項通過（沒有確定被裁切的內容）。\n");
exit($hitA ? 1 : 0);
