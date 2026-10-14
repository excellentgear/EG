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

        .fgrid { display:grid; grid-template-columns:repeat(3, minmax(0,1fr)); gap:8px 14px; margin-bottom:10px; }
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
            <span class="rm-tab on" data-tab="context"><span class="tn"></span><small>組織內外部議題與利害關係者需求</small></span>
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
            <div><label>專案編號</label><input type="text" id="fProject" maxlength="60"></div>
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

<!-- ============ 評級對照表 ============ -->
<div class="rm-mask" id="ratingMask"><div class="rm-modal" style="width:860px;">
    <div class="rm-mhd"><span>評級對照表（由模組設定即時產生）</span><span class="x" data-close><i class="fa fa-times"></i></span></div>
    <div class="rm-mbd" id="ratingBody"></div>
</div></div>

<!-- ============ 模組設定 ============ -->
<div class="rm-mask" id="setMask"><div class="rm-modal" style="width:980px;">
    <div class="rm-mhd"><span>模組設定</span><span class="x" data-close><i class="fa fa-times"></i></span></div>
    <div class="rm-mbd">
        <div class="set-sec"><h5>AS 文件編號綁定（表單名稱與文件編號由綁定自動取得）</h5>
            <table class="rm-table"><thead><tr><th>表單</th><th>目前綁定</th><th></th></tr></thead><tbody id="setAsdocBody"></tbody></table>
            <div class="hint">合併後的「風險與機會因應措施管制表」建議綁定新的 AS 文件；AS 文件改名後，這裡與列印版的名稱會自動跟著變。</div></div>
        <div class="set-sec"><h5>嚴重度級距</h5><div id="setSev"></div>
            <button type="button" class="b-mini" data-addscale="sev"><i class="fa fa-plus"></i> 新增一級</button></div>
        <div class="set-sec"><h5>頻率級距</h5><div id="setFrq"></div>
            <button type="button" class="b-mini" data-addscale="frq"><i class="fa fa-plus"></i> 新增一級</button></div>
        <div class="set-sec"><h5>風險分數與風險等級</h5>
            <div class="fld" style="margin-bottom:6px;"><label>分數算法</label>
                <select id="setMethod" style="max-width:320px;"><option value="multiply">嚴重度 × 頻率</option><option value="add">嚴重度 ＋ 頻率</option><option value="max">取嚴重度與頻率較大者</option></select></div>
            <div id="setLvl"></div>
            <button type="button" class="b-mini" id="btnAddLvl"><i class="fa fa-plus"></i> 新增一級</button>
            <div class="hint">風險等級＝分數大於等於「起算分數」的最高一級；最低一級固定從 0 起算。勾選「需要因應措施」的等級，儲存時若該列沒有風險確認編號，會自動配發。</div></div>
        <div class="set-sec"><h5>組織處境分析表的構面（一行一個）</h5>
            <textarea id="setDims" style="width:100%;min-height:90px;border:1px solid #D8BE93;border-radius:4px;padding:6px 8px;box-sizing:border-box;"></textarea></div>
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
        <ul><li><b>組織處境分析表</b>：記錄公司內外部議題、相關利害關係者與他們的需求與期望，是風險與機會的來源。</li>
            <li><b>風險與機會因應措施管制表</b>：把議題整理成風險／機會，評估嚴重度與頻率，自動算出風險等級，並記錄因應措施與成效確認（原「策略行動措施管制表」與「風險評估及處理表」合併為這一張）。</li>
            <li>利害關係者的溝通記錄、措施追蹤與溝通管制在「溝通管理」頁，按上方「前往溝通管理」即可轉跳。</li></ul>
        <h4>操作步驟</h4>
        <ul><li>按「新增」→ 填專案編號、填表日期、相關部門 → 逐列填寫（最後一列按 ↓ 自動加列）→ 儲存。</li>
            <li>風險表每一列的「議題描述」可按「選擇處境議題」連動組織處境分析表：內/外部議題、利害關係者、需求與期望、議題描述都跟著來源走（來源改了這裡跟著變），不必重打；要自行修改先按「解除連動」。「風險與機會」欄選這一列是風險還是機會，「對組織的影響說明」寫它對組織造成的影響。</li>
            <li>嚴重度與頻率要一起選，<b>風險等級由系統依目前設定自動算出</b>，不可手填。</li>
            <li>等級被設定為「需要因應措施」的列，儲存時會自動配發風險確認編號。</li>
            <li>「評級對照表」可隨時查看目前每一級的名稱與說明，以及嚴重度×頻率對應的風險等級。</li></ul>
        <h4>重要行為</h4>
        <ul><li>級距、風險等級門檻、構面、確認編號前綴<b>全部可在「模組設定」修改</b>；改完後既有資料的風險等級依新設定即時重算。</li>
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
function calcLevel(sev, frq){
    if (sev === '' || sev == null || frq === '' || frq == null) return null;
    sev = +sev; frq = +frq;
    var S = META.settings, okS = false, okF = false;
    $.each(S.rm_severity, function(_, r){ if (+r.v === sev) okS = true; });
    $.each(S.rm_frequency, function(_, r){ if (+r.v === frq) okF = true; });
    if (!okS || !okF) return null;
    var score = S.rm_method === 'add' ? sev + frq : (S.rm_method === 'max' ? Math.max(sev, frq) : sev * frq);
    var hit = null;
    $.each(META.levels, function(i, lv){ if (score >= +lv.min) hit = lv; });
    return hit ? {score:score, name:hit.name, action:!!hit.action, bg:hit.bg, fg:hit.fg} : null;
}
function lvBadge(lv, withScore){
    if (!lv) return '<span class="lvb none">未評估</span>';
    return '<span class="lvb" style="background:' + lv.bg + ';color:' + lv.fg + ';">' + esc(lv.name)
        + (withScore ? '（' + lv.score + '）' : '') + '</span>';
}

