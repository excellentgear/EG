<?php
/**
 * 各單位負荷分析（views/ADM/unit_load.php）
 * 第四階段：頁面骨架＋總覽分頁＋設定跳窗。五個單位（設計課/業務課/生管/生產課/品管）各自的
 * 詳細資料分頁本階段先留空殼，內容留給下一階段補。
 *
 * 資料與設定一律呼叫 src/store/UnitLoad_API.php；計算唯一實作在 src/common/unit_load_lib.php。
 * 本頁不自己算任何統計數字，只負責顯示與互動。
 */
ini_set('session.gc_maxlifetime', 43200);
session_set_cookie_params(43200);
session_start();
if (!isset($_SESSION['userName'])) {
    $_SESSION['lastpage'] = "../../views/ADM/unit_load.php";
    header("Location:../../index.php");
    exit;
}
include_once '../../src/common/_config.php';
include_once '../../src/common/DBConnection.php';
include_once '../../src/common/unit_load_lib.php';
include_once '../../src/common/order_analysis_lib.php';
include_once '../../src/common/role_features_helper.php';

$db  = (new DBConnection())->getPDO();
$uid = (int)($_SESSION['id'] ?? 0);

$feat     = rf_load_user_features_all($db, $uid);
$isAdmin  = in_array('all', $feat, true);
$canAdmin = $isAdmin || in_array('unit_load_admin', $feat, true);
$canView  = $canAdmin || in_array('unit_load_view', $feat, true);

if (empty($_SESSION['ul_csrf'])) $_SESSION['ul_csrf'] = bin2hex(random_bytes(16));
$CSRF = $_SESSION['ul_csrf'];

// 年度清單：本模組 API 沒有單獨的 years action，借用訂單分析既有的 oa_years()（依 order_track
// 下單日反推），這是全站期間篩選最常見的年度來源，不另外重寫一套查詢。
$years    = oa_years($db);
$thisYear = (int)date('Y');
$defYear  = in_array($thisYear, $years, true) ? $thisYear : (int)$years[0];
$roleLabel = $canAdmin ? '管理員' : ($canView ? '各單位負荷分析（檢視）' : '無權限');

function ulEsc($v) { return htmlspecialchars((string)$v, ENT_QUOTES, 'UTF-8'); }

