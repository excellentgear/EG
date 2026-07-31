<?php
/**
 * views/ACC/payment.php — 付款與沖帳（應付側，與 receipt.php 對稱）
 *
 * 為什麼需要這一層：purchase_request.pay_status／pay_date 是單頭單一欄位，
 * 表達不了「月結廠商一次匯款付掉五張採購單」或「先付一半」。
 * 付款單＋沖帳明細（acc_payment / acc_payment_alloc）才做得到一對多與部分付款。
 *
 * 只處理**月結**採購單：現金／零用金採購不經會計（採購自己記零用金帳），
 * 判定與改判在「應付對帳單」頁，沖帳時若挑到現金單後端會直接擋下。
 *
 * 沖完會回寫 purchase_request.pay_status／pay_date——使用者已拍板：
 * 月結採購走會計，會計的沖帳結果就是付款狀態的唯一真相，採購頁只顯示結果。
 *
 * 三道守門（後端 acc_payment_alloc_save 內）：
 *   1) 沖帳總額不可超過付款金額
 *   2) 單張採購單不可超過其未付餘額
 *   3) 採購單廠商必須與付款單廠商一致（且不可是現金單）
 *
 * 資料一律走 src/store/Acc_API.php；權限 acc_lib.php（roles module='accounting'）
 */
session_start();
if (!isset($_SESSION['userName'])) {
    $_SESSION['lastpage'] = "../../views/ACC/payment.php";
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
         : ($perms['reconAp'] ? '應付對帳(生管)'
         : ($perms['canView'] ? '會計檢閱' : '無權限'))));
?>
<!DOCTYPE html>
<html lang="zh-Hant">
<head>
<meta charset="utf-8">
<meta http-equiv="X-UA-Compatible" content="IE=edge">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>付款與沖帳</title>
<link href="../../resource/css/bootstrap.css" rel="stylesheet">
<link href="../../resource/css/font-awesome.css" rel="stylesheet">
<link href="../../resource/css/nprogress.css" rel="stylesheet">
<link href="../../resource/css/custom.css" rel="stylesheet">
<style>
/* 應付＝暖棕赭（錢出去），與收款頁的琥珀橘明顯不同，避免兩頁看起來一樣而誤操作 */
:root{
  --a-line:#DFCBA9; --a-line2:#C9AE85; --a-bg:#FBF5EC; --a-bg2:#F6EBDA;
  --a-ink:#4E2C0B; --a-ink2:#7d6242; --a-brand:#7A4A1E;
  --a-acc:#A2703A; --a-acc-d:#8A5A2B; --a-ok:#E8D3B4; --a-bad:#C0392B;
}
#sidebar-menu{visibility:hidden;}
.right_col .page-title{margin:8px 0 4px;overflow:hidden;}

