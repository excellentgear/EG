<?php
/**
 * views/ACC/_acc_tools.php — 會計頁面共用工具（單據快搜 + 帳款月份調整）
 *
 * 由 ar_statement.php / ap_statement.php include。兩頁共用同一份，避免各寫一套走鐘。
 * 使用方式：
 *   1) include 本檔（放在 </body> 之前、載完 jQuery 之後的 <script> 之前）
 *   2) 在頁面自己的 script 內呼叫：
 *        AccTools.init({ side:'ar'|'ap', api:API, csrf:function(){return CSRF;}, onChanged:load });
 *   3) 綁按鈕：AccTools.openLookup() / AccTools.openMonth()
 *
 * 兩個工具的用途：
 *  - 單據快搜：客戶或廠商拿紙本單據／對帳單來時，用上面任何一個號碼、金額、料號
 *    就能跨出貨／退貨／發票／折讓／收款／加工移轉單找出來，並直接顯示屬於哪個帳款月份。
 *  - 帳款月份調整：出貨或收貨日超過結帳日時系統會自動歸下個月，但對方可能認定算本月；
 *    這裡可人工指定（留空＝恢復自動計算），可單筆或批次。
 *    應收側已開過發票的憑證會鎖住不可改（金額已在發票上）。
 */
$ACC_TOOL_SIDE = $ACC_TOOL_SIDE ?? 'ar';
?>
<style>
/* 共用工具的樣式；顏色一律吃頁面自己的 CSS 變數，所以應收/應付會各自呈現該頁色系 */
.at-mask{display:none;position:fixed;top:0;left:0;right:0;bottom:0;width:100%;height:100%;
  background:rgba(60,40,20,.5);z-index:10500;overflow:auto;padding:30px 12px;}
.at-mask.show{display:block;}
.at-modal{background:#fff;border-radius:8px;width:1120px;max-width:100%;margin:0 auto;
  box-shadow:0 6px 34px rgba(0,0,0,.34);}
