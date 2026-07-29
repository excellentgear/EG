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
$VIEW_ALL = $IS_ADMIN || rf_has_feature($features, 'leave_view_all');

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
    </div>

    <!-- ═══════════ 申請請假 ═══════════ -->
    <div class="top-tab" id="tab-apply">
      <div class="lv-card lv-form">
        <h4><i class="fa fa-pencil-square-o"></i> 填寫請假單</h4>

        <div class="annual-box" id="annualBox">
          <div class="it"><div class="lb">本年度特休額度</div><div class="vl"><span id="anEnt">—</span> 天</div></div>
          <div class="it"><div class="lb">已核准使用</div><div class="vl"><span id="anUsed">—</span> 天</div></div>
          <div class="it"><div class="lb">送審中</div><div class="vl"><span id="anPend">—</span> 天</div></div>
          <div class="it"><div class="lb">剩餘可用</div><div class="vl" style="color:var(--amber-d);"><span id="anRem">—</span> 天</div></div>
          <div class="it" style="flex:1;min-width:200px;display:flex;align-items:center;">
            <span style="font-size:11.5px;color:#9a7b4f;">特休額度與員工資料的到職日／年資同一套算法即時計算，不另建額度表。</span>
          </div>
        </div>

        <div class="row">
          <div class="col-md-3 col-sm-6" style="margin-bottom:10px;">
            <label>假別 <span style="color:var(--coral);">*</span></label>
            <select class="form-control input-sm" id="fType"></select>
            <div id="typeHint" style="font-size:11.5px;color:#9a7b4f;margin-top:3px;min-height:16px;"></div>
          </div>
          <div class="col-md-3 col-sm-6" style="margin-bottom:10px;">
            <label>請假日期（起） <span style="color:var(--coral);">*</span></label>
            <input type="date" class="form-control input-sm eg-inp" id="fDateFrom" max="9999-12-31">
          </div>
          <div class="col-md-3 col-sm-6" style="margin-bottom:10px;">
            <label>請假日期（迄）</label>
            <input type="date" class="form-control input-sm eg-inp" id="fDateTo" max="9999-12-31">
            <div style="font-size:11.5px;color:#9a7b4f;margin-top:3px;">留空＝當天請假</div>
          </div>
          <div class="col-md-3 col-sm-6" style="margin-bottom:10px;">
            <label>時數／天數（自動計算）</label>
            <input type="text" class="form-control input-sm" id="fAmount" readonly style="background:#f7f2e8;">
          </div>
        </div>
        <div id="shiftHint" style="display:none;font-size:12.5px;margin-bottom:10px;padding:7px 12px;border-radius:6px;"></div>
        <div class="row">
          <div class="col-md-3 col-sm-6" style="margin-bottom:10px;">
            <label>開始時間 <span style="color:var(--coral);">*</span></label>
            <input type="datetime-local" class="form-control input-sm eg-inp" id="fStart" step="1800">
          </div>
          <div class="col-md-3 col-sm-6" style="margin-bottom:10px;">
            <label>結束時間 <span style="color:var(--coral);">*</span></label>
            <input type="datetime-local" class="form-control input-sm eg-inp" id="fEnd" step="1800">
          </div>
          <div class="col-md-6" style="margin-bottom:10px;">
            <label style="visibility:hidden;">.</label>
            <div style="font-size:12px;color:#9a7b4f;padding-top:6px;">
              選好上方請假日期後，系統會依您當天的<b>固定班別排班</b>自動帶出整天的起訖時間；
              只請半天或幾小時再自行調整即可（<b>以半小時為單位</b>）。
              <button type="button" class="btn btn-xs btn-default" style="margin-left:6px;" onclick="applyShift(true)">重新帶入排班時間</button>
            </div>
          </div>
        </div>

        <div class="row">
          <div class="col-md-4 col-sm-6" style="margin-bottom:10px;" id="agentWrap">
            <label>職務代理人 <span id="agentReq" style="color:var(--coral);display:none;">*</span></label>
            <select class="form-control input-sm" id="fAgent"></select>
            <div style="font-size:11.5px;color:#9a7b4f;margin-top:3px;">
              代理人清單來自「人事設定」的代理人設定，本頁不提供新增；核准後系統會通知他接手職務。
            </div>
          </div>
          <div class="col-md-8" style="margin-bottom:10px;">
            <label>請假原因</label>
            <input type="text" class="form-control input-sm eg-inp" id="fReason" maxlength="200" placeholder="簡述原因（選填）">
          </div>
        </div>

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
          <select class="form-control input-sm" id="fScope" style="width:auto;" onchange="listPage=1;loadList()">
            <option value="mine">我的請假單</option>
            <?php if ($VIEW_ALL): ?><option value="all">全公司</option><?php endif; ?>
            <option value="dept">我的部門（含下轄）</option>
          </select>
          <select class="form-control input-sm eg-inp" id="fStatus" style="width:auto;" onchange="listPage=1;loadList()">
            <option value="">全部狀態</option>
            <option value="pending">審核中</option>
            <option value="approved">已核准</option>
            <option value="rejected">已退回</option>
            <option value="canceled">已取消</option>
          </select>
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
    <button class="btn btn-default" data-dismiss="modal">關閉</button>
    <button class="btn btn-coral" id="btnCancelLeave" style="display:none;" onclick="doCancel()"></button>
  </div>
