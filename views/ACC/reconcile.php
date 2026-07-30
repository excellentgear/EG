<?php
/**
 * views/ACC/reconcile.php — 對帳作業（業務對應收、生管對應付）
 *
 * 實務分工：應收由業務對完帳給會計、應付由生管對完帳給會計。
 * 對帳時對方（客戶／廠商）常拿紙本來，且雙方單據切法不一致：
 *   - 我方分批送加工，廠商把它們併成一列請款  → 多項加總（勾多列→合併為一組）
 *   - 我方一次送，廠商拆批甚至拆月份請款      → 單項拆分（一列→拆成多段，可各自指定月份）
 * 這兩種都只影響「對帳底稿」，永不寫回原始出貨／加工紀錄；orig 值永久保留供稽核比對。
 *
 * 其他為了對帳速度做的事：點列即勾選並轉暖淺綠、可拖移排序成與紙本相同順序、
 * 旁邊掛計算機、可輸入對方紙本合計即時算差額、可暫存中斷後再繼續
 * （每個對象×月份只有一份暫存，確認送出後即成為已確認紀錄）。
 *
 * 鎖帳規則：按「確認正確」即鎖帳，涵蓋憑證標記已對完，之後不可改單也不可再暫存；
 * 僅會計管理員可退回重對且須填原因。已開立發票的憑證另有一層更硬的鎖
 * （不提供解鎖，只能作廢／折讓／補開），因為帳面要與國稅局申報一致。
 *
 * 資料一律走 src/store/Acc_API.php；權限 acc_lib.php（roles module='accounting'）
 */