.at-modal .m-head{background:var(--a-acc,#F0A24B);color:#fff;padding:10px 14px;font-weight:bold;
  border-radius:8px 8px 0 0;display:flex;align-items:center;font-size:15px;}
.at-modal .m-close{margin-left:auto;cursor:pointer;font-size:18px;}
.at-modal .m-body{padding:14px;max-height:70vh;overflow:auto;}
.at-modal .m-foot{padding:10px 14px;border-top:1px solid var(--a-line,#E8D5B5);text-align:right;}
.at-modal .m-foot button{height:32px;padding:0 14px;border:1px solid var(--a-line2,#D8BE93);
  border-radius:4px;background:#fff;cursor:pointer;color:var(--a-ink,#5b3a1e);margin-left:5px;}
.at-modal .m-foot button.go{background:var(--a-acc,#F0A24B);color:#fff;
  border-color:var(--a-acc-d,#d98a33);font-weight:bold;}
.at-modal .m-foot button:disabled{opacity:.45;cursor:default;}

.at-bar{display:flex;flex-wrap:wrap;gap:6px;align-items:center;
  border:1.5px solid var(--a-line,#E8D5B5);border-radius:8px;padding:8px 10px;margin-bottom:8px;
  background:var(--a-bg,#FDF8EF);}
.at-bar label{margin:0;font-size:13px;color:var(--a-ink,#5b3a1e);font-weight:normal;}
.at-bar input[type=text],.at-bar input[type=date],.at-bar input[type=month],.at-bar select,.at-bar button{
  height:32px;font-size:13px;line-height:1;padding:0 10px;border:1px solid var(--a-line2,#D8BE93);
  border-radius:4px;background:#fff;color:var(--a-ink,#5b3a1e);}
.at-bar button{cursor:pointer;}
.at-bar button:hover{background:var(--a-ok,#F7E0BD);}
.at-bar .btn-warm{background:var(--a-acc,#F0A24B);color:#fff;border-color:var(--a-acc-d,#d98a33);font-weight:bold;}
#atKw{width:300px;font-size:15px;font-weight:bold;}
#atKw:focus{border-color:var(--a-acc,#F0A24B);box-shadow:0 0 0 2px rgba(240,162,75,.3);outline:none;}

table.at-t{width:100%;border-collapse:collapse;font-size:13px;}
table.at-t th,table.at-t td{border:1px solid #EADFC8;padding:4px 7px;white-space:nowrap;text-align:center;}
table.at-t thead th{background:var(--a-ok,#F7E0BD);color:var(--a-ink,#5b3a1e);font-weight:bold;}
table.at-t td.l{text-align:left;}
table.at-t td.r{text-align:right;}
table.at-t tbody tr:nth-child(even){background:#FFFCF6;}
table.at-t tbody tr.hit{background:#FCEBD2 !important;}
table.at-t tbody tr.locked{color:#a08a6a;}
.at-wrap{overflow-x:auto;border:1px solid var(--a-line,#E8D5B5);border-radius:6px;background:#fff;margin-bottom:10px;}

.at-grp{font-size:13.5px;font-weight:bold;color:var(--a-brand,#8A5A2B);margin:10px 0 4px;
  padding-left:8px;border-left:4px solid var(--a-acc,#F0A24B);}
.at-pill{display:inline-block;padding:1px 8px;border-radius:9px;font-size:11px;line-height:17px;}
.at-ovr{background:var(--a-acc,#F0A24B);color:#fff;}
.at-auto{background:#EFE6D6;color:#8a6d45;}
.at-lock{background:#C9B69F;color:#fff;}
.at-inv{background:#8A5A2B;color:#fff;}
.at-amt{background:#DD5138;color:#fff;}
.at-info{background:var(--a-bg2,#FFF7E8);border-left:5px solid var(--a-acc,#F0A24B);
  color:var(--a-ink,#5b3a1e);padding:8px 12px;border-radius:4px;font-size:13px;
  margin-bottom:8px;line-height:1.65;}
.at-warn{background:#FBE3DC;color:#7a2c17;border-left:5px solid #DD5138;
  padding:8px 12px;border-radius:4px;font-size:13px;margin-bottom:8px;}
.at-mth-in{width:118px;height:25px;border:1px solid var(--a-line2,#D8BE93);border-radius:4px;
  font-size:12.5px;color:var(--a-ink,#5b3a1e);}
.at-hint{font-size:11.5px;color:var(--a-ink2,#8a6d45);line-height:1.6;}
kbd{background:#f4e6ce;border:1px solid var(--a-line2,#D8BE93);border-bottom-width:2px;
  border-radius:3px;padding:0 5px;font-size:11px;color:var(--a-ink,#5b3a1e);font-family:inherit;}
</style>

<!-- ══ 單據快搜 ══ -->
<div class="at-mask" id="atLookup"><div class="at-modal">
  <div class="m-head"><i class="fa fa-search"></i>&nbsp;單據快搜
    <span style="font-weight:normal;font-size:13px;opacity:.9;">客戶／廠商拿紙本來時，用上面任一號碼或金額查</span>
    <span class="m-close" data-atclose="atLookup">✕</span></div>
  <div class="m-body">
    <div class="at-info">
      輸入紙本上<b>任何一項</b>：出貨單號、退貨單號、發票號碼、加工移轉單號、料號、客戶／廠商名稱，
      或<b>直接輸入金額</b>（會做 ±0.5% 容差比對）。系統會跨出貨／退貨／發票／折讓／收款／加工移轉單全找一遍，
      並直接告訴你每一筆<b>算在哪個帳款月份</b>，不必自己翻月份猜。
    </div>
    <div class="at-bar">
      <input type="text" id="atKw" placeholder="單號／金額／料號／客戶或廠商名稱">
      <button id="atKwGo" class="btn-warm"><i class="fa fa-search"></i> 查詢</button>
      <button id="atKwCsv"><i class="fa fa-file-text-o"></i> 匯出結果</button>
      <span id="atKwSum" style="font-size:13px;color:var(--a-ink,#5b3a1e);margin-left:6px;"></span>
    </div>
    <div id="atKwBox"></div>
  </div>
  <div class="m-foot"><button data-atclose="atLookup">關閉</button></div>
</div></div>

<!-- ══ 帳款月份調整 ══ -->
<div class="at-mask" id="atMonth"><div class="at-modal">
  <div class="m-head"><i class="fa fa-calendar"></i>&nbsp;<span id="atMthTitle">帳款月份調整</span>
    <span class="m-close" data-atclose="atMonth">✕</span></div>
  <div class="m-body">
    <div class="at-info" id="atMthNote"></div>
    <div class="at-bar">
      <input type="text" id="atMthKw" placeholder="單號／料號／客戶或廠商" style="width:200px;">
      <label>單據日期</label>
      <input type="date" id="atMthFrom" style="width:145px;"> ~ <input type="date" id="atMthTo" style="width:145px;">
      <label>現行帳款月</label>
      <input type="month" id="atMthBm" style="width:140px;">
      <label><input type="checkbox" id="atMthOnlyOvr"> 只看已人工指定的</label>
      <button id="atMthGo" class="btn-warm"><i class="fa fa-search"></i> 查詢</button>
    </div>
    <div class="at-bar" style="background:var(--a-bg2,#FFF7E8);">
      <button id="atMthAll">全選可調整</button>
      <button id="atMthNone">清除選取</button>
      <label style="margin-left:10px;">批次改為</label>
      <input type="month" id="atMthTarget" style="width:145px;">
      <button id="atMthApply" class="btn-warm" disabled><i class="fa fa-check"></i> 套用到勾選的單據</button>
      <button id="atMthReset" disabled title="清除人工指定，回到依結帳日自動計算">恢復自動計算</button>
      <span id="atMthSum" style="margin-left:auto;font-size:13px;color:var(--a-ink,#5b3a1e);"></span>
    </div>
    <div id="atMthBox"></div>
  </div>
  <div class="m-foot"><button data-atclose="atMonth">關閉</button></div>
</div></div>

<script>
/* 會計共用工具：單據快搜 + 帳款月份調整。應收/應付兩頁共用同一份實作。 */
var AccTools = (function ($) {
'use strict';
var SIDE='ar', API='', getCsrf=function(){return '';}, onChanged=null;
var lookupData=null, mthRows=[], mthSel={};

var KIND_LABEL={ship:'出貨單', 'return':'退貨單', invoice:'發票', allowance:'折讓單',
                receipt:'收款單', process:'加工移轉單'};
var GRP_ORDER=[['ship','出貨單'],['return','退貨單'],['invoice','發票／折讓單'],
               ['receipt','收款單'],['process','加工移轉單（應付）']];
var ST_LABEL={draft:'草稿', exported:'已轉出', issued:'已開立', 'void':'已作廢'};

function esc(s){ return String(s==null?'':s).replace(/[&<>"']/g,function(c){
  return {'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#39;'}[c]; }); }
function nf(n){ return Math.round(Number(n)||0).toLocaleString('en-US'); }
function toast(m,bad){
  var $m=$('#msg');
  if(!$m.length){ if(bad) alert(m.replace(/<[^>]+>/g,'')); return; }
  $m.removeClass('ok bad').addClass(bad?'bad':'ok').html(m).stop(true,true).fadeIn(150);
  clearTimeout($m.data('t')); $m.data('t',setTimeout(function(){ $m.fadeOut(400); },bad?7000:3800));
}
function open(id){ $('#'+id).addClass('show'); }
function close(id){ $('#'+id).removeClass('show'); }
$(document).on('click','[data-atclose]',function(){ close($(this).data('atclose')); });
$(document).on('click','.at-mask',function(e){ if(e.target===this) $(this).removeClass('show'); });

/* ── 單據快搜 ── */
function doLookup(){
  var kw=$.trim($('#atKw').val());
  if(!kw){ toast('請輸入要查的內容', true); $('#atKw').focus(); return; }
  $('#atKwBox').html('<div style="padding:16px;color:#8a6d45;">查詢中…</div>');
  $.post(API+'?action=doc_lookup',{kw:kw},function(r){
    if(!r.ok){ toast(esc(r.error||'查詢失敗'), true); return; }
    lookupData=r;
    var s=r.summary||{};
    $('#atKwSum').html('共 <b>'+nf(s.total)+'</b> 筆　'
      +'出貨 '+nf(s.ship)+'　退貨 '+nf(s.return)+'　發票 '+nf(s.invoice)
      +'　收款 '+nf(s.receipt)+'　加工 '+nf(s.process)
      +(r.is_amount_search?'　<span class="at-pill at-amt">含金額比對</span>':''));
    renderLookup(r);
  },'json').fail(function(){ toast('查詢失敗', true); });
}

function renderLookup(r){
  var g=r.groups||{}, kwNum=null, h='';
  if(r.is_amount_search) kwNum=parseFloat(String(r.keyword).replace(/,/g,''));
  var any=false;

  GRP_ORDER.forEach(function(pair){
    var key=pair[0], list=g[key]||[];
    if(key==='invoice' && g.allowance) list=list.concat(g.allowance);
    if(!list.length) return;
    any=true;
    var cut=(r.truncated||{})[key];
    h+='<div class="at-grp">'+esc(pair[1])+'（'+list.length+'）'
      +(cut?' <span class="at-pill at-amt" style="font-weight:normal;">只顯示前 '+(r.limit||60)
            +' 筆，請縮小關鍵字</span>':'')
      +'</div><div class="at-wrap"><table class="at-t">';
    if(key==='receipt'){
      h+='<thead><tr><th>收款單號</th><th>入帳日</th><th>客戶</th><th>方式</th>'
        +'<th>收款金額</th><th>已沖帳</th><th>暫收款</th></tr></thead><tbody>';
      list.forEach(function(x){
        var hit=(kwNum!==null && Math.abs(Math.abs(x.amount)-kwNum)<=Math.max(1,kwNum*0.005));
        h+='<tr'+(hit?' class="hit"':'')+'><td><b>'+esc(x.no)+'</b></td><td>'+esc(x.date||'')+'</td>'
          +'<td class="l">'+esc(x.party)+'</td><td>'+esc(x.method||'')+'</td>'
          +'<td class="r"><b>'+nf(x.amount)+'</b></td><td class="r">'+nf(x.allocated)+'</td>'
          +'<td class="r">'+(x.open>0.005?'<b style="color:#DD5138;">'+nf(x.open)+'</b>':'')+'</td></tr>';
      });
    } else if(key==='invoice'){
      h+='<thead><tr><th>類型</th><th>發票號碼</th><th>開立日</th><th>客戶</th><th>統編</th>'
        +'<th>帳款月份</th><th>狀態</th><th>含稅金額</th><th>已收</th><th>未收</th></tr></thead><tbody>';
      list.forEach(function(x){
        var hit=(kwNum!==null && Math.abs(Math.abs(x.amount)-kwNum)<=Math.max(1,kwNum*0.005));
        h+='<tr'+(hit?' class="hit"':'')+'>'
          +'<td>'+(x.kind==='allowance'?'<span class="at-pill at-amt">折讓</span>':'發票')+'</td>'
          +'<td><b>'+esc(x.no)+'</b></td><td>'+esc(x.date||'')+'</td>'
          +'<td class="l">'+esc(x.party)+'</td><td>'+esc(x.tax_id||'')+'</td>'
          +'<td><b>'+esc(x.billing_month)+'</b></td>'
          +'<td>'+esc(ST_LABEL[x.status]||x.status)+'</td>'
          +'<td class="r"><b>'+nf(x.amount)+'</b></td><td class="r">'+nf(x.paid)+'</td>'
          +'<td class="r">'+(x.open>0.005?'<b style="color:#DD5138;">'+nf(x.open)+'</b>':'')+'</td></tr>';
      });
    } else {
      var isProc=(key==='process');
      h+='<thead><tr><th>單號</th><th>日期</th><th>'+(isProc?'廠商':'客戶')+'</th>'
        +(isProc?'<th>製令</th>':'')+'<th>料號</th><th>數量</th><th>單價</th><th>金額</th>'
        +'<th>帳款月份</th>'+(isProc?'<th>廠商發票日</th>':'<th>已開發票</th>')+'</tr></thead><tbody>';
      list.forEach(function(x){
        var hit=(kwNum!==null && Math.abs(Math.abs(x.amount)-kwNum)<=Math.max(1,kwNum*0.005));
        h+='<tr'+(hit?' class="hit"':'')+'><td><b>'+esc(x.no)+'</b></td><td>'+esc(x.date||'')+'</td>'
          +'<td class="l">'+esc(x.party)+'</td>'
          +(isProc?'<td>'+esc(x.bom||'')+'</td>':'')
          +'<td class="l">'+esc(x.product_id||'')+'</td>'
          +'<td class="r">'+nf(x.qty)+'</td><td class="r">'+esc(String(x.unit_price||''))+'</td>'
          +'<td class="r"><b>'+nf(x.amount)+'</b></td>'
          +'<td><b>'+esc(x.billing_month)+'</b>'
            +(x.overridden?' <span class="at-pill at-ovr">人工</span>':'')+'</td>'
          +(isProc?'<td>'+esc(x.inv_date||'—')+'</td>'
                 :'<td>'+(x.invoiced?'<span class="at-pill at-inv">'+esc(x.invoiced)+'</span>':'—')+'</td>')
          +'</tr>';
      });
    }
    h+='</tbody></table></div>';
  });

  if(!any) h='<div class="at-warn">查不到任何符合的單據。試試只輸入單號的一部分、或直接輸入金額。</div>';
  else h='<div class="at-hint" style="margin-bottom:6px;">'
        +'底色標示 <span class="hit" style="padding:1px 8px;border:1px solid #EADFC8;">這樣</span> 的列＝金額與你輸入的數字相符。'
        +'「帳款月份」就是這筆會出現在哪一期對帳單上。</div>'+h;
  $('#atKwBox').html(h);
}

/* ── 帳款月份調整 ── */
function mthFilters(){
  return {side:SIDE, kw:$('#atMthKw').val(), date_from:$('#atMthFrom').val(),
          date_to:$('#atMthTo').val(), billing_month:$('#atMthBm').val(),
          only_override:$('#atMthOnlyOvr').is(':checked')?1:0};
}
function doMthSearch(){
  $('#atMthBox').html('<div style="padding:16px;color:#8a6d45;">查詢中…</div>');
  mthSel={};
  $.post(API+'?action=billing_search', mthFilters(), function(r){
    if(!r.ok){ toast(esc(r.error||'查詢失敗'), true); return; }
    mthRows=r.rows||[];
    var s=r.summary||{};
    $('#atMthSum').html('共 <b>'+nf(s.count)+'</b> 筆　金額 <b>'+nf(s.amount)+'</b>'
      +'　已人工指定 <b>'+nf(s.overridden)+'</b>'
      +(s.locked?'　已開發票鎖定 <b style="color:#DD5138;">'+nf(s.locked)+'</b>':''));
    renderMth();
  },'json').fail(function(){ toast('查詢失敗', true); });
}

function renderMth(){
  if(!mthRows.length){
    $('#atMthBox').html('<div class="at-warn">查不到符合條件的單據。</div>');
    updateMthSel(); return;
  }
  var isAp=(SIDE==='ap');
  var h='<div class="at-wrap"><table class="at-t"><thead><tr>'
    +'<th style="width:32px;"><input type="checkbox" id="atMthChkAll"></th>'
    +'<th>類型</th><th>單號</th><th>單據日期</th><th>'+(isAp?'廠商':'客戶')+'</th>'
    +'<th>料號</th><th>數量</th><th>金額</th>'
    +'<th>依結帳日自動</th><th>目前帳款月份</th><th>指定為</th>'
    +(isAp?'':'<th>已開發票</th>')+'</tr></thead><tbody>';
  mthRows.forEach(function(x,i){
    var k=x.src_type+'-'+x.id;
    var lock=!!x.locked;
    h+='<tr data-i="'+i+'" class="'+(mthSel[k]?'hit ':'')+(lock?'locked':'')+'">'
      +'<td><input type="checkbox" class="at-mth-ck"'+(mthSel[k]?' checked':'')+(lock?' disabled':'')+'></td>'
      +'<td>'+esc(KIND_LABEL[x.kind]||x.kind)+'</td>'
      +'<td><b>'+esc(x.no||'')+'</b></td><td>'+esc(x.date)+'</td>'
      +'<td class="l">'+esc(x.party||'')+'</td>'
      +'<td class="l">'+esc(x.product_id||'')+'</td>'
      +'<td class="r">'+nf(x.qty)+'</td><td class="r"><b>'+nf(x.amount)+'</b></td>'
      +'<td><span class="at-pill at-auto">'+esc(x.auto_month)+'</span></td>'
      +'<td><b>'+esc(x.billing_month)+'</b>'
        +(x.overridden?' <span class="at-pill at-ovr">人工</span>':'')+'</td>'
      +'<td>'+(lock?'<span class="at-pill at-lock">已鎖定</span>'
              :'<input type="month" class="at-mth-in" data-i="'+i+'" value="'+esc(x.override||'')+'">')+'</td>'
      +(isAp?'':'<td>'+(x.invoiced?'<span class="at-pill at-inv">'+esc(x.invoiced)+'</span>':'—')+'</td>')
      +'</tr>';
  });
  $('#atMthBox').html(h+'</tbody></table></div>'
    +'<div class="at-hint">「依結帳日自動」是系統按結帳日推算的月份；'
    +'「指定為」填了就以你填的為準，<b>清空即恢復自動計算</b>。改完會即時存檔。'
    + (SIDE==='ar' ? '已開立在發票上的單據會鎖住不可改——金額已經在發票上了，硬改會讓對帳單與發票對不起來，'
                   + '要改請先作廢該發票。' : '應付側改的是「廠商發票年月」。')+'</div>');
  updateMthSel();
}

$(document).on('change','.at-mth-ck',function(){
  var $tr=$(this).closest('tr'), x=mthRows[parseInt($tr.data('i'),10)];
  var k=x.src_type+'-'+x.id;
  if(this.checked) mthSel[k]=x; else delete mthSel[k];
  $tr.toggleClass('hit', this.checked);
  updateMthSel();
});
$(document).on('change','#atMthChkAll',function(){
  var on=this.checked;
  $('#atMthBox .at-mth-ck:not(:disabled)').prop('checked',on).each(function(){
    var $tr=$(this).closest('tr'), x=mthRows[parseInt($tr.data('i'),10)];
    var k=x.src_type+'-'+x.id;
    if(on) mthSel[k]=x; else delete mthSel[k];
    $tr.toggleClass('hit',on);
  });
  updateMthSel();
});
function updateMthSel(){
  var n=Object.keys(mthSel).length;
  $('#atMthApply,#atMthReset').prop('disabled', n===0);
  $('#atMthApply').html('<i class="fa fa-check"></i> 套用到勾選的 '+(n||'')+' 筆');
}

/* 單筆就地改 */
$(document).on('change','.at-mth-in',function(){
  var x=mthRows[parseInt($(this).data('i'),10)];
  setMonth([{src_type:x.src_type,id:x.id,no:x.no}], $(this).val()||'');
});

function setMonth(items, ym){
  var post={csrf:getCsrf(), ym:ym};
  if(items.length===1){
    post.src_type=items[0].src_type; post.id=items[0].id;
    $.post(API+'?action=billing_set', post, function(r){
      if(!r.ok){ toast(esc(r.error||'設定失敗'), true); doMthSearch(); return; }
      toast(esc(r.message)); doMthSearch();
      if(onChanged) onChanged();
    },'json').fail(function(x){
      var m='設定失敗'; try{ m=JSON.parse(x.responseText).error||m; }catch(e){}
      toast(esc(m), true); doMthSearch();
    });
  } else {
    post.items=JSON.stringify(items);
    $.post(API+'?action=billing_set_bulk', post, function(r){
      if(!r.ok){ toast(esc(r.error||'設定失敗'), true); return; }
      toast('<b>'+esc(r.message)+'</b>');
      if(r.errors && r.errors.length) toast(r.errors.map(esc).join('<br>'), true);
      mthSel={}; doMthSearch();
      if(onChanged) onChanged();
    },'json').fail(function(){ toast('批次設定失敗', true); });
  }
}

$(document).on('click','#atMthApply',function(){
  var ym=$('#atMthTarget').val();
  if(!ym){ toast('請先選擇要改成哪個帳款月份', true); $('#atMthTarget').focus(); return; }
  var items=Object.keys(mthSel).map(function(k){
    return {src_type:mthSel[k].src_type, id:mthSel[k].id, no:mthSel[k].no}; });
  if(!items.length) return;
  if(!confirm('把 '+items.length+' 筆單據的帳款月份指定為 '+ym+'？')) return;
  setMonth(items, ym);
});
$(document).on('click','#atMthReset',function(){
  var items=Object.keys(mthSel).map(function(k){
    return {src_type:mthSel[k].src_type, id:mthSel[k].id, no:mthSel[k].no}; });
  if(!items.length) return;
  if(!confirm('清除 '+items.length+' 筆單據的人工指定，恢復依結帳日自動計算？')) return;
  setMonth(items, '');
});
$(document).on('click','#atMthAll',function(){ $('#atMthChkAll').prop('checked',true).trigger('change'); });
$(document).on('click','#atMthNone',function(){
  $('#atMthChkAll').prop('checked',false).trigger('change'); mthSel={}; renderMth(); });

/* 事件綁定 */
$(document).on('click','#atKwGo', doLookup);
$(document).on('keydown','#atKw',function(e){ if(e.key==='Enter'){ e.preventDefault(); doLookup(); } });
$(document).on('click','#atKwCsv',function(){
  var kw=$.trim($('#atKw').val());
  if(!kw){ toast('請先查詢', true); return; }
  window.location = API+'?action=doc_lookup_export&kw='+encodeURIComponent(kw);
});
$(document).on('click','#atMthGo', doMthSearch);
$(document).on('keydown','#atMthKw',function(e){ if(e.key==='Enter'){ e.preventDefault(); doMthSearch(); } });
$(document).on('change','#atMthOnlyOvr,#atMthBm', doMthSearch);

return {
  init: function(o){
    SIDE=(o.side==='ap')?'ap':'ar';
    API=o.api; getCsrf=o.csrf||getCsrf; onChanged=o.onChanged||null;
    $('#atMthTitle').text(SIDE==='ap' ? '帳款月份調整（應付／廠商加工費）' : '帳款月份調整（應收／出貨與退貨）');
    $('#atMthNote').html(SIDE==='ap'
      ? '收貨或加工日期跨月時，廠商可能認定算另一個月的帳。這裡調整的是<b>廠商發票年月</b>'
        + '（bom_ing_transfer_log.invoice_ym），也就是應付對帳單的歸月依據。清空＝退回用加工日期所在月份。'
      : '出貨或退貨日期超過結帳日時，系統會自動歸到下個月，但客戶可能認定算本月帳。'
        + '這裡可以人工指定帳款月份（清空＝恢復依結帳日自動計算）。'
        + '<b>已開立在發票上的單據會鎖住不可改</b>，要改請先作廢該發票。');
    // 預設查最近兩個月，方便處理剛跨月的單
    var t=new Date(), f=new Date(); f.setMonth(f.getMonth()-2);
    $('#atMthTo').val(t.toISOString().slice(0,10));
    $('#atMthFrom').val(f.toISOString().slice(0,10));
  },
  openLookup: function(kw){
    $('#atKwBox').empty(); $('#atKwSum').empty();
    if(kw!==undefined) $('#atKw').val(kw);
    open('atLookup');
    setTimeout(function(){ $('#atKw').focus().select(); },80);
    if(kw) doLookup();
  },
  openMonth: function(){
    mthRows=[]; mthSel={}; $('#atMthBox').empty(); $('#atMthSum').empty();
    open('atMonth'); doMthSearch();
  }
};
})(jQuery);
</script>
