<?php
/**
 * 流程地圖（頁面導覽圖）
 * 以流程圖的方式呈現「接單→生產→出貨」等作業順序，每個方塊就是一個系統頁面，點了直接進入。
 * 權限判定與左側選單完全一致（見 src/common/flow_map_lib.php）：沒權限的方塊反灰且不可點，連網址後端都不給。
 * 編輯（拖曳方塊、連箭頭、增刪方塊）僅管理員；一般人只能看與點。
 */
session_start();
if (!isset($_SESSION['userName'])) {
    $_SESSION['lastpage'] = "../../views/admin/flow_map.php";
    header("Location:../../index.php");
    exit;
}
include_once '../../src/common/_config.php';
include_once '../../src/common/DBConnection.php';
include_once '../../src/common/flow_map_lib.php';

$db = (new DBConnection())->getPDO();
fmap_ensure_schema($db);
$st = $db->prepare("SELECT id FROM `user` WHERE user_uname=?");
$st->execute([$_SESSION['userName']]);
$uid = (int)$st->fetchColumn();
$isAdmin = fmap_is_admin($db, $uid);
if (empty($_SESSION['fmap_csrf'])) $_SESSION['fmap_csrf'] = bin2hex(random_bytes(16));
$csrf = $_SESSION['fmap_csrf'];
?>
<!DOCTYPE html>
<html lang="zh-Hant">
<head>
    <meta charset="utf-8">
    <meta http-equiv="X-UA-Compatible" content="IE=edge">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>流程地圖</title>
    <link href="../../resource/css/bootstrap.css" rel="stylesheet">
    <link href="../../resource/css/font-awesome.css" rel="stylesheet">
    <link href="../../resource/css/nprogress.css" rel="stylesheet">
    <link href="../../resource/css/custom.css" rel="stylesheet">
    <style>
        #sidebar-menu { visibility: hidden; }
        .right_col .page-title { margin:8px 0 6px; overflow:hidden; clear:both; display:flex; align-items:center; flex-wrap:wrap; gap:8px; }
        .right_col .page-title h3 { margin:0; color:#5b3a1e; }
        .page-help-btn { margin-left:auto; height:30px; font-size:13px; padding:0 12px; border:1px solid #d98a33; border-radius:15px; background:#F0A24B; color:#fff; cursor:pointer; }
        .page-help-btn:hover { background:#d98a33; }
        @media print { .page-help-btn, .fm-bar, .fm-edit-bar { display:none !important; } }
        .fm-bar, .fm-edit-bar { display:flex; flex-wrap:wrap; align-items:center; gap:6px; margin-bottom:8px; font-size:13px; color:#5b3a1e; }
        .fm-bar select, .fm-bar button, .fm-bar input, .fm-edit-bar select, .fm-edit-bar button, .fm-edit-bar input[type=text] {
            height:30px; font-size:13px; padding:0 8px; border:1px solid #D8BE93; border-radius:4px; background:#fff; color:#5b3a1e; }
        .fm-bar button, .fm-edit-bar button { cursor:pointer; }
        .fm-bar button:hover, .fm-edit-bar button:hover { background:#F7E0BD; }
        .fm-edit-bar { background:#FBF3E5; border:1px dashed #D8BE93; border-radius:6px; padding:6px 8px; display:none; }
        .fm-edit-bar.on { display:flex; }
        .fm-edit-bar button.active { background:#F0A24B; color:#fff; border-color:#d98a33; }
        .fm-edit-bar .sw { width:22px; height:22px; border-radius:4px; border:2px solid transparent; padding:0; }
        .fm-edit-bar .sw.sel { border-color:#5b3a1e; }
        .fm-hint { font-size:12px; color:#8a6d45; }
        .fm-wrap { overflow:auto; border:1px solid #E8D5B5; border-radius:6px; background:#fff; max-height:calc(100vh - 230px); min-height:320px; }
        .fm-canvas { position:relative; background-image:radial-gradient(#EADFC8 1px, transparent 1px); background-size:20px 20px; }
        .fm-canvas svg { position:absolute; left:0; top:0; pointer-events:none; }
        .fm-canvas svg .edge { pointer-events:none; }
        .fm-canvas.editing svg .edge-hit { pointer-events:stroke; cursor:pointer; }
        .fm-node { position:absolute; box-sizing:border-box; display:flex; align-items:center; justify-content:center; text-align:center;
            border-radius:8px; padding:4px 8px; font-size:14px; line-height:1.25; color:#5b3a1e; border:2px solid rgba(0,0,0,.12);
            box-shadow:0 1px 2px rgba(91,58,30,.18); user-select:none; word-break:break-all; }
        .fm-node.c0 { background:#F7E0BD; } .fm-node.c1 { background:#F0A24B; } .fm-node.c2 { background:#E9C79A; }
        .fm-node.c3 { background:#C98B4A; color:#fff; } .fm-node.c4 { background:#8A5A2B; color:#fff; }
        .fm-node.link { cursor:pointer; }
        .fm-node.link:hover { box-shadow:0 0 0 3px rgba(240,162,75,.55); }
        .fm-node.link .fa { margin-left:5px; font-size:11px; opacity:.7; }
        .fm-node.locked { background:#EFEAE0; color:#a8998a; border-style:dashed; cursor:not-allowed; }
        .fm-node.text { background:transparent; border:2px dashed #D8BE93; box-shadow:none; font-weight:bold; }
        .fm-node.sel { outline:3px solid #DD5138; outline-offset:2px; }
        .fm-node.src { outline:3px solid #F0A24B; outline-offset:2px; }
        .editing .fm-node { cursor:move; }
        .fm-resize { position:absolute; right:-2px; bottom:-2px; width:12px; height:12px; background:#5b3a1e; border-radius:2px; cursor:nwse-resize; display:none; }
        .editing .fm-node.sel .fm-resize { display:block; }
        .fm-empty { padding:40px; text-align:center; color:#8a6d45; }
        .fm-mask { position:fixed; inset:0; background:rgba(60,40,20,.45); display:none; z-index:10050; align-items:center; justify-content:center; }
        .fm-mask.on { display:flex; }
        .fm-modal { background:#fff; width:640px; max-width:96vw; max-height:90vh; overflow:auto; border-radius:8px; }
        .fm-modal .m-head { background:#F7E0BD; padding:10px 14px; font-weight:bold; color:#5b3a1e; display:flex; justify-content:space-between; }
        .fm-modal .m-body { padding:12px 16px; } .fm-modal .m-foot { padding:8px 14px; text-align:right; border-top:1px solid #EADFC8; }
        .fm-modal .m-foot button { height:30px; padding:0 14px; border:1px solid #D8BE93; border-radius:4px; background:#F0A24B; color:#fff; cursor:pointer; }
        .help-doc { font-size:13px; color:#5b3a1e; line-height:1.75; }
        .help-doc h4 { color:#8A5A2B; border-bottom:2px solid #F7E0BD; padding-bottom:3px; margin:14px 0 6px; font-size:15px; }
    </style>
</head>
<body class="nav-sm">
<div class="container body">
<div class="main_container">
    <?php include '../partPage/sideAndTopBarMenu.html' ?>
    <div class="right_col" role="main">
        <div class="page-title">
            <h3><i class="fa fa-sitemap"></i> 流程地圖</h3>
            <button class="page-help-btn" id="btnPageHelp"><i class="fa fa-question-circle"></i> 使用說明</button>
        </div>

        <div class="fm-bar">
            <label>地圖：<select id="selMap"></select></label>
            <label><input type="checkbox" id="chkHide" style="height:auto"> 隱藏沒有權限的頁面</label>
            <span class="fm-hint">點方塊直接進入該頁；灰色虛線方塊＝你沒有這個頁面的權限</span>
            <?php if ($isAdmin): ?>
            <button id="btnEdit" style="margin-left:auto"><i class="fa fa-pencil"></i> 編輯模式</button>
            <?php endif; ?>
        </div>

        <?php if ($isAdmin): ?>
        <div class="fm-edit-bar" id="editBar">
            <button id="btnNewMap"><i class="fa fa-plus"></i> 新地圖</button>
            <button id="btnRenMap">改名</button>
            <button id="btnDelMap" style="color:#DD5138">刪除地圖</button>
            <span style="border-left:1px solid #D8BE93;height:22px"></span>
            <select id="selPage" data-eg-filter="輸入頁面名稱篩選…" style="min-width:220px"></select>
            <button id="btnAddPage"><i class="fa fa-plus"></i> 加入頁面方塊</button>
            <button id="btnAddText"><i class="fa fa-font"></i> 加入文字方塊</button>
            <button id="btnConnect"><i class="fa fa-long-arrow-right"></i> 連箭頭</button>
            <span style="border-left:1px solid #D8BE93;height:22px"></span>
            <span id="selTools" style="display:none;align-items:center;gap:6px">
                <input type="text" id="txtLabel" maxlength="60" placeholder="方塊文字" style="width:150px">
                <button class="sw" data-c="0" style="background:#F7E0BD"></button><button class="sw" data-c="1" style="background:#F0A24B"></button>
                <button class="sw" data-c="2" style="background:#E9C79A"></button><button class="sw" data-c="3" style="background:#C98B4A"></button>
                <button class="sw" data-c="4" style="background:#8A5A2B"></button>
                <button id="btnDelSel" style="color:#DD5138"><i class="fa fa-trash"></i> 刪除選取</button>
            </span>
            <span id="edgeTools" style="display:none;align-items:center;gap:6px">
                <input type="text" id="txtEdge" maxlength="30" placeholder="箭頭說明（可空白）" style="width:150px">
                <button id="btnDelEdge" style="color:#DD5138"><i class="fa fa-trash"></i> 刪除箭頭</button>
            </span>
            <button id="btnSave" style="margin-left:auto;background:#F0A24B;color:#fff;border-color:#d98a33"><i class="fa fa-save"></i> 儲存</button>
            <span class="fm-hint" id="editHint" style="flex-basis:100%"></span>
        </div>
        <?php endif; ?>

        <div class="fm-wrap" id="fmWrap"><div class="fm-canvas" id="fmCanvas"></div></div>
    </div>
</div>
</div>

<div class="fm-mask" id="helpUseMask"><div class="fm-modal">
    <div class="m-head"><span><i class="fa fa-question-circle"></i> 流程地圖 使用說明</span><span style="cursor:pointer" onclick="$('#helpUseMask').removeClass('on')">✕</span></div>
    <div class="m-body help-doc">
        <h4>這是什麼</h4>
        <p>把作業順序畫成流程圖，每個方塊是一個系統頁面，<b>點方塊就直接進入該頁</b>。不知道下一步要去哪個頁面時，從這裡找最快。</p>
        <h4>權限</h4>
        <p>跟左側選單完全一樣：你有權限的頁面是彩色方塊可以點；沒權限的是灰色虛線方塊，點不進去（系統連網址都不會給）。勾「隱藏沒有權限的頁面」可把它們與相連的箭頭一起藏起來。</p>
        <h4>管理員怎麼編輯</h4>
        <ol>
            <li>按「編輯模式」。可「新地圖」或切換地圖。</li>
            <li>下拉選頁面 → 「加入頁面方塊」；也可加「文字方塊」當階段標題（例如「接單」「生產」「出貨」）。</li>
            <li>按住方塊拖曳移動；選取後可拖右下角小方塊調整大小、改文字與顏色。</li>
            <li>按「連箭頭」，依序點起點方塊、終點方塊即可；點箭頭可改說明或刪除。</li>
            <li>最後按「儲存」。沒存檔離開時會提醒。</li>
        </ol>
        <p>方塊上的頁名與網址即時取自選單設定，改頁名或搬網址不必回來修地圖。</p>
        <h4>權限角色</h4>
        <p>全體登入者可檢視；編輯限管理員。</p>
    </div>
    <div class="m-foot"><button onclick="$('#helpUseMask').removeClass('on')">我知道了</button></div>
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

(function(){
var API = '../../src/store/FlowMap_API.php';
var CSRF = <?= json_encode($csrf) ?>;
var IS_ADMIN = <?= $isAdmin ? 'true' : 'false' ?>;
var NS = 'http://www.w3.org/2000/svg';
var M = null;            // 目前地圖 {map, nodes, edges}
var editing = false, connecting = false, dirty = false, selNode = null, selEdge = null, connSrc = null, tmpId = -1;
var PAGES = null;

function esc(s){ return String(s == null ? '' : s).replace(/[&<>"]/g, function(c){ return {'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;'}[c]; }); }
function api(action, data, type){
    return $.ajax({ url: API + '?action=' + action, type: type || 'GET', data: data, dataType: 'json' })
        .fail(function(x){ var m = (x.responseJSON && x.responseJSON.error) || '連線失敗'; alert(m); });
}
function post(action, data){ data = data || {}; data.csrf = CSRF; return api(action, data, 'POST'); }
function nodeById(id){ for (var i = 0; i < M.nodes.length; i++) if (M.nodes[i].node_id === id) return M.nodes[i]; return null; }
function setDirty(v){ dirty = v; }
window.addEventListener('beforeunload', function(e){ if (dirty) { e.preventDefault(); e.returnValue = ''; } });

function loadList(selectId){
    api('list').done(function(r){
        var h = '';
        r.maps.forEach(function(m){ h += '<option value="' + m.map_id + '">' + esc(m.map_name) + '</option>'; });
        $('#selMap').html(h || '<option value="">（尚無地圖）</option>');
        if (!r.maps.length) { M = null; render(); return; }
        if (selectId) $('#selMap').val(selectId);
        loadMap($('#selMap').val());
    });
}
function loadMap(id){
    api('get', { map_id: id }).done(function(r){
        M = { map: r.map, nodes: r.nodes, edges: r.edges };
        selNode = selEdge = connSrc = null; setDirty(false);
        render();
    });
}

function isHidden(n){ return $('#chkHide').prop('checked') && !editing && n.page_id && !n.allowed; }

function render(){
    var $c = $('#fmCanvas').empty().toggleClass('editing', editing);
    if (!M || !M.nodes.length) {
        $c.css({ width: 'auto', height: 'auto' }).html('<div class="fm-empty">' + (M ? '這張地圖還沒有方塊' + (IS_ADMIN ? '，請按「編輯模式」加入頁面。' : '。') : '尚未建立任何地圖。' + (IS_ADMIN ? '請按「編輯模式」→「新地圖」。' : '請洽管理員。')) + '</div>');
        return;
    }
    var W = M.map.canvas_w, H = M.map.canvas_h;
    M.nodes.forEach(function(n){ W = Math.max(W, n.x + n.w + 60); H = Math.max(H, n.y + n.h + 60); });
    $c.css({ width: W, height: H });
    var svg = document.createElementNS(NS, 'svg');
    svg.setAttribute('width', W); svg.setAttribute('height', H);
    svg.innerHTML = '<defs><marker id="ar" viewBox="0 0 10 10" refX="9" refY="5" markerWidth="8" markerHeight="8" orient="auto-start-reverse"><path d="M0,0 L10,5 L0,10 z" fill="#8A5A2B"/></marker>' +
        '<marker id="arsel" viewBox="0 0 10 10" refX="9" refY="5" markerWidth="8" markerHeight="8" orient="auto-start-reverse"><path d="M0,0 L10,5 L0,10 z" fill="#DD5138"/></marker></defs>';
    $c[0].appendChild(svg);
    M.edges.forEach(function(e, i){
        var a = nodeById(e.from), b = nodeById(e.to);
        if (!a || !b || isHidden(a) || isHidden(b)) return;
        var p = edgePts(a, b), sel = (selEdge === i);
        var ln = document.createElementNS(NS, 'line');
        ln.setAttribute('class', 'edge'); ln.setAttribute('x1', p[0]); ln.setAttribute('y1', p[1]); ln.setAttribute('x2', p[2]); ln.setAttribute('y2', p[3]);
        ln.setAttribute('stroke', sel ? '#DD5138' : '#8A5A2B'); ln.setAttribute('stroke-width', 2.5);
        ln.setAttribute('marker-end', 'url(#' + (sel ? 'arsel' : 'ar') + ')');
        svg.appendChild(ln);
        if (editing) {
            var hit = ln.cloneNode(); hit.setAttribute('class', 'edge-hit'); hit.setAttribute('stroke', 'transparent'); hit.setAttribute('stroke-width', 14); hit.removeAttribute('marker-end');
            hit.addEventListener('mousedown', function(ev){ ev.stopPropagation(); selectEdge(i); });
            svg.appendChild(hit);
        }
        if (e.label) {
            var t = document.createElementNS(NS, 'text');
            t.setAttribute('x', (p[0] + p[2]) / 2); t.setAttribute('y', (p[1] + p[3]) / 2 - 5); t.setAttribute('text-anchor', 'middle');
            t.setAttribute('font-size', 12); t.setAttribute('fill', '#5b3a1e'); t.setAttribute('paint-order', 'stroke'); t.setAttribute('stroke', '#fff'); t.setAttribute('stroke-width', 4);
            t.textContent = e.label; svg.appendChild(t);
        }
    });
    M.nodes.forEach(function(n){
        if (isHidden(n)) return;
        var isPage = !!n.page_id, link = !editing && isPage && n.allowed && n.page_url;
        var cls = 'fm-node ' + (isPage ? (n.allowed || editing ? 'c' + n.color : 'locked') : 'text c' + n.color);
        if (!isPage) cls = 'fm-node text';
        if (link) cls += ' link';
        if (editing && isPage && !n.allowed) cls += ' locked';
        if (selNode === n.node_id) cls += ' sel';
        if (connSrc === n.node_id) cls += ' src';
        var $n = $('<div class="' + cls + '" data-id="' + n.node_id + '"></div>').css({ left: n.x, top: n.y, width: n.w, height: n.h });
        $n.html('<span>' + esc(n.text) + '</span>' + (link ? '<i class="fa fa-external-link"></i>' : '') + (!n.allowed && isPage ? '<i class="fa fa-lock"></i>' : '') + '<i class="fm-resize"></i>');
        if (!isPage) $n.addClass('c' + n.color);
        $c.append($n);
    });
    refreshTools();
}
// 線段兩端裁到方塊邊框上
function edgePts(a, b){
    var ac = [a.x + a.w / 2, a.y + a.h / 2], bc = [b.x + b.w / 2, b.y + b.h / 2];
    function clip(n, c, o){
        var dx = o[0] - c[0], dy = o[1] - c[1];
        if (!dx && !dy) return c;
        var sx = dx ? (n.w / 2 + 2) / Math.abs(dx) : Infinity, sy = dy ? (n.h / 2 + 2) / Math.abs(dy) : Infinity, s = Math.min(sx, sy);
        return [c[0] + dx * s, c[1] + dy * s];
    }
    var p1 = clip(a, ac, bc), p2 = clip(b, bc, ac);
    return [p1[0], p1[1], p2[0], p2[1]];
}

/* ===== 檢視模式：點擊進頁 ===== */
$('#fmCanvas').on('click', '.fm-node', function(){
    if (editing) return;
    var n = nodeById(parseInt($(this).data('id'), 10));
    if (n && n.page_id && n.allowed && n.page_url) window.location.href = n.page_url;
});
$('#selMap').on('change', function(){
    if (dirty && !confirm('目前的修改還沒儲存，確定要切換地圖嗎？')) { $(this).val(M.map.map_id); return; }
    loadMap($(this).val());
});
$('#chkHide').on('change', render);
$('#btnPageHelp').on('click', function(){ $('#helpUseMask').addClass('on'); });

if (!IS_ADMIN) { loadList(); return; }

/* ===== 編輯模式（管理員） ===== */
function hint(t){ $('#editHint').text(t || ''); }
function refreshTools(){
    var n = selNode != null ? nodeById(selNode) : null;
    $('#selTools').css('display', n ? 'flex' : 'none');
    $('#edgeTools').css('display', selEdge != null ? 'flex' : 'none');
    if (n) {
        $('#txtLabel').val(n.page_id ? (n.label || '') : n.text).attr('placeholder', n.page_id ? '自訂文字（空白＝用頁名）' : '方塊文字');
        $('.sw').removeClass('sel').filter('[data-c=' + n.color + ']').addClass('sel');
    }
    if (selEdge != null && M.edges[selEdge]) $('#txtEdge').val(M.edges[selEdge].label || '');
    $('#btnConnect').toggleClass('active', connecting);
}
function selectNode(id){ selNode = id; selEdge = null; render(); }
function selectEdge(i){ selEdge = i; selNode = null; render(); }

$('#btnEdit').on('click', function(){
    editing = !editing; connecting = false; connSrc = null; selNode = selEdge = null;
    $('#editBar').toggleClass('on', editing);
    $(this).toggleClass('active', editing).html(editing ? '<i class="fa fa-eye"></i> 回到檢視模式' : '<i class="fa fa-pencil"></i> 編輯模式');
    hint(editing ? '拖曳方塊移動；選取後可拖右下角調大小。完成後記得按「儲存」。' : '');
    if (editing && !PAGES) api('pages').done(function(r){
        PAGES = r.pages; var h = '', g = null;
        r.pages.forEach(function(p){
            if (p.group_name !== g) { if (g !== null) h += '</optgroup>'; h += '<optgroup label="' + esc(p.group_name || '（未分群組）') + '">'; g = p.group_name; }
            h += '<option value="' + p.page_id + '">' + esc(p.page_name) + '</option>';
        });
        $('#selPage').html(h + '</optgroup>');
    });
    render();
});

function addNode(o){
    var y = 20, x = 20;
    M.nodes.forEach(function(n){ y = Math.max(y, n.y); });
    var n = $.extend({ node_id: tmpId--, page_id: null, label: '', text: '', x: x, y: y + (M.nodes.length ? 70 : 0), w: 150, h: 46, color: 0, allowed: true, page_url: null }, o);
    M.nodes.push(n); setDirty(true); selectNode(n.node_id);
}
$('#btnAddPage').on('click', function(){
    if (!M) return alert('請先建立地圖');
    var pid = parseInt($('#selPage').val(), 10); if (!pid) return;
    var p = PAGES.filter(function(x){ return +x.page_id === pid; })[0];
    addNode({ page_id: pid, text: p.page_name });
});
$('#btnAddText').on('click', function(){ if (!M) return alert('請先建立地圖'); addNode({ text: '階段標題', label: '階段標題', w: 120, h: 34 }); });
$('#btnConnect').on('click', function(){ connecting = !connecting; connSrc = null; hint(connecting ? '依序點「起點方塊」再點「終點方塊」；再按一次此鈕結束連線模式。' : ''); render(); });

$('.sw').on('click', function(){ var n = nodeById(selNode); if (!n) return; n.color = +$(this).data('c'); setDirty(true); render(); });
$('#txtLabel').on('input', function(){
    var n = nodeById(selNode); if (!n) return; var v = $(this).val();
    n.label = v;
    if (!n.page_id) n.text = v;
    else n.text = v !== '' ? v : ((PAGES || []).filter(function(x){ return +x.page_id === n.page_id; })[0] || {}).page_name || n.text;
    setDirty(true);
    $('.fm-node[data-id="' + n.node_id + '"] span').text(n.text);
});
$('#txtEdge').on('input', function(){ if (selEdge != null) { M.edges[selEdge].label = $(this).val(); setDirty(true); } });
$('#txtEdge').on('change', render);
$('#btnDelSel').on('click', function(){
    if (selNode == null || !confirm('刪除這個方塊與相連的箭頭？')) return;
    M.nodes = M.nodes.filter(function(n){ return n.node_id !== selNode; });
    M.edges = M.edges.filter(function(e){ return e.from !== selNode && e.to !== selNode; });
    selNode = null; setDirty(true); render();
});
$('#btnDelEdge').on('click', function(){ if (selEdge == null) return; M.edges.splice(selEdge, 1); selEdge = null; setDirty(true); render(); });

// 拖曳／縮放／連線
var drag = null;
$('#fmCanvas').on('mousedown', function(ev){
    if (!editing) return;
    var $n = $(ev.target).closest('.fm-node');
    if (!$n.length) { if (!$(ev.target).closest('svg').length || ev.target.tagName === 'svg') { selNode = selEdge = null; render(); } return; }
    var id = parseInt($n.data('id'), 10), n = nodeById(id);
    if (connecting) {
        if (connSrc == null) { connSrc = id; render(); }
        else if (connSrc !== id) {
            if (!M.edges.some(function(e){ return e.from === connSrc && e.to === id; })) { M.edges.push({ from: connSrc, to: id, label: '' }); setDirty(true); }
            connSrc = null; render();
        }
        return;
    }
    if (selNode !== id) { selNode = id; selEdge = null; render(); $n = $('.fm-node[data-id="' + id + '"]'); }
    drag = { id: id, mode: $(ev.target).hasClass('fm-resize') ? 'size' : 'move', sx: ev.pageX, sy: ev.pageY, ox: n.x, oy: n.y, ow: n.w, oh: n.h, moved: false };
    ev.preventDefault();
});
$(document).on('mousemove', function(ev){
    if (!drag) return;
    var n = nodeById(drag.id), dx = ev.pageX - drag.sx, dy = ev.pageY - drag.sy;
    if (!drag.moved && Math.abs(dx) + Math.abs(dy) < 3) return;
    drag.moved = true;
    function sn(v){ return Math.round(v / 10) * 10; }
    if (drag.mode === 'move') { n.x = Math.max(0, sn(drag.ox + dx)); n.y = Math.max(0, sn(drag.oy + dy)); }
    else { n.w = Math.max(80, Math.min(400, sn(drag.ow + dx))); n.h = Math.max(30, Math.min(200, sn(drag.oh + dy))); }
    setDirty(true); render();
});
$(document).on('mouseup', function(){ drag = null; });

$('#btnSave').on('click', function(){
    if (!M) return;
    var W = 1400, H = 700;
    M.nodes.forEach(function(n){ W = Math.max(W, n.x + n.w + 60); H = Math.max(H, n.y + n.h + 60); });
    post('save', {
        map_id: M.map.map_id, canvas_w: W, canvas_h: H,
        nodes: JSON.stringify(M.nodes.map(function(n){ return { id: n.node_id, page_id: n.page_id, label: n.label, x: n.x, y: n.y, w: n.w, h: n.h, color: n.color }; })),
        edges: JSON.stringify(M.edges.map(function(e){ return { from: e.from, to: e.to, label: e.label }; }))
    }).done(function(){ setDirty(false); hint('已儲存'); loadMap(M.map.map_id); });
});
$('#btnNewMap').on('click', function(){
    var name = prompt('新地圖名稱（例如：接單到出貨）'); if (!name) return;
    post('map_create', { name: name }).done(function(r){ loadList(r.map_id); });
});
$('#btnRenMap').on('click', function(){
    if (!M) return; var name = prompt('地圖名稱', M.map.map_name); if (!name) return;
    post('map_rename', { map_id: M.map.map_id, name: name }).done(function(){ loadList(M.map.map_id); });
});
$('#btnDelMap').on('click', function(){
    if (!M || !confirm('確定刪除地圖「' + M.map.map_name + '」？方塊與箭頭會一併刪除，無法復原。')) return;
    post('map_delete', { map_id: M.map.map_id }).done(function(){ setDirty(false); loadList(); });
});
$(document).on('keydown', function(ev){
    if (!editing || $(ev.target).is('input,select,textarea')) return;
    if (ev.key === 'Delete') { if (selNode != null) $('#btnDelSel').click(); else if (selEdge != null) $('#btnDelEdge').click(); }
});

loadList();
})();
</script>
</body>
</html>
