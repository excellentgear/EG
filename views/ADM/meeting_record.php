<?php
/**
 * 會議紀錄管理（2-GM-05-01 會議記錄／2-GM-05-03 會議通知單）
 * 資料一律走 src/store/Meeting_API.php；權限 src/common/meeting_lib.php
 * 草稿僅記錄人本人看得到；送出後由角色設定「檢視全部」或出席／主席／總經理身分才看得到（見 meeting_can_view）。
 */
session_start();
if (!isset($_SESSION['userName'])) {
    $_SESSION['lastpage'] = "../../views/ADM/meeting_record.php";
    header("Location:../../index.php");
    exit;
}
include_once '../../src/common/_config.php';
include_once '../../src/common/DBConnection.php';
include_once '../../src/common/meeting_lib.php';

$db = (new DBConnection())->getPDO();
meeting_ensure_schema($db);
$mUser = meeting_current_user($db);
$perms = meeting_perms($db, $mUser);

// 角色說明一律當下查資料庫現況組出，不寫死角色清單（鐵律4；比照 training_record.php）
$roleRows = [];
try {
    $roleRows = $db->query("SELECT role_id, role_code, role_name, is_system FROM roles
                            WHERE module='meeting' OR is_system=1 ORDER BY is_system DESC, role_id")->fetchAll(PDO::FETCH_ASSOC);
} catch (Throwable $e) {}
$featLabel = [];
foreach (MEETING_FEATURES as $f) $featLabel[$f['code']] = $f['label'];
$roleExplain = [];
foreach ($roleRows as $rr) {
    if ((int)$rr['is_system'] === 1) { $roleExplain[] = [$rr['role_name'], '系統角色，固定擁有全部權限（不可修改）']; continue; }
    $codes = [];
    try {
        $fc = $db->prepare("SELECT feature_code FROM role_features WHERE role_id=?");
        $fc->execute([(int)$rr['role_id']]);
        $codes = $fc->fetchAll(PDO::FETCH_COLUMN);
    } catch (Throwable $e) {}
    if (in_array('all', $codes, true)) $desc = '固定擁有全部權限';
    else {
        $labels = [];
        foreach ($codes as $c) if (isset($featLabel[$c])) $labels[] = $featLabel[$c];
        $desc = $labels ? implode('；', $labels) : '（尚未勾選任何功能）';
    }
    $roleExplain[] = [$rr['role_name'], $desc];
}
?>
<!DOCTYPE html>
<html lang="zh-Hant">
<head>
    <meta charset="utf-8">
    <meta http-equiv="X-UA-Compatible" content="IE=edge">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>會議紀錄管理</title>
    <link href="../../resource/css/bootstrap.css" rel="stylesheet">
    <link href="../../resource/css/font-awesome.css" rel="stylesheet">
    <link href="../../resource/css/nprogress.css" rel="stylesheet">
    <link href="../../resource/css/custom.css" rel="stylesheet">
    <style>
        #sidebar-menu { visibility: hidden; }
        .right_col .page-title { margin:8px 0 4px; overflow:hidden; clear:both; }
        .mt-toolbar { display:flex; flex-wrap:wrap; gap:6px; align-items:center; clear:both;
            border:1.5px solid #E8D5B5; border-radius:8px; padding:8px 10px; margin-bottom:10px; background:#FDF8EF; }
        .mt-toolbar label { margin:0; font-size:13px; color:#5b3a1e; }
        .mt-toolbar select, .mt-toolbar input[type=text], .mt-toolbar button {
            height:30px; font-size:13px; line-height:1; padding:0 10px; border:1px solid #D8BE93;
            border-radius:4px; background:#fff; color:#5b3a1e; cursor:pointer; }
        .mt-toolbar button:hover { background:#F7E0BD; }
        .mt-toolbar .btn-warm { background:#F0A24B; color:#fff; border-color:#d98a33; }
        .mt-toolbar .btn-warm:hover { background:#d98a33; }
        .page-help-btn { margin-left:auto; height:30px; font-size:13px; padding:0 14px; border:1px solid #D8BE93;
            border-radius:4px; background:#fff; color:#5b3a1e; cursor:pointer; }
        .page-help-btn:hover { background:#F7E0BD; }
        .help-doc h4 { font-size:14px; color:#8A5A2B; margin:10px 0 4px; }
        input[type=number]::-webkit-outer-spin-button, input[type=number]::-webkit-inner-spin-button { -webkit-appearance:none; margin:0; }
        input[type=number] { -moz-appearance:textfield; }
        .mt-mask { display:none; position:fixed; inset:0; background:rgba(60,40,20,.45); z-index:1050; }
        .mt-modal { background:#fff; border-radius:8px; max-width:600px; margin:40px auto; box-shadow:0 5px 25px rgba(0,0,0,.3);
            max-height:88vh; display:flex; flex-direction:column; }
        .mt-modal .m-head { background:#F7E0BD; color:#5b3a1e; font-weight:bold; padding:10px 15px; border-radius:8px 8px 0 0;
            display:flex; justify-content:space-between; }
        .mt-modal .m-head .m-close { cursor:pointer; color:#b5762a; }
        .mt-modal .m-body { padding:15px; overflow-y:auto; }
        .mt-modal .m-body label { display:block; font-size:13px; color:#5b3a1e; margin:9px 0 3px; }
        .mt-modal .m-body input[type=text], .mt-modal .m-body input[type=number], .mt-modal .m-body input[type=date],
        .mt-modal .m-body select, .mt-modal .m-body textarea { width:100%; border:1px solid #D8BE93; border-radius:4px;
            padding:5px 8px; font-size:13px; box-sizing:border-box; }
        .mt-modal .m-foot { padding:10px 15px; border-top:1px solid #EADFC8; text-align:right; }
        .mt-modal .m-foot button { height:30px; padding:0 16px; border-radius:4px; font-size:13px; border:1px solid #d98a33; cursor:pointer; }
        .mt-modal .m-foot .b-ok { background:#F0A24B; color:#fff; }
        .mt-modal .m-foot .b-cancel { background:#fff; color:#5b3a1e; border-color:#D8BE93; margin-right:6px; }
        .mt-modal .m-foot .b-danger { background:#DD5138; color:#fff; border-color:#c23f28; }
        .mt-modal.wide { max-width:920px; }
        .grid2 { display:grid; grid-template-columns:1fr 1fr; gap:0 14px; }
        .grid3 { display:grid; grid-template-columns:1fr 1fr 1fr; gap:0 14px; }
        .errmsg { color:#DD5138; font-size:11.5px; line-height:1.5; }
        input.ro-auto, input.ro-auto:focus { background:#EFEAE1 !important; color:#7a7166 !important;
            border-color:#D9D1C4 !important; cursor:not-allowed; }
        .time-in { letter-spacing:.5px; text-align:center; }
        .stat-filter { display:flex; gap:5px; flex-wrap:wrap; }
        .sf-btn { cursor:pointer; font-size:12px; border:1.5px solid transparent; border-radius:12px; padding:3px 10px;
            opacity:.55; transition:opacity .15s; }
        .sf-btn:hover { opacity:.85; }
        .sf-btn.on { opacity:1; border-color:#8A5A2B; box-shadow:inset 0 0 0 1px #fff; font-weight:bold; }
        .sf-btn b { margin-left:3px; font-weight:normal; }
        .mt-modal .m-body input.inv, .mt-modal .m-body select.inv { border-color:#DD5138 !important; background:#FFF4F0; }
        .mt-hint { margin-top:10px; font-size:12px; color:#8a6d45; background:#FDF8EF; border:1px dashed #E8D5B5;
            border-radius:6px; padding:7px 10px; line-height:1.6; }
        .mt-hint b { color:#8A5A2B; }
        .mt-sec { border-top:1px dashed #EADFC8; margin-top:10px; padding-top:6px; }
        .mt-sec-title { font-weight:bold; color:#5b3a1e; margin:6px 0 4px; }
        .att-people { max-height:130px; overflow-y:auto; border:1px solid #EADFC8; border-radius:6px; padding:6px 8px;
            display:flex; flex-wrap:wrap; gap:4px 14px; margin-bottom:6px; min-height:20px; }
        .att-people label { font-size:12px; color:#5b3a1e; margin:0; font-weight:normal; cursor:pointer; }
        .att-people .empty { color:#b0a390; font-size:12px; }
        button.b-att { height:28px; font-size:12px; border:1px solid #d98a33; background:#F0A24B; color:#fff; border-radius:4px; cursor:pointer; padding:0 10px; }
        button.b-att.wt { background:#fff; color:#8A5A2B; }
        .att-list-wrap { max-height:190px; overflow-y:auto; border:1px solid #EADFC8; border-radius:6px; }
        table.att-tbl { width:100%; border-collapse:collapse; font-size:12px; }
        table.att-tbl th, table.att-tbl td { border-bottom:1px solid #F0E7D5; padding:3px 8px; text-align:center; }
        table.att-tbl thead th { position:sticky; top:0; background:#F7E0BD; color:#5b3a1e; }
        table.att-tbl td.t-left { text-align:left; }
        .att-del { color:#DD5138; cursor:pointer; }
        table.itm-tbl { width:100%; border-collapse:collapse; font-size:12.5px; margin-bottom:8px; }
        table.itm-tbl th, table.itm-tbl td { border:1px solid #EADFC8; padding:4px 6px; vertical-align:top; }
        table.itm-tbl thead th { background:#F7E0BD; color:#5b3a1e; }
        table.itm-tbl textarea { width:100%; min-height:44px; border:1px solid #D8BE93; border-radius:4px; padding:4px 6px; font-size:12.5px; box-sizing:border-box; }
        table.itm-tbl input[type=text], table.itm-tbl input[type=date] { width:100%; border:1px solid #D8BE93; border-radius:4px; padding:3px 5px; font-size:12px; box-sizing:border-box; }
        .dp-pick { position:relative; border:1px solid #D8BE93; border-radius:4px; background:#fff; padding:2px 3px; min-width:130px; }
        .dp-tags { display:flex; flex-wrap:wrap; gap:2px; }
        .dp-tags .tg { background:#F7E0BD; color:#5b3a1e; border-radius:9px; font-size:11px; padding:1px 5px 1px 7px; white-space:nowrap; }
        .dp-tags .tg i { cursor:pointer; color:#b5762a; margin-left:3px; }
        .dp-pick > input { width:100%; border:none !important; outline:none; font-size:11.5px; padding:2px 3px !important; }
        .dp-list { display:none; position:absolute; left:0; right:0; top:100%; z-index:30; background:#fff;
            border:1px solid #D8BE93; border-radius:0 0 4px 4px; max-height:170px; overflow-y:auto; box-shadow:0 4px 10px rgba(0,0,0,.12); min-width:150px; }
        .dp-list div { padding:3px 8px; font-size:11.5px; color:#5b3a1e; cursor:pointer; }
        .dp-list div:hover { background:#FBF0DD; }
        .confirm-yes { color:#7a5217; font-weight:bold; font-size:11.5px; }
        .confirm-no { color:#b0a390; font-size:11.5px; }
        .mt-table-wrap { overflow-x:auto; border:1px solid #E8D5B5; border-radius:6px; background:#fff; }
        table.mt-table { width:100%; border-collapse:collapse; font-size:13px; }
        table.mt-table th, table.mt-table td { border:1px solid #EADFC8; padding:6px 8px; text-align:center; }
        table.mt-table thead th { background:#F7E0BD; color:#5b3a1e; font-weight:bold; }
        table.mt-table tbody tr:nth-child(even) { background:#FBF6EC; }
        table.mt-table tbody tr:hover { background:#FBF0DD; }
        table.mt-table td.t-left { text-align:left; }
        .st-pill { display:inline-block; font-size:12px; border-radius:10px; padding:2px 9px; }
        .st-draft { background:#efe7d8; color:#7a6d5a; }
        .st-submitted { background:#F2C86D; color:#5C3D00; font-weight:bold; }
        .st-chair_done { background:#E8B77A; color:#4d2f10; font-weight:bold; }
        .st-done { background:#F0A24B; color:#fff; }
        .st-rejected { background:#DD5138; color:#fff; }
        .mt-op { color:#b5762a; cursor:pointer; }
        .mt-op:hover { color:#8A5A2B; text-decoration:underline; }
        .mt-op-wrap { display:flex; gap:12px; align-items:center; white-space:nowrap; }
        .view-box { font-size:12.5px; color:#5b3a1e; line-height:1.7; }
        .view-box h5 { margin:10px 0 4px; font-size:13px; color:#8A5A2B; font-weight:bold; }
        .view-box .kv { margin-bottom:6px; }
        .view-box .kv b { color:#8A5A2B; }
        .view-box table { width:100%; border-collapse:collapse; font-size:12px; background:#fff; margin-bottom:8px; }
        .view-box table th, .view-box table td { border:1px solid #EADFC8; padding:4px 7px; text-align:left; }
        .view-box table th { background:#F7E0BD; color:#5b3a1e; text-align:center; }
        .sign-row { display:flex; gap:5px; align-items:center; flex-wrap:wrap; padding:3px 0; }
        .sign-row input[type=password] { width:120px; height:26px; border:1px solid #D8BE93; border-radius:4px; padding:0 6px; font-size:12px; }
        .sign-row button { height:26px; font-size:11.5px; border:1px solid #d98a33; background:#F0A24B; color:#fff; border-radius:4px; cursor:pointer; padding:0 8px; }
        .sign-ok { color:#7a5217; font-size:11.5px; }
        .decide-box { border:1.5px solid #F0A24B; border-radius:8px; background:#FFF7E8; padding:10px 12px; margin-top:10px; }
        .decide-box textarea { width:100%; min-height:56px; border:1px solid #D8BE93; border-radius:4px; padding:5px 8px; font-size:12.5px; box-sizing:border-box; margin-top:4px; }
        .kpi-box { border:1.5px solid #D8BE93; border-radius:6px; background:#FDF8EF; padding:8px 10px; margin-top:8px; font-size:12.5px; }
        .kpi-box table { width:100%; border-collapse:collapse; font-size:12px; margin-top:4px; }
        .kpi-box table th, .kpi-box table td { border:1px solid #EADFC8; padding:4px 7px; text-align:center; }
        .kpi-meta { font-size:12px; margin-bottom:4px; }
        .kpi-week-tbl { width:100%; border-collapse:collapse; font-size:11.5px; }
        .kpi-week-tbl th, .kpi-week-tbl td { border:1px solid #D8BE93; padding:4px 6px; text-align:center; }
        .kpi-total-row { font-weight:bold; background:#FFF3DE; }
        .kpi-top3-wrap { display:flex; gap:10px; margin-top:8px; flex-wrap:wrap; }
        .kpi-top3 { flex:1 1 220px; padding-left:6px; }
        .kpi-top3-tt { font-weight:bold; font-size:12px; margin-bottom:3px; }
        .kpi-top3-tbl { width:100%; border-collapse:collapse; font-size:11px; }
        .kpi-top3-tbl th, .kpi-top3-tbl td { border:1px solid #EADFC8; padding:3px 5px; text-align:center; }
        .item-confirm-box { display:flex; gap:3px; align-items:center; flex-wrap:wrap; justify-content:center; }
        .item-confirm-box select, .item-confirm-box input, .item-confirm-box button { font-size:11.5px; }
        .item-notify-status { font-size:10.5px; color:#8a6d45; margin-top:3px; }
        .mt-noperm { margin:40px auto; max-width:520px; text-align:center; border:1.5px solid #E8D5B5; border-radius:10px;
            padding:30px; background:#FDF8EF; color:#5b3a1e; }
        @media print {
            .mt-toolbar, .nav_menu, .left_col, footer, .mt-op { display:none !important; }
            .right_col { margin:0 !important; padding:0 !important; }
        }
    </style>
</head>
<body class="nav-sm">
<div class="container body">
<div class="main_container">
    <?php include '../partPage/sideAndTopBarMenu.html' ?>
    <div class="right_col" role="main">
        <div class="page-title" style="display:flex;align-items:center;flex-wrap:wrap;">
            <h2 style="margin:6px 0;">會議紀錄管理
                <small style="color:#8a6d45;"><span id="mtHeaderDocNo"></span>會議記錄／2-GM-05-03 會議通知單</small></h2>
            <button class="page-help-btn" id="btnPageHelp" style="margin-left:auto;"><i class="fa fa-question-circle"></i> 使用說明</button>
        </div>
        <div class="clearfix"></div>

<?php if (!$perms['canView']): ?>
        <div class="mt-noperm">
            <h4><i class="fa fa-lock"></i> 無會議記錄檢閱權限</h4>
            <p>請洽系統管理者於「使用者權限設定」指派「會議記錄」相關角色。</p>
        </div>
<?php else: ?>
        <div class="mt-toolbar">
            <label>年度</label><select id="yearSel"></select>
            <label>狀態（可複選篩選）</label>
            <div class="stat-filter" id="statFilter"></div>
            <button id="btnMtSetting" style="display:none;margin-left:auto;"><i class="fa fa-cog"></i> 模組設定</button>
            <button class="btn-warm" id="btnAdd" style="display:none;"><i class="fa fa-plus"></i> 新增會議紀錄</button>
        </div>
        <div class="mt-table-wrap">
            <table class="mt-table">
                <thead><tr><th style="width:100px;">日期</th><th style="width:34%;">主題</th><th style="width:90px;">主席</th>
                    <th style="width:90px;">記錄</th><th style="width:100px;">狀態</th><th style="width:200px;">操作</th></tr></thead>
                <tbody id="listBody"></tbody>
            </table>
        </div>
<?php endif; ?>
    </div>
</div></div>

<!-- 建立/編輯 modal -->
<div class="mt-mask" id="edMask"><div class="mt-modal wide">
    <div class="m-head"><span id="edTitle">新增會議紀錄</span><span class="m-close" onclick="closeMask('edMask')">✕</span></div>
    <div class="m-body">
        <div class="grid3">
            <div style="grid-column:span 2;"><label>會議主題 *
                <select id="edPreset" style="width:auto;display:inline-block;height:20px;font-size:11px;padding:0 4px;margin-left:6px;"><option value="">套用常用設定…</option></select>
                <a href="javascript:;" id="btnPresetMgr" style="display:none;font-size:11px;color:#b5762a;margin-left:4px;" onclick="openPresetMgr()"><i class="fa fa-cog"></i> 管理</a>
                <select id="edCalPick" style="width:auto;display:inline-block;height:20px;font-size:11px;padding:0 4px;margin-left:6px;" title="只列出今天的行事曆會議，自動帶入日期/時間/主題/出席人員"><option value="">從行事曆選今天的會議…</option></select></label>
                <input type="text" id="edSubject" maxlength="100" list="edSubjectTags"><datalist id="edSubjectTags"></datalist>
                <div class="errmsg" id="errEdSubject"></div></div>
            <div><label>會議日期 *</label><input type="date" id="edDate" max="9999-12-31">
                <div class="errmsg" id="errEdDate"></div></div>
            <div><label>開始時間</label><input type="text" id="edStart" class="time-in" maxlength="5" placeholder="09:00">
                <div class="errmsg" id="errEdStart"></div></div>
            <div><label>結束時間</label><input type="text" id="edEnd" class="time-in" maxlength="5" placeholder="17:00">
                <div class="errmsg" id="errEdEnd"></div></div>
            <div><label>地點</label><input type="text" id="edLoc" maxlength="100" list="edLocTags"><datalist id="edLocTags"></datalist></div>
            <div><label>主席（出席人員內選一位） *</label><select id="edChair"><option value="">請先加入出席人員</option></select></div>
            <div><label>記錄</label><input type="text" id="edRecorder" maxlength="50" class="ro-auto" readonly tabindex="-1" data-eg-skip title="自動帶入目前登入者，不可修改"></div>
        </div>

        <div class="mt-sec">
            <div class="mt-sec-title">出席人員 <small id="attCount" style="color:#8a6d45;font-weight:normal;"></small></div>
            <div style="display:flex;gap:6px;align-items:center;flex-wrap:wrap;margin-bottom:4px;">
                <select id="attGroupSel" style="width:170px;height:28px;border:1px solid #D8BE93;border-radius:4px;"><option value="">套用已存的群組…</option></select>
                <button type="button" class="b-att wt" onclick="groupApply()"><i class="fa fa-users"></i> 套用</button>
                <button type="button" class="b-att wt" onclick="openGroupSave()" title="把目前出席人員清單存成群組，下次可直接套用"><i class="fa fa-save"></i> 另存為群組</button>
            </div>
            <div style="display:flex;gap:6px;align-items:center;flex-wrap:wrap;margin-bottom:4px;">
                <select id="attDept" style="width:150px;height:28px;border:1px solid #D8BE93;border-radius:4px;"><option value="">選部門載入人員…</option></select>
                <button type="button" class="b-att" onclick="attAddChecked()"><i class="fa fa-user-plus"></i> 加入勾選人員</button>
                <label style="margin:0;font-size:12px;color:#8a6d45;"><input type="checkbox" id="attPickAll"> 全選</label>
            </div>
            <div id="attPeopleBox" class="att-people"></div>
            <div class="att-list-wrap">
                <table class="att-tbl"><thead><tr><th>部門</th><th>職稱</th><th>姓名</th><th style="width:26px;"></th></tr></thead>
                <tbody id="attBody"></tbody></table>
            </div>
        </div>

        <div class="mt-sec">
            <div class="mt-sec-title">宣布事項</div>
            <table class="itm-tbl">
                <thead><tr><th style="width:30px;">NO</th><th>內容</th><th style="width:160px;">備註</th><th style="width:24px;"></th></tr></thead>
                <tbody id="itmBodyA" data-eg-row-add="itemAddAnnounce" data-eg-row-del="itemDelLastAnnounce"></tbody>
            </table>
            <button type="button" class="b-att wt" onclick="itemAdd('announce')"><i class="fa fa-plus"></i> 新增宣布事項</button>
        </div>
        <div class="mt-sec">
            <div class="mt-sec-title">上級指示要項</div>
            <table class="itm-tbl">
                <thead><tr><th style="width:30px;">NO</th><th>報告要點及決議事項</th><th style="width:96px;">應完成日期</th>
                    <th style="width:130px;">負責部門</th><th style="width:120px;">備註</th><th style="width:24px;"></th></tr></thead>
                <tbody id="itmBodyD" data-eg-row-add="itemAddDirective" data-eg-row-del="itemDelLastDirective"></tbody>
            </table>
            <button type="button" class="b-att wt" onclick="itemAdd('directive')"><i class="fa fa-plus"></i> 新增上級指示項目</button>
        </div>
        <div class="mt-sec">
            <div class="mt-sec-title">會議要項</div>
            <table class="itm-tbl">
                <thead><tr><th style="width:30px;">NO</th><th>報告要點及決議事項</th><th style="width:96px;">應完成日期</th>
                    <th style="width:130px;">負責部門</th><th style="width:120px;">備註</th><th style="width:24px;"></th></tr></thead>
                <tbody id="itmBodyG" data-eg-row-add="itemAddGeneral" data-eg-row-del="itemDelLastGeneral"></tbody>
            </table>
            <button type="button" class="b-att wt" onclick="itemAdd('general')"><i class="fa fa-plus"></i> 新增會議要項</button>
        </div>

        <div class="mt-sec">
            <div class="mt-sec-title">出貨目標達成率（產銷會議可插入本月數據佐證，非必要可略過）</div>
            <div id="kpiBox"></div>
        </div>

        <div class="mt-sec">
            <div class="mt-sec-title">附件</div>
            <div id="edAttachList" style="font-size:12px;"></div>
            <div style="margin-top:5px;display:flex;gap:6px;align-items:center;flex-wrap:wrap;">
                <input type="file" id="edAttachFile" style="font-size:12px;">
                <input type="text" id="edAttachType" maxlength="100" placeholder="附件類型／說明(自由輸入，選填)" style="width:220px;">
                <button type="button" class="b-att" onclick="edUploadAttach()"><i class="fa fa-upload"></i> 上傳</button>
                <span style="font-size:11px;color:#8a6d45;">單檔上限 20MB</span>
            </div>
        </div>
    </div>
    <div class="m-foot">
        <div style="text-align:left;font-size:11.5px;color:#8a6d45;line-height:1.6;margin-bottom:6px;">
            <b style="color:#8A5A2B;">存草稿</b>＝可隨時再修改；<b style="color:#8A5A2B;">送出</b>＝鎖定內容並通知主席確認簽章，之後需退回才能再改。
        </div>
        <button class="b-cancel" onclick="closeMask('edMask')">取消</button>
        <button class="b-ok" style="background:#fff;color:#8A5A2B;" onclick="saveDraft(false)">存草稿</button>
        <button class="b-ok" id="btnEdSubmit" onclick="saveDraft(true)"><i class="fa fa-paper-plane"></i> 存檔並送出</button>
    </div>
</div></div>

<!-- 檢視/簽核/列印 modal -->
<div class="mt-mask" id="viewMask"><div class="mt-modal wide">
    <div class="m-head"><span id="viewTitle">會議紀錄</span><span class="m-close" onclick="closeMask('viewMask')">✕</span></div>
    <div class="m-body view-box" id="viewBody"></div>
    <div class="m-foot">
        <button class="b-cancel" onclick="printBlankSignSheet()"><i class="fa fa-file-o"></i> 列印空白簽到表</button>
        <button class="b-cancel" id="btnPrintSignedSheet" style="display:none;" onclick="printSignedSignSheet()"><i class="fa fa-file-text-o"></i> 列印簽到表</button>
        <button class="b-cancel" onclick="printMeetingRecord()"><i class="fa fa-print"></i> 列印會議紀錄(預覽用)</button>
        <button class="b-cancel" id="btnPrintKpi" style="display:none;" onclick="printKpiOnly()"><i class="fa fa-line-chart"></i> 列印出貨目標達成率</button>
        <button class="b-ok" onclick="closeMask('viewMask')">關閉</button>
    </div>
</div></div>

<!-- 常用設定管理（主題綁地點綁時間，管理員維護，套用後仍可自行修改） -->
<div class="mt-mask" id="presetMask"><div class="mt-modal">
    <div class="m-head"><span>常用設定管理</span><span class="m-close" onclick="closeMask('presetMask')">✕</span></div>
    <div class="m-body">
        <div class="att-list-wrap" style="max-height:220px;">
            <table class="att-tbl"><thead><tr><th>主題</th><th>地點</th><th style="width:100px;">時間</th><th style="width:26px;"></th></tr></thead>
            <tbody id="presetBody"></tbody></table>
        </div>
        <div style="display:flex;gap:5px;flex-wrap:wrap;margin-top:8px;">
            <input type="text" id="pstSubject" placeholder="主題" style="flex:1 1 120px;">
            <input type="text" id="pstLoc" placeholder="地點" style="flex:1 1 100px;">
            <input type="text" id="pstStart" class="time-in" maxlength="5" placeholder="09:00" style="width:66px;">
            <input type="text" id="pstEnd" class="time-in" maxlength="5" placeholder="17:00" style="width:66px;">
            <button type="button" class="b-att" onclick="presetAdd()"><i class="fa fa-plus"></i> 新增</button>
        </div>
    </div>
    <div class="m-foot"><button class="b-ok" onclick="closeMask('presetMask')">關閉</button></div>
</div></div>

<!-- 模組設定（管理員）：角色設定／附件與AS文件綁定，兩個分頁在同一顆按鈕內 -->
<div class="mt-mask" id="mtSetMask"><div class="mt-modal wide">
    <div class="m-head"><span>模組設定</span><span class="m-close" onclick="closeMask('mtSetMask')">✕</span></div>
    <div class="m-body">
        <div class="va-tabs" style="display:flex;gap:4px;margin-bottom:10px;border-bottom:2px solid #E8D5B5;">
            <button type="button" class="set-tab active" data-tab="role" onclick="setTabSwitch('role')">角色設定</button>
            <button type="button" class="set-tab" data-tab="attach" onclick="setTabSwitch('attach')">附件與簽到表AS文件綁定</button>
        </div>
        <div id="setPaneRole">
            <p style="font-size:12px;color:#8a6d45;margin:0 0 8px;">左邊選或新增角色 → 右邊改名稱、勾這個角色能看到什麼／能做什麼。權限由上而下包含：勾了「會議記錄管理員」自動含登錄與檢閱。「誰擁有這個角色」在<a href="../user/user_permissions.php" target="_blank">人員權限設定頁</a>設定，這裡只定義角色內容。</p>
            <div style="display:flex;gap:10px;flex-wrap:wrap;align-items:flex-start;">
                <div style="border:1px solid #E8D5B5;border-radius:6px;background:#fff;flex:0 0 190px;">
                    <div style="background:#F7E0BD;color:#5b3a1e;font-size:12px;font-weight:bold;padding:5px 10px;border-radius:6px 6px 0 0;display:flex;justify-content:space-between;align-items:center;">角色
                        <button type="button" class="b-att" id="btnRoleAdd" style="padding:1px 8px;">＋ 新增</button></div>
                    <div id="roleList" style="max-height:280px;overflow-y:auto;"></div>
                </div>
                <div style="border:1px solid #E8D5B5;border-radius:6px;background:#fff;flex:1;min-width:260px;">
                    <div style="background:#F7E0BD;color:#5b3a1e;font-size:12px;font-weight:bold;padding:5px 10px;border-radius:6px 6px 0 0;">角色內容</div>
                    <div id="roleEdit" style="display:none;padding:10px;">
                        <label>角色名稱</label>
                        <div style="display:flex;gap:6px;">
                            <input type="text" id="roleName" style="flex:1;">
                            <button type="button" class="b-att" id="btnRoleRename">改名</button>
                            <button type="button" class="b-att" style="color:#DD5138;" id="btnRoleDel">刪除</button>
                        </div>
                        <div style="font-size:12px;font-weight:bold;color:#8A5A2B;margin:10px 0 4px;">可視內容（看得到什麼）</div>
                        <div id="featView"></div>
                        <div style="font-size:12px;font-weight:bold;color:#8A5A2B;margin:10px 0 4px;">可操作（能做什麼）</div>
                        <div id="featOp"></div>
                        <button type="button" class="b-att" id="btnRoleFeatSave" style="margin-top:10px;background:#F0A24B;color:#fff;"><i class="fa fa-save"></i> 儲存功能</button>
                    </div>
                    <div id="roleEditHint" style="padding:24px;text-align:center;color:#8a6d45;">請在左側選一個角色，或按「＋ 新增」</div>
                </div>
            </div>
        </div>
        <div id="setPaneAttach" style="display:none;">
            <label>附件儲存路徑（留空＝用全站預設根目錄＋「會議紀錄」子資料夾）</label>
            <input type="text" id="setNasDir" style="width:100%;" placeholder="\\excellentnas\...\會議紀錄">
            <button type="button" class="b-att" style="margin-top:6px;" onclick="submitNasDir()">儲存路徑</button>
            <div style="margin-top:16px;">
                <label>會議記錄 AS 文件編號綁定</label>
                <div style="display:flex;gap:6px;align-items:center;">
                    <span id="recordDocLabel" style="flex:1;padding:6px 8px;border:1px solid #D8BE93;border-radius:4px;background:#FFF7E8;">（尚未綁定）</span>
                    <button type="button" class="b-att" onclick="openRecordDocPicker()">選擇</button>
                </div>
            </div>
            <div style="margin-top:16px;">
                <label>簽到表 AS 文件編號綁定</label>
                <div style="display:flex;gap:6px;align-items:center;">
                    <span id="signsheetDocLabel" style="flex:1;padding:6px 8px;border:1px solid #D8BE93;border-radius:4px;background:#FFF7E8;">（尚未綁定）</span>
                    <button type="button" class="b-att" onclick="openSignsheetPicker()">選擇</button>
                </div>
            </div>
            <div style="margin-top:16px;">
                <label>出席人員／簽到、負責人／確認簽名 的簽名圖章樣式</label>
                <div style="display:flex;gap:6px;align-items:center;">
                    <select id="setStampTpl" style="flex:1;"><option value="0">（預設印章樣式）</option></select>
                    <button type="button" class="b-att" onclick="submitStampTpl()">儲存</button>
                </div>
                <p class="text-muted" style="font-size:11.5px;margin:4px 0 0;">套用哪個模板請到「圖章管理 → 線上圖章設計」建立/挑選；有上傳掃描實體章的人一律優先用掃描章，這裡只影響沒掃描章時自動產生的印章樣式。</p>
            </div>
            <div style="margin-top:16px;">
                <label>出貨目標達成率 基礎設定（週目標金額／帳款起始日）</label>
                <div style="display:flex;gap:6px;align-items:center;flex-wrap:wrap;">
                    <select id="kpiTgtYear" style="width:80px;"></select>
                    <select id="kpiTgtMonth" style="width:70px;"></select>
                    <span>週目標金額</span><input type="number" id="kpiTgtAmount" style="width:120px;" min="0">
                    <span>帳款起始日</span><input type="number" id="kpiTgtStartDay" style="width:60px;" min="1" max="28">
                    <button type="button" class="b-att" onclick="submitKpiTarget()">儲存</button>
                </div>
                <p class="text-muted" style="font-size:11.5px;margin:4px 0 0;">此設定與 KPI 設定頁（AS9100 關鍵績效指標）共用同一組設定，兩邊改其中一處即可，出貨目標達成率週報頁面(Shipping_Analysis_new.php)存廢不影響。</p>
            </div>
        </div>
    </div>
    <div class="m-foot"><button class="b-ok" onclick="closeMask('mtSetMask')">關閉</button></div>
</div></div>

<!-- 出席人員群組（仿通知功能：公開/私人自訂群組，重用共用 co_editor_preset） -->
<div class="mt-mask" id="groupSaveMask"><div class="mt-modal" style="max-width:380px;">
    <div class="m-head"><span>另存為群組</span><span class="m-close" onclick="closeMask('groupSaveMask')">✕</span></div>
    <div class="m-body">
        <label>群組名稱</label><input type="text" id="grpName" maxlength="50">
        <label style="margin-top:10px;"><input type="checkbox" id="grpPublic" style="width:auto;margin-right:5px;"> 公開（所有人可選用；不勾＝僅自己看得到）</label>
    </div>
    <div class="m-foot"><button class="b-cancel" onclick="closeMask('groupSaveMask')">取消</button>
        <button class="b-ok" onclick="groupSaveConfirm()">儲存</button></div>
</div></div>

<!-- 使用說明 modal（鐵律7） -->
<div class="mt-mask" id="helpUseMask"><div class="mt-modal wide">
    <div class="m-head"><span>使用說明 — 會議紀錄管理</span><span class="m-close" onclick="closeMask('helpUseMask')">✕</span></div>
    <div class="m-body help-doc" style="font-size:13px;color:#5b3a1e;line-height:1.8;">
        <h4>功能說明</h4>
        會議記錄（2-GM-05-01）線上化：建立會議基本資料與出席名單、現場密碼簽到、上級指示要項／會議要項雙表格、
        主席與總經理雙層簽核、產銷會議可自動插入本月出貨目標達成率佐證。
        <h4>操作步驟</h4>
        <b>①新增</b>：填主題/日期/時間/地點（主題、地點可打字自由輸入，也可從曾用過的建議清單挑；有設定「常用設定」時可一鍵套用主題+地點+時間，套用後仍可自行修改），加入出席人員（依部門挑選，或套用已存的<b>公開/私人群組</b>——把常開會的一批人存成群組，下次直接套用，也可另存新群組），指定主席。日期時間存檔前後端都會檢查合理性（結束不可早於或等於開始）。「記錄」欄固定為目前登入者，不可修改。<br>
        <b>②建立要項</b>：分「上級指示要項」與「會議要項」兩張表，每項可填應完成日期、負責部門（可多選）、備註。<br>
        <b>③現場簽到</b>：開啟「檢視」，出席人員名單旁各自輸入<b>本人密碼</b>簽到（共用一台裝置輪流簽，用選人不用密碼反查身分，不會有密碼重複無法辨識的問題）。<br>
        <b>④存草稿或送出</b>：草稿只有記錄人自己看得到，可隨時修改；<b>送出</b>後鎖定內容並通知主席確認簽章 → 主席簽章後自動通知總經理確認簽章（總經理可逐筆或整體回覆意見）→ 完成。
        任一階段可退回，退回後記錄人可修改並重新送出。<br>
        <b>⑤負責人/部門項目確認</b>：要項的「負責人/部門」欄可點連結<b>切換兩種模式（二擇一，切換會清空另一種的選擇）</b>：<br>
        　－<b>選部門</b>（可多選）：<b>每個負責部門各要一位代表簽名</b>，系統依序自動算出誰要簽（現場只有算出的那位本人能輸入密碼簽這格）：①該部門本次以<b>主要角色</b>出席的主管優先（有設職級的職稱，如經理/副理/課長/組長等）②該部門沒有主要角色主管出席，才由<b>兼任</b>該部門主管的出席者代簽 ③連兼任主管都沒有，才由該部門出席人員中職稱排序最高者代簽（②③兩種情況章旁都標示「(代)」，不特別區分是否兼任）。<br>
        　－<b>指定人員</b>（可多選、可打字搜尋全公司人員）：直接指名的人只要本次有出席就是必簽者，不套用主管優先判定；沒指定到部門，不論那位人員屬於哪個部門都是他本人簽。<br>
        兩種模式下，負責人（部門或指定人員）本次完全沒人出席時，都會改發通知給對方回簽（任一人回覆即算完成）。<br>
        <b>⑥插入出貨目標達成率</b>：草稿階段可按「插入本月數據」，系統會先確認出貨資料已更新至前一個工作天，未達標會提示還差幾天，不會插入不完整的數字；插入後的數字是<b>當下的快照</b>，之後不會再變動。已完成核准的會議記錄在「檢視」畫面也能再插入/更新：一般人插入後會<b>清空目前簽核紀錄改回草稿</b>，需重新送出取得主席／總經理簽章；<b>超級管理員</b>插入後<b>維持已核准狀態</b>，不需重新送審。
        <h4>重要行為</h4>
        ・草稿只有記錄人本人看得到；送出後，出席人員／主席／總經理都自動有唯讀權限，其餘人是否看得到全部會議記錄依角色設定的「檢視全部」功能。<br>
        ・列印的會議記錄／空白簽到表<b>不含電子簽章</b>，供現場紙本簽名或掃描存查；主席／總經理的簽核仍在系統內完成並自動蓋章存證；出席人員<b>全部完成電子簽到</b>後會多一顆「列印簽到表」按鈕，印出來的是已蓋章版；有插入出貨目標達成率時會多一顆「列印出貨目標達成率」按鈕。<b>每顆列印按鈕各自印一份文件</b>（不提供多份文件合併列印），確保各自的AS文件編號都能正確印在頁面右下角。<br>
        ・主席或總經理今日若有請假等行程，會自動由代理人處理（依「代理系統設定」解析，不必自己找人代簽）。<br>
        ・清單上方「狀態」按鈕可複選篩選（點選切換開關），每顆按鈕會顯示目前年度符合筆數。<br>
        ・出席簽到蓋章的日期一律顯示<b>會議日期</b>（不論實際點擊簽到當下是哪一天），實際簽到時間僅另外標示供稽核參考。
        <h4>設定入口</h4>
        「常用設定」（主題旁的齒輪連結，僅管理員看得到）：維護主題+地點+時間的組合，供新增會議時一鍵套用（套用後仍可自行修改，不會鎖死）。
        <h4>權限角色</h4>
        會議記錄檢閱＝看（草稿仍僅本人）；會議記錄登錄＝新增/編輯/送出；會議記錄管理員＝＋檢視全部人員記錄、刪除、修改他人已送出記錄、維護常用設定；管理者全權。<br>
        ・<b>超級管理員（帳號e）專屬</b>：檢視畫面內出席簽到／項目確認簽名旁會多出「[改日期/補簽]」連結，可個別或用「一鍵補齊全部簽章日期」批次補齊漏簽/校正日期，尚未簽核的部分會視同已完成一併補簽；主席／總經理若該場會議從未送出過，也會自動送審＋自動核准（總經理階段會先確保主席已核准），不會卡在「查無紀錄無法補」。操作前需輸入超級管理員密碼，且會留下 page_change_log 紀錄可追溯。
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
<script src="../../resource/js/eg_asdoc_picker.js?v=<?= @filemtime(__DIR__.'/../../resource/js/eg_asdoc_picker.js') ?>"></script>
<script>
$(document).ready(function(){
    var $am = $('#sidebar-menu .nav.side-menu > li.active');
    if ($am.length) { $am.removeClass('active').find('ul.child_menu').hide(); $am.find('li.current-page').removeClass('current-page'); }
    $('#sidebar-menu').css('visibility','visible');
});

var API = '../../src/store/Meeting_API.php';
var META = null, PERMS = null, DEPTS = [], ALL_PEOPLE = [];
var MEETINGS = [];
var STATUS_LABEL = {draft:'草稿', submitted:'待主席簽章', chair_done:'待總經理簽章', done:'已完成', rejected:'已退回'};
function esc(s){ return String(s==null?'':s).replace(/[&<>"']/g,function(c){
    return {'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#39;'}[c]; }); }
function fmtDate(d){ return d ? String(d).substr(0,10) : ''; }
function setErr($el, msgBoxId, msg){ $el.toggleClass('inv', !!msg); if (msgBoxId) $('#'+msgBoxId).text(msg||''); return !msg; }
function closeMask(id){ document.getElementById(id).style.display='none'; }
function openMask(id){ document.getElementById(id).style.display='block'; }
function deptById(id){ for (var i=0;i<DEPTS.length;i++) if (String(DEPTS[i].id)===String(id)) return DEPTS[i]; return null; }
function parseTime(v){
    var s = String(v==null?'':v).trim().replace(/[：]/g,':').replace(/\s+/g,'');
    if (s==='') return {ok:true, val:''};
    var hh, mm, m;
    if ((m = s.match(/^(\d{1,2}):(\d{1,2})$/))) { hh=+m[1]; mm=+m[2]; }
    else if ((m = s.match(/^(\d{1,2})$/)))      { hh=+m[1]; mm=0; }
    else if ((m = s.match(/^(\d)(\d{2})$/)))    { hh=+m[1]; mm=+m[2]; }
    else if ((m = s.match(/^(\d{2})(\d{2})$/))) { hh=+m[1]; mm=+m[2]; }
    else return {ok:false, msg:'時間格式應為 HH:MM'};
    if (hh>23) return {ok:false, msg:'小時 '+hh+' 不存在，須 0~23'};
    if (mm>59) return {ok:false, msg:'分鐘 '+mm+' 不存在，須 0~59'};
    return {ok:true, val:(hh<10?'0':'')+hh+':'+(mm<10?'0':'')+mm};
}
function edTimeValidate(){
    var ok = true, bs = parseTime($('#edStart').val()), be = parseTime($('#edEnd').val());
    ok = setErr($('#edStart'),'errEdStart', bs.ok?'':bs.msg) && ok;
    ok = setErr($('#edEnd'),'errEdEnd', be.ok?'':be.msg) && ok;
    if (ok && bs.val && be.val && be.val<=bs.val) {
        ok = setErr($('#edEnd'),'errEdEnd','結束時間不可早於或等於開始時間') && false;
    }
    return ok;
}
$('#edStart,#edEnd').on('change', function(){
    var p = parseTime($(this).val());
    if (p.ok) $(this).val(p.val);
    edTimeValidate();
});

var PRESETS = [];
function loadMeta(cb){
    $.getJSON(API, {action:'meta'}, function(m){
        if (!m.ok){ alert(m.error||'載入失敗'); return; }
        META = m; PERMS = m.perms; DEPTS = m.departments||[]; PRESETS = m.presets||[];
        window.__ownCompany = m.company_name;
        var $y = $('#yearSel').empty();
        (m.years||[]).forEach(function(y){ $y.append('<option value="'+y+'">'+y+' 年</option>'); });
        $y.val(m.cur_year);
        var $ad = $('#attDept').empty().append('<option value="">選部門載入人員…</option>');
        DEPTS.forEach(function(d){ $ad.append('<option value="'+d.id+'">'+esc(d.name)+'</option>'); });
        if (m.perms.canEdit) $('#btnAdd').show();
        if (m.perms.canAdmin) { $('#btnPresetMgr').show(); $('#btnMtSetting').show(); }
        $('#mtHeaderDocNo').text(m.as_doc_record && m.as_doc_record.doc_no ? (m.as_doc_record.doc_no+' ') : '');
        renderPresetUI();
        loadGroups();
        $.getJSON(API, {action:'people_all'}, function(r){ if (r.ok) ALL_PEOPLE = r.people||[]; });
        if (cb) cb();
    });
}
/* 常用設定：主題綁地點綁時間，套用後仍可自行修改；輸入框同時提供 datalist 建議（打字篩選＋手動輸入並存） */
function renderPresetUI(){
    var subj = {}, loc = {};
    PRESETS.forEach(function(p){ subj[p.subject]=1; if(p.location) loc[p.location]=1; });
    $('#edSubjectTags').html(Object.keys(subj).map(function(s){ return '<option value="'+esc(s)+'">'; }).join(''));
    $('#edLocTags').html(Object.keys(loc).map(function(s){ return '<option value="'+esc(s)+'">'; }).join(''));
    var h = '<option value="">套用常用設定…</option>';
    PRESETS.forEach(function(p){
        var tm = p.start_time ? (p.start_time+(p.end_time?'~'+p.end_time:'')) : '';
        h += '<option value="'+p.preset_id+'">'+esc(p.subject)+(p.location?'｜'+esc(p.location):'')+(tm?'｜'+esc(tm):'')+'</option>';
    });
    $('#edPreset').html(h);
}
$('#edPreset').on('change', function(){
    var p = PRESETS.find(function(x){ return String(x.preset_id)===$('#edPreset').val(); });
    if (!p) return;
    $('#edSubject').val(p.subject);
    if (p.location) $('#edLoc').val(p.location);
    if (p.start_time) $('#edStart').val(p.start_time);
    if (p.end_time) $('#edEnd').val(p.end_time);
    edTimeValidate();
    $(this).val('');
});
$('#yearSel').on('change', function(){ loadList(); });
$('#btnAdd').on('click', openCreate);
$('#btnPageHelp').on('click', function(){ openMask('helpUseMask'); });

function loadList(){
    NProgress.start();
    $.getJSON(API, {action:'list', year:$('#yearSel').val()}, function(res){
        NProgress.done();
        if (!res.ok){ alert(res.error||'載入失敗'); return; }
        MEETINGS = res.meetings||[];
        renderStatFilter(); renderList();
    });
}
/* 狀態篩選：按鈕點選可複選，每顆顯示目前年度符合筆數；未選任何顆＝顯示全部 */
var STAT_FILTER = new Set();
function renderStatFilter(){
    var counts = {};
    Object.keys(STATUS_LABEL).forEach(function(k){ counts[k] = 0; });
    MEETINGS.forEach(function(m){ counts[m.approval_status] = (counts[m.approval_status]||0) + 1; });
    var h = '';
    Object.keys(STATUS_LABEL).forEach(function(k){
        h += '<span class="sf-btn st-'+k+(STAT_FILTER.has(k)?' on':'')+'" data-v="'+k+'">'
           + STATUS_LABEL[k] + '<b>×' + counts[k] + '</b></span>';
    });
    $('#statFilter').html(h);
}
$(document).on('click', '#statFilter .sf-btn', function(){
    var v = $(this).data('v');
    if (STAT_FILTER.has(v)) STAT_FILTER.delete(v); else STAT_FILTER.add(v);
    renderStatFilter(); renderList();
});
function renderList(){
    var h = '';
    var rows = MEETINGS.filter(function(m){ return !STAT_FILTER.size || STAT_FILTER.has(m.approval_status); });
    rows.forEach(function(m){
        h += '<tr><td>'+fmtDate(m.meeting_date)+'</td><td class="t-left">'+esc(m.subject)+'</td>'
           + '<td>'+esc(m.chair_name||'—')+'</td><td>'+esc(m.recorder_name||'')+'</td>'
           + '<td><span class="st-pill st-'+m.approval_status+'">'+(STATUS_LABEL[m.approval_status]||m.approval_status)+'</span></td>'
           + '<td><div class="mt-op-wrap">'
           + '<span class="mt-op" onclick="openView('+m.meeting_id+')"><i class="fa fa-search-plus"></i> 檢視</span>';
        var canEditRow = (m.is_mine || PERMS.canAdmin) && (m.approval_status==='draft' || m.approval_status==='rejected');
        if (canEditRow) h += '<span class="mt-op" onclick="openEdit('+m.meeting_id+')"><i class="fa fa-pencil"></i> 編輯</span>';
        if (m.approval_status==='draft' && (m.is_mine || PERMS.canAdmin))
            h += '<span class="mt-op" style="color:#DD5138;" onclick="deleteMeeting('+m.meeting_id+')"><i class="fa fa-trash"></i> 刪除</span>';
        h += '</div></td></tr>';
    });
    $('#listBody').html(h || '<tr><td colspan="6" style="color:#8a6d45;padding:14px;">本年度尚無會議記錄</td></tr>');
}

/* ---------- 建立/編輯 ---------- */
var EDIT_ID = 0, ATT = [], ITEMS_D = [], ITEMS_G = [], ITEMS_A = [], KPI_SNAP = null, EDIT_ATTACHES = [], TEMP_ATTACH_IDS = [];
function openCreate(){
    EDIT_ID = 0; ATT = []; ITEMS_D = []; ITEMS_G = []; ITEMS_A = []; KPI_SNAP = null; EDIT_ATTACHES = []; TEMP_ATTACH_IDS = [];
    $('#edTitle').text('新增會議紀錄');
    $('#edSubject').val(''); $('#edDate').val(META.today); $('#edStart').val(''); $('#edEnd').val('');
    $('#edLoc').val(''); $('#edRecorder').val(META.uname);
    renderAtt(); renderItems('directive'); renderItems('general'); renderItems('announce'); renderChairSel(); renderKpiBox(); renderEdAttach();
    $('#attDept').val(''); $('#attPeopleBox').html('<span class="empty">選部門載入人員</span>');
    loadCalendarMeetings();
    openMask('edMask');
}
/* 從行事曆挑選「今天」的會議類別事件，自動帶入日期/時間/主題/出席人員（使用者明確要求限當天；只在新增時提供，編輯既有記錄不覆蓋） */
var CAL_EVENTS = [];
function loadCalendarMeetings(){
    $.getJSON(API, {action:'calendar_meetings'}, function(res){
        if (!res.ok) return;
        CAL_EVENTS = res.events||[];
        var h = '<option value="">從行事曆選今天的會議…</option>';
        CAL_EVENTS.forEach(function(e){
            var d = String(e.start).substr(0,10), t = String(e.start).substr(11,5);
            h += '<option value="'+e.id+'">'+esc(d)+' '+esc(t)+'　'+esc(e.title)+'</option>';
        });
        $('#edCalPick').html(h);
    });
}
$('#edCalPick').on('change', function(){
    var ev = CAL_EVENTS.find(function(x){ return String(x.id)===$('#edCalPick').val(); });
    $(this).val('');
    if (!ev) return;
    $('#edSubject').val(ev.title);
    $('#edDate').val(String(ev.start).substr(0,10));
    $('#edStart').val(String(ev.start).substr(11,5));
    $('#edEnd').val(String(ev.end).substr(11,5));
    edTimeValidate();
    (ev.actors||[]).forEach(function(a){
        if (!ATT.some(function(x){ return x.user_id===a.user_id; }))
            ATT.push({user_id:a.user_id, user_name:a.user_name, dept_name:a.dept_name, position_name:a.position_name, signed:0});
    });
    renderAtt(); renderChairSel();
});
function openEdit(id){
    $.getJSON(API, {action:'get_detail', meeting_id:id}, function(res){
        if (!res.ok){ alert(res.error||'載入失敗'); return; }
        var m = res.meeting;
        EDIT_ID = m.meeting_id;
        $('#edTitle').text('編輯會議紀錄');
        $('#edSubject').val(m.subject); $('#edDate').val(fmtDate(m.meeting_date));
        $('#edStart').val(m.start_time||''); $('#edEnd').val(m.end_time||''); $('#edLoc').val(m.location||'');
        $('#edRecorder').val(m.recorder_name||'');
        ATT = (res.attendees||[]).map(function(a){ return {user_id:+a.user_id, user_name:a.user_name, dept_name:a.dept_name, position_name:a.position_name||'', signed:+a.signed===1?1:0}; });
        ITEMS_D = []; ITEMS_G = []; ITEMS_A = [];
        (res.items||[]).forEach(function(it){
            var row = {item_id:it.item_id, content:it.content, due_date:fmtDate(it.due_date),
                owner_depts:(it.owner_depts?String(it.owner_depts).split(','):[]),
                owner_users:(it.owner_users?String(it.owner_users).split(','):[]), remark:it.remark||'',
                owner_mode: it.owner_users ? 'user' : 'dept',
                confirm_slots:it.confirm_slots||[]};
            var target = it.kind==='directive' ? ITEMS_D : (it.kind==='announce' ? ITEMS_A : ITEMS_G);
            target.push(row);
        });
        KPI_SNAP = m.kpi_snapshot_json ? JSON.parse(m.kpi_snapshot_json) : null;
        EDIT_ATTACHES = res.attaches||[]; TEMP_ATTACH_IDS = [];
        renderAtt(); renderItems('directive'); renderItems('general'); renderItems('announce'); renderChairSel(); renderKpiBox(); renderEdAttach();
        $('#edChair').val(m.chair_user_id||'');
        $('#attDept').val(''); $('#attPeopleBox').html('<span class="empty">選部門載入人員</span>');
        openMask('edMask');
    });
}
function deleteMeeting(id){
    if (!confirm('確定刪除此會議記錄？（無法復原）')) return;
    $.post(API, {action:'delete', meeting_id:id}, function(res){
        if (!res.ok){ alert(res.error||'刪除失敗'); return; }
        loadList();
    }, 'json');
}

/* 出席人員：部門挑選 → 勾選加入（同 training 模組模式）。2026-08-06使用者明確要求：不列會議當天時段有請假
   的人員，一律帶目前表單上的日期/時間查詢(由後端 meeting_filter_available_people 過濾)；日期/時間改變時
   若已選了部門，重新載入該部門名單，避免名單跟已改過的日期時間對不上。 */
function attDeptReload(){
    var did = $('#attDept').val();
    if (!did){ $('#attPeopleBox').html('<span class="empty">選部門載入人員</span>'); return; }
    if (!$('#edDate').val()){ $('#attPeopleBox').html('<span class="empty">請先填會議日期，才能排除當天請假人員</span>'); return; }
    $('#attPeopleBox').html('<span class="empty">載入中…</span>');
    $.getJSON(API, {action:'people', dept_id:did, meeting_date:$('#edDate').val(), start_time:$('#edStart').val(), end_time:$('#edEnd').val()}, function(res){
        if (!res.ok){ $('#attPeopleBox').html('<span class="empty">載入失敗</span>'); return; }
        var h = '';
        res.people.forEach(function(u){
            var inList = ATT.some(function(a){ return a.user_id===+u.id; });
            h += '<label><input type="checkbox" class="att-ck" value="'+u.id+'" data-name="'+esc(u.user_cname)+'"'
               + ' data-dept="'+esc($('#attDept option:selected').text())+'" data-pos="'+esc(u.position_name||'')+'"'+(inList?' checked disabled':'')+'> '
               + esc(u.user_cname)+(u.position_name?'<span style="color:#8a6d45;">（'+esc(u.position_name)+'）</span>':'')+(inList?'(已加)':'')+'</label>';
        });
        $('#attPeopleBox').html(h || '<span class="empty">此部門無可選人員(可能全數請假或僅剩超級管理員)</span>');
        $('#attPickAll').prop('checked', false);
    });
}
$('#attDept').on('change', attDeptReload);
$('#edDate,#edStart,#edEnd').on('change', function(){ if ($('#attDept').val()) attDeptReload(); });
$('#attPickAll').on('change', function(){ $('#attPeopleBox .att-ck:not(:disabled)').prop('checked', this.checked); });
function attAddChecked(){
    $('#attPeopleBox .att-ck:checked:not(:disabled)').each(function(){
        var id = +$(this).val();
        if (!ATT.some(function(a){ return a.user_id===id; }))
            ATT.push({user_id:id, user_name:$(this).data('name'), dept_name:$(this).data('dept'), position_name:$(this).data('pos')||'', signed:0});
    });
    renderAtt(); renderChairSel(); $('#attDept').trigger('change');
}
function attDel(i){
    var was = ATT[i];
    ATT.splice(i,1);
    renderAtt(); renderChairSel();
    if (was) $('#attDept').trigger('change');
}
function renderAtt(){
    var h = '';
    ATT.forEach(function(a,i){
        h += '<tr><td>'+esc(a.dept_name||'')+'</td><td>'+esc(a.position_name||'—')+'</td><td class="t-left">'+esc(a.user_name||'')
           + (+a.signed===1 ? ' <span style="color:#7a5217;font-size:11px;">（已簽到）</span>' : '') + '</td>'
           + '<td><span class="att-del" onclick="attDel('+i+')"><i class="fa fa-times"></i></span></td></tr>';
    });
    $('#attBody').html(h || '<tr><td colspan="4" style="color:#8a6d45;padding:6px;">尚未加入出席人員</td></tr>');
    $('#attCount').text(ATT.length ? '（共 '+ATT.length+' 人）' : '');
    refreshEdSubmitBtn();
}
/* 送出按鈕動態標籤(2026-08-06使用者明確要求)：出席人員全部簽到、且負責部門/指定人員也都簽完 → 存檔並送出；
   全部簽到但還有負責人未簽 → 存檔並通知(送出後會另行擴大通知相關人員回簽，不再擋下送出)。
   這裡只用來決定按鈕文字(best-effort，資料來源是畫面上目前的 ATT/ITEMS，可能因為使用者剛編輯過而與後端存檔前的狀態略有落差)，
   真正是否需要通知一律由後端 submit 當下重新算(meeting_item_pending_notify_targets)，不受這裡影響。 */
function mtReadiness(){
    var allSigned = ATT.length>0 && ATT.every(function(a){ return +a.signed===1; });
    var pending = ITEMS_D.concat(ITEMS_G).filter(function(it){
        var hasOwner = (it.owner_depts&&it.owner_depts.length) || (it.owner_users&&it.owner_users.length);
        if (!hasOwner) return false;
        var slots = it.confirm_slots||[];
        return !(slots.length>0 && slots.every(function(s){ return s.signed; }));
    }).length;
    return {allSigned:allSigned, pending:pending};
}
function refreshEdSubmitBtn(){
    var r = mtReadiness();
    $('#btnEdSubmit').html(r.allSigned && r.pending>0
        ? '<i class="fa fa-bullhorn"></i> 存檔並通知'
        : '<i class="fa fa-paper-plane"></i> 存檔並送出');
}
function renderChairSel(){
    var cur = $('#edChair').val();
    var h = '<option value="">請選擇主席</option>';
    ATT.forEach(function(a){ h += '<option value="'+a.user_id+'">'+esc(a.user_name)+'（'+esc(a.dept_name||'')+'）</option>'; });
    $('#edChair').html(h).val(cur);
}

/* ---------- 常用設定管理（主題綁地點綁時間，管理員維護） ---------- */
function openPresetMgr(){ renderPresetList(); openMask('presetMask'); }
function renderPresetList(){
    var h = '';
    PRESETS.forEach(function(p){
        var tm = p.start_time ? (p.start_time+(p.end_time?'~'+p.end_time:'')) : '';
        h += '<tr><td class="t-left">'+esc(p.subject)+'</td><td>'+esc(p.location||'')+'</td><td>'+esc(tm)+'</td>'
           + '<td><span class="att-del" onclick="presetDel('+p.preset_id+')"><i class="fa fa-times"></i></span></td></tr>';
    });
    $('#presetBody').html(h || '<tr><td colspan="4" style="color:#8a6d45;padding:6px;">尚未建立常用設定</td></tr>');
}
function presetAdd(){
    var subject = $.trim($('#pstSubject').val());
    if (!subject){ alert('請輸入主題'); return; }
    var bs = parseTime($('#pstStart').val()), be = parseTime($('#pstEnd').val());
    if (!bs.ok){ alert('開始時間：'+bs.msg); return; }
    if (!be.ok){ alert('結束時間：'+be.msg); return; }
    $.post(API, {action:'preset_save', subject:subject, location:$('#pstLoc').val(), start_time:bs.val, end_time:be.val}, function(res){
        if (!res.ok){ alert(res.error||'新增失敗'); return; }
        $('#pstSubject,#pstLoc,#pstStart,#pstEnd').val('');
        loadMeta(function(){ renderPresetList(); });
    }, 'json');
}
function presetDel(id){
    if (!confirm('刪除此常用設定？')) return;
    $.post(API, {action:'preset_delete', preset_id:id}, function(res){
        if (!res.ok){ alert(res.error||'刪除失敗'); return; }
        loadMeta(function(){ renderPresetList(); });
    }, 'json');
}

/* ---------- 出席人員群組（仿通知功能：公開/私人自訂群組，重用共用 co_editor_preset／_editorPreset.php） ---------- */
var GROUP_API = '../../src/store/_editorPreset.php';
function loadGroups(){
    $.getJSON(GROUP_API, {action:'list', module:'meeting_attendee_target'}, function(res){
        if (!res.ok) return;
        var h = '<option value="">套用已存的群組…</option>';
        (res.data||[]).forEach(function(g){
            h += '<option value="'+g.id+'">'+(g.is_public?'':'【私人】')+esc(g.name)+'</option>';
        });
        $('#attGroupSel').html(h);
    });
}
function groupApply(){
    var id = $('#attGroupSel').val();
    if (!id){ alert('請先選擇群組'); return; }
    if (!$('#edDate').val()){ alert('請先填會議日期，套用群組時才能排除當天請假的人員'); return; }
    $.getJSON(GROUP_API, {action:'get', id:id}, function(res){
        if (!res.ok){ alert(res.msg||'載入群組失敗'); return; }
        var ids = (res.editors||[]).map(function(e){ return String(e.code||'').replace(/^u/,''); }).filter(function(x){ return x && !isNaN(x); });
        if (!ids.length){ alert('此群組沒有可用的人員'); return; }
        $.getJSON(API, {action:'resolve_people', user_ids:ids.join(','), meeting_date:$('#edDate').val(), start_time:$('#edStart').val(), end_time:$('#edEnd').val()}, function(r2){
            if (!r2.ok) return;
            (r2.people||[]).forEach(function(p){
                if (!ATT.some(function(a){ return a.user_id===p.id; }))
                    ATT.push({user_id:p.id, user_name:p.user_cname, dept_name:p.dept_name, position_name:p.position_name, signed:0});
            });
            renderAtt(); renderChairSel();
            if (r2.people.length < ids.length) alert('已套用群組（其中 '+(ids.length-r2.people.length)+' 位因會議當天請假、超級管理員或已離職，未加入名單）。');
        });
    });
}
function openGroupSave(){
    if (!ATT.length){ alert('請先加入出席人員再另存為群組'); return; }
    $('#grpName').val(''); $('#grpPublic').prop('checked', false);
    openMask('groupSaveMask');
}
function groupSaveConfirm(){
    var name = $.trim($('#grpName').val());
    if (!name){ alert('請輸入群組名稱'); return; }
    var editors = ATT.map(function(a){ return {code:'u'+a.user_id, name:a.user_name, mode:'read'}; });
    $.post(GROUP_API, {action:'save', module:'meeting_attendee_target', name:name,
        is_public:$('#grpPublic').prop('checked')?1:0, editors:JSON.stringify(editors)}, function(res){
        if (!res.ok){ alert(res.msg||'儲存失敗'); return; }
        closeMask('groupSaveMask'); loadGroups(); alert('已儲存群組。');
    }, 'json');
}

/* 會議要項三表格：宣布事項(kind=announce,無應完成日期/負責部門/簽名) / 上級指示要項(kind=directive) / 會議要項(kind=general) */
function itemsArr(kind){ return kind==='directive' ? ITEMS_D : (kind==='announce' ? ITEMS_A : ITEMS_G); }
function itemBodySel(kind){ return '#itmBody'+(kind==='directive'?'D':(kind==='announce'?'A':'G')); }
function itemAdd(kind){ itemsArr(kind).push({item_id:0, content:'', due_date:'', owner_depts:[], owner_users:[], owner_mode:'dept', remark:''}); renderItems(kind); }
function itemDelLast(kind){ var a=itemsArr(kind); if (a.length) a.pop(); renderItems(kind); }
function itemDel(kind,i){ itemsArr(kind).splice(i,1); renderItems(kind); }
function itemEdit(kind,i,key,val){ var a=itemsArr(kind); if (a[i]) a[i][key]=val; }
function itemAddDirective(){ itemAdd('directive'); }
function itemDelLastDirective(){ itemDelLast('directive'); }
function itemAddGeneral(){ itemAdd('general'); }
function itemDelLastGeneral(){ itemDelLast('general'); }
function itemAddAnnounce(){ itemAdd('announce'); }
function itemDelLastAnnounce(){ itemDelLast('announce'); }
function renderItems(kind){
    var a = itemsArr(kind), h = '';
    if (kind === 'announce') {
        a.forEach(function(it,i){
            h += '<tr><td style="text-align:center;">'+(i+1)+'</td>'
               + '<td><textarea onchange="itemEdit(\'announce\','+i+',\'content\',this.value)">'+esc(it.content||'')+'</textarea></td>'
               + '<td><input type="text" maxlength="200" value="'+esc(it.remark||'')+'" onchange="itemEdit(\'announce\','+i+',\'remark\',this.value)"></td>'
               + '<td><span class="att-del" onclick="itemDel(\'announce\','+i+')"><i class="fa fa-times"></i></span></td></tr>';
        });
        $(itemBodySel(kind)).html(h || '<tr><td colspan="4" style="color:#8a6d45;padding:6px;text-align:center;">尚未建立項目</td></tr>');
        refreshEdSubmitBtn();
        return;
    }
    a.forEach(function(it,i){
        var slots = it.confirm_slots||[], doneN = slots.filter(function(s){ return s.signed; }).length;
        var hasOwner = (it.owner_depts&&it.owner_depts.length) || (it.owner_users&&it.owner_users.length);
        var confirmTxt = slots.length ? ('<span class="'+(doneN===slots.length?'confirm-yes':'confirm-no')+'">已簽 '+doneN+'/'+slots.length+'</span>')
                        : (hasOwner ? '<span class="confirm-no">負責人本次未出席</span>' : '<span class="confirm-no">未指派負責人</span>');
        h += '<tr><td style="text-align:center;">'+(i+1)+'</td>'
           + '<td><textarea onchange="itemEdit(\''+kind+'\','+i+',\'content\',this.value)">'+esc(it.content||'')+'</textarea></td>'
           + '<td><input type="date" max="9999-12-31" value="'+esc(it.due_date||'')+'" onchange="itemEdit(\''+kind+'\','+i+',\'due_date\',this.value)"></td>'
           + '<td>'+ownerPickHtml(kind,i,it)+'</td>'
           + '<td><input type="text" maxlength="200" value="'+esc(it.remark||'')+'" onchange="itemEdit(\''+kind+'\','+i+',\'remark\',this.value)" placeholder="'+confirmTxt.replace(/<[^>]+>/g,'')+'"></td>'
           + '<td><span class="att-del" onclick="itemDel(\''+kind+'\','+i+')"><i class="fa fa-times"></i></span></td></tr>';
    });
    $(itemBodySel(kind)).html(h || '<tr><td colspan="6" style="color:#8a6d45;padding:6px;text-align:center;">尚未建立項目</td></tr>');
    refreshEdSubmitBtn();
}
/* 負責人二擇一(2026-08-05使用者明確要求)：部門模式(自動判定主要角色主管優先→兼任主管→職稱排序最高者)，
   或指定人員模式(直接指名，本次只要有出席就是必簽者，完全取代部門判定)；用切換連結決定畫哪一種挑選器。 */
/* owner_mode 是畫面切換用的明確狀態(2026-08-06修正)：舊版沒有這個欄位，純粹用「owner_users是否有值」反推目前該顯示
   哪種挑選器，導致切換成指定人員模式後(此時owner_users還是空陣列)，mode又被反推回dept、畫面沒有真的換成人員挑選器，
   使用者只看到部門被清空、以為按鈕沒反應。owner_mode 只是前端顯示用的暫存狀態，不會送給後端(後端仍以陣列內容判斷)。 */
function ownerPickHtml(kind,i,it){
    var mode = it.owner_mode || ((it.owner_users&&it.owner_users.length) ? 'user' : 'dept');
    var toggle = '<a href="javascript:void(0)" style="font-size:11px;display:block;" onclick="toggleOwnerMode(\''+kind+'\','+i+')">切換：'+(mode==='dept'?'改指定人員':'改選部門')+'</a>';
    return toggle + (mode==='user' ? userPickHtml(kind,i,it.owner_users||[]) : deptPickHtml(kind,i,it.owner_depts||[]));
}
function toggleOwnerMode(kind,i){
    var a = itemsArr(kind)[i]; if (!a) return;
    var curMode = a.owner_mode || ((a.owner_users&&a.owner_users.length) ? 'user' : 'dept');
    // 切換模式時清空另一種資料重新挑選，避免存檔時殘留(後端也會以owner_users有值時完全取代owner_depts，這裡雙保險)
    if (curMode === 'user') { a.owner_mode = 'dept'; a.owner_users = []; a.owner_depts = a.owner_depts || []; }
    else { a.owner_mode = 'user'; a.owner_depts = []; a.owner_users = a.owner_users || []; }
    renderItems(kind);
}
function deptPickHtml(kind,i,ids){
    var tags = '';
    ids.forEach(function(id){ var d=deptById(id); if(d) tags += '<span class="tg">'+esc(d.name)+'<i class="fa fa-times" onclick="itmDeptDel(\''+kind+'\','+i+',\''+id+'\')"></i></span>'; });
    return '<div class="dp-pick itm-dp" data-kind="'+kind+'" data-i="'+i+'"><div class="dp-tags">'+tags+'</div>'
         + '<input type="text" class="itm-dp-kw" placeholder="選部門…" data-eg-skip autocomplete="off"><div class="dp-list"></div></div>';
}
function itmDeptDel(kind,i,id){
    var a = itemsArr(kind)[i]; if (!a) return;
    a.owner_depts = a.owner_depts.filter(function(x){ return String(x)!==String(id); });
    renderItems(kind);
}
$(document).on('focus input', '.itm-dp-kw', function(){
    var $pick = $(this).closest('.itm-dp'), kind = $pick.data('kind'), i = $pick.data('i');
    var a = itemsArr(kind)[i]; if (!a) return;
    var kw = $.trim($(this).val()).toLowerCase(), h = '';
    DEPTS.forEach(function(d){
        if (kw && d.name.toLowerCase().indexOf(kw)<0) return;
        var on = (a.owner_depts||[]).some(function(x){ return String(x)===String(d.id); });
        h += '<div data-id="'+d.id+'" style="'+(on?'color:#b0a390;':'')+'">'+(on?'✔ ':'')+esc(d.name)+'</div>';
    });
    $pick.find('.dp-list').html(h || '<div style="color:#b0a390;">查無部門</div>').show();
});
$(document).on('click', '.itm-dp .dp-list div[data-id]', function(){
    var $pick = $(this).closest('.itm-dp'), kind = $pick.data('kind'), i = $pick.data('i'), id = String($(this).data('id'));
    var a = itemsArr(kind)[i]; if (!a) return;
    a.owner_depts = a.owner_depts || [];
    var idx = a.owner_depts.findIndex(function(x){ return String(x)===id; });
    if (idx>=0) a.owner_depts.splice(idx,1); else a.owner_depts.push(id);
    renderItems(kind);
});
/* 指定人員挑選器：與部門挑選器同一套UI模式(標籤+打字篩選)，資料來源改用全員清單 ALL_PEOPLE(超過10筆一律可打字篩選，鐵則見ai-rules/08)。 */
function personById(id){ for (var i=0;i<ALL_PEOPLE.length;i++) if (String(ALL_PEOPLE[i].id)===String(id)) return ALL_PEOPLE[i]; return null; }
/* 「負責人/部門」欄顯示文字：指定人員模式列人名，部門模式列部門名(二擇一，owner_users有值時優先)。 */
function ownerDisplayText(it){
    var ownerUsers = it.owner_users ? String(it.owner_users).split(',') : [];
    if (ownerUsers.length) {
        return ownerUsers.map(function(id){ var p=personById(id); return p?p.user_cname:''; }).filter(Boolean).join('、');
    }
    return (it.owner_depts?String(it.owner_depts).split(','):[]).map(function(id){ var d=deptById(id); return d?d.name:''; }).filter(Boolean).join('、');
}
function userPickHtml(kind,i,ids){
    var tags = '';
    ids.forEach(function(id){ var p=personById(id); if(p) tags += '<span class="tg">'+esc(p.user_cname)+(p.dept_name?'('+esc(p.dept_name)+')':'')+'<i class="fa fa-times" onclick="itmUserDel(\''+kind+'\','+i+',\''+id+'\')"></i></span>'; });
    return '<div class="dp-pick itm-up" data-kind="'+kind+'" data-i="'+i+'"><div class="dp-tags">'+tags+'</div>'
         + '<input type="text" class="itm-up-kw" placeholder="搜尋人員姓名…" data-eg-skip autocomplete="off"><div class="dp-list"></div></div>';
}
function itmUserDel(kind,i,id){
    var a = itemsArr(kind)[i]; if (!a) return;
    a.owner_users = a.owner_users.filter(function(x){ return String(x)!==String(id); });
    renderItems(kind);
}
$(document).on('focus input', '.itm-up-kw', function(){
    var $pick = $(this).closest('.itm-up'), kind = $pick.data('kind'), i = $pick.data('i');
    var a = itemsArr(kind)[i]; if (!a) return;
    var kw = $.trim($(this).val()).toLowerCase(), h = '', n = 0;
    ALL_PEOPLE.forEach(function(p){
        if (n >= 30) return; // 全員清單可能上百筆，篩選後只列前30筆避免卡頓，打更精確的關鍵字即可縮小範圍
        if (kw && p.user_cname.toLowerCase().indexOf(kw)<0) return;
        var on = (a.owner_users||[]).some(function(x){ return String(x)===String(p.id); });
        h += '<div data-id="'+p.id+'" style="'+(on?'color:#b0a390;':'')+'">'+(on?'✔ ':'')+esc(p.user_cname)+(p.dept_name?'（'+esc(p.dept_name)+'）':'')+'</div>';
        n++;
    });
    $pick.find('.dp-list').html(h || '<div style="color:#b0a390;">查無人員</div>').show();
});
$(document).on('click', '.itm-up .dp-list div[data-id]', function(){
    var $pick = $(this).closest('.itm-up'), kind = $pick.data('kind'), i = $pick.data('i'), id = String($(this).data('id'));
    var a = itemsArr(kind)[i]; if (!a) return;
    a.owner_users = a.owner_users || [];
    var idx = a.owner_users.findIndex(function(x){ return String(x)===id; });
    if (idx>=0) a.owner_users.splice(idx,1); else a.owner_users.push(id);
    renderItems(kind);
});
$(document).on('click', function(e){ if (!$(e.target).closest('.itm-up').length) $('.itm-up .dp-list').hide(); });
$(document).on('click', function(e){ if (!$(e.target).closest('.itm-dp').length) $('.itm-dp .dp-list').hide(); });

/* 出貨目標達成率快照：內容與 Shipping_Analysis_new.php 的「月份出貨KPI週報」完全相同(4週明細+合計+大額前三名)，
   共用同一份 kpi_lib.php 算出的資料結構，畫面/檢視/列印三處都呼叫這支 kpiReportHtml() 產生內容，避免各刻一份對不起來。 */
function kpiReportHtml(k){
    var rows = (k.weeks||[]).map(function(w){
        var chg = (w.change_rate===null || w.change_rate===undefined) ? '—' : ((w.change_rate>=0?'▲':'▼')+Math.abs(w.change_rate)+'%');
        return '<tr><td>W'+w.no+'　'+fmtDate(w.start)+'~'+fmtDate(w.end)+'</td>'
             + '<td>'+w.week_target.toLocaleString()+'</td>'
             + '<td>'+w.order_amount.toLocaleString()+'</td><td>'+w.order_rate+'%</td>'
             + '<td>'+w.ship_amount.toLocaleString()+'</td><td>'+w.return_amount.toLocaleString()+'</td>'
             + '<td>'+w.revenue.toLocaleString()+'</td><td>'+w.cum_revenue.toLocaleString()+'</td>'
             + '<td><b>'+w.revenue_rate+'%</b></td><td>'+chg+'</td></tr>';
    }).join('');
    var t = k.totals || {};
    var totalRow = '<tr class="kpi-total-row"><td>合計</td><td>'+(t.cum_target||0).toLocaleString()+'</td>'
        + '<td>'+(t.order_amount||0).toLocaleString()+'</td><td>'+(t.order_rate||0)+'%</td>'
        + '<td>'+(t.ship_amount||0).toLocaleString()+'</td><td>'+(t.return_amount||0).toLocaleString()+'</td>'
        + '<td>'+(t.revenue||0).toLocaleString()+'</td><td>—</td><td>'+(t.revenue_rate||0)+'%</td><td>—</td></tr>';
    function top3(list, label, color){
        if (!list || !list.length) return '';
        var r = list.map(function(x,i){
            var d = x.Order_date || x.date || '';
            return '<tr><td>'+(i+1)+'</td><td>'+fmtDate(d)+'</td><td>'+esc(x.Client_name||'')+'</td><td>'+esc(x.Product_id||'')+'</td><td style="text-align:right;">'+Number(x.amount).toLocaleString()+'</td></tr>';
        }).join('');
        return '<div class="kpi-top3" style="border-left:3px solid '+color+';">'
             + '<div class="kpi-top3-tt" style="color:'+color+';">'+label+'</div>'
             + '<table class="kpi-top3-tbl"><tr><th>NO</th><th>日期</th><th>客戶</th><th>料號</th><th>金額</th></tr>'+r+'</table></div>';
    }
    return '<div class="kpi-meta"><b>帳款月：</b>'+esc(k.billing_start)+' ~ '+esc(k.billing_end)
        + '　<b>資料基準日：</b>'+esc(META.today||'')
        + (k.ship_latest ? ('　<b>出貨單最新日期：</b>'+esc(k.ship_latest)) : '')
        + (k.return_latest ? ('　<b>退貨單最新日期：</b>'+esc(k.return_latest)) : '') + '</div>'
        + '<table class="kpi-week-tbl"><tr><th>週別／期間</th><th>期目標</th><th>接單金額</th><th>接單達成率</th>'
        + '<th>出貨金額</th><th>退貨金額</th><th>當期營收</th><th>累計營收</th><th>目標達成率</th><th>與上月同週</th></tr>'
        + rows + totalRow + '</table>'
        + '<div class="kpi-top3-wrap">'
        + top3(k.top_ship, '出貨大額前三名', '#3498db') + top3(k.top_order, '訂單大額前三名', '#27ae60') + top3(k.top_return, '退貨大額前三名', '#e74c3c')
        + '</div>';
}
function kpiCss(){
    return '.kpi-meta{font-size:12px;margin-bottom:4px;}'
        + '.kpi-week-tbl{width:100%;border-collapse:collapse;font-size:11.5px;}'
        + '.kpi-week-tbl th,.kpi-week-tbl td{border:1px solid #999;padding:4px 6px;text-align:center;}'
        + '.kpi-total-row{font-weight:bold;background:#FFF3DE;}'
        + '.kpi-top3-wrap{display:flex;gap:10px;margin-top:8px;flex-wrap:wrap;}'
        + '.kpi-top3{flex:1 1 220px;padding-left:6px;}'
        + '.kpi-top3-tt{font-weight:bold;font-size:12px;margin-bottom:3px;}'
        + '.kpi-top3-tbl{width:100%;border-collapse:collapse;font-size:11px;}'
        + '.kpi-top3-tbl th,.kpi-top3-tbl td{border:1px solid #999;padding:3px 5px;text-align:center;}';
}
function renderKpiBox(){
    var h = '';
    if (KPI_SNAP) {
        h += '<div class="kpi-box">' + kpiReportHtml(KPI_SNAP)
           + '<div style="margin-top:6px;"><button type="button" class="b-att wt" onclick="kpiRemove()"><i class="fa fa-times"></i> 移除</button></div></div>';
    } else {
        h += '<button type="button" class="b-att" onclick="kpiInsert()"><i class="fa fa-line-chart"></i> 插入本月出貨目標達成率</button>'
           + '<span style="font-size:11.5px;color:#8a6d45;margin-left:8px;">會先確認出貨資料已更新至前一個工作天，未達標會提示，不會插入不完整的數字。</span>';
    }
    $('#kpiBox').html(h);
}
function kpiInsert(){
    if (!EDIT_ID){ alert('請先「存草稿」建立會議記錄後再插入'); return; }
    $.post(API, {action:'kpi_insert', meeting_id:EDIT_ID}, function(res){
        if (!res.ok){ alert(res.error||'插入失敗'); return; }
        KPI_SNAP = res.snapshot; renderKpiBox();
    }, 'json').fail(function(x){ alert(x.responseJSON&&x.responseJSON.error || '插入失敗'); });
}
function kpiRemove(){
    if (!EDIT_ID) { KPI_SNAP = null; renderKpiBox(); return; }
    $.post(API, {action:'kpi_remove', meeting_id:EDIT_ID}, function(res){
        if (!res.ok){ alert(res.error||'移除失敗'); return; }
        KPI_SNAP = null; renderKpiBox();
    }, 'json');
}
/* 已完成核准(done)後插入/更新出貨目標達成率(檢視畫面用，非編輯表單)：一般人會清空簽核改回草稿，超級管理員維持已核准。 */
function kpiInsertAfterDone(mid){
    if (!confirm(META.is_superadmin
        ? '確定要插入/更新出貨目標達成率？（超級管理員身分：將維持已核准狀態，不需重新送審）'
        : '確定要插入/更新出貨目標達成率？此會議記錄已完成核准，插入後會清空目前簽核紀錄、改回草稿狀態，需要重新送出取得主席／總經理簽章。')) return;
    $.post(API, {action:'kpi_insert', meeting_id:mid}, function(res){
        if (!res.ok){ alert(res.error||'插入失敗'); return; }
        if (res.reset_note) alert(res.reset_note);
        openView(mid); loadList();
    }, 'json').fail(function(x){ alert(x.responseJSON&&x.responseJSON.error || '插入失敗'); });
}

/* 存草稿／送出 */
function gatherPayload(){
    return {
        action:'save', meeting_id: EDIT_ID, subject: $('#edSubject').val(), meeting_date: $('#edDate').val(),
        start_time: $('#edStart').val(), end_time: $('#edEnd').val(), location: $('#edLoc').val(),
        chair_user_id: $('#edChair').val(), recorder_name: $('#edRecorder').val(),
        attendees: JSON.stringify(ATT),
        items: JSON.stringify(ITEMS_A.map(function(it){ return $.extend({kind:'announce'}, it); })
                    .concat(ITEMS_D.map(function(it){ return $.extend({kind:'directive'}, it); }))
                    .concat(ITEMS_G.map(function(it){ return $.extend({kind:'general'}, it); }))),
        temp_attach_ids: TEMP_ATTACH_IDS.join(',')
    };
}
/* ---------- 附件（手動輸入類型/說明，草稿階段先暫存 meeting_id=0，存檔時轉正） ---------- */
function renderEdAttach(){
    var h='';
    (EDIT_ATTACHES||[]).forEach(function(a){
        h+='<div style="display:flex;gap:8px;align-items:center;border-bottom:1px dashed #EADFC8;padding:3px 0;">';
        h+=a.exists?'<a href="'+API+'?action=download_attach&attach_id='+a.attach_id+'" target="_blank" style="color:#b5762a;flex:1;">📄 '+esc(a.original_name||'')+'</a>'
                   :'<span style="color:#c9bda9;text-decoration:line-through;flex:1;">📄 '+esc(a.original_name||'')+'(檔案不存在)</span>';
        h+='<span style="color:#8a6d45;font-size:11px;">'+esc(a.attach_type||'')+'　'+esc(a.created_by_name||'')+'</span>';
        h+='<span class="att-del" style="cursor:pointer;" onclick="edDelAttach('+a.attach_id+')"><i class="fa fa-trash"></i></span></div>';
    });
    $('#edAttachList').html(h||'<span style="color:#8a6d45;">尚無附件</span>');
}
function edUploadAttach(){
    var f=document.getElementById('edAttachFile');
    if(!f.files.length){ alert('請選擇檔案'); return; }
    var fd=new FormData();
    fd.append('action','attach_upload'); fd.append('meeting_id', EDIT_ID||0);
    fd.append('attach_type', $('#edAttachType').val()); fd.append('file', f.files[0]);
    $.ajax({url:API,method:'POST',data:fd,processData:false,contentType:false,dataType:'json'})
     .done(function(res){
        if(!res.ok){ alert(res.error||'上傳失敗'); return; }
        if (!EDIT_ID) TEMP_ATTACH_IDS.push(res.attach_id);
        EDIT_ATTACHES.push({attach_id:res.attach_id, original_name:f.files[0].name, attach_type:$('#edAttachType').val(), exists:true, created_by_name:META.uname});
        f.value=''; $('#edAttachType').val(''); renderEdAttach();
     })
     .fail(function(x){ alert('上傳失敗：'+(x.responseJSON&&x.responseJSON.error||x.status)); });
}
function edDelAttach(aid){
    if(!confirm('刪除此附件？')) return;
    $.post(API,{action:'attach_delete',attach_id:aid},function(res){
        if(!res.ok){ alert(res.error||'刪除失敗'); return; }
        EDIT_ATTACHES = EDIT_ATTACHES.filter(function(a){ return a.attach_id!==aid; });
        TEMP_ATTACH_IDS = TEMP_ATTACH_IDS.filter(function(x){ return x!==aid; });
        renderEdAttach();
    },'json');
}
/* 送出（送簽核/存檔並通知共用）：一律用 .fail() 接住後端擋下的驗證錯誤(如尚未全部簽到)，避免點下去沒有任何反應。
   後端已不再因負責部門/指定人員尚未現場簽名而擋下送出，改為擴大通知相關人員回簽，pending_items 是還有幾項待回簽。 */
function submitMeeting(id, cb){
    $.post(API, {action:'submit', meeting_id:id}, function(r2){
        if (!r2.ok){ cb(false, r2.error||'送出失敗'); return; }
        var msg = '已送出，已通知主席確認簽章。';
        if (r2.pending_items) msg += '尚有 '+r2.pending_items+' 項負責部門／指定人員未現場簽名，已另行通知相關人員回簽。';
        cb(true, msg);
    }, 'json').fail(function(x){ cb(false, (x.responseJSON&&x.responseJSON.error)||('連線失敗(HTTP '+x.status+')')); });
}
function saveDraft(thenSubmit){
    if (!$.trim($('#edSubject').val())){ setErr($('#edSubject'),'errEdSubject','請輸入會議主題'); return; }
    setErr($('#edSubject'),'errEdSubject','');
    if (!$('#edDate').val()){ setErr($('#edDate'),'errEdDate','請選擇會議日期'); return; }
    setErr($('#edDate'),'errEdDate','');
    if (!edTimeValidate()){ alert('時間欄位有誤，請先修正'); return; }
    if (thenSubmit && !$('#edChair').val()){ alert('送出前請先指定主席'); return; }
    if (thenSubmit && !ATT.length){ alert('送出前請先加入出席人員'); return; }
    $.post(API, gatherPayload(), function(res){
        if (!res.ok){ alert(res.error||'儲存失敗'); return; }
        EDIT_ID = res.meeting_id;
        if (!thenSubmit){ alert('已儲存草稿。'); closeMask('edMask'); loadList(); return; }
        submitMeeting(EDIT_ID, function(ok, msg){
            if (!ok){ alert('草稿已存，但送出失敗：'+msg); closeMask('edMask'); loadList(); return; }
            alert(msg); closeMask('edMask'); loadList();
        });
    }, 'json').fail(function(x){ alert('儲存失敗：'+(x.responseJSON&&x.responseJSON.error||x.status)); });
}

/* ---------- 檢視／簽到／簽核／確認 ---------- */
var VIEW = null;
function openView(id){
    $.getJSON(API, {action:'get_detail', meeting_id:id}, function(res){
        if (!res.ok){ alert(res.error||'載入失敗'); return; }
        VIEW = res;
        $('#viewTitle').text(res.meeting.subject);
        $('#viewBody').html(viewHtml(res));
        $('#btnPrintKpi').toggle(!!res.meeting.kpi_snapshot_json);
        var allSigned = (res.attendees||[]).length>0 && (res.attendees||[]).every(function(a){ return +a.signed===1; });
        $('#btnPrintSignedSheet').toggle(allSigned);
        openMask('viewMask');
    });
}
function viewHtml(res){
    var m = res.meeting, ext = false;
    var h = '<div class="kv"><b>主題：</b>'+esc(m.subject)+'　<b>日期：</b>'+fmtDate(m.meeting_date)
        + (m.start_time?('　<b>時間：</b>'+esc(m.start_time)+(m.end_time?'~'+esc(m.end_time):'')):'')
        + '　<b>地點：</b>'+esc(m.location||'—')+'</div>'
        + '<div class="kv"><b>主席：</b>'+esc(m.chair_name||'—')+'　<b>記錄：</b>'+esc(m.recorder_name||'')
        + '　<b>狀態：</b><span class="st-pill st-'+m.approval_status+'">'+(STATUS_LABEL[m.approval_status]||m.approval_status)+'</span></div>';

    h += '<h5>出席人員／簽到</h5><table><tr><th>部門</th><th>職稱</th><th>姓名</th><th>簽到</th></tr>';
    (res.attendees||[]).forEach(function(a){
        var signed = +a.signed === 1;
        h += '<tr data-uid="'+a.user_id+'"><td>'+esc(a.dept_name||'')+'</td><td>'+esc(a.position_name||'')+'</td>'
           + '<td>'+esc(a.user_name||'')+'</td>'
           + '<td>'+(signed ? '<span class="sign-ok">'+((window.EGStamp&&EGStamp.stamp)?EGStamp.stamp(a.user_name,fmtDate(m.meeting_date),false,mStampSchema(),a.dept_name,a.position_name):'<i class="fa fa-check"></i>')
                + ' <span style="font-size:11px;" title="實際簽到時間(僅供稽核，蓋章日期一律採會議日期)">'+esc(String(a.signed_at||'').substr(0,16))+'</span>'
                + (META.is_superadmin?' <a href="javascript:void(0)" onclick="adminBackfillRow(\'attendee\','+a.att_id+')" style="font-size:11px;">[改日期/補簽]</a>':'')+'</span>'
                : '<span class="sign-row"><input type="password" placeholder="本人密碼，按Enter簽到" id="pw-'+a.user_id+'" data-eg-skip'
                  + ' onkeydown="if(event.key===\'Enter\'){event.preventDefault();signAttendee('+m.meeting_id+','+a.user_id+');}">'
                  + (META.is_superadmin?' <a href="javascript:void(0)" onclick="adminBackfillRow(\'attendee\','+a.att_id+')" style="font-size:11px;">[超管補簽]</a>':'')
                  + '</span>')+'</td></tr>';
    });
    h += '</table>';

    function itemsTable(kind, title) {
        var rows = (res.items||[]).filter(function(it){ return it.kind===kind; });
        if (!rows.length) return '';
        var t = '<h5>'+title+'</h5><table><tr><th>序</th><th>報告要點及決議事項</th><th>應完成日期</th><th>負責人/部門</th><th>確認簽名／回簽狀態</th><th>備註</th>'
              + (m.approval_status==='chair_done'||m.approval_status==='done' ? '<th>總經理意見</th>' : '') + '</tr>';
        rows.forEach(function(it, idx){
            var deptNames = ownerDisplayText(it);
            t += '<tr><td>'+(idx+1)+'</td><td>'+esc(it.content).replace(/\n/g,'<br>')+'</td><td>'+fmtDate(it.due_date)+'</td>'
               + '<td>'+esc(deptNames||'—')+'</td>'
               + '<td>'+itemConfirmCellHtml(it)+'</td>'
               + '<td>'+esc(it.remark||'')+'</td>'
               + (m.approval_status==='chair_done'||m.approval_status==='done' ? '<td>'+esc(it.gm_comment||'')+'</td>' : '') + '</tr>';
        });
        return t + '</table>';
    }
    h += itemsTable('directive','上級指示要項') + itemsTable('general','會議要項');

    if (m.kpi_snapshot_json) {
        h += '<h5>出貨目標達成率</h5>' + kpiReportHtml(JSON.parse(m.kpi_snapshot_json));
    }
    // 已完成核准(done)後仍可插入/更新出貨目標達成率(2026-08-06使用者明確要求)：一般人插入後會清掉舊簽核改回草稿需重新送審；
    // 超級管理員插入後維持已核准狀態，不必重跑簽核。草稿/退回階段的插入按鈕在編輯畫面(renderKpiBox)，這裡只處理done之後的情況。
    if (m.approval_status==='done' && PERMS.canKpiInsert && (+m.recorder_user_id===META.uid || PERMS.canAdmin)) {
        h += '<div style="margin-top:8px;"><button type="button" class="b-att" onclick="kpiInsertAfterDone('+m.meeting_id+')"><i class="fa fa-line-chart"></i> '
           + (m.kpi_snapshot_json?'更新':'插入') + '出貨目標達成率</button>'
           + '<span style="font-size:11px;color:#8a6d45;margin-left:6px;">'
           + (META.is_superadmin ? '超級管理員身分：插入後維持已核准狀態，不需重新送審' : '插入後會清空目前簽核紀錄改回草稿，需重新送出取得簽章')
           + '</span></div>';
    }

    // 送簽核／存檔並通知(2026-08-06使用者明確要求)：避免現場簽到/項目確認都在檢視畫面完成後，
    // 還要跳回編輯畫面才能送出。全部出席人員簽到才能送出；負責部門/指定人員若還沒簽完，
    // 按鈕改標「存檔並通知」，送出後會另行擴大通知相關人員回簽（不再擋下送出）。
    if (m.can_edit) {
        var rdy = mtReadinessFromView(res);
        if (!rdy.allSigned) {
            h += '<div class="mt-hint">尚有出席人員未完成現場簽到，全部簽到後才能送出。</div>';
        } else {
            h += '<div style="margin-top:10px;">'
               + '<button type="button" class="b-att" onclick="viewSubmit('+m.meeting_id+')">'
               + (rdy.pending>0 ? '<i class="fa fa-bullhorn"></i> 存檔並通知' : '<i class="fa fa-paper-plane"></i> 送簽核') + '</button>'
               + (rdy.pending>0 ? '<span style="font-size:11px;color:#8a6d45;margin-left:8px;">尚有 '+rdy.pending+' 項負責部門／指定人員未現場簽名，送出後將另行通知相關人員回簽。</span>' : '')
               + '</div>';
        }
    }

    // 主席／總經理簽核區
    if (m.approval_status==='submitted' && (+m.chair_signer_id===META.uid || PERMS.canAdmin)) {
        h += decideBoxHtml(m.meeting_id, 'chair', '主席確認簽章', false);
    } else if (m.approval_status==='chair_done' && (+m.gm_signer_id===META.uid || PERMS.canAdmin)) {
        h += decideBoxHtml(m.meeting_id, 'gm', '總經理確認簽章', true);
    }
    // 撤回：僅「待主席簽章且尚未任何人簽核」時、記錄人本人或管理員可撤回(退回draft修改後重新送出)
    if (m.approval_status==='submitted' && (+m.recorder_user_id===META.uid || PERMS.canAdmin)) {
        h += '<div style="margin-top:10px;"><button type="button" class="b-att wt" style="color:#DD5138;border-color:#DD5138;" onclick="withdrawMeeting('+m.meeting_id+')"><i class="fa fa-reply"></i> 撤回（送錯了/送早了，改回草稿）</button></div>';
    }
    // 超級管理員：補齊/修改主席/總經理簽核日期＋一鍵補齊整場(2026-08-05使用者明確要求)
    if (META.is_superadmin) {
        h += '<div style="margin-top:12px;padding:8px;border:1px dashed #c9a06a;font-size:12px;">'
           + '<b>超級管理員工具</b>　'
           + '主席：'+(m.chair_approval?esc(m.chair_approval.approver_name||'')+'（'+esc(String(m.chair_approval.decided_at||'').substr(0,10)||'待簽')+'）':'（尚未送出）')
                + ' <a href="javascript:void(0)" onclick="adminBackfillRow(\'chair\',0)">[改日期/補簽]</a>'
           + '　總經理：'+(m.gm_approval?esc(m.gm_approval.approver_name||'')+'（'+esc(String(m.gm_approval.decided_at||'').substr(0,10)||'待簽')+'）':'（尚未送出）')
                + ' <a href="javascript:void(0)" onclick="adminBackfillRow(\'gm\',0)">[改日期/補簽]</a>'
           + '　<button type="button" class="b-att" onclick="adminBackfillAll()" style="margin-left:8px;"><i class="fa fa-magic"></i> 一鍵補齊全部簽章日期</button>'
           + '</div>';
    }
    return h;
}
/* 檢視畫面用的送出就緒判斷：資料來自 get_detail 回傳的伺服器現況(比編輯畫面的 mtReadiness 準確，不會有前端暫存過期問題)。 */
function mtReadinessFromView(res){
    var atts = res.attendees||[];
    var allSigned = atts.length>0 && atts.every(function(a){ return +a.signed===1; });
    var pending = (res.items||[]).filter(function(it){ return it.kind!=='announce'; }).filter(function(it){
        if (!it.owner_depts && !it.owner_users) return false;
        var slots = it.confirm_slots||[];
        return !(slots.length>0 && slots.every(function(s){ return s.signed; }));
    }).length;
    return {allSigned:allSigned, pending:pending};
}
/* 檢視畫面直接送出(2026-08-06使用者明確要求)：內容已存檔，不需再gather表單，直接呼叫 submit 動作即可。 */
function viewSubmit(mid){
    if (!confirm('確定送出？送出後將鎖定內容，並通知主席確認簽章。')) return;
    submitMeeting(mid, function(ok, msg){
        if (!ok){ alert('送出失敗：'+msg); return; }
        alert(msg); closeMask('viewMask'); loadList();
    });
}
function withdrawMeeting(mid){
    if (!confirm('確定撤回？將取消目前的主席待簽通知，退回草稿狀態，可修改後重新送出。')) return;
    $.post(API, {action:'withdraw', meeting_id:mid}, function(res){
        if (!res.ok){ alert(res.error||'撤回失敗'); return; }
        alert('已撤回，改為草稿狀態。'); closeMask('viewMask'); loadList();
    }, 'json').fail(function(x){ alert(x.responseJSON&&x.responseJSON.error || '撤回失敗'); });
}
function decideBoxHtml(mid, level, title, withItemComments){
    var h = '<div class="decide-box"><b>'+title+'</b>';
    if (withItemComments) {
        h += '<div style="font-size:11.5px;color:#8a6d45;margin-top:4px;">可於上方各項目表格填寫個別意見，或在下方填整體意見後一併簽章。</div>';
    }
    h += '<textarea id="decideNote" placeholder="意見／退回原因（退回必填）"></textarea>'
       + '<div style="margin-top:6px;"><button type="button" class="b-att" onclick="decide('+mid+',\''+level+'\',\'approved\')"><i class="fa fa-check"></i> 確認簽章</button>'
       + ' <button type="button" class="b-att wt" style="color:#DD5138;border-color:#DD5138;" onclick="decide('+mid+',\''+level+'\',\'rejected\')"><i class="fa fa-undo"></i> 退回</button></div></div>';
    return h;
}
function signAttendee(mid, uidv){
    var pw = $('#pw-'+uidv).val();
    if (!pw){ alert('請輸入密碼'); return; }
    $.post(API, {action:'sign', meeting_id:mid, user_id:uidv, password:pw}, function(res){
        if (!res.ok){ alert(res.error||'簽到失敗'); $('#pw-'+uidv).val('').select(); return; }
        // 局部更新：不整個重繪(openView)避免打斷聚焦體驗，直接換掉該列＋自動蓋章＋跳到下一位未簽到欄位
        var att = (VIEW.attendees||[]).filter(function(a){ return String(a.user_id)===String(uidv); })[0];
        var nowStr = new Date().toISOString().slice(0,16).replace('T',' ');
        if (att) { att.signed = 1; att.signed_at = nowStr; }
        var stampHtml = (window.EGStamp && EGStamp.stamp) ? EGStamp.stamp(att?att.user_name:'', fmtDate(VIEW.meeting.meeting_date), false, mStampSchema(), att?att.dept_name:'', att?att.position_name:'') : '<i class="fa fa-check"></i>';
        $('tr[data-uid="'+uidv+'"] td:last-child').html('<span class="sign-ok">'+stampHtml+' <span style="font-size:11px;">'+esc(nowStr)+'</span></span>');
        var next = (VIEW.attendees||[]).filter(function(a){ return !(+a.signed); })[0];
        if (next) setTimeout(function(){ $('#pw-'+next.user_id).focus(); }, 30);
        else alert('全員已簽到');
    }, 'json').fail(function(x){ alert(x.responseJSON&&x.responseJSON.error || '簽到失敗'); $('#pw-'+uidv).val('').select(); });
}
/* 部門指派項目「確認簽名」(2026-08-05改版，使用者明確要求)：每個負責部門各一格簽名槽，該部門本次有出席的主管優先、
   沒主管出席才由代表簽(後端 meeting_item_required_signers 算出，前端不用自己挑人)；限被算出的那位本人現場輸入密碼確認
   (比照簽到表密碼驗證，避免共用裝置分不清是誰簽的)。負責部門本次完全沒人出席時無簽名槽，改走送出會議記錄時自動發出的
   通知系統回簽，狀態一併顯示在同一格內。 */
function slotTag(s){ return !s.is_manager ? '(代)' : ''; }
function itemConfirmCellHtml(it){
    var slots = it.confirm_slots || [];
    var h = slots.map(function(s){
        if (s.signed) {
            // 2026-08-06改版：實際簽名者不一定是系統原本挑出的那位代表(部門任一出席人員/主管透過通知回覆都算數)，
            // s.user_name 已是後端依 dept_id 比對出的實際簽名人；有 reply_content 代表是透過通知回覆完成，顯示在下方。
            return '<div class="confirm-yes">'+((window.EGStamp&&EGStamp.stamp)?EGStamp.stamp(s.user_name, String(s.confirmed_at||'').substr(0,10), false, mStampSchema(), s.dept_name):esc(s.user_name))
                 + ' <span style="font-size:11px;">'+esc(s.dept_name||'')+slotTag(s)+'</span>'
                 + (META.is_superadmin?' <a href="javascript:void(0)" onclick="adminBackfillRow(\'item\','+it.item_id+')" style="font-size:11px;">[改日期]</a>':'')+'</div>'
                 + (s.reply_content ? '<div style="font-size:11px;color:#5b3a1e;margin-top:2px;">💬 '+esc(s.reply_content)+'</div>' : '');
        }
        return '<div class="item-confirm-box"><span style="font-size:11px;">'+esc(s.dept_name||'')+'：'+esc(s.user_name)+slotTag(s)+'</span>'
             + '<input type="password" id="pwConfirm'+it.item_id+'_'+s.user_id+'" placeholder="密碼" style="width:70px;" data-eg-skip'
             + ' onkeydown="if(event.key===\'Enter\'){event.preventDefault();confirmItemWithPassword('+it.item_id+','+s.user_id+');}">'
             + '<button type="button" onclick="confirmItemWithPassword('+it.item_id+','+s.user_id+')">確認</button></div>';
    }).join('');
    var nt = it.notify_targets || [];
    if (nt.length) {
        h += '<div class="item-notify-status">' + nt.map(function(t){
            var st = t.replied_at ? ('已回覆 '+String(t.replied_at).substr(0,10)) : (t.read_at ? '已閱未回覆' : '未讀');
            return esc(t.user_name)+'：'+st + (t.reply_content ? ('<div style="margin-top:2px;">💬 '+esc(t.reply_content)+'</div>') : '');
        }).join('　') + '</div>';
    }
    if (META.is_superadmin) h += '<div><a href="javascript:void(0)" onclick="adminBackfillRow(\'item\','+it.item_id+')" style="font-size:11px;">[超管補齊此項目]</a></div>';
    if (!h) h = '<span class="confirm-no">—</span>';
    return h;
}
/* 超級管理員：補齊/修改單一列(出席簽到或項目確認)的簽章日期，未簽者一併視同補簽(2026-08-05使用者明確要求)。
   密碼沿用同一次檢視期間內輸入過的值(ADMIN_PW)，避免每列都要重打一次；日期預設帶會議日期，可自行修改。 */
var ADMIN_PW = '';
function adminBackfillRow(scope, targetId){
    var m = VIEW.meeting;
    if (!ADMIN_PW) ADMIN_PW = prompt('請輸入超級管理員密碼（本次檢視期間內補簽/改日期共用，重新整理後需再輸入一次）：') || '';
    if (!ADMIN_PW) return;
    var date = prompt('請輸入日期(YYYY-MM-DD)：', m.meeting_date ? fmtDate(m.meeting_date) : '');
    if (!date) return;
    if (!/^\d{4}-\d{2}-\d{2}$/.test(date)) { alert('日期格式不正確'); return; }
    $.post(API, {action:'admin_backfill', meeting_id:m.meeting_id, password:ADMIN_PW, date:date, scope:scope, target_id:targetId}, function(res){
        if (!res.ok){ alert(res.error||'補登失敗'); ADMIN_PW=''; return; }
        openView(m.meeting_id);
    }, 'json').fail(function(x){ alert(x.responseJSON&&x.responseJSON.error || '補登失敗'); ADMIN_PW=''; });
}
/* 超級管理員：一次補齊整場會議的簽章日期(出席簽到＋項目確認＋主席＋總經理)，同樣視同未簽者一併補簽。 */
function adminBackfillAll(){
    var m = VIEW.meeting;
    if (!confirm('確定要補齊「'+m.subject+'」整場會議的簽到／項目確認／主席／總經理簽章日期？尚未簽核的部分將視同已完成一併補簽（主席／總經理若還沒送出過也會自動送審＋自動核准），此操作會留下稽核紀錄。')) return;
    if (!ADMIN_PW) ADMIN_PW = prompt('請輸入超級管理員密碼：') || '';
    if (!ADMIN_PW) return;
    var date = prompt('請輸入要套用的日期(YYYY-MM-DD)：', m.meeting_date ? fmtDate(m.meeting_date) : '');
    if (!date) return;
    if (!/^\d{4}-\d{2}-\d{2}$/.test(date)) { alert('日期格式不正確'); return; }
    $.post(API, {action:'admin_backfill', meeting_id:m.meeting_id, password:ADMIN_PW, date:date, scope:'all'}, function(res){
        if (!res.ok){ alert(res.error||'補登失敗'); ADMIN_PW=''; return; }
        alert('已補齊。');
        openView(m.meeting_id);
    }, 'json').fail(function(x){ alert(x.responseJSON&&x.responseJSON.error || '補登失敗'); ADMIN_PW=''; });
}
function confirmItemWithPassword(itemId, uidv){
    var pw = $('#pwConfirm'+itemId+'_'+uidv).val();
    if (!pw){ alert('請輸入密碼'); return; }
    $.post(API, {action:'item_confirm', item_id:itemId, user_id:uidv, password:pw}, function(res){
        if (!res.ok){ alert(res.error||'確認失敗'); return; }
        openView(VIEW.meeting.meeting_id);
    }, 'json').fail(function(x){ alert(x.responseJSON&&x.responseJSON.error || '確認失敗'); $('#pwConfirm'+itemId+'_'+uidv).val('').select(); });
}
function decide(mid, level, decision){
    var note = $('#decideNote').val();
    if (decision==='rejected' && !$.trim(note)){ alert('退回必須填寫原因'); return; }
    if (decision==='approved' && !confirm('確定簽章？')) return;
    if (decision==='rejected' && !confirm('確定退回給記錄人修改？')) return;
    $.post(API, {action:'decide', meeting_id:mid, level:level, decision:decision, note:note}, function(res){
        if (!res.ok){ alert(res.error||'處理失敗'); return; }
        alert('已處理。'); closeMask('viewMask'); loadList();
    }, 'json').fail(function(x){ alert(x.responseJSON&&x.responseJSON.error || '處理失敗'); });
}

/* ---------- 列印（不含電子簽章，供現場紙本簽名／掃描） ----------
   showPageCounter(2026-08-06使用者明確要求寫進規則，比照ai-rules/16第二節「多頁才顯示」)：只有一頁的表單不印「第X頁/共Y頁」，
   用 onload 後量測 document.body.scrollHeight 是否超過單頁可用高度，超過才動態插入 @bottom-left 頁碼CSS；
   不傳(undefined)＝預設開啟此判斷；若未來又出現合併多份獨立文件的列印情境，該情境需明確傳 false 整個關掉(彼此不算同一份報表頁數)。 */
function egPrintWindow(title, bodyHtml, extraCss, docNo, landscape, pageCount, showPageCounter){
    if (showPageCounter === undefined) showPageCounter = true;
    var asCss = String(docNo||'').replace(/['\\]/g,'');
    var css = '@page{size:A4 '+(landscape?'landscape':'portrait')+';'
            + (pageCount
                ? 'margin:12mm 8mm 16mm;' + (asCss ? " @bottom-right{ content:'"+asCss+"'; font-size:9pt; color:#333; }" : '')
                : 'margin:0;')
            + '}'
            + (pageCount ? '' : 'html,body{margin:0;padding:0;}')
            + 'body{font-family:"Microsoft JhengHei","微軟正黑體",sans-serif;color:#000;'
            + (pageCount ? '' : 'padding:10mm 8mm 12mm;') + '-webkit-print-color-adjust:exact;print-color-adjust:exact;}'
            + '.pt-head{text-align:center;margin-bottom:6px;}'
            + '.pt-head .co{font-size:22px;font-weight:bold;letter-spacing:2px;}'
            + '.pt-head .tt{font-size:16px;font-weight:bold;margin-top:3px;letter-spacing:1px;}'
            + (extraCss||'');
    var w = window.open('', '_blank');
    if (!w){ alert('請允許彈出視窗'); return; }
    var onloadJs = (pageCount && showPageCounter)
        ? ('var onePageA4=('+(landscape?'210':'297')+'-28)*96/25.4;'
          +'if(document.body.scrollHeight>onePageA4*0.92){'
          +'var st=document.createElement(\'style\');'
          +'st.textContent="@page{ @bottom-left{ content:\'第 \' counter(page) \' 頁／共 \' counter(pages) \' 頁\'; font-size:9pt; color:#333; } }";'
          +'document.head.appendChild(st);}')
        : '';
    w.document.write('<html><head><meta charset="utf-8"><title>'+esc(title)+'</title><style>'+css+'</style></head><body>'
        + bodyHtml
        + '<scr'+'ipt>window.onload=function(){'+onloadJs+'setTimeout(function(){window.print();},200);};</scr'+'ipt></body></html>');
    w.document.close();
}
/* 會議記錄/簽到表 列印共用版面：完全比照公司實體表單附件(2-GM-05-01)，橫式列印。
   主題/日期/主席＋地點/時間/記錄＋出席人員 2列表頭；宣布事項獨立表格(無應完成日期/負責人/確認簽名欄)、與下方
   上級指示要項/會議要項合併表(左側直書跨列標籤)中間留間隔列；底部總經理/主席/製表 三欄簽章，用 flex+margin-top:auto
   固定貼在版面最下方（.mr-page 撐滿版心高度，簽章列自動被推到底，不因項目/KPI內容多寡而移動位置）。 */
function mrCss(){
    return '.mr-page{display:flex;flex-direction:column;min-height:172mm;}'
        + '.pt-head{text-align:center;margin-bottom:8px;}.pt-head .co{font-size:22px;font-weight:bold;letter-spacing:2px;}.pt-head .tt{font-size:16px;font-weight:bold;margin-top:3px;letter-spacing:1px;}'
        + '.mr-title{text-align:center;font-size:20px;font-weight:bold;letter-spacing:2px;margin-bottom:8px;}'
        + 'table.mr-head{width:100%;border-collapse:collapse;font-size:13px;table-layout:fixed;}'
        + 'table.mr-head th{background:#fff;font-weight:bold;border:1px solid #333;padding:5px 6px;width:56px;text-align:center;}'
        + 'table.mr-head td.val{border:1px solid #333;padding:5px 8px;text-align:left;}'
        + 'table.mr-head th.att-lbl{width:64px;}'
        + 'table.mr-head td.att-val{border:1px solid #333;padding:5px 8px;text-align:left;vertical-align:top;width:220px;}'
        + 'table.mr-announce{width:100%;border-collapse:collapse;font-size:12px;margin-top:8px;}'
        + 'table.mr-announce th,table.mr-announce td{border:1px solid #333;padding:5px;text-align:center;}'
        + 'table.mr-announce td.t-left{text-align:left;}'
        + '.mr-gap{height:12px;}'
        + 'table.mr-items{width:100%;border-collapse:collapse;font-size:12px;margin-top:8px;}'
        + 'table.mr-items th,table.mr-items td{border:1px solid #333;padding:5px;text-align:center;}'
        + 'table.mr-items td.t-left{text-align:left;}'
        + 'table.mr-items th.mr-grp-hd{width:32px;}'
        + 'table.mr-items td.mr-grp{width:32px;writing-mode:vertical-rl;text-orientation:upright;font-weight:bold;letter-spacing:4px;}'
        + 'table.mr-foot{width:100%;margin-top:16px;font-size:13px;margin-top:auto;}'
        + 'table.mr-foot td{padding:10px 6px;width:33.33%;}'
        + 'table.mr-foot td.mr-foot-prep{text-align:right;}'
        + '.mr-bottom-note{margin-top:4px;font-size:11px;color:#666;display:flex;justify-content:space-between;}'
        // AS文件編號固定貼在頁面實際右下角(2026-08-06使用者明確要求：不論表格內容長短都要在右下角，不是緊接在表格後面)。
        // Chrome列印對 @page{@bottom-right{content:...}} 支援不穩定(本頁曾實測跑位)，改用 position:fixed 釘住視窗座標；
        // 前提是整次列印工作從頭到尾只對應同一份文件(position:fixed在Chrome列印中的範圍是整個列印工作，不是單一頁面，
        // 混排不同文件會疊字，2026-08-06實測確認)——本頁已把每個列印按鈕都拆成剛好一份文件，不再有混排情境，
        // 故本頁全部用fixed；.mr-bottom-note(內文寫法)仍保留給函式的'inline'模式，供日後若真的需要合併列印時使用。
        + '.as-doc-fixed{position:fixed;right:8mm;bottom:6mm;font-size:9pt;color:#333;}'
        + 'table.ss-head{width:100%;border-collapse:collapse;font-size:13px;margin-bottom:6px;}'
        + 'table.ss-head td{border:1px solid #333;padding:6px 8px;text-align:left;width:50%;}'
        // 字級/列高只有這一份定義，空白簽到表與已簽署簽到表共用同一支 signSheetPageHtml()+這份CSS，兩者格式(含欄位高度)一律相同，
        // 不可分開各自調整(AS文件格式規定表格不可因狀態不同而改變版面)——要調大只在這裡改一次。
        + 'table.sf{width:100%;border-collapse:collapse;font-size:15px;}table.sf th,table.sf td{border:1px solid #333;padding:6px;text-align:center;height:52px;overflow:hidden;}'
        + 'h5{font-size:13px;margin:10px 0 3px;}'
        // 圖章尺寸(ai-rules/18)：有充足空間的簽核欄(主席/總經理/製表)一律91px；密集逐列表格(簽到表/項目確認多人簽章)改用填滿列高比例，不可套固定px
        + '.mr-foot .stamp-wrap svg,.mr-foot svg.car-stamp{width:91px !important;height:91px !important;-webkit-print-color-adjust:exact;print-color-adjust:exact;}'
        + 'table.sf .stamp-wrap,table.mr-items .mr-confirm-cell .stamp-wrap{height:90%;display:inline-flex;align-items:center;margin:0 4px 0 0;}'
        + 'table.sf .stamp-wrap svg,table.sf svg.car-stamp,table.mr-items .mr-confirm-cell .stamp-wrap svg,table.mr-items .mr-confirm-cell svg.car-stamp{height:100%;width:auto;-webkit-print-color-adjust:exact;print-color-adjust:exact;}'
        + 'table.mr-items .mr-confirm-cell{height:44px;overflow:hidden;white-space:nowrap;}'
        + kpiCss();
}
function meetingItemGroupRows(items, kind, groupLabel){
    var rows = (items||[]).filter(function(it){ return it.kind===kind; });
    if (!rows.length) return '';
    return rows.map(function(it,i){
        var deptNames = ownerDisplayText(it);
        var confirmHtml = (it.confirm_slots||[]).filter(function(s){ return s.signed; }).map(function(s){
            var tag = slotTag(s);
            return ((window.EGStamp&&EGStamp.stamp)?EGStamp.stamp(s.user_name, String(s.confirmed_at||'').substr(0,10), false, mStampSchema(), s.dept_name):esc(s.user_name))
                 + (tag?'<span style="font-size:10px;">'+tag+'</span>':'');
        }).join('');
        return '<tr>' + (i===0 ? '<td class="mr-grp" rowspan="'+rows.length+'">'+groupLabel+'</td>' : '')
             + '<td>'+(i+1)+'</td><td class="t-left">'+esc(it.content).replace(/\n/g,'<br>')+'</td>'
             + '<td>'+fmtDate(it.due_date)+'</td><td>'+esc(deptNames)+'</td><td class="mr-confirm-cell">'+confirmHtml+'</td><td>'+esc(it.remark||'')+'</td></tr>';
    }).join('');
}
/* 宣布事項獨立表格用：只有序/內容/備註三欄，不含應完成日期/負責人/確認簽名(該三欄對宣布事項無意義)。 */
function announceItemRows(items){
    return (items||[]).filter(function(it){ return it.kind==='announce'; }).map(function(it,i){
        return '<tr><td>'+(i+1)+'</td><td class="t-left">'+esc(it.content).replace(/\n/g,'<br>')+'</td><td>'+esc(it.remark||'')+'</td></tr>';
    }).join('');
}
/* docNoMode(2026-08-06改版，使用者明確要求)：'fixed'＝單獨列印時AS編號用 position:fixed 釘在頁面實際右下角(不因表格
   內容長短跟著移動，Chrome對@page bottom-right支援不穩定不採用)；'inline'＝合併列印用，因單一列印工作混多份不同編號的
   文件，頁面座標的fixed定位跨頁不穩定，改在版面內文手動印(緊接該份文件內容之後，見.mr-bottom-note)；空字串/未傳＝不印編號。
   簽章列(總經理/主席/製表)用 flex+margin-top:auto 固定貼在版面最下方，不因項目/KPI內容多寡而移動位置(見 .mr-page/.mr-foot 樣式)。 */
function meetingRecordPageHtml(m, res, docNoMode){
    var announceBody = announceItemRows(res.items);
    var itemBody = meetingItemGroupRows(res.items, 'directive', '上級指示要項') + meetingItemGroupRows(res.items, 'general', '會議要項');
    var chairApp = m.chair_approval, gmApp = m.gm_approval;
    var chairStamp = (chairApp && chairApp.approver_name)
        ? ((window.EGStamp&&EGStamp.stamp)?EGStamp.stamp(chairApp.approver_name, String(chairApp.decided_at||'').substr(0,10), String(chairApp.approver_id)!==String(m.chair_user_id)):'')
        : '';
    var gmStamp = (gmApp && gmApp.approver_name)
        ? ((window.EGStamp&&EGStamp.stamp)?EGStamp.stamp(gmApp.approver_name, String(gmApp.decided_at||'').substr(0,10), META.gm_id!=null && String(gmApp.approver_id)!==String(META.gm_id)):'')
        : '';
    var madeStamp = (window.EGStamp&&EGStamp.stamp && m.recorder_name) ? EGStamp.stamp(m.recorder_name, fmtDate(m.meeting_date)) : '';
    var recordTitle = (META.as_doc_record && META.as_doc_record.doc_name) ? META.as_doc_record.doc_name : '會議記錄';
    var attendeeNames = (res.attendees||[]).map(function(a){ return esc(a.user_name); }).join('、') || '—';
    return '<div class="mr-page">'
        + '<div class="mr-title">'+esc(META.company_name||'')+'-'+esc(recordTitle)+'</div>'
        + '<table class="mr-head">'
        + '<tr><th>主題</th><td class="val">'+esc(m.subject)+'</td><th>日期</th><td class="val">'+fmtDate(m.meeting_date)+'</td>'
        +   '<th>主席</th><td class="val">'+esc(m.chair_name||'')+'</td>'
        +   '<th class="att-lbl" rowspan="2">出席人員</th><td class="att-val" rowspan="2">'+attendeeNames+'</td></tr>'
        + '<tr><th>地點</th><td class="val">'+esc(m.location||'—')+'</td>'
        +   '<th>時間</th><td class="val">'+(m.start_time?(esc(m.start_time)+(m.end_time?'~'+esc(m.end_time):'')):'')+'</td>'
        +   '<th>記錄</th><td class="val">'+esc(m.recorder_name||'')+'</td></tr>'
        + '</table>'
        + (announceBody ? '<table class="mr-announce"><thead><tr><th style="width:32px;">序</th><th>宣布事項</th><th style="width:120px;">備註</th></tr></thead><tbody>'+announceBody+'</tbody></table>' : '')
        + (announceBody && itemBody ? '<div class="mr-gap"></div>' : '')
        + (itemBody ? '<table class="mr-items"><thead><tr><th class="mr-grp-hd"></th><th>序</th><th>報告要點及決議事項</th><th>應完成日期</th><th>負責人/部門</th><th>確認簽名</th><th>備註</th></tr></thead><tbody>'+itemBody+'</tbody></table>' : '')
        + '<table class="mr-foot"><tr><td>總經理：'+gmStamp+'</td><td>主席：'+chairStamp+'</td><td class="mr-foot-prep">製表：'+madeStamp+'</td></tr></table>'
        + (docNoMode==='inline' && m.as_doc_record_no ? '<div class="mr-bottom-note"><span></span><span>'+esc(m.as_doc_record_no)+'</span></div>' : '')
        + (docNoMode==='fixed' && m.as_doc_record_no ? '<div class="as-doc-fixed">'+esc(m.as_doc_record_no)+'</div>' : '')
        + '</div>';
}
/* 出貨目標達成率獨立一頁(有自己的簽章，只有「製表」一欄)，跟會議記錄各自一張A4，不再併頁(使用者明確要求)。 */
function kpiPageHtml(m, preparerName){
    var k = JSON.parse(m.kpi_snapshot_json);
    var madeStamp = (window.EGStamp&&EGStamp.stamp && preparerName) ? EGStamp.stamp(preparerName, fmtDate(m.meeting_date)) : '';
    return '<div class="mr-page">'
        + '<div class="pt-head"><div class="co">'+esc(META.company_name||'')+'</div><div class="tt">出貨目標達成率</div></div>'
        + kpiReportHtml(k)
        + '<table class="mr-foot"><tr><td></td><td></td><td class="mr-foot-prep">製表：'+madeStamp+'</td></tr></table>'
        + '</div>';
}
function signSheetPageHtml(m, attendees, withSignatures, docNoMode){
    var rows = (attendees||[]).map(function(a,i){
        var sigHtml = '';
        if (withSignatures) {
            var signed = +a.signed===1;
            sigHtml = signed ? ((window.EGStamp&&EGStamp.stamp)?EGStamp.stamp(a.user_name, fmtDate(m.meeting_date), false, mStampSchema(), a.dept_name, a.position_name):esc(a.user_name)) : '';
        }
        return '<tr><td>'+(i+1)+'</td><td>'+esc(a.dept_name||'')+'</td><td>'+esc(a.position_name||'')+'</td><td>'+esc(a.user_name||'')+'</td><td>'+sigHtml+'</td></tr>';
    }).join('');
    var signTitle = (META.as_doc_signsheet && META.as_doc_signsheet.doc_name) ? META.as_doc_signsheet.doc_name : '會議簽到表';
    return '<div class="pt-head"><div class="co">'+esc(META.company_name||'')+'</div><div class="tt">'+esc(signTitle)+'</div></div>'
        + '<table class="ss-head">'
        + '<tr><td colspan="2">主題：'+esc(m.subject)+'</td></tr>'
        + '<tr><td colspan="2">日期時間：'+fmtDate(m.meeting_date)+(m.start_time?('　'+esc(m.start_time)+(m.end_time?'~'+esc(m.end_time):'')):'')+'</td></tr>'
        + '<tr><td>地點：'+esc(m.location||'—')+'</td><td>主席：'+esc(m.chair_name||'')+'</td></tr>'
        + '</table>'
        + '<table class="sf"><tr><th style="width:36px;">序</th><th>部門</th><th>職稱</th><th>姓名</th><th style="width:130px;">簽名</th></tr>'+rows+'</table>'
        + (docNoMode==='inline' ? '<div class="mr-bottom-note"><span></span><span>'+esc(m.as_doc_signsheet_no||'')+'</span></div>' : '')
        + (docNoMode==='fixed' ? '<div class="as-doc-fixed">'+esc(m.as_doc_signsheet_no||'')+'</div>' : '');
}
/* 有出貨目標達成率快照時，「製表」改用業務部門最基層主管(meeting_preparer_candidates 解析)；沒有KPI就不用問，直接印(製表退回記錄人)。 */
function resolvePreparerThenPrint(cb){
    var m = VIEW.meeting;
    if (!m.kpi_snapshot_json) { cb(''); return; }
    $.getJSON(API, {action:'preparer_candidates', meeting_id:m.meeting_id}, function(res){
        if (!res.ok) { cb(''); return; }
        var cands = res.candidates||[];
        if (cands.length <= 1) { cb(cands.length ? cands[0].name : ''); return; }
        var names = cands.map(function(c){ return c.name; }).join('、');
        var pick = prompt('本次會議業務製表人有多位，請輸入其中一位姓名：\n'+names, cands[0].name);
        cb(pick===null ? '' : ($.trim(pick) || cands[0].name));
    });
}
/* 列印策略(2026-08-06四次修正，徹底解法)：position:fixed 釘右下角的AS編號只能用在「整次列印工作從頭到尾只對應
   同一份文件、同一組編號」的情境；一旦同一次列印工作混了不同文件或含無編號的頁(如會議記錄+KPI合印)，position:fixed
   會疊到其他頁去(2026-08-06實測確認)，改用內文寫法又會離頁面角落有距離，兩種都不完美。與其在這兩個妥協方案來回，
   直接讓「每個列印按鈕＝剛好一份文件」——會議記錄、簽到表(空白/已簽署)、出貨目標達成率各自獨立成單一列印工作，
   不再有「一次列印多份文件」的按鈕，AS編號全部用 position:fixed 就能保證正確，不必再判斷內文/fixed兩種模式。
   使用者需要看多份文件時分別點對應按鈕列印即可(原「列印完整紀錄」按鈕已移除)。 */
function printMeetingRecord(){
    if (!VIEW) return;
    var m = VIEW.meeting, res = VIEW;
    egPrintWindow('會議記錄', meetingRecordPageHtml(m, res, 'fixed'), mrCss(), '', true, true);
}
function printBlankSignSheet(){
    if (!VIEW) return;
    egPrintWindow('會議簽到表', signSheetPageHtml(VIEW.meeting, VIEW.attendees, false, 'fixed'), mrCss(), '', false, true);
}
/* 簽到表(已簽署版)：出席人員電子簽到全部完成才會顯示按鈕(openView時判斷)，含真圖章。 */
function printSignedSignSheet(){
    if (!VIEW) return;
    egPrintWindow('會議簽到表', signSheetPageHtml(VIEW.meeting, VIEW.attendees, true, 'fixed'), mrCss(), '', false, true);
}
/* 出貨目標達成率獨立列印(openView時依 kpi_snapshot_json 是否存在決定按鈕顯示)：本身沒有綁定AS編號，不受上述問題影響。 */
function printKpiOnly(){
    if (!VIEW || !VIEW.meeting.kpi_snapshot_json) return;
    resolvePreparerThenPrint(function(preparerName){
        egPrintWindow('出貨目標達成率', kpiPageHtml(VIEW.meeting, preparerName), mrCss(), '', true, true);
    });
}

/* ---------- 模組設定：角色設定(仿 training_record.php) + 附件路徑/簽到表AS綁定 ---------- */
$('#btnMtSetting').on('click', function(){ setTabSwitch('role'); loadRoles(); $('#setNasDir').val(META.attach_nas_dir||''); renderSignsheetLabel(); loadStampTplOptions(); loadKpiTargetUI(); openMask('mtSetMask'); });
function setTabSwitch(tab){
    $('.set-tab').removeClass('active'); $('.set-tab[data-tab="'+tab+'"]').addClass('active');
    $('#setPaneRole').toggle(tab==='role'); $('#setPaneAttach').toggle(tab==='attach');
}
var RAPI = '../../src/store/Roles_API.php';
var ROLES = [], CURROLE = 0;
function loadRoles(then){
    $.getJSON(RAPI, {action:'get_roles', module:'meeting'}, function(res){
        ROLES = res.data || [];
        var h = '';
        ROLES.forEach(function(r){
            var sys = String(r.is_system)==='1';
            h += '<div class="role-item'+(sys?' sys':'')+'" data-id="'+r.role_id+'">'+esc(r.role_name)+(sys?'（系統．固定全權）':'')+'</div>';
        });
        $('#roleList').html(h || '<div style="padding:10px;color:#8a6d45;">尚無角色</div>');
        if (CURROLE) $('.role-item[data-id="'+CURROLE+'"]').addClass('on');
        if (typeof then==='function') then();
    });
}
function selRole(id){
    var r = ROLES.filter(function(x){ return String(x.role_id)===String(id); })[0];
    if (!r) return;
    if (String(r.is_system)==='1'){ alert('系統角色「'+r.role_name+'」固定擁有全部權限，不可修改'); return; }
    CURROLE = id;
    $('.role-item').removeClass('on'); $('.role-item[data-id="'+id+'"]').addClass('on');
    $('#roleEditHint').hide(); $('#roleEdit').show();
    $('#roleName').val(r.role_name);
    var vh='', oh='';
    (META.features||[]).forEach(function(f){
        var row = '<label class="role-feat" style="display:block;font-weight:normal;padding:2px 0;"><input type="checkbox" class="featcb" value="'+esc(f.code)+'"> '+esc(f.label)+'</label>';
        if (f.group==='view') vh += row; else oh += row;
    });
    $('#featView').html(vh); $('#featOp').html(oh);
    $.getJSON(RAPI, {action:'get_role_features', role_id:id}, function(res){
        var has = res.data || [];
        $('.featcb').each(function(){ $(this).prop('checked', has.indexOf(this.value)>-1 || has.indexOf('all')>-1); });
    });
}
$(document).on('click', '#roleList .role-item', function(){ selRole($(this).data('id')); });
$('#btnRoleAdd').on('click', function(){
    var n = prompt('新角色名稱：');
    if (!n || !$.trim(n)) return;
    $.post(RAPI, {action:'save_role', role_name:$.trim(n), module:'meeting'}, function(r){
        if (!r.success){ alert(r.message); return; }
        loadRoles(function(){ selRole(r.role_id); });
    }, 'json');
});
$('#btnRoleRename').on('click', function(){
    if (!CURROLE) return;
    var n = $.trim($('#roleName').val()||'');
    if (!n){ alert('請輸入角色名稱'); return; }
    $.post(RAPI, {action:'save_role', role_id:CURROLE, role_name:n}, function(r){
        if (!r.success){ alert(r.message); return; }
        loadRoles(); alert('已改名');
    }, 'json');
});
$('#btnRoleDel').on('click', function(){
    if (!CURROLE) return;
    if (!confirm('確定刪除此角色？擁有此角色的人會失去對應權限。')) return;
    $.post(RAPI, {action:'delete_role', role_id:CURROLE}, function(r){
        if (!r.success){ alert(r.message); return; }
        CURROLE = 0; $('#roleEdit').hide(); $('#roleEditHint').show();
        loadRoles();
    }, 'json');
});
$('#btnRoleFeatSave').on('click', function(){
    if (!CURROLE) return;
    var feats = $('.featcb:checked').map(function(){ return this.value; }).get();
    $.post(RAPI, {action:'save_role_features', role_id:CURROLE, features:JSON.stringify(feats)}, function(r){
        alert(r.success ? '已儲存。受影響的人重新整理頁面後生效。' : r.message);
    }, 'json');
});
function submitNasDir(){
    $.post(API, {action:'attach_setting_save', nas_dir:$('#setNasDir').val()}, function(res){
        if (!res.ok){ alert(res.error||'儲存失敗'); return; }
        META.attach_nas_dir = res.attach_nas_dir; alert('已儲存');
    }, 'json');
}
/* 出席簽到／項目確認簽名要不要套用自訂圖章模板：META.stamp_template 由模組設定頁指定(見 loadStampTplOptions/submitStampTpl) */
function mStampSchema(){ return META.stamp_template ? META.stamp_template.schema : null; }
function loadStampTplOptions(){
    $.getJSON(API, {action:'stamp_tpl_options'}, function(res){
        if (!res.ok) return;
        var cur = META.stamp_template ? META.stamp_template.id : 0;
        var h = '<option value="0">（預設印章樣式）</option>';
        (res.templates||[]).forEach(function(t){
            h += '<option value="'+t.id+'"'+(String(t.id)===String(cur)?' selected':'')+'>'+(t.type_name?esc(t.type_name)+'｜':'')+esc(t.tpl_name)+'</option>';
        });
        $('#setStampTpl').html(h);
    });
}
function submitStampTpl(){
    $.post(API, {action:'stamp_tpl_save', template_id:$('#setStampTpl').val()}, function(res){
        if (!res.ok){ alert(res.error||'儲存失敗'); return; }
        loadMeta(function(){ alert('已儲存，重新整理頁面後對出席簽到/項目確認簽名生效'); });
    }, 'json');
}
function loadKpiTargetUI(){
    var y0 = +String(META.today||'').substr(0,4) || new Date().getFullYear();
    var $y = $('#kpiTgtYear').empty(), $m = $('#kpiTgtMonth').empty();
    for (var y=y0-1; y<=y0+1; y++) $y.append('<option value="'+y+'"'+(y===y0?' selected':'')+'>'+y+' 年</option>');
    for (var mo=1; mo<=12; mo++) $m.append('<option value="'+mo+'">'+mo+' 月</option>');
    $m.val(+String(META.today||'').substr(5,2) || (new Date().getMonth()+1));
    loadKpiTargetValues();
}
function loadKpiTargetValues(){
    $.getJSON(API, {action:'kpi_target_get', year:$('#kpiTgtYear').val(), month:$('#kpiTgtMonth').val()}, function(res){
        if (!res.ok) return;
        $('#kpiTgtAmount').val(res.target_amount||0);
        $('#kpiTgtStartDay').val(res.start_day||1);
    });
}
$(document).on('change', '#kpiTgtYear,#kpiTgtMonth', loadKpiTargetValues);
function submitKpiTarget(){
    $.post(API, {action:'kpi_target_save', year:$('#kpiTgtYear').val(), month:$('#kpiTgtMonth').val(),
        target_amount:$('#kpiTgtAmount').val(), start_day:$('#kpiTgtStartDay').val()}, function(res){
        if (!res.ok){ alert(res.error||'儲存失敗'); return; }
        alert('已儲存');
    }, 'json');
}
function renderSignsheetLabel(){
    $('#signsheetDocLabel').text(EGAsDoc.label(META.as_doc_signsheet));
    $('#recordDocLabel').text(EGAsDoc.label(META.as_doc_record));
}
function openSignsheetPicker(){
    $.getJSON(API, {action:'asdoc_list'}, function(res){
        if (!res.ok){ alert(res.error||'載入失敗'); return; }
        EGAsDoc.open({
            docs: res.docs||[], current: META.as_doc_signsheet ? META.as_doc_signsheet.id : 0,
            title: '會議簽到表 AS 文件綁定',
            onSave: function(id, doc){
                $.post(API, {action:'as_doc_signsheet_save', doc_id:id}, function(r){
                    if (!r.ok){ alert(r.error||'儲存失敗'); return; }
                    META.as_doc_signsheet = r.as_doc_signsheet; renderSignsheetLabel();
                }, 'json');
            }
        });
    });
}
function openRecordDocPicker(){
    $.getJSON(API, {action:'asdoc_list'}, function(res){
        if (!res.ok){ alert(res.error||'載入失敗'); return; }
        EGAsDoc.open({
            docs: res.docs||[], current: META.as_doc_record ? META.as_doc_record.id : 0,
            title: '會議記錄 AS 文件綁定',
            onSave: function(id, doc){
                $.post(API, {action:'as_doc_record_save', doc_id:id}, function(r){
                    if (!r.ok){ alert(r.error||'儲存失敗'); return; }
                    META.as_doc_record = r.as_doc_record; renderSignsheetLabel();
                    $('#mtHeaderDocNo').text(META.as_doc_record && META.as_doc_record.doc_no ? META.as_doc_record.doc_no : '');
                }, 'json');
            }
        });
    });
}

/* 通知置頂欄「待簽章」點進來會帶 ?sign=meeting_id&event=xxx（見 sideAndTopBarMenu.html 的 MEETING_APPROVAL 路由）：
   載入完成後自動開啟該筆檢視並捲動到簽名區，不必再自己從清單裡找。event 參數只是給該通知系統識別用，這裡不需要。 */
var URL_SIGN_ID = (function(){
    var m = String(location.search||'').match(/[?&]sign=(\d+)/);
    return m ? parseInt(m[1], 10) : 0;
})();
loadMeta(function(){
    loadList();
    if (URL_SIGN_ID) {
        openView(URL_SIGN_ID);
        setTimeout(function(){
            var $box = $('#viewBody .decide-box');
            if ($box.length) {
                $box[0].scrollIntoView({behavior:'smooth', block:'center'});
                $box.css({'box-shadow':'0 0 0 3px #F0A24B', 'transition':'box-shadow .3s'});
            }
        }, 400);
    }
});
</script>
</body>
</html>
