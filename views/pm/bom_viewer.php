<?php
session_start();
if (!isset($_SESSION['userName'])) {
    header('Location: ../../index.php');
    exit();
}

// ── AJAX：取得檔名標籤設定 ────────────────────────────────────────────────
if (isset($_POST['action']) && $_POST['action'] === 'get_file_tags_setting') {
    header('Content-Type: application/json');
    include_once '../../src/common/DBConnection.php';
    try {
        $pdo_t = (new DBConnection())->getPDO();
        $st = $pdo_t->prepare("SELECT param_value FROM system_parameters WHERE param_group='BOM_FILE_TAGS' AND param_key='tags_config'");
        $st->execute();
        $row = $st->fetch(PDO::FETCH_ASSOC);
        $config = $row ? json_decode($row['param_value'], true) : [];
        echo json_encode(['success' => true, 'config' => $config ?: []]);
    } catch (Exception $e) {
        echo json_encode(['success' => false, 'message' => $e->getMessage()]);
    }
    exit;
}

// ── AJAX：儲存檔名標籤設定 ────────────────────────────────────────────────
if (isset($_POST['action']) && $_POST['action'] === 'save_file_tags_setting') {
    header('Content-Type: application/json');
    include_once '../../src/common/DBConnection.php';
    $tags_config = $_POST['tags_config'] ?? '[]';
    $user = $_SESSION['id'] ?? 'system';
    try {
        $pdo_t = (new DBConnection())->getPDO();
        $sql = "INSERT INTO system_parameters (param_group, param_key, param_value, description, updated_by, updated_at)
                VALUES ('BOM_FILE_TAGS', 'tags_config', :val, 'BOM檔案標籤設定', :user, NOW())
                ON DUPLICATE KEY UPDATE param_value = :val_upd, updated_by = :user_upd, updated_at = NOW()";
        $st = $pdo_t->prepare($sql);
        $st->execute([':val' => $tags_config, ':user' => $user, ':val_upd' => $tags_config, ':user_upd' => $user]);
        echo json_encode(['success' => true]);
    } catch (Exception $e) {
        echo json_encode(['success' => false, 'message' => $e->getMessage()]);
    }
    exit;
}

// ── 自家 AJAX：d_id 模式檔案清單 ─────────────────────────────────────────
if (isset($_POST['action']) && $_POST['action'] === 'get_files_by_did') {
    header('Content-Type: application/json');
    try {
        $did = trim($_POST['d_id'] ?? '');
        if (empty($did)) throw new Exception('缺少 d_id');
        include_once '../../src/common/DBConnection.php';
        $pdo2 = (new DBConnection())->getPDO();
        $stmt = $pdo2->prepare("SELECT bom, sqty FROM bom WHERE d_id = ? ORDER BY Created_At DESC");
        $stmt->execute([$did]);
        $bom_rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
        $scan_dir = 'Z:/BOM/'; $url_dir = '/nas/';
        $files = [];
        // 載入 tags_config（自動檔名標籤設定）
        $tagsConfig = [];
        try {
            $tRow = $pdo2->query("SELECT param_value FROM system_parameters WHERE param_group='BOM_FILE_TAGS' AND param_key='tags_config'")->fetch(PDO::FETCH_ASSOC);
            if ($tRow) $tagsConfig = json_decode($tRow['param_value'], true) ?: [];
        } catch (Exception $_te) {}

        // 依檔名套用標籤的輔助函式
        $applyTags = function($filename) use ($tagsConfig) {
            $tags = [];
            $nameNoExt = pathinfo($filename, PATHINFO_FILENAME);
            foreach ($tagsConfig as $t) {
                $suffix = $t['suffix'] ?? '';
                if ($suffix !== '' && strpos($nameNoExt, $suffix) !== false) {
                    $tags[] = ['label' => $t['label'] ?? $suffix, 'color' => $t['color'] ?? '#777'];
                }
            }
            return $tags;
        };

        if (is_dir($scan_dir) && !empty($bom_rows)) {
            $allF = scandir($scan_dir);
            foreach ($bom_rows as $row) {
                $bname = $row['bom']; $sqty = $row['sqty'];
                foreach ($allF as $fn) {
                    if ($fn==='.'||$fn==='..') continue;
                    if (strpos($fn, $bname) === 0) {
                        $ext = strtolower(pathinfo($fn, PATHINFO_EXTENSION));
                        if (in_array($ext, ['jpg','jpeg','png','pdf'])) {
                            $label = $bname . ($sqty !== null ? ' (Qty:'.$sqty.')' : '');
                            $files[] = ['path'=>$url_dir.$fn, 'type'=>$ext, 'name'=>$fn,
                                        'label'=>$label, 'tags'=>$applyTags($fn), 'is_plus'=>false];
                        }
                    }
                }
            }
            usort($files, function($a,$b){ return strcmp($a['name'],$b['name']); });
        }
        echo json_encode(['success'=>true, 'files'=>$files, 'erp_files'=>[]]);
    } catch (Exception $e) {
        echo json_encode(['success'=>false, 'message'=>$e->getMessage()]);
    }
    exit;
}

