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
                    <th>月份</th><th>部門</th><th>課程名稱</th><th>講師/主辦</th><th>時數</th>
                    <th>應到</th><th>實到</th><th>狀態</th><th>完成日</th><th>操作</th>
                </tr></thead>
                <tbody id="trBody"><tr><td colspan="10" style="padding:20px;color:#8a6d45;">載入中…</td></tr></tbody>
            </table>
        </div>
        <div style="font-size:11px;color:#8a6d45;margin-top:4px;">
            狀態：<span class="st-pill st-planned">計畫中</span> <span class="st-pill st-done">已完成</span>
            <span class="st-pill st-cancelled">取消</span>。
            KPI 達成率＝當月「已完成」場次 ÷「計畫」場次（取消不計入）；部門留空＝全公司課程。
        </div>
<?php endif; ?>
    </div>
    <?php include '../partPage/footer.html' ?>
</div>
</div>

<!-- 新增/編輯 modal -->
<div class="tr-mask" id="edMask"><div class="tr-modal">
    <div class="m-head"><span id="edTitle">新增訓練場次</span><span class="m-close" onclick="closeMask('edMask')">✕</span></div>
    <div class="m-body">
        <div class="grid2">
            <div><label>年度 *</label><input type="number" id="edYear" step="1"></div>
            <div><label>計畫月份 *</label><select id="edMonth"></select></div>
            <div><label>部門（留空＝全公司）</label><select id="edDept"><option value="">全公司</option></select></div>
            <div><label>課程/訓練名稱 *</label><input type="text" id="edCourse" maxlength="100"></div>
            <div><label>講師/主辦</label><input type="text" id="edTrainer" maxlength="50"></div>
            <div><label>時數</label><input type="number" id="edHours" step="any" min="0"></div>
            <div><label>應到人數</label><input type="number" id="edTarget" step="1" min="0"></div>
            <div><label>實到人數</label><input type="number" id="edActual" step="1" min="0"></div>
            <div><label>狀態</label><select id="edStatus">
                <option value="planned">計畫中</option><option value="done">已完成</option><option value="cancelled">取消</option>
            </select></div>
            <div><label>完成日（狀態=已完成時）</label><input type="date" id="edDone"></div>
        </div>
        <label>備註</label><input type="text" id="edNote" maxlength="200">
    </div>
    <div class="m-foot">
        <button class="b-cancel" onclick="closeMask('edMask')">取消</button>
        <button class="b-ok" onclick="submitEd()">儲存</button>
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
        var $d = $('#deptSel'), $ed = $('#edDept');
        m.departments.forEach(function(d){
            $d.append('<option value="'+d.id+'">'+esc(d.name)+'</option>');
            $ed.append('<option value="'+d.id+'">'+esc(d.name)+'</option>');
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
        html += '<tr>';
        html += '<td>'+r.plan_month+'月</td>';
        html += '<td>'+esc(r.dept_name||'')+'</td>';
        html += '<td class="t-left"><b>'+esc(r.course_name)+'</b></td>';
        html += '<td>'+esc(r.trainer||'—')+'</td>';
        html += '<td>'+(r.hours==null?'—':numTrim(r.hours))+'</td>';
        html += '<td>'+(r.target_headcount==null?'—':r.target_headcount)+'</td>';
        html += '<td>'+(r.actual_headcount==null?'—':r.actual_headcount)+'</td>';
        html += '<td>'+statPill(r.status)+'</td>';
        html += '<td>'+(fmtDate(r.done_date)||'—')+'</td>';
        html += '<td>';
        if (PERMS.canEdit) html += '<span class="tr-op" onclick="openEd('+r.session_id+')"><i class="fa fa-pencil"></i>編輯</span>';
        if (PERMS.canAdmin) html += '<span class="tr-op" style="color:#DD5138;" onclick="delSession('+r.session_id+')"><i class="fa fa-trash"></i></span>';
        if (!PERMS.canEdit) html += '—';
        html += '</td></tr>';
    });
    $('#trBody').html(html || '<tr><td colspan="10" style="padding:16px;color:#8a6d45;">無符合條件的訓練場次</td></tr>');
}

$('#deptSel,#statSel').on('change', renderTable);
$('#kwSel').on('input', renderTable);
$('#yearSel').on('change', loadList);

/* ---------- 新增/編輯 ---------- */
function openEd(sid){
    var r = sid ? ROWS.find(function(x){ return String(x.session_id)===String(sid); }) : null;
    $('#edTitle').text(r ? '編輯訓練場次' : '新增訓練場次');
    $('#edMask').data('sid', r ? r.session_id : 0);
    $('#edYear').val(r ? r.year : $('#yearSel').val());
    $('#edMonth').val(r ? r.plan_month : (META.cur_month));
    $('#edDept').val(r && r.dept_id!=null ? r.dept_id : '');
    $('#edCourse').val(r ? r.course_name : '');
    $('#edTrainer').val(r ? (r.trainer||'') : '');
    $('#edHours').val(r && r.hours!=null ? numTrim(r.hours) : '');
    $('#edTarget').val(r && r.target_headcount!=null ? r.target_headcount : '');
    $('#edActual').val(r && r.actual_headcount!=null ? r.actual_headcount : '');
    $('#edStatus').val(r ? r.status : 'planned');
    $('#edDone').val(r ? fmtDate(r.done_date) : '');
    $('#edNote').val(r ? (r.note||'') : '');
    openMask('edMask');
    setTimeout(function(){ $('#edCourse').focus(); }, 100);
}
$('#btnAdd').on('click', function(){ openEd(0); });
$('#edStatus').on('change', function(){
    if (this.value==='done' && !$('#edDone').val()) $('#edDone').val(META.today || new Date().toISOString().substr(0,10));
});
function submitEd(){
    if (!$.trim($('#edCourse').val())){ alert('請填課程名稱'); return; }
    $.post(API, {action:'save_session', session_id:$('#edMask').data('sid'),
        year:$('#edYear').val(), plan_month:$('#edMonth').val(), dept_id:$('#edDept').val(),
        course_name:$('#edCourse').val(), trainer:$('#edTrainer').val(), hours:$('#edHours').val(),
        target_headcount:$('#edTarget').val(), actual_headcount:$('#edActual').val(),
        status:$('#edStatus').val(), done_date:$('#edDone').val(), note:$('#edNote').val()},
    function(res){
        if (!res.ok){ alert(res.error||'儲存失敗'); return; }
        closeMask('edMask'); loadList();
    }, 'json').fail(function(x){ alert('儲存失敗：'+(x.responseJSON&&x.responseJSON.error||x.status)); });
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
    var rows = [['年','月','部門','課程名稱','講師/主辦','時數','應到','實到','狀態','完成日','備註']];
    ROWS.forEach(function(r){
        rows.push([r.year, r.plan_month, r.dept_name||'', r.course_name, r.trainer||'',
            r.hours==null?'':numTrim(r.hours), r.target_headcount==null?'':r.target_headcount,
            r.actual_headcount==null?'':r.actual_headcount, STATUS_LABEL[r.status]||r.status,
            fmtDate(r.done_date), r.note||'']);
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
