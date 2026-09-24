<?php
/**
 * as_doc_print.php — AS 文件線上版的列印版（2026-09-22）
 *
 * 依 ai-rules/16：
 *   ・大標題＝本公司全名（動態取 customer_list.is_own_company=1 的 customer_full，禁寫死）
 *   ・表頭＝這份 AS 文件自己的名稱（doc_name），不寫死
 *   ・頁尾左下＝頁碼（counter(pages)，多頁才印）
 *   ・頁尾右下＝AS 文件編號＋版次，每頁都印
 *   ・紙張與方向依內容設定（A4/A3、直/橫式）
 * 依 ai-rules/23：按下列印就留一筆列印紀錄。
 *
 * 版次刻意「印這個版次自己的號」而不是用 eg_asdoc_no_asof_id() 回推：
 * 這一頁印的就是某一個特定版次的內容，版次本來就已經確定，
 * 回推是給「單據有業務日期、要找出當時生效版次」的情境用的。
 */
session_start();
if (!isset($_SESSION['userName'])) {
    header("Location:../../index.php");
    exit;
}
include_once '../../src/common/_config.php';
include_once '../../src/common/DBConnection.php';
include_once '../../src/common/as_doc_content_lib.php';
include_once '../../src/common/date_fmt_lib.php';

$db  = (new DBConnection())->getPDO();
adc_ensure_schema($db);
$uid = (int)($_SESSION['id'] ?? 0);
$P   = adc_perms($db, $uid);

$versionId = (int)($_GET['version_id'] ?? 0);
$V = $versionId > 0 ? adc_version_info($db, $versionId) : null;

$err = '';
if (!$P['view'])                                      $err = '沒有 AS 文件的檢視權限';
elseif (!$V)                                          $err = '找不到這個版次';
elseif ((int)$V['is_obsolete'] === 1 && !$P['admin']) $err = '這份文件已廢止，只有管理員能開啟';

$C = $err === '' ? adc_content_by_version($db, $versionId) : null;
if ($err === '' && (!$C || trim((string)$C['content_html']) === '')) $err = '這個版次還沒有線上版內容';

$company = '';
try {
    $company = (string)($db->query("SELECT customer_full FROM customer_list WHERE is_own_company=1 LIMIT 1")->fetchColumn() ?: '');
} catch (Throwable $e) {}

$pageSize   = ($C['page_size'] ?? 'A4') === 'A3' ? 'A3' : 'A4';
$orient     = ($C['orientation'] ?? 'portrait') === 'landscape' ? 'landscape' : 'portrait';
/* @page margin 固定「上16／左右15／下18mm」，內容區的實際可印高度＝紙張長邊
   （直式）或短邊（橫式）扣掉上下邊界，用來讓 .adt-page 在列印時有一個「明確的
   高度」可以撐開（見下方 @media print 的說明，這裡先算好給 CSS 字串直接套）。 */
$paperWH = $pageSize === 'A3' ? [297, 420] : [210, 297];
if ($orient === 'landscape') $paperWH = [$paperWH[1], $paperWH[0]];
$printContentH = $paperWH[1] - 16 - 18;
$docNo      = (string)($V['doc_no'] ?? '');
$docName    = (string)($V['doc_name'] ?? '');
$ver        = (string)($V['version'] ?? '');
$footRight  = $docNo . ($ver !== '' ? '　版次 ' . $ver : '');
$revDate    = $V['revised_date'] ? eg_fmt_date($V['revised_date']) : '';
$isPrimary  = (int)($C['is_primary'] ?? 0) === 1;

/* 版面（封面／制修訂紀錄書／目錄／每一頁的頁首頁尾）一律由 as_doc_tpl_lib 產生，
   編輯器用的是同一支——兩邊各寫一份版面一定會走鐘（鐵律4）。
   圖片走本站 API 網址（同一個 session，cookie 帶得過去）；列印前會等圖片載完才叫 print()。 */
