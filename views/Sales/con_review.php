<?php
/**
 * 合約訂單審查表（AS 2-SM-01-06）
 * 架構說明與設計決策全部寫在 src/common/con_review_lib.php 檔頭，這裡只是畫面。
 * 一張訂單一份（稽核製程標籤才需要）、業務日期鎖接單日、內容部門逐項自填自簽、
 * 業務課決行、總經理核准。資料操作走 src/store/ConReview_API.php。
 */
session_start();
if (!isset($_SESSION['userName'])) {
    $_SESSION['lastpage'] = "../../views/Sales/con_review.php";
    header("Location:../../index.php");
    exit;
}
include_once '../../src/common/_config.php';
include_once '../../src/common/DBConnection.php';
include_once '../../src/common/con_review_lib.php';

if (!function_exists('safe_html')) { function safe_html($v) { return htmlspecialchars($v ?? '', ENT_QUOTES, 'UTF-8'); } }

$db = (new DBConnection())->getPDO();
cnrv_ensure_schema($db);
$cnrvUser = cnrv_current_user($db);
$perms = cnrv_perms($db, $cnrvUser);
$roleLabel = $perms['isAdmin'] ? '管理者' : ($perms['canAdmin'] ? '審查表管理員' : ($perms['canCreate'] ? '可建立審查' : ($perms['canView'] ? '檢視/填寫' : '無權限')));
$companyName = eg_company_full_name($db);
?>
<!DOCTYPE html>
<html lang="zh-Hant">
<head>
    <meta charset="utf-8">
    <meta http-equiv="X-UA-Compatible" content="IE=edge">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>合約訂單審查表</title>
    <link href="../../resource/css/bootstrap.css" rel="stylesheet">
    <link href="../../resource/css/font-awesome.css" rel="stylesheet">
    <link href="../../resource/css/nprogress.css" rel="stylesheet">
    <link href="../../resource/css/custom.css" rel="stylesheet">
    <style>
        #sidebar-menu { visibility: hidden; }
        .right_col .page-title { margin:8px 0 4px; overflow:hidden; clear:both; }
        .page-help-btn { height:30px; font-size:13px; padding:0 12px; border:1px solid #d98a33; border-radius:15px;
            background:#F0A24B; color:#fff; cursor:pointer; margin-left:auto; }
        .page-help-btn:hover { background:#d98a33; }
        @media print { .page-help-btn { display:none !important; } }
        .help-doc { font-size:13px; color:#5b3a1e; line-height:1.75; }
        .help-doc h4 { color:#8A5A2B; border-bottom:2px solid #F7E0BD; padding-bottom:3px; margin:14px 0 6px; font-size:15px; }
        .help-doc h4:first-child { margin-top:0; }
        .help-doc b { color:#8A5A2B; }

        .cr-toolbar { display:flex; gap:8px; align-items:center; flex-wrap:wrap; margin-bottom:12px; }
        .cr-toolbar select, .cr-toolbar input[type=text] { height:32px; border:1px solid #E8D5B5; border-radius:4px; padding:0 8px; }
        .cr-btn { height:32px; padding:0 14px; border:1px solid #d98a33; background:#F0A24B; color:#fff; border-radius:4px; cursor:pointer; font-size:13px; }
        .cr-btn:hover { background:#d98a33; }
        .cr-btn.b-plain { background:#fff; color:#8a6d45; border-color:#E8D5B5; }
        .cr-btn.b-plain:hover { background:#FDF6EC; }
        .cr-btn:disabled { opacity:.5; cursor:not-allowed; }

        table.cr-tbl { width:100%; border-collapse:collapse; background:#fff; }
        table.cr-tbl th, table.cr-tbl td { border:1px solid #EFE3D1; padding:6px 8px; font-size:13px; }
        table.cr-tbl th { background:#FDF6EC; color:#8a6d45; text-align:left; }
        table.cr-tbl tr:hover { background:#FFFBF3; }
        .st-badge { display:inline-block; padding:1px 8px; border-radius:10px; font-size:11.5px; line-height:18px; }
        .st-draft { background:#F3EADC; color:#8a6d45; }
        .st-submitted { background:#FFF3E2; color:#8a5a2b; border:1px solid #E4D3BC; }
        .st-closed { background:#E7F3E8; color:#2d6a3e; }
        .st-void { background:#F0F0F0; color:#999; }
        .dc-accept { color:#2d6a3e; font-weight:600; }
        .dc-conditional { color:#b5862f; font-weight:600; }
        .dc-reject { color:#c0392b; font-weight:600; }

        .cr-mask { display:none; position:fixed; inset:0; background:rgba(0,0,0,.4); z-index:9000; overflow:auto; padding:30px 0; }
        .cr-modal { background:#fff; border-radius:8px; margin:0 auto; max-width:960px; box-shadow:0 8px 30px rgba(0,0,0,.25); }
        .cr-modal.narrow { max-width:520px; }
        .m-head { display:flex; justify-content:space-between; align-items:center; padding:14px 18px; border-bottom:1px solid #EFE3D1; font-size:16px; font-weight:600; color:#5b3a1e; }
        .m-close { cursor:pointer; color:#aaa; font-size:18px; }
        .m-close:hover { color:#666; }
        .m-body { padding:16px 18px; max-height:72vh; overflow:auto; }
        .m-foot { padding:12px 18px; border-top:1px solid #EFE3D1; text-align:right; }
        .m-body label { display:block; font-size:12.5px; color:#8a6d45; margin:8px 0 2px; }
        .m-body input[type=text], .m-body input[type=date], .m-body select, .m-body textarea { width:100%; border:1px solid #E8D5B5; border-radius:4px; padding:5px 8px; font-size:13px; }

        .cr-sec-title { font-size:14px; font-weight:600; color:#5b3a1e; margin:16px 0 8px; border-left:4px solid #F0A24B; padding-left:8px; }
        .cr-hdr-grid { display:grid; grid-template-columns:repeat(3,1fr); gap:10px 18px; background:#FDF6EC; border:1px solid #E8D5B5; border-radius:6px; padding:12px 14px; font-size:13px; }
        .cr-hdr-grid .k { color:#8a6d45; } .cr-hdr-grid .v { font-weight:600; color:#333; }

        .cr-grp-row td { background:#F7F0E4; font-weight:600; color:#8a5a2b; }
        .cr-dept-box { border:1px solid #E8D5B5; border-radius:6px; padding:10px 12px; margin-bottom:8px; }
        .cr-dept-box.signed { background:#E7F3E8; border-color:#bfe0c4; }
        .cr-dept-box .hd { display:flex; justify-content:space-between; align-items:center; }
        .ord-opt { padding:6px 8px; border-bottom:1px solid #F3EADC; cursor:pointer; }
        .ord-opt:hover { background:#FDF6EC; }
        .ord-opt.disabled { cursor:not-allowed; opacity:.55; }
        .rf-mini-btn { font-size:11px; color:#b5862f; cursor:pointer; text-decoration:underline; }

        @media print { .cr-toolbar, .page-help-btn, .cr-print-hide { display:none !important; } }
    </style>
</head>
<body class="nav-sm">
<div class="container body">
<div class="main_container">
<div class="col-md-3 left_col"><div class="left_col scroll-view"><div class="clearfix"></div>
<div id="sidebar-menu" class="main_menu_side hidden-print main_menu"></div>
</div></div>

<div class="right_col" role="main" style="min-height:100vh;">
    <div class="page-title" style="display:flex;align-items:center;flex-wrap:wrap;">
        <h3 style="margin:6px 0;">合約訂單審查表 <small>（2-SM-01-06）</small></h3>
        <span style="font-size:12.5px;color:#8a6d45;margin-left:12px;">身分：<?= safe_html($roleLabel) ?></span>
        <button type="button" class="page-help-btn" id="btnPageHelp" style="margin-left:auto;"><i class="fa fa-question-circle"></i> 使用說明</button>
    </div>
    <div class="clearfix"></div>

    <div class="cr-toolbar cr-print-hide">
        <?php if ($perms['canCreate']): ?><button type="button" class="cr-btn" id="btnAdd"><i class="fa fa-plus"></i> 新增審查</button>
        <button type="button" class="cr-btn b-plain" id="btnSuggest"><i class="fa fa-magic"></i> 建議建立清單</button><?php endif; ?>
        <select id="filterStatus"><option value="">全部狀態</option><option value="draft">草稿</option><option value="submitted">審查中</option><option value="closed">已結案</option></select>
        <input type="text" id="filterKw" placeholder="訂單編號／客戶／料號／編號 搜尋" style="width:220px;">
        <button type="button" class="cr-btn b-plain" id="btnSearch"><i class="fa fa-search"></i> 查詢</button>
        <?php if ($perms['canAdmin']): ?><button type="button" class="cr-btn b-plain" id="btnTpl" style="margin-left:auto;"><i class="fa fa-list"></i> 範本維護</button><?php endif; ?>
    </div>

    <table class="cr-tbl">
        <thead><tr><th style="width:120px;">編號</th><th>訂單</th><th>客戶</th><th>料號</th><th style="width:90px;">業務日期</th><th style="width:70px;">狀態</th><th style="width:120px;">決行</th><th style="width:70px;"></th></tr></thead>
        <tbody id="listBody"><tr><td colspan="8" style="text-align:center;color:#999;">載入中…</td></tr></tbody>
    </table>
    <div style="margin-top:10px;font-size:12.5px;color:#8a6d45;" id="listTotal"></div>
</div></div></div>

<!-- 新增審查：挑訂單 -->
<div class="cr-mask" id="addMask"><div class="cr-modal narrow">
    <div class="m-head"><span>新增合約訂單審查</span><span class="m-close" onclick="closeMask('addMask')">✕</span></div>
    <div class="m-body">
        <label>來源訂單<span style="color:#c0392b;">＊</span></label>
        <input type="text" id="addOrdKw" placeholder="輸入訂單編號／客戶／料號搜尋（至少 2 個字）" autocomplete="off">
        <div id="addOrdList" style="max-height:220px;overflow:auto;border:1px solid #E8D5B5;border-radius:4px;margin-top:4px;display:none;"></div>
        <div id="addOrdPicked" style="display:none;background:#FDF6EC;border:1px solid #E8D5B5;border-radius:4px;padding:8px 10px;margin-top:6px;font-size:12.5px;line-height:1.7;"></div>
        <div id="addOrdErr" style="color:#c0392b;font-size:12px;display:none;margin-top:4px;"></div>
    </div>
    <div class="m-foot"><button type="button" class="cr-btn b-plain" onclick="closeMask('addMask')">取消</button> <button type="button" class="cr-btn" onclick="submitAdd()">建立</button></div>
</div></div>

<!-- 詳情 -->
<div class="cr-mask" id="viewMask"><div class="cr-modal">
    <div class="m-head"><span id="vwTitle">合約訂單審查</span><span class="m-close" onclick="closeMask('viewMask')">✕</span></div>
    <div class="m-body" id="vwBody">載入中…</div>
    <div class="m-foot cr-print-hide"><button type="button" class="cr-btn b-plain" onclick="closeMask('viewMask')">關閉</button> <button type="button" class="cr-btn b-plain" onclick="printDoc()"><i class="fa fa-print"></i> 列印</button></div>
</div></div>

<!-- 建議建立清單 -->
<div class="cr-mask" id="suggestMask"><div class="cr-modal">
    <div class="m-head"><span>建議建立清單</span><span class="m-close" onclick="closeMask('suggestMask')">✕</span></div>
    <div class="m-body">
        <p style="font-size:12.5px;color:#8a6d45;">以下是「AS 認定需要審查、但還沒建立審查表單」的訂單（舊資料不強制補，這裡只是方便一次補齊，由您決定要不要建立）。</p>
        <div style="display:flex;gap:8px;align-items:center;margin-bottom:8px;">
            <label style="margin:0;font-size:12.5px;">範圍：</label>
            <select id="suggestDays" style="height:30px;border:1px solid #E8D5B5;border-radius:4px;padding:0 6px;">
                <option value="30">最近 30 天</option><option value="60">最近 60 天</option><option value="90">最近 90 天</option><option value="0">不限（全部）</option>
            </select>
            <button type="button" class="cr-btn b-plain" style="height:30px;" onclick="loadSuggest()">重新查詢</button>
            <input type="text" id="suggestClientKw" placeholder="輸入客戶部份ID或部份名稱篩選" style="height:30px;width:200px;border:1px solid #E8D5B5;border-radius:4px;padding:0 8px;">
            <span id="suggestTotal" style="font-size:12.5px;color:#8a6d45;margin-left:auto;"></span>
        </div>
        <table class="cr-tbl">
            <thead><tr><th style="width:36px;text-align:center;"><input type="checkbox" id="suggestAll" onchange="suggestToggleAll(this.checked)"></th><th>訂單</th><th>客戶</th><th>料號</th><th style="width:90px;">接單日期</th><th style="width:110px;">AS 認定</th></tr></thead>
            <tbody id="suggestBody"><tr><td colspan="6" style="text-align:center;color:#999;">載入中…</td></tr></tbody>
        </table>
    </div>
    <div class="m-foot"><span id="suggestPickedCount" style="float:left;font-size:12.5px;color:#8a6d45;margin-top:6px;"></span>
        <button type="button" class="cr-btn b-plain" onclick="closeMask('suggestMask')">關閉</button>
        <button type="button" class="cr-btn" onclick="suggestBatchCreate()"><i class="fa fa-magic"></i> 一鍵建立已勾選</button></div>
</div></div>

<!-- 自動填寫並簽核 -->
<div class="cr-mask" id="autoFillMask"><div class="cr-modal narrow">
    <div class="m-head"><span>自動填寫並簽核</span><span class="m-close" onclick="closeMask('autoFillMask')">✕</span></div>
    <div class="m-body" id="autoFillBody">
        <p style="font-size:12.5px;color:#8a6d45;">尚未送出會先自動送出；尚未填寫的項目用範本設定的「預設值」帶入（沒有設定預設值的項目會跳過，不會憑空填上去）；內容部門逐一嘗試自動簽核，<b>只有當天真的有上班的人才會被記為簽核人，當天不在的一律不簽、改列原因讓您自己決定</b>。已填寫/已簽核的內容不會被覆蓋。</p>
        <label>簽核日期</label>
        <input type="date" id="autoFillDate" max="9999-12-31">
        <div id="autoFillDateHint" style="font-size:11.5px;color:#8a6d45;margin-top:2px;"></div>
        <div id="autoFillResult" style="margin-top:12px;"></div>
    </div>
    <div class="m-foot"><button type="button" class="cr-btn b-plain" onclick="closeMask('autoFillMask')">關閉</button>
        <button type="button" class="cr-btn" id="btnAutoFillGo" onclick="submitAutoFillSign()"><i class="fa fa-magic"></i> 執行</button></div>
</div></div>

<!-- 範本維護 -->
<div class="cr-mask" id="tplMask"><div class="cr-modal">
    <div class="m-head"><span>範本項目維護</span><span class="m-close" onclick="closeMask('tplMask')">✕</span></div>
    <div class="m-body">
        <div style="margin-bottom:10px;">
            <button type="button" class="cr-btn b-plain" onclick="tplAdd()"><i class="fa fa-plus"></i> 新增項目</button>
            <button type="button" class="cr-btn b-plain" onclick="tplSeedFromDevEval()" title="把產品開發評估表 2-TD-02-01 的 32 項確認項目複製過來，之後兩邊各自獨立"><i class="fa fa-download"></i> 從產品開發評估表複製項目</button>
        </div>
        <table class="cr-tbl">
            <thead><tr><th style="width:60px;">順序</th><th style="width:70px;">區分</th><th>項目內容</th><th style="width:110px;">負責部門</th><th style="width:140px;">額外選項(逗號分隔)</th><th style="width:110px;">預設值</th><th style="width:50px;">啟用</th><th style="width:60px;"></th></tr></thead>
            <tbody id="tplBody"></tbody>
        </table>
    </div>
    <div class="m-foot"><button type="button" class="cr-btn b-plain" onclick="closeMask('tplMask')">關閉</button></div>
</div></div>

<!-- 使用說明 -->
<div class="cr-mask" id="helpUseMask"><div class="cr-modal">
    <div class="m-head"><span>使用說明</span><span class="m-close" onclick="closeMask('helpUseMask')">✕</span></div>
    <div class="m-body help-doc">
        <h4>這張表單在做什麼</h4>
        <p>AS9100 7.2 合約訂單審查：接到一張訂單時，評估「人機料法環」等能力是否足以接單。<b>只有訂單追蹤設定的「稽核製程標籤」判定為需要審查的訂單</b>（AS 認證範圍內的製程）才需要建立這張表單，其餘訂單（全製、單製非AS認證、廠內治具）不需要。</p>
        <h4>操作步驟</h4>
        <ul>
            <li>按「新增審查」挑選一張需要審查的訂單（已建過的會標示出來、不可重複建立）；或按「建議建立清單」一次列出所有還沒建立的訂單，勾選後「一鍵建立已勾選」。</li>
            <li>建立後業務日期自動帶入該訂單的接單日期，<b>不可修改</b>——審查的是「接單那一刻」的狀況；訂單後續若有變更，走另一份文件「訂單修改審查記錄表」。</li>
            <li>按「送出」後，負責各項目的部門才能開始填寫並簽核。</li>
            <li>各部門填完自己負責的項目後按「本課確認」簽核；<b>全部內容部門簽完</b>才能進行業務課決行。</li>
            <li>業務課決行（可接單／可接單需客戶確認條件／不可接單）後，交由總經理核准，核准後表單結案。</li>
        </ul>
        <h4>項目範本</h4>
        <p>項目範本由管理員在「範本維護」裡增刪修改，可一次從產品開發評估表複製 32 項當起點——<b>複製後兩邊完全獨立</b>，之後互不影響。每個項目的結果固定可選「是／否／N/A」三選一，管理員可再加「額外選項」（逗號分隔），<b>部門實際填寫答案時</b>可下拉選擇也可以自己打字輸入；「預設值」則<b>只能從「是／否／N/A＋目前設定的額外選項」裡下拉挑一個</b>（不能自己打字），供下方的自動填寫功能使用——改了額外選項，預設值的候選清單會跟著更新，原本選的如果不在新清單裡會自動清空要求重選。</p>
        <h4>管理員：自動填寫並簽核</h4>
        <p>給例行、低風險的訂單快速走完內容部門這一段：未送出的會先自動送出，尚未填寫的項目依範本設定的「預設值」帶入（沒設定預設值的項目會跳過），內容部門逐一嘗試自動簽核。<b>簽核日期預設是接單日期，可改成之後的日期但必須是工作日</b>；<b>只有當天真的有上班（在職、沒請假、沒整天公出）的人才會被記為簽核人</b>，當天都不在就不自動簽、列出原因讓管理員自己處理，絕不會蓋一個當天根本不在的人的章。已填寫的項目、已簽核的部門不會被覆蓋。業務課決行／總經理核准仍需要人工進行，不會被這個功能代勞。</p>
        <h4>誰能填寫／簽核</h4>
        <p>每個項目指定一個負責部門，<b>該部門的人</b>（部門主管優先，沒有主管時部門內任一人）就能填寫與簽核該部門的項目，不需要額外指派角色；要能進入本頁檢視/填寫，仍需要管理員指派「con_review_view」角色。</p>
        <h4>權限角色</h4>
        <ul><li><b>con_review_view</b>：檢視清單、填寫/簽核自己部門的項目</li><li><b>con_review_create</b>：建立新的審查表單</li><li><b>con_review_admin</b>：範本維護、補資料、作廢</li></ul>
    </div>
    <div class="m-foot"><button type="button" class="cr-btn b-plain" onclick="closeMask('helpUseMask')">關閉</button></div>
</div></div>

<script src="../../resource/js/jquery.min.js"></script>
<script src="../../resource/js/bootstrap.min.js"></script>
<script src="../../resource/js/fastclick.js"></script>
<script src="../../resource/js/nprogress.js"></script>
<script src="../../resource/js/custom.min.js"></script>
<script src="../../resource/js/eg_date_fmt.js?v=<?= @filemtime(__DIR__.'/../../resource/js/eg_date_fmt.js') ?>"></script>
<script src="../../resource/js/eg_stamp.js?v=<?= @filemtime(__DIR__.'/../../resource/js/eg_stamp.js') ?>"></script>
<script src="../../resource/js/eg_input_rules.js?v=<?= @filemtime(__DIR__.'/../../resource/js/eg_input_rules.js') ?>"></script>
<script>
$(document).ready(function(){ $('#sidebar-menu').css('visibility','visible'); });
$('#btnPageHelp').on('click', function(){ openMask('helpUseMask'); });

var API = '../../src/store/ConReview_API.php';
var META = {};
var DECISIONS = {};
window.__ownCompany = <?= json_encode($companyName, JSON_UNESCAPED_UNICODE) ?>;

function esc(s){ return $('<div>').text(s==null?'':String(s)).html(); }
function dispDate(d, withTime){ return d ? egFmtDate(d, withTime) : ''; }   // eg_date_fmt.js 只匯出 egFmtDate()，dispDate() 是各頁慣例自己包一層（ai-rules/20）
function openMask(id){ $('#'+id).css('display','block'); }
function closeMask(id){ $('#'+id).css('display','none'); }
var STATUS_LABEL = {draft:'草稿', submitted:'審查中', closed:'已結案', void:'已作廢'};

function loadMeta(cb){
    $.getJSON(API, {action:'meta'}, function(res){
        if (!res.ok){ alert(res.error||'載入失敗'); return; }
        META = res; DECISIONS = res.decisions || {};
        if (cb) cb();
    });
}

/* ───────────────── 清單 ───────────────── */
function loadList(){
    $.getJSON(API, {action:'list', status:$('#filterStatus').val(), keyword:$.trim($('#filterKw').val())}, function(res){
        if (!res.ok){ alert(res.error||'載入失敗'); return; }
        var h = '';
        (res.rows||[]).forEach(function(r){
            var dc = r.decision ? '<span class="dc-'+r.decision+'">'+esc(DECISIONS[r.decision]||r.decision)+'</span>' : '<span style="color:#bbb;">—</span>';
            h += '<tr><td>'+esc(r.doc_no)+'</td><td>'+esc(r.order_oo)+'</td><td>'+esc(r.client_name)+'</td><td>'+esc(r.part_no_text)+'</td>'
               + '<td>'+dispDate(r.business_date)+'</td><td><span class="st-badge st-'+r.status+'">'+STATUS_LABEL[r.status]+'</span></td>'
               + '<td>'+dc+'</td><td><button type="button" class="cr-btn b-plain" style="padding:2px 10px;height:26px;" onclick="openView('+r.id+')">開啟</button></td></tr>';
        });
        $('#listBody').html(h || '<tr><td colspan="8" style="text-align:center;color:#999;">沒有符合條件的資料</td></tr>');
        $('#listTotal').text('共 ' + (res.total||0) + ' 筆');
    });
}
$('#btnSearch').on('click', loadList);
$('#filterStatus').on('change', loadList);
$('#filterKw').on('keydown', function(e){ if (e.key==='Enter') loadList(); });

/* ───────────────── 新增（挑訂單） ───────────────── */
var ADD_ORD = null, ADD_T = null, ADD_SEQ = 0;
$('#btnAdd').on('click', function(){
    ADD_ORD = null; $('#addOrdPicked').hide().empty(); $('#addOrdKw').val(''); $('#addOrdList').hide().empty(); $('#addOrdErr').hide();
    openMask('addMask');
});
$('#addOrdKw').on('input', function(){
    var kw = $.trim(this.value);
    clearTimeout(ADD_T);
    if (kw.length < 2) { $('#addOrdList').hide().empty(); return; }
    ADD_T = setTimeout(function(){
        var seq = ++ADD_SEQ;
        $.getJSON(API, {action:'order_search', kw:kw}, function(res){
            if (seq !== ADD_SEQ || !res.ok) return;
            var h = (res.orders||[]).map(function(o){
                var done = o.doc_id ? '<span style="color:#c0392b;">（已建過）</span>' : '';
                return '<div class="ord-opt'+(o.doc_id?' disabled':'')+'" data-id="'+o.Order_id+'">'
                     + '<b>'+esc(o.Order_oo||('#'+o.Order_id))+'</b> '+done
                     + '<div style="font-size:11.5px;color:#8a6d45;">'+esc(o.client_name_txt||o.Client_name||'')
                     + '｜'+esc(o.d_id||'')+'｜'+Number(o.Qty||0).toLocaleString()+' 件｜接單 '+dispDate(o.Order_date)
                     + '｜<span style="color:#b5862f;">'+esc(o.tag_label||'')+'</span></div></div>';
            }).join('');
            if (!h) h = '<div style="padding:8px;color:#8a6d45;font-size:12px;">查不到符合的訂單（只列出「稽核製程」標籤的訂單）</div>';
            $('#addOrdList').html(h).show();
        });
    }, 300);
});
$(document).on('click', '#addOrdList .ord-opt:not(.disabled)', function(){
    var id = $(this).data('id');
    $.getJSON(API, {action:'order_get', order_id:id}, function(res){
        if (!res.ok){ alert(res.error||'讀取訂單失敗'); return; }
        var o = res.order;
        if (o.doc_id){ $('#addOrdErr').text('這張訂單已經建立過審查表單').show(); return; }
        if (!o.need_review){ $('#addOrdErr').text('這張訂單的 AS 認定不需要做合約訂單審查').show(); return; }
        ADD_ORD = o; $('#addOrdErr').hide(); $('#addOrdList').hide().empty(); $('#addOrdKw').val('');
        $('#addOrdPicked').html('<b>'+esc(o.Order_oo)+'</b>　<span class="rf-mini-btn" onclick="addOrdClear()">改選</span>'
            + '<br>客戶：'+esc(o.client_name_txt||o.Client_name||'（未綁客戶主檔）')
            + '<br>料號：'+esc(o.d_id||'')+'　數量：'+Number(o.Qty||0).toLocaleString()+' 件'
            + '<br>接單日期：'+dispDate(o.Order_date)+'　交期：'+dispDate(o.Delivery_date)
            + '<br>AS 認定：'+esc(o.tag_label||'')).show();
    });
});
function addOrdClear(){ ADD_ORD = null; $('#addOrdPicked').hide().empty(); $('#addOrdKw').val('').focus(); }
function submitAdd(){
    if (!ADD_ORD){ $('#addOrdErr').text('請先選擇來源訂單').show(); return; }
    $.post(API, {action:'create', csrf:META.csrf, order_id:ADD_ORD.Order_id}, function(res){
        if (!res.ok){ alert(res.error||'建立失敗'); return; }
        closeMask('addMask'); loadList(); openView(res.id);
    }, 'json');
}

/* ───────────────── 建議建立清單（批次一鍵建立） ───────────────── */
var SUGGEST_ROWS = [], SUGGEST_PICKED = {};   // order_id => true，用物件而非只看畫面勾選，篩選前後才不會把已勾的洗掉
$('#btnSuggest').on('click', function(){ SUGGEST_PICKED = {}; $('#suggestClientKw').val(''); openMask('suggestMask'); loadSuggest(); });
function loadSuggest(){
    $('#suggestBody').html('<tr><td colspan="6" style="text-align:center;color:#999;">載入中…</td></tr>');
    $.getJSON(API, {action:'suggest_list', days:$('#suggestDays').val()}, function(res){
        if (!res.ok){ alert(res.error||'載入失敗'); return; }
        SUGGEST_ROWS = res.rows || [];
        SUGGEST_LAST_TOTAL = res.total || 0;
        suggestApplyFilter();
    });
}
/* 客戶部份ID或部份名稱即時篩選（2026-10-05 使用者要求）：資料已經整批載入畫面，
   直接在前端過濾即時顯示，不必每打一個字都打一次後端。 */
var SUGGEST_LAST_TOTAL = 0;
function suggestApplyFilter(){
    var kw = $.trim($('#suggestClientKw').val()).toLowerCase();
    var rows = !kw ? SUGGEST_ROWS : SUGGEST_ROWS.filter(function(o){
        var idTxt = String(o.client_id||'').toLowerCase();
        var nameTxt = String(o.client_name_txt||o.Client_name||'').toLowerCase();
        return idTxt.indexOf(kw) !== -1 || nameTxt.indexOf(kw) !== -1;
    });
    var h = rows.map(function(o){
        var checked = SUGGEST_PICKED[o.Order_id] ? ' checked' : '';
        return '<tr><td style="text-align:center;"><input type="checkbox" class="suggest-chk" value="'+o.Order_id+'"'+checked+'></td>'
             + '<td>'+esc(o.Order_oo||('#'+o.Order_id))+'</td><td>'+esc(o.client_name_txt||o.Client_name||'')+(o.client_id?' <span style="color:#b5862f;font-size:11px;">('+esc(o.client_id)+')</span>':'')+'</td>'
             + '<td>'+esc(o.d_id||'')+'</td><td>'+dispDate(o.Order_date)+'</td><td>'+esc(o.tag_label||'')+'</td></tr>';
    }).join('');
    $('#suggestBody').html(h || '<tr><td colspan="6" style="text-align:center;color:#999;">'+(kw?'沒有符合「'+esc(kw)+'」的訂單':'目前沒有待建議建立的訂單')+'</td></tr>');
    $('#suggestAll').prop('checked', rows.length>0 && rows.every(function(o){ return SUGGEST_PICKED[o.Order_id]; }));
    var shown = SUGGEST_ROWS.length;
    $('#suggestTotal').text(kw ? ('篩選出 '+rows.length+' / 已載入 '+shown+' 筆')
                               : (SUGGEST_LAST_TOTAL > shown ? ('顯示 '+shown+' / 共 '+SUGGEST_LAST_TOTAL+'（調整範圍可看到更多）') : ('共 '+SUGGEST_LAST_TOTAL+' 筆')));
    suggestUpdateCount();
}
$('#suggestClientKw').on('input', suggestApplyFilter);
function suggestToggleAll(on){
    $('.suggest-chk').prop('checked', on).each(function(){ SUGGEST_PICKED[$(this).val()] = on ? true : undefined; });
    suggestUpdateCount();
}
$(document).on('change', '.suggest-chk', function(){ SUGGEST_PICKED[this.value] = this.checked ? true : undefined; suggestUpdateCount(); });
function suggestUpdateCount(){
    var n = Object.keys(SUGGEST_PICKED).filter(function(k){ return SUGGEST_PICKED[k]; }).length;
    $('#suggestPickedCount').text(n ? ('已勾選 '+n+' 筆（篩選不會洗掉已勾選的）') : '');
}
function suggestBatchCreate(){
    // 用 SUGGEST_PICKED 不是 $('.suggest-chk:checked')——篩選過後只有「目前看得到」的列在 DOM 裡，
    // 篩選前勾選、篩選後不在畫面上的那些也要一起送出，否則會悄悄漏掉使用者已經勾選的訂單。
    var ids = Object.keys(SUGGEST_PICKED).filter(function(k){ return SUGGEST_PICKED[k]; });
    if (!ids.length){ alert('請先勾選要建立的訂單'); return; }
    if (!confirm('確定要一次建立 '+ids.length+' 張合約訂單審查表嗎？')) return;
    $.post(API, {action:'batch_create', csrf:META.csrf, order_ids:ids.join(',')}, function(res){
        if (!res.ok){ alert(res.error||'建立失敗'); return; }
        var failN = Object.keys(res.failed||{}).length;
        var msg = '已建立 '+res.created_count+' 張';
        if (failN) msg += '，'+failN+' 張失敗：\n' + Object.values(res.failed).join('\n');
        alert(msg);
        closeMask('suggestMask'); loadList();
    }, 'json');
}

/* ───────────────── 詳情 ───────────────── */
var CUR = null;
function openView(id){
    $.getJSON(API, {action:'get', id:id}, function(res){
        if (!res.ok){ alert(res.error||'載入失敗'); return; }
        CUR = res;
        $('#vwTitle').text('合約訂單審查 — ' + esc(res.doc.doc_no));
        renderView();
        openMask('viewMask');
    });
}
function itemFieldHtml(it, editable){
    var opts = it.options || [];
    var dlId = 'dl_'+it.id;
    var dl = opts.length ? '<datalist id="'+dlId+'">'+opts.map(function(o){return '<option value="'+esc(o)+'"></option>';}).join('')+'</datalist>' : '';
    if (!editable) return esc(it.answer_value||'') + dl;
    return '<input type="text" list="'+dlId+'" value="'+esc(it.answer_value||'')+'" placeholder="可選擇或自行填寫" '
         + 'onchange="itemSave('+it.id+',this.value,null)" style="width:100%;border:1px solid #E8D5B5;border-radius:4px;padding:3px 6px;font-size:12.5px;">' + dl;
}
function renderView(){
    var d = CUR.doc, items = CUR.items, depts = CUR.depts;
    var h = '<div class="cr-hdr-grid">'
        + '<div><span class="k">訂單編號：</span><span class="v">'+esc(d.order_oo)+'</span></div>'
        + '<div><span class="k">客戶：</span><span class="v">'+esc(d.client_name)+'</span></div>'
        + '<div><span class="k">料號：</span><span class="v">'+esc(d.part_no_text)+'</span></div>'
        + '<div><span class="k">數量：</span><span class="v">'+Number(d.qty||0).toLocaleString()+'</span></div>'
        + '<div><span class="k">接單日期(業務日期)：</span><span class="v">'+dispDate(d.business_date)+'</span></div>'
        + '<div><span class="k">交期：</span><span class="v">'+dispDate(d.delivery_date)+'</span></div>'
        + '<div><span class="k">AS 認定：</span><span class="v">'+esc(d.tag_label)+'</span></div>'
        + '<div><span class="k">狀態：</span><span class="v"><span class="st-badge st-'+d.status+'">'+STATUS_LABEL[d.status]+'</span></span></div>'
        + '<div><span class="k">編號：</span><span class="v">'+esc(d.doc_no)+'</span></div>'
        + '</div>';

    // 管理員「自動填寫並簽核」（2026-10-05 使用者交辦）：給例行、低風險訂單一鍵快速走完
    // 送出＋逐項帶入範本預設值＋內容部門自動簽核；業務課決行／總經理核准仍要人工進行。
    if (CUR.is_admin && (d.status==='draft' || d.status==='submitted')) {
        h += '<div style="margin:10px 0;padding:10px 12px;background:#FDF6EC;border:1px dashed #E8D5B5;border-radius:6px;">'
           + '<button type="button" class="cr-btn b-plain" onclick="openAutoFillSign()"><i class="fa fa-magic"></i> 自動填寫並簽核</button>'
           + '<span style="font-size:11.5px;color:#8a6d45;margin-left:10px;">用範本設定的預設值快速帶入尚未填寫的項目，並自動完成內容部門簽核（已填寫/已簽核的不會被覆蓋）。</span>'
           + '</div>';
    }

    h += '<div class="cr-sec-title">審查項目</div>';
    h += '<table class="cr-tbl"><thead><tr><th style="width:60px;">區分</th><th>項目內容</th><th style="width:90px;">負責部門</th><th style="width:170px;">結果</th><th style="width:120px;">填寫人</th></tr></thead><tbody>';
    var lastGrp = null;
    items.forEach(function(it){
        var dp = depts.find(function(x){ return x.dept_id===it.dept_id; });
        var editable = d.status==='submitted' && dp && dp.can_fill && !dp.signed;
        h += '<tr><td>'+esc(it.group_label)+'</td><td>'+esc(it.item_text)+'</td><td>'+esc(it.dept_name||'（未指定）')+'</td>'
           + '<td>'+itemFieldHtml(it, editable)+'</td><td style="font-size:11.5px;color:#8a6d45;">'+esc(it.filled_by_name||'')+'</td></tr>';
    });
    h += '</tbody></table>';

    h += '<div class="cr-sec-title">內容部門簽核</div>';
    depts.forEach(function(dp){
        h += '<div class="cr-dept-box'+(dp.signed?' signed':'')+'"><div class="hd"><b>'+esc(dp.dept_name)+'</b>';
        if (dp.signed) {
            h += '<span style="color:#2d6a3e;font-size:12.5px;"><i class="fa fa-check-circle"></i> '+esc(dp.signed_by_name)+' 於 '+dispDate(dp.signed_at)+' 簽核</span>';
        } else if (d.status==='submitted' && (dp.can_fill || CUR.is_admin)) {
            h += '<span><input type="text" id="deptNote_'+dp.dept_id+'" placeholder="意見(選填)" style="width:200px;border:1px solid #E8D5B5;border-radius:4px;padding:3px 6px;font-size:12px;margin-right:6px;">'
               + '<button type="button" class="cr-btn" style="height:26px;padding:0 10px;font-size:12px;" onclick="deptSign('+dp.dept_id+')">本課確認</button></span>';
        } else {
            h += '<span style="color:#bbb;font-size:12px;">尚未簽核</span>';
        }
        h += '</div>'+(dp.note?('<div style="font-size:12px;color:#8a6d45;margin-top:4px;">意見：'+esc(dp.note)+'</div>'):'')+'</div>';
    });

    h += '<div class="cr-sec-title">決行與核准</div>';
    if (d.status==='draft') {
        h += '<p style="color:#8a6d45;font-size:13px;">尚未送出，送出後各負責部門才能填寫與簽核。</p>';
        if (CUR.can_edit_header) h += '<button type="button" class="cr-btn" onclick="submitDoc()">送出</button>';
    } else {
        h += '<div class="cr-hdr-grid" style="grid-template-columns:1fr 1fr;">';
        h += '<div><span class="k">業務課決行：</span><span class="v">'+(d.decision ? ('<span class="dc-'+d.decision+'">'+esc(DECISIONS[d.decision])+'</span> — '+esc(d.sales_decided_by_name||'')+' '+dispDate(d.sales_decided_at)) : '尚未決行')+'</span></div>';
        h += '<div><span class="k">總經理核准：</span><span class="v">'+(d.gm_approved_by_name ? (esc(d.gm_approved_by_name)+' '+dispDate(d.gm_approved_at)+(d.gm_is_deputy?'（代）':'')) : '尚未核准')+'</span></div>';
        h += '</div>';
        if (d.decision===null && d.status==='submitted') {
            if (CUR.all_depts_signed && (CUR.can_decide || CUR.is_admin)) {
                var opts = Object.keys(DECISIONS).map(function(k){ return '<option value="'+k+'">'+esc(DECISIONS[k])+'</option>'; }).join('');
                h += '<div style="margin-top:8px;"><select id="decideSel" style="width:220px;border:1px solid #E8D5B5;border-radius:4px;padding:5px;">'+opts+'</select> '
                   + '<input type="text" id="decideNote" placeholder="決行意見(選填)" style="width:260px;border:1px solid #E8D5B5;border-radius:4px;padding:5px 8px;"> '
                   + '<button type="button" class="cr-btn" onclick="decideDoc()">決行</button></div>';
            } else if (!CUR.all_depts_signed) {
                h += '<p style="color:#8a6d45;font-size:13px;margin-top:6px;">尚有內容部門未完成簽核，不可決行。</p>';
            }
        } else if (d.decision!==null && d.status==='submitted') {
            if (CUR.gm_signer && (CUR.gm_signer.id===META.uid || CUR.is_admin)) {
                h += '<div style="margin-top:8px;"><button type="button" class="cr-btn" onclick="approveDoc()">核准並結案</button></div>';
            } else {
                h += '<p style="color:#8a6d45;font-size:13px;margin-top:6px;">等待總經理'+(CUR.gm_signer?('（'+esc(CUR.gm_signer.user_cname)+'）'):'')+'核准。</p>';
            }
        }
    }
    $('#vwBody').html(h);
}
function itemSave(itemId, value, note){
    $.post(API, {action:'item_save', csrf:META.csrf, doc_id:CUR.doc.id, item_id:itemId, value:value||'', note:note||''}, function(res){
        if (!res.ok){ alert(res.error||'存檔失敗'); openView(CUR.doc.id); return; }
    }, 'json');
}
function deptSign(deptId){
    var note = $('#deptNote_'+deptId).val();
    $.post(API, {action:'dept_sign', csrf:META.csrf, doc_id:CUR.doc.id, dept_id:deptId, note:note}, function(res){
        if (!res.ok){ alert(res.error||'簽核失敗'); return; }
        openView(CUR.doc.id); loadList();
    }, 'json');
}
function submitDoc(){
    if (!confirm('送出後表頭將鎖定，確定送出？')) return;
    $.post(API, {action:'submit', csrf:META.csrf, doc_id:CUR.doc.id}, function(res){
        if (!res.ok){ alert(res.error||'送出失敗'); return; }
        openView(CUR.doc.id); loadList();
    }, 'json');
}
function decideDoc(){
    var decision = $('#decideSel').val(), note = $('#decideNote').val();
    $.post(API, {action:'decide', csrf:META.csrf, doc_id:CUR.doc.id, decision:decision, note:note}, function(res){
        if (!res.ok){ alert(res.error||'決行失敗'); return; }
        openView(CUR.doc.id); loadList();
    }, 'json');
}
function approveDoc(){
    if (!confirm('核准後本表單結案，確定核准？')) return;
    $.post(API, {action:'approve', csrf:META.csrf, doc_id:CUR.doc.id}, function(res){
        if (!res.ok){ alert(res.error||'核准失敗'); return; }
        openView(CUR.doc.id); loadList();
    }, 'json');
}
function printDoc(){ window.print(); }

/* ───────────────── 管理員：自動填寫並簽核 ───────────────── */
function openAutoFillSign(){
    $('#autoFillDate').val(CUR.doc.business_date);
    $('#autoFillDateHint').text('不可早於接單日期 '+dispDate(CUR.doc.business_date)+'，且必須是工作日');
    $('#autoFillResult').empty();
    $('#btnAutoFillGo').prop('disabled', false).text('執行');
    openMask('autoFillMask');
}
function submitAutoFillSign(){
    var signDate = $('#autoFillDate').val();
    if (!signDate){ alert('請選擇簽核日期'); return; }
    $('#btnAutoFillGo').prop('disabled', true).text('執行中…');
    $.post(API, {action:'admin_auto_fill_sign', csrf:META.csrf, doc_id:CUR.doc.id, sign_date:signDate}, function(res){
        $('#btnAutoFillGo').prop('disabled', false).text('執行');
        if (!res.ok){ alert(res.error||'執行失敗'); return; }
        var h = '<div style="background:#E7F3E8;border:1px solid #bfe0c4;border-radius:6px;padding:8px 10px;font-size:12.5px;">'
              + '已帶入 '+res.filled+' 個項目的預設值'+(res.skipped_no_default?('，'+res.skipped_no_default+' 個項目沒有設定預設值已略過'):'')+'。</div>';
        var signedKeys = Object.keys(res.signed_depts||{});
        if (signedKeys.length) {
            h += '<div style="margin-top:8px;"><b style="font-size:12.5px;color:#2d6a3e;">已自動簽核：</b><ul style="margin:4px 0 0;padding-left:20px;font-size:12px;">';
            signedKeys.forEach(function(k){
                var info = res.signed_depts[k];
                h += '<li>'+esc(info.dept_name)+'　由 '+esc(info.signer_name)+' 簽核'
                   + (info.note_warnings && info.note_warnings.length ? '　<span style="color:#b5862f;">（原候選人當天不在：'+esc(info.note_warnings.join('；'))+'，已改由下一位）</span>' : '')
                   + '</li>';
            });
            h += '</ul></div>';
        }
        var unsignedKeys = Object.keys(res.unsigned_depts||{});
        if (unsignedKeys.length) {
            h += '<div style="margin-top:8px;"><b style="font-size:12.5px;color:#c0392b;">未能自動簽核（請自行處理）：</b><ul style="margin:4px 0 0;padding-left:20px;font-size:12px;">';
            unsignedKeys.forEach(function(k){
                var info = res.unsigned_depts[k];
                h += '<li>'+esc(info.dept_name)+'：'+esc(info.reason)+'</li>';
            });
            h += '</ul></div>';
        }
        $('#autoFillResult').html(h);
        openView(CUR.doc.id); loadList();
    }, 'json').fail(function(){ $('#btnAutoFillGo').prop('disabled', false).text('執行'); });
}

/* ───────────────── 範本維護 ───────────────── */
function loadTpl(){
    $.getJSON(API, {action:'tpl_list'}, function(res){
        if (!res.ok){ alert(res.error||'載入失敗'); return; }
        renderTpl(res.items);
    });
}
var CNRV_BASE_OPTIONS = ['是','否','N/A'];   // 跟後端 CNRV_BASE_OPTIONS 同一份固定基礎選項
/* 預設值選單選項：固定的是/否/N-A + 這一列目前的額外選項，去重。
   跟後端 cnrv_merge_options() 是同一份規則（基礎三選項固定在前）。 */
function tplDefaultOptsHtml(customOptsArr, curVal){
    var allOpts = CNRV_BASE_OPTIONS.concat((customOptsArr||[]).filter(function(o){ return CNRV_BASE_OPTIONS.indexOf(o)===-1; }));
    var h = '<option value="">（不設定）</option>';
    allOpts.forEach(function(o){ h += '<option value="'+esc(o)+'"'+(o===curVal?' selected':'')+'>'+esc(o)+'</option>'; });
    return h;
}
function renderTpl(items){
    var deptOpts = '<option value="">（未指定）</option>' + (META.departments||[]).map(function(d){ return '<option value="'+d.id+'">'+esc(d.name)+'</option>'; }).join('');
    var h = (items||[]).map(function(it){
        var sel = deptOpts.replace('value="'+it.dept_id+'"', 'value="'+it.dept_id+'" selected');
        return '<tr data-id="'+it.id+'">'
             + '<td><input type="text" data-f="sort_order" value="'+it.sort_order+'" style="width:50px;" onchange="tplEdit('+it.id+',this)"></td>'
             + '<td><input type="text" data-f="group_label" value="'+esc(it.group_label)+'" style="width:60px;" onchange="tplEdit('+it.id+',this)"></td>'
             + '<td><input type="text" data-f="item_text" value="'+esc(it.item_text)+'" onchange="tplEdit('+it.id+',this)"></td>'
             + '<td><select data-f="dept_id" onchange="tplEdit('+it.id+',this)">'+sel+'</select></td>'
             + '<td><input type="text" data-f="options" value="'+esc((it.custom_options||[]).join(','))+'" title="在「是／否／N/A」固定三選項之外，這個項目額外可選的回覆" onchange="tplOptionsChanged('+it.id+',this)"></td>'
             // 預設值一律是「下拉選」不是打字——直接綁定當下的額外選項清單，不可能選到不存在的值
             // （2026-10-05 使用者要求：預設值要「對應到可以綁定的額外選項」，不要用自由輸入的 combo）。
             + '<td><select data-f="default_value" title="管理員按「自動填寫並簽核」時，這個項目沒人填就自動帶入這個值" onchange="tplEdit('+it.id+',this)">'+tplDefaultOptsHtml(it.custom_options, it.default_value)+'</select></td>'
             + '<td style="text-align:center;"><input type="checkbox" data-f="is_active" '+(it.is_active?'checked':'')+' onchange="tplEdit('+it.id+',this)"></td>'
             + '<td style="text-align:center;"><span class="rf-mini-btn" onclick="tplDel('+it.id+')"><i class="fa fa-times"></i> 刪除</span></td></tr>';
    }).join('');
    $('#tplBody').html(h || '<tr><td colspan="8" style="text-align:center;color:#999;">尚無項目，可按上方「從產品開發評估表複製項目」當起點</td></tr>');
}
/* 改用 data-f 屬性而非位置索引讀欄位——新增欄位時位置索引很容易悄悄錯位（本頁已有前車之鑑）。 */
function tplRowData(tr){
    return {
        id: tr.data('id'),
        sort_order: tr.find('[data-f=sort_order]').val(),
        group_label: tr.find('[data-f=group_label]').val(),
        item_text: tr.find('[data-f=item_text]').val(),
        dept_id: tr.find('[data-f=dept_id]').val(),
        options: tr.find('[data-f=options]').val(),
        default_value: tr.find('[data-f=default_value]').val(),
        is_active: tr.find('[data-f=is_active]').is(':checked') ? 1 : 0,
    };
}
function tplEdit(id){
    var tr = $('#tplBody tr[data-id='+id+']');
    var data = tplRowData(tr);
    $.post(API, {action:'tpl_save', csrf:META.csrf, id:id, sort_order:data.sort_order, group_label:data.group_label,
                 item_text:data.item_text, dept_id:data.dept_id, options:data.options, default_value:data.default_value, is_active:data.is_active}, function(res){
        if (!res.ok){ alert(res.error||'儲存失敗'); loadTpl(); return; }
    }, 'json');
}
/* 改「額外選項」時，如果目前的預設值已經不在新的選項清單裡，要連帶清掉，否則後端會因為
   「預設值不是合法選項」擋下整列存檔，使用者卻看不出是哪裡出錯。 */
function tplOptionsChanged(id, el){
    var tr = $('#tplBody tr[data-id='+id+']');
    var customOpts = $.trim(el.value).split(',').map(function(s){ return $.trim(s); }).filter(Boolean);
    var $dv = tr.find('[data-f=default_value]');
    var curVal = $dv.val();
    // 預設值下拉是「綁定」在這一列目前的額外選項上——改了額外選項，候選清單要立刻跟著重建
    // （select 是 renderTpl() 畫表格當下就定型的，不重建的話剛打的新選項要存檔+整表重畫才選得到）；
    // 原本選的值如果已經不在新清單裡，一併清空要求重選（tplDefaultOptsHtml 選不到就自動落在「不設定」）。
    $dv.html(tplDefaultOptsHtml(customOpts, curVal));
    tplEdit(id);
}
function tplAdd(){
    $.post(API, {action:'tpl_save', csrf:META.csrf, id:0, sort_order:9999, group_label:'', item_text:'（新項目，請編輯內容）', dept_id:0, options:'', default_value:'', is_active:1}, function(res){
        if (!res.ok){ alert(res.error||'新增失敗'); return; }
        renderTpl(res.items);
    }, 'json');
}
function tplDel(id){
    if (!confirm('確定刪除此範本項目？（已建立的審查表單不受影響，只影響之後新建的）')) return;
    $.post(API, {action:'tpl_delete', csrf:META.csrf, id:id}, function(res){
        if (!res.ok){ alert(res.error||'刪除失敗'); return; }
        renderTpl(res.items);
    }, 'json');
}
function tplSeedFromDevEval(){
    $.getJSON(API, {action:'tpl_list'}, function(res){
        var has = (res.items||[]).length;
        var msg = has ? ('目前已經有 '+has+' 項，複製會「接在後面」不會覆蓋。要繼續嗎？')
                      : '要把產品開發評估表的 32 項確認項目複製過來嗎？\n\n複製過來之後兩邊各自獨立，可自行刪減修改。';
        if (!confirm(msg)) return;
        $.post(API, {action:'tpl_seed_from_dev_eval', csrf:META.csrf}, function(r2){
            if (!r2.ok){ alert(r2.error||'複製失敗'); return; }
            renderTpl(r2.items);
            alert('已複製 ' + r2.added + ' 項，請確認內容是否符合需要。');
        }, 'json');
    });
}
$('#btnTpl').on('click', function(){ loadTpl(); openMask('tplMask'); });

/* 深連結（2026-10-05）：從訂單追蹤頁的入口連結過來，語意比照 part_viewer 的 ?tags_setting=1——
   頁面照常載入之後多做一件事，守門與必填一字不動；查不到就安靜退回正常畫面，不留白畫面。
   這裡是一般 <a target="_blank"> 真的被使用者點擊開新分頁，不是 JS 觸發的 window.open()，
   不會被彈出視窗封鎖，不需要 document.open/write/close 那一套。 */
var Q_OPEN_ID = parseInt(new URLSearchParams(location.search).get('open_id'), 10) || 0;
var Q_NEW_ORDER = parseInt(new URLSearchParams(location.search).get('new_order'), 10) || 0;
function openAddForOrder(orderId){
    $('#btnAdd').trigger('click');
    $.getJSON(API, {action:'order_get', order_id:orderId}, function(res){
        if (!res.ok || !res.order) return;      // 查不到就停在「已開啟新增表單」，使用者自己挑
        var o = res.order;
        if (o.doc_id){ $('#addOrdErr').text('這張訂單已經建立過審查表單（#'+o.doc_id+'），請直接開啟原本那一張').show(); return; }
        if (!o.need_review){ $('#addOrdErr').text('這張訂單的 AS 認定不需要做合約訂單審查').show(); return; }
        ADD_ORD = o; $('#addOrdErr').hide();
        $('#addOrdPicked').html('<b>'+esc(o.Order_oo)+'</b>　<span class="rf-mini-btn" onclick="addOrdClear()">改選</span>'
            + '<br>客戶：'+esc(o.client_name_txt||o.Client_name||'（未綁客戶主檔）')
            + '<br>料號：'+esc(o.d_id||'')+'　數量：'+Number(o.Qty||0).toLocaleString()+' 件'
            + '<br>接單日期：'+dispDate(o.Order_date)+'　交期：'+dispDate(o.Delivery_date)
            + '<br>AS 認定：'+esc(o.tag_label||'')).show();
    });
}
loadMeta(function(){
    loadList();
    if (Q_OPEN_ID) openView(Q_OPEN_ID);
    else if (Q_NEW_ORDER) openAddForOrder(Q_NEW_ORDER);
});
</script>
</body>
</html>
