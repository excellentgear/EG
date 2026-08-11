<?php
/**
 * 審核表單模板管理（review_form 引擎）—— 2026-08-11 新增
 * 首發模板：2-TD-04-01 仿冒零件防制審核表／2-TD-03-01 產品安全審核表
 * 資料一律走 src/store/ReviewForm_API.php；權限 src/common/review_form_lib.php rvf_perms()
 * 管理員可設定模板全部設定；維護部門主管／被指派的維護人員只能編輯「項次欄位定義」。
 */
session_start();
if (!isset($_SESSION['userName'])) {
    $_SESSION['lastpage'] = "../../views/ADM/review_form_template.php";
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
    <title>審核表單模板管理</title>
    <link href="../../resource/css/bootstrap.css" rel="stylesheet">
    <link href="../../resource/css/font-awesome.css" rel="stylesheet">
    <link href="../../resource/css/nprogress.css" rel="stylesheet">
    <link href="../../resource/css/custom.css" rel="stylesheet">
    <style>
        #sidebar-menu { visibility: hidden; }
        .right_col .page-title { margin:8px 0 4px; overflow:hidden; clear:both; }
        .rf-toolbar { display:flex; flex-wrap:wrap; gap:6px; align-items:center; clear:both;
            border:1.5px solid #E8D5B5; border-radius:8px; padding:8px 10px; margin-bottom:10px; background:#FDF8EF; }
        .rf-toolbar button { height:30px; font-size:13px; padding:0 12px; border:1px solid #D8BE93; border-radius:4px;
            background:#fff; color:#5b3a1e; cursor:pointer; }
        .rf-toolbar .btn-warm { background:#F0A24B; color:#fff; border-color:#d98a33; }
        .page-help-btn { margin-left:auto; height:30px; font-size:13px; padding:0 14px; border:1px solid #D8BE93;
            border-radius:4px; background:#fff; color:#5b3a1e; cursor:pointer; }
        .help-doc h4 { font-size:14px; color:#8A5A2B; margin:10px 0 4px; }
        table.rf-tbl { width:100%; border-collapse:collapse; font-size:13px; background:#fff; }
        table.rf-tbl th, table.rf-tbl td { border:1px solid #EADFC8; padding:6px 8px; }
        table.rf-tbl thead th { background:#F7E0BD; color:#5b3a1e; }
        .rf-table-wrap { overflow-x:auto; border:1px solid #E8D5B5; border-radius:6px; }
        .tag-on { color:#7a5217; font-weight:bold; } .tag-off { color:#b0a390; }
        .rf-mask { display:none; position:fixed; inset:0; background:rgba(60,40,20,.45); z-index:1050; }
        .rf-modal { background:#fff; border-radius:8px; max-width:640px; margin:30px auto; box-shadow:0 5px 25px rgba(0,0,0,.3);
            max-height:90vh; display:flex; flex-direction:column; }
        .rf-modal.wide { max-width:960px; }
        .rf-modal .m-head { background:#F7E0BD; color:#5b3a1e; font-weight:bold; padding:10px 15px; border-radius:8px 8px 0 0;
            display:flex; justify-content:space-between; }
        .rf-modal .m-head .m-close { cursor:pointer; color:#b5762a; }
        .rf-modal .m-body { padding:15px; overflow-y:auto; }
        .rf-modal .m-body label { display:block; font-size:13px; color:#5b3a1e; margin:9px 0 3px; }
        .rf-modal .m-body input[type=text], .rf-modal .m-body input[type=number], .rf-modal .m-body input[type=date],
        .rf-modal .m-body select, .rf-modal .m-body textarea { width:100%; border:1px solid #D8BE93; border-radius:4px;
            padding:5px 8px; font-size:13px; box-sizing:border-box; }
        .rf-modal .m-foot { padding:10px 15px; border-top:1px solid #EADFC8; text-align:right; }
        .rf-modal .m-foot button { height:30px; padding:0 16px; border-radius:4px; font-size:13px; border:1px solid #d98a33; cursor:pointer; }
        .rf-modal .m-foot .b-ok { background:#F0A24B; color:#fff; }
        .rf-modal .m-foot .b-cancel { background:#fff; color:#5b3a1e; border-color:#D8BE93; margin-right:6px; }
        .rf-modal .m-foot .b-danger { background:#DD5138; color:#fff; border-color:#c23f28; }
        .grid2 { display:grid; grid-template-columns:1fr 1fr; gap:0 14px; }
        .rf-sec { border-top:1px dashed #EADFC8; margin-top:10px; padding-top:8px; }
        .rf-sec-title { font-weight:bold; color:#5b3a1e; margin:4px 0 6px; }
        .rf-hint { font-size:11.5px; color:#8a6d45; margin:2px 0 6px; }
        table.col-tbl { width:100%; border-collapse:collapse; font-size:12.5px; margin-bottom:8px; }
        table.col-tbl th, table.col-tbl td { border:1px solid #EADFC8; padding:4px 6px; vertical-align:top; }
        table.col-tbl thead th { background:#F7E0BD; color:#5b3a1e; }
        table.col-tbl input, table.col-tbl select { width:100%; border:1px solid #D8BE93; border-radius:4px; padding:3px 5px; font-size:12px; box-sizing:border-box; }
        .rf-del { color:#DD5138; cursor:pointer; }
        .chain-row { display:flex; align-items:center; gap:6px; margin-bottom:4px; }
        .chain-row select { flex:1; }
        .mt-tags { max-height:120px; overflow-y:auto; border:1px solid #EADFC8; border-radius:6px; padding:6px 8px; margin-bottom:6px; }
        .mt-tags .tg { display:inline-block; background:#F7E0BD; color:#5b3a1e; border-radius:9px; font-size:11px; padding:1px 8px; margin:2px; }
        .mt-tags .tg i { cursor:pointer; color:#b5762a; margin-left:4px; }
        @media print { .page-help-btn { display:none; } }
    </style>
</head>
<body class="nav-sm">
<div class="container body">
<div class="main_container">
    <?php include '../partPage/sideAndTopBarMenu.html' ?>
    <div class="right_col" role="main">
        <div class="page-title" style="display:flex;align-items:center;flex-wrap:wrap;clear:both;">
            <h2 style="margin:6px 0;">審核表單模板管理 <small style="color:#8a6d45;">管理員設定模板／維護人員維護項次內容</small></h2>
            <button class="page-help-btn" id="btnPageHelp"><i class="fa fa-question-circle"></i> 使用說明</button>
        </div>
        <div class="clearfix"></div>

<?php if (!$perms['canView']): ?>
        <div><h4><i class="fa fa-lock"></i> 無審核表單檢閱權限</h4><p>請洽系統管理者於「使用者權限設定」指派「審核表單」相關角色。</p></div>
<?php else: ?>
        <div class="rf-toolbar">
            <span>共用表單模板引擎：新建模板可綁定 AS 文件編號、設計項次欄位、設定審核/核准流程。</span>
            <button class="btn-warm" id="btnAddTpl" style="display:none;margin-left:auto;"><i class="fa fa-plus"></i> 新增模板</button>
            <a href="review_form.php" class="btn" style="height:30px;line-height:28px;padding:0 12px;border:1px solid #D8BE93;border-radius:4px;color:#5b3a1e;text-decoration:none;">前往「建立/填寫表單」→</a>
        </div>
        <div class="rf-table-wrap">
        <table class="rf-tbl">
            <thead><tr><th>模板名稱</th><th>綁定AS文件</th><th>紙張</th><th>審核</th><th>核准</th><th>維護部門</th><th style="width:200px;">操作</th></tr></thead>
            <tbody id="tplBody"><tr><td colspan="7" style="text-align:center;color:#8a6d45;">載入中…</td></tr></tbody>
        </table>
        </div>
<?php endif; ?>
    </div>
</div>
</div>

<!-- 模板設定 modal（管理員） -->
<div class="rf-mask" id="settingMask"><div class="rf-modal">
    <div class="m-head"><span id="settingTitle">新增模板</span><span class="m-close" onclick="closeMask('settingMask')">✕</span></div>
    <div class="m-body">
        <input type="hidden" id="stId" value="0">
        <label>模板名稱</label><input type="text" id="stName" maxlength="100" placeholder="例：仿冒零件防制審核表">
        <label>綁定 AS 文件編號</label>
        <div><span id="stDocLabel" style="color:#5b3a1e;">未綁定</span>
            <button type="button" onclick="openTplAsDocPicker()" style="margin-left:8px;height:26px;font-size:12px;border:1px solid #D8BE93;border-radius:4px;background:#fff;cursor:pointer;">選擇…</button></div>
        <label>列印紙張大小</label>
        <select id="stPaper"><option value="A4">A4</option><option value="A3">A3</option></select>

        <div class="rf-sec"><div class="rf-sec-title">審核（可選）</div>
            <label><input type="checkbox" id="stNeedReview"> 需要審核</label>
            <label>審核部門（該部門任一主管審核通過即完成）</label>
            <select id="stReviewDept"><option value="">（未設定）</option></select>
        </div>

        <div class="rf-sec"><div class="rf-sec-title">核准（可選）</div>
            <label><input type="checkbox" id="stNeedApproval"> 需要核准</label>
            <div class="rf-hint">核准人員優先序：由上而下依序嘗試，取第一個有結果的方法；解析到送出者本人會自動跳下一順位（迴避球員兼裁判）。預設僅「最高決策者」。</div>
            <div id="chainBox"></div>
            <div class="grid2">
                <div><label>「部門或人員」方法 — 綁部門</label><select id="stApproverDept"><option value="">（未設定）</option></select></div>
                <div><label>「部門或人員」方法 — 綁人員（優先於部門）</label><select id="stApproverUser" data-eg-filter="輸入人員姓名篩選…"><option value="">（未設定）</option></select></div>
            </div>
        </div>

        <div class="rf-sec"><div class="rf-sec-title">項次內容維護權限（可選）</div>
            <label>維護部門（此部門內主管、以及被指派的維護人員可修改「項次欄位定義」，其餘設定仍僅管理員可改）</label>
            <select id="stMaintainDept"><option value="">（未設定，僅管理員可維護）</option></select>
        </div>
    </div>
    <div class="m-foot"><button class="b-cancel" onclick="closeMask('settingMask')">取消</button>
        <button class="b-ok" onclick="submitTplSettings()">儲存</button></div>
</div></div>

<!-- 項次欄位定義 modal（管理員／維護人員） -->
<div class="rf-mask" id="schemaMask"><div class="rf-modal wide">
    <div class="m-head"><span id="schemaTitle">項次欄位定義</span><span class="m-close" onclick="closeMask('schemaMask')">✕</span></div>
    <div class="m-body">
        <input type="hidden" id="scTplId" value="0">
        <div class="rf-sec-title">逐列可填欄位（除固定的「項目」文字外，額外可設定審查結果／其他欄位）</div>
        <div class="rf-hint">每列固定含「項目」文字欄；以下自訂欄位會依序顯示在項目欄之後。「排版」選整行代表獨佔一列（適合長文字），選並排代表與其他並排欄位同一列。</div>
        <table class="col-tbl">
            <thead><tr><th style="width:16%;">標籤</th><th style="width:12%;">類型</th><th style="width:20%;">提示詞（灰字）</th><th style="width:18%;">選項(逗號分隔，僅下拉用)</th><th style="width:8%;">必填</th><th style="width:10%;">排版</th><th style="width:6%;"></th></tr></thead>
            <tbody id="colBody"></tbody>
        </table>
        <button type="button" onclick="colAdd()" style="height:26px;font-size:12px;border:1px solid #d98a33;background:#F0A24B;color:#fff;border-radius:4px;cursor:pointer;">+ 新增欄位</button>

        <div class="rf-sec"><div class="rf-sec-title">相關日期欄位（自訂標題，如「開始日期」「結案日期」）</div>
        <table class="col-tbl">
            <thead><tr><th style="width:70%;">日期標題</th><th style="width:10%;"></th></tr></thead>
            <tbody id="dateBody"></tbody>
        </table>
        <button type="button" onclick="dateAdd()" style="height:26px;font-size:12px;border:1px solid #d98a33;background:#F0A24B;color:#fff;border-radius:4px;cursor:pointer;">+ 新增日期欄位</button>
        </div>

        <div class="rf-sec"><div class="rf-sec-title">負責人簽名方式</div>
            <label><input type="radio" name="signMode" value="password"> 現場輸入本人密碼線上簽名</label>
            <label><input type="radio" name="signMode" value="notify"> 送出後改用通知請對方回簽</label>
        </div>

        <div class="rf-sec"><div class="rf-sec-title">維護人員名單</div>
        <div class="mt-tags" id="maintTags"></div>
        <select id="maintUserSel" data-eg-filter="輸入人員姓名篩選…" style="width:70%;"><option value="">選擇人員…</option></select>
        <button type="button" onclick="maintainerAdd()" style="height:26px;font-size:12px;border:1px solid #D8BE93;background:#fff;border-radius:4px;cursor:pointer;">加入</button>
        </div>

        <div class="rf-sec"><div class="rf-sec-title">是否連動更新 AS 文件版次</div>
            <label><input type="checkbox" id="scBumpAsDoc"> 本次修改要連動更新 AS 文件版次（存檔即生效）</label>
            <div id="bumpBox" style="display:none;border:1px dashed #E8D5B5;border-radius:6px;padding:8px;margin-top:6px;">
                <div class="grid2">
                    <div><label>新版次號</label><input type="text" id="bumpVersion" placeholder="例：B、2"></div>
                    <div><label>修訂生效日</label><input type="date" id="bumpDate" max="9999-12-31"></div>
                </div>
                <label>修訂重點</label><textarea id="bumpSummary" rows="2"></textarea>
                <label>新版文件檔（有「免附件補登」權限者可留空）</label><input type="file" id="bumpFile">
                <label>文件制修申請單附件一（有「免附件補登」權限者可留空）</label><input type="file" id="bumpApply">
            </div>
        </div>
    </div>
    <div class="m-foot"><button class="b-cancel" onclick="closeMask('schemaMask')">取消</button>
        <button class="b-ok" onclick="submitSchema()">儲存項次欄位定義</button></div>
</div></div>

<!-- 使用說明 modal（鐵律7） -->
<div class="rf-mask" id="helpUseMask"><div class="rf-modal wide">
    <div class="m-head"><span>使用說明 — 審核表單模板管理</span><span class="m-close" onclick="closeMask('helpUseMask')">✕</span></div>
    <div class="m-body help-doc" style="font-size:13px;color:#5b3a1e;line-height:1.8;">
        <h4>功能說明</h4>
        通用「審核表單」引擎：管理員可自建任意張表單模板（首發：2-TD-04-01 仿冒零件防制審核表、2-TD-03-01 產品安全審核表），各模板各自綁定一個 AS 文件編號。一般使用者依模板建立表單、逐列填寫並讓負責人線上簽名，模板可設定送出後要不要走審核/核准。
        <h4>操作步驟</h4>
        <b>①新增模板</b>：設定名稱、綁定 AS 文件編號、列印紙張大小（A4/A3）、是否需要審核（設審核部門，任一主管審過即完成）、是否需要核准（可設核准優先序：綁部門或人員／自動抓送出者上一階主管／全站最高決策者，預設只用「最高決策者」，可調整順序或組合）、維護部門（可指派誰能修改項次內容）。<br>
        <b>②設定項次欄位定義</b>：除固定的「項目」文字欄外，可新增任意數量的自訂欄位（如審查結果下拉、說明欄），每欄可設提示詞（灰字顯示在填寫畫面）、是否必填、排版方式（並排/整行）；可另設「相關日期」欄位（自訂標題，如開始/結案日期）；並選擇負責人簽名方式（現場密碼簽名或送出後通知回簽）。<br>
        <b>③維護人員</b>：管理員或維護部門內主管可指派特定人員為「維護人員」，該名單與維護部門主管都能修改「項次欄位定義」，但不能改模板其他設定（AS文件綁定/審核/核准/維護部門本身）。<br>
        <b>④連動 AS 文件改版</b>：修改項次欄位定義存檔時可勾選「連動更新 AS 文件版次」，需上傳新版文件檔與文件制修申請單（有「免附件補登」權限者可免附件），存檔後立即生效成為現行版本；已建立的舊表單仍顯示建立當下的欄位定義，不受影響。
        <h4>重要行為</h4>
        ・項次欄位定義改版是「存檔即生效」，不另設草稿。已建立的表單各自記錄自己建立當下對應的模板版本，欄位顯示不受之後改版影響。<br>
        ・核准優先序解析到送出表單的本人時，會自動跳下一順位，不會球員兼裁判。
        <h4>設定入口</h4>
        本頁清單「編輯設定」（管理員）／「編輯項次」（管理員或維護人員）。
        <h4>權限角色</h4>
        審核表單檢閱＝看清單；審核表單建立＝可到「建立/填寫表單」頁使用；模板管理＝本頁全部設定；管理者全權。
    </div>
    <div class="m-foot"><button class="b-ok" onclick="closeMask('helpUseMask')">關閉</button></div>
</div></div>

<script src="../../resource/js/jquery.min.js"></script>
<script src="../../resource/js/bootstrap.min.js"></script>
<script src="../../resource/js/nprogress.js"></script>
<script src="../../resource/js/custom.min.js"></script>
<script src="../../resource/js/eg_input_rules.js?v=<?= @filemtime(__DIR__.'/../../resource/js/eg_input_rules.js') ?>"></script>
<script src="../../resource/js/eg_asdoc_picker.js?v=<?= @filemtime(__DIR__.'/../../resource/js/eg_asdoc_picker.js') ?>"></script>
<script>
var API = '../../src/store/ReviewForm_API.php';
var ASDOC_API = '../../src/store/AS_Document_API.php';
var META = {}, TEMPLATES = [];
function esc(s){ return $('<div>').text(s==null?'':s).html(); }
function openMask(id){ $('#'+id).css('display','block'); }
function closeMask(id){ $('#'+id).css('display','none'); }
$(document).ready(function(){
    var $am = $('#sidebar-menu .nav.side-menu > li.active');
    if ($am.length) { $am.removeClass('active').find('ul.child_menu').hide(); $am.find('li.current-page').removeClass('current-page'); }
    $('#sidebar-menu').css('visibility','visible');
});
$('#btnPageHelp').on('click', function(){ openMask('helpUseMask'); });

function loadMeta(cb){
    $.getJSON(API, {action:'meta'}, function(res){
        if (!res.ok){ alert(res.error||'載入失敗'); return; }
        META = res;
        var deptOpts = '<option value="">（未設定）</option>' + META.departments.map(function(d){ return '<option value="'+d.id+'">'+esc(d.name)+'</option>'; }).join('');
        $('#stReviewDept,#stApproverDept,#stMaintainDept').html(deptOpts);
        var userOpts = '<option value="">（未設定）</option>' + META.people.map(function(p){ return '<option value="'+p.id+'">'+esc(p.display)+'</option>'; }).join('');
        $('#stApproverUser').html(userOpts);
        $('#maintUserSel').html('<option value="">選擇人員…</option>' + META.people.map(function(p){ return '<option value="'+p.id+'">'+esc(p.display)+'</option>'; }).join(''));
        if (META.perms.canAdmin) $('#btnAddTpl').show();
        renderChainBox();
        if (cb) cb();
    });
}
function renderChainBox(){
    var methods = {dept_or_user:'部門或人員', auto_supervisor:'自動抓上一階主管', top_approver:'最高決策者'};
    var h = '';
    for (var i=0;i<3;i++){
        h += '<div class="chain-row"><span style="width:44px;color:#8a6d45;">第'+(i+1)+'順位</span><select class="chain-sel" data-idx="'+i+'"><option value="">不使用</option>';
        Object.keys(methods).forEach(function(k){ h += '<option value="'+k+'">'+methods[k]+'</option>'; });
        h += '</select></div>';
    }
    $('#chainBox').html(h);
}
function loadTemplates(){
    $.getJSON(API, {action:'template_list'}, function(res){
        if (!res.ok){ alert(res.error||'載入失敗'); return; }
        TEMPLATES = res.templates;
        var h = '';
        TEMPLATES.forEach(function(t){
            var docTxt = t.as_doc ? (t.as_doc.doc_no + ' ' + t.as_doc.doc_name) : '<span class="tag-off">未綁定</span>';
            h += '<tr><td>'+esc(t.name)+'</td><td>'+docTxt+'</td><td>'+t.paper_size+'</td>'
               + '<td>'+(t.need_review==1?'<span class="tag-on">需審核</span>':'<span class="tag-off">不需要</span>')+'</td>'
               + '<td>'+(t.need_approval==1?'<span class="tag-on">需核准</span>':'<span class="tag-off">不需要</span>')+'</td>'
               + '<td>'+(t.maintain_dept_id?deptName(t.maintain_dept_id):'<span class="tag-off">（未設定）</span>')+'</td>'
               + '<td>'
               + (META.perms.canAdmin ? '<button onclick="openSettingModal('+t.id+')" style="margin-right:4px;">編輯設定</button>' : '')
               + (t.can_edit_items ? '<button onclick="openSchemaModal('+t.id+')">編輯項次</button>' : '')
               + '</td></tr>';
        });
        $('#tplBody').html(h || '<tr><td colspan="7" style="text-align:center;color:#8a6d45;padding:10px;">尚未建立任何模板</td></tr>');
    });
}
function deptName(id){ var d=(META.departments||[]).find(function(x){ return String(x.id)===String(id); }); return d?esc(d.name):''; }

/* ============ 模板設定 ============ */
function openSettingModal(id){
    $('#stId').val(id||0);
    if (!id){
        $('#settingTitle').text('新增模板'); $('#stName').val(''); $('#stPaper').val('A4');
        $('#stDocLabel').text('未綁定').data('id',0);
        $('#stNeedReview').prop('checked',false); $('#stReviewDept').val('');
        $('#stNeedApproval').prop('checked',false); $('#stApproverDept').val(''); $('#stApproverUser').val('');
        renderChainBox(); $('.chain-sel[data-idx=0]').val('top_approver');
        $('#stMaintainDept').val('');
        openMask('settingMask'); return;
    }
    $.getJSON(API, {action:'template_get', id:id}, function(res){
        if (!res.ok){ alert(res.error||'載入失敗'); return; }
        var t = res.template;
        $('#settingTitle').text('編輯模板：'+t.name);
        $('#stName').val(t.name); $('#stPaper').val(t.paper_size);
        $('#stDocLabel').text(t.as_doc ? (t.as_doc.doc_no+' '+t.as_doc.doc_name) : '未綁定').data('id', t.as_doc?t.as_doc.id:0);
        $('#stNeedReview').prop('checked', t.need_review==1); $('#stReviewDept').val(t.review_dept_id||'');
        $('#stNeedApproval').prop('checked', t.need_approval==1);
        $('#stApproverDept').val(t.approver_dept_id||''); $('#stApproverUser').val(t.approver_user_id||'');
        renderChainBox();
        (t.approver_chain||['top_approver']).forEach(function(m,i){ $('.chain-sel[data-idx='+i+']').val(m); });
        $('#stMaintainDept').val(t.maintain_dept_id||'');
        openMask('settingMask');
    });
}
function openTplAsDocPicker(){
    $.getJSON(API, {action:'asdoc_list'}, function(res){
        if (!res.ok){ alert(res.error||'載入失敗'); return; }
        EGAsDoc.open({ docs: res.docs||[], current: $('#stDocLabel').data('id')||0, title:'模板 AS 文件綁定',
            onSave: function(id, doc){ $('#stDocLabel').text(doc?(doc.doc_no+' '+doc.doc_name):'未綁定').data('id', id); }
        });
    });
}
function submitTplSettings(){
    var name = $.trim($('#stName').val());
    if (!name){ alert('請輸入模板名稱'); return; }
    var chain = [];
    $('.chain-sel').each(function(){ var v=$(this).val(); if (v) chain.push(v); });
    $.post(API, {
        action:'template_settings_save', csrf:META.csrf, id:$('#stId').val(), name:name, paper_size:$('#stPaper').val(),
        need_review: $('#stNeedReview').is(':checked')?1:0, review_dept_id:$('#stReviewDept').val(),
        need_approval: $('#stNeedApproval').is(':checked')?1:0,
        approver_dept_id:$('#stApproverDept').val(), approver_user_id:$('#stApproverUser').val(),
        approver_chain: JSON.stringify(chain.length?chain:['top_approver']),
        maintain_dept_id:$('#stMaintainDept').val(), as_doc_id:$('#stDocLabel').data('id')||0
    }, function(res){
        if (!res.ok){ alert(res.error||'儲存失敗'); return; }
        closeMask('settingMask'); loadTemplates();
    }, 'json');
}

/* ============ 項次欄位定義 ============ */
var COLS = [], DATES = [], CUR_SCHEMA_TPL = null;
function colAdd(){ COLS.push({key:'', label:'', type:'text', placeholder:'', required:0, layout:'inline', options:''}); renderCols(); }
function colDel(i){ COLS.splice(i,1); renderCols(); }
function colEdit(i,k,v){ COLS[i][k]=v; if (k==='label' && !COLS[i]._keyManual) COLS[i].key = slugify(v); renderCols(); }
function slugify(s){ return 'c_' + String(s).replace(/[^a-zA-Z0-9一-龥]+/g,'').substr(0,20) + '_' + Math.floor(Math.random()*900+100); }
function renderCols(){
    var h = '';
    COLS.forEach(function(c,i){
        h += '<tr><td><input type="text" value="'+esc(c.label)+'" onchange="colEdit('+i+',\'label\',this.value)"></td>'
           + '<td><select onchange="colEdit('+i+',\'type\',this.value)">'
           +   ['text','textarea','select'].map(function(tp){ return '<option value="'+tp+'"'+(c.type===tp?' selected':'')+'>'+({text:'單行文字',textarea:'多行文字',select:'下拉選單'})[tp]+'</option>'; }).join('')
           + '</select></td>'
           + '<td><input type="text" value="'+esc(c.placeholder)+'" onchange="colEdit('+i+',\'placeholder\',this.value)"></td>'
           + '<td><input type="text" value="'+esc(c.options)+'" '+(c.type!=='select'?'disabled':'')+' placeholder="合格,不合格,其他" onchange="colEdit('+i+',\'options\',this.value)"></td>'
           + '<td style="text-align:center;"><input type="checkbox" '+(c.required?'checked':'')+' onchange="colEdit('+i+',\'required\',this.checked?1:0)"></td>'
           + '<td><select onchange="colEdit('+i+',\'layout\',this.value)"><option value="inline"'+(c.layout==='inline'?' selected':'')+'>並排</option><option value="block"'+(c.layout==='block'?' selected':'')+'>整行</option></select></td>'
           + '<td style="text-align:center;"><span class="rf-del" onclick="colDel('+i+')"><i class="fa fa-times"></i></span></td></tr>';
    });
    $('#colBody').html(h || '<tr><td colspan="7" style="text-align:center;color:#8a6d45;">尚未新增欄位</td></tr>');
}
function dateAdd(){ DATES.push({key:'', label:''}); renderDates(); }
function dateDel(i){ DATES.splice(i,1); renderDates(); }
function dateEdit(i,v){ DATES[i].label = v; if (!DATES[i]._keyManual) DATES[i].key = slugify(v); renderDates(); }
function renderDates(){
    var h = '';
    DATES.forEach(function(d,i){
        h += '<tr><td><input type="text" value="'+esc(d.label)+'" placeholder="例：結案日期" onchange="dateEdit('+i+',this.value)"></td>'
           + '<td style="text-align:center;"><span class="rf-del" onclick="dateDel('+i+')"><i class="fa fa-times"></i></span></td></tr>';
    });
    $('#dateBody').html(h || '<tr><td colspan="2" style="text-align:center;color:#8a6d45;">尚未新增日期欄位</td></tr>');
}
function openSchemaModal(id){
    $.getJSON(API, {action:'template_get', id:id}, function(res){
        if (!res.ok){ alert(res.error||'載入失敗'); return; }
        var t = res.template;
        CUR_SCHEMA_TPL = t;
        $('#scTplId').val(t.id);
        $('#schemaTitle').text('項次欄位定義：'+t.name);
        COLS = (t.schema.columns||[]).map(function(c){ return $.extend({_keyManual:true}, c); });
        DATES = (t.schema.date_fields||[]).map(function(d){ return $.extend({_keyManual:true}, d); });
        renderCols(); renderDates();
        $('input[name=signMode][value="'+(t.schema.sign_mode||'password')+'"]').prop('checked',true);
        renderMaintainers(t.maintainers||[]);
        $('#scBumpAsDoc').prop('checked',false); $('#bumpBox').hide();
        openMask('schemaMask');
    });
}
function renderMaintainers(list){
    var h = list.map(function(m){ return '<span class="tg">'+esc(m.user_cname)+'<i class="fa fa-times" onclick="maintainerRemove('+m.user_id+')"></i></span>'; }).join('');
    $('#maintTags').html(h || '<span style="color:#8a6d45;font-size:12px;">尚未指派維護人員</span>');
}
function maintainerAdd(){
    var uid = $('#maintUserSel').val();
    if (!uid){ alert('請選擇人員'); return; }
    $.post(API, {action:'maintainer_add', csrf:META.csrf, template_id:CUR_SCHEMA_TPL.id, user_id:uid}, function(res){
        if (!res.ok){ alert(res.error||'新增失敗'); return; }
        renderMaintainers(res.maintainers);
    }, 'json');
}
function maintainerRemove(uid){
    $.post(API, {action:'maintainer_remove', csrf:META.csrf, template_id:CUR_SCHEMA_TPL.id, user_id:uid}, function(res){
        if (!res.ok){ alert(res.error||'移除失敗'); return; }
        renderMaintainers(res.maintainers);
    }, 'json');
}
$('#scBumpAsDoc').on('change', function(){ $('#bumpBox').toggle(this.checked); });

function submitSchema(){
    var schema = {
        columns: COLS.filter(function(c){ return $.trim(c.label)!==''; }).map(function(c){
            return {key:c.key, label:c.label, type:c.type, placeholder:c.placeholder||'', required:c.required?1:0, layout:c.layout,
                     options: c.type==='select' ? c.options.split(',').map(function(s){return $.trim(s);}).filter(Boolean) : []};
        }),
        date_fields: DATES.filter(function(d){ return $.trim(d.label)!==''; }).map(function(d){ return {key:d.key, label:d.label}; }),
        sign_mode: $('input[name=signMode]:checked').val() || 'password'
    };
    if (!$('#scBumpAsDoc').is(':checked')) {
        doSchemaSave(schema, null); return;
    }
    var fd = new FormData();
    fd.append('action','add_version'); fd.append('doc_id', CUR_SCHEMA_TPL.as_doc ? CUR_SCHEMA_TPL.as_doc.id : 0);
    fd.append('version', $('#bumpVersion').val()); fd.append('revised_date', $('#bumpDate').val());
    fd.append('revised_summary', $('#bumpSummary').val());
    if ($('#bumpFile')[0].files[0]) fd.append('file', $('#bumpFile')[0].files[0]);
    if ($('#bumpApply')[0].files[0]) fd.append('apply_form', $('#bumpApply')[0].files[0]);
    if (!CUR_SCHEMA_TPL.as_doc){ alert('此模板尚未綁定 AS 文件，請先請管理員到「編輯設定」綁定'); return; }
    fetch(ASDOC_API, {method:'POST', body:fd}).then(function(r){ return r.json(); }).then(function(r){
        if (r.status !== 'success'){ alert(r.message||'AS文件改版失敗'); return; }
        doSchemaSave(schema, r.version_id);
    }).catch(function(){ alert('AS文件改版失敗（連線錯誤）'); });
}
function doSchemaSave(schema, bumpedVersionId){
    $.post(API, {
        action:'template_schema_save', csrf:META.csrf, template_id:CUR_SCHEMA_TPL.id,
        schema: JSON.stringify(schema), bumped_as_doc_version_id: bumpedVersionId||0
    }, function(res){
        if (!res.ok){ alert(res.error||'儲存失敗'); return; }
        closeMask('schemaMask'); loadTemplates();
        alert('已儲存（第 '+res.version+' 版）');
    }, 'json');
}

loadMeta(loadTemplates);
</script>
</body>
</html>