$pagesHtml = [];
if ($err === '') {
    require_once '../../src/common/as_doc_tpl_lib.php';
    $ctx  = adt_context($db, $versionId);
    $body = adc_hydrate_html($db, (string)$C['content_html'], (int)$C['id'],
                             '../../src/store/AsDocContent_API.php?action=asset&id=');
    $conts = adt_split_pages($body);
    $sys   = adt_system_pages($ctx, $conts);
    /* 頁次只算正文（使用者 2026-09-23 指定：文件制修訂紀錄書與目錄都不算一頁），
       所以正文第一頁就是「1 / 正文總頁數」。 */
    $total = count($conts);
    $pv    = adt_page_versions($ctx['versions'], count($conts), $ctx['version']);

    /* 「第 X 頁／共 Y 頁」＋AS編號版次：改用 PHP 直接算好印成一般 HTML，
       不再靠 CSS 具名頁（page:revlog + @page revlog）去關掉——那招在真實瀏覽器的
       列印預覽裡沒有作用（Chrome 對 `page` 屬性/具名 @page 選取器的支援本來就
       不完整，只有無頭 Chrome 產 PDF 那條路徑恰好吃得到，真人打開列印對話框
       看到的仍是沒關掉的版本，使用者 2026-09-24 實測回報「你沒有改好」）。
       這裡的「頁」＝一張 <section class="adt-page">，這份文件本來就是「編輯器上
       看到一頁＝印出一頁」設計（每頁高度先算好、強制分頁），所以用 PHP 算出來的
       張數與瀏覽器實際印出的張數一致；跟內文表格自己的「頁次」欄位（adt_header_html）
       本來就是同一種「一個區塊算一頁」的算法，兩者現在也一致了。 */
    $sheetTotal = count($sys) + count($conts);
    $sheetNo    = 0;
    $sheetFoot  = function (bool $suppress) use (&$sheetNo, $sheetTotal, $footRight): string {
        $sheetNo++;
        if ($suppress) return '';
        $left = $sheetTotal > 1 ? ('第 ' . $sheetNo . ' 頁／共 ' . $sheetTotal . ' 頁') : '';
        return '<div class="adt-pgno"><span>' . htmlspecialchars($left, ENT_QUOTES) . '</span>'
             . '<span>' . htmlspecialchars($footRight, ENT_QUOTES) . '</span></div>';
    };

    $no    = 0;
    foreach ($sys as $s) {
        // 系統頁不印頁首（它們自己就是完整版面），也不計入頁次。
        // 頁尾（自訂文字＋AS編號）原則上照印，但「文件制修訂紀錄書」自己的
        // 表格已經印了文件編號／版次（使用者 2026-09-23 指定），再印一次頁尾／
        // 頁碼列都是重複，兩個都關掉。
        $isRevlog = ($s['key'] === 'revlog');
        $ftr = $isRevlog ? '' : adt_footer_html($ctx);
        $pagesHtml[] = '<section class="adt-page adt-page-' . $s['key'] . '">'
                     . '<div class="adt-body eg-docbody">' . $s['html'] . '</div>'
                     . $ftr . $sheetFoot($isRevlog) . '</section>';
    }
    foreach ($conts as $i => $one) {
        $no++;
        $pagesHtml[] = '<section class="adt-page">'
                     . adt_header_html($ctx, $no, $total, $pv[$i] ?? $ctx['version'])
                     . '<div class="adt-body eg-docbody">' . $one . '</div>'
                     . adt_footer_html($ctx) . $sheetFoot(false) . '</section>';
    }
}
?>
<!DOCTYPE html>
<html lang="zh-Hant">
<head>
<meta charset="utf-8">
<title><?= htmlspecialchars(($docNo !== '' ? $docNo . ' ' : '') . $docName) ?></title>
<!-- 內文排版與編輯器共用同一個檔：兩邊各寫一份的話換頁位置會對不起來
     （實測過：列印頁少了 p{margin:0 0 4px}，編輯器 12 頁列印卻變 23 頁） -->
<link rel="stylesheet" href="../../resource/css/eg_doc_page.css?v=<?= @filemtime(__DIR__.'/../../resource/css/eg_doc_page.css') ?>">
<?php /* 公版設定（表格字型與框線型式）只覆寫 CSS 變數，不動任何規則 */
      require_once __DIR__ . '/../../src/common/as_doc_tpl_lib.php'; ?>
