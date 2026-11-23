<?php
/**
 * 簽呈（2-MM-01-15）— 獨立頁面，與公告／通知（createEvent.php）分開。
 * 電子簽核：部門主管批示 → 總經理批示 → 會辦單位批示（順序可由管理員調整），每關可核准／退回並寫批示意見，
 * 提簽人與關係人可在單上回覆；待簽、結果、回覆都走站內通知（live_event）＋推播。
 * 資料一律走 src/store/Memo_API.php；共用邏輯 src/common/memo_lib.php
 */
session_start();
if (!isset($_SESSION['userName'])) {
    $_SESSION['lastpage'] = "../../views/ADM/memo_request.php";
    header("Location:../../index.php");
    exit;
}
include_once '../../src/common/_config.php';
include_once '../../src/common/DBConnection.php';
include_once '../../src/common/memo_lib.php';

$db = (new DBConnection())->getPDO();
memo_ensure_schema($db);
$mUser = memo_current_user($db);
$perms = memo_perms($db, $mUser);
$roleLabel = $perms['isAdmin'] ? '管理者'
           : ($perms['canAdmin'] ? '簽呈管理員'
           : ($perms['viewAny'] ? '簽呈檢閱' : ($perms['canApply'] ? '一般員工（可開自己的簽呈）' : '無權限')));
