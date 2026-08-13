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
            <h2 style="margin:6px 0;">人資職務表單設定 <small style="color:#8a6d45;">職位範本／機型量具白名單／部門表單資格／AS文件綁定</small></h2>
            <a href="hr_position_forms.php" style="margin-left:8px;">← 回操作頁</a>
            <button id="btnRoleSetting" style="display:none;margin-left:8px;height:30px;font-size:13px;padding:0 12px;border:1px solid #D8BE93;border-radius:4px;background:#fff;color:#5b3a1e;cursor:pointer;"><i class="fa fa-users"></i> 角色設定</button>
            <button class="page-help-btn" id="btnPageHelp"><i class="fa fa-question-circle"></i> 使用說明</button>
        </div>
        <div class="clearfix"></div>

        <ul class="nav nav-tabs nav-hf" id="hfTabs">
            <li class="active"><a href="#" data-type="job_desc">職務說明書範本</a></li>
            <li><a href="#" data-type="skill_assess">技能鑑定表範本</a></li>
            <li><a href="#" data-type="competency">職能鑑定表範本</a></li>
            <li><a href="#" data-type="whitelist">機型/量具白名單</a></li>
            <li><a href="#" data-type="deptset">部門表單資格</a></li>
            <li><a href="#" data-type="misc">員工編號前綴</a></li>
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
            <p style="font-size:12.5px;color:#8a6d45;">從既有機台主檔（machine_list，比照 process_schedule_NOW.php「機台設定」頁的欄位認定：機型=machine_model、機台編號=asset_no，不使用現場編號field_no）與量測儀器校驗量具主檔（qc_tool）勾選要開放給「專業技能鑑定考核表」選用的機型/量具；機台名稱固定取自主檔，不開放手動改字。</p>
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

        <div class="hf-tabpane" id="pane-misc" data-type="misc">
            <p style="font-size:12.5px;color:#8a6d45;">全站唯一設定值：三張表單列印/檢視顯示「員工編號」時（本系統慣例＝員工帳號 id 本身），一律加上此前綴一起顯示；未設定則不加前綴。</p>
            <label>員工編號前綴</label>
            <input type="text" id="userNoPrefix" style="max-width:200px;" placeholder="例如 EG-">
            <div style="margin-top:8px;"><button class="btn-warm" onclick="userNoPrefixSave()">儲存</button> <span id="userNoPrefixMsg" style="font-size:12.5px;color:#3f9142;"></span></div>

            <p style="font-size:12.5px;color:#8a6d45;margin-top:20px;">技能鑑定表／職能鑑定表的「確認人」解析：優先找員工所在部門逐層往上比對，看哪個部門掛著這個職位的人（排除本人），例如生產1廠員工找不到就往上找生產部/生產課掛這個職位的人；整條路徑都找不到才退回系統既有的直屬主管邏輯。留空＝完全比照原本邏輯不啟用這套。</p>
            <label>確認人（課長）對應職位</label>
            <select id="confirmerPosition" style="max-width:200px;"></select>
            <div style="margin-top:8px;"><button class="btn-warm" onclick="confirmerPositionSave()">儲存</button> <span id="confirmerPositionMsg" style="font-size:12.5px;color:#3f9142;"></span></div>
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
        <label>適用部門×職位（部門選「不限部門」＝該職位不論哪個部門都適用；一次可勾多個職位一起加入）</label>
        <div style="display:flex;gap:8px;align-items:flex-start;flex-wrap:wrap;">
            <select id="scopeNewDept" style="width:170px;"></select>
            <div style="flex:1;min-width:220px;border:1px solid #D8BE93;border-radius:4px;padding:6px;max-height:150px;overflow-y:auto;" id="scopeNewPosList"></div>
        </div>
        <div style="margin:6px 0;"><button type="button" class="hf-btn-sm" onclick="scopeAddSelected()">+ 新增選取的職位</button></div>
        <table class="itm-tbl" id="scopeTbl"><thead><tr><th>部門</th><th>職位</th><th style="width:50px;"></th></tr></thead><tbody id="scopeBody"></tbody></table>

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