</div></div></div>

<!-- ═══ 角色說明 Modal ═══ -->
<div class="modal fade" id="roleHelpModal" tabindex="-1"><div class="modal-dialog"><div class="modal-content">
  <div class="modal-header" style="background:var(--sand);">
    <button type="button" class="close" data-dismiss="modal">&times;</button>
    <h4 class="modal-title" style="color:var(--amber-d);">請假系統 權限說明</h4>
  </div>
  <div class="modal-body" style="font-size:13px;">
    <p><b>一般使用者（所有登入者）</b>：申請請假、查看與撤回／銷假自己的單、上傳與補件自己的證明文件。</p>
    <p><b>主管（職稱有設定階級者）</b>：除上述外，可檢視自己部門（含下轄部門）的請假單。</p>
    <p><b>人事（leave_view_all）</b>：可檢視全公司請假單；此角色<u>不含</u>代為簽核的權力。</p>
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
<script src="../../resource/js/eg_stamp.js?v=<?= $avStamp ?>"></script>
<script>
const API = '../../src/store/Leave_API.php';
const IS_ADMIN = <?= $IS_ADMIN ? 'true' : 'false' ?>;
let CSRF = '', TYPES = [], AGENTS = [], SETTINGS = {}, ME = {};
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
  const $f = $(root||document).find('input.eg-inp, select.eg-inp, input.form-control, select.form-control');
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
    // 代理人下拉
    const $a = $('#fAgent').empty().append('<option value="">'+(AGENTS.length? '請選擇代理人' : '（尚未設定代理人，請洽人事）')+'</option>');
    AGENTS.forEach(a => $a.append('<option value="'+a.user_id+'">'+esc(a.user_cname)+'</option>'));
    renderAnnual(r.annual);
    if(IS_ADMIN){ $('#psHeader').val(SETTINGS.print_header||''); $('#psFooter').val(SETTINGS.print_footer||''); }
    // 附件根目錄未設定的警告改在「選到需附證明的假別」時才顯示（見假別切換），此處不預先顯示
    uploadToken = newToken();
    bindInputUx();
    refreshPendingCount();
  });
}
function renderAnnual(an){
  if(!an) return;
  $('#anEnt').text(num(an.entitlement)); $('#anUsed').text(num(an.used));
  $('#anPend').text(num(an.pending));    $('#anRem').text(num(an.remaining));
}

