<?php
/**
 * 專案管理（2-GM-02 專案管理程序）— 全新頁面（2026-08-20 建立）
 * 紙本：FOR CODEING 說明文件/AS9100(各組維護版)/總經理室/總經理室 2-GM/2-GM-02-專案管理程序/
 *       2-GM-02-02-專案執行規劃表.xlsx、2-GM-02-03-專案管理卡.xlsx
 *       ※ 2-GM-02-01 專案計劃需求表依使用者決定不建置（改由「訂單轉專案」立案，程序書另行改版廢止）
 * 資料一律走 src/store/Project_API.php；共用邏輯 src/common/project_lib.php
 * 甘特：畫面用橫條時間軸（預計淺條／實際深條疊放、今日線、逾期紅標、里程碑菱形）；
 *       列印仍用紙本的格狀周期表，維持 AS 文件 1:1。
 */
session_start();
if (!isset($_SESSION['userName'])) {
    $_SESSION['lastpage'] = "../../views/GM/project_mgmt.php";
    header("Location:../../index.php");
    exit;
}
include_once '../../src/common/_config.php';
include_once '../../src/common/DBConnection.php';
include_once '../../src/common/project_lib.php';

$db = (new DBConnection())->getPDO();
prj_ensure_schema($db);
$prjUser = prj_current_user($db);
$perms   = prj_perms($db, $prjUser);
$roleLabel = $perms['isAdmin'] ? '管理者'
           : ($perms['canAdmin'] ? '專案管理員'
           : ($perms['canEdit'] ? '專案登錄' : ($perms['canView'] ? '專案檢閱' : '無角色')));
