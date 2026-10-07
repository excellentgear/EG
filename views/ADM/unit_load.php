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

/* 分頁容器（總覽是唯一有內容的，其餘先放空殼） */
.ul-tab-pane { }
.ul-placeholder { background:#fff; border:1px dashed var(--line); border-radius:8px; padding:40px 20px;
                  text-align:center; color:var(--muted); font-size:13px; }
.ul-placeholder i { font-size:26px; display:block; margin-bottom:10px; color:var(--green); }

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

  <!-- ── 五個單位的詳細資料分頁（下一階段補內容，本階段先留空殼） ─ -->
  <?php foreach ($UNIT_KEYS as $k): ?>
  <div class="ul-tab-pane" id="tab-<?= ulEsc($k) ?>" data-unit="<?= ulEsc($k) ?>" style="display:none">
    <div class="ul-placeholder">
      <i class="fa <?= ulEsc($UNIT_ICONS[$k] ?? 'fa-circle') ?>"></i>
      <?= ulEsc($UNIT_LABELS[$k] ?? $k) ?>的詳細資料即將顯示（下一階段補上）
    </div>
  </div>
  <?php endforeach; ?>

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
        <li>五個單位各自的<b>詳細資料分頁</b>（逐人明細、趨勢圖等）將於下一階段補上，本階段先看得到分頁、按得下去，內容稍後顯示。</li>
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
$(document).on('click', '.ul-tab-btn', function(){
  var t = $(this).data('tab');
  $('.ul-tab-btn').removeClass('active'); $(this).addClass('active');
  $('.ul-tab-pane').hide(); $('#tab-'+t).show();
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
$('#btnReload').on('click', load);

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
    load();
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