/* ================= 分頁骨架 ================= */
function paneHtml(k){
    var isRisk = k === 'risk';
    return '<div class="rm-toolbar">'
        + '<button class="btn-warm" data-new="' + k + '"><i class="fa fa-plus"></i> 新增</button>'
        + '<label>年度</label><select data-year="' + k + '" style="width:92px;"></select>'
        + '<input type="text" data-kw="' + k + '" placeholder="關鍵字（單號／專案／部門／議題／措施…）" style="width:280px;">'
        + (isRisk ? '<button data-rating="1"><i class="fa fa-table"></i> 評級對照表</button>' : '')
        + '<button data-csv="' + k + '"><i class="fa fa-download"></i> 匯出 CSV</button>'
        + '</div>'
        + '<div class="rm-pager" data-pager="' + k + '"></div>'
        + '<div class="rm-table-wrap"><table class="rm-table"><thead><tr>'
        + '<th>單號</th><th>專案編號</th><th>相關部門</th><th>填表日期</th><th>筆數</th>'
        + (isRisk ? '<th>最高風險等級</th><th>需因應措施</th>' : '')
        + '<th>填表人</th><th>操作</th></tr></thead><tbody data-body="' + k + '"></tbody></table></div>';
}
function fillYears(){
    var y = new Date().getFullYear();
    KINDS.forEach(function(k){
        var s = $('[data-year="' + k + '"]').empty().append('<option value="0">全部</option>');
        for (var i = y + 1; i >= y - 8; i--) s.append('<option value="' + i + '"' + (i === y ? ' selected' : '') + '>' + i + '</option>');
    });
}
function loadList(k, resetPage){
    if (resetPage) STATE[k].page = 1;
    get({action:'doc_list', kind:k, page:STATE[k].page, per:STATE[k].per,
         year:$('[data-year="' + k + '"]').val() || 0, kw:$('[data-kw="' + k + '"]').val()}, function(res){
        var isRisk = k === 'risk', b = $('[data-body="' + k + '"]').empty();
        if (!res.rows.length) b.append('<tr><td colspan="' + (isRisk ? 9 : 7) + '" class="rm-empty">沒有符合條件的資料</td></tr>');
        $.each(res.rows, function(_, r){
            var ops = '<button class="b-mini" data-open="' + r.doc_id + '" data-kind="' + k + '"><i class="fa fa-' + (r.can_edit ? 'pencil' : 'eye') + '"></i> '
                + (r.can_edit ? '編輯' : '檢視') + '</button>'
                + (META.perms.canAdmin ? '<button class="b-mini danger" data-del="' + r.doc_id + '"><i class="fa fa-trash"></i></button>' : '');
            b.append('<tr><td>' + esc(r.doc_no) + '</td><td>' + esc(r.project_no) + '</td><td class="l">' + esc(r.dept_names) + '</td>'
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
    loadList(k);                           // 切換分頁一律刷新（點開即刷新）
});

/* ================= 表單編輯 ================= */
function colsOf(kind){
    // [欄位 key, 標題, 寬度px]
    if (kind === 'context') return [['seq','編號',52],['scope','內/外部議題',100],['dimension','構面',130],['stakeholder','利害關係者',150],
        ['needs','需求與期望',230],['issue','議題描述',300],['op','',44]];
    return [['seq','編號',52],['scope','內/外部議題',96],['stakeholder','利害關係者',130],['needs','需求與期望',170],['issue','議題描述',210],
        ['ro_text','對組織的影響說明',210],['ro_kind','風險與機會',92],['severity','嚴重度',92],['frequency','頻率',92],['level','風險等級',92],['confirm_no','風險確認編號',104],
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
          + td('<select data-f="severity">' + scaleOptions(META.settings.rm_severity, it.severity) + '</select>')
          + td('<select data-f="frequency">' + scaleOptions(META.settings.rm_frequency, it.frequency) + '</select>')
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
          + '<tr><th>嚴重度</th><th>頻率</th><th>風險等級</th></tr>';
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
        $('#fProject').val(doc ? (doc.project_no || '') : '');
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
    post({action:'doc_delete', doc_id:id}, function(){ KINDS.forEach(function(k){ loadList(k); }); });
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
    post({action:'doc_save', doc_id:CUR.doc_id, kind:CUR.kind, project_no:$('#fProject').val(), fill_date:date,
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
            b.append('<tr><td>' + esc(r.doc_no) + '</td><td>' + esc(META.scopes[r.scope] || '') + '</td><td>' + esc(r.dimension) + '</td>'
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
    var S = META.settings, h = '';
    var scale = function(title, list){
        var t = '<h4 style="color:#8A5A2B;margin:10px 0 4px;">' + title + '</h4><table class="mx"><thead><tr><th>數值</th><th>名稱</th><th>說明</th></tr></thead><tbody>';
        $.each(list, function(_, r){ t += '<tr><td>' + r.v + '</td><td>' + esc(r.label) + '</td><td style="text-align:left;">' + esc(r.desc) + '</td></tr>'; });
        return t + '</tbody></table>';
    };
    h += scale('嚴重度', S.rm_severity) + scale('頻率', S.rm_frequency);
    h += '<h4 style="color:#8A5A2B;margin:10px 0 4px;">風險等級（分數算法：' + {multiply:'嚴重度 × 頻率', add:'嚴重度 ＋ 頻率', max:'取較大者'}[S.rm_method] + '）</h4>'
       + '<table class="mx"><thead><tr><th>等級</th><th>起算分數</th><th>需要因應措施</th></tr></thead><tbody>';
    $.each(META.levels, function(_, lv){
        h += '<tr><td>' + lvBadge(lv, false) + '</td><td>' + lv.min + '</td><td>' + (lv.action ? '是' : '否') + '</td></tr>';
    });
    h += '</tbody></table><h4 style="color:#8A5A2B;margin:10px 0 4px;">嚴重度 × 頻率 對照</h4><table class="mx"><thead><tr><th>嚴重度＼頻率</th>';
    $.each(S.rm_frequency, function(_, f){ h += '<th>' + f.v + ' ' + esc(f.label) + '</th>'; });
    h += '</tr></thead><tbody>';
    $.each(S.rm_severity, function(_, s){
        h += '<tr><th>' + s.v + ' ' + esc(s.label) + '</th>';
        $.each(S.rm_frequency, function(_, f){ h += '<td>' + lvBadge(calcLevel(s.v, f.v), true) + '</td>'; });
        h += '</tr>';
    });
    $('#ratingBody').html(h + '</tbody></table>');
}
$(document).on('click', '[data-rating]', function(){ renderRating(); openMask('ratingMask'); });

/* ================= 模組設定 ================= */
var SETDRAFT = null;
function scaleRowHtml(kind, r){
    return '<tr><td><input type="number" data-s="v" value="' + esc(r.v) + '" style="width:70px;" min="1" max="99"></td>'
        + '<td><input type="text" data-s="label" value="' + esc(r.label) + '" maxlength="20"></td>'
        + '<td><input type="text" data-s="desc" value="' + esc(r.desc) + '" maxlength="200"></td>'
        + '<td><button type="button" class="b-mini danger" data-rmrow><i class="fa fa-times"></i></button></td></tr>';
}
function scaleTable(id, list){
    var t = '<table class="rm-table fld"><thead><tr><th style="width:80px;">數值</th><th style="width:150px;">名稱</th><th>說明</th><th style="width:44px;"></th></tr></thead><tbody>';
    $.each(list, function(_, r){ t += scaleRowHtml(id, r); });
    $('#' + id).html(t + '</tbody></table>');
}
function lvlRowHtml(r){
    return '<tr><td><input type="text" data-l="name" value="' + esc(r.name) + '" maxlength="10" style="width:110px;"></td>'
        + '<td><input type="number" data-l="min" value="' + esc(r.min) + '" min="0" style="width:90px;"></td>'
        + '<td style="text-align:center;"><input type="checkbox" data-l="action"' + (r.action ? ' checked' : '') + '></td>'
        + '<td><button type="button" class="b-mini danger" data-rmrow><i class="fa fa-times"></i></button></td></tr>';
}
function renderSet(){
    var S = META.settings;
    scaleTable('setSev', S.rm_severity); scaleTable('setFrq', S.rm_frequency);
    var t = '<table class="rm-table fld"><thead><tr><th>等級名稱</th><th>起算分數</th><th>需要因應措施</th><th style="width:44px;"></th></tr></thead><tbody>';
    $.each(S.rm_levels, function(_, r){ t += lvlRowHtml(r); });
    $('#setLvl').html(t + '</tbody></table>');
    $('#setMethod').val(S.rm_method); $('#setDims').val(S.rm_dimensions.join('\n')); $('#setPrefix').val(S.rm_confirm_prefix);
    var b = $('#setAsdocBody').empty();
    $.each(META.asdoc, function(k, v){
        var cur = v.doc ? (v.doc.doc_no + '（' + v.doc.doc_name + '）') : '尚未綁定　建議：' + v.fallback;
        b.append('<tr><td class="l">' + esc(v.label) + '</td><td class="l">' + esc(cur) + '</td>'
            + '<td><button class="b-mini" data-pickasdoc="' + k + '">選擇</button></td></tr>');
    });
    $('#eSet').text('');
}
$(document).on('click', '[data-rmrow]', function(){
    var tb = $(this).closest('tbody');
    if (tb.find('tr').length <= 1){ alert('至少要保留一列'); return; }
    $(this).closest('tr').remove();
});
$(document).on('click', '[data-addscale]', function(){
    var id = $(this).data('addscale') === 'sev' ? 'setSev' : 'setFrq', tb = $('#' + id + ' tbody');
    var mx = 0; tb.find('[data-s=v]').each(function(){ mx = Math.max(mx, +$(this).val() || 0); });
    tb.append(scaleRowHtml(id, {v:mx + 1, label:'', desc:''}));
});
$('#btnAddLvl').on('click', function(){ $('#setLvl tbody').append(lvlRowHtml({name:'', min:'', action:false})); });
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
function readScale(id){
    var out = [];
    $('#' + id + ' tbody tr').each(function(){
        out.push({v:$(this).find('[data-s=v]').val(), label:$(this).find('[data-s=label]').val(), desc:$(this).find('[data-s=desc]').val()});
    });
    return out;
}
$('#btnSetting').on('click', function(){ renderSet(); openMask('setMask'); });
$('#btnSetSave').on('click', function(){
    var lv = [];
    $('#setLvl tbody tr').each(function(){
        lv.push({name:$(this).find('[data-l=name]').val(), min:$(this).find('[data-l=min]').val(), action:$(this).find('[data-l=action]').is(':checked')});
    });
    var s = {rm_severity:readScale('setSev'), rm_frequency:readScale('setFrq'), rm_method:$('#setMethod').val(), rm_levels:lv,
             rm_dimensions:$('#setDims').val().split('\n'), rm_confirm_prefix:$('#setPrefix').val()};
    var send = function(force){
        $('#eSet').text('');
        NProgress.start();
        // 這支要自己處理 409（需要確認），所以關掉全域錯誤彈窗
        $.ajax({url:API, method:'POST', dataType:'json', global:false, data:{action:'setting_save', csrf:CSRF, settings:JSON.stringify(s), force:force ? 1 : 0}})
         .done(function(res){
            NProgress.done();
            META.settings = res.settings; META.levels = res.levels;
            alert('設定已儲存'); closeMask('setMask');
            KINDS.forEach(function(k){ loadList(k); });
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
function egPrintWindow(title, bodyHtml, docNo){
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
        + '.pt-foot{position:fixed;right:8mm;bottom:5mm;font-size:9pt;color:#333;}';
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
                    + '<td>' + esc(scaleLabel(S.rm_severity, it.severity)) + '</td><td>' + esc(scaleLabel(S.rm_frequency, it.frequency)) + '</td>'
                    + '<td>' + esc(lv) + '</td><td>' + esc(it.confirm_no) + '</td>'
                    + '<td class="l">' + esc(it.measure) + '</td><td class="l">' + esc(it.effect_check) + '</td></tr>';
            }
        });
        var head = '<div class="pt-head"><div class="co">' + esc(meta.company) + '</div><div class="tt">' + esc(meta.doc_name || kindName(kind)) + '</div></div>'
            + '<div class="pt-meta"><span>專案編號：' + esc(d.project_no) + '</span><span>相關部門：' + esc(d.dept_names) + '</span><span>填表日期：' + esc(dispDate(d.fill_date)) + '</span></div>';
        var table;
        if (kind === 'context'){
            table = '<table class="pt"><colgroup><col style="width:40px;"><col style="width:70px;"><col style="width:100px;"><col style="width:140px;"><col><col></colgroup>'
                + '<thead><tr><th>編號</th><th>內/外部議題</th><th>構面</th><th>利害關係者</th><th>需求與期望</th><th>議題描述</th></tr></thead><tbody>' + tr + '</tbody></table>';
        } else {
            table = '<table class="pt"><colgroup><col style="width:34px;"><col style="width:52px;"><col style="width:84px;"><col style="width:100px;"><col style="width:120px;">'
                + '<col style="width:120px;"><col style="width:56px;"><col style="width:56px;"><col style="width:56px;"><col style="width:56px;"><col style="width:70px;"><col><col></colgroup>'
                + '<thead><tr><th rowspan="2">編號</th><th rowspan="2">內/外部議題</th><th rowspan="2">利害關係者</th><th rowspan="2">需求與期望</th><th rowspan="2">議題描述</th>'
                + '<th rowspan="2">對組織的影響說明</th><th rowspan="2">風險與機會</th><th colspan="3">風險評估</th><th rowspan="2">風險確認編號</th><th rowspan="2">因應措施</th><th rowspan="2">成效確認</th></tr>'
                + '<tr><th>嚴重度</th><th>頻率</th><th>風險等級</th></tr></thead><tbody>' + tr + '</tbody></table>';
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
        var rows = [['單號','專案編號','相關部門','填表日期','筆數'].concat(k === 'risk' ? ['最高風險等級','需因應措施筆數'] : []).concat(['填表人'])];
        $.each(res.rows, function(_, r){
            rows.push([r.doc_no, r.project_no || '', r.dept_names || '', dispDate(r.fill_date), r.item_cnt]
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
    KINDS.forEach(function(k){ $('#pane_' + k).html(paneHtml(k)); });
    fillYears(); renderTabNames(); renderAsdocHint(); renderRoleHelp();
    var q = {};
    location.search.replace(/^\?/, '').split('&').forEach(function(kv){ if (!kv) return; var p = kv.split('='); q[decodeURIComponent(p[0])] = decodeURIComponent(p[1] || ''); });
    var startTab = q.tab === 'risk' ? 'risk' : 'context';
    $('#mainTabs .rm-tab[data-tab="' + startTab + '"]').trigger('click');
    if (+q.doc) openDoc(startTab, +q.doc);
});
</script>
<?php endif; ?>
</body>
</html>