.side-band{display:flex;align-items:center;gap:10px;clear:both;margin-bottom:8px;color:#fff;
  border-radius:8px;padding:7px 14px;font-size:15px;box-shadow:inset 0 -3px 0 rgba(0,0,0,.14);
  background:linear-gradient(90deg,#8A5A2B 0%,#6E4520 100%);}
.side-band .sb-sub{font-size:12.5px;opacity:.92;font-weight:normal;}
.side-band .sb-lnk{margin-left:auto;display:flex;gap:6px;}
.side-band .sb-lnk a{font-size:13px;color:#fff;background:rgba(255,255,255,.18);
  border:1px solid rgba(255,255,255,.55);border-radius:14px;padding:4px 13px;
  text-decoration:none;white-space:nowrap;}
.side-band .sb-lnk a:hover{background:rgba(255,255,255,.34);color:#fff;text-decoration:none;}

.a-bar{display:flex;flex-wrap:wrap;gap:6px;align-items:center;clear:both;
  border:1.5px solid var(--a-line);border-radius:8px;padding:8px 10px;margin-bottom:8px;background:var(--a-bg);}
.a-bar label{margin:0;font-size:13px;color:var(--a-ink);font-weight:normal;}
.a-bar input[type=text],.a-bar input[type=date],.a-bar input[type=number],.a-bar select,.a-bar button{
  height:32px;font-size:13px;line-height:1;padding:0 10px;border:1px solid var(--a-line2);
  border-radius:4px;background:#fff;color:var(--a-ink);}
.a-bar button{cursor:pointer;}
.a-bar button:hover{background:var(--a-ok);}
.a-bar .btn-warm{background:var(--a-acc);color:#fff;border-color:var(--a-acc-d);font-weight:bold;}
.a-bar .btn-warm:hover{background:var(--a-acc-d);}
.a-role{margin-left:auto;font-size:13px;color:var(--a-ink);background:var(--a-ok);
  border-radius:12px;padding:5px 12px;white-space:nowrap;}
.a-role .fa-question-circle{cursor:pointer;color:#8a5a2b;margin-left:5px;}

.a-stat{display:flex;flex-wrap:wrap;gap:20px;align-items:center;margin-bottom:8px;
  border:1.5px solid var(--a-line);border-radius:8px;padding:9px 14px;background:var(--a-bg2);}
.a-stat .n{font-size:19px;font-weight:bold;color:var(--a-brand);}
.a-stat .n.big{font-size:23px;}
.a-stat .n.bad{color:var(--a-bad);}
.a-stat .l{font-size:12px;color:var(--a-ink2);}
.a-stat .sep{width:1px;height:30px;background:var(--a-line);}

.a-pager{display:flex;justify-content:flex-end;align-items:center;gap:6px;margin-bottom:4px;
  font-size:13px;color:var(--a-ink);}
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
table.a-t td.l{text-align:left;}
table.a-t td.r{text-align:right;}
table.a-t tbody tr:nth-child(even){background:#FDFAF4;}
table.a-t tbody tr:hover{background:#F6EBDA;}

.pill{display:inline-block;padding:1px 8px;border-radius:9px;font-size:11px;line-height:17px;}
.p-full{background:#5C7A2E;color:#fff;}
.p-part{background:var(--a-acc);color:#fff;}
.p-none{background:var(--a-bad);color:#fff;}
.btn-mini{height:24px;padding:0 8px;border:1px solid var(--a-line2);border-radius:4px;
  background:#fff;cursor:pointer;color:var(--a-ink);font-size:12px;}
.btn-mini:hover{background:var(--a-ok);}

.a-mask{display:none;position:fixed;top:0;left:0;right:0;bottom:0;width:100%;height:100%;
  background:rgba(60,40,20,.45);z-index:9999;overflow:auto;padding:34px 12px;}
.a-mask.show{display:block;}
.a-modal{background:#fff;border-radius:8px;width:940px;max-width:100%;margin:0 auto;
  box-shadow:0 6px 30px rgba(0,0,0,.3);}
.a-modal.narrow{width:620px;}
.a-modal .m-head{background:var(--a-ok);color:var(--a-ink);padding:9px 14px;font-weight:bold;
  border-radius:8px 8px 0 0;display:flex;align-items:center;}
.a-modal .m-close{margin-left:auto;cursor:pointer;font-size:17px;}
.a-modal .m-body{padding:14px;max-height:68vh;overflow:auto;}
.a-modal .m-foot{padding:10px 14px;border-top:1px solid var(--a-line);text-align:right;}
.a-modal .m-foot button{height:32px;padding:0 14px;border:1px solid var(--a-line2);border-radius:4px;
  background:#fff;cursor:pointer;color:var(--a-ink);margin-left:5px;}
.a-modal .m-foot button.go{background:var(--a-acc);color:#fff;border-color:var(--a-acc-d);font-weight:bold;}
.a-modal .m-foot button:disabled{opacity:.45;cursor:default;}

.frm{display:grid;grid-template-columns:100px 1fr 100px 1fr;gap:8px 10px;align-items:center;
  font-size:13px;color:var(--a-ink);}
.frm input,.frm select{height:31px;border:1px solid var(--a-line2);border-radius:4px;padding:0 8px;
  font-size:13px;color:var(--a-ink);width:100%;}
.frm .full{grid-column:2 / span 3;}
.frm label{margin:0;font-weight:normal;}
/* 錯誤即時顯示：紅框＋欄位旁寫「為什麼錯」 */
.frm input.bad,.frm select.bad{border-color:var(--a-bad);background:#FDECE7;}
.f-err{grid-column:2 / span 3;color:var(--a-bad);font-size:12px;margin:-4px 0 2px;min-height:16px;}

.alloc-in{width:110px;height:25px;text-align:right;border:1px solid var(--a-line2);
  border-radius:4px;font-size:12.5px;padding:0 5px;color:var(--a-ink);}
.alloc-in:focus{border-color:var(--a-acc);background:#FFFBF3;outline:none;}
.alloc-in.over{border-color:var(--a-bad);background:#FDECE7;}

.info{background:var(--a-bg2);border-left:5px solid var(--a-acc);color:var(--a-ink);
  padding:8px 12px;border-radius:4px;font-size:13px;margin-bottom:8px;line-height:1.6;}
.warn{background:#FBE3DC;color:#7a2c17;border-left:5px solid var(--a-bad);
  padding:8px 12px;border-radius:4px;font-size:13px;margin-bottom:8px;}

.a-msg{position:fixed;top:64px;right:18px;z-index:12000;min-width:250px;max-width:440px;
  padding:11px 15px;border-radius:6px;display:none;font-size:14px;box-shadow:0 3px 12px rgba(0,0,0,.2);}
.a-msg.ok{background:#E8D3B4;color:#4E2C0B;border-left:5px solid var(--a-acc);}
.a-msg.bad{background:#FBE3DC;color:#7a2c17;border-left:5px solid var(--a-bad);}
.a-noperm{border:1.5px solid var(--a-line);background:var(--a-bg);border-radius:8px;padding:26px;color:var(--a-ink);}
.a-hint{font-size:11.5px;color:var(--a-ink2);margin-top:5px;line-height:1.6;}

@media print{
  .a-bar,.a-pager,.a-mask,.nav_menu,.left_col,footer{display:none !important;}
  .right_col{margin:0 !important;padding:0 !important;}
  table.a-t thead th{position:static;}
}
</style>
</head>
<body class="nav-sm">
<div class="container body">
<div class="main_container">
  <?php include '../partPage/sideAndTopBarMenu.html' ?>
  <div class="right_col" role="main">
    <div class="page-title" style="display:flex;align-items:center;flex-wrap:wrap;">
      <h2 style="margin:6px 0;"><i class="fa fa-credit-card" style="color:#8A5A2B;"></i> 付款與沖帳
        <small style="color:#7d6242;">一筆付款可沖多張採購單、一張採購單可分次付；沒沖完的部分視為暫付款</small></h2>
    </div>
    <div class="clearfix"></div>

    <div class="side-band">
      <i class="fa fa-arrow-circle-up"></i>
      <b>應付帳款</b><span class="sb-sub">錢出去 · 付給廠商（只處理月結採購，現金／零用金不經會計）</span>
      <span class="sb-lnk">
        <a href="ap_statement.php">應付對帳單</a>
        <a href="receipt.php">收款與沖帳</a>
      </span>
    </div>

<?php if (!$perms['canView']): ?>
    <div class="a-noperm">
      <h4><i class="fa fa-lock"></i> 無會計模組檢閱權限</h4>
      <p>請洽管理者於「使用者權限設定」指派「會計檢閱／會計登錄／會計管理員」角色。</p>
    </div>
<?php else: ?>
    <div class="a-bar">
      <label>出帳日</label>
      <input type="date" id="dFrom" style="width:145px;"> ~ <input type="date" id="dTo" style="width:145px;">
      <input type="text" id="kw" placeholder="付款單號／廠商／銀行／支票號" style="width:230px;">
      <label><input type="checkbox" id="onlyUn"> 只看有暫付款的</label>
      <button id="btnSearch" class="btn-warm"><i class="fa fa-search"></i> 查詢</button>
      <span class="a-role">目前角色：<b><?= htmlspecialchars($roleLbl) ?></b>
        <i class="fa fa-question-circle" id="btnRoleHelp" title="角色權限說明"></i></span>
    </div>

    <div class="a-bar" style="background:var(--a-bg2);">
      <?php if ($perms['canEdit']): ?>
      <button id="btnNew" class="btn-warm"><i class="fa fa-plus"></i> 登錄付款</button>
      <?php endif; ?>
      <button id="btnExport"><i class="fa fa-file-text-o"></i> 匯出CSV</button>
      <button id="btnPrint"><i class="fa fa-print"></i> 列印／PDF</button>
      <a href="ap_statement.php" style="text-decoration:none;">
        <button type="button"><i class="fa fa-truck"></i> 應付對帳單</button></a>
      <a href="recon_overview.php" style="text-decoration:none;">
        <button type="button"><i class="fa fa-list-alt"></i> 對帳單總覽</button></a>
    </div>

    <div class="a-stat">
      <div><span class="n" id="stCount">—</span> <span class="l">筆付款</span></div>
      <div class="sep"></div>
      <div><span class="n big" id="stAmount">—</span> <span class="l">付款總額</span></div>
      <div><span class="n" id="stAlloc">—</span> <span class="l">已沖帳</span></div>
      <div><span class="n bad" id="stUnalloc">—</span> <span class="l">暫付款（未分配）</span></div>
      <div><span class="n" id="stFee">—</span> <span class="l">匯費</span></div>
    </div>

    <div class="a-pager">
      <span id="pgInfo" style="margin-right:auto;color:#7d6242;"></span>
      每頁 <select id="perPage"><option>5</option><option>10</option><option selected>20</option><option>50</option></select> 筆
      <span id="pgBtns"></span>
    </div>

    <div class="a-wrap">
      <table class="a-t" id="tbl">
        <thead><tr>
          <th>付款單號</th><th>出帳日</th><th>廠商</th><th>方式</th>
          <th>付款金額</th><th>匯費</th><th>已沖帳</th><th>暫付款</th><th>狀態</th>
          <th>銀行／票號</th><th>票期</th><th>備註</th><th>操作</th>
        </tr></thead>
        <tbody id="tbody"><tr><td colspan="13" style="padding:22px;color:#7d6242;">載入中…</td></tr></tbody>
      </table>
    </div>
    <div class="a-hint">
      「暫付款」＝錢付出去了但還沒指定要沖哪張採購單的金額，點<b>沖帳</b>去分配。
      沖帳時系統會擋下四種錯誤：沖帳總額超過付款金額、單張採購單超過其未付餘額、
      採購單廠商與付款單廠商不符、挑到現金／零用金採購單。
      沖完會自動回寫採購單的付款狀態（全額付清＝已付、部分＝未付），採購頁看到的就是這裡的結果。
    </div>
<?php endif; ?>
  </div>
  <?php include '../partPage/footer.html' ?>
</div>
</div>

<?php if ($perms['canEdit']): ?>
<!-- 付款單編輯 -->
<div class="a-mask" id="mkPy"><div class="a-modal narrow">
  <div class="m-head"><i class="fa fa-credit-card"></i>&nbsp;<span id="pyTitle">登錄付款</span>
    <span class="m-close" data-close="mkPy">✕</span></div>
  <div class="m-body">
    <div class="frm">
      <label>廠商 <span style="color:#C0392B;">*</span></label>
      <div class="full">
        <input type="text" id="pyVendor" list="vendList" placeholder="輸入或選擇廠商（有未付採購單的排前面）">
        <datalist id="vendList"></datalist>
      </div>
      <div class="f-err" id="errVendor"></div>
      <label>出帳日 <span style="color:#C0392B;">*</span></label>
      <input type="date" id="pyDate">
      <label>付款方式</label>
      <select id="pyMethod">
        <option>匯款</option><option>支票</option><option>現金</option><option>其他</option>
      </select>
      <div class="f-err" id="errDate"></div>
      <label>付款金額 <span style="color:#C0392B;">*</span></label>
      <input type="number" id="pyAmount" step="0.01" min="0">
      <label>匯費</label>
      <input type="number" id="pyFee" step="0.01" min="0" value="0">
      <div class="f-err" id="errAmount"></div>
      <label>銀行</label>
      <input type="text" id="pyBank">
      <label>支票號碼</label>
      <input type="text" id="pyCheckNo">
      <label>票期</label>
      <input type="date" id="pyCheckDue">
      <label>備註</label>
      <input type="text" id="pyNote" class="full" maxlength="200">
    </div>
    <div class="a-hint" style="margin-top:8px;">
      存檔後會直接接著開沖帳畫面。匯費是我方另外負擔的手續費，不影響沖帳金額。
      廠商要與採購單上的廠商一致，沖帳時才挑得到那些單。
    </div>
  </div>
  <div class="m-foot">
    <button data-close="mkPy">取消</button>
    <button class="go" id="btnPySave"><i class="fa fa-check"></i> 存檔</button>
  </div>
</div></div>

<!-- 沖帳 -->
<div class="a-mask" id="mkAl"><div class="a-modal">
  <div class="m-head"><i class="fa fa-link"></i>&nbsp;<span id="alTitle">沖帳</span>
    <span class="m-close" data-close="mkAl">✕</span></div>
  <div class="m-body">
    <div id="alHead"></div>
    <div class="a-bar" style="padding:6px 8px;">
      <button id="btnAlAuto">自動由舊到新填滿</button>
      <button id="btnAlClear">清空全部</button>
      <span id="alTally" style="margin-left:auto;font-size:13px;color:var(--a-ink);"></span>
    </div>
    <div id="alBox"></div>
  </div>
  <div class="m-foot">
    <button data-close="mkAl">取消</button>
    <button class="go" id="btnAlSave"><i class="fa fa-check"></i> 儲存沖帳</button>
  </div>
</div></div>
<?php endif; ?>

<!-- 角色說明 -->
<div class="a-mask" id="mkRole"><div class="a-modal narrow">
  <div class="m-head">角色權限說明<span class="m-close" data-close="mkRole">✕</span></div>
  <div class="m-body" style="font-size:13.5px;color:#4E2C0B;line-height:1.9;">
    <b>管理者</b>：固定擁有全部權限。<br>
    <b>會計管理員</b>：會計登錄的全部權限，另可<b>刪除付款單</b>（刪除後相關採購單付款狀態會重算）。<br>
    <b>會計登錄</b>：可登錄付款、執行沖帳。<br>
    <b>會計檢閱／應付對帳(生管)</b>：僅可查詢與匯出，不能付款。<br>
    <span style="color:#7d6242;font-size:12.5px;">角色於「使用者權限設定」頁指派（模組 accounting）。</span>
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
var CAN_EDIT  = <?= $perms['canEdit']  ? 'true' : 'false' ?>;
var CAN_ADMIN = <?= $perms['canAdmin'] ? 'true' : 'false' ?>;

var CSRF = '', rows = [], page = 1, perPage = 20, total = 0;
var vendors = [], alData = null;

function esc(s){ return String(s==null?'':s).replace(/[&<>"']/g,function(c){
  return {'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#39;'}[c]; }); }
function nf(n){ return Math.round(Number(n)||0).toLocaleString('en-US'); }
function toast(m,bad){
  var $m=$('#msg').removeClass('ok bad').addClass(bad?'bad':'ok').html(m).stop(true,true).fadeIn(150);
  clearTimeout($m.data('t')); $m.data('t',setTimeout(function(){ $m.fadeOut(400); },bad?7000:3800));
}
function openMask(id){ $('#'+id).addClass('show'); }
function closeMask(id){ $('#'+id).removeClass('show'); }
$(document).on('click','[data-close]',function(){ closeMask($(this).data('close')); });
$(document).on('click','.a-mask',function(e){ if(e.target===this) $(this).removeClass('show'); });
$('#btnRoleHelp').on('click',function(){ openMask('mkRole'); });
function qs(o){ return Object.keys(o).map(function(k){
  return k+'='+encodeURIComponent(o[k]==null?'':o[k]); }).join('&'); }

/* 預設近三個月 */
(function(){
  var t=new Date(), f=new Date(); f.setMonth(f.getMonth()-3);
  $('#dTo').val(t.toISOString().slice(0,10));
  $('#dFrom').val(f.toISOString().slice(0,10));
})();

$.getJSON(API,{action:'meta'},function(r){
  if(!r.ok){ toast(esc(r.error||'初始化失敗'), true); return; }
  CSRF=r.csrf;
  $.getJSON(API,{action:'ap_vendors'},function(vr){
    if(vr.ok){
      vendors = vr.vendors||[];
      $('#vendList').html(vendors.map(function(v){
        return '<option value="'+esc(v.vendor_name)+'">'+esc(v.vendor_id)
             + '　未付 '+nf(v.open)+'（'+v.cnt+' 張）</option>'; }).join(''));
    }
  });
  load();
}).fail(function(){ toast('無法連線到會計 API', true); });

function filters(){
  return {date_from:$('#dFrom').val(), date_to:$('#dTo').val(), kw:$('#kw').val(),
          only_unalloc:$('#onlyUn').is(':checked')?1:0, page:page, per_page:perPage};
}

function load(){
  $('#tbody').html('<tr><td colspan="13" style="padding:22px;color:#7d6242;">查詢中…</td></tr>');
  $.post(API+'?action=payment_list', filters(), function(r){
    if(!r.ok){ toast(esc(r.error||'查詢失敗'), true); return; }
    rows=r.rows||[]; total=r.total||0;
    var s=r.summary||{};
    $('#stCount').text(nf(s.count)); $('#stAmount').text(nf(s.amount));
    $('#stAlloc').text(nf(s.allocated)); $('#stUnalloc').text(nf(s.unallocated));
    $('#stFee').text(nf(s.fee));
    render(); renderPager();
  },'json').fail(function(){ toast('查詢失敗', true); });
}

function render(){
  if(!rows.length){
    $('#tbody').html('<tr><td colspan="13" style="padding:22px;color:#7d6242;">'
      +'此條件下沒有付款紀錄。'+(CAN_EDIT?'按「登錄付款」新增。':'')+'</td></tr>');
    return;
  }
  var h='';
  rows.forEach(function(v,i){
    var st = (v.unallocated<=0.005) ? '<span class="pill p-full">已全數沖帳</span>'
           : (v.allocated>0.005 ? '<span class="pill p-part">部分沖帳</span>'
                                : '<span class="pill p-none">未沖帳</span>');
    h+='<tr data-i="'+i+'" data-id="'+v.payment_id+'">'
      +'<td><b>'+esc(v.payment_no)+'</b></td>'
      +'<td>'+esc(v.pay_date)+'</td>'
      +'<td class="l">'+esc(v.vendor_name)
        +(v.vendor_id?' <span style="color:#a08a6a;font-size:11px;">'+esc(v.vendor_id)+'</span>':'')+'</td>'
      +'<td>'+esc(v.method||'')+'</td>'
      +'<td class="r"><b>'+nf(v.amount)+'</b></td>'
      +'<td class="r">'+(v.fee>0?nf(v.fee):'')+'</td>'
      +'<td class="r">'+nf(v.allocated)+'</td>'
      +'<td class="r">'+(v.unallocated>0.005?'<b style="color:#C0392B;">'+nf(v.unallocated)+'</b>':'')+'</td>'
      +'<td>'+st+'</td>'
      +'<td class="l">'+esc(v.bank||'')+(v.check_no?' / '+esc(v.check_no):'')+'</td>'
      +'<td>'+esc(v.check_due||'')+'</td>'
      +'<td class="l" style="font-size:11.5px;">'+esc((v.note||'').substr(0,18))+'</td>'
      +'<td>'
        +(CAN_EDIT?'<button class="btn-mini py-alloc" data-id="'+v.payment_id+'">沖帳'
          +(v.alloc_cnt?'('+v.alloc_cnt+')':'')+'</button> '
          +'<button class="btn-mini py-edit" data-i="'+i+'">修改</button> ':'')
        +(CAN_ADMIN?'<button class="btn-mini py-del" data-id="'+v.payment_id+'">刪除</button>':'')
      +'</td></tr>';
  });
  $('#tbody').html(h);
}

function renderPager(){
  var pages=Math.max(1,Math.ceil(total/perPage));
  if(page>pages) page=pages;
  var from=total?(page-1)*perPage+1:0, to=Math.min(page*perPage,total);
  $('#pgInfo').text('顯示 '+from+'–'+to+'，共 '+nf(total)+' 筆');
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
$('#dFrom,#dTo,#onlyUn').on('change',function(){ page=1; load(); });
$('#btnExport').on('click',function(){
  var f=filters(); delete f.page; delete f.per_page;
  window.location = API+'?action=payment_export&'+qs(f);
});
$('#btnPrint').on('click',function(){ window.print(); });

/* ══ 付款單編輯 ══ */
if(CAN_EDIT){
  var editId = 0;

  /* 錯誤即時偵測：輸入當下就驗，紅框＋欄位旁寫「為什麼錯」，不等送出才報 */
  function setErr($el, id, msg){
    $el.toggleClass('bad', !!msg);
    $('#'+id).text(msg||'');
    return !msg;
  }
  function vVendor(){
    var v=$.trim($('#pyVendor').val());
    if(v==='') return setErr($('#pyVendor'),'errVendor','請填廠商；沖帳時只挑得到這家廠商的採購單');
    return setErr($('#pyVendor'),'errVendor','');
  }
  function vDate(){
    var v=$('#pyDate').val();
    if(!v) return setErr($('#pyDate'),'errDate','請填出帳日');
    if(!/^\d{4}-\d{2}-\d{2}$/.test(v)) return setErr($('#pyDate'),'errDate','日期格式要是 YYYY-MM-DD');
    return setErr($('#pyDate'),'errDate','');
  }
  function vAmount(){
    var raw=$.trim($('#pyAmount').val());
    if(raw==='')          return setErr($('#pyAmount'),'errAmount','請填付款金額');
    var v=parseFloat(raw);
    if(isNaN(v))          return setErr($('#pyAmount'),'errAmount','付款金額只能填數字');
    if(v<=0)              return setErr($('#pyAmount'),'errAmount','付款金額必須大於 0');
    return setErr($('#pyAmount'),'errAmount','');
  }
  $('#pyVendor').on('input change', vVendor);
  $('#pyDate').on('input change', vDate);
  $('#pyAmount').on('input change', vAmount);

  function openPy(v){
    editId = v ? Number(v.payment_id) : 0;
    $('#pyTitle').text(v ? ('修改付款單 '+v.payment_no) : '登錄付款');
    $('#pyVendor').val(v?v.vendor_name:'').data('vid', v?(v.vendor_id||''):'');
    $('#pyDate').val(v?v.pay_date:new Date().toISOString().slice(0,10));
    $('#pyMethod').val(v?(v.method||'匯款'):'匯款');
    $('#pyAmount').val(v?v.amount:'');
    $('#pyFee').val(v?v.fee:0);
    $('#pyBank').val(v?(v.bank||''):'');
    $('#pyCheckNo').val(v?(v.check_no||''):'');
    $('#pyCheckDue').val(v?(v.check_due||''):'');
    $('#pyNote').val(v?(v.note||''):'');
    $('.f-err').text(''); $('#mkPy .frm input,#mkPy .frm select').removeClass('bad');
    openMask('mkPy');
    setTimeout(function(){ $('#pyVendor').focus(); },80);
  }
  $('#btnNew').on('click',function(){ openPy(null); });
  $(document).on('click','.py-edit',function(){ openPy(rows[parseInt($(this).data('i'),10)]); });

  $('#btnPySave').on('click',function(){
    var okAll = [vVendor(), vDate(), vAmount()].every(Boolean);
    if(!okAll){ $('#mkPy .frm .bad').first().focus(); return; }
    var name=$.trim($('#pyVendor').val());
    var vid='';
    vendors.forEach(function(v){ if(v.vendor_name===name) vid=v.vendor_id; });
    var d={payment_id:editId, vendor_id:vid, vendor_name:name,
           pay_date:$('#pyDate').val(), method:$('#pyMethod').val(),
           amount:parseFloat($('#pyAmount').val())||0, fee:parseFloat($('#pyFee').val())||0,
           bank:$.trim($('#pyBank').val()), check_no:$.trim($('#pyCheckNo').val()),
           check_due:$('#pyCheckDue').val(), note:$.trim($('#pyNote').val()), csrf:CSRF};
    var $b=$(this).prop('disabled',true).html('<i class="fa fa-spinner fa-spin"></i> 存檔中…');
    $.post(API+'?action=payment_save', d, function(r){
      $b.prop('disabled',false).html('<i class="fa fa-check"></i> 存檔');
      if(!r.ok){ toast(esc(r.error||'存檔失敗'), true); return; }
      closeMask('mkPy'); toast(esc(r.message)); load();
      if(!editId && r.payment_id) setTimeout(function(){ openAlloc(r.payment_id); },400);
    },'json').fail(function(x){
      $b.prop('disabled',false).html('<i class="fa fa-check"></i> 存檔');
      var m='存檔失敗'; try{ m=JSON.parse(x.responseText).error||m; }catch(e){}
      toast(esc(m), true);
    });
  });

  /* ══ 沖帳 ══ */
  function openAlloc(pid){
    $('#alHead').html(''); $('#alBox').html('<div style="padding:14px;color:#7d6242;">載入中…</div>');
    $('#alTally').text('');
    openMask('mkAl');
    $.post(API+'?action=payment_alloc_options',{payment_id:pid},function(r){
      if(!r.ok){ $('#alBox').html('<div class="warn">'+esc(r.error||'載入失敗')+'</div>'); return; }
      alData=r;
      var p=r.payment;
      $('#alTitle').text('沖帳　'+p.payment_no+'　'+p.vendor_name);
      $('#alHead').html('<div class="info">付款 <b>'+nf(p.amount)+'</b> 元（'+esc(p.pay_date)
        +'　'+esc(p.method||'')+'）　廠商 <b>'+esc(p.vendor_name)+'</b><br>'
        +'把金額填到下面各張採購單的「本次沖帳」欄；沒填完的部分會留作暫付款。'
        +'全額沖完的採購單會自動標記為已付。</div>');
      renderAlloc();
    },'json').fail(function(){ $('#alBox').html('<div class="warn">載入失敗</div>'); });
  }
  $(document).on('click','.py-alloc',function(){ openAlloc($(this).data('id')); });

  function renderAlloc(){
    var ps=alData.purchases||[], allocs=alData.allocs||[];
    var cur={};
    allocs.forEach(function(a){ cur[a.req_id]=Number(a.amount)||0; });

    if(!ps.length){
      $('#alBox').html('<div class="warn">這家廠商目前沒有未付完的月結採購單。<br>'
        +'可能原因：採購單還沒下單（詢價／簽核中不算應付）、已經付清、'
        +'或被判定成現金／零用金（到「應付對帳單」頁按「現金／零用金（未列入）」可查看並改判）。</div>');
      updateTally(); return;
    }
    var h='<table class="a-t"><thead><tr><th>採購單號</th><th>標題</th><th>帳款月份</th><th>發票號碼</th>'
        +'<th>含稅總額</th><th>已付(其他)</th><th>可沖額度</th><th>本次沖帳</th></tr></thead><tbody>';
    ps.forEach(function(v,i){
      var other=Number(v.paid_amt)-Number(v.this_paid);
      h+='<tr><td><b>'+esc(v.req_no)+'</b></td>'
        +'<td class="l">'+esc((v.title||'').substr(0,18))+'</td>'
        +'<td>'+esc(v.billing_month)+'</td>'
        +'<td>'+esc(v.invoice_no||'—')+'</td>'
        +'<td class="r">'+nf(v.grand_total)+'</td>'
        +'<td class="r">'+(Math.abs(other)>0.005?nf(other):'')+'</td>'
        +'<td class="r"><b>'+nf(v.available)+'</b></td>'
        +'<td><input type="number" class="alloc-in" data-i="'+i+'" data-id="'+v.req_id+'" step="0.01" value="'
          +(cur[v.req_id]!==undefined?cur[v.req_id]:'')+'"></td></tr>';
    });
    $('#alBox').html(h+'</tbody></table>');
    updateTally();
  }

  function updateTally(){
    var p=alData.payment, sum=0, over=false;
    $('.alloc-in').each(function(){
      var v=parseFloat(this.value)||0;
      var i=parseInt($(this).data('i'),10);
      var av=Number((alData.purchases[i]||{}).available)||0;
      var bad=(v>av+0.005) || (v<-0.005);
      $(this).toggleClass('over', bad);
      if(bad) over=true;
      sum+=v;
    });
    var left=Math.round((Number(p.amount)-sum)*100)/100;
    var msg='已分配 <b>'+nf(sum)+'</b>　／　付款 '+nf(p.amount);
    if(left>0.005)       msg+='　／　暫付款 <b style="color:#C0392B;">'+nf(left)+'</b>';
    else if(left<-0.005) msg+='　／　<b style="color:#C0392B;">超過付款金額 '+nf(-left)+'</b>';
    if(over) msg+='　／　<b style="color:#C0392B;">有欄位超過可沖額度</b>';
    $('#alTally').html(msg);
    $('#btnAlSave').prop('disabled', over || left<-0.005);
  }
  $(document).on('input','.alloc-in', updateTally);

  $('#btnAlAuto').on('click',function(){
    if(!alData) return;
    var left=Number(alData.payment.amount)||0;
    $('.alloc-in').each(function(){
      var i=parseInt($(this).data('i'),10), av=Number(alData.purchases[i].available)||0;
      if(av<=0 || left<=0.005){ this.value=''; return; }
      var take=Math.min(av,left);
      this.value=Math.round(take*100)/100;
      left-=take;
    });
    updateTally();
  });
  $('#btnAlClear').on('click',function(){ $('.alloc-in').val(''); updateTally(); });

  $('#btnAlSave').on('click',function(){
    var allocs=[];
    $('.alloc-in').each(function(){
      var v=parseFloat(this.value);
      if(!isNaN(v) && Math.abs(v)>0.005) allocs.push({req_id:Number($(this).data('id')), amount:v});
    });
    var $b=$(this).prop('disabled',true).html('<i class="fa fa-spinner fa-spin"></i> 儲存中…');
    $.post(API+'?action=payment_alloc_save',
      {csrf:CSRF, payment_id:alData.payment.payment_id, allocs:JSON.stringify(allocs)},
      function(r){
        $b.prop('disabled',false).html('<i class="fa fa-check"></i> 儲存沖帳');
        if(!r.ok){ toast(esc(r.error||'沖帳失敗'), true); return; }
        closeMask('mkAl'); toast(esc(r.message)); load();
      },'json').fail(function(x){
        $b.prop('disabled',false).html('<i class="fa fa-check"></i> 儲存沖帳');
        var m='沖帳失敗'; try{ m=JSON.parse(x.responseText).error||m; }catch(e){}
        toast(esc(m), true);
      });
  });
}

/* 刪除（僅會計管理員） */
if(CAN_ADMIN){
  $(document).on('click','.py-del',function(){
    var id=$(this).data('id');
    if(!confirm('確定刪除這筆付款單？其沖帳明細會一併刪除，相關採購單的付款狀態會重算回未付。')) return;
    $.post(API+'?action=payment_delete',{csrf:CSRF, payment_id:id},function(r){
      if(!r.ok){ toast(esc(r.error||'刪除失敗'), true); return; }
      toast(esc(r.message)); load();
    },'json').fail(function(){ toast('刪除失敗', true); });
  });
}
})(jQuery);
</script>
</body>
</html>