<style><?= adt_style_css($db) ?></style>
<style>
@page {
    size: <?= $pageSize ?> <?= $orient ?>;
    margin: 16mm 15mm 18mm;   /* 下緣要多留：頁碼與 AS 編號改印在內容區最下面一行 */
}
/* 「第 X 頁／共 Y 頁」＋AS編號版次改成一般 HTML（見上方 $sheetFoot），不再用
   CSS 具名頁（page:revlog + @page revlog）去關：具名頁選取器在真實瀏覽器的
   列印預覽裡對 @bottom-left/@bottom-right 沒有作用（只有無頭 Chrome 產 PDF
   那條路徑吃得到，真人打開列印對話框看到的仍是沒關掉的版本——2026-09-24
   使用者實測回報過），故全面棄用，改由 PHP 直接算好要不要印、印什麼。 */
.adt-pgno { display: flex; justify-content: space-between; font-size: 9pt; color: #333; margin-top: 2mm; }
/* 留白一律交給 @page，body 不再自己加 padding——兩邊各留一次會把內容擠到中間又切邊 */
html, body { margin: 0; padding: 0; }
body { font-family: "微軟正黑體","Microsoft JhengHei",sans-serif; font-size: 12pt; color: #000; line-height: 1.6; }
/* 內文的字級、行高、段落與表格間距一律來自 eg_doc_page.css（.eg-docbody），
   這裡只加「列印特有」的規則，不可以再寫一份排版 */
.adt-page thead { display: table-header-group; }   /* 表頭跨頁重複 */
.adt-page tr { page-break-inside: avoid; }         /* 資料列不可被切成上下兩半 */
.adt-page hr[style*="page-break"] { display: none; }  /* 頁界標記本身不印（頁已經切開了） */
/* 一個 .adt-page ＝ 一張紙：高度固定成可印區、強制換頁，
   這樣編輯器上看到的一頁就是印出來的一頁（不會再出現「下方被切得亂七八糟」）。 */
.adt-page { page-break-after: always; break-after: page;
            display: flex; flex-direction: column; }
.adt-page:last-child { page-break-after: auto; break-after: auto; }
/* ⚠ 不可以用 overflow:hidden：內容比一頁高時（實測都是很長的表格）會被**裁掉看不見**，
   使用者在預覽上根本不知道有東西沒印到。改成讓那一頁自己長高，
   列印時本來就會依 page-break 規則跨頁，內容不會漏。 */
.adt-page > .adt-body { flex: 1 1 auto; overflow: visible; }
.err { padding: 40px; text-align: center; color: #A34E2A; font-size: 14pt; }
@media screen {
    body { background: #efe9e0; }
    /* 螢幕上也一頁一張紙，跟編輯器與實際列印一致 */
    /* min-height 而不是 height：比 A4 高的那幾頁（長表格）要看得到全部內容 */
    .adt-page { background: #fff; box-sizing: border-box;
        width: <?= $orient === 'landscape' ? ($pageSize === 'A3' ? '420mm' : '297mm') : ($pageSize === 'A3' ? '297mm' : '210mm') ?>;
        min-height: <?= $orient === 'landscape' ? ($pageSize === 'A3' ? '297mm' : '210mm') : ($pageSize === 'A3' ? '420mm' : '297mm') ?>;
        margin: 14px auto; padding: 16mm 15mm 18mm; box-shadow: 0 2px 8px rgba(0,0,0,.2); }
    .bar { position: fixed; top: 0; left: 0; right: 0; background: #faf6f0; border-bottom: 1px solid #e4d3ba;
           padding: 7px 14px; text-align: center; z-index: 9; }
    .bar button { border: 1px solid #d98a33; background: #F0A24B; color: #fff; border-radius: 4px;
                  height: 28px; padding: 0 14px; font-size: 13px; }
    .bar span { font-size: 12px; color: #8A5A2B; margin-left: 10px; }
    body { padding-top: 44px; }
}
@media print {
    .bar { display: none !important; }
    /* 列印時留白交給 @page，.adt-page 不再自己加內距（兩邊各留一次會把內容擠到中間又切邊）。
       ⚠ min-height 不可以留 0（原本是 0）：.adt-page 是 flex 直欄容器，
       .adt-body 靠 flex:1 撐滿剩餘高度才會讓公版大框（body_frame 開啟時）的外框
       延伸到頁底——但 flex-grow 要有「明確的容器高度」才算得出剩餘空間，
       min-height:0 等於沒有，容器只會縮到跟內容一樣高，內容比較短的那幾頁
       （使用者 2026-09-23 實測抓到：不含制修訂紀錄書的正文第 1 頁）外框就會
       中途停住、下面留一整片沒有框線的空白，跟其他頁對不齊。
       改成可印區的實際高度（紙張扣掉上 16／下 18mm）：內容比一頁短時撐滿，
       比一頁長時 min-height 不會限制它變得更高、照樣自然換頁不受影響。 */
    .adt-page { width: auto; height: auto; min-height: <?= $printContentH ?>mm; margin: 0; padding: 0; box-shadow: none; }
}
</style>
</head>
<body>
<?php if ($err !== ''): ?>
    <div class="err"><?= htmlspecialchars($err) ?></div>
<?php else: ?>
<div class="bar">
    <button onclick="doPrint()">列印</button>
    <span>紙張 <?= $pageSize ?> <?= $orient === 'landscape' ? '橫式' : '直式' ?>
        <?= $isPrimary ? '' : '（此版次的線上內容尚未設為正本，目前僅供預覽）' ?></span>
</div>
<?php /* 一個 .adt-page ＝ 一張紙。系統頁（封面／制修訂紀錄書／目錄）與正文頁的頁首頁尾
         全部由 as_doc_tpl_lib 產生，使用者只編正文。 */ ?>
<?php foreach ($pagesHtml as $one) { echo $one; } ?>
<script src="../../resource/js/eg_stamp.js?v=<?= @filemtime(__DIR__.'/../../resource/js/eg_stamp.js') ?>"></script>
<script src="../../resource/js/eg_doc_sign_stamp.js?v=<?= @filemtime(__DIR__.'/../../resource/js/eg_doc_sign_stamp.js') ?>"></script>
<script src="../../resource/js/eg_print_log.js?v=<?= @filemtime(__DIR__.'/../../resource/js/eg_print_log.js') ?>"></script>
<script>
function doPrint(){
    // 列印紀錄（ai-rules/23）：記的是「按下列印」這個動作，送出即忘不影響列印
    if (window.EGPrintLog) {
        EGPrintLog.record({
            source: 'as_doc_content', doc_kind: 'form',
            doc_name: <?= json_encode(($docNo !== '' ? $docNo . ' ' : '') . $docName . ($ver !== '' ? ' 版次' . $ver : ''), JSON_UNESCAPED_UNICODE) ?>,
            ref_table: 'as_doc_content', ref_id: <?= (int)($C['id'] ?? 0) ?>,
            note: <?= json_encode($isPrimary ? '' : '草稿（未設為正本）', JSON_UNESCAPED_UNICODE) ?>
        });
    }
    window.print();
}
// 圖片沒載完就叫 print() 會印出空白的圖框，所以等全部圖片就緒（含失敗的）才自動跳列印。
// 簽章要**先畫**再等圖片：掃描實體章本身也是圖，先畫才會被下面那一輪等到。
function startAutoPrint(){
    var imgs = Array.prototype.slice.call(document.querySelectorAll('.adt-page img'));
    var left = imgs.filter(function(i){ return !i.complete; }).length;
    function go(){ setTimeout(doPrint, 250); }
    if (!left) { go(); return; }
    imgs.forEach(function(i){
        if (i.complete) return;
        function done(){ if (--left <= 0) go(); }
        i.addEventListener('load', done);
        i.addEventListener('error', done);
    });
    // 保險：圖片一直載不完也不要卡住不列印
    setTimeout(function(){ if (left > 0) { left = 0; go(); } }, 6000);
}
if (window.egDocStamps) egDocStamps(document, startAutoPrint);
else startAutoPrint();
</script>
<?php endif; ?>
</body>
</html>
