<?php
/**
 * views/ACC/allowance.php — 折讓單（會計模組）
 *
 * 會計從「出貨單／退貨單」明細挑出要折讓的幾筆（可混合、多筆，限同一客戶），
 * 帶入原數量與單價（可改成部分折讓），自動算單筆金額與營業稅；開立後由會計填入
 * 電子發票平台提供的「折讓證明單號碼」，並可上傳附件。
 * 開立後，對帳進度追蹤（recon_track.php）該客戶會顯示「有折讓」，折讓視為下個帳款月份的對帳項目。
 *
 * 規則與計算一律在 src/common/acc_allowance_lib.php；資料走 src/store/AccAllowance_API.php。
 * 權限：aca_perms()（roles module='acc_allowance'）：全站管理員／本頁管理員(改設定)／會計(開立·填號·作廢)／唯讀。
 */
session_start();
if (!isset($_SESSION['userName'])) {
    $_SESSION['lastpage'] = "../../views/ACC/allowance.php";
    header("Location:../../index.php");
    exit;
}
include_once '../../src/common/_config.php';
include_once '../../src/common/DBConnection.php';
include_once '../../src/common/acc_allowance_lib.php';

$db = (new DBConnection())->getPDO();
acc_ensure_schema($db);
aca_ensure_schema($db);
$accUser = acc_current_user($db);
$perms   = aca_perms($db, $accUser);
$roleLbl = $perms['isAdmin'] ? '管理者'
         : ($perms['canAdmin'] ? '本頁管理員'
         : ($perms['canEdit'] ? '會計'
         : ($perms['canView'] ? '唯讀' : '無權限')));
