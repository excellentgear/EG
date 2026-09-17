<?php
/**
 * 溝通管理（3-GM-01）— 一頁三分頁，2026-09-14 建立
 *   分頁一 利害關係者溝通記錄表   3-GM-01-01（單據型，唯一需要簽章）
 *   分頁二 回應利害關係者措施追蹤表 3-GM-01-02（只盯還沒做完的事）
 *   分頁三 溝通管制表               3-GM-01-03（常態性溝通機制）
 * 資料一律走 src/store/CommMgmt_API.php；共用邏輯 src/common/comm_mgmt_lib.php
 */
session_start();
if (!isset($_SESSION['userName'])) {
    $_SESSION['lastpage'] = "../../views/GM/communication_mgmt.php";
    header("Location:../../index.php");
    exit;
}
include_once '../../src/common/_config.php';
include_once '../../src/common/DBConnection.php';
include_once '../../src/common/comm_mgmt_lib.php';

$db = (new DBConnection())->getPDO();
cm_ensure_schema($db);
$cmUser = cm_current_user($db);
$perms  = cm_perms($db, $cmUser);
$roleLabel = cm_role_label($perms);
/* 溝通管制表的到期提醒是「順路觸發」的，掛在全站共用的 _config.php（與 personal_task／CAR 同一處），
   不是掛在這一頁——只掛這頁的話，沒有人打開溝通管理就永遠不會檢查，提醒等於沒有作用。 */
