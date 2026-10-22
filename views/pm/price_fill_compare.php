<?php
/**
 * 訂單補單價比對頁（臨時頁，2026-10-22）— 左：補資料／ERP 內容，右：對應的訂單追蹤內容，可批次轉價格。
 * 僅系統管理員。判定與套用邏輯唯一實作在 src/common/order_price_autofill_lib.php。
 */
session_start();
if (!isset($_SESSION['userName'])) { header("Location:../../index.php"); exit; }
include '../../src/common/DBConnection.php';
include '../../src/common/_config.php';
include '../../src/common/role_features_helper.php';
$isAdminPage = rf_has_feature(rf_load_user_features($db, (int)($_SESSION['id'] ?? 0)), 'all');
if (!$isAdminPage) { http_response_code(403); echo '僅系統管理員可使用此頁'; exit; }
?>
<!DOCTYPE html>
<html lang="zh-Hant">
<head>
<meta charset="utf-8">
<meta http-equiv="X-UA-Compatible" content="IE=edge">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>補單價比對</title>
<link href="../../resource/css/bootstrap.css" rel="stylesheet">
<link href="../../resource/css/font-awesome.css" rel="stylesheet">
<link href="../../resource/css/nprogress.css" rel="stylesheet">
<link href="../../resource/css/custom.css" rel="stylesheet">
<style>
#sidebar-menu { visibility: hidden; }
.right_col .page-title { margin:8px 0 4px; overflow:hidden; clear:both; }
.pf-note { background:#FFF7E8; border:1px dashed #F0A24B; border-radius:6px; padding:8px 12px; margin:6px 0 10px; font-size:12px; color:#5b3a1e; line-height:1.7; clear:both; }
.pf-tabs { display:flex; flex-wrap:wrap; gap:6px; margin:6px 0; }
.pf-tab { border:1px solid #E5C79A; background:#fff; color:#5b3a1e; border-radius:16px; padding:3px 12px; font-size:12px; cursor:pointer; }
.pf-tab.on { background:#F0A24B; color:#fff; border-color:#d98a33; }
.pf-tab .n { background:#F7E0BD; color:#5b3a1e; border-radius:9px; padding:0 6px; margin-left:4px; font-size:11px; }
.pf-tab.on .n { background:#fff; }
.pf-bar { display:flex; flex-wrap:wrap; gap:8px; align-items:center; margin:6px 0; }
.pf-bar input[type=text] { height:30px; width:240px; }
.pf-btn { height:30px; padding:0 12px; border-radius:4px; border:1px solid #d98a33; background:#F0A24B; color:#fff; cursor:pointer; font-size:13px; }
.pf-btn.sec { background:#fff; color:#8A5A2B; }
.pf-btn:disabled { opacity:.45; cursor:not-allowed; }
table.pf { width:100%; border-collapse:collapse; font-size:12px; background:#fff; table-layout:fixed; }
table.pf th { background:#F7E0BD; color:#5b3a1e; padding:5px 6px; border:1px solid #E5C79A; position:sticky; top:0; z-index:2; }
table.pf td { user-select:text; border:1px solid #EAD9BF; padding:4px 6px; vertical-align:top; word-break:break-all; line-height:1.5; }
table.pf td.l { background:#FFF9EF; } table.pf td.r { background:#fff; }
.pf-grp { background:#F7E0BD; font-weight:600; color:#5b3a1e; }
.pf-price { font-weight:700; color:#8A5A2B; } .pf-diff { color:#DD5138; font-weight:700; }
.pf-tag { display:inline-block; border-radius:3px; padding:0 6px; font-size:11px; background:#F0A24B; color:#fff; margin-right:4px; line-height:16px; }
.pf-tag.g { background:#8A8A8A; } .pf-tag.w { background:#DD5138; }
.pf-proc-bad { background:#DD5138; color:#fff; font-weight:700; padding:0 4px; border-radius:3px; }
.pf-proc-ok { background:#DDEFD0; color:#3b5d22; font-weight:700; padding:0 4px; border-radius:3px; }
.pf-proc-syn { background:#DDEFD0; color:#3b5d22; font-weight:700; padding:0 4px; border-radius:3px; border-bottom:2px dashed #7aa65a; }
.pf-proc-na { background:#F7E0BD; color:#8A5A2B; padding:0 4px; border-radius:3px; }
.pf-pick { cursor:copy; }
.pf-pm { margin-top:4px; } .pf-pm .pm-in { width:180px; height:24px; }
.pf-chip { display:inline-block; cursor:pointer; border:1px solid #d98a33; background:#FFF3DD; color:#8A5A2B; border-radius:10px; padding:0 8px; margin:2px 3px 0 0; font-size:11px; }
.pf-chip:hover { background:#F0A24B; color:#fff; }
.pf-src { border-bottom:1px dotted #E5C79A; padding:2px 0; } .pf-src:last-child { border:0; }
.pf-wrap { overflow:auto; max-height:70vh; border:1px solid #E5C79A; }
.pf-pager { display:flex; gap:6px; align-items:center; margin:8px 0; font-size:12px; }
</style>
</head>
<body class="nav-sm">
<div class="container body"><div class="main_container">
<?php include '../partPage/sideAndTopBarMenu.html' ?>
<div class="right_col" role="main"><div class="">
  <div class="page-title"><div class="title_left"><h4>訂單補單價比對 <small>（臨時頁・僅系統管理員）</small></h4></div></div>
  <div class="pf-note">
    左欄＝補資料（暫存區）或 ERP 訂單資料的內容，右欄＝對應的訂單追蹤內容，逐筆對照。<br>
    <b>只會填「單價空白」的訂單，不覆蓋任何既有單價</b>；補入者業務備註加註「【自動補單價】」並留紀錄（order_price_autofill_log）。<br>
    <b>規則1</b>＝同訂單編號下料號與數量組合完全相同；<b>規則2</b>＝同訂單＋料號＋數量＋製程完全相同（製程不可空白）。
    補資料待轉入列只要「訂單內已有相同料號與數量明細」，系統會自動認定為「已存在，不需轉入」；規則1／2 轉價格後也一樣。<br>
    <b>製程比對色</b>：<span class="pf-proc-ok">綠＝一致</span>　<span class="pf-proc-bad">紅＝不一致（左邊品名規格最後一段＝製程，右邊訂單製程同步標色）</span>　<span class="pf-proc-syn">綠＋虛線＝用語不同但意思相同（全製＝代料完成＝代料到成品…，自動判定一致）</span>　<span class="pf-proc-na">橘＝其中一邊空白，無法比對</span><br>A／B 是依 ERP 訂單資料的建議（B 的製程空白或不一致，請抽查）；C、D 只供參考，不可批次。
  </div>
  <div id="autoMsg" class="pf-note" style="display:none;background:#EFF7E8;border-color:#7aa65a"></div>
  <div class="pf-tabs" id="tabs"></div>
  <div class="pf-bar">
    <input type="text" id="q" placeholder="搜尋 訂單編號／料號／客戶／製程（空白分隔多關鍵字）" data-eg-skip>
    <input type="text" id="lproc" placeholder="左側製程篩選（補資料／ERP）" data-eg-skip style="width:190px">
    <input type="text" id="rproc" placeholder="右側製程篩選（訂單追蹤）" data-eg-skip style="width:190px">
    <select id="pstate" class="form-control input-sm" style="width:130px"><option value="">製程：全部</option><option value="bad">不一致</option><option value="na">一邊空白</option><option value="syn">同義</option><option value="ok">一致</option></select>
    <select id="per" class="form-control input-sm" style="width:90px"><option>50</option><option>100</option><option>200</option></select>
    <button class="pf-btn sec" id="btnPage">勾選本頁全部</button>
    <button class="pf-btn sec" id="btnAllMatch">勾選全部符合（不分頁）</button>
    <button class="pf-btn sec" id="btnClear">取消勾選</button>
    <span id="selInfo" style="font-size:12px;color:#8A5A2B">已選 0 筆</span>
    <label style="margin:0;font-size:12px;color:#5b3a1e;cursor:pointer"><input type="checkbox" id="fillProc" checked> 同時把 ERP 製程填入訂單製程空白者</label>
    <button class="pf-btn" id="btnApply" disabled><i class="fa fa-exchange"></i> 轉價格（已選 <span id="selN">0</span>）</button>
  </div>
  <div class="pf-wrap"><table class="pf" id="tb">
    <colgroup><col style="width:34px"><col style="width:41%"><col style="width:59%"></colgroup>
    <thead><tr><th><input type="checkbox" id="ckAll"></th><th>補資料／ERP 內容（來源）</th><th>訂單追蹤內容（現況）</th></tr></thead>
    <tbody id="body"><tr><td colspan="3" style="text-align:center;padding:20px">載入中…</td></tr></tbody>
  </table></div>
  <div class="pf-pager"><button class="pf-btn sec" id="pPrev">上一頁</button><span id="pInfo"></span><button class="pf-btn sec" id="pNext">下一頁</button></div>
</div></div>
<?php include '../partPage/footer.html' ?>
</div></div>
<script src="../../resource/js/jquery.min.js"></script>
<script src="../../resource/js/bootstrap.min.js"></script>
<script src="../../resource/js/fastclick.js"></script>
<script src="../../resource/js/nprogress.js"></script>
<script src="../../resource/js/custom.min.js"></script>
<script src="../../resource/js/eg_input_rules.js?v=<?= @filemtime(__DIR__.'/../../resource/js/eg_input_rules.js') ?>"></script>
<script>
$(document).ready(function(){
    var $am = $('#sidebar-menu .nav.side-menu > li.active');
    if ($am.length) { $am.removeClass('active').find('ul.child_menu').hide(); $am.find('li.current-page').removeClass('current-page'); }
    $('#sidebar-menu').css('visibility','visible');
});
var API = '../../src/store/PriceFillCompare_API.php';
var CATS = [['R1','規則1（可轉）'],['R2','規則2（可轉）'],['DONE','已補價待結案（可轉）'],['PROC','已補價·補製程（可轉）'],['PROCM','補製程·需人工（可填寫後轉）'],['A','建議A（可轉）'],['B','建議B 需抽查（可轉）'],['P','一對一配對（可轉・確認順序）'],['C','C 多個單價（可選加總）'],['D','D 無ERP資料'],['N','非訂單（治具／繪圖）']];
var PM = {}, CAT = 'R1', FIRST = true, PAGE = 1, TOTAL = 0, PER = 50, COUNTS = {}, CANAPPLY = [], SEL = {}, ROWS = {};
function esc(s){ return String(s==null?'':s).replace(/[&<>"]/g,function(c){return {'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;'}[c];}); }
function fmtP(v){ if(v===null||v===''||v===undefined) return '—'; var n=parseFloat(v); return isNaN(n)?esc(v):n.toLocaleString('en-US',{maximumFractionDigits:4}); }
function dd(s){ return s ? String(s).substr(0,10).replace(/-/g,'.') : ''; }
function api(act, data){ return $.ajax({url:API+'?action='+act, type:'POST', data:data, dataType:'json'}); }
function selCount(){ return Object.keys(SEL).length; }
function refreshSel(){ var n=selCount(); $('#selN').text(n); $('#selInfo').text('已選 '+n+' 筆'); $('#btnApply').prop('disabled', n===0 || CANAPPLY.indexOf(CAT)<0); }
function renderTabs(){
    var h=''; CATS.forEach(function(c){ h+='<span class="pf-tab'+(c[0]===CAT?' on':'')+'" data-c="'+c[0]+'">'+c[1]+'<span class="n">'+(COUNTS[c[0]]||0)+'</span></span>'; });
    $('#tabs').html(h);
}
function specSplit(spec){ var t=String(spec||'').trim(), i=t.search(/\s\S*$/); return i<0 ? {head:'',tail:t} : {head:t.slice(0,i+1), tail:t.slice(i+1)}; }
function srcHtml(c){
    return (c.src||[]).map(function(s){
        var st = s.state==='ERP' ? '<span class="pf-tag g">ERP</span>' : '<span class="pf-tag'+(s.state==='converted'?' g':'')+'">暫存·'+esc({pending:'待轉入',skipped:'已略過',converted:'已轉入'}[s.state]||s.state)+'</span>';
        var sp=specSplit(s.spec_text), ps = c.proc_state;
        var specH = s.spec_text ? '品名規格：'+esc(sp.head)+'<span class="pf-proc-'+ps+(c.cat==='PROCM'?' pf-pick':'')+'" '+(c.cat==='PROCM'?'data-v="'+esc(sp.tail)+'" title="雙擊帶入右側「補入製程」"':'')+'>'+esc(sp.tail)+'</span>' : '';
        return '<div class="pf-src">'+st+'單號 <b>'+esc(s.order_oo)+'</b>　料號 <b>'+esc(s.part_no)+'</b>　數量 <b>'+esc(s.qty)+'</b>　單價 <span class="pf-price">'+fmtP(s.unit_price)+'</span><br>'+
               (s.process_text!=null ? '製程：<span class="pf-proc-'+ps+'">'+(esc(s.process_text)||'（空白）')+'</span><br>' : '')+
               specH+(s.order_date?'　日期：'+dd(s.order_date):'')+'</div>';
    }).join('') || '<i style="color:#999">（無對應來源明細）</i>';
}
function ordProcState(c){ return c.proc_state; }
function pcHtml(c){   // 多個單價：選「加總」或單一單價
    if(c.cat!=='C' || !c.price_options) return '';
    var o=c.price_options, h='<option value="'+o.sum+'">加總 → '+fmtP(o.sum)+'</option>';
    o.each.forEach(function(p){ h+='<option value="'+p+'">只採用 '+fmtP(p)+'</option>'; });
    return '<div class="pf-pm">採用單價：<select class="pc-sel" data-id="'+c.order_id+'">'+h+'</select></div>';
}
var PF = {};   // 自動預填的製程（用來判斷「沒勾同時補製程」時不要送出）
function ooHtml(c){   // 查無 ERP 資料的訂單：多半是單號打錯，可直接輸入正確單號修改
    if(c.cat==='N') return '<div class="pf-pm"><button type="button" class="pf-btn ex-go" data-id="'+c.order_id+'" data-flag="0" style="height:24px;padding:0 10px">取消非訂單標記</button></div>';
    if(c.cat!=='D') return '';
    return '<div class="pf-pm"><button type="button" class="pf-btn ex-go" data-id="'+c.order_id+'" data-flag="1" style="height:24px;padding:0 10px" title="治具或只有繪製圖面、為統計工作量才列入的，不需補單價">非訂單</button>　更正單號：<input type="text" class="oo-in" data-id="'+c.order_id+'" placeholder="輸入正確單號，例 OO1150601001" maxlength="12" data-eg-skip> <button type="button" class="pf-btn oo-go" data-id="'+c.order_id+'" style="height:24px;padding:0 10px">改單號</button></div>';
}
function pmHtml(c){   // 訂單製程空白時：補入製程輸入框（預填 ERP 製程）＋ERP 候選快速帶入；所有可轉的類別都適用
    var blank = String(c.order.Processing_items||'').trim()==='';
    if(c.cat==='PROCM'){}
    else if(!(blank && c.cat!=='DONE' && c.cat!=='D')) return '';
    if(blank && PM[c.order_id]===undefined && c.proc_fill){ PM[c.order_id]=c.proc_fill; PF[c.order_id]=c.proc_fill; }
    var opts=(c.proc_options||[]).slice();
    (c.src||[]).forEach(function(s){ var t=(s.process_text!=null&&s.process_text!=='')?s.process_text:specSplit(s.spec_text).tail; t=String(t||'').trim(); if(t && opts.indexOf(t)<0) opts.push(t); });
    var chips=opts.map(function(o){ return '<span class="pf-chip" data-v="'+esc(o)+'">'+esc(o)+'</span>'; }).join('');
    return '<div class="pf-pm">補入製程：<input type="text" class="pm-in" data-id="'+c.order_id+'" value="'+esc(PM[c.order_id]||'')+'" placeholder="直接輸入／貼上，或點下方候選" data-eg-skip> '+chips+'</div>';
}
function ordHtml(c){
    var o=c.order, same = c.src && c.src[0] && String(c.src[0].qty)===String(o.Qty);
    return '<span class="pf-tag g">訂單 #'+esc(o.Order_id)+'</span>單號 <b>'+esc(o.Order_oo)+'</b>　客戶 '+esc(o.client_display||'')+'<br>'+
           '料號 <b>'+esc(o.d_id)+'</b>　數量 <b>'+esc(o.Qty)+'</b>　目前單價 <span class="pf-diff">'+fmtP(o.unit_price)+'</span>'+
           (c.proc_fill ? '<br><span class="pf-tag w">將補製程</span><b>'+esc(c.proc_fill)+'</b>' : '')+(c.price!=null && c.cat!=='DONE' ? '　→　<span class="pf-price">補入 '+fmtP(c.price)+'</span>' : '')+'<br>'+
           '製程：<span class="pf-proc-'+ordProcState(c)+'">'+(esc(o.Processing_items)||'（空白）')+'</span><br>接單 '+dd(o.Order_date)+'　交期 '+dd(o.Delivery_date)+(o.quote_no?'　報價 '+esc(o.quote_no):'')+
           (o.Order_ps?'<br>業務備註：'+esc(o.Order_ps):'')+'<div style="color:#8A5A2B;margin-top:2px">▶ '+esc(c.note)+'</div>'+pmHtml(c)+pcHtml(c)+ooHtml(c);
}
function render(res){
    ROWS={}; var h='';
    var canAp = CANAPPLY.indexOf(CAT)>=0;
    res.rows.forEach(function(c){
        ROWS[c.order_id]=c;
        h+='<tr data-id="'+c.order_id+'"><td style="text-align:center">'+(canAp?'<input type="checkbox" class="ck" value="'+c.order_id+'"'+(SEL[c.order_id]?' checked':'')+'>':'')+'</td><td class="l">'+srcHtml(c)+'</td><td class="r">'+ordHtml(c)+'</td></tr>';
    });
    $('#body').html(h || '<tr><td colspan="3" style="text-align:center;padding:20px;color:#999">沒有資料</td></tr>');
    var pages=Math.max(1,Math.ceil(res.total/res.per));
    $('#pInfo').text('第 '+res.page+' / '+pages+' 頁（共 '+res.total+' 筆）');
    $('#pPrev').prop('disabled',res.page<=1); $('#pNext').prop('disabled',res.page>=pages);
    $('#ckAll').prop('disabled',!canAp).prop('checked',false);
    refreshSel();
}
function load(){
    $('#body').html('<tr><td colspan="3" style="text-align:center;padding:20px">載入中…</td></tr>');
    api('list',{cat:CAT,q:$('#q').val(),lproc:$('#lproc').val(),rproc:$('#rproc').val(),pstate:$('#pstate').val(),page:PAGE,per:$('#per').val()}).done(function(res){
        if(!res.ok){ alert(res.error); return; }
        COUNTS=res.counts; CANAPPLY=res.can_apply; TOTAL=res.total;
        if (res.auto_closed) { $('#autoMsg').text('已自動將 '+res.auto_closed+' 筆「訂單內已有相同明細」的補資料待轉入列認定為已存在').show(); }
        if (FIRST) { FIRST=false; if (!COUNTS[CAT]) { var f=CATS.filter(function(c){return COUNTS[c[0]];})[0]; if (f) { CAT=f[0]; load(); return; } } }
        renderTabs(); render(res);
    }).fail(function(x){ alert('讀取失敗：'+(x.responseJSON&&x.responseJSON.error||x.status)); });
}
$('#tabs').on('click','.pf-tab',function(){ CAT=$(this).data('c'); PAGE=1; SEL={}; load(); });
$('#body').on('dblclick','.pf-pick',function(e){ e.stopPropagation(); $(this).closest('tr').find('.pm-in').val($(this).data('v')).trigger('input'); window.getSelection().removeAllRanges(); });
$('#body').on('input','.oo-in',function(){ var p=this.selectionStart; this.value=this.value.toUpperCase().replace(/\s+/g,''); try{this.setSelectionRange(p,p);}catch(e){} });
$('#body').on('keydown','.oo-in',function(e){ if(e.key==='Enter'){ e.preventDefault(); $(this).closest('.pf-pm').find('.oo-go').click(); } });
$('#body').on('dblclick','.oo-in',function(){ $(this).val(''); });
$('#body').on('click','.oo-go',function(e){
    e.stopPropagation(); var id=$(this).data('id'), v=($(this).closest('.pf-pm').find('.oo-in').val()||'').trim().toUpperCase();
    if(!/^OO\d{10}$/.test(v)){ alert('單號格式應為 OO＋民國年3碼＋月2碼＋日2碼＋流水號3碼（共12碼，大寫），例：OO1150601001'); return; }
    var c=ROWS[id]||{order:{}};
    if(!confirm('把訂單 #'+id+' 的單號：'+(c.order.Order_oo||'')+'　改為：'+v+'　確定？')) return;
    var $btn=$(this).prop('disabled',true);
    api('fix_oo',{order_id:id,new_oo:v}).done(function(res){
        $btn.prop('disabled',false);
        if(!res.ok){ alert(res.error); return; }
        // AJAX 就地更新該列，不重載整頁、不再跳第二個提示
        c.order.Order_oo=res.new_oo;
        var $tr=$('#body tr[data-id="'+id+'"]');
        $tr.find('td.r').html(ordHtml(c));
        $tr.find('td.r .pf-pm').last().append('<div style="color:#2E7D32;margin-top:3px">✔ 已改為 '+esc(res.new_oo)+(res.erp_rows? '（ERP 有此單號＋料號的資料 '+res.erp_rows+' 筆，切換分頁或重新查詢後會移到對應分頁）':'（ERP 查無此單號＋料號的資料）')+'</div>');
    }).fail(function(x){ $btn.prop('disabled',false); alert('失敗：'+(x.responseJSON&&x.responseJSON.error||x.status)); });
});
// 標記／取消「非訂單」：只確認一次，AJAX 就地移除該列並更新分頁筆數
$('#body').on('click','.ex-go',function(e){
    e.stopPropagation(); var $b=$(this), id=$b.data('id'), flag=String($b.data('flag'))==='1', c=ROWS[id]||{order:{}};
    if(!confirm(flag ? '把訂單 #'+id+'（'+(c.order.Order_oo||'')+'）標記為「非訂單」（治具／只繪製圖面，統計工作量用）？不會再列入補單價。' : '取消訂單 #'+id+' 的「非訂單」標記？')) return;
    $b.prop('disabled',true);
    api('set_exempt',{ids:JSON.stringify([id]),flag:flag?1:0}).done(function(res){
        if(!res.ok){ $b.prop('disabled',false); alert(res.error); return; }
        var from=flag?'D':'N';
        $('#body tr[data-id="'+id+'"]').fadeOut(150,function(){ $(this).remove(); });
        if(COUNTS[from]) COUNTS[from]--; if(!flag) COUNTS.N=COUNTS.N||0; if(flag) COUNTS.N=(COUNTS.N||0)+1;
        TOTAL--; renderTabs();
    }).fail(function(x){ $b.prop('disabled',false); alert('失敗：'+(x.responseJSON&&x.responseJSON.error||x.status)); });
});
$('#body').on('click','.pf-chip',function(e){ e.stopPropagation(); var $in=$(this).closest('.pf-pm').find('.pm-in'); $in.val($(this).data('v')).trigger('input'); });
$('#body').on('input','.pm-in',function(){ PM[$(this).data('id')]=$(this).val(); var $ck=$(this).closest('tr').find('.ck'); if($(this).val().trim() && !$ck.prop('checked')){ $ck.prop('checked',true).trigger('change'); } });
$('#body').on('dblclick','.pm-in',function(){ $(this).val('').trigger('input'); });
$('#body').on('change','.ck',function(){ var id=$(this).val(); if(this.checked) SEL[id]=1; else delete SEL[id]; refreshSel(); });
$('#body').on('click','td.l,td.r',function(e){ if($(e.target).is('a,input,select,option,button,.pf-chip')) return;
    if(String(window.getSelection?window.getSelection():'').length>0) return;   // 正在選取文字（要複製）時不切換勾選
    var $c=$(this).closest('tr').find('.ck'); if($c.length){ $c.prop('checked',!$c.prop('checked')).trigger('change'); } });
$('#ckAll,#btnPage').on('click',function(){ $('#body .ck').each(function(){ $(this).prop('checked',true); SEL[$(this).val()]=1; }); refreshSel(); });
$('#btnAllMatch').on('click',function(){
    if(CANAPPLY.indexOf(CAT)<0){ fillToast('此類別不可批次轉入'); return; }
    var $b=$(this).prop('disabled',true);
    api('list',{cat:CAT,q:$('#q').val(),ids_only:1}).done(function(res){
        $b.prop('disabled',false);
        if(!res.ok){ alert(res.error); return; }
        res.ids.forEach(function(id){ SEL[id]=1; });
        $('#body .ck').each(function(){ $(this).prop('checked',!!SEL[$(this).val()]); });
        refreshSel(); fillToast('已勾選全部符合的 '+res.ids.length+' 筆（含其他頁）');
    }).fail(function(){ $b.prop('disabled',false); alert('讀取失敗'); });
});
$('#btnClear').on('click',function(){ SEL={}; $('#body .ck').prop('checked',false); $('#ckAll').prop('checked',false); refreshSel(); });
$('#pPrev').on('click',function(){ PAGE--; load(); }); $('#pNext').on('click',function(){ PAGE++; load(); });
$('#per,#pstate').on('change',function(){ PAGE=1; load(); });
// 輸入框快速連點兩下＝清除內容並解除該篩選；製程狀態下拉連點兩下＝回到「全部」
$('#q,#lproc,#rproc').on('dblclick',function(){ if($(this).val()!==''){ $(this).val('').trigger('input'); } });
$('#pstate').on('dblclick',function(){ if($(this).val()!==''){ $(this).val('').trigger('change'); } });
var qt=null; $('#q,#lproc,#rproc').on('input',function(){ clearTimeout(qt); qt=setTimeout(function(){ PAGE=1; load(); },350); });
function fillToast(t){
    var $t=$('<div></div>').text(t).css({position:'fixed',right:'20px',bottom:'24px',zIndex:99999,background:'#7A4A1E',color:'#fff',padding:'10px 16px',borderRadius:'6px',fontSize:'14px',boxShadow:'0 2px 8px rgba(0,0,0,.3)'}).appendTo('body');
    setTimeout(function(){ $t.fadeOut(400,function(){ $t.remove(); }); },3000);
}
$('#btnApply').on('click',function(){
    var ids=Object.keys(SEL).map(Number); if(!ids.length) return;
    var label=(CATS.filter(function(c){return c[0]===CAT;})[0]||[])[1]||CAT;
    var pm = {}, pc = {};
    if (CAT!=='PROCM' && CAT!=='DONE') { var fp=$('#fillProc').prop('checked'); ids.forEach(function(id){ var v=(PM[id]||'').trim(); if(!v) return; if(!fp && PF[id]!==undefined && v===PF[id]) return; pm[id]=v; }); }
    if (CAT==='C') { ids.forEach(function(id){ pc[id]=$('.pc-sel[data-id="'+id+'"]').val(); }); }
    if (CAT==='PROCM') { var miss=0; ids.forEach(function(id){ var v=(PM[id]||'').trim(); if(!v) miss++; else pm[id]=v; }); if(miss){ alert('有 '+miss+' 筆還沒填「補入製程」，請填寫或取消勾選。'); return; } }
    var warn = CAT==='B' ? '\n\n※ 建議B 的製程空白或與 ERP 不一致，請確認已抽查。' : '';
    var what = CAT==='C' ? '把你在每列選定的單價（加總或單一單價）轉入訂單追蹤（只填空白、業務備註加註【自動補單價】）' : CAT==='PROCM' ? '把你填寫的製程寫入訂單製程空白者（單價不動，業務備註加註【自動補製程】）' : CAT==='PROC' ? '只把 ERP 製程填入訂單製程空白者（單價不動，業務備註加註【自動補製程】）' : CAT==='DONE' ? '把對應的補資料列認定為「已存在」（不改單價）' : '把單價轉入訂單追蹤（只填空白、業務備註加註【自動補單價】）'+(CAT==='R1'||CAT==='R2'?'，並把對應的補資料待轉入列認定為「已存在，不需轉入」':'');
    if(!confirm('類別：'+label+'\n確定對 '+ids.length+' 筆：'+what+'？'+warn)) return;
    var $b=$(this).prop('disabled',true);
    api('apply',{cat:CAT,ids:JSON.stringify(ids),fill_proc:$('#fillProc').prop('checked')?1:0,proc_map:JSON.stringify(pm),price_map:JSON.stringify(pc)}).done(function(res){
        if(!res.ok){ alert(res.error); $b.prop('disabled',false); return; }
        var doneTxt='完成：補入單價 '+res.filled+' 筆、補資料認定已轉入 '+res.staged+' 筆、略過 '+res.skipped+' 筆';
        if(res.msgs&&res.msgs.length){ alert(doneTxt+'\n'+res.msgs.slice(0,10).join('\n')); }
        else { fillToast(doneTxt); }
        SEL={}; load();
    }).fail(function(x){ alert('失敗：'+(x.responseJSON&&x.responseJSON.error||x.status)); $b.prop('disabled',false); });
});
load();
</script>
</body></html>
