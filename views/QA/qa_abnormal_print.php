<?php
/**
 * qa_abnormal_print.php — 品質異常處理單 列印版（照紙本 2-QA-01-01_vC）
 * 建立：2026-09-18
 *
 * 版面完全照 Excel 的區塊順序與欄位：
 *   表頭(單號/客戶/填寫日期 → 製令/客退單號/責任單位 → 料號/批量/檢驗數/不良數/不良率)
 *   → 量測尺寸與實測值 1~12（三列）→ 異常原因分類 → 異常現象＋(業務/品管)承辦
 *   → 異常處置方式＋(業務/品管)主管 → 處置說明 → 相關單位意見(僅勾選者需回覆)
 *   → 總經理裁示＋矯正單號 → 扣款確認(製程/其他/合計、核准扣款金額、報廢單號、三格簽章)
 *
 * 依 ai-rules/16：公司全名動態取（禁寫死）、表頭取綁定 AS 文件的 doc_name、
 *                 AS 編號右下角且版次依業務日期(填寫日期)回推、頁碼左下且多頁才印。
 * 依 ai-rules/18：簽章一律走 eg_stamp.js 帶日期印章，日期＝該格的業務日期。
 * 依 ai-rules/23：開啟本頁即留一筆列印紀錄。
 */
session_start();
if (!isset($_SESSION['id'])) {
    $_SESSION['lastpage'] = '/EGsystem/views/QA/qa_abnormal_print.php?id=' . (int)($_GET['id'] ?? 0);
    header('Location: /EGsystem/index.php');
    exit;
}
require_once __DIR__ . '/../../src/common/_config.php';
require_once __DIR__ . '/../../src/common/DBConnection.php';
require_once __DIR__ . '/../../src/common/qa_abnormal_lib.php';
require_once __DIR__ . '/../../src/common/asdoc_lib.php';
require_once __DIR__ . '/../../src/common/org_role_lib.php';
require_once __DIR__ . '/../../src/common/date_fmt_lib.php';
require_once __DIR__ . '/../../src/common/print_log_lib.php';

$db = (new DBConnection())->getPDO();
qab_ensure_schema($db);
$uid   = (int)$_SESSION['id'];
$perms = qab_perms($db, $uid);
$id = (int)($_GET['id'] ?? 0);
if (!$perms['canView'] && !qab_can_view_order($db, $perms, $id)) {
    http_response_code(403);
    exit('沒有這張品質異常單的檢視權限');
}
$o  = qab_order($db, $id);
if (!$o) exit('找不到這張異常單');

$company  = eg_company_full_name($db);
$asDoc    = eg_asdoc_get($db, QAB_ASDOC_MODULE);
$bizDate  = (string)($o['fill_date'] ?: $o['occurrence_date'] ?: substr((string)$o['created_at'], 0, 10));
$asNo     = $asDoc ? eg_asdoc_no_asof_id($db, (int)$asDoc['id'], $bizDate) : '';
$formName = $asDoc && trim((string)$asDoc['doc_name']) !== '' ? (string)$asDoc['doc_name'] : '品質異常處理單';

// 列印紀錄（ai-rules/23）
try {
    eg_print_log_add($db, [
        'source'   => 'qa_abnormal',
        'doc_name' => $formName . ' ' . $o['abnormal_order_no'],
        'part_no'  => (string)($o['part_no'] ?? ''),
        'note'     => 'id=' . $id,
    ]);
} catch (Throwable $e) {}

$stampTpl    = qab_stamp_tpl($db);            // 一般簽章用的圖章模板（設定 → 其他設定）
$stampTplAsk = qab_stamp_tpl($db, 'ask');     // 相關單位意見那五格可以另外指定（例：長方章）
$causeMap  = qab_cause_map($db);
$dispOpts  = qab_options($db, 'disp', false);
$gmOpts    = qab_options($db, 'gm', false);
$rate      = (float)($o['surcharge_rate'] ?: 1);
$tot       = $o['deduct_totals'];

