<?php
/**
 * 對帳進度追蹤 — 2026-10-06 新增（使用者交辦）
 *
 * 跟既有 reconcile.php／recon_overview.php（對帳底稿、逐筆核對金額）完全分開：
 * 這一頁管的是「這個月這家客戶/廠商的對帳作業，流程走到哪一關」
 * （處理中→已對帳→已送會計→會計已接收→會計已處理），不碰 acc_recon_sheet 一個欄位。
 *
 * 資料來源：
 *  應收＝acc_ar_summary()（跟 Shipping_Quick.php／reconcile.php 同一套帳款月份口徑）
 *  應付＝bom_ing_transfer_log.bill_ym（跟 Transfer_Log_Analysis.php 同一套廠商結帳日口徑）
 *
 * 角色（module='acc_recon_track'，全新獨立角色，與會計模組的 acc_* 角色分開設定）：
 *   art_admin    本頁管理員（可修改）：任意設定/回復任何狀態、改結帳日、改權限矩陣
 *   art_view_all 本頁管理員（唯讀）：看得到全部，不能按任何按鈕、不能改設定
 *   art_pm / art_sales / art_acc：生管／業務／會計，哪個角色可以按哪個狀態由「設定→角色權限矩陣」決定
 */
ini_set('session.gc_maxlifetime', 43200);
session_set_cookie_params(43200);
session_start();
if (!isset($_SESSION['userName'])) {
    $_SESSION['lastpage'] = "../../views/ACC/recon_track.php";
    header("Location:../../index.php");
    exit;
}
include_once '../../src/common/_config.php';
include_once '../../src/common/DBConnection.php';
include_once '../../src/common/acc_track_lib.php';
include_once '../../src/common/role_features_helper.php';
include_once '../../src/common/org_role_lib.php';

$db  = (new DBConnection())->getPDO();
$uid = (int)($_SESSION['id'] ?? 0);
act_ensure_schema($db);

$perms = act_perms($db, $uid);
$roleBits = [];
if ($perms['canAdmin']) $roleBits[] = '本頁管理員（可修改）';
elseif ($perms['viewAll']) $roleBits[] = '本頁管理員（唯讀）';
if ($perms['isPm'])    $roleBits[] = '生管';
if ($perms['isSales']) $roleBits[] = '業務';
if ($perms['isAcc'])   $roleBits[] = '會計';
$roleLabel = $roleBits ? implode('、', $roleBits) : '無權限';

if (empty($_SESSION['act_csrf'])) $_SESSION['act_csrf'] = bin2hex(random_bytes(16));
$CSRF = $_SESSION['act_csrf'];

$years    = act_years($db);
$thisYear = (int)date('Y');
$defYear  = in_array($thisYear, $years, true) ? $thisYear : (int)$years[0];

