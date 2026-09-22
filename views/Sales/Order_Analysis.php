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
.nav-jump { display:flex; gap:6px; flex-wrap:wrap; margin-bottom:10px; }
.nav-jump a { font-size:12px; border:1px solid var(--line); background:#fff; color:#6B4423;
              border-radius:14px; padding:3px 12px; text-decoration:none; }
.nav-jump a:hover { background:var(--sand); }
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

  <div id="noteBar"></div>
  <div class="nav-jump">
    <a href="#secTrend">訂單趨勢</a><a href="#secNew">新訂單（新料號）</a><a href="#secProc">全製／單製</a>
    <a href="#secBand">數量區間</a><a href="#secClient">客戶比較</a><a href="#secRank">客戶增減排名</a><a href="#secPart">受訂料號排名</a>
  </div>

  <div id="kpiRow" class="kpi-row"></div>

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
  <div class="m-win" style="width:820px;">
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
      <div class="err-txt" id="setErr"></div>
    </div>
    <div class="m-foot">
      <button class="btn btn-default" data-close="setMask">取消</button>
      <button class="btn btn-warm" id="btnSetSave"><i class="fa fa-save"></i> 儲存設定</button>
    </div>
  </div>
</div>
<?php endif; ?>

<script src="../../resource/js/jquery.min.js"></script>
<script src="../../resource/js/bootstrap.min.js"></script>
<script src="../../resource/js/fastclick.js"></script>
<script src="../../resource/js/nprogress.js"></script>
<script src="../../resource/js/custom.min.js"></script>
<script src="../../code/highcharts.js"></script>
<!-- 日期顯示一律走共用檔（ai-rules/20：YYYY.MM.DD） -->
<script src="../../resource/js/eg_date_fmt.js?v=<?= @filemtime(__DIR__.'/../../resource/js/eg_date_fmt.js') ?>"></script>
<!-- 輸入欄位共用規則（雙擊清空／聚焦全選／Enter 跳欄／可增列表格／下拉打字篩選） -->
<script src="../../resource/js/eg_input_rules.js?v=<?= @filemtime(__DIR__.'/../../resource/js/eg_input_rules.js') ?>"></script>
<script>
/* 側欄：CSS 先藏、這裡再顯示（鐵律6，兩者必須成對） */
$(document).ready(function(){ $('#sidebar-menu').css('visibility','visible'); });

var OA_API  = '../../src/store/OrderAnalysis_API.php';
var OA_CSRF = '<?= oaEsc($CSRF) ?>';
var CAN_SET = <?= $canSet ? 'true' : 'false' ?>;

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
    renderNote(); renderKpi(); renderTrend(); renderNew(); renderProc(); renderBand();
    renderClient(); renderRank(); renderParts();
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
    h += '<tr><td>'+esc(p.pno)+'</td><td>'+esc(p.cname)+'</td><td>'+dispDate(p.first)+'</td>'
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
       + (c.flag==='new'?' <span class="badge-new">新客戶</span>':'')+'</td>'
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
  $('#rankHint').text('依「較'+m.cmp_label+'的增減'+metLabel(m.rank_metric)+'」排序（不是依百分比——只看 % 的話小客戶會永遠排在大客戶前面）');
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
    h += '<tr><td class="n">'+(i+1)+'</td><td>'+esc(p.pno)+'</td><td>'+esc(p.cname)+'</td>'
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
        (c.flag==='new'?'新客戶':(c.flag==='lost'?'本期掛零':''))+(c.bad?' 未建主檔':''));
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

<?php if ($canView && $canSet): ?>
/* ── 設定 ───────────────────────────────────────────── */
var SET = { bands:[], rules:[], fallback:'single', defaults:null };
$('#btnSetting').on('click', function(){
  $.get(OA_API, {action:'settings_get'}, function(r){
    if(!r||!r.ok){ alert((r&&r.error)||'設定載入失敗'); return; }
    SET.bands = r.bands||[]; SET.rules = r.rules||[]; SET.fallback = r.fallback||'single'; SET.defaults = r.defaults;
    if(r.csrf) OA_CSRF = r.csrf;
    $('#setFallback').val(SET.fallback); $('#setErr').text('');
    renderSetBand(); renderSetRule(); openMask('setMask');
  }, 'json');
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
function setBandDel(i){ if(SET.bands.length<=1) return; SET.bands.splice(i,1); renderSetBand(); validateSet(); }
function setBandReset(){ SET.bands = JSON.parse(JSON.stringify(SET.defaults.bands)); renderSetBand(); validateSet(); }
function setRuleAdd(){ SET.rules.push({label:'', kw:'', cls:'full'}); renderSetRule(); validateSet(); }
function setRuleDel(i){ if(SET.rules.length<=1) return; SET.rules.splice(i,1); renderSetRule(); validateSet(); }
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
  $('#setErr').text(e.join('\n'));
  $('#btnSetSave').prop('disabled', e.length>0);
  return e.length===0;
}
$('#btnSetSave').on('click', function(){
  if(!validateSet()) return;
  $.post(OA_API, {action:'settings_save', csrf:OA_CSRF, fallback:SET.fallback,
                  bands:JSON.stringify(SET.bands), rules:JSON.stringify(SET.rules)}, function(r){
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
