<?php
/**
 * 供應商稽核管理（KPI 2-GM-04-01 第6項 廠商稽核按時執行率 的來源頁）
 * 廠商主檔沿用 maker_list；週期自動推算下次應稽核日；半年結算(6/12月)。
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
        .va-toolbar select, .va-toolbar input[type=text], .va-toolbar button {
            height:30px; font-size:13px; line-height:1; padding:0 10px; border:1px solid #D8BE93;
            border-radius:4px; background:#fff; color:#5b3a1e; cursor:pointer; }
        .va-toolbar button:hover { background:#F7E0BD; }
        .va-toolbar .btn-warm { background:#F0A24B; color:#fff; border-color:#d98a33; }
        .va-role-badge { margin-left:auto; font-size:13px; color:#5b3a1e; background:#F7E0BD; border-radius:12px; padding:4px 12px; }
        .va-role-badge .fa-question-circle { cursor:pointer; color:#b5762a; margin-left:5px; }
        .va-stat { display:flex; flex-wrap:wrap; gap:18px; align-items:center; margin-bottom:10px;
            border:1.5px solid #E8D5B5; border-radius:8px; padding:10px 14px; background:#FFF7E8; }
        .va-stat .s-num { font-size:22px; font-weight:bold; color:#8A5A2B; }
        .va-stat .s-lab { font-size:12px; color:#8a6d45; }
        .va-stat .s-rate.below { color:#DD5138; }
        .va-stat .s-rate.ok { color:#8A5A2B; }
        .va-table-wrap { overflow-x:auto; border:1px solid #E8D5B5; border-radius:6px; background:#fff; }
        table.va-table { width:100%; border-collapse:collapse; font-size:13px; }
        table.va-table th, table.va-table td { border:1px solid #EADFC8; padding:5px 8px; white-space:nowrap; text-align:center; }
        table.va-table thead th { position:sticky; top:0; z-index:5; background:#F7E0BD; color:#5b3a1e; font-weight:bold; }
        table.va-table tbody tr:nth-child(even) { background:#FBF6EC; }
        table.va-table tbody tr:hover { background:#FBF0DD; }
        table.va-table td.t-left { text-align:left; }
        .st-pill { display:inline-block; font-size:12px; border-radius:10px; padding:2px 9px; }
        .st-overdue { background:#DD5138; color:#fff; }
        .st-soon { background:#F0A24B; color:#fff; }
        .st-ok { background:#F7E0BD; color:#7a5217; }
        .st-nobaseline { background:#fff; color:#c4863a; border:1px dashed #D8BE93; }
        .st-unmanaged { background:#efe7d8; color:#b0a390; }
        .rs-pass { color:#8A5A2B; } .rs-conditional { color:#c98a2e; } .rs-fail { color:#DD5138; }
        .va-op { color:#b5762a; cursor:pointer; margin:0 4px; }
        .va-op:hover { color:#8A5A2B; text-decoration:underline; }
        .managed-yes { color:#8A5A2B; font-weight:bold; }
        .managed-no { color:#b0a390; }
        input[type=number]::-webkit-outer-spin-button, input[type=number]::-webkit-inner-spin-button { -webkit-appearance:none; margin:0; }
        input[type=number] { -moz-appearance:textfield; }
        .va-mask { display:none; position:fixed; inset:0; background:rgba(60,40,20,.45); z-index:1050; }
        .va-modal { background:#fff; border-radius:8px; max-width:560px; margin:52px auto; box-shadow:0 5px 25px rgba(0,0,0,.3);
            max-height:84vh; display:flex; flex-direction:column; }
        .va-modal.wide { max-width:820px; }
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
                <small style="color:#8a6d45;">KPI 2-GM-04-01 #6 廠商稽核按時執行率 來源頁（半年結算）</small></h2>
        </div>
        <div class="clearfix"></div>

<?php if (!$perms['canView']): ?>
        <div class="va-noperm">
            <h4><i class="fa fa-lock"></i> 無供應商稽核檢閱權限</h4>
            <p>請洽管理者於「使用者權限設定」指派「稽核檢閱／登錄／管理員」角色。</p>
        </div>
<?php else: ?>
        <div class="va-toolbar">
            <label>年度</label>
            <select id="yearSel"></select>
            <label>期別</label>
            <select id="halfSel"><option value="1">上半年(1-6月)</option><option value="2">下半年(7-12月)</option></select>
            <label>狀態</label>
            <select id="statSel">
                <option value="">全部</option><option value="managed">僅納管</option>
                <option value="overdue">逾期</option><option value="soon">即將到期</option>
                <option value="ok">正常</option><option value="nobaseline">未設基準</option><option value="unmanaged">未納管</option>
            </select>
            <input type="text" id="kwSel" placeholder="搜尋廠商" style="width:130px;">
            <button id="btnCsv"><i class="fa fa-file-text-o"></i> 匯出CSV</button>
            <button onclick="window.print()"><i class="fa fa-print"></i> 列印</button>
            <span class="va-role-badge">目前角色：<b><?= htmlspecialchars($roleLabel) ?></b>
                <i class="fa fa-question-circle" id="btnRoleHelp" title="角色權限說明"></i></span>
        </div>

        <div class="va-stat" id="statBar">
            <div><span class="s-num" id="stDen">—</span> <span class="s-lab">本期應稽核</span></div>
            <div><span class="s-num" id="stNum">—</span> <span class="s-lab">按時完成</span></div>
            <div><span class="s-num s-rate" id="stRate">—</span> <span class="s-lab">按時執行率（目標 ≥70%）</span></div>
            <div class="s-lab" id="stHint" style="margin-left:auto;"></div>
        </div>

        <div class="va-table-wrap">
            <table class="va-table" id="vaTable">
                <thead><tr>
                    <th>廠商編號</th><th>廠商名稱</th><th>週期(月)</th><th>下次應稽核日</th>
                    <th>狀態</th><th>最近稽核</th><th>納入管理</th><th>操作</th>
                </tr></thead>
                <tbody id="vaBody"><tr><td colspan="8" style="padding:20px;color:#8a6d45;">載入中…</td></tr></tbody>
            </table>
        </div>
        <div style="font-size:11px;color:#8a6d45;margin-top:4px;">
            狀態：<span class="st-pill st-overdue">逾期</span> <span class="st-pill st-soon">60天內到期</span>
            <span class="st-pill st-ok">正常</span> <span class="st-pill st-nobaseline">未設基準</span>
            <span class="st-pill st-unmanaged">未納管</span>。
            「納入管理」者才計入 KPI；下次應稽核日＝上次稽核日＋週期（登錄完成後自動前滾）；達成率按上/下半年結算。
        </div>
<?php endif; ?>
    </div>
    <?php include '../partPage/footer.html' ?>
</div>
</div>

<!-- 登錄稽核 modal -->
<div class="va-mask" id="recMask"><div class="va-modal">
    <div class="m-head"><span id="recTitle">登錄稽核</span><span class="m-close" onclick="closeMask('recMask')">✕</span></div>
    <div class="m-body">
        <div style="font-size:12px;color:#8a6d45;" id="recInfo"></div>
        <div class="grid2">
            <div><label>稽核日 *</label><input type="date" id="recDate"></div>
            <div><label>判定結果</label><select id="recResult">
                <option value="pass">合格</option><option value="conditional">限期改善</option><option value="fail">不合格</option>
            </select></div>
            <div><label>稽核分數</label><input type="number" id="recScore" step="1" min="0" max="100"></div>
            <div><label>稽核人員</label><input type="text" id="recAuditor" maxlength="50"></div>
            <div><label>報告編號</label><input type="text" id="recReport" maxlength="50"></div>
            <div><label>備註</label><input type="text" id="recNote" maxlength="200"></div>
        </div>
        <div style="font-size:12px;color:#b5762a;margin-top:8px;" id="recRoll"></div>
    </div>
    <div class="m-foot">
        <button class="b-cancel" onclick="closeMask('recMask')">取消</button>
        <button class="b-ok" onclick="submitRec()">登錄</button>
    </div>
</div></div>

<!-- 設定稽核屬性 modal -->
<div class="va-mask" id="setMask"><div class="va-modal">
    <div class="m-head"><span id="setTitle">稽核設定</span><span class="m-close" onclick="closeMask('setMask')">✕</span></div>
    <div class="m-body">
        <div class="grid2">
            <div><label>稽核週期（月）</label><input type="number" id="setCycle" step="1" min="0" placeholder="例：12"></div>
            <div><label>納入稽核管理（計入 KPI）</label><select id="setManaged">
                <option value="1">是</option><option value="0">否</option>
            </select></div>
            <div style="grid-column:1/3;"><label>下次應稽核日（基準）</label><input type="date" id="setBase"></div>
        </div>
        <div style="font-size:12px;color:#8a6d45;margin-top:8px;">
            設定基準到期日後，每次登錄稽核會依週期自動前滾，不需再手動維護。
        </div>
    </div>
    <div class="m-foot">
        <button class="b-cancel" onclick="closeMask('setMask')">取消</button>
        <button class="b-ok" onclick="submitSet()">儲存</button>
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
        <b>稽核檢閱</b>：檢視廠商清單、稽核歷史、本期統計與匯出。<br>
        <b>稽核登錄</b>：檢閱＋登錄各廠商的稽核完成紀錄。<br>
        <b>稽核管理員</b>：登錄＋設定週期/納管/基準到期日、刪除誤登紀錄。<br>
        <b>管理者</b>：系統管理者固定擁有全部權限。<br>
        <hr style="border-color:#EADFC8;">
        本頁資料為 KPI「廠商稽核按時執行率(#6)」計算來源；納管廠商依到期日落在上/下半年計入 KPI。
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

var API = '../../src/store/VendorAudit_API.php';
var META = null, ROWS = [], PERMS = null;
var canView = <?= $perms['canView'] ? 'true' : 'false' ?>;
var RESULT_LABEL = {pass:'合格', conditional:'限期改善', fail:'不合格'};
var STATUS_LABEL = {overdue:'逾期', soon:'即將到期', ok:'正常', nobaseline:'未設基準', unmanaged:'未納管'};

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
        for (var y=cy+1; y>=cy-4; y--) $y.append('<option value="'+y+'">'+y+'</option>');
        $y.val(cy);
        $('#halfSel').val(m.cur_half);
        if (cb) cb();
    });
}

function loadList(){
    NProgress.start();
    $.getJSON(API, {action:'list', year:$('#yearSel').val(), half:$('#halfSel').val()}, function(res){
        NProgress.done();
        if (!res.ok){ alert(res.error||'載入失敗'); return; }
        ROWS = res.rows; PERMS = res.perms;
        renderStat(res);
        renderTable();
    }).fail(function(x){ NProgress.done(); alert('載入失敗：'+(x.responseJSON&&x.responseJSON.error||x.status)); });
}

function renderStat(res){
    var lab = res.year+' '+(res.half===1?'上半年':'下半年');
    $('#stDen').text(res.stat.den);
    $('#stNum').text(res.stat.num);
    var $r = $('#stRate');
    if (res.stat.value === null){ $r.text('—').removeClass('below ok'); $('#stHint').text(lab+' 無應稽核廠商'); }
    else {
        var v = Math.round(res.stat.value*10)/10;
        $r.text(v+'%').toggleClass('below', v<70).toggleClass('ok', v>=70);
        $('#stHint').text(lab+'：'+res.stat.num+' / '+res.stat.den+' 按時完成');
    }
}

function statPill(s){ return '<span class="st-pill st-'+s+'">'+(STATUS_LABEL[s]||s)+'</span>'; }

function renderTable(){
    var stt = $('#statSel').val(), kw = $.trim($('#kwSel').val()).toLowerCase();
    var html = '';
    ROWS.forEach(function(r){
        if (stt === 'managed' && r.audit_managed!==1) return;
        if (stt && stt!=='managed' && r.status!==stt) return;
        if (kw && (String(r.maker_id).toLowerCase().indexOf(kw)<0 && String(r.maker_id_no).toLowerCase().indexOf(kw)<0)) return;
        var last = r.last ? (fmtDate(r.last.audit_date)+'（'+(RESULT_LABEL[r.last.result]||r.last.result)+'）') : '—';
        html += '<tr>';
        html += '<td>'+esc(r.maker_id_no)+'</td>';
        html += '<td class="t-left"><b>'+esc(r.maker_id||'')+'</b></td>';
        html += '<td>'+(r.audit_cycle_months==null?'—':r.audit_cycle_months)+'</td>';
        html += '<td>'+(fmtDate(r.audit_next_due)||'—')+'</td>';
        html += '<td>'+statPill(r.status)+'</td>';
        html += '<td>'+last+'</td>';
        html += '<td class="'+(r.audit_managed===1?'managed-yes':'managed-no')+'">'+(r.audit_managed===1?'✔ 是':'否')+'</td>';
        html += '<td>';
        html += PERMS.canEdit ? '<span class="va-op" onclick="openRec(\''+esc(r.maker_id_no)+'\')"><i class="fa fa-pencil"></i>登錄</span>' : '';
        html += PERMS.canAdmin ? '<span class="va-op" onclick="openSet(\''+esc(r.maker_id_no)+'\')"><i class="fa fa-gear"></i>設定</span>' : '';
        html += '<span class="va-op" onclick="openHis(\''+esc(r.maker_id_no)+'\')"><i class="fa fa-history"></i>歷史</span>';
        if (!PERMS.canEdit && !PERMS.canAdmin) {}
        html += '</td></tr>';
    });
    $('#vaBody').html(html || '<tr><td colspan="8" style="padding:16px;color:#8a6d45;">無符合條件的廠商</td></tr>');
}

$('#statSel').on('change', renderTable);
$('#kwSel').on('input', renderTable);
$('#yearSel,#halfSel').on('change', loadList);

function findRow(mid){ return ROWS.find(function(x){ return x.maker_id_no===mid; }); }

/* ---------- 登錄稽核 ---------- */
var recMk = null;
function openRec(mid){
    recMk = findRow(mid);
    $('#recTitle').text('登錄稽核：'+recMk.maker_id+'（'+recMk.maker_id_no+'）');
    $('#recInfo').html('目前下次應稽核日：<b>'+(fmtDate(recMk.audit_next_due)||'（未設定）')+'</b>　週期：'
        +(recMk.audit_cycle_months==null?'（未設）':recMk.audit_cycle_months+' 月'));
    $('#recDate').val(META.today);
    $('#recResult').val('pass'); $('#recScore').val(''); $('#recAuditor').val(''); $('#recReport').val(''); $('#recNote').val('');
    updateRoll(); openMask('recMask');
    setTimeout(function(){ $('#recDate').focus(); }, 100);
}
function updateRoll(){
    var cyc = recMk.audit_cycle_months, d = $('#recDate').val();
    if (cyc==null || !d){ $('#recRoll').text(''); return; }
    var dt = new Date(d); dt.setMonth(dt.getMonth()+parseInt(cyc,10));
    $('#recRoll').text('登錄後下次應稽核日將前滾為約 '+dt.toISOString().substr(0,10)+'（依週期 '+cyc+' 月）');
}
$('#recDate').on('change', updateRoll);
function submitRec(){
    if (!$('#recDate').val()){ alert('請選擇稽核日'); return; }
    $.post(API, {action:'record_audit', maker_id_no:recMk.maker_id_no, audit_date:$('#recDate').val(),
        result:$('#recResult').val(), score:$('#recScore').val(), auditor:$('#recAuditor').val(),
        report_no:$('#recReport').val(), note:$('#recNote').val()}, function(res){
        if (!res.ok){ alert(res.error||'登錄失敗'); return; }
        closeMask('recMask'); loadList();
    }, 'json').fail(function(x){ alert('登錄失敗：'+(x.responseJSON&&x.responseJSON.error||x.status)); });
}