function actEsc($v) { return htmlspecialchars((string)$v, ENT_QUOTES, 'UTF-8'); }
?>
<!DOCTYPE html>
<html lang="zh-Hant">
<head>
<meta charset="utf-8">
<meta http-equiv="X-UA-Compatible" content="IE=edge">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>對帳進度追蹤</title>
<link href="../../resource/css/bootstrap.css" rel="stylesheet">
<link href="../../resource/css/font-awesome.css" rel="stylesheet">
<link href="../../resource/css/nprogress.css" rel="stylesheet">
<link href="../../resource/css/custom.css" rel="stylesheet">
<style>
#sidebar-menu { visibility: hidden; }
:root{ --ink:#4A3524; --cream:#FCF7F0; --sand:#F7E0BD; --amber:#F0A24B; --amber-d:#C77C1A;
       --coral:#DD5138; --line:#E4D3BC; --muted:#a08a6f; --brown:#8a5a2b; }
body { background:#F6F1EA; }
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
.btn-danger-o { background:#fff; border:1px solid var(--coral); color:var(--coral); }
.btn-danger-o:hover { background:#FDF2EE; }
.art-bar { display:flex; gap:8px; align-items:center; flex-wrap:wrap; margin-bottom:10px; }
.art-bar label { margin:0; font-size:12px; color:var(--ink); font-weight:600; }
.sec { background:#fff; border:1px solid var(--line); border-radius:8px; padding:12px 14px; margin-bottom:14px; }
.sec h4 { margin:0 0 8px; font-size:16px; color:var(--ink); font-weight:700; display:flex; align-items:center; gap:8px; flex-wrap:wrap; }
.sec h4 .hint { font-size:11px; color:var(--muted); font-weight:normal; }
.sec-tools { margin-left:auto; display:flex; gap:6px; align-items:center; flex-wrap:wrap; }
.side-toggle { display:flex; border:1px solid var(--amber-d); border-radius:16px; overflow:hidden; }
.side-toggle button { border:none; background:#fff; color:var(--amber-d); padding:5px 16px; font-size:13px; font-weight:700; cursor:pointer; }
.side-toggle button.on { background:var(--amber); color:var(--ink); }
.main-tabs { display:flex; gap:6px; margin-bottom:10px; }
.main-tabs button { border:1px solid var(--line); background:#fff; color:#6B4423; padding:6px 16px; border-radius:16px; font-size:13px; font-weight:700; cursor:pointer; }
.main-tabs button.on { background:var(--ink); color:#fff; border-color:var(--ink); }
.art-tabpane { display:none; } .art-tabpane.on { display:block; }
/* 狀態卡片／籤 */
.st-cards { display:flex; gap:8px; flex-wrap:wrap; margin-bottom:12px; }
.st-card { flex:1 1 110px; min-width:110px; background:#fff; border:1px solid var(--line); border-top:3px solid var(--muted);
           border-radius:8px; padding:8px 10px; cursor:pointer; user-select:none; }
.st-card.on { outline:2px solid var(--amber-d); }
.st-card .lab { font-size:11px; color:var(--muted); }
.st-card .val { font-size:22px; font-weight:700; color:var(--ink); }
.st-card[data-st="processing"]   { border-top-color:#a08a6f; }
.st-card[data-st="reconciled"]   { border-top-color:var(--amber); }
.st-card[data-st="sent_to_acc"]  { border-top-color:#C77C1A; }
.st-card[data-st="acc_received"] { border-top-color:#5B8A72; }
.st-card[data-st="acc_done"]     { border-top-color:#2E7D32; }
.badge-st { display:inline-block; border-radius:10px; padding:2px 9px; font-size:11px; font-weight:700; color:#fff; white-space:nowrap; }
.badge-processing   { background:#a08a6f; }
.badge-reconciled   { background:var(--amber); color:#4E2C0B; }
.badge-sent_to_acc   { background:#C77C1A; }
.badge-acc_received { background:#5B8A72; }
.badge-acc_done      { background:#2E7D32; }
.badge-need  { background:var(--coral); color:#fff; border-radius:9px; padding:1px 7px; font-size:10px; }
.badge-nneed { background:#E4D3BC; color:#6B4423; border-radius:9px; padding:1px 7px; font-size:10px; }
table.art-t { width:100%; border-collapse:collapse; font-size:12px; table-layout:fixed; }
table.art-t th, table.art-t td { border:1px solid var(--line); padding:5px 6px; vertical-align:middle; word-break:break-all; line-height:1.5; }
table.art-t th { background:#faf6f0; color:#6B4423; font-weight:700; text-align:center; white-space:nowrap; }
table.art-t td.n { text-align:right; font-variant-numeric:tabular-nums; }
table.art-t td.c { text-align:center; }
table.art-t tbody tr:nth-child(even) { background:#fdfbf8; }
.tbl-wrap { max-height:560px; overflow:auto; border:1px solid var(--line); border-radius:6px; }
.tbl-wrap table.art-t th { position:sticky; top:0; z-index:2; }
.row-btn { font-size:11px; padding:2px 9px; border-radius:10px; margin:1px; }
.icon-btn { border:none; background:none; color:var(--brown); cursor:pointer; font-size:14px; padding:2px 4px; }
.icon-btn:hover { color:var(--coral); }
/* 跳窗 */
.m-mask { position:fixed; inset:0; background:rgba(74,53,36,.45); z-index:10300; display:none; }
.m-win  { background:#fff; border-radius:8px; width:760px; max-width:95vw; margin:4vh auto;
          box-shadow:0 8px 30px rgba(0,0,0,.3); display:flex; flex-direction:column; max-height:92vh; }
.m-win.wide { width:960px; }
.m-head { padding:10px 14px; border-bottom:1px solid var(--line); font-weight:700; color:var(--ink); display:flex; align-items:center; }
.m-head .x { margin-left:auto; cursor:pointer; color:var(--muted); }
.m-body { padding:14px; overflow:auto; }
.m-foot { padding:10px 14px; border-top:1px solid var(--line); text-align:right; }
.help-doc h4 { color:var(--amber-d); font-size:15px; margin:14px 0 6px; }
.help-doc li { margin-bottom:4px; line-height:1.7; }
.matrix-tbl th, .matrix-tbl td { text-align:center; padding:6px; border:1px solid var(--line); }
.matrix-tbl th { background:#faf6f0; }
.err-txt { color:var(--coral); font-size:12px; margin-top:6px; white-space:pre-line; }
.kpi-row { display:flex; gap:10px; flex-wrap:wrap; margin-bottom:12px; }
.kpi-card { flex:1 1 150px; min-width:150px; background:#fff; border:1px solid var(--line); border-top:3px solid var(--amber); border-radius:8px; padding:10px 12px; }
.kpi-card .k-lab { font-size:12px; color:var(--muted); }
.kpi-card .k-val { font-size:22px; font-weight:700; color:var(--ink); line-height:1.2; }
.chart-box { width:100%; height:300px; }
.ins-list { display:flex; flex-direction:column; gap:6px; }
.ins { display:flex; gap:10px; align-items:flex-start; border:1px solid var(--line); border-left-width:4px;
       border-radius:6px; padding:7px 10px; background:#fffdfa; }
.ins .ic { font-size:15px; line-height:20px; width:18px; text-align:center; flex:0 0 18px; }
.ins .bd { flex:1 1 auto; min-width:0; }
.ins .tt { font-weight:700; color:var(--ink); font-size:13px; }
.ins .dt { font-size:12px; color:#6B4423; line-height:1.7; }
.ins-bad  { border-left-color:var(--coral); }  .ins-bad  .ic { color:var(--coral); }
.ins-warn { border-left-color:var(--amber); }  .ins-warn .ic { color:var(--amber-d); }
.ins-good { border-left-color:#4F8A4F; }       .ins-good .ic { color:#2E7D32; }
.ins-info { border-left-color:#B9A78C; }       .ins-info .ic { color:var(--muted); }
</style>
</head>
<body class="nav-sm">
<div class="container body"><div class="main_container">
<?php include '../partPage/sideAndTopBarMenu.html'; ?>
<div class="right_col" role="main">

  <div class="page-title">
    <h3><i class="fa fa-tasks" style="color:var(--amber-d);"></i> 對帳進度追蹤
      <span class="role-tag">目前身分：<?= actEsc($roleLabel) ?></span>
      <a href="recon_overview.php" class="btn btn-xs btn-warm-o" style="font-weight:600;">
        <i class="fa fa-arrow-left"></i> 對帳底稿總覽</a>
      <button id="btnPageHelp" class="page-help-btn" style="margin-left:auto;"><i class="fa fa-question-circle"></i> 使用說明</button>
    </h3>
  </div>

<?php if (!$perms['canView'] && !$perms['canAdmin']): ?>
  <div class="warm-panel" style="color:var(--coral);">您沒有對帳進度追蹤的使用權限，請洽管理員指派角色（模組：acc_recon_track）。</div>
<?php else: ?>

  <div class="art-bar">
    <div class="side-toggle">
      <button id="sideAr" class="on" onclick="setSide('ar')">應收（客戶）</button>
      <button id="sideAp" onclick="setSide('ap')">應付（廠商）</button>
    </div>
    <?php if ($perms['canAdmin']): ?>
      <button class="btn btn-sm btn-warm-o" onclick="openSettingMask()"><i class="fa fa-cog"></i> 設定</button>
    <?php endif; ?>
  </div>

  <div class="main-tabs">
    <button id="tabList" class="on" onclick="switchTab('list')"><i class="fa fa-list"></i> 進度清單</button>
    <button id="tabStat" onclick="switchTab('stat')"><i class="fa fa-bar-chart"></i> 統計分析</button>
  </div>

  <!-- ══════════ 進度清單 ══════════ -->
  <div class="art-tabpane on" id="paneList">
    <div class="sec">
      <h4>
        <span id="listTitle">應收 進度清單</span>
        <span class="hint">依結帳月份顯示，每月第一次開啟時系統會自動建立追蹤列（預設狀態：處理中）</span>
        <div class="sec-tools">
          <label>結帳月份</label>
          <input type="month" id="fBm" class="form-control input-sm" style="width:130px;" onchange="loadList()">
          <input type="text" id="fKw" class="form-control input-sm" style="width:160px;" placeholder="搜尋客戶/廠商名稱…" onkeyup="if(event.key==='Enter')loadList()">
          <button class="btn btn-sm btn-warm-o" onclick="loadList()"><i class="fa fa-search"></i> 查詢</button>
        </div>
      </h4>
      <div class="st-cards" id="stCards"></div>
      <div class="tbl-wrap"><table class="art-t" id="listTbl"><thead></thead><tbody><tr><td colspan="9" class="c">請選擇結帳月份</td></tr></tbody></table></div>
    </div>
  </div>

  <!-- ══════════ 統計分析 ══════════ -->
  <div class="art-tabpane" id="paneStat">
    <div class="sec">
      <h4>
        統計分析
        <span class="hint">工作天數＝含起訖日的工作日數（遇假日不計），分組可在「設定」調整</span>
        <div class="sec-tools">
          <label>年度</label>
          <select id="sYear" class="form-control input-sm" style="width:90px;" onchange="fillIdx();loadStat();"></select>
          <label>粒度</label>
          <select id="sGran" class="form-control input-sm" style="width:80px;" onchange="fillIdx();loadStat();"></select>
          <label>期別</label>
          <select id="sIdx" class="form-control input-sm" style="width:110px;" onchange="loadStat()"></select>
        </div>
      </h4>
      <div class="kpi-row" id="statKpi"></div>
      <h4 style="font-size:14px;">自動分析</h4>
      <div class="ins-list" id="statIns"><div class="hint">載入中…</div></div>
      <h4 style="font-size:14px;margin-top:14px;">工作天數統計</h4>
      <div class="tbl-wrap" style="max-height:220px;"><table class="art-t" id="statGroupTbl"><thead>
        <tr><th>分組</th><th>本期筆數</th><th>平均工作天</th><th>最少</th><th>最多</th></tr></thead><tbody></tbody></table></div>
      <h4 style="font-size:14px;margin-top:14px;">趨勢（本年度各期別平均工作天數）</h4>
      <div class="chart-box" id="chTrend"></div>
    </div>
  </div>

<?php endif; ?>
</div><!-- /right_col -->

<!-- ══════════ 設定跳窗（管理員）══════════ -->
<div class="m-mask" id="setMask">
  <div class="m-win wide">
    <div class="m-head">對帳進度追蹤 — 設定 <span class="x" data-close="setMask">✕</span></div>
    <div class="m-body">
      <h4 style="font-size:14px;">角色權限矩陣 <span class="hint" style="font-size:11px;color:#888;">勾選的角色可以把狀態按鈕按成該列狀態；本頁管理員(art_admin)不受此限，永遠可以任意設定/回復</span></h4>
      <div id="matrixWrap"></div>
      <h4 style="font-size:14px;margin-top:16px;">工作天數統計分組</h4>
      <table class="art-t" id="groupTbl" style="margin-bottom:6px;"><thead>
        <tr><th>名稱</th><th>起點</th><th>終點</th><th style="width:40px;"></th></tr></thead>
        <tbody id="groupTbody" data-eg-row-add="groupAdd" data-eg-row-del="groupDel"></tbody></table>
      <button class="btn btn-sm btn-warm-o" onclick="groupAdd()"><i class="fa fa-plus"></i> 加一組</button>
      <div class="err-txt" id="setErr"></div>
    </div>
    <div class="m-foot">
      <button class="btn btn-default" data-close="setMask">關閉</button>
      <button class="btn btn-warm" onclick="saveSetting()">儲存</button>
    </div>
  </div>
</div>

<!-- ══════════ 結帳日快速修改 ══════════ -->
<div class="m-mask" id="settleMask">
  <div class="m-win" style="width:460px;">
    <div class="m-head">修改結帳日 <span class="x" data-close="settleMask">✕</span></div>
    <div class="m-body">
      <p id="settleTargetName" style="font-weight:700;color:var(--ink);"></p>
      <div class="form-group"><label>結帳模式</label>
        <select id="seMode" class="form-control" onchange="document.getElementById('seDayWrap').style.display=(this.value==='FIXED')?'':'none'">
          <option value="FIXED">固定結帳日</option>
          <option value="EOM">月底結帳</option>
          <option value="VARIABLE">不固定</option>
        </select>
      </div>
      <div class="form-group" id="seDayWrap"><label>固定結帳日（1~31）</label>
        <input type="number" id="seDay" class="form-control" min="1" max="31"></div>
      <div class="err-txt" id="settleErr"></div>
      <p style="font-size:11px;color:#888;">修改會同步寫回<?= '' ?>客戶/廠商主檔，並留下修改紀錄（可在「修改紀錄」查看與快速復原）。</p>
    </div>
    <div class="m-foot">
      <button class="btn btn-default" data-close="settleMask">關閉</button>
      <button class="btn btn-warm" onclick="saveSettle()">儲存</button>
    </div>
  </div>
</div>

<!-- ══════════ 修改紀錄 ══════════ -->
<div class="m-mask" id="logMask">
  <div class="m-win">
    <div class="m-head">修改紀錄 <span class="x" data-close="logMask">✕</span></div>
    <div class="m-body"><div class="tbl-wrap" style="max-height:400px;"><table class="art-t" id="logTbl"><thead>
      <tr><th>欄位</th><th>修改前</th><th>修改後</th><th>修改人</th><th>修改時間</th><th>狀態</th><th>操作</th></tr></thead><tbody></tbody></table></div></div>
    <div class="m-foot"><button class="btn btn-default" data-close="logMask">關閉</button></div>
  </div>
</div>

<!-- ══════════ 臨時結帳調整（應收）══════════ -->
<div class="m-mask" id="exMask">
  <div class="m-win">
    <div class="m-head">臨時結帳調整 <span class="x" data-close="exMask">✕</span></div>
    <div class="m-body">
      <p id="exTargetName" style="font-weight:700;color:var(--ink);"></p>
      <div class="row">
        <div class="col-md-4"><div class="form-group"><label>適用年月</label><input type="month" id="exYm" class="form-control"></div></div>
        <div class="col-md-4"><div class="form-group"><label>調整後結帳日</label><input type="date" id="exDate" class="form-control"></div></div>
        <div class="col-md-4"><div class="form-group"><label>原因</label><input type="text" id="exReason" class="form-control" maxlength="100"></div></div>
      </div>
      <button class="btn btn-sm btn-warm" onclick="exAdd()"><i class="fa fa-plus"></i> 新增</button>
      <div class="err-txt" id="exErr"></div>
      <table class="art-t" style="margin-top:10px;"><thead><tr><th>適用年月</th><th>調整後結帳日</th><th>原因</th><th style="width:40px;"></th></tr></thead>
        <tbody id="exTbody"></tbody></table>
    </div>
    <div class="m-foot"><button class="btn btn-default" data-close="exMask">關閉</button></div>
  </div>
</div>

<!-- ══════════ 使用說明 ══════════ -->
<div class="m-mask" id="helpUseMask">
  <div class="m-win">
    <div class="m-head">對帳進度追蹤 — 使用說明 <span class="x" data-close="helpUseMask">✕</span></div>
    <div class="m-body help-doc">
      <h4>這頁在做什麼</h4>
      <p>追蹤「這個月這家客戶/廠商的對帳作業，流程走到哪一關」——處理中 → 已對帳 → 已送會計 → 會計已接收 → 會計已處理。
      跟對帳底稿（reconcile.php，逐筆核對金額）完全分開，這裡只是流程進度看板。</p>
      <h4>資料怎麼來</h4>
      <ul>
        <li>應收：選定結帳月份後，自動抓出該月有出貨紀錄的客戶（與出貨單、對帳作業同一套結帳日口徑）。</li>
        <li>應付：自動抓出該結帳月份有加工移轉憑單的廠商（與「製程移轉一覽表」同一套廠商結帳日口徑）。</li>
        <li>第一次開啟某個月份時，系統會自動把這些對象建成追蹤列，預設狀態「處理中」。</li>
      </ul>
      <h4>狀態按鈕</h4>
      <ul>
        <li>每個狀態能不能由哪個角色按，由管理員在「設定→角色權限矩陣」調整（同一個狀態可以同時勾生管與業務）。</li>
        <li>本頁管理員（art_admin）不受矩陣限制，可以任意設定或回復任何狀態。</li>
      </ul>
      <h4>結帳日快速修改</h4>
      <p>管理員可在清單上直接修改客戶/廠商的結帳模式與固定結帳日，會同步寫回主檔管理（客戶/廠商分頁），並留下修改紀錄（欄位、修改前後、修改人、時間），可一鍵復原。</p>
      <h4>臨時結帳調整（僅應收）</h4>
      <p>某客戶某個月結帳日臨時提前或延後，可在清單的「臨時結帳調整」按鈕設定，與主檔管理→客戶編輯的設定是同一份資料。</p>
      <h4>統計分析</h4>
      <p>按月/季/半年/年切換期間，看各分組（如「結帳日→已送會計」）的平均工作天數、本期自動分析（與上一期比較），以及本年度各期別的趨勢圖。分組可在「設定」新增/修改。</p>
      <h4>權限角色</h4>
      <ul>
        <li>art_admin 本頁管理員（可修改）／art_view_all 本頁管理員（唯讀，可看全部不能改）</li>
        <li>art_pm 生管／art_sales 業務／art_acc 會計——能按哪個狀態依權限矩陣設定</li>
      </ul>
    </div>
    <div class="m-foot"><button class="btn btn-warm" data-close="helpUseMask">關閉</button></div>
  </div>
</div>

<script src="../../resource/js/jquery.min.js"></script>
<script src="../../resource/js/bootstrap.min.js"></script>
<script src="../../resource/js/fastclick.js"></script>
<script src="../../resource/js/nprogress.js"></script>
<script src="../../resource/js/custom.min.js"></script>
<script src="../../code/highcharts.js"></script>
<script src="../../resource/js/eg_input_rules.js?v=<?= @filemtime(__DIR__.'/../../resource/js/eg_input_rules.js') ?>"></script>
<script>
var API = '../../src/store/AccTrack_API.php';
var CSRF = <?= json_encode($CSRF) ?>;
var PERMS = <?= json_encode($perms) ?>;
var STATUSES = {}, ANCHORS = {}, MATRIX = {}, GROUPS = [];
var SIDE = 'ar';
var LAST_ROWS = [];

function openMask(id){ document.getElementById(id).style.display='block'; }
function closeMask(id){ document.getElementById(id).style.display='none'; }
document.addEventListener('click', function(e){
    if (e.target.classList && e.target.classList.contains('x') && e.target.dataset.close) closeMask(e.target.dataset.close);
    if (e.target.dataset && e.target.dataset.close && e.target.classList.contains('btn')) closeMask(e.target.dataset.close);
    if (e.target.classList && e.target.classList.contains('m-mask')) e.target.style.display='none';
});
function showToast(msg, type){
    if (window.toastr) { type==='error' ? toastr.error(msg) : toastr.success(msg); return; }
    alert(msg);
}
function esc(s){ return String(s==null?'':s).replace(/[&<>"']/g, function(c){ return {'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#39;'}[c]; }); }
function fmtMoney(v){ v = Number(v||0); return v.toLocaleString('en-US', {maximumFractionDigits:0}); }

function api(data){
    data = data || {};
    data.csrf = CSRF;
    return $.ajax({ url: API, method: (data.action && data._get) ? 'GET' : 'POST', data: data, dataType: 'json' })
        .fail(function(xhr){
            var r = {}; try { r = JSON.parse(xhr.responseText); } catch(e){}
            showToast(r.error || '發生錯誤', 'error');
        });
}
function apiGet(action, params){
    params = params || {}; params.action = action;
    return $.get(API, params).fail(function(xhr){
        var r = {}; try { r = JSON.parse(xhr.responseText); } catch(e){}
        showToast(r.error || '發生錯誤', 'error');
    });
}

/* ── 側別切換 ─────────────────────────────────────────────── */
function setSide(s){
    SIDE = s;
    document.getElementById('sideAr').classList.toggle('on', s==='ar');
    document.getElementById('sideAp').classList.toggle('on', s==='ap');
    document.getElementById('listTitle').textContent = (s==='ar' ? '應收' : '應付') + ' 進度清單';
    loadList();
    if (document.getElementById('paneStat').classList.contains('on')) loadStat();
}
function switchTab(t){
    document.getElementById('tabList').classList.toggle('on', t==='list');
    document.getElementById('tabStat').classList.toggle('on', t==='stat');
    document.getElementById('paneList').classList.toggle('on', t==='list');
    document.getElementById('paneStat').classList.toggle('on', t==='stat');
    if (t==='stat') loadStat();
}

/* ── 進度清單 ─────────────────────────────────────────────── */
function defaultBm(){
    var d = new Date(); var m = d.getMonth()+1;
    return d.getFullYear() + '-' + (m<10?'0'+m:m);
}
var ST_FILTER = '';
function renderStCards(counts){
    var html = '';
    Object.keys(STATUSES).forEach(function(k){
        html += '<div class="st-card' + (ST_FILTER===k?' on':'') + '" data-st="' + k + '" onclick="toggleStFilter(\'' + k + '\')">' +
            '<div class="lab">' + esc(STATUSES[k]) + '</div><div class="val">' + (counts[k]||0) + '</div></div>';
    });
    document.getElementById('stCards').innerHTML = html;
}
function toggleStFilter(k){ ST_FILTER = (ST_FILTER===k) ? '' : k; loadList(); }

function nextAllowedStatus(row){
    var order = Object.keys(STATUSES);
    var i = order.indexOf(row.status);
    if (i < 0 || i >= order.length-1) return null;
    var to = order[i+1];
    if (PERMS.canAdmin) return to;
    var allowed = (MATRIX[row.side] && MATRIX[row.side][to]) || [];
    if (PERMS.isPm && allowed.indexOf('art_pm')>=0) return to;
    if (PERMS.isSales && allowed.indexOf('art_sales')>=0) return to;
    if (PERMS.isAcc && allowed.indexOf('art_acc')>=0) return to;
    return null;
}

function loadList(){
    var bm = document.getElementById('fBm').value || defaultBm();
    document.getElementById('fBm').value = bm;
    var kw = document.getElementById('fKw').value.trim();
    apiGet('list', {side:SIDE, bm:bm, status:ST_FILTER, kw:kw, _:Date.now()}).done(function(r){
        if (!r || !r.ok) return;
        LAST_ROWS = r.rows;
        renderStCards(r.counts);
        renderListTbl(r.rows);
    });
}
function renderListTbl(rows){
    var isAr = (SIDE==='ar');
    var head = isAr
        ? '<tr><th style="width:150px;">客戶</th><th style="width:70px;">結帳日</th><th style="width:90px;">本期金額(含稅)</th>' +
          '<th style="width:110px;">對帳單</th><th style="width:100px;">狀態</th><th style="width:150px;">操作</th><th style="width:70px;">結帳日設定</th><th style="width:70px;">臨時調整</th><th style="width:70px;">修改紀錄</th></tr>'
        : '<tr><th style="width:150px;">廠商</th><th style="width:70px;">結帳日</th><th style="width:60px;">筆數</th><th style="width:90px;">本期金額(含稅)</th>' +
          '<th style="width:100px;">狀態</th><th style="width:150px;">操作</th><th style="width:70px;">結帳日設定</th><th style="width:70px;">修改紀錄</th></tr>';
    document.getElementById('listTbl').querySelector('thead').innerHTML = head;

    if (!rows.length) {
        document.getElementById('listTbl').querySelector('tbody').innerHTML = '<tr><td colspan="9" class="c">這個月份沒有符合條件的資料</td></tr>';
        return;
    }
    var html = '';
    rows.forEach(function(r){
        var badge = '<span class="badge-st badge-' + r.status + '">' + esc(STATUSES[r.status]||r.status) + '</span>';
        var next = nextAllowedStatus(r);
        var btns = next ? '<button class="btn btn-warm row-btn" onclick="doSetStatus(' + r.track_id + ',\'' + next + '\')">設為' + esc(STATUSES[next]) + '</button>' : '';
        if (PERMS.canAdmin) {
            btns += ' <select class="form-control input-sm" style="width:100px;display:inline-block;" onchange="if(this.value)doSetStatus(' + r.track_id + ',this.value);this.value=\'\';">' +
                '<option value="">（管理員改派）</option>' +
                Object.keys(STATUSES).filter(function(k){return k!==r.status;}).map(function(k){ return '<option value="'+k+'">'+esc(STATUSES[k])+'</option>'; }).join('') +
                '</select>';
        }
        var targetType = isAr ? 'customer' : 'maker';
        var targetId = r.party_id || '';
        var settleBtn = (PERMS.canAdmin && targetId) ? '<button class="icon-btn" title="修改結帳日" onclick="openSettleMask(\''+targetType+'\',\''+esc(targetId)+'\',\''+esc(r.party_name)+'\',\''+(r.settlement_mode||'FIXED')+'\','+(r.settlement_day||'')+')"><i class="fa fa-pencil"></i></button>' : '';
        var logBtn = targetId ? '<button class="icon-btn" title="修改紀錄" onclick="openLogMask(\''+targetType+'\',\''+esc(targetId)+'\')"><i class="fa fa-history"></i></button>' : '';

        if (isAr) {
            var needBadge = r.need_recon_stmt ? '<span class="badge-need">需要（' + (r.recon_provide_by==='company'?'本公司提供':'客戶提供') + '）</span>' : '<span class="badge-nneed">不需要</span>';
            var exBtn = (targetId && (PERMS.canAdmin||PERMS.isSales)) ? '<button class="icon-btn" title="臨時結帳調整" onclick="openExMaskFor(\''+esc(targetId)+'\',\''+esc(r.party_name)+'\')"><i class="fa fa-calendar-plus-o"></i></button>' : '';
            html += '<tr><td>' + esc(r.party_name) + (r.in_master?'':' <span class="hint" style="color:var(--coral);">(未建主檔)</span>') + '</td>' +
                '<td class="c">' + (r.cutoff_date||'—') + '</td>' +
                '<td class="n">' + fmtMoney(r.total_amt) + '</td>' +
                '<td class="c">' + needBadge + '</td>' +
                '<td class="c">' + badge + '</td>' +
                '<td class="c">' + btns + '</td>' +
                '<td class="c">' + settleBtn + '</td>' +
                '<td class="c">' + exBtn + '</td>' +
                '<td class="c">' + logBtn + '</td></tr>';
        } else {
            html += '<tr><td>' + esc(r.party_name) + (r.in_master?'':' <span class="hint" style="color:var(--coral);">(未建主檔)</span>') + '</td>' +
                '<td class="c">' + (r.cutoff_date||'—') + '</td>' +
                '<td class="n">' + (r.cnt||0) + '</td>' +
                '<td class="n">' + fmtMoney(r.total_amt) + '</td>' +
                '<td class="c">' + badge + '</td>' +
                '<td class="c">' + btns + '</td>' +
                '<td class="c">' + settleBtn + '</td>' +
                '<td class="c">' + logBtn + '</td></tr>';
        }
    });
    document.getElementById('listTbl').querySelector('tbody').innerHTML = html;
}
function doSetStatus(trackId, to){
    api({action:'set_status', track_id:trackId, to_status:to}).done(function(r){
        if (r && r.ok) { showToast(r.message||'已更新','success'); loadList(); }
    });
}

/* ── 結帳日快速修改 ──────────────────────────────────────────── */
var SETTLE_TARGET = null;
function openSettleMask(type, id, name, mode, day){
    SETTLE_TARGET = {type:type, id:id};
    document.getElementById('settleTargetName').textContent = name;
    document.getElementById('seMode').value = mode || 'FIXED';
    document.getElementById('seDay').value = day || '';
    document.getElementById('seDayWrap').style.display = (document.getElementById('seMode').value==='FIXED') ? '' : 'none';
    document.getElementById('settleErr').textContent = '';
    openMask('settleMask');
}
function saveSettle(){
    if (!SETTLE_TARGET) return;
    var mode = document.getElementById('seMode').value;
    var day = document.getElementById('seDay').value;
    if (mode==='FIXED' && (!day || day<1 || day>31)) { document.getElementById('settleErr').textContent = '固定結帳日請輸入 1~31'; return; }
    api({action:'settle_quick_edit', target_type:SETTLE_TARGET.type, target_id:SETTLE_TARGET.id, settlement_mode:mode, settlement_day:(mode==='FIXED'?day:'')}).done(function(r){
        if (r && r.ok) { showToast(r.message,'success'); closeMask('settleMask'); loadList(); }
        else if (r) document.getElementById('settleErr').textContent = r.error||'';
    });
}

/* ── 修改紀錄 ─────────────────────────────────────────────── */
var LOG_TARGET = null;
function openLogMask(type, id){
    LOG_TARGET = {type:type, id:id};
    apiGet('change_log', {target_type:type, target_id:id}).done(function(r){
        if (!r || !r.ok) return;
        var html = '';
        if (!r.rows.length) html = '<tr><td colspan="7" class="c">尚無修改紀錄</td></tr>';
        r.rows.forEach(function(l){
            var canRevert = PERMS.canAdmin && !l.reverted;
            html += '<tr><td>' + esc(l.field) + '</td><td>' + esc(l.old_value) + '</td><td>' + esc(l.new_value) + '</td>' +
                '<td>' + esc(l.changed_by_cname||l.changed_by_name) + '</td><td>' + esc(l.changed_at) + '</td>' +
                '<td>' + (l.reverted ? '已復原' : (l.revert_of_id ? '復原自 #'+l.revert_of_id : '—')) + '</td>' +
                '<td>' + (canRevert ? '<button class="btn btn-xs btn-danger-o" onclick="doRevert('+l.id+')">復原</button>' : '') + '</td></tr>';
        });
        document.getElementById('logTbl').querySelector('tbody').innerHTML = html;
        openMask('logMask');
    });
}
function doRevert(logId){
    if (!confirm('確定要復原這筆修改嗎？')) return;
    api({action:'settle_revert', log_id:logId}).done(function(r){
        if (r && r.ok) { showToast('已復原','success'); openLogMask(LOG_TARGET.type, LOG_TARGET.id); loadList(); }
    });
}

/* ── 臨時結帳調整（應收）──────────────────────────────────────── */
var EX_TARGET = null;
function openExMaskFor(customerId, name){
    EX_TARGET = customerId;
    document.getElementById('exTargetName').textContent = name + '（' + customerId + '）';
    document.getElementById('exYm').value = ''; document.getElementById('exDate').value = ''; document.getElementById('exReason').value = '';
    document.getElementById('exErr').textContent = '';
    loadExList();
    openMask('exMask');
}
function loadExList(){
    apiGet('settle_ex_list', {customer_id:EX_TARGET}).done(function(r){
        if (!r || !r.ok) return;
        var html = '';
        r.rows.forEach(function(e){
            html += '<tr><td>' + esc(e.target_year_month) + '</td><td>' + esc(e.adjusted_date) + '</td><td>' + esc(e.reason) + '</td>' +
                '<td><button class="icon-btn" onclick="exDel(' + e.exception_id + ')"><i class="fa fa-trash"></i></button></td></tr>';
        });
        document.getElementById('exTbody').innerHTML = html || '<tr><td colspan="4" class="c">尚未設定</td></tr>';
    });
}
function exAdd(){
    var ym = document.getElementById('exYm').value, d = document.getElementById('exDate').value, reason = document.getElementById('exReason').value.trim();
    if (!ym || !d) { document.getElementById('exErr').textContent = '請填適用年月與調整後結帳日'; return; }
    api({action:'settle_ex_save', customer_id:EX_TARGET, ym:ym, adjusted:d, reason:reason}).done(function(r){
        if (r && r.ok) { showToast('已儲存','success'); loadExList(); loadList(); document.getElementById('exReason').value=''; }
        else if (r) document.getElementById('exErr').textContent = r.error||'';
    });
}
function exDel(id){
    if (!confirm('確定刪除這筆臨時結帳調整？')) return;
    api({action:'settle_ex_delete', exception_id:id}).done(function(r){ if (r && r.ok) { loadExList(); loadList(); } });
}

/* ── 統計分析 ─────────────────────────────────────────────── */
var GRANS = {'month':'月','quarter':'季','half':'半年','year':'整年'};
var GRAN_IDX = { year:[['1','整年']], half:[['1','上半年'],['2','下半年']],
                 quarter:[['1','第 1 季'],['2','第 2 季'],['3','第 3 季'],['4','第 4 季']], month:null };
function fillGranYear(years){
    var sy = document.getElementById('sYear'), sg = document.getElementById('sGran');
    sy.innerHTML = years.map(function(y){ return '<option value="'+y+'">'+y+'</option>'; }).join('');
    sy.value = <?= json_encode($defYear) ?>;
    sg.innerHTML = Object.keys(GRANS).map(function(k){ return '<option value="'+k+'">'+GRANS[k]+'</option>'; }).join('');
    sg.value = 'month';
    fillIdx();
}
function fillIdx(){
    var g = document.getElementById('sGran').value;
    var list = GRAN_IDX[g];
    if (!list) { list = []; for (var m=1;m<=12;m++) list.push([String(m), m+'月']); }
    document.getElementById('sIdx').innerHTML = list.map(function(p){ return '<option value="'+p[0]+'">'+p[1]+'</option>'; }).join('');
    var now = new Date();
    if (g==='month') document.getElementById('sIdx').value = String(now.getMonth()+1);
}
function loadStat(){
    var year = document.getElementById('sYear').value, gran = document.getElementById('sGran').value, idx = document.getElementById('sIdx').value;
    apiGet('stats', {side:SIDE, year:year, gran:gran, idx:idx}).done(function(r){
        if (!r || !r.ok) return;
        renderStatKpi(r.counts, r.period);
        renderInsights(r.insights);
        renderGroupTbl(r.stat.groups);
        renderTrend(r.trend);
    });
}
function renderStatKpi(counts, period){
    var total = 0; Object.keys(counts).forEach(function(k){ total += counts[k]; });
    var html = '<div class="kpi-card"><div class="k-lab">期間</div><div class="k-val" style="font-size:14px;">' + esc(period.label) + '</div></div>';
    html += '<div class="kpi-card"><div class="k-lab">追蹤筆數</div><div class="k-val">' + total + '</div></div>';
    Object.keys(STATUSES).forEach(function(k){
        html += '<div class="kpi-card"><div class="k-lab">' + esc(STATUSES[k]) + '</div><div class="k-val">' + (counts[k]||0) + '</div></div>';
    });
    document.getElementById('statKpi').innerHTML = html;
}
function renderInsights(list){
    if (!list || !list.length) { document.getElementById('statIns').innerHTML = '<div class="hint">本期沒有特別需要注意的事項</div>'; return; }
    var html = '';
    list.forEach(function(i){
        var icon = {bad:'fa-exclamation-circle',warn:'fa-exclamation-triangle',good:'fa-check-circle',info:'fa-info-circle'}[i.level]||'fa-info-circle';
        html += '<div class="ins ins-' + i.level + '"><div class="ic"><i class="fa ' + icon + '"></i></div>' +
            '<div class="bd"><div class="tt">' + esc(i.title) + '</div><div class="dt">' + esc(i.detail) + '</div></div></div>';
    });
    document.getElementById('statIns').innerHTML = html;
}
function renderGroupTbl(groups){
    var html = '';
    groups.forEach(function(g){
        html += '<tr><td>' + esc(g.label) + '</td><td class="n">' + g.count + '</td>' +
            '<td class="n">' + (g.avg==null?'—':g.avg) + '</td><td class="n">' + (g.min==null?'—':g.min) + '</td><td class="n">' + (g.max==null?'—':g.max) + '</td></tr>';
    });
    document.getElementById('statGroupTbl').querySelector('tbody').innerHTML = html || '<tr><td colspan="5" class="c">尚無資料</td></tr>';
}
var TREND_CHART = null;
function renderTrend(trend){
    if (!trend || !trend.length) return;
    var labels = trend.map(function(t){ return t.label; });
    var gLabels = (trend[0].groups||[]).map(function(g){ return g.label; });
    var series = gLabels.map(function(lab, gi){
        return { name: lab, data: trend.map(function(t){ return (t.groups[gi] && t.groups[gi].avg!=null) ? t.groups[gi].avg : null; }) };
    });
    if (TREND_CHART) TREND_CHART.destroy();
    TREND_CHART = Highcharts.chart('chTrend', {
        chart: { type: 'line', backgroundColor: '#fff', style: { fontFamily: '"Microsoft JhengHei",sans-serif' } },
        title: { text: null }, credits: { enabled: false },
        xAxis: { categories: labels }, yAxis: { title: { text: '平均工作天數' } },
        series: series
    });
}

/* ── 設定跳窗（角色矩陣 + 工作天數分組）────────────────────────── */
var ROLE_CODES = [['art_pm','生管'],['art_sales','業務'],['art_acc','會計']];
function openSettingMask(){
    renderMatrix();
    renderGroups();
    document.getElementById('setErr').textContent = '';
    openMask('setMask');
}
function renderMatrix(){
    ['ar','ap'].forEach(function(side){
        var title = side==='ar' ? '應收' : '應付';
        var rows = Object.keys(STATUSES).filter(function(k){ return k!=='processing'; }).map(function(st){
            var allowed = (MATRIX[side] && MATRIX[side][st]) || [];
            var cells = ROLE_CODES.map(function(rc){
                var checked = allowed.indexOf(rc[0])>=0 ? 'checked' : '';
                return '<td><label><input type="checkbox" data-side="'+side+'" data-status="'+st+'" data-role="'+rc[0]+'" '+checked+'> '+rc[1]+'</label></td>';
            }).join('');
            return '<tr><th>' + esc(STATUSES[st]) + '</th>' + cells + '</tr>';
        }).join('');
        var html = '<h5 style="margin:10px 0 4px;">' + title + '</h5><table class="art-t matrix-tbl"><thead><tr><th>狀態</th>' +
            ROLE_CODES.map(function(rc){ return '<th>'+rc[1]+'</th>'; }).join('') + '</tr></thead><tbody>' + rows + '</tbody></table>';
        document.getElementById('matrixWrap').insertAdjacentHTML('beforeend', html);
    });
}
function renderGroups(){
    var anchorOpts = Object.keys(ANCHORS).map(function(k){ return '<option value="'+k+'">'+esc(ANCHORS[k])+'</option>'; }).join('');
    var html = '';
    GROUPS.forEach(function(g, i){
        html += '<tr data-i="'+i+'"><td><input class="rm-in" data-k="label" value="'+esc(g.label)+'"></td>' +
            '<td><select class="rm-in" data-k="from">'+anchorOpts+'</select></td>' +
            '<td><select class="rm-in" data-k="to">'+anchorOpts+'</select></td>' +
            '<td><button class="icon-btn" onclick="groupDel('+i+')"><i class="fa fa-trash"></i></button></td></tr>';
    });
    document.getElementById('groupTbody').innerHTML = html;
    document.querySelectorAll('#groupTbody tr').forEach(function(tr){
        var i = parseInt(tr.dataset.i);
        tr.querySelector('select[data-k="from"]').value = GROUPS[i].from;
        tr.querySelector('select[data-k="to"]').value = GROUPS[i].to;
    });
}
function collectGroups(){
    var out = [];
    document.querySelectorAll('#groupTbody tr').forEach(function(tr){
        out.push({ label: tr.querySelector('[data-k="label"]').value.trim(), from: tr.querySelector('[data-k="from"]').value, to: tr.querySelector('[data-k="to"]').value });
    });
    return out;
}
function groupAdd(){ GROUPS = collectGroups(); GROUPS.push({label:'', from:'cutoff_date', to:'acc_done'}); renderGroups(); }
function groupDel(i){ GROUPS = collectGroups(); if (typeof i!=='number') i = GROUPS.length-1; GROUPS.splice(i,1); renderGroups(); }
function collectMatrixRows(){
    var map = {};
    document.querySelectorAll('#matrixWrap input[type=checkbox]').forEach(function(cb){
        var key = cb.dataset.side + '|' + cb.dataset.status;
        map[key] = map[key] || { side: cb.dataset.side, status: cb.dataset.status, roles: [] };
        if (cb.checked) map[key].roles.push(cb.dataset.role);
    });
    return Object.values(map);
}
function saveSetting(){
    var groups = collectGroups().filter(function(g){ return g.label; });
    if (!groups.length) { document.getElementById('setErr').textContent = '至少要有一組工作天數統計分組'; return; }
    var rows = collectMatrixRows();
    $.when(
        api({action:'role_matrix_save', rows: JSON.stringify(rows)}),
        api({action:'workday_groups_save', groups: JSON.stringify(groups)})
    ).done(function(r1, r2){
        if (r1[0] && r1[0].ok) MATRIX = r1[0].matrix;
        if (r2[0] && r2[0].ok) GROUPS = r2[0].groups;
        showToast('已儲存','success');
        closeMask('setMask');
        loadList(); if (document.getElementById('paneStat').classList.contains('on')) loadStat();
    });
}

/* ── 初始化 ───────────────────────────────────────────────── */
$(function(){
    $('#sidebar-menu').css('visibility','visible');
    document.getElementById('fBm').value = defaultBm();
    $('#btnPageHelp').on('click', function(){ openMask('helpUseMask'); });
    apiGet('bootstrap', {}).done(function(r){
        if (!r || !r.ok) return;
        STATUSES = r.statuses; ANCHORS = r.anchors; MATRIX = r.matrix; GROUPS = r.groups;
        fillGranYear(r.years);
        setSide('ar');
    });
});
</script>
</body>
</html>
