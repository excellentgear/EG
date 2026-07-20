<?php
/**
 * correction_order.php — 異常矯正處理單 (CAR) 主頁（列表 + 開立）
 * 後端：src/store/store_CAR_API.php ｜ 共用：src/common/car_lib.php
 */
ini_set('display_errors', 1);
error_reporting(E_ALL);

session_set_cookie_params(43200);
session_start();

require_once __DIR__ . '/../../src/common/_config.php';
require_once __DIR__ . '/../../src/common/DBConnection.php';
require_once __DIR__ . '/../../src/common/rbac.php';
require_once __DIR__ . '/../../src/common/car_lib.php';

$isPopup = isset($_GET['popup']) && $_GET['popup'] == '1';

if (!isset($_SESSION['userName'])) {
    // 非彈窗才導回登入；彈窗提示即可（避免無權限時的導回迴圈由選單處理）
    header('Location: ../../index.php');
    exit;
}

$conn = new DBConnection();
$pdo  = $conn->getPDO();
$me   = car_current_user($pdo);
$features = rbac_user_features($pdo, (int)$me['id']);

$CAN_VIEW     = rbac_has($features, 'car_view');
$CAN_CREATE   = rbac_has($features, 'car_create');
$CAN_EDIT     = rbac_has($features, 'car_edit');
$CAN_DELETE   = rbac_has($features, 'car_delete');
$CAN_SETTINGS = rbac_has($features, 'car_manage_settings');

