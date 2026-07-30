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
        .cat-pill { display:inline-block; font-size:11px; background:#FDF3E0; color:#8A5A2B; border:1px solid #EADFC8;
            border-radius:10px; padding:1px 8px; margin:1px 2px; }
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
                <thead><tr>
                    <th>客戶</th><th>料號</th><th>文件名稱</th><th>外來文件類別</th>
                    <th>發行日期</th><th>發行單位</th><th>來源</th>
                </tr></thead>
                <tbody id="xdBody"><tr><td colspan="7" style="padding:20px;color:#8a6d45;">載入中…</td></tr></tbody>
            </table>
        </div>
        <div style="font-size:11px;color:#8a6d45;margin-top:4px;">
            發行日期＝附件上傳日期。「外來文件類別」顯示標籤設定的類別名稱（未設定則用標籤名稱）。
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

<!-- 角色說明 -->
<div class="xd-mask" id="helpMask"><div class="xd-modal">
    <div class="m-head">角色權限說明<span class="m-close" onclick="closeMask('helpMask')">✕</span></div>
    <div class="m-body" style="line-height:1.8;">
        <b>外來文件檢閱</b>：檢視清單、匯出 CSV、列印。<br>
        <b>外來文件管理</b>：檢閱＋綁定 AS 文件編號。<br>
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
var MODE = 'bound', PAGE = 1, TOTAL = 0, AS_DOC = null, AS_DOCS = [], ISSUE_UNIT = '';

function esc(s){ return String(s==null?'':s).replace(/[&<>"']/g,function(c){
    return {'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#39;'}[c]; }); }
function closeMask(id){ document.getElementById(id).style.display='none'; }
function openMask(id){ document.getElementById(id).style.display='block'; }

function filters(){
    return { mode: MODE, customer_id: $('#custSel').val()||'', year: $('#yearSel').val()||0 };
}

function loadOptions(){
    $.post(API, {action:'get_options'}, function(res){
        if (!res.success) return;
        (res.customers||[]).forEach(function(c){
            $('#custSel').append('<option value="'+esc(c.customer_id)+'">'+esc(c.customer)+'</option>');
        });
        (res.years||[]).forEach(function(y){
            $('#yearSel').append('<option value="'+y+'">'+y+' 年</option>');
        });
        AS_DOCS = res.as_docs||[];
        renderAsDoc(res.as_doc);
        ISSUE_UNIT = res.issue_unit||'';
        $('#issueUnit').text(ISSUE_UNIT || '（未設定業務單位）');
    }, 'json');
}

function renderAsDoc(doc){
    AS_DOC = doc || null;
    $('#asDocNo').text(AS_DOC ? (AS_DOC.doc_no + '（' + AS_DOC.doc_name + '）') : '尚未綁定');
}

