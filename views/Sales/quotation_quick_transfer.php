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
    </style>
</head>
<body class="nav-sm">
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
            <li><b>切換客戶</b>：點客戶欄位旁的「切換」，搜尋並選擇正確的客戶；找不到一樣可以「＋新建客戶」。</li>
            <li>補齊後，可以用每張報價單右上角的「轉正式報價單」單張轉入，或勾選多張後用上方「批次轉入正式報價單」一次轉入。</li>
        </ul>
        <div class="tip">即使料號ID或製程還沒補齊也可以轉入正式，完成度只是提示、不會強制擋下轉入。</div>
        <h4>重要行為</h4>
        <ul>
            <li>本頁的修改只作用在「尚待確認」的報價單，一旦轉入正式，請回報價單管理頁編輯（本頁會拒絕再次修改已正式的資料）。</li>
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

<script src="../../resource/js/jquery.min.js"></script>
<script src="../../resource/js/bootstrap.min.js"></script>
<script src="../../resource/js/fastclick.js"></script>
<script src="../../resource/js/nprogress.js"></script>
<script src="../../resource/js/custom.min.js"></script>
<script src="../../resource/js/eg_input_rules.js?v=<?= @filemtime(__DIR__.'/../../resource/js/eg_input_rules.js') ?>"></script>
<script>
$(document).ready(function(){ $('#sidebar-menu').css('visibility','visible'); });
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
        renderStats();
        renderCards();
    });
}

function renderStats() {
    const total = qtData.length;
    const ready = qtData.filter(r => Number(r.items_no_dsetting) === 0 && Number(r.items_no_process) === 0).length;
    $('#qtStats').html(
        '<div class="qt-stat-chip">尚待確認 <b>' + total + '</b> 張</div>' +
        '<div class="qt-stat-chip">已補齊(料號ID+製程) <b>' + ready + '</b> 張</div>'
    );
}

function fmtDate(d) { return d || ''; }

