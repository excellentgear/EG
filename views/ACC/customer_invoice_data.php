<?php
/**
 * views/ACC/customer_invoice_data.php — 客戶發票資料維護（會計模組第一步）
 *
 * 為什麼需要這頁：電子發票一定要有「買方統一編號＋買方名稱」，
 * 但 925 家有效客戶只有 12 家資料完整；近一年有出貨的 175 家中有 171 家缺資料。
 * 沒有這些資料，後面的應收對帳與發票轉出就算做好了也開不出發票。
 *
 * 功能：依「近期出貨金額」排序讓你先補最重要的客戶；可就地編輯；
 *       可從 ERP 匯出的 CSV 批次匯入（自動猜欄位對應→預覽差異→勾選套用）。
 * 統編一律做財政部檢查碼驗證，擋下打錯的號碼。
 * 資料一律走 src/store/Acc_API.php；權限 acc_lib.php（roles module='accounting'）
 */
session_start();
if (!isset($_SESSION['userName'])) {
    $_SESSION['lastpage'] = "../../views/ACC/customer_invoice_data.php";
    header("Location:../../index.php");
    exit;
}
include_once '../../src/common/_config.php';
include_once '../../src/common/DBConnection.php';
include_once '../../src/common/acc_lib.php';

$db      = (new DBConnection())->getPDO();
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
<title>客戶發票資料維護</title>
<link href="../../resource/css/bootstrap.css" rel="stylesheet">
<link href="../../resource/css/font-awesome.css" rel="stylesheet">
<link href="../../resource/css/nprogress.css" rel="stylesheet">
<link href="../../resource/css/custom.css" rel="stylesheet">
<style>
:root{
  --a-line:#E8D5B5; --a-line2:#D8BE93; --a-bg:#FDF8EF; --a-bg2:#FFF7E8;
  --a-ink:#5b3a1e; --a-ink2:#8a6d45; --a-brand:#8A5A2B;
  --a-acc:#F0A24B; --a-acc-d:#d98a33;
  --a-ok:#F7E0BD; --a-warn:#F0A24B; --a-bad:#DD5138;
}
#sidebar-menu{visibility:hidden;}
.right_col .page-title{margin:8px 0 4px;overflow:hidden;}

.a-bar{display:flex;flex-wrap:wrap;gap:6px;align-items:center;clear:both;
  border:1.5px solid var(--a-line);border-radius:8px;padding:8px 10px;margin-bottom:8px;background:var(--a-bg);}
