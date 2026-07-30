<?php
/**
 * views/ACC/ap_statement.php — 應付對帳單（廠商加工費）（會計模組第五步）
 *
 * 一列＝廠商×發票年月。來源 bom_ing_transfer_log：
 * 實測 43,622 筆有金額的加工紀錄中，43,616 筆已帶 invoice_date / invoice_ym /
 * tax_amount / paid_qty（99.99%），廠商發票資訊本來就在維護，
 * 因此會計端只做彙總對帳，不重新登錄廠商發票。
 *
 * 月份歸屬：優先用資料本身的 invoice_ym（廠商發票年月）；
 * 沒有 invoice_ym 的少數紀錄才退回用 transfer_date 所在月份。
 *
 * 尚未涵蓋（待 purchase_request 有實際資料後接入）：材料／其他採購的應付。
 * 加工費與採購會共用同一層付款沖帳，屆時在此頁增加來源切換。
 *
 * 資料一律走 src/store/Acc_API.php；權限 acc_lib.php（roles module='accounting'）
 */
session_start();
if (!isset($_SESSION['userName'])) {
    $_SESSION['lastpage'] = "../../views/ACC/ap_statement.php";
    header("Location:../../index.php");
    exit;
}
include_once '../../src/common/_config.php';
include_once '../../src/common/DBConnection.php';
include_once '../../src/common/acc_lib.php';

$db = (new DBConnection())->getPDO();
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
<title>應付對帳單（廠商加工費）</title>
<link href="../../resource/css/bootstrap.css" rel="stylesheet">
<link href="../../resource/css/font-awesome.css" rel="stylesheet">
<link href="../../resource/css/nprogress.css" rel="stylesheet">
<link href="../../resource/css/custom.css" rel="stylesheet">
<style>
/* 應付＝暖棕赭色系（錢出去）；應收＝琥珀橘（錢進來）。
   兩頁都在暖色系內（符合配色規範），但主色、表頭、身分帶明顯不同，
   且顏色不是唯一辨識依據——另有文字標籤與箭頭方向。 */
:root{
  --a-line:#DFCBA9; --a-line2:#C9AE85; --a-bg:#FBF5EC; --a-bg2:#F6EBDA;
  --a-ink:#4E2C0B; --a-ink2:#7d6242; --a-brand:#7A4A1E;
  --a-acc:#A2703A; --a-acc-d:#8A5A2B; --a-ok:#E8D3B4; --a-bad:#C0392B;
}
#sidebar-menu{visibility:hidden;}
.right_col .page-title{margin:8px 0 4px;overflow:hidden;}

