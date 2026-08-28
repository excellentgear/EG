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
            <div style="margin-bottom:10px;">
                <button class="btn btn-warning btn-sm" id="btnBatchConfirm" <?= $canEdit ? '' : 'disabled' ?>>
                    <i class="fa fa-check"></i> 批次轉入正式報價單
                </button>
                <label style="font-size:12px;margin-left:10px;"><input type="checkbox" id="qtCheckAll"> 全選本頁</label>
                <span id="qtSelCount" style="font-size:12px;color:#888;margin-left:8px;"></span>
                <label style="font-size:12px;margin-left:14px;">年份：<select id="qtYearFilter" class="form-control input-sm" style="display:inline-block;width:90px;"><option value="">全部</option></select></label>
                <?php if (!$canEdit): ?>
                    <span style="font-size:12px;color:#c0392b;margin-left:8px;">您沒有編輯權限，僅供檢視</span>
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
            <li><b>設定製程</b>：跟報價單管理頁一樣的製程標籤（先選大類再選子標籤，可複選），點一下即存檔。</li>
            <li><b>綁定料號ID</b>：在「料號ID綁定」欄搜尋料號關鍵字，點選正確的項目即可綁定；<b>找不到就直接在搜尋結果下方按「＋新增料號」</b>快速建立並自動綁定。</li>
            <li><b>切換客戶</b>：點客戶欄位旁的「切換」，搜尋並選擇正確的客戶；找不到一樣可以「＋新建客戶」；跳窗內按 <b>Enter</b> 等同直接送出（唯一符合的搜尋結果或已填妥的新建表單）。</li>
            <li>補齊後，可以用每張報價單右上角的「轉正式報價單」單張轉入，或勾選多張後用上方「批次轉入正式報價單」一次轉入。<b>料號ID沒有全部綁定完的報價單，按鈕與勾選框會是反灰的</b>，滑鼠移上去會說明還缺幾筆。</li>
            <li>清單右上角可篩選<b>年份</b>，<b>預設顯示最新年份</b>（可自行切到其他年份或「全部」）；報價單依日期新到舊排序。</li>
            <li>上方統計列的「<b>最新資料日期</b>」是把目前尚待確認的所有報價單<b>由 OP 單號本身解析</b>出來的日期（OP＋民國年3碼＋月日4碼，例：OP1071228004 → 2018.12.28）取最新的一天，可用來看舊資料補到哪一天；括號內是該日期取自哪一張單號。此欄不受年份篩選影響，一律以全部尚待確認的報價單計算。</li>
        </ul>
        <div class="tip"><b>料號ID未完全綁定的報價單不可轉入正式報價單</b>——該張報價單每一筆項目都綁好料號ID之後，「轉正式報價單」按鈕與勾選框會自動解鎖（不必重新整理頁面），「全選本頁」也只會勾到可以轉入的那幾張。<b>製程</b>不擋，沒設定一樣可以轉入，完成度只是提示。</div>
        <h4>加快補件速度</h4>
        <ul>
            <li><b>製程「複製上一筆」</b>：一鍵複製同報價單中前一項的製程設定。</li>
            <li><b>製程「套用到本單全部」</b>：把目前這筆的製程設定一次套用到同報價單其餘所有項目（會覆蓋原設定，套用前會再次確認）。</li>
            <li><b>只找到一筆完全相同的料號時直接綁定</b>：按「快速綁定」後，如果搜尋結果只有一筆、而且料號名稱與這一列的料號文字完全一樣，系統直接綁上、不再開跳窗要您按一次確認。唯一的例外是「本客戶底下找不到、退而求其次全範圍找到，而且那一筆已經綁在別的客戶底下」——那會照常開跳窗讓您確認，因為跨客戶綁定會把別家的圖面與檢驗標準一起接過來。</li>
            <li><b>整張單一鍵建立並綁定料號</b>：料號ID還沒綁完的報價單，卡片右上角有「一鍵建立並綁定料號」。按下去會把這張單<b>所有</b>未綁定的項目一次處理完：依每一筆的料號文字先找既有料號（先找本單客戶的，再找沒有綁客戶的），<b>都找不到才以本單客戶新建一筆</b>再綁上——跟跳窗裡「找不到？新增此料號（綁此客戶）」同一條規則。同一張單裡重複出現的料號只會建立一筆；做完會列出新建了哪些、沿用了哪些。<b>這張單必須先設定客戶</b>（新建的料號要綁到客戶），還沒設定會擋下並提示先用「切換」設定。</li>
            <li><b>綁定料號後自動偵測同料號</b>：綁定或新增料號時，系統會找出「尚待確認」報價單中同料號文字、同客戶、還沒綁定的其他項目（常見於同一張報價單內同料號不同數量級距），跳窗列出讓您勾選是否一併綁定，不用逐筆重複搜尋。</li>
        </ul>
        <h4>重要行為</h4>
        <ul>
            <li>本頁的修改只作用在「尚待確認」的報價單，一旦轉入正式，請回報價單管理頁編輯（本頁會拒絕再次修改已正式的資料）。</li>
            <li><b>為什麼料號ID一定要綁完才能轉正</b>：料號ID(d_setting)是全系統判定「這筆報價屬於哪個料號」的唯一依據，沒綁定的話出貨統計、歷史單價、毛利分析都認不到這張報價單；而且轉正之後這張單就不再出現在本頁，也無法再從這裡補綁，等於永久漏掉。因此這是<b>強制擋下</b>，不是提示——後端同樣會擋，不能繞過畫面直接送出。</li>
            <li>綁定料號ID／設定製程／切換客戶都是<b>單張報價單/單筆項目</b>的修正，不會像料號管理頁的「移轉綁定」一樣影響全系統其他歷史資料。</li>
            <li>轉入正式時：若這張報價單本身沒有真實填表人資訊（ERP匯入本來就沒有這項資料），系統會自動標記為「業務公用」帳號製表；<b>核准欄位刻意留空不自動核准</b>——系統無法確認幾年前當時真正的業務主管是誰，與其虛構一筆假的核准紀錄，不如留白讓有需要的人自行判斷；也因此<b>不會</b>發送「待核准」通知給現在的主管。</li>
        </ul>
        <h4>權限</h4>
        <p>沿用報價單管理頁權限（module: quotation_list），需要 U（修改）或 A（管理）權限才能編輯，僅檢閱者唯讀。</p>
    </div>
    <div class="m-foot"><button class="btn btn-warning" onclick="closeMask('helpUseMask')">我知道了</button></div>
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
let qtItemsCache = {};      // quote_id => items[]
let qtProcState  = {};      // item_id => { activeGid, selected:[sub_tag_id,...] }
let custSwitchQuoteId = null;

