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
.sd-pill{display:inline-block;padding:2px 12px;border-radius:12px;color:#fff;font-size:12.5px;font-weight:bold;line-height:18px;}
.sd-ar{background:#5C7A2E;} .sd-ap{background:#DD5138;} td.amt-ap{color:#DD5138;font-weight:bold;}
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
      <a class="page-help-btn" href="invoice_export.php" target="_blank" rel="noopener" style="margin-left:auto;margin-right:8px;line-height:28px;text-decoration:none;"><i class="fa fa-file-text-o"></i> 開發票</a>
      <a class="page-help-btn" href="receipt.php" target="_blank" rel="noopener" style="margin-left:0;margin-right:8px;line-height:28px;text-decoration:none;"><i class="fa fa-money"></i> 收款沖帳</a>
      <a class="page-help-btn" href="payment.php" target="_blank" rel="noopener" style="margin-left:0;margin-right:8px;line-height:28px;text-decoration:none;"><i class="fa fa-credit-card"></i> 付款沖帳</a>
      <button class="page-help-btn" id="btnHelp" style="margin-left:0;"><i class="fa fa-question-circle"></i> 使用說明</button>
    </div>
    <div class="clearfix"></div>
<?php if (!$perms['canView']): ?>
    <div class="a-noperm"><h4><i class="fa fa-lock"></i> 無會計模組檢閱權限</h4>
      <p>請洽管理者於「使用者權限設定」指派會計角色。</p></div>
<?php else: ?>
    <div class="a-tabs">
      <button class="tab-btn on" data-tab="new"><i class="fa fa-plus-square-o"></i> 1. 開立傳票</button>
      <button class="tab-btn" data-tab="list"><i class="fa fa-search"></i> 2. 傳票查詢</button>
      <?php if ($perms['canEdit']): ?><button class="tab-btn" data-tab="man"><i class="fa fa-pencil-square-o"></i> 3. 訂單預收／無單據</button><?php endif; ?>
      <?php if ($perms['canEdit']): ?><button id="btnAcctCfg" style="align-self:center;"><i class="fa fa-cog"></i> 科目設定</button><?php endif; ?>
      <?php if ($perms['canEdit']): ?><button id="btnCoAcct" style="align-self:center;"><i class="fa fa-bank"></i> 本公司入帳帳戶</button><?php endif; ?>
      <span class="a-role" style="align-self:center;">目前角色：<b><?= htmlspecialchars($roleLbl) ?></b></span>
    </div>

    <div id="tabNew">
      <div class="a-bar">
        <label>對象</label>
        <select id="vSide"><option value="ar">客戶（應收）</option><option value="ap">廠商（應付）</option></select>
        <label>帳款月份</label><input type="month" id="vBm">
        <button id="btnVThis">本月</button><button id="btnVPrev">上月</button>
        <button id="btnVParties" class="btn-warm"><i class="fa fa-refresh"></i> 載入對象</button>
        <label>客戶／廠商</label>
        <select id="vParty" data-eg-filter="輸入名稱或代號篩選…" style="min-width:260px;"><option value="">（請先載入）</option></select>
        <button id="btnVLoad"><i class="fa fa-list"></i> 列出明細</button>
      </div>
      <div id="vInfo"></div>
      <div id="vBox" style="display:none;">
        <div class="a-bar" style="background:var(--a-bg2);">
          <label>傳票日期<span style="color:#DD5138;">＊</span></label><input type="date" id="vDate">
          <label>ERP傳票號碼<span style="color:#DD5138;">＊</span></label><input type="text" id="vNo" maxlength="12" class="vno-in" autocomplete="off" data-eg-hint="完整 V-1151126001；只輸入 1~3 碼＝流水號（年月日取自傳票日期）；也可只輸入 MMDD＋流水3碼（1126001）或 民國年MMDD流水3碼，自動補 V- 與民國年">
          <span id="vNoErr" style="color:#DD5138;font-size:12.5px;"></span>
          <label>備註</label><input type="text" id="vNote" maxlength="200" style="width:200px;">
          <button id="btnVAll">全選可開立</button><button id="btnVNone">清除</button>
          <?php if ($perms['canEdit']): ?>
          <button id="btnVCreate" class="btn-warm" disabled><i class="fa fa-book"></i> 開立傳票</button>
          <?php endif; ?>
          <span style="margin-left:auto;font-size:13px;">已選 <b id="vSelN" style="color:var(--a-bad);">0</b> 筆　合計 <b id="vSelAmt">0</b></span>
        </div>
        <div class="a-wrap" style="max-height:60vh;overflow:auto;">
          <table class="a-t"><thead><tr><th style="width:30px;"><input type="checkbox" id="vCkAll"></th><th>類型</th><th>單號</th><th>日期</th>
            <th id="vColPid">料號</th><th>品名規格</th><th>數量</th><th>單價</th><th>金額</th><th>發票</th><th>傳票</th></tr></thead>
            <tbody id="vBody"></tbody></table>
        </div>
      </div>
    </div>

    <div id="tabMan" style="display:none;">
      <div class="a-bar">
        <label><input type="radio" name="mKind" value="order" checked> 依訂單開立（預收訂金）</label>
        <label><input type="radio" name="mKind" value="free"> 無單據開立（例：賣廢料）</label>
      </div>
      <div id="mOrderPick">
        <div class="a-bar"><label>搜尋訂單</label>
          <input type="text" id="mKw" placeholder="客戶ID／客戶名稱／訂單編號／料號／客戶單號（可空白分隔多個關鍵字）" style="width:420px;" autocomplete="off">
          <button id="btnMSearch" class="btn-warm"><i class="fa fa-search"></i> 搜尋</button>
          <span id="mHit" style="color:#8a6d45;font-size:12.5px;"></span></div>
        <div class="a-wrap" style="max-height:260px;overflow:auto;"><table class="a-t"><thead><tr><th>客戶</th><th>訂單編號</th><th>客戶單號</th><th>料號</th><th>數量</th><th>訂單日</th><th>已預收</th><th>客戶溢繳</th><th></th></tr></thead>
          <tbody id="mBody"><tr><td colspan="9" style="padding:14px;color:#8a6d45;">輸入關鍵字搜尋訂單</td></tr></tbody></table></div>
        <div id="mPicked" class="info" style="display:none;margin-top:6px;"></div>
      </div>
      <div id="mFreeParty" class="a-bar" style="display:none;"><label>對象（選填）</label><input type="text" id="mParty" maxlength="60" style="width:260px;" placeholder="客戶／廠商名稱（可空白）" autocomplete="off"></div>
      <div id="mForm" style="display:none;margin-top:8px;">
        <div class="a-bar">
          <label>傳票日期<span style="color:#DD5138;">＊</span></label><input type="date" id="mDate">
          <label>ERP傳票號碼<span style="color:#DD5138;">＊</span></label><input type="text" id="mNo" maxlength="12" class="vno-in" autocomplete="off" data-eg-hint="完整 V-1151126001；或只輸入流水號 1~3 碼（年月日取自傳票日期）">
          <span id="mNoErr" style="color:#DD5138;font-size:12.5px;"></span>
          <label>備註</label><input type="text" id="mNote" maxlength="300" style="width:360px;">
        </div>
        <table class="a-t" style="max-width:900px;"><thead><tr><th style="width:120px;">會計科目</th><th>科目名稱</th><th style="width:90px;">借貸</th><th style="width:130px;">金額</th><th style="width:200px;">摘要</th><th style="width:60px;"></th></tr></thead>
          <tbody id="mLines"></tbody>
          <tfoot><tr><td colspan="3" style="text-align:right;font-weight:700;">借方 <span id="mD">0</span>　貸方 <span id="mC">0</span></td><td colspan="3" id="mBal"></td></tr></tfoot></table>
        <div class="a-bar" style="margin-top:6px;"><button id="btnMAdd"><i class="fa fa-plus"></i> 加一列</button>
          <span id="mErr" style="color:#DD5138;font-size:12.5px;"></span>
          <button id="btnMCreate" class="btn-warm" style="margin-left:auto;" disabled><i class="fa fa-book"></i> 開立傳票</button></div>
      </div>
    </div>
    <div id="tabList" style="display:none;">
      <div class="a-bar">
        <label>帳款月份</label><input type="month" id="lFrom"> ~ <input type="month" id="lTo">
        <select id="lSide"><option value="">應收＋應付</option><option value="ar">客戶（應收）</option><option value="ap">廠商（應付）</option><option value="order">訂單預收</option><option value="free">無單據</option></select>
        <select id="lStatus"><option value="">全部狀態</option><option value="open">有效</option><option value="void">已作廢</option></select>
        <label><input type="checkbox" id="lNoNo"> 只看尚未填傳票號碼</label>
        <input type="text" id="lKw" placeholder="傳票號碼／客戶(含編號)／單號／料號／發票號碼" style="width:260px;">
      </div>
      <div class="a-pager"><span id="lInfo" style="margin-right:auto;color:#8a6d45;"></span>
        每頁 <select id="lPer"><option>5</option><option>10</option><option selected>20</option><option>50</option></select> 筆 <span id="lPg"></span></div>
      <div class="a-wrap"><table class="a-t"><thead><tr><th>傳票號碼</th><th>傳票日期</th><th>類別</th><th>對象</th><th>帳款月份</th>
        <th>金額</th><th>發票號碼</th><th>狀態</th><th>備註</th><th>操作</th></tr></thead><tbody id="lBody"></tbody></table></div>
    </div>
<?php endif; ?>
  </div>
</div>
</div>

<div class="a-mask" id="mkAtt"><div class="a-modal" style="width:520px;max-width:96vw;">
  <div class="m-head"><i class="fa fa-paperclip"></i>&nbsp;發票附件<span class="m-close" data-close="mkAtt">✕</span></div>
  <div class="m-body" id="attBody"></div>
  <div class="m-foot"><button data-close="mkAtt">關閉</button></div>
</div></div>
<div class="a-mask" id="mkJ"><div class="a-modal" style="width:900px;max-width:96vw;">
  <div class="m-head"><i class="fa fa-book"></i>&nbsp;<span id="jTitle">確認傳票</span><span class="m-close" data-close="mkJ">✕</span></div>
  <div class="m-body">
    <div id="jHead" class="info" style="margin-bottom:8px;"></div>
    <div style="font-weight:700;color:#5b3a1e;margin-bottom:6px;"><i class="fa fa-book"></i> 會計分錄　<span style="font-weight:400;font-size:12.5px;color:#8a6d45;">依勾選明細自動帶出（科目可直接點選修改）；匯費、手續費等請在下方加入科目，借貸方向依科目代號首碼自動判定（1／5／6＝借方，2／3／4＝貸方）</span></div>
    <input type="hidden" id="jRound" value="round">
    <div style="margin-bottom:6px;font-size:12.5px;color:#8a6d45;">金額一律逐列四捨五入至整數，稅額以四捨五入後的未稅金額計算</div>
    <div id="jExp" class="info" style="display:none;margin-bottom:6px;"></div>
    <table class="a-t" id="tblJ"><thead><tr><th style="width:110px;">會計科目</th><th>科目名稱</th><th style="width:210px;">摘要</th><th style="width:130px;">借方金額</th><th style="width:130px;">貸方金額</th><th style="width:60px;"></th></tr></thead>
      <tbody id="jBody"></tbody>
      <tfoot><tr><td colspan="3"style="text-align:right;font-weight:700;">合計</td><td class="r" id="jDsum" style="font-weight:700;">0</td><td class="r" id="jCsum" style="font-weight:700;">0</td><td id="jBal"></td></tr></tfoot></table>
    <div class="a-bar" style="margin-top:6px;">
      <input type="text" id="jCode" list="dlAcct" maxlength="10" placeholder="科目代號" style="width:110px;" autocomplete="off">
      <input type="text" id="jName" readonly tabindex="-1" data-eg-skip placeholder="科目名稱（輸入代號自動帶入）" style="width:200px;background:#f3ece0;cursor:default;" autocomplete="off">
      <input type="text" id="jAmt" placeholder="金額" style="width:110px;text-align:right;" autocomplete="off">
      <select id="jSide" style="display:none;"><option value="D">借方</option><option value="C">貸方</option></select>
      <input type="text" id="jMemo" maxlength="100" placeholder="備註（選填）" style="width:150px;" autocomplete="off">
      <button id="btnJAdd"><i class="fa fa-plus"></i> 加入科目</button>
      <span id="jErr" style="color:#DD5138;font-size:12.5px;"></span>
    </div>
    <datalist id="dlAcct"></datalist>
    <div id="vSigns" style="display:flex;margin-top:8px;border:1px solid var(--a-line2);"></div>
  </div>
  <div class="m-foot"><button data-close="mkJ">取消</button><button class="go" id="btnJConfirm"><i class="fa fa-check"></i> 確定開立</button></div>
</div></div>
<div class="a-mask" id="mkCoAcct"><div class="a-modal" style="width:1100px;max-width:96vw;">
  <div class="m-head"><i class="fa fa-bank"></i>&nbsp;本公司入帳帳戶（收款登錄時匯款要選的帳戶）<span class="m-close" data-close="mkCoAcct">✕</span></div>
  <div class="m-body">
    <div class="a-hint" style="margin-bottom:8px;">每個帳戶要有設定編號、名稱、銀行、帳號；「對應科目」是收款傳票借方的銀行存款科目（例 1102600 台新銀行）。可指定一個預設帳戶，收款時自動帶入。<?php if (!$perms['canAdmin']): ?><b>只有會計管理員可以修改。</b><?php endif; ?></div>
    <table class="a-t" id="coTbl"><thead><tr><th style="width:90px;">設定編號</th><th>名稱</th><th>銀行</th><th>帳號</th><th style="width:110px;">對應科目</th><th>備註</th><th style="width:50px;">預設</th><th style="width:50px;">啟用</th><th style="width:130px;"></th></tr></thead><tbody id="coBody"></tbody></table>
  </div>
  <div class="m-foot"><button data-close="mkCoAcct">關閉</button></div>
</div></div>
<div class="a-mask" id="mkAcct"><div class="a-modal" style="width:1180px;max-width:96vw;">
  <div class="m-head"><i class="fa fa-cog"></i>&nbsp;預設會計科目<span class="m-close" data-close="mkAcct">✕</span></div>
  <div class="m-body" id="acctBody" style="overflow-x:hidden;"></div>
  <div class="m-foot"><button data-close="mkAcct">關閉</button><?php if ($perms['canAdmin']): ?><button class="go" id="btnAcctSave"><i class="fa fa-check"></i> 儲存預設科目</button><?php endif; ?><?php if ($perms['canAdmin']): ?><button class="go" id="btnAcctListSave"><i class="fa fa-check"></i> 儲存科目表</button><?php endif; ?></div>
</div></div>
<div class="a-mask" id="mkV"><div class="a-modal">
  <div class="m-head"><i class="fa fa-book"></i>&nbsp;<span id="mvTitle">傳票</span><span class="m-close" data-close="mkV">✕</span></div>
  <div class="m-body" id="mvBody"></div>
  <div class="m-foot" id="mvFoot"></div>
</div></div>
<div class="a-mask" id="mkHelp"><div class="a-modal narrow">
  <div class="m-head"><i class="fa fa-question-circle"></i>&nbsp;使用說明<span class="m-close" data-close="mkHelp">✕</span></div>
  <div class="m-body help-doc">
    <h5>流程</h5>開完發票後：選對象與帳款月份 → 勾選要請款的出貨單／退貨單（廠商＝移轉憑單）→ 填傳票日期與 ERP 傳票號碼（<b>兩者都必須先填</b>，號碼格式 V-民國年3碼＋MMDD＋流水3碼，日期須與傳票日期一致）→ 按「開立傳票」，在跳出的視窗確認會計分錄（可加匯費、手續費等科目）→ 確定開立。傳票查詢可列印傳票、看明細。ERP 開出傳票後，把 ERP 傳票號碼填進來（可建立時填、也可事後到「傳票查詢」補填）。
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
<script src="../../resource/js/eg_print_log.js?v=<?= @filemtime(__DIR__.'/../../resource/js/eg_print_log.js') ?: time() ?>"></script>
<script src="../../resource/js/eg_input_rules.js?v=<?= @filemtime(__DIR__.'/../../resource/js/eg_input_rules.js') ?: time() ?>"></script>
<script>
$(document).ready(function () { $('#sidebar-menu').css('visibility', 'visible'); });
(function ($) {
'use strict';
var API='../../src/store/Acc_API.php', CSRF='', CAN_EDIT=<?= $perms['canEdit']?'true':'false' ?>, CAN_ADMIN=<?= $perms['canAdmin']?'true':'false' ?>;
var items=[], SEL={}, lPage=1, PARTY_NAME='', DISCPCT=0;   // DISCPCT：此客戶的折扣%（主檔「接受扣%」）
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
var SRC_LBL={IS:'出貨',IR:'退貨',TLOG:'委外加工',PURC:'採購'};
function srcLbl(t){ return esc(SRC_LBL[t]||t); }
function failMsg(x,d){ var m=d||'操作失敗'; try{ m=JSON.parse(x.responseText).error||m; }catch(e){} toast(esc(m), true); }

$.getJSON(API,{action:'meta'},function(r){
  if(r.ok) CSRF=r.csrf;
  /* 由發票管理「開傳票」帶來（?side=ar&bm=2026-11&party=德士）：載入對象→選定→列出明細→勾選可開立的 */
  var q=new URLSearchParams(location.search), qb=q.get('bm'), qp=(q.get('party')||'').trim();
  if(/^\d{4}-\d{2}$/.test(qb||'') && qp){
    $('#vSide').val(q.get('side')==='ap'?'ap':'ar'); $('#vBm').val(qb);
    window.afterParties=function(){
      if(!$('#vParty option').filter(function(){ return this.value===qp; }).length){ toast(esc(qp)+' 在 '+esc(qb)+' 沒有可開傳票的明細', true); return; }
      window.afterItems=function(){
        $('#btnVAll').trigger('click');
        var inv=q.get('inv'), idt=q.get('idate');
        if(inv && !$('#vNote').val()) $('#vNote').val(('廠商發票 '+(idt?dd(idt):inv)).slice(0,200));
      };
      $('#vParty').val(qp).trigger('change');
    };
    loadParties();
  }
});
(function(){
  var d=new Date(); d.setDate(1); d.setMonth(d.getMonth()-1);
  var m=d.getFullYear()+'-'+('0'+(d.getMonth()+1)).slice(-2);
  $('#vBm').val(m);   // 查詢頁的帳款月份預設留空＝不限，避免剛開的傳票因為月份不同查不到
  var t=new Date(); $('#vDate').val(t.getFullYear()+'-'+('0'+(t.getMonth()+1)).slice(-2)+'-'+('0'+t.getDate()).slice(-2));
})();
$('.tab-btn').on('click',function(){
  var t=$(this).data('tab'); $('.tab-btn').removeClass('on'); $(this).addClass('on');
  $('#tabNew').toggle(t==='new'); $('#tabList').toggle(t==='list'); $('#tabMan').toggle(t==='man');
  if(t==='list') loadList();
});


/* ── 訂單預收／無單據 ── */
var M_ORDER=null, M_LINES=[];
function mKind(){ return $('input[name=mKind]:checked').val(); }
function mDefaultLines(){
  if(mKind()==='order') M_LINES=[{code:'1102000',side:'D',amount:'',memo:''},{code:'2251000',side:'C',amount:'',memo:''}];
  else M_LINES=[{code:'',side:'D',amount:'',memo:''},{code:'',side:'C',amount:'',memo:''}];
}
function mAcctName(c){ var a=ACCT.filter(function(x){ return x.code===c; })[0]; return a?a.name:''; }
function mNum(v){ return parseFloat(String(v).replace(/,/g,''))||0; }
function mRender(){
  var h='', d=0, c=0;
  M_LINES.forEach(function(l,i){
    var a=mNum(l.amount); if(l.side==='D') d+=a; else c+=a;
    h+='<tr><td><input type="text" class="m-code" data-i="'+i+'" list="dlAcct" value="'+esc(l.code)+'" maxlength="10" autocomplete="off" data-eg-skip style="width:100%;box-sizing:border-box;"></td>'
      +'<td class="l">'+esc(mAcctName(l.code))+'</td>'
      +'<td><select class="m-side" data-i="'+i+'" data-eg-skip><option value="D"'+(l.side==='D'?' selected':'')+'>借方</option><option value="C"'+(l.side==='C'?' selected':'')+'>貸方</option></select></td>'
      +'<td><input type="text" class="m-amt" data-i="'+i+'" value="'+esc(l.amount)+'" autocomplete="off" data-eg-skip style="width:100%;box-sizing:border-box;text-align:right;"></td>'
      +'<td><input type="text" class="m-memo" data-i="'+i+'" value="'+esc(l.memo)+'" maxlength="100" autocomplete="off" data-eg-skip style="width:100%;box-sizing:border-box;"></td>'
      +'<td>'+(M_LINES.length>2?'<button class="btn-mini m-del" data-i="'+i+'">移除</button>':'')+'</td></tr>';
  });
  $('#mLines').html(h); d=Math.round(d*100)/100; c=Math.round(c*100)/100;
  $('#mD').text(nf2(d)); $('#mC').text(nf2(c));
  var bad=M_LINES.some(function(l){ return !mAcctName(l.code) || !(mNum(l.amount)>0); });
  var ok=d>0 && Math.abs(d-c)<0.009 && !bad;
  $('#mBal').html(Math.abs(d-c)<0.009&&d>0?'<span style="color:#5C7A2E;">✓ 平衡</span>':'<span style="color:#DD5138;">借貸不平衡或未填金額</span>');
  var needOrder=(mKind()==='order'&&!M_ORDER);
  $('#btnMCreate').prop('disabled', !ok||needOrder);
  $('#mErr').text(needOrder?'請先選定訂單':'');
}
function mSwitch(){
  var o=(mKind()==='order');
  $('#mOrderPick').toggle(o); $('#mFreeParty').toggle(!o); $('#mForm').toggle(!o||!!M_ORDER);
  mDefaultLines(); mRender();
}
$(document).on('change','input[name=mKind]',function(){ M_ORDER=null; $('#mPicked').hide(); mSwitch(); });
function mSearch(){
  var kw=$.trim($('#mKw').val()); if(!kw){ $('#mBody').html('<tr><td colspan="9" style="padding:14px;color:#8a6d45;">輸入關鍵字搜尋訂單</td></tr>'); return; }
  $.post(API+'?action=vch_order_search',{kw:kw},function(r){
    if(!r.ok){ toast(esc(r.error||'查詢失敗'),true); return; }
    window.M_ROWS=r.rows;
    var h=r.rows.length?'':'<tr><td colspan="9" style="padding:14px;color:#8a6d45;">找不到符合的訂單</td></tr>';
    r.rows.forEach(function(o,i){
      h+='<tr><td class="l">'+esc(o.client_display)+' <span class="lk">'+esc(o.customer_id||o.Client_name_ID||'')+'</span></td><td>'+esc(o.Order_oo)+'</td><td>'+esc(o.c_order)+'</td><td class="l">'+esc(o.d_id)+'</td>'
        +'<td class="r">'+nf(o.Qty)+'</td><td>'+dd(o.order_date)+'</td><td class="r">'+(+o.prepaid?'<b style="color:#5C7A2E;">'+nf(o.prepaid)+'</b>':'')+'</td>'
        +'<td class="r">'+(+o.overpaid?'<b style="color:#B9793C;">'+nf(o.overpaid)+'</b>':'')+'</td><td><button class="btn-mini m-pick" data-i="'+i+'">選取</button></td></tr>';
    });
    $('#mBody').html(h); $('#mHit').text(r.rows.length>=60?'僅列前 60 筆，請加關鍵字縮小':'共 '+r.rows.length+' 筆');
  },'json').fail(function(x){ failMsg(x,'查詢失敗'); });
}
$('#btnMSearch').on('click',mSearch);
$('#mKw').on('keydown',function(e){ if(e.which===13){ e.preventDefault(); mSearch(); } });
$(document).on('click','.m-pick',function(){
  var o=window.M_ROWS[+$(this).data('i')]; M_ORDER=o;
  $('#mPicked').show().html('已選訂單：<b>'+esc(o.Order_oo)+'</b>　客戶單號 <b>'+esc(o.c_order||'—')+'</b>　客戶 <b>'+esc(o.client_display)+'</b>　料號 '+esc(o.d_id)
    +(+o.prepaid?'　<span style="color:#5C7A2E;">已預收 '+nf(o.prepaid)+'</span>':'')+(+o.overpaid?'　<span style="color:#B9793C;">客戶尚有溢繳款 '+nf(o.overpaid)+'（可考慮沖用）</span>':''));
  $('#mNote').val(('訂單 '+o.Order_oo+' 客戶單號 '+(o.c_order||'—')+' '+o.client_display).slice(0,300));
  $('#mForm').show(); mRender();
});
$(document).on('input change','.m-code',function(e){
  var i=+$(this).data('i'); M_LINES[i].code=$.trim(this.value);
  var f=sideOfCode(M_LINES[i].code); if(f) M_LINES[i].side=f;
  if(e.type==='change') mRender(); else $(this).closest('tr').find('td.l').text(mAcctName(M_LINES[i].code));
});
$(document).on('change','.m-side',function(){ M_LINES[+$(this).data('i')].side=this.value; mRender(); });
$(document).on('change','.m-amt',function(){
  var i=+$(this).data('i'); M_LINES[i].amount=this.value;
  if(mKind()==='order'&&M_LINES.length===2) M_LINES[1-i].amount=this.value;   // 預收：一借一貸同額
  mRender();
});
$(document).on('change','.m-memo',function(){ M_LINES[+$(this).data('i')].memo=this.value; });
$(document).on('click','.m-del',function(){ M_LINES.splice(+$(this).data('i'),1); mRender(); });
$('#btnMAdd').on('click',function(){ M_LINES.push({code:'',side:'D',amount:'',memo:''}); mRender(); });
function mNoState(){
  var no=normNo($('#mNo').val(), $('#mDate').val()); if($('#mNo').val()!==no) $('#mNo').val(no);
  var e=no?noCheck(no,$('#mDate').val()):''; $('#mNoErr').text(e||''); return !e&&!!no;
}
$('#mNo').on('blur change',mNoState); $('#mDate').on('change',function(){ if($('#mNo').val()) mNoState(); });
$('#btnMCreate').on('click',function(){
  if(!mNoState()){ if(!$('#mNoErr').text()) $('#mNoErr').text('請填 ERP 傳票號碼'); return; }
  var $b=$(this).prop('disabled',true);
  $.post(API+'?action=vch_create_manual',{csrf:CSRF,kind:mKind(),order_id:M_ORDER?M_ORDER.Order_id:0,party_name:$('#mParty').val(),
    voucher_date:$('#mDate').val(),voucher_no:$('#mNo').val(),note:$('#mNote').val(),
    lines:JSON.stringify(M_LINES.map(function(l){ return {code:l.code,side:l.side,amount:mNum(l.amount),memo:l.memo}; }))},function(r){
    if(!r.ok){ toast(esc(r.error||'開立失敗'),true); $b.prop('disabled',false); return; }
    toast('已開立傳票 '+esc($('#mNo').val())+'，合計 '+nf(r.total));
    M_ORDER=null; $('#mPicked').hide(); $('#mNo').val(''); $('#mNote').val(''); if(mKind()==='order'){ $('#mForm').hide(); mSearch(); } mDefaultLines(); mRender();
  },'json').fail(function(x){ failMsg(x,'開立失敗'); $b.prop('disabled',false); });
});
(function(){ var t=new Date(); $('#mDate').val(t.getFullYear()+'-'+('0'+(t.getMonth()+1)).slice(-2)+'-'+('0'+t.getDate()).slice(-2)); mDefaultLines(); })();

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
    if(window.afterParties){ var f=window.afterParties; window.afterParties=null; f(); }
  },'json').fail(function(x){ failMsg(x,'載入失敗'); });
}
$('#btnVParties').on('click',loadParties);
$('#vSide,#vBm').on('change',function(){ $('#vBox').hide(); loadParties(); });
$('#btnVThis,#btnVPrev').on('click',function(){
  var d=new Date(); d.setDate(1); if(this.id==='btnVPrev') d.setMonth(d.getMonth()-1);
  $('#vBm').val(d.getFullYear()+'-'+('0'+(d.getMonth()+1)).slice(-2)).trigger('change');
});
// 選定（或篩選到只剩一家而自動選起）客戶／廠商後直接列出明細
$('#vParty').on('change',function(){ if($(this).val()) loadItems(); });
function loadItems(){
  var pid=$('#vParty').val(); if(!pid){ toast('請先選擇客戶／廠商', true); return; }
  $.post(API+'?action=vch_candidates',{side:$('#vSide').val(),party_id:pid,bm:$('#vBm').val()},function(r){
    if(!r.ok){ toast(esc(r.error||'查詢失敗'), true); return; }
    items=r.items||[]; DISCPCT=Number(r.disc_pct)||0; SEL={}; $('#vBox').show();
    PARTY_NAME=r.party_name||''; $('#vColPid').text($('#vSide').val()==='ap'?'廠商':'料號');
    var lk=items.filter(function(i){return i.locked;}).length;
    $('#vInfo').html('<div class="info"><b>'+esc(r.party_name)+'</b>　共 '+items.length+' 筆明細'+(lk?'，其中 '+lk+' 筆已有傳票（灰底，不可重複開立）':'')+'。</div>');
    renderItems();
    if(window.afterItems){ var f=window.afterItems; window.afterItems=null; f(); }
  },'json').fail(function(x){ failMsg(x,'查詢失敗'); });
}
$('#btnVLoad').on('click',loadItems);
function renderItems(){
  var h='';
  if(!items.length) h='<tr><td colspan="11" style="padding:20px;color:#8a6d45;">此對象在該帳款月份沒有明細</td></tr>';
  items.forEach(function(it,i){
    var ret=(it.src_type==='IR');
    h+='<tr data-i="'+i+'" class="'+(it.locked?'lockedrow ':'')+(SEL[i]?'on':'')+'"'+(ret?' style="color:#DD5138;background:#FADADD;"':'')+'>'
      +'<td>'+(it.locked||!CAN_EDIT?'':'<input type="checkbox" class="vck"'+(SEL[i]?' checked':'')+'>')+'</td>'
      +'<td>'+srcLbl(it.src_type)+'</td><td>'+esc(it.src_no)+'</td><td>'+dd(it.src_date)+'</td>'
      +'<td class="l">'+esc($('#vSide').val()==='ap'?PARTY_NAME:it.product_id)+'</td><td class="l">'+esc((it.spec||'').substr(0,26))+'</td>'
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
/* ══ 會計分錄／傳票號碼檢查 ══ */
var ACCT=[], ACFG={}, JX=[], TAXR=0.05, ME='', SLOTS={}, COMPANY='';
function sideOfCode(c){ var f=String(c||'').charAt(0); return (f&&'156'.indexOf(f)>=0)?'D':((f&&'234'.indexOf(f)>=0)?'C':null); }
function nf2(n){ return Number(n||0).toLocaleString('en-US',{minimumFractionDigits:0,maximumFractionDigits:2}); }
/* 簡寫補全：MMDD＋流水3碼（7碼）→ 補傳票日期的民國年與 V-；民國年MMDD流水3碼（10碼）→ 補 V- */
function normNo(v, date){
  v=$.trim(String(v||'')).toUpperCase();
  if(/^\d{10}$/.test(v)) return 'V-'+v;
  var m=/^(\d{4})-(\d{2})-(\d{2})$/.exec(date||'');
  if(/^\d{7}$/.test(v) && m) return 'V-'+('00'+(+m[1]-1911)).slice(-3)+v;
  /* 只輸入 1~3 碼＝流水號：民國年與 MMDD 取自傳票日期 */
  if(/^\d{1,3}$/.test(v) && m) return 'V-'+('00'+(+m[1]-1911)).slice(-3)+m[2]+m[3]+('00'+v).slice(-3);
  return v;
}
function noCheck(no, date){
  if(!no) return '請填 ERP 傳票號碼';
  var m=/^V-(\d{3})(\d{2})(\d{2})(\d{3})$/.exec(no);
  if(!m) return '格式須為 V-民國年3碼＋MMDD＋流水3碼（例 V-1151126001）';
  var y=+m[1]+1911, mo=+m[2], d=+m[3], dt=new Date(y,mo-1,d);
  if(dt.getFullYear()!==y || dt.getMonth()!==mo-1 || dt.getDate()!==d) return '號碼裡的日期 '+m[1]+m[2]+m[3]+' 不是有效日期';
  var iso=y+'-'+('0'+mo).slice(-2)+'-'+('0'+d).slice(-2);
  if(date && iso!==date) return '號碼裡的日期（'+iso.replace(/-/g,'.')+'）與傳票日期（'+dd(date)+'）不一致';
  return '';
}
function vNoState(){
  var no=$.trim($('#vNo').val()).toUpperCase();
  var e = no==='' ? '' : noCheck(no, $('#vDate').val());
  $('#vNoErr').text(e); $('#vNo').toggleClass('bad', !!e);
  return no!=='' && !e;
}
$('#vNo').on('input',function(){ this.value=this.value.toUpperCase(); vNoState(); });
$('#vNo').on('blur change',function(){ this.value=normNo(this.value, $('#vDate').val()); vNoState(); });
$('#vDate').on('input change',function(){ var $n=$('#vNo'); if(/^\d{7}$/.test($.trim($n.val()))) $n.val(normNo($n.val(), $(this).val())); vNoState(); });
function loadAcct(cb){
  $.getJSON(API,{action:'vch_accounts'},function(r){
    if(!r.ok) return; ACCT=r.accounts||[]; ACFG=r.cfg||{}; TAXR=Number(r.tax_rate)||0.05; ME=r.me||''; PRULES=r.prefix_rules||[]; SLOTS=r.slots||{}; COMPANY=r.company||'';
    $('#dlAcct').html(ACCT.map(function(a){ return '<option value="'+esc(a.code)+'">'+esc(a.name)+'</option>'; }).join(''));
    if(cb) cb(r);
  });
}
loadAcct();
var PRULES=[], EXPSEL={};
function ruleFor(no){ var best=null; PRULES.forEach(function(r){ if(r.prefix && String(no||'').toUpperCase().indexOf(String(r.prefix).toUpperCase())===0 && (!best||r.prefix.length>best.prefix.length)) best=r; }); return best; }
function expGroups(){   // 應付：依單號開頭分組 → [{key,rule,amount}]
  var m=$('#jRound').val(), f=function(n){ return m==='round'?Math.round(n):(m==='floor'?Math.floor(n+1e-9):n); }, g={}, order=[];
  Object.keys(SEL).forEach(function(i){ var it=items[i], r=ruleFor(it.src_no), k=r?r.prefix:'', v=Number(it.amount)||0;
    if(!g[k]){ g[k]={key:k,rule:r,pos:0,neg:0}; order.push(k); } if(v>=0) g[k].pos+=v; else g[k].neg+=-v; });
  var out=order.map(function(k){ var x=g[k]; x.amount=f(Math.round(x.pos*100)/100)-f(Math.round(x.neg*100)/100); return x; });
  var diff=Math.round((selNet()-out.reduce(function(a,x){ return a+x.amount; },0))*100)/100;
  if(Math.abs(diff)>0.001 && out.length){ var mi=0; out.forEach(function(x,i){ if(Math.abs(x.amount)>Math.abs(out[mi].amount)) mi=i; }); out[mi].amount=Math.round((out[mi].amount+diff)*100)/100; }
  return out;
}
function expAcct(x){ if(!x.rule) return ACFG.ap_exp||{code:'',name:''}; var a=x.rule.accounts, c=EXPSEL[x.key]; return a.filter(function(y){ return y.code===c; })[0]||a[0]; }
function renderExp(){
  if($('#vSide').val()!=='ap'){ $('#jExp').hide().empty(); return; }
  var h='';
  expGroups().forEach(function(x){ if(!x.rule || x.rule.accounts.length<2) return;
    h+='<div style="margin:4px 0;">單號「'+esc(x.key)+'」開頭的明細，進貨會計科目：<select class="jexp" data-k="'+esc(x.key)+'" style="width:auto;">'
      +x.rule.accounts.map(function(a){ return '<option value="'+esc(a.code)+'"'+(expAcct(x).code===a.code?' selected':'')+'>'+esc(a.code+' '+a.name)+'</option>'; }).join('')+'</select></div>'; });
  $('#jExp').html(h).toggle(!!h);
}
$(document).on('change','.jexp',function(){ EXPSEL[$(this).data('k')]=this.value; journalRender(); });
function selParts(){
  var sh=0, rt=0; Object.keys(SEL).forEach(function(i){ var v=Number(items[i].amount)||0; if(v>=0) sh+=v; else rt+=-v; });
  sh=Math.round(sh*100)/100; rt=Math.round(rt*100)/100;
  var m=$('#jRound').val(), f=function(n){ return m==='round'?Math.round(n):(m==='floor'?Math.floor(n+1e-9):n); };
  return {ship:f(sh), ret:f(rt)};
}
function selNet(){ var p=selParts(); return Math.round((p.ship-p.ret)*100)/100; }
function autoLines(){
  var P=selParts(), net=selNet(), side=$('#vSide').val(), L=[];
  // 客戶折扣：（出貨－退貨）淨額 × 折扣%，借銷貨折讓 4103000（與後端 acc_vch_auto_lines 同一規則）
  var disc=(side==='ar' && DISCPCT>0 && net>0)?Math.round(net*DISCPCT/100):0;
  // 多張發票：每張發票各一筆應收帳款（與後端同一規則；各張淨額正負不一時退回單一列）
  var IG=null;
  if(side==='ar'){
    var gm={}, go=[], mf=$('#jRound').val(), ff=function(n){ return mf==='round'?Math.round(n):(mf==='floor'?Math.floor(n+1e-9):n); };
    Object.keys(SEL).forEach(function(i){ var it=items[i], iid=Number(it.invoice_id)||0, k=iid>0?('I'+iid):$.trim(it.invoice_no||''), v=Number(it.amount)||0, ok2=iid>0&&Number(it.inv_total)>0;
      if(!gm[k]){ gm[k]={inv:$.trim(it.invoice_no||''),ship:0,ret:0,inv_tot:ok2?Math.round(Number(it.inv_total)):0,inv_tax:ok2?Math.round(Number(it.inv_tax)):0}; go.push(k); } if(v>=0) gm[k].ship+=v; else gm[k].ret+=-v; });
    var hasIT=go.some(function(k){ return gm[k].inv_tot>0; });
    if(go.filter(function(k){ return k!==''; }).length>1 || hasIT){
      var ok=true, sg=null, sd=0, sn=0, gl=[];
      go.forEach(function(k){ var g=gm[k]; g.ship=ff(Math.round(g.ship*100)/100); g.ret=ff(Math.round(g.ret*100)/100);
        var n=Math.round((g.ship-g.ret)*100)/100; g.disc=(DISCPCT>0&&n>0)?Math.round(n*DISCPCT/100):0; sd+=g.disc;
        if(g.inv_tot>0){ sn+=g.inv_tot-g.inv_tax; gl.push(g); return; }
        sn+=n-g.disc;
        var s=(n-g.disc)<0; if(sg!==null&&sg!==s) ok=false; sg=s; gl.push(g); });
      if(ok){ IG=gl; disc=Math.round(sd*100)/100; net=Math.round(sn*100)/100; }
    }
  }
  if(!IG && disc>0) net=Math.round((net-disc)*100)/100;
  var abs=Math.abs(net), tax=Math.round(abs*TAXR), neg=net<0;
  var A=function(k){ return ACFG[k]||{code:'',name:''}; };
  if(side==='ar'){
    // 收入與退回分開列示、銷項稅額分兩行，應收帳款以含稅淨額入帳
    if(IG){
      IG.forEach(function(g){
        if(g.inv_tot>0){ var gm0=g.inv?('發票 '+g.inv):'';
          L.push({code:A('ar_recv').code,name:A('ar_recv').name,side:'D',amount:g.inv_tot,memo:gm0});
          L.push({code:A('ar_ret').code,name:A('ar_ret').name,side:'D',amount:g.ret,memo:gm0});
          L.push({code:'4103000',name:'銷貨折讓',side:'D',amount:g.disc,memo:g.disc>0?(String(Math.round(DISCPCT*1000)/1000)+'%折扣'):''});
          L.push({code:A('ar_tax').code,name:A('ar_tax').name,side:'C',amount:g.inv_tax,memo:gm0});
          L.push({code:A('ar_rev').code,name:A('ar_rev').name,side:'C',amount:Math.round((g.inv_tot+g.ret+g.disc-g.inv_tax)*100)/100,memo:gm0});
          return; }
        var gn=Math.round((g.ship-g.ret-g.disc)*100)/100, gt=Math.round(Math.abs(gn)*TAXR), gm=g.inv?('發票 '+g.inv):'';
        L.push({code:A('ar_recv').code,name:A('ar_recv').name,side:gn<0?'C':'D',amount:Math.round((Math.abs(gn)+gt)*100)/100,memo:gm});
        L.push({code:A('ar_ret').code,name:A('ar_ret').name,side:'D',amount:g.ret,memo:gm});
        L.push({code:'4103000',name:'銷貨折讓',side:'D',amount:g.disc,memo:g.disc>0?(String(Math.round(DISCPCT*1000)/1000)+'%折扣'):''});
        L.push({code:A('ar_tax').code,name:A('ar_tax').name,side:gn<0?'D':'C',amount:gt,memo:gm});
        L.push({code:A('ar_rev').code,name:A('ar_rev').name,side:'C',amount:g.ship,memo:gm}); });
    } else {
    L.push({code:A('ar_recv').code,name:A('ar_recv').name,side:neg?'C':'D',amount:Math.round((abs+tax)*100)/100});
    L.push({code:A('ar_ret').code,name:A('ar_ret').name,side:'D',amount:P.ret});
    L.push({code:'4103000',name:'銷貨折讓',side:'D',amount:disc,memo:disc>0?(String(Math.round(DISCPCT*1000)/1000)+'%折扣'):''});
    L.push({code:A('ar_tax').code,name:A('ar_tax').name,side:neg?'D':'C',amount:tax});
    L.push({code:A('ar_rev').code,name:A('ar_rev').name,side:'C',amount:P.ship});
    }
  } else {
    var eg=expGroups(), by={}, ord=[];
    eg.forEach(function(x){ var a=expAcct(x); if(!by[a.code]){ by[a.code]={name:a.name,amt:0}; ord.push(a.code); } by[a.code].amt+=x.amount; });
    ord.forEach(function(c){ L.push({code:c,name:by[c].name,side:by[c].amt<0?'C':'D',amount:Math.round(Math.abs(by[c].amt)*100)/100}); });
    L.push({code:A('ap_tax').code,name:A('ap_tax').name,side:neg?'C':'D',amount:tax});
    L.push({code:A('ap_pay').code,name:A('ap_pay').name,side:neg?'D':'C',amount:abs+tax});
  }
  L=L.filter(function(l){ return l.amount>0.004; });
  var recvIx=[]; if(side==='ar') L.forEach(function(l,i){ if(l.code===A('ar_recv').code&&l.side==='D') recvIx.push(i); });
  // 使用者手改的科目（以該列順序為鍵；借貸方向維持原分錄結構不變）
  L.forEach(function(l,i){ l.ai=i; l.sysmemo=l.memo||''; var o=AOVR[i];
    if(o && o.code && !(l.code==='4103000' && l.sysmemo)){ l.code=o.code; l.name=o.name; l.edited=1; }
    if(o && o.memo!=null){ l.memo=o.memo; } });
  // 訂單預收自動沖抵（與後端同一規則）：應收帳款減少、借預收貨款 2251000
  if(side==='ar' && PP_AVAIL>0 && recvIx.length){
    var rt=0; recvIx.forEach(function(i){ rt+=L[i].amount; });
    var off=Math.round(Math.min(PP_AVAIL,rt)*100)/100, left=off;
    if(off>0.004){
      recvIx.forEach(function(i){ var t=Math.min(left,L[i].amount); L[i].amount=Math.round((L[i].amount-t)*100)/100; left=Math.round((left-t)*100)/100; });
      L=L.filter(function(l){ return l.amount>0.004; });
      L.push({code:'2251000',name:'預收貨款',side:'D',amount:off,memo:'沖預收 '+PP_NOS,ai:-1,sysmemo:'沖預收 '+PP_NOS,pp:1});
    }
  }
  return L;
}
var jBalanced=false, AOVR={}, PP_AVAIL=0, PP_NOS='';
/* 自動帶出的會計科目可直接手改：輸入代號或名稱，下方出現符合的科目清單可選；離開欄位時驗證必須在科目表內 */
$(document).on('change','.j-acode',function(){
  var ai=+$(this).data('ai'), v=$.trim(this.value), a=null, up=v.toUpperCase();
  if(v){
    a=ACCT.filter(function(x){ return x.code===v; })[0]
      || ACCT.filter(function(x){ return x.name===v; })[0]
      || (function(){ var m=ACCT.filter(function(x){ return (x.code+' '+x.name).toUpperCase().indexOf(up)>=0; }); return m.length===1?m[0]:null; })();
    if(!a){ toast('科目「'+esc(v)+'」不在科目表（或符合多筆），請從清單選擇或輸入完整科目代號', true); journalRender(); return; }
  }
  var keep=AOVR[ai]&&AOVR[ai].memo!=null?AOVR[ai].memo:null;
  if(!a){ delete AOVR[ai]; if(keep!=null) AOVR[ai]={memo:keep}; } else AOVR[ai]={code:a.code,name:a.name,memo:keep};
  journalRender();
});
/* 摘要：可手動補充；系統帶出的「?%折扣」不可刪除（被刪掉會自動補回） */
$(document).on('change','.j-amemo',function(){
  var ai=+$(this).data('ai'), sys=String($(this).data('sys')||''), v=$.trim(this.value);
  if(sys){ v=sys+(v?' '+v:''); }   // 輸入框只放補充文字，系統摘要固定在前
  var o=AOVR[ai]||{}; o.memo=v; AOVR[ai]=o;
  journalRender();
});
/* 點到會計科目欄自動全選，方便直接覆寫（mouseup 會取消選取，故延後到下一個 tick） */
$(document).on('focus click','.j-acode',function(){ var el=this; setTimeout(function(){ if(document.activeElement===el) el.select(); },0); });
function signBoxes(signs, maker, canClear, canSign, id){
  var sg='', ks=Object.keys(SLOTS);
  ks.forEach(function(k){ var g=(signs||{})[k];
    sg+='<div style="flex:1;text-align:center;border-right:1px solid var(--a-line2);"><div style="background:var(--a-bg2);padding:3px;">'+esc(SLOTS[k])+'</div><div style="min-height:44px;padding:2px;">'
      +(g?'<b>'+esc(g.name)+'</b><div style="font-size:11.5px;color:#8a6d45;">'+dd(g.date)+'</div>'+(canClear?'<button class="btn-mini v-sign" data-mode="clear" data-slot="'+k+'" data-id="'+id+'">取消</button>':'')
        :(canSign?'<button class="btn-mini v-sign" data-mode="sign" data-slot="'+k+'" data-id="'+id+'" style="margin-top:8px;">簽章</button>':''))+'</div></div>'; });
  sg+='<div style="flex:1;text-align:center;"><div style="background:var(--a-bg2);padding:3px;">製單</div><div style="min-height:44px;line-height:44px;">'+esc(maker||'')+'</div></div>';
  return sg;
}
function journalRender(){
  var rows=autoLines().map(function(l){ return $.extend({auto:1},l); }).concat(JX.map(function(l,i){ return $.extend({auto:0,ix:i},l); }));
  var d=0,c=0,h='';
  rows.forEach(function(l){
    if(l.side==='D') d+=l.amount; else c+=l.amount;
    var lock=((l.auto && l.code==='4103000' && l.sysmemo) || l.pp);   // 系統帶出的客戶折扣列：科目固定、「?%折扣」不可刪
    var sysM=l.auto?String(l.sysmemo||''):'';
    var sufM=sysM?String(l.memo||'').slice(sysM.length).replace(/^\s+/,''):'';
    var memoCell = sysM
      ? '<div style="display:flex;gap:4px;align-items:center;"><span style="white-space:nowrap;">'+esc(sysM)+' <span class="lk">🔒</span></span>'
        +(CAN_EDIT?'<input type="text" class="j-amemo" data-ai="'+l.ai+'" data-sys="'+esc(sysM)+'" maxlength="80" value="'+esc(sufM)+'" placeholder="補充文字" data-eg-skip style="flex:1;min-width:0;box-sizing:border-box;">':esc(sufM))+'</div>'
      : l.auto
      ? (CAN_EDIT?'<input type="text" class="j-amemo" data-ai="'+l.ai+'" data-sys="'+esc(l.sysmemo||'')+'" maxlength="100" value="'+esc(l.memo||'')+'" data-eg-skip style="width:100%;box-sizing:border-box;">':esc(l.memo||''))
      : esc(l.memo||'');
    h+='<tr><td>'+(l.auto&&CAN_EDIT&&!lock?'<input type="text" class="j-acode" list="dlAcct" data-ai="'+l.ai+'" value="'+esc(l.code)+'" maxlength="40" autocomplete="off" data-eg-skip title="可直接改：輸入科目代號或名稱，從清單選擇" style="width:100%;box-sizing:border-box;'+(l.edited?'background:#FFF3D1;':'')+'">':esc(l.code))+'</td><td class="l" style="'+(l.side==='C'?'padding-left:26px;':'')+'">'+esc(l.name)+(lock?' <span class="lk">🔒</span>':'')+(l.auto?'':' <span class="lk">（追加）</span>')+'</td>'
      +'<td>'+memoCell+'</td>'
      +'<td class="r">'+(l.side==='D'?nf2(l.amount):'')+'</td><td class="r">'+(l.side==='C'?nf2(l.amount):'')+'</td>'
      +'<td>'+(l.auto?'':'<button class="btn-mini j-del" data-i="'+l.ix+'">移除</button>')+'</td></tr>';
  });
  $('#jBody').html(h); d=Math.round(d*100)/100; c=Math.round(c*100)/100;
  $('#jDsum').text(nf2(d)); $('#jCsum').text(nf2(c));
  jBalanced=Math.abs(d-c)<0.009;
  $('#jBal').html(jBalanced?'<span style="color:#5C7A2E;">✓ 平衡</span>':'<span style="color:#DD5138;">不平衡</span>');
  $('#btnJConfirm').prop('disabled', !jBalanced);
  $('#vSigns').html(signBoxes({}, ME, false, false, 0));
}
$('#jRound').on('change',function(){ journalRender(); });
$('#jCode').on('input change',function(){
  var code=$.trim(this.value), a=ACCT.filter(function(x){ return x.code===code; })[0];
  $('#jName').val(a?a.name:'');
  $('#jSide').toggle(code.length>=4 && !sideOfCode(code));
});
$('#btnJAdd').on('click',function(){
  var code=$.trim($('#jCode').val()), name=$.trim($('#jName').val()), amt=parseFloat(String($('#jAmt').val()).replace(/,/g,''));
  var e='';
  if(!/^\d{4,10}$/.test(code)) e='科目代號須為 4~10 位數字';
  else if(!(amt>0)) e='金額須大於 0';
  else { var a=ACCT.filter(function(x){ return x.code===code; })[0]; if(!a) e='科目 '+code+' 不在科目表，請先到科目表新增'; else name=a.name; }
  $('#jErr').text(e); if(e) return;
  JX.push({code:code,name:name,side:sideOfCode(code)||$('#jSide').val(),amount:Math.round(amt*100)/100,memo:$.trim($('#jMemo').val())});
  $('#jCode,#jName,#jAmt,#jMemo').val(''); $('#jSide').hide(); journalRender();
});
$(document).on('click','.j-del',function(){ JX.splice(+$(this).data('i'),1); journalRender(); });
var CATS=[];
function catOpts(sel){ var h=''; CATS.forEach(function(c){ h+='<option value="'+esc(c.code)+'"'+(c.code===sel?' selected':'')+'>'+esc(c.code+' '+c.name)+'</option>'; }); return h; }
var IN='style="width:100%;box-sizing:border-box;"';
function acRow(a){
  var sd=a.side||sideOfCode(a.code), on=(a.active===undefined||Number(a.active)), dis=CAN_ADMIN?'':' disabled', cat=a.category||String(a.code).substr(0,2);
  var delc=CAN_ADMIN?('<td>'+(Number(a.used)?'<span style="color:#8a6d45;font-size:12px;" title="已有傳票使用，只能取消啟用">使用中</span>':'<input type="checkbox" class="al-del" title="刪除">')+'</td>'):'';
  return '<tr><td>'+esc(a.code)+'<input type="hidden" class="al-code" value="'+esc(a.code)+'"></td><td><input type="text" class="al-name" maxlength="60" value="'+esc(a.name)+'" '+IN+dis+'></td>'
    +'<td><select class="al-cat" '+IN+dis+'>'+catOpts(cat)+'</select></td><td style="text-align:center;">'+(sd==='D'?'借':(sd==='C'?'貸':'<span style="color:#8a6d45;">自選</span>'))+'</td>'
    +'<td><input type="text" class="al-sum" maxlength="200" value="'+esc(a.summary||'')+'" '+IN+dis+'></td><td><input type="text" class="al-note" maxlength="200" value="'+esc(a.note||'')+'" '+IN+dis+'></td>'
    +'<td><input type="text" class="al-par" maxlength="10" value="'+esc(a.parent_code||'')+'" '+IN+dis+'></td><td style="text-align:center;"><input type="checkbox" class="al-on"'+(on?' checked':'')+dis+'></td>'+delc+'</tr>';
}
/* 由「會計設定」頁連過來：?open=acct／coacct 自動開啟對應設定視窗 */
setTimeout(function(){ var m2=/[?&]vch=(\d+)/.exec(location.search); if(m2) $('<button class="v-open" data-id="'+m2[1]+'">').appendTo('body').hide().click().remove(); },900);
setTimeout(function(){ var m=/[?&]open=(acct|coacct)/.exec(location.search); if(m) $(m[1]==='acct'?'#btnAcctCfg':'#btnCoAcct').click(); },900);
/* 本公司入帳帳戶 */
var CO=[];
function coRow(a){
  var id=a.acct_id||0, ro=CAN_ADMIN?'':' disabled';
  var f=function(k,w,ph){ return '<td><input type="text" class="co-'+k+'" value="'+esc(a[k]||'')+'" style="width:'+w+';"'+ro+(ph?' placeholder="'+ph+'"':'')+' data-eg-skip></td>'; };
  return '<tr data-id="'+id+'">'+f('code','86px')+f('name','100%')+f('bank','100%')+f('account_no','100%')
    +'<td><input type="text" class="co-gl" list="dlAcct" value="'+esc(a.gl_code||'1102000')+'" style="width:100px;"'+ro+' data-eg-skip></td>'+f('note','100%')
    +'<td><input type="radio" name="coDef" class="co-def"'+(Number(a.is_default)?' checked':'')+ro+'></td>'
    +'<td><input type="checkbox" class="co-act"'+((id===0||Number(a.active))?' checked':'')+ro+'></td>'
    +'<td>'+(CAN_ADMIN?'<button class="btn-mini co-save">儲存</button>'+(id?' <button class="btn-mini co-del">刪除</button>':''):'')+'</td></tr>';
}
function coRender(){ $('#coBody').html(CO.map(coRow).join('')+(CAN_ADMIN?coRow({acct_id:0,gl_code:'1102000'}):'')); }
$('#btnCoAcct').on('click',function(){
  $('#coBody').html('<tr><td colspan="9" style="padding:14px;color:#8a6d45;">載入中…</td></tr>'); openMask('mkCoAcct');
  $.getJSON(API,{action:'rcpt_accts',all:1},function(r){ if(!r.ok){ toast(esc(r.error||'載入失敗'),true); return; } CO=r.accts||[]; coRender(); });
});
$(document).on('click','.co-save',function(){
  var $r=$(this).closest('tr'), d={acct_id:Number($r.data('id'))||0, code:$.trim($r.find('.co-code').val()), name:$.trim($r.find('.co-name').val()),
    bank:$.trim($r.find('.co-bank').val()), account_no:$.trim($r.find('.co-account_no').val()), gl_code:$.trim($r.find('.co-gl').val()),
    note:$.trim($r.find('.co-note').val()), is_default:$r.find('.co-def').is(':checked')?1:0, active:$r.find('.co-act').is(':checked')?1:0};
  if(!d.code){ toast('請填設定編號',true); return; } if(!d.name){ toast('請填名稱',true); return; } if(!d.account_no){ toast('請填帳號',true); return; }
  if(!/^\d{4,10}$/.test(d.gl_code)){ toast('對應科目代號須為 4~10 位數字',true); return; }
  $.post(API+'?action=rcpt_acct_save',{csrf:CSRF,data:JSON.stringify(d)},function(r){
    if(!r.ok){ toast(esc(r.error||'儲存失敗'),true); return; } toast('已儲存'); CO=r.accts||[]; coRender();
  },'json').fail(function(x){ var m='儲存失敗'; try{ m=JSON.parse(x.responseText).error||m; }catch(e){} toast(esc(m),true); });
});
$(document).on('click','.co-del',function(){
  if(!confirm('確定刪除這個帳戶？（已被收款單使用的只能改為停用）')) return;
  $.post(API+'?action=rcpt_acct_del',{csrf:CSRF,acct_id:$(this).closest('tr').data('id')},function(r){
    if(!r.ok){ toast(esc(r.error||'刪除失敗'),true); return; } toast('已刪除'); CO=r.accts||[]; coRender();
  },'json').fail(function(x){ var m='刪除失敗'; try{ m=JSON.parse(x.responseText).error||m; }catch(e){} toast(esc(m),true); });
});
$('#btnAcctCfg').on('click',function(){
  $.getJSON(API,{action:'vch_accounts'},function(r){
    var dis=CAN_ADMIN?'':' disabled'; CATS=r.categories||[];
    var h='<div style="font-weight:700;margin-bottom:4px;">① 預設會計科目（開傳票時自動帶出）'+(CAN_ADMIN?'':'<span style="font-weight:400;color:#8a6d45;font-size:12.5px;">　僅會計管理員可修改</span>')+'</div>'
      +'<table class="a-t"><thead><tr><th>用途</th><th>科目代號</th><th>科目名稱</th></tr></thead><tbody>';
    var L=r.labels||{}; Object.keys(L).forEach(function(k){ var c=(r.cfg||{})[k]||{};
      h+='<tr><td class="l">'+esc(L[k])+'</td><td><input type="text" class="ac-code" data-k="'+k+'" maxlength="10" value="'+esc(c.code||'')+'" style="width:100px;"'+dis+'></td><td><input type="text" class="ac-name" data-k="'+k+'" maxlength="40" value="'+esc(c.name||'')+'" style="width:150px;"'+dis+'></td></tr>'; });
    h+='</tbody></table><div class="a-hint" style="margin:4px 0 12px;">借貸方向固定：應收＝應收帳款借／銷項稅額、銷貨收入貸；應付＝進貨／加工費、進項稅額借／應付帳款貸。</div>';
    h+='<div style="font-weight:700;margin-bottom:4px;">② 科目表（追加科目時可選；新增科目代號＋名稱後按「儲存科目表」）'+(CAN_ADMIN?'':'<span style="font-weight:400;color:#8a6d45;font-size:12.5px;">　唯讀，僅會計管理員可修改</span>')+'</div>'
      +'<table class="a-t" style="table-layout:fixed;width:100%;"><colgroup><col style="width:90px;"><col style="width:170px;"><col style="width:190px;"><col style="width:48px;"><col><col><col style="width:95px;"><col style="width:48px;">'+(CAN_ADMIN?'<col style="width:60px;">':'')+'</colgroup><thead><tr><th>科目代號</th><th>科目名稱</th><th>類別</th><th>借貸</th><th>摘要</th><th>備註</th><th>所屬科目</th><th>啟用</th>'+(CAN_ADMIN?'<th>刪除</th>':'')+'</tr></thead><tbody id="acListBody">';
    (r.all_accounts||[]).forEach(function(a){ h+=acRow(a); });
    h+='</tbody></table>'+(!CAN_ADMIN?'':'<div class="a-bar" style="margin-top:6px;"><input type="text" id="alNewCode" maxlength="10" placeholder="新科目代號" style="width:110px;" autocomplete="off"><input type="text" id="alNewName" maxlength="60" placeholder="新科目名稱" style="width:200px;" autocomplete="off"><button id="btnAlAdd"><i class="fa fa-plus"></i> 加入科目表</button><span id="alErr" style="color:#DD5138;font-size:12.5px;"></span></div>');
    h+='<div style="font-weight:700;margin:14px 0 4px;">③ 科目分類（前兩碼；1~3 開頭＝資產負債表、4~9 開頭＝損益表，之後報表依此彙總。改完按下方「儲存科目表」一併存）</div>'
      +'<table class="a-t" style="table-layout:fixed;width:100%;"><colgroup><col style="width:90px;"><col style="width:300px;"><col><col style="width:70px;">'+(CAN_ADMIN?'<col style="width:60px;">':'')+'</colgroup><thead><tr><th>分類代號</th><th>分類名稱</th><th>備註</th><th>科目數</th>'+(CAN_ADMIN?'<th>刪除</th>':'')+'</tr></thead><tbody id="catBody">';
    (r.categories||[]).forEach(function(c){ h+=catRow(c); });
    h+='</tbody></table>'+(!CAN_ADMIN?'':'<div class="a-bar" style="margin-top:6px;"><input type="text" id="catNewCode" maxlength="2" placeholder="2位代號" style="width:80px;" autocomplete="off"><input type="text" id="catNewName" maxlength="60" placeholder="新分類名稱" style="width:200px;" autocomplete="off"><button id="btnCatAdd"><i class="fa fa-plus"></i> 加入分類</button><span id="catErr" style="color:#DD5138;font-size:12.5px;"></span></div>');
    PRULES=r.prefix_rules||[];
    h+='<div style="font-weight:700;margin:14px 0 4px;">④ 應付：單號開頭對應進貨會計科目'+(CAN_ADMIN?'':'<span style="font-weight:400;color:#8a6d45;font-size:12.5px;">　僅會計管理員可修改</span>')+'</div>'
      +'<div class="a-hint" style="margin-bottom:4px;">明細單號以「單號開頭」起頭時，傳票的進貨借方科目改用這裡設定的科目；設多個科目時，確認傳票時可下拉選擇（第一個為預設）。沒有符合的單號開頭，仍用上方「應付：進貨」。每行一個科目：代號 名稱（名稱可省略，會從科目表帶入）。</div>'
      +'<table class="a-t"><thead><tr><th style="width:110px;">單號開頭</th><th>會計科目（一行一個）</th>'+(CAN_ADMIN?'<th style="width:60px;">刪除</th>':'')+'</tr></thead><tbody id="prBody">';
    PRULES.forEach(function(x){ h+=prRow(x); });
    h+='</tbody></table>'+(CAN_ADMIN?'<div class="a-bar" style="margin-top:6px;"><button id="btnPrAdd"><i class="fa fa-plus"></i> 新增單號開頭</button><button class="go" id="btnPrSave"><i class="fa fa-check"></i> 儲存單號對應科目</button></div>':'');
    $('#acctBody').html(h); openMask('mkAcct');
  });
});
function prRow(x){
  var dis=CAN_ADMIN?'':' disabled', t=(x.accounts||[]).map(function(a){ return a.code+' '+a.name; }).join('\n');
  return '<tr class="pr-row"><td><input type="text" class="pr-pf" maxlength="10" value="'+esc(x.prefix||'')+'" style="width:90px;"'+dis+'></td>'
    +'<td><textarea class="pr-acc" rows="2" style="width:100%;" list="dlAcct"'+dis+'>'+esc(t)+'</textarea></td>'+(CAN_ADMIN?'<td style="text-align:center;"><input type="checkbox" class="pr-del"></td>':'')+'</tr>';
}
$(document).on('click','#btnPrAdd',function(){ $('#prBody').append(prRow({prefix:'',accounts:[]})); });
$(document).on('click','#btnPrSave',function(){
  var rules=[]; $('#prBody .pr-row').each(function(){ var $t=$(this); if($t.find('.pr-del').is(':checked')) return;
    var accs=[]; String($t.find('.pr-acc').val()).split(/\n/).forEach(function(ln){ ln=$.trim(ln); if(!ln) return; var m=ln.match(/^(\d+)\s*(.*)$/); accs.push(m?{code:m[1],name:m[2]}:{code:ln,name:''}); });
    rules.push({prefix:$.trim($t.find('.pr-pf').val()), accounts:accs}); });
  $.post(API+'?action=vch_prefix_rules_save',{csrf:CSRF,rules:JSON.stringify(rules)},function(r){
    if(!r.ok){ toast(esc(r.error||'儲存失敗'), true); return; }
    toast(esc(r.message)); loadAcct(); $('#btnAcctCfg').click();
  },'json').fail(function(x){ failMsg(x,'儲存失敗'); });
});
function catRow(c){
  var dis=CAN_ADMIN?'':' disabled';
  return '<tr data-code="'+esc(c.code)+'"><td>'+esc(c.code)+'</td><td><input type="text" class="ct-name" maxlength="60" value="'+esc(c.name)+'" '+IN+dis+'></td><td><input type="text" class="ct-note" maxlength="200" value="'+esc(c.note||'')+'" '+IN+dis+'></td><td style="text-align:center;">'+(c.n||0)+'</td>'
    +(CAN_ADMIN?'<td style="text-align:center;">'+((c.n||0)?'<span style="color:#8a6d45;font-size:12px;">使用中</span>':'<input type="checkbox" class="ct-del">')+'</td>':'')+'</tr>';
}
$(document).on('click','#btnCatAdd',function(){
  var c=$.trim($('#catNewCode').val()), n=$.trim($('#catNewName').val()), e='';
  if(!/^\d{2}$/.test(c)) e='分類代號須為 2 位數字'; else if(!n) e='請填分類名稱';
  else if($('#catBody tr[data-code='+c+']').length) e='分類 '+c+' 已存在';
  $('#catErr').text(e); if(e) return;
  $('#catBody').append(catRow({code:c,name:n,note:'',n:0})); CATS.push({code:c,name:n}); $('.al-cat').append('<option value="'+esc(c)+'">'+esc(c+' '+n)+'</option>'); $('#catNewCode,#catNewName').val('');
});
$(document).on('click','#btnAlAdd',function(){
  var c=$.trim($('#alNewCode').val()), n=$.trim($('#alNewName').val()), e='';
  if(!/^\d{4,10}$/.test(c)) e='科目代號須為 4~10 位數字'; else if(!n) e='請填科目名稱';
  else if($('.al-code').filter(function(){ return this.value===c; }).length) e='科目 '+c+' 已在科目表';
  $('#alErr').text(e); if(e) return;
  $('#acListBody').append(acRow({code:c,name:n}));
  $('#alNewCode,#alNewName').val('');
});
$('#btnAcctListSave').on('click',function(){
  if(!CAN_ADMIN) return;
  var rows=[], delA=[]; $('#acListBody tr').each(function(){ var $t=$(this); if($t.find('.al-del').is(':checked')){ delA.push(String($t.find('.al-code').val())); return; } rows.push({code:$t.find('.al-code').val(), category:$t.find('.al-cat').val(), name:$.trim($t.find('.al-name').val()), summary:$.trim($t.find('.al-sum').val()), note:$.trim($t.find('.al-note').val()), parent_code:$.trim($t.find('.al-par').val()), active:$t.find('.al-on').is(':checked')?1:0}); });
  var cats=[], del=[]; $('#catBody tr').each(function(){ var $t=$(this), c=$t.data('code'); if($t.find('.ct-del').is(':checked')) del.push(String(c)); else cats.push({code:String(c), name:$.trim($t.find('.ct-name').val()), note:$.trim($t.find('.ct-note').val())}); });
  $.post(API+'?action=vch_categories_save',{csrf:CSRF,rows:JSON.stringify(cats),del:JSON.stringify(del)},function(r0){
    if(!r0.ok){ toast(esc(r0.error||'分類儲存失敗'), true); return; }
    $.post(API+'?action=vch_accounts_save',{csrf:CSRF,rows:JSON.stringify(rows),del:JSON.stringify(delA)},function(r){
      if(!r.ok){ toast(esc(r.error||'儲存失敗'), true); return; }
      toast('已儲存科目表與科目分類'); loadAcct(); $('#btnAcctCfg').click();
    },'json').fail(function(x){ failMsg(x,'儲存失敗'); });
  },'json').fail(function(x){ failMsg(x,'儲存失敗'); });
});
$('#btnAcctSave').on('click',function(){
  var cfg={}; $('.ac-code').each(function(){ var k=$(this).data('k'); cfg[k]={code:$.trim(this.value), name:$.trim($('.ac-name[data-k='+k+']').val())}; });
  $.post(API+'?action=vch_acct_cfg_save',{csrf:CSRF,cfg:JSON.stringify(cfg)},function(r){
    if(!r.ok){ toast(esc(r.error||'儲存失敗'), true); return; }
    toast(esc(r.message)); closeMask('mkAcct'); loadAcct();
  },'json').fail(function(x){ failMsg(x,'儲存失敗'); });
});/* 傳票列印（隱藏 iframe，不開新分頁） */
function voucherPrintHtml(r){
  var v=r.voucher, jd=0, jc=0, rows='';
  (r.lines||[]).forEach(function(l){ var D=(l.side==='D'), a=Number(l.amount)||0; if(D) jd+=a; else jc+=a;
    rows+='<tr><td>'+esc(l.code)+'</td><td style="text-align:left;'+(D?'':'padding-left:22px;')+'">'+esc(l.name)+(l.memo?'　'+esc(l.memo):'')+'</td><td class="r">'+(D?nf2(a):'')+'</td><td class="r">'+(D?'':nf2(a))+'</td></tr>'; });
  var SG=r.signs||{}, sgs='', hd='';
  Object.keys(SLOTS).forEach(function(k){ hd+='<td class="h">'+esc(SLOTS[k])+'</td>'; var g=SG[k]; sgs+='<td>'+(g?esc(g.name)+'<br><span style="font-size:11px;">'+dd(g.date)+'</span>':'')+'</td>'; });
  hd+='<td class="h">製單</td>'; sgs+='<td>'+esc(v.maker_name||v.created_by||'')+'</td>';
  return '<!doctype html><html><head><meta charset="utf-8"><title>轉帳傳票 '+esc(v.voucher_no||'')+'</title><style>'
    +'@page{size:A4 portrait;margin:0}body{margin:0;font-family:"Microsoft JhengHei","PingFang TC",sans-serif;color:#222}'
    +'.sheet{box-sizing:border-box;width:210mm;min-height:297mm;padding:16mm 14mm}'
    +'h1{text-align:center;font-size:20px;margin:0}h2{text-align:center;font-size:17px;margin:4px 0 12px;letter-spacing:8px}'
    +'.meta{display:flex;flex-wrap:wrap;gap:4px 24px;font-size:13px;margin-bottom:8px}'
    +'table{border-collapse:collapse;width:100%;table-layout:fixed;font-size:13px}th,td{border:1px solid #444;padding:6px 8px;text-align:center}'
    +'td.r{text-align:right}tfoot td{font-weight:700}.sign td{height:56px}.sign td.h{height:auto;background:#f2ece2;font-size:12.5px;padding:3px}'
    +'</style></head><body><div class="sheet"><h1>'+esc(COMPANY)+'</h1><h2>轉帳傳票</h2>'
    +'<div class="meta"><span>傳票號碼：<b>'+esc(v.voucher_no||'')+'</b></span><span>傳票日期：'+dd(v.voucher_date)+'</span><span>'+(v.side==='ap'?'廠商':'客戶')+'：'+esc(v.party_name)+'</span><span>帳款月份：'+esc(v.billing_month||'')+'</span></div>'
    +'<table><colgroup><col style="width:22%"><col style="width:38%"><col style="width:20%"><col style="width:20%"></colgroup><thead><tr><th>會計科目</th><th>科目名稱</th><th>借方金額</th><th>貸方金額</th></tr></thead><tbody>'+rows+'</tbody>'
    +'<tfoot><tr><td colspan="2" style="text-align:right;">合計</td><td class="r">'+nf2(jd)+'</td><td class="r">'+nf2(jc)+'</td></tr></tfoot></table>'
    +(v.note?'<div style="margin-top:8px;font-size:13px;">備註：'+esc(v.note)+'</div>':'')
    +'<table class="sign" style="margin-top:14px;"><tr>'+hd+'</tr><tr>'+sgs+'</tr></table></div></body></html>';
}
function printVoucher(id){
  $.post(API+'?action=vch_get',{voucher_id:id},function(r){
    if(!r.ok){ toast(esc(r.error||'讀取失敗'), true); return; }
    var f=document.getElementById('vPrintFrame'); if(f) f.remove();
    f=document.createElement('iframe'); f.id='vPrintFrame'; f.style.cssText='position:fixed;right:0;bottom:0;width:0;height:0;border:0;';
    document.body.appendChild(f);
    var doc=f.contentWindow.document; doc.open(); doc.write(voucherPrintHtml(r)); doc.close();
    if(window.EGPrintLog) EGPrintLog.record({source:'acc_voucher', doc_kind:'form', doc_name:'轉帳傳票 '+(r.voucher.voucher_no||'')+' '+r.voucher.party_name, ref_table:'acc_voucher', ref_id:id});
    setTimeout(function(){ f.contentWindow.focus(); f.contentWindow.print(); }, 250);
  },'json').fail(function(x){ failMsg(x,'讀取失敗'); });
}
$(document).on('click','.v-print',function(){ printVoucher($(this).data('id')); });
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
  var d=$('#vDate').val(); if(!d){ toast('請先填傳票日期', true); $('#vDate').focus(); return; }
  var noV=normNo($('#vNo').val(), d), noE=noCheck(noV, d); $('#vNo').val(noV);
  if(noE){ toast('傳票號碼：'+esc(noE), true); $('#vNo').focus(); return; }
  JX=[]; AOVR={}; $('#jErr').text('');
  $('#jTitle').text('確認傳票 '+noV);
  $('#jRound').val('round'); EXPSEL={}; renderExp();
  $('#jHead').html('<b>'+esc($('#vParty option:selected').text().split('　')[0])+'</b>　帳款月份 '+esc($('#vBm').val())+'　傳票日期 '+dd(d)+'　共 '+k.length+' 筆憑證、未稅合計 '+nf2(selNet()));
  PP_AVAIL=0; PP_NOS='';
  journalRender(); openMask('mkJ');
  if($('#vSide').val()==='ar'){
    $.post(API+'?action=vch_prepay_avail',{sel:JSON.stringify(k.map(function(i){ return {src_type:items[i].src_type, src_id:items[i].src_id}; }))},function(r){
      if(r.ok && Number(r.avail)>0){ PP_AVAIL=Number(r.avail); PP_NOS=Object.keys(r.orders||{}).map(function(o){ return r.orders[o].no; }).join('、'); journalRender();
        $('#jHead').append('　<span style="color:#3f5a1c;background:#EAF1DA;border:1px solid #B7CC8C;border-radius:3px;padding:1px 6px;">此客戶訂單有預收 '+nf2(PP_AVAIL)+'，已自動沖抵應收</span>'); }
    },'json');
  }
});
$('#btnJConfirm').on('click',function(){
  var k=Object.keys(SEL); if(!k.length) return;
  var d=$('#vDate').val(), noV=normNo($('#vNo').val(), d), noE=noCheck(noV, d);
  if(noE){ toast('傳票號碼：'+esc(noE), true); return; }
  if(!jBalanced){ toast('會計分錄借貸不平衡，請調整追加的科目', true); return; }
  var sel=k.map(function(i){ return {src_type:items[i].src_type, src_id:items[i].src_id}; });
  var $b=$(this).prop('disabled',true);
  $.post(API+'?action=vch_create',{csrf:CSRF,side:$('#vSide').val(),party_id:$('#vParty').val(),bm:$('#vBm').val(),
    voucher_date:d,voucher_no:noV,note:$('#vNote').val(),sel:JSON.stringify(sel),extra:JSON.stringify(JX),auto_ovr:JSON.stringify(AOVR),rounding:$('#jRound').val(),exp_choice:JSON.stringify(EXPSEL)},function(r){
    $b.prop('disabled',false);
    if(!r.ok){ toast(esc(r.error||'開立失敗'), true); return; }
    closeMask('mkJ'); toast('<b>'+esc(r.message)+'</b>　可到「傳票查詢」列印或看明細');
    $('#vNo').val(''); $('#vNote').val(''); JX=[]; vNoState(); loadItems();
  },'json').fail(function(x){ $b.prop('disabled',false); failMsg(x,'開立失敗'); });
});
/* 傳票查詢：點發票號碼／迴紋針開啟該傳票對應發票的附件（一個直接開，多個列清單） */
$(document).on('click','.att-open',function(e){
  e.preventDefault();
  var ids=String($(this).data('inv')||'').split(',').filter(Boolean), all=[], n=0;
  if(!ids.length) return;
  ids.forEach(function(id){
    $.getJSON(API,{action:'inv_attach_list',invoice_id:id},function(r){
      (r.rows||[]).forEach(function(a){ all.push(a); });
      if(++n<ids.length) return;
      if(!all.length){ toast('沒有附件', true); return; }
      if(all.length===1){ window.open(API+'?action=inv_attach_download&att_id='+all[0].att_id,'_blank'); return; }
      $('#attBody').html(all.map(function(a){ return '<div style="padding:5px 0;"><a href="'+API+'?action=inv_attach_download&att_id='+a.att_id+'" target="_blank" rel="noopener"><i class="fa fa-paperclip"></i> '+esc(a.orig_name)+'</a></div>'; }).join(''));
      openMask('mkAtt');
    });
  });
});
/* ── 查詢 ── */
var lSeq=0;
function loadList(){
  var mySeq=++lSeq;
  $('#lBody').html('<tr><td colspan="10" style="padding:18px;color:#8a6d45;">查詢中…</td></tr>');
  $.post(API+'?action=vch_list',{bm_from:$('#lFrom').val(),bm_to:$('#lTo').val(),side:$('#lSide').val(),status:$('#lStatus').val(),
    no_number:$('#lNoNo').is(':checked')?'1':'',kw:$('#lKw').val(),page:lPage,per_page:$('#lPer').val()},function(r){
    if(mySeq!==lSeq) return;   // 打字很快時只採用最後一次查詢
    if(!r.ok){ toast(esc(r.error||'查詢失敗'), true); return; }
    var h='';
    if(!r.rows.length) h='<tr><td colspan="10" style="padding:18px;color:#8a6d45;">沒有符合的傳票</td></tr>';
    r.rows.forEach(function(v){
      h+='<tr class="'+(v.status==='void'?'voided':'')+'"><td><b>'+esc(v.voucher_no||'')+'</b>'+(v.voucher_no?'':'<span class="lk">（未填）</span>')+'</td>'
        +'<td>'+dd(v.voucher_date)+'</td><td><span class="sd-pill '+(v.kind==='order'?'sd-ar">預收':(v.kind==='free'?'sd-ar" style="background:#B9793C;">無單據':(v.side==='ap'?'sd-ap">應付':'sd-ar">應收')))+'</span></td><td class="l">'+esc(v.party_name)+'</td>'
        +'<td>'+esc(v.billing_month||'')+'</td><td class="r'+(v.side==='ap'?' amt-ap':'')+'">'+nf(v.total_amount)+'</td>'
        +'<td style="white-space:nowrap;">'+(v.att_invs?'<a href="#" class="att-open" data-inv="'+esc(v.att_invs)+'" title="點選開啟發票附件">'+esc(v.invoice_nos||'')+'</a>':esc(v.invoice_nos||''))
        +' <span style="display:inline-block;width:20px;text-align:left;">'+(v.att_invs?'<a href="#" class="att-open" data-inv="'+esc(v.att_invs)+'" style="color:#B9793C;" title="有附件，點選開啟"><i class="fa fa-paperclip"></i></a>':'')+'</span></td>'
        +'<td><span class="pill st-'+v.status+'">'+(v.status==='void'?'已作廢':'有效')+'</span></td><td class="l">'+esc(v.note||'')+'</td>'
        +'<td><button class="btn-mini v-open" data-id="'+v.voucher_id+'">明細</button> <button class="btn-mini v-print" data-id="'+v.voucher_id+'"><i class="fa fa-print"></i> 列印</button></td></tr>';
    });
    $('#lBody').html(h);
    var pages=Math.max(1,Math.ceil(r.total/r.per_page)); $('#lInfo').text('共 '+r.total+' 張');
    var pg=''; for(var p=Math.max(1,r.page-2);p<=Math.min(pages,r.page+2);p++) pg+='<button class="'+(p===r.page?'on':'')+'" data-p="'+p+'">'+p+'</button>';
    $('#lPg').html(pg);
  },'json').fail(function(x){ failMsg(x,'查詢失敗'); });
}
var lTimer=null;
function loadListLive(){ lPage=1; loadList(); }
$('#lPer,#lSide,#lStatus,#lNoNo,#lFrom,#lTo').on('change',loadListLive);
$('#lKw').on('input',function(){ clearTimeout(lTimer); lTimer=setTimeout(loadListLive,350); })
  .on('keydown',function(e){ if(e.which===13){ clearTimeout(lTimer); loadListLive(); } });
$(document).on('click','#lPg button',function(){ lPage=+$(this).data('p'); loadList(); });
$(document).on('click','.v-open',function(){
  var id=$(this).data('id');
  $.post(API+'?action=vch_get',{voucher_id:id},function(r){
    if(!r.ok){ toast(esc(r.error||'讀取失敗'), true); return; }
    var v=r.voucher, void_=(v.status==='void'), settled=(+v.settled===1), ed=CAN_EDIT&&!void_&&!settled;
    $('#mvTitle').text('傳票 '+(v.voucher_no||'(未填號碼)')+'　'+v.party_name+'　'+v.billing_month);
    var h='<div class="a-bar"><label>傳票號碼</label><input type="text" id="evNo" class="vno-in" maxlength="40" value="'+esc(v.voucher_no||'')+'"'+(ed?'':' disabled')+'>'
      +'<label>傳票日期</label><input type="date" id="evDate" value="'+esc(v.voucher_date)+'"'+(ed?'':' disabled')+'>'
      +'<label>備註</label><input type="text" id="evNote" maxlength="200" style="width:240px;" value="'+esc(v.note||'')+'"'+(ed?'':' disabled')+'></div>'
      +(void_?'<div class="warn">此傳票已作廢：'+esc(v.void_reason||'')+'</div>':'')
      +((settled&&!void_)?'<div class="warn">此傳票已沖帳（'+esc(v.settled_reason||'')+'），不可修改。</div>':'')
      +'<table class="a-t"><thead><tr><th>類型</th><th>單號</th><th>日期</th><th>料號</th><th>品名規格</th><th>數量</th><th>單價</th><th>金額</th><th>發票</th></tr></thead><tbody>';
    r.items.forEach(function(it){ h+='<tr'+(it.src_type==='IR'?' style="background:#FADADD;"':'')+'><td>'+srcLbl(it.src_type)+'</td><td>'+esc(it.src_no)+'</td><td>'+dd(it.src_date)+'</td><td class="l">'+esc(it.product_id)+'</td>'
      +'<td class="l">'+esc((it.spec||'').substr(0,26))+'</td><td class="r">'+nf(it.qty)+'</td><td class="r">'+np(it.unit_price)+'</td><td class="r">'+nf(it.amount)+'</td><td>'+esc(it.invoice_no||'')+'</td></tr>'; });
    h+='</tbody></table><div style="text-align:right;margin-top:6px;">合計 <b>'+nf(v.total_amount)+'</b></div>';
    var jd=0,jc=0, jh='<div style="font-weight:700;margin:12px 0 4px;"><i class="fa fa-book"></i> 會計分錄</div><table class="a-t" style="max-width:780px;"><thead><tr><th>會計科目</th><th>科目名稱</th><th>借方金額</th><th>貸方金額</th></tr></thead><tbody>';
    if(ed){
      jh='<div style="font-weight:700;margin:12px 0 4px;"><i class="fa fa-book"></i> 會計分錄（未沖帳，可修改）</div><table class="a-t" style="max-width:860px;"><thead><tr><th>科目代號</th><th>借貸</th><th>金額</th><th>備註</th><th></th></tr></thead><tbody id="evLines">';
      (r.lines||[]).forEach(function(l){ jh+=evLineRow(l); });
      jh+='</tbody></table><div class="a-bar" style="margin-top:6px;"><button id="btnEvLineAdd"><i class="fa fa-plus"></i> 加一列</button><span id="evBal" style="font-size:12.5px;"></span></div>';
    } else {
    (r.lines||[]).forEach(function(l){ var D=(l.side==='D'), a=Number(l.amount)||0; if(D) jd+=a; else jc+=a;
      jh+='<tr><td>'+esc(l.code)+'</td><td class="l" style="'+(D?'':'padding-left:26px;')+'">'+esc(l.name)+(l.memo?'　<span style="color:#8a6d45;font-size:12px;">'+esc(l.memo)+'</span>':'')+'</td><td class="r">'+(D?nf2(a):'')+'</td><td class="r">'+(D?'':nf2(a))+'</td></tr>'; });
    jh+='<tr><td colspan="2" style="text-align:right;font-weight:700;">合計</td><td class="r"><b>'+nf2(jd)+'</b></td><td class="r"><b>'+nf2(jc)+'</b></td></tr></tbody></table>';
    }
    h+=jh+'<div style="display:flex;max-width:780px;margin-top:8px;border:1px solid var(--a-line2);">'+signBoxes(r.signs||{}, v.maker_name||v.created_by, CAN_ADMIN&&!void_, ed, id)+'</div>';
    $('#mvBody').html(h); if(ed) evBalance();
    var f='<button data-close="mkV">關閉</button>';
    if(ed) f+='<button class="go" id="btnVSave" data-id="'+id+'"><i class="fa fa-check"></i> 儲存</button>';
    if(CAN_ADMIN&&!void_) f+='<button id="btnVVoid" data-id="'+id+'" style="color:#DD5138;">作廢傳票</button>';
    f+=' <button class="v-print" data-id="'+id+'"><i class="fa fa-print"></i> 列印</button>'; $('#mvFoot').html(f); openMask('mkV');
  },'json').fail(function(x){ failMsg(x,'讀取失敗'); });
});
$(document).on('click','.v-sign',function(){
  var id=$(this).data('id');
  $.post(API+'?action=vch_sign',{csrf:CSRF,voucher_id:id,slot:$(this).data('slot'),mode:$(this).data('mode')},function(r){
    if(!r.ok){ toast(esc(r.error||'簽章失敗'), true); return; }
    $('.v-open[data-id='+id+']').first().trigger('click');
  },'json').fail(function(x){ failMsg(x,'簽章失敗'); });
});
function evLineRow(l){
  l=l||{}; var auto=(+l.is_auto===1), disc=(auto&&l.code==='4103000'), sysM=disc?String(l.memo||''):'';
  var memoCell = sysM
    ? '<span style="white-space:nowrap;">'+esc(sysM)+' 🔒</span> <input type="text" class="ev-memo" data-sys="'+esc(sysM)+'" maxlength="80" style="width:130px;" placeholder="補充文字" value="'+esc(String(l.memo||'').slice(sysM.length).replace(/^\s+/,''))+'">'
    : '<input type="text" class="ev-memo" maxlength="100" style="width:220px;" value="'+esc(l.memo||'')+'">';
  if(disc){   // 客戶折扣列：科目、借貸、金額鎖定成文字（隱藏欄位供儲存時讀取）
    return '<tr data-auto="1" data-disc="1"><td><b>'+esc(l.code)+'</b> 🔒<input type="hidden" class="ev-code" value="'+esc(l.code)+'"><div class="ev-nm" style="font-size:11.5px;color:#8a6d45;">'+esc(l.name||'')+'</div></td>'
      +'<td>'+(l.side==='C'?'貸':'借')+'<input type="hidden" class="ev-side" value="'+esc(l.side||'D')+'"></td>'
      +'<td style="text-align:right;">'+nf2(Number(l.amount)||0)+'<input type="hidden" class="ev-amt" value="'+esc(l.amount)+'"></td>'
      +'<td>'+memoCell+'</td><td></td></tr>';
  }
  return '<tr data-auto="'+(auto?1:0)+'"><td><input type="text" class="ev-code" list="dlAcct" maxlength="10" style="width:100px;" value="'+esc(l.code||'')+'">'+(l.name?'<div class="ev-nm" style="font-size:11.5px;color:#8a6d45;">'+esc(l.name)+'</div>':'')+'</td>'
    +'<td><select class="ev-side"><option value="D"'+(l.side==='C'?'':' selected')+'>借</option><option value="C"'+(l.side==='C'?' selected':'')+'>貸</option></select></td>'
    +'<td><input type="number" step="0.01" class="ev-amt" style="width:130px;text-align:right;" value="'+(l.amount!=null?l.amount:'')+'"></td>'
    +'<td>'+memoCell+'</td>'
    +'<td>'+(auto?'':'<button class="ev-del" title="刪除此列">×</button>')+'</td></tr>';
}
function evBalance(){
  var d=0,c=0; $('#evLines tr').each(function(){ var a=Number($(this).find('.ev-amt').val())||0; if($(this).find('.ev-side').val()==='D') d+=a; else c+=a; });
  var ok=Math.abs(d-c)<0.01; $('#evBal').html('借方 <b>'+nf2(d)+'</b>　貸方 <b>'+nf2(c)+'</b>　<span style="color:'+(ok?'#5B7F3A':'#DD5138')+';">'+(ok?'平衡':'不平衡')+'</span>');
}
/* 改科目代號 → 即時帶出科目名稱（先查已載入的科目表，沒有再向後端查含停用的全部科目） */
function evShowName($inp, name){
  var $d=$inp.siblings('.ev-nm'); if(!$d.length) $d=$('<div class="ev-nm" style="font-size:11.5px;"></div>').insertAfter($inp);
  if(name){ $d.css('color','#8a6d45').text(name); } else { $d.css('color','#DD5138').text('科目表查無此代號'); }
}
$(document).on('input change','.ev-code',function(){
  var $i=$(this), c=$.trim($i.val()), seq=($i.data('seq')||0)+1; $i.data('seq',seq);
  if(!/^\d{4,10}$/.test(c)){ $i.siblings('.ev-nm').remove(); return; }
  var hit=ACCT.filter(function(a){ return String(a.code)===c; })[0];
  if(hit){ evShowName($i, hit.name); return; }
  $.getJSON(API,{action:'vch_accounts'},function(r){
    if($i.data('seq')!==seq) return;                       // 打字很快時只採用最後一次查詢
    var all=(r&&r.all_accounts)||[], h=all.filter(function(a){ return String(a.code)===c; })[0];
    evShowName($i, h?h.name:'');
  });
});
$(document).on('click','#btnEvLineAdd',function(){ $('#evLines').append(evLineRow({side:'D'})); evBalance(); });
$(document).on('click','.ev-del',function(){ $(this).closest('tr').remove(); evBalance(); });
$(document).on('input change','#evLines input,#evLines select',evBalance);
$(document).on('click','#btnVSave',function(){
  var id=$(this).data('id'), d=$('#evDate').val(); if(!d){ toast('請填傳票日期', true); return; }
  var lines=[]; $('#evLines tr').each(function(){ var $t=$(this); lines.push({code:$.trim($t.find('.ev-code').val()),side:$t.find('.ev-side').val(),amount:$t.find('.ev-amt').val(),memo:(function(){ var $m=$t.find('.ev-memo'), sys=String($m.data('sys')||''), v=$.trim($m.val()); return sys?(sys+(v?' '+v:'')):$m.val(); })(),is_auto:$t.data('auto')}); });
  var post={csrf:CSRF,voucher_id:id,voucher_no:normNo($('#evNo').val(), d),voucher_date:d,note:$('#evNote').val()};
  if($('#evLines').length) post.lines=JSON.stringify(lines);
  $.post(API+'?action=vch_update',post,function(r){
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
