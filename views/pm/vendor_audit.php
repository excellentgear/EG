<?php
/**
 * 供應商稽核管理（KPI 2-GM-04-01 第6項 廠商稽核按時執行率 的來源頁）—— 稽核批次模型
 * 每期(上/下半年)挑一批稽核對象(手動多選/隨機抽取)；大類/加工項目階層篩選(比照 master_data)。
 * 資料一律走 src/store/VendorAudit_API.php；權限 vendor_audit_lib.php
 *
 * 【待辦，2026-08-05 使用者提出，尚未實作】次稽核自動產生：首次稽核(audit_mode='first')分數達合格
 * 門檻(pass_rate)者，系統應自動產生一筆「次稽核」對象(audit_mode='again')，含人員實地審查／供應商
 * 自主評核兩份文件；由稽核員填上次稽核日期後，一樣可走既有「稽核評鑑表單」流程完成、自動簽核與列印。
 * 可參考 openRec()/completeRec() 既有的稽核評鑑表單填寫→完成→簽核路徑複用，不要另開一套。
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
if (!$perms['canView'] && $vaUser) {
    // 沒有本模組角色，但目前是「簽核設定」解析出的簽核人(含代理)時，仍放行檢閱層級——
    // 否則被指派簽核的主管收到通知點進來卻進不了頁面，違反 ai-rules/17「通知要能直接看到內容並決行」。
    $vaUid = (int)$vaUser['id'];
    try {
        $topApprover = eg_org_user($db, 'top_approver');
        if ($topApprover && (int)$topApprover['id'] === $vaUid) $perms['canView'] = true;
        if (!$perms['canView']) {
            $sg = vendor_audit_resolve_signer($db, 0);
            if ($sg && (int)$sg['id'] === $vaUid) $perms['canView'] = true;
        }
    } catch (Throwable $e) {}
}
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
        .help-doc .tip { background:#FFF7E8; border:1px dashed #F0A24B; border-radius:6px; padding:6px 10px; margin:6px 0; }
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
        .va-scope-switch { display:flex; gap:0; margin-bottom:10px; }
        .va-scope-btn { border:1px solid #DD8A38; background:#fff; color:#B5762A; cursor:pointer;
            padding:7px 20px; font-size:14px; font-weight:bold; }
        .va-scope-btn:first-child { border-radius:6px 0 0 6px; }
        .va-scope-btn:last-child { border-radius:0 6px 6px 0; border-left:none; }
        .va-scope-btn.active { background:#DD8A38; color:#fff; }
        /* 凍結窗格：標題→分頁→工具列→門檻說明 固定在頂端(僅螢幕) */
        @media screen {
            .right_col .page-title { position:sticky; top:0; z-index:32; background:#fff; }
            .va-scope-switch { position:sticky; top:34px; z-index:31; background:#fff; padding-top:4px; }
            .va-tabs { position:sticky; top:76px; z-index:31; background:#fff; }
            #tabEval .va-toolbar { position:sticky; top:112px; z-index:30; }
            #tabEval #evThresh { position:sticky; top:160px; z-index:29; background:#fff; padding:3px 0; margin:0; }
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
        table.ev-mini tr.full td { background:#F6E3C5; font-weight:bold; }
        table.ev-mini td.over { color:#DD5138; font-weight:bold; }
        td.ev-sc { background:#FBF6EC; }
        @media print {
            .va-scope-switch, .va-tabs, .va-toolbar, .va-remind { display:none !important; }
            .ev-cards { gap:4px; }
            .ev-card:nth-child(4n) { page-break-after: always; }
        }
        /* 客戶端分頁列（每頁10筆） */
        .va-pager { display:flex; justify-content:flex-end; align-items:center; gap:5px; margin:10px 2px 4px; flex-wrap:wrap; }
        .va-pager .pg-info { font-size:12px; color:#8a6d45; margin-right:auto; }
        .va-pager button { min-width:30px; height:28px; padding:0 9px; border:1px solid #D8BE93; background:#fff; color:#5b3a1e; border-radius:4px; cursor:pointer; font-size:12px; }
        .va-pager button:hover:not(:disabled) { background:#F7E0BD; }
        .va-pager button.cur { background:#F0A24B; color:#fff; border-color:#F0A24B; font-weight:bold; }
        .va-pager button:disabled { opacity:.4; cursor:default; }
        @media print { .va-pager { display:none !important; } }
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
        #afTable.va-abnormal .af-self-col { display:none; }
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
            /* 非目前分頁與任何遮罩即使被遺留在DOM中也強制不印，避免多出空白頁 */
            #tabEval, #tabRoster, #tabPlan, .va-mask, .va-totop { display:none !important; }
            body, .right_col, .container.body, .main_container { min-height:0 !important; height:auto !important; }
            #vaListPrintHead { display:block !important; text-align:center; margin-bottom:8px; }
            #vaListPrintHead .co { font-size:22px; font-weight:bold; letter-spacing:1px; }
            #vaListPrintHead .tt { font-size:16px; font-weight:bold; margin-top:3px; }
            #vaListPrintHead .sub { font-size:11px; color:#444; margin-top:2px; }
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
            <button id="btnPageHelp" class="page-help-btn" style="margin-left:auto;"><i class="fa fa-question-circle"></i> 使用說明</button>
        </div>
        <div class="clearfix"></div>

<?php if (!$perms['canView']): ?>
        <div class="va-noperm">
            <h4><i class="fa fa-lock"></i> 無供應商稽核檢閱權限</h4>
            <p>請洽管理者於「使用者權限設定」指派「稽核檢閱／登錄／管理員」角色。</p>
        </div>
<?php else: ?>
        <div class="va-scope-switch">
            <button class="va-scope-btn active" data-scope="outsource" onclick="setScope('outsource')"><i class="fa fa-industry"></i> 外包加工（生管）</button>
            <button class="va-scope-btn" data-scope="purchase" onclick="setScope('purchase')"><i class="fa fa-shopping-cart"></i> 採購</button>
        </div>
        <div class="va-tabs">
            <button class="va-tab active" data-tab="audit"><i class="fa fa-check-square-o"></i> 稽核批次</button>
            <button class="va-tab" data-tab="eval"><i class="fa fa-line-chart"></i> 定期評核（月不良/遲交率）</button>
            <button class="va-tab" data-tab="roster"><i class="fa fa-list-alt"></i> 合格供應商清冊</button>
            <button class="va-tab" data-tab="plan"><i class="fa fa-calendar"></i> 供應商稽核計劃</button>
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
            <button id="btnAttachSet" style="display:none;"><i class="fa fa-folder-open-o"></i> 附件路徑</button>
            <button id="btnAsDoc" style="display:none;"><i class="fa fa-link"></i> AS文件綁定</button>
            <button id="btnChecklist" style="display:none;"><i class="fa fa-list-ol"></i> 查核表設定</button>
            <button id="btnSignSetting" style="display:none;"><i class="fa fa-pencil-square-o"></i> 簽核設定</button>
            <button id="btnBlank"><i class="fa fa-file-o"></i> 列印空白表單</button>
            <button id="btnCsv"><i class="fa fa-file-text-o"></i> 匯出CSV</button>
            <button onclick="window.print()"><i class="fa fa-print"></i> 列印清單</button>
            <span class="va-role-badge">目前角色：<b><?= htmlspecialchars($roleLabel) ?></b><span id="scopeVerBadge" style="display:none;"></span>
                <i class="fa fa-question-circle" id="btnRoleHelp" title="角色權限說明"></i></span>
        </div>

        <div class="va-stat" id="statBar">
            <div><span class="s-num" id="stDen">—</span> <span class="s-lab">本期應稽核（對象）</span></div>
            <div><span class="s-num" id="stNum">—</span> <span class="s-lab">已完成</span></div>
            <div><span class="s-num s-rate" id="stRate">—</span> <span class="s-lab">執行率（目標 ≥70%）</span></div>
            <div class="s-lab" id="stHint" style="margin-left:auto;"></div>
        </div>
        <div class="va-remind" id="remind"></div>

        <div id="vaListPrintHead" style="display:none;"></div>
        <div class="va-table-wrap">
            <table class="va-table" id="vaTable">
                <thead><tr>
                    <th>廠商編號</th><th>廠商名稱</th><th>加工項目</th><th>預定月份</th><th>稽核狀態</th>
                    <th>稽核日</th><th>綜合合格率</th><th>判定</th><th>稽核員</th><th>操作</th>
                </tr></thead>
                <tbody id="vaBody"><tr><td colspan="10" style="padding:20px;color:#8a6d45;">載入中…</td></tr></tbody>
            </table>
        </div>
        <div class="va-remind" style="font-size:11px;color:#8a6d45;margin-top:4px;">
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
                            <th rowspan="2" title="數量(PCS)；同月取「被檢驗量」與「回廠量」較大者，品質與交期共用同一分母">進貨數<br><span style="font-weight:normal;font-size:11px;">(PCS)</span></th>
                            <th colspan="3">品質（進料檢驗）</th>
                            <th colspan="2">交期</th>
                            <th rowspan="2">判定</th>
                        </tr><tr>
                            <th>不良數</th><th>特採數</th><th>品質分<span style="font-weight:normal;">(60)</span></th>
                            <th>遲交數</th><th>交期分<span style="font-weight:normal;">(40)</span></th>
                        </tr></thead>
                        <tbody id="evBody"></tbody>
                    </table>
                </div>
            </div>
            <div id="evCards" class="ev-cards"></div>
            <div id="evPager" class="va-pager" style="display:none;"></div>
            <div id="evEmpty" style="padding:18px;color:#8a6d45;">按「全部納管廠商」列出所有納管廠商評核，或選單一廠商查詢。（自動略過整年無資料廠商）</div>
            <div class="va-remind" style="font-size:11px;color:#8a6d45;margin-top:4px;">
                資料自 ERP（bom_ing）自動計算，單位一律<b>數量 PCS</b>：<b>進貨數＝該月「被檢驗量」與「回廠量」取較大者</b>（同一批的檢驗日與回廠日常跨月，取大者讓品質與交期共用同一分母）；不良＝判定 ng、特採＝判定 AOD（顆數優先取該批異常數量，抓不到才算整批）；交期＝發包日＋約定工作天為應交日，遲交＝回廠日晚於應交（遲交量＝該批數量）。
                分數＝品質分 60×(1−(不良數＋特採數)÷進貨數) ＋ 交期分 40×(1−遲交數÷進貨數)（半年加總後計算、無條件捨去）；半年判定與總判定依等級門檻（管理員可設），總判定＝上、下半年總分平均。
            </div>
        </div><!-- /tabEval -->

        <div id="tabRoster" style="display:none;">
            <div class="va-toolbar">
                <label>評核年度</label>
                <select id="rsYear"></select>
                <button class="btn-warm" id="rsAdd" style="display:none;"><i class="fa fa-plus"></i> 加入清冊廠商</button>
                <button id="rsBatchGrade" style="display:none;"><i class="fa fa-pencil"></i> 批次設定等級</button>
                <button id="rsClearGrade" style="display:none;"><i class="fa fa-eraser"></i> 清除採用改回建議</button>
                <button id="rsStaleBtn" style="display:none;"><i class="fa fa-exclamation-triangle"></i> 檢查兩年未交易外包廠</button>
                <button id="rsCsvBtn"><i class="fa fa-file-text-o"></i> 匯出CSV</button>
                <button id="rsPrintBtn"><i class="fa fa-print"></i> 列印清冊</button>
            </div>
            <div class="va-remind" id="rsRemind"></div>
            <div class="va-table-wrap">
                <table class="va-table" id="rosterTable">
                    <thead><tr>
                        <th style="width:32px;"><input type="checkbox" id="rsAllCk"></th>
                        <th style="text-align:left;">項目</th><th>廠商</th>
                        <th>建議等級</th><th>採用等級</th>
                        <th title="未達標（等級設定中最低的一階，目前為 C；或無等級）預設「老闆指定」，可改選「客戶指定」">備註</th>
                        <th>類型</th><th>操作</th>
                    </tr></thead>
                    <tbody id="rosterBody"><tr><td colspan="8" style="padding:18px;color:#8a6d45;">載入中…</td></tr></tbody>
                </table>
            </div>
            <div id="rsPager" class="va-pager" style="display:none;"></div>
            <div class="va-remind" style="font-size:11px;color:#8a6d45;margin-top:4px;">
                清冊＝納管廠商（固定稽核）＋手動列入之合格廠商（不需稽核者，靠定期評核績效監控）。建議等級來自定期評核全年成績；採用等級可批次覆寫。
            </div>
        </div><!-- /tabRoster -->

        <div id="tabPlan" style="display:none;">
            <div class="va-toolbar">
                <label>年度</label>
                <select id="planYear"></select>
                <button id="planPrintBtn"><i class="fa fa-print"></i> 列印計劃</button>
                <button class="btn-warm" id="planSubmitBtn" style="display:none;"><i class="fa fa-send"></i> 送出計畫</button>
                <span id="planLockInfo" style="font-size:12px;color:#8a6d45;"></span>
            </div>
            <div class="va-table-wrap">
                <table class="va-table" id="planTable">
                    <thead><tr id="planHeadRow"></tr></thead>
                    <tbody id="planBody"><tr><td style="padding:18px;color:#8a6d45;">載入中…</td></tr></tbody>
                </table>
            </div>
            <div class="va-remind" style="font-size:11px;color:#8a6d45;margin-top:4px;">
                依「加入稽核對象」時設定的預定稽核月份彙總全年度計畫（不分上下半年）；送出計畫後將鎖定該年度不可再增列對象。
            </div>
        </div><!-- /tabPlan -->
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
        <div id="recStatusBox" style="display:none;margin-bottom:8px;"></div>
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
        <div style="margin:8px 0;">
            <label>審查類別（必選一項）</label>
            <label style="display:inline-block;margin:0 14px 0 0;font-weight:normal;"><input type="radio" name="recReviewType" value="site"> 人員實地審查</label>
            <label style="display:inline-block;margin:0 14px 0 0;font-weight:normal;"><input type="radio" name="recReviewType" value="self"> 供應商自主評核</label>
            <label style="display:inline-block;font-weight:normal;"><input type="radio" name="recReviewType" value="abnormal"> 異常檢核（僅需稽核分）</label>
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
            每項依單項滿分評自評/稽核分（0＝最差）；綜合合格率＝自評率×自評權重＋稽核率×稽核權重，達門檻才判合格。
        </div>
        <div class="af-quickfill" style="display:flex;gap:14px;align-items:center;flex-wrap:wrap;margin-bottom:6px;font-size:12px;color:#5b3a1e;">
            <span>自評快速套用：<input type="number" id="qfSelf" min="0" style="width:56px;">
                <button type="button" class="b-att2" onclick="quickFillScore('self')">套用全部</button>
                <button type="button" class="b-att2" style="color:#DD5138;" onclick="quickClearScore('self')">清空</button></span>
            <span>稽核快速套用：<input type="number" id="qfAudit" min="0" style="width:56px;">
                <button type="button" class="b-att2" onclick="quickFillScore('audit')">套用全部</button>
                <button type="button" class="b-att2" style="color:#DD5138;" onclick="quickClearScore('audit')">清空</button></span>
            <span style="color:#b0a390;">(套用後仍可個別修改)</span>
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
        <button class="b-ok" id="btnRecSave" onclick="submitRec()">儲存</button>
        <button class="b-ok" id="btnRecComplete" style="background:#8A5A2B;" onclick="completeRec()">完成</button>
        <button class="b-ok" id="btnRecSubmitSign" style="background:#DD8A38;display:none;" onclick="submitSign()">送審核</button>
    </div>
</div></div>

<!-- 稽核簽核決行 modal（由通知連結 ?sign=target_id 開啟，或由列表待簽核列點開） -->
<div class="va-mask" id="signMask"><div class="va-modal wide">
    <div class="m-head"><span id="signTitle">供應商稽核簽核</span><span class="m-close" onclick="closeMask('signMask')">✕</span></div>
    <div class="m-body">
        <div id="signInfo" style="font-size:13px;color:#5b3a1e;margin-bottom:8px;"></div>
        <table class="af-table" style="width:100%;"><thead><tr><th>評鑑項目</th><th>單項滿分</th><th>自評合格率</th><th>稽核合格率</th></tr></thead><tbody id="signCatBody"></tbody></table>
        <div id="signConc" class="af-summary" style="margin-top:8px;"></div>
        <div style="margin-top:10px;"><label>意見／退回原因（核准可留空，退回必填）</label>
            <textarea id="signNote" rows="2" style="width:100%;"></textarea></div>
    </div>
    <div class="m-foot">
        <button class="b-cancel" onclick="closeMask('signMask')">關閉</button>
        <button class="b-cancel" style="background:#DD5138;color:#fff;" onclick="signDecideAction('rejected')">退回</button>
        <button class="b-ok" onclick="signDecideAction('approved')">核准</button>
    </div>
</div></div>

<!-- 兩年未交易外包廠 modal -->
<div class="va-mask" id="staleMask"><div class="va-modal wide">
    <div class="m-head"><span>兩年未交易外包廠（建議移除）</span><span class="m-close" onclick="closeMask('staleMask')">✕</span></div>
    <div class="m-body">
        <div id="staleHint" style="font-size:12px;color:#8a6d45;margin-bottom:6px;"></div>
        <div class="va-table-wrap"><table class="va-table"><thead><tr>
            <th style="width:32px;"><input type="checkbox" id="staleAll"></th><th>加工項目</th><th>廠商ID</th><th>廠商名稱</th><th>最後發包日</th><th>類型</th>
        </tr></thead><tbody id="staleBody"></tbody></table></div>
        <div style="font-size:11px;color:#DD5138;margin-top:6px;">確認移除：取消納管＋移出合格清冊，並自稽核批次刪除尚未稽核之對象（已稽核紀錄保留）。</div>
    </div>
    <div class="m-foot">
        <button class="b-cancel" onclick="closeMask('staleMask')">取消</button>
        <button class="b-ok" onclick="staleRemove()"><i class="fa fa-trash"></i> 確認移除勾選</button>
    </div>
</div></div>

<!-- 加入合格清冊 modal -->
<div class="va-mask" id="rsAddMask"><div class="va-modal wide">
    <div class="m-head"><span>加入合格清冊廠商（手動列入不需納管者）</span><span class="m-close" onclick="closeMask('rsAddMask')">✕</span></div>
    <div class="m-body">
        <div style="display:flex;gap:6px;align-items:center;margin-bottom:6px;">
            <input type="text" id="rsAddKw" placeholder="搜尋廠商名/編號" style="width:180px;height:28px;border:1px solid #D8BE93;border-radius:4px;padding:0 6px;">
            <button class="b-att2" onclick="rsAddSearch()">查詢</button>
            <label style="margin:0;font-size:12px;"><input type="checkbox" id="rsAddAll"> 全選</label>
            <button class="b-att2" onclick="rsAddSelected()"><i class="fa fa-check"></i> 加入</button>
            <span id="rsAddCnt" style="font-size:12px;color:#8a6d45;"></span>
        </div>
        <div class="pk-grid" id="rsAddBody"><div class="empty">輸入關鍵字查詢（已在清冊/停用者不列出）</div></div>
    </div>
    <div class="m-foot"><button class="b-cancel" onclick="closeMask('rsAddMask')">關閉</button></div>
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

<!-- 附件預覽 modal：圖片/PDF 點擊直接預覽，不觸發瀏覽器下載 -->
<div class="va-mask" id="vaPrevMask"><div class="va-modal xwide">
    <div class="m-head"><span id="vaPrevTitle">附件預覽</span><span class="m-close" onclick="vaClosePreview()">✕</span></div>
    <div class="m-body" style="text-align:center;max-height:78vh;overflow:auto;background:#525659;">
        <img id="vaPrevImg" style="max-width:100%;display:none;">
        <iframe id="vaPrevFrame" style="width:100%;height:75vh;border:none;display:none;background:#fff;"></iframe>
    </div>
</div></div>

<!-- 週期設定 modal -->
<div class="va-mask" id="cycMask"><div class="va-modal">
    <div class="m-head"><span>共用稽核週期設定</span><span class="m-close" onclick="closeMask('cycMask')">✕</span></div>
    <div class="m-body">
        <label>稽核週期（月）—— 全公司共用，作為「多久辦一期」的參考與提醒</label>
        <input type="number" id="cycVal" step="1" min="1" style="width:120px;">
        <div style="font-size:12px;color:#8a6d45;margin-top:6px;">例：6＝每半年一期。此值僅供提醒，不會自動改變各期對象。</div>
        <label style="margin-top:10px;">「列印清單」標題（本期稽核批次清單列印時顯示的文件名稱）</label>
        <input type="text" id="cycListTitle" maxlength="60" style="width:100%;" placeholder="供應商稽核計畫實施結果">
    </div>
    <div class="m-foot">
        <button class="b-cancel" onclick="closeMask('cycMask')">取消</button>
        <button class="b-ok" onclick="submitCycle()">儲存</button>
    </div>
</div></div>

<!-- 附件路徑設定 modal -->
<div class="va-mask" id="attMask"><div class="va-modal">
    <div class="m-head"><span>佐證附件儲存路徑設定</span><span class="m-close" onclick="closeMask('attMask')">✕</span></div>
    <div class="m-body">
        <label>佐證附件儲存路徑（base）—— 供應商自評等附件的實體存放資料夾</label>
        <input type="text" id="cycAttachBase" maxlength="255" placeholder="留空＝預設 uploads/vendor_audit_attach；可填 NAS 路徑如 \\NAS\品保\供應商稽核附件">
        <div style="font-size:12px;color:#8a6d45;margin-top:6px;">DB 只存檔名，完整路徑於讀取當下用此設定＋年度即時組出；換 NAS 只需改這裡（既有檔案需一併搬移）。</div>
    </div>
    <div class="m-foot">
        <button class="b-cancel" onclick="closeMask('attMask')">取消</button>
        <button class="b-ok" onclick="submitAttachBase()">儲存</button>
    </div>
</div></div>

<!-- AS 文件綁定設定 modal（四份文件的名稱/編號連動 AS 文件管理） -->
<div class="va-mask" id="asDocMask"><div class="va-modal">
    <div class="m-head"><span>AS 文件綁定設定</span><span class="m-close" onclick="closeMask('asDocMask')">✕</span></div>
    <div class="m-body">
        <div style="font-size:12px;color:#8a6d45;margin-bottom:8px;">各文件列印的名稱與編號跟 AS 文件管理連動；AS 改名/改編號，列印自動跟著變。</div>
        <label>稽核查檢表（2-PH-01-02）</label>
        <div style="display:flex;gap:6px;align-items:center;"><input type="text" id="cycAsKw" placeholder="搜尋" style="width:120px;">
            <select id="cycAsDoc" style="flex:1;min-width:180px;"><option value="0">（用預設 供應商評鑑稽核查表 / 2-PH-01-02）</option></select></div>
        <label style="margin-top:8px;">品質系統評鑑記錄表（2-PH-01-03）</label>
        <div style="display:flex;gap:6px;align-items:center;"><input type="text" id="cycRecKw" placeholder="搜尋" style="width:120px;">
            <select id="cycRecDoc" style="flex:1;min-width:180px;"><option value="0">（用預設 品質系統評鑑記錄表 / 2-PH-01-03）</option></select></div>
        <label style="margin-top:8px;">合格供應商清冊（2-PH-01-04）</label>
        <div style="display:flex;gap:6px;align-items:center;"><input type="text" id="cycRosKw" placeholder="搜尋" style="width:120px;">
            <select id="cycRosDoc" style="flex:1;min-width:180px;"><option value="0">（用預設 合格供應商清冊 / 2-PH-01-04）</option></select></div>
        <label style="margin-top:8px;">供應商定期評核表（2-PH-01-05）</label>
        <div style="display:flex;gap:6px;align-items:center;"><input type="text" id="cycEvKw" placeholder="搜尋" style="width:120px;">
            <select id="cycEvDoc" style="flex:1;min-width:180px;"><option value="0">（用預設 供應商定期評核表 / 2-PH-01-05）</option></select></div>
        <label style="margin-top:8px;">供應商稽核計劃（2-PH-01-06）</label>
        <div style="display:flex;gap:6px;align-items:center;"><input type="text" id="cycPlanKw" placeholder="搜尋" style="width:120px;">
            <select id="cycPlanDoc" style="flex:1;min-width:180px;"><option value="0">（用預設 供應商稽核計劃 / 2-PH-01-06）</option></select></div>
    </div>
    <div class="m-foot">
        <button class="b-cancel" onclick="closeMask('asDocMask')">取消</button>
        <button class="b-ok" onclick="submitAsDoc()">儲存</button>
    </div>
</div></div>

<!-- 查核表設定 modal（類別/項次/單項滿分 可設定化） -->
<div class="va-mask" id="checklistMask"><div class="va-modal xwide">
    <div class="m-head"><span>查核表設定</span><span class="m-close" onclick="closeMask('checklistMask')">✕</span></div>
    <div class="m-body">
        <div style="font-size:12px;color:#8a6d45;margin-bottom:8px;">
            可調整每個類別/項次的文字與單項滿分；總分滿分由系統依所有項次滿分加總自動計算。<br>
            <b>已完成評分的稽核紀錄會凍結當時的查核表內容，之後在此調整不會影響舊紀錄。</b>
        </div>
        <div id="clCatsBox"></div>
        <button type="button" class="b-att2" onclick="clAddCat()"><i class="fa fa-plus"></i> 新增類別</button>
        <div style="margin-top:14px;display:flex;gap:16px;align-items:center;flex-wrap:wrap;">
            <label>自評權重 <input type="number" id="clSelfW" step="0.05" min="0" max="1" style="width:70px;"></label>
            <label>稽核權重 <input type="number" id="clAuditW" step="0.05" min="0" max="1" style="width:70px;"></label>
            <label>合格率門檻% <input type="number" id="clPassRate" step="0.5" min="0" max="100" style="width:70px;"></label>
            <span>總分滿分：<b id="clTotalMax">0</b>（系統自動計算）</span>
        </div>
    </div>
    <div class="m-foot">
        <button class="b-cancel" onclick="closeMask('checklistMask')">取消</button>
        <button class="b-ok" onclick="submitChecklist()">儲存</button>
    </div>
</div></div>

<!-- 簽核設定 modal（稽核評鑑表單自動簽核/簽核部門 + 稽核計劃是否需核准） -->
<div class="va-mask" id="signSetMask"><div class="va-modal">
    <div class="m-head"><span>簽核設定</span><span class="m-close" onclick="closeMask('signSetMask')">✕</span></div>
    <div class="m-body">
        <div style="font-weight:bold;color:#5b3a1e;margin-bottom:4px;">稽核評鑑表單「完成」後的簽核</div>
        <label><input type="checkbox" id="ssAuto"> 自動簽核（系統依下方部門自動解析主管蓋章，不需人工核准）</label>
        <div style="margin-top:6px;"><label>簽核部門（依組織角色綁定「生管部門」往上取，只能選生管組本身或上層部門）</label>
            <select id="ssDept" style="width:100%;"><option value="">（尚未設定）</option></select>
        </div>
        <div style="font-size:11px;color:#8a6d45;margin-top:4px;">不論是否自動簽核，「誰簽」都是解析這裡設定的部門主管（含代理/迴避）；未勾自動簽核時，完成後需按「送審核」通知該主管簽核。</div>
        <hr style="margin:14px 0;border-color:#EADFC8;">
        <div style="font-weight:bold;color:#5b3a1e;margin-bottom:4px;">供應商稽核計劃送出後是否需要核准</div>
        <label><input type="checkbox" id="ssPlanNeed"> 需要核准（不勾＝送出即生效）</label>
        <div style="font-size:11px;color:#8a6d45;margin-top:4px;">目前符合資格的核准人：<b id="ssTopApprover">—</b>（任一人核准即生效；實際解析依下方「核准來源優先序」，第一順位「部門或人員綁定」的內容要改請到「組織角色綁定設定」第三節）</div>
        <hr style="margin:14px 0;border-color:#EADFC8;">
        <div style="font-weight:bold;color:#5b3a1e;margin-bottom:4px;">供應商稽核計劃「核准來源」優先序</div>
        <div style="font-size:11px;color:#8a6d45;margin-bottom:6px;">依序嘗試，第一個解析得出人選就採用（解析到的人若剛好是送出計劃的本人，視同該順位無結果，自動改試下一順位，避免球員兼裁判）。「部門或人員綁定」要改設定內容請到「組織角色綁定設定」第三節。</div>
        <div id="chainBox" style="display:flex;gap:8px;flex-wrap:wrap;"></div>
    </div>
    <div class="m-foot">
        <button class="b-cancel" onclick="closeMask('signSetMask')">取消</button>
        <button class="b-ok" onclick="submitSignSetting()">儲存</button>
    </div>
</div></div>

<!-- 供應商稽核計劃 核准/退回 modal -->
<div class="va-mask" id="planDecideMask"><div class="va-modal">
    <div class="m-head"><span>核准 / 退回年度稽核計劃</span><span class="m-close" onclick="closeMask('planDecideMask')">✕</span></div>
    <div class="m-body">
        <div style="margin-bottom:8px;">
            <label style="display:inline-block;margin-right:16px;font-weight:normal;"><input type="radio" name="pdDecision" value="approved" checked> 核准</label>
            <label style="display:inline-block;font-weight:normal;"><input type="radio" name="pdDecision" value="rejected"> 退回</label>
        </div>
        <div><label>核准日期（預設今天，可改成實際核准當天的日期）</label><input type="date" id="pdDate"></div>
        <div id="pdNoteBox" style="display:none;margin-top:8px;"><label>退回原因（必填）</label><textarea id="pdNote" rows="3" style="width:100%;" maxlength="300"></textarea></div>
    </div>
    <div class="m-foot">
        <button class="b-cancel" onclick="closeMask('planDecideMask')">取消</button>
        <button class="b-ok" onclick="submitPlanDecide()">送出</button>
    </div>
</div></div>

<!-- 操作確認密碼 modal（密碼一律用 password 欄位遮罩顯示，不用 window.prompt 明碼；比照 as_document_management.php 的 askSuperPwd，
     驗證改走全站共用的 src/common/confirm_password_lib.php，超級管理員或被授權的管理員都可以輸入自己的操作確認密碼） -->
<div class="va-mask" id="pwMask"><div class="va-modal">
    <div class="m-head"><span>操作確認密碼</span><span class="m-close" onclick="closeMask('pwMask')">✕</span></div>
    <div class="m-body">
        <p id="pwMsg" style="white-space:pre-line;color:#5b3a1e;margin:0 0 8px;"></p>
        <input type="password" id="pwInput" autocomplete="new-password" placeholder="請輸入操作確認密碼"
            style="width:100%;border:1px solid #D8BE93;border-radius:4px;padding:6px 8px;font-size:13px;box-sizing:border-box;">
        <div id="pwErr" style="color:#DD5138;margin-top:6px;font-size:12px;"></div>
    </div>
    <div class="m-foot">
        <button class="b-cancel" onclick="closeMask('pwMask')">取消</button>
        <button class="b-ok" id="pwOk">確定</button>
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
        <div style="font-size:12px;color:#8a6d45;margin:8px 0 12px;"><b>合格與否只看下面的評核等級</b>（勾「視為不合格」的等級才算不合格）；這三個率上限只做<b>超標標紅提醒</b>，不會把整個半年判成不合格（特採率設 100＝連提醒都不做）。約定工作天沿用 KPI#7 準交口徑。</div>
        <label>特定廠商的約定工作天（未列出的廠商用上面的預設）</label>
        <div style="display:flex;gap:6px;align-items:center;margin-bottom:5px;">
            <input type="text" id="stLeadKw" placeholder="廠商下拉找不到？打名稱或編號查詢後再選…" style="flex:1;">
            <button type="button" class="b-att2" id="stLeadKwGo"><i class="fa fa-search"></i> 查詢廠商</button>
        </div>
        <div id="stLeadRows" style="border:1px solid #EADFC8;border-radius:6px;padding:6px 8px;max-height:200px;overflow:auto;"></div>
        <div style="margin:4px 0 12px;"><button type="button" class="b-att2" onclick="leadAddRow('','')"><i class="fa fa-plus"></i> 新增廠商</button>
            <span style="font-size:11px;color:#8a6d45;">熱處理／表面處理等交期本來就較長的廠商可個別設定（例 14 天）；<b>刪除該列＝恢復用預設</b>。設定後遲交判定與交期分數會依該廠商的天數重算。</span></div>
        <label>評核等級門檻（分數 ≥ 該值即為該等級，由高到低）</label>
        <div id="stGrades" style="border:1px solid #EADFC8;border-radius:6px;padding:6px 8px;"></div>
        <div style="margin:4px 0 4px;"><button type="button" class="b-att2" onclick="gradeAddRow('',0,0)"><i class="fa fa-plus"></i> 新增等級</button>
            <span style="font-size:11px;color:#8a6d45;">總分0~100；例：A≥95、B≥85、C≥0，並把 C 勾「視為不合格」＝落到 C 就是不合格。可勾多級。</span></div>
        <div style="font-size:11px;color:#8a6d45;">AS 文件綁定（含定期評核表）已移至「稽核批次」工具列的「AS文件綁定」設定。</div>
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

<!-- 頁面使用說明 modal -->
<div class="va-mask" id="helpUseMask"><div class="va-modal xwide">
    <div class="m-head"><span><i class="fa fa-question-circle"></i> 供應商稽核管理 使用說明</span><span class="m-close" onclick="closeMask('helpUseMask')">✕</span></div>
    <div class="m-body help-doc">
        <p>本頁為 KPI「2-GM-04-01 #6 廠商稽核按時執行率」的來源頁，並整合供應商評鑑相關 AS 表單。分三個分頁：<b>稽核批次</b>、<b>定期評核</b>、<b>合格供應商清冊</b>。</p>

        <h4>○、範疇切換（外包加工／採購）</h4>
        <ul>
            <li>頁面最上方有「外包加工（生管）／採購」切換鈕：<b>生管管的外包加工廠</b>與<b>採購管的一般供應商</b>共用同一支頁面，但<b>稽核批次對象、合格供應商清冊、查核表題庫、年度稽核計劃各自獨立</b>，切換後看到的是該範疇自己的資料，兩邊互不干涉、互不影響。</li>
            <li>供應商屬於哪個範疇由 master_data 廠商主檔的<b>加工大類</b>自動判定（大類=外包加工廠者歸生管，其餘歸採購），不必手動指定。</li>
            <li>查核表設定、年度計畫送簽核准，都是「目前切換到哪個範疇，就是在編輯/送出哪個範疇的資料」，管理員留意畫面上的範疇標示再操作。</li>
        </ul>

        <h4>一、稽核批次（實地稽核，半年一期）</h4>
        <ul>
            <li><b>模型</b>：每期（上半年 1–6 月／下半年 7–12 月）挑一批廠商稽核。KPI 執行率＝已完成 ÷ 本期對象數。</li>
            <li><b>加入稽核對象</b>：依大類／加工項目篩選後多選加入，或隨機抽 N 家（自納管廠商）；可指定「預定稽核月份」。</li>
            <li><b>登錄</b>：填「供應商評鑑稽核表」簡版 15 項，每項自評分＋稽核分各 0~7；系統自動算各類與綜合合格率（自評×0.3＋稽核×0.7），<b>≥75% 判合格</b>。</li>
            <li><b>記錄表</b>（已稽核者）：由 15 項換算 5 大類合格率，含<b>雷達圖</b>；可「列印記錄表」或「一次印全部文件」（查核表＋記錄表為不同文件，各自跳出一個列印視窗；記錄表若有上傳佐證附件會一併接續印出，圖片與 PDF 可直接預覽，其他類型僅顯示檔名）；可上傳供應商簽名回傳掃描檔。</li>
            <li><b>停用廠商</b>（master_data 客戶/廠商設為停用者）：灰底、不可加入、不列入 KPI。</li>
        </ul>

        <h4>二、定期評核（月不良／遲交率，ERP 自動算）</h4>
        <ul>
            <li><b>資料來源</b>：自 ERP（bom_ing）自動計算，<b>單位一律數量（PCS）</b>，不是批數——品質依<b>檢驗日</b>歸月（不良＝ng 驗退、特採＝AOD，顆數優先取該批 QC 記錄的異常數量，抓不到才算整批發包數）；交期依<b>回廠日</b>歸月（遲交＝回廠日晚於「發包日＋約定工作天」，遲交量＝該批數量）。</li>
            <li><b>進貨數（共用分母）</b>：同一個月<b>取「被檢驗量」與「回廠量」較大者</b>，品質與交期共用同一個進貨數。原因：同一批的檢驗日與回廠日常常跨月（例如 3 月回廠、4 月才驗），兩邊各自當分母會出現「一邊 0 一邊有量」對不起來的情形。滑鼠移到進貨數上會顯示原始的被檢驗量／回廠量。</li>
            <li><b>畫面欄位是「數量」不是「率」</b>：不良數／特採數／遲交數直接顯示 PCS；<b>滑鼠移到數字上會顯示它佔進貨數的百分比</b>，超過門檻的數字一樣標紅。匯出 CSV 兩者都有。</li>
            <li><b>分數／等級</b>：以半年<b>加總</b>後計算——<b>品質分＝60×(1−(不良數＋特採數)÷進貨數)</b>、<b>交期分＝40×(1−遲交數÷進貨數)</b>，皆<b>無條件捨去</b>（比照紙本 Excel）；半年總分＝品質分＋交期分（0～100），依等級門檻判 A/B/C/D。<b>總判定＝上半年與下半年總分的平均</b>（只有一個半年有資料就用該半年）。該分項整段期間沒有資料（分母 0）視同無缺失給滿分。</li>
            <li><b>合格／不合格由等級決定</b>：在「門檻設定」的評核等級區塊逐級勾選<b>「視為不合格」</b>（例：A≥95、B≥85、C≥0，把 C 勾起來＝落到 C 就是不合格；可勾多級）。<b>不良率／遲交率／特採率上限只做超標標紅提醒</b>，不再把整個半年判成不合格（所以不會再出現「A 級卻標不合格」）。舊資料沒設過時，預設把最低一階當不合格。</li>
            <li><b>卡片標題的合格徽章＝總判定</b>（上下半年平均分的等級），與表格最後一列「全年（總判定）」永遠一致；「只看不合格」篩選也是看總判定。各半年自己的合格與否看該半年那一列。</li>
            <li><b>全部納管廠商</b>：一次列出所有納管廠商，2 欄卡片，<b>每頁 10 家</b>、下方可翻頁（只畫當頁避免一次載入過慢）；可「只看不合格」；橫式列印一頁 6 間（列印為全部廠商，不受翻頁影響）。</li>
            <li><b>單一廠商</b>：查一家的 12 個月明細，上方顯示上／下半年／全年分數與等級。</li>
        </ul>

        <h4>三、合格供應商清冊</h4>
        <ul>
            <li><b>組成</b>：清冊＝<b>納管廠商</b>（固定要稽核）∪ <b>手動列入</b>（不需納管但認定合格者，靠定期評核績效監控）。清單<b>每頁 10 家</b>、下方可翻頁；匯出 CSV／列印清冊皆為全部廠商，不受翻頁影響。</li>
            <li><b>評核等級</b>：建議等級來自定期評核全年成績；可勾選<b>批次設定採用等級</b>覆寫建議，或清除改回建議。</li>
            <li><b>備註（老闆指定／客戶指定）</b>：等級<b>未達標</b>（＝等級設定中<b>最低的那一階</b>，目前設 A/B/C 三級時就是 <b>C</b>；若改成 A/B/C/D 四級就是 D；含當年度無資料而<b>無等級</b>者）一律<b>預設顯示「老闆指定」</b>，說明它為何仍留在合格清冊；可自行改選「客戶指定」或清成（無）。達標者預設空白。改選即存，CSV 與列印清冊都會帶出這欄（原本的「廠商備註」欄已取消）。</li>
            <li><b>檢查兩年未交易外包廠</b>：列出納管/在冊、有發包史但最後發包超過兩年的外包廠（顯示最後發包日）；<b>需你勾選確認</b>後才移除（取消納管＋移出清冊＋刪未稽核對象），不會自動靜默移除。</li>
        </ul>

        <div class="tip">
            <b>重要行為（常見疑問）</b>：<br>
            ● 納管廠商<b>某年度完全沒有交易</b> → <b>不會</b>出現在「定期評核（全部納管）」（自動略過整年無資料者）。<br>
            ● 但它<b>仍會</b>出現在<b>合格清冊</b>（納管＝固定要稽核的合格供應商，即使當年無交易仍列冊），其「建議等級」顯示「<b>—</b>」（無資料可算）；可手動設「採用等級」，或靠實地稽核判定。
        </div>

        <h4>四、供應商稽核計劃（年度，2-PH-01-06）</h4>
        <ul>
            <li>「供應商稽核計劃」分頁：勾選各廠商預定稽核月份後<b>送出</b>即鎖定當年度（不可再增列對象），除非退回或管理員取消。</li>
            <li><b>是否需要核准</b>：管理員於「簽核設定」勾選；不勾＝送出即生效。</li>
            <li><b>核准來源優先序</b>（同樣在「簽核設定」設定）：依序嘗試「部門或人員綁定」／「自動：送出者的上一階主管」／「最高決策者」，取第一個解析得出人選的順位（該順位任一人核准即生效）；若解析到的人剛好是送出計劃的本人，視同該順位無結果，自動改試下一順位，避免球員兼裁判。</li>
            <li><b>核准／退回</b>：核准人按「核准/退回」跳窗操作，<b>核准日期可自行輸入</b>（預設今天）；退回必須填寫原因，送出人可修改後重新送出。</li>
        </ul>

        <h4>五、設定（管理員，於稽核批次工具列）</h4>
        <ul>
            <li><b>稽核員設定</b>：按管理範圍（外包加工／採購／通用）指定部門與稽核員；離職者（在職狀態）自動不列入下拉。</li>
            <li><b>週期設定</b>：共用稽核週期（月），僅供「多久辦一期」提醒。</li>
            <li><b>附件路徑</b>：佐證附件實體存放資料夾（可填 NAS），DB 只存檔名、路徑即時組。</li>
            <li><b>AS文件綁定</b>：四份文件（2-PH-01-02 查檢表／03 記錄表／04 清冊／05 定期評核表）的列印名稱與編號跟 AS 文件管理連動，AS 改名/改號自動跟著變。</li>
            <li><b>門檻設定</b>（定期評核）：不良率／遲交率／特採率上限、約定工作天、評核等級門檻，以及<b>特定廠商的約定工作天</b>（廠商層級覆寫：熱處理等交期本來就較長的廠商可個別設天數，未設定者用預設；刪除該列即恢復預設。單一廠商查詢時上方會標示「本廠商專屬設定」）。</li>
        </ul>

        <h4>六、權限角色</h4>
        <ul>
            <li><b>稽核檢閱</b>：檢視/歷史/統計/匯出；<b>稽核登錄</b>：＋加入/移除對象、登錄稽核、上傳附件、清冊維護；<b>稽核管理員</b>：＋稽核員/週期/附件/AS綁定/門檻設定、兩年未交易移除；<b>管理者</b>固定全權。</li>
        </ul>
        <div style="font-size:11px;color:#8a6d45;margin-top:8px;">列印文件標頭一律取「本公司」（master_data 客戶分頁設為本公司之客戶全名/發票用）。</div>
    </div>
    <div class="m-foot"><button class="b-ok" onclick="closeMask('helpUseMask')">我知道了</button></div>
</div></div>

<script src="../../resource/js/jquery.min.js"></script>
<script src="../../resource/js/bootstrap.min.js"></script>
<script src="../../resource/js/nprogress.js"></script>
<script src="../../resource/js/custom.min.js"></script>
<script src="../../resource/js/eg_stamp.js?v=<?= @filemtime(__DIR__.'/../../resource/js/eg_stamp.js') ?>"></script>
<script src="../../resource/js/eg_date_fmt.js?v=<?= @filemtime(__DIR__.'/../../resource/js/eg_date_fmt.js') ?>"></script>
<script src="../../code/highcharts.js"></script>
<script src="../../code/highcharts-more.js"></script>
<script>
$(document).ready(function(){
    var $am = $('#sidebar-menu .nav.side-menu > li.active');
    if ($am.length) { $am.removeClass('active').find('ul.child_menu').hide(); $am.find('li.current-page').removeClass('current-page'); }
    $('#sidebar-menu').css('visibility','visible');
});

var API = '../../src/store/VendorAudit_API.php';
var META = null, TARGETS = [], PERMS = null, POOL = [], ROUND_YEAR = null, CUR_CFG = null, CUR_PROD_TYPE = null, CUR_REC = null;
/** 目前操作範疇(外包加工/採購)：生管/採購同頁面共用，資料各自獨立(2026-08-17)。
 *  一律用 ajaxPrefilter 幫本頁所有 API 請求自動帶上 scope，不必逐一改每個 $.getJSON/$.post 呼叫點。 */
var CUR_SCOPE = localStorage.getItem('va_cur_scope') === 'purchase' ? 'purchase' : 'outsource';
$.ajaxPrefilter(function(options){
    if (options.url !== API) return;
    if (options.data instanceof FormData) { options.data.append('scope', CUR_SCOPE); return; }
    if (options.data == null) { options.data = {scope: CUR_SCOPE}; return; }
    if (typeof options.data === 'string') {
        if (!/(^|&)scope=/.test(options.data)) options.data += (options.data ? '&' : '') + 'scope=' + encodeURIComponent(CUR_SCOPE);
    } else if (options.data.scope === undefined) {
        options.data.scope = CUR_SCOPE;
    }
});
function setScope(s){
    if (s !== 'outsource' && s !== 'purchase') return;
    var vis = (META && META.visible_scopes) || ['outsource','purchase'];
    if (vis.indexOf(s) === -1) return;
    CUR_SCOPE = s;
    localStorage.setItem('va_cur_scope', s);
    $('.va-scope-btn').removeClass('active').filter('[data-scope="'+s+'"]').addClass('active');
    loadMeta(function(){ reloadCurrentTab(); });
}
function reloadCurrentTab(){
    var t = $('.va-tab.active').data('tab');
    if (t === 'audit') loadRound();
    else if (t === 'eval') { loadEvVendors($('#evKw').val()||''); $('#evSingle,#evCards,#evPager').hide(); $('#evEmpty').hide(); }
    else if (t === 'roster') loadRoster();
    else if (t === 'plan') loadPlan();
}
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
var CUR_USER_NAME = <?= json_encode($vaUser['user_cname'] ?? '', JSON_UNESCAPED_UNICODE) ?>;
var RESULT_LABEL = {pass:'合格', conditional:'限期改善', fail:'不合格'};

function closeMask(id){ document.getElementById(id).style.display='none'; }
function openMask(id){ document.getElementById(id).style.display='block'; }
function esc(s){ return String(s==null?'':s).replace(/[&<>"']/g,function(c){
    return {'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#39;'}[c]; }); }
function fmtDate(d){ return d ? egFmtDate(d) : ''; }

function loadMeta(cb){
    $.getJSON(API, {action:'meta'}, function(m){
        if (!m.ok){ alert(m.error||'載入失敗'); return; }
        // 範疇可視性(2026-08-17使用者明確要求)：只有管理員/該範疇登記的稽核員才看得到該切換鈕，
        // 避免純採購資格的人連「外包加工(生管)」分頁都看得到、誤以為自己能操作。
        // 目前 CUR_SCOPE 若不在可視清單內(例：換人共用瀏覽器留下舊 localStorage)，強制修正成第一個
        // 可視範疇並整批重打一次，確保後續 canEdit/checklist 等 scope 相依欄位是對的範疇。
        var vis = m.visible_scopes || ['outsource','purchase'];
        $('.va-scope-btn').each(function(){ $(this).toggle(vis.indexOf($(this).data('scope')) !== -1); });
        $('.va-scope-switch').toggle(vis.length > 1);
        // 只看得到單一範疇的人(左上角沒有切換鈕可看)，在角色徽章旁補顯示目前是哪個版本；
        // 兩邊都看得到的人不必重複顯示，切換鈕本身就是最清楚的指示（使用者2026-08-17明確要求）。
        if (vis.length === 1) $('#scopeVerBadge').text('（'+scopeLabel(vis[0])+'）').show();
        else $('#scopeVerBadge').hide();
        if (vis.indexOf(CUR_SCOPE) === -1) {
            CUR_SCOPE = vis[0] || 'outsource';
            localStorage.setItem('va_cur_scope', CUR_SCOPE);
            $('.va-scope-btn').removeClass('active').filter('[data-scope="'+CUR_SCOPE+'"]').addClass('active');
            loadMeta(cb);
            return;
        }
        META = m; PERMS = m.perms;
        window.__ownCompany = m.company_name;
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
        $('#btnPick').toggle(!!m.perms.canEdit);
        // 週期設定/附件路徑/AS文件綁定是共用infra，不隨scope收斂(使用者2026-08-17明確選擇暫時兩邊共用)，
        // 仍用原本的 canAdmin；查核表設定/簽核設定/稽核員設定/納管切換/定期評核門檻/兩年未交易檢查
        // 全部依範疇收斂，改用 canAdminScope，避免顯示了按鈕但點下去被後端擋。用 toggle 而非 show，
        // 因為現在切換範疇後這些權限可能從有變沒有，需要能連帶隱藏回去。
        $('#btnCycle,#btnAttachSet,#btnAsDoc').toggle(!!m.perms.canAdmin);
        $('#btnAuditor,#btnChecklist,#btnSignSetting,#pkManageGrp,#evSet').toggle(!!m.perms.canAdminScope);
        var $ey = $('#evYear').empty(), $ry = $('#rsYear').empty(), $py = $('#planYear').empty();
        for (var yy=m.cur_year; yy>=m.cur_year-5; yy--){ $ey.append('<option value="'+yy+'">'+yy+'</option>'); $ry.append('<option value="'+yy+'">'+yy+'</option>'); $py.append('<option value="'+yy+'">'+yy+'</option>'); }
        $ey.val(m.cur_year); $ry.val(m.cur_year); $py.val(m.cur_year);
        $('#rsAdd,#rsBatchGrade,#rsClearGrade').toggle(!!m.perms.canEdit);
        $('#rsStaleBtn').toggle(!!m.perms.canAdminScope);
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
        renderStat(res); renderRemind(res); renderTargets(); renderListPrintHead(res.year, res.half);
    }).fail(function(x){ NProgress.done(); alert('載入失敗：'+(x.responseJSON&&x.responseJSON.error||x.status)); });
}
function renderListPrintHead(year, half){
    var title = META.list_print_title || '供應商稽核計畫實施結果';
    $('#vaListPrintHead').html('<div class="co">'+esc(META.company_name||'')+'</div>'
        + '<div class="tt">'+esc(title)+'</div>'
        + '<div class="sub">'+year+' 年　'+(half===1?'上半年':'下半年')+'（'+scopeLabel(CUR_SCOPE)+'）</div>');
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

function signStatusPill(status){
    var map = {completed:['st-todo','待送審核'], pending:['st-todo','待簽核'], approved:['st-done','已核准'], rejected:['st-dis','已退回']};
    var m = map[status] || ['st-done','已完成'];
    return '<span class="st-pill '+m[0]+'">'+m[1]+'</span>';
}
function renderTargets(){
    var html = '';
    TARGETS.forEach(function(t){
        var done = !!t.audit_date;
        var stat = t.disabled ? '<span class="st-pill st-dis">停用</span>'
                 : (done ? signStatusPill(t.sign_status) : '<span class="st-pill st-todo">未稽核</span>');
        html += '<tr'+(t.disabled?' class="dis"':'')+'>';
        html += '<td>'+esc(t.maker_id_no)+'</td>';
        html += '<td class="t-left"><b>'+esc(t.maker_id||'')+'</b></td>';
        html += '<td class="t-left">'+esc(t.main_cat_name||'—')+'</td>';
        html += '<td>'+(t.plan_month?t.plan_month+'月':'—')+'</td>';
        html += '<td>'+stat+(t.disabled?'':planTimeliness(t))+'</td>';
        html += '<td>'+(fmtDate(t.audit_date)||'—')+'</td>';
        html += '<td>'+(t.overall_rate==null?'—':t.overall_rate+'%')+'</td>';
        html += '<td>'+(t.judge?(t.judge==='pass'?'<span class="af-judge-pass">合格</span>':'<span class="af-judge-fail">不合格</span>'):'—')+'</td>';
        html += '<td>'+esc(t.auditor||'—')+'</td>';
        html += '<td>';
        if (PERMS.canEdit) html += '<span class="va-op" onclick="openRec('+t.target_id+')"><i class="fa fa-pencil"></i>登錄</span>';
        if (t.sign_status==='pending') html += '<span class="va-op" style="color:#DD8A38;" onclick="openSignMask('+t.target_id+')"><i class="fa fa-check-square-o"></i>簽核</span>';
        if (t.audit_date && t.sign_status!=='draft') html += '<span class="va-op" onclick="openRecordSheet('+t.target_id+')"><i class="fa fa-file-text-o"></i>記錄表</span>';
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
    var mx = +($(el).data('max')) || 7;
    var bad = v!=='' && (!/^\d+$/.test(v) || +v<0 || +v>mx);
    $(el).toggleClass('af-invalid', bad);
    return !bad;
}
function renderRecStatus(t){
    var st = t.status||'draft';
    var locked = (st==='pending'||st==='approved');
    var label = {completed:'已完成，待送審核', pending:'已送審核，待簽核', approved:'已核准'}[st] || '';
    var html='';
    if (st==='rejected' && t.reject_info){
        html = '<div style="background:#FDEAE6;border:1px solid #DD5138;border-radius:6px;padding:8px 10px;color:#a83a2a;">'
            + '<b>已退回</b>（'+esc(t.reject_info.by||'')+'　'+fmtDate(t.reject_info.at)+'）原因：'+esc(t.reject_info.note||'')
            + '，請修改後重新按「完成」送審。</div>';
    } else if (label){
        html = '<div style="background:#FFF7E8;border:1px solid #E8D5B5;border-radius:6px;padding:6px 10px;color:#8A5A2B;font-weight:bold;">目前狀態：'+label
            + (st==='approved' && t.signed_by_name ? '（主管：'+esc(t.signed_by_name)+' '+(fmtDate(t.signed_at)||'')+'）' : '') + '</div>';
    }
    $('#recStatusBox').html(html).toggle(!!html);
    $('#afBody input, #recPlanMonth, #recDate, #recMode, #recAuditor, #recSelfEval, #recReport, #recConclusion, #recNote, #qfSelf, #qfAudit, input[name=recReviewType]')
        .prop('disabled', locked);
    $('.af-quickfill button').prop('disabled', locked);
    $('#btnRecSave, #btnRecComplete').toggle(!locked);
    $('#btnRecSubmitSign').toggle(st==='completed');
}
function openRec(tid){
    recTid = tid;
    $.getJSON(API, {action:'get_form', target_id:tid}, function(res){
        if(!res.ok){ alert(res.error||'載入失敗'); return; }
        var t = res.target;
        CUR_CFG = t.checklist_cfg || {items:META.items, total_max:META.total_max, self_w:META.self_w, audit_w:META.audit_w, pass_rate:META.pass_rate};
        CUR_PROD_TYPE = t.prod_type || null;
        CUR_REC = t;
        $('#recTitle').text('稽核評鑑表單：'+t.maker_id+'（'+t.maker_id_no+'）');
        $('#recPlanMonth').val(t.plan_month||'');
        $('#recDate').val((t.audit_date?String(t.audit_date).substr(0,10):'')||META.today);
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
        $('input[name=recReviewType]').prop('checked', false);
        if (t.review_type) $('input[name=recReviewType][value="'+t.review_type+'"]').prop('checked', true);
        renderAttach(t.target_id, res.attaches||[]);
        renderForm(t.scores||{});
        renderRecStatus(t);
        openMask('recMask');
    });
}
function scopeLabel(s){ return {outsource:'外包加工',purchase:'採購',all:'通用'}[s]||s; }
/* ---------- 佐證附件 ---------- */
/** 附件連結：圖片/PDF 點擊開跳窗預覽(vaPreviewAttach)，不觸發瀏覽器下載；其餘類型維持開新分頁
 *  (瀏覽器本來就無法預覽，交給瀏覽器自行下載或開啟)。afAttachList/rsAttachList 共用。 */
function vaAttachLinkHtml(a){
    if (!a.exists) return '<span style="color:#c9bda9;text-decoration:line-through;flex:1;overflow:hidden;text-overflow:ellipsis;">📄 '+esc(a.original_name||'')+'(檔案不存在)</span>';
    var url = API+'?action=attach_open&attach_id='+a.attach_id, ext = vaAttachExt(a.original_name);
    if (VA_IMG_EXT.indexOf(ext)!==-1 || ext==='pdf') {
        return '<a href="'+esc(url)+'" class="va-attach-prev" data-url="'+esc(url)+'" data-ext="'+esc(ext)+'" data-name="'+esc(a.original_name||'')+'" style="color:#b5762a;flex:1;overflow:hidden;text-overflow:ellipsis;">📄 '+esc(a.original_name||'')+'</a>';
    }
    return '<a href="'+esc(url)+'" target="_blank" style="color:#b5762a;flex:1;overflow:hidden;text-overflow:ellipsis;">📄 '+esc(a.original_name||'')+'</a>';
}
$(document).on('click', 'a.va-attach-prev', function(e){
    e.preventDefault();
    vaPreviewAttach($(this).data('url'), $(this).data('name'), $(this).data('ext'));
});
function vaPreviewAttach(url, name, ext){
    $('#vaPrevTitle').text(name||'附件預覽');
    if (ext==='pdf') { $('#vaPrevImg').hide().attr('src',''); $('#vaPrevFrame').attr('src', url).show(); }
    else { $('#vaPrevFrame').hide().attr('src',''); $('#vaPrevImg').attr('src', url).show(); }
    openMask('vaPrevMask');
}
function vaClosePreview(){ $('#vaPrevFrame').attr('src',''); closeMask('vaPrevMask'); }
function renderAttach(tid, list){
    $('#afAttachBox').data('tid', tid);
    var h='';
    (list||[]).forEach(function(a){
        h += '<div style="display:flex;gap:8px;align-items:center;border-bottom:1px dashed #EADFC8;padding:3px 0;">';
        h += vaAttachLinkHtml(a);
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
    var cfg = CUR_CFG || {items:META.items};
    cfg.items.forEach(function(cat){
        html+='<tr class="af-cat"><td class="af-q">'+esc(cat[1])+'</td><td class="af-sc af-self-col">自評</td><td class="af-sc af-audit-col">稽核</td></tr>';
        cat[2].forEach(function(it){
            var iid=it[0], no=it[1], q=it[2], mx=it[3], s=scores[iid]||{};
            html+='<tr data-iid="'+iid+'">';
            html+='<td class="af-q">'+esc(no)+'. '+esc(q)+'</td>';
            html+='<td class="af-sc af-self-col"><input type="number" class="af-self af-score" data-max="'+mx+'" min="0" max="'+mx+'" step="1" placeholder="0~'+mx+'" value="'+(s.self==null?'':s.self)+'" oninput="scoreCheck(this);recompute()"></td>';
            html+='<td class="af-sc af-audit-col"><input type="number" class="af-audit af-score" data-max="'+mx+'" min="0" max="'+mx+'" step="1" placeholder="0~'+mx+'" value="'+(s.audit==null?'':s.audit)+'" oninput="scoreCheck(this);recompute()"></td>';
            html+='</tr>';
        });
    });
    $('#afBody').html(html);
    applyReviewTypeCols();
    recompute();
}
/** 異常檢核只需稽核分：切換審查類別時隱藏/顯示自評欄（僅畫面隱藏，資料仍在，切回其他類別會還原）；
 *  供應商自主評核對應的稽核狀況固定是「自我評量」，選到此審查類別時自動帶入。 */
function applyReviewTypeCols(){
    var rt = $('input[name=recReviewType]:checked').val();
    $('#afTable').toggleClass('va-abnormal', rt==='abnormal');
    if (rt === 'self') $('#recMode').val('self');
}
$(document).on('change', 'input[name=recReviewType]', applyReviewTypeCols);
function collectScores(){
    var scores={};
    $('#afBody tr[data-iid]').each(function(){
        var iid=$(this).data('iid'), self=$(this).find('.af-self').val(), audit=$(this).find('.af-audit').val();
        if(self!==''||audit!=='') scores[iid]={self:self===''?null:+self, audit:audit===''?null:+audit};
    });
    return scores;
}
function quickFillScore(kind){
    var v = $('#qf'+(kind==='self'?'Self':'Audit')).val();
    if (v===''){ alert('請輸入分數'); return; }
    v = +v;
    if (isNaN(v) || v<0){ alert('請輸入 0 以上的整數'); return; }
    $('#afBody .af-'+kind).each(function(){
        var mx = +$(this).data('max') || 7;
        $(this).val(Math.min(v, mx));
        scoreCheck(this);
    });
    recompute();
}
function quickClearScore(kind){
    if (!confirm((kind==='self'?'自評分':'稽核分')+'全部清空？（清空後仍可個別重新填寫）')) return;
    $('#afBody .af-'+kind).each(function(){ $(this).val(''); scoreCheck(this); });
    recompute();
}
function recompute(){
    var cfg = CUR_CFG || {items:META.items, self_w:META.self_w, audit_w:META.audit_w, pass_rate:META.pass_rate};
    var pass=cfg.pass_rate, sw=cfg.self_w, aw=cfg.audit_w, scores=collectScores();
    var rows='<table><tr><th>分類</th><th>滿分</th><th>自評分</th><th>稽核分</th><th>自評率</th><th>稽核率</th></tr>';
    var tSelf=0,tAudit=0,tMax=0;
    cfg.items.forEach(function(cat){
        var items=cat[2], cMax=0, cSelf=0, cAudit=0;
        items.forEach(function(it){ var mx=it[3], s=scores[it[0]]||{};
            cMax+=mx; cSelf+=Math.max(0,Math.min(mx,+s.self||0)); cAudit+=Math.max(0,Math.min(mx,+s.audit||0)); });
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
    // 儲存(草稿)：只做基本檢查，不檢查評分內容/結果——完整性檢查留給「完成」按鈕
    if ($('#recDate').val() && !$('#recAuditor').val()){ alert('請選擇稽核員'); return; }
    var bad=0;
    $('#afBody .af-score').each(function(){ if(!scoreCheck(this)) bad++; });
    if(bad){ alert('有 '+bad+' 個分數超出範圍或非整數，已標紅，請修正後再儲存'); return; }
    var scores=collectScores();
    $.post(API, {action:'record_target', target_id:recTid, audit_date:$('#recDate').val(), plan_month:$('#recPlanMonth').val(),
        audit_mode:$('#recMode').val(), auditor:$('#recAuditor').val(), review_type:$('input[name=recReviewType]:checked').val()||'',
        self_evaluator:$('#recSelfEval').val(), report_no:$('#recReport').val(),
        conclusion:$('#recConclusion').val(), note:$('#recNote').val(), scores:JSON.stringify(scores)},
    function(res){
        if(!res.ok){ alert(res.error||'儲存失敗'); return; }
        closeMask('recMask'); loadRound();
    }, 'json').fail(function(x){ alert('儲存失敗：'+(x.responseJSON&&x.responseJSON.error||x.status)); });
}
/** 完成前的完整性檢查：稽核員/建議結論/所有項次自評稽核分皆需填寫且在單項滿分內，回傳缺漏說明陣列(空=通過) */
function recCompleteErrors(){
    var errs=[];
    if (!$('#recAuditor').val()) errs.push('請填寫稽核員');
    if (!$('#recDate').val()) errs.push('請先填寫稽核日期');
    if (!$('#recConclusion').val()) errs.push('請選擇建議評鑑結果');
    if (!$('input[name=recReviewType]:checked').length) errs.push('請選擇審查類別（人員實地審查／供應商自主評核／異常檢核）');
    var reviewType = $('input[name=recReviewType]:checked').val();
    var scores=collectScores(), badSelf=0, badAudit=0;
    (CUR_CFG&&CUR_CFG.items||[]).forEach(function(cat){
        cat[2].forEach(function(it){
            var iid=it[0], mx=it[3], s=scores[iid]||{};
            var ok=function(v){ return v!=null && v!=='' && /^\d+$/.test(String(v)) && +v>=0 && +v<=mx; };
            if(reviewType!=='abnormal' && !ok(s.self)) badSelf++;
            if(!ok(s.audit)) badAudit++;
        });
    });
    if (badSelf) errs.push('尚有 '+badSelf+' 項自評分未填寫或超出範圍');
    if (badAudit) errs.push('尚有 '+badAudit+' 項稽核分未填寫或超出範圍');
    return errs;
}
function completeRec(){
    var errs = recCompleteErrors();
    if (errs.length){ alert('尚未完成，請先修正：\n'+errs.join('\n')); return; }
    var scores=collectScores();
    $.post(API, {action:'complete_target', target_id:recTid, audit_date:$('#recDate').val(), plan_month:$('#recPlanMonth').val(),
        audit_mode:$('#recMode').val(), auditor:$('#recAuditor').val(), review_type:$('input[name=recReviewType]:checked').val()||'',
        self_evaluator:$('#recSelfEval').val(), report_no:$('#recReport').val(),
        conclusion:$('#recConclusion').val(), note:$('#recNote').val(), scores:JSON.stringify(scores)},
    function(res){
        if(!res.ok){ alert(res.error||'完成失敗'); return; }
        alert(res.status==='approved' ? '已完成並自動核可' : '已完成，已自動通知主管簽核');
        closeMask('recMask'); loadRound();
    }, 'json').fail(function(x){ alert('完成失敗：'+(x.responseJSON&&x.responseJSON.error||x.status)); });
}
function submitSign(){
    if (!confirm('確定送出簽核？送出後將鎖定此筆，需等待主管核准或退回。')) return;
    $.post(API, {action:'submit_sign', target_id:recTid}, function(res){
        if(!res.ok){ alert(res.error||'送出失敗'); return; }
        alert('已送出簽核通知');
        closeMask('recMask'); loadRound();
    }, 'json').fail(function(x){ alert('送出失敗：'+(x.responseJSON&&x.responseJSON.error||x.status)); });
}
/* ---------- 簽核決行(通知連結 ?sign=target_id) ---------- */
var SIGN_TID = null;
function openSignMask(tid){
    $.getJSON(API, {action:'get_form', target_id:tid}, function(res){
        if(!res.ok){ alert(res.error||'載入失敗'); return; }
        var t = res.target, cfg = t.checklist_cfg, c = computeCats(t.scores||{}, cfg);
        SIGN_TID = tid;
        $('#signTitle').text('供應商稽核簽核：'+t.maker_id+'（'+t.maker_id_no+'）');
        var modeL={first:'首次稽核',again:'次稽核',self:'自我評量'}[t.audit_mode]||'—';
        $('#signInfo').html('稽核日期：'+(fmtDate(t.audit_date)||'—')+'　稽核狀況：'+modeL+'　稽核員：'+esc(t.auditor||'—')+'　建議結論：'+esc(t.conclusion||'—'));
        var rows='';
        c.cats.forEach(function(k){ rows+='<tr><td class="af-q">'+esc(k.name)+'</td><td class="af-sc">'+k.max+'</td><td class="af-sc">'+k.self_rate+'%</td><td class="af-sc">'+k.audit_rate+'%</td></tr>'; });
        $('#signCatBody').html(rows);
        $('#signConc').html('綜合合格率：<b style="font-size:16px;">'+c.overall+'%</b>　判定：'+vaJudgeBadgeHtml(c.pass));
        $('#signNote').val('');
        if (t.status !== 'pending') alert('此筆目前狀態非「待簽核」（目前：'+(t.status||'draft')+'），可能已被處理過，請確認後再決行');
        openMask('signMask');
    });
}
function signDecideAction(decision){
    if (decision==='rejected' && !$('#signNote').val().trim()){ alert('退回必須填寫原因'); return; }
    if (!confirm(decision==='approved' ? '確定核准？' : '確定退回？')) return;
    $.post(API, {action:'sign_decide', target_id:SIGN_TID, decision:decision, note:$('#signNote').val()}, function(res){
        if(!res.ok){ alert(res.error||'處理失敗'); return; }
        alert(decision==='approved' ? '已核准' : '已退回');
        closeMask('signMask'); loadRound();
    }, 'json').fail(function(x){ alert('處理失敗：'+(x.responseJSON&&x.responseJSON.error||x.status)); });
}
function removeTarget(tid){
    if (!confirm('將此廠商移出本期稽核對象？')) return;
    $.post(API, {action:'remove_target', target_id:tid}, function(res){
        if (!res.ok){ alert(res.error||'移除失敗'); return; }
        loadRound();
    }, 'json');
}

/* ---------- 週期設定 ---------- */
/* 週期設定 */
$('#btnCycle').on('click', function(){ $('#cycVal').val(META.cycle_months); $('#cycListTitle').val(META.list_print_title||''); openMask('cycMask'); });
function submitCycle(){
    $.post(API, {action:'save_cycle', cycle_months:$('#cycVal').val(), list_print_title:$('#cycListTitle').val()}, function(res){
        if (!res.ok){ alert(res.error||'儲存失敗'); return; }
        META.cycle_months = res.cycle_months; META.list_print_title = res.list_print_title; closeMask('cycMask'); loadRound();
        renderListPrintHead();
    }, 'json');
}
/* 附件路徑設定 */
$('#btnAttachSet').on('click', function(){ $('#cycAttachBase').val(META.attach_base||''); openMask('attMask'); });
function submitAttachBase(){
    $.post(API, {action:'save_cycle', cycle_months:META.cycle_months, attach_base:$('#cycAttachBase').val()}, function(res){
        if (!res.ok){ alert(res.error||'儲存失敗'); return; }
        META.attach_base = res.attach_base; closeMask('attMask');
    }, 'json');
}
/* AS 文件綁定設定（四份文件） */
$('#btnAsDoc').on('click', function(){
    loadAsForms('#cycAsDoc', '', META.as_doc, '供應商評鑑稽核查表 / 2-PH-01-02');
    loadAsForms('#cycRecDoc', '', META.record_as_doc, '供應商品質系統評鑑記錄表 / 2-PH-01-03');
    loadAsForms('#cycRosDoc', '', META.roster_as_doc, '合格供應商清冊 / 2-PH-01-04');
    loadAsForms('#cycEvDoc', '', META.eval_as_doc, '供應商定期評核表 / 2-PH-01-05');
    loadAsForms('#cycPlanDoc', '', META.plan_as_doc, '供應商稽核計劃 / 2-PH-01-06');
    openMask('asDocMask');
});
var cycAsT=null, cycRecT=null, cycRosT=null, cycEvT=null, cycPlanT=null;
$('#cycAsKw').on('input', function(){ clearTimeout(cycAsT); var k=$(this).val(); cycAsT=setTimeout(function(){ loadAsForms('#cycAsDoc', k, META.as_doc, '供應商評鑑稽核查表 / 2-PH-01-02', +$('#cycAsDoc').val()); }, 300); });
$('#cycRecKw').on('input', function(){ clearTimeout(cycRecT); var k=$(this).val(); cycRecT=setTimeout(function(){ loadAsForms('#cycRecDoc', k, META.record_as_doc, '供應商品質系統評鑑記錄表 / 2-PH-01-03', +$('#cycRecDoc').val()); }, 300); });
$('#cycRosKw').on('input', function(){ clearTimeout(cycRosT); var k=$(this).val(); cycRosT=setTimeout(function(){ loadAsForms('#cycRosDoc', k, META.roster_as_doc, '合格供應商清冊 / 2-PH-01-04', +$('#cycRosDoc').val()); }, 300); });
$('#cycEvKw').on('input', function(){ clearTimeout(cycEvT); var k=$(this).val(); cycEvT=setTimeout(function(){ loadAsForms('#cycEvDoc', k, META.eval_as_doc, '供應商定期評核表 / 2-PH-01-05', +$('#cycEvDoc').val()); }, 300); });
$('#cycPlanKw').on('input', function(){ clearTimeout(cycPlanT); var k=$(this).val(); cycPlanT=setTimeout(function(){ loadAsForms('#cycPlanDoc', k, META.plan_as_doc, '供應商稽核計劃 / 2-PH-01-06', +$('#cycPlanDoc').val()); }, 300); });
function loadAsForms(sel, kw, curDoc, defLabel, keepId){
    var selId = keepId!=null ? keepId : (curDoc?curDoc.id:0);
    $.getJSON(API, {action:'as_forms', kw:kw||''}, function(res){
        if(!res.ok) return;
        var $s=$(sel).html('<option value="0">（用預設「'+defLabel+'」）</option>');
        (res.forms||[]).forEach(function(f){ $s.append('<option value="'+f.id+'">'+esc(f.doc_no)+' '+esc(f.doc_name)+'</option>'); });
        if(selId && $s.find('option[value="'+selId+'"]').length===0 && curDoc)
            $s.append('<option value="'+curDoc.id+'">'+esc(curDoc.doc_no)+' '+esc(curDoc.doc_name)+'（目前綁定）</option>');
        $s.val(selId||0);
    });
}
function submitAsDoc(){
    $.post(API, {action:'save_cycle', cycle_months:META.cycle_months,
        as_doc_id:$('#cycAsDoc').val(), record_as_doc_id:$('#cycRecDoc').val(),
        roster_as_doc_id:$('#cycRosDoc').val(), eval_as_doc_id:$('#cycEvDoc').val(),
        plan_as_doc_id:$('#cycPlanDoc').val()}, function(res){
        if (!res.ok){ alert(res.error||'儲存失敗'); return; }
        META.as_doc=res.as_doc; META.record_as_doc=res.record_as_doc; META.roster_as_doc=res.roster_as_doc; META.eval_as_doc=res.eval_as_doc;
        META.plan_as_doc=res.plan_as_doc;
        closeMask('asDocMask');
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
/** 查核表單一版本(mode='self'=供應商自主評核版,全部留白；mode='site'=人員實地審查版,顯示分數) */
function auditFormOneVersion(o, mode){
    var cfg = o.cfg || CUR_CFG || {items:META.items, total_max:META.total_max, self_w:META.self_w, audit_w:META.audit_w, pass_rate:META.pass_rate};
    var docName = (META.as_doc && META.as_doc.doc_name) || '供應商評鑑稽核查表';
    var head = '<div style="text-align:center;">'
        + '<div style="font-size:24px;font-weight:bold;letter-spacing:1px;">'+esc(META.company_name||'')+'</div>'
        + '<div style="font-size:18px;font-weight:bold;margin-top:3px;">'+esc(docName)+'</div></div>';
    var reviewMap = {site:'人員實地審查', self:'供應商自主評核', abnormal:'異常檢核'};
    var reviewBoxes = ['site','self','abnormal'].map(function(k){ return (mode===k?'☑':'□')+reviewMap[k]; }).join('　');
    var prodMap = {raw:'原料', outsource:'委外加工件', packaging:'包材'};
    var prodBoxes = ['raw','outsource','packaging'].map(function(k){ return (o.prodType===k?'☑':'□')+prodMap[k]; }).join('　');
    var modeMap = {first:'首次稽核', again:'次稽核', self:'自我評量'};
    // 供應商自主評核版(mode==='self')本來就只有「自我評量」這一種可能，固定勾選；
    // 人員實地審查/異常檢核版依該筆紀錄真實的稽核狀況(o.mode)顯示（可能是首次或次稽核）
    var modeForBoxes = (mode === 'self') ? 'self' : o.mode;
    var modeBoxes = ['first','again','self'].map(function(k){ return (modeForBoxes===k?'☑':'□')+modeMap[k]; }).join('　');
    var info = '<table class="pf-info"><tr>'
        + '<td>供應商：'+(o.maker?esc(o.maker):'________________')+'</td>'
        + '<td>日期：'+(o.dateStr?esc(o.dateStr):'____ / ____ / ____')+'</td></tr>'
        + '<tr><td colspan="2">審查類別：'+reviewBoxes+'</td></tr>'
        + '<tr><td colspan="2">生產類別：'+prodBoxes+'</td></tr>'
        + '<tr><td colspan="2">稽核狀況：'+modeBoxes+'</td></tr></table>';
    var showScores = (mode === 'site');
    var scoreLabel = mode==='site' ? '稽核分' : '自評分';
    var tSelf = 0, tAudit = 0;
    var rows = '<table class="pf" style="table-layout:fixed;"><colgroup><col style="width:60px;"><col style="width:34px;"><col>'
        + '<col style="width:70px;"><col style="width:100px;"></colgroup>'
        + '<thead><tr><th>項目</th><th>項次</th><th>查核問題</th><th>單項滿分</th><th>'+scoreLabel+'</th></tr></thead><tbody>';
    cfg.items.forEach(function(cat){
        var items = cat[2];
        items.forEach(function(it, idx){
            var s = (o.scores && o.scores[it[0]]) || {};
            if (s.self != null && s.self !== '') tSelf += +s.self;
            if (s.audit != null && s.audit !== '') tAudit += +s.audit;
            var sv = mode==='site' ? s.audit : s.self;
            rows += '<tr>';
            if (idx===0) rows += '<td rowspan="'+items.length+'" style="vertical-align:middle;font-weight:bold;">'+esc(cat[1])+'</td>';
            rows += '<td>'+esc(it[1])+'</td><td class="q">'+esc(it[2])+'</td><td>'+it[3]+'</td>'
                + '<td>'+(showScores && sv!=null?sv:'')+'</td></tr>';
        });
    });
    rows += '<tr style="font-weight:bold;"><td colspan="3">合計</td><td>'+cfg.total_max+'</td>'
        + '<td>'+(showScores?(mode==='site'?tAudit:tSelf):'')+'</td></tr>';
    rows += '</tbody></table>';
    var madeCell = mode==='site' ? (o.auditorName ? vaStampHtml(o.auditorName, o.dateStr||'') : '__________________')
                 : (mode==='self' ? '' : '__________________');
    var mgrCell = mode==='self' ? 'N/A'
                : (o.mgrApproved && o.mgrName ? vaStampHtml(o.mgrName, o.mgrDate||'', o.mgrIsDeputy) : '__________________');
    var sign = '<table class="pf-sign"><tr>'
        + '<td style="width:50%;">主管簽核：'+mgrCell+'</td>'
        + '<td style="width:50%;">製表：'+madeCell+'</td></tr></table>';
    return head + info + rows + sign;
}
/** 查核表列印一律一次印兩個版本：供應商自主評核版(全空白給供應商填)＋人員實地審查版(顯示分數,製表=稽核員) */
function auditFormHTML(o){
    o = o || {};
    return '<div style="page-break-after:always;">'+auditFormOneVersion(o,'self')+'</div>'+auditFormOneVersion(o,'site');
}
/** noPageCount=true：本印出的內容本來就是多份各自獨立的文件(如查核表自評版+審查版)串接列印，
 *  不是同一份文件跨頁，不該顯示「第X頁/共Y頁」(即使實體紙張超過一張) */
/** extraCss：額外CSS，供混合橫直式列印用「具名頁」覆蓋(比照 meeting_record.php 的 egPrintWindow/doPrintFullRecord，
 *  同一份列印工作內用 @page 具名規則(如 @page va-landscape{size:A4 landscape;...})+對應元素套 page:va-landscape
 *  達成同一次列印混排直式/橫式，實測穩定可行，不需要拆成多個列印視窗)。 */
function writePrintWindow(w, bodyHtml, title, docNo, landscape, noPageCount, extraCss){
    var asTxt = String(docNo||'').replace(/['\\]/g,'');
    var css = 'body{font-family:"Microsoft JhengHei","微軟正黑體",sans-serif;color:#000;padding:14px;}'
        + 'table.pf{width:100%;border-collapse:collapse;font-size:13px;margin-top:8px;}'
        + 'table.pf th,table.pf td{border:1px solid #333;padding:3px 6px;height:22px;text-align:center;vertical-align:middle;}'
        + 'table.pf td.q{text-align:left;}'
        + 'table.pf.plan-table th,table.pf.plan-table td{padding:2px 5px;height:16px;}'
        + 'table.pf-info{width:100%;font-size:13px;margin-top:10px;border-collapse:collapse;}table.pf-info td{padding:5px 6px;border:1px solid #999;}'
        + 'table.pf-sign{width:100%;margin-top:20px;font-size:13px;page-break-inside:avoid;}table.pf-sign td{padding:14px 6px 8px;}'
        + '.stamp-wrap svg,svg.car-stamp{width:91px;height:91px;-webkit-print-color-adjust:exact;print-color-adjust:exact;}'
        + '.rs-chart-wrap{margin:0 auto;}.rs-chart-wrap svg{width:auto !important;height:230px !important;max-width:100% !important;}'
        + 'table.pf.rs-table{font-size:15px;}table.pf.rs-table th,table.pf.rs-table td{padding:6px 8px;height:28px;}'
        + '.attach-page{page-break-before:always;}'
        + '@media print{@page{size:A4 '+(landscape?'landscape':'portrait')+';margin:12mm 8mm 16mm;'
        + (asTxt ? " @bottom-right{ content:'"+asTxt+"'; font-size:9pt; color:#333; }" : '')
        + '}' + (extraCss||'') + '}';
    w.document.open();
    w.document.write('<html><head><meta charset="utf-8"><title>'+esc(title)+'</title><style>'+css+'</style></head><body>'
        + bodyHtml
        + '<scr'+'ipt>window.onload=function(){'
        + (noPageCount ? '' :
          'var onePageA4=(297-28)*96/25.4;'
        + 'if(document.body.scrollHeight>onePageA4*0.92){'
        + 'var st=document.createElement(\'style\');'
        + 'st.textContent="@page{ @bottom-left{ content:\'第 \' counter(page) \' 頁／共 \' counter(pages) \' 頁\'; font-size:9pt; color:#333; } }";'
        + 'document.head.appendChild(st);}')
        + 'setTimeout(function(){window.print();},150);};</scr'+'ipt></body></html>');
    w.document.close();
}
function openPrintWindow(bodyHtml, title, docNo, landscape, noPageCount, extraCss){
    var w = window.open('', '_blank');
    if (!w){ alert('請允許彈出視窗以列印'); return null; }
    writePrintWindow(w, bodyHtml, title, docNo, landscape, noPageCount, extraCss);
    return w;
}
function printBlankForm(){
    var cfg = {items:META.items, total_max:META.total_max, self_w:META.self_w, audit_w:META.audit_w, pass_rate:META.pass_rate};
    openPrintWindow(auditFormHTML({cfg:cfg}), '供應商評鑑稽核查表', (META.as_doc&&META.as_doc.doc_no)||'2-PH-01-02', false, true);
}
function printCurrentForm(){
    openPrintWindow(auditFormHTML({
        maker: $('#recTitle').text().replace('稽核評鑑表單：',''),
        dateStr: fmtDate($('#recDate').val()), scores: collectScores(), mode: $('#recMode').val(),
        prodType: CUR_PROD_TYPE, auditorName: $('#recAuditor').val(),
        mgrApproved: !!(CUR_REC && CUR_REC.status==='approved' && CUR_REC.signed_by_name),
        mgrName: CUR_REC && CUR_REC.signed_by_name, mgrDate: fmtDate($('#recDate').val()),
        mgrIsDeputy: CUR_REC && !!CUR_REC.signed_is_deputy
    }), '供應商評鑑稽核查表', (CUR_REC&&CUR_REC.as_doc_no)||(META.as_doc&&META.as_doc.doc_no)||'2-PH-01-02', false, true);
}
$('#btnBlank').on('click', printBlankForm);

/* ---------- 評鑑記錄表（2-PH-01-03，雷達圖）---------- */
var RS = null, rsChart = null;
function computeCats(scores, cfg){
    cfg = cfg || {items:META.items, self_w:META.self_w, audit_w:META.audit_w, pass_rate:META.pass_rate};
    var cats=[], tSelf=0,tAudit=0,tMax=0;
    cfg.items.forEach(function(cat){
        var items=cat[2], cMax=0, cSelf=0,cAudit=0;
        items.forEach(function(it){ var mx=it[3], s=scores[it[0]]||{}; cMax+=mx; cSelf+=Math.max(0,Math.min(mx,+s.self||0)); cAudit+=Math.max(0,Math.min(mx,+s.audit||0)); });
        cats.push({name:cat[1], max:cMax, self_rate:cMax?Math.round(cSelf/cMax*1000)/10:0, audit_rate:cMax?Math.round(cAudit/cMax*1000)/10:0});
        tSelf+=cSelf;tAudit+=cAudit;tMax+=cMax;
    });
    var selfR=tMax?Math.round(tSelf/tMax*1000)/10:0, auditR=tMax?Math.round(tAudit/tMax*1000)/10:0;
    var overall=Math.round((selfR*cfg.self_w+auditR*cfg.audit_w)*10)/10;
    return {cats:cats, selfR:selfR, auditR:auditR, overall:overall, pass:overall>=cfg.pass_rate, total_max:tMax};
}
function openRecordSheet(tid){
    $.getJSON(API, {action:'get_form', target_id:tid}, function(res){
        if(!res.ok){ alert(res.error||'載入失敗'); return; }
        var t=res.target, cfg=t.checklist_cfg, c=computeCats(t.scores||{}, cfg);
        RS={tid:tid, t:t, c:c, cfg:cfg, attaches:res.attaches||[]};
        var doc=META.record_as_doc, docName=(doc&&doc.doc_name)||'供應商品質系統評鑑記錄表';
        $('#rsTitle').text(docName+'：'+t.maker_id+'（'+t.maker_id_no+'）');
        var modeL={first:'首次稽核',again:'次稽核',self:'自我評量'}[t.audit_mode]||'—';
        $('#rsInfo').html('供應商：<b>'+esc(t.maker_id)+'</b>（'+esc(t.maker_id_no)+'）　稽核日期：'+(fmtDate(t.audit_date)||'—')+'　稽核狀況：'+modeL+'　稽核員：'+esc(t.auditor||'—'));
        var rows='';
        c.cats.forEach(function(k){ var comb=Math.round((k.self_rate*cfg.self_w+k.audit_rate*cfg.audit_w)*10)/10;
            rows+='<tr><td class="af-q">'+esc(k.name)+'</td><td class="af-sc">'+k.max+'</td><td class="af-sc">'+k.self_rate+'%</td><td class="af-sc">'+k.audit_rate+'%</td><td class="af-sc">'+comb+'%</td></tr>'; });
        rows+='<tr class="af-cat"><td class="af-q">總成績</td><td class="af-sc">'+c.total_max+'</td><td class="af-sc">'+c.selfR+'%</td><td class="af-sc">'+c.auditR+'%</td><td class="af-sc">'+c.overall+'%</td></tr>';
        $('#rsCatBody').html(rows);
        $('#rsConc').html('綜合評鑑合格率（自評×'+cfg.self_w+'＋稽核×'+cfg.audit_w+'）：<b style="font-size:16px;">'+c.overall+'%</b>'
            +'（核准條件 ≥'+cfg.pass_rate+'%）'+(t.conclusion?'　建議：'+esc(t.conclusion):'')
            +'<div style="margin-top:6px;">判定：'+vaJudgeBadgeHtml(c.pass)+'</div>');
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
        h+=vaAttachLinkHtml(a);
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
        $.getJSON(API,{action:'get_form',target_id:tid},function(r){ if(r.ok){ rsRenderAttach(tid,r.attaches); if(RS) RS.attaches=r.attaches||[]; } }); })
     .fail(function(x){ NProgress.done(); alert('上傳失敗：'+(x.responseJSON&&x.responseJSON.error||x.status)); });
}
function rsDelAttach(aid){
    if(!confirm('刪除此附件？')) return;
    $.post(API,{action:'attach_delete',attach_id:aid},function(res){ if(!res.ok){alert(res.error||'失敗');return;}
        var tid=$('#rsAttachBox').data('tid'); $.getJSON(API,{action:'get_form',target_id:tid},function(r){ if(r.ok){ rsRenderAttach(tid,r.attaches); if(RS) RS.attaches=r.attaches||[]; } }); },'json');
}
function vaStampHtml(name, date, isDeputy){
    try { if (window.EGStamp && EGStamp.stamp) return EGStamp.stamp(name, date||'', !!isDeputy); } catch(e){}
    return name ? ('<span style="font-size:14px;">'+esc(name)+'</span>'+(date?'<div style="font-size:10px;color:#555;">'+esc(date)+'</div>':'')) : '';
}
function vaJudgeBadgeHtml(pass){
    return '<span style="display:inline-block;font-size:19px;font-weight:bold;padding:3px 16px;border-radius:6px;border:2px solid '
        +(pass?'#8A5A2B;color:#8A5A2B;background:#FBEBD2;':'#DD5138;color:#DD5138;background:#FDEAE6;')+'">'
        +(pass?'合格供應商':'不合格')+'</span>';
}
/** 記錄表列印內容(拼接HTML字串，非直接列印畫面)：雷達圖抓當下畫面已渲染的live SVG(rsChart)嵌入，
 *  其餘表格/文字重新組版——比照 meeting_record.php 的作法，同一份文件的內容用字串組出來，
 *  才能跟查核表一起放進同一個列印視窗、用具名頁(@page)混排橫直式(見 printAllDocs())。
 *  簽章列分三欄(左欄/中間留白/右欄)，比照本檔供應商稽核計劃列印(fixedFoot)既有規則。 */
function recordSheetHTML(){
    if (!RS) return '';
    var t=RS.t, c=RS.c, cfg=RS.cfg, doc=META.record_as_doc, docName=(doc&&doc.doc_name)||'供應商品質系統評鑑記錄表';
    var modeL={first:'首次稽核',again:'次稽核',self:'自我評量'}[t.audit_mode]||'____';
    var head='<div style="text-align:center;"><div style="font-size:25px;font-weight:bold;letter-spacing:1px;">'+esc(META.company_name||'')+'</div>'
        +'<div style="font-size:19px;font-weight:bold;margin-top:3px;">'+esc(docName)+'</div></div>';
    var info='<table class="pf-info"><tr><td>供應商：'+esc(t.maker_id)+'（'+esc(t.maker_id_no)+'）</td><td>加工項目：'+esc(t.main_cat_name||'—')+'</td><td>稽核日期：'+(fmtDate(t.audit_date)||'____')+'</td><td>稽核狀況：'+esc(modeL)+'</td></tr></table>';
    var rows='<table class="pf rs-table" style="table-layout:fixed;"><colgroup><col style="width:38%;"><col style="width:14%;"><col style="width:16%;"><col style="width:16%;"><col style="width:16%;"></colgroup>'
        +'<thead><tr><th>評鑑項目</th><th>單項滿分</th><th>自評合格率</th><th>稽核合格率</th><th>綜合合格率</th></tr></thead><tbody>';
    c.cats.forEach(function(k){ var comb=Math.round((k.self_rate*cfg.self_w+k.audit_rate*cfg.audit_w)*10)/10;
        rows+='<tr><td class="q">'+esc(k.name)+'</td><td>'+k.max+'</td><td>'+k.self_rate+'%</td><td>'+k.audit_rate+'%</td><td>'+comb+'%</td></tr>'; });
    rows+='<tr style="font-weight:bold;"><td class="q">總成績</td><td>'+c.total_max+'</td><td>'+c.selfR+'%</td><td>'+c.auditR+'%</td><td>'+c.overall+'%</td></tr></tbody></table>';
    var svg = rsChart ? rsChart.container.querySelector('svg').outerHTML : '';
    var body = '<div style="display:flex;gap:20px;align-items:center;margin-top:8px;">'
        + '<div style="flex:0 0 42%;min-width:0;">'+rows+'</div>'
        + '<div class="rs-chart-wrap" style="text-align:center;flex:1;">'+svg+'</div></div>';
    var conc='<div style="margin-top:8px;font-size:13px;">綜合評鑑合格率（自評×'+cfg.self_w+'＋稽核×'+cfg.audit_w+'）＝<b style="font-size:16px;">'+c.overall+'%</b>；核准條件：綜合合格率 ≥'+cfg.pass_rate+'%'+(t.conclusion?'；建議：'+esc(t.conclusion):'')+'</div>'
        +'<div style="margin-top:4px;">判定：'+vaJudgeBadgeHtml(c.pass)+'</div>';
    var mgrStamp = (t.status==='approved' && t.signed_by_name) ? vaStampHtml(t.signed_by_name, fmtDate(t.audit_date)||'', !!t.signed_is_deputy) : '';
    var audStamp = t.auditor ? vaStampHtml(t.auditor, fmtDate(t.audit_date)||'') : '';
    var sign='<table class="pf-sign" style="margin-top:14px;border:none;page-break-inside:avoid;"><tr>'
        +'<td style="width:33%;border:none;"><div style="font-size:11px;color:#555;">主管</div><div style="margin-top:2px;min-height:91px;">'+mgrStamp+'</div></td>'
        +'<td style="width:34%;border:none;"></td>'
        +'<td style="width:33%;border:none;"><div style="font-size:11px;color:#555;">稽核員</div><div style="margin-top:2px;min-height:91px;">'+audStamp+'</div></td>'
        +'</tr></table>';
    return head+info+body+conc+sign;
}
/** 記錄表本身橫式，但附件一律直式（圖面/掃描件多為直式拍攝，橫式反而縮得更小），
 *  用具名頁 va-portrait 覆蓋回直式，比照 printAllDocs() 混排橫直式的既有做法。 */
async function printRecordSheet(){
    if (!RS) { alert('無資料'); return; }
    var docNo = (RS.t&&RS.t.record_as_doc_no)||(META.record_as_doc&&META.record_as_doc.doc_no)||'2-PH-01-03';
    var attachHtml = '';
    if ((RS.attaches||[]).length) {
        try { attachHtml = await vaBuildAttachPrintHTML(RS.attaches); }
        catch (e) { attachHtml = '<div style="color:#c00;">附件載入發生錯誤，部分附件可能未列印，請至系統個別下載查看。</div>'; }
    }
    var extraCss = '';
    if (attachHtml) {
        var asTxt = String(docNo||'').replace(/['\\]/g,'');
        extraCss = '@page va-portrait{size:A4 portrait;margin:12mm 8mm 16mm;'
            + (asTxt ? " @bottom-right{ content:'"+asTxt+"'; font-size:9pt; color:#333; }" : '')
            + '} .va-portrait-page{page:va-portrait;}';
    }
    var body = recordSheetHTML() + (attachHtml ? '<div class="va-portrait-page">' + attachHtml + '</div>' : '');
    openPrintWindow(body, '供應商品質系統評鑑記錄表', docNo, true, true, extraCss);
}

/* ---------- 記錄表列印附加佐證附件（圖片直接嵌入／PDF 用 pdf.js 轉圖，其餘類型不支援預覽） ---------- */
var VA_IMG_EXT = ['jpg','jpeg','png','gif','bmp','webp'];
function vaAttachExt(name){ var m = String(name||'').match(/\.([a-z0-9]+)$/i); return m ? m[1].toLowerCase() : ''; }
function vaImgToDataURL(url){
    return new Promise(function(resolve){
        var img = new Image();
        img.onload = function(){
            try {
                var cv = document.createElement('canvas'); cv.width = img.naturalWidth; cv.height = img.naturalHeight;
                cv.getContext('2d').drawImage(img, 0, 0);
                resolve(cv.toDataURL('image/jpeg', 0.92));
            } catch (e) { resolve(null); }
        };
        img.onerror = function(){ resolve(null); };
        img.src = url;
    });
}
var VA_PDFJS_BASE = '../../resource/js/pdfjs/';
var VA_PDFJS_V = <?= json_encode((int)(@filemtime(__DIR__.'/../../resource/js/pdfjs/pdf.min.js') ?: 0)) ?>;
var vaPdfjsLoading = null;
function vaEnsurePdfJs(){
    if (window.pdfjsLib) return Promise.resolve(window.pdfjsLib);
    if (vaPdfjsLoading) return vaPdfjsLoading;
    vaPdfjsLoading = new Promise(function(resolve, reject){
        var s = document.createElement('script');
        s.src = VA_PDFJS_BASE + 'pdf.min.js?v=' + VA_PDFJS_V;
        s.onload = function(){
            if (!window.pdfjsLib) { vaPdfjsLoading = null; reject(new Error('pdfjsLib 未載入')); return; }
            window.pdfjsLib.GlobalWorkerOptions.workerSrc = VA_PDFJS_BASE + 'pdf.worker.min.js?v=' + VA_PDFJS_V;
            resolve(window.pdfjsLib);
        };
        s.onerror = function(){ vaPdfjsLoading = null; reject(new Error('找不到 pdfjs')); };
        document.head.appendChild(s);
    });
    return vaPdfjsLoading;
}
async function vaPdfToDataURLs(url){
    var lib = await vaEnsurePdfJs();
    var doc = await lib.getDocument({url: url, withCredentials: true}).promise;
    var out = [];
    for (var i = 1; i <= doc.numPages; i++) {
        var page = await doc.getPage(i);
        var vp = page.getViewport({scale: 2});
        var cv = document.createElement('canvas'); cv.width = Math.round(vp.width); cv.height = Math.round(vp.height);
        var ctx = cv.getContext('2d'); ctx.fillStyle = '#fff'; ctx.fillRect(0, 0, cv.width, cv.height);
        await page.render({canvasContext: ctx, viewport: vp}).promise;
        out.push(cv.toDataURL('image/jpeg', 0.92));
    }
    return out;
}
/** 把佐證附件清單轉成可直接塞進列印視窗 body 的 HTML（每份附件另起一頁）；圖片/PDF 皆轉成內嵌 dataURL，
 *  不需要列印視窗再等非同步載入完成才能列印。其餘類型（doc/xls等）僅顯示檔名提示，不支援線上預覽。 */
async function vaBuildAttachPrintHTML(attaches){
    var parts = [];
    for (var i = 0; i < (attaches||[]).length; i++) {
        var a = attaches[i];
        if (!a.exists) continue;
        var ext = vaAttachExt(a.original_name);
        var url = API + '?action=attach_open&attach_id=' + a.attach_id;
        var label = '<div style="font-size:11px;color:#666;margin:4px 0 6px;">附件：' + esc(a.original_name||'') + (a.note ? '（' + esc(a.note) + '）' : '') + '</div>';
        var imgStyle = 'max-width:100%;max-height:250mm;display:block;margin:0 auto;';
        if (VA_IMG_EXT.indexOf(ext) !== -1) {
            var durl = await vaImgToDataURL(url);
            parts.push('<div class="attach-page">' + label + (durl
                ? '<img src="'+durl+'" style="'+imgStyle+'">'
                : '<div style="color:#c00;">（圖片載入失敗，請至系統下載查看）</div>') + '</div>');
        } else if (ext === 'pdf') {
            try {
                var pages = await vaPdfToDataURLs(url);
                pages.forEach(function(durl, pi){
                    parts.push('<div class="attach-page">' + (pi===0 ? label : '') + '<img src="'+durl+'" style="'+imgStyle+'"></div>');
                });
            } catch (e) {
                parts.push('<div class="attach-page">' + label + '<div style="color:#c00;">（PDF 轉圖失敗，請至系統下載查看）</div></div>');
            }
        } else {
            parts.push('<div class="attach-page">' + label + '<div style="color:#8a6d45;">（此類型不支援線上預覽，請至系統下載查看原始檔）</div></div>');
        }
    }
    return parts.join('');
}

/** 一次印全部文件＝查核表(直式)＋記錄表(橫式)+附件，合成同一份列印工作、同一個視窗跳出列印。
 *  比照 meeting_record.php 的 doPrintFullRecord()：用具名頁(@page va-landscape)覆蓋成橫式，
 *  套在記錄表+附件的外層 div 上，其餘(查核表兩頁)沿用預設直式；兩種文件的AS編號各自在對應
 *  的@page規則(預設頁/具名頁)各自設定右下角頁尾，不會互相覆蓋。 */
async function printAllDocs(){
    if (!RS) { alert('無資料'); return; }
    var docNo1 = (RS.t&&RS.t.as_doc_no)||(META.as_doc&&META.as_doc.doc_no)||'2-PH-01-02', docNo2 = (RS.t&&RS.t.record_as_doc_no)||(META.record_as_doc&&META.record_as_doc.doc_no)||'2-PH-01-03';
    var page1 = auditFormHTML({maker:RS.t.maker_id+'（'+RS.t.maker_id_no+'）', dateStr:fmtDate(RS.t.audit_date), scores:RS.t.scores, mode:RS.t.audit_mode, cfg:RS.cfg, prodType:RS.t.prod_type, auditorName:RS.t.auditor,
        mgrApproved: !!(RS.t.status==='approved' && RS.t.signed_by_name), mgrName:RS.t.signed_by_name, mgrDate:fmtDate(RS.t.audit_date), mgrIsDeputy:!!RS.t.signed_is_deputy});

    var attachHtml = '';
    if ((RS.attaches||[]).length) {
        try { attachHtml = await vaBuildAttachPrintHTML(RS.attaches); }
        catch (e) { attachHtml = '<div style="color:#c00;">附件載入發生錯誤，部分附件可能未列印，請至系統個別下載查看。</div>'; }
    }
    var asTxt2 = String(docNo2||'').replace(/['\\]/g,'');
    var extraCss = '@page va-landscape{size:A4 landscape;margin:12mm 8mm 16mm;'
        + (asTxt2 ? " @bottom-right{ content:'"+asTxt2+"'; font-size:9pt; color:#333; }" : '')
        + '} .va-landscape-page{page:va-landscape;}';
    // 附件不放進 .va-landscape-page：查核表本來就是預設頁(直式)，附件跟著沿用預設頁直式，
    // 不會被記錄表的橫式具名頁帶偏(每份附件各自的 .attach-page 已強制分頁)。
    var body = page1 + '<div class="va-landscape-page" style="page-break-before:always;">' + recordSheetHTML() + '</div>' + attachHtml;
    openPrintWindow(body, '供應商稽核文件', docNo1, false, true, extraCss);
}

/* ---------- CSV ---------- */
$('#btnCsv').on('click', function(){
    var rows = [['廠商編號','廠商名稱','加工項目','預定月份','稽核狀態','稽核日','綜合合格率','判定','稽核員','報告編號','備註']];
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
    $('#tabAudit').toggle(t==='audit'); $('#tabEval').toggle(t==='eval'); $('#tabRoster').toggle(t==='roster'); $('#tabPlan').toggle(t==='plan');
    if (t==='eval') loadEvVendors($('#evKw').val()||'');   // 切入時重抓納管廠商(納管可能剛變動)
    if (t==='roster') loadRoster();
    if (t==='plan') loadPlan();
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
/* 哪些等級被設為不合格（合格判定唯一依據，率上限只做標紅提醒） */
function failGradeText(s){
    var f=((s&&s.grades)||[]).filter(function(g){ return g.fail; }).map(function(g){ return g.label; });
    return f.length?('　<span style="color:#DD5138;">不合格等級：'+esc(f.join('／'))+'</span>')
                   :'　<span style="color:#c0762c;">尚未設定哪個等級算不合格</span>';
}
/* 數量顯示：0 一律顯示 0（不顯示破折號，使用者要求「有進貨沒不良就是 0%」的同一原則） */
function qty(v){ return (v==null)?'—':Number(v).toLocaleString(); }
/* 門檻超標判定（全域版，畫面各處共用；s=該次查詢回傳的門檻設定） */
function ovNg(v,s){ return v!=null&&s&&v>s.ng_max; }
function ovLt(v,s){ return v!=null&&s&&v>s.late_max; }
function ovSp(v,s){ return s&&s.special_max<100&&v!=null&&v>s.special_max; }
/* 數量欄：顯示數量、滑鼠移上去看該欄佔進貨數的比率；超過門檻仍標紅（比率不再單獨佔一欄） */
function qtyCell(v, r, over, overCls){
    return '<td'+(over?' class="'+(overCls||'af-judge-fail')+'"':'')
        +(r==null?'':' title="佔進貨數 '+r+'%"')+'>'+qty(v)+'</td>';
}
/* 進貨數欄提示：說明這個月是取檢驗量還是回廠量（兩者取大） */
function inTip(d){
    if(d.in_qty==null) return '';
    return ' title="被檢驗量 '+(d.qc_qty||0)+' PCS／回廠量 '+(d.del_qty||0)+' PCS，取較大者"';
}
function loadEval(){
    var mid=$('#evVendor').val(); if(!mid) return;
    NProgress.start();
    $.getJSON(API, {action:'periodic_eval', maker_id_no:mid, year:$('#evYear').val()}, function(res){
        NProgress.done();
        if(!res.ok){ alert(res.error||'查詢失敗'); return; }
        EVAL=res; EVAL_ALL=null;
        $('#evSingle').show(); $('#evCards').empty().hide(); $('#evPager').hide(); $('#evEmpty').hide(); $('#evCsv').show(); $('#evFailBox').hide();
        var s=res.settings;
        $('#evThresh').html('廠商：<b>'+esc(res.maker_name)+'</b>　'+res.year+' 年　提醒門檻：不良率≤'+s.ng_max+'%、遲交率≤'+s.late_max+'%'
            +(s.special_max<100?('、特採率≤'+s.special_max+'%'):'（特採率不判定）')
            +'　約定工作天 '+(res.lead_days==null?s.default_days:res.lead_days)+' 天'
            +(res.lead_days_custom?'<span style="color:#c0762c;">（本廠商專屬設定）</span>':'')+failGradeText(s));
        // 上方：半年/全年 分數與等級
        var sc=function(hf,lab){ if(!hf||hf.score==null) return '<div><span class="s-lab">'+lab+'</span> <span class="s-num" style="font-size:16px;">—</span></div>';
            return '<div><span class="s-lab">'+lab+'</span> <span class="s-num" style="font-size:18px;">'+hf.score+'</span>'
                +'<span class="s-lab"> 分</span> <b style="font-size:16px;color:'+(hf.judge==='fail'?'#DD5138':'#8A5A2B')+';">'+(hf.grade||'—')+'</b></div>'; };
        $('#evScoreTop').html(sc(res.halves[1],'上半年')+sc(res.halves[2],'下半年')+sc(res.full,'全年(總判定)')
            +'<div class="s-lab" style="margin-left:auto;">品質分＝'+(s.q_max||60)+'×(1−(不良＋特採)÷進貨數)、交期分＝'+(s.d_max||40)+'×(1−遲交÷進貨數)；總判定＝上下半年平均分</div>');
        var h='';
        for(var m=1;m<=12;m++){
            var d=res.months[m];
            h+='<tr><td>'+m+'月</td>';
            h+='<td'+inTip(d)+'>'+qty(d.in_qty)+'</td>';
            h+=qtyCell(d.ng, d.ng_rate, ovNg(d.ng_rate,s));
            h+=qtyCell(d.special, d.special_rate, ovSp(d.special_rate,s));
            h+='<td class="ev-sc">—</td>';                       // 分數只在半年/全年列計算
            h+=qtyCell(d.late, d.late_rate, ovLt(d.late_rate,s));
            h+='<td class="ev-sc">—</td><td>—</td></tr>';
            if(m===6) h+=halfRow(res.halves[1],'上半年',s);
            if(m===12) h+=halfRow(res.halves[2],'下半年',s)+halfRow(res.full,'全年（總判定）',s,true);
        }
        $('#evBody').html(h);
    }).fail(function(x){ NProgress.done(); alert('查詢失敗：'+(x.responseJSON&&x.responseJSON.error||x.status)); });
}
/* 半年/全年彙總列：品質分(60)＋交期分(40)＋判定(等級 + 門檻合格與否) */
function halfRow(hf,label,s,isFull){
    if(!hf) return '';
    var judge=hf.judge?(hf.judge==='pass'?'<span class="af-judge-pass">合格</span>':'<span class="af-judge-fail">不合格</span>'):'—';
    var grade=(hf.score==null)?'—':('<b style="font-size:14px;color:'+(hf.judge==='fail'?'#DD5138':'#8A5A2B')+';">'+esc(hf.grade||'—')+'</b> <span style="font-weight:normal;">'+hf.score+'分</span>');
    var num=function(v){ return v==null?'—':v; };
    return '<tr style="background:'+(isFull?'#F6E3C5':'#FDF3E0')+';font-weight:bold;"><td>'+label+'</td>'
        +'<td'+inTip(hf)+'>'+qty(hf.in_qty)+'</td>'
        +qtyCell(hf.ng, hf.ng_rate, ovNg(hf.ng_rate,s))+qtyCell(hf.special, hf.special_rate, ovSp(hf.special_rate,s))
        +'<td class="ev-sc">'+num(hf.q_score)+'</td>'
        +qtyCell(hf.late, hf.late_rate, ovLt(hf.late_rate,s))+'<td class="ev-sc">'+num(hf.d_score)+'</td>'
        +'<td>'+grade+'　'+judge+'</td></tr>';
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
        $('#evThresh').html(res.year+' 年　全部納管廠商（'+res.vendors.length+' 家有資料，已略過無資料者）　提醒門檻：不良率≤'+s.ng_max+'%、遲交率≤'+s.late_max+'%'
            +(s.special_max<100?('、特採率≤'+s.special_max+'%'):'（特採率不判定）')+'　約定工作天 '+s.default_days+' 天（部分廠商可個別設定）'+failGradeText(s));
        $('#evSingle').hide(); $('#evEmpty').hide(); $('#evCsv').hide(); $('#evFailBox').show(); $('#evCards').css('display','grid');
        evPage=1; renderEvalCards();
    }).fail(function(x){ NProgress.done(); alert('載入失敗：'+(x.responseJSON&&x.responseJSON.error||x.status)); });
});
$('#evFailOnly').on('change', function(){ evPage=1; renderEvalCards(); });
/* ── 客戶端分頁列（每頁10筆，只渲染當頁避免一次畫太多列拖慢頁面） ── */
var VA_PER = 10;
function vaBuildPager(sel, total, page, onGo){
    var pages=Math.max(1, Math.ceil(total/VA_PER));
    if(page>pages) page=pages; if(page<1) page=1;
    var $p=$(sel);
    if(total<=VA_PER){ $p.hide().empty(); return page; }
    var from=(page-1)*VA_PER+1, to=Math.min(page*VA_PER,total);
    var h='<span class="pg-info">第 '+from+'–'+to+' 筆／共 '+total+' 筆（第 '+page+'／'+pages+' 頁）</span>';
    h+='<button '+(page<=1?'disabled':'')+' data-go="'+(page-1)+'"><i class="fa fa-angle-left"></i></button>';
    var st=Math.max(1,page-2), en=Math.min(pages,page+2);
    if(st>1){ h+='<button data-go="1">1</button>'; if(st>2) h+='<span style="color:#c9b28c;">…</span>'; }
    for(var i=st;i<=en;i++) h+='<button class="'+(i===page?'cur':'')+'" data-go="'+i+'">'+i+'</button>';
    if(en<pages){ if(en<pages-1) h+='<span style="color:#c9b28c;">…</span>'; h+='<button data-go="'+pages+'">'+pages+'</button>'; }
    h+='<button '+(page>=pages?'disabled':'')+' data-go="'+(page+1)+'"><i class="fa fa-angle-right"></i></button>';
    $p.html(h).show();
    $p.off('click').on('click','button[data-go]',function(){ onGo(+$(this).attr('data-go')); });
    return page;
}
var evPage=1;
function renderEvalCards(){
    if(!EVAL_ALL) return;
    var s=EVAL_ALL.settings, failOnly=$('#evFailOnly').is(':checked');
    var list=EVAL_ALL.vendors.filter(function(v){ return !failOnly || v.fail; });
    evPage=vaBuildPager('#evPager', list.length, evPage, function(p){ evPage=p; renderEvalCards(); var el=document.getElementById('evCards'); if(el) el.scrollIntoView({behavior:'smooth',block:'start'}); });
    var pageList=list.slice((evPage-1)*VA_PER, evPage*VA_PER);
    var html='';
    pageList.forEach(function(v){
        // 標題徽章一律跟隨「總判定」，與表格最後一列一致（避免兩個口徑矛盾）
        var g=v.full&&v.full.grade?('總判定 <b style="color:'+(v.fail?'#DD5138':'#8A5A2B')+';">'+esc(v.full.grade)+'</b>（'+(v.full.score==null?'—':v.full.score)+'分）'
                +(v.fail?' <span class="af-judge-fail">不合格</span>':' <span class="af-judge-pass">合格</span>')):'';
        html+='<div class="ev-card"><div class="h"><span>'+esc(v.maker_name)+'（'+esc(v.maker_id_no)+'）</span><span>'+g+'</span></div>';
        html+='<table class="ev-mini"><thead>'
            +'<tr><th rowspan="2">月</th><th rowspan="2" title="數量(PCS)：同月取被檢驗量與回廠量較大者">進貨數</th>'
            +'<th colspan="3">品質（進料檢驗）</th><th colspan="2">交期</th><th rowspan="2">判定</th></tr>'
            +'<tr><th>不良數</th><th>特採數</th><th>品質分</th><th>遲交數</th><th>交期分</th></tr></thead><tbody>';
        for(var m=1;m<=12;m++){ var d=v.months[m];
            html+='<tr><td>'+m+'</td><td'+inTip(d)+'>'+qty(d.in_qty)+'</td>'
                +qtyCell(d.ng, d.ng_rate, ovNg(d.ng_rate,s), 'over')
                +qtyCell(d.special, d.special_rate, ovSp(d.special_rate,s), 'over')
                +'<td class="ev-sc">—</td>'
                +qtyCell(d.late, d.late_rate, ovLt(d.late_rate,s), 'over')
                +'<td class="ev-sc">—</td><td>—</td></tr>';
            if(m===6) html+=miniHalf(v.halves[1],'上半年');
            if(m===12) html+=miniHalf(v.halves[2],'下半年')+miniHalf(v.full,'全年',true);
        }
        html+='</tbody></table></div>';
    });
    $('#evCards').html(html||'<div style="padding:14px;color:#8a6d45;grid-column:1/-1;">'+(failOnly?'無不合格廠商':'無資料')+'</div>');
}
function miniHalf(hf,label,isFull){
    if(!hf) return '';
    var num=function(x){ return x==null?'—':x; };
    var g=(hf.score==null)?'—':('<b style="color:'+(hf.judge==='fail'?'#DD5138':'#8A5A2B')+';">'+esc(hf.grade||'—')+'</b> '+hf.score+'分');
    return '<tr class="'+(isFull?'full':'half')+'"><td>'+label+'</td><td'+inTip(hf)+'>'+qty(hf.in_qty)+'</td>'
        +qtyCell(hf.ng, hf.ng_rate, false)+qtyCell(hf.special, hf.special_rate, false)+'<td class="ev-sc">'+num(hf.q_score)+'</td>'
        +qtyCell(hf.late, hf.late_rate, false)+'<td class="ev-sc">'+num(hf.d_score)+'</td><td>'+g+'</td></tr>';
}
/* 全部評核 橫式列印：一頁6間(3欄×2列)，頁首公司名+文件名，右上頁碼，右下AS編號 */
function evCardPrintHTML(v){
    var s=EVAL_ALL.settings;
    var oNg=function(x){return x!=null&&x>s.ng_max?' class="over"':'';}, oLt=function(x){return x!=null&&x>s.late_max?' class="over"':'';}, oSp=function(x){return s.special_max<100&&x!=null&&x>s.special_max?' class="over"':'';};
    var num=function(x){ return x==null?'—':x; };
    var sumRow=function(hf,label,cls){
        if(!hf) return '';
        var jj=hf.judge?(hf.judge==='pass'?'合格':'不合格'):'—';
        var g=(hf.score==null)?'—':((hf.grade||'—')+' '+hf.score+'分');
        return '<tr class="'+cls+'"><td>'+label+'</td><td>'+qty(hf.in_qty)+'</td><td>'+qty(hf.ng)+'</td><td>'+qty(hf.special)+'</td><td>'+num(hf.q_score)+'</td>'
            +'<td>'+qty(hf.late)+'</td><td>'+num(hf.d_score)+'</td><td>'+g+' '+jj+'</td></tr>';
    };
    var h='<div class="pc"><div class="pc-h">'+esc(v.maker_name)+'（'+esc(v.maker_id_no)+'）'
        +(v.fail?'<span class="jf">不合格</span>':'<span class="jp">合格</span>')+'</div>'
        +'<table class="pm"><thead>'
        +'<tr><th rowspan="2">月</th><th rowspan="2">進貨數</th><th colspan="3">品質</th><th colspan="2">交期</th><th rowspan="2">判定</th></tr>'
        +'<tr><th>不良數</th><th>特採數</th><th>品質分</th><th>遲交數</th><th>交期分</th></tr></thead><tbody>';
    for(var m=1;m<=12;m++){ var d=v.months[m];
        h+='<tr><td>'+m+'</td><td>'+qty(d.in_qty)+'</td>'
            +'<td'+oNg(d.ng_rate)+'>'+qty(d.ng)+'</td><td'+oSp(d.special_rate)+'>'+qty(d.special)+'</td><td>—</td>'
            +'<td'+oLt(d.late_rate)+'>'+qty(d.late)+'</td><td>—</td></tr>';
        if(m===6)  h+=sumRow(v.halves[1],'上半','ph');
        if(m===12) h+=sumRow(v.halves[2],'下半','ph')+sumRow(v.full,'總判定','pf');
    }
    return h+'</tbody></table></div>';
}
function printEvalAll(){
    if(!EVAL_ALL) return;
    var list=EVAL_ALL.vendors.filter(function(v){ return !$('#evFailOnly').is(':checked')||v.fail; });
    if(!list.length){ alert('無資料可列印'); return; }
    var doc=EVAL_ALL.eval_as_doc||META.eval_as_doc, docName=(doc&&doc.doc_name)||'供應商定期評核表', docNo=(doc&&doc.doc_no)||'2-PH-01-05';
    var per=6, pages=Math.ceil(list.length/per), body='';
    for(var p=0;p<pages;p++){
        var cards='';
        for(var k=p*per;k<Math.min((p+1)*per,list.length);k++) cards+=evCardPrintHTML(list[k]);
        body+='<div class="pg"><div class="pg-h"><div class="ttl"><div class="co">'+esc(META.company_name||'')+'</div><div class="dn">'+esc(docName)+'</div></div>'
            +'<div class="yr">'+EVAL_ALL.year+' 年</div></div>'
            +'<div class="pg-cards">'+cards+'</div></div>';
    }
    var asTxt = String(docNo||'').replace(/['\\]/g,'');
    var w=window.open('','_blank'); if(!w){alert('請允許彈出視窗');return;}
    var css='@page{size:A4 landscape;margin:8mm 8mm 14mm;'
        +" @bottom-left{ content:'第 ' counter(page) ' 頁／共 ' counter(pages) ' 頁'; font-size:9pt; color:#333; }"
        +(asTxt?" @bottom-right{ content:'"+asTxt+"'; font-size:9pt; color:#333; }":'')
        +'} body{font-family:"Microsoft JhengHei","微軟正黑體",sans-serif;color:#000;margin:0;}'
        +'.pg{page-break-after:always;padding:2mm;} .pg:last-child{page-break-after:auto;}'
        +'.pg-h{display:flex;justify-content:space-between;align-items:flex-end;border-bottom:2px solid #333;padding-bottom:3px;margin-bottom:5px;}'
        +'.pg-h .co{font-size:22px;font-weight:bold;letter-spacing:1px;} .pg-h .dn{font-size:16px;font-weight:bold;margin-top:2px;} .pg-h .yr{font-size:13px;font-weight:bold;}'
        +'.pg-cards{display:grid;grid-template-columns:1fr 1fr 1fr;gap:5px;}'
        +'.pc{border:1px solid #333;border-radius:3px;padding:3px 4px;break-inside:avoid;}'
        +'.pc-h{font-weight:bold;font-size:12px;margin-bottom:2px;display:flex;justify-content:space-between;}'
        +'.pc .jf{color:#c00;} .pc .jp{color:#282;}'
        +'table.pm{width:100%;border-collapse:collapse;font-size:9px;table-layout:fixed;} table.pm th,table.pm td{border:1px solid #999;padding:0 1px;text-align:center;word-break:break-all;}'
        +'table.pm thead th{background:#eee;} table.pm tr.ph td{background:#f3ead6;font-weight:bold;} table.pm tr.pf td{background:#e8d6b6;font-weight:bold;} table.pm td.over{color:#c00;font-weight:bold;}';
    w.document.write('<html><head><meta charset="utf-8"><title>供應商定期評核</title><style>'+css+'</style></head><body>'+body
        +'<scr'+'ipt>window.onload=function(){setTimeout(function(){window.print();},200);};</scr'+'ipt></body></html>');
    w.document.close();
}
$('#evPrint').on('click', function(){ if(EVAL_ALL) printEvalAll(); else window.print(); });
function gradeAddRow(label, min, fail){
    $('#stGrades').append('<div class="gr-row" style="display:flex;gap:6px;align-items:center;margin-bottom:4px;">'
        +'等級 <input type="text" class="gr-label" maxlength="6" value="'+esc(label||'')+'" style="width:60px;">'
        +' 分數 ≥ <input type="number" class="gr-min" step="1" min="0" max="100" value="'+(min==null?'':min)+'" style="width:70px;">'
        +' <label style="margin:0;font-weight:normal;color:#DD5138;white-space:nowrap;"><input type="checkbox" class="gr-fail"'+(fail?' checked':'')+'> 視為不合格</label>'
        +' <span class="af-del" style="color:#DD5138;cursor:pointer;" onclick="$(this).closest(\'.gr-row\').remove()"><i class="fa fa-times"></i></span></div>');
}
/* ---- 特定廠商的約定工作天（廠商層級覆寫；未設定＝用預設） ---- */
var LEAD_VENDORS = null;   // 廠商候選清單(納管廠商)，只抓一次
function leadVendorOptions(sel){
    var h='<option value="">選廠商…</option>';
    (LEAD_VENDORS||[]).forEach(function(v){
        h+='<option value="'+esc(v.maker_id_no)+'"'+(sel===v.maker_id_no?' selected':'')+'>'+esc(v.maker_id||v.maker_id_no)+'（'+esc(v.maker_id_no)+'）</option>';
    });
    return h;
}
function leadAddRow(mid, days){
    $('#stLeadRows').append('<div class="lead-row" style="display:flex;gap:6px;align-items:center;margin-bottom:4px;">'
        +'<select class="lead-mid" style="flex:1;min-width:150px;">'+leadVendorOptions(mid)+'</select>'
        +'<input type="number" class="lead-days" step="1" min="0" value="'+(days==null||days===''?'':days)+'" style="width:70px;"> 工作天'
        +' <span class="af-del" style="color:#DD5138;cursor:pointer;" onclick="$(this).closest(\'.lead-row\').remove()"><i class="fa fa-times"></i></span></div>');
}
function leadLoadRows(){
    $('#stLeadRows').html('<div style="font-size:12px;color:#8a6d45;">載入中…</div>');
    var render=function(rows){
        $('#stLeadRows').empty();
        (rows||[]).forEach(function(r){ leadAddRow(r.maker_id_no, r.audit_lead_days); });
        if(!rows||!rows.length) $('#stLeadRows').html('<div style="font-size:12px;color:#8a6d45;">尚未設定任何廠商（全部用上面的預設天數）</div>');
    };
    var go=function(rows){ if(LEAD_VENDORS) render(rows); else
        $.getJSON(API,{action:'eval_vendors'},function(res){ LEAD_VENDORS=(res&&res.vendors)||[]; render(rows); }); };
    $.getJSON(API,{action:'eval_lead_days_list'},function(res){ go(res&&res.ok?res.rows:[]); })
      .fail(function(){ go([]); });
}
/* 下拉預設只有納管廠商；打關鍵字可查全廠商後併進候選清單（沿用本頁 #evKw 既有做法，
   本頁不在 eg_input_rules.js 覆蓋範圍內故不用 data-eg-filter） */
function leadSearchVendors(){
    var kw=$.trim($('#stLeadKw').val());
    $.getJSON(API,{action:'eval_vendors',kw:kw},function(res){
        if(!res.ok) return;
        var have={}; (LEAD_VENDORS||[]).forEach(function(v){ have[v.maker_id_no]=1; });
        (res.vendors||[]).forEach(function(v){ if(!have[v.maker_id_no]){ LEAD_VENDORS.push(v); have[v.maker_id_no]=1; } });
        LEAD_VENDORS.sort(function(a,b){ return String(a.maker_id||'').localeCompare(String(b.maker_id||'')); });
        // 重建所有列的選項但保留各列目前已選的廠商
        $('#stLeadRows .lead-row').each(function(){ var $s=$(this).find('.lead-mid'), cur=$s.val(); $s.html(leadVendorOptions(cur)); });
        if(kw) alert('已把符合「'+kw+'」的 '+(res.vendors||[]).length+' 家併入下拉候選');
    });
}
$('#stLeadKwGo').on('click', leadSearchVendors);
$('#stLeadKw').on('keydown', function(e){ if(e.key==='Enter'){ e.preventDefault(); leadSearchVendors(); } });
$('#evSet').on('click', function(){
    var s=(EVAL&&EVAL.settings)||META.eval_settings||{ng_max:5,late_max:30,special_max:100,default_days:7};
    $('#stLeadKw').val(''); leadLoadRows();
    $('#stNgMax').val(s.ng_max); $('#stLateMax').val(s.late_max); $('#stSpMax').val(s.special_max); $('#stDays').val(s.default_days);
    $('#stGrades').empty(); ((s.grades&&s.grades.length)?s.grades:[{min:90,label:'A'},{min:80,label:'B'},{min:70,label:'C'},{min:0,label:'D',fail:1}]).forEach(function(g){ gradeAddRow(g.label,g.min,g.fail); });
    // 定期評核門檻依範疇各自獨立，標題標示目前正在編輯哪一份
    $('#evSetMask .m-head span:first').text('定期評核門檻設定（'+scopeLabel(CUR_SCOPE)+'）');
    openMask('evSetMask');
});
function submitEvSet(){
    var grades=[]; $('#stGrades .gr-row').each(function(){ var l=$.trim($(this).find('.gr-label').val()), mn=$(this).find('.gr-min').val();
        if(l!=='') grades.push({label:l, min:mn===''?0:+mn, fail:$(this).find('.gr-fail').is(':checked')?1:0}); });
    if(grades.length && !grades.some(function(g){return g.fail;})
       && !confirm('沒有勾選任何「視為不合格」的等級，所有廠商都會判合格。確定要這樣存？')) return;
    // 廠商專屬工作天：前端先驗（選了廠商就一定要填天數、同一廠商不可重複），後端 API 再驗一次
    var leads=[], seen={}, bad=null;
    $('#stLeadRows .lead-row').each(function(){
        var mid=$(this).find('.lead-mid').val(), d=$.trim($(this).find('.lead-days').val());
        if(!mid && d==='') return;                       // 空白列直接略過
        if(!mid){ bad='「特定廠商的約定工作天」有一列沒選廠商'; return; }
        if(d===''||+d<0){ bad='廠商「'+mid+'」的約定工作天沒填或小於 0'; return; }
        if(seen[mid]){ bad='廠商「'+mid+'」重複設定了兩次'; return; }
        seen[mid]=1; leads.push({maker_id_no:mid, days:+d});
    });
    if(bad){ alert(bad); return; }
    $.post(API, {action:'save_eval_settings', ng_max:$('#stNgMax').val(), late_max:$('#stLateMax').val(),
        special_max:$('#stSpMax').val(), default_days:$('#stDays').val(), grades:JSON.stringify(grades)}, function(res){
        if(!res.ok){ alert(res.error||'儲存失敗'); return; }
        META.eval_settings=res.settings;
        $.post(API, {action:'eval_lead_days_save', rows:JSON.stringify(leads)}, function(r2){
            if(!r2.ok){ alert('門檻已存，但廠商專屬工作天儲存失敗：'+(r2.error||'')); return; }
            closeMask('evSetMask');
            if(EVAL_ALL) $('#evAll').click(); else if($('#evVendor').val()) loadEval();
        }, 'json');
    }, 'json');
}
$('#evCsv').on('click', function(){
    if(!EVAL) return;
    var rows=[['月份','進貨數(PCS)','被檢驗量','回廠量','不良數','不良率','特採率','品質分(60)','遲交數','遲交率','交期分(40)','總分','等級','門檻判定']];
    for(var m=1;m<=12;m++){ var d=EVAL.months[m];
        rows.push([m+'月',d.in_qty,d.qc_qty,d.del_qty,d.ng,rate(d.ng_rate),rate(d.special_rate),'',d.late,rate(d.late_rate),'','','','']); }
    [['上半年',EVAL.halves[1]],['下半年',EVAL.halves[2]],['全年（總判定）',EVAL.full]].forEach(function(p){ var hf=p[1]; if(!hf) return;
        rows.push([p[0],hf.in_qty,hf.qc_qty,hf.del_qty,hf.ng,rate(hf.ng_rate),rate(hf.special_rate),(hf.q_score==null?'':hf.q_score),
                   hf.late,rate(hf.late_rate),(hf.d_score==null?'':hf.d_score),
                   (hf.score==null?'':hf.score),(hf.grade||''),(hf.judge?(hf.judge==='pass'?'合格':'不合格'):'')]); });
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

/* ---------- 合格供應商清冊 ---------- */
var ROSTER=null;
$('#rsYear').on('change', loadRoster);
function loadRoster(){
    NProgress.start();
    var rsYr = $('#rsYear').val() || new Date().getFullYear();
    $.getJSON(API, {action:'roster_list', year:rsYr}, function(res){
        NProgress.done();
        if(!res.ok){ alert(res.error||'載入失敗'); return; }
        ROSTER=res;
        $('#rsRemind').html(res.year+' 年評核　共 <b>'+res.rows.length+'</b> 家（納管 '+res.rows.filter(function(r){return r.is_managed;}).length+' ＋ 手動列入 '+res.rows.filter(function(r){return !r.is_managed&&r.in_roster;}).length+'）　建議等級來自定期評核全年成績');
        rsPage=1; renderRoster();
    }).fail(function(x){ NProgress.done(); alert('載入失敗：'+(x.responseJSON&&x.responseJSON.error||x.status)); });
}
var rsPage=1;
function renderRoster(){
    var all=ROSTER.rows;
    rsPage=vaBuildPager('#rsPager', all.length, rsPage, function(p){ rsPage=p; renderRoster(); var el=document.getElementById('rosterTable'); if(el) el.scrollIntoView({behavior:'smooth',block:'start'}); });
    var rows=all.slice((rsPage-1)*VA_PER, rsPage*VA_PER);
    var h='';
    rows.forEach(function(r){
        var over=(r.roster_grade!=null&&r.roster_grade!=='');
        h+='<tr><td><input type="checkbox" class="rs-ck" value="'+esc(r.maker_id_no)+'"></td>';
        h+='<td class="t-left">'+esc(r.main_cat_name||'—')+'</td>';
        h+='<td class="t-left"><b>'+esc(r.maker_id||'')+'</b><div style="font-size:11px;color:#8a6d45;">'+esc(r.maker_id_no)+'</div></td>';
        h+='<td>'+(r.suggest_grade?r.suggest_grade+'（'+(r.suggest_score==null?'—':r.suggest_score)+'）':'—')+'</td>';
        h+='<td><b style="color:#8A5A2B;">'+esc(r.final_grade||'—')+'</b>'+(over?' <span style="font-size:10px;color:#c0762c;">手動</span>':'')+'</td>';
        h+='<td>'+rsNoteCell(r)+'</td>';
        h+='<td>'+(r.is_managed?'<span class="st-pill st-done">納管</span>':'<span class="st-pill st-todo">手動列入</span>')+'</td>';
        h+='<td>'+((!r.is_managed&&r.in_roster&&PERMS.canEdit)?'<span class="va-op" style="color:#DD5138;" onclick="rsRemove(\''+esc(r.maker_id_no)+'\')"><i class="fa fa-times"></i>移出</span>':'—')+'</td></tr>';
    });
    $('#rosterBody').html(h||'<tr><td colspan="8" style="padding:16px;color:#8a6d45;">清冊尚無廠商，請設定納管或「加入清冊廠商」</td></tr>');
    $('#rsAllCk').prop('checked',false);
}
/* 清冊備註：未達標(等級A/B/C以外或無等級)預設「老闆指定」，可改「客戶指定」；無權限者只顯示文字 */
function rsNoteOptions(){ return (ROSTER&&ROSTER.note_options)||{boss:'老闆指定',customer:'客戶指定'}; }
function rsNoteText(v){ var o=rsNoteOptions(); return v&&o[v]?o[v]:''; }
function rsNoteCell(r){
    var cur=r.note||'', o=rsNoteOptions();
    if(!PERMS.canEdit) return cur?('<span class="st-pill st-todo">'+esc(rsNoteText(cur))+'</span>'):'—';
    var h='<select class="rs-note" data-mid="'+esc(r.maker_id_no)+'" style="font-size:12px;padding:1px 3px;"><option value="">（無）</option>';
    Object.keys(o).forEach(function(k){ h+='<option value="'+esc(k)+'"'+(cur===k?' selected':'')+'>'+esc(o[k])+'</option>'; });
    return h+'</select>'+(r.substandard&&!r.roster_note?'<div style="font-size:10px;color:#c0762c;">未達標預設</div>':'');
}
/* 選了就存（點開即刷新鐵則：存完重載清冊，確保等級/備註與他人改動同步） */
$('#rosterBody').on('change','select.rs-note',function(){
    var mid=$(this).data('mid'), v=$(this).val();
    $.post(API,{action:'roster_set_note',maker_ids:mid,note:v},function(res){
        if(!res.ok){ alert(res.error||'儲存失敗'); }
        loadRoster();
    },'json');
});
$('#rsAllCk').on('change', function(){ $('#rosterBody input.rs-ck').prop('checked', this.checked); });
function rsChecked(){ return $('#rosterBody input.rs-ck:checked').map(function(){return this.value;}).get(); }
$('#rsBatchGrade').on('click', function(){
    var ids=rsChecked(); if(!ids.length){ alert('請勾選廠商'); return; }
    var g=prompt('設定採用等級（例：'+(ROSTER.settings.grades||[]).map(function(x){return x.label;}).join('/')+'）：');
    if(g===null) return; g=$.trim(g); if(!g){ alert('請輸入等級'); return; }
    $.post(API,{action:'roster_set_grade',maker_ids:ids.join(','),grade:g},function(res){ if(!res.ok){alert(res.error||'失敗');return;} loadRoster(); },'json');
});
$('#rsClearGrade').on('click', function(){
    var ids=rsChecked(); if(!ids.length){ alert('請勾選廠商'); return; }
    $.post(API,{action:'roster_set_grade',maker_ids:ids.join(','),grade:''},function(res){ if(!res.ok){alert(res.error||'失敗');return;} loadRoster(); },'json');
});
function rsRemove(mid){
    if(!confirm('將此廠商移出合格清冊？（納管廠商會保留）')) return;
    $.post(API,{action:'roster_remove',maker_id_no:mid},function(res){ if(!res.ok){alert(res.error||'失敗');return;} loadRoster(); },'json');
}
$('#rsAdd').on('click', function(){ $('#rsAddBody').html('<div class="empty">輸入關鍵字查詢</div>'); $('#rsAddKw').val(''); $('#rsAddCnt').text(''); openMask('rsAddMask'); });
$('#rsAddKw').on('keydown', function(e){ if(e.key==='Enter') rsAddSearch(); });
function rsAddSearch(){
    var kw=$.trim($('#rsAddKw').val()); if(!kw){ alert('請輸入關鍵字'); return; }
    $.getJSON(API,{action:'eval_vendors',kw:kw},function(res){
        if(!res.ok) return;
        var have={}; (ROSTER?ROSTER.rows:[]).forEach(function(r){ have[r.maker_id_no]=1; });
        var list=(res.vendors||[]).filter(function(v){ return !have[v.maker_id_no]; });
        $('#rsAddCnt').text('符合 '+list.length+' 家（未在清冊）');
        var h=''; list.forEach(function(v){ h+='<label class="pk-item"><input type="checkbox" class="rsa-ck" value="'+esc(v.maker_id_no)+'"><span class="no">'+esc(v.maker_id_no)+'</span><span class="nm">'+esc(v.maker_id||'')+'</span></label>'; });
        $('#rsAddBody').html(h||'<div class="empty">無符合或皆已在清冊</div>'); $('#rsAddAll').prop('checked',false);
    });
}
$('#rsAddAll').on('change', function(){ $('#rsAddBody .rsa-ck').prop('checked', this.checked); });
function rsAddSelected(){
    var ids=$('#rsAddBody .rsa-ck:checked').map(function(){return this.value;}).get();
    if(!ids.length){ alert('請勾選廠商'); return; }
    $.post(API,{action:'roster_add',maker_ids:ids.join(',')},function(res){ if(!res.ok){alert(res.error||'失敗');return;} closeMask('rsAddMask'); loadRoster(); },'json');
}
$('#rsCsvBtn').on('click', function(){
    if(!ROSTER) return;
    var rows=[['項目','廠商ID','廠商名稱','建議等級','採用等級','備註','類型']];
    ROSTER.rows.forEach(function(r){ rows.push([r.main_cat_name||'',r.maker_id_no,r.maker_id||'',(r.suggest_grade||'')+(r.suggest_score==null?'':'('+r.suggest_score+')'),r.final_grade||'',rsNoteText(r.note),r.is_managed?'納管':'手動列入']); });
    var csv='﻿'+rows.map(function(l){return l.map(function(v){return '"'+String(v==null?'':v).replace(/"/g,'""')+'"';}).join(',');}).join('\r\n');
    var a=document.createElement('a'); a.href=URL.createObjectURL(new Blob([csv],{type:'text/csv;charset=utf-8;'})); a.download='合格供應商清冊_'+ROSTER.year+'_'+scopeLabel(CUR_SCOPE)+'.csv'; a.click();
});
/** 合格供應商清冊三欄簽章：製表＝目前登入者，審核＝eg_resolve_supervisor()解析的部門上一階主管
 *  (若解不到就退回製表人本人)，核准＝全站共用「最高核准人員」(org_role_lib top_approver)；
 *  三欄一律蓋自動圖章，日期本清冊沒有單一業務日期可用，改成每次列印當下手動輸入一次套用三欄
 *  （使用者2026-08-10明確要求）。 */
$('#rsPrintBtn').on('click', function(){
    if(!ROSTER||!ROSTER.rows.length){ alert('清冊無資料'); return; }
    var d = prompt('請輸入本次列印的簽章日期(YYYY-MM-DD)，留空＝今天：', META.today);
    if (d === null) return;
    d = $.trim(d) || META.today;
    $.getJSON(API, {action:'roster_sign_info'}, function(res){
        if (!res.ok){ alert(res.error||'載入簽章人失敗'); return; }
        var dateStr = fmtDate(d);
        var makeStamp = vaStampHtml(CUR_USER_NAME||'', dateStr);
        var reviewName = res.reviewer_name || CUR_USER_NAME || '';
        var reviewStamp = reviewName ? vaStampHtml(reviewName, dateStr) : '__________________';
        var approveStamp = res.approver_name ? vaStampHtml(res.approver_name, dateStr) : '__________________';
        var doc=META.roster_as_doc, docName=(doc&&doc.doc_name)||'合格供應商清冊', docNo=(doc&&doc.doc_no)||'2-PH-01-04';
        var head='<div style="text-align:center;">'
            +'<div style="font-size:24px;font-weight:bold;letter-spacing:1px;">'+esc(META.company_name||'')+'</div>'
            +'<div style="font-size:18px;font-weight:bold;margin-top:3px;">'+esc(docName)+'</div></div>'
            +'<div style="text-align:left;font-size:14px;font-weight:bold;margin-top:8px;">'+(ROSTER.year||new Date().getFullYear())+' 年（'+scopeLabel(CUR_SCOPE)+'）</div>';
        var rows='<table class="pf" style="table-layout:fixed;margin-top:2px;"><colgroup><col style="width:5%;"><col style="width:39%;">'
            +'<col style="width:24%;"><col style="width:14%;"><col style="width:18%;"></colgroup>'
            +'<thead><tr><th>序</th><th style="text-align:left;">項目</th><th>廠商</th><th>評核等級</th><th>備註</th></tr></thead><tbody>';
        ROSTER.rows.forEach(function(r,i){ rows+='<tr><td>'+(i+1)+'</td><td class="q">'+esc(r.main_cat_name||'')+'</td>'
            +'<td class="q"><b>'+esc(r.maker_id||'')+'</b><div style="font-size:11px;color:#555;">'+esc(r.maker_id_no)+'</div></td>'
            +'<td>'+esc(r.final_grade||'—')+'</td><td>'+esc(rsNoteText(r.note))+'</td></tr>'; });
        rows+='</tbody></table>';
        var sign='<table class="pf-sign" style="page-break-inside:avoid;"><tr>'
            +'<td style="width:33%;"><div style="font-size:11px;color:#555;">製表</div><div style="margin-top:2px;min-height:91px;">'+makeStamp+'</div></td>'
            +'<td style="width:34%;"><div style="font-size:11px;color:#555;">審核</div><div style="margin-top:2px;min-height:91px;">'+reviewStamp+'</div></td>'
            +'<td style="width:33%;"><div style="font-size:11px;color:#555;">核准</div><div style="margin-top:2px;min-height:91px;">'+approveStamp+'</div></td>'
            +'</tr></table>';
        openPrintWindow(head+rows+sign, '合格供應商清冊', docNo);
    });
});

/* ---------- 供應商稽核計劃(2-PH-01-06,年度版) ---------- */
var PLANDATA = null;
function loadPlan(cb){
    var year = $('#planYear').val() || META.cur_year;
    NProgress.start();
    $.getJSON(API, {action:'plan_data', year:year}, function(res){
        NProgress.done();
        if(!res.ok){ alert(res.error||'載入失敗'); return; }
        PLANDATA = res;
        renderPlan(res);
        if (cb) cb(res);
    }).fail(function(x){ NProgress.done(); alert('載入失敗：'+(x.responseJSON&&x.responseJSON.error||x.status)); });
}
function renderPlan(res){
    var head = '<th style="text-align:left;">供應商名稱</th>';
    for (var m=1;m<=12;m++) head += '<th>'+m+'月</th>';
    head += '<th>備註</th>';
    $('#planHeadRow').html(head);
    var body='';
    (res.rows||[]).forEach(function(r){
        body += '<tr><td class="t-left"><b>'+esc(r.maker_id||'')+'</b></td>';
        for (var m=1;m<=12;m++) body += '<td>'+(r.months&&r.months[m]?'V':'')+'</td>';
        body += '<td class="t-left">'+esc(r.sub_cat_names||'')+'</td></tr>';
    });
    $('#planBody').html(body||'<tr><td colspan="14" style="padding:16px;color:#8a6d45;">本年度尚無稽核計畫對象</td></tr>');
    var stMap={pending:'待核准',approved:'已核准',rejected:'已退回(修改後可重新送出)'};
    var lockInfo = res.lock ? ('送出日期：'+fmtDate(res.lock.submit_date)+'　狀態：'+(stMap[res.lock.status]||res.lock.status)
        + (res.lock.status==='approved'&&res.lock.approved_by_name ? '（核准：'+esc(res.lock.approved_by_name)+' '+fmtDate(res.lock.approved_date||res.lock.approved_at)+'）' : '')
        + (res.lock.status==='pending'&&res.approver_names&&res.approver_names.length ? '（可核准：'+esc(res.approver_names.join('、'))+'）' : '')) : '尚未送出（可持續增列對象）';
    $('#planLockInfo').text(lockInfo);
    $('#planSubmitBtn').toggle(!!(PERMS.canEdit && !res.locked));
    $('#planDecideBtn').remove();
    $('#planCancelBtn').remove();
    if (res.lock && res.lock.status==='pending' && res.can_decide) {
        // can_decide後端算好(合格核准人/送出人本人/管理者)才顯示，跟plan_decide的權限判斷一致，
        // 完全無關的人看不到這顆按鈕(使用者2026-08-10明確要求要「完全鎖住」，不是只靠後端擋)
        $('<button id="planDecideBtn" class="b-att2" style="margin-left:8px;">核准/退回</button>').on('click', openPlanDecideMask).insertAfter('#planLockInfo');
    }
    if (res.lock && (res.lock.status==='pending'||res.lock.status==='approved') && META.confirm_pw_allowed) {
        // 只有超級管理員或被授權的管理員看得到；取消後解除鎖定回到可增列對象狀態，需輸入操作確認密碼
        $('<button id="planCancelBtn" class="b-att2" style="margin-left:8px;color:#DD5138;">取消送出</button>').on('click', cancelPlan).insertAfter($('#planDecideBtn').length?'#planDecideBtn':'#planLockInfo');
    }
}
/** 操作確認密碼：密碼一律用 password 欄位遮罩顯示，不用 window.prompt 明碼(會外洩)——
 *  比照 as_document_management.php 的 askSuperPwd()，全站任何要輸入密碼確認的地方都應該用這種寫法；
 *  實際驗證走 src/common/confirm_password_lib.php，超級管理員或被授權的管理員都能用自己的密碼。 */
function askSuperPwd(msg, onConfirm){
    $('#pwMsg').text(msg); $('#pwInput').val(''); $('#pwErr').text('');
    $('#pwMask').data('onConfirm', onConfirm);
    openMask('pwMask');
    setTimeout(function(){ $('#pwInput').trigger('focus'); }, 200);
}
$('#pwOk').on('click', function(){
    var pwd = $('#pwInput').val();
    if (!pwd) { $('#pwErr').text('請輸入密碼'); return; }
    var cb = $('#pwMask').data('onConfirm');
    closeMask('pwMask');
    if (cb) cb(pwd);
});
$(document).on('keydown', '#pwInput', function(e){ if (e.key==='Enter'){ e.preventDefault(); $('#pwOk').trigger('click'); } });
function cancelPlan(){
    var year = $('#planYear').val();
    if (!confirm('確定要取消 '+year+' 年度稽核計畫的送出/核准狀態嗎？取消後會解除鎖定，可重新增列對象並重新送出，此操作會留下紀錄。')) return;
    askSuperPwd('請輸入操作確認密碼以確認取消 '+year+' 年度稽核計畫：', function(pwd){
        $.post(API, {action:'plan_cancel', year:year, password:pwd}, function(res){
            if (!res.ok){ alert(res.error||'取消失敗'); return; }
            alert('已取消，該年度計畫已解除鎖定。');
            loadPlan();
        }, 'json').fail(function(x){ alert(x.responseJSON&&x.responseJSON.error || '取消失敗'); });
    });
}
$('#planYear').on('change', loadPlan);
$('#planSubmitBtn').on('click', function(){
    var d = prompt('請輸入送出計畫日期(YYYY-MM-DD)，留空＝今天：', META.today);
    if (d===null) return;
    d = d.trim() || META.today;
    if (!/^\d{4}-\d{2}-\d{2}$/.test(d)){ alert('日期格式不正確'); return; }
    if (!confirm('確定送出 '+$('#planYear').val()+' 年度稽核計畫？送出後將鎖定，不可再增列對象。')) return;
    $.post(API, {action:'plan_submit', year:$('#planYear').val(), submit_date:d}, function(res){
        if(!res.ok){ alert(res.error||'送出失敗'); return; }
        alert(res.lock.status==='approved' ? '已送出並生效' : '已送出，待核准');
        loadPlan();
    }, 'json').fail(function(x){ alert('送出失敗：'+(x.responseJSON&&x.responseJSON.error||x.status)); });
});
function openPlanDecideMask(){
    $('input[name="pdDecision"][value="approved"]').prop('checked', true);
    $('#pdNoteBox').hide();
    $('#pdNote').val('');
    $('#pdDate').val(META.today);
    openMask('planDecideMask');
}
$(document).on('change', 'input[name="pdDecision"]', function(){
    $('#pdNoteBox').toggle($('input[name="pdDecision"]:checked').val()==='rejected');
});
function submitPlanDecide(){
    var decision = $('input[name="pdDecision"]:checked').val();
    var d = $('#pdDate').val();
    if (!d){ alert('請選擇核准日期'); return; }
    var note = $('#pdNote').val().trim();
    if (decision==='rejected' && !note){ alert('退回必須填寫原因'); return; }
    if (!confirm(decision==='approved' ? '確定核准這份年度稽核計畫？' : '確定退回這份年度稽核計畫？')) return;
    planDecideAction(decision, note, d);
}
function planDecideAction(decision, note, approvedDate){
    $.post(API, {action:'plan_decide', year:$('#planYear').val(), decision:decision, note:note||'', approved_date:approvedDate||META.today}, function(res){
        if(!res.ok){ alert(res.error||'處理失敗'); return; }
        alert(decision==='approved' ? '已核准' : '已退回');
        closeMask('planDecideMask');
        loadPlan();
    }, 'json').fail(function(x){ alert('處理失敗：'+(x.responseJSON&&x.responseJSON.error||x.status)); });
}
/** 供應商稽核計劃列印：每16家一頁，超過自動換頁；每頁自帶大標題(年度置左對齊供應商名稱欄、公司名/文件名置中)，
 *  簽核區只出現在最後一頁且不可被切斷。禁用 position:fixed 頁首頁尾（見 ai-rules/16、[[print_pagination]]，
 *  fixed 定位在不同印表機/列印引擎下排版不穩定，是先前版面跑掉的根因）；改用逐頁分段 page-break-after 的做法，
 *  和本檔 printEvalAll() 相同手法。 */
$('#planPrintBtn').on('click', function(){
    if (!PLANDATA){ alert('請先載入資料'); return; }
    var docName=(PLANDATA.plan_as_doc&&PLANDATA.plan_as_doc.doc_name)||'供應商稽核計劃';
    var docNo=(PLANDATA.plan_as_doc&&PLANDATA.plan_as_doc.doc_no)||'2-PH-01-06';
    var year=$('#planYear').val();
    var lock = PLANDATA.lock;
    // 核准與製表人同印一份文件的日期，兩欄一律用同一個日期（該計劃的業務日期＝送出日，比照ai-rules/18簽章日期規則），
    // 不印核准當下真正點擊的日期，避免同一張計劃上出現兩個不同日期。
    var planDate = fmtDate(lock ? lock.submit_date : META.today) || '';
    var approveStamp = (lock && lock.status==='approved' && lock.approved_by_name)
        ? vaStampHtml(lock.approved_by_name, planDate) : '';
    var maker = lock ? (lock.submitted_by_name||'') : CUR_USER_NAME;
    var makeStamp = maker ? vaStampHtml(maker, planDate) : '';
    var rowsAll = PLANDATA.rows||[];
    var PER_PAGE = 16;
    var pageCount = Math.max(1, Math.ceil(rowsAll.length/PER_PAGE));
    var body = '';
    for (var p=0; p<pageCount; p++){
        var chunk = rowsAll.slice(p*PER_PAGE, (p+1)*PER_PAGE);
        var isLast = (p === pageCount-1);
        var head = '<div style="text-align:center;">'
            + '<div style="font-size:20px;font-weight:bold;letter-spacing:1px;">'+esc(PLANDATA.company_name||'')+'</div>'
            + '<div style="font-size:14px;font-weight:bold;margin-top:2px;">'+esc(docName)+'</div></div>'
            + '<div style="text-align:left;font-size:14px;font-weight:bold;margin-top:8px;">'+esc(year)+' 年（'+scopeLabel(CUR_SCOPE)+'）</div>';
        var table = '<table class="pf plan-table" style="table-layout:fixed;margin-top:2px;"><colgroup><col style="width:15%;">';
        for (var m=1;m<=12;m++) table += '<col>';
        table += '<col style="width:16%;"></colgroup><thead><tr><th>供應商名稱</th>';
        for (m=1;m<=12;m++) table += '<th>'+m+'月</th>';
        table += '<th style="text-align:left;">加工項目</th></tr></thead><tbody>';
        chunk.forEach(function(r){
            table += '<tr><td class="q">'+esc(r.maker_id||'')+'</td>';
            for (var mm=1;mm<=12;mm++) table += '<td>'+(r.months&&r.months[mm]?'V':'')+'</td>';
            table += '<td class="q">'+esc(r.sub_cat_names||'')+'</td></tr>';
        });
        table += '</tbody></table>';
        // 91px圖章比60px留白高，簽章區實際高度以章為準(見ai-rules/16第6條圖章列印統一91px)；
        // 這裡的min-height只是版面美觀的最小值，不是真正的高度上限，故估算頁面總高時要用圖章實際尺寸
        var sign = isLast ? ('<table class="pf-sign" style="margin-top:8px;page-break-inside:avoid;"><tr>'
            +'<td style="width:33%;"><div style="font-size:11px;color:#555;">核准</div><div style="margin-top:2px;min-height:91px;">'+approveStamp+'</div></td>'
            +'<td style="width:34%;"></td>'
            +'<td style="width:33%;"><div style="font-size:11px;color:#555;">製表人</div><div style="margin-top:2px;min-height:91px;">'+makeStamp+'</div></td>'
            +'</tr></table>') : '';
        body += '<div'+(isLast?'':' style="page-break-after:always;"')+'>'+head+table+sign+'</div>';
    }
    openPrintWindow(body, '供應商稽核計劃', docNo, true);
});

/* ---------- 查核表設定(管理員) ---------- */
var CL_CATS = [];
$('#btnChecklist').on('click', function(){
    $.getJSON(API, {action:'get_checklist'}, function(res){
        if(!res.ok){ alert(res.error||'載入失敗'); return; }
        CL_CATS = (res.items||[]).map(function(cat){
            return {code:cat[0], name:cat[1], items:cat[2].map(function(it){ return {item_id:+it[0], item_no:it[1], question:it[2], item_max:it[3]}; })};
        });
        $('#clSelfW').val(res.self_w); $('#clAuditW').val(res.audit_w); $('#clPassRate').val(res.pass_rate);
        // 查核表依範疇(外包加工/採購)各自獨立一份，標題標示目前正在編輯哪一份，避免誤改成對方的題庫
        $('#checklistMask .m-head span:first').text('查核表設定（'+scopeLabel(CUR_SCOPE)+'）');
        clRenderCats();
        openMask('checklistMask');
    });
});
function clRenderCats(){
    var html='';
    CL_CATS.forEach(function(cat, ci){
        html += '<div style="border:1px solid #EADFC8;border-radius:6px;padding:8px;margin-bottom:8px;">';
        html += '<div style="display:flex;gap:8px;align-items:center;margin-bottom:6px;">'
            + '<input type="text" data-ci="'+ci+'" class="cl-code" placeholder="代碼(如A)" style="width:60px;" value="'+esc(cat.code||'')+'">'
            + '<input type="text" data-ci="'+ci+'" class="cl-name" placeholder="類別名稱" style="flex:1;" value="'+esc(cat.name||'')+'">'
            + '<span class="va-op" style="color:#DD5138;" onclick="clDelCat('+ci+')"><i class="fa fa-times"></i>刪除類別</span></div>';
        html += '<table style="width:100%;font-size:12px;"><thead><tr><th style="width:60px;">項次</th><th>查核問題</th><th style="width:90px;">單項滿分</th><th style="width:40px;"></th></tr></thead><tbody>';
        cat.items.forEach(function(it, ii){
            html += '<tr><td><input type="text" data-ci="'+ci+'" data-ii="'+ii+'" class="cl-no" value="'+esc(it.item_no||'')+'" style="width:50px;"></td>'
                + '<td><input type="text" data-ci="'+ci+'" data-ii="'+ii+'" class="cl-q" value="'+esc(it.question||'')+'" style="width:100%;"></td>'
                + '<td><input type="number" data-ci="'+ci+'" data-ii="'+ii+'" class="cl-max" min="1" step="0.5" value="'+it.item_max+'" style="width:70px;"></td>'
                + '<td><span class="va-op" style="color:#DD5138;" onclick="clDelItem('+ci+','+ii+')"><i class="fa fa-times"></i></span></td></tr>';
        });
        html += '</tbody></table><button type="button" class="b-att2" style="margin-top:6px;" onclick="clAddItem('+ci+')"><i class="fa fa-plus"></i> 新增項次</button>';
        html += '</div>';
    });
    $('#clCatsBox').html(html);
    clBindInputs();
    clRecalcTotal();
}
function clBindInputs(){
    $('.cl-code').off('input').on('input', function(){ CL_CATS[+$(this).data('ci')].code = $(this).val(); });
    $('.cl-name').off('input').on('input', function(){ CL_CATS[+$(this).data('ci')].name = $(this).val(); });
    $('.cl-no').off('input').on('input', function(){ CL_CATS[+$(this).data('ci')].items[+$(this).data('ii')].item_no = $(this).val(); });
    $('.cl-q').off('input').on('input', function(){ CL_CATS[+$(this).data('ci')].items[+$(this).data('ii')].question = $(this).val(); });
    $('.cl-max').off('input').on('input', function(){ CL_CATS[+$(this).data('ci')].items[+$(this).data('ii')].item_max = +$(this).val()||1; clRecalcTotal(); });
}
function clRecalcTotal(){
    var t=0; CL_CATS.forEach(function(cat){ cat.items.forEach(function(it){ t+=(+it.item_max||0); }); });
    $('#clTotalMax').text(t);
}
function clAddCat(){ CL_CATS.push({code:'', name:'新類別', items:[{item_id:0,item_no:'1',question:'',item_max:7}]}); clRenderCats(); }
function clDelCat(ci){ if(CL_CATS.length<=1){alert('至少要保留一個類別');return;} CL_CATS.splice(ci,1); clRenderCats(); }
function clAddItem(ci){ CL_CATS[ci].items.push({item_id:0, item_no:String(CL_CATS[ci].items.length+1), question:'', item_max:7}); clRenderCats(); }
function clDelItem(ci,ii){ if(CL_CATS[ci].items.length<=1){alert('該類別至少要保留一個項次');return;} CL_CATS[ci].items.splice(ii,1); clRenderCats(); }
function submitChecklist(){
    for (var i=0;i<CL_CATS.length;i++){
        if (!CL_CATS[i].name.trim()){ alert('類別名稱不可空白'); return; }
        for (var j=0;j<CL_CATS[i].items.length;j++){
            if (!CL_CATS[i].items[j].question.trim()){ alert('查核問題不可空白'); return; }
            if (!(+CL_CATS[i].items[j].item_max>0)){ alert('單項滿分需大於0'); return; }
        }
    }
    $.post(API, {action:'save_checklist', cats:JSON.stringify(CL_CATS),
        self_w:$('#clSelfW').val(), audit_w:$('#clAuditW').val(), pass_rate:$('#clPassRate').val()}, function(res){
        if(!res.ok){ alert(res.error||'儲存失敗'); return; }
        META.items=res.items; META.total_max=res.total_max; META.self_w=res.self_w; META.audit_w=res.audit_w; META.pass_rate=res.pass_rate;
        closeMask('checklistMask');
        alert('已儲存（已完成評分的紀錄不受影響）');
    }, 'json').fail(function(x){ alert('儲存失敗：'+(x.responseJSON&&x.responseJSON.error||x.status)); });
}

/* ---------- 簽核設定(管理員) ---------- */
var CHAIN_LABELS = {dept_or_user:'部門或人員綁定（組織角色綁定設定第三節）', auto_supervisor:'自動：送出者的上一階主管', top_approver:'最高決策者'};
var CHAIN_METHODS_ALL = ['dept_or_user','auto_supervisor','top_approver'];
function renderChainBox(chain){
    chain = (chain && chain.length) ? chain : CHAIN_METHODS_ALL;
    var h='';
    for (var i=0;i<3;i++){
        h += '<div><label>第'+(i+1)+'順位</label><select class="chain-sel" data-idx="'+i+'" style="width:220px;"><option value="">（不使用）</option>';
        CHAIN_METHODS_ALL.forEach(function(m){
            h += '<option value="'+m+'"'+(chain[i]===m?' selected':'')+'>'+esc(CHAIN_LABELS[m])+'</option>';
        });
        h += '</select></div>';
    }
    $('#chainBox').html(h);
}
$('#btnSignSetting').on('click', function(){
    // 稽核紀錄簽核設定+年度計畫簽核開關/核准鏈依範疇各自獨立，標題標示目前正在編輯哪一份
    $('#signSetMask .m-head span:first').text('簽核設定（'+scopeLabel(CUR_SCOPE)+'）');
    $('#ssAuto').prop('checked', !!(META.sign_setting && META.sign_setting.auto));
    $('#ssPlanNeed').prop('checked', !!(META.plan_sign_setting && META.plan_sign_setting.need));
    $('#ssTopApprover').text((META.plan_approver_names && META.plan_approver_names.length) ? META.plan_approver_names.join('、')
        : '（目前解析不到任何合格人選，請先到「組織角色綁定設定」的「供應商稽核計劃核准」指定部門或人員）');
    $.getJSON(API, {action:'get_approver_chain'}, function(res){
        renderChainBox(res.ok ? res.chain : null);
    });
    $.getJSON(API, {action:'sign_dept_options'}, function(res){
        if(!res.ok) return;
        var $s=$('#ssDept').html('<option value="">（尚未設定）</option>');
        (res.options||[]).forEach(function(o){ $s.append('<option value="'+o.id+'">'+esc(o.name)+'</option>'); });
        $s.val((META.sign_setting && META.sign_setting.dept_id) || '');
    });
    openMask('signSetMask');
});
function submitSignSetting(){
    $.post(API, {action:'save_sign_setting', auto:$('#ssAuto').is(':checked')?1:0, dept_id:$('#ssDept').val()||0}, function(res){
        if(!res.ok){ alert(res.error||'儲存失敗'); return; }
        META.sign_setting = res.setting;
        $.post(API, {action:'save_plan_sign_setting', need:$('#ssPlanNeed').is(':checked')?1:0}, function(res2){
            if(!res2.ok){ alert(res2.error||'儲存失敗'); return; }
            META.plan_sign_setting = res2.setting;
            var chain=[];
            $('.chain-sel').each(function(){ var v=$(this).val(); if (v && chain.indexOf(v)<0) chain.push(v); });
            $.post(API, {action:'save_approver_chain', chain:JSON.stringify(chain)}, function(res3){
                if(!res3.ok){ alert(res3.error||'儲存失敗'); return; }
                closeMask('signSetMask');
                loadPlan();
            }, 'json');
        }, 'json');
    }, 'json').fail(function(x){ alert('儲存失敗：'+(x.responseJSON&&x.responseJSON.error||x.status)); });
}

/* ---------- 兩年未交易外包廠 ---------- */
$('#rsStaleBtn').on('click', function(){
    $.getJSON(API, {action:'stale_vendors'}, function(res){
        if(!res.ok){ alert(res.error||'查詢失敗'); return; }
        $('#staleHint').html('最後發包日早於 <b>'+res.cutoff+'</b>（兩年前）的納管／在冊外包廠共 <b>'+res.rows.length+'</b> 家，請確認後移除：');
        var h='';
        res.rows.forEach(function(r){
            h+='<tr><td><input type="checkbox" class="stale-ck" value="'+esc(r.maker_id_no)+'" checked></td>'
              +'<td>'+esc(r.main_cat_name||'—')+'</td><td>'+esc(r.maker_id_no)+'</td><td class="t-left">'+esc(r.maker_id||'')+'</td>'
              +'<td style="color:#DD5138;">'+esc(r.last_date||'—')+'</td>'
              +'<td>'+(r.audit_managed?'納管':'')+(r.in_roster?(r.audit_managed?'+清冊':'手動列入'):'')+'</td></tr>';
        });
        $('#staleBody').html(h||'<tr><td colspan="6" style="padding:14px;color:#8a6d45;">無兩年未交易外包廠，清冊乾淨</td></tr>');
        $('#staleAll').prop('checked', res.rows.length>0);
        openMask('staleMask');
    });
});
$('#staleAll').on('change', function(){ $('#staleBody .stale-ck').prop('checked', this.checked); });
function staleRemove(){
    var ids=$('#staleBody .stale-ck:checked').map(function(){return this.value;}).get();
    if(!ids.length){ alert('請勾選要移除的廠商'); return; }
    if(!confirm('確認移除勾選的 '+ids.length+' 家？（取消納管＋移出清冊＋刪未稽核對象）')) return;
    $.post(API,{action:'stale_remove',maker_ids:ids.join(',')},function(res){
        if(!res.ok){ alert(res.error||'移除失敗'); return; }
        alert('已移除 '+res.removed+' 家'); closeMask('staleMask'); loadRoster();
    },'json').fail(function(x){ alert('移除失敗：'+(x.responseJSON&&x.responseJSON.error||x.status)); });
}

$('#btnRoleHelp').on('click', function(){ openMask('helpMask'); });
$('#btnPageHelp').on('click', function(){ openMask('helpUseMask'); });
$('.va-mask').on('click', function(e){ if (e.target===this) this.style.display='none'; });

$(window).on('scroll', function(){ $('#vaToTop').toggle($(window).scrollTop()>200); });

// 通知深連結可能指定範疇(生管/採購各自獨立年度計畫，見VENDOR_AUDIT_PLAN_OUTSOURCE/PURCHASE)；
// 開頁當下就要用對的 scope 呼叫 meta/loadRound，不能等載入完才切換(切換會整批重打API)。
(function(){
    var sm = /[?&]scope=(outsource|purchase)/.exec(location.search);
    if (sm) { CUR_SCOPE = sm[1]; localStorage.setItem('va_cur_scope', CUR_SCOPE); }
    $('.va-scope-btn').removeClass('active').filter('[data-scope="'+CUR_SCOPE+'"]').addClass('active');
})();
if (canView) loadMeta(function(){
    loadRound();
    var m = /[?&]sign=(\d+)/.exec(location.search);
    if (m) openSignMask(+m[1]);
    // 供應商稽核計劃通知深連結：從通知點進來直接切到「供應商稽核計劃」分頁＋對應年度，
    // 待核准且本人有權核准時自動跳出核准/退回跳窗，不要求使用者自己找分頁點（ai-rules/17）。
    var pa = /[?&]plan_approve=(\d+)/.exec(location.search), py = /[?&]plan_year=(\d+)/.exec(location.search);
    if (pa || py) {
        $('.va-tab').removeClass('active'); $('.va-tab[data-tab="plan"]').addClass('active');
        $('#tabAudit,#tabEval,#tabRoster').hide(); $('#tabPlan').show();
        $('#planYear').val(+((pa||py)[1]));
        loadPlan(pa ? function(res){
            if (res.lock && res.lock.status==='pending' && res.can_decide) openPlanDecideMask();
            else if (res.lock && res.lock.status!=='pending') alert('此年度計劃目前狀態為「'+({approved:'已核准',rejected:'已退回'}[res.lock.status]||res.lock.status)+'」，無待核准項目');
        } : null);
    }
});
</script>
</body>
</html>
