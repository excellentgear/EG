<?php
/**
 * sop_sip_print.php — SOP／SIP 列印版
 * 建立：2026-09-21　｜　2026-09-22 依使用者回報大改
 *
 * 依 ai-rules/16：
 *   大標題＝本公司全名（動態取自客戶主檔標記本公司那一筆，禁寫死）
 *   表頭＝綁定 AS 文件的表單名稱（doc_name），不寫死
 *   頁碼左下、AS 編號右下，**版次依表單日期回推當時生效的版次**
 * 依 ai-rules/18：簽章一律走 eg_stamp.js 帶日期圖章，日期＝該格的簽章日期，圖章不縮小
 * 依 ai-rules/23：留列印紀錄
 *
 * ── 2026-09-22 三件關鍵修正（使用者回報）
 * ① **頁尾不可以用 `@page { @bottom-right { content } }`**：Chrome 完全不支援 margin box，
 *    寫了是靜默不印——所以 AS 編號與頁碼從來沒有印出來過。改用 position:fixed 的頁尾，
 *    Chrome 會把 fixed 元素印在每一頁上。（@page 的 size/margin 本身是支援的，保留。）
 * ② 紙張大小與方向改成可設定：綁機台／量具＝A4 直式，料號相關＝A3 橫式（左邊要放圖面）。
 * ③ 開起來就直接跳列印預覽、印完自動關掉，不要再多按一次「列印」。
 *    **一定要等圖章畫完、圖片載完才 print()**，不然會印出沒有章、沒有圖的紙。
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
$layout = (string)($F['layout'] ?? 'std');
$formDate = (string)($ver['form_date'] ?? '');
/* 發行日期＝所選圖面那張料號附件的發行章日期（ai-rules/15）。取不到就印「—」，
   **不可以退回印製表日期**，也不可以把「尚未登錄發行章日期」這種內部提示印到紙上
   （列印版是給稽核看的正式紀錄，ai-rules/23 的口徑）。 */
$issueDate = (string)($F['issue']['date'] ?? '');

$company  = ss_company_name($db);
$asNo     = ss_as_no($db, $kind, (int)($ver['as_doc_id'] ?? 0), $formDate);
$formName = ss_as_title($db, $kind, (int)($ver['as_doc_id'] ?? 0));
/* 表頭仍然取綁定 AS 文件的表單名稱（ai-rules/16 禁寫死），但後面補上 SOP／SIP
   ——現場講的是「SOP」，只印「製造製程說明書」看不出是哪一種（使用者 2026-09-22 指定）。
   名稱裡本來就有 SOP／SIP 字樣的就不重複加。 */
$tabCode = strtoupper((string)(ss_kinds()[$kind]['tab'] ?? ''));
if ($tabCode !== '' && stripos($formName, $tabCode) === false) $formName .= '　' . $tabCode;
$vers     = ss_ver_rows($db, (int)$doc['doc_id']);
$machine  = $F['machine'];
$mMeta    = $F['machine_meta'] ?? [];
$assetTx  = (string)($mMeta['asset_text'] ?? ($machine['asset_no'] ?? ''));

/* 紙張：預設由 ss_paper() 決定（機台／量具 A4 直式、料號相關 A3 橫式），
   網址帶 ?paper=／?orient= 可以臨時改這一次（設定頁是「預先設定好」的那一份）。 */
$paper = ss_paper($db, $doc, $ver);
$qs = strtoupper((string)($_GET['paper'] ?? ''));
$qo = strtolower((string)($_GET['orient'] ?? ''));
if (isset(ss_papers()[$qs]))  $paper['size']   = $qs;
if (isset(ss_orients()[$qo])) $paper['orient'] = $qo;
[$sheetW, $sheetH] = ss_paper_mm($paper);
$pageCss = strtolower($paper['size']) . ' ' . $paper['orient'];

/* ── gsop 的欄寬（使用者 2026-09-30 指定的對齊）──
   「要點」的右緣要和表頭「版次」欄的右緣切齊。兩張表一樣寬，所以**只要讓右半邊的
   備註欄寬度等於表頭的「發行日期＋製表日期」兩格**，兩條線自然對齊，不必去算左欄寬度。 */
$gsContentW = $sheetW - 24;        // .sheet 左右各 12mm 白邊
$gsLeftW  = $paper['orient'] === 'landscape' ? 150 : 96;   // 左半邊（圖面＋檢驗項目）
$gsTtlW   = 40;    // 表頭最左邊「標準作業流程SOP」那一格
$gsVerW   = 16;    // 版次
$gsIssueW = 26;    // 發行日期
$gsDateW  = 26;    // 製表日期
$gsNoteW  = $gsIssueW + $gsDateW;   // 右半邊「備註」欄＝上面那兩格加起來
$gsNameW  = 12;    // 軟體/硬體步驟那一欄（使用者指定縮成一半，原本 24mm）
/* 表頭中間四欄（加工機種／客戶名稱／產品料號／工程名稱）分掉剩下的寬度。
   **每一欄都要給明確寬度、而且加總剛好等於表寬**——只要留一欄 auto，
   table-layout:fixed 就會自己去分配剩餘寬度，指定的 mm 會被拉大，
   兩張表的欄位線就對不起來（實測 26mm 被畫成 27.13mm，兩邊差了 6.4mm）。 */
