<?php
/**
 * AS9100 關鍵績效指標總覽（2-GM-04-01）
 * 21項指標 × 12月 + 平均/去年平均；自動計算(快照鎖定)+手動填寫+管理者覆寫+佐證附件+前端試算
 * 資料一律走 src/store/KpiAs_API.php；設定頁 KPI_setting.php（僅KPI管理者）
 */
session_start();
if (!isset($_SESSION['userName'])) {
    $_SESSION['lastpage'] = "../../views/news/KPI.php?in=999";
    header("Location:../../index.php");
    exit;
}
include_once '../../src/common/_config.php';
include_once '../../src/common/DBConnection.php';
include_once '../../src/common/kpi_as_lib.php';

$db = (new DBConnection())->getPDO();
kpi_as_ensure_schema($db);
$kpiUser = kpi_as_current_user($db);
$kpiPerms = kpi_as_perms($db, $kpiUser);
$roleLabel = $kpiPerms['isAdmin'] ? '管理者'
           : ($kpiPerms['canAdmin'] ? 'KPI管理員'
           : ($kpiPerms['canFill'] ? 'KPI填報'
           : ($kpiPerms['canView'] ? 'KPI檢閱（唯讀）' : '無權限')));
?>
<!DOCTYPE html>
<html lang="zh-Hant">
<head>
    <meta charset="utf-8">
    <meta http-equiv="X-UA-Compatible" content="IE=edge">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>KPI 關鍵績效指標</title>
    <link href="../../resource/css/bootstrap.css" rel="stylesheet">
    <link href="../../resource/css/font-awesome.css" rel="stylesheet">
    <link href="../../resource/css/nprogress.css" rel="stylesheet">
    <link href="../../resource/css/custom.css" rel="stylesheet">
    <style>
        #sidebar-menu { visibility: hidden; }
        .right_col .page-title { margin:8px 0 4px; overflow:hidden; }
        .right_col .page-title h2 { margin:6px 0; }
        .kpi-toolbar { clear:both; }
        /* ===== 暖色系配色（ai-rules/10）===== */
        .kpi-toolbar { display:flex; flex-wrap:wrap; gap:6px; align-items:center;
            border:1.5px solid #E8D5B5; border-radius:8px; padding:8px 10px; margin-bottom:10px; background:#FDF8EF; }
        .kpi-toolbar select, .kpi-toolbar button, .kpi-toolbar a.btn { height:30px; font-size:13px; line-height:1;
            padding:0 10px; border:1px solid #D8BE93; border-radius:4px; background:#fff; color:#5b3a1e; cursor:pointer; }
        .kpi-toolbar button:hover, .kpi-toolbar a.btn:hover { background:#F7E0BD; }
        .kpi-toolbar .btn-warm { background:#F0A24B; color:#fff; border-color:#d98a33; }
        .kpi-toolbar .btn-warm:hover { background:#d98a33; }
        .kpi-role-badge { margin-left:auto; font-size:13px; color:#5b3a1e; background:#F7E0BD;
            border-radius:12px; padding:4px 12px; }
        .kpi-role-badge .fa-question-circle { cursor:pointer; color:#b5762a; margin-left:5px; }
        .kpi-table-wrap { overflow-x:auto; border:1px solid #E8D5B5; border-radius:6px; background:#fff; }
        table.kpi-table { width:100%; border-collapse:collapse; font-size:13px; table-layout:auto; }
        table.kpi-table th, table.kpi-table td { border:1px solid #EADFC8; padding:4px 6px; white-space:nowrap; text-align:center; }
        table.kpi-table thead th { position:sticky; top:0; z-index:5; background:#F7E0BD; color:#5b3a1e; font-weight:bold; }
        table.kpi-table td.kpi-name { text-align:left; max-width:220px; overflow:hidden; text-overflow:ellipsis; cursor:help; }
        table.kpi-table tbody tr:nth-child(even) { background:#FBF6EC; }
        table.kpi-table tbody tr:hover { background:#FBF0DD; }
        td.kpi-cell { cursor:pointer; min-width:46px; position:relative; }
        td.kpi-cell:hover { outline:2px solid #F0A24B; outline-offset:-2px; }
        .kpi-below { color:#DD5138; font-weight:bold; }
        .kpi-na { color:#b0a390; }
        .kpi-none { color:#c4863a; }
        .kpi-preview { color:#F0A24B; font-style:italic; }
        .kpi-ov-mark { color:#DD5138; font-size:10px; vertical-align:super; }
        .kpi-attach-badge { display:inline-block; font-size:10px; background:#F7E0BD; color:#7a5217;
            border-radius:8px; padding:0 4px; margin-left:2px; }
        .kpi-avg { background:#FDF3E0; font-weight:bold; }
        /* 儲存格彈出選單 */
        #cellMenu { position:absolute; z-index:1000; background:#fff; border:1px solid #D8BE93; border-radius:6px;
            box-shadow:0 3px 10px rgba(90,58,30,.25); min-width:170px; display:none; }
        #cellMenu .cm-head { background:#F7E0BD; color:#5b3a1e; font-size:12px; padding:5px 10px;
            border-radius:6px 6px 0 0; font-weight:bold; }
        #cellMenu .cm-item { padding:6px 12px; font-size:13px; color:#5b3a1e; cursor:pointer; }
        #cellMenu .cm-item:hover { background:#FBF0DD; }
        #cellMenu .cm-item.disabled { color:#c9bda9; cursor:not-allowed; }
        #cellMenu .cm-info { padding:4px 12px; font-size:11px; color:#8a6d45; border-top:1px dashed #EADFC8; }
        /* 試算列 */
        .kpi-sim-bar { background:#FFF7E8; border:1px dashed #F0A24B; border-radius:6px; padding:6px 10px;
            margin:4px 0; font-size:12px; display:flex; flex-wrap:wrap; gap:8px; align-items:center; }
        .kpi-sim-bar input { width:110px; height:24px; font-size:12px; border:1px solid #D8BE93; border-radius:3px; padding:0 5px; }
        .kpi-sim-bar button { height:24px; font-size:12px; border:1px solid #d98a33; background:#F0A24B; color:#fff;
            border-radius:3px; cursor:pointer; padding:0 10px; }
        /* modal 共用 */
        .kpi-modal-mask { display:none; position:fixed; inset:0; background:rgba(60,40,20,.45); z-index:1050; }
        .kpi-modal { background:#fff; border-radius:8px; max-width:560px; margin:60px auto; padding:0;
            box-shadow:0 5px 25px rgba(0,0,0,.3); max-height:82vh; display:flex; flex-direction:column; }
        .kpi-modal .m-head { background:#F7E0BD; color:#5b3a1e; font-weight:bold; padding:10px 15px;
            border-radius:8px 8px 0 0; display:flex; justify-content:space-between; }
        .kpi-modal .m-head .m-close { cursor:pointer; color:#b5762a; }
        .kpi-modal .m-body { padding:15px; overflow-y:auto; }
        .kpi-modal .m-body label { display:block; font-size:13px; color:#5b3a1e; margin:8px 0 3px; }
        .kpi-modal .m-body input[type=text], .kpi-modal .m-body input[type=number],
        .kpi-modal .m-body select, .kpi-modal .m-body textarea { width:100%; border:1px solid #D8BE93;
            border-radius:4px; padding:5px 8px; font-size:13px; box-sizing:border-box; }
        .kpi-modal .m-foot { padding:10px 15px; border-top:1px solid #EADFC8; text-align:right; }
        .kpi-modal .m-foot button { height:30px; padding:0 16px; border-radius:4px; font-size:13px;
            border:1px solid #d98a33; cursor:pointer; }
        .kpi-modal .m-foot .b-ok { background:#F0A24B; color:#fff; }
        .kpi-modal .m-foot .b-cancel { background:#fff; color:#5b3a1e; border-color:#D8BE93; margin-right:6px; }
        input[type=number]::-webkit-outer-spin-button, input[type=number]::-webkit-inner-spin-button
            { -webkit-appearance:none; margin:0; }
        input[type=number] { -moz-appearance:textfield; }
        .att-row { display:flex; gap:8px; align-items:center; border-bottom:1px dashed #EADFC8; padding:6px 0; font-size:13px; }
        .att-row .att-name { color:#b5762a; cursor:pointer; text-decoration:underline; flex:1;
            overflow:hidden; text-overflow:ellipsis; white-space:nowrap; }
        .att-row .att-note { color:#8a6d45; max-width:150px; overflow:hidden; text-overflow:ellipsis; white-space:nowrap; }
        .att-row .att-del { color:#DD5138; cursor:pointer; }
        .att-missing { color:#c9bda9; text-decoration:line-through; }
        #chartBox { border:1.5px solid #E8D5B5; border-radius:8px; padding:10px; margin-top:12px; background:#fff; }
        #chartPicks { display:flex; flex-wrap:wrap; gap:4px 12px; font-size:12px; color:#5b3a1e; margin-bottom:6px; }
        #chartPicks label { cursor:pointer; margin:0; font-weight:normal; }
        .kpi-noperm { margin:40px auto; max-width:520px; text-align:center; border:1.5px solid #E8D5B5;
            border-radius:10px; padding:30px; background:#FDF8EF; color:#5b3a1e; }
        .kpi-print-head { display:none; text-align:center; }
        .kpi-print-comp { font-size:20px; font-weight:bold; }
        .kpi-print-title { font-size:15px; font-weight:bold; letter-spacing:3px; margin-top:2px; }
        .kpi-print-sub { font-size:11px; color:#555; margin-top:2px; }
        /* 兩份規則刻意重複：@media print 是保險（萬一使用者直接 Ctrl+P 未走 printKpi()）；
           body.kpi-printing 是 printKpi() 按下當下同步套用，讓縮放量測時的版面跟真正列印時一致（見下方 printKpi()） */
        @media print {
            .page-title, .kpi-toolbar, #chartBox, #cellMenu, .nav_menu, .left_col, .kpi-sim-bar, footer,
            .kpi-role-badge .fa-question-circle, .kpi-ov-mark, .kpi-legend { display:none !important; }
            .right_col { margin:0 !important; padding:0 !important; }
            table.kpi-table { font-size:10px; }
            table.kpi-table th, table.kpi-table td { padding:2px 3px; }
            .kpi-table-wrap { overflow:visible; border:none; }
            table.kpi-table thead th { position:static; }
            .kpi-print-head { display:block !important; }
        }
        body.kpi-printing .page-title, body.kpi-printing .kpi-toolbar, body.kpi-printing #chartBox, body.kpi-printing #cellMenu,
        body.kpi-printing .nav_menu, body.kpi-printing .left_col, body.kpi-printing .kpi-sim-bar,
        body.kpi-printing footer, body.kpi-printing .kpi-role-badge .fa-question-circle,
        body.kpi-printing .kpi-ov-mark, body.kpi-printing .kpi-legend { display:none !important; }
        body.kpi-printing .right_col { margin:0 !important; padding:0 !important; }
        body.kpi-printing table.kpi-table { font-size:10px; }
        body.kpi-printing table.kpi-table th, body.kpi-printing table.kpi-table td { padding:2px 3px; }
        body.kpi-printing .kpi-table-wrap { overflow:visible; border:none; }
        body.kpi-printing table.kpi-table thead th { position:static; }
        body.kpi-printing .kpi-print-head { display:block !important; }
    </style>
</head>
<body class="nav-sm">
<div class="container body">
<div class="main_container">
    <?php include '../partPage/sideAndTopBarMenu.html' ?>
    <div class="right_col" role="main">
        <div class="page-title" style="display:flex;align-items:center;flex-wrap:wrap;">
            <h2 style="margin:6px 0;">KPI 關鍵績效指標 <small style="color:#8a6d45;">2-GM-04-01（每月10號前完成填寫）</small></h2>
        </div>
        <div class="clearfix"></div>

<?php if (!$kpiPerms['canView']): ?>
        <div class="kpi-noperm">
            <h4><i class="fa fa-lock"></i> 無 KPI 檢閱權限</h4>
            <p>請洽管理者於「使用者權限設定」指派 KPI 角色，<br>或於 KPI 設定頁建立部門×主管階級授權規則。</p>
        </div>
<?php else: ?>
        <div class="kpi-toolbar">
            <label style="margin:0;font-size:13px;color:#5b3a1e;">年度</label>
            <select id="yearSel"></select>
            <button id="btnRecalcYear" title="重新計算本年度所有自動指標(已結束月份)" style="display:none;">
                <i class="fa fa-refresh"></i> 重算本年</button>
            <button id="btnUpload"><i class="fa fa-paperclip"></i> 上傳佐證</button>
            <button id="btnCsv"><i class="fa fa-file-text-o"></i> 匯出CSV</button>
            <label style="margin:0;font-size:13px;color:#5b3a1e;">紙張</label>
            <select id="kpiPaperSel"><option value="A4">A4</option><option value="A3">A3</option></select>
            <button onclick="printKpi()"><i class="fa fa-print"></i> 列印</button>
            <a class="btn" id="btnSetting" href="KPI_setting.php" style="display:none;line-height:28px;">
                <i class="fa fa-gear"></i> 設定</a>
            <span class="kpi-role-badge">目前角色：<b><?= htmlspecialchars($roleLabel) ?></b>
                <i class="fa fa-question-circle" id="btnRoleHelp" title="角色權限說明"></i></span>
        </div>

        <div class="kpi-print-head" id="kpiPrintHead">
            <div class="kpi-print-comp"></div>
            <div class="kpi-print-title"></div>
            <div class="kpi-print-sub"></div>
        </div>

        <div class="kpi-table-wrap">
            <table class="kpi-table" id="kpiTable">
                <thead>
                    <tr id="kpiHeadRow">
                        <th>項次</th><th>指標內容</th><th>擔當者</th><th>頻率</th><th>判定目標</th>
                        <th>1月</th><th>2月</th><th>3月</th><th>4月</th><th>5月</th><th>6月</th>
                        <th>7月</th><th>8月</th><th>9月</th><th>10月</th><th>11月</th><th>12月</th>
                        <th>平均</th><th>去年平均</th>
                    </tr>
                </thead>
                <tbody id="kpiBody"><tr><td colspan="19" style="padding:20px;color:#8a6d45;">載入中…</td></tr></tbody>
            </table>
        </div>
        <div class="kpi-legend" style="font-size:11px;color:#8a6d45;margin-top:4px;">
            說明：<span class="kpi-preview">橘色斜體</span>=當月即時試算(未定案)；<span class="kpi-below">紅字</span>=未達標；
            <span class="kpi-ov-mark">✱</span>=手動覆寫；<span class="kpi-attach-badge">📎n</span>=佐證附件；?=無資料；NA=未到期。
            點儲存格可操作（明細/附件/填寫/覆寫/重算）。
        </div>

        <div id="chartBox">
            <div style="font-weight:bold;color:#5b3a1e;margin-bottom:4px;"><i class="fa fa-line-chart"></i> 趨勢圖</div>
            <div id="chartPicks"></div>
            <div id="kpiChart" style="height:320px;"></div>
        </div>
<?php endif; ?>
    </div>
    <?php include '../partPage/footer.html' ?>
</div>
</div>

<!-- 儲存格彈出選單 -->
<div id="cellMenu"></div>

<!-- 填寫 modal -->
<div class="kpi-modal-mask" id="fillMask"><div class="kpi-modal">
    <div class="m-head"><span id="fillTitle">填寫</span><span class="m-close" onclick="closeMask('fillMask')">✕</span></div>
    <div class="m-body">
        <div id="fillYesNoBox" style="display:none;">
            <label>結果</label>
            <select id="fillYesNo"><option value="1">Yes（期限內完成）</option><option value="0">No（未完成）</option></select>
        </div>
        <div id="fillNumBox">
            <label id="fillValueLabel">數值</label>
            <input type="number" id="fillValue" step="any">
        </div>
        <label>備註（選填）</label>
        <input type="text" id="fillNote" maxlength="200">
    </div>
    <div class="m-foot">
        <button class="b-cancel" onclick="closeMask('fillMask')">取消</button>
        <button class="b-ok" onclick="submitFill()">儲存</button>
    </div>
</div></div>

<!-- 覆寫 modal -->
<div class="kpi-modal-mask" id="ovMask"><div class="kpi-modal">
    <div class="m-head"><span id="ovTitle">手動覆寫</span><span class="m-close" onclick="closeMask('ovMask')">✕</span></div>
    <div class="m-body">
        <div style="font-size:12px;color:#8a6d45;" id="ovOrigInfo"></div>
        <label>覆寫值</label>
        <input type="number" id="ovValue" step="any">
        <label>覆寫原因（必填，寫入變更歷史）</label>
        <input type="text" id="ovReason" maxlength="200">
    </div>
    <div class="m-foot">
        <button class="b-cancel" onclick="closeMask('ovMask')">取消</button>
        <button class="b-ok" onclick="submitOverride()">覆寫</button>
    </div>
</div></div>

<!-- 附件 modal -->
<div class="kpi-modal-mask" id="attMask"><div class="kpi-modal">
    <div class="m-head"><span id="attTitle">佐證附件</span><span class="m-close" onclick="closeMask('attMask')">✕</span></div>
    <div class="m-body">
        <div id="attPickBox" style="display:none;border-bottom:1px solid #EADFC8;padding-bottom:8px;margin-bottom:8px;">
            <label>KPI 指標（僅列出您可填寫的項目）</label>
            <select id="attIndSel"></select>
            <label>月份</label>
            <select id="attMonthSel"></select>
        </div>
        <div id="attList" style="min-height:40px;"></div>
        <div id="attUpBox" style="margin-top:10px;border-top:1px dashed #EADFC8;padding-top:8px;">
            <label>新增附件（<span id="attLimitTxt"></span>）</label>
            <input type="file" id="attFile">
            <label>附件說明（選填）</label>
            <input type="text" id="attNote" maxlength="200" placeholder="例：6月客訴統計表、盤點紀錄掃描檔…">
            <div style="text-align:right;margin-top:6px;">
                <button class="b-ok" style="height:28px;padding:0 14px;border:1px solid #d98a33;background:#F0A24B;color:#fff;border-radius:4px;cursor:pointer;"
                    onclick="submitAttach()">上傳</button>
            </div>
        </div>
    </div>
</div></div>

<!-- 明細 modal -->
<div class="kpi-modal-mask" id="dtMask"><div class="kpi-modal">
    <div class="m-head"><span id="dtTitle">數值明細</span><span class="m-close" onclick="closeMask('dtMask')">✕</span></div>
    <div class="m-body" id="dtBody" style="font-size:13px;color:#5b3a1e;"></div>
</div></div>

<!-- 角色說明 modal -->
<div class="kpi-modal-mask" id="helpMask"><div class="kpi-modal">
    <div class="m-head"><span>KPI 角色權限說明</span><span class="m-close" onclick="closeMask('helpMask')">✕</span></div>
    <div class="m-body" style="font-size:13px;color:#5b3a1e;line-height:1.8;">
        <b>KPI檢閱</b>：可看KPI總覽、趨勢圖、附件清單與開啟附件；可用「試算」預覽開放參數（不影響正式數值）。<br>
        <b>KPI填報</b>：檢閱＋可重算自動指標(當年度)。<br>
        <b>KPI管理員</b>：填報＋舊年度重算/補填/覆寫、KPI設定頁(指標/公式參數/目標/權限規則/NAS路徑)。<br>
        <b>管理者</b>：系統管理者固定擁有全部權限。<br>
        <b>手動指標填寫／手動覆寫／上傳佐證附件</b>：不分角色，一律僅該指標「擔當者」本人、或擔當者今天請假時系統解析出的代理人可操作(覆寫需填原因)；管理者不受此限。<br>
        <hr style="border-color:#EADFC8;">
        授權方式（聯集）：①權限設定頁指派 KPI 角色(個人/職稱)；②KPI設定頁建立「部門×主管階級」規則或指定人員為管理者。<br>
        年度鎖定：隔年 2/1 起該年度重算/補填/覆寫僅 KPI 管理員可操作，其他人僅能檢視快照。
    </div>
</div></div>

<script src="../../resource/js/jquery.min.js"></script>
<script src="../../resource/js/bootstrap.min.js"></script>
<script src="../../resource/js/nprogress.js"></script>
<script src="../../code/highcharts.js"></script>
<script src="../../resource/js/custom.min.js"></script>
<script>
$(document).ready(function(){
    var $activeMenu = $('#sidebar-menu .nav.side-menu > li.active');
    if ($activeMenu.length) {
        $activeMenu.removeClass('active').find('ul.child_menu').hide();
        $activeMenu.find('li.current-page').removeClass('current-page');
    }
    $('#sidebar-menu').css('visibility', 'visible');
});

var API = '../../src/store/KpiAs_API.php';
var META = null, MATRIX = null, YEAR = null;
var canView = <?= $kpiPerms['canView'] ? 'true' : 'false' ?>;

/* ---------- 共用 ---------- */
function closeMask(id){ document.getElementById(id).style.display='none'; }
function openMask(id){ document.getElementById(id).style.display='block'; }
function esc(s){ return String(s==null?'':s).replace(/[&<>"']/g, function(c){
    return {'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#39;'}[c]; }); }
function fmtVal(v, type){
    if (v === null || v === undefined) return null;
    v = parseFloat(v);
    if (type === 'yesno') return v >= 1 ? 'Yes' : 'No';
    if (type === 'percent') return (Math.round(v*10)/10) + '%';
    if (type === 'rate' || type === 'score') return (Math.round(v*10)/10) + '';
    return (Math.round(v*10)/10) + '';
}
function freqName(f){ return {monthly:'每月',quarterly:'每季',halfyear:'半年',yearly:'每年'}[f] || f; }

/* ---------- 載入 ---------- */
function loadMeta(cb){
    $.getJSON(API, {action:'meta', year: YEAR || undefined}, function(m){
        if (!m.ok) { alert(m.error || '載入失敗'); return; }
        META = m;
        if (!YEAR) YEAR = m.cur_year;
        var $y = $('#yearSel').empty();
        m.years.forEach(function(y){ $y.append('<option value="'+y+'"'+(y===YEAR?' selected':'')+'>'+y+'</option>'); });
        if (m.perms.canAdmin) $('#btnSetting').show();
        renderPrintHead();
        if (cb) cb();
    });
}
function loadMatrix(){
    NProgress.start();
    $.getJSON(API, {action:'matrix', year: YEAR}, function(res){
        NProgress.done();
        if (!res.ok) { alert(res.error || '載入失敗'); return; }
        MATRIX = res;
        renderTable();
        renderChartPicks();
        renderChart();
        var anyRecalc = res.rows.some(function(r){ return r.can_recalc; });
        $('#btnRecalcYear').toggle(anyRecalc);
    }).fail(function(x){ NProgress.done(); alert('載入失敗：' + (x.responseJSON && x.responseJSON.error || x.status)); });
}

/* ---------- 表格渲染 ---------- */
function renderTable(){
    var html = '';
    MATRIX.rows.forEach(function(r, ri){
        var tip = '對應條文：' + (r.clause||'-') + '\n統計方式：' + (r.stat_desc||'-')
                + '\n來源：' + (r.source_mode==='auto' ? '自動計算' : '手動填寫')
                + (r.calculator_key ? '（'+r.calculator_key+'）' : '');
        html += '<tr data-ri="'+ri+'">';
        html += '<td>'+r.item_no+'</td>';
        html += '<td class="kpi-name" title="'+esc(tip)+'">'+esc(r.name)
              + (r.exposed_params.length ? ' <i class="fa fa-sliders" style="color:#F0A24B;cursor:pointer;" title="試算(調整開放參數)" onclick="toggleSim('+ri+', event)"></i>' : '')
              + '</td>';
        html += '<td style="font-size:12px;">'+esc(r.owner||'')+'</td>';
        html += '<td style="font-size:12px;">'+freqName(r.freq)+'</td>';
        html += '<td style="font-size:12px;">'+esc(r.target.text || '')+'</td>';
        for (var m=1; m<=12; m++){
            if (r.months.indexOf(m) < 0) { html += '<td class="kpi-na">—</td>'; continue; }
            var c = r.cells[m];
            var cls = 'kpi-cell', txt;
            if (c.future) { txt = '<span class="kpi-na">NA</span>'; }
            else if (c.v === null) { txt = '<span class="kpi-none">?</span>'; }
            else {
                var f = fmtVal(c.v, r.value_type);
                if (c.src === 'preview') txt = '<span class="kpi-preview" title="當月即時試算，未定案">'+f+'</span>';
                else if (c.below) txt = '<span class="kpi-below">'+f+'</span>';
                else txt = f;
                if (c.src === 'override') txt += '<span class="kpi-ov-mark" title="手動覆寫：'+esc(c.ov_reason||'')+'">✱</span>';
            }
            if (c.attach > 0) txt += '<span class="kpi-attach-badge" title="佐證附件 '+c.attach+' 件">📎'+c.attach+'</span>';
            html += '<td class="'+cls+'" data-ri="'+ri+'" data-m="'+m+'">'+txt+'</td>';
        }
        var avgTxt, pavgTxt;
        if (r.value_type === 'yesno') {
            var yes=0, tot=0;
            r.months.forEach(function(m){ var c=r.cells[m];
                if (!c.future && c.v !== null && c.src !== 'preview') { tot++; if (c.v>=1) yes++; } });
            avgTxt = tot ? yes+'/'+tot : '';
            pavgTxt = '';
        } else {
            avgTxt = r.avg === null ? '' : fmtVal(r.avg, r.value_type);
            pavgTxt = r.prev_avg === null ? '' : fmtVal(r.prev_avg, r.value_type);
        }
        html += '<td class="kpi-avg">'+avgTxt+'</td><td class="kpi-avg" style="color:#8a6d45;">'+pavgTxt+'</td>';
        html += '</tr>';
        html += '<tr class="kpi-sim-row" id="simRow'+ri+'" style="display:none;"><td colspan="19"></td></tr>';
    });
    $('#kpiBody').html(html || '<tr><td colspan="19">無資料</td></tr>');
}

/* ---------- 儲存格選單 ---------- */
$(document).on('click', 'td.kpi-cell', function(e){
    var ri = +$(this).data('ri'), m = +$(this).data('m');
    var r = MATRIX.rows[ri], c = r.cells[m];
    var items = [];
    items.push({t:'<i class="fa fa-info-circle"></i> 數值明細', f:function(){ showDetail(ri,m); }});
    items.push({t:'<i class="fa fa-paperclip"></i> 附件（'+c.attach+'）', f:function(){ openAttach(r.indicator_id, m, r); }});
    if (r.can_fill && !c.future) items.push({t:'<i class="fa fa-pencil"></i> 填寫/修改', f:function(){ openFill(ri,m); }});
    if (r.can_recalc && !c.future && c.src !== 'preview')
        items.push({t:'<i class="fa fa-refresh"></i> 重算此月', f:function(){ doRecalc(r.indicator_id, m); }});
    if (r.can_override && !c.future) {
        items.push({t:'<i class="fa fa-hand-paper-o"></i> 手動覆寫', f:function(){ openOverride(ri,m); }});
        if (c.src === 'override') items.push({t:'<i class="fa fa-eraser"></i> 清除覆寫', f:function(){ doClearOverride(r.indicator_id, m); }});
    }
    var html = '<div class="cm-head">'+r.item_no+'. '+esc(r.name)+'｜'+m+'月</div>';
    items.forEach(function(it, i){ html += '<div class="cm-item" data-i="'+i+'">'+it.t+'</div>'; });
    if (c.filled_by) html += '<div class="cm-info">填寫：'+esc(c.filled_by)+' '+esc((c.filled_at||'').substr(0,16))+'</div>';
    if (c.ov_by) html += '<div class="cm-info">覆寫：'+esc(c.ov_by)+' '+esc((c.ov_at||'').substr(0,16))+'</div>';
    var $menu = $('#cellMenu').html(html).show();
    $menu.css({left: Math.min(e.pageX, $(window).width()-200), top: e.pageY + 5});
    $menu.find('.cm-item').on('click', function(){ $menu.hide(); items[+$(this).data('i')].f(); });
    e.stopPropagation();
});
$(document).on('click', function(){ $('#cellMenu').hide(); });

function showDetail(ri, m){
    var r = MATRIX.rows[ri], c = r.cells[m];
    var si = r.source_info || {};
    var h = '<b>'+r.item_no+'. '+esc(r.name)+'</b>（'+YEAR+'年'+m+'月）<hr style="border-color:#EADFC8;margin:6px 0;">';
    h += '判定目標：'+esc(r.target.text||'-')+'<br>';
    h += '對應條文：'+esc(r.clause||'-')+'<br>';
    h += '統計方式：'+esc(r.stat_desc||'-')+'<br>';
    h += '資料來源：'+esc(si.label||(r.source_mode==='manual'?'手動填寫':'-'))
       + (si.page?'（'+esc(si.page)+'）':'')+'<br>';
    if (si.desc) h += '計算口徑：'+esc(si.desc)+'<br>';
    h += '擔當者：'+esc(r.owner||'-')+'　｜　頻率：'+freqName(r.freq)+'<br>';
    h += '<hr style="border-color:#EADFC8;margin:6px 0;">';
    h += '顯示值：<b>'+(c.v===null?'?':fmtVal(c.v, r.value_type))+'</b>（來源：'+({auto:'自動計算(快照)',manual:'手動填寫',override:'手動覆寫',preview:'當月即時試算',none:'無資料'}[c.src]||c.src)+'）<br>';
    if (c.num !== null || c.den !== null) {
        h += '分子／分母：'+(c.num===null?'-':(+c.num))+' ／ '+(c.den===null?'-':(+c.den));
        if (c.den !== null && +c.den !== 0 && (r.value_type==='percent'||r.value_type==='rate')) {
            h += '　=　'+(Math.round((+c.num)/(+c.den)*10000)/100)+(r.value_type==='percent'?'%':'');
        }
        h += '<br>';
    }
    if (c.computed_at) h += '結算時間：'+esc((c.computed_at||'').substr(0,16))+'<br>';
    if (c.src === 'override' && c.auto_v !== null) h += '被覆寫前自動值：'+fmtVal(c.auto_v, r.value_type)+'<br>';
    if (c.ov_by) h += '覆寫：'+esc(c.ov_by)+'（'+esc(c.ov_reason||'')+'）'+esc((c.ov_at||'').substr(0,16))+'<br>';
    if (c.filled_by) h += '填寫：'+esc(c.filled_by)+' '+esc((c.filled_at||'').substr(0,16))+'<br>';
    if (c.note) h += '備註：'+esc(c.note)+'<br>';
    $('#dtTitle').text('數值明細');
    $('#dtBody').html(h);
    openMask('dtMask');
}

/* ---------- 重算 ---------- */
function doRecalc(iid, month){
    $.post(API, {action:'recalc', indicator_id:iid, year:YEAR, month:month||0}, function(res){
        if (!res.ok) { alert(res.error||'重算失敗'); return; }
        loadMatrix();
    }, 'json').fail(function(x){ alert('重算失敗：'+(x.responseJSON&&x.responseJSON.error||x.status)); });
}
$('#btnRecalcYear').on('click', function(){
    if (!confirm('重新計算 '+YEAR+' 年度所有自動指標的已結束月份？（覆寫值不受影響）')) return;
    var autos = MATRIX.rows.filter(function(r){ return r.can_recalc; });
    var done = 0;
    NProgress.start();
    (function next(){
        if (done >= autos.length) { NProgress.done(); loadMatrix(); return; }
        $.post(API, {action:'recalc', indicator_id:autos[done].indicator_id, year:YEAR, month:0}, function(){ done++; next(); }, 'json')
         .fail(function(){ done++; next(); });
    })();
});

/* ---------- 填寫 ---------- */
var fillCtx = null;
function openFill(ri, m){
    var r = MATRIX.rows[ri], c = r.cells[m];
    fillCtx = {iid: r.indicator_id, m: m, type: r.value_type};
    $('#fillTitle').text('填寫：'+r.item_no+'. '+r.name+'（'+YEAR+'年'+m+'月）');
    if (r.value_type === 'yesno') {
        $('#fillYesNoBox').show(); $('#fillNumBox').hide();
        $('#fillYesNo').val(c.v !== null && c.v >= 1 ? '1' : (c.v === null ? '1' : '0'));
    } else {
        $('#fillYesNoBox').hide(); $('#fillNumBox').show();
        $('#fillValueLabel').text('數值' + (r.target.unit ? '（'+r.target.unit+'）' : ''));
        $('#fillValue').val(c.v === null ? '' : c.v);
    }
    $('#fillNote').val(c.note || '');
    openMask('fillMask');
    setTimeout(function(){ (r.value_type==='yesno' ? $('#fillYesNo') : $('#fillValue')).focus().select(); }, 100);
}
function submitFill(){
    var v = fillCtx.type === 'yesno' ? $('#fillYesNo').val() : $('#fillValue').val();
    if (v === '') { alert('請輸入數值'); return; }
    $.post(API, {action:'fill', indicator_id:fillCtx.iid, year:YEAR, month:fillCtx.m, value:v, note:$('#fillNote').val()},
        function(res){
            if (!res.ok) { alert(res.error||'儲存失敗'); return; }
            closeMask('fillMask'); loadMatrix();
        }, 'json').fail(function(x){ alert('儲存失敗：'+(x.responseJSON&&x.responseJSON.error||x.status)); });
}

/* ---------- 覆寫 ---------- */
var ovCtx = null;
function openOverride(ri, m){
    var r = MATRIX.rows[ri], c = r.cells[m];
    ovCtx = {iid: r.indicator_id, m: m};
    $('#ovTitle').text('手動覆寫：'+r.item_no+'. '+r.name+'（'+YEAR+'年'+m+'月）');
    $('#ovOrigInfo').text('目前顯示值：' + (c.v===null?'?':fmtVal(c.v, r.value_type)) + '（覆寫後原值保留可追溯）');
    $('#ovValue').val(c.src==='override' && c.v!==null ? c.v : '');
    $('#ovReason').val('');
    openMask('ovMask');
    setTimeout(function(){ $('#ovValue').focus().select(); }, 100);
}
function submitOverride(){
    if ($('#ovValue').val() === '') { alert('請輸入覆寫值'); return; }
    if ($.trim($('#ovReason').val()) === '') { alert('覆寫原因必填'); return; }
    $.post(API, {action:'override', indicator_id:ovCtx.iid, year:YEAR, month:ovCtx.m,
                 value:$('#ovValue').val(), reason:$('#ovReason').val()},
        function(res){
            if (!res.ok) { alert(res.error||'覆寫失敗'); return; }
            closeMask('ovMask'); loadMatrix();
        }, 'json').fail(function(x){ alert('覆寫失敗：'+(x.responseJSON&&x.responseJSON.error||x.status)); });
}
function doClearOverride(iid, m){
    if (!confirm('清除此格的手動覆寫，恢復顯示原值？')) return;
    $.post(API, {action:'clear_override', indicator_id:iid, year:YEAR, month:m}, function(res){
        if (!res.ok) { alert(res.error||'失敗'); return; }
        loadMatrix();
    }, 'json');
}

/* ---------- 附件 ---------- */
var attCtx = null;
function openAttach(iid, month, row){
    attCtx = {iid: iid, m: month};
    $('#attPickBox').hide();
    $('#attTitle').text('佐證附件：'+(row ? row.item_no+'. '+row.name : '')+'（'+YEAR+'年'+month+'月）');
    var mine = (META.my_indicators||[]).some(function(mi){ return mi.indicator_id === iid; });
    $('#attUpBox').toggle(mine || META.perms.canAdmin);
    refreshAttList();
    openMask('attMask');
}
$('#btnUpload').on('click', function(){
    var list = META.my_indicators || [];
    if (!list.length) { alert('您目前沒有可填寫/上傳佐證的 KPI 項目（僅指標擔當者與管理者可上傳）'); return; }
    var $s = $('#attIndSel').empty();
    list.forEach(function(mi){ $s.append('<option value="'+mi.indicator_id+'">'+mi.item_no+'. '+esc(mi.name)+'</option>'); });
    $('#attPickBox').show();
    $('#attUpBox').show();
    $('#attTitle').text('上傳佐證附件（'+YEAR+'年）');
    fillAttMonths();
    attCtx = null;
    refreshAttList();
    openMask('attMask');
});
function kpiPeriodStartMonth(freq, m){
    // 手動指標的bucket月只代表期間結束點，期間開始就能填/傳附件，不必等到結束月，見 kpi_as_lib.php 同名函式
    if (freq === 'quarterly') return m - 2;
    if (freq === 'halfyear') return m - 5;
    if (freq === 'yearly') return 1;
    return m;
}
function fillAttMonths(){
    var iid = +$('#attIndSel').val();
    var mi = (META.my_indicators||[]).find(function(x){ return x.indicator_id === iid; });
    var $m = $('#attMonthSel').empty();
    (mi ? mi.months : [1,2,3,4,5,6,7,8,9,10,11,12]).forEach(function(m){
        var startM = mi && mi.source_mode === 'manual' ? kpiPeriodStartMonth(mi.freq, m) : m;
        if (YEAR === META.cur_year && startM > META.cur_month) return;
        $m.append('<option value="'+m+'">'+m+'月</option>');
    });
    if (YEAR === META.cur_year && $m.find('option').length) $m.val($m.find('option').last().val());
    refreshAttList();
}
$('#attIndSel').on('change', fillAttMonths);
$('#attMonthSel').on('change', refreshAttList);
function curAttCell(){
    if (attCtx) return attCtx;
    return {iid: +$('#attIndSel').val(), m: +$('#attMonthSel').val()};
}
function refreshAttList(){
    var c = curAttCell();
    if (!c.iid || !c.m) { $('#attList').html('<span style="color:#8a6d45;font-size:12px;">請選擇指標與月份</span>'); return; }
    $.getJSON(API, {action:'attach_list', indicator_id:c.iid, year:YEAR, month:c.m}, function(res){
        if (!res.ok) { $('#attList').html(esc(res.error||'載入失敗')); return; }
        $('#attLimitTxt').text('每月每項上限 '+res.max+' 件，單檔 20MB');
        if (!res.list.length) { $('#attList').html('<span style="color:#8a6d45;font-size:12px;">尚無附件</span>'); return; }
        var h = '';
        res.list.forEach(function(a){
            h += '<div class="att-row">';
            h += a.exists
               ? '<span class="att-name" title="開啟" onclick="window.open(API+\'?action=attach_open&attach_id='+a.attach_id+'\')">📄 '+esc(a.original_name)+'</span>'
               : '<span class="att-name att-missing" title="檔案不存在(NAS路徑可能已變更)">📄 '+esc(a.original_name)+'</span>';
            h += '<span class="att-note" title="'+esc(a.note||'')+'">'+esc(a.note||'')+'</span>';
            h += '<span style="color:#8a6d45;font-size:11px;">'+esc(a.uploaded_by_name||'')+' '+esc((a.created_at||'').substr(5,11))+'</span>';
            if (a.can_delete) h += '<span class="att-del" title="刪除" onclick="delAttach('+a.attach_id+')"><i class="fa fa-trash"></i></span>';
            h += '</div>';
        });
        $('#attList').html(h);
    });
}
function submitAttach(){
    var c = curAttCell();
    var f = document.getElementById('attFile');
    if (!c.iid || !c.m) { alert('請選擇指標與月份'); return; }
    if (!f.files.length) { alert('請選擇檔案'); return; }
    var fd = new FormData();
    fd.append('action', 'attach_upload');
    fd.append('indicator_id', c.iid);
    fd.append('year', YEAR);
    fd.append('month', c.m);
    fd.append('note', $('#attNote').val());
    fd.append('file', f.files[0]);
    NProgress.start();
    $.ajax({url:API, method:'POST', data:fd, processData:false, contentType:false, dataType:'json'})
        .done(function(res){
            NProgress.done();
            if (!res.ok) { alert(res.error||'上傳失敗'); return; }
            f.value = ''; $('#attNote').val('');
            refreshAttList(); loadMatrix();
        })
        .fail(function(x){ NProgress.done(); alert('上傳失敗：'+(x.responseJSON&&x.responseJSON.error||x.status)); });
}
function delAttach(aid){
    if (!confirm('刪除此附件？（NAS上的檔案將一併刪除）')) return;
    $.post(API, {action:'attach_delete', attach_id:aid}, function(res){
        if (!res.ok) { alert(res.error||'刪除失敗'); return; }
        refreshAttList(); loadMatrix();
    }, 'json');
}

/* ---------- 前端試算（開放參數） ---------- */
function toggleSim(ri, ev){
    if (ev) ev.stopPropagation();
    var $row = $('#simRow'+ri);
    if ($row.is(':visible')) { $row.hide(); loadMatrix(); return; }
    var r = MATRIX.rows[ri];
    var h = '<div class="kpi-sim-bar"><b><i class="fa fa-sliders"></i> 試算</b>';
    r.exposed_params.forEach(function(p){
        var val = Array.isArray(p.value) ? p.value.join(',') : (p.value === null || typeof p.value === 'object' ? '' : p.value);
        h += '<label>'+esc(p.label)+'</label><input data-key="'+esc(p.key)+'" data-type="'+esc(p.type)+'" value="'+esc(val)+'">';
    });
    h += '<button onclick="runSim('+ri+')">試算</button>';
    if (MATRIX.can_admin) h += '<button style="background:#DD5138;border-color:#b53c28;" onclick="applySim('+ri+')" title="把目前試算參數寫回本年度設定並重算">套用修改</button>';
    h += '<button style="background:#fff;color:#5b3a1e;border-color:#D8BE93;" onclick="toggleSim('+ri+')">還原</button>';
    h += '<span style="color:#b5762a;">試算僅預覽；「套用修改」才會寫回本年度設定</span></div>';
    $row.show().find('td').html(h);
}
function runSim(ri){
    var r = MATRIX.rows[ri];
    var params = {};
    $('#simRow'+ri+' input').each(function(){
        var k = $(this).data('key'), t = $(this).data('type'), v = $.trim($(this).val());
        if (t === 'int' || t === 'num') params[k] = v === '' ? 0 : +v;
        else if (t === 'bool') params[k] = v === '1' || v === 'true' ? 1 : 0;
        else params[k] = v; // textlist/intlist/statuslist：後端以逗號切分
    });
    NProgress.start();
    $.getJSON(API, {action:'preview', indicator_id:r.indicator_id, year:YEAR, params:JSON.stringify(params)}, function(res){
        NProgress.done();
        if (!res.ok) { alert(res.error||'試算失敗'); return; }
        var $tr = $('tr[data-ri="'+ri+'"]');
        r.months.forEach(function(m){
            var $td = $tr.find('td[data-m="'+m+'"]');
            if (!$td.length) return;
            var pv = res.months[m];
            if (pv === null || pv === undefined) return;
            $td.html('<span class="kpi-preview" title="試算值 分子='+pv.num+' 分母='+pv.den+'">'+fmtVal(pv.v, r.value_type)+'</span>');
        });
    }).fail(function(){ NProgress.done(); alert('試算失敗'); });
}
function collectSimParams(ri){
    var params = {};
    $('#simRow'+ri+' input').each(function(){
        var k = $(this).data('key'), t = $(this).data('type'), v = $.trim($(this).val());
        if (t === 'int' || t === 'num') params[k] = v === '' ? 0 : +v;
        else if (t === 'bool') params[k] = v === '1' || v === 'true' ? 1 : 0;
        else params[k] = v;
    });
    return params;
}
function applySim(ri){
    var r = MATRIX.rows[ri];
    if (!confirm('把第 '+r.item_no+' 項目前試算的參數寫回 '+YEAR+' 年度設定，並重算已結束月份？')) return;
    NProgress.start();
    $.post(API, {action:'apply_params', indicator_id:r.indicator_id, year:YEAR, params:JSON.stringify(collectSimParams(ri))}, function(res){
        NProgress.done();
        if (!res.ok) { alert(res.error||'套用失敗'); return; }
        alert(res.changed > 0 ? ('已套用並重算（更新 '+res.changed+' 個參數）') : '參數無變更');
        loadMatrix();
    }, 'json').fail(function(x){ NProgress.done(); alert('套用失敗：'+(x.responseJSON&&x.responseJSON.error||x.status)); });
}

/* ---------- 趨勢圖 ---------- */
var chartSel = null;
function renderChartPicks(){
    if (chartSel === null) {
        chartSel = {};
        MATRIX.rows.filter(function(r){ return r.source_mode==='auto'; }).slice(0,4)
            .forEach(function(r){ chartSel[r.indicator_id] = true; });
    }
    var h = '';
    MATRIX.rows.forEach(function(r){
        if (r.value_type === 'yesno') return;
        h += '<label><input type="checkbox" data-iid="'+r.indicator_id+'"'+(chartSel[r.indicator_id]?' checked':'')+'> '
           + r.item_no+'.'+esc(r.name)+'</label>';
    });
    $('#chartPicks').html(h);
    $('#chartPicks input').on('change', function(){
        chartSel[+$(this).data('iid')] = this.checked;
        renderChart();
    });
}
var warmColors = ['#F0A24B','#DD5138','#B5762A','#8A5A2B','#E8C170','#C98A5E','#A0522D','#D2A24C'];
function renderChart(){
    var series = [];
    var ci = 0;
    MATRIX.rows.forEach(function(r){
        if (!chartSel[r.indicator_id] || r.value_type === 'yesno') return;
        var data = [];
        for (var m=1; m<=12; m++){
            if (r.months.indexOf(m) < 0) { data.push(null); continue; }
            var c = r.cells[m];
            data.push(c.future || c.v === null ? null : +c.v);
        }
        series.push({name:r.item_no+'.'+r.name, data:data, color:warmColors[ci++ % warmColors.length]});
    });
    Highcharts.chart('kpiChart', {
        chart:{type:'line', backgroundColor:'transparent'},
        title:{text:null}, credits:{enabled:false},
        xAxis:{categories:['1月','2月','3月','4月','5月','6月','7月','8月','9月','10月','11月','12月']},
        yAxis:{title:{text:null}},
        tooltip:{shared:true},
        plotOptions:{line:{connectNulls:false, marker:{enabled:true, radius:3}}},
        series: series.length ? series : [{name:'（勾選上方指標）', data:[]}]
    });
}

/* ---------- 匯出 CSV ---------- */
$('#btnCsv').on('click', function(){
    if (!MATRIX) return;
    var rows = [['項次','指標內容','對應條文','擔當者','頻率','統計方式','判定目標',
                 '1月','2月','3月','4月','5月','6月','7月','8月','9月','10月','11月','12月','平均','去年平均']];
    MATRIX.rows.forEach(function(r){
        var line = [r.item_no, r.name, r.clause||'', r.owner||'', freqName(r.freq), r.stat_desc||'', r.target.text||''];
        for (var m=1; m<=12; m++){
            if (r.months.indexOf(m) < 0) { line.push('—'); continue; }
            var c = r.cells[m];
            line.push(c.future ? 'NA' : (c.v===null ? '?' : fmtVal(c.v, r.value_type) + (c.src==='override'?'*':'') + (c.src==='preview'?'(試算)':'')));
        }
        var avgTxt = r.value_type==='yesno' ? '' : (r.avg===null?'':fmtVal(r.avg, r.value_type));
        line.push(avgTxt, r.prev_avg===null?'':fmtVal(r.prev_avg, r.value_type));
        rows.push(line);
    });
    var csv = '﻿' + rows.map(function(l){
        return l.map(function(v){ return '"'+String(v==null?'':v).replace(/"/g,'""')+'"'; }).join(',');
    }).join('\r\n');
    var a = document.createElement('a');
    a.href = URL.createObjectURL(new Blob([csv], {type:'text/csv;charset=utf-8;'}));
    a.download = 'KPI_2-GM-04-01_' + YEAR + '.csv';
    a.click();
});

/* ---------- 列印（ai-rules/16：大標題本公司名/表頭綁定AS文件表單名稱/頁碼左下/AS文件編號右下；
   並按使用者選定紙張(A4/A3)自動縮放整份內容到一頁，見 printKpi()） ---------- */
function renderPrintHead(){
    if (!META) return;
    var docName = (META.as_doc && META.as_doc.doc_name) ? META.as_doc.doc_name : 'KPI 關鍵績效指標總覽（2-GM-04-01）';
    $('#kpiPrintHead .kpi-print-comp').text(META.company || '');
    $('#kpiPrintHead .kpi-print-title').text(docName);
    $('#kpiPrintHead .kpi-print-sub').text(YEAR + ' 年度｜列印日期：' + new Date().toISOString().substr(0,10));
}
var KPI_PAPER_MM = { A4:{w:297,h:210}, A3:{w:420,h:297} }; // 統一用橫式（19欄較容易排下）
function kpiMmToPx(mm){ return mm * 96 / 25.4; }
function applyPrintPageStyle(paper, asDocNo){
    var size = KPI_PAPER_MM[paper] || KPI_PAPER_MM.A4;
    var asTxt = String(asDocNo||'').replace(/['\\]/g, '');
    var css = '@page{size:' + paper + ' landscape;margin:10mm 8mm 14mm 8mm;'
        + (asTxt ? " @bottom-right{content:'" + asTxt + "';font-size:9pt;color:#333;}" : '')
        + " @bottom-left{content:'第 ' counter(page) ' 頁／共 ' counter(pages) ' 頁';font-size:9pt;color:#333;}"
        + '}';
    var $st = $('#kpiPageStyle');
    if (!$st.length) $st = $('<style id="kpiPageStyle"></style>').appendTo('head');
    $st.text(css);
    return size;
}
function printKpi(){
    if (!MATRIX) return;
    renderPrintHead();
    var paper = $('#kpiPaperSel').val() || 'A4';
    var size = applyPrintPageStyle(paper, META && META.as_doc_no);
    document.body.classList.add('kpi-printing');
    document.body.style.zoom = 1;
    // 量測「即將列印」版面（此時工具列/圖表已被 body.kpi-printing 隱藏、表頭已顯示）算出縮放比例，塞滿選定紙張的一頁
    var natW = document.getElementById('kpiTable').scrollWidth;
    var natH = document.querySelector('.right_col').scrollHeight;
    var safeMm = 6; // 印表機不可印邊界安全值
    var pageWpx = kpiMmToPx(size.w - 8*2 - safeMm);
    var pageHpx = kpiMmToPx(size.h - 10 - 14 - safeMm);
    var scale = Math.min(pageWpx / natW, pageHpx / natH);
    scale = Math.max(0.35, Math.min(scale, 2.5));
    document.body.style.zoom = scale;
    setTimeout(function(){ window.print(); }, 100);
}
window.addEventListener('afterprint', function(){
    document.body.style.zoom = '';
    document.body.classList.remove('kpi-printing');
});

/* ---------- 事件 ---------- */
$('#yearSel').on('change', function(){ YEAR = +this.value; chartSel = null; loadMeta(function(){ loadMatrix(); }); });
$('#btnRoleHelp').on('click', function(){ openMask('helpMask'); });
$('.kpi-modal-mask').on('click', function(e){ if (e.target === this) this.style.display='none'; });
// 輸入欄位規則：聚焦全選、雙擊清空、Enter 送出
$(document).on('focus', '.kpi-modal input[type=text], .kpi-modal input[type=number]', function(){ this.select(); });
$(document).on('dblclick', '.kpi-modal input[type=text], .kpi-modal input[type=number]', function(){ this.value=''; });
$(document).on('keydown', '#fillMask input', function(e){ if (e.key==='Enter') submitFill(); });
$(document).on('keydown', '#ovMask input', function(e){ if (e.key==='Enter') submitOverride(); });

if (canView) loadMeta(function(){ loadMatrix(); });
</script>
</body>
</html>
