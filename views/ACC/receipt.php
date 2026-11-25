<?php
/**
 * views/ACC/receipt.php — 收款與沖帳（會計模組第四步）
 *
 * 彈性沖帳：一筆收款可沖多張發票、一張發票可被多筆收款分次沖，支援部分收款與尾款。
 * 沒沖完的部分視為「暫收款」，會列在未分配欄位提醒。折讓單以負數參與抵扣。
 *
 * 三道守門（後端 acc_alloc_save 內）：
 *   1) 沖帳總額不可超過收款金額
 *   2) 單張發票不可超過其未收餘額
 *   3) 發票客戶必須與收款單客戶一致
 *
 * 資料一律走 src/store/Acc_API.php；權限 acc_lib.php（roles module='accounting'）
 */
session_start();
if (!isset($_SESSION['userName'])) {
    $_SESSION['lastpage'] = "../../views/ACC/receipt.php";
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
<title>收款與沖帳</title>
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
      <h2 style="margin:6px 0;"><i class="fa fa-money" style="color:#F0A24B;"></i> 收款與沖帳
        <small style="color:#8a6d45;">一筆收款可沖多張發票、一張發票可分次收；沒沖完的部分視為暫收款</small></h2>
    </div>
    <div class="clearfix"></div>

<?php if (!$perms['canView']): ?>
    <div class="a-noperm">
      <h4><i class="fa fa-lock"></i> 無會計模組檢閱權限</h4>
      <p>請洽管理者於「使用者權限設定」指派「會計檢閱／會計登錄／會計管理員」角色。</p>
    </div>
<?php else: ?>
    <div class="a-tabs">
      <button class="tab-btn on" data-tab="due"><i class="fa fa-clock-o"></i> 1. 待收款（請款日期／應入帳日期）</button>
      <button class="tab-btn" data-tab="rc"><i class="fa fa-money"></i> 2. 收款單與沖帳</button>
    </div>

    <div id="paneDue">
      <div class="a-bar">
        <input type="text" id="dueKw" placeholder="客戶／發票號碼" style="width:200px;">
        <label><input type="checkbox" id="dueOver"> 只看已逾期</label>
        <button id="btnDueLoad" class="btn-warm"><i class="fa fa-search"></i> 查詢</button>
        <?php if ($perms['canEdit']): ?>
        <button id="btnDueRecv" disabled><i class="fa fa-money"></i> 登錄收款（勾選的發票）</button>
        <?php endif; ?>
        <span id="dueSel" style="font-size:13px;color:#8a6d45;"></span>
      </div>
      <div class="a-stat">
        <div><span class="n" id="dueCnt">—</span> <span class="l">張未收發票</span></div>
        <div><span class="n big" id="dueOpen">—</span> <span class="l">未收餘額</span></div>
        <div><span class="n bad" id="dueOverAmt">—</span> <span class="l">已逾期金額</span></div>
        <div><span class="n bad" id="dueOverCnt">—</span> <span class="l">逾期張數</span></div>
      </div>
      <div class="a-wrap" style="max-height:62vh;overflow:auto;">
        <table class="a-t"><thead><tr><th style="width:30px;"><input type="checkbox" id="dueAll"></th><th>客戶</th><th>發票號碼</th><th>發票日期</th>
          <th>帳款月份</th><th>請款日期</th><th>傳票號碼</th><th>收帳條件</th><th>應入帳日期</th><th>逾期</th><th>含稅金額</th><th>未收餘額</th></tr></thead>
          <tbody id="dueBody"><tr><td colspan="12" style="padding:22px;color:#8a6d45;">載入中…</td></tr></tbody></table>
      </div>
      <div class="a-hint">
        <b>請款日期</b>＝該發票明細所屬傳票的傳票日期（還沒開傳票則空白）。
        <b>應入帳日期</b>依客戶主檔的收帳設定自動推算：「依天數」＝發票日期＋天數；「月結／次月結」＝帳款月份月底＋天數（次月結已含 30 天）。
        客戶沒填收帳天數時顯示「未設定」，請到主檔管理補齊。
      </div>
    </div>

    <div id="paneRc" style="display:none;">
    <div class="a-bar">
      <label>入帳日</label>
      <input type="date" id="dFrom" style="width:145px;"> ~ <input type="date" id="dTo" style="width:145px;">
      <input type="text" id="kw" placeholder="收款單號／客戶／銀行／支票號" style="width:220px;">
      <label><input type="checkbox" id="onlyUn"> 只看有暫收款的</label>
      <button id="btnSearch" class="btn-warm"><i class="fa fa-search"></i> 查詢</button>
      <span class="a-role">目前角色：<b><?= htmlspecialchars($roleLbl) ?></b>
        <i class="fa fa-question-circle" id="btnRoleHelp" title="角色權限說明"></i></span>
    </div>

    <div class="a-bar" style="background:var(--a-bg2);">
      <?php if ($perms['canEdit']): ?>
      <button id="btnNew" class="btn-warm"><i class="fa fa-plus"></i> 登錄收款</button>
      <?php endif; ?>
      <button id="btnExport"><i class="fa fa-file-text-o"></i> 匯出CSV</button>
      <button id="btnPrint"><i class="fa fa-print"></i> 列印／PDF</button>
      <a href="invoice_export.php" style="text-decoration:none;">
        <button type="button"><i class="fa fa-file-text-o"></i> 發票開立與轉出</button></a>
      <a href="voucher.php" style="text-decoration:none;">
        <button type="button"><i class="fa fa-book"></i> 傳票管理</button></a>
      <a href="ar_statement.php" style="text-decoration:none;">
        <button type="button"><i class="fa fa-calculator"></i> 應收對帳單</button></a>
    </div>

    <div class="a-stat">
      <div><span class="n" id="stCount">—</span> <span class="l">筆收款</span></div>
      <div class="sep"></div>
      <div><span class="n big" id="stAmount">—</span> <span class="l">收款總額</span></div>
      <div><span class="n" id="stAlloc">—</span> <span class="l">已沖帳</span></div>
      <div><span class="n bad" id="stUnalloc">—</span> <span class="l">暫收款（未分配）</span></div>
      <div><span class="n" id="stFee">—</span> <span class="l">手續費</span></div>
    </div>

    <div class="a-pager">
      <span id="pgInfo" style="margin-right:auto;color:#8a6d45;"></span>
      每頁 <select id="perPage"><option>5</option><option>10</option><option selected>20</option><option>50</option></select> 筆
      <span id="pgBtns"></span>
    </div>

    <div class="a-wrap">
      <table class="a-t" id="tbl">
        <thead><tr>
          <th>收款單號</th><th>入帳日</th><th>客戶</th><th>方式</th>
          <th>收款金額</th><th>手續費</th><th>已沖帳</th><th>暫收款</th><th>狀態</th>
          <th>銀行／票號</th><th>票期</th><th>備註</th><th>操作</th>
        </tr></thead>
        <tbody id="tbody"><tr><td colspan="13" style="padding:22px;color:#8a6d45;">載入中…</td></tr></tbody>
      </table>
    </div>
    <div class="a-hint">
      「暫收款」＝收了錢但還沒指定要沖哪張發票的金額，點<b>沖帳</b>去分配。
      沖帳時系統會擋下三種錯誤：沖帳總額超過收款金額、單張發票超過其未收餘額、發票客戶與收款單客戶不符。
      折讓單會以負數出現在可沖清單中（等於減少應收）。
    </div>
    </div>
<?php endif; ?>
  </div>
  <?php include '../partPage/footer.html' ?>
</div>
</div>

<?php if ($perms['canEdit']): ?>
<!-- 收款單編輯 -->
<div class="a-mask" id="mkRc"><div class="a-modal narrow">
  <div class="m-head"><i class="fa fa-money"></i>&nbsp;<span id="rcTitle">登錄收款</span>
    <span class="m-close" data-close="mkRc">✕</span></div>
  <div class="m-body">
    <div class="frm">
      <label>客戶 <span style="color:#DD5138;">*</span></label>
      <div class="full">
        <input type="text" id="rcCust" list="custList" placeholder="輸入或選擇客戶簡稱">
        <datalist id="custList"></datalist>
      </div>
      <label>入帳日 <span style="color:#DD5138;">*</span></label>
      <input type="date" id="rcDate">
      <label>收款方式 <span style="color:#DD5138;">*</span></label>
      <select id="rcMethod"><option>匯款</option><option>支票</option><option>現金</option><option>其他</option></select>
      <div class="rc-hint" id="rcTerms"></div>
      <label>應收金額</label>
      <input type="number" id="rcDue" step="0.01" min="0" data-eg-hint="沖帳對象的未收合計；填了之後實收會自動算">
      <label class="rc-deduct">扣 %</label>
      <input type="number" id="rcPct" step="0.001" min="0" max="100" class="rc-deduct" data-eg-hint="客戶現金／票據扣款百分比">
      <label class="rc-deduct">扣款金額</label>
      <input type="number" id="rcDed" step="0.01" min="0" value="0" class="rc-deduct">
      <label>手續費（匯費）</label>
      <input type="number" id="rcFee" step="0.01" min="0" value="0">
      <label>實收金額 <span style="color:#DD5138;">*</span></label>
      <input type="number" id="rcAmount" step="0.01" min="0">
      <span></span><span></span>
      <div class="sub rc-remit">匯款資料</div>
      <label class="rc-remit">匯入帳號 <span style="color:#DD5138;">*</span></label>
      <input type="text" id="rcAcct" class="rc-remit" maxlength="40">
      <label class="rc-remit rc-bank">銀行</label>
      <input type="text" id="rcBank" class="rc-remit rc-bank">
      <div class="sub rc-check">支票資料</div>
      <label class="rc-check">支票號碼 <span style="color:#DD5138;">*</span></label>
      <input type="text" id="rcCheckNo" class="rc-check" maxlength="30">
      <label class="rc-check">票期（到期日） <span style="color:#DD5138;">*</span></label>
      <input type="date" id="rcCheckDue" class="rc-check">
      <label class="rc-check">開票銀行</label>
      <input type="text" id="rcBank2" class="rc-check" maxlength="50">
      <span class="rc-check"></span>
      <label>備註</label>
      <input type="text" id="rcNote" class="full" maxlength="200">
    </div>
    <div class="a-hint" style="margin-top:8px;">
      存檔後可接著做沖帳。實收金額＝應收金額－扣款－手續費；客戶扣款與手續費視為已收，沖帳時可一併沖掉（不會留零頭）。
    </div>
  </div>
  <div class="m-foot">
    <button data-close="mkRc">取消</button>
    <button class="go" id="btnRcSave"><i class="fa fa-check"></i> 存檔</button>
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
      <span id="alTally" style="margin-left:auto;font-size:13px;color:#5b3a1e;"></span>
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
  <div class="m-body" style="font-size:13.5px;color:#5b3a1e;line-height:1.9;">
    <b>管理者</b>：固定擁有全部權限。<br>
    <b>會計管理員</b>：會計登錄的全部權限，另可<b>刪除收款單</b>。<br>
    <b>會計登錄</b>：可登錄收款、執行沖帳。<br>
    <b>會計檢閱</b>：僅可查詢與匯出。<br>
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

var CSRF = '', rows = [], page = 1, perPage = 20, total = 0;
var alData = null;

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
function qs(o){ return Object.keys(o).map(function(k){ return k+'='+encodeURIComponent(o[k]); }).join('&'); }

/* UI 規則：有值雙擊清空、聚焦全選、Enter 跳下一欄 */
function bindRules($s){
  $s.find('input[type=text],input[type=date],input[type=number]').off('.rr')
    .on('focus.rr',function(){ if(this.value!=='') try{ this.select(); }catch(e){} })
    .on('dblclick.rr',function(){ if(this.value!==''){ this.value=''; $(this).trigger('change'); } })
    .on('keydown.rr',function(e){
      if(e.key==='Enter'){
        e.preventDefault();
        var $f=$s.find('input,select').filter(':visible:not([disabled])');
        var i=$f.index(this);
        if(i>=0 && i<$f.length-1) $f.eq(i+1).focus(); else $s.closest('.a-modal').find('.m-foot .go').click();
      }
    });
}

/* ══ 分頁與待收款清單 ══ */
$('.tab-btn').on('click',function(){
  var t=$(this).data('tab'); $('.tab-btn').removeClass('on'); $(this).addClass('on');
  $('#paneDue').toggle(t==='due'); $('#paneRc').toggle(t==='rc');
  if(t==='due') loadDue(); else if(typeof load==='function') load();
});
var dueRows=[], dueSel={};
function dd(s){ return s?String(s).replace(/-/g,'.'):''; }
/* 沖帳上限：新版收款單（settle_extra=1）含客戶扣款與匯費；舊收款單＝收款金額 */
function rcCap(rc){ var a=Number(rc.amount)||0; return Number(rc.settle_extra)? a+(Number(rc.deduct_amt)||0)+(Number(rc.fee)||0) : a; }
function loadDue(){
  $('#dueBody').html('<tr><td colspan="12" style="padding:22px;color:#8a6d45;">查詢中…</td></tr>');
  $.post(API+'?action=rcpt_due_list',{kw:$('#dueKw').val(),only_overdue:$('#dueOver').is(':checked')?'1':''},function(r){
    if(!r.ok){ toast(esc(r.error||'查詢失敗'), true); return; }
    dueRows=r.rows||[]; dueSel={};
    var s=r.summary||{};
    $('#dueCnt').text(nf(s.count)); $('#dueOpen').text(nf(s.open)); $('#dueOverAmt').text(nf(s.overdue)); $('#dueOverCnt').text(nf(s.overdue_cnt));
    var h='';
    if(!dueRows.length) h='<tr><td colspan="12" style="padding:22px;color:#8a6d45;">沒有未收款的已開立發票</td></tr>';
    dueRows.forEach(function(v,i){
      h+='<tr data-i="'+i+'"><td><input type="checkbox" class="dck"></td><td class="l">'+esc(v.customer_name)+'</td>'
       +'<td><b>'+esc(v.invoice_no||'')+'</b></td><td>'+dd(v.invoice_date)+'</td><td>'+esc(v.billing_month||'')+'</td>'
       +'<td>'+(v.request_date?dd(v.request_date):'<span style="color:#b09a86;">未開傳票</span>')+'</td>'
       +'<td>'+esc(v.voucher_no||'')+'</td><td class="l" style="font-size:12px;">'+esc(v.terms_text)+(v.allow_deduct?' ／接受扣%':'')+'</td>'
       +'<td>'+(v.due_date?'<b>'+dd(v.due_date)+'</b>':'<span style="color:#b09a86;">未設定</span>')+'</td>'
       +'<td>'+(v.overdue_days>0?'<span class="od">逾期 '+v.overdue_days+' 天</span>':'')+'</td>'
       +'<td class="r">'+nf(v.total_amount)+'</td><td class="r"><b>'+nf(v.open)+'</b></td></tr>';
    });
    $('#dueBody').html(h); updDue();
  },'json').fail(function(){ toast('查詢失敗', true); });
}
function updDue(){
  var k=Object.keys(dueSel), amt=0, cust={};
  k.forEach(function(i){ amt+=dueRows[i].open; cust[dueRows[i].customer_name]=1; });
  var nc=Object.keys(cust).length;
  $('#dueSel').text(k.length?('已選 '+k.length+' 張、'+nf(amt)+' 元'+(nc>1?'（跨 '+nc+' 個客戶，一次只能收一個客戶）':'')):'');
  $('#btnDueRecv').prop('disabled', !k.length || nc!==1);
}
$(document).on('change','.dck',function(){
  var i=$(this).closest('tr').data('i'); if(this.checked) dueSel[i]=1; else delete dueSel[i]; updDue();
});
$('#dueAll').on('change',function(){
  var on=this.checked; $('#dueBody .dck').each(function(){ $(this).prop('checked',on); var i=$(this).closest('tr').data('i'); if(on) dueSel[i]=1; else delete dueSel[i]; }); updDue();
});
$('#btnDueLoad').on('click',loadDue);
$('#dueKw').on('keydown',function(e){ if(e.key==='Enter'){ e.preventDefault(); loadDue(); } });
$('#dueOver').on('change',loadDue);

/* 預設近三個月 */
(function(){
  var t=new Date(), f=new Date(); f.setMonth(f.getMonth()-3);
  $('#dTo').val(t.toISOString().slice(0,10));
  $('#dFrom').val(f.toISOString().slice(0,10));
})();

$.getJSON(API,{action:'meta'},function(r){
  if(!r.ok){ toast(esc(r.error||'初始化失敗'), true); return; }
  CSRF=r.csrf;
  $.getJSON(API,{action:'rcpt_customers'},function(cr){
    if(cr.ok) $('#custList').html((cr.customers||[]).map(function(c){
      return '<option value="'+esc(c.customer_name)+'">'; }).join(''));
  });
  loadDue();
}).fail(function(){ toast('無法連線到會計 API', true); });

function filters(){
  return {date_from:$('#dFrom').val(), date_to:$('#dTo').val(), kw:$('#kw').val(),
          only_unalloc:$('#onlyUn').is(':checked')?1:0, page:page, per_page:perPage};
}

function load(){
  $('#tbody').html('<tr><td colspan="13" style="padding:22px;color:#8a6d45;">查詢中…</td></tr>');
  $.post(API+'?action=rcpt_list', filters(), function(r){
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
    $('#tbody').html('<tr><td colspan="13" style="padding:22px;color:#8a6d45;">'
      +'此條件下沒有收款紀錄。'+(CAN_EDIT?'按「登錄收款」新增。':'')+'</td></tr>');
    return;
  }
  var h='';
  rows.forEach(function(v,i){
    var st = (v.unallocated<=0.005) ? '<span class="pill p-full">已全數沖帳</span>'
           : (v.allocated>0.005 ? '<span class="pill p-part">部分沖帳</span>'
                                : '<span class="pill p-none">未沖帳</span>');
    h+='<tr data-i="'+i+'" data-id="'+v.receipt_id+'">'
      +'<td><b>'+esc(v.receipt_no)+'</b></td>'
      +'<td>'+esc(v.receipt_date)+'</td>'
      +'<td class="l">'+esc(v.customer_name)+'</td>'
      +'<td>'+esc(v.method||'')+'</td>'
      +'<td class="r"><b>'+nf(v.amount)+'</b></td>'
      +'<td class="r">'+(v.fee>0?nf(v.fee):'')+'</td>'
      +'<td class="r">'+nf(v.allocated)+'</td>'
      +'<td class="r">'+(v.unallocated>0.005?'<b style="color:#DD5138;">'+nf(v.unallocated)+'</b>':'')+'</td>'
      +'<td>'+st+'</td>'
      +'<td class="l">'+esc(v.bank||'')+(v.check_no?' / '+esc(v.check_no):'')+(v.remit_account?' / 帳號 '+esc(v.remit_account):'')
        +(Number(v.deduct_amt)>0?' <span class="od">扣'+nf(v.deduct_amt)+'</span>':'')+'</td>'
      +'<td>'+esc(v.check_due||'')+'</td>'
      +'<td class="l" style="font-size:11.5px;">'+esc((v.note||'').substr(0,18))+'</td>'
      +'<td>'
        +(CAN_EDIT?'<button class="btn-mini rc-alloc" data-id="'+v.receipt_id+'">沖帳'
          +(v.alloc_cnt?'('+v.alloc_cnt+')':'')+'</button> '
          +'<button class="btn-mini rc-edit" data-i="'+i+'">修改</button> ':'')
        +(CAN_ADMIN?'<button class="btn-mini rc-del" data-id="'+v.receipt_id+'">刪除</button>':'')
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
  window.location = API+'?action=rcpt_export&'+qs(f);
});
$('#btnPrint').on('click',function(){ window.print(); });

/* ══ 收款單編輯 ══ */
if(CAN_EDIT){
  var editId = 0;
  var rcLegacy=false;
  function openRc(v, pre){
    editId = v ? Number(v.receipt_id) : 0;
    $('#rcTitle').text(v ? ('修改收款單 '+v.receipt_no) : '登錄收款');
    $('#rcCust').val(v?v.customer_name:'');
    $('#rcDate').val(v?v.receipt_date:new Date().toISOString().slice(0,10));
    pre = pre || {};
    var legacy = v && !Number(v.settle_extra);   // 舊收款單：沒有應收／扣款欄位，不強迫改成新規則
    $('#rcMethod').val(v?(v.method||'匯款'):'匯款');
    $('#rcDue').val(v?(v.due_amount||''):(pre.due||''));
    $('#rcPct').val(v?(v.deduct_pct||''):'');
    $('#rcDed').val(v?(v.deduct_amt||0):0);
    $('#rcFee').val(v?v.fee:0);
    $('#rcAmount').val(v?v.amount:'');
    $('#rcAcct').val(v?(v.remit_account||''):'');
    $('#rcBank').val(v?(v.bank||''):''); $('#rcBank2').val(v?(v.bank||''):'');
    $('#rcCheckNo').val(v?(v.check_no||''):'');
    $('#rcCheckDue').val(v?(v.check_due||''):'');
    $('#rcNote').val(v?(v.note||''):(pre.note||''));
    if(pre.customer) $('#rcCust').val(pre.customer);
    if(pre.due) rcCalc();
    rcLegacy=legacy; rcShow(); rcTerms();
    bindRules($('#mkRc'));
    openMask('mkRc');
    setTimeout(function(){ $('#rcCust').focus(); },80);
  }
  $('#btnNew').on('click',function(){ openRc(null); });
  $('#btnDueRecv').on('click',function(){
    var k=Object.keys(dueSel); if(!k.length) return;
    var amt=0, nos=[]; k.forEach(function(i){ amt+=dueRows[i].open; nos.push(dueRows[i].invoice_no); });
    openRc(null,{customer:dueRows[k[0]].customer_name, due:Math.round(amt*100)/100, note:'發票 '+nos.join('、').substr(0,150)});
  });

  /* 入帳類型：不同類型顯示不同必填資料；扣%與匯費自動算實收 */
  function rcShow(){
    var m=$('#rcMethod').val();
    $('.rc-remit').toggleClass('hide', m!=='匯款');
    $('.rc-check').toggleClass('hide', m!=='支票');
    $('.rc-deduct').toggleClass('hide', !(m==='支票'||m==='現金'));
    if(m!=='支票'&&m!=='現金'){ $('#rcPct').val(''); $('#rcDed').val(0); }
  }
  function rcCalc(){
    var due=parseFloat($('#rcDue').val());
    if(isNaN(due)) return;
    var ded=parseFloat($('#rcDed').val())||0;
    var fee=parseFloat($('#rcFee').val())||0;
    $('#rcAmount').val(Math.round((due-ded-fee)*100)/100);
  }
  function rcTerms(){
    var c=$.trim($('#rcCust').val()); if(!c){ $('#rcTerms').text(''); return; }
    $.post(API+'?action=rcpt_opts',{customer:c},function(r){
      if(!r.ok||!r.terms){ $('#rcTerms').text(''); return; }
      $('#rcTerms').html('收帳條件：'+esc(r.terms.terms_text)+(Number(r.terms.allow_deduct)?'　<b>此客戶接受扣%</b>':'　（主檔未勾選接受扣%）')
        +(r.terms.payment_methods?'　常用收款方式：'+esc(r.terms.payment_methods):''));
    },'json');
  }
  $('#rcMethod').on('change',function(){ rcShow(); rcCalc(); });
  /* 扣%一變（或應收一變）就重算扣款金額；直接改扣款金額則以金額為準（扣%留著當參考） */
  function rcPctToDed(){
    var due=parseFloat($('#rcDue').val()), pct=parseFloat($('#rcPct').val());
    if(!isNaN(due) && !isNaN(pct)) $('#rcDed').val(Math.round(due*pct)/100);
    rcCalc();
  }
  $('#rcPct,#rcDue').on('input',rcPctToDed);
  $('#rcDed,#rcFee').on('input',rcCalc);
  $('#rcCust').on('change',rcTerms);
  $.getJSON(API,{action:'rcpt_opts'},function(r){
    if(r.ok&&r.methods) $('#rcMethod').html(r.methods.map(function(m){ return '<option>'+esc(m)+'</option>'; }).join(''));
  });
  $(document).on('click','.rc-edit',function(){ openRc(rows[parseInt($(this).data('i'),10)]); });

  $('#btnRcSave').on('click',function(){
    var m=$('#rcMethod').val();
    var d={receipt_id:editId, customer_name:$.trim($('#rcCust').val()),
           receipt_date:$('#rcDate').val(), method:m,
           amount:parseFloat($('#rcAmount').val())||0, fee:parseFloat($('#rcFee').val())||0,
           bank:$.trim(m==='支票'?$('#rcBank2').val():$('#rcBank').val()),
           check_no:m==='支票'?$.trim($('#rcCheckNo').val()):'',
           check_due:m==='支票'?$('#rcCheckDue').val():'',
           remit_account:m==='匯款'?$.trim($('#rcAcct').val()):'',
           due_amount:$('#rcDue').val(), deduct_pct:$('#rcPct').val(), deduct_amt:parseFloat($('#rcDed').val())||0,
           note:$.trim($('#rcNote').val())};
    if(!rcLegacy && m==='匯款' && !d.remit_account){ toast('匯款需填「匯入帳號」', true); $('#rcAcct').focus(); return; }
    if(!rcLegacy && m==='支票' && !d.check_no){ toast('支票需填「支票號碼」', true); $('#rcCheckNo').focus(); return; }
    if(!rcLegacy && m==='支票' && !d.check_due){ toast('支票需填「票期」', true); $('#rcCheckDue').focus(); return; }
    if(!rcLegacy && d.due_amount!=='' && Math.abs((parseFloat(d.due_amount)-d.deduct_amt-d.fee)-d.amount)>0.5){
      toast('金額不符：應收 － 扣款 － 匯費 必須等於實收', true); $('#rcAmount').focus(); return; }
    if(!d.customer_name){ toast('請填客戶', true); $('#rcCust').focus(); return; }
    if(!d.receipt_date){ toast('請填入帳日', true); return; }
    if(!(d.amount>0)){ toast('收款金額必須大於 0', true); $('#rcAmount').focus(); return; }
    var $b=$(this).prop('disabled',true).html('<i class="fa fa-spinner fa-spin"></i> 存檔中…');
    $.post(API+'?action=rcpt_save',{csrf:CSRF, data:JSON.stringify(d)},function(r){
      $b.prop('disabled',false).html('<i class="fa fa-check"></i> 存檔');
      if(!r.ok){ toast(esc(r.error||'存檔失敗'), true); return; }
      closeMask('mkRc'); toast(esc(r.message));
      load();
      // 新建立的收款單直接接著開沖帳畫面
      if(!editId && r.receipt_id) setTimeout(function(){ openAlloc(r.receipt_id); },400);
    },'json').fail(function(x){
      $b.prop('disabled',false).html('<i class="fa fa-check"></i> 存檔');
      var m='存檔失敗'; try{ m=JSON.parse(x.responseText).error||m; }catch(e){}
      toast(esc(m), true);
    });
  });

  /* ══ 沖帳 ══ */
  function openAlloc(rid){
    $('#alHead').html(''); $('#alBox').html('<div style="padding:14px;color:#8a6d45;">載入中…</div>');
    $('#alTally').text('');
    openMask('mkAl');
    $.post(API+'?action=rcpt_alloc_options',{receipt_id:rid},function(r){
      if(!r.ok){ $('#alBox').html('<div class="warn">'+esc(r.error||'載入失敗')+'</div>'); return; }
      alData=r;
      var rc=r.receipt;
      $('#alTitle').text('沖帳　'+rc.receipt_no+'　'+rc.customer_name);
      $('#alHead').html('<div class="info">收款 <b>'+nf(rc.amount)+'</b> 元（'+esc(rc.receipt_date)
        +'　'+esc(rc.method||'')+'）　客戶 <b>'+esc(rc.customer_name)+'</b><br>'
        +'把金額填到下面各張發票的「本次沖帳」欄；沒填完的部分會留作暫收款。</div>');
      renderAlloc();
    },'json').fail(function(){ $('#alBox').html('<div class="warn">載入失敗</div>'); });
  }
  $(document).on('click','.rc-alloc',function(){ openAlloc($(this).data('id')); });

  function renderAlloc(){
    var invs=alData.invoices||[], allocs=alData.allocs||[];
    var cur={};
    allocs.forEach(function(a){ cur[a.invoice_id]=Number(a.amount)||0; });

    if(!invs.length){
      $('#alBox').html('<div class="warn">此客戶目前沒有可沖帳的已開立發票。'
        +'請先到「發票開立與轉出」把發票開立並回填發票號碼（狀態需為「已開立」）。</div>');
      updateTally(); return;
    }
    var h='<table class="a-t"><thead><tr><th>類型</th><th>發票號碼</th><th>開立日</th><th>帳款月份</th>'
        +'<th>含稅金額</th><th>已收(其他)</th><th>可沖額度</th><th>本次沖帳</th></tr></thead><tbody>';
    invs.forEach(function(v,i){
      var isAllow=(v.doc_type==='ALLOWANCE');
      var other=Number(v.paid)-Number(v.this_paid);
      h+='<tr><td>'+(isAllow?'<span class="pill dt-ALLOWANCE">折讓</span>':'發票')+'</td>'
        +'<td><b>'+esc(v.invoice_no||'#'+v.invoice_id)+'</b></td>'
        +'<td>'+esc(v.invoice_date||'')+'</td><td>'+esc(v.billing_month)+'</td>'
        +'<td class="r">'+nf(v.total_amount)+'</td>'
        +'<td class="r">'+(Math.abs(other)>0.005?nf(other):'')+'</td>'
        +'<td class="r"><b>'+nf(v.available)+'</b></td>'
        +'<td><input type="number" class="alloc-in" data-i="'+i+'" data-id="'+v.invoice_id+'" step="0.01" value="'
          +(cur[v.invoice_id]!==undefined?cur[v.invoice_id]:'')+'"></td></tr>';
    });
    $('#alBox').html(h+'</tbody></table>');
    updateTally();
  }

  function updateTally(){
    var rc=alData.receipt, sum=0, over=false;
    $('.alloc-in').each(function(){
      var v=parseFloat(this.value)||0;
      var i=parseInt($(this).data('i'),10);
      var av=Number((alData.invoices[i]||{}).available)||0;
      var bad = (av>=0) ? (v>av+0.005) : (v<av-0.005);
      $(this).toggleClass('over', bad);
      if(bad) over=true;
      sum+=v;
    });
    var left=Math.round((rcCap(rc)-sum)*100)/100;
    var msg='已分配 <b>'+nf(sum)+'</b>　／　可沖 '+nf(rcCap(rc))+(rcCap(rc)!==Number(rc.amount)?'（實收 '+nf(rc.amount)+'＋扣款／匯費 '+nf(rcCap(rc)-Number(rc.amount))+'）':'');
    if(left>0.005)      msg+='　／　暫收款 <b style="color:#DD5138;">'+nf(left)+'</b>';
    else if(left<-0.005) msg+='　／　<b style="color:#DD5138;">超過收款金額 '+nf(-left)+'</b>';
    $('#alTally').html(msg);
    $('#btnAlSave').prop('disabled', over || left<-0.005);
  }
  $(document).on('input','.alloc-in', updateTally);

  $('#btnAlAuto').on('click',function(){
    if(!alData) return;
    var left=rcCap(alData.receipt);
    // 先把折讓（負數額度）全部帶入，再由舊到新填發票
    $('.alloc-in').each(function(){
      var i=parseInt($(this).data('i'),10), av=Number(alData.invoices[i].available)||0;
      if(av<0){ this.value=av; left-=av; } else { this.value=''; }
    });
    $('.alloc-in').each(function(){
      var i=parseInt($(this).data('i'),10), av=Number(alData.invoices[i].available)||0;
      if(av<=0) return;
      if(left<=0.005) return;
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
      if(!isNaN(v) && Math.abs(v)>0.005) allocs.push({invoice_id:Number($(this).data('id')), amount:v});
    });
    var $b=$(this).prop('disabled',true).html('<i class="fa fa-spinner fa-spin"></i> 儲存中…');
    $.post(API+'?action=rcpt_alloc_save',
      {csrf:CSRF, receipt_id:alData.receipt.receipt_id, allocs:JSON.stringify(allocs)},
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

/* 刪除 */
if(CAN_ADMIN){
  $(document).on('click','.rc-del',function(){
    var id=$(this).data('id');
    if(!confirm('確定刪除這筆收款單？其沖帳明細會一併刪除，相關發票的未收餘額會回復。')) return;
    $.post(API+'?action=rcpt_delete',{csrf:CSRF, receipt_id:id},function(r){
      if(!r.ok){ toast(esc(r.error||'刪除失敗'), true); return; }
      toast(esc(r.message)); load();
    },'json').fail(function(){ toast('刪除失敗', true); });
  });
}
})(jQuery);
</script>
</body>
</html>
