<?php
/**
 * 教育訓練管理（KPI 2-GM-04-01 第19項 人員教育訓練達成率 的來源頁）
 * 管理員後端維護各部門/年度訓練計畫；達成率=當月完成場次/計畫場次。
 * 資料一律走 src/store/Training_API.php；權限 training_lib.php
 */
session_start();
if (!isset($_SESSION['userName'])) {
    $_SESSION['lastpage'] = "../../views/ADM/training_record.php";
    header("Location:../../index.php");
    exit;
}
include_once '../../src/common/_config.php';
include_once '../../src/common/DBConnection.php';
include_once '../../src/common/training_lib.php';

$db = (new DBConnection())->getPDO();
training_ensure_schema($db);
$trUser = training_current_user($db);
$perms = training_perms($db, $trUser);
$roleLabel = $perms['isAdmin'] ? '管理者'
           : ($perms['canAdmin'] ? '訓練管理員'
           : ($perms['canEdit'] ? '訓練登錄'
           : ($perms['canView'] ? '訓練檢閱' : '無權限')));
?>
<!DOCTYPE html>
<html lang="zh-Hant">
<head>
    <meta charset="utf-8">
    <meta http-equiv="X-UA-Compatible" content="IE=edge">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>教育訓練管理</title>
    <link href="../../resource/css/bootstrap.css" rel="stylesheet">
    <link href="../../resource/css/font-awesome.css" rel="stylesheet">
    <link href="../../resource/css/nprogress.css" rel="stylesheet">
    <link href="../../resource/css/custom.css" rel="stylesheet">
    <style>
        #sidebar-menu { visibility: hidden; }
        .right_col .page-title { margin:8px 0 4px; overflow:hidden; }
        .tr-toolbar { display:flex; flex-wrap:wrap; gap:6px; align-items:center; clear:both;
            border:1.5px solid #E8D5B5; border-radius:8px; padding:8px 10px; margin-bottom:10px; background:#FDF8EF; }
        .tr-toolbar label { margin:0; font-size:13px; color:#5b3a1e; }
        .tr-toolbar select, .tr-toolbar input[type=text], .tr-toolbar button {
            height:30px; font-size:13px; line-height:1; padding:0 10px; border:1px solid #D8BE93;
            border-radius:4px; background:#fff; color:#5b3a1e; cursor:pointer; }
        .tr-toolbar button:hover { background:#F7E0BD; }
        .tr-toolbar .btn-warm { background:#F0A24B; color:#fff; border-color:#d98a33; }
        .tr-toolbar .btn-warm:hover { background:#d98a33; }
        .tr-role-badge { margin-left:auto; font-size:13px; color:#5b3a1e; background:#F7E0BD; border-radius:12px; padding:4px 12px; }
        .tr-role-badge .fa-question-circle { cursor:pointer; color:#b5762a; margin-left:5px; }
        .tr-stat { display:flex; flex-wrap:wrap; gap:6px; align-items:center; margin-bottom:10px;
            border:1.5px solid #E8D5B5; border-radius:8px; padding:8px 10px; background:#FFF7E8; }
        .tr-stat .yr { font-size:14px; color:#8A5A2B; font-weight:bold; margin-right:8px; }
        .mon-pill { display:inline-block; font-size:12px; border:1px solid #E8D5B5; border-radius:6px;
            padding:2px 7px; color:#5b3a1e; background:#fff; }
        .mon-pill b { color:#8A5A2B; }
        .mon-pill.below b { color:#DD5138; }
        .mon-pill.empty { color:#c4b79c; }
        .tr-table-wrap { overflow-x:auto; border:1px solid #E8D5B5; border-radius:6px; background:#fff; }
        table.tr-table { width:100%; border-collapse:collapse; font-size:13px; }
        table.tr-table th, table.tr-table td { border:1px solid #EADFC8; padding:5px 8px; white-space:nowrap; text-align:center; }
        table.tr-table thead th { position:sticky; top:0; z-index:5; background:#F7E0BD; color:#5b3a1e; font-weight:bold; }
        table.tr-table tbody tr:nth-child(even) { background:#FBF6EC; }
        table.tr-table tbody tr:hover { background:#FBF0DD; }
        table.tr-table td.t-left { text-align:left; }
        .st-pill { display:inline-block; font-size:12px; border-radius:10px; padding:2px 9px; }
        .st-planned { background:#F7E0BD; color:#7a5217; }
        .st-done { background:#F0A24B; color:#fff; }
        .st-cancelled { background:#efe7d8; color:#b0a390; text-decoration:line-through; }
        .tr-op { color:#b5762a; cursor:pointer; margin:0 4px; }
        .tr-op:hover { color:#8A5A2B; text-decoration:underline; }
        input[type=number]::-webkit-outer-spin-button, input[type=number]::-webkit-inner-spin-button { -webkit-appearance:none; margin:0; }
        input[type=number] { -moz-appearance:textfield; }
        .tr-mask { display:none; position:fixed; inset:0; background:rgba(60,40,20,.45); z-index:1050; }
        .tr-modal { background:#fff; border-radius:8px; max-width:600px; margin:48px auto; box-shadow:0 5px 25px rgba(0,0,0,.3);
            max-height:86vh; display:flex; flex-direction:column; }
        .tr-modal .m-head { background:#F7E0BD; color:#5b3a1e; font-weight:bold; padding:10px 15px; border-radius:8px 8px 0 0;
            display:flex; justify-content:space-between; }
        .tr-modal .m-head .m-close { cursor:pointer; color:#b5762a; }
        .tr-modal .m-body { padding:15px; overflow-y:auto; }
        .tr-modal .m-body label { display:block; font-size:13px; color:#5b3a1e; margin:9px 0 3px; }
        .tr-modal .m-body input[type=text], .tr-modal .m-body input[type=number], .tr-modal .m-body input[type=date],
        .tr-modal .m-body select, .tr-modal .m-body textarea { width:100%; border:1px solid #D8BE93; border-radius:4px;
            padding:5px 8px; font-size:13px; box-sizing:border-box; }
        .tr-modal .m-foot { padding:10px 15px; border-top:1px solid #EADFC8; text-align:right; }
        .tr-modal .m-foot button { height:30px; padding:0 16px; border-radius:4px; font-size:13px; border:1px solid #d98a33; cursor:pointer; }
        .tr-modal .m-foot .b-ok { background:#F0A24B; color:#fff; }
        .tr-modal .m-foot .b-cancel { background:#fff; color:#5b3a1e; border-color:#D8BE93; margin-right:6px; }
        .grid2 { display:grid; grid-template-columns:1fr 1fr; gap:0 14px; }
        .tr-modal.wide { max-width:820px; }
        .tr-hint { margin-top:12px; font-size:12px; color:#8a6d45; background:#FDF8EF; border:1px dashed #E8D5B5;
            border-radius:6px; padding:7px 10px; line-height:1.6; }
        .tr-hint b { color:#8A5A2B; }
        .ex-plan { border:1px solid #E8D5B5; border-radius:6px; background:#FFF7E8; padding:8px 10px; font-size:13px;
            color:#5b3a1e; line-height:1.8; margin-bottom:4px; }
        .ex-plan b { color:#8A5A2B; }
        .ex-plan .st-pill { margin-left:4px; }
        .att-sec { border-top:1px dashed #EADFC8; margin-top:10px; }
        .att-people { max-height:130px; overflow-y:auto; border:1px solid #EADFC8; border-radius:6px; padding:6px 8px;
            display:flex; flex-wrap:wrap; gap:4px 14px; margin-bottom:6px; min-height:20px; }
        .att-people label { font-size:12px; color:#5b3a1e; margin:0; font-weight:normal; cursor:pointer; }
        .att-people .empty { color:#b0a390; font-size:12px; }
        button.b-att { height:28px; font-size:12px; border:1px solid #d98a33; background:#F0A24B; color:#fff; border-radius:4px; cursor:pointer; padding:0 10px; }
        .att-list-wrap { max-height:180px; overflow-y:auto; border:1px solid #EADFC8; border-radius:6px; }
        table.att-tbl { width:100%; border-collapse:collapse; font-size:12px; }
        table.att-tbl th, table.att-tbl td { border-bottom:1px solid #F0E7D5; padding:3px 8px; text-align:center; }
        table.att-tbl thead th { position:sticky; top:0; background:#F7E0BD; color:#5b3a1e; }
        table.att-tbl td.t-left { text-align:left; }
        .att-del { color:#DD5138; cursor:pointer; }
        .tr-noperm { margin:40px auto; max-width:520px; text-align:center; border:1.5px solid #E8D5B5; border-radius:10px;
            padding:30px; background:#FDF8EF; color:#5b3a1e; }
        @media print {
            .tr-toolbar, .nav_menu, .left_col, footer, .tr-role-badge .fa-question-circle, .tr-op { display:none !important; }
            .right_col { margin:0 !important; padding:0 !important; }
            table.tr-table thead th { position:static; }
        }
    </style>
</head>
<body class="nav-sm">
<div class="container body">
<div class="main_container">
    <?php include '../partPage/sideAndTopBarMenu.html' ?>
    <div class="right_col" role="main">
        <div class="page-title" style="display:flex;align-items:center;flex-wrap:wrap;">
            <h2 style="margin:6px 0;">教育訓練管理
                <small style="color:#8a6d45;">KPI 2-GM-04-01 #19 人員教育訓練達成率 來源頁</small></h2>
        </div>
        <div class="clearfix"></div>

<?php if (!$perms['canView']): ?>
        <div class="tr-noperm">
            <h4><i class="fa fa-lock"></i> 無教育訓練檢閱權限</h4>
            <p>請洽管理者於「使用者權限設定」指派「訓練檢閱／登錄／管理員」角色。</p>
        </div>
<?php else: ?>
        <div class="tr-toolbar">
            <label>年度</label>
            <select id="yearSel"></select>
            <label>部門</label>
            <select id="deptSel"><option value="">全部</option></select>
            <label>狀態</label>
            <select id="statSel">
                <option value="">全部</option><option value="planned">計畫中</option>
                <option value="done">已完成</option><option value="cancelled">取消</option>
            </select>
            <input type="text" id="kwSel" placeholder="搜尋課程" style="width:130px;">
            <button class="btn-warm" id="btnAdd" style="display:none;"><i class="fa fa-plus"></i> 新增訓練場次</button>
            <button id="btnCsv"><i class="fa fa-file-text-o"></i> 匯出CSV</button>
            <button onclick="window.print()"><i class="fa fa-print"></i> 列印</button>
            <span class="tr-role-badge">目前角色：<b><?= htmlspecialchars($roleLabel) ?></b>
                <i class="fa fa-question-circle" id="btnRoleHelp" title="角色權限說明"></i></span>
        </div>

        <div class="tr-stat" id="statBar">
            <span class="yr" id="yrRate">—</span>
            <span id="monWrap"></span>
        </div>

        <div class="tr-table-wrap">
            <table class="tr-table" id="trTable">
                <thead><tr>
                    <th>月份</th><th>對象部門</th><th>課程名稱</th><th>類型</th><th>講師/開課單位</th><th>時數</th>
                    <th>應到</th><th>實到</th><th>狀態</th><th>實際開課日</th><th>操作</th>
                </tr></thead>
                <tbody id="trBody"><tr><td colspan="11" style="padding:20px;color:#8a6d45;">載入中…</td></tr></tbody>
            </table>
        </div>
        <div style="font-size:11px;color:#8a6d45;margin-top:4px;">
            狀態：<span class="st-pill st-planned">計畫中</span> <span class="st-pill st-done">已完成</span>
            <span class="st-pill st-cancelled">取消</span>。
            流程分兩步：<b>①計畫</b>（年月、課程、部門、講師/開課單位、時數）→ <b>②確認實行</b>（實際開課日期、時段、地點、參加人員），
            按下「確認實行」存檔後狀態才轉為已完成。
            KPI 達成率＝當月「已完成」場次 ÷「計畫」場次（取消不計入）；部門留空＝全公司課程。
        </div>
<?php endif; ?>
    </div>
    <?php include '../partPage/footer.html' ?>
</div>
</div>

<!-- 新增/編輯「訓練計畫」modal（只填計畫內容，不含日期時間/地點/人員） -->
<div class="tr-mask" id="edMask"><div class="tr-modal">
    <div class="m-head"><span id="edTitle">新增訓練計畫</span><span class="m-close" onclick="closeMask('edMask')">✕</span></div>
    <div class="m-body">
        <div class="grid2">
            <div><label>年度 *</label><input type="number" id="edYear" step="1"></div>
            <div><label>計畫月份 *</label><select id="edMonth"></select></div>
            <div><label>課程/訓練名稱 *</label><input type="text" id="edCourse" maxlength="100"></div>
            <div><label>對象部門（留空＝全公司）</label><select id="edDept"><option value="">全公司</option></select></div>
            <div><label>訓練類型</label><select id="edType"><option value="internal">內訓</option><option value="external">外訓</option></select></div>
            <div><label>時數（預計）</label><input type="number" id="edHours" step="any" min="0"></div>
        </div>
        <div id="edInternalBox">
            <label>講師（部門→人員；外部講師可直接打字）</label>
            <div style="display:flex;gap:6px;">
                <select id="edTrainerDept" style="flex:0 0 130px;"><option value="">部門</option></select>
                <select id="edTrainerPerson" style="flex:0 0 130px;"><option value="">人員</option></select>
                <input type="text" id="edTrainer" maxlength="50" placeholder="講師姓名" style="flex:1;">
            </div>
        </div>
        <div id="edExternalBox" style="display:none;">
            <label>開課單位／主辦（外訓）*</label><input type="text" id="edOrgUnit" maxlength="100" placeholder="例：中衛發展中心">
        </div>
        <label>備註</label><input type="text" id="edNote" maxlength="200">
        <div class="tr-hint"><i class="fa fa-info-circle"></i>
            實際開課日期、時段、上課地點與參加人員，請於清單按 <b>確認實行</b> 登錄；計畫存檔後狀態為「計畫中」。</div>
    </div>
    <div class="m-foot">
        <button class="b-cancel" onclick="closeMask('edMask')">取消</button>
        <button class="b-ok" onclick="submitEd()">儲存計畫</button>
    </div>
</div></div>

<!-- 確認實行 modal（實際開課日期時間、地點、參加人員） -->
<div class="tr-mask" id="exMask"><div class="tr-modal wide">
    <div class="m-head"><span id="exTitle">確認實行</span><span class="m-close" onclick="closeMask('exMask')">✕</span></div>
    <div class="m-body">
        <div class="ex-plan" id="exPlanInfo"></div>
        <div class="grid2">
            <div><label>實際開課日期 *</label><input type="date" id="exDone"></div>
            <div><label>上課地點</label><input type="text" id="exLocation" maxlength="100" placeholder="例：二樓會議室"></div>
            <div><label>時段（起）</label><input type="time" id="exStart"></div>
            <div><label>時段（迄）</label><input type="time" id="exEnd"></div>
        </div>

        <div class="att-sec">
            <div style="font-weight:bold;color:#5b3a1e;margin:12px 0 4px;">參加人員名單 <small id="attCount" style="color:#8a6d45;font-weight:normal;"></small></div>
            <div style="display:flex;gap:6px;align-items:center;flex-wrap:wrap;margin-bottom:4px;">
                <select id="attDept" style="width:150px;height:28px;border:1px solid #D8BE93;border-radius:4px;"><option value="">選部門載入人員…</option></select>
                <button type="button" class="b-att" onclick="attAddChecked()"><i class="fa fa-user-plus"></i> 加入勾選人員</button>
                <label style="margin:0;font-size:12px;color:#8a6d45;"><input type="checkbox" id="attPickAll"> 全選</label>
            </div>
            <div id="attPeopleBox" class="att-people"></div>
            <div class="att-list-wrap">
                <table class="att-tbl"><thead><tr><th>姓名</th><th>部門</th><th>實到</th><th>簽名</th><th></th></tr></thead>
                <tbody id="attBody"></tbody></table>
            </div>
        </div>
    </div>
    <div class="m-foot">
        <button class="b-cancel" onclick="printSignSheet()"><i class="fa fa-print"></i> 列印簽到表</button>
        <button class="b-cancel" id="exRevert" style="display:none;color:#DD5138;" onclick="revertPlanned()"><i class="fa fa-undo"></i> 退回計畫中</button>
        <button class="b-cancel" onclick="closeMask('exMask')">取消</button>
        <button class="b-ok" id="exSave" onclick="submitEx()">確認實行完成</button>
    </div>
</div></div>

<!-- 角色說明 modal -->
<div class="tr-mask" id="helpMask"><div class="tr-modal">
    <div class="m-head"><span>角色權限說明</span><span class="m-close" onclick="closeMask('helpMask')">✕</span></div>
    <div class="m-body" style="font-size:13px;color:#5b3a1e;line-height:1.8;">
        <b>訓練檢閱</b>：檢視訓練計畫/紀錄、月達成率與匯出。<br>
        <b>訓練登錄</b>：檢閱＋新增/編輯訓練場次、登錄完成。<br>
        <b>訓練管理員</b>：登錄＋刪除場次。<br>
        <b>管理者</b>：系統管理者固定擁有全部權限。<br>
        <hr style="border-color:#EADFC8;">
        本頁資料為 KPI「人員教育訓練達成率(#19)」計算來源；達成率依「計畫月份」歸月計算。
    </div>
</div></div>

<script src="../../resource/js/jquery.min.js"></script>
<script src="../../resource/js/bootstrap.min.js"></script>
<script src="../../resource/js/nprogress.js"></script>
<script src="../../resource/js/custom.min.js"></script>
<script>
$(document).ready(function(){
    var $am = $('#sidebar-menu .nav.side-menu > li.active');
    if ($am.length) { $am.removeClass('active').find('ul.child_menu').hide(); $am.find('li.current-page').removeClass('current-page'); }
    $('#sidebar-menu').css('visibility','visible');
});

var API = '../../src/store/Training_API.php';
var META = null, ROWS = [], PERMS = null;
var canView = <?= $perms['canView'] ? 'true' : 'false' ?>;
var STATUS_LABEL = {planned:'計畫中', done:'已完成', cancelled:'取消'};

function closeMask(id){ document.getElementById(id).style.display='none'; }
function openMask(id){ document.getElementById(id).style.display='block'; }
function esc(s){ return String(s==null?'':s).replace(/[&<>"']/g,function(c){
    return {'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#39;'}[c]; }); }
function fmtDate(d){ return d ? String(d).substr(0,10) : ''; }
function numTrim(v){ if (v==null||v==='') return ''; var n=parseFloat(v); return (Math.round(n*10)/10)+''; }

function loadMeta(cb){
    $.getJSON(API, {action:'meta'}, function(m){
        if (!m.ok){ alert(m.error||'載入失敗'); return; }
        META = m; PERMS = m.perms;
        var $y = $('#yearSel').empty();
        m.years.forEach(function(y){ $y.append('<option value="'+y+'">'+y+'</option>'); });
        $y.val(m.cur_year);
        var $d = $('#deptSel'), $ed = $('#edDept'), $td = $('#edTrainerDept'), $ad = $('#attDept');
        m.departments.forEach(function(d){
            $d.append('<option value="'+d.id+'">'+esc(d.name)+'</option>');
            $ed.append('<option value="'+d.id+'">'+esc(d.name)+'</option>');
            $td.append('<option value="'+d.id+'">'+esc(d.name)+'</option>');
            $ad.append('<option value="'+d.id+'">'+esc(d.name)+'</option>');
        });
        var $em = $('#edMonth').empty();
        for (var i=1;i<=12;i++) $em.append('<option value="'+i+'">'+i+'月</option>');
        if (m.perms.canEdit) $('#btnAdd').show();
        if (cb) cb();
    });
}

function loadList(){
    NProgress.start();
    $.getJSON(API, {action:'list', year:$('#yearSel').val()}, function(res){
        NProgress.done();
        if (!res.ok){ alert(res.error||'載入失敗'); return; }
        ROWS = res.rows; PERMS = res.perms;
        renderStat(res);
        renderTable();
    }).fail(function(x){ NProgress.done(); alert('載入失敗：'+(x.responseJSON&&x.responseJSON.error||x.status)); });
}

function renderStat(res){
    $('#yrRate').text(res.year+' 年度達成率：'+(res.year_rate===null?'—':res.year_rate+'%')
        +'（'+res.year_num+'/'+res.year_den+' 場）');
    var h = '';
    for (var m=1;m<=12;m++){
        var s = res.summary[m];
        if (!s.den){ h += '<span class="mon-pill empty">'+m+'月 —</span> '; continue; }
        var rate = Math.round(s.num/s.den*1000)/10;
        h += '<span class="mon-pill'+(rate<95?' below':'')+'">'+m+'月 <b>'+rate+'%</b> ('+s.num+'/'+s.den+')</span> ';
    }
    $('#monWrap').html(h);
}

function statPill(s){ return '<span class="st-pill st-'+s+'">'+(STATUS_LABEL[s]||s)+'</span>'; }

function renderTable(){
    var dep = $('#deptSel').val(), stt = $('#statSel').val(), kw = $.trim($('#kwSel').val()).toLowerCase();
    var html = '';
    ROWS.forEach(function(r){
        if (dep && String(r.dept_id)!==String(dep)) return;
        if (stt && r.status!==stt) return;
        if (kw && String(r.course_name).toLowerCase().indexOf(kw)<0) return;
        var ext = r.train_type==='external';
        html += '<tr>';
        html += '<td>'+r.plan_month+'月</td>';
        html += '<td>'+esc(r.dept_name||'')+'</td>';
        html += '<td class="t-left"><b>'+esc(r.course_name)+'</b></td>';
        html += '<td>'+(ext?'<span style="color:#c0762c;">外訓</span>':'內訓')+'</td>';
        html += '<td>'+esc((ext?r.org_unit:r.trainer)||'—')+'</td>';
        html += '<td>'+(r.hours==null?'—':numTrim(r.hours))+'</td>';
        html += '<td>'+(r.target_headcount==null?'—':r.target_headcount)+'</td>';
        html += '<td>'+(r.actual_headcount==null?'—':r.actual_headcount)+'</td>';
        html += '<td>'+statPill(r.status)+'</td>';
        html += '<td>'+(fmtDate(r.done_date)||'—')+'</td>';
        html += '<td style="white-space:nowrap;">';
        if (PERMS.canEdit) {
            html += '<span class="tr-op" onclick="openEd('+r.session_id+')" title="修改計畫內容"><i class="fa fa-pencil"></i>計畫</span>';
            if (r.status==='cancelled') {
                html += '<span class="tr-op" onclick="setStatus('+r.session_id+',\'planned\')" title="恢復為計畫中"><i class="fa fa-undo"></i>恢復</span>';
            } else if (r.status==='done') {
                html += '<span class="tr-op" onclick="openEx('+r.session_id+')" title="修改實行紀錄"><i class="fa fa-check-square-o"></i>實行紀錄</span>';
            } else {
                html += '<span class="tr-op" onclick="openEx('+r.session_id+')" title="登錄實際開課日期/地點/人員"><i class="fa fa-check-square-o"></i>確認實行</span>';
                html += '<span class="tr-op" onclick="setStatus('+r.session_id+',\'cancelled\')" title="取消此計畫"><i class="fa fa-ban"></i>取消</span>';
            }
            html += '<span class="tr-op" onclick="copySession('+r.session_id+')" title="複製內容(不帶名單)"><i class="fa fa-copy"></i>複製</span>';
        }
        if (PERMS.canAdmin) html += '<span class="tr-op" style="color:#DD5138;" onclick="delSession('+r.session_id+')" title="刪除場次"><i class="fa fa-trash"></i></span>';
        if (!PERMS.canEdit && !PERMS.canAdmin) html += '—';
        html += '</td></tr>';
    });
    $('#trBody').html(html || '<tr><td colspan="11" style="padding:16px;color:#8a6d45;">無符合條件的訓練場次</td></tr>');
}

$('#deptSel,#statSel').on('change', renderTable);
$('#kwSel').on('input', renderTable);
$('#yearSel').on('change', loadList);

/* ---------- 新增/編輯 ---------- */
var ATT = [];   // 應參加名單 [{user_id,user_name,dept_name,attended,signed}]
function applyType(){
    var ext = $('#edType').val()==='external';
    $('#edExternalBox').toggle(ext);
    $('#edInternalBox').toggle(!ext);
}
$('#edType').on('change', applyType);
/* 計畫 modal：只有計畫內容（年月/部門/課程/類型/講師或單位/時數/備註） */
function openEd(sid){
    var r = sid ? ROWS.find(function(x){ return String(x.session_id)===String(sid); }) : null;
    $('#edTitle').text(r ? '編輯訓練計畫' : '新增訓練計畫');
    $('#edMask').data('sid', r ? r.session_id : 0);
    $('#edYear').val(r ? r.year : $('#yearSel').val());
    $('#edMonth').val(r ? r.plan_month : (META.cur_month));
    $('#edDept').val(r && r.dept_id!=null ? r.dept_id : '');
    $('#edCourse').val(r ? r.course_name : '');
    $('#edType').val(r ? (r.train_type||'internal') : 'internal'); applyType();
    $('#edTrainer').val(r ? (r.trainer||'') : ''); $('#edTrainerDept').val(''); $('#edTrainerPerson').html('<option value="">人員</option>');
    $('#edOrgUnit').val(r ? (r.org_unit||'') : '');
    $('#edHours').val(r && r.hours!=null ? numTrim(r.hours) : '');
    $('#edNote').val(r ? (r.note||'') : '');
    openMask('edMask');
    setTimeout(function(){ $('#edCourse').focus(); }, 100);
}
$('#btnAdd').on('click', function(){ openEd(0); });

/* 確認實行 modal：實際開課日期/時段/地點＋參加人員 */
var EXROW = null;
function openEx(sid){
    var r = ROWS.find(function(x){ return String(x.session_id)===String(sid); });
    if (!r) return;
    EXROW = r;
    var done = r.status==='done';
    $('#exTitle').text(done ? '實行紀錄（已完成）' : '確認實行');
    $('#exSave').text(done ? '儲存實行紀錄' : '確認實行完成');
    $('#exRevert').toggle(done);
    $('#exMask').data('sid', r.session_id);
    var ext = r.train_type==='external';
    $('#exPlanInfo').html(
        '<div><b>'+esc(r.course_name)+'</b> '+statPill(r.status)+'</div>'
      + '<div>計畫：'+r.year+' 年 '+r.plan_month+' 月　對象部門：'+esc(r.dept_name||'全公司')
      + '　類型：'+(ext?'外訓':'內訓')+'　'+(ext?'開課單位':'講師')+'：'+esc((ext?r.org_unit:r.trainer)||'—')
      + '　時數：'+(r.hours==null?'—':numTrim(r.hours))+'</div>');
    $('#exDone').val(fmtDate(r.done_date) || (done ? '' : (META.today||'')));
    $('#exStart').val(r.start_time||''); $('#exEnd').val(r.end_time||'');
    $('#exLocation').val(r.location||'');
    $('#attDept').val(''); $('#attPeopleBox').html('<span class="empty">選部門載入人員</span>');
    $('#attPickAll').prop('checked', false);
    ATT = [];
    $.getJSON(API, {action:'get_attendees', session_id:r.session_id}, function(res){
        if (res.ok) ATT = res.attendees.map(function(a){ return {user_id:+a.user_id, user_name:a.user_name, dept_name:a.dept_name, attended:+a.attended, signed:+a.signed}; });
        renderAtt();
    });
    renderAtt();
    openMask('exMask');
    setTimeout(function(){ $('#exDone').focus(); }, 100);
}
/* 講師：部門→人員 */
$('#edTrainerDept').on('change', function(){
    var did=$(this).val(); var $p=$('#edTrainerPerson').html('<option value="">人員</option>');
    if(did) $.getJSON(API,{action:'people',dept_id:did},function(res){ if(res.ok) res.people.forEach(function(u){ $p.append('<option value="'+u.id+'">'+esc(u.user_cname)+'</option>'); }); });
});
$('#edTrainerPerson').on('change', function(){ var t=$(this).find('option:selected').text(); if($(this).val()) $('#edTrainer').val(t); });
/* 參加人員 */
$('#attDept').on('change', function(){
    var did=$(this).val(); var $b=$('#attPeopleBox');
    if(!did){ $b.html('<span class="empty">選部門載入人員</span>'); return; }
    $b.html('<span class="empty">載入中…</span>');
    $.getJSON(API,{action:'people',dept_id:did},function(res){
        if(!res.ok){ $b.html('<span class="empty">載入失敗</span>'); return; }
        var deptName=$('#attDept option:selected').text();
        var h=''; res.people.forEach(function(u){
            var inList=ATT.some(function(a){return a.user_id===+u.id;});
            h+='<label><input type="checkbox" class="att-ck" value="'+u.id+'" data-name="'+esc(u.user_cname)+'" data-dept="'+esc(deptName)+'"'+(inList?' checked disabled':'')+'> '+esc(u.user_cname)+(inList?'(已加)':'')+'</label>';
        });
        $b.html(h||'<span class="empty">此部門無人員</span>');
        $('#attPickAll').prop('checked',false);
    });
});
$('#attPickAll').on('change', function(){ $('#attPeopleBox .att-ck:not(:disabled)').prop('checked', this.checked); });
function attAddChecked(){
    $('#attPeopleBox .att-ck:checked:not(:disabled)').each(function(){
        var id=+$(this).val();
        if(!ATT.some(function(a){return a.user_id===id;}))
            ATT.push({user_id:id, user_name:$(this).data('name'), dept_name:$(this).data('dept'), attended:0, signed:0});
    });
    renderAtt();
    $('#attDept').trigger('change');
}
function renderAtt(){
    var h='';
    ATT.forEach(function(a,i){
        h+='<tr><td class="t-left">'+esc(a.user_name||'')+'</td><td>'+esc(a.dept_name||'')+'</td>'
          +'<td><input type="checkbox" '+(a.attended?'checked':'')+' onchange="ATT['+i+'].attended=this.checked?1:0;attCount()"></td>'
          +'<td>'+(a.signed?'<span style="color:#8A5A2B;">已簽</span>':'—')+'</td>'
          +'<td><span class="att-del" onclick="attDel('+i+')"><i class="fa fa-times"></i></span></td></tr>';
    });
    $('#attBody').html(h||'<tr><td colspan="5" style="color:#8a6d45;padding:8px;">尚未加入人員</td></tr>');
    attCount();
}
function attCount(){ var a=ATT.filter(function(x){return x.attended;}).length; $('#attCount').text('（應到 '+ATT.length+'　實到 '+a+'）'); }
function attDel(i){ ATT.splice(i,1); renderAtt(); if($('#attDept').val()) $('#attDept').trigger('change'); }

/* 儲存計畫（不動實行欄位） */
function submitEd(){
    if (!$.trim($('#edCourse').val())){ alert('請填課程名稱'); return; }
    if ($('#edType').val()==='external' && !$.trim($('#edOrgUnit').val())){ alert('外訓請填開課單位'); return; }
    $.post(API, {action:'save_session', session_id:$('#edMask').data('sid'),
        year:$('#edYear').val(), plan_month:$('#edMonth').val(), dept_id:$('#edDept').val(),
        course_name:$('#edCourse').val(), train_type:$('#edType').val(),
        trainer:$('#edTrainer').val(), trainer_id:$('#edTrainerPerson').val(), org_unit:$('#edOrgUnit').val(),
        hours:$('#edHours').val(), note:$('#edNote').val()},
    function(res){
        if (!res.ok){ alert(res.error||'儲存失敗'); return; }
        closeMask('edMask'); loadList();
    }, 'json').fail(function(x){ alert('儲存失敗：'+(x.responseJSON&&x.responseJSON.error||x.status)); });
}

/* 確認實行：寫實際開課日期/時段/地點（狀態轉已完成）＋參加名單 */
function submitEx(){
    var sid = $('#exMask').data('sid');
    if (!$('#exDone').val()){ alert('請選擇實際開課日期'); $('#exDone').focus(); return; }
    if ($('#exStart').val() && $('#exEnd').val() && $('#exEnd').val() < $('#exStart').val()){
        alert('時段迄不可早於時段起'); return; }
    if (!ATT.length && !confirm('尚未加入任何參加人員，仍要確認實行？')) return;
    $.post(API, {action:'save_execution', session_id:sid, done_date:$('#exDone').val(),
        start_time:$('#exStart').val(), end_time:$('#exEnd').val(), location:$('#exLocation').val()},
    function(res){
        if (!res.ok){ alert(res.error||'儲存失敗'); return; }
        $.post(API, {action:'save_attendees', session_id:sid, attendees:JSON.stringify(ATT)}, function(r2){
            if (!r2.ok){ alert('實行紀錄已存，但名單儲存失敗：'+(r2.error||'')); }
            closeMask('exMask'); loadList();
        }, 'json').fail(function(){ closeMask('exMask'); loadList(); });
    }, 'json').fail(function(x){ alert('儲存失敗：'+(x.responseJSON&&x.responseJSON.error||x.status)); });
}
/* 退回計畫中（實行 modal 內） */
function revertPlanned(){
    if (!confirm('退回為「計畫中」？實際開課日將清空（時段/地點與名單保留），此場次不再計入當月完成數。')) return;
    setStatus($('#exMask').data('sid'), 'planned', true);
}
/* 狀態切換：取消計畫 / 恢復計畫 / 退回計畫中 */
function setStatus(sid, status, fromEx){
    if (!fromEx){
        var msg = status==='cancelled' ? '取消此訓練計畫？（取消的場次不計入 KPI 分母）' : '恢復為「計畫中」？';
        if (!confirm(msg)) return;
    }
    $.post(API, {action:'set_status', session_id:sid, status:status}, function(res){
        if (!res.ok){ alert(res.error||'狀態變更失敗'); return; }
        closeMask('exMask'); loadList();
    }, 'json').fail(function(x){ alert('狀態變更失敗：'+(x.responseJSON&&x.responseJSON.error||x.status)); });
}
function copySession(sid){
    if (!confirm('複製此場次內容為新的一場（不含參加名單）？')) return;
    $.post(API, {action:'copy_session', session_id:sid}, function(res){
        if (!res.ok){ alert(res.error||'複製失敗'); return; }
        loadList(); alert('已複製為新場次，可再編輯調整並另建參加名單');
    }, 'json').fail(function(x){ alert('複製失敗：'+(x.responseJSON&&x.responseJSON.error||x.status)); });
}
/* 列印簽到表（確認實行 modal 中的場次＋名單） */
function printSignSheet(){
    var r = EXROW || {};
    var course=r.course_name||'（課程名稱）';
    var ext=r.train_type==='external';
    var lect=ext?('外訓／開課單位：'+(r.org_unit||'')):('講師：'+(r.trainer||''));
    var when=(r.year||'')+'年'+(r.plan_month||'')+'月　實際日期：'+($('#exDone').val()||'____/__/__')
        +'　時段：'+($('#exStart').val()||'__:__')+'~'+($('#exEnd').val()||'__:__');
    var where='地點：'+($('#exLocation').val()||'____________')+'　時數：'+(r.hours==null?'__':numTrim(r.hours))+' 小時';
    var rows='';
    (ATT.length?ATT:[{},{},{},{},{},{},{},{},{},{}]).forEach(function(a,i){
        rows+='<tr><td>'+(i+1)+'</td><td>'+esc(a.user_name||'')+'</td><td>'+esc(a.dept_name||'')+'</td><td style="width:160px;"></td><td style="width:80px;"></td></tr>';
    });
    var html='<div style="text-align:center;"><div style="font-size:18px;font-weight:bold;">超正齒輪科技有限公司</div>'
        +'<div style="font-size:15px;margin-top:2px;">教育訓練簽到表</div></div>'
        +'<table class="sf-info"><tr><td colspan="2">課程名稱：'+esc(course)+'</td></tr>'
        +'<tr><td>'+esc(lect)+'</td><td>'+esc(where)+'</td></tr><tr><td colspan="2">'+esc(when)+'</td></tr></table>'
        +'<table class="sf"><thead><tr><th style="width:36px;">序</th><th>姓名</th><th>部門</th><th>簽名</th><th>時數確認</th></tr></thead><tbody>'+rows+'</tbody></table>'
        +'<div style="margin-top:14px;font-size:13px;">講師/主辦簽章：______________　　單位主管簽章：______________</div>';
    var w=window.open('','_blank'); if(!w){alert('請允許彈出視窗');return;}
    var css='body{font-family:"Microsoft JhengHei","微軟正黑體",sans-serif;color:#000;padding:14px;}'
        +'table.sf{width:100%;border-collapse:collapse;font-size:13px;margin-top:8px;}table.sf th,table.sf td{border:1px solid #333;padding:6px;text-align:center;height:30px;}'
        +'table.sf-info{width:100%;border-collapse:collapse;font-size:13px;margin-top:10px;}table.sf-info td{border:1px solid #999;padding:5px 8px;text-align:left;}'
        +'@media print{@page{size:A4;margin:12mm;}}';
    w.document.write('<html><head><meta charset="utf-8"><title>教育訓練簽到表</title><style>'+css+'</style></head><body>'+html+'<scr'+'ipt>window.onload=function(){setTimeout(function(){window.print();},150);};</scr'+'ipt></body></html>');
    w.document.close();
}
function delSession(sid){
    if (!confirm('刪除此訓練場次？')) return;
    $.post(API, {action:'delete_session', session_id:sid}, function(res){
        if (!res.ok){ alert(res.error||'刪除失敗'); return; }
        loadList();
    }, 'json');
}

/* ---------- CSV ---------- */
$('#btnCsv').on('click', function(){
    var rows = [['年','月','對象部門','課程名稱','類型','講師/開課單位','時數','應到','實到','狀態','實際開課日','時段','地點','備註']];
    ROWS.forEach(function(r){
        var ext=r.train_type==='external';
        rows.push([r.year, r.plan_month, r.dept_name||'', r.course_name, ext?'外訓':'內訓', (ext?r.org_unit:r.trainer)||'',
            r.hours==null?'':numTrim(r.hours), r.target_headcount==null?'':r.target_headcount,
            r.actual_headcount==null?'':r.actual_headcount, STATUS_LABEL[r.status]||r.status,
            fmtDate(r.done_date), (r.start_time||'')+(r.end_time?'~'+r.end_time:''), r.location||'', r.note||'']);
    });
    var csv = '﻿' + rows.map(function(l){
        return l.map(function(v){ return '"'+String(v==null?'':v).replace(/"/g,'""')+'"'; }).join(',');
    }).join('\r\n');
    var a = document.createElement('a');
    a.href = URL.createObjectURL(new Blob([csv], {type:'text/csv;charset=utf-8;'}));
    a.download = '教育訓練_'+$('#yearSel').val()+'.csv';
    a.click();
});

$('#btnRoleHelp').on('click', function(){ openMask('helpMask'); });
$('.tr-mask').on('click', function(e){ if (e.target===this) this.style.display='none'; });
$(document).on('focus', '.tr-modal input[type=text], .tr-modal input[type=number]', function(){ this.select(); });
$(document).on('dblclick', '.tr-modal input[type=text], .tr-modal input[type=number]', function(){ this.value=''; });

if (canView) loadMeta(function(){ loadList(); });
</script>
</body>
</html>
