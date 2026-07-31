<?php
// leave_request.php — 請假系統（申請 / 我的請假單 / 待我簽核）
// 後端：src/store/Leave_API.php ｜ 商業邏輯：src/common/leave_lib.php
// 代理解析一律走 delegate_lib（ai-rules/11、12 鐵律）；代理設定入口在 views/ADM/hr_settings.php，本頁不做代理設定 UI。
// 權限模組 leave：登入者皆可申請/查自己的單；leave_view_all 或管理員可看全部；主管(職稱有階級)可看部門。
session_start();
if (!isset($_SESSION['userName'])) { header("Location:../../index.php"); exit; }

include_once '../../src/common/_config.php';
include ("../../src/common/DBConnection.php");
include_once '../../src/common/role_features_helper.php';
$db  = new DBConnection();
$pdo = $db->getPDO();
$uid = (int)($_SESSION['id'] ?? 0);

$features = rf_load_user_features_override($pdo, $uid, 'leave');
$IS_ADMIN = rf_has_feature($features, 'all');
// 徹底刪除只給「員工 id=1 且在職狀態=99 最高權限」的帳號（2026-07-30 使用者要求；
// 判定與後端 eg_leave_is_superadmin() 同一條件，前端只是不畫按鈕，實際守門在 API）
require_once '../../src/common/leave_lib.php';
$IS_SUPERADMIN = eg_leave_is_superadmin($pdo, $uid);
$VIEW_ALL = $IS_ADMIN || rf_has_feature($features, 'leave_view_all');
// 請假統計分頁：人事/管理員看全公司；主管（主職職稱有階級）看自己部門含下轄；其他人看不到這個分頁。
// 判定與 Leave_API 的 leave_dept_scope() 同一條件（主職 position_level.level 非 NULL），
// 前端只是不畫分頁，實際守門在 API 的 stats / stats_options。
$IS_SUPERVISOR = false;
try {
    $mainIdent = eg_user_main_identity($pdo, $uid);
    $IS_SUPERVISOR = ($mainIdent && $mainIdent['level'] !== null);
} catch (Throwable $e) {}
$SHOW_STATS = $VIEW_ALL || $IS_SUPERVISOR;

// 首次載入植入預設角色（module=leave）
try {
    $has = $pdo->query("SELECT role_id FROM roles WHERE module='leave' AND is_system=0 LIMIT 1")->fetchColumn();
    if (!$has) {
        $mk = function ($name, $codes) use ($pdo) {
            $rcode = 'role_' . time() . '_' . rand(100, 999);
            $pdo->prepare("INSERT INTO roles (role_code, role_name, module) VALUES (?, ?, 'leave')")->execute([$rcode, $name]);
            $rid = (int)$pdo->lastInsertId();
            $ins = $pdo->prepare("INSERT IGNORE INTO role_features (role_id, feature_code) VALUES (?, ?)");
            foreach ($codes as $c) $ins->execute([$rid, $c]);
        };
        $mk('人事（可看全部請假單）', ['leave_view_all']);
    }
} catch (Throwable $e) {}

$PAGE_FEATURES = [
    ['code' => 'leave_view_all', 'label' => '檢視全公司請假單（人事用；不含代簽權）'],
];

