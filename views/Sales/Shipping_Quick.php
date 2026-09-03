<?php
/**
 * views/Sales/Shipping_Quick.php — 快速出貨（新版，取代 Quick_Shipping.php）
 *
 * 設計主軸：快、精準、簡潔。
 *  - 單頁無 Tab：一個搜尋框通吃（訂單號／料號／客戶／品名／製令號）
 *  - 一列一訂單，製令為附屬資訊（同訂單多張製令不再炸成多列）
 *  - 鍵盤流：搜尋 Enter → ↑↓ 選列 → 空白鍵勾選 → 打數量 → Ctrl+Enter 出貨
 *  - 製令完工量走 shipping_lib.php sq_bom_avail_map()（舊版跨製程 SUM 會灌水，勿沿用）
 *  - 出貨單＝一張單多個料號明細（同客戶同日共用 IS_number，比照 ERP 現行結構）
 * 資料一律走 src/store/Shipping_API.php；權限 shipping_lib.php（roles module='shipping'）
 */
session_start();
if (!isset($_SESSION['userName'])) {
    $_SESSION['lastpage'] = "../../views/Sales/Shipping_Quick.php";
    header("Location:../../index.php");
    exit;
}
include_once '../../src/common/_config.php';
include_once '../../src/common/DBConnection.php';
include_once '../../src/common/shipping_lib.php';

$db      = (new DBConnection())->getPDO();
$sqUser  = sq_current_user($db);
$perms   = sq_perms($db, $sqUser);
$roleLbl = $perms['isAdmin'] ? '管理者'
         : ($perms['canAdmin'] ? '出貨管理員'
         : ($perms['canEdit'] ? '出貨登錄'
         : ($perms['canView'] ? '出貨檢閱' : '無權限')));
?>
<!DOCTYPE html>
<html lang="zh-Hant">
<head>
<meta charset="utf-8">
<meta http-equiv="X-UA-Compatible" content="IE=edge">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>快速出貨</title>
<link href="../../resource/css/bootstrap.css" rel="stylesheet">
<link href="../../resource/css/font-awesome.css" rel="stylesheet">
<link href="../../resource/css/nprogress.css" rel="stylesheet">
<link href="../../resource/css/custom.css" rel="stylesheet">
<style>
/* ── 暖色系調色盤（配色規範：一律暖色，深底白字／淺底深棕字）── */
:root{
  --sq-line:#E8D5B5; --sq-line2:#D8BE93; --sq-bg:#FDF8EF; --sq-bg2:#FFF7E8;
  --sq-ink:#5b3a1e; --sq-ink2:#8a6d45; --sq-brand:#8A5A2B;
  --sq-acc:#F0A24B; --sq-acc-d:#d98a33;
  --sq-normal:#F7E0BD; --sq-urgent:#F0A24B; --sq-super:#DD5138;
}
#sidebar-menu{visibility:hidden;}
.right_col .page-title{margin:8px 0 4px;overflow:hidden;}

.sq-bar{display:flex;flex-wrap:wrap;gap:6px;align-items:center;clear:both;
  border:1.5px solid var(--sq-line);border-radius:8px;padding:8px 10px;margin-bottom:8px;background:var(--sq-bg);}