// ── 假別切換：代理人/證明文件需求提示 ──
$(document).on('change', '#fType', function(){
  const t = TYPES.find(x => String(x.id) === String(this.value));
  if(!t){ $('#typeHint').text(''); $('#agentReq').hide(); $('#attReq').hide(); $('#attachBlock').hide(); $('#signerPrev').hide(); return; }
  const unit = {hour:'可請時假（以半小時為單位）', halfday:'以半天為單位（不足半天以半天計）', day:'以整天為單位'}[t.unit_type] || '';
  let hint = unit;
  if(+t.need_approval === 0) hint += '｜此假別免主管簽核';
  else hint += '｜需簽核至第 ' + t.max_approval_level + ' 層主管';
  $('#typeHint').text(hint);
  $('#agentReq').toggle(+t.agent === 1);
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
$(document).on('change', '#fStart, #fEnd', function(){ shiftApplied = true; doPreview(); });

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
    // datetime-local 需要 "YYYY-MM-DDTHH:MM"
    const toLocal = s => String(s||'').substring(0,16).replace(' ', 'T');
    $('#fStart').val(toLocal(r.start_datetime));
    $('#fEnd').val(toLocal(r.end_datetime));
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

// ── 試算＋簽核人預覽 ──
let previewTimer = null;
function doPreview(){
  clearTimeout(previewTimer);
  previewTimer = setTimeout(function(){
    const tid = $('#fType').val(), s = $('#fStart').val(), e = $('#fEnd').val();
    if(!tid){ return; }
    $.getJSON(API, {action:'preview', leave_type_id:tid, start:s?s.replace('T',' ')+':00':'', end:e?e.replace('T',' ')+':00':''}, function(r){
      if(!r.success) return;
      if(r.amount){
        $('#fAmount').val(r.amount.hours>0 ? (num(r.amount.hours)+' 小時（'+num(r.amount.days)+' 天，'+r.amount.workdays+' 個工作日）') : '時段內無工作日');
      } else $('#fAmount').val('');
      if(r.annual) renderAnnual(r.annual);
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
  const tid = $('#fType').val(), s = $('#fStart').val(), e = $('#fEnd').val();
  if(!tid){ $('#applyMsg').html('<span style="color:#a3341f;">請選擇假別</span>'); return; }
  if(!$('#fDateFrom').val()){ $('#applyMsg').html('<span style="color:#a3341f;">請選擇請假日期</span>'); $('#fDateFrom').focus(); return; }
  if(!s || !e){ $('#applyMsg').html('<span style="color:#a3341f;">請填寫開始與結束時間</span>'); return; }
  $('#btnSubmit').prop('disabled', true);
  $('#applyMsg').html('送出中…');
  $.post(API, {
    action:'submit', csrf:CSRF, leave_type_id:tid,
    start_datetime: s.replace('T',' ')+':00', end_datetime: e.replace('T',' ')+':00',
    reason: $('#fReason').val(), agent_user_id: $('#fAgent').val()||0, upload_token: uploadToken
  }, function(r){
    $('#btnSubmit').prop('disabled', false);
    if(!r.success){ $('#applyMsg').html('<span style="color:#a3341f;"><i class="fa fa-times-circle"></i> '+esc(r.message)+'</span>'); return; }
    let msg = '<span style="color:#4d6b2e;"><i class="fa fa-check-circle"></i> '+esc(r.message)+'（單號 #'+r.id+'）行事曆已標示為「申請中」。</span>';
    if(r.need_attach_later) msg += '<br><span class="tag-warn">待補證明</span> <span style="color:#8a5a1a;">請盡快到「我的請假單」開啟本單補上傳證明文件。</span>';
    $('#applyMsg').html(msg);
    resetForm(true);
    refreshPendingCount();
  }, 'json').fail(function(){ $('#btnSubmit').prop('disabled', false); $('#applyMsg').html('<span style="color:#a3341f;">送出失敗</span>'); });
}
function resetForm(keepMsg){
  $('#fType').val(''); $('#fStart').val(''); $('#fEnd').val(''); $('#fAmount').val('');
  $('#fDateFrom').val(''); $('#fDateTo').val(''); $('#shiftHint').hide(); shiftApplied = false;
  $('#fReason').val(''); $('#fAgent').val(''); $('#tempList').empty();
  $('#signerPrev').hide(); $('#typeHint').text(''); $('#agentReq').hide();
  $('#attReq').hide(); $('#attachBlock').hide(); $('#attMsg').text('');
  const ff0 = document.getElementById('fFile'); if(ff0) ff0.value = '';
  if(!keepMsg) $('#applyMsg').empty();
  uploadToken = newToken();
  $.getJSON(API, {action:'annual_summary'}, function(r){ if(r.success) renderAnnual(r.annual); });
}

// ── 列表 ──
function loadList(){
  const q = {action:'list', scope:$('#fScope').val(), status:$('#fStatus').val(), page:listPage, per:$('#fPer').val()};
  $.getJSON(API, q, function(r){
    if(!r.success){ $('#listBody').html('<tr><td colspan="10" class="empty-note">'+esc(r.message)+'</td></tr>'); $('#pagerBtns').empty(); return; }
    listTotal = r.total;
    $('#listBody').html((r.rows||[]).length ? r.rows.map(rowHtml).join('')
                        : '<tr><td colspan="10" class="empty-note">沒有符合條件的請假單</td></tr>');
    renderPager(r.total, r.page, r.per);
    $('#listInfo').text('共 ' + r.total + ' 筆，第 ' + r.page + ' 頁');
  });
}
function stBadge(s){
  const m = {pending:['st-pending','審核中'], approved:['st-approved','已核准'],
             rejected:['st-rejected','已退回'], canceled:['st-canceled','已取消']};
  const x = m[s] || ['st-canceled', s];
  return '<span class="st-badge '+x[0]+'">'+x[1]+'</span>';
}
function rowHtml(o){
  let tags = '';
  if(+o.is_backdated === 1) tags += '<span class="tag-soft">補請假</span> ';
  if(o.attach_status === 'pending') tags += '<span class="tag-warn">待補證明</span> ';
  if(o.status === 'pending') tags += (window.EGStamp ? EGStamp.badge('pending', 15) : '');
  if(o.status === 'approved') tags += (window.EGStamp ? EGStamp.badge('sign', 15) : '');
  return '<tr>'
    + '<td>#'+o.id+'</td>'
    + '<td>'+esc(o.applicant_name)+'</td>'
    + '<td>'+esc(o.leave_name)+'</td>'
    + '<td style="white-space:nowrap;">'+esc(String(o.start_datetime).substring(0,16))+'<br>~ '+esc(String(o.end_datetime).substring(0,16))+'</td>'
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
  $.getJSON(API, {action:'list', scope:$('#fScope').val(), status:$('#fStatus').val(), page:1, per:50}, function(first){
    if(!first.success){ alert(first.message); return; }
    const total = first.total, per = 50, pages = Math.max(1, Math.ceil(total/per));
    const all = [], reqs = [];
    for(let p=1;p<=pages;p++) reqs.push($.getJSON(API, {action:'list', scope:$('#fScope').val(), status:$('#fStatus').val(), page:p, per:per}));
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
      a.download = '請假單_' + new Date().toISOString().substring(0,10) + '.csv';
      a.click();
    });
  });
}
function exportPdf(){
  // 列印一律交給瀏覽器引擎原生分頁（單一表格，不用 JS 量高度自算分頁）
  $.getJSON(API, {action:'list', scope:$('#fScope').val(), status:$('#fStatus').val(), page:1, per:50}, function(first){
    if(!first.success){ alert(first.message); return; }
    const pages = Math.max(1, Math.ceil(first.total/50)), reqs = [];
    for(let p=1;p<=pages;p++) reqs.push($.getJSON(API, {action:'list', scope:$('#fScope').val(), status:$('#fStatus').val(), page:p, per:50}));
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
      h += '<h2>' + esc(SETTINGS.print_header || '請假單列表') + '</h2>';
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
  $('#detailBody').html('<div class="empty-note">載入中…</div>');
  $('#detailModal').modal('show');
  $.getJSON(API, {action:'detail', id:id}, function(r){
    if(!r.success){ $('#detailBody').html('<div class="empty-note">'+esc(r.message)+'</div>'); $('#btnCancelLeave').hide(); return; }
    const o = r.request;
    curDetailCanCancel = r.can_cancel; curDetailStatus = o.status;
    let h = '<table class="lv-tbl" style="margin-bottom:14px;"><tbody>';
    const row = (k,v) => '<tr><th style="width:110px;">'+k+'</th><td>'+v+'</td></tr>';
    h += row('單號', '#'+o.id + '　' + stBadge(o.status)
             + (o.status==='pending' && window.EGStamp ? ' '+EGStamp.badge('pending',16) : '')
             + (o.status==='approved' && window.EGStamp ? ' '+EGStamp.badge('sign',16) : ''));
    h += row('申請人', esc(o.applicant_name));
    h += row('假別', esc(o.leave_name));
    h += row('請假時段', esc(String(o.start_datetime).substring(0,16)) + ' ~ ' + esc(String(o.end_datetime).substring(0,16))
             + (+o.is_backdated===1 ? ' <span class="tag-soft">補請假</span>' : ''));
    h += row('時數／天數', num(o.total_hours)+' 小時 / '+num(o.total_days)+' 天');
    h += row('職務代理人', esc(o.agent_name || '—'));
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

    if((r.sign_records||[]).length){
      h += '<h4 style="color:var(--amber-d);font-size:14px;margin-top:16px;">簽章軌跡</h4>';
      r.sign_records.forEach(function(s){
        const actTxt = {approved:'核准', rejected:'退回', canceled:'撤回／銷假'}[s.action] || s.action;
        h += '<div class="flow-row">'
           + (s.action==='approved' && window.EGStamp ? EGStamp.badge('sign',18) : '<span class="lvl-badge">'+(s.step_no==99?'—':('第 '+s.step_no+' 層'))+'</span>')
           + '<span>'+esc(s.signer_name)+'</span><span>'+actTxt+'</span>'
           + '<span style="color:#9a7b4f;flex:1;">'+esc(s.remark||'')+'</span>'
           + '<span style="color:#9a7b4f;font-size:12px;">'+esc(String(s.signed_at||'').substring(0,16))+'</span></div>';
      });
    }
    $('#detailBody').html(h);

    if(r.can_cancel){
      $('#btnCancelLeave').show().html('<i class="fa fa-undo"></i> ' + (o.status==='approved' ? '銷假' : '撤回申請'));
    } else $('#btnCancelLeave').hide();
  });
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
function doCancel(){
  const isApproved = curDetailStatus === 'approved';
  const msg = isApproved ? '確定要銷假？行事曆上的休假會撤除，並通知所有已簽核的主管與代理人。' : '確定撤回此申請？';
  if(!confirm(msg)) return;
  const reason = prompt(isApproved ? '請輸入銷假原因：' : '請輸入撤回原因（可留空）：', '') ;
  if(reason === null) return;
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
      return '<div class="lv-card" style="background:#fff;">'
        + '<div style="display:flex;gap:10px;flex-wrap:wrap;align-items:center;margin-bottom:6px;">'
        + '<b style="font-size:14px;">#'+o.leave_request_id+' '+esc(o.applicant_name)+'</b>'
        + '<span class="tag-soft">'+esc(o.leave_name)+'</span>'
        + '<span class="lvl-badge" style="background:var(--sand);border:1px solid var(--sand-d);color:var(--amber-d);border-radius:10px;padding:1px 9px;font-size:11px;">第 '+o.approval_level+' 層</span>'
        + (o.as_delegate ? '<span class="tag-warn">您以代理人身分簽核</span>' : '')
        + (+o.is_backdated===1 ? '<span class="tag-soft">補請假</span>' : '')
        + (o.attach_status==='pending' ? '<span class="tag-warn">待補證明</span>' : '')
        + (window.EGStamp ? EGStamp.badge('pending',15) : '')
        + '</div>'
        + '<div style="font-size:13px;color:#6b5638;">時段：'+esc(String(o.start_datetime).substring(0,16))+' ~ '+esc(String(o.end_datetime).substring(0,16))
        + '　時數：'+num(o.total_hours)+' 小時（'+num(o.total_days)+' 天）</div>'
        + (o.reason ? '<div style="font-size:13px;color:#6b5638;">原因：'+esc(o.reason)+'</div>' : '')
        + (o.as_delegate && o.delegate_reason ? '<div style="font-size:11.5px;color:#9a7b4f;">'+esc(o.delegate_reason)+'</div>' : '')
        + '<div style="margin-top:8px;display:flex;gap:8px;align-items:center;flex-wrap:wrap;">'
        + '<input type="text" class="form-control input-sm eg-inp" id="rm'+o.leave_request_id+'" placeholder="簽核意見（退回必填）" style="max-width:320px;">'
        + '<button class="btn btn-sm btn-amber" onclick="doSign('+o.leave_request_id+',\'approve\')"><i class="fa fa-check"></i> 核准</button>'
        + '<button class="btn btn-sm btn-coral" onclick="doSign('+o.leave_request_id+',\'reject\')"><i class="fa fa-times"></i> 退回</button>'
        + '<button class="btn btn-sm btn-default" onclick="openDetail('+o.leave_request_id+')">檢視詳情</button>'
        + '</div></div>';
    }).join(''));
    bindInputUx('#pendBody');
  });
}
function doSign(id, decision){
  const remark = $('#rm'+id).val() || '';
  if(decision === 'reject' && !remark.trim()){ alert('退回必須填寫意見'); $('#rm'+id).focus(); return; }
  if(!confirm(decision === 'approve' ? '確定核准此請假單？' : '確定退回此請假單？')) return;
  $.post(API, {action:'sign', csrf:CSRF, id:id, decision:decision, remark:remark}, function(r){
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

$(function(){ boot(); });
</script>
</body>
</html>
