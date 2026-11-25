<?php
/**
 * views/ACC/voucher.php — 傳票（請款）
 *
 * 開完發票後，選定要請款的出貨單／退貨單（應付側為加工移轉憑單）開傳票。
 * 傳票日期可改；ERP 傳票號碼可建立時填、也可事後補填。
 * 有傳票的明細視為已做帳（請款），金額／數量／料號鎖定（ERP 對帳單匯入等功能會檢查）。
 * 資料一律走 src/store/Acc_API.php（vch_*），邏輯在 src/common/acc_voucher_lib.php。
 */
session_start();
if (!isset($_SESSION['userName'])) {
    $_SESSION['lastpage'] = "../../views/ACC/voucher.php";
    header("Location:../../index.php");
    exit;
}
include_once '../../src/common/_config.php';
include_once '../../src/common/DBConnection.php';
include_once '../../src/common/acc_lib.php';
include_once '../../src/common/acc_voucher_lib.php';

$db = (new DBConnection())->getPDO();
acc_ensure_schema($db);
acc_voucher_ensure($db);
$accUser = acc_current_user($db);
$perms   = acc_perms($db, $accUser);
$roleLbl = $perms['isAdmin'] ? '管理者'
         : ($perms['canAdmin'] ? '會計管理員'
         : ($perms['canEdit'] ? '會計登錄'
         : ($perms['canView'] ? '會計檢閱' : '無權限')));
