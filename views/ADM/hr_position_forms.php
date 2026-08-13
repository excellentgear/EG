<?php
/**
 * 人資職務表單 — 職務說明書／專業技能鑑定考核表／員工職能鑑定表（三分頁同一頁）—— 2026-08-13 新增
 * 範本/白名單/部門設定請至「人資職務表單設定」hr_position_forms_template.php（僅管理員）。
 * 資料一律走 src/store/HrForm_API.php；權限 src/common/hr_form_lib.php hrf_perms()
 */
session_start();
if (!isset($_SESSION['userName'])) {
    $_SESSION['lastpage'] = "../../views/ADM/hr_position_forms.php";
    header("Location:../../index.php");
    exit;
}
include_once '../../src/common/_config.php';
include_once '../../src/common/DBConnection.php';
include_once '../../src/common/hr_form_lib.php';

$db = (new DBConnection())->getPDO();
$hrfUser = hrf_current_user($db);
$perms = hrf_perms($db, $hrfUser);
?>
<!DOCTYPE html>
<html lang="zh-Hant">
<head>
    <meta charset="utf-8">
    <meta http-equiv="X-UA-Compatible" content="IE=edge">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>人資職務表單</title>
    <link href="../../resource/css/bootstrap.css" rel="stylesheet">
    <link href="../../resource/css/font-awesome.css" rel="stylesheet">
    <link href="../../resource/css/nprogress.css" rel="stylesheet">
    <link href="../../resource/css/custom.css" rel="stylesheet">
    <style>
        #sidebar-menu { visibility: hidden; }
        .right_col .page-title { margin:8px 0 4px; overflow:hidden; clear:both; }
        .hf-toolbar { display:flex; flex-wrap:wrap; gap:6px; align-items:center; clear:both;
            border:1.5px solid #E8D5B5; border-radius:8px; padding:8px 10px; margin-bottom:10px; background:#FDF8EF; }
        .hf-toolbar input[type=text], .hf-toolbar select, .hf-toolbar button { height:30px; font-size:13px; padding:0 12px; border:1px solid #D8BE93; border-radius:4px;
            background:#fff; color:#5b3a1e; }
        .hf-toolbar button { cursor:pointer; }
        .hf-toolbar .btn-warm { background:#F0A24B; color:#fff; border-color:#d98a33; }
        .hf-toolbar .btn-danger { background:#DD5138; color:#fff; border-color:#c23f28; }
        .page-help-btn { margin-left:auto; height:30px; font-size:13px; padding:0 14px; border:1px solid #D8BE93;
            border-radius:4px; background:#fff; color:#5b3a1e; cursor:pointer; }
        .help-doc h4 { font-size:14px; color:#8A5A2B; margin:10px 0 4px; }
        .hf-asdoc { font-size:12px; color:#8a6d45; margin-left:6px; }
        table.hf-tbl { width:100%; border-collapse:collapse; font-size:13px; background:#fff; }
        table.hf-tbl th, table.hf-tbl td { border:1px solid #EADFC8; padding:6px 8px; }
        table.hf-tbl thead th { background:#F7E0BD; color:#5b3a1e; }
        .hf-table-wrap { overflow-x:auto; border:1px solid #E8D5B5; border-radius:6px; }
        .st-badge { border-radius:10px; padding:2px 9px; font-size:11.5px; color:#fff; white-space:nowrap; }
        .st-draft,.st-active{background:#b0a390;} .st-confirming,.st-approving{background:#F0A24B;} .st-signed{background:#3f9142;} .st-rejected{background:#DD5138;}
        .hf-mask { display:none; position:fixed; inset:0; background:rgba(60,40,20,.45); z-index:1050; overflow-y:auto; }
        .hf-modal { background:#fff; border-radius:8px; max-width:1040px; margin:24px auto; box-shadow:0 5px 25px rgba(0,0,0,.3); }
        #viewMask .hf-modal { max-width:min(1100px, 94vw); }
        .hf-modal .m-head { background:#F7E0BD; color:#5b3a1e; font-weight:bold; padding:10px 15px; border-radius:8px 8px 0 0;
            display:flex; justify-content:space-between; position:sticky; top:0; }
        .hf-modal .m-head .m-close { cursor:pointer; color:#b5762a; }
        .hf-modal .m-body { padding:15px; }
        .hf-modal .m-body label { display:block; font-size:13px; color:#5b3a1e; margin:9px 0 3px; }
        .hf-modal .m-body input[type=text], .hf-modal .m-body input[type=date], .hf-modal .m-body select, .hf-modal .m-body textarea
            { border:1px solid #D8BE93; border-radius:4px; padding:5px 8px; font-size:13px; box-sizing:border-box; width:100%; }
        .hf-modal .m-foot { padding:10px 15px; border-top:1px solid #EADFC8; text-align:right; }
        .hf-modal .m-foot button { height:30px; padding:0 16px; border-radius:4px; font-size:13px; border:1px solid #d98a33; cursor:pointer; margin-left:6px; }
        .hf-modal .m-foot .b-ok { background:#F0A24B; color:#fff; }
        .hf-modal .m-foot .b-cancel { background:#fff; color:#5b3a1e; border-color:#D8BE93; }
        .hf-modal .m-foot .b-danger { background:#DD5138; color:#fff; border-color:#c23f28; }
        table.itm-tbl { width:100%; border-collapse:collapse; font-size:12.5px; margin-bottom:8px; }
        table.itm-tbl th, table.itm-tbl td { border:1px solid #EADFC8; padding:5px 6px; vertical-align:top; }
        table.itm-tbl thead th { background:#F7E0BD; color:#5b3a1e; }
        table.itm-tbl textarea { width:100%; min-height:44px; border:1px solid #D8BE93; border-radius:4px; padding:4px 6px; font-size:12.5px; box-sizing:border-box; }
        table.itm-tbl select { width:100%; border:1px solid #D8BE93; border-radius:4px; padding:3px 5px; font-size:12px; box-sizing:border-box; }
        .hf-people-pick { border:1px solid #D8BE93; border-radius:6px; padding:6px; }
        .hf-people-pick .flt { width:100%; border:1px solid #D8BE93; border-radius:4px; padding:5px 8px; font-size:12.5px; margin-bottom:6px; box-sizing:border-box; }
        .hf-people-list { max-height:220px; overflow-y:auto; }
        .hf-people-list label { display:block; font-size:12.5px; padding:2px 4px; margin:0; cursor:pointer; }
        .hf-people-list label:hover { background:#FBF0DD; }
        .hf-people-list .leave-tag { color:#DD5138; font-size:11px; }
        .hf-machine-pick { max-height:200px; overflow-y:auto; border:1px solid #D8BE93; border-radius:6px; padding:6px; margin-top:4px; }
        .hf-machine-pick label { display:block; font-size:12.5px; padding:2px 4px; cursor:pointer; }
        .hf-radio-row label { display:inline-block; margin-right:14px; font-weight:normal; font-size:13px; }
        .decide-box { border:1.5px solid #E8D5B5; border-radius:8px; padding:10px; margin-top:10px; background:#FDF8EF; }
        .hf-score-tbl td input[type=number] { width:56px; text-align:center; }
        .nav-hf { margin:0 0 10px; }
        .nav-hf > li > a { color:#5b3a1e; }
        .nav-hf > li.active > a { color:#8A5A2B; font-weight:bold; border-color:#E8D5B5 #E8D5B5 #fff; }
        .hf-tabpane { display:none; }
        .hf-tabpane.active { display:block; }
        .err-list { color:#DD5138; font-size:12px; margin-top:6px; }
        @media print { .page-help-btn { display:none; } }
    </style>
</head>
<body class="nav-sm">
<div class="container body">
<div class="main_container">
    <?php include '../partPage/sideAndTopBarMenu.html' ?>
    <div class="right_col" role="main">
        <div class="page-title" style="display:flex;align-items:center;flex-wrap:wrap;clear:both;">
            <h2 style="margin:6px 0;">人資職務表單 <small style="color:#8a6d45;">職務說明書／專業技能鑑定考核表／員工職能鑑定表</small></h2>
            <button class="page-help-btn" id="btnPageHelp"><i class="fa fa-question-circle"></i> 使用說明</button>
        </div>
        <div class="clearfix"></div>

<?php if (!$perms['canView']): ?>
        <div><h4><i class="fa fa-lock"></i> 無人資職務表單檢閱權限</h4><p>請洽系統管理者於「使用者權限設定」指派「人資職務表單」相關角色。</p></div>
<?php else: ?>
        <ul class="nav nav-tabs nav-hf" id="hfTabs">
            <li class="active"><a href="#" data-type="job_desc">職務說明書</a></li>
            <li><a href="#" data-type="skill_assess">專業技能鑑定考核表</a></li>
            <li><a href="#" data-type="competency">員工職能鑑定表</a></li>
        </ul>

<?php foreach (['job_desc','skill_assess','competency'] as $ft): ?>
        <div class="hf-tabpane<?= $ft==='job_desc'?' active':'' ?>" id="pane-<?= $ft ?>" data-type="<?= $ft ?>">
            <div class="hf-toolbar">
                <input type="text" class="kw" placeholder="搜尋部門/職位/姓名…" style="width:200px;">
                <button class="btn-warm btn-create"><i class="fa fa-plus"></i> 建立表單</button>
                <button class="btn-print-all"><i class="fa fa-print"></i> 列印全部</button>
                <?php if ($ft !== 'job_desc'): ?>
                <select class="st-filter" style="width:120px;">
                    <option value="">狀態：全部</option>
                    <option value="draft">草稿</option>
                    <option value="confirming">確認中</option>
                    <option value="approving">核准中</option>
                    <option value="signed">已完成</option>
                    <option value="rejected">已退回</option>
                </select>
                <button class="btn-auto-sign" style="display:none;background:#8A5A2B;color:#fff;"><i class="fa fa-magic"></i> 超管自動簽核</button>
                <?php endif; ?>
                <span class="hf-asdoc"></span>
                <a href="hr_position_forms_template.php" class="admin-only" style="display:none;margin-left:auto;height:30px;line-height:28px;padding:0 12px;border:1px solid #D8BE93;border-radius:4px;color:#5b3a1e;text-decoration:none;">範本管理→</a>
            </div>
            <div class="hf-table-wrap">
            <table class="hf-tbl">
                <thead class="thead-<?= $ft ?>"></thead>
                <tbody class="list-body"><tr><td colspan="9" style="text-align:center;color:#8a6d45;">載入中…</td></tr></tbody>
            </table>
            </div>
        </div>
<?php endforeach; ?>
<?php endif; ?>
    </div>
</div>
</div>

<!-- 建立/批次建立 modal -->
<div class="hf-mask" id="createMask"><div class="hf-modal" style="max-width:640px;">
    <div class="m-head"><span id="createTitle">建立表單</span><span class="m-close" onclick="closeMask('createMask')">✕</span></div>
    <div class="m-body">
        <label>選擇員工（可複選；選 1 人＝單人建立，選多人＝批次建立）</label>
        <div class="hf-people-pick">
            <input type="text" class="flt" placeholder="輸入姓名/部門/職稱篩選…" oninput="hfFilterPeople(this)">
            <div class="hf-people-list" id="createPeopleList"></div>
        </div>
        <label>業務日期（建立日期，可自行指定以利補登舊資料）</label>
        <input type="date" id="createBizDate" max="9999-12-31">
        <div id="createMachineBlock" style="display:none;">
            <label>機型來源</label>
            <div class="hf-radio-row">
                <label><input type="radio" name="mSrc" value="tpl" checked onchange="hfToggleMachineSrc()"> 依各員工職位範本自動帶入（預設）</label>
                <label><input type="radio" name="mSrc" value="manual" onchange="hfToggleMachineSrc()"> 手動指定機型（套用到全部選取員工）</label>
            </div>
            <div class="hf-machine-pick" id="createMachineList" style="display:none;"></div>
        </div>
        <div class="err-list" id="createErrList"></div>
    </div>
    <div class="m-foot"><button class="b-cancel" onclick="closeMask('createMask')">取消</button><button class="b-ok" onclick="hfSubmitCreate()">建立</button></div>
</div></div>

<!-- 檢視/編輯/簽核 modal -->
<div class="hf-mask" id="viewMask"><div class="hf-modal">
    <div class="m-head"><span id="viewTitle">表單</span><span class="m-close" onclick="closeMask('viewMask')">✕</span></div>
    <div class="m-body" id="viewBody"></div>
</div></div>

<!-- 超管自動簽核 modal -->
<div class="hf-mask" id="autoSignMask"><div class="hf-modal" style="max-width:440px;">
    <div class="m-head"><span>超級管理員自動簽核</span><span class="m-close" onclick="closeMask('autoSignMask')">✕</span></div>
    <div class="m-body">
        <p style="font-size:12.5px;color:#8a6d45;">已勾選 <b id="autoSignCount">0</b> 筆表單，將把尚未完成的確認/核准關卡一次補簽。用於補登舊資料，請謹慎使用。</p>
        <label>操作確認密碼</label>
        <input type="password" id="autoSignPwd">
        <label>簽核日期（決行時間會在此日期內隨機錯開，不跨天）</label>
        <input type="date" id="autoSignDate" max="9999-12-31">
        <div class="err-list" id="autoSignErr"></div>
    </div>
    <div class="m-foot"><button class="b-cancel" onclick="closeMask('autoSignMask')">取消</button><button class="b-ok" onclick="hfSubmitAutoSign()">執行</button></div>
</div></div>

<!-- 使用說明 modal（鐵律7） -->
<div class="hf-mask" id="helpUseMask"><div class="hf-modal" style="max-width:780px;">
    <div class="m-head"><span>使用說明 — 人資職務表單</span><span class="m-close" onclick="closeMask('helpUseMask')">✕</span></div>
    <div class="m-body help-doc" style="font-size:13px;color:#5b3a1e;line-height:1.8;">
        <h4>功能說明</h4>
        三張固定表單：<b>職務說明書</b>（每位員工一份，內容依職位範本帶入，不需簽核，僅留記錄）、<b>專業技能鑑定考核表</b>（每位員工「每個機型」一份，總經理／課長各自評分，需確認＋核准）、<b>員工職能鑑定表</b>（每位員工一份，職能項目依職位範本帶入，需確認＋核准）。三張表單各自獨立綁定 AS 文件編號。
        <h4>操作步驟</h4>
        <b>①建立表單</b>：勾選一位或多位員工＋指定業務日期即可建立（勾 1 人＝單人建立，勾多人＝批次建立）；系統會依該員工當下的部門×職位比對「職位範本」自動帶入內容，找不到範本會在建立結果顯示錯誤，需請管理員先到「範本管理」設定。專業技能鑑定考核表另需選機型（預設依職位範本的適用機型清單自動展開成多筆，也可手動指定機型套用到所有選取員工）。<br>
        <b>②填寫／評分</b>：職務說明書內容欄可直接編輯存檔；技能鑑定表由課長／總經理各自在「確認」「核准」時填寫自己那欄分數；職能鑑定表的操作/異常排除評分由確認人（直屬主管）填寫。<br>
        <b>③送出</b>：技能鑑定表／職能鑑定表草稿建立後需按「送出」才會通知確認人（該員工直屬主管）；確認通過後自動通知核准人（總經理）；任一關退回都需填寫原因，退回後表單回到草稿可修改重送。<br>
        <b>④複製表單</b>：任何表單都可按「複製」，以複製者身分建立一份新草稿（機型/內容原樣帶入），需重新走送出流程。<br>
        <b>⑤列印</b>：可單筆列印，或「列印全部」依目前清單篩選結果批次列印（每位員工自己一頁，批次列印不顯示頁碼）。<br>
        <b>⑥超級管理員自動簽核</b>：僅 id=1 可用，勾選表單後輸入操作確認密碼＋指定簽核日期，一次補齊尚未完成的確認/核准關卡，用於補登舊紙本資料。
        <h4>重要行為</h4>
        ・部門是否產生技能鑑定表／職能鑑定表由管理員在「範本管理」設定，職務說明書全員適用。<br>
        ・機型/量具選項為管理員從既有機台主檔與量測儀器校驗的量具主檔勾選建立的白名單，不是全部主檔都能選。<br>
        ・確認人固定為該員工直屬主管、核准人固定為全站最高決策者（多數為總經理），無法個別調整。
        <h4>權限角色</h4>
        人資職務表單檢閱＝看清單（僅看跟自己有關的）；檢視全部＝看全部人的表單；建立＝新增/批次建立/複製/編輯/送出；列印；範本管理＝到「範本管理」頁設定範本/白名單/部門資格/AS文件綁定；管理者全權。
    </div>
    <div class="m-foot"><button class="b-ok" onclick="closeMask('helpUseMask')">關閉</button></div>
</div></div>

<script src="../../resource/js/jquery.min.js"></script>
<script src="../../resource/js/bootstrap.min.js"></script>
<script src="../../resource/js/fastclick.js"></script>
<script src="../../resource/js/nprogress.js"></script>
<script src="../../resource/js/custom.min.js"></script>
<script src="../../resource/js/eg_input_rules.js?v=<?= @filemtime(__DIR__.'/../../resource/js/eg_input_rules.js') ?>"></script>
<script src="../../resource/js/eg_stamp.js?v=<?= @filemtime(__DIR__.'/../../resource/js/eg_stamp.js') ?>"></script>
<script src="../../resource/js/eg_date_fmt.js?v=<?= @filemtime(__DIR__.'/../../resource/js/eg_date_fmt.js') ?>"></script>
<script>
var API = '../../src/store/HrForm_API.php';
var META = {};
var CUR_TAB = 'job_desc';
var LISTS = {job_desc:[], skill_assess:[], competency:[]};
var FORM_LABEL = {job_desc:'職務說明書', skill_assess:'專業技能鑑定考核表', competency:'員工職能鑑定表'};
var STATUS_LABEL = {draft:'草稿', active:'已建立', confirming:'確認中', approving:'核准中', signed:'已完成', rejected:'已退回'};
var CUR = null; // 目前檢視中的 instance

function esc(s){ return $('<div>').text(s==null?'':s).html(); }
function dispDate(d){ return (typeof egFmtDate === 'function') ? egFmtDate(d) : (d||''); }
function openMask(id){ $('#'+id).css('display','block'); }
function closeMask(id){ $('#'+id).css('display','none'); }
function ajaxPost(action, data, cb){
    data = data || {}; data.action = action; data.csrf = META.csrf;
    $.post(API, data, function(res){ cb(res); }, 'json').fail(function(){ cb({ok:false, error:'連線失敗'}); });
}

$(document).ready(function(){
    var $am = $('#sidebar-menu .nav.side-menu > li.active');
    if ($am.length) { $am.removeClass('active').find('ul.child_menu').hide(); $am.find('li.current-page').removeClass('current-page'); }
    $('#sidebar-menu').css('visibility','visible');
});
$('#btnPageHelp').on('click', function(){ openMask('helpUseMask'); });

$('#hfTabs a').on('click', function(e){
    e.preventDefault();
    var t = $(this).data('type');
    $('#hfTabs li').removeClass('active'); $(this).parent().addClass('active');
    $('.hf-tabpane').removeClass('active'); $('#pane-'+t).addClass('active');
    CUR_TAB = t;
    loadList(t);
});
$('.hf-tabpane .kw').on('input', function(){ renderList($(this).closest('.hf-tabpane').data('type')); });
$('.hf-tabpane .st-filter').on('change', function(){ renderList($(this).closest('.hf-tabpane').data('type')); });
$('.hf-tabpane .btn-create').on('click', function(){ openCreateModal($(this).closest('.hf-tabpane').data('type')); });
$('.hf-tabpane .btn-print-all').on('click', function(){ printAll($(this).closest('.hf-tabpane').data('type')); });
$('.hf-tabpane .btn-auto-sign').on('click', function(){ openAutoSignModal($(this).closest('.hf-tabpane').data('type')); });

function loadMeta(cb){
    $.getJSON(API, {action:'meta'}, function(res){
        if (!res.ok){ alert(res.error||'載入失敗'); return; }
        META = res;
        window.__ownCompany = META.company_name || '';
        if (META.perms.canAdmin) $('.admin-only').show();
        if (META.perms.isSuperAdmin) $('.btn-auto-sign').show();
        loadAsDoc('job_desc'); loadAsDoc('skill_assess'); loadAsDoc('competency');
        buildTableHeads();
        if (cb) cb();
    });
}
function loadAsDoc(ft){
    $.getJSON(API, {action:'asdoc_get', form_type:ft}, function(res){
        if (!res.ok) return;
        var t = res.doc ? (res.doc.doc_no+' '+res.doc.doc_name) : '（尚未綁定 AS 文件編號）';
        $('#pane-'+ft+' .hf-asdoc').text('AS文件：'+t);
    });
}
function buildTableHeads(){
    var ck = META.perms.isSuperAdmin ? '<th style="width:22px;"></th>' : '';
    $('.thead-job_desc').html('<tr><th>部門</th><th>職位</th><th>姓名</th><th>建立日期</th><th style="width:150px;">操作</th></tr>');
    $('.thead-skill_assess').html('<tr>'+ck+'<th>部門</th><th>姓名</th><th>機型</th><th>項目名稱</th><th>總經理考核</th><th>課長考核</th><th>確認</th><th>核准</th><th style="width:170px;">操作</th></tr>');
    $('.thead-competency').html('<tr>'+ck+'<th>部門</th><th>姓名</th><th>職務</th><th>確認</th><th>核准</th><th style="width:170px;">操作</th></tr>');
}

function loadList(ft){
    var url = {action:'list', form_type:ft};
    $.getJSON(API, url, function(res){
        if (!res.ok){ $('#pane-'+ft+' .list-body').html('<tr><td colspan="9" style="color:#DD5138;">'+esc(res.error||'載入失敗')+'</td></tr>'); return; }
        LISTS[ft] = res.instances || [];
        renderList(ft);
    });
}

function scoreAvg(a,b,c){ var v=[a,b,c].filter(function(x){return x!==null && x!=='';}); if(!v.length) return null; var s=0; v.forEach(function(x){s+=Number(x);}); return Math.round((s/v.length)*100)/100; }

function renderList(ft){
    ft = ft || CUR_TAB;
    var $pane = $('#pane-'+ft);
    var kw = ($pane.find('.kw').val()||'').toLowerCase();
    var stf = $pane.find('.st-filter').val()||'';
    var rows = (LISTS[ft]||[]).filter(function(r){
        if (stf && r.status !== stf) return false;
        if (!kw) return true;
        var hay = (r.dept_name+' '+r.position_name+' '+r.user_cname+' '+(r.machine_display_name||'')+' '+(r.item_name||'')).toLowerCase();
        return hay.indexOf(kw) >= 0;
    });
    var $tb = $pane.find('.list-body');
    if (!rows.length){ $tb.html('<tr><td colspan="9" style="text-align:center;color:#8a6d45;">尚無資料</td></tr>'); return; }
    var html = '';
    rows.forEach(function(r){
        var stBadge = '<span class="st-badge st-'+r.status+'">'+(STATUS_LABEL[r.status]||r.status)+'</span>';
        var opBtns = '<button class="hf-btn-sm" onclick="openViewModal(\''+ft+'\','+r.id+')">檢視</button> '
                   + '<button class="hf-btn-sm" onclick="printOne(\''+ft+'\','+r.id+')">列印</button> '
                   + '<button class="hf-btn-sm" onclick="copyInstance(\''+ft+'\','+r.id+')">複製</button> '
                   + (META.perms.canAdmin || r.created_by == META.uid ? '<button class="hf-btn-sm" onclick="deleteInstance(\''+ft+'\','+r.id+')">刪除</button>' : '');
        var ck = (META.perms.isSuperAdmin && ft!=='job_desc') ? '<td><input type="checkbox" class="auto-ck" value="'+r.id+'"></td>' : '';
        if (ft === 'job_desc') {
            html += '<tr><td>'+esc(r.dept_name)+'</td><td>'+esc(r.position_name)+'</td><td>'+esc(r.user_cname)+'</td><td>'+dispDate(r.business_date)+'</td><td>'+opBtns+'</td></tr>';
        } else if (ft === 'skill_assess') {
            var gmAvg = scoreAvg(r.score_quality_gm,r.score_efficiency_gm,r.score_proficiency_gm);
            var mgrAvg = scoreAvg(r.score_quality_mgr,r.score_efficiency_mgr,r.score_proficiency_mgr);
            html += '<tr>'+ck+'<td>'+esc(r.dept_name)+'</td><td>'+esc(r.user_cname)+'</td><td>'+esc(r.machine_display_name)+'</td><td>'+esc(r.item_name)+'</td>'
                  + '<td>'+(gmAvg===null?'-':gmAvg)+'</td><td>'+(mgrAvg===null?'-':mgrAvg)+'</td>'
                  + '<td>'+(r.confirm_user_name?esc(r.confirm_user_name):stBadge)+'</td><td>'+(r.approve_user_name?esc(r.approve_user_name):(r.status==='signed'?stBadge:'-'))+'</td>'
                  + '<td>'+opBtns+'</td></tr>';
        } else {
            html += '<tr>'+ck+'<td>'+esc(r.dept_name)+'</td><td>'+esc(r.user_cname)+'</td><td>'+esc(r.position_name)+'</td>'
                  + '<td>'+(r.confirm_user_name?esc(r.confirm_user_name):stBadge)+'</td><td>'+(r.approve_user_name?esc(r.approve_user_name):(r.status==='signed'?stBadge:'-'))+'</td>'
                  + '<td>'+opBtns+'</td></tr>';
        }
    });
    $tb.html(html);
}
</script>
<style>.hf-btn-sm{height:26px;padding:0 8px;border-radius:4px;font-size:11.5px;border:1px solid #D8BE93;background:#fff;color:#5b3a1e;cursor:pointer;}.hf-btn-sm:hover{background:#FBF0DD;}</style>
<script>
/* ============================================================ 員工/機型挑選元件 ============================================================ */

function hfPeopleRowHtml(p, checked){
    var lv = p.on_leave ? ' <span class="leave-tag">['+esc(p.leave_note)+']</span>' : '';
    return '<label data-hay="'+esc((p.dept_name||'')+' '+(p.position_name||'')+' '+p.user_cname).toLowerCase()+'">'
         + '<input type="checkbox" value="'+p.id+'"'+(checked?' checked':'')+'> '
         + esc(p.dept_name||'') + ' / ' + esc(p.position_name||'') + ' / ' + esc(p.user_cname) + lv + '</label>';
}
function hfFilterPeople(input){
    var kw = (input.value||'').toLowerCase();
    $(input).closest('.hf-people-pick').find('.hf-people-list label').each(function(){
        $(this).toggle(!kw || ($(this).data('hay')+'').indexOf(kw) >= 0);
    });
}

var CREATE_TYPE = 'job_desc';
function openCreateModal(ft){
    CREATE_TYPE = ft;
    $('#createTitle').text('建立表單 — '+FORM_LABEL[ft]);
    $('#createBizDate').val(META.today);
    $('#createErrList').empty();
    var eligDeptIds = null;
    if (ft !== 'job_desc') {
        var col = ft === 'skill_assess' ? 'produce_skill_assess' : 'produce_competency';
        eligDeptIds = (META.dept_type_settings||[]).filter(function(d){ return !!d[col]; }).map(function(d){ return d.department_id; });
    }
    var people = (META.people||[]).filter(function(p){
        if (!eligDeptIds) return true;
        return (p.dept_ids||[]).some(function(d){ return eligDeptIds.indexOf(d) >= 0; });
    });
    $('#createPeopleList').html(people.map(function(p){ return hfPeopleRowHtml(p,false); }).join('') || '<span style="color:#8a6d45;">目前沒有符合資格的員工（請確認部門表單資格設定）</span>');
    $('#createMachineBlock').toggle(ft === 'skill_assess');
    if (ft === 'skill_assess') {
        $('input[name=mSrc][value=tpl]').prop('checked', true);
        $('#createMachineList').hide();
        $.getJSON(API, {action:'whitelist_list'}, function(res){
            if (!res.ok) { $('#createMachineList').html('<span style="color:#8a6d45;">（僅管理員可預覽白名單，手動指定請洽管理員）</span>'); return; }
            $('#createMachineList').html((res.whitelist||[]).map(function(w){
                return '<label><input type="checkbox" value="'+w.id+'"> '+esc(w.item_name||w.display_name)+'（'+esc(w.display_name)+'）</label>';
            }).join('') || '<span style="color:#8a6d45;">尚未建立白名單</span>');
        });
    }
    openMask('createMask');
}
function hfToggleMachineSrc(){
    $('#createMachineList').toggle($('input[name=mSrc]:checked').val() === 'manual');
}
function hfSubmitCreate(){
    var uids = $('#createPeopleList input:checked').map(function(){ return $(this).val(); }).get();
    if (!uids.length){ $('#createErrList').text('請至少選擇一位員工'); return; }
    var bizDate = $('#createBizDate').val() || META.today;
    var wids = [];
    if (CREATE_TYPE === 'skill_assess' && $('input[name=mSrc]:checked').val() === 'manual') {
        wids = $('#createMachineList input:checked').map(function(){ return $(this).val(); }).get();
        if (!wids.length){ $('#createErrList').text('請至少選擇一個機型，或改選「依職位範本自動帶入」'); return; }
    }
    ajaxPost('batch_create', {form_type:CREATE_TYPE, user_ids:JSON.stringify(uids), whitelist_ids:JSON.stringify(wids), business_date:bizDate}, function(res){
        if (!res.ok){ $('#createErrList').text(res.error||'建立失敗'); return; }
        var msg = '成功建立 '+res.created+' 筆';
        if (res.errors && res.errors.length) msg += '；' + res.errors.length + ' 筆失敗：' + res.errors.join('；');
        $('#createErrList').css('color', res.errors && res.errors.length ? '#DD5138' : '#3f9142').text(msg);
        loadList(CREATE_TYPE);
        if (!res.errors || !res.errors.length) setTimeout(function(){ closeMask('createMask'); }, 900);
    });
}
</script>
<script>
/* ============================================================ 檢視/編輯/簽核 ============================================================ */

function statusNote(r){
    if (r.status === 'rejected') return '<div style="color:#DD5138;">此表單已被退回，可修改後重新送出。</div>';
    return '';
}
function jdItemsTableHtml(items){
    var rows = items && items.length ? items : [{data:{}}];
    var html = '<div class="itm-tbl-wrap"><table class="itm-tbl"><thead><tr><th>工作摘要</th><th>工作相關程序書</th><th>產出表單名稱</th><th>DPI 項目（績效標準計算方式）</th></tr></thead>'
             + '<tbody id="jdItemsBody" data-eg-row-add="hfJdRowAdd" data-eg-row-del="hfJdRowDel">';
    rows.forEach(function(it){ html += jdRowHtml(it.data||{}); });
    html += '</tbody></table></div><button class="hf-btn-sm" onclick="hfJdRowAdd()">+新增列</button> <button class="hf-btn-sm" onclick="hfJdRowDel()">-刪除末列</button>';
    return html;
}
function jdRowHtml(d){
    return '<tr><td><textarea class="c-a">'+esc(d.summary||'')+'</textarea></td><td><textarea class="c-b">'+esc(d.process||'')+'</textarea></td>'
         + '<td><textarea class="c-c">'+esc(d.form_name||'')+'</textarea></td><td><textarea class="c-d">'+esc(d.dpi||'')+'</textarea></td></tr>';
}
function hfJdRowAdd(){ $('#jdItemsBody').append(jdRowHtml({})); }
function hfJdRowDel(){ var $rows=$('#jdItemsBody tr'); if ($rows.length>1) $rows.last().remove(); }
function jdItemsCollect(){
    var out = [];
    $('#jdItemsBody tr').each(function(){
        var $t = $(this);
        out.push({data:{summary:$t.find('.c-a').val(), process:$t.find('.c-b').val(), form_name:$t.find('.c-c').val(), dpi:$t.find('.c-d').val()}});
    });
    return out;
}

function cpItemsTableHtml(items, editable){
    var rows = items && items.length ? items : [{data:{}}];
    var html = '<div class="itm-tbl-wrap"><table class="itm-tbl"><thead><tr><th style="width:36px;">編號</th><th>項目名稱</th><th style="width:110px;">操作</th><th style="width:110px;">異常排除</th></tr></thead>'
             + '<tbody id="cpItemsBody" data-eg-row-add="hfCpRowAdd" data-eg-row-del="hfCpRowDel">';
    rows.forEach(function(it,i){ html += cpRowHtml(it.data||{}, i+1, editable); });
    html += '</tbody></table></div>'
          + (editable ? '<button class="hf-btn-sm" onclick="hfCpRowAdd()">+新增列</button> <button class="hf-btn-sm" onclick="hfCpRowDel()">-刪除末列</button>' : '');
    return html;
}
function scoreSelectHtml(cls, val, disabled){
    var opts = ['','1','2','3','4'].map(function(v){ return '<option value="'+v+'"'+(String(val==null?'':val)===v?' selected':'')+'>'+(v||'—')+'</option>'; }).join('');
    return '<select class="'+cls+'"'+(disabled?' disabled':'')+'>'+opts+'</select>';
}
function cpRowHtml(d, no, editable){
    var nameCell = editable ? '<textarea class="c-name">'+esc(d.skill_name||'')+'</textarea>' : esc(d.skill_name||'');
    return '<tr><td style="text-align:center;">'+no+'</td><td>'+nameCell+'</td>'
         + '<td>'+scoreSelectHtml('c-op', d.score_op, !editable)+'</td><td>'+scoreSelectHtml('c-ex', d.score_ex, !editable)+'</td></tr>';
}
function hfCpRowAdd(){ var n=$('#cpItemsBody tr').length+1; $('#cpItemsBody').append(cpRowHtml({}, n, true)); }
function hfCpRowDel(){ var $rows=$('#cpItemsBody tr'); if ($rows.length>1) $rows.last().remove(); }
function cpItemsCollect(){
    var out = [];
    $('#cpItemsBody tr').each(function(){
        var $t = $(this);
        out.push({data:{skill_name:$t.find('.c-name').val() || $t.find('td').eq(1).text(), score_op:$t.find('.c-op').val()||null, score_ex:$t.find('.c-ex').val()||null}});
    });
    return out;
}

function openViewModal(ft, id){
    $.getJSON(API, {action:'get', id:id}, function(res){
        if (!res.ok){ alert(res.error||'載入失敗'); return; }
        CUR = res.instance;
        $('#viewTitle').text(FORM_LABEL[ft]+' — '+CUR.user_cname);
        $('#viewBody').html(renderViewBody(ft, CUR));
        openMask('viewMask');
    });
}
function headTableHtml(r){
    var h = '<table class="itm-tbl"><tbody>'
          + '<tr><th style="width:90px;">部門</th><td>'+esc(r.dept_name||'')+'</td><th style="width:90px;">職位</th><td>'+esc(r.position_name||'')+'</td></tr>'
          + '<tr><th>姓名</th><td>'+esc(r.user_cname||'')+'</td><th>員工編號</th><td>'+esc(r.user_no||'')+'</td></tr>'
          + '<tr><th>到職日</th><td>'+dispDate(r.onboard_date)+'</td><th>主管</th><td>'+esc(r.supervisor_name||'')+'</td></tr>'
          + '<tr><th>業務日期</th><td>'+dispDate(r.business_date)+'</td><th>狀態</th><td>'+(STATUS_LABEL[r.status]||r.status)+'</td></tr>';
    if (r.form_type === 'skill_assess') h += '<tr><th>機型</th><td>'+esc(r.machine_display_name||'')+'</td><th>項目名稱</th><td>'+esc(r.item_name||'')+'</td></tr>';
    h += '</tbody></table>';
    return h;
}
function decideBoxHtml(level, r){
    if (r.status !== level) return '';
    var scoreInputs = '';
    if (r.form_type === 'skill_assess') {
        var suf = level === 'confirming' ? 'mgr' : 'gm';
        var label = level === 'confirming' ? '課長考核' : '總經理考核';
        scoreInputs = '<p style="font-weight:bold;">'+label+'評分（1~4分）</p>'
            + '<table class="itm-tbl hf-score-tbl"><tr><td>品質</td><td><input type="number" min="1" max="4" id="sc-quality" value="'+(r['score_quality_'+suf]??'')+'"></td>'
            + '<td>效率</td><td><input type="number" min="1" max="4" id="sc-efficiency" value="'+(r['score_efficiency_'+suf]??'')+'"></td>'
            + '<td>熟練度</td><td><input type="number" min="1" max="4" id="sc-proficiency" value="'+(r['score_proficiency_'+suf]??'')+'"></td></tr></table>';
    }
    var btnLabel = level === 'confirming' ? '確認' : '核准';
    var fn = level === 'confirming' ? 'hfConfirmDecide' : 'hfApproveDecide';
    return '<div class="decide-box"><b>'+btnLabel+'（'+(level==='confirming'?'直屬主管':'總經理')+'）</b>'
         + scoreInputs
         + '<label>退回原因（僅退回時必填）</label><textarea id="decideNote" rows="2"></textarea>'
         + '<div style="margin-top:8px;"><button class="b-ok" onclick="'+fn+'(\'approved\')">'+btnLabel+'通過</button> '
         + '<button class="b-danger" onclick="'+fn+'(\'rejected\')">退回</button></div></div>';
}
function renderViewBody(ft, r){
    var h = statusNote(r) + headTableHtml(r);
    var editable = (ft === 'job_desc') || (r.status === 'draft');
    if (ft === 'job_desc') {
        h += jdItemsTableHtml(r.items);
        h += '<div style="margin-top:10px;"><button class="b-ok" onclick="hfSaveItems(\'job_desc\')">存檔</button> <button class="hf-btn-sm" onclick="printOne(\'job_desc\','+r.id+')">列印</button> <button class="hf-btn-sm" onclick="copyInstance(\'job_desc\','+r.id+')">複製</button></div>';
    } else if (ft === 'skill_assess') {
        h += '<table class="itm-tbl hf-score-tbl"><thead><tr><th></th><th>品質</th><th>效率</th><th>熟練度</th><th>平均</th></tr></thead><tbody>'
           + '<tr><th>總經理考核</th><td>'+(r.score_quality_gm??'-')+'</td><td>'+(r.score_efficiency_gm??'-')+'</td><td>'+(r.score_proficiency_gm??'-')+'</td><td>'+(scoreAvg(r.score_quality_gm,r.score_efficiency_gm,r.score_proficiency_gm)??'-')+'</td></tr>'
           + '<tr><th>課長考核</th><td>'+(r.score_quality_mgr??'-')+'</td><td>'+(r.score_efficiency_mgr??'-')+'</td><td>'+(r.score_proficiency_mgr??'-')+'</td><td>'+(scoreAvg(r.score_quality_mgr,r.score_efficiency_mgr,r.score_proficiency_mgr)??'-')+'</td></tr>'
           + '</tbody></table>';
        if (r.status === 'draft') h += '<div style="margin-top:8px;"><button class="b-ok" onclick="hfSubmitInstance()">送出（通知直屬主管確認）</button></div>';
        h += decideBoxHtml('confirming', r) + decideBoxHtml('approving', r);
        h += '<div style="margin-top:10px;"><button class="hf-btn-sm" onclick="printOne(\'skill_assess\','+r.id+')">列印</button> <button class="hf-btn-sm" onclick="copyInstance(\'skill_assess\','+r.id+')">複製</button></div>';
    } else {
        h += cpItemsTableHtml(r.items, r.status === 'draft' || r.status === 'confirming');
        if (r.status === 'draft') h += '<div style="margin-top:8px;"><button class="b-ok" onclick="hfSaveItems(\'competency\')">存檔</button> <button class="b-ok" onclick="hfSubmitInstance()">送出（通知直屬主管確認）</button></div>';
        h += decideBoxHtml('confirming', r) + decideBoxHtml('approving', r);
        h += '<div style="margin-top:10px;"><button class="hf-btn-sm" onclick="printOne(\'competency\','+r.id+')">列印</button> <button class="hf-btn-sm" onclick="copyInstance(\'competency\','+r.id+')">複製</button></div>';
    }
    return h;
}
function hfSaveItems(ft){
    var items = ft === 'job_desc' ? jdItemsCollect() : cpItemsCollect();
    ajaxPost('save_items', {id:CUR.id, items:JSON.stringify(items)}, function(res){
        if (!res.ok){ alert(res.error||'存檔失敗'); return; }
        alert('已存檔'); loadList(ft);
    });
}
function hfSubmitInstance(){
    ajaxPost('submit', {id:CUR.id}, function(res){
        if (!res.ok){ alert(res.error||'送出失敗'); return; }
        alert('已送出'); closeMask('viewMask'); loadList(CUR.form_type);
    });
}
function hfConfirmDecide(decision){
    var note = $('#decideNote').val();
    if (decision === 'rejected' && !note){ alert('退回請填寫原因'); return; }
    var payload = {id:CUR.id, decision:decision, note:note};
    if (CUR.form_type === 'skill_assess') payload.scores = JSON.stringify({quality_mgr:$('#sc-quality').val(), efficiency_mgr:$('#sc-efficiency').val(), proficiency_mgr:$('#sc-proficiency').val()});
    if (CUR.form_type === 'competency') payload.items = JSON.stringify(cpItemsCollect());
    ajaxPost('confirm_decide', payload, function(res){
        if (!res.ok){ alert(res.error||'處理失敗'); return; }
        alert('已處理'); closeMask('viewMask'); loadList(CUR.form_type);
    });
}
function hfApproveDecide(decision){
    var note = $('#decideNote').val();
    if (decision === 'rejected' && !note){ alert('退回請填寫原因'); return; }
    var payload = {id:CUR.id, decision:decision, note:note};
    if (CUR.form_type === 'skill_assess') payload.scores = JSON.stringify({quality_gm:$('#sc-quality').val(), efficiency_gm:$('#sc-efficiency').val(), proficiency_gm:$('#sc-proficiency').val()});
    ajaxPost('approve_decide', payload, function(res){
        if (!res.ok){ alert(res.error||'處理失敗'); return; }
        alert('已處理'); closeMask('viewMask'); loadList(CUR.form_type);
    });
}
function copyInstance(ft, id){
    if (!confirm('確定要複製這份表單？將建立一份新草稿。')) return;
    ajaxPost('copy', {id:id}, function(res){
        if (!res.ok){ alert(res.error||'複製失敗'); return; }
        alert('已複製為新草稿'); loadList(ft);
    });
}
function deleteInstance(ft, id){
    if (!confirm('確定要刪除這份表單？無法復原。')) return;
    ajaxPost('delete', {id:id}, function(res){
        if (!res.ok){ alert(res.error||'刪除失敗'); return; }
        loadList(ft);
    });
}
</script>
<script>
/* ============================================================ 超管自動簽核 ============================================================ */
var AUTO_SIGN_TYPE = null;
function openAutoSignModal(ft){
    AUTO_SIGN_TYPE = ft;
    var ids = $('#pane-'+ft+' .auto-ck:checked').map(function(){ return this.value; }).get();
    if (!ids.length){ alert('請先在清單勾選要補簽核的表單'); return; }
    $('#autoSignCount').text(ids.length);
    $('#autoSignPwd').val(''); $('#autoSignDate').val(META.today); $('#autoSignErr').empty();
    openMask('autoSignMask');
}
function hfSubmitAutoSign(){
    var ids = $('#pane-'+AUTO_SIGN_TYPE+' .auto-ck:checked').map(function(){ return this.value; }).get();
    var pwd = $('#autoSignPwd').val();
    if (!pwd){ $('#autoSignErr').text('請輸入操作確認密碼'); return; }
    ajaxPost('auto_sign_bulk', {ids:JSON.stringify(ids), password:pwd, sign_date:$('#autoSignDate').val()||META.today}, function(res){
        if (!res.ok){ $('#autoSignErr').text(res.error||'執行失敗'); return; }
        var msg = '已補簽 '+res.done+' 筆';
        if (res.errors && res.errors.length) msg += '；失敗：'+res.errors.join('；');
        alert(msg);
        closeMask('autoSignMask');
        loadList(AUTO_SIGN_TYPE);
    });
}
</script>
<script>
/* ============================================================ 列印 ============================================================ */

function egPrintWindow(title, bodyHtml, extraCss, docNo, showPageNum){
    var asCss = String(docNo||'').replace(/['\\]/g,'');
    var css = '@page{size:A4 portrait;margin:12mm 8mm 16mm;}'
            + 'html,body{margin:0;padding:0;}'
            + 'body{font-family:"Microsoft JhengHei","微軟正黑體",sans-serif;color:#000;-webkit-print-color-adjust:exact;print-color-adjust:exact;}'
            + '.pt-head{text-align:center;margin-bottom:6px;}.pt-head .co{font-size:22px;font-weight:bold;letter-spacing:2px;}.pt-head .tt{font-size:16px;font-weight:bold;margin-top:3px;letter-spacing:1px;}'
            + '.hf-page-num{position:fixed;left:8mm;bottom:6mm;font-size:9pt;color:#333;}'
            + '.hf-as-doc{position:fixed;right:8mm;bottom:6mm;font-size:9pt;color:#333;}'
            + '.hf-page{page-break-after:always;}'
            + hfPrintCss() + (extraCss||'');
    var w = window.open('', '_blank');
    if (!w){ alert('請允許彈出視窗'); return; }
    w.document.write('<html><head><meta charset="utf-8"><title>'+esc(title)+'</title><style>'+css+'</style></head><body>'
        + bodyHtml
        + (asCss ? '<div class="hf-as-doc">'+asCss+'</div>' : '')
        + (showPageNum ? '<div class="hf-page-num">第 1 頁／共 1 頁</div>' : '')
        + '<scr'+'ipt>window.onload=function(){ setTimeout(function(){ window.print(); }, 300); };</scr'+'ipt></body></html>');
    w.document.close();
}
function hfPrintCss(){
    return 'table.hf-p-head{width:100%;border-collapse:collapse;font-size:13px;table-layout:fixed;margin-bottom:6px;}'
         + 'table.hf-p-head th{background:#fff;font-weight:bold;border:1px solid #333;padding:5px 6px;width:90px;text-align:center;}'
         + 'table.hf-p-head td{border:1px solid #333;padding:5px 8px;text-align:left;}'
         + 'table.hf-p-items{width:100%;border-collapse:collapse;font-size:11.5px;margin-top:6px;}'
         + 'table.hf-p-items th,table.hf-p-items td{border:1px solid #333;padding:5px 6px;text-align:center;}'
         + 'table.hf-p-items td.t-left{text-align:left;}'
         + 'table.hf-p-foot{width:100%;margin-top:16px;margin-bottom:12mm;font-size:13px;}'
         + 'table.hf-p-foot td{padding:6px;width:33.33%;text-align:center;vertical-align:top;}'
         + 'table.hf-p-foot .foot-lbl{margin-bottom:4px;}'
         + '.hf-p-note{font-size:11px;color:#333;margin-top:8px;line-height:1.6;}'
         + 'table.hf-p-foot svg{width:76px !important;height:76px !important;max-width:76px;max-height:76px;-webkit-print-color-adjust:exact;print-color-adjust:exact;}';
}
function stampOrName(name, date, isDeputy, schema){
    return (window.EGStamp && EGStamp.stamp) ? EGStamp.stamp(name, date, !!isDeputy, schema) : esc(name||'');
}

function jdPrintHtml(r){
    var h = '<div class="pt-head"><div class="co">'+esc(META.company_name||'')+'</div><div class="tt">職務說明書</div></div>';
    h += '<table class="hf-p-head"><tr><th>工號</th><td>'+esc(r.user_no||'')+'</td><th>姓名</th><td>'+esc(r.user_cname||'')+'</td></tr>'
       + '<tr><th>職務名稱</th><td>'+esc(r.position_name||'')+'</td><th>到職日</th><td>'+dispDate(r.onboard_date)+'</td></tr>'
       + '<tr><th>所屬部門</th><td>'+esc(r.dept_name||'')+'</td><th>直屬主管</th><td>'+esc(r.supervisor_name||'')+'</td></tr>'
       + '<tr><th>建立日期</th><td colspan="3">'+dispDate(r.business_date)+'</td></tr></table>';
    h += '<table class="hf-p-items"><thead><tr><th>工作摘要</th><th>工作相關程序書</th><th>產出表單名稱</th><th>DPI 項目（績效標準計算方式）</th></tr></thead><tbody>';
    (r.items||[]).forEach(function(it){
        var d = it.data||{};
        h += '<tr><td class="t-left">'+esc(d.summary||'').replace(/\n/g,'<br>')+'</td><td class="t-left">'+esc(d.process||'').replace(/\n/g,'<br>')+'</td>'
           + '<td class="t-left">'+esc(d.form_name||'').replace(/\n/g,'<br>')+'</td><td class="t-left">'+esc(d.dpi||'').replace(/\n/g,'<br>')+'</td></tr>';
    });
    h += '</tbody></table>';
    return h;
}
function saPrintHtml(r, tpl){
    var h = '<div class="pt-head"><div class="co">'+esc(META.company_name||'')+'</div><div class="tt">專業技能鑑定考核表</div></div>';
    h += '<table class="hf-p-head"><tr><th>單位</th><td>'+esc(r.dept_name||'')+'</td><th>姓名</th><td>'+esc(r.user_cname||'')+'</td></tr>'
       + '<tr><th>機型</th><td>'+esc(r.machine_display_name||'')+'</td><th>項目名稱</th><td>'+esc(r.item_name||'')+'</td></tr>'
       + '<tr><th>日期</th><td colspan="3">'+dispDate(r.business_date)+'</td></tr></table>';
    h += '<table class="hf-p-items"><thead><tr><th style="width:25%;">分類項目</th><th>總經理考核</th><th>課長考核</th></tr></thead><tbody>'
       + ['quality','efficiency','proficiency'].map(function(k,i){
           var lbl = ['品質','效率','熟練度'][i];
           return '<tr><td>'+lbl+'</td><td>'+(r['score_'+k+'_gm']??'')+'</td><td>'+(r['score_'+k+'_mgr']??'')+'</td></tr>';
         }).join('')
       + '<tr><td>平均</td><td>'+(scoreAvg(r.score_quality_gm,r.score_efficiency_gm,r.score_proficiency_gm)??'')+'</td><td>'+(scoreAvg(r.score_quality_mgr,r.score_efficiency_mgr,r.score_proficiency_mgr)??'')+'</td></tr>'
       + '</tbody></table>';
    h += '<div class="hf-p-note">說明：品質：依合格率計算(1分=25%、2分=50%、3分=75%、4分=100%)　效率：依標準工時計算效率(1分60%以下、2分=60~74%、3分=75~84%、4分=85%以上)　熟練度：1分=略、2分=熟、3分=獨立作業、4分=可教學<br>考核分數：1~4分，評分2分以上才合格，總經理、課長均要3分以上才合格。</div>';
    h += printFootHtml(r, tpl);
    return h;
}
function cpPrintHtml(r, tpl){
    var h = '<div class="pt-head"><div class="co">'+esc(META.company_name||'')+'</div><div class="tt">員工職能鑑定表</div></div>';
    h += '<table class="hf-p-head"><tr><th>部門</th><td>'+esc(r.dept_name||'')+'</td><th>員工編號</th><td>'+esc(r.user_no||'')+'</td></tr>'
       + '<tr><th>姓名</th><td>'+esc(r.user_cname||'')+'</td><th>到職日</th><td>'+dispDate(r.onboard_date)+'</td></tr>'
       + '<tr><th>職務</th><td>'+esc(r.position_name||'')+'</td><th>主管</th><td>'+esc(r.supervisor_name||'')+'</td></tr>'
       + '<tr><th>建立日期</th><td>'+dispDate(r.business_date)+'</td><th>最新更新日期</th><td>'+dispDate(r.updated_at?r.updated_at.substr(0,10):r.business_date)+'</td></tr></table>';
    h += '<table class="hf-p-items"><thead><tr><th style="width:50px;">編號</th><th>項目名稱</th><th style="width:90px;">操作</th><th style="width:90px;">異常排除</th></tr></thead><tbody>';
    (r.items||[]).forEach(function(it,i){
        var d = it.data||{};
        h += '<tr><td>'+(i+1)+'</td><td class="t-left">'+esc(d.skill_name||'')+'</td><td>'+(d.score_op||'')+'</td><td>'+(d.score_ex||'')+'</td></tr>';
    });
    h += '</tbody></table>';
    h += '<div class="hf-p-note">填寫說明：人員依技能項目其純熟度可分為： 1=略(大部分須人員指導)　2=熟(少部分須人員指導)　3=獨立作業　4=可教學。其鑑別方式，由主管依據教育訓練後評鑑方式依職能鑑定考核表確認。</div>';
    h += printFootHtml(r, tpl);
    return h;
}
function printFootHtml(r, tpl){
    var listSchema = tpl && tpl.list_stamp ? tpl.list_stamp.schema : null;
    var footSchema = tpl && tpl.footer_stamp ? tpl.footer_stamp.schema : null;
    var approveStamp = r.approve_user_name ? stampOrName(r.approve_user_name, r.approve_at?r.approve_at.substr(0,10):r.business_date, false, footSchema) : '';
    var confirmStamp = r.confirm_user_name ? stampOrName(r.confirm_user_name, r.confirm_at?r.confirm_at.substr(0,10):r.business_date, false, footSchema) : '';
    return '<table class="hf-p-foot"><tr>'
         + '<td><div class="foot-lbl">核准</div>'+approveStamp+'</td>'
         + '<td><div class="foot-lbl">確認</div>'+confirmStamp+'</td>'
         + '</tr></table>';
}

function fetchTplForPrint(r, cb){
    if (!r.template_id || !META.perms.canAdmin) { cb(null); return; }
    $.getJSON(API, {action:'template_get', id:r.template_id}, function(res){ cb(res.ok ? res.template : null); });
}
function printOne(ft, id){
    $.getJSON(API, {action:'get', id:id}, function(res){
        if (!res.ok){ alert(res.error||'載入失敗'); return; }
        var r = res.instance;
        $.getJSON(API, {action:'asdoc_get', form_type:ft}, function(dres){
            var docNo = '';
            if (dres.ok && dres.doc) {
                docNo = dres.doc.doc_no; // 版次依業務日期回推，交由後端 hrf_asdoc_no_display 較精準，這裡先用現行編號顯示
            }
            fetchTplForPrint(r, function(tpl){
                var html = ft === 'job_desc' ? jdPrintHtml(r) : (ft === 'skill_assess' ? saPrintHtml(r, tpl) : cpPrintHtml(r, tpl));
                egPrintWindow(FORM_LABEL[ft]+' - '+r.user_cname, '<div class="hf-page">'+html+'</div>', '', docNo, true);
            });
        });
    });
}

/** 列印全部：依目前頁籤篩選結果，每位員工自己一頁，不印頁碼（使用者明確要求的例外）。 */
function printAll(ft){
    var rows = (LISTS[ft]||[]).slice();
    if (!rows.length){ alert('目前沒有可列印的資料'); return; }
    var byUser = {};
    rows.forEach(function(r){ (byUser[r.user_id] = byUser[r.user_id] || []).push(r); });
    $.getJSON(API, {action:'asdoc_get', form_type:ft}, function(dres){
        var docNo = (dres.ok && dres.doc) ? dres.doc.doc_no : '';
        var pages = '';
        Object.keys(byUser).forEach(function(uidKey){
            var list = byUser[uidKey];
            if (ft === 'job_desc') {
                pages += '<div class="hf-page">'+jdPrintHtml(list[0])+'</div>';
            } else if (ft === 'skill_assess') {
                var h = '<div class="pt-head"><div class="co">'+esc(META.company_name||'')+'</div><div class="tt">專業技能鑑定考核表</div></div>';
                h += '<table class="hf-p-head"><tr><th>單位</th><td>'+esc(list[0].dept_name||'')+'</td><th>姓名</th><td>'+esc(list[0].user_cname||'')+'</td></tr></table>';
                list.forEach(function(r){
                    h += '<div style="margin-top:8px;font-weight:bold;">機型：'+esc(r.machine_display_name||'')+'　項目名稱：'+esc(r.item_name||'')+'　日期：'+dispDate(r.business_date)+'</div>';
                    h += '<table class="hf-p-items"><thead><tr><th style="width:25%;">分類項目</th><th>總經理考核</th><th>課長考核</th></tr></thead><tbody>'
                       + ['quality','efficiency','proficiency'].map(function(k,i){ var lbl=['品質','效率','熟練度'][i]; return '<tr><td>'+lbl+'</td><td>'+(r['score_'+k+'_gm']??'')+'</td><td>'+(r['score_'+k+'_mgr']??'')+'</td></tr>'; }).join('')
                       + '<tr><td>平均</td><td>'+(scoreAvg(r.score_quality_gm,r.score_efficiency_gm,r.score_proficiency_gm)??'')+'</td><td>'+(scoreAvg(r.score_quality_mgr,r.score_efficiency_mgr,r.score_proficiency_mgr)??'')+'</td></tr></tbody></table>';
                    h += printFootHtml(r, null);
                });
                pages += '<div class="hf-page">'+h+'</div>';
            } else {
                pages += '<div class="hf-page">'+cpPrintHtml(list[0], null)+'</div>';
            }
        });
        egPrintWindow(FORM_LABEL[ft]+' - 全部列印', pages, '', docNo, false);
    });
}

loadMeta(function(){ loadList('job_desc'); });
</script>
</body>
</html>
