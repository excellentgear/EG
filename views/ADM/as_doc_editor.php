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
        /* 存檔按鈕＋「上次存檔」時間分兩行（使用者 2026-09-24 交辦：時間要顯示在
           按鈕下方，不要跟按鈕擠在同一列） */
        .ad-savebox { display:flex; flex-direction:column; align-items:flex-start; gap:2px; }
        .ad-save-sub { font-size:11.5px; line-height:1.4; display:flex; align-items:center; gap:6px; }
        .ad-dirty { color:#DD5138; font-size:12px; font-weight:bold; display:none; }
        .ad-dirty.on { display:inline; }
        .ad-saved { color:#7A9A4A; font-size:12px; }

        /* 未轉換清單：匯入之後最重要的東西，所以用強調色而且預設展開 */
        .ad-report { border:1px solid #e4b77a; background:#fdf6ea; border-radius:5px; padding:9px 12px; margin-bottom:10px; }
        /* ── 內文引用的文件編號：提示條與確認跳窗（暖色系，ai-rules/10）── */
        .ad-ref { border:1px solid #F0A24B; background:#FCEFD9; border-radius:5px; padding:9px 12px; margin-bottom:10px; }
        .ad-ref-t { color:#8a5a12; font-size:14px; line-height:1.6; }
        .ad-ref-t b { margin:0 4px; }
        .ad-ref-b { margin-top:6px; display:flex; align-items:center; gap:10px; flex-wrap:wrap; }
        .ad-ref-note { font-size:12px; color:#8a6d45; line-height:1.6; }
        .adr-grid { display:flex; gap:10px; align-items:stretch; }
        .adr-left { width:360px; flex:0 0 360px; display:flex; flex-direction:column; }
        .adr-right { flex:1 1 auto; min-width:0; display:flex; flex-direction:column; }
        .adr-hd { font-size:12px; color:#6B471A; background:#F6EBD9; border:1px solid #E6CFA6;
                  border-bottom:0; border-radius:4px 4px 0 0; padding:5px 8px;
                  display:flex; align-items:center; gap:8px; }
        .adr-hd-n { margin-left:auto; color:#8a6d45; }
        .adr-list, .adr-prev { border:1px solid #E6CFA6; border-radius:0 0 4px 4px; background:#fff;
                               overflow:auto; height:420px; }
        .adr-list { padding:4px; }
        .adr-it { border:1px solid #EADCC4; border-radius:4px; padding:6px 8px; margin-bottom:5px; }
        .adr-it.on { border-color:#F0A24B; background:#FFF9F0; }
        .adr-it-h { font-size:13px; color:#4A3524; line-height:1.6; }
        .adr-it-h input { margin-right:5px; }
        .adr-it-s { font-size:11px; color:#8a6d45; line-height:1.6; margin-top:3px; }
        .adr-hit { font-size:11px; color:#5b4a33; line-height:1.6; border-top:1px dashed #EADCC4;
                   padding-top:3px; margin-top:3px; }
        .adr-hit b { color:#8a5a12; }
        .adr-prev { padding:10px 14px; }
        .adr-apply { margin-top:8px; display:flex; align-items:center; gap:6px; flex-wrap:wrap; }
        .adr-will { font-size:12px; color:#8a6d45; line-height:1.6; width:100%; }
        /* 標示樣式：預覽用，也給列表裡的小字共用 */
        .adr-old, del.adr-old { color:#A5502E; text-decoration:line-through; background:#FBE6DE; padding:0 2px; }
        .adr-new, ins.adr-new { color:#3E6B2E; text-decoration:none; background:#E4F2D9; padding:0 2px; font-weight:bold; }
        .adr-warn, mark.adr-warn { background:#FCE9A8; color:#7a5a12; padding:0 2px; }
        .ad-report h4 { margin:0 0 6px; font-size:14px; color:#8A5A2B; }
        .ad-report ul { margin:0 0 4px 0; padding-left:20px; }
        .ad-report li { font-size:12.5px; line-height:1.75; color:#5a4326; }
        .ad-report li.must { color:#A34E2A; }
        .ad-report li.must b { color:#DD5138; }
        .ad-report .ad-rp-x { float:right; cursor:pointer; color:#b08a57; }
        /* 「已經轉進來的」與一般說明改成一排小標籤，不要每一項各自一整行
           （使用者 2026-09-24 回報：這一塊內容太多很混亂） */
        .ad-rp-chips { display:flex; flex-wrap:wrap; gap:6px; margin-top:2px; }
        .ad-rp-chip { display:inline-flex; align-items:center; gap:4px; font-size:11.5px; line-height:20px;
            padding:0 8px; border-radius:10px; white-space:nowrap; }
        .ad-rp-chip.ok   { background:#E4F2D9; color:#3E6B2E; }
        .ad-rp-chip.info { background:#F2EDE2; color:#7a6a52; }

        .ad-noperm { border:1px solid #e4d3ba; background:#faf6f0; border-radius:5px; padding:26px; text-align:center; color:#6B471A; }

        /* 回頂端（編輯區塊右下角，內容一多捲下去就看不到上方工具列，比照
           td_dev_eval.php 既有寫法，使用者 2026-09-24 交辦） */
        .ad-back-top { position:fixed; right:22px; bottom:22px; width:38px; height:38px; border-radius:50%;
            background:#F0A24B; color:#fff; text-align:center; line-height:38px; font-size:16px;
            box-shadow:0 2px 8px rgba(0,0,0,.25); cursor:pointer; display:none; z-index:120; }
        .ad-back-top:hover { background:#d98a33; }
        .ad-back-top.show { display:block; }

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

        .ad-sign-box { display:inline-flex; align-items:center; gap:5px; padding:0 8px; margin:0 2px;
            border-left:1px solid #e4d3ba; border-right:1px solid #e4d3ba; }
        .ad-badge.sign-wait { background:#F0A24B; color:#fff; }
        .ad-badge.sign-ok   { background:#7A9A4A; color:#fff; }
        .ad-badge.sign-no   { background:#DD5138; color:#fff; }
        .ad-badge.sign-rel  { background:#8A5A2B; color:#fff; }
        .sg-step { border:1px solid #e4d3ba; border-radius:4px; padding:7px 10px; margin-bottom:6px;
            display:flex; align-items:center; gap:10px; font-size:13px; }
        .sg-step.on   { border-color:#F0A24B; background:#FFF9F0; }
        .sg-step.done { background:#F6F8F2; }
        .sg-step .sg-s { width:62px; color:#8A5A2B; font-weight:bold; }
        .sg-step .sg-p { flex:1 1 auto; color:#4E2C0B; }
        .sg-step .sg-t { color:#8A5A2B; font-size:12px; }
        .sg-note { font-size:12px; color:#8A5A2B; margin-top:2px; }
        .sg-pick { border:1px solid #e4d3ba; border-radius:4px; padding:8px 10px; margin-bottom:7px; }
        .sg-pick .sg-h { font-size:13px; color:#4E2C0B; font-weight:bold; margin-bottom:4px; }
        .sg-pick .sg-f { font-size:12px; color:#8A5A2B; }

        /* 固定標題範本設定（2026-09-24 新增） */
        .ht-lvl { margin:8px 0 10px; font-size:13px; color:#6B471A; }
        .ht-cols { display:flex; gap:14px; }
        .ht-col { flex:1 1 50%; min-width:0; }
        .ht-col-h { font-size:13px; font-weight:bold; color:#6B471A; margin-bottom:5px; }
        .ht-hint { font-size:11.5px; color:#8A5A2B; line-height:1.6; margin-bottom:6px; }
        .ht-tree { border:1px solid #e4d3ba; border-radius:4px; padding:6px; min-height:80px;
            max-height:420px; overflow:auto; margin-bottom:7px; background:#fff; }
        .ht-top { border:1px solid #EADCC4; border-radius:4px; padding:6px 7px; margin-bottom:6px; background:#FFF9F0; }
        .ht-top-row { display:flex; align-items:center; gap:5px; }
        .ht-top-row input { flex:1 1 auto; }
        .ht-sub-list { margin:6px 0 4px 20px; }
        .ht-sub-row { display:flex; align-items:center; gap:5px; margin-bottom:4px; }
        .ht-sub-row input { flex:1 1 auto; }
        .ht-sub-row:before { content:"—"; color:#b08a57; font-size:11px; }
        .ht-mini { border:1px solid #d8c7b0; background:#faf6f0; color:#6B471A; font-size:11px;
            line-height:20px; height:22px; width:22px; padding:0; border-radius:3px; text-align:center; }
        .ht-mini:hover { background:#f2e6d4; }
        .ht-mini.danger { color:#A34E2A; }
        .ht-addsub { font-size:11.5px; padding:0 6px; height:22px; line-height:20px; margin-top:2px; }
        .ht-doclist { border:1px solid #e4d3ba; border-radius:4px; max-height:120px; overflow:auto;
            background:#fff; margin:5px 0 8px; }
        .ht-doc-it { padding:5px 8px; font-size:12.5px; color:#4A3524; border-bottom:1px solid #f2ede2; cursor:pointer; }
        .ht-doc-it:hover { background:#FFF9F0; }
        .ht-doc-it:last-child { border-bottom:0; }
        .ht-doc-it b { color:#8A5A2B; }
        .ht-harvest { border:1px solid #e4d3ba; border-radius:4px; max-height:260px; overflow:auto;
            background:#fff; padding:4px; margin-bottom:7px; }
        .ht-hti { display:flex; align-items:flex-start; gap:6px; padding:3px 4px; font-size:12.5px; color:#4A3524; }
        .ht-hti.h2 { margin-left:20px; color:#7a6a52; }
        .ht-hti input[type=checkbox] { margin-top:3px; }

        .tpl-row { margin-bottom:13px; }
        .tpl-row > label:first-child { display:block; font-size:13px; color:#6B471A; font-weight:bold; margin-bottom:3px; }
        .tpl-hint { font-size:12px; color:#8A5A2B; line-height:1.6; margin-top:3px; }
        .tpl-sub { display:block; font-size:12px; color:#6B471A; margin-bottom:2px; }
        /* 公版設定的工具列：長得跟文書軟體一樣，選了就在下面即時看到 */
        .st-bar { background:#fff; border:1px solid #e4d3ba; border-radius:4px; padding:5px 6px;
            display:flex; flex-wrap:wrap; align-items:center; gap:3px; margin-bottom:7px; }
        .st-lab { font-size:12px; color:#8A5A2B; margin:0 2px 0 6px; }
        .st-sel { height:26px; border:1px solid #d8c7b0; border-radius:3px; background:#fff;
            color:#4E2C0B; font-size:12px; max-width:110px; }
        .st-btn { border:1px solid #e4d3ba; background:#faf6f0; color:#6B471A; font-size:12px;
            line-height:24px; height:26px; padding:0 9px; border-radius:3px; }
        .st-btn:hover { background:#f2e6d4; }
        .st-btn.on { background:#e8d5b8; border-color:#8A5A2B; font-weight:bold; }
        .st-sp { display:inline-block; width:1px; height:18px; background:#e4d3ba; margin:0 5px; }
        .st-prev-wrap { margin-top:8px; }
        /* 預覽用「一張紙的一角」呈現，所見即所得 */
        .st-prev { background:#fff; border:1px solid #d8c7b0; padding:10px 12px; border-radius:3px; }
        .st-prev .st-body { font-size:11pt; line-height:1.5; }
        .st-prev .st-body p { margin:0 0 4px; }
        .st-prev table.adt-hdr { margin-bottom:2mm; }
        .tpl-row.tpl-l1 { display:none; }        /* 只有一階文件才顯示，由 JS 打開 */

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
            <span class="ad-kv">類別 <b id="adKind">—</b></span>
            <span class="ad-kv" title="印在文件制修訂紀錄書上的「發行單位」">發行單位 <b id="adIssueDept">—</b></span>
            <span id="adPrimaryBadge" class="ad-badge none">尚未建立線上版</span>
            <?php if ((int)$V['current_version_id'] !== (int)$V['version_id']): ?>
              <span class="ad-badge draft" title="這不是目前生效的版次，是歷史版本">歷史版本</span>
            <?php endif; ?>

            <!-- 送審狀態放在「線上版草稿／正本」徽章右側（使用者 2026-09-24 交辦），
                 跟工具列的動作按鈕分開，一眼就看得到目前簽到哪一關 -->
            <span class="ad-sign-box">
              <span class="ad-badge none" id="adSignBadge">尚未送簽</span>
              <?php if ($P['edit']): ?>
                <button class="btn btn-default btn-sm" id="btnSignSubmit" title="送出制修訂／審查／核准的簽核">
                  <i class="fa fa-paper-plane-o"></i> 送審</button>
                <button class="btn btn-default btn-sm" id="btnSignCancel" style="display:none">取消送簽</button>
              <?php endif; ?>
              <button class="btn btn-warning btn-sm" id="btnSignDo" style="display:none">
                <i class="fa fa-check"></i> 換我簽核</button>
              <?php if ($P['edit']): ?>
                <button class="btn btn-success btn-sm" id="btnRelease" style="display:none">
                  <i class="fa fa-certificate"></i> 正式發行</button>
              <?php endif; ?>
              <button class="btn btn-link btn-sm" id="btnSignInfo" style="padding:4px 6px" title="看簽核進度">
                <i class="fa fa-list-ul"></i> 簽核進度</button>
              <?php if ($P['admin']): ?>
                <button class="btn btn-link btn-sm" id="btnSignCfg" style="padding:4px 6px" title="設定制修訂／審查／核准由誰簽、順序、要不要自動簽核">
                  <i class="fa fa-cog"></i> 簽核設定</button>
              <?php endif; ?>
            </span>

            <span style="margin-left:auto">
              <a class="btn btn-default btn-sm" href="as_document_management.php"><i class="fa fa-arrow-left"></i> 回 AS 文件管理</a>
            </span>
        </div>

        <div class="ad-bar">
          <?php if ($P['edit']): ?>
            <span class="ad-savebox">
              <button class="btn btn-warning btn-sm" id="btnSave"><i class="fa fa-save"></i> 存檔 <small>(Ctrl+S)</small></button>
              <span class="ad-save-sub">
                <span class="ad-dirty" id="adDirty"><i class="fa fa-exclamation-circle"></i> 有未存檔的變更</span>
                <span id="adLayout" style="display:none;font-size:12px;color:#8A5A2B">
                  <i class="fa fa-spinner fa-spin"></i> 排版中…</span>
                <span class="ad-saved" id="adSaved"></span>
              </span>
            </span>
            <span style="width:10px"></span>
            <button class="btn btn-default btn-sm" id="btnImport" title="用 LibreOffice 把這個版次掛的 Word 原始檔轉成線上內容">
              <i class="fa fa-file-word-o"></i> 從 Word 匯入</button>
            <button class="btn btn-default btn-sm" id="btnFork" title="把同一份文件其他版次的線上內容複製過來（含圖片與流程圖）">
              <i class="fa fa-copy"></i> 從其他版次複製</button>
            <button class="btn btn-default btn-sm" id="btnTpl" title="設定發行單位、頁尾左下文字、一階封面英文與目錄">
              <i class="fa fa-sliders"></i> 版面設定</button>
            <?php if ($P['admin']): ?>
              <button class="btn btn-default btn-sm" id="btnHeadTpl" title="設定新建文件時自動帶入的固定大標題／小標題">
                <i class="fa fa-list-alt"></i> 標題範本設定</button>
            <?php endif; ?>
            <span style="width:10px"></span>
          <?php endif; ?>

          <?php if ($P['edit']): ?>
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

        <!-- 別份文件改了編號或被廢止，而這份線上版的內文還寫著舊編號（2026-09-22 使用者交辦）。
             預設就展開，不給收起來——收起來等於沒做，印出去的還是指向不存在的編號。 -->
        <div class="ad-ref" id="adRefBar" style="display:none">
          <div class="ad-ref-t"><i class="fa fa-exclamation-triangle"></i>
            <b>內文引用的文件編號需要確認</b>
            <span id="adRefSum"></span>
          </div>
          <div class="ad-ref-b">
            <button class="btn btn-warning btn-sm" id="btnRefOpen"><i class="fa fa-search"></i> 檢視並確認</button>
            <span class="ad-ref-note">確認之後會<b>自動補一列制修訂紀錄</b>（版別
              <span id="adRefNextVer">—</span>、修訂日期＝來源異動日、頁次＝實際改到的頁）。</span>
          </div>
        </div>

        <div class="ad-report" id="adReport" style="display:none">
          <span class="ad-rp-x" id="adReportX" title="收起">&times;</span>
          <h4><i class="fa fa-info-circle"></i> 這份文件從 Word 匯入的結果</h4>
          <div id="adReportBody"></div>
        </div>

        <div id="adEditorHost"></div>
        <div id="adReadonly" style="display:none" class="egrt-wrap"></div>
        <div class="ad-back-top" id="btnBackTop" title="回到頂端"><i class="fa fa-arrow-up"></i></div>
<?php endif; ?>
    </div>
</div>
</div>

<!-- 內文引用的文件編號：確認與套用（2026-09-22 使用者交辦） -->
<div class="m-mask" id="mRef"><div class="m-win" style="width:1000px;max-width:96vw;">
  <div class="m-head">內文引用的文件編號 — 確認後才會改
    <span class="m-x" data-close="mRef">&times;</span></div>
  <div class="m-body">
    <div class="m-note">
      別的文件改了編號或被廢止，而這份文件的內文還寫著舊的。<br>
      <b>編號變更</b>系統已經算好要換成什麼，下方預覽裡<span class="adr-old">舊編號會畫刪除線</span>、<span class="adr-new">新編號是綠底</span>；<br>
      <b>廢止</b>沒有新編號可以換，只會用<span class="adr-warn">黃底標出來</span>，<b>系統不會自己改</b>——要改指到別份文件還是整段刪掉，請自行編輯。<br>
      勾選要套用的項目後按「套用」，系統會改內文並<b>在文件制修訂紀錄書補一列</b>。
    </div>
    <div class="adr-grid">
      <div class="adr-left">
        <div class="adr-hd">
          <label style="font-weight:normal;margin:0;"><input type="checkbox" id="refAll"> 全選</label>
          <span class="adr-hd-n" id="refCnt"></span>
        </div>
        <div id="refList" class="adr-list"></div>
        <div class="adr-apply">
          <div class="adr-will" id="refWill"></div>
          <button class="btn btn-warning btn-sm" id="btnRefApply"><i class="fa fa-check"></i> 套用並補制修訂紀錄</button>
          <button class="btn btn-default btn-sm" id="btnRefSkip" title="確認過這幾處不需要改；會留下誰在什麼時候略過的紀錄">
            這幾處不用改</button>
        </div>
      </div>
      <div class="adr-right">
        <div class="adr-hd">預覽（<b>只是預覽</b>，這些標示不會存進文件）
          <span class="adr-hd-n" id="refMarks"></span></div>
        <div id="refPrev" class="adr-prev eg-docbody"></div>
      </div>
    </div>
  </div>
  <div class="m-foot"><button class="btn btn-default btn-sm" data-close="mRef">關閉</button></div>
</div></div>

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

<!-- 固定標題範本設定（管理員）：新建文件沒有舊版可複製時自動帶入 -->
<div class="m-mask" id="mHeadTpl"><div class="m-win wide">
  <div class="m-head">固定標題範本設定 <span class="m-x" data-close="mHeadTpl">&times;</span></div>
  <div class="m-body">
    <div class="m-note">
      這裡設定的大標題／小標題，會在<b>某一階文件第一次建立、完全沒有任何舊版內容可以複製</b>時
      自動帶進編輯器（帶入後仍是草稿，要按「存檔」才會真的存進去，不喜歡也可以直接刪改）。<br>
      每一階（一階／二階／三階／四階）各自一份範本。小標題掛在大標題底下，
      <b>選了某個小標題要帶入，上面那個大標題也會自動一起帶入</b>（沒有大標題的小標題沒有意義）。
    </div>
    <div class="ht-lvl">
      套用階別：
      <select id="htLevel" class="form-control input-sm" style="width:auto;display:inline-block"></select>
    </div>
    <div class="ht-cols">
      <div class="ht-col">
        <div class="ht-col-h">目前範本（<span id="htCount">0</span> 個大標題）</div>
        <div id="htTree" class="ht-tree"></div>
        <button class="btn btn-default btn-sm" id="htAddTop"><i class="fa fa-plus"></i> 新增大標題</button>
      </div>
      <div class="ht-col">
        <div class="ht-col-h">從既有文件挑選標題</div>
        <div class="ht-hint">只認編輯器裡真正用「標題1／標題2」格式標記過的段落——
          文字比對用猜的容易把整段內文誤判成標題，所以刻意不猜。
          要挑的文件如果還沒有標題格式，請先進去那份文件的編輯器，
          把要當範本的那幾行用工具列的「段落階層」改成「標題1」或「標題2」再存檔。</div>
        <input type="text" id="htDocKw" class="form-control input-sm" placeholder="輸入文件編號或名稱搜尋…">
        <div id="htDocList" class="ht-doclist"></div>
        <div id="htHarvestBox" style="display:none">
          <div class="ht-col-h" style="margin-top:8px">挑選要帶入的段落</div>
          <div id="htHarvestList" class="ht-harvest"></div>
          <button class="btn btn-warning btn-sm" id="htHarvestApply"><i class="fa fa-arrow-left"></i> 加入左邊的範本</button>
        </div>
      </div>
    </div>
  </div>
  <div class="m-foot">
    <button class="btn btn-default btn-sm" data-close="mHeadTpl">關閉</button>
    <button class="btn btn-warning btn-sm" id="htSave"><i class="fa fa-save"></i> 儲存這一階的範本</button>
  </div>
</div></div>

<!-- 送審：挑各關卡的簽核人 -->
<div class="m-mask" id="mSign"><div class="m-win">
  <div class="m-head">送出簽核 <span class="m-x" data-close="mSign">&times;</span></div>
  <div class="m-body">
    <div class="m-note">
      關卡與順序是<b>管理員設定</b>的，這裡只挑人。<br>
      候選名單是<b>送審當下在職</b>的人——設定存的是「部門＋職稱」不是某個人，
      所以有人離職或調部門也不必回頭改設定。<br>
      標「自動簽核」的關卡送出當下就會簽掉，並留下簽核紀錄。
    </div>
    <div id="signPickBody"></div>
  </div>
  <div class="m-foot">
    <span id="signMsg" style="float:left;color:#8A5A2B;font-size:12px;line-height:28px"></span>
    <button class="btn btn-default btn-sm" data-close="mSign">取消</button>
    <button class="btn btn-warning btn-sm" id="btnSignGo"><i class="fa fa-paper-plane-o"></i> 送出</button>
  </div>
</div></div>

<!-- 簽核進度／簽核與退回 -->
<div class="m-mask" id="mSignInfo"><div class="m-win wide">
  <div class="m-head">簽核進度 <span class="m-x" data-close="mSignInfo">&times;</span></div>
  <div class="m-body">
    <div id="signInfoBody"></div>
    <div id="signDoBox" style="display:none;margin-top:10px;border-top:1px dashed #e4d3ba;padding-top:10px">
      <div style="font-size:13px;color:#4E2C0B;font-weight:bold;margin-bottom:4px">輪到你簽這一關</div>
      <textarea id="signNote" class="form-control" rows="2"
                data-eg-hint="同意可以不填；退回一定要填原因"></textarea>
      <div style="margin-top:7px;text-align:right">
        <button class="btn btn-danger btn-sm" id="btnSignReject">退回</button>
        <button class="btn btn-warning btn-sm" id="btnSignOk"><i class="fa fa-check"></i> 簽核</button>
      </div>
    </div>
  </div>
  <div class="m-foot"><button class="btn btn-default btn-sm" data-close="mSignInfo">關閉</button></div>
</div></div>

<?php if ($P['admin']): ?>
<!-- 簽核設定（管理員） -->
<div class="m-mask" id="mSignCfg"><div class="m-win wide">
  <div class="m-head">簽核設定 <span class="m-x" data-close="mSignCfg">&times;</span></div>
  <div class="m-body">
    <div class="m-note">
      設定<b>制修訂／審查／核准</b>三關由誰簽、<b>順序</b>，以及要不要<b>自動簽核</b>。<br>
      簽核人一律設<b>部門＋職稱</b>不要指定到某一個人——人員異動、離職、調部門都不必回來改，
      系統會在<b>送審當下</b>解析「現在在職的是誰」讓建立者挑。<br>
      制修訂那一關預設是<b>內容的最後修改人</b>（表單修改人），要改成別人也可以。
    </div>
    <div style="margin-bottom:8px">
      <label style="font-size:13px;color:#6B471A;margin:0 8px 0 0">套用範圍</label>
      <select id="cfgScope" class="form-control input-sm" style="width:auto;display:inline-block" data-eg-skip>
        <option value="0">全站預設（所有文件共用）</option>
      </select>
      <span id="cfgScopeNote" style="font-size:12px;color:#8A5A2B;margin-left:8px"></span>
    </div>
    <table class="table table-bordered" id="cfgTbl" style="font-size:13px;margin-bottom:6px">
      <thead><tr>
        <th style="width:52px">順序</th><th style="width:96px">關卡</th><th style="width:190px">簽核人來源</th>
        <th>對象</th><th style="width:78px">自動簽核</th><th style="width:96px"></th>
      </tr></thead>
      <tbody></tbody>
    </table>
    <button class="btn btn-default btn-sm" id="btnCfgAdd"><i class="fa fa-plus"></i> 增加一關</button>
  </div>
  <div class="m-foot">
    <span id="cfgMsg" style="float:left;color:#8A5A2B;font-size:12px;line-height:28px"></span>
    <button class="btn btn-default btn-sm" data-close="mSignCfg">取消</button>
    <button class="btn btn-warning btn-sm" id="btnCfgSave"><i class="fa fa-save"></i> 存檔</button>
  </div>
</div></div>
<?php endif; ?>

<!-- 版面設定（發行單位／頁尾左下文字／一階封面英文／目錄） -->
<div class="m-mask" id="mTpl"><div class="m-win">
  <div class="m-head">版面設定 <span class="m-x" data-close="mTpl">&times;</span></div>
  <div class="m-body">
    <div class="m-note">
      這裡設定的是<b>整份文件</b>（<span id="tplDocNo"></span>）的版面，<b>所有版次共用</b>；
      內容本身仍然逐版次各自編輯。<br>
      封面、文件制修訂紀錄書、目錄、每一頁的頁首頁尾都是<b>系統自動產生</b>的，
      下面這幾項是少數沒辦法從資料推導、需要管理員指定的。
    </div>

    <div class="tpl-row">
      <label>發行單位</label>
      <select id="tplDept" class="form-control" data-eg-filter="輸入部門名稱篩選…"></select>
      <div class="tpl-hint">
        印在文件制修訂紀錄書下方的「發行單位」。
        留空＝用這份文件自己隸屬的部門（目前是 <b id="tplDeptFallback">—</b>）。
      </div>
    </div>

    <div class="tpl-row">
      <label>頁尾左下文字</label>
      <input type="text" id="tplFoot" class="form-control" maxlength="120"
             data-eg-hint="留空就會印預設的「(本文件不得擅自塗改或影印)」">
      <div class="tpl-hint">
        每一頁的左下角。留空＝印預設值 <b id="tplFootDefault"></b>。
        （右下角固定是 AS 文件編號，不可更改）
      </div>
    </div>

    <div class="tpl-row tpl-l1">
      <label>封面英文書名</label>
      <input type="text" id="tplCoverEn" class="form-control" maxlength="255"
             data-eg-hint="例：QUALITY MANUAL">
      <div class="tpl-hint">
        一階文件封面外框內、中文文件名稱下方的那一行英文。只有<b>一階（品質手冊）</b>會用到。
      </div>
    </div>

    <div class="tpl-row tpl-l1">
      <label>目錄頁</label>
      <label style="font-weight:normal;font-size:13px;color:#6B471A;margin:0;">
        <input type="checkbox" id="tplToc"> 自動產生目錄頁（排在制修訂紀錄書之後）
      </label>
      <div class="tpl-hint">目錄依正文裡的標題自動產生，不必自己維護。只有<b>一階</b>會用到。</div>
    </div>

    <?php if ($P['admin']): ?>
    <div style="border-top:1px dashed #e4d3ba;margin-top:14px;padding-top:12px">
      <div style="font-size:13px;color:#4E2C0B;font-weight:bold;margin-bottom:4px">
        <i class="fa fa-table"></i> 公版設定（表格外觀）</div>
      <div class="m-note" style="margin-top:0">
        這一組是<b>全站所有 AS 文件共用</b>的公版：改一次，<b>每一份文件的表格與框線一起變</b>
        （含制修訂紀錄書、頁首與正文裡的表格）。只有管理員看得到這一區。
      </div>
      <!-- 工具列：排版與文書軟體一樣，選了就在下面的預覽即時看到結果 -->
      <div class="st-bar">
        <span class="st-lab">字型</span>
        <select id="stFont" class="st-sel" style="max-width:150px" data-eg-skip></select>
        <span class="st-lab">字級</span>
        <select id="stSize" class="st-sel" data-eg-skip>
          <option value="">（跟著內文）</option>
          <option>9pt</option><option>9.5pt</option><option>10pt</option><option>10.5pt</option>
          <option>11pt</option><option>12pt</option><option>14pt</option>
        </select>
        <button type="button" class="st-btn" id="stBold" title="表格文字粗體"><b>B</b></button>
        <span class="st-sp"></span>
        <span class="st-lab">框線</span>
        <select id="stBStyle" class="st-sel" data-eg-skip></select>
        <select id="stBW" class="st-sel" data-eg-skip></select>
        <select id="stBColor" class="st-sel" data-eg-skip></select>
        <span class="st-sp"></span>
        <span class="st-lab">格內留白</span>
        <select id="stPad" class="st-sel" data-eg-skip>
          <option value="2px">窄</option><option value="4px">標準</option>
          <option value="6px">寬</option><option value="8px">很寬</option>
        </select>
        <span class="st-sp"></span>
        <button type="button" class="st-btn" id="stFrame" title="把正文整塊用外框框起來，框線與頁首同寬">
          <i class="fa fa-square-o"></i> 內容大框</button>
        <select id="stBodyPad" class="st-sel" data-eg-skip title="大框與文字之間留多少空白">
          <option value="2mm">2mm</option><option value="3mm">3mm</option>
          <option value="4mm">4mm</option><option value="5mm">5mm</option>
        </select>
      </div>
      <div class="tpl-hint">
        改完按下方「存檔」才會套用到所有文件；預設值與改版前完全相同，沒動過的文件外觀不會變。<br>
        <b>內容大框</b>打開後，正文會被一個外框框起來，<b>框線與上方頁首同寬、左右自然對齊</b>，
        匯入的內容直接放進這個大框裡。
      </div>
      <div class="st-prev-wrap">
        <div class="tpl-sub" style="margin-bottom:3px">預覽（就是文件上會長的樣子）</div>
        <div id="stPreview" class="st-prev">
          <table class="adt-hdr">
            <colgroup><col class="adt-c1"><col class="adt-c2"><col class="adt-c3"><col class="adt-c4"></colgroup>
            <tr><td class="adt-hdr-co" rowspan="2" colspan="2">
                  <div class="adt-hdr-coen">EXCELLENT GEAR TECHNOLOGY CO.,LTD</div>
                  <div class="adt-hdr-cozh">超正齒輪科技有限公司</div></td>
                <td class="adt-hdr-k">文件編號</td><td class="adt-hdr-v">2-DC-01</td></tr>
            <tr><td class="adt-hdr-k">頁　　次</td><td class="adt-hdr-v">1 / 12</td></tr>
            <tr><td class="adt-hdr-nk">文件名稱</td><td class="adt-hdr-nm">文件管理程序</td>
                <td class="adt-hdr-k">頁 版 別</td><td class="adt-hdr-v">2.0</td></tr>
          </table>
          <div class="eg-docbody st-body">
            <p>這裡是正文。打開「內容大框」之後，這一整塊會被框起來。</p>
            <table><tr><th>項目</th><th>說明</th></tr>
                   <tr><td>範例列</td><td>框線、字型與格內留白套用後的樣子</td></tr></table>
          </div>
        </div>
      </div>
    </div>
    <?php endif; ?>
  </div>
  <div class="m-foot">
    <span id="tplMsg" style="float:left;color:#8A5A2B;font-size:12px;line-height:28px"></span>
    <button class="btn btn-default btn-sm" data-close="mTpl">取消</button>
    <button class="btn btn-warning btn-sm" id="btnTplSave"><i class="fa fa-save"></i> 存檔</button>
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
      <li><b>補流程圖</b>：按「插入流程圖」。<span class="hl">左邊的圖形用拖的拉到畫布上</span>就好
          （點一下也可以），<span class="hl">雙擊圖形直接打字</span>；箭頭與直線則是在畫布上由起點拖到終點，
          畫好之後選起來，兩端的圓點可以各自再拉。
          不想從頭畫的話，左下角有<span class="hl">一鍵插入的範本</span>：
          <b>烏龜圖</b>（過程分析：輸入／輸出＋用什麼・用誰・如何做・做得如何四隻腳）、
          <b>基本流程</b>（開始→作業→判斷→結束，含「否」的回頭路）、<b>PDCA 循環</b>。
          插入之後每一個框都還可以改字、搬位置，不要的框選起來按 Delete。</li>
      <li><b>插入圖片</b>：按「插入圖片」上傳，之後點一下圖片可改寬度、對齊、裁切。</li>
      <li><b>在圖片上加文字</b>：點一下圖片（尤其是匯入時轉不動、改用圖片方式帶進來的流程圖／示意圖），
          按<span class="hl">「加文字」</span>——原圖會變成底圖鎖住，跟「插入流程圖」同一套工具疊文字方塊上去；
          存檔後這張圖就變成可再編輯的流程圖，之後改用「編輯流程圖」繼續調整文字位置，
          不必重新上傳底圖。</li>
      <li><b>存檔</b>（Ctrl+S）。確認整份沒問題之後再勾<span class="hl">「設為此版次的正本」</span>。</li>
    </ol>

    <h4>封面、制修訂紀錄書、目錄、頁首頁尾都不必自己打</h4>
    <p>這幾頁是<span class="hl">系統自動產生</span>的，你只要編正文：</p>
    <ul>
      <li><b>文件制修訂紀錄書</b>：公司中文全名取自客戶主檔裡標記為「本公司」的那一筆（發票用全名）、
          第二列是公司英文全名；左上放大置中顯示這份 AS 文件的名稱、右上是文件編號；
          文件類別依階別自動顯示（一階＝品質手冊(1)、二階＝程序書(2)）。
          底下的制修訂紀錄<span class="hl">逐列帶出這份文件的版次履歷</span>，
          制修訂部門自動帶入文件隸屬的部門（是「組」時會一併顯示上層，例：資材課 倉管組）。</li>
      <li><b>每一頁的頁首</b>（二階第二頁起）：公司英文＋中文、右上 AS 文件編號、頁次／總頁次自動編號。
          <b>頁版別</b>會依制修訂紀錄裡的「制修訂頁次」<span class="hl">自動跳版</span>——
          寫「全冊」就整份跟著跳，寫「4」或「4,5」或「4-6」就只有那幾頁跳。</li>
      <li><b>頁尾</b>：左下是固定字樣、右下固定是 AS 文件編號。</li>
      <li><b>一階文件</b>另外會自動產生封面頁與目錄頁。</li>
    </ul>
    <p>其中只有四項系統推導不出來，需要管理員在工具列的
       <span class="hl">「版面設定」</span>指定：<b>發行單位</b>（不指定就用文件自己的部門）、
       <b>頁尾左下文字</b>（不填就印「(本文件不得擅自塗改或影印)」）、
       <b>一階封面的英文書名</b>、<b>要不要自動產生目錄頁</b>。
       這四項是掛在「文件」上的，<span class="hl">所有版次共用</span>。</p>

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
<!-- 簽章：制修訂紀錄書上的制修訂／審查／核准三格（ai-rules/18 一律走共用圖章元件） -->
<script src="../../resource/js/eg_stamp.js?v=<?= @filemtime(__DIR__.'/../../resource/js/eg_stamp.js') ?>"></script>
<script src="../../resource/js/eg_doc_sign_stamp.js?v=<?= @filemtime(__DIR__.'/../../resource/js/eg_doc_sign_stamp.js') ?>"></script>
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
var CAN_ADMIN = <?= $P['admin'] ? 'true' : 'false' ?>;
var DOC_LEVEL = <?= json_encode((string)$V['doc_level'], JSON_UNESCAPED_UNICODE) ?>;
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

/* 回到頂端：內容一多捲下去就看不到上方工具列與存檔鈕（使用者 2026-09-24 交辦） */
$(window).on('scroll', function(){ $('#btnBackTop').toggleClass('show', $(window).scrollTop() > 300); });
$('#btnBackTop').on('click', function(){ $('html,body').animate({ scrollTop: 0 }, 200); });
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

/* 匯入完成後，下一次 load() 把內容排好就自動按一次「依編號自動縮排」（使用者要求）；
   只作用這一次就清掉，一般開頁面/複製版次不受影響 */
var AUTO_INDENT_AFTER_LOAD = false;

/* 初次載入與自動分頁跑完之前不給存檔（見 save() 的說明） */
var EDITOR_READY = false;
function setEditorReady(on) {
    EDITOR_READY = !!on;
    $('#btnSave').prop('disabled', !on)
                 .attr('title', on ? '' : '文件排版中，排版完成才可存檔');
    $('#adLayout').toggle(!on);
}

/** 等自動分頁穩定下來（頁數連續 3 次不變＝排完了），最多等 20 秒。
 *  cb：排版真的穩定、EDITOR_READY 變 true 之後才執行（例如匯入後要在這時候再做事，
 *  提早做的話量到的還是排版中途的頁數，縮排／存檔都會用到還沒定案的分頁）。 */
function waitLayout(cb) {
    var last = -1, same = 0, tries = 0;
    (function tick(){
        if (!ED) return;
        var n = ED.pageCount();
        if (n === last) same++; else { same = 0; last = n; }
        tries++;
        if (same >= 3 || tries > 60) { setEditorReady(true); if (cb) cb(); return; }
        setTimeout(tick, 330);
    })();
}

/* ── 載入 ─────────────────────────────────────────────────────────────── */
/** 把一段 HTML 塞進編輯器並接手後續（版面樣板／分頁等穩＋存檔按鈕開放）。
 *  seeded=true＝這段內容是自動帶入的固定標題骨架，不是資料庫存過的東西，
 *  所以標成「有未存檔的變更」、也不要顯示「上次存檔」（根本還沒存過）。 */
function applyEditorHtml(html, c, seeded) {
    if (!ED) ED = mkEditor();
    ED.set(html);
    loadChrome();              // 版面樣板（封面/制修訂紀錄書/目錄/頁首頁尾）
    setDirty(!!seeded);
    // 自動分頁是非同步的（字型載入、圖片載入、350ms 防抖動都會再排一次），
    // 所以等「頁數連續幾次都不再變」才算排版完成、才開放存檔
    if (AUTO_INDENT_AFTER_LOAD) {
        AUTO_INDENT_AFTER_LOAD = false;
        // 縮排要等分頁真的穩定下來才做（提早做的話，量到的頁數還在跑，
        // 縮排造成的換行又會再引發一次重排，兩件事疊在一起會亂套）；
        // 縮排完馬上存檔——不然畫面上看到的是縮排後的新分頁，
        // 資料庫裡存的、列印會印出來的卻還是縮排前的舊分頁，兩邊對不起來。
        waitLayout(function(){ ED.autoNumIndent(); save(); });
    } else {
        waitLayout();
    }
    if (!seeded && c && c.updated_at) $('#adSaved').text('上次存檔 ' + fmt(c.updated_at));
}

function load() {
    setEditorReady(false);
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
        loadSign();                    // 送簽狀態（沒有編輯權的人也要看得到簽到哪裡了）

        var html = (c && c.html) || '';
        if (CAN_EDIT) {
            // 這份文件目前完全沒有線上內容、也沒有其他版次可以複製（即「開立一份全新
            // 文件，底下沒有現成程序書」的情況），才自動帶入這一階的固定標題骨架——
            // 有內容或有得複製時絕不覆蓋，避免把已經打好的字洗掉（使用者 2026-09-24 交辦）。
            var canSeed = !html && DOC_LEVEL && !VERSIONS.some(function(v){
                return String(v.id) !== String(VID) && Number(v.has_online) > 0;
            });
            if (canSeed) {
                $.getJSON(API, { action:'heading_tpl_get', doc_level: DOC_LEVEL }, function(hr){
                    var sk = (hr && hr.success && hr.skeleton_html) || '';
                    applyEditorHtml(sk, c, !!sk);
                    if (sk) alert('這份文件目前還沒有任何內容，已自動帶入「' + DOC_LEVEL
                        + '」設定好的固定標題（' + (hr.count || 0) + ' 個大標題），請直接在裡面填寫內容；\n'
                        + '不需要的標題可以直接刪掉。記得填完要按「存檔」才會真的存進去。');
                }).fail(function(){ applyEditorHtml(html, c, false); });
            } else {
                applyEditorHtml(html, c, false);
            }
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
            // 封面／制修訂紀錄書／目錄與逐頁頁首頁尾一樣要顯示給唯讀的人看——
            // **被指派的簽核人多半就沒有編輯權**，看不到制修訂紀錄書就等於不知道自己在簽什麼。
            // 版面一律用後端那一份（action=tpl），不在這裡自己組第二套。
            $.getJSON(API, { action:'tpl', version_id: VID }, function(t){
                var sys = (t && t.success && t.sys) ? t.sys : [];
                var hdrTpl = (t && t.hdr_tpl) || '', ftr = (t && t.ftr) || '';
                var pv = (t && t.page_vers) || [], dv = (t && t.doc_ver) || '';
                var total = sys.length + parts.length;
                function sheet(inner, no, chromeTop, chromeBot) {
                    return '<div class="egrt-sheetwrap">'
                         + '<div class="egrt-page egrt-sheet" style="width:' + mm[0] + 'mm;height:' + mm[1] + 'mm">'
                         + (chromeTop || '') + inner + (chromeBot || '') + '</div>'
                         + '<span class="egrt-pageno">第 ' + no + ' 頁 / 共 ' + total + ' 頁</span></div>';
                }
                var h = '';
                sys.forEach(function(s, i){ h += sheet(s.html, i + 1, '', ftr); });
                parts.forEach(function(p, i){
                    var no = sys.length + i + 1;
                    var hdr = hdrTpl.replace(/\{\{PAGE\}\}/g, no)
                                    .replace(/\{\{TOTAL\}\}/g, total)
                                    .replace(/\{\{VER\}\}/g, pv[i] || dv);
                    h += sheet(p, no, hdr, ftr);
                });
                host.innerHTML = '<div class="egrt-doc-scroll" style="max-height:680px"><div class="egrt-pages">'
                               + h + '</div></div>';
                afterRo();
            }).fail(function(){
                // 拿不到版面時至少要把正文顯示出來，不要整頁空白
                host.innerHTML = '<div class="egrt-doc-scroll" style="max-height:680px"><div class="egrt-pages">'
                    + parts.map(function(p, i){
                        return '<div class="egrt-sheetwrap">'
                             + '<div class="egrt-page egrt-sheet" style="width:' + mm[0] + 'mm;height:' + mm[1] + 'mm">'
                             + p + '</div>'
                             + '<span class="egrt-pageno">第 ' + (i+1) + ' 頁 / 共 ' + parts.length + ' 頁</span></div>';
                      }).join('')
                    + '</div></div>';
                afterRo();
            });
            function afterRo() {
                // src 不存在內容裡，唯讀顯示也要依資產編號補回來
                Array.prototype.slice.call(host.querySelectorAll('img[data-asset]')).forEach(function(im){
                    im.setAttribute('src', assetUrl(im.getAttribute('data-asset')));
                });
                // 制修訂紀錄書上的簽章
                if (window.egDocStamps) egDocStamps(host);
                // 唯讀也要縮放到容器寬度內，否則同樣會出現左右拉桿
                var sc = host.querySelector('.egrt-doc-scroll'), box = host.querySelector('.egrt-pages');
                if (!sc || !box) return;
                var pw = mm[0] * 96 / 25.4, need = pw + 32;
                var k = Math.min(1, (sc.clientWidth || need) / need);
                box.style.zoom = k < 1 ? k : '';
                $(window).on('resize', function(){
                    var k2 = Math.min(1, (sc.clientWidth || need) / need);
                    box.style.zoom = k2 < 1 ? k2 : '';
                });
            }
        }
        loadRef();                 // 內文引用的文件編號有沒有待確認的
    });
}

/* ── 內文引用的文件編號：待確認 → 預覽標示 → 套用（2026-09-22 使用者交辦）───────
   別份文件改了編號或被廢止時，AS_Document_API 會掃過所有線上版內文並建立待處理列。
   這裡負責讓使用者**看到每一處改在哪**再決定，規則全部在 as_doc_ref_lib.php。 */
var REF = [];            // 待確認清單
var REF_PICK = {};       // id → 勾了沒
var REF_NEXT = '';       // 套用之後的版別

function loadRef() {
    $.getJSON(API, { action:'ref_pending', version_id: VID }, function(r){
        if (!r || !r.success) return;
        REF = r.rows || [];
        REF_NEXT = r.next_version || '';
        if (!REF.length) { $('#adRefBar').hide(); return; }
        var hits = 0, obs = 0;
        REF.forEach(function(x){ hits += (x.hits || 0); if (x.kind === 'obsolete') obs++; });
        $('#adRefSum').text('：' + REF.length + ' 個編號、共 ' + hits + ' 處'
            + (obs ? ('（其中 ' + obs + ' 個是廢止，系統不會自動改）') : ''));
        $('#adRefNextVer').text((r.cur_version || '—') + ' → ' + REF_NEXT);
        $('#adRefBar').show();
    });
}

function refPickedIds() {
    return REF.filter(function(x){ return REF_PICK[x.id]; }).map(function(x){ return x.id; });
}
function refPaintList() {
    var h = REF.map(function(x){
        var on = !!REF_PICK[x.id];
        var head = x.kind === 'obsolete'
            ? ('<span class="adr-warn">' + esc(x.old_no) + '</span> 已廢止')
            : ('<span class="adr-old">' + esc(x.old_no) + '</span> → <span class="adr-new">' + esc(x.new_no) + '</span>');
        var hits = (x.hits_list || []).map(function(t){
            return '<div class="adr-hit"><b>正文第 ' + t.page + ' 頁</b>　…'
                 + esc(t.before) + '<b>' + esc(t.old) + '</b>' + esc(t.after) + '…</div>';
        }).join('');
        return '<div class="adr-it' + (on ? ' on' : '') + '">'
             + '<div class="adr-it-h"><label style="font-weight:normal;margin:0;">'
             + '<input type="checkbox" class="refChk" value="' + x.id + '"' + (on ? ' checked' : '') + '>'
             + head + '　<span style="color:#8a6d45;">' + x.hits + ' 處</span></label></div>'
             + '<div class="adr-it-s">' + esc(x.note || '') + (x.date ? ('　異動日 ' + esc(x.date)) : '') + '</div>'
             + hits + '</div>';
    }).join('');
    $('#refList').html(h || '<div style="padding:12px;color:#8a6d45;">沒有待確認的項目。</div>');
    $('#refCnt').text('共 ' + REF.length + ' 個編號');
    refPaintWill();
}
function refPaintWill() {
    var ids = refPickedIds();
    if (!ids.length) { $('#refWill').html('<span style="color:#DD5138;">請先勾選要套用的項目。</span>'); return; }
    var picked = REF.filter(function(x){ return REF_PICK[x.id]; });
    var pages = {}, dates = [];
    picked.forEach(function(x){
        String(x.pages || '').split(',').forEach(function(p){ p = p.trim(); if (p) pages[+p] = 1; });
        if (x.date) dates.push(x.date);
    });
    dates.sort();
    var ps = Object.keys(pages).map(Number).sort(function(a,b){ return a-b; });
    $('#refWill').html('套用後會補一列制修訂紀錄：版別 <b>' + esc(REF_NEXT) + '</b>'
        + '、修訂日期 <b>' + esc(dates.length ? dates[dates.length-1] : '（今天）') + '</b>'
        + '、制修訂頁次 <b>' + (ps.length ? ('第 ' + ps.join('、') + ' 頁') : '—') + '</b>（正文頁碼）。');
}
function refPaintPreview() {
    var ids = refPickedIds();
    if (!ids.length) { $('#refPrev').html('<div style="color:#8a6d45;">勾選左邊的項目就會在這裡標出來。</div>'); $('#refMarks').text(''); return; }
    $('#refPrev').html('<div style="color:#8a6d45;">載入中…</div>');
    $.getJSON(API, { action:'ref_preview', version_id: VID, ids: ids.join(',') }, function(r){
        if (!r || !r.success) { $('#refPrev').html('<div style="color:#DD5138;">' + esc((r||{}).message || '載入失敗') + '</div>'); return; }
        // 這是後端已經清洗過的內容再加上 del/ins/mark 標示，直接放進預覽區
        $('#refPrev').html(r.html || '<div style="color:#8a6d45;">這個版次沒有內容。</div>');
        $('#refMarks').text('標出 ' + (r.marks || 0) + ' 處');
    });
}
$('#btnRefOpen').on('click', function(){
    REF_PICK = {};
    // 預設把「編號變更」全勾起來（那種系統算得出正確答案）；廢止的不預設勾，要人自己決定
    REF.forEach(function(x){ if (x.kind !== 'obsolete') REF_PICK[x.id] = true; });
    $('#refAll').prop('checked', REF.length > 0 && refPickedIds().length === REF.length);
    refPaintList(); refPaintPreview();
    openMask('mRef');
});
$(document).on('change', '.refChk', function(){
    REF_PICK[+this.value] = this.checked;
    $(this).closest('.adr-it').toggleClass('on', this.checked);
    $('#refAll').prop('checked', refPickedIds().length === REF.length && REF.length > 0);
    refPaintWill(); refPaintPreview();
});
$('#refAll').on('change', function(){
    var on = this.checked;
    REF.forEach(function(x){ REF_PICK[x.id] = on; });
    refPaintList(); refPaintPreview();
});
$('#btnRefApply').on('click', function(){
    var ids = refPickedIds();
    if (!ids.length) { alert('請先勾選要套用的項目'); return; }
    var obs = REF.filter(function(x){ return REF_PICK[x.id] && x.kind === 'obsolete'; });
    var msg = '將套用 ' + ids.length + ' 個編號的變更，並在文件制修訂紀錄書補一列（版別 ' + REF_NEXT + '）。\n';
    if (obs.length) msg += '\n注意：其中 ' + obs.length + ' 個是「廢止」，系統不會自動改內文，'
                         + '只會記進制修訂摘要，請自行編輯內文。\n';
    if (DIRTY) msg += '\n你目前有未存檔的變更，套用會以「已存檔的內容」為準，請先存檔。\n';
    if (!confirm(msg + '\n確定要套用嗎？')) return;
    NProgress.start();
    $.post(API, { action:'ref_apply', version_id: VID, ids: JSON.stringify(ids), csrf: CSRF }, function(r){
        NProgress.done();
        if (!r || !r.success) { alert((r||{}).message || '套用失敗'); return; }
        closeMask('mRef');
        alert('已套用。\n版別：' + r.version + '\n修訂日期：' + r.date
            + '\n制修訂頁次：' + (r.pages || '—')
            + '\n制修訂摘要：' + (r.summary || '')
            + '\n\n（線上版的送審／自動簽核還沒做，之後會接上這裡。）');
        // 版次換了，網址要跟著換，不然重新整理會回到舊版次
        location.href = 'as_doc_editor.php?version_id=' + r.version_id;
    }, 'json');
});
$('#btnRefSkip').on('click', function(){
    var ids = refPickedIds();
    if (!ids.length) { alert('請先勾選要略過的項目'); return; }
    if (!confirm('確認這 ' + ids.length + ' 個編號不需要改？\n會留下是誰在什麼時候略過的紀錄，之後不再提示。')) return;
    $.post(API, { action:'ref_dismiss', version_id: VID, ids: JSON.stringify(ids), csrf: CSRF }, function(r){
        if (!r || !r.success) { alert((r||{}).message || '失敗'); return; }
        closeMask('mRef'); loadRef();
    }, 'json');
});

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

/** 未轉換清單：使用者明確要求「轉不動的要列出來」，所以待補項目一律醒目顯示；
    「已經轉進來的」跟一般說明只是參考資訊，改成一排小標籤而不是逐項一整行
   （使用者 2026-09-24 回報：這一塊內容太多很混亂）。 */
function paintReport(rep) {
    if (!rep) { $('#adReport').hide(); return; }
    var h = '';
    var must = (rep.todo || []).filter(function(t){ return t.level === 'must'; });
    var info = (rep.todo || []).filter(function(t){ return t.level !== 'must'; });
    if (must.length) {
        h += '<div><b style="color:#DD5138"><i class="fa fa-exclamation-triangle"></i> 還要人工補這些（' + must.length + ' 項）</b><ul>';
        must.forEach(function(t){ h += '<li class="must">' + esc(t.note) + '</li>'; });
        h += '</ul></div>';
    }
    var chips = [];
    (rep.done || []).forEach(function(t){ chips.push('<span class="ad-rp-chip ok"><i class="fa fa-check"></i> ' + esc(t.note) + '</span>'); });
    info.forEach(function(t){ chips.push('<span class="ad-rp-chip info">' + esc(t.note) + '</span>'); });
    if (chips.length) h += '<div class="ad-rp-chips">' + chips.join('') + '</div>';
    h += '<div style="font-size:11.5px;color:#a08a6f;margin-top:6px">來源：' + esc(rep.src || '')
       + (rep.at ? '　匯入於 ' + esc(rep.at) : '') + '</div>';
    $('#adReportBody').html(h);
    $('#adReport').show();
}
function esc(s) { return $('<i>').text(s == null ? '' : s).html(); }

/* ── 版面樣板 ─────────────────────────────────────────────────────────────
   封面、文件制修訂紀錄書、目錄、每一頁的頁首頁尾一律由後端產生（API action=tpl），
   資料來源是 as_document / as_document_version / customer_list，
   使用者只編正文——「可以自動代入的資料全部自動帶入」（使用者 2026-09-22 定調）。 */
var TPL = null;
function loadChrome(cb) {
    $.getJSON(API, { action:'tpl', version_id: VID }, function(r){
        if (!r.success) { if (cb) cb(); return; }
        TPL = r;
        if (ED && ED.setChrome) {
            ED.setChrome({ sys: r.sys || [], hdrTpl: r.hdr_tpl || '', ftr: r.ftr || '',
                           pageVers: r.page_vers || [], docVer: r.doc_ver || '' });
        }
        paintTplBar(r);
        // 公版設定（表格字型／框線型式）：只覆寫 CSS 變數，與列印版同一段
        if (r.style_css) {
            var st = document.getElementById('adTplStyle');
            if (!st) { st = document.createElement('style'); st.id = 'adTplStyle'; document.head.appendChild(st); }
            st.textContent = r.style_css;
        }
        // 制修訂紀錄書的三格簽章：版面是後端重新產生的，所以每次都要再畫一次
        if (window.egDocStamps) egDocStamps(document.getElementById('adEditorHost'));
        if (cb) cb();
    });
}
function paintTplBar(r) {
    $('#adKind').text(r.kind || '—');
    // 顯示「實際會印出來的那一個」：沒設定時是文件自己的部門，所以永遠不會是空白；
    // 是自動帶入的就標一下，管理員才知道它可以改
    var auto = !(r.cfg && Number(r.cfg.issue_dept_id) > 0);
    $('#adIssueDept').text(r.issue_dept || '—')
        .attr('title', auto ? '沒有特別設定，自動帶入這份文件隸屬的部門' : '由版面設定指定');
    $('#adIssueDept').next('.ad-auto').remove();
    if (auto && r.issue_dept) $('#adIssueDept').after('<span class="ad-auto" style="font-size:11px;color:#A8804C">（自動）</span>');
}

/* ── 送簽（制修訂／審查／核准）─────────────────────────────────────────
   關卡、順序、誰能簽一律由後端算（ads_plan/ads_state），前端只負責顯示與挑人；
   兩邊各算一次「輪到誰」一定會走鐘。 */
var SIGN = null;
function loadSign(cb) {
    $.getJSON(API, { action:'sign_state', version_id: VID }, function(r){
        if (!r.success) { if (cb) cb(); return; }
        SIGN = r;
        paintSignBar();
        if (cb) cb();
    });
}
function paintSignBar() {
    if (!SIGN) return;
    var c = SIGN.state.case, $b = $('#adSignBadge');
    var cls = 'none', txt = '尚未送簽';
    if (c) {
        if (c.status === 'pending')  { cls = 'sign-wait'; txt = '簽核中'; }
        else if (c.status === 'approved') { cls = 'sign-ok'; txt = c.released_at ? '已正式發行' : '簽核完成'; if (c.released_at) cls = 'sign-rel'; }
        else if (c.status === 'rejected') { cls = 'sign-no'; txt = '已退回'; }
        else if (c.status === 'canceled') { cls = 'none'; txt = '已取消送簽'; }
        if (c.status === 'approved' && c.is_auto) txt += '（自動）';
    }
    $b.attr('class', 'ad-badge ' + cls).text(txt);

    var pending = c && c.status === 'pending';
    var mine = false;
    (SIGN.state.steps || []).forEach(function(s){
        if (s.id === SIGN.state.current_step_id && s.signer_user_id === SIGN.me) mine = true;
    });
    $('#btnSignSubmit').toggle(!pending && !(c && c.status === 'approved'));
    $('#btnSignCancel').toggle(!!pending);
    $('#btnSignDo').toggle(!!(pending && mine));
    // 「正式發行」只在簽核完成且還沒發行時出現；能不能真的發行由後端再判一次
    $('#btnRelease').toggle(!!(c && c.status === 'approved' && !c.released_at));
}
function signStageRows() {
    var st = SIGN && SIGN.state ? SIGN.state.steps : [];
    return st;
}
$('#btnSignInfo').on('click', function(){
    loadSign(function(){
        var st = signStageRows(), c = SIGN.state.case;
        var h = '';
        if (!c) {
            h = '<div class="m-note">這個版次還沒有送簽。內容確認好之後按「送審」。</div>';
        } else {
            h += '<div class="sg-note">送審人：' + esc(c.submitted_by_name || '') + '　送出日：' + esc(c.submit_date || '') +
                 (c.released_at ? ('　<b>已於 ' + esc(String(c.released_at).substring(0,10)) + ' 由 ' + esc(c.released_by_name||'') + ' 正式發行</b>') : '') +
                 '</div>';
            if (c.reject_note) h += '<div class="sg-note" style="color:#DD5138">退回原因：' + esc(c.reject_note) + '</div>';
            h += '<div style="margin-top:8px"></div>';
            st.forEach(function(s){
                var cls = s.status === 'ok' ? 'done' : (s.id === SIGN.state.current_step_id ? 'on' : '');
                var mark = s.status === 'ok' ? '<span style="color:#7A9A4A">✔ 已簽</span>'
                         : s.status === 'reject' ? '<span style="color:#DD5138">✘ 退回</span>'
                         : (s.id === SIGN.state.current_step_id ? '<span style="color:#F0A24B">← 等這一關</span>' : '等候中');
                h += '<div class="sg-step ' + cls + '">'
                   + '<span class="sg-s">' + esc(s.stage_name) + '</span>'
                   + '<span class="sg-p">' + esc(s.signer_name || '—')
                   + '<span class="sg-t">　' + esc(s.dept_name || '') + ' ' + esc(s.position_name || '') + '</span>'
                   + (s.note ? '<div class="sg-note">' + esc(s.note) + '</div>' : '')
                   + '</span>'
                   + '<span class="sg-t">' + esc(s.sign_date || '') + (s.is_auto ? '　自動' : '') + '</span>'
                   + '<span>' + mark + '</span></div>';
            });
            var rel = SIGN.state.release;
            if (rel && !rel.ok && rel.newer) {
                h += '<div class="m-note" style="border-color:#DD5138;background:#FBE6DE;color:#A5502E">'
                   + esc(rel.msg) + '</div>';
            }
        }
        $('#signInfoBody').html(h);
        var mine = false;
        st.forEach(function(s){ if (s.id === SIGN.state.current_step_id && s.signer_user_id === SIGN.me) mine = true; });
        $('#signDoBox').toggle(!!(c && c.status === 'pending' && mine));
        $('#signNote').val('');
        openMask('mSignInfo');
    });
});
$('#btnSignDo').on('click', function(){ $('#btnSignInfo').click(); });
function signDecide(ok) {
    var step = 0;
    (SIGN.state.steps || []).forEach(function(s){ if (s.id === SIGN.state.current_step_id) step = s.id; });
    if (!step) { alert('找不到目前這一關，請重新整理頁面。'); return; }
    var note = $('#signNote').val() || '';
    if (!ok && !note.trim()) { alert('退回一定要填原因。'); $('#signNote').focus(); return; }
    $.post(API, { action:'sign_decide', csrf:CSRF, step_id:step, ok: ok ? 1 : 0, note:note }, function(r){
        if (!r.success) { alert(r.message || '簽核失敗'); loadSign(); return; }
        closeMask('mSignInfo');
        // 簽完要重畫制修訂紀錄書上的章。沒有編輯權的人（被指派的簽核人多半就是）
        // 走的是唯讀渲染那一條路，loadChrome() 對他們沒有作用，要整個重載才會更新。
        if (CAN_EDIT) loadChrome(); else load();
        loadSign(function(){ $('#adSaved').text(r.message || '已簽核'); });
    }, 'json');
}
$('#btnSignOk').on('click', function(){ signDecide(true); });
$('#btnSignReject').on('click', function(){ signDecide(false); });

$('#btnSignSubmit').on('click', function(){
    if (DIRTY && !confirm('有未存檔的變更，送審的是「上次存檔」的內容。\n要繼續送審嗎？（建議先按存檔）')) return;
    loadSign(function(){
        var p = SIGN.plan;
        if (!p || !p.ok) {
            alert((p && p.msg ? p.msg : '無法送審') +
                  (p && p.need_cfg ? '\n\n請管理員先到工具列的「簽核設定」設定制修訂／審查／核准由誰簽。' : ''));
            return;
        }
        var h = '';
        p.steps.forEach(function(s){
            h += '<div class="sg-pick" data-cfg="' + s.cfg_id + '">'
               + '<div class="sg-h">' + s.seq + '. ' + esc(s.stage_name)
               + (s.auto_sign ? ' <span style="color:#8A5A2B;font-weight:normal">（自動簽核）</span>' : '') + '</div>';
            if (!s.candidates.length) {
                h += '<div class="sg-f" style="color:#DD5138">管理員設定的部門與職稱目前沒有在職人員，送不出去。</div>';
            } else if (s.fixed || s.auto_sign) {
                var c0 = s.candidates[0];
                h += '<div class="sg-f">' + esc(c0.name) + '　' + esc(c0.dept) + ' ' + esc(c0.pos)
                   + (s.mode === 'editor' ? '（內容的最後修改人）' : '') + '</div>';
            } else {
                h += '<select class="form-control input-sm sg-sel" data-eg-filter="輸入姓名篩選…">'
                   + s.candidates.map(function(c2){
                       return '<option value="' + c2.id + '">' + esc(c2.name) + '　' + esc(c2.dept) + ' ' + esc(c2.pos) + '</option>';
                     }).join('') + '</select>';
            }
            h += '</div>';
        });
        $('#signPickBody').html(h);
        $('#signMsg').text('');
        openMask('mSign');
    });
});
$('#btnSignGo').on('click', function(){
    var picks = {};
    $('#signPickBody .sg-pick').each(function(){
        var cfg = $(this).data('cfg'), v = $(this).find('.sg-sel').val();
        if (v) picks[cfg] = v;
    });
    var $b = $(this).prop('disabled', true);
    $('#signMsg').text('送出中…');
    $.post(API, { action:'sign_submit', csrf:CSRF, version_id:VID, picks: JSON.stringify(picks) }, function(r){
        $b.prop('disabled', false); $('#signMsg').text('');
        if (!r.success) { alert(r.message || '送審失敗'); return; }
        closeMask('mSign');
        loadChrome();
        loadSign(function(){ $('#adSaved').text(r.message || '已送出簽核'); });
    }, 'json');
});
$('#btnSignCancel').on('click', function(){
    if (!confirm('要取消這次送簽嗎？\n已經簽過的關卡會一起作廢，之後可以重新送審。')) return;
    $.post(API, { action:'sign_cancel', csrf:CSRF, version_id:VID }, function(r){
        if (!r.success) { alert(r.message || '取消失敗'); return; }
        loadChrome();
        loadSign(function(){ $('#adSaved').text('已取消送簽'); });
    }, 'json');
});
$('#btnRelease').on('click', function(){
    var rel = SIGN && SIGN.state ? SIGN.state.release : null;
    if (rel && !rel.ok) { alert(rel.msg); return; }
    if (!confirm('正式發行之後：\n・這一版的線上內容會變成正本（大家看到與印出來的就是它）\n・AS 文件管理的「目前版次」會指到這一版\n・同一份文件其他版次的線上內容會退回草稿\n\n要發行嗎？')) return;
    $.post(API, { action:'sign_release', csrf:CSRF, version_id:VID }, function(r){
        if (!r.success) { alert(r.message || '發行失敗'); loadSign(); return; }
        alert(r.message);
        $('#chkPrimary').prop('checked', true);
        loadSign();
    }, 'json');
});

<?php if ($P['admin']): ?>
/* ── 簽核設定（管理員）───────────────────────────────────────────── */
var CFG = null;
function cfgRowHtml(r) {
    var st = CFG.stages, md = CFG.modes;
    function opts(obj, cur) {
        return Object.keys(obj).map(function(k){
            return '<option value="' + k + '"' + (k === cur ? ' selected' : '') + '>' + esc(obj[k]) + '</option>';
        }).join('');
    }
    return '<tr>'
      + '<td style="text-align:center"><span class="cfg-seq"></span>'
      +   '<div style="margin-top:3px"><button class="btn btn-xs btn-default cfg-up">↑</button> '
      +   '<button class="btn btn-xs btn-default cfg-dn">↓</button></div></td>'
      + '<td><select class="form-control input-sm cfg-stage" data-eg-skip>' + opts(st, r.stage) + '</select></td>'
      + '<td><select class="form-control input-sm cfg-mode" data-eg-skip>' + opts(md, r.mode) + '</select></td>'
      + '<td class="cfg-target"></td>'
      + '<td style="text-align:center"><input type="checkbox" class="cfg-auto"' + (Number(r.auto_sign) ? ' checked' : '') + '></td>'
      + '<td style="text-align:center"><button class="btn btn-xs btn-danger cfg-del">刪除</button></td>'
      + '</tr>';
}
function cfgPaintTarget($tr, r) {
    var mode = $tr.find('.cfg-mode').val();
    var h = '';
    if (mode === 'editor') {
        h = '<span style="font-size:12px;color:#8A5A2B">由系統自動判定（這一版內容的最後修改人）</span>';
    } else if (mode === 'user') {
        h = '<select class="form-control input-sm cfg-user" data-eg-filter="輸入姓名篩選…">'
          + CFG.people.map(function(p){
              return '<option value="' + p.id + '"' + (Number(r.user_id) === p.id ? ' selected' : '') + '>'
                   + esc(p.name) + '　' + esc(p.dept) + ' ' + esc(p.pos) + '</option>';
            }).join('') + '</select>';
    } else {
        h = '<select class="form-control input-sm cfg-dept" data-eg-filter="輸入部門篩選…" style="margin-bottom:4px">'
          + '<option value="0">（請選部門）</option>'
          + CFG.depts.map(function(d2){
              return '<option value="' + d2.id + '"' + (Number(r.pick_dept_id) === Number(d2.id) ? ' selected' : '') + '>'
                   + esc(d2.name) + '</option>';
            }).join('') + '</select>'
          + '<select class="form-control input-sm cfg-pos" data-eg-filter="輸入職稱篩選…">'
          + '<option value="0">不限職稱（這個部門的人都可以簽）</option>'
          + CFG.positions.map(function(p2){
              return '<option value="' + p2.id + '"' + (Number(r.position_id) === Number(p2.id) ? ' selected' : '') + '>'
                   + esc(p2.name) + '</option>';
            }).join('') + '</select>';
    }
    $tr.find('.cfg-target').html(h);
}
function cfgRenumber() {
    $('#cfgTbl tbody tr').each(function(i){ $(this).find('.cfg-seq').text(i + 1); });
}
function cfgLoad(deptId) {
    $.getJSON(API, { action:'sign_cfg', dept_id: deptId || 0 }, function(r){
        if (!r.success) { alert(r.message || '讀取失敗'); return; }
        CFG = r;
        if ($('#cfgScope option').length < 2 && TPL) {
            $('#cfgScope').append('<option value="' + (TPL.cfg && TPL.dept_id ? TPL.dept_id : 0) + '"></option>');
        }
        $('#cfgScopeNote').text(Number(deptId) > 0
            ? (r.scope_is_own ? '這個部門有自己的設定' : '這個部門目前沿用全站預設，存檔後就會變成它自己的設定')
            : '沒有自己設定的部門都會用這一組');
        var rows = r.rows.length ? r.rows : [
            { stage:'draft',   mode:'editor',   auto_sign:0 },
            { stage:'review',  mode:'dept_pos', auto_sign:0 },
            { stage:'approve', mode:'dept_pos', auto_sign:0 }
        ];
        $('#cfgTbl tbody').empty();
        rows.forEach(function(row){
            var $tr = $(cfgRowHtml(row));
            $('#cfgTbl tbody').append($tr);
            cfgPaintTarget($tr, row);
        });
        cfgRenumber();
        $('#cfgMsg').text('');
        openMask('mSignCfg');
    });
}
$('#btnSignCfg').on('click', function(){
    // 範圍下拉：全站預設＋這份文件自己的部門
    var $s = $('#cfgScope');
    $s.find('option[value!="0"]').remove();
    if (SIGN && SIGN.plan && SIGN.plan.dept_id) {
        $s.append('<option value="' + SIGN.plan.dept_id + '">只套用到這份文件的部門（'
                  + esc((TPL && TPL.dept_label) || '本部門') + '）</option>');
    }
    $s.val('0');
    cfgLoad(0);
});
$('#cfgScope').on('change', function(){ cfgLoad($(this).val()); });
$('#cfgTbl').on('change', '.cfg-mode', function(){ cfgPaintTarget($(this).closest('tr'), {}); });
$('#cfgTbl').on('click', '.cfg-del', function(){ $(this).closest('tr').remove(); cfgRenumber(); });
$('#cfgTbl').on('click', '.cfg-up', function(){
    var $tr = $(this).closest('tr'), $p = $tr.prev();
    if ($p.length) { $tr.insertBefore($p); cfgRenumber(); }
});
$('#cfgTbl').on('click', '.cfg-dn', function(){
    var $tr = $(this).closest('tr'), $n = $tr.next();
    if ($n.length) { $tr.insertAfter($n); cfgRenumber(); }
});
$('#btnCfgAdd').on('click', function(){
    var $tr = $(cfgRowHtml({ stage:'review', mode:'dept_pos', auto_sign:0 }));
    $('#cfgTbl tbody').append($tr);
    cfgPaintTarget($tr, {});
    cfgRenumber();
});
$('#btnCfgSave').on('click', function(){
    var rows = [];
    $('#cfgTbl tbody tr').each(function(){
        var $t = $(this);
        rows.push({
            stage: $t.find('.cfg-stage').val(),
            mode:  $t.find('.cfg-mode').val(),
            pick_dept_id: $t.find('.cfg-dept').val() || 0,
            position_id:  $t.find('.cfg-pos').val() || 0,
            user_id:      $t.find('.cfg-user').val() || 0,
            auto_sign:    $t.find('.cfg-auto').prop('checked') ? 1 : 0
        });
    });
    var $b = $(this).prop('disabled', true);
    $('#cfgMsg').text('存檔中…');
    $.post(API, { action:'sign_cfg_save', csrf:CSRF, dept_id: $('#cfgScope').val() || 0,
                  rows: JSON.stringify(rows) }, function(r){
        $b.prop('disabled', false); $('#cfgMsg').text('');
        if (!r.success) { alert(r.message || '存檔失敗'); return; }
        closeMask('mSignCfg');
        loadSign(function(){ $('#adSaved').text('簽核設定已存檔'); });
    }, 'json');
});
<?php endif; ?>

/* ── 版面設定 ─────────────────────────────────────────────────────────────
   只有這四項沒辦法從資料推導，其餘（公司名、文件名稱與編號、類別、制修訂紀錄、
   制修訂部門、頁次、頁版別）一律自動帶入，所以這個跳窗刻意就只有這四格。
   設定是掛在「文件」上的，所有版次共用。 */
$('#btnTpl').on('click', function(){
    if (!TPL) { alert('版面資料還在載入中，請稍候再試一次。'); return; }
    var cfg = TPL.cfg || {};
    $('#tplDocNo').text((TPL.doc_no || '') + ' ' + (TPL.doc_name || ''));
    $('#tplFootDefault').text(TPL.foot_default || '');
    $('#tplDeptFallback').text(TPL.dept_label || '（這份文件沒有設定隸屬部門）');

    var cur = Number(cfg.issue_dept_id || 0);
    $('#tplDept').html(
        '<option value="0">（不指定，用文件自己的部門：' + esc(TPL.dept_label || '—') + '）</option>' +
        (TPL.depts || []).map(function(d){
            return '<option value="' + d.id + '"' + (Number(d.id) === cur ? ' selected' : '') + '>' +
                   esc(d.label || d.name) + '</option>';
        }).join('')
    ).trigger('change');          // 讓共用的打字篩選框重新取一次選項快照

    $('#tplFoot').val(cfg.foot_left || '');
    $('#tplCoverEn').val(cfg.cover_en || '');
    $('#tplToc').prop('checked', !(String(cfg.toc_on) === '0'));
    // 封面英文與目錄只有一階用得到，二階以下顯示出來只會讓人以為設了有效
    // （階別存的是中文，判定由後端給旗標，前端不自己比字串）
    $('.tpl-l1').toggle(!!TPL.is_level1);
    $('#tplMsg').text('');
    $('#btnTplSave').prop('disabled', !CAN_EDIT);
    fillStyleForm();
    openMask('mTpl');
});

/* ── 公版設定（管理員）──────────────────────────────────────────────── */
function fillStyleForm() {
    if (!$('#stFont').length || !TPL || !TPL.style_opts) return;
    var o = TPL.style_opts, s = TPL.style || {};
    function fill($el, map, cur) {
        $el.html(Object.keys(map).map(function(k){
            return '<option value="' + esc(k) + '"' + (k === String(cur) ? ' selected' : '') + '>'
                 + esc(map[k]) + '</option>';
        }).join(''));
    }
    fill($('#stFont'), o.fonts, s.tbl_font || '');
    fill($('#stBStyle'), o.borders, s.brd_style || 'solid');
    fill($('#stBW'), o.widths, s.brd_w || '1px');
    fill($('#stBColor'), o.colors, s.brd_color || '#000000');
    $('#stSize').val(s.tbl_size || '');
    $('#stPad').val(s.cell_pad || '4px');
    $('#stBodyPad').val(s.body_pad || '3mm');
    $('#stBold').toggleClass('on', String(s.tbl_weight) === 'bold');
    $('#stFrame').toggleClass('on', String(s.body_frame) === '1');
    previewStyle();
}
/** 預覽：只改跳窗內那一塊的變數，不動整份文件（存檔後才真的套用） */
function previewStyle() {
    var p = document.getElementById('stPreview');
    if (!p) return;
    p.style.setProperty('--adt-tbl-font', $('#stFont').val() || 'inherit');
    p.style.setProperty('--adt-tbl-size', $('#stSize').val() || 'inherit');
    p.style.setProperty('--adt-tbl-weight', $('#stBold').hasClass('on') ? 'bold' : 'normal');
    p.style.setProperty('--adt-brd-style', $('#stBStyle').val() || 'solid');
    p.style.setProperty('--adt-brd-w', $('#stBW').val() || '1px');
    p.style.setProperty('--adt-brd-color', $('#stBColor').val() || '#000');
    p.style.setProperty('--adt-cell-pad', $('#stPad').val() || '4px');
    if ($('#stFrame').hasClass('on')) {
        p.style.setProperty('--adt-body-brd',
            ($('#stBW').val() || '1px') + ' ' + ($('#stBStyle').val() || 'solid') + ' ' + ($('#stBColor').val() || '#000'));
        p.style.setProperty('--adt-body-pad', $('#stBodyPad').val() || '3mm');
    } else {
        p.style.setProperty('--adt-body-brd', '0 none transparent');
        p.style.setProperty('--adt-body-pad', '0');
    }
}
$(document).on('change', '#stFont,#stSize,#stBStyle,#stBW,#stBColor,#stPad,#stBodyPad', previewStyle);
$(document).on('click', '#stBold,#stFrame', function(){ $(this).toggleClass('on'); previewStyle(); });
$('#btnTplSave').on('click', function(){
    var $b = $(this).prop('disabled', true);
    $('#tplMsg').text('存檔中…');
    $.post(API, {
        action: 'tpl_save', csrf: CSRF, version_id: VID,
        issue_dept_id: $('#tplDept').val() || 0,
        foot_left:     $('#tplFoot').val(),
        cover_en:      $('#tplCoverEn').val(),
        toc_on:        $('#tplToc').prop('checked') ? 1 : 0
    }, function(r){
        $b.prop('disabled', false);
        if (!r.success) { $('#tplMsg').text(''); alert(r.message || '設定存檔失敗'); return; }
        // 管理員的公版設定與版面設定一起存（同一顆按鈕，使用者不必按兩次）
        if (!$('#stFont').length) { finishTpl(); return; }
        $.post(API, {
            action: 'tpl_style_save', csrf: CSRF,
            tbl_font:   $('#stFont').val(),
            tbl_size:   $('#stSize').val(),
            tbl_weight: $('#stBold').hasClass('on') ? 'bold' : 'normal',
            brd_style:  $('#stBStyle').val(),
            brd_w:      $('#stBW').val(),
            brd_color:  $('#stBColor').val(),
            cell_pad:   $('#stPad').val(),
            body_frame: $('#stFrame').hasClass('on') ? 1 : 0,
            body_pad:   $('#stBodyPad').val()
        }, function(r2){
            if (!r2.success) { $('#tplMsg').text(''); alert(r2.message || '公版設定存檔失敗'); return; }
            finishTpl();
        }, 'json');

        function finishTpl() {
            $('#tplMsg').text('');
            closeMask('mTpl');
            // 版面是後端產生的，存完一定要重新拿一次，畫面上的封面／頁尾才會跟著變
            loadChrome(function(){ $('#adSaved').text('版面設定已存檔').show(); });
        }
    }, 'json');
});

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
        onCropImage: function(assetId, done){ openCrop(assetId, done); },
        onAddTextToImage: function(assetId, done){
            // 拿這張既有圖片（例如匯入時轉不進來、改用圖片方式帶進來的流程圖）
            // 當底圖，用跟「插入流程圖」同一套工具疊文字上去；
            // 存的時候用 asset_id:0（另存一筆新的），原圖留在底層當底圖，
            // 不去改原本那張圖片資產，document 上的 <img> 改指向新的這張
            EGFlow.open({ json:null, bgImageUrl:assetUrl(assetId), onSave: function(png, json){
                saveFlow(0, png, json, function(newId){
                    ASSET_KIND[String(newId)] = 'flow';
                    done(newId);
                });
            }});
        }
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
    /* 還沒排版完就存檔，ED.get() 只會拿到還沒分頁的那一大團內容，
       **分頁標記會整份被洗掉**（存下去之後列印就變成一頁到底）。
       所以在初次載入＋自動分頁跑完之前一律不給存。 */
    if (!EDITOR_READY) {
        alert('文件還在排版中（大文件需要幾秒），排版完成前先不要存檔，以免分頁被壓成一頁。\n請稍候再按一次存檔。');
        return;
    }
    /* 最後一道防線：編輯器上明明有好幾頁，產出的內容卻一個分頁標記都沒有，
       那一定是哪裡出了問題（曾經因此把整份文件的分頁洗成一頁）。
       這種情況寧可不存，也不要覆蓋掉好的資料。 */
    var _html = ED.get();
    var _pages = ED.pageCount();
    var _marks = (String(_html).match(/page-break-after/g) || []).length;
    if (_pages > 1 && _marks === 0) {
        alert('存檔已中止：編輯器上有 ' + _pages + ' 頁，但整理出來的內容卻沒有任何分頁標記。\n'
            + '直接存下去會把整份文件的分頁壓成一頁。\n\n請重新整理頁面再試一次；'
            + '若重整後仍然這樣，請截圖回報（原本的內容沒有被改動）。');
        return;
    }
    var $b = $('#btnSave').prop('disabled', true);
    $.post(API, {
        action:'save', csrf:CSRF, version_id:VID,
        html: _html,
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
        AUTO_INDENT_AFTER_LOAD = true;
        load();
        alert('匯入完成，已自動依編號縮排。請看上方的清單確認還有哪些要人工補（尤其流程圖）。');
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

/* ── 固定標題範本設定（管理員；2026-09-24 新增）───────────────────────────
   #htTree 底下的 DOM 就是唯一資料來源，不另外維護一份 JS 陣列跟著同步——
   逐鍵輸入若要即時同步陣列，整層重畫一次就會讓輸入框失焦、游標跳掉；
   存檔當下才去讀 DOM 組 JSON，上／下／刪除／新增小標題直接搬動 DOM 節點即可。 */
function htBuildTopEl(text) {
    var $t = $('<div class="ht-top">'
        + '<div class="ht-top-row">'
        + '<input type="text" class="form-control input-sm ht-top-in" placeholder="大標題，例如：目的">'
        + '<button type="button" class="ht-mini ht-top-up" title="上移"><i class="fa fa-arrow-up"></i></button>'
        + '<button type="button" class="ht-mini ht-top-down" title="下移"><i class="fa fa-arrow-down"></i></button>'
        + '<button type="button" class="ht-mini danger ht-top-del" title="刪除"><i class="fa fa-trash"></i></button>'
        + '</div>'
        + '<div class="ht-sub-list"></div>'
        + '<button type="button" class="btn btn-default btn-xs ht-addsub"><i class="fa fa-plus"></i> 小標題</button>'
        + '</div>');
    $t.find('.ht-top-in').val(text || '');
    return $t;
}
function htBuildSubEl(text) {
    var $s = $('<div class="ht-sub-row">'
        + '<input type="text" class="form-control input-sm ht-sub-in" placeholder="小標題">'
        + '<button type="button" class="ht-mini ht-sub-up" title="上移"><i class="fa fa-arrow-up"></i></button>'
        + '<button type="button" class="ht-mini ht-sub-down" title="下移"><i class="fa fa-arrow-down"></i></button>'
        + '<button type="button" class="ht-mini danger ht-sub-del" title="刪除"><i class="fa fa-trash"></i></button>'
        + '</div>');
    $s.find('.ht-sub-in').val(text || '');
    return $s;
}
function htUpdateCount() { $('#htCount').text($('#htTree > .ht-top').length); }
function htRenderTree(tree) {
    var $tree = $('#htTree').empty();
    (tree || []).forEach(function(t){
        var $t = htBuildTopEl(t.heading_text);
        (t.children || []).forEach(function(c){ $t.find('.ht-sub-list').append(htBuildSubEl(c.heading_text)); });
        $tree.append($t);
    });
    htUpdateCount();
}
function htLoadLevel(level) {
    $.getJSON(API, { action:'heading_tpl_get', doc_level: level }, function(hr){
        if (!hr || !hr.success) { alert((hr||{}).message || '讀取失敗'); return; }
        if (!$('#htLevel option').length) {
            $('#htLevel').html((hr.levels || []).map(function(lv){ return '<option value="'+esc(lv)+'">'+esc(lv)+'</option>'; }).join(''));
        }
        $('#htLevel').val(level);
        htRenderTree(hr.tree);
    });
}
$('#btnHeadTpl').on('click', function(){
    $('#htDocKw').val(''); $('#htDocList').empty(); $('#htHarvestBox').hide(); $('#htHarvestList').empty();
    htLoadLevel(DOC_LEVEL || '二階');
    openMask('mHeadTpl');
});
$('#htLevel').on('change', function(){ htLoadLevel($(this).val()); });
$('#htAddTop').on('click', function(){
    var $t = htBuildTopEl('');
    $('#htTree').append($t);
    htUpdateCount();
    $t.find('.ht-top-in').focus();
});
// 事件委派：新增/刪除小標題都會讓節點重新產生，用委派才不必每次重綁
$('#htTree').on('click', '.ht-addsub', function(){
    var $s = htBuildSubEl('');
    $(this).siblings('.ht-sub-list').append($s);
    $s.find('.ht-sub-in').focus();
});
$('#htTree').on('click', '.ht-top-del', function(){ $(this).closest('.ht-top').remove(); htUpdateCount(); });
$('#htTree').on('click', '.ht-sub-del', function(){ $(this).closest('.ht-sub-row').remove(); });
$('#htTree').on('click', '.ht-top-up', function(){
    var $t = $(this).closest('.ht-top'); var $p = $t.prev('.ht-top'); if ($p.length) $t.insertBefore($p);
});
$('#htTree').on('click', '.ht-top-down', function(){
    var $t = $(this).closest('.ht-top'); var $n = $t.next('.ht-top'); if ($n.length) $t.insertAfter($n);
});
$('#htTree').on('click', '.ht-sub-up', function(){
    var $s = $(this).closest('.ht-sub-row'); var $p = $s.prev('.ht-sub-row'); if ($p.length) $s.insertBefore($p);
});
$('#htTree').on('click', '.ht-sub-down', function(){
    var $s = $(this).closest('.ht-sub-row'); var $n = $s.next('.ht-sub-row'); if ($n.length) $s.insertAfter($n);
});
$('#htSave').on('click', function(){
    var tree = [];
    $('#htTree > .ht-top').each(function(){
        var text = $(this).find('> .ht-top-row .ht-top-in').val();
        var children = [];
        $(this).find('.ht-sub-row .ht-sub-in').each(function(){ children.push({ text: $(this).val() }); });
        tree.push({ text: text, children: children });
    });
    $.post(API, { action:'heading_tpl_save', csrf:CSRF, doc_level:$('#htLevel').val(), tree: JSON.stringify(tree) }, function(r){
        if (!r.success) { alert(r.message || '儲存失敗'); return; }
        alert(r.message);
        htLoadLevel($('#htLevel').val());
    }, 'json');
});

/* 從既有文件挑選標題（只認真正的 <h1>/<h2>，理由見 mHeadTpl 的說明文字） */
var HT_DOC_KW_TIMER = null;
$('#htDocKw').on('input', function(){
    clearTimeout(HT_DOC_KW_TIMER);
    var kw = $(this).val();
    HT_DOC_KW_TIMER = setTimeout(function(){
        $.getJSON(API, { action:'heading_doc_search', kw: kw }, function(r){
            var rows = (r && r.rows) || [];
            $('#htDocList').html(rows.length ? rows.map(function(d){
                return '<div class="ht-doc-it" data-vid="' + d.version_id + '">'
                     + '<b>' + esc(d.doc_no) + '</b>　' + esc(d.doc_name)
                     + '　<span style="color:#8a6d45">' + esc(d.doc_level) + ' · ' + esc(d.version) + '版</span></div>';
            }).join('') : '<div class="ht-doc-it" style="color:#a08a6f;cursor:default">沒有符合的文件</div>');
        });
    }, 300);
});
$('#htDocList').on('click', '.ht-doc-it[data-vid]', function(){
    var vid = $(this).data('vid');
    $.getJSON(API, { action:'heading_extract', version_id: vid }, function(r){
        var items = (r && r.items) || [];
        var $list = $('#htHarvestList').empty();
        if (!items.length) {
            $list.html('<div style="padding:6px;color:#a08a6f;font-size:12px">'
                + '這份文件裡沒有用「標題1／標題2」格式標記過的段落，沒東西可以挑。</div>');
        } else {
            // 用 .data('text',…) 存原始文字，不靠事後從 DOM 摳掉 checkbox 反推文字
            // （那種寫法一遇到文字裡剛好有空白或特殊字元就容易出錯）
            items.forEach(function(it){
                var $row = $('<label class="ht-hti"></label>').addClass(it.tag)
                    .attr('data-tag', it.tag).data('text', it.text);
                $row.append('<input type="checkbox">').append(document.createTextNode(it.text));
                $list.append($row);
            });
        }
        $('#htHarvestBox').show();
    });
});
$('#htHarvestList').on('change', '.ht-hti input[type=checkbox]', function(){
    if (!this.checked) return;
    var $row = $(this).closest('.ht-hti');
    if ($row.data('tag') !== 'h2') return;
    // 小標題勾了，往上找最近一個標題1一起勾起來（沒有大標題的小標題沒有意義）
    var $prev = $row.prevAll('.ht-hti[data-tag="h1"]').first();
    if ($prev.length) $prev.find('input[type=checkbox]').prop('checked', true);
});
$('#htHarvestApply').on('click', function(){
    var curTop = null, added = 0;
    $('#htHarvestList .ht-hti').each(function(){
        var $row = $(this);
        if (!$row.find('input[type=checkbox]').prop('checked')) return;
        var text = $row.data('text') || '';
        if ($row.data('tag') === 'h1') {
            curTop = htBuildTopEl(text);
            $('#htTree').append(curTop);
        } else {
            if (!curTop) { curTop = htBuildTopEl('（未命名大標題）'); $('#htTree').append(curTop); }
            curTop.find('.ht-sub-list').append(htBuildSubEl(text));
        }
        added++;
    });
    htUpdateCount();
    if (!added) { alert('請先勾選要帶入的段落。'); return; }
    alert('已加入 ' + added + ' 項到左邊的範本，別忘了按下方「儲存這一階的範本」才會真的存起來。');
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
