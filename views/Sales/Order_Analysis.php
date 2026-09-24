<?php
/**
 * 訂單分析 — 2026-09-22 建立（使用者交辦）
 *
 * 入口：訂單追蹤（NewOrder_Track.php）工具列的「訂單分析」按鈕。
 * 刻意做成獨立頁面而不是塞進訂單追蹤的跳窗：那一頁已經 11,600 行、載入路徑上的每一件事
 * 都直接影響現場每天在用的清單，**使用者明確要求「嚴禁影響現有使用者」**，
 * 所以那邊只多一顆按鈕，其餘一行都不動。
 *
 * 權限沿用訂單追蹤模組（module='order_track'）：
 *   ot_analysis         檢視本頁
 *   ot_analysis_setting 改設定（數量區間、全製／單製關鍵字）
 * 資料一律走 src/store/OrderAnalysis_API.php；計算唯一實作在 src/common/order_analysis_lib.php。
 */
ini_set('session.gc_maxlifetime', 43200);
session_set_cookie_params(43200);
session_start();
if (!isset($_SESSION['userName'])) {
    $_SESSION['lastpage'] = "../../views/Sales/Order_Analysis.php";
    header("Location:../../index.php");
    exit;
}
include_once '../../src/common/_config.php';
include_once '../../src/common/DBConnection.php';
include_once '../../src/common/order_analysis_lib.php';
include_once '../../src/common/role_features_helper.php';
include_once '../../src/common/org_role_lib.php';   // eg_company_full_name()：列印大標題的公司全名（禁寫死）

$db  = (new DBConnection())->getPDO();
$uid = (int)($_SESSION['id'] ?? 0);

$feat    = rf_load_user_features_all($db, $uid);
$isAdmin = in_array('all', $feat, true);
$canView = $isAdmin || in_array('ot_analysis', $feat, true);
$canSet  = $isAdmin || in_array('ot_analysis_setting', $feat, true);

if (empty($_SESSION['oa_csrf'])) $_SESSION['oa_csrf'] = bin2hex(random_bytes(16));
$CSRF = $_SESSION['oa_csrf'];