?>
<!DOCTYPE html>
<html lang="zh-Hant">
<head>
<meta charset="utf-8">
<meta http-equiv="X-UA-Compatible" content="IE=edge">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>折讓單</title>
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
.right_col .page-title{margin:8px 0 4px;overflow:hidden;clear:both;}
.page-help-btn{height:30px;font-size:13px;padding:0 12px;border:1px solid #d98a33;border-radius:15px;background:#F0A24B;color:#fff;cursor:pointer;}
.page-help-btn:hover{background:#d98a33;}
.help-doc{font-size:13px;color:#5b3a1e;line-height:1.75;}
.help-doc h4{color:#8A5A2B;border-bottom:2px solid #F7E0BD;padding-bottom:3px;margin:14px 0 6px;font-size:15px;}
.help-doc h4:first-child{margin-top:0;}
.help-doc b{color:#8A5A2B;}
.help-doc ul{margin:4px 0 8px;padding-left:20px;}
.help-doc .tip{background:#FFF7E8;border:1px dashed #F0A24B;border-radius:6px;padding:6px 10px;margin:6px 0;}

.a-bar{display:flex;flex-wrap:wrap;gap:6px;align-items:center;clear:both;
  border:1.5px solid var(--a-line);border-radius:8px;padding:8px 10px;margin-bottom:8px;background:var(--a-bg);}
.a-bar label{margin:0;font-size:13px;color:var(--a-ink);font-weight:normal;}
.a-bar input[type=text],.a-bar input[type=date],.a-bar input[type=month],.a-bar select,.a-bar button{
  height:32px;font-size:13px;line-height:1;padding:0 10px;border:1px solid var(--a-line2);
  border-radius:4px;background:#fff;color:var(--a-ink);}
.a-bar button{cursor:pointer;}
.a-bar button:hover{background:var(--a-ok);}
.a-bar .btn-warm{background:var(--a-acc);color:#fff;border-color:var(--a-acc-d);font-weight:bold;}
.a-bar .btn-warm:hover{background:var(--a-acc-d);}
.a-role{margin-left:auto;font-size:13px;color:var(--a-ink);background:var(--a-ok);border-radius:12px;padding:5px 12px;white-space:nowrap;}

.a-stat{display:flex;flex-wrap:wrap;gap:20px;align-items:center;margin-bottom:8px;
  border:1.5px solid var(--a-line);border-radius:8px;padding:9px 14px;background:var(--a-bg2);}
.a-stat .n{font-size:19px;font-weight:bold;color:var(--a-brand);}
.a-stat .l{font-size:12px;color:var(--a-ink2);}
.a-pager{display:flex;justify-content:flex-end;align-items:center;gap:6px;margin-bottom:4px;font-size:13px;color:var(--a-ink);}
.a-pager button{height:26px;min-width:28px;padding:0 8px;border:1px solid var(--a-line2);background:#fff;border-radius:4px;cursor:pointer;color:var(--a-ink);}
.a-pager button:hover:not(:disabled){background:var(--a-ok);}
.a-pager button.on{background:var(--a-acc);color:#fff;border-color:var(--a-acc-d);font-weight:bold;}
.a-pager button:disabled{opacity:.4;cursor:default;}
.a-pager select{height:26px;border:1px solid var(--a-line2);border-radius:4px;font-size:12px;}

.a-wrap{overflow-x:auto;border:1px solid var(--a-line);border-radius:6px;background:#fff;}
table.a-t{width:100%;border-collapse:collapse;font-size:14px;}
table.a-t th,table.a-t td{border:1px solid #EADFC8;padding:8px 10px;white-space:nowrap;text-align:center;}
table.a-t thead th{position:sticky;top:0;z-index:2;background:var(--a-ok);color:var(--a-ink);font-weight:bold;}
table.a-t td.l{text-align:left;}
table.a-t td.r{text-align:right;}
table.a-t td.wrap{white-space:normal;}
table.a-t tbody tr:nth-child(even){background:#FFFCF6;}
table.a-t tbody tr:hover,table.a-t tbody tr:hover td{background:#FDF2E0 !important;}
table.a-t tbody tr.picked,table.a-t tbody tr.picked td{background:#FCEBD2 !important;}
table.a-t tr.is-void td{color:#a08a6a;text-decoration:line-through;}
table.a-t tr.is-full td{color:#a08a6a;}
table.a-t tr.picked{background:#FCEBD2 !important;}
.pill{display:inline-block;padding:1px 8px;border-radius:9px;font-size:11px;line-height:17px;}
.p-ship{background:var(--a-ok);color:#6b4522;}
.p-ret{background:var(--a-bad);color:#fff;}
.p-void{background:#a08a6a;color:#fff;}
.p-nono{background:var(--a-acc);color:#fff;}
.btn-mini{height:24px;padding:0 8px;border:1px solid var(--a-line2);border-radius:4px;background:#fff;cursor:pointer;color:var(--a-ink);font-size:12px;}
.btn-mini:hover{background:var(--a-ok);}

.a-mask{display:none;position:fixed;top:0;left:0;right:0;bottom:0;width:100%;height:100%;
  background:rgba(60,40,20,.45);z-index:9999;overflow:auto;padding:30px 12px;}
.a-mask.show{display:block;}
.a-modal{background:#fff;border-radius:8px;width:1560px;max-width:100%;margin:0 auto;box-shadow:0 6px 30px rgba(0,0,0,.3);}
.a-modal.mid{width:1320px;}
.tight th,.tight td{padding:6px 5px !important;}
.err:not(:empty){background:#FBE3DC;border-left:5px solid #DD5138;padding:8px 12px;font-size:15px;border-radius:4px;margin:6px 0;color:#7a2c17;}
.pop-err{display:none;position:fixed;top:90px;left:50%;transform:translateX(-50%);z-index:12500;max-width:760px;min-width:360px;background:#FBE3DC;color:#7a2c17;border:2px solid #DD5138;border-left-width:8px;border-radius:8px;padding:18px 24px;font-size:18px;font-weight:bold;line-height:1.6;box-shadow:0 8px 30px rgba(0,0,0,.3);cursor:pointer;}
.pop-err small{display:block;font-weight:normal;font-size:12px;color:#8a6d45;margin-top:4px;}
.a-modal.narrow{width:620px;}
.a-modal .m-head{background:var(--a-ok);color:var(--a-ink);padding:9px 14px;font-weight:bold;border-radius:8px 8px 0 0;display:flex;align-items:center;}
.a-modal .m-close{margin-left:auto;cursor:pointer;font-size:17px;}
.a-modal .m-body{padding:16px 18px;max-height:80vh;overflow:auto;font-size:14px;}
.a-modal .m-foot{padding:10px 14px;border-top:1px solid var(--a-line);text-align:right;}
.a-modal .m-foot button{height:32px;padding:0 14px;border:1px solid var(--a-line2);border-radius:4px;background:#fff;cursor:pointer;color:var(--a-ink);margin-left:5px;}
.a-modal .m-foot button.go{background:var(--a-acc);color:#fff;border-color:var(--a-acc-d);font-weight:bold;}
.a-modal .m-foot button:disabled{opacity:.45;cursor:default;}

.sec{font-weight:bold;color:var(--a-brand);margin:10px 0 5px;font-size:14px;}
.srch{display:flex;flex-wrap:wrap;gap:6px;align-items:center;margin-bottom:6px;}
.srch input,.srch select{height:36px;font-size:14px !important;border:1px solid var(--a-line2);border-radius:4px;padding:0 8px;font-size:13px;color:var(--a-ink);}
.srch button{height:31px;padding:0 12px;border:1px solid var(--a-acc-d);border-radius:4px;background:var(--a-acc);color:#fff;font-weight:bold;cursor:pointer;}
.al-box{border:1px solid var(--a-line);border-radius:6px;max-height:360px;overflow:auto;background:#fff !important;padding:0;margin:0;box-shadow:none;}
.al-box table.a-t{margin:0;background:#fff;}
.al-box table.a-t tbody tr:nth-child(odd){background:#fff;}
.al-box table.a-t tbody tr:nth-child(even){background:#FFFCF6;}
.frm{display:grid;grid-template-columns:110px 1fr 110px 1fr;gap:8px 10px;align-items:center;font-size:13px;color:var(--a-ink);}
.frm input,.frm select{height:36px;border:1px solid var(--a-line2);border-radius:4px;padding:0 8px;font-size:13px;color:var(--a-ink);width:100%;}
.frm label{margin:0;font-weight:normal;}
.frm .full{grid-column:2 / span 3;}
.in-n{width:120px;height:32px;text-align:right;border:1px solid var(--a-line2);border-radius:4px;font-size:12.5px;padding:0 5px;color:var(--a-ink);-moz-appearance:textfield;appearance:textfield;}
.in-n::-webkit-outer-spin-button,.in-n::-webkit-inner-spin-button{-webkit-appearance:none;margin:0;}
.in-n.bad{border-color:var(--a-bad);background:#FDECE7;}
.err{color:var(--a-bad);font-size:12px;margin-top:2px;}
.info{background:var(--a-bg2);border-left:5px solid var(--a-acc);color:var(--a-ink);padding:8px 12px;border-radius:4px;font-size:13px;margin-bottom:8px;line-height:1.6;}
.a-msg{position:fixed;top:64px;right:18px;z-index:12000;min-width:250px;max-width:480px;padding:14px 18px;border-radius:6px;display:none;font-size:16px;box-shadow:0 3px 12px rgba(0,0,0,.2);}
.a-msg.ok{background:#F7E0BD;color:#5b3a1e;border-left:5px solid var(--a-acc);}
.a-msg.bad{background:#FBE3DC;color:#7a2c17;border-left:5px solid var(--a-bad);}
.a-noperm{border:1.5px solid var(--a-line);background:var(--a-bg);border-radius:8px;padding:26px;color:var(--a-ink);}
.a-hint{font-size:11.5px;color:var(--a-ink2);margin-top:5px;line-height:1.6;}
.tok{display:inline-block;background:var(--a-ok);border-radius:4px;padding:0 6px;margin:2px 4px 2px 0;font-family:Consolas,monospace;cursor:pointer;}
.tabs{display:flex;gap:4px;border-bottom:2px solid var(--a-line);margin:6px 0 10px;clear:both;}
.tabbtn{height:40px;padding:0 20px;border:1px solid var(--a-line2);border-bottom:none;border-radius:8px 8px 0 0;background:var(--a-bg2);color:var(--a-ink);font-size:15px;cursor:pointer;margin-bottom:-2px;}
.tabbtn:hover{background:var(--a-ok);}
.tabbtn.on{background:var(--a-acc);color:#fff;border-color:var(--a-acc-d);font-weight:bold;}
.a-modal.pane-box{width:100%;box-shadow:none;border:1px solid var(--a-line);}
.pane-box .m-body{max-height:none;overflow:visible;}
.pane-box .m-head{border-radius:8px 8px 0 0;}
body.mask-open{overflow:hidden;}
.cust-dd{display:none;position:fixed;z-index:11000;background:#fff;border:1.5px solid var(--a-acc);border-radius:6px;box-shadow:0 6px 20px rgba(0,0,0,.25);max-height:340px;overflow:auto;min-width:380px;}
.cust-dd .it{padding:8px 12px;cursor:pointer;font-size:14px;color:var(--a-ink);border-bottom:1px solid #F3E8D2;display:flex;gap:12px;}
.cust-dd .it:last-child{border-bottom:none;}
.cust-dd .it:hover,.cust-dd .it.on{background:var(--a-ok);}
.cust-dd .cid{font-family:Consolas,monospace;color:var(--a-brand);font-weight:bold;min-width:76px;}
.cust-dd .cfull{color:var(--a-ink2);font-size:12.5px;}
.cust-dd .none{padding:10px 12px;color:var(--a-ink2);font-size:13px;}
@media print{ .a-bar,.a-pager,.a-mask,.nav_menu,.left_col,footer{display:none !important;} .right_col{margin:0 !important;padding:0 !important;} }
</style>
</head>
<body class="nav-sm">
<div class="container body">
<div class="main_container">
  <?php include '../partPage/sideAndTopBarMenu.html' ?>
  <div class="right_col" role="main">
    <div class="page-title" style="display:flex;align-items:center;flex-wrap:wrap;">
      <h2 style="margin:6px 0;"><i class="fa fa-file-text-o" style="color:#F0A24B;"></i> 折讓單
        <small style="color:#8a6d45;">從出貨單／退貨單綁定開立折讓，填入電子發票平台的證明單號碼</small></h2>
      <button id="btnPageHelp" class="page-help-btn" style="margin-left:auto;"><i class="fa fa-question-circle"></i> 使用說明</button>
    </div>
    <div class="clearfix"></div>
<?php if (!$perms['canView']): ?>
    <div class="a-noperm"><h4><i class="fa fa-lock"></i> 沒有折讓單的使用權限</h4>
      <p>請洽管理者於「使用者權限設定」指派「折讓單」角色（本頁管理員／會計／唯讀）。</p></div>
<?php else: ?>
    <div class="tabs">
      <button type="button" class="tabbtn on" data-pane="list"><i class="fa fa-list"></i> 折讓單清單</button>
      <?php if ($perms['canEdit']): ?><button type="button" class="tabbtn" id="btnNew" data-pane="new"><i class="fa fa-plus"></i> 開立折讓單</button><?php endif; ?>
      <button type="button" class="tabbtn" id="btnLog" data-pane="log"><i class="fa fa-history"></i> 異動記錄</button>
    </div>
    <div id="paneList">
    <div class="a-bar">
      <input type="text" id="fKw" placeholder="證明單號碼／客戶／單號／料號／備註" style="width:280px;">
      <label>帳款月份</label><input type="month" id="fBm" style="width:140px;">
      <select id="fStatus"><option value="">全部狀態</option><option value="active">有效</option><option value="nonumber">尚未填證明單號碼</option><option value="void">已作廢</option></select>
      <button id="btnSearch" class="btn-warm"><i class="fa fa-search"></i> 查詢</button>
      <span class="a-role">目前角色：<b><?= htmlspecialchars($roleLbl) ?></b></span>
    </div>
    <div class="a-bar" style="background:var(--a-bg2);">
      <?php if ($perms['canAdmin']): ?><button id="btnFmt"><i class="fa fa-cog"></i> 設定（號碼格式／附件位置）</button><?php endif; ?>
      <a href="recon_track.php" style="text-decoration:none;"><button type="button"><i class="fa fa-tasks"></i> 對帳進度追蹤</button></a>
    </div>
    <div class="a-stat">
      <div><span class="n" id="stCount">—</span> <span class="l">張折讓單（符合條件）</span></div>
      <div><span class="n" id="stSum">—</span> <span class="l">有效折讓含稅合計</span></div>
    </div>
    <div class="a-pager">
      <span id="pgInfo" style="margin-right:auto;color:#8a6d45;"></span>
      每頁 <select id="perPage"><option>5</option><option>10</option><option selected>20</option><option>50</option></select> 筆
      <span id="pgBtns"></span>
    </div>
    <div class="a-wrap">
      <table class="a-t" id="tbl">
        <thead><tr>
          <th>折讓日期</th><th>帳款月份</th><th>客戶</th><th>證明單號碼</th><th>明細</th>
          <th>未稅</th><th>營業稅</th><th>含稅合計</th><th>附件</th><th>開立人</th><th>狀態</th><th>操作</th>
        </tr></thead>
        <tbody id="tbody"><tr><td colspan="12" style="padding:22px;color:#8a6d45;">載入中…</td></tr></tbody>
      </table>
    </div>
    </div><!-- /paneList -->
<?php if ($perms['canEdit']): ?>
<!-- 開立折讓單 -->
<div id="paneNew" class="pane" style="display:none;"><div class="a-modal pane-box">
  <div class="m-head"><i class="fa fa-plus"></i>&nbsp;開立折讓單</div>
  <div class="m-body">
    <div class="sec">① 搜尋出貨單／退貨單明細（點一列加入；同一張折讓單只能是同一個客戶）</div>
    <div class="srch">
      <span style="position:relative;display:inline-block;"><input type="text" id="sCust" placeholder="輸入客戶ID或名稱，從清單選擇" style="width:300px;" autocomplete="off"></span>
      <input type="text" id="sPart" placeholder="料號（可空白分隔多個關鍵字）" style="width:230px;">
      <input type="text" id="sNo" placeholder="出貨單號／退貨單號" style="width:190px;">
      <select id="sSrc"><option value="all">出貨＋退貨</option><option value="IS">只看出貨單</option><option value="IR">只看退貨單</option></select>
      <input type="date" id="sFrom" title="起日"> ~ <input type="date" id="sTo" title="迄日">
      <button id="btnSrch"><i class="fa fa-search"></i> 搜尋</button>
      <span id="srchInfo" style="font-size:12px;color:var(--a-ink2);"></span>
    </div>
    <div class="al-box"><table class="a-t" id="tSrch">
      <thead><tr><th style="width:84px;"></th><th>類別</th><th>單號</th><th>日期</th><th>客戶</th><th>料號</th><th>規格</th><th>數量</th><th>單價</th><th>金額</th><th>已折讓</th></tr></thead>
      <tbody><tr><td colspan="11" style="color:#8a6d45;padding:14px;">輸入客戶／料號／單號後按「搜尋」（都不填＝列出最近的單據）</td></tr></tbody>
    </table></div>

    <div class="sec">② 已選定的明細（折讓數量與單價可改；金額與營業稅自動計算）　<span id="selCust" style="color:var(--a-ink);font-weight:normal;"></span></div>
    <div class="srch" style="background:#FFF7E8;border:1px solid var(--a-line);border-radius:6px;padding:8px 10px;">
      <b style="color:var(--a-brand);">原發票資料　全部設成相同：</b>
      字軌 <input type="text" id="bTrack" maxlength="2" style="width:64px;text-transform:uppercase;" placeholder="AB" autocomplete="off">
      號碼 <input type="text" id="bNo" maxlength="8" style="width:120px;" placeholder="8碼數字" autocomplete="off">
      發票日期 <input type="text" id="bDate" maxlength="7" style="width:110px;" placeholder="民國年MMDD" data-eg-hint="民國年3碼＋MMDD，例如 1150929" autocomplete="off">
      <button type="button" id="btnBatchInv">套用到全部明細</button>
      <span id="bErr" class="err" style="margin:0;"></span>
      <span style="font-size:12px;color:var(--a-ink2);">套用後再改個別不同的那幾筆；之後新加入的明細也會自動帶入這組</span>
    </div>
    <div class="al-box" style="max-height:340px;"><table class="a-t" id="tSel">
      <thead><tr><th>類別</th><th>單號</th><th>日期</th><th>料號</th><th>原數量</th><th>原單價</th><th>折讓數量</th><th>折讓單價</th><th>金額</th><th>營業稅</th><th>發票字軌</th><th>發票號碼</th><th>發票日期</th><th></th></tr></thead>
      <tbody><tr><td colspan="14" style="color:#8a6d45;padding:14px;">尚未選定任何明細</td></tr></tbody>
    </table></div>
    <div id="selErr" class="err"></div>
    <div style="text-align:right;margin:6px 0;font-size:14px;color:var(--a-ink);">
      未稅 <b id="tNet">0</b>　營業稅 <b id="tTax">0</b>　含稅合計 <b id="tTot" style="color:var(--a-bad);">0</b>
    </div>

    <div class="sec">③ 折讓資料</div>
    <div class="frm">
      <label>折讓日期 <span style="color:#DD5138;">*</span></label><input type="date" id="nDate">
      <label>營業稅率</label><input type="number" id="nRate" step="0.01" min="0" max="1">
      <label>證明單號碼</label>
      <div class="full" style="display:flex;gap:6px;"><input type="text" id="nNo" maxlength="60" placeholder="電子發票網站提供的折讓證明單號碼（可先空白，之後再補）">
        <button type="button" class="btn-mini" id="btnSug" style="height:31px;white-space:nowrap;">帶入建議編號</button></div>
      <label>備註</label><input type="text" id="nNote" class="full" maxlength="500">
      <label>附件</label><input type="file" id="nFiles" class="full" multiple>
    </div>
    <div id="nErr" class="err"></div>
    <div class="a-hint">帳款月份依<b>原發票日期</b>（多筆取最早的一天）和該客戶的結帳日歸屬，並列入該月份的對帳進度追蹤（客戶名稱旁會顯示「有折讓」）。</div>
  </div>
  <div class="m-foot"><button data-pane="list">取消</button><button class="go" id="btnCreate"><i class="fa fa-check"></i> 開立折讓單</button></div>
</div></div>

<?php endif; ?>
<!-- 異動記錄 -->
<div id="paneLog" class="pane" style="display:none;"><div class="a-modal pane-box">
  <div class="m-head"><i class="fa fa-history"></i>&nbsp;折讓單異動記錄</div>
  <div class="m-body">
    <div class="srch">
      <input type="text" id="lKw" placeholder="證明單號碼／客戶／操作人／內容" style="width:260px;">
      <select id="lAct"><option value="">全部動作</option><option value="update">修改折讓內容</option><option value="set_no">修改證明單號碼</option><option value="void">作廢（刪除）</option><option value="attach_del">刪除附件</option><option value="setting">設定變更</option></select>
      <input type="date" id="lFrom"> ~ <input type="date" id="lTo">
      <button id="btnLogQ"><i class="fa fa-search"></i> 查詢</button>
      <span id="lInfo" style="font-size:12px;color:var(--a-ink2);margin-left:auto;"></span>
    </div>
    <div class="a-wrap"><table class="a-t"><thead><tr><th>時間</th><th>操作人</th><th>動作</th><th>折讓單</th><th>改了什麼</th><th>原因</th></tr></thead><tbody id="lBody"></tbody></table></div>
    <div class="a-pager" style="margin-top:6px;"><span id="lBtns"></span></div>
    <div class="a-hint">記錄只增不減：折讓單作廢後仍可在此查到是誰、何時、為什麼作廢；附件被刪也會留下檔名。</div>
  </div>
  <div class="m-foot"><button data-pane="list">關閉</button></div>
</div></div>

<?php endif; ?>
  </div>
  <?php include '../partPage/footer.html' ?>
</div>
</div>

<?php if ($perms['canView']): ?>
<!-- 折讓單明細 -->
<div class="a-mask" id="mkDtl"><div class="a-modal mid">
  <div class="m-head"><i class="fa fa-file-text-o"></i>&nbsp;<span id="dTitle">折讓單</span><span class="m-close" data-close="mkDtl">✕</span></div>
  <div class="m-body" id="dBody"></div>
  <div class="m-foot"><span id="dFootL" style="float:left;"></span><span id="dFootR"></span><button data-close="mkDtl">關閉</button></div>
</div></div>

<?php if ($perms['canAdmin']): ?>
<!-- 號碼格式設定 -->
<div class="a-mask" id="mkFmt"><div class="a-modal narrow">
  <div class="m-head"><i class="fa fa-cog"></i>&nbsp;折讓單設定<span class="m-close" data-close="mkFmt">✕</span></div>
  <div class="m-body">
    <div class="sec" style="margin-top:0;">證明單號碼格式</div>
    <div class="info">格式只決定「帶入建議編號」的內容，<b>已建立的折讓單證明單號碼不會被改動</b>。實際號碼以電子發票網站為準，仍可手動修改。</div>
    <div class="frm" style="grid-template-columns:90px 1fr;"><label>格式</label><input type="text" id="fFmt" maxlength="60"></div>
    <div id="fErr" class="err"></div>
    <div class="sec">可用代碼（點一下加入）</div>
    <div id="fTok"></div>
    <div class="sec">預覽（以今天、客戶 MR003C 為例）</div>
    <div id="fPrev" style="font-family:Consolas,monospace;font-size:15px;color:var(--a-brand);"></div>
    <div class="a-hint">預設：<span id="fDef"></span>。流水號代碼可加「:位數」補零，例如 {CM_SEQ:2} 會產生 01、02…。</div>
    <div style="text-align:right;margin-top:6px;"><button id="btnFmtDef" class="btn-mini" style="height:28px;">還原預設</button> <button class="btn-mini" id="btnFmtSave" style="height:28px;background:var(--a-acc);color:#fff;">儲存格式</button></div>

    <div class="sec" style="margin-top:18px;border-top:1px solid var(--a-line);padding-top:10px;">附件儲存位置</div>
    <div class="info">目前使用：<b id="aEff" style="word-break:break-all;"></b><br>預設位置：<span id="aDef" style="word-break:break-all;"></span>（全站附件根資料夾＋「折讓單」）<br>已上傳附件：<b id="aCnt">0</b> 個</div>
    <input type="text" id="aDir" maxlength="240" style="width:100%;height:31px;border:1px solid var(--a-line2);border-radius:4px;padding:0 8px;" placeholder="自訂完整路徑；留空＝使用預設位置">
    <div id="aErr" class="err"></div>
    <div class="a-hint">換位置時，系統會先把已上傳的附件<b>複製</b>到新位置（舊位置的檔案不會刪除），全部複製成功才切換；資料庫只存檔名，所以之後再換位置也不會讀不到舊檔。</div>
    <div style="text-align:right;margin-top:6px;"><button class="btn-mini" id="btnAttSave" style="height:28px;background:var(--a-acc);color:#fff;">儲存附件位置</button></div>
  </div>
  <div class="m-foot"><button data-close="mkFmt">關閉</button></div>
</div></div>
<?php endif; ?>

<!-- 使用說明 -->
<div class="a-mask" id="helpUseMask"><div class="a-modal mid">
  <div class="m-head"><i class="fa fa-question-circle"></i>&nbsp;折讓單使用說明<span class="m-close" data-close="helpUseMask">✕</span></div>
  <div class="m-body"><div class="help-doc">
    <h4>這頁做什麼</h4>
    <ul><li>客戶的出貨或退貨有價格異動時，從<b>出貨單／退貨單</b>綁定開立折讓單，並記錄電子發票平台提供的<b>折讓證明單號碼</b>。</li>
    <li>一張折讓單可以綁定多筆出貨單與退貨單（可混合），但必須是<b>同一個客戶</b>。</li></ul>
    <h4>操作步驟</h4>
    <ul><li>按「開立折讓單」→ 用客戶名稱／客戶ID、料號（模糊比對）搜尋 → 點一列把明細加入。</li>
    <li>已選明細會帶入來源的數量與單價，可改成部分折讓；<b>金額＝數量×單價，營業稅逐筆四捨五入後加總</b>。</li>
    <li>填折讓日期、證明單號碼（可先空白、之後再補）、備註，可直接選附件，按「開立」。</li>
    <li>同一筆來源單據可分次折讓，系統會擋下超過原數量的折讓；作廢的折讓單會釋出數量。</li></ul>
    <h4>與對帳進度追蹤的關係</h4>
    <ul><li>帳款月份依<b>原發票日期</b>（一張折讓單多筆發票時取最早的一天）和客戶結帳日歸屬；沒有發票日期的舊折讓單則依折讓日期。對帳進度追蹤該月份的客戶名稱旁會顯示「<b>有折讓</b>」，點開可看綁定的單據。</li>
    <li>雙擊該客戶看明細時，折讓項目會以<b>暖黃底色置頂</b>，並註明折讓日期與開立人員 —— 即使原出貨在已對帳的舊月份，這筆折讓也視為這個月份尚待對帳。</li></ul>
    <h4>異動記錄</h4>
    <ul><li>只記錄<b>修改</b>與<b>刪除</b>（不記新增）：修改折讓內容／證明單號碼會記<b>修改前資料與修改後資料</b>，作廢（折讓單不做實體刪除，作廢即視為刪除）會記<b>刪除前的完整資料</b>，另有刪除附件、設定變更，都會自動記錄<b>時間、操作人、改了什麼</b>；按「異動記錄」查全部，或在單張明細底部看該張的記錄。記錄不能修改或刪除。</li></ul>
    <h4>證明單號碼格式</h4>
    <p>建議編號的年月日與本月流水號以<b>原發票日期</b>為準（多筆發票時取最早的一天），不是折讓日期。</p>
    <ul><li>預設格式「客戶ID-西元年月-本月流水」（例 MR003C-202608-1）。會計管理員可在「證明單號碼格式設定」修改，只影響之後的建議編號。</li></ul>
    <h4>權限</h4>
    <ul><li>全站管理員：全部權限；本頁管理員：全部權限含修改號碼格式；會計：開立、補填號碼、附件、作廢，不可更動設定；唯讀：只能查詢。角色在「使用者權限設定」的「折讓單」區塊指派。</li></ul>
  </div></div>
</div></div>
<?php endif; ?>

<div id="custDd" class="cust-dd"></div>
<div id="popErr" class="pop-err" title="點一下關閉"></div>
<div class="a-msg" id="msg"></div>
<script src="../../resource/js/jquery.min.js"></script>
<script src="../../resource/js/bootstrap.min.js"></script>
<script src="../../resource/js/fastclick.js"></script>
<script src="../../resource/js/nprogress.js"></script>
<script src="../../resource/js/custom.min.js"></script>
<script src="../../resource/js/eg_input_rules.js?v=<?= @filemtime(__DIR__.'/../../resource/js/eg_input_rules.js') ?: time() ?>"></script>
<script src="../../resource/js/eg_date_fmt.js?v=<?= @filemtime(__DIR__.'/../../resource/js/eg_date_fmt.js') ?: time() ?>"></script>
<script>
$(document).ready(function () {
    var $am = $('#sidebar-menu .nav.side-menu > li.active');
    if ($am.length) { $am.removeClass('active').find('ul.child_menu').hide(); $am.find('li.current-page').removeClass('current-page'); }
    $('#sidebar-menu').css('visibility', 'visible');
});
<?php if ($perms['canView']): ?>
(function ($) {
'use strict';
var API = '../../src/store/AccAllowance_API.php';
var CAN_EDIT = <?= $perms['canEdit'] ? 'true' : 'false' ?>, CAN_ADMIN = <?= $perms['canAdmin'] ? 'true' : 'false' ?>;
var CSRF = '', META = {}, page = 1, perPage = 20, SEL = [], SELCUST = null;

function esc(s){ return String(s==null?'':s).replace(/[&<>"']/g,function(c){ return {'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#39;'}[c]; }); }
function nf(n){ return Math.round(Number(n)||0).toLocaleString('en-US'); }
function nf2(n){ var v=Math.round((Number(n)||0)*100)/100; return v.toLocaleString('en-US',{maximumFractionDigits:2}); }
function dd(s){ return (window.egFmtDate && s) ? egFmtDate(s) : (s||''); }
function toast(m,bad){ var $m=$('#msg').removeClass('ok bad').addClass(bad?'bad':'ok').html(m).stop(true,true).fadeIn(150);
  clearTimeout($m.data('t')); $m.data('t',setTimeout(function(){ $m.fadeOut(400); },bad?7000:3800)); }
function syncBody(){ $('body').toggleClass('mask-open',$('.a-mask.show').length>0); }
var popT=null;
function popErr(m){ if(!m) return; $('#popErr').html(esc(m)+'<small>點一下關閉</small>').stop(true,true).fadeIn(120); clearTimeout(popT); popT=setTimeout(function(){ $('#popErr').fadeOut(300); },10000); }
$(document).on('click','#popErr',function(){ $(this).fadeOut(150); });
function openMask(id){ $('#'+id).addClass('show'); syncBody(); }
function closeMask(id){ $('#'+id).removeClass('show'); syncBody(); }
function showPane(n){
  $('#paneList,#paneNew,#paneLog').hide();
  $(n==='new'?'#paneNew':(n==='log'?'#paneLog':'#paneList')).show();
  $('.tabbtn').removeClass('on'); $('.tabbtn[data-pane="'+n+'"]').addClass('on');
  window.scrollTo(0,0);
}
$(document).on('click','[data-pane]',function(){ showPane($(this).data('pane')); });
$(document).on('click','[data-close]',function(){ closeMask($(this).data('close')); });
$(document).on('click','.a-mask',function(e){ if(e.target===this) closeMask(this.id); });
$('#btnPageHelp').on('click',function(){ openMask('helpUseMask'); });
function errMsg(x,d){ var m=d||'操作失敗'; try{ m=JSON.parse(x.responseText).error||m; }catch(e){} return m; }
function post(action,data,cb,fcb){
  data=data||{}; data.action=action; data.csrf=CSRF;
  $.post(API,data,function(r){ if(r.ok) cb(r); else (fcb||function(m){toast(esc(m),true);})(r.error); },'json')
   .fail(function(x){ (fcb||function(m){toast(esc(m),true);})(errMsg(x)); });
}

/* ── 清單 ── */
function load(){
  $.getJSON(API,{action:'list',kw:$('#fKw').val(),bm:$('#fBm').val(),status:$('#fStatus').val(),page:page,per_page:perPage},function(r){
    if(!r.ok){ toast(esc(r.error||'載入失敗'),true); return; }
    $('#stCount').text(nf(r.total)); $('#stSum').text(nf(r.sum_total));
    var h='';
    (r.rows||[]).forEach(function(a){
      var v=a.status==='void', noNo=!a.allow_no;
      h+='<tr class="'+(v?'is-void':'')+'"><td>'+esc(dd(a.allow_date))+'</td><td>'+esc(a.billing_month)+'</td>'
       +'<td class="l">'+esc(a.customer_id)+'　'+esc(a.customer_name)+'</td>'
       +'<td>'+(noNo?'<span class="pill p-nono">待填證明單號碼</span>':esc(a.allow_no))+'</td>'
       +'<td>'+a.item_cnt+' 筆</td><td class="r">'+nf(a.sales_amount)+'</td><td class="r">'+nf(a.tax_amount)+'</td><td class="r"><b>'+nf(a.total_amount)+'</b></td>'
       +'<td>'+(a.file_cnt>0?'<i class="fa fa-paperclip"></i> '+a.file_cnt:'—')+'</td><td>'+esc(a.created_by_name||'')+'</td>'
       +'<td>'+(v?'<span class="pill p-void">已作廢</span>':'有效')+'</td>'
       +'<td><button class="btn-mini js-dtl" data-id="'+a.allow_id+'">明細／附件</button></td></tr>';
    });
    $('#tbody').html(h||'<tr><td colspan="12" style="padding:20px;color:#8a6d45;">沒有符合的折讓單</td></tr>');
    var pages=Math.max(1,Math.ceil(r.total/r.per_page));
    $('#pgInfo').text('共 '+r.total+' 筆，第 '+page+' / '+pages+' 頁');
    var b='<button '+(page<=1?'disabled':'')+' data-p="'+(page-1)+'">‹</button>';
    for(var i=Math.max(1,page-2);i<=Math.min(pages,page+2);i++) b+='<button class="'+(i===page?'on':'')+'" data-p="'+i+'">'+i+'</button>';
    b+='<button '+(page>=pages?'disabled':'')+' data-p="'+(page+1)+'">›</button>';
    $('#pgBtns').html(b);
  }).fail(function(x){ toast(esc(errMsg(x,'載入失敗')),true); });
}
$('#pgBtns').on('click','button[data-p]',function(){ page=parseInt($(this).data('p'),10); load(); });
$('#perPage').on('change',function(){ perPage=parseInt(this.value,10); page=1; load(); });
$('#btnSearch').on('click',function(){ page=1; load(); });
$('#fKw').on('keydown',function(e){ if(e.key==='Enter'){ page=1; load(); } });
$('#fStatus,#fBm').on('change',function(){ page=1; load(); });

/* ── 開立 ── */
function selKey(r){ return r.type+':'+r.src_id; }
function resetNew(){
  SEL=[]; SELCUST=null;
  $('#sCust,#sPart,#sNo').val(''); CHOSEN_CID=''; $('#bTrack,#bNo,#bDate').val(''); $('#bErr').text(''); ddHide(); $('#sSrc').val('all'); $('#sFrom,#sTo').val('');
  $('#tSrch tbody').html('<tr><td colspan="11" style="color:#8a6d45;padding:14px;">輸入客戶／料號／單號後按「搜尋」（都不填＝列出最近的單據）</td></tr>');
  $('#srchInfo').text(''); $('#nDate').val(META.today); $('#nRate').val(META.tax_rate); $('#nNo,#nNote').val(''); $('#nFiles').val('');
  $('#nErr').text(''); renderSel();
}
var LASTROWS=[];
/* 退貨單有綁定出貨單時，在單號下方以小字顯示 */
function boundTxt(r){
  if(r.type!=='IR'||!r.bound_is||!r.bound_is.length) return '';
  return '<div style="font-size:11px;color:#8a6d45;line-height:1.3;">↳ 綁定出貨單 '+r.bound_is.map(function(b){ return esc(b.no)+'（'+nf2(b.qty)+'）'; }).join('、')+'</div>';
}
function renderSrch(rows){
  LASTROWS=rows;
  var h='';
  rows.forEach(function(r,i){
    var inSel=SEL.some(function(s){return selKey(s)===selKey(r);});
    var blocked=r.full || !r.customer_id || (SELCUST && r.customer_id!==SELCUST);
    var why=r.full?'已全數折讓':(!r.customer_id?'客戶未建主檔':((SELCUST&&r.customer_id!==SELCUST)?'與已選客戶不同':''));
    h+='<tr class="'+(inSel?'picked':'')+(r.full?' is-full':'')+'"><td>'+(inSel?'<button class="btn-mini js-rm" data-k="'+esc(selKey(r))+'">取消</button>'
        :(blocked?'<span style="color:#a08a6a;font-size:11px;">'+why+'</span>':'<button class="btn-mini js-add" data-i="'+i+'">加入</button>'))+'</td>'
     +'<td><span class="pill '+(r.type==='IR'?'p-ret':'p-ship')+'">'+(r.type==='IR'?'退貨':'出貨')+'</span></td>'
     +'<td>'+esc(r.no)+boundTxt(r)+'</td><td>'+esc(dd(r.date))+'</td><td class="l">'+esc(r.customer)+(r.customer_id?'':' <span style="color:#DD5138;">(未建主檔)</span>')+'</td>'
     +'<td class="l">'+esc(r.part)+'</td><td class="l" style="max-width:220px;overflow:hidden;text-overflow:ellipsis;" title="'+esc(r.spec)+'">'+esc(r.spec)+'</td>'
     +'<td class="r">'+nf2(r.qty)+'</td><td class="r">'+nf2(r.unit_price)+'</td><td class="r">'+nf(r.amount)+'</td>'
     +'<td class="r">'+(r.used_qty>0?nf2(r.used_qty):'')+'</td></tr>';
  });
  $('#tSrch tbody').html(h||'<tr><td colspan="11" style="color:#8a6d45;padding:14px;">沒有符合的單據</td></tr>');
}
/* 客戶即時建議：輸入ID或名稱立即列出符合的客戶，點選（或 ↑↓＋Enter）後以該客戶搜尋 */
var CHOSEN_CID='', ddT=null, ddItems=[], ddIdx=-1;
function ddPos(){ var r=$('#sCust')[0].getBoundingClientRect(); $('#custDd').css({left:r.left,top:r.bottom+2,minWidth:Math.max(380,r.width)}); }
function ddHide(){ $('#custDd').hide(); ddIdx=-1; }
function ddRender(list){
  ddItems=list; ddIdx=-1;
  var h=list.map(function(c,i){ return '<div class="it" data-i="'+i+'"><span class="cid">'+esc(c.customer_id)+'</span><span>'+esc(c.customer)+(Number(c.is_inactive)?' <span style="color:#DD5138;font-size:12px;">(停用)</span>':'')+(c.customer_full&&c.customer_full!==c.customer?'<br><span class="cfull">'+esc(c.customer_full)+'</span>':'')+'</span></div>'; }).join('');
  $('#custDd').html(h||'<div class="none">沒有符合的客戶</div>'); ddPos(); $('#custDd').show();
}
function ddQuery(){
  var kw=$.trim($('#sCust').val());
  $.getJSON(API,{action:'customer_suggest',kw:kw},function(r){ if(r.ok && kw===$.trim($('#sCust').val())) ddRender(r.customers||[]); });
}
function ddPick(i){
  var c=ddItems[i]; if(!c) return;
  CHOSEN_CID=c.customer_id; $('#sCust').val(c.customer_id+'　'+c.customer); ddHide(); doSearch();
}
$('#sCust').on('input',function(){ CHOSEN_CID=''; clearTimeout(ddT); ddT=setTimeout(ddQuery,150); })
  .on('focus',function(){ if(!CHOSEN_CID) ddQuery(); })
  .on('keydown',function(e){
    var open=$('#custDd').is(':visible'), n=$('#custDd .it').length;
    if(open && n && (e.key==='ArrowDown'||e.key==='ArrowUp')){
      e.preventDefault(); ddIdx=(ddIdx+(e.key==='ArrowDown'?1:-1)+n)%n;
      $('#custDd .it').removeClass('on').eq(ddIdx).addClass('on')[0].scrollIntoView({block:'nearest'});
    } else if(e.key==='Enter'){
      e.preventDefault(); e.stopImmediatePropagation();
      if(open && n){ ddPick(ddIdx>=0?ddIdx:0); } else { doSearch(); }
    } else if(e.key==='Escape'){ ddHide(); }
  });
$('#custDd').on('mousedown','.it',function(e){ e.preventDefault(); ddPick(parseInt($(this).data('i'),10)); });
$(document).on('mousedown',function(e){ if(!$(e.target).closest('#custDd,#sCust').length) ddHide(); });
$(window).on('scroll resize',function(){ if($('#custDd').is(':visible')) ddPos(); });

function doSearch(){
  $('#srchInfo').text('搜尋中…');
  $.getJSON(API,{action:'search',customer:CHOSEN_CID?'':$('#sCust').val(),customer_id:CHOSEN_CID,part:$('#sPart').val(),doc_no:$('#sNo').val(),src:$('#sSrc').val(),date_from:$('#sFrom').val(),date_to:$('#sTo').val()},function(r){
    if(!r.ok){ toast(esc(r.error||'搜尋失敗'),true); return; }
    $('#srchInfo').text('共 '+r.rows.length+' 筆'+(r.truncated?'（結果超過 300 筆，只顯示最近的，請加關鍵字縮小範圍）':''));
    renderSrch(r.rows);
  }).fail(function(x){ toast(esc(errMsg(x,'搜尋失敗')),true); });
}
$('#btnSrch').on('click',doSearch);
$('#sPart,#sNo').on('keydown',function(e){ if(e.key==='Enter'){ e.preventDefault(); doSearch(); } });
$('#tSrch').on('click','.js-add',function(){
  var r=LASTROWS[parseInt($(this).data('i'),10)]; if(!r) return;
  if(!SELCUST) SELCUST=r.customer_id;
  SEL.push($.extend({},r,{use_qty:r.remain_qty>0?r.remain_qty:r.qty,use_price:r.unit_price,inv_track:$('#bTrack').val(),inv_no:$('#bNo').val(),inv_date:$('#bDate').val()}));
  renderSel(); renderSrch(LASTROWS);
});
$('#tSrch').on('click','.js-rm',function(){ rmSel($(this).data('k')); });
function rmSel(k){ SEL=SEL.filter(function(s){return selKey(s)!==k;}); if(!SEL.length) SELCUST=null; renderSel(); renderSrch(LASTROWS); }
$('#tSel').on('click','.js-rm',function(){ rmSel($(this).data('k')); });
$('#tSel').on('input','.in-q,.in-p',function(){
  var i=parseInt($(this).data('i'),10), s=SEL[i]; if(!s) return;
  if($(this).hasClass('in-q')) s.use_qty=$(this).val(); else s.use_price=$(this).val();
  calcRow(i); calcTotal();
});
/* 發票欄位：字軌2碼英文大寫／號碼8碼數字／日期民國年3碼+MMDD（輸入當下即過濾與檢查） */
function cleanTrack(v){ return String(v||'').replace(/[^A-Za-z]/g,'').toUpperCase().slice(0,2); }
function cleanDigits(v,n){ return String(v||'').replace(/\D/g,'').slice(0,n); }
function rocToIso(s){
  var m=/^(\d{3})(\d{2})(\d{2})$/.exec(s); if(!m) return null;
  var y=parseInt(m[1],10)+1911, mo=parseInt(m[2],10), d=parseInt(m[3],10), t=new Date(y,mo-1,d);
  return (t.getFullYear()===y&&t.getMonth()===mo-1&&t.getDate()===d)?(y+'-'+m[2]+'-'+m[3]):null;
}
function invFieldErr(kind,v,adate){
  if(kind==='t') return /^[A-Z]{2}$/.test(v)?'':'字軌需為 2 碼英文大寫';
  if(kind==='n') return /^\d{8}$/.test(v)?'':'發票號碼需為 8 碼數字';
  var iso=rocToIso(v); if(!iso) return '日期格式：民國年3碼＋MMDD（例 1150929），需為存在的日期';
  var ad=adate||$('#nDate').val(); if(ad && iso>ad) return '發票日期不可晚於折讓日期';
  return '';
}
function invErr(s){ return invFieldErr('t',s.inv_track||'')||invFieldErr('n',s.inv_no||'')||invFieldErr('d',s.inv_date||''); }
$('#tSel').on('input','.in-it,.in-in,.in-id',function(){
  var i=parseInt($(this).data('i'),10), s=SEL[i]; if(!s) return;
  var kind=$(this).hasClass('in-it')?'t':($(this).hasClass('in-in')?'n':'d');
  var v=kind==='t'?cleanTrack(this.value):cleanDigits(this.value,kind==='n'?8:7);
  if(this.value!==v) this.value=v;
  s[kind==='t'?'inv_track':(kind==='n'?'inv_no':'inv_date')]=v;
  var er=invFieldErr(kind,v); $(this).toggleClass('bad',!!er&&v!=='').attr('title',er);
});
$('#bTrack').on('input',function(){ this.value=cleanTrack(this.value); });
$('#bNo').on('input',function(){ this.value=cleanDigits(this.value,8); });
$('#bDate').on('input',function(){ this.value=cleanDigits(this.value,7); });
$('#btnBatchInv').on('click',function(){
  var t=$('#bTrack').val(), n=$('#bNo').val(), d=$('#bDate').val();
  var er=invFieldErr('t',t)||invFieldErr('n',n)||invFieldErr('d',d);
  if(er){ $('#bErr').text(er); popErr(er); return; }
  if(!SEL.length){ $('#bErr').text('尚未選定明細（之後加入的明細會自動帶入這組）'); return; }
  $('#bErr').text(''); SEL.forEach(function(s){ s.inv_track=t; s.inv_no=n; s.inv_date=d; }); renderSel();
  toast('已把發票資料套用到全部 '+SEL.length+' 筆明細，可再改個別不同的');
});
$('#nDate').on('change',function(){ $('#tSel .in-id').each(function(){ var er=invFieldErr('d',this.value); $(this).toggleClass('bad',!!er&&this.value!=='').attr('title',er); }); });
function rate(){ var v=parseFloat($('#nRate').val()); return isNaN(v)?0:v; }
function rowVals(s){ var q=parseFloat(s.use_qty)||0, p=parseFloat(s.use_price)||0, amt=Math.round(q*p*100)/100; return {q:q,p:p,amt:amt,tax:Math.round(amt*rate())}; }
function rowErr(s){ var v=rowVals(s); if(v.q<=0) return '折讓數量要大於 0'; if(v.p<0) return '單價不可為負'; if(v.q-s.remain_qty>0.0001) return '超過尚可折讓數量 '+nf2(s.remain_qty); return ''; }
function calcRow(i){
  var s=SEL[i], v=rowVals(s), er=rowErr(s), $tr=$('#tSel tbody tr').eq(i);
  $tr.find('.c-amt').text(nf(v.amt)); $tr.find('.c-tax').text(nf(v.tax));
  $tr.find('.in-q').toggleClass('bad',!!er).attr('title',er); $tr.find('.c-err').text(er);
}
function calcTotal(){
  var net=0,tax=0,err='';
  SEL.forEach(function(s){ var v=rowVals(s); net+=v.amt; tax+=v.tax; if(!err) err=rowErr(s)?(s.no+'：'+rowErr(s)):''; });
  $('#tNet').text(nf(net)); $('#tTax').text(nf(tax)); $('#tTot').text(nf(net+tax)); $('#selErr').text(err);
}
function renderSel(){
  var h='';
  SEL.forEach(function(s,i){
    h+='<tr><td><span class="pill '+(s.type==='IR'?'p-ret':'p-ship')+'">'+(s.type==='IR'?'退貨':'出貨')+'</span></td><td>'+esc(s.no)+boundTxt(s)+'</td><td>'+esc(dd(s.date))+'</td>'
     +'<td class="l">'+esc(s.part)+'</td><td class="r">'+nf2(s.qty)+'</td><td class="r">'+nf2(s.unit_price)+'</td>'
     +'<td><input type="number" class="in-n in-q" data-i="'+i+'" step="any" value="'+esc(s.use_qty)+'"><div class="err c-err"></div></td>'
     +'<td><input type="number" class="in-n in-p" data-i="'+i+'" step="any" value="'+esc(s.use_price)+'"></td>'
     +'<td class="r c-amt"></td><td class="r c-tax"></td>'
     +'<td><input type="text" class="in-n in-it" data-i="'+i+'" maxlength="2" style="width:56px;text-align:center;text-transform:uppercase;" value="'+esc(s.inv_track||'')+'" autocomplete="off"></td>'
     +'<td><input type="text" class="in-n in-in" data-i="'+i+'" maxlength="8" style="width:112px;text-align:center;" value="'+esc(s.inv_no||'')+'" autocomplete="off"></td>'
     +'<td><input type="text" class="in-n in-id" data-i="'+i+'" maxlength="7" style="width:96px;text-align:center;" value="'+esc(s.inv_date||'')+'" autocomplete="off"></td>'
     +'<td><button class="btn-mini js-rm" data-k="'+esc(selKey(s))+'">移除</button></td></tr>';
  });
  $('#tSel tbody').html(h||'<tr><td colspan="14" style="color:#8a6d45;padding:14px;">尚未選定任何明細</td></tr>');
  $('#tSel .in-it,#tSel .in-in,#tSel .in-id').each(function(){ var k=$(this).hasClass('in-it')?'t':($(this).hasClass('in-in')?'n':'d'); var er=this.value===''?'':invFieldErr(k,this.value); $(this).toggleClass('bad',!!er).attr('title',er); });
  $('#selCust').text(SEL.length?('客戶：'+SELCUST+'　'+SEL[0].customer):'');
  SEL.forEach(function(s,i){ calcRow(i); }); calcTotal();
}
$('#nRate').on('input',function(){ SEL.forEach(function(s,i){ calcRow(i); }); calcTotal(); });
$('#btnSug').on('click',function(){
  if(!SELCUST){ $('#nErr').text('請先選定明細（客戶由明細決定）'); return; }
  // 號碼的年月日＝原發票日期（多筆取最早）；還沒填發票日期時才暫用折讓日期
  var isos=SEL.map(function(s){ return rocToIso(s.inv_date||''); }).filter(Boolean).sort();
  $.getJSON(API,{action:'suggest_no',customer_id:SELCUST,num_date:isos[0]||'',allow_date:$('#nDate').val()},function(r){
    if(r.ok){ $('#nNo').val(r.no); $('#nErr').text(isos.length?'':'提醒：還沒填原發票日期，號碼暫以折讓日期的年月帶入；填好發票日期後請再按一次「帶入建議編號」'); } else $('#nErr').text(r.error||'');
  }).fail(function(x){ $('#nErr').text(errMsg(x)); });
});
$('#btnNew').on('click',function(){ resetNew(); });
function nFail(m){ $('#nErr').text(m); popErr(m); }
$('#btnCreate').on('click',function(){
  nFail('');
  if(!SEL.length){ nFail('請至少選一筆出貨單或退貨單明細'); return; }
  for(var i=0;i<SEL.length;i++){ var e=rowErr(SEL[i])||invErr(SEL[i]); if(e){ nFail('第 '+(i+1)+' 筆（'+SEL[i].no+'）：'+e); return; } }
  if(!$('#nDate').val()){ nFail('請選折讓日期'); return; }
  var payload={customer_id:SELCUST,allow_date:$('#nDate').val(),tax_rate:$('#nRate').val(),allow_no:$('#nNo').val(),note:$('#nNote').val(),
    items:SEL.map(function(s){return {type:s.type,src_id:s.src_id,qty:s.use_qty,unit_price:s.use_price,inv_track:s.inv_track,inv_no:s.inv_no,inv_date:s.inv_date};})};
  var fd=new FormData(); fd.append('action','create'); fd.append('csrf',CSRF); fd.append('payload',JSON.stringify(payload));
  var files=$('#nFiles')[0].files; for(var j=0;j<files.length;j++) fd.append('files[]',files[j]);
  var $b=$(this).prop('disabled',true).html('<i class="fa fa-spinner fa-spin"></i> 開立中…');
  $.ajax({url:API,type:'POST',data:fd,processData:false,contentType:false,dataType:'json'}).done(function(r){
    $b.prop('disabled',false).html('<i class="fa fa-check"></i> 開立折讓單');
    if(!r.ok){ nFail(r.error||'開立失敗'); return; }
    showPane('list'); toast(esc(r.message)+((r.attach_fail&&r.attach_fail.length)?'<br>附件未上傳：'+esc(r.attach_fail.join('；')):''),!!(r.attach_fail&&r.attach_fail.length));
    page=1; load();
  }).fail(function(x){ $b.prop('disabled',false).html('<i class="fa fa-check"></i> 開立折讓單'); nFail(errMsg(x,'開立失敗')); });
});

/* ── 明細 ── */
var CUR=null;
function invCell(i){ return esc((i.inv_track||'')+(i.inv_no||''))+(i.inv_date?'<div style="font-size:11px;color:#8a6d45;">'+esc(String(i.inv_date).slice(0,3)+'.'+String(i.inv_date).slice(3,5)+'.'+String(i.inv_date).slice(5))+'</div>':''); }
/* 檢視：合計與「含稅合計」各自一格 */
function viewTable(a){
  var h='<div class="sec">綁定的單據</div><div class="a-wrap"><table class="a-t"><thead><tr><th>類別</th><th>單號</th><th>日期</th><th>料號</th><th>規格</th><th>原數量</th><th>原單價</th><th>折讓數量</th><th>折讓單價</th><th>金額</th><th>營業稅</th><th>原發票</th></tr></thead><tbody>';
  a.items.forEach(function(i){ h+='<tr><td><span class="pill '+(i.src_type==='IR'?'p-ret':'p-ship')+'">'+(i.src_type==='IR'?'退貨':'出貨')+'</span></td><td>'+esc(i.src_no)+'</td><td>'+esc(dd(i.src_date))+'</td><td class="l">'+esc(i.part_no)+'</td><td class="l wrap">'+esc(i.spec)+'</td><td class="r">'+nf2(i.src_qty)+'</td><td class="r">'+nf2(i.src_price)+'</td><td class="r">'+nf2(i.qty)+'</td><td class="r">'+nf2(i.unit_price)+'</td><td class="r">'+nf(i.amount)+'</td><td class="r">'+nf(i.tax_amount)+'</td><td style="white-space:nowrap;">'+invCell(i)+'</td></tr>'; });
  h+='</tbody><tfoot><tr><td colspan="9" class="r"><b>合計</b></td><td class="r"><b>'+nf(a.sales_amount)+'</b></td><td class="r"><b>'+nf(a.tax_amount)+'</b></td><td></td></tr>'
    +'<tr><td colspan="9" class="r"><b>含稅合計</b></td><td colspan="3" class="r" style="font-size:16px;"><b style="color:#DD5138;">'+nf(a.total_amount)+'</b></td></tr></tfoot></table></div>';
  return h;
}
/* 修改模式 */
function editHead(a){
  return '<div class="info">客戶：<b>'+esc(a.customer_id)+'　'+esc(a.customer_name)+'</b>　（修改中：數量、單價、發票資料、折讓日期、稅率、備註可改；要換單據請作廢後重開）</div>'
   +'<div class="frm" style="grid-template-columns:100px 220px 100px 160px 80px 1fr;"><label>折讓日期</label><input type="date" id="eDate" value="'+esc(a.allow_date)+'"><label>營業稅率</label><input type="number" id="eRate" step="0.01" min="0" max="1" value="'+Number(a.tax_rate)+'"><label>備註</label><input type="text" id="eNote" maxlength="500" value="'+esc(a.note||'')+'"></div><div id="eErr" class="err"></div>';
}
function editTable(a){
  var h='<div class="sec">綁定的單據（修改數量／單價／原發票資料）</div>'
   +'<div class="srch" style="background:#FFF7E8;border:1px solid var(--a-line);border-radius:6px;padding:8px 10px;"><b style="color:var(--a-brand);">原發票資料　全部設成相同：</b> 字軌 <input type="text" id="ebTrack" maxlength="2" style="width:60px;text-transform:uppercase;" autocomplete="off"> 號碼 <input type="text" id="ebNo" maxlength="8" style="width:110px;" autocomplete="off"> 發票日期 <input type="text" id="ebDate" maxlength="7" style="width:100px;" placeholder="民國年MMDD" autocomplete="off"> <button type="button" id="btnEBatch">套用到全部明細</button><span style="font-size:12px;color:var(--a-ink2);">套用後再改個別不同的那幾筆</span></div>'
   +'<div class="a-wrap"><table class="a-t tight" id="tEdit"><thead><tr><th>類別</th><th>單號</th><th>料號</th><th>原數量</th><th>可折讓上限</th><th>折讓數量</th><th>折讓單價</th><th>金額</th><th>營業稅</th><th>發票字軌</th><th>發票號碼</th><th>發票日期</th></tr></thead><tbody>';
  a.items.forEach(function(i,k){
    h+='<tr data-id="'+i.item_id+'" data-rem="'+Number(i.remain_qty)+'"><td><span class="pill '+(i.src_type==='IR'?'p-ret':'p-ship')+'">'+(i.src_type==='IR'?'退貨':'出貨')+'</span></td><td>'+esc(i.src_no)+'</td><td class="l">'+esc(i.part_no)+'</td><td class="r">'+nf2(i.src_qty)+'</td><td class="r">'+nf2(i.remain_qty)+'</td>'
     +'<td><input type="number" class="in-n e-q" step="any" style="width:66px;" value="'+Number(i.qty)+'"></td><td><input type="number" class="in-n e-p" step="any" style="width:84px;" value="'+Number(i.unit_price)+'"></td><td class="r e-amt"></td><td class="r e-tax"></td>'
     +'<td><input type="text" class="in-n e-it" maxlength="2" style="width:50px;text-align:center;text-transform:uppercase;" value="'+esc(i.inv_track||'')+'"></td>'
     +'<td><input type="text" class="in-n e-in" maxlength="8" style="width:100px;text-align:center;" value="'+esc(i.inv_no||'')+'"></td>'
     +'<td><input type="text" class="in-n e-id" maxlength="7" style="width:84px;text-align:center;" value="'+esc(i.inv_date||'')+'"></td></tr>';
  });
  h+='</tbody><tfoot><tr><td colspan="7" class="r"><b>合計</b></td><td class="r"><b id="eNet">0</b></td><td class="r"><b id="eTax">0</b></td><td colspan="3"></td></tr>'
    +'<tr><td colspan="7" class="r"><b>含稅合計</b></td><td colspan="5" class="r" style="font-size:16px;"><b id="eTot" style="color:#DD5138;">0</b></td></tr></tfoot></table></div>';
  return h;
}
function eRate(){ var v=parseFloat($('#eRate').val()); return isNaN(v)?0:v; }
function eCalc(){
  var net=0,tax=0,err='',ad=$('#eDate').val();
  $('#tEdit tbody tr').each(function(){
    var $r=$(this), q=parseFloat($r.find('.e-q').val())||0, p=parseFloat($r.find('.e-p').val())||0, rem=parseFloat($r.data('rem'))||0;
    var amt=Math.round(q*p*100)/100, tx=Math.round(amt*eRate()); net+=amt; tax+=tx;
    $r.find('.e-amt').text(nf(amt)); $r.find('.e-tax').text(nf(tx));
    var qe=q<=0?'折讓數量要大於 0':(q-rem>0.0001?'超過可折讓上限 '+nf2(rem):''); $r.find('.e-q').toggleClass('bad',!!qe).attr('title',qe);
    var ie=[['.e-it','t'],['.e-in','n'],['.e-id','d']].map(function(c){ var $x=$r.find(c[0]), er=invFieldErr(c[1],$x.val(),ad); $x.toggleClass('bad',!!er&&$x.val()!=='').attr('title',er); return er; }).filter(Boolean)[0]||'';
    if(!err) err=qe?($r.children().eq(1).text()+'：'+qe):(ie?($r.children().eq(1).text()+'：'+ie):'');
  });
  $('#eNet').text(nf(net)); $('#eTax').text(nf(tax)); $('#eTot').text(nf(net+tax)); $('#eErr').text(err);
  return err;
}
$(document).on('input change','#eDate,#eRate,#tEdit input',function(){
  if($(this).hasClass('e-it')) this.value=cleanTrack(this.value);
  if($(this).hasClass('e-in')) this.value=cleanDigits(this.value,8);
  if($(this).hasClass('e-id')) this.value=cleanDigits(this.value,7);
  eCalc();
});
$(document).on('input','#ebTrack,#ebNo,#ebDate',function(){ this.value=this.id==='ebTrack'?cleanTrack(this.value):cleanDigits(this.value,this.id==='ebNo'?8:7); });
$(document).on('click','#btnEBatch',function(){
  var t=$('#ebTrack').val(), n=$('#ebNo').val(), d=$('#ebDate').val(), ad=$('#eDate').val();
  var er=invFieldErr('t',t,ad)||invFieldErr('n',n,ad)||invFieldErr('d',d,ad);
  if(er){ popErr(er); return; }
  $('#tEdit .e-it').val(t); $('#tEdit .e-in').val(n); $('#tEdit .e-id').val(d); eCalc();
  toast('已把發票資料套用到全部明細，可再改個別不同的');
});
$(document).on('click','#dEdit',function(){ openDtl(CUR.allow_id,true); });
$(document).on('click','#dEditCancel',function(){ openDtl(CUR.allow_id,false); });
$(document).on('click','#dEditSave',function(){
  var er=eCalc(); if(er){ popErr(er); return; }
  var items=$('#tEdit tbody tr').map(function(){ var $r=$(this); return {item_id:$r.data('id'),qty:$r.find('.e-q').val(),unit_price:$r.find('.e-p').val(),inv_track:$r.find('.e-it').val(),inv_no:$r.find('.e-in').val(),inv_date:$r.find('.e-id').val()}; }).get();
  var payload={allow_date:$('#eDate').val(),tax_rate:$('#eRate').val(),note:$('#eNote').val(),items:items};
  post('update',{id:CUR.allow_id,payload:JSON.stringify(payload)},function(r){ toast(esc(r.message)); openDtl(CUR.allow_id,false); load(); },function(m){ $('#eErr').text(m); popErr(m); });
});
var EDITING=false;
function openDtl(id,edit){
  EDITING=!!edit;
  $.getJSON(API,{action:'get',id:id},function(r){
    if(!r.ok){ toast(esc(r.error||'載入失敗'),true); return; }
    var a=CUR=r.allowance, v=a.status==='void';
    $('#dTitle').text('折讓單　'+a.customer_name+'　'+dd(a.allow_date)+(v?'（已作廢）':''));
    var h=EDITING?editHead(a):'<div class="info">客戶：<b>'+esc(a.customer_id)+'　'+esc(a.customer_name)+'</b>　折讓日期：'+esc(dd(a.allow_date))+'　帳款月份：<b>'+esc(a.billing_month)+'</b>　營業稅率：'+(Number(a.tax_rate)*100)+'%'
      +'　開立：'+esc(a.created_by_name||'')+' '+esc(String(a.created_at||'').replace(/-/g,'.'))+(a.note?'<br>備註：'+esc(a.note):'')
      +(v?'<br><b style="color:#DD5138;">已作廢</b>：'+esc(a.void_reason||'')+'（'+esc(a.voided_by_name||'')+' '+esc(String(a.voided_at||'').replace(/-/g,'.'))+'）':'')+(a.modified_by_name?'<br><span style="color:#8a6d45;">最後修改：'+esc(a.modified_by_name)+' '+esc(String(a.modified_at||'').replace(/-/g,'.'))+'</span>':'')+'</div>';
    h+='<div class="sec">折讓證明單號碼</div><div style="display:flex;gap:6px;align-items:center;">'
      +(CAN_EDIT&&!v?'<input type="text" id="dNo" maxlength="60" style="height:31px;width:340px;border:1px solid var(--a-line2);border-radius:4px;padding:0 8px;" value="'+esc(a.allow_no||'')+'" placeholder="電子發票網站提供的證明單號碼">'
        +'<button class="btn-mini" id="dNoSug" style="height:31px;">帶入建議</button><button class="btn-mini" id="dNoSave" style="height:31px;background:var(--a-acc);color:#fff;">儲存號碼</button>'
        :'<b>'+esc(a.allow_no||'（尚未填寫）')+'</b>')+'</div><div id="dNoErr" class="err"></div>';
    h+=EDITING?editTable(a):viewTable(a);
    h+='<div class="sec">附件</div><div id="dFiles">';
    (a.files||[]).forEach(function(f){ h+='<div style="margin:3px 0;"><i class="fa fa-paperclip"></i> <a href="'+API+'?action=attach_download&file_id='+f.file_id+'" target="_blank">'+esc(f.orig_name)+'</a> <span style="color:var(--a-ink2);font-size:12px;">'+esc(f.uploaded_by_name||'')+' '+esc(String(f.uploaded_at||'').substr(0,16).replace(/-/g,'.'))+'</span>'+(CAN_EDIT&&!v?' <button class="btn-mini js-fdel" data-f="'+f.file_id+'">刪除</button>':'')+'</div>'; });
    if(!(a.files||[]).length) h+='<span style="color:var(--a-ink2);">沒有附件</span>';
    h+='</div>';
    if(CAN_EDIT&&!v) h+='<div style="margin-top:8px;"><input type="file" id="dFile" multiple> <button class="btn-mini" id="dFileUp" style="height:28px;">上傳附件</button></div>'
;
    h+='<div class="sec">異動記錄</div><div class="a-wrap"><table class="a-t"><thead><tr><th>時間</th><th>操作人</th><th>動作</th><th>折讓單</th><th>改了什麼</th><th>原因</th></tr></thead><tbody id="dLog"><tr><td colspan="6">載入中…</td></tr></tbody></table></div>';
    $('#dBody').html(h); openMask('mkDtl');
    // 左下角：作廢；右側：修改／儲存
    $('#dFootL').html(CAN_EDIT&&!v&&!EDITING?'<button type="button" id="dVoid" style="height:32px;padding:0 14px;border:1px solid #DD5138;border-radius:4px;background:#fff;color:#DD5138;cursor:pointer;">作廢這張折讓單</button>':'');
    $('#dFootR').html(CAN_EDIT&&!v?(EDITING?'<button type="button" id="dEditCancel">取消修改</button><button type="button" class="go" id="dEditSave"><i class="fa fa-check"></i> 儲存修改</button>':'<button type="button" id="dEdit"><i class="fa fa-pencil"></i> 修改這張折讓單</button>'):'');
    if(EDITING) eCalc();
    $.getJSON(API,{action:'log_list',allow_id:a.allow_id,per_page:100},function(lr){ if(lr.ok) $('#dLog').html(logRows(lr.rows)); });
  }).fail(function(x){ toast(esc(errMsg(x,'載入失敗')),true); });
}
$('#tbody').on('click','.js-dtl',function(){ openDtl($(this).data('id')); });
$('#dBody').on('click','#dNoSug',function(){
  $.getJSON(API,{action:'suggest_no',id:CUR.allow_id},function(r){ if(r.ok) $('#dNo').val(r.no); });
});
$('#dBody').on('click','#dNoSave',function(){
  post('set_no',{id:CUR.allow_id,allow_no:$('#dNo').val()},function(r){ toast(esc(r.message)); openDtl(CUR.allow_id); load(); },function(m){ $('#dNoErr').text(m); });
});
$('#dBody').on('click','#dFileUp',function(){
  var files=$('#dFile')[0].files; if(!files.length){ toast('請先選擇檔案',true); return; }
  var n=files.length, done=0, fails=[];
  $.each(files,function(_,f){
    var fd=new FormData(); fd.append('action','attach_add'); fd.append('csrf',CSRF); fd.append('id',CUR.allow_id); fd.append('file',f);
    $.ajax({url:API,type:'POST',data:fd,processData:false,contentType:false,dataType:'json'}).done(function(r){ if(!r.ok) fails.push(f.name+'：'+r.error); })
     .fail(function(x){ fails.push(f.name+'：'+errMsg(x)); }).always(function(){ if(++done===n){ toast(fails.length?esc(fails.join('；')):'已上傳附件',!!fails.length); openDtl(CUR.allow_id); load(); } });
  });
});
$('#dBody').on('click','.js-fdel',function(){
  if(!confirm('確定刪除這個附件？')) return;
  post('attach_del',{file_id:$(this).data('f')},function(){ openDtl(CUR.allow_id); load(); });
});
$(document).on('click','#dVoid',function(){
  var reason=prompt('作廢後，來源單據已折讓的數量會釋出，且不再列入對帳。\n請輸入作廢原因：','');
  if(reason===null) return; if(!reason.trim()){ toast('請填寫作廢原因',true); return; }
  post('void',{id:CUR.allow_id,reason:reason},function(r){ toast(esc(r.message)); closeMask('mkDtl'); load(); });
});

/* ── 異動記錄 ── */
var ACT={create:'建立',update:'修改折讓內容',set_no:'修改證明單號碼',void:'作廢（刪除）',attach_add:'新增附件',attach_del:'刪除附件',setting:'設定變更'};
function fmtDetail(s){
  var o=null; try{ o=JSON.parse(s); }catch(e){ return esc(s); }
  if(!o||typeof o!=='object') return esc(s);
  return Object.keys(o).map(function(k){ var v=o[k];
    return '<b>'+esc(k)+'</b>：'+(Array.isArray(v)?esc(v[0])+' <i class="fa fa-long-arrow-right"></i> '+esc(v[1]):esc(v)); }).join('<br>');
}
function logRows(rows){
  return rows.map(function(l){
    return '<tr><td>'+esc(dd(String(l.at_time).substr(0,10)))+' '+esc(String(l.at_time).substr(11,5))+'</td><td>'+esc(l.by_name||'')+'</td><td>'+esc(ACT[l.action]||l.action)+'</td>'
      +'<td class="l">'+(l.allow_id>0?esc(l.customer_name||'')+'<br><span style="color:var(--a-ink2);font-size:12px;">'+esc(l.allow_no||'（無號碼）')+'　#'+l.allow_id+'</span>':'—')+'</td>'
      +'<td class="l wrap" style="min-width:260px;">'+fmtDetail(l.detail||'')+'</td><td class="l wrap">'+esc(l.reason||'')+'</td></tr>';
  }).join('')||'<tr><td colspan="6" style="padding:16px;color:#8a6d45;">沒有記錄</td></tr>';
}
var lPage=1;
function loadLog(){
  $.getJSON(API,{action:'log_list',kw:$('#lKw').val(),log_action:$('#lAct').val(),date_from:$('#lFrom').val(),date_to:$('#lTo').val(),page:lPage,per_page:20},function(r){
    if(!r.ok){ toast(esc(r.error||'載入失敗'),true); return; }
    $('#lBody').html(logRows(r.rows)); var pages=Math.max(1,Math.ceil(r.total/r.per_page));
    $('#lInfo').text('共 '+r.total+' 筆，第 '+lPage+' / '+pages+' 頁');
    $('#lBtns').html('<button '+(lPage<=1?'disabled':'')+' data-p="'+(lPage-1)+'">‹ 上一頁</button> <button '+(lPage>=pages?'disabled':'')+' data-p="'+(lPage+1)+'">下一頁 ›</button>');
  }).fail(function(x){ toast(esc(errMsg(x,'載入失敗')),true); });
}
$('#btnLog').on('click',function(){ lPage=1; loadLog(); });
$('#btnLogQ').on('click',function(){ lPage=1; loadLog(); });
$('#lKw').on('keydown',function(e){ if(e.key==='Enter'){ lPage=1; loadLog(); } });
$('#lBtns').on('click','button[data-p]',function(){ lPage=parseInt($(this).data('p'),10); loadLog(); });

/* ── 格式設定 ── */
function fmtPrev(){
  var f=$('#fFmt').val(), d=new Date(), pad=function(n,w){ n=String(n); while(n.length<w) n='0'+n; return n; };
  var out=f.replace(/\{([A-Z_]+)(?::(\d{1,2}))?\}/g,function(m,t,w){
    switch(t){ case 'CID':return 'MR003C'; case 'YYYY':return d.getFullYear(); case 'MM':return pad(d.getMonth()+1,2); case 'DD':return pad(d.getDate(),2);
      case 'CM_SEQ':return w?pad(1,w):'1'; case 'M_SEQ':return w?pad(3,w):'3'; case 'Y_SEQ':return w?pad(12,w):'12'; } return m; });
  $('#fPrev').text(out);
}
function fillAttach(a){ if(!a) return; $('#aEff').text(a.effective); $('#aDef').text(a.default); $('#aCnt').text(a.file_count); $('#aDir').val(a.custom||''); }
$('#btnAttSave').on('click',function(){
  $('#aErr').text('');
  post('attach_setting_save',{dir:$('#aDir').val()},function(r){ META.attach=r.setting; fillAttach(r.setting); toast(esc(r.message)); },function(m){ $('#aErr').text(m); });
});
$('#btnFmt').on('click',function(){
  fillAttach(META.attach); $('#aErr').text('');
  $('#fFmt').val(META.format); $('#fErr').text(''); $('#fDef').text(META.default_format);
  $('#fTok').html((META.tokens||[]).map(function(t){ return '<div><span class="tok" data-t="'+esc(t[0])+'">'+esc(t[0])+'</span> '+esc(t[1])+'</div>'; }).join(''));
  fmtPrev(); openMask('mkFmt');
});
$('#fTok').on('click','.tok',function(){ var el=$('#fFmt')[0]; el.value+=$(this).data('t'); fmtPrev(); el.focus(); });
$('#fFmt').on('input',fmtPrev);
$('#btnFmtDef').on('click',function(){ $('#fFmt').val(META.default_format); fmtPrev(); });
$('#btnFmtSave').on('click',function(){
  post('format_save',{format:$('#fFmt').val()},function(r){ META.format=r.format; toast('已儲存格式；已建立的折讓單證明單號碼不受影響'); },function(m){ $('#fErr').text(m); });
});

$.getJSON(API,{action:'meta'},function(r){
  if(!r.ok){ toast(esc(r.error||'初始化失敗'),true); return; }
  CSRF=r.csrf; META=r;
  var q=new URLSearchParams(location.search); if(q.get('id')) openDtl(q.get('id'));
  load();
}).fail(function(x){ toast(esc(errMsg(x,'初始化失敗')),true); });
})(jQuery);
<?php endif; ?>
</script>
</body>
</html>
