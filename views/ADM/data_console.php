<?php
// data_console.php — 資料急救台（模組 data_console）
// 用途：非 IT 管理員在前端查後端資料庫狀態、就地修正（例：BOM 未顯示 QC 已檢驗，查驗了沒並補旗標）。
// 權限 data_console：未指派角色者一律擋下（無 fallback-to-all）。表級設定/關聯地圖僅管理員。
session_start();
if (!isset($_SESSION['userName'])) { header("Location:../../index.php"); exit; }

include_once '../../src/common/_config.php';
include ("../../src/common/DBConnection.php");
include_once '../../src/common/role_features_helper.php';
$db  = new DBConnection();
$pdo = $db->getPDO();
$uid = (int)($_SESSION['id'] ?? 0);

// ── 權限 ──────────────────────────────────────────────────────────────────
$features = rf_load_user_features_all($pdo, $uid);
$IS_ADMIN = rf_has_feature($features, 'all');
$CAN_VIEW = $IS_ADMIN || rf_has_feature($features, 'data_console_view');
if (!$CAN_VIEW) { header("Location:../../src/store/Login.php?msg=" . urlencode('無權限檢視此頁面')); exit; }

// ── 首次載入植入預設「可操作使用者」角色（module=data_console）─────────────
try {
    $has = $pdo->query("SELECT role_id FROM roles WHERE module='data_console' AND is_system=0 LIMIT 1")->fetchColumn();
    if (!$has) {
        $rcode = 'role_' . time() . '_' . rand(100, 999);
        $pdo->prepare("INSERT INTO roles (role_code, role_name, module) VALUES (?, '可操作使用者', 'data_console')")->execute([$rcode]);
        $rid = (int)$pdo->lastInsertId();
        $ins = $pdo->prepare("INSERT IGNORE INTO role_features (role_id, feature_code) VALUES (?, ?)");
        foreach (['data_console_view', 'data_console_edit'] as $fc) $ins->execute([$rid, $fc]);
    }
} catch (Throwable $e) {}

// 角色徽章
$myRoleNames = [];
try {
    $st = $pdo->prepare("SELECT DISTINCT r.role_name FROM user_roles ur JOIN roles r ON r.role_id=ur.role_id WHERE ur.user_id=? AND (r.module='data_console' OR r.is_system=1)");
    $st->execute([$uid]);
    $myRoleNames = $st->fetchAll(PDO::FETCH_COLUMN);
} catch (Throwable $e) {}
$roleBadge = $IS_ADMIN ? '管理員' : (empty($myRoleNames) ? '（未指派）' : implode('、', $myRoleNames));

