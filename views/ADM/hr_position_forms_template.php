<?php
/**
 * 人資職務表單設定 — 職位範本／機型量具白名單／部門表單資格／AS文件綁定（僅管理員）—— 2026-08-13 新增
 * 操作頁在 hr_position_forms.php。資料一律走 src/store/HrForm_API.php；權限 hr_form_lib.php hrf_perms()
 */
session_start();
if (!isset($_SESSION['userName'])) {
    $_SESSION['lastpage'] = "../../views/ADM/hr_position_forms_template.php";
    header("Location:../../index.php");
    exit;
}
include_once '../../src/common/_config.php';
include_once '../../src/common/DBConnection.php';
include_once '../../src/common/hr_form_lib.php';

$db = (new DBConnection())->getPDO();
$hrfUser = hrf_current_user($db);
$perms = hrf_perms($db, $hrfUser);
if (!$perms['canAdmin']) { header('Location: hr_position_forms.php'); exit; }
?>
<!DOCTYPE html>
<html lang="zh-Hant">
<head>
    <meta charset="utf-8">
    <meta http-equiv="X-UA-Compatible" content="IE=edge">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>人資職務表單設定</title>
    <link href="../../resource/css/bootstrap.css" rel="stylesheet">
    <link href="../../resource/css/font-awesome.css" rel="stylesheet">
    <link href="../../resource/css/nprogress.css" rel="stylesheet">
    <link href="../../resource/css/custom.css" rel="stylesheet">
    <style>
        #sidebar-menu { visibility: hidden; }
        .right_col .page-title { margin:8px 0 4px; overflow:hidden; clear:both; }
        .hf-toolbar { display:flex; flex-wrap:wrap; gap:6px; align-items:center; clear:both;
            border:1.5px solid #E8D5B5; border-radius:8px; padding:8px 10px; margin-bottom:10px; background:#FDF8EF; }
        .hf-toolbar button { height:30px; font-size:13px; padding:0 12px; border:1px solid #D8BE93; border-radius:4px; background:#fff; color:#5b3a1e; cursor:pointer; }
        .hf-toolbar .btn-warm { background:#F0A24B; color:#fff; border-color:#d98a33; }
        .page-help-btn { margin-left:auto; height:30px; font-size:13px; padding:0 14px; border:1px solid #D8BE93; border-radius:4px; background:#fff; color:#5b3a1e; cursor:pointer; }
        .help-doc h4 { font-size:14px; color:#8A5A2B; margin:10px 0 4px; }
        table.hf-tbl { width:100%; border-collapse:collapse; font-size:13px; background:#fff; }
        table.hf-tbl th, table.hf-tbl td { border:1px solid #EADFC8; padding:6px 8px; }
        table.hf-tbl thead th { background:#F7E0BD; color:#5b3a1e; }
        .hf-table-wrap { overflow-x:auto; border:1px solid #E8D5B5; border-radius:6px; margin-bottom:14px; }
        .hf-mask { display:none; position:fixed; inset:0; background:rgba(60,40,20,.45); z-index:1050; overflow-y:auto; }
        .hf-modal { background:#fff; border-radius:8px; max-width:900px; margin:24px auto; box-shadow:0 5px 25px rgba(0,0,0,.3); }
        .hf-modal .m-head { background:#F7E0BD; color:#5b3a1e; font-weight:bold; padding:10px 15px; border-radius:8px 8px 0 0; display:flex; justify-content:space-between; position:sticky; top:0; }
        .hf-modal .m-head .m-close { cursor:pointer; color:#b5762a; }
        .hf-modal .m-body { padding:15px; }
        .hf-modal .m-body label { display:block; font-size:13px; color:#5b3a1e; margin:9px 0 3px; }
        .hf-modal .m-body input[type=text], .hf-modal .m-body select { border:1px solid #D8BE93; border-radius:4px; padding:5px 8px; font-size:13px; box-sizing:border-box; width:100%; }
        .hf-modal .m-foot { padding:10px 15px; border-top:1px solid #EADFC8; text-align:right; }
        .hf-modal .m-foot button { height:30px; padding:0 16px; border-radius:4px; font-size:13px; border:1px solid #d98a33; cursor:pointer; margin-left:6px; }
        .hf-modal .m-foot .b-ok { background:#F0A24B; color:#fff; }
        .hf-modal .m-foot .b-cancel { background:#fff; color:#5b3a1e; border-color:#D8BE93; }
        table.itm-tbl { width:100%; border-collapse:collapse; font-size:12.5px; margin-bottom:8px; }
        table.itm-tbl th, table.itm-tbl td { border:1px solid #EADFC8; padding:5px 6px; vertical-align:top; }
        table.itm-tbl thead th { background:#F7E0BD; color:#5b3a1e; }
        table.itm-tbl textarea { width:100%; min-height:40px; border:1px solid #D8BE93; border-radius:4px; padding:4px 6px; font-size:12.5px; box-sizing:border-box; }
        table.itm-tbl input[type=text], table.itm-tbl select { width:100%; border:1px solid #D8BE93; border-radius:4px; padding:3px 5px; font-size:12px; box-sizing:border-box; }
        .hf-btn-sm { height:26px; padding:0 8px; border-radius:4px; font-size:11.5px; border:1px solid #D8BE93; background:#fff; color:#5b3a1e; cursor:pointer; }
        .hf-btn-sm:hover { background:#FBF0DD; }
        .nav-hf { margin:0 0 10px; }
        .nav-hf > li > a { color:#5b3a1e; }
        .nav-hf > li.active > a { color:#8A5A2B; font-weight:bold; border-color:#E8D5B5 #E8D5B5 #fff; }
        .hf-tabpane { display:none; }
        .hf-tabpane.active { display:block; }
        .wl-col { display:inline-block; vertical-align:top; width:49%; border:1px solid #D8BE93; border-radius:6px; padding:8px; box-sizing:border-box; }
        .wl-col h4 { margin-top:0; color:#8A5A2B; }
        .wl-group { font-weight:bold; color:#8a6d45; margin:8px 0 2px; font-size:12.5px; }
        .wl-row { font-size:12.5px; padding:2px 0; display:flex; align-items:center; gap:6px; }
        .wl-row input[type=text] { width:140px; height:22px; font-size:11.5px; border:1px solid #D8BE93; border-radius:3px; padding:0 5px; }
        .wl-list { max-height:420px; overflow-y:auto; }
        .flt { width:100%; border:1px solid #D8BE93; border-radius:4px; padding:5px 8px; font-size:12.5px; margin-bottom:6px; box-sizing:border-box; }
        @media print { .page-help-btn { display:none; } }
    </style>
</head>
<body class="nav-sm">
<div class="container body">
<div class="main_container">
    <?php include '../partPage/sideAndTopBarMenu.html' ?>
    <div class="right_col" role="main">
        <div class="page-title" style="display:flex;align-items:center;flex-wrap:wrap;clear:both;">
            <h2 style="margin:6px 0;">人資職務表單設定 <small style="color:#8a6d45;">職位範本／機型量具白名單／部門表單資格／AS文件綁定</small></h2>
            <a href="hr_position_forms.php" style="margin-left:8px;">← 回操作頁</a>
            <button class="page-help-btn" id="btnPageHelp"><i class="fa fa-question-circle"></i> 使用說明</button>
        </div>
        <div class="clearfix"></div>

        <ul class="nav nav-tabs nav-hf" id="hfTabs">
            <li class="active"><a href="#" data-type="job_desc">職務說明書範本</a></li>
            <li><a href="#" data-type="skill_assess">技能鑑定表範本</a></li>
            <li><a href="#" data-type="competency">職能鑑定表範本</a></li>
            <li><a href="#" data-type="whitelist">機型/量具白名單</a></li>
            <li><a href="#" data-type="deptset">部門表單資格</a></li>
        </ul>

<?php foreach (['job_desc','skill_assess','competency'] as $ft): ?>
        <div class="hf-tabpane<?= $ft==='job_desc'?' active':'' ?>" id="pane-<?= $ft ?>" data-type="<?= $ft ?>">
            <div class="hf-toolbar">
                <button class="btn-warm btn-tpl-add"><i class="fa fa-plus"></i> 新增範本</button>
                <span class="hf-asdoc" style="font-size:12px;color:#8a6d45;"></span>
                <button class="btn-asdoc-bind" style="margin-left:auto;">綁定 AS 文件編號</button>
            </div>
            <div class="hf-table-wrap">
            <table class="hf-tbl">
                <thead><tr><th>範本名稱</th><th>適用部門×職位</th><th><?= $ft==='skill_assess' ? '適用機型數' : '內容列數' ?></th><th style="width:140px;">操作</th></tr></thead>
                <tbody class="tpl-list-body"><tr><td colspan="4" style="text-align:center;color:#8a6d45;">載入中…</td></tr></tbody>
            </table>
            </div>
        </div>
<?php endforeach; ?>

        <div class="hf-tabpane" id="pane-whitelist" data-type="whitelist">
            <p style="font-size:12.5px;color:#8a6d45;">從既有機台主檔（machine_list）與量測儀器校驗量具主檔（qc_tool）勾選要開放給「專業技能鑑定考核表」選用的機型/量具，並可自訂「項目名稱」（列印時顯示，如：投影機）。</p>
            <input type="text" class="flt" id="wlFilter" placeholder="輸入名稱篩選…" oninput="wlFilterList(this.value)">
            <div class="wl-col"><h4>生產機台（machine_list）</h4><div class="wl-list" id="wlMachines"></div></div>
            <div class="wl-col" style="margin-left:1%;"><h4>量測儀器校驗量具（qc_tool）</h4><div class="wl-list" id="wlTools"></div></div>
            <div style="clear:both;margin-top:10px;"><button class="btn-warm" onclick="wlSave()">儲存白名單</button> <span id="wlSaveMsg" style="font-size:12.5px;color:#3f9142;"></span></div>
        </div>

        <div class="hf-tabpane" id="pane-deptset" data-type="deptset">
            <p style="font-size:12.5px;color:#8a6d45;">勾選哪些部門的人員要產生「專業技能鑑定考核表」「員工職能鑑定表」（職務說明書全員適用，不受此設定限制）。變更即時儲存。</p>
            <div class="hf-table-wrap">
            <table class="hf-tbl">
                <thead><tr><th>部門</th><th style="width:160px;">產生專業技能鑑定考核表</th><th style="width:160px;">產生員工職能鑑定表</th></tr></thead>
                <tbody id="deptSetBody"></tbody>
            </table>
            </div>
        </div>
    </div>
</div>
</div>

<!-- 範本編輯 modal -->
<div class="hf-mask" id="tplMask"><div class="hf-modal">
    <div class="m-head"><span id="tplTitle">範本</span><span class="m-close" onclick="closeMask('tplMask')">✕</span></div>
    <div class="m-body">
        <label>範本名稱</label><input type="text" id="tplName">
        <div id="tplStampBlock">
            <label>逐列/評分區圖章樣式（不設定則用系統預設）</label><select id="tplListStamp"></select>
            <label>頁尾核准/確認圖章樣式（不設定則用系統預設）</label><select id="tplFootStamp"></select>
        </div>
        <label>適用部門×職位（可多筆；部門留空＝不限部門，該職位皆適用）</label>
        <table class="itm-tbl" id="scopeTbl"><thead><tr><th>部門</th><th>職位</th><th style="width:50px;"></th></tr></thead><tbody id="scopeBody"></tbody></table>
        <button class="hf-btn-sm" onclick="scopeAddRow()">+新增一列</button>

        <div id="tplContentBlock"></div>
    </div>
    <div class="m-foot"><button class="b-cancel" onclick="closeMask('tplMask')">取消</button><button class="b-ok" onclick="tplSave()">儲存</button></div>
</div></div>

<!-- AS 文件綁定 modal -->
<div class="hf-mask" id="asdocMask"><div class="hf-modal" style="max-width:600px;">
    <div class="m-head"><span>綁定 AS 文件編號</span><span class="m-close" onclick="closeMask('asdocMask')">✕</span></div>
    <div class="m-body">
        <label>輸入編號篩選</label><input type="text" id="asdocFilter" oninput="asdocFilterList(this.value)">
        <div style="max-height:320px;overflow-y:auto;border:1px solid #D8BE93;border-radius:6px;" id="asdocList"></div>
    </div>
    <div class="m-foot"><button class="b-cancel" onclick="closeMask('asdocMask')">關閉</button></div>
</div></div>

<!-- 使用說明 modal（鐵律7） -->
<div class="hf-mask" id="helpUseMask"><div class="hf-modal" style="max-width:760px;">
    <div class="m-head"><span>使用說明 — 人資職務表單設定</span><span class="m-close" onclick="closeMask('helpUseMask')">✕</span></div>
    <div class="m-body help-doc" style="font-size:13px;color:#5b3a1e;line-height:1.8;">
        <h4>功能說明</h4>
        管理員在這裡設定「職位範本」：建立表單時系統會依員工的部門×職位比對範本，自動帶入內容（職務說明書的工作職責表、員工職能鑑定表的職能項目清單）或適用機型清單（專業技能鑑定考核表）。一個範本可以綁定多筆部門×職位。
        <h4>操作步驟</h4>
        <b>①新增/編輯範本</b>：填範本名稱、綁定適用的部門×職位（部門留空代表該職位不限部門都適用）、編輯內容（職務說明書填4欄工作職責表；員工職能鑑定表填職能項目清單；專業技能鑑定考核表勾選適用機型，需先在「機型/量具白名單」建立好白名單）。<br>
        <b>②機型/量具白名單</b>：從既有機台主檔與量測儀器校驗的量具主檔勾選，可自訂「項目名稱」（列印在表單上的名稱），儲存後才能在範本裡勾選。<br>
        <b>③部門表單資格</b>：勾選哪些部門的人要產生技能鑑定表/職能鑑定表，職務說明書不受此限制、全員都會有。<br>
        <b>④AS文件編號綁定</b>：三張表單各自獨立綁定，在各自分頁右上角按鈕設定。
        <h4>重要行為</h4>
        ・建立表單時找不到符合部門×職位的範本會被擋下並提示，需先在這裡建立對應範本。<br>
        ・部門×職位有多筆符合時，優先採用完全指定部門的那筆，其次才用「不限部門」的那筆。
    </div>
    <div class="m-foot"><button class="b-ok" onclick="closeMask('helpUseMask')">關閉</button></div>
</div></div>

<script src="../../resource/js/jquery.min.js"></script>
<script src="../../resource/js/bootstrap.min.js"></script>
<script src="../../resource/js/fastclick.js"></script>
<script src="../../resource/js/nprogress.js"></script>
<script src="../../resource/js/custom.min.js"></script>
<script src="../../resource/js/eg_input_rules.js?v=<?= @filemtime(__DIR__.'/../../resource/js/eg_input_rules.js') ?>"></script>
<script src="../../resource/js/eg_asdoc_picker.js?v=<?= @filemtime(__DIR__.'/../../resource/js/eg_asdoc_picker.js') ?>"></script>
<script>
var API = '../../src/store/HrForm_API.php';
var META = {};
var FORM_LABEL = {job_desc:'職務說明書', skill_assess:'專業技能鑑定考核表', competency:'員工職能鑑定表'};
function esc(s){ return $('<div>').text(s==null?'':s).html(); }
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
    if (['job_desc','skill_assess','competency'].indexOf(t) >= 0) loadTplList(t);
    if (t === 'whitelist') loadWhitelist();
    if (t === 'deptset') loadDeptSet();
});
$('.btn-tpl-add').on('click', function(){ openTplModal($(this).closest('.hf-tabpane').data('type'), 0); });
$('.btn-asdoc-bind').on('click', function(){ openAsdocModal($(this).closest('.hf-tabpane').data('type')); });

function loadMeta(cb){
    $.getJSON(API, {action:'meta'}, function(res){
        if (!res.ok){ alert(res.error||'載入失敗'); return; }
        META = res;
        if (cb) cb();
    });
}
function loadAsDocLabel(ft){
    $.getJSON(API, {action:'asdoc_get', form_type:ft}, function(res){
        var t = (res.ok && res.doc) ? (res.doc.doc_no+' '+res.doc.doc_name) : '（尚未綁定）';
        $('#pane-'+ft+' .hf-asdoc').text('目前綁定：'+t);
    });
}

/* ============================================================ 範本清單 ============================================================ */
function loadTplList(ft){
    $.getJSON(API, {action:'template_list', form_type:ft}, function(res){
        var $tb = $('#pane-'+ft+' .tpl-list-body');
        if (!res.ok){ $tb.html('<tr><td colspan="4" style="color:#DD5138;">'+esc(res.error||'載入失敗')+'</td></tr>'); return; }
        var rows = res.templates || [];
        if (!rows.length){ $tb.html('<tr><td colspan="4" style="text-align:center;color:#8a6d45;">尚無範本</td></tr>'); return; }
        var html = '';
        rows.forEach(function(t){
            html += '<tr><td>'+esc(t.name)+'</td><td>'+(t.scope_summary||'（載入中）')+'</td><td>'+(t.count_summary||'')+'</td>'
                  + '<td><button class="hf-btn-sm" onclick="openTplModal(\''+ft+'\','+t.id+')">編輯</button> <button class="hf-btn-sm" onclick="tplDelete('+t.id+',\''+ft+'\')">刪除</button></td></tr>';
        });
        $tb.html(html);
        // 補讀每筆的 scope/count 摘要
        rows.forEach(function(t){
            $.getJSON(API, {action:'template_get', id:t.id}, function(r2){
                if (!r2.ok) return;
                var tt = r2.template;
                var scopeTxt = (tt.scope||[]).map(function(s){ return (s.department_name||'不限部門')+'×'+s.position_name; }).join('、') || '（尚未設定）';
                var cntTxt = ft === 'skill_assess' ? ((tt.machines||[]).length+' 項') : ((tt.items||[]).length+' 列');
                var $row = $tb.find('tr').eq(rows.indexOf(t));
                $row.find('td').eq(1).text(scopeTxt);
                $row.find('td').eq(2).text(cntTxt);
            });
        });
    });
    loadAsDocLabel(ft);
}
function tplDelete(id, ft){
    if (!confirm('確定要刪除此範本？已建立過的表單不受影響，但之後無法再依此範本建立新表單。')) return;
    ajaxPost('template_delete', {id:id}, function(res){ if (!res.ok){ alert(res.error||'刪除失敗'); return; } loadTplList(ft); });
}

/* ============================================================ 範本編輯 ============================================================ */
var TPL_TYPE = 'job_desc', TPL_ID = 0;
function scopeAddRow(deptId, posId){
    var deptOpts = '<option value="">不限部門</option>' + (META.departments||[]).map(function(d){ return '<option value="'+d.id+'"'+(String(deptId)===String(d.id)?' selected':'')+'>'+esc(d.name)+'</option>'; }).join('');
    var posOpts = (META.positions||[]).map(function(p){ return '<option value="'+p.id+'"'+(String(posId)===String(p.id)?' selected':'')+'>'+esc(p.name)+'</option>'; }).join('');
    $('#scopeBody').append('<tr><td><select class="sc-dept">'+deptOpts+'</select></td><td><select class="sc-pos">'+posOpts+'</select></td><td><button class="hf-btn-sm" onclick="$(this).closest(\'tr\').remove()">刪除</button></td></tr>');
}
function openTplModal(ft, id){
    TPL_TYPE = ft; TPL_ID = id;
    $('#tplTitle').text((id?'編輯':'新增')+'範本 — '+FORM_LABEL[ft]);
    $('#tplName').val('');
    $('#scopeBody').empty();
    $('#tplStampBlock').toggle(ft !== 'job_desc');
    if (ft !== 'job_desc') {
        $.getJSON(API, {action:'stamp_options'}, function(res){
            var opts = '<option value="0">（系統預設）</option>' + (res.options||[]).map(function(o){ return '<option value="'+o.id+'">'+esc(o.tpl_name)+(o.type_name?'（'+esc(o.type_name)+'）':'')+'</option>'; }).join('');
            $('#tplListStamp').html(opts); $('#tplFootStamp').html(opts);
        });
    }
    if (ft === 'skill_assess') {
        $.getJSON(API, {action:'whitelist_list'}, function(res){
            var wl = res.ok ? (res.whitelist||[]) : [];
            var html = '<label>適用機型（勾選；建立表單時系統會依這份清單自動展開每個機型各一筆）</label><div style="max-height:220px;overflow-y:auto;border:1px solid #D8BE93;border-radius:6px;padding:6px;" id="tplMachineList">'
                     + wl.map(function(w){ return '<label style="display:block;font-size:12.5px;"><input type="checkbox" class="tm-ck" value="'+w.id+'"> '+esc(w.item_name||w.display_name)+'（'+esc(w.display_name)+'）</label>'; }).join('')
                     + '</div>';
            $('#tplContentBlock').html(html);
            if (id) fillTplForEdit(id);
        });
    } else {
        $('#tplContentBlock').html(id ? '' : (ft==='job_desc' ? jdTplTableHtml([]) : cpTplTableHtml([])));
        if (id) fillTplForEdit(id);
        else if (!id) { /* 空白範本，至少一列 */ }
    }
    if (!id) openMask('tplMask');
}
function fillTplForEdit(id){
    $.getJSON(API, {action:'template_get', id:id}, function(res){
        if (!res.ok){ alert(res.error||'載入失敗'); return; }
        var t = res.template;
        $('#tplName').val(t.name);
        $('#tplListStamp').val(t.list_stamp_tpl_id||0);
        $('#tplFootStamp').val(t.footer_stamp_tpl_id||0);
        (t.scope||[]).forEach(function(s){ scopeAddRow(s.department_id, s.position_id); });
        if (t.form_type === 'skill_assess') {
            var ids = (t.machines||[]).map(function(m){ return String(m.id); });
            $('#tplMachineList .tm-ck').each(function(){ if (ids.indexOf($(this).val()) >= 0) $(this).prop('checked', true); });
        } else if (t.form_type === 'job_desc') {
            $('#tplContentBlock').html(jdTplTableHtml(t.items||[]));
        } else {
            $('#tplContentBlock').html(cpTplTableHtml(t.items||[]));
        }
        openMask('tplMask');
    });
}
function jdTplTableHtml(items){
    var rows = items && items.length ? items : [{data:{}}];
    var h = '<label>工作職責內容</label><table class="itm-tbl"><thead><tr><th>工作摘要</th><th>工作相關程序書</th><th>產出表單名稱</th><th>DPI 項目</th></tr></thead>'
          + '<tbody id="tplItemsBody" data-eg-row-add="hfTplJdAdd" data-eg-row-del="hfTplJdDel">';
    rows.forEach(function(it){ var d=it.data||{}; h += '<tr><td><textarea class="c-a">'+esc(d.summary||'')+'</textarea></td><td><textarea class="c-b">'+esc(d.process||'')+'</textarea></td><td><textarea class="c-c">'+esc(d.form_name||'')+'</textarea></td><td><textarea class="c-d">'+esc(d.dpi||'')+'</textarea></td></tr>'; });
    h += '</tbody></table><button class="hf-btn-sm" type="button" onclick="hfTplJdAdd()">+新增列</button> <button class="hf-btn-sm" type="button" onclick="hfTplJdDel()">-刪除末列</button>';
    return h;
}
function hfTplJdAdd(){ $('#tplItemsBody').append('<tr><td><textarea class="c-a"></textarea></td><td><textarea class="c-b"></textarea></td><td><textarea class="c-c"></textarea></td><td><textarea class="c-d"></textarea></td></tr>'); }
function hfTplJdDel(){ var $r=$('#tplItemsBody tr'); if ($r.length>1) $r.last().remove(); }
function cpTplTableHtml(items){
    var rows = items && items.length ? items : [{data:{}}];
    var h = '<label>職能項目清單</label><table class="itm-tbl"><thead><tr><th style="width:40px;">編號</th><th>項目名稱</th></tr></thead>'
          + '<tbody id="tplItemsBody" data-eg-row-add="hfTplCpAdd" data-eg-row-del="hfTplCpDel">';
    rows.forEach(function(it,i){ var d=it.data||{}; h += '<tr><td style="text-align:center;">'+(i+1)+'</td><td><input type="text" class="c-name" value="'+esc(d.skill_name||'')+'"></td></tr>'; });
    h += '</tbody></table><button class="hf-btn-sm" type="button" onclick="hfTplCpAdd()">+新增列</button> <button class="hf-btn-sm" type="button" onclick="hfTplCpDel()">-刪除末列</button>';
    return h;
}
function hfTplCpAdd(){ var n=$('#tplItemsBody tr').length+1; $('#tplItemsBody').append('<tr><td style="text-align:center;">'+n+'</td><td><input type="text" class="c-name"></td></tr>'); }
function hfTplCpDel(){ var $r=$('#tplItemsBody tr'); if ($r.length>1) $r.last().remove(); }

function tplSave(){
    var name = $('#tplName').val().trim();
    if (!name){ alert('請輸入範本名稱'); return; }
    var scope = $('#scopeBody tr').map(function(){
        var $t = $(this);
        return {department_id: $t.find('.sc-dept').val() || null, position_id: $t.find('.sc-pos').val()};
    }).get().filter(function(s){ return !!s.position_id; });
    var payload = {id:TPL_ID, form_type:TPL_TYPE, name:name, list_stamp_tpl_id:$('#tplListStamp').val()||0, footer_stamp_tpl_id:$('#tplFootStamp').val()||0, scope:JSON.stringify(scope)};
    if (TPL_TYPE === 'skill_assess') {
        payload.whitelist_ids = JSON.stringify($('#tplMachineList .tm-ck:checked').map(function(){ return $(this).val(); }).get());
    } else if (TPL_TYPE === 'job_desc') {
        payload.items = JSON.stringify($('#tplItemsBody tr').map(function(){ var $t=$(this); return {data:{summary:$t.find('.c-a').val(), process:$t.find('.c-b').val(), form_name:$t.find('.c-c').val(), dpi:$t.find('.c-d').val()}}; }).get());
    } else {
        payload.items = JSON.stringify($('#tplItemsBody tr').map(function(){ return {data:{skill_name:$(this).find('.c-name').val()}}; }).get());
    }
    ajaxPost('template_save', payload, function(res){
        if (!res.ok){ alert(res.error||'儲存失敗'); return; }
        closeMask('tplMask'); loadTplList(TPL_TYPE);
    });
}

/* ============================================================ AS 文件綁定 ============================================================ */
var ASDOC_TYPE = null;
function openAsdocModal(ft){
    ASDOC_TYPE = ft;
    $.getJSON(API, {action:'asdoc_list'}, function(listRes){
        $.getJSON(API, {action:'asdoc_get', form_type:ft}, function(curRes){
            EGAsDoc.open({
                docs: listRes.ok ? (listRes.docs||[]) : [],
                current: (curRes.ok && curRes.doc) ? curRes.doc.id : 0,
                title: FORM_LABEL[ft] + ' — AS 文件綁定',
                onSave: function(id){
                    ajaxPost('asdoc_save', {form_type:ft, doc_id:id}, function(res){
                        if (!res.ok){ alert(res.error||'儲存失敗'); return; }
                        loadAsDocLabel(ft);
                    });
                }
            });
        });
    });
}

/* ============================================================ 機型/量具白名單 ============================================================ */
function loadWhitelist(){
    $.getJSON(API, {action:'whitelist_sources'}, function(res){
        if (!res.ok){ alert(res.error||'載入失敗'); return; }
        renderWlGroup('#wlMachines', res.machines||[]);
        renderWlGroup('#wlTools', res.tools||[]);
    });
}
function renderWlGroup(sel, rows){
    var groups = {};
    rows.forEach(function(r){ var g = r.group_name || '未分類'; (groups[g]=groups[g]||[]).push(r); });
    var html = '';
    Object.keys(groups).forEach(function(g){
        html += '<div class="wl-group">'+esc(g)+'</div>';
        groups[g].forEach(function(r){
            var type = sel === '#wlMachines' ? 'machine' : 'tool';
            html += '<div class="wl-row" data-hay="'+esc(r.display_name).toLowerCase()+'"><label style="flex:1;"><input type="checkbox" class="wl-ck" data-type="'+type+'" data-id="'+r.source_id+'" data-dname="'+esc(r.display_name)+'"'+(r.checked?' checked':'')+'> '+esc(r.display_name)+'</label>'
                  + '<input type="text" class="wl-item-name" placeholder="項目名稱" value="'+esc(r.item_name||'')+'"></div>';
        });
    });
    $(sel).html(html || '<span style="color:#8a6d45;font-size:12px;">（查無資料）</span>');
}
function wlFilterList(kw){
    kw = (kw||'').toLowerCase();
    $('.wl-row').each(function(){ $(this).toggle(!kw || ($(this).data('hay')+'').indexOf(kw) >= 0); });
}
function wlSave(){
    var entries = [];
    $('.wl-ck:checked').each(function(){
        var $row = $(this).closest('.wl-row');
        entries.push({source_type:$(this).data('type'), source_id:$(this).data('id'), display_name:$(this).data('dname'), item_name:$row.find('.wl-item-name').val()});
    });
    ajaxPost('whitelist_save', {entries:JSON.stringify(entries)}, function(res){
        if (!res.ok){ alert(res.error||'儲存失敗'); return; }
        $('#wlSaveMsg').text('已儲存 '+entries.length+' 筆白名單（'+new Date().toLocaleTimeString()+'）');
        loadWhitelist();
    });
}

/* ============================================================ 部門表單資格 ============================================================ */
function loadDeptSet(){
    var rows = META.dept_type_settings || [];
    var html = rows.map(function(d){
        return '<tr><td>'+esc(d.name)+'</td>'
             + '<td style="text-align:center;"><input type="checkbox" class="ds-sa" data-id="'+d.department_id+'"'+(d.produce_skill_assess?' checked':'')+' onchange="dsSave('+d.department_id+')"></td>'
             + '<td style="text-align:center;"><input type="checkbox" class="ds-cp" data-id="'+d.department_id+'"'+(d.produce_competency?' checked':'')+' onchange="dsSave('+d.department_id+')"></td></tr>';
    }).join('');
    $('#deptSetBody').html(html || '<tr><td colspan="3" style="text-align:center;color:#8a6d45;">尚無部門資料</td></tr>');
}
function dsSave(deptId){
    var sa = $('.ds-sa[data-id='+deptId+']').is(':checked');
    var cp = $('.ds-cp[data-id='+deptId+']').is(':checked');
    ajaxPost('dept_type_setting_save', {department_id:deptId, produce_skill_assess:sa?1:0, produce_competency:cp?1:0}, function(res){
        if (!res.ok) alert(res.error||'儲存失敗');
    });
}

loadMeta(function(){ loadTplList('job_desc'); });
</script>
</body>
</html>
