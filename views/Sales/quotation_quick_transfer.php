<?php
// quotation_quick_transfer.php — 報價單快速轉移頁（補建舊資料專用：快速設定製程/綁定料號ID/切換客戶，確認後批次轉入正式報價單）
session_start();
if (!isset($_SESSION['userName'])) { header("Location:../../index.php"); exit; }
include '../../src/common/DBConnection.php';
$conn = new DBConnection();
$pdo  = $conn->getPDO();
$uid  = intval($_SESSION['id'] ?? 0);

// 權限：沿用報價單管理頁的 module_code='quotation_list'，尚無任何權限記錄時視為全員開放（與 Quotation_API.php 既有慣例一致）
$canEdit = true;
try {
    $total = (int)$pdo->query("SELECT COUNT(*) FROM user_module_permissions WHERE module_code='quotation_list'")->fetchColumn();
    if ($total > 0) {
        $ck = $pdo->prepare("SELECT permission FROM user_module_permissions WHERE user_id=? AND module_code='quotation_list' LIMIT 1");
        $ck->execute([$uid]);
        $perm = (string)$ck->fetchColumn();
        $canEdit = (strpos($perm, 'A') !== false || strpos($perm, 'U') !== false);
    }
} catch (Exception $e) {}
?>
<!DOCTYPE html>
<html lang="zh-Hant">
<head>
    <meta http-equiv="Content-Type" content="text/html; charset=UTF-8">
    <meta charset="utf-8">
    <meta http-equiv="X-UA-Compatible" content="IE=edge">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>報價單快速轉移</title>
    <link href="../../resource/css/bootstrap.css" rel="stylesheet">
    <link href="../../resource/css/font-awesome.css" rel="stylesheet">
    <link href="../../resource/css/nprogress.css" rel="stylesheet">
    <link href="../../resource/css/custom.css" rel="stylesheet">
    <style>
        #sidebar-menu { visibility: hidden; }
        .right_col .page-title { margin:8px 0 4px; overflow:hidden; clear:both; }
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

        .va-mask { display:none; position:fixed; inset:0; background:rgba(60,40,20,.45); z-index:1050; }
        .va-modal { background:#fff; border-radius:8px; max-width:560px; margin:36px auto; box-shadow:0 5px 25px rgba(0,0,0,.3);
            max-height:88vh; display:flex; flex-direction:column; }
        .va-modal.wide { max-width:860px; }
        .va-modal .m-head { background:#F7E0BD; color:#5b3a1e; font-weight:bold; padding:10px 15px; border-radius:8px 8px 0 0;
            display:flex; justify-content:space-between; align-items:center; }
        .va-modal .m-head .m-close { cursor:pointer; color:#b5762a; }
        .va-modal .m-body { padding:15px; overflow-y:auto; }
        .va-modal .m-foot { padding:10px 15px; border-top:1px solid #EADFC8; text-align:right; }

        .qt-stats { display:flex; gap:10px; margin-bottom:10px; flex-wrap:wrap; }
        .qt-stat-chip { background:#FFF7E8; border:1px solid #F0A24B; border-radius:6px; padding:6px 12px; font-size:12px; color:#6B471A; }
        .qt-stat-chip b { font-size:15px; }
        .qt-badge { display:inline-block; font-size:11px; padding:1px 7px; border-radius:10px; margin-right:3px; }
        .qt-badge.ok   { background:#E4F3E4; color:#2e7d32; }
        .qt-badge.warn { background:#FDEBD3; color:#a2703a; }
        .qt-badge.note { background:#8a5a2b; color:#fff; }
        .qt-bar { display:flex; align-items:center; flex-wrap:wrap; gap:8px; font-size:12px; margin-bottom:8px; }
        .qt-bar label { margin:0; font-weight:400; display:inline-flex; align-items:center; gap:4px; }
        .qt-bar-label { display:inline-block; min-width:34px; color:#8a5a2b; font-weight:700; }
        .qt-bar-sep { display:inline-block; width:1px; height:20px; background:#EADFC8; }
        .qt-note-only { background:#FBF3E6; border:1px dashed #E4C293; border-radius:6px; padding:4px 8px; color:#5b3a1e; }

        /* 關鍵字自動偵測：依建議標籤組合分組確認 */
        .kw-group { border:1px solid #EADFC8; border-radius:6px; margin-bottom:8px; background:#fff; }
        .kw-group-head { display:flex; align-items:center; gap:8px; flex-wrap:wrap; padding:6px 10px; background:#FBF6EC; cursor:pointer; }
        .kw-group-head .kw-label { font-weight:700; color:#5b3a1e; }
        .kw-group-head .kw-rules { font-size:11px; color:#a2703a; }
        .kw-group-body { display:none; max-height:280px; overflow:auto; padding:4px 10px 8px; }
        .kw-group.open .kw-group-body { display:block; }
        table.kw-item-table { width:100%; border-collapse:collapse; font-size:12px; }
        table.kw-item-table td { padding:3px 5px; border-bottom:1px dashed #eee; }
        .kw-rule-row td { padding:4px 6px; border-bottom:1px solid #f0e6d6; font-size:12px; vertical-align:top; }
        .kw-cust-box { max-height:150px; overflow:auto; border:1px solid #E4C293; border-radius:4px; padding:4px 6px; font-size:12px; }
        .kw-err { color:#DD5138; font-size:11px; }
        .qt-row-chk:disabled { opacity:.35; cursor:not-allowed; }

        .qt-card { background:#fff; border:1px solid #EADFC8; border-radius:8px; margin-bottom:12px; overflow:hidden; }
        .qt-card-head { display:flex; align-items:center; flex-wrap:wrap; gap:10px; background:#FBF6EC; padding:8px 12px; border-bottom:1px solid #EADFC8; font-size:13px; }
        .qt-card-head .qno { font-weight:700; color:#5b3a1e; }
        .qt-card-body { padding:8px 12px; }

        table.qt-item-table { width:100%; border-collapse:collapse; font-size:12px; }
        table.qt-item-table th { text-align:left; color:#8a5a2b; padding:4px 6px; border-bottom:1px solid #E4C293; }
        table.qt-item-table td { padding:5px 6px; border-bottom:1px dashed #e9dcc4; vertical-align:top; }

        .qt-proc-l1 button, .qt-proc-l2 button { font-size:11px; padding:1px 8px; margin:1px 2px 1px 0; border-radius:10px;
            border:1px solid #E4C293; background:#fff; color:#8a5a2b; cursor:pointer; }
        .qt-proc-l1 button.active { background:#E4C293; color:#4E2C0B; font-weight:700; }
        .qt-proc-l2 button.active { background:#8a5a2b; color:#fff; border-color:#8a5a2b; }
        .qt-proc-chips { margin-top:2px; }
        .qt-proc-chip { display:inline-block; background:#E4C293; color:#4E2C0B; font-size:11px; padding:1px 6px; border-radius:8px; margin:1px; }
        .qt-proc-chip .x { cursor:pointer; margin-left:3px; color:#8a5a2b; }

        .qt-search-box { position:relative; }
        .qt-search-results { position:absolute; z-index:20; background:#fff; border:1px solid #E4C293; border-radius:4px;
            max-height:220px; overflow-y:auto; width:280px; box-shadow:0 3px 12px rgba(0,0,0,.15); display:none; }
        .qt-search-results div.qt-sr-item { padding:5px 8px; font-size:12px; cursor:pointer; border-bottom:1px solid #f4ecd9; }
        .qt-search-results div.qt-sr-item:hover { background:#FFF7E8; }
        .qt-search-results .qt-sr-new { padding:6px 8px; font-size:12px; color:#2e7d32; cursor:pointer; background:#F3FAF3; }
        .qt-search-results .qt-sr-new:hover { background:#E4F3E4; }

        .qt-quickform { border:1px dashed #E4C293; border-radius:4px; padding:6px; margin-top:4px; background:#FFFBF3; }
        .qt-quickform input { font-size:11px; margin-bottom:3px; }

        .qt-pagination { margin-top:10px; text-align:right; }
        .qt-pagination button { margin-left:4px; }

        .qt-scroll-top { position:fixed; bottom:20px; right:20px; width:46px; height:46px; background:#F0A24B;
            color:#fff; border:none; border-radius:50%; text-align:center; line-height:46px; cursor:pointer;
            font-size:18px; box-shadow:0 4px 8px rgba(0,0,0,.25); transition:background .2s; z-index:1000; display:none; }
        .qt-scroll-top:hover { background:#d98a33; }
        @media print { .qt-scroll-top { display:none !important; } }
    </style>
</head>
<body class="nav-sm">
<button class="qt-scroll-top" id="qtScrollTop" onclick="window.scrollTo({top:0,behavior:'smooth'})" title="回頂端"><i class="fa fa-arrow-up"></i></button>
<div class="container body">
    <div class="main_container">
        <?php include '../partPage/sideAndTopBarMenu.html' ?>
        <div class="right_col" role="main">
            <div class="page-title" style="display:flex;align-items:center;">
                <div class="title_left"><h3>報價單快速轉移 <small>補建舊資料：設定製程／綁定料號ID／切換客戶，確認後轉入正式</small></h3></div>
                <button id="btnPageHelp" class="page-help-btn" style="margin-left:auto;"><i class="fa fa-question-circle"></i> 使用說明</button>
            </div>
            <div class="clearfix"></div>

            <div class="qt-stats" id="qtStats"></div>
            <!-- 上排＝篩選條件、下排＝動作按鈕：兩種東西混在一排時很難找（使用者回報排得很亂） -->
            <div class="qt-bar">
                <span class="qt-bar-label">篩選</span>
                <label>年份
                    <select id="qtYearFilter" class="form-control input-sm" style="display:inline-block;width:150px;"><option value="">全部</option></select>
                </label>
                <label>客戶
                    <select id="qtCustFilter" class="form-control input-sm" style="display:inline-block;width:240px;"
                            data-eg-filter="輸入客戶編號或名稱篩選…"><option value="">全部客戶</option></select>
                </label>
                <label><input type="checkbox" id="qtOnlyUnbound"> 只看未綁定料號ID的項目</label>
                <span class="qt-bar-sep"></span>
                <label><input type="checkbox" id="qtCheckAll"> 全選本頁</label>
                <span id="qtSelCount" style="color:#888;"></span>
            </div>
            <div class="qt-bar">
                <span class="qt-bar-label">動作</span>
                <button class="btn btn-warning btn-sm" id="btnBatchConfirm" <?= $canEdit ? '' : 'disabled' ?>>
                    <i class="fa fa-check"></i> 批次轉入正式報價單
                </button>
                <?php if ($canEdit): ?>
                <button class="btn btn-success btn-sm" id="btnTransferReady"
                        title="把目前篩選範圍內「料號ID與製程都已補齊」的報價單全部轉入正式報價單">
                    <i class="fa fa-check-square-o"></i> 一鍵轉入已補齊 <span id="qtReadyCnt">(0)</span>
                </button>
                <span class="qt-bar-sep"></span>
                <button class="btn btn-success btn-sm" id="btnBatchAutoBind"
                        title="把目前年份篩選範圍內、所有還沒綁完料號ID的報價單一次處理完">
                    <i class="fa fa-magic"></i> 一鍵建立並綁定料號（全部）
                </button>
                <button class="btn btn-default btn-sm" id="btnBulkProc"
                        title="把同一組製程一次套用到目前篩選範圍內的項目">
                    <i class="fa fa-tags"></i> 批次設定製程
                </button>
                <button class="btn btn-warning btn-sm" id="btnKwScan"
                        title="依規格文字的關鍵字規則，一次建議整批項目的製程標籤，再由您分組確認">
                    <i class="fa fa-search"></i> 關鍵字自動偵測製程
                </button>
                <button class="btn btn-default btn-sm" id="btnKwRules" title="設定關鍵字規則">
                    <i class="fa fa-cog"></i> 規則設定
                </button>
                <?php endif; ?>
                <?php if (!$canEdit): ?>
                    <span style="color:#c0392b;">您沒有編輯權限，僅供檢視</span>
                <?php endif; ?>
            </div>

            <div id="qtCards"></div>
            <div class="qt-pagination" id="qtPagination"></div>

            <?php include '../partPage/footer.html' ?>
        </div>
    </div>
</div>

<!-- 使用說明 -->
<div class="va-mask" id="helpUseMask"><div class="va-modal wide">
    <div class="m-head"><span><i class="fa fa-question-circle"></i> 報價單快速轉移 使用說明</span><span class="m-close" onclick="closeMask('helpUseMask')">✕</span></div>
    <div class="m-body help-doc">
        <h4>功能說明</h4>
        <p>用於補建舊報價單資料（例如 ERP 直接匯入的歷史報價單）。這類資料匯入時只有料號/數量/單價，<b>沒有製程分類、沒有綁定正式的料號ID(d_setting)</b>，客戶代碼也可能因年代久遠而與現行代碼不同。本頁讓您逐張快速補齊這些資訊，確認後再轉入正式報價單清單。</p>
        <h4>操作步驟</h4>
        <ul>
            <li>清單只列出「尚待確認」的報價單，確認轉入後就會從本頁消失（不會刪除，只是不再顯示於此）。每張報價單的所有料號明細直接顯示，不需點開。</li>
            <li><b>設定製程</b>：跟報價單管理頁一樣的製程標籤（先選大類再選子標籤，可複選），點一下即存檔。<b>有些標籤（例如磨銳、鍍TIN、熱處理、刀具類）本身沒有對應到 ERP 的製程代號</b>，只要點了標籤就算已設定製程，一樣可以轉入正式報價單。</li>
            <li><b>綁定料號ID</b>：在「料號ID綁定」欄搜尋料號關鍵字，點選正確的項目即可綁定；<b>找不到就直接在搜尋結果下方按「＋新增料號」</b>快速建立並自動綁定。</li>
            <li><b>切換客戶</b>：點客戶欄位旁的「切換」，搜尋並選擇正確的客戶；找不到一樣可以「＋新建客戶」；跳窗內按 <b>Enter</b> 等同直接送出（唯一符合的搜尋結果或已填妥的新建表單）。</li>
            <li>補齊後，可以用每張報價單右上角的「轉正式報價單」單張轉入，或勾選多張後用上方「批次轉入正式報價單」一次轉入。<b>料號ID或製程沒有全部補齊的報價單，按鈕與勾選框會是反灰的</b>，滑鼠移上去會說明還缺什麼、缺幾筆。</li>
            <li>清單右上角可篩選<b>年份</b>（<b>預設顯示最新年份</b>，可切到其他年份或「全部」）與<b>客戶</b>（下拉可直接打字，客戶編號或名稱都找得到，選項後面是該客戶還有幾張待確認）；報價單依日期新到舊排序。</li>
            <li><b>只看未綁定料號ID的項目</b>：勾起來之後清單只留還沒綁完料號的報價單，而且卡片裡也只列出還沒綁的那幾列，可以直接一列一列按「快速綁定」，不用在整張單裡找。</li>
            <li><b>批次設定製程</b>：先用上面的客戶／年份篩到一批（同一個客戶的單製程多半相同），再按「批次設定製程」選好標籤一次套用。套用範圍可選<b>目前篩選的全部報價單</b>（預設，會跨頁）或<b>只有目前這一頁</b>（想先套一頁確認結果再放大範圍時用）；套用對象可選<b>只套用到還沒設定製程的項目</b>（預設）或<b>全部項目</b>（會覆蓋原本設定）。跳窗上的「預計影響 N 筆」會依這兩個選擇即時重算。</li>
            <li><b>關鍵字自動偵測製程</b>（最快的補法）：先按「規則設定」訂好規則——規則只比對<b>規格</b>文字，「包含」用逗號連接＝這些字<b>全部都要有</b>（想表達「這個或那個」請在同一個關鍵字裡用 <code>|</code>，例 <code>冶具|治具</code>）、「不包含」用逗號連接＝<b>任何一個出現就不算命中</b>；可指定<b>只適用某幾個客戶</b>（多選，任一符合即可），不指定＝通用規則。第一次可按「載入建議規則範本」一次建好一組常見規則再自行增修。設好後按「關鍵字自動偵測製程」，系統把命中的項目<b>依建議的標籤組合分組</b>（顯示筆數與命中的規則），整組一次確認、也可以展開逐筆取消個別項目，按「套用已勾選的項目」才會真的寫進去——<b>系統只建議，絕不自動套用</b>。</li>
            <li><b>帶入備註</b>：有些規格（例如「半月型六角口模 線割對半」）根本沒有對應的製程標籤，這時按製程欄的「帶入備註」，規格文字會帶進<b>整張報價單的備註欄</b>（自動維護的【規格備註】區塊，您自己寫的備註不會被動到），該列直接顯示為「備註」，<b>也算補齊了製程</b>可以轉入正式報價單；按「取消，改設定製程」即還原，備註那一行會自動消失。規則裡也可以勾「帶入備註」，讓某類關鍵字整批走這條路。</li>
            <li><b>一鍵轉入已補齊</b>：依標籤分組確認之後，您不會知道哪幾張整張都補完了——按鈕上的數字就是目前篩選範圍內<b>料號ID與製程都補齊</b>的張數，按下去一次全部轉入正式報價單。</li>
            <li><b>分頁</b>可直接跳到<b>第一頁／最後一頁</b>；清單只要重畫（轉入、套用、換頁、改篩選），「全選本頁」的勾一律清空，避免誤以為還有選著的報價單。</li>
            <li><b>轉入正式之後不會跳回第一頁</b>：轉走的那幾張直接從清單移除，畫面停在原本的頁次與捲動位置，右下角以小提示告知結果，方便連續作業。</li>
            <li>上方統計列的「<b>最新資料日期</b>」是把目前尚待確認的所有報價單<b>由 OP 單號本身解析</b>出來的日期（OP＋民國年3碼＋月日4碼，例：OP1071228004 → 2018.12.28）取最新的一天，可用來看舊資料補到哪一天；括號內是該日期取自哪一張單號。此欄不受年份篩選影響，一律以全部尚待確認的報價單計算。</li>
        </ul>
        <div class="tip"><b>料號ID與製程都補齊的報價單才可以轉入正式報價單</b>——該張報價單每一筆項目都綁好料號ID、也都設好製程之後，「轉正式報價單」按鈕與勾選框才會自動解鎖（不必重新整理頁面），「全選本頁」也只會勾到可以轉入的那幾張。卡片上的兩個徽章就是這兩項的完成度。</div>
        <h4>加快補件速度</h4>
        <ul>
            <li><b>製程「複製上一筆」</b>：一鍵複製同報價單中前一項的製程設定。</li>
            <li><b>製程「套用到本單全部」</b>：把目前這筆的製程設定一次套用到同報價單其餘所有項目（會覆蓋原設定，套用前會再次確認）。</li>
            <li><b>只找到一筆完全相同的料號時直接綁定</b>：按「快速綁定」後，如果搜尋結果只有一筆、而且料號名稱與這一列的料號文字完全一樣，系統直接綁上、不再開跳窗要您按一次確認。唯一的例外是「本客戶底下找不到、退而求其次全範圍找到，而且那一筆已經綁在別的客戶底下」——那會照常開跳窗讓您確認，因為跨客戶綁定會把別家的圖面與檢驗標準一起接過來。</li>
            <li><b>整批一鍵建立並綁定料號</b>：頁面最上方的「一鍵建立並綁定料號（全部）」會把<b>目前年份篩選範圍內</b>所有還沒綁完料號的報價單一次處理完（規則與下方單張的完全相同），做完會列出處理了幾張、新建與沿用各幾筆、哪些因為多筆同名沒綁、哪些整張跳過（例如還沒設定客戶）。<b>刻意不做成一進頁面就自動執行</b>——這個動作會在料號主檔建立新料號、建立後不會自動刪除，每次開頁自動跑太危險（例如某張單客戶設錯，會一口氣建出一批掛錯客戶的料號），所以固定要按一次並確認。</li>
            <li><b>整張單一鍵建立並綁定料號</b>：料號ID還沒綁完的報價單，卡片右上角有「一鍵建立並綁定料號」。按下去會把這張單<b>所有</b>未綁定的項目一次處理完：依每一筆的料號文字先找既有料號（先找本單客戶的，再找沒有綁客戶的），<b>都找不到才以本單客戶新建一筆</b>再綁上——跟跳窗裡「找不到？新增此料號（綁此客戶）」同一條規則。同一張單裡重複出現的料號只會建立一筆；做完會列出新建了哪些、沿用了哪些。<b>比對用的是「完全同名」而不是模糊比對，所以不會撿到相似料號</b>；萬一料號主檔裡有多筆完全同名的登錄（舊資料有這種重複），系統無法判斷該用哪一筆，會<b>保持該筆未綁定並列出來</b>，請改用那一列的「快速綁定」自己挑。<b>這張單必須先設定客戶</b>（新建的料號要綁到客戶），還沒設定會擋下並提示先用「切換」設定。</li>
            <li><b>綁定料號後自動偵測同料號</b>：綁定或新增料號時，系統會找出「尚待確認」報價單中同料號文字、同客戶、還沒綁定的其他項目（常見於同一張報價單內同料號不同數量級距），跳窗列出讓您勾選是否一併綁定，不用逐筆重複搜尋。</li>
        </ul>
        <h4>重要行為</h4>
        <ul>
            <li>本頁的修改只作用在「尚待確認」的報價單，一旦轉入正式，請回報價單管理頁編輯（本頁會拒絕再次修改已正式的資料）。</li>
            <li><b>為什麼料號ID與製程一定要補齊才能轉正</b>：料號ID(d_setting)是全系統判定「這筆報價屬於哪個料號」的唯一依據，沒綁定的話出貨統計、歷史單價、毛利分析都認不到這張報價單；製程則決定這筆報價的加工內容，沒設定的話報價單列印與後續轉訂單都看不出要做什麼。而且轉正之後這張單就不再出現在本頁，也無法再從這裡補，等於永久漏掉。因此兩項都是<b>強制擋下</b>，不是提示——後端同樣會擋，不能繞過畫面直接送出。</li>
            <li>綁定料號ID／設定製程／切換客戶都是<b>單張報價單/單筆項目</b>的修正，不會像料號管理頁的「移轉綁定」一樣影響全系統其他歷史資料。</li>
            <li>轉入正式時：若這張報價單本身沒有真實填表人資訊（ERP匯入本來就沒有這項資料），系統會自動標記為「業務公用」帳號製表；<b>核准欄位刻意留空不自動核准</b>——系統無法確認幾年前當時真正的業務主管是誰，與其虛構一筆假的核准紀錄，不如留白讓有需要的人自行判斷；也因此<b>不會</b>發送「待核准」通知給現在的主管。</li>
        </ul>
        <h4>權限</h4>
        <p>沿用報價單管理頁權限（module: quotation_list），需要 U（修改）或 A（管理）權限才能編輯，僅檢閱者唯讀。</p>
    </div>
    <div class="m-foot"><button class="btn btn-warning" onclick="closeMask('helpUseMask')">我知道了</button></div>
</div></div>

<!-- 批次設定製程 -->
<div class="va-mask" id="bulkProcMask"><div class="va-modal wide">
    <div class="m-head"><span><i class="fa fa-tags"></i> 批次設定製程</span><span class="m-close" onclick="closeMask('bulkProcMask')">✕</span></div>
    <div class="m-body">
        <div style="font-size:12px;color:#8a5a2b;margin-bottom:8px;">套用範圍　<span id="bpScopeText"></span></div>
        <div style="margin-bottom:6px;font-size:12px;">
            <label style="margin-right:14px;"><input type="radio" name="bpScope" value="filtered" checked> 套用到<b>目前篩選的全部報價單</b>（<span id="bpScopeAllCnt">0</span> 張）</label>
            <label><input type="radio" name="bpScope" value="page"> 只套用到<b>目前這一頁</b>（<span id="bpScopePageCnt">0</span> 張）</label>
        </div>
        <div style="margin-bottom:8px;font-size:12px;">
            <label style="margin-right:14px;"><input type="radio" name="bpTarget" value="unset" checked> 只套用到<b>還沒設定製程</b>的項目</label>
            <label><input type="radio" name="bpTarget" value="all"> 套用到<b>全部</b>項目（會覆蓋原本設定）</label>
        </div>
        <div style="font-size:12px;margin-bottom:10px;">預計影響 <b id="bpCount">0</b> 筆項目
            <span id="bpNeedLoad" style="color:#a2703a;"></span></div>
        <div id="bpTagArea"></div>
    </div>
    <div class="m-foot">
        <button class="btn btn-default" onclick="closeMask('bulkProcMask')">取消</button>
        <button class="btn btn-warning" id="bpSubmitBtn" onclick="submitBulkProc()"><i class="fa fa-check"></i> 套用</button>
    </div>
</div></div>

<!-- 關鍵字自動偵測結果：依建議的標籤組合分組確認 -->
<div class="va-mask" id="kwScanMask"><div class="va-modal wide">
    <div class="m-head"><span><i class="fa fa-search"></i> 關鍵字自動偵測製程</span><span class="m-close" onclick="closeMask('kwScanMask')">✕</span></div>
    <div class="m-body">
        <div style="font-size:12px;color:#8a5a2b;margin-bottom:6px;" id="kwScanScope"></div>
        <div style="margin-bottom:6px;font-size:12px;">
            <label style="margin-right:14px;"><input type="radio" name="kwScope" value="filtered" checked> 掃描<b>目前篩選的全部報價單</b>（<span id="kwScopeAllCnt">0</span> 張）</label>
            <label style="margin-right:14px;"><input type="radio" name="kwScope" value="page"> 只掃描<b>目前這一頁</b>（<span id="kwScopePageCnt">0</span> 張）</label>
            <button class="btn btn-default btn-xs" onclick="runKwScan()"><i class="fa fa-refresh"></i> 重新偵測</button>
            <label style="margin-left:12px;"><input type="checkbox" id="kwIncludeSet"> 連<b>已經設定過製程</b>的項目也一起偵測（套用會覆蓋原設定）</label>
        </div>
        <div style="font-size:12px;margin-bottom:8px;" id="kwScanSummary"></div>
        <div style="margin-bottom:6px;font-size:12px;">
            <button class="btn btn-default btn-xs" onclick="kwSelectAll(true)">全部勾選</button>
            <button class="btn btn-default btn-xs" onclick="kwSelectAll(false)">全部取消</button>
            <span style="margin-left:10px;color:#8a5a2b;">已勾選 <b id="kwSelCount">0</b> 筆</span>
            <span style="margin-left:10px;color:#888;">點分組列可展開逐筆核對／取消個別項目</span>
        </div>
        <div id="kwGroups"></div>
    </div>
    <div class="m-foot">
        <button class="btn btn-default" onclick="closeMask('kwScanMask')">關閉</button>
        <button class="btn btn-warning" id="kwApplyBtn" onclick="submitKwApply()"><i class="fa fa-check"></i> 套用已勾選的項目</button>
    </div>
</div></div>

<!-- 關鍵字規則設定 -->
<div class="va-mask" id="kwRuleMask"><div class="va-modal wide">
    <div class="m-head"><span><i class="fa fa-cog"></i> 關鍵字規則設定</span><span class="m-close" onclick="closeMask('kwRuleMask')">✕</span></div>
    <div class="m-body">
        <div style="font-size:12px;color:#8a5a2b;margin-bottom:8px;">
            規則只比對項目的<b>規格</b>文字，不分大小寫。<br>
            <b>「包含」用逗號連接＝這些字<u>全部都要有</u></b>（例 <code>齒研,冶具|治具</code> ＝ 要同時含「齒研」而且含「冶具」或「治具」）；
            想表達「這個<u>或</u>那個」請在<b>同一個關鍵字裡用 <code>|</code></b>（例 <code>冶具|治具</code>）。<br>
            <b>「不包含」用逗號連接＝其中<u>任何一個</u>出現就不算命中</b>（例 <code>冶具,治具,刀</code>）。<br>
            客戶不勾＝通用規則；勾了多個＝<u>任一</u>符合即成立。多條規則同時命中時，建議的標籤取<b>聯集</b>。
        </div>
        <table style="width:100%;border-collapse:collapse;font-size:12px;">
            <thead><tr style="color:#8a5a2b;border-bottom:1px solid #E4C293;">
                <th style="text-align:left;padding:4px 6px;">規則名稱</th><th style="text-align:left;">包含</th>
                <th style="text-align:left;">不包含</th><th style="text-align:left;">客戶</th>
                <th style="text-align:left;">帶入</th><th style="text-align:left;width:110px;">操作</th>
            </tr></thead>
            <tbody id="kwRuleRows"></tbody>
        </table>
        <hr style="margin:10px 0;">
        <div id="kwRuleForm">
            <input type="hidden" id="krRuleId" value="0">
            <div style="display:flex;gap:10px;flex-wrap:wrap;">
                <div style="flex:1;min-width:180px;"><label style="font-size:12px;">規則名稱 *</label>
                    <input type="text" class="form-control input-sm" id="krName" maxlength="60" placeholder="例：齒研">
                    <div class="kw-err" id="krNameErr"></div></div>
                <div style="flex:1;min-width:230px;"><label style="font-size:12px;">規格「包含」 *</label>
                    <input type="text" class="form-control input-sm" id="krInc" placeholder="例：整修,修整,修改">
                    <div style="font-size:11px;margin-top:2px;">
                        <label style="margin-right:10px;"><input type="radio" name="krIncMode" value="any" checked> <b>任一即可</b>（逗號＝或）</label>
                        <label><input type="radio" name="krIncMode" value="all"> <b>全部都要含</b>（逗號＝且）</label>
                    </div>
                    <div class="kw-err" id="krIncErr"></div></div>
                <div style="flex:1;min-width:180px;"><label style="font-size:12px;">規格「不包含」 <span style="color:#888;font-weight:400;">逗號＝任一中就排除</span></label>
                    <input type="text" class="form-control input-sm" id="krExc" placeholder="冶具,治具,刀　＝ 含其中任一個就不算"></div>
                <div style="width:90px;"><label style="font-size:12px;">排序</label>
                    <input type="number" class="form-control input-sm" id="krPriority" value="0"></div>
            </div>
            <div id="krPreview" style="font-size:12px;margin-top:6px;color:#8a5a2b;min-height:20px;"></div>
            <div style="display:flex;gap:10px;flex-wrap:wrap;margin-top:8px;">
                <div style="flex:1;min-width:260px;">
                    <label style="font-size:12px;">命中時帶入的製程標籤 <span style="color:#888;">（不選＝改勾下方「帶入備註」）</span></label>
                    <div id="krTagArea"></div>
                    <div style="margin-top:4px;"><label style="font-size:12px;"><input type="checkbox" id="krToNote"> 帶入備註（把規格文字帶進整張報價單的備註欄，不設製程標籤）</label></div>
                    <div class="kw-err" id="krTagErr"></div>
                </div>
                <div style="flex:1;min-width:260px;">
                    <label style="font-size:12px;">只適用這些客戶（可多選，任一符合即可；不勾＝通用規則）</label>
                    <input type="text" class="form-control input-sm" id="krCustFilter" placeholder="輸入客戶編號或名稱篩選…" style="margin-bottom:4px;">
                    <div class="kw-cust-box" id="krCustBox"></div>
                </div>
            </div>
        </div>
    </div>
    <div class="m-foot">
        <button class="btn btn-default" onclick="closeMask('kwRuleMask')">關閉</button>
        <button class="btn btn-default" onclick="krSeed()" title="一次建立一組常見的製程關鍵字規則（同名的不會重複建立），建立後可自行增修或刪除">
            <i class="fa fa-magic"></i> 載入建議規則範本</button>
        <button class="btn btn-default" onclick="krResetForm()">清空表單</button>
        <button class="btn btn-warning" id="krSaveBtn" onclick="krSave()"><i class="fa fa-save"></i> 儲存規則</button>
    </div>
</div></div>

<!-- 切換客戶 -->
<div class="va-mask" id="custSwitchMask"><div class="va-modal">
    <div class="m-head"><span><i class="fa fa-exchange"></i> 切換客戶</span><span class="m-close" onclick="closeMask('custSwitchMask')">✕</span></div>
    <div class="m-body">
        <div style="font-size:12px;color:#888;margin-bottom:8px;">報價單：<b id="custSwitchQuoteNo"></b>　目前客戶：<span id="custSwitchCurrent"></span></div>
        <div class="qt-search-box">
            <input type="text" id="custSwitchKw" class="form-control" placeholder="輸入客戶名稱或代碼搜尋…" autocomplete="off">
            <div class="qt-search-results" id="custSwitchResults"></div>
        </div>
        <div class="qt-quickform" id="custNewForm" style="display:none;">
            <input type="text" class="form-control input-sm" id="custNewId" placeholder="客戶代碼（新建用）">
            <input type="text" class="form-control input-sm" id="custNewName" placeholder="客戶名稱">
            <button class="btn btn-success btn-xs" onclick="submitNewCustomer()"><i class="fa fa-save"></i> 建立並套用</button>
            <div id="custNewErr" style="color:#c0392b;font-size:11px;margin-top:3px;"></div>
        </div>
    </div>
    <div class="m-foot"><button class="btn btn-default" onclick="closeMask('custSwitchMask')">關閉</button></div>
</div></div>

<!-- 快速綁定料號ID（比照 NewOrder_Track.php 快速綁定：自動判斷客戶與料號，Enter 即確認綁定） -->
<div class="va-mask" id="quickBindPartMask"><div class="va-modal">
    <div class="m-head"><span><i class="fa fa-link"></i> 快速綁定料號ID</span><span class="m-close" onclick="closeMask('quickBindPartMask')">✕</span></div>
    <div class="m-body">
        <div style="font-size:12px;color:#888;margin-bottom:8px;">料號原文：<b id="qbpOrigText"></b>　所屬客戶：<span id="qbpClientName"></span></div>
        <div id="qbpLoading" style="text-align:center;padding:15px;"><i class="fa fa-spinner fa-spin"></i></div>
        <div id="qbpResultArea" style="display:none;"></div>
    </div>
    <div class="m-foot">
        <button class="btn btn-default" onclick="closeMask('quickBindPartMask')">取消</button>
        <button class="btn btn-primary" id="qbpSaveBtn" style="display:none;" onclick="saveQuickBindPart()"><i class="fa fa-save"></i> 確認綁定</button>
    </div>
</div></div>

<!-- 綁定後偵測同料號+同客戶的其他未綁定項目，詢問是否一併綁定 -->
<div class="va-mask" id="batchBindMask"><div class="va-modal wide">
    <div class="m-head"><span><i class="fa fa-clone"></i> 發現相同料號的其他項目</span><span class="m-close" onclick="closeMask('batchBindMask')">✕</span></div>
    <div class="m-body">
        <p style="font-size:12px;color:#5b3a1e;">找到 <b id="bbCount"></b> 筆同料號「<b id="bbProductText"></b>」、同客戶、尚未綁定的項目，是否一併綁定為「<b id="bbTargetPart"></b>」？</p>
        <table class="qt-item-table" style="width:100%;">
            <thead><tr><th style="width:30px;"><input type="checkbox" id="bbCheckAll" checked></th><th>報價單號</th><th>規格</th><th>數量</th><th>單價</th></tr></thead>
            <tbody id="bbList"></tbody>
        </table>
    </div>
    <div class="m-foot">
        <button class="btn btn-default" onclick="closeMask('batchBindMask')">不用了</button>
        <button class="btn btn-primary" onclick="confirmBatchBind()"><i class="fa fa-check"></i> 一併綁定已勾選項目</button>
    </div>
</div></div>

<script src="../../resource/js/jquery.min.js"></script>
<script src="../../resource/js/bootstrap.min.js"></script>
<script src="../../resource/js/fastclick.js"></script>
<script src="../../resource/js/nprogress.js"></script>
<script src="../../resource/js/custom.min.js"></script>
<script src="../../resource/js/eg_input_rules.js?v=<?= @filemtime(__DIR__.'/../../resource/js/eg_input_rules.js') ?>"></script>
<script src="../../resource/js/eg_date_fmt.js?v=<?= @filemtime(__DIR__.'/../../resource/js/eg_date_fmt.js') ?>"></script>
<script>
$(document).ready(function(){ $('#sidebar-menu').css('visibility','visible'); });
$(window).on('scroll', function(){ $('#qtScrollTop').toggle($(window).scrollTop() > 200); });
function closeMask(id){ document.getElementById(id).style.display='none'; }
function openMask(id){ document.getElementById(id).style.display='block'; }
$('#btnPageHelp').on('click', function(){ openMask('helpUseMask'); });

const API_URL = '../../src/store/Quotation_API.php';
const CAN_EDIT = <?= $canEdit ? 'true' : 'false' ?>;
let qtData = [];
let processTagTree = [];
let qtPage = 1;
const QT_PAGE_SIZE = 10;
let qtPageRows = [];        // 目前這一頁畫出來的報價單（批次設定製程的「只套用到目前這一頁」用）
let qtItemsCache = {};      // quote_id => items[]
let qtProcState  = {};      // item_id => { activeGid, selected:[sub_tag_id,...] }
let custSwitchQuoteId = null;

function loadProcessTagTree(cb) {
    $.get(API_URL, { action: 'get_process_tag_tree' }, function(res) {
        if (res.success) processTagTree = res.tree || [];
        if (cb) cb();
    });
}

function loadPendingList(done) {
    // 清單重抓＝畫面上所有明細一律視為過期：批次綁定／批次設定製程之後，
    // 標頭徽章是由重抓的統計算出來的、項目列卻是吃 qtItemsCache，不清就會出現
    // 「標頭說料號ID已綁定、底下的列還是未綁定」這種兩邊對不起來的畫面
    qtItemsCache = {};
    qtProcState  = {};
    $('#qtCards').html('<div style="text-align:center;color:#999;padding:20px;"><i class="fa fa-spinner fa-spin"></i> 載入中…</div>');
    $.get(API_URL, { action: 'get_pending_transfer_list' }, function(res) {
        if (!res.success) { $('#qtCards').html('載入失敗：' + (res.message||'')); return; }
        qtData = res.data;
        qtPage = 1;
        populateYearFilter();
        populateCustFilter();
        renderStats();
        renderCards();
        if (typeof done === 'function') done();
    });
}
$('#qtYearFilter').on('change', function(){ qtPage = 1; renderCards(); });
$('#qtCustFilter').on('change', function(){ qtPage = 1; populateYearFilter(); renderCards(); });
$('#qtOnlyUnbound').on('change', function(){ qtPage = 1; renderCards(); });

function renderStats() {
    const total = qtData.length;
    const ready = qtData.filter(r => Number(r.items_no_dsetting) === 0 && Number(r.items_no_process) === 0).length;
    const latest = latestOpDate(qtData);
    $('#qtStats').html(
        '<div class="qt-stat-chip">尚待確認 <b>' + total + '</b> 張</div>' +
        '<div class="qt-stat-chip">已補齊(料號ID+製程) <b>' + ready + '</b> 張</div>' +
        (latest
            ? '<div class="qt-stat-chip" title="由 OP 單號本身解析出來的日期（OP＋民國年3碼＋月日4碼），不是資料表上的報價日期欄位">' +
                  '最新資料日期 <b>' + fmtDate(latest.date) + '</b> <small style="color:#a1834f;">(' + latest.quote_no + ')</small>' +
              '</div>'
            : '<div class="qt-stat-chip" title="OP 單號格式為 OP＋民國年3碼＋月日4碼">最新資料日期 <b>—</b> <small style="color:#a1834f;">(單號無法解析日期)</small></div>')
    );
}

// 由 OP 單號解析日期：OP + 民國年3碼 + MMDD + 流水號3碼（例：OP1071228004 → 2018-12-28）
function opDateOf(quoteNo) {
    const m = String(quoteNo || '').match(/^OP(\d{3})(\d{2})(\d{2})/);
    if (!m) return '';
    const y = parseInt(m[1], 10) + 1911, mo = parseInt(m[2], 10), d = parseInt(m[3], 10);
    if (mo < 1 || mo > 12 || d < 1 || d > 31) return '';
    const dt = new Date(y, mo - 1, d);
    if (dt.getFullYear() !== y || dt.getMonth() !== mo - 1 || dt.getDate() !== d) return '';   // 擋 02/31 這種不存在的日期
    return m[1] === '000' ? '' : (y + '-' + m[2] + '-' + m[3]);
}

// 取整份清單中「由 OP 單號解析出來」最新的一天（解析不到的單號略過）
function latestOpDate(rows) {
    let best = null;
    (rows || []).forEach(function(r) {
        const d = opDateOf(r.quote_no);
        if (!d) return;
        if (!best || d > best.date || (d === best.date && String(r.quote_no) > String(best.quote_no))) {
            best = { date: d, quote_no: r.quote_no };
        }
    });
    return best;
}

function fmtDate(d) { return egFmtDate(d); }
function yearOf(d) { return d ? String(d).substring(0, 4) : ''; }

let qtYearInit = false;   // 只有第一次載入才自動選最新年份，之後一律尊重使用者當下選的
function populateYearFilter() {
    // 只算目前選定客戶的資料：客戶篩掉之後沒有單的年份一律不列出來
    const cust = $('#qtCustFilter').val();
    const cnt = {};
    qtData.forEach(function(r) {
        if (cust && String(r.client_id || '') !== cust) return;
        const y = yearOf(r.quote_date);
        if (y) cnt[y] = (cnt[y] || 0) + 1;
    });
    const years = Object.keys(cnt).sort().reverse();
    const $sel = $('#qtYearFilter');
    const cur = $sel.val();
    const totalN = Object.keys(cnt).reduce(function(a, y){ return a + cnt[y]; }, 0);
    $sel.html('<option value="">全部（' + totalN + '）</option>' +
              years.map(y => '<option value="' + y + '">' + y + '（' + cnt[y] + '）</option>').join(''));
    if (!qtYearInit) {
        qtYearInit = true;
        if (years.length) $sel.val(years[0]);              // 預設＝最新年份
    } else if (years.indexOf(cur) !== -1) {
        $sel.val(cur);                                     // 使用者選的年份還在，維持不動
    } else if (cur !== '' && years.length) {
        $sel.val(years[0]);                                // 原本選的年份已經沒有待確認資料了，退回最新年份
    }
}

function getFilteredData() {
    const y = $('#qtYearFilter').val();
    const c = $('#qtCustFilter').val();
    const onlyUnbound = $('#qtOnlyUnbound').is(':checked');
    return qtData.filter(function(r) {
        if (y && yearOf(r.quote_date) !== y) return false;
        if (c && String(r.client_id || '') !== c) return false;
        if (onlyUnbound && Number(r.items_no_dsetting) === 0) return false;
        return true;
    });
}

// 客戶下拉：只列出目前尚待確認的報價單實際出現過的客戶（附張數），
// 打字篩選走共用檔 eg_input_rules.js 的 data-eg-filter（比對選項文字＝名稱＋編號，兩種都找得到）
function populateCustFilter() {
    const map = {};
    qtData.forEach(function(r) {
        const id = String(r.client_id || '');
        if (!id) return;
        if (!map[id]) map[id] = { id: id, name: r.client_name || '', n: 0 };
        map[id].n++;
    });
    const list = Object.keys(map).map(function(k){ return map[k]; })
        .sort(function(a, b){ return b.n - a.n || a.id.localeCompare(b.id); });
    const $sel = $('#qtCustFilter');
    const cur = $sel.val();
    $sel.html('<option value="">全部客戶</option>' + list.map(function(c) {
        return '<option value="' + c.id + '">' + c.name + '（' + c.id + '）— ' + c.n + ' 張</option>';
    }).join(''));
    if (cur && list.some(function(c){ return c.id === cur; })) $sel.val(cur);
}

// 「這一筆項目有沒有設定製程」的唯一判定（後端 get_pending_transfer_list／quick_confirm_transfer 同規則）。
// 一定要連 process_notes（實際點選的子標籤 id 清單）一起看：有 31 個啟用中的子標籤
// （磨銳／鍍TIN／熱處理／刀具類…）本來就沒有綁定 process_no，只看 processes 會變成
// 「明明點了標籤，系統卻說還沒設定製程」，而且那張報價單永遠轉不進正式報價單。
function itemHasProcess(it) {
    if (Number(it.note_only) === 1) return true;          // 以備註代替製程（規格文字已帶進報價單備註欄）
    if (it.processes && String(it.processes) !== '') return true;
    return String(it.process_notes || '').trim() !== '';
}

// 料號ID與製程都補齊的報價單才可以轉入正式報價單
//（後端 quick_confirm_transfer 會以同一規則再擋一次＝鐵律8）
function transferGate(noDs, cnt, noPc) {
    noDs = Number(noDs) || 0; noPc = Number(noPc) || 0; cnt = Number(cnt) || 0;
    const miss = [];
    if (noDs > 0) miss.push('料號ID尚未完全綁定（缺 ' + noDs + '/' + cnt + ' 筆）');
    if (noPc > 0) miss.push('製程尚未完全設定（缺 ' + noPc + '/' + cnt + ' 筆）');
    if (miss.length) return { ok:false, reason:miss.join('、') + '，補齊後才能轉入正式報價單' };
    return { ok:true, reason:'' };
}

// 綁定料號後即時解鎖／再度鎖上該張卡片的轉入入口，不必重新整理整頁
function applyTransferGate(qid, noDs, cnt, noPc) {
    const gate = transferGate(noDs, cnt, noPc);
    const $chk = $('#qtChk' + qid), $btn = $('#qtBtnTx' + qid);
    if (!gate.ok) $chk.prop('checked', false);
    $chk.prop('disabled', !gate.ok).attr('title', gate.ok ? null : gate.reason);
    $btn.prop('disabled', !gate.ok).attr('title', gate.ok ? null : gate.reason);
    $('#qtBtnAuto' + qid).toggle((Number(noDs) || 0) > 0);   // 這顆只管料號，與製程完成度無關
    updateSelCount();
}

function renderCards() {
    const filtered = getFilteredData();
    if (!qtData.length) {
        $('#qtCards').html('<div style="text-align:center;color:#999;padding:20px;">目前沒有尚待確認的報價單</div>');
        $('#qtPagination').html('');
        return;
    }
    if (!filtered.length) {
        $('#qtCards').html('<div style="text-align:center;color:#999;padding:20px;">此年份沒有尚待確認的報價單</div>');
        $('#qtPagination').html('');
        return;
    }
    const totalPages = Math.max(1, Math.ceil(filtered.length / QT_PAGE_SIZE));
    if (qtPage > totalPages) qtPage = totalPages;
    const start = (qtPage - 1) * QT_PAGE_SIZE;
    const pageRows = filtered.slice(start, start + QT_PAGE_SIZE);
    qtPageRows = pageRows;   // 批次設定製程的「只套用到目前這一頁」直接沿用畫面上這一批，不另外算一次

    let html = '';
    pageRows.forEach(function(r) {
        const noDs = Number(r.items_no_dsetting), noPc = Number(r.items_no_process), cnt = Number(r.item_count);
        let badge = '';
        badge += (noDs === 0) ? '<span class="qt-badge ok">料號ID已綁定</span>' : '<span class="qt-badge warn">料號ID缺 ' + noDs + '/' + cnt + '</span>';
        badge += (noPc === 0) ? '<span class="qt-badge ok">製程已設定</span>' : '<span class="qt-badge warn">製程缺 ' + noPc + '/' + cnt + '</span>';
        const gate = transferGate(noDs, cnt, noPc);
        html += '<div class="qt-card" data-qid="' + r.quote_id + '">' +
            '<div class="qt-card-head">' +
                '<input type="checkbox" class="qt-row-chk" id="qtChk' + r.quote_id + '" value="' + r.quote_id + '"' +
                    (gate.ok ? '' : ' disabled title="' + gate.reason + '"') + '>' +
                '<span class="qno">' + r.quote_no + '</span>' +
                '<span>' + fmtDate(r.quote_date) + '</span>' +
                '<span>客戶：' + (r.client_name || '<em style="color:#c0392b">未設定</em>') + (r.client_id ? ' <small style="color:#aaa">(' + r.client_id + ')</small>' : '') +
                    (CAN_EDIT ? ' <a href="javascript:void(0)" onclick="openCustSwitch(' + r.quote_id + ',\'' + r.quote_no + '\',\'' + (r.client_name||'').replace(/'/g,"") + '\')">切換</a>' : '') +
                '</span>' +
                '<span>項目數：' + cnt + '</span>' +
                '<span class="qt-badge-cell">' + badge + '</span>' +
                '<span style="margin-left:auto;display:flex;gap:6px;align-items:center;">' +
                    (CAN_EDIT ? '<button class="btn btn-success btn-xs" id="qtBtnAuto' + r.quote_id + '"' +
                            (noDs > 0 ? '' : ' style="display:none;"') +
                            ' title="把這張單所有未綁定的項目，依料號文字找既有料號；找不到就以本單客戶新建一筆再綁上"' +
                            ' onclick="autoBindQuote(' + r.quote_id + ',\'' + r.quote_no + '\')"><i class="fa fa-magic"></i> 一鍵建立並綁定料號</button>' : '') +
                    (CAN_EDIT ? '<button class="btn btn-warning btn-xs" id="qtBtnTx' + r.quote_id + '"' +
                            (gate.ok ? '' : ' disabled title="' + gate.reason + '"') +
                            ' onclick="confirmTransferOne(' + r.quote_id + ',\'' + r.quote_no + '\')"><i class="fa fa-check"></i> 轉正式報價單</button>' : '') +
                '</span>' +
            '</div>' +
            '<div class="qt-card-body" id="qtCardBody' + r.quote_id + '">載入項目中…</div>' +
            '</div>';
    });
    $('#qtCards').html(html);

    let pg = '';
    pg += '<button class="btn btn-default btn-xs" ' + (qtPage<=1?'disabled':'') + ' onclick="qtGoPage(1)" title="第一頁"><i class="fa fa-angle-double-left"></i> 第一頁</button> ';
    pg += '<button class="btn btn-default btn-xs" ' + (qtPage<=1?'disabled':'') + ' onclick="qtGoPage(' + (qtPage-1) + ')">上一頁</button>';
    pg += ' 第 ' + qtPage + ' / ' + totalPages + ' 頁（共 ' + filtered.length + ' 張） ';
    pg += '<button class="btn btn-default btn-xs" ' + (qtPage>=totalPages?'disabled':'') + ' onclick="qtGoPage(' + (qtPage+1) + ')">下一頁</button> ';
    pg += '<button class="btn btn-default btn-xs" ' + (qtPage>=totalPages?'disabled':'') + ' onclick="qtGoPage(' + totalPages + ')" title="最後一頁">最後一頁 <i class="fa fa-angle-double-right"></i></button>';
    $('#qtPagination').html(pg);

    pageRows.forEach(function(r) { renderItemBody(r.quote_id); });
    // 重畫清單＝勾選狀態一律歸零：轉入正式後那幾張已從清單移除，
    // 「全選本頁」若還留著勾，會讓人以為目前仍有選取的報價單
    $('#qtCheckAll').prop('checked', false);
    updateSelCount();
    updateReadyCount();
}

function qtGoPage(p) { qtPage = p; renderCards(); }

// 整張報價單一鍵建立並綁定料號：後端逐筆先找既有料號（本單客戶→無客戶），都找不到才以本單客戶新建，
// 與跳窗裡「找不到？新增此料號（綁此客戶）」同一條規則，只是一次做完整張單。
function autoBindQuote(quoteId, quoteNo) {
    const row = qtData.find(function(r){ return String(r.quote_id) === String(quoteId); });
    const noDs = row ? Number(row.items_no_dsetting) : 0;
    if (!noDs) { qtNotify('報價單 ' + quoteNo + ' 的料號ID已經全部綁定完成。', 'warn'); return; }
    if (!row.client_id) { qtNotify('報價單 ' + quoteNo + ' 還沒有設定客戶，無法自動建立料號（新建的料號要綁到客戶）。\n請先按客戶欄旁的「切換」設定客戶後再試。', 'warn'); return; }
    const $b = $('#qtBtnAuto' + quoteId).prop('disabled', true);
    $.post(API_URL, { action: 'quick_autobind_quote', quote_id: quoteId }, function(res) {
        $b.prop('disabled', false);
        if (!res.success) { qtNotify('自動建立並綁定失敗：' + res.message, 'err'); return; }
        let msg = '報價單 ' + quoteNo + ' 已綁定 ' + res.bound + ' 筆';
        if (res.created) msg += '\n新建料號 ' + res.created + ' 筆：' + res.created_nos.join('、');
        if (res.reused)  msg += '\n沿用既有料號 ' + res.reused + ' 筆：' + res.reused_nos.join('、');
        if (res.skipped) msg += '\n略過 ' + res.skipped + ' 筆（料號欄是空的，無法建立）';
        if (res.ambiguous) msg += '\n\n以下 ' + res.ambiguous + ' 個料號在主檔裡有多筆完全同名的登錄，系統無法判斷該用哪一筆，\n已保持未綁定，請用該列的「快速綁定」自行挑選：\n' + res.ambiguous_nos.join('、');
        qtNotify(msg, 'ok');
        reloadQuoteItems(quoteId);
    });
}

// 重新抓這張報價單的明細並更新統計/閘門（不整頁重載，維持捲動位置與目前頁碼）
function reloadQuoteItems(qid) {
    delete qtItemsCache[qid];
    $('#qtCardBody' + qid).html('載入項目中…');
    $.get(API_URL, { action: 'get_detail', quote_id: qid }, function(res) {
        if (!res.success) { $('#qtCardBody' + qid).html('載入失敗：' + (res.message||'')); return; }
        qtItemsCache[qid] = res.data.items || [];
        drawItems(qid, qtItemsCache[qid]);
        if (qtItemsCache[qid].length) refreshStatsOnly(qtItemsCache[qid][0].item_id);
    });
}

function renderItemBody(qid) {
    const $body = $('#qtCardBody' + qid);
    if (!$body.length) return;
    if (qtItemsCache[qid]) { drawItems(qid, qtItemsCache[qid]); return; }
    $.get(API_URL, { action: 'get_detail', quote_id: qid }, function(res) {
        if (!res.success) { $body.html('載入失敗：' + (res.message||'')); return; }
        qtItemsCache[qid] = res.data.items || [];
        drawItems(qid, qtItemsCache[qid]);
    });
}

// 從已存的 process_no 清單推算目前選取的子標籤（跟主編輯頁 inferSubTagsFromProcessIds 邏輯一致）
function inferSubTagsFromProcessIds(processIds) {
    const result = [];
    processTagTree.forEach(g => {
        (g.sub_tags || []).forEach(st => {
            const pnos = (st.process_nos || []).map(String);
            if (pnos.length > 0 && pnos.every(p => processIds.includes(p))) result.push(st.sub_tag_id);
        });
    });
    return result;
}

function findQuoteIdByItemId(itemId) {
    let qid = null;
    Object.keys(qtItemsCache).forEach(function(k) {
        if (qtItemsCache[k].some(function(it){ return String(it.item_id) === String(itemId); })) qid = k;
    });
    return qid;
}

function drawItems(qid, allItems) {
    const row = qtData.find(function(r){ return String(r.quote_id) === String(qid); });
    // 勾了「只看未綁定料號ID的項目」時，卡片裡也只列還沒綁的那幾列，
    // 直接對著清單一列一列按「快速綁定」，不用在整張單裡找
    const onlyUnbound = $('#qtOnlyUnbound').is(':checked');
    const items = onlyUnbound ? allItems.filter(function(it){ return !it.d_setting_d_id; }) : allItems;
    if (!items.length) { $('#qtCardBody' + qid).html('<div style="color:#999;font-size:12px;">（此篩選條件下沒有要顯示的項目）</div>'); return; }
    let html = '<table class="qt-item-table"><thead><tr><th>料號</th><th>規格</th><th>數量</th><th>單價</th><th style="width:170px;">料號ID綁定</th><th style="min-width:260px;">製程</th></tr></thead><tbody>';
    items.forEach(function(it, idx) {
        const boundText = it.d_setting_d_id ? ('<span class="qt-badge ok">已綁定 #' + it.d_setting_d_id + '</span>') : '<span class="qt-badge warn">未綁定</span>';
        const procIds = (it.processes || '').split(',').filter(function(v){return v!=='';});
        if (!qtProcState[it.item_id]) {
            // process_notes 就是當初實際點選的子標籤 id 清單，有存就以它為準；
            // 沒有（舊資料）才退回由 process_no 反推——但那是多對多、推不準的，
            // 會把所有含該 process_no 的子標籤全部點亮，只能當沒有更好資訊時的猜測。
            const savedSubIds = String(it.process_notes || '').split(',')
                .map(function(v){ return parseInt(v, 10); })
                .filter(function(v){ return !isNaN(v) && v > 0; });
            const selected = savedSubIds.length ? savedSubIds : inferSubTagsFromProcessIds(procIds);
            let activeGid = processTagTree.length ? processTagTree[0].group_id : null;
            for (const g of processTagTree) {
                if ((g.sub_tags || []).some(st => selected.includes(st.sub_tag_id))) { activeGid = g.group_id; break; }
            }
            qtProcState[it.item_id] = { activeGid: activeGid, selected: selected };
        }
        const prevItemId = idx > 0 ? items[idx - 1].item_id : null;
        html += '<tr data-item="' + it.item_id + '">' +
            '<td>' + it.product_id + '</td>' +
            '<td>' + (it.specification || '') + '</td>' +
            '<td>' + it.quantity + '</td>' +
            '<td>' + it.unit_price + '</td>' +
            '<td>' + boundText + (CAN_EDIT ? renderPartBindWidget(it, row) : '') + '</td>' +
            '<td>' + (CAN_EDIT ? renderProcWidget(it.item_id, prevItemId, items.length) : '') + '</td>' +
            '</tr>';
    });
    html += '</tbody></table>';
    $('#qtCardBody' + qid).html(html);
}

function renderPartBindWidget(it, row) {
    const cid = row ? (row.client_id || '') : '';
    const cname = row ? (row.client_name || '') : '';
    return ' <button type="button" class="btn btn-default btn-xs" style="margin-top:3px;" ' +
        'onclick="openQuickBindPart(' + it.item_id + ',\'' + String(it.product_id).replace(/'/g,"") + '\',\'' + cid + '\',\'' + cname.replace(/'/g,"") + '\')">' +
        '<i class="fa fa-link"></i> ' + (it.d_setting_d_id ? '重新綁定' : '快速綁定') + '</button>';
}

function findItemById(itemId) {
    let found = null;
    Object.keys(qtItemsCache).forEach(function(k) {
        (qtItemsCache[k] || []).forEach(function(it) { if (String(it.item_id) === String(itemId)) found = it; });
    });
    return found;
}

function renderProcWidget(itemId, prevItemId, totalInQuote) {
    const state = qtProcState[itemId];
    const item  = findItemById(itemId);

    // 以備註代替製程：整格直接顯示為備註（規格文字已帶進整張報價單的備註欄），不再顯示標籤選擇器
    if (item && Number(item.note_only) === 1) {
        return '<div class="qt-note-only">' +
               '<span class="qt-badge note">備註</span> ' + escapeQt(item.specification || '') +
               '<div style="font-size:10px;margin-top:2px;">已帶入本張報價單的備註欄　' +
               '<a href="javascript:void(0)" onclick="setItemNoteOnly(' + itemId + ',false)">取消，改設定製程</a></div></div>';
    }

    let toolbar = '';
    if (prevItemId || (totalInQuote && totalInQuote > 1)) {
        toolbar = '<div style="font-size:10px;margin-bottom:2px;">';
        if (prevItemId) toolbar += '<a href="javascript:void(0)" onclick="copyProcessFromItem(' + itemId + ',' + prevItemId + ')"><i class="fa fa-clone"></i> 複製上一筆</a>';
        if (totalInQuote && totalInQuote > 1) toolbar += (prevItemId ? '　' : '') + '<a href="javascript:void(0)" onclick="applyProcessToAllInQuote(' + itemId + ')"><i class="fa fa-copy"></i> 套用到本單全部</a>';
        toolbar += '</div>';
    }
    // 沒有對應製程標籤的項目（例如「半月型六角口模 線割對半」）走這顆：規格文字帶進報價單備註欄，
    // 該列改以備註表示，也算補齊了製程、可以轉入正式報價單
    toolbar += '<div style="font-size:10px;margin-bottom:2px;"><a href="javascript:void(0)" ' +
               'title="把這一筆的規格文字帶進整張報價單的備註欄，本列改以備註表示" ' +
               'onclick="setItemNoteOnly(' + itemId + ',true)"><i class="fa fa-comment-o"></i> 帶入備註</a></div>';

    let l1 = '<div class="qt-proc-l1">';
    processTagTree.forEach(function(g) {
        l1 += '<button type="button" class="' + (g.group_id === state.activeGid ? 'active' : '') + '" onclick="procSetActiveGroup(' + itemId + ',' + g.group_id + ')">' + g.group_name + '</button>';
    });
    l1 += '</div>';

    let l2 = '<div class="qt-proc-l2">';
    const g = processTagTree.find(function(x){ return x.group_id === state.activeGid; });
    if (g) {
        (g.sub_tags || []).forEach(function(st) {
            const on = state.selected.indexOf(st.sub_tag_id) !== -1;
            l2 += '<button type="button" class="' + (on?'active':'') + '" onclick="procToggleSubTag(' + itemId + ',' + st.sub_tag_id + ')">' + st.sub_tag_name + '</button>';
        });
    }
    l2 += '</div>';

    let chips = '';
    if (state.selected.length) {
        chips = '<div class="qt-proc-chips">';
        state.selected.forEach(function(sid) {
            let name = String(sid);
            processTagTree.forEach(function(g2){ (g2.sub_tags||[]).forEach(function(st2){ if (st2.sub_tag_id === sid) name = st2.sub_tag_name; }); });
            chips += '<span class="qt-proc-chip">' + name + '<span class="x" onclick="procRemoveSubTag(' + itemId + ',' + sid + ')">&times;</span></span>';
        });
        chips += '</div>';
    }
    return toolbar + l1 + l2 + chips;
}

function copyProcessFromItem(itemId, sourceItemId) {
    const src = qtProcState[sourceItemId];
    if (!src) return;
    qtProcState[itemId] = { activeGid: src.activeGid, selected: src.selected.slice() };
    saveItemProcess(itemId);
    redrawProcCell(itemId);
}

function applyProcessToAllInQuote(itemId) {
    const qid = findQuoteIdByItemId(itemId);
    if (!qid) return;
    const items = qtItemsCache[qid];
    const others = items.filter(function(it){ return String(it.item_id) !== String(itemId); });
    if (!others.length) return;
    if (!confirm('確定要把這筆的製程套用到本張報價單其餘 ' + others.length + ' 筆項目嗎？會覆蓋這些項目原有的製程設定。')) return;
    const src = qtProcState[itemId];
    others.forEach(function(it) {
        qtProcState[it.item_id] = { activeGid: src.activeGid, selected: src.selected.slice() };
    });
    drawItems(qid, items);
    others.forEach(function(it) { saveItemProcess(it.item_id); });
}

function procSetActiveGroup(itemId, gid) {
    qtProcState[itemId].activeGid = gid;
    redrawProcCell(itemId);
}

function procToggleSubTag(itemId, subTagId) {
    const state = qtProcState[itemId];
    const idx = state.selected.indexOf(subTagId);
    if (idx === -1) state.selected.push(subTagId); else state.selected.splice(idx, 1);
    saveItemProcess(itemId);
    redrawProcCell(itemId);
}

function procRemoveSubTag(itemId, subTagId) {
    const state = qtProcState[itemId];
    state.selected = state.selected.filter(function(x){ return x !== subTagId; });
    saveItemProcess(itemId);
    redrawProcCell(itemId);
}

function redrawProcCell(itemId) {
    const qid = findQuoteIdByItemId(itemId);
    const items = qid ? qtItemsCache[qid] : [];
    const idx = items.findIndex(function(it){ return String(it.item_id) === String(itemId); });
    const prevItemId = idx > 0 ? items[idx - 1].item_id : null;
    $('tr[data-item="' + itemId + '"] td:last-child').html(renderProcWidget(itemId, prevItemId, items.length));
}

function saveItemProcess(itemId) {
    const state = qtProcState[itemId];
    const procIds = new Set();
    state.selected.forEach(function(sid) {
        processTagTree.forEach(function(g){ (g.sub_tags||[]).forEach(function(st){ if (st.sub_tag_id === sid) (st.process_nos||[]).forEach(function(p){ procIds.add(p); }); }); });
    });
    let groupType = 'single_process';
    if (state.selected.length) {
        const g = processTagTree.find(function(x){ return x.group_id === state.activeGid; });
        if (g) groupType = g.group_type || 'single_process';
    }
    // sub_tag_ids 一定要送：報價單管理頁是靠 process_notes（子標籤 id 清單）決定顯示哪些製程標籤的，
    // 只送攤平後的 process_no 會讓那邊的檢視畫面空白、編輯畫面點亮一堆不相干的標籤
    const subIdsStr = state.selected.join(',');
    $.post(API_URL, { action: 'quick_set_item_process', item_id: itemId, process_nos: [...procIds].join(','), group_type: groupType, sub_tag_ids: subIdsStr }, function(res) {
        if (!res.success) { qtNotify('設定製程失敗：' + res.message, 'err'); return; }
        Object.keys(qtItemsCache).forEach(function(qid) {
            qtItemsCache[qid].forEach(function(it) {
                if (String(it.item_id) === String(itemId)) { it.processes = [...procIds].join(','); it.process_notes = subIdsStr; }
            });
        });
        // 只更新完成度統計不必整頁重載
        refreshStatsOnly(itemId);
    });
}

// 帶入備註／取消：後端會重建該報價單備註欄的【規格備註】區塊（使用者自己寫的備註不動）
function setItemNoteOnly(itemId, on) {
    const it = findItemById(itemId);
    if (on && !String(it && it.specification || '').trim()) { qtNotify('這一筆沒有規格文字，沒有內容可以帶入備註。', 'warn'); return; }
    $.post(API_URL, { action: 'quick_set_item_note_only', item_id: itemId, on: on ? '1' : '0' }, function(res) {
        if (!res.success) { qtNotify('設定失敗：' + res.message, 'err'); return; }
        if (it) { it.note_only = on ? 1 : 0; if (on) { it.processes = ''; it.process_notes = ''; } }
        if (on) delete qtProcState[itemId];
        const qid = findQuoteIdByItemId(itemId);
        if (qid) drawItems(qid, qtItemsCache[qid]);
        refreshStatsOnly(itemId);
        showQtToast(on ? '已帶入報價單備註' : '已取消備註，請設定製程');
    });
}

function escapeQt(t) {
    return String(t == null ? '' : t).replace(/&/g, '&amp;').replace(/</g, '&lt;')
        .replace(/>/g, '&gt;').replace(/"/g, '&quot;');
}

// 局部更新該項目所屬報價單卡片的完成度徽章與頂端統計（避免重載整頁打斷正在操作的畫面）
function refreshStatsOnly(itemId) {
    let qid = null;
    Object.keys(qtItemsCache).forEach(function(k) {
        if (qtItemsCache[k].some(function(it){ return String(it.item_id) === String(itemId); })) qid = k;
    });
    if (!qid) return;
    const items = qtItemsCache[qid];
    const noDs = items.filter(function(it){ return !it.d_setting_d_id; }).length;
    const noPc = items.filter(function(it){ return !itemHasProcess(it); }).length;
    const row = qtData.find(function(r){ return String(r.quote_id) === String(qid); });
    if (row) { row.items_no_dsetting = noDs; row.items_no_process = noPc; }
    renderStats();
    const cnt = items.length;
    const badgeHtml =
        (noDs === 0 ? '<span class="qt-badge ok">料號ID已綁定</span>' : '<span class="qt-badge warn">料號ID缺 ' + noDs + '/' + cnt + '</span>') +
        (noPc === 0 ? '<span class="qt-badge ok">製程已設定</span>' : '<span class="qt-badge warn">製程缺 ' + noPc + '/' + cnt + '</span>');
    $('.qt-card[data-qid="' + qid + '"] .qt-badge-cell').html(badgeHtml);
    applyTransferGate(qid, noDs, cnt, noPc);
}

// ── 快速綁定料號ID：比照 NewOrder_Track.php 快速綁定 Modal，自動判斷客戶與料號 ──
let qbpItemId = null, qbpClientId = null, qbpSelectedPart = null, qbpParts = [], qbpOrigProductText = '';

function openQuickBindPart(itemId, productId, clientId, clientName) {
    qbpItemId = itemId; qbpClientId = clientId || null; qbpSelectedPart = null; qbpParts = [];
    qbpOrigProductText = productId;
    $('#qbpOrigText').text(productId);
    $('#qbpClientName').text(clientName || '（未設定）');
    $('#qbpLoading').show();
    $('#qbpResultArea').hide().empty();
    $('#qbpSaveBtn').hide();
    // 跳窗改由 renderQbpResults 決定要不要開：只找到一筆且料號完全相同時直接綁定，
    // 不必先把跳窗開起來再自己關掉（會閃一下）
    qbpLookup(productId);
}

function qbpLookup(term) {
    const params = { action: 'search_data', type: 'part', term: term };
    if (qbpClientId) params.customer_id = qbpClientId;
    $.get(API_URL, params, function(res) {
        const parts = res.success ? res.data : [];
        if (parts.length === 0 && qbpClientId) {
            // 此客戶底下找不到，退而求其次做全範圍搜尋（可能是屬於其他客戶或尚未綁客戶的料號）
            $.get(API_URL, { action: 'search_data', type: 'part', term: term }, function(res2) {
                $('#qbpLoading').hide();
                renderQbpResults(res2.success ? res2.data : [], term, true);
            });
        } else {
            $('#qbpLoading').hide();
            renderQbpResults(parts, term, false);
        }
    });
}

function renderQbpResults(parts, term, isFallback) {
    qbpParts = parts;
    const $area = $('#qbpResultArea').empty().show();
    let html = '';
    if (isFallback) html += '<div style="color:#a2703a;font-size:11px;margin-bottom:6px;">此客戶底下找不到符合的料號，以下是全範圍搜尋結果（可能屬於其他客戶）：</div>';

    if (parts.length === 0) {
        html += '<div style="color:#999;font-size:12px;margin-bottom:8px;">找不到符合的料號</div>';
    } else if (parts.length === 1) {
        qbpSelectedPart = parts[0];
        // 只找到一筆、而且料號名稱與原始料號文字完全相同 → 直接綁定，不用再按一次「確認綁定」。
        // 例外：全範圍退而求其次找到的那一筆若已經綁在「別的客戶」底下就不自動綁——
        // 那是跨客戶的判斷（別家的圖面/檢驗標準會一起接過來），一律交給使用者自己確認。
        const exact = String(parts[0].D_Setting_Id || '').trim().toUpperCase() === String(qbpOrigProductText || '').trim().toUpperCase();
        if (exact && (!isFallback || !parts[0].Client_Name)) { saveQuickBindPart(); return; }
        html += '<div><span class="label label-success"><i class="fa fa-check"></i> ' + parts[0].D_Setting_Id + (parts[0].Client_Name ? (' (' + parts[0].Client_Name + ')') : '') + '</span></div>';
    } else {
        html += '<div>';
        parts.forEach(function(p, i) {
            html += '<button type="button" class="btn btn-default btn-xs qbp-part-btn" style="margin:2px;" data-i="' + i + '">' + p.D_Setting_Id + (p.Client_Name ? (' (' + p.Client_Name + ')') : '') + '</button>';
        });
        html += '</div>';
    }

    html += '<div class="qt-quickform">' +
        '<input type="text" class="form-control input-sm qbp-new-no" placeholder="料號" value="' + String(term).replace(/"/g,'') + '">' +
        '<button type="button" class="btn btn-success btn-xs" onclick="submitQbpNewPart()"><i class="fa fa-plus"></i> 找不到？新增此料號' + (qbpClientId ? '（綁此客戶）' : '') + '</button>' +
        '<div class="qbp-new-err" style="color:#c0392b;font-size:11px;margin-top:3px;"></div>' +
        '</div>';

    $area.html(html);
    openMask('quickBindPartMask');
    $area.find('.qbp-part-btn').on('click', function() {
        $area.find('.qbp-part-btn').removeClass('btn-primary').addClass('btn-default');
        $(this).removeClass('btn-default').addClass('btn-primary');
        qbpSelectedPart = qbpParts[$(this).data('i')];
    });
    $('#qbpSaveBtn').toggle(parts.length > 0);
}

function submitQbpNewPart() {
    const $box = $('.qt-quickform');
    const no = $box.find('.qbp-new-no').val().trim();
    if (!no) { $box.find('.qbp-new-err').text('料號不可為空'); return; }
    $.post(API_URL, { action: 'save_part_info', part_no: no, type: 'N', customer_id: qbpClientId || '' }, function(res) {
        if (!res.success) { $box.find('.qbp-new-err').text(res.message || '建立失敗'); return; }
        qbpSelectedPart = { d_id: res.d_id, D_Setting_Id: no };
        saveQuickBindPart();
    });
}

function saveQuickBindPart() {
    if (!qbpSelectedPart) return;
    const boundItemId = qbpItemId, boundDId = qbpSelectedPart.d_id, boundProductText = qbpOrigProductText, boundClientId = qbpClientId;
    $.post(API_URL, { action: 'quick_bind_item_dsetting', item_id: boundItemId, d_id: boundDId }, function(res) {
        if (!res.success) { qtNotify('綁定失敗：' + res.message, 'err'); return; }
        const dSettingId = res.product_id || qbpSelectedPart.D_Setting_Id;
        Object.keys(qtItemsCache).forEach(function(qid) {
            qtItemsCache[qid].forEach(function(it) { if (String(it.item_id) === String(boundItemId)) { it.d_setting_d_id = boundDId; it.product_id = dSettingId; } });
        });
        closeMask('quickBindPartMask');
        const qid = findQuoteIdByItemId(boundItemId);
        if (qid) drawItems(qid, qtItemsCache[qid]);
        refreshStatsOnly(boundItemId);
        checkBatchBindCandidates(boundProductText, boundClientId, boundDId, dSettingId, boundItemId);
    });
}

// 綁定成功後，找出同料號文字＋同客戶、還沒綁定的其他項目，問是否一併綁定
function checkBatchBindCandidates(productText, clientId, dId, dSettingId, excludeItemId) {
    if (!productText) return;
    const params = { action: 'find_unbound_matches', product_text: productText, exclude_item_id: excludeItemId };
    if (clientId) params.client_id = clientId;
    $.get(API_URL, params, function(res) {
        if (!res.success || !res.data.length) return;
        bbCandidates = res.data;
        bbDId = dId; bbDSettingId = dSettingId;
        $('#bbCount').text(res.data.length);
        $('#bbProductText').text(productText);
        $('#bbTargetPart').text(dSettingId);
        let html = '';
        res.data.forEach(function(c, i) {
            html += '<tr><td><input type="checkbox" class="bb-chk" checked data-i="' + i + '"></td><td>' + c.quote_no + '</td><td>' + (c.specification || '') + '</td><td>' + c.quantity + '</td><td>' + c.unit_price + '</td></tr>';
        });
        $('#bbList').html(html);
        $('#bbCheckAll').prop('checked', true);
        openMask('batchBindMask');
    });
}

let bbCandidates = [], bbDId = null, bbDSettingId = '';
$(document).on('change', '#bbCheckAll', function() { $('.bb-chk').prop('checked', this.checked); });

function confirmBatchBind() {
    const ids = [];
    $('.bb-chk:checked').each(function() { ids.push(bbCandidates[$(this).data('i')].item_id); });
    if (!ids.length) { closeMask('batchBindMask'); return; }
    $.post(API_URL, { action: 'batch_bind_items_dsetting', item_ids: JSON.stringify(ids), d_id: bbDId }, function(res) {
        if (!res.success) { qtNotify('批次綁定失敗：' + res.message, 'err'); return; }
        const idSet = ids.map(String);
        const boundCandidates = bbCandidates.filter(function(c) { return idSet.indexOf(String(c.item_id)) !== -1; });

        // 更新已快取的項目明細（若該報價單卡片曾經渲染過）
        Object.keys(qtItemsCache).forEach(function(qid) {
            qtItemsCache[qid].forEach(function(it) { if (idSet.indexOf(String(it.item_id)) !== -1) { it.d_setting_d_id = bbDId; it.product_id = bbDSettingId; } });
        });

        // 只局部更新受影響的報價單卡片，不整頁重載，避免捲動位置/當前頁碼跳掉
        const byQuote = {};
        boundCandidates.forEach(function(c) { (byQuote[c.quote_id] = byQuote[c.quote_id] || []).push(c); });
        Object.keys(byQuote).forEach(function(qid) {
            if (qtItemsCache[qid]) {
                drawItems(qid, qtItemsCache[qid]);
                refreshStatsOnly(byQuote[qid][0].item_id);
            } else {
                // 該報價單卡片目前不在畫面上（尚未載入過明細），先更新統計數字，翻到那頁時會自然重新抓取正確明細
                const row = qtData.find(function(r) { return String(r.quote_id) === String(qid); });
                if (row) row.items_no_dsetting = Math.max(0, Number(row.items_no_dsetting) - byQuote[qid].length);
                renderStats();
            }
        });

        closeMask('batchBindMask');
    });
}

// Enter 鍵＝確認綁定（比照 NewOrder_Track.php 快速綁定 Modal；多選未選定時不觸發）
// 先 preventDefault+stopPropagation：eg_input_rules.js 的 containerOf() 認不得 .va-modal，會退化成把整個
// document.body 當成表單容器對整頁欄位做「Enter 跳下一欄」，導致跳窗內按 Enter 跳到頁面別處而不是送出。
$('#quickBindPartMask').on('keydown', function(e) {
    if (e.key !== 'Enter') return;
    e.preventDefault();
    e.stopPropagation();
    const $btn = $('#qbpSaveBtn');
    if (!$btn.is(':visible')) return;
    if (qbpParts.length > 1 && !qbpSelectedPart) return;
    saveQuickBindPart();
});

function openCustSwitch(quoteId, quoteNo, curName) {
    custSwitchQuoteId = quoteId;
    $('#custSwitchQuoteNo').text(quoteNo);
    $('#custSwitchCurrent').text(curName || '（未設定）');
    $('#custSwitchKw').val('');
    $('#custSwitchResults').hide().empty();
    custSwitchResults = [];
    $('#custNewForm').hide();
    $('#custNewId, #custNewName').val('');
    $('#custNewErr').text('');
    openMask('custSwitchMask');
}

let custSearchTimer = null;
let custSwitchResults = [];
$('#custSwitchKw').on('keyup', function(e) {
    if (e.key === 'Enter') return; // Enter 由下方 keydown 統一處理，避免重複觸發搜尋
    const kw = $(this).val().trim();
    clearTimeout(custSearchTimer);
    custSwitchResults = [];
    if (kw.length < 1) { $('#custSwitchResults').hide(); $('#custNewForm').hide(); return; }
    custSearchTimer = setTimeout(function() {
        $.get(API_URL, { action: 'search_data', type: 'customer', term: kw }, function(res) {
            const $r = $('#custSwitchResults');
            if (res.success && res.data.length) {
                custSwitchResults = res.data;
                let h = '';
                res.data.forEach(function(c, i) {
                    h += '<div class="qt-sr-item" data-i="' + i + '">' + c.customer + '　<small style="color:#aaa">' + c.customer_id + '</small></div>';
                });
                $r.html(h).show();
                $r.find('.qt-sr-item').on('click', function() {
                    const c = custSwitchResults[$(this).data('i')];
                    switchCustomer(c.customer_id, c.customer);
                });
                $('#custNewForm').hide();
            } else {
                $r.html('<div style="padding:5px 8px;color:#999;font-size:12px;">查無結果</div>').show();
                $('#custNewName').val(kw);
                $('#custNewForm').show();
            }
        });
    }, 300);
});

// Enter 鍵＝確認（唯一搜尋結果或已填妥新建表單時直接送出，比照 NewOrder_Track.php 快速綁定 Modal）
// 先 stopPropagation：eg_input_rules.js 的 containerOf() 認不得 .va-modal，會退化成把整個 document.body
// 當成表單容器、對整頁欄位做「Enter 跳下一欄」，導致跳窗內按 Enter 跳到頁面別處而不是送出。
$('#custSwitchMask').on('keydown', function(e) {
    if (e.key !== 'Enter') return;
    e.preventDefault();
    e.stopPropagation();
    if ($('#custNewForm').is(':visible')) {
        if ($('#custNewId').val().trim() && $('#custNewName').val().trim()) submitNewCustomer();
    } else if (custSwitchResults.length === 1) {
        switchCustomer(custSwitchResults[0].customer_id, custSwitchResults[0].customer);
    }
});

function submitNewCustomer() {
    const id = $('#custNewId').val().trim();
    const name = $('#custNewName').val().trim();
    if (!id || !name) { $('#custNewErr').text('客戶代碼與名稱都必填'); return; }
    $.post(API_URL, { action: 'save_customer', customer_id_new: id, customer_name_modal: name }, function(res) {
        if (!res.success) { $('#custNewErr').text(res.message || '建立失敗'); return; }
        switchCustomer(res.customer_id, name);
    });
}

function switchCustomer(customerId, customerName) {
    $.post(API_URL, { action: 'quick_switch_quote_customer', quote_id: custSwitchQuoteId, customer_id: customerId }, function(res) {
        if (!res.success) { qtNotify('切換失敗：' + res.message, 'err'); return; }
        closeMask('custSwitchMask');
        loadPendingList();
    });
}

function updateSelCount() {
    const n = $('.qt-row-chk:checked').length;
    $('#qtSelCount').text(n > 0 ? ('已選 ' + n + ' 張') : '');
}
$(document).on('change', '.qt-row-chk, #qtCheckAll', function() {
    if (this.id === 'qtCheckAll') $('.qt-row-chk:not(:disabled)').prop('checked', this.checked);
    updateSelCount();
});

function doConfirmTransfer(ids, doneMsg) {
    // 轉入成功後刻意「不」整頁重載：原本 loadPendingList() 會把頁碼歸零跳回第一頁，
    // 在第 8 頁處理到一半的人每轉一張就被丟回開頭。改成就地把轉走的那幾張從清單移除，
    // 停在原本的頁次與捲動位置繼續做（最後一張被移走造成頁數變少時才往前退一頁）。
    const keepPage   = qtPage;
    const keepScroll = window.scrollY;
    $.post(API_URL, { action: 'quick_confirm_transfer', quote_ids: JSON.stringify(ids) }, function(res) {
        if (!res.success) { qtNotify('轉入失敗：' + res.message, 'err'); return; }
        const gone = ids.map(String);
        qtData = qtData.filter(function(r){ return gone.indexOf(String(r.quote_id)) === -1; });
        gone.forEach(function(id){ delete qtItemsCache[id]; });
        populateYearFilter();
        populateCustFilter();
        renderStats();
        qtPage = keepPage;
        renderCards();                       // renderCards 內會把超出範圍的頁碼夾回最後一頁
        window.scrollTo(0, keepScroll);
        showQtToast(doneMsg || ('已轉入 ' + res.updated + ' 張報價單'));
    });
}

// 轉入成功用小提示帶過就好，不要用 alert 打斷連續作業（失敗才跳 alert）
// 全頁的訊息一律走右下角浮動提示，不用 alert（使用者要求：不要一直按 Enter 確認）。
// 三種語意：ok＝綠底（做完了）、warn＝橘底（擋下來的原因）、err＝紅底（失敗）。
// 多則會往上堆疊不互相覆蓋；預設 5 秒自動消失，內容長的自動延長，滑鼠移上去不會消失。
function qtNotify(msg, type) {
    let $wrap = $('#qtToastWrap');
    if (!$wrap.length) {
        $wrap = $('<div id="qtToastWrap" style="position:fixed;right:20px;bottom:24px;z-index:2000;' +
                  'display:flex;flex-direction:column-reverse;gap:8px;max-width:420px;"></div>').appendTo('body');
    }
    const c = { ok:   { bg:'#2e7d32', fg:'#fff' },
                warn: { bg:'#F0A24B', fg:'#4E2C0B' },
                err:  { bg:'#DD5138', fg:'#fff' } }[type || 'ok'] || { bg:'#2e7d32', fg:'#fff' };
    const $t = $('<div></div>').css({
        background: c.bg, color: c.fg, padding: '10px 16px', borderRadius: '6px', fontSize: '13px',
        boxShadow: '0 4px 12px rgba(0,0,0,.3)', whiteSpace: 'pre-line', lineHeight: '1.5', display: 'none'
    }).text(String(msg == null ? '' : msg)).appendTo($wrap);
    // 內容長的多留一點時間看（每 40 個字多 1 秒，最多 15 秒）
    const ms = Math.min(15000, 5000 + Math.floor(String(msg || '').length / 40) * 1000);
    let timer = setTimeout(function(){ $t.fadeOut(300, function(){ $t.remove(); }); }, ms);
    $t.on('mouseenter', function(){ clearTimeout(timer); })
      .on('mouseleave', function(){ timer = setTimeout(function(){ $t.fadeOut(300, function(){ $t.remove(); }); }, 2000); })
      .on('click', function(){ clearTimeout(timer); $t.fadeOut(150, function(){ $t.remove(); }); })
      .fadeIn(120);
}
function showQtToast(msg) { qtNotify(msg, 'ok'); }

function confirmTransferOne(quoteId, quoteNo) {
    const row = qtData.find(function(r){ return String(r.quote_id) === String(quoteId); });
    const gate = transferGate(row ? row.items_no_dsetting : 0, row ? row.item_count : 0, row ? row.items_no_process : 0);
    if (!gate.ok) { qtNotify('報價單 ' + quoteNo + ' 無法轉入正式報價單：\n' + gate.reason, 'warn'); return; }
    if (!confirm('確定要將報價單 ' + quoteNo + ' 轉入正式報價單嗎？轉入後將從本頁移除。')) return;
    doConfirmTransfer([quoteId]);
}

// ── 批次設定製程：把同一組製程一次套用到目前篩選範圍內的項目 ──
// 典型用法＝先用客戶下拉篩到某一家，該客戶的單製程多半相同，一次設完。
let bpState = { activeGid: null, selected: [] };

$('#btnBulkProc').on('click', function() {
    const rows = getFilteredData();
    if (!rows.length) { qtNotify('目前篩選範圍內沒有報價單。', 'warn'); return; }
    bpState = { activeGid: processTagTree.length ? processTagTree[0].group_id : null, selected: [] };
    const cust = $('#qtCustFilter').val();
    const custName = cust ? ($('#qtCustFilter option:selected').text().split('—')[0].trim()) : '全部客戶';
    $('#bpScopeText').text('年份：' + ($('#qtYearFilter').val() || '全部') + '　客戶：' + custName + '　報價單：' + rows.length + ' 張');
    $('#bpScopeAllCnt').text(rows.length);
    $('#bpScopePageCnt').text(qtPageRows.length);
    $('input[name="bpTarget"][value="unset"]').prop('checked', true);
    $('input[name="bpScope"][value="filtered"]').prop('checked', true);
    renderBpTags();
    updateBpCount();
    openMask('bulkProcMask');
});

function renderBpTags() {
    let l1 = '<div class="qt-proc-l1">';
    processTagTree.forEach(function(g) {
        l1 += '<button type="button" class="' + (g.group_id === bpState.activeGid ? 'active' : '') + '" onclick="bpSetGroup(' + g.group_id + ')">' + g.group_name + '</button>';
    });
    l1 += '</div>';
    let l2 = '<div class="qt-proc-l2" style="margin-top:4px;">';
    const g = processTagTree.find(function(x){ return x.group_id === bpState.activeGid; });
    if (g) (g.sub_tags || []).forEach(function(st) {
        l2 += '<button type="button" class="' + (bpState.selected.indexOf(st.sub_tag_id) !== -1 ? 'active' : '') + '" onclick="bpToggle(' + st.sub_tag_id + ')">' + st.sub_tag_name + '</button>';
    });
    l2 += '</div>';
    let chips = '<div class="qt-proc-chips">';
    bpState.selected.forEach(function(sid) {
        let nm = '';
        processTagTree.forEach(function(g2){ (g2.sub_tags||[]).forEach(function(st){ if (st.sub_tag_id === sid) nm = st.sub_tag_name; }); });
        chips += '<span class="qt-proc-chip">' + nm + '<span class="x" onclick="bpToggle(' + sid + ')">×</span></span>';
    });
    chips += '</div>';
    $('#bpTagArea').html(l1 + l2 + chips);
}
function bpSetGroup(gid) { bpState.activeGid = gid; renderBpTags(); }
function bpToggle(sid) {
    const i = bpState.selected.indexOf(sid);
    if (i === -1) bpState.selected.push(sid); else bpState.selected.splice(i, 1);
    renderBpTags();
}

// 套用範圍：預設「目前篩選的全部報價單」（可能跨很多頁），也可以只套用到目前這一頁——
// 先套一頁確認結果對不對，比一口氣套用幾百張安全。
function getBpRows() {
    return $('input[name="bpScope"]:checked').val() === 'page' ? qtPageRows : getFilteredData();
}

// 影響筆數＝選定範圍內的全部項目（選「篩選全部」時不是只算目前這一頁），用清單上的統計數字加總，
// 不必把每張報價單的明細都抓回來；實際要動哪些項目由後端自己挑（見 quick_set_process_bulk）
function updateBpCount() {
    const onlyUnset = $('input[name="bpTarget"]:checked').val() === 'unset';
    const n = getBpRows().reduce(function(a, r) {
        return a + Number(onlyUnset ? r.items_no_process : r.item_count);
    }, 0);
    $('#bpCount').text(n);
}
$(document).on('change', 'input[name="bpTarget"],input[name="bpScope"]', updateBpCount);
$(document).on('change', '#kwIncludeSet', runKwScan);

function submitBulkProc() {
    if (!bpState.selected.length) { qtNotify('請先選擇要套用的製程標籤。', 'warn'); return; }
    const onlyUnset = $('input[name="bpTarget"]:checked').val() === 'unset';
    const onlyPage  = $('input[name="bpScope"]:checked').val() === 'page';
    const rows = getBpRows();
    if (!rows.length) { qtNotify(onlyPage ? '目前這一頁沒有報價單。' : '目前篩選範圍內沒有報價單。', 'warn'); return; }
    const est = $('#bpCount').text();
    if (!confirm('將把選定的製程套用到' + (onlyPage ? '目前這一頁的 ' : '') + rows.length + ' 張報價單、約 ' + est + ' 筆項目' +
                 (onlyUnset ? '（只有還沒設定製程的）' : '（會覆蓋原本已設定的）') + '。\n\n確定要執行嗎？')) return;

    const $btn = $('#bpSubmitBtn').prop('disabled', true).html('<i class="fa fa-spinner fa-spin"></i> 處理中…');
    const qids = rows.map(function(r){ return Number(r.quote_id); });
    $.post(API_URL, { action: 'quick_set_process_bulk', quote_ids: JSON.stringify(qids),
                      only_unset: onlyUnset ? '1' : '0', sub_tag_ids: bpState.selected.join(',') }, function(res) {
        $btn.prop('disabled', false).html('<i class="fa fa-check"></i> 套用');
        if (!res.success) { qtNotify('批次設定製程失敗：' + res.message, 'err'); return; }
        closeMask('bulkProcMask');
        showQtToast('已設定 ' + res.updated + ' 筆項目的製程');
        // 動到的範圍可能很大，快取一律作廢重抓，避免畫面與資料庫不一致
        qtItemsCache = {};
        qtProcState  = {};
        const keepPage = qtPage, keepScroll = window.scrollY;
        loadPendingList(function(){ qtPage = keepPage; renderCards(); window.scrollTo(0, keepScroll); });
    });
}

// 這些項目的製程狀態變了，清掉舊的畫面狀態讓它依新的 process_notes 重畫
function clearProcStateForQuote(qid) {
    (qtItemsCache[qid] || []).forEach(function(it){ delete qtProcState[it.item_id]; });
}


// 頁面最上方的一鍵：把目前年份篩選範圍內所有還沒綁完料號的報價單一次做完。
// 刻意不做成「一進頁面自動跑」——這個動作會在料號主檔建立新料號，是改不回來的，
// 每次開頁就自動建立料號太危險（例如某張單的客戶設錯，會一口氣建出一批掛錯客戶的料號）。
$('#btnBatchAutoBind').on('click', function() {
    const rows = getFilteredData().filter(function(r){ return Number(r.items_no_dsetting) > 0; });
    if (!rows.length) { qtNotify('目前篩選範圍內沒有需要綁定料號的報價單。', 'warn'); return; }
    const noCust = rows.filter(function(r){ return !r.client_id; });
    const items  = rows.reduce(function(a, r){ return a + Number(r.items_no_dsetting); }, 0);
    const scope  = $('#qtYearFilter').val() ? ($('#qtYearFilter').val() + ' 年') : '全部年份';
    let msg = '將對「' + scope + '」範圍內的 ' + rows.length + ' 張報價單、共 ' + items + ' 筆未綁定項目自動建立並綁定料號。\n\n';
    msg += '處理規則：\n・依料號文字先找既有料號（先找該單客戶的，再找沒綁客戶的）\n・都找不到才以該單客戶新建一筆\n・完全同名的既有料號超過一筆時不自動綁，會列出來\n';
    if (noCust.length) msg += '\n其中 ' + noCust.length + ' 張還沒設定客戶，會跳過不處理。\n';
    msg += '\n這會在料號主檔建立新料號（建立後不會自動刪除），確定要執行嗎？';
    if (!confirm(msg)) return;

    const $b = $(this).prop('disabled', true).html('<i class="fa fa-spinner fa-spin"></i> 處理中，請稍候…');
    const ids = rows.map(function(r){ return Number(r.quote_id); });
    $.post(API_URL, { action: 'quick_autobind_quote', quote_ids: JSON.stringify(ids) }, function(res) {
        $b.prop('disabled', false).html('<i class="fa fa-magic"></i> 一鍵建立並綁定料號（全部）');
        if (!res.success) { qtNotify('自動建立並綁定失敗：' + res.message, 'err'); return; }
        let m = '已處理 ' + res.quotes + ' 張報價單，綁定 ' + res.bound + ' 筆項目。';
        if (res.created)   m += '\n新建料號 ' + res.created + ' 筆';
        if (res.reused)    m += '\n沿用既有料號 ' + res.reused + ' 筆';
        if (res.skipped)   m += '\n略過 ' + res.skipped + ' 筆（料號欄是空的）';
        if (res.ambiguous) m += '\n\n有 ' + res.ambiguous + ' 筆因為主檔裡存在多筆完全同名的料號而沒有自動綁定，\n請用該列的「快速綁定」自行挑選：\n' + res.ambiguous_nos.join('、');
        if (res.issue_count) m += '\n\n以下 ' + res.issue_count + ' 張沒有處理：\n' + res.issues.map(function(i){ return '・' + i.quote_no + '：' + i.reason; }).join('\n');
        qtNotify(m, 'ok');
        loadPendingList();
    });
});

$('#btnBatchConfirm').on('click', function() {
    const ids = $('.qt-row-chk:checked').map(function(){ return Number($(this).val()); }).get();
    if (!ids.length) { qtNotify('請先勾選要轉入正式報價單的項目', 'warn'); return; }
    const blocked = [];
    ids.forEach(function(id) {
        const row = qtData.find(function(r){ return Number(r.quote_id) === Number(id); });
        if (!row) return;
        const g = transferGate(row.items_no_dsetting, row.item_count, row.items_no_process);
        if (!g.ok) blocked.push('・' + row.quote_no + '：' + g.reason.replace('，補齊後才能轉入正式報價單', ''));
    });
    if (blocked.length) {
        qtNotify('以下 ' + blocked.length + ' 張報價單還沒補齊，不可轉入正式報價單：\n' + blocked.join('\n') + '\n\n請先補齊料號ID與製程後再轉入。', 'warn');
        return;
    }
    if (!confirm('確定要將這 ' + ids.length + ' 張報價單轉入正式報價單清單嗎？轉入後將從本頁移除。')) return;
    doConfirmTransfer(ids);
});

// ══════════════════════════════════════════════════════════════════
// 關鍵字自動偵測製程：依規格文字的規則建議標籤 → 依「建議的標籤組合」分組 → 人工確認後套用
// 系統只建議、絕不自動套用（「泓創綠能」與「泓創綠能科技」那種同理：像不代表是）。
// ══════════════════════════════════════════════════════════════════
let kwGroups = [];          // 後端回來的分組結果
let kwChecked = {};         // item_id => true（勾選狀態，跨分組共用一份）

$('#btnKwScan').on('click', function() {
    const rows = getFilteredData();
    if (!rows.length) { qtNotify('目前篩選範圍內沒有報價單。', 'warn'); return; }
    $('#kwScopeAllCnt').text(rows.length);
    $('#kwScopePageCnt').text(qtPageRows.length);
    $('input[name="kwScope"][value="filtered"]').prop('checked', true);
    $('#kwGroups').html('');
    $('#kwScanSummary').html('');
    openMask('kwScanMask');
    runKwScan();
});

function kwScanRows() {
    return $('input[name="kwScope"]:checked').val() === 'page' ? qtPageRows : getFilteredData();
}

function runKwScan() {
    const rows = kwScanRows();
    const cust = $('#qtCustFilter').val();
    const custName = cust ? ($('#qtCustFilter option:selected').text().split('—')[0].trim()) : '全部客戶';
    $('#kwScanScope').text('年份：' + ($('#qtYearFilter').val() || '全部') + '　客戶：' + custName + '　掃描 ' + rows.length + ' 張報價單');
    $('#kwGroups').html('<div style="color:#999;padding:14px;"><i class="fa fa-spinner fa-spin"></i> 偵測中…</div>');
    const onlyUnset = !$('#kwIncludeSet').is(':checked');
    $.post(API_URL, { action: 'qkw_scan', quote_ids: JSON.stringify(rows.map(function(r){ return Number(r.quote_id); })),
                      only_unset: onlyUnset ? '1' : '0' }, function(res) {
        if (!res.success) { $('#kwGroups').html('偵測失敗：' + (res.message || '')); return; }
        kwGroups = res.data.groups || [];
        kwChecked = {};
        // 預設全部勾選（使用者要的是「快速確認」，逐筆取消比逐筆勾選省力得多）
        kwGroups.forEach(function(g){ g.items.forEach(function(it){ kwChecked[it.item_id] = true; }); });
        $('#kwScanSummary').html(res.data.rules === 0
            ? '<span style="color:#DD5138;">尚未設定任何關鍵字規則，請先按工具列的「規則設定」新增。</span>'
            : ('掃描 ' + res.data.scanned + ' 筆未設定製程的項目，命中 <b>' + res.data.matched + '</b> 筆，共 ' + kwGroups.length + ' 種建議組合（使用 ' + res.data.rules + ' 條規則）'));
        renderKwGroups();
    });
}

function renderKwGroups() {
    if (!kwGroups.length) { $('#kwGroups').html('<div style="color:#999;padding:14px;">沒有任何項目命中規則。</div>'); updateKwSel(); return; }
    let html = '';
    kwGroups.forEach(function(g, gi) {
        const checkedN = g.items.filter(function(it){ return kwChecked[it.item_id]; }).length;
        const hadN = g.items.filter(function(it){ return Number(it.had); }).length;
        html += '<div class="kw-group" id="kwG' + gi + '">' +
            '<div class="kw-group-head" onclick="kwToggleOpen(' + gi + ')">' +
                '<input type="checkbox" onclick="event.stopPropagation();kwToggleGroup(' + gi + ',this.checked)" ' +
                    (checkedN === g.items.length ? 'checked' : '') + ' id="kwGChk' + gi + '">' +
                '<span class="kw-label">' + (g.kind === 'note' ? '<span class="qt-badge note">備註</span> ' : '') + escapeQt(g.label) + '</span>' +
                '<span style="color:#8a5a2b;">' + g.count + ' 筆</span>' +
                (hadN ? '<span class="qt-badge warn">其中 ' + hadN + ' 筆原本已有設定，套用會覆蓋</span>' : '') +
                '<span class="kw-rules">命中規則：' + escapeQt((g.rules || []).join('、')) + '</span>' +
                '<span style="margin-left:auto;font-size:11px;color:#a2703a;" id="kwGSel' + gi + '">已勾 ' + checkedN + '</span>' +
                '<i class="fa fa-caret-down"></i>' +
            '</div>' +
            '<div class="kw-group-body" id="kwGBody' + gi + '"></div>' +
        '</div>';
    });
    $('#kwGroups').html(html);
    updateKwSel();
}

// 逐筆清單很大（單一組可能三千筆），展開時才畫，避免一次塞進 DOM 卡住整個跳窗
function kwToggleOpen(gi) {
    const $g = $('#kwG' + gi);
    const open = $g.hasClass('open');
    if (!open && !$g.data('drawn')) { kwDrawItems(gi); $g.data('drawn', 1); }
    $g.toggleClass('open', !open);
}

function kwDrawItems(gi) {
    const g = kwGroups[gi];
    let h = '<table class="kw-item-table">';
    g.items.forEach(function(it) {
        h += '<tr><td style="width:22px;"><input type="checkbox" class="kw-item-chk" data-gi="' + gi + '" value="' + it.item_id + '"' +
             (kwChecked[it.item_id] ? ' checked' : '') + '></td>' +
             '<td style="width:66px;">' + (Number(it.had) ? '<span class="qt-badge warn">會覆蓋</span>' : '') + '</td>' +
             '<td style="width:120px;color:#8a5a2b;">' + escapeQt(it.quote_no) + '</td>' +
             '<td style="width:110px;">' + escapeQt(it.client_name || '') + '</td>' +
             '<td style="width:150px;">' + escapeQt(it.product_id || '') + '</td>' +
             '<td>' + escapeQt(it.spec || '') + '</td></tr>';
    });
    $('#kwGBody' + gi).html(h + '</table>');
}

$(document).on('change', '.kw-item-chk', function() {
    kwChecked[this.value] = this.checked;
    const gi = Number($(this).data('gi'));
    kwSyncGroupHead(gi);
    updateKwSel();
});

function kwSyncGroupHead(gi) {
    const g = kwGroups[gi];
    const n = g.items.filter(function(it){ return kwChecked[it.item_id]; }).length;
    $('#kwGChk' + gi).prop('checked', n === g.items.length).prop('indeterminate', n > 0 && n < g.items.length);
    $('#kwGSel' + gi).text('已勾 ' + n);
}

function kwToggleGroup(gi, on) {
    kwGroups[gi].items.forEach(function(it){ kwChecked[it.item_id] = !!on; });
    $('#kwGBody' + gi).find('.kw-item-chk').prop('checked', !!on);
    kwSyncGroupHead(gi);
    updateKwSel();
}

function kwSelectAll(on) {
    kwGroups.forEach(function(g, gi){ kwToggleGroup(gi, on); });
}

function updateKwSel() {
    let n = 0;
    Object.keys(kwChecked).forEach(function(k){ if (kwChecked[k]) n++; });
    $('#kwSelCount').text(n);
    $('#kwApplyBtn').prop('disabled', n === 0);
}

function submitKwApply() {
    const batches = [];
    let total = 0;
    kwGroups.forEach(function(g) {
        const ids = g.items.filter(function(it){ return kwChecked[it.item_id]; }).map(function(it){ return it.item_id; });
        if (!ids.length) return;
        total += ids.length;
        batches.push(g.kind === 'note' ? { to_note: 1, item_ids: ids } : { sub_tag_ids: g.sub_tag_ids, item_ids: ids });
    });
    if (!total) { qtNotify('沒有勾選任何項目。', 'warn'); return; }
    if (!confirm('將把確認過的建議套用到 ' + total + ' 筆項目（' + batches.length + ' 種組合）。\n\n確定要執行嗎？')) return;
    const $btn = $('#kwApplyBtn').prop('disabled', true).html('<i class="fa fa-spinner fa-spin"></i> 套用中…');
    $.post(API_URL, { action: 'qkw_apply', batches: JSON.stringify(batches) }, function(res) {
        $btn.prop('disabled', false).html('<i class="fa fa-check"></i> 套用已勾選的項目');
        if (!res.success) { qtNotify('套用失敗：' + res.message, 'err'); return; }
        closeMask('kwScanMask');
        showQtToast('已套用 ' + res.data.items + ' 筆項目' + (res.data.note_quotes ? ('，' + res.data.note_quotes + ' 張報價單已更新備註') : ''));
        qtItemsCache = {}; qtProcState = {};
        const keepPage = qtPage, keepScroll = window.scrollY;
        loadPendingList(function(){ qtPage = keepPage; renderCards(); window.scrollTo(0, keepScroll); });
    });
}

// ── 關鍵字規則設定 ─────────────────────────────────────────
let krRules = [], krTagSel = [], krCustSel = {};

$('#btnKwRules').on('click', function() { openMask('kwRuleMask'); krLoad(); });

function krLoad() {
    $.get(API_URL, { action: 'qkw_rule_list' }, function(res) {
        if (!res.success) { qtNotify('讀取規則失敗：' + (res.message || ''), 'err'); return; }
        krRules = res.data || [];
        krRenderRows(res.tags || {});
        krResetForm();
    });
}

function krRenderRows(tagNames) {
    if (!krRules.length) { $('#kwRuleRows').html('<tr><td colspan="6" style="color:#999;padding:8px;">尚未設定任何規則</td></tr>'); return; }
    let h = '';
    krRules.forEach(function(r) {
        const tags = String(r.sub_tag_ids || '').split(',').filter(Boolean)
            .map(function(id){ return tagNames[id] || ('#' + id); }).join('、');
        h += '<tr class="kw-rule-row">' +
            '<td>' + escapeQt(r.rule_name) + (Number(r.is_active) ? '' : ' <span class="qt-badge warn">停用</span>') + '</td>' +
            '<td>' + escapeQt(r.include_kw) +
                (String(r.include_kw).indexOf(',') >= 0
                    ? ' <span class="qt-badge ' + (r.include_mode === 'any' ? 'ok' : 'warn') + '">' +
                      (r.include_mode === 'any' ? '任一即可' : '全部都要含') + '</span>' : '') + '</td>' +
            '<td>' + escapeQt(r.exclude_kw) + '</td>' +
            '<td>' + (r.customer_ids ? escapeQt(r.customer_ids) : '<span style="color:#888;">通用</span>') + '</td>' +
            '<td>' + (Number(r.to_note) ? '<span class="qt-badge note">備註</span>' : escapeQt(tags)) + '</td>' +
            '<td><a href="javascript:void(0)" onclick="krEdit(' + r.rule_id + ')">編輯</a>　' +
                '<a href="javascript:void(0)" style="color:#DD5138;" onclick="krDelete(' + r.rule_id + ')">刪除</a></td>' +
        '</tr>';
    });
    $('#kwRuleRows').html(h);
}

// 與項目列上的製程選擇器同一種操作方式：先點大類、再點子標籤，已選的顯示在下方（可按 × 移除）
let krActiveGid = null;
function krRenderTagPicker() {
    if (krActiveGid === null && processTagTree.length) krActiveGid = processTagTree[0].group_id;
    let l1 = '<div class="qt-proc-l1">';
    processTagTree.forEach(function(g) {
        l1 += '<button type="button" class="' + (g.group_id === krActiveGid ? 'active' : '') +
              '" onclick="krSetGroup(' + g.group_id + ')">' + escapeQt(g.group_name) + '</button>';
    });
    l1 += '</div>';
    let l2 = '<div class="qt-proc-l2" style="margin-top:3px;">';
    const g = processTagTree.find(function(x){ return x.group_id === krActiveGid; });
    if (g) (g.sub_tags || []).forEach(function(st) {
        l2 += '<button type="button" class="' + (krTagSel.indexOf(st.sub_tag_id) !== -1 ? 'active' : '') +
              '" onclick="krToggleTag(' + st.sub_tag_id + ')">' + escapeQt(st.sub_tag_name) + '</button>';
    });
    l2 += '</div>';
    let chips = '<div class="qt-proc-chips" style="margin-top:4px;">';
    if (!krTagSel.length) {
        chips += '<span style="color:#888;">尚未選擇任何製程標籤</span>';
    } else {
        chips += '<span style="color:#8a5a2b;margin-right:4px;">已選：</span>';
        krTagSel.forEach(function(sid) {
            let nm = String(sid);
            processTagTree.forEach(function(g2){ (g2.sub_tags||[]).forEach(function(st){ if (st.sub_tag_id === sid) nm = st.sub_tag_name; }); });
            chips += '<span class="qt-proc-chip">' + escapeQt(nm) + '<span class="x" onclick="krToggleTag(' + sid + ')">&times;</span></span>';
        });
    }
    chips += '</div>';
    $('#krTagArea').html(l1 + l2 + chips);
}
function krSetGroup(gid) { krActiveGid = gid; krRenderTagPicker(); }

// 即時試算：邊打邊算「這條規則會命中幾筆」，避免存完才發現逗號語意搞錯、一筆都沒中
let krPvTimer = null;
function krPreview() {
    clearTimeout(krPvTimer);
    krPvTimer = setTimeout(function() {
        const inc = $('#krInc').val().trim();
        if (!inc) { $('#krPreview').text(''); return; }
        $('#krPreview').text('試算中…');
        $.post(API_URL, { action: 'qkw_rule_preview', include_kw: inc,
                          include_mode: $('input[name="krIncMode"]:checked').val(),
                          exclude_kw: $('#krExc').val().trim(),
                          customer_ids: Object.keys(krCustSel).join(',') }, function(res) {
            if (!res.success) { $('#krPreview').text(''); return; }
            const d = res.data;
            if (!d.matched) {
                $('#krPreview').html('<span style="color:#DD5138;">目前尚待確認的資料裡<b>一筆都沒有命中</b>' +
                    '——如果你要表達的是「這些字其中一個」，請把上面切成「任一即可」。</span>');
                return;
            }
            $('#krPreview').html('這條規則會命中 <b>' + d.matched + '</b> 筆（其中 <b>' + d.unset +
                '</b> 筆還沒設定製程）　例：' + d.samples.map(escapeQt).join(' ｜ '));
        });
    }, 350);
}
$(document).on('input', '#krInc,#krExc', krPreview);
$(document).on('change', 'input[name="krIncMode"]', krPreview);

function krToggleTag(sid) {
    const i = krTagSel.indexOf(sid);
    if (i === -1) krTagSel.push(sid); else krTagSel.splice(i, 1);
    if (krTagSel.length) $('#krToNote').prop('checked', false);
    krRenderTagPicker();
    krValidate();
}

// 客戶候選＝目前尚待確認的報價單實際出現過的客戶（與工具列下拉同一份來源）
function krRenderCustBox(filter) {
    const map = {};
    qtData.forEach(function(r) {
        const id = String(r.client_id || '');
        if (!id) return;
        if (!map[id]) map[id] = { id: id, name: r.client_name || '' };
    });
    const kw = String(filter || '').trim().toLowerCase();
    let h = '';
    Object.keys(map).sort().forEach(function(id) {
        const txt = map[id].name + '（' + id + '）';
        if (kw && txt.toLowerCase().indexOf(kw) === -1 && !krCustSel[id]) return;
        h += '<label style="display:block;"><input type="checkbox" class="kr-cust" value="' + id + '"' +
             (krCustSel[id] ? ' checked' : '') + '> ' + escapeQt(txt) + '</label>';
    });
    $('#krCustBox').html(h || '<span style="color:#999;">沒有符合的客戶</span>');
}
$(document).on('input', '#krCustFilter', function(){ krRenderCustBox(this.value); });
$(document).on('change', '.kr-cust', function(){ if (this.checked) krCustSel[this.value] = true; else delete krCustSel[this.value]; krPreview(); });
$(document).on('change', '#krToNote', function(){ if (this.checked) { krTagSel = []; krRenderTagPicker(); } krValidate(); });
$(document).on('input', '#krName,#krInc', krValidate);

// 前端即時驗證，後端 qkw_rule_validate() 同一套規則再擋一次（鐵律8）
function krValidate() {
    let ok = true;
    const name = $('#krName').val().trim(), inc = $('#krInc').val().trim();
    $('#krNameErr').text(name ? '' : '請填規則名稱'); if (!name) ok = false;
    $('#krIncErr').text(inc ? '' : '請至少填一個「包含」關鍵字'); if (!inc) ok = false;
    const toNote = $('#krToNote').is(':checked');
    let tagErr = '';
    if (!toNote && !krTagSel.length) tagErr = '請選擇要帶入的製程標籤，或改勾「帶入備註」';
    if (toNote && krTagSel.length) tagErr = '「帶入備註」與製程標籤只能擇一';
    $('#krTagErr').text(tagErr); if (tagErr) ok = false;
    $('#krSaveBtn').prop('disabled', !ok);
    return ok;
}

function krResetForm() {
    $('#krRuleId').val(0); $('#krName').val(''); $('#krInc').val(''); $('#krExc').val('');
    $('#krPriority').val(0); $('#krToNote').prop('checked', false);
    $('input[name="krIncMode"][value="any"]').prop('checked', true); $('#krPreview').text('');
    krTagSel = []; krCustSel = {}; $('#krCustFilter').val('');
    krRenderTagPicker(); krRenderCustBox(''); krValidate();
}

function krEdit(ruleId) {
    const r = krRules.find(function(x){ return Number(x.rule_id) === Number(ruleId); });
    if (!r) return;
    $('#krRuleId').val(r.rule_id); $('#krName').val(r.rule_name); $('#krInc').val(r.include_kw);
    $('#krExc').val(r.exclude_kw); $('#krPriority').val(r.priority);
    $('input[name="krIncMode"][value="' + (r.include_mode === 'any' ? 'any' : 'all') + '"]').prop('checked', true);
    krPreview();
    $('#krToNote').prop('checked', Number(r.to_note) === 1);
    krTagSel = String(r.sub_tag_ids || '').split(',').filter(Boolean).map(Number);
    krCustSel = {}; String(r.customer_ids || '').split(',').filter(Boolean).forEach(function(c){ krCustSel[c] = true; });
    $('#krCustFilter').val('');
    krRenderTagPicker(); krRenderCustBox(''); krValidate();
}

function krSave() {
    if (!krValidate()) return;
    $.post(API_URL, {
        action: 'qkw_rule_save', rule_id: $('#krRuleId').val(), rule_name: $('#krName').val().trim(),
        include_kw: $('#krInc').val().trim(), include_mode: $('input[name="krIncMode"]:checked').val(),
        exclude_kw: $('#krExc').val().trim(),
        customer_ids: Object.keys(krCustSel).join(','), sub_tag_ids: krTagSel.join(','),
        to_note: $('#krToNote').is(':checked') ? 1 : 0, priority: $('#krPriority').val() || 0, is_active: 1
    }, function(res) {
        if (!res.success) { qtNotify('儲存失敗：' + res.message, 'err'); return; }
        showQtToast('規則已儲存');
        krLoad();
    });
}

// 建議規則範本：常見的製程字對到對應標籤（例：規格含「齒研」但不含「冶具/治具/刀/全製」→ 齒研）。
// 只是起手式，建立後照樣可以改關鍵字、換標籤、指定客戶或停用。
function krSeed() {
    if (!confirm('將建立一組常見的製程關鍵字規則（同名的不會重複建立）。' + '\n' +
                 '建立後可自行增修或刪除，且一律仍要人工確認才會套用。' + '\n\n' +
                 '確定要載入嗎？')) return;
    $.post(API_URL, { action: 'qkw_rule_seed' }, function(res) {
        if (!res.success) { qtNotify('載入失敗：' + res.message, 'err'); return; }
        showQtToast(res.added ? ('已新增 ' + res.added + ' 條規則') : '沒有新增（範本規則都已存在）');
        krLoad();
    });
}

function krDelete(ruleId) {
    if (!confirm('確定要刪除這條規則嗎？（不影響已經套用出去的製程設定）')) return;
    $.post(API_URL, { action: 'qkw_rule_delete', rule_id: ruleId }, function(res) {
        if (!res.success) { qtNotify('刪除失敗：' + res.message, 'err'); return; }
        showQtToast('規則已刪除');
        krLoad();
    });
}

// ── 一鍵轉入「已補齊」的報價單 ──────────────────────────
// 依標籤分組確認完之後，使用者不會知道哪幾張整張都補齊了，所以由系統直接算給他。
function readyQuotes() {
    return getFilteredData().filter(function(r) {
        return Number(r.items_no_dsetting) === 0 && Number(r.items_no_process) === 0 && Number(r.item_count) > 0;
    });
}

function updateReadyCount() {
    $('#qtReadyCnt').text('(' + readyQuotes().length + ')');
}

$('#btnTransferReady').on('click', function() {
    const rows = readyQuotes();
    if (!rows.length) { qtNotify('目前篩選範圍內沒有「料號ID與製程都已補齊」的報價單。', 'warn'); return; }
    const items = rows.reduce(function(a, r){ return a + Number(r.item_count); }, 0);
    if (!confirm('將把目前篩選範圍內已補齊的 ' + rows.length + ' 張報價單（共 ' + items + ' 筆項目）轉入正式報價單。\n\n轉入後這些單就不會再出現在本頁，確定要執行嗎？')) return;
    const $btn = $(this).prop('disabled', true).html('<i class="fa fa-spinner fa-spin"></i> 轉入中…');
    $.post(API_URL, { action: 'quick_confirm_transfer', quote_ids: JSON.stringify(rows.map(function(r){ return Number(r.quote_id); })) }, function(res) {
        $btn.prop('disabled', false).html('<i class="fa fa-check-square-o"></i> 一鍵轉入已補齊 <span id="qtReadyCnt">(0)</span>');
        if (!res.success) { qtNotify('轉入失敗：' + res.message, 'err'); return; }
        showQtToast('已轉入 ' + res.updated + ' 張報價單');
        qtItemsCache = {}; qtProcState = {};
        const keepPage = qtPage;
        loadPendingList(function(){ qtPage = keepPage; renderCards(); });
    });
});

// 點外部關閉搜尋結果下拉
$(document).on('click', function(e) {
    if (!$(e.target).closest('.qt-search-box').length) $('.qt-search-results').hide();
});

loadProcessTagTree(function() { loadPendingList(); });
</script>
</body>
</html>