function loadProcessTagTree(cb) {
    $.get(API_URL, { action: 'get_process_tag_tree' }, function(res) {
        if (res.success) processTagTree = res.tree || [];
        if (cb) cb();
    });
}

function loadPendingList() {
    $('#qtCards').html('<div style="text-align:center;color:#999;padding:20px;"><i class="fa fa-spinner fa-spin"></i> 載入中…</div>');
    $.get(API_URL, { action: 'get_pending_transfer_list' }, function(res) {
        if (!res.success) { $('#qtCards').html('載入失敗：' + (res.message||'')); return; }
        qtData = res.data;
        qtPage = 1;
        populateYearFilter();
        renderStats();
        renderCards();
    });
}
$('#qtYearFilter').on('change', function(){ qtPage = 1; renderCards(); });

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
    const years = Array.from(new Set(qtData.map(r => yearOf(r.quote_date)).filter(Boolean))).sort().reverse();
    const $sel = $('#qtYearFilter');
    const cur = $sel.val();
    $sel.html('<option value="">全部</option>' + years.map(y => '<option value="' + y + '">' + y + '</option>').join(''));
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
    if (!y) return qtData;
    return qtData.filter(r => yearOf(r.quote_date) === y);
}

// 料號ID未完全綁定的報價單不可轉入正式報價單（後端 quick_confirm_transfer 會以同一規則再擋一次＝鐵律8）。
// 製程沒設定不擋，只是完成度提示。
function transferGate(noDs, cnt) {
    noDs = Number(noDs) || 0;
    if (noDs > 0) return { ok:false, reason:'料號ID尚未完全綁定（缺 ' + noDs + '/' + (Number(cnt)||0) + ' 筆），補齊後才能轉入正式報價單' };
    return { ok:true, reason:'' };
}

