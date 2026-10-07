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

// 單位鍵與對照標籤：鍵一律呼叫 ul_unit_keys()（唯一登記處），中文標籤是本模組固定的五個單位。
$UNIT_KEYS = ul_unit_keys();
$UNIT_LABELS = ['design' => '設計課', 'sales' => '業務課', 'pm' => '生管', 'prod' => '生產課', 'qc' => '品管'];
$UNIT_ICONS  = ['design' => 'fa-pencil', 'sales' => 'fa-handshake-o', 'pm' => 'fa-sitemap', 'prod' => 'fa-industry', 'qc' => 'fa-check-square-o'];
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

/* 自動分析清單 */
.ins-list .ins-item { padding:8px 10px; border-left:4px solid var(--green); background:#F6F9F0;
                       border-radius:4px; margin-bottom:6px; font-size:12.5px; line-height:1.6; }
.ins-list .ins-item.bad { border-left-color:var(--coral); background:#FDF1EF; }
.ins-list .ins-item.warn { border-left-color:var(--green-d); background:#F0F4E6; }
.ins-list .ins-item b { color:var(--ink); }

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
.ul-empty-hint { color:var(--muted); font-size:12.5px; padding:16px 0; text-align:center; }

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

  <!-- ── 六個分頁：總覽／設計課／業務課／生管／生產課／品管 ──────── -->
  <div class="ul-tabs">
    <button type="button" class="ul-tab-btn active" data-tab="overview"><i class="fa fa-bar-chart"></i> 總覽</button>
    <?php foreach ($UNIT_KEYS as $k): ?>
    <button type="button" class="ul-tab-btn" data-tab="<?= ulEsc($k) ?>">
      <i class="fa <?= ulEsc($UNIT_ICONS[$k] ?? 'fa-circle') ?>"></i> <?= ulEsc($UNIT_LABELS[$k] ?? $k) ?>
    </button>
    <?php endforeach; ?>
  </div>

  <!-- ── 總覽分頁（本階段唯一有實際內容的分頁） ──────────── -->
  <div class="ul-tab-pane" id="tab-overview">

    <div id="noteBar"></div>
    <div id="kpiRow" class="kpi-row"></div>

    <div class="sec" id="secInsight">
      <h4><i class="fa fa-lightbulb-o" style="color:var(--coral);"></i> 自動分析
        <span class="hint">把各單位當前數字跟門檻與上一期比，每一條都附具體數字</span>
      </h4>
      <div id="insightList" class="ins-list"></div>
    </div>

  </div>

  <!-- ── 設計課分頁 ─────────────────────────────────────── -->
  <div class="ul-tab-pane" id="tab-design" data-unit="design" style="display:none">
    <div id="dsgNote"></div>
    <div id="dsgKpi" class="kpi-row"></div>

    <div class="sec">
      <h4><i class="fa fa-user-circle-o" style="color:var(--green-d);"></i> 審圖人統計
        <span class="hint">僅設計課恰好 2 人時可判定（制度上按審圖視為「對方審圖」）</span></h4>
      <div id="dsgReviewer"></div>
    </div>

    <div class="sec">
      <h4><i class="fa fa-line-chart" style="color:var(--green-d);"></i> 每日完成數（已按轉生管）趨勢</h4>
      <div class="chart-box" id="dsgDailyChart" style="height:320px;"></div>
    </div>

    <div class="sec">
      <h4><i class="fa fa-tags" style="color:var(--green-d);"></i> 訂單標籤分布</h4>
      <div id="dsgTags"></div>
    </div>

    <div class="sec">
      <h4><i class="fa fa-comments-o" style="color:var(--green-d);"></i> 設計備註問題與回覆工作天
        <span class="hint">開放問題總數為現況快照；平均回覆工作天為本期內完成回覆者</span></h4>
      <div id="dsgNoteStats"></div>
    </div>

    <div class="sec">
      <h4><i class="fa fa-table" style="color:var(--green-d);"></i> 逐人負荷明細</h4>
      <div class="table-responsive"><table class="table table-striped" id="dsgTable">
        <thead><tr><th>部門</th><th>職稱</th><th>姓名</th><th>批圖中</th><th>已按審圖</th><th>已按轉生管</th>
          <th>問題訂單數</th><th>平均出圖工作天</th></tr></thead>
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
      <div class="table-responsive"><table class="table table-striped" id="salTable">
        <thead><tr><th>部門</th><th>職稱</th><th>姓名</th><th>報價單數</th><th>報價明細筆數</th>
          <th>訂單追蹤筆數</th><th>待回覆問題筆數</th></tr></thead>
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

    <div class="sec">
      <h4><i class="fa fa-sitemap" style="color:var(--green-d);"></i> 製程大類負荷
        <span class="hint">目前進行中（尚未移轉）的製程逐大類統計，非本期累積，不受期間篩選影響</span></h4>
      <div class="table-responsive"><table class="table table-striped" id="prodTypeTable">
        <thead><tr><th>製程大類</th><th>未指派機台</th><th>已指派機台</th><th>合計</th></tr></thead>
        <tbody></tbody>
      </table></div>
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

    <div class="sec">
      <h4><i class="fa fa-cube" style="color:var(--green-d);"></i> 包裝負荷</h4>
      <div id="prodPackingKpi" class="kpi-row"></div>
      <div class="chart-box" id="prodPackingChart" style="height:280px;"></div>
      <div id="prodPackingExtremes"></div>
    </div>
  </div>

  <!-- ── 品管分頁 ───────────────────────────────────────── -->
  <div class="ul-tab-pane" id="tab-qc" data-unit="qc" style="display:none">
    <div id="qcNote"></div>

    <div class="sec">
      <h4><i class="fa fa-list-ol" style="color:var(--green-d);"></i> 目前待驗佇列
        <span class="hint">現況快照，不受期間篩選影響</span></h4>
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
        <li>把<b>設計課、業務課、生管、生產課、品管</b>五個單位目前的工作量各自整理成一張 KPI 卡與一頁明細，
            讓主管一眼看出哪個單位現在比較吃緊。</li>
        <li><b>總覽分頁</b>：五張 KPI 卡＋整頁自動分析（哪個單位的哪個指標偏高、跟上一期比有沒有變嚴重）。</li>
        <li>五個單位各自的<b>詳細資料分頁</b>：逐人明細、趨勢圖、各類清單，點分頁鈕或 KPI 卡的「查看明細 »」即可切入。
            生產課多了「包裝負荷」子區塊（待包裝筆數／每日完成數／平均處理工作天／處理最久與最快前5筆），
            品管多了「目前待驗佇列」（依製程分組的現況筆數）。</li>
      </ul>
      <h4>操作步驟</h4>
      <ol>
        <li>選「年度 → 期間（月／季／半年／整年）→ 要看哪一期」，比較基準可切「上一期」或「去年同期」。</li>
        <li>按「重新計算」。</li>
        <li>按某張 KPI 卡右下角的「查看明細 »」或上方分頁鈕，切到該單位的詳細分頁（本階段為佔位畫面）。</li>
      </ol>
      <h4>重要行為／常見疑問</h4>
      <ul>
        <li><b>卡片背景變成紅色、並標「⚠ 負荷過重」</b>：代表自動分析偵測到這個單位有指標超過設定的門檻。</li>
        <li><b>生管沒有逐人明細</b>：製令資料表（bom_ing）沒有「這張製令由哪個生管負責」的欄位，所以生管只有
            全公司現況統計，逐人明細恆為空，這是資料結構的既有限制。</li>
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
function kpiCard(key, title, peopleCount, metricsHtml, bad){
  var icon = UNIT_ICONS[key] || 'fa-circle';
  return '<div class="kpi-card'+(bad?' ul-overload':'')+'" data-unit="'+key+'">'
       + (bad ? '<div class="kpi-bad-tag"><i class="fa fa-exclamation-triangle"></i>負荷過重</div>' : '')
       + '<div class="k-lab"><i class="fa '+icon+'"></i> '+esc(title)+'　<span class="k-ppl">('+nf(peopleCount)+' 人)</span></div>'
       + metricsHtml
       + '<div class="k-more"><a href="javascript:void(0)" class="ul-go-tab" data-tab="'+key+'">查看明細 »</a></div>'
       + '</div>';
}
function metricLine(label, cur, cmp, fmt){
  fmt = fmt || nf;
  return '<div class="k-metric"><span class="k-metric-lab">'+esc(label)+'</span>'
       + '<span class="k-metric-val">'+fmt(cur)+' '+deltaHtml(cur, cmp, fmt)+'</span></div>';
}

/* ── 總覽：是否「負荷過重」目前只能靠自動分析（insights）反推——overview 這個 action
   只回原始數字，不像 data_design 等明細 action 那樣逐指標附 overload 旗標。
   做法：掃一遍 insights，level==='bad' 且標題以該單位中文名稱開頭的，就把整張卡標紅；
   這是暫時的推算方式，等下一階段做詳細分頁時，若總覽也要逐指標標紅，需要 API 另外補一個
   欄位（不要在前端重算門檻邏輯，那是 unit_load_lib.php 的 ul_is_overload() 專責的事）。 */
function renderOverview(d){
  var badUnit = {};
  (d.insights||[]).forEach(function(it){
    if(it.level !== 'bad') return;
    Object.keys(UNIT_LABELS).forEach(function(k){
      if(String(it.title||'').indexOf(UNIT_LABELS[k]) === 0) badUnit[k] = true;
    });
  });

  var h = '';
  var dc = d.design.cur, dp = d.design.cmp;
  h += kpiCard('design', UNIT_LABELS.design, d.design.people_count,
       metricLine('批圖中', dc.drawing_wip, dp.drawing_wip)
     + metricLine('繪圖平均工作天', dc.avg_draw_workdays, dp.avg_draw_workdays, nf1)
     + metricLine('設計備註待回覆訂單', dc.issue_orders, dp.issue_orders),
     !!badUnit.design);

  var sc = d.sales.cur, sp = d.sales.cmp;
  h += kpiCard('sales', UNIT_LABELS.sales, d.sales.people_count,
       metricLine('本期報價單', sc.quote_count, sp.quote_count)
     + metricLine('待回覆問題', sc.open_issue_count, sp.open_issue_count),
     !!badUnit.sales);

  var pc = d.pm.cur, pp = d.pm.cmp;
  h += kpiCard('pm', UNIT_LABELS.pm, d.pm.people_count,
       metricLine('委外加工中', pc.outsource_wip, pp.outsource_wip)
     + metricLine('廠內加工中', pc.internal_wip, pp.internal_wip)
     + metricLine('待對帳筆數', pc.pending_recon_lines, pp.pending_recon_lines),
     !!badUnit.pm);

  var prod = d.prod;
  h += kpiCard('prod', UNIT_LABELS.prod, prod.people_count,
       metricLine('未指派機台', prod.unassigned_total, null)
     + metricLine('已指派機台', prod.assigned_total, null)
     + metricLine('未正式指派卻已報工', prod.untracked_total, null)
     + metricLine('平均架機時間（分）', prod.avg_setup_minutes, null, nf1),
     !!badUnit.prod);

  var qc = d.qc;
  h += kpiCard('qc', UNIT_LABELS.qc, qc.people_count,
       metricLine('待驗平均等待工作天', qc.avg_wait_workdays, null, nf1)
     + metricLine('平均NG比例', qc.avg_ng_rate!=null ? qc.avg_ng_rate*100 : null, null, pct1)
     + metricLine('脫離流程補檢驗', qc.adhoc_total, null),
     !!badUnit.qc);

  $('#kpiRow').html(h);
  renderInsights(d.insights || []);
}
function renderInsights(list){
  if(!list.length){
    $('#insightList').html('<div style="color:var(--muted);font-size:12.5px;">目前沒有需要特別留意的事項。</div>');
    return;
  }
  var h = '';
  list.forEach(function(it){
    var lv = it.level || 'info';
    var icon = lv==='bad' ? 'fa-exclamation-triangle' : (lv==='warn' ? 'fa-flag' : 'fa-info-circle');
    h += '<div class="ins-item '+esc(lv)+'"><i class="fa '+icon+'"></i> <b>'+esc(it.title)+'</b>　'+esc(it.detail)+'</div>';
  });
  $('#insightList').html(h);
}

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
/** 小型統計卡（重用 .kpi-card 版面，但沒有「查看明細 »」連結——本身就在明細分頁上） */
function statTile(label, cur, cmp, cmpLabel, fmt, bad){
  fmt = fmt || nf;
  return '<div class="kpi-card'+(bad?' ul-overload':'')+'">'
       + (bad ? '<div class="kpi-bad-tag"><i class="fa fa-exclamation-triangle"></i>負荷過重</div>' : '')
       + '<div class="k-lab">'+esc(label)+'</div>'
       + '<div class="k-val">'+fmt(cur)+'</div>'
       + (cmp===undefined ? '' : '<div class="k-cmp">較'+esc(cmpLabel||'上一期')+'：'+deltaHtml(cur, cmp, fmt)+'</div>')
       + '</div>';
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
function ensureThresholds(cb){
  if (UL_THR){ cb(); return; }
  $.get(UL_API, {action:'settings_get'}, function(r){
    var flat = {};
    if (r && r.ok){
      Object.keys(r.threshold_defaults||{}).forEach(function(unit){
        Object.keys(r.threshold_defaults[unit]).forEach(function(mk){
          var def = r.threshold_defaults[unit][mk].value;
          var cur = (r.thresholds[unit]||{})[mk];
          flat[unit+'.'+mk] = Number((cur===undefined||cur===null) ? def : cur);
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
/* 包裝待包裝筆數／品管目前待驗佇列總筆數：這兩個是本次新增的指標，unit_load_lib.php 的
   ul_threshold_defaults() 尚未收錄對應門檻鍵，暫不等 lib 端支援——先用這裡的合理預設值，
   之後若要讓管理員也能在「設定」跳窗調整，再補進 ul_threshold_defaults() 並改走 UL_THR。 */
var UL_LOCAL_THR_DEFAULT = { packing_pending: 200, qc_pending_total: 150 };
function localThr(key, fallbackKey){
  if (UL_THR && UL_THR[key] !== undefined) return Number(UL_THR[key]);
  return Number(UL_LOCAL_THR_DEFAULT[fallbackKey]);
}
/** 把 YYYY-MM-DD HH:MM:SS 或純日期字串只取日期部分，空值印破折號 */
function dOnly(s){ return s ? String(s).substring(0,10) : '—'; }
/** 待驗/包裝「最長/最短前5筆」小表格，withProcess=true 時多印一欄製程名稱 */
function extremesTable(rows, withProcess){
  rows = rows || [];
  if (!rows.length) return '<div class="ul-empty-hint" style="padding:6px 0;">（無資料）</div>';
  var h = '<table class="table table-condensed" style="margin-bottom:0;"><thead><tr><th>製令</th>'
        + (withProcess ? '<th>製程</th>' : '') + '<th>工作天</th><th>進入</th><th>完成</th></tr></thead><tbody>';
  rows.forEach(function(r){
    h += '<tr><td>'+esc(r.bom)+'</td>'
       + (withProcess ? '<td>'+esc(r.process_name)+'</td>' : '')
       + '<td>'+nf1(r.workdays)+'</td><td>'+esc(dOnly(r.enter_at))+'</td><td>'+esc(dOnly(r.finish_at))+'</td></tr>';
  });
  h += '</tbody></table>';
  return h;
}

/* ── 設計課分頁 ─────────────────────────────────────── */
function loadDesign(){
  var params = $.extend({action:'data_design'}, periodParams());
  ensureThresholds(function(){
    $.get(UL_API, params, function(r){
      if(!r || !r.ok){ alert((r&&r.error)||'載入失敗'); return; }
      renderDesign(r);
      UNIT_LOADED.design = true;
    }, 'json').fail(function(xhr){
      var r = xhr.responseJSON;
      alert((r&&r.error) || ('載入失敗：HTTP '+xhr.status));
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
  h += statTile('新案件', s.new_case, c.new_case, L, nf, false);
  h += statTile('問題訂單數', s.issue_orders, c.issue_orders, L, nf, !!ov.issue_orders);
  h += statTile('平均出圖工作天', s.avg_draw_workdays, c.avg_draw_workdays, L, nf1, !!ov.avg_draw_workdays);
  $('#dsgKpi').html(h);

  renderDesignReviewer(d.reviewer, d.by_person);
  renderDesignDailyChart(d.daily_pmget, d.by_person);
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
function renderDesignDailyChart(rows, byPerson){
  rows = rows || [];
  if (!rows.length){
    $('#dsgDailyChart').html(emptyHint('本期沒有已按轉生管的資料。'));
    return;
  }
  var dates = [], seen = {};
  rows.forEach(function(r){ if(!seen[r.d]){ seen[r.d]=1; dates.push(r.d); } });
  dates.sort();
  var byAte = {};
  rows.forEach(function(r){
    var k = String(r.ate);
    if (!byAte[k]) byAte[k] = {};
    byAte[k][r.d] = r.c;
  });
  var series = Object.keys(byAte).map(function(k){
    return { name: nameOf(byPerson, k), data: dates.map(function(d){ return byAte[k][d] || 0; }) };
  });
  chart('dsgDailyChart', {
    chart: { type:'line', height:320 },
    xAxis: { categories: dates },
    yAxis: { title:{text:null}, allowDecimals:false, min:0 },
    tooltip: { shared:true },
    series: series
  });
}
function renderDesignTags(rows){
  rows = rows || [];
  if (!rows.length){ $('#dsgTags').html(emptyHint('本期沒有標籤資料。')); return; }
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
function loadSales(){
  var params = $.extend({action:'data_sales'}, periodParams());
  ensureThresholds(function(){
    $.get(UL_API, params, function(r){
      if(!r || !r.ok){ alert((r&&r.error)||'載入失敗'); return; }
      renderSales(r);
      UNIT_LOADED.sales = true;
    }, 'json').fail(function(xhr){
      var r = xhr.responseJSON;
      alert((r&&r.error) || ('載入失敗：HTTP '+xhr.status));
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
function loadPm(){
  var params = $.extend({action:'data_pm'}, periodParams());
  $.get(UL_API, params, function(r){
    if(!r || !r.ok){ alert((r&&r.error)||'載入失敗'); return; }
    renderPm(r);
    UNIT_LOADED.pm = true;
  }, 'json').fail(function(xhr){
    var r = xhr.responseJSON;
    alert((r&&r.error) || ('載入失敗：HTTP '+xhr.status));
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
function loadProd(){
  var params = $.extend({action:'data_prod'}, periodParams());
  ensureThresholds(function(){
    $.get(UL_API, params, function(r){
      if(!r || !r.ok){ alert((r&&r.error)||'載入失敗'); return; }
      renderProd(r);
      UNIT_LOADED.prod = true;
    }, 'json').fail(function(xhr){
      var r = xhr.responseJSON;
      alert((r&&r.error) || ('載入失敗：HTTP '+xhr.status));
    });
  });
}
function renderProd(d){
  $('#prodNote').html(d.note ? '<div class="ul-unit-note"><i class="fa fa-exclamation-circle"></i> '+esc(d.note)+'</div>' : '');
  var ov = d.overload || {};

  renderProdTypeTable(d.by_process_type);
  renderProdUntracked(d.untracked, !!ov.untracked_count);
  renderProdDailyChart(d.daily_output);
  renderProdTimeStats(d.setup, d.production, d.per_capita, !!ov.avg_setup_minutes);
  renderProdPacking(d.packing);
}
function renderProdTypeTable(rows){
  rows = rows || [];
  if (!rows.length){
    $('#prodTypeTable tbody').html('<tr><td colspan="4" style="text-align:center;color:var(--muted);">目前沒有進行中的製程。</td></tr>');
    return;
  }
  var h = '';
  rows.forEach(function(r){
    h += '<tr><td>'+esc(r.process_type_name)+'</td>'
       + '<td'+cellBadCls('prod','unassigned',r.unassigned)+'>'+nf(r.unassigned)+'</td>'
       + '<td>'+nf(r.assigned)+'</td>'
       + '<td>'+nf(r.total)+'</td></tr>';
  });
  $('#prodTypeTable tbody').html(h);
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
    h += '<div style="font-size:12px;color:var(--muted);margin-bottom:4px;">範例（最多20筆）：</div>';
    h += '<div class="table-responsive"><table class="table table-condensed"><thead><tr><th>製令</th><th>製程</th><th>報工日期</th></tr></thead><tbody>';
    u.examples.forEach(function(r){ h += '<tr><td>'+esc(r.bom)+'</td><td>'+esc(r.process_name)+'</td><td>'+esc(dOnly(r.report_date))+'</td></tr>'; });
    h += '</tbody></table></div>';
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
function renderProdPacking(p){
  p = p || { pending:0, daily:[], avg_workdays:null, longest:[], shortest:[] };
  var bad = Number(p.pending||0) > localThr('prod.packing_pending', 'packing_pending');

  var h = '<div class="kpi-row">';
  h += statTile('待包裝筆數', p.pending, undefined, null, nf, bad);
  h += statTile('平均處理工作天', p.avg_workdays, undefined, null, nf1, false);
  h += '</div>';
  $('#prodPackingKpi').html(h);

  var daily = p.daily || [];
  if (!daily.length){
    $('#prodPackingChart').html(emptyHint('本期沒有包裝完工資料。'));
  } else {
    chart('prodPackingChart', {
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
  $('#prodPackingExtremes').html(h2);
}

/* ── 品管分頁 ───────────────────────────────────────── */
function loadQc(){
  var params = $.extend({action:'data_qc'}, periodParams());
  ensureThresholds(function(){
    $.get(UL_API, params, function(r){
      if(!r || !r.ok){ alert((r&&r.error)||'載入失敗'); return; }
      renderQc(r);
      UNIT_LOADED.qc = true;
    }, 'json').fail(function(xhr){
      var r = xhr.responseJSON;
      alert((r&&r.error) || ('載入失敗：HTTP '+xhr.status));
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
  var h = '<div class="table-responsive"><table class="table table-striped"><thead><tr><th>製程</th><th>筆數</th></tr></thead><tbody>';
  rows.forEach(function(r){ h += '<tr><td>'+esc(r.process_name)+'</td><td>'+nf(r.count)+'</td></tr>'; });
  h += '</tbody></table></div>';
  $('#qcPendingTable').html(h);
}
function renderQcDailyChart(rows){
  rows = rows || [];
  if (!rows.length){ $('#qcDailyChart').html(emptyHint('本期沒有檢驗項目資料。')); return; }
  var dates = [], seen = {};
  rows.forEach(function(r){ if(!seen[r.check_date]){ seen[r.check_date]=1; dates.push(r.check_date); } });
  dates.sort();
  var byProc = {};
  rows.forEach(function(r){
    var k = String(r.process_name);
    if(!byProc[k]) byProc[k] = {};
    byProc[k][r.check_date] = (byProc[k][r.check_date]||0) + Number(r.count||0);
  });
  var series = Object.keys(byProc).map(function(k){
    return { name:k, data: dates.map(function(d){ return byProc[k][d] || 0; }) };
  });
  chart('qcDailyChart', {
    chart: { type:'column', height:320 },
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
var UNIT_LOADERS = { design: loadDesign, sales: loadSales, pm: loadPm, prod: loadProd, qc: loadQc };
var UNIT_LOADED  = { design: false, sales: false, pm: false, prod: false, qc: false };
function loadUnitTab(key){
  if (UNIT_LOADERS[key]) UNIT_LOADERS[key]();
}

/* ── 主流程：載入總覽資料 ───────────────────────────── */
function load(){
  var params = {action:'overview', year:$('#fYear').val(), gran:$('#fGran').val(),
                period_idx:$('#fIdx').val(), cmp:$('#fCmp').val()};
  $.get(UL_API, params, function(r){
    if(!r || !r.ok){ alert((r&&r.error)||'載入失敗'); return; }
    renderOverview(r);
  }, 'json').fail(function(xhr){
    var r = xhr.responseJSON;
    alert((r&&r.error) || ('載入失敗：HTTP '+xhr.status));
  });
}
/* 重新計算：總覽一律重算；目前若正開著某個單位的詳細分頁，那個分頁也要連帶重算
  （鐵律：改了期間篩選，正在看的那個分頁不能還是舊資料）。 */
$('#btnReload').on('click', function(){
  load();
  var t = $('.ul-tab-btn.active').data('tab');
  if (UNIT_LOADERS[t]) loadUnitTab(t);
});

/* ── 設定跳窗：部門範圍設定／門檻設定 ───────────────── */
var DEPT_NODES = null;   // dept_tree 回傳，快取一次即可
var DEPT_WORK  = {};     // 工作中選取狀態：unit -> {deptId:{include_sub:bool}}
var THRESH_WORK = {};    // 工作中門檻值：unit -> key -> value
var THRESH_DEFAULTS = {};

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
function renderSetDept(){
  var h = '';
  UNIT_KEYS.forEach(function(k){
    h += '<div class="ul-dept-unit"><div class="ul-dept-unit-h"><i class="fa '+(UNIT_ICONS[k]||'fa-circle')+'"></i> '+esc(UNIT_LABELS[k]||k)+'</div>';
    h += deptTreeHtml(DEPT_NODES, null, k, DEPT_WORK[k]||{});
    h += '</div>';
  });
  $('#setDeptBody').html(h);
}
$(document).on('change', '.ul-dept-cb', function(){
  var unit = $(this).data('unit'), id = $(this).data('id'), on = this.checked;
  if(!DEPT_WORK[unit]) DEPT_WORK[unit] = {};
  if(on){ if(!DEPT_WORK[unit][id]) DEPT_WORK[unit][id] = {include_sub:true}; }
  else { delete DEPT_WORK[unit][id]; }
  $(this).closest('li').children('.ul-sub-lab').toggle(on);
});
$(document).on('change', '.ul-sub-cb', function(){
  var unit = $(this).data('unit'), id = $(this).data('id');
  if(DEPT_WORK[unit] && DEPT_WORK[unit][id]) DEPT_WORK[unit][id].include_sub = this.checked;
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
      h += '<div class="ul-thr-row"><label>'+esc(def.label)+'</label>'
         + '<input type="number" step="any" class="form-control input-sm ul-thr-in" data-unit="'+k+'" data-key="'+mk+'" value="'+val+'">'
         + '<span class="ul-thr-def"'+(isDefault?'':' style="display:none;"')+'>（預設值）</span>'
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

function openSettings(){
  if(!CAN_ADMIN) return;
  $.get(UL_API, {action:'settings_get'}, function(r){
    if(!r || !r.ok){ alert((r&&r.error)||'設定載入失敗'); return; }
    THRESH_DEFAULTS = r.threshold_defaults || {};
    DEPT_WORK = {};
    UNIT_KEYS.forEach(function(k){
      var map = {};
      (r.dept_cfg[k]||[]).forEach(function(c){ map[c.dept_id] = {include_sub: !!c.include_sub}; });
      DEPT_WORK[k] = map;
    });
    THRESH_WORK = {};
    UNIT_KEYS.forEach(function(k){
      THRESH_WORK[k] = {};
      Object.keys(THRESH_DEFAULTS[k]||{}).forEach(function(mk){
        var cur = (r.thresholds[k]||{})[mk];
        THRESH_WORK[k][mk] = (cur===undefined||cur===null) ? THRESH_DEFAULTS[k][mk].value : cur;
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
  $.post(UL_API, {action:'settings_save', csrf:UL_CSRF, dept_cfg:JSON.stringify(deptCfg), thresholds:JSON.stringify(thresholds)}, function(r){
    if(!r || !r.ok){ alert((r&&r.error)||'儲存失敗'); return; }
    showToast('設定已儲存');
    closeMask('setMask');
    UL_THR = null; // 部門範圍／門檻可能都變了，逐人明細表的標紅門檻快取要跟著失效
    load();
    // 正在看哪個單位的詳細分頁，部門範圍一改，那個分頁也要連帶重算（不能留著設定前的舊人員清單）
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
<?php endif; ?>
</script>
</body>
</html>
