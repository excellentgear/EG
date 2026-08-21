<?php
/**
 * 列印與簽核紀錄（2026-08-21 使用者明確要求）
 *
 * 分頁一「列印紀錄」：誰、何時、在哪台電腦、印了哪份文件。
 *   來源＝新式 print_log（附件列印：料號主檔／BOM檢視器／料號檢視器／批圖編輯器）
 *       ＋既有各模組的列印紀錄表（報價單／PFMEA／產品開發評估表／文件制修申請單／表單簽核案件）。
 * 分頁二「簽核紀錄」：文件名稱、送件日期、簽核人、簽核日期時間、結果（許可／不許可／待簽核）與回覆。
 *   來源＝全站共用的 approval_record（含自動產生的簽核紀錄）。
 *   ※ 依使用者要求，畫面上一律不出現「自動簽核」字樣。
 *
 * 全部邏輯在 src/common/print_log_lib.php，資料一律走 src/store/PrintSignLog_API.php。
 * 權限：roles module='print_sign_log' → psl_admin(管理)／psl_view_all(查全部)；
 *       沒有角色的人只看得到自己的紀錄（後端強制綁 user_id，不靠前端）。
 * 規則見 ai-rules/23-列印與簽核紀錄.md。
 */
session_start();
if (!isset($_SESSION['userName'])) {
    $_SESSION['lastpage'] = "../../views/admin/print_sign_log.php";
    header("Location:../../index.php");
    exit;
}
include_once '../../src/common/_config.php';
include_once '../../src/common/DBConnection.php';
include_once '../../src/common/print_log_lib.php';

$db = (new DBConnection())->getPDO();
$u  = eg_printlog_current_user($db);
$perms = eg_printlog_perms($db, $u);

$roleLabel = $perms['isAdmin'] ? '管理者'
           : ($perms['canAdmin'] ? '紀錄管理' : ($perms['canViewAll'] ? '紀錄檢閱' : '僅本人紀錄'));

