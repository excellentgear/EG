<?php
/**
 * views/ACC/recon_overview.php — 對帳單總覽（應收＋應付一起看）
 *
 * 用途：會計與稽核要一頁看到「這個月誰對完了、誰還卡著、哪幾份有差額」，
 * 不必一份一份開 reconcile.php 才知道進度。
 *
 * 一列 = 一份對帳底稿（acc_recon_sheet，對象×帳款月份唯一一份）。
 * 資料走 Acc_API.php 的 sheet_list；排序／統計／匯出一律後端對「全部符合條件」的資料算
 * （差額是運算欄位，只用當頁資料排會排錯）。
 *
 * 這頁同時含兩側，所以整頁用中性暖棕為底，靠側別徽章區分
 * （應收＝琥珀橘 #F0A24B、應付＝暖棕赭 #8A5A2B），不挑其中一側的色當底色。
 *
 * 權限：acc_lib.php acc_perms()。檢閱即可看；「退回重對」僅會計管理員。
 * 只有單側對帳角色的人（業務／生管），本頁鎖在他那一側，不給看另一側。
 */
session_start();
if (!isset($_SESSION['userName'])) {
    $_SESSION['lastpage'] = "../../views/ACC/recon_overview.php";
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
         : ($perms['reconAr'] && $perms['reconAp'] ? '應收＋應付對帳'
         : ($perms['reconAr'] ? '應收對帳(業務)'
         : ($perms['reconAp'] ? '應付對帳(生管)'
         : ($perms['canView'] ? '會計檢閱' : '無權限'))))));

/* 只有單側對帳角色者（純業務／純生管），鎖在自己那一側 */
$lockSide = '';
if (!$perms['canEdit'] && !$perms['canAdmin']) {
    if ($perms['reconAr'] && !$perms['reconAp']) $lockSide = 'ar';
    if ($perms['reconAp'] && !$perms['reconAr']) $lockSide = 'ap';
}
?>
<!DOCTYPE html>
<html lang="zh-Hant">
<head>
<meta charset="utf-8">
<meta http-equiv="X-UA-Compatible" content="IE=edge">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>對帳單總覽</title>
<link href="../../resource/css/bootstrap.css" rel="stylesheet">
<link href="../../resource/css/font-awesome.css" rel="stylesheet">
<link href="../../resource/css/nprogress.css" rel="stylesheet">
<link href="../../resource/css/custom.css" rel="stylesheet">
<style>
/* 中性暖棕底（本頁兩側並存，不偏任何一側），側別靠徽章區分 */
:root{
  --a-line:#E3D2B6; --a-line2:#D2B98F; --a-bg:#FBF6EE; --a-bg2:#F7EEE0;
  --a-ink:#54341A; --a-ink2:#8a6d45; --a-brand:#7A4A1E;
  --a-acc:#B9793C; --a-acc-d:#9A6229; --a-ok:#EFDFC4; --a-bad:#DD5138;
  --a-ar:#F0A24B; --a-ap:#8A5A2B; --a-good:#5C7A2E; --a-hit:#E4EDD4;
}
#sidebar-menu{visibility:hidden;}
.right_col .page-title{margin:8px 0 4px;overflow:hidden;}

