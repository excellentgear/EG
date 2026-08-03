<?php
/**
 * 外來文件清單（AS9100 外來文件管制）
 * 附件標籤勾「列入外來文件」者（quotation_file_categories.is_external_doc）依料號/報價附件彙整成清單。
 * 欄位：客戶、料號、文件名稱、外來文件類別、發行日期(上傳日)、發行單位(SALES_SETTING 業務單位)。
 * 可切換「只看有訂單綁定的料號 / 所有有附件的料號」、指定客戶、年度；列印依客戶分組、右下角帶綁定的 AS 文件編號。
 * 資料一律走 src/store/ExternalDoc_API.php。
 */
session_start();
if (!isset($_SESSION['userName'])) {
    $_SESSION['lastpage'] = "../../views/Sales/external_doc_list.php";
    header("Location:../../index.php");
    exit;
}
include_once '../../src/common/_config.php';
include_once '../../src/common/DBConnection.php';
include_once '../../src/common/role_features_helper.php';

$db  = (new DBConnection())->getPDO();
$uid = (int)($_SESSION['id'] ?? 0);

// 權限：頁面 ACRUD 矩陣 OR external_doc 模組角色（與 ExternalDoc_API 同邏輯）
$extFeatures    = $uid ? rf_load_user_features_override($db, $uid, 'external_doc') : [];
$extIsRoleAdmin = in_array('all', $extFeatures, true);
$extPagePerm = '';
try {
    $st = $db->prepare("SELECT page_id, group_id FROM system_module_pages WHERE page_url LIKE '%views/Sales/external_doc_list.php' LIMIT 1");
    $st->execute();
    $pg = $st->fetch(PDO::FETCH_ASSOC);
    if ($pg) {
        $st = $db->prepare("SELECT permission FROM user_module_permissions WHERE user_id=? AND scope='page' AND module_code=?");
        $st->execute([$uid, $pg['page_id']]);
        $perms = $st->fetchAll(PDO::FETCH_COLUMN);
        if (empty($perms) && !empty($pg['group_id'])) {
            $st = $db->prepare("SELECT module_code FROM system_modules WHERE group_id=? LIMIT 1");
            $st->execute([$pg['group_id']]);
            $gCode = $st->fetchColumn();
            if ($gCode) {
                $st = $db->prepare("SELECT permission FROM user_module_permissions WHERE user_id=? AND scope='group' AND module_code=?");
                $st->execute([$uid, $gCode]);
                $perms = $st->fetchAll(PDO::FETCH_COLUMN);
            }
        }
        $chars = [];
        foreach ($perms as $p) { $chars = array_merge($chars, str_split($p)); }
        $extPagePerm = implode('', array_unique($chars));
    }
} catch (Exception $e) {}
$canView   = $extIsRoleAdmin || strpos($extPagePerm, 'A') !== false || strpos($extPagePerm, 'R') !== false
           || in_array('extdoc_view', $extFeatures, true);
