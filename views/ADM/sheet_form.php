<?php
/**
 * 表格模板填寫 — 2026-10-14 新增
 * 依「表格模板管理」設計好的模板建立單據、填寫待填欄位、列印。模板設計見 sheet_form_template.php。
 * 資料走 src/store/SheetForm_API.php；權限／驗證在 src/common/sheet_form_lib.php。
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
    <link rel="stylesheet" href="../../resource/css/eg_univer.css?v=<?= $_v('resource/css/eg_univer.css') ?>">
    <style>
        #sidebar-menu { visibility: hidden; }
        .right_col .page-title { margin:8px 0 4px; overflow:hidden; clear:both; }
        .sf-toolbar { display:flex; flex-wrap:wrap; gap:6px; align-items:center; clear:both; border:1.5px solid #E8D5B5; border-radius:8px;
            padding:8px 10px; margin-bottom:10px; background:#FDF8EF; }
        .sf-toolbar button, .sf-toolbar a.sf-btn { height:30px; line-height:28px; font-size:13px; padding:0 12px; border:1px solid #D8BE93; border-radius:4px; background:#fff; color:#5b3a1e; cursor:pointer; text-decoration:none; }
        .sf-toolbar select, .sf-toolbar input[type=text], .sf-toolbar input[type=date] { height:30px; border:1px solid #D8BE93; border-radius:4px; padding:0 6px; font-size:13px; }
        .sf-toolbar .btn-warm { background:#F0A24B; color:#fff; border-color:#d98a33; }
        .page-help-btn { margin-left:auto; height:30px; font-size:13px; padding:0 14px; border:1px solid #D8BE93; border-radius:4px; background:#fff; color:#5b3a1e; cursor:pointer; }
        .help-doc h4 { font-size:14px; color:#8A5A2B; margin:10px 0 4px; }
        table.sf-tbl { width:100%; border-collapse:collapse; font-size:13px; background:#fff; }
        table.sf-tbl th, table.sf-tbl td { border:1px solid #EADFC8; padding:6px 8px; }
        table.sf-tbl thead th { background:#F7E0BD; color:#5b3a1e; }
        .sf-wrap { overflow-x:auto; border:1px solid #E8D5B5; border-radius:6px; }
        .st-badge { display:inline-block; padding:1px 6px; border-radius:3px; font-size:11px; line-height:16px; border:1px solid transparent; }
        .st-draft { background:#F7E0BD; color:#7a5217; border-color:#E0BE86; } .st-done { background:#EFE7DA; color:#5b3a1e; border-color:#D8C6A8; }
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
        #fz { display:none; position:fixed; inset:0; z-index:10050; background:#fff; flex-direction:column; }
        #fz.on { display:flex; }
        .fz-top { display:flex; flex-wrap:wrap; gap:8px; align-items:center; padding:6px 10px; background:#FDF8EF; border-bottom:1.5px solid #E8D5B5; font-size:13px; color:#5b3a1e; }
        .fz-top input[type=text], .fz-top input[type=date] { height:28px; border:1px solid #D8BE93; border-radius:4px; padding:0 6px; font-size:13px; }
        .fz-top button { height:28px; font-size:13px; padding:0 12px; border:1px solid #D8BE93; border-radius:4px; background:#fff; color:#5b3a1e; cursor:pointer; }
        .fz-top .btn-warm { background:#F0A24B; color:#fff; border-color:#d98a33; }
        #fzSheet { flex:1; min-height:0; position:relative; }
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
            <h2 style="margin:6px 0;">表格模板填寫 <small style="color:#8a6d45;">依模板建立單據、填寫、列印</small></h2>
            <button class="page-help-btn" id="btnPageHelp"><i class="fa fa-question-circle"></i> 使用說明</button>
        </div>
        <div class="clearfix"></div>
        <div class="sf-toolbar">
            <button class="btn-warm" id="btnNew"><i class="fa fa-plus"></i> 新增單據</button>
            <select id="fStatus"><option value="">全部狀態</option><option value="draft">草稿</option><option value="done">已完成</option></select>
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
            <thead><tr><th>單據標題</th><th>模板</th><th style="width:100px;">業務日期</th><th>建立人</th><th style="width:70px;">狀態</th><th style="width:150px;">最後修改</th><th style="width:200px;">操作</th></tr></thead>
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

<!-- 填寫畫面 -->
<div id="fz">
    <div class="fz-top">
        <b>標題</b><input type="text" id="fzTitle" maxlength="150" style="width:240px">
        <b>業務日期</b><input type="date" id="fzDate">
        <span id="fzTpl" style="color:#8a6d45"></span>
        <span id="fzState"></span>
        <span style="margin-left:auto;"></span>
        <button id="btnAddRow" style="display:none;"><i class="fa fa-plus"></i> 加一列</button>
        <button id="btnDelRow" style="display:none;"><i class="fa fa-minus"></i> 刪最後一列</button>
        <button id="btnFzSave" class="btn-warm"><i class="fa fa-save"></i> 儲存草稿</button>
        <button id="btnFzDone"><i class="fa fa-check"></i> 完成送出</button>
        <button id="btnFzPrint"><i class="fa fa-print"></i> 列印</button>
        <button id="btnFzClose">關閉</button>
    </div>
    <div id="fzSheet"></div>
</div>

<div class="sf-mask" id="helpUseMask"><div class="sf-modal wide">
    <div class="m-head"><span>使用說明 — 表格模板填寫</span><span class="m-close" onclick="closeMask('helpUseMask')">✕</span></div>
    <div class="m-body help-doc" style="font-size:13px;color:#5b3a1e;line-height:1.8;">
        <h4>功能說明</h4>
        依管理員在「表格模板管理」設計好的模板，建立單據並填寫。畫面像 Excel，但只有<b>淡黃色底</b>的格子可以填，其餘是固定內容或自動計算的公式。
        <h4>操作步驟</h4>
        <b>①新增單據</b>：按「新增單據」→ 選模板、（可選）寫標題、確認業務日期 → 開始填寫。<br>
        <b>②填寫</b>：點淡黃色格子直接輸入；下拉格會跳選單、日期格會跳日曆、勾選格直接點。有「加一列」鈕的模板可增加明細列，合計會自動更新。<br>
        <b>③儲存草稿／完成送出</b>：草稿可以隨時存、日後再改；「完成送出」會檢查必填與格式，通過後單據鎖定、不能再改（要改請洽管理員）。<br>
        <b>④列印</b>：按「列印」開出 A4／A3 列印畫面（依模板設定的紙張與方向），表頭是公司名稱與表單名稱，右下角是 AS 文件編號（版次依業務日期回推），並會記錄列印紀錄。<br>
        <b>⑤刪除</b>：草稿可由建立人刪除；已完成的單據只有管理員能刪。
        <h4>重要行為</h4>
        ・單據建立當下會把模板完整複製一份，之後管理員再改模板，不影響你已建立的單據。<br>
        ・只能看到自己建立的單據；有「檢視全部」角色者才看得到別人的。<br>
        ・第一版尚未提供送簽流程。
        <h4>權限角色</h4>
        所有登入者可建立與填寫自己的單據；檢視全部單據需「檢視全部人員建立的表單」角色；模板設計需「模板管理」角色。
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
<script src="../../resource/js/eg_univer.js?v=<?= $_v('resource/js/eg_univer.js') ?>"></script>
<script src="../../resource/js/eg_sheet_form.js?v=<?= $_v('resource/js/eg_sheet_form.js') ?>"></script>
<script>
var API = '../../src/store/SheetForm_API.php';
var META = null, PAGE = 1, TOTAL = 0, FZ = null /* {id, ctl, inst, dirty} */;
function toast(m){ var $t=$('#sfToast'); $t.text(m).stop(true,true).fadeIn(120); setTimeout(function(){ $t.fadeOut(300); }, 3000); }
function openMask(id){ $('#'+id).show(); } function closeMask(id){ $('#'+id).hide(); }
function esc(s){ return String(s==null?'':s).replace(/[&<>"]/g,function(m){return {'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;'}[m];}); }
function dispDate(s){ return s ? egFmtDate(s) : ''; }
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
        loadList();
    });
});
function loadList(){
    $.getJSON(API, {action:'instance_list', page:PAGE, per:$('#fPer').val(), status:$('#fStatus').val(), template_id:$('#fTpl').val(), kw:$('#fKw').val()}, function(res){
        if(!res.ok){ alert(res.error); return; }
        TOTAL = res.total; var h='';
        (res.rows||[]).forEach(function(r){
            h += '<tr><td>'+esc(r.title||'（未命名）')+'</td><td>'+esc(r.template_name)+'</td><td>'+dispDate(r.biz_date)+'</td><td>'+esc(r.creator_name||'')+'</td>'
              + '<td><span class="st-badge '+(r.status==='done'?'st-done':'st-draft')+'">'+(r.status==='done'?'已完成':'草稿')+'</span></td><td>'+esc((r.modified_at||'').substring(0,16))+'</td>'
              + '<td><span class="lnk" onclick="openInst('+r.id+')"><i class="fa fa-'+(r.can_edit?'pencil':'eye')+'"></i> '+(r.can_edit?'填寫':'檢視')+'</span>'
              + (r.can_edit || META.perms.canAdmin ? '<span class="lnk del" onclick="delInst('+r.id+')">刪除</span>' : '') + '</td></tr>';
        });
        $('#listBody').html(h || '<tr><td colspan="7" style="text-align:center;color:#8a6d45;">沒有符合條件的單據</td></tr>');
        var per = +res.per, pages = Math.max(1, Math.ceil(res.total/per));
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
    post({action:'instance_delete', id:id}, function(){ loadList(); });
}

/* ---------------- 填寫 ---------------- */
function openInst(id){
    $.getJSON(API, {action:'instance_get', id:id}, function(res){
        if(!res.ok){ alert(res.error); return; }
        var inst = res.instance;
        if (FZ && FZ.ctl) FZ.ctl.dispose();
        $('#fzSheet').empty().append('<div id="fzU" style="position:absolute;inset:0;"></div>');
        $('#fz').addClass('on');
        FZ = { id:inst.id, inst:inst, dirty:false };
        $('#fzTitle').val(inst.title).prop('disabled', !inst.can_edit); $('#fzDate').val(inst.biz_date).prop('disabled', !inst.can_edit);
        $('#fzTpl').text('模板：'+inst.template_name);
        $('#fzState').html(inst.status==='done' ? '<span class="st-badge st-done">已完成（唯讀）</span>' : (inst.can_edit ? '<span class="st-badge st-draft">草稿</span>' : '<span class="st-badge st-done">唯讀</span>'));
        FZ.ctl = EGSheetForm.mount({ el:'fzU', mode: inst.can_edit ? 'fill' : 'view', book:inst.book, fields:inst.fields, values:inst.values, extraRows:inst.extra_rows, people:META.people, toast:toast });
        $('.fz-top button').prop('disabled', true);
        FZ.ctl.ready.then(function(){ $('.fz-top button').prop('disabled', false); });
        var det = FZ.ctl.hasDetail() && inst.can_edit;
        $('#btnAddRow,#btnDelRow').toggle(det);
        $('#btnFzSave,#btnFzDone').toggle(!!inst.can_edit);
    });
}
function collect(submit){
    return { action:'instance_save', id:FZ.id, title:$('#fzTitle').val(), biz_date:$('#fzDate').val(), extra_rows:FZ.ctl.getExtra(), values_json:JSON.stringify(FZ.ctl.getValues()), submit:submit?1:0 };
}
function doSave(submit){
    post(collect(submit), function(res){
        FZ.dirty=false; toast(submit?'已完成送出':'已儲存草稿'); loadList();
        if (submit){ closeFill(true); }
    }, function(res){ alert((res.error||'儲存失敗') + ((res.errors&&res.errors.length>1)?'\n\n'+res.errors.slice(0,8).map(function(e){return '・'+e[2];}).join('\n'):'')); });
}
$('#btnFzSave').on('click', function(){ doSave(false); });
$('#btnFzDone').on('click', function(){ if (confirm('完成送出後單據會鎖定、不能再修改，確定嗎？')) doSave(true); });
$('#btnAddRow').on('click', function(){ FZ.ctl.addDetailRow().then(function(){ toast('已加一列（共 '+(FZ.ctl.getExtra()+1)+' 列）'); }); });
$('#btnDelRow').on('click', function(){ if (FZ.ctl.getExtra()<=0){ toast('只剩一列了'); return; } if (confirm('刪除最後一列（含已填內容）？')) FZ.ctl.removeLastDetailRow().then(function(){ toast('已刪除最後一列'); }); });
function printInst(){
    var i = FZ.inst;
    EGSheetForm.printWindow(FZ.ctl, { title:i.print.title, company:i.print.company, doc_no:i.print.doc_no, paper_size:i.paper_size, orientation:i.orientation });
    post({action:'print_log', id:FZ.id}, function(){});
}
$('#btnFzPrint').on('click', printInst);
function closeFill(force){
    if (!force && FZ && FZ.inst.can_edit && !confirm('關閉前請確認已按「儲存草稿」，未儲存的內容會遺失。確定關閉？')) return;
    if (FZ && FZ.ctl) FZ.ctl.dispose();
    FZ = null; $('#fz').removeClass('on'); $('#fzSheet').empty();
}
$('#btnFzClose').on('click', function(){ closeFill(false); });
</script>
</body>
</html>
