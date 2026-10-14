<?php
/**
 * 表格模板填寫 — 2026-10-14 新增
 * 依「表格模板管理」設計好的模板建立單據、填寫待填欄位、送簽、列印。模板設計見 sheet_form_template.php。
 * 資料走 src/store/SheetForm_API.php；權限／驗證／簽核在 src/common/sheet_form_lib.php、sheet_form_flow_lib.php。
 * 填寫畫面是網頁式表單（resource/js/eg_sheet_form_ui.js）；Univer 試算表引擎藏在畫面外只負責公式計算。
 */
session_start();
if (!isset($_SESSION['userName'])) {
    $_SESSION['lastpage'] = "../../views/ADM/sheet_form.php";
    header("Location:../../index.php");
    exit;
}
include_once '../../src/common/_config.php';
include_once '../../src/common/DBConnection.php';
include_once '../../src/common/sheet_form_lib.php';

$db = (new DBConnection())->getPDO();
sf_ensure_schema($db);
$sfUser = sf_current_user($db);
$perms = sf_perms($db, $sfUser);
$_v = function ($rel) { return @filemtime(__DIR__ . '/../../' . $rel); };
?>
<!DOCTYPE html>
<html lang="zh-Hant">
<head>
    <meta charset="utf-8">
    <meta http-equiv="X-UA-Compatible" content="IE=edge">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>表格模板填寫</title>
    <link href="../../resource/css/bootstrap.css" rel="stylesheet">
    <link href="../../resource/css/font-awesome.css" rel="stylesheet">
    <link href="../../resource/css/nprogress.css" rel="stylesheet">
    <link href="../../resource/css/custom.css" rel="stylesheet">
    <style>
        #sidebar-menu { visibility: hidden; }
        .right_col .page-title { margin:8px 0 4px; overflow:hidden; clear:both; }
        .sf-toolbar { display:flex; flex-wrap:wrap; gap:6px; align-items:center; clear:both; border:1.5px solid #E8D5B5; border-radius:8px;
            padding:8px 10px; margin-bottom:10px; background:#FDF8EF; }
        .sf-toolbar button, .sf-toolbar a.sf-btn { height:30px; line-height:28px; font-size:13px; padding:0 12px; border:1px solid #D8BE93; border-radius:4px; background:#fff; color:#5b3a1e; cursor:pointer; text-decoration:none; }
        .sf-toolbar select, .sf-toolbar input[type=text], .sf-toolbar input[type=date] { height:30px; border:1px solid #D8BE93; border-radius:4px; padding:0 6px; font-size:13px; }
        .sf-toolbar .btn-warm { background:#F0A24B; color:#fff; border-color:#d98a33; }
        .sf-toolbar .tab-on { background:#F7E0BD; font-weight:bold; }
        .sf-toolbar .badge-n { background:#DD5138; color:#fff; border-radius:9px; font-size:11px; line-height:16px; padding:0 6px; margin-left:4px; display:none; }
        .page-help-btn { margin-left:auto; height:30px; font-size:13px; padding:0 14px; border:1px solid #D8BE93; border-radius:4px; background:#fff; color:#5b3a1e; cursor:pointer; }
        .help-doc h4 { font-size:14px; color:#8A5A2B; margin:10px 0 4px; }
        table.sf-tbl { width:100%; border-collapse:collapse; font-size:13px; background:#fff; }
        table.sf-tbl th, table.sf-tbl td { border:1px solid #EADFC8; padding:6px 8px; }
        table.sf-tbl thead th { background:#F7E0BD; color:#5b3a1e; }
        .sf-wrap { overflow-x:auto; border:1px solid #E8D5B5; border-radius:6px; }
        .st-badge { display:inline-block; padding:1px 6px; border-radius:3px; font-size:11px; line-height:16px; border:1px solid transparent; }
        .st-draft { background:#F7E0BD; color:#7a5217; border-color:#E0BE86; } .st-done { background:#EFE7DA; color:#5b3a1e; border-color:#D8C6A8; }
        .st-pending { background:#FFF3D1; color:#8a5a00; border-color:#F0A24B; } .st-rejected { background:#FFF4F1; color:#DD5138; border-color:#DD5138; }
        .lnk { color:#b5762a; cursor:pointer; margin-right:8px; } .lnk.del { color:#DD5138; }
        .pager { display:flex; gap:8px; align-items:center; justify-content:flex-end; margin:6px 0; font-size:13px; color:#5b3a1e; }
        .pager button { height:26px; border:1px solid #D8BE93; border-radius:4px; background:#fff; padding:0 10px; cursor:pointer; }
        .pager button:disabled { opacity:.45; cursor:default; }
        .sf-mask { display:none; position:fixed; inset:0; background:rgba(60,40,20,.45); z-index:1050; }
        .sf-modal { background:#fff; border-radius:8px; max-width:560px; margin:60px auto; box-shadow:0 5px 25px rgba(0,0,0,.3); max-height:90vh; display:flex; flex-direction:column; }
        .sf-modal.wide { max-width:860px; margin:30px auto; }
        .sf-modal .m-head { background:#F7E0BD; color:#5b3a1e; font-weight:bold; padding:10px 15px; border-radius:8px 8px 0 0; display:flex; justify-content:space-between; }
        .sf-modal .m-head .m-close { cursor:pointer; color:#b5762a; }
        .sf-modal .m-body { padding:15px; overflow-y:auto; }
        .sf-modal .m-body label { display:block; font-size:13px; color:#5b3a1e; margin:9px 0 3px; }
        .sf-modal .m-body input, .sf-modal .m-body select { width:100%; border:1px solid #D8BE93; border-radius:4px; padding:5px 8px; font-size:13px; box-sizing:border-box; }
        .sf-modal .m-foot { padding:10px 15px; border-top:1px solid #EADFC8; text-align:right; }
        .sf-modal .m-foot button { height:30px; padding:0 16px; border-radius:4px; font-size:13px; border:1px solid #d98a33; cursor:pointer; background:#F0A24B; color:#fff; }
        .sf-modal .m-foot button.b-cancel { background:#fff; color:#5b3a1e; border-color:#D8BE93; margin-right:6px; }
        #helpUseMask, #newMask { z-index:10040; }
        .sf-toast { position:fixed; left:50%; bottom:30px; transform:translateX(-50%); background:#5b3a1e; color:#fff; padding:8px 18px; border-radius:6px; z-index:20000; display:none; font-size:13px; max-width:80vw; }
        @media print { .page-help-btn { display:none; } }
    </style>
</head>
<body class="nav-sm">
<div class="container body">
<div class="main_container">
    <?php include '../partPage/sideAndTopBarMenu.html' ?>
    <div class="right_col" role="main">
        <div class="page-title" style="display:flex;align-items:center;flex-wrap:wrap;clear:both;">
            <h2 style="margin:6px 0;">表格模板填寫 <small style="color:#8a6d45;">依模板建立單據、填寫、送簽、列印</small></h2>
            <button class="page-help-btn" id="btnPageHelp"><i class="fa fa-question-circle"></i> 使用說明</button>
        </div>
        <div class="clearfix"></div>
        <div class="sf-toolbar">
            <button class="btn-warm" id="btnNew"><i class="fa fa-plus"></i> 新增單據</button>
            <button id="tabAll" class="tab-on">全部單據</button>
            <button id="tabTodo">待我簽核<span class="badge-n" id="todoN">0</span></button>
            <select id="fStatus"><option value="">全部狀態</option><option value="draft">草稿</option><option value="pending">簽核中</option><option value="rejected">已退回</option><option value="done">已完成</option></select>
            <select id="fTpl" data-eg-filter="輸入模板名稱篩選…"><option value="">全部模板</option></select>
            <input type="text" id="fKw" placeholder="關鍵字（標題／模板／建立人）" style="width:220px">
            <select id="fPer"><option>5</option><option>10</option><option selected>20</option><option>50</option></select>
<?php if ($perms['canAdmin']): ?>
            <a class="sf-btn" href="sheet_form_template.php" style="margin-left:auto;">前往「模板管理」→</a>
<?php endif; ?>
        </div>
        <div class="pager" id="pagerTop"></div>
        <div class="sf-wrap">
        <table class="sf-tbl">
            <thead><tr><th>單據標題</th><th>模板</th><th style="width:100px;">業務日期</th><th>建立人</th><th style="width:120px;">狀態</th><th style="width:150px;">最後修改</th><th style="width:150px;">操作</th></tr></thead>
            <tbody id="listBody"><tr><td colspan="7" style="text-align:center;color:#8a6d45;">載入中…</td></tr></tbody>
        </table>
        </div>
    </div>
</div>
</div>

<!-- 新增單據 -->
<div class="sf-mask" id="newMask"><div class="sf-modal">
    <div class="m-head"><span>新增單據</span><span class="m-close" onclick="closeMask('newMask')">✕</span></div>
    <div class="m-body">
        <label>使用模板</label><select id="nTpl" data-eg-filter="輸入模板名稱篩選…"></select>
        <label>單據標題（可留空，方便日後查找，例：10月第2週外觀檢查）</label><input type="text" id="nTitle" maxlength="150">
        <label>業務日期（表單上的日期；列印的 AS 版次依它回推）</label><input type="date" id="nDate">
        <div id="nErr" style="color:#DD5138;font-size:12px;margin-top:6px;"></div>
    </div>
    <div class="m-foot"><button class="b-cancel" onclick="closeMask('newMask')">取消</button><button id="btnNewOk">建立並開始填寫</button></div>
</div></div>

<?php include __DIR__ . '/_sheet_form_modal.php'; ?>

<div class="sf-mask" id="helpUseMask"><div class="sf-modal wide">
    <div class="m-head"><span>使用說明 — 表格模板填寫</span><span class="m-close" onclick="closeMask('helpUseMask')">✕</span></div>
    <div class="m-body help-doc" style="font-size:13px;color:#5b3a1e;line-height:1.8;">
        <h4>功能說明</h4>
        依管理員在「表格模板管理」設計好的模板，建立單據、填寫、送簽、列印。填寫畫面是一般的網頁表單（Excel 只用來設計模板）：基本資料、明細卡片、自動計算的欄位一目了然，不用懂 Excel。
        <h4>操作步驟</h4>
        <b>①新增單據</b>：按「新增單據」→ 選模板、（可選）寫標題、確認業務日期 → 開始填寫。模板若有預設的標準項目，會自動帶入明細。<br>
        <b>②填寫</b>：直接在欄位輸入；下拉欄選項、日期欄點日曆、勾選欄直接點；<b>料號、客戶欄輸入關鍵字會從主檔搜尋，請從清單挑選</b>。必填欄位標紅色星號，輸入錯誤當場提示原因。明細可按「新增一筆」，每筆右上角「✕ 刪除」可刪除該筆，合計等計算欄位自動更新。<br>
        <b>③儲存草稿／送出</b>：草稿可隨時存、日後再改。模板有簽核流程時按「送出簽核」，依序通知各關簽核人；沒有簽核流程則「完成送出」後直接鎖定。<br>
        <b>④簽核</b>：被指定的簽核人會收到通知，也會出現在「待我簽核」。開啟單據可看完整內容與簽核進度，核准或退回（<b>退回必須填原因</b>）。被退回的單據建立人可修改後重新送出。簽核人請假且設有代理人時，代理人也能簽（圖章右下角加「代」字）。<br>
        <b>⑤列印</b>：依模板設定的紙張與方向，表頭是公司名稱與表單名稱，底部是填表人與各關簽核人的圖章，右下角是 AS 文件編號（版次依業務日期回推），並記錄列印紀錄。<br>
        <b>⑥刪除</b>：草稿與已退回的單據可由建立人刪除；其他狀態只有管理員能刪。
        <h4>重要行為</h4>
        ・單據建立當下會把模板（版面、欄位、簽核流程）完整複製一份，之後管理員再改模板，不影響已建立的單據。<br>
        ・簽核中與已完成的單據不能修改。送出者本人不能簽自己送出的單據。<br>
        ・只能看到自己建立的單據；被指定的簽核人與曾簽過的人也看得到；有「檢視全部」角色者看得到所有人的。
        <h4>權限角色</h4>
        所有登入者可建立與填寫自己的單據；檢視全部單據需「檢視全部人員建立的表單」角色；模板設計與簽核流程設定需「模板管理」角色；被模板指定為維護人員者可修改該模板的標準項目。
    </div>
    <div class="m-foot"><button onclick="closeMask('helpUseMask')">關閉</button></div>
</div></div>
<div class="sf-toast" id="sfToast"></div>

<script src="../../resource/js/jquery.min.js"></script>
<script src="../../resource/js/bootstrap.min.js"></script>
<script src="../../resource/js/nprogress.js"></script>
<script src="../../resource/js/custom.min.js"></script>
<script src="../../resource/js/eg_input_rules.js?v=<?= $_v('resource/js/eg_input_rules.js') ?>"></script>
<script src="../../resource/js/eg_date_fmt.js?v=<?= $_v('resource/js/eg_date_fmt.js') ?>"></script>
<script src="../../resource/js/eg_stamp.js?v=<?= $_v('resource/js/eg_stamp.js') ?>"></script>
<script src="../../resource/js/eg_stamp_tpl.js?v=<?= $_v('resource/js/eg_stamp_tpl.js') ?>"></script>
<script src="../../resource/js/eg_sheet_form.js?v=<?= $_v('resource/js/eg_sheet_form.js') ?>"></script>
<script src="../../resource/js/eg_sheet_form_ui.js?v=<?= $_v('resource/js/eg_sheet_form_ui.js') ?>"></script>
<script>
var API = '../../src/store/SheetForm_API.php';
var META = null, PAGE = 1, TODO = false, FM = null;
var ENGINE_VER = '<?= $_v('resource/js/eg_univer.js') ?>', ENGINE_P = null;
var STATUS_LABEL = {draft:'草稿', pending:'簽核中', done:'已完成', rejected:'已退回'};
function toast(m){ var $t=$('#sfToast'); $t.text(m).stop(true,true).fadeIn(120); setTimeout(function(){ $t.fadeOut(300); }, 3000); }
function openMask(id){ $('#'+id).show(); } function closeMask(id){ $('#'+id).hide(); }
function esc(s){ return String(s==null?'':s).replace(/[&<>"]/g,function(m){return {'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;'}[m];}); }
function dispDate(s){ return s ? egFmtDate(String(s).substring(0,10)) : ''; }
/** 試算表引擎（約 12MB）只在第一次開單據時才載入；清單頁完全不碰它。列表載好後會在背景預先載入。 */
function loadEngine(){
    if (window.EGUniver) return Promise.resolve();
    if (ENGINE_P) return ENGINE_P;
    ENGINE_P = new Promise(function(res, rej){
        var l = document.createElement('link'); l.rel = 'stylesheet'; l.href = '../../resource/css/eg_univer.css?v=<?= $_v('resource/css/eg_univer.css') ?>'; document.head.appendChild(l);
        var sc = document.createElement('script'); sc.src = '../../resource/js/eg_univer.js?v=' + ENGINE_VER;
        sc.onload = function(){ res(); }; sc.onerror = function(){ ENGINE_P = null; rej(new Error('表單元件載入失敗，請重新整理後再試')); };
        document.head.appendChild(sc);
    });
    return ENGINE_P;
}
$(document).ready(function(){
    var $am = $('#sidebar-menu .nav.side-menu > li.active');
    if ($am.length) { $am.removeClass('active').find('ul.child_menu').hide(); $am.find('li.current-page').removeClass('current-page'); }
    $('#sidebar-menu').css('visibility','visible');
});
$('#btnPageHelp').on('click', function(){ openMask('helpUseMask'); });
function post(data, cb, errcb){
    data.csrf = META.csrf;
    $.ajax({url:API, type:'POST', data:data, dataType:'json', success:function(res){ if(!res.ok){ if(errcb) errcb(res); else alert(res.error||'失敗'); return; } cb&&cb(res); },
        error:function(x){ var r={}; try{ r=JSON.parse(x.responseText); }catch(e){} if(errcb) errcb(r); else alert(r.error||'失敗'); }});
}
$.getJSON(API, {action:'meta'}, function(res){
    if(!res.ok){ alert(res.error||'載入失敗'); return; } META = res;
    $.getJSON(API, {action:'template_list'}, function(r){
        var h='', h2='<option value="">全部模板</option>';
        (r.templates||[]).forEach(function(t){ h2 += '<option value="'+t.id+'">'+esc(t.name)+(t.status==='archived'?'（已停用）':'')+'</option>'; if (t.status==='active') h += '<option value="'+t.id+'">'+esc(t.name)+'</option>'; });
        $('#nTpl').html(h || '<option value="">（尚無可用模板）</option>'); $('#fTpl').html(h2);
        var qs = new URLSearchParams(location.search);
        if (qs.get('todo')) setTab(true);
        loadList(); refreshTodo();
        var deep = qs.get('decide') || qs.get('inst');
        if (deep) openInst(+deep);
    });
});
function refreshTodo(){ $.getJSON(API, {action:'todo_count'}, function(r){ if (r.ok) $('#todoN').text(r.n).toggle(r.n>0); }); }
function setTab(todo){ TODO = !!todo; $('#tabAll').toggleClass('tab-on', !todo); $('#tabTodo').toggleClass('tab-on', todo); PAGE = 1; }
$('#tabAll').on('click', function(){ setTab(false); loadList(); });
$('#tabTodo').on('click', function(){ setTab(true); loadList(); });
function loadList(){
    $.getJSON(API, {action:'instance_list', page:PAGE, per:$('#fPer').val(), status:$('#fStatus').val(), template_id:$('#fTpl').val(), kw:$('#fKw').val(), todo:TODO?1:0}, function(res){
        if(!res.ok){ alert(res.error); return; }
        var h='';
        (res.rows||[]).forEach(function(r){
            var lab = (STATUS_LABEL[r.status]||r.status) + (r.status==='pending' && r.step_label ? '・'+r.step_label : '');
            h += '<tr><td>'+esc(r.title||'（未命名）')+'</td><td>'+esc(r.template_name)+'</td><td>'+dispDate(r.biz_date)+'</td><td>'+esc(r.creator_name||'')+'</td>'
              + '<td><span class="st-badge st-'+r.status+'">'+esc(lab)+'</span></td><td>'+esc((r.modified_at||'').substring(0,16))+'</td>'
              + '<td><span class="lnk" onclick="openInst('+r.id+')"><i class="fa fa-'+(r.can_edit?'pencil':(TODO?'check-square-o':'eye'))+'"></i> '+(r.can_edit?'填寫':(TODO?'簽核':'檢視'))+'</span>'
              + ((r.can_edit || META.perms.canAdmin) ? '<span class="lnk del" onclick="delInst('+r.id+')">刪除</span>' : '') + '</td></tr>';
        });
        $('#listBody').html(h || '<tr><td colspan="7" style="text-align:center;color:#8a6d45;">沒有符合條件的單據</td></tr>');
        var per = +res.per, pages = Math.max(1, Math.ceil(res.total/per));
        setTimeout(function(){ loadEngine().catch(function(){}); }, 1200);
        $('#pagerTop').html('<span>共 '+res.total+' 筆</span><button '+(PAGE<=1?'disabled':'')+' onclick="gotoPage('+(PAGE-1)+')">上一頁</button><span>'+PAGE+' / '+pages+'</span><button '+(PAGE>=pages?'disabled':'')+' onclick="gotoPage('+(PAGE+1)+')">下一頁</button>');
    });
}
function gotoPage(p){ PAGE = p; loadList(); }
$('#fStatus,#fTpl,#fPer').on('change', function(){ PAGE=1; loadList(); });
var kwT; $('#fKw').on('input', function(){ clearTimeout(kwT); kwT=setTimeout(function(){ PAGE=1; loadList(); }, 350); });

$('#btnNew').on('click', function(){
    if (!$('#nTpl').val()){ alert('目前沒有可用的模板，請先請管理員到「表格模板管理」建立。'); return; }
    var d = new Date(); $('#nDate').val(d.getFullYear()+'-'+('0'+(d.getMonth()+1)).slice(-2)+'-'+('0'+d.getDate()).slice(-2)); $('#nTitle').val(''); $('#nErr').text(''); openMask('newMask');
});
$('#btnNewOk').on('click', function(){
    if (!$('#nDate').val()){ $('#nErr').text('請選擇業務日期'); return; }
    post({action:'instance_create', template_id:$('#nTpl').val(), title:$('#nTitle').val(), biz_date:$('#nDate').val()}, function(res){ closeMask('newMask'); loadList(); openInst(res.id); });
});
function delInst(id){
    if (!confirm('確定刪除這張單據？')) return;
    post({action:'instance_delete', id:id}, function(){ loadList(); refreshTodo(); });
}

/* ---------------- 填寫／簽核（網頁式表單） ---------------- */
function stampHtml(name, date, deputy, dept, pos){
    try { if (window.EGStamp && EGStamp.stamp) return EGStamp.stamp(name, date ? egFmtDate(String(date).substring(0,10)) : '', !!deputy, FM && FM.inst.stamp_tpl, dept || '', pos || ''); } catch(e){}
    return esc(name || '');
}
function renderFlow(inst){
    var steps = inst.flow_steps || [], h = '';
    if (!steps.length){ $('#fmFlow').html(''); return; }
    h += '<div class="fm-flow"><div style="font-weight:bold;color:#8A5A2B;margin-bottom:6px;">簽核進度</div><div class="fm-steps">';
    h += '<div class="fm-step s-done"><b>填表</b><div>'+esc(inst.creator_name||'')+'</div></div>';
    steps.forEach(function(s){
        var cls = s.state==='done'?'s-done':(s.state==='cur'?'s-cur':(s.state==='rej'?'s-rej':'')), t = '';
        if (s.state==='done') t = esc(s.by)+(s.deleg?'（代 '+esc(s.deleg)+'）':'')+'<div class="st-note">'+esc((s.at||'').substring(0,16))+'</div>';
        else if (s.state==='rej') t = esc(s.by)+' 退回<div class="st-note">'+esc((s.at||'').substring(0,16))+'\n原因：'+esc(s.note)+'</div>';
        else if (s.state==='cur') t = '待簽核中<div class="st-note">'+esc(s.waiting_for||'')+'</div>';
        else t = (inst.status==='pending' && s.waiting_for) ? '等待前關完成' : '尚未送出';
        h += '<div class="fm-step '+cls+'"><b>'+esc(s.label)+'</b><div>'+t+'</div></div>';
    });
    $('#fmFlow').html(h+'</div></div>');
}
function openInst(id){
    $.getJSON(API, {action:'instance_get', id:id}, function(res){
        if(!res.ok){ alert(res.error); return; }
        var inst = res.instance;
        closeFill(true, true);
        $('#fmEngine').empty().append('<div id="fmU" style="position:absolute;inset:0;"></div>');
        FM = { id:inst.id, inst:inst, canEdit:!!inst.can_edit };
        window.__ownCompany = (inst.print && inst.print.company) || '';
        $('#fmTitle').text(inst.template_name);
        $('#fmName').val(inst.title).prop('disabled', !inst.can_edit);
        $('#fmDate').val(inst.biz_date).prop('disabled', !inst.can_edit);
        $('#fmWho').text(inst.template_name+'／'+(inst.creator_name||''));
        var sl = (STATUS_LABEL[inst.status]||inst.status) + (inst.can_edit?'':'（唯讀）');
        $('#fmState').html('<span class="st-badge st-'+inst.status+'">'+esc(sl)+'</span>');
        renderFlow(inst);
        // 簽核列：輪到我簽時顯示核准／退回（退回必填原因）
        if (inst.can_decide){
            $('#fmDecide').html('<div class="fm-decide"><b>請審閱下方表單內容後處理</b><textarea id="fmNote" rows="2" placeholder="意見（核准可不填；退回必須填寫原因）"></textarea>'
              + '<div style="margin-top:8px;display:flex;gap:8px;"><button type="button" class="b-ok" id="btnFmApprove" style="height:32px;padding:0 18px;border-radius:4px;border:1px solid #d98a33;background:#F0A24B;color:#fff;cursor:pointer;">核准</button>'
              + '<button type="button" id="btnFmReject" style="height:32px;padding:0 18px;border-radius:4px;border:1px solid #c23f28;background:#DD5138;color:#fff;cursor:pointer;">退回</button></div><div class="fm-errsum" id="fmDecErr"></div></div>').show();
        } else $('#fmDecide').hide().html('');
        $('#fmBase,#fmDetail,#fmCalc').html(''); $('#fmErr').text('');
        $('#btnFmSave').toggle(!!inst.can_edit);
        $('#btnFmDone').toggle(!!inst.can_edit).html(inst.has_flow ? '<i class="fa fa-paper-plane"></i> 送出簽核' : '<i class="fa fa-check"></i> 完成送出');
        $('#fmMask').addClass('on'); $('#fmMask .fm-foot button').prop('disabled', true);
        $('#fmBase').html('<div style="padding:20px;color:#8a6d45">載入中…</div>');
        var cur = FM;
        loadEngine().then(function(){
            if (FM!==cur) return;
            cur.ctl = EGSheetForm.mount({ el:'fmU', mode:'fill', book:inst.book, fields:inst.fields, values:inst.values, extraRows:inst.extra_rows, people:META.people, toast:toast });
            return cur.ctl.ready;
        }).then(function(){
            if (FM!==cur) return;
            cur.ui = EGSheetFormUI.create({ root:'#fmMask', ctl:cur.ctl, fields:inst.fields, canEdit:!!inst.can_edit, people:META.people, lookupUrl:API });
            cur.ui.build();
            $('#fmMask .fm-foot button').prop('disabled', false);
        }).catch(function(e){ $('#fmBase').html('<div style="padding:20px;color:#DD5138">'+esc(e.message||'載入失敗')+'</div>'); $('#btnFmClose').prop('disabled', false); });
    });
}
function collect(submit){
    return { action:'instance_save', id:FM.id, title:$('#fmName').val(), biz_date:$('#fmDate').val(), extra_rows:FM.ctl.getExtra(), values_json:JSON.stringify(FM.ctl.getValues()), submit:submit?1:0 };
}
function doSave(submit){
    if (!FM.ui.validateAll(submit)) return;
    post(collect(submit), function(res){
        toast(submit ? (FM.inst.has_flow ? '已送出簽核' : '已完成送出') : '已儲存草稿'); loadList(); refreshTodo();
        if (submit){ closeFill(true); }
    }, function(res){
        $('#fmErr').text(res.error||'儲存失敗');
        FM.ui.showErrors(res.errors);
    });
}
$('#btnFmSave').on('click', function(){ doSave(false); });
$('#btnFmDone').on('click', function(){
    var msg = FM.inst.has_flow ? '送出後會依序通知簽核人，簽核期間單據鎖定不能修改，確定送出嗎？' : '完成送出後單據會鎖定、不能再修改，確定嗎？';
    if (confirm(msg)) doSave(true);
});
$('#fmMask').on('click', '#btnFmApprove,#btnFmReject', function(){
    var rej = this.id==='btnFmReject', note = $.trim($('#fmNote').val());
    if (rej && !note){ $('#fmDecErr').text('退回必須填寫原因'); $('#fmNote').focus(); return; }
    if (!confirm(rej ? '確定退回這張單據？' : '確定核准？')) return;
    $('#fmDecide button').prop('disabled', true);
    post({action:'flow_decide', id:FM.id, decision:rej?'rejected':'approved', note:note}, function(res){
        toast(rej ? '已退回' : (res.status==='done' ? '已簽核完成' : '已核准，送往下一關')); closeFill(true); loadList(); refreshTodo();
    }, function(res){ $('#fmDecErr').text(res.error||'處理失敗'); $('#fmDecide button').prop('disabled', false); });
});
function printInst(){
    var i = FM.inst, signs = [];
    if (i.has_flow){
        signs.push({label:'填表人', html: stampHtml(i.creator_name, i.submit_date||i.biz_date, false, (i.creator||{}).dept, (i.creator||{}).position)});
        (i.flow_steps||[]).forEach(function(s){ signs.push({label:s.label, html: s.state==='done' ? stampHtml(s.by, s.at, !!s.deleg, s.dept, s.position) : ''}); });
    }
    EGSheetForm.printWindow(FM.ctl, { title:i.print.title, company:i.print.company, doc_no:i.print.doc_no, paper_size:i.paper_size, orientation:i.orientation, signs:signs });
    post({action:'print_log', id:FM.id}, function(){});
}
$('#btnFmPrint').on('click', printInst);
function closeFill(force, silent){
    if (!force && FM && FM.canEdit && !confirm('關閉前請確認已按「儲存草稿」，未儲存的內容會遺失。確定關閉？')) return;
    if (FM && FM.ui) FM.ui.destroy();
    if (FM && FM.ctl && FM.ctl.dispose) FM.ctl.dispose();
    FM = null; if (!silent) $('#fmMask').removeClass('on'); $('#fmEngine').empty();
}
$('#btnFmClose,#btnFmX').on('click', function(){ closeFill(false); });
</script>
</body>
</html>