session_start();
if (!isset($_SESSION['userName'])) {
    $_SESSION['lastpage'] = "../../views/ACC/reconcile.php";
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
$taxRate = acc_tax_rate($db);

/* 由對帳單總覽（recon_overview.php）帶參數直接開某一份：
   ?side=ap&party_id=RZ002A&bm=2026-05。沒帶參數就照原本的下拉流程走。 */
$deep = ['side' => '', 'party_id' => '', 'bm' => ''];
if (isset($_GET['side']) || isset($_GET['party_id']) || isset($_GET['bm'])) {
    $deep['side']     = (($_GET['side'] ?? '') === 'ap') ? 'ap' : 'ar';
    $deep['party_id'] = trim((string)($_GET['party_id'] ?? ''));
    $bm = trim((string)($_GET['bm'] ?? ''));
    $deep['bm'] = preg_match('/^\d{4}-\d{2}$/', $bm) ? $bm : '';
    if ($deep['party_id'] === '' || $deep['bm'] === '') $deep = ['side' => '', 'party_id' => '', 'bm' => ''];
}
?>
<!DOCTYPE html>
<html lang="zh-Hant">
<head>
<meta charset="utf-8">
<meta http-equiv="X-UA-Compatible" content="IE=edge">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>對帳作業</title>
<link href="../../resource/css/bootstrap.css" rel="stylesheet">
<link href="../../resource/css/font-awesome.css" rel="stylesheet">
<link href="../../resource/css/nprogress.css" rel="stylesheet">
<link href="../../resource/css/custom.css" rel="stylesheet">
<style>
/* 色系隨側別切換：應收＝琥珀橘（錢進來）、應付＝暖棕赭（錢出去），
   都在暖色系內但明顯可辨，另有文字與箭頭雙重標示。 */
:root{
  --a-line:#E8D5B5; --a-line2:#D8BE93; --a-bg:#FDF8EF; --a-bg2:#FFF7E8;
  --a-ink:#5b3a1e; --a-ink2:#8a6d45; --a-brand:#8A5A2B;
  --a-acc:#F0A24B; --a-acc-d:#d98a33; --a-ok:#F7E0BD; --a-bad:#DD5138;
  /* 已對到本筆的暖淺綠（偏橄欖／暖調，不是冷螢光綠） */
  --a-hit:#E4EDD4; --a-hit-bd:#BFCF9E;
}
body.side-ap{
  --a-line:#DFCBA9; --a-line2:#C9AE85; --a-bg:#FBF5EC; --a-bg2:#F6EBDA;
  --a-ink:#4E2C0B; --a-ink2:#7d6242; --a-brand:#7A4A1E;
  --a-acc:#A2703A; --a-acc-d:#8A5A2B; --a-ok:#E8D3B4; --a-bad:#C0392B;
}
#sidebar-menu{visibility:hidden;}
.right_col .page-title{margin:8px 0 4px;overflow:hidden;}

.side-band{display:flex;align-items:center;gap:10px;clear:both;margin-bottom:8px;color:#fff;
  border-radius:8px;padding:7px 14px;font-size:15px;box-shadow:inset 0 -3px 0 rgba(0,0,0,.14);
  background:linear-gradient(90deg,#F0A24B 0%,#E8912F 100%);}
body.side-ap .side-band{background:linear-gradient(90deg,#8A5A2B 0%,#6E4520 100%);}
.side-band .sb-sub{font-size:12.5px;opacity:.92;font-weight:normal;}
.side-band .sb-sw{margin-left:auto;display:flex;gap:6px;}
.side-band .sb-sw button{font-size:13px;color:#fff;background:rgba(255,255,255,.16);
  border:1px solid rgba(255,255,255,.5);border-radius:14px;padding:4px 14px;cursor:pointer;}
.side-band .sb-sw button.on{background:#fff;color:var(--a-brand);font-weight:bold;}

.a-bar{display:flex;flex-wrap:wrap;gap:6px;align-items:center;clear:both;
  border:1.5px solid var(--a-line);border-radius:8px;padding:8px 10px;margin-bottom:8px;background:var(--a-bg);}
.a-bar label{margin:0;font-size:13px;color:var(--a-ink);font-weight:normal;}
.a-bar input[type=text],.a-bar input[type=month],.a-bar input[type=number],.a-bar select,.a-bar button{
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
#partySel{min-width:260px;}

.a-stat{display:flex;flex-wrap:wrap;gap:18px;align-items:center;margin-bottom:8px;
  border:1.5px solid var(--a-line);border-radius:8px;padding:9px 14px;background:var(--a-bg2);}
.a-stat .n{font-size:19px;font-weight:bold;color:var(--a-brand);}
.a-stat .n.big{font-size:23px;}
.a-stat .n.bad{color:var(--a-bad);}
.a-stat .n.good{color:#5C7A2E;}
.a-stat .l{font-size:12px;color:var(--a-ink2);}
.a-stat .sep{width:1px;height:30px;background:var(--a-line);}
.a-stat input{height:30px;width:130px;text-align:right;border:1px solid var(--a-line2);
  border-radius:4px;font-size:14px;padding:0 7px;color:var(--a-ink);}
.st-pill{display:inline-block;padding:2px 11px;border-radius:11px;font-size:12px;font-weight:bold;}
.stp-new{background:#EFE6D6;color:#8a6d45;}
.stp-draft{background:var(--a-acc);color:#fff;}
.stp-confirmed{background:#5C7A2E;color:#fff;}
.stp-reopened{background:var(--a-bad);color:#fff;}

.a-wrap{overflow-x:auto;border:1px solid var(--a-line);border-radius:6px;background:#fff;}
table.a-t{width:100%;border-collapse:collapse;font-size:13px;}
table.a-t th,table.a-t td{border:1px solid #EADFC8;padding:3px 6px;white-space:nowrap;text-align:center;}
table.a-t thead th{position:sticky;top:0;z-index:2;background:var(--a-ok);color:var(--a-ink);font-weight:bold;}
table.a-t td.l{text-align:left;}
table.a-t td.r{text-align:right;}
table.a-t tbody tr:nth-child(even){background:#FFFCF6;}
/* 已對到本筆＝暖淺綠 */
table.a-t tbody tr.hit{background:var(--a-hit) !important;}
table.a-t tbody tr.hit td{border-color:var(--a-hit-bd);}
table.a-t tbody tr.sel{outline:2px solid var(--a-acc);outline-offset:-2px;}
table.a-t tbody tr.grp{border-left:4px solid var(--a-acc);}
table.a-t tbody tr.child{background:#FBF3E6;}
table.a-t tbody tr.child td:first-child{padding-left:22px;}
table.a-t tbody tr.parent{color:#a08a6a;font-style:italic;}
table.a-t tbody tr.dragover{border-top:3px solid var(--a-acc);}
table.a-t tbody tr.dragging{opacity:.4;}
.drag-h{cursor:grab;color:var(--a-ink2);user-select:none;}
.drag-h:active{cursor:grabbing;}

.cell-in{width:78px;height:24px;text-align:right;border:1px solid transparent;border-radius:3px;
  font-size:12.5px;padding:0 4px;color:var(--a-ink);background:transparent;}
.cell-in:hover{border-color:var(--a-line2);background:#fff;}
.cell-in:focus{border-color:var(--a-acc);background:#FFFBF3;outline:none;}
.cell-in.wide{width:130px;text-align:left;}
.cell-in.mth{width:112px;text-align:center;}
.cell-in.adj{background:#FFF3DF;border-color:var(--a-line2);font-weight:bold;}
.orig-v{font-size:11px;color:#a08a6a;}

.pill{display:inline-block;padding:1px 7px;border-radius:9px;font-size:11px;line-height:16px;}
.p-g{background:var(--a-acc);color:#fff;}
.p-s{background:#5C7A2E;color:#fff;}
.p-lock{background:#C9B69F;color:#fff;}
.p-inv{background:#8A5A2B;color:#fff;}
.btn-mini{height:23px;padding:0 7px;border:1px solid var(--a-line2);border-radius:4px;
  background:#fff;cursor:pointer;color:var(--a-ink);font-size:11.5px;}
.btn-mini:hover{background:var(--a-ok);}

.dock{position:fixed;left:0;right:0;bottom:0;z-index:900;background:var(--a-brand);color:#fff;
  padding:9px 18px;display:none;align-items:center;gap:16px;box-shadow:0 -2px 10px rgba(0,0,0,.22);}
.dock b{font-size:17px;}
.dock .sp{margin-left:auto;display:flex;gap:7px;}
.dock button{height:33px;padding:0 15px;border-radius:5px;border:1px solid #fff;
  background:transparent;color:#fff;font-size:13.5px;cursor:pointer;}
.dock button.go{background:var(--a-acc);border-color:var(--a-acc-d);font-weight:bold;}
.dock button.ok{background:#5C7A2E;border-color:#4A6325;font-weight:bold;}
.dock button:hover{background:rgba(255,255,255,.16);}
.dock button.go:hover{background:var(--a-acc-d);}
.dock button:disabled{opacity:.45;cursor:default;}

/* 計算機 */
.calc{position:fixed;right:16px;bottom:70px;z-index:950;width:212px;background:#fff;
  border:2px solid var(--a-acc);border-radius:10px;box-shadow:0 4px 18px rgba(0,0,0,.25);display:none;}
.calc.show{display:block;}
.calc .c-head{background:var(--a-acc);color:#fff;padding:5px 10px;font-size:13px;font-weight:bold;
  border-radius:8px 8px 0 0;display:flex;align-items:center;cursor:move;}
.calc .c-head .x{margin-left:auto;cursor:pointer;}
.calc .c-scr{padding:6px 9px;text-align:right;font-size:20px;font-weight:bold;color:var(--a-ink);
  background:#FFFBF3;border-bottom:1px solid var(--a-line);min-height:34px;word-break:break-all;}
.calc .c-exp{padding:2px 9px;text-align:right;font-size:11px;color:var(--a-ink2);min-height:16px;
  background:#FFFBF3;word-break:break-all;}
.calc .c-pad{display:grid;grid-template-columns:repeat(4,1fr);gap:3px;padding:6px;}
.calc .c-pad button{height:31px;border:1px solid var(--a-line2);border-radius:4px;background:#fff;
  font-size:14px;cursor:pointer;color:var(--a-ink);}
.calc .c-pad button:hover{background:var(--a-ok);}
.calc .c-pad button.op{background:var(--a-bg2);}
.calc .c-pad button.eq{background:var(--a-acc);color:#fff;font-weight:bold;}
.calc .c-tip{padding:0 9px 7px;font-size:10.5px;color:var(--a-ink2);line-height:1.5;}

.a-mask{display:none;position:fixed;top:0;left:0;right:0;bottom:0;width:100%;height:100%;
  background:rgba(60,40,20,.45);z-index:9999;overflow:auto;padding:34px 12px;}
.a-mask.show{display:block;}
.a-modal{background:#fff;border-radius:8px;width:820px;max-width:100%;margin:0 auto;
  box-shadow:0 6px 30px rgba(0,0,0,.3);}
.a-modal.narrow{width:600px;}
.a-modal .m-head{background:var(--a-ok);color:var(--a-ink);padding:9px 14px;font-weight:bold;
  border-radius:8px 8px 0 0;display:flex;align-items:center;}
.a-modal .m-close{margin-left:auto;cursor:pointer;font-size:17px;}
.a-modal .m-body{padding:14px;max-height:68vh;overflow:auto;}
.a-modal .m-foot{padding:10px 14px;border-top:1px solid var(--a-line);text-align:right;}
.a-modal .m-foot button{height:32px;padding:0 14px;border:1px solid var(--a-line2);border-radius:4px;
  background:#fff;cursor:pointer;color:var(--a-ink);margin-left:5px;}
.a-modal .m-foot button.go{background:var(--a-acc);color:#fff;border-color:var(--a-acc-d);font-weight:bold;}

.info{background:var(--a-bg2);border-left:5px solid var(--a-acc);color:var(--a-ink);
  padding:8px 12px;border-radius:4px;font-size:13px;margin-bottom:8px;line-height:1.6;}
.warn{background:#FBE3DC;color:#7a2c17;border-left:5px solid var(--a-bad);
  padding:8px 12px;border-radius:4px;font-size:13px;margin-bottom:8px;}
.okbox{background:#EEF4E2;color:#3F5520;border-left:5px solid #5C7A2E;
  padding:8px 12px;border-radius:4px;font-size:13px;margin-bottom:8px;}

.a-msg{position:fixed;top:64px;right:18px;z-index:12000;min-width:250px;max-width:440px;
  padding:11px 15px;border-radius:6px;display:none;font-size:14px;box-shadow:0 3px 12px rgba(0,0,0,.2);}
.a-msg.ok{background:#F7E0BD;color:#5b3a1e;border-left:5px solid var(--a-acc);}
.a-msg.bad{background:#FBE3DC;color:#7a2c17;border-left:5px solid var(--a-bad);}
.a-noperm{border:1.5px solid var(--a-line);background:var(--a-bg);border-radius:8px;padding:26px;color:var(--a-ink);}
.a-hint{font-size:11.5px;color:var(--a-ink2);margin-top:5px;line-height:1.6;}
kbd{background:#f4e6ce;border:1px solid var(--a-line2);border-bottom-width:2px;border-radius:3px;
  padding:0 5px;font-size:11px;color:var(--a-ink);font-family:inherit;}

@media print{
  .a-bar,.dock,.calc,.a-mask,.side-band .sb-sw,.nav_menu,.left_col,footer{display:none !important;}
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
      <h2 style="margin:6px 0;"><i class="fa fa-check-square-o" style="color:#F0A24B;"></i> 對帳作業
        <small style="color:#8a6d45;">點列即勾選、可拖移排序對照紙本、可加總／拆分、可暫存續對；確認正確即鎖帳交會計</small></h2>
    </div>
    <div class="clearfix"></div>

<?php if (!$perms['canView']): ?>
    <div class="a-noperm">
      <h4><i class="fa fa-lock"></i> 無對帳權限</h4>
      <p>請洽管理者於「使用者權限設定」指派「應收對帳(業務)」、「應付對帳(生管)」或會計相關角色。</p>
    </div>
<?php else: ?>
    <div class="side-band">
      <i class="fa" id="sideIcon"></i>
      <b id="sideName">應收帳款</b><span class="sb-sub" id="sideSub">錢進來 · 向客戶收款</span>
      <span class="sb-sw">
        <button id="btnSideAr" class="on">應收（客戶）</button>
        <button id="btnSideAp">應付（廠商）</button>
      </span>
    </div>

    <div class="a-bar">
      <label>帳款月份</label>
      <input type="month" id="bm" style="width:145px;">
      <label id="partyLbl">客戶</label>
      <select id="partySel"><option value="">請先選帳款月份</option></select>
      <button id="btnLoad" class="btn-warm"><i class="fa fa-folder-open-o"></i> 載入對帳單</button>
      <button id="btnCalc"><i class="fa fa-calculator"></i> 計算機</button>
      <span class="a-role">目前角色：<b><?= htmlspecialchars($roleLbl) ?></b>
        <i class="fa fa-question-circle" id="btnRoleHelp" title="角色權限說明"></i></span>
    </div>

    <div id="sheetBox" style="display:none;">
      <div class="a-stat">
        <div><span class="st-pill" id="stStatus">—</span></div>
        <div class="sep"></div>
        <div><span class="n" id="stLines">—</span> <span class="l">列</span></div>
        <div><span class="n good" id="stChecked">—</span> <span class="l">已對到</span></div>
        <div class="sep"></div>
        <div><span class="n big" id="stOur">—</span> <span class="l">我方未稅</span></div>
        <div>
          <span class="l">對方紙本合計</span><br>
          <input type="number" id="theirTotal" step="0.01" placeholder="輸入紙本金額">
        </div>
        <div><span class="n big" id="stDiff">—</span> <span class="l">差額</span></div>
        <div class="sep"></div>
        <div><span class="n" id="stTax">—</span> <span class="l">稅額</span></div>
        <div><span class="n" id="stTotal">—</span> <span class="l">含稅</span></div>
      </div>
      <div id="noteBox"></div>

      <div class="a-bar" style="background:var(--a-bg2);">
        <button id="btnGroup"><i class="fa fa-object-group"></i> 合併為一組（多項加總）</button>
        <button id="btnUngroup"><i class="fa fa-object-ungroup"></i> 取消分組</button>
        <button id="btnSplit"><i class="fa fa-cut"></i> 拆分此列</button>
        <span style="width:1px;height:22px;background:var(--a-line);margin:0 4px;"></span>
        <button id="btnCheckAll"><i class="fa fa-check-square-o"></i> 全部標已對到</button>
        <button id="btnUncheckAll"><i class="fa fa-square-o"></i> 全部取消</button>
        <span style="width:1px;height:22px;background:var(--a-line);margin:0 4px;"></span>
        <button id="btnExport"><i class="fa fa-file-text-o"></i> 匯出底稿</button>
        <button id="btnPrint"><i class="fa fa-print"></i> 列印</button>
        <span class="a-hint" style="margin-left:6px;">
          點任一列＝標記已對到（轉<span style="background:var(--a-hit);padding:1px 6px;border-radius:3px;">暖淺綠</span>）；
          拖左側 <i class="fa fa-bars"></i> 可調順序對照紙本
        </span>
      </div>

      <div class="a-wrap">
        <table class="a-t" id="tbl">
          <thead><tr>
            <th style="width:26px;"></th>
            <th style="width:30px;">✓</th>
            <th>單號</th><th>日期</th>
            <th class="ap-only">製令</th>
            <th>料號</th><th>說明</th>
            <th>原始數量</th><th>原始單價</th><th>原始金額</th>
            <th style="background:#F0CFA0;">調整數量</th>
            <th style="background:#F0CFA0;">調整單價</th>
            <th style="background:#F0CFA0;">調整金額</th>
            <th style="background:#F0CFA0;">指定月份</th>
            <th>組／拆</th><th>備註</th><th>操作</th>
          </tr></thead>
          <tbody id="tbody"></tbody>
          <tfoot id="tfoot"></tfoot>
        </table>
      </div>
      <div class="a-hint">
        「調整」欄留空＝沿用原始值。<b>加總與拆分只影響這份對帳底稿，不會改到原始出貨／加工紀錄</b>——
        原始值永久保留在左邊供會計與稽核比對。拆分出來的各段金額合計必須等於原列金額，否則存不進去。
        有子列的父列會標為<span class="pill p-s">拆分來源</span>且不計入合計（金額由子列代表）。
      </div>
    </div>

    <div id="emptyBox" class="info" style="display:block;">
      選擇「帳款月份」與對象後按<b>載入對帳單</b>。若之前有暫存，會自動接續上次的進度；
      沒有的話就依該月份的出貨／加工紀錄即時組出一份新的。
    </div>
<?php endif; ?>
  </div>
  <?php include '../partPage/footer.html' ?>
</div>
</div>

<!-- 底部動作條 -->
<div class="dock" id="dock">
  <span>已對到 <b id="dkChecked">0</b> / <span id="dkLines">0</span> 列</span>
  <span>我方 <b id="dkOur">0</b></span>
  <span id="dkDiff"></span>
  <span class="sp">
    <button id="btnSave" class="go"><i class="fa fa-save"></i> 暫存進度</button>
    <button id="btnConfirm" class="ok"><i class="fa fa-check"></i> 確認正確（鎖帳）</button>
    <button id="btnReopen" style="display:none;"><i class="fa fa-undo"></i> 退回重對</button>
  </span>
</div>

<!-- 計算機 -->
<div class="calc" id="calc">
  <div class="c-head" id="calcHead"><i class="fa fa-calculator"></i>&nbsp;計算機<span class="x" id="calcX">✕</span></div>
  <div class="c-exp" id="calcExp"></div>
  <div class="c-scr" id="calcScr">0</div>
  <div class="c-pad">
    <button data-c="7">7</button><button data-c="8">8</button><button data-c="9">9</button><button class="op" data-c="/">÷</button>
    <button data-c="4">4</button><button data-c="5">5</button><button data-c="6">6</button><button class="op" data-c="*">×</button>
    <button data-c="1">1</button><button data-c="2">2</button><button data-c="3">3</button><button class="op" data-c="-">−</button>
    <button data-c="0">0</button><button data-c=".">.</button><button class="op" data-c="%">%</button><button class="op" data-c="+">＋</button>
    <button data-c="C">C</button><button data-c="BS">⌫</button><button data-c="SUM" title="把已勾選列的金額加總帶進來">Σ勾選</button>
    <button class="eq" data-c="=">＝</button>
  </div>
  <div class="c-tip">Σ勾選＝把目前已對到的列金額加總帶入。% 為除以 100。</div>
</div>

<!-- 拆分 -->
<div class="a-mask" id="mkSplit"><div class="a-modal">
  <div class="m-head"><i class="fa fa-cut"></i>&nbsp;拆分此列<span class="m-close" data-close="mkSplit">✕</span></div>
  <div class="m-body">
    <div class="info" id="spInfo"></div>
    <div class="a-bar">
      <label>拆成幾段</label>
      <input type="number" id="spN" min="2" max="12" value="2" style="width:80px;">
      <button id="spGen" class="btn-warm">產生欄位（平均分配）</button>
      <span class="a-hint" style="margin-left:6px;">用於「我方一次送、廠商拆批或拆月份請款」</span>
    </div>
    <div id="spBox"></div>
  </div>
  <div class="m-foot">
    <span id="spTally" style="float:left;font-size:13px;color:var(--a-ink);line-height:32px;"></span>
    <button data-close="mkSplit">取消</button>
    <button class="go" id="spOk"><i class="fa fa-check"></i> 套用拆分</button>
  </div>
</div></div>

<!-- 退回重對 -->
<div class="a-mask" id="mkReopen"><div class="a-modal narrow">
  <div class="m-head"><i class="fa fa-undo"></i>&nbsp;退回重對<span class="m-close" data-close="mkReopen">✕</span></div>
  <div class="m-body">
    <div class="warn">退回後對帳人員可再修改此對帳單涵蓋的單據。此動作會記錄退回人、時間與原因。</div>
    <label style="font-size:13px;color:var(--a-ink);">退回原因（必填）</label>
    <input type="text" id="roReason" maxlength="300" style="width:100%;height:32px;
      border:1px solid var(--a-line2);border-radius:4px;padding:0 8px;color:var(--a-ink);">
  </div>
  <div class="m-foot">
    <button data-close="mkReopen">取消</button>
    <button class="go" id="roOk"><i class="fa fa-undo"></i> 確認退回</button>
  </div>
</div></div>

<!-- 角色說明 -->
<div class="a-mask" id="mkRole"><div class="a-modal narrow">
  <div class="m-head">角色權限說明<span class="m-close" data-close="mkRole">✕</span></div>
  <div class="m-body" style="font-size:13.5px;color:#5b3a1e;line-height:1.9;">
    <b>管理者／會計管理員</b>：兩側都可對帳，另可<b>退回已鎖帳的對帳單</b>。<br>
    <b>會計登錄</b>：兩側都可對帳。<br>
    <b>應收對帳(業務)</b>：只能對<b>應收</b>（客戶／出貨退貨），不可碰應付。<br>
    <b>應付對帳(生管)</b>：只能對<b>應付</b>（廠商／加工費），不可碰應收。<br>
    <b>會計檢閱</b>：只能看，不能改也不能確認。<br>
    <span style="color:#8a6d45;font-size:12.5px;">
      鎖帳規則：按「確認正確」即鎖帳，之後不可改單也不可再暫存，僅會計管理員可退回。<br>
      已開立發票的憑證另有更硬的鎖——不提供解鎖，只能作廢／折讓／補開（帳面須與國稅局申報一致）。<br>
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
var TAX_RATE = <?= json_encode($taxRate) ?>;
var P = {
  canReconAr: <?= $perms['canReconAr'] ? 'true' : 'false' ?>,
  canReconAp: <?= $perms['canReconAp'] ? 'true' : 'false' ?>,
  canAdmin:   <?= $perms['canAdmin']   ? 'true' : 'false' ?>
};

var CSRF = '', SIDE = 'ar', sheet = null, lines = [], selIdx = -1, keySeq = 0, canEdit = false;
/* 從總覽頁帶進來的目標（沒帶就是空字串），對象清單載完後自動選起來並載入 */
var DEEP = <?= json_encode($deep, JSON_UNESCAPED_UNICODE) ?>;
var pendingParty = '';
var ST_LABEL = {'new':'尚未建立', draft:'暫存中', confirmed:'已確認鎖帳', reopened:'已退回重對'};

function esc(s){ return String(s==null?'':s).replace(/[&<>"']/g,function(c){
  return {'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#39;'}[c]; }); }
function nf(n){ return Math.round(Number(n)||0).toLocaleString('en-US'); }
function n2(n){ var v=Number(n)||0; return String(parseFloat(v.toFixed(4))); }
function toast(m,bad){
  var $m=$('#msg').removeClass('ok bad').addClass(bad?'bad':'ok').html(m).stop(true,true).fadeIn(150);
  clearTimeout($m.data('t')); $m.data('t',setTimeout(function(){ $m.fadeOut(400); },bad?7000:3600));
}
function openMask(id){ $('#'+id).addClass('show'); }
function closeMask(id){ $('#'+id).removeClass('show'); }
$(document).on('click','[data-close]',function(){ closeMask($(this).data('close')); });
$(document).on('click','.a-mask',function(e){ if(e.target===this) $(this).removeClass('show'); });
$('#btnRoleHelp').on('click',function(){ openMask('mkRole'); });
function nk(){ return 'k' + (++keySeq); }
function amtOf(l){
  return (l.adj_amount!==null && l.adj_amount!==undefined && l.adj_amount!=='')
    ? Number(l.adj_amount) : Number(l.orig_amount||0);
}
/* 有子列的父列不計入合計（金額由子列代表），與後端同一套規則 */
function parentKeys(){
  var s={};
  lines.forEach(function(l){ if(l.split_parent_key) s[l.split_parent_key]=1; });
  return s;
}

/* ══ 側別切換 ══ */
function setSide(s){
  SIDE = (s==='ap') ? 'ap' : 'ar';
  $('body').toggleClass('side-ap', SIDE==='ap');
  $('#btnSideAr').toggleClass('on', SIDE==='ar');
  $('#btnSideAp').toggleClass('on', SIDE==='ap');
  $('#sideIcon').attr('class','fa ' + (SIDE==='ap'?'fa-arrow-circle-up':'fa-arrow-circle-down'));
  $('#sideName').text(SIDE==='ap'?'應付帳款':'應收帳款');
  $('#sideSub').text(SIDE==='ap'?'錢出去 · 付給廠商':'錢進來 · 向客戶收款');
  $('#partyLbl').text(SIDE==='ap'?'廠商':'客戶');
  $('.ap-only').toggle(SIDE==='ap');
  sheet=null; lines=[]; $('#sheetBox').hide(); $('#emptyBox').show(); $('#dock').hide();
  loadParties();
}
$('#btnSideAr').on('click',function(){
  if(!P.canReconAr && !P.canAdmin){ toast('你沒有應收對帳權限', true); return; }
  setSide('ar');
});
$('#btnSideAp').on('click',function(){
  if(!P.canReconAp && !P.canAdmin){ toast('你沒有應付對帳權限', true); return; }
  setSide('ap');
});

/* ══ 初始化 ══ */
(function(){
  var d=new Date(); d.setDate(1); d.setMonth(d.getMonth()-1);
  $('#bm').val(d.getFullYear()+'-'+('0'+(d.getMonth()+1)).slice(-2));
})();
$.getJSON(API,{action:'meta'},function(r){
  if(!r.ok){ toast(esc(r.error||'初始化失敗'), true); return; }
  CSRF=r.csrf;
  if(DEEP.party_id){
    // 由總覽頁指定了要開哪一份：設好月份與側別，對象清單載完會自動接著載入
    $('#bm').val(DEEP.bm);
    pendingParty = DEEP.party_id;
    setSide(DEEP.side);
    return;
  }
  // 只有單側權限的人，直接切到他那一側
  if(!P.canReconAr && P.canReconAp) setSide('ap'); else setSide('ar');
}).fail(function(){ toast('無法連線到會計 API', true); });

function loadParties(){
  var bm=$('#bm').val();
  if(!bm){ $('#partySel').html('<option value="">請先選帳款月份</option>'); return; }
  $('#partySel').html('<option value="">載入中…</option>');
  $.post(API+'?action=recon_parties',{side:SIDE, bm:bm},function(r){
    if(!r.ok){ toast(esc(r.error||'查詢失敗'), true); return; }
    var h='<option value="">請選擇（共 '+r.parties.length+' 家）</option>';
    r.parties.forEach(function(p){
      var tag='';
      if(p.sheet) tag = ' ['+(ST_LABEL[p.sheet.status]||p.sheet.status)
        + (p.sheet.status==='draft' ? ' '+p.sheet.checked_cnt+'/'+p.sheet.line_cnt : '') + ']';
      h+='<option value="'+esc(p.party_id)+'">'+esc(p.party_name)
        +'（'+p.cnt+' 筆 / '+nf(p.amount)+'）'+esc(tag)+'</option>';
    });
    $('#partySel').html(h);
    if(pendingParty){
      var want=pendingParty; pendingParty='';
      // 該對象若該月已無來源憑證（例如全數拆到別的月份）就不會在清單裡，
      // 補一個 option 進去，底稿仍然要能開起來看
      var found=false;
      $('#partySel option').each(function(){ if(this.value===want) found=true; });
      if(!found){
        $('#partySel').append('<option value="'+esc(want)+'">'+esc(want)+'（已有對帳底稿）</option>');
      }
      $('#partySel').val(want);
      if($('#partySel').val()===want) loadSheet();
      else toast('找不到指定的對象：'+esc(want), true);
    }
  },'json').fail(function(){ toast('查詢對象清單失敗', true); });
}
$('#bm').on('change', loadParties);

/* ══ 載入底稿 ══ */
$('#btnLoad').on('click', loadSheet);
$('#partySel').on('change',function(){ if($(this).val()) loadSheet(); });

function loadSheet(){
  var bm=$('#bm').val(), pid=$('#partySel').val();
  if(!bm || !pid){ toast('請選擇帳款月份與對象', true); return; }
  $.post(API+'?action=sheet_load',{side:SIDE, party_id:pid, billing_month:bm},function(r){
    if(!r.ok){ toast(esc(r.error||'載入失敗'), true); return; }
    sheet = r.sheet; canEdit = !!r.can_edit;
    keySeq = 0;
    // 統一成前端用的 key 模型：client_key / split_parent_key
    var idMap = {};
    lines = (sheet.lines||[]).map(function(l){
      var k = nk();
      if(l.line_id) idMap[l.line_id] = k;
      return $.extend({}, l, {client_key:k});
    });
    lines.forEach(function(l){
      l.split_parent_key = (l.split_parent && idMap[l.split_parent]) ? idMap[l.split_parent] : null;
    });
    $('#theirTotal').val(sheet.their_total===null||sheet.their_total===undefined ? '' : sheet.their_total);
    $('#emptyBox').hide(); $('#sheetBox').show();
    $('#btnReopen').toggle(P.canAdmin && sheet.status==='confirmed');
    render();
    toast(r.from==='draft'
      ? '已接續上次暫存的進度（'+esc(sheet.party_name)+' '+esc(sheet.billing_month)+'）'
      : '已依該月份的單據組出新的對帳單（尚未暫存）');
  },'json').fail(function(x){
    var m='載入失敗'; try{ m=JSON.parse(x.responseText).error||m; }catch(e){}
    toast(esc(m), true);
  });
}

/* ══ 畫表 ══ */
function render(){
  var locked = (sheet.status==='confirmed');
  var editable = canEdit && !locked;
  var pk = parentKeys();

  // 依 sort_order 排；拆分子列緊跟在父列後面
  var top = lines.filter(function(l){ return !l.split_parent_key; })
                 .sort(function(a,b){ return (a.sort_order||0)-(b.sort_order||0); });
  var ordered = [];
  top.forEach(function(l){
    ordered.push(l);
    lines.filter(function(c){ return c.split_parent_key===l.client_key; })
         .sort(function(a,b){ return (a.split_seq||0)-(b.split_seq||0); })
         .forEach(function(c){ ordered.push(c); });
  });

  var h='';
  ordered.forEach(function(l){
    var i = lines.indexOf(l);
    var isChild = !!l.split_parent_key;
    var isParent = !!pk[l.client_key];
    var cls = [];
    if(l.checked) cls.push('hit');
    if(i===selIdx) cls.push('sel');
    if(l.group_no) cls.push('grp');
    if(isChild) cls.push('child');
    if(isParent) cls.push('parent');
    var ro = editable ? '' : ' readonly';

    h+='<tr data-i="'+i+'" class="'+cls.join(' ')+'"'+(editable&&!isChild?' draggable="true"':'')+'>'
      +'<td>'+(editable&&!isChild?'<span class="drag-h"><i class="fa fa-bars"></i></span>':'')+'</td>'
      +'<td>'+(l.checked?'<i class="fa fa-check" style="color:#5C7A2E;"></i>':'')+'</td>'
      +'<td class="l">'+esc(l.doc_no||'')+'</td>'
      +'<td>'+esc(l.doc_date||'')+'</td>'
      +(SIDE==='ap'?'<td>'+esc(l.bom||'')+'</td>':'')
      +'<td class="l">'+esc(l.product_id||'')+'</td>'
      +'<td class="l">'+esc((l.spec||'').substr(0,18))+'</td>'
      +'<td class="r orig-v">'+(l.orig_qty===null?'':nf(l.orig_qty))+'</td>'
      +'<td class="r orig-v">'+(l.orig_price===null?'':n2(l.orig_price))+'</td>'
      +'<td class="r orig-v"><b>'+(l.orig_amount===null?'':nf(l.orig_amount))+'</b></td>'
      +'<td><input type="number" class="cell-in adj-q'+(l.adj_qty!==null&&l.adj_qty!==''?' adj':'')+'" data-f="adj_qty" value="'+esc(l.adj_qty===null?'':l.adj_qty)+'"'+ro+'></td>'
      +'<td><input type="number" step="0.0001" class="cell-in adj-p'+(l.adj_price!==null&&l.adj_price!==''?' adj':'')+'" data-f="adj_price" value="'+esc(l.adj_price===null?'':l.adj_price)+'"'+ro+'></td>'
      +'<td><input type="number" step="0.01" class="cell-in adj-a'+(l.adj_amount!==null&&l.adj_amount!==''?' adj':'')+'" data-f="adj_amount" value="'+esc(l.adj_amount===null?'':l.adj_amount)+'"'+ro+'></td>'
      +'<td><input type="month" class="cell-in mth'+(l.adj_month?' adj':'')+'" data-f="adj_month" value="'+esc(l.adj_month||'')+'"'+ro+'></td>'
      +'<td>'+(l.group_no?'<span class="pill p-g">組'+l.group_no+'</span>':'')
             +(isParent?'<span class="pill p-s">拆分來源</span>':'')
             +(isChild?'<span class="pill p-s">第'+(l.split_seq||'')+'段</span>':'')+'</td>'
      +'<td><input type="text" class="cell-in wide" data-f="memo" value="'+esc(l.memo||'')+'"'+ro+'></td>'
      +'<td>'+(editable&&isChild?'<button class="btn-mini rm-child">移除</button>':'')+'</td>'
      +'</tr>';
  });
  $('#tbody').html(h);
  renderFoot(pk);
  updateStat();
}

function renderFoot(pk){
  // 各分組小計（多項加總的組要看得到合計，才能跟對方那一列比）
  var g={}, h='';
  lines.forEach(function(l){
    if(!l.group_no) return;
    if(pk[l.client_key]) return;
    g[l.group_no] = (g[l.group_no]||0) + amtOf(l);
  });
  Object.keys(g).forEach(function(k){
    h+='<tr><td colspan="'+(SIDE==='ap'?10:9)+'" class="r"><b>加總組 '+esc(k)+' 小計</b></td>'
      +'<td colspan="3" class="r"><b style="color:var(--a-brand);font-size:15px;">'+nf(g[k])+'</b></td>'
      +'<td colspan="4"></td></tr>';
  });
  $('#tfoot').html(h);
}

/* ══ 統計與差額 ══ */
function totals(){
  var pk=parentKeys(), net=0, ck=0;
  lines.forEach(function(l){
    if(pk[l.client_key]) return;
    net += amtOf(l);
  });
  lines.forEach(function(l){ if(l.checked) ck++; });
  var tax=Math.round(net*TAX_RATE);
  return {net:net, tax:tax, total:net+tax, checked:ck, count:lines.length};
}
function updateStat(){
  var t=totals();
  var their = $('#theirTotal').val()==='' ? null : Number($('#theirTotal').val());
  var diff  = (their===null) ? null : Math.round((their - t.net)*100)/100;

  $('#stStatus').attr('class','st-pill stp-'+(sheet.status||'new')).text(ST_LABEL[sheet.status]||sheet.status);
  $('#stLines').text(nf(t.count));
  $('#stChecked').text(nf(t.checked));
  $('#stOur').text(nf(t.net));
  $('#stTax').text(nf(t.tax));
  $('#stTotal').text(nf(t.total));
  $('#stDiff').removeClass('bad good')
    .addClass(diff===null?'':(Math.abs(diff)<0.01?'good':'bad'))
    .text(diff===null?'—':nf(diff));

  $('#dkChecked').text(nf(t.checked)); $('#dkLines').text(nf(t.count));
  $('#dkOur').text(nf(t.net));
  $('#dkDiff').html(diff===null?'' : (Math.abs(diff)<0.01
      ? '<b style="color:#CFE8A8;">✓ 與對方紙本相符</b>'
      : '差額 <b style="color:#FFD9CF;">'+nf(diff)+'</b>'));
  $('#dock').css('display','flex');

  var locked=(sheet.status==='confirmed');
  $('#btnSave,#btnConfirm').prop('disabled', !canEdit || locked);
  $('#btnGroup,#btnUngroup,#btnSplit,#btnCheckAll,#btnUncheckAll').prop('disabled', !canEdit || locked);
  $('#btnReopen').toggle(P.canAdmin && locked);

  var nb='';
  if(locked) nb='<div class="okbox">此對帳單已於 '+esc(sheet.confirmed_at||'')+' 由 <b>'
    +esc(sheet.confirmed_by_name||'')+'</b> 確認鎖帳，內容不可再修改。'
    +(P.canAdmin?'需修改請按右下角「退回重對」。':'需修改請洽會計管理員退回。')+'</div>';
  else if(sheet.status==='reopened') nb='<div class="warn">此對帳單曾被 <b>'+esc(sheet.reopen_by_name||'')
    +'</b> 於 '+esc(sheet.reopen_at||'')+' 退回重對，原因：'+esc(sheet.reopen_reason||'')+'</div>';
  else if(!canEdit) nb='<div class="warn">你沒有這一側的對帳修改權限，只能檢視。</div>';
  $('#noteBox').html(nb);
}
$('#theirTotal').on('input', updateStat);

/* ══ 點列勾選（已對到→暖淺綠）══ */
$(document).on('click','#tbody td',function(e){
  if($(e.target).is('input,button,.drag-h,i')) return;
  var $tr=$(this).closest('tr'), i=parseInt($tr.data('i'),10);
  selIdx=i;
  if(canEdit && sheet.status!=='confirmed') lines[i].checked = lines[i].checked ? 0 : 1;
  render();
});
$('#btnCheckAll').on('click',function(){ lines.forEach(function(l){ l.checked=1; }); render(); });
$('#btnUncheckAll').on('click',function(){ lines.forEach(function(l){ l.checked=0; }); render(); });

/* ══ 就地編輯調整值 ══ */
$(document).on('change','#tbody .cell-in',function(){
  var i=parseInt($(this).closest('tr').data('i'),10);
  var f=$(this).data('f'), v=$.trim($(this).val());
  lines[i][f] = (v==='') ? null : v;
  // 改了數量或單價、但沒自己填金額 → 自動算金額，省得手動乘
  if((f==='adj_qty'||f==='adj_price') && (lines[i].adj_amount===null||lines[i].adj_amount==='')){
    var q = (lines[i].adj_qty!==null&&lines[i].adj_qty!=='') ? Number(lines[i].adj_qty) : Number(lines[i].orig_qty);
    var p = (lines[i].adj_price!==null&&lines[i].adj_price!=='') ? Number(lines[i].adj_price) : Number(lines[i].orig_price);
    if(!isNaN(q)&&!isNaN(p)) lines[i].adj_amount = Math.round(q*p*100)/100;
  }
  render();
});

/* ══ 多項加總 ══ */
$('#btnGroup').on('click',function(){
  var sel=lines.filter(function(l){ return l.checked && !l.split_parent_key; });
  if(sel.length<2){ toast('請先點選（勾選）至少 2 列要合併的單據', true); return; }
  var mx=0; lines.forEach(function(l){ if(l.group_no) mx=Math.max(mx,Number(l.group_no)); });
  var g=mx+1;
  sel.forEach(function(l){ l.group_no=g; });
  // 同組排在一起，方便與對方那一列對照
  var base=Math.min.apply(null, sel.map(function(l){ return l.sort_order||0; }));
  sel.forEach(function(l,n){ l.sort_order = base + n; });
  render();
  var sum=0; sel.forEach(function(l){ sum+=amtOf(l); });
  toast('已合併 '+sel.length+' 列為<b>組 '+g+'</b>，小計 <b>'+nf(sum)+'</b>（可與對方那一列比對）');
});
$('#btnUngroup').on('click',function(){
  var n=0;
  lines.forEach(function(l){ if(l.checked && l.group_no){ l.group_no=null; n++; } });
  if(!n){ toast('請先勾選要取消分組的列', true); return; }
  render(); toast('已取消 '+n+' 列的分組');
});

/* ══ 單項拆分 ══ */
var spTarget=null;
$('#btnSplit').on('click',function(){
  if(selIdx<0 || !lines[selIdx]){ toast('請先點一列要拆分的單據（點該列任一處）', true); return; }
  var l=lines[selIdx];
  if(l.split_parent_key){ toast('這已經是拆分出來的子列，不能再拆', true); return; }
  var has=lines.some(function(x){ return x.split_parent_key===l.client_key; });
  if(has){ toast('這列已經拆分過了，請先移除原本的子列', true); return; }
  spTarget=l;
  $('#spInfo').html('要拆分：<b>'+esc(l.doc_no||'')+'</b>　'+esc(l.doc_date||'')
    +'　'+esc(l.product_id||'')+'　金額 <b>'+nf(amtOf(l))+'</b><br>'
    +'用於「我方一次送、但對方拆批或拆月份請款」。<b>各段金額合計必須等於 '+nf(amtOf(l))+'</b>。');
  $('#spN').val(2); $('#spBox').empty(); $('#spTally').text('');
  openMask('mkSplit');
  $('#spGen').click();
});
$('#spGen').on('click',function(){
  var n=Math.max(2,Math.min(12,parseInt($('#spN').val(),10)||2));
  var tot=amtOf(spTarget);
  var each=Math.floor(tot/n*100)/100;
  var h='<table class="a-t"><thead><tr><th>段</th><th>金額</th><th>帳款月份</th><th>備註</th></tr></thead><tbody>';
  for(var i=0;i<n;i++){
    var v = (i===n-1) ? Math.round((tot-each*(n-1))*100)/100 : each;
    h+='<tr><td>'+(i+1)+'</td>'
      +'<td><input type="number" step="0.01" class="sp-a" value="'+v+'" style="width:120px;height:28px;text-align:right;border:1px solid var(--a-line2);border-radius:4px;padding:0 5px;"></td>'
      +'<td><input type="month" class="sp-m" value="'+esc(spTarget.adj_month||sheet.billing_month)+'" style="width:135px;height:28px;border:1px solid var(--a-line2);border-radius:4px;"></td>'
      +'<td><input type="text" class="sp-n" placeholder="如：廠商 6 月才請這段" style="width:230px;height:28px;border:1px solid var(--a-line2);border-radius:4px;padding:0 5px;"></td></tr>';
  }
  $('#spBox').html(h+'</tbody></table>');
  spTally();
});
function spTally(){
  var sum=0; $('.sp-a').each(function(){ sum+=Number(this.value)||0; });
  var tot=amtOf(spTarget), d=Math.round((sum-tot)*100)/100;
  $('#spTally').html('各段合計 <b>'+nf(sum)+'</b> ／ 原列 '+nf(tot)
    + (Math.abs(d)<0.01 ? ' <b style="color:#5C7A2E;">✓ 相符</b>'
                        : ' <b style="color:var(--a-bad);">差 '+nf(d)+'（必須為 0 才能套用）</b>'));
  $('#spOk').prop('disabled', Math.abs(d)>=0.01);
}
$(document).on('input','.sp-a', spTally);
$('#spOk').on('click',function(){
  var tot=amtOf(spTarget), sum=0, kids=[];
  $('#spBox tbody tr').each(function(n){
    var a=Number($(this).find('.sp-a').val())||0;
    sum+=a;
    kids.push({amount:a, month:$(this).find('.sp-m').val(), memo:$.trim($(this).find('.sp-n').val())});
  });
  if(Math.abs(sum-tot)>=0.01){ toast('各段合計必須等於原列金額', true); return; }
  // 移除舊子列後重建
  lines = lines.filter(function(l){ return l.split_parent_key!==spTarget.client_key; });
  kids.forEach(function(k,n){
    lines.push({
      client_key:nk(), split_parent_key:spTarget.client_key, split_seq:n+1,
      src_type:'SPLIT', src_id:0, doc_no:spTarget.doc_no, doc_date:spTarget.doc_date,
      bom:spTarget.bom, product_id:spTarget.product_id, spec:spTarget.spec,
      orig_qty:null, orig_price:null, orig_amount:tot,
      adj_qty:null, adj_price:null, adj_amount:k.amount, adj_month:k.month||null,
      checked:0, group_no:null, memo:k.memo||null,
      sort_order:(spTarget.sort_order||0)+ (n+1)/100
    });
  });
  closeMask('mkSplit'); render();
  toast('已把 <b>'+esc(spTarget.doc_no||'')+'</b> 拆成 '+kids.length+' 段（原列改為拆分來源，不計入合計）');
});
$(document).on('click','.rm-child',function(e){
  e.stopPropagation();
  var i=parseInt($(this).closest('tr').data('i'),10);
  var k=lines[i].split_parent_key;
  lines.splice(i,1);
  var left=lines.filter(function(l){ return l.split_parent_key===k; });
  left.forEach(function(l,n){ l.split_seq=n+1; });
  selIdx=-1; render();
  toast(left.length ? '已移除一段（剩 '+left.length+' 段，注意合計需與原列相符才能存）'
                    : '已移除全部拆分，該列恢復計入合計');
});

/* ══ 拖移排序 ══ */
var dragIdx=-1;
$(document).on('dragstart','#tbody tr',function(e){
  dragIdx=parseInt($(this).data('i'),10);
  $(this).addClass('dragging');
  try{ e.originalEvent.dataTransfer.effectAllowed='move';
       e.originalEvent.dataTransfer.setData('text/plain',String(dragIdx)); }catch(err){}
});
$(document).on('dragend','#tbody tr',function(){ $('#tbody tr').removeClass('dragging dragover'); });
$(document).on('dragover','#tbody tr',function(e){
  e.preventDefault();
  $('#tbody tr').removeClass('dragover'); $(this).addClass('dragover');
});
$(document).on('drop','#tbody tr',function(e){
  e.preventDefault();
  $('#tbody tr').removeClass('dragover dragging');
  var to=parseInt($(this).data('i'),10);
  if(dragIdx<0 || to<0 || dragIdx===to) return;
  var src=lines[dragIdx], dst=lines[to];
  if(src.split_parent_key) return;                 // 子列跟著父列走，不單獨排
  // 重排：把 src 的 sort_order 插到 dst 前面
  var tops=lines.filter(function(l){ return !l.split_parent_key; })
                .sort(function(a,b){ return (a.sort_order||0)-(b.sort_order||0); });
  var arr=tops.filter(function(l){ return l!==src; });
  var at=arr.indexOf(dst);
  if(at<0) at=arr.length;
  arr.splice(at,0,src);
  arr.forEach(function(l,n){ l.sort_order=(n+1)*10; });
  // 子列跟著父列
  lines.filter(function(l){ return l.split_parent_key; }).forEach(function(c){
    var p=lines.filter(function(x){ return x.client_key===c.split_parent_key; })[0];
    if(p) c.sort_order=(p.sort_order||0)+(c.split_seq||1)/100;
  });
  dragIdx=-1; selIdx=-1; render();
});

/* ══ 暫存 / 確認 / 退回 ══ */
function payload(){
  return {
    sheet: JSON.stringify({
      side:SIDE, party_id:sheet.party_id, party_name:sheet.party_name,
      billing_month:sheet.billing_month,
      their_total: $('#theirTotal').val()==='' ? null : $('#theirTotal').val(),
      memo: sheet.memo||null
    }),
    lines: JSON.stringify(lines.map(function(l){
      return {
        client_key:l.client_key, split_parent_key:l.split_parent_key||null, split_seq:l.split_seq||null,
        sort_order:Math.round(l.sort_order||0), src_type:l.src_type, src_id:l.src_id||0,
        doc_no:l.doc_no, doc_date:l.doc_date, bom:l.bom, product_id:l.product_id, spec:l.spec,
        orig_qty:l.orig_qty, orig_price:l.orig_price, orig_amount:l.orig_amount,
        adj_qty:l.adj_qty, adj_price:l.adj_price, adj_amount:l.adj_amount, adj_month:l.adj_month,
        checked:l.checked?1:0, group_no:l.group_no||null, memo:l.memo||null
      };
    }))
  };
}
$('#btnSave').on('click',function(){
  var p=payload(); p.csrf=CSRF;
  var $b=$(this).prop('disabled',true).html('<i class="fa fa-spinner fa-spin"></i> 暫存中…');
  $.post(API+'?action=sheet_save', p, function(r){
    $b.prop('disabled',false).html('<i class="fa fa-save"></i> 暫存進度');
    if(!r.ok){ toast(esc(r.error||'暫存失敗'), true); return; }
    sheet.sheet_id=r.sheet_id; sheet.status='draft';
    toast(esc(r.message)); loadSheet();
  },'json').fail(function(x){
    $b.prop('disabled',false).html('<i class="fa fa-save"></i> 暫存進度');
    var m='暫存失敗'; try{ m=JSON.parse(x.responseText).error||m; }catch(e){}
    toast(esc(m), true);
  });
});
$('#btnConfirm').on('click',function(){
  var t=totals();
  var their=$('#theirTotal').val()===''?null:Number($('#theirTotal').val());
  var diff = their===null?null:Math.round((their-t.net)*100)/100;
  var warn='';
  if(t.checked<t.count) warn+='\n・還有 '+(t.count-t.checked)+' 列沒有標記「已對到」';
  if(diff!==null && Math.abs(diff)>=0.01) warn+='\n・與對方紙本仍有差額 '+nf(diff)+' 元';
  if(their===null) warn+='\n・尚未輸入對方紙本合計（無法比對）';
  if(!confirm('確認這份對帳單正確並鎖帳？'+(warn?'\n\n請注意：'+warn:'')
    +'\n\n鎖帳後不可再修改，僅會計管理員可退回重對。')) return;

  // 先把目前畫面存起來再確認，避免鎖到舊版本
  var p=payload(); p.csrf=CSRF;
  var $b=$(this).prop('disabled',true).html('<i class="fa fa-spinner fa-spin"></i> 處理中…');
  $.post(API+'?action=sheet_save', p, function(r){
    if(!r.ok){ $b.prop('disabled',false).html('<i class="fa fa-check"></i> 確認正確（鎖帳）');
               toast(esc(r.error||'暫存失敗'), true); return; }
    $.post(API+'?action=sheet_confirm',{csrf:CSRF, sheet_id:r.sheet_id},function(c){
      $b.prop('disabled',false).html('<i class="fa fa-check"></i> 確認正確（鎖帳）');
      if(!c.ok){ toast(esc(c.error||'確認失敗'), true); return; }
      toast('<b>'+esc(c.message)+'</b>');
      loadSheet(); loadParties();
    },'json').fail(function(){ $b.prop('disabled',false).html('<i class="fa fa-check"></i> 確認正確（鎖帳）');
                               toast('確認失敗', true); });
  },'json').fail(function(){ $b.prop('disabled',false).html('<i class="fa fa-check"></i> 確認正確（鎖帳）');
                             toast('暫存失敗', true); });
});
$('#btnReopen').on('click',function(){ $('#roReason').val(''); openMask('mkReopen'); });
$('#roOk').on('click',function(){
  var rs=$.trim($('#roReason').val());
  if(rs.length<2){ toast('請填寫退回原因', true); $('#roReason').focus(); return; }
  $.post(API+'?action=sheet_reopen',{csrf:CSRF, sheet_id:sheet.sheet_id, reason:rs},function(r){
    if(!r.ok){ toast(esc(r.error||'退回失敗'), true); return; }
    closeMask('mkReopen'); toast(esc(r.message)); loadSheet(); loadParties();
  },'json').fail(function(){ toast('退回失敗', true); });
});

$('#btnExport').on('click',function(){
  if(!sheet || !sheet.sheet_id){ toast('請先暫存後再匯出', true); return; }
  window.location = API+'?action=sheet_export&side='+SIDE
    +'&party_id='+encodeURIComponent(sheet.party_id)
    +'&billing_month='+encodeURIComponent(sheet.billing_month);
});
$('#btnPrint').on('click',function(){ window.print(); });

/* ══ 計算機 ══ */
var cExp='', cVal='0';
$('#btnCalc').on('click',function(){ $('#calc').toggleClass('show'); });
$('#calcX').on('click',function(){ $('#calc').removeClass('show'); });
$(document).on('click','.c-pad button',function(){
  var c=$(this).data('c');
  if(c==='C'){ cExp=''; cVal='0'; }
  else if(c==='BS'){ cExp=cExp.slice(0,-1); cVal=cExp||'0'; }
  else if(c==='SUM'){
    var s=0; lines.forEach(function(l){ if(l.checked) s+=amtOf(l); });
    cExp=String(s); cVal=String(s);
  }
  else if(c==='='){
    try{
      var e=cExp.replace(/%/g,'/100');
      if(!/^[\d+\-*/.() ]+$/.test(e)) throw 0;
      var v=Function('"use strict";return ('+e+')')();
      cVal=(v===undefined||v===null||isNaN(v))?'錯誤':String(Math.round(v*10000)/10000);
      cExp=(cVal==='錯誤')?'':cVal;
    }catch(err){ cVal='錯誤'; cExp=''; }
  }
  else { cExp+=String(c); cVal=cExp; }
  $('#calcExp').text(cExp);
  $('#calcScr').text(cVal);
});
/* 計算機可拖動 */
(function(){
  var dx=0,dy=0,drag=false;
  $('#calcHead').on('mousedown',function(e){
    if($(e.target).is('#calcX')) return;
    drag=true;
    var o=$('#calc').offset();
    dx=e.pageX-o.left; dy=e.pageY-o.top;
    e.preventDefault();
  });
  $(document).on('mousemove',function(e){
    if(!drag) return;
    $('#calc').css({left:(e.pageX-dx)+'px', top:(e.pageY-dy)+'px', right:'auto', bottom:'auto'});
  }).on('mouseup',function(){ drag=false; });
})();
})(jQuery);
</script>
</body>
</html>
