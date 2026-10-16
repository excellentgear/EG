<?php
/**
 * 人員下拉「兼任者只剩一個職務」健檢 —— 新增／修改任何「挑人」畫面收尾必跑
 *
 * 用法：& C:\MAMP\bin\php\php8.3.1\php.exe C:\MAMP\htdocs\EGsystem\ai-rules\tools\check_people_posts.php
 * 通過條件：「不在基準線內的檔案」為（無）。
 *
 * 為什麼要有這支：
 *   eg_people_list() ／ eg_people_list_asof() 一個人只回「一列」，挑的是職級最高那筆職務，
 *   所以主職在技術課、兼任生管組組長的人，挑人下拉只看得到「生管組　組長」，
 *   在技術課底下完全找不到他。這條規則 CLAUDE.md「人員列表鐵則⑥」早就寫了，
 *   但仍一再重犯（2026-10-16 風險與機會管理的填表人下拉又犯一次）——
 *   規則只寫「怎麼寫」不寫「怎麼驗收」就會失守（側欄、輸入規則都是同一個教訓），所以加這支掃描。
 *
 * 判定（刻意寬鬆、只抓最常見的漏法）：
 *   檔案呼叫了 eg_people_list( 或 eg_people_list_asof( ，
 *   而且檔案裡有組 <option 的痕跡（PHP 或 JS 都算），
 *   但整份檔案找不到 all_posts／eg_people_posts／eg_people_posts_asof／post_key → 不合格。
 *
 * 不適用（請在呼叫處用註解說明，不必改）：
 *   簽核對象、圖章職稱、通知收件人這類「要這個人的代表身分」的情境——那種情況只能有一個身分，
 *   不該列出兼任的每一個職務。這類檔案若被誤抓，請把檔名加進 people_posts_baseline.txt 並寫明原因。
 *
 * 基準線：2026-10-16 當下已存在的檔案只列為「待檢」，之後新增的檔案漏掉才算不合格（棘輪機制）。
 */
$base = dirname(__DIR__, 2);
$roots = [$base . DIRECTORY_SEPARATOR . 'views', $base . DIRECTORY_SEPARATOR . 'src'];
$baselineFile = __DIR__ . DIRECTORY_SEPARATOR . 'people_posts_baseline.txt';
$baseline = [];
if (is_readable($baselineFile)) {
    foreach (file($baselineFile, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) as $line) {
        $line = trim($line);
        if ($line === '' || $line[0] === '#') continue;
        $baseline[str_replace('\\', '/', $line)] = true;
    }
}
$archive = '_' . '封存';
$bad = []; $known = [];
foreach ($roots as $root) {
    if (!is_dir($root)) continue;
    $it = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root, FilesystemIterator::SKIP_DOTS));
    foreach ($it as $f) {
        if (!$f->isFile() || strtolower($f->getExtension()) !== 'php') continue;
        $path = str_replace('\\', '/', $f->getPathname());
        if (strpos($path, $archive) !== false || strpos($path, '/migrations/') !== false) continue;
        if (substr($path, -strlen('src/common/people_lib.php')) === 'src/common/people_lib.php') continue;
        $src = @file_get_contents($f->getPathname());
        if ($src === false) continue;
        if (!preg_match('/\beg_people_list(_asof)?\s*\(/', $src)) continue;
        if (!preg_match('/<option|\boption\b\s*value|\.append\(\s*[\'"]<option/i', $src)) continue;
        if (preg_match('/all_posts|eg_people_posts(_asof)?\s*\(|post_key/', $src)) continue;
        $rel = ltrim(str_replace(str_replace('\\', '/', $base), '', $path), '/');
        if (isset($baseline[$rel])) $known[] = $rel; else $bad[] = $rel;
    }
}
sort($bad); sort($known);
echo "【不合格】呼叫 eg_people_list／_asof 組下拉，卻沒有 all_posts／eg_people_posts／post_key（兼任者的主職會消失）：\n";
echo $bad ? '  ' . implode("\n  ", $bad) . "\n" : "  （無）\n";
echo "\n【待檢（基準線內，共 " . count($known) . " 支，改到這些檔案時順手處理）】\n";
foreach ($known as $k) echo "  $k\n";
exit($bad ? 1 : 0);