$years     = oa_years($db);
$thisYear  = (int)date('Y');
$defYear   = in_array($thisYear, $years, true) ? $thisYear : (int)$years[0];
$roleLabel = $isAdmin ? '管理員' : ($canSet ? '訂單分析（可設定）' : ($canView ? '訂單分析（檢視）' : '無權限'));
// 列印大標題＝本公司全名（ai-rules/16 第一節：動態取自客戶主檔標記「本公司」那一筆，禁寫死）
$COMPANY = '';
try { $COMPANY = eg_company_full_name($db); } catch (Throwable $e) { $COMPANY = ''; }
function oaEsc($v) { return htmlspecialchars((string)$v, ENT_QUOTES, 'UTF-8'); }
?>
<!DOCTYPE html>
<html lang="zh-Hant">
<head>
<meta charset="utf-8">
<meta http-equiv="X-UA-Compatible" content="IE=edge">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>訂單分析</title>
<link href="../../resource/css/bootstrap.css" rel="stylesheet">
<link href="../../resource/css/font-awesome.css" rel="stylesheet">
<link href="../../resource/css/nprogress.css" rel="stylesheet">
<link href="../../resource/css/custom.css" rel="stylesheet">
<style>
/* 側欄：CSS 藏起來、ready 時再顯示（鐵律6，CSS 與 JS 必須成對，只抄一半側欄會整片消失） */
#sidebar-menu { visibility: hidden; }
:root{ --ink:#4A3524; --cream:#FCF7F0; --sand:#F7E0BD; --amber:#F0A24B; --amber-d:#C77C1A;
       --coral:#DD5138; --line:#E4D3BC; --muted:#a08a6f; --brown:#8a5a2b; }
body { background:#F6F1EA; }
/* .right_col 第一個子元素一律 clear:both（鐵律6：頂欄高度 0 且浮動溢出，會把 BFC 子元素壓成寬 0） */
.right_col .page-title { margin:8px 0 4px; overflow:hidden; clear:both; }
.page-title h3 { color:var(--ink); margin:0; display:flex; align-items:center; gap:10px; flex-wrap:wrap; font-size:22px; }
.page-help-btn { height:30px; font-size:13px; padding:0 12px; border:1px solid var(--amber-d);
                 border-radius:15px; background:#fff; color:var(--amber-d); }
.page-help-btn:hover { background:var(--amber-d); color:#fff; }
@media print { .page-help-btn { display:none !important; } }
.role-tag { font-size:12px; background:#EFE3CF; color:#6B4423; border-radius:10px; padding:2px 10px; font-weight:normal; }
.warm-panel { background:#fff; border:1px solid var(--line); border-radius:8px; padding:12px 14px; margin-bottom:12px; }
.btn-warm { background:var(--amber); border:1px solid var(--amber-d); color:var(--ink); font-weight:bold; }
.btn-warm:hover,.btn-warm:focus { background:var(--amber-d); color:#fff; }
.btn-warm-o { background:#fff; border:1px solid var(--amber-d); color:var(--amber-d); }
.btn-warm-o:hover { background:var(--sand); color:var(--ink); }
.oa-bar { display:flex; gap:8px; align-items:center; flex-wrap:wrap; }
.oa-bar label { margin:0; font-size:12px; color:var(--ink); font-weight:600; }
.oa-note { background:#faf6f0; border:1px solid var(--line); border-left:4px solid var(--amber);
           border-radius:6px; padding:8px 12px; font-size:12px; color:#6B4423; line-height:1.8; margin-bottom:12px; }
.oa-note b { color:var(--coral); }
.oa-warn { background:#FDF2EE; border-left-color:var(--coral); }
/* KPI 卡 */
.kpi-row { display:flex; gap:10px; flex-wrap:wrap; margin-bottom:12px; }
.kpi-card { flex:1 1 150px; min-width:150px; background:#fff; border:1px solid var(--line);
            border-top:3px solid var(--amber); border-radius:8px; padding:10px 12px; }
.kpi-card .k-lab { font-size:12px; color:var(--muted); }
.kpi-card .k-val { font-size:24px; font-weight:700; color:var(--ink); line-height:1.2; word-break:break-all; }
.kpi-card .k-sub { font-size:11px; color:var(--muted); margin-top:2px; }
.kpi-card.k-new { border-top-color:var(--coral); }
.up   { color:#2E7D32; font-weight:700; }
.down { color:var(--coral); font-weight:700; }
.flat { color:var(--muted); }
/* 區塊 */
.sec { background:#fff; border:1px solid var(--line); border-radius:8px; padding:12px 14px; margin-bottom:14px; }
.sec h4 { margin:0 0 4px; font-size:16px; color:var(--ink); font-weight:700;
          display:flex; align-items:center; gap:8px; flex-wrap:wrap; }
.sec h4 .hint { font-size:11px; color:var(--muted); font-weight:normal; }
.sec-tools { margin-left:auto; display:flex; gap:6px; align-items:center; flex-wrap:wrap; }
.chart-box { width:100%; height:300px; }
.chart-box.tall { height:360px; }
.two-col { display:flex; gap:14px; flex-wrap:wrap; }
.two-col > div { flex:1 1 380px; min-width:300px; }
/* 表格（一律自動換行、不出現左右捲軸；寬表另外包 overflow-x） */
table.oa-t { width:100%; border-collapse:collapse; font-size:12px; table-layout:fixed; }
table.oa-t th, table.oa-t td { border:1px solid var(--line); padding:4px 6px; vertical-align:middle;
                               word-break:break-all; line-height:1.5; }
table.oa-t th { background:#faf6f0; color:#6B4423; font-weight:700; text-align:center; white-space:nowrap; }
table.oa-t td.n { text-align:right; font-variant-numeric:tabular-nums; }
table.oa-t tbody tr:nth-child(even) { background:#fdfbf8; }
.tbl-wrap { max-height:420px; overflow:auto; border:1px solid var(--line); border-radius:6px; }
.tbl-wrap table.oa-t th { position:sticky; top:0; z-index:2; }
.badge-new  { background:var(--coral); color:#fff; border-radius:9px; padding:1px 7px; font-size:10px; line-height:16px; display:inline-block; }
.badge-return { background:var(--amber); color:#4E2C0B; border-radius:9px; padding:1px 7px; font-size:10px; line-height:16px; display:inline-block; }
.badge-lost { background:#7A4A34; color:#fff; border-radius:9px; padding:1px 7px; font-size:10px; line-height:16px; display:inline-block; }
.badge-warn { background:var(--sand); color:#6B4423; border-radius:9px; padding:1px 7px; font-size:10px; line-height:16px; display:inline-block; }
/* 客戶 chips */
.chips { display:flex; gap:5px; flex-wrap:wrap; align-items:center; }
.chip { background:var(--sand); color:#6B4423; border:1px solid var(--line); border-radius:12px;
        padding:1px 8px; font-size:12px; line-height:19px; }
.chip i { cursor:pointer; margin-left:4px; color:#8C3A28; }
/* 跳窗 */
.m-mask { position:fixed; inset:0; background:rgba(74,53,36,.45); z-index:10300; display:none; }
.m-win  { background:#fff; border-radius:8px; width:760px; max-width:95vw; margin:4vh auto;
          box-shadow:0 8px 30px rgba(0,0,0,.3); display:flex; flex-direction:column; max-height:92vh; }
.m-head { padding:10px 14px; border-bottom:1px solid var(--line); font-weight:700; color:var(--ink);
          display:flex; align-items:center; }
.m-head .x { margin-left:auto; cursor:pointer; color:var(--muted); }
.m-body { padding:14px; overflow:auto; }
.m-foot { padding:10px 14px; border-top:1px solid var(--line); text-align:right; }
.help-doc h4 { color:var(--amber-d); font-size:15px; margin:14px 0 6px; }
.help-doc li { margin-bottom:4px; line-height:1.7; }
.rm-in { width:100%; border:1px solid var(--line); border-radius:4px; padding:2px 6px; font-size:12px; }
.err-txt { color:var(--coral); font-size:12px; margin-top:6px; white-space:pre-line; }
/* 自動分析卡片 */
.ins-list { display:flex; flex-direction:column; gap:6px; }
.ins { display:flex; gap:10px; align-items:flex-start; border:1px solid var(--line); border-left-width:4px;
       border-radius:6px; padding:7px 10px; background:#fffdfa; }
.ins .ic { font-size:15px; line-height:20px; width:18px; text-align:center; flex:0 0 18px; }
.ins .bd { flex:1 1 auto; min-width:0; }
.ins .tt { font-weight:700; color:var(--ink); font-size:13px; }
.ins .dt { font-size:12px; color:#6B4423; line-height:1.7; }
.ins .mt { flex:0 0 auto; font-weight:700; font-size:13px; white-space:nowrap; }
.ins-bad  { border-left-color:var(--coral); }           .ins-bad  .ic,.ins-bad  .mt { color:var(--coral); }
.ins-warn { border-left-color:var(--amber); }           .ins-warn .ic,.ins-warn .mt { color:var(--amber-d); }
.ins-good { border-left-color:#4F8A4F; }                .ins-good .ic,.ins-good .mt { color:#2E7D32; }
.ins-info { border-left-color:#B9A78C; }                .ins-info .ic,.ins-info .mt { color:var(--muted); }
/* 自動分析：可展開的客戶名單（如「流失客戶」）*/
.ins-toggle { margin-left:8px; font-size:11px; font-weight:600; color:var(--amber-d); cursor:pointer; white-space:nowrap; }
.ins-toggle:hover { color:var(--coral); }
.ins-cli-wrap { margin-top:6px; padding-top:6px; border-top:1px dashed var(--line); }
.ins-cli-grid { display:grid; gap:3px 14px; }
.ins-cli-head { margin-bottom:3px; }
.ins-cli-hcell { display:flex; justify-content:space-between; gap:8px; font-size:10px; font-weight:700; color:var(--muted);
                 text-transform:uppercase; letter-spacing:.3px; padding-bottom:3px; border-bottom:1px solid var(--line); }
.ins-cli-cell { display:flex; justify-content:space-between; gap:8px; font-size:12px; padding:2px 0; }
.ins-cli-name { color:#6B4423; overflow:hidden; text-overflow:ellipsis; white-space:nowrap; }
.ins-cli-name a { color:#8a5a2b; border-bottom:1px dotted #8a5a2b; cursor:pointer; }
.ins-cli-name a:hover { color:var(--coral); border-bottom-color:var(--coral); }
.ins-cli-amt { color:var(--muted); font-variant-numeric:tabular-nums; white-space:nowrap; }
/* 可點的料號（開圖面檢視） */
.pno-link { color:#8a5a2b; border-bottom:1px dotted #8a5a2b; cursor:pointer; }
.pno-link:hover { color:var(--coral); border-bottom-color:var(--coral); }
.nav-jump { display:flex; gap:6px; flex-wrap:wrap; margin-bottom:10px; }
.nav-jump a { font-size:12px; border:1px solid var(--line); background:#fff; color:#6B4423;
              padding:3px 10px; border-radius:12px; text-decoration:none; }
.nav-jump a:hover { background:var(--sand); }
/* 右側懸浮工具列：回訂單追蹤／回頂端／快速導覽（使用者要求，注意透明度避免遮蔽圖表） */
.float-tools { position:fixed; right:18px; bottom:18px; z-index:9000;
               display:flex; flex-direction:column-reverse; align-items:flex-end; gap:10px; }
@media print { .float-tools { display:none !important; } }
.float-btn { width:52px; height:52px; border-radius:50%; border:none; cursor:pointer; text-decoration:none;
             display:flex; flex-direction:column; align-items:center; justify-content:center;
             color:#fff; font-size:10px; font-weight:600; line-height:1.1;
             background:linear-gradient(135deg,#8a5a2b,#F0A24B); box-shadow:0 4px 14px rgba(0,0,0,.22);
             opacity:.55; transition:opacity .15s,filter .15s; }
.float-btn i { font-size:17px; margin-bottom:1px; }
.float-btn:hover, .float-btn:focus { color:#fff; text-decoration:none; opacity:1; filter:brightness(1.08); }
.float-btn.totop { width:44px; height:44px; display:none; }
.float-btn.totop i { font-size:16px; margin-bottom:0; }
/* 快速導覽：縮成圖示，滑鼠移過才展開清單 */
.qnav { position:relative; }
.qnav-fab { width:44px; height:44px; border-radius:50%; border:1px solid var(--line); cursor:pointer;
            background:#fff; color:#8a5a2b; box-shadow:0 4px 12px rgba(0,0,0,.18); font-size:16px;
            opacity:.55; transition:opacity .15s; display:flex; align-items:center; justify-content:center; }
.qnav:hover .qnav-fab, .qnav-fab:focus { opacity:1; }
.qnav-panel { position:absolute; right:52px; bottom:0; min-width:150px;
              background:rgba(255,253,250,.97); border:1px solid var(--line); border-radius:10px;
              box-shadow:0 6px 18px rgba(0,0,0,.2); padding:8px; display:flex; flex-direction:column; gap:3px;
              opacity:0; pointer-events:none; transform:translateX(6px); transition:opacity .15s,transform .15s; }
.qnav:hover .qnav-panel, .qnav-panel:hover { opacity:1; pointer-events:auto; transform:translateX(0); }
.qnav-panel a { font-size:12px; color:#6B4423; padding:5px 10px; border-radius:6px; text-decoration:none; white-space:nowrap; }
.qnav-panel a:hover { background:var(--sand); }
</style>
</head>
<!-- 側欄載入時維持收合（全站慣例 nav-sm） -->
<body class="nav-sm">
<div class="container body"><div class="main_container">
<?php include '../partPage/sideAndTopBarMenu.html'; ?>
<div class="right_col" role="main">

  <div class="page-title">
    <h3><i class="fa fa-bar-chart" style="color:var(--amber-d);"></i> 訂單分析
      <span class="role-tag">目前身分：<?= oaEsc($roleLabel) ?></span>
      <a href="NewOrder_Track.php" class="btn btn-xs btn-warm-o" style="font-weight:600;">
        <i class="fa fa-arrow-left"></i> 回訂單追蹤</a>
      <button id="btnPageHelp" class="page-help-btn" style="margin-left:auto;"><i class="fa fa-question-circle"></i> 使用說明</button>
    </h3>
  </div>

<?php if (!$canView): ?>
  <div class="warm-panel" style="color:var(--coral);">
    您沒有訂單分析的檢視權限。請洽管理員於「訂單追蹤 → 角色設定」勾選
    <code>ot_analysis</code>（訂單分析）或 <code>ot_analysis_setting</code>（訂單分析設定）。
  </div>
<?php else: ?>

  <!-- ── 條件列 ─────────────────────────────────────────── -->
  <div class="warm-panel">
    <div class="oa-bar">
      <label>年度</label>
      <select id="fYear" class="form-control input-sm" style="width:92px;">
        <?php foreach ($years as $y): ?>
          <option value="<?= (int)$y ?>" <?= $y === $defYear ? 'selected' : '' ?>><?= (int)$y ?></option>
        <?php endforeach; ?>
      </select>
      <label>期間</label>
      <select id="fGran" class="form-control input-sm" style="width:82px;">
        <?php foreach (oa_grans() as $k => $v): ?>
          <option value="<?= oaEsc($k) ?>" <?= $k === 'quarter' ? 'selected' : '' ?>><?= oaEsc($v) ?></option>
        <?php endforeach; ?>
      </select>
      <select id="fIdx" class="form-control input-sm" style="width:118px;"></select>
      <label>日期基準</label>
      <select id="fBasis" class="form-control input-sm" style="width:130px;">
        <?php foreach (oa_date_bases() as $k => $v): ?>
          <option value="<?= oaEsc($k) ?>"><?= oaEsc($v) ?></option>
        <?php endforeach; ?>
      </select>
      <label>比較基準</label>
      <select id="fCmp" class="form-control input-sm" style="width:110px;">
        <?php foreach (oa_compares() as $k => $v): ?>
          <option value="<?= oaEsc($k) ?>"><?= oaEsc($v) ?></option>
        <?php endforeach; ?>
      </select>
      <label>排序口徑</label>
      <select id="fRank" class="form-control input-sm" style="width:118px;">
        <option value="">自動</option>
        <option value="amount">金額</option>
        <option value="qty">數量</option>
        <option value="orders">筆數</option>
      </select>
      <label style="font-weight:normal;"><input type="checkbox" id="cbAlign" checked data-eg-skip>
        進行中的期間與基期對齊天數</label>
      <label style="font-weight:normal;"><input type="checkbox" id="cbPaused" data-eg-skip>
        含暫停/取消的訂單</label>
      <span class="sec-tools">
        <button class="btn btn-sm btn-warm" id="btnReload"><i class="fa fa-refresh"></i> 重新計算</button>
        <button class="btn btn-sm btn-warm-o" id="btnPrint"><i class="fa fa-print"></i> 列印報告</button>
        <button class="btn btn-sm btn-warm-o" id="btnCsv"><i class="fa fa-file-excel-o"></i> CSV</button>
        <?php if ($canSet): ?>
        <button class="btn btn-sm btn-warm-o" id="btnSetting"><i class="fa fa-cog"></i> 設定</button>
        <?php endif; ?>
      </span>
    </div>
    <div class="oa-bar" style="margin-top:8px;">
      <label>客戶篩選</label>
      <button class="btn btn-xs btn-warm-o" id="btnCliPick"><i class="fa fa-users"></i> 選擇客戶（可多選比較）</button>
      <button class="btn btn-xs btn-default" id="btnCliClear" style="display:none;">清除</button>
      <span class="chips" id="cliChips"><span style="font-size:12px;color:var(--muted);">未篩選＝全部客戶</span></span>
    </div>
  </div>

  <div class="nav-jump">
    <a href="#secInsight">自動分析</a><a href="#secTrend">訂單趨勢</a><a href="#secNew">新訂單（新料號）</a><a href="#secProc">全製／單製</a>
    <a href="#secBand">數量區間</a><a href="#secClient">客戶比較</a><a href="#secRank">客戶增減排名</a>
    <a href="#secMa">訂單量監控</a><a href="#secPart">受訂料號排名</a>
  </div>

  <div id="noteBar"></div>
  <div id="kpiAlertBar"></div>

  <div id="kpiRow" class="kpi-row"></div>

  <!-- ── 自動分析 ─────────────────────────────────────── -->
  <div class="sec" id="secInsight">
    <h4><i class="fa fa-lightbulb-o" style="color:var(--coral);"></i> 自動分析
      <span class="hint">系統直接把「要自己盯著圖表看才發現得了」的事寫成結論，每一條都附數字</span>
    </h4>
    <div id="insightList" class="ins-list"></div>
  </div>

  <!-- ── 趨勢 ─────────────────────────────────────────── -->
  <div class="sec" id="secTrend">
    <h4><i class="fa fa-line-chart" style="color:var(--amber-d);"></i> 訂單趨勢
      <span class="hint" id="trendHint"></span>
      <span class="sec-tools">
        <label style="margin:0;font-size:12px;">柱狀顯示</label>
        <select id="trendMetric" class="form-control input-sm" style="width:100px;">
          <option value="amount">訂單金額</option>
          <option value="qty">訂單數量</option>
        </select>
        <label style="margin:0;font-weight:normal;font-size:12px;">
          <input type="checkbox" id="cbPrevYear" checked data-eg-skip> 疊上去年同期</label>
      </span>
    </h4>
    <div id="chTrend" class="chart-box tall"></div>
  </div>

  <!-- ── 新訂單 ───────────────────────────────────────── -->
  <div class="sec" id="secNew">
    <h4><i class="fa fa-star-o" style="color:var(--coral);"></i> 新訂單（料號第一次出現）
      <span class="hint" id="newHint"></span>
      <span class="sec-tools">
        <input type="text" id="newKw" class="form-control input-sm" style="width:170px;"
               data-eg-hint="輸入料號或客戶篩選下表">
      </span>
    </h4>
    <div class="two-col">
      <div><div id="chNew" class="chart-box"></div></div>
      <div><div id="chNewPie" class="chart-box"></div></div>
    </div>
    <div class="tbl-wrap" style="margin-top:10px;">
      <table class="oa-t" id="tblNew">
        <colgroup><col style="width:22%"><col style="width:16%"><col style="width:13%"><col style="width:10%">
                  <col style="width:10%"><col style="width:13%"><col style="width:16%"></colgroup>
        <thead><tr><th>料號</th><th>客戶</th><th>第一次出現</th><th>來源</th>
                   <th>本期筆數</th><th>本期數量</th><th>本期金額</th></tr></thead>
        <tbody></tbody>
      </table>
    </div>
  </div>

  <!-- ── 全製／單製 ───────────────────────────────────── -->
  <div class="sec" id="secProc">
    <h4><i class="fa fa-cogs" style="color:var(--amber-d);"></i> 全製／單製分析
      <span class="hint">依「製程」欄的文字用關鍵字規則判定<?= $canSet ? '（可在「設定」調整規則）' : '' ?></span>
    </h4>
    <div class="two-col">
      <div><div id="chProcPie" class="chart-box"></div></div>
      <div><div id="chProcStack" class="chart-box"></div></div>
    </div>
    <div style="margin-top:10px;">
      <table class="oa-t" id="tblProc">
        <colgroup><col style="width:24%"><col style="width:14%"><col style="width:12%"><col style="width:12%"><col style="width:38%"></colgroup>
        <thead><tr><th>規則</th><th>關鍵字</th><th>判定</th><th>本期命中筆數</th><th>本期出現過的製程寫法（抽樣）</th></tr></thead>
        <tbody></tbody>
      </table>
    </div>
  </div>

  <!-- ── 數量區間 ─────────────────────────────────────── -->
  <div class="sec" id="secBand">
    <h4><i class="fa fa-sliders" style="color:var(--amber-d);"></i> 數量區間分析
      <span class="hint"><?= $canSet ? '區間可在「設定」調整' : '區間由管理員設定' ?></span>
    </h4>
    <div id="chBand" class="chart-box"></div>
    <table class="oa-t" id="tblBand" style="margin-top:10px;">
      <colgroup><col style="width:18%"><col style="width:13%"><col style="width:11%"><col style="width:16%">
                <col style="width:11%"><col style="width:20%"><col style="width:11%"></colgroup>
      <thead><tr><th>數量區間</th><th>筆數</th><th>佔筆數</th><th>總數量</th><th>佔數量</th><th>金額</th><th>佔金額</th></tr></thead>
      <tbody></tbody>
    </table>
  </div>

  <!-- ── 客戶比較 ─────────────────────────────────────── -->
  <div class="sec" id="secClient">
    <h4><i class="fa fa-users" style="color:var(--amber-d);"></i> 客戶比較表
      <span class="hint" id="cliHint"></span>
      <span class="sec-tools">
        <label style="margin:0;font-size:12px;">圖表顯示</label>
        <select id="cliMetric" class="form-control input-sm" style="width:100px;">
          <option value="amount">金額</option><option value="qty">數量</option><option value="orders">筆數</option>
        </select>
      </span>
    </h4>
    <div id="chClient" class="chart-box tall"></div>
    <div class="tbl-wrap" style="margin-top:10px;">
      <table class="oa-t" id="tblClient">
        <colgroup><col style="width:15%"><col style="width:8%"><col style="width:11%"><col style="width:13%">
                  <col style="width:9%"><col style="width:9%"><col style="width:9%"><col style="width:9%"><col style="width:17%"></colgroup>
        <thead><tr><th>客戶</th><th>筆數</th><th>數量</th><th>金額</th><th>料號數</th><th>新料號</th>
                   <th>全製</th><th>單製</th><th>較<span class="cmpLab">基期</span>增減</th></tr></thead>
        <tbody></tbody>
      </table>
    </div>
  </div>

  <!-- ── 客戶增減排名 ─────────────────────────────────── -->
  <div class="sec" id="secRank">
    <h4><i class="fa fa-exchange" style="color:var(--amber-d);"></i> 期間內客戶增減排名
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

  <!-- ── 訂單量監控（移動平均）───────────────────────── -->
  <div class="sec" id="secMa">
    <h4><i class="fa fa-heartbeat" style="color:var(--amber-d);"></i> 訂單量監控（金額移動平均）
      <span class="hint" id="maHint"></span>
      <?php if ($canSet): ?>
      <span class="sec-tools"><button class="btn btn-xs btn-warm-o" id="btnMaSetting"><i class="fa fa-cog"></i> 監控設定</button></span>
      <?php endif; ?>
    </h4>
    <div id="maNote" class="oa-note" style="margin-bottom:10px;"></div>
    <div id="chMa" class="chart-box"></div>
    <table class="oa-t" id="tblMa" style="margin-top:10px;">
      <colgroup><col style="width:12%"><col style="width:16%"><col style="width:12%"><col style="width:18%"><col style="width:16%"><col style="width:26%"></colgroup>
      <thead><tr><th>月份</th><th>當月訂單金額</th><th>有單價佔比</th><th>前 N 月移動平均</th><th>安全水平</th><th>判定</th></tr></thead>
      <tbody></tbody>
    </table>
  </div>

  <!-- ── 料號排名 ─────────────────────────────────────── -->
  <div class="sec" id="secPart">
    <h4><i class="fa fa-cube" style="color:var(--amber-d);"></i> 受訂料號排名
      <span class="hint" id="partHint"></span>
    </h4>
    <div id="chParts" class="chart-box tall"></div>
    <div class="tbl-wrap" style="margin-top:10px;">
      <table class="oa-t" id="tblParts">
        <colgroup><col style="width:5%"><col style="width:21%"><col style="width:14%"><col style="width:10%">
                  <col style="width:12%"><col style="width:14%"><col style="width:12%"><col style="width:12%"></colgroup>
        <thead><tr><th>#</th><th>料號</th><th>客戶</th><th>筆數</th><th>數量</th><th>金額</th>
                   <th>較基期增減</th><th>新料號</th></tr></thead><tbody></tbody>
      </table>
    </div>
  </div>

<?php endif; ?>
</div><!-- /right_col -->
</div></div>

<!-- 右側懸浮工具列：回頂端／快速導覽（使用者要求；列印時隱藏） -->
<div class="float-tools">
  <button type="button" class="float-btn totop" id="btnToTop" title="回頂端"
          onclick="window.scrollTo({top:0,behavior:'smooth'});"><i class="fa fa-arrow-up"></i>頂端</button>
  <div class="qnav">
    <button type="button" class="qnav-fab" title="快速導覽（各區塊）"><i class="fa fa-compass"></i></button>
    <div class="qnav-panel">
      <a href="#secInsight">自動分析</a>
      <a href="#secTrend">訂單趨勢</a>
      <a href="#secNew">新訂單（新料號）</a>
      <a href="#secProc">全製／單製</a>
      <a href="#secBand">數量區間</a>
      <a href="#secClient">客戶比較</a>
      <a href="#secRank">客戶增減排名</a>
      <a href="#secMa">訂單量監控</a>
      <a href="#secPart">受訂料號排名</a>
    </div>
  </div>
</div>

<!-- ── 使用說明（鐵律7）──────────────────────────────── -->
<div class="m-mask" id="helpUseMask">
  <div class="m-win" style="width:880px;">
    <div class="m-head">訂單分析 — 使用說明 <span class="x" data-close="helpUseMask">✕</span></div>
    <div class="m-body help-doc">
      <h4>功能說明（這一頁在回答什麼）</h4>
      <ul>
        <li><b>新訂單有多少</b>：這一期接到的訂單裡，有幾支料號是「系統裡第一次出現」的（沒有更早的出貨／訂單／製令／退貨）。</li>
        <li><b>訂單金額與筆數的趨勢</b>：可切月／季／半年／整年，並疊上去年同期對照。</li>
        <li><b>全製／單製</b>各佔多少筆與多少金額。</li>
        <li><b>各數量區間</b>佔多少筆（區間由管理員設定）。</li>
        <li><b>客戶比較</b>：挑幾家客戶並排比較，逐期看趨勢。</li>
        <li><b>客戶增減排名／受訂料號排名</b>：跟去年同期或上一期比，誰成長、誰衰退。</li>
      </ul>

      <h4>操作步驟</h4>
      <ol>
        <li>選「年度 → 期間（月／季／半年／整年）→ 要看哪一期」。</li>
        <li>要跟去年同期或上一期比，改「比較基準」。</li>
        <li>要只看某幾家客戶，按「選擇客戶」勾起來——<b>整份報表都會跟著只算那幾家</b>，同時下方「客戶比較表」會把勾選的客戶並排列出。</li>
        <li>按「重新計算」。要把數字帶去 Excel 就按「CSV」（匯出的是目前畫面上全部區塊的資料，不是只有一張表）。</li>
      </ol>

      <h4>重要行為／常見疑問</h4>
      <ul>
        <li><b>金額只算得出「有填單價」的訂單。</b>實測 2024 年 2,922 張訂單只有 9 張有單價、2025 年 3,628 張只有 11 張，
            2026 年才開始正常填。所以<b>舊年度的金額是一排 0，那是資料沒填不是沒接單</b>；
            要看長期趨勢請改看「訂單數量」。畫面上會即時印出本期與基期各有幾成訂單有單價。</li>
        <li><b>基期幾乎沒有單價時，排序會自動改用「數量」</b>並在說明列寫明原因，否則每一家客戶都會變成「無限成長」。</li>
        <li><b>還沒過完的期間不可以直接跟完整的期間比。</b>預設會把基期也只算到同樣的天數（可在上方取消「對齊天數」）。</li>
        <li><b>「新料號」＝在這套系統現有的資料裡第一次出現</b>，不等於公司從來沒做過。
            四個來源各自最早的資料日期（資料視界）會印在說明列上，在那之前的歷史系統裡沒有。</li>
        <li><b>訂單狀態</b>：暫停／取消的訂單預設不列入（那不是實際接到的單），已結案的一律列入（那是正常做完的單）。
            要一起看請勾「含暫停/取消的訂單」。</li>
        <li><b>全製／單製是用「製程」欄的文字判定的</b>——那一欄是手打的自由文字，系統裡沒有任何欄位記著這件事。
            規則與每條規則命中幾筆都列在「全製／單製分析」區塊，判得不對請到「設定」調整關鍵字。</li>
        <li><b>客戶會自動歸戶</b>：ERP 寫「高鋒工業」、主檔是「高鋒」會算成同一家（含別名）。
            對不到客戶主檔的會標「未建主檔」，請到會計的對帳作業建別名。</li>
        <li><b>「新客戶」與「回流客戶」是兩件不同的事</b>：「新客戶」＝這家客戶在系統整段歷史裡（出貨／訂單／退貨）
            第一次出現就落在本期；「回流客戶」＝以前就下過單，只是比較的那一期剛好沒下、這期又回來——
            <b>回流客戶不是新開發的客源</b>，不要混為一談。</li>
      </ul>

      <h4>設定入口</h4>
      <ul>
        <li>右上角「設定」（需 <code>ot_analysis_setting</code>）：<b>數量區間</b>（可增減列、改名稱與上下限）與
            <b>全製／單製關鍵字規則</b>（關鍵字用逗號分隔＝全部都要含、用「|」分隔＝任一即可）。</li>
        <li>權限指派：訂單追蹤頁的「角色設定」。</li>
      </ul>

      <h4>權限角色</h4>
      <ul>
        <li><code>ot_analysis</code>：檢視本頁。</li>
        <li><code>ot_analysis_setting</code>：修改數量區間與全製／單製關鍵字規則。</li>
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
      <div style="font-size:11px;color:var(--muted);margin-bottom:6px;">
        只列出「所選年度真的有訂單」的客戶；括號內是該年度的訂單筆數。
      </div>
      <div class="tbl-wrap" style="max-height:44vh;">
        <table class="oa-t" id="tblCli">
          <colgroup><col style="width:8%"><col style="width:38%"><col style="width:18%"><col style="width:18%"><col style="width:18%"></colgroup>
          <thead><tr><th></th><th>客戶</th><th>客戶編號</th><th>該年度筆數</th><th>該年度數量</th></tr></thead>
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

<?php if ($canView && $canSet): ?>
<!-- ── 設定 ─────────────────────────────────────────── -->
<div class="m-mask" id="setMask">
  <div class="m-win" style="width:900px;">
    <div class="m-head">訂單分析設定 <span class="x" data-close="setMask">✕</span></div>
    <div class="m-body" style="max-height:72vh;">
      <h4 style="font-size:15px;color:var(--amber-d);margin:0 0 6px;">數量區間</h4>
      <div style="font-size:12px;color:var(--muted);margin-bottom:6px;">
        報表會依訂單數量把每一張單歸到一個區間。<b>上限留空＝以上無限</b>（只能是最後一列）。
        區間<b>不可以重疊</b>，重疊的話同一張單會被算進兩個區間、佔比加起來超過 100%。
        在最後一列按 <kbd>↓</kbd> 會自動加一列。
      </div>
      <table class="oa-t" id="tblSetBand">
        <colgroup><col style="width:40%"><col style="width:22%"><col style="width:22%"><col style="width:16%"></colgroup>
        <thead><tr><th>名稱</th><th>數量下限</th><th>數量上限（留空＝以上）</th><th>操作</th></tr></thead>
        <tbody data-eg-row-add="setBandAdd" data-eg-row-del="setBandDel"></tbody>
      </table>
      <div style="margin-top:6px;">
        <button class="btn btn-xs btn-warm-o" onclick="setBandAdd()"><i class="fa fa-plus"></i> 加一列</button>
        <button class="btn btn-xs btn-default" onclick="setBandReset()">回復預設</button>
      </div>

      <h4 style="font-size:15px;color:var(--amber-d);margin:16px 0 6px;">全製／單製關鍵字規則</h4>
      <div style="font-size:12px;color:var(--muted);margin-bottom:6px;">
        訂單的「製程」欄是<b>手打的自由文字</b>（代料成品／代料完成／全製 (齒研)…），
        系統裡沒有欄位記著這張單是全製還是單製，所以用關鍵字判定。<b>由上往下比，先命中先算</b>。<br>
        關鍵字語法：<code>逗號</code>分隔＝<b>全部都要含</b>；同一個關鍵字裡用 <code>|</code> 分隔＝<b>任一即可</b>
        （例：<code>代料|備料</code>）。判得對不對請看主畫面「全製／單製分析」裡每條規則命中幾筆。
      </div>
      <table class="oa-t" id="tblSetRule">
        <colgroup><col style="width:26%"><col style="width:40%"><col style="width:18%"><col style="width:16%"></colgroup>
        <thead><tr><th>規則名稱</th><th>關鍵字</th><th>判定為</th><th>操作</th></tr></thead>
        <tbody data-eg-row-add="setRuleAdd" data-eg-row-del="setRuleDel"></tbody>
      </table>
      <div style="margin-top:6px;">
        <button class="btn btn-xs btn-warm-o" onclick="setRuleAdd()"><i class="fa fa-plus"></i> 加一列</button>
        <button class="btn btn-xs btn-default" onclick="setRuleReset()">回復預設</button>
      </div>
      <div class="oa-bar" style="margin-top:10px;">
        <label>都沒有命中規則時歸類為</label>
        <select id="setFallback" class="form-control input-sm" style="width:150px;">
          <option value="single">單製</option>
          <option value="full">全製</option>
          <option value="unknown">無法判定（不歸類）</option>
        </select>
      </div>

      <h4 style="font-size:15px;color:var(--amber-d);margin:18px 0 6px;">訂單 KPI 未達標提醒</h4>
      <div style="font-size:12px;color:var(--muted);margin-bottom:6px;">
        讀「KPI 關鍵績效指標」的<b id="setKpiName">月份受訂目標達成金額</b>，
        看最近幾個<b>已經結束的月份</b>有沒有未達標（本月還沒過完，拿半個月的數字判未達標一定是錯的，所以不看本月）。
        有未達標就在頁面最上方顯示紅色提醒，並算出<b>本月離月目標還差多少、剩幾天</b>。
        <span id="setKpiWarn" style="color:var(--coral);"></span>
      </div>
      <div class="oa-bar">
        <label>看最近</label>
        <input type="number" id="setKpiMonths" class="rm-in" style="width:70px;" min="1" max="12">
        <label>個已結束的月份</label>
      </div>

      <h4 style="font-size:15px;color:var(--amber-d);margin:18px 0 6px;">訂單量監控（金額移動平均，自動通知）</h4>
      <div style="font-size:12px;color:var(--muted);margin-bottom:6px;">
        每月自動評估一次：取「前 N 個月訂單金額的移動平均」，<b>連續 M 個月低於安全水平</b>就自動通知指定人員
        （站內通知＋Web Push＋Telegram，內容附逐月數據與判定，並附開啟本頁的連結）。<br>
        <b style="color:var(--coral);">資料品質保護</b>：訂單金額只算得出「有填單價」的訂單，
        當月有填單價的訂單佔比低於下面設定的門檻時，該月視為<b>資料不足、不納入評估</b>——
        本站 2026-03 以前幾乎沒有人填單價，不擋的話每個月都會發出假警報。
      </div>
      <div class="oa-bar">
        <label style="font-weight:normal;"><input type="checkbox" id="setMaEnabled" data-eg-skip> 啟用自動通知</label>
        <label>移動平均取前</label>
        <input type="number" id="setMaMonths" class="rm-in" style="width:64px;" min="2" max="12">
        <label>個月，連續</label>
        <input type="number" id="setMaCons" class="rm-in" style="width:56px;" min="1" max="6">
        <label>個月低於安全水平就通知</label>
      </div>
      <div class="oa-bar" style="margin-top:6px;">
        <label>安全水平</label>
        <select id="setMaMode" class="form-control input-sm" style="width:210px;">
          <option value="kpi">該年度訂單 KPI 的月受訂目標金額</option>
          <option value="manual">自訂金額</option>
        </select>
        <input type="number" id="setMaValue" class="rm-in" style="width:130px;" min="0" data-eg-hint="每月安全水平金額（元）">
        <label>當月有單價佔比低於</label>
        <input type="number" id="setMaCov" class="rm-in" style="width:64px;" min="0" max="100">
        <label>% 視為資料不足</label>
      </div>
      <div class="oa-bar" style="margin-top:6px;align-items:flex-start;">
        <label style="padding-top:4px;">收通知人員</label>
        <div style="flex:1 1 auto;min-width:240px;">
          <div class="oa-bar" style="margin-bottom:4px;">
            <select id="setMaUserPick" class="form-control input-sm" style="width:260px;"
                    data-eg-filter="輸入姓名或部門篩選…"></select>
            <button type="button" class="btn btn-xs btn-warm-o" id="setMaUserAdd">＋ 加入</button>
          </div>
          <div class="chips" id="setMaUsers"></div>
        </div>
      </div>
      <div class="oa-bar" style="margin-top:8px;">
        <button type="button" class="btn btn-xs btn-warm-o" id="btnMaPreview"><i class="fa fa-flask"></i> 用目前設定試算（只計算，不會發通知）</button>
        <span id="maPreviewOut" style="font-size:12px;color:#6B4423;"></span>
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

<!-- 客戶唯讀檢視：不開新視窗（window.open 在目前瀏覽器下其實是一個可移動/有網址列的
     正常視窗，一點都不像原本編輯客戶那種固定對話框），改用同一套 .m-mask/.m-win 疊一層，
     裡面塞 master_data_management.php 的極簡唯讀片段（?view_customer=&embed=1）。
     iframe 高度由裡面用 postMessage 回報，長多高就給多高，不會出現內部捲軸。 -->
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
<!-- exporting：列印報告要把圖表轉成 SVG 放進列印版（chart.getSVG() 由這支提供） -->
<script src="../../code/modules/exporting.js"></script>
<!-- 列印紀錄（ai-rules/23：會列印的頁面一律留下列印時間·列印人·登入電腦·文件名稱） -->
<script src="../../resource/js/eg_print_log.js?v=<?= @filemtime(__DIR__.'/../../resource/js/eg_print_log.js') ?>"></script>
<!-- 日期顯示一律走共用檔（ai-rules/20：YYYY.MM.DD） -->
<script src="../../resource/js/eg_date_fmt.js?v=<?= @filemtime(__DIR__.'/../../resource/js/eg_date_fmt.js') ?>"></script>
<!-- 輸入欄位共用規則（雙擊清空／聚焦全選／Enter 跳欄／可增列表格／下拉打字篩選） -->
<script src="../../resource/js/eg_input_rules.js?v=<?= @filemtime(__DIR__.'/../../resource/js/eg_input_rules.js') ?>"></script>
<script>
/* 側欄：CSS 先藏、這裡再顯示（鐵律6，兩者必須成對） */
$(document).ready(function(){ $('#sidebar-menu').css('visibility','visible'); });

/* 回頂端：捲到底下時才出現，按下捲回最上面（露出頁面標題「訂單分析」） */
$(window).on('scroll', function(){ $('#btnToTop').toggle($(window).scrollTop() > 200); });

var OA_API  = '../../src/store/OrderAnalysis_API.php';
var OA_CSRF = '<?= oaEsc($CSRF) ?>';
var CAN_SET = <?= $canSet ? 'true' : 'false' ?>;
var COMPANY = <?= json_encode($COMPANY, JSON_UNESCAPED_UNICODE) ?>;

/* 點料號 → 開圖面檢視（與訂單追蹤頁同一種開法：帶料號主檔 PK 的獨立視窗）。
   一定要帶 pk＝d_setting.d_id：同名料號常有好幾筆主檔分屬不同客戶，只帶料號文字會開到別家的圖。 */
function oaOpenDrawing(pid, pno){
  pid = parseInt(pid, 10) || 0;
  if(!pid){ alert('這一筆沒有綁定料號主檔，無法開啟圖面檢視。'); return; }
  var w = screen.availWidth, h = screen.availHeight;
  var pw = Math.min(1400, Math.round(w * 0.85)), ph = Math.min(900, Math.round(h * 0.88));
  window.open('../pm/bom_viewer.php?pk=' + encodeURIComponent(pid), 'drawing_' + pid,
    'width=' + pw + ',height=' + ph + ',left=' + Math.round((w - pw) / 2) + ',top=' + Math.round((h - ph) / 2)
    + ',resizable=yes,scrollbars=yes,menubar=no,toolbar=no,location=no,status=no');
}
/* 點客戶名稱 → 開客戶主檔唯讀檢視。不用 window.open：新版瀏覽器下 window.open 開出來的
   其實是一個可移動、有網址列的正常視窗，一點都不像原本編輯客戶那種固定對話框——改成用本頁
   既有的 .m-mask/.m-win 疊一層，裡面塞 master_data_management.php 的極簡唯讀片段
   （?view_customer=&embed=1，只有分頁與唯讀欄位，不帶側欄/頂欄/清單），
   高度由裡面用 postMessage 回報多高就給多高，不會有內部捲軸。未建主檔（cid 空）不給點。 */
function oaOpenCustomerView(cid){
  cid = String(cid||'').trim();
  if(!cid || cid==='0'){ return; }
  document.getElementById('custViewFrame').src = '../pages/master_data_management.php?view_customer=' + encodeURIComponent(cid) + '&embed=1';
  openMask('custViewMask');
}
function closeCustView(){
  closeMask('custViewMask');
  document.getElementById('custViewFrame').src = 'about:blank';
}
$('#custViewMask').on('click', function(e){ if(e.target===this) closeCustView(); });
window.addEventListener('message', function(e){
  if (!e.data || e.data.type !== 'cv-resize') return;
  var f = document.getElementById('custViewFrame');
  if (!f) return;
  var h = Math.max(200, Math.min(parseInt(e.data.height,10)||200, Math.round(window.innerHeight*0.85)));
  f.style.height = h + 'px';
});
/* 料號儲存格：有綁主檔才可點 */
function pnoCell(p){
  var t = esc(p.pno);
  if(!p.pid) return t + ' <span style="font-size:10px;color:#a08a6f;" title="沒有綁定料號主檔，無法開圖">（未綁主檔）</span>';
  return '<span class="pno-link" data-pid="' + p.pid + '" title="點一下開啟圖面檢視（圖面／報價／訂單附件）">'
       + '<i class="fa fa-picture-o" style="font-size:11px;opacity:.75;"></i> ' + t + '</span>';
}
$(document).on('click', '.pno-link', function(){ oaOpenDrawing($(this).data('pid')); });

/* ai-rules/10 暖色調色盤（同語意同色、跨頁一致；禁止隨機或 HSL 上色） */
var PAL = ['#DD5138','#F0A24B','#B06F27','#E8C07A','#8A5A2B','#D98A5F','#C9A227','#A34E2A','#EBD3A8','#7A4A34'];
var C_AMBER='#F0A24B', C_CORAL='#DD5138', C_BROWN='#8A5A2B', C_SAND='#EBD3A8', C_GREEN='#4F8A4F';

function esc(s){ return $('<div>').text(s==null?'':s).html(); }
function nf(n){ n=Number(n)||0; return n.toLocaleString('en-US'); }
function nf1(n){ n=Number(n)||0; return n.toLocaleString('en-US',{maximumFractionDigits:1}); }
function money(n){ return nf(Math.round(Number(n)||0)); }
function wan(n){ return Math.round((Number(n)||0)/10000*10)/10; }   /* 萬元，小數一位 */
function dispDate(s){ return (typeof egFmtDate==='function') ? egFmtDate(s) : (s||''); }
function pct(a,b){ b=Number(b)||0; if(!b) return '—'; return (Math.round((Number(a)||0)/b*1000)/10)+'%'; }
/* 本頁沒有專用提醒元件，補一支極簡的（不阻擋、自動消失）不影響列印流程 */
function showToast(msg, kind){
  var c = kind==='error' ? '#DD5138' : (kind==='info' ? '#8a5a2b' : '#4F8A4F');
  var $t = $('<div>').text(msg).css({
    position:'fixed', left:'50%', bottom:'26px', transform:'translateX(-50%)', zIndex:10500,
    background:c, color:'#fff', padding:'8px 18px', borderRadius:'20px', fontSize:'13px',
    boxShadow:'0 3px 10px rgba(0,0,0,.25)', opacity:0
  }).appendTo('body');
  $t.animate({opacity:1}, 150).delay(2200).animate({opacity:0}, 300, function(){ $t.remove(); });
}
function deltaHtml(cur, prev, fmt){
  fmt = fmt || nf;
  var p = Number(prev)||0, d = (Number(cur)||0) - p;
  var cls = d>0?'up':(d<0?'down':'flat');
  var sign = d>0?'+':'';
  /* 基期是 0 或小到不成比例時**不要印百分比**：2025 年幾乎沒人填單價，
     基期 11,740 對本期 3,973 萬會算出「+338368%」，那是基期沒有資料不是業績成長 */
  var rate;
  if(!p)                          rate = '（基期為 0）';
  else if(Math.abs(d/p) > 10)     rate = '（基期過小，成長率不具意義）';
  else                            rate = '（'+sign+Math.round(d/Math.abs(p)*1000)/10+'%）';
  return '<span class="'+cls+'">'+sign+fmt(d)+'</span> <span style="font-size:10px;color:#a08a6f;">'+rate+'</span>';
}
function openMask(id){ $('#'+id).show(); }
function closeMask(id){ $('#'+id).hide(); }
$(document).on('click','[data-close]', function(){ closeMask($(this).data('close')); });
$(document).on('click','.m-mask', function(e){ if(e.target===this) $(this).hide(); });
$('#btnPageHelp').on('click', function(){ openMask('helpUseMask'); });

/* ── 期別下拉（跟著粒度變）──────────────────────────── */
var GRAN_IDX = {
  year:    [['1','整年']],
  half:    [['1','上半年'],['2','下半年']],
  quarter: [['1','第 1 季'],['2','第 2 季'],['3','第 3 季'],['4','第 4 季']],
  month:   null
};
function fillIdx(keepIdx){
  var g = $('#fGran').val(), list = GRAN_IDX[g];
  if(!list){ list=[]; for(var m=1;m<=12;m++) list.push([String(m), m+' 月']); }
  var h='';
  list.forEach(function(x){ h += '<option value="'+x[0]+'">'+x[1]+'</option>'; });
  $('#fIdx').html(h).prop('disabled', g==='year');
  var want = keepIdx || defaultIdx(g);
  if($('#fIdx option[value="'+want+'"]').length) $('#fIdx').val(want);
}
/* 預設停在「最近一個已經開始的期別」；選的是今年就用今天，其他年度就選最後一期 */
function defaultIdx(g){
  var y = parseInt($('#fYear').val(),10), now = new Date();
  if(y !== now.getFullYear()){ return g==='month'?'12':(g==='quarter'?'4':(g==='half'?'2':'1')); }
  var m = now.getMonth()+1;
  if(g==='month')   return String(m);
  if(g==='quarter') return String(Math.ceil(m/3));
  if(g==='half')    return m<=6?'1':'2';
  return '1';
}
$('#fGran, #fYear').on('change', function(){ fillIdx(); });

/* ── 客戶挑選 ───────────────────────────────────────── */
var CLI_LIST = [], CLI_SEL = {}, CLI_YEAR = 0;
function loadClients(cb){
  var y = $('#fYear').val();
  if(CLI_YEAR === y && CLI_LIST.length){ if(cb) cb(); return; }
  $.get(OA_API, {action:'clients', year:y, basis:$('#fBasis').val(),
                 include_paused:$('#cbPaused').is(':checked')?1:0}, function(r){
    if(!r || !r.ok){ alert((r&&r.error)||'客戶清單載入失敗'); return; }
    CLI_LIST = r.clients || []; CLI_YEAR = y;
    if(cb) cb();
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
       + '<td>'+esc(c.cid||'—')+'</td><td class="n">'+nf(c.orders)+'</td><td class="n">'+nf(c.qty)+'</td></tr>';
  });
  $('#tblCli tbody').html(h || '<tr><td colspan="5" style="text-align:center;color:#a08a6f;">沒有符合的客戶</td></tr>');
  $('#cliCount').text('顯示 '+n+' / 共 '+CLI_LIST.length+' 家；已選 '+Object.keys(CLI_SEL).length+' 家');
}
$('#btnCliPick').on('click', function(){ loadClients(function(){ $('#cliKw').val(''); renderCliTable(); openMask('cliMask'); }); });
$('#cliKw').on('input', renderCliTable);
$(document).on('change', '.cli-cb', function(){
  var k = $(this).data('k');
  if($(this).is(':checked')) CLI_SEL[k]=1; else delete CLI_SEL[k];
  $('#cliCount').text($('#cliCount').text().replace(/已選 \d+ 家/, '已選 '+Object.keys(CLI_SEL).length+' 家'));
});
/* 只把「目前篩出來看得到的」全選／取消，才不會把畫面上看不到的客戶一起動到 */
$('#cliAll').on('click', function(){ $('.cli-cb').each(function(){ CLI_SEL[$(this).data('k')]=1; }); renderCliTable(); });
$('#cliNone').on('click', function(){ CLI_SEL={}; renderCliTable(); });
$('#cliApply').on('click', function(){ closeMask('cliMask'); renderChips(); load(); });
$('#btnCliClear').on('click', function(){ CLI_SEL={}; renderChips(); load(); });
function renderChips(){
  var keys = Object.keys(CLI_SEL);
  if(!keys.length){
    $('#cliChips').html('<span style="font-size:12px;color:var(--muted);">未篩選＝全部客戶</span>');
    $('#btnCliClear').hide(); return;
  }
  var map={}; CLI_LIST.forEach(function(c){ map[c.key]=c.name; });
  var h='';
  keys.forEach(function(k){ h += '<span class="chip">'+esc(map[k]||k)+'<i class="fa fa-times" data-k="'+esc(k)+'"></i></span>'; });
  $('#cliChips').html(h); $('#btnCliClear').show();
}
$(document).on('click','#cliChips .fa-times', function(){ delete CLI_SEL[$(this).data('k')]; renderChips(); load(); });

/* ── 主流程 ─────────────────────────────────────────── */
var DATA = null, CHARTS = {};
function chart(id, opt){
  try { if(CHARTS[id]) { CHARTS[id].destroy(); CHARTS[id]=null; } } catch(e){}
  CHARTS[id] = Highcharts.chart(id, opt);
}
/* 條列很多的橫條圖要自己把「容器」撐高。
   只在 chart.height 給值是不夠的——.chart-box 的 CSS 固定高度會把容器壓回 360px，
   實測 30 條的排名圖被壓成 360px，衰退的那半段整個看不到（而且完全不報錯）。 */
function sizeBox(id, px){ var e=document.getElementById(id); if(e) e.style.height = Math.round(px)+'px'; }
var BASE_CHART = {
  chart:{ backgroundColor:'#fff', style:{fontFamily:'"Microsoft JhengHei",sans-serif'}, spacing:[8,8,6,8] },
  title:{ text:null }, credits:{ enabled:false },
  xAxis:{ lineColor:'#E4D3BC', tickColor:'#E4D3BC', labels:{ style:{fontSize:'11px', color:'#6B4423'} } },
  legend:{ itemStyle:{fontSize:'11px', fontWeight:'400', color:'#6B4423'}, symbolRadius:3 }
};
function opt(o){ return $.extend(true, {}, BASE_CHART, o); }

function load(){
  var req = {
    action:'analyze', year:$('#fYear').val(), gran:$('#fGran').val(), idx:$('#fIdx').val(),
    basis:$('#fBasis').val(), cmp:$('#fCmp').val(), rank_metric:$('#fRank').val(),
    align:$('#cbAlign').is(':checked')?1:0, include_paused:$('#cbPaused').is(':checked')?1:0,
    top:50, clients:JSON.stringify(Object.keys(CLI_SEL))
  };
  $('#noteBar').html('<div class="oa-note"><i class="fa fa-spinner fa-spin"></i> 計算中…</div>');
  $.post(OA_API, req, function(r){
    if(!r || !r.ok){ $('#noteBar').html('<div class="oa-note oa-warn">'+esc((r&&r.error)||'載入失敗')+'</div>'); return; }
    DATA = r;
    renderNote(); renderKpiAlert(); renderKpi(); renderInsights();
    renderTrend(); renderNew(); renderProc(); renderBand();
    renderClient(); renderRank(); renderMa(); renderParts();
  }, 'json').fail(function(x){
    $('#noteBar').html('<div class="oa-note oa-warn">載入失敗（HTTP '+x.status+'）'
      + (x.status===403?'：權限不足或連線憑證失效，請重新整理頁面':'') + '</div>');
  });
}
$('#btnReload').on('click', load);
$('#fYear, #fGran, #fIdx, #fBasis, #fCmp, #fRank, #cbAlign, #cbPaused').on('change', load);

/* ── 說明列：口徑與資料事實一律寫出來（不講就會被當成程式壞掉）──── */
function renderNote(){
  var m = DATA.meta, h = [];
  h.push('<b>本期</b>：'+esc(m.period.label)+'（'+dispDate(m.period_eff.start)+'～'+dispDate(m.period_eff.end)+'）'
       + '，日期基準＝'+esc(m.basis_label)
       + '　<b>基期</b>：'+esc(m.cmp_period.label)
       + (m.align && m.elapsed_days!==null ? '（本期才過 '+m.elapsed_days+' 天，基期已同步只算到 '+dispDate(m.cmp_period_eff.end)+'）'
                                           : '（'+dispDate(m.cmp_period_eff.start)+'～'+dispDate(m.cmp_period_eff.end)+'）'));
  h.push('<b>訂單金額只算得出「有填單價」的訂單</b>：本期 '+nf(DATA.kpi.cur.px_orders)+'/'+nf(DATA.kpi.cur.orders)
       + ' 張有單價（'+m.px_cov_cur+'%），基期 '+nf(DATA.kpi.cmp.px_orders)+'/'+nf(DATA.kpi.cmp.orders)
       + ' 張（'+m.px_cov_cmp+'%）。沒填單價的訂單金額以 0 計，'
       + (m.px_cov_cmp < 60 ? '<b>基期金額幾乎是空的，請以「訂單數量／筆數」為準</b>。' : '看長期趨勢建議一併看「訂單數量」。'));
  if(m.rank_metric_auto) h.push('<b>'+esc(m.rank_metric_auto)+'</b>');
  var hz = m.horizon || {}, lab = m.source_labels || {}, hs=[];
  Object.keys(lab).forEach(function(k){ if(hz[k]) hs.push(lab[k]+' '+dispDate(hz[k])+' 起'); });
  h.push('<b>「新料號」＝在這套系統現有資料裡第一次出現</b>（比對出貨／訂單／製令／退貨四個來源），'
       + '不等於公司從來沒做過——系統裡最早的資料是：'+esc(hs.join('、'))+'，更早的歷史這裡沒有。');
  h.push('<b>訂單狀態</b>：暫停/取消（Order_status=6）'+(m.include_paused?'<b>已一併列入</b>':'不列入')
       + '，已結案（=9）一律列入。'
       + (m.warn.no_client ? '　本期有 <b>'+nf(m.warn.no_client)+'</b> 筆訂單的客戶對不到客戶主檔（已自成一組並標「未建主檔」，可到對帳作業建別名）。' : '')
       + (m.warn.no_part   ? '　有 <b>'+nf(m.warn.no_part)+'</b> 筆訂單沒有綁料號主檔，無法判定是不是新料號。' : ''));
  $('#noteBar').html('<div class="oa-note">'+h.join('<br>')+'</div>');
  $('.cmpLab').text(m.cmp_label);
}

/* ── 訂單 KPI 未達標 → 本月要衝刺（使用者要求的提醒）──────── */
function renderKpiAlert(){
  var a = DATA.kpi_alert;
  if(!a || !a.below){ $('#kpiAlertBar').html(''); return; }
  var gap = a.month_gap;
  var h = '<div class="oa-note oa-warn" style="border-left-color:var(--coral);background:#FDF2EE;">'
    + '<div style="font-size:15px;font-weight:700;color:var(--coral);margin-bottom:4px;">'
    + '<i class="fa fa-exclamation-triangle"></i> 本月要衝刺：最近 '+a.n+' 個月有 '+a.bad_count+' 個月「'+esc(a.indicator)+'」未達標</div>'
    + '未達標月份：<b>'+esc(a.bad_list.join('、'))+'</b>（判定與目標值一律取自 KPI 關鍵績效指標頁的年度設定，本頁不另設一套）。<br>';
  if(gap === null){
    h += '本年度沒有設定「每月受訂目標金額」，所以算不出本月還差多少；請到 KPI 設定頁補上月目標。';
  }else{
    h += '<b>'+a.this_year+'/'+a.this_month+'月</b> 目標 <b>'+money(a.month_target)+'</b> 元，'
       + '目前已接 <b>'+money(a.month_got)+'</b> 元（'+nf(a.px_orders)+'/'+nf(a.orders)+' 張有填單價）'
       + (gap > 0 ? ('，<b style="color:var(--coral);font-size:15px;">還差 '+money(gap)+' 元</b>，只剩 <b>'+a.days_left+'</b> 天。')
                  : '，<b style="color:#2E7D32;">本月已達標</b>。');
  }
  h += '<br><span style="color:var(--muted);">※ 本月還沒過完，所以「未達標」只看已經結束的月份；'
     + '本月進度只是提醒衝刺用，不代表本月已未達標。</span></div>';
  $('#kpiAlertBar').html(h);
}

/* ── 自動分析 ───────────────────────────────────────── */
var _insSeq = 0;
function renderInsights(){
  var list = DATA.insights || [], icons = {bad:'fa-times-circle', warn:'fa-exclamation-circle',
                                           good:'fa-check-circle', info:'fa-info-circle'};
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
       + (hasList ? ' <span class="ins-toggle" onclick="oaToggleInsList(\''+listId+'\',this)">展開名單 <i class="fa fa-caret-down"></i></span>' : '')
       + '</div><div class="dt">'+esc(x.detail)+'</div>'
       + (hasList ? oaRenderInsClientGrid(x, listId) : '')
       + '</div>'
       + (x.metric ? '<div class="mt">'+esc(x.metric)+'</div>' : '') + '</div>';
  });
  $('#insightList').html(h);
}
/* 自動分析裡「有 N 家客戶完全沒有下單」這類結論的展開名單：
   左＝客戶名稱（可點開唯讀檢視，未建主檔者不給點）、右＝上一期（比較基期）金額或數量，3～4 欄排版。
   標頭跟本體用同一組 grid-template-columns，逐欄對齊。 */
function oaRenderInsClientGrid(x, listId){
  var cols = (x.clients.length > 18) ? 4 : 3;
  var unit = x.unit === '元' ? ' 元' : ' 支';
  var amtLabel = esc(x.cmp_label||'上期') + (x.unit === '元' ? '金額' : '數量');
  var colStyle = 'grid-template-columns:repeat('+cols+',1fr);';
  var head = '';
  for (var i = 0; i < cols; i++) {
    head += '<div class="ins-cli-hcell"><span>客戶名稱</span><span>'+amtLabel+'</span></div>';
  }
  var cells = x.clients.map(function(c){
    var amt = nf(c.amount) + unit;
    var nameHtml = (c.cid && c.cid !== '0' && !c.bad)
        ? '<a onclick="oaOpenCustomerView(\''+esc(c.cid)+'\');return false;" href="#" title="開啟客戶主檔唯讀檢視">'+esc(c.name)+'</a>'
        : '<span title="未建客戶主檔，無法開啟檢視">'+esc(c.name)+'</span>';
    return '<div class="ins-cli-cell"><span class="ins-cli-name">'+nameHtml+'</span><span class="ins-cli-amt">'+amt+'</span></div>';
  }).join('');
  return '<div class="ins-cli-wrap" id="'+listId+'" style="display:none;">'
       + '<div class="ins-cli-grid ins-cli-head" style="'+colStyle+'">'+head+'</div>'
       + '<div class="ins-cli-grid" style="'+colStyle+'">'+cells+'</div>'
       + '</div>';
}
function oaToggleInsList(id, el){
  var box = document.getElementById(id);
  if(!box) return;
  var show = box.style.display === 'none';
  box.style.display = show ? 'block' : 'none';
  $(el).html(show ? '收合名單 <i class="fa fa-caret-up"></i>' : '展開名單 <i class="fa fa-caret-down"></i>');
}

/* ── 訂單量監控（移動平均）─────────────────────────── */
function renderMa(){
  var ma = DATA.ma;
  if(!ma || !ma.series){ $('#maNote').html('無法計算'); return; }
  var thrTxt = ma.mode === 'manual' ? ('自訂 '+money(ma.manual_value)+' 元／月') : '該年度訂單 KPI 的月受訂目標金額';
  var st = ma.hit ? ('<b style="color:var(--coral);">已連續 '+ma.streak+' 個月低於安全水平（達到通知條件）</b>')
                  : (ma.streak ? ('目前連續 '+ma.streak+' 個月低於安全水平（需連續 '+ma.need+' 個月才通知）')
                               : '<b style="color:#2E7D32;">目前正常</b>');
  var bad = (ma.series||[]).filter(function(s){ return s.unreliable; }).map(function(s){ return s.ym; });
  $('#maNote').html(
      '判定方式：取「前 <b>'+ma.months+'</b> 個月訂單金額的移動平均」，連續 <b>'+ma.need+'</b> 個月低於安全水平就自動通知。'
    + '　安全水平＝'+esc(thrTxt)+'。　狀態：'+st
    + '<br>自動通知：<b>'+(ma.enabled?'已啟用':'未啟用')+'</b>'
    + (ma.notify_users && ma.notify_users.length ? ('，收件 '+ma.notify_users.length+' 人') : '，尚未指定收通知人員')
    + '。評估每月一次（由系統順路觸發，不需要工作排程器）。'
    + (bad.length ? ('<br><b>'+bad.length+'</b> 個月因「有填單價的訂單不到 '+ma.min_coverage
                     +'%」視為資料不足、不納入評估（'+esc(bad.join('、'))+'）——不擋的話會發出假警報。') : ''));
  $('#maHint').text('每月自動評估，連續低於安全水平就通知指定人員');

  var cats = ma.series.map(function(s){ return s.ym; });
  chart('chMa', opt({
    xAxis:{ categories:cats },
    yAxis:{ title:{text:'金額（萬元）',style:{fontSize:'11px',color:'#a08a6f'}}, gridLineColor:'#F0E8DC',
            labels:{style:{fontSize:'10px',color:'#a08a6f'}} },
    tooltip:{ shared:true, style:{fontSize:'11px'} },
    series:[
      { name:'當月訂單金額', type:'column', color:C_SAND, borderRadius:3,
        data: ma.series.map(function(s){ return { y:wan(s.amount), color: s.unreliable ? '#E0D6C6' : C_SAND }; }) },
      { name:'前'+ma.months+'月移動平均', type:'line', color:C_AMBER, lineWidth:3, marker:{radius:4},
        data: ma.series.map(function(s){ return { y:wan(s.avg), color: s.below ? C_CORAL : C_AMBER }; }) },
      { name:'安全水平', type:'line', color:C_CORAL, dashStyle:'ShortDash', lineWidth:2, marker:{enabled:false},
        data: ma.series.map(function(s){ return s.threshold===null? null : wan(s.threshold); }) }
    ]
  }));
  var h = '';
  ma.series.forEach(function(s){
    var judge = s.unreliable ? '<span class="badge-warn">資料不足，不評估</span>'
              : (s.below ? '<span class="badge-new">低於安全水平</span>' : '<span style="color:#2E7D32;">正常</span>');
    h += '<tr><td>'+esc(s.ym)+'</td><td class="n">'+money(s.amount)+'</td><td class="n">'+nf1(s.cov)+'%</td>'
       + '<td class="n">'+money(s.avg)+'</td><td class="n">'+(s.threshold===null?'未設定':money(s.threshold))+'</td>'
       + '<td>'+judge+(s.unreliable?('（'+esc(s.unreliable_months.join('、'))+'）'):'')+'</td></tr>';
  });
  $('#tblMa tbody').html(h||'<tr><td colspan="6" style="text-align:center;color:#a08a6f;">沒有資料</td></tr>');
}

/* ── KPI ────────────────────────────────────────────── */
function renderKpi(){
  var c = DATA.kpi.cur, p = DATA.kpi.cmp, m = DATA.meta;
  function card(lab, val, sub, cls){
    return '<div class="kpi-card '+(cls||'')+'"><div class="k-lab">'+lab+'</div>'
         + '<div class="k-val">'+val+'</div><div class="k-sub">'+sub+'</div></div>';
  }
  var fullPct = c.orders ? Math.round(c.full*1000/c.orders)/10 : 0;
  var h = '';
  h += card('訂單筆數', nf(c.orders), '較'+esc(m.cmp_label)+' '+deltaHtml(c.orders,p.orders));
  h += card('訂單數量', nf(c.qty),    '較'+esc(m.cmp_label)+' '+deltaHtml(c.qty,p.qty));
  h += card('訂單金額', money(c.amount), '較'+esc(m.cmp_label)+' '+deltaHtml(c.amount,p.amount,money)
            + '<br>有單價 '+c.px_orders+'/'+c.orders+' 張');
  h += card('下單客戶數', nf(c.clients), '較'+esc(m.cmp_label)+' '+deltaHtml(c.clients,p.clients));
  h += card('受訂料號數', nf(c.parts),   '較'+esc(m.cmp_label)+' '+deltaHtml(c.parts,p.parts));
  h += card('新料號', nf(c.new_parts), '佔本期料號 '+pct(c.new_parts,c.parts)+'；較'+esc(m.cmp_label)+' '+deltaHtml(c.new_parts,p.new_parts), 'k-new');
  h += card('新料號訂單', nf(c.new_orders), '佔本期筆數 '+pct(c.new_orders,c.orders)+'；金額 '+money(c.new_amount), 'k-new');
  h += card('全製佔比', fullPct+'%', '全製 '+nf(c.full)+' 筆／單製 '+nf(c.single)+' 筆'
            + (c.none? '／未填製程 '+nf(c.none)+' 筆':'') + (c.unknown? '／無法判定 '+nf(c.unknown)+' 筆':''));
  $('#kpiRow').html(h);
}

/* ── 趨勢 ───────────────────────────────────────────── */
function renderTrend(){
  var t = DATA.trend, met = $('#trendMetric').val(), showPrev = $('#cbPrevYear').is(':checked');
  var cats = t.cur.map(function(b){ return b.label.replace(/^\d{4}\s*/,''); });
  var isAmt = (met==='amount');
  var curV  = t.cur.map(function(b){ return isAmt ? wan(b.amount) : b.qty; });
  var prevV = t.prev.map(function(b){ return isAmt ? wan(b.amount) : b.qty; });
  var curO  = t.cur.map(function(b){ return b.orders; });
  var prevO = t.prev.map(function(b){ return b.orders; });
  var unit  = isAmt ? '萬元' : '支';
  var ser = [
    { name:DATA.meta.year+' '+(isAmt?'訂單金額':'訂單數量'), type:'column', data:curV, color:C_AMBER, yAxis:0, borderRadius:3 },
    { name:DATA.meta.year+' 訂單筆數', type:'line', data:curO, color:C_CORAL, yAxis:1, lineWidth:2, marker:{radius:4, symbol:'circle'} }
  ];
  if(showPrev){
    ser.push({ name:t.prev_year+' '+(isAmt?'金額':'數量'), type:'column', data:prevV, color:C_SAND, yAxis:0, borderRadius:3 });
    ser.push({ name:t.prev_year+' 筆數', type:'line', data:prevO, color:C_BROWN, yAxis:1,
               dashStyle:'ShortDash', lineWidth:2, marker:{radius:3, symbol:'diamond'} });
  }
  chart('chTrend', opt({
    xAxis:{ categories:cats },
    yAxis:[{ title:{text:(isAmt?'金額（萬元）':'數量（支）'), style:{fontSize:'11px',color:'#a08a6f'}},
             gridLineColor:'#F0E8DC', labels:{style:{fontSize:'10px',color:'#a08a6f'}} },
           { title:{text:'訂單筆數', style:{fontSize:'11px',color:'#a08a6f'}}, opposite:true,
             gridLineWidth:0, labels:{style:{fontSize:'10px',color:'#a08a6f'}} }],
    tooltip:{ shared:true, backgroundColor:'rgba(255,255,255,.97)', borderColor:'#E4D3BC', borderRadius:8,
              style:{fontSize:'11px'},
              formatter:function(){
                var s='<b>'+this.x+'</b><br/>';
                this.points.forEach(function(p){
                  var u = (p.series.options.yAxis===1) ? ' 筆' : (' '+unit);
                  s += '<span style="color:'+p.series.color+'">●</span> '+p.series.name+'：<b>'+nf1(p.y)+u+'</b><br/>';
                });
                /* 金額一定要順便講「這一期有幾張訂單真的填了單價」，
                   否則使用者會把「沒人填單價」誤讀成「那一季沒接到單」 */
                if(isAmt){
                  var i = this.points.length ? this.points[0].point.index : -1;
                  if(i>=0 && t.cur[i]) s += '<span style="color:#a08a6f;">'+DATA.meta.year+' 有單價 '
                                          + t.cur[i].px_orders+'/'+t.cur[i].orders+' 張</span><br/>';
                  if(showPrev && i>=0 && t.prev[i]) s += '<span style="color:#a08a6f;">'+t.prev_year+' 有單價 '
                                          + t.prev[i].px_orders+'/'+t.prev[i].orders+' 張</span>';
                }
                return s;
              } },
    plotOptions:{ column:{ grouping:true, pointPadding:0.08, groupPadding:0.14 } },
    series: ser
  }));
  $('#trendHint').text('依「'+DATA.meta.gran_label+'」切期；柱＝'+(isAmt?'金額（萬元）':'數量')+'，折線＝訂單筆數');
}
$('#trendMetric, #cbPrevYear').on('change', function(){ if(DATA) renderTrend(); });

/* ── 新訂單（新料號）──────────────────────────────── */
function renderNew(){
  var t = DATA.trend, c = DATA.kpi.cur;
  var cats = t.cur.map(function(b){ return b.label.replace(/^\d{4}\s*/,''); });
  chart('chNew', opt({
    xAxis:{ categories:cats },
    yAxis:[{ title:{text:'新料號（支）',style:{fontSize:'11px',color:'#a08a6f'}}, gridLineColor:'#F0E8DC',
             labels:{style:{fontSize:'10px',color:'#a08a6f'}} },
           { title:{text:'新料號訂單佔比',style:{fontSize:'11px',color:'#a08a6f'}}, opposite:true, gridLineWidth:0,
             labels:{format:'{value}%', style:{fontSize:'10px',color:'#a08a6f'}} }],
    tooltip:{ shared:true, style:{fontSize:'11px'} },
    series:[
      { name:'新料號數', type:'column', data:t.cur.map(function(b){return b.new_parts;}), color:C_CORAL, borderRadius:3, yAxis:0 },
      { name:'新料號訂單佔比', type:'line', yAxis:1, color:C_BROWN, lineWidth:2, marker:{radius:3},
        data:t.cur.map(function(b){ return b.orders? Math.round(b.new_orders*1000/b.orders)/10 : 0; }) }
    ]
  }));
  var oldOrders = Math.max(0, c.orders - c.new_orders);
  chart('chNewPie', opt({
    chart:{ type:'pie' },
    tooltip:{ pointFormat:'<b>{point.y} 筆</b>（{point.percentage:.1f}%）', style:{fontSize:'11px'} },
    plotOptions:{ pie:{ dataLabels:{ enabled:true, style:{fontSize:'11px',color:'#6B4423',textOutline:'none'},
                        format:'{point.name}<br>{point.y} 筆（{point.percentage:.0f}%）' }, innerSize:'45%' } },
    series:[{ name:'訂單筆數', colorByPoint:true, data:[
      { name:'新料號的訂單', y:c.new_orders, color:C_CORAL },
      { name:'既有料號的訂單', y:oldOrders, color:C_SAND }
    ]}]
  }));
  $('#newHint').text('本期新料號 '+nf(c.new_parts)+' 支、產生 '+nf(c.new_orders)+' 筆訂單（金額 '+money(c.new_amount)+'）');
  renderNewTable();
}
function renderNewTable(){
  var kw = String($('#newKw').val()||'').trim().toLowerCase();
  var lab = DATA.meta.source_labels || {}, h='', n=0;
  (DATA.new_list||[]).forEach(function(p){
    if(kw && (String(p.pno).toLowerCase().indexOf(kw)<0 && String(p.cname).toLowerCase().indexOf(kw)<0)) return;
    n++;
    h += '<tr><td>'+pnoCell(p)+'</td><td>'+esc(p.cname)+'</td><td>'+dispDate(p.first)+'</td>'
       + '<td style="text-align:center;">'+esc(lab[p.fsrc]||p.fsrc||'—')+'</td>'
       + '<td class="n">'+nf(p.orders)+'</td><td class="n">'+nf(p.qty)+'</td>'
       + '<td class="n">'+(p.px? money(p.amount) : '<span style="color:#a08a6f;">未開價</span>')+'</td></tr>';
  });
  $('#tblNew tbody').html(h || '<tr><td colspan="7" style="text-align:center;color:#a08a6f;">本期沒有新料號</td></tr>');
}
$('#newKw').on('input', function(){ if(DATA) renderNewTable(); });

/* ── 全製／單製 ─────────────────────────────────────── */
function renderProc(){
  var c = DATA.kpi.cur, t = DATA.trend;
  var pie = [
    { name:'全製', y:c.full,   color:C_AMBER },
    { name:'單製', y:c.single, color:C_BROWN }
  ];
  if(c.unknown) pie.push({ name:'無法判定', y:c.unknown, color:C_SAND });
  if(c.none)    pie.push({ name:'未填製程', y:c.none,    color:'#D9CDBC' });
  chart('chProcPie', opt({
    chart:{ type:'pie' },
    tooltip:{ pointFormat:'<b>{point.y} 筆</b>（{point.percentage:.1f}%）', style:{fontSize:'11px'} },
    plotOptions:{ pie:{ dataLabels:{ enabled:true, style:{fontSize:'11px',color:'#6B4423',textOutline:'none'},
                        format:'{point.name}<br>{point.y} 筆（{point.percentage:.0f}%）' } } },
    series:[{ name:'訂單筆數', colorByPoint:true, data:pie }]
  }));
  var cats = t.cur.map(function(b){ return b.label.replace(/^\d{4}\s*/,''); });
  var ser = [
    { name:'全製', data:t.cur.map(function(b){return b.full;}),    color:C_AMBER },
    { name:'單製', data:t.cur.map(function(b){return b.single;}),  color:C_BROWN }
  ];
  if(c.unknown) ser.push({ name:'無法判定', data:t.cur.map(function(b){return b.unknown;}), color:C_SAND });
  if(c.none)    ser.push({ name:'未填製程', data:t.cur.map(function(b){return b.none;}),    color:'#D9CDBC' });
  chart('chProcStack', opt({
    chart:{ type:'column' },
    xAxis:{ categories:cats },
    yAxis:{ min:0, title:{text:'訂單筆數',style:{fontSize:'11px',color:'#a08a6f'}}, gridLineColor:'#F0E8DC',
            labels:{style:{fontSize:'10px',color:'#a08a6f'}}, stackLabels:{enabled:false} },
    tooltip:{ shared:true, style:{fontSize:'11px'} },
    plotOptions:{ column:{ stacking:'normal', borderRadius:2, pointPadding:0.05, groupPadding:0.16 } },
    series: ser
  }));
  var samp = (DATA.proc.samples)||{}, h='';
  (DATA.proc.rule_hits||[]).forEach(function(r){
    h += '<tr><td>'+esc(r.label)+'</td><td>'+esc(r.kw)+'</td>'
       + '<td style="text-align:center;">'+(r.cls==='full'?'全製':'單製')+'</td>'
       + '<td class="n">'+nf(r.orders)+'</td><td></td></tr>';
  });
  h += '<tr><td colspan="3" style="color:#a08a6f;">沒有命中任何規則 → 歸為「'
     + (DATA.meta.fallback==='full'?'全製':(DATA.meta.fallback==='single'?'單製':'無法判定'))+'」</td>'
     + '<td class="n">'+nf(DATA.meta.fallback==='full'? Math.max(0,DATA.kpi.cur.full - sumHits())
                       : (DATA.meta.fallback==='single'? DATA.kpi.cur.single : DATA.kpi.cur.unknown))+'</td>'
     + '<td style="font-size:11px;color:#a08a6f;">'+esc((samp[DATA.meta.fallback]||[]).slice(0,6).join('、'))+'</td></tr>';
  h += '<tr><td colspan="3" style="color:#a08a6f;">製程欄空白</td><td class="n">'+nf(DATA.kpi.cur.none)+'</td><td></td></tr>';
  $('#tblProc tbody').html(h);
}
function sumHits(){ var s=0; (DATA.proc.rule_hits||[]).forEach(function(r){ s += Number(r.orders)||0; }); return s; }

/* ── 數量區間 ───────────────────────────────────────── */
function renderBand(){
  var b = DATA.bands||[];
  chart('chBand', opt({
    chart:{ type:'column' },
    xAxis:{ categories:b.map(function(x){return x.label;}) },
    yAxis:[{ title:{text:'訂單筆數',style:{fontSize:'11px',color:'#a08a6f'}}, gridLineColor:'#F0E8DC',
             labels:{style:{fontSize:'10px',color:'#a08a6f'}} },
           { title:{text:'佔筆數',style:{fontSize:'11px',color:'#a08a6f'}}, opposite:true, gridLineWidth:0,
             labels:{format:'{value}%', style:{fontSize:'10px',color:'#a08a6f'}} }],
    tooltip:{ shared:true, style:{fontSize:'11px'} },
    plotOptions:{ column:{ borderRadius:3, pointPadding:0.06 },
                  series:{ dataLabels:{ enabled:true, style:{fontSize:'10px',color:'#6B4423',textOutline:'none'} } } },
    series:[
      { name:'訂單筆數', type:'column', data:b.map(function(x){return x.orders;}), color:C_AMBER, yAxis:0 },
      { name:'佔筆數%', type:'line', yAxis:1, color:C_CORAL, lineWidth:2, marker:{radius:3},
        data:b.map(function(x){return x.pct_orders;}), dataLabels:{enabled:false} }
    ]
  }));
  var h='';
  b.forEach(function(x){
    h += '<tr><td>'+esc(x.label)+(x.min===null?' <span class="badge-warn">未涵蓋</span>':'')+'</td>'
       + '<td class="n">'+nf(x.orders)+'</td><td class="n">'+x.pct_orders+'%</td>'
       + '<td class="n">'+nf(x.qty)+'</td><td class="n">'+x.pct_qty+'%</td>'
       + '<td class="n">'+money(x.amount)+'</td><td class="n">'+x.pct_amount+'%</td></tr>';
  });
  $('#tblBand tbody').html(h||'<tr><td colspan="7" style="text-align:center;color:#a08a6f;">本期沒有訂單</td></tr>');
}

/* ── 客戶比較 ───────────────────────────────────────── */
function renderClient(){
  var cc = DATA.client_cmp, met = $('#cliMetric').val(), m = DATA.meta;
  var cats = (m.buckets||[]).map(function(s){ return s.replace(/^\d{4}\s*/,''); });
  var isAmt = (met==='amount');
  var ser = (cc.series||[]).map(function(s, i){
    return { name:s.name, data:(s[met]||[]).map(function(v){ return isAmt? wan(v) : v; }), color:PAL[i%PAL.length], borderRadius:2 };
  });
  chart('chClient', opt({
    chart:{ type:'column' },
    xAxis:{ categories:cats },
    yAxis:{ title:{text:(isAmt?'金額（萬元）':(met==='qty'?'數量（支）':'訂單筆數')),style:{fontSize:'11px',color:'#a08a6f'}},
            gridLineColor:'#F0E8DC', labels:{style:{fontSize:'10px',color:'#a08a6f'}} },
    tooltip:{ shared:true, style:{fontSize:'11px'} },
    plotOptions:{ column:{ pointPadding:0.02, groupPadding:0.12 } },
    series: ser.length? ser : [{ name:'（沒有資料）', data:[] }]
  }));
  var keys = cc.keys||[], byKey={};
  (DATA.clients||[]).forEach(function(c){ byKey[c.key]=c; });
  var mk = 'd_'+m.rank_metric, fmt = (m.rank_metric==='amount')? money : nf;
  var h='';
  keys.forEach(function(k){
    var c = byKey[k]; if(!c) return;
    h += '<tr><td>'+esc(c.name)+(c.bad?' <span class="badge-warn">未建主檔</span>':'')
       + (c.flag==='new'?' <span class="badge-new">新客戶</span>':'')
       + (c.flag==='return'?' <span class="badge-return">回流客戶</span>':'')+'</td>'
       + '<td class="n">'+nf(c.cur.orders)+'</td><td class="n">'+nf(c.cur.qty)+'</td>'
       + '<td class="n">'+money(c.cur.amount)+'</td><td class="n">'+nf(c.parts)+'</td>'
       + '<td class="n">'+nf(c.new_parts)+'</td><td class="n">'+nf(c.cur.full)+'</td><td class="n">'+nf(c.cur.single)+'</td>'
       + '<td class="n">'+deltaHtml(c.cur[m.rank_metric], c.cmp[m.rank_metric], fmt)+'</td></tr>';
  });
  $('#tblClient tbody').html(h||'<tr><td colspan="9" style="text-align:center;color:#a08a6f;">沒有可比較的客戶</td></tr>');
  $('#cliHint').text(Object.keys(CLI_SEL).length
    ? ('已篩選 '+Object.keys(CLI_SEL).length+' 家客戶，整份報表都只算這幾家')
    : ('未篩選客戶 → 自動取本期前 8 大客戶做比較（增減欄位依「'+metLabel(m.rank_metric)+'」）'));
}
function metLabel(k){ return k==='amount'?'金額':(k==='qty'?'數量':'筆數'); }
$('#cliMetric').on('change', function(){ if(DATA) renderClient(); });

/* ── 客戶增減排名 ───────────────────────────────────
   **依「增減金額（或數量）」排序，不是依百分比**：只看 % 的話，
   1 萬變 2 萬的小客戶會永遠排在 500 萬掉到 400 萬的大客戶前面。 */
function renderRank(){
  var m = DATA.meta, top = parseInt($('#rankTop').val(),10)||15;
  var mk = 'd_'+m.rank_metric, fmt = (m.rank_metric==='amount')? money : nf;
  var rows = (DATA.rank_clients||[]).slice();
  var ups   = rows.filter(function(c){ return c[mk] > 0; }).slice(0, top);
  var downs = rows.filter(function(c){ return c[mk] < 0; });
  downs.sort(function(a,b){ return a[mk]-b[mk]; });
  downs = downs.slice(0, top);
  var lost = rows.filter(function(c){ return c.flag==='lost'; });

  var mix = ups.slice().reverse().concat(downs);   /* 圖上由多到少一路排到衰退 */
  var isAmt = (m.rank_metric==='amount');
  var hRank = Math.max(280, mix.length*24+90);
  sizeBox('chRank', hRank);
  chart('chRank', opt({
    chart:{ type:'bar', height: hRank },
    xAxis:{ categories: mix.map(function(c){ return c.name; }), labels:{style:{fontSize:'11px',color:'#6B4423'}} },
    yAxis:{ title:{text:'較'+m.cmp_label+'增減（'+(isAmt?'萬元':metLabel(m.rank_metric))+'）',
                   style:{fontSize:'11px',color:'#a08a6f'}},
            gridLineColor:'#F0E8DC', labels:{style:{fontSize:'10px',color:'#a08a6f'}},
            plotLines:[{ value:0, color:'#B23A2E', width:1, dashStyle:'Dash' }] },
    legend:{ enabled:false },
    tooltip:{ style:{fontSize:'11px'},
              formatter:function(){ return '<b>'+this.x+'</b><br>增減：<b>'+nf1(this.y)+(isAmt?' 萬元':'')+'</b>'; } },
    plotOptions:{ bar:{ borderRadius:2, dataLabels:{ enabled:true, style:{fontSize:'10px',color:'#6B4423',textOutline:'none'},
                        formatter:function(){ return nf1(this.y); } } } },
    series:[{ name:'增減', data: mix.map(function(c){
        var v = isAmt ? wan(c[mk]) : c[mk];
        return { y:v, color: v>=0 ? C_GREEN : C_CORAL };
      }) }]
  }));

  function rowsHtml(arr){
    var h='';
    arr.forEach(function(c){
      h += '<tr><td>'+esc(c.name)
         + (c.flag==='new'?' <span class="badge-new">新客戶</span>':'')
         + (c.flag==='return'?' <span class="badge-return">回流客戶</span>':'')
         + (c.flag==='lost'?' <span class="badge-lost">本期掛零</span>':'')
         + (c.bad?' <span class="badge-warn">未建主檔</span>':'')+'</td>'
         + '<td class="n">'+fmt(c.cur[m.rank_metric])+'</td>'
         + '<td class="n">'+fmt(c.cmp[m.rank_metric])+'</td>'
         + '<td class="n">'+deltaHtml(c.cur[m.rank_metric], c.cmp[m.rank_metric], fmt)+'</td></tr>';
    });
    return h;
  }
  $('#tblRankUp').find('tbody').html(rowsHtml(ups)||'<tr><td colspan="4" style="text-align:center;color:#a08a6f;">沒有成長的客戶</td></tr>');
  var dh = rowsHtml(downs);
  if(lost.length){
    dh += '<tr><td colspan="4" style="background:#faf6f0;color:#6B4423;font-size:11px;">'
        + '<b>基期有下單、本期完全沒有</b>（'+lost.length+' 家）：'
        + esc(lost.map(function(c){return c.name;}).slice(0,40).join('、')) + (lost.length>40?' …':'') + '</td></tr>';
  }
  $('#tblRankDown').find('tbody').html(dh||'<tr><td colspan="4" style="text-align:center;color:#a08a6f;">沒有衰退的客戶</td></tr>');
  $('#rankHint').text('依「較'+m.cmp_label+'的增減'+metLabel(m.rank_metric)+'」排序（不是依百分比——只看 % 的話小客戶會永遠排在大客戶前面）。'
    + '「新客戶」＝系統裡本期才第一次出現；「回流客戶」＝以前下過單，只是'+m.cmp_label+'剛好沒下、這期又回來——兩者處理方式不同，不要混為一談。');
}
$('#rankTop').on('change', function(){ if(DATA) renderRank(); });

/* ── 料號排名 ───────────────────────────────────────── */
function renderParts(){
  var m = DATA.meta, top = parseInt($('#rankTop').val(),10)||15;
  var list = (DATA.rank_parts||[]).slice(0, top), isAmt = (m.rank_metric==='amount');
  var hParts = Math.max(280, list.length*24+90);
  sizeBox('chParts', hParts);
  chart('chParts', opt({
    chart:{ type:'bar', height: hParts },
    xAxis:{ categories: list.map(function(p){ return p.pno + '｜' + p.cname; }),
            labels:{style:{fontSize:'11px',color:'#6B4423'}} },
    yAxis:{ title:{text:metLabel(m.rank_metric)+(isAmt?'（萬元）':''),style:{fontSize:'11px',color:'#a08a6f'}},
            gridLineColor:'#F0E8DC', labels:{style:{fontSize:'10px',color:'#a08a6f'}} },
    legend:{ enabled:false },
    tooltip:{ style:{fontSize:'11px'},
              formatter:function(){ return '<b>'+this.x+'</b><br>'+metLabel(m.rank_metric)+'：<b>'+nf1(this.y)+(isAmt?' 萬元':'')+'</b>'; } },
    plotOptions:{ bar:{ borderRadius:2, dataLabels:{ enabled:true, style:{fontSize:'10px',color:'#6B4423',textOutline:'none'},
                        formatter:function(){ return nf1(this.y); } } } },
    series:[{ name:metLabel(m.rank_metric), data: list.map(function(p, i){
        var v = isAmt ? wan(p.cur.amount) : p.cur[m.rank_metric];
        return { y:v, color: p.is_new ? C_CORAL : PAL[(i%PAL.length)] };
      }) }]
  }));
  var fmt = isAmt? money : nf, h='';
  (DATA.rank_parts||[]).forEach(function(p, i){
    h += '<tr><td class="n">'+(i+1)+'</td><td>'+pnoCell(p)+'</td><td>'+esc(p.cname)+'</td>'
       + '<td class="n">'+nf(p.cur.orders)+'</td><td class="n">'+nf(p.cur.qty)+'</td>'
       + '<td class="n">'+money(p.cur.amount)+'</td>'
       + '<td class="n">'+deltaHtml(p.cur[m.rank_metric], p.cmp[m.rank_metric], fmt)+'</td>'
       + '<td style="text-align:center;">'+(p.is_new?'<span class="badge-new">新</span>':'')+'</td></tr>';
  });
  $('#tblParts tbody').html(h||'<tr><td colspan="8" style="text-align:center;color:#a08a6f;">本期沒有訂單</td></tr>');
  $('#partHint').text('依本期「'+metLabel(m.rank_metric)+'」排序，紅色＝本期第一次出現的新料號');
}

/* ── CSV（把畫面上全部區塊一次帶去 Excel）──────────── */
$('#btnCsv').on('click', function(){
  if(!DATA){ alert('請先計算'); return; }
  var m = DATA.meta, L = [];
  function row(){ L.push(Array.prototype.slice.call(arguments).map(function(v){
    v = (v==null)?'':String(v);
    return /[",\n]/.test(v) ? '"'+v.replace(/"/g,'""')+'"' : v;
  }).join(',')); }
  row('訂單分析', m.period.label, m.period_eff.start+'~'+m.period_eff.end, '日期基準='+m.basis_label,
      '基期='+m.cmp_period.label+'('+m.cmp_period_eff.start+'~'+m.cmp_period_eff.end+')',
      '排序口徑='+metLabel(m.rank_metric), '匯出時間='+m.today);
  row('※金額只計「有填單價」的訂單', '本期 '+DATA.kpi.cur.px_orders+'/'+DATA.kpi.cur.orders+' 張有單價',
      '基期 '+DATA.kpi.cmp.px_orders+'/'+DATA.kpi.cmp.orders+' 張');
  row('');
  row('【摘要】','項目','本期','基期');
  [['訂單筆數','orders'],['訂單數量','qty'],['訂單金額','amount'],['下單客戶數','clients'],
   ['受訂料號數','parts'],['新料號','new_parts'],['新料號訂單','new_orders'],['新料號金額','new_amount'],
   ['全製筆數','full'],['單製筆數','single']].forEach(function(x){
    row('', x[0], Math.round(DATA.kpi.cur[x[1]]||0), Math.round(DATA.kpi.cmp[x[1]]||0));
  });
  row('');
  row('【趨勢】','期別','訂單筆數','訂單數量','訂單金額','有單價張數','新料號','新料號訂單');
  DATA.trend.cur.forEach(function(b){ row('', b.label, b.orders, b.qty, Math.round(b.amount), b.px_orders, b.new_parts, b.new_orders); });
  row('');
  row('【數量區間】','區間','筆數','佔筆數%','數量','佔數量%','金額','佔金額%');
  DATA.bands.forEach(function(b){ row('', b.label, b.orders, b.pct_orders, b.qty, b.pct_qty, Math.round(b.amount), b.pct_amount); });
  row('');
  row('【全製/單製】','規則','關鍵字','判定','命中筆數');
  (DATA.proc.rule_hits||[]).forEach(function(r){ row('', r.label, r.kw, r.cls==='full'?'全製':'單製', r.orders); });
  row('', '合計', '', '全製', DATA.kpi.cur.full);
  row('', '合計', '', '單製', DATA.kpi.cur.single);
  row('', '合計', '', '未填製程', DATA.kpi.cur.none);
  row('');
  row('【客戶（全部）】','客戶','客戶編號','本期筆數','本期數量','本期金額','基期筆數','基期數量','基期金額',
      '料號數','新料號','全製','單製','狀態');
  (DATA.clients||[]).forEach(function(c){
    row('', c.name, c.cid, c.cur.orders, c.cur.qty, Math.round(c.cur.amount),
        c.cmp.orders, c.cmp.qty, Math.round(c.cmp.amount), c.parts, c.new_parts, c.cur.full, c.cur.single,
        (c.flag==='new'?'新客戶':(c.flag==='return'?'回流客戶':(c.flag==='lost'?'本期掛零':'')))+(c.bad?' 未建主檔':''));
  });
  row('');
  row('【受訂料號排名】','#','料號','客戶','本期筆數','本期數量','本期金額','基期金額','是否新料號','第一次出現','來源');
  (DATA.rank_parts||[]).forEach(function(p,i){
    row('', i+1, p.pno, p.cname, p.cur.orders, p.cur.qty, Math.round(p.cur.amount), Math.round(p.cmp.amount),
        p.is_new?'新':'', p.first, (m.source_labels||{})[p.fsrc]||p.fsrc);
  });
  row('');
  row('【新料號明細】','料號','客戶','第一次出現','來源','本期筆數','本期數量','本期金額');
  (DATA.new_list||[]).forEach(function(p){
    row('', p.pno, p.cname, p.first, (m.source_labels||{})[p.fsrc]||p.fsrc, p.orders, p.qty, Math.round(p.amount));
  });

  var blob = new Blob(['﻿'+L.join('\r\n')], {type:'text/csv;charset=utf-8;'});
  var a = document.createElement('a');
  a.href = URL.createObjectURL(blob);
  a.download = '訂單分析_' + m.period.label.replace(/\s/g,'') + '.csv';
  document.body.appendChild(a); a.click();
  setTimeout(function(){ URL.revokeObjectURL(a.href); a.remove(); }, 500);
});

/* ══════════════════════════════════════════════════════════════════
 * 列印報告（使用者要求：固定 A3 橫式、排版要美觀）
 *
 * 固定 A3、不做「A4 放不下自動升 A3」：CSS 宣告的紙張尺寸是網頁單方面說的，
 * 印表機紙匣裡實際放什麼才是設備決定的——放 A4 卻宣告 A3，Chrome 會把 A3 版面
 * 硬套到 A4 紙上、右邊被裁掉（project_mgmt_ui.js 已踩過這個坑並拿掉自動升級）。
 * 使用者這次是明講「固定用 A3 橫式」，所以直接宣告 A3，並在按下列印時提醒
 * 「列印對話框請把紙張選成 A3」。
 *
 * 版面規則沿用 ai-rules/16：字型統一、表頭跨頁重複、資料列不被切開、
 * 多頁才印左下角頁碼（量寬度用列印實際寬度，不是視窗寬度）；本報表不是
 * 正式 AS9100 文件（沒有綁定表單編號），所以只印公司全名不印 AS 編號。
 * 圖表一律用 Highcharts 既有實例的 getSVG() 轉成向量圖嵌進列印版，
 * 不重新畫一次——畫面看到的圖跟列印看到的圖才會是同一份數據。
 * ══════════════════════════════════════════════════════════════════ */
var PR_MG = 14, PR_PAD = 5;                 // mm，跟 project_mgmt_ui.js 同一組數字
var PR_W_MM = 420, PR_H_MM = 297;           // A3 橫式
function prChartSvg(id, w, h){
  try {
    var c = CHARTS[id];
    if(!c) return '';
    return c.getSVG({ chart:{ width:w, height:h } });
  } catch(e){ return ''; }
}
function prBadgeTxt(lv){ return lv==='bad'?'要處理':(lv==='warn'?'要注意':(lv==='good'?'正面':'說明')); }
function oaPrintHtml(){
  var m = DATA.meta, k = DATA.kpi, useAmt = (m.px_cov_cur>=30 && m.px_cov_cmp>=30);
  var printTime = new Date().toLocaleString('zh-TW');

  var css =
    '*{box-sizing:border-box;margin:0;padding:0;}'+
    'body{font-family:"Microsoft JhengHei","微軟正黑體",sans-serif;color:#222;font-size:10.5pt;padding:'+PR_PAD+'mm;}'+
    '@page{size:'+PR_W_MM+'mm '+PR_H_MM+'mm;margin:'+PR_MG+'mm;}'+
    '@media print{*{-webkit-print-color-adjust:exact;print-color-adjust:exact;}thead{display:table-header-group;}tr{page-break-inside:avoid;}'+
    '.pr-sec{page-break-inside:avoid;}}'+
    '.pr-head{background:#FBF3E7;color:#4A3524;padding:6mm 8mm;border-radius:2mm;margin-bottom:4mm;'+
    'border:1px solid #E4D3BC;border-left:3mm solid #F0A24B;}'+
    '.pr-co{font-size:17pt;font-weight:700;letter-spacing:2px;text-align:left;}'+
    '.pr-tt{font-size:13pt;text-align:left;margin-top:1mm;color:#6B4423;}'+
    '.pr-sub{font-size:9pt;text-align:left;margin-top:2mm;color:#8a6a4a;}'+
    '.pr-alert{background:#FDF2EE;border:1px solid #DD5138;border-left:4mm solid #DD5138;border-radius:2mm;'+
    'padding:4mm 6mm;margin-bottom:4mm;font-size:10pt;line-height:1.7;}'+
    '.pr-alert b.tt{display:block;color:#DD5138;font-size:12pt;margin-bottom:1.5mm;}'+
    '.pr-kpi{display:grid;grid-template-columns:repeat(8,minmax(0,1fr));gap:2.5mm;margin-bottom:4mm;}'+
    '.pr-kc{background:#faf6f0;border:1px solid #E4D3BC;border-top:1mm solid #F0A24B;border-radius:1.5mm;padding:2.5mm 2mm;}'+
    '.pr-kc.warn{border-top-color:#DD5138;}'+
    '.pr-kc .lb{font-size:8pt;color:#a08a6f;}'+
    '.pr-kc .vl{font-size:12.5pt;font-weight:700;color:#4A3524;line-height:1.25;word-break:break-all;}'+
    '.pr-kc .sb{font-size:7.5pt;color:#a08a6f;margin-top:0.5mm;}'+
    '.pr-sec{margin-bottom:4mm;}'+
    '.pr-sec-title{font-size:12pt;font-weight:700;color:#4A3524;border-left:1.2mm solid #F0A24B;padding-left:2mm;margin-bottom:2mm;}'+
    '.pr-two{display:grid;grid-template-columns:minmax(0,1fr) minmax(0,1fr);gap:5mm;}'+
    '.pr-three{display:grid;grid-template-columns:minmax(0,34fr) minmax(0,33fr) minmax(0,33fr);gap:5mm;}'+
    '.pr-chart{text-align:center;}'+
    '.pr-chart svg{max-width:100%;height:auto;}'+
    '.pr-ins{display:flex;gap:2.5mm;align-items:flex-start;border:1px solid #E4D3BC;border-left:1.2mm solid #B9A78C;'+
    'border-radius:1.2mm;padding:1.8mm 3mm;margin-bottom:1.5mm;font-size:9pt;line-height:1.55;}'+
    '.pr-ins.bad{border-left-color:#DD5138;} .pr-ins.warn{border-left-color:#F0A24B;} .pr-ins.good{border-left-color:#4F8A4F;}'+
    '.pr-ins .tag{flex:0 0 auto;font-size:7.5pt;font-weight:700;color:#fff;background:#B9A78C;border-radius:3mm;padding:0.3mm 2mm;}'+
    '.pr-ins.bad .tag{background:#DD5138;} .pr-ins.warn .tag{background:#F0A24B;color:#4E2C0B;} .pr-ins.good .tag{background:#4F8A4F;}'+
    '.pr-ins .bd{flex:1 1 auto;} .pr-ins .bd b{color:#4A3524;}'+
    'table{width:100%;border-collapse:collapse;table-layout:fixed;font-size:8.8pt;}'+
    'th{background:#8a5a2b;color:#fff;padding:1.3mm 2mm;font-weight:700;white-space:nowrap;}'+
    'td{padding:1.1mm 2mm;border-bottom:0.2mm solid #E4D3BC;overflow:hidden;text-overflow:ellipsis;white-space:nowrap;}'+
    'tr:nth-child(even) td{background:#FDFBF8;}'+
    '.tr{text-align:right;} .tc{text-align:center;}'+
    '.pr-badge{font-size:7.5pt;border-radius:2.5mm;padding:0.2mm 1.6mm;color:#fff;}'+
    '.pr-badge.new{background:#DD5138;} .pr-badge.return{background:#F0A24B;color:#4E2C0B;} .pr-badge.lost{background:#7A4A34;} .pr-badge.warn{background:#F7E0BD;color:#6B4423;}'+
    '.pr-note{font-size:8pt;color:#6B4423;background:#faf6f0;border-left:1mm solid #F0A24B;padding:2mm 3mm;margin-bottom:3mm;line-height:1.6;}'+
    '.pr-footer{margin-top:3mm;padding-top:2mm;border-top:0.2mm solid #E4D3BC;font-size:7.5pt;color:#a08a6f;text-align:center;}';

  var h = '<div class="pr-head"><div class="pr-co">'+esc(COMPANY||'')+'</div>'+
    '<div class="pr-tt">訂单分析報告</div>'+
    '<div class="pr-sub">期間：'+esc(m.period.label)+'（'+dispDate(m.period_eff.start)+'～'+dispDate(m.period_eff.end)+'）'+
    '　日期基準：'+esc(m.basis_label)+'　比較基準：'+esc(m.cmp_label)+
    '（'+esc(m.cmp_period.label)+'）　列印時間：'+esc(printTime)+'</div></div>';

  // 就衴2026-09-22這一批自己的紀錄一樣，金額只算得出有填單價的訂单，每張列印都要講清楚覆蓋率
  h += '<div class="pr-note">※訂单金額只算得出「有填單價」的訂单：本期 '+
    nf(k.cur.px_orders)+'/'+nf(k.cur.orders)+' 張有單價（'+m.px_cov_cur+'%），'+esc(m.cmp_label)+' '+
    nf(k.cmp.px_orders)+'/'+nf(k.cmp.orders)+' 張（'+m.px_cov_cmp+'%）。' +
    (useAmt?'':'比例過低无法比較金額，以下報告已改以數量筆數為主。') +
    '　「新料號」=在系統現有資料裡第一次出現，不等於公司從來沒做過。</div>';

  if(DATA.kpi_alert && DATA.kpi_alert.below){
    var a = DATA.kpi_alert;
    h += '<div class="pr-alert"><b class="tt">⚠ 本月要衝刺：最近 '+a.n+' 個月有 '+a.bad_count+' 個月「'+esc(a.indicator)+'」未達標</b>'+
      '未達標月份：<b>'+esc(a.bad_list.join('、'))+'</b>。'+
      (a.month_gap===null ? '本年度未設定每月受訂目標金額，無法推算本月還差多少。'
        : ('本月目標 '+money(a.month_target)+' 元，已接 '+money(a.month_got)+' 元'+
           (a.month_gap>0 ? ('，還差 <b>'+money(a.month_gap)+'</b> 元，剩 '+a.days_left+' 天。') : '，已達標。'))) +
      '</div>';
  }

  function kc(lab, val, sub, warn){ return '<div class="pr-kc'+(warn?' warn':'')+'"><div class="lb">'+lab+'</div><div class="vl">'+val+'</div><div class="sb">'+sub+'</div></div>'; }
  h += '<div class="pr-kpi">'+
    kc('訂单筆数', nf(k.cur.orders), '較'+esc(m.cmp_label)+' '+(k.cur.orders-k.cmp.orders>=0?'+':'')+nf(k.cur.orders-k.cmp.orders)) +
    kc('訂单數量', nf(k.cur.qty), '較'+esc(m.cmp_label)+' '+(k.cur.qty-k.cmp.qty>=0?'+':'')+nf(k.cur.qty-k.cmp.qty)) +
    kc('訂单金額', money(k.cur.amount), '有單價 '+k.cur.px_orders+'/'+k.cur.orders) +
    kc('下单客戶數', nf(k.cur.clients), '較'+esc(m.cmp_label)+' '+(k.cur.clients-k.cmp.clients>=0?'+':'')+nf(k.cur.clients-k.cmp.clients)) +
    kc('受訂料號數', nf(k.cur.parts), '較'+esc(m.cmp_label)+' '+(k.cur.parts-k.cmp.parts>=0?'+':'')+nf(k.cur.parts-k.cmp.parts)) +
    kc('新料號', nf(k.cur.new_parts), '佔本期料號 '+pct(k.cur.new_parts,k.cur.parts)) +
    kc('新料號訂单', nf(k.cur.new_orders), '金額 '+money(k.cur.new_amount)) +
    kc('全製佔比', k.cur.orders?Math.round(k.cur.full*1000/k.cur.orders)/10:0, '全製 '+nf(k.cur.full)+' / 單製 '+nf(k.cur.single), (k.cur.orders-k.cur.px_orders)>k.cur.orders*0.3) +
    '</div>';

  // 自動分析
  h += '<div class="pr-sec"><div class="pr-sec-title">自動分析</div>';
  var order = {bad:0,warn:1,good:2,info:3};
  var ins = (DATA.insights||[]).slice().sort(function(x,y){ return (order[x.level]||9)-(order[y.level]||9); });
  if(!ins.length) h += '<div style="font-size:9pt;color:#a08a6f;">本期沒有需要特別指出的變化。</div>';
  ins.forEach(function(x){
    h += '<div class="pr-ins '+esc(x.level)+'"><span class="tag">'+esc(prBadgeTxt(x.level))+'</span>'+
      '<div class="bd"><b>'+esc(x.title)+(x.metric?'（'+esc(x.metric)+'）':'')+'</b>　'+esc(x.detail)+'</div></div>';
  });
  h += '</div>';

  // 訂单趨勢
  var trendSvg = prChartSvg('chTrend', 1180, 300);
  if(trendSvg) h += '<div class="pr-sec"><div class="pr-sec-title">訂单趨勢</div><div class="pr-chart">'+trendSvg+'</div></div>';

  // 數量區間 + 全製/單製
  var bandSvg = prChartSvg('chBand', 700, 260);
  var procSvg = prChartSvg('chProcPie', 420, 260);
  var bandRows = ''; (DATA.bands||[]).forEach(function(b){
    bandRows += '<tr><td>'+esc(b.label)+'</td><td class="tr">'+nf(b.orders)+'</td><td class="tr">'+b.pct_orders+'%</td>'+
      '<td class="tr">'+money(b.amount)+'</td></tr>';
  });
  var ruleRows = ''; (DATA.proc.rule_hits||[]).forEach(function(r){
    ruleRows += '<tr><td>'+esc(r.label)+'</td><td class="tc">'+(r.cls==='full'?'全製':'單製')+'</td><td class="tr">'+nf(r.orders)+'</td></tr>';
  });
  h += '<div class="pr-sec"><div class="pr-two">'+
    '<div><div class="pr-sec-title">數量區間分析</div>'+
      (bandSvg?'<div class="pr-chart">'+bandSvg+'</div>':'')+
      '<table><colgroup><col style="width:34%"><col style="width:20%"><col style="width:20%"><col style="width:26%"></colgroup>'+
      '<thead><tr><th>區間</th><th class="tr">筆數</th><th class="tr">佔比</th><th class="tr">金額</th></tr></thead><tbody>'+bandRows+'</tbody></table></div>'+
    '<div><div class="pr-sec-title">全製／單製分析</div>'+
      (procSvg?'<div class="pr-chart">'+procSvg+'</div>':'')+
      '<table><colgroup><col style="width:44%"><col style="width:24%"><col style="width:32%"></colgroup>'+
      '<thead><tr><th>規則</th><th class="tc">判定</th><th class="tr">命中筆數</th></tr></thead><tbody>'+ruleRows+'</tbody></table></div>'+
    '</div></div>';

  // 客戶增減排名
  var rankSvg = prChartSvg('chRank', 1180, Math.min(420, document.getElementById('chRank').offsetHeight||300));
  if(rankSvg) h += '<div class="pr-sec"><div class="pr-sec-title">期間內客戶增減排名</div><div class="pr-chart">'+rankSvg+'</div></div>';

  var mk = 'd_'+m.rank_metric, fmt = (m.rank_metric==='amount')? money : nf;
  var ups = (DATA.rank_clients||[]).filter(function(c){return c[mk]>0;}).slice(0,10);
  var downs = (DATA.rank_clients||[]).filter(function(c){return c[mk]<0;}).sort(function(a,b){return a[mk]-b[mk];}).slice(0,10);
  function rankRows(list){ var s=''; list.forEach(function(c){
    s += '<tr><td>'+esc(c.name)+(c.flag==='new'?' <span class="pr-badge new">新</span>':'')+(c.flag==='return'?' <span class="pr-badge return">回流</span>':'')+(c.flag==='lost'?' <span class="pr-badge lost">掛零</span>':'')+'</td>'+
      '<td class="tr">'+fmt(c.cur[m.rank_metric])+'</td><td class="tr">'+fmt(c.cmp[m.rank_metric])+'</td>'+
      '<td class="tr">'+(c[mk]>=0?'+':'')+fmt(c[mk])+'</td></tr>'; });
    return s || '<tr><td colspan="4" class="tc">無</td></tr>'; }
  h += '<div class="pr-sec"><div class="pr-two">'+
    '<div><div class="pr-sec-title" style="border-left-color:#4F8A4F;">成長客戶（前 '+ups.length+' 家）</div>'+
      '<table><colgroup><col style="width:38%"><col style="width:20%"><col style="width:20%"><col style="width:22%"></colgroup>'+
      '<thead><tr><th>客戶</th><th class="tr">本期</th><th class="tr">基期</th><th class="tr">增減</th></tr></thead><tbody>'+rankRows(ups)+'</tbody></table></div>'+
    '<div><div class="pr-sec-title" style="border-left-color:#DD5138;">衰退客戶（前 '+downs.length+' 家）</div>'+
      '<table><colgroup><col style="width:38%"><col style="width:20%"><col style="width:20%"><col style="width:22%"></colgroup>'+
      '<thead><tr><th>客戶</th><th class="tr">本期</th><th class="tr">基期</th><th class="tr">增減</th></tr></thead><tbody>'+rankRows(downs)+'</tbody></table></div>'+
    '</div></div>';

  // 訂单量監控（移動平均）
  var maSvg = prChartSvg('chMa', 1180, 280);
  if(maSvg && DATA.ma && DATA.ma.series && DATA.ma.series.length){
    var maRows = ''; (DATA.ma.series||[]).slice(-8).forEach(function(s){
      var judge = s.unreliable ? '資料不足' : (s.below ? '<b style="color:#DD5138;">低於安全水平</b>' : '正常');
      maRows += '<tr><td>'+esc(s.ym)+'</td><td class="tr">'+money(s.amount)+'</td><td class="tr">'+money(s.avg)+'</td>'+
        '<td class="tr">'+(s.threshold===null?'未設定':money(s.threshold))+'</td><td>'+judge+'</td></tr>';
    });
    h += '<div class="pr-sec"><div class="pr-sec-title">訂单量監控（金額移動平均，前 '+DATA.ma.months+' 月）</div>'+
      '<div class="pr-chart">'+maSvg+'</div>'+
      '<table><colgroup><col style="width:14%"><col style="width:22%"><col style="width:22%"><col style="width:22%"><col style="width:20%"></colgroup>'+
      '<thead><tr><th>月份</th><th class="tr">當月金額</th><th class="tr">移動平均</th><th class="tr">安全水平</th><th>判定</th></tr></thead>'+
      '<tbody>'+maRows+'</tbody></table></div>';
  }

  // 受訂料號排名（前10）
  var partRows = ''; (DATA.rank_parts||[]).slice(0,10).forEach(function(p,i){
    partRows += '<tr><td class="tc">'+(i+1)+'</td><td>'+esc(p.pno)+'</td><td>'+esc(p.cname)+'</td>'+
      '<td class="tr">'+nf(p.cur.orders)+'</td><td class="tr">'+nf(p.cur.qty)+'</td><td class="tr">'+money(p.cur.amount)+'</td>'+
      '<td class="tc">'+(p.is_new?'<span class="pr-badge new">新</span>':'')+'</td></tr>';
  });
  h += '<div class="pr-sec"><div class="pr-sec-title">受訂料號排名（前 10）</div>'+
    '<table><colgroup><col style="width:5%"><col style="width:22%"><col style="width:16%"><col style="width:14%"><col style="width:14%"><col style="width:19%"><col style="width:10%"></colgroup>'+
    '<thead><tr><th>#</th><th>料號</th><th>客戶</th><th class="tr">筆數</th><th class="tr">數量</th><th class="tr">金額</th><th class="tc">新料號</th></tr></thead>'+
    '<tbody>'+(partRows||'<tr><td colspan="7" class="tc">無</td></tr>')+'</tbody></table></div>';

  h += '<div class="pr-footer">本報告由 EGsystem 訂单分析自動產生｜列印時間：'+esc(printTime)+'</div>';

  return '<!DOCTYPE html><html lang="zh-Hant"><head><meta charset="utf-8"><title>訂单分析報告 '+esc(m.period.label)+'</title>'+
    '<style>'+css+'</style></head><body>'+h+'</body></html>';
}
/** 量列印實際寬度下內容有没有超過一頁——一定要先把 body 縮到列印實際寬度再量，
 *  否則用視窗寬度（視緣徕不到）量出來的高度跟列印實際完全不一樣
 *（Shipping_Analysis_new.php 已踩過：1280px 量 753px 判多頁、實際列印只有 1 頁）。 */
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
  if(!w){ alert('瀏覽器擋掩了彈出視窗，請允許本站彈出後再試'); return; }
  w.document.write(oaPrintHtml()); w.document.close(); w.focus();
  try {
    if(window.EGPrintLog) EGPrintLog.record({ source:'order_analysis', doc_name:'訂单分析報告 '+DATA.meta.period.label, doc_kind:'form' });
  } catch(e){}
  setTimeout(function(){
    if(prNeedPageCounter(w)) prAddPageCounter(w);
    showToast('列印对話框請把紙張選成 A3，方向選横向', 'info');
    w.print();
  }, 700);
});


<?php if ($canView && $canSet): ?>
/* ── 設定 ───────────────────────────────────────────── */
var SET = { bands:[], rules:[], fallback:'single', alert:null, defaults:null };
var MA_USERS = [];   // 人員候選清單（settings_get 不帶，另呼叫 action=users 載入一次即快取）
function openSetMask(){
  $.get(OA_API, {action:'settings_get'}, function(r){
    if(!r||!r.ok){ alert((r&&r.error)||'設定載入失敗'); return; }
    SET.bands = r.bands||[]; SET.rules = r.rules||[]; SET.fallback = r.fallback||'single';
    SET.alert = r.alert || {}; SET.defaults = r.defaults;
    if(r.csrf) OA_CSRF = r.csrf;
    $('#setFallback').val(SET.fallback); $('#setErr').text('');
    if(r.kpi_info){
      $('#setKpiName').text(r.kpi_info.name||'月份受訂目標達成金額'); $('#setKpiWarn').text('');
    } else {
      $('#setKpiName').text('月份受訂目標達成金額');
      $('#setKpiWarn').text('（本年度找不到這項 KPI 的設定，未達標提醒會一直顯示「沒有資料」，請先到 KPI 關鍵績效指標頁設定）');
    }
    renderSetBand(); renderSetRule(); renderAlertSet();
    if(!MA_USERS.length){
      $.get(OA_API, {action:'users'}, function(ur){
        if(ur && ur.ok){ MA_USERS = ur.users||[]; fillMaUserPick(); }
      }, 'json');
    } else fillMaUserPick();
    openMask('setMask');
  }, 'json');
}
$('#btnSetting').on('click', openSetMask);
$('#btnMaSetting').on('click', openSetMask);
function renderAlertSet(){
  var a = SET.alert;
  $('#setKpiMonths').val(a.kpi_alert_months);
  $('#setMaEnabled').prop('checked', !!a.ma_enabled);
  $('#setMaMonths').val(a.ma_months);
  $('#setMaCons').val(a.ma_consecutive);
  $('#setMaMode').val(a.ma_threshold_mode);
  $('#setMaValue').val(a.ma_threshold_value||0).prop('disabled', a.ma_threshold_mode!=='manual');
  $('#setMaCov').val(a.ma_min_coverage);
  renderMaUserChips();
}
function fillMaUserPick(){
  var picked = {}; (SET.alert.ma_notify_users||[]).forEach(function(id){ picked[id]=1; });
  var h = '<option value="">請選擇人員…</option>';
  MA_USERS.forEach(function(u){
    if(picked[u.id]) return;   // 已加入的不再出現在候選裡，避免選了又疊一次
    h += '<option value="'+u.id+'">'+esc(u.dept)+'　'+esc(u.post)+'　'+esc(u.name)
       + (u.note? '（'+esc(u.note)+'）':'') + '</option>';
  });
  $('#setMaUserPick').html(h);
}
function renderMaUserChips(){
  var byId = {}; MA_USERS.forEach(function(u){ byId[u.id]=u; });
  var ids = SET.alert.ma_notify_users || [];
  var h = ids.length ? '' : '<span style="font-size:12px;color:var(--muted);">尚未指定任何人員——啟用自動通知前必須先加至少一位</span>';
  ids.forEach(function(id){
    var u = byId[id];
    h += '<span class="chip">'+(u?esc(u.name):('#'+id))+'<i class="fa fa-times" data-id="'+id+'"></i></span>';
  });
  $('#setMaUsers').html(h);
}
$('#setMaUserAdd').on('click', function(){
  var id = parseInt($('#setMaUserPick').val(),10);
  if(!id) return;
  SET.alert.ma_notify_users = SET.alert.ma_notify_users || [];
  if(SET.alert.ma_notify_users.indexOf(id) < 0) SET.alert.ma_notify_users.push(id);
  fillMaUserPick(); renderMaUserChips(); validateSet();
});
$(document).on('click', '#setMaUsers .fa-times', function(){
  var id = parseInt($(this).data('id'),10);
  SET.alert.ma_notify_users = (SET.alert.ma_notify_users||[]).filter(function(x){ return x!==id; });
  fillMaUserPick(); renderMaUserChips(); validateSet();
});
$('#setKpiMonths').on('input change', function(){ SET.alert.kpi_alert_months = parseInt($(this).val(),10)||3; });
$('#setMaEnabled').on('change', function(){ SET.alert.ma_enabled = $(this).is(':checked')?1:0; validateSet(); });
$('#setMaMonths').on('input change', function(){ SET.alert.ma_months = parseInt($(this).val(),10)||3; validateSet(); });
$('#setMaCons').on('input change', function(){ SET.alert.ma_consecutive = parseInt($(this).val(),10)||2; validateSet(); });
$('#setMaMode').on('change', function(){
  SET.alert.ma_threshold_mode = $(this).val();
  $('#setMaValue').prop('disabled', SET.alert.ma_threshold_mode!=='manual');
  validateSet();
});
$('#setMaValue').on('input change', function(){ SET.alert.ma_threshold_value = parseFloat($(this).val())||0; validateSet(); });
$('#setMaCov').on('input change', function(){ SET.alert.ma_min_coverage = parseInt($(this).val(),10)||60; });
$('#btnMaPreview').on('click', function(){
  $('#maPreviewOut').text('試算中…');
  $.get(OA_API, {action:'ma_preview', months:SET.alert.ma_months, consecutive:SET.alert.ma_consecutive,
                 min_coverage:SET.alert.ma_min_coverage}, function(r){
    if(!r||!r.ok){ $('#maPreviewOut').text((r&&r.error)||'試算失敗'); return; }
    var ma = r.ma, last6 = (ma.series||[]).slice(-6);
    var txt = '連續 '+ma.streak+' 個月低於安全水平（'+(ma.hit?'達到':'未達到')+'通知條件，需連續 '+ma.need+' 個月）。最近幾期：'
      + last6.map(function(s){ return s.ym+'＝'+(s.unreliable?'資料不足':(s.below?'低於':'正常')); }).join('、');
    $('#maPreviewOut').html(esc(txt));
  }, 'json').fail(function(){ $('#maPreviewOut').text('試算失敗'); });
});
function renderSetBand(){
  var h='';
  SET.bands.forEach(function(b,i){
    h += '<tr><td><input class="rm-in sb-f" data-i="'+i+'" data-k="label" value="'+esc(b.label)+'"></td>'
       + '<td><input type="number" class="rm-in sb-f" data-i="'+i+'" data-k="min" value="'+(b.min==null?'':b.min)+'"></td>'
       + '<td><input type="number" class="rm-in sb-f" data-i="'+i+'" data-k="max" value="'+(b.max==null?'':b.max)+'" data-eg-hint="留空＝以上無限"></td>'
       + '<td style="text-align:center;"><button class="btn btn-xs btn-default" onclick="setBandDel('+i+')">×</button></td></tr>';
  });
  $('#tblSetBand tbody').html(h);
}
function renderSetRule(){
  var h='';
  SET.rules.forEach(function(r,i){
    h += '<tr><td><input class="rm-in sr-f" data-i="'+i+'" data-k="label" value="'+esc(r.label)+'"></td>'
       + '<td><input class="rm-in sr-f" data-i="'+i+'" data-k="kw" value="'+esc(r.kw)+'" data-eg-hint="逗號=都要含；直線|=任一即可"></td>'
       + '<td><select class="sr-f" data-i="'+i+'" data-k="cls" style="width:100%;font-size:12px;">'
       + '<option value="full"'+(r.cls==='full'?' selected':'')+'>全製</option>'
       + '<option value="single"'+(r.cls==='single'?' selected':'')+'>單製</option></select></td>'
       + '<td style="text-align:center;"><button class="btn btn-xs btn-default" onclick="setRuleDel('+i+')">×</button></td></tr>';
  });
  $('#tblSetRule tbody').html(h);
}
$(document).on('input change','.sb-f', function(){
  var i=parseInt($(this).data('i'),10), k=$(this).data('k'), v=$(this).val();
  if(k!=='label') v = (String(v).trim()==='') ? null : parseInt(v,10);
  SET.bands[i][k]=v; validateSet();
});
$(document).on('input change','.sr-f', function(){
  var i=parseInt($(this).data('i'),10); SET.rules[i][$(this).data('k')]=$(this).val(); validateSet();
});
$('#setFallback').on('change', function(){ SET.fallback=$(this).val(); });
/* data-eg-row-add/​del：共用檔 eg_input_rules.js 的「末列↓加一列、空白末列↑移除」會呼叫這兩支 */
function setBandAdd(){ var last=SET.bands[SET.bands.length-1];
  var min = last && last.max!=null ? (parseInt(last.max,10)+1) : null;
  SET.bands.push({label:'', min:min, max:null}); renderSetBand(); validateSet(); }
/* 注意：共用檔 eg_input_rules.js 的 data-eg-row-del 是**不帶參數**呼叫，語意是「移除最後一列」。
   不補這個預設值的話 splice(undefined,1) 會刪掉第 0 列＝按 ↑ 收回空白列時刪錯人。 */
function setBandDel(i){ if(SET.bands.length<=1) return;
  if(typeof i!=='number') i=SET.bands.length-1;
  SET.bands.splice(i,1); renderSetBand(); validateSet(); }
function setBandReset(){ SET.bands = JSON.parse(JSON.stringify(SET.defaults.bands)); renderSetBand(); validateSet(); }
function setRuleAdd(){ SET.rules.push({label:'', kw:'', cls:'full'}); renderSetRule(); validateSet(); }
function setRuleDel(i){ if(SET.rules.length<=1) return;
  if(typeof i!=='number') i=SET.rules.length-1;        /* 同上：不帶參數＝移除最後一列 */
  SET.rules.splice(i,1); renderSetRule(); validateSet(); }
function setRuleReset(){ SET.rules = JSON.parse(JSON.stringify(SET.defaults.rules)); renderSetRule(); validateSet(); }
/* 前端即時驗證（後端 oa_qty_bands_norm／oa_proc_rules_norm 會用同一套規則再擋一次＝鐵律8） */
function validateSet(){
  var e=[], bs = SET.bands.slice().filter(function(b){ return !(String(b.label||'').trim()==='' && b.min==null && b.max==null); });
  bs.sort(function(a,b){ return (a.min||0)-(b.min||0); });
  bs.forEach(function(b,i){
    if((b.min||0) < 0) e.push('數量區間第 '+(i+1)+' 列：下限不可小於 0');
    if(b.max!=null && b.max < (b.min||0)) e.push('數量區間第 '+(i+1)+' 列：上限不可小於下限');
    if(i>0){
      var pm = bs[i-1].max;
      if(pm==null) e.push('「'+(bs[i-1].label||'前一列')+'」沒有上限，後面不可以再有區間');
      else if((b.min||0) <= pm) e.push('「'+(bs[i-1].label||'')+'」與「'+(b.label||'')+'」的範圍重疊');
    }
  });
  if(!bs.length) e.push('至少要有一個數量區間');
  if(!SET.rules.filter(function(r){ return String(r.kw||'').trim()!==''; }).length) e.push('至少要有一條關鍵字規則');
  if(SET.alert){
    var a = SET.alert;
    if(a.ma_threshold_mode==='manual' && !(parseFloat(a.ma_threshold_value)>0)) e.push('安全水平選「自訂金額」時，金額必須大於 0');
    if(a.ma_enabled && !(a.ma_notify_users && a.ma_notify_users.length)) e.push('啟用自動通知時，一定要指定至少一位收通知的人員');
  }
  $('#setErr').text(e.join('\n'));
  $('#btnSetSave').prop('disabled', e.length>0);
  return e.length===0;
}
$('#btnSetSave').on('click', function(){
  if(!validateSet()) return;
  $.post(OA_API, {action:'settings_save', csrf:OA_CSRF, fallback:SET.fallback,
                  bands:JSON.stringify(SET.bands), rules:JSON.stringify(SET.rules),
                  alert:JSON.stringify(SET.alert||{})}, function(r){
    if(!r||!r.ok){ $('#setErr').text((r&&r.error)||'儲存失敗'); return; }
    closeMask('setMask'); load();
  }, 'json').fail(function(x){
    var msg = '儲存失敗（HTTP '+x.status+'）';
    try { var j = JSON.parse(x.responseText); if(j && j.error) msg = j.error; } catch(e){}
    $('#setErr').text(msg);
  });
});
<?php endif; ?>

<?php if ($canView): ?>
fillIdx();
load();
<?php endif; ?>
</script>
</body>
</html>