?>
<!DOCTYPE html>
<html lang="zh-Hant">
<head>
<meta charset="utf-8">
<meta http-equiv="X-UA-Compatible" content="IE=edge">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>傳票管理</title>
<link href="../../resource/css/bootstrap.css" rel="stylesheet">
<link href="../../resource/css/font-awesome.css" rel="stylesheet">
<link href="../../resource/css/nprogress.css" rel="stylesheet">
<link href="../../resource/css/custom.css" rel="stylesheet">
<link href="_acc_pages.css?v=<?= @filemtime(__DIR__ . '/_acc_pages.css') ?: time() ?>" rel="stylesheet">
<style>
.page-help-btn{margin-left:auto;height:30px;padding:0 12px;border:1px solid var(--a-line2);border-radius:15px;background:#fff;color:var(--a-ink);cursor:pointer;}
.help-doc{font-size:13.5px;line-height:1.9;color:var(--a-ink);}
.help-doc h5{margin:10px 0 2px;color:var(--a-brand);font-weight:bold;}
.st-open{background:var(--a-ok);color:#6b4522;} .st-void{background:#C9B69F;color:#fff;}
.lk{color:#8a6d45;} tr.lockedrow{color:#a08a6a;background:#F6EFE2 !important;}
.vno-in{width:150px;height:28px;border:1px solid var(--a-line2);border-radius:4px;padding:0 6px;color:var(--a-ink);}
</style>
</head>
<body class="nav-sm">
<div class="container body">
<div class="main_container">
  <?php include '../partPage/sideAndTopBarMenu.html' ?>
  <div class="right_col" role="main">
    <div class="page-title" style="display:flex;align-items:center;flex-wrap:wrap;">
      <h2 style="margin:6px 0;"><i class="fa fa-book" style="color:#F0A24B;"></i> 傳票管理
        <small style="color:#8a6d45;">選定請款的出貨／退貨（廠商＝移轉憑單）開傳票，填入 ERP 傳票號碼後鎖定</small></h2>
      <button class="page-help-btn" id="btnHelp"><i class="fa fa-question-circle"></i> 使用說明</button>
    </div>
    <div class="clearfix"></div>
<?php if (!$perms['canView']): ?>
    <div class="a-noperm"><h4><i class="fa fa-lock"></i> 無會計模組檢閱權限</h4>
      <p>請洽管理者於「使用者權限設定」指派會計角色。</p></div>
<?php else: ?>
    <div class="a-tabs">
      <button class="tab-btn on" data-tab="new"><i class="fa fa-plus-square-o"></i> 1. 開立傳票</button>
      <button class="tab-btn" data-tab="list"><i class="fa fa-search"></i> 2. 傳票查詢</button>
      <span class="a-role" style="align-self:center;">目前角色：<b><?= htmlspecialchars($roleLbl) ?></b></span>
    </div>

    <div id="tabNew">
      <div class="a-bar">
        <label>對象</label>
        <select id="vSide"><option value="ar">客戶（應收）</option><option value="ap">廠商（應付）</option></select>
        <label>帳款月份</label><input type="month" id="vBm">
        <button id="btnVParties" class="btn-warm"><i class="fa fa-refresh"></i> 載入對象</button>
        <label>客戶／廠商</label>
        <select id="vParty" data-eg-filter="輸入名稱或代號篩選…" style="min-width:260px;"><option value="">（請先載入）</option></select>
        <button id="btnVLoad"><i class="fa fa-list"></i> 列出明細</button>
      </div>
      <div id="vInfo"></div>
      <div id="vBox" style="display:none;">
        <div class="a-bar" style="background:var(--a-bg2);">
          <label>傳票日期</label><input type="date" id="vDate">
          <label>ERP傳票號碼</label><input type="text" id="vNo" maxlength="40" class="vno-in" placeholder="可事後補填" data-eg-hint="ERP 開出傳票後再填也可以">
          <label>備註</label><input type="text" id="vNote" maxlength="200" style="width:200px;">
          <button id="btnVAll">全選可開立</button><button id="btnVNone">清除</button>
          <?php if ($perms['canEdit']): ?>
          <button id="btnVCreate" class="btn-warm" disabled><i class="fa fa-book"></i> 開立傳票</button>
          <?php endif; ?>
          <span style="margin-left:auto;font-size:13px;">已選 <b id="vSelN" style="color:var(--a-bad);">0</b> 筆　合計 <b id="vSelAmt">0</b></span>
        </div>
        <div class="a-wrap" style="max-height:60vh;overflow:auto;">
          <table class="a-t"><thead><tr><th style="width:30px;"><input type="checkbox" id="vCkAll"></th><th>類型</th><th>單號</th><th>日期</th>
            <th>料號</th><th>品名規格</th><th>數量</th><th>單價</th><th>金額</th><th>發票</th><th>傳票</th></tr></thead>
            <tbody id="vBody"></tbody></table>
        </div>
      </div>
    </div>

    <div id="tabList" style="display:none;">
      <div class="a-bar">
        <label>帳款月份</label><input type="month" id="lFrom"> ~ <input type="month" id="lTo">
        <select id="lSide"><option value="">應收＋應付</option><option value="ar">客戶（應收）</option><option value="ap">廠商（應付）</option></select>
        <select id="lStatus"><option value="">全部狀態</option><option value="open">有效</option><option value="void">已作廢</option></select>
        <label><input type="checkbox" id="lNoNo"> 只看尚未填傳票號碼</label>
        <input type="text" id="lKw" placeholder="傳票號碼／對象／單號／料號／發票號碼" style="width:260px;">
        <button id="btnLLoad" class="btn-warm"><i class="fa fa-search"></i> 查詢</button>
      </div>
      <div class="a-pager"><span id="lInfo" style="margin-right:auto;color:#8a6d45;"></span>
        每頁 <select id="lPer"><option>5</option><option>10</option><option selected>20</option><option>50</option></select> 筆 <span id="lPg"></span></div>
      <div class="a-wrap"><table class="a-t"><thead><tr><th>傳票號碼</th><th>傳票日期</th><th>類別</th><th>對象</th><th>帳款月份</th>
        <th>明細</th><th>金額</th><th>狀態</th><th>備註</th><th>操作</th></tr></thead><tbody id="lBody"></tbody></table></div>
    </div>
<?php endif; ?>
  </div>
</div>
</div>

<div class="a-mask" id="mkV"><div class="a-modal">
  <div class="m-head"><i class="fa fa-book"></i>&nbsp;<span id="mvTitle">傳票</span><span class="m-close" data-close="mkV">✕</span></div>
  <div class="m-body" id="mvBody"></div>
  <div class="m-foot" id="mvFoot"></div>
</div></div>
<div class="a-mask" id="mkHelp"><div class="a-modal narrow">
  <div class="m-head"><i class="fa fa-question-circle"></i>&nbsp;使用說明<span class="m-close" data-close="mkHelp">✕</span></div>
  <div class="m-body help-doc">
    <h5>流程</h5>開完發票後：選對象與帳款月份 → 勾選要請款的出貨單／退貨單（廠商＝移轉憑單）→ 設定傳票日期 → 開立傳票。ERP 開出傳票後，把 ERP 傳票號碼填進來（可建立時填、也可事後到「傳票查詢」補填）。
    <h5>鎖定</h5>明細只要掛在有效傳票上，就視為「已做帳（請款）」：金額、數量、料號鎖定，不會再被 ERP 對帳單匯入等功能修改，也不能重複開傳票。作廢傳票（限會計管理員、需填原因）會釋放明細。
    <h5>查詢</h5>傳票號碼、對象、出貨／退貨／移轉單號、料號、<b>發票號碼</b>都可以搜尋；也可只列出尚未填傳票號碼的。
    <h5>權限</h5>檢閱：查詢。登錄：開立、修改傳票號碼／日期／備註。管理員：作廢。
  </div>
</div></div>
<div class="a-msg" id="msg"></div>

<script src="../../resource/js/jquery.min.js"></script>
<script src="../../resource/js/bootstrap.min.js"></script>
<script src="../../resource/js/fastclick.js"></script>
<script src="../../resource/js/nprogress.js"></script>
<script src="../../resource/js/custom.min.js"></script>
<script src="../../resource/js/eg_input_rules.js?v=<?= @filemtime(__DIR__.'/../../resource/js/eg_input_rules.js') ?: time() ?>"></script>
<script>
$(document).ready(function () { $('#sidebar-menu').css('visibility', 'visible'); });
(function ($) {
'use strict';
var API='../../src/store/Acc_API.php', CSRF='', CAN_EDIT=<?= $perms['canEdit']?'true':'false' ?>, CAN_ADMIN=<?= $perms['canAdmin']?'true':'false' ?>;
var items=[], SEL={}, lPage=1;
function esc(s){ return String(s==null?'':s).replace(/[&<>"']/g,function(c){return {'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#39;'}[c];}); }
function nf(n){ return Math.round(Number(n)||0).toLocaleString('en-US'); }
function np(n){ return String(parseFloat(Number(n||0).toFixed(4))); }
function dd(s){ return s?String(s).replace(/-/g,'.'):''; }
function toast(m,bad){ var $m=$('#msg').removeClass('ok bad').addClass(bad?'bad':'ok').html(m).stop(true,true).fadeIn(150);
  clearTimeout($m.data('t')); $m.data('t',setTimeout(function(){ $m.fadeOut(400); },bad?8000:4000)); }
function openMask(id){ $('#'+id).addClass('show'); } function closeMask(id){ $('#'+id).removeClass('show'); }
$(document).on('click','[data-close]',function(){ closeMask($(this).data('close')); });
$(document).on('click','.a-mask',function(e){ if(e.target===this) $(this).removeClass('show'); });
$('#btnHelp').on('click',function(){ openMask('mkHelp'); });
function failMsg(x,d){ var m=d||'操作失敗'; try{ m=JSON.parse(x.responseText).error||m; }catch(e){} toast(esc(m), true); }

$.getJSON(API,{action:'meta'},function(r){ if(r.ok) CSRF=r.csrf; });
(function(){
  var d=new Date(); d.setDate(1); d.setMonth(d.getMonth()-1);
  var m=d.getFullYear()+'-'+('0'+(d.getMonth()+1)).slice(-2);
  $('#vBm').val(m);   // 查詢頁的帳款月份預設留空＝不限，避免剛開的傳票因為月份不同查不到
  var t=new Date(); $('#vDate').val(t.getFullYear()+'-'+('0'+(t.getMonth()+1)).slice(-2)+'-'+('0'+t.getDate()).slice(-2));
})();
$('.tab-btn').on('click',function(){
  var t=$(this).data('tab'); $('.tab-btn').removeClass('on'); $(this).addClass('on');
  $('#tabNew').toggle(t==='new'); $('#tabList').toggle(t==='list');
  if(t==='list') loadList();
});

/* ── 開立 ── */
function loadParties(){
  var side=$('#vSide').val(), bm=$('#vBm').val();
  if(!bm){ toast('請選擇帳款月份', true); return; }
  $('#vParty').html('<option value="">載入中…</option>');
  $.post(API+'?action=vch_parties',{side:side,bm:bm},function(r){
    if(!r.ok){ toast(esc(r.error||'載入失敗'), true); return; }
    var h='<option value="">（選擇'+(side==='ar'?'客戶':'廠商')+'，共 '+r.parties.length+' 家）</option>';
    r.parties.forEach(function(p){ h+='<option value="'+esc(p.id)+'">'+esc(p.name)+'　'+esc(p.code||'')+'　'+nf(p.amount)+' 元／'+p.cnt+'筆</option>'; });
    $('#vParty').html(h);
  },'json').fail(function(x){ failMsg(x,'載入失敗'); });
}
$('#btnVParties').on('click',loadParties);
$('#vSide,#vBm').on('change',function(){ $('#vBox').hide(); loadParties(); });
function loadItems(){
  var pid=$('#vParty').val(); if(!pid){ toast('請先選擇客戶／廠商', true); return; }
  $.post(API+'?action=vch_candidates',{side:$('#vSide').val(),party_id:pid,bm:$('#vBm').val()},function(r){
    if(!r.ok){ toast(esc(r.error||'查詢失敗'), true); return; }
    items=r.items||[]; SEL={}; $('#vBox').show();
    var lk=items.filter(function(i){return i.locked;}).length;
    $('#vInfo').html('<div class="info"><b>'+esc(r.party_name)+'</b>　共 '+items.length+' 筆明細'+(lk?'，其中 '+lk+' 筆已有傳票（灰底，不可重複開立）':'')+'。</div>');
    renderItems();
  },'json').fail(function(x){ failMsg(x,'查詢失敗'); });
}
$('#btnVLoad').on('click',loadItems);
function renderItems(){
  var h='';
  if(!items.length) h='<tr><td colspan="11" style="padding:20px;color:#8a6d45;">此對象在該帳款月份沒有明細</td></tr>';
  items.forEach(function(it,i){
    var ret=(it.src_type==='IR');
    h+='<tr data-i="'+i+'" class="'+(it.locked?'lockedrow ':'')+(SEL[i]?'on':'')+'"'+(ret?' style="color:#DD5138;"':'')+'>'
      +'<td>'+(it.locked||!CAN_EDIT?'':'<input type="checkbox" class="vck"'+(SEL[i]?' checked':'')+'>')+'</td>'
      +'<td>'+(ret?'退貨':(it.src_type==='IS'?'出貨':esc(it.src_type)))+'</td><td>'+esc(it.src_no)+'</td><td>'+dd(it.src_date)+'</td>'
      +'<td class="l">'+esc(it.product_id)+'</td><td class="l">'+esc((it.spec||'').substr(0,26))+'</td>'
      +'<td class="r">'+nf(it.qty)+'</td><td class="r">'+np(it.unit_price)+'</td><td class="r">'+nf(it.amount)+'</td>'
      +'<td>'+esc(it.invoice_no||(it.invoiced?'已開（未回填號碼）':''))+'</td>'
      +'<td>'+(it.locked?'<span class="lk"><i class="fa fa-lock"></i> '+esc(it.voucher_no||'(未填號)')+'</span>':'')+'</td></tr>';
  });
  $('#vBody').html(h); updSel();
}
function updSel(){
  var k=Object.keys(SEL), amt=0; k.forEach(function(i){ amt+=Number(items[i].amount)||0; });
  $('#vSelN').text(k.length); $('#vSelAmt').text(nf(amt)); $('#btnVCreate').prop('disabled',!k.length);
}
$(document).on('change','.vck',function(){
  var i=$(this).closest('tr').data('i'); if(this.checked) SEL[i]=1; else delete SEL[i];
  $(this).closest('tr').toggleClass('on',this.checked); updSel();
});
$('#vCkAll,#btnVAll').on('click change',function(){
  if(this.id==='vCkAll' && this.type!=='checkbox') return;
  var on=(this.id==='vCkAll')?this.checked:true;
  items.forEach(function(it,i){ if(!it.locked){ if(on) SEL[i]=1; else delete SEL[i]; } }); renderItems();
});
$('#btnVNone').on('click',function(){ SEL={}; $('#vCkAll').prop('checked',false); renderItems(); });
$('#btnVCreate').on('click',function(){
  var k=Object.keys(SEL); if(!k.length) return;
  var d=$('#vDate').val(); if(!d){ toast('請填傳票日期', true); return; }
  var sel=k.map(function(i){ return {src_type:items[i].src_type, src_id:items[i].src_id}; });
  if(!confirm('開立傳票？共 '+k.length+' 筆，合計 '+$('#vSelAmt').text()+' 元。\n開立後這些明細的金額／數量／料號會鎖定。')) return;
  var $b=$(this).prop('disabled',true);
  $.post(API+'?action=vch_create',{csrf:CSRF,side:$('#vSide').val(),party_id:$('#vParty').val(),bm:$('#vBm').val(),
    voucher_date:d,voucher_no:$('#vNo').val(),note:$('#vNote').val(),sel:JSON.stringify(sel)},function(r){
    $b.prop('disabled',false);
    if(!r.ok){ toast(esc(r.error||'開立失敗'), true); return; }
    toast('<b>'+esc(r.message)+'</b>'); $('#vNo').val(''); $('#vNote').val(''); loadItems();
  },'json').fail(function(x){ $b.prop('disabled',false); failMsg(x,'開立失敗'); });
});

/* ── 查詢 ── */
function loadList(){
  $('#lBody').html('<tr><td colspan="10" style="padding:18px;color:#8a6d45;">查詢中…</td></tr>');
  $.post(API+'?action=vch_list',{bm_from:$('#lFrom').val(),bm_to:$('#lTo').val(),side:$('#lSide').val(),status:$('#lStatus').val(),
    no_number:$('#lNoNo').is(':checked')?'1':'',kw:$('#lKw').val(),page:lPage,per_page:$('#lPer').val()},function(r){
    if(!r.ok){ toast(esc(r.error||'查詢失敗'), true); return; }
    var h='';
    if(!r.rows.length) h='<tr><td colspan="10" style="padding:18px;color:#8a6d45;">沒有符合的傳票</td></tr>';
    r.rows.forEach(function(v){
      h+='<tr class="'+(v.status==='void'?'voided':'')+'"><td><b>'+esc(v.voucher_no||'')+'</b>'+(v.voucher_no?'':'<span class="lk">（未填）</span>')+'</td>'
        +'<td>'+dd(v.voucher_date)+'</td><td>'+(v.side==='ap'?'應付':'應收')+'</td><td class="l">'+esc(v.party_name)+'</td>'
        +'<td>'+esc(v.billing_month||'')+'</td><td class="r">'+nf(v.item_cnt)+'</td><td class="r">'+nf(v.total_amount)+'</td>'
        +'<td><span class="pill st-'+v.status+'">'+(v.status==='void'?'已作廢':'有效')+'</span></td><td class="l">'+esc(v.note||'')+'</td>'
        +'<td><button class="btn-mini v-open" data-id="'+v.voucher_id+'">檢視／編輯</button></td></tr>';
    });
    $('#lBody').html(h);
    var pages=Math.max(1,Math.ceil(r.total/r.per_page)); $('#lInfo').text('共 '+r.total+' 張');
    var pg=''; for(var p=Math.max(1,r.page-2);p<=Math.min(pages,r.page+2);p++) pg+='<button class="'+(p===r.page?'on':'')+'" data-p="'+p+'">'+p+'</button>';
    $('#lPg').html(pg);
  },'json').fail(function(x){ failMsg(x,'查詢失敗'); });
}
$('#btnLLoad').on('click',function(){ lPage=1; loadList(); });
$('#lPer,#lSide,#lStatus,#lNoNo').on('change',function(){ lPage=1; loadList(); });
$('#lKw').on('keydown',function(e){ if(e.which===13){ lPage=1; loadList(); } });
$(document).on('click','#lPg button',function(){ lPage=+$(this).data('p'); loadList(); });
$(document).on('click','.v-open',function(){
  var id=$(this).data('id');
  $.post(API+'?action=vch_get',{voucher_id:id},function(r){
    if(!r.ok){ toast(esc(r.error||'讀取失敗'), true); return; }
    var v=r.voucher, void_=(v.status==='void'), ed=CAN_EDIT&&!void_;
    $('#mvTitle').text('傳票 '+(v.voucher_no||'(未填號碼)')+'　'+v.party_name+'　'+v.billing_month);
    var h='<div class="a-bar"><label>傳票號碼</label><input type="text" id="evNo" class="vno-in" maxlength="40" value="'+esc(v.voucher_no||'')+'"'+(ed?'':' disabled')+'>'
      +'<label>傳票日期</label><input type="date" id="evDate" value="'+esc(v.voucher_date)+'"'+(ed?'':' disabled')+'>'
      +'<label>備註</label><input type="text" id="evNote" maxlength="200" style="width:240px;" value="'+esc(v.note||'')+'"'+(ed?'':' disabled')+'></div>'
      +(void_?'<div class="warn">此傳票已作廢：'+esc(v.void_reason||'')+'</div>':'')
      +'<table class="a-t"><thead><tr><th>類型</th><th>單號</th><th>日期</th><th>料號</th><th>品名規格</th><th>數量</th><th>單價</th><th>金額</th><th>發票</th></tr></thead><tbody>';
    r.items.forEach(function(it){ h+='<tr><td>'+esc(it.src_type)+'</td><td>'+esc(it.src_no)+'</td><td>'+dd(it.src_date)+'</td><td class="l">'+esc(it.product_id)+'</td>'
      +'<td class="l">'+esc((it.spec||'').substr(0,26))+'</td><td class="r">'+nf(it.qty)+'</td><td class="r">'+np(it.unit_price)+'</td><td class="r">'+nf(it.amount)+'</td><td>'+esc(it.invoice_no||'')+'</td></tr>'; });
    h+='</tbody></table><div style="text-align:right;margin-top:6px;">合計 <b>'+nf(v.total_amount)+'</b></div>';
    $('#mvBody').html(h);
    var f='<button data-close="mkV">關閉</button>';
    if(ed) f+='<button class="go" id="btnVSave" data-id="'+id+'"><i class="fa fa-check"></i> 儲存</button>';
    if(CAN_ADMIN&&!void_) f+='<button id="btnVVoid" data-id="'+id+'" style="color:#DD5138;">作廢傳票</button>';
    $('#mvFoot').html(f); openMask('mkV');
  },'json').fail(function(x){ failMsg(x,'讀取失敗'); });
});
$(document).on('click','#btnVSave',function(){
  var id=$(this).data('id'), d=$('#evDate').val(); if(!d){ toast('請填傳票日期', true); return; }
  $.post(API+'?action=vch_update',{csrf:CSRF,voucher_id:id,voucher_no:$('#evNo').val(),voucher_date:d,note:$('#evNote').val()},function(r){
    if(!r.ok){ toast(esc(r.error||'儲存失敗'), true); return; }
    toast('已儲存'); closeMask('mkV'); loadList();
  },'json').fail(function(x){ failMsg(x,'儲存失敗'); });
});
$(document).on('click','#btnVVoid',function(){
  var id=$(this).data('id'), why=prompt('作廢原因（必填）：'); if(why===null) return;
  if(!why.trim()){ toast('請填寫作廢原因', true); return; }
  $.post(API+'?action=vch_void',{csrf:CSRF,voucher_id:id,reason:why},function(r){
    if(!r.ok){ toast(esc(r.error||'作廢失敗'), true); return; }
    toast(esc(r.message)); closeMask('mkV'); loadList();
  },'json').fail(function(x){ failMsg(x,'作廢失敗'); });
});
})(jQuery);
</script>
</body>
</html>