?>
<!DOCTYPE html>
<html lang="zh-Hant">
<head>
    <meta charset="utf-8">
    <meta http-equiv="X-UA-Compatible" content="IE=edge">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>溝通管理</title>
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

        /* ---- 分頁 ---- */
        .cm-tabs { display:flex; flex-wrap:wrap; gap:4px; border-bottom:2px solid #E0BE86; margin:6px 0 10px; clear:both; }
        .cm-tab { padding:7px 16px; font-size:14px; color:#8a6d45; background:#FBF5EA; border:1px solid #E8D5B5;
            border-bottom:none; border-radius:6px 6px 0 0; cursor:pointer; margin-bottom:-2px; }
        .cm-tab:hover { background:#F7E0BD; }
        .cm-tab.on { background:#F0A24B; color:#fff; border-color:#d98a33; font-weight:bold; }
        .cm-tab small { display:block; font-size:11px; opacity:.85; }
        .cm-pane { display:none; }
        .cm-pane.on { display:block; }

        /* ---- 工具列 ---- */
        .cm-toolbar { display:flex; flex-wrap:wrap; gap:6px; align-items:center; clear:both;
            border:1.5px solid #E8D5B5; border-radius:8px; padding:8px 10px; margin-bottom:10px; background:#FDF8EF; }
        .cm-toolbar label { margin:0; font-size:13px; color:#5b3a1e; }
        .cm-toolbar select, .cm-toolbar input, .cm-toolbar button {
            height:30px; font-size:13px; line-height:1; padding:0 10px; border:1px solid #D8BE93;
            border-radius:4px; background:#fff; color:#5b3a1e; }
        .cm-toolbar button { cursor:pointer; }
        .cm-toolbar button:hover { background:#F7E0BD; }
        .cm-toolbar .btn-warm { background:#F0A24B; color:#fff; border-color:#d98a33; }
        .cm-toolbar .btn-warm:hover { background:#d98a33; }
        .cm-role-badge { margin-left:auto; font-size:13px; color:#5b3a1e; background:#F7E0BD; border-radius:12px; padding:4px 12px; }

        /* ---- 分頁列（列表右上） ---- */
        .cm-pager { display:flex; justify-content:flex-end; align-items:center; gap:5px; margin:0 0 6px; font-size:12.5px; color:#8a6d45; }
        .cm-pager button { height:26px; min-width:28px; padding:0 8px; border:1px solid #D8BE93; background:#fff;
            color:#5b3a1e; border-radius:4px; cursor:pointer; font-size:12.5px; }
        .cm-pager button.on { background:#F0A24B; color:#fff; border-color:#d98a33; }
        .cm-pager button[disabled] { opacity:.45; cursor:default; }
        .cm-pager select { height:26px; font-size:12.5px; border:1px solid #D8BE93; border-radius:4px; background:#fff; color:#5b3a1e; }

        /* ---- 表格 ---- */
        .cm-table-wrap { overflow-x:auto; border:1px solid #E8D5B5; border-radius:6px; background:#fff; }
        table.cm-table { width:100%; border-collapse:collapse; font-size:13px; }
        table.cm-table th, table.cm-table td { border:1px solid #EADFC8; padding:5px 8px; text-align:center; vertical-align:middle; }
        table.cm-table thead th { background:#F7E0BD; color:#5b3a1e; font-weight:bold; white-space:nowrap; }
        table.cm-table tbody tr:nth-child(even) { background:#FBF6EC; }
        table.cm-table tbody tr:hover { background:#FBF0DD; }
        table.cm-table td.l { text-align:left; }
        table.cm-table td.wrap { white-space:normal; word-break:break-word; min-width:150px; }
        .cm-empty { padding:22px; text-align:center; color:#8a6d45; }

        /* ---- 狀態徽章（顏色不是唯一資訊，一律配文字＝ai-rules/10） ---- */
        .st { display:inline-block; padding:1px 8px; border-radius:10px; font-size:11.5px; line-height:17px;
            border:1px solid transparent; white-space:nowrap; }
        .st-draft    { background:#F5EEE2; color:#8a6d45; border-color:#E0CDA9; }
        .st-mgr_wait { background:#F7E0BD; color:#7a4e14; border-color:#E0BE86; }
        .st-gm_wait  { background:#F0A24B; color:#fff;    border-color:#d98a33; }
        .st-closed   { background:#EFE3CC; color:#5b3a1e; border-color:#D6BE94; }
        .st-rej      { background:#DD5138; color:#fff;    border-color:#C4442D; }
        .tg-open     { background:#F0A24B; color:#fff; border-color:#d98a33; }
        .tg-closed   { background:#EFE3CC; color:#5b3a1e; border-color:#D6BE94; }
        .tg-late     { background:#DD5138; color:#fff; border-color:#C4442D; }
        .b-mini { height:24px; padding:0 8px; font-size:12px; border:1px solid #D8BE93; background:#fff; color:#5b3a1e;
            border-radius:4px; cursor:pointer; margin:1px; white-space:nowrap; }
        .b-mini:hover { background:#F7E0BD; }
        .b-mini.warm { background:#F0A24B; color:#fff; border-color:#d98a33; }
        .b-mini.warm:hover { background:#d98a33; }
        .b-mini.danger { color:#DD5138; }

        /* ---- 跳窗（寬度一律固定像素，禁用 vw＝會蓋過側邊選單） ---- */
        .cm-mask { display:none; position:fixed; inset:0; background:rgba(60,40,20,.45); z-index:11000; overflow-y:auto; }
        .cm-mask.on { display:block; }
        .cm-modal { background:#fff; border-radius:8px; margin:34px auto; box-shadow:0 5px 25px rgba(0,0,0,.3);
            display:flex; flex-direction:column; max-height:88vh; max-width:96%; }
        .cm-mhd { background:#F7E0BD; color:#5b3a1e; font-weight:bold; padding:10px 15px; border-radius:8px 8px 0 0;
            display:flex; justify-content:space-between; align-items:center; }
        .cm-mhd .x { cursor:pointer; color:#b5762a; }
        .cm-mbd { padding:14px 16px; overflow-y:auto; overflow-x:hidden; }
        .cm-mft { padding:10px 15px; border-top:1px solid #EADFC8; text-align:right; }
        .cm-mft button, .cm-mbd button.act { height:30px; padding:0 16px; border-radius:4px; font-size:13px; cursor:pointer;
            border:1px solid #D8BE93; background:#fff; color:#5b3a1e; margin-left:6px; }
        .cm-mft button.btn-warm { border-color:#d98a33; background:#F0A24B; color:#fff; }
        .cm-mft button.btn-danger { border-color:#C4442D; background:#DD5138; color:#fff; }

        /* ---- 表單版面 ---- */
        .fgrid { display:grid; grid-template-columns:repeat(2, minmax(0,1fr)); gap:8px 14px; }
        .fgrid .full { grid-column:1 / -1; min-width:0; }
        .fgrid > div { min-width:0; }
        .fgrid label, .fld label { display:block; font-size:12.5px; color:#5b3a1e; margin:0 0 2px; font-weight:bold; }
        .fgrid input[type=text], .fgrid input[type=date], .fgrid input[type=number], .fgrid select, .fgrid textarea,
        .fld input[type=text], .fld input[type=date], .fld select, .fld textarea {
            width:100%; border:1px solid #D8BE93; border-radius:4px; padding:5px 8px; font-size:13px;
            background:#FFFDF8; color:#5b3a1e; box-sizing:border-box; }
        .fgrid textarea, .fld textarea { min-height:56px; resize:vertical; }
        .chk-row { display:flex; flex-wrap:wrap; gap:4px 16px; align-items:center; padding:4px 0; }
        .chk-row label { font-weight:normal; display:inline-flex; align-items:center; gap:4px; margin:0; font-size:13px; }
        .chk-row input[type=checkbox], .chk-row input[type=radio] { margin:0; }
        .chk-row input[type=text] { width:170px; border:1px solid #D8BE93; border-radius:4px; padding:3px 6px;
            font-size:12.5px; background:#FFFDF8; color:#5b3a1e; }
        .err { color:#DD5138; font-size:12px; min-height:16px; display:block; }
        input.bad, select.bad, textarea.bad { border-color:#DD5138 !important; background:#FFF6F3 !important; }
        .hint { font-size:12px; color:#8a6d45; line-height:1.6; }
        .note-box { background:#FBF5EA; border:1px solid #EADFC8; border-radius:6px; padding:8px 10px; font-size:12.5px;
            color:#5b3a1e; line-height:1.7; }
        .note-box b { color:#8A5A2B; }

        table.it-tbl { width:100%; border-collapse:collapse; font-size:12.5px; }
        table.it-tbl th, table.it-tbl td { border:1px solid #EADFC8; padding:3px 5px; vertical-align:top; }
        table.it-tbl thead th { background:#F7E0BD; color:#5b3a1e; text-align:center; }
        table.it-tbl textarea { width:100%; min-height:44px; border:1px solid #D8BE93; border-radius:4px; padding:4px 6px;
            font-size:12.5px; background:#FFFDF8; color:#5b3a1e; box-sizing:border-box; resize:vertical; }
        table.it-tbl td.seq { text-align:center; width:34px; color:#8a6d45; }
        table.it-tbl td.op { width:104px; text-align:center; }

        /* ---- 簽章格（畫面預覽；列印版另有自己的 CSS） ---- */
        .sign-wrap { display:flex; gap:10px; flex-wrap:wrap; margin-top:10px; }
        .sign-box { flex:1 1 240px; border:1px solid #D8BE93; border-radius:6px; padding:6px 10px; background:#FFFDF8;
            min-height:104px; }
        .sign-box .lb { font-size:12.5px; color:#5b3a1e; font-weight:bold; }
        .sign-box .sub { font-size:11.5px; color:#8a6d45; }
        .sign-box .box { text-align:center; min-height:76px; }
        .stamp-wrap svg, svg.car-stamp { width:76px; height:76px; }

        /* ---- 人員多選 chips ---- */
        .chips { display:flex; flex-wrap:wrap; gap:4px; margin:4px 0; min-height:24px; }
        .chip { background:#F7E0BD; border:1px solid #E0BE86; color:#5b3a1e; border-radius:12px;
            padding:1px 8px; font-size:12px; display:inline-flex; align-items:center; gap:5px; }
        .chip i { cursor:pointer; color:#b5762a; }
        .att-row { display:flex; align-items:center; gap:8px; font-size:12.5px; padding:3px 0; border-bottom:1px dashed #EADFC8; }
        .att-row .nm { flex:1 1 auto; color:#5b3a1e; word-break:break-all; }
        .att-row .sz { color:#8a6d45; font-size:11.5px; white-space:nowrap; }

        /* ---- 依類別連動的利害關係者挑選器 ---- */
        .pk-box { border:1px dashed #E0CDA9; border-radius:6px; background:#FDF8EF; padding:7px 9px; margin-top:5px; }
        .pk-line { display:flex; flex-wrap:wrap; gap:6px; align-items:center; margin-bottom:5px; min-width:0; }
        .pk-line > * { min-width:0; max-width:100%; }
        .pk-line:last-child { margin-bottom:0; }
        .pk-line > label { font-size:12.5px; color:#5b3a1e; font-weight:bold; margin:0; white-space:nowrap; }
        .pk-line input[type=text], .pk-line select { border:1px solid #D8BE93; border-radius:4px; padding:4px 7px;
            font-size:12.5px; background:#fff; color:#5b3a1e; box-sizing:border-box; height:28px; }
        .pk-line select { max-width:100%; }
        .pk-grow { flex:1 1 220px; min-width:120px; max-width:100%; }
        .pk-sum { font-size:12px; color:#8A5A2B; background:#F7E0BD; border-radius:4px; padding:3px 8px;
            display:inline-block; margin-top:4px; }
        .pk-sum.none { background:#F5EEE2; color:#8a6d45; }

        /* ---- 單一欄位的模糊搜尋自動完成（打字即時列建議，選了就填回同一個欄位） ----
           清單用 position:fixed 由 JS 定位：跳窗的 .cm-mbd 是 overflow-y:auto 的捲動容器，
           用 absolute 會在捲動時被容器裁掉（這個專案在 review_form 已經踩過一次）。 */
        .pk-ac { position:relative; }
        .pk-ac-list { position:fixed; z-index:11500; background:#fff; border:1px solid #D8BE93; border-radius:0 0 4px 4px;
            max-height:230px; overflow-y:auto; display:none; box-shadow:0 4px 14px rgba(60,40,20,.22); }
        .pk-ac-item { padding:5px 9px; font-size:12.5px; color:#5b3a1e; cursor:pointer; border-bottom:1px solid #F3E8D4;
            white-space:nowrap; overflow:hidden; text-overflow:ellipsis; }
        .pk-ac-item:last-child { border-bottom:none; }
        .pk-ac-item:hover, .pk-ac-item.on { background:#F7E0BD; }
        .pk-ac-item .id { color:#8A5A2B; font-weight:bold; margin-right:6px; }
        .pk-ac-empty { padding:6px 9px; font-size:12px; color:#8a6d45; }
        /* 已綁定的編號：顯示在該欄位「上方」（使用者指定），只有客戶／供應商才會出現 */
        .pk-bound { font-size:12px; margin:0 0 3px; }
        .pk-bound b { color:#8A5A2B; }
        .pk-bound .tag { background:#F7E0BD; border:1px solid #E0BE86; color:#5b3a1e; border-radius:4px;
            padding:1px 7px; display:inline-block; }
        .pk-bound .no { background:#FFF6F3; border:1px solid #E7BDB2; color:#C4442D; border-radius:4px;
            padding:1px 7px; display:inline-block; }

        /* ---- 頻率「每 N 單位 M 次」 ---- */
        .freq-row { display:flex; flex-wrap:wrap; gap:5px; align-items:center; font-size:13px; color:#5b3a1e; }
        .freq-row input[type=number] { width:64px; text-align:center; }
        .freq-row select { width:86px; }
    </style>
</head>
<body class="nav-sm">
<div class="container body">
<div class="main_container">
    <?php include '../partPage/sideAndTopBarMenu.html' ?>
    <div class="right_col" role="main">
        <div class="page-title" style="display:flex;align-items:center;flex-wrap:wrap;">
            <h2 style="margin:6px 0;">溝通管理
                <small style="color:#8a6d45;">3-GM-01　溝通管制表 → 溝通記錄表 → 措施追蹤表，一頁控管</small></h2>
            <button id="btnPageHelp" class="page-help-btn" style="margin-left:auto;"><i class="fa fa-question-circle"></i> 使用說明</button>
        </div>
        <div class="clearfix"></div>

<?php if (!$perms['uid']): ?>
        <div class="note-box" style="margin-top:12px;">
            <h4 style="color:#DD5138;margin-top:0;"><i class="fa fa-lock"></i> 無溝通管理使用權限</h4>
            此帳號目前不是在職狀態，無法使用本模組。如有疑問請洽人事或系統管理者。
        </div>
<?php else: ?>
        <div class="cm-toolbar">
            <button id="btnSetting" class="btn-warm" style="display:none;"><i class="fa fa-sliders"></i> 模組設定</button>
            <span id="asdocHint" class="hint" style="flex:1 1 320px;"></span>
            <span class="cm-role-badge">目前角色：<b><?= htmlspecialchars($roleLabel) ?></b>
                <i class="fa fa-question-circle" id="btnRoleHelp" style="cursor:pointer;" title="角色權限說明"></i></span>
        </div>

        <div class="cm-tabs" id="mainTabs">
            <span class="cm-tab on" data-tab="rec">利害關係者溝通記錄表<small>3-GM-01-01　事件當下的紀錄</small></span>
            <span class="cm-tab" data-tab="track">回應利害關係者措施追蹤表<small>3-GM-01-02　盯還沒做完的事</small></span>
            <span class="cm-tab" data-tab="ctrl">溝通管制表<small>3-GM-01-03　常態性溝通機制</small></span>
        </div>

        <!-- ============ 分頁一：溝通記錄表 ============ -->
        <div class="cm-pane on" id="paneRec">
            <div class="cm-toolbar">
                <button id="btnRecAdd" class="btn-warm"><i class="fa fa-plus"></i> 新增溝通記錄</button>
                <label>狀態</label>
                <select id="recStatus" style="width:130px;"><option value="">全部</option></select>
                <label>類別</label>
                <select id="recKind" style="width:110px;"><option value="">全部</option></select>
                <label>溝通日期</label>
                <input type="date" id="recFrom" style="width:140px;"> ～ <input type="date" id="recTo" style="width:140px;">
                <label style="display:inline-flex;align-items:center;gap:4px;">
                    <input type="checkbox" id="recMine"> 只看我建立的</label>
                <input type="text" id="recKw" placeholder="搜尋單號/對象/問題/回覆" style="width:210px;">
                <button id="btnRecSearch"><i class="fa fa-search"></i> 查詢</button>
                <button id="btnRecCsv"><i class="fa fa-file-text-o"></i> 匯出CSV</button>
            </div>
            <div class="cm-pager" id="recPager"></div>
            <div class="cm-table-wrap">
                <table class="cm-table" id="recTable">
                    <thead><tr>
                        <th style="width:110px;">單號</th><th style="width:96px;">溝通日期</th><th style="width:62px;">型態</th>
                        <th style="width:70px;">類別</th><th>利害關係者</th><th style="width:150px;">填表人/部門</th>
                        <th style="width:110px;">管道</th><th style="width:58px;">問題數</th><th style="width:62px;">附件</th>
                        <th style="width:120px;">狀態</th><th style="width:210px;">操作</th>
                    </tr></thead>
                    <tbody id="recBody"><tr><td colspan="11" class="cm-empty">載入中…</td></tr></tbody>
                </table>
            </div>
        </div>

        <!-- ============ 分頁二：措施追蹤表 ============ -->
        <div class="cm-pane" id="paneTrack">
            <div class="cm-toolbar">
                <button id="btnTrackAdd" class="btn-warm"><i class="fa fa-plus"></i> 新增追蹤項目</button>
                <label>顯示</label>
                <select id="trackShow" style="width:150px;">
                    <option value="open">未結案（預設）</option>
                    <option value="closed">已結案</option>
                    <option value="all">全部</option>
                </select>
                <input type="text" id="trackKw" placeholder="搜尋利害關係者/內容/措施/負責人" style="width:230px;">
                <button id="btnTrackSearch"><i class="fa fa-search"></i> 查詢</button>
                <button id="btnTrackPrint"><i class="fa fa-print"></i> 列印追蹤表</button>
                <button id="btnTrackCsv"><i class="fa fa-file-text-o"></i> 匯出CSV</button>
            </div>
            <div class="cm-pager" id="trackPager"></div>
            <div class="cm-table-wrap">
                <table class="cm-table" id="trackTable">
                    <thead><tr>
                        <th style="width:50px;">項次</th><th style="width:150px;">利害關係者</th><th>反應內容</th>
                        <th style="width:96px;">反應日期</th><th>回應措施</th><th style="width:100px;">負責人</th>
                        <th style="width:110px;">預計完成日</th><th style="width:96px;">是否結案</th>
                        <th style="width:110px;">來源</th><th style="width:130px;">操作</th>
                    </tr></thead>
                    <tbody id="trackBody"><tr><td colspan="10" class="cm-empty">載入中…</td></tr></tbody>
                </table>
            </div>
        </div>

        <!-- ============ 分頁三：溝通管制表 ============ -->
        <div class="cm-pane" id="paneCtrl">
            <div class="cm-toolbar">
                <button id="btnCtrlAdd" class="btn-warm"><i class="fa fa-plus"></i> 新增管制項目</button>
                <input type="text" id="ctrlKw" placeholder="搜尋填表人/利害關係人/內容/管道/頻率" style="width:250px;">
                <button id="btnCtrlSearch"><i class="fa fa-search"></i> 查詢</button>
                <button id="btnCtrlPrint"><i class="fa fa-print"></i> 列印管制表</button>
                <button id="btnCtrlCsv"><i class="fa fa-file-text-o"></i> 匯出CSV</button>
                <span class="hint" style="flex:1 1 260px;">管制表只寫<b>常態性溝通機制</b>（例：每週與外包廠的產銷會議），單一突發事件請記到「溝通記錄表」。</span>
            </div>
            <div class="cm-pager" id="ctrlPager"></div>
            <div class="cm-table-wrap">
                <table class="cm-table" id="ctrlTable">
                    <thead><tr>
                        <th style="width:50px;">項次</th><th style="width:120px;">填表人</th><th style="width:170px;">利害關係人</th>
                        <th>溝通內容</th><th style="width:140px;">溝通管道</th><th style="width:106px;">頻率</th>
                        <th style="width:170px;">下次應溝通日／提醒</th><th style="width:190px;">操作</th>
                    </tr></thead>
                    <tbody id="ctrlBody"><tr><td colspan="8" class="cm-empty">載入中…</td></tr></tbody>
                </table>
            </div>
        </div>
<?php endif; ?>
    </div><!-- right_col -->
</div></div>

<!-- ======================= 溝通記錄表：編輯 ======================= -->
<div class="cm-mask" id="recMask"><div class="cm-modal" style="width:1080px;">
    <div class="cm-mhd"><span id="recTitle">新增溝通記錄</span><span class="x" data-close>&times;</span></div>
    <div class="cm-mbd">
        <div id="recRejectBox" class="note-box" style="display:none;border-color:#DD5138;background:#FFF6F3;margin-bottom:10px;"></div>
        <div class="fgrid">
            <div class="full">
                <label>型態 <span style="color:#DD5138;">*</span></label>
                <div class="chk-row" id="recTypeRow"></div>
            </div>
            <div>
                <label>溝通日期 <span style="color:#DD5138;">*</span>
                    <span class="hint" style="font-weight:normal;">（本單業務日期：AS 版次與圖章日期都依它）</span></label>
                <input type="date" id="fCommDate"><span class="err" id="eCommDate"></span>
            </div>
            <div>
                <label>利害關係者公司/代表人 <span style="color:#DD5138;">*</span>
                    <span class="hint" id="fPartyLock" style="font-weight:normal;display:none;">（打字即時搜尋，選了就填在這一格）</span></label>
                <!-- 綁定的編號顯示在欄位「上方」，只有類別＝客戶／供應商時才出現 -->
                <div class="pk-bound" id="fPartyBound" style="display:none;"></div>
                <div class="pk-ac">
                    <input type="text" id="fParty" maxlength="200" autocomplete="off">
                    <div class="pk-ac-list" id="fPartyList"></div>
                </div>
                <span class="err" id="eParty"></span>
            </div>
            <div class="full">
                <label>類別 <span style="color:#DD5138;">*</span></label>
                <div class="chk-row" id="recKindRow"></div><span class="err" id="eKind"></span>

                <!-- 類別連動的對象挑選（客戶／供應商＝模糊搜尋、員工＝先選部門、其他＝手填）。
                     後端 rec_save 會用同一批資料來源再核對一次＝鐵律8，不是只擋這裡的 UI。 -->
                <div class="pk-box" id="pkSearchBox" style="display:none;">
                    <div class="pk-line">
                        <label>代表人</label>
                        <select id="pkContact" style="width:210px;" data-eg-filter="輸入姓名篩選…"></select>
                        <input type="text" id="pkContactName" class="pk-grow" maxlength="100"
                               data-eg-hint="清單裡沒有這位聯絡人時，直接在這裡手寫姓名">
                    </div>
                    <div class="hint">對象請直接在上方「利害關係者公司/代表人」欄位打名稱或編號（例如 <b>歐克</b> 或 <b>.D001</b>），
                        會即時列出建議清單供選擇。代表人可從對方登錄的聯絡人挑，也可以自己手寫（選填）。</div>
                </div>

                <div class="pk-box" id="pkEmpBox" style="display:none;">
                    <div class="pk-line">
                        <label>部門</label>
                        <select id="pkDept" style="width:230px;"></select>
                        <label>員工／職稱</label>
                        <select id="pkUser" class="pk-grow"></select>
                    </div>
                    <div class="hint">職稱依<b>溝通日期</b>回推當時的（ai-rules/22）；一個人身兼多職時，每個職稱各列一列。</div>
                </div>

                <div class="pk-box" id="pkOtherBox" style="display:none;">
                    <div class="pk-line">
                        <label>類別說明 <span style="color:#DD5138;">*</span></label>
                        <input type="text" id="fKindOther" class="pk-grow" maxlength="100"
                               data-eg-hint="例如：主管機關、認證機構、社區、股東">
                    </div>
                    <div class="hint">「其他」類別的利害關係者公司／代表人請直接在上方欄位手動填寫。</div>
                </div>
                <div id="pkSummary"></div>
            </div>
            <div class="full">
                <label>管道 <span style="color:#DD5138;">*</span>（可複選）</label>
                <div class="chk-row" id="recChRow"></div><span class="err" id="eCh"></span>
            </div>
            <div class="full">
                <label>填表人／部門 <span style="color:#DD5138;">*</span>
                    <span class="hint" id="fMakerFixed" style="font-weight:normal;display:none;">（填表人固定是您本人；身兼多職時可自行選擇要用哪個部門／職位填這張表）</span></label>
                <div class="pk-line">
                    <label style="font-weight:normal;">部門</label>
                    <select id="fMakerDept" style="width:200px;"></select>
                    <span id="fMakerWrap"><label style="font-weight:normal;">人員</label>
                        <select id="fMaker" style="width:200px;"></select></span>
                    <label style="font-weight:normal;">職位</label>
                    <select id="fIdentity" class="pk-grow"></select>
                    <span class="hint" id="fMakerShowMe"></span>
                </div>
                <div class="hint" id="signPreview" style="margin-top:4px;"></div>
                <span class="err" id="eIdentity"></span>
            </div>
        </div>

        <label style="display:block;font-size:12.5px;color:#5b3a1e;margin:10px 0 2px;font-weight:bold;">
            溝通問題／回覆內容 <span style="color:#DD5138;">*</span>
            <span class="hint" style="font-weight:normal;">（可依實際需求增列；末列按 <b>↓</b> 自動加一列、沒填東西的末列按 <b>↑</b> 自動移除）</span>
        </label>
        <table class="it-tbl">
            <thead><tr><th style="width:34px;">#</th><th>溝通問題</th><th>回覆內容</th><th style="width:104px;">追蹤</th></tr></thead>
            <tbody id="itemBody" data-eg-row-add="cmItemAdd" data-eg-row-del="cmItemDelLast"></tbody>
        </table>
        <div style="margin:4px 0 8px;">
            <button type="button" class="b-mini" onclick="cmItemAdd()"><i class="fa fa-plus"></i> 新增一列</button>
            <span class="err" id="eItems"></span>
            <span class="hint">需要後續行動的問題，存檔後可按該列的<b>「轉追蹤」</b>建立措施追蹤項目；當下就解決的不必轉。</span>
        </div>

        <div class="fld" style="margin-top:6px;">
            <label>備註（選填）</label>
            <textarea id="fRemark" maxlength="500" style="min-height:44px;"></textarea>
        </div>

        <!-- 附件（鐵律5：新增當下就能上傳，走 temp/active 暫存） -->
        <div style="margin-top:10px;border-top:1px dashed #E0CDA9;padding-top:8px;">
            <label style="font-size:12.5px;color:#5b3a1e;font-weight:bold;">佐證附件（選填）</label>
            <div class="hint" style="margin-bottom:4px;">email 截圖、會議照片、對方來文等。單檔 50MB 以內；新增中就可以先傳，按下儲存時自動歸到這張單。</div>
            <div class="pk-line" style="margin-bottom:4px;">
                <label>說明（選填）</label>
                <input type="text" id="fAttNote" class="pk-grow" maxlength="200"
                       data-eg-hint="例如：客訴 email 往來截圖；填了之後清單上會顯示這段說明，而不是原始檔名">
            </div>
            <input type="file" id="fAtt" multiple style="font-size:12.5px;">
            <div class="hint">說明會套用在<b>這一次選取的全部檔案</b>上；要給不同說明就分次上傳。上傳後仍可按「改說明」修改。</div>
            <div id="attList" style="margin-top:4px;"></div>
        </div>

        <div id="recSignArea" style="display:none;">
            <label style="display:block;font-size:12.5px;color:#5b3a1e;margin:12px 0 2px;font-weight:bold;">簽章</label>
            <div class="sign-wrap" id="signWrap"></div>
        </div>
    </div>
    <div class="cm-mft">
        <span id="recStatusHint" class="hint" style="float:left;padding-top:6px;"></span>
        <button data-close>關閉</button>
        <button id="btnRecPrint"><i class="fa fa-print"></i> 列印</button>
        <button id="btnRecSave" class="btn-warm"><i class="fa fa-save"></i> 儲存</button>
        <button id="btnRecSubmit" class="btn-warm"><i class="fa fa-paper-plane"></i> 儲存並送出確認</button>
    </div>
</div></div>

<!-- ======================= 附件清單（從列表的附件數點開，⑤） ======================= -->
<div class="cm-mask" id="attMask"><div class="cm-modal" style="width:620px;">
    <div class="cm-mhd"><span id="attTitle">佐證附件</span><span class="x" data-close>&times;</span></div>
    <div class="cm-mbd"><div id="attListView"></div></div>
    <div class="cm-mft"><button data-close>關閉</button></div>
</div></div>

<!-- ======================= 確認／退回 ======================= -->
<div class="cm-mask" id="signMask"><div class="cm-modal" style="width:560px;">
    <div class="cm-mhd"><span id="signTitle">確認</span><span class="x" data-close>&times;</span></div>
    <div class="cm-mbd">
        <div class="note-box" id="signInfo" style="margin-bottom:10px;"></div>
        <div class="fld">
            <label id="signNoteLb">意見（選填）</label>
            <textarea id="signNote" maxlength="500"></textarea><span class="err" id="eSignNote"></span>
        </div>
    </div>
    <div class="cm-mft">
        <button data-close>取消</button>
        <button id="btnSignReject" class="btn-danger" style="border-color:#C4442D;background:#DD5138;color:#fff;">
            <i class="fa fa-undo"></i> 退回（須填原因）</button>
        <button id="btnSignOk" class="btn-warm"><i class="fa fa-check"></i> 確認</button>
    </div>
</div></div>

<!-- ======================= 轉入追蹤表 ======================= -->
<div class="cm-mask" id="toTrackMask"><div class="cm-modal" style="width:620px;">
    <div class="cm-mhd"><span>轉入措施追蹤表</span><span class="x" data-close>&times;</span></div>
    <div class="cm-mbd">
        <div class="note-box" style="margin-bottom:10px;">
            追蹤表只列<b>需要後續行動</b>的項目（有負責人、有預計完成日）。當下就回覆完畢的問題留在溝通記錄表即可。
        </div>
        <div class="fld" style="margin-bottom:8px;"><label>反應內容（自動帶入溝通問題）</label>
            <textarea id="ttContent" readonly style="background:#F5EEE2;"></textarea></div>
        <div class="fgrid">
            <div class="full"><label>回應措施</label><textarea id="ttAction"></textarea></div>
            <div class="full"><label>負責人 <span style="color:#DD5138;">*</span>
                    <span class="hint" style="font-weight:normal;">（先選部門，再挑該部門底下的人；職稱依溝通日期回推當時的）</span></label>
                <div class="pk-line">
                    <select id="ttOwnerDept" style="width:200px;"></select>
                    <select id="ttOwner" class="pk-grow"></select>
                </div>
                <span class="err" id="eTtOwner"></span></div>
            <div><label>預計完成日 <span style="color:#DD5138;">*</span></label>
                <input type="date" id="ttDue"><span class="err" id="eTtDue"></span></div>
        </div>
    </div>
    <div class="cm-mft"><button data-close>取消</button>
        <button id="btnToTrackOk" class="btn-warm"><i class="fa fa-share"></i> 建立追蹤項目</button></div>
</div></div>

<!-- ======================= 追蹤項目編輯 ======================= -->
<div class="cm-mask" id="trackMask"><div class="cm-modal" style="width:720px;">
    <div class="cm-mhd"><span id="trackTitle">新增追蹤項目</span><span class="x" data-close>&times;</span></div>
    <div class="cm-mbd">
        <div class="fgrid">
            <div><label>利害關係者 <span style="color:#DD5138;">*</span></label>
                <input type="text" id="tkParty" maxlength="200"><span class="err" id="eTkParty"></span></div>
            <div><label>反應日期</label><input type="date" id="tkReact"></div>
            <div class="full"><label>反應內容 <span style="color:#DD5138;">*</span></label>
                <textarea id="tkContent"></textarea><span class="err" id="eTkContent"></span></div>
            <div class="full"><label>回應措施</label><textarea id="tkAction"></textarea></div>
            <div class="full"><label>負責人 <span style="color:#DD5138;">*</span>
                    <span class="hint" style="font-weight:normal;">（先選部門，再挑該部門底下的人）</span></label>
                <div class="pk-line">
                    <select id="tkOwnerDept" style="width:200px;"></select>
                    <select id="tkOwner" class="pk-grow"></select>
                </div>
                <span class="err" id="eTkOwner"></span></div>
            <div><label>預計完成日 <span id="tkDueStar" style="color:#DD5138;">*</span></label>
                <input type="date" id="tkDue"><span class="err" id="eTkDue"></span></div>
            <div class="full">
                <label style="font-weight:normal;display:inline-flex;align-items:center;gap:6px;">
                    <input type="checkbox" id="tkClosed"> <b>是否結案</b>
                    <span class="hint">勾選＝改善措施已確認有效並完成，下次審查即可移出清單。</span></label>
            </div>
            <div id="tkCloseWrap" style="display:none;"><label>結案日期</label><input type="date" id="tkCloseDate"></div>
            <div id="tkCloseNoteWrap" style="display:none;"><label>結案說明</label><input type="text" id="tkCloseNote" maxlength="500"></div>
        </div>
        <div id="tkSrc" class="hint" style="margin-top:8px;"></div>
    </div>
    <div class="cm-mft"><button data-close>取消</button>
        <button id="btnTrackDel" class="btn-danger" style="display:none;border-color:#C4442D;background:#DD5138;color:#fff;">
            <i class="fa fa-trash"></i> 刪除</button>
        <button id="btnTrackSave" class="btn-warm"><i class="fa fa-save"></i> 儲存</button></div>
</div></div>

<!-- ======================= 管制項目編輯 ======================= -->
<div class="cm-mask" id="ctrlMask"><div class="cm-modal" style="width:780px;">
    <div class="cm-mhd"><span id="ctrlTitle">新增管制項目</span><span class="x" data-close>&times;</span></div>
    <div class="cm-mbd">
        <div class="note-box" style="margin-bottom:10px;">
            管制表寫的是<b>常態性的溝通機制</b>（跟誰、多久一次、用什麼管道），<b>不寫單一次的溝通事件</b>；
            真的溝通了再按該列的「建立溝通記錄」開一張記錄表。
        </div>
        <div class="fgrid">
            <div class="full">
                <label>填表人／部門 <span style="color:#DD5138;">*</span>
                    <span class="hint" id="cfMakerFixed" style="font-weight:normal;display:none;">（一般使用者固定為自己，不可修改）</span></label>
                <div class="pk-line">
                    <select id="cfMakerDept" style="width:200px;display:none;"></select>
                    <select id="cfMaker" class="pk-grow" style="display:none;"></select>
                    <span class="hint" id="cfMakerShow"></span>
                </div>
            </div>

            <div class="full">
                <label>類別 <span style="color:#DD5138;">*</span></label>
                <div class="chk-row" id="cfKindRow"></div><span class="err" id="eCfKind"></span>
            </div>

            <div class="full">
                <label>利害關係人 <span style="color:#DD5138;">*</span>
                    <span class="hint" id="cfPartyLock" style="font-weight:normal;display:none;">（打字即時搜尋，選了就填在這一格）</span></label>
                <!-- 綁定的編號顯示在欄位「上方」，只有類別＝客戶／供應商時才出現 -->
                <div class="pk-bound" id="cfPartyBound" style="display:none;"></div>
                <div class="pk-ac">
                    <input type="text" id="cfParty" maxlength="200" autocomplete="off">
                    <div class="pk-ac-list" id="cfPartyList"></div>
                </div>
                <span class="err" id="eCfParty"></span>

                <div class="pk-box" id="cfSearchBox" style="display:none;">
                    <div class="hint">在上面的欄位直接打名稱或編號（例如 <b>歐克</b> 或 <b>.D001</b>）就會即時列出建議清單。
                        管制表只記「固定要跟哪一家溝通」，<b>不必挑到聯絡人是哪一位</b>——開溝通記錄表時才填。</div>
                </div>

                <div class="pk-box" id="cfEmpBox" style="display:none;">
                    <div class="pk-line">
                        <label>部門</label>
                        <select id="cfDept" style="width:220px;"></select>
                        <label>員工／職稱</label>
                        <select id="cfUser" class="pk-grow"></select>
                    </div>
                </div>

                <div class="pk-box" id="cfOtherBox" style="display:none;">
                    <div class="pk-line">
                        <label>類別說明 <span style="color:#DD5138;">*</span></label>
                        <input type="text" id="cfKindOther" class="pk-grow" maxlength="100"
                               data-eg-hint="例如：主管機關、認證機構、社區、股東">
                    </div>
                    <div class="hint">「其他」類別的利害關係人請直接在上方欄位手動填寫（例如：勞動部勞工保險局）。</div>
                </div>
                <div id="cfSummary"></div>
            </div>

            <div class="full"><label>溝通內容 <span style="color:#DD5138;">*</span></label>
                <textarea id="cfContent"></textarea><span class="err" id="eCfContent"></span></div>

            <div class="full"><label>溝通管道 <span style="color:#DD5138;">*</span>（可複選）</label>
                <div class="chk-row" id="cfChRow"></div><span class="err" id="eCfChannel"></span></div>

            <div class="full"><label>頻率 <span style="color:#DD5138;">*</span></label>
                <div class="freq-row">
                    每 <input type="number" id="cfFreqN" min="1" max="999" value="1">
                    <select id="cfFreqUnit"></select>
                    <input type="number" id="cfFreqTimes" min="1" max="999" value="1"> 次
                    <span class="pk-sum" id="cfFreqPreview"></span>
                </div>
                <span class="err" id="eCfFreq"></span></div>

            <!-- 提醒（⑪）：站內通知＋推播並行，所以不必綁手機或 Telegram 也收得到 -->
            <div class="full" style="border-top:1px dashed #E0CDA9;padding-top:8px;margin-top:2px;">
                <label style="display:inline-flex;align-items:center;gap:6px;font-weight:normal;">
                    <input type="checkbox" id="cfRemind"> <b>到期自動提醒</b>
                    <span class="hint">系統會在「下次應溝通日」前幾天自動發<b>站內通知</b>並推播，不必綁手機或 Telegram 也收得到。</span>
                </label>
            </div>
            <div><label>下次應溝通日 <span class="cfRemReq" style="color:#DD5138;display:none;">*</span></label>
                <input type="date" id="cfNextDue"><span class="err" id="eCfNextDue"></span></div>
            <div><label>提前幾天提醒</label>
                <div class="pk-line">
                    <input type="number" id="cfLead" min="0" max="365" value="3" style="width:80px;"> 天，
                    <input type="text" id="cfRemTime" maxlength="5" style="width:74px;" data-eg-hint="24 小時制，例如 09:00"> 發送
                </div>
                <span class="err" id="eCfRemTime"></span></div>
            <div class="full" id="cfTargetWrap" style="display:none;">
                <label>提醒對象 <span class="cfRemReq" style="color:#DD5138;display:none;">*</span></label>
                <div class="chips" id="cfTargetChips"></div>
                <div class="pk-line">
                    <select id="cfTargetUser" style="width:230px;" data-eg-filter="輸入姓名篩選…"></select>
                    <select id="cfTargetDept" style="width:230px;" data-eg-filter="輸入部門名稱篩選…"></select>
                </div>
                <div class="hint">選<b>部門</b>＝該部門<b>含子部門</b>的在職人員都會收到（設「資材課」時生管組的人也會收到）。</div>
                <span class="err" id="eCfTarget"></span></div>

            <div class="full"><label>備註（選填）</label><input type="text" id="cfRemark" maxlength="500"></div>
            <div><label>排序</label><input type="number" id="cfSort" value="0"></div>
        </div>
    </div>
    <div class="cm-mft"><button data-close>取消</button>
        <button id="btnCtrlDel" class="btn-danger" style="display:none;border-color:#C4442D;background:#DD5138;color:#fff;">
            <i class="fa fa-trash"></i> 刪除</button>
        <button id="btnCtrlSave" class="btn-warm"><i class="fa fa-save"></i> 儲存</button></div>
</div></div>

<!-- ======================= 管制項目 → 建立溝通記錄表（⑫） ======================= -->
<div class="cm-mask" id="c2rMask"><div class="cm-modal" style="width:620px;">
    <div class="cm-mhd"><span>由管制項目建立溝通記錄表</span><span class="x" data-close>&times;</span></div>
    <div class="cm-mbd">
        <div class="note-box" style="margin-bottom:10px;">
            把這個常態機制實際執行了一次，記成一張溝通記錄表。<b>溝通日期自動帶入今天</b>；
            管制項目上已綁定的對象不可修改（只有類別「其他」可以改），管道可以改。<b>同一筆可以重複建立</b>。
        </div>
        <div class="fgrid">
            <div class="full"><label>利害關係人</label>
                <input type="text" id="c2rParty" maxlength="200">
                <span class="hint" id="c2rPartyLock" style="display:none;">此對象由管制項目綁定，不可在這裡修改。</span>
                <span class="err" id="eC2rParty"></span></div>
            <div class="full" id="c2rKindOtherWrap" style="display:none;">
                <label>類別說明</label><input type="text" id="c2rKindOther" maxlength="100"></div>
            <div class="full"><label>溝通管道 <span style="color:#DD5138;">*</span>（可複選，可修改）</label>
                <div class="chk-row" id="c2rChRow"></div>
                <span class="err" id="eC2rCh"></span></div>
            <div class="full"><label>溝通內容（會帶成第一列溝通問題）</label>
                <textarea id="c2rContent" readonly style="background:#F5EEE2;"></textarea></div>
            <div class="full" id="c2rAdvWrap">
                <label style="display:inline-flex;align-items:center;gap:6px;font-weight:normal;">
                    <input type="checkbox" id="c2rAdv" checked> <b>同時把「下次應溝通日」往後推一期</b>
                </label>
                <div class="hint" id="c2rAdvHint"></div>
            </div>
        </div>
    </div>
    <div class="cm-mft"><button data-close>取消</button>
        <button id="btnC2rOk" class="btn-warm"><i class="fa fa-plus"></i> 建立並開啟</button></div>
</div></div>

<!-- ======================= 模組設定 ======================= -->
<div class="cm-mask" id="setMask"><div class="cm-modal" style="width:860px;">
    <div class="cm-mhd"><span>模組設定</span><span class="x" data-close>&times;</span></div>
    <div class="cm-mbd">
        <h4 style="color:#8A5A2B;border-bottom:2px solid #F7E0BD;padding-bottom:3px;margin:0 0 8px;font-size:15px;">
            一、AS 文件編號綁定</h4>
        <div class="hint" style="margin-bottom:6px;">綁定後，列印版的表頭會取該文件的表單名稱、頁尾右下角印文件編號；
            單筆的溝通記錄表會依<b>該單的溝通日期</b>回推當時生效的版次。</div>
        <table class="cm-table" style="margin-bottom:14px;">
            <thead><tr><th style="width:230px;">表單</th><th>目前綁定</th><th style="width:110px;">操作</th></tr></thead>
            <tbody id="setAsdocBody"></tbody>
        </table>

        <h4 style="color:#8A5A2B;border-bottom:2px solid #F7E0BD;padding-bottom:3px;margin:0 0 8px;font-size:15px;">
            二、簽章圖章模板</h4>
        <div class="fgrid" style="margin-bottom:14px;">
            <div class="full">
                <label>溝通記錄表的「總經理確認／部門主管確認」圖章樣式</label>
                <select id="setStampTpl" data-eg-filter="輸入模板名稱篩選…"></select>
                <div class="hint">未指定＝用系統預設回墨印。已上傳<b>掃描實體章</b>的人一律優先用實體章，這個設定只影響沒有實體章時自動產生的印章樣式。
                    模板本身請到「圖章管理 → 線上圖章設計」維護。</div>
            </div>
        </div>

        <h4 style="color:#8A5A2B;border-bottom:2px solid #F7E0BD;padding-bottom:3px;margin:0 0 8px;font-size:15px;">
            三、是否需要簽核</h4>
        <div class="fgrid" style="margin-bottom:14px;">
            <div class="full">
                <label style="display:inline-flex;align-items:center;gap:6px;font-weight:normal;">
                    <input type="checkbox" id="setNeedSign"> <b>溝通記錄表需要「部門主管確認」與「總經理確認」</b>
                </label>
                <div class="hint">
                    取消勾選＝<b>免簽核</b>：按下「送出」的當下系統自動完成兩關並結案，不會發任何待簽通知。<br>
                    免簽核時<b>簽章欄仍然會蓋章</b>（依 ai-rules/21：簽章日期＝該單溝通日期，兩關時間刻意錯開且不跨日），
                    簽核人取各關卡原本的合格簽核池第一位、池空才退回「最高核准人員」；
                    紀錄一樣寫進共用的 <b>approval_record</b>，在「列印與簽核紀錄」查得到（ai-rules/23）。<br>
                    下面「三」「四」兩節的設定<b>在免簽核時仍然有用</b>——那是用來決定自動簽核要蓋誰的章。
                </div>
            </div>
        </div>

        <h4 style="color:#8A5A2B;border-bottom:2px solid #F7E0BD;padding-bottom:3px;margin:0 0 8px;font-size:15px;">
            四、誰可以簽「部門主管確認」</h4>
        <div class="fgrid" style="margin-bottom:14px;">
            <div class="full">
                <label>解析方式</label>
                <div class="chk-row">
                    <label><input type="radio" name="mgrSrc" value="auto"> 依填表人的部門與職稱自動解析（建議）</label>
                    <label><input type="radio" name="mgrSrc" value="users"> 固定由指定人員簽</label>
                </div>
            </div>
            <div class="full" id="mgrAutoWrap">
                <label>哪些層級以上的主管才可簽章</label>
                <select id="setMgrRank" style="width:260px;"></select>
                <div class="hint">
                    取自「部門職稱設定」的<b>職稱階級</b>。選「三階主管」＝三階、二階、一階主管與最高決策者<b>都可以簽</b>。<br>
                    解析規則：在<b>填表人所屬部門</b>內找「職位編號小於填表人」且符合上述階級的人；
                    同部門找不到就<b>沿部門樹往上一層找</b>（例：品管組組長 → 品管課課長；生管組組長 → 資材課副理）。<br>
                    <b>往上只追到「課」級為止</b>（ai-rules/24 的全站規則）——課級以上的總經理室／董事長室是所有單位的共同上級、
                    不是誰的部門主管，而且總經理本來就要簽下面那一格，再抓上來會變成同一個人蓋兩格章。
                    追到課級仍找不到（＝填表人已是該課最高主管），該格<b>免簽</b>、直接送總經理確認。
                </div>
            </div>
            <div class="full" id="mgrUsersWrap" style="display:none;">
                <label>指定人員（其中任一人簽即可）</label>
                <div class="chips" id="mgrChips"></div>
                <select id="mgrPick" data-eg-filter="輸入姓名篩選…"></select>
            </div>
        </div>

        <h4 style="color:#8A5A2B;border-bottom:2px solid #F7E0BD;padding-bottom:3px;margin:0 0 8px;font-size:15px;">
            五、誰可以簽「總經理確認」</h4>
        <div class="fgrid">
            <div class="full">
                <label>解析方式</label>
                <div class="chk-row">
                    <label><input type="radio" name="gmSrc" value="top"> 組織角色綁定的「最高核准人員」（預設）</label>
                    <label><input type="radio" name="gmSrc" value="users"> 固定由指定人員簽</label>
                    <label><input type="radio" name="gmSrc" value="rank"> 某個職稱階級以上的人都可簽</label>
                </div>
                <div class="hint" id="gmTopHint"></div>
            </div>
            <div class="full" id="gmUsersWrap" style="display:none;">
                <label>指定人員（其中任一人簽即可）</label>
                <div class="chips" id="gmChips"></div>
                <select id="gmPick" data-eg-filter="輸入姓名篩選…"></select>
            </div>
            <div class="full" id="gmRankWrap" style="display:none;">
                <label>哪些層級以上的人可簽</label>
                <select id="setGmRank" style="width:260px;"></select>
            </div>
        </div>
    </div>
    <div class="cm-mft"><button data-close>取消</button>
        <button id="btnSetSave" class="btn-warm"><i class="fa fa-save"></i> 儲存設定</button></div>
</div></div>

<!-- ======================= 角色說明 ======================= -->
<div class="cm-mask" id="roleMask"><div class="cm-modal" style="width:620px;">
    <div class="cm-mhd"><span>角色權限說明</span><span class="x" data-close>&times;</span></div>
    <div class="cm-mbd help-doc" id="roleBody"></div>
    <div class="cm-mft"><button data-close>關閉</button></div>
</div></div>

<!-- ======================= 使用說明（鐵律7） ======================= -->
<div class="cm-mask" id="helpUseMask"><div class="cm-modal" style="width:880px;">
    <div class="cm-mhd"><span><i class="fa fa-question-circle"></i> 溝通管理　使用說明</span><span class="x" data-close>&times;</span></div>
    <div class="cm-mbd help-doc">
        <h4>這個模組在做什麼</h4>
        <p>把 <b>3-GM-01 溝通管理辦法</b>底下的三份表單做成一頁三分頁。三份表單是一個<b>由上到下過濾的漏斗</b>：</p>
        <ul>
            <li><b>溝通管制表（3-GM-01-03）</b>＝遊戲規則。寫的是「我們計畫好要跟誰溝通、多久一次」的<b>常態性機制</b>
                （例：每週與外包廠的產銷會議）。<b>不寫單一突發事件。</b></li>
            <li><b>溝通記錄表（3-GM-01-01）</b>＝事件發生當下的紀錄。所有溝通都可以記在這裡。<br>
                ・<b>當下就解決／純告知</b>（例：廠商問過年有沒有上班，回答有）→ 記在這裡就結束了。<br>
                ・<b>需要後續行動</b>（例：廠商反映加工前未提早反應問題，需內部檢討流程）→ 按該列的「轉追蹤」送進追蹤表。</li>
            <li><b>措施追蹤表（3-GM-01-02）</b>＝只盯還沒做完的事。只列有<b>預計完成日</b>、要指派<b>負責人</b>的項目；
                確認改善有效並完成後把「是否結案」設為是，下次審查就可以移出清單（預設只顯示未結案）。</li>
        </ul>

        <h4>操作步驟：溝通記錄表</h4>
        <ul>
            <li>①「新增溝通記錄」→ 填型態／溝通日期／類別／利害關係者／管道。
                <b>溝通日期是本單的業務日期</b>，單號、AS 文件版次、圖章日期都依它產生。</li>
            <li>②<b>「類別」會連動利害關係者的填法</b>：
                <br>・<b>客戶／供應商</b>：直接在「利害關係者公司/代表人」欄位<b>打名稱或編號</b>（例 <b>歐克</b> 或 <b>.D001</b>），
                    會<b>即時跳出建議清單</b>（不用按搜尋），點一筆就填回同一個欄位，
                    <b>綁定到的編號顯示在該欄位上方</b>。代表人可從對方登錄的聯絡人挑，也可以自己手寫。
                    <br>　<span style="color:#DD5138;">注意：手動改動欄位文字＝自動解除綁定</span>，
                    必須重新從清單挑一筆才存得進去——這是為了避免「畫面上寫 A、實際綁到 B」。
                <br>・<b>員工</b>：先選<b>部門</b>再挑人；職稱是<b>依溝通日期回推當時的</b>，
                    <b>主要職務與兼任職務都會列出來</b>（同一個人掛兩個職稱就出現兩列，那是兩種身分）。
                <br>・<b>其他</b>：填「類別說明」（例：主管機關）並自行輸入對象名稱。</li>
            <li>③「填表人／部門」是<b>部門 → 人員 → 職位</b>三段式：
                <br>・<b>溝通管理員</b>：三段都可以選，可以代其他人建單（先選部門找人，再選那個人在該部門的職位）。
                <br>・<b>一般使用者</b>：<b>填表人自動就是您本人、不可修改</b>（「人員」那一段不會出現）；
                    但<b>「部門」只會列出您自己有職務的部門</b>、「職位」列出您在該部門的職稱，
                    所以<b>身兼多部門多職位的人可以自己選要用哪一個身分填這張表</b>（預設停在主要職務）。
                <br>選好之後下方會即時顯示<b>這張單會送給誰確認</b>——不同身分的部門主管不一樣，換身分簽核人就會跟著換。</li>
            <li>④ 逐列填溝通問題與回覆內容。末列按 <b>↓</b> 自動加一列、沒填東西的末列按 <b>↑</b> 自動移除。</li>
            <li>⑤ 需要的話上傳佐證附件（新增中就可以先傳，按下儲存時自動歸到這張單）。
                <b>「說明」欄是選填</b>：填了之後清單上就顯示這段說明而不是原始檔名
                （掃描檔名多半是一串日期流水號，看不出是什麼），原始檔名仍留在提示與下載檔名上。
                說明會套用在<b>這一次選取的全部檔案</b>，要給不同說明就分次上傳，事後也可以按「改說明」修改。</li>
            <li>⑥ 按「儲存並送出確認」。送出後<b>草稿鎖定不能再改</b>，被退回才會回到可編輯狀態。</li>
            <li>清單上的<b>「附件」欄會顯示附件數量</b>，點一下就能直接檢視／下載，不必先開整張單。</li>
        </ul>

        <h4>簽核怎麼跑</h4>
        <ul>
            <li>順序是<b>填表人送出 → 部門主管確認 → 總經理確認 → 結案</b>。</li>
            <li><b>部門主管確認</b>：系統在<b>填表人所屬部門</b>裡找「職位編號小於填表人」且<b>階級符合設定門檻</b>的人；
                同部門找不到就<b>沿部門樹往上一層找</b>（例：品管組組長 → 品管課課長；生管組組長 → 資材課副理）。
                <b>往上只追到「課」級為止</b>：課級以上的總經理室／董事長室是所有單位的共同上級、不是誰的部門主管，
                而且總經理本來就要簽下面那一格。所以<b>課級最高主管（例：品管課課長）自己開的單，這一格免簽</b>，
                直接送總經理確認（對應紙本的「若由主管填寫則此格免簽」）。</li>
            <li>該關卡的<b>名單內任一人簽了就算數</b>；名單內的人請假並設有代理人時，<b>代理人也可以代簽</b>，圖章右下角會自動加「代」字。</li>
            <li><b>退回一定要填原因</b>，退回後單據回到草稿、填表人可以修改後重新送出。</li>
            <li>簽章日期一律用<b>該單的溝通日期</b>，不是按下確認的那一天。</li>
            <li><b>整個模組可以設定成「免簽核」</b>（模組設定 → 二、是否需要簽核，溝通管理員）：
                取消勾選後，按下送出的當下系統就<b>自動完成兩格確認並結案</b>，不會發任何待簽通知。
                免簽核時<b>簽章欄照樣蓋章</b>（簽章日期仍是該單溝通日期、兩關時間會刻意錯開且不跨日），
                簽核人取各關卡原本的合格簽核池第一位、池空才退回「最高核准人員」；
                紀錄一樣寫進共用的 approval_record，在「列印與簽核紀錄」查得到。
                所以<b>「誰可以簽」那兩節在免簽核時仍然有用</b>——那是用來決定自動簽核要蓋誰的章。</li>
        </ul>

        <h4>溝通管制表：頻率、自動提醒、一鍵開記錄表</h4>
        <ul>
            <li><b>填表人／利害關係人／溝通管道的填法與溝通記錄表完全一樣</b>（類別連動、打字即時搜尋、綁定編號顯示在上方）。
                差別只有一個：管制表<b>不必挑到聯絡人是哪一位</b>——那是實際溝通、開記錄表時才填的。</li>
            <li><b>頻率固定填成「每 ? [天/週/月/半年/年] ? 次」</b>（例：每 2 週 1 次），輸入時下方會即時預覽最後會顯示成什麼。</li>
            <li><b>下次應溝通日</b>＝這個常態機制下一次該執行的日期，也是自動提醒的基準。</li>
            <li><b>到期自動提醒</b>：勾起來之後，系統會在「下次應溝通日<b>往前推 N 天</b>」那一天的指定時間，
                自動通知您指定的對象。
                <br>・提醒<b>一定會發站內通知</b>（畫面右上角的通知鈴），<b>不必綁手機或 Telegram 也收得到</b>；
                    有訂閱推播或綁了 Telegram 的人會另外再收到一則。
                <br>・提醒對象可以指定<b>人員</b>，也可以指定<b>部門</b>——選部門＝該部門<b>含子部門</b>的在職人員都會收到
                    （設「資材課」時生管組的人也會收到）。
                <br>・同一期<b>只會提醒一次</b>；改了「下次應溝通日」就視為新的一期，會重新提醒。
                <br>・系統沒有工作排程器，提醒是<b>有人在使用系統時順路檢查</b>的。
                    所以半夜到期的提醒會等到隔天有人開任何頁面時補發，<b>不會漏掉、只是可能晚一點</b>。</li>
            <li><b>「建立溝通記錄」按鈕</b>：實際執行了一次就按它，會依這個機制<b>自動開一張溝通記錄表</b>
                （溝通日期＝今天，溝通內容帶成第一列溝通問題）。
                <br>・管制項目上<b>已綁定的對象不可修改</b>，只有類別是「其他」的才能在這裡改；<b>溝通管道可以改</b>。
                <br>・預設會勾「同時把下次應溝通日往後推一期」，跳窗上會先告訴您會推到哪一天；不想動排程就取消勾選。
                <br>・<b>同一筆管制項目可以重複建立</b>記錄表，系統不會擋。</li>
        </ul>

        <h4>列印</h4>
        <ul>
            <li>三份表單都可列印，版面依公司列印標準：大標題＝公司全名、表頭＝綁定 AS 文件的表單名稱、
                <b>文件編號印在頁尾右下角</b>、超過一頁才印頁碼。</li>
            <li>溝通記錄表是<b>單筆列印</b>，AS 版次會依該單的<b>溝通日期</b>回推當時生效的版次（不是一律印最新版）。
                追蹤表與管制表是清單型，印的是<b>目前畫面上篩選的結果</b>與現行最新版次。</li>
            <li><b>按下列印就會留下列印紀錄</b>（時間／列印人／電腦），可在「列印與簽核紀錄」查詢。
                瀏覽器不會回報您在列印對話框按了確定還是取消，所以<b>按取消也會留一筆</b>，這是刻意的。</li>
        </ul>

        <h4>常見疑問</h4>
        <ul>
            <li><b>Q：為什麼我送出後直接跳到總經理確認？</b><br>
                A：代表系統從您的部門一路往上層找都沒有職位編號比您小、且符合階級門檻的主管，也就是您已經是最高主管，該格免簽。</li>
            <li><b>Q：主管抓錯人怎麼辦？</b><br>
                A：解析依據是「部門職稱設定」裡的<b>職位編號</b>與<b>職稱階級</b>，以及員工的部門職稱對應。
                先確認那兩處資料，再看模組設定裡的階級門檻是不是設得太嚴。也可以改成「固定由指定人員簽」。</li>
            <li><b>Q：轉過追蹤表的項目可以再轉一次嗎？</b><br>
                A：不行，同一列只會對應一個追蹤項目，避免追蹤表長出重複資料。追蹤項目被管理員刪除後才能重新轉入。</li>
            <li><b>Q：附件存在哪裡？</b><br>
                A：AS9100 根目錄底下的「溝通管理」資料夾，由系統自動建立。資料庫只存檔名，所以日後整批搬家只要改設定值。</li>
            <li><b>Q：客戶明明打對了，為什麼按儲存說「要綁到客戶編號才存得進去」？</b><br>
                A：因為<b>只有從跳出的建議清單點選過</b>才算綁定。自己把字打完但沒有點清單、
                或選完之後又手動改了欄位裡的字，都會解除綁定（欄位上方會變回「尚未綁定」）。
                請重新打關鍵字，從清單挑一筆。</li>
            <li><b>Q：管制表設了提醒，時間到了卻沒收到？</b><br>
                A：依序確認 ①「到期自動提醒」有勾 ②「下次應溝通日」有填 ③ 提醒對象有指定
                ④ 這一期是不是已經提醒過了（同一期只發一次，改過下次應溝通日才會重新發）
                ⑤ 提醒是有人在使用系統時順路檢查的，半夜到期會等到隔天有人開頁面時補發。</li>
            <li><b>Q：追蹤表的負責人下拉為什麼是空的？</b><br>
                A：負責人改成<b>先選部門、再挑該部門底下的人</b>，沒先選部門時人員下拉本來就是空的。</li>
        </ul>

        <h4>設定入口</h4>
        <ul>
            <li><b>模組設定</b>（畫面左上，溝通管理員）：三份表單的 AS 文件綁定、簽章圖章模板、
                <b>是否需要簽核</b>、兩個簽章格分別由誰簽（含「哪些層級以上的主管才可簽章」）。</li>
            <li><b>每一筆管制項目的提醒時間與提醒對象</b>：管制表分頁 → 該列「編輯」→ 到期自動提醒。</li>
            <li><b>職位編號與職稱階級</b>：管理者 →「部門職稱設定」。</li>
            <li><b>最高核准人員</b>：管理者 →「組織角色綁定設定」（本模組不寫死人名）。</li>
            <li><b>圖章模板</b>：「圖章管理 → 線上圖章設計」。</li>
        </ul>

        <h4>權限角色</h4>
        <div id="helpRoleBody"></div>
    </div>
    <div class="cm-mft"><button data-close>關閉</button></div>
</div></div>

<script src="../../resource/js/jquery.min.js"></script>
<script src="../../resource/js/bootstrap.min.js"></script>
<script src="../../resource/js/fastclick.js"></script>
<script src="../../resource/js/nprogress.js"></script>
<script src="../../resource/js/custom.min.js"></script>
<script src="../../resource/js/eg_stamp.js?v=<?= @filemtime(__DIR__.'/../../resource/js/eg_stamp.js') ?>"></script>
<script src="../../resource/js/eg_stamp_tpl.js?v=<?= @filemtime(__DIR__.'/../../resource/js/eg_stamp_tpl.js') ?>"></script>
<script src="../../resource/js/eg_date_fmt.js?v=<?= @filemtime(__DIR__.'/../../resource/js/eg_date_fmt.js') ?>"></script>
<script src="../../resource/js/eg_asdoc_picker.js?v=<?= @filemtime(__DIR__.'/../../resource/js/eg_asdoc_picker.js') ?>"></script>
<script src="../../resource/js/eg_print_log.js?v=<?= @filemtime(__DIR__.'/../../resource/js/eg_print_log.js') ?>"></script>
<script src="../../resource/js/eg_input_rules.js?v=<?= @filemtime(__DIR__.'/../../resource/js/eg_input_rules.js') ?>"></script>
<script>
$(document).ready(function(){ $('#sidebar-menu').css('visibility','visible'); });

var API  = '../../src/store/CommMgmt_API.php';
var META = null, CSRF = '', TODAY = '';
var REC = null, RECITEMS = [], ATTACHES = [], TEMPKEY = '';
var PAGE = {rec:1, track:1, ctrl:1}, PER = {rec:20, track:20, ctrl:20};
var SIGNCTX = null, TTCTX = null;
var MGRUSERS = [], GMUSERS = [];

function esc(s){ return $('<div>').text(s == null ? '' : s).html(); }
/* 日期顯示一律 YYYY.MM.DD（ai-rules/20）；egFmtDate() 是唯一實作，本頁只包一層顯示用 */
function dispDate(s){ try { return window.egFmtDate ? egFmtDate(s) : (s || ''); } catch(e){ return s || ''; } }
function openMask(id){ $('#' + id).addClass('on'); }
function closeMask(id){ $('#' + id).removeClass('on'); }
$(document).on('click', '.cm-mask [data-close]', function(){ $(this).closest('.cm-mask').removeClass('on'); });
$(document).on('click', '.cm-mask', function(e){ if (e.target === this) $(this).removeClass('on'); });
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
    $.post(API, data, function(res){ NProgress.done(); if (res && res.ok) cb(res); }, 'json')
     .fail(function(){ NProgress.done(); });
}
function get(data, cb){
    NProgress.start();
    $.getJSON(API, data, function(res){ NProgress.done(); if (res && res.ok) cb(res); })
     .fail(function(){ NProgress.done(); });
}
function clearErr(box){ $(box).find('.err').text(''); $(box).find('.bad').removeClass('bad'); }
function setErr(id, el, msg){ $('#' + id).text(msg); if (el) $(el).addClass('bad'); }
/* 打字即時查詢用的去抖動：停下來 wait 毫秒才真的送一次，不會每敲一個字就打一次 API。
   （使用者要求「模糊篩選要即時出選單，不要還得按搜尋」） */
function debounce(fn, wait){
    var t = null;
    return function(){
        var self = this, args = arguments;
        if (t) clearTimeout(t);
        t = setTimeout(function(){ t = null; fn.apply(self, args); }, wait || 250);
    };
}

/* ================= 共用：部門 → 人員兩段式挑選（ai-rules/08 第五節＋ai-rules/22） =================
   全公司幾十個人擠在一個下拉裡，使用者要用眼睛找；改成先選部門再挑人。
   職稱一律依**該單的業務日期**回推當時的，主職與兼任都列（同一人在同一部門掛兩個職稱＝兩列，
   那是兩種身分，不可合併）。

   **這些資料一律放在 PEOPLECACHE 這個模組變數裡，絕對不可掛在 <option> 的 jQuery data() 上**——
   打字篩選（eg_input_rules.js 規則7）會用 innerHTML 整批重畫選項，掛在 option 上的資料會整個消失，
   症狀是「篩選過一次之後就抓不到人了」，而且只有實際打字篩選才看得到。 */
var PEOPLECACHE = {};      // { selectId: [rows...] }
var PARTYCACHE  = {};      // { selectId: [rows...] } 客戶／供應商搜尋結果
var CONTACTCACHE = {};     // { selectId: [rows...] } 聯絡人

function fillDeptSel(sel, val, placeholder){
    var s = $(sel).empty().append($('<option>').val('').text(placeholder || '請選擇部門…'));
    (META.depts || []).forEach(function(d){
        var pad = new Array(Math.max(0, (+d.level || 1) - 1) + 1).join('　');
        s.append($('<option>').val(d.id).text(pad + d.name));
    });
    s.val(val ? String(val) : '');
}
function personLabel(p){
    return p.name + (p.pos_name ? '（' + p.pos_name + '）' : '');
}
/** 載入某部門的人員到下拉；keepUid 有值且仍在名單內就沿用選取。cb(rows) 於填完後呼叫。 */
function loadDeptPeople(deptId, userSel, date, keepUid, cb){
    var s = $(userSel);
    PEOPLECACHE[s.attr('id')] = [];
    if (!+deptId){ s.empty().append($('<option>').val('').text('請先選擇部門')); if (cb) cb([]); return; }
    s.empty().append($('<option>').val('').text('載入中…'));
    get({action:'dept_people', dept_id:deptId, date:date || TODAY}, function(res){
        var rows = res.rows || [];
        PEOPLECACHE[s.attr('id')] = rows;
        s.empty().append($('<option>').val('').text(rows.length ? '請選擇人員…' : '（該部門在此日期查無在職人員）'));
        rows.forEach(function(p, i){
            // value 用陣列索引而不是 user_id：同一個人在同一部門可能有兩個職稱＝兩列，user_id 會撞號
            s.append($('<option>').val(i).text(personLabel(p)));
        });
        if (keepUid){
            for (var i = 0; i < rows.length; i++) if (+rows[i].id === +keepUid){ s.val(String(i)); break; }
        }
        if (cb) cb(rows);
    });
}
/** 取目前選到的人（回 null＝沒選） */
function pickedPerson(userSel){
    var s = $(userSel), rows = PEOPLECACHE[s.attr('id')] || [], v = s.val();
    return (v === '' || v == null) ? null : (rows[+v] || null);
}

/* ================= 共用：單一欄位的模糊搜尋自動完成 =================
   使用者要求：不要「關鍵字欄＋選擇對象下拉」兩個欄位重複，
   直接在對象欄位裡打字就即時列出建議清單，選了就填回同一個欄位，
   綁定到的編號顯示在該欄位「上方」（只有客戶／供應商才有編號）。

   一改字就視同解除綁定——不解除的話會出現「畫面上寫 A、實際還綁著 B」，
   而且完全看不出來；後端 rec_save/ctrl_save 也會用同一批資料再核對一次（鐵律8）。

   建議清單刻意用 position:fixed 由 JS 定位：跳窗的 .cm-mbd 是 overflow-y:auto 的捲動容器，
   absolute 會在捲動時被裁掉（這個專案在 review_form 已經踩過一次）。 */
var ACS = {};        // { inputId: {rows:[], idx:-1, kindFn:fn, onPick:fn, listSel:'#..'} }

function acSetup(inputSel, listSel, kindFn, onPick){
    var $in = $(inputSel), key = $in.attr('id');
    ACS[key] = {rows:[], idx:-1, kindFn:kindFn, onPick:onPick, listSel:listSel, inputSel:inputSel};
    var search = debounce(function(){ acSearch(key); }, 250);
    $in.on('input', function(){
        ACS[key].onPick(null);          // 手動改字＝解除綁定
        search();
    });
    $in.on('focus', function(){ acSearch(key); });
    $in.on('blur', function(){ setTimeout(function(){ acHide(key); }, 180); });   // 讓 click 先跑完
    $in.on('keydown', function(e){
        var st = ACS[key];
        if ($(st.listSel).is(':visible') && st.rows.length){
            if (e.key === 'ArrowDown'){ e.preventDefault(); e.stopPropagation(); acMove(key, 1); return; }
            if (e.key === 'ArrowUp'){ e.preventDefault(); e.stopPropagation(); acMove(key, -1); return; }
            if (e.key === 'Enter' && st.idx >= 0){ e.preventDefault(); e.stopPropagation(); acPick(key, st.idx); return; }
            if (e.key === 'Escape'){ acHide(key); return; }
        }
    });
}
function acSearch(key){
    var st = ACS[key];
    if (!st) return;
    var kind = st.kindFn();
    if (kind !== 'customer' && kind !== 'supplier'){ acHide(key); return; }
    get({action:'party_search', kind:kind, kw:$(st.inputSel).val() || ''}, function(res){
        st.rows = res.rows || [];
        st.idx = -1;
        acRender(key);
    });
}
function acRender(key){
    var st = ACS[key], $l = $(st.listSel).empty();
    if (!st.rows.length){
        $l.html('<div class="pk-ac-empty">查無符合的資料，請換個關鍵字</div>');
    } else {
        st.rows.forEach(function(x, i){
            $l.append('<div class="pk-ac-item" data-i="' + i + '"><span class="id">' + esc(x.id) + '</span>'
                + esc(x.full_name || x.name) + '</div>');
        });
    }
    acPosition(key);
    $l.show();
    $l.find('.pk-ac-item').on('mousedown', function(e){ e.preventDefault(); acPick(key, +$(this).data('i')); });
}
function acPosition(key){
    var st = ACS[key], el = $(st.inputSel)[0];
    if (!el) return;
    var r = el.getBoundingClientRect();
    $(st.listSel).css({left:r.left + 'px', top:r.bottom + 'px', width:r.width + 'px'});
}
function acMove(key, d){
    var st = ACS[key];
    st.idx = Math.max(0, Math.min(st.rows.length - 1, st.idx + d));
    var $items = $(st.listSel).find('.pk-ac-item').removeClass('on');
    var $on = $items.eq(st.idx).addClass('on');
    if ($on.length){
        var l = $(st.listSel)[0], o = $on[0];
        if (o.offsetTop < l.scrollTop) l.scrollTop = o.offsetTop;
        else if (o.offsetTop + o.offsetHeight > l.scrollTop + l.clientHeight) l.scrollTop = o.offsetTop + o.offsetHeight - l.clientHeight;
    }
}
function acPick(key, i){
    var st = ACS[key], x = st.rows[i];
    if (!x) return;
    $(st.inputSel).val(x.full_name || x.name);   // 選了就直接顯示在同一個欄位內
    st.onPick(x);
    acHide(key);
}
function acHide(key){ var st = ACS[key]; if (st) $(st.listSel).hide(); }
/** 畫面捲動／視窗縮放時，fixed 清單要跟著輸入框走，否則會浮在錯的位置 */
$(window).on('scroll resize', function(){ for (var k in ACS) if ($(ACS[k].listSel).is(':visible')) acPosition(k); });
$('.cm-mbd').on('scroll', function(){ for (var k in ACS) if ($(ACS[k].listSel).is(':visible')) acPosition(k); });
/** 綁定編號的顯示（欄位上方）；沒綁到時明白說「尚未綁定」，不要留白讓人以為綁好了 */
function acBoundHtml(boundSel, kind, hit){
    var lb = (kind === 'customer' ? '客戶編號' : '廠商編號');
    $(boundSel).show().html(hit
        ? '<span class="tag">已綁定　' + esc(lb) + '：<b>' + esc(hit.id) + '</b></span>'
        : '<span class="no">尚未綁定' + esc(lb) + '　請在下方欄位打名稱或編號，再從清單挑一筆</span>');
}

/* ================= 分頁 ================= */
/* 切換分頁＝所有分頁一起重新整理（點開即刷新鐵則，ai-rules/08 第六節）：
   別人可能剛把某張單簽掉或把追蹤項目結案，停在舊快取會讓人對著過期狀態操作。 */
$('#mainTabs .cm-tab').on('click', function(){
    var t = $(this).data('tab');
    $('#mainTabs .cm-tab').removeClass('on'); $(this).addClass('on');
    $('.cm-pane').removeClass('on');
    $('#pane' + t.charAt(0).toUpperCase() + t.slice(1)).addClass('on');
    loadRec(); loadTrack(); loadCtrl();
});

/* ================= 初始化 ================= */
function boot(){
    get({action:'meta'}, function(res){
        META = res; CSRF = res.csrf; TODAY = res.today;
        window.__ownCompany = res.company;                 // eg_stamp.js 章面上半格印的公司全名
        $('#btnSetting').toggle(!!res.perms.canAdmin);
        // ⑧ 免簽核時按鈕文案要跟著改，不然使用者會以為還會送給誰簽
        $('#btnRecSubmit').html(res.need_sign
            ? '<i class="fa fa-paper-plane"></i> 儲存並送出確認'
            : '<i class="fa fa-check-circle"></i> 儲存並送出（免簽核・自動完成）');
        var s = $('#recStatus'); $.each(res.status, function(k, v){ s.append($('<option>').val(k).text(v)); });
        var k = $('#recKind');   $.each(res.kinds,  function(kk, v){ k.append($('<option>').val(kk).text(v)); });
        renderAsdocHint();
        renderRoleHelp();
        loadRec(); loadTrack(); loadCtrl();
        openFromUrl();
    });
}
/* 通知點進來時直接開到那張單（路由在 views/partPage/sideAndTopBarMenu.html）：
   ?sign=rec_id ＝待確認通知，自動跳出確認/退回跳窗；?rec=rec_id ＝結果通知，開單據明細 */
function openFromUrl(){
    var q = {};
    location.search.replace(/^\?/, '').split('&').forEach(function(kv){
        if (!kv) return;
        var p = kv.split('=');
        q[decodeURIComponent(p[0])] = decodeURIComponent(p[1] || '');
    });
    if (+q.sign) openSign(+q.sign);
    else if (+q.rec) openRec(+q.rec);
    // 溝通管制表的到期提醒點進來：切到管制表分頁（?ctrl=N 只用來標示是哪一筆，不自動開編輯窗，
    // 使用者多半是要按「建立溝通記錄」而不是改設定）
    else if (q.tab === 'ctrl') $('#mainTabs .cm-tab[data-tab="ctrl"]').trigger('click');
}
function renderAsdocHint(){
    var out = [], miss = [];
    $.each(META.asdoc, function(k, v){
        if (v.doc) out.push(esc(v.doc.doc_no) + '（' + esc(v.label) + '）');
        else miss.push(esc(v.label) + '｜建議 ' + esc(v.fallback));
    });
    var h = out.length ? 'AS 文件：' + out.join('　') : '';
    if (miss.length) h += (h ? '　' : '') + '<span style="color:#DD5138;">尚未綁定：' + miss.join('、')
        + (META.perms.canAdmin ? '（請按「模組設定」綁定，列印版才會有表單名稱與文件編號）' : '（請洽溝通管理員設定）') + '</span>';
    $('#asdocHint').html(h);
}
function renderRoleHelp(){
    /* 鐵律4：角色說明一律查目前實際資料組出，不放一份寫死的清單（改名/刪除後才不會顯示舊內容） */
    var h = '<ul>'
      + '<li><b>一般員工（不需指派任何角色）</b>：建立／編輯／送出<b>自己的</b>溝通記錄表，'
      + '確認指派給自己的那一關，維護追蹤表與管制表項目。</li>'
      + '<li><b>溝通紀錄檢閱（cm_view）</b>：唯讀查看<b>全部</b>溝通記錄表。</li>'
      + '<li><b>溝通管理員（cm_admin）</b>：檢閱＋代其他人建單、刪除溝通記錄／追蹤項目／管制項目、模組設定'
      + '（AS 文件綁定、簽章圖章模板、兩個簽章格由誰簽）。</li>'
      + '<li><b>管理者</b>：固定擁有全部權限。</li></ul>'
      + '<div class="hint">目前登入身分：<b>' + esc(META.me.name) + '</b>，角色：<b>' + esc(META.role_label) + '</b>。'
      + '角色指派請至「使用者權限設定」頁。</div>';
    $('#roleBody, #helpRoleBody').html(h);
}
$('#btnPageHelp').on('click', function(){ openMask('helpUseMask'); });
$('#btnRoleHelp').on('click', function(){ openMask('roleMask'); });

/* ================= 分頁列 ================= */
function renderPager(el, total, page, per, onGo, onPer){
    var pages = Math.max(1, Math.ceil(total / per));
    if (page > pages) page = pages;
    var h = '<span>共 ' + total + ' 筆</span>'
          + '<select class="pp">' + [5,10,20,50].map(function(n){
                return '<option value="' + n + '"' + (n === per ? ' selected' : '') + '>' + n + ' 筆/頁</option>'; }).join('') + '</select>'
          + '<button class="pg" data-p="1"' + (page <= 1 ? ' disabled' : '') + '>«</button>'
          + '<button class="pg" data-p="' + (page - 1) + '"' + (page <= 1 ? ' disabled' : '') + '>‹</button>';
    var st = Math.max(1, page - 2), en = Math.min(pages, st + 4);
    st = Math.max(1, en - 4);
    for (var i = st; i <= en; i++) h += '<button class="pg' + (i === page ? ' on' : '') + '" data-p="' + i + '">' + i + '</button>';
    h += '<button class="pg" data-p="' + (page + 1) + '"' + (page >= pages ? ' disabled' : '') + '>›</button>'
       + '<button class="pg" data-p="' + pages + '"' + (page >= pages ? ' disabled' : '') + '>»</button>';
    $(el).html(h);
    $(el).find('.pg').on('click', function(){ if (!this.disabled) onGo(parseInt($(this).data('p'), 10)); });
    $(el).find('.pp').on('change', function(){ onPer(parseInt(this.value, 10)); });
}

/* ================= 分頁一：溝通記錄表 ================= */
function recFilters(){
    return {action:'rec_list', page:PAGE.rec, per:PER.rec,
        status:$('#recStatus').val(), kind:$('#recKind').val(),
        from:$('#recFrom').val(), to:$('#recTo').val(),
        mine:$('#recMine').is(':checked') ? 1 : 0, kw:$('#recKw').val()};
}
function loadRec(){
    get(recFilters(), function(res){
        var b = $('#recBody').empty();
        RECATT = {};
        if (!res.rows.length){ b.html('<tr><td colspan="11" class="cm-empty">沒有符合條件的溝通記錄</td></tr>'); }
        res.rows.forEach(function(r){
            // 清單上就看得到附件數，點一下開跳窗直接檢視／下載（使用者要求⑤）
            RECATT[r.rec_id] = r.attaches || [];
            var nAtt = (r.attaches || []).length;
            var attCell = nAtt
                ? '<button class="b-mini" onclick="openAttList(' + r.rec_id + ')" title="點開檢視附件">'
                  + '<i class="fa fa-paperclip"></i> ' + nAtt + '</button>'
                : '<span class="hint">－</span>';
            var chs = [];
            $.each(META.channels, function(k, v){ if (+r['ch_' + k]) chs.push(k === 'other' ? (r.ch_other_text || v) : v); });
            var kind = META.kinds[r.party_kind] || '';
            if (r.party_kind === 'other' && r.party_kind_other) kind = r.party_kind_other;
            var stCls = 'st-' + r.status;
            var stTxt = META.status[r.status] || r.status;
            if (r.status === 'draft' && r.reject_note){ stCls = 'st-rej'; stTxt = '已退回'; }
            var ops = '<button class="b-mini" onclick="openRec(' + r.rec_id + ')"><i class="fa fa-folder-open-o"></i> 開啟</button>';
            if (r.can_sign) ops += '<button class="b-mini warm" onclick="openSign(' + r.rec_id + ')"><i class="fa fa-check"></i> 確認</button>';
            ops += '<button class="b-mini" onclick="printRecord(' + r.rec_id + ')"><i class="fa fa-print"></i></button>';
            if (r.can_edit) ops += '<button class="b-mini danger" onclick="delRec(' + r.rec_id + ')"><i class="fa fa-trash"></i></button>';
            b.append('<tr>'
                + '<td>' + esc(r.rec_no) + '</td>'
                + '<td>' + dispDate(r.comm_date) + '</td>'
                + '<td>' + esc(META.types[r.comm_type] || '') + '</td>'
                + '<td>' + esc(kind) + '</td>'
                + '<td class="l wrap">' + esc(r.party_name) + '</td>'
                + '<td class="l">' + esc(r.maker_name) + '<br><span class="hint">' + esc(r.maker_dept_name) + '　' + esc(r.maker_pos_name) + '</span></td>'
                + '<td>' + esc(chs.join('、')) + '</td>'
                + '<td>' + (r.items ? r.items.length : 0) + '</td>'
                + '<td>' + attCell + '</td>'
                + '<td><span class="st ' + stCls + '">' + esc(stTxt) + '</span>'
                + (+r.mgr_skip ? '<br><span class="hint">主管格免簽</span>' : '') + '</td>'
                + '<td>' + ops + '</td></tr>');
        });
        renderPager('#recPager', res.total, res.page, res.per,
            function(p){ PAGE.rec = p; loadRec(); },
            function(n){ PER.rec = n; PAGE.rec = 1; loadRec(); });
    });
}
$('#btnRecSearch').on('click', function(){ PAGE.rec = 1; loadRec(); });
$('#recKw').on('keydown', function(e){ if (e.key === 'Enter'){ PAGE.rec = 1; loadRec(); } });
$('#recStatus,#recKind,#recFrom,#recTo,#recMine').on('change', function(){ PAGE.rec = 1; loadRec(); });

/* ---- 可增列表格（共用檔 eg_input_rules.js 呼叫這兩支） ---- */
function cmItemAdd(){ RECITEMS.push({question:'', reply:'', track_id:0, item_id:0}); renderItems(); }
function cmItemDelLast(){ if (RECITEMS.length > 1){ RECITEMS.pop(); renderItems(); } }
function syncItemsFromDom(){
    $('#itemBody tr').each(function(i){
        if (!RECITEMS[i]) return;
        RECITEMS[i].question = $(this).find('.iq').val() || '';
        RECITEMS[i].reply    = $(this).find('.ir').val() || '';
    });
}
function renderItems(){
    var b = $('#itemBody').empty(), ro = REC && !REC.can_edit;
    RECITEMS.forEach(function(it, i){
        var op = '';
        if (REC && REC.rec && REC.rec.rec_id){
            op = it.track_id
                ? '<span class="st tg-closed" title="已建立追蹤項目">已轉追蹤</span>'
                : '<button type="button" class="b-mini" onclick="openToTrack(' + i + ')"><i class="fa fa-share"></i> 轉追蹤</button>';
        } else {
            op = '<span class="hint">存檔後可轉</span>';
        }
        b.append('<tr>'
            + '<td class="seq">' + (i + 1) + '</td>'
            + '<td><textarea class="iq"' + (ro ? ' readonly' : '') + '>' + esc(it.question) + '</textarea></td>'
            + '<td><textarea class="ir"' + (ro ? ' readonly' : '') + '>' + esc(it.reply) + '</textarea></td>'
            + '<td class="op">' + op + '</td></tr>');
    });
    $('#itemBody textarea').on('input', function(){ syncItemsFromDom(); });
}

/* ---- 開啟編輯跳窗 ---- */
$('#btnRecAdd').on('click', function(){ openRec(0); });
function openRec(id){
    clearErr('#recMask');
    TEMPKEY = 'tk' + Date.now() + Math.floor(Math.random() * 1e6);
    if (!id){
        REC = {rec:null, can_edit:true, stage:'', can_sign:false};
        RECITEMS = [{question:'', reply:'', track_id:0, item_id:0}, {question:'', reply:'', track_id:0, item_id:0}];
        ATTACHES = [];
        $('#recTitle').text('新增溝通記錄');
        $('#fCommDate').val(TODAY); $('#fParty').val(''); $('#fRemark').val(''); $('#fAttNote').val('');
        buildChoiceRows('irregular', '', '', {}, '');
        $('#recRejectBox').hide(); $('#recSignArea').hide();
        $('#btnRecSave,#btnRecSubmit').show(); $('#btnRecPrint').hide();
        $('#recStatusHint').text('');
        setupMakerPicker(0, 0, 0);
        resetPartyPicker(null);
        renderItems(); renderAttaches(); reloadIdentities();
        openMask('recMask');
        return;
    }
    get({action:'rec_get', id:id}, function(res){
        REC = res;
        var r = res.rec;
        RECITEMS = res.items.map(function(x){ return {question:x.question || '', reply:x.reply || '', track_id:+x.track_id || 0, item_id:+x.item_id}; });
        if (!RECITEMS.length) RECITEMS = [{question:'', reply:'', track_id:0, item_id:0}];
        ATTACHES = res.attaches || [];
        $('#recTitle').text('溝通記錄表　' + r.rec_no + '　' + (META.status[r.status] || ''));
        $('#fCommDate').val(r.comm_date); $('#fParty').val(r.party_name); $('#fRemark').val(r.remark || '');
        $('#fAttNote').val('');
        var ch = {}; $.each(META.channels, function(k){ ch[k] = +r['ch_' + k]; });
        buildChoiceRows(r.comm_type, r.party_kind, r.party_kind_other, ch, r.ch_other_text);
        setupMakerPicker(r.maker_id, r.maker_dept_id, r.maker_pos_id);
        resetPartyPicker(r);
        if (r.status === 'draft' && r.reject_note){
            $('#recRejectBox').show().html('<b style="color:#DD5138;">此單曾被退回</b>　退回人：' + esc(r.reject_by)
                + '　' + dispDate((r.reject_at || '').substr(0, 10)) + '<br>原因：' + esc(r.reject_note));
        } else $('#recRejectBox').hide();
        $('#btnRecSave,#btnRecSubmit').toggle(!!res.can_edit);
        $('#btnRecPrint').show();
        $('#recStatusHint').html('狀態：<b>' + esc(META.status[r.status] || r.status) + '</b>'
            + (+r.mgr_skip ? '（部門主管確認格免簽）' : ''));
        renderItems(); renderAttaches(); renderSignArea(res);
        openMask('recMask');
    });
}
/* ---- ② 填表人／部門：「部門 → 人員 → 職位」三段式 ----
   管理員／溝通管理員：三段都可選（代其他人建單）。
   一般使用者：**填表人自動＝建立者本人、不可改**（人員那一段整個隱藏），
   但「部門」只列他自己有職務的部門、「職位」列他在該部門的職稱——
   身兼多部門多職位的人照樣可以自己選要用哪個身分填這張表（使用者明確要求）。

   三段都不掛打字篩選框：已經先用部門收斂過，每一段的選項都只剩幾個，
   篩選框反而是多餘的干擾（使用者指定）。 */
function setupMakerPicker(makerId, makerDeptId, makerPosId){
    var admin = !!META.perms.canAdmin;
    $('#fMakerWrap').toggle(admin);
    $('#fMakerFixed').toggle(!admin);
    $('#fMakerShowMe').text(admin ? '' : META.me.name);
    MAKERUID = admin ? +(makerId || META.me.id) : +META.me.id;

    var keep = (makerDeptId && makerPosId) ? (makerDeptId + '|' + makerPosId) : '';
    if (!admin){
        // 部門清單＝自己有職務的那幾個部門，由 identities 回推（依溝通日期）
        reloadIdentities(keep, +makerDeptId || 0);
        return;
    }
    // 管理員：部門是「要在哪個部門裡找人」，選到的人＋該部門就是填表身分的部門
    var dept = +makerDeptId || 0;
    if (!dept){
        (META.people || []).forEach(function(p){ if (+p.id === MAKERUID && !dept) dept = +p.department_id || 0; });
    }
    fillDeptSel('#fMakerDept', dept, '請選擇部門…');
    loadDeptPeopleUniq(dept, '#fMaker', $('#fCommDate').val() || TODAY, MAKERUID, function(rows){
        if (!pickedPerson('#fMaker') && !rows.length) MAKERUID = +META.me.id;
        reloadIdentities(keep, dept);
    });
}
/** 管理員挑「人」時同一個人只需要出現一次（職位在第三段選），所以依 user id 去重 */
function loadDeptPeopleUniq(deptId, userSel, date, keepUid, cb){
    var sEl = $(userSel);
    PEOPLECACHE[sEl.attr('id')] = [];
    if (!+deptId){ sEl.empty().append($('<option>').val('').text('請先選擇部門')); if (cb) cb([]); return; }
    sEl.empty().append($('<option>').val('').text('載入中…'));
    get({action:'dept_people', dept_id:deptId, date:date || TODAY}, function(res){
        var seen = {}, rows = [];
        (res.rows || []).forEach(function(p){ if (!seen[p.id]){ seen[p.id] = 1; rows.push(p); } });
        PEOPLECACHE[sEl.attr('id')] = rows;
        sEl.empty().append($('<option>').val('').text(rows.length ? '請選擇人員…' : '（該部門在此日期查無在職人員）'));
        rows.forEach(function(p, i){ sEl.append($('<option>').val(i).text(p.name)); });
        if (keepUid){
            for (var i = 0; i < rows.length; i++) if (+rows[i].id === +keepUid){ sEl.val(String(i)); break; }
        }
        if (cb) cb(rows);
    });
}
$('#fMakerDept').on('change', function(){
    var d = this.value;
    if (META.perms.canAdmin){
        loadDeptPeopleUniq(d, '#fMaker', $('#fCommDate').val() || TODAY, 0, function(){ reloadIdentities('', +d || 0); });
    } else {
        // 一般使用者：部門一換，職位下拉就換成他在新部門的職稱
        fillIdentityPos(+d || 0, 0);
    }
});
$('#fMaker').on('change', function(){
    var p = pickedPerson('#fMaker');
    MAKERUID = p ? +p.id : +META.me.id;
    reloadIdentities('', +$('#fMakerDept').val() || 0);
});

function buildChoiceRows(type, kind, kindOther, ch, chOther){
    var t = $('#recTypeRow').empty();
    $.each(META.types, function(k, v){
        t.append('<label><input type="radio" name="cType" value="' + k + '"' + (k === type ? ' checked' : '') + '> ' + esc(v) + '</label>');
    });
    var kr = $('#recKindRow').empty();
    $.each(META.kinds, function(k, v){
        kr.append('<label><input type="radio" name="cKind" value="' + k + '"' + (k === kind ? ' checked' : '') + '> ' + esc(v) + '</label>');
    });
    $('#fKindOther').val(kindOther || '');
    var cr = $('#recChRow').empty();
    $.each(META.channels, function(k, v){
        cr.append('<label><input type="checkbox" class="cCh" value="' + k + '"' + (ch && ch[k] ? ' checked' : '') + '> ' + esc(v) + '</label>');
    });
    cr.append('<input type="text" id="fChOther" maxlength="100" data-eg-hint="管道勾了「其他」才要填，例如：LINE 群組" value="' + esc(chOther || '') + '">');
    var ro = REC && !REC.can_edit;
    $('#recTypeRow input,#recKindRow input,#recChRow input').prop('disabled', !!ro);
    $('#fCommDate,#fParty,#fRemark,#fKindOther').prop('readonly', !!ro);
    $('#fIdentity,#fMaker,#fMakerDept').prop('disabled', !!ro);
    $('#fAtt,#fAttNote').prop('disabled', !!ro);
    $('#pkContact,#pkContactName,#pkDept,#pkUser').prop('disabled', !!ro);
}

/* ================= ① 類別連動的利害關係者挑選 =================
   客戶／供應商＝打名稱或編號模糊搜尋（cm_party_search）；代表人可挑對方登錄的聯絡人或手填。
   員工＝先選部門，職稱依溝通日期回推當時的（主職＋兼任都列）。
   其他＝完全手填，不綁任何主檔。
   後端 rec_save 會拿同一批來源再核對一次（塞不存在的客戶編號、塞別部門的人都會被擋）＝鐵律8。 */
var MAKERUID = 0;
var PARTY = {ref_id:'', user_id:0, contact_id:0, contact_name:''};

function currentKind(){ return $('input[name=cKind]:checked').val() || ''; }

/** 依目前類別切換右側輸入區塊；$rec 有值＝開啟既有單據，要把已存的值回填 */
function resetPartyPicker(rec){
    var kind = currentKind();
    var ro   = REC && !REC.can_edit;
    PARTY = {
        ref_id:       rec ? (rec.party_ref_id || '') : '',
        user_id:      rec ? (+rec.party_user_id || 0) : 0,
        contact_id:   rec ? (+rec.party_contact_id || 0) : 0,
        contact_name: rec ? (rec.party_contact_name || '') : ''
    };
    $('#pkSearchBox,#pkEmpBox,#pkOtherBox').hide();
    $('#pkSummary').empty();
    $('#fPartyBound').hide().empty();
    acHide('fParty');
    $('#pkContactName').val(PARTY.contact_name);
    /* 客戶／供應商＝在這一格打字自動完成（可輸入）；員工＝由下方部門/人員帶入（唯讀）；
       其他＝完全手填。不給手打是為了避免「畫面寫 A、實際綁 B」。 */
    var typeable = (kind === 'customer' || kind === 'supplier' || kind === 'other');
    $('#fParty').prop('readonly', !!ro || !typeable);
    $('#fPartyLock').toggle(kind === 'customer' || kind === 'supplier');

    if (kind === 'customer' || kind === 'supplier'){
        $('#pkSearchBox').show();
        if (PARTY.ref_id){
            // 既有單據：用存下來的編號回查一次，確認還在、並把編號顯示在欄位上方
            get({action:'party_search', kind:kind, kw:PARTY.ref_id}, function(res){
                var hit = null;
                (res.rows || []).forEach(function(x){ if (String(x.id) === String(PARTY.ref_id)) hit = x; });
                PARTYHIT = hit;
                if (hit) $('#fParty').val(hit.full_name || hit.name);
                acBoundHtml('#fPartyBound', kind, hit);
                loadPartyContacts();
            });
        } else {
            PARTYHIT = null;
            acBoundHtml('#fPartyBound', kind, null);
            $('#pkContact').empty().append($('<option>').val('').text('請先選擇對象'));
        }
    } else if (kind === 'employee'){
        $('#pkEmpBox').show();
        fillDeptSel('#pkDept', PARTY.ref_id, '請選擇部門…');
        loadDeptPeople(PARTY.ref_id, '#pkUser', $('#fCommDate').val() || TODAY, PARTY.user_id, function(){ syncPartyFromPicker(); });
    } else if (kind === 'other'){
        $('#pkOtherBox').show();
    }
    if (kind !== 'customer' && kind !== 'supplier' && kind !== 'employee') syncPartyFromPicker();
}
$('#recKindRow').on('change', 'input[name=cKind]', function(){
    $('#fParty').val('');                 // 換類別＝換一套對象，舊的名稱留著只會誤導
    resetPartyPicker(null);
});

/* 「利害關係者公司/代表人」這一格就是搜尋框：打字→即時建議→選了直接填在這一格，
   編號顯示在欄位上方（使用者指定的做法，取代原本「關鍵字欄＋選擇下拉」兩個欄位） */
var PARTYHIT = null;
acSetup('#fParty', '#fPartyList', currentKind, function(x){
    var kind = currentKind();
    PARTYHIT = x;
    PARTY.ref_id = x ? String(x.id) : '';
    // 換了對象＝原本挑的聯絡人一定不再屬於這一家，要清掉
    PARTY.contact_id = 0; PARTY.contact_name = ''; $('#pkContactName').val('').prop('readonly', false);
    acBoundHtml('#fPartyBound', kind, x);
    if (x) loadPartyContacts();
    else $('#pkContact').empty().append($('<option>').val('').text('請先選擇對象'));
    syncPartyFromPicker();
});
function loadPartyContacts(){
    var kind = currentKind(), s = $('#pkContact').empty();
    if (!PARTY.ref_id){ s.append($('<option>').val('').text('請先選擇對象')); return; }
    s.append($('<option>').val('').text('載入中…'));
    get({action:'party_contacts', kind:kind, ref_id:PARTY.ref_id}, function(res){
        var rows = res.rows || [];
        CONTACTCACHE['pkContact'] = rows;
        s.empty().append($('<option>').val('').text(rows.length ? '（手動填寫／不指定）' : '（對方尚未登錄聯絡人，請手動填寫）'));
        rows.forEach(function(c, i){
            s.append($('<option>').val(i).text(c.name + (c.title ? '　' + c.title : '') + (c.department ? '　' + c.department : '')));
        });
        if (PARTY.contact_id){
            for (var i = 0; i < rows.length; i++) if (+rows[i].contact_id === +PARTY.contact_id){ s.val(String(i)); break; }
        }
        syncPartyFromPicker();
    });
}
$('#pkContact').on('change', function(){
    var rows = CONTACTCACHE['pkContact'] || [], c = this.value === '' ? null : rows[+this.value];
    PARTY.contact_id   = c ? +c.contact_id : 0;
    PARTY.contact_name = c ? c.name : '';
    $('#pkContactName').val(c ? c.name : '').prop('readonly', !!c);
    syncPartyFromPicker();
});
$('#pkContactName').on('input', function(){
    if (PARTY.contact_id) return;                    // 挑了聯絡人就以那一位為準
    PARTY.contact_name = this.value || '';
    syncPartyFromPicker();
});
$('#pkDept').on('change', function(){
    PARTY.ref_id = this.value || ''; PARTY.user_id = 0;
    loadDeptPeople(this.value, '#pkUser', $('#fCommDate').val() || TODAY, 0, function(){ syncPartyFromPicker(); });
});
$('#pkUser').on('change', function(){
    var p = pickedPerson('#pkUser');
    PARTY.user_id = p ? +p.id : 0;
    PARTY.contact_name = p ? personLabel(p) : '';
    syncPartyFromPicker();
});
/** 把挑到的對象反映到「利害關係者公司/代表人」欄與下方摘要（畫面與送出的值永遠一致） */
function syncPartyFromPicker(){
    var kind = currentKind(), sum = $('#pkSummary').empty(), txt = '';
    if (kind === 'customer' || kind === 'supplier'){
        if (PARTYHIT){
            txt = '將存入：' + (PARTYHIT.full_name || PARTYHIT.name) + '（編號 ' + PARTYHIT.id + '）'
                + (PARTY.contact_name ? '　代表人：' + PARTY.contact_name : '　代表人：未指定');
        }
    } else if (kind === 'employee'){
        var p = pickedPerson('#pkUser');
        if (p){
            $('#fParty').val(p.dept_name + '　' + p.name);
            txt = '將存入：' + p.dept_name + '　' + personLabel(p);
        }
    }
    if (txt) sum.html('<span class="pk-sum">' + esc(txt) + '</span>');
}
/* 選了填表身分就即時顯示「這張單會送給誰確認」——不先講清楚，使用者送出後才發現簽核人不對 */
/* 身分資料放模組變數、不要掛在 <option> 的 jQuery data 上：
   打字篩選（eg_input_rules.js 規則7）會把整批 <option> 用 innerHTML 重畫，掛在 option 上的 data 會整個不見，
   症狀是「篩選過一次之後簽核人預覽就空白了」——只有實際打字篩選才看得到。 */
var IDMAP = {}, GMPOOL = [], IDENTS = [];
/**
 * 抓這個人在該業務日期的全部身分（部門×職稱），填進「部門」與「職位」兩段下拉。
 * @param keep     想保留的 dept|pos（改日期時沿用原本選的身分）
 * @param wantDept 想停在哪一個部門（管理員挑完人之後＝他挑人的那個部門）
 */
function reloadIdentities(keep, wantDept){
    var uid = MAKERUID || META.me.id;
    var admin = !!META.perms.canAdmin;
    get({action:'identities', user_id:uid, date:$('#fCommDate').val() || TODAY}, function(res){
        IDENTS = res.identities || [];
        IDMAP = {}; GMPOOL = res.gm_pool || [];
        IDENTS.forEach(function(i){ IDMAP[i.department_id + '|' + i.position_id] = i; });

        if (!IDENTS.length){
            $('#fIdentity').empty().append($('<option>').val('').text('（查無部門／職稱）'));
            if (!admin) $('#fMakerDept').empty().append($('<option>').val('').text('（查無部門）'));
            $('#signPreview').html('<span style="color:#DD5138;">查無'
                + (admin && uid !== +META.me.id ? '這位人員' : '您') + '在該日期的部門／職稱資料，請洽人事確認員工部門職稱設定。</span>');
            return;
        }

        var keepDept = 0, keepPos = 0;
        if (keep){ var kv = String(keep).split('|'); keepDept = +kv[0] || 0; keepPos = +kv[1] || 0; }

        if (!admin){
            /* 一般使用者：部門下拉只列他自己有職務的部門（去重、依職位編號排序），
               不是全公司的部門清單——列出去也選不到人，只會讓人以為可以代別人填。 */
            var seen = {}, depts = [];
            IDENTS.forEach(function(i){
                if (seen[i.department_id]) return;
                seen[i.department_id] = 1;
                depts.push({id:i.department_id, name:i.department_name, sort:i.pos_sort});
            });
            var dsel = $('#fMakerDept').empty();
            depts.forEach(function(d){ dsel.append($('<option>').val(d.id).text(d.name)); });
            var pick = keepDept || wantDept || 0;
            if (!pick || !seen[pick]){
                // 沒指定就停在主要職務的部門（拍板③：預設主職）
                var main = null;
                IDENTS.forEach(function(i){ if (+i.is_main && !main) main = i; });
                pick = +((main || IDENTS[0]).department_id);
            }
            dsel.val(String(pick));
            fillIdentityPos(pick, keepPos);
            return;
        }

        // 管理員：部門下拉是全公司（找人用），職位下拉限「他在這個部門」的職稱
        var d = keepDept || wantDept || +$('#fMakerDept').val() || 0;
        fillIdentityPos(d, keepPos);
    });
}
/** 依部門填「職位」下拉；該部門沒有職稱時退回列出這個人的全部身分，不要變成空白選不了 */
function fillIdentityPos(deptId, keepPos){
    var s = $('#fIdentity').empty();
    var list = IDENTS.filter(function(i){ return +i.department_id === +deptId; });
    if (!list.length) list = IDENTS.slice();
    list.forEach(function(i){
        s.append($('<option>').val(i.department_id + '|' + i.position_id)
            .text(i.position_name + '（職位編號 ' + i.pos_sort + '）' + (i.is_main ? '　主要職務' : '')));
    });
    if (keepPos){
        var k = deptId + '|' + keepPos;
        if (s.find('option[value="' + k + '"]').length) s.val(k);
    }
    if (!s.val() && list.length) s.val(list[0].department_id + '|' + list[0].position_id);
    showSignPreview();
}
function showSignPreview(){
    var i = IDMAP[$('#fIdentity').val()];
    if (!i){ $('#signPreview').html(''); return; }
    if (!META.need_sign){
        $('#signPreview').html('<b>簽核</b>：本模組目前設定為<b>免簽核</b>，按下送出時系統會自動完成'
            + '「部門主管確認」與「總經理確認」兩格並結案（簽章日期用本單溝通日期）。'
            + (META.perms.canAdmin ? '　要改回需要簽核請按「模組設定」。' : ''));
        return;
    }
    var gm = GMPOOL;
    var gmTxt = gm.length ? gm.map(function(p){ return esc(p.name); }).join('、') : '<span style="color:#DD5138;">尚未設定（請洽管理員）</span>';
    var h;
    if (i.mgr_skip){
        h = '<b>部門主管確認</b>：<span style="color:#8A5A2B;">免簽</span>'
          + '（本單位往上追溯到<b>課級</b>為止，都沒有職位編號比您小、又符合階級門檻的主管）';
    } else {
        var names = i.mgr_pool.map(function(p){
            return esc(p.name) + '（' + esc(p.dept_name) + ' ' + esc(p.pos_name) + (p.scope === 'up' ? '・上層部門' : p.scope === 'fixed' ? '・指定' : '') + '）';
        });
        h = '<b>部門主管確認</b>：' + names.join('、') + '　<span class="hint">（任一人簽即可）</span>';
    }
    $('#signPreview').html(h + '<br><b>總經理確認</b>：' + gmTxt);
}
$('#fIdentity').on('change', showSignPreview);
/* 溝通日期＝本單業務日期，一改就要把「依當時職務解析」的三份名單全部重抓（ai-rules/22）：
   填表身分、管理員代填時的人員清單、以及類別＝員工時的對象清單。只改一份就會出現
   「畫面上挑得到、送出卻被後端擋下」——因為後端是用溝通日期重新驗的（鐵律8）。 */
$('#fCommDate').on('change', function(){
    var d = this.value || TODAY, keep = $('#fIdentity').val();
    if (META.perms.canAdmin){
        loadDeptPeopleUniq($('#fMakerDept').val(), '#fMaker', d, MAKERUID, function(){
            reloadIdentities(keep, +$('#fMakerDept').val() || 0);
        });
    } else {
        reloadIdentities(keep, +$('#fMakerDept').val() || 0);
    }
    if (currentKind() === 'employee') loadDeptPeople($('#pkDept').val(), '#pkUser', d, PARTY.user_id, function(){ syncPartyFromPicker(); });
});

/* ---- 附件 ---- */
/* ⑥ 有填說明時，畫面上一律顯示說明而不是原始檔名（掃描檔名多半是一串日期流水號，看不出是什麼）。
   原始檔名仍掛在 title 上、下載時也還是用原檔名。 */
function attDisplayName(a){ return (a.note && String(a.note).trim() !== '') ? a.note : (a.orig_name || a.file_name); }
function attRowHtml(a, editable){
    var nm = attDisplayName(a);
    return '<div class="att-row">'
        + '<span class="nm" title="' + esc(a.orig_name || a.file_name) + '">' + esc(nm)
        + (a.note && String(a.note).trim() !== '' ? '<span class="hint">　（' + esc(a.orig_name || a.file_name) + '）</span>' : '')
        + '</span>'
        + '<span class="sz">' + Math.round((+a.file_size || 0) / 1024) + ' KB</span>'
        + '<a class="b-mini" target="_blank" href="' + API + '?action=att_download&att_id=' + a.att_id + '"><i class="fa fa-eye"></i> 檢視</a>'
        + '<a class="b-mini" href="' + API + '?action=att_download&att_id=' + a.att_id + '&dl=1&dl_name='
            + encodeURIComponent(a.orig_name || a.file_name) + '"><i class="fa fa-download"></i> 下載</a>'
        + (editable ? '<button type="button" class="b-mini" onclick="editAttNote(' + a.att_id + ')"><i class="fa fa-pencil"></i> 改說明</button>'
                    + '<button type="button" class="b-mini danger" onclick="delAtt(' + a.att_id + ')"><i class="fa fa-trash"></i></button>' : '')
        + '</div>';
}
function renderAttaches(){
    var b = $('#attList').empty(), ro = REC && !REC.can_edit;
    if (!ATTACHES.length){ b.html('<span class="hint">尚無附件</span>'); return; }
    ATTACHES.forEach(function(a){ b.append(attRowHtml(a, !ro)); });
}
function editAttNote(id){
    var cur = '';
    ATTACHES.forEach(function(a){ if (+a.att_id === +id) cur = a.note || ''; });
    var v = prompt('附件說明（留空＝顯示原始檔名）', cur);
    if (v === null) return;
    post({action:'att_note', att_id:id, note:v}, function(){ refreshAttaches(); });
}
/* 上傳一律用原生可見的 file input＋送出時直讀 input.files（記憶 file_upload_change_event 三鐵則） */
$('#fAtt').on('change', function(){
    var files = this.files;
    if (!files || !files.length) return;
    var recId = (REC && REC.rec) ? REC.rec.rec_id : 0, done = 0, self = this;
    var note = $('#fAttNote').val() || '';        // 這一次選取的全部檔案套同一段說明
    NProgress.start();
    for (var i = 0; i < files.length; i++){
        var fd = new FormData();
        fd.append('action', 'att_upload'); fd.append('csrf', CSRF);
        fd.append('rec_id', recId); fd.append('temp_key', TEMPKEY);
        fd.append('note', note);
        fd.append('file', files[i]);
        $.ajax({url:API, type:'POST', data:fd, processData:false, contentType:false, dataType:'json'})
         .always(function(){
             if (++done === files.length){ NProgress.done(); self.value = ''; $('#fAttNote').val(''); refreshAttaches(); }
         });
    }
});
/* ⑤ 從清單的附件數點開：唯讀檢視，不必先開整張單 */
var RECATT = {};
function openAttList(recId){
    var rows = RECATT[recId] || [];
    $('#attTitle').text('佐證附件（' + rows.length + ' 個）');
    var b = $('#attListView').empty();
    if (!rows.length){ b.html('<span class="hint">這張單沒有附件。</span>'); }
    else rows.forEach(function(a){ b.append(attRowHtml(a, false)); });
    b.append('<div class="hint" style="margin-top:8px;">要新增或刪除附件請按該列的「開啟」進入單據；'
        + '單據送出後附件即鎖定不可異動。</div>');
    openMask('attMask');
}
function refreshAttaches(){
    var recId = (REC && REC.rec) ? REC.rec.rec_id : 0;
    get({action:'att_list', rec_id:recId, temp_key:TEMPKEY}, function(res){ ATTACHES = res.rows || []; renderAttaches(); });
}
function delAtt(id){
    if (!confirm('確定要刪除這個附件嗎？檔案會一併從 NAS 移除。')) return;
    post({action:'att_delete', att_id:id}, function(){ refreshAttaches(); });
}

/* ---- 簽章區（畫面預覽） ---- */
function stampSchema(){ return META.stamp_tpl ? META.stamp_tpl.schema : null; }
function stampHtml(name, date, deputy, dept, pos){
    if (!name) return '<span class="hint">（尚未確認）</span>';
    try {
        if (window.EGStamp && EGStamp.stamp) return EGStamp.stamp(name, dispDate(date), !!deputy, stampSchema(), dept, pos);
    } catch(e){}
    return '<span style="font-size:14px;">' + esc(name) + '</span><div class="hint">' + dispDate(date) + '</div>';
}
function renderSignArea(res){
    var r = res.rec;
    if (r.status === 'draft'){ $('#recSignArea').hide(); return; }
    $('#recSignArea').show();
    var mgr = +r.mgr_skip
        ? '<div class="box"><span class="hint">本格免簽（填表人已是最高主管）</span></div>'
        : '<div class="box">' + stampHtml(r.mgr_name, r.mgr_date, +r.mgr_deputy, r.mgr_dept_name, r.mgr_pos_name) + '</div>'
          + (+r.mgr_deputy ? '<div class="sub">代理 ' + esc(r.mgr_for_name) + ' 簽章</div>' : '')
          + (r.mgr_note ? '<div class="sub">意見：' + esc(r.mgr_note) + '</div>' : '');
    var gm = '<div class="box">' + stampHtml(r.gm_name, r.gm_date, +r.gm_deputy) + '</div>'
          + (+r.gm_deputy ? '<div class="sub">代理 ' + esc(r.gm_for_name) + ' 簽章</div>' : '')
          + (r.gm_note ? '<div class="sub">意見：' + esc(r.gm_note) + '</div>' : '');
    $('#signWrap').html(
        '<div class="sign-box"><div class="lb">總經理確認</div>' + gm + '</div>'
      + '<div class="sign-box"><div class="lb">部門主管確認</div><div class="sub">(若由主管填寫則此格免簽)</div>' + mgr + '</div>');
}

/* ---- 儲存／送出（前端即時驗證，後端同規則再擋一次＝鐵律8） ---- */
function collectRec(){
    clearErr('#recMask');
    syncItemsFromDom();
    var ok = true, d = {action:'rec_save'};
    if (REC && REC.rec) d.rec_id = REC.rec.rec_id;
    d.comm_date = $('#fCommDate').val();
    if (!d.comm_date){ setErr('eCommDate', '#fCommDate', '請填寫溝通日期'); ok = false; }
    d.comm_type = $('input[name=cType]:checked').val() || '';
    d.party_kind = $('input[name=cKind]:checked').val() || '';
    if (!d.party_kind){ setErr('eKind', null, '請選擇類別'); ok = false; }
    d.party_kind_other = $('#fKindOther').val() || '';
    if (d.party_kind === 'other' && !d.party_kind_other.trim()){ setErr('eKind', '#fKindOther', '類別選「其他」時請填寫說明'); ok = false; }
    d.party_name = $('#fParty').val() || '';
    if (!d.party_name.trim()){ setErr('eParty', '#fParty', '請填寫利害關係者公司/代表人'); ok = false; }
    /* ① 依類別帶上挑到的對象；後端會用同一批來源再核對一次（鐵律8） */
    d.party_ref_id = PARTY.ref_id || '';
    d.party_user_id = PARTY.user_id || 0;
    d.party_contact_id = PARTY.contact_id || 0;
    d.party_contact_name = (PARTY.contact_id ? PARTY.contact_name : ($('#pkContactName').val() || ''));
    if (d.party_kind === 'customer' && !d.party_ref_id){ setErr('eParty', '#fParty', '請在欄位內打客戶名稱或編號，再從跳出的清單挑一筆（要綁到客戶編號才存得進去）'); ok = false; }
    if (d.party_kind === 'supplier' && !d.party_ref_id){ setErr('eParty', '#fParty', '請在欄位內打廠商名稱或編號，再從跳出的清單挑一筆（要綁到廠商編號才存得進去）'); ok = false; }
    if (d.party_kind === 'employee'){
        if (!d.party_ref_id){ setErr('eKind', '#pkDept', '請選擇員工所屬部門'); ok = false; }
        else if (!d.party_user_id){ setErr('eKind', '#pkUser', '請選擇員工'); ok = false; }
    }
    var any = false;
    $('#recChRow .cCh').each(function(){ if (this.checked){ d['ch_' + this.value] = 1; any = true; } });
    if (!any){ setErr('eCh', null, '請至少勾選一種溝通管道'); ok = false; }
    d.ch_other_text = $('#fChOther').val() || '';
    if (d.ch_other && !d.ch_other_text.trim()){ setErr('eCh', '#fChOther', '管道勾選「其他」時請填寫說明'); ok = false; }
    var idv = ($('#fIdentity').val() || '').split('|');
    d.maker_id = MAKERUID || META.me.id;
    d.maker_dept_id = idv[0] || 0; d.maker_pos_id = idv[1] || 0;
    if (!idv[0]){ setErr('eIdentity', '#fIdentity', '請選擇填表人的部門／職稱'); ok = false; }
    var items = RECITEMS.filter(function(x){ return (x.question || '').trim() !== '' || (x.reply || '').trim() !== ''; });
    if (!items.length){ setErr('eItems', null, '請至少填寫一項溝通問題'); ok = false; }
    d.items = JSON.stringify(items);
    d.remark = $('#fRemark').val() || '';
    d.temp_key = TEMPKEY;
    return ok ? d : null;
}
$('#btnRecSave').on('click', function(){
    var d = collectRec(); if (!d) return;
    post(d, function(res){
        alert('已儲存（單號 ' + res.rec_no + '）');
        loadRec(); openRec(res.rec_id);
    });
});
$('#btnRecSubmit').on('click', function(){
    var d = collectRec(); if (!d) return;
    // ⑧ 模組設定為免簽核時，送出＝當場自動簽完並結案，文案要講清楚，不可還寫「送部門主管確認」
    var msg = META.need_sign
        ? '送出後這張單會鎖定不能修改，並依序送部門主管與總經理確認。確定要送出嗎？'
        : '本模組目前設定為「免簽核」：送出後系統會立刻自動完成兩格確認並結案（簽章日期用本單溝通日期），'
          + '之後不能再修改。確定要送出嗎？';
    if (!confirm(msg)) return;
    post(d, function(res){
        post({action:'rec_submit', rec_id:res.rec_id, status_seen:'draft'}, function(r2){
            alert(r2.msg || '已送出');
            closeMask('recMask'); loadRec();
        });
    });
});
function delRec(id){
    if (!confirm('確定要刪除這張溝通記錄表嗎？')) return;
    post({action:'rec_delete', rec_id:id}, function(){ loadRec(); });
}

/* ---- 確認／退回 ---- */
function openSign(id){
    /* 點開即刷新鐵則：按下去的當下才向後端要最新狀態，不用清單上的快取 */
    get({action:'rec_get', id:id}, function(res){
        if (!res.can_sign){ alert('這張單目前不需要您確認（可能已被其他人處理），請重新整理。'); loadRec(); return; }
        SIGNCTX = res;
        var r = res.rec;
        var what = res.stage === 'gm' ? '總經理確認' : '部門主管確認';
        $('#signTitle').text(what + '　' + r.rec_no);
        var items = (res.items || []).map(function(x, i){
            return '<div style="margin-top:4px;"><b>' + (i + 1) + '. ' + esc(x.question) + '</b>'
                 + (x.reply ? '<br>　回覆：' + esc(x.reply) : '') + '</div>'; }).join('');
        $('#signInfo').html('<b>' + esc(what) + '</b>'
            + (res.sign_deputy ? '<span style="color:#DD5138;">（您是 ' + esc(res.sign_for) + ' 的代理人，簽章會標示「代」）</span>' : '')
            + '<br>單號：' + esc(r.rec_no) + '　溝通日期：' + dispDate(r.comm_date)
            + '<br>填表人：' + esc(r.maker_name) + '（' + esc(r.maker_dept_name) + '　' + esc(r.maker_pos_name) + '）'
            + '<br>利害關係者：' + esc(r.party_name)
            + '<br><span class="hint">溝通問題：</span>' + items
            + '<div class="hint" style="margin-top:6px;">簽章日期會用<b>該單的溝通日期 ' + dispDate(r.comm_date) + '</b>，不是今天。</div>');
        $('#signNote').val(''); clearErr('#signMask');
        openMask('signMask');
    });
}
function doDecide(decision){
    if (!SIGNCTX) return;
    var note = $('#signNote').val() || '';
    if (decision === 'rejected' && !note.trim()){ setErr('eSignNote', '#signNote', '退回必須填寫原因'); return; }
    post({action:'rec_decide', rec_id:SIGNCTX.rec.rec_id, decision:decision, note:note, status_seen:SIGNCTX.rec.status},
        function(res){ alert(res.msg || (decision === 'approved' ? '已確認' : '已退回')); closeMask('signMask'); loadRec(); });
}
$('#btnSignOk').on('click', function(){ doDecide('approved'); });
$('#btnSignReject').on('click', function(){ doDecide('rejected'); });

/* ---- 轉入追蹤表 ---- */
function openToTrack(idx){
    var it = RECITEMS[idx];
    if (!it || !it.item_id){ alert('請先儲存這張單，再把項目轉入追蹤表。'); return; }
    TTCTX = it;
    clearErr('#toTrackMask');
    $('#ttContent').val(it.question);
    $('#ttAction').val(it.reply || '');
    $('#ttDue').val('');
    // ⑦ 負責人改成「部門 → 人員」兩段式，職稱依該單溝通日期回推當時的
    TTDATE = (REC && REC.rec) ? REC.rec.comm_date : TODAY;
    fillDeptSel('#ttOwnerDept', 0, '請選擇部門…');
    loadDeptPeople(0, '#ttOwner', TTDATE, 0, function(){ });
    openMask('toTrackMask');
}
var TTDATE = '';
$('#ttOwnerDept').on('change', function(){ loadDeptPeople(this.value, '#ttOwner', TTDATE || TODAY, 0, function(){ }); });
$('#btnToTrackOk').on('click', function(){
    clearErr('#toTrackMask');
    var ok = true, p = pickedPerson('#ttOwner');
    if (!$('#ttOwnerDept').val()){ setErr('eTtOwner', '#ttOwnerDept', '請先選擇負責人所屬部門'); ok = false; }
    else if (!p){ setErr('eTtOwner', '#ttOwner', '請指定負責人'); ok = false; }
    if (!$('#ttDue').val()){ setErr('eTtDue', '#ttDue', '請填寫預計完成日'); ok = false; }
    if (!ok) return;
    post({action:'rec_to_track', item_id:TTCTX.item_id, action_text:$('#ttAction').val(),
          owner_id:p.id, due_date:$('#ttDue').val()}, function(){
        alert('已建立追蹤項目，可在「回應利害關係者措施追蹤表」分頁查看。');
        closeMask('toTrackMask');
        openRec(REC.rec.rec_id); loadTrack();
    });
});

/* ================= 分頁二：措施追蹤表 ================= */
function loadTrack(){
    get({action:'track_list', page:PAGE.track, per:PER.track, show:$('#trackShow').val(), kw:$('#trackKw').val()}, function(res){
        window.__trackPrint = res.print;
        var b = $('#trackBody').empty();
        if (!res.rows.length) b.html('<tr><td colspan="10" class="cm-empty">沒有符合條件的追蹤項目</td></tr>');
        res.rows.forEach(function(r, i){
            var late = !+r.is_closed && r.due_date && r.due_date < TODAY;
            var badge = +r.is_closed ? '<span class="st tg-closed">是</span>'
                      : (late ? '<span class="st tg-late">否・已逾期</span>' : '<span class="st tg-open">否</span>');
            b.append('<tr>'
                + '<td>' + ((res.page - 1) * res.per + i + 1) + '</td>'
                + '<td class="l wrap">' + esc(r.party) + '</td>'
                + '<td class="l wrap">' + esc(r.content) + '</td>'
                + '<td>' + dispDate(r.react_date) + '</td>'
                + '<td class="l wrap">' + esc(r.action) + '</td>'
                + '<td>' + esc(r.owner_name) + '</td>'
                + '<td>' + dispDate(r.due_date) + '</td>'
                + '<td>' + badge + (+r.is_closed && r.closed_date ? '<br><span class="hint">' + dispDate(r.closed_date) + '</span>' : '') + '</td>'
                + '<td>' + (r.src_rec_no ? '<a href="javascript:;" onclick="openRec(' + r.src_rec_id + ')">' + esc(r.src_rec_no) + '</a>' : '<span class="hint">手動新增</span>') + '</td>'
                + '<td><button class="b-mini" onclick=\'openTrack(' + JSON.stringify(r.track_id) + ')\'><i class="fa fa-pencil"></i> 編輯</button></td>'
                + '</tr>');
        });
        renderPager('#trackPager', res.total, res.page, res.per,
            function(p){ PAGE.track = p; loadTrack(); },
            function(n){ PER.track = n; PAGE.track = 1; loadTrack(); });
    });
}
$('#btnTrackSearch').on('click', function(){ PAGE.track = 1; loadTrack(); });
$('#trackKw').on('keydown', function(e){ if (e.key === 'Enter'){ PAGE.track = 1; loadTrack(); } });
$('#trackShow').on('change', function(){ PAGE.track = 1; loadTrack(); });

var TRACKROWS = {}, TKLEGACYOWNER = '';
$('#btnTrackAdd').on('click', function(){ openTrack(0); });
$('#tkOwnerDept').on('change', function(){
    TKLEGACYOWNER = '';                                   // 使用者自己改部門＝要重新挑人，舊姓名退路作廢
    loadDeptPeople(this.value, '#tkOwner', $('#tkReact').val() || TODAY, 0, function(){ });
});
function openTrack(id){
    clearErr('#trackMask');
    /* 負責人一樣改成「部門 → 人員」兩段式。編輯既有項目時先用 META.people 反查他的部門，
       這樣一開跳窗就直接停在對的部門、人也已經選好。 */
    var fillOwner = function(uid, date){
        var dept = 0;
        (META.people || []).forEach(function(p){ if (+p.id === +uid && !dept) dept = +p.department_id || 0; });
        fillDeptSel('#tkOwnerDept', dept, '請選擇部門…');
        loadDeptPeople(dept, '#tkOwner', date || TODAY, uid, function(){ });
    };
    if (!id){
        $('#trackTitle').text('新增追蹤項目');
        $('#tkParty,#tkContent,#tkAction,#tkCloseNote').val('');
        $('#tkReact,#tkDue,#tkCloseDate').val('');
        $('#tkClosed').prop('checked', false).trigger('change');
        $('#tkSrc').html(''); $('#btnTrackDel').hide();
        TKLEGACYOWNER = '';
        fillOwner(0, TODAY); $('#trackMask').data('id', 0);
        openMask('trackMask');
        return;
    }
    get({action:'track_list', page:1, per:50, show:'all', kw:''}, function(res){
        var r = null;
        res.rows.forEach(function(x){ if (+x.track_id === +id) r = x; });
        if (!r){ alert('查無此追蹤項目，請重新整理。'); loadTrack(); return; }
        $('#trackTitle').text('編輯追蹤項目');
        $('#tkParty').val(r.party); $('#tkContent').val(r.content); $('#tkAction').val(r.action);
        $('#tkReact').val(r.react_date || ''); $('#tkDue').val(r.due_date || '');
        $('#tkCloseDate').val(r.closed_date || ''); $('#tkCloseNote').val(r.close_note || '');
        $('#tkClosed').prop('checked', !!+r.is_closed).trigger('change');
        /* 舊資料可能只有姓名沒有 user_id（或那個人已離職、不在候選名單裡）——
           留著原姓名當退路，使用者沒有重新挑人時就照原樣存回去，不要把人洗掉。 */
        TKLEGACYOWNER = (!+r.owner_id && r.owner_name) ? r.owner_name : '';
        fillOwner(+r.owner_id || 0, r.react_date || TODAY);
        $('#tkSrc').html(r.src_rec_no ? '來源：溝通記錄表 <b>' + esc(r.src_rec_no) + '</b>' : '來源：手動新增');
        $('#btnTrackDel').toggle(!!META.perms.canAdmin);
        $('#trackMask').data('id', id);
        openMask('trackMask');
    });
}
$('#tkClosed').on('change', function(){
    var c = this.checked;
    $('#tkCloseWrap,#tkCloseNoteWrap').toggle(c);
    $('#tkDueStar').toggle(!c);
    if (c && !$('#tkCloseDate').val()) $('#tkCloseDate').val(TODAY);
});
$('#btnTrackSave').on('click', function(){
    clearErr('#trackMask');
    var ok = true, closed = $('#tkClosed').is(':checked');
    if (!$('#tkParty').val().trim()){ setErr('eTkParty', '#tkParty', '請填寫利害關係者'); ok = false; }
    if (!$('#tkContent').val().trim()){ setErr('eTkContent', '#tkContent', '請填寫反應內容'); ok = false; }
    var tkp = pickedPerson('#tkOwner');
    if (!tkp && !TKLEGACYOWNER){ setErr('eTkOwner', '#tkOwner', '請指定負責人（先選部門再挑人）'); ok = false; }
    if (!closed && !$('#tkDue').val()){ setErr('eTkDue', '#tkDue', '尚未結案的項目必須填寫預計完成日'); ok = false; }
    if (!ok) return;
    post({action:'track_save', track_id:$('#trackMask').data('id') || 0,
        party:$('#tkParty').val(), content:$('#tkContent').val(), react_date:$('#tkReact').val(),
        action_text:$('#tkAction').val(), owner_id:tkp ? tkp.id : 0,
        owner_name:tkp ? '' : TKLEGACYOWNER, due_date:$('#tkDue').val(),
        is_closed:closed ? 1 : 0, closed_date:$('#tkCloseDate').val(), close_note:$('#tkCloseNote').val()},
        function(){ closeMask('trackMask'); loadTrack(); });
});
$('#btnTrackDel').on('click', function(){
    if (!confirm('確定要刪除這個追蹤項目嗎？來源溝通記錄表上的「已轉追蹤」標記會一併解除。')) return;
    post({action:'track_delete', track_id:$('#trackMask').data('id') || 0},
        function(){ closeMask('trackMask'); loadTrack(); });
});

/* ================= 分頁三：溝通管制表 ================= */
function loadCtrl(){
    get({action:'ctrl_list', page:PAGE.ctrl, per:PER.ctrl, kw:$('#ctrlKw').val()}, function(res){
        window.__ctrlPrint = res.print;
        CTRLROWS = {};
        var b = $('#ctrlBody').empty();
        if (!res.rows.length) b.html('<tr><td colspan="8" class="cm-empty">尚未建立任何常態性溝通機制</td></tr>');
        res.rows.forEach(function(r, i){
            CTRLROWS[r.ctrl_id] = r;
            // 頻率字串一律用後端 cm_freq_text() 組好的 freq_text（鐵律4：前端不要再拼一次「每 N 單位 M 次」）
            var freq = r.freq_text || r.freq || '';
            var due  = r.next_due_date ? dispDate(r.next_due_date) : '<span class="hint">未設定</span>';
            var late = r.next_due_date && r.next_due_date < TODAY;
            var rem;
            if (+r.remind_enabled){
                rem = '<span class="st tg-open">提醒開啟</span><br><span class="hint">提前 ' + (+r.remind_lead_days || 0)
                    + ' 天　' + esc(String(r.remind_time || '09:00').substr(0, 5)) + '<br>'
                    + esc((r.target_labels || []).join('、') || '（未指定對象）') + '</span>';
            } else {
                rem = '<span class="hint">未開啟提醒</span>';
            }
            b.append('<tr>'
                + '<td>' + ((res.page - 1) * res.per + i + 1) + '</td>'
                + '<td>' + esc(r.maker_name) + (r.maker_dept_name ? '<br><span class="hint">' + esc(r.maker_dept_name) + '</span>' : '') + '</td>'
                + '<td class="l wrap">' + esc(r.party)
                + '<br><span class="hint">' + esc(r.party_kind === 'other' ? (r.party_kind_other || '其他') : (META.kinds[r.party_kind] || '')) + '</span></td>'
                + '<td class="l wrap">' + esc(r.content) + '</td>'
                + '<td class="l">' + esc(r.channel) + '</td>'
                + '<td>' + esc(freq) + '</td>'
                + '<td>' + due + (late ? '<br><span class="st tg-late">已逾期</span>' : '') + '<br>' + rem + '</td>'
                + '<td><button class="b-mini warm" onclick="openC2R(' + r.ctrl_id + ')" title="依這個機制建立一張溝通記錄表">'
                + '<i class="fa fa-plus"></i> 建立溝通記錄</button>'
                + '<button class="b-mini" onclick="openCtrl(' + r.ctrl_id + ')"><i class="fa fa-pencil"></i> 編輯</button></td>'
                + '</tr>');
        });
        renderPager('#ctrlPager', res.total, res.page, res.per,
            function(p){ PAGE.ctrl = p; loadCtrl(); },
            function(n){ PER.ctrl = n; PAGE.ctrl = 1; loadCtrl(); });
    });
}
$('#btnCtrlSearch').on('click', function(){ PAGE.ctrl = 1; loadCtrl(); });
$('#ctrlKw').on('keydown', function(e){ if (e.key === 'Enter'){ PAGE.ctrl = 1; loadCtrl(); } });

/* ---- ⑨⑩⑪ 管制項目編輯：填表人／利害關係人／管道全部比照溝通記錄，頻率固定「每 N 單位 M 次」 ---- */
var CTRLROWS = {}, CFMAKERUID = 0;
var CFPARTY = {ref_id:'', user_id:0};
var CFTARGETS = [];                 // [{type:'user'|'dept', id:N}]

function cfKind(){ return $('input[name=cfKind]:checked').val() || ''; }

$('#btnCtrlAdd').on('click', function(){ openCtrl(0); });
function openCtrl(id){
    clearErr('#ctrlMask');
    var r = id ? CTRLROWS[id] : null;
    if (id && !r){ alert('查無此管制項目，請重新整理。'); loadCtrl(); return; }

    // ---- 類別 radio（管制表沒有「型態」，但類別與管道跟記錄表同一套常數） ----
    var kr = $('#cfKindRow').empty();
    $.each(META.kinds, function(k, v){
        kr.append('<label><input type="radio" name="cfKind" value="' + k + '"'
            + ((r ? r.party_kind : '') === k ? ' checked' : '') + '> ' + esc(v) + '</label>');
    });
    var cr = $('#cfChRow').empty();
    $.each(META.channels, function(k, v){
        // 管制表的 channel 是顯示字串（後端由勾選組出來的），回填時用字串比對把勾勾點回去
        var on = r ? (k === 'other' ? false : (String(r.channel || '').indexOf(v) >= 0)) : false;
        cr.append('<label><input type="checkbox" class="cfCh" value="' + k + '"' + (on ? ' checked' : '') + '> ' + esc(v) + '</label>');
    });
    cr.append('<input type="text" id="cfChOther" maxlength="100" data-eg-hint="管道勾了「其他」才要填，例如：LINE 群組" value="">');

    // ---- 頻率單位 ----
    var fu = $('#cfFreqUnit').empty();
    $.each(META.freq_units || {month:'月'}, function(k, v){ fu.append($('<option>').val(k).text(v)); });

    // ---- 填表人（管理員可代填；一般使用者固定自己） ----
    var admin = !!META.perms.canAdmin;
    $('#cfMakerDept,#cfMaker').toggle(admin);
    $('#cfMakerFixed').toggle(!admin);
    CFMAKERUID = r ? (+r.maker_id || +META.me.id) : +META.me.id;
    if (admin){
        var mdept = r ? (+r.maker_dept_id || 0) : 0;
        if (!mdept) (META.people || []).forEach(function(p){ if (+p.id === CFMAKERUID && !mdept) mdept = +p.department_id || 0; });
        fillDeptSel('#cfMakerDept', mdept, '請選擇部門…');
        loadDeptPeople(mdept, '#cfMaker', TODAY, CFMAKERUID, function(){ });
        $('#cfMakerShow').text('');
    } else {
        $('#cfMakerShow').text(META.me.name);
    }

    // ---- 利害關係人 ----
    CFPARTY = {ref_id: r ? (r.party_ref_id || '') : '', user_id: r ? (+r.party_user_id || 0) : 0};
    $('#cfKindOther').val(r ? (r.party_kind_other || '') : '');
    $('#cfParty').val(r ? (r.party || '') : '');
    resetCfParty(true);

    // ---- 其餘欄位 ----
    $('#cfContent').val(r ? (r.content || '') : '');
    $('#cfFreqN').val(r ? (+r.freq_n || 1) : 1);
    $('#cfFreqUnit').val(r ? (r.freq_unit || 'month') : 'month');
    $('#cfFreqTimes').val(r ? (+r.freq_times || 1) : 1);
    $('#cfNextDue').val(r ? (r.next_due_date || '') : '');
    $('#cfLead').val(r && r.remind_lead_days !== null && r.remind_lead_days !== undefined ? +r.remind_lead_days : 3);
    $('#cfRemTime').val(r && r.remind_time ? String(r.remind_time).substr(0, 5) : '09:00');
    $('#cfRemind').prop('checked', r ? !!+r.remind_enabled : false);
    $('#cfRemark').val(r ? (r.remark || '') : '');
    $('#cfSort').val(r ? (+r.sort_order || 0) : 0);
    CFTARGETS = (r && r.targets ? r.targets : []).map(function(t){ return {type:t.target_type, id:+t.target_id}; });
    fillCfTargetPicks();
    renderCfTargets();
    syncCfRemind();
    updFreqPreview();

    $('#ctrlTitle').text(id ? '編輯管制項目' : '新增管制項目');
    $('#btnCtrlDel').toggle(!!META.perms.canAdmin && !!id);
    $('#ctrlMask').data('id', id || 0);
    openMask('ctrlMask');
}
$('#cfMakerDept').on('change', function(){ loadDeptPeople(this.value, '#cfMaker', TODAY, 0, function(){ }); });
$('#cfMaker').on('change', function(){ var p = pickedPerson('#cfMaker'); CFMAKERUID = p ? +p.id : +META.me.id; });

/* 類別連動（管制表版：客戶／供應商**不必挑到聯絡人**，那是開記錄表時才填的） */
function resetCfParty(keep){
    var kind = cfKind();
    $('#cfSearchBox,#cfEmpBox,#cfOtherBox').hide();
    $('#cfSummary').empty();
    $('#cfPartyBound').hide().empty();
    acHide('cfParty');
    if (!keep){ CFPARTY = {ref_id:'', user_id:0}; CFHIT = null; $('#cfParty').val(''); }
    // 客戶／供應商＝在這一格打字自動完成；員工＝由下方部門/人員帶入（唯讀）；其他＝手填
    $('#cfParty').prop('readonly', kind === 'employee');
    $('#cfPartyLock').toggle(kind === 'customer' || kind === 'supplier');
    if (kind === 'customer' || kind === 'supplier'){
        $('#cfSearchBox').show();
        if (CFPARTY.ref_id){
            get({action:'party_search', kind:kind, kw:CFPARTY.ref_id}, function(res){
                var hit = null;
                (res.rows || []).forEach(function(x){ if (String(x.id) === String(CFPARTY.ref_id)) hit = x; });
                CFHIT = hit;
                if (hit) $('#cfParty').val(hit.full_name || hit.name);
                acBoundHtml('#cfPartyBound', kind, hit);
                syncCfParty();
            });
        } else {
            CFHIT = null;
            acBoundHtml('#cfPartyBound', kind, null);
        }
    } else if (kind === 'employee'){
        $('#cfEmpBox').show();
        fillDeptSel('#cfDept', CFPARTY.ref_id, '請選擇部門…');
        loadDeptPeople(CFPARTY.ref_id, '#cfUser', TODAY, CFPARTY.user_id, function(){ syncCfParty(); });
    } else if (kind === 'other'){
        $('#cfOtherBox').show();
    }
}
$('#cfKindRow').on('change', 'input[name=cfKind]', function(){ resetCfParty(false); });
/* 「利害關係人」這一格就是搜尋框（同記錄表的做法） */
var CFHIT = null;
acSetup('#cfParty', '#cfPartyList', cfKind, function(x){
    CFHIT = x;
    CFPARTY.ref_id = x ? String(x.id) : '';
    acBoundHtml('#cfPartyBound', cfKind(), x);
    syncCfParty();
});
$('#cfDept').on('change', function(){
    CFPARTY.ref_id = this.value || ''; CFPARTY.user_id = 0;
    loadDeptPeople(this.value, '#cfUser', TODAY, 0, function(){ syncCfParty(); });
});
$('#cfUser').on('change', function(){
    var p = pickedPerson('#cfUser');
    CFPARTY.user_id = p ? +p.id : 0;
    if (p) $('#cfParty').val(p.dept_name + '　' + personLabel(p));   // 唯讀欄位由這裡帶入
    syncCfParty();
});
function syncCfParty(){
    var kind = cfKind(), sum = $('#cfSummary').empty(), txt = '';
    if (kind === 'customer' || kind === 'supplier'){
        if (CFHIT) txt = '將存入：' + (CFHIT.full_name || CFHIT.name) + '（編號 ' + CFHIT.id + '）';
    } else if (kind === 'employee'){
        var p = pickedPerson('#cfUser');
        if (p) txt = '將存入：' + p.dept_name + '　' + personLabel(p);
    }
    if (txt) sum.html('<span class="pk-sum">' + esc(txt) + '</span>');
}

/* ---- 頻率預覽：畫面上的字串跟後端 cm_freq_text() 產生的一致，存檔前就看得到 ---- */
function updFreqPreview(){
    var u = (META.freq_units || {})[$('#cfFreqUnit').val()] || '';
    $('#cfFreqPreview').text('每 ' + (parseInt($('#cfFreqN').val(), 10) || 1) + ' ' + u
        + ' ' + (parseInt($('#cfFreqTimes').val(), 10) || 1) + ' 次');
}
$('#cfFreqN,#cfFreqUnit,#cfFreqTimes').on('input change', updFreqPreview);

/* ---- ⑪ 提醒對象 ---- */
function syncCfRemind(){
    var on = $('#cfRemind').is(':checked');
    $('#cfTargetWrap').toggle(on);
    $('.cfRemReq').toggle(on);
}
$('#cfRemind').on('change', syncCfRemind);
function fillCfTargetPicks(){
    var u = $('#cfTargetUser').empty().append($('<option>').val('').text('＋ 加入人員…'));
    (META.people || []).forEach(function(p){
        u.append($('<option>').val(p.id).text(p.user_cname + '（' + (p.dept_name || '') + ' ' + (p.position_name || '') + '）'));
    });
    var d = $('#cfTargetDept').empty().append($('<option>').val('').text('＋ 加入部門（含子部門）…'));
    (META.depts || []).forEach(function(x){
        var pad = new Array(Math.max(0, (+x.level || 1) - 1) + 1).join('　');
        d.append($('<option>').val(x.id).text(pad + x.name));
    });
}
function cfTargetLabel(t){
    if (t.type === 'user') return nameOf(t.id);
    var nm = '';
    (META.depts || []).forEach(function(x){ if (+x.id === +t.id) nm = x.name; });
    return (nm || ('部門#' + t.id)) + '（含子部門）';
}
function renderCfTargets(){
    var b = $('#cfTargetChips').empty();
    if (!CFTARGETS.length){ b.html('<span class="hint">尚未指定提醒對象</span>'); return; }
    CFTARGETS.forEach(function(t, i){
        b.append('<span class="chip">' + esc(cfTargetLabel(t))
            + ' <i class="fa fa-times" onclick="dropCfTarget(' + i + ')"></i></span>');
    });
}
function dropCfTarget(i){ CFTARGETS.splice(i, 1); renderCfTargets(); }
$('#cfTargetUser').on('change', function(){
    var v = +this.value; this.value = '';
    if (!v) return;
    var dup = CFTARGETS.some(function(t){ return t.type === 'user' && +t.id === v; });
    if (!dup){ CFTARGETS.push({type:'user', id:v}); renderCfTargets(); }
});
$('#cfTargetDept').on('change', function(){
    var v = +this.value; this.value = '';
    if (!v) return;
    var dup = CFTARGETS.some(function(t){ return t.type === 'dept' && +t.id === v; });
    if (!dup){ CFTARGETS.push({type:'dept', id:v}); renderCfTargets(); }
});

/* ---- 儲存（前端即時驗證，後端 ctrl_save 同規則再擋一次＝鐵律8） ---- */
$('#btnCtrlSave').on('click', function(){
    clearErr('#ctrlMask');
    var ok = true, kind = cfKind(), d = {action:'ctrl_save', ctrl_id:$('#ctrlMask').data('id') || 0};
    d.maker_id = CFMAKERUID || META.me.id;
    d.maker_dept_id = META.perms.canAdmin ? ($('#cfMakerDept').val() || 0) : 0;

    if (!kind){ setErr('eCfKind', null, '請選擇利害關係人的類別'); ok = false; }
    d.party_kind = kind;
    d.party_kind_other = $('#cfKindOther').val() || '';
    d.party_ref_id = CFPARTY.ref_id || '';
    d.party_user_id = CFPARTY.user_id || 0;
    d.party = $('#cfParty').val() || '';
    if (kind === 'other'){
        if (!d.party_kind_other.trim()){ setErr('eCfKind', '#cfKindOther', '類別選「其他」時請填寫說明'); ok = false; }
        if (!d.party.trim()){ setErr('eCfParty', '#cfParty', '請填寫利害關係人'); ok = false; }
    } else if (kind === 'customer' || kind === 'supplier'){
        if (!d.party_ref_id){ setErr('eCfParty', '#cfParty', kind === 'customer'
            ? '請在欄位內打客戶名稱或編號，再從跳出的清單挑一筆（要綁到客戶編號才存得進去）'
            : '請在欄位內打廠商名稱或編號，再從跳出的清單挑一筆（要綁到廠商編號才存得進去）'); ok = false; }
    } else if (kind === 'employee'){
        if (!d.party_ref_id){ setErr('eCfKind', '#cfDept', '請選擇員工所屬部門'); ok = false; }
        else if (!d.party_user_id){ setErr('eCfKind', '#cfUser', '請選擇員工'); ok = false; }
    }

    d.content = $('#cfContent').val() || '';
    if (!d.content.trim()){ setErr('eCfContent', '#cfContent', '請填寫溝通內容'); ok = false; }

    var anyCh = false;
    $('#cfChRow .cfCh').each(function(){ if (this.checked){ d['ch_' + this.value] = 1; anyCh = true; } });
    if (!anyCh){ setErr('eCfChannel', null, '請至少勾選一種溝通管道'); ok = false; }
    d.ch_other_text = $('#cfChOther').val() || '';
    if (d.ch_other && !d.ch_other_text.trim()){ setErr('eCfChannel', '#cfChOther', '管道勾選「其他」時請填寫說明'); ok = false; }

    d.freq_n = parseInt($('#cfFreqN').val(), 10) || 0;
    d.freq_unit = $('#cfFreqUnit').val() || 'month';
    d.freq_times = parseInt($('#cfFreqTimes').val(), 10) || 0;
    if (d.freq_n < 1 || d.freq_times < 1){ setErr('eCfFreq', '#cfFreqN', '頻率的次數與週期都必須至少是 1'); ok = false; }

    d.next_due_date = $('#cfNextDue').val() || '';
    d.remind_enabled = $('#cfRemind').is(':checked') ? 1 : 0;
    d.remind_lead_days = parseInt($('#cfLead').val(), 10) || 0;
    d.remind_time = $('#cfRemTime').val() || '';
    d.targets = JSON.stringify(CFTARGETS);
    if (d.remind_enabled){
        if (!d.next_due_date){ setErr('eCfNextDue', '#cfNextDue', '要自動提醒就必須填「下次應溝通日」，提醒時間是由它往前推算的'); ok = false; }
        if (d.remind_time && !/^\d{1,2}:\d{2}$/.test(d.remind_time)){ setErr('eCfRemTime', '#cfRemTime', '提醒時間請填 24 小時制的 HH:MM，例如 09:00'); ok = false; }
        if (!CFTARGETS.length){ setErr('eCfTarget', '#cfTargetUser', '要自動提醒就必須至少指定一位提醒對象（人員或部門）'); ok = false; }
    }
    d.remark = $('#cfRemark').val() || '';
    d.sort_order = $('#cfSort').val() || 0;
    if (!ok) return;
    post(d, function(){ closeMask('ctrlMask'); loadCtrl(); });
});
$('#btnCtrlDel').on('click', function(){
    if (!confirm('確定要刪除這個管制項目嗎？')) return;
    post({action:'ctrl_delete', ctrl_id:$('#ctrlMask').data('id') || 0},
        function(){ closeMask('ctrlMask'); loadCtrl(); });
});

/* ---- ⑫ 由管制項目建立溝通記錄表 ---- */
var C2RID = 0;
function openC2R(id){
    /* 點開即刷新鐵則（ai-rules/08 第六節）：別人可能剛改過頻率或下次應溝通日，
       不能拿清單上的快取去算「會推到哪一天」。 */
    get({action:'ctrl_list', page:1, per:50, kw:''}, function(res){
        var r = null;
        (res.rows || []).forEach(function(x){ if (+x.ctrl_id === +id) r = x; });
        if (!r){ alert('查無此管制項目（可能剛被刪除），請重新整理。'); loadCtrl(); return; }
        CTRLROWS[id] = r; C2RID = id;
        clearErr('#c2rMask');
        var isOther = (r.party_kind === 'other');
        $('#c2rParty').val(r.party || '').prop('readonly', !isOther);
        $('#c2rPartyLock').toggle(!isOther);
        $('#c2rKindOtherWrap').toggle(isOther);
        $('#c2rKindOther').val(r.party_kind_other || '');
        $('#c2rContent').val(r.content || '');
        var cr = $('#c2rChRow').empty();
        $.each(META.channels, function(k, v){
            var on = (k === 'other') ? false : (String(r.channel || '').indexOf(v) >= 0);
            cr.append('<label><input type="checkbox" class="c2rCh" value="' + k + '"' + (on ? ' checked' : '') + '> ' + esc(v) + '</label>');
        });
        cr.append('<input type="text" id="c2rChOther" maxlength="100" data-eg-hint="管道勾了「其他」才要填" value="">');
        // 下次應溝通日要推到哪一天，先算給使用者看，不要讓他按完才發現被改掉了
        var base = r.next_due_date || TODAY;
        var nx = advanceLocal(base, +r.freq_n || 1, r.freq_unit || 'month');
        $('#c2rAdv').prop('checked', true);
        $('#c2rAdvHint').html('目前的下次應溝通日：<b>' + (r.next_due_date ? dispDate(r.next_due_date) : '未設定（會以今天為基準）')
            + '</b>　→　更新為 <b>' + dispDate(nx) + '</b>（' + esc(r.freq_text || '') + '）。'
            + '<br>同一筆管制項目<b>可以重複建立</b>記錄表；不想動提醒排程就取消勾選。');
        openMask('c2rMask');
    });
}
/** 前端預告用的推算（與後端 cm_freq_advance() 同規則；真正寫入的值一律以後端算的為準） */
function advanceLocal(dateStr, n, unit){
    var p = String(dateStr).split('-');
    var d = new Date(+p[0], (+p[1] || 1) - 1, +p[2] || 1);
    n = Math.max(1, n || 1);
    if (unit === 'day') d.setDate(d.getDate() + n);
    else if (unit === 'week') d.setDate(d.getDate() + n * 7);
    else if (unit === 'halfyear') d.setMonth(d.getMonth() + n * 6);
    else if (unit === 'year') d.setFullYear(d.getFullYear() + n);
    else d.setMonth(d.getMonth() + n);
    var mm = ('0' + (d.getMonth() + 1)).slice(-2), dd = ('0' + d.getDate()).slice(-2);
    return d.getFullYear() + '-' + mm + '-' + dd;
}
$('#btnC2rOk').on('click', function(){
    clearErr('#c2rMask');
    var r = CTRLROWS[C2RID];
    if (!r) return;
    var d = {action:'ctrl_to_record', ctrl_id:C2RID, advance_due:$('#c2rAdv').is(':checked') ? 1 : 0};
    if (r.party_kind === 'other'){
        d.party_name = $('#c2rParty').val() || '';
        d.party_kind_other = $('#c2rKindOther').val() || '';
        if (!d.party_name.trim()){ setErr('eC2rParty', '#c2rParty', '請填寫利害關係人'); return; }
    }
    var any = false;
    $('#c2rChRow .c2rCh').each(function(){ if (this.checked){ d['ch_' + this.value] = 1; any = true; } });
    if (!any){ setErr('eC2rCh', null, '請至少勾選一種溝通管道'); return; }
    d.ch_other_text = $('#c2rChOther').val() || '';
    if (d.ch_other && !d.ch_other_text.trim()){ setErr('eC2rCh', '#c2rChOther', '管道勾選「其他」時請填寫說明'); return; }
    post(d, function(res){
        closeMask('c2rMask');
        loadCtrl(); loadRec();
        // 建好就直接開那張記錄表，使用者接著填溝通問題與回覆
        $('#mainTabs .cm-tab[data-tab="rec"]').trigger('click');
        openRec(res.rec_id);
    });
});
/* ================= 模組設定 ================= */
$('#btnSetting').on('click', function(){
    var s = META.settings;
    var t = $('#setStampTpl').empty().append('<option value="">（不指定，用系統預設回墨印）</option>');
    (META.stamp_list || []).forEach(function(p){
        t.append($('<option>').val(p.id).text((p.type_name ? p.type_name + '／' : '') + p.tpl_name));
    });
    t.val(s.cm_stamp_tpl_id || '')
    var mkRank = function(el, val){
        var r = $(el).empty();
        (META.ranks || []).forEach(function(x){
            r.append($('<option>').val(x.rank_order).text(x.name + '（含以上都可簽）'));
        });
        r.val(val);
    };
    mkRank('#setMgrRank', s.cm_mgr_rank_max);
    mkRank('#setGmRank', s.cm_gm_rank_max);
    $('#setNeedSign').prop('checked', String(s.cm_need_sign === undefined ? '1' : s.cm_need_sign) !== '0');
    $('input[name=mgrSrc][value="' + (s.cm_mgr_source || 'auto') + '"]').prop('checked', true).trigger('change');
    $('input[name=gmSrc][value="' + (s.cm_gm_source || 'top') + '"]').prop('checked', true).trigger('change');
    MGRUSERS = JSON.parse(s.cm_mgr_users || '[]');
    GMUSERS  = JSON.parse(s.cm_gm_users || '[]');
    renderChips('mgr'); renderChips('gm');
    fillPick('#mgrPick'); fillPick('#gmPick');
    renderSetAsdoc();
    openMask('setMask');
});
function fillPick(sel){
    var s = $(sel).empty().append('<option value="">＋ 加入人員…</option>');
    (META.people || []).forEach(function(p){
        s.append($('<option>').val(p.id).text(p.user_cname + '（' + (p.dept_name || '') + ' ' + (p.position_name || '') + '）'));
    });
    s
}
function nameOf(uid){
    var n = '';
    (META.people || []).forEach(function(p){ if (+p.id === +uid) n = p.user_cname; });
    return n || ('#' + uid);
}
function renderChips(which){
    var arr = which === 'mgr' ? MGRUSERS : GMUSERS;
    var b = $('#' + which + 'Chips').empty();
    if (!arr.length){ b.html('<span class="hint">尚未指定任何人員</span>'); return; }
    arr.forEach(function(uid){
        b.append('<span class="chip">' + esc(nameOf(uid))
            + ' <i class="fa fa-times" onclick="dropChip(\'' + which + '\',' + uid + ')"></i></span>');
    });
}
function dropChip(which, uid){
    if (which === 'mgr') MGRUSERS = MGRUSERS.filter(function(x){ return +x !== +uid; });
    else GMUSERS = GMUSERS.filter(function(x){ return +x !== +uid; });
    renderChips(which);
}
$('#mgrPick').on('change', function(){
    var v = +this.value; this.value = '';
    if (v && MGRUSERS.indexOf(v) < 0){ MGRUSERS.push(v); renderChips('mgr'); }
});
$('#gmPick').on('change', function(){
    var v = +this.value; this.value = '';
    if (v && GMUSERS.indexOf(v) < 0){ GMUSERS.push(v); renderChips('gm'); }
});
$('input[name=mgrSrc]').on('change', function(){
    var v = $('input[name=mgrSrc]:checked').val();
    $('#mgrAutoWrap').toggle(v === 'auto'); $('#mgrUsersWrap').toggle(v === 'users');
});
$('input[name=gmSrc]').on('change', function(){
    var v = $('input[name=gmSrc]:checked').val();
    $('#gmUsersWrap').toggle(v === 'users'); $('#gmRankWrap').toggle(v === 'rank');
    $('#gmTopHint').html(v === 'top'
        ? '「最高核准人員」是全站共用的組織角色綁定，設定在<b>管理者 → 組織角色綁定設定</b>；本模組不寫死人名。'
        : '');
});
function renderSetAsdoc(){
    var b = $('#setAsdocBody').empty();
    $.each(META.asdoc, function(k, v){
        var cur = v.doc ? (v.doc.doc_no + '（' + v.doc.doc_name + '）') : '尚未綁定　建議：' + v.fallback;
        b.append('<tr><td class="l">' + esc(v.label) + '</td>'
            + '<td class="l" id="asd_' + k + '">' + esc(cur) + '</td>'
            + '<td><button class="b-mini" onclick="pickAsdoc(\'' + k + '\')">選擇</button></td></tr>');
    });
}
function pickAsdoc(which){
    EGAsDoc.open({
        docs: META.asdoc_list, current: META.asdoc[which].doc_id,
        title: 'AS 文件編號綁定　' + META.asdoc[which].label,
        onSave: function(id){
            post({action:'asdoc_save', which:which, doc_id:id || 0}, function(res){
                META.asdoc = res.asdoc; renderSetAsdoc(); renderAsdocHint();
            });
        }
    });
}
$('#btnSetSave').on('click', function(){
    var s = {
        cm_need_sign:    $('#setNeedSign').is(':checked') ? '1' : '0',
        cm_stamp_tpl_id: $('#setStampTpl').val() || '',
        cm_mgr_source:   $('input[name=mgrSrc]:checked').val() || 'auto',
        cm_mgr_rank_max: $('#setMgrRank').val() || '3',
        cm_mgr_users:    MGRUSERS,
        cm_gm_source:    $('input[name=gmSrc]:checked').val() || 'top',
        cm_gm_rank_max:  $('#setGmRank').val() || '0',
        cm_gm_users:     GMUSERS
    };
    if (s.cm_mgr_source === 'users' && !MGRUSERS.length){ alert('選了「固定由指定人員簽」就必須至少指定一位人員。'); return; }
    if (s.cm_gm_source === 'users' && !GMUSERS.length){ alert('選了「固定由指定人員簽」就必須至少指定一位人員。'); return; }
    if (!$('#setNeedSign').is(':checked')
        && !confirm('取消「需要簽核」＝往後溝通記錄表一按送出就自動簽完並結案，不會再送給任何人確認。確定要改成免簽核嗎？')) return;
    post({action:'setting_save', settings:JSON.stringify(s)}, function(res){
        META.settings = res.settings;
        // need_sign 會影響送出按鈕文案與簽核人預覽，一定要重抓 meta 讓畫面跟著變
        get({action:'meta'}, function(m){
            META = m; CSRF = m.csrf;
            $('#btnRecSubmit').html(m.need_sign
                ? '<i class="fa fa-paper-plane"></i> 儲存並送出確認'
                : '<i class="fa fa-check-circle"></i> 儲存並送出（免簽核・自動完成）');
        });
        alert('設定已儲存');
        closeMask('setMask');
    });
});

/* ================= 列印（ai-rules/16） ================= */
/* 大標題＝公司全名（動態取，禁寫死）／表頭＝綁定 AS 文件的表單名稱／文件編號右下角／多頁才印頁碼。
   分頁 100% 交給瀏覽器列印引擎，不做整頁縮放（ai-rules/18 鐵則8——那會把圖章一起壓扁）。 */
function egPrintWindow(title, bodyHtml, extraCss, docNo, landscape, showPageCounter){
    if (showPageCounter === undefined) showPageCounter = true;
    var asHtml = esc(String(docNo || ''));
    var css = '@page{size:A4 ' + (landscape ? 'landscape' : 'portrait') + ';margin:0;}'
        + 'html,body{margin:0;padding:0;}'
        + 'body{font-family:"Microsoft JhengHei","微軟正黑體",sans-serif;color:#000;padding:10mm 8mm 12mm;'
        + '-webkit-print-color-adjust:exact;print-color-adjust:exact;}'
        + '.pt-head{text-align:center;margin-bottom:6px;}'
        + '.pt-head .co{font-size:22px;font-weight:bold;letter-spacing:2px;}'
        + '.pt-head .tt{font-size:16px;font-weight:bold;margin-top:3px;letter-spacing:1px;}'
        + 'table.pt{width:100%;border-collapse:collapse;font-size:12px;margin-top:6px;table-layout:fixed;}'
        + 'table.pt th,table.pt td{border:1px solid #333;padding:4px 5px;text-align:center;'
        + 'word-wrap:break-word;overflow-wrap:break-word;line-height:1.4;vertical-align:middle;}'
        + 'table.pt th{background:#EFEFEF;font-weight:bold;}'
        + 'table.pt td.l{text-align:left;vertical-align:top;}'
        /* 型態／類別／管道是單行的勾選格，td.l 讓它靠上會整格浮在上緣、跟右邊的日期對不齊；
           溝通問題／回覆內容那張表仍維持 td.l 的靠上（多行文字本來就該靠上），所以另開一個 class */
        + 'table.pt td.l.mid{vertical-align:middle;}'
        /* 圖章尺寸一律抄 ai-rules/18 鐵則6 這一行，不要自己另外發明數字 */
        + '.stamp-wrap svg,svg.car-stamp{width:91px;height:91px;-webkit-print-color-adjust:exact;print-color-adjust:exact;}'
        + '.pt-foot{position:fixed;right:8mm;bottom:5mm;font-size:9pt;color:#333;}'
        + (extraCss || '');
    var w = window.open('', '_blank');
    if (!w){ alert('請允許彈出視窗'); return; }
    var onloadJs = showPageCounter
        ? ('var onePageA4=(' + (landscape ? '210' : '297') + '-28)*96/25.4;'
         + 'if(document.body.scrollHeight>onePageA4*0.92){var st=document.createElement(\'style\');'
         + 'st.textContent="@page{ @bottom-left{ content:\'第 \' counter(page) \' 頁／共 \' counter(pages) \' 頁\'; font-size:9pt; color:#333; } }";'
         + 'document.head.appendChild(st);}')
        : '';
    // <!DOCTYPE html> 不可省略：少了它會落入 Quirks Mode，scrollHeight 量不準、單頁判斷會失準
    w.document.write('<!DOCTYPE html><html><head><meta charset="utf-8"><title>' + esc(title) + '</title><style>' + css
        + '</style></head><body>' + bodyHtml
        + (asHtml ? '<div class="pt-foot">' + asHtml + '</div>' : '')
        + '<scr' + 'ipt>window.onload=function(){' + onloadJs + 'setTimeout(function(){window.print();},250);};</scr' + 'ipt></body></html>');
    w.document.close();
    return w;
}
function printHead(meta, fallbackTitle){
    return '<div class="pt-head"><div class="co">' + esc(meta.company) + '</div>'
         + '<div class="tt">' + esc(meta.doc_name || fallbackTitle) + '</div></div>';
}
/* 掃描實體章的對照表是非同步載入的，沒等它就會把有實體章的人印成預設 SVG 章、跟畫面上看到的不一樣 */
function whenStampReady(fn){
    try { if (window.EGStamp && EGStamp.whenReady){ EGStamp.whenReady(fn); return; } } catch(e){}
    fn();
}

function printRecord(id){
    get({action:'rec_get', id:id}, function(res){
        whenStampReady(function(){
            var r = res.rec, meta = res.print;
            var mark = function(on){ return on ? '■' : '□'; };
            var chs = [];
            $.each(META.channels, function(k, v){
                chs.push(mark(+r['ch_' + k]) + (k === 'other' ? '其他：' + esc(r.ch_other_text || '') : esc(v)));
            });
            var kinds = [];
            $.each(META.kinds, function(k, v){
                kinds.push(mark(r.party_kind === k) + (k === 'other' ? '其他：' + esc(r.party_kind_other || '') : esc(v)));
            });
            var types = [];
            $.each(META.types, function(k, v){ types.push(mark(r.comm_type === k) + esc(v)); });

            var items = (res.items || []).map(function(x, i){
                return '<tr><td style="width:34px;">' + (i + 1) + '</td><td class="l">' + esc(x.question).replace(/\n/g, '<br>')
                     + '</td><td class="l">' + esc(x.reply).replace(/\n/g, '<br>') + '</td></tr>';
            }).join('');
            // 紙本固定 5 列，不足補空白列（維持表單的書寫版面）
            for (var i = (res.items || []).length; i < 5; i++)
                items += '<tr><td>' + (i + 1) + '</td><td>&nbsp;</td><td>&nbsp;</td></tr>';

            var mgrCell = +r.mgr_skip
                ? '<div style="font-size:11px;color:#333;padding-top:22px;">（填表人為單位主管，本格免簽）</div>'
                : (r.mgr_name ? stampHtml(r.mgr_name, r.mgr_date, +r.mgr_deputy, r.mgr_dept_name, r.mgr_pos_name) : '&nbsp;');
            var gmCell = r.gm_name ? stampHtml(r.gm_name, r.gm_date, +r.gm_deputy) : '&nbsp;';

            var body = printHead(meta, '利害關係者溝通記錄表')
                + '<table class="pt">'
                + '<colgroup><col style="width:80px;"><col><col style="width:150px;"><col style="width:230px;"></colgroup>'
                + '<tr><th>型態</th><td class="l mid">' + types.join('　') + '</td>'
                + '<th>溝通日期</th><td>' + dispDate(r.comm_date) + '</td></tr>'
                + '<tr><th>類別</th><td class="l mid">' + kinds.join('　') + '</td>'
                + '<th>利害關係者<br>公司/代表人</th><td>' + esc(r.party_name) + '</td></tr>'
                + '<tr><th>管道</th><td class="l mid">' + chs.join('　') + '</td>'
                + '<th>填表人/部門</th><td>' + esc((r.maker_dept_name || '') + '　' + (r.maker_name || '')) + '</td></tr>'
                + '</table>'
                + '<table class="pt" style="margin-top:0;">'
                + '<colgroup><col style="width:34px;"><col style="width:48%;"><col></colgroup>'
                + '<thead><tr><th colspan="2">溝通問題(可依實際需求增列問題)</th><th>回覆內容</th></tr></thead>'
                + '<tbody>' + items + '</tbody></table>'
                + '<table class="pt" style="margin-top:0;">'
                + '<tr><th style="width:120px;">總經理確認：</th><td style="height:100px;">' + gmCell + '</td>'
                + '<th style="width:200px;">部門主管確認：<br><span style="font-weight:normal;font-size:10px;">(若由主管填寫則此格免簽)</span></th>'
                + '<td style="height:100px;">' + mgrCell + '</td></tr></table>'
                + (r.remark ? '<div style="font-size:11px;margin-top:6px;">備註：' + esc(r.remark) + '</div>' : '');

            egPrintWindow('溝通記錄表 ' + r.rec_no, body, '', meta.doc_no, true);
            EGPrintLog.record({source:'comm_mgmt', doc_name:'利害關係者溝通記錄表 ' + r.rec_no,
                doc_kind:'form', ref_table:'comm_record', ref_id:r.rec_id});
        });
    });
}
$('#btnRecPrint').on('click', function(){ if (REC && REC.rec) printRecord(REC.rec.rec_id); });

/* 清單型列印＝印出目前篩選的全部結果（不是只有這一頁），所以重新向後端要 per=0 的全量 */
$('#btnTrackPrint').on('click', function(){
    get({action:'track_list', page:1, per:50, show:$('#trackShow').val(), kw:$('#trackKw').val()}, function(first){
        var total = first.total, per = 50, pages = Math.ceil(total / per) || 1, rows = [], done = 0;
        var render = function(){
            var meta = first.print;
            var tr = rows.map(function(r, i){
                return '<tr><td>' + (i + 1) + '</td><td class="l">' + esc(r.party) + '</td>'
                     + '<td class="l">' + esc(r.content).replace(/\n/g, '<br>') + '</td>'
                     + '<td>' + dispDate(r.react_date) + '</td>'
                     + '<td class="l">' + esc(r.action).replace(/\n/g, '<br>') + '</td>'
                     + '<td>' + esc(r.owner_name) + '</td><td>' + dispDate(r.due_date) + '</td>'
                     + '<td>' + (+r.is_closed ? '是' : '否') + '</td></tr>';
            }).join('') || '<tr><td colspan="8">（無資料）</td></tr>';
            var body = printHead(meta, '回應利害關係者措施追蹤表')
                + '<table class="pt"><colgroup><col style="width:40px;"><col style="width:120px;"><col>'
                + '<col style="width:76px;"><col><col style="width:80px;"><col style="width:86px;"><col style="width:60px;"></colgroup>'
                + '<thead><tr><th>項次</th><th>利害關係者</th><th>反應內容</th><th>反應日期</th>'
                + '<th>回應措施</th><th>負責人</th><th>預計完成日</th><th>是否結案</th></tr></thead>'
                + '<tbody>' + tr + '</tbody></table>';
            egPrintWindow('回應利害關係者措施追蹤表', body, '', meta.doc_no, true);
            EGPrintLog.record({source:'comm_mgmt', doc_name:'回應利害關係者措施追蹤表（' + rows.length + ' 筆）',
                doc_kind:'form', ref_table:'comm_track'});
        };
        for (var p = 1; p <= pages; p++){
            (function(pp){
                get({action:'track_list', page:pp, per:per, show:$('#trackShow').val(), kw:$('#trackKw').val()}, function(res){
                    rows = rows.concat(res.rows.map(function(x){ return $.extend({__p:pp}, x); }));
                    if (++done === pages){
                        rows.sort(function(a, b){ return a.__p - b.__p; });
                        render();
                    }
                });
            })(p);
        }
    });
});
$('#btnCtrlPrint').on('click', function(){
    get({action:'ctrl_list', page:1, per:50, kw:$('#ctrlKw').val()}, function(first){
        var total = first.total, per = 50, pages = Math.ceil(total / per) || 1, rows = [], done = 0;
        var render = function(){
            var meta = first.print;
            var tr = rows.map(function(r){
                return '<tr><td>' + esc(r.maker_name) + '</td><td class="l">' + esc(r.party) + '</td>'
                     + '<td class="l">' + esc(r.content).replace(/\n/g, '<br>') + '</td>'
                     + '<td class="l">' + esc(r.channel) + '</td><td>' + esc(r.freq_text || r.freq) + '</td>'
                     + '<td>' + (r.next_due_date ? dispDate(r.next_due_date) : '') + '</td></tr>';
            }).join('') || '<tr><td colspan="6">（無資料）</td></tr>';
            var body = printHead(meta, '溝通管制表')
                + '<table class="pt"><thead><tr><th style="width:90px;">填表人</th><th style="width:130px;">利害關係人</th>'
                + '<th>溝通內容</th><th style="width:110px;">溝通管道</th><th style="width:100px;">頻率</th>'
                + '<th style="width:96px;">下次應溝通日</th></tr></thead>'
                + '<tbody>' + tr + '</tbody></table>';
            egPrintWindow('溝通管制表', body, '', meta.doc_no, false);
            EGPrintLog.record({source:'comm_mgmt', doc_name:'溝通管制表（' + rows.length + ' 筆）',
                doc_kind:'form', ref_table:'comm_ctrl'});
        };
        for (var p = 1; p <= pages; p++){
            (function(pp){
                get({action:'ctrl_list', page:pp, per:per, kw:$('#ctrlKw').val()}, function(res){
                    rows = rows.concat(res.rows.map(function(x){ return $.extend({__p:pp}, x); }));
                    if (++done === pages){ rows.sort(function(a, b){ return a.__p - b.__p; }); render(); }
                });
            })(p);
        }
    });
});

/* ================= CSV 匯出（一律以全部符合篩選的資料為準，不是只有這一頁） ================= */
function csvDump(name, head, rows){
    var lines = [head.join(',')];
    rows.forEach(function(r){ lines.push(r.map(function(c){ return '"' + String(c == null ? '' : c).replace(/"/g, '""') + '"'; }).join(',')); });
    var blob = new Blob(["﻿" + lines.join('\r\n')], {type:'text/csv;charset=utf-8;'});
    var a = document.createElement('a');
    a.href = URL.createObjectURL(blob); a.download = name + '_' + TODAY + '.csv';
    document.body.appendChild(a); a.click(); document.body.removeChild(a);
}
function fetchAll(base, cb){
    get($.extend({}, base, {page:1, per:50}), function(first){
        var pages = Math.ceil(first.total / 50) || 1, rows = [], done = 0;
        for (var p = 1; p <= pages; p++){
            (function(pp){
                get($.extend({}, base, {page:pp, per:50}), function(res){
                    rows = rows.concat(res.rows.map(function(x){ return $.extend({__p:pp}, x); }));
                    if (++done === pages){ rows.sort(function(a, b){ return a.__p - b.__p; }); cb(rows); }
                });
            })(p);
        }
    });
}
$('#btnRecCsv').on('click', function(){
    fetchAll(recFilters(), function(rows){
        csvDump('利害關係者溝通記錄表', ['單號','溝通日期','型態','類別','利害關係者','代表人','填表人','部門','職稱','管道','附件數','附件','狀態','部門主管','總經理','備註'],
            rows.map(function(r){
                var chs = [];
                $.each(META.channels, function(k, v){ if (+r['ch_' + k]) chs.push(k === 'other' ? (r.ch_other_text || v) : v); });
                var kind = META.kinds[r.party_kind] || '';
                if (r.party_kind === 'other') kind = r.party_kind_other || kind;
                var atts = r.attaches || [];
                return [r.rec_no, dispDate(r.comm_date), META.types[r.comm_type] || '', kind, r.party_name,
                    r.party_contact_name || '',
                    r.maker_name, r.maker_dept_name, r.maker_pos_name, chs.join('、'),
                    atts.length, atts.map(attDisplayName).join('、'),
                    (+r.mgr_skip ? '（主管格免簽）' : '') + (META.status[r.status] || ''),
                    r.mgr_name || '', r.gm_name || '', r.remark || ''];
            }));
    });
});
$('#btnTrackCsv').on('click', function(){
    fetchAll({action:'track_list', show:$('#trackShow').val(), kw:$('#trackKw').val()}, function(rows){
        csvDump('回應利害關係者措施追蹤表', ['項次','利害關係者','反應內容','反應日期','回應措施','負責人','預計完成日','是否結案','結案日期','來源單號'],
            rows.map(function(r, i){
                return [i + 1, r.party, r.content, dispDate(r.react_date), r.action, r.owner_name,
                    dispDate(r.due_date), +r.is_closed ? '是' : '否', dispDate(r.closed_date), r.src_rec_no || ''];
            }));
    });
});
$('#btnCtrlCsv').on('click', function(){
    fetchAll({action:'ctrl_list', kw:$('#ctrlKw').val()}, function(rows){
        csvDump('溝通管制表',
            ['項次','填表人','部門','類別','利害關係人','溝通內容','溝通管道','頻率','下次應溝通日','自動提醒','提前天數','提醒時間','提醒對象','備註'],
            rows.map(function(r, i){
                var kind = r.party_kind === 'other' ? (r.party_kind_other || '其他') : (META.kinds[r.party_kind] || '');
                return [i + 1, r.maker_name, r.maker_dept_name || '', kind, r.party, r.content, r.channel,
                    r.freq_text || r.freq, dispDate(r.next_due_date),
                    +r.remind_enabled ? '是' : '否',
                    +r.remind_enabled ? (+r.remind_lead_days || 0) : '',
                    +r.remind_enabled ? String(r.remind_time || '').substr(0, 5) : '',
                    (r.target_labels || []).join('、'), r.remark || ''];
            }));
    });
});

<?php if ($perms['uid']): ?>
boot();
<?php endif; ?>
</script>
</body>
</html>
