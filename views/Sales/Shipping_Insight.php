<?php
/**
 * 出貨分析 — 2026-09-24 建立（使用者交辦：比照訂單分析做一份新的出貨分析頁面）
 *
 * 全新獨立頁面，**不修改** views/Sales/Shipping_Analysis_new.php 一行程式碼（使用者明確要求）。
 * 版面／自動分析／列印比照 views/Sales/Order_Analysis.php（僅改藍色系配色以便區分兩份分析報表）；
 * 功能比照 Shipping_Analysis_new.php：出貨性質設定／月份截止日／異常偵測／客戶季度分析／
 * 出貨明細・退貨單・訂單・客戶統計 四個分頁。
 *
 * 權限沿用既有「shipping」模組角色（src/common/shipping_lib.php 的 sq_perms()），不另開新角色：
 *   canView  可檢視本頁；canAdmin 可改設定（出貨性質、月份截止日、KPI/移動平均監控、確認異常）。
 * 計算唯一實作在 src/common/shipping_insight_lib.php；資料一律走 src/store/ShippingInsight_API.php。
 */
session_start();
if (!isset($_SESSION['userName'])) {
    $_SESSION['lastpage'] = "../../views/Sales/Shipping_Insight.php";
    header("Location:../../index.php");
    exit;
}
include_once '../../src/common/_config.php';
include_once '../../src/common/DBConnection.php';
include_once '../../src/common/shipping_lib.php';
include_once '../../src/common/shipping_insight_lib.php';
include_once '../../src/common/org_role_lib.php';   // eg_company_full_name()：列印大標題公司全名（禁寫死）

$db = (new DBConnection())->getPDO();
$sqUser = sq_current_user($db);
$perms  = sq_perms($db, $sqUser);
$canView  = (bool)$perms['canView'];
$canAdmin = (bool)$perms['canAdmin'];
$roleLabel = $perms['isAdmin'] ? '管理者'
           : ($perms['canAdmin'] ? '出貨管理員'
           : ($perms['canEdit'] ? '出貨登錄'
           : ($perms['canView'] ? '出貨檢閱' : '無權限')));

if (empty($_SESSION['si_csrf'])) $_SESSION['si_csrf'] = bin2hex(random_bytes(16));
$CSRF = $_SESSION['si_csrf'];

