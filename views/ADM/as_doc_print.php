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
    $total = count($sys) + count($conts);
    $pv    = adt_page_versions($ctx['versions'], count($conts), $ctx['version']);
    $no    = 0;
    foreach ($sys as $s) {
        $no++;
        // 系統頁不印頁首（它們自己就是完整版面），但頁尾照印
        $pagesHtml[] = '<section class="adt-page adt-page-' . $s['key'] . '">'
                     . '<div class="adt-body eg-docbody">' . $s['html'] . '</div>'
                     . adt_footer_html($ctx) . '</section>';
    }
    foreach ($conts as $i => $one) {
        $no++;
        $pagesHtml[] = '<section class="adt-page">'
                     . adt_header_html($ctx, $no, $total, $pv[$i] ?? $ctx['version'])
                     . '<div class="adt-body eg-docbody">' . $one . '</div>'
                     . adt_footer_html($ctx) . '</section>';
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
<style>
@page {
    size: <?= $pageSize ?> <?= $orient ?>;
    margin: 16mm 15mm 18mm;   /* 下緣要多留：頁碼與 AS 編號印在下方頁邊區 */
    @bottom-left  { content: "第 " counter(page) " 頁／共 " counter(pages) " 頁"; font-size: 9pt; color: #333; }
    @bottom-right { content: "<?= htmlspecialchars($footRight, ENT_QUOTES) ?>"; font-size: 9pt; color: #333; }
}
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
    /* 列印時留白交給 @page，.adt-page 不再自己加內距（兩邊各留一次會把內容擠到中間又切邊） */
    .adt-page { width: auto; height: auto; min-height: 0; margin: 0; padding: 0; box-shadow: none; }
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
// 圖片沒載完就叫 print() 會印出空白的圖框，所以等全部圖片就緒（含失敗的）才自動跳列印
(function(){
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
})();
</script>
<?php endif; ?>
</body>
</html>
