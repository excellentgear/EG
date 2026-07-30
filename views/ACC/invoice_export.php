<?php
/**
 * views/ACC/invoice_export.php — 發票開立與轉出（會計模組第三步）
 *
 * 本系統不連動電子發票。流程是：
 *   1) 待開立：依帳款月份把出貨（扣同期退貨）彙總成發票草稿（預設一客戶一月一張，可拆單）
 *   2) 轉出：匯出通用 CSV，拿去電子發票平台開立
 *   3) 回填：把平台實際開出的發票號碼與日期填回來（可逐張輸入，或匯入平台的已開立清單 CSV）
 *   4) 折讓：發票已開立之後才發生的退貨，開銷貨折讓證明單（同期退貨在步驟 1 就已扣除）
 *
 * 防重複開立：acc_invoice_item 對 (src_type, src_id) 設唯一鍵，
 * 同一張出貨單不可能被開進兩張發票；發票作廢後憑證會自動釋放回待開立清單。
 *
 * 資料一律走 src/store/Acc_API.php；權限 acc_lib.php（roles module='accounting'）
 */
session_start();
if (!isset($_SESSION['userName'])) {
    $_SESSION['lastpage'] = "../../views/ACC/invoice_export.php";
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
$taxRate = acc_tax_rate($db);
?>
<!DOCTYPE html>
<html lang="zh-Hant">
<head>
<meta charset="utf-8">
<meta http-equiv="X-UA-Compatible" content="IE=edge">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>發票開立與轉出</title>
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

.a-tabs{display:flex;gap:0;margin-bottom:10px;clear:both;border-bottom:2px solid var(--a-acc);}
.a-tabs button{border:1px solid var(--a-line2);border-bottom:0;background:#F3E7D2;color:var(--a-ink2);
  padding:8px 20px;font-size:14px;cursor:pointer;border-radius:6px 6px 0 0;margin-right:3px;}
.a-tabs button.on{background:var(--a-acc);color:#fff;font-weight:bold;border-color:var(--a-acc-d);}

.a-bar{display:flex;flex-wrap:wrap;gap:6px;align-items:center;
  border:1.5px solid var(--a-line);border-radius:8px;padding:8px 10px;margin-bottom:8px;background:var(--a-bg);}
.a-bar label{margin:0;font-size:13px;color:var(--a-ink);font-weight:normal;}
.a-bar input[type=text],.a-bar input[type=month],.a-bar input[type=date],.a-bar select,.a-bar button{
  height:32px;font-size:13px;line-height:1;padding:0 10px;border:1px solid var(--a-line2);
  border-radius:4px;background:#fff;color:var(--a-ink);}
.a-bar button{cursor:pointer;}
.a-bar button:hover{background:var(--a-ok);}
.a-bar .btn-warm{background:var(--a-acc);color:#fff;border-color:var(--a-acc-d);font-weight:bold;}
.a-bar .btn-warm:hover{background:var(--a-acc-d);}
.a-bar button:disabled{opacity:.45;cursor:default;}
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
.a-stat .sel{margin-left:auto;font-size:13px;}
.a-stat .sel b{color:var(--a-bad);font-size:17px;}

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
table.a-t tbody tr.on{background:#FCEBD2 !important;}
table.a-t tbody tr.blocked{color:#a08a6a;}
table.a-t tbody tr.voided{color:#b09a86;text-decoration:line-through;}

.pill{display:inline-block;padding:1px 8px;border-radius:9px;font-size:11px;line-height:17px;}
.s-ok{background:var(--a-ok);color:#6b4522;}
.s-no_tax{background:#EFE6D6;color:#8a6d45;}
.s-bad_tax{background:var(--a-bad);color:#fff;}
.s-no_full{background:var(--a-acc);color:#fff;}
.st-draft{background:#EFE6D6;color:#8a6d45;}
.st-exported{background:var(--a-acc);color:#fff;}
.st-issued{background:#8A5A2B;color:#fff;}
.st-void{background:#C9B69F;color:#fff;}
.dt-ALLOWANCE{background:var(--a-bad);color:#fff;}
.age-0-30{background:var(--a-ok);color:#6b4522;}
.age-31-60{background:#EFC98F;color:#6b4522;}
.age-61-90{background:var(--a-acc);color:#fff;}
.age-90\+{background:var(--a-bad);color:#fff;}
.btn-mini{height:24px;padding:0 8px;border:1px solid var(--a-line2);border-radius:4px;
  background:#fff;cursor:pointer;color:var(--a-ink);font-size:12px;}
.btn-mini:hover{background:var(--a-ok);}
.no-in{width:112px;height:25px;text-align:center;border:1px solid var(--a-line2);border-radius:4px;
  font-size:12.5px;text-transform:uppercase;color:var(--a-ink);}
.no-in.bad{border-color:var(--a-bad);background:#FDECE7;}
.dt-in{width:126px;height:25px;border:1px solid var(--a-line2);border-radius:4px;font-size:12.5px;color:var(--a-ink);}

.a-mask{display:none;position:fixed;top:0;left:0;right:0;bottom:0;width:100%;height:100%;
  background:rgba(60,40,20,.45);z-index:9999;overflow:auto;padding:34px 12px;}
.a-mask.show{display:block;}
.a-modal{background:#fff;border-radius:8px;width:960px;max-width:100%;margin:0 auto;
  box-shadow:0 6px 30px rgba(0,0,0,.3);}
.a-modal.narrow{width:640px;}
.a-modal .m-head{background:var(--a-ok);color:var(--a-ink);padding:9px 14px;font-weight:bold;
  border-radius:8px 8px 0 0;display:flex;align-items:center;}
.a-modal .m-close{margin-left:auto;cursor:pointer;font-size:17px;}
.a-modal .m-body{padding:14px;max-height:68vh;overflow:auto;}
.a-modal .m-foot{padding:10px 14px;border-top:1px solid var(--a-line);text-align:right;}
.a-modal .m-foot button{height:32px;padding:0 14px;border:1px solid var(--a-line2);border-radius:4px;
  background:#fff;cursor:pointer;color:var(--a-ink);margin-left:5px;}
.a-modal .m-foot button.go{background:var(--a-acc);color:#fff;border-color:var(--a-acc-d);font-weight:bold;}
.a-modal .m-foot button:disabled{opacity:.45;cursor:default;}

.warn{background:#FBE3DC;color:#7a2c17;border-left:5px solid var(--a-bad);
  padding:8px 12px;border-radius:4px;font-size:13px;margin-bottom:8px;}
.info{background:var(--a-bg2);border-left:5px solid var(--a-acc);color:var(--a-ink);
  padding:8px 12px;border-radius:4px;font-size:13px;margin-bottom:8px;line-height:1.6;}

.a-msg{position:fixed;top:64px;right:18px;z-index:12000;min-width:250px;max-width:440px;
  padding:11px 15px;border-radius:6px;display:none;font-size:14px;box-shadow:0 3px 12px rgba(0,0,0,.2);}
.a-msg.ok{background:#F7E0BD;color:#5b3a1e;border-left:5px solid var(--a-acc);}
.a-msg.bad{background:#FBE3DC;color:#7a2c17;border-left:5px solid var(--a-bad);}
.a-noperm{border:1.5px solid var(--a-line);background:var(--a-bg);border-radius:8px;padding:26px;color:var(--a-ink);}
.a-hint{font-size:11.5px;color:var(--a-ink2);margin-top:5px;line-height:1.6;}

@media print{
  .a-bar,.a-pager,.a-tabs,.a-mask,.nav_menu,.left_col,footer{display:none !important;}
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
      <h2 style="margin:6px 0;"><i class="fa fa-file-text-o" style="color:#F0A24B;"></i> 發票開立與轉出
        <small style="color:#8a6d45;">本系統不連動電子發票：產生清單 → 轉出 CSV 拿去平台開立 → 回填發票號碼</small></h2>
    </div>
    <div class="clearfix"></div>

<?php if (!$perms['canView']): ?>
    <div class="a-noperm">
      <h4><i class="fa fa-lock"></i> 無會計模組檢閱權限</h4>
      <p>請洽管理者於「使用者權限設定」指派「會計檢閱／會計登錄／會計管理員」角色。</p>
    </div>
<?php else: ?>
    <div class="a-tabs">
      <button class="tab-btn on" data-tab="cand"><i class="fa fa-plus-square-o"></i> 1. 待開立</button>
      <button class="tab-btn" data-tab="inv"><i class="fa fa-list"></i> 2. 發票管理（轉出／回填／折讓）</button>
      <span class="a-role" style="align-self:center;">目前角色：<b><?= htmlspecialchars($roleLbl) ?></b>
        <i class="fa fa-question-circle" id="btnRoleHelp" title="角色權限說明"></i></span>
    </div>

    <!-- ══ 分頁1：待開立 ══ -->
    <div id="tabCand">
      <div class="a-bar">
        <label>帳款月份</label>
        <input type="month" id="candBm">
        <button id="btnCandPrev">上月</button>
        <button id="btnCandLoad" class="btn-warm"><i class="fa fa-refresh"></i> 重新計算</button>
        <label style="margin-left:8px;"><input type="checkbox" id="candOnlyOk" checked> 只顯示可開立的</label>
        <label><input type="checkbox" id="candSplit"> 每張出貨單各開一張發票</label>
      </div>

      <div class="a-stat">
        <div><span class="n" id="cdGroups">—</span> <span class="l">客戶</span></div>
        <div><span class="n" id="cdCan">—</span> <span class="l">可開立</span></div>
        <div><span class="n bad" id="cdBlocked">—</span> <span class="l">缺發票資料</span></div>
        <div class="sep"></div>
        <div><span class="n big" id="cdNet">—</span> <span class="l">未稅</span></div>
        <div><span class="n" id="cdTax">—</span> <span class="l">稅額</span></div>
        <div><span class="n big" id="cdTotal">—</span> <span class="l">含稅</span></div>
        <div class="sel">已選 <b id="cdSel">0</b> 家</div>
      </div>
      <div id="cdWarn"></div>

      <div class="a-bar" style="background:var(--a-bg2);">
        <button id="btnCdAll">全選可開立</button>
        <button id="btnCdNone">清除選取</button>
        <?php if ($perms['canEdit']): ?>
        <button id="btnCdCreate" class="btn-warm" disabled><i class="fa fa-file-text-o"></i> 產生發票草稿</button>
        <?php endif; ?>
        <a href="customer_invoice_data.php" style="text-decoration:none;">
          <button type="button"><i class="fa fa-id-card-o"></i> 去補客戶發票資料</button></a>
      </div>

      <div class="a-wrap">
        <table class="a-t" id="tblCand">
          <thead><tr>
            <th style="width:32px;"><input type="checkbox" id="cdChkAll"></th>
            <th>客戶</th><th>統一編號</th><th>發票抬頭</th><th>發票資料</th>
            <th>憑證筆數</th><th>未稅</th><th>稅額</th><th>含稅</th><th>明細</th>
          </tr></thead>
          <tbody id="cdBody"><tr><td colspan="10" style="padding:22px;color:#8a6d45;">載入中…</td></tr></tbody>
        </table>
      </div>
      <div class="a-hint">
        「憑證」＝該帳款月份的出貨單與退貨單。<b>同期退貨會直接在發票中扣除</b>，不需另開折讓單。
        已開過發票的憑證不會再出現（資料庫層以唯一鍵防止同一張出貨被開兩次）；發票作廢後憑證會自動回到這裡。
        缺統一編號或發票抬頭的客戶無法開立，請先補齊主檔。
      </div>
    </div>

    <!-- ══ 分頁2：發票管理 ══ -->
    <div id="tabInv" style="display:none;">
      <div class="a-bar">
        <label>帳款月份</label>
        <input type="month" id="invBmFrom"> ~ <input type="month" id="invBmTo">
        <label>狀態</label>
        <select id="invStatus">
          <option value="all">全部</option>
          <option value="draft">草稿（待轉出）</option>
          <option value="exported">已轉出（待回填號碼）</option>
          <option value="issued">已開立</option>
          <option value="void">已作廢</option>
        </select>
        <label>類型</label>
        <select id="invDocType">
          <option value="all">全部</option>
          <option value="INVOICE">發票</option>
          <option value="ALLOWANCE">折讓單</option>
        </select>
        <input type="text" id="invKw" placeholder="發票號碼／客戶／統編" style="width:190px;">
        <label><input type="checkbox" id="invOnlyOpen"> 只看未收款的</label>
        <button id="btnInvLoad" class="btn-warm"><i class="fa fa-search"></i> 查詢</button>
      </div>

      <div class="a-stat">
        <div><span class="n" id="ivCount">—</span> <span class="l">張</span></div>
        <div><span class="n" id="ivDraft">—</span> <span class="l">草稿</span></div>
        <div><span class="n" id="ivExported">—</span> <span class="l">已轉出</span></div>
        <div><span class="n" id="ivIssued">—</span> <span class="l">已開立</span></div>
        <div class="sep"></div>
        <div><span class="n big" id="ivTotal">—</span> <span class="l">含稅合計</span></div>
        <div><span class="n bad" id="ivOpen">—</span> <span class="l">未收餘額</span></div>
        <div class="sel">已選 <b id="ivSel">0</b> 張</div>
      </div>
      <div id="ivAge"></div>

      <div class="a-bar" style="background:var(--a-bg2);">
        <?php if ($perms['canEdit']): ?>
        <button id="btnInvExport" class="btn-warm" disabled><i class="fa fa-download"></i> 轉出 CSV（供平台開立）</button>
        <label><input type="checkbox" id="expByItem"> 逐品項列出</label>
        <button id="btnInvMark" disabled><i class="fa fa-check-square-o"></i> 標記為已轉出</button>
        <button id="btnBackfillCsv"><i class="fa fa-upload"></i> 匯入平台已開立清單</button>
        <button id="btnAllowance"><i class="fa fa-reply"></i> 開折讓單</button>
        <?php endif; ?>
        <button id="btnInvPrint"><i class="fa fa-print"></i> 列印／PDF</button>
      </div>

      <div class="a-pager">
        <span id="ivPgInfo" style="margin-right:auto;color:#8a6d45;"></span>
        每頁 <select id="ivPerPage"><option>5</option><option>10</option><option selected>20</option><option>50</option></select> 筆
        <span id="ivPgBtns"></span>
      </div>

      <div class="a-wrap">
        <table class="a-t" id="tblInv">
          <thead><tr>
            <th style="width:32px;"><input type="checkbox" id="ivChkAll"></th>
            <th>類型</th><th>狀態</th><th>發票號碼</th><th>開立日期</th>
            <th>客戶</th><th>統一編號</th><th>帳款月份</th>
            <th>未稅</th><th>稅額</th><th>含稅</th><th>已收</th><th>未收</th><th>帳齡</th>
            <th>明細</th><th>操作</th>
          </tr></thead>
          <tbody id="ivBody"><tr><td colspan="16" style="padding:22px;color:#8a6d45;">請按查詢</td></tr></tbody>
        </table>
      </div>
      <div class="a-hint">
        流程：<b>草稿</b>（產生後）→ 轉出 CSV 並<b>標記為已轉出</b> → 到電子發票平台開立 →
        把實際號碼<b>回填</b>（可在「發票號碼」欄直接輸入，或用「匯入平台已開立清單」批次比對）→ 狀態變<b>已開立</b>。
        發票號碼須為 2 個英文字母＋8 位數字，系統會驗格式並防止重號。
        帳齡自發票開立日起算，只計已開立且尚有未收餘額者。
      </div>
    </div>
<?php endif; ?>
  </div>
  <?php include '../partPage/footer.html' ?>
</div>
</div>

<!-- 憑證明細 -->
<div class="a-mask" id="mkItems"><div class="a-modal">
  <div class="m-head"><i class="fa fa-list"></i>&nbsp;<span id="itTitle">明細</span>
    <span class="m-close" data-close="mkItems">✕</span></div>
  <div class="m-body" id="itBody"></div>
  <div class="m-foot"><button data-close="mkItems">關閉</button></div>
</div></div>

<?php if ($perms['canEdit']): ?>
<!-- 匯入平台已開立清單 -->
<div class="a-mask" id="mkBf"><div class="a-modal">
  <div class="m-head"><i class="fa fa-upload"></i>&nbsp;匯入平台「已開立清單」回填發票號碼
    <span class="m-close" data-close="mkBf">✕</span></div>
  <div class="m-body">
    <div class="info">
      從電子發票平台匯出「已開立發票清單」CSV 上傳即可。系統會用<b>買方統一編號</b>比對到待回填的發票，
      同一統編有多張時再以<b>含稅金額</b>挑最接近的。支援 UTF-8 與 Big5、日期格式 2026/5/5、20260505、2026-05-05 皆可。
      比對不到的列不會寫入，會列出來讓你人工處理。
    </div>
    <div class="a-bar">
      <input type="file" id="bfFile" accept=".csv,text/csv" style="font-size:13px;">
      <button id="btnBfGo" class="btn-warm"><i class="fa fa-search"></i> 比對</button>
      <span id="bfSummary" style="font-size:13px;color:#5b3a1e;margin-left:8px;"></span>
    </div>
    <div id="bfResult"></div>
  </div>
  <div class="m-foot">
    <span id="bfSel" style="float:left;color:#5b3a1e;font-size:13px;line-height:32px;"></span>
    <button data-close="mkBf">取消</button>
    <button class="go" id="btnBfApply" disabled><i class="fa fa-check"></i> 回填勾選的發票號碼</button>
  </div>
</div></div>

<!-- 折讓單 -->
<div class="a-mask" id="mkAllow"><div class="a-modal">
  <div class="m-head"><i class="fa fa-reply"></i>&nbsp;開立銷貨折讓證明單
    <span class="m-close" data-close="mkAllow">✕</span></div>
  <div class="m-body">
    <div class="info">
      折讓單用於<b>發票已經開立之後才發生的退貨</b>。若退貨與出貨在同一帳款月份，
      在「待開立」階段就已經直接從發票金額中扣除，不需要開折讓單。
    </div>
    <div class="a-bar">
      <label>客戶</label>
      <input type="text" id="alCust" placeholder="輸入客戶簡稱" style="width:190px;">
      <button id="btnAlLoad" class="btn-warm">查詢可折讓資料</button>
    </div>
    <div id="alBox"></div>
  </div>
  <div class="m-foot">
    <span id="alSel" style="float:left;color:#5b3a1e;font-size:13px;line-height:32px;"></span>
    <button data-close="mkAllow">取消</button>
    <button class="go" id="btnAlCreate" disabled><i class="fa fa-check"></i> 建立折讓單</button>
  </div>
</div></div>
<?php endif; ?>

<!-- 角色說明 -->
<div class="a-mask" id="mkRole"><div class="a-modal narrow">
  <div class="m-head">角色權限說明<span class="m-close" data-close="mkRole">✕</span></div>
  <div class="m-body" style="font-size:13.5px;color:#5b3a1e;line-height:1.9;">
    <b>管理者</b>：固定擁有全部權限。<br>
    <b>會計管理員</b>：會計登錄的全部權限，另可<b>作廢發票</b>。<br>
    <b>會計登錄</b>：可產生發票、轉出、回填號碼、開折讓單。<br>
    <b>會計檢閱</b>：僅可查詢與列印。<br>
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
var CAN_EDIT  = <?= $perms['canEdit']  ? 'true' : 'false' ?>;
var CAN_ADMIN = <?= $perms['canAdmin'] ? 'true' : 'false' ?>;
var TAX_RATE  = <?= json_encode($taxRate) ?>;

var CSRF = '';
var cand = [], candSel = {};
var invRows = [], invSel = {}, ivPage = 1, ivPerPage = 20, ivTotal = 0;
var bfMatched = [], alData = null;

var READY_LABEL  = {ok:'可開立', no_tax:'缺統編', bad_tax:'統編錯誤', no_full:'缺發票抬頭'};
var STATUS_LABEL = {draft:'草稿', exported:'已轉出', issued:'已開立', void:'已作廢'};

function esc(s){ return String(s==null?'':s).replace(/[&<>"']/g,function(c){
  return {'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#39;'}[c]; }); }
function nf(n){ return Math.round(Number(n)||0).toLocaleString('en-US'); }
function np(n){ var v=Number(n)||0; return String(parseFloat(v.toFixed(4))); }
function toast(m,bad){
  var $m=$('#msg').removeClass('ok bad').addClass(bad?'bad':'ok').html(m).stop(true,true).fadeIn(150);
  clearTimeout($m.data('t')); $m.data('t',setTimeout(function(){ $m.fadeOut(400); },bad?7000:3800));
}
function openMask(id){ $('#'+id).addClass('show'); }
function closeMask(id){ $('#'+id).removeClass('show'); }
$(document).on('click','[data-close]',function(){ closeMask($(this).data('close')); });
$(document).on('click','.a-mask',function(e){ if(e.target===this) $(this).removeClass('show'); });
$('#btnRoleHelp').on('click',function(){ openMask('mkRole'); });
function qs(o){ return Object.keys(o).map(function(k){ return k+'='+encodeURIComponent(o[k]); }).join('&'); }

/* 台灣電子發票號碼：2 英文字母 + 8 數字 */
function validInvNo(s){ return /^[A-Z]{2}\d{8}$/.test(String(s||'').toUpperCase()); }

/* 分頁切換 */
$('.tab-btn').on('click',function(){
  var t=$(this).data('tab');
  $('.tab-btn').removeClass('on'); $(this).addClass('on');
  $('#tabCand').toggle(t==='cand');
  $('#tabInv').toggle(t==='inv');
  if(t==='inv' && !invRows.length) loadInv();
});

/* 預設上個月 */
(function(){
  var d=new Date(); d.setDate(1); d.setMonth(d.getMonth()-1);
  var m=d.getFullYear()+'-'+('0'+(d.getMonth()+1)).slice(-2);
  $('#candBm').val(m); $('#invBmFrom').val(m); $('#invBmTo').val(m);
})();
$('#btnCandPrev').on('click',function(){
  var v=$('#candBm').val(); if(!v) return;
  var p=v.split('-'), d=new Date(+p[0], +p[1]-2, 1);
  $('#candBm').val(d.getFullYear()+'-'+('0'+(d.getMonth()+1)).slice(-2));
  loadCand();
});

$.getJSON(API,{action:'meta'},function(r){
  if(!r.ok){ toast(esc(r.error||'初始化失敗'), true); return; }
  CSRF=r.csrf; loadCand();
}).fail(function(){ toast('無法連線到會計 API', true); });

/* ══════════════ 分頁1：待開立 ══════════════ */
function loadCand(){
  var bm=$('#candBm').val();
  if(!bm){ toast('請選擇帳款月份', true); return; }
  $('#cdBody').html('<tr><td colspan="10" style="padding:22px;color:#8a6d45;">計算中…</td></tr>');
  candSel={};
  $.post(API+'?action=inv_candidates',{bm:bm},function(r){
    if(!r.ok){ toast(esc(r.error||'查詢失敗'), true); return; }
    cand=r.groups||[];
    var s=r.summary||{};
    $('#cdGroups').text(nf(s.groups)); $('#cdCan').text(nf(s.can_issue));
    $('#cdBlocked').text(nf(s.blocked));
    $('#cdNet').text(nf(s.net_amt)); $('#cdTax').text(nf(s.tax_amt)); $('#cdTotal').text(nf(s.total_amt));
    $('#cdWarn').html(s.blocked>0
      ? '<div class="warn"><b>'+nf(s.blocked)+'</b> 家客戶因為缺統一編號或發票抬頭而無法開立發票（金額 '
        +nf(s.total_amt- cand.filter(function(g){return g.can_issue;})
             .reduce(function(a,g){return a+g.total_amt;},0))
        +' 元）。請先到「客戶發票資料維護」補齊。</div>'
      : '');
    renderCand();
  },'json').fail(function(){ toast('查詢失敗', true); });
}

function renderCand(){
  var list = $('#candOnlyOk').is(':checked') ? cand.filter(function(g){ return g.can_issue; }) : cand;
  if(!list.length){
    $('#cdBody').html('<tr><td colspan="10" style="padding:22px;color:#8a6d45;">'
      +'此帳款月份沒有待開立的憑證（可能都已開過發票，或取消勾選「只顯示可開立的」看被擋下的客戶）。</td></tr>');
    updateCdSel(); return;
  }
  var h='';
  list.forEach(function(g){
    var k=g.customer;
    h+='<tr data-c="'+esc(k)+'" class="'+(candSel[k]?'on ':'')+(g.can_issue?'':'blocked')+'">'
      +'<td><input type="checkbox" class="cd-ck"'+(candSel[k]?' checked':'')
        +(g.can_issue&&CAN_EDIT?'':' disabled')+'></td>'
      +'<td class="l">'+esc(g.customer)+(g.in_master?'':' <span class="pill s-bad_tax">未對應主檔</span>')+'</td>'
      +'<td>'+esc(g.tax_id||'—')+'</td>'
      +'<td class="l">'+esc(g.customer_full||'—')+'</td>'
      +'<td><span class="pill s-'+g.inv_ready+'">'+esc(READY_LABEL[g.inv_ready]||g.inv_ready)+'</span></td>'
      +'<td class="r">'+nf(g.item_cnt)+'</td>'
      +'<td class="r"><b>'+nf(g.net_amt)+'</b></td>'
      +'<td class="r">'+nf(g.tax_amt)+'</td>'
      +'<td class="r"><b style="color:#8A5A2B;">'+nf(g.total_amt)+'</b></td>'
      +'<td><button class="btn-mini cd-detail" data-c="'+esc(k)+'">憑證</button></td>'
      +'</tr>';
  });
  $('#cdBody').html(h);
  updateCdSel();
}
$('#candOnlyOk').on('change', renderCand);

$(document).on('change','.cd-ck',function(){
  var k=$(this).closest('tr').data('c');
  if(this.checked) candSel[k]=1; else delete candSel[k];
  $(this).closest('tr').toggleClass('on', this.checked);
  updateCdSel();
});
$('#cdChkAll').on('change',function(){
  var on=this.checked;
  $('#cdBody .cd-ck:not(:disabled)').prop('checked',on).each(function(){
    var k=$(this).closest('tr').data('c');
    if(on) candSel[k]=1; else delete candSel[k];
    $(this).closest('tr').toggleClass('on',on);
  });
  updateCdSel();
});
$('#btnCdAll').on('click',function(){ $('#cdChkAll').prop('checked',true).trigger('change'); });
$('#btnCdNone').on('click',function(){ $('#cdChkAll').prop('checked',false).trigger('change'); candSel={}; renderCand(); });
function updateCdSel(){
  var n=Object.keys(candSel).length;
  $('#cdSel').text(nf(n));
  $('#btnCdCreate').prop('disabled', n===0);
}

$(document).on('click','.cd-detail',function(){
  var k=$(this).data('c'), g=null;
  cand.forEach(function(x){ if(x.customer===k) g=x; });
  if(!g) return;
  $('#itTitle').text('待開立憑證　'+g.customer+'　'+g.billing_month);
  var h='<table class="a-t"><thead><tr><th>#</th><th>類型</th><th>單號</th><th>日期</th>'
       +'<th>料號</th><th>品名規格</th><th>數量</th><th>單價</th><th>金額</th></tr></thead><tbody>';
  (g.items||[]).forEach(function(it,i){
    var ret=(it.src_type==='IR');
    h+='<tr'+(ret?' style="color:#DD5138;"':'')+'><td>'+(i+1)+'</td>'
      +'<td>'+(ret?'退貨':'出貨')+'</td><td>'+esc(it.src_no)+'</td><td>'+esc(it.src_date)+'</td>'
      +'<td class="l">'+esc(it.product_id)+'</td><td class="l">'+esc((it.spec||'').substr(0,24))+'</td>'
      +'<td class="r">'+nf(it.qty)+'</td><td class="r">'+np(it.unit_price)+'</td>'
      +'<td class="r">'+nf(it.amount)+'</td></tr>';
  });
  h+='</tbody></table><div style="text-align:right;margin-top:8px;font-size:14px;">'
    +'未稅 <b>'+nf(g.net_amt)+'</b>　稅額 <b>'+nf(g.tax_amt)+'</b>　含稅 <b style="color:#DD5138;">'
    +nf(g.total_amt)+'</b></div>';
  $('#itBody').html(h);
  openMask('mkItems');
});

$('#btnCdCreate').on('click',function(){
  var sel=Object.keys(candSel);
  if(!sel.length) return;
  var split=$('#candSplit').is(':checked');
  if(!confirm('確定為 '+sel.length+' 家客戶產生發票草稿？\n'
    +(split?'（每張出貨單各開一張發票）':'（一客戶一張，同期退貨已扣除）'))) return;
  var bm=$('#candBm').val();
  var payload=sel.map(function(c){ return {customer:c, billing_month:bm}; });
  var $b=$(this).prop('disabled',true).html('<i class="fa fa-spinner fa-spin"></i> 產生中…');
  $.post(API+'?action=inv_create',
    {csrf:CSRF, sel:JSON.stringify(payload), split_by_src:split?1:0},
    function(r){
      $b.prop('disabled',false).html('<i class="fa fa-file-text-o"></i> 產生發票草稿');
      if(!r.ok){ toast(esc(r.error||'產生失敗'), true); return; }
      toast('<b>'+esc(r.message)+'</b>');
      if(r.errors && r.errors.length) toast(r.errors.map(esc).join('<br>'), true);
      candSel={}; loadCand();
      invRows=[]; $('.tab-btn[data-tab=inv]').click();
    },'json').fail(function(x){
      $b.prop('disabled',false).html('<i class="fa fa-file-text-o"></i> 產生發票草稿');
      var m='產生失敗'; try{ m=JSON.parse(x.responseText).error||m; }catch(e){}
      toast(esc(m), true);
    });
});
$('#btnCandLoad').on('click', loadCand);
$('#candBm').on('change', loadCand);

/* ══════════════ 分頁2：發票管理 ══════════════ */
function invFilters(){
  return {bm_from:$('#invBmFrom').val(), bm_to:$('#invBmTo').val(),
          status:$('#invStatus').val(), doc_type:$('#invDocType').val(),
          kw:$('#invKw').val(), only_open:$('#invOnlyOpen').is(':checked')?1:0,
          page:ivPage, per_page:ivPerPage};
}
function loadInv(){
  $('#ivBody').html('<tr><td colspan="16" style="padding:22px;color:#8a6d45;">查詢中…</td></tr>');
  invSel={};
  $.post(API+'?action=inv_list', invFilters(), function(r){
    if(!r.ok){ toast(esc(r.error||'查詢失敗'), true); return; }
    invRows=r.rows||[]; ivTotal=r.total||0;
    var s=r.summary||{};
    $('#ivCount').text(nf(s.count)); $('#ivDraft').text(nf(s.draft));
    $('#ivExported').text(nf(s.exported)); $('#ivIssued').text(nf(s.issued));
    $('#ivTotal').text(nf(s.total_amt)); $('#ivOpen').text(nf(s.open_amt));
    var a=s.age||{}, tot=(a['0-30']||0)+(a['31-60']||0)+(a['61-90']||0)+(a['90+']||0);
    $('#ivAge').html(tot>0
      ? '<div class="info">未收帳齡：'
        +'0–30天 <b>'+nf(a['0-30'])+'</b>　31–60天 <b>'+nf(a['31-60'])+'</b>　'
        +'61–90天 <b style="color:#d98a33;">'+nf(a['61-90'])+'</b>　'
        +'90天以上 <b style="color:#DD5138;">'+nf(a['90+'])+'</b></div>'
      : '');
    renderInv(); renderIvPager();
  },'json').fail(function(){ toast('查詢失敗', true); });
}

function renderInv(){
  if(!invRows.length){
    $('#ivBody').html('<tr><td colspan="16" style="padding:22px;color:#8a6d45;">'
      +'此條件下沒有發票。請先到「1. 待開立」產生發票草稿。</td></tr>');
    updateIvSel(); return;
  }
  var h='';
  invRows.forEach(function(v,i){
    var id=v.invoice_id, isAllow=(v.doc_type==='ALLOWANCE'), isVoid=(v.status==='void');
    var canEditNo = CAN_EDIT && (v.status==='draft' || v.status==='exported');
    h+='<tr data-i="'+i+'" data-id="'+id+'" class="'+(invSel[id]?'on ':'')+(isVoid?'voided':'')+'">'
      +'<td><input type="checkbox" class="iv-ck"'+(invSel[id]?' checked':'')+(isVoid?' disabled':'')+'></td>'
      +'<td>'+(isAllow?'<span class="pill dt-ALLOWANCE">折讓單</span>':'發票')+'</td>'
      +'<td><span class="pill st-'+v.status+'">'+esc(STATUS_LABEL[v.status]||v.status)+'</span></td>'
      +'<td>'+(canEditNo
          ? '<input type="text" class="no-in" data-id="'+id+'" maxlength="10" value="'+esc(v.invoice_no||'')+'" placeholder="AB12345678">'
          : '<b>'+esc(v.invoice_no||'—')+'</b>')+'</td>'
      +'<td>'+(canEditNo
          ? '<input type="date" class="dt-in" data-id="'+id+'" value="'+esc(v.invoice_date||'')+'">'
          : esc(v.invoice_date||'—'))+'</td>'
      +'<td class="l">'+esc(v.customer_full||v.customer_name)+'</td>'
      +'<td>'+esc(v.tax_id||'—')+'</td>'
      +'<td>'+esc(v.billing_month)+'</td>'
      +'<td class="r">'+(isAllow?'-':'')+nf(v.sales_amount)+'</td>'
      +'<td class="r">'+(isAllow?'-':'')+nf(v.tax_amount)+'</td>'
      +'<td class="r"><b>'+(isAllow?'-':'')+nf(v.total_amount)+'</b></td>'
      +'<td class="r">'+(v.paid_amt>0?nf(v.paid_amt):'')+'</td>'
      +'<td class="r">'+(v.open_amt>0.005 && !isVoid?'<b style="color:#DD5138;">'+nf(v.open_amt)+'</b>':'')+'</td>'
      +'<td>'+(v.age_bucket?'<span class="pill age-'+v.age_bucket+'">'+v.age_days+'天</span>':'')+'</td>'
      +'<td><button class="btn-mini iv-detail" data-id="'+id+'">'+nf(v.item_cnt)+' 筆</button></td>'
      +'<td>'+(CAN_ADMIN && !isVoid
          ? '<button class="btn-mini iv-void" data-id="'+id+'">作廢</button>' : '')+'</td>'
      +'</tr>';
  });
  $('#ivBody').html(h);
  updateIvSel();
}

function renderIvPager(){
  var pages=Math.max(1,Math.ceil(ivTotal/ivPerPage));
  if(ivPage>pages) ivPage=pages;
  var from=ivTotal?(ivPage-1)*ivPerPage+1:0, to=Math.min(ivPage*ivPerPage,ivTotal);
  $('#ivPgInfo').text('顯示 '+from+'–'+to+'，共 '+nf(ivTotal)+' 張');
  if(pages<=1 && ivTotal<=10){ $('#ivPgBtns').html(''); return; }
  var h='<button data-p="1" '+(ivPage===1?'disabled':'')+'>«</button>'
      +'<button data-p="'+(ivPage-1)+'" '+(ivPage===1?'disabled':'')+'>‹</button>';
  var st=Math.max(1,ivPage-2), en=Math.min(pages,st+4); st=Math.max(1,en-4);
  for(var p=st;p<=en;p++) h+='<button data-p="'+p+'" class="'+(p===ivPage?'on':'')+'">'+p+'</button>';
  h+='<button data-p="'+(ivPage+1)+'" '+(ivPage===pages?'disabled':'')+'>›</button>'
   +'<button data-p="'+pages+'" '+(ivPage===pages?'disabled':'')+'>»</button>';
  $('#ivPgBtns').html(h);
}
$(document).on('click','#ivPgBtns button',function(){
  var p=parseInt($(this).data('p'),10); if(p>=1){ ivPage=p; loadInv(); }
});
$('#ivPerPage').on('change',function(){ ivPerPage=parseInt(this.value,10)||20; ivPage=1; loadInv(); });
$('#btnInvLoad').on('click',function(){ ivPage=1; loadInv(); });
$('#invKw').on('keydown',function(e){ if(e.key==='Enter'){ e.preventDefault(); ivPage=1; loadInv(); } });
$('#invStatus,#invDocType,#invOnlyOpen,#invBmFrom,#invBmTo').on('change',function(){ ivPage=1; loadInv(); });

$(document).on('change','.iv-ck',function(){
  var id=$(this).closest('tr').data('id');
  if(this.checked) invSel[id]=1; else delete invSel[id];
  $(this).closest('tr').toggleClass('on',this.checked);
  updateIvSel();
});
$('#ivChkAll').on('change',function(){
  var on=this.checked;
  $('#ivBody .iv-ck:not(:disabled)').prop('checked',on).each(function(){
    var id=$(this).closest('tr').data('id');
    if(on) invSel[id]=1; else delete invSel[id];
    $(this).closest('tr').toggleClass('on',on);
  });
  updateIvSel();
});
function updateIvSel(){
  var n=Object.keys(invSel).length;
  $('#ivSel').text(nf(n));
  $('#btnInvExport,#btnInvMark').prop('disabled', n===0);
}

/* 憑證明細 */
$(document).on('click','.iv-detail',function(){
  var id=$(this).data('id');
  $('#itBody').html('<div style="padding:16px;color:#8a6d45;">載入中…</div>');
  openMask('mkItems');
  $.post(API+'?action=inv_items',{invoice_id:id},function(r){
    if(!r.ok){ $('#itBody').html('<div style="padding:16px;color:#DD5138;">'+esc(r.error)+'</div>'); return; }
    var v=r.invoice;
    $('#itTitle').text((v.doc_type==='ALLOWANCE'?'折讓單':'發票')+'　'
      +(v.invoice_no||'#'+v.invoice_id)+'　'+v.customer_name);
    var h='<div class="info">客戶 <b>'+esc(v.customer_full||v.customer_name)+'</b>　統編 '+esc(v.tax_id||'—')
      +'　帳款月份 '+esc(v.billing_month)+'　狀態 '+esc(STATUS_LABEL[v.status]||v.status)
      +(v.void_reason?'　作廢原因：'+esc(v.void_reason):'')+'</div>'
      +'<table class="a-t"><thead><tr><th>#</th><th>類型</th><th>來源單號</th><th>日期</th>'
      +'<th>料號</th><th>品名規格</th><th>數量</th><th>單價</th><th>金額</th></tr></thead><tbody>';
    (r.items||[]).forEach(function(it,i){
      var ret=(it.src_type==='IR');
      h+='<tr'+(ret?' style="color:#DD5138;"':'')+'><td>'+(i+1)+'</td>'
        +'<td>'+(ret?'退貨':'出貨')+'</td><td>'+esc(it.src_no)+'</td><td>'+esc(it.src_date)+'</td>'
        +'<td class="l">'+esc(it.product_id)+'</td><td class="l">'+esc((it.spec||'').substr(0,24))+'</td>'
        +'<td class="r">'+nf(it.qty)+'</td><td class="r">'+np(it.unit_price)+'</td>'
        +'<td class="r">'+nf(it.amount)+'</td></tr>';
    });
    h+='</tbody></table><div style="text-align:right;margin-top:8px;font-size:14px;">'
      +'未稅 <b>'+nf(v.sales_amount)+'</b>　稅額 <b>'+nf(v.tax_amount)+'</b>　含稅 <b style="color:#DD5138;">'
      +nf(v.total_amount)+'</b></div>';
    $('#itBody').html(h);
  },'json').fail(function(){ $('#itBody').html('<div style="padding:16px;color:#DD5138;">載入失敗</div>'); });
});

/* 轉出 CSV / 標記已轉出 */
$('#btnInvExport').on('click',function(){
  var ids=Object.keys(invSel);
  if(!ids.length) return;
  window.location = API+'?action=inv_export_csv&ids='+ids.join(',')
    +($('#expByItem').is(':checked')?'&by_item=1':'');
});
$('#btnInvMark').on('click',function(){
  var ids=Object.keys(invSel).map(Number);
  if(!ids.length) return;
  if(!confirm('把 '+ids.length+' 張草稿標記為「已轉出」？\n（只有草稿狀態會被更新）')) return;
  var $b=$(this).prop('disabled',true);
  $.post(API+'?action=inv_export_mark',{csrf:CSRF, ids:JSON.stringify(ids)},function(r){
    $b.prop('disabled',false);
    if(!r.ok){ toast(esc(r.error||'標記失敗'), true); return; }
    toast(esc(r.message)); loadInv();
  },'json').fail(function(){ $b.prop('disabled',false); toast('標記失敗', true); });
});

/* 就地回填發票號碼：號碼與日期都填好且格式正確才送出 */
$(document).on('input','.no-in',function(){
  var v=this.value.toUpperCase(); this.value=v;
  $(this).toggleClass('bad', v!=='' && !validInvNo(v));
});
$(document).on('blur','.no-in, .dt-in', function(){
  if(!CAN_EDIT) return;
  var id=$(this).data('id');
  var $tr=$('#ivBody tr[data-id="'+id+'"]');
  var no=$.trim($tr.find('.no-in').val()||'').toUpperCase();
  var dt=$.trim($tr.find('.dt-in').val()||'');
  if(no==='') return;
  if(!validInvNo(no)){ return; }             // 格式不對先不送，等使用者改完
  if(dt===''){ toast('請一併填寫開立日期', true); $tr.find('.dt-in').focus(); return; }
  var i=parseInt($tr.data('i'),10), v=invRows[i];
  if(v && v.invoice_no===no && v.invoice_date===dt) return;
  $.post(API+'?action=inv_backfill',
    {csrf:CSRF, pairs:JSON.stringify([{invoice_id:id, invoice_no:no, invoice_date:dt}])},
    function(r){
      if(!r.ok){ toast(esc(r.error||'回填失敗'), true); return; }
      if(r.errors && r.errors.length){ toast(r.errors.map(esc).join('<br>'), true); return; }
      toast(esc(r.message)); loadInv();
    },'json').fail(function(x){
      var m='回填失敗'; try{ m=JSON.parse(x.responseText).error||m; }catch(e){}
      toast(esc(m), true);
    });
});

/* 作廢 */
$(document).on('click','.iv-void',function(){
  var id=$(this).data('id');
  var reason=prompt('作廢原因（會記錄下來，作廢後該發票的出貨憑證會回到待開立清單）：');
  if(reason===null) return;
  $.post(API+'?action=inv_void',{csrf:CSRF, invoice_id:id, reason:reason},function(r){
    if(!r.ok){ toast(esc(r.error||'作廢失敗'), true); return; }
    toast(esc(r.message)); loadInv(); cand=[]; loadCand();
  },'json').fail(function(x){
    var m='作廢失敗'; try{ m=JSON.parse(x.responseText).error||m; }catch(e){}
    toast(esc(m), true);
  });
});
$('#btnInvPrint').on('click',function(){ window.print(); });

/* ══ 匯入平台已開立清單 ══ */
if(CAN_EDIT){
  $('#btnBackfillCsv').on('click',function(){
    bfMatched=[]; $('#bfFile').val(''); $('#bfResult').empty(); $('#bfSummary').empty();
    $('#btnBfApply').prop('disabled',true); $('#bfSel').text('');
    openMask('mkBf');
  });
  $('#btnBfGo').on('click',function(){
    var f=$('#bfFile')[0].files[0];
    if(!f){ toast('請先選擇 CSV 檔', true); return; }
    var fd=new FormData(); fd.append('file',f); fd.append('csrf',CSRF);
    var $b=$(this).prop('disabled',true).text('比對中…');
    $.ajax({url:API+'?action=inv_backfill_csv', type:'POST', data:fd, processData:false,
      contentType:false, dataType:'json'})
    .done(function(r){
      $b.prop('disabled',false).html('<i class="fa fa-search"></i> 比對');
      if(!r.ok){ toast(esc(r.error||'比對失敗'), true); return; }
      bfMatched=r.matched||[];
      var s=r.summary||{};
      $('#bfSummary').html('CSV '+nf(s.csv_rows)+' 列：比對到 <b style="color:#8A5A2B;">'+nf(s.matched)
        +'</b> 張，未比對到 <b style="color:#DD5138;">'+nf(s.unmatched)+'</b> 列；系統待回填 '+nf(r.pending)+' 張');
      renderBf(r);
    })
    .fail(function(x){
      $b.prop('disabled',false).html('<i class="fa fa-search"></i> 比對');
      var m='比對失敗'; try{ m=JSON.parse(x.responseText).error||m; }catch(e){}
      toast(esc(m), true);
    });
  });

  function renderBf(r){
    var h='';
    if(bfMatched.length){
      h+='<table class="a-t"><thead><tr><th style="width:32px;"><input type="checkbox" id="bfAll" checked></th>'
        +'<th>CSV列</th><th>發票號碼</th><th>開立日期</th><th>客戶</th><th>帳款月份</th>'
        +'<th>系統含稅</th><th>CSV含稅</th></tr></thead><tbody>';
      bfMatched.forEach(function(m,i){
        var bad=(!m.no_ok||!m.date_ok);
        h+='<tr'+(bad?' style="color:#DD5138;"':'')+'>'
          +'<td><input type="checkbox" class="bf-ck" data-i="'+i+'"'+(bad?'':' checked')+'></td>'
          +'<td>'+m.row+'</td><td><b>'+esc(m.invoice_no)+'</b>'+(m.no_ok?'':' ✗格式')+'</td>'
          +'<td>'+esc(m.invoice_date||'')+(m.date_ok?'':' ✗日期')+'</td>'
          +'<td class="l">'+esc(m.customer)+'</td><td>'+esc(m.billing_month)+'</td>'
          +'<td class="r">'+nf(m.total_amount)+'</td>'
          +'<td class="r">'+(m.csv_amount>0?nf(m.csv_amount):'—')+'</td></tr>';
      });
      h+='</tbody></table>';
    } else {
      h+='<div style="padding:12px;color:#8a6d45;">沒有比對到任何待回填的發票。</div>';
    }
    var un=r.unmatched||[];
    if(un.length){
      h+='<div style="margin-top:10px;"><div style="font-size:13px;color:#DD5138;margin-bottom:4px;">'
        +'以下 '+nf(un.length)+' 列比對不到系統中待回填的發票（不會寫入）：</div>'
        +'<table class="a-t"><thead><tr><th>CSV列</th><th>發票號碼</th><th>日期</th><th>買方統編</th><th>含稅金額</th></tr></thead><tbody>';
      un.slice(0,100).forEach(function(u){
        h+='<tr><td>'+u.row+'</td><td>'+esc(u.invoice_no)+'</td><td>'+esc(u.invoice_date)
          +'</td><td>'+esc(u.tax_id)+'</td><td class="r">'+(u.amount>0?nf(u.amount):'—')+'</td></tr>';
      });
      h+='</tbody></table>'+(un.length>100?'<div class="a-hint">（只顯示前 100 列）</div>':'')+'</div>';
    }
    $('#bfResult').html(h);
    updateBfSel();
  }
  $(document).on('change','#bfAll',function(){ $('.bf-ck').prop('checked',this.checked); updateBfSel(); });
  $(document).on('change','.bf-ck', updateBfSel);
  function updateBfSel(){
    var n=$('.bf-ck:checked').length;
    $('#bfSel').text('已勾選 '+n+' 張');
    $('#btnBfApply').prop('disabled', n===0);
  }
  $('#btnBfApply').on('click',function(){
    var pairs=[];
    $('.bf-ck:checked').each(function(){
      var m=bfMatched[parseInt($(this).data('i'),10)];
      if(m) pairs.push({invoice_id:m.invoice_id, invoice_no:m.invoice_no, invoice_date:m.invoice_date});
    });
    if(!pairs.length) return;
    var $b=$(this).prop('disabled',true).html('<i class="fa fa-spinner fa-spin"></i> 回填中…');
    $.post(API+'?action=inv_backfill',{csrf:CSRF, pairs:JSON.stringify(pairs)},function(r){
      $b.prop('disabled',false).html('<i class="fa fa-check"></i> 回填勾選的發票號碼');
      if(!r.ok){ toast(esc(r.error||'回填失敗'), true); return; }
      closeMask('mkBf'); toast(esc(r.message));
      if(r.errors && r.errors.length) toast(r.errors.map(esc).join('<br>'), true);
      loadInv();
    },'json').fail(function(){ $b.prop('disabled',false).html('<i class="fa fa-check"></i> 回填勾選的發票號碼');
      toast('回填失敗', true); });
  });

  /* ══ 折讓單 ══ */
  $('#btnAllowance').on('click',function(){
    alData=null; $('#alBox').empty(); $('#alSel').text('');
    $('#btnAlCreate').prop('disabled',true);
    // 若清單中有選一張已開立發票，帶入其客戶
    var pre='';
    Object.keys(invSel).forEach(function(id){
      invRows.forEach(function(v){ if(String(v.invoice_id)===String(id) && v.status==='issued') pre=v.customer_name; });
    });
    $('#alCust').val(pre);
    openMask('mkAllow');
    if(pre) $('#btnAlLoad').click();
  });
  $('#alCust').on('keydown',function(e){ if(e.key==='Enter'){ e.preventDefault(); $('#btnAlLoad').click(); } });
  $('#btnAlLoad').on('click',function(){
    var cn=$.trim($('#alCust').val());
    if(!cn){ toast('請輸入客戶簡稱', true); return; }
    $('#alBox').html('<div style="padding:12px;color:#8a6d45;">查詢中…</div>');
    $.post(API+'?action=allow_options',{customer:cn},function(r){
      if(!r.ok){ toast(esc(r.error||'查詢失敗'), true); return; }
      alData=r;
      if(!r.invoices.length){
        $('#alBox').html('<div class="warn">此客戶目前沒有「已開立」的發票，無法開折讓單。'
          +'折讓單必須掛在一張已開立的發票之下。</div>');
        updateAlSel(); return;
      }
      if(!r.returns.length){
        $('#alBox').html('<div class="warn">此客戶沒有尚未入帳的退貨單。'
          +'（同期退貨已在發票中扣除，或已開過折讓單）</div>');
        updateAlSel(); return;
      }
      var h='<div style="font-size:13px;color:#5b3a1e;margin-bottom:5px;">選擇原發票：</div>'
        +'<select id="alInv" style="width:100%;height:32px;border:1px solid #D8BE93;border-radius:4px;">';
      r.invoices.forEach(function(v){
        h+='<option value="'+v.invoice_id+'">'+esc(v.invoice_no)+'　'+esc(v.invoice_date)
          +'　帳款月 '+esc(v.billing_month)+'　含稅 '+nf(v.total_amount)+'</option>';
      });
      h+='</select>';
      h+='<div style="font-size:13px;color:#5b3a1e;margin:10px 0 5px;">勾選要折讓的退貨單：</div>'
        +'<table class="a-t"><thead><tr><th style="width:32px;"><input type="checkbox" id="alAll"></th>'
        +'<th>退貨單號</th><th>日期</th><th>料號</th><th>品名規格</th><th>數量</th><th>單價</th><th>金額</th><th>退貨原因</th>'
        +'</tr></thead><tbody>';
      r.returns.forEach(function(x,i){
        h+='<tr><td><input type="checkbox" class="al-ck" data-i="'+i+'"></td>'
          +'<td>'+esc(x.IR_no)+'</td><td>'+esc(x.d)+'</td><td class="l">'+esc(x.d_id)+'</td>'
          +'<td class="l">'+esc((x.Specification||'').substr(0,22))+'</td>'
          +'<td class="r">'+nf(x.Qty)+'</td><td class="r">'+np(x.Unit_price)+'</td>'
          +'<td class="r"><b>'+nf(x.amount)+'</b></td>'
          +'<td class="l" style="font-size:11.5px;">'+esc((x.IR_ps||'').substr(0,24))+'</td></tr>';
      });
      $('#alBox').html(h+'</tbody></table>');
      updateAlSel();
    },'json').fail(function(){ toast('查詢失敗', true); });
  });
  $(document).on('change','#alAll',function(){ $('.al-ck').prop('checked',this.checked); updateAlSel(); });
  $(document).on('change','.al-ck', updateAlSel);
  function updateAlSel(){
    var n=$('.al-ck:checked').length, amt=0;
    $('.al-ck:checked').each(function(){
      var x=alData.returns[parseInt($(this).data('i'),10)];
      if(x) amt+=Number(x.amount)||0;
    });
    var tax=Math.round(amt*TAX_RATE);
    $('#alSel').html(n? '已選 '+n+' 筆　折讓未稅 <b>'+nf(amt)+'</b>　稅 <b>'+nf(tax)
      +'</b>　含稅 <b style="color:#DD5138;">'+nf(amt+tax)+'</b>' : '');
    $('#btnAlCreate').prop('disabled', n===0);
  }
  $('#btnAlCreate').on('click',function(){
    var ids=[];
    $('.al-ck:checked').each(function(){
      var x=alData.returns[parseInt($(this).data('i'),10)];
      if(x) ids.push(Number(x.IR_id));
    });
    if(!ids.length) return;
    var refId=$('#alInv').val();
    if(!refId){ toast('請選擇原發票', true); return; }
    var $b=$(this).prop('disabled',true).html('<i class="fa fa-spinner fa-spin"></i> 建立中…');
    $.post(API+'?action=allow_create',
      {csrf:CSRF, ref_invoice_id:refId, ir_ids:JSON.stringify(ids)},
      function(r){
        $b.prop('disabled',false).html('<i class="fa fa-check"></i> 建立折讓單');
        if(!r.ok){ toast(esc(r.error||'建立失敗'), true); return; }
        closeMask('mkAllow'); toast('<b>'+esc(r.message)+'</b>');
        if(r.errors && r.errors.length) toast(r.errors.map(esc).join('<br>'), true);
        loadInv();
      },'json').fail(function(x){
        $b.prop('disabled',false).html('<i class="fa fa-check"></i> 建立折讓單');
        var m='建立失敗'; try{ m=JSON.parse(x.responseText).error||m; }catch(e){}
        toast(esc(m), true);
      });
  });
}
})(jQuery);
</script>
</body>
</html>