$myRoleNames = [];
try {
    $st = $pdo->prepare("SELECT DISTINCT r.role_name FROM user_roles ur JOIN roles r ON r.role_id=ur.role_id
                         WHERE ur.user_id=? AND (r.module='leave' OR r.is_system=1)");
    $st->execute([$uid]);
    $myRoleNames = $st->fetchAll(PDO::FETCH_COLUMN);
} catch (Throwable $e) {}
$roleBadge = $IS_ADMIN ? '管理員' : (empty($myRoleNames) ? '一般使用者' : implode('、', $myRoleNames));
$avStamp = @filemtime(__DIR__ . '/../../resource/js/eg_stamp.js') ?: time();
?>
<!DOCTYPE html>
<html lang="zh-Hant">
<head>
<meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1">
<title>請假系統 | Excellentgear</title>
<link href="../../resource/css/bootstrap.css" rel="stylesheet">
<link href="../../resource/css/font-awesome.css" rel="stylesheet">
<link href="../../resource/css/nprogress.css" rel="stylesheet">
<link href="../../resource/css/custom.css" rel="stylesheet">
<style>
:root{--amber:#d99a4e;--amber-d:#b06f27;--sand:#faf3e7;--ink:#3a2c1a;--coral:#dd5138;--sand-d:#e6d8c3;}
.lv-wrap{padding:0 16px 40px;}
.pm-tab-btn{border:none;background:transparent;padding:7px 16px;border-radius:6px;font-size:14px;font-weight:600;color:#9a7b4f;cursor:pointer;transition:all .2s;}
.pm-tab-btn.active{background:#fffdf9;color:var(--amber-d);box-shadow:0 2px 5px rgba(0,0,0,.08);}
.lv-card{border:1px solid var(--sand-d);border-radius:8px;background:#fffdf9;padding:14px 16px;margin-bottom:14px;}
.lv-card h4{margin:0 0 10px;color:var(--amber-d);font-size:15px;}
.btn-amber{background:var(--amber);border-color:var(--amber-d);color:#fff;}
.btn-amber:hover{background:var(--amber-d);color:#fff;}
.btn-coral{background:var(--coral);border-color:#b53c26;color:#fff;}
.btn-coral:hover{background:#b53c26;color:#fff;}
.lv-tbl{width:100%;border-collapse:collapse;font-size:13px;background:#fff;}
.lv-tbl th{background:var(--sand);color:var(--amber-d);padding:7px 8px;border-bottom:2px solid var(--sand-d);text-align:left;font-weight:600;white-space:nowrap;}
.lv-tbl td{padding:6px 8px;border-bottom:1px solid #f0e7d7;vertical-align:middle;}
.lv-tbl tr:hover td{background:#fdf6ea;}
/* 狀態燈號：暖色系固定語意，顏色非唯一資訊（一律配文字） */
.st-badge{font-size:11px;padding:2px 8px;border-radius:9px;font-weight:600;white-space:nowrap;}
.st-pending {background:#F7E0BD;color:#8a5a1a;}   /* 審核中 */
.st-approved{background:#e7f0dd;color:#4d6b2e;}   /* 已核准 */
.st-rejected{background:#f7d9d1;color:#a3341f;}   /* 已退回 */
.st-canceled{background:#ece5da;color:#6b5638;}   /* 已取消 */
.st-cancelpend{background:#F0A24B;color:#fff;}    /* 撤回待簽核（需主管處理，用較醒目的暖橘） */
.tag-warn{background:#F0A24B;color:#fff;font-size:11px;padding:2px 7px;border-radius:9px;font-weight:600;}
.tag-soft{background:#f3ead9;color:#8a5a1a;font-size:11px;padding:2px 7px;border-radius:9px;}
.lv-form label{font-size:13px;color:#6b5638;margin-bottom:3px;font-weight:600;}
.lv-form .form-control{border-color:var(--sand-d);}
.lv-form .form-control:focus{border-color:var(--amber);box-shadow:0 0 4px rgba(217,154,78,.4);}
/* 數字輸入框：無上下增減鈕 */
input[type=number]::-webkit-outer-spin-button,input[type=number]::-webkit-inner-spin-button{-webkit-appearance:none;margin:0;}
input[type=number]{-moz-appearance:textfield;}
.role-badge{display:inline-block;background:var(--sand);border:1px solid var(--sand-d);color:var(--amber-d);border-radius:12px;padding:2px 10px;font-size:12px;margin-left:8px;}
.help-i{cursor:pointer;color:var(--amber);margin-left:4px;}
.annual-box{display:flex;gap:22px;flex-wrap:wrap;background:var(--sand);border:1px solid var(--sand-d);border-radius:7px;padding:9px 14px;margin-bottom:12px;}
.annual-box .it .lb{font-size:11px;color:#9a7b4f;}
.annual-box .it .vl{font-size:17px;font-weight:700;color:var(--ink);}
.signer-prev{background:#fdf6ea;border:1px dashed var(--amber);border-radius:6px;padding:8px 12px;font-size:12.5px;color:#6b5638;}
.signer-prev .lvl{display:inline-block;background:var(--amber);color:#fff;border-radius:8px;padding:0 7px;font-size:11px;margin-right:5px;}
.pager{display:flex;gap:4px;align-items:center;justify-content:flex-end;flex-wrap:wrap;}
.pager button{border:1px solid var(--sand-d);background:#fff;color:#6b5638;border-radius:4px;padding:2px 9px;font-size:12px;cursor:pointer;}
.pager button.on{background:var(--amber);color:#fff;border-color:var(--amber-d);}
.pager button:disabled{opacity:.45;cursor:not-allowed;}
.att-item{display:flex;align-items:center;gap:8px;font-size:12.5px;padding:3px 0;border-bottom:1px dotted #eadfcb;}
.flow-row{display:flex;align-items:center;gap:10px;padding:7px 0;border-bottom:1px solid #f0e7d7;font-size:13px;}
.flow-row .lvl-badge{background:var(--sand);border:1px solid var(--sand-d);color:var(--amber-d);border-radius:10px;padding:1px 9px;font-size:11px;font-weight:600;white-space:nowrap;}
.empty-note{padding:22px;color:#9a7b4f;text-align:center;font-size:13px;}
/* 列底色依狀態（暖色系，快速辨識）；顏色不是唯一資訊，狀態欄仍有文字標籤。
   左側色條加強區分：審核中橘、已退回赭紅、已取消暖粉；已核准保持白底讓需注意的狀態跳出來。 */
.lv-tbl tr.row-pending  > td{background:#FDF4E3;}
.lv-tbl tr.row-rejected > td{background:#FBE6DF;}
.lv-tbl tr.row-canceled > td{background:#FAE3E7;}
.lv-tbl tr.row-cancelpend > td{background:#FBEBD2;}
.lv-tbl tr.row-cancelpend > td:first-child{box-shadow:inset 3px 0 0 #D9873A;}
.lv-tbl tr.row-cancelpend:hover > td{background:#F8E0BC;}
.lv-tbl tr.row-pending  > td:first-child{box-shadow:inset 3px 0 0 #F0A24B;}
.lv-tbl tr.row-rejected > td:first-child{box-shadow:inset 3px 0 0 #DD5138;}
.lv-tbl tr.row-canceled > td:first-child{box-shadow:inset 3px 0 0 #C4808F;}
.lv-tbl tr.row-pending:hover  > td{background:#FBEDD4;}
.lv-tbl tr.row-rejected:hover > td{background:#F7D9CE;}
.lv-tbl tr.row-canceled:hover > td{background:#F5D5DB;}
/* 範圍／狀態切換鈕（則一選擇，暖色系；選中深底白字，未選淺底深棕字） */
.scope-btn,.status-btn{background:#fffdf9;border:1px solid var(--sand-d);color:#8a6d45;font-size:12.5px;}
.scope-btn:hover,.status-btn:hover{background:#f7e9d5;color:#6b5638;}
.scope-btn.on,.status-btn.on{background:var(--amber);border-color:var(--amber-d);color:#fff;font-weight:600;}
/* ── 請假統計 ── */
.chart-box{position:relative;height:300px;}                 /* Chart.js 需要固定高度的容器 */
.chart-box canvas{max-width:100%;}
.st-chip{border:1px solid var(--sand-d);border-radius:14px;padding:2px 11px 2px 8px;font-size:12px;
         cursor:pointer;background:#fffdf9;color:#8a6d45;user-select:none;white-space:nowrap;
         display:inline-flex;align-items:center;gap:6px;}
.st-chip .dot{width:11px;height:11px;border-radius:3px;display:inline-block;border:1px solid rgba(0,0,0,.15);}
.st-chip.off{opacity:.42;text-decoration:line-through;}
.st-chip:hover{background:#f7e9d5;}
.kpi-it .lb{font-size:11px;color:#9a7b4f;}
.kpi-it .vl{font-size:19px;font-weight:700;color:var(--ink);}
.kpi-it .un{font-size:12px;font-weight:400;color:#9a7b4f;}
.lv-tbl td.numc,.lv-tbl th.numc{text-align:right;white-space:nowrap;}
.lv-tbl tr.sum-row td{background:#f7efe0;font-weight:700;color:var(--amber-d);}
@media print{
  .right_col,.container.body{margin:0!important;padding:0!important;}
  .nav_menu,.left_col,.pm-tab-btn,.btn,.pager,.no-print{display:none!important;}
}
</style>
</head>
<body class="nav-sm">
<div class="container body"><div class="main_container">
<?php include '../partPage/sideAndTopBarMenu.html' ?>
<div class="right_col" role="main">
  <div class="page-title"><div class="title_left">
    <h3>請假系統 <small>申請・簽核・行事曆連動</small>
      <span class="role-badge">角色：<?= htmlspecialchars($roleBadge) ?></span>
      <i class="fa fa-question-circle help-i" title="各角色權限說明" onclick="$('#roleHelpModal').modal('show')"></i>
    </h3>
  </div></div>
  <div class="clearfix"></div>

  <div class="lv-wrap">
    <div style="display:flex;gap:4px;background:#f3ead9;border-radius:8px;padding:4px;margin-bottom:12px;flex-wrap:wrap;">
      <button class="pm-tab-btn active" id="tbApply" onclick="topTab('apply',this)">📝 申請請假</button>
      <button class="pm-tab-btn" id="tbMine" onclick="topTab('mine',this);loadList()">📋 我的請假單</button>
      <button class="pm-tab-btn" id="tbSign" onclick="topTab('sign',this);loadPending()">✍️ 待我簽核 <span id="pendCnt" class="tag-warn" style="display:none;">0</span></button>
      <?php if ($SHOW_STATS): ?>
      <button class="pm-tab-btn" id="tbStats" onclick="topTab('stats',this);openStats()">📊 請假統計</button>
      <?php endif; ?>
    </div>

    <!-- ═══════════ 申請請假 ═══════════ -->
    <div class="top-tab" id="tab-apply">
      <div class="lv-card lv-form">
        <h4><i class="fa fa-pencil-square-o"></i> 填寫請假單</h4>

        <div class="annual-box" id="annualBox">
          <!-- 年度切換：預設今年；只列出本人實際有請假資料的年度（一定含今年） -->
          <div class="it">
            <div class="lb">年度</div>
            <select class="form-control input-sm" id="fAnYear" data-eg-skip
                    style="width:auto;padding:2px 6px;height:26px;font-size:14px;font-weight:700;
                           color:var(--amber-d);border-color:var(--sand-d);background:#fff;"></select>
          </div>
          <div class="it"><div class="lb"><span class="an-y">本年度</span>特休額度</div><div class="vl"><span id="anEnt">—</span> 天</div></div>
          <div class="it"><div class="lb">已核准使用</div><div class="vl"><span id="anUsed">—</span> 天</div></div>
          <div class="it"><div class="lb">送審中</div><div class="vl"><span id="anPend">—</span> 天</div></div>
          <div class="it"><div class="lb">剩餘可用</div><div class="vl" style="color:var(--amber-d);"><span id="anRem">—</span> 天</div></div>
          <!-- 右側：該年度其他假別的已核准累積（只列請過的假別，故每人顯示不同）-->
          <div class="it" style="flex:1;min-width:240px;border-left:1px solid var(--sand-d);padding-left:16px;">
            <div class="lb"><span class="an-y">本年度</span>請假紀錄（已核准）</div>
            <div id="yearUsage" style="font-size:13px;color:var(--ink);line-height:1.7;margin-top:2px;"></div>
            <div style="font-size:11px;color:#9a7b4f;margin-top:2px;">特休額度與員工資料的到職日／年資同一套算法即時計算，不另建額度表。</div>
          </div>
        </div>

        <div class="row">
          <div class="col-md-3 col-sm-6" style="margin-bottom:10px;">
            <label>假別 <span style="color:var(--coral);">*</span></label>
            <select class="form-control input-sm" id="fType"></select>
            <div id="typeHint" style="font-size:11.5px;color:#9a7b4f;margin-top:3px;min-height:16px;"></div>
          </div>
          <!-- 日期與時間成對放在一起：選完日期，時間欄只需選時間（半小時刻度） -->
          <div class="col-md-4 col-sm-6" style="margin-bottom:10px;">
            <label>開始 <span style="color:var(--coral);">*</span>
              <span style="font-weight:400;font-size:11.5px;color:#9a7b4f;">日期／時間</span></label>
            <div style="display:flex;gap:6px;">
              <input type="date" class="form-control input-sm eg-inp" id="fDateFrom" max="9999-12-31" style="flex:1 1 58%;">
              <input type="text" class="form-control input-sm eg-inp" id="fTimeFrom" maxlength="5" placeholder="08:00" style="flex:1 1 42%;">
            </div>
            <div id="errTimeFrom" style="font-size:11.5px;color:#a3341f;margin-top:3px;"></div>
          </div>
          <div class="col-md-4 col-sm-6" style="margin-bottom:10px;">
            <label>結束 <span style="color:var(--coral);">*</span>
              <span style="font-weight:400;font-size:11.5px;color:#9a7b4f;">日期／時間</span></label>
            <div style="display:flex;gap:6px;">
              <input type="date" class="form-control input-sm eg-inp" id="fDateTo" max="9999-12-31" style="flex:1 1 58%;">
              <input type="text" class="form-control input-sm eg-inp" id="fTimeTo" maxlength="5" placeholder="17:00" style="flex:1 1 42%;">
            </div>
            <div id="errTimeTo" style="font-size:11.5px;color:#a3341f;margin-top:3px;"></div>
            <div style="font-size:11.5px;color:#9a7b4f;margin-top:2px;">日期留空＝與開始同一天；時間可直接打 0900／9</div>
          </div>
          <div class="col-md-1 col-sm-6" style="margin-bottom:10px;min-width:130px;">
            <label>時數／天數</label>
            <input type="text" class="form-control input-sm" id="fAmount" readonly style="background:#f7f2e8;">
          </div>
        </div>
        <div id="shiftHint" style="display:none;font-size:12.5px;margin-bottom:10px;padding:7px 12px;border-radius:6px;"></div>
        <div style="font-size:12px;color:#9a7b4f;margin-bottom:10px;">
          選好開始日期後，系統會依您當天的<b>固定班別排班</b>自動帶出整天的起訖（時間以半小時為單位）；
          只請半天或幾小時，直接改時間即可。
          <button type="button" class="btn btn-xs btn-default" style="margin-left:6px;" onclick="applyShift(true)">重新帶入排班時間</button>
        </div>

        <div class="row">
          <div class="col-md-12" style="margin-bottom:10px;">
            <label>請假原因</label>
            <input type="text" class="form-control input-sm eg-inp" id="fReason" maxlength="200" placeholder="簡述原因（選填）">
          </div>
        </div>

        <!-- 職務代理人：系統依「人事設定」的優先順位自動決定，申請人不需挑選 -->
        <div id="agentPrev" class="signer-prev" style="display:none;margin-bottom:10px;"></div>

        <!-- 證明文件：只有假別在「人事設定→假別設定」勾了「需附證明文件」才會出現整個區塊 -->
        <div class="row" id="attachBlock" style="display:none;">
          <div class="col-md-12" style="margin-bottom:10px;">
            <label>證明文件 <span id="attReq" style="color:var(--coral);display:none;">*</span>
              <span style="font-weight:400;font-size:11.5px;color:#9a7b4f;">（jpg／png／pdf，單檔 20MB 內；新增當下即可上傳，不必先存單）</span>
            </label>
            <div style="display:flex;gap:8px;align-items:center;flex-wrap:wrap;">
              <input type="file" id="fFile" accept=".jpg,.jpeg,.png,.pdf" style="font-size:12.5px;">
              <button class="btn btn-xs btn-default" type="button" onclick="uploadTemp()"><i class="fa fa-upload"></i> 上傳</button>
              <span id="attMsg" style="font-size:12px;"></span>
            </div>
            <div id="tempList" style="margin-top:6px;"></div>
          </div>
        </div>

        <div id="signerPrev" class="signer-prev" style="display:none;margin-bottom:10px;"></div>
        <div id="applyMsg" style="min-height:20px;font-size:13px;margin-bottom:6px;"></div>

        <button class="btn btn-amber" id="btnSubmit" onclick="submitLeave()"><i class="fa fa-paper-plane"></i> 送出申請</button>
        <button class="btn btn-default" type="button" onclick="resetForm()">清空重填</button>
      </div>
    </div>

    <!-- ═══════════ 我的請假單 ═══════════ -->
    <div class="top-tab" id="tab-mine" style="display:none;">
      <div class="lv-card">
        <div style="display:flex;gap:10px;align-items:center;flex-wrap:wrap;margin-bottom:10px;">
          <h4 style="margin:0;flex:0 0 auto;"><i class="fa fa-list"></i> 請假單列表</h4>
          <!-- 範圍改按鈕切換（則一選擇）：只有三個選項，按鈕比下拉少一次點擊 -->
          <div class="btn-group btn-group-sm" role="group" id="scopeBtns">
            <button type="button" class="btn scope-btn" data-scope="mine">我的請假單</button>
            <button type="button" class="btn scope-btn" data-scope="dept">我的部門（含下轄）</button>
            <?php if ($VIEW_ALL): ?><button type="button" class="btn scope-btn" data-scope="all">全公司</button><?php endif; ?>
          </div>
          <input type="hidden" id="fScope" value="<?= $VIEW_ALL ? 'all' : 'mine' ?>"><!-- 人事/管理員預設全公司 -->
          <!-- 年度：預設今年，可切換到有資料的其他年度或「全部年度」；選項由後端依目前範圍算出 -->
          <select class="form-control input-sm" id="fYear"
                  title="雙擊＝解除年度篩選（全部年度）"
                  style="width:auto;display:inline-block;border-color:var(--sand-d);color:#8a6d45;"
                  onchange="listPage=1;loadList()"></select>
          <div class="btn-group btn-group-sm" role="group" id="statusBtns">
            <button type="button" class="btn status-btn" data-status="">全部狀態</button>
            <button type="button" class="btn status-btn" data-status="pending">審核中</button>
            <button type="button" class="btn status-btn" data-status="cancel_pending">撤回待簽核</button>
            <button type="button" class="btn status-btn" data-status="approved">已核准</button>
            <button type="button" class="btn status-btn" data-status="rejected">已退回</button>
            <button type="button" class="btn status-btn" data-status="canceled">已取消</button>
          </div>
          <input type="hidden" id="fStatus" value="">
          <span style="flex:1;"></span>
          <div class="pager no-print">
            <span style="font-size:12px;color:#9a7b4f;">每頁</span>
            <select class="form-control input-sm" id="fPer" style="width:auto;display:inline-block;" onchange="listPage=1;loadList()">
              <option>5</option><option selected>10</option><option>20</option><option>50</option>
            </select>
            <span id="pagerBtns"></span>
            <button onclick="exportCsv()" title="匯出目前條件的全部資料（後端全量）"><i class="fa fa-file-excel-o"></i> CSV</button>
            <button onclick="exportPdf()" title="列印／另存 PDF"><i class="fa fa-file-pdf-o"></i> PDF</button>
            <?php if ($IS_ADMIN): ?><button onclick="openPrintSetting()" title="設定列印表頭表尾"><i class="fa fa-cog"></i></button><?php endif; ?>
          </div>
        </div>
        <div style="overflow-x:auto;">
          <table class="lv-tbl">
            <thead><tr>
              <th>單號</th><th>申請人</th><th>假別</th><th>起訖</th><th>時數/天數</th>
              <th>代理人</th><th>狀態</th><th>標記</th><th>送出時間</th><th class="no-print">操作</th>
            </tr></thead>
            <tbody id="listBody"><tr><td colspan="10" class="empty-note">載入中…</td></tr></tbody>
          </table>
        </div>
        <div id="listInfo" style="font-size:12px;color:#9a7b4f;margin-top:8px;"></div>
      </div>
    </div>

    <!-- ═══════════ 待我簽核 ═══════════ -->
    <div class="top-tab" id="tab-sign" style="display:none;">
      <div class="lv-card">
        <h4><i class="fa fa-check-square-o"></i> 待我簽核</h4>
        <div style="font-size:12px;color:#9a7b4f;margin-bottom:8px;">
          <i class="fa fa-info-circle"></i> 清單包含「輪到您簽」與「您代理的主管該簽」的單據；同一層主管本人或其代理任一人簽核即完成該層。
        </div>
        <div id="pendBody"><div class="empty-note">載入中…</div></div>
      </div>
    </div>

    <!-- ═══════════ 請假統計（人事／主管） ═══════════ -->
    <?php if ($SHOW_STATS): ?>
    <div class="top-tab" id="tab-stats" style="display:none;">
      <!-- 篩選列：四個子分頁共用同一組條件，切子分頁不會讓數字換一套口徑 -->
      <div class="lv-card" id="stFilterCard">
        <div style="display:flex;gap:10px;align-items:flex-end;flex-wrap:wrap;">
          <div>
            <label style="display:block;font-size:12px;color:#9a7b4f;margin-bottom:2px;">年度</label>
            <select class="form-control input-sm" id="stYear" style="width:auto;"></select>
          </div>
          <div>
            <label style="display:block;font-size:12px;color:#9a7b4f;margin-bottom:2px;">部門</label>
            <select class="form-control input-sm" id="stDept" style="width:auto;min-width:130px;"
                    title="雙擊＝解除部門篩選"></select>
          </div>
          <div>
            <label style="display:block;font-size:12px;color:#9a7b4f;margin-bottom:2px;">人員</label>
            <select class="form-control input-sm" id="stUser" style="width:auto;min-width:190px;"
                    title="雙擊＝解除人員篩選"></select>
          </div>
          <div>
            <label style="display:block;font-size:12px;color:#9a7b4f;margin-bottom:2px;">狀態</label>
            <label style="font-weight:400;font-size:12.5px;color:#6b5638;white-space:nowrap;padding:5px 0;">
              <input type="checkbox" id="stPending" data-eg-skip> 含審核中（預設只算已核准）
            </label>
          </div>
          <span style="flex:1;"></span>
          <div class="no-print">
            <button class="btn btn-sm btn-default" onclick="loadStats()"><i class="fa fa-refresh"></i> 重新計算</button>
            <button class="btn btn-sm btn-amber" onclick="printStats()"><i class="fa fa-print"></i> 列印本分頁</button>
          </div>
        </div>
        <!-- 假別篩選：色塊即圖表顏色，點一下切換納入與否（長假天數大，可在這裡排除） -->
        <div style="margin-top:10px;">
          <div style="font-size:12px;color:#9a7b4f;margin-bottom:4px;">
            假別（點選切換納入；<b>留職停薪／育嬰留停等長假天數很大</b>，會壓過其他假別，需要時請在此排除）
            <a href="javascript:;" onclick="stAllTypes(true)" style="margin-left:8px;">全選</a>
            <a href="javascript:;" onclick="stAllTypes(false)" style="margin-left:6px;">全不選</a>
          </div>
          <div id="stTypeChips" style="display:flex;gap:6px;flex-wrap:wrap;"></div>
        </div>
        <div id="stScopeNote" style="font-size:11.5px;color:#9a7b4f;margin-top:8px;"></div>
      </div>

      <!-- KPI 卡：四個子分頁共用，一眼看到選定條件下的總量 -->
      <div id="stKpi" class="annual-box" style="gap:26px;"></div>

      <div style="display:flex;gap:4px;background:#f3ead9;border-radius:8px;padding:4px;margin-bottom:12px;flex-wrap:wrap;">
        <button class="pm-tab-btn active" id="sbMonth" onclick="statsSub('month',this)">月度統計</button>
        <button class="pm-tab-btn" id="sbYear"  onclick="statsSub('year',this)">年度比較</button>
        <button class="pm-tab-btn" id="sbTrend" onclick="statsSub('trend',this)">趨勢分析</button>
        <button class="pm-tab-btn" id="sbPeople" onclick="statsSub('people',this)">部門・人員分析</button>
      </div>

      <!-- ── 月度統計 ── -->
      <div class="st-sub" id="st-month">
        <div class="row">
          <div class="col-md-8"><div class="lv-card">
            <h4><i class="fa fa-bar-chart"></i> <span class="st-y"></span>每月請假天數（依假別堆疊）</h4>
            <div class="chart-box"><canvas id="cvMonth"></canvas></div>
          </div></div>
          <div class="col-md-4"><div class="lv-card">
            <h4><i class="fa fa-pie-chart"></i> 假別佔比</h4>
            <div class="chart-box"><canvas id="cvType"></canvas></div>
          </div></div>
        </div>
        <div class="lv-card">
          <h4><i class="fa fa-table"></i> 月 × 假別 交叉表（天）</h4>
          <div style="overflow-x:auto;"><table class="lv-tbl" id="tbMonth"></table></div>
        </div>
      </div>

      <!-- ── 年度比較 ── -->
      <div class="st-sub" id="st-year" style="display:none;">
        <div class="lv-card">
          <h4><i class="fa fa-bar-chart"></i> 各年度請假天數（依假別堆疊，不受上方年度篩選影響）</h4>
          <div class="chart-box"><canvas id="cvYear"></canvas></div>
        </div>
        <div class="lv-card">
          <h4><i class="fa fa-table"></i> 年 × 假別 交叉表（天）</h4>
          <div style="overflow-x:auto;"><table class="lv-tbl" id="tbYear"></table></div>
        </div>
      </div>

      <!-- ── 趨勢分析 ── -->
      <div class="st-sub" id="st-trend" style="display:none;">
        <div class="lv-card">
          <h4><i class="fa fa-line-chart"></i> 逐月請假天數趨勢（跨年度連續，中間沒人請假的月份補 0）</h4>
          <div class="chart-box"><canvas id="cvTrend"></canvas></div>
        </div>
        <div class="row">
          <div class="col-md-6"><div class="lv-card">
            <h4><i class="fa fa-line-chart"></i> 逐月請假<b>件數</b></h4>
            <div class="chart-box"><canvas id="cvTrendCnt"></canvas></div>
          </div></div>
          <div class="col-md-6"><div class="lv-card">
            <h4><i class="fa fa-exchange"></i> 同期比較（<span class="st-y2"></span> vs 前一年，每月天數）</h4>
            <div class="chart-box"><canvas id="cvYoY"></canvas></div>
          </div></div>
        </div>
        <div class="lv-card">
          <h4><i class="fa fa-table"></i> 逐月明細</h4>
          <div style="overflow-x:auto;"><table class="lv-tbl" id="tbTrend"></table></div>
        </div>
      </div>

      <!-- ── 部門・人員分析 ── -->
      <div class="st-sub" id="st-people" style="display:none;">
        <div class="row">
          <div class="col-md-6"><div class="lv-card">
            <h4><i class="fa fa-building-o"></i> <span class="st-y"></span>各部門請假天數</h4>
            <div class="chart-box"><canvas id="cvDept"></canvas></div>
          </div></div>
          <div class="col-md-6"><div class="lv-card">
            <h4><i class="fa fa-user"></i> 請假天數最多的人員（前 15 名）</h4>
            <div class="chart-box"><canvas id="cvTop"></canvas></div>
          </div></div>
        </div>
        <div class="lv-card">
          <div style="display:flex;gap:10px;align-items:center;flex-wrap:wrap;margin-bottom:8px;">
            <h4 style="margin:0;flex:0 0 auto;"><i class="fa fa-table"></i> 部門統計</h4>
          </div>
          <div style="overflow-x:auto;"><table class="lv-tbl" id="tbDept"></table></div>
        </div>
        <div class="lv-card">
          <div style="display:flex;gap:10px;align-items:center;flex-wrap:wrap;margin-bottom:8px;">
            <h4 style="margin:0;flex:0 0 auto;"><i class="fa fa-users"></i> 人員明細</h4>
            <span style="flex:1;"></span>
            <div class="pager no-print">
              <span style="font-size:12px;color:#9a7b4f;">每頁</span>
              <select class="form-control input-sm" id="stPer" style="width:auto;display:inline-block;"
                      data-eg-skip onchange="stPersonPage=1;renderPeopleTable()">
                <option>5</option><option selected>10</option><option>20</option><option>50</option>
              </select>
              <span id="stPagerBtns"></span>
              <button onclick="exportStatsCsv()" title="匯出全部人員（後端全量計算的結果）">
                <i class="fa fa-file-excel-o"></i> CSV</button>
            </div>
          </div>
          <div style="overflow-x:auto;"><table class="lv-tbl" id="tbPeople"></table></div>
          <div id="stPeopleInfo" style="font-size:12px;color:#9a7b4f;margin-top:8px;"></div>
        </div>
      </div>
    </div>
    <?php endif; ?>
  </div>
</div></div></div>

<!-- ═══ 詳情 Modal ═══ -->
<div class="modal fade" id="detailModal" tabindex="-1"><div class="modal-dialog modal-lg"><div class="modal-content">
  <div class="modal-header" style="background:var(--sand);">
    <button type="button" class="close" data-dismiss="modal">&times;</button>
    <h4 class="modal-title" style="color:var(--amber-d);">請假單詳情</h4>
  </div>
  <div class="modal-body" id="detailBody" style="max-height:70vh;overflow:auto;"></div>
  <div class="modal-footer no-print">
    <span id="editHint" style="float:left;font-size:12px;color:#8a5a1a;text-align:left;max-width:60%;"></span>
    <button class="btn btn-default" data-dismiss="modal">關閉</button>
    <button class="btn btn-amber" id="btnEditLeave" style="display:none;" onclick="startEdit()"><i class="fa fa-pencil"></i> 修改內容</button>
    <button class="btn btn-amber" id="btnReqChange" style="display:none;" onclick="requestChange()"><i class="fa fa-refresh"></i> 申請修改</button>
    <button class="btn btn-coral" id="btnCancelLeave" style="display:none;" onclick="doCancel()"></button>
    <?php if ($IS_SUPERADMIN): ?>
    <button class="btn btn-coral" id="btnDeleteLeave" style="display:none;" onclick="doDelete()"
            title="徹底刪除此單及其通知、簽核紀錄、行事曆事件與附件（不可回復，僅最高權限帳號測試用）">
      <i class="fa fa-trash"></i> 徹底刪除
    </button>
    <?php endif; ?>
  </div>
</div></div></div>

<?php if ($IS_SUPERADMIN): ?>
<!-- ═══ 徹底刪除確認 Modal（僅最高權限帳號；三道關卡：權限＋單號＋本人密碼）═══ -->
<div class="modal fade" id="deleteModal" tabindex="-1"><div class="modal-dialog"><div class="modal-content">
  <div class="modal-header" style="background:#fbe6df;">
    <button type="button" class="close" data-dismiss="modal">&times;</button>
    <h4 class="modal-title" style="color:#a3341f;"><i class="fa fa-exclamation-triangle"></i> 徹底刪除請假單 <span id="delReqNo"></span></h4>
  </div>
  <div class="modal-body lv-form">
    <div style="background:#fdf0dc;border:1px solid #e9c98f;color:#8a5a1a;padding:9px 12px;border-radius:5px;font-size:13px;margin-bottom:12px;">
      將一併刪除：<b>簽核流程與簽章軌跡、相關通知（含已發送到置頂欄的通知）、行事曆事件、附件檔案</b>。<br>
      此操作<b>不可回復</b>，僅供測試使用；刪除內容會寫入稽核紀錄（audit_log）以便追溯。
    </div>
    <label>請輸入單號確認</label>
    <input type="text" class="form-control input-sm" id="delConfirmId" placeholder="輸入上方單號的數字" autocomplete="off">
    <label style="margin-top:10px;">最高權限帳號密碼 <span style="font-weight:400;font-size:11.5px;color:#9a7b4f;">（員工編號 1 本人密碼）</span></label>
    <input type="password" class="form-control input-sm" id="delPassword" autocomplete="new-password">
    <div id="delMsg" style="font-size:12.5px;margin-top:8px;min-height:18px;"></div>
  </div>
  <div class="modal-footer">
    <button class="btn btn-default" data-dismiss="modal">取消</button>
    <button class="btn btn-coral" onclick="doDeleteConfirm()"><i class="fa fa-trash"></i> 確認徹底刪除</button>
  </div>
</div></div></div>
<?php endif; ?>

<!-- ═══ 角色說明 Modal ═══ -->
<div class="modal fade" id="roleHelpModal" tabindex="-1"><div class="modal-dialog"><div class="modal-content">
  <div class="modal-header" style="background:var(--sand);">
    <button type="button" class="close" data-dismiss="modal">&times;</button>
    <h4 class="modal-title" style="color:var(--amber-d);">請假系統 權限說明</h4>
  </div>
  <div class="modal-body" style="font-size:13px;">
    <p><b>一般使用者（所有登入者）</b>：申請請假、查看與撤回／銷假自己的單、上傳與補件自己的證明文件。</p>
    <p><b>主管（職稱有設定階級者）</b>：除上述外，可檢視自己部門（含下轄部門）的請假單，
       並可使用「請假統計」分頁（範圍同樣限自己部門含下轄）。</p>
    <p><b>人事（leave_view_all）</b>：可檢視全公司請假單，「請假統計」分頁涵蓋全公司；
       此角色<u>不含</u>代為簽核的權力。</p>
    <p><b>管理者</b>：全部權限，另可代任何人銷假、刪除已核准單的附件、設定列印表頭表尾。</p>
    <hr>
    <p style="color:#8a5a1a;"><b>簽核權不由角色決定</b>：由申請人的部門／職稱階級推出主管鏈，每層由該層主管本人簽；主管當日有行程（請假／外出／會議）時，改由其在「人事設定」設定的代理人簽；若代理人正好是申請人本人，則依權責分離自動直升上一級。</p>
    <p style="color:#8a5a1a;"><b>代理人設定</b>一律在「人事設定（hr_settings）」維護，本頁不提供代理設定介面。</p>
  </div>
</div></div></div>

<!-- ═══ 列印表頭表尾設定 Modal（管理員）═══ -->
<?php if ($IS_ADMIN): ?>
<div class="modal fade" id="printSetModal" tabindex="-1"><div class="modal-dialog"><div class="modal-content">
  <div class="modal-header" style="background:var(--sand);">
    <button type="button" class="close" data-dismiss="modal">&times;</button>
    <h4 class="modal-title" style="color:var(--amber-d);">列印表頭／表尾設定</h4>
  </div>
  <div class="modal-body lv-form">
    <label>表頭（列印最上方，通常放公司抬頭）</label>
    <input type="text" class="form-control input-sm eg-inp" id="psHeader" maxlength="100">
    <label style="margin-top:10px;">表尾（列印最下方，通常放表單編號）</label>
    <input type="text" class="form-control input-sm eg-inp" id="psFooter" maxlength="100">
  </div>
  <div class="modal-footer">
    <button class="btn btn-default" data-dismiss="modal">取消</button>
    <button class="btn btn-amber" onclick="savePrintSetting()">儲存</button>
  </div>
</div></div></div>
<?php endif; ?>

<!-- Gentelella 版型必備，順序不可調換：缺 custom.min.js 左側欄選單就不會展開（ai-rules/00-診斷.md 陷阱表） -->
<script src="../../resource/js/jquery.min.js"></script>
<script src="../../resource/js/bootstrap.min.js"></script>
<script src="../../resource/js/fastclick.js"></script>
<script src="../../resource/js/nprogress.js"></script>
<script src="../../resource/js/custom.min.js"></script>
<!-- Chart.js 必須排在 custom.min.js 之後（custom.min.js 內有 Chart v2 的相容 patch，
     順序顛倒會被覆蓋）。用站內本地檔不用 CDN：本系統是內網，連不到外網時 CDN 會整頁圖表消失。 -->
<?php if ($SHOW_STATS): ?><script src="../../resource/js/Chart.min.js"></script><?php endif; ?>
<script src="../../resource/js/eg_stamp.js?v=<?= $avStamp ?>"></script>
<script>
const API = '../../src/store/Leave_API.php';
const IS_ADMIN = <?= $IS_ADMIN ? 'true' : 'false' ?>;
const IS_SUPERADMIN = <?= $IS_SUPERADMIN ? 'true' : 'false' ?>;   // 僅 id=1 且 state=99，可徹底刪除
let CSRF = '', TYPES = [], AGENTS = [], SETTINGS = {}, ME = {};
let CUR_YEAR = (new Date()).getFullYear();
let uploadToken = '';
let listPage = 1, listTotal = 0, curDetailId = 0, curDetailCanCancel = false, curDetailStatus = '';

function esc(s){ return $('<div>').text(s==null?'':s).html(); }
function num(v){ const n = parseFloat(v); return isNaN(n) ? '0' : String(parseFloat(n.toFixed(2))); }  // 小數尾0省略
function newToken(){ let s=''; for(let i=0;i<8;i++) s += Math.floor(Math.random()*65536).toString(16).padStart(4,'0'); return s.substring(0,32); }

// ── 分頁切換 ──
function topTab(k, btn){
  $('.top-tab').hide(); $('#tab-'+k).show();
  $('.pm-tab-btn').removeClass('active'); $(btn).addClass('active');
}

// ── 共用輸入 UX（全站規範）：有值雙擊清空／聚焦全選／Enter 跳下一欄（textarea 除外）──
function bindInputUx(root){
  // data-eg-skip 的欄位排除在共用行為外（全站規則；例如申請頁的年度下拉不該被雙擊清成別的年度）
  const $f = $(root||document).find('input.eg-inp, select.eg-inp, input.form-control, select.form-control')
               .not('[data-eg-skip]');
  $f.off('.egux')
    .on('dblclick.egux', function(){ if($(this).is('select')){ this.selectedIndex=0; $(this).trigger('change'); } else if(this.value!==''){ this.value=''; $(this).trigger('change'); } })
    .on('focus.egux', function(){ if(this.value!=='' && this.select) { try{ this.select(); }catch(e){} } })
    .on('keydown.egux', function(e){
      if(e.key!=='Enter' || this.tagName==='TEXTAREA') return;
      e.preventDefault();
      const list = $(this).closest('.lv-form, .modal-body').find('input:visible:not([readonly]),select:visible,button.btn-amber:visible').toArray();
      const i = list.indexOf(this);
      if(i>=0 && i<list.length-1) list[i+1].focus();
      else if(i===list.length-1 && $(this).closest('.lv-form').find('#btnSubmit').length) submitLeave();
    });
}

// ── 初始化 ──
function boot(){
  $.getJSON(API, {action:'bootstrap'}, function(r){
    if(!r.success){ alert(r.message||'載入失敗'); return; }
    CSRF = r.csrf; TYPES = r.leave_types||[]; AGENTS = r.agent_candidates||[]; SETTINGS = r.settings||{}; ME = r.me||{};
    // 假別下拉
    const $t = $('#fType').empty().append('<option value="">請選擇假別</option>');
    TYPES.forEach(t => $t.append('<option value="'+t.id+'">'+esc(t.leave_name)+'</option>'));
    // 代理人不再由申請人挑選：系統依人事設定的順位自動解析，改以 renderAgentPreview() 唯讀顯示
    CUR_YEAR = r.cur_year || (new Date()).getFullYear();
    $('#fAnYear').empty().append((r.my_years||[CUR_YEAR]).map(y => '<option value="'+y+'">'+y+' 年</option>').join(''));
    $('#fAnYear').val(String(CUR_YEAR));
    syncAnnualYearLabel();
    renderAnnual(r.annual);
    renderYearUsage(r.year_usage);
    if(IS_ADMIN){ $('#psHeader').val(SETTINGS.print_header||''); $('#psFooter').val(SETTINGS.print_footer||''); }
    // 附件根目錄未設定的警告改在「選到需附證明的假別」時才顯示（見假別切換），此處不預先顯示
    uploadToken = newToken();
    bindInputUx();
    syncFilterBtns();
    refreshPendingCount();
  });
}
function renderAnnual(an){
  if(!an) return;
  $('#anEnt').text(num(an.entitlement)); $('#anUsed').text(num(an.used));
  $('#anPend').text(num(an.pending));    $('#anRem').text(num(an.remaining));
}
// 年度切換：標題文字跟著改（看往年時要一眼看得出來現在不是今年）
function syncAnnualYearLabel(){
  const y = parseInt($('#fAnYear').val() || CUR_YEAR, 10);
  $('.an-y').text(y === CUR_YEAR ? '本年度' : (y + ' 年'));
}
// 申請頁的特休額度與各假別累積，一律以下拉選的年度為準（查往年紀錄用）
function loadAnnual(){
  syncAnnualYearLabel();
  $.getJSON(API, {action:'annual_summary', year:$('#fAnYear').val()}, function(r){
    if(!r.success) return;
    renderAnnual(r.annual); renderYearUsage(r.year_usage);
  });
}
$(document).on('change', '#fAnYear', loadAnnual);
// 本年度各假別已核准累積：只列請過的假別，格式「1天+5小時」（後端 eg_leave_fmt_amount 算好）
function renderYearUsage(list){
  if(!list || !list.length){
    const y = parseInt($('#fAnYear').val() || CUR_YEAR, 10);
    $('#yearUsage').html('<span style="color:#9a7b4f;">'
      + (y === CUR_YEAR ? '本年度' : (y + ' 年')) + '尚無已核准的請假紀錄</span>');
    return;
  }
  $('#yearUsage').html(list.map(function(u){
    return '<span style="display:inline-block;margin-right:14px;white-space:nowrap;">'
         + esc(u.leave_name) + '　<b>' + esc(u.label) + '</b></span>';
  }).join(''));
}

// ── 假別切換：代理人/證明文件需求提示 ──
$(document).on('change', '#fType', function(){
  const t = TYPES.find(x => String(x.id) === String(this.value));
  if(!t){ $('#typeHint').text(''); $('#attReq').hide(); $('#attachBlock').hide();
          $('#signerPrev').hide(); $('#agentPrev').hide(); return; }
  const unit = {hour:'可請時假（以半小時為單位）', halfday:'以半天為單位（不足半天以半天計）', day:'以整天為單位'}[t.unit_type] || '';
  let hint = unit;
  if(+t.need_approval === 0) hint += '｜此假別免主管簽核';
  else hint += '｜需簽核至第 ' + t.max_approval_level + ' 層主管';
  $('#typeHint').text(hint);
  // 代理人由系統自動解析，畫面在 doPreview() 回來時以 renderAgentPreview() 唯讀顯示
  // 沒設定「需附證明文件」的假別，整個上傳區塊不出現（使用者要求 2026-07-29）
  const needAtt = (+t.require_attachment === 1);
  $('#attReq').toggle(needAtt);
  $('#attachBlock').toggle(needAtt);
  if(needAtt){
    const minD = parseFloat(t.attach_min_days||0);
    let m = '<span style="color:#8a5a1a;">此假別需附證明文件'
          + (minD>0 ? ('（超過 '+num(minD)+' 天才需要）') : '')
          + (+t.allow_attach_later===1 ? '；可先送審、事後補件。' : '；必須先上傳才能送出。') + '</span>';
    if(!SETTINGS.attach_ready) m = '<span style="color:#a3341f;">附件根目錄尚未設定，請洽管理員（人事設定→請假系統設定）</span>';
    $('#attMsg').html(m);
  } else {
    // 切換到不需證明的假別：清掉先前可能已暫存的附件狀態，避免誤送
    $('#attMsg').text(''); $('#tempList').empty();
    const ff = document.getElementById('fFile'); if(ff) ff.value = '';
  }
  doPreview();
});
/* ── 時間：直接輸入（09:00 / 0900 / 9 都可），禁用下拉；離開欄位才正規化，即時說明錯誤原因
      規範見 ai-rules/08 第二之二節；實作比照 views/ADM/training_record.php 的 parseTime() ── */
function parseTime(v){
  var s = String(v==null?'':v).trim().replace(/[：]/g,':').replace(/\s+/g,'');
  if (s==='') return {ok:true, val:''};
  var hh, mm, m;
  if ((m = s.match(/^(\d{1,2}):(\d{1,2})$/))) { hh=+m[1]; mm=+m[2]; }
  else if ((m = s.match(/^(\d{1,2})$/)))      { hh=+m[1]; mm=0; }
  else if ((m = s.match(/^(\d)(\d{2})$/)))    { hh=+m[1]; mm=+m[2]; }
  else if ((m = s.match(/^(\d{2})(\d{2})$/))) { hh=+m[1]; mm=+m[2]; }
  else return {ok:false, msg:'時間格式應為 HH:MM（例 09:00，也可打 0900 或 9）'};
  if (hh>23) return {ok:false, msg:'小時 '+hh+' 不存在，須 0~23'};
  if (mm>59) return {ok:false, msg:'分鐘 '+mm+' 不存在，須 0~59'};
  return {ok:true, val:(hh<10?'0':'')+hh+':'+(mm<10?'0':'')+mm};
}
// 請假以半小時為單位：正規化時把分鐘吸附到 00 / 30（無條件進位，與後端時數計算一致）
function snapHalf(t){
  if(!t) return '';
  const p = t.split(':'); let h = +p[0], m = +p[1];
  if(m === 0 || m === 30) return t;
  if(m < 30) m = 30; else { m = 0; h = (h + 1) % 24; }
  return (h<10?'0':'')+h+':'+(m<10?'0':'')+m;
}
function timeErr(id, msg){
  const $i = $('#'+id), $e = $('#err'+id.substring(1));
  if(msg){ $i.css('border-color','#dd5138'); $e.text(msg); }
  else { $i.css('border-color',''); $e.text(''); }
}
// 打字中只提示不改寫（否則游標會被搶）；change/blur 才正規化
$(document).on('input', '#fTimeFrom, #fTimeTo', function(){
  const r = parseTime(this.value);
  timeErr(this.id, r.ok ? '' : r.msg);
});
$(document).on('change blur', '#fTimeFrom, #fTimeTo', function(){
  const r = parseTime(this.value);
  if(!r.ok){ timeErr(this.id, r.msg); return; }
  const snapped = snapHalf(r.val);
  this.value = snapped;
  timeErr(this.id, (r.val && snapped !== r.val) ? ('請假以半小時為單位，已調整為 ' + snapped) : '');
  shiftApplied = true;
  checkTimeOrder();
  doPreview();
});
// 同一天的結束時間不可早於或等於開始時間
function checkTimeOrder(){
  const df = $('#fDateFrom').val(), dt = $('#fDateTo').val() || df;
  const tf = $('#fTimeFrom').val(), tt = $('#fTimeTo').val();
  if(!df || !tf || !tt) return true;
  if(dt === df && tt <= tf){
    timeErr('fTimeTo', '同一天的結束時間（'+tt+'）不可早於或等於開始時間（'+tf+'）；跨夜請把結束日期改為隔天');
    return false;
  }
  return true;
}

// 時間欄只選時間，送出時再與日期組成完整時間點
function startDT(){ const d=$('#fDateFrom').val(), t=$('#fTimeFrom').val(); return (d&&t) ? (d+' '+t+':00') : ''; }
function endDT(){ const d=$('#fDateTo').val()||$('#fDateFrom').val(), t=$('#fTimeTo').val(); return (d&&t) ? (d+' '+t+':00') : ''; }

// ── 排班連動：選好請假日期 → 依當日固定班別自動帶出整天的起訖時間 ──
let shiftApplied = false;   // 使用者是否已自行動過時間欄（動過就不再自動覆蓋）
$(document).on('change', '#fDateFrom, #fDateTo', function(){
  const from = $('#fDateFrom').val();
  if (from && $('#fDateTo').val() && $('#fDateTo').val() < from) $('#fDateTo').val(from);
  shiftApplied = false;     // 換日期＝重新帶入
  applyShift(false);
});
function applyShift(force){
  const from = $('#fDateFrom').val();
  if(!from){ $('#shiftHint').hide(); return; }
  if(shiftApplied && !force) return;   // 使用者手動調過時間就不覆蓋，除非按「重新帶入」
  const to = $('#fDateTo').val() || from;
  $.getJSON(API, {action:'roster_shift', start_date:from, end_date:to}, function(r){
    if(!r.success) return;
    const dPart = s => String(s||'').substring(0,10);
    const tPart = s => String(s||'').substring(11,16);
    $('#fTimeFrom').val(tPart(r.start_datetime));
    $('#fTimeTo').val(tPart(r.end_datetime));
    // 跨夜班的結束會落到隔天，結束日期要一併帶出來，使用者才看得懂
    $('#fDateTo').val(dPart(r.end_datetime) === from ? '' : dPart(r.end_datetime));
    shiftApplied = false;   // 這是系統帶入的，之後使用者若手動改才鎖住

    const has = r.start_shift || r.end_shift;
    let msg, bg, bd, col;
    if(has && (!r.missing || !r.missing.length)){
      const s = r.start_shift, e = r.end_shift;
      msg = '<i class="fa fa-calendar-check-o"></i> 已依排班帶入整天請假：<b>' + esc(s.name) + '</b> '
          + esc(s.start_time) + '～' + esc(e ? e.end_time : s.end_time)
          + (s.is_overnight ? '（跨夜，結束時間為隔天）' : '')
          + '。只請部分時段的話請直接改下方時間。';
      bg = '#eef5e6'; bd = '#b8cf9a'; col = '#4d6b2e';
    } else if(has){
      msg = '<i class="fa fa-exclamation-triangle"></i> 部分日期（' + esc(r.missing.join('、'))
          + '）查不到排班，該端已用預設上下班時間帶入，<b>請確認下方時間是否正確</b>。';
      bg = '#fdf0dc'; bd = '#e9c98f'; col = '#8a5a1a';
    } else {
      msg = '<i class="fa fa-info-circle"></i> 這幾天在「輪值排班表 → 固定班別排班」查不到您的排班，'
          + '已用預設上下班時間帶入，<b>請自行確認下方時間</b>。';
      bg = '#fdf0dc'; bd = '#e9c98f'; col = '#8a5a1a';
    }
    $('#shiftHint').html(msg)
      .css({background:bg, border:'1px solid '+bd, color:col}).show();
    doPreview();
  });
}

// ── 職務代理人預覽（唯讀）：系統依人事設定的順位自動決定，第一順位同期間也請假就換下一位 ──
function renderAgentPreview(r){
  const $p = $('#agentPrev');
  if(!r.agent_required){
    $p.html('<b><i class="fa fa-user-o"></i> 職務代理人：</b>此假別不需代理人。').show();
    return;
  }
  const ags = r.agents || [];
  if(!ags.length){
    $p.html('<b><i class="fa fa-user-o"></i> 職務代理人：</b>'
      + '<span style="color:#8a5a1a;">您尚未設定職務代理人，視為此職務不需代理，可直接送出。'
      + '若需要指定（例如主管職），請洽人事於「人事設定 → 代理人設定」新增。</span>').show();
    return;
  }
  let h = '<b><i class="fa fa-user-o"></i> 請假期間將由以下人員代理（系統依「人事設定」的順位自動決定，不需挑選）：</b><br>';
  ags.forEach(function(a){
    const mark = a.is_main === true ? '[主]' : (a.is_main === false ? '[兼]' : '');
    h += '<span class="lvl">' + esc(mark + ' ' + (a.scope_label||'')) + '</span>';
    if(a.agent_user_id){
      h += '<b>' + esc(a.agent_name) + '</b>'
         + ' <span style="color:#9a7b4f;">— ' + esc(a.reason) + '</span><br>';
    } else {
      h += '<span style="color:#a3341f;">⚠ 無可用代理人</span>'
         + ' <span style="color:#9a7b4f;">— ' + esc(a.reason) + '</span><br>';
    }
  });
  h += '<span style="color:#9a7b4f;font-size:11.5px;">※ 核准後系統會通知上列代理人接手職務；'
     + '若代理人在您請假期間也請假，會自動改由下一順位代理。</span>';
  $p.html(h).show();
}

// ── 試算＋簽核人預覽 ──
let previewTimer = null;
function doPreview(){
  clearTimeout(previewTimer);
  previewTimer = setTimeout(function(){
    const tid = $('#fType').val(), s = startDT(), e = endDT();
    if(!tid){ return; }
    $.getJSON(API, {action:'preview', leave_type_id:tid, start:s, end:e}, function(r){
      if(!r.success) return;
      if(r.amount){
        $('#fAmount').val(r.amount.hours>0 ? (num(r.amount.hours)+' 小時（'+num(r.amount.days)+' 天，'+r.amount.workdays+' 個工作日）') : '時段內無工作日');
      } else $('#fAmount').val('');
      if(r.annual) renderAnnual(r.annual);
      // 代理人：系統自動依順位解析，畫面只顯示結果與原因（申請人無法挑選）
      renderAgentPreview(r);
      if(r.signers && r.signers.length){
        let h = '<b><i class="fa fa-users"></i> 將由以下人員簽核：</b><br>';
        r.signers.forEach(function(g){
          h += '<span class="lvl">第 '+g.level+' 層</span>' + esc(g.signer_name||('#'+g.signer_id));
          if(g.is_delegated) h += ' <span class="tag-soft">代理 '+esc(g.target_name)+'</span>';
          if(g.is_sod_escalated) h += ' <span class="tag-soft">權責迴避直升</span>';
          if(g.fallback) h += ' <span class="tag-soft">最終裁決者</span>';
          h += ' <span style="color:#9a7b4f;">— '+esc(g.reason)+'</span><br>';
        });
        h += '<span style="color:#9a7b4f;font-size:11.5px;">※ 實際簽核人以送審／簽核當下的行程與代理設定為準。</span>';
        $('#signerPrev').html(h).show();
      } else if(r.signers){
        $('#signerPrev').html('<b>此假別免主管簽核</b>，送出後直接核准並寫入行事曆。').show();
      }
    });
  }, 250);
}

// ── 附件：新增中先傳 temp ──
function uploadTemp(){
  const f = document.getElementById('fFile').files[0];
  if(!f){ $('#attMsg').html('<span style="color:#a3341f;">請先選擇檔案</span>'); return; }
  const tid = $('#fType').val();
  if(!tid){ $('#attMsg').html('<span style="color:#a3341f;">請先選擇假別</span>'); return; }
  const fd = new FormData();
  fd.append('action','attach_upload'); fd.append('csrf',CSRF);
  fd.append('upload_token', uploadToken); fd.append('leave_type_id', tid); fd.append('file', f);
  $('#attMsg').html('上傳中…');
  $.ajax({url:API, type:'POST', data:fd, processData:false, contentType:false, dataType:'json'})
   .done(function(r){
      $('#attMsg').html(r.success ? '<span style="color:#4d6b2e;">已上傳 '+esc(r.file_name)+'</span>'
                                  : '<span style="color:#a3341f;">'+esc(r.message)+'</span>');
      if(r.success){ document.getElementById('fFile').value=''; loadTempList(); }
   }).fail(function(){ $('#attMsg').html('<span style="color:#a3341f;">上傳失敗</span>'); });
}
function loadTempList(){
  $.getJSON(API, {action:'attach_temp_list', upload_token: uploadToken}, function(r){
    if(!r.success) return;
    $('#tempList').html((r.rows||[]).map(function(a){
      return '<div class="att-item"><i class="fa fa-paperclip"></i> '+esc(a.file_name)
           + ' <span style="color:#9a7b4f;">('+Math.round(a.file_size/1024)+' KB)</span>'
           + ' <a href="javascript:;" onclick="delAttach('+a.id+',1)" style="color:var(--coral);">移除</a></div>';
    }).join(''));
  });
}
function delAttach(id, isTemp){
  if(!confirm('確定移除此附件？')) return;
  $.post(API, {action:'attach_delete', csrf:CSRF, id:id}, function(r){
    if(!r.success){ alert(r.message); return; }
    if(isTemp) loadTempList(); else openDetail(curDetailId);
  }, 'json');
}

// ── 送審 ──
function submitLeave(){
  const tid = $('#fType').val(), s = startDT(), e = endDT();
  if(!tid){ $('#applyMsg').html('<span style="color:#a3341f;">請選擇假別</span>'); return; }
  if(!$('#fDateFrom').val()){ $('#applyMsg').html('<span style="color:#a3341f;">請選擇開始日期</span>'); $('#fDateFrom').focus(); return; }
  if(!$('#fTimeFrom').val()){ $('#applyMsg').html('<span style="color:#a3341f;">請選擇開始時間</span>'); $('#fTimeFrom').focus(); return; }
  if(!$('#fTimeTo').val()){ $('#applyMsg').html('<span style="color:#a3341f;">請選擇結束時間</span>'); $('#fTimeTo').focus(); return; }
  const pf = parseTime($('#fTimeFrom').val()), pt = parseTime($('#fTimeTo').val());
  if(!pf.ok){ timeErr('fTimeFrom', pf.msg); $('#fTimeFrom').focus(); return; }
  if(!pt.ok){ timeErr('fTimeTo', pt.msg); $('#fTimeTo').focus(); return; }
  if(!checkTimeOrder()){ $('#fTimeTo').focus(); return; }
  if(!s || !e){ $('#applyMsg').html('<span style="color:#a3341f;">請填寫完整的開始與結束日期時間</span>'); return; }
  $('#btnSubmit').prop('disabled', true);
  $('#applyMsg').html(editingId ? '儲存中…' : '送出中…');
  const payload = {
    csrf:CSRF, leave_type_id:tid, start_datetime: s, end_datetime: e,
    reason: $('#fReason').val()   // 代理人由後端自動解析，不從前端傳
  };
  if(editingId){ payload.action = 'update'; payload.id = editingId; }
  else { payload.action = 'submit'; payload.upload_token = uploadToken; }
  $.post(API, payload, function(r){
    $('#btnSubmit').prop('disabled', false);
    if(!r.success){ $('#applyMsg').html('<span style="color:#a3341f;"><i class="fa fa-times-circle"></i> '+esc(r.message)+'</span>'); return; }
    if(editingId){
      $('#applyMsg').html('<span style="color:#4d6b2e;"><i class="fa fa-check-circle"></i> '+esc(r.message)+'（單號 #'+editingId+'）</span>');
      cancelEdit();
      loadList();
      refreshPendingCount();
      return;
    }
    let msg = '<span style="color:#4d6b2e;"><i class="fa fa-check-circle"></i> '+esc(r.message)+'（單號 #'+r.id+'）行事曆已標示為「申請中」。</span>';
    if(r.need_attach_later) msg += '<br><span class="tag-warn">待補證明</span> <span style="color:#8a5a1a;">請盡快到「我的請假單」開啟本單補上傳證明文件。</span>';
    $('#applyMsg').html(msg);
    resetForm(true);
    refreshPendingCount();
  }, 'json').fail(function(){ $('#btnSubmit').prop('disabled', false); $('#applyMsg').html('<span style="color:#a3341f;">'+(editingId?'儲存':'送出')+'失敗</span>'); });
}
function resetForm(keepMsg){
  $('#fType').val(''); $('#fTimeFrom').val(''); $('#fTimeTo').val(''); $('#fAmount').val('');
  $('#fDateFrom').val(''); $('#fDateTo').val(''); $('#shiftHint').hide(); shiftApplied = false;
  $('#fReason').val(''); $('#tempList').empty();
  $('#signerPrev').hide(); $('#agentPrev').hide(); $('#typeHint').text('');
  $('#attReq').hide(); $('#attachBlock').hide(); $('#attMsg').text('');
  const ff0 = document.getElementById('fFile'); if(ff0) ff0.value = '';
  if(!keepMsg) $('#applyMsg').empty();
  uploadToken = newToken();
  loadAnnual();
}

// ── 範圍／狀態按鈕切換（則一選擇）──
function syncFilterBtns(){
  $('.scope-btn').removeClass('on').filter('[data-scope="'+$('#fScope').val()+'"]').addClass('on');
  $('.status-btn').removeClass('on').filter('[data-status="'+$('#fStatus').val()+'"]').addClass('on');
}
$(document).on('click', '.scope-btn', function(){
  $('#fScope').val($(this).data('scope')); syncFilterBtns(); listPage = 1; loadList();
});
$(document).on('click', '.status-btn', function(){
  $('#fStatus').val(String($(this).data('status')||'')); syncFilterBtns(); listPage = 1; loadList();
});

// ── 列表 ──
// 列表查詢條件（列表／CSV／PDF 共用同一份，避免匯出的內容跟畫面對不起來）
function listQuery(extra){
  return $.extend({action:'list', scope:$('#fScope').val(), status:$('#fStatus').val(),
                   year:($('#fYear').val() || CUR_YEAR)}, extra || {});
}
/* 年度下拉：選項由後端依「目前範圍」算出（不受狀態／年度篩選影響，切走了才切得回來）。
   每次載入都重畫會把使用者選的年度洗掉，所以只有選項真的變了才重畫，並保留目前選擇。 */
let yearOptsKey = '';
function renderYearOptions(years){
  const cur = $('#fYear').val() || String(CUR_YEAR);
  const list = (years || []).slice();
  if(list.indexOf(CUR_YEAR) < 0) list.push(CUR_YEAR);          // 今年就算沒資料也要能選回來
  list.sort((a,b) => b - a);
  const key = list.join(',');
  if(key === yearOptsKey) return;
  yearOptsKey = key;
  // 「全部年度」排第一個：雙擊篩選欄＝解除該欄篩選（全站 UI 規則），select 的雙擊會回到第一個選項
  $('#fYear').html('<option value="all">全部年度</option>'
                  + list.map(y => '<option value="'+y+'">'+y+' 年</option>').join(''));
  $('#fYear').val((cur === 'all' || list.indexOf(parseInt(cur,10)) >= 0) ? cur : String(CUR_YEAR));
}
function loadList(){
  const q = listQuery({page:listPage, per:$('#fPer').val()});
  $.getJSON(API, q, function(r){
    if(!r.success){ $('#listBody').html('<tr><td colspan="10" class="empty-note">'+esc(r.message)+'</td></tr>'); $('#pagerBtns').empty(); return; }
    listTotal = r.total;
    renderYearOptions(r.years);
    $('#listBody').html((r.rows||[]).length ? r.rows.map(rowHtml).join('')
                        : '<tr><td colspan="10" class="empty-note">沒有符合條件的請假單</td></tr>');
    renderPager(r.total, r.page, r.per);
    const yTxt = (String(r.year) === 'all') ? '全部年度' : (r.year + ' 年');
    $('#listInfo').text(yTxt + '　共 ' + r.total + ' 筆，第 ' + r.page + ' 頁');
  });
}
function stBadge(s){
  const m = {pending:['st-pending','審核中'], approved:['st-approved','已核准'],
             rejected:['st-rejected','已退回'], canceled:['st-canceled','已取消'],
             cancel_pending:['st-cancelpend','撤回待簽核']};
  const x = m[s] || ['st-canceled', s];
  return '<span class="st-badge '+x[0]+'">'+x[1]+'</span>';
}

/* ── 起訖顯示（列表／待簽核／詳情共用同一份，避免各處格式不一致）──
   規則（2026-07-29 使用者要求）：
     整天請假（起訖時間＝該日班別或涵蓋整個上班時間）→ 只顯示日期
     同一天內的部分時段          → 日期 + 時間（日期只出現一次）
     跨日                        → 兩邊都顯示日期，中間用 ~
*/
function fmtPeriod(startStr, endStr, isFullDay){
  const s = String(startStr||''), e = String(endStr||'');
  const sd = s.substring(0,10), ed = e.substring(0,10);
  const st = s.substring(11,16), et = e.substring(11,16);
  if(sd === ed){
    if(isFullDay) return sd;                       // 整天：只顯示日期
    return sd + '　' + st + '～' + et;             // 同日部分時段：日期只出現一次
  }
  // 跨日
  if(isFullDay) return sd + ' ~ ' + ed;
  return sd + ' ' + st + ' ~ ' + ed + ' ' + et;
}
// 是否視為「整天請假」：以後端算出的天數為準（半小時單位下，整天＝天數為整數且時數≥一天工時）
function isFullDayLeave(o){
  const days = parseFloat(o.total_days || 0), hrs = parseFloat(o.total_hours || 0);
  const perDay = parseFloat((SETTINGS && SETTINGS.hours_per_day) || 8);
  return days > 0 && Math.abs(days - Math.round(days)) < 0.001 && hrs >= perDay - 0.001;
}
function rowHtml(o){
  // 標記欄只放「額外資訊」；狀態一律只在狀態欄用 stBadge 呈現，
  // 不再另外放申請中/簽章圖示（同一件事用兩種樣式顯示會不一致，2026-07-29 使用者回報）
  let tags = '';
  if(+o.is_backdated === 1) tags += '<span class="tag-soft">補請假</span> ';
  if(o.attach_status === 'pending') tags += '<span class="tag-warn">待補證明</span> ';
  if(tags === '') tags = '<span style="color:#c9b89c;">—</span>';
  // 整列底色依狀態（已核准不上色，讓需要注意的三種狀態跳出來）
  const rowCls = {pending:'row-pending', rejected:'row-rejected', canceled:'row-canceled',
                  cancel_pending:'row-cancelpend'}[o.status] || '';
  return '<tr class="'+rowCls+'">'
    + '<td>#'+o.id+'</td>'
    + '<td>'+esc(o.applicant_name)+'</td>'
    + '<td>'+esc(o.leave_name)+'</td>'
    + '<td style="white-space:nowrap;">'+esc(fmtPeriod(o.start_datetime, o.end_datetime, isFullDayLeave(o)))+'</td>'
    + '<td>'+num(o.total_hours)+' 時 / '+num(o.total_days)+' 天</td>'
    + '<td>'+esc(o.agent_name||'—')+'</td>'
    + '<td>'+stBadge(o.status)+'</td>'
    + '<td>'+tags+'</td>'
    + '<td style="white-space:nowrap;">'+esc(String(o.submit_time||'').substring(0,16))+'</td>'
    + '<td class="no-print"><button class="btn btn-xs btn-default" onclick="openDetail('+o.id+')">檢視</button></td>'
    + '</tr>';
}
function renderPager(total, page, per){
  const pages = Math.max(1, Math.ceil(total/per));
  if(pages <= 1){ $('#pagerBtns').empty(); return; }
  let h = '<button '+(page<=1?'disabled':'')+' onclick="listPage='+(page-1)+';loadList()">‹</button>';
  const from = Math.max(1, page-2), to = Math.min(pages, from+4);
  for(let i=from;i<=to;i++) h += '<button class="'+(i===page?'on':'')+'" onclick="listPage='+i+';loadList()">'+i+'</button>';
  h += '<button '+(page>=pages?'disabled':'')+' onclick="listPage='+(page+1)+';loadList()">›</button>';
  $('#pagerBtns').html(h);
}

// ── 匯出（後端全量，不用前端已載入的那一頁）──
function exportCsv(){
  $.getJSON(API, listQuery({page:1, per:50}), function(first){
    if(!first.success){ alert(first.message); return; }
    const total = first.total, per = 50, pages = Math.max(1, Math.ceil(total/per));
    const all = [], reqs = [];
    for(let p=1;p<=pages;p++) reqs.push($.getJSON(API, listQuery({page:p, per:per})));
    $.when.apply($, reqs).done(function(){
      const results = (pages === 1) ? [arguments] : arguments;
      for(let i=0;i<pages;i++){
        const r = (pages === 1) ? first : results[i][0];
        (r.rows||[]).forEach(o => all.push(o));
      }
      const head = ['單號','申請人','假別','開始','結束','時數','天數','代理人','狀態','補請假','證明文件','送出時間'];
      const stTxt = {pending:'審核中',approved:'已核准',rejected:'已退回',canceled:'已取消'};
      const atTxt = {not_required:'不需要',pending:'待補證明',done:'已附'};
      const lines = [head.join(',')].concat(all.map(function(o){
        return ['#'+o.id, o.applicant_name, o.leave_name, o.start_datetime, o.end_datetime,
                num(o.total_hours), num(o.total_days), o.agent_name||'', stTxt[o.status]||o.status,
                (+o.is_backdated===1?'是':''), atTxt[o.attach_status]||'', o.submit_time]
               .map(v => '"' + String(v==null?'':v).replace(/"/g,'""') + '"').join(',');
      }));
      const blob = new Blob(['﻿' + lines.join('\r\n')], {type:'text/csv;charset=utf-8;'});
      const a = document.createElement('a');
      a.href = URL.createObjectURL(blob);
      const yTag = (String($('#fYear').val()) === 'all') ? '全部年度' : ($('#fYear').val() + '年');
      a.download = '請假單_' + yTag + '_' + new Date().toISOString().substring(0,10) + '.csv';
      a.click();
    });
  });
}
function exportPdf(){
  // 列印一律交給瀏覽器引擎原生分頁（單一表格，不用 JS 量高度自算分頁）
  $.getJSON(API, listQuery({page:1, per:50}), function(first){
    if(!first.success){ alert(first.message); return; }
    const pages = Math.max(1, Math.ceil(first.total/50)), reqs = [];
    for(let p=1;p<=pages;p++) reqs.push($.getJSON(API, listQuery({page:p, per:50})));
    $.when.apply($, reqs).done(function(){
      const results = (pages === 1) ? [[first]] : arguments;
      const all = [];
      for(let i=0;i<pages;i++){ const r = (pages===1)?first:results[i][0]; (r.rows||[]).forEach(o=>all.push(o)); }
      const stTxt = {pending:'審核中',approved:'已核准',rejected:'已退回',canceled:'已取消'};
      let h = '<html><head><meta charset="utf-8"><title>請假單列表</title><style>'
        + 'body{font-family:"Microsoft JhengHei",sans-serif;font-size:12px;color:#3a2c1a;}'
        + 'h2{text-align:center;font-size:16px;margin:6px 0;}'
        + 'table{width:100%;border-collapse:collapse;} th{background:#faf3e7;color:#b06f27;}'
        + 'th,td{border:1px solid #e6d8c3;padding:4px 6px;text-align:left;}'
        + 'tfoot{display:table-footer-group;} thead{display:table-header-group;}'
        + '.ft{text-align:center;color:#9a7b4f;font-size:11px;margin-top:8px;}'
        + '</style></head><body>';
      const yTxt = (String($('#fYear').val()) === 'all') ? '全部年度' : ($('#fYear').val() + ' 年度');
      h += '<h2>' + esc(SETTINGS.print_header || '請假單列表') + '</h2>';
      h += '<div style="text-align:center;color:#9a7b4f;font-size:11.5px;margin-bottom:6px;">' + esc(yTxt) + '</div>';
      h += '<table><thead><tr><th>單號</th><th>申請人</th><th>假別</th><th>開始</th><th>結束</th><th>時數</th><th>天數</th><th>代理人</th><th>狀態</th></tr></thead><tbody>';
      all.forEach(function(o){
        h += '<tr><td>#'+o.id+'</td><td>'+esc(o.applicant_name)+'</td><td>'+esc(o.leave_name)+'</td>'
           + '<td>'+esc(String(o.start_datetime).substring(0,16))+'</td><td>'+esc(String(o.end_datetime).substring(0,16))+'</td>'
           + '<td>'+num(o.total_hours)+'</td><td>'+num(o.total_days)+'</td><td>'+esc(o.agent_name||'')+'</td>'
           + '<td>'+(stTxt[o.status]||o.status)+'</td></tr>';
      });
      h += '</tbody></table><div class="ft">' + esc(SETTINGS.print_footer||'') + '　列印時間：' + new Date().toLocaleString('zh-TW') + '　共 ' + all.length + ' 筆</div></body></html>';
      const w = window.open('', '_blank');
      w.document.write(h); w.document.close();
      setTimeout(function(){ w.print(); }, 300);
    });
  });
}
function openPrintSetting(){ $('#printSetModal').modal('show'); }
function savePrintSetting(){
  $.post(API, {action:'save_print_setting', csrf:CSRF, header:$('#psHeader').val(), footer:$('#psFooter').val()}, function(r){
    alert(r.message || (r.success?'已儲存':'儲存失敗'));
    if(r.success){ SETTINGS.print_header = $('#psHeader').val(); SETTINGS.print_footer = $('#psFooter').val(); $('#printSetModal').modal('hide'); }
  }, 'json');
}

// ── 詳情 ──
function openDetail(id){
  curDetailId = id;
  // 先把所有動作鈕收起來，避免上一張單的按鈕殘留在這張單上
  $('#btnCancelLeave, #btnEditLeave, #btnReqChange, #btnDeleteLeave').hide();
  $('#editHint').text('');
  $('#detailBody').html('<div class="empty-note">載入中…</div>');
  $('#detailModal').modal('show');
  $.getJSON(API, {action:'detail', id:id}, function(r){
    if(!r.success){ $('#detailBody').html('<div class="empty-note">'+esc(r.message)+'</div>'); $('#btnCancelLeave').hide(); return; }
    const o = r.request;
    curDetailCanCancel = r.can_cancel; curDetailStatus = o.status;
    let h = '<table class="lv-tbl" style="margin-bottom:14px;"><tbody>';
    const row = (k,v) => '<tr><th style="width:110px;">'+k+'</th><td>'+v+'</td></tr>';
    h += row('單號', '#'+o.id + '　' + stBadge(o.status));
    h += row('申請人', esc(o.applicant_name));
    h += row('假別', esc(o.leave_name));
    h += row('請假時段', esc(fmtPeriod(o.start_datetime, o.end_datetime, isFullDayLeave(o)))
             + (isFullDayLeave(o) ? ' <span class="tag-soft">整天</span>' : '')
             + (+o.is_backdated===1 ? ' <span class="tag-soft">補請假</span>' : ''));
    h += row('時數／天數', num(o.total_hours)+' 小時 / '+num(o.total_days)+' 天');
    // 職務代理人：每個職務身分各一位（系統依人事設定順位自動解析），舊單退回單一欄位
    let agentHtml = '';
    if((r.agents||[]).length){
      agentHtml = r.agents.map(function(a){
        const mark = (String(a.is_main) === '1') ? '[主] ' : (a.is_main === null ? '' : '[兼] ');
        return '<div>' + esc(mark + (a.scope_label||'')) + '：'
             + (a.agent_user_id ? '<b>'+esc(a.agent_name)+'</b>' : '<span style="color:#a3341f;">無可用代理人</span>')
             + ' <span style="color:#9a7b4f;font-size:12px;">'+esc(a.resolve_reason||'')+'</span></div>';
      }).join('');
    } else {
      agentHtml = esc(o.agent_name || '—');
    }
    h += row('職務代理人', agentHtml);
    h += row('請假原因', esc(o.reason||'—'));
    if(o.status === 'canceled') h += row('銷假原因', esc(o.cancel_reason||'—') + '（' + esc(String(o.canceled_at||'').substring(0,16)) + '）');
    h += '</tbody></table>';

    // 證明文件（含補件）：假別沒設定需附證明就整段不顯示；
    // 但若該單先前已有附件（例如假別設定事後被改掉），仍要列出來讓人看得到、不憑空消失。
    const needAtt = (+o.require_attachment === 1);
    const hasAtt  = (r.attachments||[]).length > 0;
    if(needAtt || hasAtt){
      h += '<h4 style="color:var(--amber-d);font-size:14px;">證明文件'
         + (o.attach_status==='pending' ? ' <span class="tag-warn">待補證明</span>' : '')
         + (!needAtt && hasAtt ? ' <span class="tag-soft">此假別現已不需證明</span>' : '')
         + '</h4>';
      h += hasAtt ? (r.attachments||[]).map(function(a){
          return '<div class="att-item"><i class="fa fa-paperclip"></i> '
               + '<a href="'+API+'?action=attach_download&id='+a.id+'" target="_blank">'+esc(a.file_name)+'</a>'
               + ' <span style="color:#9a7b4f;">('+Math.round(a.file_size/1024)+' KB, '+esc(String(a.uploaded_at).substring(0,16))+')</span>'
               + (String(o.employee_id)===String(ME.id) && o.status!=='approved' ? ' <a href="javascript:;" onclick="delAttach('+a.id+',0)" style="color:var(--coral);">移除</a>' : '')
               + '</div>';
        }).join('') : '<div style="font-size:12.5px;color:#9a7b4f;padding:4px 0;">（尚無附件）</div>';
      // 補上傳只在「此假別需附證明」時提供
      if(needAtt && String(o.employee_id) === String(ME.id) && o.status !== 'rejected' && o.status !== 'canceled'){
        h += '<div class="no-print" style="margin-top:6px;display:flex;gap:8px;align-items:center;flex-wrap:wrap;">'
           + '<input type="file" id="dFile" accept=".jpg,.jpeg,.png,.pdf" style="font-size:12.5px;">'
           + '<button class="btn btn-xs btn-amber" onclick="uploadLater('+o.id+')"><i class="fa fa-upload"></i> 補上傳</button>'
           + '<span id="dAttMsg" style="font-size:12px;"></span></div>';
      }
    }

    // 簽核流程（leave_approval：每層狀態）＋ 簽章軌跡（leave_sign_record）
    h += '<h4 style="color:var(--amber-d);font-size:14px;margin-top:16px;">簽核流程</h4>';
    if((r.approvals||[]).length){
      r.approvals.forEach(function(a){
        const done = a.status !== 'pending';
        h += '<div class="flow-row"><span class="lvl-badge">第 '+a.approval_level+' 層</span>'
           + '<span>'+esc(a.approver_name)+'</span>'
           + (a.delegate_name ? '<span class="tag-soft">由代理人 '+esc(a.delegate_name)+' 簽</span>' : '')
           + stBadge(a.status === 'pending' ? 'pending' : a.status)
           + (done && a.status==='approved' && window.EGStamp ? EGStamp.badge('sign',18) : '')
           + '<span style="color:#9a7b4f;flex:1;">'+esc(a.remark||'')+'</span>'
           + '<span style="color:#9a7b4f;font-size:12px;">'+esc(String(a.approval_time||'').substring(0,16))+'</span></div>';
      });
    } else h += '<div style="font-size:12.5px;color:#9a7b4f;">（此假別免主管簽核）</div>';

    /* 簽章軌跡：單層一次決行時，軌跡內容與上面的簽核流程完全一樣（同一人同一時間同一意見），
       兩塊並列就是重複顯示（2026-07-30 使用者回報）。因此只在「軌跡比流程多出資訊」時才顯示：
         · 有非簽核層的動作（step_no>=98：修改內容 98／撤回銷假 99）
         · 同一層有多筆紀錄（退回後重簽，流程表只留最後結果、軌跡才看得到完整過程）
         · 軌跡筆數多於流程已決行的層數
       其餘情況（例如本例：單層退回一次）就只顯示簽核流程，不重複。 */
    const recs = r.sign_records || [];
    const decided = (r.approvals || []).filter(a => a.status !== 'pending').length;
    const stepCount = {};
    recs.forEach(s => { stepCount[s.step_no] = (stepCount[s.step_no] || 0) + 1; });
    const hasExtraStep = recs.some(s => +s.step_no >= 98);
    const hasRepeat = Object.keys(stepCount).some(k => stepCount[k] > 1);
    if(recs.length && (hasExtraStep || hasRepeat || recs.length > decided)){
      h += '<h4 style="color:var(--amber-d);font-size:14px;margin-top:16px;">簽章軌跡'
         + '<span style="font-weight:400;font-size:11.5px;color:#9a7b4f;">（含修改／撤回／重簽等完整歷程）</span></h4>';
      recs.forEach(function(s){
        const actTxt = {approved:'核准', rejected:'退回', canceled:'撤回／銷假', edited:'修改內容'}[s.action] || s.action;
        const stepTxt = (+s.step_no === 98) ? '修改' : ((+s.step_no === 99) ? '撤回' : ('第 '+s.step_no+' 層'));
        h += '<div class="flow-row">'
           + (s.action==='approved' && window.EGStamp ? EGStamp.badge('sign',18) : '<span class="lvl-badge">'+stepTxt+'</span>')
           + '<span>'+esc(s.signer_name)+'</span><span>'+actTxt+'</span>'
           + '<span style="color:#9a7b4f;flex:1;">'+esc(s.remark||'')+'</span>'
           + '<span style="color:#9a7b4f;font-size:12px;">'+esc(String(s.signed_at||'').substring(0,16))+'</span></div>';
      });
    }
    $('#detailBody').html(h);

    // 撤回/銷假按鈕依「請假日期」分三種情形（2026-07-30 規則）
    curCancelMode = r.cancel_mode || 'direct';
    if(r.can_cancel && curCancelMode !== 'blocked'){
      const base = (o.status==='approved' ? '銷假' : '撤回申請');
      $('#btnCancelLeave').show().html('<i class="fa fa-undo"></i> '
        + (curCancelMode === 'approval' ? (base + '（需主管簽核）') : base));
    } else $('#btnCancelLeave').hide();
    if(r.can_cancel && curCancelMode === 'blocked'){
      $('#editHint').html('<span style="color:#a3341f;">請假期間已結束，為避免「已休假卻無請假紀錄」，'
        + '不開放自行撤回；如確有需要請洽管理員。</span>');
    }

    // 修改：審核前（且尚無人簽核）可直接改；已核准提供「申請修改」
    curDetailReq = o;
    if(IS_SUPERADMIN) $('#btnDeleteLeave').show();
    $('#btnEditLeave').toggle(!!r.can_edit);
    $('#btnReqChange').toggle(!!r.can_request_change);
    $('#editHint').text((!r.can_edit && r.edit_reason && String(o.employee_id)===String(ME.id)) ? r.edit_reason : '');
  });
}

// ── 徹底刪除（僅管理者，測試用）：連通知、簽核紀錄、行事曆事件、附件一起刪，不可回復 ──
function doDelete(){
  const o = curDetailReq; if(!o) return;
  $('#delReqNo').text('#' + o.id);
  $('#delConfirmId').val(''); $('#delPassword').val(''); $('#delMsg').text('');
  // 先關詳情再開刪除窗（Bootstrap3 兩窗接續開會殘留遮罩，等關完再開）
  $('#detailModal').one('hidden.bs.modal', function(){ $('#deleteModal').modal('show'); }).modal('hide');
}
function doDeleteConfirm(){
  const o = curDetailReq; if(!o) return;
  const cid = $('#delConfirmId').val().trim(), pw = $('#delPassword').val();
  if(cid !== String(o.id)){ $('#delMsg').html('<span style="color:#a3341f;">單號不符，請輸入 '+o.id+'</span>'); $('#delConfirmId').focus(); return; }
  if(!pw){ $('#delMsg').html('<span style="color:#a3341f;">請輸入最高權限帳號的密碼</span>'); $('#delPassword').focus(); return; }
  $('#delMsg').text('刪除中…');
  $.post(API, {action:'delete', csrf:CSRF, id:o.id, confirm_id:cid, password:pw}, function(r){
    if(!r.success){ $('#delMsg').html('<span style="color:#a3341f;">'+esc(r.message)+'</span>'); $('#delPassword').val('').focus(); return; }
    $('#delPassword').val('');
    $('#deleteModal').modal('hide');
    alert(r.message);
    loadList(); refreshPendingCount();
  }, 'json').fail(function(){ $('#delMsg').html('<span style="color:#a3341f;">刪除請求失敗</span>'); });
}

// ── 修改審核前的單：把原內容帶回申請分頁，改成「儲存修改」模式 ──
let editingId = 0, curDetailReq = null;
function startEdit(){
  const o = curDetailReq; if(!o) return;
  $('#detailModal').modal('hide');
  editingId = o.id;
  topTab('apply', document.getElementById('tbApply'));
  $('#fType').val(o.leave_type_id).trigger('change');
  const sd = String(o.start_datetime||''), ed = String(o.end_datetime||'');
  $('#fDateFrom').val(sd.substring(0,10));
  $('#fTimeFrom').val(sd.substring(11,16));
  $('#fDateTo').val(ed.substring(0,10) === sd.substring(0,10) ? '' : ed.substring(0,10));
  $('#fTimeTo').val(ed.substring(11,16));
  $('#fReason').val(o.reason || '');
  shiftApplied = true;        // 帶回原值後不要被排班覆蓋（代理人由後端依新期間重算）
  doPreview();
  $('#btnSubmit').html('<i class="fa fa-save"></i> 儲存修改（重新送審）');
  $('#editBanner').remove();
  $('.lv-form').prepend('<div id="editBanner" style="background:#fdf0dc;border:1px solid #e9c98f;color:#8a5a1a;'
    + 'padding:8px 12px;border-radius:5px;font-size:13px;margin-bottom:10px;">'
    + '<i class="fa fa-pencil"></i> 正在修改請假單 <b>#'+o.id+'</b>，儲存後會重新送簽核。'
    + ' <a href="javascript:;" onclick="cancelEdit()" style="text-decoration:underline;">取消修改</a></div>');
  $('html, body').animate({scrollTop: 0}, 300);
}
function cancelEdit(){
  editingId = 0;
  $('#editBanner').remove();
  $('#btnSubmit').html('<i class="fa fa-paper-plane"></i> 送出申請');
  resetForm();
}
// 已核准 → 申請修改：等同銷假後重新申請（帶回原內容），流程上是「變更」
function requestChange(){
  const o = curDetailReq; if(!o) return;
  if(!confirm('已核准的請假要修改，系統會先「銷假」（撤除行事曆並通知已簽核的主管），'
    + '再把原內容帶回申請單讓您調整後重新送審。\n\n確定要申請修改嗎？')) return;
  const reason = prompt('請輸入申請修改的原因（會記入銷假原因）：', '內容需修改');
  if(reason === null) return;
  $.post(API, {action:'cancel', csrf:CSRF, id:o.id, reason:'申請修改：'+reason}, function(r){
    if(!r.success){ alert(r.message); return; }
    $('#detailModal').modal('hide');
    loadList(); refreshPendingCount();
    // 帶回原內容開新單（不是修改舊單，舊單已銷假留存紀錄）
    editingId = 0;
    topTab('apply', document.getElementById('tbApply'));
    $('#fType').val(o.leave_type_id).trigger('change');
    const sd = String(o.start_datetime||''), ed = String(o.end_datetime||'');
    $('#fDateFrom').val(sd.substring(0,10));
    $('#fTimeFrom').val(sd.substring(11,16));
    $('#fDateTo').val(ed.substring(0,10) === sd.substring(0,10) ? '' : ed.substring(0,10));
    $('#fTimeTo').val(ed.substring(11,16));
    $('#fReason').val(o.reason || '');
    shiftApplied = true; doPreview();
    $('#applyMsg').html('<span style="color:#8a5a1a;">原單 #'+o.id+' 已銷假，已帶回原內容，請調整後送出新的請假單。</span>');
    $('html, body').animate({scrollTop: 0}, 300);
  }, 'json');
}
function uploadLater(reqId){
  const f = document.getElementById('dFile').files[0];
  if(!f){ $('#dAttMsg').html('<span style="color:#a3341f;">請先選擇檔案</span>'); return; }
  const fd = new FormData();
  fd.append('action','attach_upload'); fd.append('csrf',CSRF);
  fd.append('leave_request_id', reqId); fd.append('file', f);
  $('#dAttMsg').html('上傳中…');
  $.ajax({url:API, type:'POST', data:fd, processData:false, contentType:false, dataType:'json'})
   .done(function(r){
      if(r.success){ openDetail(reqId); loadList(); }
      else $('#dAttMsg').html('<span style="color:#a3341f;">'+esc(r.message)+'</span>');
   }).fail(function(){ $('#dAttMsg').html('<span style="color:#a3341f;">上傳失敗</span>'); });
}
let curCancelMode = 'direct';
function doCancel(){
  const isApproved = curDetailStatus === 'approved';
  const needSign = (curCancelMode === 'approval');
  const msg = needSign
    ? '此單已在請假期間內（含請假當日），撤回必須經主管簽核。\n\n'
      + '送出後單據會變成「撤回待簽核」，主管核准後才真的取消（行事曆在核准前先保留）。\n\n確定要提出撤回申請嗎？'
    : (isApproved ? '確定要銷假？行事曆上的休假會撤除，並通知所有已簽核的主管與代理人。' : '確定撤回此申請？');
  if(!confirm(msg)) return;
  const reason = prompt(needSign ? '請輸入撤回原因（必填，會送給主管審核）：'
                                 : (isApproved ? '請輸入銷假原因：' : '請輸入撤回原因（可留空）：'), '');
  if(reason === null) return;
  if(needSign && !String(reason).trim()){ alert('請假期間內撤回必須填寫原因。'); return; }
  $.post(API, {action:'cancel', csrf:CSRF, id:curDetailId, reason:reason}, function(r){
    alert(r.message);
    if(r.success){ $('#detailModal').modal('hide'); loadList(); refreshPendingCount(); }
  }, 'json');
}

// ── 待我簽核 ──
function loadPending(){
  $.getJSON(API, {action:'pending_for_me'}, function(r){
    if(!r.success){ $('#pendBody').html('<div class="empty-note">'+esc(r.message)+'</div>'); return; }
    if(!r.rows.length){ $('#pendBody').html('<div class="empty-note">目前沒有待您簽核的請假單 👍</div>'); return; }
    $('#pendBody').html(r.rows.map(function(o){
      const isCancel = (o.approval_kind === 'cancel');
      return '<div class="lv-card" style="background:#fff;'
        + (isCancel ? 'border-color:#D9873A;box-shadow:inset 4px 0 0 #F0A24B;' : '')
        + '" data-req="'+o.leave_request_id+'" data-kind="'+esc(o.approval_kind||'leave')+'">'
        + '<div style="display:flex;gap:10px;flex-wrap:wrap;align-items:center;margin-bottom:6px;">'
        + '<b style="font-size:14px;">#'+o.leave_request_id+' '+esc(o.applicant_name)+'</b>'
        + '<span class="tag-soft">'+esc(o.leave_name)+'</span>'
        + (isCancel
            ? '<span class="st-badge st-cancelpend">撤回待您簽核</span>'
            : '<span class="lvl-badge" style="background:var(--sand);border:1px solid var(--sand-d);color:var(--amber-d);border-radius:10px;padding:1px 9px;font-size:11px;">第 '+o.approval_level+' 層</span>'
              + stBadge('pending'))
        + (o.as_delegate ? '<span class="tag-warn">您以代理人身分簽核</span>' : '')
        + (+o.is_backdated===1 ? '<span class="tag-soft">補請假</span>' : '')
        + (o.attach_status==='pending' ? '<span class="tag-warn">待補證明</span>' : '')
        + '</div>'
        + (isCancel ? '<div style="font-size:13px;color:#8a5a1a;background:#fdf0dc;border:1px solid #e9c98f;'
              + 'padding:6px 10px;border-radius:4px;margin-bottom:6px;">'
              + '此單已在請假期間內，申請人要求撤回，需您簽核。撤回原因：'
              + esc(o.cancel_reason || '（未填）') + '</div>' : '')
        + '<div style="font-size:13px;color:#6b5638;">時段：'+esc(fmtPeriod(o.start_datetime, o.end_datetime, isFullDayLeave(o)))
        + '　時數：'+num(o.total_hours)+' 小時（'+num(o.total_days)+' 天）</div>'
        + (o.reason ? '<div style="font-size:13px;color:#6b5638;">原因：'+esc(o.reason)+'</div>' : '')
        + (o.as_delegate && o.delegate_reason ? '<div style="font-size:11.5px;color:#9a7b4f;">'+esc(o.delegate_reason)+'</div>' : '')
        + '<div style="margin-top:8px;display:flex;gap:8px;align-items:center;flex-wrap:wrap;">'
        + '<input type="text" class="form-control input-sm eg-inp" id="rm'+o.leave_request_id+'" placeholder="簽核意見（退回必填）" style="max-width:320px;">'
        + '<button class="btn btn-sm btn-amber" onclick="doSign('+o.leave_request_id+',\'approve\',\''+(isCancel?'cancel':'leave')+'\')">'
        + '<i class="fa fa-check"></i> ' + (isCancel ? '核准撤回' : '核准') + '</button>'
        + '<button class="btn btn-sm btn-coral" onclick="doSign('+o.leave_request_id+',\'reject\',\''+(isCancel?'cancel':'leave')+'\')">'
        + '<i class="fa fa-times"></i> ' + (isCancel ? '駁回撤回' : '退回') + '</button>'
        + '<button class="btn btn-sm btn-default" onclick="openDetail('+o.leave_request_id+')">檢視詳情</button>'
        + '</div></div>';
    }).join(''));
    bindInputUx('#pendBody');
  });
}
function doSign(id, decision, kind){
  kind = kind || 'leave';
  const remark = $('#rm'+id).val() || '';
  if(decision === 'reject' && !remark.trim()){ alert('退回必須填寫意見'); $('#rm'+id).focus(); return; }
  const msg = (kind === 'cancel')
    ? (decision === 'approve'
        ? '確定核准撤回？此請假將取消，行事曆上的休假會撤除。'
        : '確定駁回撤回？此請假仍然有效。')
    : (decision === 'approve' ? '確定核准此請假單？' : '確定退回此請假單？');
  if(!confirm(msg)) return;
  $.post(API, {action:'sign', csrf:CSRF, id:id, decision:decision, remark:remark, kind:kind}, function(r){
    alert(r.message);
    if(r.success){ loadPending(); refreshPendingCount(); }
  }, 'json');
}
function refreshPendingCount(){
  $.getJSON(API, {action:'pending_for_me'}, function(r){
    if(!r.success) return;
    if(r.count > 0) $('#pendCnt').text(r.count).show(); else $('#pendCnt').hide();
  });
}

// 從通知點進來（?sign=請假單id）：直接切到「待我簽核」並標出該單
function focusSignTarget(){
  const m = location.search.match(/[?&]sign=(\d+)/);
  if(!m) return;
  const rid = m[1];
  topTab('sign', document.getElementById('tbSign'));
  loadPending();
  // 等清單畫完再捲到該單並highlight
  setTimeout(function(){
    const $card = $('#pendBody').find('[data-req="'+rid+'"]');
    if($card.length){
      $('html, body').animate({scrollTop: $card.offset().top - 90}, 350);
      $card.css({'box-shadow':'0 0 0 2px var(--amber)','border-color':'var(--amber-d)'});
    } else {
      $('#pendBody').prepend('<div class="empty-note" style="color:#8a5a1a;">'
        + '請假單 #'+rid+' 已不在您的待簽清單中（可能已由其他有權簽核者處理，或單據已撤回）。</div>');
    }
  }, 700);
}

/* ══════════════════════════════════════════════════════════════════
   請假統計（人事／主管）
   · 所有數字都由後端 eg_leave_stats() 對「全部符合條件的資料」算完才回傳，
     本檔只負責畫圖與排版，絕不拿已載入的一頁自己加總（ai-rules/08）。
   · 假別顏色由後端固定色盤指派（暖色系，ai-rules/10），這裡不自己配色、不用亂數。
   · 圖表容器在隱藏分頁時寬高為 0，Chart.js 會畫成 0×0 → 一律等分頁顯示後才建圖。
   ══════════════════════════════════════════════════════════════════ */
<?php if ($SHOW_STATS): ?>
let ST = null;              // 後端回來的整包統計資料
let stLoaded = false;       // 是否已載過（第一次點分頁才載）
let stSub = 'month';        // 目前子分頁
let stTypesOn = null;       // 啟用的假別 id（null＝尚未初始化＝全選）
let stPersonPage = 1;
const stCharts = {};        // canvas id -> Chart 實例（重畫前要 destroy）
const MON = ['1月','2月','3月','4月','5月','6月','7月','8月','9月','10月','11月','12月'];

function openStats(){
  if(stLoaded){ statsSub(stSub, document.getElementById('sb' + stSub.charAt(0).toUpperCase() + stSub.slice(1))); return; }
  stLoaded = true;
  $.getJSON(API, {action:'stats_options'}, function(o){
    if(!o.success){ $('#stScopeNote').html('<span style="color:#a3341f;">'+esc(o.message)+'</span>'); return; }
    $('#stDept').html('<option value="0">全部部門</option>'
      + (o.depts||[]).map(d => '<option value="'+d.id+'">'+esc(d.name)+'</option>').join(''));
    $('#stUser').html('<option value="0">全部人員</option>'
      + (o.people||[]).map(p => '<option value="'+p.id+'">'+esc(p.label)+'</option>').join(''));
    loadStats();
  });
}
// 部門／人員／狀態一改就重算（年度與假別各自有處理）
$(document).on('change', '#stDept, #stUser, #stPending, #stYear', function(){ stPersonPage = 1; loadStats(); });

function stTypeIdsParam(){
  if(!ST || !stTypesOn) return '';
  const all = ST.types.map(t => +t.id);
  // 全選時不帶 type_ids，讓後端維持「不篩」的語意
  return (stTypesOn.length === all.length) ? '' : stTypesOn.join(',');
}
function loadStats(){
  if(ST && stTypesOn && stTypesOn.length === 0){
    $('#stScopeNote').html('<span style="color:#a3341f;">請至少選擇一個假別。</span>');
    return;
  }
  $('#stKpi').html('<div class="empty-note" style="width:100%;">統計計算中…</div>');
  const q = {action:'stats',
             year: $('#stYear').val() || CUR_YEAR,
             dept_id: $('#stDept').val() || 0,
             user_id: $('#stUser').val() || 0,
             with_pending: $('#stPending').is(':checked') ? 1 : 0,
             type_ids: stTypeIdsParam()};
  $.getJSON(API, q, function(r){
    if(!r.success){ $('#stKpi').html('<div class="empty-note" style="width:100%;color:#a3341f;">'+esc(r.message)+'</div>'); return; }
    ST = r.data;
    renderStatsHeader(r);
    renderStats();
  });
}
function renderStatsHeader(r){
  // 年度下拉（只在選項變動時重畫，避免洗掉使用者的選擇）
  const cur = $('#stYear').val();
  const key = (ST.years||[]).join(',');
  if($('#stYear').data('key') !== key){
    $('#stYear').data('key', key)
      .html((ST.years||[CUR_YEAR]).map(y => '<option value="'+y+'">'+y+' 年</option>').join('')
            + '<option value="all">全部年度</option>')
      .val(cur || String(CUR_YEAR));
    if(!$('#stYear').val()) $('#stYear').val(String(CUR_YEAR));
  }
  // 假別色塊（第一次載入時全選）
  if(!stTypesOn) stTypesOn = ST.types.map(t => +t.id);
  $('#stTypeChips').html(ST.types.map(function(t){
    const on = stTypesOn.indexOf(+t.id) >= 0;
    return '<span class="st-chip'+(on?'':' off')+'" data-tid="'+t.id+'">'
         + '<span class="dot" style="background:'+t.color+';"></span>' + esc(t.leave_name) + '</span>';
  }).join(''));
  const yTxt = (String(ST.year) === 'all') ? '全部年度' : (ST.year + ' 年');
  $('.st-y').text(yTxt + '　');
  $('.st-y2').text(String(ST.year) === 'all' ? '最近一年' : ST.year);
  $('#stScopeNote').html(
      '<i class="fa fa-info-circle"></i> 範圍：' + (r.scope === 'all' ? '全公司' : '我的部門（含下轄）')
    + '　狀態：' + (r.with_pending ? '已核准＋審核中' : '僅已核准')
    + '　｜　年度／月份一律以請假<b>起日</b>歸屬（與特休額度同口徑），跨月長假整筆算在起日那個月。'
    + '　｜　所有總計皆由後端對全部符合條件的資料算出，非畫面上這一頁的加總。');
}
$(document).on('click', '.st-chip', function(){
  const tid = +$(this).data('tid');
  const i = stTypesOn.indexOf(tid);
  if(i >= 0) stTypesOn.splice(i, 1); else stTypesOn.push(tid);
  stPersonPage = 1;
  loadStats();
});
function stAllTypes(on){
  if(!ST) return;
  stTypesOn = on ? ST.types.map(t => +t.id) : [];
  stPersonPage = 1;
  if(on) loadStats(); else renderStatsHeader({scope:'', with_pending:false});
}

function statsSub(k, btn){
  stSub = k;
  $('.st-sub').hide(); $('#st-'+k).show();
  $('#tab-stats .pm-tab-btn').removeClass('active'); if(btn) $(btn).addClass('active');
  renderStats();   // 分頁顯示之後才建圖（隱藏時 canvas 寬高為 0）
}

// ── KPI ──
function renderKpi(){
  const k = ST.kpi;
  const it = (lb, vl, un) => '<div class="it kpi-it"><div class="lb">'+lb+'</div><div class="vl">'
                           + vl + (un ? ' <span class="un">'+un+'</span>' : '') + '</div></div>';
  let h = it('總請假天數', num(k.total_days), '天')
        + it('總時數', num(k.total_hours), '小時')
        + it('請假單數', k.req_count, '張')
        + it('請假人數', k.people_count, '人')
        + it('平均每人', num(k.avg_days), '天');
  if(k.top_type) h += it('最多的假別', esc(k.top_type), num(k.top_type_days)+' 天');
  if(k.busiest_month) h += it('最多的月份', k.busiest_month + ' 月', num(k.busiest_month_days)+' 天');
  $('#stKpi').html(h || '<div class="empty-note" style="width:100%;">此條件下沒有資料</div>');
}

// ── Chart.js 共用 ──
function mkChart(id, cfg){
  const el = document.getElementById(id);
  if(!el || typeof Chart === 'undefined') return;
  if(stCharts[id]){ stCharts[id].destroy(); delete stCharts[id]; }
  cfg.options = $.extend(true, {responsive:true, maintainAspectRatio:false,
                                legend:{position:'bottom', labels:{fontColor:'#6b5638', boxWidth:12, fontSize:11}},
                                tooltips:{backgroundColor:'rgba(58,44,26,.92)'}}, cfg.options || {});
  stCharts[id] = new Chart(el.getContext('2d'), cfg);
}
function axesStacked(){
  return {xAxes:[{stacked:true, gridLines:{color:'#f0e7d7'}, ticks:{fontColor:'#8a6d45', fontSize:11}}],
          yAxes:[{stacked:true, gridLines:{color:'#f0e7d7'},
                  ticks:{beginAtZero:true, fontColor:'#8a6d45', fontSize:11}}]};
}
function activeTypes(){ return (ST.types||[]).filter(t => !stTypesOn || stTypesOn.indexOf(+t.id) >= 0); }
// 只保留這批資料裡真的出現過的假別，圖例才不會塞一堆 0（顏色仍是固定色盤，不會跑掉）
function usedTypes(buckets){
  return activeTypes().filter(t => buckets.some(b => (+((b.by_type||{})[t.id]) || 0) > 0));
}

function renderStats(){
  if(!ST) return;
  renderKpi();
  if(stSub === 'month')  renderMonth();
  if(stSub === 'year')   renderYear();
  if(stSub === 'trend')  renderTrend();
  if(stSub === 'people') renderPeople();
}

// ── 月度統計 ──
function renderMonth(){
  const bm = ST.by_month || [], ts = usedTypes(bm);
  mkChart('cvMonth', {type:'bar',
    data:{labels:MON, datasets: ts.map(t => ({label:t.leave_name, backgroundColor:t.color,
             data: bm.map(m => +((m.by_type||{})[t.id]) || 0)}))},
    options:{scales:axesStacked(),
             tooltips:{callbacks:{label:(ti,d)=> d.datasets[ti.datasetIndex].label+'：'+ti.yLabel+' 天'}}}});
  const bt = (ST.by_type || []).filter(t => !stTypesOn || stTypesOn.indexOf(+t.leave_type_id) >= 0);
  mkChart('cvType', {type:'doughnut',
    data:{labels: bt.map(t => t.leave_name),
          datasets:[{data: bt.map(t => t.days), backgroundColor: bt.map(t => t.color),
                     borderColor:'#fffdf9', borderWidth:2}]},
    options:{cutoutPercentage:52,
             tooltips:{callbacks:{label:(ti,d)=> d.labels[ti.index]+'：'+d.datasets[0].data[ti.index]+' 天'}}}});
  // 交叉表（含每月合計與每假別合計；合計由各格加總，來源本身已是後端全量結果）
  $('#tbMonth').html(crossTable('月份', MON.map((m,i)=>({key:m, b:bm[i]||{}})), ts));
}

// ── 年度比較 ──
function renderYear(){
  const by = ST.by_year || [], ts = usedTypes(by);
  const labels = by.map(y => y.year + ' 年');
  mkChart('cvYear', {type:'bar',
    data:{labels: labels, datasets: ts.map(t => ({label:t.leave_name, backgroundColor:t.color,
             data: by.map(y => +((y.by_type||{})[t.id]) || 0)}))},
    options:{scales:axesStacked(),
             tooltips:{callbacks:{label:(ti,d)=> d.datasets[ti.datasetIndex].label+'：'+ti.yLabel+' 天'}}}});
  $('#tbYear').html(crossTable('年度', by.map(y => ({key:y.year+' 年', b:y})), ts));
}

// ── 趨勢分析 ──
function renderTrend(){
  const tr = ST.trend || [];
  const labels = tr.map(t => t.ym);
  mkChart('cvTrend', {type:'line',
    data:{labels: labels, datasets:[{label:'請假天數', data: tr.map(t => t.total_days),
            borderColor:'#B06F27', backgroundColor:'rgba(217,154,78,.22)', pointBackgroundColor:'#B06F27',
            pointRadius:3, borderWidth:2, fill:true, lineTension:.25}]},
    options:{scales:{xAxes:[{gridLines:{color:'#f0e7d7'}, ticks:{fontColor:'#8a6d45', fontSize:10, maxTicksLimit:14}}],
                     yAxes:[{gridLines:{color:'#f0e7d7'}, ticks:{beginAtZero:true, fontColor:'#8a6d45', fontSize:11}}]}}});
  mkChart('cvTrendCnt', {type:'line',
    data:{labels: labels, datasets:[{label:'請假單數', data: tr.map(t => t.req_count),
            borderColor:'#DD5138', backgroundColor:'rgba(221,81,56,.16)', pointBackgroundColor:'#DD5138',
            pointRadius:3, borderWidth:2, fill:true, lineTension:.25}]},
    options:{scales:{xAxes:[{gridLines:{color:'#f0e7d7'}, ticks:{fontColor:'#8a6d45', fontSize:10, maxTicksLimit:12}}],
                     yAxes:[{gridLines:{color:'#f0e7d7'}, ticks:{beginAtZero:true, fontColor:'#8a6d45', fontSize:11, precision:0}}]}}});
  // 同期比較：從逐月資料切出「選定年度」與「前一年」各 12 個月
  const baseY = (String(ST.year) === 'all')
              ? (tr.length ? parseInt(tr[tr.length-1].ym.substring(0,4), 10) : CUR_YEAR)
              : parseInt(ST.year, 10);
  const pick = y => { const a = new Array(12).fill(0);
                      tr.forEach(t => { if(parseInt(t.ym.substring(0,4),10) === y) a[parseInt(t.ym.substring(5,7),10)-1] = t.total_days; });
                      return a; };
  mkChart('cvYoY', {type:'bar',
    data:{labels: MON, datasets:[
      {label: baseY + ' 年', backgroundColor:'#F0A24B', data: pick(baseY)},
      {label:(baseY-1) + ' 年', backgroundColor:'#EBD3A8', data: pick(baseY-1)}]},
    options:{scales:{xAxes:[{gridLines:{color:'#f0e7d7'}, ticks:{fontColor:'#8a6d45', fontSize:11}}],
                     yAxes:[{gridLines:{color:'#f0e7d7'}, ticks:{beginAtZero:true, fontColor:'#8a6d45', fontSize:11}}]},
             tooltips:{callbacks:{label:(ti,d)=> d.datasets[ti.datasetIndex].label+'：'+ti.yLabel+' 天'}}}});
  // 逐月明細表
  let h = '<thead><tr><th>年月</th><th class="numc">天數</th><th class="numc">時數</th><th class="numc">單數</th></tr></thead><tbody>';
  if(!tr.length) h += '<tr><td colspan="4" class="empty-note">沒有資料</td></tr>';
  tr.forEach(t => { h += '<tr><td>'+esc(t.ym)+'</td><td class="numc">'+num(t.total_days)
                       + '</td><td class="numc">'+num(t.total_hours)+'</td><td class="numc">'+t.req_count+'</td></tr>'; });
  h += '</tbody>';
  $('#tbTrend').html(h);
}

// ── 部門・人員 ──
function renderPeople(){
  const bd = ST.by_dept || [];
  mkChart('cvDept', {type:'horizontalBar',
    data:{labels: bd.map(d => d.dept_name),
          datasets:[{label:'請假天數', backgroundColor:'#B06F27', data: bd.map(d => d.days)}]},
    options:{legend:{display:false},
             scales:{xAxes:[{gridLines:{color:'#f0e7d7'}, ticks:{beginAtZero:true, fontColor:'#8a6d45', fontSize:11}}],
                     yAxes:[{gridLines:{color:'#f0e7d7'}, ticks:{fontColor:'#8a6d45', fontSize:11}}]},
             tooltips:{callbacks:{label:(ti,d)=> ti.xLabel+' 天'}}}});
  const top = (ST.by_person || []).slice(0, 15);
  mkChart('cvTop', {type:'horizontalBar',
    data:{labels: top.map(p => p.name + (p.left_company ? '（已離職）' : '')),
          datasets:[{label:'請假天數', backgroundColor:'#F0A24B', data: top.map(p => p.days)}]},
    options:{legend:{display:false},
             scales:{xAxes:[{gridLines:{color:'#f0e7d7'}, ticks:{beginAtZero:true, fontColor:'#8a6d45', fontSize:11}}],
                     yAxes:[{gridLines:{color:'#f0e7d7'}, ticks:{fontColor:'#8a6d45', fontSize:11}}]},
             tooltips:{callbacks:{label:(ti,d)=> ti.xLabel+' 天'}}}});
  // 部門表
  let h = '<thead><tr><th>部門</th><th class="numc">天數</th><th class="numc">時數</th>'
        + '<th class="numc">單數</th><th class="numc">人數</th><th class="numc">平均每人(天)</th></tr></thead><tbody>';
  if(!bd.length) h += '<tr><td colspan="6" class="empty-note">沒有資料</td></tr>';
  bd.forEach(d => { h += '<tr><td>'+esc(d.dept_name)+'</td><td class="numc">'+num(d.days)+'</td>'
                       + '<td class="numc">'+num(d.hours)+'</td><td class="numc">'+d.req_count+'</td>'
                       + '<td class="numc">'+d.people+'</td><td class="numc">'+num(d.avg_days)+'</td></tr>'; });
  h += '</tbody>';
  $('#tbDept').html(h);
  renderPeopleTable();
}
function renderPeopleTable(){
  const all = ST.by_person || [], ts = usedTypes(all);
  const per = parseInt($('#stPer').val() || 10, 10);
  const pages = Math.max(1, Math.ceil(all.length / per));
  if(stPersonPage > pages) stPersonPage = pages;
  const rows = all.slice((stPersonPage-1)*per, stPersonPage*per);
  let h = '<thead><tr><th>姓名</th><th>職稱</th><th>部門</th>'
        + ts.map(t => '<th class="numc">'+esc(t.leave_name)+'</th>').join('')
        + '<th class="numc">合計(天)</th><th class="numc">時數</th><th class="numc">單數</th></tr></thead><tbody>';
  if(!rows.length) h += '<tr><td colspan="'+(6+ts.length)+'" class="empty-note">沒有資料</td></tr>';
  rows.forEach(function(p){
    h += '<tr><td>'+esc(p.name)
       + (p.left_company ? ' <span class="tag-soft">已離職</span>' : '')
       + (!p.left_company && p.state_label && p.state_label !== '在職' ? ' <span class="tag-soft">'+esc(p.state_label)+'</span>' : '')
       + '</td><td>'+esc(p.position_name||'—')+'</td><td>'+esc(p.dept_name||'—')+'</td>'
       + ts.map(t => '<td class="numc">'+num((p.by_type||{})[t.id] || 0)+'</td>').join('')
       + '<td class="numc"><b>'+num(p.days)+'</b></td><td class="numc">'+num(p.hours)+'</td>'
       + '<td class="numc">'+p.req_count+'</td></tr>';
  });
  // 合計列：對「全部人員」加總，不是只加目前這一頁（ai-rules/08）
  if(all.length){
    const sum = f => all.reduce((s,p) => s + (+f(p) || 0), 0);
    h += '<tr class="sum-row"><td colspan="3">全部 '+all.length+' 人合計</td>'
       + ts.map(t => '<td class="numc">'+num(sum(p => (p.by_type||{})[t.id] || 0))+'</td>').join('')
       + '<td class="numc">'+num(sum(p => p.days))+'</td><td class="numc">'+num(sum(p => p.hours))+'</td>'
       + '<td class="numc">'+sum(p => p.req_count)+'</td></tr>';
  }
  h += '</tbody>';
  $('#tbPeople').html(h);
  // 分頁鈕
  let pg = '';
  if(pages > 1){
    pg += '<button '+(stPersonPage<=1?'disabled':'')+' onclick="stPersonPage='+(stPersonPage-1)+';renderPeopleTable()">‹</button>';
    const from = Math.max(1, stPersonPage-2), to = Math.min(pages, from+4);
    for(let i=from;i<=to;i++) pg += '<button class="'+(i===stPersonPage?'on':'')+'" onclick="stPersonPage='+i+';renderPeopleTable()">'+i+'</button>';
    pg += '<button '+(stPersonPage>=pages?'disabled':'')+' onclick="stPersonPage='+(stPersonPage+1)+';renderPeopleTable()">›</button>';
  }
  $('#stPagerBtns').html(pg);
  $('#stPeopleInfo').text('共 ' + all.length + ' 人，第 ' + stPersonPage + ' / ' + pages + ' 頁');
}

// ── 月/年 交叉表（共用）──
function crossTable(firstCol, buckets, ts){
  let h = '<thead><tr><th>'+firstCol+'</th>'
        + ts.map(t => '<th class="numc">'+esc(t.leave_name)+'</th>').join('')
        + '<th class="numc">合計(天)</th><th class="numc">單數</th></tr></thead><tbody>';
  if(!buckets.length) h += '<tr><td colspan="'+(3+ts.length)+'" class="empty-note">沒有資料</td></tr>';
  const colSum = ts.map(() => 0); let allSum = 0, cntSum = 0;
  buckets.forEach(function(x){
    const b = x.b || {};
    h += '<tr><td>'+esc(x.key)+'</td>'
       + ts.map(function(t, i){ const v = +((b.by_type||{})[t.id]) || 0; colSum[i] += v;
                                return '<td class="numc">'+(v ? num(v) : '<span style="color:#c9b89c;">—</span>')+'</td>'; }).join('')
       + '<td class="numc"><b>'+num(b.total_days || 0)+'</b></td>'
       + '<td class="numc">'+(b.req_count || 0)+'</td></tr>';
    allSum += (+b.total_days || 0); cntSum += (+b.req_count || 0);
  });
  h += '<tr class="sum-row"><td>合計</td>'
     + colSum.map(v => '<td class="numc">'+num(v)+'</td>').join('')
     + '<td class="numc">'+num(allSum)+'</td><td class="numc">'+cntSum+'</td></tr></tbody>';
  return h;
}

// ── 列印：把目前子分頁的圖表轉成圖片＋表格一起送去列印（交給瀏覽器原生分頁）──
function printStats(){
  if(!ST){ alert('請先等統計載入完成'); return; }
  const map = {month:{title:'月度統計', canvas:['cvMonth','cvType'], tables:[['月 × 假別 交叉表（天）','tbMonth']]},
               year:{title:'年度比較',  canvas:['cvYear'],           tables:[['年 × 假別 交叉表（天）','tbYear']]},
               trend:{title:'趨勢分析', canvas:['cvTrend','cvTrendCnt','cvYoY'], tables:[['逐月明細','tbTrend']]},
               people:{title:'部門・人員分析', canvas:['cvDept','cvTop'],
                       tables:[['部門統計','tbDept'], ['人員明細（全部人員）','tbPeople']]}};
  const cfg = map[stSub];
  const yTxt = (String(ST.year) === 'all') ? '全部年度' : (ST.year + ' 年度');
  const cond = [yTxt,
                ($('#stDept option:selected').text() || ''),
                ($('#stUser option:selected').text() || ''),
                ($('#stPending').is(':checked') ? '含審核中' : '僅已核准'),
                '假別：' + (stTypesOn && stTypesOn.length === ST.types.length ? '全部'
                          : ST.types.filter(t => stTypesOn.indexOf(+t.id) >= 0).map(t => t.leave_name).join('、'))
               ].join('　｜　');
  let h = '<html><head><meta charset="utf-8"><title>請假統計 - ' + cfg.title + '</title><style>'
    + 'body{font-family:"Microsoft JhengHei",sans-serif;font-size:12px;color:#3a2c1a;margin:12px;}'
    + 'h2{text-align:center;font-size:17px;margin:4px 0;} h3{font-size:13px;color:#b06f27;margin:14px 0 6px;}'
    + '.cond{text-align:center;color:#8a6d45;font-size:11.5px;margin-bottom:10px;}'
    + '.kpi{display:flex;flex-wrap:wrap;gap:14px;justify-content:center;margin-bottom:10px;}'
    + '.kpi div{border:1px solid #e6d8c3;background:#faf3e7;border-radius:5px;padding:5px 12px;}'
    + '.kpi b{font-size:15px;color:#3a2c1a;}'
    + 'img{max-width:100%;page-break-inside:avoid;} table{width:100%;border-collapse:collapse;}'
    + 'th{background:#faf3e7;color:#b06f27;} th,td{border:1px solid #e6d8c3;padding:3px 6px;}'
    + '.numc{text-align:right;} .sum-row td{background:#f7efe0;font-weight:700;}'
    + 'thead{display:table-header-group;} tr{page-break-inside:avoid;}'
    + '.ft{text-align:center;color:#9a7b4f;font-size:11px;margin-top:10px;}'
    + '.empty-note,.no-print{display:none;} .tag-soft{font-size:10px;color:#8a5a1a;}'
    + '</style></head><body>';
  h += '<h2>' + esc(SETTINGS.print_header || '請假統計表') + '</h2>';
  h += '<h2 style="font-size:14px;">' + esc(cfg.title) + '</h2>';
  h += '<div class="cond">' + esc(cond) + '</div>';
  const k = ST.kpi;
  h += '<div class="kpi">'
     + '<div>總請假天數 <b>'+num(k.total_days)+'</b> 天</div>'
     + '<div>總時數 <b>'+num(k.total_hours)+'</b> 小時</div>'
     + '<div>請假單數 <b>'+k.req_count+'</b> 張</div>'
     + '<div>請假人數 <b>'+k.people_count+'</b> 人</div>'
     + '<div>平均每人 <b>'+num(k.avg_days)+'</b> 天</div></div>';
  cfg.canvas.forEach(function(id){
    const el = document.getElementById(id);
    if(el && el.width) h += '<div><img src="'+el.toDataURL('image/png')+'"></div>';
  });
  cfg.tables.forEach(function(t){
    const $t = $('#'+t[1]);
    if($t.length) h += '<h3>'+esc(t[0])+'</h3><table>' + $t.html() + '</table>';
  });
  h += '<div class="ft">' + esc(SETTINGS.print_footer || '')
     + '　列印時間：' + new Date().toLocaleString('zh-TW') + '</div></body></html>';
  const w = window.open('', '_blank');
  w.document.write(h); w.document.close();
  setTimeout(function(){ w.print(); }, 600);   // 等圖片 decode 完再叫列印
}

// ── 人員明細 CSV（後端全量結果，不是目前這一頁）──
function exportStatsCsv(){
  if(!ST) return;
  const all = ST.by_person || [], ts = usedTypes(all);
  const head = ['姓名','職稱','部門','在職狀態'].concat(ts.map(t => t.leave_name + '(天)'))
               .concat(['合計(天)','時數','單數']);
  const lines = [head.join(',')].concat(all.map(function(p){
    return [p.name, p.position_name, p.dept_name, p.state_label]
           .concat(ts.map(t => num((p.by_type||{})[t.id] || 0)))
           .concat([num(p.days), num(p.hours), p.req_count])
           .map(v => '"' + String(v==null?'':v).replace(/"/g,'""') + '"').join(',');
  }));
  const yTag = (String(ST.year) === 'all') ? '全部年度' : (ST.year + '年');
  const blob = new Blob(['﻿' + lines.join('\r\n')], {type:'text/csv;charset=utf-8;'});
  const a = document.createElement('a');
  a.href = URL.createObjectURL(blob);
  a.download = '請假統計_人員明細_' + yTag + '_' + new Date().toISOString().substring(0,10) + '.csv';
  a.click();
}

// 視窗尺寸變動時讓目前分頁的圖重新配合寬度（Chart.js 只對可見 canvas 有效）
let stResizeTimer = null;
$(window).on('resize', function(){
  if(!ST || $('#tab-stats').is(':hidden')) return;
  clearTimeout(stResizeTimer);
  stResizeTimer = setTimeout(renderStats, 250);
});
<?php endif; ?>

$(function(){ boot(); focusSignTarget(); });
</script>
</body>
</html>