function renderCards() {
    if (!qtData.length) {
        $('#qtCards').html('<div style="text-align:center;color:#999;padding:20px;">目前沒有尚待確認的報價單</div>');
        $('#qtPagination').html('');
        return;
    }
    const totalPages = Math.max(1, Math.ceil(qtData.length / QT_PAGE_SIZE));
    if (qtPage > totalPages) qtPage = totalPages;
    const start = (qtPage - 1) * QT_PAGE_SIZE;
    const pageRows = qtData.slice(start, start + QT_PAGE_SIZE);

    let html = '';
    pageRows.forEach(function(r) {
        const noDs = Number(r.items_no_dsetting), noPc = Number(r.items_no_process), cnt = Number(r.item_count);
        let badge = '';
        badge += (noDs === 0) ? '<span class="qt-badge ok">料號ID已綁定</span>' : '<span class="qt-badge warn">料號ID缺 ' + noDs + '/' + cnt + '</span>';
        badge += (noPc === 0) ? '<span class="qt-badge ok">製程已設定</span>' : '<span class="qt-badge warn">製程缺 ' + noPc + '/' + cnt + '</span>';
        html += '<div class="qt-card" data-qid="' + r.quote_id + '">' +
            '<div class="qt-card-head">' +
                '<input type="checkbox" class="qt-row-chk" value="' + r.quote_id + '">' +
                '<span class="qno">' + r.quote_no + '</span>' +
                '<span>' + fmtDate(r.quote_date) + '</span>' +
                '<span>客戶：' + (r.client_name || '<em style="color:#c0392b">未設定</em>') + (r.client_id ? ' <small style="color:#aaa">(' + r.client_id + ')</small>' : '') +
                    (CAN_EDIT ? ' <a href="javascript:void(0)" onclick="openCustSwitch(' + r.quote_id + ',\'' + r.quote_no + '\',\'' + (r.client_name||'').replace(/'/g,"") + '\')">切換</a>' : '') +
                '</span>' +
                '<span>項目數：' + cnt + '</span>' +
                '<span class="qt-badge-cell">' + badge + '</span>' +
                (CAN_EDIT ? '<button class="btn btn-warning btn-xs" style="margin-left:auto;" onclick="confirmTransferOne(' + r.quote_id + ',\'' + r.quote_no + '\')"><i class="fa fa-check"></i> 轉正式報價單</button>' : '<span style="margin-left:auto;"></span>') +
            '</div>' +
            '<div class="qt-card-body" id="qtCardBody' + r.quote_id + '">載入項目中…</div>' +
            '</div>';
    });
    $('#qtCards').html(html);

    let pg = '';
    pg += '<button class="btn btn-default btn-xs" ' + (qtPage<=1?'disabled':'') + ' onclick="qtGoPage(' + (qtPage-1) + ')">上一頁</button>';
    pg += ' 第 ' + qtPage + ' / ' + totalPages + ' 頁（共 ' + qtData.length + ' 張） ';
    pg += '<button class="btn btn-default btn-xs" ' + (qtPage>=totalPages?'disabled':'') + ' onclick="qtGoPage(' + (qtPage+1) + ')">下一頁</button>';
    $('#qtPagination').html(pg);

    pageRows.forEach(function(r) { renderItemBody(r.quote_id); });
    updateSelCount();
}

function qtGoPage(p) { qtPage = p; renderCards(); }

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

function drawItems(qid, items) {
    let html = '<table class="qt-item-table"><thead><tr><th>料號</th><th>規格</th><th>數量</th><th>單價</th><th style="width:230px;">料號ID綁定</th><th style="min-width:260px;">製程</th></tr></thead><tbody>';
    items.forEach(function(it) {
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
        html += '<tr data-item="' + it.item_id + '">' +
            '<td>' + it.product_id + '</td>' +
            '<td>' + (it.specification || '') + '</td>' +
            '<td>' + it.quantity + '</td>' +
            '<td>' + it.unit_price + '</td>' +
            '<td>' + boundText + (CAN_EDIT ? renderPartBindWidget(it) : '') + '</td>' +
            '<td>' + (CAN_EDIT ? renderProcWidget(it.item_id) : '') + '</td>' +
            '</tr>';
    });
    html += '</tbody></table>';
    $('#qtCardBody' + qid).html(html);
}

function renderPartBindWidget(it) {
    return ' <div class="qt-search-box" style="margin-top:3px;">' +
        '<input type="text" class="form-control input-sm" placeholder="搜尋料號ID…" data-item="' + it.item_id + '" onkeyup="partSearchKeyup(this)" autocomplete="off">' +
        '<div class="qt-search-results"></div>' +
        '</div>';
}

function renderProcWidget(itemId) {
    const state = qtProcState[itemId];
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
    return l1 + l2 + chips;
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
    $('tr[data-item="' + itemId + '"] td:last-child').html(renderProcWidget(itemId));
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
}

let partSearchTimer = null;
function partSearchKeyup(input) {
    const $input = $(input);
    const $results = $input.siblings('.qt-search-results');
    const kw = $input.val().trim();
    clearTimeout(partSearchTimer);
    if (kw.length < 1) { $results.hide(); return; }
    partSearchTimer = setTimeout(function() {
        $.get(API_URL, { action: 'search_data', type: 'part', term: kw }, function(res) {
            let h = '';
            if (res.success && res.data.length) {
                res.data.forEach(function(p) {
                    h += '<div class="qt-sr-item" onclick="bindPart(' + $input.data('item') + ',' + p.d_id + ',\'' + p.D_Setting_Id.replace(/'/g,"") + '\',this)">' +
                        p.D_Setting_Id + (p.Client_Name ? '　<small style="color:#aaa">' + p.Client_Name + '</small>' : '') + '</div>';
                });
            } else {
                h += '<div style="padding:5px 8px;color:#999;font-size:12px;">查無結果</div>';
            }
            h += '<div class="qt-sr-new" onclick="openNewPartForm(' + $input.data('item') + ',this)"><i class="fa fa-plus"></i> 新增料號「' + kw.replace(/'/g,"") + '」</div>';
            $results.html(h).show();
        });
    }, 300);
}

function openNewPartForm(itemId, el) {
    const $results = $(el).closest('.qt-search-results');
    const kw = $results.siblings('input').val().trim();
    let qid = null;
    Object.keys(qtItemsCache).forEach(function(k) {
        if (qtItemsCache[k].some(function(it){ return String(it.item_id) === String(itemId); })) qid = k;
    });
    const row = qtData.find(function(r){ return String(r.quote_id) === String(qid); });
    const custId = row ? row.client_id : null;
    $results.html(
        '<div class="qt-quickform" style="position:relative;">' +
        '<input type="text" class="form-control input-sm qt-np-no" placeholder="料號" value="' + kw.replace(/"/g,'') + '">' +
        '<input type="text" class="form-control input-sm qt-np-spec" placeholder="規格描述（選填）">' +
        (custId ? ('<div style="font-size:11px;color:#888;margin-bottom:3px;">關聯客戶：' + (row.client_name || custId) + '</div>')
                : '<div style="font-size:11px;color:#c0392b;margin-bottom:3px;">此報價單尚未設定客戶，新料號將不綁客戶</div>') +
        '<button class="btn btn-success btn-xs" onclick="submitNewPart(' + itemId + ',\'' + (custId||'') + '\',this)"><i class="fa fa-save"></i> 建立並綁定</button>' +
        '<div class="qt-np-err" style="color:#c0392b;font-size:11px;margin-top:3px;"></div>' +
        '</div>'
    ).show();
}

function submitNewPart(itemId, custId, el) {
    const $box = $(el).closest('.qt-quickform');
    const partNo = $box.find('.qt-np-no').val().trim();
    const spec   = $box.find('.qt-np-spec').val().trim();
    if (!partNo) { $box.find('.qt-np-err').text('料號不可為空'); return; }
    $.post(API_URL, { action: 'save_part_info', part_no: partNo, type: 'N', customer_id: custId, remark: spec }, function(res) {
        if (!res.success) { $box.find('.qt-np-err').text(res.message || '建立失敗'); return; }
        bindPart(itemId, res.d_id, partNo, null);
    });
}

function bindPart(itemId, dId, dSettingId, el) {
    $.post(API_URL, { action: 'quick_bind_item_dsetting', item_id: itemId, d_id: dId }, function(res) {
        if (!res.success) { alert('綁定失敗：' + res.message); return; }
        Object.keys(qtItemsCache).forEach(function(qid) {
            qtItemsCache[qid].forEach(function(it) { if (String(it.item_id) === String(itemId)) { it.d_setting_d_id = dId; it.product_id = dSettingId; } });
        });
        if (el) $(el).closest('.qt-search-results').hide();
        // 重繪該項目所在的整張卡片（品項ID已變動）
        let qid = null;
        Object.keys(qtItemsCache).forEach(function(k) {
            if (qtItemsCache[k].some(function(it){ return String(it.item_id) === String(itemId); })) qid = k;
        });
        if (qid) drawItems(qid, qtItemsCache[qid]);
        refreshStatsOnly(itemId);
    });
}

function openCustSwitch(quoteId, quoteNo, curName) {
    custSwitchQuoteId = quoteId;
    $('#custSwitchQuoteNo').text(quoteNo);
    $('#custSwitchCurrent').text(curName || '（未設定）');
    $('#custSwitchKw').val('');
    $('#custSwitchResults').hide().empty();
    $('#custNewForm').hide();
    $('#custNewId, #custNewName').val('');
    $('#custNewErr').text('');
    openMask('custSwitchMask');
}

let custSearchTimer = null;
$('#custSwitchKw').on('keyup', function() {
    const kw = $(this).val().trim();
    clearTimeout(custSearchTimer);
    if (kw.length < 1) { $('#custSwitchResults').hide(); $('#custNewForm').hide(); return; }
    custSearchTimer = setTimeout(function() {
        $.get(API_URL, { action: 'search_data', type: 'customer', term: kw }, function(res) {
            const $r = $('#custSwitchResults');
            if (res.success && res.data.length) {
                let h = '';
                res.data.forEach(function(c) {
                    h += '<div class="qt-sr-item" onclick="switchCustomer(\'' + c.customer_id + '\',\'' + c.customer.replace(/'/g,"") + '\')">' + c.customer + '　<small style="color:#aaa">' + c.customer_id + '</small></div>';
                });
                $r.html(h).show();
                $('#custNewForm').hide();
            } else {
                $r.html('<div style="padding:5px 8px;color:#999;font-size:12px;">查無結果</div>').show();
                $('#custNewName').val(kw);
                $('#custNewForm').show();
            }
        });
    }, 300);
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
    if (this.id === 'qtCheckAll') $('.qt-row-chk').prop('checked', this.checked);
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
    if (!confirm('確定要將報價單 ' + quoteNo + ' 轉入正式報價單嗎？轉入後將從本頁移除。')) return;
    doConfirmTransfer([quoteId]);
}

$('#btnBatchConfirm').on('click', function() {
    const ids = $('.qt-row-chk:checked').map(function(){ return Number($(this).val()); }).get();
    if (!ids.length) { alert('請先勾選要轉入正式報價單的項目'); return; }
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
