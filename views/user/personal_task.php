<?php
/**
 * personal_task.php — 個人工作紀錄
 * 每人只看得到自己的紀錄（含管理者也看不到他人內容；隱私由 API 層 WHERE user_id 把關）。
 * 功能：狀態卡篩選(未完成/急件/暫停/已完成)、期限與急件紅底、進度流程(依序回報/拖移排序/範本)、
 *       提醒(期限與各進度可設幾天/幾小時前，走推播不寫入公告)、CSV/PDF匯出(含表頭表尾設定)、
 *       附件圖片(列表與編輯窗縮圖/點擊另開原圖，儲存路徑由管理員在「設定」統一設定)。
 * 後端：src/store/PersonalTask_API.php ｜ 視覺設計比照 views/pm/bom_tracking.php(源自 NewOrder_Track222)
 */
ini_set('display_errors', 1);
error_reporting(E_ALL);

session_start();

require_once __DIR__ . '/../../src/common/_config.php';
require_once __DIR__ . '/../../src/common/DBConnection.php';
require_once __DIR__ . '/../../src/common/role_features_helper.php';

if (!isset($_SESSION['userName'])) {
    $_SESSION['lastpage'] = "../../views/user/personal_task.php";
    header('Location: ../../index.php');
    exit;
}

$conn = new DBConnection();
$pdo2 = $conn->getPDO();
$my_id = (int)$_SESSION['id'];
$has_access = rf_has_module_role($pdo2, $my_id, 'personal_task');
?>
<!DOCTYPE html>
<html lang="zh-Hant">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>個人工作紀錄</title>
    <link href="../../resource/css/bootstrap.css" rel="stylesheet">
    <link href="../../resource/css/font-awesome.css" rel="stylesheet">
    <link href="../../resource/css/custom.css" rel="stylesheet">
    <style>
        /* 數字輸入框無上下增減按鈕（UI規範） */
        input[type=number]::-webkit-inner-spin-button,
        input[type=number]::-webkit-outer-spin-button { -webkit-appearance:none; margin:0; }
        input[type=number]{ -moz-appearance:textfield; appearance:textfield; }

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
            box-shadow:0 2px 5px rgba(0,0,0,.05); border-left:4px solid transparent; position:relative; overflow:hidden;
            cursor:pointer; transition:transform .1s, box-shadow .1s; }
        .stat-card:hover { transform:translateY(-2px); box-shadow:0 5px 15px rgba(0,0,0,.1); }
        /* 選取樣式比照 NewOrder_Track222：同卡色 3px 光圈 + 微放大 */
        .stat-card.active { background-color:#fff; transform:scale(1.02); z-index:1; }
        .stat-card.c-open.active   { box-shadow:0 0 0 3px #F39C12; }
        .stat-card.c-urgent.active { box-shadow:0 0 0 3px #E74C3C; }
        .stat-card.c-paused.active { box-shadow:0 0 0 3px #95A5A6; }
        .stat-card.c-done.active   { box-shadow:0 0 0 3px #1ABB9C; }
        .stat-card .stat-icon { position:absolute; right:15px; top:15px; font-size:32px; opacity:.1; }
        .stat-card .stat-value { font-size:24px; font-weight:800; color:var(--primary-color); }
        .stat-card .stat-label { font-size:12px; color:#888; font-weight:600; letter-spacing:1px; }
        .stat-card.c-open   { border-left-color:#F39C12; }
        .stat-card.c-urgent { border-left-color:#E74C3C; background:#fff5f5; }
        .stat-card.c-urgent .stat-value { color:#C0392B; }
        .stat-card.c-paused { border-left-color:#95A5A6; }
        .stat-card.c-done   { border-left-color:#1ABB9C; }

        .filter-bar { background:#fff; padding:10px; border-radius:8px; margin-bottom:15px;
            display:flex; gap:10px; align-items:center; flex-wrap:wrap; box-shadow:0 2px 5px rgba(0,0,0,.05); }
        .main-card { background:var(--card-bg); border-radius:8px; box-shadow:0 2px 5px rgba(0,0,0,.05); padding:15px; }
        .table-toolbar { display:flex; justify-content:space-between; align-items:center; margin-bottom:8px; flex-wrap:wrap; gap:8px; }

        table.ptask-table thead th { background:#F8F9FA; color:#555; font-weight:700; border-bottom:2px solid #E9ECEF;
            padding:10px 5px; font-size:13px; white-space:nowrap; }
        table.ptask-table tbody td { padding:8px 5px; vertical-align:middle; border-bottom:1px solid #F1F3F5; font-size:13px; }
        table.ptask-table tbody tr:hover { background:#FAFBFE; }
        /* 急件：紅底整列；已逾期再加深 */
        table.ptask-table tbody tr.row-urgent { background:#fdecea; }
        table.ptask-table tbody tr.row-urgent:hover { background:#fbdcd8; }
        table.ptask-table tbody tr.row-overdue { background:#f9cfc9; }
        table.ptask-table tbody tr.row-overdue:hover { background:#f6beb6; }

        /* 進度步驟：橫向 stepper（圓點＋連接線） */
        .step-flow { display:flex; align-items:flex-start; flex-wrap:wrap; row-gap:8px; }
        .pt-step { display:flex; flex-direction:column; align-items:center; min-width:66px; max-width:120px; }
        .pt-step-top { display:flex; align-items:center; width:100%; }
        .pt-line { flex:1; height:3px; background:#E3E7ED; border-radius:2px; min-width:10px; }
        .pt-line.done { background:#1ABB9C; }
        .pt-line.edge { visibility:hidden; }
        .pt-dot { width:22px; height:22px; border-radius:50%; display:flex; align-items:center; justify-content:center;
            font-size:11px; font-weight:700; flex:0 0 22px; margin:0 2px;
            background:#fff; border:2px solid #CBD3DC; color:#9AA5B1; }
        .pt-step.reached .pt-dot { background:#1ABB9C; border-color:#1ABB9C; color:#fff; }
        .pt-step.current .pt-dot { background:#fff; border-color:#F39C12; color:#F39C12; animation:ptPulse 1.8s infinite; }
        @keyframes ptPulse {
            0%   { box-shadow:0 0 0 0 rgba(243,156,18,.35); }
            70%  { box-shadow:0 0 0 7px rgba(243,156,18,0); }
            100% { box-shadow:0 0 0 0 rgba(243,156,18,0); }
        }
        .pt-step.current { cursor:pointer; }
        .pt-step.current:hover .pt-dot { background:#FEF6E7; }
        .pt-step-name { font-size:12px; margin-top:3px; color:#8A94A0; text-align:center; line-height:1.2; word-break:break-all; padding:0 3px; }
        .pt-step.reached .pt-step-name { color:#0e8c73; font-weight:600; }
        .pt-step.current .pt-step-name { color:#b9770e; font-weight:700; }
        .pt-step-time { font-size:10px; color:#A8B0BA; margin-top:1px; white-space:nowrap; }
        .pt-step.reached .pt-step-time { color:#17a98a; }
        .step-undo { cursor:pointer; color:#c0392b; font-size:10px; margin-top:1px; }
        .step-undo:hover { text-decoration:underline; }

        /* 進度下方的小項：面板排在流程下方的對齊帶，JS 讓左緣對齊所屬進度節點 */
        .pt-items-badge { font-size:10px; color:#5B8DEF; cursor:pointer; margin-top:2px; white-space:nowrap; user-select:none; }
        .pt-items-badge:hover { text-decoration:underline; }
        .pt-items-badge.all-done { color:#1ABB9C; }
        .pt-panels { display:flex; align-items:flex-start; }
        .pt-items-panel { margin-top:4px; background:#F7F9FC; border:1px solid #E3E9F1; border-radius:6px;
            padding:4px 8px; text-align:left; width:max-content; min-width:130px; max-width:210px; flex:0 0 auto; }
        .pt-items-panel.current-step { border-color:#F5D9A8; background:#FFFDF5; }
        .pt-panel-title { font-size:10px; color:#8A94A0; margin-bottom:1px; font-weight:700; white-space:nowrap; }
        .pt-item-row { display:flex; align-items:center; gap:5px; font-size:11px; padding:1px 0; color:#556; margin:0; font-weight:400; line-height:1.5; }
        .pt-item-row input[type=checkbox] { margin:0; cursor:pointer; }
        .pt-item-row.done .pt-item-name { color:#9AA5B1; text-decoration:line-through; }
        /* 小項文字：單行截斷，點文字展開/收合全文 */
        .pt-item-name { max-width:130px; white-space:nowrap; overflow:hidden; text-overflow:ellipsis; cursor:pointer; }
        .pt-item-name.expanded { white-space:normal; word-break:break-all; }
        .pt-item-date { font-size:10px; color:#1ABB9C; margin-left:auto; padding-left:8px; white-space:nowrap; }

        .deadline-urgent { color:#C0392B; font-weight:700; }
        /* 綁定標籤：淡底暖色小框（低調不搶眼） */
        .bind-badge { display:inline-block; padding:0 4px; border-radius:3px; font-size:10px; line-height:1.6;
            margin-right:4px; border:1px solid; background:#FDFAF5; }
        .bind-bom      { color:#9A7BB0; border-color:#E2D5EC; background:#FAF7FC; }
        .bind-part     { color:#7FA3BC; border-color:#D8E5EE; background:#F7FAFC; }
        .bind-customer { color:#7FB3A2; border-color:#D5EAE2; background:#F6FBF9; }
        .bind-maker    { color:#C99A6B; border-color:#EEDFCE; background:#FCF8F3; }
        .bind-order    { color:#C08A52; border-color:#EFDFC9; background:#FDF8F0; }

        .no-access-box{ max-width:520px; margin:80px auto; text-align:center; padding:40px; background:#fff; border-radius:8px; box-shadow:0 2px 5px rgba(0,0,0,.08); }

        /* 可雙擊編輯的儲存格 */
        td.cell-edit { cursor:pointer; }
        td.cell-edit:hover { background:#F0F5FB; }

        /* 新增/編輯跳窗改版：漸層標頭 + 區塊卡片 */
        .pt-modal { border:none; border-radius:10px; overflow:hidden; box-shadow:0 12px 40px rgba(42,63,84,.35); }
        .pt-modal-header { background:linear-gradient(135deg, #2A3F54 0%, #1ABB9C 100%); color:#fff; padding:13px 15px; border-bottom:none; }
        .pt-modal-header .modal-title { font-weight:700; }
        .pt-modal-header .close { color:#fff; opacity:.75; text-shadow:none; font-size:24px; margin-top:1px; }
        .pt-modal-header .close:hover { opacity:1; }
        .pt-header-actions { float:right; margin-right:34px; display:flex; gap:8px; margin-top:1px; }
        .pt-modal-body { background:#F4F7FC; padding:15px; max-height:calc(100vh - 220px); overflow-y:auto; }
        .form-section { background:#fff; border:1px solid #E8EEF5; border-radius:8px; padding:12px 15px; margin-bottom:12px; box-shadow:0 1px 3px rgba(0,0,0,.04); }
        .form-section-title { font-size:13px; font-weight:700; color:#2A3F54; margin-bottom:10px; }
        .form-section-title > i { color:#1ABB9C; margin-right:6px; }
        .pt-modal .form-control { border-radius:6px; box-shadow:none; border-color:#D8E0EA; }
        .pt-modal .form-control:focus { border-color:#1ABB9C; }
        .pt-modal label { font-size:12px; color:#7A869A; font-weight:600; margin-bottom:3px; }
        .pt-modal-footer { background:#F4F7FC; border-top:1px solid #E8EEF5; }

        /* 自動完成下拉 */
        .ac-box{ position:relative; }
        .ac-list{ position:absolute; z-index:1060; left:0; right:0; background:#fff; border:1px solid #ccc; border-top:none; max-height:220px; overflow:auto; display:none; }
        .ac-item{ padding:6px 10px; cursor:pointer; border-bottom:1px solid #f0f0f0; font-size:13px; }
        .ac-item:hover{ background:#f5f5f5; }

        /* 編輯 modal 內的步驟列（step-main 主列 + step-items 小項區） */
        .step-edit-row { padding:5px 4px; border-bottom:1px dashed #eee; background:#fff; }
        .step-main { display:flex; align-items:center; gap:6px; }
        .step-edit-row .step-drag { cursor:grab; color:#aaa; padding:0 4px; }
        .step-edit-row.reached { background:#f2fbf8; }
        .step-edit-row.reached .step-drag { visibility:hidden; }
        .step-edit-row .step-del { color:#d9534f; cursor:pointer; padding:0 4px; }
        .step-items { padding:3px 0 3px 30px; }
        .step-item-row { display:flex; align-items:center; gap:6px; padding:2px 0; }
        .item-done-tag { font-size:11px; color:#0e8c73; white-space:nowrap; }
        .step-edit-row input.step-name { width:140px; }
        .step-edit-row input.step-interval { width:60px; }
        .step-edit-row input.step-planned { width:135px; }
        .step-edit-row input.step-remind-val { width:58px; }
        .step-edit-row select.step-remind-unit { width:66px; }
        .step-reached-tag { font-size:11px; color:#0e8c73; white-space:nowrap; }

        /* 底部輕量提示 toast（不需按已閱，比照 liveEvent/mobile.php） */
        #ptToast { display:none; position:fixed; left:50%; bottom:40px; transform:translateX(-50%);
            background:rgba(0,0,0,.78); color:#fff; padding:9px 20px; border-radius:20px; font-size:13px; z-index:2000; }

        .role-hint { color:#888; font-size:12px; cursor:pointer; }

        /* 附件圖片縮圖（比照 Sales_Track：點擊另開原圖、×刪除） */
        .pt-img-wrap { position:relative; display:inline-block; }
        .pt-img-wrap img { max-height:110px; max-width:200px; width:auto; height:auto; border-radius:5px; border:1px solid #ddd; display:block; }
        .pt-img-del { position:absolute; top:-6px; right:-6px; width:17px; height:17px; border-radius:9px; background:#e74c3c;
            color:#fff; border:none; font-size:11px; cursor:pointer; line-height:17px; padding:0; text-align:center; }
        .pt-row-imgs { display:flex; flex-wrap:wrap; gap:4px; margin-top:4px; }
        .pt-row-imgs img { max-height:48px; max-width:90px; width:auto; height:auto; border-radius:3px; border:1px solid #ddd; display:block; }
        .pt-img-more { display:inline-flex; align-items:center; justify-content:center; min-width:30px; height:48px;
            background:#f0f4f8; border:1px solid #ccd; border-radius:3px; font-size:11px; color:#555; font-weight:600; cursor:pointer; }
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
          <p style="color:#888;">您目前沒有「個人工作紀錄」功能的使用權限，請聯絡管理者至「使用者權限管理」頁面指派角色。</p>
      </div>
<?php else: ?>
      <div class="page-title">
        <div class="title_left"><h3>個人工作紀錄</h3></div>
        <div class="title_right" style="text-align:right; padding-top:12px;">
          <span class="role-hint" id="roleHint">
            <i class="fa fa-user"></i> 個人工作紀錄使用者
            <i class="fa fa-question-circle" style="margin-left:4px;"></i>
          </span>
        </div>
      </div>
      <div class="clearfix"></div>

      <div class="stats-container">
        <div class="stat-card c-open active" data-filter="open"><i class="fa fa-tasks stat-icon"></i><div class="stat-value" id="cntOpen">0</div><div class="stat-label">未完成</div></div>
        <div class="stat-card c-urgent" data-filter="urgent"><i class="fa fa-exclamation-triangle stat-icon"></i><div class="stat-value" id="cntUrgent">0</div><div class="stat-label">急件（期限將至）</div></div>
        <div class="stat-card c-paused" data-filter="paused"><i class="fa fa-pause stat-icon"></i><div class="stat-value" id="cntPaused">0</div><div class="stat-label">暫停</div></div>
        <div class="stat-card c-done" data-filter="done"><i class="fa fa-check-circle stat-icon"></i><div class="stat-value" id="cntDone">0</div><div class="stat-label">已完成</div></div>
      </div>

      <div class="filter-bar">
        <button class="btn btn-success btn-sm" id="btnNewTask"><i class="fa fa-plus"></i> 新增紀錄</button>
        <input type="text" id="kwFilter" class="form-control input-sm" data-filter-field="1" placeholder="關鍵字（標題/綁定/備註）" style="width:220px;">
        <button class="btn btn-primary btn-sm" id="btnApplyFilter">篩選</button>
        <div style="margin-left:auto; display:flex; gap:8px;">
          <button class="btn btn-default btn-sm" id="btnSettings"><i class="fa fa-cog"></i> 設定</button>
          <button class="btn btn-default btn-sm" id="btnTemplates"><i class="fa fa-list-alt"></i> 流程範本</button>
          <button class="btn btn-default btn-sm" id="btnExportSetting"><i class="fa fa-header"></i> 表頭表尾</button>
          <button class="btn btn-info btn-sm" id="btnExportCsv">轉 CSV</button>
          <button class="btn btn-info btn-sm" id="btnExportPdf">轉 PDF</button>
        </div>
      </div>

      <div class="main-card">
        <div class="table-toolbar">
          <div style="display:flex;align-items:center;gap:10px;flex-wrap:wrap;">
            <button class="btn btn-default btn-xs" id="btnToggleAllItems"><i class="fa fa-list-ul"></i> 展開所有小項</button>
            <span style="color:#888;font-size:12px;">
              雙擊「接收日期／標題／期限」可開啟編輯；點「進度」<b>橘色</b>圓點回報到達（須依順序），最後的<i class="fa fa-flag-checkered"></i>「完成」＝整筆完成。
            </span>
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
        <div style="overflow-x:auto;width:100%;">
          <table class="table ptask-table" id="ptaskTable">
            <thead><tr>
              <th style="width:92px;">接收日期</th>
              <th style="min-width:180px;">標題</th>
              <th style="width:130px;">期限</th>
              <th style="min-width:300px;">進度</th>
            </tr></thead>
            <tbody id="ptaskTbody"><tr><td colspan="4" class="text-center text-muted">載入中...</td></tr></tbody>
          </table>
        </div>
      </div>
<?php endif; ?>
    </div>
  </div>
</div>

<?php if ($has_access): ?>
<!-- ══ 新增/編輯 Modal ══ -->
<div class="modal fade" id="taskModal" role="dialog" tabindex="-1" data-backdrop="static">
  <div class="modal-dialog" style="width:790px;">
    <div class="modal-content pt-modal">
      <div class="modal-header pt-modal-header">
        <button type="button" class="close" data-dismiss="modal" title="關閉">&times;</button>
        <div class="pt-header-actions">
          <button type="button" class="btn btn-warning btn-xs" id="btnModalStatus" style="display:none;"><i class="fa fa-pause"></i> 暫停</button>
          <button type="button" class="btn btn-danger btn-xs" id="btnModalDelete" style="display:none;"><i class="fa fa-trash"></i> 刪除</button>
        </div>
        <h4 class="modal-title" id="taskModalTitle"><i class="fa fa-pencil-square-o"></i> 新增紀錄</h4>
      </div>
      <div class="modal-body pt-modal-body">
        <input type="hidden" id="tId" value="0">

        <div class="form-section">
          <div class="form-section-title"><i class="fa fa-info-circle"></i>基本資料</div>
          <div class="row">
            <div class="col-sm-7"><div class="form-group" style="margin-bottom:8px;">
              <label>標題 <span style="color:#E74C3C;">*</span></label>
              <input type="text" id="tTitle" class="form-control" maxlength="200" placeholder="自行輸入工作標題">
            </div></div>
            <div class="col-sm-5"><div class="form-group" style="margin-bottom:8px;">
              <label>接收日期</label>
              <input type="date" id="tReceived" class="form-control" max="9999-12-31">
            </div></div>
          </div>
          <div class="form-group" style="margin-bottom:0;">
            <label>綁定對象（擇一綁定，也可不綁定）</label>
            <div style="display:flex; gap:8px; align-items:flex-start;">
              <select id="tBindType" class="form-control" style="width:115px;">
                <option value="">不綁定</option>
                <option value="bom">BOM號碼</option>
                <option value="part">料號</option>
                <option value="customer">客戶</option>
                <option value="maker">廠商</option>
                <option value="order">訂單追蹤</option>
              </select>
              <div class="ac-box" style="flex:1;">
                <input type="text" id="tBindKw" class="form-control" placeholder="先選類型，再輸入關鍵字搜尋" disabled>
                <div class="ac-list" id="tBindSug"></div>
              </div>
            </div>
            <div id="tBindChosen" style="margin-top:6px;"></div>
            <input type="hidden" id="tBindId"><input type="hidden" id="tBindLabel">
          </div>
        </div>

        <div class="form-section">
          <div class="form-section-title"><i class="fa fa-bell-o"></i>期限與提醒</div>
          <div class="row">
            <div class="col-sm-5"><div class="form-group" style="margin-bottom:0;">
              <label>期限（可不設定）</label>
              <input type="date" id="tDeadline" class="form-control" max="9999-12-31">
            </div></div>
            <div class="col-sm-4"><div class="form-group" style="margin-bottom:0;">
              <label>期限提醒（空白=不提醒）</label>
              <div style="display:flex; gap:5px; align-items:center;">
                <input type="number" id="tRemindVal" class="form-control" min="1" style="width:70px;">
                <select id="tRemindUnit" class="form-control" style="width:75px;">
                  <option value="1440">天</option>
                  <option value="60">小時</option>
                </select>
                <span style="white-space:nowrap;font-size:12px;color:#7A869A;">前提醒</span>
              </div>
            </div></div>
            <div class="col-sm-3"><div class="form-group" style="margin-bottom:0;">
              <label>急件天數（空白=預設）</label>
              <input type="number" id="tUrgentDays" class="form-control" min="0" placeholder="預設">
            </div></div>
          </div>
        </div>

        <div class="form-section">
          <div class="form-section-title"><i class="fa fa-tasks"></i>進度流程
            <span style="font-weight:400;color:#A8B0BA;font-size:11px;">（可拖移排序；由上而下依序回報，日期與提醒都可不設定）</span>
          </div>
          <div style="display:flex; gap:8px; margin-bottom:8px; align-items:center; flex-wrap:wrap;">
            <button type="button" class="btn btn-success btn-xs" id="btnAddStep"><i class="fa fa-plus"></i> 新增進度</button>
            <select id="tplApplySelect" class="form-control input-sm" style="width:170px;"><option value="">套用範本...</option></select>
            <button type="button" class="btn btn-default btn-xs" id="btnSaveAsTpl"><i class="fa fa-save"></i> 存成範本</button>
            <span style="margin-left:auto; display:flex; align-items:center; gap:5px; font-size:12px; color:#7A869A;">
              預設間隔 <input type="number" id="defaultInterval" class="form-control input-sm" min="0" style="width:58px;"> 工作天
              <button type="button" class="btn btn-primary btn-xs" id="btnApplyInterval" title="把此天數帶入所有(未到達)進度的間隔並重算日期，之後可逐列手動修改">帶入全部</button>
              <button type="button" class="btn btn-info btn-xs" id="btnSuggestInterval" title="依「接收日期→期限的工作天數 ÷ 進度數」自動算出建議間隔並帶入">依期限建議</button>
            </span>
          </div>
          <div style="font-size:11px; color:#A8B0BA; margin-bottom:4px;">
            名稱欄按 <b>↓</b> 自動新增下一列、空白列按 <b>↑</b> 自動移除。「間隔」填工作天數（週末與休假日不算、補班日算），自動從上一列日期（第一列從接收日期）推算預定日期。
          </div>
          <div style="display:flex; gap:6px; padding:0 4px 2px 26px; font-size:11px; color:#A8B0BA;">
            <span style="width:140px;">進度名稱</span><span style="width:60px;">間隔(工作天)</span><span style="width:135px;">預定日期(可空)</span><span>提醒(可空)</span>
          </div>
          <div id="stepEditor"></div>
        </div>

        <div class="form-section">
          <div class="form-section-title"><i class="fa fa-commenting-o"></i>備註</div>
          <textarea id="tNote" class="form-control" rows="2" placeholder="選填"></textarea>
        </div>

        <div class="form-section">
          <div class="form-section-title"><i class="fa fa-image"></i>附件圖片
            <span style="font-weight:400;color:#A8B0BA;font-size:11px;">（點縮圖另開原圖）</span>
          </div>
          <div id="tImgsList" style="display:flex;flex-wrap:wrap;gap:8px;margin-bottom:8px;"></div>
          <label class="btn btn-default btn-sm" id="tImgUploadBtn" style="cursor:pointer;margin-bottom:0;">
            <i class="fa fa-upload"></i> 上傳圖片
            <input type="file" id="tImgUpload" accept="image/*" multiple style="display:none;">
          </label>
          <span id="tImgHint" style="font-size:11px;color:#A8B0BA;margin-left:6px;"></span>
        </div>
      </div>
      <div class="modal-footer pt-modal-footer">
        <button type="button" class="btn btn-default" data-dismiss="modal">取消</button>
        <button type="button" class="btn btn-primary" id="btnSaveTask" data-enter-submit="1"><i class="fa fa-check"></i> 儲存</button>
      </div>
    </div>
  </div>
</div>

<!-- ══ 急件天數設定 Modal ══ -->
<div class="modal fade" id="settingModal" role="dialog" tabindex="-1">
  <div class="modal-dialog" style="width:400px;">
    <div class="modal-content">
      <div class="modal-header"><button type="button" class="close" data-dismiss="modal">&times;</button>
        <h4 class="modal-title"><i class="fa fa-cog"></i> 設定</h4></div>
      <div class="modal-body">
        <p style="color:#888;font-size:12px;">未完成的紀錄在「期限前 N 天」起會以紅底急件顯示，並可用急件卡快速篩選。此為個人預設值，每筆紀錄也可個別覆寫。</p>
        <div class="form-group" style="display:flex; align-items:center; gap:8px;">
          <label style="margin:0;">期限前</label>
          <input type="number" id="setUrgentDays" class="form-control" min="0" style="width:80px;">
          <label style="margin:0;">天顯示急件</label>
        </div>
        <div id="attachPathBox" style="display:none; border-top:1px dashed #ddd; margin-top:12px; padding-top:10px;">
          <div style="font-weight:700; font-size:13px; margin-bottom:6px;"><i class="fa fa-folder-open-o"></i> 附件儲存路徑（全系統設定，僅管理員可見）</div>
          <div class="form-group" style="margin-bottom:8px;">
            <label style="font-size:12px; margin-bottom:2px;">NAS 實體路徑（後端寫檔）</label>
            <input type="text" id="setNasDir" class="form-control input-sm" placeholder="例：Z:/BOM/ERP/個人工作/">
          </div>
          <div class="form-group" style="margin-bottom:6px;">
            <label style="font-size:12px; margin-bottom:2px;">URL 前綴（前端顯示）</label>
            <input type="text" id="setUrlDir" class="form-control input-sm" placeholder="例：/nas/ERP/個人工作/">
          </div>
          <p style="color:#888;font-size:11px;margin:0;">資料庫只存檔名，顯示時用此設定即時組出路徑；修改後所有附件（含既有）一律改讀新路徑，搬移 NAS 資料夾時請把既有檔案一併搬過去。</p>
        </div>
      </div>
      <div class="modal-footer">
        <button type="button" class="btn btn-default" data-dismiss="modal">取消</button>
        <button type="button" class="btn btn-primary" id="btnSaveSetting" data-enter-submit="1">儲存</button>
      </div>
    </div>
  </div>
</div>

<!-- ══ 流程範本管理 Modal ══ -->
<div class="modal fade" id="tplModal" role="dialog" tabindex="-1">
  <div class="modal-dialog" style="width:480px;">
    <div class="modal-content">
      <div class="modal-header"><button type="button" class="close" data-dismiss="modal">&times;</button>
        <h4 class="modal-title"><i class="fa fa-list-alt"></i> 流程範本管理</h4></div>
      <div class="modal-body">
        <p style="color:#888;font-size:12px;">範本只有自己看得到。新增範本請在「新增/編輯紀錄」視窗中把排好的流程按「存成範本」。</p>
        <div id="tplList"></div>
      </div>
      <div class="modal-footer"><button type="button" class="btn btn-default" data-dismiss="modal">關閉</button></div>
    </div>
  </div>
</div>

<!-- ══ 表頭表尾設定 Modal（匯出用）══ -->
<div class="modal fade" id="exportSettingModal" role="dialog" tabindex="-1">
  <div class="modal-dialog" style="width:480px;">
    <div class="modal-content">
      <div class="modal-header"><button type="button" class="close" data-dismiss="modal">&times;</button>
        <h4 class="modal-title"><i class="fa fa-header"></i> 匯出表頭表尾設定</h4></div>
      <div class="modal-body">
        <div class="form-group"><label>表頭文字</label>
          <input type="text" id="expHeader" class="form-control" placeholder="例如：公司名稱－個人工作紀錄"></div>
        <div class="form-group"><label>表尾文字</label>
          <input type="text" id="expFooter" class="form-control" placeholder="例如：僅供內部使用"></div>
        <p style="color:#888;font-size:12px;">設定會記在這台電腦的瀏覽器，匯出 CSV / PDF 時自動帶入。</p>
      </div>
      <div class="modal-footer">
        <button type="button" class="btn btn-default" data-dismiss="modal">取消</button>
        <button type="button" class="btn btn-primary" id="btnSaveExpSetting" data-enter-submit="1">儲存</button>
      </div>
    </div>
  </div>
</div>

<!-- ══ 權限說明 Modal ══ -->
<div class="modal fade" id="roleInfoModal" role="dialog" tabindex="-1">
  <div class="modal-dialog" style="width:480px;">
    <div class="modal-content">
      <div class="modal-header"><button type="button" class="close" data-dismiss="modal">&times;</button>
        <h4 class="modal-title"><i class="fa fa-question-circle"></i> 權限說明</h4></div>
      <div class="modal-body" style="font-size:13px;">
        <ul style="padding-left:18px;">
          <li><b>個人工作紀錄使用者</b>：可建立、編輯、刪除自己的工作紀錄與流程範本。</li>
          <li>每個人<b>只看得到自己</b>建立的紀錄；其他使用者（包含管理者）都看不到你的內容。</li>
          <li>權限指派：管理者可至「使用者權限管理」頁指派本功能的使用資格。</li>
        </ul>
      </div>
      <div class="modal-footer"><button type="button" class="btn btn-default" data-dismiss="modal">關閉</button></div>
    </div>
  </div>
</div>

<div id="ptToast"></div>
<?php endif; ?>

<script src="../../resource/js/jquery.min.js"></script>
<script src="../../resource/js/bootstrap.min.js"></script>
<script src="https://code.jquery.com/ui/1.12.1/jquery-ui.js"></script>
<script src="../../resource/js/custom.min.js"></script>
<script src="../../resource/js/pdfmake.min.js"></script>
<?php if ($has_access): ?>
<script>
var API = '../../src/store/PersonalTask_API.php';
var state = { filter: 'open', kw: '', page: 1, pageSize: 10, total: 0, rows: [], urgentDefault: 3 };
var templates = [];

function apiGet(action, params)  { return $.get(API, Object.assign({ action: action }, params || {}), null, 'json'); }
function apiPost(action, params) { return $.post(API, Object.assign({ action: action }, params || {}), null, 'json'); }

function toast(msg) {
    $('#ptToast').text(msg).fadeIn(120);
    setTimeout(function () { $('#ptToast').fadeOut(300); }, 2200);
}

function esc(s) { return $('<div>').text(s == null ? '' : String(s)).html(); }

// 日期顯示格式一律 YYYY.MM.DD；與今年同年時可用短格式 MM.DD（使用者要求 2026-07-16）
var CUR_YEAR = String(new Date().getFullYear());
function fmtDate(dt) { // "2026-07-20..." → "2026.07.20"
    if (!dt) return '';
    return String(dt).substring(0, 10).replace(/-/g, '.');
}
function fmtDateShort(dt) { // 同年 → "MM.DD"；跨年 → "YYYY.MM.DD"
    if (!dt) return '';
    var d = fmtDate(dt);
    return String(dt).substring(0, 4) === CUR_YEAR ? d.substring(5) : d;
}
function fmtDt(dt) { // datetime → "YYYY.MM.DD HH:MM"（00:00 省略時間）
    if (!dt) return '';
    var s = String(dt).substring(0, 16);
    var t = s.substring(11, 16);
    return fmtDate(s) + ((t && t !== '00:00') ? ' ' + t : '');
}
function fmtDtShort(dt) { // 同年省年的 datetime 短格式
    if (!dt) return '';
    var s = String(dt).substring(0, 16);
    var t = s.substring(11, 16);
    return fmtDateShort(s) + ((t && t !== '00:00') ? ' ' + t : '');
}
function fmtMMDD(dt) { // "2026-07-16 ..." → "07.16"
    if (!dt) return '';
    var s = String(dt);
    return s.substring(5, 7) + '.' + s.substring(8, 10);
}
// DB datetime → date input 值（期限與進度都只用日期）
function dtToDateInput(dt) { return dt ? String(dt).substring(0, 10) : ''; }

// ── 工作天（比照 views/pages/calendar.php：補班日('m')算上班、週末與休假日('s')不算）──
var workday = { holidays: {}, makeup: {} };
function loadWorkdayData() {
    return apiGet('get_workday_data').done(function (res) {
        if (!res.success) return;
        (res.holidays || []).forEach(function (d) { workday.holidays[d] = 1; });
        (res.makeup || []).forEach(function (d) { workday.makeup[d] = 1; });
    });
}
function dateToStr(d) { return d.getFullYear() + '-' + ('0' + (d.getMonth() + 1)).slice(-2) + '-' + ('0' + d.getDate()).slice(-2); }
function isWorkday(d) {
    var ds = dateToStr(d);
    if (workday.makeup[ds]) return true;
    var dow = d.getDay();
    return dow !== 0 && dow !== 6 && !workday.holidays[ds];
}
// 從 baseStr 起加 n 個工作天（n=0 回傳 baseStr 本身）
function addWorkingDays(baseStr, n) {
    var d = new Date(baseStr + 'T00:00:00');
    if (isNaN(d.getTime())) return '';
    var added = 0, guard = 0;
    while (added < n && guard < 3700) { d.setDate(d.getDate() + 1); guard++; if (isWorkday(d)) added++; }
    return dateToStr(d);
}
// fromStr(不含) ~ toStr(含) 之間的工作天數
function workdaysBetween(fromStr, toStr) {
    var d = new Date(fromStr + 'T00:00:00'), end = new Date(toStr + 'T00:00:00');
    if (isNaN(d.getTime()) || isNaN(end.getTime())) return 0;
    var c = 0, guard = 0;
    while (d < end && guard < 3700) { d.setDate(d.getDate() + 1); guard++; if (isWorkday(d)) c++; }
    return c;
}

// 分鐘 → {val, unit}（unit: 1440=天, 60=小時）
function minToUi(min) {
    if (min == null || min === '') return { val: '', unit: '1440' };
    min = parseInt(min, 10);
    if (min % 1440 === 0) return { val: min / 1440, unit: '1440' };
    return { val: Math.round(min / 60), unit: '60' };
}

var bindTypeName = { bom: 'BOM', part: '料號', customer: '客戶', maker: '廠商', order: '訂單' };

// ── 列表 ─────────────────────────────────────────────────────────────
function filterParams() {
    var p = { kw: state.kw, page: state.page, pageSize: state.pageSize };
    if (state.filter === 'urgent') { p.urgent_only = 1; p.status = '0'; }
    else p.status = { open: '0', paused: '2', done: '1' }[state.filter] || '0';
    return p;
}

function loadList() {
    apiGet('list_tasks', filterParams()).done(function (res) {
        if (!res.success) { $('#ptaskTbody').html('<tr><td colspan="4" class="text-center text-muted">' + esc(res.message || '讀取失敗') + '</td></tr>'); return; }
        state.rows = res.data || [];
        state.total = res.total || 0;
        state.urgentDefault = res.urgent_default || 3;
        var c = res.counts || {};
        $('#cntOpen').text(c.cnt_open || 0);
        $('#cntUrgent').text(c.cnt_urgent || 0);
        $('#cntPaused').text(c.cnt_paused || 0);
        $('#cntDone').text(c.cnt_done || 0);
        var maxPage = Math.max(1, Math.ceil(state.total / state.pageSize));
        if (state.page > maxPage) { state.page = maxPage; loadList(); return; }
        $('#pageInfo').text('共 ' + state.total + ' 筆，第 ' + state.page + '/' + maxPage + ' 頁');
        $('#pageNum').text(state.page);
        renderRows(state.rows);
    });
}

function deadlineCell(r) {
    if (!r.deadline) return $('<td>').html('<span style="color:#bbb;">未設定</span>');
    var $td = $('<td>');
    var dl = String(r.deadline).substring(0, 10);
    var $d = $('<div>').text(fmtDate(dl));
    if (r.status == 0) {
        var today = dateToStr(new Date());
        var $tag = $('<div style="font-size:11px;">');
        if (r.is_overdue == 1) {
            $tag.text('已逾期 ' + workdaysBetween(dl, today) + ' 工作天').addClass('deadline-urgent');
            $d.addClass('deadline-urgent');
        } else {
            var remain = workdaysBetween(today, dl);
            $tag.text('剩 ' + remain + ' 工作天');
            if (r.is_urgent == 1) { $tag.addClass('deadline-urgent'); $d.addClass('deadline-urgent'); }
            else { $tag.css('color', '#888'); }
        }
        $td.append($d).append($tag);
    } else { $td.append($d); }
    return $td;
}

var expandAllItems = false;   // 「展開所有小項」開關（預設只展開目前進度的小項）

function stepFlowCell(r) {
    var $td = $('<td>');
    var steps = r.steps || [];
    var firstUnreachedId = null, lastReachedId = null;
    steps.forEach(function (s) {
        if (s.reached_at == null && firstUnreachedId === null) firstUnreachedId = s.id;
        if (s.reached_at != null) lastReachedId = s.id;
    });
    var allRealReached = steps.every(function (s) { return s.reached_at != null; });
    var $panelsBox = $('<div class="pt-panels">');   // 小項面板對齊帶（JS 對齊到所屬節點正下方）
    var $flow = $('<div class="step-flow">');

    steps.forEach(function (s, i) {
        var reached = s.reached_at != null;
        var isCurrent = (r.status == 0 && s.id === firstUnreachedId);
        var $node = $('<div class="pt-step">');
        if (reached) $node.addClass('reached');
        else if (isCurrent) $node.addClass('current');

        // 圓點與左右連接線：與前一步之間的線，在前一步已到達時上色（最後永遠接到「完成」節點）
        var prevReached = i > 0 && steps[i - 1].reached_at != null;
        var $top = $('<div class="pt-step-top">');
        $top.append($('<span class="pt-line">').addClass(i === 0 ? 'edge' : (prevReached ? 'done' : '')));
        var $dot = $('<span class="pt-dot">');
        if (reached) $dot.html('<i class="fa fa-check"></i>'); else $dot.text(i + 1);
        $top.append($dot);
        $top.append($('<span class="pt-line">').addClass(reached ? 'done' : ''));
        $node.append($top);

        $node.append($('<div class="pt-step-name">').text(s.step_name));
        if (reached) {
            $node.attr('title', '到達：' + fmtDt(s.reached_at) + (s.planned_at ? '（預定 ' + fmtDate(s.planned_at) + '）' : ''));
            $node.append($('<div class="pt-step-time">').text(fmtDtShort(s.reached_at)));
            // 最後一個已到達者提供復原（僅未完成狀態）
            if (r.status == 0 && s.id === lastReachedId) {
                $node.append($('<span class="step-undo">').text('復原').on('click', function (e) {
                    e.stopPropagation();
                    if (!confirm('取消「' + s.step_name + '」的到達回報？')) return;
                    apiPost('unreach_step', { step_id: s.id }).done(function (res) {
                        if (!res.success) { alert(res.message || '復原失敗'); return; }
                        loadList();
                    });
                }));
            }
        } else if (isCurrent) {
            $node.attr('title', (s.planned_at ? '預定 ' + fmtDate(s.planned_at) + '，' : '') + '點一下回報到達');
            if (s.planned_at) $node.append($('<div class="pt-step-time">').text('預定 ' + fmtDateShort(s.planned_at)));
            $node.on('click', function () {
                if (!confirm('回報到達「' + s.step_name + '」？（會記錄現在時間）')) return;
                apiPost('reach_step', { step_id: s.id }).done(function (res) {
                    if (!res.success) { alert(res.message || '回報失敗'); return; }
                    toast('已到達「' + s.step_name + '」 ' + fmtDt(res.reached_at));
                    loadList();
                });
            });
        } else {
            if (s.planned_at) $node.append($('<div class="pt-step-time">').text('預定 ' + fmtDateShort(s.planned_at)));
            $node.attr('title', '尚未輪到此進度');
        }

        // 小項：徽章顯示完成數，面板展開在流程下方、左緣對齊所屬節點；目前進度預設展開，「展開所有小項」時全開
        var items = s.items || [];
        if (items.length) {
            var $badge = $('<div class="pt-items-badge">');
            var $panel = buildItemsPanel(s, $badge, isCurrent);
            $panel.data('nodeEl', $node);
            if (!(expandAllItems || isCurrent)) $panel.hide();
            $badge.on('click', function (e) {
                e.stopPropagation();
                $panel.toggle();
                alignPanels($panelsBox);
            });
            $node.append($badge);
            $panelsBox.append($panel);
        }
        $flow.append($node);
    });

    // 虛擬「完成」節點：所有進度到達後點它＝整筆完成（記錄完成時間）；已完成可復原
    var isDone = (r.status == 1);
    var finCurrent = (r.status == 0 && allRealReached);
    var $fin = $('<div class="pt-step">').addClass(isDone ? 'reached' : (finCurrent ? 'current' : ''));
    var $finTop = $('<div class="pt-step-top">');
    $finTop.append($('<span class="pt-line">').addClass(steps.length === 0 ? 'edge'
        : (steps[steps.length - 1].reached_at != null ? 'done' : '')));
    $finTop.append($('<span class="pt-dot">').html('<i class="fa fa-flag-checkered"></i>'));
    $finTop.append($('<span class="pt-line edge">'));
    $fin.append($finTop).append($('<div class="pt-step-name">').text('完成'));
    if (isDone) {
        $fin.attr('title', '完成於 ' + fmtDt(r.completed_at));
        if (r.completed_at) $fin.append($('<div class="pt-step-time">').text(fmtDtShort(r.completed_at)));
        $fin.append($('<span class="step-undo">').text('復原').on('click', function (e) {
            e.stopPropagation();
            if (!confirm('把「' + r.title + '」恢復為未完成？')) return;
            setStatus(r, 0);
        }));
    } else if (finCurrent) {
        $fin.attr('title', '點一下＝整筆工作完成');
        $fin.on('click', function () {
            if (!confirm('確認「' + r.title + '」已全部完成？')) return;
            setStatus(r, 1);
        });
    } else {
        $fin.attr('title', '全部進度到達後才可點選完成');
    }
    $flow.append($fin);

    return $td.append($flow).append($panelsBox);
}

// 小項面板水平對齊：每個面板左緣對齊上方所屬進度節點；放不下時往右順推、不互相重疊
function alignPanels($box) {
    if (!$box || !$box.length) return;
    var boxLeft = $box.offset().left;
    var consumed = 0;
    $box.children('.pt-items-panel').each(function () {
        var $p = $(this);
        if (!$p.is(':visible')) return;
        var $node = $p.data('nodeEl');
        var desired = $node ? Math.max(0, $node.offset().left - boxLeft) : 0;
        var ml = Math.max(consumed === 0 ? 0 : 6, desired - consumed);
        $p.css('margin-left', ml + 'px');
        consumed += ml + $p.outerWidth();
    });
}
function alignAllPanels() {
    $('#ptaskTbody .pt-panels').each(function () { alignPanels($(this)); });
}
var _alignTimer = null;
$(window).on('resize', function () { clearTimeout(_alignTimer); _alignTimer = setTimeout(alignAllPanels, 150); });

// 小項面板：勾選=完成(記MM.DD)、可取消；不受步驟順序限制。
// 名稱過長單行截斷(…)，點名稱展開/收合完整文字
function buildItemsPanel(step, $badge, isCurrent) {
    var items = step.items || [];
    function refreshBadge() {
        var doneCnt = items.filter(function (x) { return x.done_at != null; }).length;
        $badge.text('小項 ' + doneCnt + '/' + items.length + ' ▾')
              .toggleClass('all-done', doneCnt === items.length);
    }
    refreshBadge();
    var $panel = $('<div class="pt-items-panel">').toggleClass('current-step', !!isCurrent);
    $panel.append($('<div class="pt-panel-title">').text('「' + step.step_name + '」小項'));
    items.forEach(function (it) {
        var $row = $('<div class="pt-item-row">').toggleClass('done', it.done_at != null);
        var $cb = $('<input type="checkbox">').prop('checked', it.done_at != null);
        var $name = $('<span class="pt-item-name">').text(it.item_name)
            .attr('title', '點一下展開/收合完整文字')
            .on('click', function () { $(this).toggleClass('expanded'); });
        var $date = $('<span class="pt-item-date">').text(it.done_at ? fmtMMDD(it.done_at) : '');
        $cb.on('change', function () {
            var done = this.checked ? 1 : 0;
            apiPost('toggle_step_item', { item_id: it.id, done: done }).done(function (res) {
                if (!res.success) { alert(res.message || '設定失敗'); $cb.prop('checked', !done); return; }
                it.done_at = res.done_at || null;
                $row.toggleClass('done', !!it.done_at);
                $date.text(it.done_at ? fmtMMDD(it.done_at) : '');
                refreshBadge();
            });
        });
        $row.append($cb).append($name).append($date);
        $panel.append($row);
    });
    return $panel;
}

$('#btnToggleAllItems').on('click', function () {
    expandAllItems = !expandAllItems;
    $(this).html('<i class="fa fa-list-ul"></i> ' + (expandAllItems ? '收合所有小項' : '展開所有小項'));
    renderRows(state.rows);
});

function renderRows(rows) {
    var $tb = $('#ptaskTbody').empty();
    if (!rows.length) { $tb.html('<tr><td colspan="4" class="text-center text-muted">沒有符合條件的紀錄</td></tr>'); return; }
    rows.forEach(function (r) {
        var tr = $('<tr>').data('task', r);
        if (r.status == 0) {
            if (r.is_overdue == 1) tr.addClass('row-overdue');
            else if (r.is_urgent == 1) tr.addClass('row-urgent');
        }
        tr.append($('<td class="cell-edit" title="雙擊編輯">').text(fmtDate(r.received_at)));
        var $titleTd = $('<td class="cell-edit" title="雙擊編輯">');
        $titleTd.append($('<div style="font-weight:600;">').text(r.title));
        // 綁定內容顯示在標題下方（增加閱讀性）
        if (r.bind_type && r.bind_label) {
            var $bind = $('<div style="margin-top:2px;">');
            $bind.append($('<span class="bind-badge bind-' + r.bind_type + '">').text(bindTypeName[r.bind_type] || r.bind_type));
            $bind.append($('<span style="font-size:12px;color:#667;">').text(r.bind_label));
            $titleTd.append($bind);
        }
        if (r.note) $titleTd.append($('<div style="font-size:11px;color:#888;white-space:pre-line;">').text(r.note));
        // 附件縮圖：最多顯示 3 張，點縮圖另開原圖；「+N」開啟編輯視窗看全部（比照 Sales_Track）
        var imgs = r.images || [];
        if (imgs.length) {
            var $imgs = $('<div class="pt-row-imgs">');
            imgs.slice(0, 3).forEach(function (img) {
                $imgs.append($('<a target="_blank">').attr('href', img.url)
                    .attr('title', img.original_name || img.file_name)
                    .append($('<img>').attr('src', img.url).on('error', function () { $(this).closest('a').hide(); })));
            });
            if (imgs.length > 3) {
                $imgs.append($('<span class="pt-img-more" title="開啟編輯視窗看全部圖片">').text('+' + (imgs.length - 3))
                    .on('click', function (e) { e.stopPropagation(); openTaskModal(r.id); }));
            }
            $titleTd.append($imgs);
        }
        tr.append($titleTd);
        tr.append(deadlineCell(r).addClass('cell-edit').attr('title', '雙擊編輯'));
        tr.append(stepFlowCell(r));
        $tb.append(tr);
    });
    alignAllPanels();  // 所有列都進 DOM 後，把小項面板對齊到各自的進度節點下方
}

// 雙擊「接收日期／標題／期限」任一儲存格 → 開啟編輯跳窗
$('#ptaskTbody').on('dblclick', 'td.cell-edit', function () {
    var r = $(this).closest('tr').data('task');
    if (r) openTaskModal(r.id);
});

function setStatus(r, status) {
    var name = { 0: '未完成', 1: '已完成', 2: '暫停' }[status];
    apiPost('set_status', { id: r.id, status: status }).done(function (res) {
        if (!res.success) { alert(res.message || '設定失敗'); return; }
        toast('「' + r.title + '」已設為' + name);
        loadList();
    });
}

// ── 狀態卡切換 ───────────────────────────────────────────────────────
$('.stat-card').on('click', function () {
    $('.stat-card').removeClass('active');
    $(this).addClass('active');
    state.filter = $(this).data('filter');
    state.page = 1;
    loadList();
});

$('#btnApplyFilter').on('click', function () { state.kw = $('#kwFilter').val(); state.page = 1; loadList(); });
$('#pageSizeSelect').on('change', function () { state.pageSize = parseInt($(this).val(), 10); state.page = 1; loadList(); });
$('#btnPrevPage').on('click', function () { if (state.page > 1) { state.page--; loadList(); } });
$('#btnNextPage').on('click', function () {
    var maxPage = Math.max(1, Math.ceil(state.total / state.pageSize));
    if (state.page < maxPage) { state.page++; loadList(); }
});

// ── 新增/編輯 Modal ──────────────────────────────────────────────────
function stepItemRowHtml(it) {
    // it: {id, item_name, done_at} 或空(新小項)
    it = it || {};
    var $r = $('<div class="step-item-row">').data('itemId', it.id || 0);
    $r.append($('<span style="color:#ccc;"><i class="fa fa-angle-right"></i></span>'));
    $r.append($('<input type="text" class="form-control input-sm item-name" maxlength="150" placeholder="小項名稱" style="width:220px;">').val(it.item_name || ''));
    if (it.done_at) $r.append($('<span class="item-done-tag">').text('✔ ' + fmtMMDD(it.done_at)));
    $r.append($('<span class="step-del" title="刪除小項"><i class="fa fa-times-circle"></i></span>').on('click', function () {
        var $wrap = $r.closest('.step-edit-row');
        $r.remove();
        updateItemBtnCount($wrap);
    }));
    return $r;
}

function updateItemBtnCount($row) {
    $row.find('.item-cnt').text($row.find('.step-item-row').length);
}

function stepRowHtml(s) {
    // s: {id, step_name, planned_at, remind_before_minutes, reached_at, items} 或空(新步驟)
    s = s || {};
    var reached = s.reached_at != null;
    var $row = $('<div class="step-edit-row' + (reached ? ' reached' : '') + '">').data('stepId', s.id || 0).data('reached', reached ? 1 : 0);
    var $main = $('<div class="step-main">');
    $main.append($('<span class="step-drag"><i class="fa fa-bars"></i></span>'));
    $main.append($('<input type="text" class="form-control input-sm step-name" maxlength="100" placeholder="進度名稱">').val(s.step_name || ''));
    $main.append($('<input type="number" class="form-control input-sm step-interval" min="0" placeholder="間隔" title="與上一列預定日期間隔的工作天數（第一列從接收日期起算）">'));
    $main.append($('<input type="date" class="form-control input-sm step-planned" max="9999-12-31">').val(dtToDateInput(s.planned_at)));
    var ui = minToUi(s.remind_before_minutes);
    $main.append($('<input type="number" class="form-control input-sm step-remind-val" min="1" placeholder="提醒">').val(ui.val));
    var $unit = $('<select class="form-control input-sm step-remind-unit"><option value="1440">天</option><option value="60">小時</option></select>').val(ui.unit);
    $main.append($unit).append($('<span style="font-size:11px;color:#888;white-space:nowrap;">前</span>'));

    // 小項區（預設收合；按鈕顯示數量）
    var items = s.items || [];
    var $itemsBox = $('<div class="step-items" style="display:none;">');
    items.forEach(function (it) { $itemsBox.append(stepItemRowHtml(it)); });
    var $itemsFooter = $('<div style="padding:2px 0;">');
    $itemsFooter.append($('<button type="button" class="btn btn-default btn-xs"><i class="fa fa-plus"></i> 新增小項</button>').on('click', function () {
        var $n = stepItemRowHtml();
        $n.insertBefore($itemsFooter);
        updateItemBtnCount($row);
        $n.find('.item-name').focus();
    }));
    $itemsBox.append($itemsFooter);
    $main.append($('<button type="button" class="btn btn-xs btn-default" title="展開/收合小項清單">')
        .html('<i class="fa fa-list-ul"></i> 小項 <span class="item-cnt">' + items.length + '</span>')
        .on('click', function () { $itemsBox.toggle(); }));

    if (reached) {
        $main.append($('<span class="step-reached-tag">').text('✔ ' + fmtDtShort(s.reached_at)));
    } else {
        $main.append($('<span class="step-del" title="刪除此進度"><i class="fa fa-times-circle"></i></span>').on('click', function () { $row.remove(); recomputeStepDates(); }));
    }
    $row.append($main).append($itemsBox);
    return $row;
}

function initStepSortable() {
    $('#stepEditor').sortable({
        items: '.step-edit-row:not(.reached)',   // 已到達的固定在前，不可拖
        handle: '.step-drag',
        axis: 'y',
        update: function () { recomputeStepDates(); }
    });
}

// 依「間隔(工作天)」自動推算各列預定日期：有填間隔的列 = 上一列日期(第一列從接收日期)加 N 個工作天；
// 手動改日期的列會清空自己的間隔並成為後續列的新基準
function recomputeStepDates() {
    var prev = $('#tReceived').val() || dateToStr(new Date());
    $('#stepEditor .step-edit-row').each(function () {
        var $r = $(this);
        var iv = $r.find('.step-interval').val();
        var $d = $r.find('.step-planned');
        if (iv !== '' && iv != null && $r.data('reached') != 1) {
            var v = addWorkingDays(prev, Math.max(0, parseInt(iv, 10) || 0));
            if (v) $d.val(v);
        }
        if ($d.val()) prev = $d.val();
    });
}
$('#stepEditor').on('input change', '.step-interval', recomputeStepDates);
$('#stepEditor').on('change', '.step-planned', function () {
    $(this).closest('.step-edit-row').find('.step-interval').val(''); // 手動改日期＝脫離自動推算
    recomputeStepDates();
});
$('#tReceived').on('change', recomputeStepDates);

// 進度名稱欄鍵盤操作：↓ 移至下一列（最後一列時自動新增）；↑ 空白列自動移除並回到上一列
$('#stepEditor').on('keydown', '.step-name', function (e) {
    var $row = $(this).closest('.step-edit-row');
    if (e.key === 'ArrowDown') {
        e.preventDefault();
        var $next = $row.next('.step-edit-row');
        if (!$next.length) { $next = stepRowHtml(); $('#stepEditor').append($next); initStepSortable(); }
        $next.find('.step-name').focus();
    } else if (e.key === 'ArrowUp') {
        e.preventDefault();
        var $prev = $row.prev('.step-edit-row');
        if ($(this).val().trim() === '' && $row.data('reached') != 1) {
            $row.remove();
            recomputeStepDates();
        }
        if ($prev.length) $prev.find('.step-name').focus();
    }
});

// 小項名稱欄鍵盤操作：↓ 下一個小項（最後一個時自動新增）；↑ 空白小項自動移除並回到上一個
$('#stepEditor').on('keydown', '.item-name', function (e) {
    var $r = $(this).closest('.step-item-row');
    var $wrap = $(this).closest('.step-edit-row');
    if (e.key === 'ArrowDown') {
        e.preventDefault();
        var $next = $r.next('.step-item-row');
        if (!$next.length) {
            $next = stepItemRowHtml();
            $next.insertAfter($r);
            updateItemBtnCount($wrap);
        }
        $next.find('.item-name').focus();
    } else if (e.key === 'ArrowUp') {
        e.preventDefault();
        var $prev = $r.prev('.step-item-row');
        if ($(this).val().trim() === '') {
            $r.remove();
            updateItemBtnCount($wrap);
        }
        if ($prev.length) $prev.find('.item-name').focus();
        else $wrap.find('.step-name').focus();
    }
});

var modalTask = { id: 0, status: 0, title: '' };

// 右上角暫停/刪除的顯示與文案（依紀錄狀態）
function refreshModalHeaderButtons() {
    if (!modalTask.id) { $('#btnModalStatus, #btnModalDelete').hide(); return; }
    var $b = $('#btnModalStatus').removeClass('btn-warning btn-primary');
    if (modalTask.status == 0) $b.addClass('btn-warning').html('<i class="fa fa-pause"></i> 暫停');
    else if (modalTask.status == 2) $b.addClass('btn-primary').html('<i class="fa fa-play"></i> 恢復');
    else $b.addClass('btn-primary').html('<i class="fa fa-undo"></i> 恢復未完成');
    $('#btnModalStatus, #btnModalDelete').show();
}

function openTaskModal(id) {
    $('#tId').val(id || 0);
    $('#taskModalTitle').html('<i class="fa fa-pencil-square-o"></i> ' + (id ? '編輯紀錄' : '新增紀錄'));
    $('#tTitle, #tBindKw, #tBindId, #tBindLabel, #tRemindVal, #tUrgentDays, #tNote, #tDeadline, #defaultInterval').val('');
    $('#tBindType').val(''); $('#tBindKw').prop('disabled', true);
    $('#tBindChosen').empty(); $('#tBindSug').hide().empty();
    $('#tRemindUnit').val('1440');
    $('#tReceived').val(new Date().toISOString().substring(0, 10));
    $('#tUrgentDays').attr('placeholder', '預設 ' + state.urgentDefault + ' 天');
    $('#stepEditor').empty();
    $('#tImgsList').empty();
    $('#tImgUploadBtn').toggle(!!id);
    $('#tImgHint').text(id ? '' : '儲存紀錄後即可上傳圖片');
    modalTask = { id: id || 0, status: 0, title: '' };
    refreshModalHeaderButtons();
    loadTplOptions();
    if (id) {
        apiGet('get_task', { id: id }).done(function (res) {
            if (!res.success) { alert(res.message || '讀取失敗'); return; }
            var t = res.data;
            modalTask = { id: t.id, status: parseInt(t.status, 10), title: t.title };
            refreshModalHeaderButtons();
            $('#tTitle').val(t.title);
            $('#tReceived').val(t.received_at);
            $('#tDeadline').val(dtToDateInput(t.deadline));
            var ui = minToUi(t.remind_before_minutes);
            $('#tRemindVal').val(ui.val); $('#tRemindUnit').val(ui.unit);
            $('#tUrgentDays').val(t.urgent_days == null ? '' : t.urgent_days);
            $('#tNote').val(t.note || '');
            if (t.bind_type && t.bind_id) setBind(t.bind_type, t.bind_id, t.bind_label);
            (t.steps || []).forEach(function (s) { $('#stepEditor').append(stepRowHtml(s)); });
            initStepSortable();
            renderModalImages(t.images || []);
            $('#taskModal').modal('show');
        });
    } else {
        $('#stepEditor').append(stepRowHtml());
        initStepSortable();
        $('#taskModal').modal('show');
    }
}
$('#btnNewTask').on('click', function () { openTaskModal(0); });
$('#btnAddStep').on('click', function () { $('#stepEditor').append(stepRowHtml()); initStepSortable(); });

// 預設間隔(工作天)：帶入所有未到達進度的間隔並重算日期，之後仍可逐列手動修改
function applyDefaultInterval(v) {
    var n = 0;
    $('#stepEditor .step-edit-row').each(function () {
        if ($(this).data('reached') == 1) return;
        $(this).find('.step-interval').val(v);
        n++;
    });
    recomputeStepDates();
    return n;
}
// 輸入當下即時帶入（延遲綁定在 document，避免任何重繪造成事件失效）
$(document).on('input change', '#defaultInterval', function () {
    var v = $(this).val();
    if (v !== '') applyDefaultInterval(v);
});
$(document).on('click', '#btnApplyInterval', function () {
    var v = $('#defaultInterval').val();
    if (v === '') { alert('請先輸入預設間隔天數'); $('#defaultInterval').focus(); return; }
    var n = applyDefaultInterval(v);
    if (!n) alert('目前沒有可帶入的進度列（已到達的進度不會變動）');
});
// 依期限自動建議：接收日期→期限的工作天數 ÷ 進度數，平均分配
// 例：7/10 接收、7/20 期限(皆工作日)共 10 工作天，2 個進度 → 各 5 天：第1個 7/15、第2個 7/20(=期限)
$(document).on('click', '#btnSuggestInterval', function () {
    var received = $('#tReceived').val();
    var deadline = $('#tDeadline').val();
    if (!deadline) { alert('請先設定「期限」，才能依期限計算建議間隔'); $('#tDeadline').focus(); return; }
    var count = 0;
    $('#stepEditor .step-edit-row').each(function () {
        if ($(this).data('reached') == 1) return;
        if ($(this).find('.step-name').val().trim() !== '') count++;
    });
    if (!count) { alert('請先輸入進度名稱'); return; }
    var total = workdaysBetween(received, deadline);
    if (total <= 0) { alert('期限需晚於接收日期'); return; }
    var suggest = Math.max(1, Math.floor(total / count));
    $('#defaultInterval').val(suggest);
    applyDefaultInterval(suggest);
    toast('已依期限帶入建議間隔：每個進度 ' + suggest + ' 個工作天（共 ' + total + ' 工作天 ÷ ' + count + ' 個進度）');
});

// 二次確認：必須輸入大寫 OK（避免誤按）
function confirmOK(msg) {
    var v = prompt(msg + '\n\n此操作需要二次確認，請輸入大寫 OK：');
    if (v === null) return false;
    if (v !== 'OK') { alert('輸入不是 OK，已取消操作'); return false; }
    return true;
}

$('#btnModalStatus').on('click', function () {
    if (!modalTask.id) return;
    var to, msg;
    if (modalTask.status == 0) {
        if (!confirmOK('暫停「' + modalTask.title + '」？')) return;
        to = 2; msg = '已暫停';
    } else {
        if (!confirm('把「' + modalTask.title + '」恢復為未完成？')) return;
        to = 0; msg = '已恢復未完成';
    }
    apiPost('set_status', { id: modalTask.id, status: to }).done(function (res) {
        if (!res.success) { alert(res.message || '設定失敗'); return; }
        $('#taskModal').modal('hide');
        toast(msg); loadList();
    });
});

$('#btnModalDelete').on('click', function () {
    if (!modalTask.id) return;
    if (!confirmOK('刪除「' + modalTask.title + '」？進度與小項會一併刪除，無法復原。')) return;
    apiPost('delete_task', { id: modalTask.id }).done(function (res) {
        if (!res.success) { alert(res.message || '刪除失敗'); return; }
        $('#taskModal').modal('hide');
        toast('已刪除'); loadList();
    });
});

// ── 綁定搜尋（自動完成）──────────────────────────────────────────────
function setBind(type, id, label) {
    $('#tBindType').val(type); $('#tBindId').val(id); $('#tBindLabel').val(label);
    $('#tBindKw').prop('disabled', false).val('');
    var chip = $('<span class="label label-primary" style="font-size:12px;">')
        .text((bindTypeName[type] || '') + '：' + label);
    chip.append($('<span style="margin-left:6px;cursor:pointer;">×</span>').on('click', clearBind));
    $('#tBindChosen').empty().append(chip);
}
function clearBind() { $('#tBindId, #tBindLabel').val(''); $('#tBindChosen').empty(); }

$('#tBindType').on('change', function () {
    clearBind();
    var type = $(this).val();
    $('#tBindKw').prop('disabled', !type).val('')
        .attr('placeholder', !type ? '先選類型，再輸入關鍵字搜尋'
            : (type === 'order' ? '輸入料號（或訂單編號）搜尋訂單' : '輸入關鍵字搜尋'));
    $('#tBindSug').hide().empty();
});

var bindSearchTimer = null;
$('#tBindKw').on('input focus', function () {
    var type = $('#tBindType').val();
    if (!type) return;
    var kw = $(this).val();
    clearTimeout(bindSearchTimer);
    bindSearchTimer = setTimeout(function () {
        var actionMap = { bom: 'search_boms', part: 'search_parts', customer: 'search_customers', maker: 'search_makers', order: 'search_orders' };
        apiGet(actionMap[type], { kw: kw }).done(function (res) {
            var $sug = $('#tBindSug').empty();
            (res.data || []).forEach(function (o) {
                var id, label, extra = '';
                if (type === 'bom') { id = o.bom; label = o.bom; extra = o.Client_Name || ''; }
                else if (type === 'part') { id = o.d_id; label = o.D_Setting_Id; extra = o.Drawing_No || ''; }
                else if (type === 'customer') { id = o.customer_id; label = o.customer; extra = o.customer_id; }
                else if (type === 'order') {
                    id = o.Order_id; label = o.Order_oo + '（' + o.d_id + '）';
                    extra = (o.Client_name || '') + (o.Delivery_date ? ' 交期' + fmtDate(o.Delivery_date) : '')
                          + (o.Order_status == 9 ? ' [已結案]' : (o.Order_status == 6 ? ' [暫停/取消]' : ''));
                }
                else { id = o.maker_id_no; label = o.maker_id; extra = o.maker_id_all || ''; }
                var $item = $('<div class="ac-item">').text(label + (extra ? '（' + extra + '）' : ''));
                $item.on('mousedown', function (e) { e.preventDefault(); setBind(type, id, label); $sug.hide(); });
                $sug.append($item);
            });
            $sug.toggle(!!(res.data || []).length);
        });
    }, 180);
});
$(document).on('click', function (e) { if (!$(e.target).closest('.ac-box').length) $('#tBindSug').hide(); });

// ── 附件圖片（縮圖點擊另開原圖、×刪除；比照 Sales_Track_test）────────
function renderModalImages(imgs) {
    var $list = $('#tImgsList').empty();
    (imgs || []).forEach(function (img) {
        var $el = $('<div class="pt-img-wrap">');
        $el.append($('<a target="_blank">').attr('href', img.url)
            .attr('title', img.original_name || img.file_name)
            .append($('<img>').attr('src', img.url).on('error', function () { $el.hide(); })));
        $el.append($('<button type="button" class="pt-img-del" title="刪除此圖片">').html('&times;').on('click', function () {
            if (!confirm('確認刪除此圖片？')) return;
            apiPost('delete_task_image', { img_id: img.img_id }).done(function (res) {
                if (!res.success) { alert(res.message || '刪除失敗'); return; }
                loadTaskImages(modalTask.id);
                loadList();
            });
        }));
        $list.append($el);
    });
}
function loadTaskImages(taskId) {
    if (!taskId) return;
    apiGet('list_task_images', { task_id: taskId }).done(function (res) {
        if (res.success) renderModalImages(res.data || []);
    });
}
$('#tImgUpload').on('change', function () {
    var files = Array.prototype.slice.call(this.files || []);
    $(this).val('');
    var taskId = parseInt($('#tId').val(), 10) || 0;
    if (!taskId || !files.length) return;
    var pending = files.length;
    files.forEach(function (file) {
        var fd = new FormData();
        fd.append('action', 'upload_task_image');
        fd.append('task_id', taskId);
        fd.append('image', file);
        $.ajax({ url: API, type: 'POST', data: fd, processData: false, contentType: false, dataType: 'json' })
            .done(function (res) { if (!res.success) alert((file.name || '圖片') + ' 上傳失敗：' + (res.message || '')); })
            .fail(function () { alert((file.name || '圖片') + ' 上傳失敗，請重試'); })
            .always(function () { if (--pending === 0) { loadTaskImages(taskId); loadList(); } });
    });
});

// ── 儲存 ─────────────────────────────────────────────────────────────
function collectSteps() {
    var steps = [];
    var ok = true;
    $('#stepEditor .step-edit-row').each(function () {
        var $r = $(this);
        var name = $r.find('.step-name').val().trim();
        if (!name) {
            // 已到達的步驟名稱不可清空；未到達的空白列直接略過
            if ($r.data('reached') == 1) { alert('已到達的進度名稱不可空白'); ok = false; return false; }
            return;
        }
        var items = [];
        $r.find('.step-item-row').each(function () {
            var iname = $(this).find('.item-name').val().trim();
            if (iname) items.push({ id: $(this).data('itemId') || 0, name: iname });
        });
        var rv = $r.find('.step-remind-val').val();
        steps.push({
            id: $r.data('stepId') || 0,
            name: name,
            planned_at: $r.find('.step-planned').val() || '',
            remind_before_minutes: rv === '' ? '' : parseInt(rv, 10) * parseInt($r.find('.step-remind-unit').val(), 10),
            items: items
        });
    });
    return ok ? steps : null;
}

$('#btnSaveTask').on('click', function () {
    var title = $('#tTitle').val().trim();
    if (!title) { alert('請輸入標題'); $('#tTitle').focus(); return; }
    var steps = collectSteps();
    if (steps === null) return;
    var rv = $('#tRemindVal').val();
    var params = {
        id: $('#tId').val(),
        title: title,
        received_at: $('#tReceived').val(),
        bind_type: $('#tBindId').val() ? $('#tBindType').val() : '',
        bind_id: $('#tBindId').val(),
        bind_label: $('#tBindLabel').val(),
        deadline: $('#tDeadline').val() || '',
        remind_before_minutes: rv === '' ? '' : parseInt(rv, 10) * parseInt($('#tRemindUnit').val(), 10),
        urgent_days: $('#tUrgentDays').val(),
        note: $('#tNote').val(),
        steps: JSON.stringify(steps)
    };
    var $btn = $(this).prop('disabled', true);
    apiPost('save_task', params).done(function (res) {
        $btn.prop('disabled', false);
        if (!res.success) { alert(res.message || '儲存失敗'); return; }
        $('#taskModal').modal('hide');
        toast('已儲存');
        loadList();
    }).fail(function () { $btn.prop('disabled', false); alert('儲存失敗，請重試'); });
});

// ── 流程範本 ─────────────────────────────────────────────────────────
function loadTplOptions() {
    apiGet('list_templates').done(function (res) {
        templates = res.data || [];
        var $sel = $('#tplApplySelect').empty().append('<option value="">套用範本...</option>');
        templates.forEach(function (t) { $sel.append($('<option>').val(t.id).text(t.template_name)); });
    });
}

$('#tplApplySelect').on('change', function () {
    var id = $(this).val();
    if (!id) return;
    var tpl = templates.find(function (t) { return String(t.id) === String(id); });
    $(this).val('');
    if (!tpl) return;
    var names = [];
    try { names = JSON.parse(tpl.steps_json) || []; } catch (e) {}
    if (!names.length) return;
    // 保留已到達的步驟，未到達的以範本取代
    var $unreached = $('#stepEditor .step-edit-row:not(.reached)');
    var hasContent = $unreached.filter(function () { return $(this).find('.step-name').val().trim() !== ''; }).length > 0;
    if (hasContent && !confirm('套用範本會取代目前尚未到達的進度項目，確定？')) return;
    $unreached.remove();
    names.forEach(function (n) { $('#stepEditor').append(stepRowHtml({ step_name: n })); });
    initStepSortable();
});

$('#btnSaveAsTpl').on('click', function () {
    var names = [];
    $('#stepEditor .step-edit-row .step-name').each(function () {
        var v = $(this).val().trim();
        if (v) names.push(v);
    });
    if (!names.length) { alert('請先輸入進度名稱'); return; }
    var name = prompt('請輸入範本名稱（同名會覆蓋）：');
    if (!name) return;
    apiPost('save_template', { template_name: name, steps_json: JSON.stringify(names) }).done(function (res) {
        if (!res.success) { alert(res.message || '儲存失敗'); return; }
        toast('範本已儲存');
        loadTplOptions();
    });
});

$('#btnTemplates').on('click', function () {
    apiGet('list_templates').done(function (res) {
        var $box = $('#tplList').empty();
        var data = res.data || [];
        if (!data.length) { $box.html('<div class="text-muted">尚無範本</div>'); }
        data.forEach(function (t) {
            var names = [];
            try { names = JSON.parse(t.steps_json) || []; } catch (e) {}
            var $row = $('<div style="display:flex;align-items:center;gap:8px;padding:6px 0;border-bottom:1px dashed #eee;">');
            $row.append($('<b>').text(t.template_name));
            $row.append($('<span style="color:#888;font-size:12px;flex:1;">').text(names.join(' → ')));
            $row.append($('<span style="color:#d9534f;cursor:pointer;"><i class="fa fa-trash"></i></span>').on('click', function () {
                if (!confirm('刪除範本「' + t.template_name + '」？')) return;
                apiPost('delete_template', { id: t.id }).done(function () { $('#btnTemplates').click(); loadTplOptions(); });
            }));
            $box.append($row);
        });
        $('#tplModal').modal('show');
    });
});

// ── 設定（急件天數；管理員另可設定附件儲存路徑）──────────────────────
$('#btnSettings').on('click', function () {
    apiGet('get_settings').done(function (res) {
        $('#setUrgentDays').val(res.urgent_days != null ? res.urgent_days : 3);
        if (res.is_admin) {
            $('#attachPathBox').show();
            $('#setNasDir').val(res.attach_nas_dir || '');
            $('#setUrlDir').val(res.attach_url_dir || '');
        } else {
            $('#attachPathBox').hide();
        }
        $('#settingModal').modal('show');
    });
});
$('#btnSaveSetting').on('click', function () {
    apiPost('save_settings', { urgent_days: $('#setUrgentDays').val() }).done(function (res) {
        if (!res.success) { alert(res.message || '儲存失敗'); return; }
        var finish = function () { $('#settingModal').modal('hide'); toast('已儲存設定'); loadList(); };
        if ($('#attachPathBox').is(':visible')) {
            apiPost('save_attach_path', { nas_dir: $('#setNasDir').val(), url_dir: $('#setUrlDir').val() }).done(function (r2) {
                if (!r2.success) { alert(r2.message || '附件路徑儲存失敗'); return; }
                finish();
            });
        } else finish();
    });
});

// ── 表頭表尾設定（存 localStorage）───────────────────────────────────
function expSetting() {
    try { return JSON.parse(localStorage.getItem('ptask_export_setting') || '{}'); } catch (e) { return {}; }
}
$('#btnExportSetting').on('click', function () {
    var s = expSetting();
    $('#expHeader').val(s.header || ''); $('#expFooter').val(s.footer || '');
    $('#exportSettingModal').modal('show');
});
$('#btnSaveExpSetting').on('click', function () {
    localStorage.setItem('ptask_export_setting', JSON.stringify({ header: $('#expHeader').val(), footer: $('#expFooter').val() }));
    $('#exportSettingModal').modal('hide');
    toast('已儲存表頭表尾設定');
});

// ── 匯出（後端抓全量：目前篩選條件下全部資料，非只當頁）───────────────
var statusName = { 0: '未完成', 1: '已完成', 2: '暫停' };
function stepsSummary(r) {
    var steps = r.steps || [];
    if (!steps.length) return '';
    return steps.map(function (s) {
        return s.step_name + (s.reached_at != null ? '✔' + fmtDt(s.reached_at) : '');
    }).join(' → ');
}
function fetchAllForExport(cb) {
    var p = filterParams();
    p.export = 1;
    apiGet('list_tasks', p).done(function (res) { cb(res.success ? (res.data || []) : []); });
}

$('#btnExportCsv').on('click', function () {
    fetchAllForExport(function (rows) {
        var s = expSetting();
        var lines = [];
        if (s.header) lines.push('"' + s.header.replace(/"/g, '""') + '"');
        lines.push('接收日期,標題,綁定,期限,進度,狀態,備註');
        rows.forEach(function (r) {
            var bind = r.bind_label ? (bindTypeName[r.bind_type] || '') + '：' + r.bind_label : '';
            var cells = [r.received_at || '', r.title, bind, fmtDt(r.deadline), stepsSummary(r), statusName[r.status] || '', r.note || ''];
            lines.push(cells.map(function (c) { return '"' + String(c).replace(/"/g, '""') + '"'; }).join(','));
        });
        if (s.footer) lines.push('"' + s.footer.replace(/"/g, '""') + '"');
        var blob = new Blob(["﻿" + lines.join("\n")], { type: 'text/csv;charset=utf-8;' });
        var a = document.createElement('a');
        a.href = URL.createObjectURL(blob);
        a.download = 'personal_task.csv';
        a.click();
    });
});

$('#btnExportPdf').on('click', function () {
    fetchAllForExport(function (rows) {
        var s = expSetting();
        var body = [['接收日期', '標題', '綁定', '期限', '進度', '狀態', '備註']];
        rows.forEach(function (r) {
            var bind = r.bind_label ? (bindTypeName[r.bind_type] || '') + '：' + r.bind_label : '';
            body.push([r.received_at || '', r.title, bind, fmtDt(r.deadline), stepsSummary(r), statusName[r.status] || '', r.note || '']);
        });
        var content = [];
        if (s.header) content.push({ text: s.header, fontSize: 12, margin: [0, 0, 0, 6] });
        content.push({ text: '個人工作紀錄', fontSize: 14, margin: [0, 0, 0, 10] });
        content.push({ table: { headerRows: 1, body: body } });
        pdfMake.createPdf({
            pageOrientation: 'landscape',
            content: content,
            footer: s.footer ? function () { return { text: s.footer, alignment: 'center', fontSize: 9 }; } : undefined,
            defaultStyle: { fontSize: 9 }
        }).download('personal_task.pdf');
    });
});

// ── 權限說明 ─────────────────────────────────────────────────────────
$('#roleHint').on('click', function () { $('#roleInfoModal').modal('show'); });

// ── 輸入欄三互動（ai-rules/08：雙擊清空、聚焦全選、Enter逐欄/末欄送出）──
(function () {
    var sel = 'input[type=text], input[type=number], input[type=date], input[type=datetime-local], input[type=search]';
    // 1. 雙擊清空；篩選欄雙擊同時解除該欄篩選
    $(document).on('dblclick', sel, function () {
        if (this.readOnly || this.disabled) return;
        $(this).val('');
        if ($(this).data('filter-field')) { state.kw = ''; state.page = 1; loadList(); }
    });
    // 2. 聚焦已有資料自動全選
    $(document).on('focusin', sel, function () {
        var el = this;
        if (el.value) setTimeout(function () { try { el.select(); } catch (e) {} }, 0);
    });
    // 3. Enter 逐欄前進，最後一欄 Enter 送出（textarea 維持換行）
    $(document).on('keydown', sel + ', select', function (e) {
        if (e.key !== 'Enter') return;
        e.preventDefault();
        var $scope = $(this).closest('.modal:visible');
        if (!$scope.length) {
            // 篩選列：Enter 直接套用篩選
            if ($(this).data('filter-field')) { $('#btnApplyFilter').click(); return; }
            $scope = $(this).closest('.filter-bar');
            if (!$scope.length) return;
        }
        var $fields = $scope.find('input:visible:enabled, select:visible:enabled, textarea:visible:enabled')
            .not('[readonly]');
        var idx = $fields.index(this);
        if (idx >= 0 && idx < $fields.length - 1) {
            $fields.eq(idx + 1).focus();
        } else {
            var $submit = $scope.find('[data-enter-submit]:visible');
            if ($submit.length) $submit.first().click();
        }
    });
})();

$(document).ready(function () {
    // 先載入行事曆工作天資料（供剩餘工作天顯示與間隔推算），失敗也照常載入列表（退化為只排除週末）
    loadWorkdayData().always(function () { loadList(); });
});
</script>
<?php endif; ?>
</body>
</html>