$thisYear = (int)date('Y');
$saleTypes = si_sale_types($db);
$COMPANY = '';
try { $COMPANY = eg_company_full_name($db); } catch (Throwable $e) { $COMPANY = ''; }
function siEsc($v) { return htmlspecialchars((string)$v, ENT_QUOTES, 'UTF-8'); }
?>
<!DOCTYPE html>
<html lang="zh-Hant">
<head>
<meta charset="utf-8">
<meta http-equiv="X-UA-Compatible" content="IE=edge">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>出貨分析</title>
<link href="../../resource/css/bootstrap.css" rel="stylesheet">
<link href="../../resource/css/font-awesome.css" rel="stylesheet">
<link href="../../resource/css/nprogress.css" rel="stylesheet">
<link href="../../resource/css/custom.css" rel="stylesheet">
<style>
/* 側欄：CSS 藏起來、ready 時再顯示（鐵律6，CSS 與 JS 必須成對） */
#sidebar-menu { visibility: hidden; }
/* 藍色系配色（使用者明確要求：方便與訂單分析的暖色系區分；語意色維持全站通用：綠=正面/紅=需處理） */
:root{ --ink:#12324D; --cream:#F2F8FD; --sand:#DCEBFA; --blue:#2E7FD6; --blue-d:#1B5FA8;
       --coral:#D64545; --line:#C7DEF2; --muted:#6C89A6; --navy2:#3E6B96; }
body { background:#EFF5FB; }
.right_col .page-title { margin:8px 0 4px; overflow:hidden; clear:both; }
.page-title h3 { color:var(--ink); margin:0; display:flex; align-items:center; gap:10px; flex-wrap:wrap; font-size:22px; }
.page-help-btn { height:30px; font-size:13px; padding:0 12px; border:1px solid var(--blue-d);
                 border-radius:15px; background:#fff; color:var(--blue-d); }
.page-help-btn:hover { background:var(--blue-d); color:#fff; }
@media print { .page-help-btn { display:none !important; } }
.role-tag { font-size:12px; background:#E2F0FC; color:#1B4F78; border-radius:10px; padding:2px 10px; font-weight:normal; }
.warm-panel { background:#fff; border:1px solid var(--line); border-radius:8px; padding:12px 14px; margin-bottom:12px; }
.btn-warm { background:var(--blue); border:1px solid var(--blue-d); color:#fff; font-weight:bold; }
.btn-warm:hover,.btn-warm:focus { background:var(--blue-d); color:#fff; }
.btn-warm-o { background:#fff; border:1px solid var(--blue-d); color:var(--blue-d); }
.btn-warm-o:hover { background:var(--sand); color:var(--ink); }
.oa-bar { display:flex; gap:8px; align-items:center; flex-wrap:wrap; }
.oa-bar label { margin:0; font-size:12px; color:var(--ink); font-weight:600; }
.oa-note { background:#F7FBFF; border:1px solid var(--line); border-left:4px solid var(--blue);
           border-radius:6px; padding:8px 12px; font-size:12px; color:#1B4F78; line-height:1.8; margin-bottom:12px; }
.oa-note b { color:var(--coral); }
.oa-warn { background:#FDF0EF; border-left-color:var(--coral); }
.kpi-row { display:flex; gap:10px; flex-wrap:wrap; margin-bottom:12px; }
.kpi-card { flex:1 1 150px; min-width:150px; background:#fff; border:1px solid var(--line);
            border-top:3px solid var(--blue); border-radius:8px; padding:10px 12px; }
.kpi-card .k-lab { font-size:12px; color:var(--muted); }
.kpi-card .k-val { font-size:24px; font-weight:700; color:var(--ink); line-height:1.2; word-break:break-all; }
.kpi-card .k-sub { font-size:11px; color:var(--muted); margin-top:2px; }
.kpi-card.k-warn { border-top-color:var(--coral); }
.up   { color:#2E7D32; font-weight:700; }
.down { color:var(--coral); font-weight:700; }
.flat { color:var(--muted); }
.sec { background:#fff; border:1px solid var(--line); border-radius:8px; padding:12px 14px; margin-bottom:14px; }
.sec h4 { margin:0 0 4px; font-size:16px; color:var(--ink); font-weight:700;
          display:flex; align-items:center; gap:8px; flex-wrap:wrap; }
.sec h4 .hint { font-size:11px; color:var(--muted); font-weight:normal; }
.sec-tools { margin-left:auto; display:flex; gap:6px; align-items:center; flex-wrap:wrap; }
.chart-box { width:100%; height:300px; }
.chart-box.tall { height:360px; }
.two-col { display:flex; gap:14px; flex-wrap:wrap; }
.two-col > div { flex:1 1 380px; min-width:300px; }
table.oa-t { width:100%; border-collapse:collapse; font-size:12px; table-layout:fixed; }
table.oa-t th, table.oa-t td { border:1px solid var(--line); padding:4px 6px; vertical-align:middle;
                               word-break:break-all; line-height:1.5; }
table.oa-t th { background:#F3F9FE; color:#1B4F78; font-weight:700; text-align:center; white-space:nowrap; }
table.oa-t td.n { text-align:right; font-variant-numeric:tabular-nums; }
table.oa-t tbody tr:nth-child(even) { background:#FBFDFF; }
.tbl-wrap { max-height:420px; overflow:auto; border:1px solid var(--line); border-radius:6px; }
.tbl-wrap table.oa-t th { position:sticky; top:0; z-index:2; }
/* 表格裡「本期這組欄位」與「基期這組欄位」相鄰時，中間要有明顯分隔線才能一眼看出分界 */
table.oa-t th.grp-div, table.oa-t td.grp-div { border-right:3px solid var(--blue-d); }
.badge-new  { background:var(--coral); color:#fff; border-radius:9px; padding:1px 7px; font-size:10px; line-height:16px; display:inline-block; }
.badge-return { background:#BFE0FF; color:#1B4F78; border-radius:9px; padding:1px 7px; font-size:10px; line-height:16px; display:inline-block; }
.badge-lost { background:#5B7A99; color:#fff; border-radius:9px; padding:1px 7px; font-size:10px; line-height:16px; display:inline-block; }
.badge-warn { background:var(--sand); color:#1B4F78; border-radius:9px; padding:1px 7px; font-size:10px; line-height:16px; display:inline-block; }
.badge-anom { background:#FCE9E7; color:var(--coral); border:1px solid var(--coral); border-radius:9px; padding:0 6px; font-size:10px; line-height:16px; display:inline-block; }
/* AS 稽核分類：AS（kind=process，管理員設定的稽核製程）／非AS（固定選項＋管理員自訂其他選項），
   分組規則與 NewOrder_Track.php 下拉分組同一套（kind==='process'） */
.badge-as    { background:var(--coral); color:#fff; border-radius:4px; padding:1px 6px; font-size:10px; font-weight:700; line-height:16px; display:inline-block; margin-right:4px; }
.badge-nonas { background:#C7DEF2; color:#1B4F78; border-radius:4px; padding:1px 6px; font-size:10px; font-weight:700; line-height:16px; display:inline-block; margin-right:4px; }
.badge-tag   { background:var(--sand); color:#1B4F78; border:1px solid var(--line); border-radius:4px; padding:1px 6px;
               font-size:10px; line-height:16px; display:inline-block; margin:1px 3px 1px 0; }
.rec-actions { margin:4px 0 0; padding-left:18px; font-size:12px; color:#1B4F78; line-height:1.7; }
.rec-actions li { margin-bottom:1px; }
.chips { display:flex; gap:5px; flex-wrap:wrap; align-items:center; }
.chip { background:var(--sand); color:#1B4F78; border:1px solid var(--line); border-radius:12px;
        padding:1px 8px; font-size:12px; line-height:19px; }
.chip i { cursor:pointer; margin-left:4px; color:#B24A3A; }
.m-mask { position:fixed; inset:0; background:rgba(18,50,77,.45); z-index:10300; display:none; }
.m-win  { background:#fff; border-radius:8px; width:760px; max-width:95vw; margin:4vh auto;
          box-shadow:0 8px 30px rgba(0,0,0,.3); display:flex; flex-direction:column; max-height:92vh; }
.m-head { padding:10px 14px; border-bottom:1px solid var(--line); font-weight:700; color:var(--ink);
          display:flex; align-items:center; }
.m-head .x { margin-left:auto; cursor:pointer; color:var(--muted); }
.m-body { padding:14px; overflow:auto; }
.m-foot { padding:10px 14px; border-top:1px solid var(--line); text-align:right; }
.help-doc h4 { color:var(--blue-d); font-size:15px; margin:14px 0 6px; }
.help-doc li { margin-bottom:4px; line-height:1.7; }
.rm-in { width:100%; border:1px solid var(--line); border-radius:4px; padding:2px 6px; font-size:12px; }
.err-txt { color:var(--coral); font-size:12px; margin-top:6px; white-space:pre-line; }
.ins-list { display:flex; flex-direction:column; gap:6px; }
.ins { display:flex; gap:10px; align-items:flex-start; border:1px solid var(--line); border-left-width:4px;
       border-radius:6px; padding:7px 10px; background:#FBFDFF; }
.ins .ic { font-size:15px; line-height:20px; width:18px; text-align:center; flex:0 0 18px; }
.ins .bd { flex:1 1 auto; min-width:0; }
.ins .tt { font-weight:700; color:var(--ink); font-size:13px; }
.ins .dt { font-size:12px; color:#1B4F78; line-height:1.7; }
.ins .mt { flex:0 0 auto; font-weight:700; font-size:13px; white-space:nowrap; }
.ins-bad  { border-left-color:var(--coral); }  .ins-bad  .ic,.ins-bad  .mt { color:var(--coral); }
.ins-warn { border-left-color:var(--blue); }   .ins-warn .ic,.ins-warn .mt { color:var(--blue-d); }
.ins-good { border-left-color:#4F8A4F; }       .ins-good .ic,.ins-good .mt { color:#2E7D32; }
.ins-info { border-left-color:#9BB7CE; }       .ins-info .ic,.ins-info .mt { color:var(--muted); }
.ins-toggle { margin-left:8px; font-size:11px; font-weight:600; color:var(--blue-d); cursor:pointer; white-space:nowrap; }
.ins-toggle:hover { color:var(--coral); }
.ins-cli-wrap { margin-top:6px; padding-top:6px; border-top:1px dashed var(--line); }
.ins-cli-grid { display:grid; gap:3px 14px; }
.ins-cli-head { margin-bottom:3px; }
.ins-cli-hcell { display:flex; justify-content:space-between; gap:8px; font-size:10px; font-weight:700; color:var(--muted);
                 text-transform:uppercase; letter-spacing:.3px; padding-bottom:3px; border-bottom:1px solid var(--line); }
.ins-cli-cell { display:flex; justify-content:space-between; gap:8px; font-size:12px; padding:2px 0; }
.ins-cli-name { color:#1B4F78; overflow:hidden; text-overflow:ellipsis; white-space:nowrap; }
.ins-cli-name a { color:var(--blue-d); border-bottom:1px dotted var(--blue-d); cursor:pointer; }
.ins-cli-name a:hover { color:var(--coral); border-bottom-color:var(--coral); }
.ins-cli-amt { color:var(--muted); font-variant-numeric:tabular-nums; white-space:nowrap; }
.pno-link { color:var(--blue-d); border-bottom:1px dotted var(--blue-d); cursor:pointer; }
.pno-link:hover { color:var(--coral); border-bottom-color:var(--coral); }
.nav-jump { display:flex; gap:6px; flex-wrap:wrap; margin-bottom:10px; }
.nav-jump a { font-size:12px; border:1px solid var(--line); background:#fff; color:#1B4F78;
              padding:3px 10px; border-radius:12px; text-decoration:none; }
.nav-jump a:hover { background:var(--sand); }
/* 頁內分頁（分類／項目太多時，表格分頁顯示用） */
.oa-pager { display:flex; align-items:center; gap:8px; font-size:12px; color:var(--muted); flex-wrap:wrap; }
.oa-pager button { border:1px solid var(--line); background:#fff; color:#1B4F78; border-radius:4px;
                    padding:2px 10px; font-size:12px; cursor:pointer; }
.oa-pager button:hover:not(:disabled) { background:var(--sand); }
.oa-pager button:disabled { opacity:.4; cursor:default; }
/* 右側懸浮工具列：回頂端／快速導覽（比照 Order_Analysis.php 同一套；「回出貨紀錄分析」
   已經在頁首有連結，不在這裡重複放一顆，注意透明度避免遮蔽圖表） */
.float-tools { position:fixed; right:18px; bottom:18px; z-index:9000;
               display:flex; flex-direction:column-reverse; align-items:flex-end; gap:10px; }
@media print { .float-tools { display:none !important; } }
.float-btn { width:52px; height:52px; border-radius:50%; border:none; cursor:pointer; text-decoration:none;
             display:flex; flex-direction:column; align-items:center; justify-content:center;
             color:#fff; font-size:10px; font-weight:600; line-height:1.1;
             background:linear-gradient(135deg,#1B5FA8,#2E7FD6); box-shadow:0 4px 14px rgba(0,0,0,.22);
             opacity:.55; transition:opacity .15s,filter .15s; }
.float-btn i { font-size:17px; margin-bottom:1px; }
.float-btn:hover, .float-btn:focus { color:#fff; text-decoration:none; opacity:1; filter:brightness(1.08); }
.float-btn.totop { width:44px; height:44px; display:none; }
.float-btn.totop i { font-size:16px; margin-bottom:0; }
/* 快速導覽：縮成圖示，滑鼠移過才展開清單 */
.qnav { position:relative; }
.qnav-fab { width:44px; height:44px; border-radius:50%; border:1px solid var(--line); cursor:pointer;
            background:#fff; color:var(--blue-d); box-shadow:0 4px 12px rgba(0,0,0,.18); font-size:16px;
            opacity:.55; transition:opacity .15s; display:flex; align-items:center; justify-content:center; }
.qnav:hover .qnav-fab, .qnav-fab:focus { opacity:1; }
.qnav-panel { position:absolute; right:52px; bottom:0; min-width:150px;
              background:rgba(250,253,255,.97); border:1px solid var(--line); border-radius:10px;
              box-shadow:0 6px 18px rgba(0,0,0,.2); padding:8px; display:flex; flex-direction:column; gap:3px;
              opacity:0; pointer-events:none; transform:translateX(6px); transition:opacity .15s,transform .15s; }
.qnav:hover .qnav-panel, .qnav-panel:hover { opacity:1; pointer-events:auto; transform:translateX(0); }
.qnav-panel a { font-size:12px; color:#1B4F78; padding:5px 10px; border-radius:6px; text-decoration:none; white-space:nowrap; }
.qnav-panel a:hover { background:var(--sand); }
/* 明細分頁（出貨明細/退貨單/訂單/客戶統計） */
.list-tabs { display:flex; gap:4px; margin-bottom:8px; flex-wrap:wrap; }
.list-tab-btn { background:#F3F9FE; border:1px solid var(--line); border-radius:6px 6px 0 0; padding:6px 14px;
                font-size:13px; color:var(--ink); cursor:pointer; }
.list-tab-btn.active { background:var(--blue); color:#fff; border-color:var(--blue-d); font-weight:700; }
.pager { display:flex; gap:6px; align-items:center; margin-top:8px; flex-wrap:wrap; }
.pager button { background:#fff; border:1px solid var(--line); border-radius:4px; padding:2px 10px; font-size:12px; cursor:pointer; }
.pager button:disabled { opacity:.4; cursor:default; }
.pager .pg-info { font-size:12px; color:var(--muted); }
/* 客戶季度分析 */
.cq-sub-tabs { display:flex; gap:4px; margin-bottom:8px; }
.cq-sub-btn { background:#F3F9FE; border:1px solid var(--line); border-radius:6px; padding:5px 14px; font-size:13px; cursor:pointer; color:var(--ink); }
.cq-sub-btn.active { background:var(--blue); color:#fff; border-color:var(--blue-d); font-weight:700; }
/* 頂層分頁（比照訂單分析：總覽分析／交期與急件分析，不再往下一直累加） */
.oa-maintabs { display:flex; gap:8px; flex-wrap:wrap; margin-bottom:12px; }
.oa-mtab { border:1px solid var(--blue-d); background:#fff; color:var(--blue-d); font-weight:600;
           font-size:13px; padding:7px 16px; border-radius:16px; cursor:pointer; }
.oa-mtab:hover { background:var(--sand); }
.oa-mtab.active { background:var(--blue-d); color:#fff; }
.ltu-cell { padding:3px 0; border-bottom:1px dashed var(--line); }
.ltu-cell .l1 { display:flex; justify-content:space-between; gap:8px; font-size:12px; }
.ltu-cell .l1 .amt { color:var(--ink); font-weight:600; font-variant-numeric:tabular-nums; white-space:nowrap; }
.ltu-cell .l2 { display:flex; justify-content:space-between; gap:8px; font-size:10.5px; color:var(--muted); margin-top:1px; }
</style>
</head>
<body class="nav-sm">
<div class="container body"><div class="main_container">
<?php include '../partPage/sideAndTopBarMenu.html'; ?>
<div class="right_col" role="main">

  <div class="page-title">
    <h3><i class="fa fa-truck" style="color:var(--blue-d);"></i> 出貨分析
      <span class="role-tag">目前身分：<?= siEsc($roleLabel) ?></span>
      <a href="Shipping_Analysis_new.php" class="btn btn-xs btn-warm-o" style="font-weight:600;">
        <i class="fa fa-arrow-left"></i> 回出貨紀錄分析</a>
      <button id="btnPageHelp" class="page-help-btn" style="margin-left:auto;"><i class="fa fa-question-circle"></i> 使用說明</button>
    </h3>
  </div>

<?php if (!$canView): ?>
  <div class="warm-panel" style="color:var(--coral);">
    您沒有出貨分析的檢視權限。請洽管理員指派「快速出貨」模組的 <code>shipping_view</code> 以上角色。
  </div>
<?php else: ?>

  <!-- ── 條件列 ─────────────────────────────────────────── -->
  <div class="warm-panel">
    <div class="oa-bar">
      <label>年度</label>
      <select id="fYear" class="form-control input-sm" style="width:92px;">
        <?php for ($y = $thisYear + 1; $y >= $thisYear - 4; $y--): ?>
          <option value="<?= $y ?>" <?= $y === $thisYear ? 'selected' : '' ?>><?= $y ?></option>
        <?php endfor; ?>
      </select>
      <label>期間</label>
      <select id="fGran" class="form-control input-sm" style="width:82px;">
        <option value="month">月</option>
        <option value="quarter" selected>季</option>
        <option value="half">半年</option>
        <option value="year">整年</option>
      </select>
      <select id="fIdx" class="form-control input-sm" style="width:118px;"></select>
      <label>比較基準</label>
      <select id="fCmp" class="form-control input-sm" style="width:110px;">
        <option value="yoy">去年同期</option>
        <option value="prev">上一期</option>
      </select>
      <label style="font-weight:normal;"><input type="checkbox" id="cbAlign" checked data-eg-skip>
        進行中的期間與基期對齊天數</label>
      <span class="sec-tools">
        <button class="btn btn-sm btn-warm" id="btnReload"><i class="fa fa-refresh"></i> 重新計算</button>
        <button class="btn btn-sm btn-warm-o" id="btnPrint"><i class="fa fa-print"></i> 列印報告</button>
        <button class="btn btn-sm btn-warm-o" id="btnCsv"><i class="fa fa-file-excel-o"></i> CSV</button>
      </span>
    </div>
    <div class="oa-bar" style="margin-top:8px;">
      <label>客戶篩選</label>
      <button class="btn btn-xs btn-warm-o" id="btnCliPick"><i class="fa fa-users"></i> 選擇客戶（可多選比較）</button>
      <button class="btn btn-xs btn-default" id="btnCliClear" style="display:none;">清除</button>
      <span class="chips" id="cliChips"><span style="font-size:12px;color:var(--muted);">未篩選＝全部客戶</span></span>
      <label style="margin-left:10px;">出貨性質</label>
      <button class="btn btn-xs btn-warm-o" id="btnStPick"><i class="fa fa-filter"></i> 篩選出貨性質</button>
      <span style="font-size:12px;color:var(--muted);" id="stFilterLabel">預設（排除不列入統計的性質）</span>
      <span class="sec-tools">
        <button class="btn btn-xs btn-warm-o" id="btnCutoff"><i class="fa fa-calendar-check-o"></i> 月份截止日</button>
        <button class="btn btn-xs btn-warm-o" id="btnSaleTypeSetting"><i class="fa fa-tags"></i> 出貨性質設定</button>
        <button class="btn btn-xs btn-warm-o" id="btnAnomaly"><i class="fa fa-exclamation-triangle"></i> 異常偵測</button>
        <?php if ($canAdmin): ?>
        <button class="btn btn-xs btn-warm-o" id="btnSetting"><i class="fa fa-cog"></i> 監控設定</button>
        <?php endif; ?>
      </span>
    </div>
  </div>

  <!-- ── 頂層分頁：總覽分析／交期與急件分析（比照訂單分析，不要一直往下累加）── -->
  <div class="oa-maintabs">
    <button type="button" class="oa-mtab active" data-tab="overview"><i class="fa fa-bar-chart"></i> 總覽分析</button>
    <button type="button" class="oa-mtab" data-tab="leadtime"><i class="fa fa-clock-o"></i> 交期與急件分析</button>
  </div>

  <div class="oa-tabpanel" data-tab="overview">
  <div id="noteBar"></div>
  <div id="kpiAlertBar"></div>
  <div class="nav-jump">
    <a href="#secInsight">自動分析</a><a href="#secRecommend">建議採取</a><a href="#secTrend">相關金額趨勢</a><a href="#secSaleType">出貨性質分布</a>
    <a href="#secAstag">AS 稽核分類</a>
    <a href="#secClient">客戶比較</a><a href="#secRank">客戶增減排名</a><a href="#secMa">出貨淨額監控</a>
    <a href="#secCq">客戶季度分析</a><a href="#secList">出貨明細／退貨單／訂單／客戶統計</a>
  </div>

  <div id="kpiRow" class="kpi-row"></div>

  <!-- ── 自動分析 ─────────────────────────────────────── -->
  <div class="sec" id="secInsight">
    <h4><i class="fa fa-lightbulb-o" style="color:var(--coral);"></i> 自動分析
      <span class="hint">系統直接把「要自己盯著圖表看才發現得了」的事寫成結論，每一條都附數字</span>
    </h4>
    <div id="insightList" class="ins-list"></div>
  </div>

  <!-- ── 建議採取 ─────────────────────────────────────── -->
  <div class="sec" id="secRecommend">
    <h4><i class="fa fa-flag-o" style="color:var(--coral);"></i> 建議採取
      <span class="hint">綜合上方「自動分析」各項結論，整理成業務可以實際動手做的行動清單</span>
    </h4>
    <div id="recommendList" class="ins-list"></div>
  </div>

  <!-- ── 相關金額趨勢 ─────────────────────────────────── -->
  <div class="sec" id="secTrend">
    <h4><i class="fa fa-line-chart" style="color:var(--blue-d);"></i> 相關金額趨勢
      <span class="hint" id="trendHint"></span>
      <span class="sec-tools">
        <label style="margin:0;font-weight:normal;font-size:12px;">
          <input type="checkbox" id="cbPrevYear" checked data-eg-skip> 疊上去年同期</label>
      </span>
    </h4>
    <div id="chTrend" class="chart-box tall"></div>
    <div class="tbl-wrap" style="margin-top:10px;">
      <table class="oa-t" id="tblTrend">
        <colgroup><col style="width:16%"><col style="width:14%"><col style="width:14%"><col style="width:14%">
                  <col style="width:14%"><col style="width:14%"><col style="width:14%"></colgroup>
        <thead><tr><th>期別</th><th>出貨金額</th><th>退貨金額</th><th>訂單金額</th><th>淨額</th><th>出貨筆數</th><th>退貨筆數</th></tr></thead>
        <tbody></tbody>
      </table>
    </div>
  </div>

  <!-- ── 出貨性質分布 ─────────────────────────────────── -->
  <div class="sec" id="secSaleType">
    <h4><i class="fa fa-pie-chart" style="color:var(--blue-d);"></i> 出貨性質分布（本期）
      <span class="hint">依上方「出貨性質」篩選的範圍統計；可到「出貨性質設定」調整哪些性質列入統計</span>
    </h4>
    <div class="two-col">
      <div><div id="chSaleType" class="chart-box"></div></div>
      <div>
        <table class="oa-t" id="tblSaleType">
          <colgroup><col style="width:40%"><col style="width:20%"><col style="width:20%"><col style="width:20%"></colgroup>
          <thead><tr><th>出貨性質</th><th>筆數</th><th>數量</th><th>金額</th></tr></thead>
          <tbody></tbody>
        </table>
      </div>
    </div>
  </div>

  <!-- ── AS 稽核分類（透過出貨單綁定的訂單反查「稽核製程標籤 AS 認定」）────── -->
  <div class="sec" id="secAstag">
    <h4><i class="fa fa-certificate" style="color:var(--blue-d);"></i> AS 稽核分類分析
      <span class="hint" id="astagHint"></span>
      <span class="sec-tools">
        <label style="margin:0;font-size:12px;">圖表顯示</label>
        <select id="astagMetric" class="form-control input-sm" style="width:100px;">
          <option value="amount">金額</option>
          <option value="qty">數量</option>
          <option value="rows">筆數</option>
        </select>
      </span>
    </h4>
    <div class="two-col">
      <div><div id="chAstagPie" class="chart-box"></div></div>
      <div><div id="chAstagTrend" class="chart-box"></div></div>
    </div>
    <table class="oa-t" id="tblAstag" style="margin-top:10px;">
      <colgroup><col style="width:24%"><col style="width:13%"><col style="width:13%"><col style="width:17%">
                <col style="width:13%"><col style="width:20%"></colgroup>
      <thead><tr><th>分類（AS 認定）</th><th>本期筆數</th><th>本期數量</th><th class="grp-div">本期出貨金額</th>
                 <th>基期筆數</th><th>較<span class="cmpLab">基期</span>增減</th></tr></thead>
      <tbody></tbody>
    </table>
    <div class="oa-pager" id="astagPager" style="margin-top:6px;"></div>
  </div>

  <!-- ── 客戶比較 ─────────────────────────────────────── -->
  <div class="sec" id="secClient">
    <h4><i class="fa fa-users" style="color:var(--blue-d);"></i> 客戶比較表
      <span class="hint" id="cliHint"></span>
      <span class="sec-tools">
        <label style="margin:0;font-size:12px;">圖表顯示</label>
        <select id="cliMetric" class="form-control input-sm" style="width:100px;">
          <option value="ship">出貨金額</option><option value="ret">退貨金額</option>
          <option value="ord">訂單金額</option><option value="net" selected>淨額</option>
        </select>
      </span>
    </h4>
    <div class="sec-tools" style="margin-bottom:8px;">
      <button type="button" class="btn btn-xs btn-primary" id="cliTabAll" onclick="cliTab('all')">全部</button>
      <button type="button" class="btn btn-xs btn-default" id="cliTabAstag" onclick="cliTab('astag')">依訂單標籤分類</button>
      <button type="button" class="btn btn-xs btn-default" id="cliTabCls" onclick="cliTab('cls')">依全製/多製程/單製</button>
    </div>
    <div id="chClient" class="chart-box tall"></div>
    <div class="tbl-wrap" style="margin-top:10px;">
      <table class="oa-t" id="tblClient">
        <colgroup id="tblClientCols"><col style="width:16%"><col style="width:13%"><col style="width:13%"><col style="width:13%">
                  <col style="width:13%"><col style="width:13%"><col style="width:19%"></colgroup>
        <thead id="tblClientHead"><tr><th>客戶</th><th>出貨</th><th>退貨</th><th>訂單</th><th>淨額</th><th>異常筆數</th><th>較<span class="cmpLab">基期</span>淨額增減</th></tr></thead>
        <tbody></tbody>
      </table>
    </div>
  </div>

  <!-- ── 客戶增減排名 ─────────────────────────────────── -->
  <div class="sec" id="secRank">
    <h4><i class="fa fa-exchange" style="color:var(--blue-d);"></i> 期間內客戶增減排名（依淨額增減）
      <span class="hint" id="rankHint"></span>
      <span class="sec-tools">
        <label style="margin:0;font-size:12px;">顯示筆數</label>
        <select id="rankTop" class="form-control input-sm" style="width:80px;">
          <option>10</option><option selected>15</option><option>20</option><option>30</option>
        </select>
      </span>
    </h4>
    <div id="chRank" class="chart-box tall"></div>
    <div class="two-col" style="margin-top:10px;">
      <div>
        <div style="font-size:13px;font-weight:700;color:#2E7D32;margin-bottom:4px;">成長客戶</div>
        <div class="tbl-wrap" style="max-height:300px;">
          <table class="oa-t" id="tblRankUp">
            <colgroup><col style="width:26%"><col style="width:25%"><col style="width:25%"><col style="width:24%"></colgroup>
            <thead><tr><th>客戶</th><th>本期</th><th>基期</th><th>增減</th></tr></thead><tbody></tbody>
          </table>
        </div>
      </div>
      <div>
        <div style="font-size:13px;font-weight:700;color:var(--coral);margin-bottom:4px;">衰退客戶</div>
        <div class="tbl-wrap" style="max-height:300px;">
          <table class="oa-t" id="tblRankDown">
            <colgroup><col style="width:26%"><col style="width:25%"><col style="width:25%"><col style="width:24%"></colgroup>
            <thead><tr><th>客戶</th><th>本期</th><th>基期</th><th>增減</th></tr></thead><tbody></tbody>
          </table>
        </div>
      </div>
    </div>
  </div>

  <!-- ── 出貨淨額監控（移動平均）───────────────────────── -->
  <div class="sec" id="secMa">
    <h4><i class="fa fa-heartbeat" style="color:var(--blue-d);"></i> 出貨淨額監控（金額移動平均）
      <span class="hint" id="maHint"></span>
    </h4>
    <div id="maNote" class="oa-note" style="margin-bottom:10px;"></div>
    <div id="chMa" class="chart-box"></div>
    <table class="oa-t" id="tblMa" style="margin-top:10px;">
      <colgroup><col style="width:14%"><col style="width:20%"><col style="width:20%"><col style="width:20%"><col style="width:26%"></colgroup>
      <thead><tr><th>月份</th><th>當月出貨淨額</th><th>前 N 月移動平均</th><th>安全水平</th><th>判定</th></tr></thead>
      <tbody></tbody>
    </table>
  </div>

  <!-- ── 客戶季度分析 ─────────────────────────────────── -->
  <div class="sec" id="secCq">
    <h4><i class="fa fa-calendar" style="color:var(--blue-d);"></i> 客戶季度分析
      <span class="hint">帳款季（依月份截止日）逐季走勢；計算與 Shipping_Analysis_new.php 同一套共用庫，數字一致</span>
    </h4>
    <div class="cq-sub-tabs">
      <button class="cq-sub-btn active" id="cqTabTrendBtn" onclick="cqSwitchTab('trend')">客戶趨勢</button>
      <button class="cq-sub-btn" id="cqTabRankBtn" onclick="cqSwitchTab('rank')">成長／衰退排行</button>
    </div>
    <div id="cqPaneTrend">
      <div class="oa-bar" style="margin-bottom:8px;">
        <label>客戶</label>
        <select id="cqClient" class="form-control input-sm" style="width:220px;" data-eg-filter="輸入客戶名稱篩選…">
          <option value="">全部客戶合計</option>
        </select>
        <label>往前比較</label>
        <select id="cqYearsBack" class="form-control input-sm" style="width:80px;">
          <option value="1">1 年</option><option value="2">2 年</option><option value="3">3 年</option>
        </select>
        <button class="btn btn-xs btn-warm-o" id="btnCqReload"><i class="fa fa-refresh"></i> 重新計算</button>
        <span id="cqQuality" style="font-size:11px;color:var(--muted);"></span>
      </div>
      <div id="chCqTrend" class="chart-box"></div>
      <div class="tbl-wrap" style="margin-top:10px;max-height:280px;">
        <table class="oa-t" id="tblCqTrend">
          <colgroup><col style="width:16%"><col style="width:20%"><col style="width:20%"><col style="width:22%"><col style="width:22%"></colgroup>
          <thead><tr><th>季</th><th>訂單</th><th>出貨</th><th>退貨</th><th>淨額</th></tr></thead><tbody></tbody>
        </table>
      </div>
    </div>
    <div id="cqPaneRank" style="display:none;">
      <div class="oa-bar" style="margin-bottom:8px;">
        <label>季別</label>
        <select id="cqRankYear" class="form-control input-sm" style="width:90px;"></select>
        <select id="cqRankQ" class="form-control input-sm" style="width:80px;">
          <option value="1">Q1</option><option value="2">Q2</option><option value="3">Q3</option><option value="4">Q4</option>
        </select>
        <label>比較</label>
        <select id="cqRankCmp" class="form-control input-sm" style="width:100px;">
          <option value="yoy">去年同季</option><option value="qoq">上一季</option>
        </select>
        <label>指標</label>
        <select id="cqRankMetric" class="form-control input-sm" style="width:110px;">
          <option value="net">淨額</option><option value="ship">出貨金額</option><option value="ord">訂單金額</option>
        </select>
        <button class="btn btn-xs btn-warm-o" id="btnCqRankReload"><i class="fa fa-refresh"></i> 重新計算</button>
      </div>
      <div id="chCqRank" class="chart-box tall"></div>
      <div class="two-col" style="margin-top:10px;">
        <div>
          <div style="font-size:13px;font-weight:700;color:#2E7D32;margin-bottom:4px;">成長客戶</div>
          <div class="tbl-wrap" style="max-height:280px;"><table class="oa-t" id="tblCqUp">
            <colgroup><col style="width:34%"><col style="width:22%"><col style="width:22%"><col style="width:22%"></colgroup>
            <thead><tr><th>客戶</th><th>本季</th><th>比較季</th><th>增減</th></tr></thead><tbody></tbody></table></div>
        </div>
        <div>
          <div style="font-size:13px;font-weight:700;color:var(--coral);margin-bottom:4px;">衰退客戶</div>
          <div class="tbl-wrap" style="max-height:280px;"><table class="oa-t" id="tblCqDown">
            <colgroup><col style="width:34%"><col style="width:22%"><col style="width:22%"><col style="width:22%"></colgroup>
            <thead><tr><th>客戶</th><th>本季</th><th>比較季</th><th>增減</th></tr></thead><tbody></tbody></table></div>
        </div>
      </div>
    </div>
  </div>

  <!-- ── 明細分頁 ─────────────────────────────────────── -->
  <div class="sec" id="secList">
    <h4><i class="fa fa-list" style="color:var(--blue-d);"></i> 明細資料
      <span class="hint">只列目前選定的期間，不受年度整體影響</span>
      <span class="sec-tools">
        <input type="text" id="listKw" class="form-control input-sm" style="width:180px;" data-eg-hint="輸入客戶/料號/單號篩選">
      </span>
    </h4>
    <div class="list-tabs">
      <button class="list-tab-btn active" id="tabBtnShip" onclick="listSwitchTab('ship')"><i class="fa fa-truck"></i> 出貨明細</button>
      <button class="list-tab-btn" id="tabBtnReturn" onclick="listSwitchTab('return')"><i class="fa fa-undo"></i> 退貨單</button>
      <button class="list-tab-btn" id="tabBtnOrder" onclick="listSwitchTab('order')"><i class="fa fa-file-text-o"></i> 訂單</button>
      <button class="list-tab-btn" id="tabBtnCustomer" onclick="listSwitchTab('customer')"><i class="fa fa-users"></i> 客戶統計</button>
    </div>
    <div id="paneShip">
      <div class="tbl-wrap"><table class="oa-t" id="tblShip">
        <colgroup><col style="width:11%"><col style="width:11%"><col style="width:15%"><col style="width:13%"><col style="width:20%"><col style="width:8%"><col style="width:9%"><col style="width:13%"></colgroup>
        <thead><tr><th>出貨日期</th><th>出貨單號</th><th>客戶</th><th>料號</th><th>規格</th><th>數量</th><th>單價</th><th>金額</th></tr></thead>
        <tbody></tbody></table></div>
      <div class="pager" id="pagerShip"></div>
    </div>
    <div id="paneReturn" style="display:none;">
      <div class="tbl-wrap"><table class="oa-t" id="tblReturn">
        <colgroup><col style="width:11%"><col style="width:11%"><col style="width:15%"><col style="width:13%"><col style="width:8%"><col style="width:9%"><col style="width:11%"><col style="width:22%"></colgroup>
        <thead><tr><th>退貨日期</th><th>退貨單號</th><th>客戶</th><th>料號</th><th>數量</th><th>單價</th><th>金額</th><th>原因/說明</th></tr></thead>
        <tbody></tbody></table></div>
      <div class="pager" id="pagerReturn"></div>
    </div>
    <div id="paneOrder" style="display:none;">
      <div class="tbl-wrap"><table class="oa-t" id="tblOrder">
        <colgroup><col style="width:11%"><col style="width:11%"><col style="width:15%"><col style="width:13%"><col style="width:8%"><col style="width:9%"><col style="width:11%"><col style="width:11%"><col style="width:11%"></colgroup>
        <thead><tr><th>下單日期</th><th>訂單號</th><th>客戶</th><th>料號</th><th>數量</th><th>單價</th><th>金額</th><th>交期</th><th>狀態</th></tr></thead>
        <tbody></tbody></table></div>
      <div class="pager" id="pagerOrder"></div>
    </div>
    <div id="paneCustomer" style="display:none;">
      <div class="tbl-wrap"><table class="oa-t" id="tblCustomer">
        <colgroup><col style="width:16%"><col style="width:12%"><col style="width:12%"><col style="width:12%"><col style="width:12%"><col style="width:12%"><col style="width:24%"></colgroup>
        <thead><tr><th>客戶</th><th>出貨</th><th>退貨</th><th>訂單</th><th>淨額</th><th>異常筆數</th><th>狀態</th></tr></thead>
        <tbody></tbody></table></div>
    </div>
  </div>
  </div><!-- /oa-tabpanel overview -->

  <!-- ══════════════════════════════════════════════════════════════════
       交期與急件分析（2026-10-07 使用者交辦：整套比照訂單分析複製，資料一律即時
       呼叫 order_analysis_lib.php 的 oa_leadtime_report()／oa_urgent_insights()，
       跟訂單分析共用同一份管理員設定（急件百分位／排除下限），不另存一份。
       ══════════════════════════════════════════════════════════════════ -->
  <div class="oa-tabpanel" data-tab="leadtime" style="display:none;">
    <div class="warm-panel">
      <div class="oa-bar">
        <label>急件（交期過短）判定</label>
        <label style="font-weight:normal;">全製</label><input type="number" id="ltPctFull" class="rm-in" style="width:52px;" min="1" max="100">
        <label style="font-weight:normal;">%　多製程</label><input type="number" id="ltPctMulti" class="rm-in" style="width:52px;" min="1" max="100">
        <label style="font-weight:normal;">%　單製</label><input type="number" id="ltPctSingle" class="rm-in" style="width:52px;" min="1" max="100">
        <label style="font-weight:normal;">%</label>
        <button class="btn btn-xs btn-warm" id="btnLtApplyPct"><i class="fa fa-refresh"></i> 套用（僅本次計算）</button>
        <button class="btn btn-xs btn-default" id="btnLtResetPct">回復管理員預設值</button>
      </div>
      <div style="font-size:12px;color:var(--muted);margin-top:4px;">
        上面欄位預設帶入管理員目前設定的值，調整後按「套用」只影響這一次計算，<b>不會更改管理員的預設設定</b>；
        這份設定與「訂單分析」頁的「交期與急件分析」共用同一份，改其中一邊兩邊都會一起變動。
        要改管理員預設值請按右下「急件判定設定」。
      </div>
    </div>
    <div id="ltNote" class="oa-note"></div>
    <div id="ltKpiRow" class="kpi-row"></div>

    <div class="sec" id="secLtInsight">
      <h4><i class="fa fa-lightbulb-o" style="color:var(--coral);"></i> 急件自動分析
        <span class="hint">依「交期工作天數」短到長，依類別各自的百分位門檻自動判定是不是急件</span>
      </h4>
      <div id="ltInsightList" class="ins-list"></div>
    </div>

    <div class="sec" id="secLtDist">
      <h4><i class="fa fa-bar-chart" style="color:var(--blue-d);"></i> 交期工作天數分布（全製／多製程／單製）
        <?php if ($canAdmin): ?>
        <span class="sec-tools"><button class="btn btn-xs btn-warm-o" id="btnUrgentSetting"><i class="fa fa-cog"></i> 急件判定設定</button></span>
        <?php endif; ?>
      </h4>
      <div class="two-col">
        <div><div id="chLtBox" class="chart-box"></div></div>
        <div class="tbl-wrap" style="max-height:300px;">
          <table class="oa-t" id="tblLtCls">
            <colgroup><col style="width:24%"><col style="width:10%"><col style="width:16%"><col style="width:12%">
                      <col style="width:14%"><col style="width:12%"><col style="width:12%"></colgroup>
            <thead><tr><th>類別</th><th>筆數</th><th>平均工作天</th><th>中位數</th><th>急件門檻</th><th>急件數</th><th>急件比例</th></tr></thead>
            <tbody></tbody>
          </table>
        </div>
      </div>
    </div>

    <div class="sec" id="secLtShipDist">
      <h4><i class="fa fa-truck" style="color:var(--blue-d);"></i> 實際出貨工作天數分析（全製／多製程／單製）
        <span class="hint">依類別比較「承諾交期工作天」與「實際出貨工作天」；目前多數訂單尚未綁定出貨單，覆蓋率會誠實反映</span>
      </h4>
      <div class="tbl-wrap" style="max-height:300px;">
        <table class="oa-t" id="tblLtShipCls">
          <colgroup><col style="width:20%"><col style="width:10%"><col style="width:14%"><col style="width:14%">
                    <col style="width:16%"><col style="width:13%"><col style="width:13%"></colgroup>
          <thead><tr><th>類別</th><th>筆數</th><th>出貨綁定覆蓋率</th><th>平均交期工作天</th>
                     <th>平均實際出貨工作天</th><th>平均延誤天數</th><th>逾交期比例</th></tr></thead>
          <tbody></tbody>
        </table>
      </div>
    </div>

    <div class="sec" id="secLtBand">
      <h4><i class="fa fa-cubes" style="color:var(--blue-d);"></i> 數量區間與急件比例</h4>
      <div id="chLtBand" class="chart-box"></div>
    </div>

    <div class="sec" id="secLtClient">
      <h4><i class="fa fa-users" style="color:var(--blue-d);"></i> 急件客戶排行
        <span class="hint">本期共 <span id="ltClientCount">0</span> 家客戶有急件訂單</span>
      </h4>
      <div class="tbl-wrap">
        <table class="oa-t" id="tblLtClient">
          <colgroup><col style="width:5%"><col style="width:26%"><col style="width:15%"><col style="width:17%"><col style="width:17%"><col style="width:20%"></colgroup>
          <thead><tr><th>#</th><th>客戶</th><th>急件筆數</th><th>急件金額</th><th>佔急件總額</th><th id="tblLtClientCmpHead">較基期增減</th></tr></thead>
          <tbody></tbody>
        </table>
      </div>
    </div>

    <div class="sec" id="secLtList">
      <h4><i class="fa fa-list" style="color:var(--blue-d);"></i> 急件明細
        <span class="hint">依交期工作天數由短到長排序，最多列 200 筆</span>
      </h4>
      <div class="tbl-wrap">
        <table class="oa-t" id="tblLtList">
          <colgroup><col style="width:8%"><col style="width:8%"><col style="width:7%"><col style="width:9%"><col style="width:6%">
                    <col style="width:6%"><col style="width:6%"><col style="width:5%"><col style="width:11%"><col style="width:5%"><col style="width:7%">
                    <col style="width:10%"><col style="width:10%"></colgroup>
          <thead><tr><th>訂單號</th><th>KEY單日期/業務</th><th>客戶</th><th>料號</th><th>下單日</th><th>交期</th>
                     <th>工作天數</th><th title="去BOM_ING抓取不重複的製程站（bom_sn）數量；這張訂單還沒開立製令（BOM）時查不到，顯示「—」">製程數</th>
                     <th>類別</th><th>數量</th><th>金額</th><th>實際出貨</th><th>備註</th></tr></thead>
          <tbody></tbody>
        </table>
      </div>
    </div>
  </div><!-- /oa-tabpanel leadtime -->

<?php endif; ?>
</div><!-- /right_col -->
</div></div>

<!-- 右側懸浮工具列：回頂端／快速導覽（比照 Order_Analysis.php；列印時隱藏） -->
<div class="float-tools">
  <button type="button" class="float-btn totop" id="btnToTop" title="回頂端"
          onclick="window.scrollTo({top:0,behavior:'smooth'});"><i class="fa fa-arrow-up"></i>頂端</button>
  <div class="qnav">
    <button type="button" class="qnav-fab" title="快速導覽（各區塊）"><i class="fa fa-compass"></i></button>
    <div class="qnav-panel">
      <a href="#secInsight">自動分析</a>
      <a href="#secRecommend">建議採取</a>
      <a href="#secTrend">相關金額趨勢</a>
      <a href="#secSaleType">出貨性質分布</a>
      <a href="#secAstag">AS 稽核分類</a>
      <a href="#secClient">客戶比較</a>
      <a href="#secRank">客戶增減排名</a>
      <a href="#secMa">出貨淨額監控</a>
      <a href="#secCq">客戶季度分析</a>
      <a href="#secList">明細資料</a>
    </div>
  </div>
</div>

<!-- ── 使用說明（鐵律7）──────────────────────────────── -->
<div class="m-mask" id="helpUseMask">
  <div class="m-win" style="width:880px;">
    <div class="m-head">出貨分析 — 使用說明 <span class="x" data-close="helpUseMask">✕</span></div>
    <div class="m-body help-doc">
      <h4>功能說明（這一頁在回答什麼）</h4>
      <ul>
        <li><b>出貨／退貨／訂單金額的趨勢</b>：可切月／季／半年／整年，並疊上去年同期對照。</li>
        <li><b>出貨性質分布</b>：本期各出貨性質（一般產品、樣品…）各佔多少金額。</li>
        <li><b>AS 稽核分類</b>：出貨單透過已綁定的訂單反查訂單追蹤設定的「稽核製程標籤（AS 認定）」
            （全製／單製○○／多製程／廠內治具…），有趨勢圖與期間金額統計；分類由管理員在訂單追蹤的
            設定自動決定，分類太多時表格會自動分頁。</li>
        <li><b>客戶比較／客戶增減排名</b>：挑幾家客戶並排比較，或看整體誰成長、誰衰退（依淨額增減排序）。</li>
        <li><b>出貨淨額監控</b>：出貨金額移動平均是否連續低於安全水平。</li>
        <li><b>客戶季度分析</b>：帳款季逐季走勢與成長／衰退排行（與出貨紀錄分析頁同一套計算）。</li>
        <li><b>明細資料</b>：目前選定期間的出貨明細／退貨單／訂單／客戶統計四張表。</li>
        <li><b>自動分析</b>：把「要自己盯著圖表看才發現得了」的事直接寫成結論，每一條都附數字。</li>
      </ul>
      <h4>操作步驟</h4>
      <ol>
        <li>選「年度 → 期間（月／季／半年／整年）→ 要看哪一期」，要跟去年同期或上一期比就改「比較基準」。</li>
        <li>要只看某幾家客戶按「選擇客戶」；要只看某幾種出貨性質按「篩選出貨性質」（不選＝預設排除「不列入統計」的性質）。</li>
        <li>按「重新計算」。要把數字帶去 Excel 就按「CSV」；要印報告就按「列印報告」。</li>
      </ol>
      <h4>重要行為／常見疑問</h4>
      <ul>
        <li><b>出貨金額一律先套「出貨性質」篩選</b>：沒有明確篩選時，預設排除「不列入統計」（is_count=0，如樣品、補件）的性質。</li>
        <li><b>訂單金額只算得出「有填單價」的訂單</b>（畫面會印覆蓋率），舊年度訂單金額偏低多半是資料沒填。</li>
        <li><b>「新客戶」與「回流客戶」不同</b>：新客戶＝系統整段歷史第一次出現就在本期；回流客戶＝以前有往來、只是比較期剛好沒下單。</li>
        <li><b>異常偵測</b>：一般出貨金額為 0（多半漏填單價）會被標記；若某出貨性質設定「金額&gt;0 視為異常」（如樣品），則反過來抓有金額的那幾筆；
            設定「排除異常檢測」的性質完全不檢查。可在「異常偵測」跳窗逐筆或整批標記「已確認」（正常，非資料錯誤）。</li>
        <li><b>月份截止日</b>：影響「客戶季度分析」帳款季的季別切法，全站與出貨紀錄分析頁共用同一個設定。</li>
        <li><b>AS 稽核分類只能統計「已綁定訂單」的出貨</b>——出貨單本身沒有稽核製程標籤這個欄位，
            一定要先在快速出貨或追溯對照把出貨單綁到訂單，才查得到訂單追蹤設定的 AS 認定；
            綁定率低不是程式漏算，是現場還沒做綁定，畫面上會誠實列出「未綁定訂單」與
            「訂單尚未設定標籤」兩種不同的缺口，分開補齊即可。</li>
      </ul>
      <h4>設定入口</h4>
      <ul>
        <li>「出貨性質設定」：新增／修改／刪除出貨性質，設定是否列入統計、是否排除異常檢測。</li>
        <li>「月份截止日」：設定每月幾號為帳款月截止日（全站共用）。</li>
        <li>「監控設定」（需 <code>shipping_admin</code>）：KPI（月銷貨額達成率）提醒月數、出貨淨額移動平均監控門檻。</li>
        <li>權限指派：快速出貨模組的角色指派（<code>shipping_view</code>／<code>shipping_edit</code>／<code>shipping_admin</code>）。</li>
      </ul>
      <h4>權限角色</h4>
      <ul>
        <li><code>shipping_view</code> 以上：檢視本頁。</li>
        <li><code>shipping_admin</code>：另可修改監控設定、月份截止日、確認異常。</li>
        <li>系統管理員一律具備以上全部權限。</li>
      </ul>
    </div>
    <div class="m-foot"><button class="btn btn-warm" data-close="helpUseMask">關閉</button></div>
  </div>
</div>

<!-- ── 客戶挑選 ─────────────────────────────────────── -->
<div class="m-mask" id="cliMask">
  <div class="m-win" style="width:720px;">
    <div class="m-head">選擇客戶（可多選做比較） <span class="x" data-close="cliMask">✕</span></div>
    <div class="m-body" style="max-height:66vh;">
      <div class="oa-bar" style="margin-bottom:8px;">
        <input type="text" id="cliKw" class="form-control input-sm" style="width:220px;" data-eg-hint="輸入客戶名稱或編號篩選">
        <button class="btn btn-xs btn-warm-o" id="cliAll">全選目前清單</button>
        <button class="btn btn-xs btn-default" id="cliNone">全部取消</button>
        <span style="font-size:12px;color:var(--muted);" id="cliCount"></span>
      </div>
      <div style="font-size:11px;color:var(--muted);margin-bottom:6px;">只列出「所選年度真的有出貨或訂單」的客戶。</div>
      <div class="tbl-wrap" style="max-height:44vh;">
        <table class="oa-t" id="tblCli">
          <colgroup><col style="width:8%"><col style="width:38%"><col style="width:18%"><col style="width:18%"><col style="width:18%"></colgroup>
          <thead><tr><th></th><th>客戶</th><th>客戶編號</th><th>該年度出貨筆數</th><th>該年度訂單筆數</th></tr></thead>
          <tbody></tbody>
        </table>
      </div>
    </div>
    <div class="m-foot">
      <button class="btn btn-default" data-close="cliMask">取消</button>
      <button class="btn btn-warm" id="cliApply">套用</button>
    </div>
  </div>
</div>

<!-- ── 出貨性質篩選 ─────────────────────────────────── -->
<div class="m-mask" id="stMask">
  <div class="m-win" style="width:500px;">
    <div class="m-head">篩選出貨性質 <span class="x" data-close="stMask">✕</span></div>
    <div class="m-body">
      <div style="font-size:12px;color:var(--muted);margin-bottom:8px;">
        不勾選任何項目＝預設排除「不列入統計」的性質；勾選之後只計算勾起來的性質。
      </div>
      <div id="stCheckList"></div>
    </div>
    <div class="m-foot">
      <button class="btn btn-default" id="stClearBtn">清除（回預設）</button>
      <button class="btn btn-warm" id="stApplyBtn">套用</button>
    </div>
  </div>
</div>

<!-- ── 月份截止日 ─────────────────────────────────────── -->
<div class="m-mask" id="cutoffMask">
  <div class="m-win" style="width:440px;">
    <div class="m-head">帳款 / 接單月份截止日 <span class="x" data-close="cutoffMask">✕</span></div>
    <div class="m-body">
      <p style="font-size:12px;color:var(--muted);">全站共用設定（與出貨紀錄分析頁同一個值），影響「客戶季度分析」的帳款季切法。0＝不設截止日（純日曆月）。</p>
      <div class="oa-bar">
        <label>每月</label>
        <input type="number" id="cutoffDay" class="rm-in" style="width:80px;" min="0" max="31">
        <label>日截止</label>
      </div>
      <div class="err-txt" id="cutoffErr"></div>
    </div>
    <div class="m-foot">
      <button class="btn btn-default" data-close="cutoffMask">取消</button>
      <?php if ($canAdmin): ?><button class="btn btn-warm" id="btnCutoffSave">儲存</button><?php endif; ?>
    </div>
  </div>
</div>

<!-- ── 出貨性質設定 ─────────────────────────────────── -->
<div class="m-mask" id="saleTypeSetMask">
  <div class="m-win" style="width:820px;">
    <div class="m-head">出貨性質設定 <span class="x" data-close="saleTypeSetMask">✕</span></div>
    <div class="m-body" style="max-height:72vh;">
      <div class="two-col">
        <div style="flex:0 0 240px;">
          <div style="font-weight:700;color:var(--blue-d);margin-bottom:6px;">新增／修改</div>
          <input type="hidden" id="st_id">
          <div class="oa-bar" style="flex-direction:column;align-items:stretch;gap:6px;">
            <label>性質名稱 *</label>
            <input type="text" id="st_name" class="rm-in">
            <label>說明</label>
            <input type="text" id="st_desc" class="rm-in">
            <label>排序（數字越小越前）</label>
            <input type="number" id="st_sort" class="rm-in" value="0">
            <label style="font-weight:normal;"><input type="checkbox" id="st_count" checked data-eg-skip> 納入統計</label>
            <label style="font-weight:normal;"><input type="checkbox" id="st_exclude_anomaly" data-eg-skip> 排除異常檢測（全部排除）</label>
            <label style="font-weight:normal;"><input type="checkbox" id="st_exclude_when_nonzero" data-eg-skip>
              <span style="color:var(--coral);">金額&gt;0 視為異常</span></label>
            <label style="font-weight:normal;"><input type="checkbox" id="st_active" checked data-eg-skip> 啟用</label>
            <button class="btn btn-sm btn-warm" id="btnStSave" style="margin-top:6px;">儲存</button>
            <button class="btn btn-sm btn-default" id="btnStReset">重置表單</button>
          </div>
        </div>
        <div style="flex:1 1 420px;">
          <table class="oa-t" id="tblStAdmin">
            <colgroup><col style="width:26%"><col style="width:14%"><col style="width:14%"><col style="width:16%"><col style="width:10%"><col style="width:20%"></colgroup>
            <thead><tr><th>名稱</th><th>統計</th><th>排除異常</th><th>金額&gt;0異常</th><th>啟用</th><th>操作</th></tr></thead>
            <tbody></tbody>
          </table>
        </div>
      </div>
    </div>
    <div class="m-foot"><button class="btn btn-warm" data-close="saleTypeSetMask">關閉</button></div>
  </div>
</div>

<!-- ── 異常偵測 ─────────────────────────────────────── -->
<div class="m-mask" id="anomalyMask">
  <div class="m-win" style="width:900px;">
    <div class="m-head">異常偵測報告（本期） <span class="x" data-close="anomalyMask">✕</span></div>
    <div class="m-body" style="max-height:74vh;">
      <div class="oa-bar" style="margin-bottom:8px;">
        <label style="font-weight:normal;"><input type="checkbox" id="anomShowAll" data-eg-skip> 顯示已確認的</label>
        <?php if ($canAdmin): ?>
        <button class="btn btn-xs btn-warm-o" id="btnAnomConfirmSel">將勾選的標記為已確認</button>
        <button class="btn btn-xs btn-default" id="btnAnomUnconfirmSel">取消勾選項目的確認</button>
        <?php endif; ?>
        <span id="anomTotal" style="font-size:12px;color:var(--muted);margin-left:auto;"></span>
      </div>
      <div id="anomByType" style="margin-bottom:8px;"></div>
      <div class="tbl-wrap" style="max-height:42vh;">
        <table class="oa-t" id="tblAnomaly">
          <colgroup><col style="width:5%"><col style="width:11%"><col style="width:15%"><col style="width:13%"><col style="width:9%"><col style="width:9%"><col style="width:8%"><col style="width:12%"><col style="width:18%"></colgroup>
          <thead><tr><th></th><th>出貨日期</th><th>客戶</th><th>料號</th><th>數量</th><th>單價</th><th>金額</th><th>出貨性質</th><th>原因</th></tr></thead>
          <tbody></tbody>
        </table>
      </div>
      <div class="pager" id="pagerAnom"></div>
    </div>
    <div class="m-foot"><button class="btn btn-warm" data-close="anomalyMask">關閉</button></div>
  </div>
</div>

<?php if ($canAdmin): ?>
<!-- ── 急件（交期過短）判定設定：與訂單分析頁共用同一份管理員設定 ─────── -->
<div class="m-mask" id="urgSetMask">
  <div class="m-win" style="width:540px;">
    <div class="m-head">急件（交期過短）判定設定 <span class="x" data-close="urgSetMask">✕</span></div>
    <div class="m-body">
      <div style="font-size:12px;color:var(--muted);margin-bottom:8px;">
        這份設定與「訂單分析」頁「交期與急件分析」分頁共用同一份管理員設定，在這裡改，
        訂單分析那邊也會一起變動（反之亦然）。
      </div>
      <div style="font-size:12px;color:var(--muted);margin-bottom:6px;">
        依<b>全製／多製程／單製</b>各自的交期工作天數分布，取<b>最短的前 N%</b>視為急件
        （門檻永遠用全部客戶、同一期間的資料計算，不受客戶篩選影響）。
      </div>
      <div class="oa-bar">
        <label>全製</label><input type="number" id="urgPctFull" class="rm-in" style="width:60px;" min="1" max="100">
        <label>%　多製程</label><input type="number" id="urgPctMulti" class="rm-in" style="width:60px;" min="1" max="100">
        <label>%　單製</label><input type="number" id="urgPctSingle" class="rm-in" style="width:60px;" min="1" max="100">
        <label>%</label>
      </div>
      <div style="font-size:12px;color:var(--muted);margin:10px 0 6px;">
        <b>排除疑似誤植交期的訂單</b>：交期工作天數 ≤ 下面這個值的訂單，整段分析一律當作沒有交期資料
        （例如全製訂單交期工作天數＝0，大多是交期日期打錯）。留空＝不排除。
      </div>
      <div class="oa-bar">
        <label>排除工作天數 ≤</label>
        <input type="number" id="urgPctFloor" class="rm-in" style="width:70px;" min="0" max="60" data-eg-hint="留空＝不排除">
        <label>天的訂單</label>
      </div>
      <div class="err-txt" id="urgSetErr"></div>
    </div>
    <div class="m-foot">
      <button class="btn btn-default" data-close="urgSetMask">取消</button>
      <button class="btn btn-warm" id="btnUrgSetSave"><i class="fa fa-save"></i> 儲存設定</button>
    </div>
  </div>
</div>
<?php endif; ?>

<?php if ($canAdmin): ?>
<!-- ── 監控設定 ─────────────────────────────────────── -->
<div class="m-mask" id="setMask">
  <div class="m-win" style="width:760px;">
    <div class="m-head">出貨分析監控設定 <span class="x" data-close="setMask">✕</span></div>
    <div class="m-body" style="max-height:72vh;">
      <h4 style="font-size:15px;color:var(--blue-d);margin:0 0 6px;">月銷貨額達成率提醒</h4>
      <div style="font-size:12px;color:var(--muted);margin-bottom:6px;">
        讀 KPI 關鍵績效指標的「月銷貨額達成率」，看最近幾個已結束的月份有沒有未達標。
      </div>
      <div class="oa-bar">
        <label>看最近</label>
        <input type="number" id="setKpiMonths" class="rm-in" style="width:70px;" min="1" max="12">
        <label>個已結束的月份</label>
      </div>

      <h4 style="font-size:15px;color:var(--blue-d);margin:18px 0 6px;">出貨淨額移動平均監控</h4>
      <div style="font-size:12px;color:var(--muted);margin-bottom:6px;">
        每月自動評估：取「前 N 個月出貨淨額的移動平均」，連續 M 個月低於安全水平就標成需注意。
      </div>
      <div class="oa-bar">
        <label style="font-weight:normal;"><input type="checkbox" id="setMaEnabled" data-eg-skip> 啟用</label>
        <label>移動平均取前</label>
        <input type="number" id="setMaMonths" class="rm-in" style="width:64px;" min="2" max="12">
        <label>個月，連續</label>
        <input type="number" id="setMaCons" class="rm-in" style="width:56px;" min="1" max="6">
        <label>個月低於安全水平</label>
      </div>
      <div class="oa-bar" style="margin-top:6px;">
        <label>安全水平</label>
        <select id="setMaMode" class="form-control input-sm" style="width:210px;">
          <option value="kpi">該年度月銷貨目標金額</option>
          <option value="manual">自訂金額</option>
        </select>
        <input type="number" id="setMaValue" class="rm-in" style="width:130px;" min="0" data-eg-hint="每月安全水平金額（元）">
      </div>
      <div class="oa-bar" style="margin-top:6px;">
        <label>未確認異常筆數達到</label>
        <input type="number" id="setAnomMin" class="rm-in" style="width:70px;" min="1" max="500">
        <label>筆時，自動分析標成「要處理」</label>
      </div>
      <div class="oa-bar" style="margin-top:8px;">
        <button type="button" class="btn btn-xs btn-warm-o" id="btnMaPreview"><i class="fa fa-flask"></i> 用目前設定試算</button>
        <span id="maPreviewOut" style="font-size:12px;color:#1B4F78;"></span>
      </div>
      <div class="err-txt" id="setErr"></div>
    </div>
    <div class="m-foot">
      <button class="btn btn-default" data-close="setMask">取消</button>
      <button class="btn btn-warm" id="btnSetSave"><i class="fa fa-save"></i> 儲存設定</button>
    </div>
  </div>
</div>
<?php endif; ?>

<!-- 客戶唯讀檢視（比照 Order_Analysis.php 的做法，不用 window.open） -->
<div class="m-mask" id="custViewMask">
  <div class="m-win" id="custViewWin" style="width:760px;">
    <div class="m-head" id="custViewTitle">檢視客戶基本資料 <span class="x" onclick="closeCustView()">✕</span></div>
    <div class="m-body" style="padding:0;overflow:hidden;">
      <iframe id="custViewFrame" src="about:blank" style="display:block;width:100%;height:420px;border:0;"></iframe>
    </div>
  </div>
</div>

<script src="../../resource/js/jquery.min.js"></script>
<script src="../../resource/js/bootstrap.min.js"></script>
<script src="../../resource/js/fastclick.js"></script>
<script src="../../resource/js/nprogress.js"></script>
<script src="../../resource/js/custom.min.js"></script>
<script src="../../code/highcharts.js"></script>
<script src="../../code/modules/exporting.js"></script>
<script src="../../resource/js/eg_print_log.js?v=<?= @filemtime(__DIR__.'/../../resource/js/eg_print_log.js') ?>"></script>
<script src="../../resource/js/eg_date_fmt.js?v=<?= @filemtime(__DIR__.'/../../resource/js/eg_date_fmt.js') ?>"></script>
<script src="../../resource/js/eg_input_rules.js?v=<?= @filemtime(__DIR__.'/../../resource/js/eg_input_rules.js') ?>"></script>
<script>
$(document).ready(function(){ $('#sidebar-menu').css('visibility','visible'); });

/* 回頂端：捲到底下時才出現，按下捲回最上面（比照 Order_Analysis.php 同一套） */
$(window).on('scroll', function(){ $('#btnToTop').toggle($(window).scrollTop() > 200); });

var SI_API   = '../../src/store/ShippingInsight_API.php';
var SI_CSRF  = '<?= siEsc($CSRF) ?>';
var CAN_ADMIN = <?= $canAdmin ? 'true' : 'false' ?>;
var COMPANY  = <?= json_encode($COMPANY, JSON_UNESCAPED_UNICODE) ?>;
var SALE_TYPES = <?= json_encode($saleTypes, JSON_UNESCAPED_UNICODE) ?>;

function esc(s){ return $('<div>').text(s==null?'':s).html(); }
function nf(n){ n=Number(n)||0; return n.toLocaleString('en-US'); }
function nf1(n){ n=Number(n)||0; return n.toLocaleString('en-US',{maximumFractionDigits:1}); }
function money(n){ return nf(Math.round(Number(n)||0)); }
function wan(n){ return Math.round((Number(n)||0)/10000*10)/10; }
function dispDate(s){ return (typeof egFmtDate==='function') ? egFmtDate(s) : (s||''); }
function pct(a,b){ b=Number(b)||0; if(!b) return '—'; return (Math.round((Number(a)||0)/b*1000)/10)+'%'; }
function showToast(msg, kind){
  var c = kind==='error' ? '#D64545' : (kind==='info' ? '#1B5FA8' : '#4F8A4F');
  var $t = $('<div>').text(msg).css({ position:'fixed', left:'50%', bottom:'26px', transform:'translateX(-50%)', zIndex:10500,
    background:c, color:'#fff', padding:'8px 18px', borderRadius:'20px', fontSize:'13px',
    boxShadow:'0 3px 10px rgba(0,0,0,.25)', opacity:0 }).appendTo('body');
  $t.animate({opacity:1}, 150).delay(2200).animate({opacity:0}, 300, function(){ $t.remove(); });
}
function deltaHtml(cur, prev, fmt){
  fmt = fmt || nf;
  var p = Number(prev)||0, d = (Number(cur)||0) - p;
  var cls = d>0?'up':(d<0?'down':'flat');
  var sign = d>0?'+':'';
  var rate;
  if(!p) rate = '（基期為 0）';
  else if(Math.abs(d/p) > 10) rate = '（基期過小，成長率不具意義）';
  else rate = '（'+sign+Math.round(d/Math.abs(p)*1000)/10+'%）';
  return '<span class="'+cls+'">'+sign+fmt(d)+'</span> <span style="font-size:10px;color:var(--muted);">'+rate+'</span>';
}
function openMask(id){ $('#'+id).show(); }
function closeMask(id){ $('#'+id).hide(); }
$(document).on('click','[data-close]', function(){ closeMask($(this).data('close')); });
$(document).on('click','.m-mask', function(e){ if(e.target===this) $(this).hide(); });
$('#btnPageHelp').on('click', function(){ openMask('helpUseMask'); });

function oaOpenDrawing(pid){
  pid = parseInt(pid, 10) || 0;
  if(!pid){ alert('這一筆沒有綁定料號主檔，無法開啟圖面檢視。'); return; }
  var w = screen.availWidth, h = screen.availHeight;
  var pw = Math.min(1400, Math.round(w * 0.85)), ph = Math.min(900, Math.round(h * 0.88));
  window.open('../pm/bom_viewer.php?pk=' + encodeURIComponent(pid), 'drawing_' + pid,
    'width=' + pw + ',height=' + ph + ',left=' + Math.round((w - pw) / 2) + ',top=' + Math.round((h - ph) / 2)
    + ',resizable=yes,scrollbars=yes,menubar=no,toolbar=no,location=no,status=no');
}
function oaOpenCustomerView(cid){
  cid = String(cid||'').trim();
  if(!cid || cid==='0'){ return; }
  document.getElementById('custViewFrame').src = '../pages/master_data_management.php?view_customer=' + encodeURIComponent(cid) + '&embed=1';
  openMask('custViewMask');
}
function closeCustView(){ closeMask('custViewMask'); document.getElementById('custViewFrame').src = 'about:blank'; }
$('#custViewMask').on('click', function(e){ if(e.target===this) closeCustView(); });
window.addEventListener('message', function(e){
  if (!e.data || e.data.type !== 'cv-resize') return;
  var f = document.getElementById('custViewFrame'); if (!f) return;
  var h = Math.max(200, Math.min(parseInt(e.data.height,10)||200, Math.round(window.innerHeight*0.85)));
  f.style.height = h + 'px';
});
function pnoCell(p){
  var t = esc(p.pno);
  if(!p.pid) return t + ' <span style="font-size:10px;color:var(--muted);" title="沒有綁定料號主檔">（未綁主檔）</span>';
  return '<span class="pno-link" data-pid="'+p.pid+'" title="點一下開啟圖面檢視">'
       + '<i class="fa fa-picture-o" style="font-size:11px;opacity:.75;"></i> '+t+'</span>';
}
$(document).on('click', '.pno-link', function(){ oaOpenDrawing($(this).data('pid')); });

/* ai-rules/10 藍色系調色盤（同語意同色、跨頁一致） */
var PAL = ['#2E7FD6','#5AA9E6','#1B5FA8','#0D3A66','#7FB2DD','#4C8FC4','#8FC1EA','#123A5C','#A9D2F0','#2C5F8A'];
var C_BLUE='#2E7FD6', C_CORAL='#D64545', C_NAVY='#123A5C', C_LIGHT='#BFE0FF', C_GREEN='#4F8A4F';

var BASE_CHART = {
  chart:{ backgroundColor:'#fff', style:{fontFamily:'"Microsoft JhengHei",sans-serif'}, spacing:[8,8,6,8] },
  title:{ text:null }, credits:{ enabled:false },
  xAxis:{ lineColor:'#C7DEF2', tickColor:'#C7DEF2', labels:{ style:{fontSize:'11px', color:'#1B4F78'} } },
  legend:{ itemStyle:{fontSize:'11px', fontWeight:'400', color:'#1B4F78'}, symbolRadius:3 }
};
function opt(o){ return $.extend(true, {}, BASE_CHART, o); }
var DATA = null, CHARTS = {};
function chart(id, o){ try { if(CHARTS[id]){ CHARTS[id].destroy(); CHARTS[id]=null; } } catch(e){} CHARTS[id] = Highcharts.chart(id, o); }
function sizeBox(id, px){ var e=document.getElementById(id); if(e) e.style.height = Math.round(px)+'px'; }

/* ── 期別下拉 ─────────────────────────────────────────── */
var GRAN_IDX = { year:[['1','整年']], half:[['1','上半年'],['2','下半年']],
                 quarter:[['1','第 1 季'],['2','第 2 季'],['3','第 3 季'],['4','第 4 季']], month:null };
function fillIdx(){
  var g = $('#fGran').val(), list = GRAN_IDX[g];
  if(!list){ list=[]; for(var m=1;m<=12;m++) list.push([String(m), m+' 月']); }
  var h=''; list.forEach(function(x){ h += '<option value="'+x[0]+'">'+x[1]+'</option>'; });
  $('#fIdx').html(h).prop('disabled', g==='year');
  var want = defaultIdx(g);
  if($('#fIdx option[value="'+want+'"]').length) $('#fIdx').val(want);
}
function defaultIdx(g){
  var y = parseInt($('#fYear').val(),10), now = new Date();
  if(y !== now.getFullYear()){ return g==='month'?'12':(g==='quarter'?'4':(g==='half'?'2':'1')); }
  var m = now.getMonth()+1;
  if(g==='month') return String(m);
  if(g==='quarter') return String(Math.ceil(m/3));
  if(g==='half') return m<=6?'1':'2';
  return '1';
}
$('#fGran, #fYear').on('change', function(){ fillIdx(); });
fillIdx();

/* ── 客戶挑選 ───────────────────────────────────────── */
var CLI_LIST = [], CLI_SEL = {}, CLI_YEAR = 0;
function loadClients(cb){
  var y = $('#fYear').val();
  if(CLI_YEAR === y && CLI_LIST.length){ if(cb) cb(); return; }
  $.get(SI_API, {action:'clients', year:y}, function(r){
    if(!r || !r.ok){ alert((r&&r.error)||'客戶清單載入失敗'); return; }
    CLI_LIST = r.clients || []; CLI_YEAR = y; if(cb) cb();
  }, 'json');
}
function renderCliTable(){
  var kw = String($('#cliKw').val()||'').trim().toLowerCase();
  var h='', n=0;
  CLI_LIST.forEach(function(c){
    if(kw && (String(c.name).toLowerCase().indexOf(kw)<0 && String(c.cid).toLowerCase().indexOf(kw)<0)) return;
    n++;
    h += '<tr><td style="text-align:center;"><input type="checkbox" class="cli-cb" data-k="'+esc(c.key)+'"'
       + (CLI_SEL[c.key]?' checked':'') + '></td>'
       + '<td>'+esc(c.name)+(c.bad?' <span class="badge-warn">未建主檔</span>':'')+'</td>'
       + '<td>'+esc(c.cid||'—')+'</td><td class="n">'+nf(c.ship_rows)+'</td><td class="n">'+nf(c.ord_rows)+'</td></tr>';
  });
  $('#tblCli tbody').html(h || '<tr><td colspan="5" style="text-align:center;color:var(--muted);">沒有符合的客戶</td></tr>');
  $('#cliCount').text('顯示 '+n+' / 共 '+CLI_LIST.length+' 家；已選 '+Object.keys(CLI_SEL).length+' 家');
}
$('#btnCliPick').on('click', function(){ loadClients(function(){ $('#cliKw').val(''); renderCliTable(); openMask('cliMask'); }); });
$('#cliKw').on('input', renderCliTable);
$(document).on('change', '.cli-cb', function(){
  var k = $(this).data('k');
  if($(this).is(':checked')) CLI_SEL[k]=1; else delete CLI_SEL[k];
  $('#cliCount').text($('#cliCount').text().replace(/已選 \d+ 家/, '已選 '+Object.keys(CLI_SEL).length+' 家'));
});
$('#cliAll').on('click', function(){ $('.cli-cb').each(function(){ CLI_SEL[$(this).data('k')]=1; }); renderCliTable(); });
$('#cliNone').on('click', function(){ CLI_SEL={}; renderCliTable(); });
$('#cliApply').on('click', function(){ closeMask('cliMask'); renderChips(); load(); });
$('#btnCliClear').on('click', function(){ CLI_SEL={}; renderChips(); load(); });
function renderChips(){
  var keys = Object.keys(CLI_SEL);
  if(!keys.length){ $('#cliChips').html('<span style="font-size:12px;color:var(--muted);">未篩選＝全部客戶</span>'); $('#btnCliClear').hide(); return; }
  var map={}; CLI_LIST.forEach(function(c){ map[c.key]=c.name; });
  var h=''; keys.forEach(function(k){ h += '<span class="chip">'+esc(map[k]||k)+'<i class="fa fa-times" data-k="'+esc(k)+'"></i></span>'; });
  $('#cliChips').html(h); $('#btnCliClear').show();
}
$(document).on('click','#cliChips .fa-times', function(){ delete CLI_SEL[$(this).data('k')]; renderChips(); load(); });

/* ── 出貨性質篩選 ─────────────────────────────────────── */
var ST_SEL = {};
function renderStCheckList(){
  var h = '<label style="display:block;font-weight:normal;margin-bottom:4px;"><input type="checkbox" class="st-cb" value="NULL"'
        + (ST_SEL['NULL']?' checked':'') + '> 一般產品（未設定出貨性質）</label>';
  SALE_TYPES.forEach(function(s){
    h += '<label style="display:block;font-weight:normal;margin-bottom:4px;"><input type="checkbox" class="st-cb" value="'+s.sale_type_id+'"'
       + (ST_SEL[String(s.sale_type_id)]?' checked':'') + '> '+esc(s.sale_type_name)
       + (String(s.is_count)==='0'?' <span style="color:var(--muted);font-size:11px;">（不列入統計）</span>':'') + '</label>';
  });
  $('#stCheckList').html(h);
}
$('#btnStPick').on('click', function(){ renderStCheckList(); openMask('stMask'); });
$(document).on('change', '.st-cb', function(){
  var v = $(this).val();
  if($(this).is(':checked')) ST_SEL[v]=1; else delete ST_SEL[v];
});
$('#stClearBtn').on('click', function(){ ST_SEL={}; renderStCheckList(); });
$('#stApplyBtn').on('click', function(){
  closeMask('stMask');
  var keys = Object.keys(ST_SEL);
  $('#stFilterLabel').text(keys.length ? ('已篩選 '+keys.length+' 種出貨性質') : '預設（排除不列入統計的性質）');
  load();
});
function stSelectedArr(){ return Object.keys(ST_SEL); }

/* ── 主流程 ─────────────────────────────────────────── */
/* ── 頂層分頁切換（總覽分析／交期與急件分析）─────── */
var CUR_MAIN_TAB = 'overview';
function oaSwitchMainTab(t){
  CUR_MAIN_TAB = t;
  $('.oa-tabpanel').hide();
  $('.oa-tabpanel[data-tab="'+t+'"]').show();
  $('.oa-mtab').removeClass('active');
  $('.oa-mtab[data-tab="'+t+'"]').addClass('active');
  if(t==='leadtime' && !LT_DATA) loadLeadtime();
}
$('.oa-mtab').on('click', function(){ oaSwitchMainTab($(this).data('tab')); });
$(document).on('click', '.nav-jump a', function(){ oaSwitchMainTab('overview'); });

function load(){
  var req = { action:'analyze', year:$('#fYear').val(), gran:$('#fGran').val(), idx:$('#fIdx').val(),
    cmp:$('#fCmp').val(), align:$('#cbAlign').is(':checked')?1:0, top:50,
    clients:JSON.stringify(Object.keys(CLI_SEL)), sale_types:JSON.stringify(stSelectedArr()) };
  $('#noteBar').html('<div class="oa-note"><i class="fa fa-spinner fa-spin"></i> 計算中…</div>');
  $.post(SI_API, req, function(r){
    if(!r || !r.ok){ $('#noteBar').html('<div class="oa-note oa-warn">'+esc((r&&r.error)||'載入失敗')+'</div>'); return; }
    DATA = r;
    renderNote(); renderKpiAlert(); renderKpi(); renderInsights(); renderRecommend();
    renderTrend(); renderSaleType(); renderAstag(); renderClient(); renderRank(); renderMa();
    listReload();
    if(LT_DATA) loadLeadtime();   // 篩選條件變了，急件分析也要跟著重算
  }, 'json').fail(function(x){
    $('#noteBar').html('<div class="oa-note oa-warn">載入失敗（HTTP '+x.status+'）'
      + (x.status===403?'：權限不足或連線憑證失效，請重新整理頁面':'') + '</div>');
  });
}
$('#btnReload').on('click', load);
$('#fYear, #fGran, #fIdx, #fCmp, #cbAlign').on('change', load);

/* ══════════════════════════════════════════════════════════════════
 * 交期與急件分析（2026-10-07 使用者交辦，整套比照訂單分析複製；計算唯一實作仍在
 * order_analysis_lib.php 的 oa_leadtime_report()／oa_urgent_insights()，本頁只是
 * 另開一個呼叫端，不重寫任何判定邏輯——鐵律4）
 * ══════════════════════════════════════════════════════════════════ */
var LT_DATA = null;
var LT_PCT_INIT = false;
function loadLeadtime(){
  var req = {
    action:'leadtime_analyze', year:$('#fYear').val(), gran:$('#fGran').val(), idx:$('#fIdx').val(),
    cmp:$('#fCmp').val(), align:$('#cbAlign').is(':checked')?1:0,
    clients:JSON.stringify(Object.keys(CLI_SEL))
  };
  if(LT_PCT_INIT){
    req.urgent_pct = JSON.stringify({
      full:   parseInt($('#ltPctFull').val(),10)   || 20,
      multi:  parseInt($('#ltPctMulti').val(),10)  || 20,
      single: parseInt($('#ltPctSingle').val(),10) || 20
    });
  }
  $('#ltNote').html('<i class="fa fa-spinner fa-spin"></i> 計算中…');
  $.post(SI_API, req, function(r){
    if(!r||!r.ok){ $('#ltNote').html('<span style="color:var(--coral);">'+esc((r&&r.error)||'載入失敗')+'</span>'); return; }
    LT_DATA = r;
    if(!LT_PCT_INIT){
      var d = r.settings_default.percentile;
      $('#ltPctFull').val(d.full); $('#ltPctMulti').val(d.multi); $('#ltPctSingle').val(d.single);
      LT_PCT_INIT = true;
    }
    renderLtNote(); renderLtKpi(); renderLtInsights(); renderLtDist(); renderLtShipDist(); renderLtBand(); renderLtClient(); renderLtList();
  }, 'json').fail(function(x){
    $('#ltNote').html('<span style="color:var(--coral);">載入失敗（HTTP '+x.status+'）</span>');
  });
}
$('#btnLtApplyPct').on('click', loadLeadtime);
$('#btnLtResetPct').on('click', function(){
  var d = (LT_DATA && LT_DATA.settings_default && LT_DATA.settings_default.percentile) || {full:20,multi:20,single:20};
  $('#ltPctFull').val(d.full); $('#ltPctMulti').val(d.multi); $('#ltPctSingle').val(d.single);
  loadLeadtime();
});
function renderLtNote(){
  var m = LT_DATA.period, c = LT_DATA.cmp_period;
  var used = LT_DATA.settings.percentile, def = LT_DATA.settings_default.percentile;
  var pctLine = '<b>本次使用的急件判定</b>：全製 '+used.full+'%／多製程 '+used.multi+'%／單製 '+used.single+'%'
    + '　<span style="color:var(--muted);">（管理員預設值：全製 '+def.full+'%／多製程 '+def.multi+'%／單製 '+def.single+'%）</span>';
  if(LT_DATA.is_override) pctLine += ' <span class="badge-warn">已修改，僅本次計算，不影響管理員預設值</span>';
  $('#ltNote').html(pctLine + '<br>'
    + '<b>本期</b>：'+esc(m.label)+'（'+dispDate(m.start)+'～'+dispDate(m.end)+'）　'
    + '<b>基期</b>：'+esc(c.label)+'（'+dispDate(c.start)+'～'+dispDate(c.end)+'）<br>'
    + '交期工作天數＝下單日到交期之間扣掉假日的工作天數（不含下單當天，同一天交貨＝0 個工作天）；'
    + '急件門檻依「全製／多製程／單製」各自的分布百分位計算，<b>門檻用全部客戶資料算，不受上方客戶篩選影響</b>。');
}
function ltCard(lab, val, sub){
  return '<div class="kpi-card"><div class="k-lab">'+lab+'</div><div class="k-val">'+val+'</div><div class="k-sub">'+(sub||'')+'</div></div>';
}
function renderLtKpi(){
  var c = LT_DATA.cur, p = LT_DATA.cmp, h = '';
  var curL = LT_DATA.period.label, cmpL = LT_DATA.cmp_period.label;
  h += ltCard('急件筆數', nf(c.urgent_orders), '佔'+curL+' '+c.urgent_order_ratio+'%（'+cmpL+' '+p.urgent_order_ratio+'%）');
  h += ltCard('急件金額佔比', c.urgent_amount_ratio+'%', money(c.urgent_amount)+' 元');
  h += ltCard('急件客戶數', nf(c.urgent_clients), '家');
  h += ltCard(curL+'訂單總數', nf(c.orders), money(c.amount)+' 元');
  h += ltCard('出貨綁定覆蓋率', c.ship_coverage+'%', nf(c.ship_bound_n)+' / '+nf(c.orders)+' 張（多數尚未綁定）');
  h += ltCard('平均實際出貨工作天', c.avg_ship_leadtime==null?'—':c.avg_ship_leadtime, '僅計已綁定出貨單的訂單');
  h += ltCard('逾交期比例', c.late_ratio+'%', nf(c.late_n)+' 張逾交期'+(c.avg_delay_days==null?'':('，平均延誤 '+c.avg_delay_days+' 天')));
  $('#ltKpiRow').html(h);
}
function renderLtInsights(){
  var list = LT_DATA.insights || [];
  var icon = {bad:'fa-exclamation-circle', warn:'fa-exclamation-triangle', good:'fa-check-circle', info:'fa-info-circle'};
  var h = list.map(function(x){
    var hasList = x.clients && x.clients.length;
    var listId = hasList ? ('ltInsCli'+(_insSeq++)) : '';
    return '<div class="ins ins-'+x.level+'"><div class="ic"><i class="fa '+(icon[x.level]||'fa-info-circle')+'"></i></div>'
      + '<div class="bd"><div class="tt">'+esc(x.title)
      + (hasList ? ' <span class="ins-toggle" onclick="siToggleInsList(\''+listId+'\',this)">展開名單 <i class="fa fa-caret-down"></i></span>' : '')
      + '</div><div class="dt">'+esc(x.detail)+'</div>'
      + (hasList ? ('<div class="ins-cli-wrap" id="'+listId+'" style="display:none;">'+ltRenderUrgentClientGrid(x.clients)+'</div>') : '')
      + '</div>'
      + (x.metric?('<div class="mt">'+esc(x.metric)+'</div>'):'')+'</div>';
  }).join('');
  $('#ltInsightList').html(h || '<div style="color:var(--muted);font-size:12px;">（沒有分析結果）</div>');
}
function renderLtDist(){
  var rows = (LT_DATA.cur.by_cls||[]).slice();
  rows.sort(function(a,b){ return b.n-a.n; });
  chart('chLtBox', opt({
    chart:{ type:'column' },
    xAxis:{ categories: rows.map(function(r){ return r.label; }) },
    yAxis:{ min:0, title:{text:'工作天數',style:{fontSize:'11px',color:'#a08a6f'}}, gridLineColor:'#F0E8DC',
            labels:{style:{fontSize:'10px',color:'#a08a6f'}} },
    tooltip:{ shared:true, style:{fontSize:'11px'} },
    series:[
      { name:'平均工作天', type:'column', data: rows.map(function(r){ return r.avg; }), color:C_BLUE, borderRadius:2 },
      { name:'急件門檻',   type:'line',   data: rows.map(function(r){ return r.threshold; }), color:C_CORAL, marker:{enabled:true} }
    ]
  }));
  var h = rows.map(function(r){
    return '<tr><td>'+esc(r.label)+(r.is_urgent_class?'':' <span style="color:var(--muted);font-size:10px;">（不列入判定）</span>')+'</td>'
      + '<td class="n">'+nf(r.n)+'</td><td class="n">'+(r.avg==null?'—':r.avg)+'</td>'
      + '<td class="n">'+(r.median==null?'—':r.median)+'</td>'
      + '<td class="n">'+(r.threshold==null?'—':nf1(r.threshold))+'</td>'
      + '<td class="n">'+nf(r.urgent_n)+'</td><td class="n">'+r.urgent_ratio+'%</td></tr>';
  }).join('');
  $('#tblLtCls tbody').html(h || '<tr><td colspan="7" style="text-align:center;color:var(--muted);">（無資料）</td></tr>');
}
/* 實際出貨工作天數：跟上面「交期工作天數」同一組類別，逐類別比較承諾交期與實際出貨落差——
   出貨綁定覆蓋率低時其餘欄位顯示「—」，不可以把「沒有資料」誤算成「0 天」或「0%」。 */
function renderLtShipDist(){
  var rows = (LT_DATA.cur.by_cls||[]).slice();
  rows.sort(function(a,b){ return b.n-a.n; });
  var h = rows.map(function(r){
    return '<tr><td>'+esc(r.label)+(r.is_urgent_class?'':' <span style="color:var(--muted);font-size:10px;">（不列入判定）</span>')+'</td>'
      + '<td class="n">'+nf(r.n)+'</td>'
      + '<td class="n">'+r.ship_coverage+'%<span style="color:var(--muted);font-size:10px;">（'+nf(r.ship_bound_n)+'）</span></td>'
      + '<td class="n">'+(r.avg==null?'—':r.avg)+'</td>'
      + '<td class="n">'+(r.avg_ship_leadtime==null?'—':r.avg_ship_leadtime)+'</td>'
      + '<td class="n">'+(r.avg_delay_days==null?'—':r.avg_delay_days)+'</td>'
      + '<td class="n">'+(r.ship_bound_n?r.late_ratio+'%':'—')+'</td></tr>';
  }).join('');
  $('#tblLtShipCls tbody').html(h || '<tr><td colspan="7" style="text-align:center;color:var(--muted);">（無資料）</td></tr>');
}
function renderLtBand(){
  var rows = (LT_DATA.cur.by_band||[]).slice();
  chart('chLtBand', opt({
    chart:{ type:'column' },
    xAxis:{ categories: rows.map(function(r){ return r.label; }) },
    yAxis:{ min:0, title:{text:'筆數',style:{fontSize:'11px',color:'#a08a6f'}}, gridLineColor:'#F0E8DC',
            labels:{style:{fontSize:'10px',color:'#a08a6f'}} },
    tooltip:{ shared:true, style:{fontSize:'11px'} },
    plotOptions:{ column:{ borderRadius:2, pointPadding:0.08, groupPadding:0.14 } },
    series:[
      { name:'總筆數',   data: rows.map(function(r){ return r.n; }),         color:C_LIGHT },
      { name:'急件筆數', data: rows.map(function(r){ return r.urgent_n; }), color:C_CORAL }
    ]
  }));
}
function ltDeltaText(c){
  if(c.delta_pct===null || c.delta_pct===undefined) return '<span style="color:var(--muted);">'+esc(LT_DATA.cmp_period.label)+'無急件</span>';
  var v = c.delta_pct;
  return '<span class="'+(v>=0?'up':'down')+'">'+(v>=0?'+':'')+v+'%</span>';
}
function renderLtClient(){
  var rows = LT_DATA.cur.client_list || [];
  $('#ltClientCount').text(nf(LT_DATA.cur.urgent_clients));
  $('#tblLtClientCmpHead').text('較'+LT_DATA.cmp_period.label+'增減');
  var h = rows.map(function(r,i){
    return '<tr><td class="n">'+(i+1)+'</td><td>'+esc(r.name)+(r.bad?' <span class="badge-warn">未建主檔</span>':'')+'</td>'
      + '<td class="n">'+nf(r.n)+'</td><td class="n">'+money(r.amount)+'</td>'
      + '<td class="n">'+(r.pct_of_total!=null?r.pct_of_total+'%':'—')+'</td>'
      + '<td class="n">'+ltDeltaText(r)+'</td></tr>';
  }).join('');
  $('#tblLtClient tbody').html(h || '<tr><td colspan="6" style="text-align:center;color:var(--muted);">（本期沒有急件）</td></tr>');
}
function ltRenderUrgentClientGrid(clients){
  var cols = (clients.length > 15) ? 3 : 2;
  var colStyle = 'grid-template-columns:repeat('+cols+',1fr);';
  var cells = clients.map(function(c){
    return '<div class="ltu-cell">'
      + '<div class="l1"><span class="ins-cli-name">'+esc(c.name)+(c.bad?' <span class="badge-warn">未建主檔</span>':'')+'</span>'
      + '<span class="amt">'+money(c.amount)+' 元</span></div>'
      + '<div class="l2"><span>'+nf(c.n)+' 筆／佔急件總額 '+(c.pct_of_total!=null?c.pct_of_total+'%':'—')+'</span>'
      + '<span>較'+esc(LT_DATA.cmp_period.label)+ltDeltaText(c)+'</span></div>'
      + '</div>';
  }).join('');
  return '<div class="ins-cli-grid" style="'+colStyle+'margin-top:6px;">'+cells+'</div>';
}
function ltCatCell(r){
  var tag = r.as_label || r.label;
  var h = '<div style="font-size:11px;font-weight:600;color:#1B4F78;">'+esc(tag)+'</div>';
  if (r.proc) h += '<div style="font-size:10px;color:var(--muted);margin-top:1px;">'+esc(r.proc)+'</div>';
  return h;
}
function ltShipCell(r){
  if (!r.ship_date) return '<span style="color:var(--muted);">未綁定</span>';
  var d = r.delay_days;
  var dTxt = (d==null) ? '' : (d>0 ? ('延誤 '+d+' 天') : (d<0 ? ('提前 '+(-d)+' 天') : '準時'));
  var dCol = (d==null) ? 'var(--muted)' : (d>0 ? 'var(--coral)' : (d<0 ? '#2E7D32' : 'var(--muted)'));
  return '<div style="font-size:11px;">'+dispDate(r.ship_date)+'</div>'
    + '<div style="font-size:10px;color:var(--muted);">工作天 '+(r.ship_lt==null?'—':r.ship_lt)+'</div>'
    + (dTxt ? '<div style="font-size:10px;color:'+dCol+';">'+dTxt+'</div>' : '');
}
/* KEY單日期/業務：日期在上、業務（填單人員）在下，疊兩行放在客戶左側（與訂單分析同一套排版） */
function ltKeyCell(r){
  return '<div style="font-size:11px;">'+(r.created_at?dispDate(r.created_at):'—')+'</div>'
    + '<div style="font-size:10px;color:var(--muted);">'+esc(r.created_by_name||'—')+'</div>';
}
function renderLtList(){
  var rows = LT_DATA.cur.urgent_list || [];
  var h = rows.map(function(r){
    return '<tr><td>'+esc(r.no)+(r.c_order?('<br><span style="font-size:10px;color:var(--muted);">'+esc(r.c_order)+'</span>'):'')+'</td>'
      + '<td>'+ltKeyCell(r)+'</td>'
      + '<td>'+esc(r.cname)+'</td><td>'+pnoCell(r)+'</td>'
      + '<td>'+dispDate(r.odate)+'</td><td>'+dispDate(r.ddate)+'</td>'
      + '<td class="n">'+r.lt+'</td>'
      + '<td class="n">'+(r.proc_steps==null?'<span style="color:var(--muted);" title="尚未開立製令（BOM），查不到製程資料">—</span>':nf(r.proc_steps))+'</td>'
      + '<td>'+ltCatCell(r)+'</td>'
      + '<td class="n">'+nf(r.qty)+'</td><td class="n">'+money(r.amount)+'</td>'
      + '<td>'+ltShipCell(r)+'</td>'
      + '<td style="font-size:11px;">'+(r.order_ps?esc(r.order_ps):'<span style="color:var(--muted);">—</span>')+'</td></tr>';
  }).join('');
  $('#tblLtList tbody').html(h || '<tr><td colspan="13" style="text-align:center;color:var(--muted);">（本期沒有急件）</td></tr>');
}
<?php if ($canAdmin): ?>
function openUrgSetMask(){
  $.get(SI_API, {action:'urgent_settings_get'}, function(r){
    if(!r||!r.ok){ alert((r&&r.error)||'設定載入失敗'); return; }
    SI_CSRF = r.csrf || SI_CSRF;
    var u = r.settings || {percentile:{full:20,multi:20,single:20}, min_workdays_floor:null};
    $('#urgPctFull').val(u.percentile.full); $('#urgPctMulti').val(u.percentile.multi); $('#urgPctSingle').val(u.percentile.single);
    $('#urgPctFloor').val(u.min_workdays_floor==null?'':u.min_workdays_floor);
    $('#urgSetErr').text('');
    openMask('urgSetMask');
  }, 'json');
}
$('#btnUrgentSetting').on('click', openUrgSetMask);
$('#btnUrgSetSave').on('click', function(){
  var full = parseInt($('#urgPctFull').val(),10), multi = parseInt($('#urgPctMulti').val(),10), single = parseInt($('#urgPctSingle').val(),10);
  var errs = [];
  [['全製',full],['多製程',multi],['單製',single]].forEach(function(p){ if(!(p[1]>=1 && p[1]<=100)) errs.push(p[0]+'的百分比必須介於 1~100'); });
  var floorRaw = $('#urgPctFloor').val();
  var floor = (floorRaw==='') ? null : parseInt(floorRaw,10);
  if(floor!=null && !(floor>=0 && floor<=60)) errs.push('排除工作天數下限必須介於 0~60，或留空不排除');
  if(errs.length){ $('#urgSetErr').text(errs.join('\n')); return; }
  var payload = JSON.stringify({ percentile:{full:full,multi:multi,single:single}, min_workdays_floor:floor });
  $.post(SI_API, {action:'urgent_settings_save', urgent:payload, csrf:SI_CSRF}, function(r){
    if(!r||!r.ok){ $('#urgSetErr').text((r&&r.error)||'儲存失敗'); return; }
    closeMask('urgSetMask'); showToast('已儲存'); if(LT_DATA) loadLeadtime();
  }, 'json').fail(function(x){
    var msg = '儲存失敗（HTTP '+x.status+'）';
    try { var j = JSON.parse(x.responseText); if(j && j.error) msg = j.error; } catch(e){}
    $('#urgSetErr').text(msg);
  });
});
<?php endif; ?>

function renderNote(){
  var m = DATA.meta, h = [];
  h.push('<b>本期</b>：'+esc(m.period.label)+'（'+dispDate(m.period_eff.start)+'～'+dispDate(m.period_eff.end)+'）'
       + '　<b>基期</b>：'+esc(m.cmp_period.label)
       + (m.align && m.elapsed_days!==null ? '（本期才過 '+m.elapsed_days+' 天，基期已同步只算到 '+dispDate(m.cmp_period_eff.end)+'）'
                                           : '（'+dispDate(m.cmp_period_eff.start)+'～'+dispDate(m.cmp_period_eff.end)+'）'));
  h.push('<b>訂單金額只算得出「有填單價」的訂單</b>：本期 '+m.ord_px_cov_cur+'%、基期 '+m.ord_px_cov_cmp+'% 有填單價，其餘以 0 計。');
  h.push('<b>出貨性質</b>：'+esc($('#stFilterLabel').text())+'。'
       + (m.warn.no_client ? '　本期有 <b>'+nf(m.warn.no_client)+'</b> 筆出貨的客戶對不到客戶主檔（已自成一組並標「未建主檔」）。' : ''));
  $('#noteBar').html('<div class="oa-note">'+h.join('<br>')+'</div>');
  $('.cmpLab').text(m.cmp_label);
}
function renderKpiAlert(){
  var a = DATA.kpi_alert;
  if(!a || !a.below){ $('#kpiAlertBar').html(''); return; }
  var gap = a.month_gap;
  var h = '<div class="oa-note oa-warn" style="border-left-color:var(--coral);background:#FDF0EF;">'
    + '<div style="font-size:15px;font-weight:700;color:var(--coral);margin-bottom:4px;">'
    + '<i class="fa fa-exclamation-triangle"></i> 最近 '+a.n+' 個月有 '+a.bad_count+' 個月「'+esc(a.indicator)+'」未達標</div>'
    + '未達標月份：<b>'+esc(a.bad_list.join('、'))+'</b>。<br>';
  if(gap === null){ h += '本年度沒有設定月銷貨目標金額，算不出本月還差多少。'; }
  else {
    h += '<b>'+a.this_year+'/'+a.this_month+'月</b> 目標 <b>'+money(a.month_target)+'</b> 元，'
       + '目前已出貨 <b>'+money(a.month_got)+'</b> 元'
       + (gap > 0 ? ('，<b style="color:var(--coral);font-size:15px;">還差 '+money(gap)+' 元</b>，只剩 <b>'+a.days_left+'</b> 天'
                     +(a.workdays_left!=null?'（約 <b>'+a.workdays_left+'</b> 個工作天）':'')+'。')
                  : '，<b style="color:#2E7D32;">本月已達標</b>。');
  }
  $('#kpiAlertBar').html(h);
}
var _insSeq = 0;
function renderInsights(){
  var list = DATA.insights || [], icons = {bad:'fa-times-circle', warn:'fa-exclamation-circle', good:'fa-check-circle', info:'fa-info-circle'};
  if(!list.length){ $('#insightList').html('<div style="font-size:12px;color:var(--muted);">本期沒有需要特別指出的變化。</div>'); return; }
  var order = {bad:0, warn:1, good:2, info:3};
  list = list.slice().sort(function(a,b){ return (order[a.level]||9) - (order[b.level]||9); });
  var h = '';
  list.forEach(function(x){
    var hasList = x.clients && x.clients.length;
    var listId = hasList ? ('insCli'+(_insSeq++)) : '';
    h += '<div class="ins ins-'+esc(x.level)+'">'
       + '<div class="ic"><i class="fa '+(icons[x.level]||'fa-info-circle')+'"></i></div>'
       + '<div class="bd"><div class="tt">'+esc(x.title)
       + (hasList ? ' <span class="ins-toggle" onclick="siToggleInsList(\''+listId+'\',this)">展開名單 <i class="fa fa-caret-down"></i></span>' : '')
       + '</div><div class="dt">'+esc(x.detail)+'</div>'
       + (hasList ? siRenderInsClientGrid(x, listId) : '') + '</div>'
       + (x.metric ? '<div class="mt">'+esc(x.metric)+'</div>' : '') + '</div>';
  });
  $('#insightList').html(h);
}
function siRenderInsClientGrid(x, listId){
  var cols = (x.clients.length > 18) ? 4 : 3;
  var amtLabel = esc(x.cmp_label||'上期')+'出貨金額';
  var colStyle = 'grid-template-columns:repeat('+cols+',1fr);';
  var head=''; for(var i=0;i<cols;i++) head += '<div class="ins-cli-hcell"><span>客戶名稱</span><span>'+amtLabel+'</span></div>';
  var cells = x.clients.map(function(c){
    var amt = nf(c.amount)+' 元';
    var nameHtml = (c.cid && c.cid !== '0' && !c.bad)
      ? '<a onclick="oaOpenCustomerView(\''+esc(c.cid)+'\');return false;" href="#">'+esc(c.name)+'</a>'
      : '<span title="未建客戶主檔">'+esc(c.name)+'</span>';
    return '<div class="ins-cli-cell"><span class="ins-cli-name">'+nameHtml+'</span><span class="ins-cli-amt">'+amt+'</span></div>';
  }).join('');
  return '<div class="ins-cli-wrap" id="'+listId+'" style="display:none;">'
       + '<div class="ins-cli-grid ins-cli-head" style="'+colStyle+'">'+head+'</div>'
       + '<div class="ins-cli-grid" style="'+colStyle+'">'+cells+'</div></div>';
}
function siToggleInsList(id, el){
  var box = document.getElementById(id); if(!box) return;
  var show = box.style.display === 'none';
  box.style.display = show ? 'block' : 'none';
  $(el).html(show ? '收合名單 <i class="fa fa-caret-up"></i>' : '展開名單 <i class="fa fa-caret-down"></i>');
}

/* ── 建議採取 ───────────────────────────────────────── */
// 2026-10-06 使用者交辦：出貨分析也要跟訂單分析一樣有這個區塊，資料是 si_recommend()
// （shipping_insight_lib.php）依「哪些自動分析結論出現了」對應出的行動清單，這裡只負責畫。
function renderRecommend(){
  var list = DATA.recommend || [], icons = {bad:'fa-exclamation-triangle', warn:'fa-exclamation-circle',
                                             good:'fa-check-circle', info:'fa-info-circle'};
  if(!list.length){ $('#recommendList').html('<div style="font-size:12px;color:var(--muted);">本期沒有特別需要處理的異常。</div>'); return; }
  var h = '';
  list.forEach(function(x){
    var acts = (x.actions||[]).map(function(a){ return '<li>'+esc(a)+'</li>'; }).join('');
    h += '<div class="ins ins-'+esc(x.level)+'">'
       + '<div class="ic"><i class="fa '+(icons[x.level]||'fa-info-circle')+'"></i></div>'
       + '<div class="bd"><div class="tt">'+esc(x.title)+'</div>'
       + (acts ? '<ul class="rec-actions">'+acts+'</ul>' : '')
       + '</div></div>';
  });
  $('#recommendList').html(h);
}

function renderMa(){
  var ma = DATA.ma;
  if(!ma || !ma.series){ $('#maNote').html('無法計算'); return; }
  var thrTxt = ma.mode === 'manual' ? ('自訂 '+money(ma.manual_value)+' 元／月') : '該年度月銷貨目標金額';
  var st = ma.hit ? ('<b style="color:var(--coral);">已連續 '+ma.streak+' 個月低於安全水平</b>')
                  : (ma.streak ? ('目前連續 '+ma.streak+' 個月低於安全水平（需連續 '+ma.need+' 個月才示警）')
                               : '<b style="color:#2E7D32;">目前正常</b>');
  $('#maNote').html('判定方式：取「前 <b>'+ma.months+'</b> 個月出貨淨額的移動平均」，連續 <b>'+ma.need+'</b> 個月低於安全水平即示警。'
    + '　安全水平＝'+esc(thrTxt)+'。　狀態：'+st);
  $('#maHint').text('每月自動評估，出貨金額移動平均是否連續低於安全水平');
  var cats = ma.series.map(function(s){ return s.ym; });
  chart('chMa', opt({
    xAxis:{ categories:cats },
    yAxis:{ title:{text:'金額（萬元）',style:{fontSize:'11px',color:'var(--muted)'}}, gridLineColor:'#EAF3FC',
            labels:{style:{fontSize:'10px',color:'var(--muted)'}} },
    tooltip:{ shared:true, style:{fontSize:'11px'} },
    series:[
      { name:'當月出貨淨額', type:'column', color:C_LIGHT, borderRadius:3, data: ma.series.map(function(s){ return wan(s.amount); }) },
      { name:'前'+ma.months+'月移動平均', type:'line', color:C_BLUE, lineWidth:3, marker:{radius:4},
        data: ma.series.map(function(s){ return { y:wan(s.avg), color: s.below ? C_CORAL : C_BLUE }; }) },
      { name:'安全水平', type:'line', color:C_CORAL, dashStyle:'ShortDash', lineWidth:2, marker:{enabled:false},
        data: ma.series.map(function(s){ return s.threshold===null? null : wan(s.threshold); }) }
    ]
  }));
  var h='';
  ma.series.forEach(function(s){
    var judge = s.below ? '<span class="badge-new">低於安全水平</span>' : '<span style="color:#2E7D32;">正常</span>';
    h += '<tr><td>'+esc(s.ym)+'</td><td class="n">'+money(s.amount)+'</td><td class="n">'+money(s.avg)+'</td>'
       + '<td class="n">'+(s.threshold===null?'未設定':money(s.threshold))+'</td><td>'+judge+'</td></tr>';
  });
  $('#tblMa tbody').html(h||'<tr><td colspan="5" style="text-align:center;color:var(--muted);">沒有資料</td></tr>');
}

function renderKpi(){
  var c = DATA.kpi.cur, p = DATA.kpi.cmp, m = DATA.meta;
  function card(lab, val, sub, cls){
    return '<div class="kpi-card '+(cls||'')+'"><div class="k-lab">'+lab+'</div><div class="k-val">'+val+'</div><div class="k-sub">'+sub+'</div></div>';
  }
  var h = '';
  h += card('出貨金額', money(c.ship_amount), '較'+esc(m.cmp_label)+' '+deltaHtml(c.ship_amount,p.ship_amount,money));
  h += card('出貨數量', nf(c.ship_qty), '較'+esc(m.cmp_label)+' '+deltaHtml(c.ship_qty,p.ship_qty));
  h += card('出貨筆數', nf(c.ship_rows), '較'+esc(m.cmp_label)+' '+deltaHtml(c.ship_rows,p.ship_rows));
  h += card('退貨金額', money(c.ret_amount), '較'+esc(m.cmp_label)+' '+deltaHtml(c.ret_amount,p.ret_amount,money));
  h += card('淨額（出貨－退貨）', money(c.net_amount), '較'+esc(m.cmp_label)+' '+deltaHtml(c.net_amount,p.net_amount,money));
  h += card('訂單金額', money(c.ord_amount), '有單價 '+c.ord_px+'/'+c.ord_rows+' 張');
  h += card('客戶數', nf(c.clients), '較'+esc(m.cmp_label)+' '+deltaHtml(c.clients,p.clients));
  h += card('未確認異常', nf(c.anomaly_unconfirmed), '本期共判定異常 '+nf(c.anomaly)+' 筆', c.anomaly_unconfirmed>0?'k-warn':'');
  $('#kpiRow').html(h);
}

function renderTrend(){
  var t = DATA.trend, showPrev = $('#cbPrevYear').is(':checked');
  var cats = t.cur.map(function(b){ return b.label.replace(/^\d{4}\s*/,''); });
  var ship = t.cur.map(function(b){ return wan(b.ship_amount); });
  var ret  = t.cur.map(function(b){ return wan(b.ret_amount); });
  var ord  = t.cur.map(function(b){ return wan(b.ord_amount); });
  var net  = t.cur.map(function(b){ return wan(b.net_amount); });
  var ser = [
    { name:DATA.meta.year+' 出貨', type:'column', data:ship, color:C_BLUE, borderRadius:3 },
    { name:DATA.meta.year+' 退貨', type:'column', data:ret, color:C_CORAL, borderRadius:3 },
    { name:DATA.meta.year+' 訂單', type:'column', data:ord, color:C_LIGHT, borderRadius:3 },
    { name:DATA.meta.year+' 淨額', type:'line', data:net, color:C_NAVY, lineWidth:3, marker:{radius:4} }
  ];
  if(showPrev){
    var netPrev = t.prev.map(function(b){ return wan(b.net_amount); });
    ser.push({ name:t.prev_year+' 淨額', type:'line', data:netPrev, color:'#8FC1EA', dashStyle:'ShortDash', lineWidth:2, marker:{radius:3, symbol:'diamond'} });
  }
  chart('chTrend', opt({
    xAxis:{ categories:cats },
    yAxis:{ title:{text:'金額（萬元）',style:{fontSize:'11px',color:'var(--muted)'}}, gridLineColor:'#EAF3FC',
            labels:{style:{fontSize:'10px',color:'var(--muted)'}} },
    tooltip:{ shared:true, style:{fontSize:'11px'} },
    plotOptions:{ column:{ grouping:true, pointPadding:0.05, groupPadding:0.14 } },
    series: ser
  }));
  $('#trendHint').text('柱＝出貨／退貨／訂單金額，折線＝淨額（萬元）');
  var h='';
  t.cur.forEach(function(b){
    h += '<tr><td>'+esc(b.label)+'</td><td class="n">'+money(b.ship_amount)+'</td><td class="n">'+money(b.ret_amount)+'</td>'
       + '<td class="n">'+money(b.ord_amount)+'</td><td class="n">'+money(b.net_amount)+'</td>'
       + '<td class="n">'+nf(b.ship_rows)+'</td><td class="n">'+nf(b.ret_rows)+'</td></tr>';
  });
  $('#tblTrend tbody').html(h);
}
$('#cbPrevYear').on('change', function(){ if(DATA) renderTrend(); });

function renderSaleType(){
  var s = DATA.sale_type_stat || [];
  chart('chSaleType', opt({
    chart:{ type:'pie' },
    tooltip:{ pointFormat:'<b>{point.y:,.0f} 元</b>（{point.percentage:.1f}%）', style:{fontSize:'11px'} },
    plotOptions:{ pie:{ dataLabels:{ enabled:true, style:{fontSize:'11px',color:'#1B4F78',textOutline:'none'},
                        format:'{point.name}<br>{point.percentage:.0f}%' } } },
    series:[{ name:'出貨金額', colorByPoint:true, data:s.map(function(x,i){ return {name:x.name, y:x.amount, color:PAL[i%PAL.length]}; }) }]
  }));
  var h=''; s.forEach(function(x){ h += '<tr><td>'+esc(x.name)+'</td><td class="n">'+nf(x.rows)+'</td><td class="n">'+nf(x.qty)+'</td><td class="n">'+money(x.amount)+'</td></tr>'; });
  $('#tblSaleType tbody').html(h||'<tr><td colspan="4" style="text-align:center;color:var(--muted);">本期沒有出貨</td></tr>');
}

/* ── AS 稽核分類（透過出貨單綁定的訂單反查「稽核製程標籤（AS 認定）」）──────
   出貨單本身沒有這個欄位，要透過「出貨單↔訂單」綁定才查得到該訂單的 AS 認定，
   所以「未綁定訂單」與「訂單尚未設定標籤」兩種缺口要分開講清楚，不能混在一起；
   分類清單完全跟著訂單追蹤「稽核製程標籤」的設定自動變動，類別多時表格頁內分頁。 */
/* 分類名稱左側的 AS／非AS 籤：is_proc=1（kind=process，管理員設定的稽核製程，如齒研/插齒）＝AS，
   其餘固定選項／管理員自訂其他選項＝非AS；與 NewOrder_Track.php 下拉分組同一套規則。
   「未綁定訂單」「訂單尚未設定標籤」沒有認定結果，不給籤。 */
function astagBadge(t){
  if(t.key === 'unbound' || t.key === 'unset') return '';
  return t.is_proc ? '<span class="badge-as">AS</span>' : '<span class="badge-nonas">非AS</span>';
}
function astagLabelText(t){
  if(t.key === 'unbound' || t.key === 'unset') return t.label;
  return (t.is_proc ? 'AS ' : '非AS ') + t.label;
}
var ASTAG_PAGE = 1, ASTAG_PER = 10;
function renderAstag(){
  var a = DATA.astag || {rows:[], trend:[], buckets:[], unbound:null, unset:null, bound_pct:0}, rows = a.rows||[];
  var met = $('#astagMetric').val();
  var unboundRows = (a.unbound && a.unbound.cur) ? a.unbound.cur.ship_rows : 0;
  $('#astagHint').text('本期出貨有綁定訂單的佔 '+nf1(a.bound_pct)+'%'
    + (unboundRows ? '，還有 '+nf(unboundRows)+' 筆未綁定訂單（列在下表）' : '')
    + '；分類由「訂單追蹤 → 設定 → 稽核製程標籤（AS 認定）」決定，改了這裡自動跟著變');

  /* 圓餅：只畫真的查得到分類的那一部分（未綁定／訂單尚未設定標籤不畫進圓餅，
     混進去會佔掉一大塊、模糊了已查得到分類的真實比例） */
  var pieRows = rows.filter(function(t){ return t.key!=='unbound' && t.key!=='unset' && Number(t.cur[met])>0; });
  chart('chAstagPie', opt({
    chart:{ type:'pie' },
    tooltip:{ pointFormat: met==='amount' ? '<b>{point.y:,.0f} 元</b>（{point.percentage:.1f}%）'
                                           : '<b>{point.y:,.0f}</b>（{point.percentage:.1f}%）', style:{fontSize:'11px'} },
    plotOptions:{ pie:{ dataLabels:{ enabled:true, style:{fontSize:'11px',color:'#1B4F78',textOutline:'none'},
                        format:'{point.name}<br>{point.percentage:.0f}%' } } },
    series:[{ name:'本期（已查得到分類）', colorByPoint:true,
               data: pieRows.map(function(t,i){ return { name:t.label, y:t.cur[met], color:PAL[i%PAL.length] }; }) }]
  }));

  var buckets = (a.buckets||[]).map(function(s){ return s.replace(/^\d{4}\s*/,''); });
  var ser = (a.trend||[]).map(function(s, i){
    var key = (met==='rows') ? 'rows' : met;
    return { name:s.label, data:(s[key]||[]), color:PAL[i%PAL.length], borderRadius:2 };
  });
  chart('chAstagTrend', opt({
    chart:{ type:'column' },
    xAxis:{ categories: buckets },
    yAxis:{ min:0, title:{text:(met==='amount'?'出貨金額':(met==='qty'?'數量':'筆數')),style:{fontSize:'11px',color:'var(--muted)'}},
            gridLineColor:'#EAF3FC', labels:{style:{fontSize:'10px',color:'var(--muted)'}} },
    tooltip:{ shared:true, style:{fontSize:'11px'} },
    plotOptions:{ column:{ stacking:'normal', borderRadius:2, pointPadding:0.04, groupPadding:0.12 } },
    series: ser.length ? ser : [{ name:'（沒有資料）', data:[] }]
  }));

  ASTAG_PAGE = 1;
  renderAstagTable();
}
function renderAstagTable(){
  var a = DATA.astag || {rows:[]}, rows = (a.rows||[]).slice();
  var total = rows.length, pages = Math.max(1, Math.ceil(total/ASTAG_PER));
  if(ASTAG_PAGE > pages) ASTAG_PAGE = pages;
  if(ASTAG_PAGE < 1) ASTAG_PAGE = 1;
  var slice = rows.slice((ASTAG_PAGE-1)*ASTAG_PER, ASTAG_PAGE*ASTAG_PER);
  var h = '';
  slice.forEach(function(t){
    var isGap = (t.key === 'unbound' || t.key === 'unset');
    h += '<tr'+(isGap?' style="color:var(--muted);"':'')+'>'
       + '<td>'+astagBadge(t)+esc(t.label)+(isGap?' <span class="badge-warn">查不到分類</span>':'')+'</td>'
       + '<td class="n">'+nf(t.cur.ship_rows)+'</td><td class="n">'+nf(t.cur.ship_qty)+'</td>'
       + '<td class="n grp-div">'+money(t.cur.ship_amount)+'</td>'
       + '<td class="n">'+nf(t.cmp.ship_rows)+'</td>'
       + '<td class="n">'+deltaHtml(t.cur.ship_amount, t.cmp.ship_amount, money)+'</td></tr>';
  });
  $('#tblAstag tbody').html(h || '<tr><td colspan="6" style="text-align:center;color:var(--muted);">本期沒有資料</td></tr>');

  if(total <= ASTAG_PER){ $('#astagPager').html(''); return; }
  var ph = '<button type="button" id="astagPrev"'+(ASTAG_PAGE<=1?' disabled':'')+'><i class="fa fa-angle-left"></i> 上一頁</button>'
    + '<span>第 '+ASTAG_PAGE+' / '+pages+' 頁（共 '+total+' 個分類）</span>'
    + '<button type="button" id="astagNext"'+(ASTAG_PAGE>=pages?' disabled':'')+'>下一頁 <i class="fa fa-angle-right"></i></button>';
  $('#astagPager').html(ph);
}
$(document).on('click', '#astagPrev', function(){ ASTAG_PAGE--; renderAstagTable(); });
$(document).on('click', '#astagNext', function(){ ASTAG_PAGE++; renderAstagTable(); });
$('#astagMetric').on('change', function(){ if(DATA) renderAstag(); });

// 2026-10-06 使用者交辦：客戶比較表也要能切「依訂單標籤分類」／「依全製/多製程/單製」，
// 與訂單分析同一套交叉資料做法（DATA.client_by_cls／DATA.client_by_astag，categories 換成
// DATA.client_cmp.names 這份跟比較圖同一組客戶）；只能依「出貨單」統計（si_report() 已講明：
// 退貨／訂單沒有綁定 AS 標籤的途徑）。
var CLI_TAB = 'all';
var TBL_CLIENT_COLS_HTML = '<col style="width:16%"><col style="width:13%"><col style="width:13%"><col style="width:13%">'
  + '<col style="width:13%"><col style="width:13%"><col style="width:19%">';
var TBL_CLIENT_HEAD_HTML = '<tr><th>客戶</th><th>出貨</th><th>退貨</th><th>訂單</th><th>淨額</th><th>異常筆數</th><th>較<span class="cmpLab">基期</span>淨額增減</th></tr>';
function cliTab(t){
  CLI_TAB = t;
  ['cliTabAll','cliTabAstag','cliTabCls'].forEach(function(id){
    var on = id === 'cliTab'+(t==='all'?'All':(t==='astag'?'Astag':'Cls'));
    $('#'+id).toggleClass('btn-primary', on).toggleClass('btn-default', !on);
  });
  renderClient();
}
function renderClient(){
  if (CLI_TAB !== 'all') {
    renderClientCross(CLI_TAB === 'cls' ? (DATA.client_by_cls||[]) : (DATA.client_by_astag||[]));
    return;
  }
  $('#tblClientCols').html(TBL_CLIENT_COLS_HTML);
  $('#tblClientHead').html(TBL_CLIENT_HEAD_HTML);
  var cc = DATA.client_cmp, met = $('#cliMetric').val(), m = DATA.meta;
  var cats = (m.buckets||[]).map(function(s){ return s.replace(/^\d{4}\s*/,''); });
  var ser = (cc.series||[]).map(function(s, i){
    return { name:s.name, data:(s[met]||[]).map(function(v){ return wan(v); }), color:PAL[i%PAL.length], borderRadius:2 };
  });
  chart('chClient', opt({
    chart:{ type:'column' },
    xAxis:{ categories:cats },
    yAxis:{ title:{text:'金額（萬元）',style:{fontSize:'11px',color:'var(--muted)'}}, gridLineColor:'#EAF3FC',
            labels:{style:{fontSize:'10px',color:'var(--muted)'}} },
    tooltip:{ shared:true, style:{fontSize:'11px'} },
    plotOptions:{ column:{ pointPadding:0.02, groupPadding:0.12 } },
    series: ser.length? ser : [{ name:'（沒有資料）', data:[] }]
  }));
  var keys = cc.keys||[], byKey={};
  (DATA.clients||[]).forEach(function(c){ byKey[c.key]=c; });
  var h='';
  keys.forEach(function(k){
    var c = byKey[k]; if(!c) return;
    h += '<tr><td>'+esc(c.name)+(c.bad?' <span class="badge-warn">未建主檔</span>':'')
       + (c.flag==='new'?' <span class="badge-new">新客戶</span>':'')
       + (c.flag==='return'?' <span class="badge-return">回流客戶</span>':'')+'</td>'
       + '<td class="n">'+money(c.cur.ship_amount)+'</td><td class="n">'+money(c.cur.ret_amount)+'</td>'
       + '<td class="n">'+money(c.cur.ord_amount)+'</td><td class="n">'+money(c.cur.net_amount)+'</td>'
       + '<td class="n">'+(c.cur.anomaly_unconfirmed>0?('<span class="badge-anom">'+nf(c.cur.anomaly_unconfirmed)+'</span>'):'0')+'</td>'
       + '<td class="n">'+deltaHtml(c.cur.net_amount, c.cmp.net_amount, money)+'</td></tr>';
  });
  $('#tblClient tbody').html(h||'<tr><td colspan="7" style="text-align:center;color:var(--muted);">沒有可比較的客戶</td></tr>');
  $('#cliHint').text(Object.keys(CLI_SEL).length ? ('已篩選 '+Object.keys(CLI_SEL).length+' 家客戶') : '未篩選客戶 → 自動取本期淨額前 8 大客戶');
}
/** 依訂單標籤分類／依全製-多製程-單製 共用的客戶交叉表：堆疊柱狀圖＋分類×客戶矩陣表，
    只能依「出貨單」統計，categories 換成客戶名稱。 */
function renderClientCross(list){
  var names = (DATA.client_cmp && DATA.client_cmp.names) || [];
  var palette = PAL;
  var ser = list.map(function(row, i){ return { name: row.label, data: row.bands, color: palette[i % palette.length] }; });
  chart('chClient', opt({
    chart:{ type:'column' },
    xAxis:{ categories: names },
    yAxis:{ min:0, title:{text:'出貨筆數',style:{fontSize:'11px',color:'var(--muted)'}}, gridLineColor:'#EAF3FC',
            labels:{style:{fontSize:'10px',color:'var(--muted)'}} },
    tooltip:{ shared:true, style:{fontSize:'11px'} },
    plotOptions:{ column:{ stacking:'normal', borderRadius:2, pointPadding:0.05, groupPadding:0.16 } },
    series: ser.length ? ser : [{ name:'（本期沒有資料）', data: names.map(function(){return 0;}) }]
  }));
  $('#tblClientCols').html('');
  $('#tblClientHead').html('<tr><th>分類</th>'+names.map(function(n){ return '<th class="tr">'+esc(n)+'</th>'; }).join('')+'<th class="tr">合計</th></tr>');
  var h = '';
  list.forEach(function(row){
    var tot = row.bands.reduce(function(a,v){ return a+v; }, 0);
    h += '<tr><td>'+esc(row.label)+(row.is_proc?' <span class="badge-as">AS</span>':'')+'</td>'
       + row.bands.map(function(v){ return '<td class="n">'+(v?nf(v):'')+'</td>'; }).join('')
       + '<td class="n"><b>'+nf(tot)+'</b></td></tr>';
  });
  $('#tblClient tbody').html(h || '<tr><td colspan="'+(names.length+2)+'" style="text-align:center;color:var(--muted);">沒有可比較的客戶</td></tr>');
  $('#cliHint').text('依'+(CLI_TAB==='cls'?'全製/多製程/單製':'訂單標籤')+'分類交叉統計出貨單筆數，客戶範圍與上方「客戶比較表」相同'
    + (Object.keys(CLI_SEL).length ? '（已篩選 '+Object.keys(CLI_SEL).length+' 家客戶）' : '（未篩選 → 自動取本期淨額前 8 大客戶）'));
}
$('#cliMetric').on('change', function(){ if(DATA) renderClient(); });

function renderRank(){
  var m = DATA.meta, top = parseInt($('#rankTop').val(),10)||15;
  var rows = (DATA.rank_clients||[]).slice();
  var ups   = rows.filter(function(c){ return c.d_net > 0; }).slice(0, top);
  var downs = rows.filter(function(c){ return c.d_net < 0; });
  downs.sort(function(a,b){ return a.d_net-b.d_net; });
  downs = downs.slice(0, top);
  var lost = rows.filter(function(c){ return c.flag==='lost'; });
  var mix = ups.slice().reverse().concat(downs);
  var hRank = Math.max(280, mix.length*24+90);
  sizeBox('chRank', hRank);
  chart('chRank', opt({
    chart:{ type:'bar', height: hRank },
    xAxis:{ categories: mix.map(function(c){ return c.name; }), labels:{style:{fontSize:'11px',color:'#1B4F78'}} },
    yAxis:{ title:{text:'較'+m.cmp_label+'淨額增減（萬元）',style:{fontSize:'11px',color:'var(--muted)'}},
            gridLineColor:'#EAF3FC', labels:{style:{fontSize:'10px',color:'var(--muted)'}},
            plotLines:[{ value:0, color:'#8A2E2E', width:1, dashStyle:'Dash' }] },
    legend:{ enabled:false },
    tooltip:{ style:{fontSize:'11px'}, formatter:function(){ return '<b>'+this.x+'</b><br>增減：<b>'+nf1(this.y)+' 萬元</b>'; } },
    plotOptions:{ bar:{ borderRadius:2, dataLabels:{ enabled:true, style:{fontSize:'10px',color:'#1B4F78',textOutline:'none'},
                        formatter:function(){ return nf1(this.y); } } } },
    series:[{ name:'增減', data: mix.map(function(c){ var v = wan(c.d_net); return { y:v, color: v>=0 ? C_GREEN : C_CORAL }; }) }]
  }));
  function rowsHtml(arr){
    var h=''; arr.forEach(function(c){
      h += '<tr><td>'+esc(c.name)
         + (c.flag==='new'?' <span class="badge-new">新客戶</span>':'')
         + (c.flag==='return'?' <span class="badge-return">回流客戶</span>':'')
         + (c.flag==='lost'?' <span class="badge-lost">本期掛零</span>':'')
         + (c.bad?' <span class="badge-warn">未建主檔</span>':'')+'</td>'
         + '<td class="n">'+money(c.cur.net_amount)+'</td><td class="n">'+money(c.cmp.net_amount)+'</td>'
         + '<td class="n">'+deltaHtml(c.cur.net_amount, c.cmp.net_amount, money)+'</td></tr>';
    });
    return h;
  }
  $('#tblRankUp').find('tbody').html(rowsHtml(ups)||'<tr><td colspan="4" style="text-align:center;color:var(--muted);">沒有成長的客戶</td></tr>');
  var dh = rowsHtml(downs);
  if(lost.length){
    dh += '<tr><td colspan="4" style="background:#F3F9FE;color:#1B4F78;font-size:11px;">'
        + '<b>基期有出貨、本期完全沒有</b>（'+lost.length+' 家）：'
        + esc(lost.map(function(c){return c.name;}).slice(0,40).join('、')) + (lost.length>40?' …':'') + '</td></tr>';
  }
  $('#tblRankDown').find('tbody').html(dh||'<tr><td colspan="4" style="text-align:center;color:var(--muted);">沒有衰退的客戶</td></tr>');
  $('#rankHint').text('依「較'+m.cmp_label+'的淨額增減」排序（不是依百分比）。');
}
$('#rankTop').on('change', function(){ if(DATA) renderRank(); });

/* ══════════════════════════════════════════════════════════
 * 客戶季度分析（唯一實作 client_quarter_lib.php，本頁只是薄包裝畫面）
 * ══════════════════════════════════════════════════════════ */
function cqSwitchTab(tab){
  $('#cqTabTrendBtn,#cqTabRankBtn').removeClass('active');
  $('#cqPaneTrend,#cqPaneRank').hide();
  if(tab==='trend'){ $('#cqTabTrendBtn').addClass('active'); $('#cqPaneTrend').show(); if(!CQ_LOADED) cqLoadQuarters(); }
  else { $('#cqTabRankBtn').addClass('active'); $('#cqPaneRank').show(); cqLoadRank(); }
}
var CQ_LOADED = false, CQ_DATA = null;
function cqLoadQuarters(){
  $.post(SI_API, { action:'cq_quarters', year: $('#fYear').val(), years_back: $('#cqYearsBack').val(),
                   sale_types: JSON.stringify(stSelectedArr()) }, function(r){
    if(!r || !r.ok){ alert((r&&r.error)||'客戶季度分析載入失敗'); return; }
    CQ_DATA = r; CQ_LOADED = true;
    var opts = '<option value="">全部客戶合計</option>';
    r.clients.forEach(function(c){ opts += '<option value="'+esc(c.key)+'">'+esc(c.name)+(c.unmatched?'（未建主檔）':'')+'</option>'; });
    $('#cqClient').html(opts);
    $('#cqQuality').text('訂單金額有單價比例：'+(r.order_quality? Object.keys(r.order_quality).map(function(k){return k+'='+r.order_quality[k].pct+'%';}).join('、') : '—'));
    cqRenderTrend();
  }, 'json');
}
function cqRenderTrend(){
  if(!CQ_DATA) return;
  var key = $('#cqClient').val();
  var quarters = CQ_DATA.quarters;
  var ord=[], ship=[], ret=[], net=[];
  if(!key){
    quarters.forEach(function(q){
      var s={ord:0,ship:0,ret:0,net:0};
      CQ_DATA.clients.forEach(function(c){ var v=c.q[q]; if(v){ s.ord+=v.ord; s.ship+=v.ship; s.ret+=v.ret; s.net+=v.net; } });
      ord.push(wan(s.ord)); ship.push(wan(s.ship)); ret.push(wan(s.ret)); net.push(wan(s.net));
    });
  } else {
    var c = CQ_DATA.clients.find(function(x){ return x.key===key; });
    quarters.forEach(function(q){ var v = c && c.q[q] ? c.q[q] : {ord:0,ship:0,ret:0,net:0};
      ord.push(wan(v.ord)); ship.push(wan(v.ship)); ret.push(wan(v.ret)); net.push(wan(v.net)); });
  }
  chart('chCqTrend', opt({
    chart:{ type:'column' },
    xAxis:{ categories: quarters },
    yAxis:{ title:{text:'金額（萬元）',style:{fontSize:'11px',color:'var(--muted)'}}, gridLineColor:'#EAF3FC',
            labels:{style:{fontSize:'10px',color:'var(--muted)'}} },
    tooltip:{ shared:true, style:{fontSize:'11px'} },
    series:[
      { name:'訂單', type:'column', data:ord, color:C_LIGHT, borderRadius:3 },
      { name:'出貨', type:'column', data:ship, color:C_BLUE, borderRadius:3 },
      { name:'退貨', type:'column', data:ret, color:C_CORAL, borderRadius:3 },
      { name:'淨額', type:'line', data:net, color:C_NAVY, lineWidth:3, marker:{radius:4} }
    ]
  }));
  var h='';
  quarters.forEach(function(q,i){ h += '<tr><td>'+q+'</td><td class="n">'+money(ord[i]*10000)+'</td><td class="n">'+money(ship[i]*10000)
    +'</td><td class="n">'+money(ret[i]*10000)+'</td><td class="n">'+money(net[i]*10000)+'</td></tr>'; });
  $('#tblCqTrend tbody').html(h);
}
$('#cqClient').on('change', cqRenderTrend);
$('#btnCqReload').on('click', function(){ CQ_LOADED=false; cqLoadQuarters(); });

function cqFillRankYear(){
  var y0 = parseInt($('#fYear').val(),10);
  var h=''; for(var y=y0+1;y>=y0-2;y--) h += '<option value="'+y+'"'+(y===y0?' selected':'')+'>'+y+'</option>';
  $('#cqRankYear').html(h);
  var now = new Date();
  $('#cqRankQ').val(String(Math.ceil((now.getMonth()+1)/3)));
}
function cqLoadRank(){
  $.post(SI_API, { action:'cq_growth', year:$('#cqRankYear').val(), q:$('#cqRankQ').val(), compare:$('#cqRankCmp').val(),
                   metric:$('#cqRankMetric').val(), sale_types: JSON.stringify(stSelectedArr()) }, function(r){
    if(!r || !r.ok){ alert((r&&r.error)||'成長／衰退排行載入失敗'); return; }
    var mk = r.metric, rows = r.rows||[];
    var ups = rows.filter(function(c){ return c.delta>0; }).sort(function(a,b){return b.delta-a.delta;}).slice(0,15);
    var downs = rows.filter(function(c){ return c.delta<0; }).sort(function(a,b){return a.delta-b.delta;}).slice(0,15);
    var mix = ups.slice().reverse().concat(downs);
    var hh = Math.max(280, mix.length*24+90);
    sizeBox('chCqRank', hh);
    chart('chCqRank', opt({
      chart:{ type:'bar', height:hh },
      xAxis:{ categories: mix.map(function(c){return c.name;}), labels:{style:{fontSize:'11px',color:'#1B4F78'}} },
      yAxis:{ title:{text:'增減（萬元）',style:{fontSize:'11px',color:'var(--muted)'}}, gridLineColor:'#EAF3FC',
              plotLines:[{value:0,color:'#8A2E2E',width:1,dashStyle:'Dash'}] },
      legend:{enabled:false},
      series:[{ name:'增減', data: mix.map(function(c){ var v=wan(c.delta); return {y:v,color:v>=0?C_GREEN:C_CORAL}; }) }]
    }));
    function rr(arr){ var h=''; arr.forEach(function(c){
      h += '<tr><td>'+esc(c.name)+(c.unmatched?' <span class="badge-warn">未建主檔</span>':'')+'</td>'
         + '<td class="n">'+money(c.cur)+'</td><td class="n">'+money(c.cmp)+'</td>'
         + '<td class="n">'+deltaHtml(c.cur, c.cmp, money)+'</td></tr>'; }); return h; }
    $('#tblCqUp tbody').html(rr(ups)||'<tr><td colspan="4" style="text-align:center;color:var(--muted);">無</td></tr>');
    $('#tblCqDown tbody').html(rr(downs)||'<tr><td colspan="4" style="text-align:center;color:var(--muted);">無</td></tr>');
  }, 'json');
}
$('#btnCqRankReload').on('click', cqLoadRank);
cqFillRankYear();

/* ══════════════════════════════════════════════════════════
 * 明細分頁：出貨明細／退貨單／訂單／客戶統計
 * ══════════════════════════════════════════════════════════ */
var LIST_TAB = 'ship';
var LIST_PAGE = { ship:1, return:1, order:1 };
function listSwitchTab(tab){
  LIST_TAB = tab;
  $('.list-tab-btn').removeClass('active');
  $('#tabBtn'+tab.charAt(0).toUpperCase()+tab.slice(1)).addClass('active');
  $('#paneShip,#paneReturn,#paneOrder,#paneCustomer').hide();
  $('#pane'+tab.charAt(0).toUpperCase()+tab.slice(1)).show();
  listReload();
}
function listReload(){
  if(!DATA) return;
  if(LIST_TAB === 'customer') { listLoadCustomer(); return; }
  var action = LIST_TAB === 'ship' ? 'list_ship' : (LIST_TAB === 'return' ? 'list_return' : 'list_order');
  var page = LIST_PAGE[LIST_TAB] || 1;
  $.get(SI_API, { action:action, year:$('#fYear').val(), gran:$('#fGran').val(), idx:$('#fIdx').val(),
                  clients:JSON.stringify(Object.keys(CLI_SEL)), sale_types:JSON.stringify(stSelectedArr()),
                  kw:$('#listKw').val(), page:page, per:50 }, function(r){
    if(!r || !r.ok){ return; }
    if(LIST_TAB === 'ship') renderShipTable(r);
    else if(LIST_TAB === 'return') renderReturnTable(r);
    else renderOrderTable(r);
  }, 'json');
}
$('#listKw').on('input', function(){ LIST_PAGE = {ship:1,return:1,order:1}; if(DATA) listReload(); });

function renderShipTable(r){
  var h=''; r.rows.forEach(function(x){
    h += '<tr><td>'+dispDate(x.dt)+'</td><td>'+esc(x.no)+'</td><td>'+esc(x.cname)+(x.cbad?' <span class="badge-warn">未建主檔</span>':'')+'</td>'
       + '<td>'+pnoCell(x)+'</td><td>'+esc(x.spec)+'</td><td class="n">'+nf(x.qty)+'</td><td class="n">'+nf(x.price)+'</td>'
       + '<td class="n">'+money(x.amount)+(x.anomaly?(' <span class="badge-anom" title="'+esc(x.anomaly_reason)+'">'+(x.anomaly_confirmed?'已確認':'異常')+'</span>'):'')+'</td></tr>';
  });
  $('#tblShip tbody').html(h||'<tr><td colspan="8" style="text-align:center;color:var(--muted);">本期沒有出貨</td></tr>');
  renderPager('pagerShip', r, 'ship');
}
function renderReturnTable(r){
  var h=''; r.rows.forEach(function(x){
    h += '<tr><td>'+dispDate(x.dt)+'</td><td>'+esc(x.no)+'</td><td>'+esc(x.cname)+(x.cbad?' <span class="badge-warn">未建主檔</span>':'')+'</td>'
       + '<td>'+pnoCell(x)+'</td><td class="n">'+nf(x.qty)+'</td><td class="n">'+nf(x.price)+'</td>'
       + '<td class="n">'+money(x.amount)+'</td><td>'+esc(x.reason)+'</td></tr>';
  });
  $('#tblReturn tbody').html(h||'<tr><td colspan="8" style="text-align:center;color:var(--muted);">本期沒有退貨</td></tr>');
  renderPager('pagerReturn', r, 'return');
}
function orderStatusText(s){ return s==='6' ? '暫停/取消' : (s==='9' ? '已結案' : '進行中'); }
function renderOrderTable(r){
  var h=''; r.rows.forEach(function(x){
    h += '<tr><td>'+dispDate(x.odate)+'</td><td>'+esc(x.no)+'</td><td>'+esc(x.cname)+(x.cbad?' <span class="badge-warn">未建主檔</span>':'')+'</td>'
       + '<td>'+pnoCell(x)+'</td><td class="n">'+nf(x.qty)+'</td><td class="n">'+nf(x.price)+'</td>'
       + '<td class="n">'+(x.haspx?money(x.amount):'<span style="color:var(--muted);">未開價</span>')+'</td>'
       + '<td>'+dispDate(x.ddate)+'</td><td>'+orderStatusText(x.status)+'</td></tr>';
  });
  $('#tblOrder tbody').html(h||'<tr><td colspan="9" style="text-align:center;color:var(--muted);">本期沒有訂單</td></tr>');
  renderPager('pagerOrder', r, 'order');
}
function renderPager(id, r, tab){
  var pages = Math.max(1, Math.ceil(r.total / r.per));
  var h = '<button '+(r.page<=1?'disabled':'')+' onclick="listGoPage(\''+tab+'\','+(r.page-1)+')"><i class="fa fa-chevron-left"></i></button>'
        + '<span class="pg-info">第 '+r.page+' / '+pages+' 頁，共 '+nf(r.total)+' 筆</span>'
        + '<button '+(r.page>=pages?'disabled':'')+' onclick="listGoPage(\''+tab+'\','+(r.page+1)+')"><i class="fa fa-chevron-right"></i></button>';
  $('#'+id).html(h);
}
function listGoPage(tab, page){ LIST_PAGE[tab] = page; listReload(); }
function listLoadCustomer(){
  $.get(SI_API, { action:'list_customer', year:$('#fYear').val(), gran:$('#fGran').val(), idx:$('#fIdx').val(),
                  clients:JSON.stringify(Object.keys(CLI_SEL)), sale_types:JSON.stringify(stSelectedArr()), kw:$('#listKw').val() }, function(r){
    if(!r || !r.ok) return;
    var h=''; r.rows.forEach(function(c){
      var stat = c.flag==='new' ? '<span class="badge-new">新客戶</span>' : (c.flag==='return' ? '<span class="badge-return">回流客戶</span>'
               : (c.flag==='lost' ? '<span class="badge-lost">本期掛零</span>' : ''));
      h += '<tr><td>'+esc(c.name)+(c.bad?' <span class="badge-warn">未建主檔</span>':'')+'</td>'
         + '<td class="n">'+money(c.cur.ship_amount)+'</td><td class="n">'+money(c.cur.ret_amount)+'</td>'
         + '<td class="n">'+money(c.cur.ord_amount)+'</td><td class="n">'+money(c.cur.net_amount)+'</td>'
         + '<td class="n">'+nf(c.cur.anomaly_unconfirmed)+'</td><td>'+stat+'</td></tr>';
    });
    $('#tblCustomer tbody').html(h||'<tr><td colspan="7" style="text-align:center;color:var(--muted);">沒有資料</td></tr>');
  }, 'json');
}

/* ══════════════════════════════════════════════════════════
 * 月份截止日 / 出貨性質設定 / 異常偵測 / 監控設定
 * ══════════════════════════════════════════════════════════ */
$('#btnCutoff').on('click', function(){
  $.get(SI_API, {action:'cutoff_get'}, function(r){
    if(!r || !r.ok) return;
    $('#cutoffDay').val(r.cutoff_day); $('#cutoffErr').text(''); SI_CSRF = r.csrf || SI_CSRF;
    openMask('cutoffMask');
  }, 'json');
});
$('#btnCutoffSave').on('click', function(){
  var d = parseInt($('#cutoffDay').val(),10);
  if(isNaN(d) || d<0 || d>31){ $('#cutoffErr').text('截止日必須介於 0~31'); return; }
  $.post(SI_API, {action:'cutoff_save', cutoff_day:d, csrf:SI_CSRF}, function(r){
    if(!r || !r.ok){ $('#cutoffErr').text((r&&r.error)||'儲存失敗'); return; }
    closeMask('cutoffMask'); showToast(r.message||'已儲存'); CQ_LOADED=false; if(DATA) load();
  }, 'json');
});

$('#btnSaleTypeSetting').on('click', function(){ stAdminLoad(); openMask('saleTypeSetMask'); });
function stAdminLoad(){
  $.post('../../src/store/manage_sale_types.php', {action:'get'}, function(res){
    if(!res || !res.success) return;
    SALE_TYPES = (res.data||[]).filter(function(x){ return String(x.is_active)==='1'; });
    var h=''; (res.data||[]).forEach(function(t){
      h += '<tr><td>'+esc(t.sale_type_name)+'</td>'
         + '<td>'+(String(t.is_count)==='1'?'✓':'')+'</td>'
         + '<td>'+(String(t.exclude_anomaly)==='1'?'✓':'')+'</td>'
         + '<td>'+(String(t.exclude_when_nonzero)==='1'?'✓':'')+'</td>'
         + '<td>'+(String(t.is_active)==='1'?'✓':'<span style="color:var(--muted);">停用</span>')+'</td>'
         + '<td><button class="btn btn-xs btn-warm-o" onclick="stAdminEdit('+t.sale_type_id+')">編輯</button> '
         + '<button class="btn btn-xs btn-default" onclick="stAdminDelete('+t.sale_type_id+')">刪除</button></td></tr>';
    });
    $('#tblStAdmin tbody').html(h);
    window._ST_ADMIN_ROWS = res.data||[];
  }, 'json');
}
function stAdminEdit(id){
  var t = (window._ST_ADMIN_ROWS||[]).find(function(x){ return String(x.sale_type_id)===String(id); });
  if(!t) return;
  $('#st_id').val(t.sale_type_id); $('#st_name').val(t.sale_type_name); $('#st_desc').val(t.description);
  $('#st_sort').val(t.sort_order); $('#st_count').prop('checked', String(t.is_count)==='1');
  $('#st_exclude_anomaly').prop('checked', String(t.exclude_anomaly)==='1');
  $('#st_exclude_when_nonzero').prop('checked', String(t.exclude_when_nonzero)==='1');
  $('#st_active').prop('checked', String(t.is_active)==='1');
}
function stAdminDelete(id){
  if(!confirm('確定刪除這個出貨性質？（若已有出貨資料使用會刪除失敗）')) return;
  $.post('../../src/store/manage_sale_types.php', {action:'delete', sale_type_id:id}, function(res){
    if(!res || !res.success){ alert((res&&res.message)||'刪除失敗'); return; }
    stAdminLoad();
  }, 'json');
}
$('#btnStReset').on('click', function(){
  $('#st_id').val(''); $('#st_name').val(''); $('#st_desc').val(''); $('#st_sort').val(0);
  $('#st_count').prop('checked', true); $('#st_exclude_anomaly').prop('checked', false);
  $('#st_exclude_when_nonzero').prop('checked', false); $('#st_active').prop('checked', true);
});
$('#btnStSave').on('click', function(){
  var name = $('#st_name').val().trim();
  if(!name){ alert('名稱為必填'); return; }
  var payload = { action:'save', sale_type_id:$('#st_id').val(), sale_type_name:name, description:$('#st_desc').val(),
                  sort_order:$('#st_sort').val() };
  if($('#st_count').is(':checked')) payload.is_count = 1;
  if($('#st_exclude_anomaly').is(':checked')) payload.exclude_anomaly = 1;
  if($('#st_exclude_when_nonzero').is(':checked')) payload.exclude_when_nonzero = 1;
  if($('#st_active').is(':checked')) payload.is_active = 1;
  $.post('../../src/store/manage_sale_types.php', payload, function(res){
    if(!res || !res.success){ alert((res&&res.message)||'儲存失敗'); return; }
    showToast('已儲存'); $('#btnStReset').click(); stAdminLoad();
  }, 'json');
});

var ANOM_PAGE = 1;
$('#btnAnomaly').on('click', function(){ ANOM_PAGE = 1; anomLoad(); openMask('anomalyMask'); });
$('#anomShowAll').on('change', function(){ ANOM_PAGE = 1; anomLoad(); });
function anomLoad(){
  $.get(SI_API, { action:'anomaly_list', year:$('#fYear').val(), gran:$('#fGran').val(), idx:$('#fIdx').val(),
                  sale_types:JSON.stringify(stSelectedArr()), all: $('#anomShowAll').is(':checked')?1:0, page:ANOM_PAGE, per:50 }, function(r){
    if(!r || !r.ok){ alert((r&&r.error)||'載入失敗'); return; }
    SI_CSRF = r.csrf || SI_CSRF;
    $('#anomTotal').text('共 '+nf(r.total)+' 筆');
    var bh = ''; (r.by_type||[]).forEach(function(t){ bh += '<span class="chip">'+esc(t.name)+'：'+nf(t.count)+' 筆／'+money(t.amount)+' 元</span> '; });
    $('#anomByType').html(bh);
    var h=''; r.rows.forEach(function(x){
      h += '<tr><td><input type="checkbox" class="anom-cb" data-id="'+x.id+'"></td><td>'+dispDate(x.dt)+'</td>'
         + '<td>'+esc(x.cname)+'</td><td>'+esc(x.pno)+'</td><td class="n">'+nf(x.qty)+'</td><td class="n">'+nf(x.price)+'</td>'
         + '<td class="n">'+money(x.amount)+'</td><td>'+esc(x.st_name)+'</td><td>'+esc(x.anomaly_reason)+'</td></tr>';
    });
    $('#tblAnomaly tbody').html(h||'<tr><td colspan="9" style="text-align:center;color:var(--muted);">沒有異常</td></tr>');
    renderPager('pagerAnom', r, 'anom');
  }, 'json');
}
function listGoPageAnom(page){ ANOM_PAGE = page; anomLoad(); }
// renderPager 會呼叫 listGoPage('anom',N)，補一個轉呼叫
var _origListGoPage = listGoPage;
listGoPage = function(tab, page){ if(tab==='anom'){ ANOM_PAGE=page; anomLoad(); } else _origListGoPage(tab, page); };
$('#btnAnomConfirmSel').on('click', function(){ anomSetConfirm(1); });
$('#btnAnomUnconfirmSel').on('click', function(){ anomSetConfirm(0); });
function anomSetConfirm(v){
  var ids = $('.anom-cb:checked').map(function(){ return parseInt($(this).data('id'),10); }).get();
  if(!ids.length){ alert('請至少勾選一筆'); return; }
  $.post(SI_API, {action:'anomaly_confirm', ids:JSON.stringify(ids), confirm:v, csrf:SI_CSRF}, function(r){
    if(!r || !r.ok){ alert((r&&r.error)||'操作失敗'); return; }
    showToast('已更新 '+r.updated+' 筆'); anomLoad(); if(DATA) load();
  }, 'json');
}

<?php if ($canAdmin): ?>
var SET = { settings:{} };
$('#btnSetting').on('click', function(){
  $.get(SI_API, {action:'settings_get'}, function(r){
    if(!r||!r.ok){ alert((r&&r.error)||'設定載入失敗'); return; }
    SET.settings = r.settings; SI_CSRF = r.csrf || SI_CSRF; $('#setErr').text('');
    $('#setKpiMonths').val(SET.settings.kpi_alert_months);
    $('#setMaEnabled').prop('checked', !!SET.settings.ma_enabled);
    $('#setMaMonths').val(SET.settings.ma_months); $('#setMaCons').val(SET.settings.ma_consecutive);
    $('#setMaMode').val(SET.settings.ma_threshold_mode);
    $('#setMaValue').val(SET.settings.ma_threshold_value||0).prop('disabled', SET.settings.ma_threshold_mode!=='manual');
    $('#setAnomMin').val(SET.settings.anomaly_alert_min);
    openMask('setMask');
  }, 'json');
});
$('#setMaMode').on('change', function(){ $('#setMaValue').prop('disabled', $(this).val()!=='manual'); });
$('#btnMaPreview').on('click', function(){
  $('#maPreviewOut').text('試算中…');
  $.get(SI_API, {action:'ma_preview', months:$('#setMaMonths').val(), consecutive:$('#setMaCons').val()}, function(r){
    if(!r||!r.ok){ $('#maPreviewOut').text((r&&r.error)||'試算失敗'); return; }
    var ma = r.ma, last6 = (ma.series||[]).slice(-6);
    $('#maPreviewOut').html(esc('連續 '+ma.streak+' 個月低於安全水平（'+(ma.hit?'達到':'未達到')+'示警條件）。最近幾期：'
      + last6.map(function(s){ return s.ym+'＝'+(s.below?'低於':'正常'); }).join('、')));
  }, 'json').fail(function(){ $('#maPreviewOut').text('試算失敗'); });
});
$('#btnSetSave').on('click', function(){
  var s = { kpi_alert_months: parseInt($('#setKpiMonths').val(),10)||3,
            ma_enabled: $('#setMaEnabled').is(':checked')?1:0,
            ma_months: parseInt($('#setMaMonths').val(),10)||3,
            ma_consecutive: parseInt($('#setMaCons').val(),10)||2,
            ma_threshold_mode: $('#setMaMode').val(),
            ma_threshold_value: parseFloat($('#setMaValue').val())||0,
            anomaly_alert_min: parseInt($('#setAnomMin').val(),10)||5,
            ma_notify_users: SET.settings.ma_notify_users||[] };
  $.post(SI_API, {action:'settings_save', settings:JSON.stringify(s), csrf:SI_CSRF}, function(r){
    if(!r||!r.ok){ $('#setErr').text((r&&r.error)||'儲存失敗'); return; }
    closeMask('setMask'); showToast('已儲存'); if(DATA) load();
  }, 'json');
});
<?php endif; ?>

/* ══════════════════════════════════════════════════════════
 * 列印報告（比照 Order_Analysis.php：固定 A3 橫式、圖表用 getSVG）
 * ══════════════════════════════════════════════════════════ */
var PR_MG = 14, PR_PAD = 5, PR_W_MM = 420, PR_H_MM = 297;
function prChartSvg(id, w, h){ try { var c = CHARTS[id]; if(!c) return ''; return c.getSVG({ chart:{ width:w, height:h } }); } catch(e){ return ''; } }
function prBadgeTxt(lv){ return lv==='bad'?'要處理':(lv==='warn'?'要注意':(lv==='good'?'正面':'說明')); }
function siPrintHtml(){
  var m = DATA.meta, k = DATA.kpi, printTime = new Date().toLocaleString('zh-TW');
  var css =
    '*{box-sizing:border-box;margin:0;padding:0;}'+
    'body{font-family:"Microsoft JhengHei","微軟正黑體",sans-serif;color:#222;font-size:10.5pt;padding:'+PR_PAD+'mm;}'+
    '@page{size:'+PR_W_MM+'mm '+PR_H_MM+'mm;margin:'+PR_MG+'mm;}'+
    '@media print{*{-webkit-print-color-adjust:exact;print-color-adjust:exact;}thead{display:table-header-group;}tr{page-break-inside:avoid;}.pr-sec{page-break-inside:avoid;}}'+
    '.pr-head{background:#EAF3FC;color:#12324D;padding:6mm 8mm;border-radius:2mm;margin-bottom:4mm;border:1px solid #C7DEF2;border-left:3mm solid #2E7FD6;}'+
    '.pr-co{font-size:17pt;font-weight:700;letter-spacing:2px;text-align:left;}'+
    '.pr-tt{font-size:13pt;text-align:left;margin-top:1mm;color:#1B4F78;}'+
    '.pr-sub{font-size:9pt;text-align:left;margin-top:2mm;color:#3E6B96;}'+
    '.pr-alert{background:#FDF0EF;border:1px solid #D64545;border-left:4mm solid #D64545;border-radius:2mm;padding:4mm 6mm;margin-bottom:4mm;font-size:10pt;line-height:1.7;}'+
    '.pr-alert b.tt{display:block;color:#D64545;font-size:12pt;margin-bottom:1.5mm;}'+
    '.pr-kpi{display:grid;grid-template-columns:repeat(8,minmax(0,1fr));gap:2.5mm;margin-bottom:4mm;}'+
    '.pr-kc{background:#F7FBFF;border:1px solid #C7DEF2;border-top:1mm solid #2E7FD6;border-radius:1.5mm;padding:2.5mm 2mm;}'+
    '.pr-kc.warn{border-top-color:#D64545;}'+
    '.pr-kc .lb{font-size:8pt;color:#6C89A6;} .pr-kc .vl{font-size:12.5pt;font-weight:700;color:#12324D;line-height:1.25;word-break:break-all;}'+
    '.pr-kc .sb{font-size:7.5pt;color:#6C89A6;margin-top:0.5mm;}'+
    '.pr-sec{margin-bottom:4mm;} .pr-sec-title{font-size:12pt;font-weight:700;color:#12324D;border-left:1.2mm solid #2E7FD6;padding-left:2mm;margin-bottom:2mm;}'+
    '.pr-two{display:grid;grid-template-columns:minmax(0,1fr) minmax(0,1fr);gap:5mm;}'+
    '.pr-chart{text-align:center;} .pr-chart svg{max-width:100%;height:auto;}'+
    '.pr-ins{display:flex;gap:2.5mm;align-items:flex-start;border:1px solid #C7DEF2;border-left:1.2mm solid #9BB7CE;border-radius:1.2mm;padding:1.8mm 3mm;margin-bottom:1.5mm;font-size:9pt;line-height:1.55;}'+
    '.pr-ins.bad{border-left-color:#D64545;} .pr-ins.warn{border-left-color:#2E7FD6;} .pr-ins.good{border-left-color:#4F8A4F;}'+
    '.pr-ins .tag{flex:0 0 auto;font-size:7.5pt;font-weight:700;color:#fff;background:#9BB7CE;border-radius:3mm;padding:0.3mm 2mm;}'+
    '.pr-ins.bad .tag{background:#D64545;} .pr-ins.warn .tag{background:#2E7FD6;} .pr-ins.good .tag{background:#4F8A4F;}'+
    '.pr-ins .bd{flex:1 1 auto;} .pr-ins .bd b{color:#12324D;}'+
    'table{width:100%;border-collapse:collapse;table-layout:fixed;font-size:8.8pt;}'+
    'th{background:#1B5FA8;color:#fff;padding:1.3mm 2mm;font-weight:700;white-space:nowrap;}'+
    'td{padding:1.1mm 2mm;border-bottom:0.2mm solid #C7DEF2;overflow:hidden;text-overflow:ellipsis;white-space:nowrap;}'+
    'tr:nth-child(even) td{background:#FBFDFF;} .tr{text-align:right;} .tc{text-align:center;}'+
    '.pr-badge{font-size:7.5pt;border-radius:2.5mm;padding:0.2mm 1.6mm;color:#fff;}'+
    '.pr-badge.new{background:#D64545;} .pr-badge.return{background:#2E7FD6;} .pr-badge.lost{background:#5B7A99;}'+
    '.pr-note{font-size:8pt;color:#1B4F78;background:#F7FBFF;border-left:1mm solid #2E7FD6;padding:2mm 3mm;margin-bottom:3mm;line-height:1.6;}'+
    '.pr-footer{margin-top:3mm;padding-top:2mm;border-top:0.2mm solid #C7DEF2;font-size:7.5pt;color:#6C89A6;text-align:center;}';

  var h = '<div class="pr-head"><div class="pr-co">'+esc(COMPANY||'')+'</div><div class="pr-tt">出貨分析報告</div>'
    + '<div class="pr-sub">期間：'+esc(m.period.label)+'（'+dispDate(m.period_eff.start)+'～'+dispDate(m.period_eff.end)+'）'
    + '　比較基準：'+esc(m.cmp_label)+'（'+esc(m.cmp_period.label)+'）　列印時間：'+esc(printTime)+'</div></div>';

  h += '<div class="pr-note">※訂單金額只算得出「有填單價」的訂單：本期 '+m.ord_px_cov_cur+'%、'+esc(m.cmp_label)+' '+m.ord_px_cov_cmp+'% 有填單價。'
     + '出貨性質：'+esc($('#stFilterLabel').text())+'。</div>';

  if(DATA.kpi_alert && DATA.kpi_alert.below){
    var a = DATA.kpi_alert;
    h += '<div class="pr-alert"><b class="tt">⚠ 最近 '+a.n+' 個月有 '+a.bad_count+' 個月「'+esc(a.indicator)+'」未達標</b>未達標月份：<b>'+esc(a.bad_list.join('、'))+'</b>。'
       + (a.month_gap===null ? '本年度未設定月銷貨目標金額。' : ('本月目標 '+money(a.month_target)+' 元，已出貨 '+money(a.month_got)+' 元'
         + (a.month_gap>0 ? ('，還差 <b>'+money(a.month_gap)+'</b> 元，剩 '+a.days_left+' 天。') : '，已達標。'))) + '</div>';
  }

  function kc(lab, val, sub, warn){ return '<div class="pr-kc'+(warn?' warn':'')+'"><div class="lb">'+lab+'</div><div class="vl">'+val+'</div><div class="sb">'+sub+'</div></div>'; }
  h += '<div class="pr-kpi">'
    + kc('出貨金額', money(k.cur.ship_amount), '較'+esc(m.cmp_label)+' '+(k.cur.ship_amount-k.cmp.ship_amount>=0?'+':'')+money(k.cur.ship_amount-k.cmp.ship_amount))
    + kc('出貨數量', nf(k.cur.ship_qty), '較'+esc(m.cmp_label)+' '+(k.cur.ship_qty-k.cmp.ship_qty>=0?'+':'')+nf(k.cur.ship_qty-k.cmp.ship_qty))
    + kc('退貨金額', money(k.cur.ret_amount), '較'+esc(m.cmp_label)+' '+(k.cur.ret_amount-k.cmp.ret_amount>=0?'+':'')+money(k.cur.ret_amount-k.cmp.ret_amount))
    + kc('淨額', money(k.cur.net_amount), '較'+esc(m.cmp_label)+' '+(k.cur.net_amount-k.cmp.net_amount>=0?'+':'')+money(k.cur.net_amount-k.cmp.net_amount))
    + kc('訂單金額', money(k.cur.ord_amount), '有單價 '+k.cur.ord_px+'/'+k.cur.ord_rows)
    + kc('客戶數', nf(k.cur.clients), '較'+esc(m.cmp_label)+' '+(k.cur.clients-k.cmp.clients>=0?'+':'')+nf(k.cur.clients-k.cmp.clients))
    + kc('出貨筆數', nf(k.cur.ship_rows), '較'+esc(m.cmp_label)+' '+(k.cur.ship_rows-k.cmp.ship_rows>=0?'+':'')+nf(k.cur.ship_rows-k.cmp.ship_rows))
    + kc('未確認異常', nf(k.cur.anomaly_unconfirmed), '共判定 '+nf(k.cur.anomaly)+' 筆', k.cur.anomaly_unconfirmed>0)
    + '</div>';

  h += '<div class="pr-sec"><div class="pr-sec-title">自動分析</div>';
  var order = {bad:0,warn:1,good:2,info:3};
  var ins = (DATA.insights||[]).slice().sort(function(x,y){ return (order[x.level]||9)-(order[y.level]||9); });
  if(!ins.length) h += '<div style="font-size:9pt;color:#6C89A6;">本期沒有需要特別指出的變化。</div>';
  ins.forEach(function(x){
    h += '<div class="pr-ins '+esc(x.level)+'"><span class="tag">'+esc(prBadgeTxt(x.level))+'</span>'
       + '<div class="bd"><b>'+esc(x.title)+(x.metric?'（'+esc(x.metric)+'）':'')+'</b>　'+esc(x.detail)+'</div></div>';
  });
  h += '</div>';

  var trendSvg = prChartSvg('chTrend', 1180, 300);
  if(trendSvg) h += '<div class="pr-sec"><div class="pr-sec-title">相關金額趨勢</div><div class="pr-chart">'+trendSvg+'</div></div>';

  var stSvg = prChartSvg('chSaleType', 500, 260);
  var stRows = ''; (DATA.sale_type_stat||[]).forEach(function(x){
    stRows += '<tr><td>'+esc(x.name)+'</td><td class="tr">'+nf(x.rows)+'</td><td class="tr">'+money(x.amount)+'</td></tr>';
  });
  h += '<div class="pr-sec"><div class="pr-two">'
     + '<div><div class="pr-sec-title">出貨性質分布</div>'+(stSvg?'<div class="pr-chart">'+stSvg+'</div>':'')
     + '<table><colgroup><col style="width:50%"><col style="width:22%"><col style="width:28%"></colgroup>'
     + '<thead><tr><th>性質</th><th class="tr">筆數</th><th class="tr">金額</th></tr></thead><tbody>'+stRows+'</tbody></table></div>'
     + '<div><div class="pr-sec-title">出貨淨額監控</div>'
     + (prChartSvg('chMa',560,260)?'<div class="pr-chart">'+prChartSvg('chMa',560,260)+'</div>':'<div style="font-size:9pt;color:#6C89A6;">無資料</div>')
     + '</div></div></div>';

  // AS 稽核分類（透過出貨單綁定的訂單反查「稽核製程標籤」認定；分類數隨設定自動變動）
  var astagPieSvg = prChartSvg('chAstagPie', 420, 260);
  var astagRows = ''; ((DATA.astag && DATA.astag.rows)||[]).slice(0,12).forEach(function(t){
    astagRows += '<tr><td>'+esc(astagLabelText(t))+'</td><td class="tr">'+nf(t.cur.ship_rows)+'</td><td class="tr">'+money(t.cur.ship_amount)+'</td></tr>';
  });
  if(astagPieSvg || astagRows){
    h += '<div class="pr-sec"><div class="pr-two">'
       + '<div><div class="pr-sec-title">AS 稽核分類（本期有綁定訂單的出貨佔 '+nf1((DATA.astag&&DATA.astag.bound_pct)||0)+'%）</div>'
       + (astagPieSvg?'<div class="pr-chart">'+astagPieSvg+'</div>':'')+'</div>'
       + '<div><div class="pr-sec-title">　</div>'
       + '<table><colgroup><col style="width:50%"><col style="width:24%"><col style="width:26%"></colgroup>'
       + '<thead><tr><th>分類</th><th class="tr">本期筆數</th><th class="tr">本期出貨金額</th></tr></thead>'
       + '<tbody>'+(astagRows||'<tr><td colspan="3" class="tc">無</td></tr>')+'</tbody></table></div>'
       + '</div></div>';
  }

  var rankSvg = prChartSvg('chRank', 1180, Math.min(420, document.getElementById('chRank').offsetHeight||300));
  if(rankSvg) h += '<div class="pr-sec"><div class="pr-sec-title">期間內客戶增減排名</div><div class="pr-chart">'+rankSvg+'</div></div>';

  var ups = (DATA.rank_clients||[]).filter(function(c){return c.d_net>0;}).slice(0,10);
  var downs = (DATA.rank_clients||[]).filter(function(c){return c.d_net<0;}).sort(function(a,b){return a.d_net-b.d_net;}).slice(0,10);
  function rankRows(list){ var s=''; list.forEach(function(c){
    s += '<tr><td>'+esc(c.name)+(c.flag==='new'?' <span class="pr-badge new">新</span>':'')+(c.flag==='return'?' <span class="pr-badge return">回流</span>':'')+(c.flag==='lost'?' <span class="pr-badge lost">掛零</span>':'')+'</td>'+
      '<td class="tr">'+money(c.cur.net_amount)+'</td><td class="tr">'+money(c.cmp.net_amount)+'</td>'+
      '<td class="tr">'+(c.d_net>=0?'+':'')+money(c.d_net)+'</td></tr>'; });
    return s || '<tr><td colspan="4" class="tc">無</td></tr>'; }
  h += '<div class="pr-sec"><div class="pr-two">'
     + '<div><div class="pr-sec-title" style="border-left-color:#4F8A4F;">成長客戶（前 '+ups.length+' 家）</div>'
     + '<table><colgroup><col style="width:38%"><col style="width:20%"><col style="width:20%"><col style="width:22%"></colgroup>'
     + '<thead><tr><th>客戶</th><th class="tr">本期</th><th class="tr">基期</th><th class="tr">增減</th></tr></thead><tbody>'+rankRows(ups)+'</tbody></table></div>'
     + '<div><div class="pr-sec-title" style="border-left-color:#D64545;">衰退客戶（前 '+downs.length+' 家）</div>'
     + '<table><colgroup><col style="width:38%"><col style="width:20%"><col style="width:20%"><col style="width:22%"></colgroup>'
     + '<thead><tr><th>客戶</th><th class="tr">本期</th><th class="tr">基期</th><th class="tr">增減</th></tr></thead><tbody>'+rankRows(downs)+'</tbody></table></div></div></div>';

  h += '<div class="pr-footer">本報告由 EGsystem 出貨分析自動產生｜列印時間：'+esc(printTime)+'</div>';
  return '<!DOCTYPE html><html lang="zh-Hant"><head><meta charset="utf-8"><title>出貨分析報告 '+esc(m.period.label)+'</title><style>'+css+'</style></head><body>'+h+'</body></html>';
}
function prNeedPageCounter(win){
  try {
    var wPx = (PR_W_MM - PR_MG*2) * 96/25.4, hPx = (PR_H_MM - PR_MG*2 - PR_PAD*2) * 96/25.4;
    var body = win.document.body, old = body.style.width;
    body.style.width = Math.round(wPx) + 'px';
    var h = body.scrollHeight;
    body.style.width = old;
    return h > hPx;
  } catch(e){ return false; }
}
function prAddPageCounter(win){
  try {
    var st = win.document.createElement('style');
    st.textContent = "@page{ @bottom-left{ content:'第 ' counter(page) ' 頁／共 ' counter(pages) ' 頁'; font-size:9pt; color:#555; } }";
    win.document.head.appendChild(st);
  } catch(e){}
}
$('#btnPrint').on('click', function(){
  if(!DATA){ alert('請先計算'); return; }
  var w = window.open('', '_blank', 'width=1280,height=900,scrollbars=yes,resizable=yes');
  if(!w){ alert('瀏覽器擋掉了彈出視窗，請允許本站彈出後再試'); return; }
  w.document.write(siPrintHtml()); w.document.close(); w.focus();
  try { if(window.EGPrintLog) EGPrintLog.record({ source:'shipping_insight', doc_name:'出貨分析報告 '+DATA.meta.period.label, doc_kind:'form' }); } catch(e){}
  setTimeout(function(){
    if(prNeedPageCounter(w)) prAddPageCounter(w);
    showToast('列印對話框請把紙張選成 A3，方向選橫向', 'info');
    w.print();
  }, 700);
});

/* ── CSV ─────────────────────────────────────────────── */
$('#btnCsv').on('click', function(){
  if(!DATA){ alert('請先計算'); return; }
  var m = DATA.meta, L = [];
  function row(){ L.push(Array.prototype.slice.call(arguments).map(function(v){
    v = (v==null)?'':String(v); return /[",\n]/.test(v) ? '"'+v.replace(/"/g,'""')+'"' : v;
  }).join(',')); }
  row('出貨分析', m.period.label, m.period_eff.start+'~'+m.period_eff.end, '基期='+m.cmp_period.label, '匯出時間='+m.today);
  row('');
  row('【摘要】','項目','本期','基期');
  [['出貨金額','ship_amount'],['出貨數量','ship_qty'],['出貨筆數','ship_rows'],['退貨金額','ret_amount'],
   ['退貨筆數','ret_rows'],['訂單金額','ord_amount'],['淨額','net_amount'],['客戶數','clients'],
   ['未確認異常','anomaly_unconfirmed']].forEach(function(x){
    row('', x[0], Math.round(DATA.kpi.cur[x[1]]||0), Math.round(DATA.kpi.cmp[x[1]]||0));
  });
  row('');
  row('【相關金額趨勢】','期別','出貨金額','退貨金額','訂單金額','淨額','出貨筆數','退貨筆數');
  DATA.trend.cur.forEach(function(b){ row('', b.label, Math.round(b.ship_amount), Math.round(b.ret_amount), Math.round(b.ord_amount), Math.round(b.net_amount), b.ship_rows, b.ret_rows); });
  row('');
  row('【出貨性質分布】','性質','筆數','數量','金額');
  (DATA.sale_type_stat||[]).forEach(function(x){ row('', x.name, x.rows, x.qty, Math.round(x.amount)); });
  row('');
  row('【AS 稽核分類】','本期有綁定訂單的出貨佔比', (DATA.astag?DATA.astag.bound_pct:0)+'%');
  row('【AS 稽核分類】','分類','本期筆數','本期數量','本期出貨金額','基期筆數','基期出貨金額');
  (DATA.astag && DATA.astag.rows || []).forEach(function(t){
    row('', astagLabelText(t), t.cur.ship_rows, t.cur.ship_qty, Math.round(t.cur.ship_amount), t.cmp.ship_rows, Math.round(t.cmp.ship_amount));
  });
  row('');
  row('【客戶（全部）】','客戶','客戶編號','出貨金額','退貨金額','訂單金額','淨額','異常筆數','狀態');
  (DATA.clients||[]).forEach(function(c){
    row('', c.name, c.cid, Math.round(c.cur.ship_amount), Math.round(c.cur.ret_amount), Math.round(c.cur.ord_amount),
        Math.round(c.cur.net_amount), c.cur.anomaly_unconfirmed,
        (c.flag==='new'?'新客戶':(c.flag==='return'?'回流客戶':(c.flag==='lost'?'本期掛零':'')))+(c.bad?' 未建主檔':''));
  });
  var blob = new Blob(['﻿'+L.join('\r\n')], {type:'text/csv;charset=utf-8;'});
  var a = document.createElement('a');
  a.href = URL.createObjectURL(blob); a.download = '出貨分析_' + m.period.label.replace(/\s/g,'') + '.csv';
  document.body.appendChild(a); a.click();
  setTimeout(function(){ URL.revokeObjectURL(a.href); a.remove(); }, 500);
});

/* ── 初始載入 ─────────────────────────────────────────── */
load();
</script>
</body>
</html>