$canManage = $extIsRoleAdmin || strpos($extPagePerm, 'A') !== false || in_array('extdoc_manage', $extFeatures, true);
$roleLabel = $extIsRoleAdmin ? '管理者' : ($canManage ? '外來文件管理' : ($canView ? '外來文件檢閱' : '無權限'));
?>
<!DOCTYPE html>
<html lang="zh-Hant">
<head>
    <meta charset="utf-8">
    <meta http-equiv="X-UA-Compatible" content="IE=edge">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>外來文件清單</title>
    <link href="../../resource/css/bootstrap.css" rel="stylesheet">
    <link href="../../resource/css/font-awesome.css" rel="stylesheet">
    <link href="../../resource/css/nprogress.css" rel="stylesheet">
    <link href="../../resource/css/custom.css" rel="stylesheet">
    <style>
        #sidebar-menu { visibility: hidden; }
        .right_col .page-title { margin:8px 0 4px; overflow:hidden; }
        .page-help-btn { height:30px; font-size:13px; padding:0 12px; border:1px solid #d98a33; border-radius:15px;
            background:#F0A24B; color:#fff; cursor:pointer; }
        .page-help-btn:hover { background:#d98a33; }
        @media print { .page-help-btn { display:none !important; } }
        .help-doc { font-size:13px; color:#5b3a1e; line-height:1.75; }
        .help-doc h4 { color:#8A5A2B; border-bottom:2px solid #F7E0BD; padding-bottom:3px; margin:14px 0 6px; font-size:15px; }
        .help-doc h4:first-child { margin-top:0; }
        .help-doc b { color:#8A5A2B; }
        .help-doc ul { margin:4px 0 8px; padding-left:20px; }
        .help-doc li { margin:2px 0; }
        .help-doc .tip { background:#FFF7E8; border:1px dashed #F0A24B; border-radius:6px; padding:6px 10px; margin:6px 0; }
        .xd-toolbar { display:flex; flex-wrap:wrap; gap:6px 10px; align-items:center; clear:both;
            border:1.5px solid #E8D5B5; border-radius:8px; padding:8px 10px; margin-bottom:10px; background:#FDF8EF; }
        .xd-toolbar label { margin:0; font-size:13px; color:#5b3a1e; }
        .xd-toolbar select, .xd-toolbar button {
            height:30px; font-size:13px; line-height:1; padding:0 10px; border:1px solid #D8BE93;
            border-radius:4px; background:#fff; color:#5b3a1e; cursor:pointer; }
        .xd-toolbar button:hover { background:#F7E0BD; }
        .xd-toolbar .btn-warm { background:#F0A24B; color:#fff; border-color:#d98a33; }
        .xd-toolbar .btn-warm:hover { background:#d98a33; }
        .xd-role-badge { margin-left:auto; font-size:13px; color:#5b3a1e; background:#F7E0BD; border-radius:12px; padding:4px 12px; }
        .xd-role-badge .fa-question-circle { cursor:pointer; color:#b5762a; margin-left:5px; }
        .xd-mode { display:flex; border:1px solid #D8BE93; border-radius:4px; overflow:hidden; }
        .xd-mode button { border:none; border-radius:0; height:28px; }
        .xd-mode button.active { background:#F0A24B; color:#fff; }
        .xd-asdoc { font-size:12px; color:#8a6d45; margin-bottom:6px; }
        .xd-asdoc b { color:#8A5A2B; }
        .xd-pagebar { display:flex; justify-content:flex-end; align-items:center; gap:6px; margin-bottom:6px; font-size:13px; color:#5b3a1e; }
        .xd-pagebar select { height:26px; font-size:12px; border:1px solid #D8BE93; border-radius:4px; }
        .xd-pagebar button { height:26px; font-size:12px; padding:0 8px; border:1px solid #D8BE93; border-radius:4px; background:#fff; color:#5b3a1e; cursor:pointer; }
        .xd-pagebar button:disabled { color:#c9bda9; cursor:default; }
        .xd-pagebar button.cur { background:#F0A24B; color:#fff; border-color:#d98a33; }
        .xd-table-wrap { overflow-x:auto; border:1px solid #E8D5B5; border-radius:6px; background:#fff; }
        table.xd-table { width:100%; border-collapse:collapse; font-size:13px; }
        table.xd-table th, table.xd-table td { border:1px solid #EADFC8; padding:5px 8px; text-align:center; }
        table.xd-table thead th { background:#F7E0BD; color:#5b3a1e; font-weight:bold; white-space:nowrap; }
        table.xd-table tbody tr:nth-child(even) { background:#FBF6EC; }
        table.xd-table tbody tr:hover { background:#FBF0DD; }
        table.xd-table td.t-left { text-align:left; }
        .src-pill { display:inline-block; font-size:11px; border-radius:10px; padding:1px 8px; }
        .src-part { background:#F7E0BD; color:#7a5217; }
        .src-quote { background:#FFF3E2; color:#C77C1A; border:1px solid #E4D3BC; }
        .cat-pill { display:inline-block; font-size:11px; border:1px solid rgba(122,82,23,.25);
            border-radius:10px; padding:1px 8px; margin:1px 2px; }
        .cat-btn { height:26px; font-size:12px; padding:0 12px; border:1px solid rgba(122,82,23,.3);
            border-radius:13px; cursor:pointer; opacity:.55; }
        .cat-btn.active { opacity:1; box-shadow:0 0 0 2px #8A5A2B inset; font-weight:bold; }
        .xd-tabs { display:flex; gap:4px; margin-bottom:8px; border-bottom:2px solid #E8D5B5; }
        .xd-tab { border:1px solid #E8D5B5; border-bottom:none; background:#FBF3E5; color:#8a6d45; cursor:pointer;
            padding:7px 16px; font-size:14px; border-radius:6px 6px 0 0; margin-bottom:-2px; }
        .xd-tab.active { background:#fff; color:#5b3a1e; font-weight:bold; border-bottom:2px solid #fff; }
        a.xd-doclink { color:#b5762a; text-decoration:underline; }
        a.xd-doclink:hover { color:#8A5A2B; }
        .xd-op { color:#b5762a; cursor:pointer; white-space:nowrap; }
        .xd-op:hover { color:#DD5138; text-decoration:underline; }
        .xd-note-edit { border:1px solid #D8BE93; border-radius:4px; padding:3px 6px; font-size:12px; width:95%; }
        .xd-noperm { margin:40px auto; max-width:520px; text-align:center; border:1.5px solid #E8D5B5; border-radius:10px;
            padding:30px; background:#FDF8EF; color:#5b3a1e; }
        .xd-mask { display:none; position:fixed; inset:0; background:rgba(60,40,20,.45); z-index:1050; }
        .xd-modal { background:#fff; border-radius:8px; max-width:560px; margin:36px auto; box-shadow:0 5px 25px rgba(0,0,0,.3);
            max-height:90vh; display:flex; flex-direction:column; }
        .xd-modal .m-head { background:#F7E0BD; color:#5b3a1e; font-weight:bold; padding:10px 15px; border-radius:8px 8px 0 0;
            display:flex; justify-content:space-between; }
        .xd-modal .m-head .m-close { cursor:pointer; color:#b5762a; }
        .xd-modal .m-body { padding:15px; overflow-y:auto; font-size:13px; color:#5b3a1e; }
        .xd-modal .m-body label { display:block; font-size:13px; color:#5b3a1e; margin:9px 0 3px; }
        .xd-modal .m-body input[type=text], .xd-modal .m-body select { width:100%; border:1px solid #D8BE93; border-radius:4px;
            padding:5px 8px; font-size:13px; box-sizing:border-box; }
        .xd-modal .m-foot { padding:10px 15px; border-top:1px solid #EADFC8; text-align:right; }
        .xd-modal .m-foot button { height:30px; padding:0 16px; border-radius:4px; font-size:13px; border:1px solid #d98a33; cursor:pointer; }
        .xd-modal .m-foot .b-ok { background:#F0A24B; color:#fff; }
        .xd-modal .m-foot .b-no { background:#fff; color:#5b3a1e; border-color:#D8BE93; margin-left:6px; }
    </style>
</head>
<body class="nav-sm">
<div class="container body">
<div class="main_container">
    <?php include '../partPage/sideAndTopBarMenu.html' ?>
    <div class="right_col" role="main">
        <div class="page-title" style="display:flex;align-items:center;flex-wrap:wrap;">
            <h2 style="margin:6px 0;">外來文件清單
                <small style="color:#8a6d45;">附件標籤勾選「列入外來文件」者自動彙整（AS9100 外來文件管制）</small></h2>
            <button id="btnPageHelp" class="page-help-btn" style="margin-left:auto;"><i class="fa fa-question-circle"></i> 使用說明</button>
        </div>
        <div class="clearfix"></div>

<?php if (!$canView): ?>
        <div class="xd-noperm">
            <h4><i class="fa fa-lock"></i> 無外來文件清單檢閱權限</h4>
            <p>請洽管理者於「使用者權限設定」指派「外來文件檢閱／管理」角色。</p>
        </div>
<?php else: ?>
        <div class="xd-toolbar">
            <label>範圍</label>
            <span class="xd-mode">
                <button type="button" id="modeBound" class="active" title="只列出曾被任何訂單綁定過的料號">有訂單綁定的料號</button>
                <button type="button" id="modeAll" title="列出所有掛了外來文件附件的料號">所有有附件的料號</button>
            </span>
            <label>客戶</label>
            <input type="text" id="custKw" placeholder="ID/名稱模糊搜尋" style="height:30px;width:130px;border:1px solid #D8BE93;border-radius:4px;padding:0 8px;font-size:13px;">
            <select id="custSel"><option value="">全部客戶</option></select>
            <label>年度</label>
            <select id="yearSel"><option value="">全部年度</option></select>
            <button id="btnCsv"><i class="fa fa-file-text-o"></i> 匯出CSV</button>
            <button class="btn-warm" id="btnPrint"><i class="fa fa-print"></i> 列印清單</button>
            <?php if ($canManage): ?>
            <button id="btnAsDoc"><i class="fa fa-bookmark-o"></i> AS文件編號綁定</button>
            <?php endif; ?>
            <span class="xd-role-badge">目前角色：<b><?= htmlspecialchars($roleLabel) ?></b>
                <i class="fa fa-question-circle" id="btnRoleHelp" title="角色權限說明"></i></span>
        </div>

        <div class="xd-asdoc" id="asDocBar">AS 文件編號：<b id="asDocNo">尚未綁定</b>
            <span id="issueUnitBar" style="margin-left:14px;">發行單位：<b id="issueUnit">—</b></span></div>

        <div id="catFilterBar" style="display:none;flex-wrap:wrap;gap:5px;align-items:center;margin-bottom:8px;font-size:13px;color:#5b3a1e;">
            <span>類別：</span>
        </div>

        <div class="xd-tabs">
            <button type="button" class="xd-tab active" id="tabActive"><i class="fa fa-list"></i> 外來文件清單</button>
            <button type="button" class="xd-tab" id="tabExcluded"><i class="fa fa-ban"></i> 已排除</button>
        </div>

        <div class="xd-pagebar">
            <span id="totalInfo">共 0 筆</span>
            <label style="margin-left:8px;">每頁</label>
            <select id="perPageSel" data-eg-skip>
                <option>5</option><option selected>10</option><option>20</option><option>50</option>
            </select>
            <span id="pageBtns"></span>
        </div>

        <div class="xd-table-wrap">
            <table class="xd-table" id="xdTable">
                <thead><tr id="xdHead"></tr></thead>
                <tbody id="xdBody"></tbody>
            </table>
        </div>
        <div style="font-size:11px;color:#8a6d45;margin-top:4px;">
            發行日期＝附件上傳日期。「外來文件類別」顯示標籤設定的類別名稱（未設定則用標籤名稱）。
            點<b>料號</b>可開啟文件；備註直接回寫到附件本體（其他頁面看到同一筆備註）；列印不帶備註。
            同一份文件在報價單與料號都掛了附件而重複時，用「排除」把重複那筆移到「已排除」分頁（可隨時加回）。
            要把某類附件納入本清單：至報價單頁或主檔管理的「附件類別標籤設定」勾選「列入外來文件清單」。
        </div>
<?php endif; ?>
    </div>
</div>
</div>

<!-- AS 文件編號綁定 -->
<div class="xd-mask" id="asMask"><div class="xd-modal">
    <div class="m-head">AS 文件編號綁定<span class="m-close" onclick="closeMask('asMask')">✕</span></div>
    <div class="m-body">
        <label>搜尋文件編號 / 名稱</label>
        <input type="text" id="asKw" placeholder="輸入關鍵字過濾" data-eg-skip>
        <label>選擇 AS 文件</label>
        <select id="asSel" size="10" style="height:auto;"></select>
        <div style="font-size:12px;color:#8a6d45;margin-top:6px;">綁定後，列印頁右下角會固定帶出此文件編號。選「（不綁定）」＝清除綁定。</div>
    </div>
    <div class="m-foot">
        <button class="b-ok" onclick="saveAsDoc()">儲存</button>
        <button class="b-no" onclick="closeMask('asMask')">取消</button>
    </div>
</div></div>

<!-- 頁面使用說明 -->
<div class="xd-mask" id="helpUseMask"><div class="xd-modal" style="max-width:760px;">
    <div class="m-head"><span><i class="fa fa-question-circle"></i> 外來文件清單 使用說明</span><span class="m-close" onclick="closeMask('helpUseMask')">✕</span></div>
    <div class="m-body help-doc">
        <h4>一、這頁在做什麼</h4>
        <p>依 AS9100 外來文件管制需求，把「客戶提供的文件」（客戶圖面、客供 3D、客戶規格…）自動彙整成一份清單。
        資料來源＝<b>料號附件</b>與<b>報價單附件</b>中，附件標籤有勾「<b>列入外來文件清單</b>」的那些附件；不必另外登錄，附件上傳掛對標籤就會自動出現。</p>
        <h4>二、操作</h4>
        <ul>
            <li><b>範圍</b>：「有訂單綁定的料號」＝該料號曾被任何訂單綁定過（正式生產過的）；「所有有附件的料號」＝包含只報過價的。</li>
            <li><b>客戶</b>：可在搜尋框輸入客戶 ID 或名稱片段模糊過濾；<b>年度</b>＝附件上傳日期年度。</li>
            <li><b>類別鈕</b>：點類別標籤只看該類別（顏色與列表標籤一致）。</li>
            <li><b>點料號</b>＝開啟該份文件；<b>備註</b>點鉛筆直接輸入，Enter 儲存、Esc 取消，會回寫到附件本體（主檔/報價頁看到同一筆備註）。</li>
            <li><b>排除</b>：同一份文件在報價單與料號兩邊都上傳過會重複出現，點「排除」把重複那筆移出清單；到「<b>已排除</b>」分頁可隨時「加回」。排除後列印與 CSV 也不會出現。</li>
            <li><b>列印</b>：依客戶分組；大標題＝本公司全名（主檔客戶頁「定為本公司」者）、左下角頁碼、右下角綁定的 AS 文件編號；<b>不帶備註</b>。CSV 有帶備註與檔名。</li>
        </ul>
        <h4>三、設定入口</h4>
        <ul>
            <li><b>哪些標籤算外來文件</b>：報價單頁「附件類別」分頁，或主檔管理「附件類別標籤設定」——勾「列入外來文件清單」，可另設清單顯示用的類別名稱（兩邊同一組設定）。</li>
            <li><b>AS 文件編號</b>：本頁「AS文件編號綁定」按鈕（需管理角色），從 AS9100 文件管理主檔挑選。</li>
            <li><b>發行單位</b>：同 Sales_Track 的業務單位設定（BOM 總覽頁修改）。</li>
        </ul>
        <h4>四、權限角色</h4>
        <ul>
            <li><b>外來文件檢閱</b>：看清單、開文件、匯出、列印。</li>
            <li><b>外來文件管理</b>：檢閱＋綁 AS 編號、編輯備註、排除/加回。</li>
            <li>管理者固定全權；未指派角色者無法檢視本頁。指派入口：使用者權限設定 →「外來文件清單」區塊。</li>
        </ul>
        <div class="tip">發行日期＝附件上傳日期。若清單是空的，通常是還沒有任何標籤勾「列入外來文件清單」。</div>
    </div>
    <div class="m-foot"><button class="b-ok" onclick="closeMask('helpUseMask')">我知道了</button></div>
</div></div>

<!-- 角色說明 -->
<div class="xd-mask" id="helpMask"><div class="xd-modal">
    <div class="m-head">角色權限說明<span class="m-close" onclick="closeMask('helpMask')">✕</span></div>
    <div class="m-body" style="line-height:1.8;">
        <b>外來文件檢閱</b>：檢視清單（含點料號開啟文件）、匯出 CSV、列印。<br>
        <b>外來文件管理</b>：檢閱＋綁定 AS 文件編號、編輯附件備註、排除/加回清單項目。<br>
        <b>管理者</b>：系統管理者固定擁有全部權限。<br>
        <hr style="border-color:#EADFC8;">
        清單來源＝附件標籤有勾「列入外來文件清單」的料號附件與報價附件；
        「有訂單綁定的料號」＝該料號曾被任何訂單綁定（不受年度篩選影響）。
    </div>
</div></div>

<script src="../../resource/js/jquery.min.js"></script>
<script src="../../resource/js/bootstrap.min.js"></script>
<script src="../../resource/js/fastclick.js"></script>
<script src="../../resource/js/nprogress.js"></script>
<script src="../../resource/js/custom.min.js"></script>
<script src="../../resource/js/eg_input_rules.js?v=<?= @filemtime(__DIR__.'/../../resource/js/eg_input_rules.js') ?>"></script>
<script>
$(document).ready(function(){
    var $am = $('#sidebar-menu .nav.side-menu > li.active');
    if ($am.length) { $am.removeClass('active').find('ul.child_menu').hide(); $am.find('li.current-page').removeClass('current-page'); }
    $('#sidebar-menu').css('visibility','visible');
});

var API = '../../src/store/ExternalDoc_API.php';
var canView = <?= $canView ? 'true' : 'false' ?>;
var canManage = <?= $canManage ? 'true' : 'false' ?>;
var MODE = 'bound', VIEW = 'active', PAGE = 1, TOTAL = 0, AS_DOC = null, AS_DOCS = [], ISSUE_UNIT = '';
var CAT = 0, CATS = [], CAT_COLOR = {}, COMPANY = '', CUSTOMERS = [];

// 類別固定調色盤（暖色系，依 ai-rules/10；同類別同色，列表/篩選鈕/列印一致）
var CAT_PALETTE = [
    ['#F7E0BD','#6b4a1c'], ['#F0A24B','#ffffff'], ['#E07856','#ffffff'], ['#C77C1A','#ffffff'],
    ['#F5C6A5','#7a4a1e'], ['#B85C38','#ffffff'], ['#9C6B3F','#ffffff'], ['#EAD3A2','#6b4a1c']
];
function catColor(cid){ return CAT_COLOR[cid] || CAT_PALETTE[0]; }
function catPill(cid, name){
    var c = catColor(cid);
    return '<span class="cat-pill" style="background:'+c[0]+';color:'+c[1]+';">'+esc(name)+'</span>';
}

function esc(s){ return String(s==null?'':s).replace(/[&<>"']/g,function(c){
    return {'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#39;'}[c]; }); }
function closeMask(id){ document.getElementById(id).style.display='none'; }
function openMask(id){ document.getElementById(id).style.display='block'; }

function filters(){
    return { mode: MODE, customer_id: $('#custSel').val()||'', year: $('#yearSel').val()||0, category: CAT };
}

function renderHead(){
    var h = '<th>客戶</th><th>料號</th><th>外來文件類別</th><th>發行日期</th><th>發行單位</th><th>來源</th><th>備註</th>';
    if (VIEW === 'excluded') h += '<th>排除資訊</th>';
    if (canManage) h += '<th>操作</th>';
    $('#xdHead').html(h);
}
function colCount(){ return 7 + (VIEW==='excluded'?1:0) + (canManage?1:0); }

function renderCustOptions(kw){
    kw = (kw||'').toLowerCase();
    var cur = $('#custSel').val();
    var $s = $('#custSel').empty().append('<option value="">全部客戶</option>');
    var hits = [];
    CUSTOMERS.forEach(function(c){
        if (kw && (c.customer_id+' '+c.customer).toLowerCase().indexOf(kw) === -1) return;
        hits.push(c.customer_id);
        $s.append('<option value="'+esc(c.customer_id)+'">'+esc(c.customer_id)+'　'+esc(c.customer)+'</option>');
    });
    // 原選擇仍在候選中就保留；模糊搜尋剛好剩一家＝直接選定
    if (cur && hits.indexOf(cur) !== -1) $s.val(cur);
    else if (kw && hits.length === 1) $s.val(hits[0]);
    else $s.val('');
}

function renderCatBar(){
    var $bar = $('#catFilterBar');
    if (!CATS.length){ $bar.hide(); return; }
    $bar.find('.cat-btn').remove();
    var mk = function(id, name){
        var c = id ? catColor(id) : ['#fff','#5b3a1e'];
        return '<button type="button" class="cat-btn'+(CAT===id?' active':'')+'" data-cid="'+id+'"'
             + ' style="background:'+c[0]+';color:'+c[1]+';">'+esc(name)+'</button>';
    };
    var h = mk(0, '全部');
    CATS.forEach(function(c){ h += mk(c.id, c.name); });
    $bar.append(h).css('display','flex');
}
$(document).on('click', '#catFilterBar .cat-btn', function(){
    CAT = parseInt($(this).data('cid'))||0;
    renderCatBar(); refreshAll();
});

// 選項（客戶/年度/類別鈕）跟著目前篩選連動：沒外來文件的客戶不會出現在下拉裡
function loadOptions(first){
    var f = filters();
    f.action = 'get_options';
    $.post(API, f, function(res){
        if (!res.success) return;
        CUSTOMERS = res.customers||[];
        var beforeCust = $('#custSel').val()||'';
        renderCustOptions($('#custKw').val()||'');
        // 年度：保留原選擇；該年度在目前篩選下已無資料則退回全部
        var beforeYear = $('#yearSel').val()||'';
        $('#yearSel').find('option:not(:first)').remove();
        (res.years||[]).forEach(function(y){
            $('#yearSel').append('<option value="'+y+'">'+y+' 年</option>');
        });
        $('#yearSel').val(($('#yearSel').find('option[value="'+beforeYear+'"]').length) ? beforeYear : '');
        // 配色以「全部外來文件標籤」為基準（跨篩選穩定同色）；按鈕只列目前篩選下實際出現的
        CAT_COLOR = {};
        (res.all_categories||[]).forEach(function(c, i){ CAT_COLOR[c.id] = CAT_PALETTE[i % CAT_PALETTE.length]; });
        CATS = res.categories||[];
        if (CAT && !CATS.some(function(c){ return c.id === CAT; })) CAT = 0;
        renderCatBar();
        COMPANY = res.company_name||'';
        AS_DOCS = res.as_docs||[];
        renderAsDoc(res.as_doc);
        ISSUE_UNIT = res.issue_unit||'';
        $('#issueUnit').text(ISSUE_UNIT || '（未設定業務單位）');
        if (first) loadList();
        // 連動後原選擇被移除（客戶/年度已無資料）→ 以新值重載一次列表
        else if (($('#custSel').val()||'') !== beforeCust || ($('#yearSel').val()||'') !== beforeYear) loadList();
    }, 'json');
}
// 任一篩選變更：重載列表＋重算其他維度的選項
function refreshAll(){ PAGE = 1; loadList(); loadOptions(false); }
var custKwT = null;
$('#custKw').on('input', function(){
    var kw = $(this).val();
    clearTimeout(custKwT);
    custKwT = setTimeout(function(){
        var before = $('#custSel').val();
        renderCustOptions(kw);
        if ($('#custSel').val() !== before) refreshAll();
    }, 250);
});

function renderAsDoc(doc){
    AS_DOC = doc || null;
    $('#asDocNo').text(AS_DOC ? (AS_DOC.doc_no + '（' + AS_DOC.doc_name + '）') : '尚未綁定');
}

function loadList(){
    var f = filters();
    f.action = 'get_list';
    f.show = VIEW;
    f.page = PAGE;
    f.per_page = parseInt($('#perPageSel').val())||10;
    renderHead();
    $('#xdBody').html('<tr><td colspan="'+colCount()+'" style="padding:20px;color:#8a6d45;">載入中…</td></tr>');
    $.post(API, f, function(res){
        if (!res.success){ $('#xdBody').html('<tr><td colspan="'+colCount()+'" style="padding:20px;color:#DD5138;">'+esc(res.message||'載入失敗')+'</td></tr>'); return; }
        TOTAL = res.total;
        ISSUE_UNIT = res.issue_unit||ISSUE_UNIT;
        $('#issueUnit').text(ISSUE_UNIT || '（未設定業務單位）');
        renderAsDoc(res.as_doc);
        var h = '';
        (res.rows||[]).forEach(function(r){
            var cats = (r.categories||[]).map(function(c, i){ return catPill((r.category_ids||[])[i], c); }).join('');
            var src = r.source==='part' ? '<span class="src-pill src-part">料號附件</span>'
                    : '<span class="src-pill src-quote" title="報價單 '+esc(r.quote_no)+'">報價附件</span>';
            var partCell = r.file_url
                ? '<a class="xd-doclink" href="'+esc(r.file_url)+'" target="_blank" title="開啟文件：'+esc(r.doc_name)+'">'+esc(r.part_no)+'</a>'
                : '<span title="'+esc(r.doc_name)+'">'+esc(r.part_no)+'</span>';
            var noteCell = esc(r.note||'');
            if (canManage) noteCell += ' <i class="fa fa-pencil xd-note-pen" style="cursor:pointer;color:#b5762a;" title="編輯備註（回寫到附件本體）"></i>';
            h += '<tr data-src="'+r.source+'" data-aid="'+r.attach_id+'" data-dpk="'+r.ds_pk+'" data-pno="'+esc(r.part_no)+'">'
               + '<td class="t-left">'+esc(r.customer_name)+'</td>'
               + '<td class="t-left">'+partCell+'</td>'
               + '<td>'+(cats||'<span style="color:#c9bda9;">—</span>')+'</td>'
               + '<td>'+esc(r.doc_date)+'</td>'
               + '<td>'+esc(ISSUE_UNIT)+'</td>'
               + '<td>'+src+'</td>'
               + '<td class="t-left xd-note" data-note="'+esc(r.note||'')+'" style="min-width:120px;">'+noteCell+'</td>';
            if (VIEW === 'excluded')
                h += '<td style="font-size:11px;color:#8a6d45;">'+esc(r.excluded_by||'')+'<br>'+esc(r.excluded_at||'')+'</td>';
            if (canManage)
                h += '<td>'+(VIEW==='excluded'
                        ? '<span class="xd-op xd-re-btn"><i class="fa fa-undo"></i> 加回</span>'
                        : '<span class="xd-op xd-ex-btn"><i class="fa fa-ban"></i> 排除</span>')+'</td>';
            h += '</tr>';
        });
        var emptyMsg = VIEW==='excluded' ? '沒有被排除的項目'
                     : '無符合條件的外來文件（先到附件類別標籤設定勾選「列入外來文件清單」）';
        $('#xdBody').html(h || '<tr><td colspan="'+colCount()+'" style="padding:20px;color:#8a6d45;">'+emptyMsg+'</td></tr>');
        renderPager();
    }, 'json');
}

// ── 分頁籤：清單 / 已排除（列印與 CSV 只針對正式清單）──────────────
$('#tabActive').on('click', function(){
    VIEW = 'active'; PAGE = 1;
    $(this).addClass('active'); $('#tabExcluded').removeClass('active');
    $('#btnPrint,#btnCsv').prop('disabled', false).css('opacity', 1);
    loadList();
});
$('#tabExcluded').on('click', function(){
    VIEW = 'excluded'; PAGE = 1;
    $(this).addClass('active'); $('#tabActive').removeClass('active');
    $('#btnPrint,#btnCsv').prop('disabled', true).css('opacity', .45);
    loadList();
});

// ── 排除 / 加回 ─────────────────────────────────────────────
$(document).on('click', '.xd-ex-btn', function(){
    var tr = $(this).closest('tr');
    if (!confirm('將「'+tr.data('pno')+'」這筆附件自外來文件清單排除？（可在「已排除」分頁加回）')) return;
    $.post(API, {action:'exclude_item', source:tr.data('src'), attach_id:tr.data('aid'),
                 ds_pk:tr.data('dpk'), part_no:tr.data('pno')}, function(res){
        if (!res.success){ alert(res.message||'排除失敗'); return; }
        loadList();
    }, 'json');
});
$(document).on('click', '.xd-re-btn', function(){
    var tr = $(this).closest('tr');
    $.post(API, {action:'restore_item', source:tr.data('src'), attach_id:tr.data('aid'),
                 ds_pk:tr.data('dpk')}, function(res){
        if (!res.success){ alert(res.message||'加回失敗'); return; }
        loadList();
    }, 'json');
});

// ── 備註即時編輯（回寫附件本體：part_attachments.note / quotation_attachments.note）──
$(document).on('click', '.xd-note-pen', function(){
    var td = $(this).closest('td');
    if (td.find('input').length) return;
    var cur = td.attr('data-note') || '';
    td.html('<input type="text" class="xd-note-edit" data-eg-skip maxlength="500" value="'+esc(cur)+'" placeholder="輸入備註後 Enter 儲存，Esc 取消">');
    var inp = td.find('input');
    inp.focus();
    inp.on('keydown', function(ev){
        if (ev.key === 'Enter'){ ev.preventDefault(); saveNote(td, inp.val()); }
        else if (ev.key === 'Escape'){ td.data('saving', 1); loadList(); }
    });
    inp.on('blur', function(){ saveNote(td, inp.val()); });
});
function saveNote(td, val){
    if (td.data('saving')) return;
    td.data('saving', 1);
    var tr = td.closest('tr');
    $.post(API, {action:'save_note', source:tr.data('src'), attach_id:tr.data('aid'), note:val}, function(res){
        if (!res.success) alert(res.message||'備註儲存失敗');
        loadList();
    }, 'json').fail(function(){ alert('備註儲存失敗'); loadList(); });
}

function renderPager(){
    var per = parseInt($('#perPageSel').val())||10;
    var pages = Math.max(1, Math.ceil(TOTAL/per));
    if (PAGE > pages) PAGE = pages;
    $('#totalInfo').text('共 '+TOTAL+' 筆');
    var h = '<button '+(PAGE<=1?'disabled':'')+' onclick="goPage('+(PAGE-1)+')">‹</button>';
    var s = Math.max(1, PAGE-2), e = Math.min(pages, s+4); s = Math.max(1, e-4);
    for (var p=s; p<=e; p++) h += '<button class="'+(p===PAGE?'cur':'')+'" onclick="goPage('+p+')">'+p+'</button>';
    h += '<button '+(PAGE>=pages?'disabled':'')+' onclick="goPage('+(PAGE+1)+')">›</button>';
    $('#pageBtns').html(h);
}
function goPage(p){ PAGE = Math.max(1, p); loadList(); }

$('#modeBound').on('click', function(){ MODE='bound'; $(this).addClass('active'); $('#modeAll').removeClass('active'); refreshAll(); });
$('#modeAll').on('click', function(){ MODE='all'; $(this).addClass('active'); $('#modeBound').removeClass('active'); refreshAll(); });
$('#custSel, #yearSel').on('change', function(){ refreshAll(); });
$('#perPageSel').on('change', function(){ PAGE=1; loadList(); });

$('#btnCsv').on('click', function(){
    var f = filters();
    location.href = API + '?action=export_csv&mode='+f.mode+'&customer_id='+encodeURIComponent(f.customer_id)+'&year='+f.year;
});

// ── 列印：依客戶分組；右下角固定頁尾＝AS 文件編號 ─────────────────────
$('#btnPrint').on('click', function(){
    var f = filters();
    f.action = 'get_print';
    $.post(API, f, function(res){
        if (!res.success){ alert(res.message||'載入失敗'); return; }
        var yearTxt = $('#yearSel').val() ? $('#yearSel').val()+' 年度' : '全部年度';
        var custTxt = $('#custSel').val() ? $('#custSel option:selected').text() : '全部客戶';
        var modeTxt = (MODE==='bound') ? '有訂單綁定的料號' : '所有有附件的料號';
        var catTxt  = CAT ? ('類別：'+($('#catFilterBar .cat-btn.active').text()||'')) : '';
        var unit = res.issue_unit||'';
        var company = res.company_name || COMPANY || '';
        var body = '<div class="p-comp">'+esc(company)+'</div>'
                 + '<div class="p-title">外來文件清單</div>'
                 + '<div class="p-sub">'+esc(yearTxt)+'｜'+esc(custTxt)+'｜'+esc(modeTxt)+(catTxt?'｜'+esc(catTxt):'')+'｜共 '+res.total+' 筆'
                 + '｜列印日期：'+new Date().toISOString().substr(0,10)+'</div>';
        (res.groups||[]).forEach(function(g){
            body += '<div class="p-cust">客戶：'+esc(g.customer_name)+'</div>';
            body += '<table class="p-tb"><thead><tr><th style="width:30%;">料號</th>'
                  + '<th>外來文件類別</th><th style="width:16%;">發行日期</th><th style="width:16%;">發行單位</th></tr></thead><tbody>';
            g.rows.forEach(function(r){
                var cats = (r.categories||[]).map(function(c, i){ return catPill((r.category_ids||[])[i], c); }).join('');
                body += '<tr><td class="tl">'+esc(r.part_no)+'</td>'
                      + '<td>'+cats+'</td><td>'+esc(r.doc_date)+'</td><td>'+esc(unit)+'</td></tr>';
            });
            body += '</tbody></table>';
        });
        if (!(res.groups||[]).length) body += '<div style="padding:20px;color:#666;">無符合條件的外來文件</div>';
        // 頁尾走 @page margin box（列印引擎繪製，每頁都有）：右下=AS 文件編號、左下=頁碼（多頁才顯示）
        var asTxt = res.as_doc ? String(res.as_doc.doc_no).replace(/['\\]/g,'') : '';
        // 左右各留 6mm 安全邊：邊界選「最小值」時 @page 的 10mm 會被覆蓋，最右欄(發行單位)會被印表機不可印區裁掉
        var css = 'body{font-family:"Microsoft JhengHei",sans-serif;margin:0;padding:0 6mm;color:#222;-webkit-print-color-adjust:exact;print-color-adjust:exact;}'
            + '.p-comp{font-size:22px;font-weight:bold;text-align:center;margin-bottom:1px;}'
            + '.p-title{font-size:17px;font-weight:bold;text-align:center;letter-spacing:6px;margin-bottom:2px;}'
            + '.p-sub{font-size:11px;text-align:center;color:#555;margin-bottom:10px;}'
            + '.p-cust{font-size:14px;font-weight:bold;margin:10px 0 3px;border-left:4px solid #F0A24B;padding-left:6px;break-after:avoid;}'
            + 'table.p-tb{width:100%;table-layout:fixed;border-collapse:collapse;font-size:11px;margin-bottom:6px;}'
            + 'table.p-tb thead{display:table-header-group;}'   // 跨頁時每頁重印表頭
            + 'table.p-tb th,table.p-tb td{border:1px solid #666;padding:2px 5px;text-align:center;overflow-wrap:anywhere;}'
            + 'table.p-tb thead th{background:#f3ead6;}'
            + 'table.p-tb td.tl{text-align:left;word-break:break-all;}'
            + 'table.p-tb tr{break-inside:avoid;}'
            + '.cat-pill{display:inline-block;font-size:10px;border:1px solid rgba(122,82,23,.25);border-radius:9px;padding:0 6px;margin:1px 2px;}'
            // 左右邊界 10mm、下邊界 18mm：RICOH 等實體印表機邊緣約 4~5mm 印不到，太貼邊會被裁掉
            // 註：瀏覽器頁首(日期/標題)不受 @page 邊界控制，CSS 無法關掉，要在列印跳窗「顯示更多設定」取消勾選「頁首及頁尾」
            + '@page{margin:12mm 10mm 18mm;'
            + (asTxt ? " @bottom-right{ content:'"+asTxt+"'; font-size:9pt; color:#333; vertical-align:top; padding-top:1mm; }" : '')
            + '}';
        var w = window.open('', '_blank');
        w.document.write('<html><head><meta charset="utf-8"><title>外來文件清單</title><style>'+css+'</style></head><body>'+body
            +'<scr'+'ipt>window.onload=function(){'
            // 內容超過一頁(以A4概算)才加頁碼——只影響顯示不影響分頁；counter(pages) 由列印引擎在列印當下計算
            +'var onePageA4=(297-30)*96/25.4;'
            +'if(document.body.scrollHeight>onePageA4*0.92){'
            +'var st=document.createElement(\'style\');'
            +'st.textContent="@page{ @bottom-left{ content:\'第 \' counter(page) \' 頁／共 \' counter(pages) \' 頁\'; font-size:9pt; color:#333; vertical-align:top; padding-top:1mm; } }";'
            +'document.head.appendChild(st);}'
            +'setTimeout(function(){window.print();},200);};</scr'+'ipt></body></html>');
        w.document.close();
    }, 'json');
});

// ── AS 文件編號綁定 ─────────────────────────────────────────────
function renderAsSel(kw){
    kw = (kw||'').toLowerCase();
    var $s = $('#asSel').html('<option value="0">（不綁定）</option>');
    AS_DOCS.forEach(function(d){
        var t = d.doc_no + ' ' + d.doc_name;
        if (kw && t.toLowerCase().indexOf(kw) === -1) return;
        $s.append('<option value="'+d.id+'">'+esc(d.doc_no)+'　'+esc(d.doc_name)+'</option>');
    });
    $s.val(AS_DOC ? String(AS_DOC.id) : '0');
    if ($s.val() === null) $s.val('0');
}
$('#btnAsDoc').on('click', function(){ $('#asKw').val(''); renderAsSel(''); openMask('asMask'); });
$('#asKw').on('input', function(){ renderAsSel($(this).val()); });
function saveAsDoc(){
    $.post(API, {action:'save_as_doc', as_doc_id: $('#asSel').val()||0}, function(res){
        if (!res.success){ alert(res.message||'儲存失敗'); return; }
        renderAsDoc(res.as_doc);
        closeMask('asMask');
    }, 'json');
}

$('#btnRoleHelp').on('click', function(){ openMask('helpMask'); });
$('#btnPageHelp').on('click', function(){ openMask('helpUseMask'); });
$('.xd-mask').on('click', function(e){ if (e.target === this) this.style.display='none'; });

if (canView){ loadOptions(true); }   // 選項(含類別配色)載好後由 loadOptions 觸發 loadList
</script>
</body>
</html>
