<?php
/**
 * sop_sip_print.php — SOP／SIP 列印版
 * 建立：2026-09-21
 *
 * 依 ai-rules/16：
 *   大標題＝本公司全名（動態取自客戶主檔標記本公司那一筆，禁寫死）
 *   表頭＝綁定 AS 文件的表單名稱（doc_name），不寫死
 *   頁碼左下 counter(pages)，**多頁才印**；AS 編號右下，**版次依表單日期回推當時生效的版次**
 * 依 ai-rules/18：簽章一律走 eg_stamp.js 帶日期圖章，日期＝該格的簽章日期，圖章不縮小
 * 依 ai-rules/23：留列印紀錄
 *
 * 版面：A3 橫式（使用者指定比照 part_process_report.php 的 A3 報告）。
 * 白邊一律由 .sheet 自己的 padding 給，**@page 的 margin 固定 0**，兩邊各留一次會把內容擠到中間又切邊。
 */
session_start();
if (!isset($_SESSION['userName'])) { header("Location:../../index.php"); exit; }
include_once '../../src/common/_config.php';
include_once '../../src/common/DBConnection.php';
include_once '../../src/common/sopsip_lib.php';
include_once '../../src/common/print_log_lib.php';
include_once '../../src/common/date_fmt_lib.php';   // 日期顯示一律 YYYY.MM.DD（ai-rules/20）

$db = (new DBConnection())->getPDO();
ss_ensure_schema($db);
$uid = (int)($_SESSION['id'] ?? 0);
$P   = ss_perms($db, $uid);

$verId = (int)($_GET['ver_id'] ?? 0);
$F = ss_ver_full($db, $verId);
if (!$F) { exit('找不到這個版次'); }
if (!ss_perm_for_kind($P, $F['kind'], 'view')) { http_response_code(403); exit('沒有檢視權限'); }

$doc = $F['doc']; $ver = $F['ver']; $kind = $F['kind'];
$formDate = (string)($ver['form_date'] ?? '');
$company  = ss_company_name($db);
$asNo     = ss_as_no($db, $kind, (int)($ver['as_doc_id'] ?? 0), $formDate);
$formName = ss_as_title($db, $kind, (int)($ver['as_doc_id'] ?? 0));
$vers     = ss_ver_rows($db, (int)$doc['doc_id']);
$machine  = $F['machine'];

try {
    eg_print_log_add($db, [
        'source'   => 'sop_sip',
        'doc_name' => $formName . ' ' . (string)$doc['title'] . ' 版次' . (string)$ver['ver_no'],
        'part_no'  => (string)($doc['part_no_text'] ?? ''),
        'note'     => 'ver_id=' . $verId,
    ]);
} catch (Throwable $e) {}