// 列印大標題的公司全名：一律取主檔設為「本公司」的那筆，禁寫死（ai-rules/16）
$companyName = '';
try {
    $st = $db->query("SELECT customer_full, customer FROM customer_list WHERE is_own_company=1 LIMIT 1");
    if ($row = $st->fetch(PDO::FETCH_ASSOC)) $companyName = trim((string)($row['customer_full'] ?: $row['customer']));
} catch (Throwable $e) {}
?>
<!DOCTYPE html>
<html lang="zh-Hant">
<head>
    <meta charset="utf-8">
    <meta http-equiv="X-UA-Compatible" content="IE=edge">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>列印與簽核紀錄</title>
    <link href="../../resource/css/bootstrap.css" rel="stylesheet">
    <link href="../../resource/css/font-awesome.css" rel="stylesheet">
    <link href="../../resource/css/nprogress.css" rel="stylesheet">
    <link href="../../resource/css/custom.css" rel="stylesheet">
    <style>
        #sidebar-menu { visibility: hidden; }
        .right_col .page-title { margin:8px 0 4px; overflow:hidden; clear:both; }
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
        .cov-box { border:1px solid #E8D5B5; border-radius:6px; padding:6px 10px; margin:4px 0 10px; background:#FDF8EF; max-height:230px; overflow-y:auto; }
        .cov-box ul { margin:2px 0; padding-left:18px; }
        .cov-yes { color:#5b7a2b; font-weight:bold; }
        .cov-no  { color:#C0563A; font-weight:bold; }
        .cov-note { font-size:12px; color:#8a6d45; }
        .ps-toolbar { clear:both; border:1.5px solid #E8D5B5; border-radius:8px; padding:8px 10px;
            margin-bottom:10px; background:#FDF8EF; }
        /* 每一列各自 flex：日期區間才不會被自動換行拆成「起日在上一列、迄日在下一列」 */
        .ps-row { display:flex; flex-wrap:wrap; gap:6px 10px; align-items:center; }
        .ps-row + .ps-row { margin-top:8px; padding-top:8px; border-top:1px dashed #E8D5B5; }
        .ps-date { display:inline-flex; align-items:center; gap:6px; white-space:nowrap; }
        .ps-toolbar label { margin:0; font-size:13px; color:#5b3a1e; }
        .ps-toolbar select, .ps-toolbar button, .ps-toolbar input[type=date], .ps-toolbar input[type=text] {
            height:30px; font-size:13px; line-height:1; padding:0 8px; border:1px solid #D8BE93;
            border-radius:4px; background:#fff; color:#5b3a1e; }
        .ps-toolbar button { cursor:pointer; padding:0 12px; }
        .ps-toolbar button:hover { background:#F7E0BD; }
        .ps-toolbar .btn-warm { background:#F0A24B; color:#fff; border-color:#d98a33; }
        .ps-toolbar .btn-warm:hover { background:#d98a33; }
        .ps-role-badge { margin-left:auto; font-size:13px; color:#5b3a1e; background:#F7E0BD; border-radius:12px; padding:4px 12px; }
        .ps-role-badge .fa-question-circle { cursor:pointer; color:#b5762a; margin-left:5px; }
        .ps-tabs { display:flex; gap:4px; margin-bottom:8px; border-bottom:2px solid #E8D5B5; clear:both; }
        .ps-tab { border:1px solid #E8D5B5; border-bottom:none; background:#FBF3E5; color:#8a6d45; cursor:pointer;
            padding:7px 16px; font-size:14px; border-radius:6px 6px 0 0; margin-bottom:-2px; }
        .ps-tab.active { background:#fff; color:#5b3a1e; font-weight:bold; border-bottom:2px solid #fff; }
        .ps-cnt { display:inline-block; min-width:18px; padding:0 5px; margin-left:4px; border-radius:9px;
            background:#F0A24B; color:#fff; font-size:11px; line-height:16px; }
        .ps-cnt.zero { background:#E8D5B5; color:#8a6d45; }
        .ps-pagebar { display:flex; justify-content:flex-end; align-items:center; gap:6px; margin-bottom:6px; font-size:13px; color:#5b3a1e; }
        .ps-pagebar select { height:26px; font-size:12px; border:1px solid #D8BE93; border-radius:4px; }
        .ps-pagebar button { height:26px; font-size:12px; padding:0 8px; border:1px solid #D8BE93; border-radius:4px; background:#fff; color:#5b3a1e; cursor:pointer; }
        .ps-pagebar button:disabled { color:#c9bda9; cursor:default; }
        .ps-pagebar button.cur { background:#F0A24B; color:#fff; border-color:#d98a33; }
        .ps-table-wrap { overflow-x:auto; border:1px solid #E8D5B5; border-radius:6px; background:#fff; }
        table.ps-table { width:100%; border-collapse:collapse; font-size:13px; }
        table.ps-table th, table.ps-table td { border:1px solid #EADFC8; padding:5px 8px; text-align:center; }
        table.ps-table thead th { background:#F7E0BD; color:#5b3a1e; font-weight:bold; white-space:nowrap; }
        table.ps-table tbody tr:nth-child(even) { background:#FBF6EC; }
        table.ps-table tbody tr:hover { background:#FBF0DD; }
        table.ps-table td.t-left { text-align:left; word-break:break-all; }
        .src-pill { display:inline-block; font-size:11px; border-radius:10px; padding:1px 8px; background:#F7E0BD; color:#7a5217; white-space:nowrap; }
        .src-pill.legacy { background:#FFF3E2; color:#C77C1A; border:1px solid #E4D3BC; }
        .res-pill { display:inline-block; font-size:11px; border-radius:10px; padding:1px 10px; white-space:nowrap; font-weight:bold; }
        .res-ok   { background:#F0A24B; color:#fff; }
        .res-no   { background:#DD5138; color:#fff; }
        .res-wait { background:#F5EFE3; color:#8a6d45; border:1px solid #E8D5B5; }
        .ps-host { font-family:Consolas,monospace; font-size:12px; color:#5b3a1e; }
        .ps-host small { color:#a08c6a; }
        .ps-empty { padding:26px; text-align:center; color:#8a6d45; }
        .ps-noperm { margin:40px auto; max-width:520px; text-align:center; border:1.5px solid #E8D5B5; border-radius:10px;
            padding:30px; background:#FDF8EF; color:#5b3a1e; }
        .ps-mask { display:none; position:fixed; inset:0; background:rgba(60,40,20,.45); z-index:1050; }
        .ps-modal { background:#fff; border-radius:8px; max-width:820px; margin:36px auto; box-shadow:0 5px 25px rgba(0,0,0,.3);
            max-height:90vh; display:flex; flex-direction:column; }
        .ps-modal .m-head { background:#F7E0BD; color:#5b3a1e; font-weight:bold; padding:10px 15px; border-radius:8px 8px 0 0;
            display:flex; justify-content:space-between; }
        .ps-modal .m-head .m-close { cursor:pointer; color:#b5762a; }
        .ps-modal .m-body { padding:15px; overflow-y:auto; font-size:13px; color:#5b3a1e; }
        .ps-modal .m-foot { padding:10px 15px; border-top:1px solid #EADFC8; text-align:right; }
        .ps-modal .m-foot button { height:30px; padding:0 16px; border-radius:4px; font-size:13px; border:1px solid #d98a33; cursor:pointer;
            background:#F0A24B; color:#fff; }
        .ps-scroll-top { display:none; position:fixed; right:24px; bottom:28px; z-index:900; width:42px; height:42px;
            border-radius:50%; border:1px solid #d98a33; background:#F0A24B; color:#fff; font-size:17px; cursor:pointer; }
    </style>
</head>
<body class="nav-sm">
<div class="container body">
<div class="main_container">
    <?php include '../partPage/sideAndTopBarMenu.html' ?>
    <div class="right_col" role="main">
        <div class="page-title" style="display:flex;align-items:center;flex-wrap:wrap;">
            <h3 style="margin:0;font-size:20px;color:#5b3a1e;">
                <i class="fa fa-history" style="color:#F0A24B;"></i> 列印與簽核紀錄
            </h3>
            <button id="btnPageHelp" class="page-help-btn" style="margin-left:auto;"><i class="fa fa-question-circle"></i> 使用說明</button>
        </div>

        <div class="ps-tabs">
            <div class="ps-tab active" data-tab="print"><i class="fa fa-print"></i> 列印紀錄<span class="ps-cnt zero" id="cntPrint">0</span></div>
            <div class="ps-tab" data-tab="sign"><i class="fa fa-check-square-o"></i> 簽核紀錄<span class="ps-cnt zero" id="cntSign">0</span></div>
        </div>

        <div class="ps-toolbar">
            <!-- 第一列：下拉篩選（選了就即時查，不必按查詢） -->
            <div class="ps-row">
                <label>資料來源</label>
                <select id="fSource" data-eg-filter="輸入來源名稱篩選…" style="min-width:190px;"><option value="">全部</option></select>

                <label id="lblPerson">列印人</label>
                <select id="fUser" data-eg-filter="輸入姓名篩選…" style="min-width:190px;"><option value="">全部</option></select>

                <span class="ps-role-badge">目前身分：<b id="roleName"><?= htmlspecialchars($roleLabel) ?></b><i class="fa fa-question-circle" id="btnRoleHelp" title="各角色權限說明"></i></span>
            </div>

            <!-- 第二列：日期區間整組不拆行＋關鍵字＋動作鈕 -->
            <div class="ps-row">
                <span class="ps-date"><label>日期</label><input type="date" id="fFrom"> ～ <input type="date" id="fTo"></span>

                <input type="text" id="fKw" placeholder="文件名稱／料號／姓名…（邊打邊查）" style="min-width:220px;">

                <button id="btnReset"><i class="fa fa-refresh"></i> 本月</button>
                <button class="btn-warm" id="btnPrintAll"><i class="fa fa-print"></i> 列印全部篩選結果</button>
            </div>
        </div>

        <div class="ps-pagebar">
            <span id="pgInfo"></span>
            <label style="margin:0 2px 0 8px;">每頁</label>
            <select id="fPer"><option>5</option><option selected>10</option><option>20</option><option>50</option></select>
            <span id="pgBtns"></span>
        </div>

        <div class="ps-table-wrap">
            <table class="ps-table">
                <thead id="tHead"></thead>
                <tbody id="tBody"></tbody>
            </table>
        </div>
        <div class="ps-empty" id="emptyBox" style="display:none;">沒有符合條件的紀錄</div>

    </div>
</div>
</div>

<button class="ps-scroll-top" id="btnTop" title="回到頂端"><i class="fa fa-arrow-up"></i></button>

<!-- 使用說明 -->
<div class="ps-mask" id="helpUseMask"><div class="ps-modal">
    <div class="m-head"><span><i class="fa fa-question-circle"></i> 列印與簽核紀錄 使用說明</span><span class="m-close" onclick="closeMask('helpUseMask')">✕</span></div>
    <div class="m-body help-doc">
        <h4>這個頁面在做什麼</h4>
        <ul>
            <li><b>列印紀錄</b>：誰、什麼時候、在哪一台電腦、印了哪一份文件。附件列印會一併記下相關料號。</li>
            <li><b>簽核紀錄</b>：文件名稱、送件日期、簽核人、簽核日期時間、結果（許可／不許可／待簽核）與簽核意見。</li>
        </ul>

        <h4>操作步驟</h4>
        <ul>
            <li>上方分頁切換「列印紀錄／簽核紀錄」，兩個分頁共用同一組篩選條件。</li>
            <li>篩選：<b>資料來源</b>（列印分頁是頁面／模組，簽核分頁是單據種類）、<b>列印人／簽核人</b>、<b>日期區間</b>，另可用關鍵字查文件名稱、料號、姓名。</li>
            <li><b>全部篩選都是即時的</b>：下拉一選、日期一改、關鍵字邊打就邊查，不需要按任何查詢按鈕。</li>
            <li>下拉選項多時可直接在篩選框打字過濾，不必用眼睛找。</li>
            <li><b>列印全部篩選結果</b>：印的是目前篩選條件下的<b>全部</b>資料，不是只有畫面這一頁。</li>
            <li>清單分頁在表格右上角，預設每頁 10 筆（可改 5／20／50）；改成每頁超過 10 筆時，右下角會出現「回到頂端」按鈕。</li>
        </ul>

        <h4>重要行為／常見疑問</h4>
        <div class="tip">
            <b>「按下列印」就會留紀錄</b>：瀏覽器不會告訴系統使用者在列印對話框最後是按了確定還是取消，
            所以按取消也一樣留一筆。這是刻意的——否則按取消就能規避紀錄。
        </div>
        <ul>
            <li><b>登入電腦</b>：顯示電腦名稱與 IP。電腦名稱由 IP 反查而來（內網 NetBIOS），查不到時只顯示 IP。</li>
            <li><b>預設區間</b>：每次進來預設顯示<b>本月</b>資料；按「本月」可隨時回到預設。</li>
            <li><b>舊的列印紀錄</b>（報價單、PFMEA 等模組本來就有的）沒有 IP 與電腦名稱欄位，那兩欄會顯示「—」。</li>
            <li><b>簽核紀錄的日期區間</b>：送件日期或簽核日期任一落在區間內都會列出。</li>
            <li>沒有被指派角色的人，只看得到<b>自己</b>的紀錄（這是後端擋的，不是只有畫面隱藏）。</li>
        </ul>

        <h4>目前涵蓋哪些表單（即時掃描，不是固定清單）</h4>
        <p class="cov-note">以下由系統當場掃描產生：列印是掃各頁面實際有沒有接上紀錄程式，簽核是查資料庫裡實際的簽核資料表，所以有人新增頁面或模組時這份清單會自己更新。</p>
        <div id="covBox" class="cov-box">載入中…</div>

        <h4>設定入口與權限角色</h4>
        <ul>
            <li>角色指派：<b>系統設定 → 使用者權限（user_permissions.php）</b>的「列印與簽核紀錄」區塊。</li>
            <li><b>紀錄管理（psl_admin）</b>：查全部紀錄、列印匯出。</li>
            <li><b>紀錄檢閱（psl_view_all）</b>：查全部紀錄（唯讀）。</li>
            <li><b>未指派角色</b>：只能查自己的紀錄。管理者固定擁有全部權限。</li>
        </ul>
    </div>
    <div class="m-foot"><button onclick="closeMask('helpUseMask')">我知道了</button></div>
</div></div>

<!-- 角色說明 -->
<div class="ps-mask" id="roleHelpMask"><div class="ps-modal" style="max-width:560px;">
    <div class="m-head"><span><i class="fa fa-users"></i> 角色權限說明</span><span class="m-close" onclick="closeMask('roleHelpMask')">✕</span></div>
    <div class="m-body" id="roleHelpBody">載入中…</div>
    <div class="m-foot"><button onclick="closeMask('roleHelpMask')">關閉</button></div>
</div></div>

<script src="../../resource/js/jquery.min.js"></script>
<script src="../../resource/js/bootstrap.min.js"></script>
<script src="../../resource/js/fastclick.js"></script>
<script src="../../resource/js/nprogress.js"></script>
<script src="../../resource/js/custom.min.js"></script>
<script src="../../resource/js/eg_input_rules.js?v=<?= @filemtime(__DIR__.'/../../resource/js/eg_input_rules.js') ?>"></script>
<script src="../../resource/js/eg_date_fmt.js?v=<?= @filemtime(__DIR__.'/../../resource/js/eg_date_fmt.js') ?>"></script>
<script>
$(document).ready(function(){
    var $am = $('#sidebar-menu .nav.side-menu > li.active');
    if ($am.length) { $am.removeClass('active').find('ul.child_menu').hide(); $am.find('li.current-page').removeClass('current-page'); }
    $('#sidebar-menu').css('visibility','visible');
});

var API = '../../src/store/PrintSignLog_API.php';
var COMPANY = <?= json_encode($companyName, JSON_UNESCAPED_UNICODE) ?>;
var META = null;
var TAB  = 'print';
var PAGE = 1;
var LAST = { rows: [], total: 0 };

function esc(s){ return $('<div>').text(s == null ? '' : String(s)).html(); }
// 顯示用日期一律 YYYY.MM.DD（ai-rules/20，唯一實作在 eg_date_fmt.js）
function dispDate(d){ return egFmtDate(d); }
function dispDateTime(d){ return egFmtDate(d, true); }
function openMask(id){ document.getElementById(id).style.display='block'; }
function closeMask(id){ document.getElementById(id).style.display='none'; }

// 本月：1 號 ~ 今天
function monthRange(){
    var d = new Date(), p = function(n){ return String(n).padStart(2,'0'); };
    var y = d.getFullYear(), m = p(d.getMonth()+1);
    return { from: y+'-'+m+'-01', to: y+'-'+m+'-'+p(d.getDate()) };
}
function resetRange(){
    var r = monthRange();
    $('#fFrom').val(r.from); $('#fTo').val(r.to);
}

function filters(extra){
    var f = {
        user_id  : $('#fUser').val() || '',
        date_from: $('#fFrom').val() || '',
        date_to  : $('#fTo').val() || '',
        kw       : $.trim($('#fKw').val() || ''),
        per      : $('#fPer').val() || 10,
        page     : PAGE
    };
    // 來源欄位在兩個分頁是不同的東西：列印分頁篩頁面來源、簽核分頁篩單據種類
    if (TAB === 'print') f.source = $('#fSource').val() || '';
    else                 f.module = $('#fSource').val() || '';
    return $.extend(f, extra || {});
}

// ── 來源下拉依分頁重建 ──────────────────────────────────────────────────
function fillSourceSel(){
    var list = (TAB === 'print') ? (META.sources || []) : (META.modules || []);
    var $s = $('#fSource').html('<option value="">全部</option>');
    list.forEach(function(x){ $s.append('<option value="'+esc(x.code)+'">'+esc(x.label)+'</option>'); });
    $('#lblPerson').text(TAB === 'print' ? '列印人' : '簽核人');
}

function fillPeopleSel(){
    var $s = $('#fUser').html('<option value="">全部</option>');
    if (!META.perms.canViewAll) {
        // 只能看自己的人不給選別人（後端也擋），下拉直接鎖成本人
        $s.html('<option value="'+META.me.id+'">'+esc(META.me.name)+'（本人）</option>');
        $s.prop('disabled', true);
        return;
    }
    (META.people || []).forEach(function(p){
        // 欄位順序固定「部門／職稱／姓名」（人員列表鐵則）
        var t = [p.dept_name || '－', p.position_name || '－', p.user_cname].join('　');
        if (String(p.state) === '0') t += '（已離職）';
        $s.append('<option value="'+p.id+'">'+esc(t)+'</option>');
    });
}

// ── 清單 ────────────────────────────────────────────────────────────────
var HEAD_PRINT = ['列印時間','資料來源','文件名稱','相關料號','列印人','登入電腦','備註'];
var HEAD_SIGN  = ['文件名稱','單據種類','送件日期','送件人','簽核關卡','簽核人','簽核日期時間','結果','回覆意見'];

function renderHead(){
    var cols = (TAB === 'print') ? HEAD_PRINT : HEAD_SIGN;
    $('#tHead').html('<tr>' + cols.map(function(c){ return '<th>'+esc(c)+'</th>'; }).join('') + '</tr>');
}

function rowHtmlPrint(r){
    var host = r.client_host ? esc(r.client_host) + (r.client_ip ? ' <small>('+esc(r.client_ip)+')</small>' : '')
             : (r.client_ip ? esc(r.client_ip) : '—');
    return '<tr>'
        + '<td style="white-space:nowrap;">' + esc(dispDateTime(r.printed_at)) + '</td>'
        + '<td><span class="src-pill' + (r.client_ip ? '' : ' legacy') + '">' + esc(r.source_label) + '</span></td>'
        + '<td class="t-left">' + esc(r.doc_name) + '</td>'
        + '<td>' + esc(r.part_no || '—') + '</td>'
        + '<td>' + esc(r.printed_by_name || '—') + '</td>'
        + '<td class="ps-host">' + host + '</td>'
        + '<td>' + esc(r.note || '') + '</td>'
        + '</tr>';
}

function rowHtmlSign(r){
    var cls = r.status === 'approved' ? 'res-ok' : (r.status === 'rejected' ? 'res-no' : 'res-wait');
    return '<tr>'
        + '<td class="t-left">' + esc(r.doc_name) + '</td>'
        + '<td>' + esc(r.module_label) + '</td>'
        + '<td style="white-space:nowrap;">' + esc(dispDate(r.doc_date || r.submitted_at)) + '</td>'
        + '<td>' + esc(r.submitted_by_name || '—') + '</td>'
        + '<td>' + esc(r.level_label) + '</td>'
        + '<td>' + esc(r.approver_name || '—') + '</td>'
        + '<td style="white-space:nowrap;">' + (r.decided_at ? esc(dispDateTime(r.decided_at)) : '—') + '</td>'
        + '<td><span class="res-pill ' + cls + '">' + esc(r.result_label) + '</span></td>'
        + '<td class="t-left">' + esc(r.note || '') + '</td>'
        + '</tr>';
}

function renderPager(total, per, page){
    var pages = per > 0 ? Math.max(1, Math.ceil(total / per)) : 1;
    if (page > pages) page = pages;
    var from = total ? (page - 1) * per + 1 : 0;
    var to   = Math.min(total, page * per);
    $('#pgInfo').text('共 ' + total + ' 筆' + (total ? '（第 ' + from + '～' + to + ' 筆）' : ''));
    var h = '';
    h += '<button ' + (page <= 1 ? 'disabled' : '') + ' data-p="1">«</button>';
    h += '<button ' + (page <= 1 ? 'disabled' : '') + ' data-p="' + (page - 1) + '">‹</button>';
    var s = Math.max(1, page - 2), e = Math.min(pages, s + 4);
    s = Math.max(1, e - 4);
    for (var i = s; i <= e; i++) h += '<button class="' + (i === page ? 'cur' : '') + '" data-p="' + i + '">' + i + '</button>';
    h += '<button ' + (page >= pages ? 'disabled' : '') + ' data-p="' + (page + 1) + '">›</button>';
    h += '<button ' + (page >= pages ? 'disabled' : '') + ' data-p="' + pages + '">»</button>';
    $('#pgBtns').html(h);
}

function loadList(){
    var act = (TAB === 'print') ? 'list_print' : 'list_sign';
    $('#tBody').html('<tr><td colspan="9" style="padding:20px;color:#8a6d45;">載入中…</td></tr>');
    $.post(API, $.extend({ action: act }, filters()), function(res){
        if (!res || !res.ok) { $('#tBody').html('<tr><td colspan="9" style="padding:20px;color:#DD5138;">'+esc((res&&res.error)||'查詢失敗')+'</td></tr>'); return; }
        LAST = { rows: res.rows || [], total: res.total || 0 };
        PAGE = res.page || 1;
        renderHead();
        var f = (TAB === 'print') ? rowHtmlPrint : rowHtmlSign;
        $('#tBody').html(LAST.rows.map(f).join(''));
        $('#emptyBox').toggle(LAST.rows.length === 0);
        renderPager(LAST.total, parseInt(res.per, 10) || 10, PAGE);
        var $c = $(TAB === 'print' ? '#cntPrint' : '#cntSign');
        $c.text(LAST.total).toggleClass('zero', LAST.total === 0);
    }, 'json').fail(function(){
        $('#tBody').html('<tr><td colspan="9" style="padding:20px;color:#DD5138;">查詢失敗（連線異常）</td></tr>');
    });
}

// 兩個分頁的筆數徽章都要即時（點開即刷新鐵則：切分頁連帶刷新另一邊的計數）
function refreshOtherCount(){
    var act = (TAB === 'print') ? 'list_sign' : 'list_print';
    var f = filters({ per: 5, page: 1 });
    // 另一個分頁的來源條件不通用，計數時不套來源篩選
    delete f.source; delete f.module;
    $.post(API, $.extend({ action: act }, f), function(res){
        if (!res || !res.ok) return;
        var $c = $(TAB === 'print' ? '#cntSign' : '#cntPrint');
        $c.text(res.total || 0).toggleClass('zero', (res.total || 0) === 0);
    }, 'json');
}

// ── 列印全部篩選結果（ai-rules/16：大標題本公司名、頁碼左下、只有多頁才印頁碼）──
function printAll(){
    var act = (TAB === 'print') ? 'list_print' : 'list_sign';
    var $b = $('#btnPrintAll').prop('disabled', true).text('整理中…');
    $.post(API, $.extend({ action: act }, filters({ per: 0, page: 1 })), function(res){
        $b.prop('disabled', false).html('<i class="fa fa-print"></i> 列印全部篩選結果');
        if (!res || !res.ok) { alert((res && res.error) || '取得資料失敗'); return; }
        var rows = res.rows || [];
        if (!rows.length) { alert('目前篩選條件下沒有資料可列印'); return; }

        var cols = (TAB === 'print') ? HEAD_PRINT : HEAD_SIGN;
        var title = (TAB === 'print') ? '列印紀錄' : '簽核紀錄';
        var cond = [];
        var srcTxt = $('#fSource option:selected').text();
        if ($('#fSource').val()) cond.push('資料來源：' + srcTxt);
        if ($('#fUser').val())   cond.push((TAB === 'print' ? '列印人：' : '簽核人：') + $.trim($('#fUser option:selected').text()));
        if ($('#fFrom').val() || $('#fTo').val())
            cond.push('日期：' + (dispDate($('#fFrom').val()) || '不限') + ' ～ ' + (dispDate($('#fTo').val()) || '不限'));
        if ($.trim($('#fKw').val())) cond.push('關鍵字：' + $.trim($('#fKw').val()));

        var body = '';
        body += '<div class="p-title">' + esc(COMPANY || '') + '</div>';
        body += '<div class="p-sub">' + esc(title) + '</div>';
        body += '<div class="p-cond">' + esc(cond.length ? cond.join('　｜　') : '全部資料') + '　｜　共 ' + rows.length + ' 筆　｜　列印日期：' + esc(dispDate(new Date().toISOString().substring(0,10))) + '</div>';
        body += '<table class="p-tb"><thead><tr>' + cols.map(function(c){ return '<th>'+esc(c)+'</th>'; }).join('') + '</tr></thead><tbody>';
        rows.forEach(function(r){
            if (TAB === 'print') {
                var host = r.client_host ? r.client_host + (r.client_ip ? ' (' + r.client_ip + ')' : '') : (r.client_ip || '—');
                body += '<tr>'
                     + '<td>' + esc(dispDateTime(r.printed_at)) + '</td>'
                     + '<td>' + esc(r.source_label) + '</td>'
                     + '<td class="tl">' + esc(r.doc_name) + '</td>'
                     + '<td>' + esc(r.part_no || '—') + '</td>'
                     + '<td>' + esc(r.printed_by_name || '—') + '</td>'
                     + '<td>' + esc(host) + '</td>'
                     + '<td class="tl">' + esc(r.note || '') + '</td></tr>';
            } else {
                body += '<tr>'
                     + '<td class="tl">' + esc(r.doc_name) + '</td>'
                     + '<td>' + esc(r.module_label) + '</td>'
                     + '<td>' + esc(dispDate(r.doc_date || r.submitted_at)) + '</td>'
                     + '<td>' + esc(r.submitted_by_name || '—') + '</td>'
                     + '<td>' + esc(r.level_label) + '</td>'
                     + '<td>' + esc(r.approver_name || '—') + '</td>'
                     + '<td>' + (r.decided_at ? esc(dispDateTime(r.decided_at)) : '—') + '</td>'
                     + '<td>' + esc(r.result_label) + '</td>'
                     + '<td class="tl">' + esc(r.note || '') + '</td></tr>';
            }
        });
        body += '</tbody></table>';

        var css = 'body{font-family:"Microsoft JhengHei",sans-serif;color:#222;margin:0;}'
            + '.p-title{text-align:center;font-size:16pt;font-weight:bold;margin-bottom:2mm;}'
            + '.p-sub{text-align:center;font-size:13pt;margin-bottom:2mm;}'
            + '.p-cond{font-size:9pt;color:#444;margin-bottom:3mm;}'
            + 'table.p-tb{width:100%;border-collapse:collapse;font-size:9pt;}'
            + 'table.p-tb th,table.p-tb td{border:1px solid #999;padding:2px 4px;text-align:center;}'
            + 'table.p-tb thead th{background:#eee;}'
            + 'table.p-tb td.tl{text-align:left;word-break:break-all;}'
            + 'table.p-tb tr{break-inside:avoid;}'
            + 'table.p-tb thead{display:table-header-group;}'   // 跨頁時表頭自然重複，不自算分頁
            + '@page{size:A4 landscape;margin:12mm 10mm 18mm;}';
        var w = window.open('', '_blank');
        if (!w) { alert('列印視窗被瀏覽器攔截，請允許本頁彈出視窗'); return; }
        w.document.write('<html><head><meta charset="utf-8"><title>' + esc(title) + '</title><style>' + css + '</style></head><body>' + body
            + '<scr'+'ipt>window.onload=function(){'
            // 內容超過一頁才加頁碼（counter(pages) 由列印引擎在列印當下計算，不用 JS 自算分頁）
            + 'var onePage=(210-30)*96/25.4;'
            + 'if(document.body.scrollHeight>onePage*0.92){'
            + 'var st=document.createElement(\'style\');'
            + 'st.textContent="@page{ @bottom-left{ content:\'第 \' counter(page) \' 頁／共 \' counter(pages) \' 頁\'; font-size:9pt; color:#333; } }";'
            + 'document.head.appendChild(st);}'
            + 'setTimeout(function(){window.print();},200);};</scr'+'ipt></body></html>');
        w.document.close();
    }, 'json').fail(function(){
        $b.prop('disabled', false).html('<i class="fa fa-print"></i> 列印全部篩選結果');
        alert('取得資料失敗');
    });
}

// ── 使用說明的「涵蓋範圍」：一律即時掃描 ────────────────────────────────
var COV_LOADED = false;
function loadCoverage(){
    if (COV_LOADED) return;
    $.get(API, { action: 'coverage' }, function(res){
        if (!res || !res.ok) { $('#covBox').html('<span style="color:#DD5138;">掃描失敗</span>'); return; }
        COV_LOADED = true;
        var h = '';
        var p = res.print || {}, s = res.sign || {};
        h += '<div><span class="cov-yes">列印：已涵蓋（' + (p.covered || []).length + ' 支頁面）</span><ul>';
        (p.covered || []).forEach(function(x){
            h += '<li>' + esc(x.label) + ' <span class="cov-note">' + esc(x.page)
               + (x.via === 'legacy' ? '（該模組原本就有自己的列印紀錄）' : '') + '</span></li>';
        });
        h += '</ul></div>';
        h += '<div><span class="cov-no">列印：尚未涵蓋（' + (p.uncovered || []).length + ' 支頁面）</span>'
           + '<div class="cov-note">這些頁面有列印功能但還沒接上紀錄，印了不會留紀錄。</div><ul>';
        (p.uncovered || []).forEach(function(x){ h += '<li class="cov-note">' + esc(x.page) + '</li>'; });
        h += '</ul></div>';

        h += '<div><span class="cov-yes">簽核：已涵蓋（' + (s.covered || []).length + ' 種單據）</span><ul>';
        (s.covered || []).forEach(function(x){
            h += '<li>' + esc(x.label) + ' <span class="cov-note">（目前 ' + x.rows + ' 筆）</span></li>';
        });
        h += '</ul></div>';
        h += '<div><span class="cov-no">簽核：尚未涵蓋（' + (s.uncovered || []).length + ' 種）</span>'
           + '<div class="cov-note">這些模組把簽核存在自己的資料表，沒有寫進共用的簽核紀錄，所以查不到。</div><ul>';
        (s.uncovered || []).forEach(function(x){
            h += '<li class="cov-note">' + esc(x.note || x.table) + '（' + esc(x.table) + '，約 ' + x.rows + ' 筆）</li>';
        });
        h += '</ul></div>';
        $('#covBox').html(h);
    }, 'json');
}

// ── 角色說明：一律查目前實際的角色設定，不放寫死清單（鐵律4）────────────
function loadRoleHelp(){
    var h = '<ul style="padding-left:18px;line-height:1.9;">'
          + '<li><b>紀錄管理</b>（psl_admin）：查全部人的紀錄、列印匯出。</li>'
          + '<li><b>紀錄檢閱</b>（psl_view_all）：查全部人的紀錄（唯讀）。</li>'
          + '<li><b>未指派角色</b>：只看得到自己的紀錄。</li>'
          + '<li><b>管理者</b>：固定擁有全部權限。</li></ul>'
          + '<div style="margin-top:8px;color:#8a6d45;">目前你的身分：<b>' + esc($('#roleName').text()) + '</b></div>';
    $('#roleHelpBody').html(h);
}

// ── 事件 ────────────────────────────────────────────────────────────────
$('.ps-tab').on('click', function(){
    var t = $(this).data('tab');
    if (t === TAB) return;
    TAB = t; PAGE = 1;
    $('.ps-tab').removeClass('active'); $(this).addClass('active');
    fillSourceSel();
    loadList();
    refreshOtherCount();
});
// 即時搜尋：任何篩選一改就查，不必按查詢鈕（使用者要求）。
// 關鍵字要 debounce——每打一個字就發一次請求，慢的那次回來會覆蓋掉快的那次，畫面會跳成舊結果。
var _kwTimer = null;
function liveSearch(delay){
    clearTimeout(_kwTimer);
    _kwTimer = setTimeout(function(){ PAGE = 1; loadList(); refreshOtherCount(); }, delay || 0);
}
$('#fSource, #fUser').on('change', function(){ liveSearch(0); });
$('#fFrom, #fTo').on('change', function(){ liveSearch(0); });
$('#fKw').on('input', function(){ liveSearch(350); });
$('#btnReset').on('click', function(){
    resetRange(); $('#fSource').val(''); $('#fKw').val('');
    if (META.perms.canViewAll) $('#fUser').val('');
    liveSearch(0);
});
$('#fPer').on('change', function(){ PAGE = 1; syncTopBtn(); loadList(); });
$('#pgBtns').on('click', 'button', function(){
    var p = parseInt($(this).data('p'), 10);
    if (!p || p === PAGE) return;
    PAGE = p; loadList();
});
$('#btnPrintAll').on('click', printAll);
$('#btnPageHelp').on('click', function(){ openMask('helpUseMask'); loadCoverage(); });
$('#btnRoleHelp').on('click', function(){ loadRoleHelp(); openMask('roleHelpMask'); });
$('.ps-mask').on('click', function(e){ if (e.target === this) this.style.display='none'; });

// 回頂端鈕：每頁 10 筆時整頁看得完、不需要，改成「每頁超過 10 筆才出現」（使用者要求）
function syncTopBtn(){ $('#btnTop').toggle((parseInt($('#fPer').val(), 10) || 10) > 10); }
$('#btnTop').on('click', function(){ $('html,body').animate({ scrollTop: 0 }, 200); });

// ── 起始 ────────────────────────────────────────────────────────────────
resetRange();
$.get(API, { action: 'meta' }, function(res){
    if (!res || !res.ok) { $('#tBody').html('<tr><td colspan="9" style="padding:20px;color:#DD5138;">載入失敗</td></tr>'); return; }
    META = res;
    fillSourceSel();
    fillPeopleSel();
    if (!META.perms.canViewAll) $('#roleName').text('僅本人紀錄');
    syncTopBtn();
    loadList();
    refreshOtherCount();
}, 'json');
</script>
</body>
</html>
