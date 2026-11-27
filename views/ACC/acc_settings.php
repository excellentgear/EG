<?php
/** views/ACC/acc_settings.php — 會計設定：銀行代號維護，並集中各項會計設定的入口。資料走 src/store/Acc_API.php */
session_start();
if (!isset($_SESSION['userName'])) {
    $_SESSION['lastpage'] = "../../views/ACC/acc_settings.php";
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
<title>會計設定</title>
<link href="../../resource/css/bootstrap.css" rel="stylesheet">
<link href="../../resource/css/font-awesome.css" rel="stylesheet">
<link href="../../resource/css/nprogress.css" rel="stylesheet">
<link href="../../resource/css/custom.css" rel="stylesheet">
<style>
:root{
  --a-line:#E8D5B5; --a-line2:#D8BE93; --a-bg:#FDF8EF; --a-bg2:#FFF7E8;
  --a-ink:#5b3a1e; --a-ink2:#8a6d45; --a-brand:#8A5A2B;
  --a-acc:#F0A24B; --a-acc-d:#d98a33; --a-ok:#F7E0BD; --a-bad:#DD5138;
}
#sidebar-menu{visibility:hidden;}
.right_col .page-title{margin:8px 0 4px;overflow:hidden;}

.a-bar{display:flex;flex-wrap:wrap;gap:6px;align-items:center;clear:both;
  border:1.5px solid var(--a-line);border-radius:8px;padding:8px 10px;margin-bottom:8px;background:var(--a-bg);}
.a-bar label{margin:0;font-size:13px;color:var(--a-ink);font-weight:normal;}
.a-bar input[type=text],.a-bar input[type=date],.a-bar input[type=number],.a-bar select,.a-bar button{
  height:32px;font-size:13px;line-height:1;padding:0 10px;border:1px solid var(--a-line2);
  border-radius:4px;background:#fff;color:var(--a-ink);}
.a-bar input[type=number]{-moz-appearance:textfield;appearance:textfield;}
.a-bar input[type=number]::-webkit-outer-spin-button,
.a-bar input[type=number]::-webkit-inner-spin-button{-webkit-appearance:none;margin:0;}
.a-bar button{cursor:pointer;}
.a-bar button:hover{background:var(--a-ok);}
.a-bar .btn-warm{background:var(--a-acc);color:#fff;border-color:var(--a-acc-d);font-weight:bold;}
.a-bar .btn-warm:hover{background:var(--a-acc-d);}
.a-role{margin-left:auto;font-size:13px;color:var(--a-ink);background:var(--a-ok);
  border-radius:12px;padding:5px 12px;white-space:nowrap;}
.a-role .fa-question-circle{cursor:pointer;color:#b5762a;margin-left:5px;}

.a-stat{display:flex;flex-wrap:wrap;gap:20px;align-items:center;margin-bottom:8px;
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
table.a-t td.l{text-align:left;}
table.a-t td.r{text-align:right;}
table.a-t tbody tr:nth-child(even){background:#FFFCF6;}
table.a-t tbody tr:hover{background:#FDF2E0;}

.pill{display:inline-block;padding:1px 8px;border-radius:9px;font-size:11px;line-height:17px;}
.p-full{background:var(--a-ok);color:#6b4522;}
.p-part{background:var(--a-acc);color:#fff;}
.p-none{background:var(--a-bad);color:#fff;}
.dt-ALLOWANCE{background:var(--a-bad);color:#fff;}
.btn-mini{height:24px;padding:0 8px;border:1px solid var(--a-line2);border-radius:4px;
  background:#fff;cursor:pointer;color:var(--a-ink);font-size:12px;}
.btn-mini:hover{background:var(--a-ok);}

.a-mask{display:none;position:fixed;top:0;left:0;right:0;bottom:0;width:100%;height:100%;
  background:rgba(60,40,20,.45);z-index:9999;overflow:auto;padding:34px 12px;}
.a-mask.show{display:block;}
.a-modal{background:#fff;border-radius:8px;width:900px;max-width:100%;margin:0 auto;
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

.frm{display:grid;grid-template-columns:100px 1fr 100px 1fr;gap:8px 10px;align-items:center;font-size:13px;color:var(--a-ink);}
.frm input,.frm select{height:31px;border:1px solid var(--a-line2);border-radius:4px;padding:0 8px;
  font-size:13px;color:var(--a-ink);width:100%;}
.frm input[type=number]{-moz-appearance:textfield;appearance:textfield;text-align:right;}
.frm input[type=number]::-webkit-outer-spin-button,
.frm input[type=number]::-webkit-inner-spin-button{-webkit-appearance:none;margin:0;}
.frm .full{grid-column:2 / span 3;}
.frm input.rc-fixed{border-color:transparent;background:transparent;font-weight:bold;text-align:left;padding-left:0;pointer-events:none;}
.frm input[type=number].rc-fixed{text-align:left;}
.frm label{margin:0;font-weight:normal;}

.alloc-in{width:110px;height:25px;text-align:right;border:1px solid var(--a-line2);
  border-radius:4px;font-size:12.5px;padding:0 5px;color:var(--a-ink);
  -moz-appearance:textfield;appearance:textfield;}
.alloc-in::-webkit-outer-spin-button,.alloc-in::-webkit-inner-spin-button{-webkit-appearance:none;margin:0;}
.alloc-in:focus{border-color:var(--a-acc);background:#FFFBF3;outline:none;}
.alloc-in.over{border-color:var(--a-bad);background:#FDECE7;}

.info{background:var(--a-bg2);border-left:5px solid var(--a-acc);color:var(--a-ink);
  padding:8px 12px;border-radius:4px;font-size:13px;margin-bottom:8px;line-height:1.6;}
.warn{background:#FBE3DC;color:#7a2c17;border-left:5px solid var(--a-bad);
  padding:8px 12px;border-radius:4px;font-size:13px;margin-bottom:8px;}

.a-msg{position:fixed;top:64px;right:18px;z-index:12000;min-width:250px;max-width:440px;
  padding:11px 15px;border-radius:6px;display:none;font-size:14px;box-shadow:0 3px 12px rgba(0,0,0,.2);}
.a-msg.ok{background:#F7E0BD;color:#5b3a1e;border-left:5px solid var(--a-acc);}
.a-msg.bad{background:#FBE3DC;color:#7a2c17;border-left:5px solid var(--a-bad);}
.a-noperm{border:1.5px solid var(--a-line);background:var(--a-bg);border-radius:8px;padding:26px;color:var(--a-ink);}
.a-hint{font-size:11.5px;color:var(--a-ink2);margin-top:5px;line-height:1.6;}
.a-tabs{display:flex;gap:0;margin-bottom:10px;clear:both;border-bottom:2px solid var(--a-acc);}
.a-tabs button{border:1px solid var(--a-line2);border-bottom:0;background:#F3E7D2;color:var(--a-ink2);
  padding:8px 20px;font-size:14px;cursor:pointer;border-radius:6px 6px 0 0;margin-right:3px;}
.a-tabs button.on{background:var(--a-acc);color:#fff;font-weight:bold;border-color:var(--a-acc-d);}
.od{color:#DD5138;font-weight:bold;}
.frm .sub{grid-column:1 / span 4;font-size:12px;color:var(--a-ink2);border-top:1px dashed var(--a-line);padding-top:4px;margin-top:2px;}
.frm .hide{display:none !important;}
.rc-hint{grid-column:2 / span 3;font-size:12px;color:var(--a-ink2);}
.sub-h{margin:12px 0 6px;font-weight:bold;font-size:13.5px;color:var(--a-ink);border-top:1px dashed var(--a-line);padding-top:8px;}
.rl-tbl td{padding:3px 4px;vertical-align:middle;}
.rl-tbl input,.rl-tbl select{height:29px;border:1px solid var(--a-line2);border-radius:4px;padding:0 6px;font-size:13px;width:100%;box-sizing:border-box;}
.rl-tbl input.rl-amt{text-align:right;}
.rl-tbl .two{display:flex;gap:4px;}
.rv-sec{margin:0 0 14px;}
.rv-sec h5{margin:0 0 6px;font-size:14px;color:var(--a-ink);}
.rv-t{width:100%;border-collapse:collapse;font-size:13px;}
.rv-t th,.rv-t td{border:1px solid var(--a-line);padding:4px 8px;}
.rv-t th{background:var(--a-bg2);}
.rv-t td.r{text-align:right;}
.rv-f{display:flex;gap:8px;align-items:center;flex-wrap:wrap;margin-top:8px;}
.rv-f input,.rv-f select{height:30px;border:1px solid var(--a-line2);border-radius:4px;padding:0 6px;}

@media print{
  .a-bar,.a-pager,.a-mask,.nav_menu,.left_col,footer{display:none !important;}
  .right_col{margin:0 !important;padding:0 !important;}
  table.a-t thead th{position:static;}
}

.set-card{display:flex;flex-direction:column;gap:4px;border:1.5px solid var(--a-line);border-radius:8px;padding:12px 14px;background:var(--a-bg);color:var(--a-ink);text-decoration:none;}
.set-card:hover{background:var(--a-bg2);border-color:var(--a-acc);}
.set-card i{color:var(--a-acc-d);font-size:20px;}
.set-card span{font-size:12.5px;color:var(--a-ink2);}
.page-help-btn{height:32px;padding:0 12px;border:1px solid var(--a-line2);border-radius:4px;background:#fff;color:var(--a-ink);font-size:13px;}
</style>
</head>
<body class="nav-sm">
<div class="container body">
<div class="main_container">
  <?php include '../partPage/sideAndTopBarMenu.html' ?>
  <div class="right_col" role="main">
    <div class="page-title" style="display:flex;align-items:center;flex-wrap:wrap;">
      <h2 style="margin:6px 0;"><i class="fa fa-cog" style="color:#F0A24B;"></i> 會計設定
        <small style="color:#8a6d45;">銀行代號維護，以及科目、入帳帳戶、電子發票等設定的集中入口</small></h2>
      <button type="button" class="page-help-btn" id="btnPageHelp" style="margin-left:auto;"><i class="fa fa-question-circle"></i> 使用說明</button>
    </div>
    <div class="clearfix"></div>

<?php if (!$perms['canView']): ?>
    <div class="a-noperm">
      <h4><i class="fa fa-lock"></i> 無會計模組檢閱權限</h4>
      <p>請洽管理者於「使用者權限設定」指派「會計檢閱／會計登錄／會計管理員」角色。</p>
    </div>
<?php else: ?>
    <div class="a-tabs">
      <button class="tab-btn on" data-tab="bank"><i class="fa fa-university"></i> 銀行代號</button>
      <button class="tab-btn" data-tab="others"><i class="fa fa-th-large"></i> 其他會計設定</button>
      <span class="a-role" style="align-self:center;">目前角色：<b><?= htmlspecialchars($roleLbl) ?></b></span>
    </div>

    <div id="paneBank">
      <div class="a-bar">
        <input type="text" id="bkKw" placeholder="代號／名稱／電話／地址" style="width:240px;" autocomplete="off">
        <select id="bkCat"><option value="">全部分類</option></select>
        <button id="bkSearch" class="btn-warm"><i class="fa fa-search"></i> 查詢</button>
        <?php if ($perms['canAdmin']): ?>
        <button id="bkNew"><i class="fa fa-plus"></i> 新增銀行代號</button>
        <span style="margin-left:auto;display:flex;gap:6px;align-items:center;">
          <input type="file" id="bkFile" accept=".xlsx" style="font-size:12px;">
          <button id="bkImport"><i class="fa fa-upload"></i> 匯入 xlsx</button>
        </span>
        <?php endif; ?>
      </div>
      <div class="a-hint" style="margin-bottom:6px;">銀行代號固定 <b>7 碼數字</b>，以文字儲存（開頭 0 不會被丟掉）。匯入檔欄位：編號／分類／名稱／電話／地址／備註；同代號會覆蓋更新，電話裡多餘的「-」會自動整理。<span id="bkTotal"></span></div>
      <div class="a-wrap">
        <table class="a-t"><thead><tr><th style="width:90px;">代號</th><th style="width:90px;">分類</th><th>名稱</th><th style="width:120px;">電話</th><th>地址</th><th>備註</th><th style="width:100px;"></th></tr></thead>
        <tbody id="bkBody"><tr><td colspan="7" style="padding:22px;color:#8a6d45;">載入中…</td></tr></tbody></table>
      </div>
      <div class="a-pager" style="display:flex;align-items:center;gap:8px;margin:8px 0;">
        <span id="bkInfo" style="font-size:13px;color:#8a6d45;"></span><span id="bkPg" style="margin-left:auto;"></span>
        <select id="bkPer"><option>20</option><option>50</option><option>10</option><option>5</option></select>
      </div>
    </div>

    <div id="paneOthers" style="display:none;">
      <div class="a-hint" style="margin-bottom:8px;">各項設定仍在原本的頁面操作，這裡集中成入口（點了會直接開啟該設定視窗）。</div>
      <div style="display:grid;grid-template-columns:repeat(auto-fill,minmax(280px,1fr));gap:10px;">
        <a class="set-card" href="voucher.php?open=acct"><i class="fa fa-book"></i><b>預設會計科目／科目表</b><span>銷貨收入、應收帳款、稅額等預設科目，科目表與分類</span></a>
        <a class="set-card" href="voucher.php?open=coacct"><i class="fa fa-bank"></i><b>本公司入帳帳戶</b><span>收款匯款要匯入的本公司帳戶（編號／名稱／銀行／帳號／科目），不是銀行代號</span></a>
        <a class="set-card" href="invoice_export.php?open=einv"><i class="fa fa-file-text-o"></i><b>電子發票設定</b><span>每張筆數上限、可往前開立天數、發票時間、補帳模式</span></a>
        <a class="set-card" href="../pages/master_data_management.php"><i class="fa fa-percent"></i><b>客戶折扣%範圍</b><span>客戶主檔勾「同意扣款百分比」後的可輸入範圍（管理員在客戶編輯視窗內修改）</span></a>
      </div>
    </div>
<?php endif; ?>
  </div>
  <?php include '../partPage/footer.html' ?>
</div>
</div>

<?php if ($perms['canAdmin']): ?>
<div class="a-mask" id="mkBk"><div class="a-modal narrow">
  <div class="m-head"><i class="fa fa-university"></i>&nbsp;<span id="bkTitle">銀行代號</span><span class="m-close" data-close="mkBk">✕</span></div>
  <div class="m-body"><div class="frm">
    <label>代號 <span style="color:#DD5138;">*</span></label>
    <div><input type="text" id="bfCode" maxlength="7" inputmode="numeric" data-eg-skip data-eg-hint="7 碼數字，開頭 0 要保留"><div id="bfErr" style="font-size:12px;color:#DD5138;min-height:16px;"></div></div>
    <label>分類</label><input type="text" id="bfCat" maxlength="30">
    <label>名稱 <span style="color:#DD5138;">*</span></label><input type="text" id="bfName" class="full" maxlength="80">
    <label>電話</label><input type="text" id="bfPhone" maxlength="40">
    <label>地址</label><input type="text" id="bfAddr" class="full" maxlength="150">
    <label>備註</label><input type="text" id="bfNote" class="full" maxlength="200">
  </div></div>
  <div class="m-foot"><button data-close="mkBk">取消</button><button class="go" id="bfSave"><i class="fa fa-check"></i> 存檔</button></div>
</div></div>
<?php endif; ?>

<div class="a-mask" id="helpUseMask"><div class="a-modal narrow">
  <div class="m-head">使用說明<span class="m-close" data-close="helpUseMask">✕</span></div>
  <div class="m-body" style="font-size:13.5px;line-height:1.9;color:#5b3a1e;">
    <b>銀行代號</b>：收款登錄支票時，「開票銀行」輸入 7 碼代號，系統即時顯示銀行名稱。這裡可查詢、新增、修改、刪除，或匯入 xlsx（會計管理員）。<br>
    <b>其他會計設定</b>：科目、本公司入帳帳戶、電子發票設定的入口。<br>
    <span style="color:#8a6d45;font-size:12.5px;">權限：檢閱者只能查詢；維護與匯入需「會計管理員」。</span>
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
$(document).ready(function () {
    var $am = $('#sidebar-menu .nav.side-menu > li.active');
    if ($am.length) { $am.removeClass('active').find('ul.child_menu').hide(); $am.find('li.current-page').removeClass('current-page'); }
    $('#sidebar-menu').css('visibility', 'visible');
});
(function ($) {
'use strict';
var API='../../src/store/Acc_API.php', CAN_ADMIN=<?= $perms['canAdmin'] ? 'true' : 'false' ?>, CSRF='', page=1, per=20, total=0;
function esc(s){ return String(s==null?'':s).replace(/[&<>"']/g,function(c){ return {'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#39;'}[c]; }); }
function toast(m,bad){ var $m=$('#msg').removeClass('ok bad').addClass(bad?'bad':'ok').html(m).stop(true,true).fadeIn(150);
  clearTimeout($m.data('t')); $m.data('t',setTimeout(function(){ $m.fadeOut(400); },bad?7000:3800)); }
function openMask(id){ $('#'+id).addClass('show'); } function closeMask(id){ $('#'+id).removeClass('show'); }
$(document).on('click','[data-close]',function(){ closeMask($(this).data('close')); });
$(document).on('click','.a-mask',function(e){ if(e.target===this) $(this).removeClass('show'); });
$('#btnPageHelp').on('click',function(){ openMask('helpUseMask'); });
$('.tab-btn').on('click',function(){ var t=$(this).data('tab'); $('.tab-btn').removeClass('on'); $(this).addClass('on'); $('#paneBank').toggle(t==='bank'); $('#paneOthers').toggle(t==='others'); });

var rows=[];
function load(){
  $('#bkBody').html('<tr><td colspan="7" style="padding:22px;color:#8a6d45;">查詢中…</td></tr>');
  $.post(API+'?action=bank_list',{kw:$('#bkKw').val(),category:$('#bkCat').val(),page:page,per_page:per},function(r){
    if(!r.ok){ toast(esc(r.error||'查詢失敗'),true); return; }
    rows=r.rows||[]; total=r.total||0;
    if(r.categories && $('#bkCat option').length<=1) r.categories.forEach(function(c){ $('#bkCat').append('<option>'+esc(c)+'</option>'); });
    $('#bkTotal').text('　符合 '+total.toLocaleString('en-US')+' 筆');
    var h=''; if(!rows.length) h='<tr><td colspan="7" style="padding:22px;color:#8a6d45;">沒有符合的銀行代號</td></tr>';
    rows.forEach(function(b,i){ h+='<tr><td><b>'+esc(b.code)+'</b></td><td>'+esc(b.category||'')+'</td><td class="l">'+esc(b.name)+'</td><td>'+esc(b.phone||'')+'</td><td class="l" style="font-size:12px;">'+esc(b.address||'')+'</td><td class="l" style="font-size:12px;">'+esc(b.note||'')+'</td><td>'
      +(CAN_ADMIN?'<button class="btn-mini bk-edit" data-i="'+i+'">修改</button> <button class="btn-mini bk-del" data-c="'+esc(b.code)+'">刪除</button>':'')+'</td></tr>'; });
    $('#bkBody').html(h);
    var pages=Math.max(1,Math.ceil(total/per)), from=total?(page-1)*per+1:0, to=Math.min(page*per,total);
    $('#bkInfo').text('顯示 '+from+'–'+to+'，共 '+total.toLocaleString('en-US')+' 筆');
    var st=Math.max(1,page-2), en=Math.min(pages,st+4); st=Math.max(1,en-4), g='<button data-p="1"'+(page===1?' disabled':'')+'>«</button><button data-p="'+(page-1)+'"'+(page===1?' disabled':'')+'>‹</button>';
    for(var p=st;p<=en;p++) g+='<button data-p="'+p+'" class="'+(p===page?'on':'')+'">'+p+'</button>';
    g+='<button data-p="'+(page+1)+'"'+(page===pages?' disabled':'')+'>›</button><button data-p="'+pages+'"'+(page===pages?' disabled':'')+'>»</button>';
    $('#bkPg').html(g);
  },'json').fail(function(){ toast('查詢失敗',true); });
}
$(document).on('click','#bkPg button',function(){ var p=parseInt($(this).data('p'),10); if(p>=1){ page=p; load(); } });
$('#bkPer').on('change',function(){ per=parseInt(this.value,10)||20; page=1; load(); });
$('#bkSearch').on('click',function(){ page=1; load(); });
$('#bkKw').on('keydown',function(e){ if(e.key==='Enter'){ e.preventDefault(); page=1; load(); } });
$('#bkCat').on('change',function(){ page=1; load(); });

if(CAN_ADMIN){
  var editing=null;
  function openBk(b){
    editing=b; $('#bkTitle').text(b?'修改銀行代號':'新增銀行代號');
    $('#bfCode').val(b?b.code:'').prop('readonly',!!b); $('#bfCat').val(b?b.category||'':''); $('#bfName').val(b?b.name:'');
    $('#bfPhone').val(b?b.phone||'':''); $('#bfAddr').val(b?b.address||'':''); $('#bfNote').val(b?b.note||'':''); $('#bfErr').text('');
    openMask('mkBk'); setTimeout(function(){ (b?$('#bfName'):$('#bfCode')).focus(); },80);
  }
  $('#bkNew').on('click',function(){ openBk(null); });
  $(document).on('click','.bk-edit',function(){ openBk(rows[parseInt($(this).data('i'),10)]); });
  $('#bfCode').on('input',function(){ var c=this.value.replace(/\D/g,'').slice(0,7); if(c!==this.value) this.value=c;
    $('#bfErr').text(c.length&&c.length<7?'須為 7 碼數字（目前 '+c.length+' 碼）':''); });
  $('#bfPhone').on('input',function(){ var v=this.value.replace(/[－–—]/g,'-').replace(/-{2,}/g,'-'); if(v!==this.value) this.value=v; });
  $('#bfPhone').on('blur',function(){ this.value=this.value.replace(/^-+|-+$/g,''); });
  $('#bfSave').on('click',function(){
    var d={code:$('#bfCode').val(),category:$.trim($('#bfCat').val()),name:$.trim($('#bfName').val()),phone:$.trim($('#bfPhone').val()),address:$.trim($('#bfAddr').val()),note:$.trim($('#bfNote').val())};
    if(!/^\d{7}$/.test(d.code)){ $('#bfErr').text('銀行代號須為 7 碼數字'); $('#bfCode').focus(); return; }
    if(!d.name){ toast('請填銀行名稱',true); $('#bfName').focus(); return; }
    $.post(API+'?action=bank_save',{csrf:CSRF,data:JSON.stringify(d)},function(r){
      if(!r.ok){ toast(esc(r.error||'儲存失敗'),true); return; } closeMask('mkBk'); toast('已儲存'); load();
    },'json').fail(function(x){ var m='儲存失敗'; try{ m=JSON.parse(x.responseText).error||m; }catch(e){} toast(esc(m),true); });
  });
  $(document).on('click','.bk-del',function(){
    if(!confirm('確定刪除銀行代號 '+$(this).data('c')+'？')) return;
    $.post(API+'?action=bank_del',{csrf:CSRF,code:$(this).data('c')},function(r){ if(!r.ok){ toast(esc(r.error||'刪除失敗'),true); return; } toast('已刪除'); load(); },'json');
  });
  $('#bkImport').on('click',function(){
    var f=$('#bkFile')[0].files[0]; if(!f){ toast('請先選擇 xlsx 檔案',true); return; }
    var fd=new FormData(); fd.append('csrf',CSRF); fd.append('file',f);
    var $b=$(this).prop('disabled',true).html('<i class="fa fa-spinner fa-spin"></i> 匯入中…');
    $.ajax({url:API+'?action=bank_import',type:'POST',data:fd,processData:false,contentType:false,dataType:'json'}).done(function(r){
      $b.prop('disabled',false).html('<i class="fa fa-upload"></i> 匯入 xlsx');
      if(!r.ok){ toast(esc(r.error||'匯入失敗'),true); return; }
      toast(esc(r.message)+((r.skipped&&r.skipped.length)?'<br>'+r.skipped.map(esc).join('<br>'):'')); $('#bkFile').val(''); page=1; load();
    }).fail(function(x){ $b.prop('disabled',false).html('<i class="fa fa-upload"></i> 匯入 xlsx'); var m='匯入失敗'; try{ m=JSON.parse(x.responseText).error||m; }catch(e){} toast(esc(m),true); });
  });
}
$.getJSON(API,{action:'meta'},function(r){ if(r.ok){ CSRF=r.csrf; } load(); }).fail(function(){ toast('無法連線到會計 API',true); });
})(jQuery);
</script>
</body>
</html>