.side-band{display:flex;align-items:center;gap:10px;clear:both;margin-bottom:8px;color:#fff;
  border-radius:8px;padding:7px 14px;font-size:15px;box-shadow:inset 0 -3px 0 rgba(0,0,0,.14);
  background:linear-gradient(90deg,#B9793C 0%,#8A5A2B 100%);}
.side-band .sb-sub{font-size:12.5px;opacity:.92;font-weight:normal;}
.side-band .sb-lnk{margin-left:auto;display:flex;gap:6px;}
.side-band .sb-lnk a{font-size:13px;color:#fff;background:rgba(255,255,255,.18);
  border:1px solid rgba(255,255,255,.55);border-radius:14px;padding:4px 13px;
  text-decoration:none;white-space:nowrap;}
.side-band .sb-lnk a:hover{background:rgba(255,255,255,.34);color:#fff;text-decoration:none;}

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
#kw{width:230px;}
.a-role{margin-left:auto;font-size:13px;color:var(--a-ink);background:var(--a-ok);
  border-radius:12px;padding:5px 12px;white-space:nowrap;}
.a-role .fa-question-circle{cursor:pointer;color:#b5762a;margin-left:5px;}

.a-stat{display:flex;flex-wrap:wrap;gap:18px;align-items:center;margin-bottom:8px;
  border:1.5px solid var(--a-line);border-radius:8px;padding:9px 14px;background:var(--a-bg2);}
.a-stat .n{font-size:19px;font-weight:bold;color:var(--a-brand);}
.a-stat .n.big{font-size:23px;}
.a-stat .n.bad{color:var(--a-bad);}
.a-stat .n.good{color:var(--a-good);}
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
table.a-t thead th.sortable{cursor:pointer;user-select:none;}
table.a-t thead th.sortable:hover{background:#E5CFA6;}
table.a-t thead th.sortable .sa{margin-left:4px;font-style:normal;opacity:.35;font-size:11px;}
table.a-t thead th.sortable .sa:before{content:'\f0dc';font-family:FontAwesome;}
table.a-t thead th.sortable.asc .sa,table.a-t thead th.sortable.desc .sa{opacity:1;color:#8A5A2B;}
table.a-t thead th.sortable.asc  .sa:before{content:'\f0de';}
table.a-t thead th.sortable.desc .sa:before{content:'\f0dd';}
table.a-t td.l{text-align:left;}
table.a-t td.r{text-align:right;}
table.a-t tbody tr:nth-child(even){background:#FFFCF6;}
table.a-t tbody tr:hover{background:#FBF0DE;}

/* 側別徽章：色＋文字雙重標示（顏色不是唯一資訊） */
.pill{display:inline-block;padding:1px 9px;border-radius:9px;font-size:11.5px;line-height:18px;}
.sd-ar{background:var(--a-ar);color:#fff;}
.sd-ap{background:var(--a-ap);color:#fff;}
.st-draft{background:#EFD9B4;color:#7a4a1e;}
.st-confirmed{background:var(--a-good);color:#fff;}
.st-reopened{background:var(--a-bad);color:#fff;}
.d-zero{color:var(--a-good);font-weight:bold;}
.d-bad{color:var(--a-bad);font-weight:bold;}
.d-none{color:#a08a6a;}
.who{font-size:11.5px;color:var(--a-ink2);line-height:1.45;}
.who b{color:var(--a-ink);font-size:12.5px;font-weight:bold;}

.btn-mini{height:24px;padding:0 9px;border:1px solid var(--a-line2);border-radius:4px;
  background:#fff;cursor:pointer;color:var(--a-ink);font-size:12px;text-decoration:none;
  display:inline-block;line-height:22px;}
.btn-mini:hover{background:var(--a-ok);color:var(--a-ink);text-decoration:none;}
.btn-mini.go{background:var(--a-acc);color:#fff;border-color:var(--a-acc-d);}
.btn-mini.go:hover{background:var(--a-acc-d);color:#fff;}

.a-mask{display:none;position:fixed;top:0;left:0;right:0;bottom:0;width:100%;height:100%;
  background:rgba(60,40,20,.45);z-index:9999;overflow:auto;padding:34px 12px;}
.a-mask.show{display:block;}
.a-modal{background:#fff;border-radius:8px;width:620px;max-width:100%;margin:0 auto;
  box-shadow:0 6px 30px rgba(0,0,0,.3);}
.a-modal .m-head{background:var(--a-ok);color:var(--a-ink);padding:9px 14px;font-weight:bold;
  border-radius:8px 8px 0 0;display:flex;align-items:center;}
.a-modal .m-close{margin-left:auto;cursor:pointer;font-size:17px;}
.a-modal .m-body{padding:14px;max-height:70vh;overflow:auto;color:var(--a-ink);font-size:13.5px;}
.a-modal .m-foot{padding:10px 14px;border-top:1px solid var(--a-line);text-align:right;}
.a-modal .m-foot button{height:32px;padding:0 14px;border:1px solid var(--a-line2);border-radius:4px;
  background:#fff;cursor:pointer;color:var(--a-ink);margin-left:5px;}
.a-modal .m-foot button.go{background:var(--a-acc);color:#fff;border-color:var(--a-acc-d);font-weight:bold;}
.a-modal textarea{width:100%;min-height:82px;border:1px solid var(--a-line2);border-radius:4px;
  padding:7px 9px;font-size:13.5px;color:var(--a-ink);}
.f-err{color:var(--a-bad);font-size:12.5px;min-height:18px;margin-top:4px;}

.a-msg{position:fixed;top:64px;right:18px;z-index:12000;min-width:250px;max-width:430px;
  padding:11px 15px;border-radius:6px;display:none;font-size:14px;box-shadow:0 3px 12px rgba(0,0,0,.2);}
.a-msg.ok {background:#F7E0BD;color:#5b3a1e;border-left:5px solid var(--a-acc);}
.a-msg.bad{background:#FBE3DC;color:#7a2c17;border-left:5px solid var(--a-bad);}
.a-noperm{border:1.5px solid var(--a-line);background:var(--a-bg);border-radius:8px;padding:26px;color:var(--a-ink);}
.a-hint{font-size:11.5px;color:var(--a-ink2);margin-top:5px;line-height:1.6;}

@media print{
  .a-bar,.a-pager,.nav_menu,.left_col,footer,.a-mask{display:none !important;}
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
      <h2 style="margin:6px 0;"><i class="fa fa-list-alt" style="color:#B9793C;"></i> 對帳單總覽
        <small style="color:#8a6d45;">一列＝一份對帳底稿；誰對完了、誰還卡著、哪幾份有差額，一頁看完</small></h2>
    </div>
    <div class="clearfix"></div>

    <div class="side-band">
      <i class="fa fa-exchange"></i>
      <b>應收＋應付對帳進度</b>
      <span class="sb-sub">左右兩側都在這頁，側別欄有<span class="pill sd-ar" style="margin:0 3px;">應收</span>
        <span class="pill sd-ap" style="margin:0 3px;">應付</span>徽章區分</span>
      <span class="sb-lnk">
        <a href="reconcile.php" title="到對帳作業頁"><i class="fa fa-check-square-o"></i> 對帳作業</a>
        <a href="ar_statement.php">應收對帳單</a>
        <a href="ap_statement.php">應付對帳單</a>
      </span>
    </div>

<?php if (!$perms['canView']): ?>
    <div class="a-noperm">
      <h4><i class="fa fa-lock"></i> 無會計模組檢閱權限</h4>
      <p>請洽管理者於「使用者權限設定」指派「會計檢閱／會計登錄／會計管理員／應收對帳(業務)／應付對帳(生管)」角色。</p>
    </div>
<?php else: ?>
    <div class="a-bar">
      <label>帳款月份</label>
      <input type="month" id="bmFrom" style="width:145px;"> ~ <input type="month" id="bmTo" style="width:145px;">
      <button id="btnThis">本月</button>
      <button id="btnPrev">上月</button>
      <button id="btnAllMonth" title="不限月份，看全部底稿">全部月份</button>
      <label>側別</label>
      <select id="side" style="width:120px;" <?= $lockSide ? 'disabled' : '' ?>>
<?php if ($lockSide === ''): ?>
        <option value="all">全部</option>
        <option value="ar">應收（客戶）</option>
        <option value="ap">應付（廠商）</option>
<?php elseif ($lockSide === 'ar'): ?>
        <option value="ar" selected>應收（客戶）</option>
<?php else: ?>
        <option value="ap" selected>應付（廠商）</option>
<?php endif; ?>
      </select>
      <label>狀態</label>
      <select id="status" style="width:130px;">
        <option value="all">全部</option>
        <option value="draft">暫存中</option>
        <option value="confirmed">已確認鎖帳</option>
        <option value="reopened">已退回重對</option>
      </select>
      <input type="text" id="kw" placeholder="對象／編號／暫存人／確認人">
      <button id="btnSearch" class="btn-warm"><i class="fa fa-search"></i> 查詢</button>
      <span class="a-role">目前角色：<b><?= htmlspecialchars($roleLbl) ?></b>
        <i class="fa fa-question-circle" id="btnRoleHelp" title="角色權限說明"></i></span>
    </div>

    <div class="a-bar" style="background:var(--a-bg2);">
      <button id="btnOnlyDiff"><i class="fa fa-exclamation-triangle"></i> 只看有差額的</button>
      <button id="btnOnlyOpen"><i class="fa fa-hourglass-half"></i> 只看還沒對完的</button>
      <span style="width:1px;height:22px;background:var(--a-line);margin:0 4px;"></span>
      <button id="btnExport"><i class="fa fa-file-text-o"></i> 匯出總覽CSV</button>
      <button id="btnPrint"><i class="fa fa-print"></i> 列印／PDF</button>
      <span class="a-hint" style="margin-left:6px;">
        「差額」＝對方紙本合計 − 我方未稅；對方紙本還沒填的顯示「未填」。
      </span>
    </div>

    <div class="a-stat">
      <div><span class="n big" id="stCount">—</span> <span class="l">總份數</span></div>
      <div class="sep"></div>
      <div><span class="n" id="stDraft">—</span> <span class="l">暫存中</span></div>
      <div><span class="n good" id="stConfirmed">—</span> <span class="l">已確認鎖帳</span></div>
      <div><span class="n bad" id="stReopened">—</span> <span class="l">已退回重對</span></div>
      <div class="sep"></div>
      <div><span class="n big bad" id="stDiff">—</span> <span class="l">有差額份數</span></div>
    </div>

    <div class="a-pager">
      <span id="pgInfo" style="margin-right:auto;color:#8a6d45;"></span>
      每頁 <select id="perPage"><option>5</option><option>10</option><option selected>20</option><option>50</option></select> 筆
      <span id="pgBtns"></span>
    </div>

    <div class="a-wrap">
      <table class="a-t" id="tbl">
        <thead><tr>
          <th class="sortable" data-sort="side" style="width:70px;">側別<i class="sa"></i></th>
          <th class="sortable" data-sort="party_name">對象<i class="sa"></i></th>
          <th class="sortable" data-sort="billing_month" style="width:100px;">帳款月份<i class="sa"></i></th>
          <th class="sortable" data-sort="status" style="width:110px;">狀態<i class="sa"></i></th>
          <th style="width:96px;">已勾選／列數</th>
          <th class="sortable" data-sort="adj_cnt" style="width:80px;">調整列數<i class="sa"></i></th>
          <th class="sortable" data-sort="our_total">我方未稅<i class="sa"></i></th>
          <th>對方紙本</th>
          <th class="sortable" data-sort="abs_diff">差額<i class="sa"></i></th>
          <th class="sortable" data-sort="saved_at">暫存<i class="sa"></i></th>
          <th class="sortable" data-sort="confirmed_at">確認<i class="sa"></i></th>
          <th style="width:220px;">操作</th>
        </tr></thead>
        <tbody id="tbody"><tr><td colspan="12" style="padding:22px;color:#8a6d45;">載入中…</td></tr></tbody>
      </table>
    </div>
    <div class="a-hint">
      這頁只列<b>已經開始對過（有暫存或已確認）</b>的底稿。還沒有人動過的對象不會出現在這裡——
      要看某月份「哪些對象有帳但還沒開始對」，請到<a href="reconcile.php">對帳作業</a>選月份，對象下拉會標出誰已有底稿。
      「退回重對」僅會計管理員可用，且必須填原因（會寫入稽核紀錄）。已開立發票的憑證另有一層更硬的鎖，
      不在這裡解除，只能作廢／折讓／補開。
    </div>
<?php endif; ?>
  </div>
  <?php include '../partPage/footer.html' ?>
</div>
</div>

<!-- 退回重對 -->
<div class="a-mask" id="mkReopen"><div class="a-modal">
  <div class="m-head"><i class="fa fa-undo"></i>&nbsp;退回重對<span class="m-close" data-close="mkReopen">✕</span></div>
  <div class="m-body">
    <div id="roTitle" style="margin-bottom:8px;"></div>
    <div style="background:#FBE3DC;color:#7a2c17;border-left:5px solid #DD5138;padding:8px 12px;
                border-radius:4px;font-size:12.5px;margin-bottom:10px;">
      退回後這份對帳單會回到可修改狀態，對帳人員可以繼續改。此動作會記入稽核紀錄。
    </div>
    <label style="font-weight:normal;">退回原因（必填，至少 2 個字）</label>
    <textarea id="roReason" placeholder="例如：廠商紙本第 3 列數量有誤，需重對"></textarea>
    <div class="f-err" id="roErr"></div>
  </div>
  <div class="m-foot">
    <button data-close="mkReopen">取消</button>
    <button class="go" id="btnRoGo"><i class="fa fa-undo"></i> 確定退回</button>
  </div>
</div></div>

<!-- 角色說明 -->
<div class="a-mask" id="mkRole"><div class="a-modal">
  <div class="m-head">角色權限說明<span class="m-close" data-close="mkRole">✕</span></div>
  <div class="m-body" style="line-height:1.9;">
    <b>管理者</b>：固定擁有全部權限。<br>
    <b>會計管理員</b>：會計登錄的全部權限，另可作廢發票、刪收款單、<b>退回已鎖帳的對帳單</b>。<br>
    <b>會計登錄</b>：檢閱＋開票／沖帳／匯入，兩側都可對帳。<br>
    <b>會計檢閱</b>：只能查詢與匯出。<br>
    <b>應收對帳(業務)</b>：只能對應收，本頁只看得到應收側。<br>
    <b>應付對帳(生管)</b>：只能對應付，本頁只看得到應付側。<br>
    <span style="color:#8a6d45;font-size:12.5px;">角色於「使用者權限設定」頁指派（模組 accounting）。</span>
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
var P = { canAdmin: <?= $perms['canAdmin'] ? 'true' : 'false' ?> };
var LOCK_SIDE = <?= json_encode($lockSide) ?>;

var rows = [], page = 1, perPage = 20, total = 0;
var sortBy = 'billing_month', sortDir = 'desc';
var onlyDiff = false, onlyOpen = false;
var CSRF = '', roTarget = null;

var SIDE_LABEL = {ar:'應收', ap:'應付'};
var ST_LABEL   = {draft:'暫存中', confirmed:'已確認鎖帳', reopened:'已退回重對'};

function esc(s){ return String(s==null?'':s).replace(/[&<>"']/g,function(c){
  return {'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#39;'}[c]; }); }
function nf(n){ return Math.round(Number(n)||0).toLocaleString('en-US'); }
function toast(m,bad){
  var $m=$('#msg').removeClass('ok bad').addClass(bad?'bad':'ok').html(m).stop(true,true).fadeIn(150);
  clearTimeout($m.data('t')); $m.data('t',setTimeout(function(){ $m.fadeOut(400); },bad?6500:3600));
}
function openMask(id){ $('#'+id).addClass('show'); }
function closeMask(id){ $('#'+id).removeClass('show'); }
$(document).on('click','[data-close]',function(){ closeMask($(this).data('close')); });
$(document).on('click','.a-mask',function(e){ if(e.target===this) $(this).removeClass('show'); });
$('#btnRoleHelp').on('click',function(){ openMask('mkRole'); });
function shortT(t){ return t ? String(t).substr(5,11) : ''; }   // MM-DD HH:MM

/* 預設查上個月帳（月結對帳通常是結完上個月才對） */
(function initMonth(){
  var d=new Date(); d.setDate(1); d.setMonth(d.getMonth()-1);
  var m=d.getFullYear()+'-'+('0'+(d.getMonth()+1)).slice(-2);
  $('#bmFrom').val(m); $('#bmTo').val(m);
})();
function setMonth(offset){
  var d=new Date(); d.setDate(1); d.setMonth(d.getMonth()+offset);
  var m=d.getFullYear()+'-'+('0'+(d.getMonth()+1)).slice(-2);
  $('#bmFrom').val(m); $('#bmTo').val(m); page=1; load();
}
$('#btnThis').on('click',function(){ setMonth(0); });
$('#btnPrev').on('click',function(){ setMonth(-1); });
$('#btnAllMonth').on('click',function(){ $('#bmFrom').val(''); $('#bmTo').val(''); page=1; load(); });

function filters(){
  return {side: LOCK_SIDE || $('#side').val(), status:$('#status').val(),
          bm_from:$('#bmFrom').val(), bm_to:$('#bmTo').val(), kw:$('#kw').val(),
          only_diff:onlyDiff?1:0, only_open:onlyOpen?1:0,
          sort:sortBy, dir:sortDir, per_page:perPage, page:page};
}

function load(){
  $('#tbody').html('<tr><td colspan="12" style="padding:22px;color:#8a6d45;">查詢中…</td></tr>');
  $.post(API+'?action=sheet_list', filters(), function(r){
    if(!r.ok){ toast(esc(r.error||'查詢失敗'), true); return; }
    rows=r.rows||[]; total=r.total||0;
    var s=r.summary||{};
    $('#stCount').text(nf(s.count));
    $('#stDraft').text(nf(s.draft));
    $('#stConfirmed').text(nf(s.confirmed));
    $('#stReopened').text(nf(s.reopened));
    $('#stDiff').text(nf(s.diff_cnt));
    render(); renderPager();
  },'json').fail(function(){ toast('查詢失敗，請稍後再試', true); });
}

function render(){
  if(!rows.length){
    $('#tbody').html('<tr><td colspan="12" style="padding:22px;color:#8a6d45;">'
      + ((onlyDiff||onlyOpen)
          ? '這個條件下沒有符合「只看…」篩選的對帳底稿。'
          : '這個條件下還沒有任何對帳底稿。到「對帳作業」開始對帳後，這裡就會出現。')
      + '</td></tr>');
    return;
  }
  var h='';
  rows.forEach(function(r){
    var diffCell;
    if(r.diff===null) diffCell='<span class="d-none">未填</span>';
    else if(Math.abs(r.diff)<=0.01) diffCell='<span class="d-zero">✓ 0</span>';
    else diffCell='<span class="d-bad">'+(r.diff>0?'+':'')+nf(r.diff)+'</span>';

    var link='reconcile.php?side='+encodeURIComponent(r.side)
           + '&party_id='+encodeURIComponent(r.party_id)
           + '&bm='+encodeURIComponent(r.billing_month);
    var ops='<a class="btn-mini go" href="'+link+'"><i class="fa fa-folder-open-o"></i> 開啟</a> '
          + '<button class="btn-mini btn-csv" data-side="'+esc(r.side)+'" data-pid="'+esc(r.party_id)
          + '" data-bm="'+esc(r.billing_month)+'"><i class="fa fa-file-text-o"></i> 底稿</button>';
    if(P.canAdmin && r.status==='confirmed'){
      ops += ' <button class="btn-mini btn-reopen" data-id="'+r.sheet_id+'" data-name="'
           + esc(r.party_name)+'" data-bm="'+esc(r.billing_month)+'">'
           + '<i class="fa fa-undo"></i> 退回</button>';
    }

    var who='';
    if(r.saved_by_name) who='<span class="who"><b>'+esc(r.saved_by_name)+'</b><br>'+esc(shortT(r.saved_at))+'</span>';
    var who2='';
    if(r.confirmed_by_name) who2='<span class="who"><b>'+esc(r.confirmed_by_name)+'</b><br>'+esc(shortT(r.confirmed_at))+'</span>';
    else if(r.reopen_by_name) who2='<span class="who" style="color:#DD5138;">退回：<b>'+esc(r.reopen_by_name)
      +'</b><br>'+esc(shortT(r.reopen_at))+'</span>';

    var tip = r.reopen_reason ? ' title="退回原因：'+esc(r.reopen_reason)+'"' : '';
    h+='<tr'+tip+'>'
      +'<td><span class="pill sd-'+esc(r.side)+'">'+esc(SIDE_LABEL[r.side]||r.side)+'</span></td>'
      +'<td class="l"><b>'+esc(r.party_name)+'</b>'
        +(r.party_id&&r.party_id!==r.party_name?' <span style="color:#a08a6a;font-size:11px;">'+esc(r.party_id)+'</span>':'')+'</td>'
      +'<td>'+esc(r.billing_month)+'</td>'
      +'<td><span class="pill st-'+esc(r.status)+'">'+esc(ST_LABEL[r.status]||r.status)+'</span></td>'
      +'<td class="r">'+nf(r.checked_cnt)+' / '+nf(r.line_cnt)+'</td>'
      +'<td class="r">'+(r.adj_cnt?'<b style="color:#B9793C;">'+nf(r.adj_cnt)+'</b>':'')+'</td>'
      +'<td class="r"><b>'+nf(r.our_total)+'</b></td>'
      +'<td class="r">'+(r.their_total===null?'<span class="d-none">—</span>':nf(r.their_total))+'</td>'
      +'<td class="r">'+diffCell+'</td>'
      +'<td>'+who+'</td><td>'+who2+'</td>'
      +'<td>'+ops+'</td></tr>';
  });
  $('#tbody').html(h);
}

function renderPager(){
  var pages=Math.max(1,Math.ceil(total/perPage));
  if(page>pages) page=pages;
  var from=total?(page-1)*perPage+1:0, to=Math.min(page*perPage,total);
  $('#pgInfo').text('顯示 '+from+'–'+to+'，共 '+nf(total)+' 份'
    + (onlyDiff?'（只看有差額）':'') + (onlyOpen?'（只看還沒對完）':''));
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
$('#bmFrom,#bmTo,#side,#status').on('change',function(){ page=1; load(); });
$('#kw').on('dblclick',function(){ if($(this).val()!==''){ $(this).val(''); page=1; load(); } });

/* 這兩個是後端篩選（統計與分頁都會跟著變），不是只挑當頁 */
$('#btnOnlyDiff').on('click',function(){
  onlyDiff=!onlyDiff; $(this).css({background:onlyDiff?'#B9793C':'', color:onlyDiff?'#fff':''});
  page=1; load();
});
$('#btnOnlyOpen').on('click',function(){
  onlyOpen=!onlyOpen; $(this).css({background:onlyOpen?'#B9793C':'', color:onlyOpen?'#fff':''});
  page=1; load();
});

$(document).on('click','#tbl thead th.sortable',function(){
  var k=$(this).data('sort');
  if(sortBy!==k){ sortBy=k; sortDir='desc'; }
  else { sortDir=(sortDir==='desc')?'asc':'desc'; }
  $('#tbl thead th.sortable').removeClass('asc desc');
  $(this).addClass(sortDir);
  page=1; load();
});

/* ══ 匯出 ══ */
function qs(o){ return Object.keys(o).map(function(k){
  return k+'='+encodeURIComponent(o[k]==null?'':o[k]); }).join('&'); }
$('#btnExport').on('click',function(){
  var f=filters(); delete f.page; delete f.per_page;
  window.location = API+'?action=sheet_list_export&'+qs(f);
});
$('#btnPrint').on('click',function(){ window.print(); });
$(document).on('click','.btn-csv',function(){
  window.location = API+'?action=sheet_export&'+qs({side:$(this).data('side'),
    party_id:$(this).data('pid'), billing_month:$(this).data('bm')});
});

/* ══ 退回重對（僅會計管理員） ══ */
$(document).on('click','.btn-reopen',function(){
  roTarget = {id: parseInt($(this).data('id'),10),
              name: String($(this).data('name')), bm: String($(this).data('bm'))};
  $('#roTitle').html('對象：<b>'+esc(roTarget.name)+'</b>　帳款月份：<b>'+esc(roTarget.bm)+'</b>');
  $('#roReason').val(''); $('#roErr').text('');
  openMask('mkReopen');
  setTimeout(function(){ $('#roReason').focus(); },120);
});
$('#roReason').on('input',function(){
  $('#roErr').text($.trim(this.value).length<2 ? '請填寫退回原因，至少 2 個字' : '');
});
$('#btnRoGo').on('click',function(){
  if(!roTarget) return;
  var reason=$.trim($('#roReason').val());
  if(reason.length<2){ $('#roErr').text('請填寫退回原因，至少 2 個字'); $('#roReason').focus(); return; }
  var $b=$(this).prop('disabled',true);
  $.post(API+'?action=sheet_reopen',{sheet_id:roTarget.id, reason:reason, csrf:CSRF},function(r){
    $b.prop('disabled',false);
    if(!r.ok){ $('#roErr').text(r.error||'退回失敗'); return; }
    closeMask('mkReopen'); toast(esc(r.message||'已退回重對')); load();
  },'json').fail(function(x){
    $b.prop('disabled',false);
    var m='退回失敗'; try{ m=JSON.parse(x.responseText).error||m; }catch(e){}
    $('#roErr').text(m);
  });
});

/* CSRF（退回重對要用）＋首次載入 */
$.getJSON(API,{action:'meta'},function(r){
  if(!r.ok){ toast(esc(r.error||'初始化失敗'), true); return; }
  CSRF=r.csrf;
}).fail(function(){ toast('無法連線到會計 API', true); });

load();
})(jQuery);
</script>
</body>
</html>
