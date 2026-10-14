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
        /* 網頁式填寫跳窗（Excel 只用在設計模板；填寫一律是一般表單） */
        #fmMask { display:none; position:fixed; inset:0; background:rgba(60,40,20,.5); z-index:10050; overflow:auto; }
        #fmMask.on { display:block; }
        .fm-panel { background:#fff; width:1100px; max-width:96vw; margin:3vh auto; border-radius:8px; box-shadow:0 6px 30px rgba(0,0,0,.35); display:flex; flex-direction:column; max-height:94vh; }
        .fm-head { background:#F7E0BD; color:#5b3a1e; padding:10px 16px; border-radius:8px 8px 0 0; display:flex; align-items:center; gap:10px; }
        .fm-head .fm-title { font-weight:bold; font-size:16px; flex:1; }
        .fm-head .m-close { cursor:pointer; color:#b5762a; font-size:18px; }
        .fm-body { padding:14px 18px; overflow-y:auto; flex:1; color:#5b3a1e; font-size:13.5px; }
        .fm-foot { padding:10px 16px; border-top:1px solid #EADFC8; display:flex; gap:8px; align-items:center; background:#FDF8EF; border-radius:0 0 8px 8px; }
        .fm-foot button { height:32px; padding:0 16px; border-radius:4px; font-size:13px; border:1px solid #D8BE93; background:#fff; color:#5b3a1e; cursor:pointer; }
        .fm-foot button.b-ok { background:#F0A24B; color:#fff; border-color:#d98a33; }
        .fm-foot button:disabled { opacity:.5; cursor:default; }
        .fm-meta { display:grid; grid-template-columns:2fr 1fr 1.4fr; gap:10px; margin-bottom:12px; }
        .fm-sec { font-weight:bold; color:#8A5A2B; margin:14px 0 8px; padding-bottom:4px; border-bottom:1.5px solid #E8D5B5; }
        .fm-grid { display:grid; grid-template-columns:repeat(auto-fill,minmax(250px,1fr)); gap:10px 16px; }
        .fm-fld label { display:block; font-size:12.5px; margin-bottom:3px; color:#5b3a1e; }
        .fm-fld label .req { color:#DD5138; margin-left:2px; }
        .fm-in, .fm-meta input, .fm-meta select { width:100%; border:1px solid #D8BE93; border-radius:4px; padding:5px 8px; font-size:13px; box-sizing:border-box; background:#fff; color:#3d2a14; }
        textarea.fm-in { resize:vertical; min-height:56px; }
        .fm-in:disabled, .fm-meta input:disabled { background:#F7F2E8; color:#6b5a45; }
        .fm-in.fm-bad { border-color:#DD5138; background:#FFF4F1; }
        .fm-msg { color:#DD5138; font-size:12px; margin-top:2px; }
        .fm-chk { display:flex; align-items:center; gap:6px; height:32px; }
        table.fm-tbl { width:100%; border-collapse:collapse; table-layout:fixed; background:#fff; }
        table.fm-tbl th, table.fm-tbl td { border:1px solid #EADFC8; padding:4px 5px; vertical-align:top; }
        table.fm-tbl thead th { background:#F7E0BD; color:#5b3a1e; font-size:12.5px; text-align:center; }
        table.fm-tbl td.fm-calc { background:#FBF6EE; text-align:center; vertical-align:middle; font-weight:bold; }
        table.fm-tbl tr.fm-foot-row td { background:#FBF6EE; font-weight:bold; text-align:right; }
        .fm-del { border:none; background:none; color:#DD5138; cursor:pointer; font-size:13px; }
        .fm-addrow { margin-top:8px; height:30px; padding:0 14px; border:1px dashed #D8BE93; border-radius:4px; background:#FDF8EF; color:#8A5A2B; cursor:pointer; }
        .fm-calcbox { display:inline-block; min-width:200px; margin:0 14px 8px 0; }
        .fm-calcbox .v { border:1px solid #E8D5B5; border-radius:4px; background:#FBF6EE; padding:5px 8px; font-weight:bold; min-height:30px; box-sizing:border-box; }
        .fm-card { border:1px solid #E8D5B5; border-radius:8px; margin-bottom:10px; background:#FFFDF8; }
        .fm-card-h { display:flex; justify-content:space-between; align-items:center; padding:6px 12px; background:#FBF0DD; border-radius:8px 8px 0 0; color:#5b3a1e; }
        .fm-cardgrid { padding:10px 12px; grid-template-columns:repeat(auto-fill,minmax(190px,1fr)); }
        .fm-fld.fm-wide { grid-column:span 2; }
        .fm-cv { border:1px solid #E8D5B5; border-radius:4px; background:#FBF6EE; padding:5px 8px; font-weight:bold; min-height:30px; box-sizing:border-box; }
        .fm-errsum { color:#DD5138; font-size:12.5px; margin-top:10px; white-space:pre-line; }
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

<!-- 填寫跳窗（網頁式表單；試算表引擎藏在畫面外只負責公式計算） -->
<div id="fmMask"><div class="fm-panel">
    <div class="fm-head"><span class="fm-title" id="fmTitle"></span><span id="fmState"></span><span class="m-close" id="btnFmX">✕</span></div>
    <div class="fm-body">
        <div class="fm-meta">
            <div class="fm-fld"><label>單據標題</label><input type="text" id="fmName" maxlength="150" placeholder="可留空，方便日後查找"></div>
            <div class="fm-fld"><label>業務日期</label><input type="date" id="fmDate"></div>
            <div class="fm-fld"><label>模板／建立人</label><div id="fmWho" style="padding:6px 0;color:#8a6d45;"></div></div>
        </div>
        <div id="fmBase"></div>
        <div id="fmDetail"></div>
        <div id="fmCalc"></div>
        <div class="fm-errsum" id="fmErr"></div>
    </div>
    <div class="fm-foot">
        <button id="btnFmPrint"><i class="fa fa-print"></i> 列印</button>
        <span style="flex:1"></span>
        <button id="btnFmSave"><i class="fa fa-save"></i> 儲存草稿</button>
        <button id="btnFmDone" class="b-ok"><i class="fa fa-check"></i> 完成送出</button>
        <button id="btnFmClose">關閉</button>
    </div>
</div></div>
<div id="fmEngine" style="position:fixed;left:-10000px;top:0;width:900px;height:600px;overflow:hidden;" aria-hidden="true"></div>

<div class="sf-mask" id="helpUseMask"><div class="sf-modal wide">
    <div class="m-head"><span>使用說明 — 表格模板填寫</span><span class="m-close" onclick="closeMask('helpUseMask')">✕</span></div>
    <div class="m-body help-doc" style="font-size:13px;color:#5b3a1e;line-height:1.8;">
        <h4>功能說明</h4>
        依管理員在「表格模板管理」設計好的模板，建立單據並填寫。填寫畫面是一般的網頁表單（Excel 只用來設計模板）：基本資料、明細表格、自動計算的欄位一目了然，不用懂 Excel。
        <h4>操作步驟</h4>
        <b>①新增單據</b>：按「新增單據」→ 選模板、（可選）寫標題、確認業務日期 → 開始填寫。<br>
        <b>②填寫</b>：直接在欄位輸入；下拉欄選項、日期欄點日曆、勾選欄直接點。必填欄位標紅色星號，輸入錯誤當場提示原因。明細表可按「新增一列」，每列右側 ✕ 可刪除該列，合計等計算欄位會自動更新。<br>
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
<script src="../../resource/js/eg_sheet_form.js?v=<?= $_v('resource/js/eg_sheet_form.js') ?>"></script>
<script>
var API = '../../src/store/SheetForm_API.php';
var ENGINE_VER = '<?= $_v('resource/js/eg_univer.js') ?>', ENGINE_P = null;
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
var META = null, PAGE = 1, TOTAL = 0;
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
        setTimeout(function(){ loadEngine().catch(function(){}); }, 1200);
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

/* ---------------- 填寫（網頁式表單） ---------------- */
var FM = null;   // {id, inst, ctl, lay, canEdit, fields, byId}
function parseDate(v){ return /^(\d{4})[-.\/](\d{1,2})[-.\/](\d{1,2})$/.test(v); }
function checkValue(f, v, strict){
    if (f.t==='checkbox') return '';
    var e = (v==='' || v==null);
    if (e) return (strict && f.req) ? '必填' : '';
    if (f.t==='number' && isNaN(Number(v))) return '必須是數字';
    if (f.t==='date' && !parseDate(String(v))) return '日期格式不正確';
    if (f.t==='select' && f.opt.indexOf(String(v))<0) return '不在選項內';
    return '';
}
function inputHtml(f, idx, v){
    var dis = FM.canEdit ? '' : ' disabled', meta = FM.lay.fieldMeta[f.id]||{}, a = ' data-f="'+f.id+'" data-i="'+(idx==null?'':idx)+'"';
    if (f.t==='checkbox') return '<label class="fm-chk"><input type="checkbox"'+a+dis+(v?' checked':'')+'> 是</label>';
    if (f.t==='number') return '<input type="number" step="any" class="fm-in"'+a+dis+' value="'+esc(v)+'">';
    if (f.t==='date') return '<input type="date" class="fm-in"'+a+dis+' value="'+esc(v)+'">';
    if (f.t==='select' || f.t==='person'){
        var opts = f.t==='select' ? f.opt.slice() : META.people.slice();
        if (v && opts.indexOf(v)<0) opts.unshift(v);
        return '<select class="fm-in"'+a+dis+(f.t==='person'?' data-eg-filter="輸入姓名篩選…"':'')+'><option value="">請選擇</option>'+opts.map(function(o){ return '<option value="'+esc(o)+'"'+(o===v?' selected':'')+'>'+esc(o)+'</option>'; }).join('')+'</select>';
    }
    return meta.wrap ? '<textarea class="fm-in" rows="2"'+a+dis+' data-eg-skip>'+esc(v)+'</textarea>' : '<input type="text" class="fm-in"'+a+dis+' value="'+esc(v)+'">';
}
function buildForm(){
    FM.lay = FM.ctl.layout();
    var vals = FM.ctl.getValues(), h = '';
    var base = FM.fields.filter(function(f){ return !f.g; });
    if (base.length){
        h += '<div class="fm-sec">基本資料</div><div class="fm-grid">';
        base.forEach(function(f){
            h += '<div class="fm-fld"><label>'+esc(f.label||EGSheetForm.a1(f.r,f.c))+(f.req?'<span class="req">*</span>':'')+'</label>'+inputHtml(f,null,vals[f.id])+'<div class="fm-msg" data-m="'+f.id+'_"></div></div>';
        });
        h += '</div>';
    }
    $('#fmBase').html(h);
    buildDetail(vals);
    refreshCalcBoxes();
}
function buildDetail(vals){
    vals = vals || FM.ctl.getValues();
    var lay = FM.lay = FM.ctl.layout(), d = lay.detail;
    if (!d){ $('#fmDetail').html(''); return; }
    var n = lay.extra + 1, h = '<div class="fm-sec">明細（共 '+n+' 筆）</div>';
    for (var i=0;i<n;i++){
        h += '<div class="fm-card"><div class="fm-card-h"><b>第 '+(i+1)+' 筆</b>'+(FM.canEdit && n>1 ? '<button type="button" class="fm-del" data-i="'+i+'" title="刪除這一筆">✕ 刪除</button>' : '')+'</div><div class="fm-grid fm-cardgrid">';
        d.cols.forEach(function(c){
            if (c.kind==='field'){
                var meta = lay.fieldMeta[c.f.id]||{}, wide = meta.w>=200 && c.f.t==='text';
                h += '<div class="fm-fld'+(wide?' fm-wide':'')+'"><label>'+esc(c.header)+(c.f.req?'<span class="req">*</span>':'')+'</label>'+inputHtml(c.f,i,(vals[c.f.id]||[])[i])+'<div class="fm-msg" data-m="'+c.f.id+'_'+i+'"></div></div>';
            } else if (!/項次|序號|NO/i.test(c.header)){
                h += '<div class="fm-fld"><label>'+esc(c.header)+'</label><div class="fm-cv" data-r="'+(lay.groupRow+i)+'" data-c="'+c.c+'"></div></div>';
            }
        });
        h += '</div></div>';
    }
    if (FM.canEdit) h += '<button type="button" class="fm-addrow" id="btnFmAddRow"><i class="fa fa-plus"></i> 新增一筆</button>';
    $('#fmDetail').html(h);
    refreshCalcBoxes();
}
function refreshCalcBoxes(){
    var h = '';
    FM.lay.summary.forEach(function(x){
        h += '<div class="fm-calcbox"><label style="font-size:12.5px">'+esc(x.label||EGSheetForm.a1(x.r,x.c))+'</label><div class="v" data-r="'+x.r+'" data-c="'+x.c+'"></div></div>';
    });
    $('#fmCalc').html(h ? '<div class="fm-sec">計算結果</div>'+h : '');
    refreshCalc();
}
var calcT;
function refreshCalc(){
    clearTimeout(calcT);
    calcT = setTimeout(function(){
        if (!FM) return;
        $('#fmMask [data-r]').each(function(){ var t = FM.ctl.display(+$(this).attr('data-r'), +$(this).attr('data-c')); $(this).text(/^#/.test(t) ? '—' : t); });
    }, 180);
}
function showMsg(id, idx, msg){
    var k = id+'_'+(idx==null?'':idx);
    $('#fmMask [data-f="'+id+'"][data-i="'+(idx==null?'':idx)+'"]').toggleClass('fm-bad', !!msg);
    $('#fmMask [data-m="'+k+'"]').text(msg||'');
}
$('#fmMask').on('input change', '[data-f]', function(){
    if (!FM || !FM.canEdit) return;
    var $e = $(this), f = FM.byId[$e.attr('data-f')], idxRaw = $e.attr('data-i'), idx = (idxRaw===''||idxRaw==null) ? null : +idxRaw;
    var v = $e.is(':checkbox') ? $e.is(':checked') : $e.val();
    FM.ctl.setField(f, idx, v);
    var er = checkValue(f, v, false);
    showMsg(f.id, idx, er ? (f.label||'')+er : '');
    refreshCalc();
});
$('#fmMask').on('click', '#btnFmAddRow', function(){
    $(this).prop('disabled', true);
    FM.ctl.addDetailRow().then(function(){ buildDetail(); });
});
$('#fmMask').on('click', '.fm-del', function(){
    var i = +$(this).attr('data-i');
    if (!confirm('刪除第 '+(i+1)+' 筆（含已填內容）？')) return;
    FM.ctl.removeDetailRow(i).then(function(){ buildDetail(); });
});
function validateAll(strict){
    var errs = [], vals = FM.ctl.getValues();
    $('#fmMask .fm-bad').removeClass('fm-bad'); $('#fmMask .fm-msg').text('');
    FM.fields.forEach(function(f){
        var arr = f.g ? (vals[f.id]||[]) : [vals[f.id]];
        arr.forEach(function(v, i){
            var er = checkValue(f, v, strict); if (!er) return;
            var idx = f.g ? i : null, msg = (f.label||EGSheetForm.a1(f.r,f.c))+(f.g?'（第'+(i+1)+'列）':'')+er;
            errs.push(msg); showMsg(f.id, idx, msg);
        });
    });
    $('#fmErr').text(errs.length ? '請修正以下問題：\n'+errs.slice(0,10).join('\n')+(errs.length>10 ? '\n…另有 '+(errs.length-10)+' 項' : '') : '');
    if (errs.length){ var el = $('#fmMask .fm-bad').get(0); if (el && el.scrollIntoView) el.scrollIntoView({block:'center'}); }
    return errs.length===0;
}
function openInst(id){
    $.getJSON(API, {action:'instance_get', id:id}, function(res){
        if(!res.ok){ alert(res.error); return; }
        var inst = res.instance;
        if (FM && FM.ctl && FM.ctl.dispose) FM.ctl.dispose();
        $('#fmEngine').empty().append('<div id="fmU" style="position:absolute;inset:0;"></div>');
        FM = { id:inst.id, inst:inst, canEdit:!!inst.can_edit, fields:inst.fields, byId:{} };
        inst.fields.forEach(function(f){ FM.byId[f.id] = f; });
        $('#fmTitle').text(inst.template_name);
        $('#fmName').val(inst.title).prop('disabled', !inst.can_edit);
        $('#fmDate').val(inst.biz_date).prop('disabled', !inst.can_edit);
        $('#fmWho').text(inst.template_name+'／'+(inst.creator_name||''));
        $('#fmState').html(inst.status==='done' ? '<span class="st-badge st-done">已完成（唯讀）</span>' : (inst.can_edit ? '<span class="st-badge st-draft">草稿</span>' : '<span class="st-badge st-done">唯讀</span>'));
        $('#fmBase,#fmDetail,#fmCalc').html(''); $('#fmErr').text('');
        $('#btnFmSave,#btnFmDone').toggle(!!inst.can_edit);
        $('#fmMask').addClass('on'); $('#fmMask .fm-foot button').prop('disabled', true);
        $('#fmBase').html('<div style="padding:20px;color:#8a6d45">載入中…</div>');
        FM.lay = { fieldMeta:{} };
        var cur = FM;
        loadEngine().then(function(){
            if (FM!==cur) return;
            FM.ctl = EGSheetForm.mount({ el:'fmU', mode:'fill', book:inst.book, fields:inst.fields, values:inst.values, extraRows:inst.extra_rows, people:META.people, toast:toast });
            return FM.ctl.ready;
        }).then(function(){ if (FM!==cur) return; buildForm(); $('#fmMask .fm-foot button').prop('disabled', false); })
          .catch(function(e){ $('#fmBase').html('<div style="padding:20px;color:#DD5138">'+esc(e.message||'載入失敗')+'</div>'); $('#btnFmClose').prop('disabled', false); });
    });
}
function collect(submit){
    return { action:'instance_save', id:FM.id, title:$('#fmName').val(), biz_date:$('#fmDate').val(), extra_rows:FM.ctl.getExtra(), values_json:JSON.stringify(FM.ctl.getValues()), submit:submit?1:0 };
}
function doSave(submit){
    if (!validateAll(submit)) return;
    post(collect(submit), function(res){
        toast(submit?'已完成送出':'已儲存草稿'); loadList();
        if (submit){ closeFill(true); }
    }, function(res){
        $('#fmErr').text(res.error||'儲存失敗');
        (res.errors||[]).forEach(function(e){ showMsg(e[0], e[1], e[2]); });
    });
}
$('#btnFmSave').on('click', function(){ doSave(false); });
$('#btnFmDone').on('click', function(){ if (confirm('完成送出後單據會鎖定、不能再修改，確定嗎？')) doSave(true); });
function printInst(){
    var i = FM.inst;
    EGSheetForm.printWindow(FM.ctl, { title:i.print.title, company:i.print.company, doc_no:i.print.doc_no, paper_size:i.paper_size, orientation:i.orientation });
    post({action:'print_log', id:FM.id}, function(){});
}
$('#btnFmPrint').on('click', printInst);
function closeFill(force){
    if (!force && FM && FM.canEdit && !confirm('關閉前請確認已按「儲存草稿」，未儲存的內容會遺失。確定關閉？')) return;
    if (FM && FM.ctl && FM.ctl.dispose) FM.ctl.dispose();
    FM = null; $('#fmMask').removeClass('on'); $('#fmEngine').empty();
}
$('#btnFmClose,#btnFmX').on('click', function(){ closeFill(false); });
</script>
</body>
</html>
