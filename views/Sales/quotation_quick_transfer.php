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

        .qt-wrap { background:#fff; border:1px solid #EADFC8; border-radius:8px; padding:14px; }
        .qt-stats { display:flex; gap:10px; margin-bottom:10px; flex-wrap:wrap; }
        .qt-stat-chip { background:#FFF7E8; border:1px solid #F0A24B; border-radius:6px; padding:6px 12px; font-size:12px; color:#6B471A; }
        .qt-stat-chip b { font-size:15px; }
        table.qt-table { width:100%; border-collapse:collapse; font-size:13px; }
        table.qt-table th { background:#F7E0BD; color:#5b3a1e; padding:6px 8px; text-align:left; white-space:nowrap; border-bottom:1px solid #E4C293; }
        table.qt-table td { padding:6px 8px; border-bottom:1px solid #f0e6d6; vertical-align:top; }
        table.qt-table tr.qt-row:hover { background:#FFFBF3; }
        .qt-badge { display:inline-block; font-size:11px; padding:1px 7px; border-radius:10px; margin-right:3px; }
        .qt-badge.ok   { background:#E4F3E4; color:#2e7d32; }
        .qt-badge.warn { background:#FDEBD3; color:#a2703a; }
        .qt-expand-btn { cursor:pointer; color:#b5762f; }
        tr.qt-item-row td { background:#FBF6EC; }
        table.qt-item-table { width:100%; border-collapse:collapse; font-size:12px; }
        table.qt-item-table th { text-align:left; color:#8a5a2b; padding:3px 6px; border-bottom:1px solid #E4C293; }
        table.qt-item-table td { padding:4px 6px; border-bottom:1px dashed #e9dcc4; }
        .qt-proc-tag { display:inline-block; background:#E4C293; color:#4E2C0B; font-size:11px; padding:1px 6px; border-radius:8px; margin:1px; cursor:pointer; }
        .qt-proc-tag.off { background:#f0f0f0; color:#999; }
        .qt-search-box { position:relative; }
        .qt-search-results { position:absolute; z-index:20; background:#fff; border:1px solid #E4C293; border-radius:4px;
            max-height:220px; overflow-y:auto; width:280px; box-shadow:0 3px 12px rgba(0,0,0,.15); display:none; }
        .qt-search-results div { padding:5px 8px; font-size:12px; cursor:pointer; border-bottom:1px solid #f4ecd9; }
        .qt-search-results div:hover { background:#FFF7E8; }
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

            <div class="qt-wrap">
                <div class="qt-stats" id="qtStats"></div>
                <div style="margin-bottom:10px;">
                    <button class="btn btn-warning btn-sm" id="btnBatchConfirm" <?= $canEdit ? '' : 'disabled' ?>>
                        <i class="fa fa-check"></i> 批次轉入正式報價單
                    </button>
                    <span id="qtSelCount" style="font-size:12px;color:#888;margin-left:8px;"></span>
                    <?php if (!$canEdit): ?>
                        <span style="font-size:12px;color:#c0392b;margin-left:8px;">您沒有編輯權限，僅供檢視</span>
                    <?php endif; ?>
                </div>
                <div style="overflow-x:auto;">
                    <table class="qt-table">
                        <thead>
                        <tr>
                            <th><input type="checkbox" id="qtCheckAll"></th>
                            <th></th>
                            <th>報價單號</th>
                            <th>日期</th>
                            <th>客戶</th>
                            <th>項目數</th>
                            <th>完成度</th>
                        </tr>
                        </thead>
                        <tbody id="qtTbody"></tbody>
                    </table>
                </div>
                <div class="qt-pagination" id="qtPagination"></div>
            </div>
            <?php include '../partPage/footer.html' ?>
        </div>
    </div>
</div>

<!-- 使用說明 -->
<div class="va-mask" id="helpUseMask"><div class="va-modal wide">
    <div class="m-head"><span><i class="fa fa-question-circle"></i> 報價單快速轉移 使用說明</span><span class="m-close" onclick="closeMask('helpUseMask')">✕</span></div>
    <div class="m-body help-doc">
        <h4>功能說明</h4>
        <p>用於補建舊報價單資料（例如 ERP 直接匯入的歷史報價單）。這類資料匯入時只有料號/數量/單價，<b>沒有製程分類、沒有綁定正式的料號ID(d_setting)</b>，客戶代碼也可能因年代久遠而與現行代碼不同。本頁讓您逐張快速補齊這些資訊，確認後再一次轉入正式報價單清單。</p>
        <h4>操作步驟</h4>
        <ul>
            <li>清單只列出「尚待確認」的報價單，確認轉入後就會從本頁消失（不會刪除，只是不再顯示於此）。</li>
            <li>點列表最左邊的 <b>▶</b> 展開該張報價單的料號明細。</li>
            <li><b>綁定料號ID</b>：在明細列的搜尋框輸入料號關鍵字，點選正確的項目即可綁定（只影響這一筆報價項目，不影響系統其他資料）。</li>
            <li><b>設定製程</b>：點選要套用的製程標籤（可複選），點一下即存檔。</li>
            <li><b>切換客戶</b>：點客戶欄位旁的「切換」，搜尋並選擇正確的客戶（只改這張報價單的客戶欄位，不影響系統其他歷史資料）。</li>
            <li>逐張補齊後，勾選左側核取框，點上方「批次轉入正式報價單」，該幾張就會從本頁移除、並在報價單管理頁（quotation_list_NEW.php）正常顯示。</li>
        </ul>
        <div class="tip">即使料號ID或製程還沒補齊也可以轉入正式，完成度只是提示、不會強制擋下轉入。</div>
        <h4>重要行為</h4>
        <ul>
            <li>本頁的修改只作用在「尚待確認」的報價單，一旦轉入正式，請回報價單管理頁編輯（本頁會拒絕再次修改已正式的資料）。</li>
            <li>綁定料號ID／設定製程／切換客戶都是<b>單張報價單/單筆項目</b>的修正，不會像料號管理頁的「移轉綁定」一樣影響全系統其他歷史資料。</li>
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
let qtProcesses = [];
let qtPage = 1;
const QT_PAGE_SIZE = 20;
let qtExpanded = {};        // quote_id => true 表示展開
let qtItemsCache = {};      // quote_id => items[]
let custSwitchQuoteId = null;

function loadProcesses(cb) {
    $.get(API_URL, { action: 'get_processes' }, function(res) {
        if (res.success) qtProcesses = res.data;
        if (cb) cb();
    });
}

function loadPendingList() {
    $('#qtTbody').html('<tr><td colspan="7" style="text-align:center;color:#999;padding:20px;"><i class="fa fa-spinner fa-spin"></i> 載入中…</td></tr>');
    $.get(API_URL, { action: 'get_pending_transfer_list' }, function(res) {
        if (!res.success) { $('#qtTbody').html('<tr><td colspan="7">載入失敗：' + (res.message||'') + '</td></tr>'); return; }
        qtData = res.data;
        qtPage = 1;
        renderStats();
        renderTable();
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

function renderTable() {
    if (!qtData.length) {
        $('#qtTbody').html('<tr><td colspan="7" style="text-align:center;color:#999;padding:20px;">目前沒有尚待確認的報價單</td></tr>');
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
        const expanded = !!qtExpanded[r.quote_id];
        html += '<tr class="qt-row" data-qid="' + r.quote_id + '">' +
            '<td><input type="checkbox" class="qt-row-chk" value="' + r.quote_id + '"></td>' +
            '<td><span class="qt-expand-btn" onclick="toggleExpand(' + r.quote_id + ')"><i class="fa fa-caret-' + (expanded?'down':'right') + '"></i></span></td>' +
            '<td>' + r.quote_no + '</td>' +
            '<td>' + fmtDate(r.quote_date) + '</td>' +
            '<td>' + (r.client_name || '<em style="color:#c0392b">未設定</em>') + (r.client_id ? ' <small style="color:#aaa">(' + r.client_id + ')</small>' : '') +
                ' ' + (CAN_EDIT ? '<a href="javascript:void(0)" style="font-size:11px;" onclick="openCustSwitch(' + r.quote_id + ',\'' + r.quote_no + '\',\'' + (r.client_name||'').replace(/'/g,"") + '\')">切換</a>' : '') +
            '</td>' +
            '<td>' + cnt + '</td>' +
            '<td>' + badge + '</td>' +
            '</tr>';
        html += '<tr class="qt-item-row" id="qtItemRow' + r.quote_id + '" style="' + (expanded?'':'display:none;') + '"><td colspan="7"><div id="qtItemBody' + r.quote_id + '">載入中…</div></td></tr>';
    });
    $('#qtTbody').html(html);

    let pg = '';
    pg += '<button class="btn btn-default btn-xs" ' + (qtPage<=1?'disabled':'') + ' onclick="qtGoPage(' + (qtPage-1) + ')">上一頁</button>';
    pg += ' 第 ' + qtPage + ' / ' + totalPages + ' 頁 ';
    pg += '<button class="btn btn-default btn-xs" ' + (qtPage>=totalPages?'disabled':'') + ' onclick="qtGoPage(' + (qtPage+1) + ')">下一頁</button>';
    $('#qtPagination').html(pg);

    Object.keys(qtExpanded).forEach(function(qid) { if (qtExpanded[qid]) renderItemBody(qid); });
    updateSelCount();
}

function qtGoPage(p) { qtPage = p; renderTable(); }

function toggleExpand(qid) {
    qtExpanded[qid] = !qtExpanded[qid];
    renderTable();
}

function renderItemBody(qid) {
    const $body = $('#qtItemBody' + qid);
    if (!$body.length) return;
    if (qtItemsCache[qid]) { drawItems(qid, qtItemsCache[qid]); return; }
    $body.html('<i class="fa fa-spinner fa-spin"></i> 載入項目中…');
    $.get(API_URL, { action: 'get_detail', quote_id: qid }, function(res) {
        if (!res.success) { $body.html('載入失敗：' + (res.message||'')); return; }
        qtItemsCache[qid] = res.data.items || [];
        drawItems(qid, qtItemsCache[qid]);
    });
}

function drawItems(qid, items) {
    let html = '<table class="qt-item-table"><thead><tr><th>料號</th><th>規格</th><th>數量</th><th>單價</th><th style="width:220px;">料號ID綁定</th><th style="width:260px;">製程</th></tr></thead><tbody>';
    items.forEach(function(it) {
        const boundText = it.d_setting_d_id ? ('<span class="qt-badge ok">已綁定 #' + it.d_setting_d_id + '</span>') : '<span class="qt-badge warn">未綁定</span>';
        const curProcNos = (it.processes || '').split(',').filter(function(v){return v!=='';}).map(Number);
        let procTags = '';
        qtProcesses.forEach(function(p) {
            const on = curProcNos.indexOf(Number(p.ProcessNo)) !== -1;
            procTags += '<span class="qt-proc-tag' + (on?'':' off') + '" data-item="' + it.item_id + '" data-proc="' + p.ProcessNo + '" onclick="toggleProcTag(this)">' + p.ProcessName + '</span>';
        });
        html += '<tr>' +
            '<td>' + it.product_id + '</td>' +
            '<td>' + (it.specification || '') + '</td>' +
            '<td>' + it.quantity + '</td>' +
            '<td>' + it.unit_price + '</td>' +
            '<td>' + boundText + (CAN_EDIT ? (' <div class="qt-search-box" style="margin-top:3px;"><input type="text" class="form-control input-sm" placeholder="搜尋料號ID…" data-item="' + it.item_id + '" onkeyup="partSearchKeyup(this)" autocomplete="off"><div class="qt-search-results"></div></div>') : '') + '</td>' +
            '<td>' + (CAN_EDIT ? procTags : boundText) + '</td>' +
            '</tr>';
    });
    html += '</tbody></table>';
    $('#qtItemBody' + qid).html(html);
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
            if (!res.success || !res.data.length) { $results.html('<div style="color:#999;">查無結果</div>').show(); return; }
            let h = '';
            res.data.forEach(function(p) {
                h += '<div onclick="bindPart(' + $input.data('item') + ',' + p.d_id + ',\'' + p.D_Setting_Id.replace(/'/g,"") + '\',this)">' +
                    p.D_Setting_Id + (p.Client_Name ? '　<small style="color:#aaa">' + p.Client_Name + '</small>' : '') + '</div>';
            });
            $results.html(h).show();
        });
    }, 300);
}

function bindPart(itemId, dId, dSettingId, el) {
    $.post(API_URL, { action: 'quick_bind_item_dsetting', item_id: itemId, d_id: dId }, function(res) {
        if (!res.success) { alert('綁定失敗：' + res.message); return; }
        $(el).closest('.qt-search-results').hide();
        // 更新快取並重繪該項目所屬報價單的明細（找到 item 所在 quote）
        Object.keys(qtItemsCache).forEach(function(qid) {
            qtItemsCache[qid].forEach(function(it) { if (String(it.item_id) === String(itemId)) { it.d_setting_d_id = dId; it.product_id = dSettingId; } });
        });
        loadPendingList();
    });
}

function toggleProcTag(el) {
    const $el = $(el);
    const itemId = $el.data('item');
    $el.toggleClass('off');
    const $row = $el.closest('td');
    const pnos = [];
    $row.find('.qt-proc-tag').not('.off').each(function() { pnos.push($(this).data('proc')); });
    $.post(API_URL, { action: 'quick_set_item_process', item_id: itemId, process_nos: pnos.join(',') }, function(res) {
        if (!res.success) { alert('設定製程失敗：' + res.message); return; }
        Object.keys(qtItemsCache).forEach(function(qid) {
            qtItemsCache[qid].forEach(function(it) { if (String(it.item_id) === String(itemId)) it.processes = pnos.join(','); });
        });
        loadPendingList();
    });
}

function openCustSwitch(quoteId, quoteNo, curName) {
    custSwitchQuoteId = quoteId;
    $('#custSwitchQuoteNo').text(quoteNo);
    $('#custSwitchCurrent').text(curName || '（未設定）');
    $('#custSwitchKw').val('');
    $('#custSwitchResults').hide().empty();
    openMask('custSwitchMask');
}

let custSearchTimer = null;
$('#custSwitchKw').on('keyup', function() {
    const kw = $(this).val().trim();
    clearTimeout(custSearchTimer);
    if (kw.length < 1) { $('#custSwitchResults').hide(); return; }
    custSearchTimer = setTimeout(function() {
        $.get(API_URL, { action: 'search_data', type: 'customer', term: kw }, function(res) {
            const $r = $('#custSwitchResults');
            if (!res.success || !res.data.length) { $r.html('<div style="color:#999;">查無結果</div>').show(); return; }
            let h = '';
            res.data.forEach(function(c) {
                h += '<div onclick="switchCustomer(\'' + c.customer_id + '\',\'' + c.customer.replace(/'/g,"") + '\')">' + c.customer + '　<small style="color:#aaa">' + c.customer_id + '</small></div>';
            });
            $r.html(h).show();
        });
    }, 300);
});

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

$('#btnBatchConfirm').on('click', function() {
    const ids = $('.qt-row-chk:checked').map(function(){ return Number($(this).val()); }).get();
    if (!ids.length) { alert('請先勾選要轉入正式報價單的項目'); return; }
    if (!confirm('確定要將這 ' + ids.length + ' 張報價單轉入正式報價單清單嗎？轉入後將從本頁移除。')) return;
    $.post(API_URL, { action: 'quick_confirm_transfer', quote_ids: JSON.stringify(ids) }, function(res) {
        if (!res.success) { alert('轉入失敗：' + res.message); return; }
        alert('已轉入 ' + res.updated + ' 張報價單');
        loadPendingList();
    });
});

// 點外部關閉搜尋結果下拉
$(document).on('click', function(e) {
    if (!$(e.target).closest('.qt-search-box').length) $('.qt-search-results').hide();
});

loadProcesses(function() { loadPendingList(); });
</script>
</body>
</html>
