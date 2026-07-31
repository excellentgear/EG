<?php
/**
 * 供應商稽核管理（KPI 2-GM-04-01 第6項 廠商稽核按時執行率 的來源頁）—— 稽核批次模型
 * 每期(上/下半年)挑一批稽核對象(手動多選/隨機抽取)；大類/加工項目階層篩選(比照 master_data)。
 * 資料一律走 src/store/VendorAudit_API.php；權限 vendor_audit_lib.php
 */
session_start();
if (!isset($_SESSION['userName'])) {
    $_SESSION['lastpage'] = "../../views/pm/vendor_audit.php";
    header("Location:../../index.php");
    exit;
}
include_once '../../src/common/_config.php';
include_once '../../src/common/DBConnection.php';
include_once '../../src/common/vendor_audit_lib.php';

$db = (new DBConnection())->getPDO();
vendor_audit_ensure_schema($db);
$vaUser = vendor_audit_current_user($db);
$perms = vendor_audit_perms($db, $vaUser);
$roleLabel = $perms['isAdmin'] ? '管理者'
           : ($perms['canAdmin'] ? '稽核管理員'
           : ($perms['canEdit'] ? '稽核登錄'
           : ($perms['canView'] ? '稽核檢閱' : '無權限')));
?>
<!DOCTYPE html>
<html lang="zh-Hant">
<head>
    <meta charset="utf-8">
    <meta http-equiv="X-UA-Compatible" content="IE=edge">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>供應商稽核管理</title>
    <link href="../../resource/css/bootstrap.css" rel="stylesheet">
    <link href="../../resource/css/font-awesome.css" rel="stylesheet">
    <link href="../../resource/css/nprogress.css" rel="stylesheet">
    <link href="../../resource/css/custom.css" rel="stylesheet">
    <style>
        #sidebar-menu { visibility: hidden; }
        .right_col .page-title { margin:8px 0 4px; overflow:hidden; }
        .va-toolbar { display:flex; flex-wrap:wrap; gap:6px; align-items:center; clear:both;
            border:1.5px solid #E8D5B5; border-radius:8px; padding:8px 10px; margin-bottom:10px; background:#FDF8EF; }
        .va-toolbar label { margin:0; font-size:13px; color:#5b3a1e; }
        .va-toolbar select, .va-toolbar input[type=text], .va-toolbar input[type=number], .va-toolbar button {
            height:30px; font-size:13px; line-height:1; padding:0 10px; border:1px solid #D8BE93;
            border-radius:4px; background:#fff; color:#5b3a1e; cursor:pointer; }
        .va-toolbar button:hover { background:#F7E0BD; }
        .va-toolbar .btn-warm { background:#F0A24B; color:#fff; border-color:#d98a33; }
        .va-toolbar .btn-warm:hover { background:#d98a33; }
        .va-role-badge { margin-left:auto; font-size:13px; color:#5b3a1e; background:#F7E0BD; border-radius:12px; padding:4px 12px; }
        .va-role-badge .fa-question-circle { cursor:pointer; color:#b5762a; margin-left:5px; }
        .va-tabs { display:flex; gap:4px; margin-bottom:8px; border-bottom:2px solid #E8D5B5; }
        .va-tab { border:1px solid #E8D5B5; border-bottom:none; background:#FBF3E5; color:#8a6d45; cursor:pointer;
            padding:7px 16px; font-size:14px; border-radius:6px 6px 0 0; margin-bottom:-2px; }
        .va-tab.active { background:#fff; color:#5b3a1e; font-weight:bold; border-bottom:2px solid #fff; }
        /* 凍結窗格：標題→分頁→工具列→門檻說明 固定在頂端(僅螢幕) */
        @media screen {
            .right_col .page-title { position:sticky; top:0; z-index:32; background:#fff; }
            .va-tabs { position:sticky; top:34px; z-index:31; background:#fff; }
            #tabEval .va-toolbar { position:sticky; top:72px; z-index:30; }
            #tabEval #evThresh { position:sticky; top:120px; z-index:29; background:#fff; padding:3px 0; margin:0; }
        }
        /* 回頂端按鈕 */
        .va-totop { position:fixed; bottom:22px; right:22px; width:48px; height:48px; border:none; border-radius:50%;
            background:#F0A24B; color:#fff; font-size:12px; font-weight:bold; cursor:pointer; z-index:1000;
            box-shadow:0 4px 8px rgba(90,58,30,.3); display:none; }
        .va-totop:hover { background:#d98a33; }
        @media print { .va-totop { display:none !important; } }
        /* 定期評核-全部卡片(2欄並列) */
        .ev-cards { display:grid; grid-template-columns:1fr 1fr; gap:8px; }
        .ev-card { border:1px solid #E8D5B5; border-radius:6px; background:#fff; padding:6px 8px; break-inside:avoid; }
        .ev-card .h { display:flex; justify-content:space-between; align-items:center; font-weight:bold; color:#5b3a1e; margin-bottom:4px; font-size:13px; }
        table.ev-mini { width:100%; border-collapse:collapse; font-size:11px; }
        table.ev-mini th, table.ev-mini td { border:1px solid #EADFC8; padding:1px 4px; text-align:center; }
        table.ev-mini thead th { background:#F7E0BD; color:#5b3a1e; }
        table.ev-mini tr.half td { background:#FDF3E0; font-weight:bold; }
        table.ev-mini td.over { color:#DD5138; font-weight:bold; }
        @media print {
            .va-tabs, .va-toolbar, .va-remind { display:none !important; }
            .ev-cards { gap:4px; }
            .ev-card:nth-child(4n) { page-break-after: always; }
        }
        .va-stat { display:flex; flex-wrap:wrap; gap:18px; align-items:center; margin-bottom:8px;
            border:1.5px solid #E8D5B5; border-radius:8px; padding:10px 14px; background:#FFF7E8; }
        .va-stat .s-num { font-size:22px; font-weight:bold; color:#8A5A2B; }
        .va-stat .s-lab { font-size:12px; color:#8a6d45; }
        .va-stat .s-rate.below { color:#DD5138; }
        .va-stat .s-rate.ok { color:#8A5A2B; }
        .va-remind { font-size:12px; color:#8a6d45; margin-bottom:8px; }
        .va-table-wrap { overflow-x:auto; border:1px solid #E8D5B5; border-radius:6px; background:#fff; }
        table.va-table { width:100%; border-collapse:collapse; font-size:13px; }
        table.va-table th, table.va-table td { border:1px solid #EADFC8; padding:5px 8px; white-space:nowrap; text-align:center; }
        table.va-table thead th { position:sticky; top:0; z-index:5; background:#F7E0BD; color:#5b3a1e; font-weight:bold; }
        table.va-table tbody tr:nth-child(even) { background:#FBF6EC; }
        table.va-table tbody tr:hover { background:#FBF0DD; }
        table.va-table tr.dis td { background:#efe7d8 !important; color:#b0a390; }
        table.va-table td.t-left { text-align:left; }
        .st-pill { display:inline-block; font-size:12px; border-radius:10px; padding:2px 9px; }
        .st-done { background:#F0A24B; color:#fff; }
        .st-todo { background:#F7E0BD; color:#7a5217; }
        .st-dis { background:#efe7d8; color:#b0a390; }
        .rs-pass { color:#8A5A2B; } .rs-conditional { color:#c98a2e; } .rs-fail { color:#DD5138; }
        .va-op { color:#b5762a; cursor:pointer; margin:0 4px; }
        .va-op:hover { color:#8A5A2B; text-decoration:underline; }
        input[type=number]::-webkit-outer-spin-button, input[type=number]::-webkit-inner-spin-button { -webkit-appearance:none; margin:0; }
        input[type=number] { -moz-appearance:textfield; }
        .va-mask { display:none; position:fixed; inset:0; background:rgba(60,40,20,.45); z-index:1050; }
        .va-modal { background:#fff; border-radius:8px; max-width:560px; margin:36px auto; box-shadow:0 5px 25px rgba(0,0,0,.3);
            max-height:90vh; display:flex; flex-direction:column; }
        .va-modal.wide { max-width:860px; }
        .va-modal .m-head { background:#F7E0BD; color:#5b3a1e; font-weight:bold; padding:10px 15px; border-radius:8px 8px 0 0;
            display:flex; justify-content:space-between; }
        .va-modal .m-head .m-close { cursor:pointer; color:#b5762a; }
        .va-modal .m-body { padding:15px; overflow-y:auto; }
        .va-modal .m-body label { display:block; font-size:13px; color:#5b3a1e; margin:9px 0 3px; }
        .va-modal .m-body input[type=text], .va-modal .m-body input[type=number], .va-modal .m-body input[type=date],
        .va-modal .m-body select, .va-modal .m-body textarea { width:100%; border:1px solid #D8BE93; border-radius:4px;
            padding:5px 8px; font-size:13px; box-sizing:border-box; }
        .va-modal .m-foot { padding:10px 15px; border-top:1px solid #EADFC8; text-align:right; }
        .va-modal .m-foot button { height:30px; padding:0 16px; border-radius:4px; font-size:13px; border:1px solid #d98a33; cursor:pointer; }
        .va-modal .m-foot .b-ok { background:#F0A24B; color:#fff; }
        .va-modal .m-foot .b-cancel { background:#fff; color:#5b3a1e; border-color:#D8BE93; margin-right:6px; }
        .grid2 { display:grid; grid-template-columns:1fr 1fr; gap:0 14px; }
        .va-modal.xwide { max-width:920px; }
        .va-modal.pkwide { max-width:min(1200px, 96vw); }
        /* 稽核評鑑表單 */
        .af-head { display:grid; grid-template-columns:repeat(3,1fr); gap:0 14px; }
        .af-table-wrap { border:1px solid #EADFC8; border-radius:6px; overflow:hidden; }
        table.af-table { width:100%; border-collapse:collapse; font-size:12px; }
        table.af-table td, table.af-table th { border-bottom:1px solid #F0E7D5; padding:4px 6px; }
        table.af-table tr.af-cat td { background:#F7E0BD; color:#5b3a1e; font-weight:bold; }
        table.af-table td.af-q { text-align:left; }
        table.af-table td.af-sc { text-align:center; white-space:nowrap; }
        table.af-table input.af-score { height:26px; font-size:13px; text-align:center; border:1px solid #D8BE93; border-radius:4px; width:60px; padding:0 4px; }
        table.af-table input.af-score:focus { outline:2px solid #F0A24B; outline-offset:-1px; }
        table.af-table input.af-invalid { background:#ffd6d6; color:#DD5138; border-color:#DD5138; font-weight:bold; }
        .af-summary { margin-top:8px; border:1.5px solid #E8D5B5; border-radius:8px; background:#FFF7E8; padding:8px 12px; font-size:12px; color:#5b3a1e; }
        .af-summary table { width:100%; border-collapse:collapse; }
        .af-summary td, .af-summary th { padding:3px 6px; text-align:center; border-bottom:1px solid #F0E7D5; }
        .af-summary .af-total td { font-weight:bold; background:#FDF3E0; }
        .af-judge-pass { color:#8A5A2B; font-weight:bold; } .af-judge-fail { color:#DD5138; font-weight:bold; }
        .af-attach { border:1px dashed #E8D5B5; border-radius:6px; background:#FDF8EF; padding:8px 10px; margin-top:8px; }
        button.b-att2 { height:28px; font-size:12px; border:1px solid #d98a33; background:#F0A24B; color:#fff; border-radius:4px; cursor:pointer; padding:0 10px; white-space:nowrap; }
        button.b-att2:hover { background:#d98a33; }
        #audPeople label { margin:0; font-weight:normal; cursor:pointer; white-space:nowrap; }
        /* picker */
        .pk-filter { display:flex; flex-wrap:wrap; gap:6px 10px; align-items:center; margin-bottom:8px; }
        .pk-filter label { margin:0; font-size:12px; color:#5b3a1e; }
        /* 高特異度覆蓋 .m-body select{width:100%}，讓大類/加工項目並排 */
        .va-modal .m-body .pk-filter select { width:150px; height:28px; font-size:12px; padding:0 6px; }
        .va-modal .m-body .pk-filter input[type=text] { width:150px; height:28px; font-size:12px; padding:0 6px; }
        .pk-selbar { display:flex; align-items:center; gap:10px; font-size:12px; color:#5b3a1e; margin-bottom:4px; }
        .pk-selbar label { margin:0; cursor:pointer; }
        /* 廠商池：多欄自動排版、不使用上下捲軸（禁止 overflow-y） */
        .pk-grid { border:1px solid #EADFC8; border-radius:6px; padding:5px 6px;
            display:grid; grid-template-columns:repeat(auto-fill, minmax(140px, 1fr)); gap:1px 8px; }
        .pk-grid .pk-item { display:flex; align-items:center; gap:4px; padding:2px 4px; border-radius:4px; font-size:12px;
            color:#5b3a1e; cursor:pointer; margin:0; font-weight:normal; white-space:nowrap; overflow:hidden; }
        .pk-grid .pk-item:hover { background:#FBF0DD; }
        .pk-grid .pk-item .no { color:#8a6d45; }
        .pk-grid .pk-item .nm { overflow:hidden; text-overflow:ellipsis; }
        .pk-grid .pk-item .cat { color:#b0a390; font-size:11px; }
        .pk-grid .pk-item .mg { color:#8A5A2B; font-weight:bold; font-size:11px; margin-left:auto; }
        .pk-grid .empty { color:#8a6d45; padding:10px; grid-column:1/-1; }
        .pk-actions { display:flex; flex-wrap:wrap; gap:8px 10px; align-items:center; margin-top:10px; padding-top:8px; border-top:1px dashed #EADFC8; }
        .pk-actions .grp { display:flex; gap:4px; align-items:center; flex-wrap:nowrap; white-space:nowrap; font-size:12px; color:#5b3a1e; }
        .pk-actions .grp select, .pk-actions .grp input[type=number] { min-height:28px; box-sizing:border-box; }
        .pk-actions button { display:inline-flex; align-items:center; gap:4px; min-height:28px; line-height:1.2; white-space:nowrap;
            font-size:12px; border-radius:4px; border:1px solid #d98a33; cursor:pointer; padding:5px 12px; }
        .pk-actions .b-add { background:#F0A24B; color:#fff; }
        .pk-actions .b-alt { background:#fff; color:#5b3a1e; border-color:#D8BE93; }
        .pk-actions input[type=number] { width:60px; min-height:28px; box-sizing:border-box; border:1px solid #D8BE93; border-radius:4px; padding:4px 6px; }
        table.hist { width:100%; border-collapse:collapse; font-size:12px; }
        table.hist th, table.hist td { border:1px solid #EADFC8; padding:4px 6px; text-align:center; }
        table.hist thead th { background:#F7E0BD; color:#5b3a1e; }
        .va-noperm { margin:40px auto; max-width:520px; text-align:center; border:1.5px solid #E8D5B5; border-radius:10px;
            padding:30px; background:#FDF8EF; color:#5b3a1e; }
        @media print {
            .va-toolbar, .nav_menu, .left_col, footer, .va-role-badge .fa-question-circle, .va-op { display:none !important; }
            .right_col { margin:0 !important; padding:0 !important; }
            table.va-table thead th { position:static; }
        }
    </style>
</head>
<body class="nav-sm">
<div class="container body">
<div class="main_container">
    <?php include '../partPage/sideAndTopBarMenu.html' ?>
    <div class="right_col" role="main">
        <div class="page-title" style="display:flex;align-items:center;flex-wrap:wrap;">
            <h2 style="margin:6px 0;">供應商稽核管理
                <small style="color:#8a6d45;">KPI 2-GM-04-01 #6 廠商稽核按時執行率 來源頁（半年一期，每期挑一批對象）</small></h2>
        </div>
        <div class="clearfix"></div>

<?php if (!$perms['canView']): ?>
        <div class="va-noperm">
            <h4><i class="fa fa-lock"></i> 無供應商稽核檢閱權限</h4>
            <p>請洽管理者於「使用者權限設定」指派「稽核檢閱／登錄／管理員」角色。</p>
        </div>
<?php else: ?>
        <div class="va-tabs">
            <button class="va-tab active" data-tab="audit"><i class="fa fa-check-square-o"></i> 稽核批次</button>
            <button class="va-tab" data-tab="eval"><i class="fa fa-line-chart"></i> 定期評核（月不良/遲交率）</button>
        </div>
        <div id="tabAudit">
        <div class="va-toolbar">
            <label>年度</label>
            <select id="yearSel"></select>
            <label>期別</label>
            <select id="halfSel"><option value="1">上半年(1-6月)</option><option value="2">下半年(7-12月)</option></select>
            <button class="btn-warm" id="btnPick" style="display:none;"><i class="fa fa-plus"></i> 加入稽核對象</button>
            <button id="btnAuditor" style="display:none;"><i class="fa fa-user-circle-o"></i> 稽核員設定</button>
            <button id="btnCycle" style="display:none;"><i class="fa fa-refresh"></i> 週期設定</button>
            <button id="btnBlank"><i class="fa fa-file-o"></i> 列印空白表單</button>
            <button id="btnCsv"><i class="fa fa-file-text-o"></i> 匯出CSV</button>
            <button onclick="window.print()"><i class="fa fa-print"></i> 列印清單</button>
            <span class="va-role-badge">目前角色：<b><?= htmlspecialchars($roleLabel) ?></b>
                <i class="fa fa-question-circle" id="btnRoleHelp" title="角色權限說明"></i></span>
        </div>

        <div class="va-stat" id="statBar">
            <div><span class="s-num" id="stDen">—</span> <span class="s-lab">本期應稽核（對象）</span></div>
            <div><span class="s-num" id="stNum">—</span> <span class="s-lab">已完成</span></div>
            <div><span class="s-num s-rate" id="stRate">—</span> <span class="s-lab">執行率（目標 ≥70%）</span></div>
            <div class="s-lab" id="stHint" style="margin-left:auto;"></div>
        </div>
        <div class="va-remind" id="remind"></div>

        <div class="va-table-wrap">
            <table class="va-table" id="vaTable">
                <thead><tr>
                    <th>廠商編號</th><th>廠商名稱</th><th>大類</th><th>預定月份</th><th>稽核狀態</th>
                    <th>稽核日</th><th>綜合合格率</th><th>判定</th><th>稽核員</th><th>操作</th>
                </tr></thead>
                <tbody id="vaBody"><tr><td colspan="10" style="padding:20px;color:#8a6d45;">載入中…</td></tr></tbody>
            </table>
        </div>
        <div style="font-size:11px;color:#8a6d45;margin-top:4px;">
            每期(上/下半年)由「加入稽核對象」挑一批廠商（大類/加工項目篩選後多選，或隨機抽 N 家），逐一登錄稽核結果。
            KPI 執行率＝已完成 ÷ 本期對象數（<span class="st-pill st-dis">停用</span>廠商不列入）。
        </div>
        </div><!-- /tabAudit -->

        <div id="tabEval" style="display:none;">
            <div class="va-toolbar">
                <label>年度</label>
                <select id="evYear"></select>
                <button class="btn-warm" id="evAll"><i class="fa fa-th-large"></i> 全部納管廠商</button>
                <span style="color:#c9bda9;">｜</span>
                <label>單一廠商</label>
                <input type="text" id="evKw" placeholder="搜尋廠商名/編號" style="width:130px;">
                <select id="evVendor" style="min-width:150px;"></select>
                <button id="evGo"><i class="fa fa-search"></i> 查詢</button>
                <span style="color:#c9bda9;">｜</span>
                <label id="evFailBox" style="display:none;"><input type="checkbox" id="evFailOnly"> 只看不合格</label>
                <button id="evSet" style="display:none;"><i class="fa fa-cog"></i> 門檻設定</button>
                <button id="evCsv" style="display:none;"><i class="fa fa-file-text-o"></i> 匯出CSV</button>
                <button id="evPrint"><i class="fa fa-print"></i> 列印</button>
            </div>
            <div class="va-remind" id="evThresh"></div>
            <div id="evSingle" style="display:none;">
                <div id="evScoreTop" class="va-stat" style="margin-bottom:8px;"></div>
                <div class="va-table-wrap">
                    <table class="va-table" id="evTable">
                        <thead><tr>
                            <th rowspan="2">月份</th>
                            <th colspan="4">品質（進料檢驗）</th>
                            <th colspan="3">交期</th>
                        </tr><tr>
                            <th>檢驗數</th><th>不良數</th><th>不良率</th><th>特採率</th>
                            <th>應交數</th><th>遲交數</th><th>遲交率</th>
                        </tr></thead>
                        <tbody id="evBody"></tbody>
                    </table>
                </div>
            </div>
            <div id="evCards" class="ev-cards"></div>
            <div id="evEmpty" style="padding:18px;color:#8a6d45;">按「全部納管廠商」列出所有納管廠商評核，或選單一廠商查詢。（自動略過整年無資料廠商）</div>
            <div style="font-size:11px;color:#8a6d45;margin-top:4px;">
                資料自 ERP（bom_ing）自動計算：品質依檢驗日歸月（不良=ng、特採=QQ）；交期＝發包日＋約定工作天為應交日，遲交＝回廠日晚於應交。半年判定依門檻（管理員可設）。
            </div>
        </div><!-- /tabEval -->
<?php endif; ?>
    </div>
    <?php include '../partPage/footer.html' ?>
</div>
</div>
<button class="va-totop" id="vaToTop" title="回頂端" onclick="window.scrollTo({top:0,behavior:'smooth'})">回頂端</button>

<!-- 廠商池挑選 modal -->
<div class="va-mask" id="pkMask"><div class="va-modal pkwide">
    <div class="m-head"><span id="pkTitle">加入稽核對象</span><span class="m-close" onclick="closeMask('pkMask')">✕</span></div>
    <div class="m-body">
        <div class="pk-filter">
            <label style="margin:0;font-size:12px;color:#5b3a1e;">大類</label>
            <select id="pkMain"><option value="">全部</option></select>
            <label style="margin:0;font-size:12px;color:#5b3a1e;">加工項目</label>
            <select id="pkSub"><option value="">全部</option></select>
            <input type="text" id="pkKw" placeholder="搜尋廠商名/編號" style="width:150px;">
            <label style="margin:0;font-size:12px;color:#5b3a1e;"><input type="checkbox" id="pkManagedOnly"> 只看納管</label>
            <button class="b-alt" style="height:28px;border:1px solid #D8BE93;border-radius:4px;background:#fff;cursor:pointer;" onclick="loadPool()">查詢</button>
            <span id="pkCount" style="font-size:12px;color:#8a6d45;"></span>
        </div>
        <div class="pk-selbar">
            <label><input type="checkbox" id="pkAll"> 全選</label>
            <span style="color:#8a6d45;">勾選要加入的廠商（<span class="mg" style="color:#8A5A2B;">✔納</span>=已納入稽核管理）</span>
        </div>
        <div class="pk-grid" id="pkBody"><div class="empty">請設定條件後查詢</div></div>
        <div class="pk-actions">
            <div class="grp">預定稽核月份 <select id="pkMonth" style="height:28px;border:1px solid #D8BE93;border-radius:4px;"></select></div>
            <div class="grp"><button class="b-add" onclick="addSelected()"><i class="fa fa-check"></i> 加入選取</button></div>
            <div class="grp">隨機抽 <input type="number" id="pkRandN" min="1" step="1" value="5"> 家：
                <button class="b-add" onclick="randomDraw()"><i class="fa fa-random"></i> 抽取加入</button>
                <span style="font-size:11px;color:#8a6d45;">(自納管廠商)</span></div>
            <div class="grp" id="pkManageGrp" style="display:none;margin-left:auto;">
                <button class="b-alt" onclick="bulkManaged(1)">選取設為納管</button>
                <button class="b-alt" onclick="bulkManaged(0)">選取取消納管</button>
            </div>
        </div>
    </div>
    <div class="m-foot">
        <button class="b-cancel" onclick="closeMask('pkMask')">關閉</button>
    </div>
</div></div>

<!-- 稽核評鑑表單 modal（簡版15項 2-PH-01-02/03） -->
<div class="va-mask" id="recMask"><div class="va-modal xwide">
    <div class="m-head"><span id="recTitle">稽核評鑑表單</span><span class="m-close" onclick="closeMask('recMask')">✕</span></div>
    <div class="m-body">
        <div class="af-head">
            <div><label>預定稽核月份</label><select id="recPlanMonth"></select></div>
            <div><label>稽核日期（留空=尚未稽核，月內完成即準時）</label><input type="date" id="recDate"></div>
            <div><label>稽核狀況</label><select id="recMode">
                <option value="first">首次稽核</option><option value="again">次稽核</option><option value="self">自我評量</option>
            </select></div>
            <div><label>稽核員 <span id="recScopeHint" style="font-size:11px;color:#b5762a;"></span></label>
                <select id="recAuditor"><option value="">—</option></select></div>
            <div><label>自評人員</label><input type="text" id="recSelfEval" maxlength="50"></div>
            <div><label>報告編號 <span style="font-size:11px;color:#8a6d45;">(稽核報告文件編號,選填)</span></label><input type="text" id="recReport" maxlength="50"></div>
        </div>
        <div class="af-attach" id="afAttachBox">
            <div style="font-weight:bold;color:#5b3a1e;margin:10px 0 4px;"><i class="fa fa-paperclip"></i> 佐證附件（供應商自評表等）</div>
            <div id="afAttachList" style="font-size:12px;"></div>
            <div id="afAttachUp" style="margin-top:5px;display:flex;gap:6px;align-items:center;flex-wrap:wrap;">
                <input type="file" id="afAttachFile" style="font-size:12px;">
                <input type="text" id="afAttachNote" maxlength="200" placeholder="附件說明(選填)" style="width:200px;">
                <button type="button" class="b-att2" onclick="uploadAttach()"><i class="fa fa-upload"></i> 上傳</button>
                <span style="font-size:11px;color:#8a6d45;">單檔上限 20MB</span>
            </div>
        </div>
        <div style="font-size:11px;color:#8a6d45;margin:6px 0;">
            每項自評/稽核各評 0~7 分（0=最差、7=最佳）；綜合合格率＝自評率×0.3＋稽核率×0.7，<b>≥75% 判合格</b>。
        </div>
        <div class="af-table-wrap">
            <table class="af-table" id="afTable"><tbody id="afBody"></tbody></table>
        </div>
        <div class="af-summary" id="afSummary"></div>
        <div class="grid2" style="margin-top:8px;">
            <div><label>建議評鑑結果（結論）</label><select id="recConclusion">
                <option value="">—</option>
                <option value="合格">合格供應商</option>
                <option value="回覆改善後合格">回覆稽核改善對策後合格</option>
                <option value="需重新稽核">有嚴重缺失，改善後需重新稽核</option>
                <option value="其他">其他</option>
            </select></div>
            <div><label>備註</label><input type="text" id="recNote" maxlength="200"></div>
        </div>
    </div>
    <div class="m-foot">
        <button class="b-cancel" onclick="printCurrentForm()"><i class="fa fa-print"></i> 列印本表</button>
        <button class="b-cancel" onclick="closeMask('recMask')">取消</button>
        <button class="b-ok" onclick="submitRec()">儲存</button>
    </div>
</div></div>

<!-- 供應商品質系統評鑑記錄表 modal（2-PH-01-03，含雷達圖） -->
<div class="va-mask" id="rsMask"><div class="va-modal xwide">
    <div class="m-head"><span id="rsTitle">供應商品質系統評鑑記錄表</span><span class="m-close" onclick="closeMask('rsMask')">✕</span></div>
    <div class="m-body">
        <div id="rsInfo" style="font-size:13px;color:#5b3a1e;margin-bottom:8px;"></div>
        <div style="display:flex;gap:14px;flex-wrap:wrap;align-items:flex-start;">
            <div style="flex:1;min-width:280px;">
                <table class="af-table" style="width:100%;"><thead><tr><th>評鑑項目</th><th>單項滿分</th><th>自評合格率</th><th>稽核合格率</th><th>綜合合格率</th></tr></thead>
                <tbody id="rsCatBody"></tbody></table>
                <div id="rsConc" class="af-summary" style="margin-top:8px;"></div>
            </div>
            <div style="flex:0 0 360px;"><div id="rsChart" style="height:320px;"></div></div>
        </div>
        <div class="af-attach" id="rsAttachBox" style="margin-top:10px;">
            <div style="font-weight:bold;color:#5b3a1e;margin-bottom:4px;"><i class="fa fa-paperclip"></i> 佐證附件（列印後供應商簽名回傳掃描檔）</div>
            <div id="rsAttachList" style="font-size:12px;"></div>
            <div id="rsAttachUp" style="margin-top:5px;display:flex;gap:6px;align-items:center;flex-wrap:wrap;">
                <input type="file" id="rsAttachFile" style="font-size:12px;">
                <input type="text" id="rsAttachNote" maxlength="200" placeholder="附件說明(選填)" style="width:180px;">
                <button type="button" class="b-att2" onclick="rsUploadAttach()"><i class="fa fa-upload"></i> 上傳</button>
            </div>
        </div>
    </div>
    <div class="m-foot">
        <button class="b-cancel" onclick="printRecordSheet()"><i class="fa fa-print"></i> 列印記錄表</button>
        <button class="b-cancel" onclick="printAllDocs()"><i class="fa fa-print"></i> 一次印全部文件</button>
        <button class="b-ok" onclick="closeMask('rsMask')">關閉</button>
    </div>
</div></div>

<!-- 週期設定 modal -->
<div class="va-mask" id="cycMask"><div class="va-modal">
    <div class="m-head"><span>共用稽核週期設定</span><span class="m-close" onclick="closeMask('cycMask')">✕</span></div>
    <div class="m-body">
        <label>稽核週期（月）—— 全公司共用，作為「多久辦一期」的參考與提醒</label>
        <input type="number" id="cycVal" step="1" min="1" style="width:120px;">
        <div style="font-size:12px;color:#8a6d45;margin:4px 0 12px;">例：6＝每半年一期。此值僅供提醒，不會自動改變各期對象。</div>
        <label>綁定 AS 表單（稽核查檢表 2-PH-01-02）</label>
        <div style="display:flex;gap:6px;align-items:center;flex-wrap:wrap;">
            <input type="text" id="cycAsKw" placeholder="搜尋文件編號/名稱" style="width:150px;">
            <select id="cycAsDoc" style="flex:1;min-width:200px;"><option value="0">（不綁定，用預設「供應商評鑑稽核查表 / 2-PH-01-02」）</option></select>
        </div>
        <label style="margin-top:8px;">綁定 AS 表單（品質系統評鑑記錄表 2-PH-01-03）</label>
        <div style="display:flex;gap:6px;align-items:center;flex-wrap:wrap;">
            <input type="text" id="cycRecKw" placeholder="搜尋文件編號/名稱" style="width:150px;">
            <select id="cycRecDoc" style="flex:1;min-width:200px;"><option value="0">（不綁定，用預設「供應商品質系統評鑑記錄表 / 2-PH-01-03」）</option></select>
        </div>
        <div style="font-size:12px;color:#8a6d45;margin:4px 0 12px;">綁定後，AS 文件改名稱/改編號，列印文件會自動跟著變。</div>
        <label>佐證附件儲存路徑（base）—— 供應商自評等附件的實體存放資料夾</label>
        <input type="text" id="cycAttachBase" maxlength="255" placeholder="留空＝預設 uploads/vendor_audit_attach；可填 NAS 路徑如 \\NAS\品保\供應商稽核附件">
        <div style="font-size:12px;color:#8a6d45;margin-top:6px;">DB 只存檔名，完整路徑於讀取當下用此設定＋年度即時組出；換 NAS 只需改這裡（既有檔案需一併搬移）。</div>
    </div>
    <div class="m-foot">
        <button class="b-cancel" onclick="closeMask('cycMask')">取消</button>
        <button class="b-ok" onclick="submitCycle()">儲存</button>
    </div>
</div></div>

<!-- 稽核員資格設定 modal -->
<div class="va-mask" id="audMask"><div class="va-modal wide">
    <div class="m-head"><span>稽核員資格設定</span><span class="m-close" onclick="closeMask('audMask')">✕</span></div>
    <div class="m-body">
        <div style="font-size:12px;color:#8a6d45;margin-bottom:6px;">
            設定管理供應商的部門與稽核員。<b>外包加工</b>與<b>採購</b>供應商的管理部門通常不同，請分別指定；「通用」表示兩者皆可稽核。
        </div>
        <div style="display:flex;gap:6px;align-items:center;flex-wrap:wrap;border:1px dashed #E8D5B5;border-radius:6px;padding:8px;margin-bottom:8px;">
            <label style="margin:0;font-size:12px;">管理範圍</label>
            <select id="audScope" style="height:28px;border:1px solid #D8BE93;border-radius:4px;">
                <option value="outsource">外包加工</option><option value="purchase">採購</option><option value="all">通用</option>
            </select>
            <label style="margin:0;font-size:12px;">部門</label>
            <select id="audDept" style="height:28px;border:1px solid #D8BE93;border-radius:4px;min-width:120px;"><option value="">選部門…</option></select>
            <button type="button" class="b-att2" onclick="audAddChecked()"><i class="fa fa-user-plus"></i> 加入勾選為稽核員</button>
            <label style="margin:0;font-size:12px;"><input type="checkbox" id="audAll"> 全選</label>
        </div>
        <div id="audPeople" style="display:flex;flex-wrap:wrap;gap:4px 14px;max-height:120px;overflow-y:auto;border:1px solid #EADFC8;border-radius:6px;padding:6px 8px;margin-bottom:10px;font-size:12px;color:#5b3a1e;"><span style="color:#b0a390;">選部門載入人員</span></div>
        <div style="font-weight:bold;color:#5b3a1e;margin-bottom:4px;">目前稽核員</div>
        <div class="va-table-wrap"><table class="va-table" style="font-size:12px;"><thead><tr><th>管理範圍</th><th>部門</th><th>姓名</th><th></th></tr></thead>
            <tbody id="audList"><tr><td colspan="4" style="padding:12px;color:#8a6d45;">尚未設定</td></tr></tbody></table></div>
    </div>
    <div class="m-foot"><button class="b-cancel" onclick="closeMask('audMask')">關閉</button></div>
</div></div>

<!-- 定期評核門檻設定 modal -->
<div class="va-mask" id="evSetMask"><div class="va-modal">
    <div class="m-head"><span>定期評核門檻設定</span><span class="m-close" onclick="closeMask('evSetMask')">✕</span></div>
    <div class="m-body">
        <div class="grid2">
            <div><label>不良率上限（%）</label><input type="number" id="stNgMax" step="0.1" min="0"></div>
            <div><label>遲交率上限（%）</label><input type="number" id="stLateMax" step="0.1" min="0"></div>
            <div><label>特採率上限（%，100=不判定）</label><input type="number" id="stSpMax" step="0.1" min="0"></div>
            <div><label>約定工作天（算應交日）</label><input type="number" id="stDays" step="1" min="0"></div>
        </div>
        <div style="font-size:12px;color:#8a6d45;margin:8px 0 12px;">半年不良率／遲交率超過上限即判不合格；特採率上限設 100 表示不納入判定。約定工作天沿用 KPI#7 準交口徑。</div>
        <label>評核等級門檻（分數 ≥ 該值即為該等級，由高到低）</label>
        <div id="stGrades" style="border:1px solid #EADFC8;border-radius:6px;padding:6px 8px;"></div>
        <div style="margin:4px 0 12px;"><button type="button" class="b-att2" onclick="gradeAddRow('',0)"><i class="fa fa-plus"></i> 新增等級</button>
            <span style="font-size:11px;color:#8a6d45;">總分0~100；例：A≥90、B≥80、C≥70、D≥0</span></div>
        <label>綁定 AS 表單 —— 全部列印的文件名稱/編號跟 AS 文件管理連動</label>
        <div style="display:flex;gap:6px;align-items:center;flex-wrap:wrap;">
            <input type="text" id="stAsKw" placeholder="搜尋文件編號/名稱" style="width:150px;">
            <select id="stAsDoc" style="flex:1;min-width:200px;"><option value="0">（不綁定，用預設「供應商定期評核表 / 2-PH-01-05」）</option></select>
        </div>
    </div>
    <div class="m-foot">
        <button class="b-cancel" onclick="closeMask('evSetMask')">取消</button>
        <button class="b-ok" onclick="submitEvSet()">儲存</button>
    </div>
</div></div>

<!-- 歷史 modal -->
<div class="va-mask" id="hisMask"><div class="va-modal wide">
    <div class="m-head"><span id="hisTitle">稽核歷史</span><span class="m-close" onclick="closeMask('hisMask')">✕</span></div>
    <div class="m-body" id="hisBody" style="font-size:13px;color:#5b3a1e;"></div>
</div></div>

<!-- 角色說明 modal -->
<div class="va-mask" id="helpMask"><div class="va-modal">
    <div class="m-head"><span>角色權限說明</span><span class="m-close" onclick="closeMask('helpMask')">✕</span></div>
    <div class="m-body" style="font-size:13px;color:#5b3a1e;line-height:1.8;">
        <b>稽核檢閱</b>：檢視本期對象/稽核歷史/統計與匯出。<br>
        <b>稽核登錄</b>：檢閱＋加入/移除本期對象、登錄稽核結果。<br>
        <b>稽核管理員</b>：登錄＋設定廠商納管、共用週期。<br>
        <b>管理者</b>：系統管理者固定擁有全部權限。<br>
        <hr style="border-color:#EADFC8;">
        本頁為 KPI「廠商稽核按時執行率(#6)」來源；每期對象由本頁挑選，執行率＝已完成÷對象數。停用廠商不列入。
    </div>
</div></div>

<script src="../../resource/js/jquery.min.js"></script>
<script src="../../resource/js/bootstrap.min.js"></script>
<script src="../../resource/js/nprogress.js"></script>
<script src="../../resource/js/custom.min.js"></script>
<script src="../../code/highcharts.js"></script>
<script src="../../code/highcharts-more.js"></script>
<script>
$(document).ready(function(){
    var $am = $('#sidebar-menu .nav.side-menu > li.active');
    if ($am.length) { $am.removeClass('active').find('ul.child_menu').hide(); $am.find('li.current-page').removeClass('current-page'); }
    $('#sidebar-menu').css('visibility','visible');
});

var API = '../../src/store/VendorAudit_API.php';
var META = null, TARGETS = [], PERMS = null, POOL = [], ROUND_YEAR = null;
function planTimeliness(t){
    if (!t.plan_month) return '';
    var planYM = ROUND_YEAR+'-'+('0'+t.plan_month).slice(-2);
    if (t.audit_date){ var doneYM = String(t.audit_date).substr(0,7);
        return doneYM<=planYM ? ' <span class="af-judge-pass" style="font-size:11px;">準時</span>'
                              : ' <span class="af-judge-fail" style="font-size:11px;">逾期</span>'; }
    var nowYM = (META.today||'').substr(0,7);
    return nowYM>planYM ? ' <span class="af-judge-fail" style="font-size:11px;">逾期未做</span>' : '';
}
var canView = <?= $perms['canView'] ? 'true' : 'false' ?>;
var RESULT_LABEL = {pass:'合格', conditional:'限期改善', fail:'不合格'};

function closeMask(id){ document.getElementById(id).style.display='none'; }
function openMask(id){ document.getElementById(id).style.display='block'; }
function esc(s){ return String(s==null?'':s).replace(/[&<>"']/g,function(c){
    return {'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#39;'}[c]; }); }
function fmtDate(d){ return d ? String(d).substr(0,10) : ''; }

function loadMeta(cb){
    $.getJSON(API, {action:'meta'}, function(m){
        if (!m.ok){ alert(m.error||'載入失敗'); return; }
        META = m; PERMS = m.perms;
        var $y = $('#yearSel').empty(), cy = m.cur_year;
        for (var y=cy; y>=cy-5; y--) $y.append('<option value="'+y+'">'+y+'</option>');
        $y.val(cy);
        $('#halfSel').val(m.cur_half);
        var opt = '<option value="">全部</option>';
        m.main_categories.forEach(function(c){ opt += '<option value="'+c.main_cat_id+'">'+esc(c.main_cat_name)+'</option>'; });
        $('#pkMain').html(opt);
        var mo = '<option value="">未定</option>';
        for (var mi=1; mi<=12; mi++) mo += '<option value="'+mi+'">'+mi+'月</option>';
        $('#pkMonth').html(mo).val(m.cur_month);
        $('#recPlanMonth').html(mo);
        if (m.perms.canEdit) $('#btnPick').show();
        if (m.perms.canAdmin){ $('#btnCycle').show(); $('#btnAuditor').show(); $('#pkManageGrp').show(); $('#evSet').show(); }
        var $ey = $('#evYear').empty();
        for (var yy=m.cur_year; yy>=m.cur_year-5; yy--) $ey.append('<option value="'+yy+'">'+yy+'</option>');
        $ey.val(m.cur_year);
        loadEvVendors('');
        if (cb) cb();
    });
}

function loadRound(){
    NProgress.start();
    $.getJSON(API, {action:'round', year:$('#yearSel').val(), half:$('#halfSel').val()}, function(res){
        NProgress.done();
        if (!res.ok){ alert(res.error||'載入失敗'); return; }
        TARGETS = res.targets; PERMS = res.perms; ROUND_YEAR = res.year;
        renderStat(res); renderRemind(res); renderTargets();
    }).fail(function(x){ NProgress.done(); alert('載入失敗：'+(x.responseJSON&&x.responseJSON.error||x.status)); });
}

function renderStat(res){
    var lab = res.year+' '+(res.half===1?'上半年':'下半年');
    $('#stDen').text(res.stat.den); $('#stNum').text(res.stat.num);
    var $r = $('#stRate');
    if (res.stat.value === null){ $r.text('—').removeClass('below ok'); $('#stHint').text(lab+(res.round_exists?'：尚無對象或全為停用':'：本期尚未建立')); }
    else { var v = Math.round(res.stat.value*10)/10;
        $r.text(v+'%').toggleClass('below', v<70).toggleClass('ok', v>=70);
        $('#stHint').text(lab+'：'+res.stat.num+' / '+res.stat.den+' 已完成'); }
}
function renderRemind(res){
    var h = '共用稽核週期：<b>'+res.cycle_months+'</b> 月';
    if (res.last_round) h += '　｜　最近一期：'+res.last_round.year+' '+(res.last_round.half==1?'上半年':'下半年');
    $('#remind').html(h);
}

function renderTargets(){
    var html = '';
    TARGETS.forEach(function(t){
        var done = !!t.audit_date;
        var stat = t.disabled ? '<span class="st-pill st-dis">停用</span>'
                 : (done ? '<span class="st-pill st-done">已完成</span>' : '<span class="st-pill st-todo">未稽核</span>');
        html += '<tr'+(t.disabled?' class="dis"':'')+'>';
        html += '<td>'+esc(t.maker_id_no)+'</td>';
        html += '<td class="t-left"><b>'+esc(t.maker_id||'')+'</b></td>';
        html += '<td>'+esc(t.main_cat_name||'—')+'</td>';
        html += '<td>'+(t.plan_month?t.plan_month+'月':'—')+'</td>';
        html += '<td>'+stat+(t.disabled?'':planTimeliness(t))+'</td>';
        html += '<td>'+(fmtDate(t.audit_date)||'—')+'</td>';
        html += '<td>'+(t.overall_rate==null?'—':t.overall_rate+'%')+'</td>';
        html += '<td>'+(t.judge?(t.judge==='pass'?'<span class="af-judge-pass">合格</span>':'<span class="af-judge-fail">不合格</span>'):'—')+'</td>';
        html += '<td>'+esc(t.auditor||'—')+'</td>';
        html += '<td>';
        if (PERMS.canEdit) html += '<span class="va-op" onclick="openRec('+t.target_id+')"><i class="fa fa-pencil"></i>登錄</span>';
        if (t.audit_date) html += '<span class="va-op" onclick="openRecordSheet('+t.target_id+')"><i class="fa fa-file-text-o"></i>記錄表</span>';
        html += '<span class="va-op" onclick="openHis(\''+esc(t.maker_id_no)+'\')"><i class="fa fa-history"></i>歷史</span>';
        if (PERMS.canEdit) html += '<span class="va-op" style="color:#DD5138;" onclick="removeTarget('+t.target_id+')"><i class="fa fa-times"></i>移除</span>';
        html += '</td></tr>';
    });
    $('#vaBody').html(html || '<tr><td colspan="10" style="padding:16px;color:#8a6d45;">本期尚無稽核對象，請按「加入稽核對象」挑選</td></tr>');
}

$('#yearSel,#halfSel').on('change', loadRound);

/* ---------- 廠商池挑選 ---------- */
$('#btnPick').on('click', function(){
    $('#pkTitle').text('加入稽核對象（'+$('#yearSel').val()+' '+($('#halfSel').val()==1?'上半年':'下半年')+'）');
    $('#pkBody').html('<tr><td colspan="5" style="padding:14px;color:#8a6d45;">請設定條件後查詢</td></tr>');
    $('#pkCount').text(''); $('#pkAll').prop('checked', false);
    openMask('pkMask');
    loadPool();
});
$('#pkMain').on('change', function(){
    var mc = $(this).val();
    var $s = $('#pkSub').html('<option value="">全部</option>');
    if (mc){ $.getJSON(API, {action:'subcats', main_cat_id:mc}, function(res){
        if (res.ok) res.subcats.forEach(function(s){ $s.append('<option value="'+s.sub_cat_id+'">'+esc(s.sub_cat_name)+'</option>'); });
    }); }
    loadPool();
});
$('#pkSub,#pkManagedOnly').on('change', loadPool);
$('#pkKw').on('keydown', function(e){ if (e.key==='Enter') loadPool(); });
$('#pkAll').on('change', function(){ $('#pkBody input.pk-ck').prop('checked', this.checked); });

function loadPool(){
    NProgress.start();
    $.getJSON(API, {action:'pool', year:$('#yearSel').val(), half:$('#halfSel').val(),
        main_cat_id:$('#pkMain').val()||0, sub_cat_id:$('#pkSub').val()||0,
        kw:$('#pkKw').val(), managed_only:$('#pkManagedOnly').is(':checked')?1:0}, function(res){
        NProgress.done();
        if (!res.ok){ alert(res.error||'載入失敗'); return; }
        POOL = res.pool;
        $('#pkCount').text('符合 '+POOL.length+' 家'+(res.capped?'（僅顯示前 500，請縮小條件）':''));
        var html = '';
        POOL.forEach(function(p){
            html += '<label class="pk-item" title="'+esc(p.maker_id_no)+' '+esc(p.maker_id||'')+'／'+esc(p.main_cat_name||'')+'">'
                + '<input type="checkbox" class="pk-ck" value="'+esc(p.maker_id_no)+'">'
                + '<span class="no">'+esc(p.maker_id_no)+'</span>'
                + '<span class="nm">'+esc(p.maker_id||'')+'</span>'
                + (p.audit_managed===1 ? '<span class="mg">✔納</span>' : '')
                + '</label>';
        });
        $('#pkBody').html(html || '<div class="empty">無符合條件的廠商</div>');
        $('#pkAll').prop('checked', false);
    }).fail(function(x){ NProgress.done(); alert('載入失敗：'+(x.responseJSON&&x.responseJSON.error||x.status)); });
}
function pkChecked(){ return $('#pkBody input.pk-ck:checked').map(function(){ return this.value; }).get(); }

function addSelected(){
    var ids = pkChecked();
    if (!ids.length){ alert('請勾選要加入的廠商'); return; }
    $.post(API, {action:'add_targets', year:$('#yearSel').val(), half:$('#halfSel').val(), maker_ids:ids.join(','), plan_month:$('#pkMonth').val()},
    function(res){
        if (!res.ok){ alert(res.error||'加入失敗'); return; }
        alert('已加入 '+res.added+' 家'); loadPool(); loadRound();
    }, 'json').fail(function(x){ alert('加入失敗：'+(x.responseJSON&&x.responseJSON.error||x.status)); });
}
function randomDraw(){
    var n = parseInt($('#pkRandN').val(),10);
    if (!n || n<1){ alert('請輸入抽取家數'); return; }
    $.post(API, {action:'random_targets', year:$('#yearSel').val(), half:$('#halfSel').val(), n:n,
        main_cat_id:$('#pkMain').val()||0, sub_cat_id:$('#pkSub').val()||0, plan_month:$('#pkMonth').val()}, function(res){
        if (!res.ok){ alert(res.error||'抽取失敗'); return; }
        alert('已隨機加入 '+res.added+' 家'+(res.note?'\n'+res.note:'')); loadPool(); loadRound();
    }, 'json').fail(function(x){ alert('抽取失敗：'+(x.responseJSON&&x.responseJSON.error||x.status)); });
}
function bulkManaged(v){
    var ids = pkChecked();
    if (!ids.length){ alert('請勾選廠商'); return; }
    $.post(API, {action:'set_managed', maker_ids:ids.join(','), managed:v}, function(res){
        if (!res.ok){ alert(res.error||'設定失敗'); return; }
        loadPool();
    }, 'json').fail(function(x){ alert('設定失敗：'+(x.responseJSON&&x.responseJSON.error||x.status)); });
}

/* ---------- 稽核評鑑表單 ---------- */
var recTid = null;
function scoreCheck(el){
    var v = $.trim($(el).val());
    var bad = v!=='' && (!/^\d+$/.test(v) || +v<0 || +v>META.item_max);
    $(el).toggleClass('af-invalid', bad);
    return !bad;
}
function openRec(tid){
    recTid = tid;
    $.getJSON(API, {action:'get_form', target_id:tid}, function(res){
        if(!res.ok){ alert(res.error||'載入失敗'); return; }
        var t = res.target;
        $('#recTitle').text('稽核評鑑表單：'+t.maker_id+'（'+t.maker_id_no+'）');
        $('#recPlanMonth').val(t.plan_month||'');
        $('#recDate').val(fmtDate(t.audit_date)||META.today);
        $('#recMode').val(t.audit_mode||'first');
        // 稽核員下拉：只列該供應商 scope(外包加工/採購)＋通用 的有資格者
        var $au = $('#recAuditor').html('<option value="">—</option>');
        (res.auditors||[]).forEach(function(a){
            $au.append('<option value="'+esc(a.user_name)+'">'+esc(a.user_name)+'（'+esc(a.dept_name||'')+'／'+scopeLabel(a.scope)+'）</option>');
        });
        // 若舊資料的稽核員不在名單內，補一個 option 以免存檔遺失
        if (t.auditor && $au.find('option[value="'+t.auditor.replace(/"/g,'')+'"]').length===0)
            $au.append('<option value="'+esc(t.auditor)+'">'+esc(t.auditor)+'（原紀錄）</option>');
        $au.val(t.auditor||'');
        $('#recScopeHint').text('本供應商屬「'+(t.scope_label||'')+'」'+((res.auditors||[]).length?'':'—尚未設定稽核員，請先按工具列「稽核員設定」'));
        $('#recSelfEval').val(t.self_evaluator||''); $('#recReport').val(t.report_no||'');
        $('#recConclusion').val(t.conclusion||''); $('#recNote').val(t.note||'');
        renderAttach(t.target_id, res.attaches||[]);
        renderForm(t.scores||{});
        openMask('recMask');
    });
}
function scopeLabel(s){ return {outsource:'外包加工',purchase:'採購',all:'通用'}[s]||s; }
/* ---------- 佐證附件 ---------- */
function renderAttach(tid, list){
    $('#afAttachBox').data('tid', tid);
    var h='';
    (list||[]).forEach(function(a){
        h += '<div style="display:flex;gap:8px;align-items:center;border-bottom:1px dashed #EADFC8;padding:3px 0;">';
        h += a.exists ? '<a href="'+API+'?action=attach_open&attach_id='+a.attach_id+'" target="_blank" style="color:#b5762a;flex:1;overflow:hidden;text-overflow:ellipsis;">📄 '+esc(a.original_name||'')+'</a>'
                      : '<span style="color:#c9bda9;text-decoration:line-through;flex:1;">📄 '+esc(a.original_name||'')+'(檔案不存在)</span>';
        h += '<span style="color:#8a6d45;font-size:11px;">'+esc(a.note||'')+'　'+esc(a.uploaded_by||'')+'</span>';
        if (PERMS.canEdit) h += '<span class="af-del" style="color:#DD5138;cursor:pointer;" onclick="delAttach('+a.attach_id+')"><i class="fa fa-trash"></i></span>';
        h += '</div>';
    });
    $('#afAttachList').html(h||'<span style="color:#8a6d45;">尚無附件</span>');
    $('#afAttachUp').toggle(!!PERMS.canEdit);
}
function uploadAttach(){
    var tid=$('#afAttachBox').data('tid'), f=document.getElementById('afAttachFile');
    if(!f.files.length){ alert('請選擇檔案'); return; }
    var fd=new FormData();
    fd.append('action','attach_upload'); fd.append('target_id',tid);
    fd.append('note',$('#afAttachNote').val()); fd.append('file',f.files[0]);
    NProgress.start();
    $.ajax({url:API,method:'POST',data:fd,processData:false,contentType:false,dataType:'json'})
     .done(function(res){ NProgress.done(); if(!res.ok){alert(res.error||'上傳失敗');return;}
         f.value=''; $('#afAttachNote').val(''); reloadAttach(tid); })
     .fail(function(x){ NProgress.done(); alert('上傳失敗：'+(x.responseJSON&&x.responseJSON.error||x.status)); });
}
function delAttach(aid){
    if(!confirm('刪除此附件？')) return;
    $.post(API,{action:'attach_delete',attach_id:aid},function(res){
        if(!res.ok){alert(res.error||'刪除失敗');return;}
        reloadAttach($('#afAttachBox').data('tid'));
    },'json');
}
function reloadAttach(tid){
    $.getJSON(API,{action:'get_form',target_id:tid},function(res){ if(res.ok) renderAttach(tid,res.target?res.attaches:[]); });
}
function renderForm(scores){
    var html='';
    META.items.forEach(function(cat){
        html+='<tr class="af-cat"><td class="af-q">'+esc(cat[1])+'</td><td class="af-sc">自評</td><td class="af-sc">稽核</td></tr>';
        cat[2].forEach(function(it){
            var iid=it[0], s=scores[iid]||{};
            html+='<tr data-iid="'+iid+'">';
            html+='<td class="af-q">'+iid+'. '+esc(it[1])+'</td>';
            html+='<td class="af-sc"><input type="number" class="af-self af-score" min="0" max="'+META.item_max+'" step="1" placeholder="0~'+META.item_max+'" value="'+(s.self==null?'':s.self)+'" oninput="scoreCheck(this);recompute()"></td>';
            html+='<td class="af-sc"><input type="number" class="af-audit af-score" min="0" max="'+META.item_max+'" step="1" placeholder="0~'+META.item_max+'" value="'+(s.audit==null?'':s.audit)+'" oninput="scoreCheck(this);recompute()"></td>';
            html+='</tr>';
        });
    });
    $('#afBody').html(html);
    recompute();
}
function collectScores(){
    var scores={};
    $('#afBody tr[data-iid]').each(function(){
        var iid=$(this).data('iid'), self=$(this).find('.af-self').val(), audit=$(this).find('.af-audit').val();
        if(self!==''||audit!=='') scores[iid]={self:self===''?null:+self, audit:audit===''?null:+audit};
    });
    return scores;
}
function recompute(){
    var MAXI=META.item_max, pass=META.pass_rate, sw=META.self_w, aw=META.audit_w, scores=collectScores();
    var rows='<table><tr><th>分類</th><th>滿分</th><th>自評分</th><th>稽核分</th><th>自評率</th><th>稽核率</th></tr>';
    var tSelf=0,tAudit=0,tMax=0;
    META.items.forEach(function(cat){
        var items=cat[2], cMax=items.length*MAXI, cSelf=0, cAudit=0;
        items.forEach(function(it){ var s=scores[it[0]]||{};
            cSelf+=Math.max(0,Math.min(MAXI,+s.self||0)); cAudit+=Math.max(0,Math.min(MAXI,+s.audit||0)); });
        tSelf+=cSelf; tAudit+=cAudit; tMax+=cMax;
        rows+='<tr><td>'+esc(cat[1])+'</td><td>'+cMax+'</td><td>'+cSelf+'</td><td>'+cAudit+'</td><td>'+(cMax?Math.round(cSelf/cMax*1000)/10:0)+'%</td><td>'+(cMax?Math.round(cAudit/cMax*1000)/10:0)+'%</td></tr>';
    });
    var selfR=tMax?Math.round(tSelf/tMax*1000)/10:0, auditR=tMax?Math.round(tAudit/tMax*1000)/10:0;
    var overall=Math.round((selfR*sw+auditR*aw)*10)/10, ok=overall>=pass;
    rows+='<tr class="af-total"><td>總成績</td><td>'+tMax+'</td><td>'+tSelf+'</td><td>'+tAudit+'</td><td>'+selfR+'%</td><td>'+auditR+'%</td></tr></table>';
    rows+='<div style="margin-top:6px;">綜合合格率（自評×'+sw+'＋稽核×'+aw+'）：<b style="font-size:15px;">'+overall+'%</b>　判定：'
        +(ok?'<span class="af-judge-pass">合格 (≥'+pass+'%)</span>':'<span class="af-judge-fail">不合格 (<'+pass+'%)</span>')+'</div>';
    $('#afSummary').html(rows);
}
function submitRec(){
    // 送出前全面驗證，有超出範圍即阻擋
    var bad=0;
    $('#afBody .af-score').each(function(){ if(!scoreCheck(this)) bad++; });
    if(bad){ alert('有 '+bad+' 個分數超出範圍或非整數（限 0~'+META.item_max+'），已標紅，請修正後再儲存'); return; }
    var scores=collectScores();
    $.post(API, {action:'record_target', target_id:recTid, audit_date:$('#recDate').val(), plan_month:$('#recPlanMonth').val(),
        audit_mode:$('#recMode').val(), auditor:$('#recAuditor').val(),
        self_evaluator:$('#recSelfEval').val(), report_no:$('#recReport').val(),
        conclusion:$('#recConclusion').val(), note:$('#recNote').val(), scores:JSON.stringify(scores)},
    function(res){
        if(!res.ok){ alert(res.error||'儲存失敗'); return; }
        closeMask('recMask'); loadRound();
    }, 'json').fail(function(x){ alert('儲存失敗：'+(x.responseJSON&&x.responseJSON.error||x.status)); });
}
function removeTarget(tid){
    if (!confirm('將此廠商移出本期稽核對象？')) return;
    $.post(API, {action:'remove_target', target_id:tid}, function(res){
        if (!res.ok){ alert(res.error||'移除失敗'); return; }
        loadRound();
    }, 'json');
}

/* ---------- 週期設定 ---------- */
$('#btnCycle').on('click', function(){
    $('#cycVal').val(META.cycle_months); $('#cycAttachBase').val(META.attach_base||'');
    loadAsForms('#cycAsDoc', '', META.as_doc, '供應商評鑑稽核查表 / 2-PH-01-02');
    loadAsForms('#cycRecDoc', '', META.record_as_doc, '供應商品質系統評鑑記錄表 / 2-PH-01-03');
    openMask('cycMask');
});
var cycAsT=null, cycRecT=null;
$('#cycAsKw').on('input', function(){ clearTimeout(cycAsT); var k=$(this).val(); cycAsT=setTimeout(function(){ loadAsForms('#cycAsDoc', k, META.as_doc, '供應商評鑑稽核查表 / 2-PH-01-02', +$('#cycAsDoc').val()); }, 300); });
$('#cycRecKw').on('input', function(){ clearTimeout(cycRecT); var k=$(this).val(); cycRecT=setTimeout(function(){ loadAsForms('#cycRecDoc', k, META.record_as_doc, '供應商品質系統評鑑記錄表 / 2-PH-01-03', +$('#cycRecDoc').val()); }, 300); });
function loadAsForms(sel, kw, curDoc, defLabel, keepId){
    var selId = keepId!=null ? keepId : (curDoc?curDoc.id:0);
    $.getJSON(API, {action:'as_forms', kw:kw||''}, function(res){
        if(!res.ok) return;
        var $s=$(sel).html('<option value="0">（不綁定，用預設「'+defLabel+'」）</option>');
        (res.forms||[]).forEach(function(f){ $s.append('<option value="'+f.id+'">'+esc(f.doc_no)+' '+esc(f.doc_name)+'</option>'); });
        if(selId && $s.find('option[value="'+selId+'"]').length===0 && curDoc)
            $s.append('<option value="'+curDoc.id+'">'+esc(curDoc.doc_no)+' '+esc(curDoc.doc_name)+'（目前綁定）</option>');
        $s.val(selId||0);
    });
}
function submitCycle(){
    $.post(API, {action:'save_cycle', cycle_months:$('#cycVal').val(), attach_base:$('#cycAttachBase').val(),
        as_doc_id:$('#cycAsDoc').val(), record_as_doc_id:$('#cycRecDoc').val()}, function(res){
        if (!res.ok){ alert(res.error||'儲存失敗'); return; }
        META.cycle_months = res.cycle_months; META.attach_base = res.attach_base; META.as_doc = res.as_doc; META.record_as_doc = res.record_as_doc; closeMask('cycMask'); loadRound();
    }, 'json');
}

/* ---------- 歷史 ---------- */
function openHis(mid){
    $.getJSON(API, {action:'vendor_history', maker_id_no:mid}, function(res){
        if (!res.ok){ alert(res.error||'載入失敗'); return; }
        $('#hisTitle').text('稽核歷史：'+res.maker_name+'（'+mid+'）');
        if (!res.list.length){ $('#hisBody').html('<div style="color:#8a6d45;padding:12px;">尚無稽核紀錄</div>'); openMask('hisMask'); return; }
        var h = '<table class="hist"><thead><tr><th>期別</th><th>稽核日</th><th>綜合合格率</th><th>判定</th><th>稽核員</th><th>報告編號</th><th>備註</th></tr></thead><tbody>';
        res.list.forEach(function(a){
            h += '<tr><td>'+a.year+' '+(a.half==1?'上半年':'下半年')+'</td>';
            h += '<td>'+(fmtDate(a.audit_date)||'未稽核')+'</td>';
            h += '<td>'+(a.overall_rate==null?'—':a.overall_rate+'%')+'</td>';
            h += '<td>'+(a.judge?(a.judge==='pass'?'<span class="af-judge-pass">合格</span>':'<span class="af-judge-fail">不合格</span>'):'—')+'</td>';
            h += '<td>'+esc(a.auditor||'—')+'</td>';
            h += '<td>'+esc(a.report_no||'—')+'</td><td>'+esc(a.note||'—')+'</td></tr>';
        });
        h += '</tbody></table>';
        $('#hisBody').html(h); openMask('hisMask');
    });
}

/* ---------- 列印評鑑表單 ---------- */
function auditFormHTML(o){
    o = o || {};
    var docName = (META.as_doc && META.as_doc.doc_name) || '供應商評鑑稽核查表';
    var docNo   = (META.as_doc && META.as_doc.doc_no)   || '2-PH-01-02';
    var head = '<div style="text-align:center;">'
        + '<div style="font-size:18px;font-weight:bold;">'+esc(META.company_name||'')+'</div>'
        + '<div style="font-size:15px;margin-top:2px;">'+esc(docName)+'</div></div>';
    var info = '<table class="pf-info"><tr>'
        + '<td>供應商：'+(o.maker?esc(o.maker):'________________')+'</td>'
        + '<td>日期：'+(o.dateStr?esc(o.dateStr):'____ / ____ / ____')+'</td></tr>'
        + '<tr><td colspan="2">稽核狀況：□首次稽核　□次稽核　□自我評量　　　評分：每項 0~7 分（0＝最差、7＝最佳；無此流程填 NA）</td></tr></table>';
    var rows = '<table class="pf"><thead><tr><th style="width:70px;">項目</th><th style="width:34px;">項次</th><th>查核問題</th>'
        + '<th style="width:44px;">自評分</th><th style="width:44px;">稽核分</th><th style="width:180px;">佐證／觀察結果</th></tr></thead><tbody>';
    META.items.forEach(function(cat){
        var items = cat[2];
        items.forEach(function(it, idx){
            var s = (o.scores && o.scores[it[0]]) || {};
            rows += '<tr>';
            if (idx===0) rows += '<td rowspan="'+items.length+'" style="vertical-align:middle;font-weight:bold;">'+esc(cat[1])+'</td>';
            rows += '<td>'+it[0]+'</td><td class="q">'+esc(it[1])+'</td>'
                + '<td>'+(s.self!=null?s.self:'')+'</td><td>'+(s.audit!=null?s.audit:'')+'</td><td></td></tr>';
        });
    });
    rows += '<tr><td colspan="2">合計</td><td style="text-align:right;">總分（滿分 '+META.total_max+'）／綜合合格率＝自評率×'+META.self_w+'＋稽核率×'+META.audit_w+'，≥'+META.pass_rate+'% 判合格</td><td></td><td></td><td></td></tr>';
    rows += '</tbody></table>';
    var sign = '<table class="pf-sign"><tr><td>供應商代表簽章：__________________</td><td>稽核員簽章：__________________</td></tr></table>';
    var footer = '<div style="text-align:right;margin-top:22px;font-size:12px;color:#333;">表單編號：'+esc(docNo)+'</div>';
    return head + info + rows + sign + footer;
}
function openPrintWindow(bodyHtml, title){
    var w = window.open('', '_blank');
    if (!w){ alert('請允許彈出視窗以列印'); return; }
    var css = 'body{font-family:"Microsoft JhengHei","微軟正黑體",sans-serif;color:#000;padding:14px;}'
        + 'table.pf{width:100%;border-collapse:collapse;font-size:12px;margin-top:8px;}'
        + 'table.pf th,table.pf td{border:1px solid #333;padding:4px 6px;text-align:center;vertical-align:middle;}'
        + 'table.pf td.q{text-align:left;}'
        + 'table.pf-info{width:100%;font-size:13px;margin-top:12px;border-collapse:collapse;}table.pf-info td{padding:4px 6px;border:1px solid #999;}'
        + 'table.pf-sign{width:100%;margin-top:18px;font-size:13px;}table.pf-sign td{padding:12px 6px;}'
        + '@media print{@page{size:A4;margin:12mm;}}';
    w.document.write('<html><head><meta charset="utf-8"><title>'+esc(title)+'</title><style>'+css+'</style></head><body>'
        + bodyHtml + '<scr'+'ipt>window.onload=function(){setTimeout(function(){window.print();},150);};</scr'+'ipt></body></html>');
    w.document.close();
}
function printBlankForm(){ openPrintWindow(auditFormHTML({}), '供應商評鑑稽核查表'); }
function printCurrentForm(){
    openPrintWindow(auditFormHTML({
        maker: $('#recTitle').text().replace('稽核評鑑表單：',''),
        dateStr: $('#recDate').val(), scores: collectScores()
    }), '供應商評鑑稽核查表');
}
$('#btnBlank').on('click', printBlankForm);

/* ---------- 評鑑記錄表（2-PH-01-03，雷達圖）---------- */
var RS = null, rsChart = null;
function computeCats(scores){
    var cats=[], tSelf=0,tAudit=0,tMax=0, MAXI=META.item_max;
    META.items.forEach(function(cat){
        var items=cat[2], cMax=items.length*MAXI, cSelf=0,cAudit=0;
        items.forEach(function(it){ var s=scores[it[0]]||{}; cSelf+=Math.max(0,Math.min(MAXI,+s.self||0)); cAudit+=Math.max(0,Math.min(MAXI,+s.audit||0)); });
        cats.push({name:cat[1], max:cMax, self_rate:cMax?Math.round(cSelf/cMax*1000)/10:0, audit_rate:cMax?Math.round(cAudit/cMax*1000)/10:0});
        tSelf+=cSelf;tAudit+=cAudit;tMax+=cMax;
    });
    var selfR=tMax?Math.round(tSelf/tMax*1000)/10:0, auditR=tMax?Math.round(tAudit/tMax*1000)/10:0;
    var overall=Math.round((selfR*META.self_w+auditR*META.audit_w)*10)/10;
    return {cats:cats, selfR:selfR, auditR:auditR, overall:overall, pass:overall>=META.pass_rate};
}
function openRecordSheet(tid){
    $.getJSON(API, {action:'get_form', target_id:tid}, function(res){
        if(!res.ok){ alert(res.error||'載入失敗'); return; }
        var t=res.target, c=computeCats(t.scores||{});
        RS={tid:tid, t:t, c:c};
        var doc=META.record_as_doc, docName=(doc&&doc.doc_name)||'供應商品質系統評鑑記錄表';
        $('#rsTitle').text(docName+'：'+t.maker_id+'（'+t.maker_id_no+'）');
        var modeL={first:'首次稽核',again:'次稽核',self:'自我評量'}[t.audit_mode]||'—';
        $('#rsInfo').html('供應商：<b>'+esc(t.maker_id)+'</b>（'+esc(t.maker_id_no)+'）　稽核日期：'+(fmtDate(t.audit_date)||'—')+'　稽核狀況：'+modeL+'　稽核員：'+esc(t.auditor||'—'));
        var rows='';
        c.cats.forEach(function(k){ var comb=Math.round((k.self_rate*META.self_w+k.audit_rate*META.audit_w)*10)/10;
            rows+='<tr><td class="af-q">'+esc(k.name)+'</td><td class="af-sc">'+k.max+'</td><td class="af-sc">'+k.self_rate+'%</td><td class="af-sc">'+k.audit_rate+'%</td><td class="af-sc">'+comb+'%</td></tr>'; });
        rows+='<tr class="af-cat"><td class="af-q">總成績</td><td class="af-sc">'+META.total_max+'</td><td class="af-sc">'+c.selfR+'%</td><td class="af-sc">'+c.auditR+'%</td><td class="af-sc">'+c.overall+'%</td></tr>';
        $('#rsCatBody').html(rows);
        $('#rsConc').html('綜合評鑑合格率（自評×'+META.self_w+'＋稽核×'+META.audit_w+'）：<b style="font-size:16px;">'+c.overall+'%</b>　判定：'
            +(c.pass?'<span class="af-judge-pass">合格供應商 (≥'+META.pass_rate+'%)</span>':'<span class="af-judge-fail">不合格 (<'+META.pass_rate+'%)</span>')
            +(t.conclusion?'　建議：'+esc(t.conclusion):''));
        rsRenderAttach(tid, res.attaches||[]);
        openMask('rsMask');
        setTimeout(drawRadar, 60);
    });
}
function drawRadar(){
    if(!RS||typeof Highcharts==='undefined') return;
    rsChart = Highcharts.chart('rsChart', {
        chart:{polar:true, type:'line', backgroundColor:'transparent'},
        title:{text:null}, credits:{enabled:false},
        pane:{size:'80%'},
        xAxis:{categories:RS.c.cats.map(function(k){return k.name;}), tickmarkPlacement:'on', lineWidth:0},
        yAxis:{gridLineInterpolation:'polygon', min:0, max:100, tickInterval:20},
        tooltip:{shared:true, valueSuffix:'%'},
        series:[{name:'自評', data:RS.c.cats.map(function(k){return k.self_rate;}), color:'#E8C170', pointPlacement:'on'},
                {name:'稽核', data:RS.c.cats.map(function(k){return k.audit_rate;}), color:'#F0A24B', pointPlacement:'on'}]
    });
}
function rsRenderAttach(tid, list){
    $('#rsAttachBox').data('tid', tid);
    var h='';
    (list||[]).forEach(function(a){
        h+='<div style="display:flex;gap:8px;align-items:center;border-bottom:1px dashed #EADFC8;padding:3px 0;">';
        h+=a.exists?'<a href="'+API+'?action=attach_open&attach_id='+a.attach_id+'" target="_blank" style="color:#b5762a;flex:1;">📄 '+esc(a.original_name||'')+'</a>'
                   :'<span style="color:#c9bda9;text-decoration:line-through;flex:1;">📄 '+esc(a.original_name||'')+'</span>';
        h+='<span style="color:#8a6d45;font-size:11px;">'+esc(a.note||'')+' '+esc(a.uploaded_by||'')+'</span>';
        if(PERMS.canEdit) h+='<span class="af-del" style="color:#DD5138;cursor:pointer;" onclick="rsDelAttach('+a.attach_id+')"><i class="fa fa-trash"></i></span>';
        h+='</div>';
    });
    $('#rsAttachList').html(h||'<span style="color:#8a6d45;">尚無附件</span>');
    $('#rsAttachUp').toggle(!!PERMS.canEdit);
}
function rsUploadAttach(){
    var tid=$('#rsAttachBox').data('tid'), f=document.getElementById('rsAttachFile');
    if(!f.files.length){ alert('請選擇檔案'); return; }
    var fd=new FormData(); fd.append('action','attach_upload'); fd.append('target_id',tid); fd.append('note',$('#rsAttachNote').val()); fd.append('file',f.files[0]);
    NProgress.start();
    $.ajax({url:API,method:'POST',data:fd,processData:false,contentType:false,dataType:'json'})
     .done(function(res){ NProgress.done(); if(!res.ok){alert(res.error||'上傳失敗');return;} f.value='';$('#rsAttachNote').val('');
        $.getJSON(API,{action:'get_form',target_id:tid},function(r){ if(r.ok) rsRenderAttach(tid,r.attaches); }); })
     .fail(function(x){ NProgress.done(); alert('上傳失敗：'+(x.responseJSON&&x.responseJSON.error||x.status)); });
}
function rsDelAttach(aid){
    if(!confirm('刪除此附件？')) return;
    $.post(API,{action:'attach_delete',attach_id:aid},function(res){ if(!res.ok){alert(res.error||'失敗');return;}
        var tid=$('#rsAttachBox').data('tid'); $.getJSON(API,{action:'get_form',target_id:tid},function(r){ if(r.ok) rsRenderAttach(tid,r.attaches); }); },'json');
}
function recordSheetHTML(){
    if(!RS) return '';
    var t=RS.t, c=RS.c, doc=META.record_as_doc, docName=(doc&&doc.doc_name)||'供應商品質系統評鑑記錄表', docNo=(doc&&doc.doc_no)||'2-PH-01-03';
    var modeL={first:'首次稽核',again:'次稽核',self:'自我評量'}[t.audit_mode]||'____';
    var head='<div style="text-align:center;"><div style="font-size:18px;font-weight:bold;">'+esc(META.company_name||'')+'</div>'
        +'<div style="font-size:15px;margin-top:2px;">'+esc(docName)+'</div></div>';
    var info='<table class="pf-info"><tr><td>供應商：'+esc(t.maker_id)+'（'+esc(t.maker_id_no)+'）</td><td>稽核日期：'+(fmtDate(t.audit_date)||'____')+'</td><td>稽核狀況：'+esc(modeL)+'</td></tr></table>';
    var rows='<table class="pf"><thead><tr><th>評鑑項目</th><th>單項滿分</th><th>自評合格率</th><th>稽核合格率</th><th>綜合合格率</th></tr></thead><tbody>';
    c.cats.forEach(function(k){ var comb=Math.round((k.self_rate*META.self_w+k.audit_rate*META.audit_w)*10)/10;
        rows+='<tr><td class="q">'+esc(k.name)+'</td><td>'+k.max+'</td><td>'+k.self_rate+'%</td><td>'+k.audit_rate+'%</td><td>'+comb+'%</td></tr>'; });
    rows+='<tr style="font-weight:bold;"><td class="q">總成績</td><td>'+META.total_max+'</td><td>'+c.selfR+'%</td><td>'+c.auditR+'%</td><td>'+c.overall+'%</td></tr></tbody></table>';
    var svg = rsChart ? rsChart.container.querySelector('svg').outerHTML : '';
    var chart='<div style="text-align:center;margin-top:8px;">'+svg+'</div>';
    var conc='<div style="margin-top:8px;font-size:13px;">綜合評鑑合格率（自評×'+META.self_w+'＋稽核×'+META.audit_w+'）＝<b>'+c.overall+'%</b>；核准條件：綜合合格率 ≥'+META.pass_rate+'% 始評定為合格供應商。判定：'+(c.pass?'合格供應商':'不合格')+(t.conclusion?'（'+esc(t.conclusion)+'）':'')+'</div>';
    var sign='<table class="pf-sign"><tr><td>稽核員簽章：____________</td><td>供應商代表簽章：____________</td><td>資材課長：____________</td></tr></table>';
    var footer='<div style="text-align:right;margin-top:14px;font-size:12px;">表單編號：'+esc(docNo)+'</div>';
    return head+info+rows+chart+conc+sign+footer;
}
function printRecordSheet(){ if(!RS){alert('無資料');return;} openPrintWindow(recordSheetHTML(), '供應商品質系統評鑑記錄表'); }
function printAllDocs(){
    if(!RS){alert('無資料');return;}
    var page1=auditFormHTML({maker:RS.t.maker_id+'（'+RS.t.maker_id_no+'）', dateStr:fmtDate(RS.t.audit_date), scores:RS.t.scores});
    var body='<div style="page-break-after:always;">'+page1+'</div>'+recordSheetHTML();
    openPrintWindow(body, '供應商稽核文件');
}

/* ---------- CSV ---------- */
$('#btnCsv').on('click', function(){
    var rows = [['廠商編號','廠商名稱','大類','預定月份','稽核狀態','稽核日','綜合合格率','判定','稽核員','報告編號','備註']];
    TARGETS.forEach(function(t){
        rows.push([t.maker_id_no, t.maker_id||'', t.main_cat_name||'', t.plan_month?t.plan_month+'月':'',
            t.disabled?'停用':(t.audit_date?'已完成':'未稽核'), fmtDate(t.audit_date),
            t.overall_rate==null?'':t.overall_rate+'%', t.judge?(t.judge==='pass'?'合格':'不合格'):'',
            t.auditor||'', t.report_no||'', t.note||'']);
    });
    var csv = '﻿' + rows.map(function(l){
        return l.map(function(v){ return '"'+String(v==null?'':v).replace(/"/g,'""')+'"'; }).join(',');
    }).join('\r\n');
    var a = document.createElement('a');
    a.href = URL.createObjectURL(new Blob([csv], {type:'text/csv;charset=utf-8;'}));
    a.download = '供應商稽核_'+$('#yearSel').val()+'_H'+$('#halfSel').val()+'.csv';
    a.click();
});

/* ---------- 分頁切換 ---------- */
$('.va-tab').on('click', function(){
    $('.va-tab').removeClass('active'); $(this).addClass('active');
    var t=$(this).data('tab');
    $('#tabAudit').toggle(t==='audit'); $('#tabEval').toggle(t==='eval');
    if (t==='eval') loadEvVendors($('#evKw').val()||'');   // 切入時重抓納管廠商(納管可能剛變動)
});

/* ---------- 定期評核 ---------- */
var EVAL = null;
function loadEvVendors(kw){
    $.getJSON(API, {action:'eval_vendors', kw:kw||''}, function(res){
        if(!res.ok) return;
        var $v=$('#evVendor').empty();
        if(!res.vendors.length){ $v.append('<option value="">（無納管廠商，請先於稽核批次設定納管或用搜尋）</option>'); return; }
        res.vendors.forEach(function(v){ $v.append('<option value="'+esc(v.maker_id_no)+'">'+esc(v.maker_id||v.maker_id_no)+'</option>'); });
    });
}
var evKwT=null;
$('#evKw').on('input', function(){ clearTimeout(evKwT); var k=$(this).val(); evKwT=setTimeout(function(){ loadEvVendors(k); }, 300); });
$('#evGo').on('click', loadEval);
$('#evVendor,#evYear').on('change', loadEval);
function rate(v){ return v==null?'—':v+'%'; }
function loadEval(){
    var mid=$('#evVendor').val(); if(!mid) return;
    NProgress.start();
    $.getJSON(API, {action:'periodic_eval', maker_id_no:mid, year:$('#evYear').val()}, function(res){
        NProgress.done();
        if(!res.ok){ alert(res.error||'查詢失敗'); return; }
        EVAL=res; EVAL_ALL=null;
        $('#evSingle').show(); $('#evCards').empty().hide(); $('#evEmpty').hide(); $('#evCsv').show(); $('#evFailBox').hide();
        var s=res.settings;
        $('#evThresh').html('廠商：<b>'+esc(res.maker_name)+'</b>　'+res.year+' 年　門檻：不良率≤'+s.ng_max+'%、遲交率≤'+s.late_max+'%'
            +(s.special_max<100?('、特採率≤'+s.special_max+'%'):'（特採率不判定）')+'　約定工作天 '+s.default_days+' 天');
        // 上方：半年/全年 分數與等級
        var sc=function(hf,lab){ if(!hf||hf.score==null) return '<div><span class="s-lab">'+lab+'</span> <span class="s-num" style="font-size:16px;">—</span></div>';
            return '<div><span class="s-lab">'+lab+'</span> <span class="s-num" style="font-size:18px;">'+hf.score+'</span>'
                +'<span class="s-lab"> 分</span> <b style="font-size:16px;color:'+(hf.judge==='fail'?'#DD5138':'#8A5A2B')+';">'+(hf.grade||'—')+'</b></div>'; };
        $('#evScoreTop').html(sc(res.halves[1],'上半年')+sc(res.halves[2],'下半年')+sc(res.full,'全年')
            +'<div class="s-lab" style="margin-left:auto;">分數＝(1-不良率)×50 +(1-遲交率)×50，依門檻判等級</div>');
        var overNg=function(v){return v!=null&&v>s.ng_max;}, overLt=function(v){return v!=null&&v>s.late_max;}, overSp=function(v){return s.special_max<100&&v!=null&&v>s.special_max;};
        var h='';
        for(var m=1;m<=12;m++){
            var d=res.months[m];
            h+='<tr><td>'+m+'月</td>';
            h+='<td>'+d.qc_in+'</td><td>'+d.ng+'</td>';
            h+='<td'+(overNg(d.ng_rate)?' class="af-judge-fail"':'')+'>'+rate(d.ng_rate)+'</td>';
            h+='<td'+(overSp(d.special_rate)?' class="af-judge-fail"':'')+'>'+rate(d.special_rate)+'</td>';
            h+='<td>'+d.del_in+'</td><td>'+d.late+'</td>';
            h+='<td'+(overLt(d.late_rate)?' class="af-judge-fail"':'')+'>'+rate(d.late_rate)+'</td></tr>';
            if(m===6) h+=halfRow(res.halves[1],'上半年',s);
            if(m===12) h+=halfRow(res.halves[2],'下半年',s);
        }
        $('#evBody').html(h);
    }).fail(function(x){ NProgress.done(); alert('查詢失敗：'+(x.responseJSON&&x.responseJSON.error||x.status)); });
}
function halfRow(hf,label,s){
    var judge=hf.judge?(hf.judge==='pass'?'<span class="af-judge-pass">合格</span>':'<span class="af-judge-fail">不合格</span>'):'—';
    return '<tr style="background:#FDF3E0;font-weight:bold;"><td>'+label+'</td>'
        +'<td>'+hf.qc_in+'</td><td>'+hf.ng+'</td><td>'+rate(hf.ng_rate)+'</td><td>'+rate(hf.special_rate)+'</td>'
        +'<td>'+hf.del_in+'</td><td>'+hf.late+'</td><td>'+rate(hf.late_rate)+'　'+judge+'</td></tr>';
}

/* ---------- 定期評核：全部納管廠商（2欄卡片，略過無資料） ---------- */
var EVAL_ALL = null;
$('#evAll').on('click', function(){
    NProgress.start();
    $.getJSON(API, {action:'periodic_eval_all', year:$('#evYear').val()}, function(res){
        NProgress.done();
        if(!res.ok){ alert(res.error||'載入失敗'); return; }
        EVAL_ALL=res; EVAL=null;
        var s=res.settings;
        $('#evThresh').html(res.year+' 年　全部納管廠商（'+res.vendors.length+' 家有資料，已略過無資料者）　門檻：不良率≤'+s.ng_max+'%、遲交率≤'+s.late_max+'%'
            +(s.special_max<100?('、特採率≤'+s.special_max+'%'):'（特採率不判定）')+'　約定工作天 '+s.default_days+' 天');
        $('#evSingle').hide(); $('#evEmpty').hide(); $('#evCsv').hide(); $('#evFailBox').show(); $('#evCards').css('display','grid');
        renderEvalCards();
    }).fail(function(x){ NProgress.done(); alert('載入失敗：'+(x.responseJSON&&x.responseJSON.error||x.status)); });
});
$('#evFailOnly').on('change', renderEvalCards);
function renderEvalCards(){
    if(!EVAL_ALL) return;
    var s=EVAL_ALL.settings, failOnly=$('#evFailOnly').is(':checked');
    var overNg=function(v){return v!=null&&v>s.ng_max;}, overLt=function(v){return v!=null&&v>s.late_max;}, overSp=function(v){return s.special_max<100&&v!=null&&v>s.special_max;};
    var list=EVAL_ALL.vendors.filter(function(v){ return !failOnly || v.fail; });
    var html='';
    list.forEach(function(v){
        var g=v.full&&v.full.grade?('等級 <b style="color:'+(v.fail?'#DD5138':'#8A5A2B')+';">'+esc(v.full.grade)+'</b>（'+(v.full.score==null?'—':v.full.score)+'分）'):'';
        html+='<div class="ev-card"><div class="h"><span>'+esc(v.maker_name)+'（'+esc(v.maker_id_no)+'）</span><span>'+g+'</span></div>';
        html+='<table class="ev-mini"><thead><tr><th>月</th><th>檢驗</th><th>不良率</th><th>特採率</th><th>回廠</th><th>遲交率</th></tr></thead><tbody>';
        for(var m=1;m<=12;m++){ var d=v.months[m];
            html+='<tr><td>'+m+'</td><td>'+d.qc_in+'</td>'
                +'<td'+(overNg(d.ng_rate)?' class="over"':'')+'>'+rate(d.ng_rate)+'</td>'
                +'<td'+(overSp(d.special_rate)?' class="over"':'')+'>'+rate(d.special_rate)+'</td>'
                +'<td>'+d.del_in+'</td>'
                +'<td'+(overLt(d.late_rate)?' class="over"':'')+'>'+rate(d.late_rate)+'</td></tr>';
            if(m===6) html+=miniHalf(v.halves[1],'上半年');
            if(m===12) html+=miniHalf(v.halves[2],'下半年');
        }
        html+='</tbody></table></div>';
    });
    $('#evCards').html(html||'<div style="padding:14px;color:#8a6d45;grid-column:1/-1;">'+(failOnly?'無不合格廠商':'無資料')+'</div>');
}
function miniHalf(hf,label){
    var j=hf.judge?(hf.judge==='pass'?'<span class="af-judge-pass">合格</span>':'<span class="af-judge-fail">不合格</span>'):'—';
    return '<tr class="half"><td>'+label+'</td><td>'+hf.qc_in+'</td><td>'+rate(hf.ng_rate)+'</td><td>'+rate(hf.special_rate)+'</td><td>'+hf.del_in+'</td><td>'+rate(hf.late_rate)+' '+j+'</td></tr>';
}
/* 全部評核 橫式列印：一頁6間(3欄×2列)，頁首公司名+文件名，右上頁碼，右下AS編號 */
function evCardPrintHTML(v){
    var s=EVAL_ALL.settings;
    var oNg=function(x){return x!=null&&x>s.ng_max?' class="over"':'';}, oLt=function(x){return x!=null&&x>s.late_max?' class="over"':'';}, oSp=function(x){return s.special_max<100&&x!=null&&x>s.special_max?' class="over"':'';};
    var h='<div class="pc"><div class="pc-h">'+esc(v.maker_name)+'（'+esc(v.maker_id_no)+'）'+(v.fail?'<span class="jf">不合格</span>':'<span class="jp">合格</span>')+'</div>'
        +'<table class="pm"><thead><tr><th>月</th><th>檢驗</th><th>不良率</th><th>特採率</th><th>回廠</th><th>遲交率</th></tr></thead><tbody>';
    for(var m=1;m<=12;m++){ var d=v.months[m];
        h+='<tr><td>'+m+'</td><td>'+d.qc_in+'</td><td'+oNg(d.ng_rate)+'>'+rate(d.ng_rate)+'</td><td'+oSp(d.special_rate)+'>'+rate(d.special_rate)+'</td><td>'+d.del_in+'</td><td'+oLt(d.late_rate)+'>'+rate(d.late_rate)+'</td></tr>';
        if(m===6||m===12){ var hf=v.halves[m===6?1:2]; var jj=hf.judge?(hf.judge==='pass'?'合格':'不合格'):'—';
            h+='<tr class="ph"><td>'+(m===6?'上半':'下半')+'</td><td>'+hf.qc_in+'</td><td>'+rate(hf.ng_rate)+'</td><td>'+rate(hf.special_rate)+'</td><td>'+hf.del_in+'</td><td>'+rate(hf.late_rate)+' '+jj+'</td></tr>'; }
    }
    return h+'</tbody></table></div>';
}
function printEvalAll(){
    if(!EVAL_ALL) return;
    var list=EVAL_ALL.vendors.filter(function(v){ return !$('#evFailOnly').is(':checked')||v.fail; });
    if(!list.length){ alert('無資料可列印'); return; }
    var doc=META.eval_as_doc, docName=(doc&&doc.doc_name)||'供應商定期評核表', docNo=(doc&&doc.doc_no)||'2-PH-01-05';
    var per=6, pages=Math.ceil(list.length/per), body='';
    for(var p=0;p<pages;p++){
        var cards='';
        for(var k=p*per;k<Math.min((p+1)*per,list.length);k++) cards+=evCardPrintHTML(list[k]);
        body+='<div class="pg"><div class="pg-h"><div class="ttl"><div class="co">'+esc(META.company_name||'')+'</div><div class="dn">'+esc(docName)+'（'+EVAL_ALL.year+' 年 定期評核）</div></div>'
            +'<div class="pn">第 '+(p+1)+' / '+pages+' 頁</div></div>'
            +'<div class="pg-cards">'+cards+'</div><div class="pg-f">表單編號：'+esc(docNo)+'</div></div>';
    }
    var w=window.open('','_blank'); if(!w){alert('請允許彈出視窗');return;}
    var css='@page{size:A4 landscape;margin:8mm;} body{font-family:"Microsoft JhengHei","微軟正黑體",sans-serif;color:#000;margin:0;}'
        +'.pg{page-break-after:always;padding:2mm;} .pg:last-child{page-break-after:auto;}'
        +'.pg-h{display:flex;justify-content:space-between;align-items:flex-end;border-bottom:2px solid #333;padding-bottom:3px;margin-bottom:5px;}'
        +'.pg-h .co{font-size:15px;font-weight:bold;} .pg-h .dn{font-size:12px;} .pg-h .pn{font-size:12px;font-weight:bold;}'
        +'.pg-cards{display:grid;grid-template-columns:1fr 1fr 1fr;gap:5px;}'
        +'.pc{border:1px solid #333;border-radius:3px;padding:3px 4px;break-inside:avoid;}'
        +'.pc-h{font-weight:bold;font-size:12px;margin-bottom:2px;display:flex;justify-content:space-between;}'
        +'.pc .jf{color:#c00;} .pc .jp{color:#282;}'
        +'table.pm{width:100%;border-collapse:collapse;font-size:10px;} table.pm th,table.pm td{border:1px solid #999;padding:0 2px;text-align:center;}'
        +'table.pm thead th{background:#eee;} table.pm tr.ph td{background:#f3ead6;font-weight:bold;} table.pm td.over{color:#c00;font-weight:bold;}'
        +'.pg-f{text-align:right;font-size:11px;margin-top:6px;}';
    w.document.write('<html><head><meta charset="utf-8"><title>供應商定期評核</title><style>'+css+'</style></head><body>'+body
        +'<scr'+'ipt>window.onload=function(){setTimeout(function(){window.print();},200);};</scr'+'ipt></body></html>');
    w.document.close();
}
$('#evPrint').on('click', function(){ if(EVAL_ALL) printEvalAll(); else window.print(); });
function gradeAddRow(label, min){
    $('#stGrades').append('<div class="gr-row" style="display:flex;gap:6px;align-items:center;margin-bottom:4px;">'
        +'等級 <input type="text" class="gr-label" maxlength="6" value="'+esc(label||'')+'" style="width:60px;">'
        +' 分數 ≥ <input type="number" class="gr-min" step="1" min="0" max="100" value="'+(min==null?'':min)+'" style="width:70px;">'
        +' <span class="af-del" style="color:#DD5138;cursor:pointer;" onclick="$(this).closest(\'.gr-row\').remove()"><i class="fa fa-times"></i></span></div>');
}
$('#evSet').on('click', function(){
    var s=(EVAL&&EVAL.settings)||META.eval_settings||{ng_max:5,late_max:30,special_max:100,default_days:7};
    $('#stNgMax').val(s.ng_max); $('#stLateMax').val(s.late_max); $('#stSpMax').val(s.special_max); $('#stDays').val(s.default_days);
    $('#stGrades').empty(); ((s.grades&&s.grades.length)?s.grades:[{min:90,label:'A'},{min:80,label:'B'},{min:70,label:'C'},{min:0,label:'D'}]).forEach(function(g){ gradeAddRow(g.label,g.min); });
    loadEvalAsForms('', META.eval_as_doc?META.eval_as_doc.id:0);
    openMask('evSetMask');
});
var stAsT=null;
$('#stAsKw').on('input', function(){ clearTimeout(stAsT); var k=$(this).val(); stAsT=setTimeout(function(){ loadEvalAsForms(k, +$('#stAsDoc').val()); }, 300); });
function loadEvalAsForms(kw, selId){
    $.getJSON(API, {action:'as_forms', kw:kw||''}, function(res){
        if(!res.ok) return;
        var $s=$('#stAsDoc').html('<option value="0">（不綁定，用預設「供應商定期評核表 / 2-PH-01-05」）</option>');
        (res.forms||[]).forEach(function(f){ $s.append('<option value="'+f.id+'">'+esc(f.doc_no)+' '+esc(f.doc_name)+'</option>'); });
        if(selId && $s.find('option[value="'+selId+'"]').length===0 && META.eval_as_doc)
            $s.append('<option value="'+META.eval_as_doc.id+'">'+esc(META.eval_as_doc.doc_no)+' '+esc(META.eval_as_doc.doc_name)+'（目前綁定）</option>');
        $s.val(selId||0);
    });
}
function submitEvSet(){
    var grades=[]; $('#stGrades .gr-row').each(function(){ var l=$.trim($(this).find('.gr-label').val()), mn=$(this).find('.gr-min').val();
        if(l!=='') grades.push({label:l, min:mn===''?0:+mn}); });
    $.post(API, {action:'save_eval_settings', ng_max:$('#stNgMax').val(), late_max:$('#stLateMax').val(),
        special_max:$('#stSpMax').val(), default_days:$('#stDays').val(), as_doc_id:$('#stAsDoc').val(), grades:JSON.stringify(grades)}, function(res){
        if(!res.ok){ alert(res.error||'儲存失敗'); return; }
        META.eval_settings=res.settings; META.eval_as_doc=res.eval_as_doc; closeMask('evSetMask');
        if(EVAL_ALL) $('#evAll').click(); else if($('#evVendor').val()) loadEval();
    }, 'json');
}
$('#evCsv').on('click', function(){
    if(!EVAL) return;
    var rows=[['月份','檢驗數','不良數','不良率','特採率','應交數','遲交數','遲交率']];
    for(var m=1;m<=12;m++){ var d=EVAL.months[m];
        rows.push([m+'月',d.qc_in,d.ng,rate(d.ng_rate),rate(d.special_rate),d.del_in,d.late,rate(d.late_rate)]); }
    [['上半年',EVAL.halves[1]],['下半年',EVAL.halves[2]]].forEach(function(p){ var hf=p[1];
        rows.push([p[0],hf.qc_in,hf.ng,rate(hf.ng_rate),rate(hf.special_rate),hf.del_in,hf.late,rate(hf.late_rate)+(hf.judge?(hf.judge==='pass'?' 合格':' 不合格'):'')]); });
    var csv='﻿'+rows.map(function(l){return l.map(function(v){return '"'+String(v==null?'':v).replace(/"/g,'""')+'"';}).join(',');}).join('\r\n');
    var a=document.createElement('a'); a.href=URL.createObjectURL(new Blob([csv],{type:'text/csv;charset=utf-8;'}));
    a.download='供應商定期評核_'+esc(EVAL.maker_name)+'_'+EVAL.year+'.csv'; a.click();
});

/* ---------- 稽核員資格設定 ---------- */
$('#btnAuditor').on('click', function(){ loadAuditorMgr(); openMask('audMask'); });
function loadAuditorMgr(){
    $.getJSON(API, {action:'auditors_all'}, function(res){
        if(!res.ok){ alert(res.error||'載入失敗'); return; }
        var $d=$('#audDept').html('<option value="">選部門…</option>');
        res.departments.forEach(function(d){ $d.append('<option value="'+d.id+'" data-name="'+esc(d.name)+'">'+esc(d.name)+'</option>'); });
        $('#audPeople').html('<span style="color:#b0a390;">選部門載入人員</span>');
        var h='';
        (res.auditors||[]).forEach(function(a){
            var left = (+a.has_left===1);
            h+='<tr'+(left?' style="background:#efe7d8;color:#b0a390;"':'')+'><td>'+scopeLabel(a.scope)+'</td><td>'+esc(a.dept_name||'')+'</td>'
              +'<td>'+esc(a.user_name||'')+(left?' <span style="color:#DD5138;">（已離職）</span>':'')+'</td>'
              +'<td><span class="af-del" style="color:#DD5138;cursor:pointer;" onclick="removeAuditor('+a.auditor_id+')"><i class="fa fa-trash"></i></span></td></tr>';
        });
        $('#audList').html(h||'<tr><td colspan="4" style="padding:12px;color:#8a6d45;">尚未設定</td></tr>');
    });
}
$('#audDept').on('change', function(){
    var did=$(this).val(); var $b=$('#audPeople');
    if(!did){ $b.html('<span style="color:#b0a390;">選部門載入人員</span>'); return; }
    $b.html('<span style="color:#b0a390;">載入中…</span>');
    $.getJSON(API,{action:'people',dept_id:did},function(res){
        if(!res.ok){ $b.html('載入失敗'); return; }
        var h=''; res.people.forEach(function(u){ h+='<label><input type="checkbox" class="aud-ck" value="'+u.id+'" data-name="'+esc(u.user_cname)+'"> '+esc(u.user_cname)+'</label>'; });
        $b.html(h||'<span style="color:#b0a390;">此部門無人員</span>'); $('#audAll').prop('checked',false);
    });
});
$('#audAll').on('change', function(){ $('#audPeople .aud-ck').prop('checked', this.checked); });
function audAddChecked(){
    var ids=$('#audPeople .aud-ck:checked').map(function(){return this.value;}).get();
    if(!ids.length){ alert('請勾選人員'); return; }
    $.post(API,{action:'add_auditors', user_ids:ids.join(','), scope:$('#audScope').val(),
        dept_id:$('#audDept').val(), dept_name:$('#audDept option:selected').data('name')||''}, function(res){
        if(!res.ok){ alert(res.error||'新增失敗'); return; }
        loadAuditorMgr();
    },'json').fail(function(x){ alert('新增失敗：'+(x.responseJSON&&x.responseJSON.error||x.status)); });
}
function removeAuditor(aid){
    if(!confirm('移除此稽核員資格？')) return;
    $.post(API,{action:'remove_auditor',auditor_id:aid},function(res){ if(!res.ok){alert(res.error||'失敗');return;} loadAuditorMgr(); },'json');
}

/* ---------- 欄位互動（雙擊清空／Enter 下一欄／評分格上下鍵移動） ---------- */
$(document).on('dblclick', '.va-modal input[type=text], .va-modal input[type=number], .va-modal input[type=date]', function(){ this.value=''; $(this).trigger('input'); });
$(document).on('focus', '.va-modal input[type=text], .va-modal input[type=number]', function(){ this.select && this.select(); });
$(document).on('keydown', '#recMask input, #recMask select', function(e){
    if (e.key==='Enter'){
        e.preventDefault();
        var $f=$('#recMask').find('input,select,textarea').filter(':visible:not([disabled])');
        var i=$f.index(this); if(i>=0 && i<$f.length-1){ var $n=$f.eq(i+1); $n.focus(); if($n.is('input')&&$n[0].select)$n[0].select(); }
    }
});
// 評分格：上下鍵移動到相鄰列同欄（自評↕自評、稽核↕稽核）
$(document).on('keydown', '#afBody input.af-score', function(e){
    if (e.key!=='ArrowUp' && e.key!=='ArrowDown') return;
    e.preventDefault();
    var cls = $(this).hasClass('af-self') ? 'af-self' : 'af-audit';
    var $col = $('#afBody input.'+cls);
    var i = $col.index(this);
    var j = e.key==='ArrowDown' ? i+1 : i-1;
    if (j>=0 && j<$col.length){ var el=$col.eq(j); el.focus(); if(el[0].select) el[0].select(); }
});

$('#btnRoleHelp').on('click', function(){ openMask('helpMask'); });
$('.va-mask').on('click', function(e){ if (e.target===this) this.style.display='none'; });

$(window).on('scroll', function(){ $('#vaToTop').toggle($(window).scrollTop()>200); });

if (canView) loadMeta(function(){ loadRound(); });
</script>
</body>
</html>
