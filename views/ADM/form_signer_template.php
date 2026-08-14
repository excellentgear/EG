<?php
/**
 * 表單簽核設計器 — 樣板管理（管理員）— 2026-08-14 新增
 * 資料一律走 src/store/FormSigner_API.php；權限 src/common/form_signer_lib.php fsd_perms()
 * 上傳表單原始檔(圖片/多頁PDF) → 側邊標籤清單拖放框選圖章/回覆內容區塊 → 設定意見/決策階段與槽位 → 發布。
 * 帶參數子頁，鐵律6不登記選單，由 form_signer.php 內連結進入。
 */
session_start();
if (!isset($_SESSION['userName'])) {
    $_SESSION['lastpage'] = "../../views/ADM/form_signer_template.php";
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
    <title>表單簽核設計器 - 樣板管理</title>
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
        .btn-danger { color:#a13a24; border-color:#DD5138; }
        .page-help-btn { margin-left:auto; height:30px; font-size:13px; padding:0 14px; border:1px solid #D8BE93;
            border-radius:4px; background:#fff; color:#5b3a1e; cursor:pointer; }
        .help-doc h4 { font-size:14px; color:#8A5A2B; margin:10px 0 4px; }
        table.fsd-tbl { width:100%; border-collapse:collapse; font-size:13px; background:#fff; }
        table.fsd-tbl th, table.fsd-tbl td { border:1px solid #EADFC8; padding:6px 8px; }
        table.fsd-tbl thead th { background:#F7E0BD; color:#5b3a1e; }
        .fsd-table-wrap { overflow-x:auto; border:1px solid #E8D5B5; border-radius:6px; }
        .tag-on { color:#7a5217; font-weight:bold; } .tag-off { color:#b0a390; }
        .fsd-mask { display:none; position:fixed; inset:0; background:rgba(60,40,20,.45); z-index:1050; }
        .fsd-modal { background:#fff; border-radius:8px; max-width:640px; margin:30px auto; box-shadow:0 5px 25px rgba(0,0,0,.3);
            max-height:90vh; display:flex; flex-direction:column; }
        .fsd-modal.wide { max-width:960px; }
        .fsd-modal .m-head { background:#F7E0BD; color:#5b3a1e; font-weight:bold; padding:10px 15px; border-radius:8px 8px 0 0;
            display:flex; justify-content:space-between; }
        .fsd-modal .m-head .m-close { cursor:pointer; color:#b5762a; }
        .fsd-modal .m-body { padding:15px; overflow-y:auto; }
        .fsd-modal .m-body label { display:block; font-size:13px; color:#5b3a1e; margin:9px 0 3px; }
        .fsd-modal .m-body input[type=text], .fsd-modal .m-body input[type=file], .fsd-modal .m-body select {
            width:100%; border:1px solid #D8BE93; border-radius:4px; padding:5px 8px; font-size:13px; box-sizing:border-box; }
        .fsd-modal .m-foot { padding:10px 15px; border-top:1px solid #EADFC8; text-align:right; }
        .fsd-modal .m-foot button { height:30px; padding:0 16px; border-radius:4px; font-size:13px; border:1px solid #d98a33; cursor:pointer; }
        .fsd-modal .m-foot .b-ok { background:#F0A24B; color:#fff; }
        .fsd-modal .m-foot .b-cancel { background:#fff; color:#5b3a1e; border-color:#D8BE93; margin-right:6px; }
        #designerPanel { display:none; }
        .fsd-stage-card { border:1px solid #E8D5B5; border-radius:8px; background:#fff; margin-bottom:10px; }
        .fsd-stage-head { background:#F7E0BD; padding:6px 10px; border-radius:8px 8px 0 0; display:flex; align-items:center; gap:8px; flex-wrap:wrap; }
        .fsd-stage-head input[type=text] { width:200px; border:1px solid #D8BE93; border-radius:4px; padding:3px 6px; }
        .fsd-stage-body { padding:8px 10px; }
        table.signer-tbl { width:100%; border-collapse:collapse; font-size:12.5px; margin-bottom:6px; }
        table.signer-tbl th, table.signer-tbl td { border:1px solid #EADFC8; padding:4px 6px; }
        table.signer-tbl thead th { background:#FBF0DD; color:#5b3a1e; }
        table.signer-tbl select, table.signer-tbl input { width:100%; border:1px solid #D8BE93; border-radius:4px; padding:3px 5px; font-size:12px; box-sizing:border-box; }
        .fsd-del { color:#DD5138; cursor:pointer; }
        .fsd-design-layout { display:flex; gap:12px; align-items:flex-start; }
        .fsd-label-panel { flex:0 0 220px; border:1px solid #E8D5B5; border-radius:8px; background:#fff; max-height:74vh; overflow-y:auto; }
        .fsd-label-panel .lp-head { background:#F7E0BD; color:#5b3a1e; font-weight:bold; padding:6px 10px; border-radius:8px 8px 0 0; }
        .fsd-label { padding:6px 8px; margin:6px; border-radius:6px; font-size:12px; cursor:grab; border:1px dashed #D8BE93; background:#FDF8EF; color:#5b3a1e; }
        .fsd-label.type-stamp { border-color:#d98a33; }
        .fsd-label.type-reply { border-color:#8A5A2B; }
        .fsd-label .placed { float:right; color:#3f8a3f; }
        .fsd-page-grid { flex:1; display:grid; grid-template-columns:1fr 1fr; gap:16px; max-height:74vh; overflow-y:auto; padding:4px; }
        .fsd-page-wrap { border:1px solid #E8D5B5; border-radius:6px; padding:6px; background:#faf6ee; }
        .fsd-page-wrap .pno { font-size:11px; color:#8a6d45; margin-bottom:4px; }
        .role-item { padding:6px 10px; font-size:12.5px; color:#5b3a1e; cursor:pointer; border-bottom:1px solid #F0E7D5; }
        .role-item:hover { background:#FBF0DD; } .role-item.on { background:#F7E0BD; font-weight:bold; } .role-item.sys { color:#b0a390; cursor:default; }
        @media print { .page-help-btn { display:none; } }
    </style>
</head>
<body class="nav-sm">
<div class="container body">
<div class="main_container">
    <?php include '../partPage/sideAndTopBarMenu.html' ?>
    <div class="right_col" role="main">
        <div class="page-title" style="display:flex;align-items:center;flex-wrap:wrap;clear:both;">
            <h2 style="margin:6px 0;">表單簽核設計器 - 樣板管理 <small style="color:#8a6d45;">上傳原始檔、框選圖章/回覆區塊、設定簽核流程</small></h2>
            <button class="page-help-btn" id="btnPageHelp"><i class="fa fa-question-circle"></i> 使用說明</button>
        </div>
        <div class="clearfix"></div>

<?php if (!$perms['canView']): ?>
        <div><h4><i class="fa fa-lock"></i> 無表單簽核設計器檢閱權限</h4><p>請洽系統管理者於「使用者權限設定」指派「表單簽核設計器」相關角色。</p></div>
<?php else: ?>
        <div id="listPanel">
            <div class="fsd-toolbar">
                <span>在上傳的表單原始檔上直接框選圖章/回覆區塊，保留原始版面。</span>
                <button id="btnRoleSetting" style="display:none;"><i class="fa fa-users"></i> 角色設定</button>
                <button class="btn-warm" id="btnAddTpl" style="display:none;margin-left:auto;"><i class="fa fa-upload"></i> 上傳新樣板</button>
                <a href="form_signer.php" style="height:30px;line-height:28px;padding:0 12px;border:1px solid #D8BE93;border-radius:4px;color:#5b3a1e;text-decoration:none;">前往「案件」→</a>
            </div>
            <div class="fsd-table-wrap">
            <table class="fsd-tbl">
                <thead><tr><th>樣板名稱</th><th>檔案類型</th><th>頁數</th><th>綁定AS文件</th><th>已發布版本</th><th>狀態</th><th style="width:220px;">操作</th></tr></thead>
                <tbody id="tplBody"><tr><td colspan="7" style="text-align:center;color:#8a6d45;">載入中…</td></tr></tbody>
            </table>
            </div>
        </div>

        <div id="designerPanel">
            <div class="fsd-toolbar">
                <button id="btnBackList"><i class="fa fa-arrow-left"></i> 返回列表</button>
                <b id="dsgTplName" style="margin-left:6px;"></b>
                <button onclick="openAsDocPicker()">綁定AS文件</button>
                <span id="dsgAsDoc" style="color:#5b3a1e;font-size:12px;"></span>
                <span style="margin-left:10px;font-size:12px;color:#5b3a1e;">圖章模板</span>
                <select id="dsgStampTpl" style="height:28px;font-size:12px;" onchange="submitStampTpl()"><option value="0">（未綁定，比照全站91px預設）</option></select>
                <button class="btn-warm" style="margin-left:auto;" onclick="addStage()"><i class="fa fa-plus"></i> 新增階段</button>
                <button class="btn-warm" onclick="saveStages()"><i class="fa fa-save"></i> 儲存階段設定</button>
                <button style="background:#F0A24B;color:#fff;border-color:#d98a33;" onclick="publishTemplate()"><i class="fa fa-check"></i> 發布</button>
            </div>
            <div id="stageArea"></div>

            <div class="fsd-toolbar" style="margin-top:14px;">
                <span>框選工作區：把左側標籤拖到頁面對應位置；點選已放置的框可拖曳調整位置/大小，選取後按下方按鈕可刪除。</span>
                <button class="fsd-del" style="border:1px solid #DD5138;margin-left:auto;" onclick="deleteSelectedField()"><i class="fa fa-trash"></i> 刪除選取框</button>
            </div>
            <div class="fsd-design-layout">
                <div class="fsd-label-panel">
                    <div class="lp-head">待框選標籤</div>
                    <div id="labelList"></div>
                </div>
                <div class="fsd-page-grid" id="pageGrid"></div>
            </div>
        </div>
<?php endif; ?>
    </div>
</div>
</div>

<!-- 上傳新樣板 modal -->
<div class="fsd-mask" id="uploadMask"><div class="fsd-modal">
    <div class="m-head"><span>上傳新樣板</span><span class="m-close" onclick="closeMask('uploadMask')">✕</span></div>
    <div class="m-body">
        <label>樣板名稱</label><input type="text" id="upName" maxlength="100" placeholder="例：出貨檢驗簽核單">
        <label>原始檔案（圖片 png/jpg 或多頁 PDF）</label><input type="file" id="upFile" accept=".png,.jpg,.jpeg,.pdf">
        <p style="font-size:11.5px;color:#8a6d45;">上傳後系統會自動量測每頁尺寸，接著即可進入框選工作區設定簽核流程與框選位置。</p>
    </div>
    <div class="m-foot"><button class="b-cancel" onclick="closeMask('uploadMask')">取消</button>
        <button class="b-ok" onclick="submitUpload()">上傳</button></div>
</div></div>

<!-- A4/A3裁切框 modal：框住文件實際內容範圍，取代直接信任原始像素量測出的寬高比 -->
<div class="fsd-mask" id="cropMask"><div class="fsd-modal wide">
    <div class="m-head"><span>A4/A3 裁切框</span><span class="m-close" onclick="closeMask('cropMask')">✕</span></div>
    <div class="m-body">
        <p style="font-size:11.5px;color:#8a6d45;">拖曳/縮放橘色框，框住文件上實際的頁面內容範圍（比例已鎖定為所選紙張大小），之後的圖章最小尺寸與列印版面都會依此範圍換算的實際公分數計算，比直接信任原始掃描/拍照的像素比例更準確。套用後會清空這一頁已框選的位置。</p>
        <div style="display:flex;gap:10px;margin-bottom:8px;align-items:center;">
            <label style="margin:0;">紙張</label>
            <select id="cropPaperSize" style="width:80px;"><option value="A4">A4</option><option value="A3">A3</option></select>
            <label style="margin:0;">方向</label>
            <select id="cropOrientation" style="width:90px;"><option value="portrait">直式</option><option value="landscape">橫式</option></select>
        </div>
        <div style="border:1px solid #E8D5B5;border-radius:6px;background:#faf6ee;padding:6px;text-align:center;">
            <canvas id="cropCanvas"></canvas>
        </div>
    </div>
    <div class="m-foot"><button class="b-cancel" onclick="closeMask('cropMask')">取消</button>
        <button class="b-ok" onclick="confirmCrop()">套用裁切框</button></div>
</div></div>

<!-- 角色設定 modal（管理員；定義本模組角色能看到/做什麼，指派給誰在「使用者權限設定」頁） -->
<div class="fsd-mask" id="roleSetMask"><div class="fsd-modal wide">
    <div class="m-head"><span>角色設定</span><span class="m-close" onclick="closeMask('roleSetMask')">✕</span></div>
    <div class="m-body">
        <p style="font-size:11.5px;color:#8a6d45;">左邊選或新增角色 → 右邊改名稱、勾這個角色能看到什麼／能做什麼。「誰擁有這個角色」在<a href="../user/user_permissions.php" target="_blank">人員權限設定頁</a>設定，這裡只定義角色內容。各階段的簽核槽位（意見成員/決策者）由樣板自己的「設計」設定，不透過此處角色。</p>
        <div style="display:flex;gap:10px;flex-wrap:wrap;align-items:flex-start;">
            <div style="border:1px solid #E8D5B5;border-radius:6px;background:#fff;flex:0 0 190px;">
                <div style="background:#F7E0BD;color:#5b3a1e;font-size:12px;font-weight:bold;padding:5px 10px;border-radius:6px 6px 0 0;display:flex;justify-content:space-between;align-items:center;">角色
                    <button type="button" id="btnRoleAdd" style="padding:1px 8px;height:22px;font-size:11px;border:1px solid #D8BE93;background:#fff;border-radius:4px;cursor:pointer;">＋ 新增</button></div>
                <div id="roleList" style="max-height:280px;overflow-y:auto;"></div>
            </div>
            <div style="border:1px solid #E8D5B5;border-radius:6px;background:#fff;flex:1;min-width:260px;">
                <div style="background:#F7E0BD;color:#5b3a1e;font-size:12px;font-weight:bold;padding:5px 10px;border-radius:6px 6px 0 0;">角色內容</div>
                <div id="roleEdit" style="display:none;padding:10px;">
                    <label>角色名稱</label>
                    <div style="display:flex;gap:6px;">
                        <input type="text" id="roleName" style="flex:1;">
                        <button type="button" id="btnRoleRename" style="height:28px;font-size:12px;border:1px solid #D8BE93;background:#fff;border-radius:4px;cursor:pointer;">改名</button>
                        <button type="button" id="btnRoleDel" style="height:28px;font-size:12px;border:1px solid #D8BE93;background:#fff;color:#DD5138;border-radius:4px;cursor:pointer;">刪除</button>
                    </div>
                    <div style="font-size:12px;font-weight:bold;color:#8A5A2B;margin:10px 0 4px;">可視內容（看得到什麼）</div>
                    <div id="featView"></div>
                    <div style="font-size:12px;font-weight:bold;color:#8A5A2B;margin:10px 0 4px;">可操作（能做什麼）</div>
                    <div id="featOp"></div>
                    <button type="button" id="btnRoleFeatSave" style="margin-top:10px;height:28px;font-size:12px;border:1px solid #d98a33;background:#F0A24B;color:#fff;border-radius:4px;cursor:pointer;"><i class="fa fa-save"></i> 儲存功能</button>
                </div>
                <div id="roleEditHint" style="padding:24px;text-align:center;color:#8a6d45;">請在左側選一個角色，或按「＋ 新增」</div>
            </div>
        </div>
    </div>
    <div class="m-foot"><button class="b-ok" onclick="closeMask('roleSetMask')">關閉</button></div>
</div></div>

<!-- 使用說明 modal（鐵律7） -->
<div class="fsd-mask" id="helpUseMask"><div class="fsd-modal wide">
    <div class="m-head"><span>使用說明 — 表單簽核設計器</span><span class="m-close" onclick="closeMask('helpUseMask')">✕</span></div>
    <div class="m-body help-doc" style="font-size:13px;color:#5b3a1e;line-height:1.8;">
        <h4>功能說明</h4>
        在管理員上傳的表單原始檔（圖片或多頁PDF）上，用「拖拽標籤到畫面定位」的方式框選出圖章區與回覆內容區，設定意見階段（並簽、不卡關）與決策階段（1~2人、真正決定流程走向）的有序流程。與「審核表單」「AS線上表單設計器」是三套獨立引擎，本模組專門用在需要保留原始版面（如客戶指定格式、紙本掃描件）的表單。
        <h4>操作步驟</h4>
        <b>①上傳新樣板</b>：填名稱、選檔案（圖片或PDF），上傳後系統自動量測頁面尺寸。<br>
        <b>②設定階段</b>：新增階段（意見/決策二擇一）、每階段可加多個槽位（簽核人來源：固定人員／部門自動主管／送出者上一階主管／全站最高決策者），設定完按「儲存階段設定」。<br>
        <b>③框選</b>：左側「待框選標籤」依剛設定的階段槽位自動產生（每槽位各一個圖章框標籤＋一個回覆框標籤），拖到右側頁面對應位置放開即完成框選；已放置的框可再拖曳調整位置/大小，選取後可刪除。圖章框有最小尺寸限制（比照全站列印圖章91px標準換算），太小會被擋下。<br>
        <b>④綁定AS文件</b>：可選填，綁定後列印時頁尾會顯示對應的AS文件編號與版次。<br>
        <b>⑤發布</b>：設定完成後按「發布」，會把目前的階段/槽位/框選整包存成一個版本快照，之後才能在「案件」頁選用此樣板建立案件。
        <h4>重要行為</h4>
        ・發布是「存檔即生效」的版本快照，已建立的案件會固定使用建立當下的版本，之後改版不影響進行中的案件。<br>
        ・意見階段沒有駁回動作、不互相卡關，全部槽位（扣除迴避的）都回應才算完成；逾期未回應僅會提醒，不會自動略過。<br>
        ・槽位解析出的人若剛好是送出案件的本人，該槽位自動略過（強制迴避，避免球員兼裁判）。
        <h4>設定入口</h4>
        本頁「上傳新樣板」／清單「設計」。
        <h4>權限角色</h4>
        表單簽核設計器檢閱＝看清單；建立/送出案件＝可到「案件」頁使用；樣板管理＝本頁全部設定；管理者全權。
    </div>
    <div class="m-foot"><button class="b-ok" onclick="closeMask('helpUseMask')">關閉</button></div>
</div></div>

<script src="../../resource/js/jquery.min.js"></script>
<script src="../../resource/js/bootstrap.min.js"></script>
<script src="../../resource/js/nprogress.js"></script>
<script src="../../resource/js/custom.min.js"></script>
<script src="../../resource/js/eg_input_rules.js?v=<?= @filemtime(__DIR__.'/../../resource/js/eg_input_rules.js') ?>"></script>
<script src="../../resource/js/eg_asdoc_picker.js?v=<?= @filemtime(__DIR__.'/../../resource/js/eg_asdoc_picker.js') ?>"></script>
<script src="../../resource/js/fabric.min.js?v=<?= @filemtime(__DIR__.'/../../resource/js/fabric.min.js') ?>"></script>
<script>
var API = '../../src/store/FormSigner_API.php';
var META = {}, TEMPLATES = [];
var CUR_TPL = null;     // 目前設計中的樣板(template_get回傳整包)
var STAGES = [];        // 設計中的階段陣列(前端暫存,儲存後才回寫DB)
var CANVASES = {};      // page_no -> fabric.Canvas
var SELECTED_OBJ = null;

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
        if (META.perms.canAdmin) { $('#btnAddTpl').show(); $('#btnRoleSetting').show(); }
        if (cb) cb();
    });
}
function loadTemplates(){
    $.getJSON(API, {action:'template_list'}, function(res){
        if (!res.ok){ alert(res.error||'載入失敗'); return; }
        TEMPLATES = res.templates;
        var h = '';
        TEMPLATES.forEach(function(t){
            var docTxt = t.as_doc ? (t.as_doc.doc_no + ' ' + t.as_doc.doc_name) : '<span class="tag-off">未綁定</span>';
            h += '<tr><td>'+esc(t.name)+'</td><td>'+(t.file_type==='pdf'?'PDF':'圖片')+'</td><td>'+t.page_count+'</td><td>'+docTxt+'</td>'
               + '<td>'+(t.published_version>0?('v'+t.published_version):'<span class="tag-off">尚未發布</span>')+'</td>'
               + '<td>'+(t.status==='active'?'<span class="tag-on">啟用</span>':'<span class="tag-off">停用</span>')+'</td>'
               + '<td>'
               + (META.perms.canAdmin ? '<button onclick="openDesigner('+t.id+')" style="margin-right:4px;">設計</button>' : '')
               + (META.perms.canAdmin ? '<button onclick="toggleStatus('+t.id+',\''+t.status+'\')" style="margin-right:4px;">'+(t.status==='active'?'停用':'啟用')+'</button>' : '')
               + (META.perms.canAdmin && t.can_delete ? '<button class="btn-danger" onclick="deleteTpl('+t.id+')" title="從未被任何案件使用過,可直接刪除"><i class="fa fa-trash"></i> 刪除</button>' : '')
               + (META.perms.canAdmin && !t.can_delete ? '<span style="color:#b0a390;font-size:11.5px;" title="已有案件使用過此樣板,無法刪除,只能停用">（已使用,不可刪除）</span>' : '')
               + '</td></tr>';
        });
        $('#tplBody').html(h || '<tr><td colspan="7" style="text-align:center;color:#8a6d45;padding:10px;">尚未上傳任何樣板</td></tr>');
    });
}
function toggleStatus(id, cur){
    $.post(API, {action:'template_set_status', csrf:META.csrf, id:id, status: cur==='active'?'inactive':'active'}, function(res){
        if (!res.ok){ alert(res.error||'操作失敗'); return; }
        loadTemplates();
    }, 'json');
}
function deleteTpl(id){
    if (!confirm('確定要永久刪除此樣板？(僅限從未被任何案件使用過的樣板，此動作無法復原)')) return;
    $.post(API, {action:'template_delete', csrf:META.csrf, id:id}, function(res){
        if (!res.ok){ alert(res.error||'刪除失敗'); return; }
        loadTemplates();
    }, 'json');
}
function submitUpload(){
    var name = $.trim($('#upName').val());
    if (!name){ alert('請輸入樣板名稱'); return; }
    var f = $('#upFile')[0].files[0];
    if (!f){ alert('請選擇檔案'); return; }
    var fd = new FormData();
    fd.append('action','template_upload'); fd.append('csrf', META.csrf); fd.append('name', name); fd.append('file', f);
    fetch(API, {method:'POST', body:fd}).then(function(r){ return r.json(); }).then(function(res){
        if (!res.ok){ alert(res.error||'上傳失敗'); return; }
        closeMask('uploadMask'); $('#upName').val(''); $('#upFile').val('');
        loadTemplates();
        openDesigner(res.id);
    }).catch(function(){ alert('上傳失敗（連線錯誤）'); });
}
$('#btnAddTpl').on('click', function(){ openMask('uploadMask'); });

/* ============================================================ 角色設定（比照 review_form_template.php） ============================================================ */
$('#btnRoleSetting').on('click', function(){ openMask('roleSetMask'); loadRoles(); });
var RAPI = '../../src/store/Roles_API.php';
var ROLES = [], CURROLE = 0;
function loadRoles(then){
    $.getJSON(RAPI, {action:'get_roles', module:'form_signer'}, function(res){
        ROLES = res.data || [];
        var h = '';
        ROLES.forEach(function(r){
            var sys = String(r.is_system)==='1';
            h += '<div class="role-item'+(sys?' sys':'')+'" data-id="'+r.role_id+'">'+esc(r.role_name)+(sys?'（系統．固定全權）':'')+'</div>';
        });
        $('#roleList').html(h || '<div style="padding:10px;color:#8a6d45;">尚無角色</div>');
        if (CURROLE) $('.role-item[data-id="'+CURROLE+'"]').addClass('on');
        if (typeof then==='function') then();
    });
}
function selRole(id){
    var r = ROLES.filter(function(x){ return String(x.role_id)===String(id); })[0];
    if (!r) return;
    if (String(r.is_system)==='1'){ alert('系統角色「'+r.role_name+'」固定擁有全部權限，不可修改'); return; }
    CURROLE = id;
    $('.role-item').removeClass('on'); $('.role-item[data-id="'+id+'"]').addClass('on');
    $('#roleEditHint').hide(); $('#roleEdit').show();
    $('#roleName').val(r.role_name);
    var vh='', oh='';
    (META.features||[]).forEach(function(f){
        var row = '<label class="role-feat" style="display:block;font-weight:normal;padding:2px 0;font-size:12.5px;"><input type="checkbox" class="featcb" value="'+esc(f.code)+'"> '+esc(f.label)+'</label>';
        if (f.group==='view') vh += row; else oh += row;
    });
    $('#featView').html(vh); $('#featOp').html(oh);
    $.getJSON(RAPI, {action:'get_role_features', role_id:id}, function(res){
        var has = res.data || [];
        $('.featcb').each(function(){ $(this).prop('checked', has.indexOf(this.value)>-1 || has.indexOf('all')>-1); });
    });
}
$(document).on('click', '#roleList .role-item', function(){ selRole($(this).data('id')); });
$('#btnRoleAdd').on('click', function(){
    var n = prompt('新角色名稱：');
    if (!n || !$.trim(n)) return;
    $.post(RAPI, {action:'save_role', role_name:$.trim(n), module:'form_signer'}, function(r){
        if (!r.success){ alert(r.message); return; }
        loadRoles(function(){ selRole(r.role_id); });
    }, 'json');
});
$('#btnRoleRename').on('click', function(){
    if (!CURROLE) return;
    var n = $.trim($('#roleName').val()||'');
    if (!n){ alert('請輸入角色名稱'); return; }
    $.post(RAPI, {action:'save_role', role_id:CURROLE, role_name:n}, function(r){
        if (!r.success){ alert(r.message); return; }
        loadRoles(); alert('已改名');
    }, 'json');
});
$('#btnRoleDel').on('click', function(){
    if (!CURROLE) return;
    if (!confirm('確定刪除此角色？擁有此角色的人會失去對應權限。')) return;
    $.post(RAPI, {action:'delete_role', role_id:CURROLE}, function(r){
        if (!r.success){ alert(r.message); return; }
        CURROLE = 0; $('#roleEdit').hide(); $('#roleEditHint').show();
        loadRoles();
    }, 'json');
});
$('#btnRoleFeatSave').on('click', function(){
    if (!CURROLE) return;
    var feats = $('.featcb:checked').map(function(){ return this.value; }).get();
    $.post(RAPI, {action:'save_role_features', role_id:CURROLE, features:JSON.stringify(feats)}, function(r){
        alert(r.success ? '已儲存。受影響的人重新整理頁面後生效。' : r.message);
    }, 'json');
});

/* ============================================================ 設計工作區 ============================================================ */
function openDesigner(id){
    $.getJSON(API, {action:'template_get', id:id}, function(res){
        if (!res.ok){ alert(res.error||'載入失敗'); return; }
        CUR_TPL = res.template;
        STAGES = (CUR_TPL.stages||[]).map(function(s){ return $.extend({}, s); });
        $('#listPanel').hide(); $('#designerPanel').show();
        $('#dsgTplName').text(CUR_TPL.name + '（'+(CUR_TPL.file_type==='pdf'?'PDF':'圖片')+'，共'+CUR_TPL.page_count+'頁）');
        $('#dsgAsDoc').text(CUR_TPL.as_doc ? ('已綁定：'+CUR_TPL.as_doc.doc_no+' '+CUR_TPL.as_doc.doc_name) : '未綁定AS文件');
        loadStampTplOptions();
        renderStages();
        if (!CUR_TPL.pages || !CUR_TPL.pages.length) {
            measureAndSavePages(function(){ buildPageCanvases(); });
        } else {
            buildPageCanvases();
        }
    });
}
$('#btnBackList').on('click', function(){
    $('#designerPanel').hide(); $('#listPanel').show();
    CANVASES = {}; loadTemplates();
});
function openAsDocPicker(){
    $.getJSON(API, {action:'asdoc_list'}, function(res){
        if (!res.ok){ alert(res.error||'載入失敗'); return; }
        EGAsDoc.open({ docs: res.docs||[], current: CUR_TPL.as_doc?CUR_TPL.as_doc.id:0, title:'樣板 AS 文件綁定',
            onSave: function(id, doc){
                $.post(API, {action:'asdoc_save', csrf:META.csrf, template_id:CUR_TPL.id, as_doc_id:id}, function(r){
                    if (!r.ok){ alert(r.error||'綁定失敗'); return; }
                    CUR_TPL.as_doc = doc;
                    $('#dsgAsDoc').text(doc ? ('已綁定：'+doc.doc_no+' '+doc.doc_name) : '未綁定AS文件');
                }, 'json');
            }
        });
    });
}
/* 圖章模板綁定：圖章尺寸依此模板設定的公分數計算(使用者明確要求)，換算邏輯與最小框選尺寸驗證見form_signer_lib.php fsd_field_min_frac()。 */
function loadStampTplOptions(){
    $.getJSON(API, {action:'stamp_tpl_options'}, function(res){
        if (!res.ok) return;
        var h = '<option value="0">（未綁定，比照全站91px預設）</option>';
        (res.templates||[]).forEach(function(t){ h += '<option value="'+t.id+'">'+(t.type_name?esc(t.type_name)+'｜':'')+esc(t.tpl_name)+'</option>'; });
        $('#dsgStampTpl').html(h).val(CUR_TPL.stamp_tpl ? CUR_TPL.stamp_tpl.id : 0);
    });
}
function submitStampTpl(){
    var id = $('#dsgStampTpl').val();
    $.post(API, {action:'stamp_tpl_save', csrf:META.csrf, template_id:CUR_TPL.id, stamp_tpl_id:id}, function(res){
        if (!res.ok){ alert(res.error||'設定失敗'); return; }
        CUR_TPL.stamp_tpl = res.template.stamp_tpl;
        alert('已設定圖章模板，既有框選的最小尺寸限制已依新模板重新計算(僅影響之後新框選/調整，既有框選不會自動變動)');
    }, 'json');
}

/* -------- 階段/槽位設定 -------- */
var SIGNER_MODE_LABEL = {user:'固定人員', dept_auto_manager:'部門自動主管', submitter_supervisor:'送出者上一階主管', top_approver:'全站最高決策者', filler:'填表人'};
function addStage(){ STAGES.push({stage_type:'advisory', name:'第'+(STAGES.length+1)+'關', auto_sign:0, signers:[]}); renderStages(); }
function delStage(i){ if (!confirm('確定刪除此階段？')) return; STAGES.splice(i,1); renderStages(); }
function stageEdit(i,k,v){ STAGES[i][k]=v; }
function addSigner(si){ STAGES[si].signers = STAGES[si].signers||[]; STAGES[si].signers.push({mode:'top_approver', user_id:null, dept_id:null, label:''}); renderStages(); }
function delSigner(si,gi){ STAGES[si].signers.splice(gi,1); renderStages(); }
function signerEdit(si,gi,k,v){ STAGES[si].signers[gi][k]=v; }
function renderStages(){
    var deptOpts = '<option value="">（不指定＝以填表人所屬部門自動判斷）</option>' + (META.departments||[]).map(function(d){ return '<option value="'+d.id+'">'+esc(d.name)+'</option>'; }).join('');
    var userOpts = '<option value="">選人員…</option>' + (META.people||[]).map(function(p){ return '<option value="'+p.id+'">'+esc(p.display)+'</option>'; }).join('');
    var h = '';
    STAGES.forEach(function(s, si){
        h += '<div class="fsd-stage-card"><div class="fsd-stage-head">'
           + '<span>第'+(si+1)+'關</span>'
           + '<select onchange="stageEdit('+si+',\'stage_type\',this.value)"><option value="advisory"'+(s.stage_type==='advisory'?' selected':'')+'>意見階段(並簽,不卡關)</option><option value="decision"'+(s.stage_type==='decision'?' selected':'')+'>決策階段(1~2人,決定流程走向)</option></select>'
           + '<input type="text" value="'+esc(s.name)+'" placeholder="階段名稱" onchange="stageEdit('+si+',\'name\',this.value)">'
           + '<label style="margin:0;font-size:12px;"><input type="checkbox" '+(s.auto_sign?'checked':'')+' onchange="stageEdit('+si+',\'auto_sign\',this.checked?1:0)"> 自動簽核(免真人,依ai-rules/21規則)</label>'
           + '<span class="fsd-del" style="margin-left:auto;" onclick="delStage('+si+')"><i class="fa fa-trash"></i> 刪除階段</span>'
           + '</div><div class="fsd-stage-body">'
           + '<table class="signer-tbl"><thead><tr><th style="width:22%;">簽核人來源</th><th style="width:28%;">指定對象</th><th style="width:20%;">標籤名稱(顯示用)</th><th style="width:10%;"></th></tr></thead><tbody>';
        (s.signers||[]).forEach(function(sg, gi){
            h += '<tr><td><select onchange="signerEdit('+si+','+gi+',\'mode\',this.value)">';
            Object.keys(SIGNER_MODE_LABEL).forEach(function(m){ h += '<option value="'+m+'"'+(sg.mode===m?' selected':'')+'>'+SIGNER_MODE_LABEL[m]+'</option>'; });
            h += '</select></td><td>';
            if (sg.mode === 'user') h += '<select onchange="signerEdit('+si+','+gi+',\'user_id\',this.value)">'+userOpts.replace('value="'+sg.user_id+'"','value="'+sg.user_id+'" selected')+'</select>';
            else if (sg.mode === 'dept_auto_manager') h += '<select onchange="signerEdit('+si+','+gi+',\'dept_id\',this.value)">'+deptOpts.replace('value="'+sg.dept_id+'"','value="'+sg.dept_id+'" selected')+'</select>';
            else h += '<span style="color:#8a6d45;">（自動解析,無需指定）</span>';
            h += '</td><td><input type="text" value="'+esc(sg.label||'')+'" placeholder="如:品管部主管" onchange="signerEdit('+si+','+gi+',\'label\',this.value)"></td>'
               + '<td style="text-align:center;"><span class="fsd-del" onclick="delSigner('+si+','+gi+')"><i class="fa fa-times"></i></span></td></tr>';
        });
        h += '</tbody></table><button type="button" onclick="addSigner('+si+')" style="height:24px;font-size:12px;border:1px solid #D8BE93;background:#fff;border-radius:4px;cursor:pointer;">+ 新增槽位(一位簽核人)</button>'
           + '</div></div>';
    });
    $('#stageArea').html(h || '<p style="color:#8a6d45;">尚未設定任何階段，請按「新增階段」。</p>');
}
function saveStages(){
    if (!STAGES.length){ alert('請至少新增一個階段'); return; }
    for (var i=0;i<STAGES.length;i++){
        if (!STAGES[i].signers || !STAGES[i].signers.length){ alert('第'+(i+1)+'關尚未設定任何槽位(簽核人)'); return; }
    }
    $.post(API, {action:'stages_save', csrf:META.csrf, template_id:CUR_TPL.id, stages:JSON.stringify(STAGES)}, function(res){
        if (!res.ok){ alert(res.error||'儲存失敗'); return; }
        // 存檔後整個重新載入(而不是只拿res.stages局部更新)：槽位若真的被刪除，對應框選也會被連動清掉，
        // 必須重抓CUR_TPL.fields與重畫畫布才能反映實際現況，避免畫面留著已經不存在的舊框選(2026-08-14修正)。
        openDesigner(CUR_TPL.id);
        alert('階段設定已儲存，可到下方框選工作區拖放標籤');
    }, 'json');
}
function publishTemplate(){
    if (!confirm('確定要發布目前的設定？發布後即可在「案件」頁選用此樣板建立新案件。')) return;
    $.post(API, {action:'schema_publish', csrf:META.csrf, template_id:CUR_TPL.id}, function(res){
        if (!res.ok){ alert(res.error||'發布失敗'); return; }
        CUR_TPL = res.template;
        alert('已發布 v'+res.version);
        loadTemplates();
    }, 'json');
}

/* -------- 頁面尺寸量測(上傳後首次進入設計頁自動跑一次) -------- */
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
function measureAndSavePages(done){
    var fileUrl = API + '?action=template_file&id=' + CUR_TPL.id;
    if (CUR_TPL.file_type === 'pdf') {
        ensurePdfJs().then(function(lib){
            return lib.getDocument({url: fileUrl, withCredentials:true}).promise;
        }).then(function(doc){
            var pages = []; var i = 1;
            function next(){
                if (i > doc.numPages) {
                    $.post(API, {action:'pages_save', csrf:META.csrf, template_id:CUR_TPL.id, pages:JSON.stringify(pages)}, function(res){
                        if (!res.ok){ alert(res.error||'頁面量測儲存失敗'); return; }
                        CUR_TPL.pages = res.pages; CUR_TPL.page_count = res.pages.length;
                        if (done) done();
                    }, 'json');
                    return;
                }
                doc.getPage(i).then(function(page){
                    var vp = page.getViewport({scale:1});
                    pages.push({page_no:i, width_pt:vp.width, height_pt:vp.height});
                    i++; next();
                });
            }
            next();
        }).catch(function(e){ alert('PDF讀取失敗：' + (e.message||e)); });
    } else {
        var img = new Image();
        img.onload = function(){
            var widthPt = img.naturalWidth / 96 * 72, heightPt = img.naturalHeight / 96 * 72;
            $.post(API, {action:'pages_save', csrf:META.csrf, template_id:CUR_TPL.id, pages:JSON.stringify([{page_no:1, width_pt:widthPt, height_pt:heightPt}])}, function(res){
                if (!res.ok){ alert(res.error||'頁面量測儲存失敗'); return; }
                CUR_TPL.pages = res.pages;
                if (done) done();
            }, 'json');
        };
        img.onerror = function(){ alert('圖片讀取失敗'); };
        img.src = fileUrl;
    }
}
/** 把來源(img)依rotation(0/90/180/270)轉正畫到新canvas，回傳該canvas；旋轉會交換寬高。 */
function rotateToCanvas(src, srcW, srcH, rotationDeg){
    rotationDeg = ((rotationDeg||0) % 360 + 360) % 360;
    var swapped = (rotationDeg === 90 || rotationDeg === 270);
    var outW = swapped ? srcH : srcW, outH = swapped ? srcW : srcH;
    var cv = document.createElement('canvas'); cv.width = outW; cv.height = outH;
    var ctx = cv.getContext('2d');
    ctx.translate(outW/2, outH/2);
    ctx.rotate(rotationDeg * Math.PI/180);
    ctx.drawImage(src, -srcW/2, -srcH/2, srcW, srcH);
    return cv;
}
/** 每頁各自rotation(人工修正掃描歪斜方向)：PDF直接用pdf.js viewport rotation參數轉正；
 *  圖片(image類型整份文件只有1頁)用canvas重繪轉正。回呼cb(pageNo, dataURL)。 */
function renderDocPages(fileType, fileUrl, pages, cb){
    if (fileType === 'pdf') {
        ensurePdfJs().then(function(lib){ return lib.getDocument({url:fileUrl, withCredentials:true}).promise; }).then(function(doc){
            pages.forEach(function(p){
                doc.getPage(p.page_no).then(function(page){
                    var rotation = (p.rotation||0) % 360;
                    var base = page.getViewport({scale:1, rotation:rotation});
                    // 同form_signer.php修正：改用約220dpi(scale=dpi/72)，長邊3500px上限防止巨大PDF記憶體爆掉(2026-08-14)。
                    var scale = Math.min(220/72, 3500/Math.max(base.width, base.height));
                    var vp = page.getViewport({scale:scale, rotation:rotation});
                    var cv = document.createElement('canvas'); cv.width = Math.round(vp.width); cv.height = Math.round(vp.height);
                    var ctx = cv.getContext('2d'); ctx.fillStyle = '#fff'; ctx.fillRect(0,0,cv.width,cv.height);
                    page.render({canvasContext:ctx, viewport:vp}).promise.then(function(){ cb(p.page_no, cv.toDataURL('image/png')); });
                });
            });
        }).catch(function(e){ alert('PDF讀取失敗：'+(e.message||e)); });
    } else {
        pages.forEach(function(p){
            if (!p.rotation) { cb(p.page_no, fileUrl); return; }
            var img = new Image();
            img.onload = function(){ cb(p.page_no, rotateToCanvas(img, img.naturalWidth, img.naturalHeight, p.rotation).toDataURL('image/png')); };
            img.src = fileUrl;
        });
    }
}
/** 旋轉該頁90度：交換有效寬高、清空該頁既有框選(座標系已變)、存檔後整個重繪。 */
function rotatePage(pageNo){
    var p = (CUR_TPL.pages||[]).filter(function(x){ return x.page_no==pageNo; })[0];
    if (!p){ alert('找不到第'+pageNo+'頁的資料'); return; }
    if (!confirm('旋轉這一頁會清空此頁已框選的位置，確定要旋轉嗎？')) return;
    var newRotation = ((p.rotation||0) + 90) % 360;
    var newWidthPt = p.height_pt, newHeightPt = p.width_pt;
    $.post(API, {action:'field_delete_page', csrf:META.csrf, template_id:CUR_TPL.id, page_no:pageNo}, function(res0){
        if (!res0.ok){ alert(res0.error||'清空框選失敗'); return; }
        p.rotation = newRotation; p.width_pt = newWidthPt; p.height_pt = newHeightPt;
        $.post(API, {action:'pages_save', csrf:META.csrf, template_id:CUR_TPL.id, pages:JSON.stringify(CUR_TPL.pages)}, function(res){
            if (!res.ok){ alert(res.error||'旋轉失敗'); return; }
            CUR_TPL.pages = res.pages;
            $.getJSON(API, {action:'template_get', id:CUR_TPL.id}, function(res2){
                CUR_TPL.fields = res2.template.fields;
                buildPageCanvases();
            }).fail(function(xhr){ alert('重新載入樣板失敗(連線錯誤 '+xhr.status+')'); });
        }, 'json').fail(function(xhr){ alert('旋轉失敗(連線錯誤 '+xhr.status+')：'+xhr.responseText); });
    }, 'json').fail(function(xhr){ alert('清空框選失敗(連線錯誤 '+xhr.status+')：'+xhr.responseText); });
}

/* -------- A4/A3裁切框：用固定比例的框框住文件實際內容範圍，取代直接信任原始像素量測出的寬高比 -------- */
var CROP_CANVAS = null, CROP_RECT = null, CROP_PAGE_NO = 0;
function cropRatio(){
    // A4/A3同屬ISO 216 A系列,長寬比皆為1:根號2,紙張大小只影響實際公分數不影響框的形狀
    var r = 1/Math.SQRT2;
    return $('#cropOrientation').val()==='landscape' ? 1/r : r;
}
function openCropModal(pageNo){
    var p = (CUR_TPL.pages||[]).filter(function(x){ return x.page_no==pageNo; })[0];
    if (!p) return;
    CROP_PAGE_NO = pageNo;
    $('#cropPaperSize').val(p.paper_size || 'A4');
    $('#cropOrientation').val(parseFloat(p.width_pt) >= parseFloat(p.height_pt) ? 'landscape' : 'portrait');
    openMask('cropMask');
    var dispW = 600, dispH = Math.round(dispW * (p.height_pt / p.width_pt || 1.414));
    $('#cropCanvas').attr({width:dispW, height:dispH});
    if (CROP_CANVAS) { CROP_CANVAS.dispose(); CROP_CANVAS = null; }
    CROP_CANVAS = new fabric.Canvas('cropCanvas', {width:dispW, height:dispH, selection:false});
    var fileUrl = API + '?action=template_file&id=' + CUR_TPL.id;
    renderDocPages(CUR_TPL.file_type, fileUrl, [p], function(pageNo2, src){
        fabric.Image.fromURL(src, function(img){
            img.set({left:0, top:0, scaleX:dispW/img.width, scaleY:dispH/img.height, selectable:false, evented:false});
            CROP_CANVAS.setBackgroundImage(img, CROP_CANVAS.renderAll.bind(CROP_CANVAS));
            addCropRect();
        }, {crossOrigin:'anonymous'});
    });
}
function addCropRect(){
    if (CROP_RECT) { CROP_CANVAS.remove(CROP_RECT); CROP_RECT = null; }
    var cv = CROP_CANVAS, ratio = cropRatio();
    var w = cv.width * 0.8, h = w / ratio;
    if (h > cv.height * 0.9) { h = cv.height * 0.9; w = h * ratio; }
    var rect = new fabric.Rect({
        left:(cv.width-w)/2, top:(cv.height-h)/2, width:w, height:h,
        fill:'rgba(240,162,75,.15)', stroke:'#d98a33', strokeWidth:2, lockRotation:true, hasRotatingPoint:false,
    });
    rect.on('scaling', function(){
        var newW = rect.width * rect.scaleX;
        rect.set({scaleY: (newW/ratio) / rect.height});
    });
    cv.add(rect); cv.setActiveObject(rect); cv.renderAll();
    CROP_RECT = rect;
}
$('#cropPaperSize, #cropOrientation').on('change', function(){ if (CROP_CANVAS && CROP_RECT) addCropRect(); });
function confirmCrop(){
    if (!CROP_CANVAS || !CROP_RECT) return;
    if (!confirm('確定套用此裁切框？會清空這一頁已框選的位置。')) return;
    var cv = CROP_CANVAS, rect = CROP_RECT;
    var cx = rect.left / cv.width, cy = rect.top / cv.height;
    var cw = (rect.width * rect.scaleX) / cv.width, ch = (rect.height * rect.scaleY) / cv.height;
    var paper = $('#cropPaperSize').val(), orient = $('#cropOrientation').val();
    var mm = paper === 'A4' ? [210,297] : [297,420];
    var wMm = orient === 'landscape' ? Math.max(mm[0],mm[1]) : Math.min(mm[0],mm[1]);
    var hMm = orient === 'landscape' ? Math.min(mm[0],mm[1]) : Math.max(mm[0],mm[1]);
    var p = (CUR_TPL.pages||[]).filter(function(x){ return x.page_no==CROP_PAGE_NO; })[0];
    p.paper_size = paper; p.crop_x = cx; p.crop_y = cy; p.crop_w = cw; p.crop_h = ch;
    p.width_pt = wMm/25.4*72; p.height_pt = hMm/25.4*72;
    $.post(API, {action:'field_delete_page', csrf:META.csrf, template_id:CUR_TPL.id, page_no:CROP_PAGE_NO}, function(){
        $.post(API, {action:'pages_save', csrf:META.csrf, template_id:CUR_TPL.id, pages:JSON.stringify(CUR_TPL.pages)}, function(res){
            if (!res.ok){ alert(res.error||'裁切失敗'); return; }
            CUR_TPL.pages = res.pages;
            $.getJSON(API, {action:'template_get', id:CUR_TPL.id}, function(res2){
                CUR_TPL.fields = res2.template.fields;
                closeMask('cropMask');
                buildPageCanvases();
            });
        }, 'json');
    }, 'json');
}

/* -------- 框選工作區：Fabric.js 座標拖放 -------- */
function fieldMinFrac(page){
    var schema = CUR_TPL.stamp_tpl ? CUR_TPL.stamp_tpl.schema : null;
    var mmEdgeW, mmEdgeH;
    if (schema && schema.size) {
        var sizePx = Math.min(600, Math.max(24, +schema.size));
        var ratio = Math.min(3, Math.max(0.3, +schema.ratio || 1));
        mmEdgeW = sizePx/96*25.4; mmEdgeH = sizePx*ratio/96*25.4;
    } else {
        mmEdgeW = mmEdgeH = 91/96*25.4;
    }
    var widthMm = (page.width_pt||0)/72*25.4, heightMm = (page.height_pt||0)/72*25.4;
    return {min_w: widthMm>0 ? mmEdgeW/widthMm : 0.05, min_h: heightMm>0 ? mmEdgeH/heightMm : 0.05};
}
function renderLabelList(){
    var placed = {};
    (CUR_TPL.fields||[]).forEach(function(f){ placed[f.stage_signer_id+'_'+f.box_type] = true; });
    var h = '';
    STAGES.forEach(function(s, si){
        (s.signers||[]).forEach(function(sg, gi){
            if (!sg.id) return; // 尚未儲存階段設定，還沒有真正的槽位id可框選
            ['stamp','reply'].forEach(function(bt){
                var isPlaced = placed[sg.id+'_'+bt];
                var text = '第'+(si+1)+'關-'+(sg.label||SIGNER_MODE_LABEL[sg.mode])+'('+(bt==='stamp'?'圖章框':'回覆框')+')';
                h += '<div class="fsd-label type-'+bt+'" draggable="true" data-ssid="'+sg.id+'" data-boxtype="'+bt+'">'+esc(text)
                   + (isPlaced ? '<span class="placed"><i class="fa fa-check"></i></span>' : '') + '</div>';
            });
        });
    });
    $('#labelList').html(h || '<p style="padding:8px;color:#8a6d45;font-size:12px;">請先儲存階段設定</p>');
}
function buildPageCanvases(){
    CANVASES = {};
    var pages = CUR_TPL.pages || [{page_no:1, width_pt:595, height_pt:842}];
    var h = '';
    pages.forEach(function(p){
        h += '<div class="fsd-page-wrap"><div class="pno">第 '+p.page_no+' 頁'
           + (p.paper_size ? ' <span style="color:#3f8a3f;">['+p.paper_size+'已裁切]</span>' : '')
           + ' <button type="button" onclick="rotatePage('+p.page_no+')" style="height:20px;font-size:11px;padding:0 6px;border:1px solid #D8BE93;background:#fff;border-radius:3px;cursor:pointer;"><i class="fa fa-rotate-right"></i> 旋轉90°</button>'
           + ' <button type="button" onclick="openCropModal('+p.page_no+')" style="height:20px;font-size:11px;padding:0 6px;border:1px solid #D8BE93;background:#fff;border-radius:3px;cursor:pointer;"><i class="fa fa-crop"></i> A4/A3裁切</button>'
           + '</div><canvas id="pgcv_'+p.page_no+'"></canvas></div>';
    });
    $('#pageGrid').html(h);
    var fileUrl = API + '?action=template_file&id=' + CUR_TPL.id;
    pages.forEach(function(p){
        var dispW = 480, dispH = Math.round(dispW * (p.height_pt / p.width_pt || 1.414));
        var cv = new fabric.Canvas('pgcv_'+p.page_no, {width:dispW, height:dispH, selection:false});
        CANVASES[p.page_no] = cv;
        renderDocPages(CUR_TPL.file_type, fileUrl, [p], function(pageNo, bgSrc){
            fabric.Image.fromURL(bgSrc, function(img){
                img.set({left:0, top:0, scaleX:dispW/img.width, scaleY:dispH/img.height, selectable:false, evented:false});
                cv.setBackgroundImage(img, cv.renderAll.bind(cv));
            }, {crossOrigin:'anonymous'});
        });
        cv.on('selection:created', function(e){ SELECTED_OBJ = {canvas:cv, obj:e.selected[0]}; });
        cv.on('selection:updated', function(e){ SELECTED_OBJ = {canvas:cv, obj:e.selected[0]}; });
        cv.on('selection:cleared', function(){ SELECTED_OBJ = null; });
        cv.on('object:modified', function(e){ saveFieldPosition(p.page_no, e.target); });

        var wrapEl = cv.upperCanvasEl;
        wrapEl.addEventListener('dragover', function(ev){ ev.preventDefault(); });
        wrapEl.addEventListener('drop', function(ev){
            ev.preventDefault();
            var data = ev.dataTransfer.getData('text/plain');
            if (!data) return;
            var d = JSON.parse(data);
            var rect = wrapEl.getBoundingClientRect();
            var xFrac = (ev.clientX - rect.left) / dispW, yFrac = (ev.clientY - rect.top) / dispH;
            var minFrac = fieldMinFrac(p);
            var wFrac = d.boxtype === 'stamp' ? Math.max(minFrac.min_w + 0.02, 0.12) : 0.26;
            var hFrac = d.boxtype === 'stamp' ? Math.max(minFrac.min_h + 0.02, 0.09) : 0.07;
            xFrac = Math.max(0, Math.min(1-wFrac, xFrac - wFrac/2));
            yFrac = Math.max(0, Math.min(1-hFrac, yFrac - hFrac/2));
            var g = addFieldBox(p.page_no, d.ssid, d.boxtype, xFrac, yFrac, wFrac, hFrac, 0);
            saveFieldPosition(p.page_no, g);
        });
    });
    // 載入既有框選
    (CUR_TPL.fields||[]).forEach(function(f){
        addFieldBox(f.page_no, f.stage_signer_id, f.box_type, f.x, f.y, f.w, f.h, f.id);
    });
    renderLabelList();
}
$(document).on('dragstart', '.fsd-label', function(e){
    var data = JSON.stringify({ssid:$(this).data('ssid'), boxtype:$(this).data('boxtype')});
    e.originalEvent.dataTransfer.setData('text/plain', data);
});
function addFieldBox(pageNo, ssid, boxType, xFrac, yFrac, wFrac, hFrac, fieldId){
    var cv = CANVASES[pageNo];
    if (!cv) return;
    var color = boxType === 'stamp' ? '#F0A24B' : '#8A5A2B';
    var label = boxType === 'stamp' ? '章' : '覆';
    var rect = new fabric.Rect({originX:'left', originY:'top', fill:color, opacity:0.32, stroke:color, strokeWidth:1.5});
    var text = new fabric.Text(label, {fontSize:13, fill:'#5b3a1e', originX:'center', originY:'center'});
    var group = new fabric.Group([rect, text], {
        left: xFrac*cv.width, top: yFrac*cv.height, width: wFrac*cv.width, height: hFrac*cv.height,
        lockRotation:true, hasRotatingPoint:false,
    });
    text.set({left: (wFrac*cv.width)/2, top: (hFrac*cv.height)/2});
    group.fieldId = fieldId||0; group.ssid = ssid; group.boxType = boxType; group.pageNo = pageNo;
    cv.add(group);
    return group;
}
function saveFieldPosition(pageNo, obj){
    if (!obj || obj.pageNo === undefined) return;
    var cv = CANVASES[pageNo];
    var xFrac = obj.left / cv.width, yFrac = obj.top / cv.height;
    var wFrac = (obj.width * obj.scaleX) / cv.width, hFrac = (obj.height * obj.scaleY) / cv.height;
    var field = {id:obj.fieldId||0, stage_signer_id:obj.ssid, box_type:obj.boxType, page_no:pageNo, x:xFrac, y:yFrac, w:wFrac, h:hFrac};
    $.post(API, {action:'field_save', csrf:META.csrf, template_id:CUR_TPL.id, field:JSON.stringify(field)}, function(res){
        if (!res.ok){ alert(res.error||'儲存失敗，請調整後再試一次'); return; }
        obj.fieldId = res.id; CUR_TPL.fields = res.fields;
        renderLabelList();
    }, 'json');
}
function deleteSelectedField(){
    if (!SELECTED_OBJ){ alert('請先點選一個框'); return; }
    var obj = SELECTED_OBJ.obj, cv = SELECTED_OBJ.canvas;
    if (!obj.fieldId){ cv.remove(obj); SELECTED_OBJ = null; return; }
    $.post(API, {action:'field_delete', csrf:META.csrf, template_id:CUR_TPL.id, field_id:obj.fieldId}, function(res){
        if (!res.ok){ alert(res.error||'刪除失敗'); return; }
        cv.remove(obj); SELECTED_OBJ = null; CUR_TPL.fields = res.fields;
        renderLabelList();
    }, 'json');
}

loadMeta(loadTemplates);
</script>
</body>
</html>