/* ---------- 設定 ---------- */
var setMk = null;
function openSet(mid){
    setMk = findRow(mid);
    $('#setTitle').text('稽核設定：'+setMk.maker_id);
    $('#setCycle').val(setMk.audit_cycle_months==null?'':setMk.audit_cycle_months);
    $('#setBase').val(fmtDate(setMk.audit_next_due));
    $('#setManaged').val(String(setMk.audit_managed));
    openMask('setMask');
}
function submitSet(){
    $.post(API, {action:'save_vendor', maker_id_no:setMk.maker_id_no, cycle:$('#setCycle').val(),
        managed:$('#setManaged').val(), baseline_due:$('#setBase').val()}, function(res){
        if (!res.ok){ alert(res.error||'儲存失敗'); return; }
        closeMask('setMask'); loadList();
    }, 'json').fail(function(x){ alert('儲存失敗：'+(x.responseJSON&&x.responseJSON.error||x.status)); });
}

/* ---------- 歷史 ---------- */
function openHis(mid){
    $.getJSON(API, {action:'history', maker_id_no:mid}, function(res){
        if (!res.ok){ alert(res.error||'載入失敗'); return; }
        $('#hisTitle').text('稽核歷史：'+res.maker.maker_id+'（'+res.maker.maker_id_no+'）');
        if (!res.list.length){ $('#hisBody').html('<div style="color:#8a6d45;padding:12px;">尚無稽核紀錄</div>'); openMask('hisMask'); return; }
        var h = '<table class="hist"><thead><tr><th>應稽核到期</th><th>稽核日</th><th>按時</th><th>結果</th><th>分數</th>'
              + '<th>稽核人員</th><th>報告編號</th><th>下次到期</th><th>登錄者</th>'+(res.can_delete?'<th></th>':'')+'</tr></thead><tbody>';
        res.list.forEach(function(a){
            var ontime = (a.due_date && a.audit_date) ? (fmtDate(a.audit_date)<=fmtDate(a.due_date)) : null;
            h += '<tr>';
            h += '<td>'+(fmtDate(a.due_date)||'—')+'</td>';
            h += '<td>'+fmtDate(a.audit_date)+'</td>';
            h += '<td>'+(ontime===null?'—':(ontime?'<span style="color:#8A5A2B;">按時</span>':'<span style="color:#DD5138;">逾期</span>'))+'</td>';
            h += '<td class="rs-'+a.result+'">'+(RESULT_LABEL[a.result]||a.result)+'</td>';
            h += '<td>'+(a.score==null?'—':a.score)+'</td>';
            h += '<td>'+esc(a.auditor||'—')+'</td>';
            h += '<td>'+esc(a.report_no||'—')+'</td>';
            h += '<td>'+(fmtDate(a.next_due)||'—')+'</td>';
            h += '<td>'+esc(a.created_by_name||'')+'</td>';
            if (res.can_delete) h += '<td><span class="va-op" style="color:#DD5138;" onclick="delAudit('+a.audit_id+')"><i class="fa fa-trash"></i></span></td>';
            h += '</tr>';
        });
        h += '</tbody></table>';
        $('#hisBody').html(h); openMask('hisMask');
    });
}
function delAudit(aid){
    if (!confirm('刪除此稽核紀錄？（將依剩餘紀錄修復下次應稽核日）')) return;
    $.post(API, {action:'delete_audit', audit_id:aid}, function(res){
        if (!res.ok){ alert(res.error||'刪除失敗'); return; }
        closeMask('hisMask'); loadList();
    }, 'json');
}

