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
if (!$perms['canView']) { http_response_code(403); exit('沒有品質異常單的檢視權限'); }

$id = (int)($_GET['id'] ?? 0);
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
$rounds = [];
foreach ($o['rounds'] as $r) if (($r['status'] ?? '') === 'Returned') $rounds[] = $r;
$slot = max(4, (int)ceil(count($rounds) / 2) * 2);

// 扣款明細：製程列彙總成一列（紙本只有「製程／其他／合計」三列），其他列逐筆印
$procRows = []; $otherRows = [];
foreach ($o['deducts'] as $dd) {
    if (($dd['kind'] ?? 'process') === 'other') $otherRows[] = $dd; else $procRows[] = $dd;
}
$procDesc = $o['deduct_desc'];
if ($rate != 1 && $tot['process'] > 0) $procDesc .= '（金額 ' . money($tot['process']) . ' × 加成 ' . rtrim(rtrim(number_format($rate, 3, '.', ''), '0'), '.') . '）';
$showDeduct = !empty($o['gm_deduct']) || !empty($o['final']['is_scrap']) || $o['deducts'] || $o['scrap_no'];
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
table.f th, table.f td { border:1px solid #000; padding:2px 4px; vertical-align:middle;
                         word-wrap:break-word; overflow-wrap:break-word; line-height:1.45; }
