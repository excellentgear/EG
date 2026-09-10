<?php
/**
 * 審核表單 — 建立／填寫／簽名／審核／核准／列印（review_form 引擎）—— 2026-08-11 新增
 * 模板設定請至「審核表單模板管理」review_form_template.php（僅管理員/維護人員可進）。
 * 資料一律走 src/store/ReviewForm_API.php；權限 src/common/review_form_lib.php rvf_perms()
 */
session_start();
if (!isset($_SESSION['userName'])) {
    $_SESSION['lastpage'] = "../../views/ADM/review_form.php";
    header("Location:../../index.php");
    exit;
}
include_once '../../src/common/_config.php';
include_once '../../src/common/DBConnection.php';
include_once '../../src/common/review_form_lib.php';

$db = (new DBConnection())->getPDO();
$rvfUser = rvf_current_user($db);
$perms = rvf_perms($db, $rvfUser);
?>
<!DOCTYPE html>
<html lang="zh-Hant">
<head>
    <meta charset="utf-8">
    <meta http-equiv="X-UA-Compatible" content="IE=edge">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>審核表單</title>
    <link href="../../resource/css/bootstrap.css" rel="stylesheet">
    <link href="../../resource/css/font-awesome.css" rel="stylesheet">
    <link href="../../resource/css/nprogress.css" rel="stylesheet">
    <link href="../../resource/css/custom.css" rel="stylesheet">
    <style>
        #sidebar-menu { visibility: hidden; }
        .right_col .page-title { margin:8px 0 4px; overflow:hidden; clear:both; }
        .rf-toolbar { display:flex; flex-wrap:wrap; gap:6px; align-items:center; clear:both;
            border:1.5px solid #E8D5B5; border-radius:8px; padding:8px 10px; margin-bottom:10px; background:#FDF8EF; }
        .rf-toolbar select, .rf-toolbar button { height:30px; font-size:13px; padding:0 12px; border:1px solid #D8BE93; border-radius:4px;
            background:#fff; color:#5b3a1e; cursor:pointer; }
        .rf-toolbar .btn-warm { background:#F0A24B; color:#fff; border-color:#d98a33; }
        .page-help-btn { margin-left:auto; height:30px; font-size:13px; padding:0 14px; border:1px solid #D8BE93;
            border-radius:4px; background:#fff; color:#5b3a1e; cursor:pointer; }
        .help-doc h4 { font-size:14px; color:#8A5A2B; margin:10px 0 4px; }
        table.rf-tbl { width:100%; border-collapse:collapse; font-size:13px; background:#fff; }
        table.rf-tbl th, table.rf-tbl td { border:1px solid #EADFC8; padding:6px 8px; }
        table.rf-tbl thead th { background:#F7E0BD; color:#5b3a1e; }
        .rf-table-wrap { overflow-x:auto; border:1px solid #E8D5B5; border-radius:6px; }
        .st-badge { border-radius:10px; padding:2px 9px; font-size:11.5px; color:#fff; }
        .st-draft{background:#b0a390;} .st-submitted,.st-reviewing,.st-approving{background:#F0A24B;} .st-approved{background:#3f9142;} .st-rejected{background:#DD5138;} .st-void{background:#888;}
        .rf-mask { display:none; position:fixed; inset:0; background:rgba(60,40,20,.45); z-index:1050; overflow-y:auto; }
        .rf-modal { background:#fff; border-radius:8px; max-width:1040px; margin:24px auto; box-shadow:0 5px 25px rgba(0,0,0,.3); }
        #viewMask .rf-modal { max-width:min(1200px, 94vw); }
        .itm-tbl-wrap { overflow-x:auto; margin-bottom:8px; }
        .rf-modal .m-head { background:#F7E0BD; color:#5b3a1e; font-weight:bold; padding:10px 15px; border-radius:8px 8px 0 0;
            display:flex; justify-content:space-between; position:sticky; top:0; }
        .rf-modal .m-head .m-close { cursor:pointer; color:#b5762a; }
        .rf-modal .m-body { padding:15px; }
        .rf-modal .m-body label { display:block; font-size:13px; color:#5b3a1e; margin:9px 0 3px; }
        .rf-modal .m-body input[type=text], .rf-modal .m-body input[type=date], .rf-modal .m-body select, .rf-modal .m-body textarea
            { border:1px solid #D8BE93; border-radius:4px; padding:5px 8px; font-size:13px; box-sizing:border-box; }
        .rf-modal .m-foot { padding:10px 15px; border-top:1px solid #EADFC8; text-align:right; }
        .rf-modal .m-foot button { height:30px; padding:0 16px; border-radius:4px; font-size:13px; border:1px solid #d98a33; cursor:pointer; margin-left:6px; }
        .rf-modal .m-foot .b-ok { background:#F0A24B; color:#fff; }
        .rf-modal .m-foot .b-cancel { background:#fff; color:#5b3a1e; border-color:#D8BE93; }
        .rf-modal .m-foot .b-danger { background:#DD5138; color:#fff; border-color:#c23f28; }
        table.itm-tbl { width:100%; border-collapse:collapse; font-size:12.5px; margin-bottom:8px; }
        table.itm-tbl th, table.itm-tbl td { border:1px solid #EADFC8; padding:5px 6px; vertical-align:top; }
        table.itm-tbl thead th { background:#F7E0BD; color:#5b3a1e; }
        table.itm-tbl textarea { width:100%; min-height:40px; border:1px solid #D8BE93; border-radius:4px; padding:4px 6px; font-size:12.5px; box-sizing:border-box; }
        table.itm-tbl input[type=text], table.itm-tbl input[type=date], table.itm-tbl select { width:100%; min-width:84px; border:1px solid #D8BE93; border-radius:4px; padding:3px 5px; font-size:12px; box-sizing:border-box; }
        table.itm-tbl td { min-width:70px; }
        table.itm-tbl th:first-child, table.itm-tbl td:first-child,
        table.itm-tbl th:last-child, table.itm-tbl td:last-child { min-width:auto; }
        .fld-block { display:block; margin-bottom:4px; } .fld-inline { display:inline-block; width:48%; margin:0 1% 4px; vertical-align:top; }
        .fld-lbl { font-size:10.5px; color:#8a6d45; }
        .owner-lbl { font-size:10.5px; font-weight:bold; color:#8a6d45; margin:3px 0 1px; }
        .owner-lbl:first-child { margin-top:0; }
        .rf-btn-sm { height:28px; padding:0 12px; border-radius:4px; font-size:12.5px; border:1px solid #D8BE93; background:#fff; color:#5b3a1e; cursor:pointer; }
        .rf-btn-sm:hover { background:#FBF0DD; }
        .dp-pick { position:relative; border:1px solid #D8BE93; border-radius:4px; background:#fff; padding:2px 3px; min-width:110px; margin-bottom:3px; }
        .dp-tags { display:flex; flex-wrap:wrap; gap:2px; }
        .dp-tags .tg { background:#F7E0BD; color:#5b3a1e; border-radius:9px; font-size:11px; padding:1px 5px 1px 7px; white-space:nowrap; }
        .dp-tags .tg i { cursor:pointer; color:#b5762a; margin-left:3px; }
        .dp-pick > input { width:100%; border:none !important; outline:none; font-size:11px; padding:2px 3px !important; }
        /* position:fixed（不是absolute）：欄位表格改用 .itm-tbl-wrap 橫向捲動後，absolute 下拉會被捲動容器的
           overflow 裁掉（CSS 規則：overflow-x 非 visible 時 overflow-y 會被瀏覽器強制視為 auto，兩軸一起裁切，
           無法只裁橫向）。改用 fixed 定位＋JS 依 input 位置現算座標，直接相對視窗定位，不受任何捲動容器影響。 */
        .dp-list { display:none; position:fixed; z-index:1200; background:#fff;
            border:1px solid #D8BE93; border-radius:4px; max-height:180px; overflow-y:auto; box-shadow:0 4px 10px rgba(0,0,0,.18); min-width:150px; }
        .dp-list div { padding:3px 8px; font-size:11.5px; color:#5b3a1e; cursor:pointer; }
        .dp-list div:hover { background:#FBF0DD; }
        .rf-del { color:#DD5138; cursor:pointer; }
        .subitem-ctrl { margin-top:3px; display:flex; gap:6px; }
        .subitem-ctrl .rf-mini-btn, .col-fill .rf-mini-btn { font-size:10.5px; color:#8a5a2b; border:1px solid #D8BE93; border-radius:9px; padding:1px 7px; cursor:pointer; background:#FBF0DD; white-space:nowrap; }
        .subitem-ctrl .rf-mini-btn:hover, .col-fill .rf-mini-btn:hover { background:#F7E0BD; }
        table.itm-tbl td.subitem-num { text-align:center; vertical-align:middle; }
        table.itm-tbl td.subitem-heading { background:#FDF8EF; }
        table.itm-tbl td.subitem-heading textarea { font-weight:bold; border-color:#D8BE93; }
        table.itm-tbl td.subitem-heading-note { background:#F5F0E5; color:#b0a390; font-size:11px; text-align:left; font-style:italic; }
        /* 直式標題（左側固定列標題）與標題直書（2026-09-09 使用者明確要求，像 SWOT／組織處境分析表那種矩陣） */
        .hdr-vert { writing-mode:vertical-rl; text-orientation:upright; letter-spacing:2px; display:inline-block; white-space:nowrap; }
        table.itm-tbl td.row-head { background:#F7E0BD; color:#5b3a1e; font-weight:bold; text-align:center; vertical-align:middle; white-space:pre-wrap; }
        table.itm-tbl td.row-head .hdr-vert { line-height:1.15; }
        table.itm-tbl td.row-head.row-head-left { text-align:left; }
        table.itm-tbl td.row-side { vertical-align:top; }
        table.itm-tbl textarea.fill-auto { width:100%; min-height:26px; overflow:hidden; resize:vertical;
            border:1px solid #D8BE93; border-radius:4px; padding:4px 6px; font-size:12.5px; box-sizing:border-box; }
        table.itm-tbl tr.head-band td { background:#FDF8EF; }
        .rf-corner { position:relative; min-width:110px; height:56px; padding:0 !important; }
        .rf-corner .cor-line { position:absolute; left:0; top:0; width:100%; height:100%; }
        .rf-corner .cor-col { position:absolute; right:6px; top:3px; font-weight:bold; }
        .rf-corner .cor-row { position:absolute; left:6px; bottom:3px; font-weight:bold; }
        table.itm-tbl thead th.rf-corner.corner-merge { border-bottom:none; }
        table.itm-tbl td.row-head.merge-up { border-top:none; }
        .col-fill { margin-top:4px; display:flex; flex-direction:column; gap:3px; font-weight:normal; }
        .col-fill .col-fill-inp { width:100%; min-width:0; border:1px solid #D8BE93; border-radius:4px; padding:2px 4px; font-size:11px; box-sizing:border-box; background:#fff; color:#5b3a1e; }
        .sign-slot { border:1px dashed #E8D5B5; border-radius:4px; padding:3px 5px; margin-bottom:3px; font-size:11px; }
        .sign-yes { color:#3f9142; font-weight:bold; } .sign-no { color:#b0a390; }
        .decide-box { border:1.5px solid #E8D5B5; border-radius:8px; padding:10px; margin-top:10px; background:#FDF8EF; }
        @media print { .page-help-btn { display:none; } }
    </style>
</head>
<body class="nav-sm">
<div class="container body">
<div class="main_container">
    <?php include '../partPage/sideAndTopBarMenu.html' ?>
    <div class="right_col" role="main">
        <div class="page-title" style="display:flex;align-items:center;flex-wrap:wrap;clear:both;">
            <h2 style="margin:6px 0;">審核表單 <small style="color:#8a6d45;">建立／填寫／簽名／審核／核准／列印</small></h2>
            <button class="page-help-btn" id="btnPageHelp"><i class="fa fa-question-circle"></i> 使用說明</button>
        </div>
        <div class="clearfix"></div>

<?php if (!$perms['canView']): ?>
        <div><h4><i class="fa fa-lock"></i> 無審核表單檢閱權限</h4><p>請洽系統管理者於「使用者權限設定」指派「審核表單」相關角色。</p></div>
<?php else: ?>
        <div class="rf-toolbar">
            <label>模板</label><select id="tplFilter"><option value="0">全部</option></select>
            <button class="btn-warm" id="btnAdd" style="display:none;"><i class="fa fa-plus"></i> 新增表單</button>
            <a href="review_form_template.php" class="btn" style="margin-left:auto;height:30px;line-height:28px;padding:0 12px;border:1px solid #D8BE93;border-radius:4px;color:#5b3a1e;text-decoration:none;">模板管理→</a>
        </div>
        <div class="rf-table-wrap">
        <table class="rf-tbl">
            <thead><tr><th>編號</th><th>模板</th><th>建立日期</th><th>填表人</th><th>狀態</th><th style="width:90px;">操作</th></tr></thead>
            <tbody id="listBody"><tr><td colspan="7" style="text-align:center;color:#8a6d45;">載入中…</td></tr></tbody>
        </table>
        </div>
<?php endif; ?>
    </div>
</div>
</div>

<!-- 新增表單 modal -->
<div class="rf-mask" id="addMask"><div class="rf-modal" style="max-width:480px;">
    <div class="m-head"><span>新增表單</span><span class="m-close" onclick="closeMask('addMask')">✕</span></div>
    <div class="m-body">
        <label>選擇模板</label><select id="addTplSel" style="width:100%;"></select>
        <label>建立日期</label><input type="date" id="addBizDate" max="9999-12-31" style="width:100%;">
        <div id="addYearBox" style="display:none;">
            <label id="addYearLbl">年度</label><input type="text" id="addYear" style="width:100%;" data-eg-skip>
            <div id="addYearErr" style="color:#c0392b;font-size:12px;display:none;margin-top:2px;"></div>
        </div>
    </div>
    <div class="m-foot"><button class="b-cancel" onclick="closeMask('addMask')">取消</button><button class="b-ok" onclick="submitAdd()">建立</button></div>
</div></div>

<!-- 檢視/編輯 modal -->
<div class="rf-mask" id="viewMask"><div class="rf-modal">
    <div class="m-head"><span id="viewTitle">表單</span><span class="m-close" onclick="closeMask('viewMask')">✕</span></div>
    <div class="m-body" id="viewBody"></div>
</div></div>

<!-- 使用說明 modal（鐵律7） -->
<div class="rf-mask" id="helpUseMask"><div class="rf-modal" style="max-width:760px;">
    <div class="m-head"><span>使用說明 — 審核表單</span><span class="m-close" onclick="closeMask('helpUseMask')">✕</span></div>
    <div class="m-body help-doc" style="font-size:13px;color:#5b3a1e;line-height:1.8;">
        <h4>功能說明</h4>
        依「審核表單模板管理」建好的模板建立表單（首發：2-TD-04-01 仿冒零件防制審核表、2-TD-03-01 產品安全審核表），逐列填寫項目與模板定義的欄位，可指定負責單位/負責人並線上簽名，送出後依模板設定走審核/核准。
        <h4>操作步驟</h4>
        <b>①新增表單</b>：選擇模板、填建立日期，建立後進入草稿編輯畫面，「填表人」固定為建立者本人，表單名稱固定沿用模板名稱。<br>
        <b>②填寫項次</b>：用「+新增列」「-刪除末列」增減項目，逐列填寫內容與模板定義的欄位；可設定該列的負責單位（可多選，該部門任一主管簽即算完成）與負責人（可多選，每人都要各自簽）；有設定「相關日期」欄位的模板可逐列填寫。每個項目可用「+小項」拆出多個小項（例如同一項目下有好幾點要分別敘述），每個小項的「項目」內容、自訂欄位、負責部門/負責人、簽名確認全部各自獨立填寫（負責人不同、各自簽自己的），項次編號只在該項目第一列顯示；日期／下拉選單欄位可在表頭一次選好值按「整欄套用」，快速套用到目前所有項目與小項的同一欄，不用逐列手動填相同值。<br>
        <b>③送出</b>：草稿階段可存檔或送出；送出後內容鎖定不可再編輯，依模板設定進入審核（審核部門任一主管審過即完成）→ 核准（依模板設定的核准優先序解析）。<br>
        <b>③-1 直式標題（矩陣式表單）</b>：模板若設定了「直式標題」（例：SWOT／組織處境分析表），表格最左欄會是模板固定好的<b>列標題</b>，與上方的欄位標題交叉成矩陣，左上角是斜線分隔的兩個維度名稱。這種表單<b>不能自己增減列</b>（沒有「+新增列」按鈕），只要填交叉格的內容即可；同一個列標題底下要分成好幾點時，按該列標題格內的「＋小項」。另外模板可以關閉「負責單位／負責人」欄，關閉時整個表單就不會出現負責人與簽名欄；各欄標題與左側列標題要置中還是靠左、要不要直書，都由模板逐欄設定。模板若開了「橫式標題下方那一列」，表格第一列會是一整排可填的格子——那一列<b>整張表單只填一次</b>（不是逐列）；若開了「直式標題右側那一欄」，每一列的列標題右邊會多一個可填的窄格，逐列各填各的。<br>
        <b>④負責人簽名</b>：模板設為「現場密碼簽名」時，畫面上各負責人可自行輸入本人密碼簽名；設為「通知回簽」時，送出後系統會通知負責人前來簽名。<br>
        <b>⑤列印</b>：完成或進行中都可列印，依模板設定的紙張大小（A4/A3）自動縮放至一頁，頁碼顯示於左下角、綁定的 AS 文件編號顯示於右下角，簽章一律蓋章並帶日期。<br>
        <b>⑥複製表單</b>：任何狀態的表單（含已完成）都可按「複製此表單」，以複製者本人的身分建立一份新草稿，項次內容比照原表單帶入，但不含簽名/審核/核准紀錄，需重新走一次流程。
        <h4>重要行為</h4>
        ・只有填表人本人可以編輯/送出自己的草稿；已送出的表單內容鎖定，不可再修改項次。<br>
        ・草稿只有填表人本人能刪除；已送出（含已完成）的表單一般人不可刪除，僅管理員能刪（會連同審核/核准紀錄一併移除，無法復原）。<br>
        ・審核/核准為 OR-gate：合格名單中任一人處理即完成該關，其餘人之後看到的會是唯讀狀態。<br>
        ・核准人解析到送出表單的本人時會自動跳下一順位，不會球員兼裁判。
        <h4>權限角色</h4>
        審核表單檢閱＝看清單（僅看自己建立的）；檢視全部＝看全部人建立的表單；審核表單建立＝新增/填寫/送出；模板管理＝可另到「模板管理」頁設定；管理者全權。
    </div>
    <div class="m-foot"><button class="b-ok" onclick="closeMask('helpUseMask')">關閉</button></div>
</div></div>

<script src="../../resource/js/jquery.min.js"></script>
<script src="../../resource/js/bootstrap.min.js"></script>
<script src="../../resource/js/nprogress.js"></script>
<script src="../../resource/js/custom.min.js"></script>
<script src="../../resource/js/eg_input_rules.js?v=<?= @filemtime(__DIR__.'/../../resource/js/eg_input_rules.js') ?>"></script>
<script src="../../resource/js/eg_stamp.js?v=<?= @filemtime(__DIR__.'/../../resource/js/eg_stamp.js') ?>"></script>
<script src="../../resource/js/eg_stamp_tpl.js?v=<?= @filemtime(__DIR__.'/../../resource/js/eg_stamp_tpl.js') ?>"></script>
<script src="../../resource/js/eg_date_fmt.js?v=<?= @filemtime(__DIR__.'/../../resource/js/eg_date_fmt.js') ?>"></script>
<script>
var API = '../../src/store/ReviewForm_API.php';
var META = {}, TEMPLATES = [], ITEMS = [], CUR = null, CUR_SCHEMA = null;
var PREVIEW_MODE = (new URLSearchParams(location.search).get('preview') === '1');
function esc(s){ return $('<div>').text(s==null?'':s).html(); }
function dispDate(d){ return (typeof egFmtDate === 'function') ? egFmtDate(d) : (d||''); }
// 欄位標題可在「項次欄位定義」用 Enter 手動換行(最多3行)，這裡把換行字元轉成 <br>；
// 手動換行後若欄位仍太窄導致真正列印時還是擠爆，由 egPrintWindow() 內的自動縮小接手（2026-08-14）。
/* 使用者在多行文字欄位打的分段，列印時要照樣換行（2026-09-10 使用者實測回報：印出來全部黏成一段）。
   textarea 的值裡是真正的換行字元，HTML 不會自己斷行，一定要轉成 <br>。 */
function nl2brEsc(v){ return esc(v==null?'':String(v)).split(/\r\n|\r|\n/).join('<br>'); }
function hdrLabelHtml(label, vertical){
    var t = esc(label||'').replace(/\n/g,'<br>');
    // 標題直書（2026-09-09）：writing-mode 讓文字由上而下、CJK 字元維持正立，欄寬可以很窄。
    return vertical ? '<span class="hdr-vert">'+t+'</span>' : t;
}
/* 標題置中與否逐欄可設（2026-09-09 使用者明確要求）；沒設定過的欄位一律視同置中＝維持既有外觀。 */
function hdrCentered(c){ return (c.hdr_center===undefined) ? true : !!Number(c.hdr_center); }
/* 兩個方向都要明講：填寫畫面的佈景主題把 th 設成 text-align:left（不是瀏覽器預設的置中），
   列印版的 CSS 又是置中，只寫其中一邊會讓「置中」在畫面上完全沒有作用（實測發現）。 */
function hdrThAttr(c){ return ' style="text-align:' + (hdrCentered(c) ? 'center' : 'left') + ';"'; }
/* ---- 表格結構（2026-09-09 新增；舊模板的 schema 沒有這些鍵，一律退回原本行為＝有負責人欄、列由使用者自行增減） ---- */
function schemaNeedOwner(s){ s = s || CUR_SCHEMA || {}; return s.need_owner===undefined ? true : !!Number(s.need_owner); }
function schemaFixedRows(s){ s = s || CUR_SCHEMA || {}; return s.row_mode==='fixed' && !!(s.row_headings||[]).length; }
function schemaRowHeads(s){ s = s || CUR_SCHEMA || {}; return schemaFixedRows(s) ? (s.row_headings||[]) : []; }
/* 沒有負責單位/負責人就沒有可以簽名的對象，簽名欄一律不出現（後端 rvf_schema_sign_mode() 同一套判定）。 */
function schemaSignMode(s){ s = s || CUR_SCHEMA || {}; return schemaNeedOwner(s) ? (s.sign_mode||'password') : 'none'; }
/* 左上角斜線標題：右上＝欄的維度名稱、左下＝列的維度名稱（比照組織處境分析表紙本）。
   斜線用 inline SVG 畫（不是 CSS 漸層），列印時線條一定印得出來、不受背景色列印設定影響。 */
function cornerCellHtml(s){
    s = s || CUR_SCHEMA || {};
    var c = $.trim(s.corner_col_label||''), r = $.trim(s.corner_row_label||'');
    if (!c && !r) return '項目';
    return '<svg class="cor-line" viewBox="0 0 100 100" preserveAspectRatio="none">'
         + '<line x1="0" y1="0" x2="100" y2="100" stroke="currentColor" stroke-width="1" vector-effect="non-scaling-stroke"/></svg>'
         + '<span class="cor-col">'+esc(c)+'</span><span class="cor-row">'+esc(r)+'</span>';
}
/* 兩個可選的「額外可填入區」（2026-09-09 使用者明確要求，紙本組織處境分析表就長這樣）：
   ①橫式標題下方多一列 —— 每個欄位各一格，整張表單只有一組值，存 rf_instance.head_data_json
   ②直式標題右側多一欄 —— 逐列各一格，存該列第一個小項 data 的保留鍵 __rowside（不是 schema 定義的欄位，
     所以不會被必填檢查掃到，也不會跟自訂欄位 key 撞名）。兩個都預設關閉＝跟原本完全一樣。 */
var RVF_ROWSIDE_KEY = '__rowside';
function schemaHeadRow(s){ s = s || CUR_SCHEMA || {}; return !!Number(s.head_row||0); }
function schemaRowSide(s){ s = s || CUR_SCHEMA || {}; return !!Number(s.row_side||0) && schemaFixedRows(s); }
function headDataGet(key){ return (HEAD_DATA && HEAD_DATA[key]) || ''; }
function headDataEdit(key, val){ HEAD_DATA[key] = val; }
// 用事件委派而不是 inline onchange：欄位 key 是使用者自訂的字串（可能含引號），組進 onchange 屬性裡會把 HTML 打壞。
$(document).on('change input', '#itmBody [data-head-key]', function(){ headDataEdit($(this).data('head-key'), this.value); fillAutoGrow(this); });
$(document).on('change input', '#itmBody [data-rowside]', function(){ rowSideEdit(parseInt($(this).data('rowside'),10), this.value); fillAutoGrow(this); });
/* 這兩區的內容多半是「1、…2、…3、…」這種條列，所以用會自動長高的多行文字框：
   打幾行就多高，不必自己拉，也不會像單行輸入框那樣把後面的字藏起來（2026-09-10 使用者明確要求）。 */
function fillAutoGrow(el){ if (!el) return; el.style.height='auto'; el.style.height=(el.scrollHeight+2)+'px'; }
function fillAutoGrowAll(){ $('#itmBody textarea.fill-auto').each(function(){ fillAutoGrow(this); }); }
function rowSideEdit(i, val){ if (ITEMS[i] && ITEMS[i].subitems[0]) ITEMS[i].subitems[0].data[RVF_ROWSIDE_KEY] = val; }
function rowSideGet(i){ var it = ITEMS[i]; return (it && it.subitems[0] && it.subitems[0].data[RVF_ROWSIDE_KEY]) || ''; }
function rowHeadCentered(s){ s = s || CUR_SCHEMA || {}; return (s.row_head_center===undefined) ? true : !!Number(s.row_head_center); }
function rowHeadHtml(i, s){
    s = s || CUR_SCHEMA || {};
    var txt = (schemaRowHeads(s)[i] || '');
    return Number(s.row_head_vertical||0) ? '<span class="hdr-vert">'+esc(txt)+'</span>' : esc(txt).replace(/\n/g,'<br>');
}
// 年度標題顯示：內部一律存西元年，依模板設定的格式換算顯示成西元或民國年（2026-08-14）。
function yearDisplay(adYear, fmt){ if (!adYear) return ''; return fmt==='roc' ? ((adYear-1911)+'年') : (adYear+'年'); }
// 負責部門/人員配對顯示：依每個人員實際所屬部門(dept_ids，含兼任)比對是否屬於已選部門之一，
// 有就配對成一行「部門 / 人員」；配對不到的部門單獨顯示部門名；配對不到任何部門的人員單獨顯示人名。
function ownerPairLines(deptIds, userIds){
    var lines = [];
    var usedUserIds = {};
    (deptIds||[]).forEach(function(did){
        var d = (META.departments||[]).find(function(x){ return String(x.id)===String(did); });
        var dName = d ? d.name : '';
        var matched = (userIds||[]).filter(function(uid){
            var p = (META.people||[]).find(function(x){ return String(x.id)===String(uid); });
            var pDeptIds = (p && (p.dept_ids && p.dept_ids.length ? p.dept_ids : [p.dept_id])) || [];
            return pDeptIds.map(String).indexOf(String(did)) >= 0;
        });
        if (matched.length) {
            matched.forEach(function(uid){
                usedUserIds[uid] = true;
                var p = (META.people||[]).find(function(x){ return String(x.id)===String(uid); });
                lines.push(dName + ' / ' + (p ? p.user_cname : ''));
            });
        } else {
            lines.push(dName);
        }
    });
    (userIds||[]).forEach(function(uid){
        if (usedUserIds[uid]) return;
        var p = (META.people||[]).find(function(x){ return String(x.id)===String(uid); });
        lines.push(p ? p.user_cname : '');
    });
    return lines.filter(Boolean);
}
function openMask(id){ $('#'+id).css('display','block'); }
function closeMask(id){ $('#'+id).css('display','none'); }
$(document).ready(function(){
    var $am = $('#sidebar-menu .nav.side-menu > li.active');
    if ($am.length) { $am.removeClass('active').find('ul.child_menu').hide(); $am.find('li.current-page').removeClass('current-page'); }
    $('#sidebar-menu').css('visibility','visible');
    if (PREVIEW_MODE) { $('.rf-toolbar,.rf-table-wrap').hide(); $('h2').text('審核表單 — 試填預覽'); }
});
$('#btnPageHelp').on('click', function(){ openMask('helpUseMask'); });
var STATUS_LABEL = {draft:'草稿', submitted:'已送出', reviewing:'審核中', approving:'核准中', approved:'已完成', rejected:'已退回', void:'已作廢'};
/* 存檔/送出等按鈕若連線中斷或伺服器回傳非 JSON（如 PHP 警告混進輸出），$.post 的 success callback 完全不會觸發，
   畫面就會看起來「按了沒反應」，使用者無從得知到底存了沒（2026-08-13 使用者實際回報過一次）。
   統一在這裡攔截，讓任何 ajax 失敗都至少會跳出提示，不會悄悄無聲失敗。 */
$(document).ajaxError(function(e, jqxhr, settings){
    if (String(settings.url||'').indexOf('ReviewForm_API.php')<0) return;
    alert('連線或伺服器發生錯誤，請重新整理頁面確認資料是否已存檔，再重試一次。');
});

function loadMeta(cb){
    $.getJSON(API, {action:'meta'}, function(res){
        if (!res.ok){ alert(res.error||'載入失敗'); return; }
        META = res;
        window.__ownCompany = META.company_name || '';   // eg_stamp.js 預設回墨印章讀這個全域變數印公司名，比照 meeting_record.php
        if (META.perms.canCreate) $('#btnAdd').show();
        if (cb) cb();
    });
}
function loadTemplates(cb){
    $.getJSON('../../src/store/ReviewForm_API.php', {action:'template_list'}, function(res){
        if (!res.ok) return;
        /* 停用（archived）的模板：不可再用來建立新表單，但用它建立的既有表單照舊要看得到，
           所以「篩選」下拉仍列出（標示已停用），只有「新增表單」的下拉排除。 */
        TEMPLATES = (res.templates||[]).filter(function(t){ return t.status==='active'; });
        var opts = TEMPLATES.map(function(t){ return '<option value="'+t.id+'">'+esc(t.name)+'</option>'; }).join('');
        var filterOpts = (res.templates||[]).map(function(t){
            return '<option value="'+t.id+'">'+esc(t.name)+(t.status==='archived'?'（已停用）':'')+'</option>';
        }).join('');
        $('#tplFilter').html('<option value="0">全部</option>'+filterOpts);
        $('#addTplSel').html(opts);
        if (cb) cb();
    });
}
$('#btnAdd').on('click', function(){
    $('#addBizDate').val(META.today);
    $('#addYear').val('');
    updateAddYearBox();
    openMask('addMask');
});
/* 年度標題（2026-08-14 使用者明確要求）：模板勾選「有年度標題」時，新建表單多一個年度輸入框，
   依模板設定的格式(西元年/民國年)提示，即時檢查年度需落在建立日期年份的前一年～後一年之間，內部一律存西元年。 */
function curAddTpl(){ return (TEMPLATES||[]).find(function(x){ return String(x.id)===String($('#addTplSel').val()); }); }
function yearAdFromInput(v, fmt){ var n = parseInt(v,10); if (isNaN(n)) return null; return fmt==='roc' ? n+1911 : n; }
function yearInputFromAd(adYear, fmt){ return fmt==='roc' ? (adYear-1911) : adYear; }
function updateAddYearBox(){
    var t = curAddTpl();
    var has = t && t.has_year_heading==1;
    $('#addYearBox').toggle(!!has);
    if (!has) return;
    var fmt = (t.schema && t.schema.year_format) || 'ad';
    $('#addYearLbl').text('年度（請輸入'+(fmt==='roc'?'民國年':'西元年')+'）');
    var bizYear = new Date($('#addBizDate').val()||META.today).getFullYear();
    if (!$.trim($('#addYear').val())) $('#addYear').val(yearInputFromAd(bizYear, fmt));
    validateAddYear();
}
function validateAddYear(){
    var t = curAddTpl();
    if (!t || t.has_year_heading!=1){ $('#addYearErr').hide(); return true; }
    var fmt = (t.schema && t.schema.year_format) || 'ad';
    var bizYear = new Date($('#addBizDate').val()||META.today).getFullYear();
    var ad = yearAdFromInput($('#addYear').val(), fmt);
    var ok = ad!==null && ad>=bizYear-1 && ad<=bizYear+1;
    $('#addYearErr').text('年度需在建立日期年份的前一年到後一年之間').toggle(!ok);
    return ok;
}
$('#addTplSel').on('change', updateAddYearBox);
$('#addBizDate').on('change', updateAddYearBox);
$('#addYear').on('input', validateAddYear);
function submitAdd(){
    var tid = $('#addTplSel').val();
    if (!tid){ alert('請選擇模板'); return; }
    var t = curAddTpl(), yearHeading = null;
    if (t && t.has_year_heading==1) {
        if (!validateAddYear()){ alert('請輸入正確的年度'); return; }
        yearHeading = yearAdFromInput($('#addYear').val(), (t.schema && t.schema.year_format) || 'ad');
    }
    $.post(API, {action:'instance_create', csrf:META.csrf, template_id:tid, business_date:$('#addBizDate').val(), year_heading:yearHeading}, function(res){
        if (!res.ok){ alert(res.error||'建立失敗'); return; }
        closeMask('addMask'); loadList(); openView(res.id);
    }, 'json');
}
$('#tplFilter').on('change', loadList);
function loadList(){
    $.getJSON(API, {action:'instance_list', template_id:$('#tplFilter').val()||0}, function(res){
        if (!res.ok){ alert(res.error||'載入失敗'); return; }
        var h = '';
        res.instances.forEach(function(r){
            h += '<tr><td>#'+r.id+'</td><td>'+esc(r.template_name)+'</td><td>'+dispDate(r.business_date)+'</td>'
               + '<td>'+esc(r.created_by_name)+'</td><td><span class="st-badge st-'+r.status+'">'+STATUS_LABEL[r.status]+'</span></td>'
               + '<td><button onclick="openView('+r.id+')">開啟</button></td></tr>';
        });
        $('#listBody').html(h || '<tr><td colspan="7" style="text-align:center;color:#8a6d45;padding:10px;">尚無資料</td></tr>');
    });
}

/* ============ 檢視/編輯 ============ */
function openView(id){
    $.getJSON(API, {action:'instance_get', id:id}, function(res){
        if (!res.ok){ alert(res.error||'載入失敗'); return; }
        CUR = res.instance; CUR_SCHEMA = res.schema; CUR.tpl = res.template;
        CUR.as_doc_no = res.as_doc_no; CUR.company_name = res.company_name;
        CUR.review = res.review; CUR.approval = res.approval; CUR.can_review = res.can_review; CUR.can_approve = res.can_approve;
        ITEMS = (res.items||[]).map(function(it){
            return {id:it.id, subitems: (it.subitems||[]).map(function(s){
                return {id:s.id, content:s.content||'', data:s.data||{},
                         owner_depts:(s.owner_depts?String(s.owner_depts).split(',').filter(Boolean):[]),
                         owner_users:(s.owner_users?String(s.owner_users).split(',').filter(Boolean):[]),
                         confirms:s.confirms||[], required_signers:s.required_signers||[], fully_signed:s.fully_signed};
            })};
        });
        try { HEAD_DATA = JSON.parse(CUR.head_data_json||'{}') || {}; } catch(e){ HEAD_DATA = {}; }
        rvfPadFixedRows();
        $('#viewTitle').text('#'+CUR.id+' '+CUR.tpl.name+'（'+STATUS_LABEL[CUR.status]+'）');
        renderView();
        openMask('viewMask');
    });
}
var HEAD_DATA = {};
function isDraftMine(){ return CUR.status==='draft' && String(CUR.created_by)===String(META.uid); }
/* 直式標題模式：畫面上的列一律等於模板定義的列標題數量。正常情況後端 rvf_instance_create() 建表單當下就把列
   建好了，這裡是防呆——舊表單、或模板事後才改成直式標題時，列數對不上就在畫面上補齊（存檔時才真正寫進資料庫），
   不然會出現「有標題卻沒有可以填的格子」或「多出一列沒有標題的空白列」。 */
function rvfPadFixedRows(){
    if (!schemaFixedRows()) return;
    var need = schemaRowHeads().length;
    while (ITEMS.length < need) ITEMS.push({id:0, subitems:[rvfBlankSubitem()]});
    // 多出來的列只在「自己的草稿」才截掉（那時使用者還能重填）；已送出的表單寧可整列照顯示也不要把資料藏起來。
    if (ITEMS.length > need && isDraftMine()) ITEMS = ITEMS.slice(0, need);
}
function renderView(){
    var h = '';
    if (PREVIEW_MODE) h += '<div style="background:#FFF7E8;border:1px dashed #E8D5B5;border-radius:6px;padding:6px 10px;margin-bottom:10px;font-size:12.5px;color:#8a6d45;">'
        + '<i class="fa fa-flask"></i> 試填預覽模式：這裡的內容<b>不會儲存、不會建立實際表單資料</b>，純粹用來檢查目前欄位定義的排版與列印效果。關閉分頁即消失。</div>';
    h += '<div style="max-width:220px;"><label>建立日期</label><input type="date" id="vBizDate" max="9999-12-31" value="'+esc(CUR.business_date)+'" '+(isDraftMine()?'':'disabled')+'></div>';
    if (CUR.tpl.has_year_heading==1 && CUR.year_heading) {
        h += '<div style="color:#8a6d45;font-size:12.5px;margin-top:2px;">年度：'+yearDisplay(CUR.year_heading, (CUR_SCHEMA.year_format||'ad'))+'（建立時已固定，不可更改）</div>';
    }
    if (!PREVIEW_MODE && CUR.status!=='draft' && CUR.submit_date) {
        h += '<div style="margin-top:4px;font-size:12.5px;color:#8a6d45;">送出日：'+dispDate(CUR.submit_date)+
             (META.perms.isAdmin ? ' <a href="javascript:void(0)" onclick="editSubmitDate()" style="margin-left:6px;">（超級管理員：修改送出日）</a>' : '')+'</div>';
    }
    // 直式標題（fixed）模式：最左欄改成模板定義的固定列標題，表頭那格＝左上角斜線維度名稱，序號欄不顯示（列標題本身就是識別）。
    var fixedRows = schemaFixedRows();
    h += '<div class="itm-tbl-wrap"><table class="itm-tbl"><thead><tr>';
    var cornerMerge = fixedRows && schemaHeadRow() && !$.trim(CUR_SCHEMA.head_row_label||'');
    // 右側那一欄沒有自己的標題時，斜線角格就往右延伸把它一起蓋住（紙本就是一個涵蓋左邊整塊的大斜線格），
    // 不要在它上面留一個空的儲存格（2026-09-10 使用者明確要求）。有填標題文字時才單獨一格。
    var sideNoHdr = schemaRowSide() && !$.trim(CUR_SCHEMA.row_side_label||'');
    var cornerSpan = sideNoHdr ? 2 : 1;
    h += fixedRows ? '<th class="rf-corner'+(cornerMerge?' corner-merge':'')+'" colspan="'+cornerSpan+'">'+cornerCellHtml()+'</th>' : '<th style="width:26px;text-align:center;">#</th><th style="text-align:center;">項目</th>';
    if (schemaRowSide() && !sideNoHdr) h += '<th style="text-align:center;">'+esc(CUR_SCHEMA.row_side_label||'')+'</th>';
    (CUR_SCHEMA.fields||[]).forEach(function(c){ if (c.layout!=='block') h += '<th'+hdrThAttr(c)+'>'+hdrLabelHtml(c.label, c.vertical)+colFillHtml(c)+'</th>'; });
    if (schemaNeedOwner()) h += '<th>負責單位/負責人</th>';
    if (schemaSignMode()!=='none') h += '<th>簽名</th>';
    h += ((isDraftMine() && !fixedRows)?'<th></th>':'')+'</tr></thead><tbody id="itmBody"'+(fixedRows?'':' data-eg-row-add="itemAdd" data-eg-row-del="itemDelLast"')+'></tbody></table></div>';
    // 固定列模式下列由模板決定，不提供增減列（使用者拍板「完全固定」）。
    if (isDraftMine() && !fixedRows) h += '<button class="rf-btn-sm" onclick="itemAdd()" style="margin-right:6px;">+新增列</button><button class="rf-btn-sm" onclick="itemDelLast()">-刪除末列</button>';
    else if (isDraftMine() && fixedRows) h += '<span style="color:#8a6d45;font-size:12px;">本表單的列由模板固定（'+schemaRowHeads().length+' 列），不可增減；同一列要分成多點時請按該列的「＋小項」。</span>';
    h += '<div style="margin-top:12px;">';
    if (PREVIEW_MODE) {
        h += '<span style="color:#8a6d45;font-size:12px;">試填後按下方「列印」即可查看實際排版；不需要送出或審核。</span>';
    } else if (isDraftMine()) {
        h += '<button class="btn-warm" style="height:32px;padding:0 14px;border-radius:4px;border:1px solid #d98a33;color:#fff;background:#F0A24B;" onclick="saveDraft()">存檔</button> '
           + '<button style="height:32px;padding:0 14px;border-radius:4px;border:1px solid #d98a33;background:#fff;color:#5b3a1e;" onclick="submitForm()">送出</button> '
           + '<button style="height:32px;padding:0 14px;border-radius:4px;border:1px solid #c23f28;background:#fff;color:#DD5138;" onclick="deleteForm()">刪除</button>';
    } else if (META.perms.canAdmin) {
        // 非草稿（已送出/審核中/已完成…）一般人不可刪，僅管理員可刪（比照後端 instance_delete 的守門邏輯）。
        h += '<button style="height:32px;padding:0 14px;border-radius:4px;border:1px solid #c23f28;background:#fff;color:#DD5138;" onclick="deleteForm()">管理員刪除</button>';
    }
    if (!PREVIEW_MODE && META.perms.canCreate) h += ' <button style="height:32px;padding:0 14px;border-radius:4px;border:1px solid #D8BE93;background:#fff;color:#5b3a1e;" onclick="duplicateForm()">複製此表單</button>';
    if (META.perms.canPrint || PREVIEW_MODE) h += ' <button style="height:32px;padding:0 14px;border-radius:4px;border:1px solid #D8BE93;background:#fff;color:#5b3a1e;" onclick="printForm()">列印</button>';
    h += '</div>';
    if (CUR.can_review) h += reviewBoxHtml('review');
    if (CUR.can_approve) h += reviewBoxHtml('approval');
    $('#viewBody').html(h);
    renderItems();
}
function reviewBoxHtml(kind){
    var lbl = kind==='review' ? '審核' : '核准';
    return '<div class="decide-box"><b>待您'+lbl+'</b><br><textarea id="note_'+kind+'" placeholder="退回原因（退回時必填）" style="width:100%;margin:6px 0;" rows="2"></textarea>'
         + '<button class="btn-warm" style="height:30px;padding:0 14px;border-radius:4px;border:1px solid #d98a33;color:#fff;background:#F0A24B;" onclick="decide(\''+kind+'\',\'approved\')">'+lbl+'通過</button> '
         + '<button style="height:30px;padding:0 14px;border-radius:4px;border:1px solid #c23f28;background:#fff;color:#DD5138;" onclick="decide(\''+kind+'\',\'rejected\')">退回</button></div>';
}
function decide(kind, decision){
    var note = $('#note_'+kind).val();
    if (decision==='rejected' && !$.trim(note)){ alert('退回必須填寫原因'); return; }
    $.post(API, {action:(kind==='review'?'review_decide':'approval_decide'), csrf:META.csrf, instance_id:CUR.id, decision:decision, note:note}, function(res){
        if (!res.ok){ alert(res.error||'處理失敗'); return; }
        loadList(); openView(CUR.id);
    }, 'json');
}

/* ---- 項次列 ---- */
function rvfBlankSubitem(){ return {id:0, content:'', data:{}, owner_depts:[], owner_users:[], confirms:[], required_signers:[], fully_signed:false}; }
function itemAdd(){ ITEMS.push({id:0, subitems:[rvfBlankSubitem()]}); renderItems(); }
function itemDelLast(){ if (ITEMS.length) ITEMS.pop(); renderItems(); }
function itemDel(i){ ITEMS.splice(i,1); renderItems(); }
/* 小項（2026-08-12 新增，使用者明確要求）：一個項目可拆多個小項，每個小項各自的「項目」內容、自訂欄位(下拉/日期/文字等)、
   負責部門/負責人、簽名確認全部各自獨立不合併（「項目」只是共用同一個項次編號的分組容器，見 renderItems()）。
   全站每個模板都自動具備此功能，不另設模板層級開關；小項數量不限，至少保留 1 筆（刪到剩 1 筆就不能再刪，
   要整組刪除請用該項次列的刪除鈕 itemDel）。 */
function subItemAdd(i){ if (ITEMS[i]) { ITEMS[i].subitems.push(rvfBlankSubitem()); renderItems(); } }
function subItemDel(i,k){ if (ITEMS[i] && ITEMS[i].subitems.length>1) { ITEMS[i].subitems.splice(k,1); renderItems(); } }
function subItemContentEdit(i,k,val){ if (ITEMS[i] && ITEMS[i].subitems[k]) ITEMS[i].subitems[k].content = val; }
function subItemFieldEdit(i,k,key,val){ if (ITEMS[i] && ITEMS[i].subitems[k]) ITEMS[i].subitems[k].data[key] = val; }
function subItemContentHtml(i,k,sub,n){
    var ta = '<textarea '+(isDraftMine()?'':'disabled')+' onchange="subItemContentEdit('+i+','+k+',this.value)">'+esc(sub.content)+'</textarea>';
    if (!isDraftMine()) return ta;
    var btns = '<div class="subitem-ctrl">';
    btns += '<span class="rf-mini-btn" onclick="subItemAdd('+i+')">+小項</span>';
    if (n>1) btns += '<span class="rf-mini-btn" onclick="subItemDel('+i+','+k+')">-此小項</span>';
    btns += '</div>';
    return ta+btns;
}
/* 直式標題模式的列標題格內控制鈕：列本身固定不可增刪，只保留「＋小項」讓同一個列標題底下拆成多點。 */
function subRowCtrlHtml(i,k,n){
    if (!isDraftMine()) return '';
    var btns = '<div class="subitem-ctrl"><span class="rf-mini-btn" onclick="subItemAdd('+i+')">+小項</span>';
    if (n>1) btns += '<span class="rf-mini-btn" onclick="subItemDel('+i+','+(n-1)+')">-末小項</span>';
    return btns+'</div>';
}
/* 項次(自動編號)欄位在有小項時只在第一列顯示數字、其餘小項列留空（使用者明確要求；欄位本身不合併，只是不重複顯示內容） */
function fieldInputHtml(i, k, c){
    var sub = ITEMS[i].subitems[k];
    var v = sub.data[c.key] || '';
    var cls = c.layout==='block' ? 'fld-block' : '';
    var lbl = c.layout==='block' ? '<div class="fld-lbl">'+hdrLabelHtml(c.label)+'</div>' : '';
    var dis = isDraftMine() ? '' : 'disabled';
    if (c.type==='seq') return '<div class="'+cls+'" style="text-align:center;font-weight:bold;color:#5b3a1e;">'+lbl+(k===0?(i+1):'')+'</div>';
    if (c.type==='date') return '<div class="'+cls+'">'+lbl+'<input type="date" max="9999-12-31" '+dis+' value="'+esc(v)+'" onchange="subItemFieldEdit('+i+','+k+',\''+c.key+'\',this.value)"></div>';
    if (c.type==='select') {
        var opts = '<option value="">'+(c.placeholder?esc(c.placeholder):'請選擇')+'</option>' + (c.options||[]).map(function(o){ return '<option value="'+esc(o)+'"'+(o===v?' selected':'')+'>'+esc(o)+'</option>'; }).join('');
        return '<div class="'+cls+'">'+lbl+'<select '+dis+' onchange="subItemFieldEdit('+i+','+k+',\''+c.key+'\',this.value)">'+opts+'</select></div>';
    }
    // 文字對齊（2026-08-14 使用者明確要求，只有單行/多行文字欄位有這個設定，預設靠左）
    var alignStyle = ' style="text-align:'+(c.align||'left')+';"';
    if (c.type==='textarea') return '<div class="'+cls+'">'+lbl+'<textarea'+alignStyle+' '+dis+' placeholder="'+esc(c.placeholder)+'" onchange="subItemFieldEdit('+i+','+k+',\''+c.key+'\',this.value)">'+esc(v)+'</textarea></div>';
    return '<div class="'+cls+'">'+lbl+'<input type="text"'+alignStyle+' '+dis+' placeholder="'+esc(c.placeholder)+'" value="'+esc(v)+'" onchange="subItemFieldEdit('+i+','+k+',\''+c.key+'\',this.value)"></div>';
}
/* 整欄套用（2026-08-12 新增，使用者明確要求）：日期／下拉選單欄位可在表頭選一次值，一鍵套用到目前所有項目、
   所有小項的這一欄，取代逐列手動填相同值。只在草稿本人編輯時顯示；只支援 date/select 兩種類型。 */
function colFillHtml(c){
    if (!isDraftMine()) return '';
    if (c.type==='date') {
        return '<div class="col-fill"><input type="date" max="9999-12-31" class="col-fill-inp" id="colFill_'+c.key+'" data-eg-skip>'
             + '<span class="rf-mini-btn" onclick="fillColumn(\''+c.key+'\')">整欄套用</span></div>';
    }
    if (c.type==='select') {
        var opts = '<option value="">（選項）</option>' + (c.options||[]).map(function(o){ return '<option value="'+esc(o)+'">'+esc(o)+'</option>'; }).join('');
        return '<div class="col-fill"><select class="col-fill-inp" id="colFill_'+c.key+'" data-eg-skip>'+opts+'</select>'
             + '<span class="rf-mini-btn" onclick="fillColumn(\''+c.key+'\')">整欄套用</span></div>';
    }
    return '';
}
function fillColumn(key){
    if (!isDraftMine()) return;
    var val = $('#colFill_'+key).val();
    if (!val) { alert('請先在表頭選擇要整欄套用的值'); return; }
    ITEMS.forEach(function(it){ (it.subitems||[]).forEach(function(s){ s.data[key] = val; }); });
    renderItems();
}
function deptTagHtml(i, k, ids){
    var tags = ids.map(function(id){ var d=(META.departments||[]).find(function(x){return String(x.id)===String(id);}); return d?'<span class="tg">'+esc(d.name)+'<i class="fa fa-times" onclick="ownerDeptDel('+i+','+k+',\''+id+'\')"></i></span>':''; }).join('');
    return '<div class="dp-pick itm-dp" data-i="'+i+'" data-k="'+k+'"><div class="dp-tags">'+tags+'</div>'+(isDraftMine()?'<input type="text" class="itm-dp-kw" placeholder="選部門…" data-eg-skip autocomplete="off"><div class="dp-list"></div>':'')+'</div>';
}
function userTagHtml(i, k, ids, deptIds){
    var tags = ids.map(function(id){ var p=(META.people||[]).find(function(x){return String(x.id)===String(id);}); return p?'<span class="tg">'+esc(p.user_cname)+'<i class="fa fa-times" onclick="ownerUserDel('+i+','+k+',\''+id+'\')"></i></span>':''; }).join('');
    var ph = (deptIds && deptIds.length) ? '只列該部門人員…' : '選人員…（未選部門，列全公司）';
    return '<div class="dp-pick itm-up" data-i="'+i+'" data-k="'+k+'"><div class="dp-tags">'+tags+'</div>'+(isDraftMine()?'<input type="text" class="itm-up-kw" placeholder="'+ph+'" data-eg-skip autocomplete="off"><div class="dp-list"></div>':'')+'</div>';
}
function ownerDeptDel(i,k,id){ var s=ITEMS[i].subitems[k]; s.owner_depts = s.owner_depts.filter(function(x){return String(x)!==String(id);}); renderItems(); }
function ownerUserDel(i,k,id){ var s=ITEMS[i].subitems[k]; s.owner_users = s.owner_users.filter(function(x){return String(x)!==String(id);}); renderItems(); }
/* .dp-list 用 position:fixed（見上方CSS註解），顯示前要用輸入框當下在畫面上的實際座標現算位置。 */
function showDpList($input, $list){
    var r = $input[0].getBoundingClientRect();
    $list.css({left: r.left, top: r.bottom + 2, minWidth: Math.max(r.width, 150)}).show();
}
$(document).on('scroll', '.itm-tbl-wrap', function(){ $('.dp-list').hide(); });
$(window).on('resize', function(){ $('.dp-list').hide(); });
$(document).on('click', function(e){ if (!$(e.target).closest('.dp-pick,.dp-list').length) $('.dp-list').hide(); });
$(document).on('focus input', '.itm-dp-kw', function(){
    var $p=$(this).closest('.itm-dp'), i=$p.data('i'), k=$p.data('k'), it=ITEMS[i]; if(!it) return;
    var sub=it.subitems[k]; if(!sub) return;
    var kw=$.trim($(this).val()).toLowerCase(), h='';
    (META.departments||[]).forEach(function(d){
        if (kw && d.name.toLowerCase().indexOf(kw)<0) return;
        var on=(sub.owner_depts||[]).some(function(x){return String(x)===String(d.id);});
        h += '<div data-id="'+d.id+'" style="'+(on?'color:#b0a390;':'')+'">'+(on?'✔ ':'')+esc(d.name)+'</div>';
    });
    var $list = $p.find('.dp-list').html(h||'<div style="color:#b0a390;">查無部門</div>');
    showDpList($(this), $list);
});
$(document).on('click', '.itm-dp .dp-list div[data-id]', function(){
    var $p=$(this).closest('.itm-dp'), i=$p.data('i'), k=$p.data('k'), it=ITEMS[i]; if(!it) return;
    var sub=it.subitems[k]; if(!sub) return;
    var id=String($(this).data('id')), idx=sub.owner_depts.findIndex(function(x){return String(x)===id;});
    if (idx>=0) sub.owner_depts.splice(idx,1); else sub.owner_depts.push(id);
    renderItems();
});
$(document).on('focus input', '.itm-up-kw', function(){
    var $p=$(this).closest('.itm-up'), i=$p.data('i'), k=$p.data('k'), it=ITEMS[i]; if(!it) return;
    var sub=it.subitems[k]; if(!sub) return;
    var kw=$.trim($(this).val()).toLowerCase(), h='';
    // 選了負責部門後，負責人只列該部門(可複選部門則為聯集)的人，避免在全公司名單裡大海撈針；
    // 未選部門時維持列出全公司（使用者要求：多個部門要逐一「選部門→選人」，不要一次把部門全選完才選人，
    // 這裡的過濾行為本身就是照著這個順序運作——先選的部門會立刻篩到位）。
    // 比對用 dept_ids（含兼任的所有部門，見 people_lib.php），不只比對主要部門 dept_id，
    // 否則兼任該部門的人在篩選時會消失，選不到（2026-08-12 使用者明確要求）。
    var deptFilter = (sub.owner_depts||[]).map(String);
    (META.people||[]).forEach(function(p){
        var pDeptIds = (p.dept_ids||[p.dept_id]).map(String);
        if (deptFilter.length && !pDeptIds.some(function(d){ return deptFilter.indexOf(d)>=0; })) return;
        if (kw && p.user_cname.toLowerCase().indexOf(kw)<0) return;
        var on=(sub.owner_users||[]).some(function(x){return String(x)===String(p.id);});
        h += '<div data-id="'+p.id+'" style="'+(on?'color:#b0a390;':'')+'">'+(on?'✔ ':'')+esc(p.display)+'</div>';
    });
    var $list = $p.find('.dp-list').html(h||'<div style="color:#b0a390;">'+(deptFilter.length?'此部門查無人員':'查無人員')+'</div>');
    showDpList($(this), $list);
});
$(document).on('click', '.itm-up .dp-list div[data-id]', function(){
    var $p=$(this).closest('.itm-up'), i=$p.data('i'), k=$p.data('k'), it=ITEMS[i]; if(!it) return;
    var sub=it.subitems[k]; if(!sub) return;
    var id=String($(this).data('id')), idx=sub.owner_users.findIndex(function(x){return String(x)===id;});
    if (idx>=0) sub.owner_users.splice(idx,1); else sub.owner_users.push(id);
    renderItems();
});
/* 簽名確認掛在小項本身（2026-08-12 改版，使用者明確要求：每個小項的負責人與簽名各自獨立），sub.id 是小項在資料庫的真實 id。 */
function signSlotsHtml(sub){
    if (!sub.required_signers || !sub.required_signers.length) return '<span style="color:#b0a390;font-size:11px;">未指派負責人</span>';
    var doneUids = (sub.confirms||[]).map(function(c){ return String(c.user_id); });
    return sub.required_signers.map(function(s){
        var done = doneUids.indexOf(String(s.id))>=0;
        var c = (sub.confirms||[]).find(function(x){ return String(x.user_id)===String(s.id); });
        if (done) return '<div class="sign-slot sign-yes">✓ '+esc(s.user_cname)+'（'+dispDate(c.signed_at)+'）</div>';
        if (String(s.id)===String(META.uid) && CUR.status!=='draft') {
            return '<div class="sign-slot"><b>'+esc(s.user_cname)+'</b><br><input type="text" inputmode="numeric" placeholder="本人密碼" class="sign-pw" data-subitem="'+sub.id+'" data-uid="'+s.id+'" style="width:80px;" data-eg-skip>'
                 + '<button onclick="doItemConfirm('+sub.id+','+s.id+')" style="height:22px;font-size:11px;">簽名</button></div>';
        }
        return '<div class="sign-slot sign-no">未簽：'+esc(s.user_cname)+'</div>';
    }).join('');
}
function doItemConfirm(subitemId, uid){
    var pw = $('.sign-pw[data-subitem="'+subitemId+'"][data-uid="'+uid+'"]').val();
    $.post(API, {action:'item_confirm', csrf:META.csrf, subitem_id:subitemId, user_id:uid, password:pw}, function(res){
        if (!res.ok){ alert(res.error||'簽名失敗'); return; }
        openView(CUR.id);
    }, 'json');
}
function renderItems(){
    var h = '';
    var inlineFields = (CUR_SCHEMA.fields||[]).filter(function(c){ return c.layout!=='block'; });
    var blockFields = (CUR_SCHEMA.fields||[]).filter(function(c){ return c.layout==='block'; });
    var hasSignCol = schemaSignMode()!=='none';
    var hasOwnerCol = schemaNeedOwner();
    var fixedRows = schemaFixedRows();
    // 負責部門/負責人/簽名/刪除鈕都不再合併（2026-08-12 改版：每個小項各自獨立負責人與簽名），
    // 每個小項自己一整列都是完整欄位，只有「項次」編號與「刪除整個項次」鈕只在該項目第一個小項列顯示。
    var hasSideCol = schemaRowSide();
    var blockColspan = (fixedRows?1:2) + (hasSideCol?1:0) + inlineFields.length + (hasOwnerCol?1:0) + (hasSignCol?1:0) + ((isDraftMine()&&!fixedRows)?1:0);
    /* 橫式標題下方的可填入列：整張表單只有一組值（一欄一格），所以放在 tbody 第一列、不放 thead
       （放 thead 的話列印分頁時每一頁都會再印一次已填的內容）。 */
    function headBandRowHtml(){
        if (!schemaHeadRow()) return '';
        var dis = isDraftMine() ? '' : 'disabled';
        var r = '<tr class="head-band">';
        var mergeUp = fixedRows && !$.trim(CUR_SCHEMA.head_row_label||'');
        var bandSideNoHdr = hasSideCol && !$.trim(CUR_SCHEMA.row_side_label||'');
        r += fixedRows ? '<td class="row-head'+(mergeUp?' merge-up':'')+'" colspan="'+(bandSideNoHdr?2:1)+'">'+esc(CUR_SCHEMA.head_row_label||'')+'</td>'
                       : '<td></td><td>'+esc(CUR_SCHEMA.head_row_label||'')+'</td>';
        if (hasSideCol && !bandSideNoHdr) r += '<td></td>';
        inlineFields.forEach(function(c){
            r += '<td><textarea class="fill-auto" rows="1" data-head-key="'+esc(c.key)+'" '+dis+'>'+esc(headDataGet(c.key))+'</textarea></td>';
        });
        if (hasOwnerCol) r += '<td></td>';
        if (hasSignCol) r += '<td></td>';
        if (isDraftMine() && !fixedRows) r += '<td></td>';
        return r + '</tr>';
    }
    // 直式標題模式：列標題儲存格用 rowspan 蓋住該列底下所有小項，每個小項都是完整的內容列（不再有「大項標題列」）。
    if (fixedRows) {
        var hFx = '';
        ITEMS.forEach(function(it,i){
            if (!it.subitems || !it.subitems.length) it.subitems = [rvfBlankSubitem()];
            var subs = it.subitems, n = subs.length;
            subs.forEach(function(sub,k){
                var rs = (blockFields.length?n*2:n);
                hFx += '<tr>';
                if (k===0) hFx += '<td class="row-head'+(rowHeadCentered()?'':' row-head-left')+'" rowspan="'+rs+'">'+rowHeadHtml(i)+subRowCtrlHtml(i,k,n)+'</td>';
                // 直式標題右側那一欄也是逐列一格，跟著列標題一起 rowspan（同一列的小項共用）
                if (k===0 && hasSideCol) hFx += '<td class="row-side" rowspan="'+rs+'"><textarea class="fill-auto" rows="1" data-rowside="'+i+'" '+(isDraftMine()?'':'disabled')+'>'+esc(rowSideGet(i))+'</textarea></td>';
                inlineFields.forEach(function(c){ hFx += '<td>'+fieldInputHtml(i,k,c)+'</td>'; });
                if (hasOwnerCol) hFx += '<td><div class="owner-lbl">負責部門</div>'+deptTagHtml(i,k,sub.owner_depts)+'<div class="owner-lbl">負責人</div>'+userTagHtml(i,k,sub.owner_users,sub.owner_depts)+'</td>';
                if (hasSignCol) hFx += '<td>'+signSlotsHtml(sub)+'</td>';
                hFx += '</tr>';
                if (blockFields.length) {
                    hFx += '<tr><td colspan="'+(blockColspan-1)+'">' + blockFields.map(function(c){ return fieldInputHtml(i,k,c); }).join('') + '</td></tr>';
                }
            });
        });
        $('#itmBody').html(headBandRowHtml() + (hFx || '<tr><td colspan="10" style="text-align:center;color:#8a6d45;">此模板未定義列標題</td></tr>'));
        fillAutoGrowAll();
        return;
    }
    // 有小項時，項次本身這一列(subitems[0]＝新增項次時原本就有的那一列)降級為純標題列：
    // 只有「項目」文字可填，其餘自訂欄位/負責部門/負責人/簽名全部不需要——因為大項只是標題，
    // 真正的內容與各自的負責人/簽名都在下面各個小項（2026-08-13 使用者明確要求）。
    var headingSpan = inlineFields.length + (hasOwnerCol?1:0) + (hasSignCol?1:0);
    ITEMS.forEach(function(it,i){
        if (!it.subitems || !it.subitems.length) it.subitems = [rvfBlankSubitem()];
        var subs = it.subitems, n = subs.length;
        subs.forEach(function(sub,k){
            var isHeading = (k===0 && n>1);
            h += '<tr><td class="subitem-num">'+(k===0?(i+1):'')+'</td>';
            h += '<td'+(isHeading?' class="subitem-heading"':'')+'>'+subItemContentHtml(i,k,sub,n)+'</td>';
            if (isHeading) {
                h += '<td colspan="'+headingSpan+'" class="subitem-heading-note">'+(isDraftMine()?'（大項標題，欄位/負責人/簽名由下方各小項各自填寫）':'')+'</td>';
            } else {
                inlineFields.forEach(function(c){ h += '<td>'+fieldInputHtml(i,k,c)+'</td>'; });
                if (hasOwnerCol) h += '<td><div class="owner-lbl">負責部門</div>'+deptTagHtml(i,k,sub.owner_depts)+'<div class="owner-lbl">負責人</div>'+userTagHtml(i,k,sub.owner_users,sub.owner_depts)+'</td>';
                if (hasSignCol) h += '<td>'+signSlotsHtml(sub)+'</td>';
            }
            if (isDraftMine()) h += '<td>'+(k===0?'<span class="rf-del" onclick="itemDel('+i+')" title="刪除整個項次(含全部小項)"><i class="fa fa-times"></i></span>':'')+'</td>';
            h += '</tr>';
            if (blockFields.length && !isHeading) {
                h += '<tr><td></td><td colspan="'+blockColspan+'">' + blockFields.map(function(c){ return fieldInputHtml(i,k,c); }).join('') + '</td></tr>';
            }
        });
    });
    $('#itmBody').html(headBandRowHtml() + (h || '<tr><td colspan="10" style="text-align:center;color:#8a6d45;">尚未建立項目</td></tr>'));
    fillAutoGrowAll();
}

function collectItems(){
    var fixedRows = schemaFixedRows(), needOwner = schemaNeedOwner();
    return ITEMS.map(function(it){
        var n = it.subitems.length;
        return {id:it.id, subitems: it.subitems.map(function(s,k){
            // 直式標題模式沒有「大項標題列」——列標題來自模板，每個小項都是完整的內容列。
            var isHeading = (!fixedRows && k===0 && n>1);
            // 大項標題列存檔時強制清空自訂欄位/負責部門/負責人（2026-08-13 使用者明確要求：
            // 有小項時大項只是標題，不需要這些值，避免殘留舊資料造成「簽不到卻要求簽名」的孤兒狀態）。
            // 直式標題模式的「項目」欄不存在（列標題來自模板 schema），content 一律空字串不佔資料；
            // 模板關掉負責單位/負責人時一律存空，避免殘留舊值變成「畫面看不到卻要求簽名」的孤兒狀態。
            return {id:s.id, content: fixedRows ? '' : s.content,
                     data: isHeading ? {} : s.data,
                     owner_depts: (isHeading || !needOwner) ? [] : s.owner_depts,
                     owner_users: (isHeading || !needOwner) ? [] : s.owner_users};
        })};
    });
}
function saveDraft(cb){
    $.post(API, {action:'instance_save_items', csrf:META.csrf, instance_id:CUR.id, business_date:$('#vBizDate').val(),
                 items:JSON.stringify(collectItems()), head_data:JSON.stringify(HEAD_DATA||{})}, function(res){
        if (!res.ok){ alert(res.error||'儲存失敗'); return; }
        if (cb) cb(); else { loadList(); openView(CUR.id); alert('已儲存'); }
    }, 'json');
}
/* 送出前檢查所有必填欄位都已填寫（2026-08-14 使用者明確要求；欄位是否必填在「項次欄位定義」逐欄設定，
   預設必填，管理員可個別勾選允許留空）。「項目」內容固定必填（含大項標題列，因為那是它唯一要填的欄位）；
   schema 自訂欄位只檢查 required=1 且非 seq 類型（seq 是自動編號，本來就沒有輸入值）。
   前端擋是即時體驗，後端 rvf_instance_submit() 會用同一套規則再驗一次，不可只做前端。 */
function findMissingRequiredFields(){
    var missing = [];
    var fixedRows = schemaFixedRows();
    ITEMS.forEach(function(it, i){
        var subs = it.subitems || [];
        subs.forEach(function(sub, k){
            // 直式標題模式的列名稱是模板固定的，沒有「項目」欄要填，改用列標題當提示文字。
            var label = fixedRows ? ('「'+(schemaRowHeads()[i]||('第'+(i+1)+'列'))+'」' + (subs.length>1 ? '第'+(k+1)+'小項' : ''))
                                  : ('第'+(i+1)+'項' + (subs.length>1 ? '第'+(k+1)+'小項' : ''));
            if (!fixedRows && !$.trim(sub.content||'')) missing.push(label+'「項目」內容');
            var isHeading = (!fixedRows && k===0 && subs.length>1);
            if (isHeading) return; // 標題列只需要項目內容，其餘欄位本來就返灰不填
            (CUR_SCHEMA.fields||[]).forEach(function(c){
                if (!c.required || c.type==='seq') return;
                var v = sub.data[c.key];
                if (v===undefined || v===null || $.trim(String(v))==='') missing.push(label+'「'+c.label+'」');
            });
        });
    });
    return missing;
}
/* 負責人所屬部門要能對到已選的負責部門之一，對不到就跳出提示要求設定（2026-08-14 使用者明確要求：
   「不知道部門的人員就自動抓取此人員所屬部門是否有符合上面設定的部門之一…沒有就跳出提示要求設定」）。
   比對用 dept_ids（含兼任）。只有小項同時有選負責部門時才檢查，未選部門(全公司名單挑人)不受此限。 */
function findOwnerDeptMismatch(){
    var msgs = [];
    if (!schemaNeedOwner()) return msgs; // 模板沒有負責單位/負責人欄，沒有東西要比對
    ITEMS.forEach(function(it, i){
        var subs = it.subitems || [];
        subs.forEach(function(sub, k){
            var subs2 = subs; var isHeading = (k===0 && subs2.length>1);
            if (isHeading) return;
            var deptIds = (sub.owner_depts||[]).map(String);
            if (!deptIds.length) return;
            (sub.owner_users||[]).forEach(function(uid){
                var p = (META.people||[]).find(function(x){ return String(x.id)===String(uid); });
                var pDeptIds = (p && (p.dept_ids && p.dept_ids.length ? p.dept_ids : [p.dept_id])) || [];
                var hit = pDeptIds.map(String).some(function(d){ return deptIds.indexOf(d)>=0; });
                if (!hit) {
                    var label = '第'+(i+1)+'項' + (subs2.length>1 ? '第'+(k+1)+'小項' : '');
                    msgs.push(label+'負責人「'+(p?p.user_cname:uid)+'」所屬部門與已選負責部門不符，請確認負責部門設定或改選正確人員');
                }
            });
        });
    });
    return msgs;
}
function submitForm(){
    var missing = findMissingRequiredFields();
    if (missing.length){ alert('以下必填欄位尚未填寫，請填完再送出：\n' + missing.join('\n')); return; }
    var mismatch = findOwnerDeptMismatch();
    if (mismatch.length){ alert('負責部門/人員設定有誤，請修正後再送出：\n' + mismatch.join('\n')); return; }
    saveDraft(function(){
        $.post(API, {action:'instance_submit', csrf:META.csrf, instance_id:CUR.id}, function(res){
            if (!res.ok){ alert(res.error||'送出失敗'); return; }
            loadList(); openView(CUR.id);
        }, 'json');
    });
}
function deleteForm(){
    var msg = CUR.status==='draft' ? '確定要刪除此草稿？' : '此表單狀態為「'+STATUS_LABEL[CUR.status]+'」，刪除後含審核/核准紀錄一併移除且無法復原，確定要刪除？';
    if (!confirm(msg)) return;
    $.post(API, {action:'instance_delete', csrf:META.csrf, instance_id:CUR.id}, function(res){
        if (!res.ok){ alert(res.error||'刪除失敗'); return; }
        closeMask('viewMask'); loadList();
    }, 'json');
}
function duplicateForm(){
    $.post(API, {action:'instance_duplicate', csrf:META.csrf, instance_id:CUR.id}, function(res){
        if (!res.ok){ alert(res.error||'複製失敗'); return; }
        loadList(); openView(res.id);
    }, 'json');
}
/* 補登舊資料用（ai-rules/21 鐵則2）：僅超級管理員看得到入口；回改後自動簽核紀錄的日期會同步跟著調整。 */
function editSubmitDate(){
    var d = prompt('修改送出日（僅影響此筆；自動簽核的紀錄會同步調整日期）：', CUR.submit_date||'');
    if (!d) return;
    if (!/^\d{4}-\d{2}-\d{2}$/.test(d)){ alert('請輸入 YYYY-MM-DD 格式的日期'); return; }
    $.post(API, {action:'instance_edit_submit_date', csrf:META.csrf, instance_id:CUR.id, submit_date:d}, function(res){
        if (!res.ok){ alert(res.error||'修改失敗'); return; }
        openView(CUR.id);
    }, 'json');
}

/* ============ 列印 ============ */
function egPrintWindow(title, bodyHtml, extraCss, docNo, paper, landscape){
    var asCss = String(docNo||'').replace(/['\\]/g,'');
    var css = '@page{size:'+(paper||'A4')+' '+(landscape?'landscape':'portrait')+';margin:12mm 8mm 16mm;}'
            + 'html,body{margin:0;padding:0;}'
            + 'body{font-family:"Microsoft JhengHei","微軟正黑體",sans-serif;color:#000;-webkit-print-color-adjust:exact;print-color-adjust:exact;}'
            + '.pt-head{position:relative;text-align:center;margin-bottom:6px;}.pt-head .co{font-size:22px;font-weight:bold;letter-spacing:2px;}.pt-head .tt{font-size:16px;font-weight:bold;margin-top:3px;letter-spacing:1px;}'
            + '.pt-head .yr{font-size:13px;color:#333;}.pt-head .yr-left{position:absolute;left:0;top:50%;transform:translateY(-50%);}.pt-head .yr-right{position:absolute;right:0;top:50%;transform:translateY(-50%);}.pt-head .yr-center{margin-top:3px;}'
            + '.rf-as-doc{position:fixed;right:8mm;bottom:6mm;font-size:9pt;color:#333;}'
            + (extraCss||'');
    var w = window.open('', '_blank');
    if (!w){ alert('請允許彈出視窗'); return; }
    // <!DOCTYPE html> 不可省略：少了它視窗會落入 Quirks Mode，<body> 內容不滿版時會被撐滿整個視窗高度
    // （document.body.scrollHeight 量出來永遠接近視窗高度而非實際內容高度），下面靠 scrollHeight 判斷單頁/多頁
    // 的頁碼顯示邏輯會失準，導致只有一頁的文件也顯示「第1頁／共1頁」（2026-08-14 使用者實測回報、絕對禁止；
    // 比照 training_record.php 既有正確寫法修正）。
    w.document.write('<!DOCTYPE html><html><head><meta charset="utf-8"><title>'+esc(title)+'</title><style>'+css+'</style></head><body>'
        + bodyHtml
        + (asCss ? '<div class="rf-as-doc">'+asCss+'</div>' : '')
        + '<scr'+'ipt>window.onload=function(){'
        // 2026-08-13 第四次修正：拿掉「整頁自動縮小塞進一頁」的 document.body.style.zoom 邏輯。
        // 這個縮放跟圖章的真實尺寸需求天生衝突——不管用反向補償 zoom 還是 fixed 圖層逃脫，圖章一旦
        // 「變回原尺寸」就會撐大所在儲存格，讓實際內容高度跟原本算縮放比例時的高度對不上，
        // 使用者兩輪實測都印出殘留縮小的圖章(1.4cm→1.9cm)。改成比照 training_record.php／print_pagination
        // 鐵則既有作法：分頁 100% 交給瀏覽器列印引擎原生處理，內容略超過一頁時自然分成第2頁，
        // 不再強行壓縮成一頁——圖章從此不會被任何縮放影響，永遠印出設計時的真實尺寸。
        // 量 scrollHeight 前先讓一拍：印章 SVG 內含 textLength/lengthAdjust 需要字型計量才能定案排版，
        // onload 觸發當下量到的高度有時還沒完全穩定。
        + 'setTimeout(function(){'
        // 欄位標題手動換行(最多3行)後，若欄寬還是太窄導致行數超過3行，逐步縮小該表頭字級直到符合
        // （只縮該 th 本身字級，不是整頁 zoom，不會影響圖章尺寸；2026-08-14 使用者明確要求）。
        // 2026-09-10 修正：原本量的是 th.scrollHeight，但表格儲存格的高度會被「同一列最高的那一格」撐大
        // （左上角斜線角格有固定高度），於是每個欄位標題都被誤判成換了很多行、一路縮到 8px 下限，
        // 實測比左側列標題小了 4px。改成量標題文字自己那層 .hdr-in 的高度，跟同列其他格無關。
        // 直書標題(.hdr-vert)是往下長不是往下換行，本來就不該套這個縮字邏輯，直接跳過。
        + 'document.querySelectorAll("th.hdr-auto").forEach(function(th){'
        + 'if(th.querySelector(".hdr-vert"))return;'
        + 'var inner=th.querySelector(".hdr-in")||th;'
        + 'var fs=12,minFs=8,tries=0;'
        + 'while(tries<16){th.style.fontSize=fs+"px";var lh=fs*1.25;var lines=inner.scrollHeight/lh;if(lines<=3.15||fs<=minFs)break;fs-=0.5;tries++;}'
        + '});'
        + 'var pageH=('+(landscape ? (paper==='A3'?'297':'210') : (paper==='A3'?'420':'297'))+'-28)*96/25.4;'
        // 頁碼只在超過一頁才顯示（ai-rules/16 第二節，比照 quotation_list_test.php／training_record.php 既有作法）：
        + 'if(document.body.scrollHeight>pageH*0.92){'
        + 'var st=document.createElement("style");'
        + 'st.textContent="@page{ @bottom-left{ content:\'第 \' counter(page) \' 頁／共 \' counter(pages) \' 頁\'; font-size:9pt; color:#333; } }";'
        + 'document.head.appendChild(st);'
        + '}'
        + 'window.print();'
        + '},120);};</scr'+'ipt></body></html>');
    w.document.close();
}
function rfCss(){
    // 2026-08-13 使用者要求整體改回收斂：表格一開始就設定在A4以內，文字縮小（15.5px→12px，padding 10px11px→6px7px）。
    return 'table.rf-p-items{width:100%;border-collapse:collapse;font-size:12px;margin-top:2px;}'
         + 'table.rf-p-items th,table.rf-p-items td{border:1px solid #333;padding:6px 7px;text-align:center;}'
         + 'table.rf-p-items td.t-left{text-align:left;}'
         // 直式標題（2026-09-09）：斜線角格用 inline SVG 畫線（不是 CSS 背景漸層，那在列印時會被「不印背景」的設定吃掉）；
         // 標題直書用 writing-mode，CJK 字元靠 text-orientation:upright 維持正立不躺著。
         + 'table.rf-p-items th.rf-corner{position:relative;padding:0;height:52px;min-width:96px;}'
         + 'table.rf-p-items th.rf-corner .cor-line{position:absolute;left:0;top:0;width:100%;height:100%;}'
         + 'table.rf-p-items th.rf-corner .cor-col{position:absolute;right:6px;top:3px;}'
         + 'table.rf-p-items th.rf-corner .cor-row{position:absolute;left:6px;bottom:3px;}'
         + 'table.rf-p-items th.rf-corner.corner-merge{border-bottom:none;}'
         + 'table.rf-p-items td.row-head.merge-up{border-top:none;}'
         + 'table.rf-p-items td.row-head{font-weight:bold;vertical-align:middle;white-space:pre-wrap;}'
         + 'table.rf-p-items td.row-head.row-head-left{text-align:left;}'
         + 'table.rf-p-items td.row-side{vertical-align:top;text-align:left;}'
         + '.hdr-vert{writing-mode:vertical-rl;text-orientation:upright;letter-spacing:2px;display:inline-block;white-space:nowrap;line-height:1.15;}'
         + '.rf-p-datebar{text-align:right;font-size:12px;color:#333;margin-bottom:3px;}'
         // 圖章尺寸直接比照 training_record.php 既有、使用者已驗證正確的寫法（不分有無指定圖章模板一律套用，
         // 不加 !important；SVG 本身的 width/height 屬性是「表現屬性」，樣式表選到就會蓋過去不需要 !important）。
         + '.stamp-wrap svg,svg.car-stamp{width:91px;height:91px;-webkit-print-color-adjust:exact;print-color-adjust:exact;}'
         + 'table.rf-p-foot{width:100%;margin-top:16px;margin-bottom:12mm;font-size:13px;}'
         + 'table.rf-p-foot td{padding:6px;width:33.33%;text-align:center;vertical-align:top;}'
         + 'table.rf-p-foot .foot-lbl{margin-bottom:4px;}'
         + 'table.rf-p-foot .foot-na{color:#888;font-size:12px;}'
         + 'table.rf-p-foot .stamp-wrap{margin:0;}';
}
/* 2026-08-13：直接比照 training_record.php，不再對「有無指定圖章模板」做區分處理——單純呼叫 EGStamp.stamp()，
   尺寸統一交給 rfCss() 的 .stamp-wrap svg 規則決定，維持最單純、已驗證過的寫法。 */
function stampOrName(name, date, isDeputy, schema){
    return (window.EGStamp && EGStamp.stamp) ? EGStamp.stamp(name, date, !!isDeputy, schema) : esc(name||'');
}
/* 兩種圖章樣式各自綁定：逐列簽章(list_stamp) 用在項目表每列負責人簽名；製表/審核/核准(footer_stamp) 用在頁尾三欄。
   模板沒設定時 schema 是 null，EGStamp.stamp 會自動退回預設樣式。 */
function stampList(name, date, isDeputy){ return stampOrName(name, date, isDeputy, CUR.tpl.list_stamp ? CUR.tpl.list_stamp.schema : null); }
function stampFooter(name, date, isDeputy){ return stampOrName(name, date, isDeputy, CUR.tpl.footer_stamp ? CUR.tpl.footer_stamp.schema : null); }
function printForm(){
    var t = CUR.tpl, schema = CUR_SCHEMA;
    // 年度標題：跟大標題(公司名/模板名稱)同一區塊顯示（2026-08-14 使用者明確確認位置語意）；
    // 左/右＝該區塊左右兩側（絕對定位不佔版面），置中＝模板名稱下方。
    var yearTxt = (t.has_year_heading==1 && CUR.year_heading) ? yearDisplay(CUR.year_heading, schema.year_format||'ad') : '';
    var yearPos = schema.year_position || 'left';
    var h = '<div class="pt-head">';
    if (yearTxt && yearPos!=='center') h += '<div class="yr yr-'+yearPos+'">'+esc(yearTxt)+'</div>';
    h += '<div class="co">'+esc(CUR.company_name||'')+'</div><div class="tt">'+esc(t.name)+'</div>';
    if (yearTxt && yearPos==='center') h += '<div class="yr yr-center">'+esc(yearTxt)+'</div>';
    h += '</div>';
    // 表頭不重複顯示狀態/填表人（2026-08-13 使用者明確要求：製表人姓名+日期下方本來就有「製表」圖章，不必再印一次；
    // 狀態對已完成的表單沒有意義），建立日期改印在項目表格右上角。
    h += '<div class="rf-p-datebar">建立日期：'+dispDate(CUR.business_date)+'</div>';
    var pHasSignCol = schemaSignMode(schema)!=='none';
    var pHasOwnerCol = schemaNeedOwner(schema);
    var pFixedRows = schemaFixedRows(schema);
    var inlineFieldsP = (schema.fields||[]);
    h += '<table class="rf-p-items"><thead><tr>';
    var pHasSideCol = schemaRowSide(schema);
    var pCornerMerge = pFixedRows && schemaHeadRow(schema) && !$.trim(schema.head_row_label||'');
    var pSideNoHdr = pHasSideCol && !$.trim(schema.row_side_label||'');
    h += pFixedRows ? '<th class="rf-corner'+(pCornerMerge?' corner-merge':'')+'" colspan="'+(pSideNoHdr?2:1)+'">'+cornerCellHtml(schema)+'</th>' : '<th>#</th><th>項目</th>';
    if (pHasSideCol && !pSideNoHdr) h += '<th>'+esc(schema.row_side_label||'')+'</th>';
    inlineFieldsP.forEach(function(c){ h += '<th class="hdr-auto"'+hdrThAttr(c)+'><div class="hdr-in">'+hdrLabelHtml(c.label, c.vertical)+'</div></th>'; });
    h += (pHasOwnerCol?'<th>負責單位/人</th>':'')+(pHasSignCol?'<th>簽名</th>':'')+'</tr></thead><tbody>';
    var headingSpanP = inlineFieldsP.length + (pHasOwnerCol?1:0) + (pHasSignCol?1:0);
    if (schemaHeadRow(schema)) {
        // 一律用畫面上當下的 HEAD_DATA（含還沒按存檔的新字），不可以回頭去讀 CUR.head_data_json——
        // 那是「載入這張表單當下」的舊值，剛打完就按列印會整列印不出來（2026-09-10 使用者實測回報）。
        var pHead = HEAD_DATA || {};
        h += '<tr>';
        var pMergeUp = pFixedRows && !$.trim(schema.head_row_label||'');
        h += pFixedRows ? '<td class="row-head'+(pMergeUp?' merge-up':'')+'" colspan="'+(pSideNoHdr?2:1)+'">'+esc(schema.head_row_label||'')+'</td>'
                        : '<td></td><td class="t-left">'+esc(schema.head_row_label||'')+'</td>';
        if (pHasSideCol && !pSideNoHdr) h += '<td></td>';
        inlineFieldsP.forEach(function(c){ h += '<td style="text-align:'+((c.type==='text'||c.type==='textarea')?(c.align||'left'):'center')+';">'+nl2brEsc(pHead[c.key]||'')+'</td>'; });
        if (pHasOwnerCol) h += '<td></td>';
        if (pHasSignCol) h += '<td></td>';
        h += '</tr>';
    }
    ITEMS.forEach(function(it,i){
        var subs = (it.subitems&&it.subitems.length) ? it.subitems : [rvfBlankSubitem()];
        var n = subs.length;
        subs.forEach(function(sub,k){
            // 直式標題模式：列標題格用 rowspan 蓋住該列所有小項，沒有「大項標題列」。
            var isHeading = (!pFixedRows && k===0 && n>1);
            h += '<tr>';
            if (pFixedRows) h += (k===0 ? '<td class="row-head'+(rowHeadCentered(schema)?'':' row-head-left')+'" rowspan="'+n+'">'+rowHeadHtml(i, schema)+'</td>' : '');
            if (pHasSideCol && k===0) h += '<td class="row-side" rowspan="'+n+'">'+nl2brEsc(rowSideGet(i))+'</td>';
            else h += '<td>'+(k===0?(i+1):'')+'</td><td class="t-left">'+esc(sub.content).replace(/\n/g,'<br>')+'</td>';
            if (isHeading) {
                // 有小項時大項這一列只是標題，其餘欄位整列合併成一個空白儲存格（2026-08-13 使用者明確要求；
                // 項次已經在最前面單獨一格，這裡的合併不含項次欄，符合「除了項次外都合併」）。
                h += '<td colspan="'+headingSpanP+'"></td></tr>';
                return;
            }
            inlineFieldsP.forEach(function(c){
                var cellTxt = c.type==='seq' ? (k===0?String(i+1):'') : (c.type==='date' ? dispDate(sub.data[c.key]||'') : nl2brEsc(sub.data[c.key]||''));
                var cellAlign = (c.type==='text'||c.type==='textarea') ? (c.align||'left') : 'center';
                h += '<td style="text-align:'+cellAlign+';">'+cellTxt+'</td>';
            });
            // 負責單位/人改成依人員實際所屬部門逐一配對顯示（2026-08-14 使用者明確要求），
            // 例：負責部門[生產部,生產2廠,生產3廠]+負責人[林鴻銘,李汪達,陳智民]，
            // 各自比對每個人員的 dept_ids 屬於哪個已選部門，印成「生產部 / 林鴻銘」「生產2廠 / 李汪達」…逐行顯示，
            // 而不是把部門、人員各自攤平成一整串再用「/」分隔。見 ownerPairLines()。
            // 每行「部門 / 人員」本身不可斷行（2026-08-14 使用者實測回報：欄位太窄時姓名被硬拆成「陳俊」「宏」兩行），
            // 只在多組部門/人員之間換行；nowrap 讓瀏覽器在該欄位寬度不夠時自動撐開欄寬，不會拆字。
            if (pHasOwnerCol) h += '<td>'+ownerPairLines(sub.owner_depts, sub.owner_users).map(function(l){ return '<span style="white-space:nowrap;">'+esc(l)+'</span>'; }).join('<br>')+'</td>';
            if (pHasSignCol) {
                var signHtml = (sub.confirms||[]).map(function(c){ return stampList(c.user_name, dispDate(c.signed_at)); }).join('');
                if (!signHtml && PREVIEW_MODE && (sub.owner_depts.length || sub.owner_users.length)) signHtml = stampList('（簽名樣式預覽）', dispDate(CUR.business_date));
                h += '<td>'+signHtml+'</td>';
            }
            h += '</tr>';
        });
    });
    h += '</tbody></table>';
    // 三顆章的日期一律印「建立日期」（CUR.business_date），不是簽核當下的實際系統時間（ai-rules/18 第4條既有
    // 全站慣例：簽章日期＝該單據的業務日期；2026-08-13 使用者實測回報審核/核准章印出系統時間、跟製表章的
    // 建立日期對不起來才發現這裡沒有跟上慣例，decided_at 仍照實記錄精確時間戳，只是不拿來當章面顯示值）。
    // 簽章欄由左到右固定「核准、審核、製表」（2026-08-14 使用者明確更正：先前做反了；A4橫式全站簽核流程
    // 一律比照此順序——最左側＝核准(或本張單上的最高簽核人員)，中間＝審核(與製表同部門主管或設定好的部門內主管)，
    // 最右側＝製表，詳見 ai-rules/18 新規則）。
    // 免審核／免核准的模板，那一格整個不要出現——不印標題也不印任何說明文字（2026-09-10 使用者明確要求：
    // 「免審核的情況就不要出現審核字樣，也不需多加解釋」）。剩下幾格就平均分配寬度。
    var footCells = [];
    if (t.need_approval) footCells.push(['核准', (CUR.approval && CUR.approval.status==='approved') ? stampFooter(CUR.approval.approver_name, dispDate(CUR.business_date)) : '']);
    if (t.need_review)   footCells.push(['審核', (CUR.review && CUR.review.status==='approved') ? stampFooter(CUR.review.approver_name, dispDate(CUR.business_date)) : '']);
    footCells.push(['製表', stampFooter(CUR.created_by_name, dispDate(CUR.business_date))]);
    h += '<table class="rf-p-foot"><tr>';
    footCells.forEach(function(c){ h += '<td style="width:'+(100/footCells.length).toFixed(2)+'%;"><div class="foot-lbl">'+esc(c[0])+'</div>'+c[1]+'</td>'; });
    h += '</tr></table>';
    egPrintWindow(t.name, h, rfCss(), CUR.as_doc_no, t.paper_size, t.orientation!=='portrait');
}

/* 試填預覽入口：①由 review_form_template.php 的「試填預覽並列印」開新分頁帶 ?preview=1 進來，讀
   sessionStorage 裡未存檔的欄位定義（畫面上正在編輯、可能還沒存檔的草稿）；②由 as_document_management.php
   的文件列表直接帶 ?preview=1&tpl_id=X 進來（2026-08-14 使用者明確要求串接），這種情況沒有 sessionStorage
   草稿，改用該模板目前「已存檔」的欄位定義。兩種都不呼叫 instance_get，不建立任何 rf_instance 資料列；
   存檔/送出/刪除/審核/核准一律不顯示，只留增減列與列印可用。 */
function initPreview(){
    var qTplId = new URLSearchParams(location.search).get('tpl_id');
    var raw = sessionStorage.getItem('rvf_preview_payload');
    var payload = raw ? JSON.parse(raw) : null;
    if (!payload && !qTplId) { alert('找不到預覽資料，請從「審核表單模板管理」的「試填預覽並列印」按鈕開啟'); return; }
    if (payload) CUR_SCHEMA = payload.schema || {fields:[], sign_mode:'password'};
    ITEMS = [];
    function openPreview(tpl, asDocNo){
        CUR = {
            id: 0, title: '', business_date: META.today, status: 'draft',
            created_by: META.uid, created_by_name: META.uname,
            tpl: tpl || {name: (payload&&payload.tpl_name) || '(未命名模板)', paper_size: (payload&&payload.paper_size) || 'A4', orientation:'landscape'},
            as_doc_no: asDocNo || '', company_name: META.company_name,
            review: null, approval: null, can_review: false, can_approve: false
        };
        $('#viewTitle').text('試填預覽 — ' + CUR.tpl.name);
        HEAD_DATA = {};
        rvfPadFixedRows(); // 預覽沒有實際資料列，直式標題模式要先依模板的列標題補出對應的空白列
        renderView();
        openMask('viewMask');
    }
    var tplId = (payload && payload.tpl_id) || qTplId;
    if (tplId) {
        // 用真實模板列（含紙張/方向/圖章綁定等設定），確保試列印跟正式列印用同一套設定，不是只用畫面上暫存的兩三個欄位。
        $.getJSON(API, {action:'template_get', id:tplId}, function(res){
            if (!res.ok){ alert(res.error||'找不到此模板'); return; }
            if (!payload) CUR_SCHEMA = res.template.schema || {fields:[], sign_mode:'password'};
            openPreview(res.template, res.template.as_doc ? res.template.as_doc.doc_no : '');
        });
    } else openPreview(null, '');
}
if (PREVIEW_MODE) loadMeta(initPreview);
else loadMeta(function(){ loadTemplates(loadList); });
</script>
</body>
</html>