.a-bar label{margin:0;font-size:13px;color:var(--a-ink);font-weight:normal;}
.a-bar input[type=text],.a-bar select,.a-bar button{
  height:32px;font-size:13px;line-height:1;padding:0 10px;border:1px solid var(--a-line2);
  border-radius:4px;background:#fff;color:var(--a-ink);}
.a-bar button{cursor:pointer;}
.a-bar button:hover{background:var(--a-ok);}
.a-bar .btn-warm{background:var(--a-acc);color:#fff;border-color:var(--a-acc-d);font-weight:bold;}
.a-bar .btn-warm:hover{background:var(--a-acc-d);}
#kw{width:260px;}
.a-role{margin-left:auto;font-size:13px;color:var(--a-ink);background:var(--a-ok);
  border-radius:12px;padding:5px 12px;white-space:nowrap;}
.a-role .fa-question-circle{cursor:pointer;color:#b5762a;margin-left:5px;}

.a-stat{display:flex;flex-wrap:wrap;gap:20px;align-items:center;margin-bottom:8px;
  border:1.5px solid var(--a-line);border-radius:8px;padding:9px 14px;background:var(--a-bg2);}
.a-stat .n{font-size:20px;font-weight:bold;color:var(--a-brand);}
.a-stat .n.bad{color:var(--a-bad);}
.a-stat .l{font-size:12px;color:var(--a-ink2);}
.a-stat .note{margin-left:auto;font-size:12px;color:var(--a-ink2);max-width:430px;line-height:1.5;}

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
table.a-t tbody tr.dirty{background:#FCEBD2 !important;}

.cell-in{width:100%;min-width:90px;height:26px;border:1px solid transparent;border-radius:4px;
  font-size:13px;padding:0 5px;color:var(--a-ink);background:transparent;}
.cell-in:hover{border-color:var(--a-line2);background:#fff;}
.cell-in:focus{border-color:var(--a-acc);background:#FFFBF3;outline:none;}
.cell-in.wide{min-width:180px;}
.cell-in.bad{border-color:var(--a-bad);background:#FDECE7;}
.cell-in[readonly]{background:transparent;border-color:transparent;cursor:default;}

.pill{display:inline-block;padding:1px 8px;border-radius:9px;font-size:11px;line-height:17px;}
.s-ok     {background:var(--a-ok);color:#6b4522;}
.s-no_tax {background:#EFE6D6;color:#8a6d45;}
.s-bad_tax{background:var(--a-bad);color:#fff;}
.s-no_full{background:var(--a-warn);color:#fff;}

.a-mask{display:none;position:fixed;top:0;left:0;right:0;bottom:0;width:100%;height:100%;
  background:rgba(60,40,20,.45);z-index:9999;overflow:auto;padding:40px 12px;}
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

.step{display:flex;gap:0;margin-bottom:12px;}
.step div{flex:1;text-align:center;font-size:12.5px;padding:6px 4px;background:#F3E7D2;color:#8a6d45;
  border-right:2px solid #fff;}
.step div:last-child{border-right:0;}
.step div.on{background:var(--a-acc);color:#fff;font-weight:bold;}
.step div.done{background:var(--a-ok);color:#6b4522;}

.chg-old{color:#a08a6a;text-decoration:line-through;font-size:12px;}
.chg-new{color:var(--a-brand);font-weight:bold;}

.a-msg{position:fixed;top:64px;right:18px;z-index:12000;min-width:250px;max-width:430px;
  padding:11px 15px;border-radius:6px;display:none;font-size:14px;box-shadow:0 3px 12px rgba(0,0,0,.2);}
.a-msg.ok {background:#F7E0BD;color:#5b3a1e;border-left:5px solid var(--a-acc);}
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
      <h2 style="margin:6px 0;"><i class="fa fa-id-card-o" style="color:#F0A24B;"></i> 客戶發票資料維護
        <small style="color:#8a6d45;">電子發票必要的統一編號與發票抬頭；預設依近期出貨金額排序，先補最重要的客戶</small></h2>
    </div>
    <div class="clearfix"></div>

<?php if (!$perms['canView']): ?>
    <div class="a-noperm">
      <h4><i class="fa fa-lock"></i> 無會計模組檢閱權限</h4>
      <p>請洽管理者於「使用者權限設定」指派「會計檢閱／會計登錄／會計管理員」角色。</p>
    </div>
<?php else: ?>
    <div class="a-bar">
      <label>顯示</label>
      <select id="statusSel">
        <option value="shipped_gap">近期有出貨且資料不全（最該處理）</option>
        <option value="shipped">近期有出貨（全部）</option>
        <option value="no_tax">缺統一編號</option>
        <option value="bad_tax">統編檢查碼錯誤</option>
        <option value="no_full">缺發票全名</option>
        <option value="ok">資料完整</option>
        <option value="all">全部客戶</option>
      </select>
      <label>出貨期間</label>
      <select id="monthsSel">
        <option value="6">近 6 個月</option>
        <option value="12" selected>近 12 個月</option>
        <option value="24">近 24 個月</option>
      </select>
      <input type="text" id="kw" placeholder="搜尋：客戶編號／簡稱／全名／統編">
      <button id="btnSearch" class="btn-warm"><i class="fa fa-search"></i> 查詢</button>
      <span class="a-role">目前角色：<b><?= htmlspecialchars($roleLbl) ?></b>
        <i class="fa fa-question-circle" id="btnRoleHelp" title="角色權限說明"></i></span>
    </div>

    <div class="a-bar" style="background:var(--a-bg2);">
      <?php if ($perms['canEdit']): ?>
      <button id="btnImport" class="btn-warm"><i class="fa fa-upload"></i> 從 ERP 匯入 CSV</button>
      <button id="btnTemplate"><i class="fa fa-download"></i> 下載匯入範本</button>
      <?php endif; ?>
      <button id="btnExport"><i class="fa fa-file-text-o"></i> 匯出目前清單CSV</button>
      <button id="btnPrint"><i class="fa fa-print"></i> 列印／PDF</button>
      <span class="a-hint" style="margin-left:10px;">
        統編會做財政部檢查碼驗證，打錯會擋下並標紅。表格內可直接修改，改完按 <kbd>Enter</kbd> 或點別處即存檔。
      </span>
    </div>

    <div class="a-stat">
      <div><span class="n" id="stShipped">—</span> <span class="l">近期有出貨的客戶</span></div>
      <div><span class="n bad" id="stGap">—</span> <span class="l">其中資料不全</span></div>
      <div><span class="n" id="stOk">—</span> <span class="l">資料完整</span></div>
      <div><span class="n" id="stNoTax">—</span> <span class="l">缺統編</span></div>
      <div><span class="n" id="stBadTax">—</span> <span class="l">統編錯誤</span></div>
      <div class="note">開立電子發票必須有<b>買方統一編號</b>與<b>買方名稱（發票全名）</b>。
        沒補齊之前，後面的應收對帳可以照用，但發票轉出會被擋下。</div>
    </div>

    <div class="a-pager">
      <span id="pgInfo" style="margin-right:auto;color:#8a6d45;"></span>
      每頁 <select id="perPage"><option>5</option><option>10</option><option selected>20</option><option>50</option></select> 筆
      <span id="pgBtns"></span>
    </div>

    <div class="a-wrap">
      <table class="a-t" id="tbl">
        <thead><tr>
          <th class="sortable" data-sort="customer_id">客戶編號<i class="sa"></i></th>
          <th class="sortable" data-sort="customer">簡稱<i class="sa"></i></th>
          <th>客戶全名（發票抬頭）</th>
          <th>統一編號</th>
          <th>發票 email</th>
          <th>發票地址</th>
          <th>帳務聯絡人</th>
          <th class="sortable" data-sort="status">狀態<i class="sa"></i></th>
          <th class="sortable" data-sort="ship_cnt">出貨筆數<i class="sa"></i></th>
          <th class="sortable" data-sort="ship_amt">出貨金額<i class="sa"></i></th>
          <th class="sortable" data-sort="last_date">最後出貨<i class="sa"></i></th>
        </tr></thead>
        <tbody id="tbody"><tr><td colspan="11" style="padding:22px;color:#8a6d45;">載入中…</td></tr></tbody>
      </table>
    </div>
    <div class="a-hint">
      出貨金額用來排優先序：金額越大代表越常開發票給這家客戶，越該先補。
      出貨資料以客戶<b>簡稱</b>歸戶（近期 is_list 的 Client_id 多為空值，只能用簡稱對主檔），
      因此簡稱與主檔不一致的客戶會顯示 0 筆，可用匯入功能一併修正。
    </div>
<?php endif; ?>
  </div>
  <?php include '../partPage/footer.html' ?>
</div>
</div>

<?php if ($perms['canEdit']): ?>
<!-- CSV 匯入精靈 -->
<div class="a-mask" id="mkImport"><div class="a-modal">
  <div class="m-head"><i class="fa fa-upload"></i>&nbsp;從 ERP 匯入客戶發票資料
    <span class="m-close" data-close="mkImport">✕</span></div>
  <div class="m-body">
    <div class="step">
      <div id="sp1" class="on">1. 選擇檔案</div>
      <div id="sp2">2. 欄位對應</div>
      <div id="sp3">3. 確認差異</div>
    </div>

    <div id="impStep1">
      <p style="font-size:13px;color:#5b3a1e;">
        從 ERP 匯出客戶主檔（含統一編號、發票抬頭）存成 <b>CSV</b> 後上傳。
        支援 UTF-8 與 Big5 編碼；欄位順序不拘，下一步可以對應。
        不確定格式的話，先按工具列的「下載匯入範本」。
      </p>
      <input type="file" id="impFile" accept=".csv,text/csv" style="font-size:13px;">
      <div class="a-hint">匯入不會刪資料：CSV 裡留空的欄位一律<b>不覆蓋</b>既有值。</div>
    </div>

    <div id="impStep2" style="display:none;">
      <p style="font-size:13px;color:#5b3a1e;">
        請確認每個 CSV 欄位要對應到系統的哪個欄位（已自動猜測，不需要的選「略過」）。
        <span id="impRowCnt" style="color:#8A5A2B;"></span>
      </p>
      <div id="impMapBox"></div>
      <div class="a-hint">比對客戶的優先序：<b>客戶編號 → 統一編號 → 客戶簡稱 → 客戶全名</b>（完全相同才算比對成功）。</div>
    </div>

    <div id="impStep3" style="display:none;">
      <div id="impSummary" style="font-size:13px;color:#5b3a1e;margin-bottom:8px;"></div>
      <div class="a-bar" style="padding:6px 8px;">
        <button id="btnImpAll">全部勾選</button>
        <button id="btnImpNone">全部取消</button>
        <label style="margin-left:8px;"><input type="checkbox" id="impShowUnmatched"> 顯示比對不到的資料列</label>
      </div>
      <div id="impDiff"></div>
    </div>
  </div>
  <div class="m-foot">
    <span id="impSel" style="float:left;color:#5b3a1e;font-size:13px;line-height:32px;"></span>
    <button data-close="mkImport">取消</button>
    <button id="btnImpBack" style="display:none;">上一步</button>
    <button class="go" id="btnImpNext">下一步</button>
    <button class="go" id="btnImpApply" style="display:none;"><i class="fa fa-check"></i> 套用勾選的變更</button>
  </div>
</div></div>
<?php endif; ?>

<!-- 角色說明 -->
<div class="a-mask" id="mkRole"><div class="a-modal narrow">
  <div class="m-head">角色權限說明<span class="m-close" data-close="mkRole">✕</span></div>
  <div class="m-body" style="font-size:13.5px;color:#5b3a1e;line-height:1.9;">
    <b>管理者</b>：固定擁有全部權限。<br>
    <b>會計管理員</b>：會計登錄的全部權限，另可做會計設定與批次調整。<br>
    <b>會計登錄</b>：可修改客戶發票資料、執行 CSV 匯入。<br>
    <b>會計檢閱</b>：僅可查詢與匯出，不能修改。<br>
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
var CAN_EDIT = <?= $perms['canEdit'] ? 'true' : 'false' ?>;

var CSRF = '', rows = [], page = 1, perPage = 20, total = 0;
var sortBy = 'ship_amt', sortDir = 'desc';
var impHead = [], impGuess = {}, impPreview = null;

var STATUS_LABEL = {ok:'完整', no_tax:'缺統編', bad_tax:'統編錯誤', no_full:'缺發票全名'};

function esc(s){ return String(s==null?'':s).replace(/[&<>"']/g,function(c){
  return {'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#39;'}[c]; }); }
function nf(n){ return (Number(n)||0).toLocaleString('en-US'); }
function toast(m, bad){
  var $m=$('#msg').removeClass('ok bad').addClass(bad?'bad':'ok').html(m).stop(true,true).fadeIn(150);
  clearTimeout($m.data('t')); $m.data('t', setTimeout(function(){ $m.fadeOut(400); }, bad?6500:3600));
}
function openMask(id){ $('#'+id).addClass('show'); }
function closeMask(id){ $('#'+id).removeClass('show'); }
$(document).on('click','[data-close]',function(){ closeMask($(this).data('close')); });
$(document).on('click','.a-mask',function(e){ if(e.target===this) $(this).removeClass('show'); });
$('#btnRoleHelp').on('click',function(){ openMask('mkRole'); });

/* 統編檢查碼（與後端 acc_valid_tax_id 同一套規則，前端先擋一次） */
function validTaxId(t){
  t = String(t||'').replace(/\D/g,'');
  if(t.length!==8 || t==='00000000') return false;
  var w=[1,2,1,2,1,2,4,1], sum=0;
  for(var i=0;i<8;i++){ var p=parseInt(t[i],10)*w[i]; sum += Math.floor(p/10) + (p%10); }
  if(sum%5===0) return true;
  return (t[6]==='7' && (sum+1)%5===0);
}

/* UI 規則：有值雙擊清空、聚焦全選 */
function bindInputRules($scope){
  $scope.find('input[type=text]').off('.ar')
    .on('focus.ar', function(){ if(this.value!=='') try{ this.select(); }catch(e){} })
    .on('dblclick.ar', function(){ if(this.value!==''){ this.value=''; $(this).trigger('change'); } });
}

/* ══ 初始化 ══ */
$.getJSON(API, {action:'meta'}, function(r){
  if(!r.ok){ toast(esc(r.error||'初始化失敗'), true); return; }
  CSRF = r.csrf;
  load();
}).fail(function(){ toast('無法連線到會計 API', true); });

function filters(){
  return {
    kw:$('#kw').val(), status:$('#statusSel').val(), months:$('#monthsSel').val(),
    sort:sortBy, dir:sortDir, per_page:perPage, page:page
  };
}

function load(){
  $('#tbody').html('<tr><td colspan="11" style="padding:22px;color:#8a6d45;">查詢中…</td></tr>');
  $.post(API+'?action=customers', filters(), function(r){
    if(!r.ok){ toast(esc(r.error||'查詢失敗'), true); return; }
    rows = r.rows||[]; total = r.total||0;
    var s = r.summary||{};
    $('#stShipped').text(nf(s.shipped));
    $('#stGap').text(nf(s.shipped_no_tax));
    $('#stOk').text(nf(s.ok));
    $('#stNoTax').text(nf(s.no_tax));
    $('#stBadTax').text(nf(s.bad_tax));
    render(); renderPager();
  },'json').fail(function(){ toast('查詢失敗', true); });
}

function cell(r, col, wide){
  var v = r[col]==null ? '' : r[col];
  var bad = (col==='tax_id' && v!=='' && !validTaxId(v)) ? ' bad' : '';
  return '<input type="text" class="cell-in'+(wide?' wide':'')+bad+'" data-col="'+col+'" value="'+esc(v)+'"'
       + (CAN_EDIT?'':' readonly')+'>';
}

function render(){
  if(!rows.length){
    $('#tbody').html('<tr><td colspan="11" style="padding:22px;color:#8a6d45;">'
      +'此條件下沒有客戶。可把「顯示」改成「全部客戶」。</td></tr>');
    return;
  }
  var h='';
  rows.forEach(function(r,i){
    h+='<tr data-i="'+i+'" data-cid="'+esc(r.customer_id)+'">'
      +'<td>'+esc(r.customer_id)+'</td>'
      +'<td class="l">'+esc(r.customer)+'</td>'
      +'<td class="l">'+cell(r,'customer_full',true)+'</td>'
      +'<td>'+cell(r,'tax_id')+'</td>'
      +'<td class="l">'+cell(r,'invoice_email',true)+'</td>'
      +'<td class="l">'+cell(r,'customer_address',true)+'</td>'
      +'<td class="l">'+cell(r,'billing_contact')+'</td>'
      +'<td><span class="pill s-'+r.status+'">'+esc(STATUS_LABEL[r.status]||r.status)+'</span></td>'
      +'<td class="r">'+nf(r.ship_cnt)+'</td>'
      +'<td class="r">'+nf(Math.round(r.ship_amt))+'</td>'
      +'<td>'+esc(r.last_date||'—')+'</td>'
      +'</tr>';
  });
  $('#tbody').html(h);
  bindInputRules($('#tbody'));
}

function renderPager(){
  var pages = Math.max(1, Math.ceil(total/perPage));
  if(page>pages) page=pages;
  var from = total? (page-1)*perPage+1 : 0, to = Math.min(page*perPage, total);
  $('#pgInfo').text('顯示 '+from+'–'+to+'，共 '+nf(total)+' 家');
  if(pages<=1 && total<=10){ $('#pgBtns').html(''); return; }
  var h='<button data-p="1" '+(page===1?'disabled':'')+'>«</button>'
      + '<button data-p="'+(page-1)+'" '+(page===1?'disabled':'')+'>‹</button>';
  var st=Math.max(1,page-2), en=Math.min(pages,st+4); st=Math.max(1,en-4);
  for(var p=st;p<=en;p++) h+='<button data-p="'+p+'" class="'+(p===page?'on':'')+'">'+p+'</button>';
  h+='<button data-p="'+(page+1)+'" '+(page===pages?'disabled':'')+'>›</button>'
   + '<button data-p="'+pages+'" '+(page===pages?'disabled':'')+'>»</button>';
  $('#pgBtns').html(h);
}
$(document).on('click','#pgBtns button',function(){
  var p=parseInt($(this).data('p'),10); if(p>=1){ page=p; load(); }
});
$('#perPage').on('change',function(){ perPage=parseInt(this.value,10)||20; page=1; load(); });
$('#btnSearch').on('click',function(){ page=1; load(); });
$('#kw').on('keydown',function(e){ if(e.key==='Enter'){ e.preventDefault(); page=1; load(); } });
$('#statusSel,#monthsSel').on('change',function(){ page=1; load(); });

$(document).on('click','#tbl thead th.sortable',function(){
  var k=$(this).data('sort');
  if(sortBy!==k){ sortBy=k; sortDir='desc'; }
  else { sortDir = (sortDir==='desc') ? 'asc' : 'desc'; }
  $('#tbl thead th.sortable').removeClass('asc desc');
  $(this).addClass(sortDir);
  page=1; load();
});

/* ══ 就地編輯（改完離開欄位或按 Enter 即存） ══ */
$(document).on('keydown','#tbody .cell-in',function(e){
  if(e.key==='Enter'){ e.preventDefault(); this.blur(); }
});
$(document).on('input','#tbody .cell-in[data-col=tax_id]',function(){
  var v=this.value.replace(/\D/g,'');
  $(this).toggleClass('bad', v!=='' && !validTaxId(v));
});
$(document).on('blur','#tbody .cell-in',function(){
  if(!CAN_EDIT) return;
  var $in=$(this), $tr=$in.closest('tr');
  var i=parseInt($tr.data('i'),10), r=rows[i], col=$in.data('col');
  var nv=$.trim($in.val()), ov=(r[col]==null?'':String(r[col]));
  if(col==='tax_id'){ nv=nv.replace(/\D/g,''); $in.val(nv); }
  if(nv===ov) return;

  if(col==='tax_id' && nv!=='' && !validTaxId(nv)){
    toast('統一編號 '+esc(nv)+' 檢查碼不正確，未存檔', true);
    $in.addClass('bad').focus();
    return;
  }
  var d={}; d[col]=nv;
  $.post(API+'?action=update_customer',
    {csrf:CSRF, customer_id:r.customer_id, data:JSON.stringify(d)},
    function(res){
      if(!res.ok){ toast(esc(res.error||'更新失敗'), true); $in.val(ov); return; }
      r[col]=nv;
      $tr.addClass('dirty');
      toast(esc(r.customer)+' 已更新');
      // 狀態可能改變，重算該列
      recalcStatus(i);
    },'json').fail(function(x){
      var m='更新失敗'; try{ m=JSON.parse(x.responseText).error||m; }catch(e){}
      toast(esc(m), true); $in.val(ov);
    });
});

function recalcStatus(i){
  var r=rows[i], st;
  var tax=$.trim(r.tax_id||''), full=$.trim(r.customer_full||'');
  if(tax==='') st='no_tax';
  else if(!validTaxId(tax)) st='bad_tax';
  else if(full==='') st='no_full';
  else st='ok';
  r.status=st;
  $('#tbody tr[data-i="'+i+'"] .pill')
    .attr('class','pill s-'+st).text(STATUS_LABEL[st]||st);
}

/* ══ 匯出 / 範本 / 列印 ══ */
function qs(o){ return Object.keys(o).map(function(k){ return k+'='+encodeURIComponent(o[k]); }).join('&'); }
$('#btnExport').on('click',function(){
  var f=filters(); delete f.page; delete f.per_page;
  window.location = API+'?action=export&'+qs(f);
});
$('#btnTemplate').on('click',function(){ window.location = API+'?action=template'; });
$('#btnPrint').on('click',function(){ window.print(); });

/* ══ CSV 匯入精靈 ══ */
if(CAN_EDIT){
  function setStep(n){
    [1,2,3].forEach(function(k){
      $('#sp'+k).removeClass('on done').addClass(k<n?'done':(k===n?'on':''));
      $('#impStep'+k).toggle(k===n);
    });
    $('#btnImpBack').toggle(n>1);
    $('#btnImpNext').toggle(n<3);
    $('#btnImpApply').toggle(n===3);
    $('#impSel').text('');
  }

  $('#btnImport').on('click',function(){
    impHead=[]; impGuess={}; impPreview=null;
    $('#impFile').val(''); $('#impMapBox').empty(); $('#impDiff').empty();
    setStep(1); openMask('mkImport');
  });

  $('#btnImpBack').on('click',function(){
    setStep($('#impStep3').is(':visible') ? 2 : 1);
  });

  $('#btnImpNext').on('click',function(){
    if($('#impStep1').is(':visible')) doReadHead();
    else doPreview();
  });

  function doReadHead(){
    var f=$('#impFile')[0].files[0];
    if(!f){ toast('請先選擇 CSV 檔', true); return; }
    var fd=new FormData(); fd.append('file', f); fd.append('csrf', CSRF);
    var $b=$('#btnImpNext').prop('disabled',true).text('讀取中…');
    $.ajax({url:API+'?action=import_head', type:'POST', data:fd, processData:false,
      contentType:false, dataType:'json'})
    .done(function(r){
      $b.prop('disabled',false).text('下一步');
      if(!r.ok){ toast(esc(r.error||'讀取失敗'), true); return; }
      impHead=r.head||[]; impGuess=r.guess||{};
      $('#impRowCnt').text('（檔案共 '+nf(r.row_count)+' 列資料）');
      var opts=[['','（略過此欄）'],['customer_id','客戶編號'],['customer','客戶簡稱'],
                ['customer_full','客戶全名(發票抬頭)'],['tax_id','統一編號'],
                ['invoice_email','發票email'],['customer_address','發票地址'],
                ['billing_contact','帳務聯絡人']];
      var h='<table class="a-t"><thead><tr><th>CSV 欄位</th><th>資料範例</th><th>對應到</th></tr></thead><tbody>';
      impHead.forEach(function(hd,i){
        var samp=(r.sample||[]).map(function(s){ return s[i]; })
                 .filter(function(x){ return x!=null && String(x).trim()!==''; }).slice(0,2).join(' / ');
        h+='<tr><td class="l"><b>'+esc(hd)+'</b></td><td class="l" style="color:#8a6d45;">'+esc(samp)+'</td><td>'
          +'<select class="imp-map" data-i="'+i+'" style="height:26px;font-size:12.5px;">';
        opts.forEach(function(o){
          h+='<option value="'+o[0]+'"'+((impGuess[i]||'')===o[0]?' selected':'')+'>'+esc(o[1])+'</option>';
        });
        h+='</select></td></tr>';
      });
      $('#impMapBox').html(h+'</tbody></table>');
      setStep(2);
    })
    .fail(function(x){
      $b.prop('disabled',false).text('下一步');
      var m='讀取失敗'; try{ m=JSON.parse(x.responseText).error||m; }catch(e){}
      toast(esc(m), true);
    });
  }

  function doPreview(){
    var map={}, used={};
    $('.imp-map').each(function(){
      var v=$(this).val(); if(!v) return;
      if(used[v]){ toast('欄位「'+esc(v)+'」被對應了兩次，請修正', true); map=null; return false; }
      used[v]=1; map[$(this).data('i')]=v;
    });
    if(map===null) return;
    if(!Object.keys(map).length){ toast('請至少對應一個欄位', true); return; }
    if(!used['customer_id'] && !used['tax_id'] && !used['customer'] && !used['customer_full']){
      toast('至少要有一個可用來比對客戶的欄位（客戶編號／統編／簡稱／全名）', true); return;
    }
    var f=$('#impFile')[0].files[0];
    var fd=new FormData(); fd.append('file', f); fd.append('csrf', CSRF);
    fd.append('map', JSON.stringify(map));
    var $b=$('#btnImpNext').prop('disabled',true).text('比對中…');
    $.ajax({url:API+'?action=import_preview', type:'POST', data:fd, processData:false,
      contentType:false, dataType:'json'})
    .done(function(r){
      $b.prop('disabled',false).text('下一步');
      if(!r.ok){ toast(esc(r.error||'比對失敗'), true); return; }
      impPreview=r; renderDiff(); setStep(3);
    })
    .fail(function(x){
      $b.prop('disabled',false).text('下一步');
      var m='比對失敗'; try{ m=JSON.parse(x.responseText).error||m; }catch(e){}
      toast(esc(m), true);
    });
  }

  var COL_LABEL={customer_full:'客戶全名', tax_id:'統一編號', invoice_email:'發票email',
                 customer_address:'發票地址', billing_contact:'帳務聯絡人'};

  function renderDiff(){
    var s=impPreview.summary||{};
    $('#impSummary').html('CSV 共 <b>'+nf(s.total)+'</b> 列：比對到 <b style="color:#8A5A2B;">'+nf(s.matched)
      +'</b> 家，其中 <b style="color:#8A5A2B;">'+nf(s.changed)+'</b> 家有變更、'+nf(s.same)
      +' 家資料相同；<b style="color:#DD5138;">'+nf(s.unmatched)+'</b> 列比對不到客戶'
      +(s.bad_tax? '；<b style="color:#DD5138;">'+nf(s.bad_tax)+'</b> 列統編檢查碼有誤（不會寫入）':''));

    var list=(impPreview.matched||[]).filter(function(m){ return Object.keys(m.changes||{}).length>0; });
    var h='';
    if(list.length){
      h+='<table class="a-t"><thead><tr><th style="width:32px;"><input type="checkbox" id="impAll" checked></th>'
        +'<th>客戶</th><th>比對方式</th><th class="l">將變更的欄位</th></tr></thead><tbody>';
      list.forEach(function(m,i){
        var chg='';
        Object.keys(m.changes).forEach(function(c){
          var x=m.changes[c];
          chg+='<div>'+esc(COL_LABEL[c]||c)+'：<span class="chg-old">'+esc(x.old||'(空)')
              +'</span> → <span class="chg-new">'+esc(x.new)+'</span></div>';
        });
        h+='<tr><td><input type="checkbox" class="imp-ck" data-i="'+i+'"'+(m.tax_bad?'':' checked')+'></td>'
          +'<td class="l">'+esc(m.customer_id)+' '+esc(m.customer)+'</td>'
          +'<td>'+esc(m.match_by)+'</td><td class="l">'+chg
          +(m.tax_bad?'<div style="color:#DD5138;">⚠ 此列統編檢查碼有誤，預設不勾選</div>':'')
          +'</td></tr>';
      });
      h+='</tbody></table>';
    } else {
      h+='<div style="padding:14px;color:#8a6d45;">沒有需要變更的資料（比對到的客戶資料都已相同）。</div>';
    }
    impPreview._list = list;

    var un=impPreview.unmatched||[];
    if(un.length){
      h+='<div id="impUnmatched" style="display:none;margin-top:10px;">'
        +'<div style="font-size:13px;color:#DD5138;margin-bottom:4px;">以下 '+nf(un.length)
        +' 列在系統找不到對應客戶（不會寫入，可能是新客戶或簡稱不一致）：</div>'
        +'<table class="a-t"><thead><tr><th>CSV列</th><th>客戶編號</th><th>簡稱</th><th>全名</th><th>統編</th></tr></thead><tbody>';
      un.slice(0,200).forEach(function(u){
        h+='<tr><td>'+u.row+'</td><td>'+esc(u.customer_id)+'</td><td class="l">'+esc(u.customer)
          +'</td><td class="l">'+esc(u.customer_full)+'</td><td>'+esc(u.tax_id)
          +(u.tax_bad?' <span style="color:#DD5138;">✗</span>':'')+'</td></tr>';
      });
      h+='</tbody></table>'+(un.length>200?'<div class="a-hint">（只顯示前 200 列）</div>':'')+'</div>';
    }
    $('#impDiff').html(h);
    updateImpSel();
  }

  $(document).on('change','#impAll',function(){ $('.imp-ck').prop('checked', this.checked); updateImpSel(); });
  $(document).on('change','.imp-ck', updateImpSel);
  $('#btnImpAll').on('click',function(){ $('.imp-ck').prop('checked',true); updateImpSel(); });
  $('#btnImpNone').on('click',function(){ $('.imp-ck').prop('checked',false); updateImpSel(); });
  $('#impShowUnmatched').on('change',function(){ $('#impUnmatched').toggle(this.checked); });
  function updateImpSel(){ $('#impSel').text('已勾選 '+$('.imp-ck:checked').length+' 家'); }

  $('#btnImpApply').on('click',function(){
    var items=[];
    $('.imp-ck:checked').each(function(){
      var m=impPreview._list[parseInt($(this).data('i'),10)];
      if(m) items.push({customer_id:m.customer_id, changes:m.changes});
    });
    if(!items.length){ toast('請先勾選要套用的客戶', true); return; }
    if(!confirm('確定套用 '+items.length+' 家客戶的變更？\n（CSV 留空的欄位不會覆蓋既有資料）')) return;
    var $b=$(this).prop('disabled',true).html('<i class="fa fa-spinner fa-spin"></i> 套用中…');
    $.post(API+'?action=import_apply', {csrf:CSRF, items:JSON.stringify(items)}, function(r){
      $b.prop('disabled',false).html('<i class="fa fa-check"></i> 套用勾選的變更');
      if(!r.ok){ toast(esc(r.error||'套用失敗'), true); return; }
      closeMask('mkImport');
      toast(esc(r.message));
      if(r.errors && r.errors.length) toast(r.errors.map(esc).join('<br>'), true);
      load();
    },'json').fail(function(x){
      $b.prop('disabled',false).html('<i class="fa fa-check"></i> 套用勾選的變更');
      var m='套用失敗'; try{ m=JSON.parse(x.responseText).error||m; }catch(e){}
      toast(esc(m), true);
    });
  });
}
})(jQuery);
</script>
</body>
</html>
