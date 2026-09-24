<?php
/**
 * qa_abnormal_list_print.php — 品質異常處理單 首頁批次月報列印（按年月，一個月一份）
 * 建立：2026-09-24
 *
 * 使用者交辦：首頁列表要能依年月勾選（只有真的有資料的月份才給勾），批次列印當月全部單據的
 * 彙總清單；這份列印**不綁定 AS 文件編號**，標題由管理員在「設定 → 其他設定」自訂。
 * 大標題仍是本公司全名（動態取，禁寫死，同 ai-rules/16 第一之一節），但頁尾右下角不印 AS 編號
 * （因為沒有綁定），只有左下角「多頁才印」頁碼（同 ai-rules/16）。
 *
 * 一個月＝一份獨立的列印工作（自己的分頁與頁碼），多個月由清單頁逐份開視窗排隊
 * （比照母單自動列印子單同一套「批次排隊」做法，見 qa_abnormal_print.php 與 ai-rules/16 第三之五節）。
 * 排序：最舊日期在最上面（使用者要求）。隱藏開單人員；不印「操作」欄（本來就沒有）；
 * 印出總筆數／總檢驗數／總不良數／整體不良率。
 * 依 ai-rules/23：開啟本頁即留一筆列印紀錄（沿用既有來源代碼 qa_abnormal）。
 */
session_start();
if (!isset($_SESSION['id'])) {
    $_SESSION['lastpage'] = '/EGsystem/views/QA/qa_abnormal_list_print.php?' . $_SERVER['QUERY_STRING'];
    header('Location: /EGsystem/index.php');
    exit;
}
require_once __DIR__ . '/../../src/common/_config.php';
require_once __DIR__ . '/../../src/common/DBConnection.php';
require_once __DIR__ . '/../../src/common/qa_abnormal_lib.php';
require_once __DIR__ . '/../../src/common/org_role_lib.php';
require_once __DIR__ . '/../../src/common/date_fmt_lib.php';
require_once __DIR__ . '/../../src/common/print_log_lib.php';

$db = (new DBConnection())->getPDO();
qab_ensure_schema($db);
$uid   = (int)$_SESSION['id'];
$perms = qab_perms($db, $uid);
if (!$perms['canView']) { http_response_code(403); exit('沒有品質異常處理單的檢視權限'); }

$year  = (int)($_GET['year'] ?? 0);
$month = (int)($_GET['month'] ?? 0);
if ($year < 2000 || $year > 2100 || $month < 1 || $month > 12) exit('年月參數不正確');