$gsMidTotal = $gsContentW - $gsTtlW - $gsVerW - $gsIssueW - $gsDateW;
$gsMidW = [];
$gsMidRatio = [7, 6.5, 12, 10.5];   // 機種／客戶／料號／工程
$gsMidSum = array_sum($gsMidRatio);
foreach ($gsMidRatio as $r) $gsMidW[] = round($gsMidTotal * $r / $gsMidSum, 2);
$gsMidW[3] = round($gsMidTotal - $gsMidW[0] - $gsMidW[1] - $gsMidW[2], 2);   // 尾差補在最後一欄
$gsPointW = round($gsContentW - $gsLeftW - $gsNameW - $gsNoteW, 2);          // 右半邊「要點」

try {
    eg_print_log_add($db, [
        'source'   => 'sop_sip',
        'doc_name' => $formName . ' ' . (string)$doc['title'] . ' 版次' . (string)$ver['ver_no'],
        'part_no'  => (string)($doc['part_no_text'] ?? ''),
        'note'     => 'ver_id=' . $verId . ' ' . $paper['size'] . '/' . $paper['orient'],
    ]);
} catch (Throwable $e) {}

/** 圖面／步驟圖的網址（列印視窗載得到，權限與主頁同一套） */
function pf(int $id): string { return 'sopsip_file.php?id=' . $id; }
function h($s): string { return htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8'); }
/** 一行一條的文字欄位 → 陣列 */
function lines($s): array {
    /* 內容裡寫到的 AS 文件編號一律就地換成「現行編號　文件名稱」——編號改版之後舊資料
       會跟著顯示新編號（使用者 2026-09-22 指定「要自動連動到當時的文件編號」）。 */
    global $db;
    $out = [];
    foreach (preg_split("/\r\n|\r|\n/", ss_asdoc_expand($db, (string)$s)) as $l) {
        $l = trim($l); if ($l !== '') $out[] = $l;
    }
    return $out;
}
/** 這個檔案是不是圖片（不是的話印檔名就好，不要放一個破圖） */
function isImg(array $f): bool {
    $n = (string)($f['orig_name'] ?: $f['file_name']);
    return (bool)preg_match('~\.(jpe?g|png|gif|bmp|webp)$~i', $n);
}

$secFiles = $F['section_files'] ?? [];
/** 段落附件：使用者拍板「接在該段文字下方」，而且**縮圖不可以太小** */
function secImgs(array $secFiles, string $key): string {
    $list = $secFiles[$key] ?? [];
    if (!$list) return '';
    $h = '<div class="secimgs">';
    foreach ($list as $f) {
        $h .= '<div class="si">';
        $h .= isImg($f) ? '<img src="' . pf((int)$f['file_id']) . '">'
                        : '<div class="nofile">（附件）</div>';
        $h .= '<div class="cap">' . h((string)($f['orig_name'] ?? '')) . '</div></div>';
    }
    return $h . '</div>';
}

$SLOTS   = ss_slots();
$SLOTS_D = ss_slots_display();   // 顯示順序（核准→審核→製表）
$stampTpl = [];
foreach (array_keys($SLOTS) as $k) $stampTpl[$k] = ss_stamp_tpl($db, $k);

/* 標準檢驗指導書左下角那塊「注意事項」是每一份都一樣的固定文字（使用者 2026-09-22 指定）。
   放在設定裡由管理員維護，這一版自己有填就以自己的為準。 */
$SIP_NOTICE_DEFAULT = "觀察作業人員操作機器時是否有按照標準作業流程(SOP)操作。\n"
    . "檢查作業員手法是否正確。\n"
    . "檢查產品，注意產品的包裝區分，防止混料。\n"
    . "注意放置產品的泡棉是否有清洗乾淨。\n"
    . "檢查包裝時是否有按照訂單要求包裝。";
$noticeLines = lines($ver['notice'] ?? '');
if (!$noticeLines) $noticeLines = lines(ss_setting_get($db, 'sip_notice_default', $SIP_NOTICE_DEFAULT));
?>
<!DOCTYPE html>
<html lang="zh-Hant">
<head>
<meta charset="utf-8">
<title><?= h($formName) ?> <?= h($doc['title']) ?></title>
<style>
    /* 白邊由 .sheet 自己給，@page 的 margin 固定 0（兩邊各留一次＝內容被擠到中間又切邊） */
    @page { size: <?= $pageCss ?>; margin: 0; }
    html, body { margin:0; padding:0; background:#EDE7DE; }
    body { font-family:"Microsoft JhengHei","微軟正黑體",sans-serif; color:#222; font-size:11pt; }
    .sheet { width:<?= $sheetW ?>mm; min-height:<?= $sheetH ?>mm; box-sizing:border-box;
             padding:10mm 12mm 18mm; background:#fff; margin:0 auto; position:relative; }
    @media screen { .sheet { box-shadow:0 4px 18px rgba(0,0,0,.2); margin:12px auto; } }
    .toolbar { text-align:center; padding:10px; }
    .toolbar button { font-size:14px; padding:6px 20px; border:1px solid #C77C1A; background:#F0A24B;
                      color:#4A3524; border-radius:4px; cursor:pointer; font-weight:bold; }
    @media print {
        .toolbar { display:none !important; }
        html, body { background:#fff; }
        /* **列印時一定要把 min-height 歸零**（使用者回報「總是多印一頁空白」）：
           min-height 給的是「整張紙的高度」，只要算出來比可印高度多零點幾個 px（不同印表機
           驅動的邊界與 DPI 都會讓它多一點點），那零點幾就會被推到第二頁去，印出一張幾乎空白的紙。
           螢幕上仍然保留 min-height，才看得出一張紙的樣子。本專案 KPI 列印版踩過同一個坑。 */
        .sheet { min-height:0 !important; height:auto !important; }
    }

    /* ── 頁尾：Chrome 不支援 @page 的 margin box，一律用 position:fixed（每一頁都會印） ── */
    .pfoot { position:fixed; left:12mm; right:12mm; bottom:6mm; font-size:9pt; color:#333;
             display:flex; justify-content:space-between; align-items:flex-end; }
    .pfoot .asno { font-weight:bold; letter-spacing:1px; }

    h1.co { text-align:center; font-size:17pt; margin:0 0 2mm; letter-spacing:2px; }
    h2.fm { text-align:center; font-size:14pt; margin:0 0 3mm; letter-spacing:4px; font-weight:normal; }
    table { border-collapse:collapse; width:100%; }
    td, th { border:1px solid #000; padding:1.2mm 1.6mm; font-size:10pt; vertical-align:top;
             word-wrap:break-word; overflow-wrap:anywhere; }
    th { background:#F2F2F2; text-align:center; font-weight:normal; }
    .lab { background:#F7F7F7; text-align:center; white-space:nowrap; }
    .mid { text-align:center; }
    .blk { margin-top:2.5mm; }
    .blk ol, .blk ul { margin:0; padding-left:6mm; }
    .blk li { line-height:1.7; }
    .imgcell { text-align:center; }
    .imgcell img { max-width:100%; max-height:46mm; }
    /* 名稱與圖示同一格：名稱在上、置中、底下一條細線分隔（照紙本） */
    .steps .imgcell .sname { font-weight:bold; padding-bottom:1mm; margin-bottom:1mm;
                             border-bottom:1px solid #bbb; }
    .steps td.no { font-size:13pt; font-weight:bold; vertical-align:middle; }
    .steps td { vertical-align:top; }
    /* 資料列不可以被切成上下兩半，表頭跨頁重複（ai-rules/16） */
    thead { display:table-header-group; }
    tr { page-break-inside:avoid; }

    /* ── 標準檢驗指導書：左邊圖面＋注意事項，右邊檢驗項目（照紙本 2-QA-02-01） ── */
    .sipmain { table-layout:fixed; }
    .sipmain > tbody > tr > td { padding:0; border:1px solid #000; }
    .sipleft { width:<?= $paper['orient'] === 'landscape' ? 118 : 74 ?>mm; }
    .drawbox { text-align:center; padding:1.5mm; border-bottom:1px solid #000; }
    .drawbox img { max-width:100%; max-height:<?= $paper['orient'] === 'landscape' ? 168 : 120 ?>mm; }
    .drawbox .none { color:#888; font-size:9pt; padding:18mm 0; }
    .noticebox { padding:1.8mm 2.5mm; }
    .noticebox .t { font-weight:bold; text-align:center; margin-bottom:1.2mm; font-size:10.5pt; }
    .noticebox ol { margin:0; padding-left:5mm; }
    .noticebox li { line-height:1.6; font-size:9.5pt; }
    table.items { table-layout:fixed; }
    table.items td, table.items th { font-size:9.5pt; }
    table.items .ul { border-bottom:1px dotted #999; }
    .lim { display:flex; }
    .lim .k { width:9mm; flex:0 0 9mm; color:#444; }
    .lim .v { flex:1; }
    /* 品質特性的文字（跨珠Ø7.3152 這種）印在上下限上方，比照紙本 */
    .qc { font-weight:bold; margin-bottom:0.4mm; }

    /* ── 標準作業流程 SOP（gsop）：照紙本 as-sop 那一批 xlsx 的版面 ──
       左半邊＝圖面＋檢驗項目，右半邊＝作業標準（軟體步驟／硬體步驟）＋更改記錄＋簽章。 */
    .gs-head { table-layout:fixed; }
    .gs-head .ttl { text-align:center; font-weight:bold; font-size:12pt; letter-spacing:2px;
                    vertical-align:middle; background:#F7F7F7; }
    .gs-head td.v { text-align:center; height:8mm; vertical-align:middle; }
    .gs-main { table-layout:fixed; margin-top:2.5mm; }
    .gs-main > tbody > tr > td { padding:0; border:0; vertical-align:top; }
    /* 右半邊**不給外框**：右邊的內容（步驟＋更改記錄＋簽章）本來就比左邊的圖面矮，
       給了外框，簽章底下那段空白就會變成一個看起來很奇怪的大空盒子
       （使用者 2026-09-30 回報「簽核欄位底下不要有奇怪的空白」）。
       裡面三張表各自有自己的框線，該有的格線一條都不會少。 */
    .gs-main > tbody > tr > td.gs-left { border:1px solid #000; }
    .gs-left { width:<?= $gsLeftW ?>mm; }   /* 唯一來源＝上面算好的 $gsLeftW */
    .gs-draw { text-align:center; padding:1.5mm; border-bottom:1px solid #000; }
    .gs-draw img { max-width:100%; max-height:<?= $paper['orient'] === 'landscape' ? 150 : 104 ?>mm; }
    .gs-draw .none { color:#888; font-size:9pt; padding:16mm 0; }
    .gs-sec { text-align:center; font-weight:bold; letter-spacing:3px; background:#EFEFEF; }
    .gs-step { table-layout:fixed; }
    .gs-step th, .gs-step td { font-size:9.5pt; }
    /* 軟體步驟／硬體步驟那一欄縮成一半（使用者 2026-09-30：欄位都縮小一半）；
       備註欄對齊表頭「發行日期＋製表日期」那兩格，所以「要點」的右緣剛好落在版次欄右側。 */
    .gs-step .gname { width:<?= $gsNameW ?>mm; text-align:center; font-weight:bold; vertical-align:middle;
                      word-break:break-all; line-height:1.35; }
    .gs-step .gnote { width:<?= $gsNoteW ?>mm; font-size:9pt; }
    .gs-step td.gkv { padding:0; }
    /* 參數格：紙本上就是「鍵｜值」一列最多三組，值可以橫跨剩下的格子 */
    table.kv { table-layout:fixed; width:100%; border:0; }
    table.kv td { border:0; border-bottom:1px solid #D9D9D9; border-right:1px solid #D9D9D9;
                  padding:1mm 1.4mm; font-size:9.5pt; }
    table.kv tr:last-child td { border-bottom:0; }
    table.kv td.k { background:#FAFAFA; text-align:center; color:#333; }
    table.kv td.v { word-break:break-all; }
    .gs-chg { margin-top:0; }
    .gs-chg th, .gs-chg td { font-size:9pt; }
    /* 簽章列（照紙本在右半邊最下方） */
    .gs-sign { table-layout:fixed; }

    /* 圖章一律不縮小（ai-rules/18）；列印新視窗拿不到 eg_stamp.js 注入的 CSS，樣式要自己寫齊 */
    .sg { height:24mm; text-align:center; vertical-align:middle; }
    .sg .eg-stamp, .sg svg { display:inline-block; }
    .sg .eg-stamp-tpl { height:auto !important; }
    .sgname { font-size:9pt; color:#444; }
    /* 段落附件圖：使用者交代「縮圖不可過小」 */
    .secimgs { display:flex; flex-wrap:wrap; gap:3mm; margin-top:2mm; }
    .secimgs .si { width:<?= $paper['orient'] === 'landscape' ? 82 : 56 ?>mm; text-align:center; page-break-inside:avoid; }
    .secimgs .si img { max-width:100%; max-height:<?= $paper['orient'] === 'landscape' ? 62 : 46 ?>mm; border:1px solid #bbb; }
    .secimgs .cap { font-size:8pt; color:#555; word-break:break-all; line-height:1.3; }
    .secimgs .nofile { padding:8mm 0; color:#888; font-size:9pt; border:1px dashed #bbb; }
</style>
</head>
<body>
<div class="toolbar">
    <button onclick="window.print()">列印</button>
    <span style="font-size:12px;color:#5b3a1e;">
        <?= h($paper['size']) ?> <?= h(ss_orients()[$paper['orient']]) ?>
        ｜若沒有自動跳出列印視窗，按左邊的按鈕
    </span>
</div>

<div class="sheet">
    <h1 class="co"><?= h($company) ?></h1>
    <h2 class="fm"><?= h($formName) ?></h2>

<?php if ($layout === 'gsop'): /* ═══════ 標準作業流程 SOP（照紙本 as-sop） ═══════ */ ?>
    <?php
        $soft = $F['sects']['soft'] ?? [];
        $hard = $F['sects']['hard'] ?? [];
        /* 加工機種：以綁定的機台型號為準（機台主檔才是正本），沒綁才退回文件上的型號文字 */
        $mModels = ss_models_split((string)($doc['machine_model'] ?? ''));
        $mText   = $mModels ? implode('、', $mModels) : '';
        /** 一列參數格：n 組鍵值攤成 6 格，最後一個值把剩下的格子吃掉（照紙本的合併方式） */
        $kvRow = function (array $pairs): string {
            $n = max(1, count($pairs));
            $out = '';
            foreach ($pairs as $i => $p) {
                $last = ($i === count($pairs) - 1);
                $span = $last ? max(1, 7 - 2 * $n) : 1;
                $out .= '<td class="k">' . h($p['k']) . '</td>';
                $out .= '<td class="v"' . ($span > 1 ? ' colspan="' . $span . '"' : '') . '>' . h($p['v']) . '</td>';
            }
            return $out;
        };
    ?>
    <table class="gs-head">
        <colgroup>
            <col style="width:<?= $gsTtlW ?>mm;">
            <?php foreach ($gsMidW as $w): ?><col style="width:<?= $w ?>mm;"><?php endforeach; ?>
            <col style="width:<?= $gsVerW ?>mm;">
            <col style="width:<?= $gsIssueW ?>mm;">
            <col style="width:<?= $gsDateW ?>mm;">
        </colgroup>
        <tr>
            <td class="ttl" rowspan="2">標 準 作 業 流 程 S O P</td>
            <td class="lab">加工機種</td><td class="lab">客戶名稱</td><td class="lab">產 品 料 號</td>
            <td class="lab">工 程 名 稱</td>
            <!-- 使用者 2026-09-30 指定：**取消數量欄**，版次之外另外印發行日期 -->
            <td class="lab">版次</td>
            <td class="lab">發行日期</td>
            <td class="lab">製表日期</td>
        </tr>
        <tr>
            <td class="v"><?= h($mText ?: '—') ?></td>
            <td class="v"><?= h($doc['customer_name'] ?: ($ver['customer_name'] ?? '')) ?></td>
            <td class="v"><?= h($doc['part_no_text'] ?: '—') ?></td>
            <td class="v"><?= h($doc['proc_name'] ?: $doc['title']) ?></td>
            <td class="v"><?= h($ver['ver_no']) ?></td>
            <td class="v"><?= $issueDate !== '' ? h(eg_fmt_date($issueDate)) : '—' ?></td>
            <td class="v"><?= h(eg_fmt_date($formDate)) ?></td>
        </tr>
    </table>

    <table class="gs-main">
        <colgroup><col style="width:<?= $gsLeftW ?>mm;"><col style="width:<?= round($gsContentW - $gsLeftW, 2) ?>mm;"></colgroup>
        <tbody><tr>
        <!-- 左半邊：圖面＋檢驗項目 -->
        <td class="gs-left">
            <div class="gs-draw">
                <?php if ((int)$ver['draw_file_id']): ?>
                    <img src="<?= pf((int)$ver['draw_file_id']) ?>">
                <?php else: ?><div class="none">（尚未帶入圖面）</div><?php endif; ?>
            </div>
            <table class="items">
                <thead><tr>
                    <th style="width:26mm;">管理重點</th><th style="width:30mm;">品質特性</th>
                    <th style="width:15mm;">擔當</th><th style="width:24mm;">檢驗方法</th>
                    <th style="width:20mm;">檢具編號</th><th>備註</th>
                </tr></thead>
                <tbody>
                <?php foreach ($F['items'] as $it): ?>
                    <?php
                        $up = trim((string)$it['up_limit']); $lo = trim((string)$it['lo_limit']);
                        $hasLim = ($up !== '' || $lo !== '');
                        $qc = trim((string)$it['q_char']);
                        /* 檢具那一欄紙本上逐檔不同（有的印檢具編號、有的印檢驗頻率），
                           兩個都有就一起印，不要挑一個而讓另一個安靜消失。 */
                        $tool = trim((string)($it['tool_label'] ?: $it['tool_no']));
                        $fq   = trim((string)$it['freq']);
                    ?>
                    <tr>
                        <td><?= h($it['ctrl_point']) ?></td>
                        <td>
                            <?php if ($qc !== ''): ?><div class="qc"><?= h($qc) ?></div><?php endif; ?>
                            <?php if ($hasLim): ?>
                                <div class="lim ul"><span class="k">上限</span><span class="v"><?= h($up) ?></span></div>
                                <div class="lim"><span class="k">下限</span><span class="v"><?= h($lo) ?></span></div>
                            <?php endif; ?>
                        </td>
                        <td class="mid"><?= h($it['owner_label'] ?? $it['owner']) ?></td>
                        <td><?= h($it['method']) ?></td>
                        <td class="mid"><?= h($tool !== '' && $fq !== '' ? $tool . '／' . $fq : ($tool ?: $fq)) ?></td>
                        <td><?= h($it['note']) ?></td>
                    </tr>
                <?php endforeach; ?>
                <?php if (!$F['items']): ?>
                    <tr><td colspan="6" class="mid" style="color:#888;padding:6mm;">（尚未填寫檢驗項目）</td></tr>
                <?php endif; ?>
                </tbody>
            </table>
        </td>

        <!-- 右半邊：作業標準（軟體步驟／硬體步驟）＋更改記錄＋簽章 -->
        <td>
            <table class="gs-step">
                <?php /* table-layout:fixed 的欄寬是看**第一列**決定的，而第一列是 colspan=3 的
                         「作業標準」——不給 colgroup 的話三欄會被平均分成各三分之一，
                         CSS 上寫的 12mm／52mm 完全不會生效（實測量到 71.9mm）。 */ ?>
                <colgroup>
                    <col style="width:<?= $gsNameW ?>mm;">
                    <col style="width:<?= $gsPointW ?>mm;">
                    <col style="width:<?= $gsNoteW ?>mm;">
                </colgroup>
                <tr><td class="gs-sec" colspan="3">作 業 標 準</td></tr>
                <tr><th class="gname">軟 體 步 驟</th><th>要　點</th><th class="gnote">備註</th></tr>
                <?php foreach ($soft as $s): ?>
                    <tr>
                        <td class="gname"><?= h($s['step_name']) ?></td>
                        <td class="gkv">
                            <?php if (!empty($s['kv'])): ?>
                                <table class="kv"><?php foreach ($s['kv'] as $row): ?>
                                    <tr><?= $kvRow($row) ?></tr>
                                <?php endforeach; ?></table>
                            <?php else: ?>
                                <?php foreach (lines($s['step_text']) as $l): ?><div style="padding:1mm 1.4mm;"><?= h($l) ?></div><?php endforeach; ?>
                            <?php endif; ?>
                        </td>
                        <td class="gnote"><?php foreach (lines($s['note']) as $l): ?><div><?= h($l) ?></div><?php endforeach; ?></td>
                    </tr>
                <?php endforeach; ?>
                <?php if (!$soft): ?><tr><td colspan="3" class="mid" style="color:#888;padding:4mm;">（尚未填寫軟體步驟）</td></tr><?php endif; ?>

                <tr><th class="gname">硬 體 步 驟</th><th>要　點</th><th class="gnote">備註</th></tr>
                <?php foreach ($hard as $s): ?>
                    <tr>
                        <td class="gname"><?= h($s['step_name']) ?></td>
                        <td><?php foreach (lines($s['step_text']) as $l): ?><div><?= h($l) ?></div><?php endforeach; ?></td>
                        <td class="gnote"><?php foreach (lines($s['note']) as $l): ?><div><?= h($l) ?></div><?php endforeach; ?></td>
                    </tr>
                <?php endforeach; ?>
                <?php if (!$hard): ?><tr><td colspan="3" class="mid" style="color:#888;padding:4mm;">（尚未填寫硬體步驟）</td></tr><?php endif; ?>
            </table>

            <table class="gs-chg">
                <thead><tr><th colspan="3">更改記錄</th></tr>
                <tr><th style="width:16mm;">版次</th><th style="width:26mm;">日期</th><th>說明</th></tr></thead>
                <tbody>
                <?php $chgN = 0; foreach (array_reverse($vers) as $v): $chgN++; ?>
                    <tr>
                        <td class="mid"><?= h($v['ver_no']) ?></td>
                        <td class="mid"><?= h(eg_fmt_date($v['form_date'])) ?></td>
                        <td><?php foreach (lines($v['rev_text'] ?? $v['rev_note']) as $l): ?><div><?= h($l) ?></div><?php endforeach; ?></td>
                    </tr>
                <?php endforeach; ?>
                <?php /* 至少留三列空白給現場手寫（使用者 2026-09-30 指定），比照紙本 */ ?>
                <?php for ($i = $chgN; $i < 3; $i++): ?>
                    <tr><td class="mid">&nbsp;</td><td class="mid">&nbsp;</td><td>&nbsp;</td></tr>
                <?php endfor; ?>
                </tbody>
            </table>

            <table class="gs-sign">
                <thead><tr>
                    <?php foreach ($SLOTS_D as $k => $def): ?><th style="width:<?= number_format(100 / max(1, count($SLOTS_D)), 1) ?>%;"><?= h($def['label']) ?></th><?php endforeach; ?>
                </tr></thead>
                <tbody><tr>
                    <?php foreach ($SLOTS_D as $k => $def): $s = $F['signs'][$k] ?? null; ?>
                        <td class="sg" data-slot="<?= h($k) ?>"
                            data-name="<?= h($s['user_name'] ?? '') ?>"
                            data-date="<?= h($s['sign_date'] ?? '') ?>"
                            data-dept="<?= h($s['dept_name'] ?? '') ?>"
                            data-pos="<?= h($s['position_name'] ?? '') ?>"
                            data-deputy="<?= (int)($s['by_deputy'] ?? 0) ? 1 : 0 ?>"></td>
                    <?php endforeach; ?>
                </tr></tbody>
            </table>
        </td>
    </tr></tbody></table>

<?php elseif ($kind === 'equip'): ?>
    <table>
        <tr>
            <td class="lab" style="width:26mm;"><?= $doc['scope'] === 'tool' ? '量具編號' : '機器編號' ?></td>
            <td><?= h($assetTx) ?></td>
            <td class="lab" style="width:26mm;">機器製造商</td><td><?= h($ver['m_maker']) ?></td>
        </tr>
        <tr>
            <td class="lab">機器名稱</td><td><?= h($ver['m_name'] ?: $doc['title']) ?></td>
            <td class="lab">型式規格</td><td><?= h($ver['m_spec'] ?: ($doc['machine_model'] ?? '')) ?></td>
        </tr>
        <tr>
            <td class="lab">加工適用範圍</td><td colspan="3"><?= h($ver['m_range']) ?></td>
        </tr>
        <tr>
            <td class="lab">版次</td><td class="mid"><?= h($ver['ver_no']) ?></td>
            <td class="lab">制定／修訂日期</td><td class="mid"><?= h(eg_fmt_date($formDate)) ?></td>
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
            <td class="lab" style="width:24mm;">製程名稱</td><td><?= h($doc['proc_name'] ?: $doc['title']) ?></td>
<?php /* 綁機台的文件，使用設備就是綁定的機器編號（畫面上已經不再另外開一個欄位讓人重打一次） */ ?>
            <td class="lab" style="width:24mm;">使用設備</td>
            <td><?= h((string)$doc['scope'] === 'machine' ? ($assetTx ?: $doc['machine_model']) : $ver['use_equip']) ?></td>
            <td class="lab" style="width:24mm;">預計工時</td><td class="mid" style="width:24mm;"><?= h($ver['est_hours']) ?></td>
        </tr>
        <tr>
            <td class="lab">適用料號</td><td><?= h($doc['part_no_text'] ?: '通用') ?></td>
            <td class="lab">客戶</td><td><?= h($doc['customer_name'] ?? '') ?></td>
            <td class="lab">版次／日期</td>
            <td class="mid"><?= h($ver['ver_no']) ?>　<?= h(eg_fmt_date($formDate)) ?></td>
        </tr>
    </table>
    <!-- 照紙本排：名稱與圖示**同一格**（名稱在上、圖在下），不另外開一欄（使用者 2026-09-22 指定） -->
    <table class="blk steps">
        <thead><tr>
            <th style="width:12mm;">項次</th><th style="width:62mm;">參考圖示</th>
            <th>操作步驟</th><th style="width:52mm;">說明</th>
        </tr></thead>
        <tbody>
        <?php foreach ($F['steps'] as $i => $s): ?>
            <tr>
                <td class="mid no"><?= $i + 1 ?></td>
                <td class="imgcell">
                    <?php if (trim((string)$s['step_name']) !== ''): ?>
                        <div class="sname"><?= h($s['step_name']) ?></div>
                    <?php endif; ?>
                    <?php if ((int)$s['img_file_id']): ?><img src="<?= pf((int)$s['img_file_id']) ?>"><?php endif; ?>
                </td>
                <?php /* 步驟文字**不要自己補編號**：現場輸入的內容本來就帶「1.」「2.」（實測全部如此），
                         補了會變成「1.1.以氣槍清潔…」 */ ?>
                <td><?php foreach (lines($s['step_text']) as $l): ?><div><?= h($l) ?></div><?php endforeach; ?></td>
                <td><?php foreach (lines($s['note']) as $l): ?><div><?= h($l) ?></div><?php endforeach; ?></td>
            </tr>
        <?php endforeach; ?>
        </tbody>
    </table>
    <?php if ($x = secImgs($secFiles, 'use_equip')): ?>
    <table class="blk"><tr><td class="lab" style="width:26mm;">使用設備說明</td><td><?= $x ?></td></tr></table>
    <?php endif; ?>

<?php else: /* ───────────── 標準檢驗指導書 SIP ───────────── */ ?>
    <table>
        <tr>
            <!-- 使用者指定：不需要製令單號與數量；「工程名稱」就是製程 -->
            <td class="lab" style="width:22mm;">客戶名稱</td>
            <td class="mid" style="width:34mm;"><?= h($doc['customer_name'] ?: ($ver['customer_name'] ?? '')) ?></td>
            <td class="lab" style="width:22mm;">產品料號</td>
            <td style="width:44mm;"><?= h($doc['part_no_text'] ?: '通用') ?></td>
            <td class="lab" style="width:22mm;">工程名稱</td>
            <td style="width:32mm;"><?= h($doc['proc_name']) ?></td>
            <td class="lab" style="width:20mm;">圖面版次</td>
            <td class="mid" style="width:18mm;"><?= h($ver['ver_no']) ?></td>
            <td class="lab" style="width:22mm;">製表日期</td>
            <td class="mid" style="width:26mm;"><?= h(eg_fmt_date($formDate)) ?></td>
        </tr>
    </table>

    <table class="sipmain blk"><tbody><tr>
        <td class="sipleft">
            <div class="drawbox">
                <?php if ((int)$ver['draw_file_id']): ?>
                    <img src="<?= pf((int)$ver['draw_file_id']) ?>">
                <?php else: ?><div class="none">（尚未帶入圖面）</div><?php endif; ?>
            </div>
            <div class="noticebox">
                <div class="t">注意事項</div>
                <ol><?php foreach ($noticeLines as $l): ?><li><?= h($l) ?></li><?php endforeach; ?></ol>
            </div>
        </td>
        <td>
            <table class="items">
                <thead><tr>
                    <th style="width:30mm;">管理重點</th><th style="width:38mm;">品質特性</th>
                    <th style="width:16mm;">擔當者</th><th style="width:26mm;">檢驗方法</th>
                    <th style="width:22mm;">檢具編號</th><th style="width:26mm;">檢驗頻率</th><th>備註</th>
                </tr></thead>
                <tbody>
                <?php foreach ($F['items'] as $it): ?>
                    <?php
                        $up = trim((string)$it['up_limit']); $lo = trim((string)$it['lo_limit']);
                        $hasLim = ($up !== '' || $lo !== '');
                    ?>
                    <tr>
                        <td><?= h($it['ctrl_point']) ?></td>
                        <td>
                            <?php
                                /* 品質特性與上下限**兩個都要印**（使用者 2026-09-23 回報）。
                                   原本只要有上下限就整個不印 q_char，於是設定好的「跨珠Ø7.3152」
                                   在紙本上完全看不到——而那正是這一列到底在量什麼的唯一說明。 */
                                $qc = trim((string)$it['q_char']);
                            ?>
                            <?php if ($qc !== ''): ?><div class="qc"><?= h($qc) ?></div><?php endif; ?>
                            <?php if ($hasLim): ?>
                                <div class="lim ul"><span class="k">上限</span><span class="v"><?= h($up) ?></span></div>
                                <div class="lim"><span class="k">下限</span><span class="v"><?= h($lo) ?></span></div>
                            <?php endif; ?>
                        </td>
                        <td class="mid"><?= h($it['owner_label'] ?? $it['owner']) ?></td>
                        <td><?= h($it['method']) ?></td>
                        <td class="mid"><?= h($it['tool_no']) ?></td>
                        <td><?= h($it['freq']) ?></td>
                        <td><?= h($it['note']) ?></td>
                    </tr>
                <?php endforeach; ?>
                <?php if (!$F['items']): ?>
                    <tr><td colspan="7" class="mid" style="color:#888;padding:6mm;">（尚未填寫檢驗項目）</td></tr>
                <?php endif; ?>
                </tbody>
            </table>
        </td>
    </tr></tbody></table>
<?php endif; ?>

    <!-- 修訂履歷／修改記錄：不另外手打，由各版次組出來。
         標準檢驗指導書照紙本只有三欄（修改版次／修改日期／說明），使用者 2026-09-22 指定；
         SOP 那兩份維持原本的五欄（多印製表人與狀態，內部用得到）。 -->
<?php if ($layout === 'gsop'): /* gsop 的更改記錄與簽章照紙本排在右半邊，上面已經印過，這裡不重複 */ ?>
<?php elseif ($kind === 'sip'): ?>
    <table class="blk">
        <thead><tr><th colspan="3">修改記錄</th></tr>
        <tr><th style="width:22mm;">修改版次</th><th style="width:32mm;">修改日期</th><th>說明</th></tr></thead>
        <tbody>
        <?php foreach (array_reverse($vers) as $v): ?>
            <tr>
                <td class="mid"><?= h($v['ver_no']) ?></td>
                <td class="mid"><?= h(eg_fmt_date($v['form_date'])) ?></td>
                <td><?= h($v['rev_text'] ?? $v['rev_note']) ?></td>
            </tr>
        <?php endforeach; ?>
        </tbody>
    </table>
<?php else: ?>
    <table class="blk">
        <thead><tr><th colspan="5">修訂履歷</th></tr>
        <tr><th style="width:18mm;">版次</th><th style="width:30mm;">日期</th><th>制/修訂事項</th>
            <th style="width:34mm;">制/修訂</th><th style="width:26mm;">狀態</th></tr></thead>
        <tbody>
        <?php foreach (array_reverse($vers) as $v): ?>
            <?php $sg = ss_sign_map($db, (int)$v['ver_id']); ?>
            <tr>
                <td class="mid"><?= h($v['ver_no']) ?></td>
                <td class="mid"><?= h(eg_fmt_date($v['form_date'])) ?></td>
                <td><?= h($v['rev_text'] ?? $v['rev_note']) ?></td>
                <td class="mid"><?= h($sg['maker']['user_name'] ?? '') ?></td>
                <td class="mid"><?= h(ss_statuses()[$v['status']] ?? $v['status']) ?></td>
            </tr>
        <?php endforeach; ?>
        </tbody>
    </table>
<?php endif; ?>

    <!-- 簽章：簽的順序是製表→審核→核准，但**印出來由左到右是核准→審核→製表**
         （使用者 2026-09-22 指定，職位高的在左，比照紙本；順序唯一登記處＝ss_slots_display()） -->
<?php if ($layout !== 'gsop'): ?>
    <table class="blk">
        <thead><tr>
            <?php foreach ($SLOTS_D as $k => $def): ?><th style="width:<?= number_format(100 / max(1, count($SLOTS_D)), 1) ?>%;"><?= h($def['label']) ?></th><?php endforeach; ?>
        </tr></thead>
        <tbody><tr>
            <?php foreach ($SLOTS_D as $k => $def): $s = $F['signs'][$k] ?? null; ?>
                <td class="sg" data-slot="<?= h($k) ?>"
                    data-name="<?= h($s['user_name'] ?? '') ?>"
                    data-date="<?= h($s['sign_date'] ?? '') ?>"
                    data-dept="<?= h($s['dept_name'] ?? '') ?>"
                    data-pos="<?= h($s['position_name'] ?? '') ?>"
                    data-deputy="<?= (int)($s['by_deputy'] ?? 0) ? 1 : 0 ?>"></td>
            <?php endforeach; ?>
        </tr></tbody>
    </table>
<?php endif; ?>

    <!-- 頁尾：Chrome 不支援 @page 的 margin box，所以用 fixed（每一頁都會印到） -->
    <div class="pfoot">
        <div class="pg"></div>
        <div class="asno"><?= h($asNo) ?></div>
    </div>
</div>

<script src="../../resource/js/eg_date_fmt.js?v=<?= @filemtime(__DIR__ . '/../../resource/js/eg_date_fmt.js') ?>"></script>
<script src="../../resource/js/eg_stamp_tpl.js?v=<?= @filemtime(__DIR__ . '/../../resource/js/eg_stamp_tpl.js') ?>"></script>
<script src="../../resource/js/eg_stamp.js?v=<?= @filemtime(__DIR__ . '/../../resource/js/eg_stamp.js') ?>"></script>
<script>
window.__ownCompany = <?= json_encode($company, JSON_UNESCAPED_UNICODE) ?>;
var SS_TPL = <?= json_encode(array_map(function ($t) {
    return $t ? json_decode((string)$t['schema_json'], true) : null;
}, $stampTpl), JSON_UNESCAPED_UNICODE) ?>;
var SS_AUTOPRINT = <?= isset($_GET['noprint']) ? '0' : '1' ?>;

(function () {
    function drawStamps() {
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
    /** 圖片全部載完才算數——沒等它就會印出一張沒有圖面的紙 */
    function imagesReady(cb) {
        var imgs = Array.prototype.slice.call(document.images);
        var left = imgs.filter(function (im) { return !im.complete; });
        if (!left.length) { cb(); return; }
        var n = left.length, done = false;
        function one() { if (--n <= 0 && !done) { done = true; cb(); } }
        left.forEach(function (im) { im.addEventListener('load', one); im.addEventListener('error', one); });
        setTimeout(function () { if (!done) { done = true; cb(); } }, 6000);   // 圖載不出來也不能卡死
    }
    /* 只差一點點就放得下一張紙時自動縮一點（使用者 2026-09-22：實際縮得進一張 A4 的就該印成一張）。
       兩個重點，都是 KPI 列印版踩出來的：
       ① **套上去之後一定要再量一次**——zoom 會改變字級與換行，實際高度不等於原高度×比例，
          所以是「縮一點→重量→還超出再縮」而不是一次算好比例。
       ② 只縮不放大，而且有下限（0.62）：縮到看不清楚就不如乖乖印兩張。 */
    var PAGE_MM = <?= (int)$sheetH ?>;          // 這次用的紙張高度（mm）
    function mm2px(mm) { return mm * 96 / 25.4; }
    function fitOnePage() {
        var sheet = document.querySelector('.sheet');
        if (!sheet) return;
        var limit = mm2px(PAGE_MM) - 2;          // 留 2px 給捨入誤差
        var z = 1;
        for (var i = 0; i < 12; i++) {
            var h = sheet.getBoundingClientRect().height;
            if (h <= limit) break;
            if (h > limit * 1.75) break;         // 本來就要兩張以上，不必硬縮
            if (z <= 0.62) break;
            z = Math.max(0.62, z - 0.04);
            sheet.style.zoom = z;
        }
    }
    function go() {
        drawStamps();
        imagesReady(function () {
            fitOnePage();                        // 圖載完、章畫完才量得準
            if (!SS_AUTOPRINT) return;
            setTimeout(function () { window.print(); }, 150);
        });
    }
    // 掃描實體章的對照表是非同步載入的，沒等它就會蓋出跟畫面不一樣的預設章
    if (window.EGStamp && EGStamp.whenReady) EGStamp.whenReady(go); else go();

    // 印完（或取消）就把這個分頁關掉，使用者不必自己回頭關
    window.addEventListener('afterprint', function () { setTimeout(function () { window.close(); }, 250); });
})();
</script>
</body>
</html>