<!-- 多選 picker modal（AS文件/KPI項目共用，選定後把結果附加進目標文字欄） -->
<div class="hf-mask" id="multiPickMask"><div class="hf-modal" style="max-width:600px;">
    <div class="m-head"><span id="multiPickTitle">選擇</span><span class="m-close" onclick="closeMask('multiPickMask')">✕</span></div>
    <div class="m-body">
        <label>輸入關鍵字篩選</label><input type="text" id="multiPickFilter" oninput="multiPickFilterList(this.value)">
        <div style="max-height:320px;overflow-y:auto;border:1px solid #D8BE93;border-radius:6px;padding:6px;" id="multiPickList"></div>
    </div>
    <div class="m-foot"><button class="b-cancel" onclick="closeMask('multiPickMask')">取消</button><button class="b-ok" onclick="multiPickConfirm()">加入所選</button></div>
</div></div>

<!-- 角色設定 modal（管理員；定義本模組角色能看到/做什麼，指派給誰在「使用者權限設定」頁） -->
<div class="hf-mask" id="roleSetMask"><div class="hf-modal" style="max-width:760px;">
    <div class="m-head"><span>角色設定</span><span class="m-close" onclick="closeMask('roleSetMask')">✕</span></div>
    <div class="m-body">
        <p style="font-size:12px;color:#8a6d45;">左邊選或新增角色 → 右邊改名稱、勾這個角色能看到什麼／能做什麼。「誰擁有這個角色」在<a href="../user/user_permissions.php" target="_blank">人員權限設定頁</a>設定，這裡只定義角色內容。</p>
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
                    <div style="font-size:12px;font-weight:bold;color:#8A5A2B;margin:10px 0 4px;">功能（看得到/能做什麼）</div>
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
<div class="hf-mask" id="helpUseMask"><div class="hf-modal" style="max-width:760px;">
    <div class="m-head"><span>使用說明 — 人資職務表單設定</span><span class="m-close" onclick="closeMask('helpUseMask')">✕</span></div>
    <div class="m-body help-doc" style="font-size:13px;color:#5b3a1e;line-height:1.8;">
        <h4>功能說明</h4>
        管理員在這裡設定「職位範本」：建立表單時系統會依員工的部門×職位比對範本，自動帶入內容（職務說明書的工作職責表、員工職能鑑定表的職能項目清單）或適用機型清單（專業技能鑑定考核表）。一個範本可以綁定多筆部門×職位。
        <h4>操作步驟</h4>
        <b>①新增/編輯範本</b>：填範本名稱、綁定適用的部門×職位（選一個部門後可一次勾選多個職位加入，部門選「不限部門」代表該職位不論哪個部門都適用）、編輯內容（職務說明書填4欄工作職責表；員工職能鑑定表填職能項目清單；專業技能鑑定考核表勾選適用機型，可用「全選/取消全選」快速操作，需先在「機型/量具白名單」建立好白名單）。<br>
        <b>②機型/量具白名單</b>：從既有機台主檔（machine_list，機型/機台編號比照process_schedule_NOW.php機台設定頁欄位認定，機台名稱固定取自主檔不可手動改字）與量測儀器校驗的量具主檔勾選，儲存後才能在範本裡勾選。<br>
        <b>③部門表單資格</b>：勾選哪些部門的人要產生技能鑑定表/職能鑑定表，職務說明書不受此限制、全員都會有。<br>
        <b>④AS文件編號綁定</b>：三張表單各自獨立綁定，在各自分頁右上角按鈕設定。<br>
        <b>⑤員工編號前綴</b>：全站唯一設定值，三張表單顯示「員工編號」時統一套用此前綴。
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
    $.post(API, data, function(res){ cb(res); }, 'json').fail(function(xhr){
        var msg = (xhr.responseJSON && xhr.responseJSON.error) ? xhr.responseJSON.error
                : (function(){ try { return JSON.parse(xhr.responseText).error; } catch(e){ return null; } })();
        cb({ok:false, error: msg || ('連線失敗（HTTP '+xhr.status+'）')});
    });
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
    if (t === 'misc') { loadUserNoPrefix(); loadConfirmerPosition(); }
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
/** 加一列已定案的部門×職位（唯讀顯示＋刪除鈕），deptId 為 0/空＝不限部門。同一組合已存在就不重複加入。 */
function scopeAddRow(deptId, posId){
    deptId = deptId || '';
    var exists = $('#scopeBody tr').filter(function(){ return String($(this).data('dept'))===String(deptId) && String($(this).data('pos'))===String(posId); }).length;
    if (exists) return;
    var deptName = deptId ? (((META.departments||[]).find(function(d){ return String(d.id)===String(deptId); })||{}).name || '') : '不限部門';
    var posName = ((META.positions||[]).find(function(p){ return String(p.id)===String(posId); })||{}).name || posId;
    $('#scopeBody').append('<tr data-dept="'+deptId+'" data-pos="'+posId+'"><td>'+esc(deptName)+'</td><td>'+esc(posName)+'</td><td><button class="hf-btn-sm" onclick="$(this).closest(\'tr\').remove()">刪除</button></td></tr>');
}
/** 初始化「選部門＋多選職位」的新增區塊。 */
function scopeInitPicker(){
    var deptOpts = '<option value="">不限部門</option>' + (META.departments||[]).map(function(d){ return '<option value="'+d.id+'">'+esc(d.name)+'</option>'; }).join('');
    $('#scopeNewDept').html(deptOpts);
    $('#scopeNewPosList').html((META.positions||[]).map(function(p){
        return '<label style="display:inline-block;width:48%;font-size:12.5px;"><input type="checkbox" class="scope-pos-ck" value="'+p.id+'"> '+esc(p.name)+'</label>';
    }).join(''));
}
function tplMachineCkAll(check){ $('#tplMachineList .tm-ck').prop('checked', check); }
function scopeAddSelected(){
    var deptId = $('#scopeNewDept').val();
    var posIds = $('.scope-pos-ck:checked').map(function(){ return $(this).val(); }).get();
    if (!posIds.length){ alert('請至少勾選一個職位'); return; }
    posIds.forEach(function(pid){ scopeAddRow(deptId, pid); });
    $('.scope-pos-ck').prop('checked', false);
}
function openTplModal(ft, id){
    TPL_TYPE = ft; TPL_ID = id;
    $('#tplTitle').text((id?'編輯':'新增')+'範本 — '+FORM_LABEL[ft]);
    $('#tplName').val('');
    $('#scopeBody').empty();
    scopeInitPicker();
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
            var html = '<label>適用機型（勾選；建立表單時系統會依這份清單自動展開每個機型各一筆）</label>'
                     + '<div style="margin-bottom:4px;"><button type="button" class="hf-btn-sm" onclick="tplMachineCkAll(true)">全選</button> <button type="button" class="hf-btn-sm" onclick="tplMachineCkAll(false)">取消全選</button></div>'
                     + '<div style="max-height:220px;overflow-y:auto;border:1px solid #D8BE93;border-radius:6px;padding:6px;" id="tplMachineList">'
                     + wl.map(function(w){ return '<label style="display:block;font-size:12.5px;"><input type="checkbox" class="tm-ck" value="'+w.id+'"> '+esc(w.display_name)+(w.machine_model?'（機型：'+esc(w.machine_model)+'）':'')+'</label>'; }).join('')
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
function jdTplRowHtml(d){
    d = d || {};
    return '<tr><td><textarea class="c-a">'+esc(d.summary||'')+'</textarea></td>'
         + '<td><textarea class="c-b">'+esc(d.process||'')+'</textarea><br>'
           + '<button type="button" class="hf-btn-sm" onclick="openAsDocPicker([\'二階\'],$(this).siblings(\'textarea\')[0])">選程序書(AS二階)</button></td>'
         + '<td><textarea class="c-c">'+esc(d.form_name||'')+'</textarea><br>'
           + '<button type="button" class="hf-btn-sm" onclick="openAsDocPicker([\'三階\',\'四階\'],$(this).siblings(\'textarea\')[0])">選表單(AS三/四階)</button></td>'
         + '<td><textarea class="c-d">'+esc(d.dpi||'')+'</textarea><br>'
           + '<button type="button" class="hf-btn-sm" onclick="openKpiPicker($(this).siblings(\'textarea\')[0])">選KPI標準</button></td></tr>';
}
function jdTplTableHtml(items){
    var rows = items && items.length ? items : [{data:{}}];
    var h = '<label>工作職責內容（「程序書」「表單」可從既有 AS 文件多選帶入編號+名稱，「產出表單名稱」欄仍可在選完後手動追加內容；「DPI 項目」可從 KPI 頁既有標準多選模糊搜尋帶入）</label>'
          + '<table class="itm-tbl"><thead><tr><th>工作摘要</th><th>工作相關程序書</th><th>產出表單名稱</th><th>DPI 項目</th></tr></thead>'
          + '<tbody id="tplItemsBody" data-eg-row-add="hfTplJdAdd" data-eg-row-del="hfTplJdDel">';
    rows.forEach(function(it){ h += jdTplRowHtml(it.data); });
    h += '</tbody></table><button class="hf-btn-sm" type="button" onclick="hfTplJdAdd()">+新增列</button> <button class="hf-btn-sm" type="button" onclick="hfTplJdDel()">-刪除末列</button>';
    return h;
}
function hfTplJdAdd(){ $('#tplItemsBody').append(jdTplRowHtml({})); }
function hfTplJdDel(){ var $r=$('#tplItemsBody tr'); if ($r.length>1) $r.last().remove(); }

/* ============================================================ AS文件/KPI 多選 picker（共用modal，選定後附加進目標textarea） ============================================================ */
var MULTI_PICK_TARGET = null, MULTI_PICK_ITEMS = [], MULTI_PICK_FORMAT = null;
function multiPickRender(items){
    $('#multiPickList').html(items.map(function(it, i){
        return '<label style="display:block;font-size:12.5px;padding:2px 0;" data-hay="'+esc(it._hay).toLowerCase()+'"><input type="checkbox" class="mp-ck" data-idx="'+i+'"> '+esc(it._label)+'</label>';
    }).join('') || '<span style="color:#8a6d45;">查無資料</span>');
}
function multiPickFilterList(kw){
    kw = (kw||'').toLowerCase();
    $('#multiPickList label').each(function(){ $(this).toggle(!kw || ($(this).data('hay')+'').indexOf(kw) >= 0); });
}
function multiPickConfirm(){
    var picked = $('.mp-ck:checked').map(function(){ return MULTI_PICK_ITEMS[$(this).data('idx')]; }).get();
    if (!picked.length){ closeMask('multiPickMask'); return; }
    var lines = picked.map(MULTI_PICK_FORMAT).join('\n');
    var cur = $(MULTI_PICK_TARGET).val();
    $(MULTI_PICK_TARGET).val(cur ? (cur + '\n' + lines) : lines);
    closeMask('multiPickMask');
}
/** 從 AS 文件管理現成 API 依「階」查詢（二階=程序書、三/四階=表單），選定後帶出「編號 名稱」不含版次。 */
function openAsDocPicker(levels, targetTextarea){
    MULTI_PICK_TARGET = targetTextarea;
    MULTI_PICK_FORMAT = function(d){ return d.doc_no + ' ' + d.doc_name; };
    $('#multiPickTitle').text('選擇 AS 文件（'+levels.join('/')+'）');
    $('#multiPickFilter').val('');
    $('#multiPickList').html('<span style="color:#8a6d45;">載入中…</span>');
    openMask('multiPickMask');
    var calls = levels.map(function(lv){
        return $.getJSON('../../src/store/AS_Document_API.php', {action:'list_documents', level:lv});
    });
    $.when.apply($, calls).always(function(){
        // AS_Document_API.php list_documents 回傳 {status:'success', data:[...]}；$.when 多筆呼叫時
        // 每筆結果會是 [data, textStatus, jqXHR] 包一層陣列，單筆呼叫時 arguments 本身就是那組，要分開處理。
        var results = calls.length > 1 ? Array.prototype.slice.call(arguments) : [arguments];
        var docs = [];
        results.forEach(function(r){
            var res = r[0];
            if (res && res.status === 'success' && res.data) docs = docs.concat(res.data);
        });
        MULTI_PICK_ITEMS = docs.map(function(d){ return {doc_no:d.doc_no, doc_name:d.doc_name, _label:d.doc_no+' '+d.doc_name, _hay:d.doc_no+' '+d.doc_name}; });
        multiPickRender(MULTI_PICK_ITEMS);
    });
}
var KPI_INDICATORS_CACHE = null;
function openKpiPicker(targetTextarea){
    MULTI_PICK_TARGET = targetTextarea;
    MULTI_PICK_FORMAT = function(d){ return d.name + '（' + (d.stat_desc||'') + '）'; };
    $('#multiPickTitle').text('選擇 KPI 標準與計算方式');
    $('#multiPickFilter').val('');
    if (KPI_INDICATORS_CACHE) { MULTI_PICK_ITEMS = KPI_INDICATORS_CACHE; multiPickRender(MULTI_PICK_ITEMS); openMask('multiPickMask'); return; }
    $('#multiPickList').html('<span style="color:#8a6d45;">載入中…</span>');
    openMask('multiPickMask');
    $.getJSON(API, {action:'kpi_indicator_list'}, function(res){
        var rows = res.ok ? (res.indicators||[]) : [];
        KPI_INDICATORS_CACHE = rows.map(function(d){ return {name:d.name, stat_desc:d.stat_desc, _label:d.name+'（'+(d.stat_desc||'')+'）', _hay:d.name+' '+(d.stat_desc||'')}; });
        MULTI_PICK_ITEMS = KPI_INDICATORS_CACHE;
        multiPickRender(MULTI_PICK_ITEMS);
    });
}
function cpTplTableHtml(items){
    var rows = items && items.length ? items : [{data:{}}];
    var h = '<label>職能項目清單（可手動增設項目，也可從已建立的「專業技能鑑定考核表範本」適用機型清單自動帶入）</label>'
          + '<div style="margin-bottom:6px;"><select id="cpFromSaTpl" style="max-width:260px;"></select> <button type="button" class="hf-btn-sm" onclick="hfCpFillFromSaTpl()">從此技能鑑定表範本帶入項目</button></div>'
          + '<table class="itm-tbl"><thead><tr><th style="width:40px;">編號</th><th>項目名稱</th></tr></thead>'
          + '<tbody id="tplItemsBody" data-eg-row-add="hfTplCpAdd" data-eg-row-del="hfTplCpDel">';
    rows.forEach(function(it,i){ var d=it.data||{}; h += '<tr><td style="text-align:center;">'+(i+1)+'</td><td><input type="text" class="c-name" value="'+esc(d.skill_name||'')+'"></td></tr>'; });
    h += '</tbody></table><button class="hf-btn-sm" type="button" onclick="hfTplCpAdd()">+新增列</button> <button class="hf-btn-sm" type="button" onclick="hfTplCpDel()">-刪除末列</button>';
    $.getJSON(API, {action:'template_list', form_type:'skill_assess'}, function(res){
        var tpls = res.ok ? (res.templates||[]) : [];
        var opts = tpls.length ? tpls.map(function(t){ return '<option value="'+t.id+'">'+esc(t.name)+'</option>'; }).join('')
                                : '<option value="">（尚未建立任何專業技能鑑定考核表範本，請先建立）</option>';
        $('#cpFromSaTpl').html(opts);
    });
    return h;
}
function hfTplCpAdd(){ var n=$('#tplItemsBody tr').length+1; $('#tplItemsBody').append('<tr><td style="text-align:center;">'+n+'</td><td><input type="text" class="c-name"></td></tr>'); }
function hfTplCpDel(){ var $r=$('#tplItemsBody tr'); if ($r.length>1) $r.last().remove(); }
/** 職能鑑定表範本的職能項目清單自動從技能鑑定表範本的適用機型清單帶入（使用者明確要求）；附加在既有列後面，不覆蓋已手動填的內容。 */
function hfCpFillFromSaTpl(){
    var saId = $('#cpFromSaTpl').val();
    if (!saId){ alert('請先建立此職位的「專業技能鑑定考核表範本」（設定適用機型），才能自動帶入職能項目'); return; }
    $.getJSON(API, {action:'template_get', id:saId}, function(res){
        if (!res.ok){ alert(res.error||'載入失敗'); return; }
        var machines = res.template.machines || [];
        if (!machines.length){ alert('此技能鑑定表範本尚未設定適用機型'); return; }
        // 若唯一一列是空白列（初始狀態），先清掉再帶入，避免留一列空白
        if ($('#tplItemsBody tr').length === 1 && !$('#tplItemsBody .c-name').val()) $('#tplItemsBody').empty();
        machines.forEach(function(m){
            var n = $('#tplItemsBody tr').length + 1;
            $('#tplItemsBody').append('<tr><td style="text-align:center;">'+n+'</td><td><input type="text" class="c-name" value="'+esc(m.display_name)+'"></td></tr>');
        });
    });
}

function tplSave(){
    var name = $('#tplName').val().trim();
    if (!name){ alert('請輸入範本名稱'); return; }
    var scope = $('#scopeBody tr').map(function(){
        var $t = $(this);
        return {department_id: $t.data('dept') || null, position_id: $t.data('pos')};
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
            // 機台名稱一律取自 machine_list，不開放手動改字；機型/機台編號一併秀出來方便對照(不可用現場編號)
            var meta = type === 'machine' ? ('　機型：'+esc(r.machine_model||'-')+'　機台編號：'+esc(r.asset_no||'-')) : '';
            html += '<div class="wl-row" data-hay="'+esc(r.display_name+' '+(r.machine_model||'')+' '+(r.asset_no||'')).toLowerCase()+'"><label style="flex:1;"><input type="checkbox" class="wl-ck" data-type="'+type+'" data-id="'+r.source_id+'"'+(r.checked?' checked':'')+'> '+esc(r.display_name)+'<span style="color:#8a6d45;font-size:11px;">'+meta+'</span></label></div>';
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
        entries.push({source_type:$(this).data('type'), source_id:$(this).data('id')});
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

/* ============================================================ 員工編號前綴 ============================================================ */
function loadUserNoPrefix(){
    $.getJSON(API, {action:'user_no_prefix_get'}, function(res){
        if (res.ok) $('#userNoPrefix').val(res.prefix||'');
    });
}
function userNoPrefixSave(){
    ajaxPost('user_no_prefix_save', {prefix:$('#userNoPrefix').val()}, function(res){
        if (!res.ok){ alert(res.error||'儲存失敗'); return; }
        $('#userNoPrefixMsg').text('已儲存（'+new Date().toLocaleTimeString()+'）');
    });
}

/* ============================================================ 確認人（課長）對應職位 ============================================================ */
function loadConfirmerPosition(){
    var opts = '<option value="0">（未設定，沿用系統原邏輯）</option>' + (META.positions||[]).map(function(p){ return '<option value="'+p.id+'">'+esc(p.name)+'</option>'; }).join('');
    $('#confirmerPosition').html(opts);
    $.getJSON(API, {action:'confirmer_position_get'}, function(res){
        if (res.ok) $('#confirmerPosition').val(res.position_id||0);
    });
}
function confirmerPositionSave(){
    ajaxPost('confirmer_position_save', {position_id:$('#confirmerPosition').val()}, function(res){
        if (!res.ok){ alert(res.error||'儲存失敗'); return; }
        $('#confirmerPositionMsg').text('已儲存（'+new Date().toLocaleTimeString()+'）');
    });
}

/* ============================================================ 角色設定（Roles_API） ============================================================ */
var RAPI = '../../src/store/Roles_API.php';
var ROLES = [], CURROLE = 0;
$('#btnRoleSetting').on('click', function(){ openMask('roleSetMask'); loadRoles(); });
function loadRoles(then){
    $.getJSON(RAPI, {action:'get_roles', module:'hr_form'}, function(res){
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
    var oh = '';
    (META.features||[]).forEach(function(f){
        oh += '<label class="role-feat" style="display:block;font-weight:normal;padding:2px 0;font-size:12.5px;"><input type="checkbox" class="featcb" value="'+esc(f.code)+'"> '+esc(f.label)+'</label>';
    });
    $('#featOp').html(oh);
    $.getJSON(RAPI, {action:'get_role_features', role_id:id}, function(res){
        var has = res.data || [];
        $('.featcb').each(function(){ $(this).prop('checked', has.indexOf(this.value)>-1 || has.indexOf('all')>-1); });
    });
}
$(document).on('click', '#roleList .role-item', function(){ selRole($(this).data('id')); });
$('#btnRoleAdd').on('click', function(){
    var n = prompt('新角色名稱：');
    if (!n || !$.trim(n)) return;
    $.post(RAPI, {action:'save_role', role_name:$.trim(n), module:'hr_form'}, function(r){
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

loadMeta(function(){ loadTplList('job_desc'); if (META.perms.canAdmin) $('#btnRoleSetting').show(); });
</script>
</body>
</html>