function h($s) { return htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8'); }
function d($s) { $s = trim((string)$s); return $s === '' ? '' : eg_fmt_date($s); }
/** 勾選框：紙本上是一個小方框，勾到就打勾 */
function cb($label, $on) { return '<span class="cb"><i>' . ($on ? '✔' : '') . '</i>' . h($label) . '</span>'; }
function money($v) { return ($v === null || $v === '') ? '' : number_format((float)$v, 0); }

// 第一層原因分類（紙本是一排勾選框）＋ 實際選到的完整路徑
$lv1 = [];
foreach ($causeMap as $c) if ($c['parent_id'] === null) $lv1[] = $c;
usort($lv1, function ($a, $b) { return $a['sort_order'] <=> $b['sort_order']; });
$selRoots = [];  // 有被選到的第一層
$paths    = [];
foreach ($o['cause_ids'] as $cid) {
    if (!isset($causeMap[$cid])) continue;
    $paths[] = $causeMap[$cid]['path'];
    $cur = $cid; $guard = 0;
    while (isset($causeMap[$cur]) && $guard++ < 10) {
        if ($causeMap[$cur]['parent_id'] === null) { $selRoots[$cur] = 1; break; }
        $cur = $causeMap[$cur]['parent_id'];
    }
}
$deepPaths = array_values(array_filter($paths, function ($p) { return strpos($p, '→') !== false; }));

// 相關單位意見：已回覆的逐格列出，不足 4 格補空白格（維持表單樣子）
/* 相關單位意見：紙本是**固定五格**（發生單位／技術／生管·採購／品保／業務），
   不是線上勾幾個部門就印幾格——所以把已回覆的輪次依「部門→紙本欄位」對應歸位。
   對應不到任何一格的部門（例：董事長室）不會印在紙本上，畫面上仍然看得到。 */
$slotMap  = qab_dept_slot_map($db);
$slotData = [];                       // slot => ['content'=>, 'who'=>, 'date'=>, 'on'=>bool]
$unmapped = [];
foreach ($o['rounds'] as $r) {
    if (($r['status'] ?? '') !== 'Returned') continue;
    $k = $slotMap[(int)$r['dept_id']] ?? '';
    if ($k === '' || !isset(qab_ask_slots()[$k])) { $unmapped[] = (string)$r['department_name']; continue; }
    if (!isset($slotData[$k])) $slotData[$k] = ['content' => [], 'who' => '', 'date' => '', 'dept' => '', 'pos' => '', 'on' => true];
    $txt = trim((string)$r['reply_content']);
    if ($txt !== '') $slotData[$k]['content'][] = $txt;
    if ($slotData[$k]['who'] === '') {
        $slotData[$k]['who']  = trim((string)($r['replied_name'] ?: $r['user_cname'] ?? ''));
        $slotData[$k]['date'] = (string)$r['return_date'];
        // 圖章模板若有 {部門}{職稱} token，要用「回覆當天」的職務（ai-rules/22）
        $pi = qab_person_asof($db, (int)($r['replied_by'] ?: $r['user_id']), substr((string)$r['return_date'], 0, 10));
        $slotData[$k]['dept'] = $pi['dept'];
        $slotData[$k]['pos']  = $pi['position'];
    }
}
/** 一格的內容（勾選框＋回覆文字＋簽章） */
function askCell(array $slotData, array $keys, $h, $d) {
    $labels = qab_ask_slots();
    $box = '';
    foreach ($keys as $k) {
        $on = !empty($slotData[$k]['on']);
        $box .= '<span class="cb"><i>' . ($on ? '✔' : '') . '</i>' . $h($labels[$k]['label']) . '</span>';
    }
    $txt = []; $who = ''; $date = ''; $dept = ''; $pos = '';
    foreach ($keys as $k) {
        if (empty($slotData[$k])) continue;
        foreach ($slotData[$k]['content'] as $c) $txt[] = $c;
        if ($who === '') {
            $who  = $slotData[$k]['who'];  $date = $slotData[$k]['date'];
            $dept = $slotData[$k]['dept'] ?? ''; $pos = $slotData[$k]['pos'] ?? '';
        }
    }
    return [$box, nl2br($h(implode("\n", $txt))), $who, $date, $dept, $pos];
}

// 扣款明細：製程列彙總成一列（紙本只有「製程／其他／合計」三列），其他列逐筆印
$procRows = []; $otherRows = [];
foreach ($o['deducts'] as $dd) {
    if (($dd['kind'] ?? 'process') === 'other') $otherRows[] = $dd; else $procRows[] = $dd;
}
$procDesc = $o['deduct_desc'];
if ($rate != 1 && $tot['process'] > 0) $procDesc .= '（金額 ' . money($tot['process']) . ' × 加成 ' . rtrim(rtrim(number_format($rate, 3, '.', ''), '0'), '.') . '）';
/* 製程說明會把每一站都列出來，站數一多就會撐高整列（紙本只有一列的高度）。
   文字一長就自動降字級，配合儲存格本來就有的 word-wrap 自動換行。 */
$descLen   = mb_strlen($procDesc, 'UTF-8');
$descStyle = $descLen > 150 ? 'font-size:7.5px;line-height:1.25;'
           : ($descLen > 90 ? 'font-size:8.5px;line-height:1.3;'
           : ($descLen > 50 ? 'font-size:9.5px;line-height:1.35;' : ''));
/* 紙本左下角本來就固定有「扣款確認」這一塊，沒勾扣款時是留白的表格。
   依條件整塊不印會讓同一份表單每次印出來高度差很多（使用者回報），所以一律印。 */
$showDeduct = true;
?>
<!DOCTYPE html>
<html lang="zh-Hant">
<head>
<meta charset="utf-8">
<title><?= h($formName . ' ' . $o['abnormal_order_no']) ?></title>
<style>
@page { size:A4 portrait; margin:10mm 9mm 12mm; }
@page { @bottom-right { content:"<?= h($asNo) ?>"; font-size:8.5pt; color:#333; } }
html,body { margin:0; padding:0; }
body { font-family:"Microsoft JhengHei","微軟正黑體",sans-serif; color:#000; font-size:11px;
       -webkit-print-color-adjust:exact; print-color-adjust:exact; }
.head { text-align:center; margin-bottom:4px; }
.head .co { font-size:19px; font-weight:bold; letter-spacing:2px; }
.head .en { font-size:9px; letter-spacing:.5px; }
.head .tt { font-size:16px; font-weight:bold; letter-spacing:6px; margin-top:2px; }
table.f { width:100%; border-collapse:collapse; table-layout:fixed; }
table.f th, table.f td { border:1px solid #000; padding:1px 4px; vertical-align:middle;
                         word-wrap:break-word; overflow-wrap:break-word; line-height:1.45; }
table.f td.lb { text-align:center; font-weight:bold; background:#F3F3F3; }
table.f td.c { text-align:center; }
/* 勾選框那幾列（異常原因分類／異常處置方式）高度要一致，紙本上這兩列本來就一樣高 */
table.f td.optrow { height:8mm; }
table.f td.t { vertical-align:top; }
.f + .f { border-top:0; }
.cb { display:inline-flex; align-items:center; gap:3px; margin:0 10px 0 0; white-space:nowrap; vertical-align:middle; }
/* 方框用 inline-flex 置中，有打勾與沒打勾的框大小與基線完全一樣
   （原本靠 vertical-align 微調，勾號一進去就把那一格的行高撐開、看起來沒對齊） */
.cb i { flex:0 0 auto; display:inline-flex; align-items:center; justify-content:center;
        width:11px; height:11px; box-sizing:border-box; border:1px solid #000;
        font-style:normal; font-size:9px; line-height:1; }
.vert { writing-mode:vertical-rl; text-orientation:upright; letter-spacing:4px; }
.sig { text-align:center; }
/* 標題貼著圖章放在左邊（原本自己佔一整列，圖章被擠到下面去、整張表就變高）：
   「(業務/品管) 主管：」會自動折成兩行，字級縮小，跟圖章維持在同一個高度。 */
.sig .cap { float:left; width:46px; font-size:7.5px; line-height:1.15; text-align:left; color:#000; }
.sig .cap b { font-weight:normal; display:block; white-space:nowrap; }   /* 「(業務/品管)」整串不折行 */
.sigbox { display:flex; align-items:center; justify-content:center; }
/* 長方章比格子寬時要整個縮進來，不可以被裁掉（使用者回報章顯示不完整） */
.sigbox svg { max-width:100%; }
/* 簽章格與它左邊的內容格之間不畫線——有線的話章看起來像獨立的一欄，分不出是誰簽的。
   border-collapse 之下相鄰的兩條邊會合併，所以兩邊都要拿掉（只拿掉一邊仍然看得到線）。 */
/* 特異度要壓得過上面的 `table.f th, table.f td{border:1px}`，不然寫了沒有作用 */
table.f td.nobr { border-right:0; }
table.f td.nobt { border-top:0; }        /* 裁示說明與矯正單號之間不畫線（紙本是連著的一格） */
table.f tr.signrow td { height:15mm; }   /* 蓋章框：沒有人簽時也要看得到框、留得下章 */
table.f td.sig { vertical-align:top; }   /* 簽章標題靠左上（使用者要求），不跟著儲存格垂直置中 */
table.f td.sig.nobl { border-left:0; }
.note { font-size:10px; margin-top:3px; }
.small { font-size:10px; color:#333; }
.mem td { height:15px; }
table.ask td { height:12mm; }
table.ask .askbd { font-size:10px; line-height:1.35; }
/* 只拿掉「內容格 → 它的簽章格」之間那一條；最右邊那一格是表格邊界，一定要留著（使用者回報右側線不見了）。
   注意 `table.ask` 本身就是 `table.f.ask`，寫成 `table.f table.ask` 永遠match不到（踩過一次）。 */
table.f.ask td.sig { border-left:0; }
table.ask td.askbody { border-right:0; }
/* 這一區的章用長方章（格子矮）。簽章欄要夠寬、標題不要浮在左邊佔掉章的位置，
   章一律靠左放滿——不然 100px 的長方章塞在扣掉標題後剩下的幾十 px 裡一定顯示不完整。 */
table.ask td.sig { padding:1px 3px; }
table.ask td.sig .cap { float:none; width:auto; font-size:7.5px; line-height:1.2; margin-bottom:1px; }
table.ask .sigbox { justify-content:flex-start; max-height:100%; }
table.ask .sigbox svg { max-width:100%; max-height:12mm; width:auto; height:auto; }
/* 圖章尺寸一律抄 ai-rules/18 鐵則6 這一行，不要自己另外發明數字 */
.stamp-wrap svg, svg.car-stamp { width:91px; height:91px; -webkit-print-color-adjust:exact; print-color-adjust:exact; }
svg.eg-stamp-tpl { height:auto !important; }
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
    <div class="tt"><?= h($formName) ?></div>
</div>

<?php if ($o['split_parent']): ?>
<div class="note" style="text-align:center;margin-bottom:3px;">
    本單為 <b><?= h($o['split_parent']['no']) ?></b> 依決策拆分出的子單，異常現象／原因分類／相關單位意見請見原始單。
</div>
<?php elseif ($o['split_children']): ?>
<div class="note" style="text-align:center;margin-bottom:3px;">
    本單已依決策拆分為 <?= count($o['split_children']) ?> 張子單：<?= h(implode('、', array_map(
        function ($c) { return $c['no'] . '（' . $c['name'] . ' ' . $c['ng_qty'] . 'pcs' . ($c['deduct_qty'] !== null ? '，扣款' . $c['deduct_qty'] . 'pcs' : '') . '）'; },
        $o['split_children']
    ))) ?>　各子單獨立結案，隨本單一併列印。
</div>
<?php endif; ?>

<!-- 表頭 -->
<table class="f">
    <colgroup><col style="width:13%"><col style="width:22%"><col style="width:13%"><col style="width:19%"><col style="width:13%"><col style="width:20%"></colgroup>
    <tr>
        <td class="lb">異常單號</td><td class="c"><?= h($o['abnormal_order_no']) ?></td>
        <td class="lb">客戶</td><td class="c"><?= h($o['client_name']) ?></td>
        <td class="lb">填寫日期</td><td class="c"><?= h(d($o['fill_date'])) ?></td>
    </tr>
    <tr>
        <td class="lb">製令編號</td><td class="c"><?= h($o['bom_no']) ?></td>
        <td class="lb">客退單號(IR)</td><td class="c"><?= h($o['ir_no']) ?></td>
        <td class="lb">責任單位</td><td class="c"><?= h($o['responsible_unit']) ?></td>
    </tr>
</table>
<table class="f">
    <colgroup><col style="width:13%"><col style="width:22%"><col style="width:9%"><col style="width:12%"><col style="width:10%"><col style="width:12%"><col style="width:10%"><col style="width:12%"></colgroup>
    <tr>
        <td class="lb">料號</td><td class="c"><?= h($o['part_no']) ?></td>
        <td class="lb">批量</td><td class="c"><?= h($o['batch_qty']) ?></td>
        <td class="lb">檢驗數</td><td class="c"><?= h($o['insp_qty']) ?></td>
        <td class="lb">不良數</td>
        <td class="c"><?= h($o['ng_qty']) ?><?php
            $iq = (float)$o['insp_qty']; $ng = (float)$o['ng_qty'];
            if ($iq > 0) echo '　<span class="small">(' . number_format($ng / $iq * 100, 2) . '%)</span>';
        ?></td>
    </tr>
</table>

<!-- 量測尺寸與實測值 -->
<table class="f mem">
    <colgroup><col style="width:16%"><?php for ($i = 0; $i < 12; $i++) echo '<col style="width:7%">'; ?></colgroup>
    <tr>
        <td class="lb" rowspan="2">量測尺寸</td>
        <td class="lb" colspan="12">實 測 值</td>
    </tr>
    <tr><?php for ($i = 1; $i <= 12; $i++) echo '<td class="c lb">' . $i . '</td>'; ?></tr>
    <?php for ($r = 0; $r < 3; $r++):
        $m = $o['measures'][$r] ?? null; ?>
    <tr>
        <td class="c"><?= h($m['dim_name'] ?? '') ?></td>
        <?php for ($i = 0; $i < 12; $i++): ?>
        <td class="c"><?= h($m['vals'][$i] ?? '') ?></td>
        <?php endfor; ?>
    </tr>
    <?php endfor; ?>
</table>

<!-- 異常原因分類＋異常現象，右邊一個合併的承辦簽章欄（比照紙本 M17:O21） -->
<table class="f">
    <colgroup><col style="width:16%"><col><col style="width:22%"></colgroup>
    <tr>
        <td class="lb">異常原因分類</td>
        <td class="optrow">
            <?php foreach ($lv1 as $c) echo cb($c['name'], isset($selRoots[$c['cat_id']])); ?>
            <?php if ($deepPaths): ?><div class="small" style="margin-top:2px;">選定：<?= h(implode('；', $deepPaths)) ?></div><?php endif; ?>
        </td>
        <td class="sig" rowspan="2">
            <div class="cap"><b>(業務/品管)</b><b>承辦：</b></div>
            <div class="sigbox" data-stamp="<?= h($o['owner_sign_name']) ?>" data-dept="<?= h($o['signs']['owner']['dept'] ?? '') ?>" data-pos="<?= h($o['signs']['owner']['position'] ?? '') ?>" data-date="<?= h(d($o['owner_sign_at'] ?: $o['fill_date'])) ?>"></div>
        </td>
    </tr>
    <tr>
        <td class="lb">異 常 現 象</td>
        <td class="t" style="height:16mm;"><?= nl2br(h($o['abnormal_phenomenon'])) ?>
            <?php if (trim((string)$o['defect_detail']) !== ''): ?>
            <div class="small" style="margin-top:4px;">原因分析：<?= nl2br(h($o['defect_detail'])) ?></div>
            <?php endif; ?>
            <?php if (trim((string)$o['qa_ps']) !== ''): ?>
            <div class="small" style="margin-top:4px;">品管備註：<?= nl2br(h($o['qa_ps'])) ?></div>
            <?php endif; ?>
        </td>
    </tr>
</table>

<!-- 異常處置方式＋處置說明，右邊一個合併的主管簽章欄（比照紙本 M22:O26） -->
<table class="f">
    <colgroup><col style="width:16%"><col><col style="width:22%"></colgroup>
    <tr>
        <td class="lb">異常處置方式</td>
        <td class="optrow"><?php foreach ($dispOpts as $op) echo cb($op['name'], in_array($op['opt_id'], $o['disp_ids'], true)); ?></td>
        <td class="sig" rowspan="2">
            <div class="cap"><b>(業務/品管)</b><b>主管：</b></div>
            <div class="sigbox" data-stamp="<?= h($o['decided_name']) ?>" data-dept="<?= h($o['signs']['disp']['dept'] ?? '') ?>" data-pos="<?= h($o['signs']['disp']['position'] ?? '') ?>" data-date="<?= h(d($o['disp_decided_at'])) ?>"></div>
        </td>
    </tr>
    <tr>
        <td class="lb">處 置 說 明</td>
        <td class="t" style="height:16mm;"><?= nl2br(h($o['disposition_note'])) ?></td>
    </tr>
</table>

<!-- 相關單位意見（紙本固定五格） -->
<table class="f">
    <tr><td class="lb">相 關 單 位 意 見　<span class="small" style="font-weight:normal;">(僅勾選者 需回覆)</span></td></tr>
</table>
<table class="f ask">
    <colgroup><col style="width:29%"><col style="width:21%"><col style="width:29%"><col style="width:21%"></colgroup>
    <?php
    $cells = [
        [['occur'], ['tech']],
        [['pm', 'purchase'], ['qa']],
    ];
    foreach ($cells as $row): ?>
    <tr>
        <?php foreach ($row as $keys):
            [$box, $txt, $who, $dt, $sdept, $spos] = askCell($slotData, $keys, 'h', 'd'); ?>
        <td class="t askbody"><?= $box ?><div class="askbd"><?= $txt ?></div></td>
        <td class="sig t"><div class="cap"><b>簽章：</b></div>
            <div class="sigbox" style="min-height:0;" data-tpl="ask" data-stamp="<?= h($who) ?>"
                 data-dept="<?= h($sdept) ?>" data-pos="<?= h($spos) ?>" data-date="<?= h(d($dt)) ?>"></div></td>
        <?php endforeach; ?>
    </tr>
    <?php endforeach; ?>
    <tr>
        <?php [$box, $txt, $who, $dt, $sdept, $spos] = askCell($slotData, ['sales'], 'h', 'd'); ?>
        <td class="t askbody" colspan="3"><?= $box ?><div class="askbd"><?= $txt ?></div></td>
        <td class="sig t"><div class="cap"><b>簽章：</b></div>
            <div class="sigbox" style="min-height:0;" data-tpl="ask" data-stamp="<?= h($who) ?>"
                 data-dept="<?= h($sdept) ?>" data-pos="<?= h($spos) ?>" data-date="<?= h(d($dt)) ?>"></div></td>
    </tr>
</table>

<!-- 總經理裁示 -->
<table class="f">
    <colgroup><col style="width:16%"><col><col style="width:26%"></colgroup>
    <tr>
        <td class="lb" rowspan="3">總經理 裁示</td>
        <td><?php
            foreach ($gmOpts as $op) echo cb($op['name'], in_array($op['opt_id'], $o['gm_ids'], true));
            echo cb('扣款', !empty($o['gm_deduct']));
        ?></td>
        <td class="sig" rowspan="3">
            <div class="cap"><b>總經理</b><b>簽章：</b></div>
            <div class="sigbox" data-stamp="<?= h($o['gm_name']) ?>" data-dept="<?= h($o['signs']['gm']['dept'] ?? '') ?>" data-pos="<?= h($o['signs']['gm']['position'] ?? '') ?>" data-date="<?= h(d($o['gm_decided_at'])) ?>"
                 data-deputy="<?= !empty($o['gm_by_deputy']) ? 1 : '' ?>"></div>
        </td>
    </tr>
    <tr><td class="t nobt" style="height:11mm;"><?= nl2br(h($o['gm_note'])) ?></td></tr>
    <tr><td class="nobt">矯正單號：<?= h($o['capa_order_no']) ?></td></tr>
</table>

<?php if ($showDeduct): ?>
<!-- 扣款確認 -->
<table class="f">
    <colgroup><col style="width:6%"><col style="width:12%"><col style="width:12%"><col style="width:30%"><col style="width:12%"><col style="width:28%"></colgroup>
    <tr>
        <td class="lb" rowspan="<?= 3 + max(1, count($otherRows)) ?>"><div class="vert">扣款確認</div></td>
        <td class="lb">扣款項目</td><td class="lb">金額 (未稅)</td><td class="lb">說明</td>
        <td class="lb">執 行</td>
        <td class="lb">通知扣款<div class="small" style="font-weight:normal;">(填移轉單號 Exp:J-1130101001)</div></td>
    </tr>
    <tr>
        <td class="c">製程</td>
        <td class="c"><?= h(money($tot['process_rated'])) ?></td>
        <td class="t small" style="<?= $descStyle ?>"><?= h($procDesc) ?></td>
        <?php $dRows = 2 + max(1, count($otherRows)); /* 製程 + 其他(至少一列) + 合計 */ ?>
        <td class="c" rowspan="<?= $dRows ?>"><?= h($o['deduct_exec']) ?></td>
        <td class="t" rowspan="<?= $dRows ?>"><?= nl2br(h($o['deduct_notify_no'])) ?></td>
    </tr>
    <?php foreach ($otherRows as $orow): ?>
    <tr>
        <td class="c">其他<?= $orow['process_name'] ? '（' . h($orow['process_name']) . '）' : '' ?></td>
        <td class="c"><?= h(money($orow['included'] ? $orow['amount'] : null)) ?></td>
        <td class="t small"><?= h($orow['note']) ?></td>
    </tr>
    <?php endforeach; ?>
    <?php if (!$otherRows): ?>
    <tr><td class="c">其他</td><td class="c"></td><td></td></tr>
    <?php endif; ?>
    <tr>
        <td class="c lb">合計</td>
        <td class="c"><b><?= h(money($tot['total'])) ?></b></td>
        <td class="c">數量　<?= h($o['deduct_qty'] === null ? '' : rtrim(rtrim(number_format((float)$o['deduct_qty'], 3, '.', ''), '0'), '.')) ?>　PCS</td>
    </tr>
</table>
<table class="f">
    <colgroup><col style="width:6%"><col style="width:18%"><col style="width:14%"><col style="width:16%"><col style="width:18%"><col style="width:28%"></colgroup>
    <tr>
        <td class="lb" rowspan="2"><div class="vert">核准</div></td>
        <td class="lb">核准 扣款 金額 (元/PCS)</td>
        <td class="c"><?= h($o['deduct_unit_amt'] === null ? '' : money($o['deduct_unit_amt'])) ?></td>
        <td class="lb">報廢單號</td>
        <td class="c" colspan="2"><b><?= h($o['scrap_no']) ?></b></td>
    </tr>
    <tr>
        <td class="small" colspan="5">★此單號需登記至不合格品管制記錄表　
            ★扣款確認表金額由生管填寫　★核准扣款金額由管理課 會計/主管 填寫</td>
    </tr>
</table>
<!-- 三個簽章並排：各佔一列的話，三個 91px 圖章就吃掉三分之一頁、整張表會變兩頁。
     沒有人簽時也要留出框線與書寫高度（使用者要求：下方要有圖章框框） -->
<table class="f">
    <colgroup><col style="width:33.4%"><col style="width:33.3%"><col style="width:33.3%"></colgroup>
    <tr>
        <td class="lb">核准 (管理課 會計/主管)</td>
        <td class="lb">(生管) 簽章</td>
        <td class="lb">(品管) 簽章</td>
    </tr>
    <tr class="signrow">
        <td class="sig"><div class="sigbox" data-stamp="<?= h($o['deduct_appr_name']) ?>" data-dept="<?= h($o['signs']['appr']['dept'] ?? '') ?>" data-pos="<?= h($o['signs']['appr']['position'] ?? '') ?>" data-date="<?= h(d($o['deduct_appr_at'])) ?>"></div></td>
        <td class="sig"><div class="sigbox" data-stamp="<?= h($o['deduct_pm_name']) ?>" data-dept="<?= h($o['signs']['pm']['dept'] ?? '') ?>" data-pos="<?= h($o['signs']['pm']['position'] ?? '') ?>" data-date="<?= h(d($o['deduct_pm_at'])) ?>"></div></td>
        <td class="sig"><div class="sigbox" data-stamp="<?= h($o['deduct_qc_name']) ?>" data-dept="<?= h($o['signs']['qc']['dept'] ?? '') ?>" data-pos="<?= h($o['signs']['qc']['position'] ?? '') ?>" data-date="<?= h(d($o['deduct_qc_at'])) ?>"></div></td>
    </tr>
</table>
<?php endif; ?>

<script src="../../resource/js/jquery.min.js"></script>
<script>
// 圖章上緣的公司全名來自這個全域變數（ai-rules/18），沒設定就印不出公司名（使用者回報）
window.__ownCompany = <?= json_encode($company, JSON_UNESCAPED_UNICODE) ?>;
var STAMP_TPL     = <?= json_encode($stampTpl['schema'] ?? null, JSON_UNESCAPED_UNICODE) ?>;
var STAMP_TPL_ASK = <?= json_encode($stampTplAsk['schema'] ?? ($stampTpl['schema'] ?? null), JSON_UNESCAPED_UNICODE) ?>;
var SPLIT_CHILDREN = <?= json_encode($o['split_children'], JSON_UNESCAPED_UNICODE) ?>;
</script>
<script src="../../resource/js/eg_stamp.js?v=<?= @filemtime(__DIR__ . '/../../resource/js/eg_stamp.js') ?>"></script>
<!-- 有指定圖章模板時這一支一定要一起載，漏載會靜默退回預設章（ai-rules/18 第11條） -->
<script src="../../resource/js/eg_stamp_tpl.js?v=<?= @filemtime(__DIR__ . '/../../resource/js/eg_stamp_tpl.js') ?>"></script>
<script>
(function () {
    function draw() {
        var boxes = document.querySelectorAll('.sigbox');
        for (var i = 0; i < boxes.length; i++) {
            var b = boxes[i], nm = (b.getAttribute('data-stamp') || '').trim();
            if (!nm) continue;                       // 沒有人簽的格子留白給現場手簽
            var dt = (b.getAttribute('data-date') || '').trim();
            // 代理人代簽的章右下角要加「代」字（ai-rules/18）
            var dep = (b.getAttribute('data-deputy') || '') === '1';
            // 模板若有 {部門}{職稱} token，要用「簽章當時」的部門職稱（ai-rules/22）
            var dpt = b.getAttribute('data-dept') || '', pos = b.getAttribute('data-pos') || '';
            var tpl = b.getAttribute('data-tpl') === 'ask' ? STAMP_TPL_ASK : STAMP_TPL;
            try { b.innerHTML = EGStamp.stamp(nm, dt, dep, tpl, dpt, pos); } catch (e) {}
        }
        /* 超過一頁時在畫面上（不印出來）講清楚原因與解法。
           **刻意不做整頁自動縮放**——ai-rules/18 第8條：整頁縮放與「圖章維持固定尺寸」天生互斥，
           試過三輪都不如預期，正確解法是把圖章改成長方章。 */
        var onePage = (297 - 22) * 96 / 25.4;
        /* 只量「會印出來的」區塊——畫面上的工具列與這個提示本身是 .noprint，
           把它們算進去會變成每張單都說會印兩頁（自己踩過） */
        var printH = 0;
        [].forEach.call(document.body.children, function (t) {
            if (t.classList.contains('noprint') || t.tagName === 'SCRIPT') return;
            printH += t.getBoundingClientRect().height;
        });
        if (printH > onePage + 2) {
            var w = document.createElement('div');
            w.className = 'noprint';
            w.style.cssText = 'margin:6px auto;max-width:760px;border:1px solid #DD5138;background:#FFF3EC;'
                + 'color:#8a3b1e;padding:8px 12px;border-radius:4px;font-size:13px;line-height:1.6;text-align:left;';
            w.innerHTML = '<b>這張單目前會印成兩頁</b>（內容 ' + Math.round(printH)
                + 'px，一頁可印 ' + Math.round(onePage) + 'px）。<br>'
                + '紙本一頁上有 9 個簽章格，<b>圓章依規定固定 91px（約 2.4cm）不縮小</b>，蓋滿就會超過一頁。<br>'
                + '請到清單頁「設定 → 其他設定 → 列印用圖章模板」改選<b>長方章</b>（例：人員簽章(長方)），'
                + '或單獨指定「相關單位意見的圖章模板」。';
            document.body.insertBefore(w, document.body.firstChild);
        }
        if (printH > onePage * 0.95) {
            var st = document.createElement('style');
            st.textContent = "@page{ @bottom-left{ content:'第 ' counter(page) ' 頁／共 ' counter(pages) ' 頁'; font-size:8.5pt; color:#333; } }";
            document.head.appendChild(st);
        }
        /* 從清單或處理頁按「列印」進來的，直接跳出列印預覽，不要再按一次（使用者要求）。
           等頁面資源載完再叫 print()，否則圖章或字型還沒就位就先排版，印出來會跑掉。 */
        if (location.search.indexOf('auto=1') >= 0) {
            var go = function () { setTimeout(function () { window.print(); }, 250); };
            if (document.readyState === 'complete') go(); else window.addEventListener('load', go);
        }
        /* 這張是「已拆分」的母單：自動一併列印子單（子單本身仍可用自己的 id 獨立列印，
           子單沒有 SPLIT_CHILDREN 所以不會再往下鏈）——逐張各自開視窗排隊，錯開 700ms
           避免被彈出視窗封鎖擋掉（同 ai-rules/16 第三之五節既有批次列印做法）。
           帶 nocascade=1 進來的（子單自己被批次帶出來印時）不要再觸發一次。 */
        if (SPLIT_CHILDREN.length && location.search.indexOf('nocascade=1') < 0) {
            SPLIT_CHILDREN.forEach(function (c, i) {
                setTimeout(function () {
                    window.open('qa_abnormal_print.php?id=' + c.id + '&auto=1&nocascade=1', '_blank');
                }, (i + 1) * 700);
            });
        }
    }
    // 掃描實體章對照表是非同步載入的，沒等它就會印成預設章、跟畫面上看到的不一樣
    if (window.EGStamp && EGStamp.whenReady) EGStamp.whenReady(draw); else window.onload = draw;
})();
</script>
</body>
</html>
