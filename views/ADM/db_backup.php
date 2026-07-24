<?php
// db_backup.php — 資料庫備份管理（Phase 1）
// 自動/手動備份列表、下載、整庫還原、整表還原、設定、還原密碼、頁內角色設定。
// 權限模組 db_backup：未指派角色者一律擋下（無 fallback-to-all）。整庫還原/設定/密碼僅管理員。
session_start();
if (!isset($_SESSION['userName'])) { header("Location:../../index.php"); exit; }

include_once '../../src/common/_config.php';           // 觸發順路備份等 tick
include ("../../src/common/DBConnection.php");
include_once '../../src/common/role_features_helper.php';
$db   = new DBConnection();
$pdo  = $db->getPDO();
$uid  = (int)($_SESSION['id'] ?? 0);

// ── 權限 ──────────────────────────────────────────────────────────────────
$features          = rf_load_user_features_all($pdo, $uid);
$IS_ADMIN          = rf_has_feature($features, 'all');
$CAN_VIEW          = $IS_ADMIN || rf_has_feature($features, 'db_backup_view');
$CAN_RUN           = $IS_ADMIN || rf_has_feature($features, 'db_backup_run');
$CAN_RESTORE_TABLE = $IS_ADMIN || rf_has_feature($features, 'db_restore_table');
if (!$CAN_VIEW) { header("Location:../../src/store/Login.php?msg=" . urlencode('無權限檢視此頁面')); exit; }

// ── 首次載入時植入預設「可操作使用者」角色（module=db_backup）──────────────
try {
    $has = $pdo->query("SELECT role_id FROM roles WHERE module='db_backup' AND is_system=0 LIMIT 1")->fetchColumn();
    if (!$has) {
        $rcode = 'role_' . time() . '_' . rand(100, 999);
        $pdo->prepare("INSERT INTO roles (role_code, role_name, module) VALUES (?, '可操作使用者', 'db_backup')")->execute([$rcode]);
        $rid = (int)$pdo->lastInsertId();
        $ins = $pdo->prepare("INSERT IGNORE INTO role_features (role_id, feature_code) VALUES (?, ?)");
        foreach (['db_backup_view', 'db_backup_run', 'db_restore_table'] as $fc) $ins->execute([$rid, $fc]);
    }
} catch (Throwable $e) {}

// ── 本頁功能清單（供頁內角色設定用）────────────────────────────────────────
$PAGE_FEATURES = [
    ['code' => 'db_backup_view',     'label' => '檢視備份列表／下載備份'],
    ['code' => 'db_backup_run',      'label' => '立即備份'],
    ['code' => 'db_restore_table',   'label' => '整表還原（仍需輸入整表還原密碼）'],
    ['code' => 'db_restore_partial', 'label' => '部分還原（Phase 2；仍需輸入部分還原密碼）'],
];

