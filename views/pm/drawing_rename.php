<?php
/**
 * drawing_rename.php — 圖面自動改檔名工具
 * 後端：src/store/store_DrawingRename_API.php ｜ 共用：src/common/attachment_lib.php（浮水印重用）
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

$CAN_VIEW     = rbac_has($features, 'drawing_rename_view');
$CAN_OPERATE  = rbac_has($features, 'drawing_rename_operate');
$CAN_SETTINGS = rbac_has($features, 'drawing_rename_manage_settings');

$permParts = [];
if ($CAN_VIEW) $permParts[] = '檢閱';
if ($CAN_OPERATE) $permParts[] = '執行改檔名';
if ($CAN_SETTINGS) $permParts[] = '管理設定';
$permBadge = $permParts ? implode('+', $permParts) : '無';
?>
<!DOCTYPE html>
<html lang="zh-Hant">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>圖面自動改檔名工具</title>
    <link href="../../resource/css/bootstrap.css" rel="stylesheet">
    <link href="../../resource/css/font-awesome.css" rel="stylesheet">
    <link href="../../resource/css/custom.css" rel="stylesheet">
    <style>
        .dr-wrap{ display:flex; gap:16px; }
        .dr-main{ flex:1 1 auto; min-width:0; }
        .dr-side{ width:280px; flex:0 0 280px; }
        .dr-viewer{ position:relative; background:#222; border-radius:6px; overflow:hidden; min-height:420px; display:flex; align-items:center; justify-content:center; }
        .dr-viewer img.dr-main-img{ max-width:100%; max-height:70vh; transition:transform .15s ease; }
        .dr-viewer embed.dr-pdf{ width:100%; height:70vh; background:#fff; }
        .dr-crops{ display:flex; gap:10px; margin-top:10px; }
        .dr-crop{ flex:1; height:130px; border:1px solid #ddd; border-radius:6px; overflow:hidden; position:relative; background:#111; }
        .dr-crop .lbl{ position:absolute; top:0; left:0; background:rgba(0,0,0,.55); color:#fff; font-size:11px; padding:2px 6px; z-index:2; }
        .dr-crop .inner{ position:absolute; }
        .dr-crop img{ position:absolute; }
        .dr-progress{ font-size:13px; color:#666; margin-bottom:6px; }
        .dr-name-preview{ font-family:monospace; font-size:14px; background:#f5f7fa; border:1px solid #e3e8ee; border-radius:5px; padding:6px 10px; word-break:break-all; }
        .dr-warn{ color:#a66a00; background:#fff6e5; border:1px solid #ffe2a8; border-radius:5px; padding:6px 10px; margin-top:6px; font-size:13px; }
        .dr-queue-badge{ position:fixed; right:20px; bottom:20px; background:#2c81ba; color:#fff; padding:8px 14px; border-radius:20px; box-shadow:0 2px 8px rgba(0,0,0,.2); font-size:13px; z-index:999; display:none; }
        .dr-fail-list{ position:fixed; right:20px; bottom:60px; width:320px; max-height:240px; overflow:auto; z-index:999; }
        .dr-done-card{ text-align:center; padding:60px 20px; color:#2c3e50; }
        .dr-empty-card{ text-align:center; padding:60px 20px; color:#999; }
        #bomNumberInput{ font-family:monospace; letter-spacing:1px; }
        input[type=number]::-webkit-inner-spin-button, input[type=number]::-webkit-outer-spin-button{ -webkit-appearance:none; margin:0; }
        .dr-boss-tag{ display:inline-block; background:#eaf4fd; color:#2980b9; border:1px solid #bcdff5; border-radius:4px; padding:1px 6px; font-size:11px; margin-left:6px; }
        /* 模式切換：作廢版(藍/安全)、覆蓋版(紅/警示) 明顯區隔，避免誤按 */
        .dr-mode-box{ border-radius:6px; padding:10px 14px; margin-bottom:12px; border:2px solid transparent; transition:background-color .15s ease,border-color .15s ease; }
        .dr-mode-box.mode-void{ background:#eaf4fd; border-color:#8fc1ea; }
        .dr-mode-box.mode-overwrite{ background:#fdecea; border-color:#e6968c; }
        .dr-mode-box .radio-inline{ font-weight:600; }
        .dr-mode-box.mode-void .radio-inline.opt-void{ color:#1d6fa5; }
        .dr-mode-box.mode-overwrite .radio-inline.opt-overwrite{ color:#c0392b; }
        .dr-mode-hint{ font-size:12px; margin-top:4px; }
        .dr-mode-box.mode-void .dr-mode-hint{ color:#3a7ca8; }
        .dr-mode-box.mode-overwrite .dr-mode-hint{ color:#c0392b; }
        .dr-mode-banner{ text-align:center; font-weight:700; font-size:13px; padding:6px 10px; border-radius:5px; margin-bottom:8px; }
        .dr-mode-banner.mode-void{ background:#eaf4fd; color:#1d6fa5; border:1px solid #8fc1ea; }
        .dr-mode-banner.mode-overwrite{ background:#fdecea; color:#c0392b; border:1px solid #e6968c; }
    </style>
</head>
<body class="nav-sm">
<div class="container body">
  <div class="main_container">
    <?php include '../partPage/sideAndTopBarMenu.html'; ?>

    <div class="right_col" role="main">
      <div class="page-title">
        <div class="title_left">
          <h3>圖面自動改檔名工具
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
        <div class="alert alert-danger"><i class="fa fa-ban"></i> 您沒有「圖面自動改檔名工具」的檢閱權限，請洽管理員於 <b>權限設定（角色 → 圖面自動改檔名工具）</b> 開通。</div>
      <?php else: ?>

      <!-- 設定區塊 -->
      <div class="row"><div class="col-md-12"><div class="x_panel">
        <div class="x_title"><h2><i class="fa fa-cog"></i> 資料夾與命名設定</h2>
          <ul class="nav navbar-right panel_toolbox"><li><a class="collapse-link"><i class="fa fa-chevron-up"></i></a></li></ul>
          <div class="clearfix"></div>
        </div>
        <div class="x_content">
          <div class="row">
            <div class="col-md-4">
              <label>來源資料夾（伺服器/NAS 路徑）</label>
              <input type="text" id="s-source" class="form-control input-sm" placeholder="例如 \\excellentnas\共用\待辨識" <?php echo $CAN_SETTINGS ? '' : 'readonly'; ?>>
            </div>
            <div class="col-md-4">
              <label>輸出資料夾（伺服器/NAS 路徑）</label>
              <input type="text" id="s-output" class="form-control input-sm" placeholder="留空則等於來源資料夾" <?php echo $CAN_SETTINGS ? '' : 'readonly'; ?>>
            </div>
            <div class="col-md-2">
              <label>前綴（上限30字）</label>
              <input type="text" id="s-prefix" class="form-control input-sm" maxlength="30" <?php echo $CAN_SETTINGS ? '' : 'readonly'; ?>>
            </div>
            <div class="col-md-2">
              <label>後綴（上限30字）</label>
              <input type="text" id="s-suffix" class="form-control input-sm" maxlength="30" <?php echo $CAN_SETTINGS ? '' : 'readonly'; ?>>
            </div>
          </div>
          <div class="row" style="margin-top:10px;">
            <div class="col-md-12 text-right">
              <?php if ($CAN_SETTINGS): ?>
              <button class="btn btn-primary btn-sm" id="btn-save-settings"><i class="fa fa-save"></i> 儲存設定</button>
              <?php endif; ?>
              <button class="btn btn-default btn-sm" id="btn-reload-list"><i class="fa fa-refresh"></i> 重新整理清單</button>
            </div>
          </div>
        </div>
      </div></div></div>

      <!-- 模式切換：每位使用者各自的操作偏好（非共用設定），任何有操作權限的人都能自行切換 -->
      <div class="dr-mode-box mode-void" id="dr-mode-box">
        <label class="radio-inline opt-void"><input type="radio" name="s-mode" value="void" checked <?php echo $CAN_OPERATE ? '' : 'disabled'; ?>> 作廢版（舊圖蓋章作廢＋搬移新圖，預設）</label>
        <label class="radio-inline opt-overwrite"><input type="radio" name="s-mode" value="overwrite" <?php echo $CAN_OPERATE ? '' : 'disabled'; ?>> 覆蓋版（完全同名直接覆蓋）</label>
        <div class="dr-mode-hint" id="dr-mode-hint">目前模式：作廢版 — 舊圖會蓋上「作廢」浮水印保留備查，新圖另外存檔，不會遺失舊資料。此為您個人的操作偏好，不影響其他使用者。</div>
      </div>

      <div id="dr-setup-hint" class="alert alert-warning" style="display:none;"><i class="fa fa-info-circle"></i> 請先設定「來源資料夾」後，按「重新整理清單」開始（輸出資料夾留空則等於來源資料夾）。</div>

      <!-- 審核區 -->
      <div id="dr-review-panel" class="row" style="display:none;"><div class="col-md-12"><div class="x_panel"><div class="x_content">
        <div class="dr-mode-banner mode-void" id="dr-mode-banner">目前模式：作廢版</div>
        <div class="dr-wrap">
          <div class="dr-main">
            <div class="dr-progress" id="dr-progress"></div>
            <div class="dr-viewer" id="dr-viewer"></div>
            <div class="dr-crops" id="dr-crops" style="display:none;">
              <div class="dr-crop"><span class="lbl">BOM 號碼區（放大）</span><div class="inner" id="crop-bom"></div></div>
              <div class="dr-crop"><span class="lbl">左上角 BOSS 字樣（放大）</span><div class="inner" id="crop-boss"></div></div>
            </div>
            <div style="margin-top:8px;">
              <button class="btn btn-default btn-sm" id="btn-rotate-left"><i class="fa fa-rotate-left"></i> 左轉</button>
              <button class="btn btn-default btn-sm" id="btn-rotate-right"><i class="fa fa-rotate-right"></i> 右轉</button>
            </div>
          </div>
          <div class="dr-side">
            <div class="form-group">
              <label>BOM 號碼</label>
              <div class="input-group">
                <span class="input-group-addon">B-</span>
                <input type="text" id="bomNumberInput" class="form-control" maxlength="10" placeholder="10碼數字" <?php echo $CAN_OPERATE ? '' : 'readonly'; ?>>
              </div>
              <div id="dr-bom-warn" class="dr-warn" style="display:none;"></div>
            </div>
            <div class="checkbox">
              <label><input type="checkbox" id="isBossCheck" <?php echo $CAN_OPERATE ? '' : 'disabled'; ?>> 這是 BOSS 圖（左上角有手寫 BOSS）</label>
            </div>
            <label>改名後檔名預覽</label>
            <div class="dr-name-preview" id="dr-name-preview">—</div>
            <div style="margin-top:16px;">
              <button class="btn btn-success btn-block" id="btn-confirm" <?php echo $CAN_OPERATE ? '' : 'disabled'; ?>><i class="fa fa-check"></i> 確認並改名 → 下一張</button>
              <div style="display:flex; gap:8px; margin-top:8px;">
                <button class="btn btn-default btn-sm" style="flex:1;" id="btn-prev"><i class="fa fa-arrow-left"></i> 上一張</button>
                <button class="btn btn-default btn-sm" style="flex:1;" id="btn-skip">跳過 <i class="fa fa-arrow-right"></i></button>
              </div>
            </div>
          </div>
        </div>
      </div></div></div></div>

      <div id="dr-done-card" class="row" style="display:none;"><div class="col-md-12"><div class="x_panel"><div class="x_content">
        <div class="dr-done-card">
          <i class="fa fa-check-circle" style="font-size:48px;color:#27ae60;"></i>
          <h3 id="dr-done-text"></h3>
          <p class="text-muted">輸出資料夾：<span id="dr-done-output"></span></p>
          <button class="btn btn-primary" id="btn-reload-list-2"><i class="fa fa-refresh"></i> 重新整理清單</button>
        </div>
      </div></div></div></div>

      <div id="dr-empty-card" class="row" style="display:none;"><div class="col-md-12"><div class="x_panel"><div class="x_content">
        <div class="dr-empty-card"><i class="fa fa-folder-open-o" style="font-size:40px;"></i><p>來源資料夾目前沒有待處理檔案。</p></div>
      </div></div></div></div>

      <?php endif; /* CAN_VIEW */ ?>
    </div>
  </div>
</div>

<!-- 權限設定（角色）Modal -->
<div class="modal fade" id="roleModal" tabindex="-1" role="dialog"><div class="modal-dialog modal-lg"><div class="modal-content">
  <div class="modal-header"><button type="button" class="close" data-dismiss="modal">&times;</button>
    <h4 class="modal-title"><i class="fa fa-key"></i> 圖面自動改檔名工具 — 權限設定（角色）</h4></div>
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
        <li><b>檢閱</b>：可進入頁面查看清單與圖面。</li>
        <li><b>執行改檔名</b>：可輸入 BOM 號碼／BOSS 勾選並執行「確認並改名」；並可自行切換「作廢版/覆蓋版」（個人偏好，不影響其他使用者）。</li>
        <li><b>管理設定</b>：可修改來源/輸出資料夾路徑、前後綴。</li>
        <li><b>管理者</b>角色固定擁有以上全部權限。</li>
      </ul>
      <p class="text-muted">請洽管理員於「使用者管理 → 權限設定」的「圖面自動改檔名工具」區塊指派角色。</p>
    </div>
  </div></div>
</div>

<div class="dr-queue-badge" id="dr-queue-badge"></div>
<div id="dr-fail-list"></div>

<script src="../../resource/js/jquery.min.js"></script>
<script src="../../resource/js/bootstrap.min.js"></script>
<script src="../../resource/js/custom.min.js"></script>
<script>
(function(){
  var API = '../../src/store/store_DrawingRename_API.php';
  var CAN_OPERATE = <?php echo $CAN_OPERATE ? 'true' : 'false'; ?>;
  var settings = { source_dir:'', output_dir:'', prefix:'', suffix:'', mode:'void' };
  var files = [];
  var idx = -1;
  var rotate = 0;
  var processedCount = 0;
  var queue = []; // 背景搬移佇列
  var queueRunning = false;
  var failList = [];

  function loadSettings(cb){
    $.post(API, {action:'get_settings'}, function(r){
      if (r && r.success) {
        settings = r.settings;
        $('#s-source').val(settings.source_dir);
        $('#s-output').val(settings.output_dir);
        $('#s-prefix').val(settings.prefix);
        $('#s-suffix').val(settings.suffix);
        $('input[name=s-mode][value="'+settings.mode+'"]').prop('checked', true);
        applyModeStyle(settings.mode);
        prevMode = settings.mode;
      }
      if (cb) cb();
    }, 'json');
  }

  $('#btn-save-settings').on('click', function(){
    $.post(API, {
      action:'save_settings',
      source_dir: $('#s-source').val(),
      output_dir: $('#s-output').val(),
      prefix: $('#s-prefix').val(),
      suffix: $('#s-suffix').val()
    }, function(r){
      if (r && r.success) { settings = r.settings; alert('設定已儲存'); loadList(); }
      else alert('儲存失敗：' + (r && r.message ? r.message : '未知錯誤'));
    }, 'json');
  });

  function currentMode(){ return $('input[name=s-mode]:checked').val() || settings.mode || 'void'; }

  // 模式切換：個人偏好，改變當下即自動儲存（不需按「儲存設定」）；切到覆蓋版需二次確認避免誤按
  var MODE_LABEL = { void: '作廢版', overwrite: '覆蓋版' };
  var MODE_HINT = {
    void: '目前模式：作廢版 — 舊圖會蓋上「作廢」浮水印保留備查，新圖另外存檔，不會遺失舊資料。此為您個人的操作偏好，不影響其他使用者。',
    overwrite: '目前模式：覆蓋版 — 同檔名的舊圖會被直接覆蓋、不留副本，請務必確認無誤再確認送出。此為您個人的操作偏好，不影響其他使用者。'
  };
  function applyModeStyle(mode){
    $('#dr-mode-box').removeClass('mode-void mode-overwrite').addClass('mode-' + mode);
    $('#dr-mode-hint').text(MODE_HINT[mode] || '');
    $('#dr-mode-banner').removeClass('mode-void mode-overwrite').addClass('mode-' + mode)
      .text('目前模式：' + (MODE_LABEL[mode] || mode));
  }
  var prevMode = 'void';
  $('input[name=s-mode]').on('change', function(){
    var newMode = $(this).val();
    if (newMode === 'overwrite' && !confirm('切換為「覆蓋版」：同檔名的舊圖會被直接覆蓋、不會保留副本或浮水印備查。\n\n確定要切換嗎？')) {
      $('input[name=s-mode][value="' + prevMode + '"]').prop('checked', true);
      return;
    }
    prevMode = newMode;
    applyModeStyle(newMode);
    settings.mode = newMode;
    $.post(API, {action:'save_mode', mode:newMode}, function(r){
      if (!r || !r.success) alert('模式切換儲存失敗：' + (r && r.message ? r.message : '未知錯誤'));
    }, 'json');
  });

  function resolvedOutputDir(){ return settings.output_dir || settings.source_dir || ''; }

  function loadList(){
    if (!settings.source_dir) {
      $('#dr-setup-hint').show();
      $('#dr-review-panel,#dr-done-card,#dr-empty-card').hide();
      return;
    }
    $('#dr-setup-hint').hide();
    $.post(API, {action:'list_files'}, function(r){
      if (!r || !r.success) { alert('讀取清單失敗：' + (r && r.message ? r.message : '未知錯誤')); return; }
      files = r.files || [];
      processedCount = 0;
      if (files.length === 0) {
        $('#dr-review-panel,#dr-done-card').hide();
        $('#dr-empty-card').show();
        return;
      }
      $('#dr-empty-card,#dr-done-card').hide();
      $('#dr-review-panel').show();
      idx = 0;
      showCurrent();
    }, 'json');
  }

  function affix(){ return { prefix: settings.prefix || '', suffix: settings.suffix || '' }; }

  function buildPreviewName(){
    var num = $('#bomNumberInput').val() || '__________';
    var isBoss = $('#isBossCheck').is(':checked');
    var a = affix();
    var ext = (files[idx] ? files[idx].ext : '');
    var body = a.prefix + 'B-' + num + (isBoss ? '' : ' ++') + a.suffix;
    return body + (ext ? ('.' + ext) : '');
  }

  function refreshPreview(){
    $('#dr-name-preview').text(buildPreviewName());
    var num = $('#bomNumberInput').val() || '';
    if (num.length === 10) {
      var m = parseInt(num.substr(3,2), 10), d = parseInt(num.substr(5,2), 10);
      if (m < 1 || m > 12 || d < 1 || d > 31) {
        $('#dr-bom-warn').text('月份或日期看起來不合理（月:'+m+' 日:'+d+'），請再次確認號碼是否看對').show();
      } else { $('#dr-bom-warn').hide(); }
    } else { $('#dr-bom-warn').hide(); }
  }

  function showCurrent(){
    if (idx < 0 || idx >= files.length) return;
    var f = files[idx];
    rotate = 0;
    $('#bomNumberInput').val('');
    $('#isBossCheck').prop('checked', false);
    $('#dr-progress').text('第 ' + (idx+1) + ' / 共 ' + files.length + ' 張　（' + f.name + '）');
    var url = API + '?action=stream_file&file=' + encodeURIComponent(f.name) + '&_=' + f.mtime;
    var $viewer = $('#dr-viewer').empty();
    if (f.ext === 'pdf') {
      $viewer.append($('<embed class="dr-pdf" type="application/pdf">').attr('src', url));
      $('#dr-crops').hide();
    } else {
      var $img = $('<img class="dr-main-img" id="dr-main-img">').attr('src', url)
        .css('filter', 'grayscale(1) contrast(1.6)');
      $viewer.append($img);
      $('#dr-crops').show();
      $img.on('load', function(){ buildCrops(url); });
    }
    applyRotatePreview();
    refreshPreview();
    $('#bomNumberInput').focus();
  }

  // 裁切放大：純前端 CSS，取原圖比例區域放大顯示，不另外打 API
  function buildCrops(url){
    // BOM 號碼區：上方偏右（約上 18% 高、右 70%~100% 寬）
    buildOneCrop('#crop-bom', url, 0.70, 0, 0.30, 0.18);
    // BOSS 字樣區：左上角（約左 0%~22%、上 0%~12%）
    buildOneCrop('#crop-boss', url, 0, 0, 0.22, 0.12);
  }
  function buildOneCrop(sel, url, rx, ry, rw, rh){
    var $box = $(sel).empty();
    var boxW = $box.width(), boxH = $box.height();
    var scale = Math.min(boxW / (rw * 1000), boxH / (rh * 1000)) * 3; // 粗略放大倍率，實際以圖片實際尺寸重算
    var $img = $('<img>').attr('src', url).css({ filter:'grayscale(1) contrast(1.8)' });
    $img.on('load', function(){
      var natW = this.naturalWidth, natH = this.naturalHeight;
      var cropW = natW * rw, cropH = natH * rh;
      var s = Math.max(boxW / cropW, boxH / cropH);
      var dispW = natW * s, dispH = natH * s;
      $img.css({ width: dispW + 'px', height: dispH + 'px', left: (-natW*rx*s) + 'px', top: (-natH*ry*s) + 'px' });
    });
    $box.append($img);
  }

  function applyRotatePreview(){
    $('#dr-main-img').css('transform', 'rotate(' + rotate + 'deg)');
  }
  $('#btn-rotate-left').on('click', function(){ rotate = (rotate - 90 + 360) % 360; applyRotatePreview(); });
  $('#btn-rotate-right').on('click', function(){ rotate = (rotate + 90) % 360; applyRotatePreview(); });

  $('#bomNumberInput').on('input', function(){
    this.value = this.value.replace(/\D/g, '').slice(0, 10);
    refreshPreview();
  });
  $('#bomNumberInput').on('dblclick', function(){ $(this).val(''); refreshPreview(); });
  $('#bomNumberInput').on('keydown', function(e){ if (e.which === 13) { e.preventDefault(); doConfirm(); } });
  $('#isBossCheck').on('change', refreshPreview);
  $('#s-prefix,#s-suffix').on('input', function(){
    var illegal = /[\\\/:\*\?"<>\|]/g;
    this.value = this.value.replace(illegal, '').slice(0, 30);
    settings.prefix = $('#s-prefix').val(); settings.suffix = $('#s-suffix').val();
    refreshPreview();
  });

  $('#btn-prev').on('click', function(){ if (idx > 0) { idx--; showCurrent(); } });
  $('#btn-skip').on('click', function(){ if (idx < files.length - 1) { idx++; showCurrent(); } else { idx++; checkListEnd(); } });

  function checkListEnd(){
    if (idx >= files.length) {
      $('#dr-review-panel').hide();
      if (processedCount > 0) {
        $('#dr-done-text').text('共 ' + processedCount + ' 張改名完畢');
        $('#dr-done-output').text(resolvedOutputDir());
        $('#dr-done-card').show();
      } else {
        $('#dr-empty-card').show();
      }
    }
  }

  function updateQueueBadge(){
    var $b = $('#dr-queue-badge');
    if (queue.length > 0 || queueRunning) { $b.text('背景處理中：' + (queue.length + (queueRunning?1:0)) + ' 筆').show(); }
    else { $b.hide(); }
  }
  function updateFailList(){
    var $f = $('#dr-fail-list').empty();
    failList.slice(-5).forEach(function(it){
      $f.append($('<div class="alert alert-danger alert-dismissible" style="font-size:12px;">')
        .append('<button type="button" class="close" data-dismiss="alert">&times;</button>')
        .append('<b>' + it.file + '</b> 處理失敗：' + it.message));
    });
  }

  function runQueue(){
    if (queueRunning || queue.length === 0) return;
    queueRunning = true;
    var job = queue.shift();
    updateQueueBadge();
    $.post(API, job.data, function(r){
      queueRunning = false;
      if (r && r.success) {
        processedCount++;
        if (r.voidErrors && r.voidErrors.length) {
          r.voidErrors.forEach(function(ve){ failList.push({file: ve.file, message: '舊檔作廢失敗：' + ve.message}); });
          updateFailList();
        }
      } else {
        failList.push({file: job.data.file, message: (r && r.message) ? r.message : '未知錯誤'});
        updateFailList();
      }
      updateQueueBadge();
      runQueue();
    }, 'json').fail(function(){
      queueRunning = false;
      failList.push({file: job.data.file, message: '網路或伺服器錯誤'});
      updateFailList();
      updateQueueBadge();
      runQueue();
    });
  }

  function doConfirm(){
    if (!CAN_OPERATE) return;
    var num = $('#bomNumberInput').val() || '';
    if (num.length !== 10) { alert('BOM 號碼必須剛好 10 碼數字'); return; }
    var f = files[idx];
    var data = {
      action: 'confirm',
      file: f.name,
      bomNumber: num,
      isBoss: $('#isBossCheck').is(':checked') ? '1' : '0',
      rotate: rotate,
      mode: currentMode()
    };
    queue.push({ data: data });
    updateQueueBadge();
    runQueue();
    idx++;
    if (idx < files.length) showCurrent(); else checkListEnd();
  }
  $('#btn-confirm').on('click', doConfirm);

  $(window).on('beforeunload', function(e){
    if (queue.length > 0 || queueRunning) {
      var msg = '還有 ' + (queue.length + (queueRunning?1:0)) + ' 筆背景處理中，請稍候再離開';
      e.returnValue = msg;
      return msg;
    }
  });

  $('#btn-reload-list,#btn-reload-list-2').on('click', loadList);
  $('#btn-perm-help').on('click', function(e){ e.preventDefault(); $('#permHelp').modal('show'); });

  // ---------- 權限設定（角色） ----------
  var ROLES_API = '../../src/store/Roles_API.php';
  var DRAW_FEATURES = [
    ['drawing_rename_view', '檢閱（進入頁面查看清單與圖面）'],
    ['drawing_rename_operate', '執行改檔名（輸入 BOM 號碼／BOSS 勾選並確認）'],
    ['drawing_rename_manage_settings', '管理設定（資料夾路徑／前後綴／模式）']
  ];
  var curRole = null;
  function esc(s){ return $('<div>').text(s == null ? '' : s).html(); }
  function loadRoles(){
    $.get(ROLES_API, {action:'get_roles', module:'drawing_rename'}, function(r){
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
      if (isSys) have = DRAW_FEATURES.map(function(f){ return f[0]; });
      var h = '';
      DRAW_FEATURES.forEach(function(f){
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
    $.post(ROLES_API, {action:'save_role', role_name:n, module:'drawing_rename'}, function(r){
      if (r && r.success) { $('#new-role-name').val(''); loadRoles(); } else alert(r && r.message || '新增失敗');
    }, 'json');
  });
  $('#btn-rename-role').on('click', function(){
    if (!curRole || curRole.sys) return;
    var n = prompt('新角色名稱', curRole.name);
    if (!n) return;
    $.post(ROLES_API, {action:'save_role', role_id:curRole.id, role_name:n.trim(), module:'drawing_rename'}, function(r){
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

  loadSettings(loadList);
})();
</script>
</body>
</html>
