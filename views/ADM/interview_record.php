<?php
/**
 * 訪談記錄 — 行政人事紀錄（事件調查／新進員工關心／在職員工定期關心）
 * 不屬 AS9100 品質文件、不綁 AS 文件編號，與「利害關係者溝通記錄」完全分開。
 * 訪談中員工反映的作業困難可整理成去識別化的「改善事項」轉入溝通管理的措施追蹤表。
 * 資料一律走 src/store/Interview_API.php；共用邏輯 src/common/interview_lib.php
 */
session_start();
if (!isset($_SESSION['userName'])) {
    $_SESSION['lastpage'] = "../../views/ADM/interview_record.php";
    header("Location:../../index.php");
    exit;
}
include_once '../../src/common/_config.php';
include_once '../../src/common/DBConnection.php';
include_once '../../src/common/interview_lib.php';

$db = (new DBConnection())->getPDO();
iv_ensure_schema($db);
$mUser = memo_current_user($db);
$P = iv_perms($db, $mUser);
$roleLabel = $P['isAdmin'] ? '管理者' : ($P['canAdmin'] ? '訪談記錄管理員' : ($P['isHr'] ? '人事部門（可看全部）' : '一般員工（只看自己經手的）'));
?>
<!DOCTYPE html>
<html lang="zh-Hant">
<head>
    <meta charset="utf-8">
    <meta http-equiv="X-UA-Compatible" content="IE=edge">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>訪談記錄</title>
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
        .iv-toolbar { display:flex; flex-wrap:wrap; gap:6px; align-items:center; clear:both; border:1.5px solid #E8D5B5; border-radius:8px; padding:8px 10px; margin-bottom:10px; background:#FDF8EF; }
        .iv-toolbar label { margin:0; font-size:13px; color:#5b3a1e; }
        .iv-toolbar select, .iv-toolbar input, .iv-toolbar button { height:30px; font-size:13px; line-height:1; padding:0 10px; border:1px solid #D8BE93; border-radius:4px; background:#fff; color:#5b3a1e; }
        .iv-toolbar button { cursor:pointer; }
        .iv-toolbar button:hover { background:#F7E0BD; }
        .iv-toolbar .btn-warm { background:#F0A24B; color:#fff; border-color:#d98a33; }
        .iv-role-badge { margin-left:auto; font-size:13px; color:#5b3a1e; background:#F7E0BD; border-radius:12px; padding:4px 12px; }
        .iv-wrap { overflow-x:auto; border:1px solid #E8D5B5; border-radius:6px; background:#fff; }
        table.iv-table { width:100%; border-collapse:collapse; font-size:13px; }
        table.iv-table th, table.iv-table td { border:1px solid #EADFC8; padding:5px 8px; text-align:center; }
        table.iv-table thead th { background:#F7E0BD; color:#5b3a1e; font-weight:bold; white-space:nowrap; }
        table.iv-table tbody tr:nth-child(even) { background:#FBF6EC; }
        table.iv-table tr.clk { cursor:pointer; }
        table.iv-table tr.clk:hover { background:#F7E0BD; }
        .iv-pager { display:flex; justify-content:flex-end; align-items:center; gap:6px; margin:6px 0; font-size:13px; color:#5b3a1e; }
        .iv-pager button { height:26px; padding:0 9px; border:1px solid #D8BE93; background:#fff; border-radius:4px; cursor:pointer; }
        .iv-pager button.on { background:#F0A24B; color:#fff; border-color:#d98a33; }
        .st { display:inline-block; padding:1px 9px; border-radius:10px; font-size:12px; line-height:18px; }
        .st-draft { background:#EFE7D8; color:#6b5535; }
        .st-done { background:#F0A24B; color:#fff; }
        .st-late { background:#DD5138; color:#fff; }
        .iv-mask { display:none; position:fixed; inset:0; background:rgba(60,40,20,.45); z-index:9000; overflow:auto; }
        .iv-modal { background:#fff; border-radius:8px; margin:40px auto; max-width:860px; width:94%; box-shadow:0 8px 30px rgba(0,0,0,.3); }
        .iv-modal.narrow { max-width:560px; }
        .m-head { background:#F7E0BD; color:#5b3a1e; font-weight:bold; padding:9px 14px; border-radius:8px 8px 0 0; display:flex; }
        .m-close { margin-left:auto; cursor:pointer; }
        .m-body { padding:14px; max-height:72vh; overflow:auto; }
        .m-foot { padding:10px 14px; border-top:1px solid #EADFC8; text-align:right; }
        .m-foot button { height:32px; padding:0 14px; border-radius:4px; border:1px solid #D8BE93; background:#fff; color:#5b3a1e; cursor:pointer; margin-left:6px; }
        .m-foot .b-ok { background:#F0A24B; color:#fff; border-color:#d98a33; }
        .m-foot .b-danger { background:#DD5138; color:#fff; border-color:#C4442D; }
        .grid2 { display:grid; grid-template-columns:1fr 1fr; gap:10px; }
        .grid3 { display:grid; grid-template-columns:1fr 1fr 1fr; gap:10px; }
        .m-body label { display:block; font-size:13px; color:#5b3a1e; margin:6px 0 3px; font-weight:normal; }
        .m-body input[type=text], .m-body input[type=date], .m-body select, .m-body textarea { width:100%; border:1px solid #D8BE93; border-radius:4px; padding:5px 8px; font-size:13px; color:#5b3a1e; background:#fff; }
        .m-body textarea { resize:vertical; }
        .iv-hint { font-size:12px; color:#8a6d45; line-height:1.7; }
        .iv-err { color:#DD5138; font-size:12px; margin-top:2px; display:none; }
        .bad { border-color:#DD5138 !important; background:#FFF3EF !important; }
        .view-box { border:1px solid #EADFC8; border-radius:6px; padding:8px 12px; background:#fff; white-space:pre-wrap; font-size:14px; line-height:1.8; color:#3d2a14; min-height:50px; }
        .imp { border:1px solid #EADFC8; border-radius:6px; padding:6px 10px; margin-bottom:6px; background:#FDF9F1; font-size:13px; color:#5b3a1e; }
        .imp .a { margin-top:4px; }
        .imp button { height:26px; font-size:12px; border:1px solid #D8BE93; background:#fff; border-radius:4px; cursor:pointer; padding:0 9px; margin-right:4px; color:#5b3a1e; }
        .imp-form { border:1.5px dashed #D8BE93; border-radius:6px; padding:8px; margin-top:6px; background:#FFFBF2; }
        .iv-noperm { border:1.5px solid #E8D5B5; background:#FDF8EF; border-radius:8px; padding:24px; color:#5b3a1e; }
        .tp-row { display:grid; grid-template-columns:1fr 90px 90px 60px 28px; gap:6px; align-items:center; margin-bottom:5px; font-size:12px; }
        .tp-row .x { cursor:pointer; color:#DD5138; text-align:center; }
        .fl { display:flex; align-items:center; gap:8px; border:1px solid #EADFC8; border-radius:6px; padding:4px 10px; margin-bottom:4px; background:#FDF9F1; font-size:13px; }
        .fl a { color:#8A5A2B; text-decoration:underline; }
        .fl .x { margin-left:auto; color:#DD5138; cursor:pointer; }
        .sec { margin:14px 0 5px; color:#8A5A2B; font-weight:bold; }
    </style>
</head>
<body class="nav-sm">
<div class="container body">
<div class="main_container">
    <?php include '../partPage/sideAndTopBarMenu.html' ?>
    <div class="right_col" role="main">
        <div class="page-title" style="display:flex;align-items:center;flex-wrap:wrap;">
            <h2 style="margin:6px 0;">訪談記錄 <small style="color:#8a6d45;">行政人事紀錄（不屬品質文件，與利害關係者溝通記錄分開）</small></h2>
            <button id="btnPageHelp" class="page-help-btn" style="margin-left:auto;"><i class="fa fa-question-circle"></i> 使用說明</button>
        </div>
        <div class="clearfix"></div>
<?php if (!$P['canUse']): ?>
        <div class="iv-noperm"><h4><i class="fa fa-lock"></i> 無使用權限</h4><p>此帳號目前不是在職狀態，無法使用訪談記錄。</p></div>
<?php else: ?>
        <div class="iv-toolbar">
            <label>類型</label><select id="fltType"></select>
            <label>狀態</label><select id="fltStatus"><option value="">全部</option><option value="draft">草稿</option><option value="done">已完成</option></select>
            <label><input type="checkbox" id="fltDue" style="height:auto;"> 只看已到下次約談日</label>
            <input type="text" id="fltKw" placeholder="編號／姓名／部門" style="width:150px;">
            <button id="btnSearch"><i class="fa fa-search"></i> 查詢</button>
            <button id="btnNew" class="btn-warm"><i class="fa fa-plus"></i> 新增訪談記錄</button>
<?php if ($P['canAdmin']): ?>            <button id="btnTypes"><i class="fa fa-cog"></i> 類型與地點設定</button>
<?php endif; ?>
            <span class="iv-role-badge">目前角色：<?= htmlspecialchars($roleLabel) ?></span>
        </div>
        <div class="iv-pager" id="pagerTop"></div>
        <div class="iv-wrap">
            <table class="iv-table">
                <thead><tr><th>編號</th><th>日期</th><th>類型</th><th>關聯簽呈</th><th>受訪人</th><th>訪談人</th><th>狀態</th><th>下次約談</th><th>改善事項</th></tr></thead>
                <tbody id="listBody"><tr><td colspan="8" class="iv-hint">載入中…</td></tr></tbody>
            </table>
        </div>
        <div class="iv-pager" id="pagerBot"></div>
<?php endif; ?>
    </div>
</div>
</div>

<!-- 編輯 -->
<div class="iv-mask" id="editMask"><div class="iv-modal">
    <div class="m-head"><span id="editTitle">新增訪談記錄</span><span class="m-close" onclick="closeMask('editMask')">✕</span></div>
    <div class="m-body">
        <div class="grid3">
            <div><label>訪談類型 *</label><select id="fType"></select></div>
            <div><label>訪談日期 *</label><input type="date" id="fDate"></div>
            <div><label>地點</label><select id="fPlace"></select></div>
        </div>
        <div class="grid2">
            <div><label>開始時間</label><input type="text" id="fStart" maxlength="5" data-eg-hint="輸入 0900 或 9:00"></div>
            <div><label>結束時間</label><input type="text" id="fEnd" maxlength="5" data-eg-hint="輸入 1000 或 10:00"></div>
        </div>
        <div class="iv-err" id="eTime"></div>
        <div class="grid2">
            <div><label>訪談人 *（先選部門）</label><select id="fIntvDept"></select><select id="fIntv" style="margin-top:4px;"></select></div>
            <div><label>受訪人 *（先選部門）</label><select id="fInteeDept"></select><select id="fIntee" style="margin-top:4px;"></select></div>
        </div>
        <div class="iv-err" id="ePeople"></div>
        <div id="memoBox" style="display:none;">
            <label>關聯簽呈 *（只列出你看得到的簽呈）</label>
            <select id="fMemo" data-eg-filter="輸入簽呈編號或主旨篩選…"></select>
            <div class="iv-err" id="eMemo"></div>
        </div>
        <label>談話內容 *（機密；只有訪談人、人事部門與管理員看得到）</label>
        <div id="guideBox" style="display:none;margin-bottom:4px;"><button type="button" id="btnGuide" style="height:26px;font-size:12px;border:1px solid #D8BE93;background:#fff;border-radius:4px;cursor:pointer;padding:0 10px;color:#5b3a1e;">插入引導問題</button> <span class="iv-hint">選用：把建議問題貼進下方，照著補答案；不需要可不點、或貼入後自行刪改。</span></div>
        <textarea id="fContent" rows="10"></textarea>
        <label>後續處理／追蹤</label>
        <textarea id="fFollow" rows="3"></textarea>
        <div id="nextBox"><label>下次約談日</label><input type="date" id="fNext"><div class="iv-err" id="eNext"></div></div>
        <div class="iv-hint" style="margin-top:6px;">改善事項（員工反映的作業環境／作業困難）請存檔後，在檢視畫面另外填寫並轉入追蹤。</div>
    </div>
    <div class="m-foot"><button onclick="closeMask('editMask')">取消</button><button class="b-ok" id="btnSave">儲存草稿</button></div>
</div></div>

<!-- 檢視 -->
<div class="iv-mask" id="viewMask"><div class="iv-modal">
    <div class="m-head"><span id="viewTitle">訪談記錄</span><span class="m-close" onclick="closeMask('viewMask')">✕</span></div>
    <div class="m-body" id="viewBody"></div>
    <div class="m-foot" id="viewFoot"></div>
</div></div>

<!-- 轉追蹤 -->
<div class="iv-mask" id="trackMask"><div class="iv-modal narrow">
    <div class="m-head"><span>轉入措施追蹤表</span><span class="m-close" onclick="closeMask('trackMask')">✕</span></div>
    <div class="m-body">
        <div class="iv-hint">轉入後這一項會出現在「溝通管理 → 措施追蹤」（類別：員工），由負責人追蹤到結案。訪談原文不會轉過去，只轉下面這段去識別化的描述。</div>
        <label>改善事項</label><div class="view-box" id="tkContent"></div>
        <label>負責人 *（先選部門）</label><select id="tkOwnerDept"></select><select id="tkOwner" style="margin-top:4px;"></select>
        <label>預計完成日 *</label><input type="date" id="tkDue">
        <label>預擬回應措施（可留空，之後在追蹤表補）</label><textarea id="tkAction" rows="3"></textarea>
        <div class="iv-err" id="eTk"></div>
    </div>
    <div class="m-foot"><button onclick="closeMask('trackMask')">取消</button><button class="b-ok" id="btnTrackOk">轉入追蹤表</button></div>
</div></div>

<!-- 類型設定 -->
<div class="iv-mask" id="typeMask"><div class="iv-modal narrow">
    <div class="m-head"><span>訪談類型與地點設定</span><span class="m-close" onclick="closeMask('typeMask')">✕</span></div>
    <div class="m-body">
        <div class="iv-hint">「須綁簽呈」＝這種訪談一定要關聯一張簽呈（例如事件調查）。「排下次約談」＝顯示下次約談日欄位，到期會在清單標示。已有記錄的類型不能刪除，移除時只會停用。</div>
        <div class="sec">地點選單</div>
        <div class="iv-hint">填單時的地點下拉就是這份清單（填單的人也能直接按「＋新增地點…」加一個）。取消勾選＝不再出現在新單。與會議紀錄共用同一份；已被單據使用的地點不可改名或刪除（只能停用）。</div>
        <div id="placeBox"></div>
        <button type="button" id="btnAddPlace" style="margin:4px 0 10px;height:28px;border:1px solid #D8BE93;background:#fff;border-radius:4px;cursor:pointer;">＋ 新增地點</button>
        <div class="sec">訪談類型</div>
        <div class="tp-row" style="font-weight:bold;"><span>名稱</span><span>須綁簽呈</span><span>排下次約談</span><span>啟用</span><span></span></div>
        <div id="typeBox"></div>
        <button type="button" id="btnAddType" style="margin-top:6px;height:28px;border:1px solid #D8BE93;background:#fff;border-radius:4px;cursor:pointer;">＋ 新增類型</button>
    </div>
    <div class="m-foot"><button onclick="closeMask('typeMask')">取消</button><button class="b-ok" id="btnTypeOk">儲存</button></div>
</div></div>

<!-- 使用說明 -->
<div class="iv-mask" id="helpUseMask"><div class="iv-modal">
    <div class="m-head"><span>使用說明　訪談記錄</span><span class="m-close" onclick="closeMask('helpUseMask')">✕</span></div>
    <div class="m-body help-doc">
        <h4>這一頁是做什麼的</h4>
        <p>記錄人事面談：<b>事件調查</b>（例如現場員工衝突調查中的談話）、<b>新進員工關心</b>、<b>在職員工定期關心</b>。類型可由管理員增減。</p>
        <p>這是<b>行政人事紀錄</b>，<b>不編入 AS9100 品質文件清單，也不綁 AS 文件編號</b>，與「利害關係者溝通記錄」完全分開——個別員工的談話內容不應出現在品質系統的溝通紀錄裡。</p>
        <h4>誰看得到</h4>
        <p>只有<b>訪談人、建立人、人事部門（組織角色綁定的人事部門成員）、訪談記錄管理員、全站管理員</b>。其他人完全看不到。</p>
        <h4>事件調查要綁簽呈</h4>
        <p>事件調查必須關聯一張簽呈（只列你本來就看得到的簽呈）。已核准的啟動調查簽呈，可在簽呈檢視畫面按「轉為調查」，直接帶著簽呈開新增訪談。調查結論與建議寫在簽呈裡請主管批示；每位當事人／在場人員各一份訪談記錄。</p>
        <h4>附件（簽章結果）</h4>
        <p>列印訪談記錄、訪談人與受訪人簽名後，把掃描檔在檢視畫面的「附件」上傳（已完成的記錄也可以）。只有看得到這筆訪談記錄的人才下載得到。調查結果簽呈會連到相關訪談記錄與這些附件。</p>
        <h4>改善事項怎麼處理</h4>
        <p>訪談中員工反映的作業環境或作業困難，若需要改善，請在檢視畫面的「改善事項」填寫<b>不含個人資料的客觀描述</b>（不可含受訪人姓名），再按「轉入追蹤表」指定負責人與預計完成日，就會進入溝通管理的措施追蹤（類別：員工）。<b>談話原文不會轉過去。</b></p>
        <h4>狀態與下次約談</h4>
        <p>草稿可修改；「完成」後鎖定（只有管理員能重新開啟）。類型有「排下次約談」時，到期（今天已到或過了下次約談日）的記錄在清單標紅，可用「只看已到下次約談日」篩出。目前只在清單標示，<b>沒有自動發通知</b>。</p>
        <h4>列印</h4>
        <p>列印版含受訪人與訪談人簽名欄（供手寫簽名確認）。</p>
        <h4>權限</h4>
        <p>所有在職員工都能新增自己的訪談記錄並看自己經手的。<b>訪談記錄管理員</b>角色可看全部、重新開啟已完成記錄、刪除、設定訪談類型（在使用者權限設定頁指派）。</p>
    </div>
    <div class="m-foot"><button onclick="closeMask('helpUseMask')">關閉</button></div>
</div></div>

<script src="../../resource/js/jquery.min.js"></script>
<script src="../../resource/js/bootstrap.min.js"></script>
<script src="../../resource/js/fastclick.js"></script>
<script src="../../resource/js/nprogress.js"></script>
<script src="../../resource/js/custom.min.js"></script>
<script src="../../resource/js/eg_date_fmt.js?v=<?= @filemtime(__DIR__.'/../../resource/js/eg_date_fmt.js') ?>"></script>
<script src="../../resource/js/eg_print_log.js?v=<?= @filemtime(__DIR__.'/../../resource/js/eg_print_log.js') ?>"></script>
<script src="../../resource/js/eg_input_rules.js?v=<?= @filemtime(__DIR__.'/../../resource/js/eg_input_rules.js') ?>"></script>
<script>
$(document).ready(function(){
    var $am = $('#sidebar-menu .nav.side-menu > li.active');
    if ($am.length) { $am.removeClass('active').find('ul.child_menu').hide(); $am.find('li.current-page').removeClass('current-page'); }
    $('#sidebar-menu').css('visibility','visible');
});

var API = '../../src/store/Interview_API.php';
var META = null, PAGE = 1, CUR_ID = 0, CUR = null, CUR_MEMO = 0, TK_IMP = 0, TYPES_EDIT = [];

function esc(s){ return String(s==null?'':s).replace(/[&<>"']/g,function(c){ return {'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#39;'}[c]; }); }
function dispDate(d){ return d ? egFmtDate(d) : ''; }
function openMask(id){ document.getElementById(id).style.display='block'; }
function closeMask(id){ document.getElementById(id).style.display='none'; }
function today(){ var d=new Date(); return d.getFullYear()+'-'+('0'+(d.getMonth()+1)).slice(-2)+'-'+('0'+d.getDate()).slice(-2); }
function post(data, ok){
    data.csrf = META ? META.csrf : '';
    $.post(API, data, function(res){ if (!res.ok) { alert(res.error||'操作失敗'); return; } ok(res); }, 'json');
}
$(document).ajaxError(function(e, x){
    var m = (x.responseJSON && x.responseJSON.error) || ('HTTP '+x.status);
    if (x.status === 403 && /CSRF/.test(m)) { loadMeta(); m += '（已更新憑證，請再按一次）'; }
    alert('操作失敗：'+m);
});
function typeById(id){ var t=null; (META.types||[]).forEach(function(x){ if (+x.type_id===+id) t=x; }); return t; }
/* 先選部門才列該部門的人（兼任者在各自的部門下各出現一次）。deptSel＝部門下拉、sel＝人員下拉 */
var PAIRS = {};
function pairInit(deptSel, sel){
    PAIRS[deptSel] = sel;
    var seen = {}, ds = [];
    (META.people||[]).forEach(function(p){ if (!seen[p.dept_id]) { seen[p.dept_id]=1; ds.push(p); } });
    ds.sort(function(a,b){ return (a.dsort-b.dsort) || String(a.dept).localeCompare(String(b.dept)); });
    var h = '<option value="">請選擇部門</option>'; ds.forEach(function(p){ h += '<option value="'+p.dept_id+'">'+esc(p.dept)+'</option>'; });
    $(deptSel).html(h); pairFill(deptSel, '');
}
function pairFill(deptSel, keepKey){
    var d = +$(deptSel).val(), h = '<option value="">'+(d ? '請選擇人員' : '請先選部門')+'</option>';
    (META.people||[]).forEach(function(p){ if (d && +p.dept_id===d) h += '<option value="'+esc(p.key)+'" data-id="'+p.id+'" data-dept="'+p.dept_id+'">'+esc((p.pos||'')+'　'+p.name)+'</option>'; });
    $(PAIRS[deptSel]).html(h).val(keepKey || '');
}
$(document).on('change', '#fIntvDept,#fInteeDept,#tkOwnerDept', function(){ pairFill('#'+this.id, ''); });
function pairSet(deptSel, uid, deptId){
    var hit = null; (META.people||[]).forEach(function(p){ if (+p.id===+uid && (!hit || +p.dept_id===+deptId)) hit = p; });
    if (!hit) return; $(deptSel).val(String(hit.dept_id)); pairFill(deptSel, hit.key);
}
function peopleIdByKey(sel){ var o=$(sel).find('option:selected'); return {id:+(o.attr('data-id')||0), dept:+(o.attr('data-dept')||0)}; }
/* 地點選單：清單 + 「＋新增地點…」（任何人填單時可直接新增） */
function fillPlaceSel(cur){
    var h = '<option value="">（不指定）</option>', has = false;
    (META.places||[]).forEach(function(p){ if (+p.is_active || p.name===cur) { h += '<option value="'+esc(p.name)+'">'+esc(p.name)+'</option>'; if (p.name===cur) has = true; } });
    if (cur && !has) h += '<option value="'+esc(cur)+'">'+esc(cur)+'</option>';
    h += '<option value="__new">＋新增地點…</option>';
    $('#fPlace').html(h).val(cur || '');
}
$('#fPlace').on('change', function(){
    if ($(this).val() !== '__new') return;
    var n = $.trim(prompt('新地點名稱：') || '');
    if (!n) { $(this).val(''); return; }
    post({action:'place_add', name:n}, function(res){ META.places = META.places || []; if (!META.places.some(function(p){ return p.name===res.name; })) META.places.push({name:res.name, is_active:1}); fillPlaceSel(res.name); });
});

function loadMeta(then){
    $.getJSON(API, {action:'meta'}, function(res){
        if (!res.ok) return;
        META = res;
        var h='<option value="0">全部</option>';
        res.types.forEach(function(t){ h += '<option value="'+t.type_id+'">'+esc(t.name)+(+t.is_active?'':'（已停用）')+'</option>'; });
        $('#fltType').html(h);
        if (then) then();
    });
}

/* ================= 清單 ================= */
function loadList(page){
    PAGE = page || PAGE;
    $.getJSON(API, {action:'list', page:PAGE, per:20, type_id:$('#fltType').val(), status:$('#fltStatus').val(), due:$('#fltDue').is(':checked')?1:0, kw:$('#fltKw').val()}, function(res){
        if (!res.ok) return;
        var h = '', t = today();
        res.rows.forEach(function(r){
            var due = r.next_date && r.status==='done' && r.next_date <= t;
            h += '<tr class="clk" data-id="'+r.iv_id+'"><td>'+esc(r.iv_no)+'</td><td>'+esc(dispDate(r.iv_date))+'</td><td>'+esc(r.type_name)+'</td><td>'+(r.memo_no ? (+r.memo_can ? '<a class="memoLink" href="memo_request.php?memo_id='+r.memo_id+'" target="_blank">'+esc(r.memo_no)+'</a>' : '<span title="無權限檢視此簽呈">'+esc(r.memo_no)+'</span>') : '')+'</td>'
               + '<td>'+esc(r.interviewee_dept)+' '+esc(r.interviewee_name)+'</td><td>'+esc(r.interviewer_name)+'</td>'
               + '<td><span class="st st-'+r.status+'">'+(r.status==='done'?'已完成':'草稿')+'</span></td>'
               + '<td>'+(r.next_date ? '<span class="'+(due?'st st-late':'')+'">'+esc(dispDate(r.next_date))+(due?' 已到':'')+'</span>' : '')+'</td>'
               + '<td>'+(+r.imp_cnt ? r.imp_cnt+' 項' : '')+'</td></tr>';
        });
        $('#listBody').html(h || '<tr><td colspan="9" class="iv-hint">沒有符合的訪談記錄</td></tr>');
        var pages = Math.max(1, Math.ceil(res.total / res.per)), ph = '共 '+res.total+' 筆　';
        for (var i=1; i<=pages && i<=15; i++) ph += '<button class="'+(i===res.page?'on':'')+'" data-p="'+i+'">'+i+'</button>';
        $('#pagerTop,#pagerBot').html(ph);
    });
}
$(document).on('click', '.iv-pager button', function(){ loadList(+$(this).data('p')); });
$(document).on('click', '#listBody tr.clk', function(e){ if ($(e.target).closest('a').length) return; openView(+$(this).data('id')); });
$('#btnSearch').on('click', function(){ loadList(1); });
$('#fltKw').on('keydown', function(e){ if (e.key==='Enter') loadList(1); });
$('#fltType,#fltStatus,#fltDue').on('change', function(){ loadList(1); });
$('#btnPageHelp').on('click', function(){ openMask('helpUseMask'); });

/* ================= 編輯 ================= */
function fillTypeSel(cur){
    var h=''; META.types.forEach(function(t){ if (+t.is_active || +t.type_id===+cur) h += '<option value="'+t.type_id+'">'+esc(t.name)+'</option>'; });
    $('#fType').html(h).val(cur ? String(cur) : $('#fType option:first').val());
    onTypeChange();
}
function onTypeChange(){
    var t = typeById($('#fType').val()) || {};
    $('#memoBox').toggle(!!+t.need_memo);
    $('#nextBox').toggle(!!+t.need_next);
    $('#guideBox').toggle(!!$.trim(t.guide||''));
}
$('#fType').on('change', onTypeChange);
function openEdit(r, presetMemo){
    CUR_ID = r ? +r.iv_id : 0; CUR_MEMO = r ? (+r.memo_id||0) : (+presetMemo||0);
    $('#editTitle').text(r ? '修改訪談記錄 '+r.iv_no : '新增訪談記錄');
    pairInit('#fIntvDept','#fIntv'); pairInit('#fInteeDept','#fIntee');
    var tp0 = r ? r.type_id : 0;
    if (!r && presetMemo) (META.types||[]).forEach(function(t){ if (!tp0 && +t.is_active && +t.need_memo) tp0 = t.type_id; });   // 由簽呈轉為調查：預選要綁簽呈的類型（事件調查）
    fillTypeSel(tp0);
    $('#fDate').val(r ? r.iv_date : today());     fillPlaceSel(r ? (r.place||'') : '');
    $('#fStart').val(r ? (r.start_time||'') : ''); $('#fEnd').val(r ? (r.end_time||'') : '');
    if (r) { pairSet('#fIntvDept', r.interviewer_id, 0); pairSet('#fInteeDept', r.interviewee_id, r.interviewee_dept_id); }
    else pairSet('#fIntvDept', META.uid, 0);
    $('#fContent').val(r ? (r.content||'') : ''); $('#fFollow').val(r ? (r.follow||'') : ''); $('#fNext').val(r ? (r.next_date||'') : '');
    loadMemos(CUR_MEMO);
    $('.iv-err').hide(); $('.bad').removeClass('bad');
    openMask('editMask');
}
/* 關聯簽呈：直接用下拉選（只列你本來就看得到的簽呈，可打字篩選） */
function loadMemos(cur){
    $.getJSON(API, {action:'memo_search', kw:''}, function(res){
        var h = '<option value="">請選擇簽呈</option>', has = false;
        (res.rows||[]).forEach(function(m){ h += '<option value="'+m.memo_id+'">'+esc(m.memo_no+'　'+m.subject)+'</option>'; if (+m.memo_id===+cur) has = true; });
        if (cur && !has) h += '<option value="'+cur+'">簽呈 #'+cur+'（目前已選）</option>';
        $('#fMemo').html(h).val(cur ? String(cur) : '');
    });
}
$('#fMemo').on('change', function(){ CUR_MEMO = +$(this).val() || 0; $('#eMemo').hide(); });
function normTime(v){ v=$.trim(v); if(!v) return ''; var m=v.match(/^(\d{1,2}):?(\d{2})$/); if(!m||+m[1]>23||+m[2]>59) return null; return ('0'+m[1]).slice(-2)+':'+m[2]; }
function checkEdit(){
    var ok = true; $('.iv-err').hide(); $('.bad').removeClass('bad');
    function bad(sel, err, msg){ $(sel).addClass('bad'); $(err).text(msg).show(); ok=false; }
    var s1=normTime($('#fStart').val()), s2=normTime($('#fEnd').val());
    if (s1===null || s2===null) bad('#fStart,#fEnd','#eTime','時間格式不對，請輸入如 0900 或 9:00（00:00～23:59）');
    else { $('#fStart').val(s1); $('#fEnd').val(s2); if (s1 && s2 && s2 < s1) bad('#fEnd','#eTime','結束時間不能早於開始時間'); }
    var a=peopleIdByKey('#fIntv'), b=peopleIdByKey('#fIntee');
    if (!a.id || !b.id) bad(!a.id?'#fIntv':'#fIntee','#ePeople','請選擇訪談人與受訪人');
    else if (a.id===b.id) bad('#fIntee','#ePeople','訪談人與受訪人不能是同一人');
    var t = typeById($('#fType').val()) || {};
    if (+t.need_memo && !CUR_MEMO) bad('#fMemo','#eMemo','「'+t.name+'」要綁定簽呈，請搜尋並選取');
    if (+t.need_next && $('#fNext').val() && $('#fNext').val() <= $('#fDate').val()) bad('#fNext','#eNext','下次約談日必須晚於訪談日期');
    if (!$('#fDate').val()) { $('#fDate').addClass('bad'); ok=false; }
    return ok;
}
$('#fStart,#fEnd,#fNext,#fDate,#fIntv,#fIntee,#fIntvDept,#fInteeDept').on('change blur', function(){ checkEdit(); });
$('#btnNew').on('click', function(){ openEdit(null); });
$('#btnSave').on('click', function(){
    if (!checkEdit()) return;
    var a=peopleIdByKey('#fIntv'), b=peopleIdByKey('#fIntee'), t = typeById($('#fType').val()) || {};
    post({action:'save', iv_id:CUR_ID, type_id:$('#fType').val(), iv_date:$('#fDate').val(), place:($('#fPlace').val()==='__new' ? '' : $('#fPlace').val()),
          start_time:$('#fStart').val(), end_time:$('#fEnd').val(), interviewer_id:a.id, interviewer_dept_id:a.dept,
          interviewee_id:b.id, interviewee_dept_id:b.dept, memo_id:CUR_MEMO,
          content:$('#fContent').val(), follow:$('#fFollow').val(), next_date:+t.need_next ? $('#fNext').val() : ''}, function(res){
        closeMask('editMask'); loadList(); openView(res.iv_id);
    });
});

/* ================= 檢視 ================= */
function openView(id){
    $.getJSON(API, {action:'get', iv_id:id}, function(res){
        if (!res.ok) { alert(res.error); return; }
        CUR = res; var r = res.row, done = r.status==='done', canEdit = res.can_edit;
        $('#viewTitle').text(r.iv_no+'　'+r.type_name);
        var h = '<div class="grid2" style="font-size:13px;color:#5b3a1e;">'
              + '<div>日期：'+esc(dispDate(r.iv_date))+' '+esc(r.start_time||'')+(r.end_time?'～'+esc(r.end_time):'')+'</div><div>地點：'+esc(r.place||'')+'</div>'
              + '<div>訪談人：'+esc(r.interviewer_dept||'')+' '+esc(r.interviewer_pos||'')+' '+esc(r.interviewer_name)+'</div>'
              + '<div>受訪人：'+esc(r.interviewee_dept||'')+' '+esc(r.interviewee_pos||'')+' '+esc(r.interviewee_name)+'</div></div>'
              + '<div style="margin:6px 0;"><span class="st st-'+r.status+'">'+(done?'已完成':'草稿')+'</span>'
              + (res.memo ? '　關聯簽呈：'+(res.memo.subject ? '<a href="memo_request.php?memo_id='+res.memo.memo_id+'" target="_blank">'+esc(res.memo.memo_no)+'</a> '+esc(res.memo.subject) : esc(res.memo.memo_no)) : '')
              + (r.next_date ? '　下次約談：'+esc(dispDate(r.next_date)) : '')+'</div>'
              + '<div class="sec">談話內容</div><div class="view-box">'+esc(r.content||'')+'</div>'
              + (r.follow ? '<div class="sec">後續處理／追蹤</div><div class="view-box">'+esc(r.follow)+'</div>' : '')
              + '<div class="sec">改善事項（去識別化，可轉入措施追蹤表）</div>';
        res.improves.forEach(function(i){
            h += '<div class="imp"><div>'+esc(i.content)+'</div><div class="a">'
               + (+i.track_id ? '<span class="st st-done">已轉追蹤 '+esc(i.track_no||'')+(+i.is_closed?'（已結案）':'')+'</span>'
                   : (canEdit ? '<button class="impTrack" data-id="'+i.imp_id+'" data-c="'+esc(i.content)+'">轉入追蹤表</button><button class="impDel" data-id="'+i.imp_id+'">刪除</button>' : '<span class="iv-hint">尚未轉入</span>'))
               + '</div></div>';
        });
        if (!res.improves.length) h += '<div class="iv-hint">沒有改善事項</div>';
        if (canEdit) h += '<div class="imp-form"><textarea id="impText" rows="2" style="width:100%;border:1px solid #D8BE93;border-radius:4px;padding:5px 8px;font-size:13px;" maxlength="1000" placeholder="例：現場反映齒研區照明不足，影響量測判讀（請勿寫入個人姓名）"></textarea>'
              + '<button id="btnImpAdd" style="margin-top:4px;height:28px;border:1px solid #d98a33;background:#F0A24B;color:#fff;border-radius:4px;padding:0 12px;cursor:pointer;">新增改善事項</button></div>';
        h += '<div class="sec">附件（訪談記錄簽章結果）</div><div id="flBox">'+filesHtml(res.files, canEdit)+'</div>';
        if (canEdit) h += '<div class="imp-form"><input type="file" id="flInput" multiple> <button id="btnFlUp" style="height:28px;border:1px solid #d98a33;background:#F0A24B;color:#fff;border-radius:4px;padding:0 12px;cursor:pointer;">上傳</button><div class="iv-hint">列印後經訪談人與受訪人簽名的掃描檔（PDF／圖片皆可，單檔 50MB 內）；已完成的記錄也能上傳。調查結果簽呈會直接連到這裡的附件。</div><div class="iv-err" id="eFl"></div></div>';
        $('#viewBody').html(h);
        var f ='<button onclick="closeMask(\'viewMask\')">關閉</button><button id="btnPrint"><i class="fa fa-print"></i> 列印</button>';
        if (canEdit && !done) f += '<button class="b-danger" id="btnDel">刪除</button><button id="btnEdit">修改</button><button class="b-ok" id="btnFinish">完成</button>';
        if (done && META.perms.canAdmin) f += '<button id="btnReopen">重新開啟</button><button class="b-danger" id="btnDel">刪除</button>';
        $('#viewFoot').html(f);
        openMask('viewMask');
    });
}
function filesHtml(files, canEdit){
    if (!files || !files.length) return '<div class="iv-hint">尚未上傳附件</div>';
    return files.map(function(f){
        return '<div class="fl"><i class="fa fa-paperclip"></i><a href="'+API+'?action=download&id='+f.file_id+'" target="_blank">'+esc(f.original_name||f.file_name)+'</a><span class="iv-hint">'+Math.round(f.file_size/1024)+' KB　'+esc(dispDate(String(f.created_at||'').substr(0,10)))+'</span>'
             + (canEdit ? '<span class="x flDel" data-id="'+f.file_id+'" title="刪除">✕</span>' : '')+'</div>';
    }).join('');
}
$(document).on('click', '#btnFlUp', function(){
    var files = document.getElementById('flInput').files;   // 送出時直讀 input.files
    if (!files || !files.length) { $('#eFl').text('請先選擇要上傳的檔案').show(); return; }
    $('#eFl').hide();
    var list = Array.prototype.slice.call(files), id = CUR.row.iv_id, $b = $(this).prop('disabled', true).text('上傳中…'), fail = [];
    (function next(i){
        if (i >= list.length) { $b.prop('disabled', false).text('上傳'); openView(id); if (fail.length) alert('上傳失敗：'+fail.join('、')); return; }
        var fd = new FormData(); fd.append('action','upload'); fd.append('csrf', META.csrf); fd.append('iv_id', id); fd.append('file', list[i]);
        $.ajax({url:API, type:'POST', data:fd, processData:false, contentType:false, dataType:'json', global:false,
            success:function(res){ if (!res.ok) fail.push(list[i].name+'（'+(res.error||'')+'）'); next(i+1); },
            error:function(x){ fail.push(list[i].name+'（'+((x.responseJSON&&x.responseJSON.error)||('HTTP '+x.status))+'）'); next(i+1); }});
    })(0);
});
$(document).on('click', '.flDel', function(){
    if (!confirm('刪除這個附件？')) return;
    post({action:'file_delete', file_id:$(this).data('id')}, function(res){ $('#flBox').html(filesHtml(res.files, true)); });
});
$(document).on('click', '#btnEdit', function(){ closeMask('viewMask'); openEdit(CUR.row); });
$(document).on('click', '#btnFinish', function(){
    if (!confirm('完成後內容鎖定，只有管理員能重新開啟。確定完成？')) return;
    post({action:'finish', iv_id:CUR.row.iv_id}, function(){ loadList(); openView(CUR.row.iv_id); });
});
$(document).on('click', '#btnReopen', function(){ post({action:'reopen', iv_id:CUR.row.iv_id}, function(){ loadList(); openView(CUR.row.iv_id); }); });
$(document).on('click', '#btnDel', function(){
    if (!confirm('確定刪除這筆訪談記錄？')) return;
    post({action:'delete', iv_id:CUR.row.iv_id}, function(){ closeMask('viewMask'); loadList(); });
});
$(document).on('click', '#btnImpAdd', function(){
    var c = $.trim($('#impText').val()); if (!c) { alert('請填改善事項內容'); return; }
    post({action:'improve_add', iv_id:CUR.row.iv_id, content:c}, function(){ openView(CUR.row.iv_id); loadList(); });
});
$(document).on('click', '.impDel', function(){
    if (!confirm('刪除這項改善事項？')) return;
    post({action:'improve_del', imp_id:$(this).data('id')}, function(){ openView(CUR.row.iv_id); loadList(); });
});
$(document).on('click', '.impTrack', function(){
    TK_IMP = +$(this).data('id'); $('#tkContent').text($(this).attr('data-c'));
    pairInit('#tkOwnerDept','#tkOwner'); $('#tkDue').val(''); $('#tkAction').val(''); $('#eTk').hide(); openMask('trackMask');
});
$('#btnTrackOk').on('click', function(){
    var o = peopleIdByKey('#tkOwner');
    if (!o.id || !$('#tkDue').val()) { $('#eTk').text('請選擇負責人並填預計完成日').show(); return; }
    post({action:'improve_to_track', imp_id:TK_IMP, owner_id:o.id, owner_dept_id:o.dept, due_date:$('#tkDue').val(), action_text:$('#tkAction').val()}, function(){
        closeMask('trackMask'); openView(CUR.row.iv_id); loadList();
    });
});

/* ================= 列印 ================= */
$(document).on('click', '#btnPrint', function(){
    var r = CUR.row, co = META.company || '';
    var w = window.open('', '_blank'); if (!w) { alert('列印視窗被瀏覽器擋住，請允許彈出視窗'); return; }
    var memo = CUR.memo && CUR.memo.subject ? '<tr><th>關聯簽呈</th><td colspan="3">'+esc(CUR.memo.memo_no+'　'+CUR.memo.subject)+'</td></tr>' : '';
    var html = '<!DOCTYPE html><html><head><meta charset="utf-8"><title>訪談記錄 '+esc(r.iv_no)+'</title><style>'
      + '@page{size:A4 portrait;margin:0}body{font-family:"Microsoft JhengHei","微軟正黑體",sans-serif;color:#000;margin:0;padding:12mm 15mm;}'
      + 'h1{text-align:center;font-size:20px;margin:0}h2{text-align:center;font-size:17px;margin:4px 0 12px}'
      + 'table{width:100%;border-collapse:collapse;font-size:14px}th,td{border:1px solid #000;padding:6px 8px;text-align:left;vertical-align:top}th{background:#f3f3f3;width:90px}'
      + 'tr{page-break-inside:auto}.c{height:105mm;white-space:pre-wrap;line-height:1.7}.f{height:24mm;white-space:pre-wrap}.sg{height:22mm;page-break-inside:avoid}.no{margin-top:6px;font-size:11px}'
      + '</style></head><body><h1>'+esc(co)+'</h1><h2>訪談記錄（'+esc(r.type_name)+'）　編號 '+esc(r.iv_no)+'</h2><table>'
      + '<tr><th>訪談日期</th><td>'+esc(dispDate(r.iv_date))+' '+esc(r.start_time||'')+(r.end_time?'～'+esc(r.end_time):'')+'</td><th>地點</th><td>'+esc(r.place||'')+'</td></tr>'
      + '<tr><th>訪談人</th><td>'+esc(r.interviewer_dept||'')+' '+esc(r.interviewer_pos||'')+' '+esc(r.interviewer_name)+'</td><th>受訪人</th><td>'+esc(r.interviewee_dept||'')+' '+esc(r.interviewee_pos||'')+' '+esc(r.interviewee_name)+'</td></tr>'
      + memo + '<tr><th>談話內容</th><td colspan="3" class="c">'+esc(r.content||'')+'</td></tr>'
      + '<tr><th>後續處理</th><td colspan="3" class="f">'+esc(r.follow||'')+'</td></tr>'
      + '<tr><th>訪談人簽名</th><td class="sg"></td><th>受訪人簽名</th><td class="sg"></td></tr></table>'
      + '<div class="no">本記錄為機密人事紀錄　列印時間：'+esc(dispDate(today()))+'</div>'
      + '<scr'+'ipt>window.onload=function(){setTimeout(function(){window.print();},200);};</scr'+'ipt></body></html>';
    w.document.write(html); w.document.close();
    if (window.EGPrintLog && EGPrintLog.record) { try { EGPrintLog.record({source:'interview_record', doc_name:'訪談記錄 '+r.iv_no, doc_kind:'form'}); } catch(e){} }
});

$(document).on('click', '#btnGuide', function(){
    var t = typeById($('#fType').val()); if (!t || !$.trim(t.guide||'')) return;
    var qs = $.trim(t.guide).split(/\r?\n/).map(function(x){ return $.trim(x); }).filter(Boolean);
    var add = qs.map(function(q,k){ return (k+1)+'. '+q+'\n答：'; }).join('\n\n');
    var cur = $('#fContent').val();
    $('#fContent').val(cur ? cur.replace(/\s+$/,'')+'\n\n'+add : add).focus();
});

/* ================= 類型設定 ================= */
function renderTypes(){
    var h=''; TYPES_EDIT.forEach(function(t,i){
        h += '<div class="tp-row" data-i="'+i+'"><input type="text" class="tpName" maxlength="40" value="'+esc(t.name)+'">'
           + '<label><input type="checkbox" class="tpMemo" '+(+t.need_memo?'checked':'')+'></label>'
           + '<label><input type="checkbox" class="tpNext" '+(+t.need_next?'checked':'')+'></label>'
           + '<label><input type="checkbox" class="tpAct" '+(+t.is_active?'checked':'')+'></label><span class="x tpDel">✕</span>'
           + '<textarea class="tpGuide" rows="2" maxlength="2000" placeholder="引導問題（選填，一行一題；填表時可按「插入引導問題」貼入）" style="grid-column:1/-1;font-size:12px;">'+esc(t.guide||'')+'</textarea></div>';
    });
    $('#typeBox').html(h);
}
function collectTypes(){
    var out=[]; $('#typeBox .tp-row').each(function(){
        var i=+$(this).data('i'); out.push({type_id:(TYPES_EDIT[i]||{}).type_id||0, name:$.trim($(this).find('.tpName').val()),
            need_memo:$(this).find('.tpMemo').is(':checked')?1:0, need_next:$(this).find('.tpNext').is(':checked')?1:0, is_active:$(this).find('.tpAct').is(':checked')?1:0, guide:$(this).find('.tpGuide').val()});
    }); return out;
}
/* 地點維護 */
var PLACES_EDIT = [];
function renderPlaces(){
    var h=''; PLACES_EDIT.forEach(function(p,i){ h += '<div class="tp-row" data-i="'+i+'" style="grid-template-columns:1fr 60px 40px;"><input type="text" class="plName" maxlength="100" value="'+esc(p.name)+'"'+(+p.used?' readonly title="已有單據使用，不可修改名稱"':'')+'><label><input type="checkbox" class="plAct" '+(+p.is_active?'checked':'')+'></label>'+(+p.used?'<span class="iv-hint" title="已有單據使用，不可刪除（可取消勾選＝停用）">'+p.used+'筆</span>':'<span class="x plDel">✕</span>')+'</div>'; });
    $('#placeBox').html(h || '<div class="iv-hint">還沒有地點</div>');
}
function collectPlaces(){
    var out=[]; $('#placeBox .tp-row').each(function(){ var i=+$(this).data('i'); out.push({place_id:(PLACES_EDIT[i]||{}).place_id||0, name:$.trim($(this).find('.plName').val()), is_active:$(this).find('.plAct').is(':checked')?1:0}); }); return out;
}
function syncPlaces(){ var c = collectPlaces(); PLACES_EDIT = c.map(function(p,i){ return $.extend({}, PLACES_EDIT[i]||{}, p); }); }
$('#btnAddPlace').on('click', function(){ syncPlaces(); PLACES_EDIT.push({place_id:0,name:'',is_active:1}); renderPlaces(); });
$(document).on('click', '.plDel', function(){ var i=+$(this).closest('.tp-row').data('i'); syncPlaces(); PLACES_EDIT.splice(i,1); renderPlaces(); });
$('#btnTypes').on('click', function(){ PLACES_EDIT = (META.places||[]).map(function(p){ return $.extend({}, p); }); renderPlaces(); TYPES_EDIT = META.types.map(function(t){ return $.extend({}, t); }); renderTypes(); openMask('typeMask'); });
$('#btnAddType').on('click', function(){ TYPES_EDIT = collectTypes().map(function(t,i){ return $.extend({}, TYPES_EDIT[i]||{}, t); }); TYPES_EDIT.push({type_id:0,name:'',need_memo:0,need_next:0,is_active:1}); renderTypes(); });
$(document).on('click', '.tpDel', function(){
    var i=+$(this).closest('.tp-row').data('i'); TYPES_EDIT = collectTypes().map(function(t,k){ return $.extend({}, TYPES_EDIT[k]||{}, t); }); TYPES_EDIT.splice(i,1); renderTypes();
});
$('#btnTypeOk').on('click', function(){
    post({action:'save_places', places:JSON.stringify(collectPlaces())}, function(){
        post({action:'save_types', types:JSON.stringify(collectTypes())}, function(){ closeMask('typeMask'); loadMeta(function(){ loadList(1); }); });
    });
});

/* 深連結：?iv_id=（簽呈上的連結直接開該筆）／?from_memo=（由已核准的啟動調查簽呈轉為調查，開新增畫面並綁好簽呈） */
loadMeta(function(){
    loadList(1);
    var q = new URLSearchParams(location.search), vi = +q.get('iv_id'), fm = +q.get('from_memo');
    if (vi) openView(vi); else if (fm) openEdit(null, fm);
});
</script>
</body>
</html>