?>
<!DOCTYPE html>
<html lang="zh-Hant">
<head>
    <meta charset="utf-8">
    <meta http-equiv="X-UA-Compatible" content="IE=edge">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>簽呈</title>
    <link href="../../resource/css/bootstrap.css" rel="stylesheet">
    <link href="../../resource/css/font-awesome.css" rel="stylesheet">
    <link href="../../resource/css/nprogress.css" rel="stylesheet">
    <link href="../../resource/css/custom.css" rel="stylesheet">
    <style>
        #sidebar-menu { visibility: hidden; }
        .right_col .page-title { margin:8px 0 4px; overflow:hidden; clear:both; }
        .page-help-btn { height:30px; font-size:13px; padding:0 12px; border:1px solid #d98a33; border-radius:15px; background:#F0A24B; color:#fff; cursor:pointer; }
        .page-help-btn:hover { background:#d98a33; }
        @media print { .page-help-btn { display:none !important; } }
        .help-doc { font-size:13px; color:#5b3a1e; line-height:1.75; }
        .help-doc h4 { color:#8A5A2B; border-bottom:2px solid #F7E0BD; padding-bottom:3px; margin:14px 0 6px; font-size:15px; }
        .help-doc h4:first-child { margin-top:0; }
        .help-doc b { color:#8A5A2B; }
        .mm-toolbar { display:flex; flex-wrap:wrap; gap:6px; align-items:center; clear:both; border:1.5px solid #E8D5B5; border-radius:8px; padding:8px 10px; margin-bottom:10px; background:#FDF8EF; }
        .mm-toolbar label { margin:0; font-size:13px; color:#5b3a1e; }
        .mm-toolbar select, .mm-toolbar input, .mm-toolbar button { height:30px; font-size:13px; line-height:1; padding:0 10px; border:1px solid #D8BE93; border-radius:4px; background:#fff; color:#5b3a1e; }
        .mm-toolbar button { cursor:pointer; }
        .mm-toolbar button:hover { background:#F7E0BD; }
        .mm-toolbar .btn-warm { background:#F0A24B; color:#fff; border-color:#d98a33; }
        .mm-toolbar .btn-warm:hover { background:#d98a33; }
        .mm-role-badge { margin-left:auto; font-size:13px; color:#5b3a1e; background:#F7E0BD; border-radius:12px; padding:4px 12px; }
        .mm-wrap { overflow-x:auto; border:1px solid #E8D5B5; border-radius:6px; background:#fff; }
        table.mm-table { width:100%; border-collapse:collapse; font-size:13px; }
        table.mm-table th, table.mm-table td { border:1px solid #EADFC8; padding:5px 8px; text-align:center; }
        table.mm-table thead th { background:#F7E0BD; color:#5b3a1e; font-weight:bold; white-space:nowrap; }
        table.mm-table tbody tr:nth-child(even) { background:#FBF6EC; }
        table.mm-table td.l { text-align:left; }
        table.mm-table tr.clk { cursor:pointer; }
        table.mm-table tr.clk:hover { background:#F7E0BD; }
        .mm-pager { display:flex; justify-content:flex-end; align-items:center; gap:6px; margin:6px 0; font-size:13px; color:#5b3a1e; }
        .mm-pager button { height:26px; padding:0 9px; border:1px solid #D8BE93; background:#fff; border-radius:4px; cursor:pointer; }
        .mm-pager button.on { background:#F0A24B; color:#fff; border-color:#d98a33; }
        .st { display:inline-block; padding:1px 9px; border-radius:10px; font-size:12px; line-height:18px; }
        .st-draft { background:#EFE7D8; color:#6b5535; }
        .st-submitted { background:#F7E0BD; color:#8A5A2B; }
        .st-approved { background:#F0A24B; color:#fff; }
        .st-rejected, .st-late { background:#DD5138; color:#fff; }
        .st-pending { background:#F7E0BD; color:#8A5A2B; }
        .st-waiting { background:#EFE7D8; color:#6b5535; }
        .st-skipped { background:#EFE7D8; color:#8a7a60; }
        .st-secret { background:#8A5A2B; color:#fff; }
        .mm-mask { display:none; position:fixed; inset:0; background:rgba(60,40,20,.45); z-index:9000; overflow:auto; }
        .mm-modal { background:#fff; border-radius:8px; margin:40px auto; max-width:860px; width:94%; box-shadow:0 8px 30px rgba(0,0,0,.3); }
        .mm-modal.narrow { max-width:540px; }
        .m-head { background:#F7E0BD; color:#5b3a1e; font-weight:bold; padding:9px 14px; border-radius:8px 8px 0 0; display:flex; }
        .m-close { margin-left:auto; cursor:pointer; }
        .m-body { padding:14px; max-height:72vh; overflow:auto; }
        .m-foot { padding:10px 14px; border-top:1px solid #EADFC8; text-align:right; }
        .m-foot button { height:32px; padding:0 14px; border-radius:4px; border:1px solid #D8BE93; background:#fff; color:#5b3a1e; cursor:pointer; margin-left:6px; }
        .m-foot .b-ok { background:#F0A24B; color:#fff; border-color:#d98a33; }
        .m-foot .b-danger { background:#DD5138; color:#fff; border-color:#C4442D; }
        .grid2 { display:grid; grid-template-columns:1fr 1fr; gap:10px; }
        .m-body label { display:block; font-size:13px; color:#5b3a1e; margin:6px 0 3px; font-weight:normal; }
        .m-body input[type=text], .m-body input[type=date], .m-body select, .m-body textarea { width:100%; border:1px solid #D8BE93; border-radius:4px; padding:5px 8px; font-size:13px; color:#5b3a1e; background:#fff; }
        .m-body textarea { resize:vertical; }
        .ro-auto { background:#F3EADB !important; color:#7a6446 !important; }
        .mm-hint { font-size:12px; color:#8a6d45; line-height:1.7; }
        .mm-err { color:#DD5138; font-size:12px; margin-top:2px; display:none; }
        .bad { border-color:#DD5138 !important; background:#FFF3EF !important; }
        .co-row { display:grid; grid-template-columns:24px 1fr 1fr 28px; gap:6px; align-items:center; margin-bottom:5px; }
        .co-row .x { cursor:pointer; color:#DD5138; text-align:center; }
        .stp { border:1px solid #EADFC8; border-radius:6px; padding:6px 10px; margin-bottom:6px; background:#FDF9F1; }
        .stp .h { display:flex; gap:8px; align-items:center; flex-wrap:wrap; font-size:13px; color:#5b3a1e; }
        .stp .n { margin-top:4px; font-size:13px; color:#5b3a1e; white-space:pre-wrap; }
        .cmt { border-left:3px solid #F0A24B; padding:3px 10px; margin-bottom:6px; font-size:13px; color:#5b3a1e; background:#FBF6EC; }
        .cmt .t { font-size:11px; color:#8a6d45; }
        .view-box { border:1px solid #EADFC8; border-radius:6px; padding:8px 12px; background:#fff; white-space:pre-wrap; font-size:14px; line-height:1.8; color:#3d2a14; min-height:60px; }
        .mm-noperm { border:1.5px solid #E8D5B5; background:#FDF8EF; border-radius:8px; padding:24px; color:#5b3a1e; }
        .mm-top { position:fixed; right:24px; bottom:24px; width:40px; height:40px; border-radius:20px; background:#F0A24B; color:#fff; border:none; cursor:pointer; display:none; z-index:100; }
        .badge-todo { background:#DD5138; color:#fff; border-radius:9px; padding:0 7px; font-size:11px; margin-left:3px; }
    </style>
</head>
<body class="nav-sm">
<div class="container body">
<div class="main_container">
    <?php include '../partPage/sideAndTopBarMenu.html' ?>
    <div class="right_col" role="main">
        <div class="page-title" style="display:flex;align-items:center;flex-wrap:wrap;">
            <h2 style="margin:6px 0;">簽呈 <small style="color:#8a6d45;">2-MM-01-15　電子簽核（與公告／通知分開）</small></h2>
            <button id="btnPageHelp" class="page-help-btn" style="margin-left:auto;"><i class="fa fa-question-circle"></i> 使用說明</button>
        </div>
        <div class="clearfix"></div>
<?php if (!$perms['canApply'] && !$perms['viewAny']): ?>
        <div class="mm-noperm"><h4><i class="fa fa-lock"></i> 無簽呈使用權限</h4><p>此帳號目前不是在職狀態，無法使用簽呈。如有疑問請洽管理課或系統管理者。</p></div>
<?php else: ?>
        <div class="mm-toolbar">
            <label>範圍</label>
            <select id="scopeSel">
                <option value="todo">待我批示</option>
                <option value="mine">我提的簽呈</option>
                <option value="involved">與我有關</option>
<?php if ($perms['viewAny']): ?>                <option value="all">全部簽呈</option>
<?php endif; ?>
            </select>
            <label>狀態</label>
            <select id="statusSel"><option value="">全部</option><option value="draft">草稿</option><option value="submitted">簽核中</option><option value="approved">已核准</option><option value="rejected">已退回</option></select>
            <input type="text" id="kwInput" placeholder="主旨／編號／提簽人／部門" style="width:220px;">
            <label>每頁</label>
            <select id="perSel"><option>5</option><option selected>10</option><option>20</option><option>50</option></select>
            <button id="btnNew" class="btn-warm"><i class="fa fa-plus"></i> 新增簽呈</button>
<?php if ($perms['canAdmin']): ?>            <button id="btnSetting"><i class="fa fa-cog"></i> 模組設定</button>
<?php endif; ?>
            <span class="mm-role-badge">目前角色：<?= htmlspecialchars($roleLabel) ?></span>
        </div>
        <div class="mm-pager" id="pagerTop"></div>
        <div class="mm-wrap">
            <table class="mm-table">
                <thead><tr><th>編號</th><th>日期</th><th>主旨</th><th>提簽人</th><th>部門</th><th>等級／時限</th><th>狀態</th><th>目前批示人</th></tr></thead>
                <tbody id="listBody"><tr><td colspan="8">載入中…</td></tr></tbody>
            </table>
        </div>
        <div class="mm-pager" id="pagerBot"></div>
<?php endif; ?>
    </div>
</div>
</div>
<button class="mm-top" id="btnTop" title="回到頂端"><i class="fa fa-arrow-up"></i></button>

<!-- 編輯簽呈（欄位順序比照紙本 2-MM-01-15） -->
<div class="mm-mask" id="editMask"><div class="mm-modal">
    <div class="m-head"><span id="editTitle">新增簽呈</span><span class="m-close" onclick="closeMask('editMask')">✕</span></div>
    <div class="m-body">
        <div class="grid2">
            <div><label>編號（管理課）</label><input type="text" id="fNo" placeholder="儲存時自動產生"></div>
            <div><label>日期 *</label><input type="date" id="fDate"><div class="mm-err" id="eDate"></div></div>
            <div><label>提簽人</label><input type="text" id="fUser" class="ro-auto" readonly></div>
            <div><label>所屬部門</label><input type="text" id="fDept"></div>
            <div><label>機密等級</label><select id="fSecrecy"></select></div>
            <div><label>時限</label><select id="fUrgency"></select></div>
        </div>
        <div class="mm-hint" id="urgHint"></div>
        <label>送簽模式</label><select id="fRoute"></select><div class="mm-hint" id="routeHint"></div>
        <label>調查來源簽呈（選填：這張是「調查結果」簽呈時選原啟動調查簽呈）</label>
        <div style="display:flex;gap:6px;"><select id="fSrc" data-eg-filter="輸入簽呈編號或主旨篩選…" style="flex:1;"></select><button type="button" id="btnInvTpl" style="height:30px;border:1px solid #d98a33;background:#F0A24B;color:#fff;border-radius:4px;padding:0 12px;cursor:pointer;white-space:nowrap;">套用調查結果格式</button></div>
        <div class="mm-hint">按「套用調查結果格式」會依來源簽呈帶入事件緣由、已建立的訪談記錄與附件清單，其餘查不到的欄位留【待填】，要全部補完才能送出。</div>
        <label>主旨 *</label><input type="text" id="fSubject" maxlength="300"><div class="mm-err" id="eSubject"></div>
        <label>說明 *</label><textarea id="fContent" rows="9"></textarea><div class="mm-err" id="eContent"></div>
        <label>會辦單位（最多 5 個；不指定人員時由該部門主管批示）</label>
        <div id="coList"></div>
        <button type="button" id="btnAddCo" style="height:28px;border:1px solid #D8BE93;background:#fff;border-radius:4px;color:#5b3a1e;cursor:pointer;">＋ 新增會辦單位</button>
        <div class="mm-hint" style="margin-top:8px;">簽核流程：<span id="flowHint"></span>　<span id="adminOwner"></span></div>
    </div>
    <div class="m-foot">
        <button onclick="closeMask('editMask')">取消</button>
        <button id="btnSaveDraft">儲存草稿</button>
        <button id="btnSaveSubmit" class="b-ok">儲存並送出</button>
    </div>
</div></div>

<!-- 檢視簽呈 -->
<div class="mm-mask" id="viewMask"><div class="mm-modal">
    <div class="m-head"><span id="viewTitle">簽呈</span><span class="m-close" onclick="closeMask('viewMask')">✕</span></div>
    <div class="m-body" id="viewBody"></div>
    <div class="m-foot" id="viewFoot"></div>
</div></div>

<!-- 批示 -->
<div class="mm-mask" id="decideMask"><div class="mm-modal narrow">
    <div class="m-head"><span id="decTitle">批示</span><span class="m-close" onclick="closeMask('decideMask')">✕</span></div>
    <div class="m-body">
        <div class="mm-hint" id="decInfo"></div>
        <label>批示日期</label><input type="date" id="decDate">
        <label>批示意見（核准可留空，退回必填）</label><textarea id="decNote" rows="5" maxlength="1000"></textarea>
        <div class="mm-err" id="eDec"></div>
    </div>
    <div class="m-foot"><button onclick="closeMask('decideMask')">取消</button>
        <button class="b-danger" id="btnReject">退回</button><button class="b-ok" id="btnApprove">核准</button></div>
</div></div>

<!-- 設定 -->
<div class="mm-mask" id="setMask"><div class="mm-modal narrow">
    <div class="m-head"><span>簽呈模組設定</span><span class="m-close" onclick="closeMask('setMask')">✕</span></div>
    <div class="m-body">
        <label>AS 文件編號綁定（表頭表單名稱與頁尾編號由此帶出）</label>
        <div style="display:flex;gap:6px;"><input type="text" id="setAsDoc" class="ro-auto" readonly><button type="button" id="btnPickAsDoc">選擇</button><button type="button" id="btnClearAsDoc">清除</button></div>
        <label>是否需要總經理批示</label>
        <select id="setNeedGm"><option value="1">需要（組織角色綁定的最高核准人員）</option><option value="0">不需要</option></select>
        <label>簽核順序</label>
        <select id="setOrder"><option value="0">部門主管 → 總經理 → 會辦單位（比照紙本欄位順序）</option><option value="1">部門主管 → 會辦單位 → 總經理</option></select>
        <label>批示圖章模板</label><select id="setStampTpl"><option value="0">（預設圓形圖章）</option></select>
        <label>人事部門可檢閱的機密等級（人事部門成員；不勾＝只能看自己經手的）</label><div id="setHrLv"></div>
        <label>部門主管可檢閱的機密等級（限自己主管的部門含下轄；不勾＝只能看自己經手的）</label><div id="setSupLv"></div>
        <label>固定送簽模式（提簽人送簽時挑選；停用的模式不會出現）</label>
        <div id="routeBox"></div><button type="button" id="btnAddRoute">＋ 新增送簽模式</button>
        <div class="mm-hint" style="margin-top:8px;">角色與權限請到「使用者權限設定」頁的「簽呈」區塊指派。</div>
    </div>
    <div class="m-foot"><button onclick="closeMask('setMask')">取消</button><button class="b-ok" id="btnSaveSet">儲存</button></div>
</div></div>

<!-- 使用說明 -->
<div class="mm-mask" id="helpUseMask"><div class="mm-modal">
    <div class="m-head"><span>簽呈 — 使用說明</span><span class="m-close" onclick="closeMask('helpUseMask')">✕</span></div>
    <div class="m-body"><div class="help-doc">
        <h4>這個頁面做什麼</h4>
        <p>簽呈（2-MM-01-15）的電子簽核。與「公告／通知」不同：公告是發給一群人看，簽呈是一張單逐關批示、可核准或退回、可回覆討論，結果留在單上。</p>
        <h4>怎麼開單</h4>
        <p>按「新增簽呈」，填日期、機密等級、時限、<b>主旨</b>、<b>說明</b>，需要其他單位意見就加<b>會辦單位</b>（最多 5 個，可指定人員，不指定則由該部門主管批示）。可先存草稿，確認後按「儲存並送出」。</p>
        <h4>簽核流程</h4>
        <p>預設：<b>部門主管批示 → 總經理批示 → 會辦單位批示</b>（會辦單位同時進行，全部批示完才結案；管理員可在設定把會辦調到總經理之前）。每一關都能<b>核准或退回</b>，退回必須填原因，退回後提簽人修改再送出會重新跑一輪。提簽人本人就是某一關的簽核人（例如課級最高主管、最高決策者）時，該關自動略過並註記。簽核人請假時依代理設定轉給代理人。</p>
        <h4>通知與回覆</h4>
        <p>輪到你批示時會收到站內通知（有啟用推播也會推播），點通知可直接開單批示。單上的「回覆」可讓提簽人與各關簽核人互相討論，每則回覆都會通知相關人員。簽核中、還沒有任何一關批示過時，提簽人可「撤回」改內容。</p>
        <h4>機密等級與時限</h4>
        <p><b>機密／極機密</b>的簽呈，只有提簽人、簽核關卡上的人與管理員看得到（檢閱角色也看不到）；通知內容也不顯示主旨。時限：普通三天、速件兩天、最速件當天，超過未完成會在清單標示「逾期」。</p>
        <h4>列印</h4>
        <p>版面比照紙本簽呈，頁尾右下角為綁定的 AS 文件編號（版次依簽呈日期回推）。各關批示意見與圖章會印在對應欄位。</p>
        <h4>權限</h4>
        <p>所有在職員工都能開自己的簽呈並批示指派給自己的單。<b>簽呈檢閱</b>角色可查看全部「一般」等級簽呈；<b>簽呈管理員</b>可看全部（含機密）、代開、修改編號、刪除、模組設定。</p>
    </div></div>
    <div class="m-foot"><button class="b-ok" onclick="closeMask('helpUseMask')">我知道了</button></div>
</div></div>

<script src="../../resource/js/jquery.min.js"></script>
<script src="../../resource/js/bootstrap.min.js"></script>
<script src="../../resource/js/fastclick.js"></script>
<script src="../../resource/js/nprogress.js"></script>
<script src="../../resource/js/custom.min.js"></script>
<script src="../../resource/js/eg_stamp.js?v=<?= @filemtime(__DIR__.'/../../resource/js/eg_stamp.js') ?>"></script>
<script src="../../resource/js/eg_stamp_tpl.js?v=<?= @filemtime(__DIR__.'/../../resource/js/eg_stamp_tpl.js') ?>"></script>
<script src="../../resource/js/eg_date_fmt.js?v=<?= @filemtime(__DIR__.'/../../resource/js/eg_date_fmt.js') ?>"></script>
<script src="../../resource/js/eg_asdoc_picker.js?v=<?= @filemtime(__DIR__.'/../../resource/js/eg_asdoc_picker.js') ?>"></script>
<script src="../../resource/js/eg_print_log.js?v=<?= @filemtime(__DIR__.'/../../resource/js/eg_print_log.js') ?>"></script>
<script src="../../resource/js/eg_input_rules.js?v=<?= @filemtime(__DIR__.'/../../resource/js/eg_input_rules.js') ?>"></script>
<script>
$(document).ready(function(){
    var $am = $('#sidebar-menu .nav.side-menu > li.active');
    if ($am.length) { $am.removeClass('active').find('ul.child_menu').hide(); $am.find('li.current-page').removeClass('current-page'); }
    $('#sidebar-menu').css('visibility','visible');
});

var API = '../../src/store/Memo_API.php';
var FIRST_LOAD = true, META = null, PAGE = 1, CUR = null, CUR_VIEW = null, COSIGN = [];
var ST_LABEL = {draft:'草稿', submitted:'簽核中', approved:'已核准', rejected:'已退回'};
var STEP_LABEL = {dept:'部門主管批示', gm:'總經理批示', cosign:'會辦單位批示'};
var STEP_ST = {waiting:'等待中', pending:'待批示', approved:'已核准', rejected:'已退回', skipped:'略過'};

function esc(s){ return String(s==null?'':s).replace(/[&<>"']/g,function(c){ return {'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#39;'}[c]; }); }
function dispDate(d){ return d ? egFmtDate(d) : ''; }
function openMask(id){ document.getElementById(id).style.display='block'; }
function closeMask(id){ document.getElementById(id).style.display='none'; }
function today(){ var d=new Date(); return d.getFullYear()+'-'+('0'+(d.getMonth()+1)).slice(-2)+'-'+('0'+d.getDate()).slice(-2); }
function stampHtml(name, date, deputy){
    try { if (window.EGStamp && EGStamp.stamp)
        return EGStamp.stamp(name, date||'', !!deputy, (META && META.stamp_template) ? META.stamp_template.schema : null); } catch(e){}
    return '<span style="font-size:14px;">'+esc(name)+'</span>'+(date?'<div style="font-size:10px;color:#555;">'+esc(date)+'</div>':'');
}
function post(data, ok){
    data.csrf = META ? META.csrf : '';
    $.post(API, data, function(res){ if (!res.ok) { alert(res.error||'操作失敗'); return; } ok(res); }, 'json');
}
$(document).ajaxError(function(e, x){
    var m = (x.responseJSON && x.responseJSON.error) || ('HTTP '+x.status);
    if (x.status === 403 && /CSRF/.test(m)) { loadMeta(); m += '（已更新憑證，請再按一次）'; }
    alert('操作失敗：'+m);
});

/* ================= 載入 ================= */
function loadMeta(then){
    $.getJSON(API, {action:'meta'}, function(res){
        if (!res.ok) return;
        META = res; window.__ownCompany = res.company || '';
        var h='', k; for (k in res.secrecy) h += '<option value="'+k+'">'+esc(res.secrecy[k])+'</option>';
        $('#fSecrecy').html(h);
        h=''; for (k in res.urgency) h += '<option value="'+k+'">'+esc(res.urgency[k])+'</option>';
        $('#fUrgency').html(h);
        fillSettingUI(res);
        if (then) then();
    });
}
function statusBadge(r){
    var s = '<span class="st st-'+r.status+'">'+ST_LABEL[r.status]+'</span>';
    if (r.status === 'submitted' && r.due_date && r.due_date < today()) s += ' <span class="st st-late">逾期</span>';
    return s;
}
function loadList(){
    $.getJSON(API, {action:'list', scope:$('#scopeSel').val(), status:$('#statusSel').val(), kw:$('#kwInput').val(), page:PAGE, per:$('#perSel').val()}, function(res){
        if (!res.ok) return;
        var h='';
        res.rows.forEach(function(r){
            var sec = r.secrecy !== 'normal' ? ' <span class="st st-secret">'+esc(META.secrecy[r.secrecy])+'</span>' : '';
            h += '<tr class="clk" data-id="'+r.memo_id+'"><td>'+esc(r.memo_no||'')+'</td><td>'+esc(dispDate(r.apply_date))+'</td>'
              + '<td class="l">'+esc(r.subject)+sec+'</td><td>'+esc(r.user_name)+'</td><td>'+esc(r.dept_name||'')+'</td>'
              + '<td>'+esc(META.urgency[r.urgency]||'')+'</td><td>'+statusBadge(r)+'</td><td class="l">'+esc(r.pending_who||'')+'</td></tr>';
        });
        $('#listBody').html(h || '<tr><td colspan="8">沒有符合條件的簽呈</td></tr>');
        var pages = Math.max(1, Math.ceil(res.total / res.per)), p='';
        p += '共 '+res.total+' 筆　';
        for (var i=1;i<=pages;i++) { if (pages>9 && Math.abs(i-PAGE)>3 && i!==1 && i!==pages) { if (Math.abs(i-PAGE)===4) p+='…'; continue; }
            p += '<button class="'+(i===PAGE?'on':'')+'" data-p="'+i+'">'+i+'</button>'; }
        $('#pagerTop,#pagerBot').html(p);
        if (FIRST_LOAD) { FIRST_LOAD = false; if ($('#scopeSel').val()==='todo' && !res.todo_count) { $('#scopeSel').val('mine'); PAGE=1; loadList(); return; } }
        var opt = $('#scopeSel option[value=todo]');
        opt.text('待我批示' + (res.todo_count ? '（'+res.todo_count+'）' : ''));
    });
}
$(document).on('click', '.mm-pager button', function(){ PAGE = +$(this).data('p'); loadList(); });
$(document).on('click', '#listBody tr.clk', function(){ openView($(this).data('id')); });
$('#scopeSel,#statusSel,#perSel').on('change', function(){ PAGE=1; loadList(); });
var kwT; $('#kwInput').on('input', function(){ clearTimeout(kwT); kwT=setTimeout(function(){ PAGE=1; loadList(); }, 300); });
$(window).on('scroll', function(){ $('#btnTop').toggle($(window).scrollTop() > 300); });
$('#btnTop').on('click', function(){ $('html,body').animate({scrollTop:0}, 200); });

/* ================= 編輯 ================= */
function flowText(){
    var s = META.settings, parts = ['部門主管批示'];
    var gm = (+s.memo_need_gm) ? '總經理批示' : '';
    var co = COSIGN.length ? '會辦單位批示（'+COSIGN.length+' 個，同時進行）' : '';
    if (+s.memo_cosign_before_gm) { if (co) parts.push(co); if (gm) parts.push(gm); } else { if (gm) parts.push(gm); if (co) parts.push(co); }
    return parts.join(' → ');
}
function urgHint(){
    var d = {normal:'普通：三天內完成', urgent:'速件：兩天內完成', rush:'最速件：當天完成'}[$('#fUrgency').val()];
    $('#urgHint').text('時限：' + d);
}
function renderCosign(){
    var depts = '<option value="0">（選擇部門）</option>' + (META.depts||[]).map(function(d){ return '<option value="'+d.id+'">'+esc(d.name)+'</option>'; }).join('');
    var h = '';
    COSIGN.forEach(function(c, i){
        var people = '<option value="0">（不指定，取部門主管）</option>' + (META.people||[]).filter(function(p){ return +p.dept_id === +c.dept_id; })
            .map(function(p){ return '<option value="'+p.id+'">'+esc((p.position_name?p.position_name+'　':'')+p.user_cname)+'</option>'; }).join('');
        h += '<div class="co-row" data-i="'+i+'"><span>'+(i+1)+'.</span><select class="coDept" data-eg-filter="輸入部門篩選…">'+depts+'</select>'
          + '<select class="coUser" data-eg-filter="輸入姓名篩選…">'+people+'</select><span class="x" title="移除">✕</span></div>';
    });
    $('#coList').html(h);
    $('#coList .co-row').each(function(){ var c = COSIGN[+$(this).data('i')]; $(this).find('.coDept').val(String(c.dept_id)); $(this).find('.coUser').val(String(c.user_id)); });
    $('#btnAddCo').toggle(COSIGN.length < 5);
    $('#flowHint').text(flowText());
}
$(document).on('change', '.coDept', function(){ var i=+$(this).closest('.co-row').data('i'); COSIGN[i] = {dept_id:+$(this).val(), user_id:0}; renderCosign(); });
$(document).on('change', '.coUser', function(){ var i=+$(this).closest('.co-row').data('i'); COSIGN[i].user_id = +$(this).val(); });
$(document).on('click', '#coList .x', function(){ COSIGN.splice(+$(this).closest('.co-row').data('i'), 1); renderCosign(); });
$('#btnAddCo').on('click', function(){ if (COSIGN.length < 5) { COSIGN.push({dept_id:0, user_id:0}); renderCosign(); } });
$('#fUrgency').on('change', urgHint);

/* 調查來源簽呈：只列已核准且你看得到的簽呈 */
function loadInvestSources(cur){
    $.getJSON(API, {action:'invest_sources'}, function(res){
        var h = '<option value="">（無，一般簽呈）</option>', has = false;
        (res.rows||[]).forEach(function(r){ h += '<option value="'+r.memo_id+'">'+esc((r.memo_no||'')+'　'+r.subject)+'</option>'; if (+r.memo_id===+cur) has = true; });
        if (cur && !has) h += '<option value="'+cur+'">簽呈 #'+cur+'（目前已選）</option>';
        $('#fSrc').html(h).val(cur ? String(cur) : '');
    });
}
$('#btnInvTpl').on('click', function(){
    var sid = +$('#fSrc').val(); if (!sid) { alert('請先選擇調查來源簽呈'); return; }
    if ($.trim($('#fContent').val()) && !confirm('說明欄已有內容，套用格式會覆蓋主旨與說明，確定？')) return;
    $.getJSON(API, {action:'invest_template', src_memo_id:sid}, function(res){
        if (!res.ok) return;
        $('#fSubject').val(res.subject).removeClass('bad'); $('#fContent').val(res.content).removeClass('bad'); $('#eSubject,#eContent').hide();
        if (res.secrecy) $('#fSecrecy').val(res.secrecy);
        alert('已套用格式（帶入 '+res.interview_cnt+' 筆訪談記錄）。請把【待填：…】的欄位補完再送出。');
    });
});
function openEdit(m){
    CUR = m || null;
    $('#editTitle').text(m ? '修改簽呈 ' + (m.memo_no||'') : '新增簽呈');
    $('#fNo').val(m ? m.memo_no : '').prop('readonly', !META.perms.canAdmin).toggleClass('ro-auto', !META.perms.canAdmin);
    $('#fDate').val(m ? m.apply_date : today());
    $('#fUser').val(m ? m.user_name : META.uname);
    $('#fDept').val(m ? m.dept_name : META.identity.dept_name);
    $('#fSecrecy').val(m ? m.secrecy : 'normal');
    $('#fUrgency').val(m ? m.urgency : 'normal');
    fillRouteSel(m ? +m.route_id : 0);
    $('#fSubject').val(m ? m.subject : '');
    $('#fContent').val(m ? (m.content||'') : '');
    loadInvestSources(m ? (+m.src_memo_id||0) : 0);
    COSIGN = m ? (m.cosign||[]).map(function(c){ return {dept_id:+c.dept_id||0, user_id:+c.user_id||0}; }) : [];
    $('.mm-err').hide(); $('.bad').removeClass('bad');
    $('#adminOwner').html(META.perms.canAdmin && !m ? '' : '');
    renderCosign(); urgHint(); openMask('editMask');
}
function validate(){
    var ok = true;
    function bad(sel, es, msg){ $(sel).addClass('bad'); $(es).text(msg).show(); ok = false; }
    $('.bad').removeClass('bad'); $('.mm-err').hide();
    if (!$('#fDate').val()) bad('#fDate', '#eDate', '請選擇簽呈日期');
    if (!$.trim($('#fSubject').val())) bad('#fSubject', '#eSubject', '主旨必填');
    if (!$.trim($('#fContent').val())) bad('#fContent', '#eContent', '說明必填（送出前一定要寫明事由）');
    return ok;
}
$('#fSubject,#fContent,#fDate').on('input change', function(){ $(this).removeClass('bad').next('.mm-err').hide(); });
function saveMemo(thenSubmit){
    if (thenSubmit && !validate()) return;
    if (!thenSubmit && !$.trim($('#fSubject').val())) { validate(); return; }
    var d = {action:'save', memo_id:CUR ? CUR.memo_id : 0, memo_no:$('#fNo').val(), apply_date:$('#fDate').val(), dept_name:$('#fDept').val(),
             position_name:CUR ? CUR.position_name : META.identity.position_name, secrecy:$('#fSecrecy').val(), urgency:$('#fUrgency').val(), route_id:$('#fRoute').val(),
             src_memo_id:$('#fSrc').val() || 0, subject:$('#fSubject').val(), content:$('#fContent').val(), cosign:JSON.stringify(COSIGN.filter(function(c){ return c.dept_id||c.user_id; }))};
    post(d, function(res){
        if (!thenSubmit) { closeMask('editMask'); loadList(); return; }
        post({action:'submit', memo_id:res.memo_id}, function(r2){
            closeMask('editMask'); loadList();
            alert(r2.status === 'approved' ? '已送出（沒有需要批示的關卡，直接核准）' : '已送出簽核，並通知第一關簽核人');
        });
    });
}
$('#btnSaveDraft').on('click', function(){ saveMemo(false); });
$('#btnSaveSubmit').on('click', function(){ saveMemo(true); });
$('#btnNew').on('click', function(){ openEdit(null); });

/* ================= 檢視 ================= */
function stepLine(s){
    var who = s.assignee_name ? esc(s.assignee_name) : '—';
    var lbl = STEP_LABEL[s.step_type] + (s.step_type==='cosign' && s.cosign_dept_name ? '（'+esc(s.cosign_dept_name)+'）' : '');
    return '<div class="stp"><div class="h"><b>'+lbl+'</b><span>'+who+(+s.is_delegated?'（代理）':'')+'</span>'
        + '<span class="st st-'+s.status+'">'+STEP_ST[s.status]+'</span>'
        + (s.decided_date ? '<span class="mm-hint">'+esc(dispDate(s.decided_date))+(s.decided_by_name && s.decided_by_name!==s.assignee_name ? '　由 '+esc(s.decided_by_name)+' 代為批示':'')+'</span>' : '')
        + '</div>' + (s.note ? '<div class="n">'+esc(s.note)+'</div>' : '') + '</div>';
}
/* 調查連結：來源簽呈、相關訪談記錄與其附件、調查結果簽呈（訪談記錄機密，後端只回你看得到的） */
function investHtml(iv){
    if (!iv || (!iv.src && !(iv.interviews||[]).length && !(iv.results||[]).length)) return '';
    var h = '<h5 style="margin:12px 0 5px;color:#8A5A2B;">調查資料連結</h5>';
    if (iv.src) h += '<div class="mm-hint" style="margin-bottom:4px;">調查來源簽呈：'+(iv.src.can ? '<a href="memo_request.php?memo_id='+iv.src.memo_id+'" target="_blank">'+esc(iv.src.memo_no)+'</a> '+esc(iv.src.subject) : esc(iv.src.memo_no)+'（無權限檢視）')+'</div>';
    (iv.results||[]).forEach(function(r){ h += '<div class="mm-hint" style="margin-bottom:4px;">調查結果簽呈：<a href="memo_request.php?memo_id='+r.memo_id+'" target="_blank">'+esc(r.memo_no)+'</a> '+esc(r.subject)+'</div>'; });
    (iv.interviews||[]).forEach(function(r){
        h += '<div class="cmt"><div><i class="fa fa-comments-o"></i> <a href="interview_record.php?iv_id='+r.iv_id+'" target="_blank">'+esc(r.iv_no)+'</a>　'+esc(dispDate(r.iv_date))+'　'+esc(r.who)+'　<span class="mm-hint">'+(r.status==='done'?'已完成':'草稿')+'</span></div>'
           + (r.files.length ? r.files.map(function(f){ return '<div style="margin-left:16px;"><i class="fa fa-paperclip"></i> <a href="../../src/store/Interview_API.php?action=download&id='+f.file_id+'" target="_blank">'+esc(f.name)+'</a></div>'; }).join('') : '<div class="mm-hint" style="margin-left:16px;">（尚無附件）</div>')
           + '</div>';
    });
    return h;
}
$(document).on('click', '#btnToInvest', function(){ window.open('interview_record.php?from_memo='+CUR_VIEW.memo.memo_id, '_blank'); });
function openView(id, thenDecide){
    $.getJSON(API, {action:'get', memo_id:id}, function(res){
        if (!res.ok) return;
        var m = res.memo; CUR_VIEW = res;
        var round = +m.round_no;
        var cur = m.steps.filter(function(s){ return +s.round_no === round; });
        var old = m.steps.filter(function(s){ return +s.round_no !== round; });
        var h = '<div class="grid2"><div><b>編號：</b>'+esc(m.memo_no||'')+'</div><div><b>日期：</b>'+esc(dispDate(m.apply_date))+'</div>'
          + '<div><b>提簽人：</b>'+esc(m.user_name)+'</div><div><b>所屬部門：</b>'+esc(m.dept_name||'')+'</div>'
          + '<div><b>機密等級：</b>'+esc(META.secrecy[m.secrecy])+'</div><div><b>時限：</b>'+esc(META.urgency[m.urgency])
          + (m.due_date && m.status==='submitted' ? '（應於 '+esc(dispDate(m.due_date))+' 前完成）' : '')+'</div></div>'
          + '<div style="margin:8px 0 3px;"><b>主旨：</b>'+esc(m.subject)+' '+statusBadge(m)+'</div>'
          + '<b>說明：</b><div class="view-box">'+esc(m.content||'')+'</div>';
        if (m.status === 'rejected' && m.reject_note) h += '<div style="margin-top:8px;color:#DD5138;"><b>退回原因：</b>'+esc(m.reject_note)+'</div>';
        h += investHtml(res.invest);
        h += '<h5 style="margin:12px 0 5px;color:#8A5A2B;">簽核關卡'+(round>1?'（第 '+round+' 輪）':'')+'</h5>';
        if (cur.length) h += cur.map(stepLine).join('');
        else {
            var rs = META.routes || [], rt = null;
            rs.forEach(function(r){ if (+r.route_id === +m.route_id) rt = r; });
            if (!rt) rs.forEach(function(r){ if (+r.is_default) rt = r; });
            h += '<div class="mm-hint">尚未送出'+(rt ? '　送簽模式：<b>'+esc(rt.route_name)+'</b>　簽核流程：'+esc(routeDesc(rt)) : '')+'</div>';
        }
        if (old.length) h += '<details style="margin-top:6px;"><summary class="mm-hint">前幾輪的簽核紀錄（'+old.length+'）</summary>'+old.map(function(s){ return '<div class="mm-hint">第 '+s.round_no+' 輪</div>'+stepLine(s); }).join('')+'</details>';
        h += '<h5 style="margin:12px 0 5px;color:#8A5A2B;">回覆</h5>';
        h += (res.comments.length ? res.comments.map(function(c){ return '<div class="cmt"><div class="t">'+esc(c.user_name)+'　'+esc(c.created_at)+'</div>'+esc(c.content)+'</div>'; }).join('') : '<div class="mm-hint">還沒有回覆</div>');
        if (m.status !== 'draft') h += '<div style="display:flex;gap:6px;margin-top:6px;"><input type="text" id="cmtText" maxlength="1000" placeholder="輸入回覆，會通知提簽人與各關簽核人" style="flex:1;border:1px solid #D8BE93;border-radius:4px;padding:5px 8px;"><button id="btnCmt" style="height:32px;border:1px solid #d98a33;background:#F0A24B;color:#fff;border-radius:4px;padding:0 14px;cursor:pointer;">送出回覆</button></div>';
        $('#viewTitle').text('簽呈 ' + (m.memo_no||''));
        $('#viewBody').html(h);
        var f = '<button onclick="closeMask(\'viewMask\')">關閉</button><button id="btnPrint"><i class="fa fa-print"></i> 列印</button>';
        if (res.invest && res.invest.can_invest) f += '<button id="btnToInvest" title="帶著這張簽呈開新增訪談記錄（事件調查）"><i class="fa fa-search"></i> 轉為調查</button>';
        if (res.can_delete) f +='<button class="b-danger" id="btnDel">刪除</button>';
        if (res.can_withdraw) f += '<button id="btnWd">撤回</button>';
        if (res.can_edit) f += '<button id="btnEd">修改</button><button class="b-ok" id="btnReSubmit">送出</button>';
        if (res.my_step) f += '<button class="b-ok" id="btnDecide">批示</button>';
        $('#viewFoot').html(f);
        openMask('viewMask');
        if (thenDecide && res.my_step) openDecide();
    });
}
$(document).on('click', '#btnCmt', function(){
    var t = $.trim($('#cmtText').val()); if (!t) return;
    post({action:'comment', memo_id:CUR_VIEW.memo.memo_id, content:t}, function(){ openView(CUR_VIEW.memo.memo_id); });
});
$(document).on('click', '#btnEd', function(){ closeMask('viewMask'); openEdit(CUR_VIEW.memo); });
$(document).on('click', '#btnReSubmit', function(){
    if (!confirm('確定送出簽核？')) return;
    post({action:'submit', memo_id:CUR_VIEW.memo.memo_id}, function(){ closeMask('viewMask'); loadList(); });
});
$(document).on('click', '#btnWd', function(){
    if (!confirm('撤回後簽呈退回草稿，可修改後重新送出。確定撤回？')) return;
    post({action:'withdraw', memo_id:CUR_VIEW.memo.memo_id}, function(){ closeMask('viewMask'); loadList(); });
});
$(document).on('click', '#btnDel', function(){
    if (!confirm('確定刪除這張簽呈？')) return;
    post({action:'delete', memo_id:CUR_VIEW.memo.memo_id}, function(){ closeMask('viewMask'); loadList(); });
});

/* ================= 批示 ================= */
function openDecide(){
    var m = CUR_VIEW.memo, st = m.steps.filter(function(s){ return +s.step_id === +CUR_VIEW.my_step; })[0];
    $('#decTitle').text(STEP_LABEL[st.step_type] + (st.cosign_dept_name ? '（'+st.cosign_dept_name+'）' : ''));
    $('#decInfo').html('簽呈：<b>'+esc(m.subject)+'</b>（'+esc(m.user_name)+'）');
    $('#decDate').val(today()); $('#decNote').val('').removeClass('bad'); $('#eDec').hide();
    openMask('decideMask');
}
$(document).on('click', '#btnDecide', openDecide);
function decide(dec){
    var note = $.trim($('#decNote').val());
    if (dec === 'rejected' && !note) { $('#decNote').addClass('bad'); $('#eDec').text('退回必須填寫原因').show(); return; }
    post({action:'decide', memo_id:CUR_VIEW.memo.memo_id, step_id:CUR_VIEW.my_step, decision:dec, note:note, decide_date:$('#decDate').val()}, function(){
        closeMask('decideMask'); closeMask('viewMask'); loadList();
    });
}
$('#btnApprove').on('click', function(){ decide('approved'); });
$('#btnReject').on('click', function(){ decide('rejected'); });
$('#decNote').on('input', function(){ $(this).removeClass('bad'); $('#eDec').hide(); });

/* ================= 列印（版面比照紙本 2-MM-01-15） ================= */
$(document).on('click', '#btnPrint', function(){ printMemo(CUR_VIEW.memo.memo_id); });
function printMemo(id){
    $.getJSON(API, {action:'print_meta', memo_id:id}, function(res){
        if (!res.ok) { alert(res.error||'讀取失敗'); return; }
        window.__ownCompany = res.company || window.__ownCompany || '';
        var w = window.open('', '_blank');
        if (!w) { alert('請允許彈出視窗'); return; }
        w.document.write(printHtml(res)); w.document.close();
        if (window.EGPrintLog) EGPrintLog.record({source:'memo_request', doc_kind:'form', doc_name:'簽呈 '+(res.memo.memo_no||''), ref_table:'memo_request', ref_id:id});
    });
}
function printHtml(res){
    var m = res.memo, round = +m.round_no;
    var steps = m.steps.filter(function(s){ return +s.round_no === round; });
    var ymd = String(m.apply_date||'').split('-');
    function box(arr){
        return arr.map(function(s){
            var ok = s.status === 'approved';
            return '<div class="sgn">' + (s.note ? '<div class="nt">'+esc(s.note)+'</div>' : '')
                 + (ok ? '<div class="stp2">'+stampHtml(s.assignee_name, dispDate(s.decided_date), +s.is_delegated)+'</div>' : '')
                 + (s.status==='rejected' ? '<div class="nt">（退回）</div>' : '') + '</div>';
        }).join('');
    }
    var dept = steps.filter(function(s){ return s.step_type==='dept'; });
    var gm = steps.filter(function(s){ return s.step_type==='gm'; });
    var co = steps.filter(function(s){ return s.step_type==='cosign'; });
    var chk = function(on){ return '<span class="cb">'+(on?'☑':'☐')+'</span>'; };
    var secs = {normal:'一般', secret:'機密', top_secret:'極機密'}, urgs = {normal:'普通', urgent:'速件', rush:'最速件'};
    var coRows = '', coNote = '';
    for (var i=0;i<5;i++) {
        var s = co[i];
        coRows += '<div class="cr">'+(i+1)+'. '+(s ? esc((s.cosign_dept_name||'')+(s.assignee_name?'　'+s.assignee_name:'')) : '')+'</div>';
        coNote += '<div class="cr2">'+(i+1)+'. '+(s ? '<span class="nt2">'+esc(s.note||'')+'</span>'+(s.status==='approved'?'<span class="stp3">'+stampHtml(s.assignee_name, dispDate(s.decided_date), +s.is_delegated)+'</span>':'') : '')+'</div>';
    }
    var css = '@page{size:A4 portrait;margin:0;}html,body{margin:0;padding:0;}'
      + 'body{font-family:"Microsoft JhengHei","微軟正黑體",sans-serif;color:#000;padding:14mm 14mm 12mm;-webkit-print-color-adjust:exact;print-color-adjust:exact;}'
      + '.hd{text-align:center;}.hd .co{font-size:23px;font-weight:bold;letter-spacing:3px;}'
      + '.hd .tt{display:inline-block;font-size:24px;font-weight:bold;letter-spacing:22px;padding:0 0 3px 22px;margin-top:8px;}'
      + '.meta{display:flex;justify-content:space-between;font-size:14px;margin:8px 2px 4px;}'
      + 'table.f{width:100%;border-collapse:collapse;table-layout:fixed;border:1.6px solid #000;}'
      + 'table.f td{border:1px solid #000;padding:5px 9px;font-size:15px;vertical-align:middle;word-break:break-all;}'
      + 'td.lb{width:27mm;background:#F5EEE3;text-align:center;font-weight:bold;letter-spacing:4px;}'
      + 'td.tall{height:62mm;vertical-align:top;white-space:pre-wrap;line-height:1.8;}'
      + 'td.sgc{height:24mm;vertical-align:top;}'
      + '.sgn{display:flex;align-items:center;justify-content:space-between;gap:6px;}.sgn .nt{flex:1;white-space:pre-wrap;font-size:14px;line-height:1.6;}'
      + '.stp2,.stp3{flex:none;}.stp-wrap,.stamp-wrap{height:auto !important;display:inline-flex;}'
      + '.stamp-wrap svg,svg.car-stamp{width:91px;height:91px;-webkit-print-color-adjust:exact;print-color-adjust:exact;}'
      + '.cr{height:11mm;line-height:11mm;padding:0;}.cr2{min-height:11mm;display:flex;align-items:center;justify-content:space-between;gap:6px;}'
      + '.nt2{font-size:14px;white-space:pre-wrap;}.cb{font-size:17px;}'
      + '.note{font-size:12.5px;margin-top:8px;text-align:center;}'
      + '.docno{position:fixed;right:14mm;bottom:8mm;font-size:10pt;color:#333;}';
    var body = '<div class="hd"><div class="co">'+esc(res.company)+'</div><div class="tt">'+esc(res.doc_name||'簽呈')+'</div></div>'
      + '<div class="meta"><span>編號：'+esc(m.memo_no||'')+'</span><span>日期：'+esc(ymd[0]||'')+' 年 '+esc(ymd[1]||'')+' 月 '+esc(ymd[2]||'')+' 日</span></div>'
      + '<table class="f">'
      + '<tr><td class="lb">提簽人</td><td>'+esc(m.user_name)+'</td><td class="lb">所屬部門</td><td>'+esc(m.dept_name||'')+'</td></tr>'
      + '<tr><td class="lb">機密等級</td><td>'+chk(m.secrecy==='normal')+'一般 '+chk(m.secrecy==='secret')+'機密 '+chk(m.secrecy==='top_secret')+'極機密</td>'
      +   '<td class="lb">時限</td><td>'+chk(m.urgency==='normal')+'普通 '+chk(m.urgency==='urgent')+'速件 '+chk(m.urgency==='rush')+'最速件</td></tr>'
      + '<tr><td class="lb">主　旨</td><td colspan="3">'+esc(m.subject)+'</td></tr>'
      + '<tr><td class="lb">說　明</td><td colspan="3" class="tall">'+esc(m.content||'')+'</td></tr>'
      + '<tr><td class="lb">部門主管批示</td><td colspan="3" class="sgc">'+box(dept)+'</td></tr>'
      + '<tr><td class="lb">總經理批示</td><td colspan="3" class="sgc">'+box(gm)+'</td></tr>'
      + '<tr><td class="lb">會辦單位</td><td colspan="3" style="padding:0 9px;">'+coRows+'</td></tr>'
      + '<tr><td class="lb">會辦單位批示</td><td colspan="3" style="padding:0 9px;">'+coNote+'</td></tr>'
      + '</table><div class="note">【注意事項】時限：普通三天內完成，速件兩天內完成，最速件當天完成。</div>'
      + (res.doc_no ? '<div class="docno">'+esc(res.doc_no)+'</div>' : '');
    return '<!DOCTYPE html><html><head><meta charset="utf-8"><title>'+esc(res.doc_name||'簽呈')+'</title><style>'+css+'</style></head><body>'+body
         + '<scr'+'ipt>window.onload=function(){setTimeout(function(){window.print();},200);};</scr'+'ipt></body></html>';
}

/* ================= 送簽模式 ================= */
var ROUTES = [];
function routeDesc(r){ return (+r.need_dept ? '部門主管 → ' : '') + (+r.need_gm ? '總經理' : '（不需總經理）') + (+r.allow_cosign ? '，可會辦' : '，不可會辦'); }
function fillRouteSel(cur){
    var rs = META.routes || [], h = '', def = 0;
    rs.forEach(function(r){ if (+r.is_default) def = +r.route_id; });
    rs.forEach(function(r){ h += '<option value="'+r.route_id+'">'+esc(r.route_name)+'</option>'; });
    $('#fRoute').html(h).val(String(cur && rs.some(function(r){ return +r.route_id===cur; }) ? cur : def));
    $('#fRoute').off('change.rt').on('change.rt', routeHint); routeHint();
}
function routeHint(){ var id = +$('#fRoute').val(), t=''; (META.routes||[]).forEach(function(r){ if (+r.route_id===id) t = '簽核流程：' + routeDesc(r); }); $('#routeHint').text(t); }
function renderRoutes(){
    var h = '';
    ROUTES.forEach(function(r, i){
        h += '<div class="rt-row" data-i="'+i+'" style="border:1px solid #e6d3b3;border-radius:4px;padding:6px;margin:4px 0">'
           + '<input type="text" class="rt-name" maxlength="60" value="'+esc(r.route_name||'')+'" placeholder="模式名稱">'
           + '<label style="font-weight:normal;margin-right:10px"><input type="checkbox" class="rt-nd"'+(+r.need_dept?' checked':'')+'> 部門主管</label>'
           + '<label style="font-weight:normal;margin-right:10px"><input type="checkbox" class="rt-ng"'+(+r.need_gm?' checked':'')+'> 總經理</label>'
           + '<label style="font-weight:normal;margin-right:10px"><input type="checkbox" class="rt-ac"'+(+r.allow_cosign?' checked':'')+'> 可會辦</label>'
           + '<label style="font-weight:normal;margin-right:10px"><input type="checkbox" class="rt-ia"'+(+r.is_active?' checked':'')+'> 啟用</label>'
           + '<label style="font-weight:normal;margin-right:10px"><input type="radio" name="rtDef" class="rt-df"'+(+r.is_default?' checked':'')+'> 預設</label>'
           + '<a href="#" class="rt-del">移除</a></div>';
    });
    $('#routeBox').html(h);
}
function collectRoutes(){
    return $('#routeBox .rt-row').map(function(){
        var $r = $(this), o = ROUTES[+$r.data('i')] || {};
        return {route_id:+o.route_id||0, route_name:$r.find('.rt-name').val(), need_dept:$r.find('.rt-nd').is(':checked')?1:0, need_gm:$r.find('.rt-ng').is(':checked')?1:0,
                allow_cosign:$r.find('.rt-ac').is(':checked')?1:0, is_active:$r.find('.rt-ia').is(':checked')?1:0, is_default:$r.find('.rt-df').is(':checked')?1:0};
    }).get();
}
$(document).on('click', '#routeBox .rt-del', function(e){ e.preventDefault(); ROUTES = collectRoutes(); ROUTES.splice(+$(this).closest('.rt-row').data('i'), 1); renderRoutes(); });
$('#btnAddRoute').on('click', function(){ ROUTES = collectRoutes(); ROUTES.push({route_id:0, route_name:'', need_dept:1, need_gm:1, allow_cosign:1, is_active:1, is_default:0}); renderRoutes(); });

/* ================= 設定（管理員） ================= */
function fillSettingUI(res){
    var s = res.settings || {};
    [['#setHrLv','memo_view_hr_levels'],['#setSupLv','memo_view_sup_levels']].forEach(function(x){
        var h = '', k; for (k in META.secrecy) h += '<label style="font-weight:normal;margin-right:14px"><input type="checkbox" value="'+k+'"'+((s[x[1]]||[]).indexOf(k)>=0?' checked':'')+'> '+esc(META.secrecy[k])+'</label>';
        $(x[0]).html(h);
    });
    ROUTES = (res.routes||[]).map(function(r){ return $.extend({}, r); }); renderRoutes();
    $('#setNeedGm').val(String(s.memo_need_gm)); $('#setOrder').val(String(s.memo_cosign_before_gm));
    var th = '<option value="0">（預設圓形圖章）</option>';
    (res.stamp_tpls||[]).forEach(function(t){ th += '<option value="'+t.id+'">'+esc(t.tpl_name)+'</option>'; });
    $('#setStampTpl').html(th).val(String(s.memo_stamp_tpl_id || 0));
    $('#setAsDoc').val(res.as_doc ? EGAsDoc.label(res.as_doc) : '');
}
$('#btnSetting').on('click', function(){ openMask('setMask'); });
$('#btnPickAsDoc').on('click', function(){
    EGAsDoc.open({docs:(META&&META.as_docs)||[], current:(META&&META.as_doc)?META.as_doc.id:0, title:'簽呈 — AS 文件編號綁定',
        onSave:function(id){ post({action:'save_asdoc', doc_id:id||0}, function(res){ $('#setAsDoc').val(res.as_doc ? EGAsDoc.label(res.as_doc) : ''); loadMeta(); }); }});
});
$('#btnClearAsDoc').on('click', function(){ post({action:'save_asdoc', doc_id:0}, function(){ $('#setAsDoc').val(''); loadMeta(); }); });
$('#btnSaveSet').on('click', function(){
    post({action:'save_settings', memo_need_gm:$('#setNeedGm').val(), memo_cosign_before_gm:$('#setOrder').val(), memo_stamp_tpl_id:$('#setStampTpl').val(), routes:JSON.stringify(collectRoutes()),
         memo_view_hr_levels:$('#setHrLv input:checked').map(function(){return this.value;}).get().join(','),
         memo_view_sup_levels:$('#setSupLv input:checked').map(function(){return this.value;}).get().join(',')},
        function(){ alert('設定已儲存'); closeMask('setMask'); loadMeta(); });
});
$('#btnPageHelp').on('click', function(){ openMask('helpUseMask'); });

/* ================= 啟動：支援通知深連結 ?memo_id=（檢視）／?memo_approve=（直接開批示） ================= */
(function(){
    var q = new URLSearchParams(location.search);
    loadMeta(function(){
        var ap = +q.get('memo_approve'), vi = +q.get('memo_id');
        if (ap) { $('#scopeSel').val('involved'); loadList(); openView(ap, true); }
        else if (vi) { $('#scopeSel').val('involved'); loadList(); openView(vi); }
        else { loadList(); }
    });
})();
</script>
</body>
</html>
