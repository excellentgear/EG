<?php
/**
 * bom_tracking.php — BOM 追蹤（自動追蹤規則 + 進度清單 + 通知 + 分享）
 * 後端：src/store/BomTrack_API.php ｜ 共用：src/common/role_features_helper.php、src/common/bom_track_notify.php
 * 視覺設計參考 views/Sales/NewOrder_Track222.php；部門/人員多選參考 views/liveEvent/createEvent.php
 */
ini_set('display_errors', 1);
error_reporting(E_ALL);

session_start();

require_once __DIR__ . '/../../src/common/_config.php';
require_once __DIR__ . '/../../src/common/DBConnection.php';
require_once __DIR__ . '/../../src/common/role_features_helper.php';

if (!isset($_SESSION['userName'])) {
    $_SESSION['lastpage'] = "../../views/pm/bom_tracking.php";
    header('Location: ../../index.php');
    exit;
}

$conn = new DBConnection();
$db = $conn->getPDO();
$my_id = (int)$_SESSION['id'];
$has_access = rf_has_module_role($db, $my_id, 'bom_track');

// 部門/人員清單（給通知對象、分享對象的多選下拉用；比照 views/liveEvent/createEvent.php 的作法）
$departments = [];
$users = [];
if ($has_access) {
    $departments = $conn->getAll("SELECT id, name, parent_id, level FROM department ORDER BY level ASC, sort_order ASC, name ASC");
    $deptMap = [];
    foreach ($departments as $d) { $deptMap[$d['id']] = $d; }
    if (!function_exists('getDeptPath')) {
        function getDeptPath($deptId, $deptMap) {
            if (empty($deptId) || !isset($deptMap[$deptId])) return '未指定';
            $path = []; $curr = $deptMap[$deptId]; $limit = 10;
            while ($curr && $limit-- > 0) {
                array_unshift($path, $curr['name']);
                if ($curr['level'] <= 3) break;
                $parentId = $curr['parent_id'];
                if (!$parentId || !isset($deptMap[$parentId])) break;
                $curr = $deptMap[$parentId];
            }
            return implode(' / ', $path);
        }
    }
    $users = $conn->getAll(
        "SELECT u.id, u.user_cname, d.name AS department_name, d.id AS department_id, p.name AS position_name
         FROM user u
         LEFT JOIN user_department_position_map udpm ON u.id = udpm.user_id AND udpm.is_main = 1
         LEFT JOIN department d ON udpm.department_id = d.id
         LEFT JOIN position p ON udpm.position_id = p.id
         WHERE u.state NOT IN (0, 90) ORDER BY u.user_cname ASC"
    );
    if (!function_exists('eg_bomtrk_target_options')) {
        function eg_bomtrk_target_options($departments, $deptMap, $users) {
            $html = '<optgroup label="部門">';
            foreach ($departments as $d) {
                $path = getDeptPath($d['id'], $deptMap);
                $html .= '<option value="dept-' . $d['id'] . '">' . htmlspecialchars($path) . '</option>';
            }
            $html .= '</optgroup><optgroup label="人員">';
            foreach ($users as $u) {
                $upath = getDeptPath($u['department_id'] ?? 0, $deptMap);
                $upos = $u['position_name'] ?? '未指定';
                $html .= '<option value="user-' . $u['id'] . '">' . htmlspecialchars($u['user_cname']) . '（' . htmlspecialchars($upath) . ' / ' . htmlspecialchars($upos) . '）</option>';
            }
            $html .= '</optgroup>';
            return $html;
        }
    }
}
?>
<!DOCTYPE html>
<html lang="zh-Hant">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>BOM 追蹤</title>
    <link href="../../resource/css/bootstrap.css" rel="stylesheet">
    <link href="../../resource/css/font-awesome.css" rel="stylesheet">
    <link href="../../resource/css/custom.css" rel="stylesheet">
    <link href="../../resource/css/select2.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://code.jquery.com/ui/1.12.1/themes/base/jquery-ui.css">
    <style>
        input[type=number]::-webkit-inner-spin-button,
        input[type=number]::-webkit-outer-spin-button { -webkit-appearance:none; margin:0; }
        input[type=number]{ -moz-appearance:textfield; appearance:textfield; }

        /* ── 視覺系統：比照 NewOrder_Track222.php ── */
        :root {
            --primary-color: #2A3F54;
            --accent-color: #1ABB9C;
            --bg-color: #F4F7FC;
            --card-bg: #FFFFFF;
            --text-color: #495057;
        }
        body { background-color: var(--bg-color); font-family: "Segoe UI","Roboto","Helvetica Neue",Arial,sans-serif; color: var(--text-color); }
        .right_col { background-color: var(--bg-color) !important; }

        .stats-container { display:flex; gap:15px; margin-bottom:15px; flex-wrap:wrap; }
        .stat-card { flex:1; min-width:150px; background:var(--card-bg); border-radius:8px; padding:15px;
            box-shadow:0 2px 5px rgba(0,0,0,.05); border-left:4px solid transparent; position:relative; overflow:hidden; }
        .stat-card .stat-icon { position:absolute; right:15px; top:15px; font-size:32px; opacity:.1; }
        .stat-card .stat-value { font-size:24px; font-weight:800; color:var(--primary-color); }
        .stat-card .stat-label { font-size:12px; color:#888; font-weight:600; text-transform:uppercase; letter-spacing:1px; }
        .stat-card.c-total  { border-left-color:#3498DB; }
        .stat-card.c-open   { border-left-color:#F39C12; }
        .stat-card.c-closed { border-left-color:#1ABB9C; }

        .filter-bar { background:#fff; padding:10px; border-radius:8px; margin-bottom:15px;
            display:flex; gap:10px; align-items:center; flex-wrap:wrap; box-shadow:0 2px 5px rgba(0,0,0,.05); }
        .main-card { background:var(--card-bg); border-radius:8px; box-shadow:0 2px 5px rgba(0,0,0,.05); padding:15px; }

        table.bomtrk-table thead th { background:#F8F9FA; color:#555; font-weight:700; border-bottom:2px solid #E9ECEF;
            padding:10px 5px; font-size:13px; white-space:nowrap; }
        table.bomtrk-table tbody td { padding:6px 5px; vertical-align:middle; border-bottom:1px solid #F1F3F5; font-size:13px; }
        table.bomtrk-table tbody tr:hover { background:#FAFBFE; }
        table.bomtrk-table.hide-select-col .col-select { display:none; }
        /* 進行中(未結案)整列淡橘底色，讓仍需追蹤的BOM一眼跳出來；已結案維持白底(斑馬紋) */
        table.bomtrk-table tbody tr.row-open > td { background-color:#FFF7E6; }
        table.bomtrk-table tbody tr.row-open:hover > td { background-color:#FFEFCC; }

        /* 追蹤規則(料號/BOM/客戶/業務)搜尋用：保留 chip + 關鍵字搜尋 */
        .ac-box{ position:relative; }
        .ac-list{ position:absolute; z-index:1000; left:0; right:0; background:#fff; border:1px solid #ccc; border-top:none; max-height:220px; overflow:auto; display:none; }
        .ac-item{ padding:6px 10px; cursor:pointer; border-bottom:1px solid #f0f0f0; font-size:13px; }
        .ac-item:hover{ background:#f5f5f5; }
        .rule-row{ display:flex; align-items:center; gap:8px; padding:6px 0; border-bottom:1px dashed #eee; }
        .rule-row .rule-del{ color:#d9534f; cursor:pointer; }

        .no-access-warn { font-size:12px; color:#e74c3c; margin-top:8px; }
        .no-access-box{ max-width:520px; margin:80px auto; text-align:center; padding:40px; background:#fff; border-radius:8px; box-shadow:0 2px 5px rgba(0,0,0,.08); }

        /* select2 若在 modal 尚未顯示(display:none)時就初始化，自動抓寬會抓到0導致文字被裁掉；強制滿版寬度 */
        .select2-container { width: 100% !important; }

        /* 分頁列固定在表格右上角 */
        .table-toolbar { display:flex; justify-content:space-between; align-items:center; margin-bottom:8px; flex-wrap:wrap; gap:8px; }
        .rubber-select-box { position:absolute; border:1px solid #2980b9; background:rgba(41,128,185,.15); z-index:20; pointer-events:none; }

        /* 使用說明按鈕/跳窗：全站統一樣式，照抄 views/pm/vendor_audit.php */
        .page-help-btn { height:30px; font-size:13px; padding:0 12px; border:1px solid #d98a33; border-radius:15px;
            background:#F0A24B; color:#fff; cursor:pointer; }
        .page-help-btn:hover { background:#d98a33; }
        @media print { .page-help-btn { display:none !important; } }
        .help-doc { font-size:13px; color:#5b3a1e; line-height:1.75; }
        .help-doc h4 { color:#8A5A2B; border-bottom:2px solid #F7E0BD; padding-bottom:3px; margin:14px 0 6px; font-size:15px; }
        .help-doc h4:first-child { margin-top:0; }
        .help-doc b { color:#8A5A2B; }
        .help-doc ul { margin:4px 0 8px; padding-left:20px; }
        .help-doc li { margin:2px 0; }
        .help-doc .tip { background:#FFF7E8; border:1px dashed #F0A24B; border-radius:6px; padding:6px 10px; margin:6px 0; }
    </style>
</head>
<body class="nav-sm">
<div class="container body">
  <div class="main_container">
    <?php include '../partPage/sideAndTopBarMenu.html'; ?>

    <div class="right_col" role="main">
<?php if (!$has_access): ?>
      <div class="no-access-box">
          <i class="fa fa-lock" style="font-size:40px;color:#e74c3c;"></i>
          <h3 style="margin-top:15px;">請先申請權限</h3>
          <p style="color:#888;">您目前沒有「BOM追蹤」功能的使用權限，請聯絡管理者至「使用者權限管理」頁面指派角色。</p>
      </div>
<?php else: ?>
      <div class="page-title" style="display:flex;align-items:center;flex-wrap:wrap;">
        <div class="title_left"><h3>BOM 追蹤</h3></div>
        <button id="btnPageHelp" class="page-help-btn" style="margin-left:auto;"><i class="fa fa-question-circle"></i> 使用說明</button>
      </div>
      <div class="clearfix"></div>

      <div class="stats-container">
        <div class="stat-card c-total"><i class="fa fa-cubes stat-icon"></i><div class="stat-value" id="statTotal">0</div><div class="stat-label">符合規則BOM</div></div>
        <div class="stat-card c-open"><i class="fa fa-spinner stat-icon"></i><div class="stat-value" id="statOpen">0</div><div class="stat-label">進行中</div></div>
        <div class="stat-card c-closed"><i class="fa fa-check-circle stat-icon"></i><div class="stat-value" id="statClosed">0</div><div class="stat-label">已結案</div></div>
      </div>

      <div class="filter-bar">
        <label style="margin:0;">群組：</label>
        <select id="groupSelect" class="form-control input-sm" style="width:200px;"></select>
        <button class="btn btn-success btn-sm" id="btnNewGroup"><i class="fa fa-plus"></i> 新增群組</button>
        <button class="btn btn-default btn-sm" id="btnManageGroup" disabled><i class="fa fa-cog"></i> 管理此群組</button>
        <button class="btn btn-danger btn-sm" id="btnDeleteGroup" disabled><i class="fa fa-trash"></i> 刪除群組</button>
        <span id="groupOwnerBadge" style="color:#888;font-size:12px;"></span>
        <input type="text" id="filterBom" class="form-control input-sm" placeholder="BOM編號關鍵字" style="width:160px;">
        <select id="filterStatus" class="form-control input-sm" style="width:120px;">
          <option value="">全部狀態</option>
          <option value="open">進行中</option>
          <option value="closed">已結案</option>
        </select>
        <button class="btn btn-primary btn-sm" id="btnFilter">篩選</button>
        <div style="margin-left:auto; display:flex; gap:8px;">
          <button class="btn btn-info btn-sm" id="btnExportCsv">轉 CSV</button>
          <button class="btn btn-info btn-sm" id="btnExportPdf">轉 PDF</button>
        </div>
      </div>

      <div class="main-card">
        <div class="table-toolbar">
          <div id="bulkNotifyBar" style="display:flex;align-items:center;gap:8px;">
            <button class="btn btn-warning btn-sm" id="btnBulkNotify" disabled><i class="fa fa-bell"></i> 設定通知（已選 <span id="selBomCount">0</span> 筆）</button>
            <span style="color:#888;font-size:12px;">勾選或框選本頁多筆BOM後可一次設定通知對象</span>
          </div>
          <div style="display:flex;align-items:center;gap:8px;">
            <div id="pageInfo" style="color:#888;font-size:12px;"></div>
            <select id="pageSizeSelect" class="form-control input-sm" style="width:80px;">
              <option value="5">5</option>
              <option value="10" selected>10</option>
              <option value="20">20</option>
              <option value="50">50</option>
            </select>
            <button class="btn btn-default btn-xs" id="btnPrevPage"><i class="fa fa-chevron-left"></i></button>
            <span id="pageNum">1</span>
            <button class="btn btn-default btn-xs" id="btnNextPage"><i class="fa fa-chevron-right"></i></button>
          </div>
        </div>
        <div id="bomTableWrap" style="overflow-x:auto;width:100%;position:relative;">
          <table class="table table-striped bomtrk-table" id="bomTrackTable">
            <thead><tr>
              <th class="col-select" style="width:30px;"><input type="checkbox" id="bomSelectAll"></th>
              <th>BOM</th><th>負責業務</th><th>料號</th><th>客戶</th><th>交期／訂單編號</th><th>目前製程</th><th>進度</th><th>狀態</th><th>操作</th>
            </tr></thead>
            <tbody id="bomTrackTbody"><tr><td colspan="10" class="text-center text-muted">請先選擇或新增群組</td></tr></tbody>
          </table>
        </div>
      </div>
<?php endif; ?>
    </div>
  </div>
</div>

<?php if ($has_access): ?>
<!-- ══ 管理群組 Modal ══ -->
<div class="modal fade" id="manageGroupModal" role="dialog" tabindex="-1">
  <div class="modal-dialog" style="width:680px;">
    <div class="modal-content">
      <div class="modal-header"><button type="button" class="close" data-dismiss="modal">&times;</button>
        <h4 class="modal-title"><i class="fa fa-cog"></i> 管理群組：<span id="mgGroupName"></span></h4></div>
      <div class="modal-body">
        <ul class="nav nav-tabs" role="tablist">
          <li class="active"><a href="#tabRules" role="tab" data-toggle="tab">追蹤規則</a></li>
          <li><a href="#tabNotify" role="tab" data-toggle="tab">通知設定</a></li>
          <li><a href="#tabShare" role="tab" data-toggle="tab">分享設定</a></li>
        </ul>
        <div class="tab-content" style="padding-top:15px;">
          <div class="tab-pane active" id="tabRules">
            <div id="excludeClosedBox" style="margin-bottom:12px;padding:8px 10px;background:#FFF7E6;border-radius:6px;">
              <label style="margin:0;"><input type="checkbox" id="excludeClosedToggle"> 排除已結案（僅排除勾選當下已符合規則且已結案的BOM，永久排除；之後才結案的仍會顯示）</label>
              <span id="closedSnapInfo" style="color:#888;font-size:12px;margin-left:10px;"></span>
              <button class="btn btn-default btn-xs" id="btnRefreshSnapshot" style="margin-left:8px;display:none;">重新整理快照</button>
            </div>
            <div id="ruleList" style="margin-bottom:15px;"></div>
            <hr>
            <div class="form-group">
              <label>新增規則</label><br>
              <label style="font-weight:normal;margin-right:6px;">加入條件組：</label>
              <select id="newRuleCondGroup" class="form-control input-sm" style="width:160px;display:inline-block;"></select>
              <button class="btn btn-default btn-xs" id="btnAddCondGroup" style="margin-left:4px;"><i class="fa fa-plus"></i> 新增條件組</button>
              <span style="color:#888;font-size:12px;margin-left:8px;">同一條件組內的規則彼此是「且 AND」，不同條件組之間是「或 OR」</span>
              <br><br>
              <select id="newRuleType" class="form-control" style="width:160px;display:inline-block;">
                <option value="part">料號</option>
                <option value="bom">BOM編號</option>
                <option value="customer">客戶</option>
                <option value="sales">業務</option>
                <option value="due_range">交期區間</option>
              </select>
            </div>
            <div id="ruleValueArea"></div>
          </div>
          <div class="tab-pane" id="tabNotify">
            <div class="checkbox"><label><input type="checkbox" id="notifyGroupToggle"> 整個群組開啟通知</label></div>
            <div id="groupSubscriberBox" style="display:none;">
              <label>通知對象</label>
              <select id="groupSubscriberSelect" multiple style="width:100%;">
                <?= eg_bomtrk_target_options($departments, $deptMap, $users) ?>
              </select>
            </div>
            <hr>
            <p style="color:#888;font-size:12px;">也可以只針對某一筆BOM單獨開通知：於下方清單每列操作欄點「通知」設定。</p>
          </div>
          <div class="tab-pane" id="tabShare">
            <label>分享對象（部門 / 人員，可多選）</label>
            <select id="shareSelect" multiple style="width:100%;">
              <?= eg_bomtrk_target_options($departments, $deptMap, $users) ?>
            </select>
            <div id="shareNoAccessWarn" class="no-access-warn" style="display:none;"></div>
          </div>
        </div>
      </div>
      <div class="modal-footer"><button type="button" class="btn btn-default" data-dismiss="modal">關閉</button></div>
    </div>
  </div>
</div>

<!-- ══ 單筆/批次BOM通知 Modal ══ -->
<div class="modal fade" id="bomNotifyModal" role="dialog" tabindex="-1">
  <div class="modal-dialog" style="width:520px;">
    <div class="modal-content">
      <div class="modal-header"><button type="button" class="close" data-dismiss="modal">&times;</button>
        <h4 class="modal-title">通知設定：<span id="bnBomNo"></span></h4></div>
      <div class="modal-body">
        <p id="bnBatchHint" style="color:#888;font-size:12px;display:none;">批次設定：套用後會統一覆蓋這幾筆BOM各自原有的通知設定。</p>
        <div class="checkbox"><label><input type="checkbox" id="bnToggle"> 開啟通知</label></div>
        <div id="bnSubBox" style="display:none;">
          <label>通知對象</label>
          <select id="bnSubscriberSelect" multiple style="width:100%;">
            <?= eg_bomtrk_target_options($departments, $deptMap, $users) ?>
          </select>
        </div>
      </div>
      <div class="modal-footer">
        <button type="button" class="btn btn-default" data-dismiss="modal">取消</button>
        <button type="button" class="btn btn-primary" id="bnApply">套用設定</button>
      </div>
    </div>
  </div>
</div>

<!-- ══ 進度時間軸 Modal ══ -->
<div class="modal fade" id="timelineModal" role="dialog" tabindex="-1">
  <div class="modal-dialog" style="width:680px;">
    <div class="modal-content">
      <div class="modal-header"><button type="button" class="close" data-dismiss="modal">&times;</button>
        <h4 class="modal-title">進度時間軸：<span id="tlBomNo"></span></h4></div>
      <div class="modal-body"><div id="tlBody" style="max-height:450px;overflow-y:auto;"></div></div>
      <div class="modal-footer"><button type="button" class="btn btn-default" data-dismiss="modal">關閉</button></div>
    </div>
  </div>
</div>

<!-- ══ 使用說明 Modal ══ -->
<div class="modal fade" id="helpUseMask" role="dialog" tabindex="-1">
  <div class="modal-dialog" style="width:680px;">
    <div class="modal-content">
      <div class="modal-header"><button type="button" class="close" data-dismiss="modal">&times;</button>
        <h4 class="modal-title"><i class="fa fa-question-circle"></i> BOM 追蹤 使用說明</h4></div>
      <div class="modal-body help-doc">
        <h4>功能說明</h4>
        <p>依「群組」自訂追蹤規則，自動抓出符合條件的BOM並持續顯示進度，可對整個群組或單筆BOM設定通知、也可把群組分享給其他人（部門/個人）檢視。</p>

        <h4>操作步驟</h4>
        <ul>
          <li>先在上方「群組」下拉新增或選擇一個追蹤群組。</li>
          <li>點「管理此群組」→「追蹤規則」分頁，新增規則（料號／BOM編號／客戶／業務／交期區間）。</li>
          <li>規則設好後，清單即自動列出符合條件的BOM，可用篩選列的關鍵字/狀態再縮小範圍。</li>
          <li>「通知設定」分頁可開啟整個群組的通知，或在清單每列點「通知」單獨設定該筆BOM。</li>
          <li>「分享設定」分頁可把群組分享給其他部門或人員（對方需已有BOM追蹤權限才看得到）。</li>
        </ul>

        <h4>條件組（AND / OR 混用）</h4>
        <div class="tip">同一個「條件組」內的規則彼此是「且 AND」（例如料號=A 且 客戶=X）；不同條件組之間是「或 OR」。例如建立「條件組1：料號=A、客戶=X」與「條件組2：料號=B」，會匹配「(料號=A 且 客戶=X) 或 (料號=B)」。點「新增條件組」建立新的OR分支；每條規則新增前用「加入條件組」下拉選要放進哪一組。標示「排除：」的規則不分條件組，一律從最終結果中全域扣除。</div>

        <h4>排除已結案</h4>
        <div class="tip">勾選「排除已結案」的當下，會把此刻已符合規則、且狀態已是「已結案」的BOM做成一份永久排除清單；<b>之後才結案</b>的BOM不會被自動追加排除，仍會留在清單上。若之後新增了規則、想把新規則比對到的舊結案BOM也一併排除，可按「重新整理快照」手動再抓一次（先前已排除的不會被移除）。取消勾選會清空排除清單，之後重新勾選會以「重新勾選當下」的狀態重新快照。</div>

        <h4>重要行為／常見疑問</h4>
        <ul>
          <li>已結案的BOM進度一律顯示100%、目前製程顯示「結案」，避免舊資料進度算不準造成誤解。</li>
          <li>「進行中」的BOM整列會有淡橘底色，方便一眼找出還需要追蹤的項目。</li>
          <li>清單可拖曳勾選欄框選多筆，批次設定通知。</li>
          <li>轉CSV／轉PDF一律匯出符合目前篩選條件的「全部」資料，不是只匯出當頁。</li>
        </ul>

        <h4>設定入口</h4>
        <p>群組管理／規則／通知／分享皆在「管理此群組」跳窗內設定，僅群組擁有者或管理員可修改；被分享者僅能檢視清單。</p>

        <h4>權限角色</h4>
        <p>本頁採全站二元權限：功能碼 <b>bom_track</b>。尚未取得權限者會看到「請先申請權限」畫面，請聯絡管理者至「使用者權限管理」頁面指派角色。</p>
      </div>
      <div class="modal-footer"><button type="button" class="btn btn-default" data-dismiss="modal">我知道了</button></div>
    </div>
  </div>
</div>
<?php endif; ?>

<script src="../../resource/js/jquery.min.js"></script>
<script src="../../resource/js/bootstrap.min.js"></script>
<script src="https://code.jquery.com/ui/1.12.1/jquery-ui.js"></script>
<script src="../../resource/js/select2.min.js"></script>
<script src="../../resource/js/custom.min.js"></script>
<script src="../../resource/js/pdfmake.min.js"></script>
<?php if ($has_access): ?>
<script>
var API = '../../src/store/BomTrack_API.php';
var state = { groupId: null, isOwnerOrAdmin: false, page: 1, pageSize: 10, total: 0, rows: [], selected: {} };
var bnBoms = []; // 目前通知設定Modal操作的BOM清單(單筆=1個, 批次=多個)
var groupScopeId = null;

function apiGet(action, params) {
    return $.get(API, Object.assign({ action: action }, params || {}), null, 'json');
}
function apiPost(action, params) {
    return $.post(API, Object.assign({ action: action }, params || {}), null, 'json');
}

// ── 群組載入 ──────────────────────────────────────────────────────────
function loadGroups(selectId) {
    apiGet('list_groups').done(function (res) {
        if (!res.success) { alert(res.message || '讀取群組失敗'); return; }
        var $sel = $('#groupSelect').empty();
        (res.data || []).forEach(function (g) {
            var label = g.group_name + (g.relation === 'shared' ? '（' + g.owner_name + '分享）' : '');
            $sel.append($('<option>').val(g.group_id).text(label).data('relation', g.relation));
        });
        if (selectId) $sel.val(selectId);
        onGroupChange();
    });
}

function currentGroupOption() {
    return $('#groupSelect option:selected');
}

function onGroupChange() {
    var gid = $('#groupSelect').val();
    state.groupId = gid ? parseInt(gid, 10) : null;
    var relation = currentGroupOption().data('relation');
    state.isOwnerOrAdmin = (relation === 'owner');
    $('#btnManageGroup, #btnDeleteGroup').prop('disabled', !state.groupId);
    $('#groupOwnerBadge').text(relation === 'shared' ? '（他人分享給你，僅可檢視）' : '');
    $('#bulkNotifyBar').toggle(state.isOwnerOrAdmin);
    $('#bomTrackTable').toggleClass('hide-select-col', !state.isOwnerOrAdmin);
    state.page = 1;
    if (state.groupId) loadMatchedList(); else { renderRows([]); updateStats(0, 0, 0); }
}

$('#groupSelect').on('change', onGroupChange);

$('#btnNewGroup').on('click', function () {
    var name = prompt('請輸入新群組名稱：');
    if (!name) return;
    apiPost('save_group', { group_name: name }).done(function (res) {
        if (!res.success) { alert(res.message || '新增失敗'); return; }
        loadGroups(res.group_id);
    });
});

$('#btnDeleteGroup').on('click', function () {
    if (!state.groupId) return;
    if (!confirm('確認刪除此群組？（規則/通知/分享設定會一併刪除）')) return;
    apiPost('delete_group', { group_id: state.groupId }).done(function (res) {
        if (!res.success) { alert(res.message || '刪除失敗'); return; }
        loadGroups();
    });
});

// ── 匹配清單 ──────────────────────────────────────────────────────────
function updateStats(total, open, closed) {
    $('#statTotal').text(total);
    $('#statOpen').text(open);
    $('#statClosed').text(closed);
}

// light=true：規則編輯中即時預覽用，只重抓第一頁列表(不跑COUNT，快)，不更新統計卡/頁碼；
// 真正的統計數字與總頁數留到使用者實際篩選/翻頁時才算(那幾個操作仍呼叫 loadMatchedList() 不帶參數)
function loadMatchedList(light) {
    if (!state.groupId) return;
    var params = {
        group_id: state.groupId, page: state.page, pageSize: state.pageSize,
        bom_kw: $('#filterBom').val(), status: $('#filterStatus').val()
    };
    if (light) params.skip_count = 1;
    apiGet('get_matched_boms', params).done(function (res) {
        if (!res.success) { $('#bomTrackTbody').html('<tr><td colspan="10" class="text-center text-muted">' + (res.message || '讀取失敗') + '</td></tr>'); if (!light) updateStats(0, 0, 0); return; }
        state.rows = res.data || [];
        renderRows(state.rows);
        if (!light) {
            state.total = res.total || 0;
            updateStats(res.total || 0, res.total_open || 0, res.total_closed || 0);
            var maxPage = Math.max(1, Math.ceil(state.total / state.pageSize));
            $('#pageInfo').text('共 ' + state.total + ' 筆，第 ' + state.page + '/' + maxPage + ' 頁');
            $('#pageNum').text(state.page);
        }
    });
}

// 料號/BOM 圖面預覽跳窗，比照 views/pm/OreadyReply_ForPm_BaseOfTime2.php 的 openBomFiles()
function openBomFiles(bom, did) {
    if (!bom && !did) return;
    var w = screen.availWidth, h = screen.availHeight;
    var pw = Math.min(1400, Math.round(w * 0.85));
    var ph = Math.min(900, Math.round(h * 0.88));
    var pl = Math.round((w - pw) / 2);
    var pt = Math.round((h - ph) / 2);
    var url = did
        ? 'part_viewer.php?d_id=' + encodeURIComponent(did) + (bom ? '&bom=' + encodeURIComponent(bom) : '')
        : 'bom_viewer.php?bom=' + encodeURIComponent(bom);
    var winName = did ? ('part_dv_' + did) : ('bom_viewer_' + bom);
    window.open(url, winName, 'width=' + pw + ',height=' + ph + ',left=' + pl + ',top=' + pt + ',resizable=yes,scrollbars=yes,menubar=no,toolbar=no,location=no,status=no');
}

// 已結案的BOM一律顯示100%：現場常有急件跳關、生管忘記在系統按移轉的情況，
// 導致bom_summary算出的progress_pct結案了還不到100%，顯示層統一用「已結案=100%」校正，比較符合實際狀況
function fmtProgress(r) {
    if (r.processing_state == 1) return '100%';
    return r.progress_pct != null ? (parseFloat(r.progress_pct) + '%') : '—';
}
// 已結案：製程一律顯示「結案」(此時目前製程對追蹤已無意義)；進行中才顯示實際製程。empty=空值時的替代字
function fmtProcess(r, empty) {
    if (r.processing_state == 1) return '結案';
    return r.latest_process_name || (empty != null ? empty : '');
}

function renderRows(rows) {
    var $tb = $('#bomTrackTbody').empty();
    state.selected = {}; // 換頁/重新整理列表時，勾選狀態不跨頁保留，避免使用者誤以為選到看不見的資料
    updateBulkNotifyButton();
    $('#bomSelectAll').prop('checked', false);
    if (!rows.length) { $tb.html('<tr><td colspan="10" class="text-center text-muted">沒有符合規則的BOM</td></tr>'); return; }
    rows.forEach(function (r) {
        var statusText = r.processing_state == 1 ? '已結案' : '進行中';
        var progress = fmtProgress(r);
        var tr = $('<tr>');
        if (r.processing_state != 1) tr.addClass('row-open'); // 進行中整列淡橘底色
        var $cb = $('<input type="checkbox">').on('change', function () {
            if (this.checked) state.selected[r.bom] = true; else delete state.selected[r.bom];
            updateBulkNotifyButton();
        });
        tr.append($('<td class="col-select">').append($cb));
        tr.append($('<td>').text(r.bom));
        tr.append($('<td>').text(r.sales_name || '—'));
        var $partTd = $('<td>');
        var $partLink = $('<a href="#" style="color:#2980b9;">').text(r.d_id || '—')
            .on('click', function (e) { e.preventDefault(); openBomFiles(r.bom, r.d_id); });
        $partTd.append($partLink);
        tr.append($partTd);
        tr.append($('<td>').text(r.Client_Name || ''));
        var $dueTd = $('<td>').text(r.Delivery_date || '無交期');
        if (r.order_no) $dueTd.append($('<div style="font-size:11px;color:#888;">').text('單號：' + r.order_no));
        tr.append($dueTd);
        tr.append($('<td>').text(fmtProcess(r, '—')));
        tr.append($('<td>').text(progress));
        tr.append($('<td>').text(statusText));
        var opTd = $('<td>');
        var btnTl = $('<button class="btn btn-xs btn-default"><i class="fa fa-clock-o"></i> 時間軸</button>')
            .on('click', function () { openTimeline(r.bom); });
        opTd.append(btnTl);
        if (state.isOwnerOrAdmin) {
            var btnNotify = $('<button class="btn btn-xs btn-warning" style="margin-left:4px;"><i class="fa fa-bell"></i> 通知</button>')
                .on('click', function () { openBomNotify(r.bom); });
            opTd.append(btnNotify);
        }
        tr.append(opTd);
        $tb.append(tr);
    });
}

$('#btnFilter').on('click', function () { state.page = 1; loadMatchedList(); });
$('#pageSizeSelect').on('change', function () { state.pageSize = parseInt($(this).val(), 10); state.page = 1; loadMatchedList(); });
$('#btnPrevPage').on('click', function () { if (state.page > 1) { state.page--; loadMatchedList(); } });
$('#btnNextPage').on('click', function () {
    var maxPage = Math.max(1, Math.ceil(state.total / state.pageSize));
    if (state.page < maxPage) { state.page++; loadMatchedList(); }
});

// ── 匯出（後端抓全量，不只當頁）──────────────────────────────────────
function fetchAllMatched(cb) {
    apiGet('get_matched_boms', {
        group_id: state.groupId, page: 1, pageSize: 100000,
        bom_kw: $('#filterBom').val(), status: $('#filterStatus').val()
    }).done(function (res) { cb(res.success ? (res.data || []) : []); });
}

$('#btnExportCsv').on('click', function () {
    if (!state.groupId) { alert('請先選擇群組'); return; }
    fetchAllMatched(function (rows) {
        var lines = ['BOM,負責業務,料號,客戶,交期,訂單編號,目前製程,進度,狀態'];
        rows.forEach(function (r) {
            var statusText = r.processing_state == 1 ? '已結案' : '進行中';
            var cells = [r.bom, r.sales_name || '', r.d_id || '', r.Client_Name || '', r.Delivery_date || '無交期', r.order_no || '', fmtProcess(r), fmtProgress(r), statusText];
            lines.push(cells.map(function (c) { return '"' + String(c).replace(/"/g, '""') + '"'; }).join(','));
        });
        var blob = new Blob(["﻿" + lines.join("\n")], { type: 'text/csv;charset=utf-8;' });
        var a = document.createElement('a');
        a.href = URL.createObjectURL(blob);
        a.download = 'bom_tracking_' + state.groupId + '.csv';
        a.click();
    });
});

$('#btnExportPdf').on('click', function () {
    if (!state.groupId) { alert('請先選擇群組'); return; }
    fetchAllMatched(function (rows) {
        var body = [['BOM', '負責業務', '料號', '客戶', '交期', '訂單編號', '目前製程', '進度', '狀態']];
        rows.forEach(function (r) {
            var statusText = r.processing_state == 1 ? '已結案' : '進行中';
            body.push([r.bom, r.sales_name || '', r.d_id || '', r.Client_Name || '', r.Delivery_date || '無交期', r.order_no || '', fmtProcess(r), fmtProgress(r), statusText]);
        });
        pdfMake.createPdf({
            pageOrientation: 'landscape',
            content: [{ text: 'BOM 追蹤清單', fontSize: 14, margin: [0, 0, 0, 10] }, { table: { headerRows: 1, body: body } }],
            defaultStyle: { fontSize: 9 }
        }).download('bom_tracking_' + state.groupId + '.pdf');
    });
});

// ── 時間軸 ────────────────────────────────────────────────────────────
// 時間欄位若剛好是 00:00:00（純日期、無實際時分秒資料）就只顯示日期，避免顯示無意義的 00:00:00
function fmtTimelineTime(t) {
    if (!t) return '';
    var s = String(t);
    return s.endsWith(' 00:00:00') ? s.slice(0, -9) : s;
}
function openTimeline(bom) {
    $('#tlBomNo').text(bom);
    $('#tlBody').html('<div class="text-muted">載入中...</div>');
    $('#timelineModal').modal('show');
    apiGet('get_bom_timeline', { bom: bom }).done(function (res) {
        if (!res.success) { $('#tlBody').html('<div class="text-muted">' + (res.message || '讀取失敗') + '</div>'); return; }
        if (!res.data.length) { $('#tlBody').html('<div class="text-muted">尚無紀錄</div>'); return; }
        // 用固定欄寬的表格排版(時間/類型/說明各自對齊)，避免不同長度的文字讓版面歪掉
        var $table = $('<table style="width:100%;border-collapse:collapse;font-size:12px;">');
        $table.append(
            '<colgroup><col style="width:140px;"><col style="width:70px;"><col></colgroup>' +
            '<thead><tr style="background:#f5f7fa;">' +
            '<th style="text-align:left;padding:5px 8px;border-bottom:2px solid #e5e5e5;">時間</th>' +
            '<th style="text-align:left;padding:5px 8px;border-bottom:2px solid #e5e5e5;">類型</th>' +
            '<th style="text-align:left;padding:5px 8px;border-bottom:2px solid #e5e5e5;">說明</th>' +
            '</tr></thead>'
        );
        var $tbody = $('<tbody>');
        res.data.forEach(function (e) {
            // QC列底色跟其他事件不同；有NG(驗退)的QC列再用更醒目的紅色標示提醒
            var rowBg = e.category === 'qc' ? '#eef6fb' : '';
            if (e.is_ng) rowBg = '#fdecea';
            var $tr = $('<tr>' ).css('background', rowBg || '');
            var noteColor = e.is_ng ? '#c0392b' : '#666';
            var noteWeight = e.is_ng ? 'font-weight:700;' : '';
            $tr.append($('<td style="padding:5px 8px;border-bottom:1px solid #eee;vertical-align:top;white-space:nowrap;">').text(fmtTimelineTime(e.time)));
            var $typeTd = $('<td style="padding:5px 8px;border-bottom:1px solid #eee;vertical-align:top;white-space:nowrap;">').text(e.type);
            if (e.is_ng) $typeTd.prepend($('<i class="fa fa-exclamation-triangle" style="color:#c0392b;margin-right:4px;"></i>'));
            $tr.append($typeTd);
            $tr.append($('<td style="padding:5px 8px;border-bottom:1px solid #eee;vertical-align:top;color:' + noteColor + ';' + noteWeight + '">').text(e.note || ''));
            $tbody.append($tr);
        });
        $table.append($tbody);
        $('#tlBody').empty().append($table);
    });
}

// ── 管理群組 Modal ───────────────────────────────────────────────────
$('#btnManageGroup').on('click', function () {
    if (!state.groupId) return;
    $('#mgGroupName').text(currentGroupOption().text());
    loadRules();
    loadGroupSettings();
    loadGroupNotifyState();
    loadShares();
    $('#newRuleType').val('part');
    renderRuleValueArea(); // 下拉預設值不會觸發 change 事件，開啟 modal 時要手動渲染一次
    $('#manageGroupModal').modal('show');
});

// ── 排除已結案（以開關開啟當下的快照為準）───────────────────────────
function loadGroupSettings() {
    apiGet('get_group_settings', { group_id: state.groupId }).done(function (res) {
        if (!res.success) return;
        $('#excludeClosedToggle').prop('checked', !!res.exclude_closed_snapshot);
        $('#btnRefreshSnapshot').toggle(!!res.exclude_closed_snapshot);
        $('#closedSnapInfo').text(res.exclude_closed_snapshot ? ('目前已永久排除 ' + res.snapshot_count + ' 筆已結案BOM（設定啟用當下的狀態）') : '');
    });
}
$('#excludeClosedToggle').on('change', function () {
    var $cb = $(this);
    var enable = $cb.is(':checked');
    apiPost('toggle_exclude_closed', { group_id: state.groupId, enable: enable ? 1 : 0 }).done(function (res) {
        if (!res.success) { alert(res.message || '設定失敗'); $cb.prop('checked', !enable); return; }
        $('#btnRefreshSnapshot').toggle(enable);
        $('#closedSnapInfo').text(enable ? ('目前已永久排除 ' + res.snapshot_count + ' 筆已結案BOM（設定啟用當下的狀態）') : '');
        state.page = 1; loadMatchedList();
    });
});
$('#btnRefreshSnapshot').on('click', function () {
    if (!confirm('重新整理快照：會把「現在」符合規則且已結案的BOM也一併加入永久排除清單（先前已排除的不會恢復顯示）。確定要重新整理嗎？')) return;
    apiPost('refresh_closed_snapshot', { group_id: state.groupId }).done(function (res) {
        if (!res.success) { alert(res.message || '操作失敗'); return; }
        $('#closedSnapInfo').text('目前已永久排除 ' + res.snapshot_count + ' 筆已結案BOM（本次新增 ' + res.added + ' 筆）');
        state.page = 1; loadMatchedList();
    });
});

// ── 條件組（AND/OR混用：組內AND、組間OR）──────────────────────────────
function loadCondGroupSelect(condGroups, selectAfterId) {
    var $sel = $('#newRuleCondGroup').empty();
    condGroups.forEach(function (g) { $sel.append($('<option>').val(g.cond_group_id).text(g.label)); });
    if (selectAfterId != null) $sel.val(selectAfterId);
}
$('#btnAddCondGroup').on('click', function () {
    var label = prompt('請輸入條件組名稱（可留空自動命名）：');
    if (label === null) return;
    apiPost('add_cond_group', { group_id: state.groupId, label: label }).done(function (res) {
        if (!res.success) { alert(res.message || '新增條件組失敗'); return; }
        loadRules(res.cond_group_id);
    });
});

function ruleTypeLabel(t) {
    return { part: '料號', bom: 'BOM編號', customer: '客戶', sales: '業務', due_range: '交期區間' }[t] || t;
}
function ruleValueText(r) {
    if (r.rule_type === 'due_range') {
        if (r.due_range_type === 'fixed') return r.due_from + ' ~ ' + r.due_to;
        return '今天' + (r.due_relative_from_days >= 0 ? '+' : '') + r.due_relative_from_days + '天 ~ 今天' + (r.due_relative_to_days >= 0 ? '+' : '') + r.due_relative_to_days + '天';
    }
    return r.rule_value_label || r.rule_value;
}

function renderRuleRow(r) {
    var row = $('<div class="rule-row">');
    row.append($('<span class="label ' + (r.is_exclude == 1 ? 'label-danger' : 'label-primary') + '">').text((r.is_exclude == 1 ? '排除：' : '') + ruleTypeLabel(r.rule_type)));
    row.append($('<span>').text(ruleValueText(r)));
    row.append($('<span class="rule-del"><i class="fa fa-times-circle"></i></span>').on('click', function () {
        if (!confirm('刪除此規則？')) return;
        apiPost('delete_rule', { rule_id: r.rule_id }).done(function () { state.page = 1; loadRules(); loadMatchedList(true); });
    }));
    return row;
}

// selectCondGroupId：新增條件組後，讓「加入條件組」下拉自動選到剛建立的那組，方便使用者接著往裡面加規則
function loadRules(selectCondGroupId) {
    $.when(apiGet('list_cond_groups', { group_id: state.groupId }), apiGet('get_rules', { group_id: state.groupId })).done(function (cgRes, ruleRes) {
        var condGroups = (cgRes[0].success && cgRes[0].data) ? cgRes[0].data : [{ cond_group_id: 0, label: '條件組1' }];
        loadCondGroupSelect(condGroups, selectCondGroupId);

        var rules = (ruleRes[0].success && ruleRes[0].data) ? ruleRes[0].data : [];
        var includeRules = rules.filter(function (r) { return r.is_exclude != 1; });
        var excludeRules = rules.filter(function (r) { return r.is_exclude == 1; });

        var $box = $('#ruleList').empty();
        if (!includeRules.length) {
            $box.append('<div class="text-muted" style="margin-bottom:8px;">尚未設定任何納入規則（至少要有一條，否則不會匹配任何BOM）</div>');
        } else {
            var byGroup = {};
            includeRules.forEach(function (r) {
                var cg = r.cond_group_id || 0;
                if (!byGroup[cg]) byGroup[cg] = [];
                byGroup[cg].push(r);
            });
            var groupIds = Object.keys(byGroup);
            groupIds.forEach(function (cg, idx) {
                if (idx > 0) $box.append('<div style="text-align:center;color:#e67e22;font-weight:700;font-size:12px;margin:6px 0;">— 或 (OR) —</div>');
                var found = condGroups.find(function (g) { return String(g.cond_group_id) === String(cg); });
                var gLabel = found ? found.label : '條件組';
                var $gBox = $('<div style="border:1px solid #eee;border-radius:6px;padding:8px;background:#FAFBFE;">');
                var $gHead = $('<div style="display:flex;justify-content:space-between;align-items:center;margin-bottom:4px;">');
                $gHead.append($('<span style="font-weight:700;font-size:12px;color:#555;">').text(gLabel + '（組內為「且 AND」）'));
                if (cg != 0 && groupIds.length > 1) {
                    $gHead.append($('<span style="color:#d9534f;cursor:pointer;font-size:12px;">刪除此條件組</span>').on('click', function () {
                        if (!confirm('刪除「' + gLabel + '」條件組？（組內規則會一併刪除）')) return;
                        apiPost('delete_cond_group', { cond_group_id: cg }).done(function (res) {
                            if (!res.success) { alert(res.message || '刪除失敗'); return; }
                            loadRules(); loadMatchedList(true);
                        });
                    }));
                }
                $gBox.append($gHead);
                byGroup[cg].forEach(function (r) { $gBox.append(renderRuleRow(r)); });
                $box.append($gBox);
            });
        }

        if (excludeRules.length) {
            var $exBox = $('<div style="margin-top:10px;padding:8px;border-radius:6px;background:#FDECEA;">');
            $exBox.append('<div style="font-weight:700;font-size:12px;color:#c0392b;margin-bottom:4px;">全域排除規則（不分條件組，一律從結果中扣除）</div>');
            excludeRules.forEach(function (r) { $exBox.append(renderRuleRow(r)); });
            $box.append($exBox);
        }
    });
}

function renderRuleValueArea() {
    var type = $('#newRuleType').val();
    var $area = $('#ruleValueArea').empty();
    if (type === 'due_range') {
        $area.html(
            '<div class="form-group"><label><input type="radio" name="dueType" value="fixed" checked> 固定日期</label> ' +
            '<label style="margin-left:15px;"><input type="radio" name="dueType" value="relative"> 相對區間</label></div>' +
            '<div id="dueFixedBox"><input type="text" id="dueFrom" class="form-control datepicker" placeholder="起(含)" style="width:150px;display:inline-block;"> ~ ' +
            '<input type="text" id="dueTo" class="form-control datepicker" placeholder="迄(含)" style="width:150px;display:inline-block;"></div>' +
            '<div id="dueRelativeBox" style="display:none;">今天 <input type="number" id="dueRelFrom" class="form-control" style="width:80px;display:inline-block;" value="-7"> 天 ~ 今天 ' +
            '<input type="number" id="dueRelTo" class="form-control" style="width:80px;display:inline-block;" value="30"> 天</div>' +
            '<div class="checkbox" style="margin-top:8px;"><label><input type="checkbox" id="dueIsExclude"> 設為排除規則（從已納入的BOM中扣除此區間）</label></div>' +
            '<button class="btn btn-primary btn-sm" id="btnAddDueRule" style="margin-top:6px;">加入規則</button>'
        );
        $area.find('.datepicker').datepicker({ dateFormat: 'yy-mm-dd' });
        $area.find('input[name=dueType]').on('change', function () {
            var v = $('input[name=dueType]:checked').val();
            $('#dueFixedBox').toggle(v === 'fixed');
            $('#dueRelativeBox').toggle(v === 'relative');
        });
        $('#btnAddDueRule').on('click', function () {
            var v = $('input[name=dueType]:checked').val();
            var params = { group_id: state.groupId, rule_type: 'due_range', due_range_type: v, is_exclude: $('#dueIsExclude').is(':checked') ? 1 : 0, cond_group_id: $('#newRuleCondGroup').val() };
            if (v === 'fixed') { params.due_from = $('#dueFrom').val(); params.due_to = $('#dueTo').val(); if (!params.due_from || !params.due_to) { alert('請選擇日期'); return; } }
            else { params.due_relative_from_days = $('#dueRelFrom').val(); params.due_relative_to_days = $('#dueRelTo').val(); }
            apiPost('save_rule', params).done(function (res) {
                if (!res.success) { alert(res.message || '新增失敗'); return; }
                state.page = 1; loadRules(); loadMatchedList(true);
            });
        });
        return;
    }

    var searchAction = { part: 'search_parts', bom: 'search_boms', customer: 'search_customers', sales: 'search_sales_users' }[type];
    var idField = { part: 'd_id', bom: 'bom', customer: 'customer_id', sales: 'id' }[type];
    var labelFn = {
        part: function (o) { return o.D_Setting_Id + (o.Drawing_No ? '（' + o.Drawing_No + '）' : ''); },
        bom: function (o) { return o.bom + '（' + (o.Client_Name || '') + '）'; },
        customer: function (o) { return o.customer + '（' + o.customer_id + '）'; },
        sales: function (o) { return o.user_cname + '（' + o.user_uname + '）'; }
    }[type];
    var patternHint = {
        part: '輸入料號的一部分（會比對料號代號與圖號，含此文字即算符合）',
        bom: '輸入BOM編號的一部分（例如 B253 會比對出所有含 B253 的BOM）',
        customer: '輸入客戶名稱的一部分',
        sales: '輸入業務姓名的一部分'
    }[type];

    $area.html(
        '<div class="form-group">' +
        '  <label><input type="radio" name="matchMode" value="exact" checked> 精確比對（從清單挑選，可多選）</label>' +
        '  <label style="margin-left:15px;"><input type="radio" name="matchMode" value="pattern"> 模糊比對（輸入文字，包含即符合）</label>' +
        '</div>' +
        '<div class="checkbox"><label><input type="checkbox" id="ruleIsExclude"> 設為排除規則（從已納入的BOM中扣除符合此條件的）</label></div>' +
        '<div id="exactModeBox">' +
        // 已選清單放在搜尋框「上方」：勾選後立刻看得到累積結果；搜尋建議清單(.ac-list)是絕對定位往下展開，
        // 若把chips放在輸入框下方會被清單蓋住(使用者以為沒選到)，故一律置頂顯示。
        '  <div id="ruleSelectedChips" style="margin-bottom:8px;"></div>' +
        '  <div class="ac-box"><input type="text" id="ruleValueKw" class="form-control" placeholder="輸入關鍵字搜尋..."><div class="ac-list" id="ruleValueSug"></div></div>' +
        '  <button class="btn btn-primary btn-sm" id="btnAddSelected" style="margin-top:8px;" disabled>加入已選（<span id="selectedCount">0</span> 筆）</button>' +
        '</div>' +
        '<div id="patternModeBox" style="display:none;">' +
        '  <p style="color:#888;font-size:12px;margin-bottom:4px;">' + patternHint + '</p>' +
        '  <input type="text" id="rulePatternVal" class="form-control" style="width:260px;display:inline-block;" placeholder="輸入要比對的文字">' +
        '  <button class="btn btn-primary btn-sm" id="btnAddPattern">加入規則</button>' +
        '</div>'
    );

    $area.find('input[name=matchMode]').on('change', function () {
        var v = $('input[name=matchMode]:checked').val();
        $('#exactModeBox').toggle(v === 'exact');
        $('#patternModeBox').toggle(v === 'pattern');
    });

    // 精確比對：搜尋結果用勾選框多選，累積後一次加入
    var selected = {}; // id -> label
    function renderSelectedChips() {
        var $chips = $('#ruleSelectedChips').empty();
        var ids = Object.keys(selected);
        ids.forEach(function (id) {
            var chip = $('<span class="label label-default" style="margin-right:6px;font-size:12px;">').text(selected[id]);
            chip.append($('<span style="margin-left:5px;cursor:pointer;">×</span>').on('click', function () {
                delete selected[id];
                renderSelectedChips();
            }));
            $chips.append(chip);
        });
        $('#selectedCount').text(ids.length);
        $('#btnAddSelected').prop('disabled', !ids.length);
    }
    var timer = null;
    $('#ruleValueKw').on('input', function () {
        clearTimeout(timer);
        var kw = $(this).val();
        timer = setTimeout(function () {
            apiGet(searchAction, { kw: kw }).done(function (res) {
                var $sug = $('#ruleValueSug').empty();
                (res.data || []).forEach(function (o) {
                    var id = String(o[idField]);
                    var label = labelFn(o);
                    var $item = $('<div class="ac-item">');
                    var $cb = $('<input type="checkbox" style="margin-right:6px;">').prop('checked', !!selected[id]);
                    $item.append($cb).append(document.createTextNode(label));
                    $item.on('click', function (e) {
                        if (e.target !== $cb[0]) $cb.prop('checked', !$cb.is(':checked'));
                        if ($cb.is(':checked')) selected[id] = label; else delete selected[id];
                        renderSelectedChips();
                    });
                    $sug.append($item);
                });
                $sug.toggle(!!res.data.length);
            });
        }, 250);
    });
    $('#btnAddSelected').on('click', function () {
        var ids = Object.keys(selected);
        if (!ids.length) return;
        apiPost('save_rule', {
            group_id: state.groupId, rule_type: type, match_mode: 'exact',
            is_exclude: $('#ruleIsExclude').is(':checked') ? 1 : 0,
            cond_group_id: $('#newRuleCondGroup').val(),
            rule_values: JSON.stringify(ids)
        }).done(function (res) {
            if (!res.success) { alert(res.message || '新增失敗'); return; }
            selected = {}; renderSelectedChips();
            $('#ruleValueKw').val(''); $('#ruleValueSug').empty().hide();
            state.page = 1; loadRules(); loadMatchedList(true);
        });
    });

    $('#btnAddPattern').on('click', function () {
        var val = $('#rulePatternVal').val().trim();
        if (!val) { alert('請輸入要比對的文字'); return; }
        apiPost('save_rule', {
            group_id: state.groupId, rule_type: type, match_mode: 'pattern',
            is_exclude: $('#ruleIsExclude').is(':checked') ? 1 : 0,
            cond_group_id: $('#newRuleCondGroup').val(),
            rule_value: val
        }).done(function (res) {
            if (!res.success) { alert(res.message || '新增失敗'); return; }
            $('#rulePatternVal').val('');
            state.page = 1; loadRules(); loadMatchedList(true);
        });
    });
}
$('#newRuleType').on('change', renderRuleValueArea);

// 點搜尋框以外的地方就收起建議清單：清單絕對定位會蓋住下方「加入已選」按鈕，不收起使用者按不到。
$(document).on('mousedown', function (e) {
    if (!$(e.target).closest('.ac-box').length) $('.ac-list').hide();
});

// ── select2 初始化（部門/人員多選，比照 views/liveEvent/createEvent.php）──
var $groupSubSelect = $('#groupSubscriberSelect').select2({ width: '100%', placeholder: '選擇通知對象（部門 / 人員，可多選）', closeOnSelect: false, allowClear: true });
var $bnSubSelect = $('#bnSubscriberSelect').select2({ width: '100%', placeholder: '選擇通知對象（部門 / 人員，可多選）', closeOnSelect: false, allowClear: true });
var $shareSelect = $('#shareSelect').select2({ width: '100%', placeholder: '選擇分享對象（部門 / 人員，可多選）', closeOnSelect: false, allowClear: true });

// ── 通知（群組層級）─────────────────────────────────────────────────
// _syncing旗標：程式化設定select2值時避免又觸發自己的change存檔handler(否則load會變成一次多餘的save)
var _groupSubSyncing = false, _bnSubSyncing = false, _shareSyncing = false;

function loadGroupNotifyState() {
    apiGet('get_notify_scopes', { group_id: state.groupId }).done(function (res) {
        var groupScope = (res.data || []).find(function (s) { return s.scope_type === 'group'; });
        groupScopeId = groupScope ? groupScope.scope_id : null;
        $('#notifyGroupToggle').prop('checked', !!groupScopeId);
        $('#groupSubscriberBox').toggle(!!groupScopeId);
        _groupSubSyncing = true;
        if (groupScopeId) {
            apiGet('get_subscribers', { scope_id: groupScopeId }).done(function (r2) {
                $groupSubSelect.val((r2.data || []).map(function (x) { return x.code; })).trigger('change');
                _groupSubSyncing = false;
            });
        } else {
            $groupSubSelect.val([]).trigger('change');
            _groupSubSyncing = false;
        }
    });
}
$('#notifyGroupToggle').on('change', function () {
    var enable = $(this).is(':checked');
    apiPost('toggle_notify_scope', { group_id: state.groupId, scope_type: 'group', enable: enable ? 1 : 0 }).done(function (res) {
        if (!res.success) { alert(res.message || '設定失敗'); return; }
        groupScopeId = res.scope_id || null;
        $('#groupSubscriberBox').toggle(enable);
        if (!enable) { _groupSubSyncing = true; $groupSubSelect.val([]).trigger('change'); _groupSubSyncing = false; }
    });
});
$groupSubSelect.on('change', function () {
    if (_groupSubSyncing || !groupScopeId) return;
    apiPost('save_subscribers', { scope_id: groupScopeId, codes: JSON.stringify($groupSubSelect.val() || []) });
});

// ── 通知（單筆／批次BOM）─────────────────────────────────────────────
// 單筆(bnBoms.length===1)：先載入該BOM既有設定方便微調；批次：不預載(各筆原設定可能不同)，
// 從空白開始設定，按「套用設定」後統一覆蓋這幾筆各自的通知範圍/對象。
function openBomNotifyMulti(boms) {
    bnBoms = boms.slice();
    $('#bnBatchHint').toggle(boms.length > 1);
    $('#bnBomNo').text(boms.length > 1 ? ('已選 ' + boms.length + ' 筆') : boms[0]);
    if (boms.length === 1) {
        apiGet('get_notify_scopes', { group_id: state.groupId }).done(function (res) {
            var scope = (res.data || []).find(function (s) { return s.scope_type === 'bom' && s.scope_bom === boms[0]; });
            $('#bnToggle').prop('checked', !!scope);
            $('#bnSubBox').toggle(!!scope);
            _bnSubSyncing = true;
            if (scope) {
                apiGet('get_subscribers', { scope_id: scope.scope_id }).done(function (r2) {
                    $bnSubSelect.val((r2.data || []).map(function (x) { return x.code; })).trigger('change');
                    _bnSubSyncing = false;
                });
            } else {
                $bnSubSelect.val([]).trigger('change');
                _bnSubSyncing = false;
            }
            $('#bomNotifyModal').modal('show');
        });
    } else {
        $('#bnToggle').prop('checked', false);
        $('#bnSubBox').hide();
        _bnSubSyncing = true; $bnSubSelect.val([]).trigger('change'); _bnSubSyncing = false;
        $('#bomNotifyModal').modal('show');
    }
}
function openBomNotify(bom) { openBomNotifyMulti([bom]); }

$('#bnToggle').on('change', function () {
    $('#bnSubBox').toggle($(this).is(':checked'));
});

$('#bnApply').on('click', function () {
    if (!bnBoms.length) return;
    var enable = $('#bnToggle').is(':checked') ? 1 : 0;
    var codes = JSON.stringify($bnSubSelect.val() || []);
    var $btn = $(this).prop('disabled', true).text('套用中...');
    var reqs = bnBoms.map(function (bom) {
        return apiPost('toggle_notify_scope', { group_id: state.groupId, scope_type: 'bom', scope_bom: bom, enable: enable }).then(function (res) {
            if (res.success && enable && res.scope_id) {
                return apiPost('save_subscribers', { scope_id: res.scope_id, codes: codes });
            }
            return res;
        });
    });
    $.when.apply($, reqs).always(function () {
        $btn.prop('disabled', false).text('套用設定');
        $('#bomNotifyModal').modal('hide');
        alert('已套用通知設定（' + bnBoms.length + ' 筆）');
    });
});

// ── 列表多選批次通知 ─────────────────────────────────────────────────
function updateBulkNotifyButton() {
    var n = Object.keys(state.selected).length;
    $('#selBomCount').text(n);
    $('#btnBulkNotify').prop('disabled', n === 0);
}
$('#bomSelectAll').on('change', function () {
    var checked = $(this).is(':checked');
    $('#bomTrackTbody input[type=checkbox]').prop('checked', checked).trigger('change');
});
$('#btnBulkNotify').on('click', function () {
    var boms = Object.keys(state.selected);
    if (!boms.length) return;
    openBomNotifyMulti(boms);
});

// ── 框選（拖曳滑鼠畫框，框到的整列打勾）──────────────────────────────
(function () {
    var $wrap = $('#bomTableWrap');
    var dragging = false, startX = 0, startY = 0, $box = null;

    $wrap.on('mousedown', function (e) {
        if (!state.isOwnerOrAdmin) return;
        // 只從最左「勾選欄」開始拖曳才啟動框選；其餘欄位(交期/單號/料號等)保留原生選字，可反白複製。
        // 點在checkbox本身維持勾選行為。
        if (!$(e.target).closest('td.col-select, th.col-select').length) return;
        if ($(e.target).is('input')) return;
        dragging = true;
        var offset = $wrap.offset();
        startX = e.pageX - offset.left + $wrap.scrollLeft();
        startY = e.pageY - offset.top + $wrap.scrollTop();
        $box = $('<div class="rubber-select-box">').appendTo($wrap).css({ left: startX, top: startY, width: 0, height: 0 });
        e.preventDefault();
    });

    $(document).on('mousemove', function (e) {
        if (!dragging || !$box) return;
        var offset = $wrap.offset();
        var curX = e.pageX - offset.left + $wrap.scrollLeft();
        var curY = e.pageY - offset.top + $wrap.scrollTop();
        $box.css({
            left: Math.min(startX, curX), top: Math.min(startY, curY),
            width: Math.abs(curX - startX), height: Math.abs(curY - startY)
        });
    });

    $(document).on('mouseup', function () {
        if (!dragging) return;
        dragging = false;
        if ($box) {
            var boxRect = $box[0].getBoundingClientRect();
            $('#bomTrackTbody tr').each(function () {
                var rowRect = this.getBoundingClientRect();
                var intersects = !(rowRect.right < boxRect.left || rowRect.left > boxRect.right || rowRect.bottom < boxRect.top || rowRect.top > boxRect.bottom);
                if (intersects) $(this).find('input[type=checkbox]').prop('checked', true).trigger('change');
            });
            $box.remove();
            $box = null;
        }
    });
})();

// ── 分享 ─────────────────────────────────────────────────────────────
function loadShares() {
    apiGet('get_shares', { group_id: state.groupId }).done(function (res) {
        _shareSyncing = true;
        var codes = (res.data || []).map(function (x) { return x.code; });
        $shareSelect.val(codes).trigger('change');
        _shareSyncing = false;
        var noAccess = (res.data || []).filter(function (x) { return !x.has_access; }).map(function (x) { return x.label; });
        $('#shareNoAccessWarn').toggle(!!noAccess.length).text(noAccess.length ? ('以下分享對象目前尚無BOM追蹤權限，需先至「使用者權限管理」指派角色才看得到：' + noAccess.join('、')) : '');
    });
}
$shareSelect.on('change', function () {
    if (_shareSyncing || !state.groupId) return;
    apiPost('save_share', { group_id: state.groupId, codes: JSON.stringify($shareSelect.val() || []) }).done(function (res) {
        if (!res.success) { alert(res.message || '分享失敗'); return; }
        loadShares();
    });
});

$('#btnPageHelp').on('click', function () { $('#helpUseMask').modal('show'); });

$(document).ready(function () { loadGroups(); });
</script>
<?php endif; ?>
</body>
</html>