function h($s) { return htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8'); }
function d($s) { $s = trim((string)$s); return $s === '' ? '' : eg_fmt_date($s); }

$rows = qab_list($db, ['year' => $year, 'month' => $month]);
$rows = array_reverse($rows);   // qab_list() 依日期新到舊，這裡要最舊在最上面

$company = eg_company_full_name($db);
$title   = qab_list_print_title($db);
$ymLabel = $year . '.' . str_pad((string)$month, 2, '0', STR_PAD_LEFT);

$totalInsp = 0; $totalNg = 0;
foreach ($rows as $r) { $totalInsp += (int)($r['insp_qty'] ?? 0); $totalNg += (int)($r['ng_qty'] ?? 0); }
$overallRate = $totalInsp > 0 ? number_format($totalNg / $totalInsp * 100, 2) . '%' : '－';

// 列印紀錄（ai-rules/23）——沿用既有來源代碼，doc_name 標明是哪個年月的彙總清單
try {
    eg_print_log_add($db, [
        'source'   => 'qa_abnormal',
        'doc_name' => $title . ' ' . $ymLabel,
        'note'     => 'list_print year=' . $year . ' month=' . $month . ' rows=' . count($rows),
    ]);
} catch (Throwable $e) {}
?>
<!DOCTYPE html>
<html lang="zh-Hant">
<head>
<meta charset="utf-8">
<title><?= h($title . ' ' . $ymLabel) ?></title>
<style>
@page { size:A4 landscape; margin:12mm 10mm 14mm; }
html,body { margin:0; padding:0; }
body { font-family:"Microsoft JhengHei","微軟正黑體",sans-serif; color:#000; font-size:11px;
       -webkit-print-color-adjust:exact; print-color-adjust:exact; }
.head { text-align:center; margin-bottom:6px; }
.head .co { font-size:18px; font-weight:bold; letter-spacing:2px; }
.head .en { font-size:8.5px; letter-spacing:.5px; }
.head .tt { font-size:14px; font-weight:bold; margin-top:4px; }
.head .tt .ym { color:#333; margin-right:10px; }
table.f { width:100%; border-collapse:collapse; table-layout:fixed; margin-top:4px; }
table.f th, table.f td { border:1px solid #000; padding:2px 4px; vertical-align:middle;
                         word-wrap:break-word; overflow-wrap:break-word; line-height:1.4; text-align:center; }
table.f thead th { background:#F3F3F3; font-weight:bold; }
table.f td.tl { text-align:left; }
table.f tfoot td { font-weight:bold; background:#FAFAFA; }
.noprint { text-align:center; padding:8px; }
@media print { .noprint { display:none !important; } }
</style>
</head>
<body>
<div class="noprint">
    <button onclick="window.print()">再列印一次</button>
    <button onclick="window.close()">關閉</button>
</div>

<div class="head">
    <div class="co"><?= h($company) ?></div>
    <div class="en">EXCELLENT GEAR TECHNOLOGY CO.,LTD</div>
    <div class="tt"><span class="ym"><?= h($ymLabel) ?></span><?= h($title) ?></div>
</div>

<table class="f">
    <colgroup>
        <col style="width:4%"><col style="width:11%"><col style="width:7%"><col style="width:11%">
        <col style="width:11%"><col style="width:11%"><col style="width:11%">
        <col style="width:6%"><col style="width:6%"><col style="width:7%">
        <col style="width:9%"><col style="width:6%">
    </colgroup>
    <thead>
        <tr>
            <th>序號</th><th>異常單號</th><th>填寫日期</th><th>客戶</th><th>料號</th>
            <th>製令／客退單</th><th>責任單位</th><th>檢驗數</th><th>不良數</th><th>不良率</th>
            <th>最終處置</th><th>狀態</th>
        </tr>
    </thead>
    <tbody>
    <?php if (!$rows): ?>
        <tr><td colspan="12">這個月份沒有資料</td></tr>
    <?php else: foreach ($rows as $i => $r):
        $iq = (int)($r['insp_qty'] ?? 0); $ng = (int)($r['ng_qty'] ?? 0);
        $rate = $iq > 0 ? number_format($ng / $iq * 100, 2) . '%' : '－';
    ?>
        <tr>
            <td><?= $i + 1 ?></td>
            <td class="tl"><?= h($r['abnormal_order_no']) ?></td>
            <td><?= h(d($r['fill_date'] ?: $r['occurrence_date'])) ?></td>
            <td class="tl"><?= h($r['client_name']) ?></td>
            <td class="tl"><?= h($r['part_no']) ?></td>
            <td class="tl"><?= h($r['bom_no'] ?: $r['ir_no']) ?></td>
            <td class="tl"><?= h($r['responsible_unit']) ?></td>
            <td><?= h($iq ?: '') ?></td>
            <td><?= h($ng ?: '') ?></td>
            <td><?= h($rate) ?></td>
            <td class="tl"><?= h($r['final_label']) ?></td>
            <td><?= h($r['status']['label'] ?? '') ?></td>
        </tr>
    <?php endforeach; endif; ?>
    </tbody>
    <?php if ($rows): ?>
    <tfoot>
        <tr>
            <td colspan="7">合計　總筆數 <?= count($rows) ?> 筆</td>
            <td><?= $totalInsp ?: '' ?></td>
            <td><?= $totalNg ?: '' ?></td>
            <td><?= h($overallRate) ?></td>
            <td colspan="2"></td>
        </tr>
    </tfoot>
    <?php endif; ?>
</table>

<script>
(function () {
    function go() {
        /* 只量會印出來的區塊，超過一頁才注入頁碼（同 qa_abnormal_print.php 既有做法） */
        var onePage = (210 - 26) * 96 / 25.4;   // A4 橫式扣邊界的可印高度(mm)換算px
        var printH = 0;
        [].forEach.call(document.body.children, function (t) {
            if (t.classList.contains('noprint') || t.tagName === 'SCRIPT') return;
            printH += t.getBoundingClientRect().height;
        });
        if (printH > onePage * 0.95) {
            var st = document.createElement('style');
            st.textContent = "@page{ @bottom-left{ content:'第 ' counter(page) ' 頁／共 ' counter(pages) ' 頁'; font-size:8.5pt; color:#333; } }";
            document.head.appendChild(st);
        }
        if (location.search.indexOf('auto=1') >= 0) {
            setTimeout(function () { window.print(); }, 250);
        }
    }
    if (document.readyState === 'complete') go(); else window.addEventListener('load', go);
})();
</script>
</body>
</html>
