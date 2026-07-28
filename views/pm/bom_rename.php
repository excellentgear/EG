<?php
/**
 * bom_rename.php — 叫料文件（BOM）自動改檔名工具
 * 後端：src/store/store_BomRename_API.php ｜ OCR：src/python/bom_rename_worker.py
 */
session_set_cookie_params(43200);
session_start();

require_once __DIR__ . '/../../src/common/_config.php';
require_once __DIR__ . '/../../src/common/DBConnection.php';
require_once __DIR__ . '/../../src/common/rbac.php';

if (!isset($_SESSION['userName'])) {
    header('Location: ../../index.php');
    exit;
}

$conn = new DBConnection();
$pdo  = $conn->getPDO();
$userId   = intval($_SESSION['id'] ?? 0);
$features = rbac_user_features($pdo, $userId);

$CAN_VIEW     = rbac_has($features, 'bom_rename_view');
$CAN_OPERATE  = rbac_has($features, 'bom_rename_operate');
$CAN_SETTINGS = rbac_has($features, 'bom_rename_manage_settings');

$permParts = [];
if ($CAN_VIEW) $permParts[] = '檢閱';
if ($CAN_OPERATE) $permParts[] = '核對確認';
if ($CAN_SETTINGS) $permParts[] = '管理設定';
$permBadge = $permParts ? implode('+', $permParts) : '無';
?>
<!DOCTYPE html>
<html lang="zh-Hant">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>叫料文件自動改檔名工具</title>
    <link href="../../resource/css/bootstrap.css" rel="stylesheet">
    <link href="../../resource/css/font-awesome.css" rel="stylesheet">
    <link href="../../resource/css/custom.css" rel="stylesheet">
    <style>
        .br-wrap{ display:flex; gap:16px; }
        .br-list{ width:230px; flex:0 0 230px; max-height:74vh; overflow:auto; border:1px solid #e3e8ee; border-radius:6px; }
        .br-list .item{ padding:8px 10px; border-bottom:1px solid #eef1f4; cursor:pointer; font-size:12px; word-break:break-all; }
        .br-list .item:hover{ background:#f5f8fb; }
        .br-list .item.active{ background:#eaf4fd; border-left:3px solid #2980b9; }
        .br-list .item .st{ float:right; }
        .br-list .item .badge-ok{ background:#27ae60; }
        .br-list .item .badge-empty{ background:#bbb; }
        .br-main{ flex:1 1 auto; min-width:0; }
        .br-viewer{ position:relative; background:#222; border-radius:6px; overflow:hidden; min-height:420px; display:flex; align-items:center; justify-content:center; }
        .br-viewer img{ max-width:100%; max-height:68vh; transition:transform .15s ease; }
        .br-boms{ margin-top:10px; }
        .bom-row{ display:flex; align-items:center; gap:6px; margin-bottom:6px; }
        .bom-row .addon{ font-family:monospace; background:#f5f7fa; border:1px solid #e3e8ee; border-right:0; border-radius:4px 0 0 4px; padding:5px 8px; }
        .bom-row input{ font-family:monospace; letter-spacing:1px; border-radius:0 4px 4px 0 !important; }
        .bom-row .erp-flag{ font-size:11px; white-space:nowrap; width:90px; }
        .bom-row .erp-ok{ color:#27ae60; }
        .bom-row .erp-warn{ color:#c0392b; }
        .crop-preview-box{ position:relative; width:100%; height:60px; background:repeating-linear-gradient(45deg,#f5f5f5,#f5f5f5 6px,#eee 6px,#eee 12px); border:1px solid #ddd; border-radius:4px; overflow:hidden; }
        .crop-preview-box .rect{ position:absolute; background:rgba(41,128,185,.35); border:1px solid #2980b9; }
        .br-done-card{ text-align:center; padding:40px 20px; color:#2c3e50; }
        .br-empty-card{ text-align:center; padding:60px 20px; color:#999; }
        .br-share-note{ font-size:12px; color:#888; margin-top:4px; }
        #bomSourceHint{ font-size:12px; color:#a66a00; }
        .br-env-status{ font-size:11px; margin-top:3px; }
        .br-env-status.ok{ color:#27ae60; }
        .br-env-status.bad{ color:#c0392b; }
        .br-env-status.auto{ color:#8a9bab; }
    </style>
</head>
<body class="nav-sm">
<div class="container body">
  <div class="main_container">
    <?php include '../partPage/sideAndTopBarMenu.html'; ?>

    <div class="right_col" role="main">
      <div class="page-title">
        <div class="title_left">
          <h3>叫料文件自動改檔名工具
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
              <li><a href="#" id="btn-role-setting"><i class="fa fa-key"></i> 權限設定（角色）</a></li>
            </ul>
          </div>
        </div></div>
        <?php endif; ?>
      </div>
      <div class="clearfix"></div>

      <?php if (!$CAN_VIEW): ?>
        <div class="alert alert-danger"><i class="fa fa-ban"></i> 您沒有「叫料文件自動改檔名工具」的檢閱權限，請洽管理員於 <b>權限設定（角色 → 叫料文件自動改檔名工具）</b> 開通。</div>
      <?php else: ?>

      <!-- 設定區塊 -->
      <div class="row"><div class="col-md-12"><div class="x_panel">
        <div class="x_title"><h2><i class="fa fa-cog"></i> 資料夾與 OCR 設定</h2>
          <ul class="nav navbar-right panel_toolbox"><li><a class="collapse-link"><i class="fa fa-chevron-up"></i></a></li></ul>
          <div class="clearfix"></div>
        </div>
        <div class="x_content">
          <div class="row">
            <div class="col-md-6">
              <label>來源資料夾（伺服器/NAS 路徑）</label>
              <input type="text" id="s-source" class="form-control input-sm" placeholder="例如 \\excellentnas\共用\待辨識" <?php echo $CAN_SETTINGS ? '' : 'readonly'; ?>>
              <div class="br-share-note"><i class="fa fa-info-circle"></i> 與「圖面自動改檔名工具」共用同一個來源資料夾設定，改這裡對面工具也會一起變。</div>
            </div>
            <div class="col-md-3">
              <label>Python 執行檔路徑</label>
              <input type="text" id="s-python" class="form-control input-sm" placeholder="留空＝自動偵測伺服器上的安裝位置" <?php echo $CAN_SETTINGS ? '' : 'readonly'; ?>>
              <div class="br-env-status" id="py-status"></div>
            </div>
            <div class="col-md-3">
              <label>Tesseract 執行檔路徑</label>
              <input type="text" id="s-tesseract" class="form-control input-sm" placeholder="留空＝自動偵測伺服器上的安裝位置" <?php echo $CAN_SETTINGS ? '' : 'readonly'; ?>>
              <div class="br-env-status" id="tess-status"></div>
            </div>
          </div>
          <div class="row" style="margin-top:6px;">
            <div class="col-md-12">
              <div class="br-share-note"><i class="fa fa-server"></i> <b>這兩個路徑指的是「伺服器主機」（MAMP 那台機器）上的執行檔位置，不是您自己電腦的路徑</b>——一般操作者用自己電腦連進來使用本工具，不需要在自己電腦安裝 Python 或 Tesseract。留空會自動偵測伺服器上的安裝位置；伺服器上如果沒有裝，「開始掃描」仍可正常使用，只是會自動切換為純人工輸入模式，不會卡住。
                <?php if ($CAN_SETTINGS): ?><button class="btn btn-default btn-xs" id="btn-check-env" style="margin-left:6px;"><i class="fa fa-plug"></i> 測試連線</button><?php endif; ?>
              </div>
            </div>
          </div>
          <div class="row" style="margin-top:10px;">
            <div class="col-md-8">
              <label>OCR 裁切區域（製令單號欄，單位：頁面百分比%）</label>
              <div class="row">
                <div class="col-md-3"><div class="input-group input-group-sm"><span class="input-group-addon">左</span><input type="number" id="c-left" class="form-control" min="0" max="100" <?php echo $CAN_SETTINGS ? '' : 'readonly'; ?>></div></div>
                <div class="col-md-3"><div class="input-group input-group-sm"><span class="input-group-addon">上</span><input type="number" id="c-top" class="form-control" min="0" max="100" <?php echo $CAN_SETTINGS ? '' : 'readonly'; ?>></div></div>
                <div class="col-md-3"><div class="input-group input-group-sm"><span class="input-group-addon">寬</span><input type="number" id="c-width" class="form-control" min="1" max="100" <?php echo $CAN_SETTINGS ? '' : 'readonly'; ?>></div></div>
                <div class="col-md-3"><div class="input-group input-group-sm"><span class="input-group-addon">高</span><input type="number" id="c-height" class="form-control" min="1" max="100" <?php echo $CAN_SETTINGS ? '' : 'readonly'; ?>></div></div>
              </div>
              <div class="crop-preview-box" style="margin-top:6px;"><div class="rect" id="crop-rect-preview"></div></div>
              <div class="br-share-note">此為預設猜測值，請依實際單據版面微調；OCR 只是輔助草稿，最終仍以人工核對為準。</div>
            </div>
            <div class="col-md-4 text-right" style="align-self:flex-end;">
              <?php if ($CAN_SETTINGS): ?>
              <button class="btn btn-primary btn-sm" id="btn-save-settings"><i class="fa fa-save"></i> 儲存設定</button>
              <?php endif; ?>
              <button class="btn btn-default btn-sm" id="btn-scan"><i class="fa fa-search"></i> 開始掃描</button>
            </div>
          </div>
        </div>
      </div></div></div>

      <div id="bomSetupHint" class="alert alert-warning" style="display:none;"><i class="fa fa-info-circle"></i> 請先設定「來源資料夾」後，按「開始掃描」開始。</div>
      <div id="ocrWarnHint" class="alert alert-warning" style="display:none;"><i class="fa fa-exclamation-triangle"></i> OCR 目前無法使用（<span id="ocrWarnMsg"></span>），已切換為純人工輸入模式，不影響手動作業。</div>

      <!-- 審核區 -->
      <div id="br-review-panel" class="row" style="display:none;"><div class="col-md-12"><div class="x_panel"><div class="x_content">
        <div class="br-wrap">
          <div class="br-list" id="br-list"></div>
          <div class="br-main">
            <div class="dr-progress" id="br-progress" style="font-size:13px;color:#666;margin-bottom:6px;"></div>
            <div class="br-viewer" id="br-viewer"></div>
            <div style="margin-top:8px;">
              <button class="btn btn-default btn-sm" id="btn-rotate-left"><i class="fa fa-rotate-left"></i> 左轉（僅預覽）</button>
              <button class="btn btn-default btn-sm" id="btn-rotate-right"><i class="fa fa-rotate-right"></i> 右轉（僅預覽）</button>
            </div>
            <div class="br-boms">
              <label>製令單號（可能不只一個，跳號也沒關係；空白列會被忽略）</label>
              <div id="bom-rows"></div>
              <button class="btn btn-default btn-sm" id="btn-add-bom-row"><i class="fa fa-plus"></i> 新增一列</button>
            </div>
            <div style="margin-top:16px;">
              <button class="btn btn-default btn-sm" id="btn-prev-file"><i class="fa fa-arrow-left"></i> 上一個</button>
              <button class="btn btn-default btn-sm" id="btn-next-file">下一個 <i class="fa fa-arrow-right"></i></button>
              <button class="btn btn-success btn-sm pull-right" id="btn-commit" <?php echo $CAN_OPERATE ? '' : 'disabled'; ?>><i class="fa fa-check"></i> 全部確認，開始產生檔案</button>
            </div>
          </div>
        </div>
      </div></div></div></div>

      <div id="br-done-card" class="row" style="display:none;"><div class="col-md-12"><div class="x_panel"><div class="x_content">
        <div class="br-done-card">
          <i class="fa fa-check-circle" style="font-size:48px;color:#27ae60;"></i>
          <h3>處理完成</h3>
          <div id="br-done-summary" style="text-align:left;display:inline-block;margin-top:10px;"></div>
          <div style="margin-top:14px;"><button class="btn btn-primary" id="btn-rescan"><i class="fa fa-refresh"></i> 重新掃描</button></div>
        </div>
      </div></div></div></div>

      <div id="br-empty-card" class="row" style="display:none;"><div class="col-md-12"><div class="x_panel"><div class="x_content">
        <div class="br-empty-card"><i class="fa fa-folder-open-o" style="font-size:40px;"></i><p>來源資料夾目前沒有待處理檔案。</p></div>
      </div></div></div></div>

      <?php endif; /* CAN_VIEW */ ?>
    </div>
  </div>
</div>

<!-- 權限設定（角色）Modal -->
<div class="modal fade" id="roleModal" tabindex="-1" role="dialog"><div class="modal-dialog modal-lg"><div class="modal-content">
  <div class="modal-header"><button type="button" class="close" data-dismiss="modal">&times;</button>
    <h4 class="modal-title"><i class="fa fa-key"></i> 叫料文件自動改檔名工具 — 權限設定（角色）</h4></div>
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

<!-- 權限說明 Modal -->
<div class="modal fade" id="permHelp" tabindex="-1" role="dialog">
  <div class="modal-dialog" role="document"><div class="modal-content">
    <div class="modal-header"><button type="button" class="close" data-dismiss="modal">&times;</button><h4 class="modal-title">各角色權限說明</h4></div>
    <div class="modal-body">
      <ul>
        <li><b>檢閱</b>：可進入頁面、掃描來源資料夾、查看預覽與 OCR 草稿。</li>
        <li><b>核對確認</b>：可編輯製令單號清單並執行「全部確認，開始產生檔案」。</li>
        <li><b>管理設定</b>：可修改來源資料夾、OCR 裁切區域、Python/Tesseract 路徑。</li>
        <li><b>管理者</b>角色固定擁有以上全部權限。</li>
      </ul>
      <p class="text-muted">本工具不做「作廢」——目標檔名已存在一律跳過，不會覆蓋既有檔案。</p>
    </div>
  </div></div>
</div>

<script src="../../resource/js/jquery.min.js"></script>
<script src="../../resource/js/bootstrap.min.js"></script>
<script src="../../resource/js/custom.min.js"></script>
<script>
(function(){
  var API = '../../src/store/store_BomRename_API.php';
  var CAN_OPERATE = <?php echo $CAN_OPERATE ? 'true' : 'false'; ?>;
  var settings = { source_dir:'', crop_left:0, crop_top:0, crop_width:35, crop_height:100, python_exe:'', tesseract_exe:'' };
  var files = [];       // 掃描結果
  var fileState = {};   // name -> { boms: [...] }
  var idx = -1;
  var rotate = 0;

  function esc(s){ return $('<div>').text(s == null ? '' : s).html(); }

  function loadSettings(cb){
    $.post(API, {action:'get_settings'}, function(r){
      if (r && r.success) {
        settings = r.settings;
        $('#s-source').val(settings.source_dir);
        // 留空欄位＝跟著伺服器自動偵測；欄位不直接填入偵測結果，避免使用者一按「儲存設定」就把自動值誤存成寫死值
        $('#s-python').val(settings.python_is_auto ? '' : settings.python_exe);
        $('#s-tesseract').val(settings.tesseract_is_auto ? '' : settings.tesseract_exe);
        if (settings.python_is_auto) {
          $('#py-status').attr('class', 'br-env-status ' + (settings.python_exe ? 'auto' : 'bad'))
            .text(settings.python_exe ? ('自動偵測到：' + settings.python_exe) : '伺服器上偵測不到 Python，掃描會退化為純人工輸入');
        } else { $('#py-status').text(''); }
        if (settings.tesseract_is_auto) {
          $('#tess-status').attr('class', 'br-env-status ' + (settings.tesseract_exe ? 'auto' : 'bad'))
            .text(settings.tesseract_exe ? ('自動偵測到：' + settings.tesseract_exe) : '伺服器上偵測不到 Tesseract，掃描會退化為純人工輸入');
        } else { $('#tess-status').text(''); }
        $('#c-left').val(settings.crop_left);
        $('#c-top').val(settings.crop_top);
        $('#c-width').val(settings.crop_width);
        $('#c-height').val(settings.crop_height);
        updateCropPreview();
      }
      if (cb) cb();
    }, 'json');
  }

  $('#btn-check-env').on('click', function(){
    var $btn = $(this).prop('disabled', true).html('<i class="fa fa-spinner fa-spin"></i> 測試中…');
    $.post(API, { action:'check_env', python_exe: $('#s-python').val(), tesseract_exe: $('#s-tesseract').val() }, function(r){
      $btn.prop('disabled', false).html('<i class="fa fa-plug"></i> 測試連線');
      if (!r || !r.success) { alert('測試失敗：' + (r && r.message ? r.message : '未知錯誤')); return; }
      paintEnvStatus('#py-status', r.python);
      paintEnvStatus('#tess-status', r.tesseract);
    }, 'json').fail(function(){
      $btn.prop('disabled', false).html('<i class="fa fa-plug"></i> 測試連線');
      alert('連線失敗，請確認網路');
    });
  });
  function paintEnvStatus(sel, r){
    var $el = $(sel);
    if (r && r.ok) $el.attr('class', 'br-env-status ok').html('<i class="fa fa-check-circle"></i> ' + esc(r.path) + '（' + esc(r.message) + '）');
    else $el.attr('class', 'br-env-status bad').html('<i class="fa fa-times-circle"></i> ' + esc((r && r.path) || '') + ' — ' + esc((r && r.message) || '無法連線'));
  }

  function updateCropPreview(){
    var l = parseFloat($('#c-left').val()) || 0, t = parseFloat($('#c-top').val()) || 0;
    var w = parseFloat($('#c-width').val()) || 0, h = parseFloat($('#c-height').val()) || 0;
    $('#crop-rect-preview').css({ left:l+'%', top:t+'%', width:w+'%', height:h+'%' });
  }
  $('#c-left,#c-top,#c-width,#c-height').on('input', updateCropPreview);

  $('#btn-save-settings').on('click', function(){
    $.post(API, {
      action:'save_settings',
      source_dir: $('#s-source').val(),
      python_exe: $('#s-python').val(),
      tesseract_exe: $('#s-tesseract').val(),
      crop_left: $('#c-left').val(), crop_top: $('#c-top').val(),
      crop_width: $('#c-width').val(), crop_height: $('#c-height').val()
    }, function(r){
      if (r && r.success) { settings = r.settings; alert('設定已儲存'); }
      else alert('儲存失敗：' + (r && r.message ? r.message : '未知錯誤'));
    }, 'json');
  });

  function doScan(){
    if (!settings.source_dir) { $('#bomSetupHint').show(); $('#br-review-panel,#br-done-card,#br-empty-card').hide(); return; }
    $('#bomSetupHint').hide();
    $('#btn-scan').prop('disabled', true).html('<i class="fa fa-spinner fa-spin"></i> 掃描中…');
    $.post(API, {action:'scan'}, function(r){
      $('#btn-scan').prop('disabled', false).html('<i class="fa fa-search"></i> 開始掃描');
      if (!r || !r.success) { alert('掃描失敗：' + (r && r.message ? r.message : '未知錯誤')); return; }
      if (r.ocr_available === false) { $('#ocrWarnMsg').text(r.ocr_message || ''); $('#ocrWarnHint').show(); }
      else { $('#ocrWarnHint').hide(); }
      files = r.files || [];
      fileState = {};
      files.forEach(function(f){ fileState[f.file] = { boms: (f.bom_drafts || []).slice() }; });
      if (files.length === 0) {
        $('#br-review-panel,#br-done-card').hide();
        $('#br-empty-card').show();
        return;
      }
      $('#br-empty-card,#br-done-card').hide();
      $('#br-review-panel').show();
      renderList();
      idx = 0;
      showCurrent();
    }, 'json').fail(function(){
      $('#btn-scan').prop('disabled', false).html('<i class="fa fa-search"></i> 開始掃描');
      alert('掃描請求失敗，請確認連線');
    });
  }
  $('#btn-scan').on('click', doScan);
  $('#btn-rescan').on('click', doScan);

  function renderList(){
    var $l = $('#br-list').empty();
    files.forEach(function(f, i){
      var n = (fileState[f.file].boms || []).filter(function(b){ return b.trim() !== ''; }).length;
      var badge = n > 0 ? '<span class="label label-success st badge-ok">' + n + '</span>' : '<span class="label label-default st badge-empty">未填</span>';
      var $item = $('<div class="item" data-i="' + i + '">').append(esc(f.file)).append(badge);
      if (i === idx) $item.addClass('active');
      $l.append($item);
    });
    $('.br-list .item').on('click', function(){ idx = $(this).data('i'); showCurrent(); });
  }

  function showCurrent(){
    if (idx < 0 || idx >= files.length) return;
    var f = files[idx];
    rotate = 0;
    $('#br-progress').text('第 ' + (idx + 1) + ' / 共 ' + files.length + ' 個　（' + f.file + '　' + (f.kind === 'pdf' ? 'PDF' : '影像') + (f.ocr_used ? '，已跑OCR草稿' : '') + '）');
    var $viewer = $('#br-viewer').empty();
    if (f.preview) {
      var url = API + '?action=preview_file&file=' + encodeURIComponent(f.preview) + '&_=' + idx;
      $viewer.append($('<img id="br-main-img">').attr('src', url));
    } else {
      $viewer.append($('<div style="color:#ccc;padding:40px;">（無預覽圖，請直接手動輸入號碼）</div>'));
    }
    applyRotatePreview();
    renderBomRows();
    renderList();
  }

  function applyRotatePreview(){ $('#br-main-img').css('transform', 'rotate(' + rotate + 'deg)'); }
  $('#btn-rotate-left').on('click', function(){ rotate = (rotate - 90 + 360) % 360; applyRotatePreview(); });
  $('#btn-rotate-right').on('click', function(){ rotate = (rotate + 90) % 360; applyRotatePreview(); });

  function currentBoms(){
    var f = files[idx]; if (!f) return [];
    return fileState[f.file].boms;
  }
  function setCurrentBoms(arr){
    var f = files[idx]; if (!f) return;
    fileState[f.file].boms = arr;
  }

  function renderBomRows(){
    var $rows = $('#bom-rows').empty();
    var boms = currentBoms();
    if (boms.length === 0) boms = [''];
    boms.forEach(function(b, i){ $rows.append(buildBomRow(b, i)); });
  }
  function buildBomRow(value, i){
    var num = (value || '').replace(/^B-/, '');
    var $row = $('<div class="bom-row" data-i="' + i + '">');
    $row.append('<span class="addon">B-</span>');
    var $inp = $('<input type="text" class="form-control input-sm bom-num" maxlength="10">').val(num);
    $row.append($inp);
    var $flag = $('<span class="erp-flag"></span>');
    $row.append($flag);
    var $rm = $('<button class="btn btn-default btn-sm" title="移除"><i class="fa fa-times"></i></button>');
    $row.append($rm);

    $inp.on('input', function(){
      this.value = this.value.replace(/\D/g, '').slice(0, 10);
      syncRowsToState();
      if (this.value.length === 10) {
        var $next = $row.next('.bom-row').find('input');
        if ($next.length) $next.focus(); else $('#btn-add-bom-row').click();
        checkErp('B-' + this.value, $flag);
      } else { $flag.text(''); }
    });
    $inp.on('dblclick', function(){ $(this).val(''); syncRowsToState(); $flag.text(''); });
    $rm.on('click', function(){ $row.remove(); syncRowsToState(); if ($('#bom-rows .bom-row').length === 0) $('#btn-add-bom-row').click(); });
    return $row;
  }
  function syncRowsToState(){
    var arr = [];
    $('#bom-rows .bom-row .bom-num').each(function(){
      var v = $(this).val();
      if (v && v.length === 10) arr.push('B-' + v);
    });
    setCurrentBoms(arr);
    renderList();
  }
  $('#btn-add-bom-row').on('click', function(){
    var i = $('#bom-rows .bom-row').length;
    $('#bom-rows').append(buildBomRow('', i));
  });

  var erpCache = {};
  function checkErp(bom, $flag){
    if (erpCache.hasOwnProperty(bom)) { paintFlag($flag, erpCache[bom]); return; }
    $.get(API, {action:'search_bom', q:bom}, function(r){
      var found = !!(r && r.success && (r.data || []).some(function(d){ return d.bom === bom; }));
      erpCache[bom] = found;
      paintFlag($flag, found);
    }, 'json');
  }
  function paintFlag($flag, found){
    if (found) $flag.removeClass('erp-warn').addClass('erp-ok').html('<i class="fa fa-check"></i> ERP有此單');
    else $flag.removeClass('erp-ok').addClass('erp-warn').html('<i class="fa fa-exclamation-triangle"></i> ERP查無此號，請再確認');
  }

  $('#btn-prev-file').on('click', function(){ if (idx > 0) { idx--; showCurrent(); } });
  $('#btn-next-file').on('click', function(){ if (idx < files.length - 1) { idx++; showCurrent(); } });

  $('#btn-commit').on('click', function(){
    if (!CAN_OPERATE) return;
    var payload = files.map(function(f){ return { name: f.file, boms: fileState[f.file].boms.filter(function(b){ return /^B-\d{10}$/.test(b); }) }; });
    var totalBoms = payload.reduce(function(s, p){ return s + p.boms.length; }, 0);
    if (totalBoms === 0) { if (!confirm('目前所有檔案都沒有填寫製令單號，確定要送出嗎？（沒填的檔案會留在原地不動）')) return; }
    $('#btn-commit').prop('disabled', true).html('<i class="fa fa-spinner fa-spin"></i> 產生檔案中…');
    $.post(API, { action:'commit', files: JSON.stringify(payload) }, function(r){
      $('#btn-commit').prop('disabled', false).html('<i class="fa fa-check"></i> 全部確認，開始產生檔案');
      if (!r || !r.success) { alert('產生失敗：' + (r && r.message ? r.message : '未知錯誤')); return; }
      showDoneSummary(r);
    }, 'json').fail(function(){
      $('#btn-commit').prop('disabled', false).html('<i class="fa fa-check"></i> 全部確認，開始產生檔案');
      alert('送出失敗，請確認連線');
    });
  });

  function showDoneSummary(r){
    $('#br-review-panel').hide();
    var h = '<ul>';
    h += '<li>合併成 PDF：' + r.mergedCount + ' 份</li>';
    h += '<li>直接複製圖片：' + r.imageCopyCount + ' 份</li>';
    h += '<li>複製 PDF：' + r.pdfCopyCount + ' 份</li>';
    h += '<li>原始檔歸檔：' + r.archivedCount + ' 份</li>';
    h += '</ul>';
    if (r.skippedExists && r.skippedExists.length) h += '<div class="text-muted" style="font-size:12px;">已存在而跳過：' + r.skippedExists.map(esc).join('、') + '</div>';
    if (r.unsupportedFormat && r.unsupportedFormat.length) h += '<div class="text-danger" style="font-size:12px;">格式不支援：' + r.unsupportedFormat.map(esc).join('、') + '</div>';
    if (r.notFoundInErp && r.notFoundInErp.length) h += '<div class="text-warning" style="font-size:12px;">ERP查無此製令單號（仍已產出，請自行確認）：' + r.notFoundInErp.map(esc).join('、') + '</div>';
    if (r.invalidBomFormat && r.invalidBomFormat.length) h += '<div class="text-danger" style="font-size:12px;">格式錯誤已略過：' + r.invalidBomFormat.map(esc).join('、') + '</div>';
    $('#br-done-summary').html(h);
    $('#br-done-card').show();
  }

  $('#btn-perm-help').on('click', function(e){ e.preventDefault(); $('#permHelp').modal('show'); });

  // ---------- 權限設定（角色） ----------
  var ROLES_API = '../../src/store/Roles_API.php';
  var BOMREN_FEATURES = [
    ['bom_rename_view', '檢閱（掃描與查看預覽）'],
    ['bom_rename_operate', '核對確認（編輯製令單號並產生檔案）'],
    ['bom_rename_manage_settings', '管理設定（資料夾／OCR裁切區／Python路徑）']
  ];
  var curRole = null;
  function loadRoles(){
    $.get(ROLES_API, {action:'get_roles', module:'bom_rename'}, function(r){
      if (!r || !r.success) { $('#role-list').html('<div class="text-danger">' + esc(r && r.message || '載入失敗') + '</div>'); return; }
      var h = '';
      r.data.forEach(function(ro){
        var sys = parseInt(ro.is_system, 10) === 1;
        h += '<a href="#" class="list-group-item role-item" data-id="' + ro.role_id + '" data-name="' + esc(ro.role_name) + '" data-sys="' + (sys ? 1 : 0) + '">'
           + esc(ro.role_name) + (sys ? ' <span class="label label-info pull-right">系統(全權)</span>' : '') + '</a>';
      });
      $('#role-list').html(h || '<div class="text-muted">尚無角色</div>');
      $('.role-item').on('click', function(e){
        e.preventDefault();
        $('.role-item').removeClass('active'); $(this).addClass('active');
        selectRole($(this).data('id'), $(this).data('name'), $(this).data('sys') == 1);
      });
    }, 'json');
  }
  function selectRole(rid, rname, isSys){
    curRole = { id: rid, name: rname, sys: isSys };
    $('#role-feat-empty').hide(); $('#role-feat-area').show(); $('#rf-role-name').text(rname); $('#rf-msg').text('');
    $.get(ROLES_API, {action:'get_role_features', role_id:rid}, function(r){
      var have = (r && r.success) ? r.data : [];
      if (isSys) have = BOMREN_FEATURES.map(function(f){ return f[0]; });
      var h = '';
      BOMREN_FEATURES.forEach(function(f){
        h += '<div class="checkbox"><label><input type="checkbox" class="rf-chk" value="' + f[0] + '" '
           + (have.indexOf(f[0]) >= 0 ? 'checked' : '') + (isSys ? ' disabled' : '') + '> ' + esc(f[1]) + ' <code>' + f[0] + '</code></label></div>';
      });
      $('#rf-checks').html(h);
      $('#btn-save-feats,#btn-del-role,#btn-rename-role').prop('disabled', isSys);
    }, 'json');
  }
  $('#btn-role-setting').on('click', function(e){ e.preventDefault(); $('#role-feat-area').hide(); $('#role-feat-empty').show(); loadRoles(); $('#roleModal').modal('show'); });
  $('#btn-add-role').on('click', function(){
    var n = $('#new-role-name').val().trim();
    if (!n) { alert('請輸入角色名稱'); return; }
    $.post(ROLES_API, {action:'save_role', role_name:n, module:'bom_rename'}, function(r){
      if (r && r.success) { $('#new-role-name').val(''); loadRoles(); } else alert(r && r.message || '新增失敗');
    }, 'json');
  });
  $('#btn-rename-role').on('click', function(){
    if (!curRole || curRole.sys) return;
    var n = prompt('新角色名稱', curRole.name);
    if (!n) return;
    $.post(ROLES_API, {action:'save_role', role_id:curRole.id, role_name:n.trim(), module:'bom_rename'}, function(r){
      if (r && r.success) loadRoles(); else alert(r && r.message || '改名失敗');
    }, 'json');
  });
  $('#btn-del-role').on('click', function(){
    if (!curRole || curRole.sys) return;
    if (!confirm('確定刪除角色「' + curRole.name + '」？此角色的功能與使用者指派都會移除。')) return;
    $.post(ROLES_API, {action:'delete_role', role_id:curRole.id}, function(r){
      if (r && r.success) { $('#role-feat-area').hide(); $('#role-feat-empty').show(); loadRoles(); } else alert(r && r.message || '刪除失敗');
    }, 'json');
  });
  $('#btn-save-feats').on('click', function(){
    if (!curRole || curRole.sys) return;
    var feats = [];
    $('.rf-chk:checked').each(function(){ feats.push($(this).val()); });
    $.post(ROLES_API, {action:'save_role_features', role_id:curRole.id, features:JSON.stringify(feats)}, function(r){
      $('#rf-msg').text(r && r.success ? '已儲存' : (r && r.message || '儲存失敗'));
    }, 'json');
  });

  loadSettings();
})();
</script>
</body>
</html>
