<?php
/**
 * 供應商稽核管理（KPI 2-GM-04-01 第6項 廠商稽核按時執行率 的來源頁）—— 稽核批次模型
 * 每期(上/下半年)挑一批稽核對象(手動多選/隨機抽取)；大類/加工項目階層篩選(比照 master_data)。
 * 資料一律走 src/store/VendorAudit_API.php；權限 vendor_audit_lib.php
 */
session_start();
if (!isset($_SESSION['userName'])) {
    $_SESSION['lastpage'] = "../../views/pm/vendor_audit.php";
    header("Location:../../index.php");
    exit;
}
include_once '../../src/common/_config.php';
include_once '../../src/common/DBConnection.php';
include_once '../../src/common/vendor_audit_lib.php';

$db = (new DBConnection())->getPDO();
vendor_audit_ensure_schema($db);
$vaUser = vendor_audit_current_user($db);
$perms = vendor_audit_perms($db, $vaUser);
$roleLabel = $perms['isAdmin'] ? '管理者'
           : ($perms['canAdmin'] ? '稽核管理員'
           : ($perms['canEdit'] ? '稽核登錄'
           : ($perms['canView'] ? '稽核檢閱' : '無權限')));
?>
<!DOCTYPE html>
<html lang="zh-Hant">
<head>
    <meta charset="utf-8">
    <meta http-equiv="X-UA-Compatible" content="IE=edge">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>供應商稽核管理</title>
    <link href="../../resource/css/bootstrap.css" rel="stylesheet">
    <link href="../../resource/css/font-awesome.css" rel="stylesheet">
    <link href="../../resource/css/nprogress.css" rel="stylesheet">
    <link href="../../resource/css/custom.css" rel="stylesheet">
    <style>
        #sidebar-menu { visibility: hidden; }
        .right_col .page-title { margin:8px 0 4px; overflow:hidden; }
        .va-toolbar { display:flex; flex-wrap:wrap; gap:6px; align-items:center; clear:both;
            border:1.5px solid #E8D5B5; border-radius:8px; padding:8px 10px; margin-bottom:10px; background:#FDF8EF; }
        .va-toolbar label { margin:0; font-size:13px; color:#5b3a1e; }
        .va-toolbar select, .va-toolbar input[type=text], .va-toolbar input[type=number], .va-toolbar button {
            height:30px; font-size:13px; line-height:1; padding:0 10px; border:1px solid #D8BE93;
            border-radius:4px; background:#fff; color:#5b3a1e; cursor:pointer; }
        .va-toolbar button:hover { background:#F7E0BD; }
        .va-toolbar .btn-warm { background:#F0A24B; color:#fff; border-color:#d98a33; }
        .va-toolbar .btn-warm:hover { background:#d98a33; }
        .va-role-badge { margin-left:auto; font-size:13px; color:#5b3a1e; background:#F7E0BD; border-radius:12px; padding:4px 12px; }
        .va-role-badge .fa-question-circle { cursor:pointer; color:#b5762a; margin-left:5px; }
        .va-stat { display:flex; flex-wrap:wrap; gap:18px; align-items:center; margin-bottom:8px;
            border:1.5px solid #E8D5B5; border-radius:8px; padding:10px 14px; background:#FFF7E8; }
        .va-stat .s-num { font-size:22px; font-weight:bold; color:#8A5A2B; }
        .va-stat .s-lab { font-size:12px; color:#8a6d45; }
        .va-stat .s-rate.below { color:#DD5138; }
        .va-stat .s-rate.ok { color:#8A5A2B; }
        .va-remind { font-size:12px; color:#8a6d45; margin-bottom:8px; }
        .va-table-wrap { overflow-x:auto; border:1px solid #E8D5B5; border-radius:6px; background:#fff; }
        table.va-table { width:100%; border-collapse:collapse; font-size:13px; }
        table.va-table th, table.va-table td { border:1px solid #EADFC8; padding:5px 8px; white-space:nowrap; text-align:center; }
        table.va-table thead th { position:sticky; top:0; z-index:5; background:#F7E0BD; color:#5b3a1e; font-weight:bold; }
        table.va-table tbody tr:nth-child(even) { background:#FBF6EC; }
        table.va-table tbody tr:hover { background:#FBF0DD; }
        table.va-table tr.dis td { background:#efe7d8 !important; color:#b0a390; }
        table.va-table td.t-left { text-align:left; }
        .st-pill { display:inline-block; font-size:12px; border-radius:10px; padding:2px 9px; }
        .st-done { background:#F0A24B; color:#fff; }
        .st-todo { background:#F7E0BD; color:#7a5217; }
        .st-dis { background:#efe7d8; color:#b0a390; }
        .rs-pass { color:#8A5A2B; } .rs-conditional { color:#c98a2e; } .rs-fail { color:#DD5138; }
        .va-op { color:#b5762a; cursor:pointer; margin:0 4px; }
        .va-op:hover { color:#8A5A2B; text-decoration:underline; }
        input[type=number]::-webkit-outer-spin-button, input[type=number]::-webkit-inner-spin-button { -webkit-appearance:none; margin:0; }
        input[type=number] { -moz-appearance:textfield; }
        .va-mask { display:none; position:fixed; inset:0; background:rgba(60,40,20,.45); z-index:1050; }
        .va-modal { background:#fff; border-radius:8px; max-width:560px; margin:36px auto; box-shadow:0 5px 25px rgba(0,0,0,.3);
            max-height:90vh; display:flex; flex-direction:column; }
        .va-modal.wide { max-width:860px; }
        .va-modal .m-head { background:#F7E0BD; color:#5b3a1e; font-weight:bold; padding:10px 15px; border-radius:8px 8px 0 0;
            display:flex; justify-content:space-between; }
        .va-modal .m-head .m-close { cursor:pointer; color:#b5762a; }
        .va-modal .m-body { padding:15px; overflow-y:auto; }
        .va-modal .m-body label { display:block; font-size:13px; color:#5b3a1e; margin:9px 0 3px; }
        .va-modal .m-body input[type=text], .va-modal .m-body input[type=number], .va-modal .m-body input[type=date],
        .va-modal .m-body select, .va-modal .m-body textarea { width:100%; border:1px solid #D8BE93; border-radius:4px;
            padding:5px 8px; font-size:13px; box-sizing:border-box; }
        .va-modal .m-foot { padding:10px 15px; border-top:1px solid #EADFC8; text-align:right; }
        .va-modal .m-foot button { height:30px; padding:0 16px; border-radius:4px; font-size:13px; border:1px solid #d98a33; cursor:pointer; }
        .va-modal .m-foot .b-ok { background:#F0A24B; color:#fff; }
        .va-modal .m-foot .b-cancel { background:#fff; color:#5b3a1e; border-color:#D8BE93; margin-right:6px; }
        .grid2 { display:grid; grid-template-columns:1fr 1fr; gap:0 14px; }
        .va-modal.xwide { max-width:920px; }
        /* 稽核評鑑表單 */
        .af-head { display:grid; grid-template-columns:repeat(3,1fr); gap:0 14px; }
        .af-table-wrap { border:1px solid #EADFC8; border-radius:6px; overflow:hidden; }
        table.af-table { width:100%; border-collapse:collapse; font-size:12px; }
        table.af-table td, table.af-table th { border-bottom:1px solid #F0E7D5; padding:4px 6px; }
        table.af-table tr.af-cat td { background:#F7E0BD; color:#5b3a1e; font-weight:bold; }
        table.af-table td.af-q { text-align:left; }
        table.af-table td.af-sc { text-align:center; white-space:nowrap; }
        table.af-table select { height:26px; font-size:12px; border:1px solid #D8BE93; border-radius:4px; width:56px; padding:0 4px; }
        .af-summary { margin-top:8px; border:1.5px solid #E8D5B5; border-radius:8px; background:#FFF7E8; padding:8px 12px; font-size:12px; color:#5b3a1e; }
        .af-summary table { width:100%; border-collapse:collapse; }
        .af-summary td, .af-summary th { padding:3px 6px; text-align:center; border-bottom:1px solid #F0E7D5; }
        .af-summary .af-total td { font-weight:bold; background:#FDF3E0; }
        .af-judge-pass { color:#8A5A2B; font-weight:bold; } .af-judge-fail { color:#DD5138; font-weight:bold; }
        /* picker */
        .pk-filter { display:flex; flex-wrap:wrap; gap:6px 10px; align-items:center; margin-bottom:8px; }
        .pk-filter label { margin:0; font-size:12px; color:#5b3a1e; }
        /* 高特異度覆蓋 .m-body select{width:100%}，讓大類/加工項目並排 */
        .va-modal .m-body .pk-filter select { width:150px; height:28px; font-size:12px; padding:0 6px; }
        .va-modal .m-body .pk-filter input[type=text] { width:150px; height:28px; font-size:12px; padding:0 6px; }
        .pk-list { border:1px solid #EADFC8; border-radius:6px; max-height:60vh; overflow-y:auto; }
        table.pk-table { width:100%; border-collapse:collapse; font-size:12px; }
        table.pk-table th, table.pk-table td { border-bottom:1px solid #F0E7D5; padding:4px 6px; text-align:center; }
        table.pk-table thead th { position:sticky; top:0; background:#F7E0BD; color:#5b3a1e; z-index:2; }
        table.pk-table td.t-left { text-align:left; }
        .mg-yes { color:#8A5A2B; font-weight:bold; } .mg-no { color:#b0a390; }
        .pk-actions { display:flex; flex-wrap:wrap; gap:8px; align-items:center; margin-top:10px; padding-top:8px; border-top:1px dashed #EADFC8; }
        .pk-actions .grp { display:flex; gap:4px; align-items:center; }
        .pk-actions button { height:28px; font-size:12px; border-radius:4px; border:1px solid #d98a33; cursor:pointer; padding:0 10px; }
        .pk-actions .b-add { background:#F0A24B; color:#fff; }
        .pk-actions .b-alt { background:#fff; color:#5b3a1e; border-color:#D8BE93; }
        .pk-actions input[type=number] { width:60px; height:28px; border:1px solid #D8BE93; border-radius:4px; padding:0 6px; }
        table.hist { width:100%; border-collapse:collapse; font-size:12px; }
        table.hist th, table.hist td { border:1px solid #EADFC8; padding:4px 6px; text-align:center; }
        table.hist thead th { background:#F7E0BD; color:#5b3a1e; }
        .va-noperm { margin:40px auto; max-width:520px; text-align:center; border:1.5px solid #E8D5B5; border-radius:10px;
            padding:30px; background:#FDF8EF; color:#5b3a1e; }
        @media print {
            .va-toolbar, .nav_menu, .left_col, footer, .va-role-badge .fa-question-circle, .va-op { display:none !important; }
            .right_col { margin:0 !important; padding:0 !important; }
            table.va-table thead th { position:static; }
        }
    </style>
</head>
<body class="nav-sm">
<div class="container body">
<div class="main_container">
    <?php include '../partPage/sideAndTopBarMenu.html' ?>
    <div class="right_col" role="main">
        <div class="page-title" style="display:flex;align-items:center;flex-wrap:wrap;">
            <h2 style="margin:6px 0;">供應商稽核管理
                <small style="color:#8a6d45;">KPI 2-GM-04-01 #6 廠商稽核按時執行率 來源頁（半年一期，每期挑一批對象）</small></h2>
        </div>
        <div class="clearfix"></div>

<?php if (!$perms['canView']): ?>
        <div class="va-noperm">
            <h4><i class="fa fa-lock"></i> 無供應商稽核檢閱權限</h4>
            <p>請洽管理者於「使用者權限設定」指派「稽核檢閱／登錄／管理員」角色。</p>
        </div>
<?php else: ?>
        <div class="va-toolbar">
            <label>年度</label>
            <select id="yearSel"></select>
            <label>期別</label>
            <select id="halfSel"><option value="1">上半年(1-6月)</option><option value="2">下半年(7-12月)</option></select>
            <button class="btn-warm" id="btnPick" style="display:none;"><i class="fa fa-plus"></i> 加入稽核對象</button>
            <button id="btnCycle" style="display:none;"><i class="fa fa-refresh"></i> 週期設定</button>
            <button id="btnCsv"><i class="fa fa-file-text-o"></i> 匯出CSV</button>
            <button onclick="window.print()"><i class="fa fa-print"></i> 列印</button>
            <span class="va-role-badge">目前角色：<b><?= htmlspecialchars($roleLabel) ?></b>
                <i class="fa fa-question-circle" id="btnRoleHelp" title="角色權限說明"></i></span>
        </div>

        <div class="va-stat" id="statBar">
            <div><span class="s-num" id="stDen">—</span> <span class="s-lab">本期應稽核（對象）</span></div>
            <div><span class="s-num" id="stNum">—</span> <span class="s-lab">已完成</span></div>
            <div><span class="s-num s-rate" id="stRate">—</span> <span class="s-lab">執行率（目標 ≥70%）</span></div>
            <div class="s-lab" id="stHint" style="margin-left:auto;"></div>
        </div>
        <div class="va-remind" id="remind"></div>

        <div class="va-table-wrap">
            <table class="va-table" id="vaTable">
                <thead><tr>
                    <th>廠商編號</th><th>廠商名稱</th><th>大類</th><th>稽核狀態</th>
                    <th>稽核日</th><th>綜合合格率</th><th>判定</th><th>稽核員</th><th>操作</th>
                </tr></thead>
                <tbody id="vaBody"><tr><td colspan="9" style="padding:20px;color:#8a6d45;">載入中…</td></tr></tbody>
            </table>
        </div>
        <div style="font-size:11px;color:#8a6d45;margin-top:4px;">
            每期(上/下半年)由「加入稽核對象」挑一批廠商（大類/加工項目篩選後多選，或隨機抽 N 家），逐一登錄稽核結果。
            KPI 執行率＝已完成 ÷ 本期對象數（<span class="st-pill st-dis">停用</span>廠商不列入）。
        </div>
<?php endif; ?>
    </div>
    <?php include '../partPage/footer.html' ?>
</div>
</div>

<!-- 廠商池挑選 modal -->
<div class="va-mask" id="pkMask"><div class="va-modal wide">
    <div class="m-head"><span id="pkTitle">加入稽核對象</span><span class="m-close" onclick="closeMask('pkMask')">✕</span></div>
    <div class="m-body">
        <div class="pk-filter">
            <label style="margin:0;font-size:12px;color:#5b3a1e;">大類</label>
            <select id="pkMain"><option value="">全部</option></select>
            <label style="margin:0;font-size:12px;color:#5b3a1e;">加工項目</label>
            <select id="pkSub"><option value="">全部</option></select>
            <input type="text" id="pkKw" placeholder="搜尋廠商名/編號" style="width:150px;">
            <label style="margin:0;font-size:12px;color:#5b3a1e;"><input type="checkbox" id="pkManagedOnly"> 只看納管</label>
            <button class="b-alt" style="height:28px;border:1px solid #D8BE93;border-radius:4px;background:#fff;cursor:pointer;" onclick="loadPool()">查詢</button>
            <span id="pkCount" style="font-size:12px;color:#8a6d45;"></span>
        </div>
        <div class="pk-list">
            <table class="pk-table">
                <thead><tr>
                    <th style="width:32px;"><input type="checkbox" id="pkAll"></th>
                    <th>編號</th><th>廠商名稱</th><th>大類</th><th>納管</th>
                </tr></thead>
                <tbody id="pkBody"><tr><td colspan="5" style="padding:14px;color:#8a6d45;">請設定條件後查詢</td></tr></tbody>
            </table>
        </div>
        <div class="pk-actions">
            <div class="grp"><button class="b-add" onclick="addSelected()"><i class="fa fa-check"></i> 加入選取</button></div>
            <div class="grp">隨機抽 <input type="number" id="pkRandN" min="1" step="1" value="5"> 家：
                <button class="b-add" onclick="randomDraw()"><i class="fa fa-random"></i> 抽取加入</button>
                <span style="font-size:11px;color:#8a6d45;">(自納管廠商)</span></div>
            <div class="grp" id="pkManageGrp" style="display:none;margin-left:auto;">
                <button class="b-alt" onclick="bulkManaged(1)">選取設為納管</button>
                <button class="b-alt" onclick="bulkManaged(0)">選取取消納管</button>
            </div>
        </div>
    </div>
    <div class="m-foot">
        <button class="b-cancel" onclick="closeMask('pkMask')">關閉</button>
    </div>
</div></div>

<!-- 稽核評鑑表單 modal（簡版15項 2-PH-01-02/03） -->
<div class="va-mask" id="recMask"><div class="va-modal xwide">
    <div class="m-head"><span id="recTitle">稽核評鑑表單</span><span class="m-close" onclick="closeMask('recMask')">✕</span></div>
    <div class="m-body">
        <div class="af-head">
            <div><label>稽核日期（留空=尚未稽核）</label><input type="date" id="recDate"></div>
            <div><label>稽核狀況</label><select id="recMode">
                <option value="first">首次稽核</option><option value="again">次稽核</option><option value="self">自我評量</option>
            </select></div>
            <div><label>稽核員</label><input type="text" id="recAuditor" maxlength="50"></div>
            <div><label>供應商代表</label><input type="text" id="recRep" maxlength="50"></div>
            <div><label>自評人員</label><input type="text" id="recSelfEval" maxlength="50"></div>
            <div><label>報告編號</label><input type="text" id="recReport" maxlength="50"></div>
        </div>
        <div style="font-size:11px;color:#8a6d45;margin:6px 0;">
            每項自評/稽核各評 0~7 分（0=最差、7=最佳）；綜合合格率＝自評率×0.3＋稽核率×0.7，<b>≥75% 判合格</b>。
        </div>
        <div class="af-table-wrap">
            <table class="af-table" id="afTable"><tbody id="afBody"></tbody></table>
        </div>
        <div class="af-summary" id="afSummary"></div>
        <div class="grid2" style="margin-top:8px;">
            <div><label>建議評鑑結果（結論）</label><select id="recConclusion">
                <option value="">—</option>
                <option value="合格">合格供應商</option>
                <option value="回覆改善後合格">回覆稽核改善對策後合格</option>
                <option value="需重新稽核">有嚴重缺失，改善後需重新稽核</option>
                <option value="其他">其他</option>
            </select></div>
            <div><label>備註</label><input type="text" id="recNote" maxlength="200"></div>
        </div>
    </div>
    <div class="m-foot">
        <button class="b-cancel" onclick="closeMask('recMask')">取消</button>
        <button class="b-ok" onclick="submitRec()">儲存</button>
    </div>
</div></div>

<!-- 週期設定 modal -->
<div class="va-mask" id="cycMask"><div class="va-modal">
    <div class="m-head"><span>共用稽核週期設定</span><span class="m-close" onclick="closeMask('cycMask')">✕</span></div>
    <div class="m-body">
        <label>稽核週期（月）—— 全公司共用，作為「多久辦一期」的參考與提醒</label>
        <input type="number" id="cycVal" step="1" min="1" style="width:120px;">
        <div style="font-size:12px;color:#8a6d45;margin-top:8px;">例：6＝每半年一期。此值僅供提醒，不會自動改變各期對象。</div>
    </div>
    <div class="m-foot">
        <button class="b-cancel" onclick="closeMask('cycMask')">取消</button>
        <button class="b-ok" onclick="submitCycle()">儲存</button>
    </div>
</div></div>

<!-- 歷史 modal -->
<div class="va-mask" id="hisMask"><div class="va-modal wide">
    <div class="m-head"><span id="hisTitle">稽核歷史</span><span class="m-close" onclick="closeMask('hisMask')">✕</span></div>
    <div class="m-body" id="hisBody" style="font-size:13px;color:#5b3a1e;"></div>
</div></div>

<!-- 角色說明 modal -->
<div class="va-mask" id="helpMask"><div class="va-modal">
    <div class="m-head"><span>角色權限說明</span><span class="m-close" onclick="closeMask('helpMask')">✕</span></div>
    <div class="m-body" style="font-size:13px;color:#5b3a1e;line-height:1.8;">
        <b>稽核檢閱</b>：檢視本期對象/稽核歷史/統計與匯出。<br>
        <b>稽核登錄</b>：檢閱＋加入/移除本期對象、登錄稽核結果。<br>
        <b>稽核管理員</b>：登錄＋設定廠商納管、共用週期。<br>
        <b>管理者</b>：系統管理者固定擁有全部權限。<br>
        <hr style="border-color:#EADFC8;">
        本頁為 KPI「廠商稽核按時執行率(#6)」來源；每期對象由本頁挑選，執行率＝已完成÷對象數。停用廠商不列入。
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

var API = '../../src/store/VendorAudit_API.php';
var META = null, TARGETS = [], PERMS = null, POOL = [];
var canView = <?= $perms['canView'] ? 'true' : 'false' ?>;
var RESULT_LABEL = {pass:'合格', conditional:'限期改善', fail:'不合格'};

function closeMask(id){ document.getElementById(id).style.display='none'; }
function openMask(id){ document.getElementById(id).style.display='block'; }
function esc(s){ return String(s==null?'':s).replace(/[&<>"']/g,function(c){
    return {'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#39;'}[c]; }); }
function fmtDate(d){ return d ? String(d).substr(0,10) : ''; }

function loadMeta(cb){
    $.getJSON(API, {action:'meta'}, function(m){
        if (!m.ok){ alert(m.error||'載入失敗'); return; }
        META = m; PERMS = m.perms;
        var $y = $('#yearSel').empty(), cy = m.cur_year;
        for (var y=cy+1; y>=cy-4; y--) $y.append('<option value="'+y+'">'+y+'</option>');
        $y.val(cy);
        $('#halfSel').val(m.cur_half);
        var opt = '<option value="">全部</option>';
        m.main_categories.forEach(function(c){ opt += '<option value="'+c.main_cat_id+'">'+esc(c.main_cat_name)+'</option>'; });
        $('#pkMain').html(opt);
        if (m.perms.canEdit) $('#btnPick').show();
        if (m.perms.canAdmin){ $('#btnCycle').show(); $('#pkManageGrp').show(); }
        if (cb) cb();
    });
}

function loadRound(){
    NProgress.start();
    $.getJSON(API, {action:'round', year:$('#yearSel').val(), half:$('#halfSel').val()}, function(res){
        NProgress.done();
        if (!res.ok){ alert(res.error||'載入失敗'); return; }
        TARGETS = res.targets; PERMS = res.perms;
        renderStat(res); renderRemind(res); renderTargets();
    }).fail(function(x){ NProgress.done(); alert('載入失敗：'+(x.responseJSON&&x.responseJSON.error||x.status)); });
}

function renderStat(res){
    var lab = res.year+' '+(res.half===1?'上半年':'下半年');
    $('#stDen').text(res.stat.den); $('#stNum').text(res.stat.num);
    var $r = $('#stRate');
    if (res.stat.value === null){ $r.text('—').removeClass('below ok'); $('#stHint').text(lab+(res.round_exists?'：尚無對象或全為停用':'：本期尚未建立')); }
    else { var v = Math.round(res.stat.value*10)/10;
        $r.text(v+'%').toggleClass('below', v<70).toggleClass('ok', v>=70);
        $('#stHint').text(lab+'：'+res.stat.num+' / '+res.stat.den+' 已完成'); }
}
function renderRemind(res){
    var h = '共用稽核週期：<b>'+res.cycle_months+'</b> 月';
    if (res.last_round) h += '　｜　最近一期：'+res.last_round.year+' '+(res.last_round.half==1?'上半年':'下半年');
    $('#remind').html(h);
}

function renderTargets(){
    var html = '';
    TARGETS.forEach(function(t){
        var done = !!t.audit_date;
        var stat = t.disabled ? '<span class="st-pill st-dis">停用</span>'
                 : (done ? '<span class="st-pill st-done">已完成</span>' : '<span class="st-pill st-todo">未稽核</span>');
        html += '<tr'+(t.disabled?' class="dis"':'')+'>';
        html += '<td>'+esc(t.maker_id_no)+'</td>';
        html += '<td class="t-left"><b>'+esc(t.maker_id||'')+'</b></td>';
        html += '<td>'+esc(t.main_cat_name||'—')+'</td>';
        html += '<td>'+stat+'</td>';
        html += '<td>'+(fmtDate(t.audit_date)||'—')+'</td>';
        html += '<td>'+(t.overall_rate==null?'—':t.overall_rate+'%')+'</td>';
        html += '<td>'+(t.judge?(t.judge==='pass'?'<span class="af-judge-pass">合格</span>':'<span class="af-judge-fail">不合格</span>'):'—')+'</td>';
        html += '<td>'+esc(t.auditor||'—')+'</td>';
        html += '<td>';
        if (PERMS.canEdit) html += '<span class="va-op" onclick="openRec('+t.target_id+')"><i class="fa fa-pencil"></i>登錄</span>';
        html += '<span class="va-op" onclick="openHis(\''+esc(t.maker_id_no)+'\')"><i class="fa fa-history"></i>歷史</span>';
        if (PERMS.canEdit) html += '<span class="va-op" style="color:#DD5138;" onclick="removeTarget('+t.target_id+')"><i class="fa fa-times"></i>移除</span>';
        html += '</td></tr>';
    });
    $('#vaBody').html(html || '<tr><td colspan="9" style="padding:16px;color:#8a6d45;">本期尚無稽核對象，請按「加入稽核對象」挑選</td></tr>');
}

$('#yearSel,#halfSel').on('change', loadRound);

/* ---------- 廠商池挑選 ---------- */
$('#btnPick').on('click', function(){
    $('#pkTitle').text('加入稽核對象（'+$('#yearSel').val()+' '+($('#halfSel').val()==1?'上半年':'下半年')+'）');
    $('#pkBody').html('<tr><td colspan="5" style="padding:14px;color:#8a6d45;">請設定條件後查詢</td></tr>');
    $('#pkCount').text(''); $('#pkAll').prop('checked', false);
    openMask('pkMask');
    loadPool();
});
$('#pkMain').on('change', function(){
    var mc = $(this).val();
    var $s = $('#pkSub').html('<option value="">全部</option>');
    if (mc){ $.getJSON(API, {action:'subcats', main_cat_id:mc}, function(res){
        if (res.ok) res.subcats.forEach(function(s){ $s.append('<option value="'+s.sub_cat_id+'">'+esc(s.sub_cat_name)+'</option>'); });
    }); }
    loadPool();
});
$('#pkSub,#pkManagedOnly').on('change', loadPool);
$('#pkKw').on('keydown', function(e){ if (e.key==='Enter') loadPool(); });
$('#pkAll').on('change', function(){ $('#pkBody input.pk-ck').prop('checked', this.checked); });

function loadPool(){
    NProgress.start();
    $.getJSON(API, {action:'pool', year:$('#yearSel').val(), half:$('#halfSel').val(),
        main_cat_id:$('#pkMain').val()||0, sub_cat_id:$('#pkSub').val()||0,
        kw:$('#pkKw').val(), managed_only:$('#pkManagedOnly').is(':checked')?1:0}, function(res){
        NProgress.done();
        if (!res.ok){ alert(res.error||'載入失敗'); return; }
        POOL = res.pool;
        $('#pkCount').text('符合 '+POOL.length+' 家'+(res.capped?'（僅顯示前 500，請縮小條件）':''));
        var html = '';
        POOL.forEach(function(p){
            html += '<tr>';
            html += '<td><input type="checkbox" class="pk-ck" value="'+esc(p.maker_id_no)+'"></td>';
            html += '<td>'+esc(p.maker_id_no)+'</td>';
            html += '<td class="t-left">'+esc(p.maker_id||'')+'</td>';
            html += '<td>'+esc(p.main_cat_name||'—')+'</td>';
            html += '<td class="'+(p.audit_managed===1?'mg-yes':'mg-no')+'">'+(p.audit_managed===1?'✔':'—')+'</td>';
            html += '</tr>';
        });
        $('#pkBody').html(html || '<tr><td colspan="5" style="padding:14px;color:#8a6d45;">無符合條件的廠商</td></tr>');
        $('#pkAll').prop('checked', false);
    }).fail(function(x){ NProgress.done(); alert('載入失敗：'+(x.responseJSON&&x.responseJSON.error||x.status)); });
}
function pkChecked(){ return $('#pkBody input.pk-ck:checked').map(function(){ return this.value; }).get(); }

function addSelected(){
    var ids = pkChecked();
    if (!ids.length){ alert('請勾選要加入的廠商'); return; }
    $.post(API, {action:'add_targets', year:$('#yearSel').val(), half:$('#halfSel').val(), maker_ids:ids.join(',')},
    function(res){
        if (!res.ok){ alert(res.error||'加入失敗'); return; }
        alert('已加入 '+res.added+' 家'); loadPool(); loadRound();
    }, 'json').fail(function(x){ alert('加入失敗：'+(x.responseJSON&&x.responseJSON.error||x.status)); });
}
function randomDraw(){
    var n = parseInt($('#pkRandN').val(),10);
    if (!n || n<1){ alert('請輸入抽取家數'); return; }
    $.post(API, {action:'random_targets', year:$('#yearSel').val(), half:$('#halfSel').val(), n:n,
        main_cat_id:$('#pkMain').val()||0, sub_cat_id:$('#pkSub').val()||0}, function(res){
        if (!res.ok){ alert(res.error||'抽取失敗'); return; }
        alert('已隨機加入 '+res.added+' 家'+(res.note?'\n'+res.note:'')); loadPool(); loadRound();
    }, 'json').fail(function(x){ alert('抽取失敗：'+(x.responseJSON&&x.responseJSON.error||x.status)); });
}
function bulkManaged(v){
    var ids = pkChecked();
    if (!ids.length){ alert('請勾選廠商'); return; }
    $.post(API, {action:'set_managed', maker_ids:ids.join(','), managed:v}, function(res){
        if (!res.ok){ alert(res.error||'設定失敗'); return; }
        loadPool();
    }, 'json').fail(function(x){ alert('設定失敗：'+(x.responseJSON&&x.responseJSON.error||x.status)); });
}

/* ---------- 稽核評鑑表單 ---------- */
var recTid = null;
function scoreOptions(v){ var o='<option value="">-</option>'; for(var i=0;i<=META.item_max;i++) o+='<option value="'+i+'"'+(String(v)===String(i)?' selected':'')+'>'+i+'</option>'; return o; }
function openRec(tid){
    recTid = tid;
    $.getJSON(API, {action:'get_form', target_id:tid}, function(res){
        if(!res.ok){ alert(res.error||'載入失敗'); return; }
        var t = res.target;
        $('#recTitle').text('稽核評鑑表單：'+t.maker_id+'（'+t.maker_id_no+'）');
        $('#recDate').val(fmtDate(t.audit_date)||META.today);
        $('#recMode').val(t.audit_mode||'first');
        $('#recAuditor').val(t.auditor||''); $('#recRep').val(t.supplier_rep||'');
        $('#recSelfEval').val(t.self_evaluator||''); $('#recReport').val(t.report_no||'');
        $('#recConclusion').val(t.conclusion||''); $('#recNote').val(t.note||'');
        renderForm(t.scores||{});
        openMask('recMask');
    });
}
function renderForm(scores){
    var html='';
    META.items.forEach(function(cat){
        html+='<tr class="af-cat"><td class="af-q">'+esc(cat[1])+'</td><td class="af-sc">自評</td><td class="af-sc">稽核</td></tr>';
        cat[2].forEach(function(it){
            var iid=it[0], s=scores[iid]||{};
            html+='<tr data-iid="'+iid+'">';
            html+='<td class="af-q">'+iid+'. '+esc(it[1])+'</td>';
            html+='<td class="af-sc"><select class="af-self" onchange="recompute()">'+scoreOptions(s.self)+'</select></td>';
            html+='<td class="af-sc"><select class="af-audit" onchange="recompute()">'+scoreOptions(s.audit)+'</select></td>';
            html+='</tr>';
        });
    });
    $('#afBody').html(html);
    recompute();
}
function collectScores(){
    var scores={};
    $('#afBody tr[data-iid]').each(function(){
        var iid=$(this).data('iid'), self=$(this).find('.af-self').val(), audit=$(this).find('.af-audit').val();
        if(self!==''||audit!=='') scores[iid]={self:self===''?null:+self, audit:audit===''?null:+audit};
    });
    return scores;
}
function recompute(){
    var MAXI=META.item_max, pass=META.pass_rate, sw=META.self_w, aw=META.audit_w, scores=collectScores();
    var rows='<table><tr><th>分類</th><th>滿分</th><th>自評分</th><th>稽核分</th><th>自評率</th><th>稽核率</th></tr>';
    var tSelf=0,tAudit=0,tMax=0;
    META.items.forEach(function(cat){
        var items=cat[2], cMax=items.length*MAXI, cSelf=0, cAudit=0;
        items.forEach(function(it){ var s=scores[it[0]]||{}; cSelf+=(s.self||0); cAudit+=(s.audit||0); });
        tSelf+=cSelf; tAudit+=cAudit; tMax+=cMax;
        rows+='<tr><td>'+esc(cat[1])+'</td><td>'+cMax+'</td><td>'+cSelf+'</td><td>'+cAudit+'</td><td>'+(cMax?Math.round(cSelf/cMax*1000)/10:0)+'%</td><td>'+(cMax?Math.round(cAudit/cMax*1000)/10:0)+'%</td></tr>';
    });
    var selfR=tMax?Math.round(tSelf/tMax*1000)/10:0, auditR=tMax?Math.round(tAudit/tMax*1000)/10:0;
    var overall=Math.round((selfR*sw+auditR*aw)*10)/10, ok=overall>=pass;
    rows+='<tr class="af-total"><td>總成績</td><td>'+tMax+'</td><td>'+tSelf+'</td><td>'+tAudit+'</td><td>'+selfR+'%</td><td>'+auditR+'%</td></tr></table>';
    rows+='<div style="margin-top:6px;">綜合合格率（自評×'+sw+'＋稽核×'+aw+'）：<b style="font-size:15px;">'+overall+'%</b>　判定：'
        +(ok?'<span class="af-judge-pass">合格 (≥'+pass+'%)</span>':'<span class="af-judge-fail">不合格 (<'+pass+'%)</span>')+'</div>';
    $('#afSummary').html(rows);
}
function submitRec(){
    var scores=collectScores();
    $.post(API, {action:'record_target', target_id:recTid, audit_date:$('#recDate').val(),
        audit_mode:$('#recMode').val(), auditor:$('#recAuditor').val(), supplier_rep:$('#recRep').val(),
        self_evaluator:$('#recSelfEval').val(), report_no:$('#recReport').val(),
        conclusion:$('#recConclusion').val(), note:$('#recNote').val(), scores:JSON.stringify(scores)},
    function(res){
        if(!res.ok){ alert(res.error||'儲存失敗'); return; }
        closeMask('recMask'); loadRound();
    }, 'json').fail(function(x){ alert('儲存失敗：'+(x.responseJSON&&x.responseJSON.error||x.status)); });
}
function removeTarget(tid){
    if (!confirm('將此廠商移出本期稽核對象？')) return;
    $.post(API, {action:'remove_target', target_id:tid}, function(res){
        if (!res.ok){ alert(res.error||'移除失敗'); return; }
        loadRound();
    }, 'json');
}

/* ---------- 週期設定 ---------- */
$('#btnCycle').on('click', function(){ $('#cycVal').val(META.cycle_months); openMask('cycMask'); });
function submitCycle(){
    $.post(API, {action:'save_cycle', cycle_months:$('#cycVal').val()}, function(res){
        if (!res.ok){ alert(res.error||'儲存失敗'); return; }
        META.cycle_months = res.cycle_months; closeMask('cycMask'); loadRound();
    }, 'json');
}

/* ---------- 歷史 ---------- */
function openHis(mid){
    $.getJSON(API, {action:'vendor_history', maker_id_no:mid}, function(res){
        if (!res.ok){ alert(res.error||'載入失敗'); return; }
        $('#hisTitle').text('稽核歷史：'+res.maker_name+'（'+mid+'）');
        if (!res.list.length){ $('#hisBody').html('<div style="color:#8a6d45;padding:12px;">尚無稽核紀錄</div>'); openMask('hisMask'); return; }
        var h = '<table class="hist"><thead><tr><th>期別</th><th>稽核日</th><th>綜合合格率</th><th>判定</th><th>稽核員</th><th>報告編號</th><th>備註</th></tr></thead><tbody>';
        res.list.forEach(function(a){
            h += '<tr><td>'+a.year+' '+(a.half==1?'上半年':'下半年')+'</td>';
            h += '<td>'+(fmtDate(a.audit_date)||'未稽核')+'</td>';
            h += '<td>'+(a.overall_rate==null?'—':a.overall_rate+'%')+'</td>';
            h += '<td>'+(a.judge?(a.judge==='pass'?'<span class="af-judge-pass">合格</span>':'<span class="af-judge-fail">不合格</span>'):'—')+'</td>';
            h += '<td>'+esc(a.auditor||'—')+'</td>';
            h += '<td>'+esc(a.report_no||'—')+'</td><td>'+esc(a.note||'—')+'</td></tr>';
        });
        h += '</tbody></table>';
        $('#hisBody').html(h); openMask('hisMask');
    });
}

/* ---------- CSV ---------- */
$('#btnCsv').on('click', function(){
    var rows = [['廠商編號','廠商名稱','大類','稽核狀態','稽核日','綜合合格率','判定','稽核員','報告編號','備註']];
    TARGETS.forEach(function(t){
        rows.push([t.maker_id_no, t.maker_id||'', t.main_cat_name||'',
            t.disabled?'停用':(t.audit_date?'已完成':'未稽核'), fmtDate(t.audit_date),
            t.overall_rate==null?'':t.overall_rate+'%', t.judge?(t.judge==='pass'?'合格':'不合格'):'',
            t.auditor||'', t.report_no||'', t.note||'']);
    });
    var csv = '﻿' + rows.map(function(l){
        return l.map(function(v){ return '"'+String(v==null?'':v).replace(/"/g,'""')+'"'; }).join(',');
    }).join('\r\n');
    var a = document.createElement('a');
    a.href = URL.createObjectURL(new Blob([csv], {type:'text/csv;charset=utf-8;'}));
    a.download = '供應商稽核_'+$('#yearSel').val()+'_H'+$('#halfSel').val()+'.csv';
    a.click();
});

$('#btnRoleHelp').on('click', function(){ openMask('helpMask'); });
$('.va-mask').on('click', function(e){ if (e.target===this) this.style.display='none'; });
$(document).on('focus', '.va-modal input[type=text], .va-modal input[type=number]', function(){ this.select(); });
$(document).on('dblclick', '.va-modal input[type=text], .va-modal input[type=number]', function(){ this.value=''; });

if (canView) loadMeta(function(){ loadRound(); });
</script>
</body>
</html>