// 角色徽章
$myRoleNames = [];
try {
    $st = $pdo->prepare("SELECT DISTINCT r.role_name FROM user_roles ur JOIN roles r ON r.role_id=ur.role_id WHERE ur.user_id=? AND (r.module='db_backup' OR r.is_system=1)");
    $st->execute([$uid]);
    $myRoleNames = $st->fetchAll(PDO::FETCH_COLUMN);
} catch (Throwable $e) {}
$roleBadge = $IS_ADMIN ? '管理員' : (empty($myRoleNames) ? '（未指派）' : implode('、', $myRoleNames));
?>
<!DOCTYPE html>
<html lang="zh-Hant">
<head>
<meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1">
<title>資料庫備份管理 | Excellentgear</title>
<link href="../../resource/css/bootstrap.css" rel="stylesheet">
<link href="../../resource/css/font-awesome.css" rel="stylesheet">
<link href="../../resource/css/nprogress.css" rel="stylesheet">
<link href="../../resource/css/custom.css" rel="stylesheet">
<style>
:root{--amber:#d99a4e;--amber-d:#b06f27;--sand:#faf3e7;--ink:#3a2c1a;--coral:#dd5138;}
.bk-wrap{padding:0 16px 40px;}
.pm-tab-btn{border:none;background:transparent;padding:7px 16px;border-radius:6px;font-size:14px;font-weight:600;color:#9a7b4f;cursor:pointer;transition:all .2s;}
.pm-tab-btn.active{background:#fffdf9;color:var(--amber-d);box-shadow:0 2px 5px rgba(0,0,0,.08);}
.bk-card{border:1px solid #e6d8c3;border-radius:8px;background:#fffdf9;padding:14px 16px;margin-bottom:14px;}
.bk-card h4{margin:0 0 10px;color:var(--amber-d);font-size:15px;}
.bk-stat{display:flex;flex-wrap:wrap;gap:20px;}
.bk-stat .it{min-width:120px;}
.bk-stat .lb{font-size:11px;color:#9a7b4f;}
.bk-stat .vl{font-size:18px;font-weight:700;color:var(--ink);}
.btn-amber{background:var(--amber);border-color:var(--amber-d);color:#fff;}
.btn-amber:hover{background:var(--amber-d);color:#fff;}
.btn-coral{background:var(--coral);border-color:#b53c26;color:#fff;}
.btn-coral:hover{background:#b53c26;color:#fff;}
.bk-tbl{width:100%;border-collapse:collapse;font-size:13px;background:#fff;}
.bk-tbl th{background:var(--sand);color:var(--amber-d);padding:7px 8px;border-bottom:2px solid #e6d8c3;text-align:left;font-weight:600;white-space:nowrap;}
.bk-tbl td{padding:6px 8px;border-bottom:1px solid #f0e7d7;vertical-align:middle;}
.bk-tbl tr:hover td{background:#fdf6ea;}
.st-badge{font-size:11px;padding:2px 7px;border-radius:9px;font-weight:600;}
.st-success{background:#e7f0dd;color:#4d6b2e;} .st-fail{background:#f7d9d1;color:#a3341f;}
.st-running{background:#f7e0bd;color:#8a5a1a;} .st-pre{background:#efe3f2;color:#6b4a78;}
.pill{font-size:10px;padding:1px 6px;border-radius:8px;background:#eee6d6;color:#6b5638;}
.pill.on{background:#8bbf7a;color:#fff;}
.err-note{background:#fdf0dc;border:1px solid #e9c98f;color:#8a5a1a;padding:6px 10px;border-radius:5px;font-size:12px;margin-top:8px;}
.warn-box{background:#fbe6df;border:1px solid #e6a58f;color:#a3341f;padding:8px 12px;border-radius:6px;font-size:13px;}
.role-badge{display:inline-block;background:var(--sand);border:1px solid #e6d8c3;color:var(--amber-d);border-radius:12px;padding:2px 10px;font-size:12px;margin-left:8px;}
.help-i{cursor:pointer;color:var(--amber);margin-left:4px;}
.form-inline-row{display:flex;gap:10px;align-items:center;margin-bottom:10px;flex-wrap:wrap;}
.form-inline-row label{min-width:120px;margin:0;font-size:13px;color:#6b5638;}
#rolesList .r-item{padding:6px 10px;border-bottom:1px solid #f0e7d7;cursor:pointer;font-size:13px;}
#rolesList .r-item.active{background:#f6e3c8;border-left:3px solid var(--amber);}
#rolesList .r-item.sys{color:#b06f27;font-weight:600;}
</style>
</head>
<body class="nav-sm">
<div class="container body"><div class="main_container">
<?php include '../partPage/sideAndTopBarMenu.html' ?>
<div class="right_col" role="main">
  <div class="page-title"><div class="title_left">
    <h3>資料庫備份管理 <small>自動備份・雲端(私有Git)・整庫/整表還原</small>
      <span class="role-badge">角色：<?= htmlspecialchars($roleBadge) ?></span>
      <i class="fa fa-question-circle help-i" title="各角色權限說明" onclick="$('#roleHelpModal').modal('show')"></i>
    </h3>
  </div></div>
  <div class="clearfix"></div>

  <div class="bk-wrap">
    <!-- ═══ 第一層分頁 ═══ -->
    <div style="display:flex;gap:4px;background:#f3ead9;border-radius:8px;padding:4px;margin-bottom:12px;flex-wrap:wrap;">
      <button class="pm-tab-btn active" id="ttbBackup" onclick="topTab('backup',this)">💾 備份管理</button>
      <button class="pm-tab-btn" id="ttbRescue" style="display:none;" onclick="topTab('rescue',this)">🛟 誤刪救援</button>
      <?php if ($IS_ADMIN): ?><button class="pm-tab-btn" id="ttbMigrate" onclick="topTab('migrate',this);if(!pmLoaded)pmLoad();">🔁 路徑遷移工具</button><?php endif; ?>
    </div>

    <!-- ═══ Tab: 備份管理 ═══ -->
    <div class="top-tab" id="top-tab-backup">
    <!-- 狀態卡 -->
    <div class="bk-card">
      <h4><i class="fa fa-dashboard"></i> 目前狀態</h4>
      <div class="bk-stat">
        <div class="it"><div class="lb">自動備份間隔</div><div class="vl"><span id="stInterval">—</span> 天</div></div>
        <div class="it"><div class="lb">工作區保留</div><div class="vl"><span id="stKeep">—</span> 份</div></div>
        <div class="it"><div class="lb">最近一次備份</div><div class="vl" style="font-size:14px;"><span id="stLast">—</span></div></div>
        <div class="it"><div class="lb">雲端自動 push</div><div class="vl" style="font-size:14px;"><span id="stPush">—</span></div></div>
        <div class="it"><div class="lb">NAS 複製路徑</div><div class="vl" style="font-size:12px;word-break:break-all;max-width:260px;"><span id="stNas">—</span></div></div>
      </div>
      <div id="errNote" class="err-note" style="display:none;"></div>
    </div>

    <!-- 工具列 -->
    <div class="bk-card">
      <div style="display:flex;gap:8px;flex-wrap:wrap;align-items:center;">
        <?php if ($CAN_RUN): ?><button class="btn btn-sm btn-amber" id="btnRun"><i class="fa fa-database"></i> 立即備份</button><?php endif; ?>
        <button class="btn btn-sm btn-default" id="btnReload"><i class="fa fa-refresh"></i> 重新整理</button>
        <span style="flex:1;"></span>
        <?php if ($IS_ADMIN): ?>
        <button class="btn btn-sm btn-default" onclick="openSettings()"><i class="fa fa-cog"></i> 設定</button>
        <button class="btn btn-sm btn-default" onclick="openPerm()"><i class="fa fa-key"></i> 角色權限</button>
        <button class="btn btn-sm btn-default" onclick="purgeHistory()" title="把Git備份庫壓縮成只剩目前檔案的單一版本,雲端舊歷史一併覆蓋(防資料外流)"><i class="fa fa-eraser"></i> 徹底清除Git歷史</button>
        <?php endif; ?>
      </div>
      <div style="font-size:12px;color:#9a7b4f;margin-top:8px;">
        <i class="fa fa-info-circle"></i> 備份由「有人開啟頁面」順路觸發：距上次備份達設定間隔才會自動跑；半夜無人使用不備份。備份檔存放在網站目錄之外的私有 Git 備份庫，僅能透過本頁下載。
      </div>
    </div>

    <!-- 備份列表 -->
    <div class="bk-card">
      <h4><i class="fa fa-history"></i> 備份紀錄</h4>
      <div style="overflow-x:auto;">
        <table class="bk-tbl">
          <thead><tr>
            <th>時間</th><th>檔名</th><th>大小</th><th>觸發</th><th>狀態</th><th>雲端</th><th>備註</th><th>操作</th>
          </tr></thead>
          <tbody id="bkBody"><tr><td colspan="8" style="padding:20px;color:#999;">載入中…</td></tr></tbody>
        </table>
      </div>
    </div>
    </div><!-- /top-tab-backup -->

    <!-- ═══ Tab: 路徑遷移工具（換NAS/換機;僅管理員）═══ -->
    <?php if ($IS_ADMIN): ?>
    <div class="top-tab" id="top-tab-migrate" style="display:none;">
    <div class="bk-card" id="pmCard">
      <h4><i class="fa fa-exchange"></i> 路徑遷移工具（換 NAS / 換主機 / 測試機用）
        <small style="color:#9a7b4f;font-weight:400;">盤點全系統 NAS 路徑,一鍵把舊位置對應到新位置,並可搬移檔案</small></h4>
      <div id="pmBody">
        <!-- 頁內分頁（仿 stock.php tab-sw）-->
        <div style="display:flex;gap:4px;background:#f3ead9;border-radius:8px;padding:4px;margin-bottom:10px;flex-wrap:wrap;">
          <button class="pm-tab-btn active" data-t="overview" onclick="pmTab('overview',this)">📋 路徑總覽</button>
          <button class="pm-tab-btn" data-t="bulk" onclick="pmTab('bulk',this)">🔁 批次替換</button>
          <button class="pm-tab-btn" data-t="copy" onclick="pmTab('copy',this)">📂 檔案搬運</button>
          <button class="pm-tab-btn" data-t="log" onclick="pmTab('log',this);pmLoadLog()">🕘 變更紀錄</button>
          <button class="pm-tab-btn" data-t="migbk" onclick="pmTab('migbk',this);migbkLoad()">🚚 移機快速備份</button>
        </div>

        <!-- Tab1 路徑總覽 -->
        <div class="pm-tab" id="pm-tab-overview">
          <div style="font-size:12px;color:#9a7b4f;margin-bottom:6px;">
            全系統跟 NAS/磁碟位置有關的設定都在這裡。「存放位置」= 這個路徑值本身存在哪(改設定就是改那裡);「使用頁面」= 哪些網頁會用到它。
          </div>
          <div style="overflow-x:auto;max-height:380px;overflow-y:auto;border:1px solid #e6d8c3;border-radius:6px;">
            <table class="bk-tbl"><thead><tr><th>用途</th><th>使用頁面</th><th>目前路徑值</th><th>路徑值存放位置</th><th>狀態</th><th>修改</th></tr></thead>
            <tbody id="pmSettingsBody"><tr><td colspan="6" style="color:#999;padding:10px;">載入中…</td></tr></tbody></table>
          </div>
          <div style="margin-top:10px;">
            <b style="color:var(--amber-d);font-size:13px;">資料庫存了完整路徑的表（批次替換會一併處理）</b>
            <div style="overflow-x:auto;max-height:130px;overflow-y:auto;border:1px solid #e6d8c3;border-radius:6px;margin-top:4px;">
              <table class="bk-tbl"><thead><tr><th>模組</th><th>欄位</th><th>路徑前綴</th><th>筆數</th></tr></thead>
              <tbody id="pmTablesBody"></tbody></table>
            </div>
          </div>
          <div style="font-size:12px;color:#8a5a1a;background:#fdf0dc;border:1px solid #e9c98f;border-radius:5px;padding:6px 10px;margin-top:8px;" id="pmHardcoded"></div>
        </div>

        <!-- Tab2 批次替換 -->
        <div class="pm-tab" id="pm-tab-bulk" style="display:none;">
          <div style="font-size:12px;color:#9a7b4f;margin-bottom:6px;">
            把所有「以舊前綴開頭」的路徑一口氣換掉(不分大小寫)。流程:①預覽 → ②勾選要套用的項目(預設全勾) → ③執行勾選項。每次執行都會寫入「變更紀錄」。
          </div>
          <div style="display:flex;gap:8px;align-items:center;flex-wrap:wrap;">
            <input type="text" id="pmOld" class="form-control" style="width:280px;" placeholder="舊前綴 例:\\excellentnas 或 Z:">
            <i class="fa fa-long-arrow-right" style="color:var(--amber);"></i>
            <input type="text" id="pmNew" class="form-control" style="width:280px;" placeholder="新前綴 例:\\newnas 或 D:\nasdata">
            <button class="btn btn-sm btn-amber" onclick="pmBulkPreview()"><i class="fa fa-eye"></i> ① 預覽影響範圍</button>
          </div>
          <div id="pmBulkResult" style="margin-top:8px;font-size:12px;"></div>
          <button class="btn btn-sm btn-coral" id="pmBulkExec" style="display:none;margin-top:6px;" onclick="pmBulkExecute()"><i class="fa fa-bolt"></i> ③ 執行勾選的替換</button>
        </div>

        <!-- Tab3 檔案搬運 -->
        <div class="pm-tab" id="pm-tab-copy" style="display:none;">
          <div style="font-size:12px;color:#9a7b4f;margin-bottom:6px;">
            把舊位置的附件實體檔複製到新位置（背景 robocopy,含子資料夾;只新增不刪除目的地既有檔）。改完設定值別忘了把檔案也搬過去,兩者都做完舊資料才讀得到。
          </div>
          <div style="display:flex;gap:8px;align-items:center;flex-wrap:wrap;">
            <input type="text" id="pmSrc" class="form-control" style="width:300px;" placeholder="來源 例:E:\USB帶來的NAS備份\BOM">
            <i class="fa fa-long-arrow-right" style="color:var(--amber);"></i>
            <input type="text" id="pmDst" class="form-control" style="width:300px;" placeholder="目的 例:D:\nasdata\BOM">
            <button class="btn btn-sm btn-amber" onclick="pmCopyStart()"><i class="fa fa-copy"></i> 開始複製</button>
          </div>
          <div id="pmCopyStatus" style="margin-top:8px;font-size:12px;white-space:pre-wrap;font-family:monospace;"></div>
        </div>

        <!-- Tab4 變更紀錄 -->
        <div class="pm-tab" id="pm-tab-log" style="display:none;">
          <div style="font-size:12px;color:#9a7b4f;margin-bottom:6px;">本頁所有路徑修改的歷史(誰、何時、把哪個路徑從什麼改成什麼)。最近 100 筆。</div>
          <div style="overflow-x:auto;max-height:400px;overflow-y:auto;border:1px solid #e6d8c3;border-radius:6px;">
            <table class="bk-tbl"><thead><tr><th>時間</th><th>對象</th><th>變更前</th><th>變更後</th><th>筆數</th><th>操作者</th></tr></thead>
            <tbody id="pmLogBody"><tr><td colspan="6" style="color:#999;padding:10px;">載入中…</td></tr></tbody></table>
          </div>
        </div>

        <!-- Tab5 移機快速備份 -->
        <div class="pm-tab" id="pm-tab-migbk" style="display:none;">
          <div style="font-size:12px;color:#9a7b4f;margin-bottom:8px;">
            換主機前一鍵打包:資料庫 + 環境設定快照 + uploads 附件 + 勾選的 NAS 文件,並自動附上「使用說明.txt」。
            <b>備份期間網頁可正常使用</b>(資料庫用不鎖表方式匯出、檔案只讀取;僅磁碟/網路較忙,尖峰時可能略慢)。
          </div>
          <div style="border:1px solid #e6d8c3;border-radius:6px;padding:10px;background:#fffaf2;margin-bottom:10px;">
            <div class="form-inline-row"><label>備份存放位置</label>
              <input type="text" id="mbDest" class="form-control" style="flex:1;min-width:280px;" placeholder="例:D:\EGsystem移機備份 或 外接硬碟 E:\..."></div>
            <div class="form-inline-row"><label>備份 uploads 附件</label>
              <label style="min-width:auto;"><input type="checkbox" id="mbUploads" checked> 網站內上傳附件(小,建議勾)</label></div>
            <div class="form-inline-row" style="align-items:flex-start;"><label>備份 NAS 文件</label>
              <div style="flex:1;">
                <label style="min-width:auto;"><input type="checkbox" id="mbDocs" checked> 連 NAS 上的文件一起備份(勾選要備份哪些路徑)</label>
                <div id="mbDocList" style="margin-top:6px;padding:6px 10px;background:#fff;border:1px solid #f0e7d7;border-radius:5px;max-height:160px;overflow-y:auto;font-size:12px;"></div>
              </div></div>
            <div class="form-inline-row"><label>順路自動備份</label>
              <select id="mbInterval" class="form-control" style="width:220px;">
                <option value="0">不自動(只手動備份)</option>
                <option value="7">每 7 天(只建議在不含NAS文件時)</option>
                <option value="30">每 30 天(建議值,含NAS文件)</option>
                <option value="90">每 90 天</option>
              </select>
              <span style="font-size:12px;color:#9a7b4f;">建議:含 NAS 文件選每月;資料庫本身已有每週自動備份鏈,這裡不用太密</span></div>
            <button class="btn btn-sm btn-default" onclick="migbkSave()"><i class="fa fa-save"></i> 儲存設定</button>
            <button class="btn btn-sm btn-amber" onclick="migbkStart()"><i class="fa fa-truck"></i> 立即快速備份</button>
          </div>
          <!-- 進度 -->
          <div id="mbProgWrap" style="display:none;border:1px solid #e6d8c3;border-radius:6px;padding:10px;background:#fffdf9;">
            <div style="display:flex;justify-content:space-between;font-size:12px;color:#6b5638;margin-bottom:4px;">
              <span id="mbProgLabel">—</span><span id="mbProgTime">—</span>
            </div>
            <div style="background:#f3ead9;border-radius:8px;height:18px;overflow:hidden;">
              <div id="mbProgBar" style="height:100%;width:0%;background:linear-gradient(90deg,#d99a4e,#b06f27);border-radius:8px;transition:width .6s;"></div>
            </div>
            <div id="mbProgMsg" style="font-size:12px;color:#8a5a1a;margin-top:6px;white-space:pre-wrap;"></div>
          </div>
        </div>
      </div>
    </div>
    </div><!-- /top-tab-migrate -->
    <?php endif; ?>

    <!-- ═══ Tab: 誤刪救援（部分還原）Phase 2 ═══ -->
    <div class="top-tab" id="top-tab-rescue" style="display:none;">
    <div class="bk-card" id="partialCard">
      <h4><i class="fa fa-life-ring"></i> 誤刪救援（部分還原）
        <small style="color:#9a7b4f;font-weight:400;">不確定資料在哪張表也能找：先把某個備份載入檢視區，再搜尋或掃描差異，勾選要救回的列</small></h4>
      <div style="display:flex;gap:10px;align-items:center;flex-wrap:wrap;margin-bottom:10px;">
        <span style="font-size:13px;color:#6b5638;">檢視區狀態：</span>
        <span id="vwStatus" class="st-badge st-running">未載入</span>
        <span id="vwInfo" style="font-size:12px;color:#9a7b4f;"></span>
        <span style="font-size:12px;color:#9a7b4f;">（在下方備份列表按「載入救援」選擇要用哪個備份）</span>
      </div>
      <div id="vwTools" style="display:none;">
        <ul class="nav nav-tabs" style="margin-bottom:10px;">
          <li class="active"><a href="#vw-tab-search" data-toggle="tab">值搜尋</a></li>
          <li><a href="#vw-tab-diff" data-toggle="tab" onclick="if(!scanDone)doScan()">誤刪掃描</a></li>
        </ul>
        <div class="tab-content">
          <div class="tab-pane active" id="vw-tab-search">
            <div style="display:flex;gap:8px;align-items:center;flex-wrap:wrap;">
              <input type="text" id="vwQ" class="form-control" style="width:280px;" placeholder="輸入關鍵字（客戶名/單號/料號…至少2字）">
              <button class="btn btn-sm btn-amber" onclick="doSearch()"><i class="fa fa-search"></i> 跨全部資料表搜尋</button>
              <span id="vwSearchHint" style="font-size:12px;color:#9a7b4f;"></span>
            </div>
          </div>
          <div class="tab-pane" id="vw-tab-diff">
            <div style="display:flex;gap:8px;align-items:center;flex-wrap:wrap;margin-bottom:8px;">
              <button class="btn btn-sm btn-amber" onclick="doScan()"><i class="fa fa-search-minus"></i> 重新掃描（備份有、現在沒有的列）</button>
              <span id="vwScanHint" style="font-size:12px;color:#9a7b4f;"></span>
            </div>
            <div id="vwScanList" style="display:flex;gap:6px;flex-wrap:wrap;"></div>
          </div>
        </div>
        <!-- 結果列表 -->
        <div id="vwResultWrap" style="display:none;margin-top:12px;">
          <div style="overflow-x:auto;max-height:380px;overflow-y:auto;border:1px solid #e6d8c3;border-radius:6px;">
            <table class="bk-tbl">
              <thead><tr>
                <th style="width:30px;"><input type="checkbox" id="vwCkAll" onclick="$('.vwck').prop('checked',this.checked)"></th>
                <th>資料表</th><th>主鍵</th><th>狀態</th><th>資料預覽</th>
              </tr></thead>
              <tbody id="vwResultBody"></tbody>
            </table>
          </div>
          <div style="display:flex;gap:10px;align-items:center;flex-wrap:wrap;margin-top:10px;">
            <select id="vwMode" class="form-control" style="width:230px;">
              <option value="insert">補回已刪除的列（現存列不動）</option>
              <option value="replace">覆蓋成備份版本（含現存列）</option>
            </select>
            <input type="password" id="vwPw" class="form-control" style="width:180px;" placeholder="部分還原密碼">
            <button class="btn btn-sm btn-coral" onclick="restoreSelected()"><i class="fa fa-undo"></i> 還原勾選的列</button>
            <span style="font-size:12px;color:#9a7b4f;">還原前會自動快照現況；一次上限 500 列</span>
          </div>
        </div>
      </div>
    </div>
    </div><!-- /top-tab-rescue -->
  </div>
</div></div></div>

<!-- 設定 Modal -->
<div class="modal fade" id="settingsModal" tabindex="-1" role="dialog"><div class="modal-dialog"><div class="modal-content">
  <div class="modal-header" style="background:var(--sand);"><button type="button" class="close" data-dismiss="modal">&times;</button>
    <h4 class="modal-title" style="color:var(--amber-d);"><i class="fa fa-cog"></i> 備份設定</h4></div>
  <div class="modal-body">
    <div class="form-inline-row"><label>自動備份間隔（天）</label><input type="number" min="1" max="365" id="cfInterval" class="form-control" style="width:120px;"></div>
    <div class="form-inline-row"><label>工作區保留份數</label><input type="number" min="1" max="200" id="cfKeep" class="form-control" style="width:120px;">
      <span style="font-size:12px;color:#9a7b4f;">超過的舊備份會從工作區移除，但仍留在 Git 歷史可還原</span></div>
    <div class="form-inline-row"><label>NAS 複製路徑</label><input type="text" id="cfNas" class="form-control" style="flex:1;min-width:200px;" placeholder="例：\\excellentnas\資料夾 或 Z:\DBbackup（留空=不複製）"></div>
    <div class="form-inline-row"><label>雲端自動 push</label>
      <label style="min-width:auto;"><input type="checkbox" id="cfPush"> 每次備份後自動 push 到私有 GitHub</label></div>
    <hr>
    <h5 style="color:var(--amber-d);"><i class="fa fa-lock"></i> 還原密碼（僅管理員可設；兩組不同）</h5>
    <div class="form-inline-row"><label>整表/整庫還原密碼</label><input type="password" id="pwTable" class="form-control" style="width:200px;" placeholder="留空=不變更；輸入新值=更新">
      <span id="pwTableSet" style="font-size:12px;"></span></div>
    <div class="form-inline-row"><label>部分還原密碼(Phase2)</label><input type="password" id="pwPartial" class="form-control" style="width:200px;" placeholder="留空=不變更；輸入新值=更新">
      <span id="pwPartialSet" style="font-size:12px;"></span></div>
    <div style="font-size:12px;color:#9a7b4f;">※ 密碼採單向雜湊儲存，設定後無法回看；可操作使用者無法更改。清空需輸入一個空白再另行處理（留空僅代表本次不變更）。</div>
  </div>
  <div class="modal-footer"><button class="btn btn-default" data-dismiss="modal">取消</button>
    <button class="btn btn-amber" id="btnSaveSettings">儲存設定</button>
    <button class="btn btn-amber" id="btnSavePw">更新密碼</button></div>
</div></div></div>

<!-- 整庫還原 Modal -->
<div class="modal fade" id="restoreFullModal" tabindex="-1" role="dialog"><div class="modal-dialog"><div class="modal-content">
  <div class="modal-header" style="background:#fbe6df;"><button type="button" class="close" data-dismiss="modal">&times;</button>
    <h4 class="modal-title" style="color:var(--coral);"><i class="fa fa-exclamation-triangle"></i> 整庫還原</h4></div>
  <div class="modal-body">
    <div class="warn-box"><b>高風險！</b>這會用所選備份<b>覆蓋整個現行資料庫</b>，之後的所有異動都會消失。系統會在還原前<b>自動先快照現況</b>當安全網，但仍請確認。</div>
    <p style="margin-top:10px;">將還原至：<b id="rfName">—</b>（<span id="rfTime">—</span>）</p>
    <div class="form-inline-row"><label>整表/整庫還原密碼</label><input type="password" id="rfPw" class="form-control" style="width:220px;"></div>
    <div class="form-inline-row"><label>輸入 <b>還原</b> 二字確認</label><input type="text" id="rfConfirm" class="form-control" style="width:160px;" placeholder="還原"></div>
  </div>
  <div class="modal-footer"><button class="btn btn-default" data-dismiss="modal">取消</button>
    <button class="btn btn-coral" id="btnDoRestoreFull">確認整庫還原</button></div>
</div></div></div>

<!-- 整表還原 Modal -->
<div class="modal fade" id="restoreTableModal" tabindex="-1" role="dialog"><div class="modal-dialog"><div class="modal-content">
  <div class="modal-header" style="background:#fbe6df;"><button type="button" class="close" data-dismiss="modal">&times;</button>
    <h4 class="modal-title" style="color:var(--coral);"><i class="fa fa-table"></i> 整表還原</h4></div>
  <div class="modal-body">
    <div class="warn-box">會用所選備份的該<b>單一資料表</b>覆蓋現行同名表（先 DROP 再重建），該表現有資料會被取代。還原前一樣會自動快照。</div>
    <p style="margin-top:10px;">來源備份：<b id="rtName">—</b>（<span id="rtTime">—</span>）</p>
    <div class="form-inline-row"><label>選擇資料表</label>
      <select id="rtTable" class="form-control" style="min-width:260px;"><option value="">載入中…</option></select></div>
    <div class="form-inline-row"><label>整表還原密碼</label><input type="password" id="rtPw" class="form-control" style="width:220px;"></div>
  </div>
  <div class="modal-footer"><button class="btn btn-default" data-dismiss="modal">取消</button>
    <button class="btn btn-coral" id="btnDoRestoreTable">確認整表還原</button></div>
</div></div></div>

<!-- 角色權限 Modal（沿用 Roles_API，module=db_backup）-->
<div class="modal fade" id="permModal" tabindex="-1" role="dialog"><div class="modal-dialog modal-lg"><div class="modal-content">
  <div class="modal-header" style="background:var(--sand);"><button type="button" class="close" data-dismiss="modal">&times;</button>
    <h4 class="modal-title" style="color:var(--amber-d);"><i class="fa fa-key"></i> 本頁角色權限設定 <small>（各頁分開，不連動）</small></h4></div>
  <div class="modal-body">
    <div style="display:flex;gap:14px;">
      <div style="flex:0 0 230px;border:1px solid #e6d8c3;border-radius:6px;">
        <div style="padding:6px 10px;background:var(--sand);font-size:12px;color:var(--amber-d);display:flex;justify-content:space-between;align-items:center;">
          角色 <button class="btn btn-xs btn-amber" onclick="addRole()">+ 新增</button></div>
        <div id="rolesList" style="max-height:340px;overflow:auto;"></div>
      </div>
      <div style="flex:1;">
        <div id="roleEditArea" style="display:none;">
          <div class="form-inline-row"><label>角色名稱</label><input type="text" id="edRoleName" class="form-control" style="flex:1;">
            <button class="btn btn-sm btn-default" onclick="renameRole()">改名</button>
            <button class="btn btn-sm btn-coral" onclick="delRole()">刪除</button></div>
          <div style="border:1px solid #e6d8c3;border-radius:6px;padding:10px;margin-top:8px;">
            <div style="font-size:12px;color:var(--amber-d);margin-bottom:6px;">功能勾選</div>
            <div id="featBox"></div>
            <button class="btn btn-sm btn-amber" style="margin-top:8px;" onclick="saveFeats()">儲存功能</button>
          </div>
          <div style="border:1px solid #e6d8c3;border-radius:6px;padding:10px;margin-top:8px;">
            <div style="font-size:12px;color:var(--amber-d);margin-bottom:6px;">指派使用者（勾選＝擁有此角色）</div>
            <div id="userBox" style="max-height:200px;overflow:auto;font-size:13px;"></div>
          </div>
        </div>
        <div id="roleHint" style="padding:30px;text-align:center;color:#b09a78;">請於左側選一個角色，或新增角色</div>
      </div>
    </div>
  </div>
</div></div></div>

<!-- 角色說明 Modal -->
<div class="modal fade" id="roleHelpModal" tabindex="-1" role="dialog"><div class="modal-dialog"><div class="modal-content">
  <div class="modal-header" style="background:var(--sand);"><button type="button" class="close" data-dismiss="modal">&times;</button>
    <h4 class="modal-title" style="color:var(--amber-d);">角色權限說明</h4></div>
  <div class="modal-body" style="font-size:13px;line-height:1.9;">
    <p><b>管理員</b>：固定擁有全部權限，含整庫還原、備份設定、還原密碼設定、角色設定。</p>
    <p><b>可操作使用者</b>：由管理員勾選其功能（檢視/下載、立即備份、整表還原、部分還原）。<b>整表/部分還原仍需輸入還原密碼</b>，密碼只有管理員能設定，因此可操作使用者若無密碼實際無法還原。</p>
    <p style="color:var(--coral);"><b>整庫還原、備份設定、還原密碼</b>一律僅限管理員。</p>
    <p style="color:#9a7b4f;">未被指派任何本頁角色者，無法進入此頁面。各頁角色設定互相獨立、不連動。</p>
  </div>
</div></div></div>

<footer><div class="pull-right"><a href="#">Excellentgear</a></div><div class="clearfix"></div></footer>

<script src="../../resource/js/jquery.min.js"></script>
<script src="../../resource/js/bootstrap.min.js"></script>
<script src="../../resource/js/fastclick.js"></script>
<script src="../../resource/js/nprogress.js"></script>
<script src="../../resource/js/custom.min.js"></script>
<script>
const API  = '../../src/store/DBBackup_API.php';
const RAPI = '../../src/store/Roles_API.php';
const MODULE = 'db_backup';
const IS_ADMIN = <?= $IS_ADMIN ? 'true' : 'false' ?>;
const PAGE_FEATURES = <?= json_encode($PAGE_FEATURES, JSON_UNESCAPED_UNICODE) ?>;
let PERM = {};

function fmtSize(b){ if(!b) return '—'; const mb=b/1048576; return mb>=1?mb.toFixed(2)+' MB':(b/1024).toFixed(0)+' KB'; }
function esc(s){ return $('<div>').text(s==null?'':s).html(); }

// ── 載入列表 + 狀態 ──
function loadList(){
  $.getJSON(API, {action:'list'}, function(res){
    if(!res.success){ alert(res.message||'讀取失敗'); return; }
    PERM = res.perm||{};
    if(PERM.restore_partial){ $('#ttbRescue').show(); pollViewStatus(); }
    const c = res.config||{};
    $('#stInterval').text(c.interval_days);
    $('#stKeep').text(c.keep_count);
    $('#stPush').text(c.auto_push?'開啟':'關閉');
    $('#stNas').text(c.nas_path||'（未設定）');
    if(c.last_error){ $('#errNote').show().html('<i class="fa fa-exclamation-triangle"></i> 上次錯誤：'+esc(c.last_error)); } else { $('#errNote').hide(); }
    // settings modal 帶入
    $('#cfInterval').val(c.interval_days); $('#cfKeep').val(c.keep_count);
    $('#cfNas').val(c.nas_path||''); $('#cfPush').prop('checked',!!c.auto_push);
    $('#pwTableSet').html(c.pw_table_set?'<span style="color:#4d6b2e;">已設定</span>':'<span style="color:#a3341f;">未設定（無法還原）</span>');
    $('#pwPartialSet').html(c.pw_partial_set?'<span style="color:#4d6b2e;">已設定</span>':'<span style="color:#a3341f;">未設定</span>');

    const rows = res.data||[];
    let last='—'; const okRow = rows.find(r=>r.status==='success'); if(okRow) last=okRow.created_at;
    $('#stLast').text(last);

    if(!rows.length){ $('#bkBody').html('<tr><td colspan="8" style="padding:20px;color:#999;">尚無備份紀錄</td></tr>'); return; }
    let h='';
    rows.forEach(function(r){
      const stMap={success:['st-success','成功'],fail:['st-fail','失敗'],running:['st-running','進行中'],'pre-restore':['st-pre','還原前快照']};
      const stc = r.status==='success' && r.trigger_type==='pre-restore' ? ['st-pre','還原前快照'] : (stMap[r.status]||['st-running',r.status]);
      let act='';
      if(r.status==='success'){
        act += '<a class="btn btn-xs btn-default" href="'+API+'?action=download&id='+r.id+'"><i class="fa fa-download"></i></a> ';
        if(PERM.restore_partial) act += '<button class="btn btn-xs btn-default" onclick="viewLoad('+r.id+')" title="載入此備份到誤刪救援檢視區"><i class="fa fa-life-ring"></i> 載入救援</button> ';
        if(PERM.restore_table) act += '<button class="btn btn-xs btn-default" onclick="openRestoreTable('+r.id+',\''+esc(r.filename)+'\',\''+esc(r.created_at)+'\')">整表還原</button> ';
        if(PERM.restore_full)  act += '<button class="btn btn-xs btn-coral" onclick="openRestoreFull('+r.id+',\''+esc(r.filename)+'\',\''+esc(r.created_at)+'\')">整庫還原</button> ';
        if(PERM.is_admin)      act += '<button class="btn btn-xs btn-default" onclick="deleteBackup('+r.id+',\''+esc(r.filename)+'\')" title="刪除此備份(git rm+推送;NAS複本不動)"><i class="fa fa-trash-o"></i></button>';
      }
      const trigMap={auto:'自動',manual:'手動','pre-restore':'還原前快照'};
      h += '<tr>'
        + '<td style="white-space:nowrap;">'+esc(r.created_at)+'</td>'
        + '<td style="font-family:monospace;font-size:12px;">'+esc(r.filename)+'</td>'
        + '<td style="white-space:nowrap;">'+fmtSize(+r.size_bytes)+'</td>'
        + '<td>'+(trigMap[r.trigger_type]||r.trigger_type)+'</td>'
        + '<td><span class="st-badge '+stc[0]+'">'+stc[1]+'</span></td>'
        + '<td><span class="pill '+(r.pushed==1?'on':'')+'">'+(r.pushed==1?'已上雲':'本機')+'</span></td>'
        + '<td style="font-size:11px;color:#8a5a1a;max-width:200px;">'+esc(r.note||'')+'</td>'
        + '<td style="white-space:nowrap;">'+act+'</td>'
        + '</tr>';
    });
    $('#bkBody').html(h);
  }).fail(function(){ alert('連線失敗'); });
}

// ── 立即備份 ──
$('#btnRun').on('click', function(){
  const $b=$(this); $b.prop('disabled',true).html('<i class="fa fa-spinner fa-spin"></i> 備份中…');
  $.post(API, {action:'run_now'}, function(res){
    alert(res.message||'');
    setTimeout(function(){ loadList(); $b.prop('disabled',false).html('<i class="fa fa-database"></i> 立即備份'); }, 4000);
  },'json').fail(function(){ alert('失敗'); $b.prop('disabled',false).html('<i class="fa fa-database"></i> 立即備份'); });
});
$('#btnReload').on('click', loadList);

// ── 設定 ──
function openSettings(){ $('#pwTable').val(''); $('#pwPartial').val(''); $('#settingsModal').modal('show'); }
$('#btnSaveSettings').on('click', function(){
  $.post(API, {action:'save_settings', interval_days:$('#cfInterval').val(), keep_count:$('#cfKeep').val(),
    nas_path:$('#cfNas').val(), auto_push:$('#cfPush').is(':checked')?'1':'0'}, function(res){
    alert(res.message||''); if(res.success) loadList();
  },'json');
});
$('#btnSavePw').on('click', function(){
  const t=$('#pwTable').val(), p=$('#pwPartial').val();
  if(t==='' && p===''){ alert('兩個密碼欄皆空白，未變更任何密碼'); return; }
  const jobs=[];
  if(t!=='') jobs.push($.post(API,{action:'set_password',type:'table',password:t},null,'json'));
  if(p!=='') jobs.push($.post(API,{action:'set_password',type:'partial',password:p},null,'json'));
  Promise.all(jobs).then(function(rs){ let msg=rs.map(r=>r.message).join('\n'); alert(msg); $('#pwTable').val(''); $('#pwPartial').val(''); loadList(); });
});

// ── 整庫還原 ──
let rfId=0;
function openRestoreFull(id,name,time){ rfId=id; $('#rfName').text(name); $('#rfTime').text(time); $('#rfPw').val(''); $('#rfConfirm').val(''); $('#restoreFullModal').modal('show'); }
$('#btnDoRestoreFull').on('click', function(){
  if($('#rfConfirm').val().trim()!=='還原'){ alert('請輸入「還原」二字確認'); return; }
  const $b=$(this); $b.prop('disabled',true).text('還原中，請勿關閉…');
  $.post(API,{action:'restore_full',id:rfId,password:$('#rfPw').val()},function(res){
    alert(res.message||''); $b.prop('disabled',false).text('確認整庫還原');
    if(res.success){ $('#restoreFullModal').modal('hide'); loadList(); }
  },'json').fail(function(){ alert('還原請求失敗'); $b.prop('disabled',false).text('確認整庫還原'); });
});

// ── 整表還原 ──
let rtId=0;
function openRestoreTable(id,name,time){
  rtId=id; $('#rtName').text(name); $('#rtTime').text(time); $('#rtPw').val('');
  $('#rtTable').html('<option value="">載入中…</option>');
  $('#restoreTableModal').modal('show');
  $.getJSON(API,{action:'list_tables',id:id},function(res){
    if(!res.success){ $('#rtTable').html('<option value="">讀取失敗</option>'); return; }
    let o='<option value="">— 請選擇資料表 —</option>';
    (res.data||[]).forEach(t=>o+='<option value="'+esc(t)+'">'+esc(t)+'</option>');
    $('#rtTable').html(o);
  });
}
$('#btnDoRestoreTable').on('click', function(){
  const t=$('#rtTable').val(); if(!t){ alert('請選擇資料表'); return; }
  if(!confirm('確定用備份覆蓋資料表「'+t+'」？此表現有資料會被取代。')) return;
  const $b=$(this); $b.prop('disabled',true).text('還原中…');
  $.post(API,{action:'restore_table',id:rtId,table:t,password:$('#rtPw').val()},function(res){
    alert(res.message||''); $b.prop('disabled',false).text('確認整表還原');
    if(res.success){ $('#restoreTableModal').modal('hide'); loadList(); }
  },'json').fail(function(){ alert('還原請求失敗'); $b.prop('disabled',false).text('確認整表還原'); });
});

// ── 角色權限（沿用 Roles_API）──
let curRoleId=0, rolesCache=[];
function openPerm(){ $('#permModal').modal('show'); loadRoles(); }
function loadRoles(){
  $.getJSON(RAPI,{action:'get_roles',module:MODULE},function(res){
    rolesCache = res.data||[];
    let h=''; rolesCache.forEach(function(r){
      h+='<div class="r-item'+(r.is_system==1?' sys':'')+'" data-id="'+r.role_id+'" onclick="selRole('+r.role_id+')">'+esc(r.role_name)+(r.is_system==1?'（系統）':'')+'</div>';
    });
    $('#rolesList').html(h||'<div style="padding:10px;color:#999;">尚無角色</div>');
  });
}
function selRole(id){
  curRoleId=id; const r=rolesCache.find(x=>x.role_id==id); if(!r) return;
  $('#rolesList .r-item').removeClass('active'); $('#rolesList .r-item[data-id="'+id+'"]').addClass('active');
  $('#roleHint').hide(); $('#roleEditArea').show();
  $('#edRoleName').val(r.role_name).prop('disabled', r.is_system==1);
  // 功能勾選
  let fb=''; PAGE_FEATURES.forEach(function(f){
    fb+='<label style="display:block;font-size:13px;"><input type="checkbox" class="featcb" value="'+f.code+'" '+(r.is_system==1?'checked disabled':'')+'> '+esc(f.label)+'</label>';
  });
  $('#featBox').html(fb);
  if(r.is_system!=1){
    $.getJSON(RAPI,{action:'get_role_features',role_id:id},function(res){
      const has=res.data||[]; $('.featcb').each(function(){ $(this).prop('checked', has.includes(this.value)||has.includes('all')); });
    });
  }
  loadUsersForRole(id, r.is_system==1);
}
function loadUsersForRole(rid,isSys){
  $.getJSON(RAPI,{action:'get_users',module:MODULE},function(res){
    const users=res.data||[]; let h='';
    users.forEach(function(u){
      const owned=(u.roles||[]).some(x=>x.role_id==rid);
      h+='<label style="display:block;"><input type="checkbox" class="ucb" data-uid="'+u.id+'" '+(owned?'checked':'')+' '+(isSys?'disabled':'')+'> '+esc(u.user_cname)+' <span style="color:#999;">('+esc(u.user_uname)+')</span></label>';
    });
    $('#userBox').html(h);
    $('.ucb').off('change').on('change',function(){
      const uid=$(this).data('uid'), on=this.checked;
      $.post(RAPI,{action:on?'assign_user_role':'remove_user_role',user_id:uid,role_id:rid},function(r){ if(!r.success) alert(r.message); },'json');
    });
  });
}
function addRole(){ const n=prompt('新角色名稱：'); if(!n) return;
  $.post(RAPI,{action:'save_role',role_name:n,module:MODULE},function(r){ if(r.success){ loadRoles(); } else alert(r.message); },'json'); }
function renameRole(){ if(!curRoleId) return; const n=$('#edRoleName').val().trim(); if(!n) return;
  $.post(RAPI,{action:'save_role',role_id:curRoleId,role_name:n},function(r){ if(r.success){ loadRoles(); } else alert(r.message); },'json'); }
function delRole(){ if(!curRoleId) return; if(!confirm('確定刪除此角色？')) return;
  $.post(RAPI,{action:'delete_role',role_id:curRoleId},function(r){ if(r.success){ $('#roleEditArea').hide(); $('#roleHint').show(); loadRoles(); } else alert(r.message); },'json'); }
function saveFeats(){ if(!curRoleId) return;
  const feats=$('.featcb:checked').map(function(){return this.value;}).get();
  $.post(RAPI,{action:'save_role_features',role_id:curRoleId,features:JSON.stringify(feats)},function(r){ alert(r.success?'已儲存功能':r.message); },'json'); }

// ── 第一層分頁切換 ──
function topTab(t,btn){
  $('.top-tab').hide(); $('#top-tab-'+t).show();
  $('#ttbBackup,#ttbRescue,#ttbMigrate').removeClass('active');
  if(btn) $(btn).addClass('active');
}

// ── 路徑遷移工具（僅管理員）──
let pmLoaded=false, pmCopyTimer=null, mbTimer=null;
function pmTab(t,btn){
  $('.pm-tab').hide(); $('#pm-tab-'+t).show();
  $('.pm-tab-btn').removeClass('active'); $(btn).addClass('active');
}
function pmLoad(){
  pmLoaded=true;
  $.getJSON(API,{action:'path_inventory'},function(res){
    if(!res.success){ alert(res.message||'盤點失敗'); return; }
    const d=res.data||{};
    let h='';
    (d.settings||[]).forEach(function(s){
      const st = s.kind==='url' ? '<span class="pill">網頁別名</span>'
               : s.exists===true ? '<span class="st-badge st-success">可連</span>'
               : s.exists===false ? '<span class="st-badge st-fail">連不到</span>'
               : '<span class="pill">未設定</span>';
      const pages=(s.pages||[]).join('、')||'—';
      h+='<tr><td><b>'+esc(s.label)+'</b><div style="font-family:monospace;font-size:10px;color:#b09a78;">'+esc(s.key)+'</div></td>'
        +'<td style="font-size:11px;max-width:220px;">'+esc(pages)+'</td>'
        +'<td style="font-family:monospace;font-size:11px;max-width:240px;word-break:break-all;">'+esc(s.value||'（空）')+'</td>'
        +'<td style="font-size:11px;color:#9a7b4f;">'+esc(s.store||'')+'</td>'
        +'<td>'+st+'</td>'
        +'<td><button class="btn btn-xs btn-default" onclick="pmEdit(\''+esc(s.scope)+'\',\''+esc(s.key)+'\',\''+esc((s.value||'').replace(/\\/g,'\\\\'))+'\')"><i class="fa fa-pencil"></i></button></td></tr>';
    });
    $('#pmSettingsBody').html(h||'<tr><td colspan="6" style="color:#999;">無</td></tr>');
    let h2='';
    (d.fullpath||[]).forEach(function(t){
      h2+='<tr><td>'+esc(t.label)+'</td><td style="font-family:monospace;font-size:11px;">'+esc(t.column)+'</td>'
        +'<td style="font-family:monospace;font-size:11px;max-width:220px;word-break:break-all;">'+esc(t.prefix)+'…</td><td>'+t.cnt+'</td></tr>';
    });
    $('#pmTablesBody').html(h2||'<tr><td colspan="4" style="color:#999;">目前沒有任何存完整路徑的資料（都是空表）</td></tr>');
    $('#pmHardcoded').html('<b>程式碼寫死路徑的頁面('+(d.hardcoded||[]).length+'頁,本工具管不到):</b> '
      +'bom_viewer、part_viewer、訂單追蹤圖檔掃描等。<b>最穩解法:新機沿用相同的 Z: 磁碟機對應與 excellentnas 主機名稱</b>(net use Z: 新位置;NAS主機名/分享名照舊),程式一行都不用改。清單詳見遷移包README。');
  });
  pmCopyPoll();
}
function pmEdit(scope,key,cur){
  const v = prompt('修改設定「'+key+'」的路徑值:', cur.replace(/\\\\/g,'\\'));
  if(v===null) return;
  $.post(API,{action:'path_set_setting',scope:scope,key:key,value:v},function(res){
    alert(res.message||''); if(res.success){ pmLoaded=false; pmLoad(); }
  },'json');
}
// 批次替換:①預覽(帶勾選框) ②執行勾選項
function pmBulkPreview(){
  const o=$('#pmOld').val().trim(), n=$('#pmNew').val().trim();
  if(!o||!n){ alert('舊前綴與新前綴都要填'); return; }
  $.post(API,{action:'path_bulk_prefix',old:o,new:n,dry:'1'},function(res){
    if(!res.success){ alert(res.message||''); return; }
    const items=res.items||[];
    if(!items.length){ $('#pmBulkResult').html('<b>'+esc(res.message)+'</b>（沒有任何項目以此前綴開頭）'); $('#pmBulkExec').hide(); return; }
    let h='<b>'+esc(res.message)+'</b> ② 勾選要套用的項目:'
      +'<label style="margin-left:10px;font-weight:400;"><input type="checkbox" checked onclick="$(\'.pmbulk-ck\').prop(\'checked\',this.checked)"> 全選</label>'
      +'<div style="border:1px solid #e6d8c3;border-radius:5px;padding:6px 10px;margin-top:4px;max-height:220px;overflow-y:auto;background:#fff;">';
    items.forEach(function(it){
      h+='<label style="display:block;font-weight:400;"><input type="checkbox" class="pmbulk-ck" value="'+esc(it.id)+'" checked> '
        +esc(it.label||it.key||it.table)+'：<code>'+esc(it.from)+'</code> → <code>'+esc(it.to)+'</code>（'+it.cnt+'筆）</label>';
    });
    h+='</div>';
    $('#pmBulkResult').html(h);
    $('#pmBulkExec').show();
  },'json');
}
function pmBulkExecute(){
  const o=$('#pmOld').val().trim(), n=$('#pmNew').val().trim();
  const sel=$('.pmbulk-ck:checked').map(function(){return this.value;}).get();
  if(!sel.length){ alert('未勾選任何項目'); return; }
  if(!confirm('確定把勾選的 '+sel.length+' 項路徑前綴「'+o+'」替換成「'+n+'」?')) return;
  $.post(API,{action:'path_bulk_prefix',old:o,new:n,dry:'0',selected:JSON.stringify(sel)},function(res){
    alert(res.message||'');
    if(res.success){ $('#pmBulkExec').hide(); $('#pmBulkResult').append('<div style="color:#4d6b2e;margin-top:4px;"><b>✔ 已執行,詳見「變更紀錄」分頁</b></div>'); pmLoaded=false; pmLoad(); }
  },'json');
}
// 變更紀錄
function pmLoadLog(){
  $.getJSON(API,{action:'path_changelog'},function(res){
    if(!res.success) return;
    let h='';
    (res.data||[]).forEach(function(r){
      const scopeMap={system_settings:'設定',db_backup_config:'備份設定',table_prefix:'資料表前綴'};
      h+='<tr><td style="white-space:nowrap;">'+esc(r.changed_at)+'</td>'
        +'<td><span class="pill">'+(scopeMap[r.scope]||r.scope)+'</span> <span style="font-family:monospace;font-size:11px;">'+esc(r.target)+'</span></td>'
        +'<td style="font-family:monospace;font-size:11px;max-width:220px;word-break:break-all;">'+esc(r.old_value||'')+'</td>'
        +'<td style="font-family:monospace;font-size:11px;max-width:220px;word-break:break-all;">'+esc(r.new_value||'')+'</td>'
        +'<td>'+r.affected_rows+'</td><td>'+esc(r.changed_by||'')+'</td></tr>';
    });
    $('#pmLogBody').html(h||'<tr><td colspan="6" style="color:#999;padding:10px;">尚無變更紀錄</td></tr>');
  });
}
// ── 移機快速備份 ──
function migbkLoad(){
  $.getJSON(API,{action:'migbk_get'},function(res){
    if(!res.success) return;
    const s=res.settings||{};
    $('#mbDest').val(s.dest||'');
    $('#mbUploads').prop('checked',!!s.include_uploads);
    $('#mbDocs').prop('checked',!!s.include_docs);
    $('#mbInterval').val(String(s.interval_days||0));
    let h='';
    (res.doc_options||[]).forEach(function(o){
      const on=(s.include_keys||[]).includes(o.key);
      const warn=o.exists===false?' <span class="st-badge st-fail">連不到</span>':'';
      h+='<label style="display:block;font-weight:400;"><input type="checkbox" class="mbdoc-ck" value="'+esc(o.key)+'" '+(on?'checked':'')+'> '
        +esc(o.label)+' <span style="font-family:monospace;color:#b09a78;">'+esc(o.path)+'</span>'+warn+'</label>';
    });
    $('#mbDocList').html(h||'<span style="color:#999;">目前沒有任何已設定的 NAS 路徑</span>');
    migbkPoll(res.status||{});
  });
}
function migbkSave(cb){
  const keys=$('.mbdoc-ck:checked').map(function(){return this.value;}).get();
  $.post(API,{action:'migbk_save',dest:$('#mbDest').val(),include_docs:$('#mbDocs').is(':checked')?'1':'0',
    include_uploads:$('#mbUploads').is(':checked')?'1':'0',include_keys:JSON.stringify(keys),
    interval_days:$('#mbInterval').val()},function(res){
    if(typeof cb==='function'){ cb(res); } else alert(res.message||'');
  },'json');
}
function migbkStart(){
  if(!$('#mbDest').val().trim()){ alert('請先填備份存放位置'); return; }
  migbkSave(function(sv){
    if(!sv.success){ alert(sv.message||'設定儲存失敗'); return; }
    $.post(API,{action:'migbk_start'},function(res){
      alert(res.message||'');
      if(res.success){ $('#mbProgWrap').show(); setTimeout(function(){ migbkPollFetch(); },1500); }
    },'json');
  });
}
function migbkPollFetch(){
  $.getJSON(API,{action:'migbk_status'},function(res){ if(res.success) migbkPoll(res.data||{}); });
}
function fmtDur(sec){ sec=Math.max(0,Math.round(sec)); const m=Math.floor(sec/60), s=sec%60; return (m? m+'分':'')+s+'秒'; }
function migbkPoll(d){
  if(!d.state){ $('#mbProgWrap').hide(); return; }
  $('#mbProgWrap').show();
  const total=+d.total_bytes||0, done=+d.done_bytes||0, elapsed=+d.elapsed_sec||0;
  let pct = d.state==='done'?100 : (total>0? Math.min(99,Math.round(done/total*100)) : (d.state==='scanning'?3:10));
  $('#mbProgBar').css('width',pct+'%');
  const stMap={scanning:'🔍 估算大小中',running:'🚚 備份中',done:'✅ 完成',fail:'❌ 失敗'};
  $('#mbProgLabel').text((stMap[d.state]||d.state)+'：'+(d.current||'')+'  '+pct+'%'
    +(total?('（'+(done/1048576).toFixed(0)+' / '+(total/1048576).toFixed(0)+' MB）'):''));
  let eta='';
  if(d.state==='running' && done>0 && total>done && elapsed>3){
    eta='　預計剩餘 '+fmtDur(elapsed*(total-done)/done);
  }
  $('#mbProgTime').text('已經過 '+fmtDur(elapsed)+eta);
  let msg=d.msg||'';
  if(d.state==='done') msg='備份完成!存放位置:'+(d.dest||$('#mbDest').val())+'\n內含「使用說明.txt」,換機時照說明還原。'+(msg?'\n警告:'+msg:'');
  if(d.state==='fail') msg='失敗原因:'+msg;
  $('#mbProgMsg').text(msg);
  if(d.state==='scanning'||d.state==='running'){ clearTimeout(mbTimer); mbTimer=setTimeout(migbkPollFetch,3000); }
}
function pmCopyStart(){
  const s=$('#pmSrc').val().trim(), d=$('#pmDst').val().trim();
  if(!s||!d){ alert('來源與目的都要填'); return; }
  if(!confirm('開始把\n'+s+'\n複製到\n'+d+'\n?(背景執行,只新增不刪除)')) return;
  $.post(API,{action:'path_copy_start',src:s,dst:d},function(res){
    alert(res.message||''); if(res.success) pmCopyPoll();
  },'json');
}
function pmCopyPoll(){
  $.getJSON(API,{action:'path_copy_status'},function(res){
    if(!res.success) return;
    const d=res.data||{};
    if(d.status==='idle'){ $('#pmCopyStatus').text(''); return; }
    const map={running:'⏳ 複製中…',done:'✅ 完成',fail:'❌ 失敗'};
    $('#pmCopyStatus').text((map[d.status]||d.status)+'  '+d.src+' → '+d.dst+(d.log?'\n'+d.log:''));
    if(d.status==='running'){ clearTimeout(pmCopyTimer); pmCopyTimer=setTimeout(pmCopyPoll,5000); }
  });
}

// ── 刪除備份 / 徹底清除Git歷史（僅管理員）──
function deleteBackup(id, name){
  if(!confirm('刪除備份「'+name+'」?\n\n・工作區與Git最新版會移除並推送雲端\n・NAS上的複本不會動\n・Git歷史仍有殘留,要連歷史清掉請再執行「徹底清除Git歷史」')) return;
  $.post(API,{action:'delete_backup',id:id},function(res){ alert(res.message||''); if(res.success) loadList(); },'json');
}
function purgeHistory(){
  const w = prompt('這會把Git備份庫的「所有歷史版本」壓縮成只剩目前檔案的單一版本,並強制覆蓋雲端(GitHub)——已刪除備份與已被汰換的舊備份將無法再從歷史找回。\n\nNAS複本與現行工作區檔案不受影響。\n\n確定請輸入「清除歷史」四字:');
  if(w===null) return;
  $.post(API,{action:'purge_history',confirm:w},function(res){ alert(res.message||''); if(res.success) loadList(); },'json');
}

// ═══════════ 誤刪救援（部分還原）Phase 2 ═══════════
let scanDone=false, vwPollTimer=null, vwRows=[];

function pollViewStatus(){
  $.getJSON(API,{action:'view_status'},function(res){
    if(!res.success) return;
    const d=res.data||{};
    const map={none:['st-running','未載入'],loading:['st-running','載入中…約20秒'],ready:['st-success','已就緒'],fail:['st-fail','載入失敗']};
    const m=map[d.status]||map.none;
    $('#vwStatus').attr('class','st-badge '+m[0]).text(m[1]);
    $('#vwInfo').text(d.status==='ready'||d.status==='loading' ? ((d.filename||'')+(d.backup_time?'（備份時間 '+d.backup_time+'）':'')) : (d.status==='fail'?(d.error||''):''));
    if(d.status==='ready'){ $('#vwTools').show(); }
    if(d.status==='loading'){ clearTimeout(vwPollTimer); vwPollTimer=setTimeout(pollViewStatus,3000); }
  });
}
function viewLoad(id){
  if(!confirm('把這個備份載入誤刪救援檢視區？（會取代目前檢視區內容，約需 20 秒）')) return;
  scanDone=false; $('#vwScanList').empty(); $('#vwResultWrap').hide(); $('#vwResultBody').empty();
  $.post(API,{action:'view_load',id:id},function(res){
    alert(res.message||'');
    if(res.success){ $('#vwTools').hide(); pollViewStatus(); topTab('rescue',document.getElementById('ttbRescue')); $('html,body').animate({scrollTop:0},300); }
  },'json');
}
function renderRows(rows){
  vwRows=rows||[];
  if(!vwRows.length){ $('#vwResultWrap').hide(); return; }
  let h='';
  vwRows.forEach(function(r,i){
    const pv=Object.entries(r.preview||{}).map(([k,v])=>'<b>'+esc(k)+'</b>='+esc(v)).join('、');
    const st = r.exists_live===false ? '<span class="st-badge st-fail">已不存在（可補回）</span>'
             : r.exists_live===true  ? '<span class="st-badge st-success">現存（覆蓋才會動）</span>'
             : '<span class="st-badge st-running">無法判斷</span>';
    h+='<tr>'
      +'<td><input type="checkbox" class="vwck" data-i="'+i+'" '+(r.exists_live===false?'checked':'')+'></td>'
      +'<td style="font-family:monospace;">'+esc(r.table)+'</td>'
      +'<td style="font-family:monospace;font-size:12px;">'+esc((r.pk_cols||[]).join(','))+'=('+esc((r.pk_vals||[]).join(','))+')</td>'
      +'<td>'+st+'</td>'
      +'<td style="font-size:11px;max-width:520px;">'+pv+'</td>'
      +'</tr>';
  });
  $('#vwResultBody').html(h);
  $('#vwCkAll').prop('checked',false);
  $('#vwResultWrap').show();
}
function doSearch(){
  const q=$('#vwQ').val().trim();
  if(q.length<2){ alert('關鍵字至少 2 個字'); return; }
  $('#vwSearchHint').text('搜尋中…');
  $.getJSON(API,{action:'view_search',q:q},function(res){
    if(!res.success){ alert(res.message||''); $('#vwSearchHint').text(''); return; }
    $('#vwSearchHint').text('命中 '+(res.data||[]).length+' 列（每表最多20列、總數上限300）');
    renderRows(res.data);
  }).fail(function(){ $('#vwSearchHint').text('搜尋失敗'); });
}
function doScan(){
  scanDone=true;
  $('#vwScanHint').text('掃描中…');
  $.getJSON(API,{action:'view_diff_overview'},function(res){
    if(!res.success){ alert(res.message||''); $('#vwScanHint').text(''); return; }
    const d=res.data||[];
    $('#vwScanHint').text(d.length? d.length+' 張資料表有「備份有、現在沒有」的列，點表名看明細：' : '沒有發現任何缺列（備份之後沒有資料被刪除）');
    let h='';
    d.forEach(function(o){ h+='<button class="btn btn-xs btn-default" onclick="loadDiffTable(\''+esc(o.table)+'\')" style="font-family:monospace;">'+esc(o.table)+' <span class="st-badge st-fail">'+o.missing+'</span></button>'; });
    $('#vwScanList').html(h);
  }).fail(function(){ $('#vwScanHint').text('掃描失敗'); });
}
function loadDiffTable(t){
  $.getJSON(API,{action:'view_diff_table',table:t},function(res){
    if(!res.success){ alert(res.message||''); return; }
    renderRows(res.data);
  });
}
function restoreSelected(){
  // 依表分組收集勾選列
  const byTable={};
  $('.vwck:checked').each(function(){
    const r=vwRows[$(this).data('i')]; if(!r) return;
    (byTable[r.table]=byTable[r.table]||[]).push(r.pk_vals);
  });
  const tables=Object.keys(byTable);
  if(!tables.length){ alert('未勾選任何列'); return; }
  const pw=$('#vwPw').val(); if(!pw){ alert('請輸入部分還原密碼'); return; }
  const mode=$('#vwMode').val();
  const total=tables.reduce((s,t)=>s+byTable[t].length,0);
  if(!confirm('將還原 '+tables.length+' 張表共 '+total+' 列（模式：'+(mode==='replace'?'覆蓋成備份版本':'補回已刪除列')+'）。還原前會自動快照，確定？')) return;
  // 逐表送出（多表時串行處理）
  let idx=0, msgs=[];
  function next(){
    if(idx>=tables.length){ alert(msgs.join('\n')); $('#vwPw').val(''); return; }
    const t=tables[idx++];
    $.post(API,{action:'view_restore_rows',table:t,pk_list:JSON.stringify(byTable[t]),mode:mode,password:pw},function(res){
      msgs.push(t+'：'+(res.message||res.msg||''));
      next();
    },'json').fail(function(){ msgs.push(t+'：請求失敗'); next(); });
  }
  next();
}

loadList();
</script>
</body></html>