$av = static fn(string $p): string => (string)@filemtime(__DIR__ . '/../../' . $p);
?>
<!DOCTYPE html>
<html lang="zh-Hant">
<head>
    <meta charset="utf-8">
    <meta http-equiv="X-UA-Compatible" content="IE=edge">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>專案管理</title>
    <link href="../../resource/css/bootstrap.css" rel="stylesheet">
    <link href="../../resource/css/font-awesome.css" rel="stylesheet">
    <link href="../../resource/css/nprogress.css" rel="stylesheet">
    <link href="../../resource/css/custom.css" rel="stylesheet">
    <style>
        #sidebar-menu { visibility: hidden; }
        .right_col .page-title { margin:8px 0 4px; overflow:hidden; clear:both; }
        /* ── 使用說明鈕（全站統一，照抄 vendor_audit.php＝鐵律7）── */
        .page-help-btn { height:30px; font-size:13px; padding:0 12px; border:1px solid #d98a33; border-radius:15px;
            background:#F0A24B; color:#fff; cursor:pointer; }
        .page-help-btn:hover { background:#d98a33; }
        @media print { .page-help-btn { display:none !important; } }
        .help-doc { font-size:13px; color:#5b3a1e; line-height:1.75; }
        .help-doc h4 { color:#8A5A2B; border-bottom:2px solid #F7E0BD; padding-bottom:3px; margin:14px 0 6px; font-size:15px; }
        .help-doc h4:first-child { margin-top:0; }
        .help-doc b { color:#8A5A2B; }
        .help-doc ul { margin:4px 0 8px; padding-left:20px; }
        .help-doc li { margin:2px 0; }

        /* ── 工具列 ── */
        .pj-toolbar { display:flex; flex-wrap:wrap; gap:6px; align-items:center; clear:both;
            border:1.5px solid #E8D5B5; border-radius:8px; padding:8px 10px; margin-bottom:10px; background:#FDF8EF; }
        .pj-toolbar label { margin:0; font-size:13px; color:#5b3a1e; }
        .pj-toolbar select, .pj-toolbar input, .pj-toolbar button {
            height:30px; font-size:13px; line-height:1; padding:0 10px; border:1px solid #D8BE93;
            border-radius:4px; background:#fff; color:#5b3a1e; }
        .pj-toolbar button { cursor:pointer; }
        .pj-toolbar button:hover { background:#F7E0BD; }
        .pj-toolbar .btn-warm { background:#F0A24B; color:#fff; border-color:#d98a33; }
        .pj-toolbar .btn-warm:hover { background:#d98a33; }
        .pj-role-badge { margin-left:auto; font-size:13px; color:#5b3a1e; background:#F7E0BD; border-radius:12px; padding:4px 12px; }

        /* ── 標籤（自訂，可按標籤篩選）── */
        .pj-tagbar { display:flex; flex-wrap:wrap; gap:5px; align-items:center; margin:0 0 10px; }
        .pj-tag { display:inline-block; padding:2px 10px; border-radius:11px; font-size:12px; cursor:pointer;
            border:1px solid #E0C9A2; background:#FBF3E6; color:#8A5A2B; white-space:nowrap; }
        .pj-tag.on { background:#F0A24B; color:#fff; border-color:#d98a33; }
        .pj-tag.ro { cursor:default; }

        /* ── 表格 ── */
        .pj-table-wrap { overflow-x:auto; border:1px solid #E8D5B5; border-radius:6px; background:#fff; }
        table.pj-table { width:100%; border-collapse:collapse; font-size:13px; }
        table.pj-table th, table.pj-table td { border:1px solid #EADFC8; padding:5px 8px; text-align:center; }
        table.pj-table thead th { background:#F7E0BD; color:#5b3a1e; font-weight:bold; white-space:nowrap; }
        table.pj-table tbody tr:nth-child(even) { background:#FBF6EC; }
        table.pj-table tbody tr:hover { background:#F7E9D2; }
        table.pj-table td.l { text-align:left; }
        .pj-op { color:#b5762a; cursor:pointer; margin:0 4px; white-space:nowrap; }
        .pj-op:hover { color:#8A5A2B; text-decoration:underline; }
        .pj-pager { display:flex; justify-content:flex-end; align-items:center; gap:6px; margin:6px 0; font-size:13px; color:#5b3a1e; }
        .pj-pager button { height:26px; padding:0 9px; border:1px solid #D8BE93; background:#fff; border-radius:4px; cursor:pointer; }
        .pj-pager button.on { background:#F0A24B; color:#fff; border-color:#d98a33; }

        /* ── 狀態燈號（暖色系固定調色盤，ai-rules/10）── */
        .st { display:inline-block; padding:1px 9px; border-radius:10px; font-size:12px; white-space:nowrap; }
        .st-draft     { background:#EFE7D8; color:#6b5535; }
        .st-submitted { background:#F7E0BD; color:#8A5A2B; }
        .st-approved  { background:#F0A24B; color:#fff; }
        .st-rejected  { background:#DD5138; color:#fff; }
        .st-closed    { background:#C8AE86; color:#fff; }
        .st-terminated{ background:#9C8064; color:#fff; }
        .ph { display:inline-block; padding:1px 8px; border-radius:9px; font-size:12px; background:#F3E4CB; color:#8A5A2B; }
        .pj-alert-badge { display:inline-block; min-width:18px; padding:0 6px; border-radius:9px; background:#DD5138;
            color:#fff; font-size:11px; line-height:18px; }
        .pj-miss-badge { display:inline-block; min-width:18px; padding:0 6px; border-radius:9px; background:#F0A24B;
            color:#fff; font-size:11px; line-height:18px; }
        .pj-ok-badge { color:#7a6446; font-size:12px; }

        /* ── 進度條 ── */
        .pj-bar { position:relative; height:14px; border-radius:7px; background:#F1E6D3; overflow:hidden; min-width:70px; }
        .pj-bar > i { position:absolute; left:0; top:0; bottom:0; background:#F0A24B; display:block; }
        .pj-bar > span { position:absolute; inset:0; font-size:11px; line-height:14px; color:#5b3a1e; text-align:center; }

        /* ── 跳窗（寬度一律固定像素，禁用 vw：會蓋過側邊選單）── */
        .pj-mask { display:none; position:fixed; inset:0; background:rgba(60,40,20,.45); z-index:9000; overflow:auto; }
        .pj-modal { background:#fff; border-radius:8px; margin:24px auto; max-width:1180px; width:96%; box-shadow:0 8px 30px rgba(0,0,0,.3); }
        .pj-modal.narrow { max-width:520px; }
        .pj-modal.mid { max-width:780px; }
        .m-head { background:#F7E0BD; color:#5b3a1e; font-weight:bold; padding:9px 14px; border-radius:8px 8px 0 0; display:flex; }
        .m-close { margin-left:auto; cursor:pointer; }
        .m-body { padding:14px; max-height:76vh; overflow:auto; }
        .m-foot { padding:10px 14px; border-top:1px solid #EADFC8; text-align:right; }
        .m-foot button { height:32px; padding:0 14px; border-radius:4px; border:1px solid #D8BE93; background:#fff; color:#5b3a1e; cursor:pointer; }
        .m-foot .b-ok { background:#F0A24B; color:#fff; border-color:#d98a33; margin-left:6px; }
        .m-foot .b-danger { background:#DD5138; color:#fff; border-color:#C4442D; }
        .m-body label { display:block; font-size:13px; color:#5b3a1e; margin:0 0 3px; font-weight:normal; }
        .m-body input[type=text], .m-body input[type=date], .m-body input[type=number], .m-body select, .m-body textarea {
            width:100%; border:1px solid #D8BE93; border-radius:4px; padding:5px 8px; font-size:13px; color:#5b3a1e; }
        .m-body textarea { resize:vertical; }
        .ro-auto { background:#F3EADB; color:#7a6446; }
        .pj-hint { font-size:12px; color:#8a6d45; line-height:1.7; }
        .pj-err { color:#DD5138; font-size:12px; margin-top:2px; display:none; }
        .fld-bad input, .fld-bad select, .fld-bad textarea { border-color:#DD5138 !important; background:#FDF1EE; }
        .grid2 { display:grid; grid-template-columns:1fr 1fr; gap:10px; }
        .grid3 { display:grid; grid-template-columns:1fr 1fr 1fr; gap:10px; }
        .grid4 { display:grid; grid-template-columns:1fr 1fr 1fr 1fr; gap:10px; }
        .sec { border:1px solid #EADFC8; border-radius:6px; padding:10px; margin-bottom:12px; background:#FDFAF4; }
        .sec > h5 { margin:0 0 8px; font-size:14px; color:#8A5A2B; font-weight:bold; border-bottom:1px solid #F0E2C7; padding-bottom:4px; }
        table.sub-tbl { width:100%; border-collapse:collapse; font-size:13px; background:#fff; }
        table.sub-tbl th, table.sub-tbl td { border:1px solid #EADFC8; padding:3px 5px; text-align:center; }
        table.sub-tbl th { background:#F7E0BD; color:#5b3a1e; white-space:nowrap; }
        table.sub-tbl input, table.sub-tbl select, table.sub-tbl textarea {
            width:100%; border:1px solid #E8D5B5; border-radius:3px; padding:3px 5px; font-size:13px; color:#5b3a1e; }
        table.sub-tbl textarea { resize:vertical; min-height:26px; }

        /* ── 分頁 ── */
        .pj-tabs { display:flex; gap:4px; flex-wrap:wrap; border-bottom:2px solid #E8D5B5; margin-bottom:10px; }
        .pj-tab { height:32px; padding:0 14px; font-size:13px; border:1px solid #E8D5B5; border-bottom:none;
            border-radius:6px 6px 0 0; background:#FBF3E6; color:#8A5A2B; cursor:pointer; }
        .pj-tab.active { background:#F0A24B; color:#fff; border-color:#d98a33; }
        .pj-pane { display:none; }
        .pj-pane.active { display:block; }

        /* ══ 甘特時間軸（畫面用；列印另有格狀周期表）══ */
        .gantt-wrap { border:1px solid #E8D5B5; border-radius:6px; background:#fff; overflow-x:auto; }
        .gantt { min-width:760px; font-size:12px; position:relative; }
        .gantt-row { display:flex; align-items:stretch; border-bottom:1px solid #F2E7D4; }
        .gantt-row:last-child { border-bottom:none; }
        .gantt-row.goal { background:#FBF3E6; font-weight:bold; color:#8A5A2B; }
        .gantt-head { background:#F7E0BD; color:#5b3a1e; font-weight:bold; position:sticky; top:0; z-index:3; }
        .gantt-lbl { flex:0 0 260px; padding:5px 8px; border-right:1px solid #E8D5B5; background:inherit;
            position:sticky; left:0; z-index:2; overflow:hidden; text-overflow:ellipsis; white-space:nowrap; }
        .gantt-own { flex:0 0 74px; padding:5px 4px; border-right:1px solid #E8D5B5; text-align:center;
            background:inherit; overflow:hidden; white-space:nowrap; }
        .gantt-track { flex:1 1 auto; position:relative; min-height:30px; }
        .gantt-grid { position:absolute; inset:0; }
        .gantt-grid i { position:absolute; top:0; bottom:0; width:1px; background:#F2E7D4; }
        .gantt-grid i.mon { background:#E6D5B8; }
        .gantt-tick { position:absolute; top:2px; font-size:11px; color:#8a6d45; white-space:nowrap;
            transform:translateX(-50%); }
        .g-plan { position:absolute; top:7px; height:8px; border-radius:4px; background:#F7E0BD; border:1px solid #E0C9A2; }
        .g-act  { position:absolute; top:15px; height:9px; border-radius:5px; background:#C97B2E; }
        .g-act.late { background:#DD5138; }
        .g-ms { position:absolute; top:9px; width:12px; height:12px; background:#8A5A2B; transform:rotate(45deg) translateX(-50%);
            transform-origin:left center; border-radius:2px; }
        .g-today { position:absolute; top:0; bottom:0; width:2px; background:#DD5138; z-index:4; pointer-events:none; }
        .g-today-lbl { position:absolute; top:0; font-size:10px; color:#DD5138; background:#fff; padding:0 3px;
            transform:translateX(-50%); z-index:5; }
        .gantt-legend { display:flex; gap:14px; flex-wrap:wrap; align-items:center; font-size:12px; color:#5b3a1e; margin:6px 2px; }
        .gantt-legend span { display:inline-flex; align-items:center; gap:4px; }
        .gantt-legend em { display:inline-block; width:22px; height:8px; border-radius:4px; font-style:normal; }

        /* ── 文件檢核 ── */
        .chk-y { color:#2F7D4F; font-weight:bold; }
        .chk-n { color:#DD5138; font-weight:bold; cursor:pointer; text-decoration:underline; }
        .pj-alertbar { border:1.5px solid #F0A24B; background:#FDF3E4; border-radius:6px; padding:8px 12px;
            margin-bottom:10px; font-size:13px; color:#5b3a1e; }
        .pj-alertbar .it { display:block; padding:2px 0; border-bottom:1px dashed #EADFC8; }
        .pj-alertbar .it:last-child { border-bottom:none; }

        .pj-noperm { border:1.5px solid #E8D5B5; background:#FDF8EF; border-radius:8px; padding:24px; color:#5b3a1e; }
        .pj-totop { position:fixed; right:24px; bottom:24px; width:40px; height:40px; border-radius:20px; background:#F0A24B;
            color:#fff; border:none; font-size:18px; cursor:pointer; display:none; z-index:8000; }
        @media print { .pj-toolbar, .pj-tabs, .pj-totop, .nav_menu, .left_col, footer { display:none !important; } }
    </style>
</head>
<body class="nav-sm">
<div class="container body">
<div class="main_container">
    <?php include '../partPage/sideAndTopBarMenu.html' ?>
    <div class="right_col" role="main">
        <div class="page-title" style="display:flex;align-items:center;flex-wrap:wrap;">
            <h2 style="margin:6px 0;">專案管理
                <small style="color:#8a6d45;">2-GM-02 專案管理程序｜執行規劃表 2-GM-02-02、專案管理卡 2-GM-02-03</small></h2>
            <button id="btnPageHelp" class="page-help-btn" style="margin-left:auto;"><i class="fa fa-question-circle"></i> 使用說明</button>
        </div>
        <div class="clearfix"></div>

<?php if (!$perms['canView']): ?>
        <div class="pj-noperm">
            <h4><i class="fa fa-lock"></i> 無專案管理檢閱權限</h4>
            <p>請洽管理者於「使用者權限設定」指派「專案檢閱／專案登錄／專案管理員」角色。</p>
        </div>
<?php else: ?>
        <div class="pj-toolbar">
            <input type="text" id="fKw" placeholder="搜尋 專案代號/名稱/客戶/負責人/料號/訂單號…" style="width:280px;">
            <select id="fType"><option value="">全部類型</option></select>
            <select id="fPhase"><option value="">全部階段</option></select>
            <select id="fStatus">
                <option value="">全部狀態</option>
                <option value="draft">草稿</option>
                <option value="submitted">已送簽</option>
                <option value="approved">已核准</option>
                <option value="rejected">已退回</option>
                <option value="closed">已結案</option>
            </select>
            <select id="fOwner" data-eg-filter="輸入姓名篩選…"><option value="">全部負責人</option></select>
            <button id="btnSearch" class="btn-warm"><i class="fa fa-search"></i> 查詢</button>
            <button id="btnReset"><i class="fa fa-refresh"></i> 清除</button>
            <button id="btnOrderToPrj" class="btn-warm"<?= $perms['canEdit'] ? '' : ' style="display:none;"' ?>>
                <i class="fa fa-random"></i> 訂單轉專案</button>
            <button id="btnNew"<?= $perms['canEdit'] ? '' : ' style="display:none;"' ?>><i class="fa fa-plus"></i> 手動建立</button>
            <button id="btnCsv"><i class="fa fa-file-excel-o"></i> CSV</button>
            <button id="btnOverview"><i class="fa fa-th-list"></i> 跨專案總覽</button>
            <button id="btnPhrase"<?= $perms['canEdit'] ? '' : ' style="display:none;"' ?>><i class="fa fa-commenting-o"></i> 常用語句</button>
            <button id="btnTags"<?= $perms['canAdmin'] ? '' : ' style="display:none;"' ?>><i class="fa fa-tags"></i> 標籤設定</button>
            <button id="btnSetting"<?= $perms['canAdmin'] ? '' : ' style="display:none;"' ?>><i class="fa fa-cog"></i> 模組設定</button>
            <span class="pj-role-badge">角色：<?= htmlspecialchars($roleLabel) ?></span>
        </div>

        <div class="pj-tagbar" id="tagFilterBar"></div>

        <div class="pj-pager">
            <label>每頁</label>
            <select id="pgSize" style="height:26px;"><option>5</option><option selected>10</option><option>20</option><option>50</option></select>
            <span id="pgInfo"></span>
            <span id="pgBtns"></span>
        </div>
        <div class="pj-table-wrap">
            <table class="pj-table" id="listTable">
                <thead><tr>
                    <th style="width:80px;">專案代號</th><th style="width:46px;">類型</th>
                    <th>專案名稱</th><th style="width:110px;">客戶</th><th style="width:78px;">負責人</th>
                    <th style="width:58px;">階段</th><th style="width:70px;">狀態</th>
                    <th style="width:150px;">期間</th><th style="width:92px;">進度</th>
                    <th style="width:52px;">訂單</th><th style="width:52px;">料號</th>
                    <th style="width:66px;">文件</th><th style="width:56px;">BOM</th>
                    <th style="width:130px;">操作</th>
                </tr></thead>
                <tbody id="listBody"><tr><td colspan="14" style="padding:18px;color:#8a6d45;">載入中…</td></tr></tbody>
            </table>
        </div>
<?php endif; ?>
    </div>
</div>
</div>

<!-- ══════════ 專案詳情（分頁） ══════════ -->
<div class="pj-mask" id="prjMask"><div class="pj-modal">
    <div class="m-head"><span id="prjTitle">專案</span><span class="m-close" onclick="closeProject()">✕</span></div>
    <div class="m-body">
        <div id="prjAlertBar"></div>
        <div class="pj-tabs">
            <button class="pj-tab active" data-pane="paneBase"><i class="fa fa-info-circle"></i> 基本資料</button>
            <button class="pj-tab" data-pane="panePlan"><i class="fa fa-calendar"></i> 執行規劃表</button>
            <button class="pj-tab" data-pane="paneCard"><i class="fa fa-id-card-o"></i> 專案管理卡</button>
            <button class="pj-tab" data-pane="paneRel"><i class="fa fa-link"></i> 關聯資料</button>
            <button class="pj-tab" data-pane="paneChk"><i class="fa fa-check-square-o"></i> 文件檢核 <span id="chkBadge"></span></button>
            <button class="pj-tab" data-pane="paneSign"><i class="fa fa-pencil-square-o"></i> 會簽／核准</button>
        </div>
        <div class="pj-pane active" id="paneBase"></div>
        <div class="pj-pane" id="panePlan"></div>
        <div class="pj-pane" id="paneCard"></div>
        <div class="pj-pane" id="paneRel"></div>
        <div class="pj-pane" id="paneChk"></div>
        <div class="pj-pane" id="paneSign"></div>
    </div>
    <div class="m-foot" id="prjFoot"></div>
</div></div>

<!-- ══════════ 訂單轉專案 ══════════ -->
<div class="pj-mask" id="o2pMask"><div class="pj-modal">
    <div class="m-head"><span><i class="fa fa-random"></i> 訂單轉專案</span><span class="m-close" onclick="closeMask('o2pMask')">✕</span></div>
    <div class="m-body">
        <div class="sec">
            <h5>一、挑選訂單（只列出尚未被任何專案綁定的訂單）</h5>
            <div class="grid4" style="margin-bottom:8px;">
                <div><label>關鍵字</label><input type="text" id="oKw" placeholder="訂單號/客戶單號/料號/客戶…"></div>
                <div><label>客戶</label><input type="text" id="oCust" placeholder="客戶ID或客戶名稱（模糊，可空白分隔多個關鍵字）"></div>
                <div><label>接單日起</label><input type="date" id="oFrom"></div>
                <div><label>接單日迄</label><input type="date" id="oTo"></div>
            </div>
            <div style="margin-bottom:8px;">
                <label style="display:inline;"><input type="checkbox" id="oClosed" data-eg-skip="1"> 含已結束訂單</label>
                <button id="btnOSearch" style="height:28px;margin-left:10px;border:1px solid #D8BE93;border-radius:4px;background:#F0A24B;color:#fff;cursor:pointer;padding:0 12px;">查詢</button>
                <span id="oCount" class="pj-hint" style="margin-left:10px;"></span>
            </div>
            <div style="max-height:280px;overflow:auto;border:1px solid #EADFC8;border-radius:4px;">
                <table class="sub-tbl" id="oTable">
                    <thead><tr>
                        <th style="width:28px;"><input type="checkbox" id="oCkAll" data-eg-skip="1"></th>
                        <th>訂單編號</th><th>客戶單號</th><th>客戶</th><th>料號</th>
                        <th style="width:60px;">數量</th><th style="width:88px;">接單日</th><th style="width:88px;">交期</th><th>加工製程</th>
                    </tr></thead>
                    <tbody id="oBody"><tr><td colspan="9" style="padding:12px;color:#8a6d45;">請先按「查詢」</td></tr></tbody>
                </table>
            </div>
        </div>
        <div class="sec">
            <h5>二、要轉成哪個專案</h5>
            <div style="margin-bottom:8px;">
                <label style="display:inline;margin-right:14px;"><input type="radio" name="o2pMode" value="new" checked data-eg-skip="1"> 建立新專案</label>
                <label style="display:inline;"><input type="radio" name="o2pMode" value="append" data-eg-skip="1"> 加入既有專案</label>
            </div>
            <div id="o2pNewBox" class="grid3">
                <div><label>專案類型 <span style="color:#DD5138;">*</span></label><select id="o2pType"></select></div>
                <div><label>專案名稱（留空自動以客戶＋料號命名）</label><input type="text" id="o2pName"></div>
                <div><label>專案負責人</label><select id="o2pOwner" data-eg-filter="輸入姓名篩選…"></select></div>
                <div style="grid-column:1 / -1;"><label>專案分類標籤</label><div class="pj-tagbar" id="o2pTagBar"></div></div>
            </div>
            <div id="o2pAppendBox" style="display:none;">
                <label>選擇既有專案</label>
                <select id="o2pPrj" data-eg-filter="輸入專案代號或名稱篩選…"></select>
            </div>
        </div>
    </div>
    <div class="m-foot">
        <button onclick="closeMask('o2pMask')">取消</button>
        <button class="b-ok" id="btnO2pGo"><i class="fa fa-check"></i> 轉入專案</button>
    </div>
</div></div>

<!-- ══════════ 標籤設定 ══════════ -->
<div class="pj-mask" id="tagMask"><div class="pj-modal mid">
    <div class="m-head"><span><i class="fa fa-tags"></i> 標籤設定</span><span class="m-close" onclick="closeMask('tagMask')">✕</span></div>
    <div class="m-body">
        <p class="pj-hint">標籤可自訂，分為<b>專案分類／目標分類／任務分類</b>三種；清單工具列可按標籤篩選。
            已被使用的標籤刪除時會自動改為「停用」（不再出現在挑選清單，既有資料保留），避免既有資料的標籤變成孤兒。</p>
        <div class="sec">
            <h5>新增標籤</h5>
            <div class="grid4">
                <div><label>種類</label><select id="tgKind"></select></div>
                <div><label>名稱 <span style="color:#DD5138;">*</span></label><input type="text" id="tgName"></div>
                <div><label>顏色（暖色系）</label><select id="tgColor"></select></div>
                <div style="display:flex;align-items:flex-end;"><button id="btnTagAdd" style="height:30px;padding:0 14px;border:1px solid #d98a33;border-radius:4px;background:#F0A24B;color:#fff;cursor:pointer;">新增</button></div>
            </div>
            <div class="pj-err" id="tgErr"></div>
        </div>
        <table class="sub-tbl" id="tagTable">
            <thead><tr><th style="width:90px;">種類</th><th>名稱</th><th style="width:74px;">預覽</th>
                <th style="width:60px;">啟用</th><th style="width:96px;">操作</th></tr></thead>
            <tbody id="tagBody"></tbody>
        </table>
    </div>
    <div class="m-foot"><button onclick="closeMask('tagMask')">關閉</button></div>
</div></div>

<!-- ══════════ 常用語句（專案目的／專案目標） ══════════ -->
<div class="pj-mask" id="phMask"><div class="pj-modal mid">
    <div class="m-head"><span><i class="fa fa-commenting-o"></i> 常用語句 － <span id="phTitle"></span></span>
        <span class="m-close" onclick="closeMask('phMask')">✕</span></div>
    <div class="m-body">
        <p class="pj-hint">事先把常寫的句子建好，填「專案目的／專案目標」時按<b>帶入</b>直接填進欄位（帶入後仍可自行修改）。
            欄位裡已經有字時會先問要<b>取代</b>還是<b>另起一行接在後面</b>。
            帶入的是<b>文字複本</b>，所以語句事後被修改或刪除，都不會影響已經填好的專案內容。</p>
        <div class="grid2" style="margin-bottom:10px;">
            <div><label>語句用於</label><select id="phField"></select>
                <div class="pj-hint" id="phFieldHint" style="display:none;">（由欄位旁的「常用語句」進來，已鎖定為該欄位）</div></div>
        </div>
        <table class="sub-tbl" id="phTable">
            <thead><tr><th>語句</th><th style="width:170px;">操作</th></tr></thead>
            <tbody id="phBody"></tbody>
        </table>
        <div class="sec" id="phEditBox" style="margin-top:12px;">
            <h5 id="phFormTitle">新增語句</h5>
            <textarea id="phText" rows="3" placeholder="輸入常用語句…（最多 500 字）"></textarea>
            <div class="pj-err" id="phErr"></div>
            <div style="margin-top:8px;text-align:right;">
                <button id="phCancel" style="display:none;height:30px;padding:0 14px;border:1px solid #D8BE93;border-radius:4px;background:#fff;color:#5b3a1e;cursor:pointer;">取消編輯</button>
                <button id="phSave" style="height:30px;padding:0 14px;margin-left:6px;border:1px solid #d98a33;border-radius:4px;background:#F0A24B;color:#fff;cursor:pointer;">新增</button>
            </div>
        </div>
    </div>
    <div class="m-foot"><button onclick="closeMask('phMask')">關閉</button></div>
</div></div>

<!-- ══════════ 模組設定 ══════════ -->
<div class="pj-mask" id="setMask"><div class="pj-modal mid">
    <div class="m-head"><span><i class="fa fa-cog"></i> 模組設定</span><span class="m-close" onclick="closeMask('setMask')">✕</span></div>
    <div class="m-body">
        <div class="sec">
            <h5>AS 文件編號綁定</h5>
            <p class="pj-hint">綁定後列印版的表頭表單名稱與頁尾右下角編號會自動帶入，<b>版次依該張表單自己的業務日期回推</b>當時生效的版次。</p>
            <div class="grid2">
                <div><label>專案執行規劃表（2-GM-02-02）</label>
                    <div style="display:flex;gap:6px;"><input type="text" id="asPlanTxt" readonly class="ro-auto">
                        <button id="btnAsPlan" style="height:30px;padding:0 12px;border:1px solid #D8BE93;border-radius:4px;background:#fff;cursor:pointer;white-space:nowrap;">挑選</button></div></div>
                <div><label>專案管理卡（2-GM-02-03）</label>
                    <div style="display:flex;gap:6px;"><input type="text" id="asCardTxt" readonly class="ro-auto">
                        <button id="btnAsCard" style="height:30px;padding:0 12px;border:1px solid #D8BE93;border-radius:4px;background:#fff;cursor:pointer;white-space:nowrap;">挑選</button></div></div>
            </div>
        </div>
        <div class="sec">
            <h5>立案核准人</h5>
            <p class="pj-hint">解析順序（ai-rules/19）：①指定人員 ②指定部門內任一職級不低於送出者的主管 ③自動抓送出者的上一級主管
                ④全站「最高核准人員」。解析到的人剛好是送出者本人時自動跳過（球員不可兼裁判）。</p>
            <div class="grid2">
                <div><label>綁定人員（優先）</label><select id="setApUser" data-eg-filter="輸入姓名篩選…"><option value="0">（不指定）</option></select></div>
                <div><label>綁定部門</label><select id="setApDept"><option value="0">（不指定）</option></select></div>
            </div>
        </div>
        <div class="sec">
            <h5>專案負責人資格</h5>
            <p class="pj-hint">指定<b>哪些部門的哪些職稱</b>可以被指派為專案負責人（「訂單轉專案」與「專案基本資料」的負責人下拉只會列出這些人）。
                職稱選「全部職稱」＝該部門全員皆可。<b>一列都沒設＝不限制</b>（全體在職員工都可以）。
                兼任多個部門／職稱的人只要任一組合命中就算合格；<b>既有專案原本的負責人不受影響</b>，設定改嚴也不會讓舊專案存不了檔。<br>
                操作：選部門 → 右邊點選要開放的職稱（可複選）→ 按「加入」。<b>同一個部門會整組取代</b>，要調整就按該列的「修改」把它讀回來改。
                <b>這裡的部門是精確比對、不含子部門</b>（例如選「資材部」不會自動包含生管／採購／倉管組，要開放請各自加一列）。</p>
            <div style="border:1px solid #EADFC8;border-radius:6px;padding:10px;background:#FFFDF8;margin-bottom:10px;">
                <div style="display:flex;gap:10px;align-items:flex-end;flex-wrap:wrap;margin-bottom:6px;">
                    <div style="min-width:200px;"><label>部門</label>
                        <select id="setOwnDept" data-eg-filter="輸入部門名稱篩選…"></select></div>
                    <div style="flex:1;min-width:260px;"><label>職稱（可複選，點一下切換）</label>
                        <div class="pj-tagbar" id="setOwnPosBar" style="margin:0;"></div></div>
                    <div style="display:flex;gap:6px;">
                        <button id="btnOwnScopeAdd" style="height:30px;padding:0 14px;border:1px solid #d98a33;border-radius:4px;background:#F0A24B;color:#fff;cursor:pointer;white-space:nowrap;">加入</button>
                        <button id="btnOwnScopeCancel" style="height:30px;padding:0 12px;border:1px solid #D8BE93;border-radius:4px;background:#fff;color:#5b3a1e;cursor:pointer;display:none;white-space:nowrap;">取消修改</button>
                    </div>
                </div>
                <div class="pj-err" id="setOwnErr"></div>
            </div>
            <table class="sub-tbl" id="ownScopeTable">
                <thead><tr><th style="width:130px;">部門</th><th>可擔任負責人的職稱</th><th style="width:104px;">操作</th></tr></thead>
                <tbody id="ownScopeBody"></tbody>
            </table>
            <p class="pj-hint" id="ownScopeCount" style="margin-top:6px;"></p>
        </div>
        <div class="sec">
            <h5>執行規劃表負責人部門</h5>
            <p class="pj-hint">指定<b>哪些部門</b>會出現在執行規劃表「負責人」的部門下拉裡（避免整間公司的人擠在同一個下拉）。
                填表時是<b>先選部門、再選人</b>；勾一個部門就連同它<b>底下的子部門</b>一起帶出來（例：勾「生產部」會帶出生產1／2／3廠的人），
                所以通常勾上層部門即可。兼任多個部門的人會在<b>每個部門底下各自以該部門的職稱</b>出現。
                <b>一個都沒勾＝不限制</b>（列出全部部門）。這份設定與上面的「專案負責人資格」互不影響。</p>
            <div id="setTaskDeptBox" style="display:flex;flex-wrap:wrap;gap:6px;"></div>
            <p class="pj-hint" id="setTaskDeptCount" style="margin-top:6px;"></p>
        </div>
        <div class="sec">
            <h5>預設會簽單位</h5>
            <p class="pj-hint">送簽時預設勾選這些單位（仍可逐次調整）。會簽人＝該部門主管，並自動經代理人解析。</p>
            <div id="setCosignBox" style="display:flex;flex-wrap:wrap;gap:6px;"></div>
        </div>
        <div class="sec">
            <h5>結案前文件檢核</h5>
            <label style="display:inline;"><input type="checkbox" id="setBlockClose" data-eg-skip="1">
                結案時若專案內料號還有文件未建立，<b>擋下並列出缺什麼</b>（管理員可強制略過）</label>
        </div>
        <div class="sec">
            <h5>圖章模板</h5>
            <p class="pj-hint">留空＝使用系統預設圖章。模板於「圖章管理」頁維護。</p>
            <div class="grid2">
                <div><label>執行規劃表</label><select id="setPlanTpl"><option value="0">（預設圖章）</option></select></div>
                <div><label>管理卡</label><select id="setCardTpl"><option value="0">（預設圖章）</option></select></div>
            </div>
        </div>
    </div>
    <div class="m-foot">
        <button onclick="closeMask('setMask')">取消</button>
        <button class="b-ok" id="btnSetSave"><i class="fa fa-save"></i> 儲存設定</button>
    </div>
</div></div>

<!-- ══════════ 跨專案總覽（內部用，非 AS 表單） ══════════ -->
<div class="pj-mask" id="ovMask"><div class="pj-modal">
    <div class="m-head"><span><i class="fa fa-th-list"></i> 跨專案總覽（內部用，不印 AS 編號）</span><span class="m-close" onclick="closeMask('ovMask')">✕</span></div>
    <div class="m-body" id="ovBody"></div>
    <div class="m-foot">
        <button onclick="closeMask('ovMask')">關閉</button>
        <button class="b-ok" id="btnOvPrint"><i class="fa fa-print"></i> 列印總覽</button>
    </div>
</div></div>

<!-- ══════════ 使用說明（鐵律7） ══════════ -->
<div class="pj-mask" id="helpUseMask"><div class="pj-modal">
    <div class="m-head"><span><i class="fa fa-question-circle"></i> 專案管理 使用說明</span><span class="m-close" onclick="closeMask('helpUseMask')">✕</span></div>
    <div class="m-body help-doc">
        <h4>一、這一頁在做什麼</h4>
        <p>依 <b>2-GM-02 專案管理程序</b> 管理專案的<b>籌備→規劃→執行→控制→結案</b>五個作業流程，並產生兩份 AS 表單：
            <b>專案執行規劃表（2-GM-02-02）</b>與<b>專案管理卡（2-GM-02-03）</b>。
            專案以<b>訂單為主軸</b>，料號由訂單自動帶出，再連動報價單、BOM 製令、製程報工與四份技術文件。</p>
        <p class="pj-hint">※ 2-GM-02-01 專案計劃需求表未建置於本系統（改由「訂單轉專案」立案，程序書另行改版廢止該表）。</p>

        <h4>二、怎麼建立專案</h4>
        <ul>
            <li><b>訂單轉專案（建議）</b>：工具列「訂單轉專案」→ 勾選訂單 →
                可以①單張訂單建新專案 ②多張訂單合併成一個專案 ③把訂單加入既有專案。
                客戶、料號、數量、交期會自動帶入；<b>專案起日＝最早接單日、迄日＝最晚交期</b>（可事後改）。</li>
            <li>挑訂單時的<b>客戶欄位是模糊搜尋</b>（沒有下拉）：打<b>客戶ID或客戶名稱</b>的片段即可，
                空白分隔多個關鍵字＝每個都要命中。改過名的舊訂單也找得到（會一併比對客戶主檔的簡稱與發票全名）。</li>
            <li><b>專案負責人下拉只列出你可以指派的人</b>：<b>專案管理員</b>可以指派任何人；
                <b>其他使用者只能挑自己所屬部門（含兼任，並含該部門的子部門）內的人</b>，預設帶入自己。
                另外還會套用管理員在「模組設定 → 專案負責人資格」以<b>部門×職稱</b>設定的資格（沒設定＝不限制）。</li>
            <li><b>手動建立</b>：還沒有訂單的開發型專案（D）用這個，之後訂單進來再用「訂單轉專案→加入既有專案」併進來。
                沒有訂單時可在「關聯資料」分頁直接掛料號。</li>
            <li><b>一張訂單只能屬於一個專案</b>；重複綁定會被擋下並告訴你它已經在哪個專案。</li>
        </ul>

        <h4>二之二、專案內容與「常用語句」</h4>
        <ul>
            <li>「專案基本資料」分頁的<b>專案內容</b>（程序書 §6.8 籌備階段的提案內容）只需填兩項：
                <b>專案目的</b>與<b>專案目標</b>（專案目標會印在執行規劃表的表頭）。</li>
            <li>兩個欄位右邊都有 <b>常用語句</b>：點開挑一句<b>帶入</b>就填進欄位，帶入後仍可自行修改。
                欄位裡已經有字時會先問要<b>取代</b>、還是<b>另起一行接在後面</b>（按「取消」＝接在後面）。</li>
            <li>語句本身可<b>新增／修改／刪除</b>：在同一個跳窗下方維護，或用工具列的
                <b>「常用語句」</b>先把句子建好再開專案。</li>
            <li>帶入的是<b>文字複本</b>，所以語句事後被改掉或刪掉，<b>都不會動到已經填好的專案內容</b>。</li>
        </ul>

        <h4>三、專案代號怎麼來的</h4>
        <p>依程序書 §6.13 自動產生 <b>7 碼</b>＝類型 1 碼（開發 <b>D</b>／客製 <b>C</b>／生產 <b>P</b>／服務 <b>S</b>）
            ＋西元年後 2 碼＋月 2 碼＋流水 2 碼。例：<code>C260801</code>＝2026 年 8 月第 1 個客製型專案。
            流水碼依「同一類型＋同一年月」遞增。</p>

        <h4>四、執行規劃表（2-GM-02-02）</h4>
        <ul>
            <li>結構是<b>目標 → 底下掛主要任務</b>；每個任務有預計/實際起迄日與負責人。</li>
            <li><b>畫面用甘特時間軸</b>：<span style="background:#F7E0BD;padding:0 6px;border-radius:4px;">淺色條</span>＝預計、
                <span style="background:#C97B2E;color:#fff;padding:0 6px;border-radius:4px;">深色條</span>＝實際、
                <span style="background:#DD5138;color:#fff;padding:0 6px;border-radius:4px;">紅色</span>＝逾期未完成、
                ◆＝里程碑、紅色垂直線＝今天。可切日／週／月刻度，也可切成清單檢視。</li>
            <li><b>列印版仍是紙本的格狀周期表</b>（預計／實際兩列），維持 AS 文件 1:1。</li>
            <li>末列按 <b>↓</b> 自動加一列、空白末列按 <b>↑</b> 自動移除（全站共用規則）。
                <b>新加的一列會自動把上一列的預計完成日當成預計開始日</b>，一路往下排很快。</li>
            <li><b>預計日程只要填兩格</b>：填了<b>預計開始</b>就自動帶出<b>預計完成＝同一天</b>（工作天數 1＝當天來回）。
                多天的任務有兩種填法，兩邊同動：<b>①填工作天數</b>→自動算出預計完成日；<b>②直接填預計完成日</b>→自動反算工作天數。</li>
            <li><b>工作天數只算工作日</b>：預計開始當天算第 1 天，之後<b>週六日與行事曆上的休假日不算、補班日要算</b>
                （行事曆＝左側選單的「行事曆」，與請假、KPI 用的是同一份）。例：週五開始、工作天數 3 天 → 預計完成是下週二。</li>
            <li><b>上一列的預計完成日會自動變成下一列的預計開始日</b>：改了某一列的日期，後面接續的列會跟著往後推；
                <b>你自己動手改過的開始日不會被蓋掉</b>（碰到就停下來，不會把你排好的日期洗掉）。</li>
            <li>下列情形欄位會立刻標紅、存檔也會被擋下（後端同樣會擋，不是只擋畫面）：
                <b>預計完成日早於預計開始日</b>、<b>預計開始日早於「專案基本資料」的專案起日</b>。
                後者要嘛把任務排晚一點，要嘛回基本資料把專案起日提前；<b>改了專案起日，規劃表會立刻重新檢查每一列</b>。</li>
            <li><b>進度% 會自動算</b>：勾著「自動」時，<b>填了實際完成日就是 100%</b>，還沒填就是 0%。
                做到一半想填實際成數（例如 60%）就直接改數字，<b>改過之後那一列就不再自動</b>（「自動」會自己取消勾選），
                要恢復自動再把勾勾回來即可。存檔時後端會再算一次，不採信畫面送上來的數字。</li>
            <li><b>專案整體進度</b>（清單上那條進度條）＝各任務進度<b>依預計工作天數加權平均</b>。
                例：20 天的任務做完、另一個 1 天的還沒做，是 <b>95%</b> 而不是 50%。沒填預計起迄日的任務以 1 天計。</li>
            <li>按「新增目標」<b>不會</b>把你還沒儲存、已經填好的目標與任務清掉；
                多按了幾個沒用到的<b>空白目標區塊會在存檔時自動略過</b>（不會卡住你存檔），
                但如果那個區塊底下已經填了主要任務，就會要求你補上目標名稱。</li>
            <li><b>怎麼存</b>：跳窗<b>底部那顆「儲存」會一次存完基本資料＋執行規劃表（＋開著的管理卡）</b>，
                規劃表工具列上的「儲存規劃表」則只存規劃表，兩顆都可以。
                還有沒存到的內容時，關閉／送簽／核准／退回／結案前都會先提醒你，不會默默丟掉。</li>
            <li><b>填到一半跑去做別的事也不會不見</b>：中途切到別的分頁、按「同步 BOM」、加料號、開管理卡等等，
                回到執行規劃表時<b>你填的內容都還在</b>（畫面也會留在原本那個分頁）。
                要去<b>開別的專案</b>時才會提醒你有東西還沒存。<br>
                另外，把整份規劃表清空再按儲存<b>會先確認一次</b>（那等於刪掉全部目標與任務）。</li>
            <li><b>「實際開始／實際完成」要等立案核准之後才能填</b>：還在草稿／送簽中／被退回的專案只排預計日程，
                那兩欄會反灰。核准後就會自動開放（後端存檔時同樣會擋，不是只擋畫面）。</li>
            <li><b>負責人是「先選部門、再選人」</b>：選了部門就會列出該部門<b>與其子部門</b>底下的人，
                並顯示他在<b>那個部門</b>的職稱。<b>兼任多個部門的人會在每個部門底下各出現一次</b>
                （例：某人主職是品管組組員、兼任生管組組長，選「生管組」時就會看到他以組長身分出現）。
                換了部門，人員清單會跟著重列；原本指派的人若仍在新部門底下會保留。</li>
            <li>部門下拉要列出哪些部門，由管理員在<b>模組設定 → 執行規劃表負責人部門</b>勾選（<b>一個都沒勾＝列出全部部門</b>）。
                勾上層部門會連子部門一起帶出來，所以通常勾到「部」這一層就夠了。</li>
        </ul>

        <h4>五、專案管理卡（2-GM-02-03）</h4>
        <ul>
            <li><b>一個專案可以開很多張</b>，每張有自己的檢討日期，可看出歷次演進；結案時的績效評分考評也用它。</li>
            <li>開卡時<b>項次／各項目標名稱／主辦單位／承辦人全部自動帶入</b>（承辦人＝該目標底下任務的負責人）。</li>
            <li><b>「目前應達成基準」由甘特日程自動算出</b>，例如「至 2026.09.15 應完成 3/5 項；已完成 2 項；逾期 1 項（試作）；
                下一項『量產試跑』預計 2026.10.15」。你可以手動覆寫；<b>改了檢討日期，仍為自動的列會跟著重算</b>。</li>
            <li>實際只需要填<b>現階段問題</b>與<b>後續辦理方法</b>；沒問題的列勾<b>「依計畫進行」</b>即可。
                <b>每一列都要有交代</b>（勾依計畫進行、或填現階段問題），否則不能送出。</li>
            <li>想一次看全公司的專案，用工具列的<b>「跨專案總覽」</b>（內部用畫面，不印 AS 編號）。</li>
        </ul>

        <h4>六、關聯資料（訂單／報價單／BOM／報工）</h4>
        <ul>
            <li><b>訂單</b>：專案的主軸，可隨時加入或移出。移出訂單時，由該訂單帶進來的料號會跟著退場（手動掛的不動）。</li>
            <li><b>料號</b>：由訂單自動帶出，另可手動補掛（用於還沒有訂單的階段）。</li>
            <li><b>製程</b>：由該訂單<b>已開立的 BOM 製令自動帶入</b>（加工順序／製程／廠商／發包回廠日／檢驗狀態）。
                BOM 一開立就會自動帶進來，也可按<b>「同步 BOM」</b>隨時重拉最新進度。<b>本頁只讀 BOM，不會改動 BOM 任何資料。</b></li>
            <li><b>製令是怎麼跟訂單對上的</b>：依序看三種對應——①製令與訂單的對應表（開製令時分配訂單量產生的）
                ②製令的訂單欄存訂單編號 ③製令的訂單欄存訂單單號。三種只要有一種對得上就會帶進來。
                <b>已經出貨結案的製令也會列出來</b>（那是這個專案實際做過的製程履歷，不該因為結案就看不到）。
                如果同步後仍是空的，代表這張製令三種對應都沒有——請確認開製令時有沒有選到這張訂單。</li>
            <li><b>BOM 製程被改動時會主動提示</b>（新增／異動／移除，並說明改了哪個欄位，例如「發包日 (空)→2026.08.01」），
                清單上會出現紅色徽章。看過後按「知悉」即可清除。你自己在製程列加的註記與里程碑<b>不會被同步覆蓋</b>。</li>
        </ul>

        <h4>七、文件檢核（自動提醒）</h4>
        <ul>
            <li>「文件檢核」分頁列出專案內<b>每個料號 × 四份技術文件</b>的有無：
                <b>產品開發評估表（2-TD-02-01）／型態識別文件管制表／PFMEA（3-TD-01-02）／外來文件清單</b>。
                缺的顯示紅色 ✗，<b>點下去直接開對應頁面並帶入該料號</b>。</li>
            <li>反方向也會提醒：那四個頁面各自的<b>「建議建立清單／缺件偵測」</b>多了一個<b>「專案」來源</b>，
                會列出「有專案、但這一頁還沒建立」的料號，可多選批次建立。</li>
            <li><b>結案時會強制檢核</b>：還有料號缺文件就擋下並列出缺什麼（管理員可強制略過；此行為可在模組設定關閉）。
                已結案／已終止的專案不再列入偵測。</li>
        </ul>

        <h4>八、會簽與核准</h4>
        <ul>
            <li>專案填好後<b>送簽</b>：勾選會簽單位 → 系統解析各單位主管（<b>自動套用代理人</b>）並發出通知 →
                各單位<b>先選同意／不同意才能填審查意見</b>（意見非必填）→ 全部會簽完由核准人核准。</li>
            <li>核准人解析順序見「模組設定 → 立案核准人」；<b>解析到的人如果是送出者本人會自動跳過</b>。</li>
            <li><b>專案負責人資格</b>（模組設定）：可限定哪些<b>部門的哪些職稱</b>才能被指派為負責人，
                「訂單轉專案」與「專案基本資料」的負責人下拉都只列這些人（後端存檔時也會再擋一次）。
                一列都沒設＝不限制；<b>既有專案原本的負責人不受影響</b>，設定改嚴不會讓舊專案存不了檔。</li>
            <li><b>誰可以指派誰</b>：專案管理員可指派任何人；一般使用者只能指派<b>自己所屬部門（含兼任、含子部門）</b>內的人，
                且仍要符合上面的資格設定。兩層是<b>疊加</b>的，若交集為空就只剩自己可選（前提是自己符合資格）。</li>
            <li>核准後專案階段自動由「籌備」推進到「規劃」；核准人可自行輸入核准日期。</li>
            <li>補歷史紙本專案：管理員可用<b>批次自動簽核</b>，指定業務日期一次補完（簽核時間會刻意錯開 5~30 分且不跨日）。</li>
        </ul>

        <h4>九、權限角色</h4>
        <ul>
            <li><b>專案檢閱</b>：看清單、明細、列印。</li>
            <li><b>專案登錄</b>：檢閱＋建立/編輯專案、訂單轉專案、編執行規劃表、開管理卡、同步 BOM。</li>
            <li><b>專案管理員</b>：登錄＋刪除、標籤維護、模組設定（含專案負責人資格、執行規劃表負責人部門）、AS 文件綁定、批次自動簽核。</li>
            <li><b>常用語句</b>的新增／修改／刪除＝<b>專案登錄</b>即可（只有檢閱角色看得到語句但不能維護，也不會出現「帶入」）。</li>
            <li><b>專案負責人</b>（資料層，非角色）：即使只有「檢閱」角色，也能編輯<b>自己負責的專案</b>。</li>
            <li><b>會簽人</b>：不需要任何角色，被指派會簽就進得來處理自己那一列。</li>
            <li>管理者固定擁有全部權限。設定入口：<b>權限設定 → 專案管理</b>。</li>
        </ul>

        <h4>十、常見疑問</h4>
        <ul>
            <li><b>Q：為什麼專案裡看不到 BOM 製程？</b><br>
                A：該訂單還沒開立 BOM，或 BOM 的訂單欄沒有對應到這張訂單。備庫單（B）與重製單（R）不會自動歸入專案。</li>
            <li><b>Q：管理卡的「目前應達成基準」可以自己寫嗎？</b><br>
                A：可以，直接改該欄即可；改過的列會停止自動更新（想恢復自動就把該列的「自動」勾回來）。</li>
            <li><b>Q：規劃表的「實際開始／實際完成」是灰的不能填？</b><br>
                A：專案還沒立案核准。那兩欄是執行階段才要填的，核准後會自動開放。</li>
            <li><b>Q：負責人下拉裡找不到某個人？</b><br>
                A：先確認選對部門——人是列在他「掛職務」的那個部門底下；兼任者在每個部門都找得到。
                如果整個部門都不在下拉裡，請管理員到<b>模組設定 → 執行規劃表負責人部門</b>把該部門（或它的上層部門）勾起來。</li>
            <li><b>Q：原本的「專案時空背景／對本公司貢獻／備註」不見了？</b><br>
                A：依使用者要求，專案內容只保留<b>專案目的</b>與<b>專案目標</b>兩項。舊資料仍留在資料庫內沒有被刪除。</li>
            <li><b>Q：專案代號可以改嗎？</b><br>
                A：自動產生後不提供修改，避免號碼撞號。要補歷史專案請用管理員的批次自動簽核並指定業務日期。</li>
        </ul>
    </div>
    <div class="m-foot"><button onclick="closeMask('helpUseMask')">關閉</button></div>
</div></div>

<button class="pj-totop" id="btnTop"><i class="fa fa-arrow-up"></i></button>

<script src="../../resource/js/jquery.min.js"></script>
<script src="../../resource/js/bootstrap.min.js"></script>
<script src="../../resource/js/fastclick.js"></script>
<script src="../../resource/js/nprogress.js"></script>
<script src="../../resource/js/custom.min.js"></script>
<script src="../../resource/js/eg_input_rules.js?v=<?= $av('resource/js/eg_input_rules.js') ?>"></script>
<script src="../../resource/js/eg_date_fmt.js?v=<?= $av('resource/js/eg_date_fmt.js') ?>"></script>
<script src="../../resource/js/eg_stamp.js?v=<?= $av('resource/js/eg_stamp.js') ?>"></script>
<script src="../../resource/js/eg_stamp_tpl.js?v=<?= $av('resource/js/eg_stamp_tpl.js') ?>"></script>
<script src="../../resource/js/eg_asdoc_picker.js?v=<?= $av('resource/js/eg_asdoc_picker.js') ?>"></script>
<script>
/* ══════════════════════════════════════════════════════════════
   專案管理 前端
   ══════════════════════════════════════════════════════════════ */
var API   = '../../src/store/Project_API.php';
var PERM  = <?= json_encode($perms, JSON_UNESCAPED_UNICODE) ?>;
var META  = {};
var CUR   = null;          // 目前開著的專案完整資料
var LIST  = [];            // 清單（前端純顯示分頁）
var PAGE  = 1, PSIZE = 10;
var FTAGS = [];            // 已選的篩選標籤
var GSCALE = 'week';       // 甘特刻度：day/week/month
var GVIEW  = 'gantt';      // gantt / list

function esc(s){ return $('<div>').text(s == null ? '' : s).html(); }
/* 顯示用日期一律 YYYY.MM.DD（ai-rules/20；eg_date_fmt.js 只匯出 egFmtDate，各頁自己包一層是既有慣例） */
function dispDate(d){ return (typeof egFmtDate === 'function') ? egFmtDate(d) : (d || ''); }
function openMask(id){ $('#' + id).show(); }
function closeMask(id){ $('#' + id).hide(); }
function num(v){ var n = parseInt(v, 10); return isNaN(n) ? 0 : n; }

/* API 錯誤統一顯示：非 2xx 時 jQuery 不呼叫 success，錯誤會只掉進 console（doc_apply 踩過的坑） */
$(document).ajaxError(function(e, xhr){
    if (xhr.status === 0) return;
    var m = '';
    try { m = (JSON.parse(xhr.responseText) || {}).error || ''; } catch(_) { m = ''; }
    alert(m || ('操作失敗（HTTP ' + xhr.status + '）'));
});

function api(action, data, method){
    return $.ajax({ url: API + '?action=' + action, type: method || 'GET', data: data, dataType: 'json' });
}
</script>
<script src="project_mgmt_ui.js?v=<?= $av('views/GM/project_mgmt_ui.js') ?>"></script>
<script>
$(document).ready(function(){
    /* 側欄：抄 vendor_audit.php（只抄 CSS 沒抄這段 JS ＝側欄整片消失，鐵律6） */
    var $am = $('#sidebar-menu .nav.side-menu > li.active');
    if ($am.length) { $am.removeClass('active').find('ul.child_menu').hide(); $am.find('li.current-page').removeClass('current-page'); }
    $('#sidebar-menu').css('visibility','visible');

<?php if ($perms['canView']): ?>
    pjInit();
<?php endif; ?>

    $('#btnPageHelp').on('click', function(){ openMask('helpUseMask'); });
    $(window).on('scroll', function(){ $('#btnTop').toggle($(window).scrollTop() > 300); });
    $('#btnTop').on('click', function(){ $('html,body').animate({ scrollTop: 0 }, 200); });
});
</script>
</body>
</html>