$jsVer = @filemtime(__DIR__ . '/../../resource/js/data_console.js') ?: time();
?>
<!DOCTYPE html>
<html lang="zh-Hant">
<head>
<meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1">
<title>資料急救台 | Excellentgear</title>
<link href="../../resource/css/bootstrap.css" rel="stylesheet">
<link href="../../resource/css/font-awesome.css" rel="stylesheet">
<link href="../../resource/css/nprogress.css" rel="stylesheet">
<link href="../../resource/css/custom.css" rel="stylesheet">
<style>
:root{--amber:#d99a4e;--amber-d:#b06f27;--sand:#faf3e7;--sand2:#f3ead9;--ink:#3a2c1a;--coral:#dd5138;--coral-d:#b53c26;--line:#e6d8c3;}
.dc-wrap{padding:0 16px 40px;}
.pm-tab-btn{border:none;background:transparent;padding:7px 16px;border-radius:6px;font-size:14px;font-weight:600;color:#9a7b4f;cursor:pointer;transition:all .2s;}
.pm-tab-btn.active{background:#fffdf9;color:var(--amber-d);box-shadow:0 2px 5px rgba(0,0,0,.08);}
.dc-card{border:1px solid var(--line);border-radius:8px;background:#fffdf9;padding:14px 16px;margin-bottom:14px;}
.dc-card h4{margin:0 0 10px;color:var(--amber-d);font-size:15px;}
.btn-amber{background:var(--amber);border-color:var(--amber-d);color:#fff;}
.btn-amber:hover{background:var(--amber-d);color:#fff;}
.btn-coral{background:var(--coral);border-color:var(--coral-d);color:#fff;}
.btn-coral:hover{background:var(--coral-d);color:#fff;}
.btn-sand{background:var(--sand);border:1px solid var(--line);color:var(--amber-d);}
.btn-sand:hover{background:#f0e2c8;color:var(--amber-d);}
.dc-tbl{width:100%;border-collapse:collapse;font-size:13px;background:#fff;}
.dc-tbl th{background:var(--sand);color:var(--amber-d);padding:7px 8px;border-bottom:2px solid var(--line);text-align:left;font-weight:600;white-space:nowrap;cursor:pointer;position:sticky;top:0;z-index:3;box-shadow:0 2px 0 var(--line);}
.dc-cmt.saved{border-color:#8bbf7a!important;background:#eef6e6;transition:background .3s;}
.dc-cmt.saving{opacity:.6;}
.dc-tbl td{padding:6px 8px;border-bottom:1px solid #f0e7d7;vertical-align:middle;white-space:nowrap;max-width:280px;overflow:hidden;text-overflow:ellipsis;}
.dc-tbl tr:hover td{background:#fdf6ea;}
.dc-scroll{overflow-x:auto;border:1px solid var(--line);border-radius:6px;}
.role-badge{display:inline-block;background:var(--sand);border:1px solid var(--line);color:var(--amber-d);border-radius:12px;padding:2px 10px;font-size:12px;margin-left:8px;}
.help-i{cursor:pointer;color:var(--amber);margin-left:4px;}
.dc-pill{font-size:10px;padding:1px 7px;border-radius:8px;background:#eee6d6;color:#6b5638;margin-left:4px;}
.dc-pill.edit{background:#8bbf7a;color:#fff;}
.dc-pill.del{background:var(--coral);color:#fff;}
.dc-pill.ro{background:#cbb894;color:#463415;}
.dc-ref{color:#7a5a2a;font-size:11px;background:#f6ecd8;border-radius:6px;padding:1px 5px;margin-left:4px;}
.dc-tablist{max-height:560px;overflow:auto;border:1px solid var(--line);border-radius:6px;background:#fff;}
.dc-tablist .it{padding:6px 10px;border-bottom:1px solid #f4ecdd;cursor:pointer;font-size:13px;display:flex;justify-content:space-between;gap:6px;align-items:center;}
.dc-tablist .it:hover{background:#fdf6ea;}
.dc-tablist .it.active{background:#f6e3c8;border-left:3px solid var(--amber);}
.dc-tablist .it .cnt{color:#a98a5c;font-size:11px;}
.dc-input{border:1px solid var(--line);border-radius:5px;padding:5px 8px;font-size:13px;}
.warn-box{background:#fbe6df;border:1px solid #e6a58f;color:#a3341f;padding:8px 12px;border-radius:6px;font-size:13px;}
.info-box{background:#fdf0dc;border:1px solid #e9c98f;color:#8a5a1a;padding:8px 12px;border-radius:6px;font-size:13px;}
.dc-modal-bg{display:none;position:fixed;inset:0;background:rgba(40,28,12,.45);z-index:1050;align-items:flex-start;justify-content:center;overflow:auto;padding:30px 12px;}
.dc-modal{background:#fffdf9;border-radius:10px;max-width:720px;width:100%;box-shadow:0 12px 40px rgba(0,0,0,.3);}
.dc-modal .hd{padding:12px 18px;border-bottom:1px solid var(--line);display:flex;justify-content:space-between;align-items:center;}
.dc-modal .hd h4{margin:0;color:var(--amber-d);font-size:16px;}
.dc-modal .bd{padding:16px 18px;max-height:70vh;overflow:auto;}
.dc-modal .ft{padding:12px 18px;border-top:1px solid var(--line);text-align:right;}
.dc-field{margin-bottom:10px;}
.dc-field label{display:block;font-size:12px;color:#6b5638;margin-bottom:3px;font-weight:600;}
.dc-field .ro-tag{font-size:10px;color:#b06f27;font-weight:400;margin-left:4px;}
.dc-field input,.dc-field select,.dc-field textarea{width:100%;border:1px solid var(--line);border-radius:5px;padding:6px 8px;font-size:13px;}
.dc-field input:disabled{background:#f4ecdd;color:#8a7350;}
.dc-combo{position:relative;}
.dc-combo .list{position:absolute;left:0;right:0;top:100%;background:#fff;border:1px solid var(--line);border-radius:6px;max-height:220px;overflow:auto;z-index:20;box-shadow:0 6px 18px rgba(0,0,0,.15);display:none;}
.dc-combo .list .o{padding:6px 9px;font-size:13px;cursor:pointer;}
.dc-combo .list .o:hover{background:#fdf6ea;}
.filter-row{display:flex;gap:6px;margin-bottom:6px;align-items:center;flex-wrap:wrap;}
.pager{display:flex;gap:4px;align-items:center;justify-content:flex-end;flex-wrap:wrap;}
.pager button{border:1px solid var(--line);background:#fff;border-radius:5px;padding:3px 9px;font-size:12px;cursor:pointer;color:var(--amber-d);}
.pager button.active{background:var(--amber);color:#fff;border-color:var(--amber-d);}
.pager button:disabled{opacity:.4;cursor:default;}
.hit-card{border:1px solid var(--line);border-radius:8px;margin-bottom:12px;background:#fff;}
.hit-card .hh{padding:8px 12px;background:var(--sand);border-bottom:1px solid var(--line);display:flex;justify-content:space-between;align-items:center;border-radius:8px 8px 0 0;}
.hit-card .hh b{color:var(--amber-d);}
.spin{display:inline-block;width:14px;height:14px;border:2px solid #e6d3b3;border-top-color:var(--amber-d);border-radius:50%;animation:dcspin .7s linear infinite;vertical-align:middle;}
@keyframes dcspin{to{transform:rotate(360deg);}}
</style>
</head>
<body class="nav-sm">
<div class="container body"><div class="main_container">
<?php include '../partPage/sideAndTopBarMenu.html' ?>
<div class="right_col" role="main">
  <div class="page-title"><div class="title_left">
    <h3>資料急救台 <small>直接查後端資料庫狀態並就地修正</small>
      <span class="role-badge">角色：<?= htmlspecialchars($roleBadge) ?></span>
      <i class="fa fa-question-circle help-i" title="使用說明" onclick="$('#dcHelpModal').modal('show')"></i>
    </h3>
  </div></div>
  <div class="clearfix"></div>

  <div class="dc-wrap">
    <div class="info-box" style="margin-bottom:12px;">
      ⚠️ 此頁<strong>直接更動資料庫</strong>，會跳過程式的連動檢查（通知、旗標、子單等不會自動同步）。請確定你已了解影響再修改；每筆改動都會留下稽核紀錄（誰、何時、把哪個欄位從什麼改成什麼、原因）。
    </div>

    <div style="display:flex;gap:4px;background:var(--sand2);border-radius:8px;padding:4px;margin-bottom:12px;flex-wrap:wrap;">
      <button class="pm-tab-btn active" id="tb_search" onclick="dcTab('search',this)">🔎 全域搜尋</button>
      <button class="pm-tab-btn" id="tb_browse" onclick="dcTab('browse',this)">📋 瀏覽 / 查詢 / 修改</button>
      <?php if ($IS_ADMIN): ?><button class="pm-tab-btn" id="tb_setting" onclick="dcTab('setting',this)">⚙️ 設定</button><?php endif; ?>
    </div>

    <!-- ═══ 全域搜尋 ═══ -->
    <div class="dc-tab" id="tab-search">
      <div class="dc-card">
        <h4><i class="fa fa-search"></i> 輸入一個料號／單號／關鍵字，掃出所有相關資料表</h4>
        <div style="display:flex;gap:8px;flex-wrap:wrap;align-items:center;">
          <input id="gsInput" class="dc-input" style="flex:1;min-width:240px;" placeholder="例如：料號、訂單號、姓名關鍵字…" onkeydown="if(event.key==='Enter')DC.globalSearch()">
          <button class="btn btn-amber" onclick="DC.globalSearch()"><i class="fa fa-search"></i> 搜尋全部資料表</button>
        </div>
        <div style="font-size:12px;color:#a98a5c;margin-top:6px;">會逐表掃描文字欄位（數字關鍵字另比對 id 欄），依命中筆數排序。大型資料庫可能需數秒。</div>
      </div>
      <div id="gsResult"></div>
    </div>

    <!-- ═══ 瀏覽 / 查詢 / 修改 ═══ -->
    <div class="dc-tab" id="tab-browse" style="display:none;">
      <div class="row">
        <div class="col-md-3">
          <div class="dc-card" style="padding:10px;">
            <input id="tblFilter" class="dc-input" style="width:100%;margin-bottom:8px;" placeholder="🔍 篩選資料表名…" oninput="DC.filterTableList(this.value)">
            <div class="dc-tablist" id="tableList"></div>
          </div>
        </div>
        <div class="col-md-9">
          <div class="dc-card" id="browsePanel">
            <div style="color:#a98a5c;font-size:14px;text-align:center;padding:40px 0;">← 從左側選一張資料表開始</div>
          </div>
        </div>
      </div>
    </div>

    <!-- ═══ 設定 ═══ -->
    <?php if ($IS_ADMIN): ?>
    <div class="dc-tab" id="tab-setting" style="display:none;">
      <div style="display:flex;gap:4px;margin-bottom:10px;flex-wrap:wrap;">
        <button class="pm-tab-btn active" id="stb_access" onclick="DC.settingTab('access',this)">📂 表級開放設定</button>
        <button class="pm-tab-btn" id="stb_ref" onclick="DC.settingTab('ref',this)">🔗 關聯地圖</button>
        <button class="pm-tab-btn" id="stb_role" onclick="DC.settingTab('role',this)">👤 角色與人員</button>
      </div>
      <div class="dc-card dc-setting-tab" id="set-access">
        <h4>哪些資料表允許編輯 / 刪除（預設全部唯讀，逐表開啟）</h4>
        <div style="margin-bottom:8px;"><input id="cfgFilter" class="dc-input" style="width:260px;" placeholder="🔍 篩選資料表名…" oninput="DC.filterCfg(this.value)"></div>
        <div class="dc-scroll" style="max-height:560px;overflow:auto;"><table class="dc-tbl" id="cfgTbl"></table></div>
      </div>
      <div class="dc-card dc-setting-tab" id="set-ref" style="display:none;">
        <h4>關聯地圖覆寫（自動偵測不準時，手動指定某欄參照哪張表、顯示哪些欄）</h4>
        <div class="info-box" style="margin-bottom:10px;">系統已依命名慣例（如 <code>user_id→user</code>、<code>d_id→d_setting</code>）自動對應大部分欄位，這裡只需補「自動猜錯或猜不到」的。留白＝用自動判斷。</div>
        <div class="filter-row">
          <input id="rmSrcT" class="dc-input" placeholder="來源表(可留白=只看欄名)" style="width:170px;">
          <input id="rmSrcC" class="dc-input" placeholder="來源欄位*" style="width:150px;">
          <span>→</span>
          <input id="rmRefT" class="dc-input" placeholder="參照表*" style="width:150px;">
          <input id="rmRefPk" class="dc-input" placeholder="參照主鍵(留白=自動)" style="width:150px;">
          <input id="rmDisp" class="dc-input" placeholder="顯示欄位,逗號分隔" style="width:180px;">
          <button class="btn btn-amber btn-sm" onclick="DC.refmapSave()">新增/更新</button>
        </div>
        <div class="dc-scroll"><table class="dc-tbl" id="refmapTbl"></table></div>
      </div>
      <div class="dc-card dc-setting-tab" id="set-role" style="display:none;">
        <h4>角色與人員指派</h4>
        <div class="info-box">
          本頁角色（模組 <code>data_console</code>）：<br>
          ・<b>data_console_view</b>：進入、瀏覽、搜尋、查詢<br>
          ・<b>data_console_edit</b>：新增 / 修改（仍受各表「允許編輯」限制）<br>
          ・<b>data_console_delete</b>：刪除（仍受各表「允許刪除」限制，且需二次確認）<br><br>
          系統已自動建立預設角色「可操作使用者」（含檢視＋編輯）。<br>
          <b>指派某人擔任哪個角色</b>，請到 👉 <a href="../user/user_permissions.php" target="_blank"><b>使用者權限設定頁</b></a> 的「資料急救台」區塊設定。管理員固定擁有全部權限。
        </div>
      </div>
    </div>
    <?php endif; ?>
  </div>
</div></div></div>

<!-- 編輯/新增 Modal -->
<div class="dc-modal-bg" id="editModalBg">
  <div class="dc-modal">
    <div class="hd"><h4 id="editModalTitle">修改資料</h4><button class="btn btn-sand btn-sm" onclick="DC.closeEdit()">✕</button></div>
    <div class="bd" id="editModalBody"></div>
    <div class="ft">
      <button class="btn btn-sand" onclick="DC.closeEdit()">取消</button>
      <button class="btn btn-amber" id="editSaveBtn" onclick="DC.saveEdit()">儲存</button>
    </div>
  </div>
</div>

<!-- 刪除影響 Modal -->
<div class="dc-modal-bg" id="delModalBg">
  <div class="dc-modal">
    <div class="hd"><h4 style="color:var(--coral-d)!important;">⚠️ 刪除前影響分析</h4><button class="btn btn-sand btn-sm" onclick="DC.closeDel()">✕</button></div>
    <div class="bd" id="delModalBody"></div>
    <div class="ft">
      <button class="btn btn-sand" onclick="DC.closeDel()">取消</button>
      <button class="btn btn-coral" id="delConfirmBtn" onclick="DC.doDelete()" disabled>確認刪除</button>
    </div>
  </div>
</div>

<!-- 使用說明 -->
<div class="modal fade" id="dcHelpModal" tabindex="-1"><div class="modal-dialog"><div class="modal-content">
  <div class="modal-header"><button type="button" class="close" data-dismiss="modal">&times;</button><h4 class="modal-title">資料急救台使用說明</h4></div>
  <div class="modal-body" style="font-size:14px;line-height:1.7;">
    <p><b>這頁做什麼：</b>當前端資料看起來不對（例：明明驗過了 BOM 卻沒顯示 QC 已檢驗），管理員可在此直接查後端資料庫的實際狀態，確認後就地把它改成正確值。</p>
    <p><b>三個分頁：</b><br>
      🔎 <b>全域搜尋</b>：輸入料號/單號，一次掃出所有相關表的資料，先搞清楚牽動到哪些地方。<br>
      📋 <b>瀏覽/查詢/修改</b>：選一張表，用篩選條件找到那一筆，按「編輯」就地改；也可新增或刪除。<br>
      ⚙️ <b>設定</b>（僅管理員）：決定哪些表可以被編輯/刪除、補關聯對應。</p>
    <p style="color:var(--coral-d);"><b>重要：</b>直接改資料庫會跳過程式的連動（通知、旗標同步等）。改之前用全域搜尋看清影響、改之後確認相關頁面是否也要一起處理。每筆改動都留稽核紀錄。</p>
  </div>
</div></div></div>

<!-- Gentelella 版型必備，順序不可調換：缺 custom.min.js 左側欄選單就不會展開（ai-rules/00-診斷.md 陷阱表） -->
<script src="../../resource/js/jquery.min.js"></script>
<script src="../../resource/js/bootstrap.min.js"></script>
<script src="../../resource/js/fastclick.js"></script>
<script src="../../resource/js/nprogress.js"></script>
<script src="../../resource/js/custom.min.js"></script>
<script>
window.DC_API = '../../src/store/DataConsole_API.php';
</script>
<script src="../../resource/js/data_console.js?v=<?= $jsVer ?>"></script>
<script>
function dcTab(k,btn){document.querySelectorAll('.dc-tab').forEach(e=>e.style.display='none');document.getElementById('tab-'+k).style.display='';document.querySelectorAll('#tb_search,#tb_browse,#tb_setting').forEach(b=>b.classList.remove('active'));btn.classList.add('active');if(k==='setting')DC.loadSettings();}
</script>
</body>
</html>