table.f td.lb { text-align:center; font-weight:bold; background:#F3F3F3; }
table.f td.c { text-align:center; }
table.f td.t { vertical-align:top; }
.f + .f { border-top:0; }
.cb { display:inline-block; margin:0 10px 0 0; white-space:nowrap; }
.cb i { display:inline-block; width:11px; height:11px; border:1px solid #000; margin-right:3px;
        font-style:normal; font-size:10px; line-height:10px; text-align:center; vertical-align:-1px; }
.vert { writing-mode:vertical-rl; text-orientation:upright; letter-spacing:4px; }
.sig { text-align:center; min-height:56px; }
.sig .cap { font-size:10px; text-align:left; }
.sigbox { display:flex; align-items:center; justify-content:center; min-height:52px; }
.note { font-size:10px; margin-top:3px; }
.small { font-size:10px; color:#333; }
.mem td { height:15px; }
/* 圖章尺寸一律抄 ai-rules/18 鐵則6 這一行，不要自己另外發明數字 */
.stamp-wrap svg, svg.car-stamp { width:91px; height:91px; -webkit-print-color-adjust:exact; print-color-adjust:exact; }
svg.eg-stamp-tpl { height:auto !important; }
.noprint { text-align:center; padding:8px; }
@media print { .noprint { display:none !important; } }
</style>
</head>
<body>
<div class="noprint">
    <button onclick="window.print()">列印</button>
    <button onclick="window.close()">關閉</button>
</div>

<div class="head">
    <div class="co"><?= h($company) ?></div>
    <div class="en">EXCELLENT GEAR TECHNOLOGY CO.,LTD</div>
    <div class="tt"><?= h($formName) ?></div>
</div>

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

<!-- 異常原因分類 -->
<table class="f">
    <colgroup><col style="width:16%"><col></colgroup>
    <tr>
        <td class="lb">異常原因分類</td>
        <td>
            <?php foreach ($lv1 as $c) echo cb($c['name'], isset($selRoots[$c['cat_id']])); ?>
            <?php if ($deepPaths): ?><div class="small" style="margin-top:2px;">選定：<?= h(implode('；', $deepPaths)) ?></div><?php endif; ?>
        </td>
    </tr>
</table>

<!-- 異常現象 ＋ 承辦簽章 -->
<table class="f">
    <colgroup><col style="width:16%"><col><col style="width:26%"></colgroup>
    <tr>
        <td class="lb">異 常 現 象</td>
        <td class="t" style="height:26mm;"><?= nl2br(h($o['abnormal_phenomenon'])) ?>
            <?php if (trim((string)$o['defect_detail']) !== ''): ?>
            <div class="small" style="margin-top:4px;">原因分析：<?= nl2br(h($o['defect_detail'])) ?></div>
            <?php endif; ?>
            <?php if (trim((string)$o['qa_ps']) !== ''): ?>
            <div class="small" style="margin-top:4px;">品管備註：<?= nl2br(h($o['qa_ps'])) ?></div>
            <?php endif; ?>
        </td>
        <td class="sig">
            <div class="cap">(業務/品管) 承辦：</div>
            <div class="sigbox" data-stamp="<?= h($o['owner_sign_name']) ?>" data-date="<?= h(d($o['owner_sign_at'] ?: $o['fill_date'])) ?>"></div>
        </td>
    </tr>
</table>

<!-- 異常處置方式 ＋ 主管簽章 -->
<table class="f">
    <colgroup><col style="width:16%"><col><col style="width:26%"></colgroup>
    <tr>
        <td class="lb">異常處置方式</td>
        <td><?php foreach ($dispOpts as $op) echo cb($op['name'], in_array($op['opt_id'], $o['disp_ids'], true)); ?></td>
        <td class="sig" rowspan="2">
            <div class="cap">(業務/品管) 主管：</div>
            <div class="sigbox" data-stamp="<?= h($o['decided_name']) ?>" data-date="<?= h(d($o['disp_decided_at'])) ?>"></div>
        </td>
    </tr>
    <tr>
        <td class="lb">處 置 說 明</td>
        <td class="t" style="height:20mm;"><?= nl2br(h($o['disposition_note'])) ?></td>
    </tr>
</table>

<!-- 相關單位意見 -->
<table class="f">
    <tr><td class="lb">相 關 單 位 意 見　<span class="small" style="font-weight:normal;">(僅勾選者 需回覆)</span></td></tr>
</table>
<table class="f">
    <colgroup><col style="width:12%"><col style="width:38%"><col style="width:12%"><col style="width:38%"></colgroup>
    <?php for ($i = 0; $i < $slot; $i += 2): ?>
    <tr>
        <?php for ($k = 0; $k < 2; $k++):
            $r = $rounds[$i + $k] ?? null;
            $unit = $r ? trim((string)($r['department_name'] ?? '')) : '';
            $who  = $r ? trim((string)($r['replied_name'] ?: $r['user_cname'] ?? '')) : '';
        ?>
        <td class="c"><?= h($unit) ?></td>
        <td class="t" style="height:17mm; position:relative;">
            <?= nl2br(h($r['reply_content'] ?? '')) ?>
            <?php if ($r): ?>
            <div style="display:flex;justify-content:flex-end;align-items:flex-end;">
                <div class="sigbox" style="min-height:0;" data-stamp="<?= h($who) ?>" data-date="<?= h(d($r['return_date'])) ?>" data-small="1"></div>
            </div>
            <?php endif; ?>
        </td>
        <?php endfor; ?>
    </tr>
    <?php endfor; ?>
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
            <div class="cap">簽章：</div>
            <div class="sigbox" data-stamp="<?= h($o['gm_name']) ?>" data-date="<?= h(d($o['gm_decided_at'])) ?>"></div>
        </td>
    </tr>
    <tr><td class="t" style="height:16mm;"><?= nl2br(h($o['gm_note'])) ?></td></tr>
    <tr><td>矯正單號：<?= h($o['capa_order_no']) ?></td></tr>
</table>

<?php if ($showDeduct): ?>
<!-- 扣款確認 -->
<table class="f">
    <colgroup><col style="width:6%"><col style="width:12%"><col style="width:12%"><col style="width:30%"><col style="width:12%"><col style="width:28%"></colgroup>
    <tr>
        <td class="lb" rowspan="<?= 4 + count($otherRows) ?>"><div class="vert">扣款確認</div></td>
        <td class="lb">扣款項目</td><td class="lb">金額 (未稅)</td><td class="lb">說明</td>
        <td class="lb">執 行</td>
        <td class="lb">通知扣款<div class="small" style="font-weight:normal;">(填移轉單號 Exp:J-1130101001)</div></td>
    </tr>
    <tr>
        <td class="c">製程</td>
        <td class="c"><?= h(money($tot['process_rated'])) ?></td>
        <td class="t small"><?= h($procDesc) ?></td>
        <td class="c" rowspan="<?= 2 + count($otherRows) ?>"><?= h($o['deduct_exec']) ?></td>
        <td class="t" rowspan="<?= 2 + count($otherRows) ?>"><?= nl2br(h($o['deduct_notify_no'])) ?></td>
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
    <colgroup><col style="width:6%"><col style="width:12%"><col style="width:12%"><col style="width:22%"><col style="width:20%"><col style="width:28%"></colgroup>
    <tr>
        <td class="lb" rowspan="2"><div class="vert">核准</div></td>
        <td class="lb">核准 扣款 金額</td>
        <td class="c">元/PCS</td>
        <td class="c"><?= h($o['deduct_unit_amt'] === null ? '' : money($o['deduct_unit_amt'])) ?></td>
        <td class="lb">核准 (管理課 會計/主管)</td>
        <td class="sig">
            <div class="sigbox" data-stamp="<?= h($o['deduct_appr_name']) ?>" data-date="<?= h(d($o['deduct_appr_at'])) ?>"></div>
        </td>
    </tr>
    <tr>
        <td class="lb">報廢單號</td>
        <td class="c" colspan="2"><b><?= h($o['scrap_no']) ?></b>
            <span class="small"><?= $o['scrap_no'] ? '' : '（不需另外開立報廢單）' ?></span></td>
        <td class="lb">(生管) 簽章</td>
        <td class="sig">
            <div class="sigbox" data-stamp="<?= h($o['deduct_pm_name']) ?>" data-date="<?= h(d($o['deduct_pm_at'])) ?>"></div>
        </td>
    </tr>
</table>
<table class="f">
    <colgroup><col style="width:52%"><col style="width:20%"><col style="width:28%"></colgroup>
    <tr>
        <td class="small">★此單號需登記至不合格品管制記錄表<br>
            ★扣款確認表金額由生管填寫　★核准扣款金額由管理課 會計/主管 填寫</td>
        <td class="lb">(品管) 簽章</td>
        <td class="sig">
            <div class="sigbox" data-stamp="<?= h($o['deduct_qc_name']) ?>" data-date="<?= h(d($o['deduct_qc_at'])) ?>"></div>
        </td>
    </tr>
</table>
<?php endif; ?>

<script src="../../resource/js/jquery.min.js"></script>
<script src="../../resource/js/eg_stamp.js?v=<?= @filemtime(__DIR__ . '/../../resource/js/eg_stamp.js') ?>"></script>
<script>
(function () {
    function draw() {
        var boxes = document.querySelectorAll('.sigbox');
        for (var i = 0; i < boxes.length; i++) {
            var b = boxes[i], nm = (b.getAttribute('data-stamp') || '').trim();
            if (!nm) continue;                       // 沒有人簽的格子留白給現場手簽
            var dt = (b.getAttribute('data-date') || '').trim();
            try { b.innerHTML = EGStamp.stamp(nm, dt, false); } catch (e) {}
        }
        // 多頁才印頁碼（ai-rules/16：左下 counter(pages)）
        var onePage = (297 - 22) * 96 / 25.4;
        if (document.body.scrollHeight > onePage * 0.95) {
            var st = document.createElement('style');
            st.textContent = "@page{ @bottom-left{ content:'第 ' counter(page) ' 頁／共 ' counter(pages) ' 頁'; font-size:8.5pt; color:#333; } }";
            document.head.appendChild(st);
        }
        if (location.search.indexOf('auto=1') >= 0) setTimeout(function () { window.print(); }, 350);
    }
    // 掃描實體章對照表是非同步載入的，沒等它就會印成預設章、跟畫面上看到的不一樣
    if (window.EGStamp && EGStamp.whenReady) EGStamp.whenReady(draw); else window.onload = draw;
})();
</script>
</body>
</html>