// ── AJAX：取得料號附件列表（供 bom_viewer 顯示）──────────────────────────
if (isset($_POST['action']) && $_POST['action'] === 'get_attachments_by_did') {
    header('Content-Type: application/json');
    try {
        // bom_viewer 傳入的是文字料號（order_track.d_id），需先查 d_setting 取整數 PK
        $partNo = trim($_POST['d_id'] ?? '');
        if (!$partNo) throw new Exception('缺少料號');
        include_once '../../src/common/DBConnection.php';
        $pdo2 = (new DBConnection())->getPDO();
        // 找出所有符合此料號的 d_setting.d_id（可能有多筆，不同客戶）
        $dsStmt = $pdo2->prepare("SELECT d_id FROM d_setting WHERE D_Setting_Id = ?");
        $dsStmt->execute([$partNo]);
        $dids = $dsStmt->fetchAll(PDO::FETCH_COLUMN);
        if (empty($dids)) {
            echo json_encode(['success' => true, 'attachments' => []]);
            exit;
        }
        // URL 目錄（以第一筆 d_id 為主，實際檔案存放路徑）
        $urlStmt = $pdo2->prepare("SELECT setting_value FROM system_settings WHERE setting_key='part_attach_url_dir'");
        $urlStmt->execute();
        $urlBase = rtrim((string)($urlStmt->fetchColumn() ?: ''), '/\\');
        // 附件需依各 d_id 子目錄取 URL，記錄時一起帶 d_id
        // 附件清單（支援多筆 d_id）
        $ph = implode(',', array_fill(0, count($dids), '?'));
        $stmt = $pdo2->prepare("SELECT pa.id, pa.d_id, pa.filename, pa.original_name, pa.category_ids, pa.file_size, pa.note,
            COALESCE(u.user_cname, pa.uploaded_by) AS uploaded_by, pa.uploaded_at
            FROM part_attachments pa
            LEFT JOIN user u ON u.id = pa.uploaded_by_id
            WHERE pa.d_id IN ($ph) AND pa.deleted_at IS NULL
            ORDER BY pa.uploaded_at DESC");
        $stmt->execute($dids);
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
        // 類別名稱對照
        $cats = [];
        try {
            $cStmt = $pdo2->query("SELECT id, category_name FROM quotation_file_categories WHERE is_active = 1");
            foreach ($cStmt->fetchAll(PDO::FETCH_ASSOC) as $c) $cats[(int)$c['id']] = $c['category_name'];
        } catch (Exception $_e) {}
        $result = [];
        foreach ($rows as $r) {
            $ext = strtolower(pathinfo($r['filename'], PATHINFO_EXTENSION));
            $catNames = [];
            if ($r['category_ids']) {
                foreach (explode(',', $r['category_ids']) as $cid) {
                    $cid = (int)trim($cid);
                    if (isset($cats[$cid])) $catNames[] = $cats[$cid];
                }
            }
            $type = in_array($ext, ['jpg','jpeg','png','gif','webp','bmp']) ? 'image'
                  : ($ext === 'pdf' ? 'pdf' : 'other');
            // 每個附件依其 d_id 子目錄取 URL
            $fileUrl = $urlBase . '/' . $r['d_id'] . '/' . $r['filename'];
            $result[] = [
                'id'             => (int)$r['id'],
                'filename'       => $r['filename'],
                'display_name'   => $r['original_name'] ?: $r['filename'],
                'url'            => $fileUrl,
                'ext'            => $ext,
                'type'           => $type,
                'file_size'      => $r['file_size'] ?: '',
                'note'           => $r['note'] ?: '',
                'uploaded_by'    => $r['uploaded_by'] ?: '',
                'uploaded_at'    => substr($r['uploaded_at'] ?: '', 0, 16),
                'category_names' => $catNames,
            ];
        }
        echo json_encode(['success' => true, 'attachments' => $result]);
    } catch (Exception $e) {
        echo json_encode(['success' => false, 'message' => $e->getMessage()]);
    }
    exit;
}

// ── 模式判斷：?bom=… 或 ?d_id=… ─────────────────────────────────────────
$bom  = trim($_GET['bom']  ?? '');
$d_id = trim($_GET['d_id'] ?? '');
$mode = '';   // 'bom' | 'did'

if (!empty($bom)) {
    $mode = 'bom';
} elseif (!empty($d_id)) {
    $mode = 'did';
    $bom  = $d_id;   // 用作標題顯示
} else {
    die('缺少 BOM 或 d_id 參數');
}
$bom_safe = htmlspecialchars($bom, ENT_QUOTES, 'UTF-8');
?>
<!DOCTYPE html>
<html lang="zh-TW">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>圖面查閱 — <?= $bom_safe ?></title>
    <link href="../../resource/css/bootstrap.css" rel="stylesheet">
    <link href="../../resource/css/font-awesome.css" rel="stylesheet">
    <style>
        * { box-sizing: border-box; }
        body { margin: 0; background: #f5f5f5; overflow: hidden; }
        .main-wrap { display: flex; height: 100vh; overflow: hidden; }

        /* ── 左側檔案列表面板 ── */
        #file-panel {
            width: 280px; min-width: 180px; overflow-y: auto; height: 100vh;
            background: #fff; border-right: 1px solid #ddd; flex-shrink: 0;
        }
        #file-panel-heading {
            padding: 9px 12px; font-weight: bold; font-size: 13px; color: #555;
            background: #f7f7f7; border-bottom: 1px solid #e0e0e0; word-break: break-all;
        }

        /* ── 右側查閱面板 ── */
        #viewer-panel { flex: 1; display: flex; flex-direction: column; height: 100vh; overflow: hidden; }
        #viewer-toolbar {
            padding: 6px 10px; background: #fff; border-bottom: 1px solid #ddd;
            display: flex; align-items: center; gap: 6px; flex-shrink: 0; flex-wrap: wrap;
        }
        #viewer-title {
            flex: 1; font-weight: bold; color: #333; overflow: hidden;
            text-overflow: ellipsis; white-space: nowrap; font-size: 13px; min-width: 0;
        }
        #viewer-content { flex: 1; overflow: hidden; background: #ddd; position: relative; }

        /* ── 圖片縮放區 ── */
        #img-zoom-wrap {
            width: 100%; height: 100%; overflow: hidden; display: none;
            align-items: center; justify-content: center; cursor: grab;
        }
        #img-zoom-wrap:active { cursor: grabbing; }
        #bom-zoom-img { max-width: 100%; max-height: 100%; transform-origin: 50% 50%; user-select: none; pointer-events: none; }

        /* ── PDF ── */
        #bom-pdf-frame { display: none; width: 100%; height: 100%; border: none; }

        /* ── 空狀態提示 ── */
        #viewer-placeholder {
            position: absolute; top: 50%; left: 50%; transform: translate(-50%, -50%);
            color: #999; font-size: 15px; text-align: center;
        }

        /* ── 檔案列表項目 ── */
        .bom-file-item.active { background: #337ab7 !important; color: #fff !important; border-color: #2e6da4 !important; }
        .bom-file-item.active .list-group-item-text { color: #fff !important; }
        .list-group-item { padding: 8px 12px; }
        .list-group-item-text { font-size: 12px; word-break: break-all; margin: 0; }
        .list-group-item-info, .list-group-item-warning, .list-group-item-danger { cursor: default; }
        /* ── 附件區塊 ── */
        .att-section-header { background:#e8f4fd !important; color:#1a5276 !important; border-top:2px solid #aed6f1 !important; margin-top:8px; cursor:default; }
        .att-file-item.active { background:#1a5276 !important; color:#fff !important; border-color:#154360 !important; }
        .att-file-item.active .list-group-item-text { color:#fff !important; }

        /* ── 儲存對話框遮罩 ── */
        #save-overlay {
            display: none; position: fixed; inset: 0;
            background: rgba(0,0,0,.45); z-index: 9000;
            align-items: center; justify-content: center;
        }
        #save-dialog {
            background: #fff; border-radius: 6px; padding: 20px 24px 16px;
            min-width: 340px; max-width: 480px; width: 90%;
            box-shadow: 0 6px 28px rgba(0,0,0,.35);
        }
        #save-dialog h5 { margin: 0 0 14px; font-size: 15px; color: #333; }
        #save-dialog .form-group { margin-bottom: 10px; }
        #save-dialog label { font-size: 13px; color: #555; margin-bottom: 4px; }
        #save-dialog .hint { font-size: 12px; color: #888; margin-top: 6px; }
        #save-dialog .btn-row { text-align: right; margin-top: 16px; }
        #save-dialog .btn-row .btn { min-width: 72px; }

    </style>
</head>
<body>
<div class="main-wrap">

    <!-- 左側：檔案清單 -->
    <div id="file-panel">
        <div id="file-panel-heading"><i class="fa fa-folder-open-o"></i> <?= $bom_safe ?></div>
        <div id="bom-file-list">
            <p class="text-center" style="margin-top:24px; color:#999;">
                <i class="fa fa-spinner fa-spin"></i> 載入中...
            </p>
        </div>
    </div>

    <!-- 右側：查閱區 -->
    <div id="viewer-panel">
        <div id="viewer-toolbar">
            <span id="viewer-title"></span>
            <button id="btn-zoom-in"    class="btn btn-default btn-xs" style="display:none;" title="放大"><i class="fa fa-search-plus"></i></button>
            <button id="btn-zoom-out"   class="btn btn-default btn-xs" style="display:none;" title="縮小"><i class="fa fa-search-minus"></i></button>
            <button id="btn-zoom-reset" class="btn btn-default btn-xs" style="display:none;" title="重置縮放"><i class="fa fa-refresh"></i></button>
            <button id="btn-paint"      class="btn btn-info    btn-xs" style="display:none;" title="用小畫家開啟（需一次性安裝）"><i class="fa fa-paint-brush"></i> 小畫家</button>
            <button id="btn-save"       class="btn btn-success btn-xs" style="display:none;" title="儲存檔案"><i class="fa fa-floppy-o"></i> 儲存</button>
            <button id="btn-print"      class="btn btn-default btn-xs" style="display:none;" title="列印"><i class="fa fa-print"></i> 列印</button>
            <button id="btn-tags-setting" class="btn btn-info btn-xs" onclick="openFileTagsSetting()" title="設定檔名標籤"><i class="fa fa-tags"></i> 設定標籤</button>
        </div>
        <!-- 小畫家提示列（每次點擊都顯示，讓使用者可視需要重新安裝） -->
        <div id="paint-install-hint" style="display:none; background:#fff3cd; color:#856404; padding:7px 12px; font-size:12px; border-bottom:2px solid #ffc107; flex-shrink:0;">
            <i class="fa fa-paint-brush" style="margin-right:4px;"></i>
            已呼叫小畫家。<strong>若未正常開啟</strong>，請
            <a href="install_paint_handler.php" style="color:#533f03; font-weight:bold; text-decoration:underline;">下載安裝程式</a>
            並雙擊執行（一次性安裝，之後即可直接使用）。
            <button type="button" onclick="document.getElementById('paint-install-hint').style.display='none';"
                style="background:none; border:none; cursor:pointer; font-size:15px; line-height:1; color:#856404; margin-left:8px; padding:0; vertical-align:middle;">&times;</button>
        </div>
        <div id="viewer-content">
            <div id="img-zoom-wrap"><img id="bom-zoom-img" src="" alt=""></div>
            <iframe id="bom-pdf-frame" src="" allowfullscreen></iframe>
            <div id="viewer-placeholder"><i class="fa fa-arrow-left"></i> 從左側選擇檔案</div>
        </div>
    </div>
</div>

<!-- 儲存對話框 -->
<div id="save-overlay">
    <div id="save-dialog">
        <h5><i class="fa fa-floppy-o" style="margin-right:7px;color:#5cb85c;"></i>儲存檔案</h5>
        <div class="form-group">
            <label for="save-filename">檔案名稱</label>
            <div class="input-group">
                <input type="text" id="save-filename" class="form-control" placeholder="輸入儲存名稱">
                <span class="input-group-addon" id="save-ext-display" style="min-width:52px; text-align:center; background:#f5f5f5; color:#555;">.jpg</span>
            </div>
        </div>
        <p class="hint"><i class="fa fa-info-circle"></i> 若瀏覽器設定「每次詢問儲存位置」，將顯示另存新檔對話框；否則自動存至下載資料夾。</p>
        <div class="btn-row">
            <button id="save-cancel" class="btn btn-default btn-sm" style="margin-right:8px;">取消</button>
            <button id="save-confirm" class="btn btn-success btn-sm"><i class="fa fa-download"></i> 下載</button>
        </div>
    </div>
</div>


<!-- 設定標籤 Modal -->
<div class="modal fade" id="fileTagsSettingModal" tabindex="-1" role="dialog" style="z-index:10070;">
    <div class="modal-dialog" role="document">
        <div class="modal-content">
            <div class="modal-header">
                <button type="button" class="close" data-dismiss="modal"><span>&times;</span></button>
                <h4 class="modal-title">設定 ERP/資材報告 檔名標籤</h4>
            </div>
            <div class="modal-body">
                <table class="table table-bordered table-condensed" id="tagsSettingTable">
                    <thead>
                        <tr>
                            <th>檔名後綴 (例: -T)</th>
                            <th>標籤名稱 (例: 叫料)</th>
                            <th>顏色</th>
                            <th width="50">操作</th>
                        </tr>
                    </thead>
                    <tbody></tbody>
                </table>
                <button type="button" class="btn btn-success btn-sm" onclick="addTagRow()"><i class="fa fa-plus"></i> 新增規則</button>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-default" data-dismiss="modal">取消</button>
                <button type="button" class="btn btn-primary" onclick="saveFileTagsSetting()">儲存設定</button>
            </div>
        </div>
    </div>
</div>

<script src="../../resource/js/jquery.min.js"></script>
<script src="../../resource/js/bootstrap.min.js"></script>
<script>
var _bom        = <?= json_encode($bom) ?>;
var _mode       = <?= json_encode($mode) ?>;   // 'bom' | 'did'
var _d_id       = <?= json_encode($d_id) ?>;   // only in did mode
var _sc         = 1, _tx = 0, _ty = 0;
var _currentType = '';
var _currentPath = '';
var _currentName = '';

// ── 工具函數 ──────────────────────────────────────────────────────────────
function escapeHtml(s) {
    return String(s)
        .replace(/&/g,'&amp;').replace(/</g,'&lt;')
        .replace(/>/g,'&gt;').replace(/"/g,'&quot;');
}
function applyTransform() {
    var img = document.getElementById('bom-zoom-img');
    if (img) img.style.transform = 'translate('+_tx+'px,'+_ty+'px) scale('+_sc+')';
}
function resetTransform() { _sc = 1; _tx = 0; _ty = 0; applyTransform(); }

// ── 檔案切換顯示 ─────────────────────────────────────────────────────────
function showFile(path, type, name) {
    _currentPath = path;
    _currentName = name || path;
    _currentType = (type || '').toLowerCase();
    var _isImg = ['jpg','jpeg','png','gif','bmp'].indexOf(_currentType) !== -1;

    $('#viewer-title').text(_currentName);
    $('#img-zoom-wrap, #bom-pdf-frame, #viewer-placeholder').hide();
    $('#btn-print, #btn-zoom-in, #btn-zoom-out, #btn-zoom-reset, #btn-save, #btn-paint').hide();
    resetTransform();

    // 小畫家支援的圖片格式
    var _paintFormats = ['jpg', 'jpeg', 'png', 'gif', 'bmp', 'tif', 'tiff'];
    var _isPaintable   = _paintFormats.indexOf(_currentType) !== -1;

    if (_currentType === 'pdf') {
        $('#bom-pdf-frame').attr('src', path).show();
        $('#btn-save, #btn-print').show();
    } else if (_isImg) {
        $('#bom-zoom-img').attr('src', path);
        $('#img-zoom-wrap').css('display', 'flex');
        $('#btn-zoom-in, #btn-zoom-out, #btn-zoom-reset, #btn-save, #btn-print').show();
    } else {
        $('#viewer-placeholder')
            .html('<i class="fa fa-download"></i> 不支援預覽，<a href="'+escapeHtml(path)+'" target="_blank">點此下載</a>')
            .show();
        $('#btn-save').show();
    }
    if (_isPaintable) { $('#btn-paint').show(); }
}

// ── 圖片縮放與拖曳 ────────────────────────────────────────────────────────
(function() {
    var wrap = document.getElementById('img-zoom-wrap');
    var _pan = false, _px, _py, _ox, _oy;
    wrap.addEventListener('wheel', function(e) {
        e.preventDefault();
        _sc = Math.max(0.1, Math.min(10, _sc + (e.deltaY < 0 ? 0.12 : -0.12)));
        applyTransform();
    }, { passive: false });
    wrap.addEventListener('mousedown', function(e) {
        _pan = true; _px = e.clientX; _py = e.clientY; _ox = _tx; _oy = _ty;
        e.preventDefault();
    });
    window.addEventListener('mousemove', function(e) {
        if (!_pan) return;
        _tx = _ox + e.clientX - _px; _ty = _oy + e.clientY - _py;
        applyTransform();
    });
    window.addEventListener('mouseup', function() { _pan = false; });
})();

$('#btn-zoom-in').on('click',    function() { _sc = Math.min(10, _sc + 0.2); applyTransform(); });
$('#btn-zoom-out').on('click',   function() { _sc = Math.max(0.1, _sc - 0.2); applyTransform(); });
$('#btn-zoom-reset').on('click', resetTransform);

// ── 列印 ──────────────────────────────────────────────────────────────────
$('#btn-print').on('click', function() {
    var isObs = $('.bom-file-item.active').data('obsolete') === '1' || $('.bom-file-item.active').data('obsolete') === 1;
    if (_currentType === 'pdf') {
        if (isObs && !confirm('此為「作廢」附件，確定要列印？')) return;
        var frame = document.getElementById('bom-pdf-frame');
        try { frame.contentWindow.print(); } catch(e) { window.print(); }
    } else {
        var src = document.getElementById('bom-zoom-img').src;
        if (!src) return;
        var _printCss = '@page{margin:0;}html,body{margin:0;padding:0;width:100%;height:100%;overflow:hidden;}body{display:flex;align-items:center;justify-content:center;}img{display:block;max-width:100%;max-height:100%;object-fit:contain;}';
        var _wmCss = isObs
            ? '.wm{position:fixed;top:50%;left:50%;transform:translate(-50%,-50%) rotate(-20deg);'
                + 'color:rgba(220,53,69,0.38);font-size:130px;font-weight:900;letter-spacing:16px;'
                + 'pointer-events:none;white-space:nowrap;z-index:999;user-select:none;font-family:Arial,sans-serif;}'
            : '';
        var _wmHtml = isObs ? '<div class="wm">作廢</div>' : '';
        // 用隱藏 iframe 列印，避免另開分頁（列印/取消後仍停留在本視窗）
        var _old = document.getElementById('bom-print-frame');
        if (_old) _old.parentNode.removeChild(_old);
        var ifr = document.createElement('iframe');
        ifr.id = 'bom-print-frame';
        ifr.style.cssText = 'position:fixed;right:0;bottom:0;width:0;height:0;border:0;visibility:hidden;';
        document.body.appendChild(ifr);
        var doc = ifr.contentWindow.document;
        doc.open();
        doc.write('<!DOCTYPE html><html><head><meta charset="UTF-8"><title>列印</title><style>'
            + _printCss + _wmCss + '</style></head><body><img src="' + escapeHtml(src) + '">'
            + _wmHtml + '</body></html>');
        doc.close();
        var _doPrint = function() { try { ifr.contentWindow.focus(); ifr.contentWindow.print(); } catch(e) { window.print(); } };
        var _img = doc.querySelector('img');
        if (_img && !_img.complete) { _img.onload = _doPrint; _img.onerror = _doPrint; }
        else { setTimeout(_doPrint, 60); }
    }
});

// ── 小畫家：呼叫自訂協議，同時顯示提示列（讓使用者可視需要重新安裝）────
$('#btn-paint').on('click', function() {
    // 只傳 host + path，避免嵌套 :// 造成 URL 解析錯誤
    // VBScript 端會自動補回 http://
    window.location.href = 'open-paint://' + window.location.host + _currentPath;
    // 每次點擊都顯示提示，成功開啟者可直接按 × 關閉；未安裝或需重裝者可點連結
    document.getElementById('paint-install-hint').style.display = 'block';
});

// ── 儲存：開啟頁內對話框 ──────────────────────────────────────────────────
$('#btn-save').on('click', function() {
    var _ext = _currentType || 'file';
    // 用目前檔案的實際名稱（去副檔名）作為預設，避免料號含 / 等非法字元
    var defaultName = _currentName
        ? _currentName.replace(/\.[^/.]+$/, '')   // 去掉副檔名
        : _bom;
    $('#save-filename').val(defaultName);
    $('#save-ext-display').text('.' + _ext).data('ext', _ext);
    $('#save-overlay').css('display', 'flex');
    setTimeout(function() {
        var inp = document.getElementById('save-filename');
        if (inp) { inp.focus(); inp.select(); }
    }, 60);
});

$('#save-cancel').on('click', function() { $('#save-overlay').hide(); });

// 點擊遮罩關閉
$('#save-overlay').on('click', function(e) {
    if (e.target === this) $('#save-overlay').hide();
});

// Enter 確認 / Esc 關閉
$('#save-filename').on('keydown', function(e) {
    if (e.key === 'Enter')  { e.preventDefault(); $('#save-confirm').click(); }
    if (e.key === 'Escape') { $('#save-overlay').hide(); }
});

// ── 儲存確認：showSaveFilePicker → fetch+blob → <a download> ────────────
$('#save-confirm').on('click', function() {
    var basename = $('#save-filename').val().trim();
    var ext      = $('#save-ext-display').data('ext') || '';
    if (!basename) { $('#save-filename').focus(); return; }
    var fullName = basename + (ext ? '.' + ext : '');
    $('#save-overlay').hide();
    doDownload(_currentPath, fullName);
});

// ── 下載：透過 PHP 端點觸發瀏覽器原生下載（支援自訂檔名）──
// 若瀏覽器設定「每次詢問儲存位置」，會顯示另存新檔對話框；否則存至下載資料夾。
function doDownload(url, filename) {
    var a = document.createElement('a');
    a.href = 'bom_download.php?path=' + encodeURIComponent(url)
           + '&filename=' + encodeURIComponent(filename);
    a.download = filename;
    document.body.appendChild(a);
    a.click();
    document.body.removeChild(a);
}

// ── 檔案列表點擊 ─────────────────────────────────────────────────────────
$(document).on('click', '.bom-file-item', function(e) {
    e.preventDefault();
    $('.bom-file-item').removeClass('active');
    $(this).addClass('active');
    var isObs = $(this).data('obsolete') === '1' || $(this).data('obsolete') === 1;
    showFile($(this).data('path'), $(this).data('type'), $(this).data('name'));
    // 作廢 overlay
    $('#viewer-content .bom-obsolete-overlay').remove();
    if (isObs) {
        $('#viewer-content').css('position', 'relative').append(
            '<div class="bom-obsolete-overlay" style="position:absolute;inset:0;display:flex;align-items:center;justify-content:center;pointer-events:none;z-index:10;">'
            + '<div style="color:rgba(220,53,69,0.45);font-size:88px;font-weight:900;letter-spacing:12px;text-shadow:0 2px 16px rgba(220,53,69,.15);user-select:none;transform:rotate(-20deg);">作廢</div>'
            + '</div>'
        );
    }
});

// ── 載入檔案清單（依模式選擇後端）───────────────────────────────────────
var _fetchUrl    = (_mode === 'did') ? '' : 'OreadyReply_ForPm_BaseOfTime.php';
var _fetchParams = (_mode === 'did')
    ? { action: 'get_files_by_did', d_id: _d_id }
    : { action: 'get_bom_files', bom: _bom };
$.post(_fetchUrl, _fetchParams, function(res) {
    var listHtml = '';
    var hasFiles = false;
    var firstItem = null;

    function makeItem(f, active) {
        if (!firstItem && active) firstItem = f;
        var label = '';
        if (f.is_plus) {
            label = '<span class="label label-warning" style="margin-right:4px;">加工圖</span>';
        }
        if (f.tags && f.tags.length > 0) {
            f.tags.forEach(function(t) {
                label += '<span class="label" style="background:'+escapeHtml(t.color||'#777')+';color:#fff;margin-right:3px;">'+escapeHtml(t.label)+'</span>';
            });
        }
        // d_id 模式下 f.label 帶有 bom+qty 標題；一般模式沿用 f.name
        var displayName = (f.label && _mode === 'did') ? f.label + ' / ' + f.name : f.name;
        return '<a href="#" class="list-group-item bom-file-item'+(active?' active':'')+'"'
            +' data-path="'+escapeHtml(f.path)+'"'
            +' data-type="'+escapeHtml(f.type)+'"'
            +' data-name="'+escapeHtml(f.name)+'">'
            +'<p class="list-group-item-text">'+label+escapeHtml(displayName)+'</p></a>';
    }

    if (res && res.success) {
        if (res.files && res.files.length > 0) {
            hasFiles = true;
            listHtml += '<li class="list-group-item list-group-item-info"><strong>BOM 圖檔</strong></li>';
            res.files.forEach(function(f, i) { listHtml += makeItem(f, i === 0); });
        }
        if (res.erp_files && res.erp_files.length > 0) {
            hasFiles = true;
            var bomM = res.erp_files.filter(function(f) { return f.match_type === 'bom' || !f.match_type; });
            var didM = res.erp_files.filter(function(f) { return f.match_type === 'did'; });
            if (bomM.length > 0) {
                listHtml += '<li class="list-group-item list-group-item-warning" style="margin-top:6px;"><strong>ERP/資材報告</strong></li>';
                bomM.forEach(function(f, i) { listHtml += makeItem(f, !res.files.length && i === 0); });
            }
            if (didM.length > 0) {
                listHtml += '<li class="list-group-item list-group-item-danger" style="margin-top:6px;"><strong>不確定批號 (僅匹配料號)</strong></li>';
                didM.forEach(function(f, i) { listHtml += makeItem(f, !res.files.length && !bomM.length && i === 0); });
            }
        }
    }

    if (hasFiles && listHtml) {
        $('#bom-file-list').html(listHtml);
        if (firstItem) showFile(firstItem.path, firstItem.type, firstItem.name);
    } else {
        $('#bom-file-list').html('<div class="alert alert-warning" style="margin:10px;">無相關圖檔</div>');
        $('#viewer-placeholder').text('無相關圖檔').show();
    }

    // ── 附件區塊（did 模式才載入）─────────────────────────────────────────
    if (_mode === 'did' && _d_id) {
        $.post('', { action: 'get_attachments_by_did', d_id: _d_id }, function(attRes) {
            if (!attRes.success || !attRes.attachments || attRes.attachments.length === 0) return;
            var attHtml = '<li class="list-group-item att-section-header">'
                + '<strong><i class="fa fa-paperclip"></i> 料號附件</strong>'
                + '<small style="float:right;font-weight:normal;color:#5d6d7e;">' + attRes.attachments.length + ' 個</small>'
                + '</li>';
            attRes.attachments.forEach(function(att) {
                var catBadges = '';
                (att.category_names || []).forEach(function(cn) {
                    if (cn === '作廢') return; // 已由紅色 badge 顯示，不重複
                    catBadges += '<span class="label label-info" style="margin-right:2px;font-size:10px;">' + escapeHtml(cn) + '</span>';
                });
                var extBadge = '<span class="label label-default" style="margin-right:4px;font-size:10px;">' + escapeHtml((att.ext || '').toUpperCase()) + '</span>';
                var info = [att.uploaded_at, att.uploaded_by, att.file_size, att.note].filter(Boolean).join(' · ');
                var isObs = (att.category_names || []).indexOf('作廢') >= 0;
                var attItemStyle = isObs ? 'background:#fff0f0;border-left:3px solid #e74c3c;' : '';
                attHtml += '<a href="#" class="list-group-item bom-file-item att-file-item"'
                    + ' data-path="' + escapeHtml(att.url) + '"'
                    + ' data-type="' + escapeHtml(att.ext) + '"'
                    + ' data-name="' + escapeHtml(att.display_name) + '"'
                    + ' data-obsolete="' + (isObs ? '1' : '0') + '"'
                    + ' style="' + attItemStyle + '">'
                    + (isObs ? '<div style="display:inline-block;background:#e74c3c;color:#fff;font-size:10px;font-weight:700;padding:0 7px;border-radius:3px;letter-spacing:1px;margin-bottom:3px;">⊘ 作廢</div><br>' : '')
                    + '<p class="list-group-item-text" style="' + (isObs ? 'color:#c0392b;text-decoration:line-through;' : '') + '">'
                    + extBadge + catBadges + escapeHtml(att.display_name)
                    + (info ? '<br><small style="color:#aaa;font-size:10px;">' + escapeHtml(info) + '</small>' : '')
                    + '</p></a>';
            });
            $('#bom-file-list').append(attHtml);
        }, 'json');
    }
}, 'json').fail(function() {
    $('#bom-file-list').html('<div class="alert alert-danger" style="margin:10px;">載入失敗，請重試</div>');
});

// ── 設定標籤 ──────────────────────────────────────────────────────────────
var _colorMap = { 'default':'#777777','primary':'#337ab7','success':'#5cb85c','info':'#5bc0de','warning':'#f0ad4e','danger':'#d9534f' };

function openFileTagsSetting() {
    $.post('', { action: 'get_file_tags_setting' }, function(res) {
        if (res.success) {
            $('#tagsSettingTable tbody').empty();
            if (res.config && res.config.length > 0) {
                res.config.forEach(function(item) { addTagRow(item.suffix, item.label, item.color); });
            }
            $('#fileTagsSettingModal').modal('show');
        } else {
            alert('載入設定失敗: ' + (res.message || '未知錯誤'));
        }
    }, 'json');
}

function addTagRow(suffix, label, color) {
    var c = _colorMap[color] || (color && color.startsWith('#') ? color : '#777777');
    var row = '<tr>'
        + '<td><input type="text" class="form-control input-sm tag-suffix" value="' + escapeHtml(suffix||'') + '" placeholder="-T"></td>'
        + '<td><input type="text" class="form-control input-sm tag-label" value="' + escapeHtml(label||'') + '" placeholder="叫料"></td>'
        + '<td><input type="color" class="form-control input-sm tag-color" value="' + escapeHtml(c) + '"></td>'
        + '<td><button type="button" class="btn btn-danger btn-xs" onclick="$(this).closest(\'tr\').remove()"><i class="fa fa-trash"></i></button></td>'
        + '</tr>';
    $('#tagsSettingTable tbody').append(row);
}

function saveFileTagsSetting() {
    var config = [];
    $('#tagsSettingTable tbody tr').each(function() {
        var suffix = $(this).find('.tag-suffix').val().trim();
        var label  = $(this).find('.tag-label').val().trim();
        var color  = $(this).find('.tag-color').val();
        if (suffix && label) config.push({ suffix: suffix, label: label, color: color });
    });
    $.post('', { action: 'save_file_tags_setting', tags_config: JSON.stringify(config) }, function(res) {
        if (res.success) {
            $('#fileTagsSettingModal').modal('hide');
            // 重新載入檔案清單以套用新標籤
            location.reload();
        } else {
            alert('儲存失敗: ' + (res.message || '未知錯誤'));
        }
    }, 'json');
}
</script>
</body>
</html>