$permParts = [];
if ($CAN_VIEW) $permParts[] = '檢閱';
if ($CAN_CREATE) $permParts[] = '開立';
if ($CAN_EDIT) $permParts[] = '修改';
if ($CAN_DELETE) $permParts[] = '刪除';
if ($CAN_SETTINGS) $permParts[] = '設定';
$permBadge = $permParts ? implode('+', $permParts) : '無';
?>
<!DOCTYPE html>
<html lang="zh-Hant">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>異常矯正處理單</title>
    <link href="../../resource/css/bootstrap.css" rel="stylesheet">
    <link href="../../resource/css/font-awesome.css" rel="stylesheet">
    <link href="../../resource/css/custom.css" rel="stylesheet">
    <style>
        input[type=number]::-webkit-inner-spin-button,
        input[type=number]::-webkit-outer-spin-button { -webkit-appearance:none; margin:0; }
        input[type=number]{ -moz-appearance:textfield; appearance:textfield; }
        /* 統計卡片（仿訂單追蹤：色條+懸浮+底紋圖示） */
        .car-stat { flex:1; cursor:pointer; background:#fff; border-radius:8px; padding:14px 15px;
                    box-shadow:0 2px 5px rgba(0,0,0,.05); transition:all .2s ease;
                    border-left:4px solid transparent; position:relative; overflow:hidden; }
        .car-stat:hover{ transform:translateY(-2px); box-shadow:0 5px 15px rgba(0,0,0,.1); }
        .car-stat.active{ transform:scale(1.02); z-index:1; }
        .car-stat .num{ font-size:24px; font-weight:800; line-height:1; color:#2A3F54; }
        .car-stat .lbl{ font-size:12px; color:#888; margin-top:4px; font-weight:600; letter-spacing:1px; }
        .car-stat .stat-icon{ position:absolute; right:12px; top:10px; font-size:32px; opacity:.12; }
        .car-stat[data-card="all"]{ border-left-color:#3498DB; }          .car-stat[data-card="all"].active{ box-shadow:0 0 0 3px #3498DB; }
        .car-stat[data-card="pending_open"]{ border-left-color:#F39C12; } .car-stat[data-card="pending_open"].active{ box-shadow:0 0 0 3px #F39C12; }
        .car-stat[data-card="unclosed"]{ border-left-color:#9B59B6; }    .car-stat[data-card="unclosed"].active{ box-shadow:0 0 0 3px #9B59B6; }
        .car-stat[data-card="rejected"]{ border-left-color:#E74C3C; }    .car-stat[data-card="rejected"].active{ box-shadow:0 0 0 3px #E74C3C; }
        .car-stat[data-card="closed"]{ border-left-color:#1ABB9C; }      .car-stat[data-card="closed"].active{ box-shadow:0 0 0 3px #1ABB9C; }
        /* 面板 / 表格 / Modal 質感 */
        .x_panel{ border-radius:8px; box-shadow:0 2px 5px rgba(0,0,0,.05); border:none; }
        #carTable{ border:1px solid #ddd; }
        #carTable thead th{ background:#2A3F54; color:#fff; border-color:#3d5266; font-weight:600; }
        #carTable tbody tr:hover{ background:#f0f7ff; }
        .modal-content{ border-radius:8px; overflow:hidden; }
        .modal-header{ background:linear-gradient(135deg,#1a252f,#2980b9); color:#fff; }
        .modal-header .close{ color:#fff; opacity:.7; }
        .btn{ border-radius:5px; }
        .ac-box{ position:relative; }
        .ac-list{ position:absolute; z-index:1000; left:0; right:0; background:#fff; border:1px solid #ccc; border-top:none; max-height:260px; overflow:auto; display:none; }
        .ac-item{ padding:6px 10px; cursor:pointer; border-bottom:1px solid #f0f0f0; font-size:13px; }
        .ac-item:hover{ background:#f5f5f5; }
        .ac-item.kb{ background:#d9edf7; }  /* 鍵盤選取高亮 */
        .tag-c{ display:inline-block; min-width:26px; text-align:center; border-radius:3px; padding:0 4px; font-size:11px; color:#fff; margin-right:6px; }
        .tag-c.客{ background:#3498db; } .tag-c.廠{ background:#e67e22; }
        /* 設定下拉：只提升按鈕群層級(勿抬整條 page-title，避免蓋住頂欄/側欄點擊)；低於 modal(1040+) */
        .page-title .btn-group{ position:relative; z-index:6; }
        .page-title .dropdown-menu{ right:0; left:auto; }
        /* 保險：頂欄/側欄層級高於內容區，確保永遠可點 */
        .top_nav{ position:relative; z-index:30; }
        .left_col, .nav_menu{ position:relative; z-index:31; }
        /* 簽章印章（回墨印風格）CSS 已由 resource/js/eg_stamp.js 自動注入，不再於此重複定義 */
        /* 客戶/廠商 小標籤（淡底細框 chip） */
        .cp-tag{ display:inline-block; padding:0 5px; border-radius:3px; font-size:10px; line-height:16px; margin-right:4px; white-space:nowrap; vertical-align:1px; }
        .cp-cust{ background:#eaf4fd; color:#2980b9; border:1px solid #bcdff5; }
        .cp-maker{ background:#fdf3e7; color:#ca6f1e; border:1px solid #f5d9b8; }
        .resp-chip{ display:inline-flex; align-items:center; gap:6px; padding:4px 10px; margin:0 6px 6px 0; border-radius:16px; border:1px solid #bbb; background:#fafafa; font-size:13px; }
        .resp-chip .x{ color:#d9534f; cursor:pointer; font-weight:bold; }
        /* 附件 chips */
        .att-box{ margin-top:6px; }
        .att-chip{ display:inline-flex; align-items:center; gap:5px; background:#f0f4f7; border:1px solid #dde6ec; border-radius:7px; padding:2px 8px; font-size:12px; margin:0 5px 4px 0; }
        .att-chip a{ color:#2980b9; text-decoration:none; }
        .att-chip a:hover{ text-decoration:underline; }
        .att-chip .att-del{ color:#d9534f; cursor:pointer; font-weight:bold; }
        .st-badge{ border-radius:10px; padding:2px 9px; font-size:11px; font-weight:600; white-space:nowrap; display:inline-block; }
        .st-applying{background:#FCF3CF;color:#7D6608;border:1px solid #F9E79F;}
        .st-apprej{background:#F6DDCC;color:#A04000;border:1px solid #EDBB99;}
        .st-open{background:#FFF3CD;color:#856404;border:1px solid #FFECB5;}
        .st-assigned{background:#D6EAF8;color:#21618C;border:1px solid #AED6F1;}
        .st-replying{background:#E8DAEF;color:#6C3483;border:1px solid #D2B4DE;}
        .st-primary{background:#D1F2EB;color:#148F77;border:1px solid #A3E4D7;}
        .st-final{background:#FCF3CF;color:#9A7D0A;border:1px solid #F7DC6F;}
        .st-closed{background:#D5F5E3;color:#1E8449;border:1px solid #ABEBC6;}
        .st-rejected{background:#FADBD8;color:#922B21;border:1px solid #F1948A;}
        .req::after{ content:" *"; color:#d9534f; }
        .car-warm{ background:#FBEEE6; border:1px solid #F3D9C4; color:#8A5A2B; border-radius:6px; padding:10px 12px; margin-top:8px; }
        .car-warm .btn{ margin-top:6px; }
        #carTable td, #carTable th{ vertical-align:middle; font-size:13px; }
        .timeline-mini{ font-size:12px; color:#888; }
        /* 無資料時也維持版面高度，避免頁尾緊貴上來 */
        .x_panel{ min-height:60vh; }
        #carBody td.text-center.text-muted{ padding:80px 0; }
    </style>
</head>
<body class="<?php echo $isPopup ? 'popup-mode' : 'nav-sm'; ?>">
<div class="container body">
  <div class="main_container">
    <?php if (!$isPopup) include '../partPage/sideAndTopBarMenu.html'; ?>

    <div class="<?php echo $isPopup ? 'col-md-12' : 'right_col'; ?>" role="main"<?php echo $isPopup ? ' style="width:100%;float:none;padding:15px;"' : ''; ?>>
      <div class="page-title">
        <div class="title_left">
          <h3>異常矯正處理單
            <small>（權限：<?php echo htmlspecialchars($permBadge); ?>）
              <a href="#" id="btn-perm-help" title="各角色權限說明"><i class="fa fa-question-circle"></i></a>
            </small>
          </h3>
        </div>
        <?php if ($CAN_SETTINGS): ?>
        <div class="title_right"><div class="pull-right">
          <div class="btn-group">
            <button class="btn btn-default btn-sm dropdown-toggle" data-toggle="dropdown"><i class="fa fa-cog"></i> 設定 <span class="caret"></span></button>
            <ul class="dropdown-menu dropdown-menu-right">
              <li><a href="#" id="btn-flow-setting"><i class="fa fa-sitemap"></i> 簽核流程設定</a></li>
              <li><a href="#" id="btn-role-setting"><i class="fa fa-key"></i> 權限設定（角色）</a></li>
            </ul>
          </div>
        </div></div>
        <?php endif; ?>
      </div>
      <div class="clearfix"></div>

      <?php if (!$CAN_VIEW): ?>
        <div class="alert alert-danger"><i class="fa fa-ban"></i> 您沒有「異常矯正處理單」的檢閱權限，請洽管理員於 <b>權限設定（角色 → 異常矯正處理單）</b> 開通。<br>
        <span class="text-muted" style="font-size:12px;">若您是某張單的相關人員（被指派回覆/簽核），請從<b>置頂欄通知</b>點開該單即可填寫；填寫送出前都可由同一則通知回來修改。</span></div>
      <?php else: ?>

      <!-- 統計卡片 -->
      <div style="display:flex;gap:12px;margin-bottom:15px;">
        <div class="car-stat active" data-card="all"><i class="fa fa-files-o stat-icon"></i><div class="num" id="stat-all">0</div><div class="lbl">全部</div></div>
        <div class="car-stat" data-card="pending_open"><i class="fa fa-hourglass-half stat-icon"></i><div class="num" id="stat-pending">0</div><div class="lbl">待核准</div></div>
        <div class="car-stat" data-card="unclosed"><i class="fa fa-cogs stat-icon"></i><div class="num" id="stat-unclosed">0</div><div class="lbl">已開立未結案</div></div>
        <div class="car-stat" data-card="rejected"><i class="fa fa-exclamation-circle stat-icon"></i><div class="num" id="stat-rejected">0</div><div class="lbl">不可結案</div></div>
        <div class="car-stat" data-card="closed"><i class="fa fa-check-circle stat-icon"></i><div class="num" id="stat-closed">0</div><div class="lbl">已結案</div></div>
      </div>

      <div class="row"><div class="col-md-12"><div class="x_panel"><div class="x_content">
        <!-- 篩選列 -->
        <div class="row" style="margin-bottom:8px;">
          <div class="col-md-2">
            <select id="f-dept" class="form-control input-sm"><option value="">責任單位(全部)</option></select>
          </div>
          <div class="col-md-2">
            <select id="f-source" class="form-control input-sm">
              <option value="">來源(全部)</option>
              <option value="QA">品質異常處理單</option>
              <option value="IR">客戶退貨單</option>
              <option value="OTHER">其他</option>
            </select>
          </div>
          <div class="col-md-3">
            <input type="text" id="f-kw" class="form-control input-sm" placeholder="搜尋單號/異常說明/責任單位...">
          </div>
          <div class="col-md-5 text-right">
            <label class="text-muted" style="font-weight:normal;font-size:12px;">每頁</label>
            <select id="f-size" class="input-sm" style="width:auto;display:inline-block;">
              <option>5</option><option selected>10</option><option>20</option><option>50</option>
            </select>
            <?php if ($CAN_CREATE): ?>
            <button class="btn btn-primary btn-sm" id="btn-new"><i class="fa fa-plus"></i> 開立</button>
            <?php endif; ?>
            <button class="btn btn-default btn-sm" id="btn-stats" title="統計表"><i class="fa fa-bar-chart"></i></button>
            <button class="btn btn-default btn-sm" id="btn-csv" title="匯出 CSV"><i class="fa fa-file-excel-o"></i></button>
            <button class="btn btn-default btn-sm" id="btn-print-list" title="總表列印/PDF"><i class="fa fa-print"></i></button>
            <span id="pager" style="margin-left:8px;"></span>
          </div>
        </div>

        <div class="table-responsive">
          <table class="table table-bordered table-hover" id="carTable">
            <thead><tr>
              <th style="width:115px;">表單編號</th>
              <th style="width:85px;">來源</th>
              <th style="width:140px;">客戶/供應商</th>
              <th style="width:110px;">料號</th>
              <th style="width:110px;">責任單位</th>
              <th style="width:95px;">目前狀態</th>
              <th style="width:300px;">最新動態</th>
              <th style="width:85px;">填表人</th>
              <th style="width:70px;">操作</th>
            </tr></thead>
            <tbody id="carBody"><tr><td colspan="9" class="text-center text-muted">載入中…</td></tr></tbody>
          </table>
        </div>
      </div></div></div></div>

      <?php endif; /* CAN_VIEW */ ?>
    </div>
  </div>
</div>

<!-- 開立 Modal -->
<div class="modal fade" id="createModal" tabindex="-1" role="dialog" data-backdrop="static">
 <div class="modal-dialog modal-lg" role="document"><div class="modal-content">
  <div class="modal-header">
    <button type="button" class="close" data-dismiss="modal">&times;</button>
    <h4 class="modal-title"><i class="fa fa-file-text-o"></i> 開立異常矯正處理單
      <small class="text-muted">（表單編號於建立時自動產生）</small></h4>
  </div>
  <div class="modal-body">
    <!-- 異常來源（三選一） -->
    <div class="form-group">
      <label class="req">異常來源</label>
      <div>
        <label class="radio-inline"><input type="radio" name="src" value="QA"> 品質異常處理單</label>
        <label class="radio-inline"><input type="radio" name="src" value="IR"> 客戶退貨單</label>
        <label class="radio-inline"><input type="radio" name="src" value="OTHER"> 其他</label>
      </div>
      <div id="src-qa" class="ac-box" style="display:none;margin-top:6px;">
        <input type="text" id="src-qa-kw" class="form-control input-sm" placeholder="輸入品質異常單號搜尋…" autocomplete="off">
        <div class="ac-list" id="src-qa-list"></div>
        <div class="text-muted" style="font-size:12px;" id="src-qa-pick"></div>
      </div>
      <div id="src-ir" class="ac-box" style="display:none;margin-top:6px;">
        <input type="text" id="src-ir-kw" class="form-control input-sm" placeholder="輸入退貨單號/客戶搜尋…" autocomplete="off">
        <div class="ac-list" id="src-ir-list"></div>
        <div class="text-muted" style="font-size:12px;" id="src-ir-pick"></div>
      </div>
      <div id="src-other" style="display:none;margin-top:6px;">
        <input type="text" id="src-other-no" class="form-control input-sm" placeholder="對應單號" style="margin-bottom:6px;">
        <input type="text" id="src-other-desc" class="form-control input-sm" placeholder="來源說明">
      </div>
    </div>

    <div class="row">
      <div class="col-md-4">
        <div class="form-group"><label>發現日期</label>
          <input type="date" id="f-found-date" class="form-control input-sm"></div>
      </div>
      <div class="col-md-8" id="opener-block">
        <div class="form-group"><label>開立職務 <small class="text-muted">(兼任時請選以哪個職務開立)</small></label>
          <select id="f-opener" class="form-control input-sm"></select>
          <div id="opener-hint" class="text-muted" style="font-size:12px;margin-top:3px;"></div>
        </div>
      </div>
    </div>

    <div class="row">
      <div class="col-md-6">
        <div class="form-group ac-box">
          <label>客戶／供應商 <small class="text-muted">(可單獨填寫)</small></label>
          <input type="text" id="cp-kw" class="form-control input-sm" placeholder="輸入部分名稱或代號…（列表標示 [客]/[廠]）" autocomplete="off">
          <div class="ac-list" id="cp-list"></div>
          <div style="font-size:12px;margin-top:3px;" id="cp-pick"></div>
        </div>
      </div>
      <div class="col-md-6">
        <div class="form-group ac-box">
          <label>料號 <small class="text-muted">(綁定後自動帶客戶，客戶/供應商欄可改選廠商)</small></label>
          <input type="text" id="part-kw" class="form-control input-sm" placeholder="輸入部分料號/圖號/規格…" autocomplete="off">
          <div class="ac-list" id="part-list"></div>
          <div style="font-size:12px;margin-top:3px;" id="part-pick"></div>
        </div>
      </div>
    </div>

    <div class="row">
      <div class="col-md-6">
        <div class="form-group ac-box">
          <label>廠內製令單號 <small class="text-muted">(綁定 BOM)</small></label>
          <input type="text" id="wo-kw" class="form-control input-sm" placeholder="輸入製令/BOM/料號/客戶…" autocomplete="off">
          <div class="ac-list" id="wo-list"></div>
          <div style="font-size:12px;margin-top:3px;" id="wo-pick"></div>
        </div>
      </div>
      <div class="col-md-3" id="qty-wrap" style="display:none;">
        <div class="form-group"><label>數量</label>
          <input type="number" id="f-qty" class="form-control input-sm" step="any"></div>
      </div>
    </div>

    <!-- 責任單位（可多選 → 拆單） -->
    <div class="form-group" id="resp-block" style="border-top:1px dashed #ddd;padding-top:8px;">
      <label>責任單位 <small class="text-muted">(非必填；可多選部門/人員/廠商/本公司，將依數量拆成獨立單)</small></label>
      <div>
        <button type="button" class="btn btn-default btn-xs" id="add-dept"><i class="fa fa-sitemap"></i> +部門/人員</button>
        <button type="button" class="btn btn-default btn-xs" id="add-maker"><i class="fa fa-industry"></i> +廠商</button>
      </div>
      <!-- 部門選擇器 -->
      <div id="pick-dept" class="well well-sm" style="display:none;margin-top:6px;">
        <div class="row">
          <div class="col-md-5"><select id="pd-dept" class="form-control input-sm"><option value="">選擇部門…</option></select></div>
          <div class="col-md-5"><select id="pd-person" class="form-control input-sm"><option value="">（整個部門，可不選人員）</option></select></div>
          <div class="col-md-2"><button type="button" class="btn btn-success btn-sm btn-block" id="pd-add">加入</button></div>
        </div>
      </div>
      <!-- 廠商選擇器 -->
      <div id="pick-maker" class="well well-sm ac-box" style="display:none;margin-top:6px;">
        <input type="text" id="pm-kw" class="form-control input-sm" placeholder="搜尋廠商…" autocomplete="off">
        <div class="ac-list" id="pm-list"></div>
      </div>
      <div id="resp-chips" style="margin-top:8px;"></div>
      <div class="text-muted" style="font-size:12px;" id="resp-note">目前 0 個責任單位 → 將建立 1 張單</div>
    </div>

    <div class="form-group">
      <label class="req">異常說明</label>
      <textarea id="f-desc" class="form-control" rows="3" placeholder="請填寫異常說明（必填，建立時將自動由您簽章）"></textarea>
      <div style="margin-top:6px;">
        <label class="btn btn-default btn-xs" style="margin:0;"><i class="fa fa-upload"></i> 上傳附件
          <input type="file" id="create-att-input" style="display:none;"></label>
        <span class="text-muted" style="font-size:12px;">（jpg/png/pdf/office，20MB 內；多選責任單位拆單時每張單都會帶附件）</span>
        <div id="create-att-list" style="margin-top:4px;"></div>
      </div>
    </div>
  </div>
  <div class="modal-footer">
    <span class="text-muted pull-left" id="create-msg"></span>
    <button type="button" class="btn btn-default" data-dismiss="modal">取消</button>
    <button type="button" class="btn btn-primary" id="btn-create-submit"><i class="fa fa-check"></i> 建立</button>
  </div>
 </div></div>
</div>

<!-- 檢視 Modal -->
<div class="modal fade" id="viewModal" tabindex="-1" role="dialog">
 <div class="modal-dialog modal-lg"><div class="modal-content">
  <div class="modal-header"><button type="button" class="close" data-dismiss="modal">&times;</button>
    <h4 class="modal-title">單據檢視</h4></div>
  <div class="modal-body" id="view-body">載入中…</div>
  <div class="modal-footer">
    <button type="button" class="btn btn-default pull-left" id="btn-print-one"><i class="fa fa-print"></i> 列印 / PDF</button>
    <button type="button" class="btn btn-default" data-dismiss="modal">關閉</button>
  </div>
 </div></div>
</div>

<!-- 簽核流程設定 Modal -->
<div class="modal fade" id="flowModal" tabindex="-1" role="dialog"><div class="modal-dialog modal-lg"><div class="modal-content">
  <div class="modal-header"><button type="button" class="close" data-dismiss="modal">&times;</button>
    <h4 class="modal-title"><i class="fa fa-sitemap"></i> 異常矯正處理單 — 簽核流程設定</h4></div>
  <div class="modal-body">
    <div class="row">
      <div class="col-md-6">
        <div class="form-group">
          <label>主管職稱層級門檻 <small class="text-muted">(此層級(含)以上視為主管：可核准開立申請、指派回覆人、首要簽核)</small></label>
          <select id="fs-level" class="form-control input-sm">
            <option value="1">一階主管（僅最高階主管）</option>
            <option value="2">二階主管（含）以上</option>
            <option value="3">三階主管（含）以上</option>
          </select>
        </div>
        <div class="form-group">
          <label>最終決策者（總經理）職位</label>
          <select id="fs-final-pos" class="form-control input-sm"><option value="">請選擇職位…</option></select>
          <div class="text-muted" style="font-size:12px;">擔任此職位者即為最終決策者，可勾選結案/不可結案。</div>
        </div>
        <div class="form-group">
          <label>附件儲存根路徑</label>
          <input type="text" id="fs-attach-path" class="form-control input-sm">
        </div>
        <div class="form-group">
          <label>列印表頭文字 <small class="text-muted">(顯示於列印頁最上方)</small></label>
          <input type="text" id="fs-print-header" class="form-control input-sm" placeholder="例：超正齒輪科技有限公司　異常矯正處理單">
        </div>
        <div class="form-group">
          <label>列印表尾文字 <small class="text-muted">(顯示於列印頁最下方)</small></label>
          <input type="text" id="fs-print-footer" class="form-control input-sm" placeholder="例：2-QA-01-04">
        </div>
      </div>
      <div class="col-md-6">
        <div class="form-group">
          <label>生管部門 <small class="text-muted">(單選；責任單位為廠商時，由此部門的主管簽核)</small></label>
          <div id="fs-pm-depts" style="max-height:120px;overflow:auto;border:1px solid #eee;border-radius:4px;padding:6px;"></div>
        </div>
        <div class="form-group">
          <label>管理課（扣款判定課室） <small class="text-muted">(單選；結案後由此課室人員做扣款判定)</small></label>
          <div id="fs-admin-depts" style="max-height:120px;overflow:auto;border:1px solid #eee;border-radius:4px;padding:6px;"></div>
        </div>
        <div class="form-group">
          <label>指定判定人員 <small class="text-muted">(最多 2 人；人員固定取自上方勾選之管理課；有指定時僅此人員可判定，未指定則課室成員皆可)</small></label>
          <div class="form-inline" style="margin-bottom:4px;">
            <select id="fs-au-user" class="form-control input-sm" style="width:60%;"><option value="">請先勾選管理課…</option></select>
            <button type="button" class="btn btn-success btn-sm" id="fs-au-add">加入</button>
          </div>
          <div id="fs-au-chips"></div>
        </div>
      </div>
    </div>
  </div>
  <div class="modal-footer">
    <span class="text-muted pull-left" id="fs-msg"></span>
    <button type="button" class="btn btn-default" data-dismiss="modal">取消</button>
    <button type="button" class="btn btn-primary" id="btn-flow-save"><i class="fa fa-check"></i> 儲存設定</button>
  </div>
</div></div></div>

<!-- 權限設定（角色）Modal -->
<div class="modal fade" id="roleModal" tabindex="-1" role="dialog"><div class="modal-dialog modal-lg"><div class="modal-content">
  <div class="modal-header"><button type="button" class="close" data-dismiss="modal">&times;</button>
    <h4 class="modal-title"><i class="fa fa-key"></i> 異常矯正處理單 — 權限設定（角色）</h4></div>
  <div class="modal-body">
    <p class="text-muted" style="font-size:12px;">在此建立/命名角色並勾選其功能；使用者與角色的對應請至 <b>人員權限設定（user_permissions）</b>。系統管理員角色(admin)固定擁有全部權限，不可修改。</p>
    <div class="row">
      <div class="col-md-5">
        <div class="input-group input-group-sm" style="margin-bottom:6px;">
          <input type="text" id="new-role-name" class="form-control" placeholder="新角色名稱…">
          <span class="input-group-btn"><button class="btn btn-success" id="btn-add-role">新增</button></span>
        </div>
        <div class="list-group" id="role-list" style="max-height:320px;overflow:auto;"></div>
      </div>
      <div class="col-md-7">
        <div id="role-feat-area" style="display:none;">
          <h5>角色「<span id="rf-role-name"></span>」的功能</h5>
          <div id="rf-checks"></div>
          <div style="margin-top:10px;">
            <button class="btn btn-primary btn-sm" id="btn-save-feats"><i class="fa fa-check"></i> 儲存功能</button>
            <button class="btn btn-default btn-sm" id="btn-rename-role">改名</button>
            <button class="btn btn-danger btn-sm pull-right" id="btn-del-role"><i class="fa fa-trash"></i> 刪除角色</button>
            <span class="text-muted" id="rf-msg" style="margin-left:8px;"></span>
          </div>
        </div>
        <div id="role-feat-empty" class="text-muted">← 請於左側選擇一個角色</div>
      </div>
    </div>
  </div>
</div></div></div>

<!-- 統計表 Modal -->
<div class="modal fade" id="statsModal" tabindex="-1"><div class="modal-dialog modal-lg"><div class="modal-content">
  <div class="modal-header"><button type="button" class="close" data-dismiss="modal">&times;</button>
    <h4 class="modal-title"><i class="fa fa-bar-chart"></i> 異常矯正處理單 — 統計表</h4></div>
  <div class="modal-body" id="stats-body">載入中…</div>
  <div class="modal-footer">
    <button type="button" class="btn btn-default pull-left" onclick="window.printStats&&window.printStats()"><i class="fa fa-print"></i> 列印 / PDF</button>
    <button type="button" class="btn btn-default" data-dismiss="modal">關閉</button>
  </div>
</div></div></div>

<!-- 權限說明 Modal -->
<div class="modal fade" id="permHelp" tabindex="-1"><div class="modal-dialog"><div class="modal-content">
  <div class="modal-header"><button type="button" class="close" data-dismiss="modal">&times;</button>
    <h4 class="modal-title">角色權限說明</h4></div>
  <div class="modal-body">
    <ul>
      <li><b>檢閱</b>：可查看列表與單據內容。</li>
      <li><b>開立</b>：可開立新的異常矯正處理單。</li>
      <li><b>修改</b>：可修改單據內容（限自己開立之單據；非開立人不可修改他人單據，唯系統管理員例外）。</li>
      <li><b>刪除</b>：可刪除單據（限自己開立之單據；非開立人不可刪除他人單據，唯系統管理員例外）。</li>
      <li><b>設定</b>：可管理主管職稱層級、生管部門、最終決策者、附件路徑等。</li>
    </ul>
    <p class="text-muted">簽核流程之權限（指派、主管簽核、總經理裁決）另依責任單位主管 / 職位設定判定。</p>
    <hr style="margin:10px 0;">
    <h5><b><i class="fa fa-sitemap"></i> 審核流程說明</b></h5>
    <ol style="padding-left:18px;line-height:1.9;">
      <li><b>開立</b>：主管職（依「簽核流程設定」的職稱層級門檻，兼任者以所選職務判定）直接開立並產生單號；一般職送出<b>開立申請</b>，待所屬部門主管核准後才成立配號（核准前申請人可<b>撤回</b>，退回/撤回可修改後重送）。責任單位可多選，將自動拆成多張同事件單。</li>
      <li><b>指派回覆人</b>：責任單位為部門→該部門主管其中一人指派回覆人（一人指派後其他主管即不可再指派）；開單時已指定人員者，該人員直接為回覆人免指派；責任單位為廠商→由生管代填、簽章壓廠商名。</li>
      <li><b>回覆填寫</b>：回覆人填「異常原因分析、矯正措施、預防措施」三段，各段簽章（修改需取消簽章重簽），三段皆簽後<b>送出</b>。回覆人本人不可由代理人代填。送出前可隨時由置頂欄通知回來修改。</li>
      <li><b>主管簽核</b>：責任單位主管（廠商責任＝生管主管）簽核通過後送總經理。</li>
      <li><b>總經理裁決</b>：<b>結案</b>（自動簽章、押今日結案日，並一併判定扣款金額，未填＝不扣款）或 <b>不可結案</b>（必填原因，自動產生退件 R 單，表頭帶入、三段需重填）。</li>
      <li><b>通知</b>：各節點自動通知相關人員；「指派您回覆」通知在填寫送出前持續顯示；同單舊進度通知自動已讀只留最新。</li>
    </ol>
    <p class="text-muted" style="font-size:12px;">※ 扣款判定與不可結案原因為機密，僅扣款判定人員與最終決策者本人可見。</p>
  </div>
</div></div></div>

<?php if (!$isPopup) include '../partPage/footer.html'; ?>
<script src="../../resource/js/jquery.min.js"></script>
<script src="../../resource/js/bootstrap.min.js"></script>
<script src="../../resource/js/eg_stamp.js"></script>
<?php if (!$isPopup): ?><script src="../../resource/js/custom.min.js"></script><?php endif; ?>
<script>
(function(){
  var API = '../../src/store/store_CAR_API.php';
  var CAN_CREATE = <?php echo $CAN_CREATE ? 'true':'false'; ?>;
  var CAN_DELETE = <?php echo $CAN_DELETE ? 'true':'false'; ?>;
  var IS_ADMIN   = <?php echo rbac_has($features, 'all') ? 'true':'false'; ?>;
  var ME_ID      = <?php echo (int)$me['id']; ?>;
  var CAN_VIEW   = <?php echo $CAN_VIEW ? 'true':'false'; ?>;
  var OPEN_ID    = <?php echo (int)($_GET['open_id'] ?? 0); ?>;   // 通知直開的單據 id（當事人無 car_view 也可開）
  var state = { card:'all', page:1, size:10 };
  var resp = [];            // 責任單位選擇陣列
  var sel = {};             // 表單暫存綁定
  var myPositions = [];     // 目前使用者的(部門,職務)身分

  function loadMyPositions(){
    api('my_positions').done(function(r){
      myPositions = (r&&r.success&&r.data)?r.data:[];
      var h='';
      if(!myPositions.length){ h='<option value="-1">（無職務身分，直接開立）</option>'; }
      else myPositions.forEach(function(p,i){ h+='<option value="'+i+'">'+esc(p.dept_name)+' / '+esc(p.position_name)+'（'+(p.is_supervisor?'主管職':'一般職')+'）</option>'; });
      $('#f-opener').html(h); updateOpenerHint();
    });
  }
  function selectedOpener(){ var v=$('#f-opener').val(); if(v==='-1'||v===null||v==='') return null; return myPositions[parseInt(v,10)]||null; }
  function updateOpenerHint(){
    var p=selectedOpener();
    if(!p || p.is_supervisor){ $('#opener-hint').html(p?'<span class="text-success">主管職 → 直接開立並產生單號。</span>':'將直接開立並產生單號。'); $('#btn-create-submit').html('<i class="fa fa-check"></i> 建立'); }
    else { $('#opener-hint').html('<span class="text-warning">一般職 → 送出後需「'+esc(p.dept_name)+'」主管核准才成立（核准時才配號）。</span>'); $('#btn-create-submit').html('<i class="fa fa-paper-plane"></i> 送出申請'); }
  }

  function api(action, data){ data = data||{}; data.action = action; return $.post(API, data, null, 'json'); }
  function esc(s){ return $('<div>').text(s==null?'':s).html(); }

  // ---------- 列表 ----------
  function fetchPage(p){
    state.page = p||1;
    state.size = parseInt($('#f-size').val(),10)||10;
    api('load_page_data', {
      card: state.card, page: state.page, size: state.size,
      resp: $('#f-dept').val()||'', source_type: $('#f-source').val()||'', kw: $('#f-kw').val()||''
    }).done(function(r){
      if(!r || !r.success){ $('#carBody').html('<tr><td colspan="9" class="text-danger text-center">'+esc(r&&r.message||'載入失敗')+'</td></tr>'); return; }
      renderStats(r.stats); renderRows(r.rows); renderPager(r);
    }).fail(function(){ $('#carBody').html('<tr><td colspan="9" class="text-danger text-center">連線失敗</td></tr>'); });
  }
  function renderStats(s){ s=s||{}; $('#stat-all').text(s.all||0); $('#stat-pending').text(s.pending||0); $('#stat-unclosed').text(s.unclosed||0); $('#stat-rejected').text(s.rejected||0); $('#stat-closed').text(s.closed||0); }
  // 簽章印章 SVG 產生（carStamp/stampRow）已抽成共用檔 resource/js/eg_stamp.js（EGStamp.stamp/.row）
  // isDeputy=true 時右下角加「代」字（代理人代簽）；注意：責任單位回覆人之三段簽章「禁止代理」——isDeputy 僅適用主管/總經理層級簽核。
  // 日期時間顯示格式 2026.07.08 16:31（共用：openView / renderDecision 皆使用）
  function fmtDT(s){ return s ? String(s).substring(0,16).replace(/-/g,'.') : ''; }

  // ---------- 附件 ----------
  var curViewId = 0;   // 目前開啟檢視的單據 id（附件上傳/刪除用）
  function attList(atts, sec, canUp){
    var items=(atts||[]).filter(function(a){ return a.field_type===sec; });
    var h='<div class="att-box">';
    items.forEach(function(a){
      var delBtn = ((a.created_by|0)===ME_ID) ? '<span class="att-del" data-id="'+a.id+'" title="刪除">&times;</span>' : '';
      h+='<span class="att-chip"><a href="'+API+'?action=download_attachment&id='+a.id+'" target="_blank"><i class="fa fa-paperclip"></i> '+esc(a.original_filename||a.file_name)+'</a>'+delBtn+'</span>';
    });
    if(canUp) h+='<label class="btn btn-default btn-xs" style="margin:0;"><i class="fa fa-upload"></i> 上傳附件<input type="file" class="att-up" data-sec="'+sec+'" style="display:none;"></label>';
    if(!items.length && !canUp) h+='<span class="text-muted" style="font-size:12px;">（無附件）</span>';
    h+='</div>'; return h;
  }
  function uploadAttachment(file, sec, carId, tempKey, done){
    var fd=new FormData();
    fd.append('action','upload_attachment'); fd.append('field_type',sec); fd.append('file',file);
    if(carId) fd.append('car_id',carId); else fd.append('temp_key',tempKey);
    $.ajax({url:API,method:'POST',data:fd,processData:false,contentType:false,dataType:'json'})
      .done(function(r){ if(!r||!r.success){ alert(r&&r.message||'上傳失敗'); } done&&done(r); })
      .fail(function(xhr){ alert((xhr.responseJSON&&xhr.responseJSON.message)||'上傳失敗'); done&&done(null); });
  }
  $(function(){
    // 檢視 Modal 內：分區上傳 / 刪除（事件委派一次綁定）
    $('#view-body').on('change','.att-up',function(){
      var f=this.files[0]; if(!f) return; var sec=$(this).data('sec');
      uploadAttachment(f, sec, curViewId, '', function(r){ if(r&&r.success) openView(curViewId); });
      $(this).val('');
    });
    $('#view-body').on('click','.att-del',function(){
      var aid=$(this).data('id'); if(!confirm('確定刪除此附件？')) return;
      api('delete_attachment',{id:aid}).done(function(r){ if(r&&r.success) openView(curViewId); else alert(r&&r.message||'刪除失敗'); })
        .fail(function(xhr){ alert((xhr.responseJSON&&xhr.responseJSON.message)||'刪除失敗'); });
    });
    // 開單 Modal：異常說明附件（temp_key 暫存，建立時自動綁定；拆多單各複製一份）
    $('#create-att-input').on('change', function(){
      var f=this.files[0]; if(!f) return;
      uploadAttachment(f, 'desc', 0, sel.temp_key, function(r){
        if(r&&r.success){ $('#create-att-list').append('<span class="att-chip"><i class="fa fa-paperclip"></i> '+esc(r.original_filename)+'</span>'); }
      });
      $(this).val('');
    });
  });

  // 客戶/廠商 按鈕式標籤 + 名稱（disp 為後端 "[客] 名稱" 格式，去前綴後配標籤）
  function cpShow(type, disp){
    if(!disp) return '';
    var name = String(disp).replace(/^\[.\]\s*/,'');
    if(type==='customer') return '<span class="cp-tag cp-cust">客戶</span>'+esc(name);
    if(type==='maker')    return '<span class="cp-tag cp-maker">廠商</span>'+esc(name);
    return esc(name);
  }
  function stBadge(st, lbl){
    var m={applying:'st-applying',app_rejected:'st-apprej',open:'st-open',assigned:'st-assigned',replying:'st-replying',pending_primary:'st-primary',pending_final:'st-final',closed:'st-closed',rejected:'st-rejected',draft:'st-open'};
    return '<span class="st-badge '+(m[st]||'st-open')+'">'+esc(lbl)+'</span>';
  }
  function renderRows(rows){
    if(!rows || !rows.length){ $('#carBody').html('<tr><td colspan="9" class="text-center text-muted">查無資料</td></tr>'); return; }
    var h='';
    rows.forEach(function(o){
      var reissue = o.reissue_of ? ' <span class="label label-warning">退件R'+(o.reissue_seq||'')+'</span>' : '';
      var latest = o.latest ? ('<div class="timeline-mini">'+esc((o.latest.created_at||'').substring(5,16).replace(/-/g,'.'))+' '+esc(o.latest.actor_name||'')+'：'+esc(o.latest.note||o.latest.action||'')+'</div>') : '';
      var noCell = o.car_no ? ('<b>'+esc(o.car_no)+'</b>') : '<span class="text-muted">（未配號）</span>';
      h += '<tr>'
        + '<td>'+noCell+reissue+'</td>'
        + '<td>'+esc(o.source_label)+(o.source_no?'<br><small class="text-muted">'+esc(o.source_no)+'</small>':'')+'</td>'
        + '<td>'+cpShow(o.counterparty_type, o.counterparty_display)+'</td>'
        + '<td>'+esc(o.drawing_no||'')+'</td>'
        + '<td>'+esc(o.resp_show||'—')+'</td>'
        + '<td>'+stBadge(o.status,o.status_label)+'</td>'
        + '<td>'+(latest||'<span class="text-muted">—</span>')+'</td>'
        + '<td>'+esc(o.created_by_name||'')+'</td>'
        + '<td><button class="btn btn-xs btn-default v-btn" data-id="'+o.id+'" title="檢視"><i class="fa fa-eye"></i></button>'
        + ((CAN_DELETE && (IS_ADMIN || (o.created_by|0)===ME_ID)) ? ' <button class="btn btn-xs btn-danger d-btn" data-id="'+o.id+'" data-no="'+esc(o.car_no||'（未配號）')+'" title="刪除"><i class="fa fa-trash"></i></button>' : '')
        + '</td>'
        + '</tr>';
    });
    $('#carBody').html(h);
  }
  function renderPager(r){
    var t=r.total||0, pg=r.page||1, pages=r.pages||1;
    var start = t? ((pg-1)*r.size+1):0, end=Math.min(pg*r.size,t);
    var h='<small class="text-muted">'+start+'–'+end+' / '+t+' 筆</small> '
      + '<button class="btn btn-xs btn-default" '+(pg<=1?'disabled':'')+' id="pg-prev">←</button> '
      + '<small>'+pg+'/'+pages+'</small> '
      + '<button class="btn btn-xs btn-default" '+(pg>=pages?'disabled':'')+' id="pg-next">→</button>';
    $('#pager').html(h);
    $('#pg-prev').on('click',function(){ if(pg>1) fetchPage(pg-1); });
    $('#pg-next').on('click',function(){ if(pg<pages) fetchPage(pg+1); });
  }

  // ---------- 通用 autocomplete ----------
  function autocomplete(inputSel, listSel, fetchFn, renderFn, pickFn){
    var $in=$(inputSel), $list=$(listSel), timer=null, data=[], idx=-1;
    function hl(){ $list.find('.ac-item').removeClass('kb');
      if(idx>=0){ var $a=$list.find('.ac-item[data-i="'+idx+'"]').addClass('kb'); var el=$a[0]; if(el&&el.scrollIntoView) el.scrollIntoView({block:'nearest'}); } }
    function choose(i){ if(i<0||i>=data.length) return; pickFn(data[i]); $list.hide().empty(); data=[]; idx=-1; }
    function render(){
      var h=''; data.forEach(function(it,i){ h+='<div class="ac-item" data-i="'+i+'">'+renderFn(it)+'</div>'; });
      $list.html(h).show();
      $list.find('.ac-item').on('mousedown', function(e){ e.preventDefault(); choose($(this).data('i')); })
                            .on('mouseenter', function(){ idx=$(this).data('i'); hl(); });
    }
    function run(){
      var kw=$in.val().trim(); clearTimeout(timer);
      timer=setTimeout(function(){
        fetchFn(kw).done(function(r){
          data=(r&&r.success&&r.data)?r.data:[]; idx=-1;
          if(!data.length){ $list.hide().empty(); return; }
          render();
        });
      }, 180);
    }
    $in.on('input', run);
    $in.on('focus', function(){ if($in.val().trim()==='') run(); });   // 空值聚焦：讓有前置條件(如已綁料號)者也能列表
    $in.on('keydown', function(e){
      if(!$list.is(':visible') || !data.length){
        if(e.key==='ArrowDown'){ run(); }   // 未展開時按下鍵可展開
        return;
      }
      if(e.key==='ArrowDown'){ e.preventDefault(); idx=Math.min(idx+1, data.length-1); hl(); }
      else if(e.key==='ArrowUp'){ e.preventDefault(); idx=Math.max(idx-1, 0); hl(); }
      else if(e.key==='Enter'){ e.preventDefault(); choose(idx<0?0:idx); }   // Enter 選定(未移動則選第一筆)
      else if(e.key==='Escape'){ $list.hide(); }
    });
    $(document).on('click', function(e){ if(!$(e.target).closest(inputSel+','+listSel).length) $list.hide(); });
  }

  // ---------- 開立 / 修改 ----------
  var editId = null;   // null=新增；有值=修改該單表頭
  function resetCreate(){
    editId=null; resp=[]; sel={}; renderResp();
    sel.temp_key = 'tk' + Date.now().toString(36) + Math.random().toString(36).slice(2,10);   // 附件暫存鍵
    $('#create-att-list').empty();
    $('input[name="src"]').prop('checked',false);
    $('#src-qa,#src-ir,#src-other').hide();
    $('#src-qa-kw,#src-ir-kw,#src-other-no,#src-other-desc,#cp-kw,#part-kw,#wo-kw,#f-qty,#f-found-date,#f-desc').val('');
    $('#src-qa-pick,#src-ir-pick,#cp-pick,#part-pick,#wo-pick,#create-msg').text('');
    $('#pick-dept,#pick-maker,#qty-wrap').hide();
    $('#resp-block,#opener-block').show();
    $('#createModal .modal-title').html('<i class="fa fa-file-text-o"></i> 開立異常矯正處理單 <small class="text-muted">（表單編號於建立時自動產生）</small>');
    updateOpenerHint();
  }
  // 以既有單據內容進入修改模式（責任單位/開立職務不可改，隱藏）
  function openEdit(o){
    resetCreate(); editId=o.id;
    $('#resp-block,#opener-block').hide();
    $('#createModal .modal-title').html('<i class="fa fa-pencil"></i> 修改異常矯正處理單 '+esc(o.car_no||'（未配號）'));
    $('#btn-create-submit').html('<i class="fa fa-save"></i> 儲存修改');
    $('input[name="src"][value="'+o.source_type+'"]').prop('checked',true);
    $('#src-qa').toggle(o.source_type==='QA'); $('#src-ir').toggle(o.source_type==='IR'); $('#src-other').toggle(o.source_type==='OTHER');
    sel.source_ref_id = o.source_ref_id||0;
    if(o.source_type==='OTHER'){ $('#src-other-no').val(o.source_no||''); $('#src-other-desc').val(o.source_desc||''); }
    else if(o.source_type==='QA'){ $('#src-qa-kw').val(o.source_no||''); $('#src-qa-pick').text(o.source_ref_id?'已選（沿用原綁定）':''); }
    else if(o.source_type==='IR'){ $('#src-ir-kw').val(o.source_no||''); $('#src-ir-pick').text(o.source_ref_id?'已選（沿用原綁定）':''); }
    if(o.counterparty_type){ sel.cp_type=o.counterparty_type; sel.cp_id=(o.counterparty_type==='maker'?o.maker_id_no:o.customer_id);
      var nm=String(o.counterparty_display||'').replace(/^\[.\]\s*/,'');
      $('#cp-kw').val(nm); $('#cp-pick').html('已選：'+cpShow(o.counterparty_type,o.counterparty_display)); }
    if(o.d_id||o.drawing_no){ sel.d_id=o.d_id||0; sel.drawing_no=o.drawing_no||''; $('#part-kw').val(o.drawing_no||''); $('#qty-wrap').show(); }
    if(o.work_order){ sel.bom_ing_fid=o.bom_ing_fid||0; sel.work_order=o.work_order; sel.bom_no=o.bom_no||o.work_order; $('#wo-kw').val(o.work_order); }
    $('#f-qty').val(o.qty!=null?parseFloat(o.qty):'');
    $('#f-found-date').val(o.found_date||'');
    $('#f-desc').val(o.abnormal_desc||'');
    // 等檢視 Modal 完全關閉再開編輯 Modal，避免殘留透明 backdrop 擋住整頁點擊
    $('#viewModal').one('hidden.bs.modal', function(){ $('#createModal').modal('show'); }).modal('hide');
  }
  function renderResp(){
    var h=''; resp.forEach(function(t,i){ h+='<span class="resp-chip">'+esc(t.label)+'<span class="x" data-i="'+i+'">&times;</span></span>'; });
    $('#resp-chips').html(h);
    $('#resp-chips .x').on('click', function(){ resp.splice($(this).data('i'),1); renderResp(); });
    var n=resp.length||1;
    $('#resp-note').text('目前 '+resp.length+' 個責任單位 → 將建立 '+n+' 張'+(n>1?'獨立':'')+'單');
  }

  function submitCreate(){
    // ── 必填檢查：缺漏逐項列出、彈窗提醒並標紅 ──
    var missing=[];
    var src=$('input[name="src"]:checked').val();
    if(!src) missing.push('異常來源（三選一）');
    else if(src==='QA' && !sel.source_ref_id) missing.push('對應的品質異常處理單（請從搜尋列表選定）');
    else if(src==='IR' && !sel.source_ref_id) missing.push('對應的客戶退貨單（請從搜尋列表選定）');
    var desc=$('#f-desc').val().trim();
    if(!desc) missing.push('異常說明');
    if(!editId && selectedOpener()===null && myPositions.length>0) missing.push('開立職務');
    if(missing.length){
      $('#create-msg').html('<span class="text-danger"><i class="fa fa-exclamation-circle"></i> 尚有必填未完成</span>');
      $('#f-desc').css('border-color', desc?'':'#d9534f');
      alert('尚有必填項目未完成：\n\n• '+missing.join('\n• '));
      return;
    }
    $('#f-desc').css('border-color','');
    var payload={
      source_type:src,
      source_ref_id: sel.source_ref_id||0,
      source_no: src==='OTHER' ? $('#src-other-no').val().trim() : '',
      source_desc: src==='OTHER' ? $('#src-other-desc').val().trim() : '',
      counterparty_type: sel.cp_type||'', customer_id: sel.cp_type==='customer'?sel.cp_id:'', maker_id_no: sel.cp_type==='maker'?sel.cp_id:'',
      d_id: sel.d_id||0, drawing_no: sel.drawing_no||'',
      bom_no: sel.bom_no||'', work_order: sel.work_order||'', bom_ing_fid: sel.bom_ing_fid||0,
      qty: $('#f-qty').val(),
      temp_key: sel.temp_key||'',
      found_date: $('#f-found-date').val()||'',
      opener_dept_id: (selectedOpener()? selectedOpener().dept_id : 0),
      opener_position_id: (selectedOpener()? selectedOpener().position_id : 0),
      abnormal_desc: desc,
      responsible: JSON.stringify(resp)
    };
    $('#btn-create-submit').prop('disabled',true);
    if(editId){ payload.car_id = editId; }
    api(editId ? 'update_order' : 'create', payload).done(function(r){
      $('#btn-create-submit').prop('disabled',false);
      if(!r||!r.success){ $('#create-msg').text(r&&r.message||(editId?'修改失敗':'建立失敗')); return; }
      $('#createModal').modal('hide'); fetchPage(editId ? state.page : 1);
      if(window.__loadRespFilter) window.__loadRespFilter();
      alert(r.message||(editId?'已儲存修改':'建立成功'));
      editId=null;
    }).fail(function(xhr){ $('#btn-create-submit').prop('disabled',false);
      var m=(xhr&&xhr.responseJSON&&xhr.responseJSON.message)||'連線失敗，請稍後再試'; $('#create-msg').text(m); });
  }

  // ---------- 事件綁定 ----------
  $(function(){
    // 安全網：任一 Modal 關閉後若已無開啟中的 Modal，清除殘留 backdrop（防整頁不可點擊）
    $(document).on('hidden.bs.modal', '.modal', function(){
      if(!$('.modal.in').length){ $('.modal-backdrop').remove(); $('body').removeClass('modal-open').css('padding-right',''); }
    });
    // 篩選卡片
    $('.car-stat').on('click', function(){ $('.car-stat').removeClass('active'); $(this).addClass('active'); state.card=$(this).data('card'); fetchPage(1); });
    $('#f-dept,#f-source').on('change', function(){ fetchPage(1); });
    $('#f-size').on('change', function(){ fetchPage(1); });
    var kwT=null; $('#f-kw').on('input', function(){ clearTimeout(kwT); kwT=setTimeout(function(){ fetchPage(1); },300); });
    $('#f-kw').on('dblclick', function(){ $(this).val(''); fetchPage(1); });
    $('#carBody').on('click', '.v-btn', function(){ openView($(this).data('id')); });
    $('#carBody').on('click', '.d-btn', function(){
      var did=$(this).data('id'), dno=$(this).data('no');
      if(!confirm('確定刪除單據 '+dno+'？\n將連同簽章、處理軌跡、附件記錄一併刪除，無法復原。')) return;
      api('delete_order',{car_id:did}).done(function(r){ alert(r&&r.message||''); if(r&&r.success){ fetchPage(state.page); if(window.__loadRespFilter) window.__loadRespFilter(); } })
        .fail(function(xhr){ alert((xhr.responseJSON&&xhr.responseJSON.message)||'刪除失敗'); });
    });
    $('#btn-perm-help').on('click', function(e){ e.preventDefault(); $('#permHelp').modal('show'); });

    // 責任單位篩選下拉：只列「已被開立過」的部門；有廠商責任單則加「廠商責任」選項
    function loadRespFilter(){
      var cur = $('#f-dept').val()||'';
      api('used_resp_depts').done(function(r){ if(r&&r.success){
        var h='<option value="">責任單位(全部)</option>';
        if(r.has_maker) h+='<option value="maker">廠商責任</option>';
        r.data.forEach(function(d){ h+='<option value="'+d.id+'">'+esc(d.name)+'</option>'; });
        $('#f-dept').html(h).val(cur);
        if($('#f-dept').val()===null) $('#f-dept').val('');
      }});
    }
    loadRespFilter();
    // 開單 Modal 的部門選擇器仍列全部部門
    api('list_depts').done(function(r){ if(r&&r.success){
      var h2='<option value="">選擇部門…</option>'; r.data.forEach(function(d){ h2+='<option value="'+d.id+'">'+esc(d.name)+'</option>'; }); $('#pd-dept').html(h2);
    }});
    window.__loadRespFilter = loadRespFilter;   // 建立/刪除後刷新篩選清單

    // 開立
    loadMyPositions();
    $('#f-opener').on('change', updateOpenerHint);
    $('#btn-new').on('click', function(){ resetCreate(); $('#createModal').modal('show'); });
    $('#btn-create-submit').on('click', submitCreate);
    $('input[name="src"]').on('change', function(){
      var v=$(this).val(); sel.source_ref_id=0;
      $('#src-qa').toggle(v==='QA'); $('#src-ir').toggle(v==='IR'); $('#src-other').toggle(v==='OTHER');
      $('#src-qa-pick,#src-ir-pick').text('');
    });

    // 來源 autocomplete
    autocomplete('#src-qa-kw','#src-qa-list', function(kw){ return api('search_qa_source',{kw:kw}); },
      function(it){ return '<b>'+esc(it.abnormal_order_no)+'</b> <small class="text-muted">'+esc(it.occurrence_date||'')+' '+esc(it.phenomenon||'')+'</small>'; },
      function(it){ sel.source_ref_id=it.id; $('#src-qa-kw').val(it.abnormal_order_no); $('#src-qa-pick').text('已選：'+it.abnormal_order_no); });
    autocomplete('#src-ir-kw','#src-ir-list', function(kw){ return api('search_ir_source',{kw:kw}); },
      function(it){ return '<b>'+esc(it.IR_no)+'</b> <small class="text-muted">'+esc(it.Client_name||'')+' '+esc(it.d_id||'')+'</small>'; },
      function(it){ sel.source_ref_id=it.IR_id; $('#src-ir-kw').val(it.IR_no); $('#src-ir-pick').text('已選：'+it.IR_no); });

    // 客戶/供應商
    autocomplete('#cp-kw','#cp-list', function(kw){ return api('search_counterparty',{kw:kw}); },
      function(it){ return '<span class="tag-c '+it.tag+'">'+it.tag+'</span>'+esc(it.name)+' <small class="text-muted">'+esc(it.id)+(it.full?' '+esc(it.full):'')+'</small>'; },
      function(it){ sel.cp_type=it.type; sel.cp_id=it.id; $('#cp-kw').val(it.name); $('#cp-pick').html('已選：<span class="tag-c '+it.tag+'">'+it.tag+'</span>'+esc(it.name)); });

    // 料號 → 自動帶客戶（並顯示數量欄、後續 BOM 只列此料號）
    autocomplete('#part-kw','#part-list', function(kw){ return api('search_part',{kw:kw}); },
      function(it){ return '<b>'+esc(it.D_Setting_Id)+'</b> <small class="text-muted">'+esc(it.Spec_No||'')+' '+esc(it.client_name||'')+'</small>'; },
      function(it){ sel.d_id=it.d_id; sel.drawing_no=it.D_Setting_Id; $('#part-kw').val(it.D_Setting_Id); $('#qty-wrap').show();
        // 換料號時清掉先前綁定的 BOM（避免不一致）
        sel.bom_ing_fid=0; sel.work_order=''; sel.bom_no=''; $('#wo-kw').val(''); $('#wo-pick').text('');
        $('#part-pick').text('已選料號：'+it.D_Setting_Id+(it.client_name?'（客戶：'+it.client_name+'）':''));
        if(it.Customer_Id && !sel.cp_id){ sel.cp_type='customer'; sel.cp_id=it.Customer_Id; $('#cp-kw').val(it.client_name||''); $('#cp-pick').html('已自動帶入客戶：<span class="tag-c 客">客</span>'+esc(it.client_name||it.Customer_Id)+'（可改選廠商）'); }
      });
    // 清空料號（雙擊）→ 隱藏數量、解除 BOM 篩選
    $('#part-kw').on('dblclick', function(){ $(this).val(''); sel.d_id=0; sel.drawing_no=''; $('#part-pick').text(''); $('#qty-wrap').hide(); });

    // 廠內製令 → BOM（已綁料號則只列此料號；選 BOM 反帶料號＋客戶）
    autocomplete('#wo-kw','#wo-list', function(kw){ return api('search_workorder',{kw:kw, part: sel.drawing_no||''}); },
      function(it){ return '<b>'+esc(it.bom)+'</b> <small class="text-muted">料號'+esc(it.part_no||'')+' '+esc(it.client||'')+' '+esc(it.process||'')+' 數量'+esc(it.sqty||0)+'</small>'; },
      function(it){ sel.bom_ing_fid=it.bom_ing_fid; sel.work_order=it.bom; sel.bom_no=it.bom; $('#wo-kw').val(it.bom); $('#wo-pick').text('已綁定製令 BOM：'+it.bom);
        if(it.part_no){ sel.drawing_no=it.part_no; if(it.ds_d_id) sel.d_id=it.ds_d_id; $('#part-kw').val(it.part_no); $('#part-pick').text('由製令帶入料號：'+it.part_no); $('#qty-wrap').show(); }
        if(it.ds_customer_id && !sel.cp_id){ sel.cp_type='customer'; sel.cp_id=it.ds_customer_id; $('#cp-kw').val(it.ds_customer_name||''); $('#cp-pick').html('已自動帶入客戶：<span class="tag-c 客">客</span>'+esc(it.ds_customer_name||it.ds_customer_id)+'（可改選廠商）'); }
      });

    // 責任單位選擇器
    $('#add-dept').on('click', function(){ $('#pick-dept').toggle(); $('#pick-maker').hide(); });
    $('#add-maker').on('click', function(){ $('#pick-maker').toggle(); $('#pick-dept').hide(); });

    $('#pd-dept').on('change', function(){ var d=$(this).val(); $('#pd-person').html('<option value="">（整個部門，可不選人員）</option>'); if(!d) return;
      api('dept_users',{dept_id:d}).done(function(r){ if(r&&r.success){ r.data.forEach(function(u){ $('#pd-person').append('<option value="'+u.id+'">'+esc(u.user_cname)+'（'+esc(u.position_name||'')+'）</option>'); }); } }); });
    $('#pd-add').on('click', function(){ var d=$('#pd-dept').val(); if(!d){ alert('請選擇部門'); return; }
      var dn=$('#pd-dept option:selected').text(), pid=$('#pd-person').val(), pn=$('#pd-person option:selected').text();
      var label = pid ? (dn+' / '+pn.replace(/（.*/,'')) : dn;
      resp.push({type:'dept', dept_id:parseInt(d,10), person_id: pid?parseInt(pid,10):0, label:label}); renderResp(); $('#pick-dept').hide(); });

    autocomplete('#pm-kw','#pm-list', function(kw){ return api('search_counterparty',{kw:kw, only:'maker'}); },
      function(it){ return '<span class="tag-c 廠">廠</span>'+esc(it.name)+' <small class="text-muted">'+esc(it.id)+'</small>'; },
      function(it){ resp.push({type:'maker', maker_id:it.id, label:'廠商：'+it.name}); renderResp(); $('#pm-kw').val(''); $('#pick-maker').hide(); });

    if(CAN_VIEW) fetchPage(1);            // 無檢閱權限者不撈列表（僅能經通知直開自己的單）
    if(OPEN_ID) openView(OPEN_ID);        // 通知直開：自動彈出該單填寫/檢視
  });

  // ---------- 檢視 / 處理 ----------
  function openView(id){
    curViewId = id;
    $('#view-body').html('載入中…'); $('#viewModal').modal('show');
    api('get_detail',{id:id}).done(function(r){
      if(!r||!r.success){ $('#view-body').html('<div class="text-danger">'+esc(r&&r.message||'載入失敗')+'</div>'); return; }
      var o=r.order, L=r.labels, perm=r.perm||{};
      window.__ownCompany = r.own_company || '';
      var sigMap={}; (r.signatures||[]).forEach(function(s){ if(!parseInt(s.revoked,10)) sigMap[s.section]={name:s.signed_name,date:s.signed_date_label,title:s.title||''}; });
      function sigText(x){ return x ? EGStamp.stamp(x.name, x.date) : '—'; }
      var acts=(r.activity||[]).map(function(a){ return '<div class="timeline-mini">'+fmtDT(a.created_at)+' '+esc(a.actor_name||'')+(a.title?'（'+esc(a.title)+'）':'')+'：'+esc(a.note||a.action)+'</div>'; }).join('');
      var grp=(r.group||[]).length>1 ? '<div class="alert alert-info" style="padding:6px 10px;">同事件('+esc(o.group_no)+')共 '+r.group.length+' 張：'+r.group.map(function(g){return esc(g.car_no||'（未配號）');}).join('、')+'</div>' : '';
      if(o.reissue_of && r.parent_no){ grp+='<div class="alert alert-warning" style="padding:6px 10px;">本單為退件重發（第 '+esc(o.reissue_seq)+' 次），母單：<a href="#" class="open-car" data-id="'+o.reissue_of+'">'+esc(r.parent_no)+'</a></div>'; }
      if((r.reissues||[]).length){ grp+='<div class="alert alert-warning" style="padding:6px 10px;">本單退件後產生：'+r.reissues.map(function(g){return '<a href="#" class="open-car" data-id="'+g.id+'">'+esc(g.car_no)+'</a>';}).join('、')+'</div>'; }
      var carNoShow = o.car_no ? esc(o.car_no) : '<span class="text-muted">（申請中，核准後配號）</span>';
      var descSig = sigText(sigMap['desc']);
      // 填表人顯示：中文名（部門/開立職務）；不另列開立職務欄
      var fillerDeptTitle = o.created_by_title || '';
      if(o.opener_position_name && fillerDeptTitle.indexOf('/')>=0){
        fillerDeptTitle = fillerDeptTitle.split('/')[0] + '/' + o.opener_position_name;   // 部門取主身分、職稱取開立時所選職務
      } else if(o.opener_position_name && !fillerDeptTitle){
        fillerDeptTitle = o.opener_position_name;
      }
      var fillerShow = esc(o.created_by_name||'') + (fillerDeptTitle?'（'+esc(fillerDeptTitle)+'）':'');
      var appInfo='';
      if(o.open_applied_at){ appInfo += '<tr><th>申請時間</th><td colspan="3">'+fmtDT(o.open_applied_at)+'</td></tr>'; }
      if(o.status==='app_rejected' && o.open_reject_reason){ appInfo += '<tr class="danger"><th>退回原因</th><td colspan="3">'+esc(o.open_reject_reason)+'（'+esc(o.open_approved_by_name||'')+'）</td></tr>'; }
      else if(o.status==='draft' && o.open_reject_reason){ appInfo += '<tr class="warning"><th>撤回</th><td colspan="3">'+esc(String(o.open_reject_reason).replace(/^撤回：/,''))+'（可修改後重新送出申請）</td></tr>'; }
      else if(o.open_approved_by_name){ appInfo += '<tr><th>核准成立</th><td colspan="3">'+esc(o.open_approved_by_name)+(o.open_approved_by_title?'（'+esc(o.open_approved_by_title)+'）':'')+' '+fmtDT(o.open_approved_at)+'</td></tr>'; }

      var actions='';
      if(perm.can_approve){
        actions='<div class="car-warm"><b>開立申請待核准</b>（由 '+esc(o.created_by_name||'')+'／'+esc(o.opener_position_name||'')+' 提出）'
          +'<div><button class="btn btn-success btn-sm" id="btn-approve-open"><i class="fa fa-check"></i> 同意成立</button> '
          +'<button class="btn btn-danger btn-sm" id="btn-reject-open"><i class="fa fa-times"></i> 退回</button></div></div>';
      }
      if(perm.can_edit_header || perm.can_resubmit || perm.can_withdraw){
        actions+='<div style="margin-top:8px;">'
          +(perm.can_edit_header?'<button class="btn btn-warning btn-sm" id="btn-edit-header"><i class="fa fa-pencil"></i> 修改</button> ':'')
          +(perm.can_resubmit?'<button class="btn btn-primary btn-sm" id="btn-resubmit"><i class="fa fa-paper-plane"></i> 重新送出申請</button> ':'')
          +(perm.can_withdraw?'<button class="btn btn-default btn-sm" id="btn-withdraw"><i class="fa fa-undo"></i> 撤回申請</button>':'')
          +'</div>';
      }

      // 指派區
      var assignHtml='';
      if(o.status==='open' && perm.can_assign){
        assignHtml='<div class="panel panel-default"><div class="panel-heading" style="padding:6px 10px;"><b>指派回覆人</b></div><div class="panel-body">'
          +'<div class="input-group input-group-sm" style="max-width:460px;"><select id="assignee-sel" class="form-control"><option value="">載入中…</option></select>'
          +'<span class="input-group-btn"><button class="btn btn-primary" id="btn-assign">指派</button></span></div></div></div>';
      } else if(o.assigned_to_name){
        assignHtml='<div class="alert alert-info" style="padding:6px 10px;">回覆人：<b>'+esc(o.assigned_to_name)+'</b>（由 '+esc(o.assigned_by_name||'')+' 指派，'+fmtDT(o.assigned_at)+'）</div>';
      }

      $('#view-body').html(
        grp
        + '<table class="table table-bordered"><tbody>'
        + '<tr><th style="width:120px;">表單編號</th><td>'+carNoShow+'</td><th style="width:120px;">狀態</th><td>'+stBadge(o.status,o.status_label)+'</td></tr>'
        + '<tr><th>異常來源</th><td>'+esc(o.source_label)+' '+esc(o.source_no||'')+'</td><th>客戶/供應商</th><td>'+cpShow(o.counterparty_type, o.counterparty_display)+'</td></tr>'
        + '<tr><th>料號</th><td>'+esc(o.drawing_no||'')+'</td><th>製令BOM</th><td>'+esc(o.work_order||'')+'</td></tr>'
        + '<tr><th>責任單位</th><td>'+esc(o.resp_display||'')+'</td><th>發現 / 填表日期</th><td>'+esc(o.found_date||'—')+' / '+esc(o.fill_date||'')+'</td></tr>'
        + '<tr><th>填表人</th><td>'+fillerShow+'</td><th>製程</th><td>'+esc(o.process_name||'')+'</td></tr>'
        + appInfo
        + '<tr><th>異常說明</th><td colspan="3">'+esc(o.abnormal_desc||'').replace(/\n/g,'<br>')
        + attList(r.attachments,'desc', perm.can_edit_header || ((o.created_by|0)===perm.me_id))
        + EGStamp.row(descSig)+'</td></tr>'
        + '</tbody></table>'
        + actions + assignHtml + renderReply(o, perm, sigMap, L, r.attachments)
        + renderDecision(o, perm, sigMap, r.attachments)
        + '<h5 style="margin-top:12px;">處理軌跡</h5>'+(acts||'<span class="text-muted">—</span>')
      );
      // 母單/退件單跳轉
      $('#view-body .open-car').on('click', function(e){ e.preventDefault(); openView($(this).data('id')); });

      // 修改表頭 / 重新送出申請
      if(perm.can_edit_header){ $('#btn-edit-header').on('click', function(){ openEdit(o); }); }
      if(perm.can_resubmit){ $('#btn-resubmit').on('click', function(){
        if(!confirm('確定重新送出申請？將重新通知部門主管核准。')) return;
        api('resubmit_application',{car_id:o.id}).done(function(rr){ alert(rr&&rr.message||''); if(rr&&rr.success){ $('#viewModal').modal('hide'); fetchPage(state.page);} }); }); }
      if(perm.can_withdraw){ $('#btn-withdraw').on('click', function(){
        var reason=prompt('撤回原因（必填）'); if(!reason) return;
        api('withdraw_application',{car_id:o.id, reason:reason}).done(function(rr){ alert(rr&&rr.message||''); if(rr&&rr.success){ openView(o.id); fetchPage(state.page);} })
          .fail(function(xhr){ alert((xhr.responseJSON&&xhr.responseJSON.message)||'撤回失敗'); }); }); }

      // 申請核准/退回
      if(perm.can_approve){
        $('#btn-approve-open').on('click', function(){ if(!confirm('確定核准成立？將產生正式單號並開始流程。')) return;
          api('approve_open',{group_no:o.group_no}).done(function(rr){ alert(rr&&rr.message||''); if(rr&&rr.success){ openView(id); fetchPage(state.page);} }); });
        $('#btn-reject-open').on('click', function(){ var reason=prompt('退回原因'); if(!reason) return;
          api('reject_open',{group_no:o.group_no, reason:reason}).done(function(rr){ alert(rr&&rr.message||''); if(rr&&rr.success){ $('#viewModal').modal('hide'); fetchPage(state.page);} }); });
      }

      // 指派
      if(o.status==='open' && perm.can_assign){
        api('get_assignees',{id:id}).done(function(rr){
          var opts='<option value="">選擇回覆人…</option>';
          (rr&&rr.data||[]).forEach(function(u){ opts+='<option value="'+u.id+'">'+esc(u.user_cname)+'（'+esc(u.position_name||'')+'）</option>'; });
          $('#assignee-sel').html(opts);
        });
        $('#btn-assign').on('click', function(){ var aid=$('#assignee-sel').val(); if(!aid){ alert('請選擇回覆人'); return; }
          api('assign',{car_id:id, assignee_id:aid}).done(function(rr){ alert(rr&&rr.message||''); if(rr&&rr.success){ openView(id); fetchPage(state.page);} }); });
      }

      // 回覆三段：儲存/簽章/修改/送出
      function gatherReply(){
        var ci=[]; $('.ci-chk:checked').each(function(){ ci.push($(this).val()); });
        return { car_id:id, cause_investigation:JSON.stringify(ci),
          cause_other:$('#rp-cause-other').val()||'', cause_detail:$('#rp-cause-detail').val()||'',
          disposition:$('input[name="rp-disp"]:checked').val()||'', disposition_other:$('#rp-disp-other').val()||'',
          correction_measure:$('#rp-corr').val()||'', correction_due:$('#rp-corr-due').val()||'',
          prevention_measure:$('#rp-prev').val()||'', prevention_due:$('#rp-prev-due').val()||'' };
      }
      $('#btn-save-reply').on('click', function(){ api('save_reply', gatherReply()).done(function(rr){ $('#reply-msg').text(rr&&rr.message||''); }); });
      $('.sign-btn').on('click', function(){ var sec=$(this).data('sec');
        api('save_reply', gatherReply()).done(function(){
          api('sign_section',{car_id:id, section:sec}).done(function(rr){ if(rr&&rr.success){ openView(id); } else $('#reply-msg').text(rr&&rr.message||'簽章失敗'); })
            .fail(function(xhr){ $('#reply-msg').text((xhr.responseJSON&&xhr.responseJSON.message)||'簽章失敗'); }); });
      });
      $('.unsign-btn').on('click', function(){ var sec=$(this).data('sec');
        api('unsign_section',{car_id:id, section:sec}).done(function(rr){ if(rr&&rr.success) openView(id); else $('#reply-msg').text(rr&&rr.message||''); }); });
      $('#btn-submit-reply').on('click', function(){ if(!confirm('確定送出？送出後將待主管簽核。')) return;
        api('submit_reply',{car_id:id}).done(function(rr){ alert(rr&&rr.message||''); if(rr&&rr.success){ $('#viewModal').modal('hide'); fetchPage(state.page);} })
          .fail(function(xhr){ $('#reply-msg').text((xhr.responseJSON&&xhr.responseJSON.message)||'送出失敗'); }); });

      // 效果確認：主管簽核 / 總經理裁決 / 管理課扣款判定
      $('#btn-primary-sign').on('click', function(){ if(!confirm('確認簽核通過？將送交總經理裁決。')) return;
        api('primary_sign',{car_id:id}).done(function(rr){ alert(rr&&rr.message||''); if(rr&&rr.success){ openView(id); fetchPage(state.page);} })
          .fail(function(xhr){ alert((xhr.responseJSON&&xhr.responseJSON.message)||'簽核失敗'); }); });
      $('#btn-final-close').on('click', function(){
        var amt=$('#final-deduct-amount').val(), note=$('#final-deduct-note').val();
        var amtTxt = (amt!=='' && parseFloat(amt)>0) ? ('扣款 '+amt+' 元') : '不扣款';
        if(!confirm('確認結案？（'+amtTxt+'）\n將自動簽章並押上今日結案日期。')) return;
        api('final_decide',{car_id:id, result:'close', deduct_amount:amt, deduct_note:note}).done(function(rr){ alert(rr&&rr.message||''); if(rr&&rr.success){ openView(id); fetchPage(state.page);} })
          .fail(function(xhr){ alert((xhr.responseJSON&&xhr.responseJSON.message)||'裁決失敗'); }); });
      $('#btn-final-reject').on('click', function(){ var reason=prompt('不可結案原因（必填）'); if(!reason) return;
        api('final_decide',{car_id:id, result:'not_close', reason:reason}).done(function(rr){ alert(rr&&rr.message||''); if(rr&&rr.success){ openView(id); fetchPage(state.page); if(window.__loadRespFilter) window.__loadRespFilter(); } })
          .fail(function(xhr){ alert((xhr.responseJSON&&xhr.responseJSON.message)||'裁決失敗'); }); });
      $('#btn-deduct-sign').on('click', function(){ var amt=$('#deduct-amount').val(); var note=$('#deduct-note').val();
        if(amt===''){ alert('請填寫扣款金額（0 表示不扣款）'); return; }
        if(!confirm('確認判定：'+(parseFloat(amt)>0?('扣款 '+amt+' 元'):'不扣款')+'？')) return;
        api('deduct_sign',{car_id:id, amount:amt, note:note}).done(function(rr){ alert(rr&&rr.message||''); if(rr&&rr.success){ openView(id); fetchPage(state.page);} })
          .fail(function(xhr){ alert((xhr.responseJSON&&xhr.responseJSON.message)||'判定失敗'); }); });
    });
  }

  // 三段回覆區塊（原因分析 / 矯正措施 / 預防措施）；附件矯正/預防分開顯示於各自區塊
  function renderReply(o, perm, sigMap, L, atts){
    if(!o.assigned_to && ['assigned','replying','pending_primary','pending_final','closed','rejected'].indexOf(o.status)<0) return '';
    var editable = !!perm.can_reply;
    function secBox(title, sec, editHtml, roHtml, dueLeft){
      var attHtml = attList(atts, sec, editable);
      var s=sigMap[sec], head='<div class="panel-heading" style="padding:6px 10px;"><b>'+title+'</b>';
      if(s) head+=' <span class="label label-success pull-right">已簽章</span>';
      head+='</div>';
      var body='<div class="panel-body">';
      if(editable && !s){ body+=editHtml+attHtml+'<div style="margin-top:6px;"><button class="btn btn-success btn-xs sign-btn" data-sec="'+sec+'"><i class="fa fa-pencil"></i> 簽章</button></div>'; }
      else {
        body+=roHtml+attHtml;
        if(s) body+=EGStamp.row(EGStamp.stamp(s.name, s.date), dueLeft||'');           // 左下=預定完成日、右下=簽章印章
        else if(dueLeft) body+='<div style="font-size:12px;color:#777;margin-top:4px;">'+dueLeft+'</div>';
        if(editable && s) body+='<div style="margin-top:6px;"><button class="btn btn-default btn-xs unsign-btn" data-sec="'+sec+'">修改（取消簽章）</button></div>';
      }
      body+='</div>';
      return '<div class="panel panel-default" style="margin-bottom:8px;">'+head+body+'</div>';
    }
    var ciArr=(o.cause_investigation||'').split(',').filter(Boolean);
    var causeEdit='<div>原因調查：';
    Object.keys(L.cause).forEach(function(k){ causeEdit+='<label class="checkbox-inline"><input type="checkbox" class="ci-chk" value="'+k+'" '+(ciArr.indexOf(k)>=0?'checked':'')+'> '+esc(L.cause[k])+'</label>'; });
    causeEdit+=' <input type="text" id="rp-cause-other" class="input-sm" style="width:120px;" placeholder="其他說明" value="'+esc(o.cause_other||'')+'"></div>'
      +'<textarea id="rp-cause-detail" class="form-control" rows="2" placeholder="異常原因分析" style="margin-top:6px;">'+esc(o.cause_detail||'')+'</textarea>';
    var causeRO='原因調查：'+(ciArr.map(function(k){return L.cause[k]||k;}).join('、')||'—')+(o.cause_other?('、'+esc(o.cause_other)):'')+'<div style="white-space:pre-wrap;margin-top:4px;">'+esc(o.cause_detail||'')+'</div>';
    var dispEdit='<div>處置方式：';
    Object.keys(L.disposition).forEach(function(k){ dispEdit+='<label class="radio-inline"><input type="radio" name="rp-disp" value="'+k+'" '+(o.disposition===k?'checked':'')+'> '+esc(L.disposition[k])+'</label>'; });
    dispEdit+=' <input type="text" id="rp-disp-other" class="input-sm" style="width:120px;" placeholder="其他說明" value="'+esc(o.disposition_other||'')+'"></div>'
      +'<textarea id="rp-corr" class="form-control" rows="2" placeholder="矯正措施" style="margin-top:6px;">'+esc(o.correction_measure||'')+'</textarea>'
      +'<div style="margin-top:4px;">預定完成日 <input type="date" id="rp-corr-due" class="input-sm" value="'+esc(o.correction_due||'')+'"></div>';
    var corrRO='處置：'+(L.disposition[o.disposition]||'—')+(o.disposition_other?('、'+esc(o.disposition_other)):'')+'<div style="white-space:pre-wrap;margin-top:4px;">'+esc(o.correction_measure||'')+'</div>';
    var prevEdit='<textarea id="rp-prev" class="form-control" rows="2" placeholder="預防措施">'+esc(o.prevention_measure||'')+'</textarea>'
      +'<div style="margin-top:4px;">預定完成日 <input type="date" id="rp-prev-due" class="input-sm" value="'+esc(o.prevention_due||'')+'"></div>';
    var prevRO='<div style="white-space:pre-wrap;">'+esc(o.prevention_measure||'')+'</div>';

    var h='<h5 style="margin-top:12px;">異常原因分析 / 異常處理情形</h5>';
    var corrDue='預定完成日：'+(o.correction_due?esc(o.correction_due).replace(/-/g,'.'):'未填寫');
    var prevDue='預定完成日：'+(o.prevention_due?esc(o.prevention_due).replace(/-/g,'.'):'未填寫');
    h+=secBox('異常原因分析','cause',causeEdit,causeRO);
    h+=secBox('矯正措施','correction',dispEdit,corrRO,corrDue);
    h+=secBox('預防措施','prevention',prevEdit,prevRO,prevDue);
    if(editable){
      h+='<div style="margin-bottom:8px;"><button class="btn btn-default btn-sm" id="btn-save-reply"><i class="fa fa-save"></i> 儲存草稿</button> '
        +'<button class="btn btn-primary btn-sm" id="btn-submit-reply"><i class="fa fa-paper-plane"></i> 送出（待主管簽核）</button> '
        +'<span class="text-muted" id="reply-msg"></span></div>';
    }
    return h;
  }

  // 效果確認區。版面：左=主管簽核(縱向整併)；右上=效果確認(結案/不可結案+裁決)；右下=總經理核准＋扣款判定(合併,印章)
  function renderDecision(o, perm, sigMap, atts){
    var reached = ['pending_primary','pending_final','closed','rejected'].indexOf(o.status)>=0;
    if(!reached) return '';
    function sigLine(x){ return x ? '<div style="text-align:right;margin-top:2px;">'+EGStamp.stamp(x.name, x.date)+'</div>' : '<span class="text-muted">待簽核</span>'; }
    var h='<h5 style="margin-top:12px;">效果確認</h5><div class="panel panel-default"><div class="panel-body" style="padding:0;">';
    h+='<div style="display:flex;align-items:stretch;">';

    // 左欄：上=主管簽核、下=扣款判定
    h+='<div style="flex:1;border-right:1px solid #eee;display:flex;flex-direction:column;">';
    h+='<div style="padding:10px 12px;flex:1;display:flex;flex-direction:column;">'
      +'<b>主管簽核：</b>'
      +(perm.can_sign_primary?'<div style="margin-top:6px;"><button class="btn btn-success btn-xs" id="btn-primary-sign"><i class="fa fa-pencil"></i> 簽核通過</button></div>':'')
      +'<div style="flex:1;"></div>'+sigLine(sigMap['primary'])
      +'</div>';
    // 左下：扣款判定（機密——僅扣款判定人員/最終決策者本人可見；欄位加高容納圖章）
    if(o.status==='closed' && (perm.can_see_deduct || perm.can_deduct)){
      h+='<div style="border-top:1px solid #eee;padding:10px 12px;min-height:120px;"><b>扣款判定：</b>';
      if(o.deduct_at){
        var amt=parseFloat(o.deduct_amount||0);
        h+='<div style="display:flex;justify-content:space-between;align-items:flex-end;">'
          +'<span>'+(amt>0?('扣款 <b>'+amt.toLocaleString()+'</b> 元'):'<b>不扣款</b>')
          +(o.deduct_note?('<br><span class="text-muted" style="font-size:12px;">備註：'+esc(o.deduct_note)+'</span>'):'')+'</span>'
          +EGStamp.stamp(o.deduct_by_name||'', (o.deduct_at||'').substring(0,10).replace(/-/g,'.'))
          +'</div>';
      } else if(perm.can_deduct){
        h+='<div style="margin-top:6px;" class="form-inline">'
          +'<input type="number" id="deduct-amount" class="form-control input-sm" style="width:130px;" placeholder="扣款金額(0=不扣)" step="any" min="0"> '
          +'<input type="text" id="deduct-note" class="form-control input-sm" style="width:150px;" placeholder="備註（選填）"> '
          +'<button class="btn btn-primary btn-sm" id="btn-deduct-sign"><i class="fa fa-pencil"></i> 判定簽核</button></div>';
      } else {
        h+='<span class="text-muted">待判定</span>';
      }
      h+='</div>';
    }
    h+='</div>';   // 左欄

    // 右欄：上=效果確認、下=總經理核准
    h+='<div style="flex:1.4;display:flex;flex-direction:column;">';
    h+='<div style="padding:10px 12px;border-bottom:1px solid #eee;"><b>效果確認：</b>';
    if(o.result==='close'){ h+=' <span class="label label-success">結案</span> 結案日期 '+esc((o.close_date||'').replace(/-/g,'.')); }
    else if(o.result==='not_close'){ h+=' <span class="label label-danger">不可結案</span>'+(o.not_close_reason?(' 原因：'+esc(o.not_close_reason)):''); }
    else { h+=' <span class="text-muted">□ 結案　□ 不可結案（待總經理裁決）</span>'; }
    if(perm.can_final){
      h+='<div style="margin-top:6px;">'
        +'<div class="form-inline" style="margin-bottom:4px;">'
        +'<input type="number" id="final-deduct-amount" class="form-control input-sm" style="width:150px;" placeholder="扣款金額(未填=不扣款)" step="any" min="0"> '
        +'<input type="text" id="final-deduct-note" class="form-control input-sm" style="width:160px;" placeholder="扣款備註(選填)"></div>'
        +'<button class="btn btn-success btn-sm" id="btn-final-close"><i class="fa fa-check"></i> 結案</button> '
        +'<button class="btn btn-danger btn-sm" id="btn-final-reject"><i class="fa fa-times"></i> 不可結案（退件）</button></div>';
    }
    h+='</div>';
    h+='<div style="padding:10px 12px;flex:1;display:flex;flex-direction:column;">'
      +'<b>總經理核准：</b>'
      +'<div style="flex:1;"></div>'+sigLine(sigMap['final'])
      +'</div>';
    h+='</div>';   // 右欄
    h+='</div>';   // flex 列
    // 效果確認附件（整寬）
    h+='<div style="border-top:1px solid #eee;padding:8px 12px;"><b>效果確認附件：</b>'
      + attList(atts, 'result', !!(perm.can_sign_primary||perm.can_final||perm.can_deduct)) + '</div>';
    h+='</div></div>';
    return h;
  }

  // ---------- 列印 / CSV / 統計 ----------
  function curFilters(){ return { card:state.card, resp:$('#f-dept').val()||'', source_type:$('#f-source').val()||'', kw:$('#f-kw').val()||'' }; }
  function printShell(title, bodyHtml, header, footer){
    var w=window.open('','_blank');
    w.document.write('<!DOCTYPE html><html><head><meta charset="utf-8"><title>'+esc(title)+'</title>'
      +'<style>@page{size:A4;margin:9mm;}'
      +'body{font-family:"Microsoft JhengHei","PMingLiU",sans-serif;font-size:12px;margin:6px;color:#000;}'
      // 內容過多時允許換頁：整張表格為單位乾淨地移到下一頁（page-break-inside:avoid），不會從儲存格中間截斷
      +'table{border-collapse:collapse;width:100%;margin-bottom:5px;page-break-inside:avoid;}th,td{border:1px solid #000;padding:3px 6px;font-size:12px;vertical-align:top;}'
      +'th{background:#f0f0f0;white-space:nowrap;}'
      +'.hd{text-align:center;font-size:16px;font-weight:bold;margin-bottom:5px;}'
      +'.ft{margin-top:5px;font-size:10px;text-align:right;page-break-inside:avoid;}'
      +'.sec{white-space:pre-wrap;min-height:42px;}'
      +'.srow{display:flex;justify-content:flex-end;align-items:flex-end;gap:4px;margin-top:1px;}'
      +'.srow2{display:flex;justify-content:space-between;align-items:flex-end;margin-top:1px;}'   /* 左=預定完成日、右=印章 */
      +'svg.car-stamp{width:66px !important;height:66px !important;}'
      +'h2{font-size:16px;}'
      +'</style></head><body>'
      +(header?'<div class="hd">'+esc(header)+'</div>':'')
      +bodyHtml
      +(footer?'<div class="ft">'+esc(footer)+'</div>':'')
      +'<scr'+'ipt>window.onload=function(){window.print();};<\/scr'+'ipt>'
      +'</body></html>');
    w.document.close();
  }
  function pStamp(x){ return x ? ('<div class="srow"><span style="font-size:11px;color:#555;">簽章：</span>'+EGStamp.stamp(x.name,x.date)+'</div>') : ''; }
  // 左下=預定完成日等文字、右下=簽章印章（同一列底部對齊）
  function pStampRow(leftTxt, x){
    return '<div class="srow2"><span style="font-size:11px;color:#555;">'+leftTxt+'</span>'
      +(x?('<span style="display:inline-flex;align-items:flex-end;gap:4px;"><span style="font-size:11px;color:#555;">簽章：</span>'+EGStamp.stamp(x.name,x.date)+'</span>'):'<span></span>')+'</div>';
  }
  // 單張列印（仿紙本 2-QA-01-04 版式）
  function printOrder(id){
    api('get_detail',{id:id}).done(function(r){
      if(!r||!r.success){ alert(r&&r.message||'載入失敗'); return; }
      var o=r.order, L=r.labels;
      window.__ownCompany = r.own_company || window.__ownCompany || '';
      var sm={}; (r.signatures||[]).forEach(function(s){ if(!parseInt(s.revoked,10)) sm[s.section]={name:s.signed_name,date:s.signed_date_label}; });
      // 勾選清單：未選填也印出全部選項（☑ 已選 / ☐ 未選），比照紙本表單
      function ckList(map, selected, otherVal){
        var h=''; Object.keys(map).forEach(function(k){ h+=((selected||[]).indexOf(k)>=0?'☑ ':'☐ ')+esc(map[k])+'　'; });
        if(otherVal) h+='：'+esc(otherVal);
        return h;
      }
      var ciKeys=(o.cause_investigation||'').split(',').filter(Boolean);
      var h='<h2 style="text-align:center;margin:0 0 6px;">異常矯正處理單</h2>'
        +'<table><tr><th style="width:90px;">表單編號</th><td>'+esc(o.car_no||'（未配號）')+'</td>'
        +'<th style="width:90px;">異常來源</th><td>'+ckList(L.source_type,[o.source_type])+(o.source_no?('　對應單號：'+esc(o.source_no)):'')+'</td></tr>'
        +'<tr><th>客戶/供應商</th><td>'+esc(String(o.counterparty_display||'').replace(/^\[.\]\s*/,''))+'</td><th>料號</th><td>'+esc(o.drawing_no||'')+'</td></tr>'
        +'<tr><th>廠內製令單號</th><td>'+esc(o.work_order||'')+'</td><th>數量</th><td>'+esc(o.qty!=null?parseFloat(o.qty):'')+'</td></tr>'
        +'<tr><th>填表日期</th><td>'+esc(o.fill_date||'')+'</td><th>發現日期</th><td>'+esc(o.found_date||'')+'</td></tr>'
        +'<tr><th>填表人</th><td>'+esc(o.created_by_name||'')+'</td><th>製程</th><td>'+esc(o.process_name||'')+'</td></tr>'
        +'<tr><th>責任單位</th><td colspan="3">'+esc(o.resp_display||'')+'</td></tr></table>'
        +'<table><tr><th style="width:90px;">異常說明</th><td><div class="sec">'+esc(o.abnormal_desc||'')+'</div>'+pStamp(sm['desc'])+'</td></tr>'
        +'<tr><th>異常原因分析</th><td>原因調查：'+ckList(L.cause, ciKeys, o.cause_other)
          +'<div class="sec">'+esc(o.cause_detail||'')+'</div>'+pStamp(sm['cause'])+'</td></tr>'
        +'<tr><th>矯正措施</th><td>處置方式：'+ckList(L.disposition, [o.disposition], o.disposition_other)
          +'<div class="sec">'+esc(o.correction_measure||'')+'</div>'+pStampRow('預定完成日：'+(o.correction_due?esc(o.correction_due).replace(/-/g,'.'):'未填寫'), sm['correction'])+'</td></tr>'
        +'<tr><th>預防措施</th><td><div class="sec">'+esc(o.prevention_measure||'')+'</div>'+pStampRow('預定完成日：'+(o.prevention_due?esc(o.prevention_due).replace(/-/g,'.'):'未填寫'), sm['prevention'])+'</td></tr></table>'
        // 效果確認 2×2 格（同前端跳窗版面）：左上=主管簽核、左下=扣款判定、右上=效果確認、右下=總經理核准
        +(function(){
          var resultTxt = (o.result==='close') ? ('☑ 結案，結案日期：'+esc((o.close_date||'').replace(/-/g,'.')))
                        : (o.result==='not_close') ? ('☑ 不可結案'+(o.not_close_reason?('，原因：'+esc(o.not_close_reason)):''))
                        : '□ 結案　□ 不可結案';
          var hasDeduct = !!o.deduct_at;
          var t='<table><tr>'
            +'<td style="width:42%;"'+(hasDeduct?'':' rowspan="2"')+'><b>主管簽核：</b>'+(sm['primary']?pStamp(sm['primary']):'')+'</td>'
            +'<td><b>效果確認：</b>'+resultTxt+'</td></tr>'
            +'<tr>'
            +(hasDeduct?('<td><b>扣款判定：</b>'
              +(parseFloat(o.deduct_amount||0)>0?('扣款 '+parseFloat(o.deduct_amount).toLocaleString()+' 元'):'不扣款')
              +(o.deduct_note?('　備註：'+esc(o.deduct_note)):'')
              +pStamp({name:o.deduct_by_name||'', date:(o.deduct_at||'').substring(0,10).replace(/-/g,'.')})+'</td>'):'')
            +'<td><b>總經理核准：</b>'+(sm['final']?pStamp(sm['final']):'')+'</td></tr></table>';
          return t;
        })()
        +'<div style="font-size:11px;">表單流程：申請人員(填寫)→責任單位(回覆異常原因分析、異常處理情形)→主管簽核→總經理(核准)→管理課(扣款判定)</div>';
      printShell('異常矯正處理單 '+(o.car_no||''), h, r.print_header||'', r.print_footer||'');
    });
  }
  // 總表列印（依目前篩選）
  function printList(){
    var f=curFilters(); f.all=1;
    api('load_page_data', f).done(function(r){
      if(!r||!r.success){ alert(r&&r.message||'載入失敗'); return; }
      var h='<h2 style="text-align:center;margin:0 0 6px;">異常矯正處理單 總表</h2>'
        +'<div style="font-size:11px;margin-bottom:4px;">列印時間：'+new Date().toLocaleString()+'　共 '+(r.total||0)+' 筆</div>'
        +'<table><tr><th>表單編號</th><th>來源</th><th>客戶/供應商</th><th>料號</th><th>責任單位</th><th>狀態</th><th>狀態日期</th><th>填表人</th><th>填表日期</th></tr>';
      (r.rows||[]).forEach(function(o){
        var stDate = o.latest ? fmtDT(o.latest.created_at) : '';   // 狀態日期＝最近一次處理時間
        h+='<tr><td>'+esc(o.car_no||'（未配號）')+'</td><td>'+esc(o.source_label)+'</td>'
          +'<td>'+esc(String(o.counterparty_display||'').replace(/^\[.\]\s*/,''))+'</td><td>'+esc(o.drawing_no||'')+'</td>'
          +'<td>'+esc(o.resp_show||'')+'</td><td>'+esc(o.status_label)+'</td><td>'+stDate+'</td>'
          +'<td>'+esc(o.created_by_name||'')+'</td><td>'+esc(o.fill_date||'')+'</td></tr>';
      });
      h+='</table>';
      printShell('異常矯正處理單 總表', h, r.print_header||'', r.print_footer||'');
    });
  }
  // 統計表
  function openStats(){
    $('#stats-body').html('載入中…'); $('#statsModal').modal('show');
    api('get_stats_report').done(function(r){
      if(!r||!r.success){ $('#stats-body').html('<div class="text-danger">'+esc(r&&r.message||'載入失敗')+'</div>'); return; }
      function tbl(title, heads, rows){
        var h='<h5><b>'+title+'</b></h5><table class="table table-bordered table-condensed"><tr>';
        heads.forEach(function(x){ h+='<th>'+x+'</th>'; }); h+='</tr>';
        rows.forEach(function(cells){ h+='<tr>'; cells.forEach(function(c){ h+='<td>'+c+'</td>'; }); h+='</tr>'; });
        return h+'</table>';
      }
      var h='<div class="row"><div class="col-md-6">';
      h+=tbl('依狀態',['狀態','件數'],(r.by_status||[]).map(function(x){return [esc(x.label),x.c];}));
      h+=tbl('依來源',['異常來源','件數'],(r.by_source||[]).map(function(x){return [esc(x.label),x.c];}));
      h+='</div><div class="col-md-6">';
      h+=tbl('依月份',['月份','開立','結案'],(r.by_month||[]).map(function(x){return [esc(x.m),x.c,x.closed_c];}));
      h+='</div></div>';
      if(r.show_deduct){
        h+=tbl('依責任單位',['責任單位','件數','已結案','不可結案','累計扣款'],(r.by_resp||[]).map(function(x){
          return [esc(x.name),x.c,x.closed_c,x.rejected_c,parseFloat(x.deduct_sum||0).toLocaleString()];}));
      } else {
        h+=tbl('依責任單位',['責任單位','件數','已結案','不可結案'],(r.by_resp||[]).map(function(x){
          return [esc(x.name),x.c,x.closed_c,x.rejected_c];}));
      }
      $('#stats-body').html(h);
    });
  }
  window.printStats = function(){ printShell('異常矯正處理單 統計表', $('#stats-body').html(), '', ''); };
  $(function(){
    $('#btn-csv').on('click', function(){ window.location = API + '?action=export_csv&' + $.param(curFilters()); });
    $('#btn-print-list').on('click', printList);
    $('#btn-stats').on('click', openStats);
    $('#btn-print-one').on('click', function(){ if(curViewId) printOrder(curViewId); });
  });

  // ---------- 權限設定（角色） ----------
  var ROLES_API = '../../src/store/Roles_API.php';
  var CAR_FEATURES = [
    ['car_view','檢閱（查看列表與單據）'],
    ['car_create','開立新單'],
    ['car_edit','修改單據（限自己開立）'],
    ['car_delete','刪除單據（限自己開立）'],
    ['car_manage_settings','管理設定（主管層級/生管/總經理/附件路徑）'],
    ['car_assign','指派回覆人（主管）'],
    ['car_reply','回覆填寫（被指派者）'],
    ['car_sign_primary','首要決策者簽核'],
    ['car_sign_final','最終決策者裁決']
  ];
  var curRole = null;
  function loadRoles(){
    $.get(ROLES_API, {action:'get_roles', module:'car'}, function(r){
      if(!r||!r.success){ $('#role-list').html('<div class="text-danger">'+esc(r&&r.message||'載入失敗')+'</div>'); return; }
      var h=''; r.data.forEach(function(ro){
        var sys = parseInt(ro.is_system,10)===1;
        h += '<a href="#" class="list-group-item role-item" data-id="'+ro.role_id+'" data-name="'+esc(ro.role_name)+'" data-sys="'+(sys?1:0)+'">'
           + esc(ro.role_name) + (sys?' <span class="label label-info pull-right">系統(全權)</span>':'') + '</a>';
      });
      $('#role-list').html(h||'<div class="text-muted">尚無角色</div>');
      $('.role-item').on('click', function(e){ e.preventDefault(); $('.role-item').removeClass('active'); $(this).addClass('active');
        selectRole($(this).data('id'), $(this).data('name'), $(this).data('sys')==1); });
    }, 'json');
  }
  function selectRole(rid, rname, isSys){
    curRole = {id:rid, name:rname, sys:isSys};
    $('#role-feat-empty').hide(); $('#role-feat-area').show(); $('#rf-role-name').text(rname); $('#rf-msg').text('');
    $.get(ROLES_API, {action:'get_role_features', role_id:rid}, function(r){
      var have = (r&&r.success)? r.data : [];
      if(isSys) have = CAR_FEATURES.map(function(f){return f[0];}); // 系統角色全權
      var h=''; CAR_FEATURES.forEach(function(f){
        h += '<div class="checkbox"><label><input type="checkbox" class="rf-chk" value="'+f[0]+'" '
           + (have.indexOf(f[0])>=0?'checked':'') + (isSys?' disabled':'') + '> '+esc(f[1])+' <code>'+f[0]+'</code></label></div>';
      });
      $('#rf-checks').html(h);
      $('#btn-save-feats,#btn-del-role,#btn-rename-role').prop('disabled', isSys);
    }, 'json');
  }
  // ---------- 簽核流程設定 ----------
  var fsAdminUsers = [];   // [{id,user_cname}]
  function fsRenderChips(){
    var h=''; fsAdminUsers.forEach(function(u,i){ h+='<span class="resp-chip">'+esc(u.user_cname)+'<span class="x" data-i="'+i+'">&times;</span></span>'; });
    $('#fs-au-chips').html(h||'<span class="text-muted" style="font-size:12px;">（未指定額外人員）</span>');
    $('#fs-au-chips .x').on('click', function(){ fsAdminUsers.splice($(this).data('i'),1); fsRenderChips(); });
  }
  function openFlowSettings(){
    $('#fs-msg').text('載入中…');
    api('get_flow_settings').done(function(r){
      if(!r||!r.success){ $('#fs-msg').text(r&&r.message||'載入失敗'); return; }
      $('#fs-msg').text('');
      $('#fs-level').val(String(r.supervisor_min_level||2));
      $('#fs-attach-path').val(r.attach_root_path||'');
      $('#fs-print-header').val(r.print_header||'');
      $('#fs-print-footer').val(r.print_footer||'');
      var posH='<option value="">請選擇職位…</option>';
      (r.positions||[]).forEach(function(p){ posH+='<option value="'+esc(p.name)+'"'+(p.name===r.final_decider_position?' selected':'')+'>'+esc(p.name)+'</option>'; });
      $('#fs-final-pos').html(posH);
      // 生管部門 / 管理課 → 單選 radio
      function deptRadios(list, checkedId, name){ var h=''; (list||[]).forEach(function(d){
        h+='<label class="radio-inline" style="margin:0 10px 4px 0;"><input type="radio" name="'+name+'" value="'+d.id+'"'+(String(checkedId)===String(d.id)?' checked':'')+'> '+esc(d.name)+'</label>'; }); return h; }
      $('#fs-pm-depts').html(deptRadios(r.depts, (r.pm_dept_ids||[])[0]||'', 'fs-pm'));
      $('#fs-admin-depts').html(deptRadios(r.depts, (r.admin_dept_ids||[])[0]||'', 'fs-admin'));
      // 判定人員固定取自勾選之管理課
      function loadAdminUsers(){
        var d=$('input[name="fs-admin"]:checked').val();
        $('#fs-au-user').html('<option value="">'+(d?'人員…':'請先勾選管理課…')+'</option>');
        if(!d) return;
        api('dept_users',{dept_id:d}).done(function(rr){ if(rr&&rr.success){ rr.data.forEach(function(u){ $('#fs-au-user').append('<option value="'+u.id+'">'+esc(u.user_cname)+'</option>'); }); } });
      }
      $('#fs-admin-depts').off('change').on('change','input[name="fs-admin"]', loadAdminUsers);
      loadAdminUsers();
      fsAdminUsers = (r.admin_users||[]).slice(); fsRenderChips();
      $('#flowModal').modal('show');
    });
  }
  $(function(){
    $('#btn-flow-setting').on('click', function(e){ e.preventDefault(); openFlowSettings(); });
    $('#fs-au-add').on('click', function(){ var uid=$('#fs-au-user').val(); if(!uid){ alert('請先勾選管理課並選擇人員'); return; }
      if(fsAdminUsers.length>=2){ alert('指定判定人員最多 2 人，請先移除既有人員'); return; }
      var nm=$('#fs-au-user option:selected').text();
      if(fsAdminUsers.some(function(u){ return String(u.id)===String(uid); })) return;
      fsAdminUsers.push({id:parseInt(uid,10), user_cname:nm}); fsRenderChips(); });
    $('#btn-flow-save').on('click', function(){
      var pmV=$('input[name="fs-pm"]:checked').val();    var pm = pmV ? [parseInt(pmV,10)] : [];
      var adV=$('input[name="fs-admin"]:checked').val(); var ad = adV ? [parseInt(adV,10)] : [];
      var au=fsAdminUsers.map(function(u){ return u.id; });
      api('save_flow_settings',{ supervisor_min_level:$('#fs-level').val(), pm_dept_ids:JSON.stringify(pm),
        admin_dept_ids:JSON.stringify(ad), admin_user_ids:JSON.stringify(au),
        final_decider_position:$('#fs-final-pos').val()||'', attach_root_path:$('#fs-attach-path').val()||'',
        print_header:$('#fs-print-header').val()||'', print_footer:$('#fs-print-footer').val()||'' })
      .done(function(r){ $('#fs-msg').text(r&&r.message||''); if(r&&r.success) setTimeout(function(){ $('#flowModal').modal('hide'); },500); })
      .fail(function(xhr){ $('#fs-msg').text((xhr.responseJSON&&xhr.responseJSON.message)||'儲存失敗'); });
    });
  });

  $(function(){
    $('#btn-role-setting').on('click', function(e){ e.preventDefault(); $('#role-feat-area').hide(); $('#role-feat-empty').show(); loadRoles(); $('#roleModal').modal('show'); });
    $('#btn-add-role').on('click', function(){ var n=$('#new-role-name').val().trim(); if(!n){ alert('請輸入角色名稱'); return; }
      $.post(ROLES_API, {action:'save_role', role_name:n, module:'car'}, function(r){ if(r&&r.success){ $('#new-role-name').val(''); loadRoles(); } else alert(r&&r.message||'新增失敗'); }, 'json'); });
    $('#btn-rename-role').on('click', function(){ if(!curRole||curRole.sys) return; var n=prompt('新角色名稱', curRole.name); if(!n) return;
      $.post(ROLES_API, {action:'save_role', role_id:curRole.id, role_name:n.trim(), module:'car'}, function(r){ if(r&&r.success){ loadRoles(); } else alert(r&&r.message||'改名失敗'); }, 'json'); });
    $('#btn-del-role').on('click', function(){ if(!curRole||curRole.sys) return; if(!confirm('確定刪除角色「'+curRole.name+'」？此角色的功能與使用者指派都會移除。')) return;
      $.post(ROLES_API, {action:'delete_role', role_id:curRole.id}, function(r){ if(r&&r.success){ $('#role-feat-area').hide(); $('#role-feat-empty').show(); loadRoles(); } else alert(r&&r.message||'刪除失敗'); }, 'json'); });
    $('#btn-save-feats').on('click', function(){ if(!curRole||curRole.sys) return;
      var feats=[]; $('.rf-chk:checked').each(function(){ feats.push($(this).val()); });
      $.post(ROLES_API, {action:'save_role_features', role_id:curRole.id, features:JSON.stringify(feats)}, function(r){ $('#rf-msg').text(r&&r.success?'已儲存':(r&&r.message||'儲存失敗')); }, 'json'); });
  });
})();
</script>
</body>
</html>
