<?php
/**
 * 型態識別文件管制表（AS 文件 RTD630EC0A00）
 * 每個料號一份「型態配置」清單：原圖/報價單/加工圖/產品開發評估表/PFMEA/檢驗報告…等定義該料號
 * 目前狀態的文件，逐列記錄型態項目/生效日期/類別/版別文件編號；可連結「外來文件清單」既有附件
 * （即時查詢顯示，不落地快照，來源更新這裡就跟著變——使用者 2026-08-11 明確拍板）。
 * 資料/權限見 src/common/type_id_ctrl_lib.php；資料操作走 src/store/ConfigIdDoc_API.php。
 */
session_start();
if (!isset($_SESSION['userName'])) {
    $_SESSION['lastpage'] = "../../views/TD/type_id_ctrl_doc.php";
    header("Location:../../index.php");
    exit;
}
include_once '../../src/common/_config.php';
include_once '../../src/common/DBConnection.php';
include_once '../../src/common/asdoc_lib.php';
include_once '../../src/common/type_id_ctrl_lib.php';

$db = (new DBConnection())->getPDO();
type_id_ctrl_ensure_schema($db);
$icUser = type_id_ctrl_current_user($db);
$perms = type_id_ctrl_perms($db, $icUser);
if (!$perms['canView']) {
    // 沒有本模組角色，仍放行（AS9100 文件全員可檢閱），但不給編輯操作
}
$roleLabel = $perms['isAdmin'] ? '管理者' : ($perms['canAdmin'] ? '型態文件管理員' : ($perms['canEdit'] ? '型態文件登錄' : ($perms['canView'] ? '型態文件檢閱' : '無權限')));
?>
<!DOCTYPE html>
<html lang="zh-Hant">
<head>
    <meta charset="utf-8">
    <meta http-equiv="X-UA-Compatible" content="IE=edge">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>型態識別文件管制表</title>
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
        .ic-toolbar { display:flex; flex-wrap:wrap; gap:6px; align-items:center; clear:both;
            border:1.5px solid #E8D5B5; border-radius:8px; padding:8px 10px; margin-bottom:10px; background:#FDF8EF; }
        .ic-toolbar label { margin:0; font-size:13px; color:#5b3a1e; }
        .ic-toolbar select, .ic-toolbar input[type=text], .ic-toolbar button {
            height:30px; font-size:13px; line-height:1; padding:0 10px; border:1px solid #D8BE93;
            border-radius:4px; background:#fff; color:#5b3a1e; cursor:pointer; }
        .ic-toolbar button:hover { background:#F7E0BD; }
        .ic-toolbar .btn-warm { background:#F0A24B; color:#fff; border-color:#d98a33; }
        .ic-toolbar .btn-warm:hover { background:#d98a33; }
        .ic-role-badge { margin-left:auto; font-size:13px; color:#5b3a1e; background:#F7E0BD; border-radius:12px; padding:4px 12px; }
        .ic-table-wrap { overflow-x:auto; border:1px solid #E8D5B5; border-radius:6px; background:#fff; }
        table.ic-table { width:100%; border-collapse:collapse; font-size:13px; }
        table.ic-table th, table.ic-table td { border:1px solid #EADFC8; padding:5px 8px; text-align:center; }
        table.ic-table thead th { background:#F7E0BD; color:#5b3a1e; font-weight:bold; }
        table.ic-table tbody tr:nth-child(even) { background:#FBF6EC; }
        table.ic-table tbody tr:hover { background:#FBF0DD; }
        table.ic-table td.t-left { text-align:left; }
        .ic-op { color:#b5762a; cursor:pointer; margin:0 4px; }
        .ic-op:hover { color:#8A5A2B; text-decoration:underline; }
        .ic-part-lnk { color:#b5762a; text-decoration:underline; cursor:pointer; }
        .ic-mask { display:none; position:fixed; inset:0; background:rgba(60,40,20,.45); z-index:1050; }
        .ic-modal { background:#fff; border-radius:8px; max-width:600px; margin:36px auto; box-shadow:0 5px 25px rgba(0,0,0,.3);
            max-height:90vh; display:flex; flex-direction:column; }
        .ic-modal.xwide { max-width:1080px; }
        .ic-modal .m-head { background:#F7E0BD; color:#5b3a1e; font-weight:bold; padding:10px 15px; border-radius:8px 8px 0 0;
            display:flex; justify-content:space-between; }
        .ic-modal .m-head .m-close { cursor:pointer; color:#b5762a; }
        .ic-modal .m-body { padding:15px; overflow-y:auto; }
        .ic-modal .m-body label { display:block; font-size:13px; color:#5b3a1e; margin:9px 0 3px; }
        .ic-modal .m-body input[type=text], .ic-modal .m-body input[type=date], .ic-modal .m-body select {
            width:100%; border:1px solid #D8BE93; border-radius:4px; padding:5px 8px; font-size:13px; box-sizing:border-box; }
        .ic-modal .m-foot { padding:10px 15px; border-top:1px solid #EADFC8; text-align:right; }
        .ic-modal .m-foot button { height:30px; padding:0 16px; border-radius:4px; font-size:13px; border:1px solid #d98a33; cursor:pointer; }
        .ic-modal .m-foot .b-ok { background:#F0A24B; color:#fff; }
        .ic-modal .m-foot .b-cancel { background:#fff; color:#5b3a1e; border-color:#D8BE93; margin-right:6px; }
        .ic-head-grid { display:grid; grid-template-columns:1fr 1fr 1fr; gap:0 14px; }
        .ic-part-box { display:flex; gap:6px; align-items:center; }
        .ic-part-box input[readonly] { background:#F7F2E6; }
        table.ic-item-table { width:100%; border-collapse:collapse; font-size:12px; margin-top:6px; }
        table.ic-item-table th, table.ic-item-table td { border:1px solid #EADFC8; padding:3px 4px; }
        table.ic-item-table thead th { background:#F7E0BD; color:#5b3a1e; }
        table.ic-item-table input[type=text], table.ic-item-table input[type=date], table.ic-item-table select {
            width:100%; box-sizing:border-box; border:1px solid #D8BE93; border-radius:3px; padding:3px 4px; font-size:12px; }
        table.ic-item-table input[disabled] { background:#F7F2E6; color:#5b3a1e; }
        table.ic-item-table td.seq { width:32px; text-align:center; color:#8a6d45; }
        table.ic-item-table td.op { width:100px; white-space:nowrap; text-align:center; }
        .ic-link-badge { font-size:10px; color:#8A5A2B; background:#F7E0BD; border-radius:8px; padding:0 6px; margin-left:2px; white-space:nowrap; }
        .ic-broken-badge { font-size:10px; color:#DD5138; background:#ffe1de; border-radius:8px; padding:0 6px; margin-left:2px; }
        .ic-row-btn { border:1px solid #D8BE93; background:#fff; color:#5b3a1e; border-radius:4px; padding:2px 6px; font-size:11px; cursor:pointer; }
        .ic-row-btn:hover { background:#F7E0BD; }
        .ic-row-btn.del { color:#DD5138; border-color:#f0c4bd; }
        .ic-noperm { margin:40px auto; max-width:520px; text-align:center; border:1.5px solid #E8D5B5; border-radius:10px;
            padding:30px; background:#FDF8EF; color:#5b3a1e; }
        @media print { .ic-toolbar, .nav_menu, .left_col, footer { display:none !important; } .right_col { margin:0 !important; padding:0 !important; } }
    </style>
</head>
<body class="nav-sm">
<div class="container body">
<div class="main_container">
    <?php include '../partPage/sideAndTopBarMenu.html' ?>
    <div class="right_col" role="main">
        <div class="page-title" style="display:flex;align-items:center;flex-wrap:wrap;">
            <h2 style="margin:6px 0;">型態識別文件管制表
                <small style="color:#8a6d45;">RTD630EC0A00 ｜ 每料號一份配置文件清單</small></h2>
            <button id="btnPageHelp" class="page-help-btn" style="margin-left:auto;"><i class="fa fa-question-circle"></i> 使用說明</button>
        </div>
        <div class="clearfix"></div>

<?php if (!$perms['canView']): ?>
        <div class="ic-noperm">
            <h4><i class="fa fa-lock"></i> 無型態識別文件檢閱權限</h4>
            <p>請洽管理者於「使用者權限設定」指派「型態文件檢閱／登錄／管理員」角色。</p>
        </div>
<?php else: ?>
        <div class="ic-toolbar">
            <label>搜尋</label>
            <input type="text" id="kwInput" placeholder="文件編號／料號／客戶" style="width:180px;">
            <button class="btn-warm" id="btnAdd" style="<?= $perms['canEdit']?'':'display:none;' ?>"><i class="fa fa-plus"></i> 新增</button>
            <button id="btnAsDoc" style="<?= $perms['canAdmin']?'':'display:none;' ?>"><i class="fa fa-link"></i> AS文件綁定</button>
            <button id="btnCsv"><i class="fa fa-file-text-o"></i> 匯出CSV</button>
            <span class="ic-role-badge">目前角色：<b><?= htmlspecialchars($roleLabel) ?></b></span>
        </div>

        <div class="ic-table-wrap">
            <table class="ic-table" id="icTable">
                <thead><tr>
                    <th>文件編號</th><th>客戶</th><th>產品編號(料號)</th><th>製程</th>
                    <th>建立人</th><th>建立時間</th><th>操作</th>
                </tr></thead>
                <tbody id="icBody"><tr><td colspan="7" style="padding:20px;color:#8a6d45;">載入中…</td></tr></tbody>
            </table>
        </div>
<?php endif; ?>
    </div>
</div>
</div>

<!-- 新增/編輯 -->
<div class="ic-mask" id="editMask"><div class="ic-modal xwide">
    <div class="m-head"><span id="editTitle">型態識別文件管制表</span><span class="m-close" onclick="closeMask('editMask')">✕</span></div>
    <div class="m-body">
        <div class="ic-head-grid">
            <div>
                <label>產品編號(料號) <span style="color:#DD5138;">*</span></label>
                <div class="ic-part-box">
                    <input type="text" id="fPartNo" readonly placeholder="請點選料號" data-eg-skip="1">
                    <button type="button" class="ic-row-btn" id="btnPickPart">選擇</button>
                </div>
                <input type="hidden" id="fPartDId" value="0">
            </div>
            <div>
                <label>客戶</label>
                <input type="text" id="fCustomerName" readonly data-eg-skip="1">
                <input type="hidden" id="fCustomerId" value="">
            </div>
            <div>
                <label>製程</label>
                <input type="text" id="fProcess" placeholder="例：滾齒至成品">
            </div>
        </div>
        <div style="margin-top:6px;font-size:12px;color:#8a6d45;">文件編號：<b id="fDocNo">存檔後自動產生</b>
            ｜ 建立：<span id="fCreatedInfo">—</span></div>

        <table class="ic-item-table">
            <thead><tr>
                <th style="width:28px;">項次</th>
                <th style="width:16%;">型態項目名稱</th>
                <th style="width:12%;">型態生效日期</th>
                <th style="width:12%;">型態類別</th>
                <th>版別／文件編號</th>
                <th class="op">操作</th>
            </tr></thead>
            <tbody id="itemBody" data-eg-row-add="icAddRow" data-eg-row-del="icDelRow"></tbody>
        </table>
        <div style="margin-top:6px;">
            <button type="button" class="ic-row-btn" onclick="icAddRow()"><i class="fa fa-plus"></i> 新增一列</button>
        </div>
        <div class="tip" style="margin-top:8px;">版別／文件編號可手動輸入，或按「連結」從外來文件清單挑選既有附件——連結後此列會<b>即時顯示外來文件清單目前的檔名與日期</b>（不落地快照，來源異動這裡會跟著變）。</div>
    </div>
    <div class="m-foot">
        <button class="b-cancel" onclick="closeMask('editMask')">取消</button>
        <button class="b-ok" id="btnSave" onclick="saveAll()">儲存</button>
    </div>
</div></div>

<!-- 從外來文件清單選取 -->
<div class="ic-mask" id="extMask" style="z-index:1200;"><div class="ic-modal">
    <div class="m-head"><span>從外來文件清單選取</span><span class="m-close" onclick="closeMask('extMask')">✕</span></div>
    <div class="m-body">
        <div id="extEmpty" style="color:#8a6d45;padding:10px;">載入中…</div>
        <table class="ic-item-table" id="extTable" style="display:none;">
            <thead><tr><th>檔名</th><th style="width:100px;">日期</th><th style="width:60px;">來源</th><th style="width:50px;"></th></tr></thead>
            <tbody id="extBody"></tbody>
        </table>
    </div>
    <div class="m-foot"><button class="b-cancel" onclick="closeMask('extMask')">取消</button></div>
</div></div>

<!-- AS 文件綁定 -->
<div class="ic-mask" id="asDocMask"><div class="ic-modal">
    <div class="m-head"><span>AS 文件編號綁定</span><span class="m-close" onclick="closeMask('asDocMask')">✕</span></div>
    <div class="m-body">
        <div style="margin-bottom:8px;">目前綁定：<b id="asDocLabel">尚未綁定</b></div>
        <button type="button" class="ic-row-btn" onclick="openAsDocPicker()">變更綁定</button>
    </div>
    <div class="m-foot"><button class="b-cancel" onclick="closeMask('asDocMask')">關閉</button></div>
</div></div>

<div class="ic-mask" id="helpUseMask"><div class="ic-modal xwide">
    <div class="m-head"><span><i class="fa fa-question-circle"></i> 型態識別文件管制表 使用說明</span><span class="m-close" onclick="closeMask('helpUseMask')">✕</span></div>
    <div class="m-body help-doc">
        <h4>功能說明</h4>
        <p>每個料號建立一份「型態識別文件管制表」，逐列記錄目前定義該料號狀態的文件（原圖、報價單、加工圖、產品開發評估表、PFMEA、檢驗報告…），用來追溯「這個料號現在的配置由哪些文件定義」。</p>
        <h4>操作步驟</h4>
        <ul>
            <li>按「新增」→ 先選擇「產品編號(料號)」（打部分字元搜尋，選定後自動帶出客戶），可點文件編號旁的料號連結開圖面。</li>
            <li>逐列填「型態項目名稱」（如：原圖、報價單…）、「型態生效日期」、「型態類別」（圖面／治夾具／報告／其他文件）。</li>
            <li>「版別／文件編號」可直接手動輸入；若該文件已在「外來文件清單」（<a href="../Sales/external_doc_list.php" target="_blank">開啟</a>）裡有附件，按「連結」挑選即可自動帶入，之後外來文件清單更新，這裡會跟著即時顯示最新檔名與日期。</li>
            <li>末列填寫後按 ↓ 或「新增一列」可再加一列；空白的最後一列會自動不存檔。</li>
            <li>按「儲存」整批寫入；文件編號（本表自身編號，格式：西元年月日＋3位流水號）存檔後自動產生，不可手動修改。</li>
        </ul>
        <h4>重要行為／常見疑問</h4>
        <ul>
            <li>「連結」的列不能手動改文字——顯示內容一律即時查詢外來文件清單目前狀態，若來源附件被刪除會顯示「來源已消失」，此時可解除連結改手動輸入。</li>
            <li>列印比照全站標準（ai-rules/16）：大標題為本公司名稱、頁尾右下角印本頁綁定的 AS 文件編號。</li>
        </ul>
        <h4>設定入口</h4>
        <p>AS 文件編號綁定：工具列「AS文件綁定」按鈕（僅管理員可見）。</p>
        <h4>權限角色</h4>
        <p>型態文件檢閱／登錄／管理員（管理者固定擁有全部權限）。</p>
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
<script src="../../resource/js/eg_asdoc_picker.js?v=<?= @filemtime(__DIR__.'/../../resource/js/eg_asdoc_picker.js') ?>"></script>
<script src="../../resource/js/eg_input_rules.js?v=<?= @filemtime(__DIR__.'/../../resource/js/eg_input_rules.js') ?>"></script>
<script>
$(document).ready(function(){ $('#sidebar-menu').css('visibility','visible'); });
var API = '../../src/store/ConfigIdDoc_API.php';
var PART_API = '../../src/store/PartPicker_API.php';
var VIEWER_URL = '../pm/part_viewer.php';
var CAN_EDIT = <?= $perms['canEdit'] ? 'true' : 'false' ?>;
var CAN_ADMIN = <?= $perms['canAdmin'] ? 'true' : 'false' ?>;
var TYPE_OPTS = [['drawing','圖面'],['jig','治夾具'],['report','報告'],['other','其他文件']];
var CUR_ID = 0, ITEMS = [], AS_DOCS = [], AS_DOC = null;

function esc(s){ return String(s==null?'':s).replace(/[&<>"']/g,function(c){return {'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#39;'}[c];}); }
function closeMask(id){ document.getElementById(id).style.display='none'; }
function openMask(id){ document.getElementById(id).style.display='block'; }
function fmtDate(s){ return (window.egFmtDate ? egFmtDate(s) : (s||'')); }

/* ---------- 清單 ---------- */
function loadList(){
    $.getJSON(API, {action:'list', kw:$('#kwInput').val()||''}, function(res){
        if (!res.success){ $('#icBody').html('<tr><td colspan="7" style="padding:20px;color:#DD5138;">'+esc(res.message||'載入失敗')+'</td></tr>'); return; }
        if (!res.rows.length){ $('#icBody').html('<tr><td colspan="7" style="padding:20px;color:#8a6d45;">尚無資料</td></tr>'); return; }
        var html = '';
        res.rows.forEach(function(r){
            html += '<tr>'
                + '<td>'+esc(r.doc_no)+'</td>'
                + '<td>'+esc(r.customer_name||r.customer_id||'')+'</td>'
                + '<td class="t-left">'+(r.part_d_id?EGPartPicker.viewerLink(r.part_d_id, VIEWER_URL, r.part_no):esc(r.part_no))+'</td>'
                + '<td>'+esc(r.process_desc||'')+'</td>'
                + '<td>'+esc(r.created_by_name||'')+'</td>'
                + '<td>'+fmtDate((r.created_at||'').substring(0,10))+'</td>'
                + '<td>'
                + '<span class="ic-op" onclick="openEdit('+r.id+')">'+(CAN_EDIT?'編輯':'檢視')+'</span>'
                + '<span class="ic-op" onclick="printDoc('+r.id+')">列印</span>'
                + (CAN_ADMIN ? '<span class="ic-op" onclick="delDoc('+r.id+')">刪除</span>' : '')
                + '</td></tr>';
        });
        $('#icBody').html(html);
    });
}
var kwT=null;
$('#kwInput').on('input', function(){ clearTimeout(kwT); kwT=setTimeout(loadList, 300); });
$('#btnCsv').on('click', function(){
    $.getJSON(API, {action:'list', kw:$('#kwInput').val()||''}, function(res){
        if (!res.success) return;
        var lines = ['文件編號,客戶,產品編號,製程,建立人,建立時間'];
        res.rows.forEach(function(r){
            lines.push([r.doc_no, r.customer_name||r.customer_id||'', r.part_no||'', r.process_desc||'', r.created_by_name||'', (r.created_at||'').substring(0,10)]
                .map(function(v){ return '"'+String(v).replace(/"/g,'""')+'"'; }).join(','));
        });
        var blob = new Blob(["\uFEFF"+lines.join("\n")], {type:'text/csv;charset=utf-8;'});
        var a = document.createElement('a'); a.href = URL.createObjectURL(blob); a.download = '型態識別文件管制表.csv';
        document.body.appendChild(a); a.click(); document.body.removeChild(a);
    });
});

/* ---------- 新增/編輯 ---------- */
function resetEditForm(){
    CUR_ID = 0; ITEMS = [];
    $('#fPartNo').val(''); $('#fPartDId').val('0'); $('#fCustomerName').val(''); $('#fCustomerId').val('');
    $('#fProcess').val(''); $('#fDocNo').text('存檔後自動產生'); $('#fCreatedInfo').text('—');
    $('#itemBody').empty();
}
function openEdit(id){
    resetEditForm();
    $('#editTitle').text(id ? '編輯型態識別文件管制表' : '新增型態識別文件管制表');
    if (!id){ openMask('editMask'); icAddRow(); return; }
    $.getJSON(API, {action:'get', id:id}, function(res){
        if (!res.success){ alert(res.message||'載入失敗'); return; }
        CUR_ID = id;
        $('#fPartNo').val(res.doc.part_no||''); $('#fPartDId').val(res.doc.part_d_id||0);
        $('#fCustomerName').val(res.doc.customer_name||''); $('#fCustomerId').val(res.doc.customer_id||'');
        $('#fProcess').val(res.doc.process_desc||'');
        $('#fDocNo').text(res.doc.doc_no);
        $('#fCreatedInfo').text((res.doc.created_by_name||'')+' '+fmtDate((res.doc.created_at||'').substring(0,10)));
        ITEMS = res.items || [];
        renderItems();
        openMask('editMask');
    });
}
window.btnAddClick = function(){ openEdit(0); };
$('#btnAdd').on('click', function(){ openEdit(0); });

$('#btnPickPart').on('click', function(){
    EGPartPicker.open({
        apiUrl: PART_API, title: '選擇產品編號(料號)',
        onSave: function(row){
            $('#fPartNo').val(row.part_no); $('#fPartDId').val(row.d_id);
            $('#fCustomerName').val(row.customer_name||row.customer_id||''); $('#fCustomerId').val(row.customer_id||'');
        }
    });
});

function itemRowHtml(it, idx){
    var linked = it.is_linked;
    var typeOpts = TYPE_OPTS.map(function(t){ return '<option value="'+t[0]+'"'+(it.item_type===t[0]?' selected':'')+'>'+t[1]+'</option>'; }).join('');
    var linkBadge = linked ? '<span class="ic-link-badge"><i class="fa fa-link"></i> 已連結</span>' : (it.ref_broken ? '<span class="ic-broken-badge">來源已消失</span>' : '');
    var docNoCell = '<input type="text" class="f-docno" value="'+esc(it.doc_no_text||'')+'"'+(linked?' disabled':'')+' placeholder="版別／文件編號">';
    var linkBtn = linked
        ? '<button type="button" class="ic-row-btn" onclick="unlinkRow(this)">解除連結</button>'
        : '<button type="button" class="ic-row-btn" onclick="pickExtDoc(this)"'+ (($('#fPartDId').val()|0) ? '' : ' disabled title="請先選擇料號"') +'>連結</button>';
    return '<tr data-ref-source="'+esc(it.ref_source||'')+'" data-ref-attach-id="'+esc(it.ref_attach_id||'')+'" data-ref-ds-pk="'+esc(it.ref_ds_pk||'')+'" data-id="'+esc(it.id||0)+'">'
        + '<td class="seq">'+(idx+1)+'</td>'
        + '<td><input type="text" class="f-name" value="'+esc(it.item_name||'')+'" placeholder="型態項目名稱"></td>'
        + '<td><input type="date" class="f-date" value="'+esc(it.effective_date||'')+'"'+(linked?' disabled':'')+'></td>'
        + '<td><select class="f-type">'+typeOpts+'</select></td>'
        + '<td>'+docNoCell+' '+linkBadge+(it.file_url?' <a href="'+esc(it.file_url)+'" target="_blank"><i class="fa fa-external-link"></i></a>':'')+'</td>'
        + '<td class="op">'+linkBtn+' <button type="button" class="ic-row-btn del" onclick="$(this).closest(\'tr\').remove(); renumberRows();">刪除</button></td>'
        + '</tr>';
}
function renderItems(){
    var html = '';
    ITEMS.forEach(function(it, idx){ html += itemRowHtml(it, idx); });
    $('#itemBody').html(html);
    if (!ITEMS.length) icAddRow();
}
function renumberRows(){ $('#itemBody tr').each(function(i){ $(this).find('td.seq').text(i+1); }); }
window.icAddRow = function(){
    var blank = {id:0, item_name:'', item_type:'other', effective_date:'', doc_no_text:'', is_linked:false, ref_source:null, ref_attach_id:null, ref_ds_pk:null};
    $('#itemBody').append(itemRowHtml(blank, $('#itemBody tr').length));
    renumberRows();
    return true;
};
window.icDelRow = function(){
    var rows = $('#itemBody tr');
    if (rows.length <= 1) return false;
    rows.last().remove();
    renumberRows();
    return true;
};

function pickExtDoc(btn){
    var dsPk = $('#fPartDId').val();
    if (!dsPk || dsPk === '0'){ alert('請先選擇料號'); return; }
    var $tr = $(btn).closest('tr');
    $('#extEmpty').show().text('載入中…'); $('#extTable').hide();
    openMask('extMask');
    $.post(API, {action:'search_ext_doc', ds_pk: dsPk}, function(res){
        if (!res.success || !res.rows.length){ $('#extEmpty').show().text('外來文件清單中查無此料號的附件'); $('#extTable').hide(); return; }
        $('#extEmpty').hide(); $('#extTable').show();
        var html = '';
        res.rows.forEach(function(r, i){
            html += '<tr><td class="t-left">'+esc(r.doc_name)+'</td><td>'+fmtDate(r.doc_date)+'</td><td>'+(r.source==='part'?'料號附件':'報價附件')+'</td>'
                + '<td><button type="button" class="ic-row-btn" onclick="applyExtDoc('+i+')">選取</button></td></tr>';
        });
        $('#extBody').html(html);
        window._extRows = res.rows; window._extTarget = $tr;
    }, 'json');
}
window.applyExtDoc = function(i){
    var r = window._extRows[i], $tr = window._extTarget;
    $tr.attr('data-ref-source', r.source).attr('data-ref-attach-id', r.attach_id).attr('data-ref-ds-pk', r.ds_pk);
    var idx = $tr.index();
    ITEMS[idx] = collectRow($tr);
    ITEMS[idx].is_linked = true; ITEMS[idx].ref_source = r.source; ITEMS[idx].ref_attach_id = r.attach_id; ITEMS[idx].ref_ds_pk = r.ds_pk;
    ITEMS[idx].doc_no_text = r.doc_name; ITEMS[idx].effective_date = r.doc_date;
    $tr.replaceWith(itemRowHtml(ITEMS[idx], idx));
    closeMask('extMask');
};
window.unlinkRow = function(btn){
    var $tr = $(btn).closest('tr');
    var idx = $tr.index();
    var it = collectRow($tr);
    it.is_linked = false; it.ref_source = null; it.ref_attach_id = null; it.ref_ds_pk = null; it.doc_no_text = ''; it.effective_date = '';
    ITEMS[idx] = it;
    $tr.replaceWith(itemRowHtml(it, idx));
};

function collectRow($tr){
    return {
        id: parseInt($tr.attr('data-id'),10) || 0,
        item_name: $tr.find('.f-name').val(),
        item_type: $tr.find('.f-type').val(),
        effective_date: $tr.find('.f-date').val(),
        doc_no_text: $tr.find('.f-docno').val(),
        is_linked: !!$tr.attr('data-ref-source'),
        ref_source: $tr.attr('data-ref-source') || null,
        ref_attach_id: $tr.attr('data-ref-attach-id') || null,
        ref_ds_pk: $tr.attr('data-ref-ds-pk') || null,
    };
}

function saveAll(){
    var partDId = $('#fPartDId').val();
    if (!partDId || partDId === '0'){ alert('請先選擇產品編號(料號)'); return; }
    var items = [];
    $('#itemBody tr').each(function(){
        var it = collectRow($(this));
        var payload = {
            id: it.id, item_name: it.item_name, item_type: it.item_type,
            ref_source: it.is_linked ? it.ref_source : '',
            ref_attach_id: it.is_linked ? it.ref_attach_id : 0,
            ref_ds_pk: it.is_linked ? it.ref_ds_pk : 0,
            manual_effective_date: it.is_linked ? '' : it.effective_date,
            manual_doc_no: it.is_linked ? '' : it.doc_no_text,
        };
        items.push(payload);
    });
    $.post(API, {
        action: 'save_all', id: CUR_ID, customer_id: $('#fCustomerId').val(), part_d_id: partDId,
        process_desc: $('#fProcess').val(), items: JSON.stringify(items)
    }, function(res){
        if (!res.success){ alert(res.message||'儲存失敗'); return; }
        closeMask('editMask'); loadList();
    }, 'json');
}

function delDoc(id){
    if (!confirm('確定刪除此筆型態識別文件管制表？')) return;
    $.post(API, {action:'delete_header', id:id}, function(res){
        if (!res.success){ alert(res.message||'刪除失敗'); return; }
        loadList();
    }, 'json');
}

/* ---------- 列印（ai-rules/16：大標題本公司名／頁尾右下AS編號／頁碼左下） ---------- */
function printDoc(id){
    $.getJSON(API, {action:'print_get', id:id}, function(res){
        if (!res.success){ alert(res.message||'載入失敗'); return; }
        var d = res.doc;
        var typeLabel = {drawing:'圖面', jig:'治夾具', report:'報告', other:'其他文件'};
        var body = '<div class="p-comp">'+esc(res.company_name)+'</div>'
            + '<div class="p-title">'+esc(res.as_doc_name)+'</div>'
            + '<table class="p-hd"><tr><td>客戶</td><td>'+esc(d.customer_name||'')+'</td><td>製程</td><td>'+esc(d.process_desc||'')+'</td></tr>'
            + '<tr><td>產品編號</td><td>'+esc(d.part_no||'')+'</td><td>建立日期</td><td>'+fmtDate((d.created_at||'').substring(0,10))+'</td></tr></table>'
            + '<table class="p-tb"><thead><tr><th style="width:26px;">項次</th><th>型態項目名稱</th><th style="width:90px;">型態生效日期</th><th style="width:70px;">型態類別</th><th>版別／文件編號</th></tr></thead><tbody>';
        (res.items||[]).forEach(function(it){
            body += '<tr><td>'+it.seq+'</td><td class="tl">'+esc(it.item_name)+'</td><td>'+fmtDate(it.effective_date)+'</td><td>'+(typeLabel[it.item_type]||'')+'</td><td class="tl">'+esc(it.doc_no_text||'')+'</td></tr>';
        });
        body += '</tbody></table>';
        var css = 'body{font-family:"Microsoft JhengHei",sans-serif;margin:0;padding:0 6mm;color:#222;-webkit-print-color-adjust:exact;print-color-adjust:exact;}'
            + '.p-comp{font-size:22px;font-weight:bold;text-align:center;margin-bottom:1px;}'
            + '.p-title{font-size:17px;font-weight:bold;text-align:center;letter-spacing:4px;margin-bottom:10px;}'
            + 'table.p-hd{width:100%;border-collapse:collapse;font-size:12px;margin-bottom:8px;}'
            + 'table.p-hd td{border:1px solid #666;padding:3px 6px;} table.p-hd td:nth-child(odd){background:#f3ead6;width:12%;font-weight:bold;}'
            + 'table.p-tb{width:100%;table-layout:fixed;border-collapse:collapse;font-size:11px;}'
            + 'table.p-tb thead{display:table-header-group;}'
            + 'table.p-tb th,table.p-tb td{border:1px solid #666;padding:2px 5px;text-align:center;overflow-wrap:anywhere;}'
            + 'table.p-tb thead th{background:#f3ead6;} table.p-tb td.tl{text-align:left;}'
            + 'table.p-tb tr{break-inside:avoid;}'
            + '@page{margin:12mm 10mm 18mm;'
            + (res.as_doc_no ? " @bottom-right{ content:'"+String(res.as_doc_no).replace(/['\\]/g,'')+"'; font-size:9pt; color:#333; vertical-align:top; padding-top:1mm; }" : '')
            + '}';
        var w = window.open('', '_blank');
        w.document.write('<html><head><meta charset="utf-8"><title>型態識別文件管制表</title><style>'+css+'</style></head><body>'+body
            +'<scr'+'ipt>window.onload=function(){'
            +'var onePageA4=(297-30)*96/25.4;'
            +'if(document.body.scrollHeight>onePageA4*0.92){'
            +'var st=document.createElement(\'style\');'
            +'st.textContent="@page{ @bottom-left{ content:\'第 \' counter(page) \' 頁／共 \' counter(pages) \' 頁\'; font-size:9pt; color:#333; vertical-align:top; padding-top:1mm; } }";'
            +'document.head.appendChild(st);}'
            +'setTimeout(function(){window.print();},200);};</scr'+'ipt></body></html>');
        w.document.close();
    });
}

/* ---------- AS 文件綁定 ---------- */
function renderAsDocLabel(){ $('#asDocLabel').text(EGAsDoc.label(AS_DOC)); }
$('#btnAsDoc').on('click', function(){
    $.getJSON(API, {action:'asdoc_list'}, function(res){
        if (!res.success) return;
        AS_DOCS = res.docs || [];
        $.getJSON(API, {action:'asdoc_get'}, function(res2){
            AS_DOC = (res2 && res2.success) ? res2.as_doc : null;
            openMask('asDocMask'); renderAsDocLabel();
        });
    });
});
function openAsDocPicker(){
    EGAsDoc.open({
        docs: AS_DOCS, current: AS_DOC ? AS_DOC.id : 0, title: '型態識別文件管制表 AS 文件綁定',
        onSave: function(id){
            $.post(API, {action:'as_doc_save', doc_id:id}, function(res){
                if (!res.success){ alert(res.message||'儲存失敗'); return; }
                AS_DOC = res.as_doc; renderAsDocLabel();
            }, 'json');
        }
    });
}

$('#btnPageHelp').on('click', function(){ openMask('helpUseMask'); });
$('.ic-mask').on('click', function(e){ if (e.target === this) this.style.display='none'; });

<?php if ($perms['canView']): ?>
loadList();
<?php endif; ?>
</script>
</body>
</html>
