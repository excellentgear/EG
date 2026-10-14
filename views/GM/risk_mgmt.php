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
<div class="rm-mask" id="docMask"><div class="rm-modal" style="width:1480px;">
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
        <div class="it-wrap"><table class="it-tbl" id="itTbl"></table></div>
        <div style="margin-top:6px;" id="addRowBox"><button type="button" class="b-mini warm" id="btnAddRow"><i class="fa fa-plus"></i> 新增列</button>
            <span class="hint">在最後一列按 ↓ 也會自動加一列；沒填內容的末列按 ↑ 會自動移除。</span></div>
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
    <div class="rm-mbd"><div class="note-box" style="margin-bottom:8px;">下面的建議全部來自本系統既有資料（去年的表、溝通管理未結案的反應、近一年品質異常單的主要原因），<b>不是憑空產生的</b>，每一條都標明來源；是不是真的要列入、放在哪個象限，由您判斷。</div>
        <div id="sugBody"></div></div>
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
            <div class="hint">風險等級＝分數大於等於「起算分數」的最高一級（低於最低一級的起算分數時歸最低級）；「評分」範圍由系統依下面的影響與可能性自動算出。「處理措施」會顯示在評級對照表上。勾選「需要因應措施」的等級，儲存時若該列沒有風險確認編號，會自動配發。</div></div>
        <div class="set-sec"><h5>② 影響分析（表B）</h5>
            <div class="fld"><label>說明欄名稱（一行一個，可自行增減；預設：公司形象／財物損失／客戶抱怨件數）</label>
                <textarea id="setImpCols" style="min-height:66px;"></textarea></div>
            <div id="setImp"></div>
            <button type="button" class="b-mini" id="btnAddImp"><i class="fa fa-plus"></i> 新增一級</button></div>
        <div class="set-sec"><h5>③ 可能性評估（表C）</h5><div id="setLik"></div>
            <button type="button" class="b-mini" id="btnAddLik"><i class="fa fa-plus"></i> 新增一級</button></div>
        <div class="set-sec"><h5>④ 風險分布（表D）</h5>
            <div class="fld" style="margin-bottom:6px;"><label>分數算法（程序書 6.3.3 採相乘制）</label>
                <select id="setMethod" style="max-width:320px;"><option value="multiply">影響等級 × 可能性等級</option><option value="add">影響等級 ＋ 可能性等級</option><option value="max">取影響與可能性較大者</option></select></div>
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
        <div class="set-sec"><h5>風險確認編號前綴</h5>
            <div class="fld"><input type="text" id="setPrefix" maxlength="6" style="max-width:140px;"></div>
            <div class="hint">編號格式：前綴＋填表年度＋流水號，例如 R2026-001。已配發的編號不會因設定變動而改變。</div></div>
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
            <li><b>風險與機會因應措施管制表</b>：把議題整理成風險／機會，評估嚴重度與頻率，自動算出風險等級，並記錄因應措施與成效確認（原「策略行動措施管制表」與「風險評估及處理表」合併為這一張）。</li>
            <li>利害關係者的溝通記錄、措施追蹤與溝通管制在「溝通管理」頁，按上方「前往溝通管理」即可轉跳。</li></ul>
        <h4>操作步驟</h4>
        <ul><li>按「新增」→ 填填表日期、相關部門 → 逐列填寫（最後一列按 ↓ 自動加列）→ 儲存。</li>
            <li>風險表每一列的「議題描述」可按「選擇處境議題」連動組織處境分析表：內/外部議題、利害關係者、需求與期望、議題描述都跟著來源走（來源改了這裡跟著變），不必重打；要自行修改先按「解除連動」。「風險與機會」欄選這一列是風險還是機會，「對組織的影響說明」寫它對組織造成的影響。</li>
            <li>嚴重度與頻率要一起選，<b>風險等級由系統依目前設定自動算出</b>，不可手填。</li>
            <li>等級被設定為「需要因應措施」的列，儲存時會自動配發風險確認編號。</li>
            <li>「風險準則／評級對照」可隨時查看 2-GM-03 的表A風險準則、表B影響分析、表C可能性分析、表D風險分布（格內附目前實際落在該格的風險筆數）。</li></ul>
        <h4>重要行為</h4>
        <ul><li>風險準則（表A）、影響分析（表B）、可能性評估（表C）、風險分布算法（表D）、構面、確認編號前綴<b>全部可在「模組設定」修改</b>（預設值取自 2-GM-03）；改完後既有資料的風險等級依新設定即時重算。</li>
            <li>表單名稱與文件編號取自綁定的 AS 文件，列印版頁尾右下角的編號版次依填表日期回推。</li>
            <li>一般員工只看得到、改得動自己建立的表單；檢閱角色可唯讀查看全部；管理員可改全部、刪除與設定。</li></ul>
        <h4>角色</h4><div id="helpRoleBody"></div>
    </div>
