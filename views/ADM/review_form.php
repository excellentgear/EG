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
        table.itm-tbl input[type=text], table.itm-tbl input[type=date], table.itm-tbl select { width:100%; border:1px solid #D8BE93; border-radius:4px; padding:3px 5px; font-size:12px; box-sizing:border-box; }
        .fld-block { display:block; margin-bottom:4px; } .fld-inline { display:inline-block; width:48%; margin:0 1% 4px; vertical-align:top; }
        .fld-lbl { font-size:10.5px; color:#8a6d45; }
        .dp-pick { position:relative; border:1px solid #D8BE93; border-radius:4px; background:#fff; padding:2px 3px; min-width:110px; margin-bottom:3px; }
        .dp-tags { display:flex; flex-wrap:wrap; gap:2px; }
        .dp-tags .tg { background:#F7E0BD; color:#5b3a1e; border-radius:9px; font-size:11px; padding:1px 5px 1px 7px; white-space:nowrap; }
        .dp-tags .tg i { cursor:pointer; color:#b5762a; margin-left:3px; }
        .dp-pick > input { width:100%; border:none !important; outline:none; font-size:11px; padding:2px 3px !important; }
        .dp-list { display:none; position:absolute; left:0; right:0; top:100%; z-index:30; background:#fff;
            border:1px solid #D8BE93; border-radius:0 0 4px 4px; max-height:150px; overflow-y:auto; box-shadow:0 4px 10px rgba(0,0,0,.12); min-width:150px; }
        .dp-list div { padding:3px 8px; font-size:11.5px; color:#5b3a1e; cursor:pointer; }
        .dp-list div:hover { background:#FBF0DD; }
        .rf-del { color:#DD5138; cursor:pointer; }
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
            <thead><tr><th>編號</th><th>模板</th><th>標題</th><th>業務日期</th><th>填表人</th><th>狀態</th><th style="width:90px;">操作</th></tr></thead>
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
        <label>標題（選填）</label><input type="text" id="addTitle" style="width:100%;">
        <label>業務日期</label><input type="date" id="addBizDate" max="9999-12-31" style="width:100%;">
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
        <b>①新增表單</b>：選擇模板、填標題（選填）與業務日期，建立後進入草稿編輯畫面，「填表人」固定為建立者本人。<br>
        <b>②填寫項次</b>：用「+新增列」「-刪除末列」增減項目，逐列填寫內容與模板定義的欄位；可設定該列的負責單位（可多選，該部門任一主管簽即算完成）與負責人（可多選，每人都要各自簽）；有設定「相關日期」欄位的模板可逐列填寫。<br>
        <b>③送出</b>：草稿階段可存檔或送出；送出後內容鎖定不可再編輯，依模板設定進入審核（審核部門任一主管審過即完成）→ 核准（依模板設定的核准優先序解析）。<br>
        <b>④負責人簽名</b>：模板設為「現場密碼簽名」時，畫面上各負責人可自行輸入本人密碼簽名；設為「通知回簽」時，送出後系統會通知負責人前來簽名。<br>
        <b>⑤列印</b>：完成或進行中都可列印，依模板設定的紙張大小（A4/A3）自動縮放至一頁，頁碼顯示於左下角、綁定的 AS 文件編號顯示於右下角，簽章一律蓋章並帶日期。
        <h4>重要行為</h4>
        ・只有填表人本人可以編輯/送出/刪除自己的草稿；已送出的表單內容鎖定，不可再修改項次。<br>
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

function loadMeta(cb){
    $.getJSON(API, {action:'meta'}, function(res){
        if (!res.ok){ alert(res.error||'載入失敗'); return; }
        META = res;
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
    $('#addTitle').val(''); $('#addBizDate').val(META.today);
    openMask('addMask');
});
function submitAdd(){
    var tid = $('#addTplSel').val();
    if (!tid){ alert('請選擇模板'); return; }
    $.post(API, {action:'instance_create', csrf:META.csrf, template_id:tid, title:$('#addTitle').val(), business_date:$('#addBizDate').val()}, function(res){
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
            h += '<tr><td>#'+r.id+'</td><td>'+esc(r.template_name)+'</td><td>'+esc(r.title||'')+'</td><td>'+dispDate(r.business_date)+'</td>'
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
            return {id:it.id, content:it.content||'', data: it.data||{},
                     owner_depts:(it.owner_depts?String(it.owner_depts).split(',').filter(Boolean):[]),
                     owner_users:(it.owner_users?String(it.owner_users).split(',').filter(Boolean):[]),
                     confirms: it.confirms||[], required_signers: it.required_signers||[], fully_signed: it.fully_signed};
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
    h += '<div class="grid2" style="display:grid;grid-template-columns:1fr 1fr;gap:0 14px;">'
          + '<div><label>標題</label><input type="text" id="vTitle" value="'+esc(CUR.title||'')+'" '+(isDraftMine()?'':'disabled')+'></div>'
          + '<div><label>業務日期</label><input type="date" id="vBizDate" max="9999-12-31" value="'+esc(CUR.business_date)+'" '+(isDraftMine()?'':'disabled')+'></div>'
          + '</div>';
    h += '<table class="itm-tbl"><thead><tr><th style="width:26px;">#</th><th>項目</th>';
    (CUR_SCHEMA.fields||[]).forEach(function(c){ if (c.layout!=='block') h += '<th>'+esc(c.label)+'</th>'; });
    h += '<th>負責單位/負責人</th>';
    h += '<th>簽名</th>'+(isDraftMine()?'<th></th>':'')+'</tr></thead><tbody id="itmBody"></tbody></table>';
    if (isDraftMine()) h += '<button onclick="itemAdd()" style="margin-right:6px;">+新增列</button><button onclick="itemDelLast()">-刪除末列</button>';
    h += '<div style="margin-top:12px;">';
    if (PREVIEW_MODE) {
        h += '<span style="color:#8a6d45;font-size:12px;">試填後按下方「列印」即可查看實際排版；不需要送出或審核。</span>';
    } else if (isDraftMine()) {
        h += '<button class="btn-warm" style="height:32px;padding:0 14px;border-radius:4px;border:1px solid #d98a33;color:#fff;background:#F0A24B;" onclick="saveDraft()">存檔</button> '
           + '<button style="height:32px;padding:0 14px;border-radius:4px;border:1px solid #d98a33;background:#fff;color:#5b3a1e;" onclick="submitForm()">送出</button> '
           + '<button style="height:32px;padding:0 14px;border-radius:4px;border:1px solid #c23f28;background:#fff;color:#DD5138;" onclick="deleteForm()">刪除</button>';
    }
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
function itemAdd(){ ITEMS.push({id:0, content:'', data:{}, owner_depts:[], owner_users:[], confirms:[], required_signers:[], fully_signed:false}); renderItems(); }
function itemDelLast(){ if (ITEMS.length) ITEMS.pop(); renderItems(); }
function itemDel(i){ ITEMS.splice(i,1); renderItems(); }
function itemEdit(i,key,val){ if (ITEMS[i]) ITEMS[i].content = val; }
function itemFieldEdit(i,key,val){ if (ITEMS[i]) ITEMS[i].data[key] = val; }
function fieldInputHtml(i, c){
    var v = ITEMS[i].data[c.key] || '';
    var cls = c.layout==='block' ? 'fld-block' : '';
    var lbl = c.layout==='block' ? '<div class="fld-lbl">'+esc(c.label)+'</div>' : '';
    var dis = isDraftMine() ? '' : 'disabled';
    if (c.type==='date') return '<div class="'+cls+'">'+lbl+'<input type="date" max="9999-12-31" '+dis+' value="'+esc(v)+'" onchange="itemFieldEdit('+i+',\''+c.key+'\',this.value)"></div>';
    if (c.type==='select') {
        var opts = '<option value="">'+(c.placeholder?esc(c.placeholder):'請選擇')+'</option>' + (c.options||[]).map(function(o){ return '<option value="'+esc(o)+'"'+(o===v?' selected':'')+'>'+esc(o)+'</option>'; }).join('');
        return '<div class="'+cls+'">'+lbl+'<select '+dis+' onchange="itemFieldEdit('+i+',\''+c.key+'\',this.value)">'+opts+'</select></div>';
    }
    if (c.type==='textarea') return '<div class="'+cls+'">'+lbl+'<textarea '+dis+' placeholder="'+esc(c.placeholder)+'" onchange="itemFieldEdit('+i+',\''+c.key+'\',this.value)">'+esc(v)+'</textarea></div>';
    return '<div class="'+cls+'">'+lbl+'<input type="text" '+dis+' placeholder="'+esc(c.placeholder)+'" value="'+esc(v)+'" onchange="itemFieldEdit('+i+',\''+c.key+'\',this.value)"></div>';
}
function deptTagHtml(i, ids){
    var tags = ids.map(function(id){ var d=(META.departments||[]).find(function(x){return String(x.id)===String(id);}); return d?'<span class="tg">'+esc(d.name)+'<i class="fa fa-times" onclick="ownerDeptDel('+i+',\''+id+'\')"></i></span>':''; }).join('');
    return '<div class="dp-pick itm-dp" data-i="'+i+'"><div class="dp-tags">'+tags+'</div>'+(isDraftMine()?'<input type="text" class="itm-dp-kw" placeholder="選部門…" data-eg-skip autocomplete="off"><div class="dp-list"></div>':'')+'</div>';
}
function userTagHtml(i, ids){
    var tags = ids.map(function(id){ var p=(META.people||[]).find(function(x){return String(x.id)===String(id);}); return p?'<span class="tg">'+esc(p.user_cname)+'<i class="fa fa-times" onclick="ownerUserDel('+i+',\''+id+'\')"></i></span>':''; }).join('');
    return '<div class="dp-pick itm-up" data-i="'+i+'"><div class="dp-tags">'+tags+'</div>'+(isDraftMine()?'<input type="text" class="itm-up-kw" placeholder="選人員…" data-eg-skip autocomplete="off"><div class="dp-list"></div>':'')+'</div>';
}
function ownerDeptDel(i,id){ ITEMS[i].owner_depts = ITEMS[i].owner_depts.filter(function(x){return String(x)!==String(id);}); renderItems(); }
function ownerUserDel(i,id){ ITEMS[i].owner_users = ITEMS[i].owner_users.filter(function(x){return String(x)!==String(id);}); renderItems(); }
$(document).on('focus input', '.itm-dp-kw', function(){
    var $p=$(this).closest('.itm-dp'), i=$p.data('i'), it=ITEMS[i]; if(!it) return;
    var kw=$.trim($(this).val()).toLowerCase(), h='';
    (META.departments||[]).forEach(function(d){
        if (kw && d.name.toLowerCase().indexOf(kw)<0) return;
        var on=(it.owner_depts||[]).some(function(x){return String(x)===String(d.id);});
        h += '<div data-id="'+d.id+'" style="'+(on?'color:#b0a390;':'')+'">'+(on?'✔ ':'')+esc(d.name)+'</div>';
    });
    $p.find('.dp-list').html(h||'<div style="color:#b0a390;">查無部門</div>').show();
});
$(document).on('click', '.itm-dp .dp-list div[data-id]', function(){
    var $p=$(this).closest('.itm-dp'), i=$p.data('i'), it=ITEMS[i]; if(!it) return;
    var id=String($(this).data('id')), idx=it.owner_depts.findIndex(function(x){return String(x)===id;});
    if (idx>=0) it.owner_depts.splice(idx,1); else it.owner_depts.push(id);
    renderItems();
});
$(document).on('focus input', '.itm-up-kw', function(){
    var $p=$(this).closest('.itm-up'), i=$p.data('i'), it=ITEMS[i]; if(!it) return;
    var kw=$.trim($(this).val()).toLowerCase(), h='';
    (META.people||[]).forEach(function(p){
        if (kw && p.user_cname.toLowerCase().indexOf(kw)<0) return;
        var on=(it.owner_users||[]).some(function(x){return String(x)===String(p.id);});
        h += '<div data-id="'+p.id+'" style="'+(on?'color:#b0a390;':'')+'">'+(on?'✔ ':'')+esc(p.display)+'</div>';
    });
    $p.find('.dp-list').html(h||'<div style="color:#b0a390;">查無人員</div>').show();
});
$(document).on('click', '.itm-up .dp-list div[data-id]', function(){
    var $p=$(this).closest('.itm-up'), i=$p.data('i'), it=ITEMS[i]; if(!it) return;
    var id=String($(this).data('id')), idx=it.owner_users.findIndex(function(x){return String(x)===id;});
    if (idx>=0) it.owner_users.splice(idx,1); else it.owner_users.push(id);
    renderItems();
});
function signSlotsHtml(i, it){
    if (!it.required_signers || !it.required_signers.length) return '<span style="color:#b0a390;font-size:11px;">未指派負責人</span>';
    var doneUids = (it.confirms||[]).map(function(c){ return String(c.user_id); });
    return it.required_signers.map(function(s){
        var done = doneUids.indexOf(String(s.id))>=0;
        var c = (it.confirms||[]).find(function(x){ return String(x.user_id)===String(s.id); });
        if (done) return '<div class="sign-slot sign-yes">✓ '+esc(s.user_cname)+'（'+dispDate(c.signed_at)+'）</div>';
        if (String(s.id)===String(META.uid) && CUR.status!=='draft') {
            return '<div class="sign-slot"><b>'+esc(s.user_cname)+'</b><br><input type="text" inputmode="numeric" placeholder="本人密碼" class="sign-pw" data-item="'+it.id+'" data-uid="'+s.id+'" style="width:80px;" data-eg-skip>'
                 + '<button onclick="doItemConfirm('+it.id+','+s.id+')" style="height:22px;font-size:11px;">簽名</button></div>';
        }
        return '<div class="sign-slot sign-no">未簽：'+esc(s.user_cname)+'</div>';
    }).join('');
}
function doItemConfirm(itemId, uid){
    var pw = $('.sign-pw[data-item="'+itemId+'"][data-uid="'+uid+'"]').val();
    $.post(API, {action:'item_confirm', csrf:META.csrf, item_id:itemId, user_id:uid, password:pw}, function(res){
        if (!res.ok){ alert(res.error||'簽名失敗'); return; }
        openView(CUR.id);
    }, 'json');
}
function renderItems(){
    var h = '';
    ITEMS.forEach(function(it,i){
        h += '<tr><td style="text-align:center;">'+(i+1)+'</td>';
        h += '<td><textarea '+(isDraftMine()?'':'disabled')+' onchange="itemEdit('+i+',\'content\',this.value)">'+esc(it.content)+'</textarea></td>';
        var inlineFields = (CUR_SCHEMA.fields||[]).filter(function(c){ return c.layout!=='block'; });
        inlineFields.forEach(function(c){ h += '<td>'+fieldInputHtml(i,c)+'</td>'; });
        h += '<td>'+('負責部門：'+deptTagHtml(i,it.owner_depts)+'負責人：'+userTagHtml(i,it.owner_users))+'</td>';
        h += '<td>'+signSlotsHtml(i,it)+'</td>';
        if (isDraftMine()) h += '<td><span class="rf-del" onclick="itemDel('+i+')"><i class="fa fa-times"></i></span></td>';
        h += '</tr>';
        var blocks = (CUR_SCHEMA.fields||[]).filter(function(c){ return c.layout==='block'; });
        if (blocks.length) {
            var colspan = 2 + inlineFields.length + 1 + (isDraftMine()?1:0);
            h += '<tr><td></td><td colspan="'+colspan+'">' + blocks.map(function(c){ return fieldInputHtml(i,c); }).join('') + '</td></tr>';
        }
    });
    $('#itmBody').html(h || '<tr><td colspan="10" style="text-align:center;color:#8a6d45;">尚未建立項目</td></tr>');
}

function collectItems(){
    return ITEMS.map(function(it){ return {id:it.id, content:it.content, data:it.data, owner_depts:it.owner_depts, owner_users:it.owner_users}; });
}
function saveDraft(cb){
    $.post(API, {action:'instance_save_items', csrf:META.csrf, instance_id:CUR.id, title:$('#vTitle').val(), business_date:$('#vBizDate').val(), items:JSON.stringify(collectItems())}, function(res){
        if (!res.ok){ alert(res.error||'儲存失敗'); return; }
        if (cb) cb(); else { loadList(); openView(CUR.id); }
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
    if (!confirm('確定要刪除此草稿？')) return;
    $.post(API, {action:'instance_delete', csrf:META.csrf, instance_id:CUR.id}, function(res){
        if (!res.ok){ alert(res.error||'刪除失敗'); return; }
        closeMask('viewMask'); loadList();
    }, 'json');
}

/* ============ 列印 ============ */
function egPrintWindow(title, bodyHtml, extraCss, docNo, paper){
    var asCss = String(docNo||'').replace(/['\\]/g,'');
    var css = '@page{size:'+(paper||'A4')+' portrait;margin:12mm 8mm 16mm;}'
            + 'html,body{margin:0;padding:0;}'
            + 'body{font-family:"Microsoft JhengHei","微軟正黑體",sans-serif;color:#000;-webkit-print-color-adjust:exact;print-color-adjust:exact;}'
            + '.pt-head{text-align:center;margin-bottom:6px;}.pt-head .co{font-size:22px;font-weight:bold;letter-spacing:2px;}.pt-head .tt{font-size:16px;font-weight:bold;margin-top:3px;letter-spacing:1px;}'
            + '.rf-page-num{position:fixed;left:8mm;bottom:6mm;font-size:9pt;color:#333;}'
            + '.rf-as-doc{position:fixed;right:8mm;bottom:6mm;font-size:9pt;color:#333;}'
            + (extraCss||'');
    var w = window.open('', '_blank');
    if (!w){ alert('請允許彈出視窗'); return; }
    var pageNumHtml = asCss ? '' : '';
    w.document.write('<html><head><meta charset="utf-8"><title>'+esc(title)+'</title><style>'+css+'</style></head><body>'
        + bodyHtml
        + (asCss ? '<div class="rf-as-doc">'+asCss+'</div>' : '')
        + '<div class="rf-page-num">第 1 頁／共 1 頁</div>'
        + '<scr'+'ipt>window.onload=function(){'
        + 'var pageH=('+(paper==='A3'?'420':'297')+'-28)*96/25.4;'
        + 'var h=document.body.scrollHeight;'
        + 'if(h>pageH){ document.body.style.zoom = Math.max(0.5, pageH/h); }'
        + 'setTimeout(function(){window.print();},250);};</scr'+'ipt></body></html>');
    w.document.close();
}
function rfCss(){
    return 'table.rf-p-head{width:100%;border-collapse:collapse;font-size:13px;table-layout:fixed;margin-bottom:6px;}'
         + 'table.rf-p-head th{background:#fff;font-weight:bold;border:1px solid #333;padding:5px 6px;width:70px;text-align:center;}'
         + 'table.rf-p-head td{border:1px solid #333;padding:5px 8px;text-align:left;}'
         + 'table.rf-p-items{width:100%;border-collapse:collapse;font-size:11.5px;margin-top:6px;}'
         + 'table.rf-p-items th,table.rf-p-items td{border:1px solid #333;padding:4px 5px;text-align:center;}'
         + 'table.rf-p-items td.t-left{text-align:left;}'
         + 'table.rf-p-foot{width:100%;margin-top:16px;font-size:13px;}'
         + 'table.rf-p-foot td{padding:10px 6px;width:33.33%;text-align:center;}'
         + 'table.rf-p-foot .stamp-wrap svg,table.rf-p-foot svg.car-stamp{width:80px !important;height:80px !important;-webkit-print-color-adjust:exact;print-color-adjust:exact;}';
}
function stampOrName(name, date, isDeputy){
    return (window.EGStamp && EGStamp.stamp) ? EGStamp.stamp(name, date, !!isDeputy) : esc(name||'');
}
function printForm(){
    var t = CUR.tpl, schema = CUR_SCHEMA;
    var h = '<div class="pt-head"><div class="co">'+esc(CUR.company_name||'')+'</div><div class="tt">'+esc(t.name)+'</div></div>';
    h += '<table class="rf-p-head"><tr><th>標題</th><td>'+esc(CUR.title||'')+'</td><th>業務日期</th><td>'+dispDate(CUR.business_date)+'</td></tr>'
       + '<tr><th>填表人</th><td>'+esc(CUR.created_by_name)+'</td><th>狀態</th><td>'+STATUS_LABEL[CUR.status]+'</td></tr></table>';
    h += '<table class="rf-p-items"><thead><tr><th>#</th><th>項目</th>';
    (schema.fields||[]).forEach(function(c){ h += '<th>'+esc(c.label)+'</th>'; });
    h += '<th>負責單位/人</th><th>簽名</th></tr></thead><tbody>';
    ITEMS.forEach(function(it,i){
        h += '<tr><td>'+(i+1)+'</td><td class="t-left">'+esc(it.content).replace(/\n/g,'<br>')+'</td>';
        (schema.fields||[]).forEach(function(c){ h += '<td>'+(c.type==='date' ? dispDate(it.data[c.key]||'') : esc(it.data[c.key]||''))+'</td>'; });
        var ownerTxt = (it.owner_depts||[]).map(function(id){ var d=(META.departments||[]).find(function(x){return String(x.id)===String(id);}); return d?d.name:''; })
            .concat((it.owner_users||[]).map(function(id){ var p=(META.people||[]).find(function(x){return String(x.id)===String(id);}); return p?p.user_cname:''; })).filter(Boolean).join('、');
        var signHtml = (it.confirms||[]).map(function(c){ return stampOrName(c.user_name, dispDate(c.signed_at)); }).join('');
        if (!signHtml && PREVIEW_MODE && (it.owner_depts.length || it.owner_users.length)) signHtml = stampOrName('（簽名樣式預覽）', dispDate(CUR.business_date));
        h += '<td>'+esc(ownerTxt)+'</td><td>'+signHtml+'</td></tr>';
    });
    h += '</tbody></table>';
    h += '<table class="rf-p-foot"><tr>';
    h += '<td>'+(CUR.review && CUR.review.status==='approved' ? ('審核：'+stampOrName(CUR.review.approver_name, dispDate(CUR.review.decided_at))) : '審核：')+'</td>';
    h += '<td>'+(CUR.approval && CUR.approval.status==='approved' ? ('核准：'+stampOrName(CUR.approval.approver_name, dispDate(CUR.approval.decided_at))) : '核准：')+'</td>';
    h += '<td>製表：'+stampOrName(CUR.created_by_name, dispDate(CUR.business_date))+'</td>';
    h += '</tr></table>';
    egPrintWindow(t.name, h, rfCss(), CUR.as_doc_no, t.paper_size);
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
    function openPreview(asDocNo){
        CUR = {
            id: 0, title: '', business_date: META.today, status: 'draft',
            created_by: META.uid, created_by_name: META.uname,
            tpl: {name: payload.tpl_name || '(未命名模板)', paper_size: payload.paper_size || 'A4'},
            as_doc_no: asDocNo || '', company_name: META.company_name,
            review: null, approval: null, can_review: false, can_approve: false
        };
        $('#viewTitle').text('試填預覽 — ' + CUR.tpl.name);
        renderView();
        openMask('viewMask');
    }
    if (payload.tpl_id) {
        $.getJSON(API, {action:'template_get', id:payload.tpl_id}, function(res){
            openPreview(res.ok && res.template.as_doc ? (res.template.as_doc.doc_no) : '');
        });
    } else openPreview('');
}
if (PREVIEW_MODE) loadMeta(initPreview);
else loadMeta(function(){ loadTemplates(loadList); });
</script>
</body>
</html>
