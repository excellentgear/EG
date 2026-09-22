<?php
/**
 * as_doc_editor.php — AS 文件「線上版」編輯器（程序書電子版）
 * 建立：2026-09-22（使用者交辦：把二階文件做成電子版，像文件備註那樣可改字型大小顏色排縮，
 *       也要像 Word 一樣能插入圖面（可改位置大小裁切）、繪製圖形或流程圖）
 *
 * 這一頁是「帶參數才能開的子頁」（?version_id=），依鐵律6 不登記進選單；
 * 入口在 AS 文件管理（as_document_management.php）的歷史版本清單。
 *
 * 規則一律走共用庫，這裡只做畫面與呼叫：
 *   內容/資產/裁切/版次凍結 → src/common/as_doc_content_lib.php
 *   Word 匯入與未轉換清單   → src/common/as_doc_import_lib.php
 *   富文字工具列與白名單    → resource/js/eg_richtext.js（profile 'doc'）
 *   流程圖編輯器            → views/ADM/_flow_editor_ui.php（Fabric）
 *   列印                    → views/ADM/as_doc_print.php（ai-rules/16）
 */
session_start();
if (!isset($_SESSION['userName'])) {
    $_SESSION['lastpage'] = "../../views/ADM/as_doc_editor.php" .
        (isset($_GET['version_id']) ? '?version_id=' . (int)$_GET['version_id'] : '');
    header("Location:../../index.php");
    exit;
}
include_once '../../src/common/_config.php';
include_once '../../src/common/DBConnection.php';
include_once '../../src/common/as_doc_content_lib.php';

$db  = (new DBConnection())->getPDO();
adc_ensure_schema($db);
$uid = (int)($_SESSION['id'] ?? 0);
$P   = adc_perms($db, $uid);

// ?version_id= 優先；只給 ?doc_id= 時用該文件目前的版次（從文件清單點進來比較直覺）
$versionId = (int)($_GET['version_id'] ?? 0);
if ($versionId <= 0 && !empty($_GET['doc_id'])) {
    try {
        $st = $db->prepare("SELECT current_version_id FROM as_document WHERE id=? AND is_deleted=0");
        $st->execute([(int)$_GET['doc_id']]);
        $versionId = (int)($st->fetchColumn() ?: 0);
    } catch (Throwable $e) {}
}
$V = $versionId > 0 ? adc_version_info($db, $versionId) : null;
if (empty($_SESSION['adc_csrf'])) $_SESSION['adc_csrf'] = bin2hex(random_bytes(16));

