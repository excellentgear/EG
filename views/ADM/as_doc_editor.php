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
/* 自動補制修訂紀錄後，內容與簽核案件被搬到新的版次列；舊版次列留著當歷史、本身沒有內容。
   開著舊網址（version_id 還是舊的）會看到一份空白文件，一不小心存檔還會建出空內容（2026-10-16 實際發生），
   所以舊版次一律導到接手的新版次。 */
if ($versionId > 0) {
    try {
        $sup = (int)$db->query("SELECT superseded_by FROM as_document_version WHERE id=" . $versionId)->fetchColumn();
        if ($sup > 0 && $sup !== $versionId) { header('Location: as_doc_editor.php?version_id=' . $sup); exit; }
    } catch (Throwable $e) { /* 欄位還沒建（尚未發生過自動補列）就不會有搬移 */ }
}
$V = $versionId > 0 ? adc_version_info($db, $versionId) : null;
// 權限要**逐文件**再算一次：這份文件的負責課室／所屬部門的人也能編輯它
// （使用者 2026-10-15）。設定類仍然只有管理員，見 adc_perms 的說明。
if ($V) $P = adc_perms($db, $uid, (int)$V['doc_id']);
if (empty($_SESSION['adc_csrf'])) $_SESSION['adc_csrf'] = bin2hex(random_bytes(16));

/* 已簽核完成並設為正本的版次＝鎖定（只讀）；要改請按「編輯」另建新版草稿（2026-10-16 使用者交辦）。
   草稿與正式版是兩個版次列，所以進來時要讓人看得到「另一邊有什麼」：正式版上提示有草稿、草稿上提示回正式版。 */
$__locked = false; $__isDraft = false; $__draftVid = 0; $__officialVid = 0;
if ($V) {
    try {
        require_once __DIR__ . '/../../src/common/as_doc_autorev_lib.php';
        adrv_ensure_cols($db);
        $__locked   = adrv_locked($db, $versionId);
        $__isDraft  = (int)$db->query("SELECT COALESCE(is_draft,0) FROM as_document_version WHERE id=" . (int)$versionId)->fetchColumn() === 1;
        $__draftVid = adrv_draft_of_doc($db, (int)$V['doc_id']);
        $__officialVid = (int)$db->query("SELECT COALESCE(current_version_id,0) FROM as_document WHERE id=" . (int)$V['doc_id'])->fetchColumn();
    } catch (Throwable $e) {}
}
/* 唯讀檢視（?view=1）：AS 文件管理清單的「檢視」與「線上」入口都走這裡（使用者 2026-10-19）。
   所有人（含有編輯權的人）預設都先看唯讀版；有編輯權的人再按「進入編輯」才切到編輯畫面。
   ⚠ 只改本頁畫面；寫入端點（AsDocContent_API）仍各自驗 adc_perms，不靠前端擋。
   先記下真正的編輯權限（給「進入編輯」鈕用），再把畫面用的 $P 降成唯讀。 */
