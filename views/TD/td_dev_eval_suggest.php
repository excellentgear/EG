<?php
/**
 * 產品開發評估表(2-TD-02-01) — 建議建立料號清單（2026-08-12 使用者明確要求）
 * 邏輯說明見 src/common/td_dev_eval_suggest_lib.php；權限與角色沿用 td_dev_eval 模組（同一套角色設定，
 * 不另建角色）。從 views/TD/td_dev_eval.php 工具列「建議建立料號清單」按鈕進入，僅管理員(canAdmin)可見。
 */
session_start();
if (!isset($_SESSION['userName'])) {
    $_SESSION['lastpage'] = "../../views/TD/td_dev_eval_suggest.php";
    header("Location:../../index.php");
    exit;
}
include_once '../../src/common/_config.php';
include_once '../../src/common/DBConnection.php';
include_once '../../src/common/td_dev_eval_lib.php';
include_once '../../src/common/td_dev_eval_suggest_lib.php';

$db = (new DBConnection())->getPDO();
td_dev_eval_ensure_schema($db);
td_dev_eval_suggest_ensure_schema($db);
$teUser = td_dev_eval_current_user($db);
$perms = td_dev_eval_perms($db, $teUser);
?>
<!DOCTYPE html>
<html lang="zh-Hant">
<head>
    <meta charset="utf-8">
    <meta http-equiv="X-UA-Compatible" content="IE=edge">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>建議建立料號清單－產品開發評估表</title>
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
        .help-doc ul { margin:4px 0 8px; padding-left:20px; }
        .sg-toolbar { display:flex; flex-wrap:wrap; gap:6px; align-items:center; clear:both;
            border:1.5px solid #E8D5B5; border-radius:8px; padding:8px 10px; margin-bottom:10px; background:#FDF8EF; }
        .sg-toolbar label { margin:0 0 0 8px; font-size:13px; color:#5b3a1e; }
        .sg-toolbar label:first-child { margin-left:0; }
        .sg-toolbar input[type=date], .sg-toolbar button {
            height:30px; font-size:13px; line-height:1; padding:0 10px; border:1px solid #D8BE93;
            border-radius:4px; background:#fff; color:#5b3a1e; cursor:pointer; }
        .sg-toolbar button:hover { background:#F7E0BD; }
        .sg-toolbar .btn-warm { background:#F0A24B; color:#fff; border-color:#d98a33; }
        .sg-toolbar .btn-warm:hover { background:#d98a33; }
        .sg-hint { color:#8a6d45; font-size:12px; margin-bottom:8px; }
        .sg-table-wrap { overflow-x:auto; border:1px solid #E8D5B5; border-radius:6px; background:#fff; }
        table.sg-table { width:100%; border-collapse:collapse; font-size:12.5px; white-space:nowrap; }
        table.sg-table th, table.sg-table td { border:1px solid #EADFC8; padding:5px 7px; text-align:center; }
        table.sg-table thead th { background:#F7E0BD; color:#5b3a1e; font-weight:bold; }
        table.sg-table tbody tr:nth-child(even) { background:#FBF6EC; }
        table.sg-table tbody tr:hover { background:#FBF0DD; }
        table.sg-table td.t-left { text-align:left; white-space:normal; }
        table.sg-table input[type=date] { width:130px; height:26px; font-size:12px; border:1px solid #D8BE93; border-radius:3px; }
        table.sg-table input[type=date].no-date { border-color:#DD5138; background:#FFF3EE; }
        .sg-src-badge { display:inline-block; font-size:10px; border-radius:8px; padding:0 6px; margin:1px; color:#fff; }
        .sg-src-order { background:#F0A24B; } .sg-src-ship { background:#8A5A2B; }
        .sg-src-bom { background:#b5762a; } .sg-src-report { background:#DD5138; }
        .sg-op { color:#b5762a; cursor:pointer; margin:0 4px; }
        .sg-op:hover { color:#8A5A2B; text-decoration:underline; }
        .sg-quick-btn { font-size:10px; padding:1px 5px; border:1px solid #D8BE93; border-radius:3px; background:#fff; cursor:pointer; margin:1px 0; display:block; }
        .sg-quick-btn:hover { background:#F7E0BD; }
        .sg-quick-btn:disabled { opacity:.4; cursor:not-allowed; }
        .sg-mask { display:none; position:fixed; inset:0; background:rgba(60,40,20,.45); z-index:1050; }
        .sg-modal { background:#fff; border-radius:8px; max-width:560px; margin:36px auto; box-shadow:0 5px 25px rgba(0,0,0,.3);
            max-height:90vh; display:flex; flex-direction:column; }
        .sg-modal.xwide { max-width:640px; }
        .sg-modal .m-head { background:#F7E0BD; color:#5b3a1e; font-weight:bold; padding:10px 15px; border-radius:8px 8px 0 0;
            display:flex; justify-content:space-between; }
        .sg-modal .m-head .m-close { cursor:pointer; color:#b5762a; }
        .sg-modal .m-body { padding:15px; overflow-y:auto; }
        .sg-modal .m-foot { padding:10px 15px; border-top:1px solid #EADFC8; text-align:right; }
        .sg-modal .m-foot button { height:30px; padding:0 16px; border-radius:4px; font-size:13px; border:1px solid #d98a33; cursor:pointer; }
        .sg-modal .m-foot .b-ok { background:#F0A24B; color:#fff; }
        .sg-modal .m-foot .b-cancel { background:#fff; color:#5b3a1e; border-color:#D8BE93; margin-right:6px; }
        .sg-cust-list { max-height:300px; overflow-y:auto; border:1px solid #EADFC8; border-radius:6px; padding:8px; }
        .sg-cust-row { display:flex; align-items:center; gap:6px; font-size:13px; padding:4px 6px; border-radius:4px; cursor:pointer; }
        .sg-cust-row:hover { background:#F7E0BD; }
        .sg-cust-row .sg-cust-id { color:#8a6d45; font-size:11px; }
        .sg-cust-selected { display:flex; flex-wrap:wrap; gap:6px; min-height:30px; border:1px dashed #D8BE93; border-radius:6px;
            padding:6px; margin-bottom:8px; background:#FDF8EF; }
        .sg-cust-chip { display:inline-flex; align-items:center; gap:5px; background:#F0A24B; color:#fff; border-radius:12px;
            padding:3px 6px 3px 10px; font-size:12px; white-space:nowrap; }
        .sg-cust-chip i { cursor:pointer; opacity:.85; }
        .sg-cust-chip i:hover { opacity:1; }
        .sg-cust-empty-hint { color:#8a6d45; font-size:12px; }
        .sg-hist-row { border-bottom:1px dashed #EADFC8; padding:5px 0; font-size:13px; }
        .sg-noperm { margin:40px auto; max-width:520px; text-align:center; border:1.5px solid #E8D5B5; border-radius:10px;
            padding:30px; background:#FDF8EF; color:#5b3a1e; }
    </style>
</head>
<body class="nav-sm">
<div class="container body">
<div class="main_container">
    <?php include '../partPage/sideAndTopBarMenu.html' ?>
    <div class="right_col" role="main">
        <div class="page-title" style="display:flex;align-items:center;flex-wrap:wrap;">
            <h2 style="margin:6px 0;">建議建立料號清單
                <small style="color:#8a6d45;">產品開發評估表 2-TD-02-01 前置作業</small></h2>
            <a href="td_dev_eval.php" class="page-help-btn" style="margin-left:auto;background:#fff;color:#5b3a1e;"><i class="fa fa-arrow-left"></i> 返回評估表清單</a>
            <button id="btnPageHelp" class="page-help-btn"><i class="fa fa-question-circle"></i> 使用說明</button>
        </div>
        <div class="clearfix"></div>

<?php if (!$perms['canAdmin']): ?>
        <div class="sg-noperm">
            <h4><i class="fa fa-lock"></i> 無此頁權限</h4>
            <p>需具備「產品開發評估表管理員」角色才能使用建議建立料號清單。請洽管理者於「使用者權限設定」指派。</p>
        </div>
<?php else: ?>
        <div class="sg-toolbar">
            <label>區間</label>
            <input type="date" id="dateFrom">
            <span>～</span>
            <input type="date" id="dateTo">
            <button class="btn-warm" id="btnQuery"><i class="fa fa-search"></i> 查詢</button>
            <button id="btnCustSetting"><i class="fa fa-users"></i> 客戶名單設定</button>
            <button id="btnIgnoreList"><i class="fa fa-eye-slash"></i> 已忽略清單</button>
            <button class="btn-warm" id="btnBulkCreate" style="margin-left:auto;"><i class="fa fa-magic"></i> 批次建立已選項目（<span id="selCount">0</span>）</button>
        </div>
        <div class="sg-hint" id="listHint">載入中…</div>

        <div class="sg-table-wrap">
            <table class="sg-table" id="sgTable">
                <thead><tr>
                    <th><input type="checkbox" id="chkAll"></th>
                    <th>客戶</th><th>料號</th><th>來源</th><th>BOM編號</th><th>BOM日期</th><th>最早報工日期</th>
                    <th>訂單日期</th><th>建立日期（=訂單日，無訂單者手動決定）</th><th>操作</th>
                </tr></thead>
                <tbody id="sgBody"><tr><td colspan="9" style="padding:20px;color:#8a6d45;">請先查詢</td></tr></tbody>
            </table>
        </div>
<?php endif; ?>
    </div>
</div>
</div>

<!-- 客戶名單設定 -->
<div class="sg-mask" id="custMask"><div class="sg-modal">
    <div class="m-head"><span>客戶名單設定</span><span class="m-close" onclick="closeMask('custMask')">✕</span></div>
    <div class="m-body">
        <div class="sg-hint">要納入建議建立清單掃描範圍的客戶（僅在名單內的客戶，其料號才會被列出）。已選客戶列在下方，點右側 ✕ 可移除。</div>
        <div class="sg-cust-selected" id="custSelectedBox"></div>
        <input type="text" id="custFilter" placeholder="輸入客戶名稱或客戶編號篩選…" style="width:100%;margin-bottom:8px;height:30px;border:1px solid #D8BE93;border-radius:4px;padding:0 8px;">
        <div class="sg-cust-list" id="custListBox"></div>
    </div>
    <div class="m-foot">
        <button class="b-cancel" onclick="closeMask('custMask')">取消</button>
        <button class="b-ok" onclick="saveCustSetting()">儲存</button>
    </div>
</div></div>

<!-- 已忽略清單 -->
<div class="sg-mask" id="ignoreMask"><div class="sg-modal xwide">
    <div class="m-head"><span>已忽略清單</span><span class="m-close" onclick="closeMask('ignoreMask')">✕</span></div>
    <div class="m-body" id="ignoreListBox"></div>
    <div class="m-foot"><button class="b-cancel" onclick="closeMask('ignoreMask')">關閉</button></div>
</div></div>

<!-- 相關記錄查看 -->
<div class="sg-mask" id="histMask"><div class="sg-modal xwide">
    <div class="m-head"><span id="histTitle">相關記錄</span><span class="m-close" onclick="closeMask('histMask')">✕</span></div>
    <div class="m-body" id="histBody"></div>
    <div class="m-foot"><button class="b-cancel" onclick="closeMask('histMask')">關閉</button></div>
</div></div>

<div class="sg-mask" id="helpUseMask"><div class="sg-modal xwide">
    <div class="m-head"><span><i class="fa fa-question-circle"></i> 建議建立料號清單 使用說明</span><span class="m-close" onclick="closeMask('helpUseMask')">✕</span></div>
    <div class="m-body help-doc">
        <h4>功能說明</h4>
        <p>依管理員設定的客戶名單，掃描指定區間內曾有「訂單、報工、BOM、出貨」任一記錄的料號，列為建議建立「產品開發評估表」的候選清單，避免漏掉該做評估卻忘記建立的料號。已經建立過評估表的客戶+料號組合、以及使用者手動「忽略」過的項目，都不會再出現。</p>
        <h4>操作步驟</h4>
        <ul>
            <li>先按「客戶名單設定」，輸入客戶名稱或客戶編號篩選後點選要加入的客戶（可複選，已選客戶會列在上方，點 ✕ 可移除），存檔後只掃這份名單內的客戶。</li>
            <li>設定查詢區間（預設近一年），按「查詢」。</li>
            <li>清單依客戶、料號排序；點「相關記錄」可看該料號在區間內的訂單/出貨/BOM/報工/報價記錄明細。</li>
            <li>「建立日期」欄：有解析到訂單日期者自動帶入（可手動改）；查無訂單日期者欄位會標紅，需先手動輸入，或用旁邊「套用BOM日期」「套用最早報工日期」按鈕快速套用參考日期，否則無法勾選建立。</li>
            <li>勾選要建立的項目（可用表頭全選），按右上角「批次建立已選項目」，即一次建立多筆評估表草稿（僅建立表頭殼，32項確認結果與簽核仍需照正常流程逐一進行）。</li>
            <li>不需要建立的項目可按「忽略」，之後就不會再出現；「已忽略清單」可查看並取消忽略。</li>
        </ul>
        <h4>重要行為／常見疑問</h4>
        <ul>
            <li>BOM 沒有客戶編號欄位，只能用客戶名稱文字比對，若 BOM 上的客戶名稱與客戶主檔不完全一致，該筆 BOM 記錄可能無法被歸類，此為資料來源限制。</li>
            <li>「BOM日期」「最早報工日期」為參考用途，不受查詢區間限制（越早越有參考價值），只是幫助決定填表日期用，不會寫入評估表其他欄位。</li>
        </ul>
        <h4>設定入口</h4>
        <p>客戶名單設定：本頁工具列「客戶名單設定」按鈕。</p>
        <h4>權限角色</h4>
        <p>需「產品開發評估表管理員」角色（與 <a href="td_dev_eval.php" target="_blank">產品開發評估表</a> 同一套角色設定，於「使用者權限設定」指派）。</p>
    </div>
    <div class="m-foot"><button class="b-ok" onclick="closeMask('helpUseMask')">我知道了</button></div>
</div></div>

<script src="../../resource/js/jquery.min.js"></script>
<script src="../../resource/js/bootstrap.min.js"></script>
<script src="../../resource/js/fastclick.js"></script>
<script src="../../resource/js/nprogress.js"></script>
<script src="../../resource/js/custom.min.js"></script>
<script src="../../resource/js/eg_date_fmt.js?v=<?= @filemtime(__DIR__.'/../../resource/js/eg_date_fmt.js') ?>"></script>
<script src="../../resource/js/eg_part_picker.js?v=<?= @filemtime(__DIR__.'/../../resource/js/eg_part_picker.js') ?>"></script>
<script src="../../resource/js/eg_input_rules.js?v=<?= @filemtime(__DIR__.'/../../resource/js/eg_input_rules.js') ?>"></script>
<script>
$(document).ready(function(){ $('#sidebar-menu').css('visibility','visible'); });
var API = '../../src/store/TdDevEvalSuggest_API.php';
var VIEWER_URL = '../pm/bom_viewer.php';
var ROWS = [];
function esc(s){ return String(s==null?'':s).replace(/[&<>"']/g,function(c){return {'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#39;'}[c];}); }
function closeMask(id){ document.getElementById(id).style.display='none'; }
function openMask(id){ document.getElementById(id).style.display='block'; }
function fmtDate(s){ return (window.egFmtDate ? egFmtDate(s) : (s||'')); }

var today = new Date().toISOString().substring(0,10);
var oneYearAgo = new Date(); oneYearAgo.setFullYear(oneYearAgo.getFullYear()-1);
$('#dateFrom').val(oneYearAgo.toISOString().substring(0,10));
$('#dateTo').val(today);

function srcBadges(r){
    var out = '';
    if (r.has_order) out += '<span class="sg-src-badge sg-src-order">訂單</span>';
    if (r.has_ship) out += '<span class="sg-src-badge sg-src-ship">出貨</span>';
    if (r.has_bom) out += '<span class="sg-src-badge sg-src-bom">BOM</span>';
    if (r.has_report) out += '<span class="sg-src-badge sg-src-report">報工</span>';
    return out;
}
function rowHtml(r, idx){
    var hasOrderDate = !!r.earliest_order_date;
    var dateVal = hasOrderDate ? r.earliest_order_date : '';
    var partCell = r.part_no_text ? EGPartPicker.viewerLink(r.part_no_text, VIEWER_URL) : '(無料號)';
    return '<tr data-idx="'+idx+'">'
        + '<td><input type="checkbox" class="sg-chk" '+(hasOrderDate?'':'disabled')+'></td>'
        + '<td>'+esc(r.customer_name)+'</td>'
        + '<td class="t-left">'+partCell+'</td>'
        + '<td>'+srcBadges(r)+'</td>'
        + '<td>'+esc(r.bom_no||'')+'</td>'
        + '<td>'+(r.bom_created_at?fmtDate(r.bom_created_at.substring(0,10)):'')+'</td>'
        + '<td>'+(r.earliest_report_date?fmtDate(r.earliest_report_date):'')+'</td>'
        + '<td>'+(hasOrderDate?fmtDate(r.earliest_order_date):'<span style="color:#DD5138;">無</span>')
            + (r.earliest_order_date_all_time ? '<br><span style="font-size:10px;color:#b5762a;" title="區間外查到更早的訂單，僅供參考">⚠更早訂單:'+fmtDate(r.earliest_order_date_all_time)+'</span>' : '')
            + '</td>'
        + '<td>'
            + '<input type="date" class="sg-filldate'+(hasOrderDate?'':' no-date')+'" value="'+esc(dateVal)+'" '+(hasOrderDate?'readonly':'')+'>'
            + (!hasOrderDate ? (
                '<button type="button" class="sg-quick-btn" onclick="applyQuick('+idx+',\'bom\')" '+(r.bom_created_at?'':'disabled')+'>套用BOM日期</button>'
                + '<button type="button" class="sg-quick-btn" onclick="applyQuick('+idx+',\'report\')" '+(r.earliest_report_date?'':'disabled')+'>套用最早報工日期</button>'
                + '<button type="button" class="sg-quick-btn" onclick="applyQuick('+idx+',\'order_all\')" '+(r.earliest_order_date_all_time?'':'disabled')+'>套用更早訂單日期</button>'
              ) : '')
        + '</td>'
        + '<td>'
            + '<span class="sg-op" onclick="openHistory('+idx+')"><i class="fa fa-list-alt"></i> 相關記錄</span>'
            + '<span class="sg-op" onclick="ignoreRow('+idx+')"><i class="fa fa-eye-slash"></i> 忽略</span>'
        + '</td></tr>';
}
window.applyQuick = function(idx, type){
    var r = ROWS[idx];
    var v = type === 'bom' ? (r.bom_created_at||'').substring(0,10)
        : (type === 'order_all' ? r.earliest_order_date_all_time : r.earliest_report_date);
    if (!v) return;
    var $tr = $('#sgBody tr[data-idx="'+idx+'"]');
    $tr.find('.sg-filldate').val(v).removeClass('no-date').prop('readonly', false);
    $tr.find('.sg-chk').prop('disabled', false);
    updateSelCount();
};
$(document).on('change', '.sg-filldate', function(){
    var $tr = $(this).closest('tr');
    var ok = !!$(this).val();
    $tr.find('.sg-chk').prop('disabled', !ok);
    if (!ok) $tr.find('.sg-chk').prop('checked', false);
    updateSelCount();
});
function updateSelCount(){ $('#selCount').text($('.sg-chk:checked').length); }
$(document).on('change', '.sg-chk', updateSelCount);
$('#chkAll').on('change', function(){
    var on = $(this).prop('checked');
    $('.sg-chk:not(:disabled)').prop('checked', on);
    updateSelCount();
});

function loadList(){
    $('#listHint').text('載入中…');
    $.getJSON(API, {action:'list', date_from:$('#dateFrom').val(), date_to:$('#dateTo').val()}, function(res){
        if (!res.success){ $('#sgBody').html('<tr><td colspan="9" style="padding:20px;color:#DD5138;">'+esc(res.message||'載入失敗')+'</td></tr>'); return; }
        if (res.no_customer_configured){
            $('#listHint').text('尚未設定客戶名單，請先按「客戶名單設定」選擇要掃描的客戶。');
            $('#sgBody').html('<tr><td colspan="9" style="padding:20px;color:#8a6d45;">尚未設定客戶名單</td></tr>');
            return;
        }
        ROWS = res.rows || [];
        $('#listHint').text('共 '+ROWS.length+' 筆候選料號（依客戶、料號排序）');
        if (!ROWS.length){ $('#sgBody').html('<tr><td colspan="9" style="padding:20px;color:#8a6d45;">此區間內查無候選料號</td></tr>'); return; }
        var html = ''; ROWS.forEach(function(r, idx){ html += rowHtml(r, idx); });
        $('#sgBody').html(html);
        $('#chkAll').prop('checked', false);
        updateSelCount();
    });
}
$('#btnQuery').on('click', loadList);

/* ---------- 客戶名單設定 ---------- */
var ALL_CUSTOMERS = [];
var SELECTED_CUST = []; // [{customer_id, customer}]，多選結果，上方以按鈕(chip)列出可點X移除
$('#btnCustSetting').on('click', function(){
    $.getJSON(API, {action:'get_customer_setting'}, function(res){
        if (!res.success) return;
        ALL_CUSTOMERS = res.all_customers || [];
        var selectedIds = res.selected_ids || [];
        SELECTED_CUST = ALL_CUSTOMERS.filter(function(c){ return selectedIds.indexOf(c.customer_id) >= 0; });
        $('#custFilter').val('');
        renderCustChips();
        renderCustPickList('');
        openMask('custMask');
    });
});
function renderCustChips(){
    var html = SELECTED_CUST.map(function(c){
        return '<span class="sg-cust-chip">'+esc(c.customer)+' <i class="fa fa-times" onclick="removeCustChip(\''+esc(c.customer_id)+'\')"></i></span>';
    }).join('');
    $('#custSelectedBox').html(html || '<span class="sg-cust-empty-hint">尚未選擇任何客戶</span>');
}
/** 篩選欄同時比對客戶名稱與客戶編號（使用者明確要求可用客戶編號篩選），已選客戶不重複出現在待選清單 */
function renderCustPickList(filterKw){
    var kw = (filterKw||'').trim().toUpperCase();
    var selectedIds = SELECTED_CUST.map(function(c){ return c.customer_id; });
    var html = '';
    ALL_CUSTOMERS.forEach(function(c){
        if (selectedIds.indexOf(c.customer_id) >= 0) return;
        if (kw && c.customer.toUpperCase().indexOf(kw) < 0 && String(c.customer_id).toUpperCase().indexOf(kw) < 0) return;
        html += '<div class="sg-cust-row" onclick="addCustChip(\''+esc(c.customer_id)+'\')"><i class="fa fa-plus-circle" style="color:#8A5A2B;"></i> '
            + esc(c.customer) + ' <span class="sg-cust-id">('+esc(c.customer_id)+')</span></div>';
    });
    $('#custListBox').html(html || '<div style="color:#8a6d45;padding:6px;">查無符合的客戶</div>');
}
window.addCustChip = function(id){
    if (SELECTED_CUST.some(function(c){ return c.customer_id === id; })) return;
    var c = ALL_CUSTOMERS.find(function(x){ return x.customer_id === id; });
    if (!c) return;
    SELECTED_CUST.push(c);
    renderCustChips();
    renderCustPickList($('#custFilter').val());
};
window.removeCustChip = function(id){
    SELECTED_CUST = SELECTED_CUST.filter(function(c){ return c.customer_id !== id; });
    renderCustChips();
    renderCustPickList($('#custFilter').val());
};
$('#custFilter').on('input', function(){ renderCustPickList($(this).val()); });
function saveCustSetting(){
    var ids = SELECTED_CUST.map(function(c){ return c.customer_id; });
    $.ajax({url:API+'?action=save_customer_setting', method:'POST', contentType:'application/json',
        data: JSON.stringify({customer_ids: ids}), dataType:'json',
        success: function(res){
            if (!res.success){ alert(res.message||'儲存失敗'); return; }
            closeMask('custMask'); loadList();
        }});
}

/* ---------- 忽略 ---------- */
window.ignoreRow = function(idx){
    var r = ROWS[idx];
    if (!confirm('確定忽略「'+r.customer_name+' / '+(r.part_no_text||'無料號')+'」？之後不會再出現在候選清單，可於「已忽略清單」取消。')) return;
    $.post(API, {action:'ignore', customer_key:r.customer_id, part_key:r.part_key, customer_name:r.customer_name, part_no_text:r.part_no_text}, function(res){
        if (!res.success){ alert(res.message||'操作失敗'); return; }
        loadList();
    }, 'json');
};
$('#btnIgnoreList').on('click', function(){
    $.getJSON(API, {action:'ignore_list'}, function(res){
        if (!res.success) return;
        var html = '';
        (res.rows||[]).forEach(function(r){
            html += '<div class="sg-hist-row">'+esc(r.customer_name)+' / '+esc(r.part_no_text||'無料號')
                + ' <span style="color:#8a6d45;">（'+esc(r.ignored_by_name||'')+' '+fmtDate((r.ignored_at||'').substring(0,10))+'）</span>'
                + ' <span class="sg-op" onclick="unignoreRow('+r.id+')"><i class="fa fa-undo"></i> 取消忽略</span></div>';
        });
        $('#ignoreListBox').html(html || '<div style="color:#8a6d45;padding:10px;">目前沒有已忽略的項目。</div>');
        openMask('ignoreMask');
    });
});
window.unignoreRow = function(id){
    $.post(API, {action:'ignore_remove', id:id}, function(res){
        if (!res.success){ alert(res.message||'操作失敗'); return; }
        $('#btnIgnoreList').click();
        loadList();
    }, 'json');
};

/* ---------- 相關記錄 ---------- */
window.openHistory = function(idx){
    var r = ROWS[idx];
    $('#histTitle').text(r.customer_name+' / '+(r.part_no_text||'無料號')+' 相關記錄');
    $.getJSON(API, {action:'history', customer_name:r.customer_name, part_d_id:r.part_d_id||0, part_no_text:r.part_no_text||'',
        date_from:$('#dateFrom').val(), date_to:$('#dateTo').val()}, function(res){
        if (!res.success){ $('#histBody').html('<div style="color:#DD5138;">'+esc(res.message||'載入失敗')+'</div>'); openMask('histMask'); return; }
        var html = '';
        (res.rows||[]).forEach(function(h){
            html += '<div class="sg-hist-row"><b>['+esc(h.type)+']</b> '+esc(h.label||'')+' － '+fmtDate(h.date)+(h.note?' － '+esc(h.note):'')+'</div>';
        });
        $('#histBody').html(html || '<div style="color:#8a6d45;padding:10px;">此區間內查無相關記錄。</div>');
        openMask('histMask');
    });
};

/* ---------- 批次建立 ---------- */
$('#btnBulkCreate').on('click', function(){
    var rows = [];
    $('#sgBody tr').each(function(){
        var $chk = $(this).find('.sg-chk');
        if (!$chk.prop('checked')) return;
        var idx = $(this).data('idx');
        var r = ROWS[idx];
        var fillDate = $(this).find('.sg-filldate').val();
        if (!fillDate) return;
        rows.push({customer_name:r.customer_name, part_d_id:r.part_d_id||0, part_no_text:r.part_no_text||'', product_name:r.product_name||'', fill_date:fillDate});
    });
    if (!rows.length){ alert('請至少勾選一筆（未決定建立日期的項目無法勾選）'); return; }
    if (!confirm('確定要一次建立 '+rows.length+' 筆產品開發評估表草稿嗎？建立後仍需照正常流程逐一填寫32項確認結果並送出簽核。')) return;
    $.ajax({url:API+'?action=bulk_create', method:'POST', contentType:'application/json',
        data: JSON.stringify({rows: rows}), dataType:'json',
        success: function(res){
            if (!res.success){ alert(res.message||'建立失敗'); return; }
            var msg = '已建立 '+res.created+' 筆。';
            if (res.errors && res.errors.length) msg += '\n以下項目失敗：\n'+res.errors.join('\n');
            alert(msg);
            loadList();
        }});
});

$('#btnPageHelp').on('click', function(){ openMask('helpUseMask'); });
$('.sg-mask').on('click', function(e){ if (e.target === this) this.style.display='none'; });

<?php if ($perms['canAdmin']): ?>
loadList();
<?php endif; ?>
</script>
</body>
</html>
