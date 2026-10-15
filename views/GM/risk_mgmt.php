<?php
/**
 * 風險與機會管理 — 一頁兩分頁，2026-10-14 建立
 *   分頁一 組織處境分析表               （綁定 AS 文件，名稱與編號由綁定自動取得）
 *   分頁二 風險與機會因應措施管制表     （合併原策略行動措施管制表＋風險評估及處理表）
 * 利害關係者溝通記錄在「溝通管理」頁，這裡只放快捷轉跳。
 * 資料走 src/store/RiskMgmt_API.php；共用邏輯 src/common/risk_mgmt_lib.php
 */
session_start();
if (!isset($_SESSION['userName'])) {
    $_SESSION['lastpage'] = "../../views/GM/risk_mgmt.php";
    header("Location:../../index.php");
    exit;
}
include_once '../../src/common/_config.php';
include_once '../../src/common/DBConnection.php';
include_once '../../src/common/risk_mgmt_lib.php';

$db = (new DBConnection())->getPDO();
rm_ensure_schema($db);
$rmUser = cm_current_user($db);
$perms  = rm_perms($db, $rmUser);
?>
<!DOCTYPE html>
<html lang="zh-Hant">
<head>
    <meta charset="utf-8">
    <meta http-equiv="X-UA-Compatible" content="IE=edge">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>風險與機會管理</title>
    <link href="../../resource/css/bootstrap.css" rel="stylesheet">
    <link href="../../resource/css/font-awesome.css" rel="stylesheet">
    <link href="../../resource/css/nprogress.css" rel="stylesheet">
    <link href="../../resource/css/custom.css" rel="stylesheet">
    <style>
        #sidebar-menu { visibility: hidden; }
        .right_col .page-title { margin:8px 0 4px; overflow:hidden; clear:both; }
        .page-help-btn { height:30px; font-size:13px; padding:0 12px; border:1px solid #d98a33; border-radius:15px;
            background:#F0A24B; color:#fff; cursor:pointer; }
        .page-help-btn:hover { background:#d98a33; }
        @media print { .page-help-btn { display:none !important; } }
        .help-doc { font-size:13px; color:#5b3a1e; line-height:1.75; }
        .help-doc h4 { color:#8A5A2B; border-bottom:2px solid #F7E0BD; padding-bottom:3px; margin:14px 0 6px; font-size:15px; }
        .help-doc h4:first-child { margin-top:0; }
        .help-doc b { color:#8A5A2B; }
        .help-doc ul { padding-left:20px; margin:4px 0; }

        .rm-tabs { display:flex; flex-wrap:wrap; gap:4px; border-bottom:2px solid #E0BE86; margin:6px 0 10px; clear:both; }
        .rm-tab { padding:7px 16px; font-size:14px; color:#8a6d45; background:#FBF5EA; border:1px solid #E8D5B5;
            border-bottom:none; border-radius:6px 6px 0 0; cursor:pointer; margin-bottom:-2px; }
        .rm-tab:hover { background:#F7E0BD; }
        .rm-tab.on { background:#F0A24B; color:#fff; border-color:#d98a33; font-weight:bold; }
        .rm-tab small { display:block; font-size:11px; opacity:.85; }
        .rm-pane { display:none; }
        .rm-pane.on { display:block; }

        .rm-toolbar { display:flex; flex-wrap:wrap; gap:6px; align-items:center; clear:both;
            border:1.5px solid #E8D5B5; border-radius:8px; padding:8px 10px; margin-bottom:10px; background:#FDF8EF; }
        .rm-toolbar label { margin:0; font-size:13px; color:#5b3a1e; }
        .rm-toolbar select, .rm-toolbar input, .rm-toolbar button, .rm-toolbar a.btn-link {
            height:30px; font-size:13px; line-height:28px; padding:0 10px; border:1px solid #D8BE93;
            border-radius:4px; background:#fff; color:#5b3a1e; box-sizing:border-box; }
        .rm-toolbar button, .rm-toolbar a.btn-link { cursor:pointer; text-decoration:none; display:inline-block; }
        .rm-toolbar button:hover, .rm-toolbar a.btn-link:hover { background:#F7E0BD; }
        .rm-toolbar .btn-warm { background:#F0A24B; color:#fff; border-color:#d98a33; }
        .rm-toolbar .btn-warm:hover { background:#d98a33; }
        .rm-role-badge { margin-left:auto; font-size:13px; color:#5b3a1e; background:#F7E0BD; border-radius:12px; padding:4px 12px; }

        .rm-pager { display:flex; justify-content:flex-end; align-items:center; gap:5px; margin:0 0 6px; font-size:12.5px; color:#8a6d45; }
        .rm-pager button { height:26px; min-width:28px; padding:0 8px; border:1px solid #D8BE93; background:#fff;
            color:#5b3a1e; border-radius:4px; cursor:pointer; font-size:12.5px; }
        .rm-pager button[disabled] { opacity:.45; cursor:default; }
        .rm-pager select { height:26px; font-size:12.5px; border:1px solid #D8BE93; border-radius:4px; background:#fff; color:#5b3a1e; }

        .rm-table-wrap { overflow-x:auto; border:1px solid #E8D5B5; border-radius:6px; background:#fff; }
        table.rm-table { width:100%; border-collapse:collapse; font-size:13px; }
        table.rm-table th, table.rm-table td { border:1px solid #EADFC8; padding:5px 8px; text-align:center; vertical-align:middle; }
        table.rm-table thead th { background:#F7E0BD; color:#5b3a1e; font-weight:bold; white-space:nowrap; }
        table.rm-table tbody tr:nth-child(even) { background:#FBF6EC; }
        table.rm-table tbody tr:hover { background:#FBF0DD; }
        table.rm-table td.l { text-align:left; }
        .rm-empty { padding:22px; text-align:center; color:#8a6d45; }

        /* 風險等級徽章：顏色一律搭配文字（ai-rules/10），line-height 要自己指定（Gentelella 的 td span 行高會撐高整列） */
        .lvb { display:inline-block; padding:1px 8px; border-radius:10px; font-size:11.5px; line-height:17px; white-space:nowrap;
            border:1px solid rgba(0,0,0,.12); }
        .lvb.none { background:#F5EEE2; color:#8a6d45; }
        .b-mini { height:24px; padding:0 8px; font-size:12px; line-height:22px; border:1px solid #D8BE93; background:#fff; color:#5b3a1e;
            border-radius:4px; cursor:pointer; margin:1px; white-space:nowrap; }
        .b-mini:hover { background:#F7E0BD; }
        .b-mini.warm { background:#F0A24B; color:#fff; border-color:#d98a33; }
        .b-mini.danger { color:#DD5138; }

        /* 跳窗（寬度一律固定像素，禁用 vw） */
        .rm-mask { display:none; position:fixed; inset:0; background:rgba(60,40,20,.45); z-index:11000; overflow-y:auto; overflow-x:hidden; }
        .rm-mask.on { display:block; }
        .rm-modal { background:#fff; border-radius:8px; margin:34px auto; box-shadow:0 5px 25px rgba(0,0,0,.3);
            display:flex; flex-direction:column; max-height:88vh; max-width:96%; }
        .rm-mhd { background:#F7E0BD; color:#5b3a1e; font-weight:bold; padding:10px 15px; border-radius:8px 8px 0 0;
            display:flex; justify-content:space-between; align-items:center; }
        .rm-mhd .x { cursor:pointer; color:#b5762a; }
        .rm-mbd { padding:14px 16px; overflow-y:auto; overflow-x:hidden; }
        .rm-mft { padding:10px 15px; border-top:1px solid #EADFC8; text-align:right; }
        .rm-mft button { height:30px; padding:0 16px; border-radius:4px; font-size:13px; cursor:pointer;
            border:1px solid #D8BE93; background:#fff; color:#5b3a1e; margin-left:6px; }
        .rm-mft button.btn-warm { border-color:#d98a33; background:#F0A24B; color:#fff; }

        .fgrid { display:grid; grid-template-columns:repeat(2, minmax(0,1fr)); gap:8px 14px; margin-bottom:10px; }
        .fgrid > div { min-width:0; }
        .fgrid .full { grid-column:1 / -1; }
        .fgrid label, .fld label { display:block; font-size:12.5px; color:#5b3a1e; margin:0 0 2px; font-weight:bold; }
        .fgrid input[type=text], .fgrid input[type=date], .fgrid select, .fgrid textarea,
        .fld input[type=text], .fld input[type=number], .fld select, .fld textarea {
            width:100%; border:1px solid #D8BE93; border-radius:4px; padding:5px 8px; font-size:13px;
            background:#FFFDF8; color:#5b3a1e; box-sizing:border-box; }
        .err { color:#DD5138; font-size:12px; min-height:16px; display:block; }
        input.bad, select.bad, textarea.bad { border-color:#DD5138 !important; background:#FFF6F3 !important; }
        .hint { font-size:12px; color:#8a6d45; line-height:1.6; }
        .note-box { background:#FBF5EA; border:1px solid #EADFC8; border-radius:6px; padding:8px 10px; font-size:12.5px; color:#5b3a1e; line-height:1.7; }
        .chips { display:flex; flex-wrap:wrap; gap:4px; margin:4px 0; min-height:24px; }
        .chip { background:#F7E0BD; border:1px solid #E0BE86; color:#5b3a1e; border-radius:12px; padding:1px 8px; font-size:12px;
            display:inline-flex; align-items:center; gap:5px; }
        .chip i { cursor:pointer; color:#b5762a; }

        /* 明細表格（表單內，欄位多時在跳窗內橫向捲動，不撐破頁面） */
        .it-wrap { overflow-x:auto; border:1px solid #EADFC8; border-radius:6px; }
        .it-wrap.cards { overflow:visible; border:none; }
        /* 風險與機會因應措施管制表的編輯畫面：一條風險一張卡片，欄位用格線排滿寬度，不需要左右拉桿 */
        #itTbl.cards, #itTbl.cards tbody { display:block; width:auto !important; table-layout:auto; }
        #itTbl.cards thead, #itTbl.cards colgroup { display:none; }
        #itTbl.cards tr { display:grid; grid-template-columns:repeat(12, minmax(0,1fr)); gap:6px 10px; position:relative;
            border:1px solid #E8D5B5; border-radius:8px; background:#FFFDF8; padding:12px 44px 12px 14px; margin-bottom:10px; }
        #itTbl.cards tr:nth-child(even) { background:#FBF6EC; }
        #itTbl.cards td { display:block; border:none; padding:0; min-width:0; text-align:left; }
        #itTbl.cards td .fl { display:block; font-size:11.5px; font-weight:bold; color:#8A5A2B; margin:0 0 2px; }
        #itTbl.cards td.seq { grid-column:span 1; text-align:center; font-weight:bold; font-size:15px; color:#fff; background:#F0A24B; border-radius:6px; align-self:start; padding:5px 0; }
        #itTbl.cards td.c-scope { grid-column:span 2; }
        #itTbl.cards td.c-sh { grid-column:span 4; }
        #itTbl.cards td.c-needs { grid-column:span 5; }
        #itTbl.cards td.c-issue, #itTbl.cards td.c-ro { grid-column:span 6; }
        #itTbl.cards td.c-kind { grid-column:span 2; }
        #itTbl.cards td.c-sev, #itTbl.cards td.c-frq { grid-column:span 3; }
        #itTbl.cards td.c-lv { grid-column:span 2; }
        #itTbl.cards td.c-cn { grid-column:span 2; }
        #itTbl.cards td.c-measure, #itTbl.cards td.c-eff { grid-column:span 6; }
        #itTbl.cards td.c-rc { grid-column:span 12; border-top:1px dashed #E0BE86; padding-top:6px; }
        #itTbl.cards td.c-rc textarea { min-height:38px; }
        #itTbl.cards td.c-es, #itTbl.cards td.c-ed { grid-column:span 2; }
        #itTbl.cards td.c-er { grid-column:span 8; }
        #itTbl.cards td.c-ev { grid-column:span 12; }
        #itTbl.cards td.c-es, #itTbl.cards td.c-ed, #itTbl.cards td.c-er, #itTbl.cards td.c-ev { border-top:1px dashed #E0BE86; padding-top:6px; }
        #itTbl.cards td.c-ev textarea { min-height:84px; }
        .ev-reason { font-size:12px; color:#8A5A2B; margin-top:3px; }
        .car-box { margin-top:5px; font-size:12px; }
        .trk { margin-top:5px; font-size:12px; color:#5b3a1e; background:#FBF4E8; border:1px solid #EADFC8; border-radius:4px; padding:4px 6px; }
        .trk.none { color:#9a8a74; }
        .trk-row { border-top:1px dotted #E0BE86; padding:3px 0 2px; }
        .trk-row:first-of-type { border-top:0; }
        .trk-ac { color:#6b5a44; }
        .trk-st { padding:0 5px; border-radius:8px; font-size:11px; line-height:16px; display:inline-block; }
        .trk-row.open .trk-st { background:#F7E0BD; } .trk-row.over .trk-st { background:#DD5138; color:#fff; } .trk-row.done .trk-st { background:#CFE3C8; }
        #itTbl.cards td.op { position:absolute; right:8px; top:8px; }
        #itTbl.cards td.lvc { padding-top:2px; }
        #itTbl.cards textarea { min-height:60px; }
        @media (max-width:980px) { #itTbl.cards td.c-er, #itTbl.cards td.c-ev { grid-column:span 12; } #itTbl.cards td.c-es, #itTbl.cards td.c-ed { grid-column:span 6; } #itTbl.cards td.c-scope, #itTbl.cards td.c-sh, #itTbl.cards td.c-needs, #itTbl.cards td.c-issue, #itTbl.cards td.c-ro,
            #itTbl.cards td.c-measure, #itTbl.cards td.c-eff { grid-column:span 12; } #itTbl.cards td.c-kind, #itTbl.cards td.c-sev, #itTbl.cards td.c-frq,
            #itTbl.cards td.c-lv, #itTbl.cards td.c-cn { grid-column:span 6; } }
        table.it-tbl { border-collapse:collapse; font-size:12.5px; table-layout:fixed; }
        table.it-tbl th, table.it-tbl td { border:1px solid #EADFC8; padding:3px 4px; vertical-align:top; }
        table.it-tbl thead th { background:#F7E0BD; color:#5b3a1e; text-align:center; vertical-align:middle; }
        table.it-tbl textarea, table.it-tbl input[type=text], table.it-tbl select { width:100%; border:1px solid #D8BE93; border-radius:4px;
            padding:4px 6px; font-size:12.5px; background:#FFFDF8; color:#5b3a1e; box-sizing:border-box; }
        table.it-tbl textarea { min-height:52px; resize:vertical; }
        table.it-tbl td.seq { text-align:center; color:#8a6d45; }
        table.it-tbl td.op { text-align:center; }
        table.it-tbl td.lvc { text-align:center; vertical-align:middle; }
        .ctx-tag { display:inline-block; font-size:11px; background:#F7E0BD; border:1px solid #E0BE86; border-radius:4px; padding:0 5px;
            margin-top:2px; line-height:16px; color:#5b3a1e; }

        /* SWOT 四象限（組織處境分析表） */
        .swot-bar { display:flex; flex-wrap:wrap; gap:6px; align-items:center; margin-bottom:6px; }
        .swot-bar select, .swot-bar input, .swot-bar button { height:30px; font-size:13px; padding:0 10px; border:1px solid #D8BE93;
            border-radius:4px; background:#fff; color:#5b3a1e; box-sizing:border-box; }
        .swot-bar button { cursor:pointer; }
        .swot-bar button:hover { background:#F7E0BD; }
        .swot-bar .btn-warm { background:#F0A24B; color:#fff; border-color:#d98a33; }
        .swot-status { font-size:12.5px; color:#8a6d45; margin:0 0 8px; }
        .swot-status.warn { color:#C4442D; }
        .swot-grid { display:grid; grid-template-columns:repeat(2, minmax(0,1fr)); gap:10px; align-items:start; }
        .swot-q { border:2px solid var(--c); border-radius:8px; background:#fff; min-width:0; overflow:hidden; }
        .swot-h { background:var(--c); color:var(--t); padding:7px 12px; font-size:15px; font-weight:bold; display:flex; align-items:center; gap:8px; }
        .swot-h small { font-weight:normal; font-size:11.5px; opacity:.9; }
        .swot-h .cnt { margin-left:auto; font-size:12px; font-weight:normal; }
        .swot-body { padding:8px 10px; }
        .swot-purpose { font-size:12px; color:#8a6d45; margin:0 0 6px; }
        .swot-it { position:relative; border:1px solid #E8D5B5; border-radius:6px; background:#FFFDF8; padding:6px 30px 6px 30px; margin-bottom:6px; }
        .swot-it .no { position:absolute; left:7px; top:7px; width:18px; height:18px; border-radius:9px; background:var(--c); color:var(--t);
            font-size:11px; line-height:18px; text-align:center; }
        .swot-it .del { position:absolute; right:5px; top:4px; border:none; background:none; color:#DD5138; cursor:pointer; font-size:14px; }
        .swot-it label { display:block; font-size:11.5px; color:#8a6d45; margin:3px 0 1px; font-weight:bold; }
        .swot-it input, .swot-it textarea { width:100%; border:1px solid #D8BE93; border-radius:4px; padding:4px 7px; font-size:13px;
            background:#fff; color:#5b3a1e; box-sizing:border-box; }
        .swot-it textarea { min-height:46px; resize:vertical; }
        .swot-it.ro input, .swot-it.ro textarea { background:#F5EEE2; }
        .swot-guide { display:none; margin-top:6px; border-top:1px dashed #E0BE86; padding-top:6px; font-size:12.5px; color:#5b3a1e; }
        .show-guide .swot-guide { display:block; }
        .swot-guide ul { margin:2px 0 6px; padding-left:18px; }
        .swot-guide .g-h { color:#8A5A2B; font-weight:bold; font-size:12px; }
        .swot-guide ul.scan { background:#FDF8EF; border:1px solid #EADFC8; border-radius:6px; padding:6px 8px 6px 22px; }
        .swot-guide ul.scan li { margin:2px 0; }
        /* 右下角回頂端 */
        #btnTop { position:fixed; right:22px; bottom:22px; width:44px; height:44px; border-radius:22px; border:1px solid #d98a33;
            background:#F0A24B; color:#fff; font-size:20px; line-height:42px; text-align:center; cursor:pointer; box-shadow:0 2px 8px rgba(60,40,20,.3);
            display:none; z-index:10500; padding:0; }
        #btnTop:hover { background:#d98a33; }
        @media print { #btnTop { display:none !important; } }
        .swot-ex { display:block; width:100%; text-align:left; margin:3px 0; padding:4px 8px; border:1px dashed #D8BE93; border-radius:6px;
            background:#FDF8EF; color:#5b3a1e; font-size:12px; line-height:1.5; cursor:pointer; }
        .swot-ex:hover { background:#F7E0BD; }
        .swot-ex b { color:#8A5A2B; }
        .sg-row { border:1px solid #EADFC8; border-radius:6px; padding:6px 9px; margin-bottom:6px; background:#FFFDF8; }
        .sg-row .src { font-size:11.5px; color:#8a6d45; }
        .sg-row .why { font-size:11.5px; color:#8a6d45; }
        .sg-row.done { opacity:.55; }
        @media (max-width:1100px) { .swot-grid { grid-template-columns:minmax(0,1fr); } }

        /* 評級對照矩陣 */
        table.mx { border-collapse:collapse; font-size:12.5px; margin:6px 0; }
        table.mx th, table.mx td { border:1px solid #EADFC8; padding:5px 9px; text-align:center; }
        table.mx th { background:#F7E0BD; color:#5b3a1e; }
        .set-sec { border:1px solid #EADFC8; border-radius:6px; padding:8px 10px; margin-bottom:10px; background:#FFFDF8; }
        .set-sec h5 { margin:0 0 6px; color:#8A5A2B; font-size:14px; }
    </style>
</head>
<body class="nav-sm">
<div class="container body">
<div class="main_container">
    <?php include '../partPage/sideAndTopBarMenu.html' ?>
    <div class="right_col" role="main">
        <div class="page-title" style="display:flex;align-items:center;flex-wrap:wrap;">
            <h2 style="margin:6px 0;">風險與機會管理
                <small style="color:#8a6d45;">組織處境分析 → 風險與機會因應措施，一頁控管</small></h2>
            <button id="btnPageHelp" class="page-help-btn" style="margin-left:auto;"><i class="fa fa-question-circle"></i> 使用說明</button>
        </div>
        <div class="clearfix"></div>

<?php if (!$perms['uid']): ?>
        <div class="note-box" style="margin-top:12px;">
            <h4 style="color:#DD5138;margin-top:0;"><i class="fa fa-lock"></i> 無使用權限</h4>
            此帳號目前不是在職狀態，無法使用本模組。如有疑問請洽人事或系統管理者。
        </div>
<?php else: ?>
        <div class="rm-toolbar">
            <button id="btnSetting" class="btn-warm" style="display:none;"><i class="fa fa-sliders"></i> 模組設定</button>
            <a class="btn-link" href="communication_mgmt.php" target="_blank" rel="noopener"
               title="利害關係者的溝通記錄、措施追蹤與溝通管制表在「溝通管理」頁"><i class="fa fa-comments-o"></i> 前往溝通管理（利害關係者）</a>
            <span id="asdocHint" class="hint" style="flex:1 1 320px;"></span>
            <span class="rm-role-badge">目前角色：<b id="roleLabel"></b></span>
        </div>

        <div class="rm-tabs" id="mainTabs">
            <span class="rm-tab on" data-tab="context"><span class="tn"></span><small>SWOT　整家公司一個年度一張</small></span>
            <span class="rm-tab" data-tab="risk"><span class="tn"></span><small>風險評估與因應措施</small></span>
        </div>

        <!-- 兩個分頁共用同一組版面，只有標題與明細欄位不同 -->
        <div class="rm-pane on" id="pane_context"></div>
        <div class="rm-pane" id="pane_risk"></div>
<?php endif; ?>
    </div>
</div>
</div>

<?php if ($perms['uid']): ?>
<!-- ============ 表單編輯／檢視 ============ -->
<div class="rm-mask" id="docMask"><div class="rm-modal" style="width:1180px;">
    <div class="rm-mhd"><span id="docTitle">表單</span><span class="x" data-close><i class="fa fa-times"></i></span></div>
    <div class="rm-mbd">
        <div class="fgrid">
            <div><label>填表日期 <span style="color:#DD5138;">*</span></label><input type="date" id="fDate"><span class="err" id="eDate"></span></div>
            <div><label>單號</label><input type="text" id="fNo" readonly placeholder="儲存後自動產生" style="background:#F5EEE2;" data-eg-skip></div>
            <div class="full"><label>相關部門</label>
                <div class="chips" id="deptChips"></div>
                <select id="deptAdd" data-eg-filter="輸入部門名稱篩選…"></select>
            </div>
        </div>
        <div id="itemHint" class="hint" style="margin-bottom:4px;"></div>
        <div class="it-wrap" id="itWrap"><table class="it-tbl" id="itTbl"></table></div>
        <div style="margin-top:6px;" id="addRowBox"><button type="button" class="b-mini warm" id="btnAddRow"><i class="fa fa-plus"></i> 新增一條</button>
            <span class="hint">在最後一條的欄位按 ↓ 也會自動加一條；沒填內容的最後一條按 ↑ 會自動移除。</span></div>
        <span class="err" id="eItems"></span>
    </div>
    <div class="rm-mft">
        <button type="button" id="btnDocPrint"><i class="fa fa-print"></i> 列印</button>
        <button type="button" data-close>關閉</button>
        <button type="button" class="btn-warm" id="btnDocSave"><i class="fa fa-save"></i> 儲存</button>
    </div>
</div></div>

<!-- ============ 引用組織處境議題 ============ -->
<div class="rm-mask" id="ctxMask" style="z-index:11200;"><div class="rm-modal" style="width:900px;">
    <div class="rm-mhd"><span>選擇組織處境分析表的議題（連動）</span><span class="x" data-close><i class="fa fa-times"></i></span></div>
    <div class="rm-mbd">
        <input type="text" id="ctxKw" placeholder="輸入議題／利害關係者／構面關鍵字，即時篩選" style="width:100%;border:1px solid #D8BE93;border-radius:4px;padding:6px 9px;margin-bottom:8px;">
        <div class="rm-table-wrap"><table class="rm-table"><thead><tr><th>單號</th><th>內/外部</th><th>構面</th><th>利害關係者</th><th>議題描述</th><th></th></tr></thead>
            <tbody id="ctxBody"></tbody></table></div>
        <div class="hint" style="margin-top:6px;">選用後這一列的內/外部議題、利害關係者、需求與期望、議題描述會<b>連動</b>組織處境分析表：來源改了，這裡跟著變；要自行修改請先按該列的「解除連動」。</div>
    </div>
</div></div>

<!-- ============ 依系統資料建議（組織處境分析表） ============ -->
<div class="rm-mask" id="sugMask"><div class="rm-modal" style="width:860px;">
    <div class="rm-mhd"><span>依系統資料建議（供參考，加入後可再修改）</span><span class="x" data-close><i class="fa fa-times"></i></span></div>
    <div class="rm-mbd"><div class="note-box" style="margin-bottom:8px;">下面的建議<b>全部由本系統的實際資料依規則即時算出</b>（不是憑空產生），<b>選哪一年就用那一年的資料重新計算，所以每年都會不一樣</b>。
            涵蓋：KPI 年度總表（未達標／全部達標）、出貨紀錄（客戶集中度、新客戶）、人員資料（新進人員比例）、行事曆（颱風假）、BOM 委外紀錄（單一廠商／單一來源製程）、
            品質異常處理單、客戶退貨、溝通管理（政府單位溝通、逾期與未結案的措施追蹤）、量測儀器校驗、去年的表。
            每一條都寫明<b>佐證來源與規則</b>，有對應的溝通記錄時會自動附上單號；是不是列入、放在哪個象限由您判斷。</div>
        <div style="margin-bottom:8px;"><label style="font-size:13px;margin:0;">資料年度</label>
            <select id="sugDy" style="width:110px;height:28px;"></select>
            <span class="hint">年初（1～3 月）填當年度的表時，預設用「前一年」的全年資料；可自行改選。</span></div>
        <div id="sugBody"></div></div>
</div></div>

<!-- ============ 判斷規則 ============ -->
<div class="rm-mask" id="ruleMask"><div class="rm-modal" style="width:1080px;">
    <div class="rm-mhd"><span>判斷規則（不依賴外部 AI，所有判斷都是下面這些規則）</span><span class="x" data-close><i class="fa fa-times"></i></span></div>
    <div class="rm-mbd">
        <div class="note-box" style="margin-bottom:8px;">「由處境分析表產生」與「依資料產生佐證」都是依下面的規則判斷，每一列產生出來的資料都會記下用到哪些規則編號；結果一律只是<b>草稿／建議</b>，要由人確認、修改並填裁示。
            嚴重度與發生率是先算成 0～1 的比例，再依「模組設定」目前有幾級換算，所以級數改了規則照樣適用。</div>
        <label style="font-size:13px;">類型：</label>
        <select id="ruleKind" style="width:150px;"><option value="">全部</option></select>
        <div class="rm-table-wrap" style="margin-top:6px;"><table class="rm-table"><thead><tr><th style="width:92px;">規則編號</th><th style="width:76px;">類型</th><th style="width:120px;">適用類別</th><th>條件</th><th>判斷結果</th></tr></thead>
            <tbody id="ruleBody"></tbody></table></div>
    </div>
</div></div>

<!-- ============ 評級對照表 ============ -->
<div class="rm-mask" id="ratingMask"><div class="rm-modal" style="width:860px;">
    <div class="rm-mhd"><span>風險管理準則（2-GM-03 表A～表D，由模組設定即時產生）</span><span class="x" data-close><i class="fa fa-times"></i></span></div>
    <div class="rm-mbd" id="ratingBody"></div>
</div></div>

<!-- ============ 模組設定 ============ -->
<div class="rm-mask" id="setMask"><div class="rm-modal" style="width:980px;">
    <div class="rm-mhd"><span>模組設定</span><span class="x" data-close><i class="fa fa-times"></i></span></div>
    <div class="rm-mbd">
        <div class="set-sec"><h5>AS 文件編號綁定（表單名稱與文件編號由綁定自動取得）</h5>
            <table class="rm-table"><thead><tr><th>表單</th><th>目前綁定</th><th></th></tr></thead><tbody id="setAsdocBody"></tbody></table>
            <div class="hint">合併後的「風險與機會因應措施管制表」建議綁定新的 AS 文件；AS 文件改名後，這裡與列印版的名稱會自動跟著變。</div></div>
        <div class="set-sec"><h5>① 風險準則（2-GM-03 表A）</h5><div id="setLvl"></div>
            <button type="button" class="b-mini" id="btnAddLvl"><i class="fa fa-plus"></i> 新增一級</button>
            <span class="err" id="eLvl"></span>
            <div class="hint"><b>評分是「下限～上限」區間</b>（例：低度 1～1、中度 2～2、高度 3～6），區間之間連續不留縫隙：每一級的<b>下限會自動＝上一級上限＋1</b>、不用也不能自己填；第一級從 1 起算，
                <b>最後一級的上限固定＝目前最高可能分數</b>（由下面的嚴重度(S)與發生率(O)等級算出，會跟著變）。上限不可小於下限，也要替後面的每一級各留至少 1 分。
                <b>已儲存的風險等級名稱不提供修改</b>（要改請刪除後重新新增）。「處理措施」會顯示在評級對照表上；勾選「需要因應措施」的等級，儲存時若該列沒有風險確認編號，會自動配發。</div></div>
        <div class="set-sec"><h5>② 嚴重度(S)分析（表B）</h5>
            <div class="fld"><label>說明欄名稱（一行一個，可自行增減；預設：公司形象／財物損失／客戶抱怨件數）</label>
                <textarea id="setImpCols" style="min-height:66px;"></textarea></div>
            <div id="setImp"></div>
            <span class="err" id="eImp"></span>
            <button type="button" class="b-mini" id="btnAddImp"><i class="fa fa-plus"></i> 新增一級</button>
            <div class="hint">等級數字由系統從 1 起<b>自動依順序編號、不能修改</b>；刪除其中一級後後面的數字會自動往前補（不會跳號），已填的風險資料會一併換成新的等級數字，被刪掉那一級的資料會變成「未評估」。</div></div>
        <div class="set-sec"><h5>③ 發生率(O)評估（表C）</h5><div id="setLik"></div>
            <span class="err" id="eLik"></span>
            <button type="button" class="b-mini" id="btnAddLik"><i class="fa fa-plus"></i> 新增一級</button>
            <div class="hint">等級數字同樣自動編號、不能修改。<b>發生機率百分比是整數區間</b>：第一級從 0% 起算、<b>最後一級固定到 100%</b>，每一級的<b>下限自動＝上一級上限＋1</b>，只需要填各級的上限（不可小於下限，也要替後面的每一級各留至少 1%）。</div></div>
        <div class="set-sec"><h5>④ 風險分布（表D）</h5>
            <div class="fld" style="margin-bottom:6px;"><label>分數算法（程序書 6.3.3 採相乘制）</label>
                <select id="setMethod" style="max-width:320px;"><option value="multiply">嚴重度(S)等級 × 發生率(O)等級</option><option value="add">嚴重度(S)等級 ＋ 發生率(O)等級</option><option value="max">取嚴重度(S)與發生率(O)較大者</option></select></div>
            <div class="hint" style="margin-bottom:4px;">下表是依上面三項設定即時預覽的風險分布（尚未儲存前就能看到結果）：</div>
            <div id="setDist"></div></div>
        <div class="set-sec"><h5>組織處境分析表（SWOT）四象限名稱</h5>
            <div class="fgrid" style="grid-template-columns:repeat(4, minmax(0,1fr));">
                <div class="fld"><label>S（內部）</label><input type="text" id="setSwot0" maxlength="20"></div>
                <div class="fld"><label>W（內部）</label><input type="text" id="setSwot1" maxlength="20"></div>
                <div class="fld"><label>O（外部）</label><input type="text" id="setSwot2" maxlength="20"></div>
                <div class="fld"><label>T（外部）</label><input type="text" id="setSwot3" maxlength="20"></div>
            </div>
            <div class="hint">四象限固定為優勢(S)、劣勢(W)、機會(O)、威脅(T)，S、W 屬內部、O、T 屬外部；這裡只改顯示名稱，不影響既有資料。</div></div>
        <div class="set-sec"><h5>機會的欄位名稱</h5>
            <div class="fld" style="display:flex;gap:10px;flex-wrap:wrap;align-items:center;">
                <label>嚴重度(S) 在機會列顯示為</label><input type="text" id="setOppSev" maxlength="12" style="width:140px;">
                <label>發生率(O) 顯示為</label><input type="text" id="setOppFrq" maxlength="12" style="width:140px;">
                <label>風險等級 顯示為</label><input type="text" id="setOppLv" maxlength="12" style="width:140px;">
            </div>
            <div class="hint">風險與機會共用同一組評分（分數算法相同），但問的問題不同：風險問「後果多嚴重、多常發生」，機會問「效益多大、多有把握做到」。
                這裡只改機會列顯示的欄位名稱與上面風險準則的「機會等級名稱」，不會另外產生一套分數；<b>機會不列入風險分布（表D）與清單的「最高風險等級」</b>。</div></div>
        <div class="set-sec"><h5>對外宣稱有外包的稽核製程</h5>
            <div class="fld" id="setOutsrc"></div>
            <div class="hint">稽核製程取自「訂單追蹤」設定的稽核製程。只有勾選的製程才會做委外廠商相關的分析與建議；沒勾＝依 AS 稽核對外宣稱沒有外包，SWOT 與風險表都不列委外議題。客戶、品質、退貨等統計一律只計稽核製程的料號。</div></div>
        <div class="set-sec"><h5>風險確認編號前綴</h5>
            <div class="fld"><input type="text" id="setPrefix" maxlength="6" style="max-width:140px;"></div>
            <div class="hint"><b>風險確認編號是什麼</b>：每條「需要因應措施」的風險（風險準則裡勾選的等級）由系統自動配發的流水號，讓這條風險在措施追蹤、管理審查、矯正單等地方被引用時有唯一編號可對照。
                格式：前綴＋填表年度＋流水號，例如 R2026-001。已配發的編號不會因設定變動而改變。</div></div>
        <span class="err" id="eSet"></span>
        <div class="hint">按「儲存設定」才會生效；設定改了之後，所有既有資料的風險等級會依新設定即時重算（資料本身不會被改動）。</div>
    </div>
    <div class="rm-mft"><button type="button" data-close>取消</button><button type="button" class="btn-warm" id="btnSetSave">儲存設定</button></div>
</div></div>

<!-- ============ 使用說明 ============ -->
<div class="rm-mask" id="helpUseMask"><div class="rm-modal" style="width:860px;">
    <div class="rm-mhd"><span>使用說明　風險與機會管理</span><span class="x" data-close><i class="fa fa-times"></i></span></div>
    <div class="rm-mbd help-doc">
        <h4>這一頁在做什麼</h4>
        <ul><li><b>組織處境分析表</b>：整家公司<b>一個年度只有一張</b>，用 SWOT 四象限（優勢／劣勢／機會／威脅）記錄內外部議題、相關利害關係者與他們的需求與期望，是風險與機會的來源。每個象限都有「填寫提示」與「範例」，按「依系統資料建議」可帶入去年內容、溝通管理未結案的反應、品質異常的主要原因等線索。</li>
            <li><b>風險與機會因應措施管制表</b>：把議題整理成風險／機會，評估嚴重度(S)與發生率(O)，自動算出風險等級，並記錄因應措施與成效確認（原「策略行動措施管制表」與「風險評估及處理表」合併為這一張）。</li>
            <li>利害關係者的溝通記錄、措施追蹤與溝通管制在「溝通管理」頁，按上方「前往溝通管理」即可轉跳。</li></ul>
        <h4>年度作業流程</h4>
        <ul><li><b>年初</b>：先填「組織處境分析表」（可按「依系統資料建議」，年初用前一年的全年資料）→ 到「風險與機會因應措施管制表」按「<b>由處境分析表產生</b>」，系統依規則替每個議題產生風險／機會草稿（影響說明、嚴重度(S)、發生率(O)、因應措施、成效確認方式），您再檢查修改。</li>
            <li><b>年中</b>：措施靠「溝通管理」的溝通記錄與措施追蹤落實。</li>
            <li><b>定期／年底的「成效確認」</b>：在每條風險的「成效確認」區按「<b>依資料產生佐證</b>」，系統蒐集 KPI 逐月數字、相關措施追蹤與溝通記錄的結案狀況並建議結果，主管填「裁示」後存檔；存檔當下的佐證是快照，之後資料再變也不會改寫。有結果就一定要有確認日期、裁示與佐證。</li>
            <li>按「判斷規則」可以看到系統用的所有規則（分類、嚴重度(S)、發生率(O)、措施、成效），都不依賴外部 AI。</li></ul>
        <h4>機會與風險怎麼分開看</h4>
        <ul><li>風險與機會<b>共用同一組評分</b>（分數算法相同），但顯示的用詞不同：風險是「嚴重度(S)／發生率(O)／風險等級（低度～極度危險）」，機會是「預期效益／實現可能性／機會等級（低度～最優先機會）」。把某列的「風險與機會」改成「機會」，欄位名稱與等級名稱會當場切換。</li>
            <li>機會等級名稱、機會的處理措施與欄位名稱都在「模組設定」維護。<b>機會不列入風險分布（表D）</b>，清單的「最高風險等級」也只看風險。</li></ul>
        <h4>因應措施的負責人與追蹤、重新評估、矯正單</h4>
        <ul><li>每條因應措施底下會<b>自動列出相關的措施追蹤</b>（負責人、預計完成日、執行中／逾期／已結案）。資料取自「溝通管理」的措施追蹤，這裡不用再填一次；要連結某一項，在因應措施文字裡寫「措施追蹤 #編號」，或在溝通管理建立追蹤項目。</li>
            <li><b>重新評估</b>：年度中途因重大變化（例如某項指標已實際發生）重新評估時，填「重新評估」日期與原因。按「依資料產生佐證」時，若依當年資料算出的發生率比表上高，畫面會用紅字提示建議重新評估，但<b>不會自動改你的評分</b>。</li>
            <li><b>成效確認結果為「無效」</b>的列會出現「開矯正單」鈕，按下去由異常矯正處理單模組開單（一條風險只開一張，開完該列顯示單號）。需要先儲存、且只有表單建立者或管理員可以開。</li></ul>
        <h4>稽核製程與委外</h4>
        <ul><li>客戶集中度、新客戶、客戶退貨、品質異常等統計<b>只計「稽核製程」的料號</b>（取自訂單追蹤設定的稽核製程標籤）。</li>
            <li>委外廠商相關的分析與建議，只針對「模組設定」勾選為<b>對外宣稱有外包</b>的稽核製程；沒勾＝沒有外包，SWOT 與風險表都不列委外議題。</li></ul>
        <h4>列印</h4>
        <ul><li>兩張表都有「列印空白表單」（現場手寫用）；正式列印的成效確認欄只印結果、日期、裁示與佐證來源編號，逐月 KPI 數據與追蹤明細留在系統畫面。</li></ul>
        <h4>名詞：風險確認編號</h4>
        <ul><li>每條<b>需要因應措施</b>的風險（等級在「風險準則」被勾選「需要因應措施」者）由系統自動配發的流水號，例如 R2026-001（前綴與年度可在模組設定調整）。
            用途是讓這條風險在其他表單（措施追蹤、管理審查、矯正單）被引用時有唯一編號可對照；已配發的編號不會因為設定變動而改變。</li></ul>
        <h4>操作步驟</h4>
        <ul><li>按「新增」→ 填填表日期、相關部門 → 逐列填寫（最後一列按 ↓ 自動加列）→ 儲存。</li>
            <li>風險表每一列的「議題描述」可按「選擇處境議題」連動組織處境分析表：內/外部議題、利害關係者、需求與期望、議題描述都跟著來源走（來源改了這裡跟著變），不必重打；要自行修改先按「解除連動」。「風險與機會」欄選這一列是風險還是機會，「對組織的影響說明」寫它對組織造成的影響。</li>
            <li>嚴重度(S)與發生率(O)要一起選，<b>風險等級由系統依目前設定自動算出</b>，不可手填。</li>
            <li>等級被設定為「需要因應措施」的列，儲存時會自動配發風險確認編號。</li>
            <li>「風險準則／評級對照」可隨時查看 2-GM-03 的表A風險準則、表B嚴重度(S)分析、表C發生率(O)分析、表D風險分布（格內附目前實際落在該格的風險筆數）。</li></ul>
        <h4>重要行為</h4>
        <ul><li>風險準則（表A）、嚴重度(S)分析（表B）、發生率(O)評估（表C）、風險分布算法（表D）、構面、確認編號前綴<b>全部可在「模組設定」修改</b>（預設值取自 2-GM-03）；改完後既有資料的風險等級依新設定即時重算。</li>
            <li>表單名稱與文件編號取自綁定的 AS 文件，列印版頁尾右下角的編號版次依填表日期回推。</li>
            <li>一般員工只看得到、改得動自己建立的表單；檢閱角色可唯讀查看全部；管理員可改全部、刪除與設定。</li></ul>
        <h4>角色</h4><div id="helpRoleBody"></div>
    </div>
</div></div>

<button type="button" id="btnTop" title="回頁面頂端" aria-label="回頁面頂端"><i class="fa fa-angle-up"></i></button>
<script src="../../resource/js/jquery.min.js"></script>
<script src="../../resource/js/bootstrap.min.js"></script>
<script src="../../resource/js/fastclick.js"></script>
<script src="../../resource/js/nprogress.js"></script>
<script src="../../resource/js/custom.min.js"></script>
<script src="../../resource/js/eg_date_fmt.js?v=<?= @filemtime(__DIR__.'/../../resource/js/eg_date_fmt.js') ?>"></script>
<script src="../../resource/js/eg_asdoc_picker.js?v=<?= @filemtime(__DIR__.'/../../resource/js/eg_asdoc_picker.js') ?>"></script>
<script src="../../resource/js/eg_print_log.js?v=<?= @filemtime(__DIR__.'/../../resource/js/eg_print_log.js') ?>"></script>
<script src="../../resource/js/eg_input_rules.js?v=<?= @filemtime(__DIR__.'/../../resource/js/eg_input_rules.js') ?>"></script>
<script>
$(document).ready(function(){ $('#sidebar-menu').css('visibility','visible'); });

var API = '../../src/store/RiskMgmt_API.php';
var META = null, CSRF = '';
var KINDS = ['context', 'risk'];
var LISTKINDS = ['risk'];       // 組織處境分析表是整家公司一個年度一張，不用清單，直接開 SWOT 版面
var STATE = {context:{page:1, per:20}, risk:{page:1, per:20}};
var CUR = null;            // 目前開啟的表單 {kind, doc_id, can_edit, dept_ids, print}
var CTXROW = null;         // 正在挑「引用處境」的那一列

function esc(s){ return $('<div>').text(s == null ? '' : s).html(); }
function dispDate(s){ try { return window.egFmtDate ? egFmtDate(s) : (s || ''); } catch(e){ return s || ''; } }
function openMask(id){ $('#' + id).addClass('on'); }
function closeMask(id){ $('#' + id).removeClass('on'); }
$(document).on('click', '.rm-mask [data-close]', function(){ $(this).closest('.rm-mask').removeClass('on'); });
$(document).on('click', '.rm-mask', function(e){ if (e.target === this) $(this).removeClass('on'); });
/* 非 2xx 時 jQuery 不呼叫 success，錯誤會只掉進 console＝畫面上看起來「沒反應」，統一攔起來顯示 */
$(document).ajaxError(function(_e, xhr){
    if (!xhr || xhr.status === 0) return;
    var m = '';
    try { m = (JSON.parse(xhr.responseText) || {}).error || ''; } catch(e){}
    alert(m || ('操作失敗（HTTP ' + xhr.status + '）'));
});
function post(data, cb){
    data.csrf = CSRF;
    NProgress.start();
    $.post(API, data, function(res){ NProgress.done(); if (res && res.ok) cb(res); }, 'json').fail(function(){ NProgress.done(); });
}
function get(data, cb){
    NProgress.start();
    $.getJSON(API, data, function(res){ NProgress.done(); if (res && res.ok) cb(res); }).fail(function(){ NProgress.done(); });
}
function debounce(fn, wait){ var t = null; return function(){ var a = arguments, c = this; clearTimeout(t); t = setTimeout(function(){ fn.apply(c, a); }, wait); }; }

/* ---------- 表單名稱一律取自綁定的 AS 文件（沒綁才退回預設名稱） ---------- */
function kindName(k){
    var a = META.asdoc[k];
    return (a && a.doc && a.doc.doc_name) ? a.doc.doc_name : (a ? a.label : k);
}
function renderTabNames(){
    $('#mainTabs .rm-tab').each(function(){ $(this).find('.tn').text(kindName($(this).data('tab'))); });
}
function renderAsdocHint(){
    var out = [], miss = [];
    $.each(META.asdoc, function(k, v){
        if (v.doc) out.push(esc(v.doc.doc_no) + '（' + esc(v.doc.doc_name) + '）');
        else miss.push(esc(v.label) + '｜建議 ' + esc(v.fallback));
    });
    var h = out.length ? 'AS 文件：' + out.join('　') : '';
    if (miss.length) h += (h ? '　' : '') + '<span style="color:#DD5138;">尚未綁定：' + miss.join('、')
        + (META.perms.canAdmin ? '（請按「模組設定」綁定，列印版才會有表單名稱與文件編號）' : '（請洽管理員設定）') + '</span>';
    $('#asdocHint').html(h);
}
function roleLabel(){
    var p = META.perms;
    return p.isAdmin ? '管理者' : p.canAdmin ? '風險與機會管理員' : p.canView ? '風險與機會檢閱' : '一般員工（可建立與編輯自己的表單）';
}
function renderRoleHelp(){
    var h = '<ul><li><b>一般員工（不需指派角色）</b>：建立與編輯<b>自己建立的</b>表單。</li>';
    $.each(META.roles, function(_, r){ h += '<li><b>' + esc(r.role_name) + '（' + esc(r.role_code) + '）</b></li>'; });
    h += '<li><b>管理者</b>：固定擁有全部權限。</li></ul>'
       + '<div class="hint">檢閱角色可唯讀查看全部表單；管理員另可刪除與使用模組設定。目前登入身分：<b>' + esc(META.me.name)
       + '</b>，角色：<b>' + esc(roleLabel()) + '</b>。角色指派請至「使用者權限設定」頁。</div>';
    $('#helpRoleBody').html(h);
}

/* ---------- 風險等級（只用來在畫面即時預覽；存檔與列表以後端 rm_level_calc 為準） ---------- */
var LVCOL = [['#F7E0BD', '#5b3a1e'], ['#F0A24B', '#5b3a1e'], ['#DD5138', '#ffffff'], ['#8B2E1E', '#ffffff']];   // 與後端 RM_LEVEL_COLORS 同一組固定暖色盤
/* 嚴重度(S)／發生率(O)的選單用清單（title 顯示完整說明） */
function impactList(S){
    S = S || META.settings;
    return $.map(S.rm_impact.rows, function(r){
        return {v:r.v, label:r.label, desc:$.map(S.rm_impact.columns, function(c, i){ return c + '：' + (r.cells[i] || ''); }).join('；')};
    });
}
function likeList(S){
    S = S || META.settings;
    return $.map(S.rm_likelihood, function(r){ return {v:r.v, label:r.label, desc:(r.prob ? r.prob + ' ' : '') + (r.desc || '')}; });
}
/* S／LV 不給＝用目前生效的設定；設定跳窗預覽時傳入草稿 */
function oppTerm(k){ var t = (META.settings.rm_opp_terms || {}); return t[k] || {sev:'預期效益', freq:'實現可能性', level:'機會等級'}[k]; }
function isOpp(ro){ return ro === 'opportunity'; }
function calcLevel(sev, frq, S, LV, roKind){
    if (sev === '' || sev == null || frq === '' || frq == null) return null;
    S = S || META.settings; LV = LV || META.levels;
    if (!LV || !LV.length) return null;
    sev = +sev; frq = +frq;
    var okS = false, okF = false;
    $.each(S.rm_impact.rows, function(_, r){ if (+r.v === sev) okS = true; });
    $.each(S.rm_likelihood, function(_, r){ if (+r.v === frq) okF = true; });
    if (!okS || !okF) return null;
    var score = S.rm_method === 'add' ? sev + frq : (S.rm_method === 'max' ? Math.max(sev, frq) : sev * frq);
    var hit = LV[0];                                   // 低於最低一級的起算分數時歸最低級
    $.each(LV, function(i, lv){ if (score >= +lv.min) hit = lv; });
    var opp = isOpp(roKind);
    return {score:score, action:!!hit.action, bg:hit.bg, fg:hit.fg, is_opp:opp,
            name:(opp && hit.name_opp) ? hit.name_opp : hit.name,
            measure:(opp && hit.measure_opp) ? hit.measure_opp : (hit.measure || '')};
}
function lvBadge(lv, withScore){
    if (!lv) return '<span class="lvb none">未評估</span>';
    return '<span class="lvb" style="background:' + lv.bg + ';color:' + lv.fg + ';">' + esc(lv.name)
        + (withScore ? '（' + lv.score + '）' : '') + '</span>';
}

/* ================= 組織處境分析表（SWOT 看板，整家公司一個年度一張） ================= */
var BOARD = {year:0, doc:null, can_edit:true, dirty:false, years:[]};
var SWOTCOL = {S:['#F0A24B', '#5b3a1e'], W:['#DD5138', '#ffffff'], O:['#E8C98F', '#5b3a1e'], T:['#8B2E1E', '#ffffff']};   // 固定暖色盤，名稱另外有文字，不靠顏色辨識
function swotMeta(key){ var m = null; $.each(META.swot, function(_, x){ if (x.key === key) m = x; }); return m; }
function swotName(key){ var m = swotMeta(key); return m ? m.name : (key || ''); }
function boardHtml(){
    var h = '<div class="swot-bar">'
        + '<label>年度</label><select id="bYear" style="width:92px;"></select>'
        + '<label>填表日期</label><input type="date" id="bDate">'
        + '<span style="flex:1 1 20px;"></span>'
        + '<button id="bSuggest"><i class="fa fa-lightbulb-o"></i> 依系統資料建議</button>'
        + '<button id="bGuide"><i class="fa fa-question-circle"></i> 填寫提示與範例</button>'
        + '<button id="bPrint"><i class="fa fa-print"></i> 列印</button>'
        + '<button data-blank="context"><i class="fa fa-file-o"></i> 列印空白表單</button>'
        + '<button id="bSave" class="btn-warm"><i class="fa fa-save"></i> 儲存</button>'
        + '</div><div class="swot-status" id="bStatus"></div><span class="err" id="bErr"></span><div class="swot-grid">';
    $.each(META.swot, function(_, m){
        var c = SWOTCOL[m.key];
        h += '<div class="swot-q" data-key="' + m.key + '" style="--c:' + c[0] + ';--t:' + c[1] + ';">'
          + '<div class="swot-h"><span class="nm">' + esc(m.name) + '</span><small>' + m.en + '・' + (m.scope === 'internal' ? '內部' : '外部') + '</small><span class="cnt"></span></div>'
          + '<div class="swot-body"><div class="swot-purpose">' + esc(META.guide[m.key].purpose) + '</div>'
          + '<div class="swot-list"></div>'
          + '<button type="button" class="b-mini warm" data-addswot="' + m.key + '"><i class="fa fa-plus"></i> 新增一條</button>'
          + '<div class="swot-guide"><div class="g-h">想想看（填寫提示）</div><ul>'
          + $.map(META.guide[m.key].ask, function(a){ return '<li>' + esc(a) + '</li>'; }).join('') + '</ul>'
          + (META.guide[m.key].scan ? '<div class="g-h">🌍 放眼全球檢核（逐項想想，有影響就寫成一條）</div><ul class="scan">'
              + $.map(META.guide[m.key].scan, function(x){ return '<li><b>' + esc(x.cat) + '</b>：' + esc(x.hint) + '</li>'; }).join('') + '</ul>' : '')
          + '<div class="g-h">範例（點一下加入，再改成自己公司的情況）</div>'
          + $.map(META.guide[m.key].ex, function(e, i){
              return '<button type="button" class="swot-ex" data-ex="' + m.key + ':' + i + '"><b>' + esc(e.stakeholder) + '</b>｜' + esc(e.needs) + '<br>' + esc(e.issue) + '</button>'; }).join('')
          + '</div></div></div>';
    });
    return h + '</div>';
}
function itemHtml(it, n){
    return '<div class="swot-it" data-item-id="' + (it.item_id || 0) + '"><span class="no">' + n + '</span>'
        + '<button type="button" class="del" data-delswot title="刪除這一條"><i class="fa fa-times"></i></button>'
        + '<label>利害關係者</label><input type="text" data-f="stakeholder" value="' + esc(it.stakeholder) + '" maxlength="200">'
        + '<label>需求與期望</label><textarea data-f="needs">' + esc(it.needs) + '</textarea>'
        + '<label>議題描述</label><textarea data-f="issue">' + esc(it.issue) + '</textarea></div>';
}
function renumberBoard(){
    $('.swot-q').each(function(){
        var q = $(this), cards = q.find('.swot-it');
        cards.each(function(i){ $(this).find('.no').text(i + 1); });
        q.find('.cnt').text(cards.length + ' 條');
    });
}
function addSwot(key, it, focus){
    var q = $('.swot-q[data-key="' + key + '"]'), n = q.find('.swot-it').length + 1;
    q.find('.swot-list').append(itemHtml(it || {}, n));
    renumberBoard(); markDirty();
    if (focus) q.find('.swot-it:last [data-f=stakeholder]').focus();
}
function markDirty(){ BOARD.dirty = true; $('#bStatus').addClass('warn').text('內容尚未儲存，記得按右上「儲存」。'); }
function setBoardEditable(on){
    $('#pane_context .swot-it').toggleClass('ro', !on);
    $('#pane_context .swot-it input, #pane_context .swot-it textarea').prop('readonly', !on);
    $('#pane_context [data-addswot], #pane_context [data-delswot], #pane_context .swot-ex, #bSave, #bSuggest, #bDate').toggle(on);
}
function loadBoard(year){
    if (!year) year = +META.today.slice(0, 4);
    get({action:'ctx_year', year:year}, function(res){
        BOARD.year = res.year; BOARD.doc = res.doc; BOARD.can_edit = res.can_edit; BOARD.dirty = false; BOARD.years = res.years;
        var sel = $('#bYear').empty(), cy = +META.today.slice(0, 4);
        for (var y = cy; y >= cy - 8; y--) sel.append('<option value="' + y + '"' + (y === res.year ? ' selected' : '') + '>' + y + '</option>');
        $('.swot-list').empty();
        $.each(res.items, function(_, it){ if (swotMeta(it.dimension)) addSwotQuiet(it.dimension, it); });
        renumberBoard();
        $('#bDate').val(res.doc ? res.doc.fill_date : (res.year === cy ? META.today : res.year + '-12-31'));
        $('#bErr').text('');
        $('#bStatus').removeClass('warn').text(res.doc
            ? '單號 ' + res.doc.doc_no + '　填表人 ' + (res.doc.created_by_name || '') + '　最後更新 ' + dispDate((res.doc.updated_at || '').slice(0, 10))
              + (res.can_edit ? '' : '　（只有建立者與管理員可以修改）')
            : '尚未建立 ' + res.year + ' 年度組織處境分析表：填寫後按「儲存」即建立（整家公司一個年度只有一張）。');
        $('#pane_context').toggleClass('show-guide', !res.items.length);
        $('#bYear').prop('disabled', false);
        setBoardEditable(res.can_edit);
        $('#bPrint').toggle(!!res.doc);
        // 還沒建立且是未來年度：不可建（填表日期不可是未來）
        if (!res.doc && res.year > cy) setBoardEditable(false);
    });
}
function addSwotQuiet(key, it){
    var q = $('.swot-q[data-key="' + key + '"]'), n = q.find('.swot-it').length + 1;
    q.find('.swot-list').append(itemHtml(it, n));
}
function collectBoard(){
    var out = [];
    $('.swot-q').each(function(){
        var key = $(this).data('key');
        $(this).find('.swot-it').each(function(){
            out.push({item_id:+$(this).data('item-id') || 0, dimension:key,
                      stakeholder:$(this).find('[data-f=stakeholder]').val(), needs:$(this).find('[data-f=needs]').val(), issue:$(this).find('[data-f=issue]').val()});
        });
    });
    return out;
}
$(document).on('click', '[data-addswot]', function(){ addSwot($(this).data('addswot'), {}, true); });
$(document).on('click', '[data-delswot]', function(){
    var card = $(this).closest('.swot-it');
    var empty = !$.trim(card.find('[data-f=stakeholder]').val() + card.find('[data-f=needs]').val() + card.find('[data-f=issue]').val());
    if (!empty && !confirm('確定要刪除這一條嗎？')) return;
    card.remove(); renumberBoard(); markDirty();
});
$(document).on('input', '.swot-it input, .swot-it textarea', function(){ if (!BOARD.dirty) markDirty(); });
$(document).on('click', '[data-ex]', function(){
    var p = String($(this).data('ex')).split(':'), e = META.guide[p[0]].ex[+p[1]];
    addSwot(p[0], {stakeholder:e.stakeholder, needs:e.needs, issue:e.issue}, false);
});
$(document).on('click', '#bGuide', function(){ $('#pane_context').toggleClass('show-guide'); });
$(document).on('change', '#bYear', function(){
    var y = +$(this).val();
    if (BOARD.dirty && !confirm('目前的內容還沒儲存，切換年度會放棄這些修改。確定切換嗎？')){ $(this).val(BOARD.year); return; }
    loadBoard(y);
});
$(document).on('click', '#bSave', function(){
    var date = $('#bDate').val(); $('#bErr').text('');
    if (!date){ $('#bErr').text('請填寫填表日期'); return; }
    if (date > META.today){ $('#bErr').text('填表日期不可是未來日期'); return; }
    if (+date.slice(0, 4) !== BOARD.year){ $('#bErr').text('填表日期必須落在 ' + BOARD.year + ' 年度內（整家公司一個年度一張）'); return; }
    var items = collectBoard(), bad = false;
    $.each(items, function(_, it){ if (!$.trim(it.stakeholder + it.needs + it.issue)) return; });
    if (!$.grep(items, function(it){ return $.trim(it.stakeholder + it.needs + it.issue); }).length){ $('#bErr').text('至少要填寫一條內容'); return; }
    post({action:'doc_save', doc_id:BOARD.doc ? BOARD.doc.doc_id : 0, kind:'context', fill_date:date, items:JSON.stringify(items)}, function(){ loadBoard(BOARD.year); });
});
/* ---------- 依系統資料建議 ---------- */
var SUG = [];
function loadSug(dy){
    get({action:'suggest', year:BOARD.year, data_year:dy || 0}, function(res){
        SUG = res.rows;
        var sel = $('#sugDy').empty();
        for (var y = BOARD.year; y >= BOARD.year - 3; y--) sel.append('<option value="' + y + '">' + y + ' 年資料</option>');
        sel.val(String(res.data_year));
        if (res.warn && res.warn.length) console.warn('建議規則有錯誤：', res.warn);
        var b = $('#sugBody').empty();
        if (!SUG.length) b.html('<div class="rm-empty">目前系統裡沒有可參考的資料（沒有去年的表、沒有未結案的溝通反應、近一年也沒有品質異常單）。可改用「填寫提示與範例」。</div>');
        var last = '';
        $.each(SUG, function(i, r){
            if (r.source !== last){ b.append('<h4 style="color:#8A5A2B;font-size:14px;margin:10px 0 4px;">' + esc(r.source) + '</h4>'); last = r.source; }
            var opts = $.map(META.swot, function(m){ return '<option value="' + m.key + '"' + (m.key === r.key ? ' selected' : '') + '>' + esc(m.name) + '</option>'; }).join('');
            b.append('<div class="sg-row" data-sg="' + i + '"><div><b>' + esc(r.stakeholder || '（未填利害關係者）') + '</b>｜' + esc(r.needs) + (r.issue ? '<br>' + esc(r.issue) : '') + '</div>'
                + '<div class="why">' + esc(r.why) + '</div>'
                + '<div style="margin-top:4px;">加入到 <select class="sg-key">' + opts + '</select> <button type="button" class="b-mini warm" data-sgadd="' + i + '">加入</button></div></div>');
        });
        openMask('sugMask');
    });
}
$(document).on('click', '#bSuggest', function(){ loadSug(0); });
$('#sugDy').on('change', function(){ loadSug(+$(this).val()); });
$(document).on('click', '[data-sgadd]', function(){
    var i = +$(this).data('sgadd'), r = SUG[i], row = $(this).closest('.sg-row');
    addSwot(row.find('.sg-key').val(), {stakeholder:r.stakeholder, needs:r.needs, issue:r.issue}, false);
    row.addClass('done'); $(this).prop('disabled', true).text('已加入');
});
/* ---------- 列印（SWOT 版面） ---------- */
$(document).on('click', '#bPrint', function(){
    if (!BOARD.doc) { alert('請先儲存後再列印'); return; }
    get({action:'ctx_year', year:BOARD.year}, function(res){
        var d = res.doc, meta = res.print, byKey = {S:[], W:[], O:[], T:[]};
        $.each(res.items, function(_, it){ if (byKey[it.dimension]) byKey[it.dimension].push(it); });
        var quad = function(key){
            var m = swotMeta(key), rows = '';
            $.each(byKey[key], function(i, it){
                rows += '<tr><td>' + (i + 1) + '</td><td class="l">' + esc(it.stakeholder) + '</td><td class="l">' + esc(it.needs) + '</td><td class="l">' + esc(it.issue) + '</td></tr>';
            });
            if (!rows) rows = '<tr><td colspan="4">　</td></tr>';
            return '<td class="qd"><div class="qh">' + esc(m.name) + '　' + m.en + '（' + (m.scope === 'internal' ? '內部' : '外部') + '）</div>'
                + '<table class="pt in"><colgroup><col style="width:28px;"><col style="width:90px;"><col style="width:30%;"><col></colgroup>'
                + '<thead><tr><th>編號</th><th>利害關係者</th><th>需求與期望</th><th>議題描述</th></tr></thead><tbody>' + rows + '</tbody></table></td>';
        };
        var head = '<div class="pt-head"><div class="co">' + esc(meta.company) + '</div><div class="tt">' + esc(meta.doc_name || kindName('context')) + '</div></div>'
            + '<div class="pt-meta"><span>年度：' + BOARD.year + '</span><span>填表日期：' + esc(dispDate(d.fill_date)) + '</span></div>';
        var body = head + '<table class="grid"><tr>' + quad('S') + quad('W') + '</tr><tr>' + quad('O') + quad('T') + '</tr></table>';
        var css = 'table.grid{width:100%;border-collapse:separate;border-spacing:6px;table-layout:fixed;}table.grid>tbody>tr>td.qd{vertical-align:top;border:2px solid #333;padding:0;}'
            + '.qh{background:#EFEFEF;font-weight:bold;font-size:13px;padding:4px 8px;border-bottom:1px solid #333;}table.pt.in{margin:0;font-size:11px;}table.pt.in th,table.pt.in td{padding:3px 4px;}'
            + 'table.grid>tbody>tr{page-break-inside:avoid;}';
        egPrintWindow(meta.doc_name || kindName('context'), body, meta.doc_no, css);
        EGPrintLog.record({source:'risk_mgmt', doc_name:(meta.doc_name || kindName('context')) + ' ' + BOARD.year + ' 年度 ' + d.doc_no, doc_kind:'form', ref_table:'rm_doc', ref_id:d.doc_id});
    });
});
window.addEventListener('beforeunload', function(e){ if (BOARD.dirty){ e.preventDefault(); e.returnValue = ''; } });

/* ================= 分頁骨架 ================= */
function paneHtml(k){
    var isRisk = k === 'risk';
    return '<div class="rm-toolbar">'
        + '<button class="btn-warm" data-new="' + k + '"><i class="fa fa-plus"></i> 新增</button>'
        + '<label>年度</label><select data-year="' + k + '" style="width:92px;"></select>'
        + '<input type="text" data-kw="' + k + '" placeholder="關鍵字（單號／部門／議題／措施…）" style="width:280px;">'
        + (isRisk ? '<button class="btn-warm" data-gen="risk"><i class="fa fa-magic"></i> 由處境分析表產生</button>'
                    + '<button data-rating="1"><i class="fa fa-table"></i> 風險準則／評級對照</button>'
                    + '<button data-rules="1"><i class="fa fa-gavel"></i> 判斷規則</button>' : '')
        + '<button data-blank="' + k + '"><i class="fa fa-file-o"></i> 列印空白表單</button>'
        + '<button data-csv="' + k + '"><i class="fa fa-download"></i> 匯出 CSV</button>'
        + '</div>'
        + '<div class="rm-pager" data-pager="' + k + '"></div>'
        + '<div class="rm-table-wrap"><table class="rm-table"><thead><tr>'
        + '<th>單號</th><th>相關部門</th><th>填表日期</th><th>筆數</th>'
        + (isRisk ? '<th>最高風險等級</th><th>需因應措施</th>' : '')
        + '<th>填表人</th><th>操作</th></tr></thead><tbody data-body="' + k + '"></tbody></table></div>';
}
function fillYears(){
    var y = new Date().getFullYear();
    LISTKINDS.forEach(function(k){
        var s = $('[data-year="' + k + '"]').empty().append('<option value="0">全部</option>');
        for (var i = y + 1; i >= y - 8; i--) s.append('<option value="' + i + '"' + (i === y ? ' selected' : '') + '>' + i + '</option>');
    });
}
function loadList(k, resetPage){
    if (resetPage) STATE[k].page = 1;
    get({action:'doc_list', kind:k, page:STATE[k].page, per:STATE[k].per,
         year:$('[data-year="' + k + '"]').val() || 0, kw:$('[data-kw="' + k + '"]').val()}, function(res){
        var isRisk = k === 'risk', b = $('[data-body="' + k + '"]').empty();
        if (!res.rows.length) b.append('<tr><td colspan="' + (isRisk ? 8 : 6) + '" class="rm-empty">沒有符合條件的資料</td></tr>');
        $.each(res.rows, function(_, r){
            var ops = '<button class="b-mini" data-print="' + r.doc_id + '"><i class="fa fa-print"></i> 列印</button>'
                + '<button class="b-mini" data-open="' + r.doc_id + '" data-kind="' + k + '"><i class="fa fa-' + (r.can_edit ? 'pencil' : 'eye') + '"></i> '
                + (r.can_edit ? '編輯' : '檢視') + '</button>'
                + (META.perms.canAdmin ? '<button class="b-mini danger" data-del="' + r.doc_id + '"><i class="fa fa-trash"></i></button>' : '');
            b.append('<tr><td>' + esc(r.doc_no) + '</td><td class="l">' + esc(r.dept_names) + '</td>'
                + '<td>' + esc(dispDate(r.fill_date)) + '</td><td>' + r.item_cnt + '</td>'
                + (isRisk ? '<td>' + lvBadge(r.top_level, true) + '</td><td>' + (r.action_cnt > 0 ? r.action_cnt + ' 筆' : '—') + '</td>' : '')
                + '<td>' + esc(r.created_by_name) + '</td><td>' + ops + '</td></tr>');
        });
        renderPager(k, res);
    });
}
function renderPager(k, res){
    var per = res.per || 0, pages = per ? Math.max(1, Math.ceil(res.total / per)) : 1, p = res.page;
    var h = '共 ' + res.total + ' 筆　每頁 <select data-per="' + k + '">';
    [5, 10, 20, 50].forEach(function(n){ h += '<option value="' + n + '"' + (n === per ? ' selected' : '') + '>' + n + '</option>'; });
    h += '</select> <button data-pg="' + k + '" data-to="' + (p - 1) + '"' + (p <= 1 ? ' disabled' : '') + '>‹</button>'
       + '<span>' + p + ' / ' + pages + '</span>'
       + '<button data-pg="' + k + '" data-to="' + (p + 1) + '"' + (p >= pages ? ' disabled' : '') + '>›</button>';
    $('[data-pager="' + k + '"]').html(h);
}
$(document).on('click', '[data-pg]', function(){ var k = $(this).data('pg'); STATE[k].page = +$(this).data('to'); loadList(k); });
$(document).on('change', '[data-per]', function(){ var k = $(this).data('per'); STATE[k].per = +$(this).val(); loadList(k, true); });
$(document).on('change', '[data-year]', function(){ loadList($(this).data('year'), true); });
$(document).on('input', '[data-kw]', debounce(function(){ loadList($(this).data('kw'), true); }, 350));

$('#mainTabs').on('click', '.rm-tab', function(){
    var k = $(this).data('tab');
    $('#mainTabs .rm-tab').removeClass('on'); $(this).addClass('on');
    $('.rm-pane').removeClass('on'); $('#pane_' + k).addClass('on');
    if (k === 'context') loadBoard(BOARD.year); else loadList(k);       // 切換分頁一律刷新（點開即刷新）
});

/* ================= 表單編輯 ================= */
function colsOf(kind){
    // [欄位 key, 標題, 寬度px]
    if (kind === 'context') return [['seq','編號',52],['scope','內/外部議題',100],['dimension','構面',130],['stakeholder','利害關係者',150],
        ['needs','需求與期望',230],['issue','議題描述',300],['op','',44]];
    return [['seq','編號',52],['scope','內/外部議題',96],['stakeholder','利害關係者',130],['needs','需求與期望',170],['issue','議題描述',210],
        ['ro_text','對組織的影響說明',210],['ro_kind','風險與機會',92],['severity','嚴重度(S)',112],['frequency','發生率(O)',112],['level','風險等級',92],['confirm_no','風險確認編號',104],
        ['measure','因應措施',200],['recheck','重新評估',150],['effect','成效確認',170],['op','',44]];
}
function scaleOptions(list, val){
    var h = '<option value="">—</option>';
    $.each(list, function(_, r){ h += '<option value="' + r.v + '"' + (String(val) === String(r.v) ? ' selected' : '') + ' title="' + esc(r.desc) + '">' + r.v + ' ' + esc(r.label) + '</option>'; });
    // 舊資料用到已被拿掉的級距：保留原值顯示，免得一開啟就被清掉
    if (val !== '' && val != null){
        var found = false; $.each(list, function(_, r){ if (String(val) === String(r.v)) found = true; });
        if (!found) h += '<option value="' + val + '" selected>' + val + '（已不在設定內）</option>';
    }
    return h;
}
function trackHtml(it){
    var ts = it.tracks || [];
    if (!ts.length) return '<div class="trk none">尚未連結措施追蹤（在溝通管理建立追蹤項目，或在因應措施文字裡寫「措施追蹤 #編號」即可連結）</div>';
    var h = '<div class="trk"><b>相關措施追蹤（負責人／預計完成）</b>';
    $.each(ts, function(_, t){
        var cls = t.state === 'over' ? 'over' : (t.state === 'closed' ? 'done' : 'open');
        var txt = t.state === 'over' ? '逾期' : (t.state === 'closed' ? '已結案' : '執行中');
        h += '<div class="trk-row ' + cls + '">#' + t.id + '　' + esc(t.owner || '未指定負責人') + '　' + (t.due ? dispDate(t.due) : '未定日期')
           + '　<span class="trk-st">' + txt + '</span><div class="trk-ac">' + esc(t.action || '') + '</div></div>';
    });
    return h + '</div>';
}
function carHtml(it){
    // 成效「無效」且已存檔的列才給開單鈕；開過了就顯示單號並可開啟
    if (it.car_id) return '<div class="car-box"><a href="../QA/correction_order.php?open_id=' + (+it.car_id) + '" target="_blank">矯正單 ' + esc(it.car_no || ('#' + it.car_id)) + '</a></div>';
    if (it.effect_status === 'ineffective' && +it.item_id > 0)
        return '<div class="car-box"><button type="button" class="b-mini danger" data-opencar title="成效判定為無效，開立異常矯正處理單追根因"><i class="fa fa-wrench"></i> 開矯正單</button></div>';
    return '<div class="car-box"></div>';
}
function effOpts(cur){
    var h = '<option value="">未確認</option>';
    $.each(META.effect_status, function(k, t){ if (k !== 'unconfirmed') h += '<option value="' + k + '"' + (cur === k ? ' selected' : '') + '>' + esc(t) + '</option>'; });
    return h;
}
function ctxFlagHtml(it){
    if (!it.ctx_item_id) return '';
    return '<span class="ctx-tag">連動處境議題' + (it.ctx_doc_no ? ' ' + esc(it.ctx_doc_no) : '') + '</span> '
        + '<a href="#" data-unlinkctx style="font-size:11px;">解除連動</a>';
}
/* 連動中：四個欄位唯讀、跟著來源；解除後可手動修改 */
function lockCtxRow(tr, on){
    tr.find('[data-f=scope]').prop('disabled', on);
    tr.find('[data-f=stakeholder],[data-f=needs],[data-f=issue]').prop('readonly', on).css('background', on ? '#F5EEE2' : '');
}
function rowHtml(kind, it, n){
    it = it || {};
    var scopeSel = '<select data-f="scope"><option value="">—</option>'
        + $.map(META.scopes, function(t, k){ return '<option value="' + k + '"' + (it.scope === k ? ' selected' : '') + '>' + t + '</option>'; }).join('') + '</select>';
    var td = function(inner, cls){ return '<td' + (cls ? ' class="' + cls + '"' : '') + '>' + inner + '</td>'; };
    var ta = function(f, v){ return '<textarea data-f="' + f + '">' + esc(v) + '</textarea>'; };
    var h = '<tr data-item-id="' + (it.item_id || 0) + '" data-ctx="' + (it.ctx_item_id || '') + '" data-rules="' + esc(it.rule_ids || '') + '">' + td('#' + n, 'seq');
    if (kind === 'context'){
        var dopts = '<option value="">—</option>', hasDim = false;
        $.each(META.settings.rm_dimensions, function(_, d){ if (it.dimension === d) hasDim = true; dopts += '<option' + (it.dimension === d ? ' selected' : '') + '>' + esc(d) + '</option>'; });
        if (it.dimension && !hasDim) dopts += '<option selected>' + esc(it.dimension) + '</option>';
        h += td(scopeSel) + td('<select data-f="dimension">' + dopts + '</select>')
          + td(ta('stakeholder', it.stakeholder)) + td(ta('needs', it.needs)) + td(ta('issue', it.issue));
    } else {
        var roSel = '<select data-f="ro_kind"><option value="">—</option>'
            + $.map(META.ro_kinds, function(t, k){ return '<option value="' + k + '"' + (it.ro_kind === k ? ' selected' : '') + '>' + t + '</option>'; }).join('') + '</select>';
        var lv = calcLevel(it.severity, it.frequency, null, null, it.ro_kind);
        var opp = isOpp(it.ro_kind);
        var fd = function(label, inner, cls){ return '<td class="' + cls + '"><label class="fl">' + label + '</label>' + inner + '</td>'; };
        // 議題描述：連動組織處境分析表的議題。連動中時，內外部／利害關係者／需求與期望／議題描述都跟著來源（唯讀）
        var issueCell = ta('issue', it.issue)
            + '<div class="ctx-flag" style="margin-top:3px;">' + ctxFlagHtml(it) + '</div>'
            + '<button type="button" class="b-mini warm" data-pickctx style="margin-top:3px;"><i class="fa fa-link"></i> 選擇處境議題</button>';
        h += fd('內/外部議題', scopeSel, 'c-scope') + fd('利害關係者', ta('stakeholder', it.stakeholder), 'c-sh') + fd('需求與期望', ta('needs', it.needs), 'c-needs')
          + fd('議題描述', issueCell, 'c-issue') + fd('對組織的影響說明', ta('ro_text', it.ro_text), 'c-ro')
          + fd('風險與機會', roSel, 'c-kind')
          + fd('<span class="lb-sev">' + (opp ? esc(oppTerm('sev')) : '嚴重度(S)') + '</span>', '<select data-f="severity">' + scaleOptions(impactList(), it.severity) + '</select>', 'c-sev')
          + fd('<span class="lb-frq">' + (opp ? esc(oppTerm('freq')) : '發生率(O)') + '</span>', '<select data-f="frequency">' + scaleOptions(likeList(), it.frequency) + '</select>', 'c-frq')
          + fd('<span class="lb-lv">' + (opp ? esc(oppTerm('level')) : '風險等級') + '</span>', lvBadge(lv, true), 'c-lv lvc')
          + fd('風險確認編號 <i class="fa fa-question-circle" style="cursor:help;color:#b5762a;" title="需要因應措施的風險由系統自動配發的流水號（例 R2026-001），讓這條風險在措施追蹤、管理審查、矯正單被引用時有唯一編號可對照；已配發的不會因設定變動而改變。"></i>', '<input type="text" data-f="confirm_no" value="' + esc(it.confirm_no) + '" maxlength="30" placeholder="自動配發" data-eg-skip>', 'c-cn')
          + fd('因應措施', ta('measure', it.measure) + trackHtml(it), 'c-measure') + fd('重新評估（年度中途因重大變化時填寫）', '<input type="date" data-f="recheck_date" value="' + esc(it.recheck_date || '') + '">' + ta('recheck_note', it.recheck_note), 'c-rc')
          + fd('成效確認方式（指標與目標，年初設定）', ta('effect_check', it.effect_check), 'c-eff')
          + fd('成效確認結果', '<select data-f="effect_status">' + effOpts(it.effect_status) + '</select>' + carHtml(it), 'c-es')
          + fd('確認日期', '<input type="date" data-f="effect_date" value="' + esc(it.effect_date || '') + '">', 'c-ed')
          + fd('裁示', ta('effect_ruling', it.effect_ruling), 'c-er')
          + fd('佐證 <button type="button" class="b-mini warm" data-evidence><i class="fa fa-search"></i> 依資料產生佐證／建議結果</button>', ta('effect_evidence', it.effect_evidence) + '<div class="ev-reason"></div>', 'c-ev');
    }
    return h + td('<button type="button" class="b-mini danger" data-delrow title="刪除此列"><i class="fa fa-times"></i></button>', 'op') + '</tr>';
}
function buildTable(kind, items){
    var cols = colsOf(kind), t = $('#itTbl').empty();
    var w = 0; cols.forEach(function(c){ w += c[2]; });
    t.toggleClass('cards', kind === 'risk'); $('#itWrap').toggleClass('cards', kind === 'risk');
    t.css('width', kind === 'risk' ? '' : w + 'px');
    var h = '<colgroup>' + cols.map(function(c){ return '<col style="width:' + c[2] + 'px;">'; }).join('') + '</colgroup><thead>';
    if (kind === 'risk'){
        h += '<tr><th rowspan="2">編號</th><th rowspan="2">內/外部議題</th><th rowspan="2">利害關係者</th><th rowspan="2">需求與期望</th><th rowspan="2">議題描述</th>'
          + '<th rowspan="2">對組織的影響說明</th><th rowspan="2">風險與機會</th><th colspan="3">風險評估</th><th rowspan="2">風險確認編號</th><th rowspan="2">因應措施</th><th rowspan="2">成效確認</th><th rowspan="2"></th></tr>'
          + '<tr><th>嚴重度(S)</th><th>發生率(O)</th><th>風險等級</th></tr>';
    } else {
        h += '<tr>' + cols.map(function(c){ return '<th>' + c[1] + '</th>'; }).join('') + '</tr>';
    }
    t.append(h + '</thead><tbody id="itBody" data-eg-row-add="rmAddRow" data-eg-row-del="rmDelLastRow"></tbody>');
    var b = $('#itBody');
    (items.length ? items : [{}]).forEach(function(it, i){ b.append(rowHtml(kind, it, i + 1)); });
}
function renumber(){ $('#itBody tr').each(function(i){ $(this).find('td.seq').text('#' + (i + 1)); }); }
/* 共用檔 eg_input_rules.js 會「不帶參數」呼叫這兩支：沒給參數就自己找最後一列 */
function rmAddRow(){
    if (!CUR || !CUR.can_edit) return;
    $('#itBody').append(rowHtml(CUR.kind, {}, $('#itBody tr').length + 1));
    return $('#itBody tr:last')[0];
}
function rmDelLastRow(){
    if (!CUR || !CUR.can_edit) return;
    var rows = $('#itBody tr'); if (rows.length <= 1) return;
    rows.last().remove(); renumber();
}
$('#btnAddRow').on('click', function(){ rmAddRow(); });
$(document).on('click', '[data-delrow]', function(){
    if (!CUR || !CUR.can_edit) return;
    if ($('#itBody tr').length <= 1){ $(this).closest('tr').find('textarea,input[type=text]').val(''); $(this).closest('tr').find('select').val(''); return; }
    $(this).closest('tr').remove(); renumber();
});
/* 嚴重度(S)／發生率(O)一改就即時更新該列的風險等級預覽 */
$(document).on('change', '#itBody select[data-f=severity], #itBody select[data-f=frequency]', function(){
    var tr = $(this).closest('tr');
    var ro = tr.find('[data-f=ro_kind]').val(), op = isOpp(ro);
    tr.find('.lb-sev').text(op ? oppTerm('sev') : '嚴重度(S)');
    tr.find('.lb-frq').text(op ? oppTerm('freq') : '發生率(O)');
    tr.find('.lb-lv').text(op ? oppTerm('level') : '風險等級');
    tr.find('td.lvc').html(lvBadge(calcLevel(tr.find('[data-f=severity]').val(), tr.find('[data-f=frequency]').val(), null, null, ro), true));
});

function renderDeptChips(){
    var h = '';
    $.each(CUR.dept_ids, function(_, id){
        var nm = ''; $.each(META.depts, function(_, d){ if (+d.id === +id) nm = d.name; });
        h += '<span class="chip">' + esc(nm) + (CUR.can_edit ? ' <i class="fa fa-times" data-rmdept="' + id + '"></i>' : '') + '</span>';
    });
    $('#deptChips').html(h || '<span class="hint">（未選擇）</span>');
    var s = $('#deptAdd').empty().append('<option value="">＋ 加入相關部門…</option>');
    $.each(META.depts, function(_, d){ if (CUR.dept_ids.indexOf(+d.id) < 0) s.append('<option value="' + d.id + '">' + esc(d.name) + '</option>'); });
    $('#deptAdd').toggle(CUR.can_edit);
}
$('#deptAdd').on('change', function(){
    var v = +$(this).val(); if (!v) return;
    CUR.dept_ids.push(v); renderDeptChips();
});
$(document).on('click', '[data-rmdept]', function(){
    var id = +$(this).data('rmdept'); CUR.dept_ids = CUR.dept_ids.filter(function(x){ return x !== id; }); renderDeptChips();
});

function openDoc(kind, id){
    var show = function(doc, items, canEdit, pr){
        CUR = {kind:kind, doc_id:doc ? +doc.doc_id : 0, can_edit:canEdit, print:pr,
               dept_ids:doc && doc.dept_ids ? (JSON.parse(doc.dept_ids) || []).map(Number) : []};
        $('#docTitle').text(kindName(kind) + (doc ? '　' + doc.doc_no : '　新增'));
        $('#fDate').val(doc ? doc.fill_date : ((NEWYEAR && NEWYEAR < +META.today.slice(0, 4)) ? NEWYEAR + '-12-31' : META.today)); NEWYEAR = 0;
        $('#fNo').val(doc ? doc.doc_no : '');
        $('#eDate, #eItems').text(''); $('#fDate').removeClass('bad');
        $('#itemHint').html(kind === 'risk'
            ? '嚴重度(S)與發生率(O)要一起選；<b>風險等級由系統依目前設定自動算出</b>。按「選擇處境議題」可連動組織處境分析表的議題。整家公司一個年度只有一張，年度中途有變動請直接在這一張上更新。'
            : '構面清單由管理員在「模組設定」維護。');
        buildTable(kind, items);
        renderDeptChips();
        $('#docMask .rm-mbd').find('input,select,textarea').not('#fNo,[data-eg-skip]').prop('disabled', !canEdit);
        $('#itBody').find('input,select,textarea').prop('disabled', !canEdit);
        if (canEdit) $('#itBody tr').each(function(){ if (+$(this).attr('data-ctx')) lockCtxRow($(this), true); });
        $('#itBody [data-pickctx], #itBody [data-delrow], #itBody [data-unlinkctx], #btnDocSave, #addRowBox').toggle(canEdit);
        $('#btnDocPrint').toggle(!!doc);
        openMask('docMask');
    };
    if (!id){ show(null, [], true, null); return; }
    get({action:'doc_get', doc_id:id}, function(res){ show(res.doc, res.items, res.can_edit, res.print); });
}
/* 一個年度只有一張：按「新增」時先看選的年度（沒選＝今年）有沒有現成的，有就直接開啟那一張 */
var NEWYEAR = 0;
$(document).on('click', '[data-new]', function(){
    var k = $(this).data('new'), cy = +META.today.slice(0, 4), y = +$('[data-year="' + k + '"]').val() || cy;
    get({action:'doc_list', kind:k, year:y, per:5}, function(res){
        if (res.rows.length){
            alert(y + ' 年度已經有「' + kindName(k) + '」（單號 ' + res.rows[0].doc_no + '），整家公司一個年度只會有一張，已為您開啟，年度中途有變動請直接在這一張上更新。');
            openDoc(k, +res.rows[0].doc_id);
        } else { NEWYEAR = y; openDoc(k, 0); }
    });
});
$(document).on('click', '[data-open]', function(){ openDoc($(this).data('kind'), +$(this).data('open')); });
$(document).on('click', '[data-del]', function(){
    var id = +$(this).data('del');
    if (!confirm('確定要刪除這張表單嗎？刪除後清單與風險表引用都不會再出現。')) return;
    post({action:'doc_delete', doc_id:id}, function(){ LISTKINDS.forEach(function(k){ loadList(k); }); });
});

function collectItems(){
    var out = [];
    $('#itBody tr').each(function(){
        var tr = $(this), o = {item_id:+tr.data('item-id') || 0, ctx_item_id:+tr.attr('data-ctx') || 0, rule_ids:tr.attr('data-rules') || ''};
        tr.find('[data-f]').each(function(){ o[$(this).data('f')] = $(this).val(); });
        out.push(o);
    });
    return out;
}
$('#btnDocSave').on('click', function(){
    var date = $('#fDate').val();
    $('#eDate, #eItems').text(''); $('#fDate').removeClass('bad');
    if (!date){ $('#eDate').text('請填寫填表日期'); $('#fDate').addClass('bad'); return; }
    if (date > META.today){ $('#eDate').text('填表日期不可是未來日期'); $('#fDate').addClass('bad'); return; }
    var items = collectItems(), bad = '';
    if (CUR.kind === 'risk'){
        $.each(items, function(i, it){
            if (!bad && ((it.severity === '') !== (it.frequency === ''))) bad = '第 ' + (i + 1) + ' 列的嚴重度(S)與發生率(O)要一起填（缺一個算不出風險等級）';
        });
    }
    if (bad){ $('#eItems').text(bad); return; }
    post({action:'doc_save', doc_id:CUR.doc_id, kind:CUR.kind, fill_date:date,
          dept_ids:JSON.stringify(CUR.dept_ids), items:JSON.stringify(items)}, function(res){
        closeMask('docMask');
        loadList(CUR.kind);
    });
});

/* ---------- 引用組織處境議題 ---------- */
$(document).on('click', '[data-pickctx]', function(){
    CTXROW = $(this).closest('tr'); $('#ctxKw').val(''); loadCtx(); openMask('ctxMask');
});
function loadCtx(){
    get({action:'ctx_options', kw:$('#ctxKw').val()}, function(res){
        var b = $('#ctxBody').empty();
        if (!res.rows.length) b.append('<tr><td colspan="6" class="rm-empty">沒有可引用的組織處境議題（請先在「' + esc(kindName('context')) + '」分頁建立）</td></tr>');
        $.each(res.rows, function(_, r){
            b.append('<tr><td>' + esc(r.doc_no) + '</td><td>' + esc(META.scopes[r.scope] || '') + '</td><td>' + esc(swotName(r.dimension)) + '</td>'
                + '<td class="l">' + esc(r.stakeholder) + '</td><td class="l">' + esc(r.issue) + '</td>'
                + '<td><button class="b-mini warm" data-usectx="' + r.item_id + '">選用</button></td></tr>')
              .find('button:last').data('row', r);
        });
    });
}
$('#ctxKw').on('input', debounce(loadCtx, 300));
$(document).on('click', '[data-usectx]', function(){
    var r = $(this).data('row'); if (!CTXROW || !r) return;
    CTXROW.attr('data-ctx', r.item_id);
    CTXROW.find('[data-f=scope]').val(r.scope || '');
    CTXROW.find('[data-f=stakeholder]').val(r.stakeholder || '');
    CTXROW.find('[data-f=needs]').val(r.needs || '');
    CTXROW.find('[data-f=issue]').val(r.issue || '');
    CTXROW.find('.ctx-flag').html(ctxFlagHtml({ctx_item_id:r.item_id, ctx_doc_no:r.doc_no}));
    lockCtxRow(CTXROW, true);
    closeMask('ctxMask');
});
$(document).on('click', '[data-unlinkctx]', function(e){
    e.preventDefault();
    var tr = $(this).closest('tr');
    if (!confirm('解除連動後，這一列的內/外部議題、利害關係者、需求與期望、議題描述就不再跟著組織處境分析表變動（目前內容會保留，可自行修改）。確定嗎？')) return;
    tr.attr('data-ctx', ''); tr.find('.ctx-flag').empty(); lockCtxRow(tr, false);
});

/* ---------- 評級對照表（完全由設定產生） ---------- */
function renderRating(){
    var S = META.settings, H = '<h4 style="color:#8A5A2B;margin:12px 0 4px;">', h = '';
    h += H + '表A、風險準則</h4><table class="mx"><thead><tr><th>風險等級</th><th>評分</th><th>處理措施</th><th>需要因應措施</th>'
       + '<th>' + esc(oppTerm('level')) + '</th><th>機會的處理措施</th></tr></thead><tbody>';
    $.each(META.levels.slice().reverse(), function(_, lv){
        h += '<tr><td>' + lvBadge(lv, false) + '</td><td>' + esc(lv.range) + '</td><td style="text-align:left;">' + esc(lv.measure) + '</td><td>' + (lv.action ? '是' : '否') + '</td>'
           + '<td>' + esc(lv.name_opp || lv.name) + '</td><td style="text-align:left;">' + esc(lv.measure_opp || lv.measure) + '</td></tr>';
    });
    h += '</tbody></table><div class="hint" style="margin:-4px 0 8px;">同一組評分同時給風險與機會用，只是顯示的名稱與處理措施不同：風險問「後果多嚴重、多常發生」，機會問「'
       + esc(oppTerm('sev')) + '多大、' + esc(oppTerm('freq')) + '多高」。</div>';
    h += H + '表B、嚴重度(S)分析</h4><table class="mx"><thead><tr><th>等級</th><th>嚴重度(S)</th>';
    $.each(S.rm_impact.columns, function(_, c){ h += '<th>' + esc(c) + '</th>'; });
    h += '</tr></thead><tbody>';
    $.each(S.rm_impact.rows.slice().reverse(), function(_, r){
        h += '<tr><td>' + r.v + '</td><td>' + esc(r.label) + '</td>';
        $.each(S.rm_impact.columns, function(i){ h += '<td style="text-align:left;">' + esc(r.cells[i] || '') + '</td>'; });
        h += '</tr>';
    });
    h += '</tbody></table>';
    h += H + '表C、發生率(O)分析</h4><table class="mx"><thead><tr><th>等級</th><th>發生率(O)分類</th><th>發生機率百分比</th><th>詳細的描述</th></tr></thead><tbody>';
    $.each(S.rm_likelihood.slice().reverse(), function(_, r){
        h += '<tr><td>' + r.v + '</td><td>' + esc(r.label) + '</td><td>' + esc(r.prob) + '</td><td style="text-align:left;">' + esc(r.desc) + '</td></tr>';
    });
    h += '</tbody></table>';
    h += H + '表D、風險分布（' + {multiply:'嚴重度(S)等級 × 發生率(O)等級', add:'嚴重度(S)等級 ＋ 發生率(O)等級', max:'取較大者'}[S.rm_method] + '；只計風險、不含機會；格內小字＝目前實際落在此格的風險筆數）</h4>'
       + '<table class="mx"><thead><tr><th>嚴重度(S)＼發生率(O)</th>';
    $.each(S.rm_likelihood, function(_, f){ h += '<th>' + esc(f.label) + '（' + f.v + '）</th>'; });
    h += '</tr></thead><tbody>';
    $.each(S.rm_impact.rows.slice().reverse(), function(_, s){
        h += '<tr><th>' + esc(s.label) + '（' + s.v + '）</th>';
        $.each(S.rm_likelihood, function(_, f){
            h += '<td data-cell="' + s.v + '-' + f.v + '">' + lvBadge(calcLevel(s.v, f.v), true) + '<div class="cnt" style="font-size:11px;color:#8a6d45;"></div></td>';
        });
        h += '</tr>';
    });
    $('#ratingBody').html(h + '</tbody></table>');
    get({action:'dist'}, function(res){
        $.each(res.cells, function(_, c){
            $('#ratingBody [data-cell="' + c.severity + '-' + c.frequency + '"] .cnt').text(c.c + ' 筆');
        });
    });
}
$(document).on('click', '[data-rating]', function(){ renderRating(); openMask('ratingMask'); });

/* ---------- 判斷規則 ---------- */
var RULES = [];
function renderRules(){
    var k = $('#ruleKind').val(), b = $('#ruleBody').empty();
    $.each(RULES, function(_, r){
        if (k && r.kind !== k) return;
        b.append('<tr><td><b>' + esc(r.id) + '</b></td><td>' + esc(r.kind) + '</td><td class="l">' + esc(r.cat) + '</td><td class="l">' + esc(r.when) + '</td><td class="l">' + esc(r.then) + '</td></tr>');
    });
}
$(document).on('click', 'button[data-rules]', function(){
    get({action:'rules_list'}, function(res){
        RULES = res.rules;
        var kinds = {}; $.each(RULES, function(_, r){ kinds[r.kind] = 1; });
        var s = $('#ruleKind').empty().append('<option value="">全部（' + RULES.length + ' 條）</option>');
        $.each(kinds, function(k){ s.append('<option>' + esc(k) + '</option>'); });
        renderRules(); openMask('ruleMask');
    });
});
$('#ruleKind').on('change', renderRules);

/* ---------- 由組織處境分析表產生風險與機會因應措施管制表（規則引擎） ---------- */
$(document).on('click', '[data-gen]', function(){
    var cy = +META.today.slice(0, 4), y = +$('[data-year="risk"]').val() || cy;
    if (!confirm('依「' + y + ' 年度組織處境分析表」，用規則替每個議題（劣勢、機會、威脅）產生風險／機會草稿：\n'
        + '影響說明、嚴重度(S)、發生率(O)、因應措施、成效確認方式都會依規則帶入。\n\n'
        + '・已經有對應風險項目的議題不會重複產生\n・優勢（S）象限不產生風險項目\n・產生的是草稿，請人工檢查後再使用（可按「判斷規則」看依據）\n\n確定要產生嗎？')) return;
    post({action:'risk_generate', year:y}, function(res){
        var lines = $.map(res.summary, function(s){ return '・' + s.issue + '…　→　' + s.category + '／' + (s.level || '未評估'); });
        alert('已' + (res.created ? '建立 ' + y + ' 年度風險與機會因應措施管制表並' : '') + '產生 ' + res.added + ' 條風險／機會草稿（評分依據 ' + res.data_year + ' 年資料）：\n\n' + lines.join('\n')
            + '\n\n請逐條檢查；每一條的「判斷依據」可在卡片的規則編號（滑鼠移到編號上）與「判斷規則」查看。');
        loadList('risk', true); openDoc('risk', +res.doc_id);
    });
});

/* ---------- 成效確認：依規則蒐集佐證並建議結果 ---------- */
$(document).on('click', '[data-opencar]', function(){
    var tr = $(this).closest('tr'), iid = +tr.attr('data-item-id') || 0, $b = $(this);
    if (!iid){ alert('請先儲存這張表單再開矯正單'); return; }
    if (tr.find('[data-f=effect_status]').val() !== 'ineffective'){ alert('成效確認結果不是「無效」，不能開立矯正單'); return; }
    if (!confirm('要為這條風險開立異常矯正處理單嗎？\n（一條風險只能開一張，開立後會通知矯正單的指派人員）')) return;
    $b.prop('disabled', true);
    post({action:'risk_open_car', item_id:iid}, function(res){
        tr.find('.car-box').html('<a href="../QA/correction_order.php?open_id=' + (+res.car_id) + '" target="_blank">矯正單 ' + esc(res.car_no) + '</a>');
        alert((res.existed ? '這條風險已經開過矯正單：' : '已開立矯正單：') + res.car_no);
    });
    setTimeout(function(){ $b.prop('disabled', false); }, 4000);      // 失敗時（錯誤訊息由全站 ajax 錯誤處理顯示）讓按鈕可以再按
});
$(document).on('click', '[data-evidence]', function(){
    var tr = $(this).closest('tr'), o = {ctx_item_id:+tr.attr('data-ctx') || 0};
    tr.find('[data-f]').each(function(){ o[$(this).data('f')] = $(this).val(); });
    var year = +(($('#fDate').val() || META.today).slice(0, 4));
    var $ev = tr.find('[data-f=effect_evidence]'), $why = tr.find('.ev-reason');
    post({action:'risk_evidence', row:JSON.stringify(o), year:year}, function(res){
        var e = res.evidence;
        if ($.trim($ev.val()) && $ev.val() !== e.text && !confirm('佐證欄已經有內容，要用系統剛產生的佐證覆蓋嗎？')) return;
        $ev.val(e.text);
        var $st = tr.find('[data-f=effect_status]');
        if (!$st.val() && e.status !== 'unconfirmed') $st.val(e.status);
        var $dt = tr.find('[data-f=effect_date]'); if (!$dt.val() && e.status !== 'unconfirmed') $dt.val(META.today);
        $why.html('<b>系統建議：' + esc(META.effect_status[e.status] || '') + '</b>（' + esc(e.reason) + '）　依據規則：' + esc(e.rule_ids) + '。結果與裁示請由主管確認後再存檔。'
            + (e.recheck_hint ? '<br><b style="color:#DD5138;">⚠ 建議重新評估：</b>' + esc(e.recheck_hint) : ''));
    });
});
// 滑鼠移到卡片編號上可看這一列是用哪些規則產生的
$(document).on('mouseenter', '#itBody tr td.seq', function(){ var r = $(this).closest('tr').attr('data-rules'); $(this).attr('title', r ? '產生這一條所依據的規則：' + r + '（按「判斷規則」查看說明）' : '手動新增，沒有套用規則'); });

/* ================= 模組設定 ================= */
var LVCOLS = LVCOL;
/* ---- 風險準則（表A）：評分區間 ---- */
function lvlRowHtml(r){
    var saved = !!r.saved;
    return '<tr data-orig="' + esc(saved ? r.name : '') + '">'
        + '<td><input type="text" data-l="name" value="' + esc(r.name) + '" maxlength="10" style="width:100px;"' + (saved ? ' readonly title="已儲存的風險等級名稱不提供修改（要改請刪除後重新新增）" style="width:100px;background:#F5EEE2;"' : '') + '></td>'
        + '<td><input type="number" class="lv-lo" value="' + esc(r.lo) + '" readonly tabindex="-1" style="width:64px;background:#F5EEE2;" title="自動＝上一級上限＋1"></td>'
        + '<td><input type="number" class="lv-hi" data-l="hi" value="' + esc(r.hi) + '" min="1" step="1" style="width:64px;"></td>'
        + '<td><input type="text" data-l="measure" value="' + esc(r.measure || '') + '" maxlength="200"></td>'
        + '<td style="text-align:center;"><input type="checkbox" data-l="action"' + (r.action ? ' checked' : '') + '></td>'
        + '<td><input type="text" data-l="name_opp" value="' + esc(r.name_opp || '') + '" maxlength="10" style="width:100px;" placeholder="（同風險名稱）"></td>'
        + '<td><input type="text" data-l="measure_opp" value="' + esc(r.measure_opp || '') + '" maxlength="200" placeholder="（同風險處理措施）"></td>'
        + '<td><button type="button" class="b-mini danger" data-rmrow="lvl"><i class="fa fa-times"></i></button></td></tr>';
}
/* ---- 嚴重度(S)分析（表B）：等級數字自動 ---- */
function impRowHtml(cols, r){
    var h = '<tr data-orig="' + (+r.orig || 0) + '"><td class="lvno" style="text-align:center;font-weight:bold;"></td>'
        + '<td><input type="text" data-i="label" value="' + esc(r.label) + '" maxlength="20" style="width:110px;"></td>';
    $.each(cols, function(i, c){ h += '<td><input type="text" data-ic="' + i + '" value="' + esc((r.cells || [])[i] || '') + '" maxlength="100" placeholder="' + esc(c) + '"></td>'; });
    return h + '<td><button type="button" class="b-mini danger" data-rmrow="imp"><i class="fa fa-times"></i></button></td></tr>';
}
/* ---- 發生率(O)評估（表C）：等級數字自動、機率為上下限 ---- */
function likRowHtml(r){
    return '<tr data-orig="' + (+r.orig || 0) + '"><td class="lvno" style="text-align:center;font-weight:bold;"></td>'
        + '<td><input type="text" data-k="label" value="' + esc(r.label) + '" maxlength="20" style="width:120px;"></td>'
        + '<td style="white-space:nowrap;"><input type="number" class="lk-lo" value="' + esc(r.lo) + '" readonly tabindex="-1" style="width:60px;background:#F5EEE2;" title="自動＝上一級上限＋1"> %</td>'
        + '<td style="white-space:nowrap;"><input type="number" class="lk-hi" data-k="hi" value="' + esc(r.hi) + '" min="0" max="100" step="1" style="width:60px;"> %</td>'
        + '<td><input type="text" data-k="desc" value="' + esc(r.desc || '') + '" maxlength="200"></td>'
        + '<td><button type="button" class="b-mini danger" data-rmrow="lik"><i class="fa fa-times"></i></button></td></tr>';
}
var IMPCOLS = [];     // 嚴重度(S)分析目前的說明欄名稱（改欄名時依舊名稱保留各格內容）
function impColsDraft(){
    var out = [];
    $('#setImpCols').val().split('\n').forEach(function(x){ x = $.trim(x); if (x && out.indexOf(x) < 0) out.push(x); });
    return out.slice(0, 6);
}
function readImp(){
    var rows = [];
    $('#setImp tbody tr').each(function(i){
        var cells = {};
        $(this).find('[data-ic]').each(function(){ cells[IMPCOLS[+$(this).data('ic')]] = $(this).val(); });
        rows.push({orig:+$(this).attr('data-orig') || 0, v:i + 1, label:$(this).find('[data-i=label]').val(), byName:cells});
    });
    return rows;
}
function renderImpTable(cols, rows){
    var t = '<table class="rm-table fld"><thead><tr><th style="width:64px;">等級</th><th style="width:120px;">嚴重度(S)</th>';
    $.each(cols, function(_, c){ t += '<th>' + esc(c) + '</th>'; });
    t += '<th style="width:44px;"></th></tr></thead><tbody>';
    $.each(rows, function(_, r){
        var cells = $.map(cols, function(c, i){ return r.byName ? (r.byName[c] || '') : ((r.cells || [])[i] || ''); });
        t += impRowHtml(cols, {orig:r.orig, label:r.label, cells:cells});
    });
    $('#setImp').html(t + '</tbody></table>');
    IMPCOLS = cols.slice();
    renumberLevels();
}
function readLik(){
    var out = [];
    $('#setLik tbody tr').each(function(i){
        out.push({orig:+$(this).attr('data-orig') || 0, v:i + 1, label:$(this).find('[data-k=label]').val(), hi:$(this).find('.lk-hi').val(), desc:$(this).find('[data-k=desc]').val()});
    });
    return out;
}
function readLvl(){
    var lv = [];
    $('#setLvl tbody tr').each(function(){
        lv.push({name:$(this).find('[data-l=name]').val(), orig_name:$(this).attr('data-orig') || '', hi:$(this).find('.lv-hi').val(),
                 measure:$(this).find('[data-l=measure]').val(), action:$(this).find('[data-l=action]').is(':checked'),
                 name_opp:$(this).find('[data-l=name_opp]').val(), measure_opp:$(this).find('[data-l=measure_opp]').val()});
    });
    return lv;
}
function renumberLevels(){
    $('#setImp tbody tr').each(function(i){ $(this).find('.lvno').text(i + 1); });
    $('#setLik tbody tr').each(function(i){ $(this).find('.lvno').text(i + 1); });
}
/* 目前草稿下可能的最高分（嚴重度(S)最高等級 × 發生率(O)最高等級，依算法） */
function draftMaxScore(){
    var a = $('#setImp tbody tr').length, b = $('#setLik tbody tr').length, m = $('#setMethod').val();
    return m === 'add' ? a + b : (m === 'max' ? Math.max(a, b) : a * b);
}
/* 可能性：下限自動串接、最後一級上限固定 100；fix=true 時把不合理的上限自動修正（離開欄位時），否則只回報錯誤 */
function relinkLik(fix){
    var rows = $('#setLik tbody tr'), n = rows.length, prev = -1, errs = [];
    rows.each(function(i){
        var tr = $(this), lo = i === 0 ? 0 : prev + 1, last = i === n - 1, name = $.trim(tr.find('[data-k=label]').val()) || ('第 ' + (i + 1) + ' 級'), hi;
        tr.find('.lk-lo').val(lo);
        var hiEl = tr.find('.lk-hi'); hiEl.removeClass('bad');
        if (last){ hi = 100; hiEl.val(100).prop('readonly', true).css('background', '#F5EEE2').attr('title', '最後一級固定到 100%'); }
        else {
            hiEl.prop('readonly', false).css('background', '').removeAttr('title');
            var raw = hiEl.val(), cap = 100 - (n - 1 - i);
            hi = Math.round(+raw);
            var bad = raw === '' || isNaN(+raw) || +raw !== hi || hi < lo || hi > cap;
            if (bad){
                if (fix){ hi = raw === '' || isNaN(+raw) ? lo : Math.min(Math.max(hi, lo), cap); hiEl.val(hi); }
                else { hiEl.addClass('bad'); errs.push('「' + name + '」的發生機率上限要是 ' + lo + '～' + cap + ' 之間的整數（下限 ' + lo + '%；後面每一級至少要留 1%）'); }
            }
        }
        prev = hi;
    });
    return errs;
}
/* 風險準則：下限自動串接、第一級從 1、最後一級上限固定＝最高分 */
function relinkLvl(fix){
    var rows = $('#setLvl tbody tr'), n = rows.length, max = draftMaxScore(), prev = 0, errs = [];
    if (n > max) errs.push('目前的嚴重度(S)與發生率(O)等級最高只會算出 ' + max + ' 分，沒有辦法分成 ' + n + ' 個風險等級');
    rows.each(function(i){
        var tr = $(this), lo = i === 0 ? 1 : prev + 1, last = i === n - 1, name = $.trim(tr.find('[data-l=name]').val()) || ('第 ' + (i + 1) + ' 級'), hi;
        tr.find('.lv-lo').val(lo);
        var hiEl = tr.find('.lv-hi'); hiEl.removeClass('bad');
        if (last){ hi = max; hiEl.val(max).prop('readonly', true).css('background', '#F5EEE2').attr('title', '最後一級上限固定＝目前最高可能分數 ' + max); if (lo > max) errs.push('「' + name + '」的下限（' + lo + '）已超過最高分數 ' + max); }
        else {
            hiEl.prop('readonly', false).css('background', '').removeAttr('title');
            var raw = hiEl.val(), cap = max - (n - 1 - i);
            hi = Math.round(+raw);
            var bad = raw === '' || isNaN(+raw) || +raw !== hi || hi < lo || hi > cap;
            if (bad){
                if (fix){ hi = raw === '' || isNaN(+raw) ? lo : Math.min(Math.max(hi, lo), cap); hiEl.val(hi); }
                else { hiEl.addClass('bad'); errs.push('「' + name + '」的評分上限要是 ' + lo + '～' + cap + ' 之間的整數（下限 ' + lo + '；後面每一級至少要留 1 分，目前最高分 ' + max + '）'); }
            }
        }
        prev = hi;
    });
    return errs;
}
function showSetErrs(fix){
    var e1 = relinkLvl(fix), e2 = relinkLik(fix);
    $('#eLvl').text(e1.join('；')); $('#eLik').text(e2.join('；'));
    return e1.concat(e2);
}
/* 風險分布預覽：一律用「草稿」即時算，儲存前就看得到結果 */
function previewDist(){
    var imp = $.map(readImp(), function(r){ return {v:r.v, label:r.label || ('第 ' + r.v + ' 級')}; }),
        lik = $.map(readLik(), function(r){ return {v:r.v, label:r.label || ('第 ' + r.v + ' 級')}; }),
        S = {rm_impact:{rows:imp, columns:[]}, rm_likelihood:lik, rm_method:$('#setMethod').val()};
    var lv = [];
    $('#setLvl tbody tr').each(function(i){
        var c = LVCOL[Math.min(i, LVCOL.length - 1)];
        lv.push({name:$.trim($(this).find('[data-l=name]').val()) || ('第 ' + (i + 1) + ' 級'), min:+$(this).find('.lv-lo').val() || 0,
                 hi:+$(this).find('.lv-hi').val() || 0, action:false, bg:c[0], fg:c[1]});
    });
    var h = '<table class="mx"><thead><tr><th>嚴重度(S)＼發生率(O)</th>';
    $.each(lik, function(_, f){ h += '<th>' + esc(f.label) + '（' + f.v + '）</th>'; });
    h += '</tr></thead><tbody>';
    $.each(imp.slice().reverse(), function(_, s){
        h += '<tr><th>' + esc(s.label) + '（' + s.v + '）</th>';
        $.each(lik, function(_, f){ h += '<td>' + lvBadge(calcLevel(s.v, f.v, S, lv), true) + '</td>'; });
        h += '</tr>';
    });
    $('#setDist').html(h + '</tbody></table>');
}
function renderSet(){
    var S = META.settings;
    var t = '<table class="rm-table fld"><thead><tr><th style="width:110px;">風險等級</th><th style="width:76px;">評分下限</th><th style="width:76px;">評分上限</th><th>處理措施</th><th style="width:90px;">需要因應措施</th><th style="width:110px;">機會等級名稱</th><th>機會的處理措施</th><th style="width:44px;"></th></tr></thead><tbody>';
    $.each(S.rm_levels, function(_, r){ t += lvlRowHtml({name:r.name, lo:r.lo, hi:r.hi, measure:r.measure, action:r.action, saved:true,
        name_opp:r.name_opp, measure_opp:r.measure_opp}); });
    $('#setOppSev').val((S.rm_opp_terms || {}).sev || ''); $('#setOppFrq').val((S.rm_opp_terms || {}).freq || ''); $('#setOppLv').val((S.rm_opp_terms || {}).level || '');
    $('#setLvl').html(t + '</tbody></table>');
    $('#setImpCols').val(S.rm_impact.columns.join('\n'));
    renderImpTable(S.rm_impact.columns, $.map(S.rm_impact.rows, function(r){ return {orig:r.v, label:r.label, cells:r.cells}; }));
    var tk = '<table class="rm-table fld"><thead><tr><th style="width:64px;">等級</th><th style="width:130px;">發生率(O)分類</th><th style="width:100px;">機率下限</th><th style="width:100px;">機率上限</th><th>詳細的描述</th><th style="width:44px;"></th></tr></thead><tbody>';
    $.each(S.rm_likelihood, function(_, r){ tk += likRowHtml({orig:r.v, label:r.label, lo:r.prob_lo, hi:r.prob_hi, desc:r.desc}); });
    $('#setLik').html(tk + '</tbody></table>');
    $('#setMethod').val(S.rm_method); $.each(S.rm_dimensions, function(i, n){ $('#setSwot' + i).val(n); }); $('#setPrefix').val(S.rm_confirm_prefix);
    var oh = ''; $.each(META.audit_procs || [], function(_, p){ oh += '<label style="margin-right:16px;font-weight:normal;"><input type="checkbox" class="setOut" value="' + p.tag_id + '"' + (($.inArray(p.tag_id, S.rm_outsource_tags || []) >= 0) ? ' checked' : '') + '> ' + esc(p.name) + '</label>'; });
    $('#setOutsrc').html(oh || '<span class="hint">訂單追蹤尚未設定稽核製程</span>');
    var b = $('#setAsdocBody').empty();
    $.each(META.asdoc, function(k, v){
        var cur = v.doc ? (v.doc.doc_no + '（' + v.doc.doc_name + '）') : '尚未綁定　建議：' + v.fallback;
        b.append('<tr><td class="l">' + esc(v.label) + '</td><td class="l">' + esc(cur) + '</td>'
            + '<td><button class="b-mini" data-pickasdoc="' + k + '">選擇</button></td></tr>');
    });
    $('#eSet').text(''); renumberLevels(); showSetErrs(false); previewDist();
}
var MINROWS = {lvl:1, imp:2, lik:2};
$(document).on('click', '[data-rmrow]', function(){
    var kind = $(this).data('rmrow'), tb = $(this).closest('tbody');
    if (tb.find('tr').length <= MINROWS[kind]){ alert(kind === 'lvl' ? '風險準則至少要保留 1 級' : '至少要保留 2 級'); return; }
    $(this).closest('tr').remove();
    renumberLevels(); showSetErrs(true); previewDist();        // 刪掉之後等級數字自動重排、區間自動重新串接
});
$('#btnAddLvl').on('click', function(){
    var rows = $('#setLvl tbody tr'), n = rows.length, max = draftMaxScore();
    if (n + 1 > max){ alert('目前最高只會算出 ' + max + ' 分，已經沒有空間再分出新的一級。'); return; }
    var last = rows.last(), lo = +last.find('.lv-lo').val() || 1;
    if (lo > max - 1){ alert('最高那一級已經只剩 ' + max + ' 分，沒有空間再分出新的一級（請先把前面等級的評分上限調小）。'); return; }
    last.find('.lv-hi').val(Math.max(lo, max - 1));            // 原本最後一級的上限讓出最高分，新的一級取最高分那一格
    $('#setLvl tbody').append(lvlRowHtml({name:'', lo:max, hi:max, measure:'', action:false, saved:false, name_opp:'', measure_opp:''}));
    showSetErrs(true); previewDist();
});
$('#btnAddImp').on('click', function(){
    if ($('#setImp tbody tr').length >= 10){ alert('嚴重度(S)分析最多 10 級'); return; }
    $('#setImp tbody').append(impRowHtml(IMPCOLS, {orig:0, label:'', cells:[]}));
    renumberLevels(); showSetErrs(true); previewDist();
});
$('#btnAddLik').on('click', function(){
    var rows = $('#setLik tbody tr'), n = rows.length;
    if (n >= 10){ alert('發生率(O)分析最多 10 級'); return; }
    var last = rows.last(), lo = +last.find('.lk-lo').val() || 0;
    if (lo > 99){ alert('最後一級已經只剩 100%，沒有空間再分出新的一級（請先把前面等級的機率上限調小）。'); return; }
    last.find('.lk-hi').val(Math.max(lo, 99));                  // 原本最後一級讓出最高的 100%，新的一級取 100%
    $('#setLik tbody').append(likRowHtml({orig:0, label:'', lo:100, hi:100, desc:''}));
    renumberLevels(); showSetErrs(true); previewDist();
});
/* 說明欄名稱一改，嚴重度(S)分析的表頭與各列欄位即時跟著變（舊欄名的內容保留） */
$('#setImpCols').on('input', debounce(function(){
    var cols = impColsDraft(), rows = readImp();
    renderImpTable(cols, rows); previewDist();
}, 300));
/* 打字中只顯示錯誤（不亂改使用者正在輸入的數字）；離開欄位時才自動修正成合理的值 */
$('#setMask').on('input', 'input, select', function(){ if (this.id !== 'setImpCols'){ showSetErrs(false); previewDist(); } });
$('#setMask').on('change', 'input, select', function(){ if (this.id !== 'setImpCols'){ showSetErrs(true); previewDist(); } });
$(document).on('click', '[data-pickasdoc]', function(){
    var which = $(this).data('pickasdoc');
    EGAsDoc.open({
        docs: META.asdoc_list, current: META.asdoc[which].doc_id,
        title: 'AS 文件編號綁定　' + META.asdoc[which].label,
        onSave: function(id){
            post({action:'asdoc_save', which:which, doc_id:id || 0}, function(res){
                META.asdoc = res.asdoc; renderSet(); renderAsdocHint(); renderTabNames();
            });
        }
    });
});
$('#btnSetting').on('click', function(){ renderSet(); openMask('setMask'); });
$('#btnSetSave').on('click', function(){
    var errs = showSetErrs(false);
    if (errs.length){ $('#eSet').text('請先修正上面標紅的欄位：' + errs[0]); return; }
    var impRows = $.map(readImp(), function(r){
        return {orig:r.orig, label:r.label, cells:$.map(IMPCOLS, function(c){ return r.byName[c] || ''; })};
    });
    var s = {rm_impact:{columns:IMPCOLS, rows:impRows}, rm_likelihood:readLik(), rm_method:$('#setMethod').val(), rm_levels:readLvl(),
             rm_dimensions:[0, 1, 2, 3].map(function(i){ return $('#setSwot' + i).val(); }), rm_confirm_prefix:$('#setPrefix').val(),
             rm_opp_terms:{sev:$('#setOppSev').val(), freq:$('#setOppFrq').val(), level:$('#setOppLv').val()},
             rm_outsource_tags:$('.setOut:checked').map(function(){ return +this.value; }).get()};
    var send = function(force){
        $('#eSet').text('');
        NProgress.start();
        // 這支要自己處理 409（需要確認），所以關掉全域錯誤彈窗
        $.ajax({url:API, method:'POST', dataType:'json', global:false, data:{action:'setting_save', csrf:CSRF, settings:JSON.stringify(s), force:force ? 1 : 0}})
         .done(function(res){
            NProgress.done();
            META.settings = res.settings; META.levels = res.levels; META.swot = res.swot;
            alert('設定已儲存'); closeMask('setMask');
            LISTKINDS.forEach(function(k){ loadList(k); }); loadBoard(BOARD.year);
         })
         .fail(function(xhr){
            NProgress.done();
            var j = {}; try { j = JSON.parse(xhr.responseText); } catch(e){}
            if (j.code === 'NEED_FORCE'){ if (confirm(j.error + '\n\n仍要儲存嗎？')) send(true); return; }
            $('#eSet').text(j.error || ('儲存失敗（HTTP ' + xhr.status + '）'));
         });
    };
    send(false);
});

/* ================= 列印（ai-rules/16；比照紙本版面） ================= */
function egPrintWindow(title, bodyHtml, docNo, extraCss){
    var asHtml = esc(String(docNo || ''));
    var css = '@page{size:A4 landscape;margin:0;}html,body{margin:0;padding:0;}'
        + 'body{font-family:"Microsoft JhengHei","微軟正黑體",sans-serif;color:#000;padding:10mm 8mm 12mm;-webkit-print-color-adjust:exact;print-color-adjust:exact;}'
        + '.pt-head{text-align:center;margin-bottom:6px;}.pt-head .co{font-size:22px;font-weight:bold;letter-spacing:2px;}'
        + '.pt-head .tt{font-size:18px;font-weight:bold;margin-top:3px;letter-spacing:1px;text-decoration:underline;}'
        + '.pt-meta{display:flex;justify-content:space-between;font-size:12.5px;margin:8px 0 4px;}'
        + 'table.pt{width:100%;border-collapse:collapse;font-size:12px;table-layout:fixed;}'
        + 'table.pt thead{display:table-header-group;}table.pt tr{page-break-inside:avoid;}'
        + 'table.pt th,table.pt td{border:1px solid #333;padding:4px 5px;text-align:center;word-wrap:break-word;overflow-wrap:break-word;line-height:1.4;vertical-align:middle;}'
        + 'table.pt th{background:#EFEFEF;font-weight:bold;}table.pt td.l{text-align:left;vertical-align:top;white-space:pre-wrap;}'
        + '.pt-foot{position:fixed;right:8mm;bottom:5mm;font-size:9pt;color:#333;}' + (extraCss || '');
    var w = window.open('', '_blank');
    if (!w){ alert('請允許彈出視窗'); return; }
    // 超過一頁才加左下角頁碼；量高度要在「列印寬度」下量（視窗寬度不同高度就不同）
    var onloadJs = 'var onePageA4=(210-28)*96/25.4;if(document.body.scrollHeight>onePageA4*0.92){var st=document.createElement(\'style\');'
        + 'st.textContent="@page{ @bottom-left{ content:\'第 \' counter(page) \' 頁／共 \' counter(pages) \' 頁\'; font-size:9pt; color:#333; } }";document.head.appendChild(st);}';
    w.document.write('<!DOCTYPE html><html><head><meta charset="utf-8"><title>' + esc(title) + '</title><style>' + css + '</style></head><body>'
        + bodyHtml + (asHtml ? '<div class="pt-foot">' + asHtml + '</div>' : '')
        + '<scr' + 'ipt>window.onload=function(){' + onloadJs + 'setTimeout(function(){window.print();},250);};</scr' + 'ipt></body></html>');
    w.document.close();
}
/* 列印用的成效確認：主表只留「結果、日期、確認結果一行、裁示、佐證來源」。
   逐月 KPI 數據與每一項措施追蹤的明細留在系統（佐證欄全文），不塞進正式表單，否則一格會變成半頁。 */
function evidenceBrief(txt){
    var lines = String(txt || '').split('\n').map(function(x){ return x.trim(); }).filter(function(x){ return x; });
    if (!lines.length) return {first:'', refs:''};
    var first = lines[0].replace(/^（參考）/, ''), refs = [];
    $.each(lines, function(_, l){
        var m1 = l.match(/措施追蹤\s*#\d+/g), m2 = l.match(/溝通記錄\s*\d{8,11}/g);
        if (m1) refs = refs.concat(m1);
        if (m2) refs = refs.concat(m2);
    });
    refs = refs.filter(function(v, i){ return refs.indexOf(v) === i; });
    return {first:first, refs:refs.join('、')};
}
function effectPrint(it){
    var h = esc(it.effect_check || '');
    if (it.effect_status && it.effect_status !== 'unconfirmed'){
        var e = evidenceBrief(it.effect_evidence);
        h += '<br><b>【成效確認：' + esc(META.effect_status[it.effect_status] || '') + '】' + esc(dispDate(it.effect_date)) + '</b>';
        if (e.first) h += '<br>確認結果：' + esc(e.first);
        h += '<br>裁示：' + esc(it.effect_ruling || '');
        h += '<br>佐證：' + esc(e.refs ? e.refs + '（詳細紀錄見系統）' : '見系統成效確認紀錄');
        if (it.car_no) h += '<br>矯正單：' + esc(it.car_no);
    }
    return h;
}
function measurePrint(it){
    var h = esc(it.measure || '').replace(/\n/g, '<br>'), ts = it.tracks || [];
    if (ts.length){
        var o = $.map(ts, function(t){ return '#' + t.id + ' ' + (t.owner || '未指定') + '／' + (t.due ? dispDate(t.due) : '未定')
            + (t.state === 'closed' ? '（已結案）' : (t.state === 'over' ? '（逾期）' : '（執行中）')); });
        h += '<br><span style="color:#444;">措施追蹤：' + esc(o.join('；')) + '</span>';
    }
    if (it.recheck_note) h += '<br><span style="color:#444;">【重新評估 ' + esc(dispDate(it.recheck_date)) + '】' + esc(it.recheck_note).replace(/\n/g, '<br>') + '</span>';
    return h;
}
function scaleLabel(list, v){
    if (v === '' || v == null) return '';
    var t = String(v); $.each(list, function(_, r){ if (+r.v === +v) t = r.v + ' ' + r.label; }); return t;
}
function printDoc(docId){
    get({action:'doc_get', doc_id:docId}, function(res){
        var d = res.doc, kind = d.kind, meta = res.print, S = META.settings, tr = '';
        $.each(res.items, function(i, it){
            if (kind === 'context'){
                tr += '<tr><td>' + (i + 1) + '</td><td>' + esc(META.scopes[it.scope] || '') + '</td><td>' + esc(it.dimension) + '</td>'
                    + '<td class="l">' + esc(it.stakeholder) + '</td><td class="l">' + esc(it.needs) + '</td><td class="l">' + esc(it.issue) + '</td></tr>';
            } else {
                var lv = it.level ? it.level.name : '';     // 機會列印出來的是機會等級名稱（後端依 ro_kind 給）
                tr += '<tr><td>' + (i + 1) + '</td><td>' + esc(META.scopes[it.scope] || '') + '</td><td class="l">' + esc(it.stakeholder) + '</td>'
                    + '<td class="l">' + esc(it.needs) + '</td><td class="l">' + esc(it.issue) + '</td>'
                    + '<td class="l">' + esc(it.ro_text) + '</td><td>' + esc(META.ro_kinds[it.ro_kind] || '') + '</td>'
                    + '<td>' + esc(scaleLabel(impactList(S), it.severity)) + '</td><td>' + esc(scaleLabel(likeList(S), it.frequency)) + '</td>'
                    + '<td>' + esc(lv) + '</td><td>' + esc(it.confirm_no) + '</td>'
                    + '<td class="l">' + measurePrint(it) + '</td><td class="l">' + effectPrint(it) + '</td></tr>';
            }
        });
        var head = '<div class="pt-head"><div class="co">' + esc(meta.company) + '</div><div class="tt">' + esc(meta.doc_name || kindName(kind)) + '</div></div>'
            + '<div class="pt-meta"><span>相關部門：' + esc(d.dept_names) + '</span><span>填表日期：' + esc(dispDate(d.fill_date)) + '</span></div>';
        var table;
        if (kind === 'context'){
            table = '<table class="pt"><colgroup><col style="width:40px;"><col style="width:70px;"><col style="width:100px;"><col style="width:140px;"><col><col></colgroup>'
                + '<thead><tr><th>編號</th><th>內/外部議題</th><th>構面</th><th>利害關係者</th><th>需求與期望</th><th>議題描述</th></tr></thead><tbody>' + tr + '</tbody></table>';
        } else {
            table = '<table class="pt"><colgroup><col style="width:34px;"><col style="width:52px;"><col style="width:84px;"><col style="width:100px;"><col style="width:120px;">'
                + '<col style="width:120px;"><col style="width:56px;"><col style="width:56px;"><col style="width:56px;"><col style="width:56px;"><col style="width:70px;"><col><col></colgroup>'
                + '<thead><tr><th rowspan="2">編號</th><th rowspan="2">內/外部議題</th><th rowspan="2">利害關係者</th><th rowspan="2">需求與期望</th><th rowspan="2">議題描述</th>'
                + '<th rowspan="2">對組織的影響說明</th><th rowspan="2">風險與機會</th><th colspan="3">風險評估</th><th rowspan="2">風險確認編號</th><th rowspan="2">因應措施</th><th rowspan="2">成效確認</th></tr>'
                + '<tr><th>嚴重度(S)</th><th>發生率(O)</th><th>風險等級</th></tr></thead><tbody>' + tr + '</tbody></table>';
        }
        // 機會列的三個評分欄位在表頭叫「嚴重度(S)／發生率(O)／風險等級」，實際問的是效益與把握度，所以在表尾註明一行
        var foot = kind === 'risk' ? '<div style="margin-top:4px;font-size:11px;color:#333;">註：風險與機會共用同一組評分；'
            + '「風險與機會」欄為機會者，嚴重度(S)＝' + esc(oppTerm('sev')) + '、發生率(O)＝' + esc(oppTerm('freq')) + '、風險等級＝' + esc(oppTerm('level')) + '。</div>' : '';
        egPrintWindow(meta.doc_name || kindName(kind), head + table + foot, meta.doc_no);
        EGPrintLog.record({source:'risk_mgmt', doc_name:(meta.doc_name || kindName(kind)) + ' ' + d.doc_no,
            doc_kind:'form', ref_table:'rm_doc', ref_id:d.doc_id});
    });
}

/* ---------- 空白表單列印（兩張表都可以印出空白紙本，現場手寫用） ---------- */
function printBlank(kind){
    get({action:'print_meta', kind:kind}, function(res){
        var meta = res.print, body, css = '', blankTd = function(n, h){ var s = ''; for (var i = 0; i < n; i++) s += '<td style="height:' + h + 'px;"></td>'; return '<tr>' + s + '</tr>'; };
        var head = '<div class="pt-head"><div class="co">' + esc(meta.company) + '</div><div class="tt">' + esc(meta.doc_name || kindName(kind)) + '</div></div>';
        if (kind === 'context'){
            head += '<div class="pt-meta"><span>年度：＿＿＿＿＿</span><span>填表日期：＿＿＿＿＿＿＿＿＿＿</span></div>';
            var quad = function(key){
                var m = swotMeta(key), rows = '';
                for (var i = 0; i < 5; i++) rows += blankTd(4, 30);
                return '<td class="qd"><div class="qh">' + esc(m.name) + '　' + m.en + '（' + (m.scope === 'internal' ? '內部' : '外部') + '）</div>'
                    + '<table class="pt in"><colgroup><col style="width:28px;"><col style="width:90px;"><col style="width:30%;"><col></colgroup>'
                    + '<thead><tr><th>編號</th><th>利害關係者</th><th>需求與期望</th><th>議題描述</th></tr></thead><tbody>' + rows + '</tbody></table></td>';
            };
            body = head + '<table class="grid"><tr>' + quad('S') + quad('W') + '</tr><tr>' + quad('O') + quad('T') + '</tr></table>';
            css = 'table.grid{width:100%;border-collapse:separate;border-spacing:6px;table-layout:fixed;}table.grid>tbody>tr>td.qd{vertical-align:top;border:2px solid #333;padding:0;}'
                + '.qh{background:#EFEFEF;font-weight:bold;font-size:13px;padding:4px 8px;border-bottom:1px solid #333;}table.pt.in{margin:0;font-size:11px;}table.pt.in th,table.pt.in td{padding:3px 4px;}'
                + 'table.grid>tbody>tr{page-break-inside:avoid;}';
        } else {
            head += '<div class="pt-meta"><span>相關部門：＿＿＿＿＿＿＿＿＿＿＿＿＿＿</span><span>填表日期：＿＿＿＿＿＿＿＿＿＿</span></div>';
            var rows = ''; for (var i = 1; i <= 10; i++) rows += '<tr><td>' + i + '</td>' + blankTd(12, 46).replace('<tr>', '').replace('</tr>', '') + '</tr>';
            body = head + '<table class="pt"><colgroup><col style="width:34px;"><col style="width:52px;"><col style="width:84px;"><col style="width:100px;"><col style="width:120px;">'
                + '<col style="width:120px;"><col style="width:56px;"><col style="width:56px;"><col style="width:56px;"><col style="width:56px;"><col style="width:70px;"><col><col></colgroup>'
                + '<thead><tr><th rowspan="2">編號</th><th rowspan="2">內/外部議題</th><th rowspan="2">利害關係者</th><th rowspan="2">需求與期望</th><th rowspan="2">議題描述</th>'
                + '<th rowspan="2">對組織的影響說明</th><th rowspan="2">風險與機會</th><th colspan="3">風險評估</th><th rowspan="2">風險確認編號</th><th rowspan="2">因應措施</th><th rowspan="2">成效確認</th></tr>'
                + '<tr><th>嚴重度(S)</th><th>發生率(O)</th><th>風險等級</th></tr></thead><tbody>' + rows + '</tbody></table>';
        }
        egPrintWindow(meta.doc_name || kindName(kind), body, meta.doc_no, css);
        EGPrintLog.record({source:'risk_mgmt', doc_name:(meta.doc_name || kindName(kind)) + '（空白表單）', doc_kind:'form', ref_table:'rm_doc', ref_id:0});
    });
}
$(document).on('click', '[data-blank]', function(){ printBlank($(this).data('blank')); });
$('#btnDocPrint').on('click', function(){ if (CUR && CUR.doc_id) printDoc(CUR.doc_id); });
$(document).on('click', '[data-print]', function(){ printDoc(+$(this).data('print')); });

/* ================= CSV ================= */
$(document).on('click', '[data-csv]', function(){
    var k = $(this).data('csv');
    get({action:'doc_list', kind:k, per:0, page:1, year:$('[data-year="' + k + '"]').val() || 0, kw:$('[data-kw="' + k + '"]').val()}, function(res){
        var rows = [['單號','相關部門','填表日期','筆數'].concat(k === 'risk' ? ['最高風險等級','需因應措施筆數'] : []).concat(['填表人'])];
        $.each(res.rows, function(_, r){
            rows.push([r.doc_no, r.dept_names || '', dispDate(r.fill_date), r.item_cnt]
                .concat(k === 'risk' ? [r.top_level ? r.top_level.name : '', r.action_cnt] : []).concat([r.created_by_name || '']));
        });
        var csv = '﻿' + rows.map(function(r){ return r.map(function(c){ return '"' + String(c).replace(/"/g, '""') + '"'; }).join(','); }).join('\r\n');
        var a = document.createElement('a');
        a.href = URL.createObjectURL(new Blob([csv], {type:'text/csv;charset=utf-8'}));
        a.download = kindName(k) + '.csv'; document.body.appendChild(a); a.click(); document.body.removeChild(a);
    });
});

$('#btnPageHelp').on('click', function(){ renderRoleHelp(); openMask('helpUseMask'); });

/* ---------- 右下角回頂端：版型的捲動容器可能是 window 也可能是 .right_col，兩邊都聽 ---------- */
function topScroller(){ var rc = document.querySelector('.right_col'); return (rc && rc.scrollHeight > rc.clientHeight + 4 && getComputedStyle(rc).overflowY !== 'visible') ? rc : null; }
function topY(){ var rc = topScroller(); return Math.max(window.pageYOffset || 0, document.documentElement.scrollTop || 0, document.body.scrollTop || 0, rc ? rc.scrollTop : 0); }
function syncTopBtn(){ $('#btnTop').toggle(topY() > 200); }
window.addEventListener('scroll', syncTopBtn, true);
$('#btnTop').on('click', function(){
    var rc = topScroller(); if (rc) rc.scrollTop = 0;
    window.scrollTo({top:0, behavior:'smooth'}); document.documentElement.scrollTop = 0; document.body.scrollTop = 0;
});

/* ================= 啟動 ================= */
get({action:'meta'}, function(m){
    META = m; CSRF = m.csrf;
    $('#roleLabel').text(roleLabel());
    $('#btnSetting').toggle(!!m.perms.canAdmin);
    $('#pane_context').html(boardHtml()); $('#pane_risk').html(paneHtml('risk'));
    fillYears(); BOARD.year = +m.today.slice(0, 4); renderTabNames(); renderAsdocHint(); renderRoleHelp();
    var q = {};
    location.search.replace(/^\?/, '').split('&').forEach(function(kv){ if (!kv) return; var p = kv.split('='); q[decodeURIComponent(p[0])] = decodeURIComponent(p[1] || ''); });
    var startTab = q.tab === 'risk' ? 'risk' : 'context';
    $('#mainTabs .rm-tab[data-tab="' + startTab + '"]').trigger('click');
    if (+q.doc && startTab === 'risk') openDoc(startTab, +q.doc);
});
</script>
<?php endif; ?>
</body>
</html>
