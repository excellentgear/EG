<?php
/**
 * 表單簽核設計器 — 案件（一般使用者）— 2026-08-14 新增
 * 資料一律走 src/store/FormSigner_API.php；權限 src/common/form_signer_lib.php fsd_perms()
 * 建立案件／意見階段回應／決策階段回應／檢視合成文件並列印（線上疊圖層，不產生伺服器端合併PDF，見規格確認事項4）。
 * 選單入口頁（鐵律6），樣板設計子頁 form_signer_template.php 由本頁連結進入不另外登記選單。
 */
session_start();
if (!isset($_SESSION['userName'])) {
    $_SESSION['lastpage'] = "../../views/ADM/form_signer.php";
    header("Location:../../index.php");
    exit;
}
include_once '../../src/common/_config.php';
include_once '../../src/common/DBConnection.php';
include_once '../../src/common/form_signer_lib.php';

$db = (new DBConnection())->getPDO();
$fsdUser = fsd_current_user($db);
$perms = fsd_perms($db, $fsdUser);
?>
<!DOCTYPE html>
<html lang="zh-Hant">
<head>
    <meta charset="utf-8">
    <meta http-equiv="X-UA-Compatible" content="IE=edge">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>表單簽核設計器 - 案件</title>
    <link href="../../resource/css/bootstrap.css" rel="stylesheet">
    <link href="../../resource/css/font-awesome.css" rel="stylesheet">
    <link href="../../resource/css/nprogress.css" rel="stylesheet">
    <link href="../../resource/css/custom.css" rel="stylesheet">
    <style>
        #sidebar-menu { visibility: hidden; }
        .right_col .page-title { margin:8px 0 4px; overflow:hidden; clear:both; }
        .fsd-toolbar { display:flex; flex-wrap:wrap; gap:6px; align-items:center; clear:both;
            border:1.5px solid #E8D5B5; border-radius:8px; padding:8px 10px; margin-bottom:10px; background:#FDF8EF; }
        .fsd-toolbar button { height:30px; font-size:13px; padding:0 12px; border:1px solid #D8BE93; border-radius:4px;
            background:#fff; color:#5b3a1e; cursor:pointer; }
        .fsd-toolbar .btn-warm { background:#F0A24B; color:#fff; border-color:#d98a33; }
        .page-help-btn { margin-left:auto; height:30px; font-size:13px; padding:0 14px; border:1px solid #D8BE93;
            border-radius:4px; background:#fff; color:#5b3a1e; cursor:pointer; }
        .help-doc h4 { font-size:14px; color:#8A5A2B; margin:10px 0 4px; }
        table.fsd-tbl { width:100%; border-collapse:collapse; font-size:13px; background:#fff; }
        table.fsd-tbl th, table.fsd-tbl td { border:1px solid #EADFC8; padding:6px 8px; }
        table.fsd-tbl thead th { background:#F7E0BD; color:#5b3a1e; }
        .fsd-table-wrap { overflow-x:auto; border:1px solid #E8D5B5; border-radius:6px; }
        .tag-on { color:#7a5217; font-weight:bold; } .tag-off { color:#b0a390; }
        .badge-stage { display:inline-block; padding:2px 8px; border-radius:9px; font-size:11.5px; }
        .badge-progress { background:#F7E0BD; color:#5b3a1e; }
        .badge-approved { background:#dcefdc; color:#2e6b2e; }
        .badge-rejected { background:#f6d9d3; color:#a13a24; }
        .badge-void { background:#eee; color:#999; }
        .badge-waiting { background:#fbeadb; color:#b5762a; font-size:11px; margin-left:4px; }
        .fsd-mask { display:none; position:fixed; inset:0; background:rgba(60,40,20,.45); z-index:1050; }
        .fsd-modal { background:#fff; border-radius:8px; max-width:520px; margin:30px auto; box-shadow:0 5px 25px rgba(0,0,0,.3);
            max-height:90vh; display:flex; flex-direction:column; }
        .fsd-modal .m-head { background:#F7E0BD; color:#5b3a1e; font-weight:bold; padding:10px 15px; border-radius:8px 8px 0 0;
            display:flex; justify-content:space-between; }
        .fsd-modal .m-head .m-close { cursor:pointer; color:#b5762a; }
        .fsd-modal .m-body { padding:15px; overflow-y:auto; }
        .fsd-modal .m-body label { display:block; font-size:13px; color:#5b3a1e; margin:9px 0 3px; }
        .fsd-modal .m-body input[type=text], .fsd-modal .m-body input[type=date], .fsd-modal .m-body select, .fsd-modal .m-body textarea {
            width:100%; border:1px solid #D8BE93; border-radius:4px; padding:5px 8px; font-size:13px; box-sizing:border-box; }
        .fsd-modal .m-foot { padding:10px 15px; border-top:1px solid #EADFC8; text-align:right; }
        .fsd-modal .m-foot button { height:30px; padding:0 16px; border-radius:4px; font-size:13px; border:1px solid #d98a33; cursor:pointer; }
        .fsd-modal .m-foot .b-ok { background:#F0A24B; color:#fff; }
        .fsd-modal .m-foot .b-cancel { background:#fff; color:#5b3a1e; border-color:#D8BE93; margin-right:6px; }
        #detailPanel { display:none; }
        .fsd-doc-grid { display:grid; grid-template-columns:1fr 1fr; gap:16px; max-height:75vh; overflow-y:auto; padding:4px; border:1px solid #E8D5B5; border-radius:8px; background:#faf6ee; }
        .fsd-doc-page { position:relative; border:1px solid #E8D5B5; border-radius:6px; overflow:hidden; background:#fff; }
        .fsd-doc-page img { display:block; width:100%; height:auto; }
        .fsd-box { position:absolute; overflow:hidden; }
        .fsd-box.stamp { display:flex; align-items:center; justify-content:center; }
        .fsd-box.reply { background:rgba(255,255,255,.85); border:1px dashed #D8BE93; font-size:11px; color:#5b3a1e; padding:2px 4px; box-sizing:border-box; }
        .fsd-box .sod-note { color:#b0a390; font-size:10px; }
        .fsd-action-panel { border:1px solid #E8D5B5; border-radius:8px; background:#fff; padding:10px; margin-top:12px; }
        .fsd-resp-list { font-size:12.5px; }
        .fsd-resp-list .r-row { border-bottom:1px solid #F0E7D5; padding:5px 0; }
        @media print {
            .page-help-btn, .fsd-toolbar, #listPanel, .fsd-action-panel, .top_nav, .left_col { display:none !important; }
            .right_col { margin:0 !important; }
            .fsd-doc-grid { grid-template-columns:1fr; max-height:none; overflow:visible; border:none; }
            .fsd-doc-page { page-break-after:always; }
            @page { margin:10mm 8mm; }
        }
    </style>
</head>
<body class="nav-sm">
<div class="container body">
<div class="main_container">
    <?php include '../partPage/sideAndTopBarMenu.html' ?>
    <div class="right_col" role="main">
        <div class="page-title" style="display:flex;align-items:center;flex-wrap:wrap;clear:both;">
            <h2 style="margin:6px 0;">表單簽核設計器 - 案件 <small style="color:#8a6d45;">建立案件、意見/決策回應、檢視並列印簽核完成文件</small></h2>
            <button class="page-help-btn" id="btnPageHelp"><i class="fa fa-question-circle"></i> 使用說明</button>
        </div>
        <div class="clearfix"></div>

<?php if (!$perms['canView']): ?>
        <div><h4><i class="fa fa-lock"></i> 無表單簽核設計器檢閱權限</h4><p>請洽系統管理者於「使用者權限設定」指派「表單簽核設計器」相關角色。</p></div>
<?php else: ?>
        <div id="listPanel">
            <div class="fsd-toolbar">
                <span>依樣板建立案件，系統依序通知各關卡簽核人。</span>
                <button class="btn-warm" id="btnAddCase" style="display:none;margin-left:auto;"><i class="fa fa-plus"></i> 建立案件</button>
                <a href="form_signer_template.php" id="lnkTplAdmin" style="display:none;height:30px;line-height:28px;padding:0 12px;border:1px solid #D8BE93;border-radius:4px;color:#5b3a1e;text-decoration:none;">樣板管理→</a>
            </div>
            <div class="fsd-table-wrap">
            <table class="fsd-tbl">
                <thead><tr><th>案件</th><th>樣板</th><th>申請人</th><th>業務日期</th><th>進度</th><th>狀態</th><th style="width:100px;">操作</th></tr></thead>
                <tbody id="caseBody"><tr><td colspan="7" style="text-align:center;color:#8a6d45;">載入中…</td></tr></tbody>
            </table>
            </div>
        </div>

        <div id="detailPanel">
            <div class="fsd-toolbar">
                <button id="btnBackList"><i class="fa fa-arrow-left"></i> 返回列表</button>
                <b id="dtlTitle" style="margin-left:6px;"></b>
                <span id="dtlStageInfo" style="margin-left:8px;color:#5b3a1e;font-size:12.5px;"></span>
                <button style="margin-left:auto;" id="btnUrge"><i class="fa fa-bell"></i> 催辦</button>
                <button class="btn-warm" onclick="window.print()"><i class="fa fa-print"></i> 列印</button>
            </div>
            <div class="fsd-doc-grid" id="docGrid"></div>

            <div class="fsd-action-panel" id="advisoryPanel" style="display:none;">
                <b>意見回應</b>（無駁回動作，不卡關；同意/不同意皆會記錄並顯示在對應回覆框）
                <div style="margin-top:8px;">
                    <textarea id="advReply" rows="2" placeholder="請輸入您的意見（可留空）"></textarea>
                    <div style="margin-top:6px;">
                        <button class="btn-warm" onclick="submitAdvisory('agree')"><i class="fa fa-check"></i> 同意</button>
                        <button onclick="submitAdvisory('disagree')" style="color:#a13a24;border-color:#DD5138;"><i class="fa fa-times"></i> 不同意</button>
                    </div>
                </div>
            </div>
            <div class="fsd-action-panel" id="decisionPanel" style="display:none;">
                <b>決策回應</b>（您的決定將推動流程前進或終止；可先展開下方查看前面各意見階段的回應彙總）
                <div style="margin-top:8px;">
                    <textarea id="decNote" rows="2" placeholder="核准/駁回原因（駁回必填）"></textarea>
                    <div style="margin-top:6px;">
                        <button class="btn-warm" onclick="submitDecision('approved')"><i class="fa fa-check"></i> 核准</button>
                        <button onclick="submitDecision('rejected')" style="color:#a13a24;border-color:#DD5138;"><i class="fa fa-times"></i> 駁回</button>
                    </div>
                </div>
            </div>
            <div class="fsd-action-panel">
                <b>回應紀錄</b>
                <div class="fsd-resp-list" id="respList" style="margin-top:6px;"></div>
            </div>
        </div>
<?php endif; ?>
    </div>
</div>
</div>

<!-- 建立案件 modal -->
<div class="fsd-mask" id="createMask"><div class="fsd-modal">
    <div class="m-head"><span>建立案件</span><span class="m-close" onclick="closeMask('createMask')">✕</span></div>
    <div class="m-body">
        <label>選擇樣板</label>
        <select id="crTpl"><option value="">請選擇…</option></select>
        <label>案件標題（可留空，預設用樣板名稱）</label><input type="text" id="crTitle" maxlength="200">
        <label>業務日期</label><input type="date" id="crDate" max="9999-12-31">
        <p style="font-size:11.5px;color:#8a6d45;">建立後立即送出，開始通知第一關的簽核人，無法再改成草稿。</p>
    </div>
    <div class="m-foot"><button class="b-cancel" onclick="closeMask('createMask')">取消</button>
        <button class="b-ok" onclick="submitCreate()">建立並送出</button></div>
</div></div>

<!-- 使用說明 modal（鐵律7） -->
<div class="fsd-mask" id="helpUseMask"><div class="fsd-modal" style="max-width:820px;">
    <div class="m-head"><span>使用說明 — 表單簽核設計器（案件）</span><span class="m-close" onclick="closeMask('helpUseMask')">✕</span></div>
    <div class="m-body help-doc" style="font-size:13px;color:#5b3a1e;line-height:1.8;">
        <h4>功能說明</h4>
        依管理員設計好的樣板建立案件，系統依序通知各關卡簽核人；簽核人以圖章模板蓋章回應，回覆內容顯示在樣板設計時框選好的對應位置，最終呈現一份已簽核完成的合成文件（線上檢視＋瀏覽器列印）。
        <h4>操作步驟</h4>
        <b>①建立案件</b>：選擇一個已發布的樣板、填標題與業務日期，建立後立即送出開始跑第一關。<br>
        <b>②意見階段</b>：該階段的每位槽位成員各自表示同意/不同意並可留言，沒有駁回動作、不會互相卡關，全部人（扣除自動迴避的）都回應後自動進入下一關。<br>
        <b>③決策階段</b>：1~2位決策者其中一人核准或駁回即決定流程走向；核准才會繼續跑下一關（或結案），駁回則案件立即終止。<br>
        <b>④催辦</b>：案件申請人或管理員可對目前階段尚未回應的人重新發送一次通知（不會強制略過或自動代為回應）。<br>
        <b>⑤列印</b>：進入案件詳情後按「列印」，瀏覽器會印出目前已疊上所有圖章/回覆內容的合成文件（每頁各自分頁列印）。
        <h4>重要行為</h4>
        ・槽位解析出的人若剛好是案件申請人本人，該槽位自動略過（強制迴避），不會顯示在回覆框裡等待回應。<br>
        ・已核准/已駁回/已作廢的案件不可再回應，只能檢視與列印。
        <h4>設定入口</h4>
        樣板的階段/槽位/框選位置由管理員在「樣板管理」頁設定。
        <h4>權限角色</h4>
        表單簽核設計器檢閱＝看得到自己建立的案件；檢視全部案件＝看得到所有人的案件；建立/送出案件＝可建立新案件；樣板管理＝管理員專屬。
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
var API = '../../src/store/FormSigner_API.php';
var META = {}, CASES = [], TEMPLATES = [];
var CUR_CASE = null, CUR_SCHEMA = null, CUR_RESPONSES = null;
function esc(s){ return $('<div>').text(s==null?'':s).html(); }
function dispDate(s){ return (window.egFmtDate && s) ? egFmtDate(s) : (s||''); }
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
        window.__ownCompany = META.company_name;
        if (!$('#crDate').val()) $('#crDate').val(META.today);
        if (META.perms.canCreate) $('#btnAddCase').show();
        if (META.perms.canAdmin) $('#lnkTplAdmin').show();
        if (cb) cb();
    });
}
function statusBadge(s){
    var map = {in_progress:['進行中','badge-progress'], approved:['已完成','badge-approved'], rejected:['已駁回','badge-rejected'], void:['已作廢','badge-void']};
    var m = map[s] || [s,'badge-progress'];
    return '<span class="badge-stage '+m[1]+'">'+m[0]+'</span>';
}
function loadTemplateOptionsForCreate(){
    $.getJSON(API, {action:'template_list'}, function(res){
        if (!res.ok) return;
        TEMPLATES = (res.templates||[]).filter(function(t){ return t.status==='active' && t.published_version>0; });
        $('#crTpl').html('<option value="">請選擇…</option>' + TEMPLATES.map(function(t){ return '<option value="'+t.id+'">'+esc(t.name)+'</option>'; }).join(''));
    });
}
function loadCases(){
    $.getJSON(API, {action:'case_list'}, function(res){
        if (!res.ok){ alert(res.error||'載入失敗'); return; }
        CASES = res.cases;
        var h = '';
        CASES.forEach(function(c){
            var stageTxt = c.status==='in_progress' ? ('第'+c.current_stage_seq+'關') : '—';
            h += '<tr><td>'+esc(c.title||c.template_name)+'</td><td>'+esc(c.template_name)+'</td><td>'+esc(c.applicant_name)+'</td>'
               + '<td>'+dispDate(c.business_date)+'</td><td>'+stageTxt+'</td><td>'+statusBadge(c.status)+'</td>'
               + '<td><button onclick="openCase('+c.id+')">檢視</button></td></tr>';
        });
        $('#caseBody').html(h || '<tr><td colspan="7" style="text-align:center;color:#8a6d45;padding:10px;">尚無案件</td></tr>');
    });
}
$('#btnAddCase').on('click', function(){ loadTemplateOptionsForCreate(); openMask('createMask'); });
function submitCreate(){
    var tid = $('#crTpl').val();
    if (!tid){ alert('請選擇樣板'); return; }
    $.post(API, {action:'case_create', csrf:META.csrf, template_id:tid, title:$.trim($('#crTitle').val()), business_date:$('#crDate').val()}, function(res){
        if (!res.ok){ alert(res.error||'建立失敗'); return; }
        closeMask('createMask'); $('#crTitle').val('');
        loadCases();
        openCase(res.id);
    }, 'json');
}

/* ============================================================ 案件詳情：合成文件疊圖層 + 回應 ============================================================ */
const PDFJS_BASE = '../../resource/js/pdfjs/';
const PDFJS_V = '<?= @filemtime(__DIR__.'/../../resource/js/pdfjs/pdf.min.js') ?>';
let pdfjsLoading = null;
function ensurePdfJs(){
    if (window.pdfjsLib) return Promise.resolve(window.pdfjsLib);
    if (pdfjsLoading) return pdfjsLoading;
    pdfjsLoading = new Promise(function(resolve, reject){
        var s = document.createElement('script');
        s.src = PDFJS_BASE + 'pdf.min.js?v=' + PDFJS_V;
        s.onload = function(){
            if (!window.pdfjsLib){ pdfjsLoading = null; reject(new Error('pdfjsLib未載入')); return; }
            window.pdfjsLib.GlobalWorkerOptions.workerSrc = PDFJS_BASE + 'pdf.worker.min.js?v=' + PDFJS_V;
            resolve(window.pdfjsLib);
        };
        s.onerror = function(){ pdfjsLoading = null; reject(new Error('pdf.min.js載入失敗')); };
        document.head.appendChild(s);
    });
    return pdfjsLoading;
}
function openCase(id){
    $.getJSON(API, {action:'case_get', id:id}, function(res){
        if (!res.ok){ alert(res.error||'載入失敗'); return; }
        CUR_CASE = res.case; CUR_SCHEMA = res.schema; CUR_RESPONSES = res.responses;
        $('#listPanel').hide(); $('#detailPanel').show();
        $('#dtlTitle').text(CUR_CASE.title || '');
        var stageTxt = CUR_CASE.status==='in_progress' ? ('目前第'+CUR_CASE.current_stage_seq+'關：'+(res.current_stage?res.current_stage.name:'')) : statusBadge(CUR_CASE.status).replace(/<[^>]+>/g,'');
        $('#dtlStageInfo').html(stageTxt);
        $('#advisoryPanel').toggle(!!res.can_advisory_respond);
        $('#decisionPanel').toggle(!!res.can_decision_respond);
        $('#advReply').val(''); $('#decNote').val('');
        renderResponses();
        renderDocGrid();
    });
}
$('#btnBackList').on('click', function(){ $('#detailPanel').hide(); $('#listPanel').show(); CUR_CASE=null; loadCases(); });
$('#btnUrge').on('click', function(){
    if (!CUR_CASE) return;
    $.post(API, {action:'case_urge', csrf:META.csrf, case_id:CUR_CASE.id}, function(res){
        alert(res.ok ? '已重新通知尚未回應的人' : (res.error||'催辦失敗'));
    }, 'json');
});
function submitAdvisory(decision){
    $.post(API, {action:'advisory_respond', csrf:META.csrf, case_id:CUR_CASE.id, decision:decision, reply_text:$.trim($('#advReply').val())}, function(res){
        if (!res.ok){ alert(res.error||'回應失敗'); return; }
        openCase(CUR_CASE.id);
    }, 'json');
}
function submitDecision(decision){
    var note = $.trim($('#decNote').val());
    if (decision === 'rejected' && !note){ alert('駁回必須填寫原因'); return; }
    $.post(API, {action:'decision_respond', csrf:META.csrf, case_id:CUR_CASE.id, decision:decision, note:note}, function(res){
        if (!res.ok){ alert(res.error||'決策失敗'); return; }
        openCase(CUR_CASE.id);
    }, 'json');
}
function renderResponses(){
    var stages = CUR_SCHEMA.stages || [];
    var bySlot = {};
    (CUR_RESPONSES||[]).forEach(function(r){ bySlot[r.slot_key] = r; });
    var h = '';
    stages.forEach(function(s){
        h += '<div class="r-row"><b>第'+s.seq+'關｜'+esc(s.name)+'</b>（'+(s.stage_type==='advisory'?'意見階段':'決策階段')+'）</div>';
        (s.signers||[]).forEach(function(sg){
            var r = bySlot[sg.slot_key];
            var who = sg.label || '（'+({user:'固定人員',dept_auto_manager:'部門自動主管',submitter_supervisor:'上一階主管',top_approver:'最高決策者'}[sg.mode]||sg.mode)+'）';
            var txt;
            if (!r) txt = '<span style="color:#8a6d45;">尚未開始</span>';
            else if (r.decision === 'skipped_sod') txt = '<span class="sod-note">（本人迴避,自動略過）</span>';
            else if (r.decision === null) txt = '<span style="color:#b5762a;">待回應（'+esc(r.resolved_user_name||'')+'）</span>';
            else {
                var decLabel = {agree:'同意', disagree:'不同意', approved:'核准', rejected:'駁回'}[r.decision] || r.decision;
                txt = esc(r.resolved_user_name||'') + '｜' + decLabel + (r.reply_text ? '｜'+esc(r.reply_text) : '') + '｜' + dispDate(r.responded_at);
            }
            h += '<div class="r-row" style="padding-left:10px;">'+esc(who)+'：'+txt+'</div>';
        });
    });
    $('#respList').html(h || '<span style="color:#8a6d45;">（無資料）</span>');
}
function renderDocGrid(){
    var pages = CUR_SCHEMA.pages || [];
    var fields = CUR_SCHEMA.fields || [];
    var bySlot = {};
    (CUR_RESPONSES||[]).forEach(function(r){ bySlot[r.slot_key] = r; });
    var h = '';
    pages.forEach(function(p){ h += '<div class="fsd-doc-page" id="docpg_'+p.page_no+'" data-w="'+p.width_pt+'" data-h="'+p.height_pt+'"></div>'; });
    $('#docGrid').html(h);
    var fileType = (CUR_SCHEMA.file||{}).file_type || 'image';
    var fileUrl = API + '?action=template_file&id=' + CUR_CASE.template_id;
    function paintOverlay(pageNo){
        var $pg = $('#docpg_'+pageNo);
        fields.filter(function(f){ return f.page_no === pageNo; }).forEach(function(f){
            var r = bySlot[f.slot_key];
            var $box = $('<div class="fsd-box '+f.box_type+'"></div>').css({left:(f.x*100)+'%', top:(f.y*100)+'%', width:(f.w*100)+'%', height:(f.h*100)+'%'});
            if (f.box_type === 'stamp') {
                if (r && r.decision && r.decision !== 'skipped_sod' && window.EGStamp) {
                    $box.html(EGStamp.stamp(r.resolved_user_name, (r.responded_at||'').substring(0,10), false));
                } else if (r && r.decision === 'skipped_sod') {
                    $box.html('<span class="sod-note">（迴避）</span>');
                }
            } else {
                if (r && r.decision && r.decision !== 'skipped_sod') {
                    var decLabel = {agree:'同意', disagree:'不同意', approved:'核准', rejected:'駁回'}[r.decision] || r.decision;
                    $box.text('【'+decLabel+'】'+(r.reply_text||''));
                }
            }
            $pg.append($box);
        });
    }
    if (fileType === 'pdf') {
        ensurePdfJs().then(function(lib){ return lib.getDocument({url:fileUrl, withCredentials:true}).promise; }).then(function(doc){
            pages.forEach(function(p){
                doc.getPage(p.page_no).then(function(page){
                    var scale = Math.min(2, 1000/Math.max(page.getViewport({scale:1}).width, page.getViewport({scale:1}).height));
                    var vp = page.getViewport({scale:scale});
                    var cv = document.createElement('canvas'); cv.width = Math.round(vp.width); cv.height = Math.round(vp.height);
                    var ctx = cv.getContext('2d'); ctx.fillStyle = '#fff'; ctx.fillRect(0,0,cv.width,cv.height);
                    page.render({canvasContext:ctx, viewport:vp}).promise.then(function(){
                        $('#docpg_'+p.page_no).prepend('<img src="'+cv.toDataURL('image/png')+'">');
                        paintOverlay(p.page_no);
                    });
                });
            });
        }).catch(function(e){ alert('PDF讀取失敗：'+(e.message||e)); });
    } else {
        pages.forEach(function(p){
            $('#docpg_'+p.page_no).prepend('<img src="'+fileUrl+'">');
            paintOverlay(p.page_no);
        });
    }
}

loadMeta(loadCases);
</script>
</body>
</html>