function loadList(){
    var f = filters();
    f.action = 'get_list';
    f.page = PAGE;
    f.per_page = parseInt($('#perPageSel').val())||10;
    $('#xdBody').html('<tr><td colspan="7" style="padding:20px;color:#8a6d45;">載入中…</td></tr>');
    $.post(API, f, function(res){
        if (!res.success){ $('#xdBody').html('<tr><td colspan="7" style="padding:20px;color:#DD5138;">'+esc(res.message||'載入失敗')+'</td></tr>'); return; }
        TOTAL = res.total;
        ISSUE_UNIT = res.issue_unit||ISSUE_UNIT;
        $('#issueUnit').text(ISSUE_UNIT || '（未設定業務單位）');
        renderAsDoc(res.as_doc);
        var h = '';
        (res.rows||[]).forEach(function(r){
            var cats = (r.categories||[]).map(function(c){ return '<span class="cat-pill">'+esc(c)+'</span>'; }).join('');
            var src = r.source==='part' ? '<span class="src-pill src-part">料號附件</span>'
                    : '<span class="src-pill src-quote" title="報價單 '+esc(r.quote_no)+'">報價附件</span>';
            h += '<tr><td class="t-left">'+esc(r.customer_name)+'</td>'
               + '<td class="t-left">'+esc(r.part_no)+'</td>'
               + '<td class="t-left" style="max-width:320px;overflow:hidden;text-overflow:ellipsis;" title="'+esc(r.doc_name)+'">'+esc(r.doc_name)+'</td>'
               + '<td>'+(cats||'<span style="color:#c9bda9;">—</span>')+'</td>'
               + '<td>'+esc(r.doc_date)+'</td>'
               + '<td>'+esc(ISSUE_UNIT)+'</td>'
               + '<td>'+src+'</td></tr>';
        });
        $('#xdBody').html(h || '<tr><td colspan="7" style="padding:20px;color:#8a6d45;">無符合條件的外來文件（先到附件類別標籤設定勾選「列入外來文件清單」）</td></tr>');
        renderPager();
    }, 'json');
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

$('#modeBound').on('click', function(){ MODE='bound'; $(this).addClass('active'); $('#modeAll').removeClass('active'); PAGE=1; loadList(); });
$('#modeAll').on('click', function(){ MODE='all'; $(this).addClass('active'); $('#modeBound').removeClass('active'); PAGE=1; loadList(); });
$('#custSel, #yearSel').on('change', function(){ PAGE=1; loadList(); });
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
        var unit = res.issue_unit||'';
        var body = '<div class="p-title">外來文件清單</div>'
                 + '<div class="p-sub">'+esc(yearTxt)+'｜'+esc(custTxt)+'｜'+esc(modeTxt)+'｜共 '+res.total+' 筆'
                 + '｜列印日期：'+new Date().toISOString().substr(0,10)+'</div>';
        (res.groups||[]).forEach(function(g){
            body += '<div class="p-cust">客戶：'+esc(g.customer_name)+'</div>';
            body += '<table class="p-tb"><thead><tr><th style="width:16%;">料號</th><th>文件名稱</th>'
                  + '<th style="width:14%;">外來文件類別</th><th style="width:10%;">發行日期</th><th style="width:10%;">發行單位</th></tr></thead><tbody>';
            g.rows.forEach(function(r){
                body += '<tr><td>'+esc(r.part_no)+'</td><td class="tl">'+esc(r.doc_name)+'</td>'
                      + '<td>'+esc((r.categories||[]).join('、'))+'</td><td>'+esc(r.doc_date)+'</td><td>'+esc(unit)+'</td></tr>';
            });
            body += '</tbody></table>';
        });
        if (!(res.groups||[]).length) body += '<div style="padding:20px;color:#666;">無符合條件的外來文件</div>';
        var asTxt = res.as_doc ? esc(res.as_doc.doc_no) : '';
        if (asTxt) body += '<div class="as-foot">'+asTxt+'</div>';
        var css = 'body{font-family:"Microsoft JhengHei",sans-serif;margin:10mm 8mm 14mm;color:#222;}'
            + '.p-title{font-size:20px;font-weight:bold;text-align:center;margin-bottom:2px;}'
            + '.p-sub{font-size:11px;text-align:center;color:#555;margin-bottom:10px;}'
            + '.p-cust{font-size:14px;font-weight:bold;margin:10px 0 3px;border-left:4px solid #F0A24B;padding-left:6px;break-after:avoid;}'
            + 'table.p-tb{width:100%;border-collapse:collapse;font-size:11px;margin-bottom:6px;}'
            + 'table.p-tb th,table.p-tb td{border:1px solid #666;padding:2px 5px;text-align:center;}'
            + 'table.p-tb thead th{background:#f3ead6;}'
            + 'table.p-tb td.tl{text-align:left;word-break:break-all;}'
            + 'table.p-tb tr{break-inside:avoid;}'
            + '.as-foot{position:fixed;bottom:2mm;right:4mm;font-size:11px;color:#333;}'
            + '@page{margin:10mm 8mm 14mm;}';
        var w = window.open('', '_blank');
        w.document.write('<html><head><meta charset="utf-8"><title>外來文件清單</title><style>'+css+'</style></head><body>'+body
            +'<scr'+'ipt>window.onload=function(){setTimeout(function(){window.print();},200);};</scr'+'ipt></body></html>');
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
$('.xd-mask').on('click', function(e){ if (e.target === this) this.style.display='none'; });

if (canView){ loadOptions(); loadList(); }
</script>
</body>
</html>