.sq-bar label{margin:0;font-size:13px;color:var(--sq-ink);font-weight:normal;}
.sq-bar input[type=text],.sq-bar input[type=date],.sq-bar select,.sq-bar button{
  height:32px;font-size:13px;line-height:1;padding:0 10px;border:1px solid var(--sq-line2);
  border-radius:4px;background:#fff;color:var(--sq-ink);}
.sq-bar button{cursor:pointer;}
.sq-bar button:hover{background:var(--sq-normal);}
.sq-bar .btn-warm{background:var(--sq-acc);color:#fff;border-color:var(--sq-acc-d);font-weight:bold;}
.sq-bar .btn-warm:hover{background:var(--sq-acc-d);}
#kw{width:340px;font-size:14px;}
#kw:focus{border-color:var(--sq-acc);box-shadow:0 0 0 2px rgba(240,162,75,.25);outline:none;}
.sq-role{margin-left:auto;font-size:13px;color:var(--sq-ink);background:var(--sq-normal);
  border-radius:12px;padding:5px 12px;white-space:nowrap;}
.sq-role .fa-question-circle{cursor:pointer;color:#b5762a;margin-left:5px;}

.sq-stat{display:flex;flex-wrap:wrap;gap:20px;align-items:center;margin-bottom:8px;
  border:1.5px solid var(--sq-line);border-radius:8px;padding:8px 14px;background:var(--sq-bg2);}
.sq-stat .n{font-size:20px;font-weight:bold;color:var(--sq-brand);}
.sq-stat .l{font-size:12px;color:var(--sq-ink2);}
.sq-stat .sel{margin-left:auto;font-size:13px;color:var(--sq-ink);}
.sq-stat .sel b{color:var(--sq-super);font-size:17px;}

.sq-pager{display:flex;justify-content:flex-end;align-items:center;gap:6px;margin-bottom:4px;font-size:13px;color:var(--sq-ink);}
.sq-pager button{height:26px;min-width:28px;padding:0 8px;border:1px solid var(--sq-line2);
  background:#fff;border-radius:4px;cursor:pointer;color:var(--sq-ink);}
.sq-pager button:hover:not(:disabled){background:var(--sq-normal);}
.sq-pager button.on{background:var(--sq-acc);color:#fff;border-color:var(--sq-acc-d);font-weight:bold;}
.sq-pager button:disabled{opacity:.4;cursor:default;}
.sq-pager select{height:26px;border:1px solid var(--sq-line2);border-radius:4px;font-size:12px;}

.sq-wrap{overflow-x:auto;border:1px solid var(--sq-line);border-radius:6px;background:#fff;}
table.sq-t{width:100%;border-collapse:collapse;font-size:13px;}
table.sq-t th,table.sq-t td{border:1px solid #EADFC8;padding:4px 7px;white-space:nowrap;text-align:center;}
table.sq-t thead th{position:sticky;top:0;z-index:2;background:var(--sq-normal);color:var(--sq-ink);font-weight:bold;}
table.sq-t thead th.sortable{cursor:pointer;user-select:none;}
table.sq-t thead th.sortable:hover{background:#F0CFA0;}
table.sq-t thead th.sortable .sa{margin-left:4px;font-style:normal;opacity:.35;font-size:11px;}
table.sq-t thead th.sortable .sa:before{content:'\f0dc';font-family:FontAwesome;}
table.sq-t thead th.sortable.asc  .sa{opacity:1;color:#8A5A2B;}
table.sq-t thead th.sortable.asc  .sa:before{content:'\f0de';}
table.sq-t thead th.sortable.desc .sa{opacity:1;color:#8A5A2B;}
table.sq-t thead th.sortable.desc .sa:before{content:'\f0dd';}
table.sq-t td.l{text-align:left;}
table.sq-t td.r{text-align:right;}
table.sq-t tbody tr:nth-child(even){background:#FFFCF6;}
table.sq-t tbody tr.cur{outline:2px solid var(--sq-acc);outline-offset:-2px;}
table.sq-t tbody tr.on{background:#FCEBD2 !important;}
table.sq-t tbody tr.noready{color:#a08a6a;}

.qty-in{width:74px;height:26px;text-align:right;border:1px solid var(--sq-line2);border-radius:4px;
  font-size:13px;padding:0 5px;color:var(--sq-ink);}
.qty-in::-webkit-outer-spin-button,.qty-in::-webkit-inner-spin-button{-webkit-appearance:none;margin:0;}
.qty-in[type=number]{-moz-appearance:textfield;appearance:textfield;}
.qty-in:focus{border-color:var(--sq-acc);outline:none;background:#FFFBF3;}
.qty-in.over{border-color:var(--sq-super);background:#FDECE7;}

.pill{display:inline-block;padding:1px 7px;border-radius:9px;font-size:11px;line-height:17px;}
.p-normal{background:var(--sq-normal);color:#6b4522;}
.p-urgent{background:var(--sq-urgent);color:#fff;}
.p-super {background:var(--sq-super);color:#fff;}
.p-none  {background:#EFE6D6;color:#8a6d45;}
.p-pause {background:#E4D3BC;color:#6b4522;}
.bom-cell{cursor:pointer;color:var(--sq-brand);text-decoration:underline dotted;}
.bom-detail{background:#FFFBF2;font-size:12px;color:var(--sq-ink2);text-align:left !important;}
.bom-detail table{width:auto;margin:2px 0;font-size:12px;}
.bom-detail td{border:1px solid #EFE0C6;padding:2px 8px;}

.sq-dock{position:fixed;left:0;right:0;bottom:0;z-index:900;background:#8A5A2B;color:#fff;
  padding:9px 18px;display:none;align-items:center;gap:18px;box-shadow:0 -2px 10px rgba(0,0,0,.22);}
.sq-dock b{font-size:18px;}
.sq-dock .sp{margin-left:auto;display:flex;gap:8px;}
.sq-dock button{height:34px;padding:0 16px;border-radius:5px;border:1px solid #fff;
  background:transparent;color:#fff;font-size:14px;cursor:pointer;}
.sq-dock button.go{background:var(--sq-acc);border-color:var(--sq-acc-d);font-weight:bold;}
.sq-dock button.go:hover{background:var(--sq-acc-d);}
.sq-dock button:hover{background:rgba(255,255,255,.16);}

/* 遮罩：不用 inset 簡寫也不用 justify-content 置中（內容比視窗寬時左半會被裁掉且捲不到），
   改為明確四邊 + 子元素 margin:auto，z-index 需高過側欄/頂欄 */
.sq-mask{display:none;position:fixed;top:0;left:0;right:0;bottom:0;width:100%;height:100%;
  background:rgba(60,40,20,.45);z-index:9999;overflow:auto;padding:40px 12px;}
.sq-mask.show{display:block;}
.sq-modal{background:#fff;border-radius:8px;width:960px;max-width:100%;margin:0 auto;
  box-shadow:0 6px 30px rgba(0,0,0,.3);}
.sq-modal.narrow{width:640px;}
/* 回填工具欄位多，需要更寬；一律用固定像素不用 vw（vw 是整個瀏覽器視窗、會蓋過側邊選單） */
.sq-modal.wide{width:1180px;}
/* 第二層跳窗（在回填跳窗之上再開候選訂單） */
.sq-mask.lv2{z-index:10001;background:rgba(60,40,20,.35);}
/* 已被其他出貨吃完的訂單：照樣列出供確認，但整列反灰不可選 */
table.sq-t tbody tr.dim{background:#F4EEE3 !important;color:#a2916f;}
table.sq-t tbody tr.dim b{color:#a2916f;}
.mt-manual{background:#8A5A2B;color:#fff;}
.mt-note{font-size:12px;color:#8a6d45;}

/* ── 追溯對照（報價→訂單→製令→出貨→退貨）──────────────────────────
   五個泳道並排，每張卡片用「分段長條」表示自己的數量被分給了誰各多少。
   顏色一律取自固定暖色盤（ai-rules/10），依對方卡片在該泳道的序號取色，
   不用隨機／HSL——同一張單在任何地方都是同一個顏色，滑上去才對得起來。 */
.sq-modal.xwide{width:1360px;}
.tc-lanes{display:flex;gap:8px;align-items:flex-start;}
.tc-lane{flex:1 1 0;min-width:0;background:#FFFCF6;border:1px solid var(--sq-line);border-radius:6px;
  display:flex;flex-direction:column;}
.tc-lane-h{background:var(--sq-normal);color:var(--sq-ink);font-weight:bold;font-size:13px;
  padding:5px 8px;border-radius:5px 5px 0 0;display:flex;align-items:center;gap:6px;}
.tc-lane-h span{margin-left:auto;font-weight:normal;font-size:11.5px;color:#8a6d45;}
.tc-lane-b{padding:6px;overflow-y:auto;overflow-x:hidden;max-height:52vh;min-height:120px;}
.tc-lane.tc-drop{outline:2px dashed var(--sq-acc);outline-offset:-3px;}

.tc-card{background:#fff;border:1px solid #EADFC8;border-left-width:4px;border-radius:5px;
  padding:5px 7px;margin-bottom:6px;font-size:12px;color:var(--sq-ink);cursor:grab;}
.tc-card:last-child{margin-bottom:0;}
.tc-card.tc-dim{opacity:.22;}
.tc-card.tc-hi{box-shadow:0 0 0 2px var(--sq-acc);}
.tc-card.tc-drag{opacity:.45;}
.tc-card.tc-over{background:#FCEBD2;box-shadow:0 0 0 2px var(--sq-acc);}
.tc-card.tc-closed{background:#FAF6EE;}
.tc-c-h{display:flex;align-items:center;gap:4px;}
.tc-c-h b{font-size:12.5px;}
.tc-grip{cursor:grab;color:#c7b192;font-size:12px;letter-spacing:-1px;user-select:none;}
.tc-grip:hover{color:var(--sq-acc);}
.tc-date{margin-left:auto;color:#8a6d45;font-size:11px;}
.tc-c-q{color:#6b4522;margin:1px 0 2px;}
.tc-c-q i{font-style:normal;color:#8a6d45;}
.tc-tag{display:inline-block;padding:0 5px;border-radius:8px;font-size:10.5px;line-height:15px;
  background:#EFE6D6;color:#8a6d45;margin-left:3px;}
.tc-tag.warn{background:var(--sq-super);color:#fff;}

.tc-bar-row{display:flex;align-items:center;gap:4px;margin-top:2px;}
.tc-bl{width:26px;flex:0 0 26px;color:#8a6d45;font-size:10.5px;}
.tc-bar{flex:1 1 auto;height:11px;background:#F1E7D6;border-radius:3px;overflow:hidden;display:flex;}
.tc-seg{height:100%;display:block;cursor:pointer;border-right:1px solid rgba(255,255,255,.65);}
.tc-seg:last-child{border-right:0;}
.tc-seg.tc-seg-free{background:#F1E7D6;cursor:default;}
.tc-seg.tc-seg-over{background:var(--sq-super) !important;}
.tc-bn{flex:0 0 auto;font-size:10.5px;color:#8a6d45;min-width:52px;text-align:right;}
.tc-bn.over{color:var(--sq-super);font-weight:bold;}
.tc-empty{padding:14px;color:#8a6d45;font-size:12.5px;text-align:center;}
.tc-legend{display:flex;flex-wrap:wrap;gap:10px;font-size:12px;color:#5b3a1e;align-items:center;}
.tc-legend i{font-style:normal;display:inline-block;width:11px;height:11px;border-radius:2px;
  vertical-align:-1px;margin-right:3px;}
.sq-modal .m-head{background:var(--sq-normal);color:var(--sq-ink);padding:9px 14px;font-weight:bold;
  border-radius:8px 8px 0 0;display:flex;align-items:center;}
.sq-modal .m-close{margin-left:auto;cursor:pointer;font-size:17px;}
.sq-modal .m-body{padding:14px;max-height:70vh;overflow:auto;}
.sq-modal .m-foot{padding:10px 14px;border-top:1px solid var(--sq-line);text-align:right;}
.sq-modal .m-foot button{height:32px;padding:0 14px;border:1px solid var(--sq-line2);border-radius:4px;
  background:#fff;cursor:pointer;color:var(--sq-ink);}
.sq-modal .m-foot button.go{background:var(--sq-acc);color:#fff;border-color:var(--sq-acc-d);font-weight:bold;}

.sq-msg{position:fixed;top:64px;right:18px;z-index:1200;min-width:260px;max-width:430px;
  padding:11px 15px;border-radius:6px;display:none;font-size:14px;box-shadow:0 3px 12px rgba(0,0,0,.2);}
.sq-msg.ok  {background:#F7E0BD;color:#5b3a1e;border-left:5px solid var(--sq-acc);}
.sq-msg.bad {background:#FBE3DC;color:#7a2c17;border-left:5px solid var(--sq-super);}
.sq-noperm{border:1.5px solid var(--sq-line);background:var(--sq-bg);border-radius:8px;padding:26px;color:var(--sq-ink);}
.sq-hint{font-size:11.5px;color:var(--sq-ink2);margin-top:5px;}
kbd{background:#f4e6ce;border:1px solid var(--sq-line2);border-bottom-width:2px;border-radius:3px;
  padding:0 5px;font-size:11px;color:var(--sq-ink);font-family:inherit;}

@media print{
  .sq-bar,.sq-stat,.sq-pager,.sq-dock,.nav_menu,.left_col,footer,.sq-mask{display:none !important;}
  .right_col{margin:0 !important;padding:0 !important;}
  table.sq-t thead th{position:static;}
}
</style>
</head>
<body class="nav-sm">
<div class="container body">
<div class="main_container">
  <?php include '../partPage/sideAndTopBarMenu.html' ?>
  <div class="right_col" role="main">
    <div class="page-title" style="display:flex;align-items:center;flex-wrap:wrap;">
      <h2 style="margin:6px 0;"><i class="fa fa-truck" style="color:#F0A24B;"></i> 快速出貨
        <small style="color:#8a6d45;">一列一訂單，勾選後填出貨量即可開單；同客戶同日自動併為一張出貨單</small></h2>
    </div>
    <div class="clearfix"></div>

<?php if (!$perms['canView']): ?>
    <div class="sq-noperm">
      <h4><i class="fa fa-lock"></i> 無出貨作業檢閱權限</h4>
      <p>請洽管理者於「使用者權限設定」指派「出貨檢閱／出貨登錄／出貨管理員」角色。</p>
    </div>
<?php else: ?>
    <div class="sq-bar">
      <label>出貨日期</label>
      <input type="date" id="shipDate">
      <input type="text" id="kw" placeholder="搜尋：訂單號／料號／客戶／品名／製令號　(按 / 快速跳到這裡)">
      <button id="btnSearch" class="btn-warm"><i class="fa fa-search"></i> 查詢</button>
      <label style="margin-left:6px;">客戶</label>
      <select id="clientSel" style="max-width:170px;"><option value="">全部</option></select>
      <label>交期</label>
      <input type="date" id="dFrom" style="width:140px;"> ~ <input type="date" id="dTo" style="width:140px;">
      <label><input type="checkbox" id="onlyReady" checked> 只顯示可出貨</label>
      <label><input type="checkbox" id="incPaused"> 含暫停單</label>
      <span class="sq-role">目前角色：<b><?= htmlspecialchars($roleLbl) ?></b>
        <i class="fa fa-question-circle" id="btnRoleHelp" title="角色權限說明"></i></span>
    </div>

    <div class="sq-bar" style="background:var(--sq-bg2);">
      <button id="btnCsv"><i class="fa fa-file-text-o"></i> 匯出CSV</button>
      <button id="btnPdf"><i class="fa fa-file-pdf-o"></i> 匯出PDF／列印</button>
      <button id="btnRecent"><i class="fa fa-history"></i> 近期出貨單</button>
      <button id="btnChain"><i class="fa fa-sitemap"></i> 追溯對照</button>
      <?php if ($perms['canAdmin']): ?>
      <button id="btnMatch"><i class="fa fa-link"></i> 舊資料訂單回填</button>
      <?php endif; ?>
      <span class="sq-hint" style="margin-left:12px;">
        鍵盤：<kbd>/</kbd> 搜尋　<kbd>↑</kbd><kbd>↓</kbd> 選列　<kbd>空白</kbd> 勾選　<kbd>Ctrl</kbd>+<kbd>Enter</kbd> 出貨
      </span>
    </div>

    <div class="sq-stat">
      <div><span class="n" id="stOrders">—</span> <span class="l">筆訂單</span></div>
      <div><span class="n" id="stRemain">—</span> <span class="l">未出量</span></div>
      <div><span class="n" id="stReady">—</span> <span class="l">目前可出</span></div>
      <div><span class="n" id="stAmount">—</span> <span class="l">可出金額</span></div>
      <div class="sel">已選 <b id="stSelCnt">0</b> 筆 ／ <b id="stSelQty">0</b> 支 ／ <b id="stSelAmt">0</b> 元</div>
    </div>

    <div class="sq-pager">
      <span id="pgInfo" style="margin-right:auto;color:#8a6d45;"></span>
      每頁 <select id="perPage"><option>5</option><option>10</option><option selected>20</option><option>50</option></select> 筆
      <span id="pgBtns"></span>
    </div>

    <div class="sq-wrap">
      <table class="sq-t" id="tbl">
        <thead><tr>
          <th style="width:34px;"><input type="checkbox" id="chkAll" title="全選本頁"></th>
          <th class="sortable" data-sort="order_oo">訂單號<i class="sa"></i></th>
          <th class="sortable" data-sort="client">客戶<i class="sa"></i></th>
          <th class="sortable" data-sort="d_id">料號<i class="sa"></i></th>
          <th>品名規格</th>
          <th>訂購</th><th>已出</th>
          <th class="sortable" data-sort="remain">未出<i class="sa"></i></th>
          <th class="sortable" data-sort="ready">可出<i class="sa"></i></th>
          <th style="background:#F0A24B;color:#fff;">出貨量</th>
          <th>單價</th><th>金額</th>
          <th class="sortable" data-sort="delivery">交期<i class="sa"></i></th>
          <th>製令</th>
        </tr></thead>
        <tbody id="tbody"><tr><td colspan="14" style="padding:22px;color:#8a6d45;">載入中…</td></tr></tbody>
      </table>
    </div>
    <div class="sq-hint">
      「可出」＝該訂單目前有完工製令、且尚未出貨的數量（已扣除製令既有出貨）。「未出」＝訂購量－已出量。
      製令完工量以「最後一道製程已移轉(E)」或 ERP 結案認定；點製令欄可展開各張製令的完工／已出／可出明細。
      <b>訂單號空白或為 NA 的不列入</b>（多為廠內治具製作，非出給客戶的貨）。點表頭可依訂單號／客戶／料號／未出／可出／交期排序。
    </div>
<?php endif; ?>
  </div>
  <?php include '../partPage/footer.html' ?>
</div>
</div>

<!-- 底部動作條 -->
<div class="sq-dock" id="dock">
  <span>已選 <b id="dkCnt">0</b> 筆</span>
  <span>合計 <b id="dkQty">0</b> 支</span>
  <span>金額 <b id="dkAmt">0</b> 元</span>
  <span id="dkGroups" style="font-size:12px;opacity:.85;"></span>
  <span class="sp">
    <button id="btnClear">清除選取</button>
    <button class="go" id="btnShip"><i class="fa fa-paper-plane"></i> 確認出貨 (Ctrl+Enter)</button>
  </span>
</div>

<!-- 出貨確認 -->
<div class="sq-mask" id="mkConfirm"><div class="sq-modal">
  <div class="m-head"><i class="fa fa-paper-plane"></i>&nbsp;確認出貨<span class="m-close" data-close="mkConfirm">✕</span></div>
  <div class="m-body" id="cfBody"></div>
  <div class="m-foot">
    <button data-close="mkConfirm">取消</button>
    <button class="go" id="btnShipGo"><i class="fa fa-check"></i> 確認建立出貨單</button>
  </div>
</div></div>

<!-- 近期出貨單 -->
<div class="sq-mask" id="mkRecent"><div class="sq-modal">
  <div class="m-head"><i class="fa fa-history"></i>&nbsp;近期出貨單<span class="m-close" data-close="mkRecent">✕</span></div>
  <div class="m-body">
    <div class="sq-bar" style="margin-bottom:8px;">
      <label>出貨日</label><input type="date" id="rcFrom" style="width:145px;"> ~ <input type="date" id="rcTo" style="width:145px;">
      <input type="text" id="rcKw" placeholder="單號／客戶／料號" style="width:200px;">
      <button id="btnRcGo" class="btn-warm">查詢</button>
    </div>
    <div id="rcList"></div>
  </div>
</div></div>

<!-- 出貨單明細／送貨單 -->
<div class="sq-mask" id="mkDetail"><div class="sq-modal">
  <div class="m-head"><i class="fa fa-file-text-o"></i>&nbsp;<span id="dtTitle">出貨單明細</span>
    <span class="m-close" data-close="mkDetail">✕</span></div>
  <div class="m-body" id="dtBody"></div>
  <div class="m-foot">
    <button data-close="mkDetail">關閉</button>
    <button class="go" id="btnPrintDn"><i class="fa fa-print"></i> 列印送貨單</button>
  </div>
</div></div>

<?php if ($perms['canAdmin']): ?>
<!-- 舊資料回填 -->
<div class="sq-mask" id="mkMatch"><div class="sq-modal wide">
  <div class="m-head"><i class="fa fa-link"></i>&nbsp;舊資料訂單回填<span class="m-close" data-close="mkMatch">✕</span></div>
  <div class="m-body">
    <p style="font-size:13px;color:#5b3a1e;">
      現有出貨資料多由 ERP 匯入、未帶訂單編號，導致「訂單未出量」算不出來。
      本工具用<b>客戶簡稱＋料號id＋日期先後（FIFO）</b>推算對應訂單，
      <b style="color:#DD5138;">須人工確認勾選後才會寫入</b>。建議由早到晚逐段區間處理（每段套用後再算下一段）。
      每一列都可以按「改選」自行指定要綁哪一張訂單；勾選「無法對應」還能把系統推不出來的那些列叫出來手動指定。
    </p>
    <div class="sq-bar">
      <label>出貨日期區間</label>
      <input type="date" id="mtFrom" style="width:150px;"> ~ <input type="date" id="mtTo" style="width:150px;">
      <button id="btnMtGo" class="btn-warm"><i class="fa fa-search"></i> 試算</button>
      <span id="mtSummary" style="font-size:13px;color:#5b3a1e;margin-left:10px;"></span>
    </div>
    <div class="sq-bar" style="background:#FBF3E4;">
      <label>篩選客戶</label>
      <select id="mtClient" style="width:200px;" data-eg-filter="輸入客戶簡稱篩選…" data-eg-filter-reset><option value="">全部客戶</option></select>
      <label>篩選料號</label>
      <select id="mtPart" style="width:230px;" data-eg-filter="輸入料號篩選…" data-eg-filter-reset><option value="">全部料號</option></select>
      <span class="mt-note">選了客戶，料號只列該客戶底下的；改動即重新試算。</span>
    </div>
    <div class="sq-bar" style="background:#FFF7E8;">
      <label>只顯示</label>
      <label><input type="checkbox" class="mt-f" value="high" checked> <span class="pill p-normal">高信心</span></label>
      <label><input type="checkbox" class="mt-f" value="mid" checked> <span class="pill p-urgent">中</span></label>
      <label><input type="checkbox" class="mt-f" value="low"> <span class="pill p-super">低(出貨量超過訂單)</span></label>
      <label><input type="checkbox" class="mt-f" value="none"> <span class="pill p-none">無法對應(手動指定)</span></label>
      <button id="btnMtAllHigh" style="margin-left:8px;">勾選所有高信心</button>
      <button id="btnMtNone">清除勾選</button>
    </div>
    <div id="mtList" style="max-height:44vh;overflow:auto;"></div>
  </div>
  <div class="m-foot">
    <span id="mtSel" style="float:left;color:#5b3a1e;font-size:13px;line-height:32px;">已勾選 0 筆</span>
    <button data-close="mkMatch">取消</button>
    <button class="go" id="btnMtApply"><i class="fa fa-check"></i> 回填勾選的資料</button>
  </div>
</div></div>

<!-- 手動改選訂單（第二層，開在回填跳窗之上）-->
<div class="sq-mask lv2" id="mkCand"><div class="sq-modal wide">
  <div class="m-head"><i class="fa fa-exchange"></i>&nbsp;改選要回填的訂單<span class="m-close" data-close="mkCand">✕</span></div>
  <div class="m-body">
    <div id="cdShip" style="font-size:13px;color:#5b3a1e;background:#FFF7E8;border:1px solid #EADFC8;
         border-radius:5px;padding:8px 12px;margin-bottom:8px;"></div>
    <div class="sq-bar" style="background:#FBF3E4;">
      <label><input type="checkbox" id="cdSame" checked> 只列相同料號</label>
      <span class="mt-note">訂單常下組合件名稱、製作時才拆成子件料號；取消勾選就列出這個客戶的所有訂單。</span>
      <input type="text" id="cdKw" placeholder="訂單編號／料號／規格關鍵字" style="width:220px;margin-left:auto;">
      <button id="btnCdGo" class="btn-warm"><i class="fa fa-search"></i> 查詢</button>
    </div>
    <div id="cdList" style="max-height:46vh;overflow:auto;"></div>
    <div class="mt-note" style="margin-top:6px;">
      候選一律限定<b>同一個客戶簡稱</b>（跨客戶綁一定是錯的，後端寫入時也會再擋一次）。
      <span style="color:#a2916f;">整列反灰</span>＝該訂單的量已被其他出貨吃完，不可選，列出來只是方便你確認。
    </div>
  </div>
  <div class="m-foot">
    <span id="cdCnt" style="float:left;color:#5b3a1e;font-size:13px;line-height:32px;"></span>
    <button id="btnCdClear">改回系統建議</button>
    <button data-close="mkCand">取消</button>
  </div>
</div></div>
<?php endif; ?>

<!-- 追溯對照：報價單 → 訂單 → 製令 → 出貨單 → 退貨單 →(重出)出貨單 -->
<div class="sq-mask" id="mkChain"><div class="sq-modal xwide">
  <div class="m-head"><i class="fa fa-sitemap"></i>&nbsp;追溯對照
    <span style="font-weight:normal;font-size:12.5px;margin-left:8px;">報價單 → 訂單 → 製令 → 出貨單 → 退貨單 →(重出)出貨單</span>
    <span class="m-close" data-close="mkChain">✕</span></div>
  <div class="m-body">
    <div class="sq-bar">
      <label>客戶</label>
      <select id="tcClient" style="width:190px;" data-eg-filter="輸入客戶簡稱篩選…" data-eg-filter-reset><option value="">請選擇客戶</option></select>
      <label>料號</label>
      <select id="tcPart" style="width:230px;" data-eg-filter="輸入料號篩選…" data-eg-filter-reset><option value="">請先選客戶</option></select>
      <label>日期</label>
      <input type="date" id="tcFrom" style="width:145px;"> ~ <input type="date" id="tcTo" style="width:145px;">
      <select id="tcOrder" style="width:120px;">
        <option value="new">日期新→舊</option><option value="old">日期舊→新</option>
      </select>
      <label title="各表的客戶簡稱寫法可能不一致，勾起來就只用料號查"><input type="checkbox" id="tcAllCli"> 不限客戶</label>
      <button id="btnTcGo" class="btn-warm"><i class="fa fa-search"></i> 載入</button>
    </div>
    <div class="sq-bar" style="background:#FFF7E8;">
      <div class="tc-legend">
        <b>怎麼用：</b>
        <span>拖<b>卡片本體</b>到別欄的卡片＝建立對應（會問你分配數量）</span>
        <span>拖左上角 <b class="tc-grip">⠿</b> ＝在同一欄調整順序，方便並排比對</span>
        <span>滑到卡片上＝把相關的單據一起打亮</span>
        <span>點<b>彩色長條</b>＝改分配量或解除</span>
      </div>
    </div>
    <div class="sq-bar" style="background:#FBF3E4;">
      <span id="tcInfo" style="font-size:13px;color:#5b3a1e;">請先選客戶與料號，再按「載入」。</span>
      <span class="mt-note" style="margin-left:auto;">長條的顏色＝對方那張單的顏色；尾端<i style="display:inline-block;width:11px;height:11px;background:#F1E7D6;border-radius:2px;vertical-align:-1px;"></i>灰色＝還沒分配的量</span>
    </div>
    <div id="tcLanes" class="tc-lanes"><div class="tc-empty" style="width:100%;">尚未載入資料。</div></div>
  </div>
  <div class="m-foot">
    <span id="tcFoot" style="float:left;color:#5b3a1e;font-size:12.5px;line-height:32px;"></span>
    <button id="btnTcMore">每欄多載 100 筆</button>
    <button data-close="mkChain">關閉</button>
  </div>
</div></div>

<!-- 分配數量（第三層，開在追溯對照之上）-->
<div class="sq-mask lv2" id="mkTcQty"><div class="sq-modal narrow">
  <div class="m-head"><i class="fa fa-exchange"></i>&nbsp;<span id="tqTitle">建立對應</span>
    <span class="m-close" data-close="mkTcQty">✕</span></div>
  <div class="m-body" style="font-size:13px;color:#5b3a1e;">
    <div id="tqInfo" style="background:#FFF7E8;border:1px solid #EADFC8;border-radius:5px;padding:8px 12px;margin-bottom:10px;"></div>
    <div style="display:flex;align-items:center;gap:8px;">
      <label style="margin:0;">分配數量</label>
      <input type="number" id="tqQty" class="qty-in" style="width:110px;" min="1">
      <span id="tqHint" class="mt-note"></span>
    </div>
    <div id="tqErr" style="color:#DD5138;font-size:12.5px;margin-top:6px;"></div>
  </div>
  <div class="m-foot">
    <button id="btnTqDel" style="float:left;color:#DD5138;border-color:#E8B8AC;">解除這條對應</button>
    <button data-close="mkTcQty">取消</button>
    <button class="go" id="btnTqOk"><i class="fa fa-check"></i> 確定</button>
  </div>
</div></div>

<!-- 角色說明 -->
<div class="sq-mask" id="mkRole"><div class="sq-modal narrow">
  <div class="m-head">角色權限說明<span class="m-close" data-close="mkRole">✕</span></div>
  <div class="m-body" style="font-size:13.5px;color:#5b3a1e;line-height:1.9;">
    <b>管理者</b>：固定擁有全部權限。<br>
    <b>出貨管理員</b>：可出貨、可執行舊資料訂單回填。<br>
    <b>出貨登錄</b>：可查詢待出貨清單並建立出貨單。<br>
    <b>出貨檢閱</b>：僅可查詢與匯出，不能建立出貨單。<br>
    <span style="color:#8a6d45;font-size:12.5px;">角色於「使用者權限設定」頁指派（模組 shipping）。</span>
  </div>
</div></div>

<div class="sq-msg" id="msg"></div>

<script src="../../resource/js/jquery.min.js"></script>
<script src="../../resource/js/bootstrap.min.js"></script>
<script src="../../resource/js/fastclick.js"></script>
<script src="../../resource/js/nprogress.js"></script>
<script src="../../resource/js/custom.min.js"></script>
<script src="../../resource/js/eg_input_rules.js?v=<?= @filemtime(__DIR__.'/../../resource/js/eg_input_rules.js') ?: time() ?>"></script>
<script src="../../resource/js/eg_date_fmt.js?v=<?= @filemtime(__DIR__.'/../../resource/js/eg_date_fmt.js') ?: time() ?>"></script>
<script>
/* 左側欄：版型預設 #sidebar-menu 為 visibility:hidden，需在 ready 後手動恢復，
   否則整個左側選單不會出現（漏掉這段是本頁第一版側欄消失的原因）。 */
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
var API   = '../../src/store/Shipping_API.php';
var CAN_EDIT  = <?= $perms['canEdit']  ? 'true' : 'false' ?>;
var CAN_ADMIN = <?= $perms['canAdmin'] ? 'true' : 'false' ?>;

var CSRF = '', rows = [], page = 1, perPage = 20, total = 0;
var sortBy = '', sortDir = 'asc';   // '' = 預設（可出貨優先、再依交期）
var sel = {};              // order_id => {qty, row}
var cur = -1;              // 目前鍵盤游標所在列 index
var lastMatch = [];

/* ── 小工具 ───────────────────────────────────────────────── */
function esc(s){ return String(s==null?'':s).replace(/[&<>"']/g,function(c){
  return {'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#39;'}[c]; }); }
function nf(n){ return (Number(n)||0).toLocaleString('en-US'); }
/* 小數尾 0 省略：3.50→3.5、3.00→3 */
function np(n){ var v=Number(n)||0; return String(parseFloat(v.toFixed(4))); }
function toast(m, bad){
  var $m=$('#msg').removeClass('ok bad').addClass(bad?'bad':'ok').html(m).stop(true,true).fadeIn(150);
  clearTimeout($m.data('t')); $m.data('t', setTimeout(function(){ $m.fadeOut(400); }, bad?6500:3800));
}
/* 顯示用日期一律 YYYY.MM.DD（ai-rules/20）；共用檔萬一沒載到也不要整段 JS 掛掉 */
function dispDate(d){
  if(d==null||d==='') return '';
  return (typeof egFmtDate==='function') ? egFmtDate(d) : String(d);
}
function openMask(id){ $('#'+id).addClass('show'); }
function closeMask(id){ $('#'+id).removeClass('show'); }
$(document).on('click','[data-close]',function(){ closeMask($(this).data('close')); });
$(document).on('click','.sq-mask',function(e){ if(e.target===this) $(this).removeClass('show'); });

/* 急件燈號（固定三色，不可隨機上色） */
function prioPill(p){
  if(p==='E') return '<span class="pill p-super">特急</span>';
  if(p==='U') return '<span class="pill p-urgent">急件</span>';
  return '';
}

/* ── UI 規則：有值雙擊清空／聚焦全選／Enter 跳下一欄 ─────────── */
function bindInputRules($scope){
  $scope.find('input[type=text],input[type=date],input[type=number]')
    .off('.sqr')
    .on('focus.sqr', function(){ if(this.value!=='' && this.select) try{ this.select(); }catch(e){} })
    .on('dblclick.sqr', function(){
      if(this.value!==''){ this.value=''; $(this).trigger('change').trigger('input'); }
    });
}

/* ══════════════════════════════════════════════════════════
 * 初始化
 * ══════════════════════════════════════════════════════════ */
function init(){
  $.getJSON(API, {action:'meta'}, function(r){
    if(!r.ok){ toast(esc(r.error||'初始化失敗'), true); return; }
    CSRF = r.csrf;
    $('#shipDate').val(r.today);
    $('#rcTo').val(r.today);
    var d=new Date(r.today); d.setDate(d.getDate()-14);
    $('#rcFrom').val(d.toISOString().slice(0,10));
    var h='<option value="">全部</option>';
    (r.clients||[]).forEach(function(c){
      h+='<option value="'+esc(c.customer_id)+'">'+esc(c.name)+'（'+c.cnt+'）</option>'; });
    $('#clientSel').html(h);
    // 追溯對照的客戶下拉用「客戶簡稱」當值（各單據表都是以簡稱歸戶）
    var h2='<option value="">請選擇客戶</option>';
    (r.clients||[]).forEach(function(c){
      h2+='<option value="'+esc(c.name)+'">'+esc(c.name)+'（'+c.cnt+'）</option>'; });
    $('#tcClient').html(h2);
    var y=new Date(r.today); y.setFullYear(y.getFullYear()-1);
    $('#tcFrom').val(y.toISOString().slice(0,10));
    $('#tcTo').val(r.today);
    bindInputRules($(document));
    load();
  }).fail(function(){ toast('無法連線到出貨 API', true); });
}

/* ══════════════════════════════════════════════════════════
 * 待出貨清單
 * ══════════════════════════════════════════════════════════ */
function filters(){
  return {
    kw:            $('#kw').val(),
    client_id:     $('#clientSel').val(),
    date_from:     $('#dFrom').val(),
    date_to:       $('#dTo').val(),
    only_ready:    $('#onlyReady').is(':checked') ? 1 : 0,
    include_paused:$('#incPaused').is(':checked') ? 1 : 0,
    sort:          sortBy,
    dir:           sortDir,
    per_page:      perPage,
    page:          page
  };
}

/* 表頭排序：同欄再點一次切換升／降冪，第三次回到預設排序 */
$(document).on('click','#tbl thead th.sortable',function(){
  var k=$(this).data('sort');
  if(sortBy!==k){ sortBy=k; sortDir='asc'; }
  else if(sortDir==='asc'){ sortDir='desc'; }
  else { sortBy=''; sortDir='asc'; }
  $('#tbl thead th.sortable').removeClass('asc desc');
  if(sortBy) $(this).addClass(sortDir);
  page=1; load();
});

function load(){
  $('#tbody').html('<tr><td colspan="14" style="padding:22px;color:#8a6d45;">查詢中…</td></tr>');
  $.post(API+'?action=pending', filters(), function(r){
    if(!r.ok){ toast(esc(r.error||'查詢失敗'), true); return; }
    rows  = r.rows||[];
    total = r.total||0;
    var s = r.summary||{};
    $('#stOrders').text(nf(s.orders));
    $('#stRemain').text(nf(s.remain));
    $('#stReady').text(nf(s.ready));
    $('#stAmount').text(nf(Math.round(s.amount||0)));
    render();
    renderPager();
  }, 'json').fail(function(){ toast('查詢失敗（網路或伺服器錯誤）', true); });
}

function render(){
  if(!rows.length){
    $('#tbody').html('<tr><td colspan="14" style="padding:22px;color:#8a6d45;">'
      + '查無符合條件的待出貨資料。可取消勾選「只顯示可出貨」看全部未出完的訂單。</td></tr>');
    return;
  }
  var h='';
  rows.forEach(function(r,i){
    var s   = sel[r.order_id];
    var qty = s ? s.qty : '';
    var pausedTag = (r.order_status===6) ? ' <span class="pill p-pause">暫停</span>' : '';
    var readyTag  = r.ready_qty>0 ? '' : ' <span class="pill p-none">無完工</span>';
    h+='<tr data-i="'+i+'" data-oid="'+r.order_id+'" class="'+(s?'on ':'')+(r.ready_qty>0?'':'noready')+'">'
      +'<td><input type="checkbox" class="ck"'+(s?' checked':'')+'></td>'
      +'<td class="l">'+esc(r.order_oo||'—')+pausedTag+'</td>'
      +'<td class="l">'+esc(r.client_display)+'</td>'
      +'<td class="l">'+esc(r.d_id)+'</td>'
      +'<td class="l" title="'+esc(r.specification)+'">'+esc((r.specification||'').substr(0,22))+'</td>'
      +'<td class="r">'+nf(r.order_qty)+'</td>'
      +'<td class="r">'+nf(r.shipped_qty)+'</td>'
      +'<td class="r"><b>'+nf(r.remain_qty)+'</b></td>'
      +'<td class="r" style="color:'+(r.ready_qty>0?'#8A5A2B':'#a08a6a')+';"><b>'+nf(r.ready_qty)+'</b>'+readyTag+'</td>'
      +'<td><input type="number" class="qty-in" value="'+qty+'" min="0" max="'+r.remain_qty+'"'
        + (CAN_EDIT?'':' disabled')+'></td>'
      +'<td class="r">'+np(r.unit_price)+'</td>'
      +'<td class="r amt">'+(s?nf(Math.round(s.qty*r.unit_price)):'')+'</td>'
      +'<td>'+esc(r.delivery_date||'—')+'</td>'
      +'<td>'+(r.bom_count
          ? '<span class="bom-cell">'+r.bom_count+' 張 '+prioPill((r.boms[0]||{}).priority)+'</span>'
          : '<span class="pill p-none">無</span>')+'</td>'
      +'</tr>';
  });
  $('#tbody').html(h);
  bindInputRules($('#tbody'));
  updateDock();
  if(cur>=rows.length) cur=rows.length-1;
  paintCur();
}

function renderPager(){
  var pages = Math.max(1, Math.ceil(total/perPage));
  if(page>pages){ page=pages; }
  var from = total? (page-1)*perPage+1 : 0;
  var to   = Math.min(page*perPage, total);
  $('#pgInfo').text('顯示 '+from+'–'+to+'，共 '+nf(total)+' 筆');
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

/* ── 勾選／數量 ───────────────────────────────────────────── */
function pick(i, on, qty){
  var r = rows[i]; if(!r) return;
  if(on){
    var q = (qty!=null && qty!=='') ? parseInt(qty,10) : (r.ready_qty>0 ? r.ready_qty : r.remain_qty);
    if(!(q>0)) q = r.remain_qty;
    sel[r.order_id] = {qty:q, row:r};
  } else {
    delete sel[r.order_id];
  }
  var $tr=$('#tbody tr[data-i="'+i+'"]');
  $tr.toggleClass('on', !!on);
  $tr.find('.ck').prop('checked', !!on);
  $tr.find('.qty-in').val(on ? sel[r.order_id].qty : '');
  $tr.find('.amt').text(on ? nf(Math.round(sel[r.order_id].qty*r.unit_price)) : '');
  updateDock();
}

$(document).on('change','#tbody .ck',function(){
  pick(parseInt($(this).closest('tr').data('i'),10), this.checked);
});
$(document).on('click','#tbody td',function(e){
  if($(e.target).is('input,.bom-cell')) return;
  cur = parseInt($(this).closest('tr').data('i'),10); paintCur();
});
$(document).on('input','#tbody .qty-in',function(){
  var $tr=$(this).closest('tr'), i=parseInt($tr.data('i'),10), r=rows[i];
  var q=parseInt(this.value,10);
  $(this).toggleClass('over', q>r.remain_qty);
  if(q>0) pick(i,true,q); else pick(i,false);
  $tr.find('.qty-in').focus();
});
/* Enter 跳下一列同欄；↑↓ 切換上下列同欄 */
$(document).on('keydown','#tbody .qty-in',function(e){
  var $tr=$(this).closest('tr'), i=parseInt($tr.data('i'),10);
  if(e.key==='Enter' && !e.ctrlKey){ e.preventDefault(); focusQty(i+1); }
  else if(e.key==='ArrowDown'){ e.preventDefault(); focusQty(i+1); }
  else if(e.key==='ArrowUp'){ e.preventDefault(); focusQty(i-1); }
});
function focusQty(i){
  if(i<0||i>=rows.length) return;
  cur=i; paintCur();
  $('#tbody tr[data-i="'+i+'"] .qty-in').focus();
}

$('#chkAll').on('change',function(){
  var on=this.checked;
  rows.forEach(function(r,i){ pick(i,on); });
});
$('#btnClear').on('click',function(){ sel={}; render(); });

function updateDock(){
  var cnt=0, qty=0, amt=0, groups={};
  Object.keys(sel).forEach(function(k){
    var s=sel[k]; cnt++; qty+=s.qty; amt+=s.qty*s.row.unit_price;
    groups[(s.row.client_id||'')+'|'+s.row.client_name]=1;
  });
  var g=Object.keys(groups).length;
  $('#stSelCnt,#dkCnt').text(nf(cnt));
  $('#stSelQty,#dkQty').text(nf(qty));
  $('#stSelAmt,#dkAmt').text(nf(Math.round(amt)));
  $('#dkGroups').text(g? '將建立 '+g+' 張出貨單（依客戶分開）':'');
  $('#dock').css('display', cnt?'flex':'none');
  $('#chkAll').prop('checked', rows.length>0 && rows.every(function(r){ return !!sel[r.order_id]; }));
}

/* ── 製令明細展開 ─────────────────────────────────────────── */
$(document).on('click','.bom-cell',function(){
  var $tr=$(this).closest('tr'), i=parseInt($tr.data('i'),10), r=rows[i];
  var $nx=$tr.next('.bom-row');
  if($nx.length){ $nx.remove(); return; }
  var h='<table><tr><th>製令</th><th>製令量</th><th>分配</th><th>完工</th><th>已出</th><th>可出</th><th>交期</th><th>備註</th></tr>';
  (r.boms||[]).forEach(function(b){
    h+='<tr><td><b>'+esc(b.bom)+'</b> '+prioPill(b.priority)+(b.closed?' <span class="pill p-normal">ERP結案</span>':'')+'</td>'
      +'<td>'+nf(b.bom_qty)+'</td><td>'+nf(b.allocated)+'</td><td>'+nf(b.done)+'</td>'
      +'<td>'+nf(b.shipped)+'</td><td><b style="color:#8A5A2B;">'+nf(b.avail)+'</b></td>'
      +'<td>'+esc(b.delivery||'—')+'</td><td>'+esc((b.bom_ps||'').substr(0,26))+'</td></tr>';
  });
  h+='</table>';
  $tr.after('<tr class="bom-row"><td colspan="14" class="bom-detail">'+h+'</td></tr>');
});

/* ── 鍵盤流 ───────────────────────────────────────────────── */
function paintCur(){
  $('#tbody tr').removeClass('cur');
  if(cur>=0) $('#tbody tr[data-i="'+cur+'"]').addClass('cur')
    .each(function(){ if(this.scrollIntoView) this.scrollIntoView({block:'nearest'}); });
}
$(document).on('keydown', function(e){
  var tag=(e.target.tagName||'').toLowerCase();
  var inField = (tag==='input'||tag==='select'||tag==='textarea');

  if(e.key==='/' && !inField){ e.preventDefault(); $('#kw').focus(); return; }
  if(e.ctrlKey && e.key==='Enter'){ e.preventDefault(); doShip(); return; }
  if($('.sq-mask.show').length) return;

  if(!inField || e.target.id==='kw'){
    if(e.key==='ArrowDown'){ e.preventDefault(); cur=Math.min(rows.length-1,cur+1); paintCur(); }
    else if(e.key==='ArrowUp'){ e.preventDefault(); cur=Math.max(0,cur-1); paintCur(); }
  }
  if(!inField && e.key===' ' && cur>=0){
    e.preventDefault();
    pick(cur, !sel[rows[cur].order_id]);
  }
  /* 直接打數字 → 進該列出貨量欄 */
  if(!inField && cur>=0 && /^[0-9]$/.test(e.key)){
    var $q=$('#tbody tr[data-i="'+cur+'"] .qty-in');
    if($q.length && !$q.prop('disabled')){ $q.val(e.key).focus().trigger('input'); e.preventDefault(); }
  }
});
$('#kw').on('keydown',function(e){
  if(e.key==='Enter'){ e.preventDefault(); page=1; load(); cur=0; }
});
$('#btnSearch').on('click',function(){ page=1; load(); });
$('#clientSel,#onlyReady,#incPaused').on('change',function(){ page=1; load(); });
$('#dFrom,#dTo').on('change',function(){ page=1; load(); });

/* ══════════════════════════════════════════════════════════
 * 建立出貨單
 * ══════════════════════════════════════════════════════════ */
function doShip(){
  if(!CAN_EDIT){ toast('無出貨登錄權限', true); return; }
  var keys=Object.keys(sel);
  if(!keys.length){ toast('請先勾選要出貨的項目', true); return; }
  if(!$('#shipDate').val()){ toast('請選擇出貨日期', true); return; }

  var groups={}, warn=[];
  keys.forEach(function(k){
    var s=sel[k], r=s.row;
    if(s.qty>r.remain_qty) warn.push(r.order_oo+'：出貨量 '+s.qty+' 超過未出量 '+r.remain_qty);
    else if(s.qty>r.ready_qty) warn.push(r.order_oo+'：出貨量 '+s.qty+' 超過目前可出量 '+r.ready_qty+'（製令尚未完工）');
    var gk=(r.client_id||'')+'|'+r.client_name;
    (groups[gk]=groups[gk]||{name:r.client_display, items:[]}).items.push(s);
  });

  var h='<div style="font-size:13px;color:#5b3a1e;margin-bottom:8px;">出貨日期 <b>'+esc($('#shipDate').val())
      +'</b>，將建立 <b>'+Object.keys(groups).length+'</b> 張出貨單：</div>';
  Object.keys(groups).forEach(function(gk){
    var g=groups[gk], sub=0, q=0;
    h+='<div style="border:1px solid #E8D5B5;border-radius:6px;margin-bottom:8px;">'
      +'<div style="background:#F7E0BD;padding:5px 10px;font-weight:bold;color:#5b3a1e;">'+esc(g.name)+'</div>'
      +'<table class="sq-t" style="font-size:12.5px;"><tr><th>訂單</th><th>料號</th><th>數量</th><th>單價</th><th>金額</th><th>製令分配</th></tr>';
    g.items.forEach(function(s){
      var r=s.row, a=s.qty*r.unit_price; sub+=a; q+=s.qty;
      var alloc=allocate(r, s.qty);
      h+='<tr><td>'+esc(r.order_oo||'—')+'</td><td class="l">'+esc(r.d_id)+'</td>'
        +'<td class="r">'+nf(s.qty)+'</td><td class="r">'+np(r.unit_price)+'</td>'
        +'<td class="r">'+nf(Math.round(a))+'</td>'
        +'<td class="l" style="font-size:11.5px;">'
        +(alloc.length? alloc.map(function(x){return esc(x.bom)+'×'+x.qty;}).join('　') : '<span style="color:#a08a6a;">無製令可扣</span>')
        +'</td></tr>';
    });
    h+='<tr style="background:#FFF7E8;font-weight:bold;"><td colspan="2">小計</td><td class="r">'+nf(q)
      +'</td><td></td><td class="r">'+nf(Math.round(sub))+'</td><td></td></tr></table></div>';
  });
  if(warn.length){
    h+='<div style="border-left:5px solid #DD5138;background:#FBE3DC;color:#7a2c17;padding:9px 12px;'
      +'border-radius:4px;font-size:13px;"><b>請確認：</b><br>'+warn.map(esc).join('<br>')+'</div>';
  }
  $('#cfBody').html(h);
  openMask('mkConfirm');
}

/* 前端預覽用的 FIFO 分配（實際仍由後端重算，以資料庫當下狀態為準） */
function allocate(r, qty){
  var out=[], left=qty;
  (r.boms||[]).forEach(function(b){
    if(left<=0) return;
    var can=b.avail; if(can<=0) return;
    var take=Math.min(can,left);
    out.push({bom:b.bom, qty:take}); left-=take;
  });
  return out;
}

$('#btnShipGo').on('click',function(){
  var $b=$(this).prop('disabled',true).html('<i class="fa fa-spinner fa-spin"></i> 建立中…');
  var items=[];
  Object.keys(sel).forEach(function(k){
    var s=sel[k], r=s.row;
    items.push({
      order_id:     r.order_id,
      d_setting_id: r.d_setting_id,
      product_id:   r.d_id,
      specification:r.specification,
      client_id:    r.client_id,
      client_name:  r.client_name,
      qty:          s.qty,
      unit_price:   r.unit_price,
      note:         '',
      boms:         allocate(r, s.qty)
    });
  });
  $.post(API+'?action=create', {
    csrf: CSRF, ship_date: $('#shipDate').val(), items: JSON.stringify(items)
  }, function(res){
    $b.prop('disabled',false).html('<i class="fa fa-check"></i> 確認建立出貨單');
    if(!res.ok){ toast(esc(res.error||'建立失敗'), true); return; }
    closeMask('mkConfirm');
    var nos=Object.keys(res.shipments||{});
    toast('<b>'+esc(res.message)+'</b><br>單號：'+nos.map(esc).join('、'));
    if(res.errors && res.errors.length) toast(res.errors.map(esc).join('<br>'), true);
    sel={}; load();
  },'json').fail(function(x){
    $b.prop('disabled',false).html('<i class="fa fa-check"></i> 確認建立出貨單');
    var m='建立失敗'; try{ m=JSON.parse(x.responseText).error||m; }catch(e){}
    toast(esc(m), true);
  });
});
$('#btnShip').on('click', doShip);

/* ══════════════════════════════════════════════════════════
 * 匯出（後端對全部符合條件的資料計算，不用前端當頁）
 * ══════════════════════════════════════════════════════════ */
$('#btnCsv').on('click',function(){
  var f=filters(); delete f.page; delete f.per_page;
  var q=Object.keys(f).map(function(k){ return k+'='+encodeURIComponent(f[k]); }).join('&');
  window.location = API+'?action=export&'+q;
});
$('#btnPdf').on('click',function(){ window.print(); });

/* ══════════════════════════════════════════════════════════
 * 近期出貨單 / 送貨單
 * ══════════════════════════════════════════════════════════ */
$('#btnRecent').on('click',function(){ openMask('mkRecent'); loadRecent(); });
$('#btnRcGo').on('click', loadRecent);
$('#rcKw').on('keydown',function(e){ if(e.key==='Enter'){ e.preventDefault(); loadRecent(); } });

function loadRecent(){
  $('#rcList').html('<div style="padding:14px;color:#8a6d45;">查詢中…</div>');
  $.post(API+'?action=recent', {kw:$('#rcKw').val(), date_from:$('#rcFrom').val(), date_to:$('#rcTo').val()},
  function(r){
    if(!r.ok){ toast(esc(r.error||'查詢失敗'), true); return; }
    if(!r.rows.length){ $('#rcList').html('<div style="padding:14px;color:#8a6d45;">此區間沒有出貨單。</div>'); return; }
    var h='<table class="sq-t"><thead><tr><th>出貨單號</th><th>出貨日</th><th>客戶</th>'
        +'<th>明細筆數</th><th>總數量</th><th>總金額</th><th></th></tr></thead><tbody>';
    r.rows.forEach(function(s){
      h+='<tr><td><b>'+esc(s.IS_number)+'</b></td><td>'+esc(s.ship_date)+'</td>'
        +'<td class="l">'+esc(s.Client_name)+'</td><td class="r">'+nf(s.item_count)+'</td>'
        +'<td class="r">'+nf(s.total_qty)+'</td><td class="r">'+nf(Math.round(s.total_amount))+'</td>'
        +'<td><button class="btn-dt" data-no="'+esc(s.IS_number)+'" style="height:24px;padding:0 9px;'
        +'border:1px solid #D8BE93;border-radius:4px;background:#fff;cursor:pointer;">明細</button></td></tr>';
    });
    $('#rcList').html(h+'</tbody></table>');
  },'json').fail(function(){ toast('查詢失敗', true); });
}

$(document).on('click','.btn-dt',function(){ showDetail($(this).data('no')); });

function showDetail(no){
  $('#dtTitle').text('出貨單 '+no);
  $('#dtBody').html('<div style="padding:14px;color:#8a6d45;">載入中…</div>');
  openMask('mkDetail');
  $.getJSON(API, {action:'detail', is_number:no}, function(r){
    if(!r.ok || !r.rows.length){ $('#dtBody').html('<div style="padding:14px;">查無明細。</div>'); return; }
    var f=r.rows[0], tq=0, ta=0;
    var h='<div id="dnPrint">'
      +'<div style="text-align:center;font-size:19px;font-weight:bold;color:#5b3a1e;margin-bottom:4px;">送　貨　單</div>'
      +'<table style="width:100%;font-size:13px;color:#5b3a1e;margin-bottom:8px;"><tr>'
      +'<td>客戶：<b>'+esc(f.customer_full||f.client_display||f.Client_name)+'</b></td>'
      +'<td>出貨單號：<b>'+esc(f.IS_number)+'</b></td></tr><tr>'
      +'<td>統一編號：'+esc(f.tax_id||'—')+'</td><td>出貨日期：'+esc(f.ship_date)+'</td></tr><tr>'
      +'<td colspan="2">地址：'+esc(f.customer_address||'—')+'</td></tr></table>'
      +'<table class="sq-t"><thead><tr><th>#</th><th>訂單號</th><th>料號</th><th>品名規格</th>'
      +'<th>數量</th><th>單價</th><th>金額</th><th>製令</th><th>備註</th></tr></thead><tbody>';
    r.rows.forEach(function(d,i){
      var a=d.Qty*d.Unit_price; tq+=Number(d.Qty); ta+=a;
      h+='<tr><td>'+(i+1)+'</td><td>'+esc(d.Order_oo||'—')+'</td><td class="l">'+esc(d.Product_id)+'</td>'
        +'<td class="l">'+esc(d.Specification||'')+'</td><td class="r">'+nf(d.Qty)+'</td>'
        +'<td class="r">'+np(d.Unit_price)+'</td><td class="r">'+nf(Math.round(a))+'</td>'
        +'<td>'+esc(d.boms||'—')+'</td><td class="l">'+esc(d.Note||'')+'</td></tr>';
    });
    h+='<tr style="background:#FFF7E8;font-weight:bold;"><td colspan="4">合計</td><td class="r">'+nf(tq)
      +'</td><td></td><td class="r">'+nf(Math.round(ta))+'</td><td colspan="2"></td></tr>'
      +'</tbody></table></div>';
    $('#dtBody').html(h);
  }).fail(function(){ $('#dtBody').html('<div style="padding:14px;color:#DD5138;">載入失敗。</div>'); });
}

$('#btnPrintDn').on('click',function(){
  var w=window.open('','_blank');
  w.document.write('<html><head><meta charset="utf-8"><title>送貨單</title>'
    +'<style>body{font-family:"Microsoft JhengHei",sans-serif;padding:18px;}'
    +'table{width:100%;border-collapse:collapse;font-size:13px;}'
    +'th,td{border:1px solid #999;padding:4px 7px;text-align:center;}'
    +'th{background:#F7E0BD;}td.l{text-align:left;}td.r{text-align:right;}</style></head><body>'
    +$('#dnPrint').html()+'</body></html>');
  w.document.close(); w.focus(); setTimeout(function(){ w.print(); }, 350);
});

/* ══════════════════════════════════════════════════════════
 * 舊資料訂單回填
 * ══════════════════════════════════════════════════════════ */
if(CAN_ADMIN){
  var mtManual = {};   // is_id => 手動指定的訂單（覆蓋系統建議）
  var mtParts  = [];   // 篩選用料號清單（含所屬客戶）
  var mtCandIs = 0;    // 目前正在改選的出貨明細 id
  var cdOrders = [];   // 候選跳窗當次列出的訂單
  var RSN = {no_order:'查無此客戶＋料號的訂單', used_up:'訂單量已被其他出貨出完',
             later:'訂單下單日晚於出貨日'};

  $('#btnMatch').on('click',function(){ openMask('mkMatch'); });

  function mtRange(){
    var f=$('#mtFrom').val(), t=$('#mtTo').val();
    if(!f||!t){ toast('請指定出貨日期區間', true); return null; }
    return {date_from:f, date_to:t};
  }

  /* 試算＝載入篩選來源（客戶／料號）＋跑一次對應 */
  $('#btnMtGo').on('click',function(){
    var r=mtRange(); if(!r) return;
    $.post(API+'?action=match_filters', r, function(res){
      if(!res.ok) return;
      mtParts = res.parts||[];
      var cur=$('#mtClient').val()||'';
      var h='<option value="">全部客戶</option>';
      (res.clients||[]).forEach(function(c){
        h+='<option value="'+esc(c.name)+'">'+esc(c.name)+'（'+nf(c.cnt)+'）</option>'; });
      $('#mtClient').html(h).val(cur);
      if($('#mtClient').val()===null) $('#mtClient').val('');
      fillParts();
    },'json');
    runMatch();
  });

  /* 料號下拉：選了客戶就只列該客戶底下的料號 */
  function fillParts(){
    var cn=$('#mtClient').val()||'', cur=$('#mtPart').val()||'';
    var agg={}, keys=[];
    mtParts.forEach(function(p){
      if(cn && p.client!==cn) return;
      if(!agg[p.d_id]){ agg[p.d_id]={product_id:p.product_id, cnt:0}; keys.push(p.d_id); }
      agg[p.d_id].cnt += (Number(p.cnt)||0);
    });
    keys.sort(function(a,b){ return String(agg[a].product_id).localeCompare(String(agg[b].product_id)); });
    var h='<option value="">全部料號</option>';
    keys.forEach(function(k){
      h+='<option value="'+k+'">'+esc(agg[k].product_id)+'（'+nf(agg[k].cnt)+'）</option>'; });
    $('#mtPart').html(h);
    $('#mtPart').val(cur && agg[cur] ? cur : '');
  }

  $('#mtClient').on('change',function(){ fillParts(); runMatch(); });
  $('#mtPart').on('change', runMatch);

  function runMatch(){
    var r=mtRange(); if(!r) return;
    r.client         = $('#mtClient').val()||'';
    r.d_id           = $('#mtPart').val()||0;
    // 「無法對應」的列量大，預設不撈；勾了才向後端要
    r.with_unmatched = $('.mt-f[value=none]').is(':checked') ? 1 : 0;
    $('#mtList').html('<div style="padding:14px;color:#8a6d45;">試算中…</div>');
    $.post(API+'?action=match_preview', r, function(res){
      if(!res.ok){ toast(esc(res.error||'試算失敗'), true); return; }
      lastMatch = res.pairs||[];
      /* 手動指定過的列一定要留著。取消「無法對應」等於重新跟後端要一份不含那些列的結果，
         不補回來的話，剛剛一筆一筆指定好的訂單會整批從畫面上消失、也不會被回填。 */
      var have={};
      lastMatch.forEach(function(p){ have[p.is_id]=1; });
      for(var k in mtManual){
        if(mtManual.hasOwnProperty(k) && !have[k] && mtManual[k]._row) lastMatch.push(mtManual[k]._row);
      }
      lastMatch.sort(function(a,b){
        return a.ship_date===b.ship_date ? (a.is_id-b.is_id) : (a.ship_date<b.ship_date?-1:1); });
      var s=res.summary||{};
      $('#mtSummary').html('待回填明細 <b>'+nf(s.ship_rows)+'</b> 筆，推得對應 <b style="color:#8A5A2B;">'
        +nf(s.matched)+'</b> 筆，無法對應 <b style="color:#DD5138;">'+nf(s.unmatched)+'</b> 筆');
      renderMatch();
    },'json').fail(function(){ toast('試算失敗', true); });
  }

  /* 手動指定的訂單優先於系統建議 */
  function eff(p){
    var m=mtManual[p.is_id];
    return m ? $.extend({}, p, m, {manual:true}) : p;
  }

  $(document).on('change','.mt-f',function(){
    if(this.value==='none') runMatch(); else renderMatch();
  });

  function renderMatch(){
    var show={};
    $('.mt-f:checked').each(function(){ show[this.value]=1; });
    // 手動指定過的列一律顯示，免得被篩選藏起來又不知道自己選過
    var list=lastMatch.filter(function(p){ return show[p.confidence] || mtManual[p.is_id]; });
    if(!list.length){ $('#mtList').html('<div style="padding:14px;color:#8a6d45;">目前篩選條件下沒有資料。</div>');
      updateMtSel(); return; }
    var h='<table class="sq-t"><thead><tr><th style="width:32px;"><input type="checkbox" id="mtAll"></th>'
        +'<th>信心</th><th>出貨單</th><th>出貨日</th><th>客戶</th><th>料號</th><th>出貨量</th><th>出貨單價</th>'
        +'<th>&rarr; 訂單</th><th>訂單日</th><th>訂購量</th><th>當時剩餘</th><th>訂單單價</th>'
        +'<th style="width:74px;">操作</th></tr></thead><tbody>';
    list.forEach(function(p0){
      var p   = eff(p0);
      var has = (p.order_id||0) > 0;
      var pill = p.manual                ? '<span class="pill mt-manual">手動</span>'
               : p0.confidence==='high'  ? '<span class="pill p-normal">高</span>'
               : p0.confidence==='mid'   ? '<span class="pill p-urgent">中</span>'
               : p0.confidence==='low'   ? '<span class="pill p-super">低</span>'
               :                           '<span class="pill p-none">無</span>';
      var ckd = has && (p.manual || p0.confidence==='high');
      h+='<tr'+(has?'':' class="noready"')+'>'
        +'<td><input type="checkbox" class="mt-ck" data-is="'+p0.is_id+'" data-oid="'+(has?p.order_id:0)+'"'
        +(has?'':' disabled')+(ckd?' checked':'')+'></td>'
        +'<td>'+pill+'</td><td>'+esc(p0.is_number)+'</td><td>'+esc(p0.ship_date)+'</td>'
        +'<td class="l">'+esc(p0.client_name)+'</td><td class="l">'+esc(p0.product_id)+'</td>'
        +'<td class="r">'+nf(p0.ship_qty)+'</td><td class="r">'+np(p0.ship_price)+'</td>';
      if(has){
        h+='<td><b>'+esc(p.order_oo||'—')+'</b>'
          +((p.manual && p.part_no && p.part_no!==p0.product_id)
              ? '<div class="mt-note">訂單料號：'+esc(p.part_no)+'</div>' : '')+'</td>'
          +'<td>'+esc(p.order_date)+'</td><td class="r">'+nf(p.order_qty)+'</td>'
          +'<td class="r"'+(p.over_qty?' style="color:#DD5138;font-weight:bold;"':'')+'>'+nf(p.order_left)+'</td>'
          +'<td class="r"'+(p.price_match?'':' style="color:#DD5138;"')+'>'+np(p.order_price)+'</td>';
      }else{
        h+='<td colspan="5" class="l" style="color:#a2916f;">'
          +esc(RSN[p0.no_reason]||'系統推不出對應')+'　&rarr; 可按右側「指定訂單」自行挑一張</td>';
      }
      h+='<td><button class="mt-pick" data-is="'+p0.is_id+'">'+(has?'改選':'指定訂單')+'</button></td></tr>';
    });
    $('#mtList').html(h+'</tbody></table>');
    updateMtSel();
  }

  $(document).on('change','#mtAll',function(){
    $('.mt-ck').not(':disabled').prop('checked', this.checked); updateMtSel(); });
  $(document).on('change','.mt-ck', updateMtSel);
  function updateMtSel(){
    var n=$('.mt-ck:checked').length, vis={};
    $('.mt-ck').each(function(){ vis[$(this).data('is')]=1; });
    var hid=0; for(var k in mtManual){ if(mtManual.hasOwnProperty(k) && !vis[k]) hid++; }
    $('#mtSel').html('已勾選 <b>'+nf(n)+'</b> 筆'
      +(hid ? ' <span style="color:#DD5138;">（另有 '+nf(hid)+' 筆手動指定不在目前篩選內，不會被回填）</span>' : ''));
  }

  $('#btnMtAllHigh').on('click',function(){
    $('.mt-f[value=high]').prop('checked',true);
    renderMatch();                       // 重畫後高信心與手動指定列預設已勾選
    var keep={};
    lastMatch.forEach(function(p){ if(p.confidence==='high') keep[p.is_id]=1; });
    for(var k in mtManual){ if(mtManual.hasOwnProperty(k)) keep[k]=1; }
    $('#mtList .mt-ck').each(function(){
      $(this).prop('checked', !this.disabled && !!keep[$(this).data('is')]); });
    updateMtSel();
  });
  $('#btnMtNone').on('click',function(){ $('.mt-ck').prop('checked',false); updateMtSel(); });

  /* -- 手動改選訂單 -------------------------------------------------- */
  $(document).on('click','.mt-pick',function(){
    mtCandIs = parseInt($(this).data('is'),10)||0;
    if(!mtCandIs) return;
    $('#cdSame').prop('checked',true); $('#cdKw').val('');
    openMask('mkCand');
    loadCand();
  });
  $('#btnCdGo').on('click', loadCand);
  $('#cdSame').on('change', loadCand);
  $('#cdKw').on('keydown',function(e){ if(e.which===13){ e.preventDefault(); loadCand(); } });

  function loadCand(){
    if(!mtCandIs) return;
    $('#cdList').html('<div style="padding:14px;color:#8a6d45;">查詢中…</div>');
    $.post(API+'?action=match_candidates', {
      is_id: mtCandIs,
      same_part: $('#cdSame').is(':checked') ? 1 : 0,
      kw: $('#cdKw').val()||''
    }, function(r){
      if(!r.ok){ toast(esc(r.error||'查詢失敗'), true); return; }
      renderCand(r);
    },'json').fail(function(){ toast('查詢失敗', true); });
  }

  function renderCand(r){
    var s=r.ship||{}, os=r.orders||[];
    cdOrders = os;
    var cur=null;
    lastMatch.forEach(function(p){ if(p.is_id===s.is_id) cur=p; });
    var e = cur ? eff(cur) : null;
    var curOid = e ? (e.order_id||0) : 0;

    $('#cdShip').html('出貨單 <b>'+esc(s.is_number)+'</b>　出貨日 '+esc(s.ship_date)
      +'　客戶 <b>'+esc(s.client_name)+'</b>　料號 <b>'+esc(s.product_id)+'</b>'
      +'　出貨量 <b>'+nf(s.ship_qty)+'</b>　出貨單價 '+np(s.ship_price)+'<br>'
      +(curOid ? '目前指定：<b style="color:#8A5A2B;">'+esc(e.order_oo||'')+'</b>'
                 +(mtManual[s.is_id]?'（手動指定）':'（系統建議）')
               : '目前：<span style="color:#DD5138;">尚未指定</span>'));

    if(r.error){ $('#cdList').html('<div style="padding:14px;color:#DD5138;">'+esc(r.error)+'</div>');
      $('#cdCnt').text(''); return; }
    if(!os.length){
      $('#cdList').html('<div style="padding:14px;color:#8a6d45;">這個客戶底下沒有符合條件的訂單。'
        +'訂單常下組合件名稱，可取消「只列相同料號」再找一次。</div>');
      $('#cdCnt').text(''); return; }

    var h='<table class="sq-t"><thead><tr><th style="width:70px;">選用</th><th>訂單編號</th><th>訂單日</th>'
      +'<th>交期</th><th>訂單料號</th><th>訂購量</th><th>已出量</th><th>剩餘量</th><th>單價</th>'
      +'<th>備註</th></tr></thead><tbody>';
    os.forEach(function(o,i){
      var tag=[];
      if(!o.selectable)   tag.push('<b>已被其他出貨出完，不可選</b>');
      else if(o.over_qty) tag.push('<span style="color:#DD5138;">出貨量超過剩餘量</span>');
      if(!o.same_part)    tag.push('<span style="color:#8A5A2B;">料號與出貨不同</span>');
      if(o.late)          tag.push('<span style="color:#DD5138;">下單日晚於出貨日</span>');
      if(!o.price_match)  tag.push('<span style="color:#DD5138;">單價不符</span>');
      if(o.closed)        tag.push('已結案');
      h+='<tr class="'+(o.selectable?'':'dim')+(o.order_id===curOid?' on':'')+'">'
        +'<td>'+(o.selectable
            ? '<button class="cd-use" data-i="'+i+'">'+(o.order_id===curOid?'目前':'選用')+'</button>'
            : '—')+'</td>'
        +'<td><b>'+esc(o.order_oo||'—')+'</b></td><td>'+esc(o.order_date)+'</td>'
        +'<td>'+esc(o.delivery||'')+'</td><td class="l">'+esc(o.part_no)+'</td>'
        +'<td class="r">'+nf(o.order_qty)+'</td><td class="r">'+nf(o.used_qty)+'</td>'
        +'<td class="r"'+(o.selectable?'':' style="font-weight:bold;"')+'>'+nf(o.order_left)+'</td>'
        +'<td class="r">'+np(o.order_price)+'</td>'
        +'<td class="l">'+(tag.join('　')||'')+'</td></tr>';
    });
    $('#cdList').html(h+'</tbody></table>');
    var tot=Number(r.total)||os.length;
    $('#cdCnt').text(tot>os.length
      ? '這個客戶共 '+nf(tot)+' 張訂單，只顯示離出貨日最近的 '+nf(os.length)+' 張（請用關鍵字縮小）'
      : '候選 '+nf(os.length)+' 張');
  }

  $(document).on('click','.cd-use',function(){
    var o = cdOrders[parseInt($(this).data('i'),10)];
    if(!o || !o.selectable) return;
    var base=null;
    lastMatch.forEach(function(p){ if(p.is_id===mtCandIs) base=p; });
    mtManual[mtCandIs] = {
      order_id:o.order_id, order_oo:o.order_oo, order_date:o.order_date,
      order_qty:o.order_qty, order_left:o.order_left, order_price:o.order_price,
      price_match:o.price_match, over_qty:o.over_qty, part_no:o.part_no,
      _row: base                      // 換篩選條件重新試算時要靠它把這一列補回來
    };
    closeMask('mkCand');
    renderMatch();
    toast('已指定訂單 '+esc(o.order_oo||''));
  });

  $('#btnCdClear').on('click',function(){
    if(!mtManual[mtCandIs]){ closeMask('mkCand'); return; }
    delete mtManual[mtCandIs];
    closeMask('mkCand'); renderMatch();
    toast('已改回系統建議');
  });

  $('#btnMtApply').on('click',function(){
    var pairs=[], manual=0;
    $('.mt-ck:checked').each(function(){
      var isId=parseInt($(this).data('is'),10)||0, oid=parseInt($(this).data('oid'),10)||0;
      if(isId>0 && oid>0){ pairs.push({is_id:isId, order_id:oid}); if(mtManual[isId]) manual++; }
    });
    if(!pairs.length){ toast('請先勾選要回填的資料', true); return; }
    if(!confirm('確定將 '+pairs.length+' 筆出貨明細回填訂單編號？'
      +(manual? '\n其中 '+manual+' 筆是你手動指定的訂單。':'')
      +'\n（僅寫入目前訂單編號為空、且客戶簡稱相符的資料）')) return;
    var $b=$(this).prop('disabled',true).html('<i class="fa fa-spinner fa-spin"></i> 回填中…');
    $.post(API+'?action=match_apply', {csrf:CSRF, pairs:JSON.stringify(pairs)}, function(r){
      $b.prop('disabled',false).html('<i class="fa fa-check"></i> 回填勾選的資料');
      if(!r.ok){ toast(esc(r.error||'回填失敗'), true); return; }
      toast(esc(r.message));
      mtManual = {};          // 已寫入的不再保留手動指定
      $('#btnMtGo').click();
      load();
    },'json').fail(function(){
      $b.prop('disabled',false).html('<i class="fa fa-check"></i> 回填勾選的資料');
      toast('回填失敗', true);
    });
  });
}


/* ══════════════════════════════════════════════════════════
 * 追溯對照：報價單 → 訂單 → 製令 → 出貨單 → 退貨單 →(重出)出貨單
 *
 * 為什麼要畫成「分段長條」而不是列文字：這幾種單據彼此是多對多而且會拆分數量
 * （一張製令分給 6 張訂單、一張出貨單分屬多張訂單都真的存在），
 * 只印文字看不出「這張單的量分給了誰各多少、還剩多少沒分配」。
 * ══════════════════════════════════════════════════════════ */
/* 固定暖色盤（ai-rules/10）：顏色代表「對方那張單」，同一張單在任何地方都同色，
   絕不用隨機或 HSL 上色，否則兩次重畫顏色就對不起來了。 */
var TC_COLORS = ['#F0A24B','#DD5138','#C77B30','#E8B04B','#B5651D','#D98E5A',
                 '#A9552B','#E5A06B','#C9963F','#8A5A2B','#EFC07A','#B07A4A'];
var TC_KINDS  = ['quote','order','bom','ship','ret'];
/* 每種卡片要畫哪幾條長條：side='a' 代表這張卡是連結的來源端，'b' 代表目標端 */
var TC_BARS = {
  quote: [{lab:'訂單', type:'quote_order', side:'a'}],
  order: [{lab:'製令', type:'order_bom',   side:'a'},
          {lab:'出貨', type:'order_ship',  side:'a'},
          {lab:'退貨', type:'ship_return', side:'a'}],
  bom:   [{lab:'訂單', type:'order_bom',   side:'b'},
          {lab:'出貨', type:'bom_ship',    side:'a'}],
  ship:  [{lab:'訂單', type:'order_ship',  side:'b'},
          {lab:'製令', type:'bom_ship',    side:'b'},
          {lab:'退貨', type:'ship_return', side:'a'}],
  ret:   [{lab:'來源', type:'ship_return', side:'b'},
          {lab:'重出', type:'return_ship', side:'a'}]
};
/* 兩種卡片之間可以建立哪一種連結（順向逆向都認，與後端 tc_link_types() 同一份規則） */
var TC_PAIRS = {
  'quote|order':['quote_order','quote'], 'order|bom':['order_bom','order'],
  'order|ship':['order_ship','order'],   'bom|ship':['bom_ship','bom'],
  'ship|ret':['ship_return','ship'],     'order|ret':['ship_return','order'],
  'ret|ship':['return_ship','ret']
};
var TC = {data:null, nodes:{}, linksBy:{}, canLink:false, limit:100,
          dragKey:'', dragMode:'', gripDown:false, cur:null, orderMemo:{}};

function tcPair(k1,k2){
  // ship↔ret 有兩種可能（出貨→退貨、退貨→重出出貨），依拖曳方向決定
  if(k1==='ship'&&k2==='ret') return ['ship_return','ship'];
  if(k1==='ret'&&k2==='ship') return ['return_ship','ret'];
  return TC_PAIRS[k1+'|'+k2] || TC_PAIRS[k2+'|'+k1] || null;
}

$('#btnChain').on('click',function(){ openMask('mkChain'); });

/* 選了客戶就去撈該客戶底下有資料的料號 */
$('#tcClient').on('change',function(){
  var c=$(this).val()||'';
  if(!c){ $('#tcPart').html('<option value="">請先選客戶</option>'); return; }
  $('#tcPart').html('<option value="">載入中…</option>');
  $.post(API+'?action=chain_parts',{client:c},function(r){
    if(!r.ok){ toast(esc(r.error||'讀取料號失敗'), true); return; }
    var h='<option value="">請選擇料號</option>';
    (r.parts||[]).forEach(function(p){
      h+='<option value="'+p.d_id+'">'+esc(p.part_no)+'（'+nf(p.cnt)+'）</option>'; });
    $('#tcPart').html(h);
  },'json').fail(function(){ toast('讀取料號失敗', true); });
});

$('#btnTcGo').on('click',function(){ TC.limit=100; tcLoad(); });
$('#btnTcMore').on('click',function(){ TC.limit+=100; tcLoad(); });
$('#tcOrder').on('change',function(){ if(TC.data) tcLoad(); });
$('#tcAllCli').on('change',function(){ if(TC.data) tcLoad(); });

function tcLoad(){
  var did=$('#tcPart').val()||0;
  if(!did){ toast('請先選擇客戶與料號', true); return; }
  tcMemoOrder();
  $('#tcLanes').html('<div class="tc-empty" style="width:100%;">載入中…</div>');
  $.post(API+'?action=chain_load',{
    d_id:did, client:$('#tcClient').val()||'', date_from:$('#tcFrom').val()||'',
    date_to:$('#tcTo').val()||'', order:$('#tcOrder').val()||'new',
    limit:TC.limit, all_client:$('#tcAllCli').is(':checked')?1:0
  },function(r){
    if(!r.ok){ toast(esc(r.error||'載入失敗'), true);
      $('#tcLanes').html('<div class="tc-empty" style="width:100%;">載入失敗。</div>'); return; }
    TC.data=r; TC.canLink=!!r.can_link;
    tcIndex(); tcRender();
  },'json').fail(function(){ toast('載入失敗', true); });
}

/* 把節點與連結建成索引：每張卡片一個穩定顏色（依它在自己泳道的序號取色） */
function tcIndex(){
  TC.nodes={}; TC.linksBy={};
  TC_KINDS.forEach(function(k){
    var L=TC.data.lanes[k]; if(!L) return;
    L.rows.forEach(function(n,i){
      n.key=k+':'+n.id; n.color=TC_COLORS[i%TC_COLORS.length]; n.idx=i;
      TC.nodes[n.key]=n;
    });
  });
  (TC.data.links||[]).forEach(function(l){
    (TC.linksBy[l.a]=TC.linksBy[l.a]||[]).push(l);
    (TC.linksBy[l.b]=TC.linksBy[l.b]||[]).push(l);
  });
}

/* 重新載入前先記住每一欄目前被拖成什麼順序，載完照樣排回去 */
function tcMemoOrder(){
  TC_KINDS.forEach(function(k){
    var a=[];
    $('#tcLanes .tc-lane[data-kind="'+k+'"] .tc-card').each(function(){ a.push($(this).data('key')); });
    if(a.length) TC.orderMemo[k]=a;
  });
}
function tcApplyMemo(){
  TC_KINDS.forEach(function(k){
    var memo=TC.orderMemo[k]; if(!memo||!memo.length) return;
    var $b=$('#tcLanes .tc-lane[data-kind="'+k+'"] .tc-lane-b');
    memo.forEach(function(key){
      var $c=$b.find('.tc-card[data-key="'+key.replace(/"/g,'')+'"]');
      if($c.length) $b.append($c);          // 依記憶的順序逐一搬到最後＝還原順序
    });
  });
}

function tcRender(){
  var d=TC.data, h='';
  TC_KINDS.forEach(function(k){
    var L=d.lanes[k];
    h+='<div class="tc-lane" data-kind="'+k+'"><div class="tc-lane-h">'+esc(L.name)
      +'<span>'+(L.total>L.rows.length ? '顯示 '+nf(L.rows.length)+' / 共 '+nf(L.total)
                                       : '共 '+nf(L.total))+'</span></div><div class="tc-lane-b">';
    if(!L.rows.length) h+='<div class="tc-empty">沒有資料</div>';
    L.rows.forEach(function(n){ h+=tcCard(n,k); });
    h+='</div></div>';
  });
  $('#tcLanes').html(h);
  tcApplyMemo();
  var p=d.part||{};
  $('#tcInfo').html('料號 <b>'+esc(p.part_no||'')+'</b>'
    +(p.client?'　客戶 <b>'+esc(p.client)+'</b>':'')
    +'　每欄上限 '+nf(d.limit)+' 筆'
    +(TC.canLink?'':'　<span style="color:#DD5138;">（你沒有建立對應的權限，僅可檢視）</span>'));
  var tot=0; TC_KINDS.forEach(function(k){ tot+=d.lanes[k].total; });
  $('#tcFoot').text('五欄合計 '+nf(tot)+' 筆，已建立對應 '+nf((d.links||[]).length)+' 條');
}

function tcCard(n,kind){
  var h='<div class="tc-card'+(n.closed?' tc-closed':'')+'" data-key="'+n.key+'" data-kind="'+kind
      +'" draggable="true" style="border-left-color:'+n.color+';">'
    +'<div class="tc-c-h"><span class="tc-grip" title="拖這裡可調整這一欄的順序">⠿</span>'
    +'<b>'+esc(n.no||'—')+'</b><span class="tc-date">'+esc(dispDate(n.date))+'</span></div>'
    +'<div class="tc-c-q">數量 <b>'+nf(n.qty)+'</b>'
    +(n.price?'　<i>單價 '+np(n.price)+'</i>':'')
    +(n.closed?'<span class="tc-tag">已結案</span>':'')
    +(n.part?'<span class="tc-tag">'+esc(n.part)+'</span>':'')
    +'</div>';
  (TC_BARS[kind]||[]).forEach(function(sp){ h+=tcBarRow(n,sp); });
  return h+'</div>';
}

function tcBarRow(n,sp){
  var ls=(TC.linksBy[n.key]||[]).filter(function(l){
    return l.type===sp.type && (sp.side==='a' ? l.a===n.key : l.b===n.key); });
  var sum=0; ls.forEach(function(l){ sum+=l.qty; });
  var base=Math.max(n.qty||0, sum) || 1;
  var over=(n.qty>0 && sum>n.qty);
  var bar='';
  ls.forEach(function(l){
    var other=TC.nodes[sp.side==='a'?l.b:l.a];
    var col=other?other.color:'#C7B192';
    bar+='<i class="tc-seg" style="width:'+(l.qty/base*100)+'%;background:'+col+';"'
      +' data-link="'+l.type+'|'+l.a+'|'+l.b+'"'
      +' title="'+esc((other?other.no:'（未載入的單據）')+'　'+nf(l.qty)
        +(l.src==='legacy'?'（來自舊欄位）':'')+'　點一下可改分配量或解除')+'"></i>';
  });
  if(sum<base) bar+='<i class="tc-seg tc-seg-free" style="width:'+((base-sum)/base*100)+'%;"></i>';
  return '<div class="tc-bar-row"><span class="tc-bl">'+sp.lab+'</span>'
    +'<div class="tc-bar">'+bar+'</div>'
    +'<span class="tc-bn'+(over?' over':'')+'">'+nf(sum)+'/'+nf(n.qty)+'</span></div>';
}

/* ── 滑過卡片：把相關單據一起打亮，其餘變淡 ─────────────── */
$(document).on('mouseenter','#tcLanes .tc-card',function(){
  var key=$(this).data('key'), rel={};
  rel[key]=1;
  (TC.linksBy[key]||[]).forEach(function(l){ rel[l.a]=1; rel[l.b]=1; });
  $('#tcLanes .tc-card').each(function(){
    var k=$(this).data('key');
    $(this).toggleClass('tc-hi', !!rel[k]).toggleClass('tc-dim', !rel[k]);
  });
  $('#tcLanes .tc-seg[data-link]').each(function(){
    var p=String($(this).attr('data-link')).split('|');
    $(this).toggleClass('tc-seg-over', p[1]===key||p[2]===key);
  });
}).on('mouseleave','#tcLanes .tc-card',function(){
  $('#tcLanes .tc-card').removeClass('tc-hi tc-dim');
  $('#tcLanes .tc-seg').removeClass('tc-seg-over');
});

/* ── 拖曳：抓 ⠿＝同欄調順序，抓卡片本體＝跨欄建立對應 ───── */
$(document).on('mousedown','#tcLanes .tc-grip',function(){ TC.gripDown=true; });
$(document).on('mouseup','#tcLanes',function(){ setTimeout(function(){ TC.gripDown=false; },0); });

$(document).on('dragstart','#tcLanes .tc-card',function(e){
  TC.dragKey=$(this).data('key');
  TC.dragMode=TC.gripDown?'reorder':'link';
  $(this).addClass('tc-drag');
  try{ e.originalEvent.dataTransfer.setData('text/plain',TC.dragKey);
       e.originalEvent.dataTransfer.effectAllowed='move'; }catch(err){}
});
$(document).on('dragend','#tcLanes .tc-card',function(){
  $('#tcLanes .tc-card').removeClass('tc-drag tc-over');
  $('#tcLanes .tc-lane').removeClass('tc-drop');
  TC.dragKey=''; TC.dragMode=''; TC.gripDown=false;
});
$(document).on('dragover','#tcLanes .tc-card',function(e){
  if(!TC.dragKey) return;
  var src=TC.nodes[TC.dragKey], dst=TC.nodes[$(this).data('key')];
  if(!src||!dst||src.key===dst.key) return;
  if(TC.dragMode==='reorder'){
    if($(this).data('kind')!==$('#tcLanes .tc-card[data-key="'+TC.dragKey+'"]').data('kind')) return;
    e.preventDefault();
    // 即時搬移，拖到哪就排到哪（純畫面順序，不寫回資料庫）
    var $d=$('#tcLanes .tc-card[data-key="'+TC.dragKey+'"]'), $t=$(this);
    var r=this.getBoundingClientRect();
    if(e.originalEvent.clientY < r.top+r.height/2) $t.before($d); else $t.after($d);
    return;
  }
  if(!TC.canLink) return;
  if(!tcPair($(this).data('kind'), $('#tcLanes .tc-card[data-key="'+TC.dragKey+'"]').data('kind'))) return;
  e.preventDefault();
  $(this).addClass('tc-over');
}).on('dragleave','#tcLanes .tc-card',function(){ $(this).removeClass('tc-over'); });

$(document).on('drop','#tcLanes .tc-card',function(e){
  e.preventDefault();
  $(this).removeClass('tc-over');
  if(TC.dragMode!=='link'||!TC.dragKey) return;
  if(!TC.canLink){ toast('你沒有建立對應的權限', true); return; }
  var a=TC.nodes[TC.dragKey], b=TC.nodes[$(this).data('key')];
  if(!a||!b) return;
  var pr=tcPair(a.kind, b.kind);
  if(!pr){ toast('這兩種單據之間沒有可建立的對應', true); return; }
  // pr[1] 是這種連結的「來源種類」，據此決定誰是 from、誰是 to
  var from=(a.kind===pr[1])?a:b, to=(a.kind===pr[1])?b:a;
  tcOpenQty(pr[0], from, to, a.kind===pr[1]?a.kind:b.kind);
});

/* ── 點彩色長條＝改分配量或解除 ─────────────────────────── */
$(document).on('click','#tcLanes .tc-seg[data-link]',function(e){
  e.stopPropagation();
  var p=String($(this).attr('data-link')).split('|');
  var l=(TC.data.links||[]).filter(function(x){
    return x.type===p[0]&&x.a===p[1]&&x.b===p[2]; })[0];
  if(!l) return;
  var from=TC.nodes[l.a], to=TC.nodes[l.b];
  if(!from||!to){ toast('對方那張單不在目前載入的範圍內，請放寬日期或筆數再試', true); return; }
  tcOpenQty(l.type, from, to, from.kind, l.qty);
});

/* ── 分配數量跳窗 ───────────────────────────────────────── */
function tcFree(node, type, side, exceptQty){
  var ls=(TC.linksBy[node.key]||[]).filter(function(l){
    return l.type===type && (side==='a'?l.a===node.key:l.b===node.key); });
  var sum=0; ls.forEach(function(l){ sum+=l.qty; });
  return (node.qty||0) - sum + (exceptQty||0);
}
function tcOpenQty(type, from, to, srcKind, existQty){
  var isEdit=(existQty!==undefined && existQty!==null);
  TC.cur={type:type, from:from, to:to, srcKind:srcKind, isEdit:isEdit};
  var noQty=(type==='quote_order');
  var fa=noQty?0:tcFree(from,type,'a',isEdit?existQty:0);
  var fb=noQty?0:tcFree(to,  type,'b',isEdit?existQty:0);
  $('#tqTitle').text(isEdit?'調整對應':'建立對應');
  $('#tqInfo').html('<b>'+esc(from.no)+'</b>（'+esc(from.kindName||tcKindName(from.kind))+'　數量 '+nf(from.qty)+'）'
    +' <span style="color:#8A5A2B;">→</span> '
    +'<b>'+esc(to.no)+'</b>（'+esc(tcKindName(to.kind))+'　數量 '+nf(to.qty)+'）'
    + (noQty ? '<div class="mt-note" style="margin-top:4px;">報價單不分配數量：一份報價本來就會對應到很多張訂單。</div>'
             : '<div class="mt-note" style="margin-top:4px;">可分配上限：'+esc(from.no)+' 還剩 <b>'+nf(fa)+'</b>、'
               +esc(to.no)+' 還剩 <b>'+nf(fb)+'</b></div>')
    + ((from.part&&to.part&&from.part!==to.part)
        ? '<div style="color:#DD5138;font-size:12px;margin-top:4px;">料號不同（'+esc(from.part)+' ↔ '+esc(to.part)
          +'）—— 若是組合件拆件請確認無誤</div>' : ''));
  $('#tqQty').prop('disabled',noQty).val(noQty?'':(isEdit?existQty:Math.max(1,Math.min(fa,fb))));
  $('#tqHint').text(noQty?'':'建議值＝兩邊剩餘量取小');
  $('#tqErr').text('');
  $('#btnTqDel').toggle(!!isEdit);
  openMask('mkTcQty');
}
function tcKindName(k){
  return {quote:'報價單',order:'訂單',bom:'製令',ship:'出貨單',ret:'退貨單'}[k]||k;
}
/* 前端即時擋一次（後端 tc_link() 同規則再擋一次，鐵律8） */
$('#tqQty').on('input',function(){
  var c=TC.cur; if(!c||c.type==='quote_order') return;
  var v=parseInt($(this).val(),10)||0;
  var fa=tcFree(c.from,c.type,'a',c.isEdit?tcCurQty():0);
  var fb=tcFree(c.to,  c.type,'b',c.isEdit?tcCurQty():0);
  var msg='';
  // 兩邊都超過時報「比較緊」的那個上限，使用者才知道到底能填多少
  if(v<=0) msg='分配數量必須大於 0';
  else if(v>fa||v>fb){
    msg=(fa<=fb) ? (c.from.no+' 只剩 '+nf(fa)+' 可分配')
                 : (c.to.no+' 只剩 '+nf(fb)+' 可分配');
  }
  $('#tqErr').text(msg);
  $(this).toggleClass('over',!!msg);
});
function tcCurQty(){
  var c=TC.cur; if(!c) return 0;
  var l=(TC.data.links||[]).filter(function(x){
    return x.type===c.type&&x.a===c.from.key&&x.b===c.to.key; })[0];
  return l?l.qty:0;
}
$('#btnTqOk').on('click',function(){
  var c=TC.cur; if(!c) return;
  var q=(c.type==='quote_order')?1:(parseInt($('#tqQty').val(),10)||0);
  if(c.type!=='quote_order'){
    $('#tqQty').trigger('input');
    if($('#tqErr').text()!==''){ toast($('#tqErr').text(), true); return; }
  }
  var $b=$(this).prop('disabled',true);
  $.post(API+'?action=chain_link',{csrf:CSRF, type:c.type, from_id:c.from.id, to_id:c.to.id,
                                   qty:q, src_kind:c.srcKind},function(r){
    $b.prop('disabled',false);
    if(!r.ok){ toast(esc(r.error||'建立失敗'), true); return; }
    toast(esc(r.message)+((r.warn&&r.warn.length)?'（'+esc(r.warn.join('；'))+'）':''));
    closeMask('mkTcQty'); tcLoad();
  },'json').fail(function(){ $b.prop('disabled',false); toast('建立失敗', true); });
});
$('#btnTqDel').on('click',function(){
  var c=TC.cur; if(!c) return;
  if(!confirm('確定解除「'+c.from.no+' → '+c.to.no+'」這條對應？')) return;
  var $b=$(this).prop('disabled',true);
  $.post(API+'?action=chain_unlink',{csrf:CSRF, type:c.type, from_id:c.from.id, to_id:c.to.id,
                                     src_kind:c.srcKind},function(r){
    $b.prop('disabled',false);
    if(!r.ok){ toast(esc(r.error||'解除失敗'), true); return; }
    toast(esc(r.message)); closeMask('mkTcQty'); tcLoad();
  },'json').fail(function(){ $b.prop('disabled',false); toast('解除失敗', true); });
});

$('#btnRoleHelp').on('click',function(){ openMask('mkRole'); });

init();
})(jQuery);
</script>
</body>
</html>