.side-band{display:flex;align-items:center;gap:10px;clear:both;margin-bottom:8px;
  background:linear-gradient(90deg,#8A5A2B 0%,#6E4520 100%);color:#fff;
  border-radius:8px;padding:7px 14px;font-size:15px;box-shadow:inset 0 -3px 0 rgba(0,0,0,.18);}
.side-band .fa-arrow-circle-up{font-size:19px;}
.side-band .sb-sub{font-size:12.5px;opacity:.92;font-weight:normal;}
.side-band .sb-switch{margin-left:auto;font-size:13px;color:#fff;background:rgba(255,255,255,.16);
  border:1px solid rgba(255,255,255,.5);border-radius:14px;padding:4px 13px;text-decoration:none;
  white-space:nowrap;}
.side-band .sb-switch:hover{background:rgba(255,255,255,.3);color:#fff;text-decoration:none;}

.a-bar{display:flex;flex-wrap:wrap;gap:6px;align-items:center;clear:both;
  border:1.5px solid var(--a-line);border-radius:8px;padding:8px 10px;margin-bottom:8px;background:var(--a-bg);}
.a-bar label{margin:0;font-size:13px;color:var(--a-ink);font-weight:normal;}
.a-bar input[type=text],.a-bar input[type=month],.a-bar select,.a-bar button{
  height:32px;font-size:13px;line-height:1;padding:0 10px;border:1px solid var(--a-line2);
  border-radius:4px;background:#fff;color:var(--a-ink);}
.a-bar button{cursor:pointer;}
.a-bar button:hover{background:var(--a-ok);}
.a-bar .btn-warm{background:var(--a-acc);color:#fff;border-color:var(--a-acc-d);font-weight:bold;}
.a-bar .btn-warm:hover{background:var(--a-acc-d);}
.a-role{margin-left:auto;font-size:13px;color:var(--a-ink);background:var(--a-ok);
  border-radius:12px;padding:5px 12px;white-space:nowrap;}
.a-role .fa-question-circle{cursor:pointer;color:#b5762a;margin-left:5px;}

.a-stat{display:flex;flex-wrap:wrap;gap:18px;align-items:center;margin-bottom:8px;
  border:1.5px solid var(--a-line);border-radius:8px;padding:9px 14px;background:var(--a-bg2);}
.a-stat .n{font-size:19px;font-weight:bold;color:var(--a-brand);}
.a-stat .n.big{font-size:23px;}
.a-stat .n.bad{color:var(--a-bad);}
.a-stat .l{font-size:12px;color:var(--a-ink2);}
.a-stat .sep{width:1px;height:30px;background:var(--a-line);}

.a-pager{display:flex;justify-content:flex-end;align-items:center;gap:6px;margin-bottom:4px;font-size:13px;color:var(--a-ink);}
.a-pager button{height:26px;min-width:28px;padding:0 8px;border:1px solid var(--a-line2);
  background:#fff;border-radius:4px;cursor:pointer;color:var(--a-ink);}
.a-pager button:hover:not(:disabled){background:var(--a-ok);}
.a-pager button.on{background:var(--a-acc);color:#fff;border-color:var(--a-acc-d);font-weight:bold;}
.a-pager button:disabled{opacity:.4;cursor:default;}
.a-pager select{height:26px;border:1px solid var(--a-line2);border-radius:4px;font-size:12px;}

.a-wrap{overflow-x:auto;border:1px solid var(--a-line);border-radius:6px;background:#fff;}
table.a-t{width:100%;border-collapse:collapse;font-size:13px;}
table.a-t th,table.a-t td{border:1px solid #EADFC8;padding:4px 7px;white-space:nowrap;text-align:center;}
table.a-t thead th{position:sticky;top:0;z-index:2;background:var(--a-ok);color:var(--a-ink);font-weight:bold;}
table.a-t thead th.sortable{cursor:pointer;user-select:none;}
table.a-t thead th.sortable:hover{background:#F0CFA0;}
table.a-t thead th.sortable .sa{margin-left:4px;font-style:normal;opacity:.35;font-size:11px;}
table.a-t thead th.sortable .sa:before{content:'\f0dc';font-family:FontAwesome;}
table.a-t thead th.sortable.asc .sa,table.a-t thead th.sortable.desc .sa{opacity:1;color:#8A5A2B;}
table.a-t thead th.sortable.asc  .sa:before{content:'\f0de';}
table.a-t thead th.sortable.desc .sa:before{content:'\f0dd';}
table.a-t td.l{text-align:left;}
table.a-t td.r{text-align:right;}
table.a-t tbody tr:nth-child(even){background:#FFFCF6;}
table.a-t tbody tr:hover{background:#FDF2E0;}
table.a-t tfoot td{background:var(--a-ok);font-weight:bold;color:var(--a-ink);}

.pill{display:inline-block;padding:1px 8px;border-radius:9px;font-size:11px;line-height:17px;}
.p-ok{background:var(--a-ok);color:#6b4522;}
.p-warn{background:var(--a-acc);color:#fff;}
.p-bad{background:var(--a-bad);color:#fff;}
.btn-mini{height:24px;padding:0 8px;border:1px solid var(--a-line2);border-radius:4px;
  background:#fff;cursor:pointer;color:var(--a-ink);font-size:12px;}
.btn-mini:hover{background:var(--a-ok);}

.a-mask{display:none;position:fixed;top:0;left:0;right:0;bottom:0;width:100%;height:100%;
  background:rgba(60,40,20,.45);z-index:9999;overflow:auto;padding:34px 12px;}
.a-mask.show{display:block;}
.a-modal{background:#fff;border-radius:8px;width:1040px;max-width:100%;margin:0 auto;
  box-shadow:0 6px 30px rgba(0,0,0,.3);}
.a-modal.narrow{width:620px;}
.a-modal .m-head{background:var(--a-ok);color:var(--a-ink);padding:9px 14px;font-weight:bold;
  border-radius:8px 8px 0 0;display:flex;align-items:center;}
.a-modal .m-close{margin-left:auto;cursor:pointer;font-size:17px;}
.a-modal .m-body{padding:14px;max-height:70vh;overflow:auto;}
.a-modal .m-foot{padding:10px 14px;border-top:1px solid var(--a-line);text-align:right;}
.a-modal .m-foot button{height:32px;padding:0 14px;border:1px solid var(--a-line2);border-radius:4px;
  background:#fff;cursor:pointer;color:var(--a-ink);margin-left:5px;}
.a-modal .m-foot button.go{background:var(--a-acc);color:#fff;border-color:var(--a-acc-d);font-weight:bold;}

.stmt-head{border:1px solid var(--a-line);border-radius:6px;padding:10px 12px;margin-bottom:10px;background:var(--a-bg);}
.stmt-head table{width:100%;font-size:13px;color:var(--a-ink);}
.stmt-head td{padding:2px 4px;}
.stmt-total{margin-top:8px;text-align:right;font-size:14px;color:var(--a-ink);}
.stmt-total b{font-size:19px;color:var(--a-brand);}
.warn{background:#FBE3DC;color:#7a2c17;border-left:5px solid var(--a-bad);
  padding:8px 12px;border-radius:4px;font-size:13px;margin-bottom:8px;}
.info{background:var(--a-bg2);border-left:5px solid var(--a-acc);color:var(--a-ink);
  padding:8px 12px;border-radius:4px;font-size:13px;margin-bottom:8px;line-height:1.6;}

.a-msg{position:fixed;top:64px;right:18px;z-index:12000;min-width:250px;max-width:430px;
  padding:11px 15px;border-radius:6px;display:none;font-size:14px;box-shadow:0 3px 12px rgba(0,0,0,.2);}
.a-msg.ok{background:#F7E0BD;color:#5b3a1e;border-left:5px solid var(--a-acc);}
.a-msg.bad{background:#FBE3DC;color:#7a2c17;border-left:5px solid var(--a-bad);}
.a-noperm{border:1.5px solid var(--a-line);background:var(--a-bg);border-radius:8px;padding:26px;color:var(--a-ink);}
.a-hint{font-size:11.5px;color:var(--a-ink2);margin-top:5px;line-height:1.6;}

@media print{
  .a-bar,.a-pager,.a-stat,.nav_menu,.left_col,footer,.a-mask .m-head,.a-mask .m-foot{display:none !important;}
  .right_col{margin:0 !important;padding:0 !important;}
  table.a-t thead th{position:static;}
  .a-mask{position:static;background:none;padding:0;overflow:visible;}
  .a-modal{width:100%;box-shadow:none;}
  .a-modal .m-body{max-height:none;overflow:visible;padding:0;}
}
</style>
</head>
<body class="nav-sm">
<div class="container body">
<div class="main_container">
  <?php include '../partPage/sideAndTopBarMenu.html' ?>
  <div class="right_col" role="main">
    <div class="page-title" style="display:flex;align-items:center;flex-wrap:wrap;">
      <h2 style="margin:6px 0;"><i class="fa fa-truck" style="color:#8A5A2B;"></i> 應付對帳單
        <small style="color:#7d6242;">廠商加工費，一列＝廠商×發票年月；資料來源為製程移轉紀錄既有的廠商發票欄位</small></h2>
    </div>
    <div class="clearfix"></div>

    <!-- 身分識別帶：與應收頁用不同色系＋不同文字與箭頭，避免兩頁看起來一樣而誤操作 -->
    <div class="side-band">
      <i class="fa fa-arrow-circle-up"></i>
      <b>應付帳款</b><span class="sb-sub">錢出去 · 付給廠商</span>
      <a href="ar_statement.php" class="sb-switch" title="切換到應收對帳單">
        切換到 <b>應收帳款</b>（錢進來） <i class="fa fa-long-arrow-right"></i></a>
    </div>

<?php if (!$perms['canView']): ?>
    <div class="a-noperm">
      <h4><i class="fa fa-lock"></i> 無會計模組檢閱權限</h4>
      <p>請洽管理者於「使用者權限設定」指派「會計檢閱／會計登錄／會計管理員」角色。</p>
    </div>
<?php else: ?>
    <div class="a-bar">
      <label>發票年月</label>
      <input type="month" id="ymFrom"> ~ <input type="month" id="ymTo">
      <button id="btnThis">本月</button>
      <button id="btnPrev">上月</button>
      <input type="text" id="kw" placeholder="廠商編號／簡稱／全稱／統編" style="width:210px;">
      <label><input type="checkbox" id="onlyGap"> 只顯示資料有缺的</label>
      <button id="btnSearch" class="btn-warm"><i class="fa fa-search"></i> 查詢</button>
      <span class="a-role">目前角色：<b><?= htmlspecialchars($roleLbl) ?></b>
        <i class="fa fa-question-circle" id="btnRoleHelp" title="角色權限說明"></i></span>
    </div>

    <div class="a-bar" style="background:var(--a-bg2);">
      <button id="btnLookup" class="btn-warm"><i class="fa fa-search"></i> 單據快搜
        <span style="font-weight:normal;font-size:11.5px;">(廠商拿請款單來)</span></button>
      <button id="btnMonth"><i class="fa fa-calendar"></i> 帳款月份調整</button>
      <span style="width:1px;height:22px;background:var(--a-line);margin:0 4px;"></span>
      <button id="btnExport"><i class="fa fa-file-text-o"></i> 匯出彙總CSV</button>
      <button id="btnPrint"><i class="fa fa-print"></i> 列印／PDF</button>
    </div>
    <div class="a-hint" style="margin:-4px 0 8px;">
      <kbd>/</kbd> 或點「單據快搜」可用移轉單號／金額／製令／料號直接查任何一筆屬於哪個發票年月。
      點任一列的「對帳單」可看該廠商該月完整加工明細並列印，用來核對廠商寄來的請款單與發票。
    </div>

    <div class="a-stat">
      <div><span class="n" id="stGroups">—</span> <span class="l">廠商×月份</span></div>
      <div><span class="n" id="stCnt">—</span> <span class="l">加工筆數</span></div>
      <div class="sep"></div>
      <div><span class="n big" id="stAmt">—</span> <span class="l">加工費(未稅)</span></div>
      <div><span class="n" id="stTax">—</span> <span class="l">稅額</span></div>
      <div><span class="n big" id="stTotal">—</span> <span class="l">應付含稅</span></div>
      <div class="sep"></div>
      <div><span class="n bad" id="stGapN">—</span> <span class="l">廠商缺統編</span></div>
    </div>
    <div id="warnBox"></div>

    <div class="a-pager">
      <span id="pgInfo" style="margin-right:auto;color:#8a6d45;"></span>
      每頁 <select id="perPage"><option>5</option><option>10</option><option selected>20</option><option>50</option></select> 筆
      <span id="pgBtns"></span>
    </div>

    <div class="a-wrap">
      <table class="a-t" id="tbl">
        <thead><tr>
          <th class="sortable" data-sort="invoice_ym">發票年月<i class="sa"></i></th>
          <th class="sortable" data-sort="maker">廠商<i class="sa"></i></th>
          <th>統一編號</th><th>付款條件</th>
          <th class="sortable" data-sort="cnt">加工筆數<i class="sa"></i></th>
          <th>加工數量</th>
          <th class="sortable" data-sort="amount">加工費(未稅)<i class="sa"></i></th>
          <th>稅額</th>
          <th class="sortable" data-sort="total_amount">應付含稅<i class="sa"></i></th>
          <th>加工日期範圍</th><th>對帳單</th>
        </tr></thead>
        <tbody id="tbody"><tr><td colspan="11" style="padding:22px;color:#8a6d45;">載入中…</td></tr></tbody>
        <tfoot id="tfoot"></tfoot>
      </table>
    </div>
    <div class="a-hint">
      月份歸屬以資料本身的<b>廠商發票年月（invoice_ym）</b>為準；少數沒填的紀錄才退回用加工日期所在月份。
      加工費與稅額直接取自製程移轉紀錄，未做二次計算，因此可與 ERP 對得起來。
      廠商簡稱在廠商主檔找不到對應者會標示 <span class="pill p-bad">未對應主檔</span>。
      <b>材料／其他採購的應付尚未納入</b>——待「申請採購」模組有實際單據後再接進來，兩者會共用同一層付款沖帳。
    </div>
<?php endif; ?>
  </div>
  <?php include '../partPage/footer.html' ?>
</div>
</div>

<!-- 應付對帳單明細 -->
<div class="a-mask" id="mkStmt"><div class="a-modal">
  <div class="m-head"><i class="fa fa-file-text-o"></i>&nbsp;<span id="stTitle">應付對帳單</span>
    <span class="m-close" data-close="mkStmt">✕</span></div>
  <div class="m-body" id="stBody"></div>
  <div class="m-foot">
    <button data-close="mkStmt">關閉</button>
    <button id="btnStmtCsv"><i class="fa fa-file-text-o"></i> 匯出CSV</button>
    <button class="go" id="btnStmtPrint"><i class="fa fa-print"></i> 列印對帳單</button>
  </div>
</div></div>

<!-- 角色說明 -->
<div class="a-mask" id="mkRole"><div class="a-modal narrow">
  <div class="m-head">角色權限說明<span class="m-close" data-close="mkRole">✕</span></div>
  <div class="m-body" style="font-size:13.5px;color:#5b3a1e;line-height:1.9;">
    <b>管理者</b>：固定擁有全部權限。<br>
    <b>會計管理員／會計登錄</b>：可查詢與匯出應付對帳資料（本頁目前為對帳用途，不修改加工紀錄）。<br>
    <b>會計檢閱</b>：可查詢與匯出。<br>
    <span style="color:#8a6d45;font-size:12.5px;">
      加工費金額請於製程移轉／加工單價相關頁面維護，本頁不改動來源資料。<br>
      角色於「使用者權限設定」頁指派（模組 accounting）。</span>
  </div>
</div></div>

<div class="a-msg" id="msg"></div>

<script src="../../resource/js/jquery.min.js"></script>
<script src="../../resource/js/bootstrap.min.js"></script>
<script src="../../resource/js/fastclick.js"></script>
<script src="../../resource/js/nprogress.js"></script>
<script src="../../resource/js/custom.min.js"></script>
<script src="../../resource/js/eg_input_rules.js?v=<?= @filemtime(__DIR__.'/../../resource/js/eg_input_rules.js') ?: time() ?>"></script>
<?php $ACC_TOOL_SIDE = 'ap'; include '_acc_tools.php'; ?>
<script>
/* 版型的 #sidebar-menu 預設 visibility:hidden，必須在此恢復，否則整個左側欄不會出現 */
$(document).ready(function () {
    var $am = $('#sidebar-menu .nav.side-menu > li.active');
    if ($am.length) {
        $am.removeClass('active').find('ul.child_menu').hide();
        $am.find('li.current-page').removeClass('current-page');
    }
    $('#sidebar-menu').css('visibility', 'visible');
});

(function ($) {
'use strict';
var API = '../../src/store/Acc_API.php';
var rows = [], page = 1, perPage = 20, total = 0;
var sortBy = 'total_amount', sortDir = 'desc';
var curStmt = null;
var CSRF = '';

function esc(s){ return String(s==null?'':s).replace(/[&<>"']/g,function(c){
  return {'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#39;'}[c]; }); }
function nf(n){ return Math.round(Number(n)||0).toLocaleString('en-US'); }
function np(n){ var v=Number(n)||0; return String(parseFloat(v.toFixed(4))); }
function toast(m,bad){
  var $m=$('#msg').removeClass('ok bad').addClass(bad?'bad':'ok').html(m).stop(true,true).fadeIn(150);
  clearTimeout($m.data('t')); $m.data('t',setTimeout(function(){ $m.fadeOut(400); },bad?6500:3600));
}
function openMask(id){ $('#'+id).addClass('show'); }
function closeMask(id){ $('#'+id).removeClass('show'); }
$(document).on('click','[data-close]',function(){ closeMask($(this).data('close')); });
$(document).on('click','.a-mask',function(e){ if(e.target===this) $(this).removeClass('show'); });
$('#btnRoleHelp').on('click',function(){ openMask('mkRole'); });
function qs(o){ return Object.keys(o).map(function(k){ return k+'='+encodeURIComponent(o[k]); }).join('&'); }

(function(){
  var d=new Date(); d.setDate(1); d.setMonth(d.getMonth()-1);
  var m=d.getFullYear()+'-'+('0'+(d.getMonth()+1)).slice(-2);
  $('#ymFrom').val(m); $('#ymTo').val(m);
})();
$('#btnThis').on('click',function(){
  var d=new Date(), m=d.getFullYear()+'-'+('0'+(d.getMonth()+1)).slice(-2);
  $('#ymFrom').val(m); $('#ymTo').val(m); page=1; load();
});
$('#btnPrev').on('click',function(){
  var d=new Date(); d.setDate(1); d.setMonth(d.getMonth()-1);
  var m=d.getFullYear()+'-'+('0'+(d.getMonth()+1)).slice(-2);
  $('#ymFrom').val(m); $('#ymTo').val(m); page=1; load();
});

function filters(){
  return {ym_from:$('#ymFrom').val(), ym_to:$('#ymTo').val(), kw:$('#kw').val(),
          only_gap:$('#onlyGap').is(':checked')?1:0,
          sort:sortBy, dir:sortDir, per_page:perPage, page:page};
}

function load(){
  if(!$('#ymFrom').val()||!$('#ymTo').val()){ toast('請選擇發票年月', true); return; }
  $('#tbody').html('<tr><td colspan="11" style="padding:22px;color:#8a6d45;">計算中…</td></tr>');
  $('#tfoot').empty();
  $.post(API+'?action=ap_summary', filters(), function(r){
    if(!r.ok){ toast(esc(r.error||'查詢失敗'), true); return; }
    rows=r.rows||[]; total=r.total||0;
    var s=r.summary||{};
    $('#stGroups').text(nf(s.groups)); $('#stCnt').text(nf(s.cnt));
    $('#stAmt').text(nf(s.amount)); $('#stTax').text(nf(s.tax_amount));
    $('#stTotal').text(nf(s.total_amount)); $('#stGapN').text(nf(s.no_tax_id));
    var w='';
    if(s.not_in_master>0)
      w+='<div class="warn"><b>'+nf(s.not_in_master)+'</b> 組的廠商代號在廠商主檔找不到對應，'
        +'金額有計入但沒有統編與付款條件可核對。</div>';
    if(s.no_inv_date>0)
      w+='<div class="info"><b>'+nf(s.no_inv_date)+'</b> 筆加工紀錄沒有填發票日期，'
        +'這些是用加工日期所在月份歸戶的，與廠商實際發票月份可能有落差。</div>';
    $('#warnBox').html(w);
    render(s); renderPager();
  },'json').fail(function(){ toast('查詢失敗', true); });
}

function render(s){
  if(!rows.length){
    $('#tbody').html('<tr><td colspan="11" style="padding:22px;color:#8a6d45;">'
      +'此發票年月沒有應付資料。</td></tr>');
    return;
  }
  var h='';
  rows.forEach(function(r,i){
    var pay = (r.payment_method||'') + (r.net_days? ' / 月結'+r.net_days+'天':'');
    h+='<tr data-i="'+i+'">'
      +'<td>'+esc(r.invoice_ym)+'</td>'
      +'<td class="l">'+esc(r.maker_name)
        +(r.maker_id_no?' <span style="color:#a08a6a;font-size:11px;">'+esc(r.maker_id_no)+'</span>':'')
        +(r.in_master?'':' <span class="pill p-bad">未對應主檔</span>')
        +(r.inactive?' <span class="pill p-warn">已停用</span>':'')+'</td>'
      +'<td>'+(r.tax_id?esc(r.tax_id):'<span class="pill p-warn">缺統編</span>')+'</td>'
      +'<td>'+esc(pay||'—')+'</td>'
      +'<td class="r">'+nf(r.cnt)+(r.no_inv_date>0?' <span class="pill p-warn">'+r.no_inv_date+'筆無發票日</span>':'')+'</td>'
      +'<td class="r">'+nf(r.qty)+'</td>'
      +'<td class="r"><b>'+nf(r.amount)+'</b></td>'
      +'<td class="r">'+nf(r.tax_amount)+'</td>'
      +'<td class="r"><b style="color:#8A5A2B;">'+nf(r.total_amount)+'</b></td>'
      +'<td style="font-size:11.5px;">'+esc(r.date_range)+'</td>'
      +'<td><button class="btn-mini btn-stmt" data-i="'+i+'"><i class="fa fa-file-text-o"></i> 對帳單</button></td>'
      +'</tr>';
  });
  $('#tbody').html(h);
  $('#tfoot').html('<tr><td colspan="4">全部符合條件合計</td>'
    +'<td class="r">'+nf(s.cnt)+'</td><td></td>'
    +'<td class="r">'+nf(s.amount)+'</td><td class="r">'+nf(s.tax_amount)+'</td>'
    +'<td class="r">'+nf(s.total_amount)+'</td><td colspan="2"></td></tr>');
}

function renderPager(){
  var pages=Math.max(1,Math.ceil(total/perPage));
  if(page>pages) page=pages;
  var from=total?(page-1)*perPage+1:0, to=Math.min(page*perPage,total);
  $('#pgInfo').text('顯示 '+from+'–'+to+'，共 '+nf(total)+' 組');
  if(pages<=1 && total<=10){ $('#pgBtns').html(''); return; }
  var h='<button data-p="1" '+(page===1?'disabled':'')+'>«</button>'
      +'<button data-p="'+(page-1)+'" '+(page===1?'disabled':'')+'>‹</button>';
  var st=Math.max(1,page-2), en=Math.min(pages,st+4); st=Math.max(1,en-4);
  for(var p=st;p<=en;p++) h+='<button data-p="'+p+'" class="'+(p===page?'on':'')+'">'+p+'</button>';
  h+='<button data-p="'+(page+1)+'" '+(page===pages?'disabled':'')+'>›</button>'
   +'<button data-p="'+pages+'" '+(page===pages?'disabled':'')+'>»</button>';
  $('#pgBtns').html(h);
}
$(document).on('click','#pgBtns button',function(){
  var p=parseInt($(this).data('p'),10); if(p>=1){ page=p; load(); }
});
$('#perPage').on('change',function(){ perPage=parseInt(this.value,10)||20; page=1; load(); });
$('#btnSearch').on('click',function(){ page=1; load(); });
$('#kw').on('keydown',function(e){ if(e.key==='Enter'){ e.preventDefault(); page=1; load(); } });
$('#ymFrom,#ymTo,#onlyGap').on('change',function(){ page=1; load(); });

$(document).on('click','#tbl thead th.sortable',function(){
  var k=$(this).data('sort');
  if(sortBy!==k){ sortBy=k; sortDir='desc'; }
  else { sortDir=(sortDir==='desc')?'asc':'desc'; }
  $('#tbl thead th.sortable').removeClass('asc desc');
  $(this).addClass(sortDir);
  page=1; load();
});

/* 對帳單明細 */
$(document).on('click','.btn-stmt',function(){
  var r=rows[parseInt($(this).data('i'),10)];
  if(!r) return;
  curStmt={maker_id_no:r.maker_id_no, invoice_ym:r.invoice_ym};
  $('#stTitle').text('應付對帳單　'+r.maker_name+'　'+r.invoice_ym);
  $('#stBody').html('<div style="padding:16px;color:#8a6d45;">載入中…</div>');
  openMask('mkStmt');
  $.post(API+'?action=ap_detail', curStmt, function(res){
    if(!res.ok){ $('#stBody').html('<div style="padding:16px;color:#DD5138;">'+esc(res.error||'載入失敗')+'</div>'); return; }
    renderStmt(res);
  },'json').fail(function(){ $('#stBody').html('<div style="padding:16px;color:#DD5138;">載入失敗。</div>'); });
});

function renderStmt(d){
  var hd=d.head||{};
  var h='<div id="stmtPrint">';
  h+='<div style="text-align:center;font-size:20px;font-weight:bold;color:#5b3a1e;margin-bottom:8px;">'
    +'應付對帳單（加工費）</div>';
  if(!hd.in_master)
    h+='<div class="warn">此廠商代號在廠商主檔找不到對應，無法取得統一編號與付款條件。</div>';

  h+='<div class="stmt-head"><table>'
    +'<tr><td style="width:14%;">廠商</td><td style="width:36%;"><b>'+esc(hd.maker_full||hd.maker_name)+'</b>'
    +(hd.maker_id_no?'（'+esc(hd.maker_id_no)+'）':'')+'</td>'
    +'<td style="width:14%;">發票年月</td><td><b>'+esc(hd.invoice_ym)+'</b></td></tr>'
    +'<tr><td>統一編號</td><td>'+esc(hd.tax_id||'（未建）')+'</td>'
    +'<td>付款條件</td><td>'+esc(hd.payment_method||'—')
    +(hd.net_days?'　月結 '+esc(hd.net_days)+' 天':'')+'</td></tr>'
    +'<tr><td>地址</td><td colspan="3">'+esc(hd.address||'—')+'</td></tr>'
    +'<tr><td>製表日</td><td colspan="3">'+new Date().toISOString().slice(0,10)+'</td></tr>'
    +'</table></div>';

  h+='<table class="a-t"><thead><tr><th>#</th><th>加工日</th><th>移轉單號</th><th>製令</th>'
    +'<th>料號</th><th>製程</th><th>加工數量</th><th>損耗</th><th>單價</th><th>加工費</th>'
    +'<th>稅額</th><th>發票日期</th><th>備註</th></tr></thead><tbody>';
  (d.items||[]).forEach(function(i,n){
    h+='<tr><td>'+(n+1)+'</td><td>'+esc(i.d)+'</td><td>'+esc(i.transfer_no||'')+'</td>'
      +'<td>'+esc(i.bom)+'</td><td class="l">'+esc(i.product_id||'')+'</td>'
      +'<td>'+esc(i.process_name||'')+'</td>'
      +'<td class="r">'+nf(i.transfer_qty)+'</td>'
      +'<td class="r">'+(i.loss_qty>0?nf(i.loss_qty):'')+'</td>'
      +'<td class="r">'+np(i.unit_price)+'</td>'
      +'<td class="r">'+nf(i.process_amount)+'</td>'
      +'<td class="r">'+nf(i.tax_amount)+'</td>'
      +'<td>'+esc(i.inv_date||'—')+'</td>'
      +'<td class="l" style="font-size:11.5px;">'+esc((i.note||'').substr(0,18))+'</td></tr>';
  });
  h+='</tbody></table>';
  h+='<div class="stmt-total">'
    +'加工費（未稅）：<b>'+nf(d.amount)+'</b> 元　／　稅額：<b>'+nf(d.tax_amount)+'</b> 元　／　'
    +'應付含稅：<b style="color:#DD5138;">'+nf(d.total_amount)+'</b> 元</div>';
  h+='</div>';
  $('#stBody').html(h);
}

$('#btnStmtPrint').on('click',function(){
  var w=window.open('','_blank');
  w.document.write('<html><head><meta charset="utf-8"><title>應付對帳單</title>'
    +'<style>body{font-family:"Microsoft JhengHei",sans-serif;padding:16px;color:#333;}'
    +'table{width:100%;border-collapse:collapse;font-size:12px;}'
    +'th,td{border:1px solid #999;padding:3px 5px;text-align:center;}'
    +'th{background:#F7E0BD;}td.l{text-align:left;}td.r{text-align:right;}'
    +'.stmt-head{border:1px solid #ccc;padding:8px;margin-bottom:8px;}'
    +'.stmt-head table{border:0;}.stmt-head td{border:0;text-align:left;}'
    +'.stmt-total{margin-top:8px;text-align:right;font-size:14px;}'
    +'.warn{border-left:4px solid #DD5138;background:#FBE3DC;padding:6px 10px;margin-bottom:8px;}'
    +'</style></head><body>'+$('#stmtPrint').html()+'</body></html>');
  w.document.close(); w.focus(); setTimeout(function(){ w.print(); },350);
});
$('#btnStmtCsv').on('click',function(){
  if(!curStmt) return;
  window.location = API+'?action=ap_detail_export&'+qs(curStmt);
});
$('#btnExport').on('click',function(){
  var f=filters(); delete f.page; delete f.per_page;
  window.location = API+'?action=ap_export&'+qs(f);
});
$('#btnPrint').on('click',function(){ window.print(); });

/* ══ 共用工具：單據快搜 / 帳款月份調整 ══ */
AccTools.init({side:'ap', api:API, csrf:function(){ return CSRF; }, onChanged:load});
$('#btnLookup').on('click',function(){ AccTools.openLookup(); });
$('#btnMonth').on('click',function(){ AccTools.openMonth(); });
$(document).on('keydown',function(e){
  var tag=(e.target.tagName||'').toLowerCase();
  if(e.key==='/' && tag!=='input' && tag!=='select' && tag!=='textarea'){
    e.preventDefault(); AccTools.openLookup('');
  }
});

/* 取 CSRF（調整帳款月份要用）*/
$.getJSON(API,{action:'meta'},function(r){ if(r.ok) CSRF=r.csrf; });

load();
})(jQuery);
</script>
</body>
</html>