/* ---------- CSV ---------- */
$('#btnCsv').on('click', function(){
    var rows = [['廠商編號','廠商名稱','週期(月)','下次應稽核日','狀態','最近稽核日','最近結果','納入管理']];
    ROWS.forEach(function(r){
        rows.push([r.maker_id_no, r.maker_id||'', r.audit_cycle_months==null?'':r.audit_cycle_months,
            fmtDate(r.audit_next_due), STATUS_LABEL[r.status]||r.status,
            r.last?fmtDate(r.last.audit_date):'', r.last?(RESULT_LABEL[r.last.result]||r.last.result):'',
            r.audit_managed===1?'是':'否']);
    });
    var csv = '﻿' + rows.map(function(l){
        return l.map(function(v){ return '"'+String(v==null?'':v).replace(/"/g,'""')+'"'; }).join(',');
    }).join('\r\n');
    var a = document.createElement('a');
    a.href = URL.createObjectURL(new Blob([csv], {type:'text/csv;charset=utf-8;'}));
    a.download = '供應商稽核_'+$('#yearSel').val()+'_H'+$('#halfSel').val()+'.csv';
    a.click();
});

$('#btnRoleHelp').on('click', function(){ openMask('helpMask'); });
$('.va-mask').on('click', function(e){ if (e.target===this) this.style.display='none'; });
$(document).on('focus', '.va-modal input[type=text], .va-modal input[type=number]', function(){ this.select(); });
$(document).on('dblclick', '.va-modal input[type=text], .va-modal input[type=number]', function(){ this.value=''; });

if (canView) loadMeta(function(){ loadList(); });
</script>
</body>
</html>