$blocked = '';
if (!$P['view'])                                   $blocked = '沒有 AS 文件的檢視權限';
elseif (!$V)                                       $blocked = '找不到這個版次（可能已被刪除，或網址少了 version_id）';
elseif ((int)$V['is_obsolete'] === 1 && !$P['admin']) $blocked = '這份文件已廢止，只有管理員能開啟';
?>
<!DOCTYPE html>
<html lang="zh-Hant">
<head>
    <meta charset="utf-8">
    <meta http-equiv="X-UA-Compatible" content="IE=edge">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title><?= $V ? htmlspecialchars($V['doc_no'] . ' ' . $V['doc_name']) : 'AS 文件線上版' ?> — 線上版編輯</title>
    <link href="../../resource/css/bootstrap.css" rel="stylesheet">
    <link href="../../resource/css/font-awesome.css" rel="stylesheet">
    <link href="../../resource/css/nprogress.css" rel="stylesheet">
    <link href="../../resource/css/custom.css" rel="stylesheet">
    <style>
        #sidebar-menu { visibility: hidden; }
        /* .right_col 的第一個子元素一定要 clear:both——.top_nav 高度是 0 且浮動溢出，
           第一個子元素若自成 BFC（overflow:hidden / display:flex / float）會被壓成寬度 0，
           標題整條消失＋上方空一大段（鐵律6，本專案已踩兩次） */
        .right_col .page-title { margin:8px 0 4px; overflow:hidden; clear:both; }
        .page-help-btn { height:30px; font-size:13px; padding:0 12px; border:1px solid #d98a33; border-radius:15px;
            background:#F0A24B; color:#fff; cursor:pointer; }
        .page-help-btn:hover { background:#d98a33; }

        .ad-head { background:#faf6f0; border:1px solid #e4d3ba; border-radius:5px; padding:8px 12px; margin-bottom:9px;
            display:flex; flex-wrap:wrap; align-items:center; gap:14px; clear:both; }
        .ad-head .ad-no { font-size:15px; font-weight:bold; color:#4E2C0B; }
        .ad-head .ad-nm { font-size:14px; color:#6B471A; }
        .ad-head .ad-kv { font-size:12px; color:#8A5A2B; }
        .ad-head .ad-kv b { color:#4E2C0B; }
        .ad-badge { display:inline-block; font-size:11px; line-height:19px; padding:0 8px; border-radius:10px; }
        .ad-badge.primary { background:#F0A24B; color:#fff; }
        .ad-badge.draft   { background:#EBD3A8; color:#6B471A; }
        .ad-badge.none    { background:#eee; color:#888; }

        .ad-bar { display:flex; flex-wrap:wrap; gap:6px; align-items:center; margin-bottom:9px; clear:both; }
        .ad-bar .btn { font-size:13px; }
        .ad-dirty { color:#DD5138; font-size:12px; font-weight:bold; display:none; }
        .ad-dirty.on { display:inline; }
        .ad-saved { color:#7A9A4A; font-size:12px; }

        /* 未轉換清單：匯入之後最重要的東西，所以用強調色而且預設展開 */
        .ad-report { border:1px solid #e4b77a; background:#fdf6ea; border-radius:5px; padding:9px 12px; margin-bottom:10px; }
        .ad-report h4 { margin:0 0 6px; font-size:14px; color:#8A5A2B; }
        .ad-report ul { margin:0 0 4px 0; padding-left:20px; }
        .ad-report li { font-size:12.5px; line-height:1.75; color:#5a4326; }
        .ad-report li.must { color:#A34E2A; }
        .ad-report li.must b { color:#DD5138; }
        .ad-report .ad-rp-ok li { color:#4a6b33; }
        .ad-report .ad-rp-x { float:right; cursor:pointer; color:#b08a57; }

        .ad-noperm { border:1px solid #e4d3ba; background:#faf6f0; border-radius:5px; padding:26px; text-align:center; color:#6B471A; }

        /* 跳窗（沿用全站慣例；寬度一律固定像素，不可用 vw——vw 相對整個瀏覽器視窗會蓋過側選單） */
        .m-mask { position:fixed; inset:0; background:rgba(0,0,0,.45); z-index:10300; display:none; }
        .m-mask.open { display:block; }
        .m-win { position:absolute; left:50%; top:50%; transform:translate(-50%,-50%); background:#fff;
            border-radius:6px; box-shadow:0 8px 30px rgba(0,0,0,.35); width:560px; max-width:96%; max-height:92%;
            display:flex; flex-direction:column; }
        .m-win.wide { width:860px; }
        .m-head { padding:9px 14px; border-bottom:1px solid #e4d3ba; background:#faf6f0; border-radius:6px 6px 0 0;
            font-weight:bold; color:#4E2C0B; display:flex; align-items:center; }
        .m-head .m-x { margin-left:auto; cursor:pointer; font-size:20px; line-height:1; color:#8A5A2B; }
        .m-body { padding:12px 14px; overflow:auto; }
        .m-foot { padding:9px 14px; border-top:1px solid #e4d3ba; background:#faf6f0; border-radius:0 0 6px 6px; text-align:right; }
        .m-note { background:#fdf6ea; border:1px solid #e9d6b4; border-radius:4px; padding:7px 10px; font-size:12px;
            color:#6B471A; line-height:1.7; margin-bottom:10px; }

        /* 裁切 */
        .ad-crop-stage { position:relative; display:inline-block; background:#333; max-width:100%; }
        .ad-crop-stage img { display:block; max-width:100%; max-height:420px; }
        .ad-crop-box { position:absolute; border:2px solid #F0A24B; box-shadow:0 0 0 9999px rgba(0,0,0,.45); cursor:move; }
        .ad-crop-h { position:absolute; width:12px; height:12px; background:#F0A24B; border:2px solid #fff; border-radius:2px; }
        .ad-crop-h.tl { left:-7px; top:-7px; cursor:nwse-resize; }
        .ad-crop-h.br { right:-7px; bottom:-7px; cursor:nwse-resize; }

        .help-doc h4 { color:#8A5A2B; font-size:14px; margin:14px 0 6px; }
        .help-doc h4:first-child { margin-top:0; }
        .help-doc p, .help-doc li { font-size:13px; line-height:1.8; color:#4a4a4a; }
        .help-doc ol, .help-doc ul { padding-left:22px; }
        .help-doc .hl { background:#F7E0BD; padding:0 3px; border-radius:2px; }

        @media print { .page-help-btn, .ad-bar, .ad-report, .m-mask { display:none !important; } }
    </style>
</head>
<body class="nav-sm">
<div class="container body">
<div class="main_container">
    <?php include '../partPage/sideAndTopBarMenu.html' ?>
    <div class="right_col" role="main">
        <div class="page-title" style="display:flex;align-items:center;flex-wrap:wrap;">
            <h2 style="margin:6px 0;">AS 文件線上版
                <small style="color:#8a6d45;">程序書電子版：直接在網頁上編輯整份文件（含圖片與流程圖）</small></h2>
            <button id="btnPageHelp" class="page-help-btn" style="margin-left:auto;"><i class="fa fa-question-circle"></i> 使用說明</button>
        </div>
        <div class="clearfix"></div>

<?php if ($blocked !== ''): ?>
        <div class="ad-noperm">
            <h4><i class="fa fa-lock"></i> <?= htmlspecialchars($blocked) ?></h4>
            <p>請洽管理者於「使用者權限設定」指派 AS 文件管理的相關角色，或回
               <a href="as_document_management.php">AS 文件管理</a> 重新選一份文件。</p>
        </div>
<?php else: ?>
        <div class="ad-head">
            <span class="ad-no"><?= htmlspecialchars($V['doc_no']) ?></span>
            <span class="ad-nm"><?= htmlspecialchars($V['doc_name']) ?></span>
            <span class="ad-kv">階別 <b><?= htmlspecialchars((string)$V['doc_level']) ?></b></span>
            <span class="ad-kv">版次 <b><?= htmlspecialchars((string)$V['version']) ?></b></span>
            <span class="ad-kv">修訂日 <b id="adRevDate"><?= htmlspecialchars((string)($V['revised_date'] ?: '—')) ?></b></span>
            <span id="adPrimaryBadge" class="ad-badge none">尚未建立線上版</span>
            <?php if ((int)$V['current_version_id'] !== (int)$V['version_id']): ?>
              <span class="ad-badge draft" title="這不是目前生效的版次，是歷史版本">歷史版本</span>
            <?php endif; ?>
            <span style="margin-left:auto">
              <a class="btn btn-default btn-sm" href="as_document_management.php"><i class="fa fa-arrow-left"></i> 回 AS 文件管理</a>
            </span>
        </div>

        <div class="ad-bar">
          <?php if ($P['edit']): ?>
            <button class="btn btn-warning btn-sm" id="btnSave"><i class="fa fa-save"></i> 存檔 <small>(Ctrl+S)</small></button>
            <span class="ad-dirty" id="adDirty"><i class="fa fa-exclamation-circle"></i> 有未存檔的變更</span>
            <span class="ad-saved" id="adSaved"></span>
            <span style="width:10px"></span>
            <button class="btn btn-default btn-sm" id="btnImport" title="用 LibreOffice 把這個版次掛的 Word 原始檔轉成線上內容">
              <i class="fa fa-file-word-o"></i> 從 Word 匯入</button>
            <button class="btn btn-default btn-sm" id="btnFork" title="把同一份文件其他版次的線上內容複製過來（含圖片與流程圖）">
              <i class="fa fa-copy"></i> 從其他版次複製</button>
            <span style="width:10px"></span>
            <label style="font-weight:normal;font-size:12px;color:#6B471A;margin:0;">
              <input type="checkbox" id="chkPrimary"> 設為此版次的正本
            </label>
          <?php endif; ?>
            <span style="width:10px"></span>
            <span class="ad-kv" style="font-size:12px;color:#8A5A2B">紙張</span>
            <select id="selPage" class="form-control input-sm" style="width:auto;display:inline-block" data-eg-skip
                    <?= $P['edit'] ? '' : 'disabled' ?>>
              <option value="A4">A4</option><option value="A3">A3</option>
            </select>
            <select id="selOrient" class="form-control input-sm" style="width:auto;display:inline-block" data-eg-skip
                    <?= $P['edit'] ? '' : 'disabled' ?>>
              <option value="portrait">直式</option><option value="landscape">橫式</option>
            </select>
            <button class="btn btn-default btn-sm" id="btnPrint"><i class="fa fa-print"></i> 列印預覽</button>
        </div>

        <div class="ad-report" id="adReport" style="display:none">
          <span class="ad-rp-x" id="adReportX" title="收起">&times;</span>
          <h4><i class="fa fa-info-circle"></i> 這份文件從 Word 匯入的結果</h4>
          <div id="adReportBody"></div>
        </div>

        <div id="adEditorHost"></div>
        <div id="adReadonly" style="display:none" class="egrt-wrap"></div>
<?php endif; ?>
    </div>
</div>
</div>

<!-- 插入圖片 -->
<div class="m-mask" id="mUpload"><div class="m-win">
  <div class="m-head">插入圖片 <span class="m-x" data-close="mUpload">&times;</span></div>
  <div class="m-body">
    <div class="m-note">
      可以放產品照片、掃描的圖面、或已經畫好的流程圖圖片。<br>
      接受 PNG／JPG／GIF，單檔 20MB 以內。<b>放進來之後可以拖右下角改大小，也可以裁切。</b><br>
      如果是要畫「之後還要修改」的流程圖，請改用工具列的
      <b><i class="fa fa-sitemap"></i> 插入流程圖</b>——那個存的是可再編輯的圖形，不是一張死的圖片。
    </div>
    <!-- 原生可見的 file input：使用者環境的 change 事件會被吞掉，
         所以一律用可見的原生 input＋送出時直讀 input.files（記憶 file_upload_change_event） -->
    <input type="file" id="upFile" accept="image/png,image/jpeg,image/gif">
    <div id="upMsg" style="margin-top:8px;font-size:12px;color:#DD5138"></div>
  </div>
  <div class="m-foot">
    <button class="btn btn-default btn-sm" data-close="mUpload">取消</button>
    <button class="btn btn-warning btn-sm" id="btnUpDo"><i class="fa fa-upload"></i> 插入</button>
  </div>
</div></div>

<!-- 裁切 -->
<div class="m-mask" id="mCrop"><div class="m-win wide">
  <div class="m-head">裁切圖片 <span class="m-x" data-close="mCrop">&times;</span></div>
  <div class="m-body" style="text-align:center">
    <div class="m-note" style="text-align:left">
      拖曳框內可移動，拖左上／右下角可改大小。<b>裁切只記「裁切範圍」，原圖不會被改掉</b>，
      所以隨時可以按「取消裁切」還原，改版之後也能重新裁。
    </div>
    <div class="ad-crop-stage" id="cropStage">
      <img id="cropImg" src="" alt="">
      <div class="ad-crop-box" id="cropBox"><div class="ad-crop-h tl"></div><div class="ad-crop-h br"></div></div>
    </div>
  </div>
  <div class="m-foot">
    <button class="btn btn-default btn-sm" id="btnCropReset" style="float:left">取消裁切（還原整張）</button>
    <button class="btn btn-default btn-sm" data-close="mCrop">取消</button>
    <button class="btn btn-warning btn-sm" id="btnCropDo"><i class="fa fa-crop"></i> 套用裁切</button>
  </div>
</div></div>

<!-- 從其他版次複製 -->
<div class="m-mask" id="mFork"><div class="m-win">
  <div class="m-head">從其他版次複製線上內容 <span class="m-x" data-close="mFork">&times;</span></div>
  <div class="m-body">
    <div class="m-note">
      會把來源版次的內容<b>連圖片與流程圖的檔案一起複製</b>一份給這個版次。<br>
      為什麼要複製而不是共用：AS9100 的舊版必須永遠印得出「當時的樣子」，
      共用同一個檔案的話，之後在這個版次改流程圖會<b>連舊版印出來的內容一起被改掉</b>。<br>
      目前這個版次<b>還沒有任何線上內容</b>時才能複製（避免把已經編好的內容洗掉）。
    </div>
    <select id="selFork" class="form-control" data-eg-filter="輸入版次篩選…"></select>
  </div>
  <div class="m-foot">
    <button class="btn btn-default btn-sm" data-close="mFork">取消</button>
    <button class="btn btn-warning btn-sm" id="btnForkDo"><i class="fa fa-copy"></i> 複製過來</button>
  </div>
</div></div>

<!-- 使用說明（鐵律7） -->
<div class="m-mask" id="helpUseMask"><div class="m-win wide">
  <div class="m-head">使用說明 — AS 文件線上版編輯器 <span class="m-x" data-close="helpUseMask">&times;</span></div>
  <div class="m-body help-doc">
    <h4>這一頁在做什麼</h4>
    <p>把程序書這種整份文件<span class="hl">直接在網頁上編輯</span>，取代原本「線下用 Word 編好 → 上傳 → 轉成 PDF 給人看」的做法。
       編好之後列印出來的表頭、AS 文件編號、版次與頁碼都由系統自動產生，不必自己在內容裡打。</p>

    <h4>操作步驟</h4>
    <ol>
      <li><b>把舊的 Word 內容搬進來</b>：按「從 Word 匯入」，系統會用 LibreOffice 轉檔，
          <span class="hl">文字與表格會轉進來</span>，轉不動的會列在上方的清單裡叫你補。</li>
      <li><b>核對並編輯</b>：工具列可改段落階層、字型、字級、顏色、對齊、縮排、清單、插入表格。</li>
      <li><b>補流程圖</b>：按「插入流程圖」畫。方框、菱形、起訖框、箭頭都有，
          <span class="hl">雙擊圖形可以直接打字</span>，箭頭選起來後兩端的圓點可以各自拉。</li>
      <li><b>插入圖片</b>：按「插入圖片」上傳，之後點一下圖片可改寬度、對齊、裁切。</li>
      <li><b>存檔</b>（Ctrl+S）。確認整份沒問題之後再勾<span class="hl">「設為此版次的正本」</span>。</li>
    </ol>

    <h4>重要行為與常見疑問</h4>
    <ul>
      <li><b>為什麼流程圖一定要重畫？</b>Word 裡的流程圖是「繪圖物件」——方框是文字方塊（文字轉得進來），
          但<span class="hl">箭頭與連接線匯出後是一個一個小圖碎片</span>，它們的位置靠 Word 的繪圖畫布，
          HTML 裡沒有那個座標系，拼不回原來的圖，所以只能重畫。方框裡的字大多已經轉進來了，可以照著打。</li>
      <li><b>「設為此版次的正本」是什麼意思？</b>沒勾＝線上內容只是草稿，別人看到與印出來的還是原本的 Word／PDF；
          勾了＝這個版次<span class="hl">以線上內容為準</span>。匯入後一律先當草稿，是為了讓你先核對完再切換。</li>
      <li><b>改版之後舊版會不會被改到？</b>不會。線上內容是<span class="hl">綁在版次上</span>的，
          而且「從其他版次複製」會把圖片與流程圖的檔案也各複製一份，
          所以在新版動流程圖，舊版印出來完全不變。</li>
      <li><b>裁切會不會弄壞原圖？</b>不會。裁切只記範圍，原圖不動，隨時可以還原或重新裁。</li>
      <li><b>頁首頁尾要自己打嗎？</b>不要。表頭（表單名稱）與頁尾（AS 編號＋版次、頁碼）列印時由系統依這份文件自動產生。</li>
      <li><b>可以貼 Word 的內容嗎？</b>可以，貼上時會自動清洗：文字、粗體、底線、顏色、表格會留下來，
          但<span class="hl">圖片不會跟著貼進來</span>（要用「插入圖片」上傳，檔案才會真的存進系統）。</li>
    </ul>

    <h4>設定入口</h4>
    <ul>
      <li>檔案存放位置：系統設定的 <code>as_doc_content_dir</code>（沒設就放在 AS9100 根目錄底下的「AS文件線上版」資料夾）。</li>
      <li>文件本身的編號、名稱、版次、修訂日：在 <a href="as_document_management.php">AS 文件管理</a> 維護，這一頁只編內容。</li>
    </ul>

    <h4>權限角色</h4>
    <ul>
      <li><b>檢視</b>：有 AS 文件檢視權就看得到線上內容。</li>
      <li><b>編輯</b>：需要 AS 文件管理的頁面 A 權，或角色功能碼
          <code>編輯線上內容（程序書電子版）</code>。</li>
      <li>已廢止的文件只有管理員能開啟（比照 AS 文件管理既有的口徑）。</li>
    </ul>
  </div>
  <div class="m-foot"><button class="btn btn-warning btn-sm" data-close="helpUseMask">我知道了</button></div>
</div></div>

<?php if ($blocked === '') include __DIR__ . '/_flow_editor_ui.php'; ?>

<script src="../../resource/js/jquery.min.js"></script>
<script src="../../resource/js/bootstrap.min.js"></script>
<script src="../../resource/js/nprogress.js"></script>
<script src="../../resource/js/custom.min.js"></script>
<script src="../../resource/js/eg_input_rules.js?v=<?= @filemtime(__DIR__.'/../../resource/js/eg_input_rules.js') ?>"></script>
<script src="../../resource/js/eg_richtext.js?v=<?= @filemtime(__DIR__.'/../../resource/js/eg_richtext.js') ?>"></script>
<script src="../../resource/js/eg_date_fmt.js?v=<?= @filemtime(__DIR__.'/../../resource/js/eg_date_fmt.js') ?>"></script>
<?php if ($blocked === ''): ?>
<script src="../../resource/js/fabric.min.js?v=<?= @filemtime(__DIR__.'/../../resource/js/fabric.min.js') ?>"></script>
<?php endif; ?>
<script>
$(document).ready(function(){
    var $am = $('#sidebar-menu .nav.side-menu > li.active');
    if ($am.length) { $am.removeClass('active').find('ul.child_menu').hide(); $am.find('li.current-page').removeClass('current-page'); }
    $('#sidebar-menu').css('visibility','visible');
});
</script>
<?php if ($blocked === ''): ?>
<script>
'use strict';
var API = '../../src/store/AsDocContent_API.php';
var VID = <?= (int)$versionId ?>;
var CSRF = '<?= htmlspecialchars($_SESSION['adc_csrf'], ENT_QUOTES) ?>';
var CAN_EDIT = <?= $P['edit'] ? 'true' : 'false' ?>;
var ED = null;                 // 富文字編輯器實例
var ASSET_KIND = {};           // 資產編號 → image/flow（決定圖片浮動列要不要出現「編輯流程圖」）
var DIRTY = false;
var VERSIONS = [];

function assetUrl(id) { return API + '?action=asset&id=' + encodeURIComponent(id); }
function setDirty(on) {
    DIRTY = !!on;
    $('#adDirty').toggleClass('on', DIRTY);
    if (DIRTY) $('#adSaved').text('');
}
function openMask(id) { document.getElementById(id).classList.add('open'); }
function closeMask(id) { document.getElementById(id).classList.remove('open'); }
$(document).on('click', '[data-close]', function(){ closeMask($(this).data('close')); });
$('#btnPageHelp').on('click', function(){ openMask('helpUseMask'); });
$('#adReportX').on('click', function(){ $('#adReport').hide(); });
// 單頁／雙頁並排的選擇記下來，下次開同一頁不必再切
$(document).on('click', '#adEditorHost .egrt-btn[data-view]', function(){
    try { localStorage.setItem('adc_view_mode', $(this).attr('data-view')); } catch(e) {}
});

/* 統一錯誤顯示：API 未捕捉的例外會回 500＋JSON，非 2xx 時 jQuery 不會進 success，
   沒有這一段的話畫面上就是「按了完全沒反應」（本專案踩過多次） */
$(document).ajaxError(function(_e, xhr){
    var m = '';
    try { m = (JSON.parse(xhr.responseText) || {}).message || ''; } catch (e) {}
    alert(m || ('伺服器沒有正常回應（HTTP ' + xhr.status + '），請重新整理頁面後再試'));
});

/* ── 載入 ─────────────────────────────────────────────────────────────── */
function load() {
    $.getJSON(API, { action:'get', version_id: VID }, function(r){
        if (!r.success) { alert(r.message || '讀取失敗'); return; }
        if (r.csrf) CSRF = r.csrf;
        VERSIONS = r.versions || [];
        ASSET_KIND = {};
        (r.assets || []).forEach(function(a){ ASSET_KIND[String(a.id)] = a.kind; });

        var c = r.content;
        $('#chkPrimary').prop('checked', !!(c && c.is_primary));
        $('#selPage').val((c && c.page_size) || 'A4');
        $('#selOrient').val((c && c.orientation) || 'portrait');
        paintPrimaryBadge(c);
        paintReport(c && c.report);

        var html = (c && c.html) || '';
        if (CAN_EDIT) {
            if (!ED) ED = mkEditor();
            ED.set(html);
            setDirty(false);
            if (c && c.updated_at) $('#adSaved').text('上次存檔 ' + fmt(c.updated_at));
        } else {
            // 沒有編輯權：唯讀顯示（清洗後才輸出，鐵律8 的第二道防線）
            // 一樣依分頁標記切成一頁一頁，跟有編輯權看到的版面一致
            $('#adEditorHost').hide();
            var host = $('#adReadonly').show()[0];
            host.className = 'egrt-wrap';
            var mm = ((c && c.page_size) === 'A3') ? [297, 420] : [210, 297];
            if ((c && c.orientation) === 'landscape') mm = [mm[1], mm[0]];
            var clean = EGRichText.render(html, 'doc');
            var parts = clean ? clean.split(/<hr[^>]*page-break-after[^>]*>/i) : [];
            if (!parts.length) parts = ['<p style="color:#a08a6f">這個版次還沒有線上版內容。</p>'];
            host.innerHTML = '<div class="egrt-doc-scroll" style="max-height:680px"><div class="egrt-pages">'
                + parts.map(function(p, i){
                    // 頁碼籤放紙張外面（與編輯模式同一套結構，見 eg_richtext.js 的說明）
                    return '<div class="egrt-sheetwrap">'
                         + '<div class="egrt-page egrt-sheet" style="width:' + mm[0] + 'mm;height:' + mm[1] + 'mm">'
                         + p + '</div>'
                         + '<span class="egrt-pageno">第 ' + (i+1) + ' 頁 / 共 ' + parts.length + ' 頁</span>'
                         + '</div>';
                  }).join('')
                + '</div></div>';
            // src 不存在內容裡，唯讀顯示也要依資產編號補回來
            Array.prototype.slice.call(host.querySelectorAll('img[data-asset]')).forEach(function(im){
                im.setAttribute('src', assetUrl(im.getAttribute('data-asset')));
            });
            // 唯讀也要縮放到容器寬度內，否則同樣會出現左右拉桿
            (function fitRo(){
                var sc = host.querySelector('.egrt-doc-scroll'), box = host.querySelector('.egrt-pages');
                if (!sc || !box) return;
                var pw = mm[0] * 96 / 25.4, need = pw + 32;
                var k = Math.min(1, (sc.clientWidth || need) / need);
                box.style.zoom = k < 1 ? k : '';
                $(window).on('resize', function(){
                    var k2 = Math.min(1, (sc.clientWidth || need) / need);
                    box.style.zoom = k2 < 1 ? k2 : '';
                });
            })();
        }
    });
}

function fmt(s) {
    if (!s) return '';
    var d = String(s).split(' ');
    return (window.egFmtDate ? egFmtDate(d[0]) : d[0]) + (d[1] ? ' ' + d[1].substring(0,5) : '');
}

function paintPrimaryBadge(c) {
    var $b = $('#adPrimaryBadge');
    if (!c || !c.html) { $b.attr('class','ad-badge none').text('尚未建立線上版'); return; }
    if (c.is_primary) $b.attr('class','ad-badge primary').text('線上版為正本');
    else              $b.attr('class','ad-badge draft').text('線上版草稿（尚未設為正本）');
}

/** 未轉換清單：使用者明確要求「轉不動的要列出來」，所以待補項目一律醒目顯示 */
function paintReport(rep) {
    if (!rep) { $('#adReport').hide(); return; }
    var h = '';
    var must = (rep.todo || []).filter(function(t){ return t.level === 'must'; });
    var info = (rep.todo || []).filter(function(t){ return t.level !== 'must'; });
    if (must.length) {
        h += '<div><b style="color:#DD5138">還要人工補這些（' + must.length + ' 項）</b><ul>';
        must.forEach(function(t){ h += '<li class="must">' + esc(t.note) + '</li>'; });
        h += '</ul></div>';
    }
    if ((rep.done || []).length) {
        h += '<div class="ad-rp-ok"><b style="color:#4a6b33">已經轉進來的</b><ul>';
        rep.done.forEach(function(t){ h += '<li>' + esc(t.note) + '</li>'; });
        h += '</ul></div>';
    }
    if (info.length) {
        h += '<div><ul>';
        info.forEach(function(t){ h += '<li style="color:#7a6a52">' + esc(t.note) + '</li>'; });
        h += '</ul></div>';
    }
    h += '<div style="font-size:11.5px;color:#a08a6f;margin-top:4px">來源：' + esc(rep.src || '')
       + (rep.at ? '　匯入於 ' + esc(rep.at) : '') + '</div>';
    $('#adReportBody').html(h);
    $('#adReport').show();
}
function esc(s) { return $('<i>').text(s == null ? '' : s).html(); }

/* ── 建立編輯器（圖片/流程圖/裁切都掛回呼，共用元件不碰模組 API）────────── */
function mkEditor() {
    return EGRichText.attach('#adEditorHost', {
        profile: 'doc',
        // 紙張大小與方向交給共用元件，編輯區的內容寬才會跟列印完全一致
        pageSize: $('#selPage').val() || 'A4',
        orientation: $('#selOrient').val() || 'portrait',
        viewMode: localStorage.getItem('adc_view_mode') === 'double' ? 'double' : 'single',
        height: 680,
        placeholder: '在這裡編輯這份文件的內容…（可先按上方「從 Word 匯入」把舊內容帶進來）',
        assetUrl: assetUrl,
        assetKind: function(id){ return ASSET_KIND[String(id)] || 'image'; },
        onChange: function(){ setDirty(true); },
        onInsertImage: function(insert){ openUpload(insert); },
        onInsertFlow: function(insert){
            EGFlow.open({ json:null, onSave: function(png, json){
                saveFlow(0, png, json, function(id){
                    ASSET_KIND[String(id)] = 'flow';
                    insert(id, { width:'70%' });
                    setDirty(true);
                });
            }});
        },
        onEditFlow: function(assetId, done){
            $.getJSON(API, { action:'get_flow', id:assetId, version_id:VID }, function(r){
                if (!r.success) { alert(r.message || '讀不到這張流程圖的編輯資料'); return; }
                EGFlow.open({ json:r.flow_json, onSave: function(png, json){
                    saveFlow(assetId, png, json, function(){ done(); });
                }});
            });
        },
        onCropImage: function(assetId, done){ openCrop(assetId, done); }
    });
}

function saveFlow(assetId, png, json, cb) {
    $.post(API, { action:'save_flow', csrf:CSRF, version_id:VID, asset_id:assetId, png:png, flow_json:json },
      function(r){
        if (!r.success) { alert(r.message || '流程圖存檔失敗'); return; }
        setDirty(true);
        if (cb) cb(r.id);
      }, 'json');
}

/* ── 插入圖片 ─────────────────────────────────────────────────────────── */
var UP_INSERT = null;
function openUpload(insert) {
    UP_INSERT = insert;
    $('#upFile').val('');
    $('#upMsg').text('');
    openMask('mUpload');
}
$('#btnUpDo').on('click', function(){
    // 送出時直讀 input.files（不靠 change 事件——使用者環境的 change 會被吞掉）
    var f = document.getElementById('upFile').files;
    if (!f || !f.length) { $('#upMsg').text('請先選一個圖片檔'); return; }
    var fd = new FormData();
    fd.append('action','upload_image'); fd.append('csrf',CSRF);
    fd.append('version_id',VID); fd.append('file', f[0]);
    var $b = $(this).prop('disabled', true);
    $.ajax({ url:API, type:'POST', data:fd, processData:false, contentType:false, dataType:'json' })
      .done(function(r){
        if (!r.success) { $('#upMsg').text(r.message || '上傳失敗'); return; }
        ASSET_KIND[String(r.id)] = 'image';
        closeMask('mUpload');
        if (UP_INSERT) UP_INSERT(r.id, { width:'60%' });
        setDirty(true);
      })
      .always(function(){ $b.prop('disabled', false); });
});

/* ── 裁切 ─────────────────────────────────────────────────────────────── */
var CROP = { id:0, done:null };
function openCrop(assetId, done) {
    CROP = { id:assetId, done:done };
    var im = document.getElementById('cropImg');
    im.onload = function(){
        // 預設框＝整張的 80%，置中（一打開就看得到框在哪）
        var w = im.clientWidth, h = im.clientHeight;
        setBox(w*0.1, h*0.1, w*0.8, h*0.8);
    };
    im.src = assetUrl(assetId) + '&_t=' + Date.now();
    openMask('mCrop');
}
function setBox(l, t, w, h) {
    var im = document.getElementById('cropImg');
    var mw = im.clientWidth, mh = im.clientHeight;
    w = Math.max(16, Math.min(w, mw)); h = Math.max(16, Math.min(h, mh));
    l = Math.max(0, Math.min(l, mw - w)); t = Math.max(0, Math.min(t, mh - h));
    $('#cropBox').css({ left:l+'px', top:t+'px', width:w+'px', height:h+'px' });
}
(function bindCrop(){
    var box = document.getElementById('cropBox');
    var mode = null, sx = 0, sy = 0, o = null;
    box.addEventListener('mousedown', function(e){
        var cl = e.target.classList;
        mode = cl.contains('tl') ? 'tl' : (cl.contains('br') ? 'br' : 'move');
        sx = e.clientX; sy = e.clientY;
        o = { l:box.offsetLeft, t:box.offsetTop, w:box.offsetWidth, h:box.offsetHeight };
        e.preventDefault(); e.stopPropagation();
    });
    document.addEventListener('mousemove', function(e){
        if (!mode) return;
        var dx = e.clientX - sx, dy = e.clientY - sy;
        if (mode === 'move')      setBox(o.l+dx, o.t+dy, o.w, o.h);
        else if (mode === 'br')   setBox(o.l, o.t, o.w+dx, o.h+dy);
        else                      setBox(o.l+dx, o.t+dy, o.w-dx, o.h-dy);
    });
    document.addEventListener('mouseup', function(){ mode = null; });
})();
$('#btnCropDo').on('click', function(){
    var im = document.getElementById('cropImg'), box = document.getElementById('cropBox');
    var mw = im.clientWidth, mh = im.clientHeight;
    if (!mw || !mh) { alert('圖片還沒載入完成'); return; }
    // 存的是 0~1 比例，不是像素——這樣換了顯示尺寸或改版重裁都還對得上
    $.post(API, { action:'crop', csrf:CSRF, version_id:VID, id:CROP.id,
                  x:(box.offsetLeft/mw), y:(box.offsetTop/mh),
                  w:(box.offsetWidth/mw), h:(box.offsetHeight/mh) }, function(r){
        if (!r.success) { alert(r.message || '裁切失敗'); return; }
        closeMask('mCrop');
        if (CROP.done) CROP.done();
    }, 'json');
});
$('#btnCropReset').on('click', function(){
    $.post(API, { action:'crop', csrf:CSRF, version_id:VID, id:CROP.id, reset:1 }, function(r){
        if (!r.success) { alert(r.message || '還原失敗'); return; }
        closeMask('mCrop');
        if (CROP.done) CROP.done();
    }, 'json');
});

/* ── 存檔 ─────────────────────────────────────────────────────────────── */
function save(cb) {
    if (!CAN_EDIT || !ED) return;
    var $b = $('#btnSave').prop('disabled', true);
    $.post(API, {
        action:'save', csrf:CSRF, version_id:VID,
        html: ED.get(),
        is_primary: $('#chkPrimary').is(':checked') ? 1 : 0,
        page_size: $('#selPage').val(),
        orientation: $('#selOrient').val()
    }, function(r){
        $b.prop('disabled', false);
        if (!r.success) { alert(r.message || '存檔失敗'); return; }
        setDirty(false);
        $('#adSaved').text('已存檔 ' + new Date().toLocaleTimeString('zh-TW', {hour:'2-digit',minute:'2-digit'}));
        paintPrimaryBadge({ html: ED.get(), is_primary: $('#chkPrimary').is(':checked') ? 1 : 0 });
        if (cb) cb();
    }, 'json').fail(function(){ $b.prop('disabled', false); });
}
$('#btnSave').on('click', function(){ save(); });
$('#chkPrimary').on('change', function(){ setDirty(true); });
// 改紙張／方向要立刻反映在編輯區（不然編出來的跟印出來的不一樣）
$('#selPage,#selOrient').on('change', function(){
    setDirty(true);
    if (ED && ED.setPaper) ED.setPaper($('#selPage').val(), $('#selOrient').val());
});
$(document).on('keydown', function(e){
    if ((e.ctrlKey || e.metaKey) && String(e.key).toLowerCase() === 's') { e.preventDefault(); save(); }
});
window.addEventListener('beforeunload', function(e){
    if (!DIRTY) return;
    e.preventDefault(); e.returnValue = '';
    return '';
});

/* ── 從 Word 匯入 ─────────────────────────────────────────────────────── */
$('#btnImport').on('click', function(){
    var hasContent = ED && ED.text().trim() !== '';
    var msg = '要用這個版次掛的 Word 原始檔重新產生線上內容嗎？\n\n'
            + '・文字與表格會轉進來\n'
            + '・Word 的流程圖（繪圖物件）轉不進來，會列在清單上請你用「插入流程圖」重畫\n'
            + '・轉檔約需 10 秒';
    if (hasContent) msg += '\n\n⚠ 目前已經有內容了，匯入會「整份覆蓋」現在編輯中的內容。';
    if (!confirm(msg)) return;
    var $b = $(this).prop('disabled', true).html('<i class="fa fa-spinner fa-spin"></i> 轉檔中…');
    $.post(API, { action:'import_word', csrf:CSRF, version_id:VID, overwrite: hasContent ? 1 : 0 },
      function(r){
        $b.prop('disabled', false).html('<i class="fa fa-file-word-o"></i> 從 Word 匯入');
        if (!r.success) { alert(r.message || '匯入失敗'); return; }
        setDirty(false);
        load();
        alert('匯入完成。請看上方的清單確認還有哪些要人工補（尤其流程圖）。');
      }, 'json')
      .fail(function(){ $b.prop('disabled', false).html('<i class="fa fa-file-word-o"></i> 從 Word 匯入'); });
});

/* ── 從其他版次複製 ───────────────────────────────────────────────────── */
$('#btnFork').on('click', function(){
    var opts = VERSIONS.filter(function(v){ return String(v.id) !== String(VID) && Number(v.has_online) > 0; });
    if (!opts.length) { alert('這份文件的其他版次都還沒有線上內容，沒有東西可以複製。'); return; }
    $('#selFork').html(opts.map(function(v){
        return '<option value="' + v.id + '">' + esc(v.version) + '　修訂日 ' + esc(v.revised_date || '—') + '</option>';
    }).join(''));
    openMask('mFork');
});
$('#btnForkDo').on('click', function(){
    $.post(API, { action:'fork', csrf:CSRF, version_id:VID, from_version_id:$('#selFork').val() }, function(r){
        if (!r.success) { alert(r.message || '複製失敗'); return; }
        closeMask('mFork');
        alert(r.message);
        load();
    }, 'json');
});

/* ── 列印 ─────────────────────────────────────────────────────────────── */
$('#btnPrint').on('click', function(){
    if (DIRTY) {
        if (!confirm('有未存檔的變更，列印預覽看到的會是「上次存檔」的內容。\n要先存檔再列印嗎？\n\n按「確定」＝先存檔再列印；按「取消」＝直接看上次存檔的內容。')) {
            window.open('as_doc_print.php?version_id=' + VID, '_blank');
            return;
        }
        save(function(){ window.open('as_doc_print.php?version_id=' + VID, '_blank'); });
        return;
    }
    window.open('as_doc_print.php?version_id=' + VID, '_blank');
});

load();
</script>
<?php endif; ?>
</body>
</html>