</div></div>

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
/* 影響／可能性的選單用清單（title 顯示完整說明） */
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
function calcLevel(sev, frq, S, LV){
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
    return {score:score, name:hit.name, action:!!hit.action, bg:hit.bg, fg:hit.fg, measure:hit.measure || ''};
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
$(document).on('click', '#bSuggest', function(){
    get({action:'suggest', year:BOARD.year}, function(res){
        SUG = res.rows;
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
});
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
        + (isRisk ? '<button data-rating="1"><i class="fa fa-table"></i> 風險準則／評級對照</button>' : '')
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
            var ops = '<button class="b-mini" data-open="' + r.doc_id + '" data-kind="' + k + '"><i class="fa fa-' + (r.can_edit ? 'pencil' : 'eye') + '"></i> '
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
        ['ro_text','對組織的影響說明',210],['ro_kind','風險與機會',92],['severity','嚴重度（影響）',112],['frequency','頻率（可能性）',112],['level','風險等級',92],['confirm_no','風險確認編號',104],
        ['measure','因應措施',200],['effect','成效確認',170],['op','',44]];
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
    var h = '<tr data-item-id="' + (it.item_id || 0) + '" data-ctx="' + (it.ctx_item_id || '') + '">' + td(n, 'seq');
    if (kind === 'context'){
        var dopts = '<option value="">—</option>', hasDim = false;
        $.each(META.settings.rm_dimensions, function(_, d){ if (it.dimension === d) hasDim = true; dopts += '<option' + (it.dimension === d ? ' selected' : '') + '>' + esc(d) + '</option>'; });
        if (it.dimension && !hasDim) dopts += '<option selected>' + esc(it.dimension) + '</option>';
        h += td(scopeSel) + td('<select data-f="dimension">' + dopts + '</select>')
          + td(ta('stakeholder', it.stakeholder)) + td(ta('needs', it.needs)) + td(ta('issue', it.issue));
    } else {
        var roSel = '<select data-f="ro_kind"><option value="">—</option>'
            + $.map(META.ro_kinds, function(t, k){ return '<option value="' + k + '"' + (it.ro_kind === k ? ' selected' : '') + '>' + t + '</option>'; }).join('') + '</select>';
        var lv = calcLevel(it.severity, it.frequency);
        // 議題描述欄：連動組織處境分析表的議題。連動中時，內外部／利害關係者／需求與期望／議題描述都跟著來源（唯讀）
        var issueCell = ta('issue', it.issue)
            + '<div class="ctx-flag" style="margin-top:3px;">' + ctxFlagHtml(it) + '</div>'
            + '<button type="button" class="b-mini warm" data-pickctx style="margin-top:3px;"><i class="fa fa-link"></i> 選擇處境議題</button>';
        h += td(scopeSel) + td(ta('stakeholder', it.stakeholder)) + td(ta('needs', it.needs)) + td(issueCell)
          + td(ta('ro_text', it.ro_text)) + td(roSel)
          + td('<select data-f="severity">' + scaleOptions(impactList(), it.severity) + '</select>')
          + td('<select data-f="frequency">' + scaleOptions(likeList(), it.frequency) + '</select>')
          + td(lvBadge(lv, true), 'lvc')
          + td('<input type="text" data-f="confirm_no" value="' + esc(it.confirm_no) + '" maxlength="30" placeholder="自動配發" data-eg-skip>')
          + td(ta('measure', it.measure)) + td(ta('effect_check', it.effect_check));
    }
    return h + td('<button type="button" class="b-mini danger" data-delrow title="刪除此列"><i class="fa fa-times"></i></button>', 'op') + '</tr>';
}
function buildTable(kind, items){
    var cols = colsOf(kind), t = $('#itTbl').empty();
    var w = 0; cols.forEach(function(c){ w += c[2]; });
    t.css('width', w + 'px');
    var h = '<colgroup>' + cols.map(function(c){ return '<col style="width:' + c[2] + 'px;">'; }).join('') + '</colgroup><thead>';
    if (kind === 'risk'){
        h += '<tr><th rowspan="2">編號</th><th rowspan="2">內/外部議題</th><th rowspan="2">利害關係者</th><th rowspan="2">需求與期望</th><th rowspan="2">議題描述</th>'
          + '<th rowspan="2">對組織的影響說明</th><th rowspan="2">風險與機會</th><th colspan="3">風險評估</th><th rowspan="2">風險確認編號</th><th rowspan="2">因應措施</th><th rowspan="2">成效確認</th><th rowspan="2"></th></tr>'
          + '<tr><th>嚴重度（影響）</th><th>頻率（可能性）</th><th>風險等級</th></tr>';
    } else {
        h += '<tr>' + cols.map(function(c){ return '<th>' + c[1] + '</th>'; }).join('') + '</tr>';
    }
    t.append(h + '</thead><tbody id="itBody" data-eg-row-add="rmAddRow" data-eg-row-del="rmDelLastRow"></tbody>');
    var b = $('#itBody');
    (items.length ? items : [{}]).forEach(function(it, i){ b.append(rowHtml(kind, it, i + 1)); });
}
function renumber(){ $('#itBody tr').each(function(i){ $(this).find('td.seq').text(i + 1); }); }
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
/* 嚴重度／頻率一改就即時更新該列的風險等級預覽 */
$(document).on('change', '#itBody select[data-f=severity], #itBody select[data-f=frequency]', function(){
    var tr = $(this).closest('tr');
    tr.find('td.lvc').html(lvBadge(calcLevel(tr.find('[data-f=severity]').val(), tr.find('[data-f=frequency]').val()), true));
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
        $('#fDate').val(doc ? doc.fill_date : META.today);
        $('#fNo').val(doc ? doc.doc_no : '');
        $('#eDate, #eItems').text(''); $('#fDate').removeClass('bad');
        $('#itemHint').html(kind === 'risk'
            ? '嚴重度與頻率要一起選；<b>風險等級由系統依目前設定自動算出</b>。按「引用處境」可把組織處境分析表的議題帶入該列。'
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
$(document).on('click', '[data-new]', function(){ openDoc($(this).data('new'), 0); });
$(document).on('click', '[data-open]', function(){ openDoc($(this).data('kind'), +$(this).data('open')); });
$(document).on('click', '[data-del]', function(){
    var id = +$(this).data('del');
    if (!confirm('確定要刪除這張表單嗎？刪除後清單與風險表引用都不會再出現。')) return;
    post({action:'doc_delete', doc_id:id}, function(){ LISTKINDS.forEach(function(k){ loadList(k); }); });
});

function collectItems(){
    var out = [];
    $('#itBody tr').each(function(){
        var tr = $(this), o = {item_id:+tr.data('item-id') || 0, ctx_item_id:+tr.attr('data-ctx') || 0};
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
            if (!bad && ((it.severity === '') !== (it.frequency === ''))) bad = '第 ' + (i + 1) + ' 列的嚴重度與頻率要一起填（缺一個算不出風險等級）';
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
    h += H + '表A、風險準則</h4><table class="mx"><thead><tr><th>風險等級</th><th>評分</th><th>處理措施</th><th>需要因應措施</th></tr></thead><tbody>';
    $.each(META.levels.slice().reverse(), function(_, lv){
        h += '<tr><td>' + lvBadge(lv, false) + '</td><td>' + esc(lv.range) + '</td><td style="text-align:left;">' + esc(lv.measure) + '</td><td>' + (lv.action ? '是' : '否') + '</td></tr>';
    });
    h += '</tbody></table>';
    h += H + '表B、影響分析</h4><table class="mx"><thead><tr><th>等級</th><th>影響</th>';
    $.each(S.rm_impact.columns, function(_, c){ h += '<th>' + esc(c) + '</th>'; });
    h += '</tr></thead><tbody>';
    $.each(S.rm_impact.rows.slice().reverse(), function(_, r){
        h += '<tr><td>' + r.v + '</td><td>' + esc(r.label) + '</td>';
        $.each(S.rm_impact.columns, function(i){ h += '<td style="text-align:left;">' + esc(r.cells[i] || '') + '</td>'; });
        h += '</tr>';
    });
    h += '</tbody></table>';
    h += H + '表C、可能性分析</h4><table class="mx"><thead><tr><th>等級</th><th>可能性分類</th><th>發生機率百分比</th><th>詳細的描述</th></tr></thead><tbody>';
    $.each(S.rm_likelihood.slice().reverse(), function(_, r){
        h += '<tr><td>' + r.v + '</td><td>' + esc(r.label) + '</td><td>' + esc(r.prob) + '</td><td style="text-align:left;">' + esc(r.desc) + '</td></tr>';
    });
    h += '</tbody></table>';
    h += H + '表D、風險分布（' + {multiply:'影響等級 × 機率等級', add:'影響等級 ＋ 機率等級', max:'取較大者'}[S.rm_method] + '；格內小字＝目前實際落在此格的風險筆數）</h4>'
       + '<table class="mx"><thead><tr><th>影響＼機率</th>';
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

/* ================= 模組設定 ================= */
function lvlRowHtml(r){
    return '<tr><td><input type="text" data-l="name" value="' + esc(r.name) + '" maxlength="10" style="width:100px;"></td>'
        + '<td><input type="number" data-l="min" value="' + esc(r.min) + '" min="0" style="width:80px;"></td>'
        + '<td class="rng" style="text-align:center;white-space:nowrap;"></td>'
        + '<td><input type="text" data-l="measure" value="' + esc(r.measure || '') + '" maxlength="200"></td>'
        + '<td style="text-align:center;"><input type="checkbox" data-l="action"' + (r.action ? ' checked' : '') + '></td>'
        + '<td><button type="button" class="b-mini danger" data-rmrow><i class="fa fa-times"></i></button></td></tr>';
}
function impRowHtml(cols, r){
    var h = '<tr><td><input type="number" data-i="v" value="' + esc(r.v) + '" min="1" max="99" style="width:64px;"></td>'
        + '<td><input type="text" data-i="label" value="' + esc(r.label) + '" maxlength="20" style="width:110px;"></td>';
    $.each(cols, function(i, c){ h += '<td><input type="text" data-ic="' + i + '" value="' + esc((r.cells || [])[i] || '') + '" maxlength="100" placeholder="' + esc(c) + '"></td>'; });
    return h + '<td><button type="button" class="b-mini danger" data-rmrow><i class="fa fa-times"></i></button></td></tr>';
}
function likRowHtml(r){
    return '<tr><td><input type="number" data-k="v" value="' + esc(r.v) + '" min="1" max="99" style="width:64px;"></td>'
        + '<td><input type="text" data-k="label" value="' + esc(r.label) + '" maxlength="20" style="width:120px;"></td>'
        + '<td><input type="text" data-k="prob" value="' + esc(r.prob || '') + '" maxlength="30" style="width:110px;"></td>'
        + '<td><input type="text" data-k="desc" value="' + esc(r.desc || '') + '" maxlength="200"></td>'
        + '<td><button type="button" class="b-mini danger" data-rmrow><i class="fa fa-times"></i></button></td></tr>';
}
var IMPCOLS = [];     // 影響分析目前的說明欄名稱（改欄名時依舊名稱保留各格內容）
function impColsDraft(){
    var out = [];
    $('#setImpCols').val().split('\n').forEach(function(x){ x = $.trim(x); if (x && out.indexOf(x) < 0) out.push(x); });
    return out.slice(0, 6);
}
function readImp(){
    var rows = [];
    $('#setImp tbody tr').each(function(){
        var cells = {};
        $(this).find('[data-ic]').each(function(){ cells[IMPCOLS[+$(this).data('ic')]] = $(this).val(); });
        rows.push({v:$(this).find('[data-i=v]').val(), label:$(this).find('[data-i=label]').val(), byName:cells});
    });
    return rows;
}
function renderImpTable(cols, rows){
    var t = '<table class="rm-table fld"><thead><tr><th style="width:74px;">等級</th><th style="width:120px;">影響</th>';
    $.each(cols, function(_, c){ t += '<th>' + esc(c) + '</th>'; });
    t += '<th style="width:44px;"></th></tr></thead><tbody>';
    $.each(rows, function(_, r){
        var cells = $.map(cols, function(c, i){ return r.byName ? (r.byName[c] || '') : ((r.cells || [])[i] || ''); });
        t += impRowHtml(cols, {v:r.v, label:r.label, cells:cells});
    });
    $('#setImp').html(t + '</tbody></table>');
    IMPCOLS = cols.slice();
}
function readLvl(){
    var lv = [];
    $('#setLvl tbody tr').each(function(){
        lv.push({name:$(this).find('[data-l=name]').val(), min:$(this).find('[data-l=min]').val(), measure:$(this).find('[data-l=measure]').val(),
                 action:$(this).find('[data-l=action]').is(':checked')});
    });
    return lv;
}
function readLik(){
    var out = [];
    $('#setLik tbody tr').each(function(){
        out.push({v:$(this).find('[data-k=v]').val(), label:$(this).find('[data-k=label]').val(), prob:$(this).find('[data-k=prob]').val(), desc:$(this).find('[data-k=desc]').val()});
    });
    return out;
}
/* 風險分布預覽＋風險準則的評分範圍：一律用「草稿」即時算，儲存前就看得到結果 */
function previewDist(){
    var imp = $.map(readImp(), function(r){ return (+r.v > 0 && $.trim(r.label)) ? {v:+r.v, label:r.label} : null; }),
        lik = $.map(readLik(), function(r){ return (+r.v > 0 && $.trim(r.label)) ? {v:+r.v, label:r.label} : null; }),
        method = $('#setMethod').val();
    imp.sort(function(a, b){ return a.v - b.v; }); lik.sort(function(a, b){ return a.v - b.v; });
    var S = {rm_impact:{rows:imp, columns:[]}, rm_likelihood:lik, rm_method:method};
    var lv = readLvl();
    lv = $.map(lv, function(r){ return $.trim(r.name) && r.min !== '' ? {name:r.name, min:+r.min, action:r.action} : null; });
    lv.sort(function(a, b){ return a.min - b.min; });
    $.each(lv, function(i, r){ var c = LVCOL[Math.min(i, LVCOL.length - 1)]; r.bg = c[0]; r.fg = c[1]; });
    var h = '<table class="mx"><thead><tr><th>影響＼機率</th>';
    $.each(lik, function(_, f){ h += '<th>' + esc(f.label) + '（' + f.v + '）</th>'; });
    h += '</tr></thead><tbody>';
    var scores = [];
    $.each(imp.slice().reverse(), function(_, s){
        h += '<tr><th>' + esc(s.label) + '（' + s.v + '）</th>';
        $.each(lik, function(_, f){
            var c = calcLevel(s.v, f.v, S, lv);
            if (c) scores.push(c.score);
            h += '<td>' + lvBadge(c, true) + '</td>';
        });
        h += '</tr>';
    });
    $('#setDist').html(h + '</tbody></table>');
    // 風險準則表上的「評分」欄：該等級實際會出現的分數範圍
    $('#setLvl tbody tr').each(function(){
        var nm = $(this).find('[data-l=name]').val(), me = null;
        $.each(lv, function(k, r){ if (r.name === nm) me = k; });
        var txt = '';
        if (me !== null){
            var lo = lv[me].min, hi = me + 1 < lv.length ? lv[me + 1].min - 1 : Infinity;
            var inr = $.grep(scores, function(x){ return (me === 0 || x >= lo) && x <= hi; });
            if (inr.length){ var a = Math.min.apply(null, inr), b = Math.max.apply(null, inr); txt = a === b ? '' + a : a + '～' + b; }
        }
        $(this).find('.rng').text(txt);
    });
}
function renderSet(){
    var S = META.settings;
    var t = '<table class="rm-table fld"><thead><tr><th style="width:110px;">風險等級</th><th style="width:90px;">起算分數</th><th style="width:80px;">評分</th><th>處理措施</th><th style="width:90px;">需要因應措施</th><th style="width:44px;"></th></tr></thead><tbody>';
    $.each(S.rm_levels, function(_, r){ t += lvlRowHtml(r); });
    $('#setLvl').html(t + '</tbody></table>');
    $('#setImpCols').val(S.rm_impact.columns.join('\n'));
    renderImpTable(S.rm_impact.columns, $.map(S.rm_impact.rows, function(r){ return {v:r.v, label:r.label, cells:r.cells}; }));
    var tk = '<table class="rm-table fld"><thead><tr><th style="width:74px;">等級</th><th style="width:130px;">可能性分類</th><th style="width:120px;">發生機率百分比</th><th>詳細的描述</th><th style="width:44px;"></th></tr></thead><tbody>';
    $.each(S.rm_likelihood, function(_, r){ tk += likRowHtml(r); });
    $('#setLik').html(tk + '</tbody></table>');
    $('#setMethod').val(S.rm_method); $.each(S.rm_dimensions, function(i, n){ $('#setSwot' + i).val(n); }); $('#setPrefix').val(S.rm_confirm_prefix);
    var b = $('#setAsdocBody').empty();
    $.each(META.asdoc, function(k, v){
        var cur = v.doc ? (v.doc.doc_no + '（' + v.doc.doc_name + '）') : '尚未綁定　建議：' + v.fallback;
        b.append('<tr><td class="l">' + esc(v.label) + '</td><td class="l">' + esc(cur) + '</td>'
            + '<td><button class="b-mini" data-pickasdoc="' + k + '">選擇</button></td></tr>');
    });
    $('#eSet').text('');
    previewDist();
}
$(document).on('click', '[data-rmrow]', function(){
    var tb = $(this).closest('tbody');
    if (tb.find('tr').length <= 1){ alert('至少要保留一列'); return; }
    $(this).closest('tr').remove(); previewDist();
});
$('#btnAddLvl').on('click', function(){ $('#setLvl tbody').append(lvlRowHtml({name:'', min:'', action:false, measure:''})); previewDist(); });
$('#btnAddImp').on('click', function(){
    var mx = 0; $('#setImp [data-i=v]').each(function(){ mx = Math.max(mx, +$(this).val() || 0); });
    $('#setImp tbody').append(impRowHtml(IMPCOLS, {v:mx + 1, label:'', cells:[]})); previewDist();
});
$('#btnAddLik').on('click', function(){
    var mx = 0; $('#setLik [data-k=v]').each(function(){ mx = Math.max(mx, +$(this).val() || 0); });
    $('#setLik tbody').append(likRowHtml({v:mx + 1, label:'', prob:'', desc:''})); previewDist();
});
/* 說明欄名稱一改，影響分析的表頭與各列欄位即時跟著變（舊欄名的內容保留） */
$('#setImpCols').on('input', debounce(function(){
    var cols = impColsDraft(), rows = readImp();
    renderImpTable(cols, rows); previewDist();
}, 300));
$('#setMask').on('input change', 'input, select', function(){ if (this.id !== 'setImpCols') previewDist(); });
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
    var impRows = $.map(readImp(), function(r){
        return {v:r.v, label:r.label, cells:$.map(IMPCOLS, function(c){ return r.byName[c] || ''; })};
    });
    var s = {rm_impact:{columns:IMPCOLS, rows:impRows}, rm_likelihood:readLik(), rm_method:$('#setMethod').val(), rm_levels:readLvl(),
             rm_dimensions:[0, 1, 2, 3].map(function(i){ return $('#setSwot' + i).val(); }), rm_confirm_prefix:$('#setPrefix').val()};
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
function scaleLabel(list, v){
    if (v === '' || v == null) return '';
    var t = String(v); $.each(list, function(_, r){ if (+r.v === +v) t = r.v + ' ' + r.label; }); return t;
}
$('#btnDocPrint').on('click', function(){
    if (!CUR || !CUR.doc_id) return;
    get({action:'doc_get', doc_id:CUR.doc_id}, function(res){
        var d = res.doc, kind = d.kind, meta = res.print, S = META.settings, tr = '';
        $.each(res.items, function(i, it){
            if (kind === 'context'){
                tr += '<tr><td>' + (i + 1) + '</td><td>' + esc(META.scopes[it.scope] || '') + '</td><td>' + esc(it.dimension) + '</td>'
                    + '<td class="l">' + esc(it.stakeholder) + '</td><td class="l">' + esc(it.needs) + '</td><td class="l">' + esc(it.issue) + '</td></tr>';
            } else {
                var lv = it.level ? it.level.name : '';
                tr += '<tr><td>' + (i + 1) + '</td><td>' + esc(META.scopes[it.scope] || '') + '</td><td class="l">' + esc(it.stakeholder) + '</td>'
                    + '<td class="l">' + esc(it.needs) + '</td><td class="l">' + esc(it.issue) + '</td>'
                    + '<td class="l">' + esc(it.ro_text) + '</td><td>' + esc(META.ro_kinds[it.ro_kind] || '') + '</td>'
                    + '<td>' + esc(scaleLabel(impactList(S), it.severity)) + '</td><td>' + esc(scaleLabel(likeList(S), it.frequency)) + '</td>'
                    + '<td>' + esc(lv) + '</td><td>' + esc(it.confirm_no) + '</td>'
                    + '<td class="l">' + esc(it.measure) + '</td><td class="l">' + esc(it.effect_check) + '</td></tr>';
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
                + '<tr><th>嚴重度（影響）</th><th>頻率（可能性）</th><th>風險等級</th></tr></thead><tbody>' + tr + '</tbody></table>';
        }
        egPrintWindow(meta.doc_name || kindName(kind), head + table, meta.doc_no);
        EGPrintLog.record({source:'risk_mgmt', doc_name:(meta.doc_name || kindName(kind)) + ' ' + d.doc_no,
            doc_kind:'form', ref_table:'rm_doc', ref_id:d.doc_id});
    });
});

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
