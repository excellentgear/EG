<?php
/**
 * 審核表單 — 建立／填寫／簽名／審核／核准／列印（review_form 引擎）—— 2026-08-11 新增
 * 模板設定請至「審核表單模板管理」review_form_template.php（僅管理員/維護人員可進）。
 * 資料一律走 src/store/ReviewForm_API.php；權限 src/common/review_form_lib.php rvf_perms()
 */
session_start();
if (!isset($_SESSION['userName'])) {
    $_SESSION['lastpage'] = "../../views/ADM/review_form.php";
    header("Location:../../index.php");
    exit;
}
include_once '../../src/common/_config.php';
include_once '../../src/common/DBConnection.php';
include_once '../../src/common/review_form_lib.php';

$db = (new DBConnection())->getPDO();
$rvfUser = rvf_current_user($db);
$perms = rvf_perms($db, $rvfUser);
?>
<!DOCTYPE html>
<html lang="zh-Hant">
<head>
    <meta charset="utf-8">
    <meta http-equiv="X-UA-Compatible" content="IE=edge">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>審核表單</title>
    <link href="../../resource/css/bootstrap.css" rel="stylesheet">
    <link href="../../resource/css/font-awesome.css" rel="stylesheet">
    <link href="../../resource/css/nprogress.css" rel="stylesheet">
    <link href="../../resource/css/custom.css" rel="stylesheet">
    <style>
        #sidebar-menu { visibility: hidden; }
        .right_col .page-title { margin:8px 0 4px; overflow:hidden; clear:both; }
        .rf-toolbar { display:flex; flex-wrap:wrap; gap:6px; align-items:center; clear:both;
            border:1.5px solid #E8D5B5; border-radius:8px; padding:8px 10px; margin-bottom:10px; background:#FDF8EF; }
        .rf-toolbar select, .rf-toolbar button { height:30px; font-size:13px; padding:0 12px; border:1px solid #D8BE93; border-radius:4px;
            background:#fff; color:#5b3a1e; cursor:pointer; }
        .rf-toolbar .btn-warm { background:#F0A24B; color:#fff; border-color:#d98a33; }
        .page-help-btn { margin-left:auto; height:30px; font-size:13px; padding:0 14px; border:1px solid #D8BE93;
            border-radius:4px; background:#fff; color:#5b3a1e; cursor:pointer; }
        .help-doc h4 { font-size:14px; color:#8A5A2B; margin:10px 0 4px; }
        table.rf-tbl { width:100%; border-collapse:collapse; font-size:13px; background:#fff; }
        table.rf-tbl th, table.rf-tbl td { border:1px solid #EADFC8; padding:6px 8px; }
        table.rf-tbl thead th { background:#F7E0BD; color:#5b3a1e; }
        .rf-table-wrap { overflow-x:auto; border:1px solid #E8D5B5; border-radius:6px; }
        .st-badge { border-radius:10px; padding:2px 9px; font-size:11.5px; color:#fff; }
        .st-draft{background:#b0a390;} .st-submitted,.st-reviewing,.st-approving{background:#F0A24B;} .st-approved{background:#3f9142;} .st-rejected{background:#DD5138;} .st-void{background:#888;}
        .rf-mask { display:none; position:fixed; inset:0; background:rgba(60,40,20,.45); z-index:1050; overflow-y:auto; }
        .rf-modal { background:#fff; border-radius:8px; max-width:1040px; margin:24px auto; box-shadow:0 5px 25px rgba(0,0,0,.3); }
        #viewMask .rf-modal { max-width:min(1200px, 94vw); }
        .itm-tbl-wrap { overflow-x:auto; margin-bottom:8px; }
        .rf-modal .m-head { background:#F7E0BD; color:#5b3a1e; font-weight:bold; padding:10px 15px; border-radius:8px 8px 0 0;
            display:flex; justify-content:space-between; position:sticky; top:0; }
        .rf-modal .m-head .m-close { cursor:pointer; color:#b5762a; }
        .rf-modal .m-body { padding:15px; }
        .rf-modal .m-body label { display:block; font-size:13px; color:#5b3a1e; margin:9px 0 3px; }
        .rf-modal .m-body input[type=text], .rf-modal .m-body input[type=date], .rf-modal .m-body select, .rf-modal .m-body textarea
            { border:1px solid #D8BE93; border-radius:4px; padding:5px 8px; font-size:13px; box-sizing:border-box; }
        .rf-modal .m-foot { padding:10px 15px; border-top:1px solid #EADFC8; text-align:right; }
        .rf-modal .m-foot button { height:30px; padding:0 16px; border-radius:4px; font-size:13px; border:1px solid #d98a33; cursor:pointer; margin-left:6px; }
        .rf-modal .m-foot .b-ok { background:#F0A24B; color:#fff; }
        .rf-modal .m-foot .b-cancel { background:#fff; color:#5b3a1e; border-color:#D8BE93; }
        .rf-modal .m-foot .b-danger { background:#DD5138; color:#fff; border-color:#c23f28; }
        table.itm-tbl { width:100%; border-collapse:collapse; font-size:12.5px; margin-bottom:8px; }
        table.itm-tbl th, table.itm-tbl td { border:1px solid #EADFC8; padding:5px 6px; vertical-align:top; }
        table.itm-tbl thead th { background:#F7E0BD; color:#5b3a1e; }
        table.itm-tbl textarea { width:100%; min-height:40px; border:1px solid #D8BE93; border-radius:4px; padding:4px 6px; font-size:12.5px; box-sizing:border-box; }
        table.itm-tbl input[type=text], table.itm-tbl input[type=date], table.itm-tbl select { width:100%; min-width:84px; border:1px solid #D8BE93; border-radius:4px; padding:3px 5px; font-size:12px; box-sizing:border-box; }
        table.itm-tbl td { min-width:70px; }
        table.itm-tbl th:first-child, table.itm-tbl td:first-child,
        table.itm-tbl th:last-child, table.itm-tbl td:last-child { min-width:auto; }
        .fld-block { display:block; margin-bottom:4px; } .fld-inline { display:inline-block; width:48%; margin:0 1% 4px; vertical-align:top; }
        .fld-lbl { font-size:10.5px; color:#8a6d45; }
        .owner-lbl { font-size:10.5px; font-weight:bold; color:#8a6d45; margin:3px 0 1px; }
        .owner-lbl:first-child { margin-top:0; }
        .rf-btn-sm { height:28px; padding:0 12px; border-radius:4px; font-size:12.5px; border:1px solid #D8BE93; background:#fff; color:#5b3a1e; cursor:pointer; }
        .rf-btn-sm:hover { background:#FBF0DD; }
        .dp-pick { position:relative; border:1px solid #D8BE93; border-radius:4px; background:#fff; padding:2px 3px; min-width:110px; margin-bottom:3px; }
        .dp-tags { display:flex; flex-wrap:wrap; gap:2px; }
        .dp-tags .tg { background:#F7E0BD; color:#5b3a1e; border-radius:9px; font-size:11px; padding:1px 5px 1px 7px; white-space:nowrap; }
        .dp-tags .tg i { cursor:pointer; color:#b5762a; margin-left:3px; }
        .dp-pick > input { width:100%; border:none !important; outline:none; font-size:11px; padding:2px 3px !important; }
        /* position:fixed（不是absolute）：欄位表格改用 .itm-tbl-wrap 橫向捲動後，absolute 下拉會被捲動容器的
           overflow 裁掉（CSS 規則：overflow-x 非 visible 時 overflow-y 會被瀏覽器強制視為 auto，兩軸一起裁切，
           無法只裁橫向）。改用 fixed 定位＋JS 依 input 位置現算座標，直接相對視窗定位，不受任何捲動容器影響。 */
        .dp-list { display:none; position:fixed; z-index:1200; background:#fff;
            border:1px solid #D8BE93; border-radius:4px; max-height:180px; overflow-y:auto; box-shadow:0 4px 10px rgba(0,0,0,.18); min-width:150px; }
        .dp-list div { padding:3px 8px; font-size:11.5px; color:#5b3a1e; cursor:pointer; }
        .dp-list div:hover { background:#FBF0DD; }
        .rf-del { color:#DD5138; cursor:pointer; }
        .subitem-ctrl { margin-top:3px; display:flex; gap:6px; }
        .subitem-ctrl .rf-mini-btn, .col-fill .rf-mini-btn { font-size:10.5px; color:#8a5a2b; border:1px solid #D8BE93; border-radius:9px; padding:1px 7px; cursor:pointer; background:#FBF0DD; white-space:nowrap; }
        .subitem-ctrl .rf-mini-btn:hover, .col-fill .rf-mini-btn:hover { background:#F7E0BD; }
        table.itm-tbl td.subitem-num { text-align:center; vertical-align:middle; }
        table.itm-tbl td.subitem-heading { background:#FDF8EF; }
        table.itm-tbl td.subitem-heading textarea { font-weight:bold; border-color:#D8BE93; }
        table.itm-tbl td.subitem-heading-note { background:#F5F0E5; color:#b0a390; font-size:11px; text-align:left; font-style:italic; }
        .col-fill { margin-top:4px; display:flex; flex-direction:column; gap:3px; font-weight:normal; }
        .col-fill .col-fill-inp { width:100%; min-width:0; border:1px solid #D8BE93; border-radius:4px; padding:2px 4px; font-size:11px; box-sizing:border-box; background:#fff; color:#5b3a1e; }
        .sign-slot { border:1px dashed #E8D5B5; border-radius:4px; padding:3px 5px; margin-bottom:3px; font-size:11px; }
        .sign-yes { color:#3f9142; font-weight:bold; } .sign-no { color:#b0a390; }
        .decide-box { border:1.5px solid #E8D5B5; border-radius:8px; padding:10px; margin-top:10px; background:#FDF8EF; }
        @media print { .page-help-btn { display:none; } }
    </style>
</head>
<body class="nav-sm">
<div class="container body">
<div class="main_container">
    <?php include '../partPage/sideAndTopBarMenu.html' ?>
    <div class="right_col" role="main">
        <div class="page-title" style="display:flex;align-items:center;flex-wrap:wrap;clear:both;">
            <h2 style="margin:6px 0;">審核表單 <small style="color:#8a6d45;">建立／填寫／簽名／審核／核准／列印</small></h2>
            <button class="page-help-btn" id="btnPageHelp"><i class="fa fa-question-circle"></i> 使用說明</button>
        </div>
        <div class="clearfix"></div>

<?php if (!$perms['canView']): ?>
        <div><h4><i class="fa fa-lock"></i> 無審核表單檢閱權限</h4><p>請洽系統管理者於「使用者權限設定」指派「審核表單」相關角色。</p></div>
<?php else: ?>
        <div class="rf-toolbar">
            <label>模板</label><select id="tplFilter"><option value="0">全部</option></select>
            <button class="btn-warm" id="btnAdd" style="display:none;"><i class="fa fa-plus"></i> 新增表單</button>
            <a href="review_form_template.php" class="btn" style="margin-left:auto;height:30px;line-height:28px;padding:0 12px;border:1px solid #D8BE93;border-radius:4px;color:#5b3a1e;text-decoration:none;">模板管理→</a>
        </div>
        <div class="rf-table-wrap">
        <table class="rf-tbl">
            <thead><tr><th>編號</th><th>模板</th><th>建立日期</th><th>填表人</th><th>狀態</th><th style="width:90px;">操作</th></tr></thead>
            <tbody id="listBody"><tr><td colspan="7" style="text-align:center;color:#8a6d45;">載入中…</td></tr></tbody>
        </table>
        </div>
<?php endif; ?>
    </div>
</div>
</div>

<!-- 新增表單 modal -->
<div class="rf-mask" id="addMask"><div class="rf-modal" style="max-width:480px;">
    <div class="m-head"><span>新增表單</span><span class="m-close" onclick="closeMask('addMask')">✕</span></div>
    <div class="m-body">
        <label>選擇模板</label><select id="addTplSel" style="width:100%;"></select>
        <label>建立日期</label><input type="date" id="addBizDate" max="9999-12-31" style="width:100%;">
    </div>
    <div class="m-foot"><button class="b-cancel" onclick="closeMask('addMask')">取消</button><button class="b-ok" onclick="submitAdd()">建立</button></div>
</div></div>

<!-- 檢視/編輯 modal -->
<div class="rf-mask" id="viewMask"><div class="rf-modal">
    <div class="m-head"><span id="viewTitle">表單</span><span class="m-close" onclick="closeMask('viewMask')">✕</span></div>
    <div class="m-body" id="viewBody"></div>
</div></div>

<!-- 使用說明 modal（鐵律7） -->
<div class="rf-mask" id="helpUseMask"><div class="rf-modal" style="max-width:760px;">
    <div class="m-head"><span>使用說明 — 審核表單</span><span class="m-close" onclick="closeMask('helpUseMask')">✕</span></div>
    <div class="m-body help-doc" style="font-size:13px;color:#5b3a1e;line-height:1.8;">
        <h4>功能說明</h4>
        依「審核表單模板管理」建好的模板建立表單（首發：2-TD-04-01 仿冒零件防制審核表、2-TD-03-01 產品安全審核表），逐列填寫項目與模板定義的欄位，可指定負責單位/負責人並線上簽名，送出後依模板設定走審核/核准。
        <h4>操作步驟</h4>
        <b>①新增表單</b>：選擇模板、填建立日期，建立後進入草稿編輯畫面，「填表人」固定為建立者本人，表單名稱固定沿用模板名稱。<br>
        <b>②填寫項次</b>：用「+新增列」「-刪除末列」增減項目，逐列填寫內容與模板定義的欄位；可設定該列的負責單位（可多選，該部門任一主管簽即算完成）與負責人（可多選，每人都要各自簽）；有設定「相關日期」欄位的模板可逐列填寫。每個項目可用「+小項」拆出多個小項（例如同一項目下有好幾點要分別敘述），每個小項的「項目」內容、自訂欄位、負責部門/負責人、簽名確認全部各自獨立填寫（負責人不同、各自簽自己的），項次編號只在該項目第一列顯示；日期／下拉選單欄位可在表頭一次選好值按「整欄套用」，快速套用到目前所有項目與小項的同一欄，不用逐列手動填相同值。<br>
        <b>③送出</b>：草稿階段可存檔或送出；送出後內容鎖定不可再編輯，依模板設定進入審核（審核部門任一主管審過即完成）→ 核准（依模板設定的核准優先序解析）。<br>
        <b>④負責人簽名</b>：模板設為「現場密碼簽名」時，畫面上各負責人可自行輸入本人密碼簽名；設為「通知回簽」時，送出後系統會通知負責人前來簽名。<br>
        <b>⑤列印</b>：完成或進行中都可列印，依模板設定的紙張大小（A4/A3）自動縮放至一頁，頁碼顯示於左下角、綁定的 AS 文件編號顯示於右下角，簽章一律蓋章並帶日期。<br>
        <b>⑥複製表單</b>：任何狀態的表單（含已完成）都可按「複製此表單」，以複製者本人的身分建立一份新草稿，項次內容比照原表單帶入，但不含簽名/審核/核准紀錄，需重新走一次流程。
        <h4>重要行為</h4>
        ・只有填表人本人可以編輯/送出自己的草稿；已送出的表單內容鎖定，不可再修改項次。<br>
        ・草稿只有填表人本人能刪除；已送出（含已完成）的表單一般人不可刪除，僅管理員能刪（會連同審核/核准紀錄一併移除，無法復原）。<br>
        ・審核/核准為 OR-gate：合格名單中任一人處理即完成該關，其餘人之後看到的會是唯讀狀態。<br>
        ・核准人解析到送出表單的本人時會自動跳下一順位，不會球員兼裁判。
        <h4>權限角色</h4>
        審核表單檢閱＝看清單（僅看自己建立的）；檢視全部＝看全部人建立的表單；審核表單建立＝新增/填寫/送出；模板管理＝可另到「模板管理」頁設定；管理者全權。
    </div>
    <div class="m-foot"><button class="b-ok" onclick="closeMask('helpUseMask')">關閉</button></div>
</div></div>

<script src="../../resource/js/jquery.min.js"></script>
<script src="../../resource/js/bootstrap.min.js"></script>
<script src="../../resource/js/nprogress.js"></script>
<script src="../../resource/js/custom.min.js"></script>
<script src="../../resource/js/eg_input_rules.js?v=<?= @filemtime(__DIR__.'/../../resource/js/eg_input_rules.js') ?>"></script>
<script src="../../resource/js/eg_stamp.js?v=<?= @filemtime(__DIR__.'/../../resource/js/eg_stamp.js') ?>"></script>
<script src="../../resource/js/eg_date_fmt.js?v=<?= @filemtime(__DIR__.'/../../resource/js/eg_date_fmt.js') ?>"></script>
<script>
var API = '../../src/store/ReviewForm_API.php';
var META = {}, TEMPLATES = [], ITEMS = [], CUR = null, CUR_SCHEMA = null;
var PREVIEW_MODE = (new URLSearchParams(location.search).get('preview') === '1');
function esc(s){ return $('<div>').text(s==null?'':s).html(); }
function dispDate(d){ return (typeof egFmtDate === 'function') ? egFmtDate(d) : (d||''); }
function openMask(id){ $('#'+id).css('display','block'); }
function closeMask(id){ $('#'+id).css('display','none'); }
$(document).ready(function(){
    var $am = $('#sidebar-menu .nav.side-menu > li.active');
    if ($am.length) { $am.removeClass('active').find('ul.child_menu').hide(); $am.find('li.current-page').removeClass('current-page'); }
    $('#sidebar-menu').css('visibility','visible');
    if (PREVIEW_MODE) { $('.rf-toolbar,.rf-table-wrap').hide(); $('h2').text('審核表單 — 試填預覽'); }
});
$('#btnPageHelp').on('click', function(){ openMask('helpUseMask'); });
var STATUS_LABEL = {draft:'草稿', submitted:'已送出', reviewing:'審核中', approving:'核准中', approved:'已完成', rejected:'已退回', void:'已作廢'};
/* 存檔/送出等按鈕若連線中斷或伺服器回傳非 JSON（如 PHP 警告混進輸出），$.post 的 success callback 完全不會觸發，
   畫面就會看起來「按了沒反應」，使用者無從得知到底存了沒（2026-08-13 使用者實際回報過一次）。
   統一在這裡攔截，讓任何 ajax 失敗都至少會跳出提示，不會悄悄無聲失敗。 */
$(document).ajaxError(function(e, jqxhr, settings){
    if (String(settings.url||'').indexOf('ReviewForm_API.php')<0) return;
    alert('連線或伺服器發生錯誤，請重新整理頁面確認資料是否已存檔，再重試一次。');
});

function loadMeta(cb){
    $.getJSON(API, {action:'meta'}, function(res){
        if (!res.ok){ alert(res.error||'載入失敗'); return; }
        META = res;
        window.__ownCompany = META.company_name || '';   // eg_stamp.js 預設回墨印章讀這個全域變數印公司名，比照 meeting_record.php
        if (META.perms.canCreate) $('#btnAdd').show();
        if (cb) cb();
    });
}
function loadTemplates(cb){
    $.getJSON('../../src/store/ReviewForm_API.php', {action:'template_list'}, function(res){
        if (!res.ok) return;
        TEMPLATES = (res.templates||[]).filter(function(t){ return t.status==='active'; });
        var opts = TEMPLATES.map(function(t){ return '<option value="'+t.id+'">'+esc(t.name)+'</option>'; }).join('');
        $('#tplFilter').html('<option value="0">全部</option>'+opts);
        $('#addTplSel').html(opts);
        if (cb) cb();
    });
}
$('#btnAdd').on('click', function(){
    $('#addBizDate').val(META.today);
    openMask('addMask');
});
function submitAdd(){
    var tid = $('#addTplSel').val();
    if (!tid){ alert('請選擇模板'); return; }
    $.post(API, {action:'instance_create', csrf:META.csrf, template_id:tid, business_date:$('#addBizDate').val()}, function(res){
        if (!res.ok){ alert(res.error||'建立失敗'); return; }
        closeMask('addMask'); loadList(); openView(res.id);
    }, 'json');
}
$('#tplFilter').on('change', loadList);
function loadList(){
    $.getJSON(API, {action:'instance_list', template_id:$('#tplFilter').val()||0}, function(res){
        if (!res.ok){ alert(res.error||'載入失敗'); return; }
        var h = '';
        res.instances.forEach(function(r){
            h += '<tr><td>#'+r.id+'</td><td>'+esc(r.template_name)+'</td><td>'+dispDate(r.business_date)+'</td>'
               + '<td>'+esc(r.created_by_name)+'</td><td><span class="st-badge st-'+r.status+'">'+STATUS_LABEL[r.status]+'</span></td>'
               + '<td><button onclick="openView('+r.id+')">開啟</button></td></tr>';
        });
        $('#listBody').html(h || '<tr><td colspan="7" style="text-align:center;color:#8a6d45;padding:10px;">尚無資料</td></tr>');
    });
}

/* ============ 檢視/編輯 ============ */
function openView(id){
    $.getJSON(API, {action:'instance_get', id:id}, function(res){
        if (!res.ok){ alert(res.error||'載入失敗'); return; }
        CUR = res.instance; CUR_SCHEMA = res.schema; CUR.tpl = res.template;
        CUR.as_doc_no = res.as_doc_no; CUR.company_name = res.company_name;
        CUR.review = res.review; CUR.approval = res.approval; CUR.can_review = res.can_review; CUR.can_approve = res.can_approve;
        ITEMS = (res.items||[]).map(function(it){
            return {id:it.id, subitems: (it.subitems||[]).map(function(s){
                return {id:s.id, content:s.content||'', data:s.data||{},
                         owner_depts:(s.owner_depts?String(s.owner_depts).split(',').filter(Boolean):[]),
                         owner_users:(s.owner_users?String(s.owner_users).split(',').filter(Boolean):[]),
                         confirms:s.confirms||[], required_signers:s.required_signers||[], fully_signed:s.fully_signed};
            })};
        });
        $('#viewTitle').text('#'+CUR.id+' '+CUR.tpl.name+'（'+STATUS_LABEL[CUR.status]+'）');
        renderView();
        openMask('viewMask');
    });
}
function isDraftMine(){ return CUR.status==='draft' && String(CUR.created_by)===String(META.uid); }
function renderView(){
    var h = '';
    if (PREVIEW_MODE) h += '<div style="background:#FFF7E8;border:1px dashed #E8D5B5;border-radius:6px;padding:6px 10px;margin-bottom:10px;font-size:12.5px;color:#8a6d45;">'
        + '<i class="fa fa-flask"></i> 試填預覽模式：這裡的內容<b>不會儲存、不會建立實際表單資料</b>，純粹用來檢查目前欄位定義的排版與列印效果。關閉分頁即消失。</div>';
    h += '<div style="max-width:220px;"><label>建立日期</label><input type="date" id="vBizDate" max="9999-12-31" value="'+esc(CUR.business_date)+'" '+(isDraftMine()?'':'disabled')+'></div>';
    if (!PREVIEW_MODE && CUR.status!=='draft' && CUR.submit_date) {
        h += '<div style="margin-top:4px;font-size:12.5px;color:#8a6d45;">送出日：'+dispDate(CUR.submit_date)+
             (META.perms.isAdmin ? ' <a href="javascript:void(0)" onclick="editSubmitDate()" style="margin-left:6px;">（超級管理員：修改送出日）</a>' : '')+'</div>';
    }
    h += '<div class="itm-tbl-wrap"><table class="itm-tbl"><thead><tr><th style="width:26px;">#</th><th>項目</th>';
    (CUR_SCHEMA.fields||[]).forEach(function(c){ if (c.layout!=='block') h += '<th>'+esc(c.label)+colFillHtml(c)+'</th>'; });
    h += '<th>負責單位/負責人</th>';
    if ((CUR_SCHEMA.sign_mode||'password')!=='none') h += '<th>簽名</th>';
    h += (isDraftMine()?'<th></th>':'')+'</tr></thead><tbody id="itmBody" data-eg-row-add="itemAdd" data-eg-row-del="itemDelLast"></tbody></table></div>';
    if (isDraftMine()) h += '<button class="rf-btn-sm" onclick="itemAdd()" style="margin-right:6px;">+新增列</button><button class="rf-btn-sm" onclick="itemDelLast()">-刪除末列</button>';
    h += '<div style="margin-top:12px;">';
    if (PREVIEW_MODE) {
        h += '<span style="color:#8a6d45;font-size:12px;">試填後按下方「列印」即可查看實際排版；不需要送出或審核。</span>';
    } else if (isDraftMine()) {
        h += '<button class="btn-warm" style="height:32px;padding:0 14px;border-radius:4px;border:1px solid #d98a33;color:#fff;background:#F0A24B;" onclick="saveDraft()">存檔</button> '
           + '<button style="height:32px;padding:0 14px;border-radius:4px;border:1px solid #d98a33;background:#fff;color:#5b3a1e;" onclick="submitForm()">送出</button> '
           + '<button style="height:32px;padding:0 14px;border-radius:4px;border:1px solid #c23f28;background:#fff;color:#DD5138;" onclick="deleteForm()">刪除</button>';
    } else if (META.perms.canAdmin) {
        // 非草稿（已送出/審核中/已完成…）一般人不可刪，僅管理員可刪（比照後端 instance_delete 的守門邏輯）。
        h += '<button style="height:32px;padding:0 14px;border-radius:4px;border:1px solid #c23f28;background:#fff;color:#DD5138;" onclick="deleteForm()">管理員刪除</button>';
    }
    if (!PREVIEW_MODE && META.perms.canCreate) h += ' <button style="height:32px;padding:0 14px;border-radius:4px;border:1px solid #D8BE93;background:#fff;color:#5b3a1e;" onclick="duplicateForm()">複製此表單</button>';
    if (META.perms.canPrint || PREVIEW_MODE) h += ' <button style="height:32px;padding:0 14px;border-radius:4px;border:1px solid #D8BE93;background:#fff;color:#5b3a1e;" onclick="printForm()">列印</button>';
    h += '</div>';
    if (CUR.can_review) h += reviewBoxHtml('review');
    if (CUR.can_approve) h += reviewBoxHtml('approval');
    $('#viewBody').html(h);
    renderItems();
}
function reviewBoxHtml(kind){
    var lbl = kind==='review' ? '審核' : '核准';
    return '<div class="decide-box"><b>待您'+lbl+'</b><br><textarea id="note_'+kind+'" placeholder="退回原因（退回時必填）" style="width:100%;margin:6px 0;" rows="2"></textarea>'
         + '<button class="btn-warm" style="height:30px;padding:0 14px;border-radius:4px;border:1px solid #d98a33;color:#fff;background:#F0A24B;" onclick="decide(\''+kind+'\',\'approved\')">'+lbl+'通過</button> '
         + '<button style="height:30px;padding:0 14px;border-radius:4px;border:1px solid #c23f28;background:#fff;color:#DD5138;" onclick="decide(\''+kind+'\',\'rejected\')">退回</button></div>';
}
function decide(kind, decision){
    var note = $('#note_'+kind).val();
    if (decision==='rejected' && !$.trim(note)){ alert('退回必須填寫原因'); return; }
    $.post(API, {action:(kind==='review'?'review_decide':'approval_decide'), csrf:META.csrf, instance_id:CUR.id, decision:decision, note:note}, function(res){
        if (!res.ok){ alert(res.error||'處理失敗'); return; }
        loadList(); openView(CUR.id);
    }, 'json');
}

/* ---- 項次列 ---- */
function rvfBlankSubitem(){ return {id:0, content:'', data:{}, owner_depts:[], owner_users:[], confirms:[], required_signers:[], fully_signed:false}; }
function itemAdd(){ ITEMS.push({id:0, subitems:[rvfBlankSubitem()]}); renderItems(); }
function itemDelLast(){ if (ITEMS.length) ITEMS.pop(); renderItems(); }
function itemDel(i){ ITEMS.splice(i,1); renderItems(); }
/* 小項（2026-08-12 新增，使用者明確要求）：一個項目可拆多個小項，每個小項各自的「項目」內容、自訂欄位(下拉/日期/文字等)、
   負責部門/負責人、簽名確認全部各自獨立不合併（「項目」只是共用同一個項次編號的分組容器，見 renderItems()）。
   全站每個模板都自動具備此功能，不另設模板層級開關；小項數量不限，至少保留 1 筆（刪到剩 1 筆就不能再刪，
   要整組刪除請用該項次列的刪除鈕 itemDel）。 */
function subItemAdd(i){ if (ITEMS[i]) { ITEMS[i].subitems.push(rvfBlankSubitem()); renderItems(); } }
function subItemDel(i,k){ if (ITEMS[i] && ITEMS[i].subitems.length>1) { ITEMS[i].subitems.splice(k,1); renderItems(); } }
function subItemContentEdit(i,k,val){ if (ITEMS[i] && ITEMS[i].subitems[k]) ITEMS[i].subitems[k].content = val; }
function subItemFieldEdit(i,k,key,val){ if (ITEMS[i] && ITEMS[i].subitems[k]) ITEMS[i].subitems[k].data[key] = val; }
function subItemContentHtml(i,k,sub,n){
    var ta = '<textarea '+(isDraftMine()?'':'disabled')+' onchange="subItemContentEdit('+i+','+k+',this.value)">'+esc(sub.content)+'</textarea>';
    if (!isDraftMine()) return ta;
    var btns = '<div class="subitem-ctrl">';
    btns += '<span class="rf-mini-btn" onclick="subItemAdd('+i+')">+小項</span>';
    if (n>1) btns += '<span class="rf-mini-btn" onclick="subItemDel('+i+','+k+')">-此小項</span>';
    btns += '</div>';
    return ta+btns;
}
/* 項次(自動編號)欄位在有小項時只在第一列顯示數字、其餘小項列留空（使用者明確要求；欄位本身不合併，只是不重複顯示內容） */
function fieldInputHtml(i, k, c){
    var sub = ITEMS[i].subitems[k];
    var v = sub.data[c.key] || '';
    var cls = c.layout==='block' ? 'fld-block' : '';
    var lbl = c.layout==='block' ? '<div class="fld-lbl">'+esc(c.label)+'</div>' : '';
    var dis = isDraftMine() ? '' : 'disabled';
    if (c.type==='seq') return '<div class="'+cls+'" style="text-align:center;font-weight:bold;color:#5b3a1e;">'+lbl+(k===0?(i+1):'')+'</div>';
    if (c.type==='date') return '<div class="'+cls+'">'+lbl+'<input type="date" max="9999-12-31" '+dis+' value="'+esc(v)+'" onchange="subItemFieldEdit('+i+','+k+',\''+c.key+'\',this.value)"></div>';
    if (c.type==='select') {
        var opts = '<option value="">'+(c.placeholder?esc(c.placeholder):'請選擇')+'</option>' + (c.options||[]).map(function(o){ return '<option value="'+esc(o)+'"'+(o===v?' selected':'')+'>'+esc(o)+'</option>'; }).join('');
        return '<div class="'+cls+'">'+lbl+'<select '+dis+' onchange="subItemFieldEdit('+i+','+k+',\''+c.key+'\',this.value)">'+opts+'</select></div>';
    }
    if (c.type==='textarea') return '<div class="'+cls+'">'+lbl+'<textarea '+dis+' placeholder="'+esc(c.placeholder)+'" onchange="subItemFieldEdit('+i+','+k+',\''+c.key+'\',this.value)">'+esc(v)+'</textarea></div>';
    return '<div class="'+cls+'">'+lbl+'<input type="text" '+dis+' placeholder="'+esc(c.placeholder)+'" value="'+esc(v)+'" onchange="subItemFieldEdit('+i+','+k+',\''+c.key+'\',this.value)"></div>';
}
/* 整欄套用（2026-08-12 新增，使用者明確要求）：日期／下拉選單欄位可在表頭選一次值，一鍵套用到目前所有項目、
   所有小項的這一欄，取代逐列手動填相同值。只在草稿本人編輯時顯示；只支援 date/select 兩種類型。 */
function colFillHtml(c){
    if (!isDraftMine()) return '';
    if (c.type==='date') {
        return '<div class="col-fill"><input type="date" max="9999-12-31" class="col-fill-inp" id="colFill_'+c.key+'" data-eg-skip>'
             + '<span class="rf-mini-btn" onclick="fillColumn(\''+c.key+'\')">整欄套用</span></div>';
    }
    if (c.type==='select') {
        var opts = '<option value="">（選項）</option>' + (c.options||[]).map(function(o){ return '<option value="'+esc(o)+'">'+esc(o)+'</option>'; }).join('');
        return '<div class="col-fill"><select class="col-fill-inp" id="colFill_'+c.key+'" data-eg-skip>'+opts+'</select>'
             + '<span class="rf-mini-btn" onclick="fillColumn(\''+c.key+'\')">整欄套用</span></div>';
    }
    return '';
}
function fillColumn(key){
    if (!isDraftMine()) return;
    var val = $('#colFill_'+key).val();
    if (!val) { alert('請先在表頭選擇要整欄套用的值'); return; }
    ITEMS.forEach(function(it){ (it.subitems||[]).forEach(function(s){ s.data[key] = val; }); });
    renderItems();
}
function deptTagHtml(i, k, ids){
    var tags = ids.map(function(id){ var d=(META.departments||[]).find(function(x){return String(x.id)===String(id);}); return d?'<span class="tg">'+esc(d.name)+'<i class="fa fa-times" onclick="ownerDeptDel('+i+','+k+',\''+id+'\')"></i></span>':''; }).join('');
    return '<div class="dp-pick itm-dp" data-i="'+i+'" data-k="'+k+'"><div class="dp-tags">'+tags+'</div>'+(isDraftMine()?'<input type="text" class="itm-dp-kw" placeholder="選部門…" data-eg-skip autocomplete="off"><div class="dp-list"></div>':'')+'</div>';
}
function userTagHtml(i, k, ids, deptIds){
    var tags = ids.map(function(id){ var p=(META.people||[]).find(function(x){return String(x.id)===String(id);}); return p?'<span class="tg">'+esc(p.user_cname)+'<i class="fa fa-times" onclick="ownerUserDel('+i+','+k+',\''+id+'\')"></i></span>':''; }).join('');
    var ph = (deptIds && deptIds.length) ? '只列該部門人員…' : '選人員…（未選部門，列全公司）';
    return '<div class="dp-pick itm-up" data-i="'+i+'" data-k="'+k+'"><div class="dp-tags">'+tags+'</div>'+(isDraftMine()?'<input type="text" class="itm-up-kw" placeholder="'+ph+'" data-eg-skip autocomplete="off"><div class="dp-list"></div>':'')+'</div>';
}
function ownerDeptDel(i,k,id){ var s=ITEMS[i].subitems[k]; s.owner_depts = s.owner_depts.filter(function(x){return String(x)!==String(id);}); renderItems(); }
function ownerUserDel(i,k,id){ var s=ITEMS[i].subitems[k]; s.owner_users = s.owner_users.filter(function(x){return String(x)!==String(id);}); renderItems(); }
/* .dp-list 用 position:fixed（見上方CSS註解），顯示前要用輸入框當下在畫面上的實際座標現算位置。 */
function showDpList($input, $list){
    var r = $input[0].getBoundingClientRect();
    $list.css({left: r.left, top: r.bottom + 2, minWidth: Math.max(r.width, 150)}).show();
}
$(document).on('scroll', '.itm-tbl-wrap', function(){ $('.dp-list').hide(); });
$(window).on('resize', function(){ $('.dp-list').hide(); });
$(document).on('click', function(e){ if (!$(e.target).closest('.dp-pick,.dp-list').length) $('.dp-list').hide(); });
$(document).on('focus input', '.itm-dp-kw', function(){
    var $p=$(this).closest('.itm-dp'), i=$p.data('i'), k=$p.data('k'), it=ITEMS[i]; if(!it) return;
    var sub=it.subitems[k]; if(!sub) return;
    var kw=$.trim($(this).val()).toLowerCase(), h='';
    (META.departments||[]).forEach(function(d){
        if (kw && d.name.toLowerCase().indexOf(kw)<0) return;
        var on=(sub.owner_depts||[]).some(function(x){return String(x)===String(d.id);});
        h += '<div data-id="'+d.id+'" style="'+(on?'color:#b0a390;':'')+'">'+(on?'✔ ':'')+esc(d.name)+'</div>';
    });
    var $list = $p.find('.dp-list').html(h||'<div style="color:#b0a390;">查無部門</div>');
    showDpList($(this), $list);
});
$(document).on('click', '.itm-dp .dp-list div[data-id]', function(){
    var $p=$(this).closest('.itm-dp'), i=$p.data('i'), k=$p.data('k'), it=ITEMS[i]; if(!it) return;
    var sub=it.subitems[k]; if(!sub) return;
    var id=String($(this).data('id')), idx=sub.owner_depts.findIndex(function(x){return String(x)===id;});
    if (idx>=0) sub.owner_depts.splice(idx,1); else sub.owner_depts.push(id);
    renderItems();
});
$(document).on('focus input', '.itm-up-kw', function(){
    var $p=$(this).closest('.itm-up'), i=$p.data('i'), k=$p.data('k'), it=ITEMS[i]; if(!it) return;
    var sub=it.subitems[k]; if(!sub) return;
    var kw=$.trim($(this).val()).toLowerCase(), h='';
    // 選了負責部門後，負責人只列該部門(可複選部門則為聯集)的人，避免在全公司名單裡大海撈針；
    // 未選部門時維持列出全公司（使用者要求：多個部門要逐一「選部門→選人」，不要一次把部門全選完才選人，
    // 這裡的過濾行為本身就是照著這個順序運作——先選的部門會立刻篩到位）。
    // 比對用 dept_ids（含兼任的所有部門，見 people_lib.php），不只比對主要部門 dept_id，
    // 否則兼任該部門的人在篩選時會消失，選不到（2026-08-12 使用者明確要求）。
    var deptFilter = (sub.owner_depts||[]).map(String);
    (META.people||[]).forEach(function(p){
        var pDeptIds = (p.dept_ids||[p.dept_id]).map(String);
        if (deptFilter.length && !pDeptIds.some(function(d){ return deptFilter.indexOf(d)>=0; })) return;
        if (kw && p.user_cname.toLowerCase().indexOf(kw)<0) return;
        var on=(sub.owner_users||[]).some(function(x){return String(x)===String(p.id);});
        h += '<div data-id="'+p.id+'" style="'+(on?'color:#b0a390;':'')+'">'+(on?'✔ ':'')+esc(p.display)+'</div>';
    });
    var $list = $p.find('.dp-list').html(h||'<div style="color:#b0a390;">'+(deptFilter.length?'此部門查無人員':'查無人員')+'</div>');
    showDpList($(this), $list);
});
$(document).on('click', '.itm-up .dp-list div[data-id]', function(){
    var $p=$(this).closest('.itm-up'), i=$p.data('i'), k=$p.data('k'), it=ITEMS[i]; if(!it) return;
    var sub=it.subitems[k]; if(!sub) return;
    var id=String($(this).data('id')), idx=sub.owner_users.findIndex(function(x){return String(x)===id;});
    if (idx>=0) sub.owner_users.splice(idx,1); else sub.owner_users.push(id);
    renderItems();
});
/* 簽名確認掛在小項本身（2026-08-12 改版，使用者明確要求：每個小項的負責人與簽名各自獨立），sub.id 是小項在資料庫的真實 id。 */
function signSlotsHtml(sub){
    if (!sub.required_signers || !sub.required_signers.length) return '<span style="color:#b0a390;font-size:11px;">未指派負責人</span>';
    var doneUids = (sub.confirms||[]).map(function(c){ return String(c.user_id); });
    return sub.required_signers.map(function(s){
        var done = doneUids.indexOf(String(s.id))>=0;
        var c = (sub.confirms||[]).find(function(x){ return String(x.user_id)===String(s.id); });
        if (done) return '<div class="sign-slot sign-yes">✓ '+esc(s.user_cname)+'（'+dispDate(c.signed_at)+'）</div>';
        if (String(s.id)===String(META.uid) && CUR.status!=='draft') {
            return '<div class="sign-slot"><b>'+esc(s.user_cname)+'</b><br><input type="text" inputmode="numeric" placeholder="本人密碼" class="sign-pw" data-subitem="'+sub.id+'" data-uid="'+s.id+'" style="width:80px;" data-eg-skip>'
                 + '<button onclick="doItemConfirm('+sub.id+','+s.id+')" style="height:22px;font-size:11px;">簽名</button></div>';
        }
        return '<div class="sign-slot sign-no">未簽：'+esc(s.user_cname)+'</div>';
    }).join('');
}
function doItemConfirm(subitemId, uid){
    var pw = $('.sign-pw[data-subitem="'+subitemId+'"][data-uid="'+uid+'"]').val();
    $.post(API, {action:'item_confirm', csrf:META.csrf, subitem_id:subitemId, user_id:uid, password:pw}, function(res){
        if (!res.ok){ alert(res.error||'簽名失敗'); return; }
        openView(CUR.id);
    }, 'json');
}
function renderItems(){
    var h = '';
    var inlineFields = (CUR_SCHEMA.fields||[]).filter(function(c){ return c.layout!=='block'; });
    var blockFields = (CUR_SCHEMA.fields||[]).filter(function(c){ return c.layout==='block'; });
    var hasSignCol = (CUR_SCHEMA.sign_mode||'password')!=='none';
    // 負責部門/負責人/簽名/刪除鈕都不再合併（2026-08-12 改版：每個小項各自獨立負責人與簽名），
    // 每個小項自己一整列都是完整欄位，只有「項次」編號與「刪除整個項次」鈕只在該項目第一個小項列顯示。
    var blockColspan = 2 + inlineFields.length + (hasSignCol?1:0) + (isDraftMine()?1:0);
    // 有小項時，項次本身這一列(subitems[0]＝新增項次時原本就有的那一列)降級為純標題列：
    // 只有「項目」文字可填，其餘自訂欄位/負責部門/負責人/簽名全部不需要——因為大項只是標題，
    // 真正的內容與各自的負責人/簽名都在下面各個小項（2026-08-13 使用者明確要求）。
    var headingSpan = inlineFields.length + 1 + (hasSignCol?1:0);
    ITEMS.forEach(function(it,i){
        if (!it.subitems || !it.subitems.length) it.subitems = [rvfBlankSubitem()];
        var subs = it.subitems, n = subs.length;
        subs.forEach(function(sub,k){
            var isHeading = (k===0 && n>1);
            h += '<tr><td class="subitem-num">'+(k===0?(i+1):'')+'</td>';
            h += '<td'+(isHeading?' class="subitem-heading"':'')+'>'+subItemContentHtml(i,k,sub,n)+'</td>';
            if (isHeading) {
                h += '<td colspan="'+headingSpan+'" class="subitem-heading-note">'+(isDraftMine()?'（大項標題，欄位/負責人/簽名由下方各小項各自填寫）':'')+'</td>';
            } else {
                inlineFields.forEach(function(c){ h += '<td>'+fieldInputHtml(i,k,c)+'</td>'; });
                h += '<td><div class="owner-lbl">負責部門</div>'+deptTagHtml(i,k,sub.owner_depts)+'<div class="owner-lbl">負責人</div>'+userTagHtml(i,k,sub.owner_users,sub.owner_depts)+'</td>';
                if (hasSignCol) h += '<td>'+signSlotsHtml(sub)+'</td>';
            }
            if (isDraftMine()) h += '<td>'+(k===0?'<span class="rf-del" onclick="itemDel('+i+')" title="刪除整個項次(含全部小項)"><i class="fa fa-times"></i></span>':'')+'</td>';
            h += '</tr>';
            if (blockFields.length && !isHeading) {
                h += '<tr><td></td><td colspan="'+blockColspan+'">' + blockFields.map(function(c){ return fieldInputHtml(i,k,c); }).join('') + '</td></tr>';
            }
        });
    });
    $('#itmBody').html(h || '<tr><td colspan="10" style="text-align:center;color:#8a6d45;">尚未建立項目</td></tr>');
}

function collectItems(){
    return ITEMS.map(function(it){
        var n = it.subitems.length;
        return {id:it.id, subitems: it.subitems.map(function(s,k){
            var isHeading = (k===0 && n>1);
            // 大項標題列存檔時強制清空自訂欄位/負責部門/負責人（2026-08-13 使用者明確要求：
            // 有小項時大項只是標題，不需要這些值，避免殘留舊資料造成「簽不到卻要求簽名」的孤兒狀態）。
            return {id:s.id, content:s.content,
                     data: isHeading ? {} : s.data,
                     owner_depts: isHeading ? [] : s.owner_depts,
                     owner_users: isHeading ? [] : s.owner_users};
        })};
    });
}
function saveDraft(cb){
    $.post(API, {action:'instance_save_items', csrf:META.csrf, instance_id:CUR.id, business_date:$('#vBizDate').val(), items:JSON.stringify(collectItems())}, function(res){
        if (!res.ok){ alert(res.error||'儲存失敗'); return; }
        if (cb) cb(); else { loadList(); openView(CUR.id); alert('已儲存'); }
    }, 'json');
}
function submitForm(){
    saveDraft(function(){
        $.post(API, {action:'instance_submit', csrf:META.csrf, instance_id:CUR.id}, function(res){
            if (!res.ok){ alert(res.error||'送出失敗'); return; }
            loadList(); openView(CUR.id);
        }, 'json');
    });
}
function deleteForm(){
    var msg = CUR.status==='draft' ? '確定要刪除此草稿？' : '此表單狀態為「'+STATUS_LABEL[CUR.status]+'」，刪除後含審核/核准紀錄一併移除且無法復原，確定要刪除？';
    if (!confirm(msg)) return;
    $.post(API, {action:'instance_delete', csrf:META.csrf, instance_id:CUR.id}, function(res){
        if (!res.ok){ alert(res.error||'刪除失敗'); return; }
        closeMask('viewMask'); loadList();
    }, 'json');
}
function duplicateForm(){
    $.post(API, {action:'instance_duplicate', csrf:META.csrf, instance_id:CUR.id}, function(res){
        if (!res.ok){ alert(res.error||'複製失敗'); return; }
        loadList(); openView(res.id);
    }, 'json');
}
/* 補登舊資料用（ai-rules/21 鐵則2）：僅超級管理員看得到入口；回改後自動簽核紀錄的日期會同步跟著調整。 */
function editSubmitDate(){
    var d = prompt('修改送出日（僅影響此筆；自動簽核的紀錄會同步調整日期）：', CUR.submit_date||'');
    if (!d) return;
    if (!/^\d{4}-\d{2}-\d{2}$/.test(d)){ alert('請輸入 YYYY-MM-DD 格式的日期'); return; }
    $.post(API, {action:'instance_edit_submit_date', csrf:META.csrf, instance_id:CUR.id, submit_date:d}, function(res){
        if (!res.ok){ alert(res.error||'修改失敗'); return; }
        openView(CUR.id);
    }, 'json');
}

/* ============ 列印 ============ */
function egPrintWindow(title, bodyHtml, extraCss, docNo, paper, landscape){
    var asCss = String(docNo||'').replace(/['\\]/g,'');
    var css = '@page{size:'+(paper||'A4')+' '+(landscape?'landscape':'portrait')+';margin:12mm 8mm 16mm;}'
            + 'html,body{margin:0;padding:0;}'
            + 'body{font-family:"Microsoft JhengHei","微軟正黑體",sans-serif;color:#000;-webkit-print-color-adjust:exact;print-color-adjust:exact;}'
            + '.pt-head{text-align:center;margin-bottom:6px;}.pt-head .co{font-size:22px;font-weight:bold;letter-spacing:2px;}.pt-head .tt{font-size:16px;font-weight:bold;margin-top:3px;letter-spacing:1px;}'
            + '.rf-as-doc{position:fixed;right:8mm;bottom:6mm;font-size:9pt;color:#333;}'
            + (extraCss||'');
    var w = window.open('', '_blank');
    if (!w){ alert('請允許彈出視窗'); return; }
    w.document.write('<html><head><meta charset="utf-8"><title>'+esc(title)+'</title><style>'+css+'</style></head><body>'
        + bodyHtml
        + (asCss ? '<div class="rf-as-doc">'+asCss+'</div>' : '')
        + '<scr'+'ipt>window.onload=function(){'
        // 量 scrollHeight 前先讓一拍：印章 SVG 內含 textLength/lengthAdjust 需要字型計量才能定案排版，
        // onload 觸發當下量到的高度有時還沒完全穩定，量太早會讓縮放比例算少、印到最後footer被紙張邊界切掉。
        + 'setTimeout(function(){'
        + 'var pageH=('+(landscape ? (paper==='A3'?'297':'210') : (paper==='A3'?'420':'297'))+'-28)*96/25.4;'
        + 'var h=document.body.scrollHeight;'
        + 'var zr=1;'
        + 'if(h>pageH){ zr=Math.max(0.5, pageH/h); document.body.style.zoom=zr; }'
        // 「不可縮放」圖章（模板勾了 noScale，見 rf-stamp-noshrink 標記）：body 整頁縮小是為了塞進一頁，
        // 但圖章本身要維持設計時的實際尺寸不被跟著縮小——反向補償縮放比例抵銷掉 body 縮放的影響
        // （zoom 是會逐層相乘的 CSS 屬性，子元素設 1/zr 會讓最終視覺呈現剛好等於原始設計大小）。
        + 'if(zr<1){ document.querySelectorAll(".rf-stamp-noshrink").forEach(function(el){ el.style.zoom=(1/zr); }); }'
        // 頁碼只在超過一頁才顯示（ai-rules/16 第二節，比照 quotation_list_test.php 既有作法）：
        // zoom 縮放後再量一次高度，若仍超過約 92% 一頁高度才動態插入 @page 頁碼樣式，單頁文件完全不印頁碼。
        + 'setTimeout(function(){'
        + 'if(document.body.scrollHeight>pageH*0.92){'
        + 'var st=document.createElement("style");'
        + 'st.textContent="@page{ @bottom-left{ content:\'第 \' counter(page) \' 頁／共 \' counter(pages) \' 頁\'; font-size:9pt; color:#333; } }";'
        + 'document.head.appendChild(st);'
        + '}'
        + 'window.print();'
        + '},60);'
        + '},120);};</scr'+'ipt></body></html>');
    w.document.close();
}
// 圖章實際外徑 2.5 公分（使用者實測回報）：96px＝1英吋＝2.54公分，2.5*96/2.54≈94.5px（2026-08-13 換算修正，
// 之前寫死 91px 沒有對照過實際外徑；此值只給「沒有指定圖章模板」時的預設回墨印/掃描章當印刷尺寸用）。
var RF_STAMP_PX = (2.5 * 96 / 2.54).toFixed(1);
function rfCss(){
    // 2026-08-13 使用者再次回報偏擠，字級/欄位留白再加大一輪（13.5px→15.5px，padding 8px9px→10px11px）。
    return 'table.rf-p-items{width:100%;border-collapse:collapse;font-size:15.5px;margin-top:2px;}'
         + 'table.rf-p-items th,table.rf-p-items td{border:1px solid #333;padding:10px 11px;text-align:center;}'
         + 'table.rf-p-items td.t-left{text-align:left;}'
         + '.rf-p-datebar{text-align:right;font-size:12.5px;color:#333;margin-bottom:3px;}'
         // 只有「沒有指定圖章模板」時才強制覆蓋成推算出的實際外徑尺寸；有指定模板(rf-stamp-tpl)時一律尊重
         // 該模板自己在「圖章管理→線上圖章設計」設定的「大小(px)」，不再用固定值蓋過去（2026-08-13 使用者回報
         // 蓋出來感覺太小，追出來是這支固定 91px !important 蓋掉了模板自訂尺寸）。
         + '.rf-stamp-defsize svg{width:'+RF_STAMP_PX+'px !important;height:'+RF_STAMP_PX+'px !important;-webkit-print-color-adjust:exact;print-color-adjust:exact;}'
         + 'table.rf-p-foot{width:100%;margin-top:16px;margin-bottom:12mm;font-size:13px;}'
         + 'table.rf-p-foot td{padding:6px;width:33.33%;text-align:center;vertical-align:top;}'
         + 'table.rf-p-foot .foot-lbl{margin-bottom:4px;}'
         + 'table.rf-p-foot .foot-na{color:#888;font-size:12px;}'
         + 'table.rf-p-foot .stamp-wrap{margin:0;}';
}
/* schema 有設定時，印出來的大小由模板自己的「大小(px)」決定（SVG width/height屬性本身就是那個值，不用CSS蓋）；
   schema 沒設定(退回預設回墨印/掃描章)時才用 RF_STAMP_PX 固定覆蓋，兩者互斥、不會互相蓋過。
   schema.noScale＝true 時額外包一層 rf-stamp-noshrink 標記，讓 egPrintWindow() 整頁縮小時對這個元素做反向補償，
   使用者原話：「就算列印畫面有縮小，也不要縮小圖章」。 */
function stampOrName(name, date, isDeputy, schema){
    var html = (window.EGStamp && EGStamp.stamp) ? EGStamp.stamp(name, date, !!isDeputy, schema) : esc(name||'');
    if (!schema) html = '<span class="rf-stamp-defsize">'+html+'</span>';
    else if (schema.noScale) html = '<span class="rf-stamp-noshrink">'+html+'</span>';
    return html;
}
/* 兩種圖章樣式各自綁定：逐列簽章(list_stamp) 用在項目表每列負責人簽名；製表/審核/核准(footer_stamp) 用在頁尾三欄。
   模板沒設定時 schema 是 null，EGStamp.stamp 會自動退回預設樣式。 */
function stampList(name, date, isDeputy){ return stampOrName(name, date, isDeputy, CUR.tpl.list_stamp ? CUR.tpl.list_stamp.schema : null); }
function stampFooter(name, date, isDeputy){ return stampOrName(name, date, isDeputy, CUR.tpl.footer_stamp ? CUR.tpl.footer_stamp.schema : null); }
function printForm(){
    var t = CUR.tpl, schema = CUR_SCHEMA;
    var h = '<div class="pt-head"><div class="co">'+esc(CUR.company_name||'')+'</div><div class="tt">'+esc(t.name)+'</div></div>';
    // 表頭不重複顯示狀態/填表人（2026-08-13 使用者明確要求：製表人姓名+日期下方本來就有「製表」圖章，不必再印一次；
    // 狀態對已完成的表單沒有意義），建立日期改印在項目表格右上角。
    h += '<div class="rf-p-datebar">建立日期：'+dispDate(CUR.business_date)+'</div>';
    var pHasSignCol = (schema.sign_mode||'password')!=='none';
    var inlineFieldsP = (schema.fields||[]);
    h += '<table class="rf-p-items"><thead><tr><th>#</th><th>項目</th>';
    inlineFieldsP.forEach(function(c){ h += '<th>'+esc(c.label)+'</th>'; });
    h += '<th>負責單位/人</th>'+(pHasSignCol?'<th>簽名</th>':'')+'</tr></thead><tbody>';
    var headingSpanP = inlineFieldsP.length + 1 + (pHasSignCol?1:0);
    ITEMS.forEach(function(it,i){
        var subs = (it.subitems&&it.subitems.length) ? it.subitems : [rvfBlankSubitem()];
        var n = subs.length;
        subs.forEach(function(sub,k){
            var isHeading = (k===0 && n>1);
            h += '<tr><td>'+(k===0?(i+1):'')+'</td><td class="t-left">'+esc(sub.content).replace(/\n/g,'<br>')+'</td>';
            if (isHeading) {
                // 有小項時大項這一列只是標題，其餘欄位整列合併成一個空白儲存格（2026-08-13 使用者明確要求；
                // 項次已經在最前面單獨一格，這裡的合併不含項次欄，符合「除了項次外都合併」）。
                h += '<td colspan="'+headingSpanP+'"></td></tr>';
                return;
            }
            inlineFieldsP.forEach(function(c){
                var cellTxt = c.type==='seq' ? (k===0?String(i+1):'') : (c.type==='date' ? dispDate(sub.data[c.key]||'') : esc(sub.data[c.key]||''));
                h += '<td>'+cellTxt+'</td>';
            });
            var ownerTxt = (sub.owner_depts||[]).map(function(id){ var d=(META.departments||[]).find(function(x){return String(x.id)===String(id);}); return d?d.name:''; })
                .concat((sub.owner_users||[]).map(function(id){ var p=(META.people||[]).find(function(x){return String(x.id)===String(id);}); return p?p.user_cname:''; })).filter(Boolean).join('、');
            h += '<td>'+esc(ownerTxt)+'</td>';
            if (pHasSignCol) {
                var signHtml = (sub.confirms||[]).map(function(c){ return stampList(c.user_name, dispDate(c.signed_at)); }).join('');
                if (!signHtml && PREVIEW_MODE && (sub.owner_depts.length || sub.owner_users.length)) signHtml = stampList('（簽名樣式預覽）', dispDate(CUR.business_date));
                h += '<td>'+signHtml+'</td>';
            }
            h += '</tr>';
        });
    });
    h += '</tbody></table>';
    h += '<table class="rf-p-foot"><tr>';
    h += '<td><div class="foot-lbl">審核</div>' + (
        !t.need_review ? '<div class="foot-na">（本模板免審核）</div>'
        : (CUR.review && CUR.review.status==='approved' ? stampFooter(CUR.review.approver_name, dispDate(CUR.review.decided_at)) : '')
    ) + '</td>';
    h += '<td><div class="foot-lbl">核准</div>' + (
        !t.need_approval ? '<div class="foot-na">（本模板免核准）</div>'
        : (CUR.approval && CUR.approval.status==='approved' ? stampFooter(CUR.approval.approver_name, dispDate(CUR.approval.decided_at)) : '')
    ) + '</td>';
    h += '<td><div class="foot-lbl">製表</div>' + stampFooter(CUR.created_by_name, dispDate(CUR.business_date)) + '</td>';
    h += '</tr></table>';
    egPrintWindow(t.name, h, rfCss(), CUR.as_doc_no, t.paper_size, t.orientation!=='portrait');
}

/* 試填預覽入口（由 review_form_template.php 的「試填預覽並列印」開新分頁帶 ?preview=1 進來）：
   讀 sessionStorage 裡未存檔的欄位定義，組一個假的 CUR/CUR_SCHEMA 直接開檢視畫面，不呼叫 instance_get，
   不建立任何 rf_instance 資料列；存檔/送出/刪除/審核/核准一律不顯示，只留增減列與列印可用。 */
function initPreview(){
    var raw = sessionStorage.getItem('rvf_preview_payload');
    if (!raw) { alert('找不到預覽資料，請從「審核表單模板管理」的「試填預覽並列印」按鈕開啟'); return; }
    var payload = JSON.parse(raw);
    CUR_SCHEMA = payload.schema || {fields:[], sign_mode:'password'};
    ITEMS = [];
    function openPreview(tpl, asDocNo){
        CUR = {
            id: 0, title: '', business_date: META.today, status: 'draft',
            created_by: META.uid, created_by_name: META.uname,
            tpl: tpl || {name: payload.tpl_name || '(未命名模板)', paper_size: payload.paper_size || 'A4', orientation:'landscape'},
            as_doc_no: asDocNo || '', company_name: META.company_name,
            review: null, approval: null, can_review: false, can_approve: false
        };
        $('#viewTitle').text('試填預覽 — ' + CUR.tpl.name);
        renderView();
        openMask('viewMask');
    }
    if (payload.tpl_id) {
        // 用真實模板列（含紙張/方向/圖章綁定等設定），確保試列印跟正式列印用同一套設定，不是只用畫面上暫存的兩三個欄位。
        $.getJSON(API, {action:'template_get', id:payload.tpl_id}, function(res){
            openPreview(res.ok ? res.template : null, res.ok && res.template.as_doc ? res.template.as_doc.doc_no : '');
        });
    } else openPreview(null, '');
}
if (PREVIEW_MODE) loadMeta(initPreview);
else loadMeta(function(){ loadTemplates(loadList); });
</script>
</body>
</html>
