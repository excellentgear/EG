<?php
/**
 * views/ACC/erp_stmt_import.php — 匯入 ERP 客戶對帳單，以 ERP 為準更新網頁出貨明細
 *
 * 對應鍵：出貨單號 + 料號 + 數量（單價常被業務在對帳時改過，所以單價不是對應條件而是被更新的對象）。
 * 資料一律走 src/store/Acc_API.php（erp_stmt_upload / erp_stmt_apply），邏輯在 src/common/acc_erp_stmt_lib.php。
 */
session_start();
if (!isset($_SESSION['userName'])) {
    $_SESSION['lastpage'] = "../../views/ACC/erp_stmt_import.php";
    header("Location:../../index.php");
    exit;
}
include_once '../../src/common/_config.php';
include_once '../../src/common/DBConnection.php';
include_once '../../src/common/acc_lib.php';

$db = (new DBConnection())->getPDO();
acc_ensure_schema($db);
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
<title>匯入ERP對帳單</title>
<link href="../../resource/css/bootstrap.css" rel="stylesheet">
<link href="../../resource/css/font-awesome.css" rel="stylesheet">
<link href="../../resource/css/nprogress.css" rel="stylesheet">
<link href="../../resource/css/custom.css" rel="stylesheet">
<link href="_acc_pages.css?v=<?= @filemtime(__DIR__ . '/_acc_pages.css') ?: time() ?>" rel="stylesheet">
<style>
.st-same{background:#EFE6D6;color:#8a6d45;}
.st-price{background:var(--a-acc);color:#fff;}
.st-qty{background:var(--a-bad);color:#fff;}
.st-erp_only{background:#8A5A2B;color:#fff;}
.st-site_only{background:#C9B69F;color:#fff;}
.st-locked{background:#5b3a1e;color:#fff;}
.chg{color:var(--a-bad);font-weight:bold;}
.page-help-btn{margin-left:auto;height:30px;padding:0 12px;border:1px solid var(--a-line2);border-radius:15px;background:#fff;color:var(--a-ink);cursor:pointer;}
.help-doc{font-size:13.5px;line-height:1.9;color:var(--a-ink);}
.help-doc h5{margin:10px 0 2px;color:var(--a-brand);font-weight:bold;}
</style>
</head>
<body class="nav-sm">
<div class="container body">
<div class="main_container">
  <?php include '../partPage/sideAndTopBarMenu.html' ?>
  <div class="right_col" role="main">
    <div class="page-title" style="display:flex;align-items:center;flex-wrap:wrap;">
      <h2 style="margin:6px 0;"><i class="fa fa-upload" style="color:#F0A24B;"></i> 匯入ERP對帳單
        <small style="color:#8a6d45;">以 ERP 為準，依「出貨單號＋料號＋數量」更新網頁出貨明細的單價</small></h2>
      <button class="page-help-btn" id="btnHelp"><i class="fa fa-question-circle"></i> 使用說明</button>
    </div>
    <div class="clearfix"></div>

<?php if (!$perms['canView']): ?>
    <div class="a-noperm"><h4><i class="fa fa-lock"></i> 無會計模組檢閱權限</h4>
      <p>請洽管理者於「使用者權限設定」指派會計角色。</p></div>
<?php else: ?>
    <div class="a-bar">
      <span class="a-role" style="margin-left:0;margin-right:8px;">目前角色：<b><?= htmlspecialchars($roleLbl) ?></b></span>
      <label>ERP 客戶對帳單檔</label>
      <input type="file" id="stmtFile" accept=".xls,.xlsx" style="font-size:13px;">
      <button id="btnUpload" class="btn-warm"<?= $perms['canEdit'] ? '' : ' disabled' ?>><i class="fa fa-search"></i> 上傳並比對</button>
      <span style="margin-left:14px;border-left:1px solid #d8c9ad;padding-left:14px;display:inline-flex;align-items:center;gap:6px;flex-wrap:wrap;">
        <label style="margin:0;">批次（多客戶）</label>
        <input type="file" id="batchFiles" accept=".xls,.xlsx" multiple style="font-size:13px;max-width:220px;">
        <button id="btnBatchUp" class="btn-warm"<?= $perms['canEdit'] ? '' : ' disabled' ?>><i class="fa fa-th-list"></i> 批次上傳並比對</button>
        <button id="btnBatchOpen" style="display:none;"><i class="fa fa-window-restore"></i> 開啟批次視窗</button>
      </span>
      <a href="reconcile.php" style="margin-left:auto;text-decoration:none;"><button type="button"><i class="fa fa-balance-scale"></i> 對帳作業</button></a>
    </div>
    <div id="sumBox" style="display:none;">
      <div class="a-stat" id="sumStat"></div>
      <div id="sumWarn"></div>
      <div class="a-bar" style="background:#fff7e8;">
        <button id="btnToRecon" class="btn-warm"><i class="fa fa-balance-scale"></i> 帶到對帳作業核對</button>
        <span style="color:#8a6d45;font-size:13px;">自動開啟這家客戶、這個帳款月份的對帳底稿，把 ERP 對帳單的「本期應收合計」填入「對方紙本合計」，並把與 ERP 一致的明細自動標成「已對到」後暫存；核對無誤再按「確認正確」。建議先把單價／數量差異更新完再帶過去。</span>
      </div>
      <div class="a-bar" style="background:var(--a-bg2);">
        <label>狀態</label>
        <select id="fStatus">
          <option value="diff">有差異（單價／數量／查無）</option>
          <option value="all">全部</option>
          <option value="price">單價不同</option>
          <option value="qty">數量不同</option>
          <option value="erp_only">網頁查無（ERP 有）</option>
          <option value="site_only">ERP 沒有（網頁有）</option>
          <option value="locked">已鎖定</option>
          <option value="same">一致</option>
          <option value="return">只看退貨單</option>
        </select>
        <input type="text" id="fKw" placeholder="搜尋單號／料號" style="width:170px;">
        <button id="btnSelPrice">勾選全部單價差異</button>
        <button id="btnSelNone">清除勾選</button>
        <?php if ($perms['canEdit']): ?>
        <button id="btnApply" class="btn-warm" disabled><i class="fa fa-check"></i> 以 ERP 為準更新勾選的明細</button>
        <?php endif; ?>
        <span id="selInfo" style="color:#8a6d45;font-size:13px;"></span>
      </div>
      <div class="a-wrap" style="max-height:62vh;overflow:auto;">
        <table class="a-t" id="tblE">
          <thead><tr><th style="width:30px;"><input type="checkbox" id="ckAll"></th><th>狀態</th><th>出貨單號</th><th>ERP日期</th>
            <th>料號／品名規格（ERP）</th><th>ERP數量</th><th>網頁數量</th><th>ERP單價</th><th>網頁單價</th><th>金額差</th><th>備註</th></tr></thead>
          <tbody id="eBody"></tbody>
        </table>
      </div>
    </div>
<?php endif; ?>
  </div>
</div>
</div>

<div class="a-mask" id="mkHelp"><div class="a-modal narrow">
  <div class="m-head"><i class="fa fa-question-circle"></i>&nbsp;使用說明<span class="m-close" data-close="mkHelp">✕</span></div>
  <div class="m-body help-doc">
    <h5>用途</h5>網頁上的出貨單可能跟 ERP 實際轉出的有誤差，這裡匯入 ERP 轉出的「客戶對帳單」（.xls／.xlsx，有規格或只有料號兩種都可），系統自動對應出貨單，並以 ERP 為準更新網頁。
    <h5>對應方式</h5>用「出貨單號＋料號＋數量」對應，單價不納入對應條件（業務常在對帳時改單價）。同一張出貨單裡同料號同數量出現多筆時，優先配對單價相同的，其餘依序配對。
    <h5>狀態說明</h5>
    <b>一致</b>：不需處理。<b>單價不同</b>：料號與數量相同、單價不同，預設可勾選更新。<b>數量不同</b>：只有料號對得上，數量（與單價）都不同，請確認後再勾選。<b>網頁查無</b>：ERP 有、網頁找不到這張出貨單或料號，只提示，請到出貨單補登。<b>ERP 沒有</b>：網頁有、ERP 對帳單沒有，只提示。<b>已鎖定</b>：已有傳票，或發票已轉出／開立，不可修改。
    <h5>退貨單</h5>對帳單裡數量／金額為負、或單號 IR 開頭的明細視為退貨，會跟網頁退貨單（依單號＋料號＋數量）核對並標「退貨」。退貨單每天由 ERP 自動同步，所以這裡<b>只核對、不更新</b>；若顯示「網頁查無」，代表同步可能漏掉那筆退貨。
    <h5>帶到對帳作業</h5>比對完（或更新完）按「帶到對帳作業核對」，會自動開啟該客戶、該帳款月份的對帳底稿，並把 ERP 檔的「本期應收合計」填入「對方紙本合計」，差額一目了然；核對無誤再按「確認正確」鎖帳。對帳底稿的帳款月份依客戶結帳日自動判定。
    <h5>注意</h5>更新只改出貨明細的數量與單價；若該筆還在「草稿發票」中，草稿發票的金額會同步更新。每次更新都會留下紀錄（舊值→新值）。
    <h5>權限</h5>檢閱可上傳比對（不需寫入），更新需「會計登錄」以上。
  </div>
</div></div>
<div class="a-mask" id="mkBatch"><div class="a-modal" style="width:1380px;">
  <div class="m-head"><i class="fa fa-th-list"></i>&nbsp;批次處理 ERP 對帳單<span class="m-close" data-close="mkBatch">✕</span></div>
  <div class="m-body" style="max-height:72vh;">
    <style>
    .bb-steps{display:grid;grid-template-columns:repeat(4,1fr);gap:8px;margin-bottom:8px;}
    .bb-step{border:1px solid #e3d4b8;border-radius:6px;background:#fff7e8;padding:8px 10px;display:flex;flex-direction:column;gap:6px;}
    .bb-step .t{font-size:12px;color:#8a6d45;}
    .bb-step .r{display:flex;gap:6px;align-items:center;flex-wrap:wrap;}
    .bb-step button{height:30px;}
    .bb-step input[type=date]{width:136px;}
    </style>
    <div class="bb-steps">
      <div class="bb-step"><div class="t">① 全部客戶</div>
        <div class="r"><button id="bbPrep" class="btn-warm"><i class="fa fa-balance-scale"></i> 帶到對帳作業</button>
        <button id="bbRefresh"><i class="fa fa-refresh"></i> 重新整理狀態</button></div></div>
      <div class="bb-step"><div class="t">② 勾選的客戶</div>
        <div class="r"><button id="bbLock" class="btn-warm"><i class="fa fa-lock"></i> 批次鎖帳</button></div></div>
      <div class="bb-step"><div class="t">③ 勾選的客戶・發票日期</div>
        <div class="r"><input type="date" id="bbInvAll"><button id="bbInvApply">套用全部</button>
        <button id="bbInv" class="btn-warm"><i class="fa fa-file-text-o"></i> 批次開發票</button></div></div>
      <div class="bb-step"><div class="t">④ 勾選的客戶・傳票日期</div>
        <div class="r"><input type="date" id="bbVchAll"><button id="bbVchApply">套用全部</button>
        <button id="bbVch" class="btn-warm"><i class="fa fa-book"></i> 批次轉傳票</button></div></div>
    </div>

    <div style="color:#8a6d45;font-size:12.5px;margin-bottom:6px;">
      ①會先依 ERP 更正單價、補上網頁漏掉的出貨明細，再把與 ERP 一致的明細標成「已對到」暫存；點客戶名稱可另開分頁核對（核對完回來按「重新整理狀態」）。
      ②只鎖已對到的明細，沒對到的會移出底稿。③發票日期可各別填。④傳票只需填 ERP 流水號（1~3 碼，欄位下方即時顯示完整號碼，年月日取自該列傳票日期），填完離開欄位會依號碼由舊到新排序。發票號碼可選填（2 英文＋8 數字），有填就直接標為已開立。上方日期改了就全部套用同一天。
      金額欄「ERP 檔」是檔內明細合計；「已對到」是網頁對帳底稿中已勾選對到的明細，兩邊不同代表還有差異要核對。</div>
    <div style="margin-bottom:6px;"><input type="text" id="bbKw" placeholder="篩選：客戶編號／客戶名稱／檔名（可打一部分）" style="width:320px;" autocomplete="off"> <span id="bbCnt" style="color:#8a6d45;font-size:12px;"></span></div>
    <div style="overflow:auto;"><table class="a-t" id="tblB">
      <thead><tr><th style="width:28px;"><input type="checkbox" id="bbAll"></th><th>客戶／檔案</th><th>帳款月</th>
        <th>ERP 未稅</th><th>ERP 稅金</th><th>ERP 含稅</th>
        <th>已對到 未稅</th><th>稅金</th><th>含稅</th><th>未對到</th>
        <th>底稿</th><th>發票</th><th>傳票</th><th>發票日期</th><th>發票號碼</th><th>傳票日期</th><th>ERP傳票號碼（只填流水號）</th><th>結果</th></tr></thead>
      <tbody id="bbBody"></tbody></table></div>
  </div>
</div></div>
<div class="a-mask" id="mkWarn" style="z-index:100001;"><div class="a-modal narrow">
  <div class="m-head" style="background:#DD5138;color:#fff;"><i class="fa fa-exclamation-triangle"></i>&nbsp;有對帳單對應不到客戶<span class="m-close" data-close="mkWarn">✕</span></div>
  <div class="m-body" id="warnBody" style="font-size:13.5px;line-height:1.7;"></div>
  <div class="m-foot"><button data-close="mkWarn">知道了</button></div>
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
var API='../../src/store/Acc_API.php', CSRF='', TOKEN='', ENT=[], SEL={};
var ST={same:'一致',price:'單價不同',qty:'數量不同',erp_only:'網頁查無',site_only:'ERP沒有',locked:'已鎖定'};
function esc(s){ return String(s==null?'':s).replace(/[&<>"']/g,function(c){return {'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#39;'}[c];}); }
function nf(n){ return Math.round(Number(n)||0).toLocaleString('en-US'); }
function np(n){ return n==null?'':String(parseFloat(Number(n).toFixed(4))); }
function toast(m,bad){ var $m=$('#msg').removeClass('ok bad').addClass(bad?'bad':'ok').html(m).stop(true,true).fadeIn(150);
  clearTimeout($m.data('t')); $m.data('t',setTimeout(function(){ $m.fadeOut(400); },bad?8000:4000)); }
function openMask(id){ $('#'+id).addClass('show'); } function closeMask(id){ $('#'+id).removeClass('show'); }
$(document).on('click','[data-close]',function(){ closeMask($(this).data('close')); });
$(document).on('click','.a-mask',function(e){ if(e.target===this) $(this).removeClass('show'); });
$('#btnHelp').on('click',function(){ openMask('mkHelp'); });
$.getJSON(API,{action:'meta'},function(r){ if(r.ok) CSRF=r.csrf; });

$('#btnUpload').on('click',function(){
  var f=$('#stmtFile')[0].files[0];
  if(!f){ toast('請先選擇 ERP 對帳單檔案', true); return; }
  if(!/\.xlsx?$/i.test(f.name)){ toast('只接受 .xls 或 .xlsx 檔案', true); return; }
  var fd=new FormData(); fd.append('file',f); fd.append('csrf',CSRF);
  var $b=$(this).prop('disabled',true).html('<i class="fa fa-spinner fa-spin"></i> 比對中…');
  $.ajax({url:API+'?action=erp_stmt_upload',method:'POST',data:fd,processData:false,contentType:false,dataType:'json'})
  .done(function(r){
    $b.prop('disabled',false).html('<i class="fa fa-search"></i> 上傳並比對');
    if(!r.ok){ toast(esc(r.error||'比對失敗'), true); return; }
    TOKEN=r.token; ENT=r.entries||[]; SEL={};
    var s=r.summary||{};
    $('#sumStat').html('<div><b>'+esc(r.customer_id)+' '+esc(r.customer_name)+'</b> <span class="l">'+esc(r.range)+'</span></div><div class="sep"></div>'
      +'<div><span class="n">'+nf(r.line_cnt)+'</span> <span class="l">ERP 明細</span></div>'
      +'<div><span class="n">'+nf(s.same)+'</span> <span class="l">一致</span></div>'
      +'<div><span class="n bad">'+nf(s.price)+'</span> <span class="l">單價不同</span></div>'
      +'<div><span class="n bad">'+nf(s.qty)+'</span> <span class="l">數量不同</span></div>'
      +'<div><span class="n bad">'+nf(s.erp_only)+'</span> <span class="l">網頁查無</span></div>'
      +'<div><span class="n">'+nf(s.site_only)+'</span> <span class="l">ERP沒有</span></div>'
      +'<div><span class="n">'+nf(s.locked)+'</span> <span class="l">已鎖定</span></div>'
      +'<div><span class="n">'+nf((r.entries||[]).filter(function(e){return e.kind==='return';}).length)+'</span> <span class="l">退貨列（僅核對）</span></div>'
      +'<div class="sel">已選 <b id="selN">0</b> 筆</div>');
    var w='';
    if(r.declared_total!=null && Math.abs(r.declared_total-r.lines_amount)>0.5)
      w+='<div class="warn">檔案明細金額合計 '+nf(r.lines_amount)+' 與檔內「本期應收合計」'+nf(r.declared_total)+' 不符，可能有明細沒讀到，請確認檔案。</div>';
    if(r.declared_total!=null && Math.abs(r.declared_total-r.lines_amount)<=0.5)
      w+='<div class="info">檔案明細合計 '+nf(r.lines_amount)+' 與「本期應收合計」相符，涵蓋 '+nf(r.is_numbers)+' 張出貨單。</div>';
    $('#sumWarn').html(w);
    $('#sumBox').show(); render();
  }).fail(function(x){
    $b.prop('disabled',false).html('<i class="fa fa-search"></i> 上傳並比對');
    var m='比對失敗'; try{ m=JSON.parse(x.responseText).error||m; }catch(e){} toast(esc(m), true);
  });
});

function visible(){
  var f=$('#fStatus').val(), kw=($('#fKw').val()||'').trim().toLowerCase();
  return ENT.map(function(e,i){ e._i=i; return e; }).filter(function(e){
    var st=e.lock?'locked':e.status;
    if(f==='return'){ if(e.kind!=='return') return false; }
    else if(f==='diff'){ if(st==='same') return false; }
    else if(f!=='all' && st!==f) return false;
    if(kw && (e.no+' '+e.erp_spec+' '+e.site_part).toLowerCase().indexOf(kw)<0) return false;
    return true;
  });
}
function canPick(e){ return e.kind!=='return' && !e.lock && (e.status==='price'||e.status==='qty') && e.is_id; }
function render(){
  var list=visible(), h='';
  if(!list.length) h='<tr><td colspan="11" style="padding:22px;color:#8a6d45;">沒有符合條件的資料</td></tr>';
  list.forEach(function(e){
    var st=e.lock?'locked':e.status;
    var diff=(e.erp_amount!=null && e.site_qty!=null)? (e.erp_amount - e.site_qty*e.site_price) : null;
    var qchg=(e.erp_qty!=null && e.site_qty!=null && Math.abs(e.erp_qty)!==Math.abs(e.site_qty));
    var pchg=(e.erp_price!=null && e.site_price!=null && Math.abs(e.erp_price-e.site_price)>0.00005);
    var note=e.lock?esc(e.lock):(st==='erp_only'?'網頁沒有這張出貨單／料號，請補登':(st==='site_only'?'ERP 對帳單沒有這筆':(st==='qty'?'數量不同，請確認':'')));
    if(e.kind==='return'){
      note='退貨單：網頁每日自動同步，此處僅核對不更新'+(st==='erp_only'?'（網頁查無這筆退貨，請確認同步是否漏掉）':(st==='site_only'?'（網頁有、ERP 對帳單沒有）':(st==='same'?'':'（請回退貨單確認）')));
    }
    h+='<tr data-i="'+e._i+'" class="'+(SEL[e._i]?'on':'')+'">'
      +'<td>'+(canPick(e)?'<input type="checkbox" class="ck"'+(SEL[e._i]?' checked':'')+'>':'')+'</td>'
      +'<td><span class="pill st-'+st+'">'+ST[st]+'</span></td>'
      +'<td>'+(e.kind==='return'?'<span class="pill st-site_only" style="margin-right:4px;">退貨</span>':'')+esc(e.no)+'</td><td>'+esc(e.erp_date||e.site_date)+'</td>'
      +'<td class="l">'+esc(e.erp_spec||e.site_part)+'</td>'
      +'<td class="r'+(qchg?' chg':'')+'">'+(e.erp_qty==null?'':nf(e.erp_qty))+'</td>'
      +'<td class="r">'+(e.site_qty==null?'':nf(e.site_qty))+'</td>'
      +'<td class="r'+(pchg?' chg':'')+'">'+np(e.erp_price)+'</td>'
      +'<td class="r">'+np(e.site_price)+'</td>'
      +'<td class="r'+(diff&&Math.abs(diff)>0.5?' chg':'')+'">'+(diff==null?'':nf(diff))+'</td>'
      +'<td class="l">'+note+'</td></tr>';
  });
  $('#eBody').html(h); upd();
}
function upd(){
  var n=Object.keys(SEL).length; $('#selN').text(n); $('#selInfo').text(n? '':'');
  $('#btnApply').prop('disabled', n===0);
}
$('#fStatus,#fKw').on('input change',render);
// 只更新 Qty／Unit_price，不碰任何綁定（訂單／製令／報價對應）；數量不同的可能牽動綁定分配，不自動改
function autoSyncPrice(done){
  var idx=[];
  ENT.forEach(function(e,i){ if(e.kind!=='return' && e.is_id && e.status==='price' && (!e.lock||e.lock==='已鎖定')) idx.push(i); });
  var hasNew=ENT.some(function(e){ return e.kind!=='return' && e.status==='erp_only' && e.erp_qty>0; });
  if(!idx.length && !hasNew){ done(0,[]); return; }
  $.post(API+'?action=erp_stmt_sync',{csrf:CSRF,token:TOKEN},function(r){
    if(!r.ok){ toast(esc(r.error||'同步單價失敗'), true); done(-1,[]); return; }
    idx.forEach(function(i){
      var e=ENT[i], sk=(r.skipped||[]).some(function(s){ return s.indexOf(e.no+' '+e.site_part)===0; });
      if(!sk){ e.status='same'; e.site_price=e.erp_price; }
    });
    SEL={}; render(); done(r.updated||0, r.skipped||[], r.created||0);
  },'json').fail(function(){ toast('同步單價失敗', true); done(-1,[]); });
}
$('#btnToRecon').on('click',function(){
  var $b=$(this).prop('disabled',true);
  var w=window.open('','_blank');   // 先在點擊當下開好分頁，免得非同步回來後被彈出視窗封鎖擋掉
  autoSyncPrice(function(n,sk,cr){
  if(n<0){ $b.prop('disabled',false); if(w) w.close(); return; }
  if(n>0||sk.length||cr) toast('已先依 ERP 同步：更正 '+n+' 筆單價'+(cr?'、自動新增 '+cr+' 筆出貨明細':'')+(sk.length?'；略過 '+sk.length+' 筆：'+sk.slice(0,3).map(esc).join('；'):'')+'（出貨單備註已註記）', false);
  $.post(API+'?action=erp_stmt_recon_link',{token:TOKEN},function(r){
    $b.prop('disabled',false);
    if(!r.ok){ toast(esc(r.error||'無法帶入'), true); if(w) w.close(); return; }
    // 注意：此時已先開了空白分頁（成為前景分頁），本頁的 confirm() 會被瀏覽器直接抑制並回 false，所以只用提示，不再詢問
    if(r.multi_party||r.multi_month) toast('這份對帳單涵蓋'+(r.multi_party?'多個客戶名稱':'')+(r.multi_party&&r.multi_month?'、':'')+(r.multi_month?'多個帳款月份':'')
      +'，已以筆數最多的「'+esc(r.party_id)+'／'+esc(r.bm)+'」開啟', false);
    var url='reconcile.php?side=ar&party_id='+encodeURIComponent(r.party_id)+'&bm='+encodeURIComponent(r.bm)
      +(r.their_total==null?'':'&their='+encodeURIComponent(r.their_total))+'&erp='+encodeURIComponent(r.token)
      +(r.date_from&&r.date_to?'&rg_from='+encodeURIComponent(r.date_from)+'&rg_to='+encodeURIComponent(r.date_to):'');
    if(w) w.location=url; else window.open(url,'_blank');
  },'json').fail(function(x){ $b.prop('disabled',false); if(w) w.close(); var m='無法帶入'; try{ m=JSON.parse(x.responseText).error||m; }catch(e){} toast(esc(m), true); });
  });
});
$(document).on('change','.ck',function(){
  var i=$(this).closest('tr').data('i');
  if(this.checked) SEL[i]=1; else delete SEL[i];
  $(this).closest('tr').toggleClass('on',this.checked); upd();
});
$('#ckAll').on('change',function(){
  var on=this.checked;
  $('#eBody .ck').each(function(){ var i=$(this).closest('tr').data('i');
    $(this).prop('checked',on).closest('tr').toggleClass('on',on); if(on) SEL[i]=1; else delete SEL[i]; });
  upd();
});
$('#btnSelPrice').on('click',function(){
  ENT.forEach(function(e,i){ if(canPick(e)&&e.status==='price') SEL[i]=1; }); render();
});
$('#btnSelNone').on('click',function(){ SEL={}; $('#ckAll').prop('checked',false); render(); });
$('#btnApply').on('click',function(){
  var idx=Object.keys(SEL).map(Number), ids=idx.map(function(i){ return ENT[i].is_id; });
  if(!ids.length) return;
  var q=idx.filter(function(i){ return ENT[i].status==='qty'; }).length;
  if(!confirm('以 ERP 為準更新 '+ids.length+' 筆出貨明細？\n'+(q?('其中 '+q+' 筆是「數量不同」，數量與單價都會改成 ERP 的值。\n'):'')
    +'（已鎖定或網頁資料已被改過的會自動略過）')) return;
  var $b=$(this).prop('disabled',true);
  $.post(API+'?action=erp_stmt_apply',{csrf:CSRF,token:TOKEN,is_ids:JSON.stringify(ids)},function(r){
    $b.prop('disabled',false);
    if(!r.ok){ toast(esc(r.error||'更新失敗'), true); return; }
    toast('<b>'+esc(r.message)+'</b>'+((r.skipped&&r.skipped.length)?'<br>'+r.skipped.map(esc).join('<br>'):''), !!(r.skipped&&r.skipped.length&&!r.updated));
    // 已更新的列改成一致
    idx.forEach(function(i){
      var e=ENT[i]; if(e.status==='price'||e.status==='qty'){
        var skipped=(r.skipped||[]).some(function(s){ return s.indexOf(e.no+' '+e.site_part)===0; });
        if(!skipped){ e.status='same'; e.site_qty=e.erp_qty; e.site_price=e.erp_price; }
      }
    });
    SEL={}; render();
  },'json').fail(function(x){ $b.prop('disabled',false); var m='更新失敗'; try{ m=JSON.parse(x.responseText).error||m; }catch(e){} toast(esc(m), true); });
});
/* ═══ 批次 ═══ */
var BR=[], TODAY=(function(){var d=new Date();return d.getFullYear()+'-'+('0'+(d.getMonth()+1)).slice(-2)+'-'+('0'+d.getDate()).slice(-2);})();
function bPost(act,data,cb,fail){
  data=$.extend({csrf:CSRF},data);
  $.post(API+'?action='+act,data,function(r){ if(!r.ok){ fail(r.error||'失敗'); return; } cb(r); },'json')
   .fail(function(x){ var m='失敗'; try{ m=JSON.parse(x.responseText).error||m; }catch(e){} fail(m); });
}
function stTxt(st){
  if(!st) return {sheet:'—',inv:'—',vch:'—'};
  var sh={none:'未建立',draft:'暫存中',confirmed:'已鎖帳'}[st.sheet_status]||st.sheet_status;
  var inv=(st.invoices||[]).length?('<span class="pill st-same">已開 '+st.invoices.length+' 張</span>'):'—';
  var vch=(st.vouchers||[]).length?('<span class="pill st-same">'+esc(st.vouchers.map(function(v){return v.voucher_no||'(未填號)';}).join('、'))+'</span>'):'—';
  return {sheet:'<span class="pill '+(st.sheet_status==='confirmed'?'st-locked':(st.sheet_status==='draft'?'st-price':'st-site_only'))+'">'+sh+'</span>',inv:inv,vch:vch};
}
/* 只輸入 1~3 碼＝流水號 → V-民國年3碼＋MMDD＋流水3碼（年月日取自該列傳票日期）；其餘（7／10 碼或完整號碼）交後端補齊 */
function vFull(r){
  var n=String(r.vch_no||'').trim().toUpperCase(), d=String(r.vch_date||'');
  if(!n) return '';
  var m=d.match(/^(\d{4})-(\d{2})-(\d{2})$/);
  if(/^\d{1,3}$/.test(n)&&m) return 'V-'+('00'+(parseInt(m[1],10)-1911)).slice(-3)+m[2]+m[3]+('00'+n).slice(-3);
  if(/^\d{10}$/.test(n)) return 'V-'+n;
  if(/^\d{7}$/.test(n)&&m) return 'V-'+('00'+(parseInt(m[1],10)-1911)).slice(-3)+n;
  return n;
}
function sortByVch(){   // 有傳票號碼者由舊到新（號碼小到大），沒填的排最後；同號依原順序
  var o=BR.map(function(r,i){ return {r:r,i:i,k:vFull(r)}; });
  o.sort(function(a,b){ if(!a.k&&!b.k) return a.i-b.i; if(!a.k) return 1; if(!b.k) return -1; return a.k<b.k?-1:(a.k>b.k?1:a.i-b.i); });
  BR=o.map(function(x){ return x.r; });
}
function bRender(){
  var h='', kw=($('#bbKw').val()||'').trim().toLowerCase(), shown=0;
  BR.forEach(function(r,i){
    if(kw && (String(r.customer_id||'')+' '+String(r.party_id||'')+' '+String(r.customer_name||'')+' '+String(r.file_name||'')).toLowerCase().indexOf(kw)<0) return;
    shown++;
    if(!r.ok){ h+='<tr><td></td><td class="l" colspan="16" style="color:#DD5138;">'+esc(r.file_name)+'：'+esc(r.error||'讀取失敗')+'</td><td></td></tr>'; return; }
    var st=r.status, t=stTxt(st), ok=!!st&&st.sheet_status!=='none';
    var diff=(ok&&r.net!=null)?(st.net-r.net):0;
    h+='<tr data-i="'+i+'"><td><input type="checkbox" class="bck"'+(r.sel?' checked':'')+'></td>'
      +'<td class="l"><b>'+(r.party_id?'<a href="'+esc(r.link||'#')+'" target="_blank" title="另開分頁核對">'+esc(r.party_id)+'</a>':esc(r.customer_name||''))+'</b>'
      +'<div style="font-size:11px;color:#8a6d45;">'+esc(r.customer_id||'')+' '+esc(r.file_name)+'</div></td>'
      +'<td>'+esc(r.bm||'')+'</td>'
      +'<td class="r">'+nf(r.net)+'</td><td class="r">'+nf(r.tax)+'</td><td class="r">'+nf(r.total)+'</td>'
      +'<td class="r'+(ok&&Math.abs(diff)>0.5?' chg':'')+'">'+(ok?nf(st.net):'')+'</td><td class="r">'+(ok?nf(st.tax):'')+'</td><td class="r">'+(ok?nf(st.total):'')+'</td>'
      +'<td class="r'+(ok&&st.unchecked_cnt?' chg':'')+'">'+(ok?st.unchecked_cnt:'')+'</td>'
      +'<td>'+t.sheet+'</td><td>'+t.inv+'</td><td>'+t.vch+'</td>'
      +'<td><input type="date" class="bdi" value="'+esc(r.inv_date||'')+'" style="width:132px;"></td>'
      +'<td><input type="text" class="bin" value="'+esc(r.inv_no||'')+'" style="width:110px;text-transform:uppercase;" maxlength="10" autocomplete="off" placeholder="AB12345678"></td>'
      +'<td><input type="date" class="bdv" value="'+esc(r.vch_date||'')+'" style="width:132px;"></td>'
      +'<td><input type="text" class="bvn" value="'+esc(r.vch_no||'')+'" style="width:84px;" maxlength="12" autocomplete="off" placeholder="流水號">'
      +'<div class="bvf" style="font-size:11px;color:#8a6d45;">'+esc(vFull(r))+'</div></td>'
      +'<td class="l" style="font-size:12px;color:'+((r.bad||(r.warn&&!r.msg))?'#DD5138':'#5b3a1e')+';">'+(r.warn?'<b>⚠ 對應不到：</b>':'')+esc(r.msg||r.warn||'')+'</td></tr>';
  });
  $('#bbCnt').text(kw?('顯示 '+shown+' / '+BR.length+' 筆'):'');
  $('#bbBody').html(h||'<tr><td colspan="18" style="padding:20px;color:#8a6d45;">沒有資料</td></tr>');
}
$(document).on('change','#bbBody .bck',function(){ BR[$(this).closest('tr').data('i')].sel=this.checked; });
$(document).on('input change','#bbBody .bdi',function(){ BR[$(this).closest('tr').data('i')].inv_date=this.value; });
$(document).on('input change','#bbBody .bdv',function(){ var r=BR[$(this).closest('tr').data('i')]; r.vch_date=this.value; $(this).closest('tr').find('.bvf').text(vFull(r)); });
$(document).on('input','#bbBody .bin',function(){ BR[$(this).closest('tr').data('i')].inv_no=this.value.toUpperCase(); });
$(document).on('input','#bbBody .bvn',function(){ var r=BR[$(this).closest('tr').data('i')]; r.vch_no=this.value; $(this).closest('tr').find('.bvf').text(vFull(r)); });
$(document).on('change','#bbBody .bvn',function(){ sortByVch(); bRender(); });   // 打完號碼離開欄位時才依號碼由舊到新排序（邊打邊排會跳行）
$('#bbKw').on('input',bRender);
$('#bbVchAll').on('change',function(){ var v=this.value; if(!v) return; BR.forEach(function(r){ r.vch_date=v; }); bRender(); });   // 改上方傳票日期＝全部同一天
$('#bbInvAll').on('change',function(){ var v=this.value; if(!v) return; BR.forEach(function(r){ r.inv_date=v; }); bRender(); });
$('#bbAll').on('change',function(){ var on=this.checked; BR.forEach(function(r){ if(r.ok) r.sel=on; }); bRender(); });
$('#bbInvApply').on('click',function(){ var v=$('#bbInvAll').val(); if(!v) return; BR.forEach(function(r){ r.inv_date=v; }); bRender(); });
$('#bbVchApply').on('click',function(){ var v=$('#bbVchAll').val(); if(!v) return; BR.forEach(function(r){ r.vch_date=v; }); bRender(); });
$('#btnBatchOpen').on('click',function(){ openMask('mkBatch'); });
$('#btnBatchUp').on('click',function(){
  var fl=Array.prototype.slice.call($('#batchFiles')[0].files);
  if(!fl.length){ toast('請先選擇多個 ERP 對帳單檔案', true); return; }
  var $b=$(this).prop('disabled',true), all=[], done=0, errs=[], pos=0, CH=15;   // PHP 單次請求最多收 20 個檔案，所以分批送
  function reset(){ $b.prop('disabled',false).html('<i class="fa fa-th-list"></i> 批次上傳並比對'); }
  (function nx(){
    if(pos>=fl.length){
      reset();
      BR=all.filter(function(x){ if(x.done){ done++; return false; } return true; });
      BR.forEach(function(x){ x.inv_date=TODAY; x.vch_date=TODAY; x.vch_no=''; });
      $('#bbInvAll,#bbVchAll').val(TODAY);
      bRender(); $('#btnBatchOpen').show(); openMask('mkBatch');
      var wl=BR.filter(function(x){ return x.warn; });
      if(wl.length){
        $('#warnBody').html('<div style="margin-bottom:8px;">以下 <b>'+wl.length+'</b> 份對帳單對應不到網頁的客戶或出貨明細，請先處理再繼續（其餘的仍可照常批次處理）：</div>'
          +wl.map(function(x){ return '<div style="border-left:3px solid #DD5138;padding:4px 8px;margin-bottom:6px;background:#fff7f3;"><b>'+esc(x.customer_name||x.file_name)+'</b>'
            +' <span style="color:#8a6d45;font-size:12px;">'+esc(x.file_name)+'</span><div>'+esc(x.warn)+'</div></div>'; }).join(''));
        openMask('mkWarn');
      }
      toast('共 '+fl.length+' 個檔案，列出 '+BR.length+' 個'+(done?('；'+done+' 個客戶已鎖帳且發票、傳票都辦完，未列出'):''), false);
      return;
    }
    var part=fl.slice(pos,pos+CH); pos+=CH;
    var fd=new FormData(); fd.append('csrf',CSRF);
    part.forEach(function(f){ fd.append('files[]',f); });
    $b.html('<i class="fa fa-spinner fa-spin"></i> 比對中 '+Math.min(pos,fl.length)+'/'+fl.length+'…');
    $.ajax({url:API+'?action=erp_batch_upload',method:'POST',data:fd,processData:false,contentType:false,dataType:'json'})
    .done(function(r){
      if(!r.ok){ reset(); toast(esc(r.error||'上傳失敗'), true); return; }
      all=all.concat(r.rows||[]); nx();
    }).fail(function(x){ reset(); var m='上傳失敗'; try{ m=JSON.parse(x.responseText).error||m; }catch(e){} toast(esc(m), true); });
  })();
});
function seq(list,fn,done){ var k=0; (function nx(){ if(k>=list.length){ if(done) done(); return; } var r=list[k++]; fn(r,function(){ bRender(); nx(); }); })(); }
function setMsg(r,m,bad){ r.msg=m; r.bad=!!bad; }
$('#bbPrep').on('click',function(){
  var list=BR.filter(function(r){ return r.ok; });
  if(!list.length) return;
  if(!confirm('把 '+list.length+' 份對帳單依序帶到對帳作業？\n會先依 ERP 更正出貨單價、補上網頁漏掉的明細，再建立（或更新）對帳底稿並標示已對到。')) return;
  var $b=$(this).prop('disabled',true);
  seq(list,function(r,next){
    setMsg(r,'處理中…'); bRender();
    bPost('erp_batch_prepare',{token:r.token},function(x){
      r.party_id=x.party_id; r.bm=x.bm; r.link=x.link||''; r.status=x.status;
      setMsg(r,(x.already?'已鎖帳（未更動）':('單價更正 '+x.synced+'、補明細 '+x.created))+(x.multi_party||x.multi_month?'；涵蓋多客戶／多月份，已取筆數最多者':'')+((x.skipped||[]).length?'；略過 '+x.skipped.length+' 筆':''), x.skipped&&x.skipped.length);
      next();
    },function(m){ setMsg(r,m,true); next(); });
  },function(){ $b.prop('disabled',false); toast('已帶到對帳作業，請點客戶名稱確認各別對帳，完成後回來鎖帳',false); });
});
function refreshStatus(cb){
  var pairs=BR.filter(function(r){ return r.ok&&r.party_id; }).map(function(r){ return {party_id:r.party_id,bm:r.bm}; });
  if(!pairs.length){ if(cb) cb(); return; }
  $.post(API+'?action=erp_batch_status',{pairs:JSON.stringify(pairs)},function(x){
    if(x.ok) (x.rows||[]).forEach(function(s){ BR.forEach(function(r){ if(r.party_id===s.party_id&&r.bm===s.bm) r.status=s.status; }); });
    bRender(); if(cb) cb();
  },'json');
}
$('#bbRefresh').on('click',function(){ refreshStatus(function(){ toast('狀態已更新',false); }); });
function picked(){
  var l=BR.filter(function(r){ return r.ok&&r.sel; });
  if(!l.length){ toast('請先勾選客戶',true); return null; }
  var miss=l.filter(function(r){ return !r.party_id; });
  if(miss.length){ toast('尚未「帶到對帳作業」：'+esc(miss[0].customer_name||miss[0].file_name),true); return null; }
  return l;
}
function stepRun(l,act,label,make,okTxt){
  if(!confirm('對勾選的 '+l.length+' 個客戶執行「'+label+'」？')) return;
  var n=0; seq(l,function(r,next){
    var d=make(r); if(d===false){ next(); return; }
    setMsg(r,'處理中…'); bRender();
    bPost(act,d,function(x){ r.status=x.status; n++; setMsg(r,okTxt(x)); next(); },function(m){ setMsg(r,m,true); next(); });
  },function(){ toast(label+'：成功 '+n+' 筆（失敗或略過的請看「結果」欄）',n<l.length); });
}
$('#bbLock').on('click',function(){
  var l=picked(); if(!l) return;
  stepRun(l,'erp_batch_lock','批次鎖帳',function(r){
    if(r.status&&r.status.sheet_status==='confirmed'){ setMsg(r,'已是鎖帳狀態'); return false; }
    return {party_id:r.party_id,bm:r.bm};
  },function(){ return '已鎖帳'; });
});
$('#bbInv').on('click',function(){
  var l=picked(); if(!l) return;
  var bad=l.filter(function(r){ return !r.inv_date; });
  if(bad.length){ toast('請填 '+esc(bad[0].party_id)+' 的發票日期',true); return; }
  stepRun(l,'erp_batch_invoice','批次開發票',function(r){
    if(r.status&&r.status.invoices&&r.status.invoices.length){ setMsg(r,'已開過發票，略過'); return false; }
    return {party_id:r.party_id,bm:r.bm,date:r.inv_date,invoice_no:String(r.inv_no||'').trim()};
  },function(x){ return x.message||'已建立發票'; });
});
$('#bbVch').on('click',function(){
  var l=picked(); if(!l) return;
  var bad=l.filter(function(r){ return !r.vch_date||!String(r.vch_no||'').trim(); });
  if(bad.length){ toast('請填 '+esc(bad[0].party_id)+' 的傳票日期與 ERP 傳票號碼',true); return; }
  stepRun(l,'erp_batch_voucher','批次轉傳票',function(r){
    if(r.status&&r.status.vouchers&&r.status.vouchers.length){ setMsg(r,'已有傳票，略過'); return false; }
    return {party_id:r.party_id,bm:r.bm,date:r.vch_date,voucher_no:vFull(r)};
  },function(x){ return x.message||'已建立傳票'; });
});
})(jQuery);
</script>
</body>
</html>