// 單位鍵與對照標籤：鍵一律呼叫 ul_unit_keys()（唯一登記處），中文標籤是本模組固定的六個單位。
// 'packing'（包裝）2026-10-07 新增為獨立單位，人員範圍實務上多為倉管組，但判定是否為
// 包裝工作一律看製程本身，見 unit_load_lib.php 的 ul_packing_by_person() 函式註解。
$UNIT_KEYS = ul_unit_keys();
$UNIT_LABELS = ['design' => '設計', 'sales' => '業務', 'pm' => '生管', 'prod' => '生產', 'qc' => '品管', 'packing' => '包裝'];
$UNIT_ICONS  = ['design' => 'fa-pencil', 'sales' => 'fa-handshake-o', 'pm' => 'fa-sitemap', 'prod' => 'fa-industry', 'qc' => 'fa-check-square-o', 'packing' => 'fa-cube'];
?>
<!DOCTYPE html>
<html lang="zh-Hant">
<head>
<meta charset="utf-8">
<meta http-equiv="X-UA-Compatible" content="IE=edge">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>各單位負荷分析</title>
<link href="../../resource/css/bootstrap.css" rel="stylesheet">
<link href="../../resource/css/font-awesome.css" rel="stylesheet">
<link href="../../resource/css/nprogress.css" rel="stylesheet">
<link href="../../resource/css/custom.css" rel="stylesheet">
<style>
/* 側欄：CSS 先藏、ready 時再顯示（鐵律6，兩者必須成對） */
#sidebar-menu { visibility: hidden; }
:root{ --ink:#3C4A2E; --cream:#FAFBF4; --sand:#E7EFD8; --green:#8FA86B; --green-d:#5E7A3D;
       --coral:#DD5138; --line:#D8E2C4; --muted:#7C8F66; --overload-bg:#F9DEDC; --overload-text:#8B2E22; }
body { background:#F3F6EC; }
/* .right_col 第一個子元素一律 clear:both（鐵律6：頂欄高度0且浮動溢出，會壓成寬度0） */
.right_col .page-title { margin:8px 0 4px; overflow:hidden; clear:both; }
.page-title h3 { color:var(--ink); margin:0; display:flex; align-items:center; gap:10px; flex-wrap:wrap; font-size:22px; }
.page-help-btn { height:30px; font-size:13px; padding:0 12px; border:1px solid var(--green-d);
                 border-radius:15px; background:#fff; color:var(--green-d); }
.page-help-btn:hover { background:var(--green-d); color:#fff; }
@media print { .page-help-btn, .ul-home-btn { display:none !important; } }
.role-tag { font-size:12px; background:var(--sand); color:var(--ink); border-radius:10px; padding:2px 10px; font-weight:normal; }
.ul-home-btn { font-weight:600; }
.ul-panel { background:#fff; border:1px solid var(--line); border-radius:8px; padding:12px 14px; margin-bottom:12px; }
.btn-ul { background:var(--green); border:1px solid var(--green-d); color:#fff; font-weight:bold; }
.btn-ul:hover,.btn-ul:focus { background:var(--green-d); color:#fff; }
.btn-ul-o { background:#fff; border:1px solid var(--green-d); color:var(--green-d); }
.btn-ul-o:hover { background:var(--sand); color:var(--ink); }
.ul-bar { display:flex; gap:8px; align-items:center; flex-wrap:wrap; }
.ul-bar label { margin:0; font-size:12px; color:var(--ink); font-weight:600; }

/* 六個單位分頁 */
.ul-tabs { display:flex; gap:8px; flex-wrap:wrap; margin-bottom:12px; }
.ul-tab-btn { border:1px solid var(--green-d); background:#fff; color:var(--green-d); font-weight:600;
              font-size:13px; padding:7px 16px; border-radius:16px; cursor:pointer; }
.ul-tab-btn:hover { background:var(--sand); }
.ul-tab-btn.active { background:var(--green-d); color:#fff; }

/* KPI 卡 */
.kpi-row { display:flex; gap:10px; flex-wrap:wrap; margin-bottom:12px; }
.kpi-card { flex:1 1 220px; min-width:220px; background:#fff; border:1px solid var(--line);
            border-top:3px solid var(--green); border-radius:8px; padding:10px 12px; }
.kpi-card.ul-overload { background:var(--overload-bg); border-top-color:var(--overload-text); border-color:#EFC2BD; }
.kpi-bad-tag { font-size:11px; color:var(--overload-text); font-weight:700; margin-bottom:4px; }
.kpi-bad-tag i { margin-right:3px; }
.k-lab { font-size:13px; color:var(--ink); font-weight:700; margin-bottom:6px; }
.k-ppl { font-size:11px; color:var(--muted); font-weight:normal; }
.k-metric { display:flex; justify-content:space-between; align-items:baseline; gap:6px; font-size:12.5px; padding:2px 0; }
.k-metric-lab { color:var(--muted); }
.k-metric-val { font-weight:700; color:var(--ink); font-variant-numeric:tabular-nums; }
.k-more { margin-top:6px; text-align:right; }
.k-more a { font-size:11.5px; color:var(--green-d); cursor:pointer; }
.up   { color:#2E7D32; font-weight:700; }
.down { color:var(--coral); font-weight:700; }
.flat { color:var(--muted); }

/* 區塊 */
.sec { background:#fff; border:1px solid var(--line); border-radius:8px; padding:12px 14px; margin-bottom:14px; }
.sec h4 { margin:0 0 4px; font-size:16px; color:var(--ink); font-weight:700;
          display:flex; align-items:center; gap:8px; flex-wrap:wrap; }
.sec h4 .hint { font-size:11px; color:var(--muted); font-weight:normal; }

/* 自動分析清單：2026-10-07 改成依單位分組，每組一個小標題 */
.ins-group { margin-bottom:12px; }
.ins-group:last-child { margin-bottom:0; }
.ins-group-h { font-size:13px; font-weight:700; color:var(--green-d); margin:0 0 6px;
               display:flex; align-items:center; gap:6px; }
.ins-item { padding:8px 10px; border-left:4px solid var(--green); background:#F6F9F0;
            border-radius:4px; margin-bottom:6px; font-size:12.5px; line-height:1.6; }
.ins-item.bad { border-left-color:var(--coral); background:#FDF1EF; }
.ins-item.warn { border-left-color:var(--green-d); background:#F0F4E6; }
.ins-item b { color:var(--ink); }

/* 2026-10-07：獨立的「部門負荷總表」（.ul-load-row/.ul-load-card/.ullc-*）已移除，過重理由
   改併進 .kpi-card 本身（見 .kpi-reasons），這裡不再需要那組 CSS。 */

/* 趨勢分析：粒度切換鈕 */
.ul-trend-gran.active { background:var(--green-d); color:#fff; }

/* 分頁容器（總覽／設計課／業務課／生管已有內容，生產課／品管本階段先放空殼） */
.ul-tab-pane { }
.ul-placeholder { background:#fff; border:1px dashed var(--line); border-radius:8px; padding:40px 20px;
                  text-align:center; color:var(--muted); font-size:13px; }
.ul-placeholder i { font-size:26px; display:block; margin-bottom:10px; color:var(--green); }

/* 單位詳細分頁共用：小型統計卡數字／小卡（審圖人次等）／表格內逐格超門檻標紅 */
.k-val { font-size:21px; font-weight:700; color:var(--ink); font-variant-numeric:tabular-nums; }
.k-cmp { font-size:11.5px; color:var(--muted); margin-top:2px; }
.kpi-card.ul-mini { flex:0 0 190px; min-width:160px; }
.ul-cell-bad { background:var(--overload-bg); color:var(--overload-text); font-weight:700; }
.ul-unit-note { color:var(--coral); border:1px solid #EFC2BD; background:var(--overload-bg);
                 border-radius:6px; padding:8px 12px; font-size:12.5px; margin-bottom:12px; }

/* 品管「目前待驗佇列」四欄網格（原本單欄直向列到很長，改成一次看到更多製程） */
.ul-pq-grid { display:grid; grid-template-columns:repeat(4,1fr); gap:8px; }
.ul-pq-item { border:1px solid var(--line); border-radius:6px; padding:8px 10px; background:#F6F9F0; }
.ul-pq-item.ul-overload { background:var(--overload-bg); border-color:#EFC2BD; }
.ul-pq-name { font-size:12.5px; font-weight:700; color:var(--ink); margin-bottom:4px;
              overflow:hidden; text-overflow:ellipsis; white-space:nowrap; }
.ul-pq-row { display:flex; justify-content:space-between; font-size:11.5px; color:var(--muted); }
.ul-pq-row b { color:var(--ink); font-variant-numeric:tabular-nums; }
.ul-pq-item.ul-overload .ul-pq-row b { color:var(--overload-text); }
@media (max-width: 1200px) { .ul-pq-grid { grid-template-columns:repeat(2,1fr); } }
@media (max-width: 700px)  { .ul-pq-grid { grid-template-columns:1fr; } }
.ul-empty-hint { color:var(--muted); font-size:12.5px; padding:16px 0; text-align:center; }

/* 生產課「製程大類負荷」四欄網格卡片（2026-10-07 改版，比照品管「目前待驗佇列」同一種
   緊湊版面，不要再用一列只有兩三個資料的傳統表格） */
.ul-pt-grid { display:grid; grid-template-columns:repeat(4,1fr); gap:8px; }
.ul-pt-item { border:1px solid var(--line); border-radius:6px; padding:8px 10px; background:#F6F9F0; }
.ul-pt-item.ul-overload { background:var(--overload-bg); border-color:#EFC2BD; }
.ul-pt-name { font-size:12.5px; font-weight:700; color:var(--ink); margin-bottom:4px;
              overflow:hidden; text-overflow:ellipsis; white-space:nowrap; }
.ul-pt-row { display:flex; justify-content:space-between; font-size:11.5px; color:var(--muted); }
.ul-pt-row b { color:var(--ink); font-variant-numeric:tabular-nums; }
.ul-pt-item.ul-overload .ul-pt-row b { color:var(--overload-text); }
@media (max-width: 1200px) { .ul-pt-grid { grid-template-columns:repeat(2,1fr); } }
@media (max-width: 700px)  { .ul-pt-grid { grid-template-columns:1fr; } }

/* 「未正式指派卻已報工」範例清單：三欄網格卡片（製令／製程／報工日期） */
.ul-pt3-grid { display:grid; grid-template-columns:repeat(3,1fr); gap:8px; }
.ul-pt3-item { border:1px solid var(--line); border-radius:6px; padding:8px 10px; background:#FDF6F0; }
.ul-pt3-row { display:flex; justify-content:space-between; font-size:11.5px; color:var(--muted); gap:6px; }
.ul-pt3-row b { color:var(--ink); font-variant-numeric:tabular-nums; text-align:right; overflow:hidden; text-overflow:ellipsis; }
@media (max-width: 1100px) { .ul-pt3-grid { grid-template-columns:repeat(2,1fr); } }
@media (max-width: 700px)  { .ul-pt3-grid { grid-template-columns:1fr; } }

/* KPI 卡內嵌的「過重理由」（2026-10-07 取代獨立的「部門負荷總表」，理由直接併進卡片本身） */
.kpi-reasons { font-size:11px; color:var(--overload-text); margin-top:6px; text-align:left; }
.kpi-reasons div { margin-bottom:2px; }
.kpi-reasons i { margin-right:2px; }

/* 逐人負荷明細表：標示時間基準的說明列（即時現況 vs 本期累積） */
.ul-time-basis-note { font-size:11px; color:var(--muted); margin-bottom:6px; }

/* 設定跳窗：部門範圍設定改多欄並列（2026-10-07，原本單欄往下排太占空間） */
.ul-dept-grid { display:grid; grid-template-columns:repeat(auto-fit, minmax(310px, 1fr)); gap:12px; }
.ul-excl-box, .ul-ptype-box { margin-top:8px; padding-top:8px; border-top:1px dashed var(--line); }
.ul-excl-h { font-size:11.5px; font-weight:700; color:var(--ink); margin-bottom:4px; }
.ul-excl-h .hint { font-weight:normal; color:var(--muted); }
.ul-excl-list label, .ul-ptype-list label { display:inline-block; margin:2px 10px 2px 0; }
.ul-nothr-lab { font-size:11px; color:var(--muted); font-weight:normal; margin-left:8px; white-space:nowrap; }

/* 使用說明 / 設定 跳窗（全站共用的 .m-mask/.m-win 疊層慣例） */
.m-mask { position:fixed; inset:0; background:rgba(60,74,46,.45); z-index:10300; display:none; }
.m-win  { background:#fff; border-radius:8px; width:760px; max-width:95vw; margin:4vh auto;
          max-height:92vh; display:flex; flex-direction:column; box-shadow:0 10px 40px rgba(0,0,0,.25); }
.m-head { padding:10px 14px; border-bottom:1px solid var(--line); font-weight:700; color:var(--ink);
          display:flex; align-items:center; }
.m-head .x { margin-left:auto; cursor:pointer; color:var(--muted); }
.m-body { padding:14px; overflow:auto; }
.m-foot { padding:10px 14px; border-top:1px solid var(--line); text-align:right; }
.help-doc h4 { color:var(--green-d); font-size:15px; margin:14px 0 6px; }
.help-doc li { margin-bottom:4px; line-height:1.7; }

/* 設定跳窗：部門範圍／門檻 兩個內部分頁 */
.ul-set-tabs { display:flex; gap:6px; margin-bottom:12px; }
.ul-set-tab { border:1px solid var(--green-d); background:#fff; color:var(--green-d); font-weight:600;
              font-size:12.5px; padding:5px 14px; border-radius:14px; cursor:pointer; }
.ul-set-tab.active { background:var(--green-d); color:#fff; }
.ul-set-pane { }
.ul-dept-unit { border:1px solid var(--line); border-radius:6px; padding:8px 10px; margin-bottom:10px; }
.ul-dept-unit-h { font-size:13px; font-weight:700; color:var(--ink); margin-bottom:6px; }
.ul-dept-ul { list-style:none; margin:0; padding-left:18px; }
.ul-dept-ul.ul-dept-root { padding-left:0; }
.ul-dept-li { margin:3px 0; }
.ul-dept-lab, .ul-sub-lab { font-weight:normal; cursor:pointer; margin-right:10px; font-size:12.5px; }
.ul-sub-lab { color:var(--muted); font-size:11.5px; }
.ul-thr-unit { border:1px solid var(--line); border-radius:6px; padding:8px 10px; margin-bottom:10px; }
.ul-thr-h { font-size:13px; font-weight:700; color:var(--ink); margin-bottom:6px; }
.ul-thr-row { display:flex; align-items:center; gap:8px; margin:4px 0; font-size:12.5px; }
.ul-thr-row label { flex:1 1 auto; margin:0; color:var(--ink); }
.ul-thr-row input { width:110px; }
.ul-thr-def { color:var(--muted); font-size:11px; }
</style>
</head>
<!-- 側欄載入時維持收合（全站慣例 nav-sm） -->
<body class="nav-sm">
<div class="container body"><div class="main_container">
<?php include '../partPage/sideAndTopBarMenu.html'; ?>
<div class="right_col" role="main">

  <div class="page-title">
    <h3><i class="fa fa-balance-scale" style="color:var(--green-d);"></i> 各單位負荷分析
      <span class="role-tag">目前身分：<?= ulEsc($roleLabel) ?></span>
      <a href="../admin/dashboard.php" class="btn btn-xs btn-ul-o ul-home-btn"><i class="fa fa-home"></i> 回首頁</a>
      <button id="btnPageHelp" class="page-help-btn" style="margin-left:auto;"><i class="fa fa-question-circle"></i> 使用說明</button>
    </h3>
  </div>

<?php if (!$canView): ?>
  <div class="ul-panel" style="color:var(--coral);">
    您沒有各單位負荷分析的檢視權限。請洽管理員開通 <code>unit_load_view</code>（檢視）或
    <code>unit_load_admin</code>（管理，含設定）。
  </div>
<?php else: ?>

  <!-- ── 期間篩選列 ─────────────────────────────────────── -->
  <div class="ul-panel">
    <div class="ul-bar">
      <label>年度</label>
      <select id="fYear" class="form-control input-sm" style="width:92px;">
        <?php foreach ($years as $y): ?>
          <option value="<?= (int)$y ?>" <?= $y === $defYear ? 'selected' : '' ?>><?= (int)$y ?></option>
        <?php endforeach; ?>
      </select>
      <label>期間</label>
      <select id="fGran" class="form-control input-sm" style="width:82px;">
        <?php foreach (oa_grans() as $k => $v): ?>
          <option value="<?= ulEsc($k) ?>" <?= $k === 'month' ? 'selected' : '' ?>><?= ulEsc($v) ?></option>
        <?php endforeach; ?>
      </select>
      <select id="fIdx" class="form-control input-sm" style="width:118px;"></select>
      <label>比較基準</label>
      <select id="fCmp" class="form-control input-sm" style="width:110px;">
        <?php foreach (oa_compares() as $k => $v): ?>
          <option value="<?= ulEsc($k) ?>"><?= ulEsc($v) ?></option>
        <?php endforeach; ?>
      </select>
      <span class="sec-tools" style="margin-left:auto;display:flex;gap:6px;">
        <button class="btn btn-sm btn-ul" id="btnReload"><i class="fa fa-refresh"></i> 重新計算</button>
        <?php if ($canAdmin): ?>
        <button class="btn btn-sm btn-ul-o" id="btnSetting"><i class="fa fa-cog"></i> 設定</button>
        <?php endif; ?>
      </span>
    </div>
  </div>

  <!-- ── 七個分頁：總覽／設計課／業務課／生管／生產課／品管／包裝 ──────── -->
  <div class="ul-tabs">
    <button type="button" class="ul-tab-btn active" data-tab="overview"><i class="fa fa-bar-chart"></i> 總覽</button>
    <?php foreach ($UNIT_KEYS as $k): ?>
    <button type="button" class="ul-tab-btn" data-tab="<?= ulEsc($k) ?>">
      <i class="fa <?= ulEsc($UNIT_ICONS[$k] ?? 'fa-circle') ?>"></i> <?= ulEsc($UNIT_LABELS[$k] ?? $k) ?>
    </button>
    <?php endforeach; ?>
  </div>

  <!-- ── 總覽分頁 ─────────────────────────────────────────── -->
  <div class="ul-tab-pane" id="tab-overview">

    <div id="noteBar"></div>

    <!-- 2026-10-07：「部門負荷總表」獨立區塊已移除，過重理由直接併進下方每張 KPI 卡片本身
         （以單位 KPI 卡為主），不再有兩處各顯示一份過重資訊。 -->
    <div id="kpiRow" class="kpi-row"></div>

    <div class="sec" id="secInsight">
      <h4><i class="fa fa-lightbulb-o" style="color:var(--coral);"></i> 自動分析
        <span class="hint">依單位分組，把各單位當前數字跟門檻與上一期比，每一條都附具體數字</span>
      </h4>
      <div id="insightList"></div>
    </div>

    <!-- 月／季趨勢分析：六個單位各自的累積型代表指標走勢（獨立於上方期間篩選） -->
    <div class="sec" id="secTrend">
      <h4><i class="fa fa-line-chart" style="color:var(--green-d);"></i> 趨勢分析
        <span class="hint">六個單位各自選一個「累積型」代表指標（現況快照型指標逐期都一樣，畫不出趨勢），滑鼠移到線上看圖例說明各代表什麼</span>
      </h4>
      <div class="ul-bar" style="margin-bottom:8px;">
        <label>粒度</label>
        <button type="button" class="btn btn-xs btn-ul-o ul-trend-gran active" data-gran="month">月</button>
        <button type="button" class="btn btn-xs btn-ul-o ul-trend-gran" data-gran="quarter">季</button>
        <label style="margin-left:10px;">往回看幾期</label>
        <select id="fTrendBuckets" class="form-control input-sm" style="width:72px;">
          <option value="6" selected>6</option>
          <option value="12">12</option>
        </select>
      </div>
      <div id="trendChart" style="height:360px;"></div>
      <div style="font-size:11px;color:var(--muted);margin-top:4px;">
        本期（最右側那一點，若當期尚未走完）數字會比較低，那是因為那一期還沒結束，不是真的下滑。
      </div>
    </div>

  </div>

  <!-- ── 設計課分頁 ─────────────────────────────────────── -->
  <div class="ul-tab-pane" id="tab-design" data-unit="design" style="display:none">
    <div id="dsgNote"></div>
    <div style="font-size:11px;color:var(--muted);margin-bottom:6px;">
      批圖中／新案件（含已處理／批圖中兩種子狀態）為<b>目前狀態</b>快照，不受上方期間篩選影響；
      已按審圖／已按轉生管／問題訂單數／平均出圖工作天為本期數字或現況統計，詳見各卡片說明。
    </div>
    <div id="dsgKpi" class="kpi-row"></div>

    <div class="sec">
      <h4><i class="fa fa-user-circle-o" style="color:var(--green-d);"></i> 審圖人統計
        <span class="hint">僅設計恰好 2 人時可判定（制度上按審圖視為「對方審圖」）</span></h4>
      <div id="dsgReviewer"></div>
    </div>

    <div class="sec">
      <h4><i class="fa fa-line-chart" style="color:var(--green-d);"></i> 每日完成數（已按轉生管）趨勢
        <span class="hint" id="dsgDailyGranNote"></span></h4>
      <div class="chart-box" id="dsgDailyChart" style="height:320px;"></div>
    </div>

    <div class="sec">
      <h4><i class="fa fa-tags" style="color:var(--green-d);"></i> 訂單標籤分布</h4>
      <div style="display:flex;gap:16px;flex-wrap:wrap;align-items:flex-start;">
        <div class="chart-box" id="dsgTagsChart" style="height:300px;flex:1 1 320px;min-width:280px;"></div>
        <div id="dsgTags" style="flex:1 1 280px;min-width:240px;"></div>
      </div>
    </div>

    <div class="sec">
      <h4><i class="fa fa-comments-o" style="color:var(--green-d);"></i> 設計備註問題與回覆工作天
        <span class="hint">開放問題總數為現況快照；平均回覆工作天為本期內完成回覆者</span></h4>
      <div id="dsgNoteStats"></div>
    </div>

    <div class="sec">
      <h4><i class="fa fa-table" style="color:var(--green-d);"></i> 逐人負荷明細</h4>
      <div class="ul-time-basis-note"><i class="fa fa-info-circle"></i> 標示＊的欄位為<b>即時現況</b>，不受上方期間篩選影響；其餘為<b>本期累積</b>。</div>
      <div class="table-responsive"><table class="table table-striped" id="dsgTable">
        <thead><tr><th>部門</th><th>職稱</th><th>姓名</th><th>批圖中＊</th><th>已按審圖</th><th>已按轉生管</th>
          <th>問題訂單數＊</th><th>平均出圖工作天</th></tr></thead>
        <tbody></tbody>
      </table></div>
    </div>
  </div>

  <!-- ── 業務課分頁 ─────────────────────────────────────── -->
  <div class="ul-tab-pane" id="tab-sales" data-unit="sales" style="display:none">
    <div id="salNote"></div>
    <div id="salKpi" class="kpi-row"></div>

    <div class="sec">
      <h4><i class="fa fa-table" style="color:var(--green-d);"></i> 逐人負荷明細</h4>
      <div class="ul-time-basis-note"><i class="fa fa-info-circle"></i> 標示＊的欄位為<b>即時現況</b>，不受上方期間篩選影響；其餘為<b>本期累積</b>。</div>
      <div class="table-responsive"><table class="table table-striped" id="salTable">
        <thead><tr><th>部門</th><th>職稱</th><th>姓名</th><th>報價單數</th><th>報價明細筆數</th>
          <th>訂單追蹤筆數</th><th>待回覆問題筆數＊</th></tr></thead>
        <tbody></tbody>
      </table></div>
    </div>
  </div>

  <!-- ── 生管分頁 ───────────────────────────────────────── -->
  <div class="ul-tab-pane" id="tab-pm" data-unit="pm" style="display:none">
    <div id="pmNote"></div>

    <div class="sec">
      <h4><i class="fa fa-sitemap" style="color:var(--green-d);"></i> 現況快照
        <span class="hint">目前狀態，非本期累積——除「待對帳」外，不受上方期間篩選影響</span></h4>
      <div id="pmKpi" class="kpi-row"></div>
    </div>

    <div class="sec">
      <div class="ul-empty-hint"><i class="fa fa-info-circle"></i>
        目前資料庫沒有記錄生管逐人負責製令的欄位（bom_ing 沒有「這張製令由哪個生管負責」這項資料），暫不提供逐人負荷。</div>
    </div>
  </div>

  <!-- ── 生產課分頁 ─────────────────────────────────────── -->
  <div class="ul-tab-pane" id="tab-prod" data-unit="prod" style="display:none">
    <div id="prodNote"></div>
    <div style="font-size:12px;color:var(--muted);margin-bottom:10px;">
      <i class="fa fa-info-circle"></i> 包裝負荷已獨立成一個分頁，請見「<a href="javascript:void(0)" class="ul-go-tab" data-tab="packing">包裝</a>」分頁。
    </div>

    <div class="sec">
      <h4><i class="fa fa-sitemap" style="color:var(--green-d);"></i> 製程大類負荷
        <span class="hint">目前進行中（尚未移轉）的製程逐大類統計，非本期累積，不受期間篩選影響；要列入哪些大類可在「設定」調整</span></h4>
      <div id="prodTypeGrid"></div>
    </div>

    <div class="sec">
      <h4><i class="fa fa-exclamation-triangle" style="color:var(--coral);"></i> 未正式指派卻已報工
        <span class="hint">本期內：報工對應的製程當時沒有被正式指派機台、或仍停在最初狀態（系統流程缺口）</span></h4>
      <div id="prodUntracked"></div>
    </div>

    <div class="sec">
      <h4><i class="fa fa-line-chart" style="color:var(--green-d);"></i> 每日報工加工數量</h4>
      <div class="chart-box" id="prodDailyChart" style="height:320px;"></div>
    </div>

    <div class="sec">
      <h4><i class="fa fa-wrench" style="color:var(--green-d);"></i> 架機與生產時間</h4>
      <div id="prodTimeKpi" class="kpi-row"></div>
      <div class="chart-box" id="prodSetupChart" style="height:280px;"></div>
    </div>

    <div class="sec">
      <h4><i class="fa fa-users" style="color:var(--green-d);"></i> 人均負荷</h4>
      <div id="prodPerCapita" class="kpi-row"></div>
    </div>
  </div>

  <!-- ── 包裝分頁（獨立單位，2026-10-07 新增）────────────── -->
  <div class="ul-tab-pane" id="tab-packing" data-unit="packing" style="display:none">
    <div id="packNote"></div>

    <div class="sec">
      <h4><i class="fa fa-cube" style="color:var(--green-d);"></i> 包裝負荷
        <span class="hint">待包裝筆數為現況快照，不受期間篩選影響；每日完成數／平均處理工作天為本期統計</span></h4>
      <div id="packKpi" class="kpi-row"></div>
      <div class="chart-box" id="packChart" style="height:280px;"></div>
      <div id="packExtremes"></div>
    </div>

    <div class="sec">
      <h4><i class="fa fa-table" style="color:var(--green-d);"></i> 逐人負荷明細</h4>
      <div class="ul-empty-hint"><i class="fa fa-info-circle"></i>
        目前無法追蹤包裝工作的逐人負荷（報工紀錄沒有記錄到實際執行包裝的倉管人員）。</div>
    </div>
  </div>

  <!-- ── 品管分頁 ───────────────────────────────────────── -->
  <div class="ul-tab-pane" id="tab-qc" data-unit="qc" style="display:none">
    <div id="qcNote"></div>

    <div class="sec">
      <h4><i class="fa fa-list-ol" style="color:var(--green-d);"></i> 目前待驗佇列
        <span class="hint">筆數為現況快照不受期間篩選影響；平均檢驗工作天數為本期已完成檢驗的歷史平均，受期間篩選影響</span></h4>
      <div id="qcPendingKpi" class="kpi-row"></div>
      <div id="qcPendingTable"></div>
    </div>

    <div class="sec">
      <h4><i class="fa fa-line-chart" style="color:var(--green-d);"></i> 每日檢驗項目數</h4>
      <div class="chart-box" id="qcDailyChart" style="height:320px;"></div>
    </div>

    <div class="sec">
      <h4><i class="fa fa-clock-o" style="color:var(--green-d);"></i> 待驗等待工作天
        <span class="hint">本期完成檢驗的製程，自進入待驗到驗完之間的工作天數</span></h4>
      <div id="qcWaitKpi" class="kpi-row"></div>
      <div id="qcWaitExtremes"></div>
    </div>

    <div class="sec">
      <h4><i class="fa fa-exclamation-circle" style="color:var(--green-d);"></i> 異常單與NG比例趨勢</h4>
      <div id="qcAbnormalKpi" class="kpi-row"></div>
      <div class="chart-box" id="qcAbnormalChart" style="height:320px;"></div>
    </div>

    <div class="sec">
      <h4><i class="fa fa-table" style="color:var(--green-d);"></i> 各人負荷明細</h4>
      <div class="ul-time-basis-note"><i class="fa fa-info-circle"></i> 以下欄位皆為<b>本期累積</b>（檢驗筆數／NG筆數）或<b>本期平均</b>（平均等待工作天），皆受上方期間篩選影響。</div>
      <div class="table-responsive"><table class="table table-striped" id="qcPersonTable">
        <thead><tr><th>部門</th><th>職稱</th><th>姓名</th><th>檢驗筆數</th><th>NG筆數</th><th>平均等待工作天</th></tr></thead>
        <tbody></tbody>
      </table></div>
    </div>

    <div class="sec">
      <h4><i class="fa fa-random" style="color:var(--green-d);"></i> 脫離正常待驗流程的補檢驗</h4>
      <div id="qcAdhoc"></div>
    </div>
  </div>

<?php endif; ?>
</div><!-- /right_col -->
</div></div>

<!-- ── 使用說明（鐵律7）──────────────────────────────── -->
<div class="m-mask" id="helpUseMask">
  <div class="m-win" style="width:820px;">
    <div class="m-head">各單位負荷分析 — 使用說明 <span class="x" data-close="helpUseMask">✕</span></div>
    <div class="m-body help-doc">
      <h4>功能說明（這一頁在回答什麼）</h4>
      <ul>
        <li>把<b>設計、業務、生管、生產、品管、包裝</b>六個單位目前的工作量各自整理成一張 KPI 卡與一頁明細，
            讓主管一眼看出哪個單位現在比較吃緊。</li>
        <li><b>總覽分頁</b>：「部門負荷總表」（六張小卡一眼看出誰過重）＋六張 KPI 卡＋依單位分組的自動分析
            （哪個單位的哪個指標偏高、跟上一期比有沒有變嚴重、連續上升或下滑）＋月／季趨勢分析折線圖。</li>
        <li>六個單位各自的<b>詳細資料分頁</b>：逐人明細、趨勢圖、各類清單，點分頁鈕或 KPI 卡的「查看明細 »」即可切入。
            品管多了「目前待驗佇列」（依製程分組的現況筆數＋本期平均檢驗工作天數）；設計課的「新案件」拆成
            「已處理」與「批圖中」兩種子狀態並附佔比。</li>
        <li><b>包裝分頁</b>：待包裝筆數／每日完成數／平均處理工作天／處理最久與最快前5筆／逐人明細。
            人員範圍實務上多為倉管組（隸屬資材課），但判定「這是不是包裝工作」一律看製程本身
            （製程名稱＝「包裝」），不是看是哪個部門的人報的。</li>
      </ul>
      <h4>操作步驟</h4>
      <ol>
        <li>選「年度 → 期間（月／季／半年／整年）→ 要看哪一期」，比較基準可切「上一期」或「去年同期」。</li>
        <li>按「重新計算」。</li>
        <li>按某張 KPI 卡或「部門負荷總表」小卡右下角的「查看明細 »」、或上方分頁鈕，切到該單位的詳細分頁。</li>
        <li>「趨勢分析」區塊可自行切「月／季」粒度與往回看幾期，獨立於上面的期間篩選，隨時可切換重畫。</li>
      </ol>
      <h4>重要行為／常見疑問</h4>
      <ul>
        <li><b>卡片背景變成紅色、並標「⚠ 負荷過重」</b>：代表那個單位目前有指標超過設定的門檻（「部門負荷
            總表」小卡會列出是哪個指標、現在多少、門檻多少；KPI 卡與自動分析用的是同一份判定，不會互相矛盾）。</li>
        <li><b>趨勢分析畫的不是「批圖中筆數」「委外加工中筆數」這些 KPI 卡上的數字</b>：那些是「現況快照」
            （查詢當下的狀態，不管選哪個月份都是同一個答案），逐期疊起來只會是一條水平線沒有意義；
            趨勢圖改用六個「累積型」代表指標（每個期間內真的發生了多少），滑鼠移到線上看圖例完整說明，
            例如設計課畫的是「本期轉生管筆數」、業務課是「本期開立報價單張數」。</li>
        <li><b>趨勢圖最右側那一點忽然變低</b>：如果那一期（例如本月）還沒走完，數字自然會比完整的一期少，
            不是真的下滑，自動分析的「連續兩期下滑」判斷也只會用已經走完的期別去判斷。</li>
        <li><b>生管沒有逐人明細</b>：製令資料表（bom_ing）沒有「這張製令由哪個生管負責」的欄位，所以生管只有
            全公司現況統計，逐人明細恆為空，這是資料結構的既有限制。</li>
        <li><b>包裝逐人明細常常是空的</b>：包裝報工（pm_process_daily_report）多半記在實際操作人員頭上，
            跟設定頁勾選的部門（實務上多為倉管組）常常對不起來；對不上時逐人明細如實顯示沒有資料，
            不勉強湊數字，整體統計（待包裝筆數／每日完成數／平均處理工作天）不受影響。</li>
        <li><b>各單位要算哪些人</b>由下方「設定」的「部門範圍設定」決定；還沒設定部門的單位，部分統計（需要
            逐人歸屬的）會顯示 0 或提示尚未設定，不影響不需要人員歸屬的現況統計。</li>
        <li><b>勾選部門的「含子部門」</b>：組織是樹狀的，勾了會連同底下所有子部門的人一起算；
            選到最上層部門（如董事長室）又勾含子部門，等於算進全公司，請依實際需要勾選。</li>
      </ul>
      <h4>設定入口</h4>
      <ul>
        <li>右上角「設定」（需 <code>unit_load_admin</code>）：<b>部門範圍設定</b>（每個單位各自勾選要計入哪些部門）、
            <b>門檻設定</b>（各單位各指標的「負荷過重」門檻數值，留預設值即可，有需要再調）。</li>
      </ul>
      <h4>權限角色</h4>
      <ul>
        <li><code>unit_load_view</code>：檢視本頁。</li>
        <li><code>unit_load_admin</code>：檢視本頁並可修改設定。</li>
        <li>系統管理員一律具備以上全部權限。</li>
      </ul>
    </div>
    <div class="m-foot"><button class="btn btn-ul" data-close="helpUseMask">關閉</button></div>
  </div>
</div>

<!-- ── 設定（部門範圍／門檻，僅 unit_load_admin）────────── -->
<div class="m-mask" id="setMask">
  <div class="m-win" style="width:760px;">
    <div class="m-head">各單位負荷分析 — 設定 <span class="x" data-close="setMask">✕</span></div>
    <div class="m-body">
      <div class="ul-set-tabs">
        <button type="button" class="ul-set-tab active" data-sub="dept">部門範圍設定</button>
        <button type="button" class="ul-set-tab" data-sub="thr">門檻設定</button>
      </div>
      <div class="ul-set-pane" id="setDeptPane"><div id="setDeptBody">載入中…</div></div>
      <div class="ul-set-pane" id="setThrPane" style="display:none;"><div id="setThrBody">載入中…</div></div>
    </div>
    <div class="m-foot">
      <button class="btn btn-default" data-close="setMask">取消</button>
      <button class="btn btn-ul" id="btnSetSave"><i class="fa fa-save"></i> 儲存設定</button>
    </div>
  </div>
</div>

<script src="../../resource/js/jquery.min.js"></script>
<script src="../../resource/js/bootstrap.min.js"></script>
<script src="../../resource/js/fastclick.js"></script>
<script src="../../resource/js/nprogress.js"></script>
<script src="../../resource/js/custom.min.js"></script>
<!-- Highcharts：本階段還沒畫圖，先載入供下一階段的五個單位詳細分頁使用（全站圖表統一走這套，不另引 Chart.js） -->
<script src="../../code/highcharts.js"></script>
<script src="../../code/modules/exporting.js"></script>
<!-- 輸入欄位共用規則（雙擊清空／聚焦全選／Enter 跳欄／下拉打字篩選），放在 custom.min.js 之後 -->
<script src="../../resource/js/eg_input_rules.js?v=<?= @filemtime(__DIR__.'/../../resource/js/eg_input_rules.js') ?>"></script>
<script>
/* 側欄：CSS 先藏、這裡再顯示（鐵律6，兩者必須成對） */
$(document).ready(function(){ $('#sidebar-menu').css('visibility','visible'); });

var UL_API    = '../../src/store/UnitLoad_API.php';
var UL_CSRF   = '<?= ulEsc($CSRF) ?>';
var CAN_ADMIN = <?= $canAdmin ? 'true' : 'false' ?>;
var UNIT_KEYS   = <?= json_encode($UNIT_KEYS, JSON_UNESCAPED_UNICODE) ?>;
var UNIT_LABELS = <?= json_encode($UNIT_LABELS, JSON_UNESCAPED_UNICODE) ?>;
var UNIT_ICONS  = <?= json_encode($UNIT_ICONS, JSON_UNESCAPED_UNICODE) ?>;

function esc(s){ return $('<div>').text(s==null?'':s).html(); }
function nf(n){ if(n===null||n===undefined) return '—'; n=Number(n)||0; return n.toLocaleString('en-US'); }
function nf1(n){ if(n===null||n===undefined) return '—'; n=Number(n)||0; return n.toLocaleString('en-US',{maximumFractionDigits:1}); }
function pct1(n){ if(n===null||n===undefined) return '—'; return (Math.round(Number(n)*1000)/10)+'%'; }
function openMask(id){ $('#'+id).show(); }
function closeMask(id){ $('#'+id).hide(); }
$(document).on('click','[data-close]', function(){ closeMask($(this).data('close')); });
$(document).on('click','.m-mask', function(e){ if(e.target===this) $(this).hide(); });
$('#btnPageHelp').on('click', function(){ openMask('helpUseMask'); });

function showToast(msg, kind){
  var c = kind==='error' ? '#DD5138' : '#5E7A3D';
  var $t = $('<div>').text(msg).css({
    position:'fixed', left:'50%', bottom:'26px', transform:'translateX(-50%)', zIndex:10500,
    background:c, color:'#fff', padding:'8px 18px', borderRadius:'20px', fontSize:'13px',
    boxShadow:'0 3px 10px rgba(0,0,0,.25)', opacity:0
  }).appendTo('body');
  $t.animate({opacity:1}, 150).delay(2200).animate({opacity:0}, 300, function(){ $t.remove(); });
}
function deltaHtml(cur, prev, fmt){
  fmt = fmt || nf;
  if(cur===null||cur===undefined||prev===null||prev===undefined) return '';
  var p = Number(prev)||0, c = Number(cur)||0, d = c - p;
  if(d===0) return '<span class="flat">持平</span>';
  var cls = d>0?'up':'down', sign = d>0?'+':'';
  return '<span class="'+cls+'">'+sign+fmt(d)+'</span>';
}

/* ── 期別下拉（跟著粒度變，與訂單分析同一套 GRAN_IDX 寫法）─────── */
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

/* ── 分頁切換（總覽／五個單位） ─────────────────────── */
/* 設計課／業務課／生管：第一次點開才呼叫對應的 data_* action（UNIT_LOADERS/UNIT_LOADED
   定義在下方「單位分頁與期間篩選串接」區塊）；已經載過的分頁直接顯示快取內容，不重打。 */
$(document).on('click', '.ul-tab-btn', function(){
  var t = $(this).data('tab');
  $('.ul-tab-btn').removeClass('active'); $(this).addClass('active');
  $('.ul-tab-pane').hide(); $('#tab-'+t).show();
  if (typeof UNIT_LOADERS !== 'undefined' && UNIT_LOADERS[t] && !UNIT_LOADED[t]) loadUnitTab(t);
});
$(document).on('click', '.ul-go-tab', function(){
  var t = $(this).data('tab');
  $('.ul-tab-btn[data-tab="'+t+'"]').trigger('click');
  window.scrollTo({top:0, behavior:'smooth'});
});

/* ── KPI 卡片 ───────────────────────────────────────── */
/* 2026-10-07：過重理由改直接併進卡片本身（取代原本獨立的「部門負荷總表」），新增
   第 6 參數 reasons（ul_unit_overload_check() 回傳的 reasons 陣列），bad 時才顯示。 */
function kpiCard(key, title, peopleCount, metricsHtml, bad, reasons){
  var icon = UNIT_ICONS[key] || 'fa-circle';
  var reasonsHtml = '';
  if (bad && reasons && reasons.length){
    reasonsHtml = '<div class="kpi-reasons">';
    reasons.forEach(function(r){
      reasonsHtml += '<div><i class="fa fa-caret-right"></i> '+esc(r.text || (r.label+' '+r.value+' > '+r.threshold))+'</div>';
    });
    reasonsHtml += '</div>';
  }
  return '<div class="kpi-card'+(bad?' ul-overload':'')+'" data-unit="'+key+'">'
       + '<div class="k-lab"><i class="fa '+icon+'"></i> '+esc(title)+'　<span class="k-ppl">('+nf(peopleCount)+' 人)</span></div>'
       + (bad ? '<div class="kpi-bad-tag"><i class="fa fa-exclamation-triangle"></i> 負荷過重</div>' : '')
       + metricsHtml
       + reasonsHtml
       + '<div class="k-more"><a href="javascript:void(0)" class="ul-go-tab" data-tab="'+key+'">查看明細 »</a></div>'
       + '</div>';
}
function metricLine(label, cur, cmp, fmt){
  fmt = fmt || nf;
  return '<div class="k-metric"><span class="k-metric-lab">'+esc(label)+'</span>'
       + '<span class="k-metric-val">'+fmt(cur)+' '+deltaHtml(cur, cmp, fmt)+'</span></div>';
}

/* ── 總覽：「負荷過重」改由後端 unit_overload 直接回傳逐單位的 overloaded 旗標與具體
   理由（2026-10-07 新增，取代原本「掃一遍 insights 標題文字反推」的暫時做法——前端
   不重算門檻邏輯，那永遠是 unit_load_lib.php 的 ul_is_overload()／ul_unit_overload_check()
   專責的事）。 */
function renderOverview(d){
  var ov = d.unit_overload || {};
  var badOf = function(k){ return !!(ov[k] && ov[k].overloaded); };
  var reasonsOf = function(k){ return (ov[k] && ov[k].reasons) || []; };

  var h = '';
  var dc = d.design.cur, dp = d.design.cmp;
  h += kpiCard('design', UNIT_LABELS.design, d.design.people_count,
       metricLine('批圖中', dc.drawing_wip, dp.drawing_wip)
     + metricLine('繪圖平均工作天', dc.avg_draw_workdays, dp.avg_draw_workdays, nf1)
     + metricLine('設計備註待回覆訂單', dc.issue_orders, dp.issue_orders),
     badOf('design'), reasonsOf('design'));

  var sc = d.sales.cur, sp = d.sales.cmp;
  h += kpiCard('sales', UNIT_LABELS.sales, d.sales.people_count,
       metricLine('本期報價單', sc.quote_count, sp.quote_count)
     + metricLine('待回覆問題', sc.open_issue_count, sp.open_issue_count),
     badOf('sales'), reasonsOf('sales'));

  var pc = d.pm.cur, pp = d.pm.cmp;
  h += kpiCard('pm', UNIT_LABELS.pm, d.pm.people_count,
       metricLine('委外加工中', pc.outsource_wip, pp.outsource_wip)
     + metricLine('廠內加工中', pc.internal_wip, pp.internal_wip)
     + metricLine('待對帳筆數', pc.pending_recon_lines, pp.pending_recon_lines),
     badOf('pm'), reasonsOf('pm'));

  var prod = d.prod;
  h += kpiCard('prod', UNIT_LABELS.prod, prod.people_count,
       metricLine('未指派機台', prod.unassigned_total, null)
     + metricLine('已指派機台', prod.assigned_total, null)
     + metricLine('未正式指派卻已報工', prod.untracked_total, null)
     + metricLine('平均架機時間（分）', prod.avg_setup_minutes, null, nf1),
     badOf('prod'), reasonsOf('prod'));

  var qc = d.qc;
  h += kpiCard('qc', UNIT_LABELS.qc, qc.people_count,
       metricLine('待驗平均等待工作天', qc.avg_wait_workdays, null, nf1)
     + metricLine('平均NG比例', qc.avg_ng_rate!=null ? qc.avg_ng_rate*100 : null, null, pct1)
     + metricLine('脫離流程補檢驗', qc.adhoc_total, null),
     badOf('qc'), reasonsOf('qc'));

  var pk = d.packing || {};
  h += kpiCard('packing', UNIT_LABELS.packing, pk.people_count,
       metricLine('待包裝筆數', pk.pending, null)
     + metricLine('平均處理工作天', pk.avg_workdays, null, nf1),
     badOf('packing'), reasonsOf('packing'));

  $('#kpiRow').html(h);
  renderInsights(d.insights || {});
}

/** 自動分析：依單位分組顯示（2026-10-07 改版），某單位沒有可講的內容時該分組直接不輸出 */
function renderInsights(grouped){
  grouped = grouped || {};
  var h = '';
  UNIT_KEYS.forEach(function(k){
    var list = grouped[k] || [];
    if (!list.length) return;
    h += '<div class="ins-group"><div class="ins-group-h"><i class="fa '
       + (UNIT_ICONS[k]||'fa-circle') + '"></i> ' + esc(UNIT_LABELS[k]||k) + '</div>';
    list.forEach(function(it){
      var lv = it.level || 'info';
      var icon = lv==='bad' ? 'fa-exclamation-triangle' : (lv==='warn' ? 'fa-flag' : 'fa-info-circle');
      h += '<div class="ins-item '+esc(lv)+'"><i class="fa '+icon+'"></i> <b>'+esc(it.title)+'</b>　'+esc(it.detail)+'</div>';
    });
    h += '</div>';
  });
  if (!h) h = '<div style="color:var(--muted);font-size:12.5px;">目前沒有需要特別留意的事項。</div>';
  $('#insightList').html(h);
}

/* ── 趨勢分析：月／季粒度的六個單位代表指標走勢，獨立於上方期間篩選自己的粒度／期數 */
var TREND_GRAN = 'month';
function loadTrend(){
  var params = {action:'trend', gran:TREND_GRAN, buckets:$('#fTrendBuckets').val()};
  $.get(UL_API, params, function(r){
    if(!r || !r.ok){ alert((r&&r.error)||'趨勢分析載入失敗'); return; }
    renderTrendChart(r);
  }, 'json').fail(function(xhr){
    var r = xhr.responseJSON;
    alert((r&&r.error) || ('趨勢分析載入失敗：HTTP '+xhr.status));
  });
}
/* 2026-10-07 第六步：六個單位同時畫在一張折線圖上，UL_PALETTE（綠色系）色階彼此太接近
   辨識不出來——這張圖專用色相跨度較大的暖色調色盤（橘／暗紅／金黃／咖啡棕／磚紅／
   赭黃），依 UNIT_KEYS 順序對應六個單位；仍維持暖色系大方向（ai-rules/10），不引入
   任何藍/青/紫這類冷色。只在這張圖覆寫 colors，其餘沿用 UL_PALETTE 的長條圖/圓餅圖
   不受影響。 */
var UL_TREND_PALETTE = ['#E8702A', '#8B2E22', '#D9A440', '#5E3A22', '#C14C3B', '#B8891F'];
function renderTrendChart(d){
  var labels = d.labels || [];
  var series = d.series || {};
  var metricLabels = d.metric_labels || {};
  var sArr = UNIT_KEYS.map(function(k){
    return { name: (UNIT_LABELS[k]||k) + '｜' + (metricLabels[k]||''), data: (series[k]||[]).map(Number) };
  });
  chart('trendChart', {
    chart: { type:'line', height:340 },
    colors: UL_TREND_PALETTE,
    xAxis: { categories: labels },
    yAxis: { title:{text:null}, allowDecimals:false, min:0 },
    tooltip: { shared:true },
    plotOptions: { series:{ marker:{ enabled:true, radius:3 } } },
    series: sArr
  });
}
$(document).on('click', '.ul-trend-gran', function(){
  $('.ul-trend-gran').removeClass('active');
  $(this).addClass('active');
  TREND_GRAN = $(this).data('gran');
  loadTrend();
});
$('#fTrendBuckets').on('change', loadTrend);

/* ══════════════════════════════════════════════════════════════════
 * 圖表共用：chart(id,opt) 與 sizeBox(id,px)（本階段建立，之後生產課／品管沿用，
 * 不要再各自寫一份）。比照 views/Sales/Order_Analysis.php 的同名函式風格，
 * 但改用本頁的暖淺綠色盤（UL_PALETTE），不要混用那邊的暖色盤——同一頁只能有
 * 一套配色語彙（ai-rules/10）。
 * ══════════════════════════════════════════════════════════════════ */
var UL_PALETTE = ['#8FA86B', '#5E7A3D', '#B9CB9E', '#3C4A2E', '#A9C47F', '#4B6134'];
var UL_CHARTS = {};
function chart(id, opt){
  try { if (UL_CHARTS[id]) { UL_CHARTS[id].destroy(); UL_CHARTS[id] = null; } } catch(e){}
  var el = document.getElementById(id);
  if (!el) return null;
  UL_CHARTS[id] = Highcharts.chart(id, $.extend(true, {
    chart: { backgroundColor:'#fff', style:{fontFamily:'"Microsoft JhengHei",sans-serif'}, spacing:[8,8,6,8] },
    title: { text:null }, credits: { enabled:false }, colors: UL_PALETTE,
    xAxis:  { lineColor:'#D8E2C4', tickColor:'#D8E2C4', labels:{ style:{fontSize:'11px', color:'#5E7A3D'} } },
    legend: { itemStyle:{fontSize:'11px', fontWeight:'400', color:'#5E7A3D'}, symbolRadius:3 }
  }, opt));
  return UL_CHARTS[id];
}
/* 條列很多的橫條圖要自己把「容器」撐高——只給 chart.height 不夠，.chart-box 的 CSS
   固定高度會把容器壓回去（ai-rules 已記過這個坑：Order_Analysis.php 排名圖曾因此
   整段看不到也不報錯）。生產課／品管若要畫長條排名圖，記得搭配呼叫這支。 */
function sizeBox(id, px){ var e = document.getElementById(id); if (e) e.style.height = Math.round(px) + 'px'; }

/* ══════════════════════════════════════════════════════════════════
 * 單位詳細分頁：共用小工具
 * ══════════════════════════════════════════════════════════════════ */
function periodParams(){
  return { year:$('#fYear').val(), gran:$('#fGran').val(), period_idx:$('#fIdx').val(), cmp:$('#fCmp').val() };
}
/** 小型統計卡（重用 .kpi-card 版面，但沒有「查看明細 »」連結——本身就在明細分頁上）；
    note（2026-10-07 新增）：可選的補充說明，印在數值下方小字（例如「基於 N 筆真正需要
    繪圖的案件」）。 */
function statTile(label, cur, cmp, cmpLabel, fmt, bad, note){
  fmt = fmt || nf;
  return '<div class="kpi-card'+(bad?' ul-overload':'')+'">'
       + '<div class="k-lab">'+esc(label)+'</div>'
       + '<div class="k-val">'+fmt(cur)+'</div>'
       + (cmp===undefined ? '' : '<div class="k-cmp">較'+esc(cmpLabel||'上一期')+'：'+deltaHtml(cur, cmp, fmt)+'</div>')
       + (note ? '<div class="k-cmp">'+esc(note)+'</div>' : '')
       + '</div>';
}
/** 設計課「新案件」卡片：ul_design_summary() 的 new_case 是結構（total/processed/
    processed_pct/in_progress/in_progress_pct），不能直接套 statTile()——另外拆出
    「已處理」「批圖中」兩種子狀態＋各自佔比，取代原本單一數字。目前狀態快照，不受
    期間篩選影響，故不比門檻（沒有對應的 overload 旗標）。 */
function newCaseTile(nc, cmpNc, cmpLabel){
  nc = nc || {total:0, processed:0, processed_pct:null, in_progress:0, in_progress_pct:null};
  var h = '<div class="kpi-card">'
        + '<div class="k-lab">新案件</div>'
        + '<div class="k-val">'+nf(nc.total)+'</div>';
  if (cmpNc !== undefined && cmpNc !== null) {
    h += '<div class="k-cmp">較'+esc(cmpLabel||'上一期')+'：'+deltaHtml(nc.total, cmpNc.total, nf)+'</div>';
  }
  h += '<div class="k-metric"><span class="k-metric-lab">已處理</span>'
     + '<span class="k-metric-val">'+nf(nc.processed)+'　'+pct1(nc.processed_pct)+'</span></div>'
     + '<div class="k-metric"><span class="k-metric-lab">批圖中</span>'
     + '<span class="k-metric-val">'+nf(nc.in_progress)+'　'+pct1(nc.in_progress_pct)+'</span></div>'
     + '</div>';
  return h;
}
/** 由 by_person（一人一列，含 user_id/name）查姓名；查不到就印 #id，不讓畫面空白 */
function nameOf(byPerson, uid){
  var hit = (byPerson||[]).filter(function(p){ return String(p.user_id) === String(uid); })[0];
  return hit ? hit.name : ('#' + uid);
}
function emptyHint(msg){ return '<div class="ul-empty-hint"><i class="fa fa-info-circle"></i> '+esc(msg)+'</div>'; }

/* 門檻值：settings_get 任何有檢視權的人都能讀（本頁 API 沒有在這個 action 上加 canAdmin 限制），
   用來給「逐人負荷明細表」逐格判斷要不要標紅——只取數字，不在前端重新發明判斷邏輯：
   design/sales/pm 這三組指標全部是「數字越大越吃緊」（沒有 _rate 結尾的比率型指標），
   所以比較規則就是單純 value > threshold，跟 ul_is_overload() 對這幾個鍵實際算出來的
   結果一致；要是之後這幾個單位也有比率型門檻，要連這裡的方向判斷一起補，不可以照抄。
   設定一旦被管理員改過（btnSetSave 成功後）要清快取重抓，否則標紅會沿用舊門檻。 */
var UL_THR = null;
/* 2026-10-07 新增「不需要門檻」逐指標覆寫之後，settings_get 回的 thresholds 裡某個指標的
   葉節點可能是純數字（舊格式，沒有被 no_threshold 覆寫過）或 {value,no_threshold} 物件
   （見 unit_load_lib.php 的 ul_merge_no_threshold_overrides()），這兩支小工具統一處理
   兩種形狀，呼叫端不必各自判斷一次。 */
function thrLeafNum(leaf, defVal){
  if (leaf === undefined || leaf === null) return defVal;
  if (typeof leaf === 'object') return (leaf.value===undefined||leaf.value===null) ? defVal : Number(leaf.value);
  return Number(leaf);
}
function thrLeafNoThr(leaf, defFlag){
  if (leaf && typeof leaf === 'object' && Object.prototype.hasOwnProperty.call(leaf,'no_threshold')) return !!leaf.no_threshold;
  return !!defFlag;
}
function ensureThresholds(cb){
  if (UL_THR){ cb(); return; }
  $.get(UL_API, {action:'settings_get'}, function(r){
    var flat = {};
    if (r && r.ok){
      Object.keys(r.threshold_defaults||{}).forEach(function(unit){
        Object.keys(r.threshold_defaults[unit]).forEach(function(mk){
          var def = r.threshold_defaults[unit][mk];
          var leaf = (r.thresholds[unit]||{})[mk];
          flat[unit+'.'+mk] = thrLeafNum(leaf, def.value);
        });
      });
    }
    UL_THR = flat;
    cb();
  }, 'json').fail(function(){ UL_THR = {}; cb(); });
}
/** 哪個單位的哪個「明細欄位名」對應哪個門檻鍵（比照 UnitLoad_API.php 的 ul_is_overload() 呼叫處整理） */
var UL_METRIC_THR_KEY = {
  design: { drawing_wip:'design.batch_pending', avg_draw_workdays:'design.avg_draw_workdays', issue_orders:'design.issue_orders' },
  sales:  { quote_count:'sales.quote_backlog', open_issue_count:'sales.open_issue_count' },
  pm:     { outsource_wip:'pm.outsource_wip', pending_recon_lines:'pm.pending_recon_lines' },
  /* prod.unassigned 沿用全單位門檻「未指派機台筆數」(prod.unassigned_count)，逐製程大類那一格
     超過同一個門檻就標紅——這是粗略的沿用，不是另外替每個大類各自訂一個門檻。 */
  prod:   { unassigned:'prod.unassigned_count' },
  qc:     { avg_wait_workdays:'qc.wait_days_avg' }
};
function cellBadCls(unit, field, value){
  if (value === null || value === undefined) return '';
  var key = (UL_METRIC_THR_KEY[unit]||{})[field];
  if (!key || !UL_THR || UL_THR[key] === undefined) return '';
  return Number(value) > Number(UL_THR[key]) ? ' class="ul-cell-bad"' : '';
}
/* 品管目前待驗佇列總筆數／品管單一製程目前筆數：這兩個指標 unit_load_lib.php 的
   ul_threshold_defaults() 尚未收錄對應門檻鍵，暫不等 lib 端支援——先用這裡的合理
   預設值，之後若要讓管理員也能在「設定」跳窗調整，再補進 ul_threshold_defaults() 並
   改走 UL_THR。（包裝待包裝筆數已正式登記為 packing.pending，overload 旗標由後端
   data_packing 計算回傳，不再走這裡的本地預設值。）
   qc_process_pending：單一製程本身堆到這個筆數以上視為偏高（跟「目前待驗總筆數」門檻
   是不同層級的判斷——總筆數看的是全部製程加起來，這裡看的是某一個製程自己有沒有堆住）。 */
var UL_LOCAL_THR_DEFAULT = { qc_pending_total: 150, qc_process_pending: 30 };
function localThr(key, fallbackKey){
  if (UL_THR && UL_THR[key] !== undefined) return Number(UL_THR[key]);
  return Number(UL_LOCAL_THR_DEFAULT[fallbackKey]);
}
/** 把 YYYY-MM-DD HH:MM:SS 或純日期字串只取日期部分，空值印破折號 */
function dOnly(s){ return s ? String(s).substring(0,10) : '—'; }
/** 待驗/包裝「最長/最短前5筆」小表格，withProcess=true 時多印一欄製程名稱。
    2026-10-07 新增「料號」欄（ul_qc_wait_time() 的 rows/longest/shortest 已補上 d_id）
    ——包裝那邊的 longest/shortest 目前沒有這個欄位，r.d_id 會是 undefined，顯示「—」，
    不會壞也不必另外判斷。 */
function extremesTable(rows, withProcess){
  rows = rows || [];
  if (!rows.length) return '<div class="ul-empty-hint" style="padding:6px 0;">（無資料）</div>';
  var h = '<table class="table table-condensed" style="margin-bottom:0;"><thead><tr><th>製令</th><th>料號</th>'
        + (withProcess ? '<th>製程</th>' : '') + '<th>工作天</th><th>進入</th><th>完成</th></tr></thead><tbody>';
  rows.forEach(function(r){
    h += '<tr><td>'+esc(r.bom)+'</td><td>'+esc(r.d_id || '—')+'</td>'
       + (withProcess ? '<td>'+esc(r.process_name)+'</td>' : '')
       + '<td>'+nf1(r.workdays)+'</td><td>'+esc(dOnly(r.enter_at))+'</td><td>'+esc(dOnly(r.finish_at))+'</td></tr>';
  });
  h += '</tbody></table>';
  return h;
}

/* ── 設計課分頁 ─────────────────────────────────────── */
/* 2026-10-07：loadXxx(silent, done) 改成共用的背景預載模式（第八步）——silent=true 時
   失敗不彈 alert（背景預載用）；done 是不管成功失敗都會呼叫一次的收尾 callback，給
   schedulePreload() 串行排隊用。UNIT_LOADING 旗標避免同一單位同時發出兩支請求。 */
function loadDesign(silent, done){
  if (UNIT_LOADING.design){ if (done) done(); return; }
  UNIT_LOADING.design = true;
  var params = $.extend({action:'data_design'}, periodParams());
  ensureThresholds(function(){
    $.get(UL_API, params, function(r){
      UNIT_LOADING.design = false;
      if(!r || !r.ok){ if(!silent) alert((r&&r.error)||'載入失敗'); if(done) done(); return; }
      renderDesign(r);
      UNIT_LOADED.design = true;
      if (done) done();
    }, 'json').fail(function(xhr){
      UNIT_LOADING.design = false;
      if (!silent){ var r = xhr.responseJSON; alert((r&&r.error) || ('載入失敗：HTTP '+xhr.status)); }
      if (done) done();
    });
  });
}
function renderDesign(d){
  $('#dsgNote').html(d.note ? '<div class="ul-unit-note"><i class="fa fa-exclamation-circle"></i> '+esc(d.note)+'</div>' : '');

  var s = d.summary, c = d.summary_cmp, ov = d.overload || {}, L = d.period.cmp_label;
  var h = '';
  h += statTile('批圖中', s.drawing_wip, c.drawing_wip, L, nf, !!ov.drawing_wip);
  h += statTile('已按審圖', s.in_review, c.in_review, L, nf, false);
  h += statTile('已按轉生管', s.pm_get, c.pm_get, L, nf, false);
  h += newCaseTile(s.new_case, c.new_case, L);
  h += statTile('問題訂單數', s.issue_orders, c.issue_orders, L, nf, !!ov.issue_orders);
  // 2026-10-07 新增：平均出圖工作天旁補上「基於幾筆真正需要繪圖的案件」；另外補一張
  // 「樣品繪圖平均工作天」卡片（avg_sample_draw_workdays／sample_draw_case_count），
  // 完全沒有樣品繪圖案件時顯示明確提示，不要留一個空白或 null 的尷尬格子。
  h += statTile('平均出圖工作天', s.avg_draw_workdays, c.avg_draw_workdays, L, nf1, !!ov.avg_draw_workdays,
       '（基於 '+nf(s.draw_case_count||0)+' 筆真正需要繪圖的案件）');
  if (Number(s.sample_draw_case_count||0) > 0){
    h += statTile('樣品繪圖平均工作天', s.avg_sample_draw_workdays, c.avg_sample_draw_workdays, L, nf1, false,
         '（基於 '+nf(s.sample_draw_case_count)+' 筆樣品繪圖案件）');
  } else {
    h += '<div class="kpi-card"><div class="k-lab">樣品繪圖平均工作天</div>'
       + '<div class="ul-empty-hint" style="padding:8px 0;">目前沒有樣品繪圖案件</div></div>';
  }
  $('#dsgKpi').html(h);

  renderDesignReviewer(d.reviewer, d.by_person);
  renderDesignDailyChart(d.daily_pmget, d.by_person, d.period);
  renderDesignTags(d.tags);
  renderDesignNoteStats(d.note_stats, d.by_person);
  renderDesignTable(d.by_person);
}
function renderDesignReviewer(rv, byPerson){
  if (!rv || !rv.supported){
    $('#dsgReviewer').html(emptyHint((rv && rv.note) || '尚無法判定。'));
    return;
  }
  var h = '<div class="kpi-row">';
  Object.keys(rv.by_user).forEach(function(uid){
    h += '<div class="kpi-card ul-mini"><div class="k-lab">'+esc(nameOf(byPerson, uid))+'</div>'
       + '<div class="k-val">'+nf(rv.by_user[uid])+' 次</div></div>';
  });
  h += '</div>';
  $('#dsgReviewer').html(h);
}
/** 依期間長度動態決定趨勢圖的時間顆粒度（2026-10-07 新增，第九步）：期間越長，點越密集
    越看不出趨勢，故 31 天內逐日、120 天內逐週（以週一為代表日）、超過則逐月。 */
function pickTimeGran(period){
  if (!period || !period.from || !period.to) return 'day';
  var span = Math.round((new Date(period.to+'T00:00:00') - new Date(period.from+'T00:00:00')) / 86400000) + 1;
  if (span <= 31) return 'day';
  if (span <= 120) return 'week';
  return 'month';
}
/** 把一個 YYYY-MM-DD 依粒度歸到所屬的桶鍵：day=原樣／week=該週週一的日期／month=YYYY-MM */
function bucketKeyFor(dateStr, gran){
  if (gran === 'month') return String(dateStr).slice(0, 7);
  if (gran === 'week'){
    var dt = new Date(dateStr+'T00:00:00');
    var dow = dt.getDay(); // 0=週日..6=週六
    var diff = (dow === 0) ? -6 : (1 - dow); // 往回退到週一
    dt.setDate(dt.getDate() + diff);
    return dt.toISOString().slice(0, 10);
  }
  return dateStr;
}
var TIME_GRAN_LABEL = { day:'逐日', week:'逐週（以週一代表整週）', month:'逐月' };
function renderDesignDailyChart(rows, byPerson, period){
  rows = rows || [];
  if (!rows.length){
    $('#dsgDailyGranNote').text('');
    $('#dsgDailyChart').html(emptyHint('本期沒有已按轉生管的資料。'));
    return;
  }
  var gran = pickTimeGran(period);
  $('#dsgDailyGranNote').text('目前依期間長度自動改用「'+TIME_GRAN_LABEL[gran]+'」彙總，避免期間拉長時資料點太密集看不出趨勢');
  var buckets = [], seen = {};
  var byAte = {};
  rows.forEach(function(r){
    var bk = bucketKeyFor(r.d, gran);
    if (!seen[bk]){ seen[bk]=1; buckets.push(bk); }
    var k = String(r.ate);
    if (!byAte[k]) byAte[k] = {};
    byAte[k][bk] = (byAte[k][bk]||0) + Number(r.c||0);
  });
  buckets.sort();
  var series = Object.keys(byAte).map(function(k){
    return { name: nameOf(byPerson, k), data: buckets.map(function(b){ return byAte[k][b] || 0; }) };
  });
  chart('dsgDailyChart', {
    chart: { type:'line', height:320 },
    xAxis: { categories: buckets },
    yAxis: { title:{text:null}, allowDecimals:false, min:0 },
    tooltip: { shared:true },
    series: series
  });
}
/** 訂單標籤分布改圓餅圖（2026-10-07，第十步），表格留在下方當明細 */
function renderDesignTags(rows){
  rows = rows || [];
  if (!rows.length){
    $('#dsgTagsChart').html(emptyHint('本期沒有標籤資料。'));
    $('#dsgTags').html('');
    return;
  }
  var data = rows.map(function(r){ return { name: r.tag_name, y: Number(r.c||0) }; });
  chart('dsgTagsChart', {
    chart: { type:'pie', height:300 },
    tooltip: { pointFormat:'{series.name}：<b>{point.y}</b>（{point.percentage:.1f}%）' },
    plotOptions: { pie: { allowPointSelect:true, dataLabels:{ enabled:true, format:'{point.name}：{point.percentage:.1f}%' } } },
    series: [{ name:'筆數', colorByPoint:true, data:data }]
  });
  var h = '<div class="table-responsive"><table class="table table-striped"><thead><tr><th>標籤</th><th>筆數</th></tr></thead><tbody>';
  rows.forEach(function(r){ h += '<tr><td>'+esc(r.tag_name)+'</td><td>'+nf(r.c)+'</td></tr>'; });
  h += '</tbody></table></div>';
  $('#dsgTags').html(h);
}
function renderDesignNoteStats(ns, byPerson){
  ns = ns || { open_count:0, avg_reply_workdays:null, by_designer:{} };
  var h = '<div class="kpi-row">';
  h += '<div class="kpi-card ul-mini"><div class="k-lab">開放問題總數</div><div class="k-val">'+nf(ns.open_count)+'</div></div>';
  h += '<div class="kpi-card ul-mini"><div class="k-lab">平均回覆工作天</div><div class="k-val">'+nf1(ns.avg_reply_workdays)+'</div></div>';
  h += '</div>';
  var keys = Object.keys(ns.by_designer || {});
  if (keys.length){
    h += '<div class="table-responsive"><table class="table table-striped"><thead><tr><th>姓名</th><th>開放問題數</th><th>平均回覆工作天</th></tr></thead><tbody>';
    keys.forEach(function(uid){
      var row = ns.by_designer[uid];
      h += '<tr><td>'+esc(nameOf(byPerson, uid))+'</td><td>'+nf(row.open)+'</td><td>'+nf1(row.avg_days)+'</td></tr>';
    });
    h += '</tbody></table></div>';
  }
  $('#dsgNoteStats').html(h);
}
function renderDesignTable(rows){
  rows = rows || [];
  if (!rows.length){
    $('#dsgTable tbody').html('<tr><td colspan="8" style="text-align:center;color:var(--muted);">尚未設定部門範圍</td></tr>');
    return;
  }
  var h = '';
  rows.forEach(function(r){
    h += '<tr><td>'+esc(r.dept_name)+'</td><td>'+esc(r.position_name)+'</td><td>'+esc(r.name)+'</td>'
       + '<td'+cellBadCls('design','drawing_wip',r.drawing_wip)+'>'+nf(r.drawing_wip)+'</td>'
       + '<td>'+nf(r.in_review)+'</td>'
       + '<td>'+nf(r.pm_get)+'</td>'
       + '<td'+cellBadCls('design','issue_orders',r.issue_orders)+'>'+nf(r.issue_orders)+'</td>'
       + '<td'+cellBadCls('design','avg_draw_workdays',r.avg_draw_workdays)+'>'+nf1(r.avg_draw_workdays)+'</td></tr>';
  });
  $('#dsgTable tbody').html(h);
}

/* ── 業務課分頁 ─────────────────────────────────────── */
function loadSales(silent, done){
  if (UNIT_LOADING.sales){ if (done) done(); return; }
  UNIT_LOADING.sales = true;
  var params = $.extend({action:'data_sales'}, periodParams());
  ensureThresholds(function(){
    $.get(UL_API, params, function(r){
      UNIT_LOADING.sales = false;
      if(!r || !r.ok){ if(!silent) alert((r&&r.error)||'載入失敗'); if(done) done(); return; }
      renderSales(r);
      UNIT_LOADED.sales = true;
      if (done) done();
    }, 'json').fail(function(xhr){
      UNIT_LOADING.sales = false;
      if (!silent){ var r = xhr.responseJSON; alert((r&&r.error) || ('載入失敗：HTTP '+xhr.status)); }
      if (done) done();
    });
  });
}
function renderSales(d){
  $('#salNote').html(d.note ? '<div class="ul-unit-note"><i class="fa fa-exclamation-circle"></i> '+esc(d.note)+'</div>' : '');

  var s = d.summary, c = d.summary_cmp, ov = d.overload || {}, L = d.period.cmp_label;
  var h = '';
  h += statTile('本期報價單數', s.quote_count, c.quote_count, L, nf, !!ov.quote_count);
  h += statTile('報價明細筆數', s.quote_item_count, c.quote_item_count, L, nf, false);
  h += statTile('訂單追蹤筆數', s.order_count, c.order_count, L, nf, false);
  h += statTile('待回覆問題筆數', s.open_issue_count, c.open_issue_count, L, nf, !!ov.open_issue_count);
  $('#salKpi').html(h);

  renderSalesTable(d.by_person);
}
function renderSalesTable(rows){
  rows = rows || [];
  if (!rows.length){
    $('#salTable tbody').html('<tr><td colspan="7" style="text-align:center;color:var(--muted);">尚未設定部門範圍</td></tr>');
    return;
  }
  var h = '';
  rows.forEach(function(r){
    h += '<tr><td>'+esc(r.dept_name)+'</td><td>'+esc(r.position_name)+'</td><td>'+esc(r.name)+'</td>'
       + '<td'+cellBadCls('sales','quote_count',r.quote_count)+'>'+nf(r.quote_count)+'</td>'
       + '<td>'+nf(r.quote_item_count)+'</td>'
       + '<td>'+nf(r.order_count)+'</td>'
       + '<td'+cellBadCls('sales','open_issue_count',r.open_issue_count)+'>'+nf(r.open_issue_count)+'</td></tr>';
  });
  $('#salTable tbody').html(h);
}

/* ── 生管分頁 ───────────────────────────────────────── */
function loadPm(silent, done){
  if (UNIT_LOADING.pm){ if (done) done(); return; }
  UNIT_LOADING.pm = true;
  var params = $.extend({action:'data_pm'}, periodParams());
  $.get(UL_API, params, function(r){
    UNIT_LOADING.pm = false;
    if(!r || !r.ok){ if(!silent) alert((r&&r.error)||'載入失敗'); if(done) done(); return; }
    renderPm(r);
    UNIT_LOADED.pm = true;
    if (done) done();
  }, 'json').fail(function(xhr){
    UNIT_LOADING.pm = false;
    if (!silent){ var r = xhr.responseJSON; alert((r&&r.error) || ('載入失敗：HTTP '+xhr.status)); }
    if (done) done();
  });
}
function renderPm(d){
  $('#pmNote').html(d.note ? '<div class="ul-unit-note"><i class="fa fa-exclamation-circle"></i> '+esc(d.note)+'</div>' : '');

  var s = d.summary, c = d.summary_cmp, ov = d.overload || {}, L = d.period.cmp_label;
  var h = '';
  h += statTile('外包未回筆數', s.outsource_wip, c.outsource_wip, L, nf, !!ov.outsource_wip);
  h += statTile('廠內在製筆數', s.internal_wip, c.internal_wip, L, nf, false);
  h += statTile('已轉QC筆數', s.to_qc, c.to_qc, L, nf, false);
  h += statTile('待移轉筆數', s.pending_transfer, c.pending_transfer, L, nf, false);
  h += statTile('已移轉筆數', s.transferred, c.transferred, L, nf, false);
  h += statTile('待對帳家數', s.pending_recon_parties, c.pending_recon_parties, L, nf, false);
  h += statTile('待對帳筆數', s.pending_recon_lines, c.pending_recon_lines, L, nf, !!ov.pending_recon_lines);
  $('#pmKpi').html(h);
}

/* ── 生產課分頁 ─────────────────────────────────────── */
function loadProd(silent, done){
  if (UNIT_LOADING.prod){ if (done) done(); return; }
  UNIT_LOADING.prod = true;
  var params = $.extend({action:'data_prod'}, periodParams());
  ensureThresholds(function(){
    $.get(UL_API, params, function(r){
      UNIT_LOADING.prod = false;
      if(!r || !r.ok){ if(!silent) alert((r&&r.error)||'載入失敗'); if(done) done(); return; }
      renderProd(r);
      UNIT_LOADED.prod = true;
      if (done) done();
    }, 'json').fail(function(xhr){
      UNIT_LOADING.prod = false;
      if (!silent){ var r = xhr.responseJSON; alert((r&&r.error) || ('載入失敗：HTTP '+xhr.status)); }
      if (done) done();
    });
  });
}
function renderProd(d){
  $('#prodNote').html(d.note ? '<div class="ul-unit-note"><i class="fa fa-exclamation-circle"></i> '+esc(d.note)+'</div>' : '');
  var ov = d.overload || {};

  renderProdTypeGrid(d.by_process_type);
  renderProdUntracked(d.untracked, !!ov.untracked_count);
  renderProdDailyChart(d.daily_output);
  renderProdTimeStats(d.setup, d.production, d.per_capita, !!ov.avg_setup_minutes);
}
/** 製程大類負荷改成四欄網格卡片（2026-10-07，第三步，比照品管「目前待驗佇列」同一種版面） */
function renderProdTypeGrid(rows){
  rows = rows || [];
  if (!rows.length){
    $('#prodTypeGrid').html(emptyHint('目前沒有進行中的製程。'));
    return;
  }
  var thr = UL_THR ? UL_THR['prod.unassigned_count'] : undefined;
  var h = '<div class="ul-pt-grid">';
  rows.forEach(function(r){
    var bad = (thr !== undefined) && Number(r.unassigned||0) > Number(thr);
    h += '<div class="ul-pt-item'+(bad?' ul-overload':'')+'">'
       + '<div class="ul-pt-name" title="'+esc(r.process_type_name)+'">'+esc(r.process_type_name)+'</div>'
       + '<div class="ul-pt-row"><span>未指派機台</span><b>'+nf(r.unassigned)+'</b></div>'
       + '<div class="ul-pt-row"><span>已指派機台</span><b>'+nf(r.assigned)+'</b></div>'
       + '<div class="ul-pt-row"><span>合計</span><b>'+nf(r.total)+'</b></div>'
       + '</div>';
  });
  h += '</div>';
  $('#prodTypeGrid').html(h);
}
function renderProdUntracked(u, bad){
  u = u || { by_process_type:[], examples:[], total:0 };
  if (!u.total){ $('#prodUntracked').html(emptyHint('本期沒有偵測到這類筆數。')); return; }
  var h = '<div class="kpi-row">' + statTile('本期筆數', u.total, undefined, null, nf, bad) + '</div>';
  if ((u.by_process_type||[]).length){
    h += '<div class="table-responsive"><table class="table table-striped"><thead><tr><th>製程大類</th><th>筆數</th></tr></thead><tbody>';
    u.by_process_type.forEach(function(r){ h += '<tr><td>'+esc(r.process_type_name)+'</td><td>'+nf(r.count)+'</td></tr>'; });
    h += '</tbody></table></div>';
  }
  if ((u.examples||[]).length){
    // 2026-10-07 第三步：範例清單改成三欄網格卡片，不再用一列只有三個資料的傳統表格。
    h += '<div style="font-size:12px;color:var(--muted);margin-bottom:4px;">範例（最多20筆）：</div>';
    h += '<div class="ul-pt3-grid">';
    u.examples.forEach(function(r){
      h += '<div class="ul-pt3-item">'
         + '<div class="ul-pt3-row"><span>製令</span><b title="'+esc(r.bom)+'">'+esc(r.bom)+'</b></div>'
         + '<div class="ul-pt3-row"><span>製程</span><b title="'+esc(r.process_name)+'">'+esc(r.process_name)+'</b></div>'
         + '<div class="ul-pt3-row"><span>報工日期</span><b>'+esc(dOnly(r.report_date))+'</b></div>'
         + '</div>';
    });
    h += '</div>';
  }
  $('#prodUntracked').html(h);
}
function renderProdDailyChart(out){
  var rows = (out && out.rows) || [];
  if (!rows.length){ $('#prodDailyChart').html(emptyHint('本期沒有報工資料。')); return; }
  var dates = [], seen = {};
  rows.forEach(function(r){ if(!seen[r.report_date]){ seen[r.report_date]=1; dates.push(r.report_date); } });
  dates.sort();
  var byType = {};
  rows.forEach(function(r){
    var k = String(r.process_type_name);
    if(!byType[k]) byType[k] = {};
    byType[k][r.report_date] = (byType[k][r.report_date]||0) + Number(r.qty||0);
  });
  var series = Object.keys(byType).map(function(k){
    return { name:k, data: dates.map(function(d){ return byType[k][d] || 0; }) };
  });
  chart('prodDailyChart', {
    chart: { type:'column', height:320 },
    xAxis: { categories: dates },
    yAxis: { title:{text:null}, allowDecimals:false, min:0 },
    tooltip: { shared:true },
    series: series
  });
}
function renderProdTimeStats(setup, production, perCapita, setupBad){
  setup = setup || { avg_minutes:null, daily:[], valid_count:0 };
  production = production || { avg_minutes:null, daily:[], valid_count:0 };
  perCapita = perCapita || {};

  var h = '<div class="kpi-row">';
  h += statTile('平均架機時間（分）', setup.avg_minutes, undefined, null, nf1, setupBad);
  h += statTile('有效架機筆數', setup.valid_count, undefined, null, nf, false);
  h += statTile('平均生產時間（分）', production.avg_minutes, undefined, null, nf1, false);
  h += statTile('有效生產筆數', production.valid_count, undefined, null, nf, false);
  h += '</div>';
  $('#prodTimeKpi').html(h);

  var h2 = '<div class="kpi-row">';
  h2 += statTile('報工人數', perCapita.worker_count, undefined, null, nf, false);
  h2 += statTile('人均產量', perCapita.avg_output_per_person, undefined, null, nf1, false);
  h2 += statTile('人均架機時間（分）', perCapita.avg_setup_minutes_per_person, undefined, null, nf1, false);
  h2 += statTile('人均生產時間（分）', perCapita.avg_production_minutes_per_person, undefined, null, nf1, false);
  h2 += '</div>';
  $('#prodPerCapita').html(h2);

  var daily = setup.daily || [];
  if (!daily.length){ $('#prodSetupChart').html(emptyHint('本期沒有架機時間資料。')); return; }
  chart('prodSetupChart', {
    chart: { type:'line', height:280 },
    xAxis: { categories: daily.map(function(r){ return r.report_date; }) },
    yAxis: { title:{text:null}, allowDecimals:false, min:0 },
    series: [{ name:'每日架機數', data: daily.map(function(r){ return r.count; }) }]
  });
}
/* ── 包裝分頁（獨立單位，2026-10-07 新增）────────────── */
function loadPacking(silent, done){
  if (UNIT_LOADING.packing){ if (done) done(); return; }
  UNIT_LOADING.packing = true;
  var params = $.extend({action:'data_packing'}, periodParams());
  ensureThresholds(function(){
    $.get(UL_API, params, function(r){
      UNIT_LOADING.packing = false;
      if(!r || !r.ok){ if(!silent) alert((r&&r.error)||'載入失敗'); if(done) done(); return; }
      renderPacking(r);
      UNIT_LOADED.packing = true;
      if (done) done();
    }, 'json').fail(function(xhr){
      UNIT_LOADING.packing = false;
      if (!silent){ var r = xhr.responseJSON; alert((r&&r.error) || ('載入失敗：HTTP '+xhr.status)); }
      if (done) done();
    });
  });
}
function renderPacking(d){
  $('#packNote').html(d.note ? '<div class="ul-unit-note"><i class="fa fa-exclamation-circle"></i> '+esc(d.note)+'</div>' : '');
  var ov = d.overload || {};
  var p = d.packing || { pending:0, daily:[], avg_workdays:null, longest:[], shortest:[] };

  var h = '<div class="kpi-row">';
  h += statTile('待包裝筆數', p.pending, undefined, null, nf, !!ov.pending);
  h += statTile('平均處理工作天', p.avg_workdays, undefined, null, nf1, false);
  h += '</div>';
  $('#packKpi').html(h);

  var daily = p.daily || [];
  if (!daily.length){
    $('#packChart').html(emptyHint('本期沒有包裝完工資料。'));
  } else {
    chart('packChart', {
      chart: { height:280 },
      xAxis: { categories: daily.map(function(r){ return r.report_date; }) },
      yAxis: [
        { title:{text:null}, allowDecimals:false, min:0 },
        { title:{text:null}, opposite:true, min:0 }
      ],
      tooltip: { shared:true },
      series: [
        { name:'每日完成筆數', type:'column', yAxis:0, data: daily.map(function(r){ return r.count; }) },
        { name:'平均處理工作天', type:'line', yAxis:1, data: daily.map(function(r){ return r.avg_workdays; }) }
      ]
    });
  }

  var h2 = '<div class="row">'
    + '<div class="col-sm-6"><div style="font-size:12.5px;color:var(--ink);font-weight:700;margin-bottom:4px;">處理最久前5筆</div>'+extremesTable(p.longest,false)+'</div>'
    + '<div class="col-sm-6"><div style="font-size:12.5px;color:var(--ink);font-weight:700;margin-bottom:4px;">處理最快前5筆</div>'+extremesTable(p.shortest,false)+'</div>'
    + '</div>';
  $('#packExtremes').html(h2);

  // 2026-10-07 第十二步：逐人負荷明細表拿掉——包裝報工記在實際操作人員頭上、跟倉管組
  // 編制常對不起來（見本頁使用說明），一個永遠是空表格的清單只會誤導使用者以為壞掉，
  // 靜態說明文字已固定寫在 HTML 裡，這裡不再呼叫 renderPackingTable()。
}

/* ── 品管分頁 ───────────────────────────────────────── */
function loadQc(silent, done){
  if (UNIT_LOADING.qc){ if (done) done(); return; }
  UNIT_LOADING.qc = true;
  var params = $.extend({action:'data_qc'}, periodParams());
  ensureThresholds(function(){
    $.get(UL_API, params, function(r){
      UNIT_LOADING.qc = false;
      if(!r || !r.ok){ if(!silent) alert((r&&r.error)||'載入失敗'); if(done) done(); return; }
      renderQc(r);
      UNIT_LOADED.qc = true;
      if (done) done();
    }, 'json').fail(function(xhr){
      UNIT_LOADING.qc = false;
      if (!silent){ var r = xhr.responseJSON; alert((r&&r.error) || ('載入失敗：HTTP '+xhr.status)); }
      if (done) done();
    });
  });
}
function renderQc(d){
  $('#qcNote').html(d.note ? '<div class="ul-unit-note"><i class="fa fa-exclamation-circle"></i> '+esc(d.note)+'</div>' : '');
  var ov = d.overload || {};

  renderQcPending(d.pending_queue);
  renderQcDailyChart(d.daily_items);
  renderQcWait(d.wait, !!ov.wait_days_avg);
  renderQcAbnormal(d.abnormal, !!ov.ng_rate);
  renderQcPersonTable(d.by_person);
  renderQcAdhoc(d.adhoc, !!ov.adhoc_count);
}
function renderQcPending(pq){
  pq = pq || { by_process:[], total:0 };
  var bad = Number(pq.total||0) > localThr('qc.pending_queue_total', 'qc_pending_total');
  $('#qcPendingKpi').html('<div class="kpi-row">' + statTile('目前待驗總筆數', pq.total, undefined, null, nf, bad) + '</div>');

  var rows = pq.by_process || [];
  if (!rows.length){ $('#qcPendingTable').html(emptyHint('目前沒有待驗中的製程。')); return; }
  // 四欄網格：每格顯示「製程名稱／目前筆數／平均檢驗工作天數」，筆數或平均工作天數
  // 任一項超過門檻就標紅（qc_process_pending／qc.wait_days_avg，後者沿用「待驗等待
  // 工作天」區塊已有、管理員可調的同一個門檻，不是另外替每個製程各訂一個）。
  var pendThr = localThr('qc.pending_queue_process', 'qc_process_pending');
  var h = '<div class="ul-pq-grid">';
  rows.forEach(function(r){
    var avg = (r.avg_wait_workdays===null || r.avg_wait_workdays===undefined) ? null : Number(r.avg_wait_workdays);
    var rowBad = Number(r.count||0) > pendThr || (avg !== null && UL_THR && UL_THR['qc.wait_days_avg'] !== undefined && avg > Number(UL_THR['qc.wait_days_avg']));
    h += '<div class="ul-pq-item'+(rowBad?' ul-overload':'')+'">'
       + '<div class="ul-pq-name" title="'+esc(r.process_name)+'">'+esc(r.process_name)+'</div>'
       + '<div class="ul-pq-row"><span>目前筆數</span><b>'+nf(r.count)+'</b></div>'
       + '<div class="ul-pq-row"><span>平均檢驗工作天</span><b>'+(avg===null?'—':nf1(avg))+'</b></div>'
       + '</div>';
  });
  h += '</div>';
  $('#qcPendingTable').html(h);
}
/* 2026-10-07 第十一步：補上一條「每日總筆數」線（跨製程加總），不只是逐製程的分組
   直條圖——至少要能一眼看出整體趨勢，不必自己把每天各製程的長條加起來心算。 */
function renderQcDailyChart(rows){
  rows = rows || [];
  if (!rows.length){ $('#qcDailyChart').html(emptyHint('本期沒有檢驗項目資料。')); return; }
  var dates = [], seen = {};
  rows.forEach(function(r){ if(!seen[r.check_date]){ seen[r.check_date]=1; dates.push(r.check_date); } });
  dates.sort();
  var byProc = {}, totalByDate = {};
  rows.forEach(function(r){
    var k = String(r.process_name);
    if(!byProc[k]) byProc[k] = {};
    byProc[k][r.check_date] = (byProc[k][r.check_date]||0) + Number(r.count||0);
    totalByDate[r.check_date] = (totalByDate[r.check_date]||0) + Number(r.count||0);
  });
  var series = Object.keys(byProc).map(function(k){
    return { type:'column', name:k, data: dates.map(function(d){ return byProc[k][d] || 0; }) };
  });
  series.push({
    type:'line', name:'每日總筆數', color:'#8B2E22', lineWidth:2, zIndex:5,
    marker:{ enabled:true, radius:3 },
    data: dates.map(function(d){ return totalByDate[d] || 0; })
  });
  chart('qcDailyChart', {
    chart: { height:320 },
    xAxis: { categories: dates },
    yAxis: { title:{text:null}, allowDecimals:false, min:0 },
    tooltip: { shared:true },
    series: series
  });
}
function renderQcWait(w, bad){
  w = w || { avg_workdays:null, longest:[], shortest:[], per_capita_workdays:null };
  var h = '<div class="kpi-row">';
  h += statTile('平均等待工作天', w.avg_workdays, undefined, null, nf1, bad);
  h += statTile('人均檢驗天數', w.per_capita_workdays, undefined, null, nf1, false);
  h += '</div>';
  $('#qcWaitKpi').html(h);

  var h2 = '<div class="row">'
    + '<div class="col-sm-6"><div style="font-size:12.5px;color:var(--ink);font-weight:700;margin-bottom:4px;">等待最久前5筆</div>'+extremesTable(w.longest,true)+'</div>'
    + '<div class="col-sm-6"><div style="font-size:12.5px;color:var(--ink);font-weight:700;margin-bottom:4px;">等待最短前5筆</div>'+extremesTable(w.shortest,true)+'</div>'
    + '</div>';
  $('#qcWaitExtremes').html(h2);
}
function renderQcAbnormal(ab, bad){
  ab = ab || { daily_abnormal:[], daily_ng_rate:[], avg_abnormal_per_day:0, avg_ng_rate:null };
  var h = '<div class="kpi-row">';
  h += statTile('平均每日異常單數', ab.avg_abnormal_per_day, undefined, null, nf1, false);
  h += statTile('平均每日NG比例', ab.avg_ng_rate!=null ? ab.avg_ng_rate*100 : null, undefined, null, pct1, bad);
  h += '</div>';
  $('#qcAbnormalKpi').html(h);

  var dates = [], seen = {};
  (ab.daily_abnormal||[]).forEach(function(r){ if(!seen[r.date]){ seen[r.date]=1; dates.push(r.date); } });
  (ab.daily_ng_rate||[]).forEach(function(r){ if(!seen[r.date]){ seen[r.date]=1; dates.push(r.date); } });
  dates.sort();
  if (!dates.length){ $('#qcAbnormalChart').html(emptyHint('本期沒有異常單或檢驗資料。')); return; }
  var abMap = {}; (ab.daily_abnormal||[]).forEach(function(r){ abMap[r.date]=r.count; });
  var ngMap = {}; (ab.daily_ng_rate||[]).forEach(function(r){ ngMap[r.date]=r.rate; });
  chart('qcAbnormalChart', {
    chart: { height:320 },
    xAxis: { categories: dates },
    yAxis: [
      { title:{text:null}, allowDecimals:false, min:0 },
      { title:{text:null}, opposite:true, min:0, labels:{ formatter:function(){ return this.value+'%'; } } }
    ],
    tooltip: { shared:true },
    series: [
      { name:'每日異常單數', type:'column', yAxis:0, data: dates.map(function(d){ return abMap[d]||0; }) },
      { name:'每日NG比例(%)', type:'line', yAxis:1, data: dates.map(function(d){ return ngMap[d]!=null ? Math.round(ngMap[d]*1000)/10 : null; }) }
    ]
  });
}
function renderQcPersonTable(rows){
  rows = rows || [];
  if (!rows.length){
    $('#qcPersonTable tbody').html('<tr><td colspan="6" style="text-align:center;color:var(--muted);">尚未設定部門範圍</td></tr>');
    return;
  }
  var h = '';
  rows.forEach(function(r){
    h += '<tr><td>'+esc(r.dept_name)+'</td><td>'+esc(r.position_name)+'</td><td>'+esc(r.name)+'</td>'
       + '<td>'+nf(r.items_count)+'</td>'
       + '<td>'+nf(r.ng_count)+'</td>'
       + '<td'+cellBadCls('qc','avg_wait_workdays',r.avg_wait_workdays)+'>'+nf1(r.avg_wait_workdays)+'</td></tr>';
  });
  $('#qcPersonTable tbody').html(h);
}
function renderQcAdhoc(ad, bad){
  ad = ad || { by_process:[], total:0 };
  var h = '<div class="kpi-row">' + statTile('本期筆數', ad.total, undefined, null, nf, bad) + '</div>';
  var rows = ad.by_process || [];
  if (rows.length){
    h += '<div class="table-responsive"><table class="table table-striped"><thead><tr><th>製程</th><th>筆數</th></tr></thead><tbody>';
    rows.forEach(function(r){ h += '<tr><td>'+esc(r.process_name)+'</td><td>'+nf(r.count)+'</td></tr>'; });
    h += '</tbody></table></div>';
  }
  $('#qcAdhoc').html(h);
}

/* ── 單位分頁與期間篩選串接 ─────────────────────────── */
var UNIT_LOADERS = { design: loadDesign, sales: loadSales, pm: loadPm, prod: loadProd, qc: loadQc, packing: loadPacking };
var UNIT_LOADED  = { design: false, sales: false, pm: false, prod: false, qc: false, packing: false };
/* 2026-10-07 第八步新增：UNIT_LOADING 避免同一單位被背景預載與使用者手動點擊同時各打
   一次 API；每個 loadXxx(silent, done) 自己維護這支旗標（見各單位的 loader 定義）。 */
var UNIT_LOADING = { design: false, sales: false, pm: false, prod: false, qc: false, packing: false };
function loadUnitTab(key, silent, done){
  if (UNIT_LOADERS[key]) UNIT_LOADERS[key](silent, done);
  else if (done) done();
}
/** window.requestIdleCallback 若瀏覽器支援就用（背景預載不搶佔使用者互動），否則退回
    setTimeout（第八步要求）。 */
function idleOrTimeout(fn, delayMs){
  if (window.requestIdleCallback) { try { requestIdleCallback(function(){ fn(); }, {timeout: delayMs + 2000}); return; } catch(e){} }
  setTimeout(fn, delayMs);
}
/** 背景依序預載其餘分頁的資料（第八步）：總覽載完之後才開始，一個一個排隊打，不要
    六支同時發出去造成瞬間尖峰負載；任何一支失敗都安靜吞掉（loadXxx 的 silent=true 不
    會彈 alert），不影響其他分頁，使用者點開那個分頁時會照常走「點開才載入」的既有
    退路重新試一次（因為失敗時 UNIT_LOADED 不會被設成 true）。 */
function schedulePreload(){
  var keys = UNIT_KEYS.filter(function(k){ return !!UNIT_LOADERS[k]; });
  var i = 0;
  function next(){
    if (i >= keys.length) return;
    var k = keys[i++];
    if (UNIT_LOADED[k]) { idleOrTimeout(next, 120); return; }
    idleOrTimeout(function(){ loadUnitTab(k, true, next); }, 300);
  }
  next();
}

/* ── 主流程：載入總覽資料 ───────────────────────────── */
function load(){
  var params = {action:'overview', year:$('#fYear').val(), gran:$('#fGran').val(),
                period_idx:$('#fIdx').val(), cmp:$('#fCmp').val()};
  $.get(UL_API, params, function(r){
    if(!r || !r.ok){ alert((r&&r.error)||'載入失敗'); return; }
    renderOverview(r);
    schedulePreload(); // 總覽畫完之後才背景依序預載其餘五個單位分頁，不卡住總覽的呈現
  }, 'json').fail(function(xhr){
    var r = xhr.responseJSON;
    alert((r&&r.error) || ('載入失敗：HTTP '+xhr.status));
  });
}
/* 重新計算：總覽一律重算；期間篩選改變了，之前背景預載／點開過的分頁快取全部失效
  （鐵律：改了期間篩選，快取的舊資料不能再被沿用），目前正開著的那個分頁額外同步重算，
  其餘五個交給 load() 成功後的 schedulePreload() 背景依序補齊。 */
$('#btnReload').on('click', function(){
  UNIT_KEYS.forEach(function(k){ UNIT_LOADED[k] = false; });
  load();
  var t = $('.ul-tab-btn.active').data('tab');
  if (UNIT_LOADERS[t]) loadUnitTab(t);
});

/* ── 設定跳窗：部門範圍設定／門檻設定 ───────────────── */
var DEPT_NODES = null;   // dept_tree 回傳，快取一次即可
var DEPT_WORK  = {};     // 工作中選取狀態：unit -> {deptId:{include_sub:bool}}
var THRESH_WORK = {};    // 工作中門檻值：unit -> key -> value
var THRESH_DEFAULTS = {};
/* 2026-10-07 新增（第四步）：生產課「製程大類負荷」白名單／各單位「排除職位」／各指標
   「不需要門檻」，三者都是本頁設定跳窗新增的工作中狀態，存檔時與 dept_cfg／thresholds
   一起整批送出（settings_save 用 array_key_exists 判斷有沒有送，本頁一律都送）。 */
var ALL_PROCESS_TYPES = [];  // settings_get 回的 process_types：[{process_type_id,process_type}]
var PPT_WORK = [];           // 工作中已勾選的 process_type_id（純整數陣列，空＝全部顯示）
var EXCL_WORK = {};          // 工作中排除職位：unit -> [position_id,...]
var NOTHR_WORK = {};         // 工作中「不需要門檻」：unit -> key -> bool

function ensureDeptNodes(cb){
  if(DEPT_NODES){ cb(); return; }
  $.get(UL_API, {action:'dept_tree'}, function(r){
    if(!r || !r.ok){ alert((r&&r.error)||'部門清單載入失敗'); return; }
    DEPT_NODES = r.depts || [];
    cb();
  }, 'json');
}
function deptTreeHtml(nodes, parentId, unitKey, selMap){
  var kids = nodes.filter(function(n){ return n.parent_id === parentId; });
  if(!kids.length) return '';
  kids.sort(function(a,b){ return (a.sort_order-b.sort_order) || (a.id-b.id); });
  var isRoot = (parentId === null);
  var h = '<ul class="ul-dept-ul'+(isRoot?' ul-dept-root':'')+'">';
  kids.forEach(function(n){
    var sel = selMap[n.id];
    var checked = sel ? ' checked' : '';
    var incSub = sel ? (sel.include_sub !== false) : true;
    h += '<li class="ul-dept-li">'
       + '<label class="ul-dept-lab"><input type="checkbox" class="ul-dept-cb" data-unit="'+unitKey+'" data-id="'+n.id+'"'+checked+'> '+esc(n.name)+'</label>';
    if(n.has_children){
      h += '<label class="ul-sub-lab" style="'+(sel?'':'display:none;')+'">'
         + '<input type="checkbox" class="ul-sub-cb" data-unit="'+unitKey+'" data-id="'+n.id+'"'+(incSub?' checked':'')+'> 含子部門</label>';
    }
    h += deptTreeHtml(nodes, n.id, unitKey, selMap);
    h += '</li>';
  });
  h += '</ul>';
  return h;
}
/** 生產課「製程大類負荷」白名單勾選清單（第四步），只出現在 prod 單位的區塊下方 */
function renderProdTypeChecklist(){
  if (!ALL_PROCESS_TYPES.length) return '';
  var h = '<div class="ul-ptype-box"><div class="ul-excl-h">要列入「製程大類負荷」的類別'
        + ' <span class="hint">（不勾任何一項＝全部顯示）</span></div><div class="ul-ptype-list">';
  ALL_PROCESS_TYPES.forEach(function(pt){
    var checked = (PPT_WORK.indexOf(Number(pt.process_type_id)) >= 0) ? ' checked' : '';
    h += '<label class="ul-sub-lab"><input type="checkbox" class="ul-ptype-cb" value="'+pt.process_type_id+'"'+checked+'> '+esc(pt.process_type)+'</label>';
  });
  h += '</div></div>';
  return h;
}
function renderSetDept(){
  var h = '<div class="ul-dept-grid">';
  UNIT_KEYS.forEach(function(k){
    h += '<div class="ul-dept-unit"><div class="ul-dept-unit-h"><i class="fa '+(UNIT_ICONS[k]||'fa-circle')+'"></i> '+esc(UNIT_LABELS[k]||k)+'</div>';
    h += deptTreeHtml(DEPT_NODES, null, k, DEPT_WORK[k]||{});
    if (k === 'prod') h += renderProdTypeChecklist();
    h += '<div class="ul-excl-box"><div class="ul-excl-h">排除職位 <span class="hint">（逐人負荷明細不列出這些職位的人，不影響彙總統計）</span></div>'
       + '<div class="ul-excl-list" data-unit="'+k+'"><span class="ul-empty-hint" style="padding:0;">載入中…</span></div></div>';
    h += '</div>';
  });
  h += '</div>';
  $('#setDeptBody').html(h);
  UNIT_KEYS.forEach(function(k){ refreshExclPositions(k); });
}
/** 依某單位目前工作中的部門勾選狀態，即時向後端要候選職位清單並重畫排除職位勾選框
    （第四步）——打字搬部門或改「含子部門」都要重抓，因為候選範圍可能變了。 */
function refreshExclPositions(unitKey){
  var cfg = Object.keys(DEPT_WORK[unitKey]||{}).map(function(id){
    return { dept_id: parseInt(id,10), include_sub: (DEPT_WORK[unitKey][id].include_sub ? 1 : 0) };
  });
  var $box = $('.ul-excl-list[data-unit="'+unitKey+'"]');
  if (!$box.length) return;
  if (!cfg.length){ $box.html('<span class="ul-empty-hint" style="padding:0;">（請先勾選部門）</span>'); return; }
  $.get(UL_API, {action:'positions_for_unit', unit:unitKey, dept_cfg:JSON.stringify(cfg)}, function(r){
    if (!r || !r.ok){ $box.html('<span class="ul-empty-hint" style="padding:0;">載入失敗</span>'); return; }
    var positions = r.positions || [];
    if (!positions.length){ $box.html('<span class="ul-empty-hint" style="padding:0;">目前範圍內查無任何職位可排除</span>'); return; }
    var sel = EXCL_WORK[unitKey] || [];
    var h = '';
    positions.forEach(function(p){
      var checked = sel.indexOf(Number(p.position_id)) >= 0 ? ' checked' : '';
      h += '<label class="ul-sub-lab"><input type="checkbox" class="ul-excl-cb" data-unit="'+unitKey+'" value="'+p.position_id+'"'+checked+'> '+esc(p.position_name)+'</label>';
    });
    $box.html(h);
  }, 'json').fail(function(){ $box.html('<span class="ul-empty-hint" style="padding:0;">載入失敗</span>'); });
}
$(document).on('change', '.ul-dept-cb', function(){
  var unit = $(this).data('unit'), id = $(this).data('id'), on = this.checked;
  if(!DEPT_WORK[unit]) DEPT_WORK[unit] = {};
  if(on){ if(!DEPT_WORK[unit][id]) DEPT_WORK[unit][id] = {include_sub:true}; }
  else { delete DEPT_WORK[unit][id]; }
  $(this).closest('li').children('.ul-sub-lab').toggle(on);
  refreshExclPositions(unit);
});
$(document).on('change', '.ul-sub-cb', function(){
  var unit = $(this).data('unit'), id = $(this).data('id');
  if(DEPT_WORK[unit] && DEPT_WORK[unit][id]) DEPT_WORK[unit][id].include_sub = this.checked;
  refreshExclPositions(unit);
});
$(document).on('change', '.ul-ptype-cb', function(){
  var id = parseInt($(this).val(), 10), idx = PPT_WORK.indexOf(id);
  if (this.checked) { if (idx < 0) PPT_WORK.push(id); }
  else { if (idx >= 0) PPT_WORK.splice(idx, 1); }
});
$(document).on('change', '.ul-excl-cb', function(){
  var unit = $(this).data('unit'), id = parseInt($(this).val(), 10);
  if (!EXCL_WORK[unit]) EXCL_WORK[unit] = [];
  var idx = EXCL_WORK[unit].indexOf(id);
  if (this.checked) { if (idx < 0) EXCL_WORK[unit].push(id); }
  else { if (idx >= 0) EXCL_WORK[unit].splice(idx, 1); }
});

function renderSetThresholds(){
  var h = '';
  UNIT_KEYS.forEach(function(k){
    var defs = THRESH_DEFAULTS[k] || {};
    h += '<div class="ul-thr-unit"><div class="ul-thr-h"><i class="fa '+(UNIT_ICONS[k]||'fa-circle')+'"></i> '+esc(UNIT_LABELS[k]||k)+'</div>';
    Object.keys(defs).forEach(function(mk){
      var def = defs[mk];
      var val = (THRESH_WORK[k]||{})[mk];
      if(val === undefined) val = def.value;
      var isDefault = Number(val) === Number(def.value);
      // 2026-10-07 第四步：每個門檻旁補一個「不需要門檻（僅供參考）」checkbox；勾起來時
      // 數字輸入框改 readonly＋反灰（不是 disabled——disabled 的輸入框使用者體感上更像
      // 「存不了」，readonly 單純防手誤，取消勾選立刻就能接著改原來的數字，值全程都還在）。
      var noThr = !!((NOTHR_WORK[k]||{})[mk]);
      h += '<div class="ul-thr-row"><label>'+esc(def.label)+'</label>'
         + '<input type="number" step="any" class="form-control input-sm ul-thr-in" data-unit="'+k+'" data-key="'+mk+'" value="'+val+'"'
         + (noThr ? ' readonly style="opacity:.55;"' : '') + '>'
         + '<span class="ul-thr-def"'+(isDefault?'':' style="display:none;"')+'>（預設值）</span>'
         + '<label class="ul-nothr-lab"><input type="checkbox" class="ul-nothr-cb" data-unit="'+k+'" data-key="'+mk+'"'+(noThr?' checked':'')+'> 不需要門檻（僅供參考）</label>'
         + '</div>';
    });
    h += '</div>';
  });
  $('#setThrBody').html(h);
}
$(document).on('input', '.ul-thr-in', function(){
  var k = $(this).data('unit'), mk = $(this).data('key');
  if(!THRESH_WORK[k]) THRESH_WORK[k] = {};
  THRESH_WORK[k][mk] = this.value;
  var def = (THRESH_DEFAULTS[k]||{})[mk];
  var isDefault = def && Number(this.value) === Number(def.value);
  $(this).siblings('.ul-thr-def').toggle(!!isDefault);
});
$(document).on('change', '.ul-nothr-cb', function(){
  var k = $(this).data('unit'), mk = $(this).data('key');
  if(!NOTHR_WORK[k]) NOTHR_WORK[k] = {};
  NOTHR_WORK[k][mk] = this.checked;
  var $input = $(this).closest('.ul-thr-row').find('.ul-thr-in');
  if (this.checked) $input.prop('readonly', true).css('opacity', '.55');
  else $input.prop('readonly', false).css('opacity', '');
});

function openSettings(){
  if(!CAN_ADMIN) return;
  $.get(UL_API, {action:'settings_get'}, function(r){
    if(!r || !r.ok){ alert((r&&r.error)||'設定載入失敗'); return; }
    THRESH_DEFAULTS = r.threshold_defaults || {};
    ALL_PROCESS_TYPES = r.process_types || [];
    PPT_WORK = (r.prod_process_types||[]).map(Number);
    DEPT_WORK = {};
    UNIT_KEYS.forEach(function(k){
      var map = {};
      (r.dept_cfg[k]||[]).forEach(function(c){ map[c.dept_id] = {include_sub: !!c.include_sub}; });
      DEPT_WORK[k] = map;
    });
    EXCL_WORK = {};
    UNIT_KEYS.forEach(function(k){ EXCL_WORK[k] = ((r.exclude_positions||{})[k]||[]).map(Number); });
    THRESH_WORK = {}; NOTHR_WORK = {};
    UNIT_KEYS.forEach(function(k){
      THRESH_WORK[k] = {}; NOTHR_WORK[k] = {};
      Object.keys(THRESH_DEFAULTS[k]||{}).forEach(function(mk){
        var leaf = (r.thresholds[k]||{})[mk];
        var def = THRESH_DEFAULTS[k][mk];
        THRESH_WORK[k][mk] = thrLeafNum(leaf, def.value);
        NOTHR_WORK[k][mk] = thrLeafNoThr(leaf, def.no_threshold);
      });
    });
    ensureDeptNodes(function(){
      renderSetDept();
      renderSetThresholds();
      setSubTab('dept');
      openMask('setMask');
    });
  }, 'json').fail(function(xhr){
    var r = xhr.responseJSON;
    alert((r&&r.error) || ('設定載入失敗：HTTP '+xhr.status));
  });
}
function setSubTab(name){
  $('.ul-set-tab').removeClass('active');
  $('.ul-set-tab[data-sub="'+name+'"]').addClass('active');
  $('#setDeptPane').toggle(name==='dept');
  $('#setThrPane').toggle(name==='thr');
}
$(document).on('click', '.ul-set-tab', function(){ setSubTab($(this).data('sub')); });
$('#btnSetting').on('click', openSettings);

$('#btnSetSave').on('click', function(){
  var deptCfg = {};
  UNIT_KEYS.forEach(function(k){
    deptCfg[k] = Object.keys(DEPT_WORK[k]||{}).map(function(id){
      return {dept_id: parseInt(id,10), include_sub: (DEPT_WORK[k][id].include_sub ? 1 : 0)};
    });
  });
  var thresholds = {};
  UNIT_KEYS.forEach(function(k){
    thresholds[k] = {};
    Object.keys(THRESH_WORK[k]||{}).forEach(function(mk){
      thresholds[k][mk] = Number(THRESH_WORK[k][mk]);
    });
  });
  var noThreshold = {};
  UNIT_KEYS.forEach(function(k){
    noThreshold[k] = {};
    Object.keys(NOTHR_WORK[k]||{}).forEach(function(mk){ noThreshold[k][mk] = NOTHR_WORK[k][mk] ? 1 : 0; });
  });
  var exclPositions = {};
  UNIT_KEYS.forEach(function(k){ exclPositions[k] = (EXCL_WORK[k]||[]).slice(); });

  $.post(UL_API, {
    action:'settings_save', csrf:UL_CSRF,
    dept_cfg:JSON.stringify(deptCfg), thresholds:JSON.stringify(thresholds),
    prod_process_types:JSON.stringify(PPT_WORK||[]),
    exclude_positions:JSON.stringify(exclPositions),
    no_threshold:JSON.stringify(noThreshold)
  }, function(r){
    if(!r || !r.ok){ alert((r&&r.error)||'儲存失敗'); return; }
    showToast('設定已儲存');
    closeMask('setMask');
    UL_THR = null; // 部門範圍／門檻可能都變了，逐人明細表的標紅門檻快取要跟著失效
    UNIT_KEYS.forEach(function(k){ UNIT_LOADED[k] = false; }); // 所有分頁快取一併失效（第八步）
    load();
    loadTrend(); // 部門範圍也會改變趨勢圖吃的人員 id，連帶重算
    // 正在看哪個單位的詳細分頁，部門範圍一改，那個分頁也要連帶重算（不能留著設定前的舊人員清單）；
    // 其餘分頁交給 load() 成功後的 schedulePreload() 背景依序補齊。
    var t = $('.ul-tab-btn.active').data('tab');
    if (UNIT_LOADERS[t]) loadUnitTab(t);
  }, 'json').fail(function(xhr){
    var r = xhr.responseJSON;
    alert((r&&r.error) || ('儲存失敗：HTTP '+xhr.status));
  });
});

<?php if ($canView): ?>
fillIdx();
load();
loadTrend();
<?php endif; ?>
</script>
</body>
</html>