// 綁定料號後即時解鎖／再度鎖上該張卡片的轉入入口，不必重新整理整頁
function applyTransferGate(qid, noDs, cnt) {
    const gate = transferGate(noDs, cnt);
    const $chk = $('#qtChk' + qid), $btn = $('#qtBtnTx' + qid);
    if (!gate.ok) $chk.prop('checked', false);
    $chk.prop('disabled', !gate.ok).attr('title', gate.ok ? null : gate.reason);
    $btn.prop('disabled', !gate.ok).attr('title', gate.ok ? null : gate.reason);
    $('#qtBtnAuto' + qid).toggle(!gate.ok);
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

    let html = '';
    pageRows.forEach(function(r) {
        const noDs = Number(r.items_no_dsetting), noPc = Number(r.items_no_process), cnt = Number(r.item_count);
        let badge = '';
        badge += (noDs === 0) ? '<span class="qt-badge ok">料號ID已綁定</span>' : '<span class="qt-badge warn">料號ID缺 ' + noDs + '/' + cnt + '</span>';
        badge += (noPc === 0) ? '<span class="qt-badge ok">製程已設定</span>' : '<span class="qt-badge warn">製程缺 ' + noPc + '/' + cnt + '</span>';
        const gate = transferGate(noDs, cnt);
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
    pg += '<button class="btn btn-default btn-xs" ' + (qtPage<=1?'disabled':'') + ' onclick="qtGoPage(' + (qtPage-1) + ')">上一頁</button>';
    pg += ' 第 ' + qtPage + ' / ' + totalPages + ' 頁（共 ' + filtered.length + ' 張） ';
    pg += '<button class="btn btn-default btn-xs" ' + (qtPage>=totalPages?'disabled':'') + ' onclick="qtGoPage(' + (qtPage+1) + ')">下一頁</button>';
    $('#qtPagination').html(pg);

    pageRows.forEach(function(r) { renderItemBody(r.quote_id); });
    updateSelCount();
}

function qtGoPage(p) { qtPage = p; renderCards(); }

// 整張報價單一鍵建立並綁定料號：後端逐筆先找既有料號（本單客戶→無客戶），都找不到才以本單客戶新建，
// 與跳窗裡「找不到？新增此料號（綁此客戶）」同一條規則，只是一次做完整張單。
function autoBindQuote(quoteId, quoteNo) {
    const row = qtData.find(function(r){ return String(r.quote_id) === String(quoteId); });
    const noDs = row ? Number(row.items_no_dsetting) : 0;
    if (!noDs) { alert('報價單 ' + quoteNo + ' 的料號ID已經全部綁定完成。'); return; }
    if (!row.client_id) { alert('報價單 ' + quoteNo + ' 還沒有設定客戶，無法自動建立料號（新建的料號要綁到客戶）。\n請先按客戶欄旁的「切換」設定客戶後再試。'); return; }
    if (!confirm('報價單 ' + quoteNo + ' 有 ' + noDs + ' 筆項目還沒綁定料號ID。\n\n將依每一筆的料號文字自動處理：\n・已經有這個料號 → 直接綁上\n・還沒有 → 以本單客戶「' + (row.client_name || '') + '」新建料號後綁上\n\n確定要執行嗎？')) return;
    const $b = $('#qtBtnAuto' + quoteId).prop('disabled', true);
    $.post(API_URL, { action: 'quick_autobind_quote', quote_id: quoteId }, function(res) {
        $b.prop('disabled', false);
        if (!res.success) { alert('自動建立並綁定失敗：' + res.message); return; }
        let msg = '報價單 ' + quoteNo + ' 已綁定 ' + res.bound + ' 筆';
        if (res.created) msg += '\n新建料號 ' + res.created + ' 筆：' + res.created_nos.join('、');
        if (res.reused)  msg += '\n沿用既有料號 ' + res.reused + ' 筆：' + res.reused_nos.join('、');
        if (res.skipped) msg += '\n略過 ' + res.skipped + ' 筆（料號欄是空的，無法建立）';
        alert(msg);
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

function drawItems(qid, items) {
    const row = qtData.find(function(r){ return String(r.quote_id) === String(qid); });
    let html = '<table class="qt-item-table"><thead><tr><th>料號</th><th>規格</th><th>數量</th><th>單價</th><th style="width:170px;">料號ID綁定</th><th style="min-width:260px;">製程</th></tr></thead><tbody>';
    items.forEach(function(it, idx) {
        const boundText = it.d_setting_d_id ? ('<span class="qt-badge ok">已綁定 #' + it.d_setting_d_id + '</span>') : '<span class="qt-badge warn">未綁定</span>';
        const procIds = (it.processes || '').split(',').filter(function(v){return v!=='';});
        if (!qtProcState[it.item_id]) {
            const selected = inferSubTagsFromProcessIds(procIds);
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

function renderProcWidget(itemId, prevItemId, totalInQuote) {
    const state = qtProcState[itemId];

    let toolbar = '';
    if (prevItemId || (totalInQuote && totalInQuote > 1)) {
        toolbar = '<div style="font-size:10px;margin-bottom:2px;">';
        if (prevItemId) toolbar += '<a href="javascript:void(0)" onclick="copyProcessFromItem(' + itemId + ',' + prevItemId + ')"><i class="fa fa-clone"></i> 複製上一筆</a>';
        if (totalInQuote && totalInQuote > 1) toolbar += (prevItemId ? '　' : '') + '<a href="javascript:void(0)" onclick="applyProcessToAllInQuote(' + itemId + ')"><i class="fa fa-copy"></i> 套用到本單全部</a>';
        toolbar += '</div>';
    }

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
    $.post(API_URL, { action: 'quick_set_item_process', item_id: itemId, process_nos: [...procIds].join(','), group_type: groupType }, function(res) {
        if (!res.success) { alert('設定製程失敗：' + res.message); return; }
        Object.keys(qtItemsCache).forEach(function(qid) {
            qtItemsCache[qid].forEach(function(it) { if (String(it.item_id) === String(itemId)) it.processes = [...procIds].join(','); });
        });
        // 只更新完成度統計不必整頁重載
        refreshStatsOnly(itemId);
    });
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
    const noPc = items.filter(function(it){ return !(it.processes && it.processes !== ''); }).length;
    const row = qtData.find(function(r){ return String(r.quote_id) === String(qid); });
    if (row) { row.items_no_dsetting = noDs; row.items_no_process = noPc; }
    renderStats();
    const cnt = items.length;
    const badgeHtml =
        (noDs === 0 ? '<span class="qt-badge ok">料號ID已綁定</span>' : '<span class="qt-badge warn">料號ID缺 ' + noDs + '/' + cnt + '</span>') +
        (noPc === 0 ? '<span class="qt-badge ok">製程已設定</span>' : '<span class="qt-badge warn">製程缺 ' + noPc + '/' + cnt + '</span>');
    $('.qt-card[data-qid="' + qid + '"] .qt-badge-cell').html(badgeHtml);
    applyTransferGate(qid, noDs, cnt);
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
        if (!res.success) { alert('綁定失敗：' + res.message); return; }
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
        if (!res.success) { alert('批次綁定失敗：' + res.message); return; }
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
        if (!res.success) { alert('切換失敗：' + res.message); return; }
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
    $.post(API_URL, { action: 'quick_confirm_transfer', quote_ids: JSON.stringify(ids) }, function(res) {
        if (!res.success) { alert('轉入失敗：' + res.message); return; }
        alert(doneMsg || ('已轉入 ' + res.updated + ' 張報價單'));
        loadPendingList();
    });
}

function confirmTransferOne(quoteId, quoteNo) {
    const row = qtData.find(function(r){ return String(r.quote_id) === String(quoteId); });
    const gate = transferGate(row ? row.items_no_dsetting : 0, row ? row.item_count : 0);
    if (!gate.ok) { alert('報價單 ' + quoteNo + ' 無法轉入正式報價單：\n' + gate.reason); return; }
    if (!confirm('確定要將報價單 ' + quoteNo + ' 轉入正式報價單嗎？轉入後將從本頁移除。')) return;
    doConfirmTransfer([quoteId]);
}

$('#btnBatchConfirm').on('click', function() {
    const ids = $('.qt-row-chk:checked').map(function(){ return Number($(this).val()); }).get();
    if (!ids.length) { alert('請先勾選要轉入正式報價單的項目'); return; }
    const blocked = [];
    ids.forEach(function(id) {
        const row = qtData.find(function(r){ return Number(r.quote_id) === Number(id); });
        if (row && !transferGate(row.items_no_dsetting, row.item_count).ok) blocked.push(row.quote_no + '（缺 ' + (Number(row.items_no_dsetting)||0) + ' 筆）');
    });
    if (blocked.length) {
        alert('以下 ' + blocked.length + ' 張報價單的料號ID尚未完全綁定，不可轉入正式報價單：\n' + blocked.join('、') + '\n\n請先補齊料號ID綁定後再轉入。');
        return;
    }
    if (!confirm('確定要將這 ' + ids.length + ' 張報價單轉入正式報價單清單嗎？轉入後將從本頁移除。')) return;
    doConfirmTransfer(ids);
});

// 點外部關閉搜尋結果下拉
$(document).on('click', function(e) {
    if (!$(e.target).closest('.qt-search-box').length) $('.qt-search-results').hide();
});

loadProcessTagTree(function() { loadPendingList(); });
</script>
</body>
</html>
