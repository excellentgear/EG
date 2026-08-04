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
        .mt-op { color:#b5762a; cursor:pointer; margin:0 4px; }
        .mt-op:hover { color:#8A5A2B; text-decoration:underline; }
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
                <small style="color:#8a6d45;">2-GM-05-01 會議記錄／2-GM-05-03 會議通知單</small></h2>
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
            <button class="btn-warm" id="btnAdd" style="display:none;margin-left:auto;"><i class="fa fa-plus"></i> 新增會議紀錄</button>
        </div>
        <div class="mt-table-wrap">
            <table class="mt-table">
                <thead><tr><th style="width:100px;">日期</th><th>主題</th><th style="width:90px;">主席</th>
                    <th style="width:90px;">記錄</th><th style="width:100px;">狀態</th><th style="width:150px;">操作</th></tr></thead>
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
                <select id="edPreset" style="width:auto;display:inline-block;height:20px;font-size:11px;padding:0 4px;margin-left:6px;"><option value="">套用常用設定…</option></select></label>
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
            <div class="mt-sec-title">上級指示要項</div>
            <table class="itm-tbl">
                <thead><tr><th style="width:30px;">NO</th><th>報告要點及決議事項</th><th style="width:96px;">應完成日期</th>
                    <th style="width:130px;">負責部門</th><th style="width:120px;">備註</th><th style="width:24px;"></th></tr></thead>
                <tbody id="itmBodyD"></tbody>
            </table>
            <button type="button" class="b-att wt" onclick="itemAdd('directive')"><i class="fa fa-plus"></i> 新增上級指示項目</button>
        </div>
        <div class="mt-sec">
            <div class="mt-sec-title">會議要項</div>
            <table class="itm-tbl">
                <thead><tr><th style="width:30px;">NO</th><th>報告要點及決議事項</th><th style="width:96px;">應完成日期</th>
                    <th style="width:130px;">負責部門</th><th style="width:120px;">備註</th><th style="width:24px;"></th></tr></thead>
                <tbody id="itmBodyG"></tbody>
            </table>
            <button type="button" class="b-att wt" onclick="itemAdd('general')"><i class="fa fa-plus"></i> 新增會議要項</button>
        </div>

        <div class="mt-sec">
            <div class="mt-sec-title">出貨目標達成率（產銷會議可插入本月數據佐證，非必要可略過）</div>
            <div id="kpiBox"></div>
        </div>
    </div>
    <div class="m-foot">
        <div style="text-align:left;font-size:11.5px;color:#8a6d45;line-height:1.6;margin-bottom:6px;">
            <b style="color:#8A5A2B;">存草稿</b>＝可隨時再修改；<b style="color:#8A5A2B;">送出</b>＝鎖定內容並通知主席確認簽章，之後需退回才能再改。
        </div>
        <button class="b-cancel" onclick="closeMask('edMask')">取消</button>
        <button class="b-ok" style="background:#fff;color:#8A5A2B;" onclick="saveDraft(false)">存草稿</button>
        <button class="b-ok" onclick="saveDraft(true)"><i class="fa fa-paper-plane"></i> 存檔並送出</button>
    </div>
</div></div>

<!-- 檢視/簽核/列印 modal -->
<div class="mt-mask" id="viewMask"><div class="mt-modal wide">
    <div class="m-head"><span id="viewTitle">會議紀錄</span><span class="m-close" onclick="closeMask('viewMask')">✕</span></div>
    <div class="m-body view-box" id="viewBody"></div>
    <div class="m-foot">
        <button class="b-cancel" onclick="printBlankSignSheet()"><i class="fa fa-file-o"></i> 列印空白簽到表</button>
        <button class="b-cancel" onclick="printMeetingRecord()"><i class="fa fa-print"></i> 列印會議紀錄</button>
        <button class="b-ok" onclick="closeMask('viewMask')">關閉</button>
    </div>
</div></div>

<!-- 使用說明 modal（鐵律7） -->
<div class="mt-mask" id="helpUseMask"><div class="mt-modal wide">
    <div class="m-head"><span>使用說明 — 會議紀錄管理</span><span class="m-close" onclick="closeMask('helpUseMask')">✕</span></div>
    <div class="m-body help-doc" style="font-size:13px;color:#5b3a1e;line-height:1.8;">
        <h4>功能說明</h4>
        會議記錄（2-GM-05-01）線上化：建立會議基本資料與出席名單、現場密碼簽到、上級指示要項／會議要項雙表格、
        主席與總經理雙層簽核、產銷會議可自動插入本月出貨目標達成率佐證。
        <h4>操作步驟</h4>
        <b>①新增</b>：填主題/日期/時間/地點，加入出席人員（依部門挑選），指定主席。<br>
        <b>②建立要項</b>：分「上級指示要項」與「會議要項」兩張表，每項可填應完成日期、負責部門（可多選）、備註。<br>
        <b>③現場簽到</b>：開啟「檢視」，出席人員名單旁各自輸入<b>本人密碼</b>簽到（共用一台裝置輪流簽，用選人不用密碼反查身分，不會有密碼重複無法辨識的問題）。<br>
        <b>④存草稿或送出</b>：草稿只有記錄人自己看得到，可隨時修改；<b>送出</b>後鎖定內容並通知主席確認簽章 → 主席簽章後自動通知總經理確認簽章（總經理可逐筆或整體回覆意見）→ 完成。
        任一階段可退回，退回後記錄人可修改並重新送出。<br>
        <b>⑤部門指派項目確認</b>：要項若指派「負責部門」，該部門本次出席人員都會收到通知，任一人簽名確認即完成。<br>
        <b>⑥插入出貨目標達成率</b>：草稿階段可按「插入本月數據」，系統會先確認出貨資料已更新至前一個工作天，未達標會提示還差幾天，不會插入不完整的數字；插入後的數字是<b>當下的快照</b>，之後不會再變動。
        <h4>重要行為</h4>
        ・草稿只有記錄人本人看得到；送出後，出席人員／主席／總經理都自動有唯讀權限，其餘人是否看得到全部會議記錄依角色設定的「檢視全部」功能。<br>
        ・列印的會議記錄／空白簽到表<b>不含電子簽章</b>，供現場紙本簽名或掃描存查；主席／總經理的簽核仍在系統內完成並自動蓋章存證。<br>
        ・主席或總經理今日若有請假等行程，會自動由代理人處理（依「代理系統設定」解析，不必自己找人代簽）。
        <h4>權限角色</h4>
        會議記錄檢閱＝看（草稿仍僅本人）；會議記錄登錄＝新增/編輯/送出；會議記錄管理員＝＋檢視全部人員記錄、刪除、修改他人已送出記錄；管理者全權。
    </div>
    <div class="m-foot"><button class="b-ok" onclick="closeMask('helpUseMask')">關閉</button></div>
</div></div>

<script src="../../resource/js/jquery.min.js"></script>
<script src="../../resource/js/bootstrap.min.js"></script>
<script src="../../resource/js/nprogress.js"></script>
<script src="../../resource/js/custom.min.js"></script>
<script src="../../resource/js/eg_input_rules.js?v=<?= @filemtime(__DIR__.'/../../resource/js/eg_input_rules.js') ?>"></script>
<script src="../../resource/js/eg_stamp.js?v=<?= @filemtime(__DIR__.'/../../resource/js/eg_stamp.js') ?>"></script>
<script>
$(document).ready(function(){
    var $am = $('#sidebar-menu .nav.side-menu > li.active');
    if ($am.length) { $am.removeClass('active').find('ul.child_menu').hide(); $am.find('li.current-page').removeClass('current-page'); }
    $('#sidebar-menu').css('visibility','visible');
});

var API = '../../src/store/Meeting_API.php';
var META = null, PERMS = null, DEPTS = [];
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

function loadMeta(cb){
    $.getJSON(API, {action:'meta'}, function(m){
        if (!m.ok){ alert(m.error||'載入失敗'); return; }
        META = m; PERMS = m.perms; DEPTS = m.departments||[];
        var $y = $('#yearSel').empty();
        (m.years||[]).forEach(function(y){ $y.append('<option value="'+y+'">'+y+' 年</option>'); });
        $y.val(m.cur_year);
        var $ad = $('#attDept').empty().append('<option value="">選部門載入人員…</option>');
        DEPTS.forEach(function(d){ $ad.append('<option value="'+d.id+'">'+esc(d.name)+'</option>'); });
        if (m.perms.canEdit) $('#btnAdd').show();
        if (cb) cb();
    });
}
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
           + '<td>'
           + '<span class="mt-op" onclick="openView('+m.meeting_id+')"><i class="fa fa-search-plus"></i> 檢視</span>';
        var canEditRow = (m.is_mine || PERMS.canAdmin) && (m.approval_status==='draft' || m.approval_status==='rejected');
        if (canEditRow) h += '<span class="mt-op" onclick="openEdit('+m.meeting_id+')"><i class="fa fa-pencil"></i> 編輯</span>';
        if (m.approval_status==='draft' && (m.is_mine || PERMS.canAdmin))
            h += '<span class="mt-op" style="color:#DD5138;" onclick="deleteMeeting('+m.meeting_id+')"><i class="fa fa-trash"></i> 刪除</span>';
        h += '</td></tr>';
    });
    $('#listBody').html(h || '<tr><td colspan="6" style="color:#8a6d45;padding:14px;">本年度尚無會議記錄</td></tr>');
}