$__viewOnly = !empty($_GET['view']);
$__realEdit = !empty($P['edit']);
$__realAdmin = !empty($P['admin']);
$P['can_new_draft'] = !empty($P['edit']) && $__locked;
// 鎖定時畫面維持與編輯時一模一樣（分頁編輯器），只是不能改：內容 contenteditable=false、不給存檔／送審／發行（見 LOCKED）
$blocked = '';
if (!$P['view'])                                   $blocked = '沒有 AS 文件的檢視權限';
elseif (!$V)                                       $blocked = '找不到這個版次（可能已被刪除，或網址少了 version_id）';
elseif ((int)$V['is_obsolete'] === 1 && !$P['admin']) $blocked = '這份文件已廢止，只有管理員能開啟';
if ($__viewOnly && $blocked === '') {          // 畫面降成唯讀（見上方說明）
    $P['edit'] = false; $P['admin'] = false; $P['edit_src'] = ''; $P['can_new_draft'] = false;
    $__isDraft = false;                        // 放棄草稿鈕屬於編輯畫面
}
$__editUrl = 'as_doc_editor.php?version_id=' . (int)$versionId;
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
    <?php /* 內文排版（.eg-docbody／版面樣板）與列印版同一個檔。
             這裡先自己掛一份帶版號的，eg_richtext.js 的 injectDocCss() 看到
             id 已經在就不會再掛一次沒有版號的——改了 CSS 使用者卻還看到舊樣式，
             十之八九就是那一份沒有版號的被瀏覽器快取住（見記憶 asset-cache-busting）。 */ ?>
    <link id="eg-docpage-css" rel="stylesheet"
          href="../../resource/css/eg_doc_page.css?v=<?= @filemtime(__DIR__.'/../../resource/css/eg_doc_page.css') ?>">
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

        /* 頁面往下捲時，存檔／匯入／設定這一排與下面的文件編輯工具列要釘在視窗上方，
           捲到文件中段也點得到（使用者 2026-10-16）。編輯工具列釘在存檔列的正下方，
           它的 top 由 JS 依存檔列的實際高度寫進 --ad-stick（按鈕換行時高度會變）。 */
        .ad-bar { display:flex; flex-wrap:wrap; gap:6px; align-items:center; margin-bottom:0; padding:5px 0 9px; clear:both;
                  position:sticky; top:0; z-index:60; background:#F7F7F7; }
        #adEditorHost .egrt-bar { position:sticky; top:var(--ad-stick, 52px); z-index:55; }
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
        .ad-float { position:fixed; right:22px; bottom:22px; z-index:120; display:flex; flex-direction:column;
            align-items:flex-end; gap:10px; }
        /* 回頂端放在切換頁面（快速導覽）鈕的下方；用 visibility 而不是 display:none，
           出現時上面那顆才不會被擠得往上跳 */
        .ad-back-top { width:38px; height:38px; border-radius:50%;
            background:#F0A24B; color:#fff; text-align:center; line-height:38px; font-size:16px;
            box-shadow:0 2px 8px rgba(0,0,0,.25); cursor:pointer; visibility:hidden; }
        .ad-back-top:hover { background:#d98a33; }
        .ad-back-top.show { visibility:visible; }
        /* 快速跳到大標題／附件：滑鼠移過圖示才展開清單（比照訂單分析的右下快速導覽） */
        .ad-qnav { position:relative; }
        .ad-qnav-fab { width:38px; height:38px; border-radius:50%; border:1px solid #e4d3ba; background:#fff; color:#8a5a2b;
            box-shadow:0 2px 8px rgba(0,0,0,.25); cursor:pointer; font-size:15px; opacity:.7;
            display:flex; align-items:center; justify-content:center; }
        .ad-qnav:hover .ad-qnav-fab { opacity:1; }
        .ad-qnav-panel { position:absolute; right:46px; bottom:0; min-width:200px; max-width:340px; max-height:62vh; overflow:auto;
            background:rgba(255,253,250,.98); border:1px solid #e4d3ba; border-radius:10px; padding:6px;
            box-shadow:0 6px 18px rgba(0,0,0,.22); display:flex; flex-direction:column; gap:2px;
            opacity:0; pointer-events:none; transform:translateX(6px); transition:opacity .15s, transform .15s; }
        .ad-qnav:hover .ad-qnav-panel { opacity:1; pointer-events:auto; transform:translateX(0); }
        .ad-qnav-panel a { font-size:12px; color:#6B4423; padding:4px 10px; border-radius:6px; text-decoration:none;
            white-space:nowrap; overflow:hidden; text-overflow:ellipsis; cursor:pointer; line-height:1.5; }
        .ad-qnav-panel a:hover { background:#F5E9D6; }
        .ad-qnav-panel .ad-qnav-empty { font-size:12px; color:#8A5A2B; padding:6px 10px; white-space:normal; }

        /* 跳窗（沿用全站慣例；寬度一律固定像素，不可用 vw——vw 相對整個瀏覽器視窗會蓋過側選單） */
        .m-mask { position:fixed; inset:0; background:rgba(0,0,0,.45); z-index:10300; display:none; }
        .m-mask.open { display:block; }
        /* 流程圖編輯器的遮罩是 10500；從流程圖按 +AS／偵測 開出的跳窗一定要疊在它上面，否則被遮在後面看不到 */
        #mAsRef, #mRef { z-index:10600; }
        #mArStyle { z-index:10650; }
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
        /* 預覽要跟文件一樣吃公版字型——頁首那張表不在 .eg-docbody 裡面，
           不補這一條的話預覽的表頭永遠是正黑體，跟存檔後的結果對不起來 */
        .st-prev { background:#fff; border:1px solid #d8c7b0; padding:10px 12px; border-radius:3px;
                   font-family: var(--adt-tbl-font, "微軟正黑體", "Microsoft JhengHei", sans-serif); }
        .st-prev .st-body { font-size:11pt; line-height:1.5; }
        .st-prev .st-body p { margin:0 0 4px; }
        .st-prev table.adt-hdr { margin-bottom:2mm; }
        .tpl-row.tpl-l1 { display:none; }        /* 只有一階文件才顯示，由 JS 打開 */

        /* 插入 AS 文件編號／名稱 的挑選器 */
        .ar-filters { display:flex; gap:8px; align-items:center; margin-bottom:8px; flex-wrap:wrap; }
        .ar-list { border:1px solid #e4d3ba; border-radius:4px; height:230px; overflow:auto; background:#fff; }
        .ar-item { display:flex; gap:10px; align-items:baseline; padding:5px 10px; cursor:pointer;
                   border-bottom:1px solid #f3ece1; font-size:13px; }
        .ar-item:hover { background:#fbf6ee; }
        .ar-item.on { background:#f0dfc4; }
        .ar-no { font-weight:bold; color:#6B471A; min-width:92px; }
        .ar-nm { color:#333; flex:1; }
        .ar-dp { color:#8A5A2B; font-size:12px; }
        .ar-obs { color:#DD5138; font-size:12px; }
        .ar-empty { padding:14px; text-align:center; color:#8A5A2B; font-size:13px; }
        .ar-pick { display:flex; gap:14px; margin-top:10px; }
        .ar-pick-l { width:250px; font-size:13px; color:#6B471A; }
        .ar-sel { margin-top:5px; padding:7px 9px; border:1px dashed #e4d3ba; border-radius:4px;
                  background:#fbf6ee; min-height:40px; font-size:13px; }
        .ar-pick-r { flex:1; }
        .ar-row { display:flex; gap:8px; align-items:center; margin-bottom:6px; flex-wrap:wrap; font-size:13px; }
        .ar-row label { font-weight:normal; margin:0; }
        .ar-lab { color:#8A5A2B; font-size:12px; min-width:34px; }
        .ar-prev { font-weight:bold; color:#6B471A; background:#f0dfc4; padding:4px 10px; border-radius:3px; }
        /* 內文裡的參照標記：畫面上淡淡標起來，知道這段字跟哪份文件連著；列印一律不標 */
        .egrt-main .eg-asref { background:#FBE6C2; border-bottom:1px solid #E8890C; }
        .egrt-main .eg-apref { border-bottom:1px dotted #8A5A2B; cursor:pointer; }
        /* 有綁定 AS 文件的字尾端多一個橘色「AS」小標＝有綁定；沒有底色也沒有小標＝只是普通文字。
           小標是 ::after 偽元素：不是內容的一部分，複製貼上與存檔都不會帶走，列印一律不顯示（下面 @media print 再關一次）。 */
        .egrt-main .eg-asref::after { content:"AS"; font:bold 8px/1 sans-serif; color:#fff; background:#E8890C;
                                      border-radius:2px; padding:1px 2px; margin-left:2px; vertical-align:super;
                                      user-select:none; -webkit-user-select:none; }
        @media print { .page-help-btn, .ad-bar, .ad-report, .m-mask { display:none !important; }
                       .eg-asref { background:none !important; border:none !important; }
                       .eg-asref::after { content:none !important; display:none !important; } }
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
            <p style="font-size:13px;color:#8A5A2B">可以編輯某一份文件的人：AS 文件管理員，
               或該文件在 AS 文件管理裡登記的<b>負責課室</b>／<b>所屬部門</b>的人員。</p>
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
            <?php
              /* 能不能改這一份，取決於 AS 文件管理上的「所屬部門」與「負責課室」。
                 唯讀的人一定要看得到「誰可以改」，不然只會以為系統壞了。 */
              $adDepts = adc_doc_dept_names($db, (int)$V['doc_id']);
              $adWho   = $adDepts ? implode('、', array_slice($adDepts, 0, 6))
                                  . (count($adDepts) > 6 ? ' 等' : '') : '';
            ?>
            <?php if ($__viewOnly && $__realEdit): ?>
              <span class="ad-badge none" style="background:#e6ddd0;color:#8A5A2B" title="目前是唯讀檢視，要修改請按「進入編輯」">
                <i class="fa fa-eye"></i> 唯讀檢視</span>
              <a class="btn btn-primary btn-sm" href="<?= htmlspecialchars($__editUrl) ?>" id="btnEnterEdit"
                 title="你有這份文件的編輯權限，進入編輯畫面（已正式發行的版次要再按「編輯」建新版草稿）">
                <i class="fa fa-pencil"></i> 進入編輯</a>
            <?php elseif (!$P['edit']): ?>
              <span class="ad-badge none" style="background:#e6ddd0;color:#8A5A2B"
                    title="可以編輯的人：AS 文件管理員，或這份文件在『AS 文件管理』裡登記的負責課室／所屬部門的人員<?= $adWho ? '（' . htmlspecialchars($adWho) . '）' : '' ?>">
                <i class="fa fa-eye"></i> 唯讀<?= $adWho ? '（可編輯：' . htmlspecialchars(mb_substr($adWho, 0, 18)) . '）' : '' ?></span>
            <?php elseif (($P['edit_src'] ?? '') === 'dept'): ?>
              <span class="ad-badge draft" title="你屬於這份文件的負責課室／所屬部門，所以可以編輯它">
                <i class="fa fa-pencil"></i> 可編輯（本課室文件）</span>
            <?php endif; ?>

            <!-- 送審狀態放在「線上版草稿／正本」徽章右側（使用者 2026-09-24 交辦），
                 跟工具列的動作按鈕分開，一眼就看得到目前簽到哪一關 -->
            <span class="ad-sign-box">
              <?php if (!empty($P['can_new_draft'])): ?>
                <button class="btn btn-primary btn-sm" id="btnNewDraft" title="正式版不能直接改：建立一份新版草稿來編輯，簽核並發行之後才會取代正式版">
                  <i class="fa fa-pencil-square-o"></i> 編輯</button>
              <?php endif; ?>
              <?php if ($__isDraft && !empty($P['edit'])): ?>
                <button class="btn btn-default btn-sm" id="btnDiscardDraft" style="color:#DD5138" title="放棄這份草稿（刪除草稿，正式版不受影響）">
                  <i class="fa fa-trash-o"></i> 放棄草稿</button>
              <?php endif; ?>
              <span class="ad-badge none" id="adSignBadge">尚未送簽</span>
              <?php if ($P['edit'] && !$__locked): ?>
                <button class="btn btn-default btn-sm" id="btnSignSubmit" title="送出制修訂／審查／核准的簽核">
                  <i class="fa fa-paper-plane-o"></i> 送審</button>
                <button class="btn btn-default btn-sm" id="btnSignCancel" style="display:none">取消送簽</button>
              <?php endif; ?>
              <button class="btn btn-warning btn-sm" id="btnSignDo" style="display:none">
                <i class="fa fa-check"></i> 換我簽核</button>
              <?php if ($P['edit'] && !$__locked): ?>
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

        <?php if ($__viewOnly): ?>
        <?php elseif ($__isDraft): ?>
        <div class="alert alert-warning" style="margin:6px 0;padding:8px 12px;font-size:13px">
          <i class="fa fa-pencil"></i> 你正在編輯<b>新版草稿</b>（還沒簽核，不影響正式版）。
          <?php if ($__officialVid > 0): ?><a href="as_doc_editor.php?version_id=<?= (int)$__officialVid ?>">回到正式版</a><?php endif; ?>
        </div>
        <?php elseif ($__locked && $__draftVid > 0): ?>
        <div class="alert alert-warning" style="margin:6px 0;padding:8px 12px;font-size:13px">
          <i class="fa fa-pencil"></i> 這份文件有一份<b>編輯中的草稿</b>（正式版維持原狀、不能直接改）。
          <a href="as_doc_editor.php?version_id=<?= (int)$__draftVid ?>" class="btn btn-xs btn-warning">開啟草稿繼續編輯</a>
        </div>
        <?php elseif ($__locked): ?>
        <div class="alert alert-info" style="margin:6px 0;padding:8px 12px;font-size:13px">
          <i class="fa fa-lock"></i> 這是已正式發行的版本，不能直接修改。要修訂請按「編輯」建立新版草稿。
        </div>
        <?php endif; ?>
        <div class="ad-bar">
          <?php if ($P['edit'] && !$__locked): ?>
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
            <?php if ($P['edit'] && !$__locked): ?>
              <button class="btn btn-default btn-sm" id="btnApx" title="附錄整理：檢查附錄編號（重複／跳號）、附錄自動從新的一頁開始、把內文裡的「附錄 X」連到對應附錄，附錄改編號時自動更新">
                <i class="fa fa-paperclip"></i> 附錄整理</button>
              <button class="btn btn-default btn-sm" id="btnLst" title="編號統一：條文底下第一層 a. b. c.、第二層 1) 2) 3)，自動辨識並列出要改的地方；在清單項目按 Enter 會自動接下一個編號">
                <i class="fa fa-list-ol"></i> 編號與縮排</button>
              <button class="btn btn-default btn-sm" id="btnLck" title="列印排版檢查：用實際列印的排法檢查有沒有哪幾頁會被切成兩張紙（框線沒到底），依已存檔內容">
                <i class="fa fa-print"></i> 列印排版檢查</button>
              <button class="btn btn-default btn-sm" id="btnFr" title="尋找與取代（Ctrl＋H）：例如把中文輸入法打出來的全形「：」一次換成半形「:」">
                <i class="fa fa-exchange"></i> 取代</button>
            <?php endif; ?>
            <?php if ($P['admin']): ?>
              <button class="btn btn-default btn-sm" id="btnHeadTpl" title="設定新建文件時自動帶入的固定大標題／小標題">
                <i class="fa fa-list-alt"></i> 標題範本設定</button>
            <?php endif; ?>
            <span style="width:10px"></span>
          <?php endif; ?>

          <?php if ($P['edit'] && !$__locked): ?>
            <span style="width:10px"></span>
            <label style="font-weight:normal;font-size:12px;color:#6B471A;margin:0;">
              <input type="checkbox" id="chkPrimary"> 設為此版次的正本
            </label>
          <?php endif; ?>
            <span style="width:10px"></span>
            <span class="ad-kv" style="font-size:12px;color:#8A5A2B">紙張</span>
            <select id="selPage" class="form-control input-sm" style="width:auto;display:inline-block" data-eg-skip
                    <?= ($P['edit'] && !$__locked) ? '' : 'disabled' ?>>
              <option value="A4">A4</option><option value="A3">A3</option>
            </select>
            <select id="selOrient" class="form-control input-sm" style="width:auto;display:inline-block" data-eg-skip
                    <?= ($P['edit'] && !$__locked) ? '' : 'disabled' ?>>
              <option value="portrait">直式</option><option value="landscape">橫式</option>
            </select>
            <?php if (!empty($P['print'])): ?>
            <button class="btn btn-default btn-sm" id="btnPrint"><i class="fa fa-print"></i> 列印預覽</button>
            <?php else: ?>
            <span class="ad-kv" style="font-size:12px;color:#8A5A2B" title="列印由角色控管：請管理員在 AS 文件管理的角色設定勾選「列印線上版（唯讀者）」">
              <i class="fa fa-ban"></i> 無列印權限</span>
            <?php endif; ?>
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
              <span id="adRefNextVer">—</span>、修訂日期＝來源異動日、頁次＝實際改到的頁），
              並<b>自動送出簽核</b>（每一關要不要自動簽，看「簽核設定」）。
              <b>改名</b>只會動到用「＋AS」插入的那些引用；流程圖裡的引用要打開那張流程圖按
              「更新引用文字」。</span>
          </div>
        </div>

        <div class="ad-report" id="adReport" style="display:none">
          <span class="ad-rp-x" id="adReportX" title="收起">&times;</span>
          <h4><i class="fa fa-info-circle"></i> 這份文件從 Word 匯入的結果</h4>
          <div id="adReportBody"></div>
        </div>

        <?php if ($__viewOnly): ?>
        <?php /* 唯讀檢視＝直接嵌入正式列印預覽（as_doc_print.php?preview=1），版面與發行後看到的完全一致；
                 編輯器畫面（#adEditorHost 等）藏起來，只在背景載入供「簽核進度」等功能使用。 */ ?>
        <style>#adEditorHost,#adReadonly,#adReport,#adRefBar{display:none !important}
               #adViewFrame{width:100%;border:0;background:#efe9e0;min-height:600px;display:block}</style>
        <iframe id="adViewFrame" src="as_doc_print.php?version_id=<?= (int)$versionId ?>&preview=1&embed=1"
                onload="try{var d=this.contentDocument;this.style.height=(d.documentElement.scrollHeight+20)+'px';setTimeout(function(f){try{f.style.height=(f.contentDocument.documentElement.scrollHeight+20)+'px'}catch(e){}},1500,this);}catch(e){}"></iframe>
        <?php endif; ?>
        <div id="adEditorHost"></div>
        <div id="adReadonly" style="display:none" class="egrt-wrap"></div>
        <div class="ad-float">
            <div class="ad-qnav">
                <button type="button" class="ad-qnav-fab" title="快速跳到大標題／附件"><i class="fa fa-list-ul"></i></button>
                <div class="ad-qnav-panel" id="adQnavPanel"></div>
            </div>
            <div class="ad-back-top" id="btnBackTop" title="回到頂端"><i class="fa fa-arrow-up"></i></div>
        </div>
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

<!-- 插入 AS 文件編號／名稱（2026-10-15 使用者交辦）
     打編號或名稱即時搜尋，也可以依部門與階別挑；可選只帶名稱／只帶編號／兩個都帶，
     並可自訂前後綴。插進去的是帶 data-asdoc 的參照標記，之後那份文件改編號或改名
     就會自動提醒來確認（見 as_doc_ref_lib.php）。 -->
<div class="m-mask" id="mAsRef"><div class="m-win" style="width:900px;max-width:96vw;">
  <div class="m-head">插入 AS 文件編號／名稱 <span class="m-x" data-close="mAsRef">&times;</span></div>
  <div class="m-body">
    <div class="m-note">
      插入之後這段文字會<b>記住是哪一份文件</b>——那份文件日後改了編號或名稱，
      這份文件打開時會跳出提示請你確認要不要跟著改（不會自動改掉，一律經過人確認）。<br>
      只帶名稱時會自動用<b>「」</b>框住，不想要的話插入後自己把引號刪掉就好。
    </div>
    <div class="ar-filters">
      <input type="text" id="arKw" class="form-control input-sm" style="width:260px"
             placeholder="打編號或名稱搜尋，例如 2-MM 或 人力資源" data-eg-skip>
      <select id="arDept" class="form-control input-sm" style="width:150px" data-eg-skip></select>
      <select id="arLevel" class="form-control input-sm" style="width:110px" data-eg-skip></select>
      <label style="font-weight:normal;font-size:12px;margin:0;color:#6B471A">
        <input type="checkbox" id="arObs" data-eg-skip> 含已廢止
      </label>
      <span id="arCount" style="font-size:12px;color:#8A5A2B;margin-left:auto"></span>
    </div>
    <div class="ar-list" id="arList"></div>
    <div class="ar-pick">
      <div class="ar-pick-l">
        <div><b>選到的文件</b></div>
        <div id="arSel" class="ar-sel">尚未選擇（請點上面清單的一列）</div>
      </div>
      <div class="ar-pick-r">
        <div class="ar-row">
          <span class="ar-lab">帶入</span>
          <label><input type="radio" name="arMode" value="both" checked data-eg-skip> 名稱＋編號</label>
          <label><input type="radio" name="arMode" value="name" data-eg-skip> 只有名稱</label>
          <label><input type="radio" name="arMode" value="no" data-eg-skip> 只有編號</label>
        </div>
        <div class="ar-row" id="arStyleRow">
          <span class="ar-lab">樣式</span>
          <select id="arStyle" class="form-control input-sm" style="width:260px" data-eg-skip></select>
          <button class="btn btn-default btn-xs" id="btnArStyleCfg" style="display:none"
                  title="管理員可以增減「名稱＋編號」的排列樣式">樣式設定</button>
        </div>
        <div class="ar-row">
          <span class="ar-lab">前綴</span>
          <input type="text" id="arPre" class="form-control input-sm" style="width:110px" maxlength="20" data-eg-skip>
          <span class="ar-lab">後綴</span>
          <input type="text" id="arPost" class="form-control input-sm" style="width:110px" maxlength="20" data-eg-skip>
          <span style="font-size:12px;color:#8A5A2B">（例如前綴「依」、後綴「辦理」）</span>
        </div>
        <div class="ar-row">
          <span class="ar-lab">預覽</span>
          <span class="ar-prev" id="arPrev">—</span>
        </div>
      </div>
    </div>
  </div>
  <div class="m-foot">
    <button class="btn btn-default btn-sm" data-close="mAsRef">取消</button>
    <button class="btn btn-warning btn-sm" id="btnArInsert" disabled><i class="fa fa-plus"></i> 插入到游標位置</button>
  </div>
</div></div>

<!-- 「名稱＋編號」的排列樣式（限管理員） -->
<div class="m-mask" id="mArStyle"><div class="m-win" style="width:620px;max-width:96vw;">
  <div class="m-head">插入樣式設定 <span class="m-x" data-close="mArStyle">&times;</span></div>
  <div class="m-body">
    <div class="m-note">
      樣板用 <b>{name}</b> 代表文件名稱、<b>{no}</b> 代表文件編號，其餘字元原樣輸出。<br>
      例：<code>{name}　({no})</code> → 人力資源管理程序　(2-MM-01)；<code>{no}{name}</code> → 2-MM-01人力資源管理程序。<br>
      至少要留一種樣式；樣板一定要用到 {name} 或 {no} 其中一個（不然插進去會是一段跟文件無關的死字串）。
    </div>
    <table class="table table-condensed" id="arStyleTbl">
      <thead><tr><th style="width:180px">顯示名稱</th><th>樣板</th><th style="width:60px"></th></tr></thead>
      <tbody></tbody>
    </table>
    <button class="btn btn-default btn-xs" id="btnArStyleAdd"><i class="fa fa-plus"></i> 新增一種</button>
    <div id="arStyleMsg" style="margin-top:8px;font-size:12px;color:#DD5138"></div>
  </div>
  <div class="m-foot">
    <button class="btn btn-default btn-sm" data-close="mArStyle">取消</button>
    <button class="btn btn-warning btn-sm" id="btnArStyleSave"><i class="fa fa-save"></i> 儲存</button>
  </div>
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
    <div id="signRevHost"></div>
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
    <div style="margin-bottom:8px">
      <label style="font-size:13px;color:#6B471A;margin:0 8px 0 0">簽章圖章樣式</label>
      <select id="cfgStampTpl" class="form-control input-sm" style="width:auto;min-width:220px;display:inline-block" data-eg-skip></select>
      <span style="font-size:12px;color:#8A5A2B;margin-left:8px">制修訂紀錄書上三個簽章格用這個樣式（模板在「圖章管理」設計；全站共用一個）</span>
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
      <label style="font-weight:normal;font-size:13px;color:#6B471A;margin:4px 0 0;display:block;">
        <input type="checkbox" id="tplTocFirst"> 目錄頁算第 1 頁（目錄也印頁首，正文從第 2 頁起算）
      </label>
      <div style="margin:6px 0 0;font-size:13px;color:#6B471A;">
        自動抓標題：
        <select id="tplTocDepth" style="height:26px;">
          <option value="0">不自動抓（只收設成「標題 1～4」的段落）</option>
          <option value="1">大標題 ＋ 附件（1. 大標題、附件A）</option>
          <option value="2">大標題 ＋ 第一層子標題 ＋ 附件（含 1.1）</option>
        </select>
      </div>
      <div class="tpl-hint">目錄依正文裡的標題自動產生，不必自己維護。只有<b>一階</b>會用到。「編號＋短標題」（例：2. 組織圖與管理代表派令）會自動抓；含逗號、句號、冒號的敘述句與有縮排的條列不會。附件是「附件A」「附件 B 組織圖」這種獨立短行。被漏掉的，把該段落設成「標題 1～4」就一定會進目錄。不勾選「目錄頁算第 1 頁」時，頁次只算正文（目錄不計頁）。</div>
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
        <select id="stFont" class="st-sel" style="max-width:150px" data-eg-skip
                title="中文字型；頁首的文件編號／文件名稱也會跟著變"></select>
        <span class="st-lab">英數</span>
        <select id="stFontEn" class="st-sel" style="max-width:140px" data-eg-skip
                title="只套在英文、數字與西文標點上，中文仍用左邊那個字型"></select>
        <span class="st-lab">字級</span>
        <select id="stSize" class="st-sel" data-eg-skip>
          <option value="">（跟著內文）</option>
          <option>9pt</option><option>9.5pt</option><option>10pt</option><option>10.5pt</option>
          <option>11pt</option><option>12pt</option><option>14pt</option>
        </select>
        <button type="button" class="st-btn" id="stBold" title="表格文字粗體"><b>B</b></button>
        <span class="st-sp"></span>
        <span class="st-lab">公司名稱</span>
        <select id="stCoMode" class="st-sel" data-eg-skip
                title="頁首公司名稱的排法；同一行時下方的文件名稱會自動變成上下兩格合併並加大">
          <option value="two">英中上下兩行</option>
          <option value="one">同一行（英 中）</option>
        </select>
        <span class="st-lab">頁首字級</span>
        <select id="stHdrSize" class="st-sel" data-eg-skip
                title="頁首那一條（文件編號／文件名稱／頁次／頁版別）的字級；字太長會自動縮小"></select>
        <span class="st-sp"></span>
        <span class="st-lab">制修訂紀錄書</span>
        <select id="stRevCo" class="st-sel" data-eg-skip title="紀錄書最上方公司名稱的字級"></select>
        <select id="stRevTtl" class="st-sel" data-eg-skip title="「文件制修訂紀錄書」標題的字級"></select>
        <button type="button" class="st-btn" id="stRevBold" title="標題粗體"><b>B</b></button>
        <button type="button" class="st-btn" id="stRevUl" title="標題底線"><u>U</u></button>
        <span class="st-lab">預設發行單位</span>
        <select id="stIssue" class="st-sel" data-eg-skip data-eg-filter="輸入部門篩選…"
                title="沒有在「版面設定」另外指定時，紀錄書上的發行單位一律印這個（沒選＝文管中心）"></select>
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
        <span class="st-lab">文字離框線</span>
        <select id="stBodyGap" class="st-sel" data-eg-skip title="每一行第一個字離左右框線幾個空格（至少留 1 個才不會貼著線）">
          <option value="0">0 空格</option><option value="1">1 空格</option><option value="2">2 空格</option>
          <option value="3">3 空格</option><option value="4">4 空格</option><option value="6">6 空格</option>
        </select>
        <select id="stBodyPad" class="st-sel" data-eg-skip title="大框上下與文字之間留多少空白">
          <option value="2mm">2mm</option><option value="3mm">3mm</option>
          <option value="4mm">4mm</option><option value="5mm">5mm</option>
        </select>
      </div>
      <div class="tpl-hint">
        改完按下方「存檔」才會套用到所有文件；預設值與改版前完全相同，沒動過的文件外觀不會變。<br>
        <b>字型</b>會一路套到<b>頁首的文件編號、文件名稱、頁次、頁版別</b>與封面、制修訂紀錄書；
        <b>英數</b>只作用在英文、數字與西文標點（中文仍用左邊的字型），留「跟著中文字型」就是不特別指定。<br>
        <b>頁首字級</b>只管上面那一條（文件編號／文件名稱／頁次／頁版別），與正文字級分開；
        <b>字太長放不下時會自動縮小</b>（縮到 6pt 還放不下才換行），所以調大之後也不會把頁首撐開。<br>
        <b>內容大框</b>打開後，正文會被一個外框框起來，<b>框線與上方頁首同寬、左右自然對齊</b>，
        匯入的內容直接放進這個大框裡。
      </div>
      <div class="st-prev-wrap">
        <div class="tpl-sub" style="margin-bottom:3px">預覽（就是文件上會長的樣子）</div>
        <div id="stPreview" class="st-prev">
          <table class="adt-hdr" id="stPrevTwo">
            <colgroup><col class="adt-c1"><col class="adt-c2"><col class="adt-c3"><col class="adt-c4"></colgroup>
            <tr><td class="adt-hdr-co" rowspan="2" colspan="2">
                  <div class="adt-hdr-coen"><span class="adt-fit">EXCELLENT GEAR TECHNOLOGY CO.,LTD</span></div>
                  <div class="adt-hdr-cozh"><span class="adt-fit">超正齒輪科技有限公司</span></div></td>
                <td class="adt-hdr-k"><span class="adt-fit">文件編號</span></td>
                <td class="adt-hdr-v"><span class="adt-fit">2-DC-01</span></td></tr>
            <tr><td class="adt-hdr-k"><span class="adt-fit">頁　　次</span></td>
                <td class="adt-hdr-v"><span class="adt-fit">1 / 12</span></td></tr>
            <tr><td class="adt-hdr-nk"><span class="adt-fit">文件名稱</span></td>
                <td class="adt-hdr-nm"><span class="adt-fit">文件管理程序</span></td>
                <td class="adt-hdr-k"><span class="adt-fit">頁 版 別</span></td>
                <td class="adt-hdr-v"><span class="adt-fit">2.0</span></td></tr>
          </table>
          <table class="adt-hdr adt-hdr-one" id="stPrevOne" style="display:none">
            <colgroup><col class="adt-c1"><col class="adt-c2"><col class="adt-c3"><col class="adt-c4"></colgroup>
            <tr><td class="adt-hdr-co" colspan="2"><div class="adt-hdr-coone"><span class="adt-fit">EXCELLENT GEAR TECHNOLOGY CO.,LTD　超正齒輪科技有限公司</span></div></td>
                <td class="adt-hdr-k"><span class="adt-fit">文件編號</span></td><td class="adt-hdr-v"><span class="adt-fit">2-DC-01</span></td></tr>
            <tr><td class="adt-hdr-nk adt-hdr-big" rowspan="2"><span class="adt-fit">文件名稱</span></td>
                <td class="adt-hdr-nm adt-hdr-big" rowspan="2"><span class="adt-fit">文件管理程序</span></td>
                <td class="adt-hdr-k"><span class="adt-fit">頁　　次</span></td><td class="adt-hdr-v"><span class="adt-fit">1 / 12</span></td></tr>
            <tr><td class="adt-hdr-k"><span class="adt-fit">頁 版 別</span></td><td class="adt-hdr-v"><span class="adt-fit">2.0</span></td></tr>
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
      <li><b>插入 AS 文件編號／名稱</b>：按工具列的 <span class="hl"><b>＋AS</b></span>。
          可以打編號或名稱搜尋，也可以依部門與階別挑；要帶入<b>名稱＋編號</b>、
          <b>只有名稱</b>（自動用「」框住）還是<b>只有編號</b>自己選，前後綴也可以自己填
          （例如前綴「依」、後綴「辦理」）。<br>
          <span class="hl">插進去的那一段會記住是哪一份文件</span>——那份文件日後改了編號或名稱，
          你打開這份文件時上方會跳出提示，按「檢視並確認」逐處確認後就會一起改掉，
          並<b>自動補一列制修訂紀錄</b>。名稱＋編號的排列樣式由管理員在同一個跳窗的
          「樣式設定」維護。</li>
      <li><b>補流程圖</b>：按「插入流程圖」。<span class="hl">左邊的圖形用拖的拉到畫布上</span>就好
          （點一下也可以），<span class="hl">雙擊圖形直接打字</span>。<br>
          <b>畫線</b>：選「箭頭」或「直線」之後，<span class="hl">點一下起點、再點一下終點</span>就連起來；
          中間想轉幾個彎就多點幾下（折線），最後<b>雙擊或按 Enter</b> 結束。工具會留著，可以一條接一條畫，
          要停止按 <b>V</b>（或再點一下那顆按鈕）。也可以直接由起點拖到終點畫一段。<br>
          <b>線畫好之後選起來</b>：橘色圓點＝拖曳端點或折點，線段中間的空心<b>＋</b>＝在那裡加一個折點；
          工具列另有<b>折點／直角／取直／圓滑</b>四顆鈕（直角＝流程圖最常用的轉折方式，
          圓滑＝通過每個折點的平滑曲線）。<br>
          <b>鍵盤</b>：V 選取、A 箭頭、L 直線、方向鍵微調 1px（按住 Shift 一次 10px）、
          Esc 取消目前選取（<span class="hl">不會關掉視窗</span>）。
          格線吸附預設是關的（要精準擺位時不會被拉走），需要時按「格線」打開。<br>
          流程圖裡面也有 <b>＋AS</b>：先選一個圖形（或文字）再按，文字會插進那個圖形並記住引用的文件；
          那份文件改了之後，下次打開這張流程圖會提示並有「更新引用文字」可以按
          （<span class="hl">流程圖插進文件的是一張圖片，系統沒辦法在後端重畫，所以一定要打開來按一次</span>）。
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
      <li><b>檢視</b>：有 AS 文件檢視權就看得到線上內容。沒有編輯權的人一律是唯讀，
          文件標題列會標<span class="hl">「唯讀」</span>並寫出哪個課室可以改。</li>
      <li><b>編輯</b>：符合下面任一種就能改<span class="hl">這一份</span>文件——
        <ul>
          <li>AS 文件管理員（頁面 A 權）；</li>
          <li>有角色功能碼 <code>編輯線上內容（程序書電子版）</code>（可改全部文件）；</li>
          <li><b>這份文件登記的課室的人</b>——也就是
              <a href="as_document_management.php">AS 文件管理</a> 裡這份文件的
              <span class="hl">所屬部門</span>（編輯資料內）或<span class="hl">負責課室</span>
              （⚙ →「更新頻率 / 負責課室」，可複選）。</li>
        </ul>
        課室是<b>精準比對、不含下轄單位</b>：一階手冊掛的是「總經理室」，
        而總經理室底下就是全公司，連下轄一起算的話等於人人都能改手冊。
        要讓別的課室也能改，請到 AS 文件管理把那個課室<b>加進「負責課室」</b>。</li>
      <li><b>設定</b>（公版表格外觀、簽核設定）：<span class="hl">只有 AS 文件管理員</span>，
          課室成員看不到也改不了。</li>
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
<script src="../../resource/js/eg_doc_fit.js?v=<?= @filemtime(__DIR__.'/../../resource/js/eg_doc_fit.js') ?>"></script>
<!-- 簽章：制修訂紀錄書上的制修訂／審查／核准三格（ai-rules/18 一律走共用圖章元件） -->
<?php
/* 圖章要有本公司名（預設章的上弧、模板章的 {公司}）全靠 window.__ownCompany——漏設＝章上公司名整片消失（2026-10-16 使用者回報）。
   圖章樣式由管理員在「簽核設定」挑，這裡把目前設定的模板 schema 一併送給前端。 */
require_once __DIR__ . '/../../src/common/org_role_lib.php';
require_once __DIR__ . '/../../src/common/as_doc_sign_lib.php';
$__stCo  = eg_company_full_name($db);
$__stTpl = ads_stamp_tpl($db);
?>
<script>window.__ownCompany = <?= json_encode($__stCo, JSON_UNESCAPED_UNICODE) ?>;
window.EG_DOC_STAMP_SCHEMA = <?= json_encode($__stTpl ? $__stTpl['schema'] : null, JSON_UNESCAPED_UNICODE) ?>;</script>
<script src="../../resource/js/eg_stamp_tpl.js?v=<?= @filemtime(__DIR__.'/../../resource/js/eg_stamp_tpl.js') ?>"></script>
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
var LOCKED = <?= ($__locked || !$P['edit']) ? 'true' : 'false' ?>;     // 已正式發行或沒有編輯權（含 ?view=1 唯讀檢視）：畫面照舊、內容唯讀
var CAN_NEW_DRAFT = <?= !empty($P['can_new_draft']) ? 'true' : 'false' ?>;
var IS_DRAFT = <?= $__isDraft ? 'true' : 'false' ?>;
var DRAFT_VID = <?= (int)$__draftVid ?>;
var OFFICIAL_VID = <?= (int)$__officialVid ?>;
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
/* 跳窗標題列可以拖著移動（使用者 2026-10-16：插入 AS 文件那個跳窗要能移開，才看得到底下的文件）。
   全頁的 .m-win 共用同一支：每次打開都先回到置中，不沿用上一次被拖走的位置
   （換了螢幕或改了瀏覽器大小，沿用舊座標會開在畫面外面）。 */
function openMask(id) {
    var m = document.getElementById(id);
    var w2 = m && m.querySelector('.m-win');
    if (w2) { w2.style.left = ''; w2.style.top = ''; w2.style.transform = ''; }
    m.classList.add('open');
}
(function () {
    var drag = null;
    document.addEventListener('mousedown', function (e) {
        var hd = e.target.closest && e.target.closest('.m-win > .m-head');
        if (!hd || e.target.closest('.m-x') || e.button !== 0) return;
        var win = hd.parentNode, r = win.getBoundingClientRect();
        win.style.left = r.left + 'px'; win.style.top = r.top + 'px'; win.style.transform = 'none';
        drag = { win: win, dx: e.clientX - r.left, dy: e.clientY - r.top };
        hd.style.cursor = 'move';
        e.preventDefault();
    });
    document.addEventListener('mousemove', function (e) {
        if (!drag) return;
        var x = e.clientX - drag.dx, y = e.clientY - drag.dy;
        // 標題列至少留一截在畫面內，不然拖出去就找不回來
        x = Math.max(60 - drag.win.offsetWidth, Math.min(window.innerWidth - 60, x));
        y = Math.max(0, Math.min(window.innerHeight - 30, y));
        drag.win.style.left = x + 'px'; drag.win.style.top = y + 'px';
    });
    document.addEventListener('mouseup', function () { drag = null; });
})();
function closeMask(id) { document.getElementById(id).classList.remove('open'); }
$(document).on('click', '[data-close]', function(){ closeMask($(this).data('close')); });
$('#btnPageHelp').on('click', function(){ openMask('helpUseMask'); });
$('#adReportX').on('click', function(){ $('#adReport').hide(); });

/* 回到頂端：內容一多捲下去就看不到上方工具列與存檔鈕（使用者 2026-09-24 交辦） */
$(window).on('scroll', function(){ $('#btnBackTop').toggleClass('show', $(window).scrollTop() > 300); });
$('#btnBackTop').on('click', function(){ $('html,body').animate({ scrollTop: 0 }, 200); });

/* 快速跳到大標題／附件：滑鼠移到右下圖示時才掃描正文（81 頁掃一次很快，平常不花成本）。
   「什麼算大標題」與目錄的自動抓取同一套規則（as_doc_tpl_lib.php 的 adt_toc_auto_level，第 1 層＋附件），
   手動設成「標題 1」的段落一律收。 */
var QNAV_ITEMS = [];
function qnavIsTop(el, inTbl) {
    var t = (el.textContent || '').replace(/\s+/g, ' ').trim();
    if (!t || t.length > 40) return false;
    if (/^(?:附件|附[錄\uF900-\uFAFF])\s*[A-Za-zＡ-Ｚ0-9０-９一二三四五六七八九十]{1,3}(?![A-Za-z0-9])(?!\s*用)/.test(t) && !/[，。；;,]/.test(t)) return true;
    if (inTbl) return false;                                         // 表格裡只收附件格式
    if (/[，。；：:;,]/.test(t)) return false;
    var m = /^(\d{1,2}(?:\.\d{1,2}){0,2})(\.?)\s*(\S.*)$/.exec(t);
    if (!m || m[1].indexOf('.') >= 0) return false;                 // 只要大標題（1. 這種），不要 1.1
    if (el.style && parseFloat(el.style.marginLeft) > 0) return false; // 有縮排的是條列
    if (m[2] !== '.') {
        return /^\d{1,2}\s+/.test(t) && m[3].length <= 15 && /^[\u4e00-\u9fff]/.test(m[3]) && !/^(?:年|月|日|件|個|項|次|天|人|頁|%)/.test(m[3]);
    }
    return t.length <= 30;
}
function qnavBuild() {
    QNAV_ITEMS = [];
    var $p = $('#adQnavPanel').empty();
    document.querySelectorAll('.egrt-main h1, .egrt-main p').forEach(function(el){
        var inTbl = !!el.closest('table');
        if (el.tagName === 'H1' ? !inTbl : qnavIsTop(el, inTbl)) {
            var t = (el.textContent || '').replace(/\s+/g, ' ').trim();
            if (t) QNAV_ITEMS.push({ el: el, t: t });
        }
    });
    if (!QNAV_ITEMS.length) {
        $p.append('<div class="ad-qnav-empty">還沒有大標題。把段落設成「標題 1」，或使用「1. 標題」「附件A」這種寫法。</div>');
        return;
    }
    QNAV_ITEMS.forEach(function(it, i){
        $p.append($('<a>').attr({ 'data-i': i, title: it.t }).text(it.t));
    });
}
$('.ad-qnav').on('mouseenter', qnavBuild);
$('#adQnavPanel').on('click', 'a', function(){
    var it = QNAV_ITEMS[+$(this).attr('data-i')];
    if (!it || !it.el.isConnected) { qnavBuild(); return; }
    it.el.scrollIntoView({ behavior: 'smooth', block: 'center' });
});
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

/* ── 表格整理（2026-10-27）──────────────────────────────────────────────
   ① 過長的表格自動分割：把編輯器排版時自動拆出來的「續表」固定成一般的獨立表格
      （編輯器原本只在畫面上拆、存檔時又接回一張——但列印是照存檔的內容印，整張表一頁放不下就被切成兩張紙、框線沒到底）。
      續表沿用欄寬與表頭，之後就是一張張獨立的表，每張都放得進一頁。
   ② 分割出來的每一張續表，上方留一行空白段落（要補標題才有地方打字）。
   ③ 表格比頁寬還寬就等比縮小表內字級，直到放得進頁寬。
   載入排版穩定後、匯入完成後各跑一次（會自動重排並重複到沒有新的續表為止）；有動就標成未存檔。 */
function tblTidy(done) {
    var total = 0, pass = 0, SEL = '#adEditorHost .egrt-main ';
    (function step(){
        var n = 0, conts = [];
        // ⓪ 清掉先前版本誤加的「強制換頁空行」（樣式剛好只有 page-break-before:always、內容完全空白的段落）：
        //    它們把每個表格硬釘在頁首，也會讓在附近按 Enter 時整頁內容被推到下一頁
        if (pass === 0) Array.prototype.slice.call(document.querySelectorAll(SEL + '> p')).forEach(function(p){
            if ((p.getAttribute('style') || '').replace(/\s+/g, '') === 'page-break-before:always;' || (p.getAttribute('style') || '').replace(/\s+/g, '') === 'page-break-before:always') {
                if ((p.textContent || '').replace(/[\s 　]/g, '') === '' && !p.querySelector('img,table')) { p.parentNode.removeChild(p); n++; }
            }
        });
        // ① 固定續表
        var frozen = [];
        Array.prototype.slice.call(document.querySelectorAll(SEL + 'table[data-egrt-cont]')).forEach(function(c){
            // 有表頭列（thead）的長表格不固定：它每頁自己重複表頭、存檔時接回一張，下次載入再依紙張重拆；
            // 固定下來反而會讓每一段各自多一份表頭而溢出、一路連鎖多拆出一堆只有一兩列的小表
            if (c.tHead) return;
            var id = c.getAttribute('data-egrt-split');
            frozen.push(c);
            c.removeAttribute('data-egrt-cont'); c.removeAttribute('data-egrt-split');
            var cb = c.closest('.egrt-main > *'); if (cb) conts.push(cb);
            if (id) Array.prototype.forEach.call(document.querySelectorAll(SEL + 'table[data-egrt-split="' + id + '"]'), function(o){ frozen.push(o); o.removeAttribute('data-egrt-split'); });
            n++;
        });
        // 固定下來的續表，跨頁補的空白格（data-egrt-fill）與被截短的合併格（data-egrt-rs0）從此就是正式的格子，把暫時記號拿掉
        frozen.forEach(function(t){
            Array.prototype.forEach.call(t.querySelectorAll('[data-egrt-fill],[data-egrt-rs0]'), function(e){
                e.removeAttribute('data-egrt-fill'); e.removeAttribute('data-egrt-rs0');
            });
        });
        apxSheets().forEach(function(sh){
            // ② 剛分割出來的續表（一定接在「已經填滿的一頁」後面，空行不會被拉回上一頁）上方補一行空白
            conts.forEach(function(blk){
                if (blk.parentNode !== sh) return;
                var p = blk.previousElementSibling;
                if (p && /^(P|DIV|H[1-6])$/.test(p.tagName)) return;
                var np = document.createElement('p'); np.innerHTML = '<br>';
                sh.insertBefore(np, blk); n++;
            });
            // ③ 超寬表格縮字級
            var cs = getComputedStyle(sh), inner = sh.clientWidth - (parseFloat(cs.paddingLeft) || 0) - (parseFloat(cs.paddingRight) || 0);
            if (!(inner > 100)) return;
            Array.prototype.forEach.call(sh.querySelectorAll('table'), function(t){
                if (t.parentElement && t.parentElement.closest('table')) return;
                var guard = 0, did = false;
                while (t.getBoundingClientRect().width > inner + 2 && guard++ < 8) {
                    var any = false;
                    Array.prototype.forEach.call(t.querySelectorAll('[style*="font-size"]'), function(e){
                        var m = /font-size:\s*([\d.]+)pt/.exec(e.getAttribute('style') || '');
                        if (!m) return;
                        var v = parseFloat(m[1]), nv = Math.max(6, Math.round(v * 0.92 * 2) / 2);
                        if (nv < v) { e.style.fontSize = nv + 'pt'; any = true; }
                    });
                    if (!any) break;
                    did = true;
                }
                if (did) n++;
            });
        });
        total += n;
        if (n && pass++ < 6) { try { ED.reflowQuiet(); } catch (e) {} setTimeout(step, 600); }
        else if (typeof done === 'function') done(total);
    })();
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
    if (LOCKED) ED.setReadOnly(true);          // 先設唯讀，後面重排產生的新頁才不會變成可編輯
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
        waitLayout(function(){ ED.autoNumIndent(); tblTidy(function(){ save(); }); });
    } else {
        /* 排版穩定後自動排一次分頁（使用者 2026-10-16：載入後文字會被頁框擋住，每次都要自己按「自動分頁」）。
           只有排完內容真的變了才標成「有未存檔的變更」，沒變就不打擾。 */
        waitLayout(function(){
            var before = ED.get();
            try { ED.reflowQuiet(); } catch (e) {}
            if (!LOCKED) tblTidy(function(n){ if (n) setDirty(true); });
            /* 空白差異不算改；但分頁位置變了一定要算「有改」——列印是照「存檔的分頁」印的，
               長表格在編輯器裡被拆到好幾頁，沒存的話列印還是整張表一頁、就會超出一張紙 */
            var unpg = function(h){ return String(h).replace(/\s+/g, ''); };
            if (!LOCKED && unpg(ED.get()) !== unpg(before)) setDirty(true);
        });
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
                if (window.EGDocFit) EGDocFit.runWhenReady(host);
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
        var hits = 0, obs = 0, ren = 0, flows = 0;
        REF.forEach(function(x){
            hits += (x.hits || 0);
            flows += (x.flows || 0);
            if (x.kind === 'obsolete') obs++;
            if (x.kind === 'rename') ren++;
        });
        $('#adRefSum').text('：' + REF.length + ' 筆、共 ' + hits + ' 處'
            + (ren ? ('（其中 ' + ren + ' 筆是改名）') : '')
            + (obs ? ('（其中 ' + obs + ' 個是廢止，系統不會自動改）') : '')
            + (flows ? ('，另有 ' + flows + ' 張流程圖也引用了它') : ''));
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
var head;
        if (x.kind === 'obsolete') {
            head = '<span class="adr-warn">' + esc(x.old_no) + '</span> 已廢止';
        } else if (x.kind === 'rename') {
            head = '文件名稱 <span class="adr-old">' + esc(x.old_name || '') + '</span>'
                 + ' → <span class="adr-new">' + esc(x.new_name || '') + '</span>'
                 + '　<span style="color:#8a6d45;font-size:12px;">(' + esc(x.old_no || '') + ')</span>';
        } else {
            head = '<span class="adr-old">' + esc(x.old_no) + '</span> → <span class="adr-new">' + esc(x.new_no) + '</span>';
        }
        var hits = (x.hits_list || []).map(function(t){
            // 改名的命中是「整段參照標記」，沒有前後文，直接把新舊兩段字列出來比較清楚
            if (!t.before && !t.after) {
                return '<div class="adr-hit"><b>正文第 ' + t.page + ' 頁</b>　'
                     + '<span class="adr-old">' + esc(t.old) + '</span> → '
                     + '<span class="adr-new">' + esc(t.new || '') + '</span></div>';
            }
            return '<div class="adr-hit"><b>正文第 ' + t.page + ' 頁</b>　…'
                 + esc(t.before) + '<b>' + esc(t.old) + '</b>' + esc(t.after) + '…</div>';
        }).join('');
        /* 流程圖不能自動改（插進文件的是一張 PNG，換字要重新產生那張圖，後端沒有瀏覽器做不到），
           所以這裡明講「請打開那張流程圖按更新引用文字」——不講的話使用者會以為套用就全部處理好了。 */
        if (x.flows) {
            hits += '<div class="adr-hit" style="background:#FDF0DC;border-color:#E8C89A;">'
                 + '<b>另有 ' + x.flows + ' 張流程圖引用了它</b>　'
                 + '流程圖插進文件的是一張圖片，系統沒辦法在後端重畫，'
                 + '請在內文點那張流程圖 →「編輯流程圖」，打開時會自動提示並有「更新引用文字」可以按。</div>';
        }
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
/* 連雙引號一起跳脫——原本走 jQuery 的 text→html，那個**不跳脫雙引號**，
   拿去拼 value="…" 時只要值本身含引號就會被截斷（2026-10-15 的公版字型就是這樣
   被存成空字串）。屬性值仍然建議直接用 DOM 設定，這裡是最後一道保險。 */
function esc(s) {
    return String(s == null ? '' : s)
        .replace(/&/g, '&amp;').replace(/"/g, '&quot;')
        .replace(/</g, '&lt;').replace(/>/g, '&gt;');
}

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
        if (r.message && Number(r.moved) === 1) alert(r.message);
        if (r.version_id && Number(r.version_id) !== Number(VID)) { location.href = 'as_doc_editor.php?version_id=' + r.version_id; return; }   // 自動補了新版次列，換到新網址
        // 簽完要重畫制修訂紀錄書上的章。沒有編輯權的人（被指派的簽核人多半就是）
        // 走的是唯讀渲染那一條路，loadChrome() 對他們沒有作用，要整個重載才會更新。
        if (CAN_EDIT) loadChrome(); else load();
        loadSign(function(){ $('#adSaved').text(r.message || '已簽核'); });
    }, 'json');
}
$('#btnSignOk').on('click', function(){ signDecide(true); });
$('#btnSignReject').on('click', function(){ signDecide(false); });

/** 送簽視窗內容。管理員改了「制修訂」的人，其他跟著他算的關卡由後端重算後整個重畫（draftSel＝目前選的人） */
function renderSignPlan(p, draftSel) {
    var h = '';
    /* 使用者 2026-10-15：「請檢查最後修改者是否為此 AS 文件之負責部門人員或是此 AS 文件之部門內人員」。
       刻意**只提醒不擋下**——臨時請別的單位的人代改就送不出去了，要不要照簽由簽核人判斷。 */
    if (p.editor_warn) {
        h += '<div class="alert alert-warning" style="padding:8px 10px;font-size:12px;margin-bottom:8px;">'
           + '<b><i class="fa fa-exclamation-triangle"></i> 請確認：</b>' + esc(p.editor_warn) + '</div>';
    }
    p.steps.forEach(function(s){
        h += '<div class="sg-pick" data-cfg="' + s.cfg_id + '">'
           + '<div class="sg-h">' + s.seq + '. ' + esc(s.stage_name)
           + (s.auto_sign ? ' <span style="color:#8A5A2B;font-weight:normal">（自動簽核）</span>' : '') + '</div>';
        if (!s.candidates.length) {
            h += '<div class="sg-f" style="color:#DD5138">'
               + (s.mode === 'editor_sup'
                  ? '解析不到最後修改人的單位主管（他可能自己就是課級最高主管），請改選別的來源或指定人員。'
                  : s.mode === 'doc_dept_sup'
                  ? '解析不到這份文件所屬部門的單位主管：文件還沒登記所屬部門／負責課室，或該部門沒有設定主管職級的在職人員。'
                  : s.mode === 'doc_owner'
                  ? '這份文件的負責課室還沒指派 AS 文件部門負責人（到「AS 文件管理 → 部門負責人」設定）。'
                  : '管理員設定的部門與職稱目前沒有在職人員，送不出去。') + '</div>';
        } else if ((s.fixed || s.auto_sign) && !(s.stage === 'draft' && !s.fixed)) {   // 管理員的「制修訂」即使是自動簽核也要能改人
            var c0 = s.candidates[0];
            h += '<div class="sg-f">' + esc(c0.name) + '　' + esc(c0.dept) + ' ' + esc(c0.pos)
               + (s.mode === 'submitter' ? '（送審的人）'
                  : s.mode === 'doc_dept_sup' ? '（文件所屬部門的單位主管）'
                  : s.mode === 'editor' ? '（內容的最後修改人）'
                  : s.mode === 'editor_sup' ? '（最後修改人的單位主管）'
                  : s.mode === 'doc_owner' ? '（AS 文件部門負責人）' : '') + '</div>';
        } else {
            h += '<select class="form-control input-sm sg-sel" data-eg-filter="輸入姓名篩選…">'
               + s.candidates.map(function(c2){
                   return '<option value="' + esc(c2.key || c2.id) + '">' + esc(c2.dept) + '　' + esc(c2.pos) + '　' + esc(c2.name) + '</option>';
                 }).join('') + '</select>';
        }
        h += '</div>';
    });
    $('#signPickBody').html(h);
    $('#signPickBody .sg-pick').each(function(){
        var cfg = $(this).data('cfg');
        p.steps.forEach(function(s){
            if (String(s.cfg_id) === String(cfg) && s.stage === 'draft' && draftSel) $('#signPickBody .sg-pick[data-cfg="' + cfg + '"] .sg-sel').val(String(draftSel));
        });
    });
}
$('#signPickBody').on('change', '.sg-pick .sg-sel', function(){
    var $pk = $(this).closest('.sg-pick');
    var p0 = SIGN && SIGN.plan, isDraft = false;
    (p0 && p0.steps || []).forEach(function(s){ if (String(s.cfg_id) === String($pk.data('cfg')) && s.stage === 'draft') isDraft = true; });
    if (!isDraft) return;
    var uid = $(this).val();
    $('#signMsg').text('依新的制修訂人員重新計算其他關卡…');
    $.getJSON(API, { action:'sign_state', version_id: VID, draft_uid: uid }, function(r){
        $('#signMsg').text('');
        if (!r.success || !r.plan || !r.plan.ok) { alert((r.plan && r.plan.msg) || r.message || '重新計算失敗'); return; }
        SIGN.plan = r.plan;
        renderSignPlan(r.plan, uid);
    });
});

/* ── 送簽時確認「制修訂紀錄」要列什麼（2026-10-16 使用者交辦）──────────────────────
   簽核完成後系統會自動新增一列制修訂紀錄；改很多條時由送簽的人勾選要列的項目、
   選要不要只列條文編號（不列內容）、也可以直接改摘要文字。
   系統無法判斷是大改版還是小改版時（沒有上一次簽核通過的內容可以比對）一定要使用者自己選，不預設。 */
var REV = null;
var REV_W = { '增': '新增', '改': '修改', '減': '刪除' };
function revSelected() { return REV.data.items.filter(function(it, i){ return REV.checked[i]; }); }
function revSummaryText() {
    var items = revSelected(), parts = [];
    ['增', '改', '減'].forEach(function(k){
        var seen = {}, names = [];
        items.filter(function(it){ return it.kind === k; }).forEach(function(it){
            var t = String(REV.numOnly ? (it.num || String(it.label || '').slice(0, 10)) : it.label).trim();
            if (t && !seen[t]) { seen[t] = 1; names.push(t); }
        });
        if (names.length) parts.push(REV_W[k] + names.join('、'));
    });
    return parts.join('；');
}
/* 制修訂頁次（頁版別就是依它跳版）：全冊＝每一頁都換成新版別；指定頁＝只換勾選的那幾頁。
   系統比對得出變動頁就先帶入，比對不出來（沒有上一次內容可比）一定要自己選，不預設。 */
function revPagesText() {
    if (REV.pagesMode === 'all') return '全冊';
    if (REV.pagesMode !== 'pick') return '';
    return Object.keys(REV.pagePick).filter(function(k){ return REV.pagePick[k]; }).map(Number).sort(function(a, b){ return a - b; }).join(',');
}
function revPagesFromItems() {
    var pg = {};
    revSelected().forEach(function(it){ pg[it.page] = true; });
    return pg;
}
function revAutoKind() { return revSelected().length > REV.data.minor_max ? 'major' : 'minor'; }
function revPaint() {
    var d = REV.data, h = '<div class="sg-pick" style="margin-top:6px;border-color:#E8890C">'
      + '<div class="sg-h">制修訂紀錄（簽核完成後自動新增一列）</div>'
      + (CAN_ADMIN ? '<div class="sg-f" style="margin-bottom:6px"><b>制修訂日期</b>（管理員可改；留空＝今天）：<input type="date" id="revDate" class="form-control input-sm" style="display:inline-block;width:auto" value="' + esc(REV.date || '') + '"></div>' : '');
    if (!d.baseline) {
        h += '<div class="sg-f" style="color:#DD5138;margin-bottom:6px">這份文件還沒有「上一次簽核通過的內容」可以比對，'
           + '系統無法判斷大小改版與修訂項目，請自己選擇並填寫摘要。</div>';
    } else if (!d.items.length) {
        h += '<div class="sg-f" style="margin-bottom:6px">與上一次簽核通過的內容相比，沒有條文變動。</div>';
    } else {
        h += '<div class="sg-f" style="margin-bottom:4px">勾選要列在制修訂紀錄的項目（共 ' + d.items.length + ' 處）：</div>'
           + '<div style="max-height:150px;overflow:auto;border:1px solid #e4d3ba;border-radius:3px;padding:4px 8px;background:#fff">';
        d.items.forEach(function(it, i){
            h += '<label style="display:block;margin:1px 0;font-weight:normal;font-size:12px"><input type="checkbox" class="rev-chk" data-i="' + i + '"'
               + (REV.checked[i] ? ' checked' : '') + '> <b>' + esc(REV_W[it.kind] || it.kind) + '</b> '
               + esc(it.num || '') + ' <span style="color:#8A5A2B">' + esc(String(it.label || '').replace(/^[\d.\s]+/, '')) + '</span>　<span style="color:#aaa">第' + it.page + '頁</span></label>';
        });
        h += '</div>'
           + '<label style="display:block;margin:6px 0 2px;font-weight:normal;font-size:12px"><input type="checkbox" id="revNumOnly"'
           + (REV.numOnly ? ' checked' : '') + '> 只列修改／刪除的條文編號，不列內容</label>';
    }
    var pc = d.page_count || 1;
    h += '<div class="sg-f" style="margin:6px 0 2px"><b>制修訂頁次</b>（頁版別會依這裡自動跳版）：'
       + ' <label style="font-weight:normal"><input type="radio" name="revPgMode" value="all"' + (REV.pagesMode === 'all' ? ' checked' : '') + '> 全冊（共 ' + pc + ' 頁都換新版別）</label>'
       + '　<label style="font-weight:normal"><input type="radio" name="revPgMode" value="pick"' + (REV.pagesMode === 'pick' ? ' checked' : '') + '> 指定頁</label></div>';
    if (REV.pagesMode === 'pick') {
        h += '<div class="sg-f" style="max-height:90px;overflow:auto;border:1px solid #e4d3ba;border-radius:3px;padding:4px 8px;background:#fff">';
        for (var pi = 1; pi <= pc; pi++) {
            h += '<label style="display:inline-block;margin:1px 8px 1px 0;font-weight:normal;font-size:12px"><input type="checkbox" class="rev-pg" data-p="' + pi + '"'
               + (REV.pagePick[pi] ? ' checked' : '') + '> 第' + pi + '頁</label>';
        }
        h += '</div>';
    }
    if (!REV.pagesMode) h += '<div class="sg-f" style="color:#DD5138">系統無法判斷哪幾頁有修改，請選「全冊」或「指定頁」。</div>';
    h += '<div class="sg-f" style="margin:6px 0 2px">這次是：'
       + ' <label style="font-weight:normal"><input type="radio" name="revKind" value="major"' + (REV.kind === 'major' ? ' checked' : '') + '> 大改版（' + esc(d.cur) + ' → <b>' + esc(d.major_ver) + '</b>）</label>'
       + '　<label style="font-weight:normal"><input type="radio" name="revKind" value="minor"' + (REV.kind === 'minor' ? ' checked' : '') + '> 小改版（→ <b>' + esc(d.minor_ver) + '</b>）</label>'
       + '　<label style="font-weight:normal"><input type="radio" name="revKind" value="none"' + (REV.kind === 'none' ? ' checked' : '') + '> 不新增制修訂紀錄</label></div>'
       + '<div class="sg-f" style="color:#8A5A2B">' + (d.judgeable ? ('修訂 ' + d.minor_max + ' 處以下算小改版，超過算大改版；系統先依勾選的項目數幫你選好，可以改。')
           : '<span style="color:#DD5138">系統無法判斷，請自己選。</span>') + '</div>'
       + '<div class="sg-f" style="margin:6px 0 2px">制修訂摘要（可直接修改文字）　<a href="#" id="revRegen" style="font-size:12px">依勾選重新產生</a></div>'
       + '<textarea id="revSum" class="form-control" rows="3" style="font-size:13px"></textarea></div>';
    $('#signRevHost').html(h);
    $('#revSum').val(REV.summary);
}
function revRefresh() {
    if (!REV.manual) REV.summary = revSummaryText();
    if (!REV.pagesTouched) {
        if (REV.data.items.length) { REV.pagesMode = 'pick'; REV.pagePick = revPagesFromItems(); }
        else if (REV.kind === 'major') { REV.pagesMode = 'all'; }
    }
    if (!REV.kindTouched && REV.data.judgeable) REV.kind = REV.data.items.length ? revAutoKind() : 'none';
    revPaint();
}
function revLoad(cb) {
    REV = null; $('#signRevHost').empty();
    $.getJSON(API, { action:'sign_rev_preview', version_id: VID }, function(r){
        if (!r || !r.success) { cb && cb(); return; }          // 取不到就維持舊流程（簽完自動判斷）
        REV = { data: r, checked: r.items.map(function(){ return true; }), numOnly: true, manual: false, kindTouched: false,
                kind: '', summary: '', pagesMode: '', pagePick: {}, pagesTouched: false };
        REV.summary = r.items.length ? revSummaryText() : '';
        REV.kind = r.judgeable ? (r.items.length ? revAutoKind() : 'none') : '';
        revRefresh();
        cb && cb();
    });
}
$('#signRevHost').on('change', '.rev-chk', function(){ REV.checked[+$(this).data('i')] = $(this).prop('checked'); revRefresh(); });
$('#signRevHost').on('change', '#revNumOnly', function(){ REV.numOnly = $(this).prop('checked'); REV.manual = false; revRefresh(); });
$('#signRevHost').on('click', '#revRegen', function(e){ e.preventDefault(); REV.manual = false; revRefresh(); });
$('#signRevHost').on('input', '#revSum', function(){ REV.manual = true; REV.summary = $(this).val(); });
$('#signRevHost').on('change', 'input[name=revPgMode]', function(){ REV.pagesTouched = true; REV.pagesMode = $(this).val(); if (REV.pagesMode === 'pick' && !Object.keys(REV.pagePick).length) REV.pagePick = revPagesFromItems(); revPaint(); });
$('#signRevHost').on('change input', '#revDate', function(){ REV.date = $(this).val(); });
$('#signRevHost').on('change', '.rev-pg', function(){ REV.pagesTouched = true; REV.pagePick[+$(this).data('p')] = $(this).prop('checked'); });
$('#signRevHost').on('change', 'input[name=revKind]', function(){ REV.kindTouched = true; REV.kind = $(this).val(); revRefresh(); });

$('#btnNewDraft').on('click', function(){
    if (!confirm('要修訂這份已正式發行的文件嗎？\n\n會複製一份「新版草稿」讓你編輯，正式版維持原狀；草稿簽核並發行之後，才會取代正式版、並自動新增制修訂紀錄。')) return;
    var $b = $(this).prop('disabled', true);
    $.post(API, { action:'sign_start_draft', csrf:CSRF, version_id:VID }, function(r){
        $b.prop('disabled', false);
        if (!r.success) { alert(r.message || '建立草稿失敗'); return; }
        location.href = 'as_doc_editor.php?version_id=' + r.version_id;
    }, 'json');
});
$('#btnDiscardDraft').on('click', function(){
    if (!confirm('確定放棄這份草稿嗎？\n草稿的內容會整份刪除（正式版不受影響），無法復原。')) return;
    $.post(API, { action:'sign_discard_draft', csrf:CSRF, version_id:VID }, function(r){
        if (!r.success) { alert(r.message || '放棄失敗'); return; }
        location.href = OFFICIAL_VID > 0 ? ('as_doc_editor.php?version_id=' + OFFICIAL_VID) : 'as_document_management.php';
    }, 'json');
});
$('#btnSignSubmit').on('click', function(){
    if (DIRTY && !confirm('有未存檔的變更，送審的是「上次存檔」的內容。\n要繼續送審嗎？（建議先按存檔）')) return;
    loadSign(function(){
        var p = SIGN.plan;
        if (!p || !p.ok) {
            alert((p && p.msg ? p.msg : '無法送審') +
                  (p && p.need_cfg ? '\n\n請管理員先到工具列的「簽核設定」設定制修訂／審查／核准由誰簽。' : ''));
            return;
        }
        renderSignPlan(p, 0);
        $('#signMsg').text('');
        revLoad();
        openMask('mSign');
    });
});
$('#btnSignGo').on('click', function(){
    var picks = {};
    $('#signPickBody .sg-pick').each(function(){
        var cfg = $(this).data('cfg'), v = $(this).find('.sg-sel').val();
        if (v) picks[cfg] = v;
    });
    var post = { action:'sign_submit', csrf:CSRF, version_id:VID, picks: JSON.stringify(picks) };
    if (REV) {
        if (!REV.kind) { alert('請先確認這次是大改版、小改版，或不新增制修訂紀錄。'); return; }
        var sum = $('#revSum').val() || '';
        if (REV.kind !== 'none' && !sum.trim()) { alert('制修訂摘要不能空白：請勾選要列的項目，或直接填寫摘要。'); $('#revSum').focus(); return; }
        if (REV.kind !== 'none' && !revPagesText()) { alert('請選擇制修訂頁次：全冊，或勾選有修改的頁。'); return; }
        if (CAN_ADMIN && REV.date) post.submit_date = REV.date;
        post.rev_kind = REV.kind; post.rev_summary = sum;
        post.rev_pages = revPagesText(); post.rev_hunks = revSelected().length;
    }
    var $b = $(this).prop('disabled', true);
    $('#signMsg').text('送出中…');
    $.post(API, post, function(r){
        $b.prop('disabled', false); $('#signMsg').text('');
        if (!r.success) { alert(r.message || '送審失敗'); return; }
        closeMask('mSign');
        // 簽核完成時系統自動補了一列制修訂紀錄（版次被搬到新的版次列）→ 換到新版次的網址，否則畫面還停在舊版次
        if (r.version_id && Number(r.version_id) !== Number(VID)) { location.href = 'as_doc_editor.php?version_id=' + r.version_id; return; }
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
        alert(r.message || r.msg || '已正式發行');
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
    /* 這三種都是系統算出來的唯一人選，沒有東西要挑——
       沒有列出來的話會掉進最後那個 else 顯示部門＋職稱挑選器，
       使用者會以為要選部門（選了也不會被用到）。 */
    if (mode === 'submitter') {
        h = '<span style="font-size:12px;color:#8A5A2B">由系統自動判定（按下送簽的那個人）；'
          + '頁面管理員送簽時可以改成別人（補歷史單據、代為送審）</span>';
    } else if (mode === 'doc_dept_sup') {
        h = '<span style="font-size:12px;color:#8A5A2B">由系統自動判定（這份文件所屬部門與負責課室的單位主管，'
          + '跟誰改的無關；文件有好幾個負責課室時，送簽時從各課室主管裡挑）</span>';
    } else if (mode === 'editor') {
        h = '<span style="font-size:12px;color:#8A5A2B">由系統自動判定（這一版內容的最後修改人）</span>';
    } else if (mode === 'editor_sup') {
        h = '<span style="font-size:12px;color:#8A5A2B">由系統自動判定（最後修改人的單位主管；'
          + '往上只追到「課」級，課級最高主管自己改的就從缺）</span>';
    } else if (mode === 'doc_owner') {
        h = '<span style="font-size:12px;color:#8A5A2B">由系統自動判定（這份文件負責課室的 AS 文件部門負責人；'
          + '他不在職或已調離時自動改用該部門的代理人）</span>';
    } else if (mode === 'user') {
        h = '<select class="form-control input-sm cfg-user" data-eg-filter="輸入姓名篩選…">'
          + (function(){
              var got = false;     // 同一個人兼任好幾個職務會有好幾列，只讓第一列帶 selected
              return CFG.people.map(function(p){
                var sel = (!got && Number(r.user_id) === p.id); if (sel) got = true;
                return '<option value="' + p.id + '"' + (sel ? ' selected' : '') + '>'
                     + esc(p.dept) + '　' + esc(p.pos) + '　' + esc(p.name) + '</option>';
              }).join('');
            })() + '</select>';
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
        $('#cfgStampTpl').html('<option value="0">預設圖章（上弧本公司名＋姓名＋日期）</option>'
            + (r.stamp_tpls || []).map(function(t){
                return '<option value="' + t.id + '"' + (Number(r.stamp_tpl_id) === Number(t.id) ? ' selected' : '') + '>'
                     + esc((t.type_name ? t.type_name + '／' : '') + t.name) + '</option>';
              }).join(''));
        if ($('#cfgScope option').length < 2 && TPL) {
            $('#cfgScope').append('<option value="' + (TPL.cfg && TPL.dept_id ? TPL.dept_id : 0) + '"></option>');
        }
        $('#cfgScopeNote').text(Number(deptId) > 0
            ? (r.scope_is_own ? '這個部門有自己的設定' : '這個部門目前沿用全站預設，存檔後就會變成它自己的設定')
            : '沒有自己設定的部門都會用這一組');
        // 還沒設定過時帶這一組預設；審查＝最後修改人的單位主管（使用者 2026-10-15 指定）
        var rows = r.rows.length ? r.rows : [
            { stage:'draft',   mode:'submitter',    auto_sign:0 },
            { stage:'review',  mode:'doc_dept_sup', auto_sign:0 },
            { stage:'approve', mode:'dept_pos',   auto_sign:0 }
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
                  stamp_tpl_id: $('#cfgStampTpl').val() || 0, rows: JSON.stringify(rows) }, function(r){
        $b.prop('disabled', false); $('#cfgMsg').text('');
        if (!r.success) { alert(r.message || '存檔失敗'); return; }
        closeMask('mSignCfg');
        // 圖章樣式換了：已經畫好的章要清掉重畫，不然畫面上還是舊樣式
        window.EG_DOC_STAMP_SCHEMA = r.stamp_schema || null;
        $('#adEditorHost td[data-sign][data-stamped]').removeAttr('data-stamped');
        loadChrome(function(){ if (window.egDocStamps) egDocStamps(document.getElementById('adEditorHost')); });
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
    $('#tplTocFirst').prop('checked', String(cfg.toc_first) === '1');
    $('#tplTocDepth').val(String(cfg.toc_auto_depth === undefined ? 2 : Math.min(2, cfg.toc_auto_depth)));
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
    /* ⚠ 一定要用 DOM 設 value，不可以拼 HTML 字串：
       字型的值本身就含雙引號（"標楷體","DFKai-SB",serif），而 esc() 是
       jQuery 的 text→html，**不跳脫雙引號**，拼進 value="…" 會在第一個引號就被截斷，
       option 的 value 變成空字串 → 選了標楷體卻存進一個空值，
       文件不會變、重開設定又跳回預設（使用者 2026-10-15 回報）。 */
    function fill($el, map, cur) {
        var el = $el[0];
        if (!el) return;
        el.innerHTML = '';
        Object.keys(map).forEach(function (k) {
            var op = document.createElement('option');
            op.value = k;
            op.textContent = map[k];
            if (k === String(cur)) op.selected = true;
            el.appendChild(op);
        });
    }
    fill($('#stFont'), o.fonts, s.tbl_font || '');
    fill($('#stFontEn'), o.fonts_en || {}, s.tbl_font_en || '');
    fill($('#stBStyle'), o.borders, s.brd_style || 'solid');
    fill($('#stBW'), o.widths, s.brd_w || '1px');
    fill($('#stBColor'), o.colors, s.brd_color || '#000000');
    fill($('#stHdrSize'), o.hdr_sizes || {}, s.hdr_size || '');
    fill($('#stRevCo'), o.rev_sizes || {}, s.rev_co_size || '18pt');
    fill($('#stRevTtl'), o.rev_sizes || {}, s.rev_ttl_size || '22pt');
    $('#stRevBold').toggleClass('on', String(s.rev_ttl_bold === undefined || s.rev_ttl_bold === '' ? '1' : s.rev_ttl_bold) === '1');
    $('#stRevUl').toggleClass('on', String(s.rev_ttl_ul === undefined || s.rev_ttl_ul === '' ? '1' : s.rev_ttl_ul) === '1');
    $('#stIssue').html('<option value="0">（預設：文管中心）</option>' + (TPL && TPL.depts || []).map(function(d){
        return '<option value="' + d.id + '"' + (Number(d.id) === Number(s.issue_dept_id || 0) ? ' selected' : '') + '>' + esc(d.label || d.name) + '</option>';
    }).join(''));
    $('#stSize').val(s.tbl_size || '');
    $('#stPad').val(s.cell_pad || '4px');
    $('#stBodyPad').val(s.body_pad || '3mm');
    $('#stBodyGap').val(s.body_gap === undefined || s.body_gap === '' ? '2' : s.body_gap);
    $('#stCoMode').val(s.hdr_co_mode === 'one' ? 'one' : 'two');
    $('#stBold').toggleClass('on', String(s.tbl_weight) === 'bold');
    $('#stFrame').toggleClass('on', String(s.body_frame) === '1');
    previewStyle();
}
/** 預覽：只改跳窗內那一塊的變數，不動整份文件（存檔後才真的套用） */
function previewStyle() {
    var p = document.getElementById('stPreview');
    if (!p) return;
    /* 沒選字型／字級時要把變數**移除**，不是設成 inherit：
       設了值就輪不到 CSS 裡 var(…, 預設) 的那個預設，預覽會變成跳窗的字型，
       跟存檔後的實際結果對不起來 */
    /* 英數字型排在最前面，中文排後面——跟後端 adt_style_font_stack() 同一套規則。
       只選了英數時中文要補上預設字型，不然中文會掉到瀏覽器預設的新細明體，
       看起來像「只改英數卻連中文都變了」。 */
    var zh = $('#stFont').val() || '', en = $('#stFontEn').val() || '';
    var fv = (!zh && !en) ? '' : ((en ? en + ',' : '') + (zh || '"微軟正黑體","Microsoft JhengHei",sans-serif'));
    var sv = $('#stSize').val();
    /* 「沒設定」不能用 removeProperty：頁面 :root 已經注入了**目前存的**公版變數，
       移除之後會掉回那個舊值，預覽就跟選的對不上。要明確寫成「未設定時的預設值」。 */
    p.style.setProperty('--adt-tbl-font', fv || '"微軟正黑體","Microsoft JhengHei",sans-serif');
    p.style.setProperty('--adt-tbl-size', sv || 'inherit');
    p.style.setProperty('--adt-tbl-weight', $('#stBold').hasClass('on') ? 'bold' : 'normal');
    $('#stPrevTwo').toggle($('#stCoMode').val() !== 'one');
    $('#stPrevOne').toggle($('#stCoMode').val() === 'one');
    var hv = $('#stHdrSize').val();
    p.style.setProperty('--adt-hdr-size', hv || '9.5pt');
    p.style.setProperty('--adt-brd-style', $('#stBStyle').val() || 'solid');
    p.style.setProperty('--adt-brd-w', $('#stBW').val() || '1px');
    p.style.setProperty('--adt-brd-color', $('#stBColor').val() || '#000');
    p.style.setProperty('--adt-cell-pad', $('#stPad').val() || '4px');
    if ($('#stFrame').hasClass('on')) {
        p.style.setProperty('--adt-body-brd',
            ($('#stBW').val() || '1px') + ' ' + ($('#stBStyle').val() || 'solid') + ' ' + ($('#stBColor').val() || '#000'));
        p.style.setProperty('--adt-body-pad', $('#stBodyPad').val() || '3mm');
        p.style.setProperty('--adt-body-gap', $('#stBodyGap').val() || '2');
    } else {
        p.style.setProperty('--adt-body-brd', '0 none transparent');
        p.style.setProperty('--adt-body-pad', '0');
        p.style.setProperty('--adt-body-gap', '0');
    }
    // 字型／字級一變，頁首那幾格就要重新算一次要不要縮小
    if (window.EGDocFit) EGDocFit.runWhenReady(p);
}
$(document).on('change', '#stFont,#stFontEn,#stSize,#stHdrSize,#stCoMode,#stBStyle,#stBW,#stBColor,#stPad,#stBodyPad,#stBodyGap', previewStyle);
$(document).on('click', '#stBold,#stFrame,#stRevBold,#stRevUl', function(){ $(this).toggleClass('on'); previewStyle(); });
$('#btnTplSave').on('click', function(){
    var $b = $(this).prop('disabled', true);
    $('#tplMsg').text('存檔中…');
    $.post(API, {
        action: 'tpl_save', csrf: CSRF, version_id: VID,
        issue_dept_id: $('#tplDept').val() || 0,
        foot_left:     $('#tplFoot').val(),
        cover_en:      $('#tplCoverEn').val(),
        toc_on:        $('#tplToc').prop('checked') ? 1 : 0,
        toc_first:     $('#tplTocFirst').prop('checked') ? 1 : 0,
        toc_auto_depth: $('#tplTocDepth').val()
    }, function(r){
        $b.prop('disabled', false);
        if (!r.success) { $('#tplMsg').text(''); alert(r.message || '設定存檔失敗'); return; }
        // 管理員的公版設定與版面設定一起存（同一顆按鈕，使用者不必按兩次）
        if (!$('#stFont').length) { finishTpl(); return; }
        $.post(API, {
            action: 'tpl_style_save', csrf: CSRF,
            tbl_font:    $('#stFont').val(),
            tbl_font_en: $('#stFontEn').val(),
            tbl_size:   $('#stSize').val(),
            hdr_size:   $('#stHdrSize').val(),
            rev_co_size: $('#stRevCo').val(), rev_ttl_size: $('#stRevTtl').val(),
            rev_ttl_bold: $('#stRevBold').hasClass('on') ? 1 : 0, rev_ttl_ul: $('#stRevUl').hasClass('on') ? 1 : 0,
            issue_dept_id: $('#stIssue').val() || 0,
            hdr_co_mode: $('#stCoMode').val(),
            tbl_weight: $('#stBold').hasClass('on') ? 'bold' : 'normal',
            brd_style:  $('#stBStyle').val(),
            brd_w:      $('#stBW').val(),
            brd_color:  $('#stBColor').val(),
            cell_pad:   $('#stPad').val(),
            body_frame: $('#stFrame').hasClass('on') ? 1 : 0,
            body_pad:   $('#stBodyPad').val(),
            body_gap:   $('#stBodyGap').val()
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

/* ── 插入 AS 文件編號／名稱（2026-10-15 使用者交辦）──────────────────────
   文件清單一次撈回來（164 份只有幾十 KB），之後打字即時篩選，不每打一個字就打一支 API。
   ⚠ 真正要插入的 HTML 一律由後端組（asref_build）：名稱與編號必須以資料庫**現況**為準，
     用畫面上幾分鐘前載入的舊值拼出來，插進去就是一段對不上的文字（鐵律8 同一個精神）。 */
var AR = { docs: [], styles: [], depts: [], levels: [], pick: null,
           insert: null,      // 內文：拿到 HTML 直接插到游標位置
           pickCb: null,      // 流程圖：只要「文字＋引用了哪一份文件」，不插 HTML
           loaded: false };

function arLoad(cb) {
    if (AR.loaded) { cb && cb(); return; }
    $.getJSON(API, { action: 'asref_meta', version_id: VID }, function (r) {
        if (!r || !r.success) { alert((r && r.message) || '讀不到 AS 文件清單'); return; }
        AR.docs = r.docs || []; AR.styles = r.styles || [];
        AR.depts = r.depts || []; AR.levels = r.levels || [];
        AR.loaded = true;
        var dh = '<option value="">全部部門</option>';
        AR.depts.forEach(function (d) { dh += '<option value="' + d.id + '">' + esc(d.name) + '</option>'; });
        $('#arDept').html(dh);
        var lh = '<option value="">全部階別</option>';
        AR.levels.forEach(function (l) { lh += '<option value="' + esc(l) + '">' + esc(l) + '</option>'; });
        $('#arLevel').html(lh);
        arFillStyles();
        $('#btnArStyleCfg').toggle(!!r.can_admin);
        cb && cb();
    });
}
function arFillStyles() {
    var h = '';
    AR.styles.forEach(function (s) {
        h += '<option value="' + esc(s.id) + '">' + esc(s.label) + '　→　' + esc(s.tpl) + '</option>';
    });
    $('#arStyle').html(h);
}
/** 篩選規則：關鍵字比對編號與名稱（多個關鍵字空白分隔，每個都要命中） */
function arRows() {
    var kw = String($('#arKw').val() || '').trim().toLowerCase().split(/\s+/).filter(Boolean);
    var dept = parseInt($('#arDept').val() || '0', 10) || 0;
    var lv = String($('#arLevel').val() || '');
    var obs = $('#arObs').is(':checked');
    return AR.docs.filter(function (d) {
        if (!obs && d.is_obsolete) return false;
        if (lv && d.doc_level !== lv) return false;
        if (dept) {
            var hit = (parseInt(d.department_id || 0, 10) === dept);
            (d.owner_depts || []).forEach(function (o) { if (parseInt(o.id, 10) === dept) hit = true; });
            if (!hit) return false;
        }
        if (kw.length) {
            var hay = ((d.doc_no || '') + ' ' + (d.doc_name || '')).toLowerCase();
            for (var i = 0; i < kw.length; i++) if (hay.indexOf(kw[i]) < 0) return false;
        }
        return true;
    });
}
function arRender() {
    var rows = arRows();
    $('#arCount').text('符合 ' + rows.length + ' 份 / 共 ' + AR.docs.length + ' 份');
    if (!rows.length) { $('#arList').html('<div class="ar-empty">沒有符合條件的文件</div>'); return; }
    var h = '';
    rows.slice(0, 300).forEach(function (d) {
        var dp = (d.owner_depts || []).map(function (o) { return o.name; }).join('、') || (d.dept_name || '');
        h += '<div class="ar-item' + (AR.pick && AR.pick.id === d.id ? ' on' : '') + '" data-id="' + d.id + '">'
           + '<span class="ar-no">' + esc(d.doc_no) + '</span>'
           + '<span class="ar-nm">' + esc(d.doc_name) + '</span>'
           + '<span class="ar-dp">' + esc(d.doc_level || '') + (dp ? '／' + esc(dp) : '') + '</span>'
           + (d.is_obsolete ? '<span class="ar-obs">已廢止</span>' : '')
           + '</div>';
    });
    if (rows.length > 300) h += '<div class="ar-empty">還有 ' + (rows.length - 300) + ' 份，請再打幾個字縮小範圍</div>';
    $('#arList').html(h);
}
/* 每次打開「插入 AS 文件編號／名稱」都從乾淨的狀態開始：關鍵字、部門／階別、含已廢止、
   上一次選到的文件與前後綴一律清空，游標直接落在搜尋框可以馬上打字
   （使用者 2026-10-16：上次的篩選留著，每次都要先自己刪掉）。
   帶入方式與樣式是個人習慣，不清。 */
/* 存檔列的實際高度寫進 --ad-stick，編輯工具列才釘得在它正下方、不被蓋住也不留縫 */
(function () {
    function fit() {
        var b = document.querySelector('.ad-bar');
        if (!b) return;
        document.documentElement.style.setProperty('--ad-stick', Math.round(b.getBoundingClientRect().height) + 'px');
    }
    window.addEventListener('resize', fit);
    document.addEventListener('DOMContentLoaded', fit);
    setTimeout(fit, 300); setTimeout(fit, 1500);
    if (window.ResizeObserver) {
        var ro = new ResizeObserver(fit);
        var tryObs = function () { var b = document.querySelector('.ad-bar'); if (b) ro.observe(b); else setTimeout(tryObs, 200); };
        tryObs();
    }
})();
function arOpenFresh() {
    arLoad(function () {
        AR.pick = null;
        $('#arKw').val('');
        $('#arDept,#arLevel').each(function () { this.selectedIndex = 0; });
        $('#arObs').prop('checked', false);
        $('#arPre,#arPost').val('');
        arRender(); arPrev();
        openMask('mAsRef');
        setTimeout(function () { var k = document.getElementById('arKw'); if (k) k.focus(); }, 60);
    });
}
function arPrev() {
    if (!AR.pick) { $('#arPrev').text('—'); $('#btnArInsert').prop('disabled', true); return; }
    var mode = $('input[name=arMode]:checked').val() || 'both';
    $('#arStyleRow').toggle(mode === 'both');
    var txt;
    if (mode === 'no') txt = AR.pick.doc_no;
    else if (mode === 'name') txt = '「' + AR.pick.doc_name + '」';
    else {
        var sid = $('#arStyle').val(), tpl = (AR.styles[0] || {}).tpl || '{name}　({no})';
        AR.styles.forEach(function (s) { if (s.id === sid) tpl = s.tpl; });
        txt = tpl.replace(/\{no\}/g, AR.pick.doc_no).replace(/\{name\}/g, AR.pick.doc_name);
    }
    txt = String($('#arPre').val() || '') + txt + String($('#arPost').val() || '');
    $('#arPrev').text(txt);
    $('#btnArInsert').prop('disabled', false);
}
/* ⚠ 關鍵字框只能綁 input，不能綁 change：打完字直接用滑鼠去點清單時，滑鼠按下去的瞬間
   輸入框失焦、觸發 change，清單被整個重畫，按下與放開落在不同的元素上，click 就不會發生——
   使用者要點第二次才選得到（2026-10-16 回報「篩選後都要點兩次」）。 */
$('#arKw').on('input', function () { arRender(); });
$('#arDept,#arLevel,#arObs').on('change', function () { arRender(); });
$(document).on('click', '.ar-item', function () {
    var id = parseInt($(this).data('id'), 10);
    AR.docs.forEach(function (d) { if (d.id === id) AR.pick = d; });
    $('.ar-item').removeClass('on');
    $(this).addClass('on');
    if (AR.pick) {
        $('#arSel').html('<b>' + esc(AR.pick.doc_no) + '</b>　' + esc(AR.pick.doc_name)
            + (AR.pick.is_obsolete ? '　<span style="color:#DD5138">（這份文件已廢止，確定要引用嗎？）</span>' : ''));
    }
    arPrev();
});
$('input[name=arMode],#arStyle').on('change', arPrev);
$('#arPre,#arPost').on('input', arPrev);

$('#btnArInsert').on('click', function () {
    if (!AR.pick || (!AR.insert && !AR.pickCb)) return;
    var mode = $('input[name=arMode]:checked').val() || 'both';
    var sid = $('#arStyle').val() || '';
    var $b = $(this).prop('disabled', true);
    $.post(API, {
        action: 'asref_build', csrf: CSRF, version_id: VID, doc_id: AR.pick.id,
        mode: mode, style: sid,
        pre: $('#arPre').val() || '', post: $('#arPost').val() || ''
    }, function (r) {
        $b.prop('disabled', false);
        if (!r || !r.success) { alert((r && r.message) || '插入失敗'); return; }
        if (AR.pickCb) {
            /* 流程圖那邊是畫布上的文字物件，不能插 HTML——改回傳「文字＋引用了哪一份文件」，
               由流程圖編輯器自己記在物件的 egAsRef 上（見 _flow_editor_ui.php 的 asRefInsert）。 */
            var tpl = '';
            AR.styles.forEach(function (x) { if (x.id === sid) tpl = x.tpl; });
            AR.pickCb({ doc_id: AR.pick.id, no: AR.pick.doc_no, name: AR.pick.doc_name,
                        mode: mode, tpl: (mode === 'both' ? (tpl || (AR.styles[0] || {}).tpl || '') : ''),
                        text: r.text });
            AR.pickCb = null;
        } else {
            AR.insert(r.html);
            $('#adSaved').text('已插入「' + r.text + '」').show();
        }
        closeMask('mAsRef');
    }, 'json');
});
/* 關掉挑選器就把「這一次是給誰用的」清掉，否則下次從內文開啟時會插到流程圖去 */
$(document).on('click', '[data-close="mAsRef"]', function () { AR.pickCb = null; });

/* ── 自動偵測 AS（2026-10-16 使用者交辦）──────────────────────────────────
   選取一段文字 → 辨識裡面的 AS 文件編號／名稱 → 列出清單確認 → 換成會連動的參照標記。
   判斷規則（只認「確定是引用」的寫法，一般敘述裡剛好出現的名稱不動）：
   ① 名稱＋(編號)  → 混合（名稱　(編號)）       例：8.1人力資源管理程序　(2-MM-01)
   ② 編號＋名稱    → 混合（編號名稱）           例：9.1 2-GM-05-01會議記錄
   ③ 只有編號      → 編號
   ④ 「名稱」（有「」或『』框住且與文件名稱完全相同）→ 名稱   例：「環境審查表」
   換成什麼 HTML 一律由後端 asref_build_many 組（名稱與編號以資料庫現況為準）。 */
function arDetect(text, byNo, names, styles) {
    var out = [], used = [];
    function free(s, e) { for (var i = 0; i < used.length; i++) if (s < used[i][1] && e > used[i][0]) return false; return true; }
    function take(s, e, doc, mode, style) { used.push([s, e]); out.push({ s: s, e: e, doc: doc, mode: mode, style: style }); }
    function styleBy(re) { for (var i = 0; i < styles.length; i++) if (re.test(styles[i].tpl)) return styles[i].id; return styles[0] ? styles[0].id : ''; }
    var re = /\d-[A-Za-z]{2,3}-\d{2}(?:-\d{2}){0,2}/g, m;
    while ((m = re.exec(text))) {
        var no = m[0].toUpperCase(), len = no.length, doc = byNo[no];
        while (!doc && len > 6) { len = no.lastIndexOf('-'); if (len < 0) break; no = no.substring(0, len); doc = byNo[no]; }
        if (!doc) continue;
        var ns = m.index, ne = m.index + no.length;
        // 前面是不是緊接著這份文件的名稱（中間可有空白與左括號）
        var head = text.substring(0, ns), hm = head.match(/[\s　]*[（(]?$/), hp = hm ? head.length - hm[0].length : head.length;
        var before = head.substring(0, hp);
        if (before.length >= doc.doc_name.length && before.substring(before.length - doc.doc_name.length) === doc.doc_name) {
            var end = ne, tail = text.substring(ne), tm = tail.match(/^[)）]/);
            if (tm) end += 1;
            var st = hp - doc.doc_name.length;
            if (free(st, end)) { take(st, end, doc, 'both', styleBy(/^\{name\}/)); continue; }
        }
        // 後面緊接著名稱（編號＋名稱）
        var after = text.substring(ne), am = after.match(/^[\s　]*/), ap = am ? am[0].length : 0;
        if (after.substring(ap, ap + doc.doc_name.length) === doc.doc_name && free(ns, ne + ap + doc.doc_name.length)) {
            take(ns, ne + ap + doc.doc_name.length, doc, 'both', styleBy(/^\{no\}/)); continue;
        }
        if (free(ns, ne)) take(ns, ne, doc, 'no', '');
    }
    var re2 = /[「『]([^」』]{2,80})[」』]/g;
    while ((m = re2.exec(text))) {
        var inner = m[1].trim(), d2 = null;
        for (var i = 0; i < names.length; i++) if (names[i].doc_name === inner) { d2 = names[i]; break; }
        if (d2 && free(m.index, m.index + m[0].length)) take(m.index, m.index + m[0].length, d2, 'name', '');
    }
    // 只選了名稱本身（沒連「」一起選）：整段選取剛好等於某份文件的名稱 → 名稱模式
    if (!out.length) {
        var tt = text.trim(), lead = text.indexOf(tt);
        for (var j = 0; j < names.length; j++) {
            if (tt !== '' && names[j].doc_name === tt) { take(lead, lead + tt.length, names[j], 'name', ''); out[0].ext = true; break; }
        }
    }
    return out;
}
function arAutoDetect(texts, done, keeps) {
    arLoad(function () {
        var byNo = {}, names = [];
        AR.docs.forEach(function (d) {
            if (d.is_obsolete) { if (!byNo[String(d.doc_no).toUpperCase()]) byNo[String(d.doc_no).toUpperCase()] = d; return; }
            byNo[String(d.doc_no).toUpperCase()] = d;
            if ((d.doc_name || '').length >= 3) names.push(d);
        });
        var per = texts.map(function (t, i) {
            var kp = (keeps && keeps[i]) || [];
            return arDetect(t, byNo, names, AR.styles).filter(function (x) {
                return !kp.some(function (k) { return k[0] === x.s && k[1] === x.e; });   // 本來就是完整標記、沒變的不列
            });
        });
        var flat = [];
        per.forEach(function (ms, i) { ms.forEach(function (x) { x.i = i; flat.push(x); }); });
        if (!flat.length) { alert('選取範圍內沒有辨識到 AS 文件編號或名稱。\n（只認：名稱＋(編號)、編號＋名稱、單獨的編號、以「」框住的完整文件名稱）'); return; }
        var cnt = { both: 0, no: 0, name: 0 }, lines = [];
        flat.forEach(function (x) {
            cnt[x.mode]++;
            if (lines.length < 20) {
                var tx = texts[x.i].substring(x.s, x.e);
                lines.push(tx + '  →  ' + (x.mode === 'both' ? '名稱＋編號' : (x.mode === 'no' ? '編號' : '名稱')) + '（' + x.doc.doc_no + '）');
            }
        });
        var msg = '辨識到 ' + flat.length + ' 處：名稱＋編號 ' + cnt.both + '、只有編號 ' + cnt.no + '、只有名稱 ' + cnt.name + '\n\n'
                + lines.join('\n') + (flat.length > 20 ? '\n…另有 ' + (flat.length - 20) + ' 處' : '')
                + '\n\n確定全部替換成會連動的 AS 參照嗎？';
        if (!confirm(msg)) return;
        $.post(API, { action: 'asref_build_many', csrf: CSRF, version_id: VID,
            items: JSON.stringify(flat.map(function (x) { return { doc_id: x.doc.id, mode: x.mode, style: x.style }; })) },
        function (r) {
            if (!r || !r.success) { alert((r && r.message) || '替換失敗'); return; }
            var res = texts.map(function () { return []; });
            flat.forEach(function (x, k) { if (r.html[k]) res[x.i].push({ s: x.s, e: x.e, html: r.html[k], ext: !!x.ext }); });
            done(res);
            $('#adSaved').text('已替換 ' + flat.length + ' 處 AS 參照').show();
        }, 'json');
    });
}

/** 流程圖「偵測AS」：每段文字辨識出的文件（不改文字，只回「哪一段是哪份文件」） */
function flowAsAuto(texts, done) {
    arLoad(function () {
        var byNo = {}, names = [];
        AR.docs.forEach(function (d) {
            byNo[String(d.doc_no).toUpperCase()] = d;
            if (!d.is_obsolete && (d.doc_name || '').length >= 3) names.push(d);
        });
        // 流程圖的文字都很短、一個框只放一份文件，所以名稱不必有「」也認（至少 4 個字，長的優先，免得短名稱誤吃）
        var byLen = names.filter(function (d) { return d.doc_name.length >= 4; })
                         .sort(function (x, y) { return y.doc_name.length - x.doc_name.length; });
        var res = texts.map(function (t) {
            var got = arDetect(t, byNo, names, AR.styles).map(function (x) {
                var tpl = '';
                if (x.mode === 'both') AR.styles.forEach(function (st) { if (st.id === x.style) tpl = st.tpl; });
                var md = x.mode;
                // 沒有「」框住的名稱，之後更新文字時也不能憑空加上「」→ 記成「只有名稱」的樣板
                if (md === 'name' && t.charAt(x.s) !== '「' && t.charAt(x.s) !== '『') { md = 'both'; tpl = '{name}'; }
                return { s: x.s, e: x.e, doc_id: x.doc.id, no: x.doc.doc_no, name: x.doc.doc_name, mode: md, tpl: tpl };
            });
            byLen.forEach(function (d) {
                if (got.some(function (g) { return g.doc_id === d.id; })) return;
                var i = t.indexOf(d.doc_name);
                if (i < 0) return;
                var e = i + d.doc_name.length;
                if (got.some(function (g) { return i < g.e && e > g.s; })) return;
                got.push({ s: i, e: e, doc_id: d.id, no: d.doc_no, name: d.doc_name, mode: 'both', tpl: '{name}' });
            });
            return got;
        });
        done(res);
    });
}
/** 流程圖「帶入相關文件」：掃文件內文，「相關文件」那一節與「使用表單」那一節各自列了哪些 AS 文件 */
function flowAsFill(done) {
    arLoad(function () {
        var byNo = {}, names = [];
        AR.docs.forEach(function (d) {
            byNo[String(d.doc_no).toUpperCase()] = d;
            if (!d.is_obsolete && (d.doc_name || '').length >= 3) names.push(d);
        });
        var out = { related: [], forms: [], tpl: '{no}{name}' }, seen = {}, grp = null;
        document.querySelectorAll('#adEditorHost .egrt-main').forEach(function (m) {
            Array.prototype.forEach.call(m.children, function (b) {
                var t = (b.textContent || '').replace(/[　\s]+/g, ' ').trim();
                if (/^\d{1,2}\.?\s*\S/.test(t) && !/^\d{1,2}\.\d/.test(t)) {      // 一層章節標題
                    if (/相關文件/.test(t)) grp = 'related';
                    else if (/使用表單|相關表單|使用記錄|使用紀錄/.test(t)) grp = 'forms';
                    else grp = null;
                    return;
                }
                if (!grp) return;
                arDetect(t, byNo, names, AR.styles).forEach(function (x) {
                    var k = grp + ':' + x.doc.id;
                    if (seen[k]) return; seen[k] = 1;
                    out[grp].push({ doc_id: x.doc.id, no: x.doc.doc_no, name: x.doc.doc_name });
                });
            });
        });
        AR.styles.forEach(function (st) { if (/^\{no\}\s*\{name\}$/.test(st.tpl)) out.tpl = st.tpl; });
        done(out);
    });
}

/** 給流程圖編輯器用的兩個回呼（挑文件、比對引用的文件改了沒） */
function flowAsRefOpts() {
    return {
        onAsAuto: flowAsAuto,
        onAsFill: flowAsFill,
        onPickAsRef: function (done) {
            AR.pickCb = done;
            arOpenFresh();
        },
        onAsRefCheck: function (list, cb) {
            var ids = list.map(function (x) { return x.doc_id; }).join(',');
            if (!ids) { cb(null); return; }
            $.getJSON(API, { action: 'asref_resolve', version_id: VID, ids: ids }, function (r) {
                cb(r && r.success ? (r.docs || {}) : null);
            });
        }
    };
}

/* 樣式設定（限管理員） */
function arStyleRows() {
    var h = '';
    AR.styles.forEach(function (s) {
        h += '<tr><td><input type="text" class="form-control input-sm as-lab" maxlength="30" value="' + esc(s.label) + '" data-eg-skip></td>'
           + '<td><input type="text" class="form-control input-sm as-tpl" maxlength="60" value="' + esc(s.tpl) + '" data-eg-skip></td>'
           + '<td><button class="btn btn-default btn-xs as-del" title="刪除這一種">&times;</button></td></tr>';
    });
    $('#arStyleTbl tbody').html(h);
}
$('#btnArStyleCfg').on('click', function () { arStyleRows(); $('#arStyleMsg').text(''); openMask('mArStyle'); });
$('#btnArStyleAdd').on('click', function () {
    $('#arStyleTbl tbody').append('<tr><td><input type="text" class="form-control input-sm as-lab" maxlength="30" data-eg-skip></td>'
        + '<td><input type="text" class="form-control input-sm as-tpl" maxlength="60" value="{name}　({no})" data-eg-skip></td>'
        + '<td><button class="btn btn-default btn-xs as-del">&times;</button></td></tr>');
});
$(document).on('click', '.as-del', function () {
    if ($('#arStyleTbl tbody tr').length <= 1) { $('#arStyleMsg').text('至少要留一種樣式'); return; }
    $(this).closest('tr').remove();
    $('#arStyleMsg').text('');
});
$('#btnArStyleSave').on('click', function () {
    var rows = [], bad = '';
    $('#arStyleTbl tbody tr').each(function () {
        var lab = String($(this).find('.as-lab').val() || '').trim();
        var tpl = String($(this).find('.as-tpl').val() || '').trim();
        if (tpl === '') return;
        // 前端先擋一次（後端同規則再擋一次＝鐵律8）
        if (tpl.indexOf('{name}') < 0 && tpl.indexOf('{no}') < 0) bad = '樣板「' + tpl + '」沒有用到 {name} 或 {no}';
        if (/[<>]/.test(tpl)) bad = '樣板不可以含 < 或 >';
        rows.push({ label: lab || tpl, tpl: tpl });
    });
    if (bad) { $('#arStyleMsg').text(bad); return; }
    if (!rows.length) { $('#arStyleMsg').text('至少要留一種樣式'); return; }
    $.post(API, { action: 'asref_styles_save', csrf: CSRF, version_id: VID, styles: JSON.stringify(rows) },
        function (r) {
            if (!r || !r.success) { $('#arStyleMsg').text((r && r.message) || '存檔失敗'); return; }
            AR.styles = r.styles || rows;
            arFillStyles();
            arPrev();
            closeMask('mArStyle');
            $('#adSaved').text('插入樣式已存檔').show();
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
        onChange: function(){
            /* 存檔剛完成時編輯器會因版面樣板／頁碼重繪再觸發一次 change，
               內容其實沒變，不可因此又標成「有未存檔的變更」（使用者 2026-10-27 回報） */
            if (SAVED_HTML !== null && Date.now() - SAVED_TS < 3000 && ED && ED.get() === SAVED_HTML) return;
            setDirty(true);
        },
        onInsertImage: function(insert){ openUpload(insert); },
        onInsertAsRef: function(insertHtml){
            AR.insert = insertHtml;
            arOpenFresh();
        },
        onAutoAsRef: arAutoDetect,
        onInsertFlow: function(insert){
            EGFlow.open({ json:null, onPickAsRef: flowAsRefOpts().onPickAsRef,
              onAsRefCheck: flowAsRefOpts().onAsRefCheck, onAsAuto: flowAsAuto, onAsFill: flowAsFill,
              onSave: function(png, json){
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
                EGFlow.open({ json:r.flow_json,
                    onPickAsRef: flowAsRefOpts().onPickAsRef,
                    onAsRefCheck: flowAsRefOpts().onAsRefCheck, onAsAuto: flowAsAuto, onAsFill: flowAsFill,
                    onSave: function(png, json){
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

/* ── 附錄整理（2026-10-27 使用者交辦）──────────────────────────────────────
   ①打「附錄」兩字時提示目前編號用到哪、下一個是哪個、哪些跳號沒用
   ②每個附錄自動從新的一頁開始（附錄本身只有半頁以下才可以跟前面共用一頁）
   ③內文裡的「附錄 X」連到對應附錄（參照＝<span class="eg-apref" data-apt="附錄標題">）；
     附錄改編號時按標題找回來自動改，改過的字標紅並列出在哪一頁。
   身分用「附錄標題」而不是編號：編號本來就會變，標題才是同一個附錄的依據。 */
var APX_PREFIX = '(?:附件|附[錄\\uF900-\\uFAFF])';
var APX_HEAD_RE = new RegExp('^(' + APX_PREFIX + ')\\s*([A-Z])');
var APX_REF_RE = new RegExp('(' + APX_PREFIX + ')(\\s*)([A-Z])(?![A-Za-z0-9])', 'g');
var APX_RED = '#cc0000';
function apxNorm(t) { return String(t || '').replace(/[\s 　]+/g, ' ').trim(); }
function apxEsc(s) { return String(s == null ? '' : s).replace(/[&<>"]/g, function(c){ return {'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;'}[c]; }); }
function apxSheets() { return Array.prototype.slice.call(document.querySelectorAll('#adEditorHost .egrt-main')); }
function apxIsHard(el) { return !!(el && el.nodeType === 1 && /(page-break-before\s*:\s*always|break-before\s*:\s*page)/i.test(el.getAttribute('style') || '')); }
function apxSetHard(el) {
    /* 不可用 el.style.pageBreakBefore：Chrome 會改寫成 break-before，清洗白名單只認舊寫法（eg_richtext.js 同一個坑） */
    var st = String(el.getAttribute('style') || '').replace(/(^|;)\s*(page-break-before|break-before)\s*:[^;]*/gi, '$1').replace(/^[\s;]+|[\s;]+$/g, '');
    el.setAttribute('style', (st ? st + ';' : '') + 'page-break-before:always');
}
/** 目前文件裡所有「附錄標題」（手動設成標題的一律算；一般段落要短、無句子標點、不是「附錄 A 用以…」這種敘述） */
function apxHeads() {
    var out = [];
    apxSheets().forEach(function(sh, pi){
        Array.prototype.forEach.call(sh.querySelectorAll('h1,h2,h3,h4,p,td'), function(el){
            if (el.closest('.eg-apref')) return;
            var tag = el.tagName, t = apxNorm(el.textContent), m = APX_HEAD_RE.exec(t);
            if (!m) return;
            if (tag === 'TD' && el.querySelector('p,h1,h2,h3,h4')) return;
            if (!/^H[1-4]$/.test(tag)) {
                if (t.length > 60 || /[，。；;,：:]/.test(t)) return;
                if (new RegExp('^' + APX_PREFIX + '\\s*[A-Z]\\s*用').test(t)) return;
            }
            out.push({ el: el, page: pi + 1, prefix: m[1], letter: m[2], title: apxNorm(t.slice(m[0].length)), top: el.parentNode === sh });
        });
    });
    return out;
}
function apxKey(h) { return h.title || ('#' + h.letter); }

/* ① 打「附錄」時的編號提示 */
var APX_HINT = null;
function apxHideHint() { if (APX_HINT) { APX_HINT.remove(); APX_HINT = null; } }
function apxHint() {
    var sel = window.getSelection();
    if (!sel || !sel.rangeCount || !sel.isCollapsed) { apxHideHint(); return; }
    var r = sel.getRangeAt(0), n = r.startContainer;
    if (n.nodeType !== 3 || !n.parentNode.closest || !n.parentNode.closest('#adEditorHost .egrt-main')) { apxHideHint(); return; }
    if (!new RegExp(APX_PREFIX + '\\s*$').test(n.textContent.slice(0, r.startOffset))) { apxHideHint(); return; }
    var seen = {}, rows = [], max = '';
    apxHeads().forEach(function(h){
        if (h.el.contains(n)) return;
        if (!seen[h.letter]) { seen[h.letter] = 1; rows.push(h); if (h.letter > max) max = h.letter; }
    });
    rows.sort(function(a, b){ return a.letter < b.letter ? -1 : 1; });
    var gaps = [];
    if (max) for (var c = 65; c < max.charCodeAt(0); c++) { if (!seen[String.fromCharCode(c)]) gaps.push(String.fromCharCode(c)); }
    var next = max ? String.fromCharCode(Math.min(90, max.charCodeAt(0) + 1)) : 'A';
    var h = '<div style="font-weight:bold;margin-bottom:3px;color:#8A5A2B">目前附錄編號</div>';
    if (!rows.length) h += '<div>（文件裡還沒有附錄）</div>';
    rows.forEach(function(x){ h += '<div>' + apxEsc(x.prefix) + ' <b>' + x.letter + '</b>　' + apxEsc(x.title.slice(0, 22)) + '<span style="color:#999">　第 ' + x.page + ' 頁</span></div>'; });
    h += '<div style="margin-top:4px;border-top:1px solid #eadfcb;padding-top:3px">下一個可用：<b style="color:#D6851F">' + next + '</b>'
       + (gaps.length ? '　<span style="color:#999">（' + gaps.join('、') + ' 目前沒有用到）</span>' : '') + '</div>';
    apxHideHint();
    var rect = r.getBoundingClientRect();
    if (!rect || (!rect.top && !rect.left)) rect = n.parentNode.getBoundingClientRect();
    APX_HINT = document.createElement('div');
    APX_HINT.style.cssText = 'position:fixed;z-index:10650;background:#fffdf8;border:1px solid #E8890C;border-radius:6px;padding:7px 10px;'
        + 'font-size:12px;line-height:1.7;color:#4a3213;box-shadow:0 4px 14px rgba(0,0,0,.25);max-width:380px;'
        + 'left:' + Math.max(8, Math.min(window.innerWidth - 400, rect.left)) + 'px;top:' + (rect.bottom + 6) + 'px';
    APX_HINT.innerHTML = h;
    document.body.appendChild(APX_HINT);
    setTimeout(apxHideHint, 9000);
}
var APX_T1 = null, APX_T2 = null;
$(document).on('input keyup compositionend', '#adEditorHost .egrt-main', function(e){
    clearTimeout(APX_T1); APX_T1 = setTimeout(apxHint, 160);
    if (e.type === 'input') {          // 剛打好一個附錄標題：自動讓它從新的一頁開始
        clearTimeout(APX_T2);
        APX_T2 = setTimeout(function(){
            var sel = window.getSelection(), n = sel && sel.rangeCount ? sel.getRangeAt(0).startContainer : null;
            var blk = n && (n.nodeType === 3 ? n.parentNode : n);
            blk = blk && blk.closest ? blk.closest('h1,h2,h3,h4,p') : null;
            if (blk && APX_HEAD_RE.test(apxNorm(blk.textContent))) { var rp = apxRun(false); if (rp.breaks.length) apxToast('「' + rp.breaks[0].letter + '」附錄已自動從新的一頁開始'); }
        }, 1300);
    }
});
$(document).on('keydown mousedown', function(e){ if (APX_HINT && (e.type === 'mousedown' || e.key === 'Escape')) apxHideHint(); });
function apxToast(msg) {
    var t = document.createElement('div');
    t.style.cssText = 'position:fixed;z-index:10700;right:80px;bottom:30px;background:#6B4423;color:#fff;padding:8px 14px;border-radius:6px;font-size:13px;box-shadow:0 4px 12px rgba(0,0,0,.3)';
    t.textContent = msg; document.body.appendChild(t); setTimeout(function(){ t.remove(); }, 3500);
}

/* ② 附錄自動換頁：附錄（標題到下一個附錄／強制換頁之前）超過半頁高就強制從新的一頁開始 */
function apxAutoBreaks(heads) {
    var done = [], blocks = [], sheets = apxSheets();
    if (!sheets.length) return done;
    sheets.forEach(function(sh){ Array.prototype.forEach.call(sh.children, function(b){ blocks.push(b); }); });
    var half = sheets[0].clientHeight / 2, headEls = heads.map(function(h){ return h.el; });
    heads.forEach(function(h){
        if (!h.top || apxIsHard(h.el)) return;
        var idx = blocks.indexOf(h.el);
        if (idx <= 0) return;
        var size = 0;
        for (var i = idx; i < blocks.length; i++) {
            if (i > idx && (headEls.indexOf(blocks[i]) >= 0 || apxIsHard(blocks[i]))) break;
            size += blocks[i].getBoundingClientRect().height;
        }
        if (size > half) { apxSetHard(h.el); done.push(h); }
    });
    return done;
}

/* ③ 內文參照：連結＋改編號時自動更新（改過的標紅） */
function apxContext(el) {
    var b = el.closest ? el.closest('p,td,li,h1,h2,h3,h4') : null;
    var t = apxNorm((b || el).textContent);
    var i = t.indexOf(apxNorm(el.textContent));
    return t.slice(Math.max(0, i - 12), i + 36);
}
function apxPageOf(el) {
    var sh = el.closest ? el.closest('.egrt-main') : null;
    return sh ? apxSheets().indexOf(sh) + 1 : 0;
}
function apxSyncRefs(heads, link) {
    var byKey = {}, byLetter = {}, res = { changes: [], missing: [], linked: 0 };
    heads.forEach(function(h){ var k = apxKey(h); if (!byKey[k]) byKey[k] = h; if (!byLetter[h.letter]) byLetter[h.letter] = h; });
    var lblRe = new RegExp('^(' + APX_PREFIX + ')(\\s*)([A-Z])');
    // 已連結的參照：照標題找回附錄，編號變了就改字、標紅
    document.querySelectorAll('#adEditorHost .egrt-main .eg-apref').forEach(function(sp){
        var h = byKey[sp.getAttribute('data-apt') || ''], m = lblRe.exec(sp.textContent);
        if (!h) { sp.style.color = APX_RED; res.missing.push({ page: apxPageOf(sp), text: apxNorm(sp.textContent), ctx: apxContext(sp) }); return; }
        if (m && m[3] !== h.letter) {
            var old = sp.textContent;
            sp.textContent = m[1] + m[2] + h.letter + sp.textContent.slice(m[0].length);
            sp.style.color = APX_RED;
            res.changes.push({ page: apxPageOf(sp), from: apxNorm(old), to: apxNorm(sp.textContent), ctx: apxContext(sp) });
        }
    });
    if (!link) return res;
    // 還沒連結的純文字「附錄 X」：連到同編號的附錄（附錄標題本身、已連結的、AS 參照裡的不動）。
    // 「附錄」與「A」常被拆在不同的 <span> 裡（Word 匯入的字型切換），所以要以「整個段落的文字」比對、
    // 再用 Range 把跨節點的那一段包起來，不能逐個文字節點比。
    var headEls = heads.map(function(h){ return h.el; });
    var blocks = [];
    apxSheets().forEach(function(sh){
        sh.querySelectorAll('p,td,li,h2,h3,h4').forEach(function(b){
            if (b.querySelector('p,td,li,h1,h2,h3,h4,table')) return;      // 只處理最內層的區塊
            if (b.closest('.eg-asref') || headEls.some(function(e){ return e === b || e.contains(b) || b.contains(e); })) return;
            blocks.push(b);
        });
    });
    blocks.forEach(function(b){
        var tw = document.createTreeWalker(b, NodeFilter.SHOW_TEXT, null, false), n, full = '', map = [];
        while ((n = tw.nextNode())) {
            if (n.parentNode.closest('.eg-apref,.eg-asref')) { full += '\u0001'.repeat(n.nodeValue.length); }   // 已連結的不再比對
            else full += n.nodeValue;
            map.push({ n: n, s: full.length - n.nodeValue.length });
        }
        if (!new RegExp(APX_PREFIX).test(full)) return;
        var found = [], m;
        APX_REF_RE.lastIndex = 0;
        while ((m = APX_REF_RE.exec(full))) { if (byLetter[m[3]]) found.push({ i: m.index, len: m[0].length, h: byLetter[m[3]] }); }
        function locate(pos, isEnd) {      // 全文位置 → (文字節點, 位移)
            for (var k = 0; k < map.length; k++) {
                var e = map[k].s + map[k].n.nodeValue.length;
                if (isEnd ? (pos > map[k].s && pos <= e) : (pos >= map[k].s && pos < e)) return { n: map[k].n, o: pos - map[k].s };
            }
            return null;
        }
        for (var q = found.length - 1; q >= 0; q--) {            // 由後往前包，前面的位移才不會跑掉
            var f = found[q], a1 = locate(f.i, false), b1 = locate(f.i + f.len, true);
            if (!a1 || !b1) continue;
            var rg = document.createRange();
            rg.setStart(a1.n, a1.o); rg.setEnd(b1.n, b1.o);
            var sp = document.createElement('span');
            sp.className = 'eg-apref'; sp.setAttribute('data-apt', apxKey(f.h).slice(0, 120));
            sp.title = '連到「' + apxKey(f.h) + '」（附錄改編號時這裡會自動跟著改；Ctrl＋點擊跳到該附錄）';
            sp.appendChild(rg.extractContents());
            rg.insertNode(sp);
            res.linked++;
        }
    });
    return res;
}
/** 整套：回傳報告；有動到內容就標成未存檔並重排 */
function apxRun(link) {
    var heads = apxHeads(), rep = { dups: [], gaps: [], breaks: [], linked: 0, changes: [], missing: [], rows: [] }, cnt = {}, max = '';
    heads.forEach(function(h){ cnt[h.letter] = (cnt[h.letter] || 0) + 1; if (h.letter > max) max = h.letter; });
    Object.keys(cnt).forEach(function(k){ if (cnt[k] > 1) rep.dups.push(k); });
    if (max) for (var c = 65; c < max.charCodeAt(0); c++) { if (!cnt[String.fromCharCode(c)]) rep.gaps.push(String.fromCharCode(c)); }
    rep.rows = heads;
    var r = apxSyncRefs(heads, link);
    rep.linked = r.linked; rep.changes = r.changes; rep.missing = r.missing;
    rep.breaks = apxAutoBreaks(heads);
    if (rep.linked || rep.changes.length || rep.missing.length || rep.breaks.length) setDirty(true);
    if (rep.breaks.length && ED) { try { ED.reflowQuiet(); } catch (e) {} }
    return rep;
}
function apxShow(rep) {
    var old = document.getElementById('apxDlg'); if (old) old.remove();
    var h = '<div style="font-weight:bold;font-size:15px;margin-bottom:8px;color:#6B4423"><i class="fa fa-paperclip"></i> 附錄整理結果</div>';
    h += '<div style="margin-bottom:6px"><b>目前附錄：</b>' + (rep.rows.length ? rep.rows.map(function(x){ return apxEsc(x.prefix) + ' ' + x.letter + '（第 ' + x.page + ' 頁）'; }).join('、') : '（沒有）') + '</div>';
    if (rep.dups.length) h += '<div style="color:#DD5138">⚠ 編號重複：' + rep.dups.join('、') + '（請改成不同編號，否則內文的連結會對到第一個）</div>';
    if (rep.gaps.length) h += '<div style="color:#8A5A2B">編號跳號（沒有用到）：' + rep.gaps.join('、') + '</div>';
    if (rep.breaks.length) h += '<div>已讓 ' + rep.breaks.map(function(b){ return b.letter; }).join('、') + ' 附錄自動從新的一頁開始（附錄超過半頁高）。</div>';
    if (rep.linked) h += '<div>已把內文裡 ' + rep.linked + ' 處「附錄 X」連到對應附錄（改編號時會自動更新）。</div>';
    if (rep.changes.length) {
        h += '<div style="margin-top:8px;color:#cc0000;font-weight:bold">附錄編號改了，下列 ' + rep.changes.length + ' 處內文已自動更改（文中以紅字標示）：</div>'
           + '<table style="width:100%;border-collapse:collapse;font-size:12px;margin-top:3px"><tr style="background:#F5E9D6"><th style="padding:3px 6px;text-align:left;width:50px">頁次</th><th style="padding:3px 6px;text-align:left;width:150px">更改</th><th style="padding:3px 6px;text-align:left">所在段落</th></tr>';
        rep.changes.forEach(function(c){ h += '<tr><td style="padding:3px 6px;border-top:1px solid #eadfcb">第 ' + c.page + ' 頁</td><td style="padding:3px 6px;border-top:1px solid #eadfcb">' + apxEsc(c.from) + ' → <b style="color:#cc0000">' + apxEsc(c.to) + '</b></td><td style="padding:3px 6px;border-top:1px solid #eadfcb;color:#555">…' + apxEsc(c.ctx) + '…</td></tr>'; });
        h += '</table>';
    }
    if (rep.missing.length) {
        h += '<div style="margin-top:8px;color:#DD5138;font-weight:bold">下列 ' + rep.missing.length + ' 處找不到對應的附錄（附錄標題被改掉或刪除了），請手動確認（已標紅）：</div>';
        rep.missing.forEach(function(c){ h += '<div style="font-size:12px">第 ' + c.page + ' 頁：' + apxEsc(c.text) + '　<span style="color:#555">…' + apxEsc(c.ctx) + '…</span></div>'; });
    }
    if (!rep.dups.length && !rep.gaps.length && !rep.breaks.length && !rep.linked && !rep.changes.length && !rep.missing.length)
        h += '<div style="color:#3E6B2E">附錄編號、換頁與內文參照都沒有問題。</div>';
    h += '<div style="margin-top:12px;text-align:right"><button type="button" class="btn btn-default btn-sm" id="apxClearRed">清除紅字標記</button> '
       + '<button type="button" class="btn btn-warning btn-sm" id="apxClose">關閉</button></div>';
    var d = document.createElement('div');
    d.id = 'apxDlg';
    d.style.cssText = 'position:fixed;z-index:10700;left:50%;top:50%;transform:translate(-50%,-50%);width:780px;max-width:94vw;max-height:80vh;overflow:auto;background:#fff;border:1px solid #E8890C;border-radius:8px;padding:16px 18px;box-shadow:0 10px 36px rgba(0,0,0,.35);font-size:13px;line-height:1.7;color:#333';
    d.innerHTML = h; document.body.appendChild(d);
    document.getElementById('apxClose').onclick = function(){ d.remove(); };
    document.getElementById('apxClearRed').onclick = function(){
        document.querySelectorAll('#adEditorHost .egrt-main .eg-apref').forEach(function(sp){
            sp.style.removeProperty('color'); if (!sp.getAttribute('style')) sp.removeAttribute('style');
        });
        setDirty(true); apxToast('已清除紅字標記');
    };
}
$('#btnApx').on('click', function(){
    if (!ED) return;
    apxShow(apxRun(true));
});
// Ctrl＋點內文裡的附錄參照＝跳到那個附錄
$(document).on('click', '#adEditorHost .egrt-main .eg-apref', function(e){
    if (!(e.ctrlKey || e.metaKey)) return;
    var k = this.getAttribute('data-apt') || '', hit = null;
    apxHeads().forEach(function(h){ if (!hit && apxKey(h) === k) hit = h; });
    if (hit) hit.el.scrollIntoView({ behavior: 'smooth', block: 'center' });
});

/* ── 條文下的清單編號與縮排統一（2026-10-27）────────────────────────────────
   標準：條文（10.1、10.2.1…）底下第一層一律 a. b. c.，第二層一律 1) 2) 3)。
   手冊裡同一層常混用 a. ／ a： ／ （a）／ 1） ／ 1)，所以「編號統一」依結構辨識層級、重排編號、列出清單讓人確認再套用；
   另外在清單項目按 Enter 時自動接下一個編號（空項目再按 Enter ＝ 結束清單）。 */
var LST_NEW = null;      // 剛按 Enter 產生的空段落（要套對齊）
var LST_WS = '[\\s\\u00A0\\u3000]';
var LST_RE = new RegExp('^' + LST_WS + '*(?:[（(]?([a-z])[)）.．、:：](?![a-zA-Z])|[（(]?(\\d{1,2})[)）])' + LST_WS + '*');
var LST_CLAUSE = new RegExp('^' + LST_WS + '*\\d+(?:\\.\\d+)+(?:' + LST_WS + '|$)');
function lstBlocks() {
    var out = [];
    apxSheets().forEach(function(sh, si){
        Array.prototype.forEach.call(sh.children, function(b){
            if (/^(P|DIV|H[1-6])$/.test(b.tagName)) out.push({ el: b, page: si + 1 });
        });
    });
    return out;
}
function lstTarget(level, n) { return level === 1 ? String.fromCharCode(96 + n) + '.' : n + ')'; }
var LST_CHAPTER = new RegExp('^' + LST_WS + '*\\d{1,2}\\.' + LST_WS + '*[^\\s\\d.]');   // 「3. 術語」「10.改善」（一定要有句點才算章標題；沒有句點的「10 改善」不算）
function lstDepth(t) { var m = LST_CLAUSE.exec(t); return m ? m[0].trim().split('.').length : 0; }
function lstPx(el) { return parseFloat(getComputedStyle(el).fontSize) || 16; }
function lstR2(v) { return Math.round(v * 10) / 10; }      // 一位小數：清洗白名單只收一位小數，多了存檔會被整個濾掉
/** 標題的「標題文字起點」＝左縮 ＋ 內縮（em，以標題自己的字級為準）；章標題（1. 2. 3.）固定 1.1em */
function lstTitleStart(el, depth) {
    if (depth === 1) return 1.1;
    var st = el.style, ml = parseFloat(st.marginLeft), pl = parseFloat(st.paddingLeft);
    if (/em$/.test(st.marginLeft || '') && /em$/.test(st.paddingLeft || '') && !isNaN(ml) && !isNaN(pl)) return ml + pl;
    return ({ 2: 3.7, 3: 6.5, 4: 9.3, 5: 12.1 })[depth] || 3.7;
}
function lstWhy(cur, tgt) {
    if (/[）]/.test(cur) && /[)]/.test(tgt)) return '全形括號改半形';
    if (/^[（(]/.test(cur)) return '去掉前括號';
    if (/[:：、]$/.test(cur)) return '句尾改成「.」';
    return '統一寫法';
}
function lstScan() {
    var rows = [], hints = [], layout = [], lastL1 = false, ts = null, hPx = 16;
    lstBlocks().forEach(function(b){
        var t = b.el.textContent || '', tt = t.trim();
        var isH = /^H[1-6]$/.test(b.el.tagName), isCl = LST_CLAUSE.test(t);
        var isChap = !isH && !isCl && LST_CHAPTER.test(t) && tt.length <= 40 && !b.el.querySelector('img,table');
        if (isH || isCl || isChap) {
            lastL1 = false;
            var depth = isCl ? lstDepth(t) : 1;
            ts = lstTitleStart(b.el, depth); hPx = lstPx(b.el);
            // 章標題（1. 2. 3.）是最上層，靠最左；底下的條文（3.1）才往內縮
            // 條文標題上殘留的大塊右邊界（Word 匯入）會讓標題擠成窄窄一欄、莫名折行（例：6.2.2 在「組／織」之間斷開）
            if (!isChap && b.el.style.marginRight) layout.push({ el: b.el, page: b.page, onlyRight: true, kind: '標題右邊界', ctx: tt.slice(0, 16) });
            if (isChap) layout.push({ el: b.el, page: b.page, ml: 0, pl: 1.1, ti: -1.1, kind: '章標題', ctx: tt.slice(0, 16) });
            return;
        }
        var m = LST_RE.exec(t);
        var plain = !m && (tt !== '' || b.el === LST_NEW) && !b.el.querySelector('img,table') && !/text-align:\s*(center|right)/.test(b.el.getAttribute('style') || '');
        // 段落字級不同於標題時，em 要換算（em 是以「該段自己的字級」算的）
        var f = hPx / lstPx(b.el);
        if (!m) {
            if (/[：:]\s*$/.test(tt)) lastL1 = false;
            if (plain && ts !== null) layout.push({ el: b.el, page: b.page, ml: lstR2(ts * f), pl: 0, ti: 0, kind: '內文', ctx: tt.slice(0, 16) });
            return;
        }
        var cur = m[0].replace(/[\s 　]+$/, '').replace(/^[\s 　]+/, '');
        var tgt = m[1] ? m[1] + '.' : m[2] + ')';
        var ctx = t.slice(m[0].length, m[0].length + 18), level = m[1] ? 1 : (lastL1 ? 2 : 1);
        if (m[1]) lastL1 = true;
        else if (!lastL1) hints.push({ page: b.page, from: cur, ctx: ctx });
        if (cur !== tgt) rows.push({ el: b.el, page: b.page, from: cur, to: tgt, level: level, why: lstWhy(cur, tgt), ctx: ctx });
        if (ts !== null) layout.push({ el: b.el, page: b.page, ml: lstR2((ts + (level === 2 ? 1.5 : 0)) * f), pl: lstR2(1.5 * f), ti: lstR2(-1.5 * f), kind: level === 1 ? '第1層清單' : '第2層清單', ctx: ctx });
    });
    // 已經是標準位置的不列
    function nn(v) { return parseFloat(v) || 0; }
    layout = layout.filter(function(x){
        if (x.onlyRight) return true;
        var st = x.el.style;
        return !(Math.abs(nn(st.marginLeft) - x.ml) < 0.05 && (x.ml === 0 || /em$/.test(st.marginLeft || '')) && !st.marginRight &&
                 Math.abs(nn(st.paddingLeft) - x.pl) < 0.05 && Math.abs(nn(st.textIndent) - x.ti) < 0.05);
    });
    rows.hints = hints; rows.layout = layout;
    return rows;
}
/** 取得區塊文字第 pos 個字元所在的 (文字節點, 位移) */
function lstLocate(el, pos, isEnd) {
    var tw = document.createTreeWalker(el, NodeFilter.SHOW_TEXT, null, false), n, s = 0;
    while ((n = tw.nextNode())) {
        var e = s + n.nodeValue.length;
        if (isEnd ? (pos > s && pos <= e) : (pos >= s && pos < e)) return { n: n, o: pos - s };
        s = e;
    }
    return null;
}
/** 把區塊開頭的編號換成 newMarker（空字串＝移除編號）。保留編號前後的空白與字型 */
function lstSetMarker(el, newMarker) {
    var t = el.textContent || '', m = LST_RE.exec(t);
    if (!m) return false;
    var lead = (/^[\s 　]*/.exec(t) || [''])[0].length;
    var end = m[0].replace(/[\s 　]+$/, '').length;
    var a = lstLocate(el, lead, false), b = lstLocate(el, end, true);
    if (!a || !b) return false;
    var rg = document.createRange();
    rg.setStart(a.n, a.o); rg.setEnd(b.n, b.o);
    rg.deleteContents();
    if (newMarker) { var tn = document.createTextNode(newMarker); rg.insertNode(tn); }
    return true;
}
function lstApplyLayout(x) {
    var st = x.el.style;
    if (x.onlyRight) { st.marginRight = ''; return; }
    st.marginLeft = x.ml ? x.ml + 'em' : ''; st.marginRight = '';
    // padding-left 單獨寫不在白名單，要用 padding 簡寫
    if (x.pl) { st.padding = '0em 0em 0em ' + x.pl + 'em'; st.textIndent = x.ti + 'em'; } else { st.padding = ''; st.textIndent = ''; }
}
function lstShow(rows) {
    var old = document.getElementById('lstDlg'); if (old) old.remove();
    var lay = rows.layout || [], hs = rows.hints || [];
    var TD = 'padding:3px 6px;border-top:1px solid #eadfcb';
    var h = '<div style="font-weight:bold;font-size:15px;margin-bottom:6px;color:#6B4423"><i class="fa fa-list-ol"></i> 編號與縮排統一</div>'
      + '<div style="background:#FBF3E4;border:1px solid #eadfcb;border-radius:6px;padding:8px 10px;font-size:12px;line-height:1.7;margin-bottom:8px"><b>目前的規則</b>'
      + '<table style="width:100%;border-collapse:collapse;margin-top:4px"><tr style="background:#F5E9D6"><th style="padding:2px 6px;text-align:left;width:130px">種類</th><th style="padding:2px 6px;text-align:left;width:150px">寫法</th><th style="padding:2px 6px;text-align:left">怎麼判斷／怎麼縮排</th></tr>'
      + '<tr><td style="' + TD + '">章標題</td><td style="' + TD + '">1. 公司簡介　3. 術語與定義</td><td style="' + TD + '">最上層，靠最左；底下條文（3.1）往內縮，所以章標題<b>不會</b>比條文更靠右</td></tr><tr><td style="' + TD + '">條文標題</td><td style="' + TD + '">10　10.1　10.2.1　7.1.5.1</td><td style="' + TD + '">章節層級，<b>不是清單、不會被改</b>；越深縮排越多（第2層 2em、第3層 4em、第4層 6em）</td></tr>'
      + '<tr><td style="' + TD + '">條文底下的內文</td><td style="' + TD + '">一般段落</td><td style="' + TD + '">自動對齊<b>上方條文標題的文字起點</b>（標題數字之後那個字）</td></tr>'
      + '<tr><td style="' + TD + '">清單第 1 層</td><td style="' + TD + '"><b>a.</b>　b.　c.</td><td style="' + TD + '">開頭是英文字母＋句點／冒號／頓號／括號（a： a、 （a））就算；寫法統一成「a.」；縮排同內文，折行時對齊字後面</td></tr>'
      + '<tr><td style="' + TD + '">清單第 2 層</td><td style="' + TD + '"><b>1)</b>　2)　3)</td><td style="' + TD + '">開頭是數字＋括號（1） （1） 1)），而且<b>前面已經有 a. 項目</b>才算第 2 層；寫法統一成「1)」；比第 1 層再縮 1.5em</td></tr>'
      + '<tr><td style="' + TD + '">不處理</td><td style="' + TD + '">1.　2.　（①）</td><td style="' + TD + '">數字加句點容易跟條文編號混淆，不動</td></tr>'
      + '</table><div style="margin-top:4px;color:#8A5A2B">只統一標點與縮排，<b>字母與數字不動</b>（a、b、c 常對應標準條文，跳號是刻意的）；內文原本殘留的大塊右邊界（讓文字擠成窄窄一欄）會一併清掉。</div></div>';
    h += '<div style="font-weight:bold;margin:6px 0 3px">① 標點寫法（' + rows.length + ' 處）</div>';
    if (!rows.length) h += '<div style="color:#3E6B2E">都已符合標準。</div>';
    else {
        h += '<div style="max-height:22vh;overflow:auto"><table style="width:100%;border-collapse:collapse;font-size:12px"><tr style="background:#F5E9D6"><th style="padding:3px 6px;text-align:left;width:50px">頁次</th><th style="padding:3px 6px;text-align:left;width:60px">層級</th><th style="padding:3px 6px;text-align:left;width:150px">改前 → 改後</th><th style="padding:3px 6px;text-align:left;width:110px">原因</th><th style="padding:3px 6px;text-align:left">內容</th></tr>';
        rows.forEach(function(r){ h += '<tr><td style="' + TD + '">第 ' + r.page + ' 頁</td><td style="' + TD + '">第 ' + r.level + ' 層</td><td style="' + TD + '">' + apxEsc(r.from) + '（' + r.from.length + ' 字）→ <b style="color:#cc0000">' + apxEsc(r.to) + '</b></td><td style="' + TD + '">' + r.why + '</td><td style="' + TD + ';color:#555">' + apxEsc(r.ctx) + '…</td></tr>'; });
        h += '</table></div>';
    }
    h += '<div style="font-weight:bold;margin:8px 0 3px">② 縮排對齊（' + lay.length + ' 段）</div>';
    if (!lay.length) h += '<div style="color:#3E6B2E">都已對齊。</div>';
    else {
        var byPage = {}; lay.forEach(function(x){ byPage[x.page] = (byPage[x.page] || 0) + 1; });
        h += '<div style="font-size:12px;color:#555">這些段落會對齊所屬條文標題的文字起點：' + Object.keys(byPage).map(function(p){ return '第 ' + p + ' 頁 ' + byPage[p] + ' 段'; }).join('、') + '</div>';
    }
    if (hs.length) {
        h += '<div style="margin-top:8px;color:#8A5A2B;font-weight:bold">③ 層級提示（不會自動改，請自行判斷）：下列 ' + hs.length + ' 處在條文底下<u>直接</u>用 1) 2)（前面沒有 a. 項目），依標準第一層應為 a. b.：</div><div style="max-height:14vh;overflow:auto;font-size:12px;color:#555">';
        hs.forEach(function(x){ h += '<div>第 ' + x.page + ' 頁　' + apxEsc(x.from) + ' ' + apxEsc(x.ctx) + '…</div>'; });
        h += '</div>';
    }
    var tot = rows.length + lay.length;
    h += '<div style="margin-top:10px;text-align:right;color:#8A5A2B;font-size:12px">套用後尚未存檔；不滿意重新整理頁面即可放棄。</div><div style="text-align:right">' + (tot ? '<button type="button" class="btn btn-warning btn-sm" id="lstApply">套用（標點 ' + rows.length + '、縮排 ' + lay.length + '）</button> ' : '') + '<button type="button" class="btn btn-default btn-sm" id="lstClose">關閉</button></div>';
    var d = document.createElement('div');
    d.id = 'lstDlg';
    d.style.cssText = 'position:fixed;z-index:10700;left:50%;top:50%;transform:translate(-50%,-50%);width:780px;max-width:94vw;max-height:88vh;overflow:auto;background:#fff;border:1px solid #E8890C;border-radius:8px;padding:16px 18px;box-shadow:0 10px 36px rgba(0,0,0,.35);font-size:13px;line-height:1.7;color:#333';
    d.innerHTML = h; document.body.appendChild(d);
    document.getElementById('lstClose').onclick = function(){ d.remove(); };
    var ap = document.getElementById('lstApply');
    if (ap) ap.onclick = function(){
        var ok = 0, ok2 = 0;
        rows.forEach(function(r){ if (lstSetMarker(r.el, r.to)) ok++; });
        lay.forEach(function(x){ lstApplyLayout(x); ok2++; });
        d.remove(); setDirty(true);
        try { ED.reflowQuiet(); } catch (e) {}
        apxToast('已統一 ' + ok + ' 處標點、對齊 ' + ok2 + ' 段（記得存檔）');
    };
}
$('#btnLst').on('click', function(){ if (ED) lstShow(lstScan()); });
// 貼上內容時：新貼進來的段落自動套標準（標點＋對齊上方條文標題）；原有段落不動，免得蓋掉刻意的縮排
$(document).on('paste', '#adEditorHost .egrt-main', function(){
    if (LOCKED || !ED) return;
    var before = new Set();
    lstBlocks().forEach(function(b){ before.add(b.el); });
    setTimeout(function(){
        var r = lstScan(), n = 0;
        r.forEach(function(x){ if (!before.has(x.el) && lstSetMarker(x.el, x.to)) n++; });
        r.layout.forEach(function(x){ if (!before.has(x.el)) { lstApplyLayout(x); n++; } });
        if (n) { setDirty(true); try { ED.reflowQuiet(); } catch (e) {} apxToast('已自動對齊貼上的內容（' + n + ' 處，對齊上方條文標題）'); }
    }, 150);
});
// 在清單項目按 Enter：自動接下一個編號；空項目再按 Enter ＝ 結束清單
$(document).on('keydown', '#adEditorHost .egrt-main', function(e){
    if (LOCKED || e.key !== 'Enter' || e.shiftKey || e.ctrlKey || e.altKey || e.isComposing) return;
    var sel = window.getSelection(); if (!sel || !sel.rangeCount || !sel.isCollapsed) return;
    var host = this, b = sel.anchorNode;
    while (b && b.parentNode !== host) b = b.parentNode;
    if (!b || b.nodeType !== 1 || !/^(P|DIV)$/.test(b.tagName)) return;
    var t = b.textContent || '';
    if (LST_CLAUSE.test(t)) return;
    var m = LST_RE.exec(t); if (!m) return;
    var tail = document.createRange(); tail.setStart(sel.anchorNode, sel.anchorOffset); tail.setEnd(b, b.childNodes.length);
    if (tail.toString().trim() !== '') return;                       // 游標在文字中間＝一般的斷行
    if (t.slice(m[0].length).trim() === '') { e.preventDefault(); lstSetMarker(b, ''); return; }
    var next = m[1] ? String.fromCharCode(m[1].charCodeAt(0) + 1) + '.' : (parseInt(m[2], 10) + 1) + ')';
    if (m[1] && m[1] === 'z') return;
    setTimeout(function(){
        var s2 = window.getSelection(); if (!s2 || !s2.rangeCount) return;
        var nb = s2.anchorNode; while (nb && nb.parentNode !== host) nb = nb.parentNode;
        if (nb && nb !== b && (nb.textContent || '').trim() === '') document.execCommand('insertText', false, next);
    }, 0);
});

/* ── 列印排版檢查（2026-10-27）──────────────────────────────────────────
   「編輯畫面看起來一頁、印出來卻被切成兩張紙、框線沒到底」——最常見的原因是 Word 匯入的大表格比頁寬還寬。
   編輯畫面量不出來（編輯器內表格會被框住），所以用隱藏的 iframe 載入實際的列印頁（as_doc_print.php?check=1），
   量排完之後哪幾張比一張紙高，回報到這裡並在編輯器上方掛紅色提示，要求處理。檢查的是「已存檔的內容」。 */
var LCK_LAST = null, LCK_MANUAL = false;
function layoutCheck() {
    if (!VID) return;
    var old = document.getElementById('lckFrame'); if (old) old.remove();
    var f = document.createElement('iframe');
    f.id = 'lckFrame';
    f.style.cssText = 'position:fixed;left:-12000px;top:0;width:900px;height:1200px;border:0;visibility:hidden';
    f.src = 'as_doc_print.php?version_id=' + VID + '&check=1';
    document.body.appendChild(f);
    setTimeout(function(){ var x = document.getElementById('lckFrame'); if (x) x.remove(); }, 90000);
}
window.addEventListener('message', function(e){
    var d = e.data;
    if (e.origin !== location.origin || !d || !d.egDocCheck) return;
    var f = document.getElementById('lckFrame'); if (f) f.remove();
    LCK_LAST = d; lckBar(d);
});
function lckBar(d) {
    var bar = document.getElementById('lckBar');
    if (!d.bad.length) { if (bar) bar.remove(); if (LCK_MANUAL) apxToast('列印排版檢查：每一頁印出來都是一張紙，沒有問題'); LCK_MANUAL = false; return; }
    LCK_MANUAL = false;
    if (!bar) {
        bar = document.createElement('div'); bar.id = 'lckBar';
        bar.style.cssText = 'margin:6px 0;padding:8px 12px;background:#FBE3DC;border:1px solid #DD5138;border-radius:6px;color:#8a2a12;font-size:13px;line-height:1.7;cursor:pointer';
        var host = document.getElementById('adEditorHost'); host.parentNode.insertBefore(bar, host);
        bar.onclick = function(){ lckShow(LCK_LAST); };
    }
    bar.innerHTML = '<b><i class="fa fa-exclamation-triangle"></i> 列印時有 ' + d.bad.length + ' 頁內容比一張紙還高，會被切到下一張、框線沒到底：</b>'
        + d.bad.map(function(x){ return '第 ' + apxEsc(String(x.page || x.idx).split('/')[0].trim()) + ' 頁'; }).join('、')
        + '　<u>點這裡看原因與處理方式</u>（依已存檔內容檢查）';
}
function lckShow(d) {
    var old = document.getElementById('lckDlg'); if (old) old.remove();
    var seen = {};
    d.bad.forEach(function(x){ var k = x.key || ''; x.nth = seen[k] || 0; seen[k] = x.nth + 1; });
    var h = '<div style="font-weight:bold;font-size:15px;margin-bottom:6px;color:#6B4423"><i class="fa fa-exclamation-triangle"></i> 列印排版檢查（共 ' + d.total + ' 張，' + d.bad.length + ' 張超出）</div>'
      + '<div style="color:#555;margin-bottom:6px">下列頁面在<b>實際列印</b>時比一張紙高，會被切成兩張，第二張只剩半截框線。編輯畫面看起來沒問題，是因為編輯器把超出的部分框住了。</div>'
      + '<table style="width:100%;border-collapse:collapse;font-size:12px"><tr style="background:#F5E9D6"><th style="padding:3px 6px;text-align:left;width:70px">頁次</th><th style="padding:3px 6px;text-align:left;width:80px">印出高度</th><th style="padding:3px 6px;text-align:left;width:110px">原因</th><th style="padding:3px 6px;text-align:left">內容開頭</th></tr>';
    d.bad.forEach(function(x){
        h += '<tr><td style="padding:3px 6px;border-top:1px solid #eadfcb"><a href="#" class="lck-go" data-k="' + apxEsc(x.key || '') + '" data-n="' + (x.nth || 0) + '" title="點一下跳到這一頁的位置" style="color:#B8480A;font-weight:bold;text-decoration:underline">第 ' + apxEsc(String(x.page || x.idx).split('/')[0].trim()) + ' 頁 ➜</a></td><td style="padding:3px 6px;border-top:1px solid #eadfcb">' + x.mm + ' mm</td><td style="padding:3px 6px;border-top:1px solid #eadfcb">' + (x.wide ? '表格比頁寬寬（' + x.wide + ' 個）' : '內容太高') + '</td><td style="padding:3px 6px;border-top:1px solid #eadfcb;color:#555">' + apxEsc(x.text) + '…</td></tr>';
    });
    h += '</table><div style="margin-top:8px;background:#FBF3E4;border:1px solid #eadfcb;border-radius:6px;padding:8px 10px;font-size:12px"><b>處理方式（擇一）：</b>'
      + '<ol style="margin:4px 0 0 18px;padding:0"><li><b>先按「存檔」</b>：編輯器已自動把長表格拆到好幾頁，但<u>沒存檔前列印看到的還是沒拆的版本</u>（列印是照存檔的分頁印的）。存完會自動重新檢查，多半就消失了；</li>'
      + '<li>還在的話：把該頁的表格縮窄（拖曳表格邊框，或刪掉多餘的欄、空白欄），讓它不超過頁寬；</li>'
      + '<li>表格太大（例如組織圖、流程表）：改成圖片（用「插入流程圖」重畫，或貼圖檔），圖片會自動縮進一頁；</li>'
      + '<li>內容太高：把表格拆成兩頁，在中間插入分頁。</li></ol>'
      + '<div style="margin-top:4px;color:#8A5A2B">改完請存檔，存檔後系統會自動重新檢查；紅色提示消失就代表印出來每頁都是一張紙。</div></div>'
      + '<div style="margin-top:10px;text-align:right"><button type="button" class="btn btn-default btn-sm" id="lckRe">重新檢查</button> <button type="button" class="btn btn-warning btn-sm" id="lckClose">關閉</button></div>';
    var dg = document.createElement('div');
    dg.id = 'lckDlg';
    dg.style.cssText = 'position:fixed;z-index:10700;left:50%;top:50%;transform:translate(-50%,-50%);width:720px;max-width:94vw;max-height:84vh;overflow:auto;background:#fff;border:1px solid #DD5138;border-radius:8px;padding:16px 18px;box-shadow:0 10px 36px rgba(0,0,0,.35);font-size:13px;line-height:1.7;color:#333';
    dg.innerHTML = h; document.body.appendChild(dg);
    document.getElementById('lckClose').onclick = function(){ dg.remove(); };
    Array.prototype.forEach.call(dg.querySelectorAll('.lck-go'), function(a){
        a.onclick = function(ev){ ev.preventDefault(); dg.remove(); lckGo(a.getAttribute('data-k'), parseInt(a.getAttribute('data-n'), 10) || 0); };
    });
    document.getElementById('lckRe').onclick = function(){ dg.remove(); apxToast('重新檢查中…'); layoutCheck(); };
}
/** 依列印檢查回報的「那個表格／區塊的開頭文字」找到編輯器裡的位置：捲到畫面中間、游標放進去、閃一下框線 */
function lckGo(key, nth) {
    var hits = [];
    key = String(key || '');
    if (key) apxSheets().forEach(function(sh){
        Array.prototype.forEach.call(sh.children, function(c){
            var els = [c].concat(Array.prototype.slice.call(c.querySelectorAll ? c.querySelectorAll('table') : []));
            els.forEach(function(e){
                if ((e.textContent || '').replace(/\s+/g, '').indexOf(key) === 0 && (!hits.length || hits[hits.length - 1] !== e)) hits.push(e);
            });
        });
    });
    // 同一個 key 可能 block 與 table 都命中：只留最內層的（table），照出現順序取第 nth 個
    hits = hits.filter(function(e, i){ return !hits.some(function(o, j){ return j !== i && e.contains(o) && o !== e; }); });
    var el = hits[nth] || hits[0];
    if (!el) { apxToast('找不到那個位置（內容可能已改過），請按「重新檢查」'); return; }
    el.scrollIntoView({ behavior: 'smooth', block: 'center' });
    try {
        var tw = document.createTreeWalker(el, NodeFilter.SHOW_TEXT, null, false), tn = tw.nextNode();
        var sh = el.closest('.egrt-main'); if (sh) sh.focus();
        if (tn) { var rg = document.createRange(); rg.setStart(tn, 0); rg.collapse(true); var sel = window.getSelection(); sel.removeAllRanges(); sel.addRange(rg); }
    } catch (e) {}
    var oldOutline = el.style.outline;
    el.style.outline = '3px solid #E8890C';
    setTimeout(function(){ el.style.outline = oldOutline; if (!el.getAttribute('style')) el.removeAttribute('style'); }, 2500);
}
$('#btnLck').on('click', function(){ LCK_MANUAL = true; apxToast('檢查列印排版中（約 10 秒）…'); layoutCheck(); });
if (!LOCKED) setTimeout(layoutCheck, 4000);        // 開啟編輯器就檢查一次

/* ── 尋找／取代（2026-10-27；Ctrl＋H）────────────────────────────────────
   整份文件（所有頁）逐段文字比對。「取代全部」直接改文字節點（不能 Ctrl+Z 一次還原，所以會先講明筆數）；
   「取代」只改目前選到的那一處（走 insertText，可以復原）。
   常見用途：中文輸入法的全形標點（：；，）一次換成半形（: ; ,），或反過來。 */
var FR = { pos: 0 };
function frNodes() {
    var out = [];
    apxSheets().forEach(function(sh){
        var tw = document.createTreeWalker(sh, NodeFilter.SHOW_TEXT, null, false), n;
        while ((n = tw.nextNode())) out.push(n);
    });
    return out;
}
function frVals() {
    return { f: document.getElementById('frFind').value, r: document.getElementById('frRep').value, ic: document.getElementById('frIc').checked };
}
function frMatches(v) {
    var out = [];
    if (!v.f) return out;
    var needle = v.ic ? v.f.toLowerCase() : v.f;
    frNodes().forEach(function(n){
        var hay = v.ic ? n.nodeValue.toLowerCase() : n.nodeValue, i = 0;
        while ((i = hay.indexOf(needle, i)) >= 0) { out.push({ n: n, i: i }); i += needle.length; }
    });
    return out;
}
function frSay(msg) { var e = document.getElementById('frMsg'); if (e) e.textContent = msg; }
function frSelect(m, len) {
    var rg = document.createRange();
    rg.setStart(m.n, m.i); rg.setEnd(m.n, m.i + len);
    var sh = m.n.parentElement && m.n.parentElement.closest('.egrt-main'); if (sh) sh.focus();
    var sel = window.getSelection(); sel.removeAllRanges(); sel.addRange(rg);
    var el = m.n.parentElement; if (el && el.scrollIntoView) el.scrollIntoView({ block: 'center' });
}
function frNext() {
    var v = frVals(), ms = frMatches(v);
    if (!ms.length) { frSay('找不到「' + v.f + '」'); return null; }
    // 從目前游標之後找起，找不到就繞回最前面
    var sel = window.getSelection(), cur = -1;
    if (sel.rangeCount) {
        var r0 = sel.getRangeAt(0);
        for (var k = 0; k < ms.length; k++) {
            var t = document.createRange(); t.setStart(ms[k].n, ms[k].i);
            if (t.compareBoundaryPoints(Range.START_TO_START, r0) > 0 || (t.compareBoundaryPoints(Range.START_TO_START, r0) === 0 && !r0.collapsed && false)) { cur = k; break; }
        }
    }
    if (cur < 0) cur = 0;
    frSelect(ms[cur], v.f.length);
    frSay('第 ' + (cur + 1) + ' / ' + ms.length + ' 處');
    return ms[cur];
}
function frReplaceOne() {
    var v = frVals(); if (!v.f) return;
    var sel = window.getSelection(), txt = sel.rangeCount ? sel.toString() : '';
    if ((v.ic ? txt.toLowerCase() : txt) === (v.ic ? v.f.toLowerCase() : v.f)) {
        document.execCommand('insertText', false, v.r);
        setDirty(true);
    }
    frNext();
}
function frReplaceAll() {
    var v = frVals(); if (!v.f) { frSay('請先輸入要找的文字'); return; }
    var ms = frMatches(v);
    if (!ms.length) { frSay('找不到「' + v.f + '」'); return; }
    if (!confirm('要把全文 ' + ms.length + ' 處「' + v.f + '」都換成「' + v.r + '」嗎？\n（換完可以重新整理頁面放棄；存檔後就不能復原了）')) return;
    var byNode = [];
    ms.forEach(function(m){ var o = byNode.filter(function(x){ return x.n === m.n; })[0]; if (!o) byNode.push(o = { n: m.n, ix: [] }); o.ix.push(m.i); });
    byNode.forEach(function(o){
        var s = o.n.nodeValue;
        o.ix.sort(function(a, b){ return b - a; }).forEach(function(i){ s = s.slice(0, i) + v.r + s.slice(i + v.f.length); });
        o.n.nodeValue = s;
    });
    setDirty(true);
    try { ED.reflowQuiet(); } catch (e) {}
    frSay('已取代 ' + ms.length + ' 處（記得存檔）');
    apxToast('已取代 ' + ms.length + ' 處');
}
function frOpen(pre) {
    var d = document.getElementById('frDlg');
    if (!d) {
        d = document.createElement('div'); d.id = 'frDlg';
        d.style.cssText = 'position:fixed;z-index:10650;right:24px;top:120px;width:360px;background:#fff;border:1px solid #E8890C;border-radius:8px;padding:12px 14px;box-shadow:0 6px 24px rgba(0,0,0,.3);font-size:13px;color:#333';
        d.innerHTML = '<div style="font-weight:bold;margin-bottom:6px;color:#6B4423"><i class="fa fa-exchange"></i> 尋找與取代 <span id="frX" style="float:right;cursor:pointer;color:#999">✕</span></div>'
          + '<input id="frFind" class="form-control input-sm" placeholder="尋找" data-eg-skip style="margin-bottom:5px">'
          + '<input id="frRep" class="form-control input-sm" placeholder="取代為（留空＝刪除）" data-eg-skip style="margin-bottom:6px">'
          + '<label style="font-weight:normal;margin:0 0 6px"><input type="checkbox" id="frIc"> 不分大小寫</label>'
          + '<div style="margin-bottom:6px;font-size:12px;color:#8A5A2B">快速帶入：<a href="#" class="fr-pre" data-f="：" data-r=":">全形 ： → 半形 :</a>　<a href="#" class="fr-pre" data-f=":" data-r="：">半形 : → 全形 ：</a></div>'
          + '<div><button type="button" class="btn btn-default btn-sm" id="frNext">找下一個</button> <button type="button" class="btn btn-default btn-sm" id="frOne">取代</button> <button type="button" class="btn btn-warning btn-sm" id="frAll">全部取代</button></div>'
          + '<div id="frMsg" style="margin-top:6px;color:#6B4423;min-height:18px"></div>';
        document.body.appendChild(d);
        document.getElementById('frX').onclick = function(){ d.style.display = 'none'; };
        document.getElementById('frNext').onclick = frNext;
        document.getElementById('frOne').onclick = frReplaceOne;
        document.getElementById('frAll').onclick = frReplaceAll;
        Array.prototype.forEach.call(d.querySelectorAll('.fr-pre'), function(a){
            a.onclick = function(e){ e.preventDefault(); document.getElementById('frFind').value = a.getAttribute('data-f'); document.getElementById('frRep').value = a.getAttribute('data-r'); frSay('已帶入，按「全部取代」'); };
        });
        d.addEventListener('keydown', function(e){
            if (e.key === 'Enter') { e.preventDefault(); frNext(); }
            if (e.key === 'Escape') d.style.display = 'none';
        });
    }
    d.style.display = '';
    var f = document.getElementById('frFind');
    var sel = window.getSelection(), st = (pre != null) ? pre : (sel && sel.rangeCount && !sel.isCollapsed ? sel.toString() : '');
    if (st && st.length < 60) f.value = st;
    f.focus(); f.select();
}
$('#btnFr').on('click', function(){ frOpen(); });
$(document).on('keydown', function(e){
    if ((e.ctrlKey || e.metaKey) && String(e.key).toLowerCase() === 'h' && !LOCKED && ED) { e.preventDefault(); frOpen(); }
});

// 在條文／內文按 Enter 換行：新的一段自動對齊上方條文標題的文字起點（不照搬標題的懸掛縮排）
$(document).on('keydown', '#adEditorHost .egrt-main', function(e){
    if (LOCKED || e.key !== 'Enter' || e.shiftKey || e.ctrlKey || e.altKey || e.isComposing) return;
    var host = this, sel = window.getSelection(); if (!sel || !sel.rangeCount) return;
    var b = sel.anchorNode; while (b && b.parentNode !== host) b = b.parentNode;
    if (!b || b.nodeType !== 1 || !/^(P|DIV|H[1-6])$/.test(b.tagName)) return;
    setTimeout(function(){
        var s2 = window.getSelection(); if (!s2 || !s2.rangeCount) return;
        var nb = s2.anchorNode; while (nb && nb.parentNode !== host) nb = nb.parentNode;
        if (!nb || nb === b || nb.nodeType !== 1 || !/^(P|DIV)$/.test(nb.tagName)) return;
        LST_NEW = nb;
        try { lstScan().layout.forEach(function(x){ if (x.el === nb) lstApplyLayout(x); }); } finally { LST_NEW = null; }
    }, 40);
});

/* ── 存檔 ─────────────────────────────────────────────────────────────── */
var SAVED_HTML = null, SAVED_TS = 0;
function save(cb) {
    if (!CAN_EDIT || !ED || LOCKED) return;
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
    // 附錄：存檔前把已連結的內文參照照附錄標題更新編號、讓大附錄從新的一頁開始；有改動存完後跳出清單
    var apxRep = null;
    try { apxRep = apxRun(false); } catch (e) { apxRep = null; }
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
        SAVED_HTML = _html; SAVED_TS = Date.now();
        $('#adSaved').text('已存檔 ' + new Date().toLocaleTimeString('zh-TW', {hour:'2-digit',minute:'2-digit'}));
        paintPrimaryBadge({ html: ED.get(), is_primary: $('#chkPrimary').is(':checked') ? 1 : 0 });
        if (apxRep && (apxRep.changes.length || apxRep.missing.length || apxRep.breaks.length)) apxShow(apxRep);
        setTimeout(layoutCheck, 800);       // 存完用實際列印排法再檢查一次
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
$('#btnPrint').length && $('#btnPrint').on('click', function(){
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