/** 圖面／步驟圖的網址（列印視窗載得到，權限與主頁同一套） */
function pf(int $id): string { return 'sopsip_file.php?id=' . $id; }
function h($s): string { return htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8'); }
/** 一行一條的文字欄位 → <li> */
function lines($s): array {
    $out = [];
    foreach (preg_split("/\r\n|\r|\n/", (string)$s) as $l) { $l = trim($l); if ($l !== '') $out[] = $l; }
    return $out;
}
$SLOTS = ss_slots();
$stampTpl = [];
foreach (array_keys($SLOTS) as $k) $stampTpl[$k] = ss_stamp_tpl($db, $k);

/* 機台：一份文件綁一個型號、底下涵蓋好幾台機器編號（使用者 2026-09-21 二次拍板） */
$mMeta   = $F['machine_meta'] ?? [];
$assetTx = (string)($mMeta['asset_text'] ?? ($machine['asset_no'] ?? ''));

/* 段落附件：使用者拍板「接在該段文字下方」，而且**縮圖不可以太小**，所以一排只放 3 張、每張 82mm */
$secFiles = $F['section_files'] ?? [];
function secImgs(array $secFiles, string $key): string {
    $list = $secFiles[$key] ?? [];
    if (!$list) return '';
    $h = '<div class="secimgs">';
    foreach ($list as $f) {
        $h .= '<div class="si"><img src="' . pf((int)$f['file_id']) . '">'
            . '<div class="cap">' . h((string)($f['orig_name'] ?? '')) . '</div></div>';
    }
    return $h . '</div>';
}
?>
<!DOCTYPE html>
<html lang="zh-Hant">
<head>
<meta charset="utf-8">
<title><?= h($formName) ?> <?= h($doc['title']) ?></title>
<style>
    /* 白邊由 .sheet 自己給，@page 固定 0（兩邊各留一次＝內容被擠到中間又切邊） */
    @page { size: A3 landscape; margin: 0; }
    @page {
        @bottom-left  { content: "第 " counter(page) " 頁／共 " counter(pages) " 頁";
                        font-size: 9pt; color:#555; margin-left: 14mm; margin-bottom: 6mm; }
        @bottom-right { content: "<?= h($asNo) ?>"; font-size: 9pt; color:#555; margin-right: 14mm; margin-bottom: 6mm; }
    }
    html, body { margin:0; padding:0; background:#EDE7DE; }
    body { font-family:"Microsoft JhengHei","微軟正黑體",sans-serif; color:#222; font-size:11pt; }
    .sheet { width:420mm; min-height:297mm; box-sizing:border-box; padding:12mm 14mm 16mm;
             background:#fff; margin:0 auto; }
    @media screen { .sheet { box-shadow:0 4px 18px rgba(0,0,0,.2); margin:12px auto; } }
    .toolbar { text-align:center; padding:10px; }
    .toolbar button { font-size:14px; padding:6px 20px; border:1px solid #C77C1A; background:#F0A24B;
                      color:#4A3524; border-radius:4px; cursor:pointer; font-weight:bold; }
    @media print { .toolbar { display:none !important; } html, body { background:#fff; } }

    h1.co { text-align:center; font-size:17pt; margin:0 0 2mm; letter-spacing:2px; }
    h2.fm { text-align:center; font-size:14pt; margin:0 0 4mm; letter-spacing:4px; font-weight:normal; }
    table { border-collapse:collapse; width:100%; }
    td, th { border:1px solid #000; padding:1.4mm 2mm; font-size:10.5pt; vertical-align:top; }
    th { background:#F2F2F2; text-align:center; font-weight:normal; }
    .lab { background:#F7F7F7; text-align:center; width:26mm; white-space:nowrap; }
    .mid { text-align:center; }
    .blk { margin-top:3mm; }
    .blk ol, .blk ul { margin:0; padding-left:6mm; }
    .blk li { line-height:1.75; }
    .imgcell { text-align:center; }
    .imgcell img { max-width:62mm; max-height:42mm; }
    .drawbox { text-align:center; }
    .drawbox img { max-width:100%; max-height:150mm; }
    /* 圖章一律不縮小（ai-rules/18）；列印新視窗拿不到 eg_stamp.js 注入的 CSS，樣式要自己寫齊 */
    .sg { height:26mm; text-align:center; vertical-align:middle; }
    .sg .eg-stamp, .sg svg { display:inline-block; }
    .sg .eg-stamp-tpl { height:auto !important; }
    .sgname { font-size:9pt; color:#444; }
    /* 段落附件圖：使用者交代「縮圖不可過小」，所以一排 3 張、每張 82mm 寬、最高 62mm */
    .secimgs { display:flex; flex-wrap:wrap; gap:3mm; margin-top:2mm; }
    .secimgs .si { width:82mm; text-align:center; page-break-inside:avoid; }
    .secimgs .si img { max-width:82mm; max-height:62mm; border:1px solid #bbb; }
    .secimgs .cap { font-size:8pt; color:#555; word-break:break-all; line-height:1.3; }
</style>
</head>
<body>
<div class="toolbar"><button onclick="window.print()">列印</button>　<span style="font-size:12px;color:#5b3a1e;">A3 橫式</span></div>

<div class="sheet">
    <h1 class="co"><?= h($company) ?></h1>
    <h2 class="fm"><?= h($formName) ?></h2>

<?php if ($kind === 'equip'): ?>
    <table>
        <tr>
            <td class="lab">機器編號</td><td><?= h($assetTx) ?></td>
            <td class="lab">機器製造商</td><td><?= h($ver['m_maker']) ?></td>
        </tr>
        <tr>
            <td class="lab">機器名稱</td><td><?= h($ver['m_name'] ?: $doc['title']) ?></td>
            <td class="lab">型式規格</td><td><?= h($ver['m_spec'] ?: ($doc['machine_model'] ?? '')) ?></td>
        </tr>
        <tr>
            <td class="lab">加工適用範圍</td><td colspan="3"><?= h($ver['m_range']) ?></td>
        </tr>
        <tr>
            <td class="lab">操作方法</td>
            <td colspan="3"><ol><?php foreach (lines($ver['op_method']) as $l): ?><li><?= h($l) ?></li><?php endforeach; ?></ol>
                <?= secImgs($secFiles, 'op_method') ?></td>
        </tr>
        <tr>
            <td class="lab">使用注意事項</td>
            <td colspan="3"><ol><?php foreach (lines($ver['cautions']) as $l): ?><li><?= h($l) ?></li><?php endforeach; ?></ol>
                <?= secImgs($secFiles, 'cautions') ?></td>
        </tr>
        <tr>
            <td class="lab">保養維修要點</td>
            <td colspan="3"><ol><?php foreach (lines($ver['maintain']) as $l): ?><li><?= h($l) ?></li><?php endforeach; ?></ol>
                <?= secImgs($secFiles, 'maintain') ?></td>
        </tr>
    </table>

<?php elseif ($kind === 'process'): ?>
    <table>
        <tr>
            <td class="lab">製程名稱</td><td><?= h($doc['title']) ?></td>
            <td class="lab">使用設備</td><td><?= h($ver['use_equip']) ?></td>
            <td class="lab">預計工時</td><td><?= h($ver['est_hours']) ?></td>
            <td class="lab">版本</td><td class="mid"><?= h($ver['ver_no']) ?></td>
        </tr>
        <tr>
            <td class="lab">適用料號</td><td><?= h($doc['part_no_text'] ?: '通用') ?></td>
            <td class="lab">制定日期</td><td class="mid"><?= h(eg_fmt_date($formDate)) ?></td>
            <td class="lab">製程</td><td><?= h($doc['proc_name']) ?></td>
            <td class="lab">客戶</td><td><?= h($doc['customer_name'] ?? '') ?></td>
        </tr>
    </table>
    <?php if ($x = secImgs($secFiles, 'use_equip')): ?>
    <table class="blk"><tr><td class="lab" style="width:30mm;">使用設備說明</td><td><?= $x ?></td></tr></table>
    <?php endif; ?>
    <table class="blk">
        <thead><tr>
            <th style="width:14mm;">項次</th><th style="width:34mm;">名稱</th><th style="width:70mm;">參考圖示</th>
            <th>操作步驟</th><th style="width:80mm;">說明</th>
        </tr></thead>
        <tbody>
        <?php foreach ($F['steps'] as $i => $s): ?>
            <tr>
                <td class="mid"><?= (int)$s['seq'] ?></td>
                <td class="mid"><?= h($s['step_name']) ?></td>
                <td class="imgcell"><?php if ((int)$s['img_file_id']): ?>
                    <img src="<?= pf((int)$s['img_file_id']) ?>"><?php endif; ?></td>
                <td><?php foreach (lines($s['step_text']) as $l): ?><div><?= h($l) ?></div><?php endforeach; ?></td>
                <td><?= nl2br(h($s['note'])) ?></td>
            </tr>
        <?php endforeach; ?>
        </tbody>
    </table>

<?php else: ?>
    <table>
        <tr>
            <!-- 使用者 2026-09-21 指定：不需要製令單號與數量；「工程名稱」就是製程，只留一欄 -->
            <td class="lab">客戶名稱</td><td><?= h($doc['customer_name'] ?: ($ver['customer_name'] ?? '')) ?></td>
            <td class="lab">產品料號</td><td><?= h($doc['part_no_text'] ?: '通用') ?></td>
            <td class="lab">工程名稱</td><td><?= h($doc['proc_name']) ?></td>
            <td class="lab">版次</td><td class="mid"><?= h($ver['ver_no']) ?></td>
            <td class="lab">製表日期</td><td class="mid"><?= h(eg_fmt_date($formDate)) ?></td>
        </tr>
    </table>
    <table class="blk">
        <thead><tr>
            <th style="width:120mm;">圖面</th>
            <th style="width:46mm;">管理重點</th><th style="width:46mm;">品質特性</th>
            <th style="width:22mm;">上限</th><th style="width:22mm;">下限</th>
            <th style="width:20mm;">擔當者</th><th style="width:34mm;">檢驗方法</th>
            <th style="width:24mm;">檢具編號</th><th style="width:30mm;">檢驗頻率</th><th>備註</th>
        </tr></thead>
        <tbody>
        <?php $items = $F['items']; $n = max(1, count($items)); ?>
        <?php foreach ($items as $i => $it): ?>
            <tr>
                <?php if ($i === 0): ?>
                    <td rowspan="<?= $n ?>" class="drawbox">
                        <?php if ((int)$ver['draw_file_id']): ?><img src="<?= pf((int)$ver['draw_file_id']) ?>"><?php endif; ?>
                    </td>
                <?php endif; ?>
                <td><?= h($it['ctrl_point']) ?></td>
                <td><?= h($it['q_char']) ?></td>
                <td class="mid"><?= h($it['up_limit']) ?></td>
                <td class="mid"><?= h($it['lo_limit']) ?></td>
                <td class="mid"><?= h($it['owner_label'] ?? $it['owner']) ?></td>
                <td><?= h($it['method']) ?></td>
                <td class="mid"><?= h($it['tool_no']) ?></td>
                <td><?= h($it['freq']) ?></td>
                <td><?= h($it['note']) ?></td>
            </tr>
        <?php endforeach; ?>
        </tbody>
    </table>
    <?php $nt = lines($ver['notice']); $ni = secImgs($secFiles, 'notice'); if ($nt || $ni): ?>
    <table class="blk"><tr>
        <td class="lab" style="width:30mm;">注意事項</td>
        <td><?php foreach ($nt as $l): ?><div><?= h($l) ?></div><?php endforeach; ?><?= $ni ?></td>
    </tr></table>
    <?php endif; ?>
<?php endif; ?>

    <!-- 修訂履歷／修改記錄：不另外手打，由各版次組出來 -->
    <table class="blk">
        <thead><tr><th colspan="5"><?= $kind === 'sip' ? '修改記錄' : '修訂履歷' ?></th></tr>
        <tr><th style="width:20mm;">版次</th><th style="width:34mm;">日期</th><th>制/修訂事項</th>
            <th style="width:40mm;">制/修訂</th><th style="width:34mm;">狀態</th></tr></thead>
        <tbody>
        <?php foreach (array_reverse($vers) as $v): ?>
            <?php $sg = ss_sign_map($db, (int)$v['ver_id']); ?>
            <tr>
                <td class="mid"><?= h($v['ver_no']) ?></td>
                <td class="mid"><?= h(eg_fmt_date($v['form_date'])) ?></td>
                <td><?= h($v['rev_note']) ?></td>
                <td class="mid"><?= h($sg['maker']['user_name'] ?? '') ?></td>
                <td class="mid"><?= h(ss_statuses()[$v['status']] ?? $v['status']) ?></td>
            </tr>
        <?php endforeach; ?>
        </tbody>
    </table>

    <!-- 簽章：製表 → 審核 → 核准（使用者拍板三關統一） -->
    <table class="blk">
        <thead><tr>
            <?php foreach ($SLOTS as $k => $def): ?><th style="width:33.3%;"><?= h($def['label']) ?></th><?php endforeach; ?>
        </tr></thead>
        <tbody><tr>
            <?php foreach ($SLOTS as $k => $def): $s = $F['signs'][$k] ?? null; ?>
                <td class="sg" data-slot="<?= h($k) ?>"
                    data-name="<?= h($s['user_name'] ?? '') ?>"
                    data-date="<?= h($s['sign_date'] ?? '') ?>"
                    data-dept="<?= h($s['dept_name'] ?? '') ?>"
                    data-pos="<?= h($s['position_name'] ?? '') ?>"
                    data-deputy="<?= (int)($s['by_deputy'] ?? 0) ? 1 : 0 ?>"></td>
            <?php endforeach; ?>
        </tr></tbody>
    </table>
</div>

<script src="../../resource/js/eg_date_fmt.js?v=<?= @filemtime(__DIR__ . '/../../resource/js/eg_date_fmt.js') ?>"></script>
<script src="../../resource/js/eg_stamp_tpl.js?v=<?= @filemtime(__DIR__ . '/../../resource/js/eg_stamp_tpl.js') ?>"></script>
<script src="../../resource/js/eg_stamp.js?v=<?= @filemtime(__DIR__ . '/../../resource/js/eg_stamp.js') ?>"></script>
<script>
window.__ownCompany = <?= json_encode($company, JSON_UNESCAPED_UNICODE) ?>;
var SS_TPL = <?= json_encode(array_map(function ($t) {
    return $t ? json_decode((string)$t['schema_json'], true) : null;
}, $stampTpl), JSON_UNESCAPED_UNICODE) ?>;
(function () {
    function draw() {
        var cells = document.querySelectorAll('td.sg');
        for (var i = 0; i < cells.length; i++) {
            var c = cells[i], nm = c.getAttribute('data-name') || '';
            if (!nm) continue;
            var dt = window.egFmtDate ? egFmtDate(c.getAttribute('data-date')) : (c.getAttribute('data-date') || '');
            var tpl = SS_TPL[c.getAttribute('data-slot')] || null;
            try {
                c.innerHTML = EGStamp.stamp(nm, dt, c.getAttribute('data-deputy') === '1', tpl,
                                            c.getAttribute('data-dept') || '', c.getAttribute('data-pos') || '');
            } catch (e) {
                c.innerHTML = '<span class="sgname">' + nm + '　' + dt + '</span>';
            }
        }
    }
    // 掃描實體章的對照表是非同步載入的，沒等它就會蓋出跟畫面不一樣的預設章
    if (window.EGStamp && EGStamp.whenReady) EGStamp.whenReady(draw); else draw();
})();
</script>
</body>
</html>
