<?php
/**
 * 量測儀器校驗管理（KPI 2-GM-04-01 第18項 量測儀器按時校驗率 的來源頁）
 * 儀器主檔沿用 qc_tool；週期自動推算下次應校驗日；登錄完成即前滾到期。
 * 資料一律走 src/store/ToolCalib_API.php；權限 tool_calib_lib.php
 */
session_start();
if (!isset($_SESSION['userName'])) {
    $_SESSION['lastpage'] = "../../views/QC/tool_calibration.php";
    header("Location:../../index.php");
    exit;
}
include_once '../../src/common/_config.php';
include_once '../../src/common/DBConnection.php';
include_once '../../src/common/tool_calib_lib.php';

$db = (new DBConnection())->getPDO();
tool_calib_ensure_schema($db);
$tcUser = tool_calib_current_user($db);
$perms = tool_calib_perms($db, $tcUser);
$roleLabel = $perms['isAdmin'] ? '管理者'
           : ($perms['canAdmin'] ? '校驗管理員'
           : ($perms['canEdit'] ? '校驗登錄'
           : ($perms['canView'] ? '校驗唯讀' : '無權限')));
?>
<!DOCTYPE html>
<html lang="zh-Hant">
<head>
    <meta charset="utf-8">
    <meta http-equiv="X-UA-Compatible" content="IE=edge">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>量測儀器校驗管理</title>
    <link href="../../resource/css/bootstrap.css" rel="stylesheet">
    <link href="../../resource/css/font-awesome.css" rel="stylesheet">
    <link href="../../resource/css/nprogress.css" rel="stylesheet">
    <link href="../../resource/css/custom.css" rel="stylesheet">
    <style>
        #sidebar-menu { visibility: hidden; }
        .right_col .page-title { margin:8px 0 4px; overflow:hidden; }
        .tc-toolbar { display:flex; flex-wrap:wrap; gap:6px; align-items:center; clear:both;
            border:1.5px solid #E8D5B5; border-radius:8px; padding:8px 10px; margin-bottom:10px; background:#FDF8EF; }
        .tc-toolbar label { margin:0; font-size:13px; color:#5b3a1e; }
        .tc-toolbar select, .tc-toolbar input[type=month], .tc-toolbar button, .tc-toolbar a.btn {
            height:30px; font-size:13px; line-height:1; padding:0 10px; border:1px solid #D8BE93;
            border-radius:4px; background:#fff; color:#5b3a1e; cursor:pointer; }
        .tc-toolbar button:hover, .tc-toolbar a.btn:hover { background:#F7E0BD; }
        .tc-toolbar .btn-warm { background:#F0A24B; color:#fff; border-color:#d98a33; }
        .tc-toolbar .btn-warm:hover { background:#d98a33; }
        .tc-role-badge { margin-left:auto; font-size:13px; color:#5b3a1e; background:#F7E0BD; border-radius:12px; padding:4px 12px; }
        .tc-role-badge .fa-question-circle { cursor:pointer; color:#b5762a; margin-left:5px; }
        /* 當月統計條 */
        .tc-stat { display:flex; flex-wrap:wrap; gap:18px; align-items:center; margin-bottom:10px;
            border:1.5px solid #E8D5B5; border-radius:8px; padding:10px 14px; background:#FFF7E8; }
        .tc-stat .s-num { font-size:22px; font-weight:bold; color:#8A5A2B; }
        .tc-stat .s-lab { font-size:12px; color:#8a6d45; }
        .tc-stat .s-rate.below { color:#DD5138; }
        .tc-stat .s-rate.ok { color:#8A5A2B; }
        /* 類別分頁列（哪些類別要當分頁＝類別設定的 calib_tab） */
        .tc-tabs { display:flex; flex-wrap:wrap; gap:4px; align-items:flex-end; margin:0 0 8px;
            border-bottom:2px solid #E8D5B5; }
        .tc-tabs .tab { padding:6px 14px; font-size:13px; color:#8a6d45; background:#FBF6EC;
            border:1px solid #E8D5B5; border-bottom:none; border-radius:6px 6px 0 0; cursor:pointer; margin-bottom:-2px; }
        .tc-tabs .tab:hover { background:#F7E0BD; }
        .tc-tabs .tab.active { background:#F0A24B; color:#fff; border-color:#d98a33; font-weight:bold; }
        .tc-tabs .tab .cnt { font-size:11px; margin-left:4px; opacity:.85; }
        .tc-tabs .tab-hint { font-size:12px; color:#b5762a; margin:0 0 6px 10px; }
        .tc-pager { display:flex; flex-wrap:wrap; gap:6px; align-items:center; margin:4px 0 6px; font-size:13px; color:#5b3a1e; }
        .tc-pager select, .tc-pager button { height:28px; font-size:13px; border:1px solid #D8BE93; border-radius:4px;
            background:#fff; color:#5b3a1e; cursor:pointer; padding:0 10px; }
        .tc-pager button:hover:not(:disabled) { background:#F7E0BD; }
        .tc-pager button:disabled { color:#c9bda9; cursor:not-allowed; }
        .tc-table-wrap { overflow-x:auto; border:1px solid #E8D5B5; border-radius:6px; background:#fff; }
        table.tc-table { width:100%; border-collapse:collapse; font-size:13px; }
        table.tc-table th, table.tc-table td { border:1px solid #EADFC8; padding:5px 8px; white-space:nowrap; text-align:center; }
        table.tc-table thead th { position:sticky; top:0; z-index:5; background:#F7E0BD; color:#5b3a1e; font-weight:bold; }
        table.tc-table tbody tr:nth-child(even) { background:#FBF6EC; }
        table.tc-table tbody tr:hover { background:#FBF0DD; }
        table.tc-table td.t-left { text-align:left; }
        .st-pill { display:inline-block; font-size:12px; border-radius:10px; padding:2px 9px; }
        .st-overdue { background:#DD5138; color:#fff; }
        .st-soon { background:#F0A24B; color:#fff; }
        .st-ok { background:#F7E0BD; color:#7a5217; }
        .st-nobaseline { background:#fff; color:#c4863a; border:1px dashed #D8BE93; }
        .st-unmanaged { background:#efe7d8; color:#b0a390; }
        .tc-op { color:#b5762a; cursor:pointer; margin:0 4px; }
        .tc-op:hover { color:#8A5A2B; text-decoration:underline; }
        .tc-op.disabled { color:#c9bda9; cursor:not-allowed; text-decoration:none; }
        .managed-yes { color:#8A5A2B; font-weight:bold; }
        .managed-no { color:#b0a390; }
        input[type=number]::-webkit-outer-spin-button, input[type=number]::-webkit-inner-spin-button { -webkit-appearance:none; margin:0; }
        input[type=number] { -moz-appearance:textfield; }
        /* modal */
        .tc-mask { display:none; position:fixed; inset:0; background:rgba(60,40,20,.45); z-index:1050; }
        .tc-modal { background:#fff; border-radius:8px; max-width:560px; margin:52px auto; box-shadow:0 5px 25px rgba(0,0,0,.3);
            max-height:84vh; display:flex; flex-direction:column; }
        .tc-modal.wide { max-width:760px; }
        .tc-modal .m-head { background:#F7E0BD; color:#5b3a1e; font-weight:bold; padding:10px 15px; border-radius:8px 8px 0 0;
            display:flex; justify-content:space-between; }
        .tc-modal .m-head .m-close { cursor:pointer; color:#b5762a; }
        .tc-modal .m-body { padding:15px; overflow-y:auto; }
        .tc-modal .m-body label { display:block; font-size:13px; color:#5b3a1e; margin:9px 0 3px; }
        .tc-modal .m-body input[type=text], .tc-modal .m-body input[type=number], .tc-modal .m-body input[type=date],
        .tc-modal .m-body select, .tc-modal .m-body textarea { width:100%; border:1px solid #D8BE93; border-radius:4px;
            padding:5px 8px; font-size:13px; box-sizing:border-box; }
        .tc-modal .m-foot { padding:10px 15px; border-top:1px solid #EADFC8; text-align:right; }
        .tc-modal .m-foot button { height:30px; padding:0 16px; border-radius:4px; font-size:13px; border:1px solid #d98a33; cursor:pointer; }
        .tc-modal .m-foot .b-ok { background:#F0A24B; color:#fff; }
        .tc-modal .m-foot .b-cancel { background:#fff; color:#5b3a1e; border-color:#D8BE93; margin-right:6px; }
        .grid2 { display:grid; grid-template-columns:1fr 1fr; gap:0 14px; }
        table.hist { width:100%; border-collapse:collapse; font-size:12px; }
        table.hist th, table.hist td { border:1px solid #EADFC8; padding:4px 6px; text-align:center; }
        table.hist thead th { background:#F7E0BD; color:#5b3a1e; }
        .tc-noperm { margin:40px auto; max-width:520px; text-align:center; border:1.5px solid #E8D5B5; border-radius:10px;
            padding:30px; background:#FDF8EF; color:#5b3a1e; }
        @media print {
            .tc-toolbar, .nav_menu, .left_col, footer, .tc-role-badge .fa-question-circle, .tc-op { display:none !important; }
            .right_col { margin:0 !important; padding:0 !important; }
            /* 只留目前分頁當標題，讓列印看得出印的是哪一類 */
            .tc-tabs { border:none; }
            .tc-tabs .tab:not(.active), .tc-tabs .tab-hint { display:none !important; }
            .tc-tabs .tab.active { background:none !important; color:#5b3a1e !important; border:none !important;
                font-size:14px; padding:0 0 4px; }
            table.tc-table thead th { position:static; }
        }
    </style>
</head>
<body class="nav-sm">
<div class="container body">
<div class="main_container">
    <?php include '../partPage/sideAndTopBarMenu.html' ?>
    <div class="right_col" role="main">
        <div class="page-title" style="display:flex;align-items:center;flex-wrap:wrap;">
            <h2 style="margin:6px 0;">量測儀器校驗管理
                <small style="color:#8a6d45;">KPI 2-GM-04-01 #18 量測儀器按時校驗率 來源頁</small></h2>
        </div>
        <div class="clearfix"></div>

<?php if (!$perms['canView']): ?>
        <div class="tc-noperm">
            <h4><i class="fa fa-lock"></i> 無量測儀器校驗檢閱權限</h4>
            <p>請洽管理者於「使用者權限設定」指派「校驗唯讀／登錄／管理員」角色。</p>
        </div>
<?php else: ?>
        <div class="tc-toolbar">
            <label>統計月份</label>
            <input type="month" id="ymSel">
            <label>狀態</label>
            <select id="statSel">
                <option value="">全部</option>
                <option value="managed">僅納管</option>
                <option value="overdue">逾期</option>
                <option value="soon">即將到期</option>
                <option value="ok">正常</option>
                <option value="nobaseline">未設基準</option>
                <option value="unmanaged">未納管</option>
            </select>
            <input type="text" id="kwSel" placeholder="搜尋編號" style="width:120px;">
            <button class="btn-warm" id="btnAdd" style="display:none;"><i class="fa fa-plus"></i> 新增儀器</button>
            <button id="btnCatSet" style="display:none;"><i class="fa fa-sliders"></i> 類別設定</button>
            <button id="btnCsv"><i class="fa fa-file-text-o"></i> 匯出CSV</button>
            <button onclick="window.print()"><i class="fa fa-print"></i> 列印</button>
            <span class="tc-role-badge">目前角色：<b><?= htmlspecialchars($roleLabel) ?></b>
                <i class="fa fa-question-circle" id="btnRoleHelp" title="角色權限說明"></i></span>
        </div>

        <div class="tc-stat" id="statBar">
            <div><span class="s-num" id="stDen">—</span> <span class="s-lab">當月應校驗</span></div>
            <div><span class="s-num" id="stNum">—</span> <span class="s-lab">準時完成</span></div>
            <div><span class="s-num s-rate" id="stRate">—</span> <span class="s-lab">按時校驗率（目標 ≥95%）</span></div>
            <div class="s-lab" id="stHint" style="margin-left:auto;"></div>
        </div>

        <div class="tc-tabs" id="tcTabs"></div>

        <div class="tc-pager" id="tcPager">
            <span id="tcCount" style="margin-right:auto;color:#8a6d45;"></span>
            <label>每頁</label>
            <select id="tcPageSize"><option>5</option><option selected>10</option><option>20</option><option>50</option></select>
            <button id="tcPrev">‹ 上一頁</button>
            <span id="tcPageInfo"></span>
            <button id="tcNext">下一頁 ›</button>
        </div>
        <div class="tc-table-wrap">
            <table class="tc-table" id="tcTable">
                <thead><tr>
                    <th>量具編號</th><th>類別</th><th>週期(月)</th><th>校驗方式</th>
                    <th>下次應校驗日</th><th>狀態</th><th>最近校驗</th><th>納入管理</th><th>操作</th>
                </tr></thead>
                <tbody id="tcBody"><tr><td colspan="9" style="padding:20px;color:#8a6d45;">載入中…</td></tr></tbody>
            </table>
        </div>
        <div style="font-size:11px;color:#8a6d45;margin-top:4px;">
            狀態：<span class="st-pill st-overdue">逾期</span> <span class="st-pill st-soon">30天內到期</span>
            <span class="st-pill st-ok">正常</span> <span class="st-pill st-nobaseline">未設基準</span>
            <span class="st-pill st-unmanaged">未納管</span>。
            「納入管理」者才計入 KPI；下次應校驗日＝上次校驗日＋週期（登錄完成後自動前滾）。
            <span id="tcExcluded" style="color:#b5762a;"></span>
        </div>
<?php endif; ?>
    </div>
    <?php include '../partPage/footer.html' ?>
</div>
</div>

<!-- 登錄校驗 modal -->
<div class="tc-mask" id="recMask"><div class="tc-modal">
    <div class="m-head"><span id="recTitle">登錄校驗</span><span class="m-close" onclick="closeMask('recMask')">✕</span></div>
    <div class="m-body">
        <div style="font-size:12px;color:#8a6d45;" id="recInfo"></div>
        <div class="grid2">
            <div><label>校驗完成日 *</label><input type="date" id="recDate"></div>
            <div><label>判定結果</label><select id="recResult">
                <option value="pass">合格</option><option value="pass_adjust">校正後合格</option><option value="fail">不合格</option>
            </select></div>
            <div><label>校驗方式</label><select id="recMethod">
                <option value="">—</option><option value="內校">內校</option><option value="外校">外校</option>
            </select></div>
            <div><label>校驗人員／單位</label><input type="text" id="recOperator" maxlength="50"></div>
            <div><label>憑證／報告編號</label><input type="text" id="recCert" maxlength="50"></div>
            <div><label>備註</label><input type="text" id="recNote" maxlength="200"></div>
        </div>
        <div style="font-size:12px;color:#b5762a;margin-top:8px;" id="recRoll"></div>
    </div>
    <div class="m-foot">
        <button class="b-cancel" onclick="closeMask('recMask')">取消</button>
        <button class="b-ok" onclick="submitRec()">登錄</button>
    </div>
</div></div>

<!-- 設定/新增儀器 modal -->
<div class="tc-mask" id="setMask"><div class="tc-modal">
    <div class="m-head"><span id="setTitle">儀器設定</span><span class="m-close" onclick="closeMask('setMask')">✕</span></div>
    <div class="m-body">
        <div class="grid2">
            <div id="setNoBox"><label>量具編號 *</label><input type="text" id="setNo" maxlength="30"></div>
            <div id="setCatBox"><label>類別 *</label>
                <select id="setCat"></select></div>
            <div><label>校驗週期（月）</label><input type="number" id="setCycle" step="1" min="0" placeholder="例：12"></div>
            <div><label>校驗方式（預設）</label><select id="setMethod">
                <option value="">—</option><option value="內校">內校</option><option value="外校">外校</option>
            </select></div>
            <div><label>下次應校驗日（基準）</label><input type="date" id="setBase"></div>
            <div><label>納入校驗管理（計入 KPI）</label><select id="setManaged">
                <option value="1">是</option><option value="0">否</option>
            </select></div>
        </div>
        <div style="font-size:12px;color:#8a6d45;margin-top:8px;">
            設定基準到期日後，之後每次登錄校驗會依週期自動前滾，不需再手動維護。<br>
            類別下拉只列出「需校驗且可設定量具編號」的類別；類別的新增／更名／刪除請至
            <a href="inspection_combined_prototype.php" target="_blank" style="color:#b5762a;">線上檢驗－量具設定</a>，
            其校驗屬性則於本頁工具列「類別設定」勾選。
        </div>
    </div>
    <div class="m-foot">
        <button class="b-cancel" onclick="closeMask('setMask')">取消</button>
        <button class="b-ok" onclick="submitSet()">儲存</button>
    </div>
</div></div>

<!-- 類別設定 modal（只設校驗屬性；類別本身的新增/更名/刪除在 線上檢驗－量具設定） -->
<div class="tc-mask" id="catMask"><div class="tc-modal wide">
    <div class="m-head"><span>量具類別設定</span><span class="m-close" onclick="closeMask('catMask')">✕</span></div>
    <div class="m-body">
        <div style="font-size:12px;color:#8a6d45;margin-bottom:8px;line-height:1.7;">
            類別的<b>新增／更名／刪除</b>請至
            <a href="inspection_combined_prototype.php" target="_blank" style="color:#b5762a;">線上檢驗－量具設定</a>（本頁不重複提供）。<br>
            <b>需校驗</b>：不是實體量具、只是檢驗方式者（例如「目視」）請取消勾選，其量具不會出現在本頁、也不列入 KPI。<br>
            <b>可設定量具編號</b>：取消後該類別不能再新增／移入量具編號。<br>
            <b>列入分頁</b>：勾選者會在清單上方出現專屬分頁；需先勾「需校驗」才能設定，未列入分頁者歸在「其他」分頁。
        </div>
        <div style="max-height:46vh;overflow-y:auto;">
        <table class="hist" id="catTable">
            <thead><tr><th style="text-align:left;">類別</th><th>量具數</th><th>需校驗</th><th>可設定量具編號</th><th>列入分頁</th></tr></thead>
            <tbody id="catBody"></tbody>
        </table>
        </div>
    </div>
    <div class="m-foot">
        <button class="b-cancel" onclick="closeMask('catMask')">取消</button>
        <button class="b-ok" onclick="submitCats()">儲存</button>
    </div>
</div></div>

<!-- 歷史 modal -->
<div class="tc-mask" id="hisMask"><div class="tc-modal wide">
    <div class="m-head"><span id="hisTitle">校驗歷史</span><span class="m-close" onclick="closeMask('hisMask')">✕</span></div>
    <div class="m-body" id="hisBody" style="font-size:13px;color:#5b3a1e;"></div>
</div></div>

<!-- 角色說明 modal -->
<div class="tc-mask" id="helpMask"><div class="tc-modal">
    <div class="m-head"><span>角色權限說明</span><span class="m-close" onclick="closeMask('helpMask')">✕</span></div>
    <div class="m-body" style="font-size:13px;color:#5b3a1e;line-height:1.8;">
        <b>校驗唯讀</b>：檢視儀器清單、校驗歷史、當月統計與匯出。<br>
        <b>校驗登錄</b>：唯讀＋登錄各儀器的校驗完成紀錄。<br>
        <b>校驗管理員</b>：登錄＋新增儀器、設定週期/納管/基準到期日、刪除誤登紀錄。<br>
        <b>管理者</b>：系統管理者固定擁有全部權限。<br>
        <hr style="border-color:#EADFC8;">
        本頁資料為 KPI「量測儀器按時校驗率(#18)」的計算來源；納入管理的儀器每月依到期日自動計入 KPI。
    </div>
</div></div>

<script src="../../resource/js/jquery.min.js"></script>
<script src="../../resource/js/bootstrap.min.js"></script>
<script src="../../resource/js/nprogress.js"></script>
<script src="../../resource/js/custom.min.js"></script>
<script>
$(document).ready(function(){
    var $am = $('#sidebar-menu .nav.side-menu > li.active');
    if ($am.length) { $am.removeClass('active').find('ul.child_menu').hide(); $am.find('li.current-page').removeClass('current-page'); }
    $('#sidebar-menu').css('visibility','visible');
});

var API = '../../src/store/ToolCalib_API.php';
var META = null, ROWS = [], PERMS = null, CATS = [];
var curTab = '';   // 目前分頁：'' 全部 ｜ 類別id ｜ 'other' 其他（需校驗但未設為分頁）
var canView = <?= $perms['canView'] ? 'true' : 'false' ?>;
var RESULT_LABEL = {pass:'合格', pass_adjust:'校正後合格', fail:'不合格'};
var STATUS_LABEL = {overdue:'逾期', soon:'即將到期', ok:'正常', nobaseline:'未設基準', unmanaged:'未納管'};

function closeMask(id){ document.getElementById(id).style.display='none'; }
function openMask(id){ document.getElementById(id).style.display='block'; }
function esc(s){ return String(s==null?'':s).replace(/[&<>"']/g,function(c){
    return {'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#39;'}[c]; }); }
function fmtDate(d){ return d ? String(d).substr(0,10) : ''; }

function loadMeta(cb){
    $.getJSON(API, {action:'meta'}, function(m){
        if (!m.ok){ alert(m.error||'載入失敗'); return; }
        META = m; PERMS = m.perms;
        $('#ymSel').val(m.cur_ym);
        setCats(m.categories);
        if (m.perms.canAdmin) { $('#btnAdd').show(); $('#btnCatSet').show(); }
        if (cb) cb();
    });
}
/* 類別清單（含旗標）：新增/更名/刪除類別在「線上檢驗－量具設定」，本頁只設校驗屬性 */
function setCats(cats){
    CATS = cats || [];
    if (META) META.categories = CATS;
    var sel = $('#setCat').val(), $sc = $('#setCat').empty();
    CATS.forEach(function(c){
        if (c.calib_required !== 1 || c.has_tool_no !== 1) return;   // 只列可掛量具編號的校驗類別
        $sc.append('<option value="'+c.QC_Tool_List_id+'">'+esc(c.QC_Tool)+'</option>');
    });
    $('#setCat').val(sel);
    renderTabs();
}

function loadList(){
    NProgress.start();
    $.getJSON(API, {action:'list', ym:$('#ymSel').val()}, function(res){
        NProgress.done();
        if (!res.ok){ alert(res.error||'載入失敗'); return; }
        ROWS = res.rows; PERMS = res.perms;
        if (res.categories) setCats(res.categories); else renderTabs();
        $('#tcExcluded').text(res.excluded > 0
            ? '　另有 '+res.excluded+' 支量具所屬類別未設為「需校驗」，未列入本頁與 KPI。' : '');
        renderStat(res.stat, res.ym);
        renderTable();
    }).fail(function(x){ NProgress.done(); alert('載入失敗：'+(x.responseJSON&&x.responseJSON.error||x.status)); });
}

/* ---------- 類別分頁列 ---------- */
function tabCats(){ return CATS.filter(function(c){ return c.calib_required===1 && c.calib_tab===1; }); }
function renderTabs(){
    var tabs = tabCats(), tabIds = tabs.map(function(c){ return String(c.QC_Tool_List_id); });
    var cnt = {}, other = 0;
    ROWS.forEach(function(r){
        var k = String(r.QC_Tool_List_id);
        cnt[k] = (cnt[k]||0) + 1;
        if (tabIds.indexOf(k) < 0) other++;
    });
    // 目前分頁若被取消設定 → 回到全部
    if (curTab !== '' && curTab !== 'other' && tabIds.indexOf(curTab) < 0) curTab = '';
    var h = '<div class="tab'+(curTab===''?' active':'')+'" data-tab="">全部<span class="cnt">'+ROWS.length+'</span></div>';
    tabs.forEach(function(c){
        var id = String(c.QC_Tool_List_id);
        h += '<div class="tab'+(curTab===id?' active':'')+'" data-tab="'+id+'">'+esc(c.QC_Tool)
           + '<span class="cnt">'+(cnt[id]||0)+'</span></div>';
    });
    if (other > 0 || curTab === 'other')
        h += '<div class="tab'+(curTab==='other'?' active':'')+'" data-tab="other">其他<span class="cnt">'+other+'</span></div>';
    if (!tabs.length)
        h += '<span class="tab-hint">尚未設定分頁類別'+((PERMS&&PERMS.canAdmin)?'，可按工具列「類別設定」勾選':'')+'</span>';
    $('#tcTabs').html(h);
}
$('#tcTabs').on('click', '.tab', function(){
    curTab = String($(this).attr('data-tab')); tcPage = 1; renderTabs(); renderTable();
});

function renderStat(stat, ym){
    $('#stDen').text(stat.den);
    $('#stNum').text(stat.num);
    var $r = $('#stRate');
    if (stat.value === null){ $r.text('—').removeClass('below ok'); $('#stHint').text(ym+' 無應校驗儀器'); }
    else {
        var v = Math.round(stat.value*10)/10;
        $r.text(v+'%').toggleClass('below', v<95).toggleClass('ok', v>=95);
        $('#stHint').text(ym+'：'+stat.num+' / '+stat.den+' 準時完成');
    }
}

function statPill(s){ return '<span class="st-pill st-'+s+'">'+(STATUS_LABEL[s]||s)+'</span>'; }

var tcPage = 1;
function filteredRows(){
    var stt = $('#statSel').val(), kw = $.trim($('#kwSel').val()).toLowerCase();
    var tabIds = tabCats().map(function(c){ return String(c.QC_Tool_List_id); });
    return ROWS.filter(function(r){
        var cid = String(r.QC_Tool_List_id);
        if (curTab === 'other') { if (tabIds.indexOf(cid) >= 0) return false; }
        else if (curTab !== '' && cid !== curTab) return false;
        if (stt === 'managed' && r.calib_managed!==1) return false;
        if (stt && stt!=='managed' && r.status!==stt) return false;
        if (kw && String(r.Tool_No).toLowerCase().indexOf(kw)<0) return false;
        return true;
    });
}
function renderTable(){
    var list = filteredRows();
    var size = parseInt($('#tcPageSize').val(),10) || 10;
    var pages = Math.max(1, Math.ceil(list.length/size));
    if (tcPage > pages) tcPage = pages;
    if (tcPage < 1) tcPage = 1;
    var start = (tcPage-1)*size;
    var pageRows = list.slice(start, start+size);
    $('#tcCount').text('共 '+list.length+' 支量具');
    $('#tcPageInfo').text(tcPage+' / '+pages+' 頁');
    $('#tcPrev').prop('disabled', tcPage<=1);
    $('#tcNext').prop('disabled', tcPage>=pages);
    var html = '';
    pageRows.forEach(function(r){
        var last = r.last ? (fmtDate(r.last.calib_date)+'（'+(RESULT_LABEL[r.last.result]||r.last.result)+'）') : '—';
        var canEdit = PERMS.canEdit, canAdmin = PERMS.canAdmin;
        html += '<tr>';
        html += '<td class="t-left"><b>'+esc(r.Tool_No)+'</b></td>';
        html += '<td>'+esc(r.category_name||'')+'</td>';
        html += '<td>'+(r.calib_cycle_months==null?'—':r.calib_cycle_months)+'</td>';
        html += '<td>'+esc(r.calib_method||'—')+'</td>';
        html += '<td>'+(fmtDate(r.calibration_due)||'—')+'</td>';
        html += '<td>'+statPill(r.status)+'</td>';
        html += '<td>'+last+'</td>';
        html += '<td class="'+(r.calib_managed===1?'managed-yes':'managed-no')+'">'+(r.calib_managed===1?'✔ 是':'否')+'</td>';
        html += '<td>';
        html += canEdit ? '<span class="tc-op" onclick="openRec('+r.Tool_id+')"><i class="fa fa-pencil"></i>登錄</span>'
                        : '<span class="tc-op disabled"><i class="fa fa-pencil"></i>登錄</span>';
        html += canAdmin ? '<span class="tc-op" onclick="openSet('+r.Tool_id+')"><i class="fa fa-gear"></i>設定</span>' : '';
        html += '<span class="tc-op" onclick="openHis('+r.Tool_id+')"><i class="fa fa-history"></i>歷史</span>';
        html += '</td></tr>';
    });
    $('#tcBody').html(html || '<tr><td colspan="9" style="padding:16px;color:#8a6d45;">無符合條件的儀器</td></tr>');
}

$('#statSel').on('change', function(){ tcPage=1; renderTable(); });
$('#kwSel').on('input', function(){ tcPage=1; renderTable(); });
$('#tcPageSize').on('change', function(){ tcPage=1; renderTable(); });
$('#tcPrev').on('click', function(){ if(tcPage>1){ tcPage--; renderTable(); } });
$('#tcNext').on('click', function(){ tcPage++; renderTable(); });
$('#ymSel').on('change', loadList);

/* ---------- 登錄/編輯校驗 ---------- */
var recTool = null, editCalibId = null;
function openRec(tid){
    var r = ROWS.find(function(x){ return x.Tool_id===tid; });
    recTool = r; editCalibId = null;
    $('#recTitle').text('登錄校驗：'+r.Tool_No+'（'+(r.category_name||'')+'）');
    $('#recInfo').html('目前下次應校驗日：<b>'+(fmtDate(r.calibration_due)||'（未設定）')+'</b>　週期：'
        +(r.calib_cycle_months==null?'（未設）':r.calib_cycle_months+' 月'));
    $('#recDate').val(META.today);
    $('#recResult').val('pass');
    $('#recMethod').val(r.calib_method||'');
    $('#recOperator').val(''); $('#recCert').val(''); $('#recNote').val('');
    updateRoll();
    openMask('recMask');
    setTimeout(function(){ $('#recDate').focus(); }, 100);
}
function editHis(cid){
    var a = (HISTORY||[]).find(function(x){ return String(x.calib_id)===String(cid); });
    if (!a) return;
    recTool = ROWS.find(function(x){ return x.Tool_id===HIST_TID; }) || {calib_cycle_months:null};
    editCalibId = cid;
    $('#recTitle').text('編輯校驗紀錄');
    $('#recInfo').html('本次應校驗到期日：<b>'+(fmtDate(a.due_date)||'（無）')+'</b>（編輯不改到期基準，僅修正內容）');
    $('#recDate').val(fmtDate(a.calib_date));
    $('#recResult').val(a.result);
    $('#recMethod').val(a.method||'');
    $('#recOperator').val(a.operator||''); $('#recCert').val(a.cert_no||''); $('#recNote').val(a.note||'');
    updateRoll();
    closeMask('hisMask'); openMask('recMask');
    setTimeout(function(){ $('#recDate').focus(); }, 100);
}
function updateRoll(){
    var cyc = recTool.calib_cycle_months, d = $('#recDate').val();
    if (cyc==null || !d){ $('#recRoll').text(''); return; }
    var dt = new Date(d); dt.setMonth(dt.getMonth()+parseInt(cyc,10));
    $('#recRoll').text('登錄後下次應校驗日將前滾為約 '+dt.toISOString().substr(0,10)+'（依週期 '+cyc+' 月）');
}
$('#recDate').on('change', updateRoll);
function submitRec(){
    if (!$('#recDate').val()){ alert('請選擇校驗完成日'); return; }
    var data = {calib_date:$('#recDate').val(), result:$('#recResult').val(), method:$('#recMethod').val(),
        operator:$('#recOperator').val(), cert_no:$('#recCert').val(), note:$('#recNote').val()};
    if (editCalibId){ data.action='edit_calib'; data.calib_id=editCalibId; }
    else { data.action='record_calib'; data.tool_id=recTool.Tool_id; }
    $.post(API, data, function(res){
        if (!res.ok){ alert(res.error||'儲存失敗'); return; }
        closeMask('recMask'); loadList();
    }, 'json').fail(function(x){ alert('儲存失敗：'+(x.responseJSON&&x.responseJSON.error||x.status)); });
}

/* ---------- 設定 / 新增 ---------- */
var setTool = null;
function openSet(tid){
    setTool = tid ? ROWS.find(function(x){ return x.Tool_id===tid; }) : null;
    $('#setCat option.tmp-cat').remove();          // 清掉上一支量具補上的「目前類別」暫時選項
    if (!$('#setCat option').length && !setTool){
        alert('目前沒有「需校驗且可設定量具編號」的類別。\n請先按工具列「類別設定」勾選，或至「線上檢驗－量具設定」新增類別。');
        return;
    }
    if (setTool){
        $('#setTitle').text('儀器設定：'+setTool.Tool_No);
        $('#setNoBox,#setCatBox').show();
        $('#setNo').val(setTool.Tool_No);
        // 該量具目前類別若已被設為不可掛編號（下拉不含）→ 補一個選項，避免存檔時被誤改類別
        var cid = String(setTool.QC_Tool_List_id);
        if (!$('#setCat option[value="'+cid+'"]').length)
            $('#setCat').append('<option class="tmp-cat" value="'+cid+'">'+esc(setTool.category_name||cid)+'（目前類別）</option>');
        $('#setCat').val(cid);
        $('#setCycle').val(setTool.calib_cycle_months==null?'':setTool.calib_cycle_months);
        $('#setMethod').val(setTool.calib_method||'');
        $('#setBase').val(fmtDate(setTool.calibration_due));
        $('#setManaged').val(String(setTool.calib_managed));
    } else {
        $('#setTitle').text('新增儀器');
        $('#setNoBox,#setCatBox').show();
        $('#setNo').val(''); $('#setCat').prop('selectedIndex',0);
        $('#setCycle').val(12); $('#setMethod').val(''); $('#setBase').val(''); $('#setManaged').val('1');
    }
    openMask('setMask');
}
$('#btnAdd').on('click', function(){ openSet(null); });
function submitSet(){
    if (!$.trim($('#setNo').val())){ alert('請填量具編號'); return; }
    var data = {cycle:$('#setCycle').val(), managed:$('#setManaged').val(),
                method:$('#setMethod').val(), baseline_due:$('#setBase').val(),
                tool_no:$('#setNo').val(), category_id:$('#setCat').val()};
    if (setTool){ data.action='save_tool'; data.tool_id=setTool.Tool_id; }
    else { data.action='create_tool'; }
    $.post(API, data, function(res){
        if (!res.ok){ alert(res.error||'儲存失敗'); return; }
        closeMask('setMask'); loadList();
    }, 'json').fail(function(x){ alert('儲存失敗：'+(x.responseJSON&&x.responseJSON.error||x.status)); });
}

/* ---------- 類別設定（管理員；只改校驗屬性旗標） ---------- */
$('#btnCatSet').on('click', function(){
    var h = CATS.map(function(c){
        var req = c.calib_required===1, hasNo = c.has_tool_no===1, tab = (c.calib_tab===1 && req);
        return '<tr data-id="'+c.QC_Tool_List_id+'">'
            + '<td style="text-align:left;">'+esc(c.QC_Tool)+'</td>'
            + '<td>'+c.tool_cnt+(c.managed_cnt>0 ? '（納管 '+c.managed_cnt+'）' : '')+'</td>'
            + '<td><input type="checkbox" class="ck-req"'+(req?' checked':'')+'></td>'
            + '<td><input type="checkbox" class="ck-hasno"'+(hasNo?' checked':'')+'></td>'
            + '<td><input type="checkbox" class="ck-tab"'+(tab?' checked':'')+(req?'':' disabled')+'></td>'
            + '</tr>';
    }).join('');
    $('#catBody').html(h || '<tr><td colspan="5" style="color:#8a6d45;padding:12px;">尚無量具類別</td></tr>');
    openMask('catMask');
});
// 取消「需校驗」→ 同時鎖住並取消「列入分頁」
$('#catBody').on('change', '.ck-req', function(){
    var $tr = $(this).closest('tr'), on = this.checked;
    $tr.find('.ck-tab').prop('disabled', !on);
    if (!on) $tr.find('.ck-tab').prop('checked', false);
});
function submitCats(){
    var items = [], warn = [];
    $('#catBody tr').each(function(){
        var $tr = $(this), id = $tr.attr('data-id');
        if (!id) return;
        var c = CATS.find(function(x){ return String(x.QC_Tool_List_id)===String(id); }) || {};
        var req = $tr.find('.ck-req').prop('checked') ? 1 : 0;
        if (!req && (c.managed_cnt||0) > 0) warn.push('・'+c.QC_Tool+'（'+c.managed_cnt+' 支已納管）');
        items.push({id:id, calib_required:req,
                    has_tool_no:$tr.find('.ck-hasno').prop('checked')?1:0,
                    calib_tab:$tr.find('.ck-tab').prop('checked')?1:0});
    });
    if (warn.length && !confirm('下列類別取消「需校驗」後，其已納管量具將不再顯示於本頁、也不計入 KPI：\n'
        + warn.join('\n') + '\n\n確定儲存？')) return;
    $.post(API, {action:'save_categories', items: JSON.stringify(items)}, function(res){
        if (!res.ok){ alert(res.error||'儲存失敗'); return; }
        setCats(res.categories); closeMask('catMask'); loadList();
    }, 'json').fail(function(x){ alert('儲存失敗：'+(x.responseJSON&&x.responseJSON.error||x.status)); });
}

/* ---------- 歷史 ---------- */
var HISTORY = [], HIST_TID = null;
function openHis(tid){
    HIST_TID = tid;
    $.getJSON(API, {action:'history', tool_id:tid}, function(res){
        if (!res.ok){ alert(res.error||'載入失敗'); return; }
        HISTORY = res.list;
        $('#hisTitle').text('校驗歷史：'+res.tool.Tool_No+'（'+(res.tool.category_name||'')+'）');
        if (!res.list.length){ $('#hisBody').html('<div style="color:#8a6d45;padding:12px;">尚無校驗紀錄</div>'); openMask('hisMask'); return; }
        var canEdit = PERMS.canEdit, canDel = res.can_delete;
        var h = '<table class="hist"><thead><tr><th>應校驗到期日</th><th>校驗完成日</th><th>準時</th><th>結果</th>'
              + '<th>方式</th><th>人員/單位</th><th>憑證編號</th><th>下次到期</th><th>登錄者</th>'
              + ((canEdit||canDel)?'<th>操作</th>':'') + '</tr></thead><tbody>';
        res.list.forEach(function(a){
            var ontime = (a.due_date && a.calib_date) ? (fmtDate(a.calib_date)<=fmtDate(a.due_date)) : null;
            h += '<tr>';
            h += '<td>'+(fmtDate(a.due_date)||'—')+'</td>';
            h += '<td>'+fmtDate(a.calib_date)+'</td>';
            h += '<td>'+(ontime===null?'—':(ontime?'<span style="color:#8A5A2B;">準時</span>':'<span style="color:#DD5138;">逾期</span>'))+'</td>';
            h += '<td>'+(RESULT_LABEL[a.result]||a.result)+'</td>';
            h += '<td>'+esc(a.method||'—')+'</td>';
            h += '<td>'+esc(a.operator||'—')+'</td>';
            h += '<td>'+esc(a.cert_no||'—')+'</td>';
            h += '<td>'+(fmtDate(a.next_due)||'—')+'</td>';
            h += '<td>'+esc(a.created_by_name||'')+'</td>';
            if (canEdit || canDel){
                h += '<td>';
                if (canEdit) h += '<span class="tc-op" onclick="editHis('+a.calib_id+')"><i class="fa fa-pencil"></i></span>';
                if (canDel) h += '<span class="tc-op" style="color:#DD5138;" onclick="delCalib('+a.calib_id+')"><i class="fa fa-trash"></i></span>';
                h += '</td>';
            }
            h += '</tr>';
        });
        h += '</tbody></table>';
        $('#hisBody').html(h);
        openMask('hisMask');
    });
}
function delCalib(cid){
    if (!confirm('刪除此校驗紀錄？（將依剩餘紀錄修復下次應校驗日）')) return;
    $.post(API, {action:'delete_calib', calib_id:cid}, function(res){
        if (!res.ok){ alert(res.error||'刪除失敗'); return; }
        closeMask('hisMask'); loadList();
    }, 'json');
}

/* ---------- 匯出 CSV ---------- */
$('#btnCsv').on('click', function(){
    var rows = [['量具編號','類別','週期(月)','校驗方式','下次應校驗日','狀態','最近校驗日','最近結果','納入管理']];
    ROWS.forEach(function(r){
        rows.push([r.Tool_No, r.category_name||'', r.calib_cycle_months==null?'':r.calib_cycle_months,
            r.calib_method||'', fmtDate(r.calibration_due), STATUS_LABEL[r.status]||r.status,
            r.last?fmtDate(r.last.calib_date):'', r.last?(RESULT_LABEL[r.last.result]||r.last.result):'',
            r.calib_managed===1?'是':'否']);
    });
    var csv = '﻿' + rows.map(function(l){
        return l.map(function(v){ return '"'+String(v==null?'':v).replace(/"/g,'""')+'"'; }).join(',');
    }).join('\r\n');
    var a = document.createElement('a');
    a.href = URL.createObjectURL(new Blob([csv], {type:'text/csv;charset=utf-8;'}));
    a.download = '量測儀器校驗清單_'+$('#ymSel').val()+'.csv';
    a.click();
});

$('#btnRoleHelp').on('click', function(){ openMask('helpMask'); });
$('.tc-mask').on('click', function(e){ if (e.target===this) this.style.display='none'; });
// UI 規範：聚焦全選、雙擊清空
$(document).on('focus', '.tc-modal input[type=text], .tc-modal input[type=number]', function(){ this.select(); });
$(document).on('dblclick', '.tc-modal input[type=text], .tc-modal input[type=number]', function(){ this.value=''; });

if (canView) loadMeta(function(){ loadList(); });
</script>
</body>
</html>