/* ---------- 建立/編輯 ---------- */
var EDIT_ID = 0, ATT = [], ITEMS_D = [], ITEMS_G = [], KPI_SNAP = null;
function openCreate(){
    EDIT_ID = 0; ATT = []; ITEMS_D = []; ITEMS_G = []; KPI_SNAP = null;
    $('#edTitle').text('新增會議紀錄');
    $('#edSubject').val(''); $('#edDate').val(META.today); $('#edStart').val(''); $('#edEnd').val('');
    $('#edLoc').val(''); $('#edRecorder').val(META.uname);
    renderAtt(); renderItems('directive'); renderItems('general'); renderChairSel(); renderKpiBox();
    $('#attDept').val(''); $('#attPeopleBox').html('<span class="empty">選部門載入人員</span>');
    openMask('edMask');
}
function openEdit(id){
    $.getJSON(API, {action:'get_detail', meeting_id:id}, function(res){
        if (!res.ok){ alert(res.error||'載入失敗'); return; }
        var m = res.meeting;
        EDIT_ID = m.meeting_id;
        $('#edTitle').text('編輯會議紀錄');
        $('#edSubject').val(m.subject); $('#edDate').val(fmtDate(m.meeting_date));
        $('#edStart').val(m.start_time||''); $('#edEnd').val(m.end_time||''); $('#edLoc').val(m.location||'');
        $('#edRecorder').val(m.recorder_name||'');
        ATT = (res.attendees||[]).map(function(a){ return {user_id:+a.user_id, user_name:a.user_name, dept_name:a.dept_name, position_name:a.position_name||''}; });
        ITEMS_D = []; ITEMS_G = [];
        (res.items||[]).forEach(function(it){
            var row = {item_id:it.item_id, content:it.content, due_date:fmtDate(it.due_date),
                owner_depts:(it.owner_depts?String(it.owner_depts).split(','):[]), remark:it.remark||'',
                confirm_user_name:it.confirm_user_name, confirm_at:it.confirm_at};
            (it.kind==='directive'?ITEMS_D:ITEMS_G).push(row);
        });
        KPI_SNAP = m.kpi_snapshot_json ? JSON.parse(m.kpi_snapshot_json) : null;
        renderAtt(); renderItems('directive'); renderItems('general'); renderKpiBox();
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

/* 出席人員：部門挑選 → 勾選加入（同 training 模組模式） */
$('#attDept').on('change', function(){
    var did = $(this).val();
    if (!did){ $('#attPeopleBox').html('<span class="empty">選部門載入人員</span>'); return; }
    $('#attPeopleBox').html('<span class="empty">載入中…</span>');
    $.getJSON(API, {action:'people', dept_id:did}, function(res){
        if (!res.ok){ $('#attPeopleBox').html('<span class="empty">載入失敗</span>'); return; }
        var h = '';
        res.people.forEach(function(u){
            var inList = ATT.some(function(a){ return a.user_id===+u.id; });
            h += '<label><input type="checkbox" class="att-ck" value="'+u.id+'" data-name="'+esc(u.user_cname)+'"'
               + ' data-dept="'+esc($('#attDept option:selected').text())+'" data-pos="'+esc(u.position_name||'')+'"'+(inList?' checked disabled':'')+'> '
               + esc(u.user_cname)+(u.position_name?'<span style="color:#8a6d45;">（'+esc(u.position_name)+'）</span>':'')+(inList?'(已加)':'')+'</label>';
        });
        $('#attPeopleBox').html(h || '<span class="empty">此部門無人員</span>');
        $('#attPickAll').prop('checked', false);
    });
});
$('#attPickAll').on('change', function(){ $('#attPeopleBox .att-ck:not(:disabled)').prop('checked', this.checked); });
function attAddChecked(){
    $('#attPeopleBox .att-ck:checked:not(:disabled)').each(function(){
        var id = +$(this).val();
        if (!ATT.some(function(a){ return a.user_id===id; }))
            ATT.push({user_id:id, user_name:$(this).data('name'), dept_name:$(this).data('dept'), position_name:$(this).data('pos')||''});
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
        h += '<tr><td>'+esc(a.dept_name||'')+'</td><td>'+esc(a.position_name||'—')+'</td><td class="t-left">'+esc(a.user_name||'')+'</td>'
           + '<td><span class="att-del" onclick="attDel('+i+')"><i class="fa fa-times"></i></span></td></tr>';
    });
    $('#attBody').html(h || '<tr><td colspan="4" style="color:#8a6d45;padding:6px;">尚未加入出席人員</td></tr>');
    $('#attCount').text(ATT.length ? '（共 '+ATT.length+' 人）' : '');
}
function renderChairSel(){
    var cur = $('#edChair').val();
    var h = '<option value="">請選擇主席</option>';
    ATT.forEach(function(a){ h += '<option value="'+a.user_id+'">'+esc(a.user_name)+'（'+esc(a.dept_name||'')+'）</option>'; });
    $('#edChair').html(h).val(cur);
}

/* 會議要項雙表格：上級指示要項(kind=directive) / 會議要項(kind=general) */
function itemsArr(kind){ return kind==='directive' ? ITEMS_D : ITEMS_G; }
function itemAdd(kind){ itemsArr(kind).push({item_id:0, content:'', due_date:'', owner_depts:[], remark:''}); renderItems(kind); }
function itemDelLast(kind){ var a=itemsArr(kind); if (a.length) a.pop(); renderItems(kind); }
function itemDel(kind,i){ itemsArr(kind).splice(i,1); renderItems(kind); }
function itemEdit(kind,i,key,val){ var a=itemsArr(kind); if (a[i]) a[i][key]=val; }
function renderItems(kind){
    var a = itemsArr(kind), h = '';
    a.forEach(function(it,i){
        var confirmTxt = it.confirm_user_name ? ('<span class="confirm-yes">'+esc(it.confirm_user_name)+' 已確認</span>') : '<span class="confirm-no">未確認</span>';
        h += '<tr><td style="text-align:center;">'+(i+1)+'</td>'
           + '<td><textarea onchange="itemEdit(\''+kind+'\','+i+',\'content\',this.value)">'+esc(it.content||'')+'</textarea></td>'
           + '<td><input type="date" max="9999-12-31" value="'+esc(it.due_date||'')+'" onchange="itemEdit(\''+kind+'\','+i+',\'due_date\',this.value)"></td>'
           + '<td>'+deptPickHtml(kind,i,it.owner_depts||[])+'</td>'
           + '<td><input type="text" maxlength="200" value="'+esc(it.remark||'')+'" onchange="itemEdit(\''+kind+'\','+i+',\'remark\',this.value)" placeholder="'+confirmTxt.replace(/<[^>]+>/g,'')+'"></td>'
           + '<td><span class="att-del" onclick="itemDel(\''+kind+'\','+i+')"><i class="fa fa-times"></i></span></td></tr>';
    });
    $('#itmBody'+(kind==='directive'?'D':'G')).html(h || '<tr><td colspan="6" style="color:#8a6d45;padding:6px;text-align:center;">尚未建立項目</td></tr>');
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
$(document).on('click', function(e){ if (!$(e.target).closest('.itm-dp').length) $('.itm-dp .dp-list').hide(); });

/* 出貨目標達成率快照 */
function renderKpiBox(){
    var h = '';
    if (KPI_SNAP) {
        h += '<div class="kpi-box"><b>帳款月：</b>'+esc(KPI_SNAP.billing_month_start)+' ~ '+esc(KPI_SNAP.billing_month_end)
           + '　<b>資料基準日：</b>'+esc(KPI_SNAP.data_asof||'—')
           + '<table><tr><th>目標金額</th><th>接單金額</th><th>出貨金額</th><th>退貨金額</th><th>淨營收</th><th>達成率</th></tr>'
           + '<tr><td>'+KPI_SNAP.target_amount.toLocaleString()+'</td><td>'+KPI_SNAP.order_amount.toLocaleString()+'</td>'
           + '<td>'+KPI_SNAP.ship_amount.toLocaleString()+'</td><td>'+KPI_SNAP.return_amount.toLocaleString()+'</td>'
           + '<td>'+KPI_SNAP.revenue.toLocaleString()+'</td><td><b>'+KPI_SNAP.achieve_rate+'%</b></td></tr></table>'
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

/* 存草稿／送出 */
function gatherPayload(){
    return {
        action:'save', meeting_id: EDIT_ID, subject: $('#edSubject').val(), meeting_date: $('#edDate').val(),
        start_time: $('#edStart').val(), end_time: $('#edEnd').val(), location: $('#edLoc').val(),
        chair_user_id: $('#edChair').val(), recorder_name: $('#edRecorder').val(),
        attendees: JSON.stringify(ATT),
        items: JSON.stringify(ITEMS_D.map(function(it){ return $.extend({kind:'directive'}, it); })
                    .concat(ITEMS_G.map(function(it){ return $.extend({kind:'general'}, it); })))
    };
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
        $.post(API, {action:'submit', meeting_id:EDIT_ID}, function(r2){
            if (!r2.ok){ alert('草稿已存，但送出失敗：'+(r2.error||'')); closeMask('edMask'); loadList(); return; }
            alert('已送出，已通知主席確認簽章。'); closeMask('edMask'); loadList();
        }, 'json');
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
        h += '<tr><td>'+esc(a.dept_name||'')+'</td><td>'+esc(a.position_name||'')+'</td>'
           + '<td>'+esc(a.user_name||'')+(a.is_chair?'（主席）':'')+'</td>'
           + '<td>'+(signed ? '<span class="sign-ok"><i class="fa fa-check"></i> 已簽到（'+esc(String(a.signed_at||'').substr(0,16))+'）</span>'
                : '<span class="sign-row"><input type="password" placeholder="本人密碼" id="pw-'+a.user_id+'">'
                  + '<button type="button" onclick="signAttendee('+m.meeting_id+','+a.user_id+')">簽到</button></span>')+'</td></tr>';
    });
    h += '</table>';

    function itemsTable(kind, title) {
        var rows = (res.items||[]).filter(function(it){ return it.kind===kind; });
        if (!rows.length) return '';
        var t = '<h5>'+title+'</h5><table><tr><th>NO</th><th>報告要點及決議事項</th><th>應完成日期</th><th>負責部門</th><th>確認簽名</th><th>備註</th>'
              + (m.approval_status==='chair_done'||m.approval_status==='done' ? '<th>總經理意見</th>' : '') + '</tr>';
        rows.forEach(function(it, idx){
            var deptNames = (it.owner_depts?String(it.owner_depts).split(','):[]).map(function(id){ var d=deptById(id); return d?d.name:''; }).filter(Boolean).join('、');
            var canConfirm = !it.confirm_user_id && it.owner_depts;
            t += '<tr><td>'+(idx+1)+'</td><td>'+esc(it.content).replace(/\n/g,'<br>')+'</td><td>'+fmtDate(it.due_date)+'</td>'
               + '<td>'+esc(deptNames||'—')+'</td>'
               + '<td>'+(it.confirm_user_id ? ('<span class="confirm-yes">'+esc(it.confirm_user_name)+'（'+esc(String(it.confirm_at||'').substr(0,16))+'）</span>')
                    : (canConfirm ? '<button type="button" onclick="confirmItem('+it.item_id+')">確認簽名</button>' : '<span class="confirm-no">—</span>'))+'</td>'
               + '<td>'+esc(it.remark||'')+'</td>'
               + (m.approval_status==='chair_done'||m.approval_status==='done' ? '<td>'+esc(it.gm_comment||'')+'</td>' : '') + '</tr>';
        });
        return t + '</table>';
    }
    h += itemsTable('directive','上級指示要項') + itemsTable('general','會議要項');

    if (m.kpi_snapshot_json) {
        var k = JSON.parse(m.kpi_snapshot_json);
        h += '<h5>出貨目標達成率</h5><table><tr><th>帳款月</th><th>目標</th><th>接單</th><th>出貨</th><th>退貨</th><th>淨營收</th><th>達成率</th></tr>'
           + '<tr><td>'+esc(k.billing_month_start)+'~'+esc(k.billing_month_end)+'</td><td>'+k.target_amount.toLocaleString()
           + '</td><td>'+k.order_amount.toLocaleString()+'</td><td>'+k.ship_amount.toLocaleString()+'</td><td>'+k.return_amount.toLocaleString()
           + '</td><td>'+k.revenue.toLocaleString()+'</td><td><b>'+k.achieve_rate+'%</b></td></tr></table>';
    }

    // 主席／總經理簽核區
    if (m.approval_status==='submitted' && (+m.chair_signer_id===META.uid || PERMS.canAdmin)) {
        h += decideBoxHtml(m.meeting_id, 'chair', '主席確認簽章', false);
    } else if (m.approval_status==='chair_done' && (+m.gm_signer_id===META.uid || PERMS.canAdmin)) {
        h += decideBoxHtml(m.meeting_id, 'gm', '總經理確認簽章', true);
    }
    return h;
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
        if (!res.ok){ alert(res.error||'簽到失敗'); return; }
        openView(mid);
    }, 'json').fail(function(x){ alert(x.responseJSON&&x.responseJSON.error || '簽到失敗'); });
}
function confirmItem(itemId){
    $.post(API, {action:'item_confirm', item_id:itemId}, function(res){
        if (!res.ok){ alert(res.error||'確認失敗'); return; }
        openView(VIEW.meeting.meeting_id);
    }, 'json').fail(function(x){ alert(x.responseJSON&&x.responseJSON.error || '確認失敗'); });
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

/* ---------- 列印（不含電子簽章，供現場紙本簽名／掃描） ---------- */
function egPrintWindow(title, bodyHtml, extraCss, docNo, landscape, pageCount){
    var asCss = String(docNo||'').replace(/['\\]/g,'');
    var css = '@page{size:A4 '+(landscape?'landscape':'portrait')+';'
            + (pageCount
                ? 'margin:12mm 8mm 16mm;'
                  + " @bottom-left{ content:'第 ' counter(page) ' 頁／共 ' counter(pages) ' 頁'; font-size:9pt; color:#333; }"
                  + (asCss ? " @bottom-right{ content:'"+asCss+"'; font-size:9pt; color:#333; }" : '')
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
    w.document.write('<html><head><meta charset="utf-8"><title>'+esc(title)+'</title><style>'+css+'</style></head><body>'
        + bodyHtml
        + '<scr'+'ipt>window.onload=function(){setTimeout(function(){window.print();},200);};</scr'+'ipt></body></html>');
    w.document.close();
}
function printMeetingRecord(){
    if (!VIEW) return;
    var m = VIEW.meeting, res = VIEW;
    function itemRows(kind){
        return (res.items||[]).filter(function(it){ return it.kind===kind; }).map(function(it,i){
            var deptNames = (it.owner_depts?String(it.owner_depts).split(','):[]).map(function(id){ var d=deptById(id); return d?d.name:''; }).filter(Boolean).join('、');
            return '<tr><td>'+(i+1)+'</td><td class="t-left">'+esc(it.content).replace(/\n/g,'<br>')+'</td><td>'+fmtDate(it.due_date)+'</td>'
                 + '<td>'+esc(deptNames)+'</td><td>'+esc(it.confirm_user_name||'')+'</td><td>'+esc(it.remark||'')+'</td></tr>';
        }).join('');
    }
    var html = '<div class="pt-head"><div class="co">'+esc(META.company_name||'')+'</div><div class="tt">會議記錄</div></div>'
        + '<table class="sf-info"><tr><td>主題：'+esc(m.subject)+'</td><td>日期：'+fmtDate(m.meeting_date)+'</td></tr>'
        + '<tr><td>主席：'+esc(m.chair_name||'')+'</td><td>記錄：'+esc(m.recorder_name||'')+'</td></tr>'
        + '<tr><td colspan="2">地點：'+esc(m.location||'—')+(m.start_time?('　時間：'+esc(m.start_time)+(m.end_time?'~'+esc(m.end_time):'')):'')
        + '　出席人員：'+((res.attendees||[]).map(function(a){ return esc(a.user_name); }).join('、')||'—')+'</td></tr></table>'
        + (itemRows('directive') ? '<h5>上級指示要項</h5><table class="sf"><tr><th>NO</th><th>報告要點及決議事項</th><th>應完成日期</th><th>負責部門</th><th>確認簽名</th><th>備註</th></tr>'+itemRows('directive')+'</table>' : '')
        + (itemRows('general') ? '<h5>會議要項</h5><table class="sf"><tr><th>NO</th><th>報告要點及決議事項</th><th>應完成日期</th><th>負責部門</th><th>確認簽名</th><th>備註</th></tr>'+itemRows('general')+'</table>' : '')
        + '<div style="margin-top:16px;font-size:13px;">主席確認：______________　　總經理確認：______________　　製表：'+esc(m.recorder_name||'')+'</div>'
        + '<div style="margin-top:6px;font-size:11px;color:#666;">（本記錄不得擅自塗改）</div>';
    var css = 'table.sf-info{width:100%;border-collapse:collapse;font-size:13px;margin-top:8px;}table.sf-info td{border:1px solid #999;padding:5px 8px;text-align:left;}'
        + 'h5{font-size:13px;margin:10px 0 3px;}'
        + 'table.sf{width:100%;border-collapse:collapse;font-size:12.5px;}table.sf th,table.sf td{border:1px solid #333;padding:5px;text-align:center;}table.sf td.t-left{text-align:left;}';
    egPrintWindow('會議記錄', html, css, '', false);
}
function printBlankSignSheet(){
    if (!VIEW) return;
    var m = VIEW.meeting;
    var rows = (VIEW.attendees||[]).map(function(a){
        return '<tr><td></td><td>'+esc(a.dept_name||'')+'</td><td>'+esc(a.position_name||'')+'</td><td>'+esc(a.user_name||'')+'</td><td></td></tr>';
    }).join('');
    var html = '<div class="pt-head"><div class="co">'+esc(META.company_name||'')+'</div><div class="tt">會議簽到表</div></div>'
        + '<table class="sf-info"><tr><td>主題：'+esc(m.subject)+'</td><td>日期：'+fmtDate(m.meeting_date)+'</td></tr>'
        + '<tr><td colspan="2">地點：'+esc(m.location||'—')+(m.start_time?('　時間：'+esc(m.start_time)+(m.end_time?'~'+esc(m.end_time):'')):'')+'</td></tr></table>'
        + '<table class="sf"><tr><th style="width:36px;">NO</th><th>部門</th><th>職稱</th><th>姓名</th><th style="width:130px;">簽名</th></tr>'+rows+'</table>';
    var css = 'table.sf-info{width:100%;border-collapse:collapse;font-size:13px;margin-top:8px;}table.sf-info td{border:1px solid #999;padding:5px 8px;text-align:left;}'
        + 'table.sf{width:100%;border-collapse:collapse;font-size:14px;margin-top:8px;}table.sf th,table.sf td{border:1px solid #333;padding:9px 6px;text-align:center;}';
    egPrintWindow('會議簽到表', html, css, '', false);
}

loadMeta(function(){ loadList(); });
</script>
</body>
</html>
