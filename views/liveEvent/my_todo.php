<?php
/**
 * my_todo.php — 我的待辦（接收端）
 * 把「通知給我、還沒處理的事」整頁列出，像 Email 收件匣一樣可勾選多筆，一次「標示已閱」或「回簽」。
 *
 * 與 createEvent.php 的分工：createEvent 是發布／管理端（建公告、補簽、列印）；本頁只給收通知的人處理自己的事。
 * 資料來源與鈴鐺完全相同（側欄計算出的 $event）；已閱／回簽的寫入沿用既有端點
 * （_markEventRead.php／_eventRespond.php），不另寫一套，所以規則（期限、本人表態）自然一致。
 *
 * 批次的界線（刻意保守）：
 *   - 只有「一般公告」（沒有 ref_type）或「結果／資訊類」（ref_type 以 _RESULT／_INFO 結尾）可批次。
 *   - 簽核、回覆+回簽、共用帳號代收（要輸入本人密碼）一律只能一筆一筆「開啟處理」，
 *     因為那些要看過內容才能做決定，批次按掉不符合稽核習慣。
 */
session_start();
require_once __DIR__ . '/../../src/common/_config.php';
require_once __DIR__ . '/../../src/common/DBConnection.php';
require_once __DIR__ . '/../../src/common/date_fmt_lib.php';

if (!isset($_SESSION['userName'])) {
    $_SESSION['lastpage'] = '../../views/liveEvent/my_todo.php';
    header('Location: ../../index.php');
    exit;
}
?>
<!DOCTYPE html>
<html lang="zh-Hant">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>我的待辦</title>
    <link href="../../resource/css/bootstrap.css" rel="stylesheet">
    <link href="../../resource/css/font-awesome.css" rel="stylesheet">
    <link href="../../resource/css/nprogress.css" rel="stylesheet">
    <link href="../../resource/css/custom.css" rel="stylesheet">
    <style>
        #sidebar-menu { visibility: hidden; }
        .right_col { background:#F4F7FC !important; }
        .right_col > .td-wrap { clear: both; }
        .page-title-row { display:flex; align-items:center; gap:10px; margin:6px 0 14px; }
        .page-title-row h3 { margin:0; color:#2A3F54; }
        .page-help-btn { margin-left:auto; border:1px solid #d9a35b; background:#fff7ea; color:#8a5a14; border-radius:16px; padding:5px 14px; font-size:13px; cursor:pointer; }
        .td-tabs { display:flex; align-items:center; gap:8px; flex-wrap:wrap; margin-bottom:10px; }
        .td-tabs .tab { border:1px solid #e0c9a3; background:#fff; color:#6b4a1a; border-radius:8px 8px 0 0; padding:7px 18px; font-weight:700; }
        .td-tabs .tab.on { background:#F0A24B; border-color:#F0A24B; color:#fff; }
        .td-tabs .src { margin-left:auto; display:flex; align-items:center; gap:6px; }
        .td-tabs .src select { min-width:200px; }
        .tg-done { background:#e6f2e6; color:#2f6b2f; }
        .tg-undone { background:#fde3dd; color:#a8341f; }
        .td-more { display:block; width:100%; border:1px solid #e0c9a3; background:#fff; color:#6b4a1a; border-radius:8px; padding:10px; font-weight:600; margin-top:10px; }
        .td-bar { position:sticky; top:0; z-index:5; display:flex; flex-wrap:wrap; align-items:center; gap:8px; background:#fff; border:1px solid #e6ecf1; border-radius:10px; padding:10px 12px; margin-bottom:10px; }
        .td-bar label.td-all { margin:0; display:flex; align-items:center; gap:6px; font-weight:600; cursor:pointer; }
        .td-filter { display:flex; gap:6px; flex-wrap:wrap; }
        .td-filter button { border:1px solid #e0c9a3; background:#fff; color:#6b4a1a; border-radius:16px; padding:4px 12px; font-size:13px; }
        .td-filter button.on { background:#F0A24B; border-color:#F0A24B; color:#fff; }
        .td-act { margin-left:auto; display:flex; gap:8px; flex-wrap:wrap; }
        .td-btn { border:none; border-radius:8px; padding:7px 16px; font-weight:700; color:#fff; }
        .td-btn:disabled { opacity:.45; cursor:not-allowed; }
        .td-btn-read { background:#c77c1a; }
        .td-btn-sign { background:#DD5138; }
        .td-list { background:#fff; border:1px solid #e6ecf1; border-radius:10px; overflow:hidden; }
        .td-row { display:flex; align-items:flex-start; gap:12px; padding:12px 14px; border-bottom:1px solid #f0e6d6; }
        .td-row:last-child { border-bottom:none; }
        .td-row:hover { background:#fffaf2; }
        .td-row.sel { background:#fff3df; }
        .td-chk { width:22px; flex:0 0 22px; padding-top:2px; }
        .td-chk input { width:17px; height:17px; cursor:pointer; }
        .td-main { flex:1; min-width:0; }
        .td-title { font-weight:700; color:#2A3F54; word-break:break-word; }
        .td-prev { color:#8a7a66; font-size:13px; margin-top:2px; overflow:hidden; display:-webkit-box; -webkit-line-clamp:2; line-clamp:2; -webkit-box-orient:vertical; }
        .td-meta { color:#a39580; font-size:12px; margin-top:4px; }
        .td-tag { display:inline-block; font-size:11px; font-weight:700; padding:1px 9px; border-radius:12px; margin-right:6px; line-height:18px; }
        .tg-read { background:#f7efe2; color:#8a6a3d; }
        .tg-sign { background:#fdecd3; color:#a8620f; }
        .tg-open { background:#fde3dd; color:#a8341f; }
        .td-side { flex:0 0 auto; }
        .td-open { border:1px solid #d9a35b; background:#fff; color:#8a5a14; border-radius:8px; padding:5px 14px; font-size:13px; font-weight:600; white-space:nowrap; }
        .td-empty { text-align:center; color:#a39580; padding:60px 20px; }
        .td-empty .fa { font-size:38px; display:block; margin-bottom:10px; color:#e0c9a3; }
        .td-toast { position:fixed; left:50%; bottom:30px; transform:translateX(-50%); background:rgba(60,40,10,.9); color:#fff; padding:11px 22px; border-radius:24px; z-index:11000; display:none; max-width:90vw; }
        .help-doc h4 { color:#8a5a14; margin-top:14px; }
        .help-doc li { margin-bottom:4px; }
        @media print { .page-help-btn, .td-bar, .td-side { display:none !important; } }
    </style>
</head>
<body class="nav-sm">
<div class="container body">
  <div class="main_container">
    <?php include '../partPage/sideAndTopBarMenu.html'; ?>

    <div class="right_col" role="main">
      <div class="td-wrap">
        <div class="page-title-row">
          <h3><i class="fa fa-inbox"></i> 我的待辦</h3>
          <button type="button" class="page-help-btn" id="btnPageHelp"><i class="fa fa-question-circle"></i> 使用說明</button>
        </div>
<?php
// ---- 把鈴鐺的未處理清單分類 ----
$today = date('Y-m-d');
$items = [];
foreach (($event ?? []) as $ev) {
    $rt   = (string)($ev['ref_type'] ?? '');
    $need = (int)($ev['need_mode'] ?? 1);
    $forUid = (int)($ev['for_uid'] ?? 0);
    $expired = !empty($ev['reply_deadline']) && $ev['reply_deadline'] < $today;
    // 期限已過的回簽，系統只要求「確認已閱」（與鈴鐺查詢一致）
    if ($expired && $need >= 2) $need = 1;

    $plain = ($rt === '') || preg_match('/_(RESULT|INFO)$/', $rt);
    $kind = 'open';                     // open=要開啟處理 / read=可批次已閱 / sign=可批次回簽
    if ($plain && !$forUid) {
        if ($need === 1) $kind = 'read';
        elseif ($need === 2) $kind = 'sign';
    }
    $items[] = ['ev' => $ev, 'kind' => $kind, 'need' => $need];
}
$cnt = ['read' => 0, 'sign' => 0, 'open' => 0];
foreach ($items as $it) $cnt[$it['kind']]++;
?>
        <div class="td-tabs" id="tdTabs">
          <button type="button" class="tab on" data-t="todo">待處理 <span id="tTodo"><?= count($items) ?></span></button>
          <button type="button" class="tab" data-t="done">已處理</button>
          <button type="button" class="tab" data-t="all">全部收到的通知</button>
          <div class="src"><label for="tdSrc" style="margin:0;">來源</label>
            <select id="tdSrc" class="form-control input-sm" data-eg-filter="輸入來源名稱篩選…"><option value="">全部來源</option></select></div>
        </div>

        <div class="td-bar" id="tdBar">
          <label class="td-all"><input type="checkbox" id="tdAll"> 全選</label>
          <div class="td-filter" id="tdFilter">
            <button type="button" class="on" data-f="all">全部 <span id="cAll"><?= count($items) ?></span></button>
            <button type="button" data-f="read">可批次已閱 <span id="cRead"><?= $cnt['read'] ?></span></button>
            <button type="button" data-f="sign">可批次回簽 <span id="cSign"><?= $cnt['sign'] ?></span></button>
            <button type="button" data-f="open">需開啟處理 <span id="cOpen"><?= $cnt['open'] ?></span></button>
          </div>
          <div class="td-act">
            <button type="button" class="td-btn td-btn-read" id="btnRead" disabled><i class="fa fa-check"></i> 標示已閱 (<span id="nRead">0</span>)</button>
            <button type="button" class="td-btn td-btn-sign" id="btnSign" disabled><i class="fa fa-pencil-square-o"></i> 回簽 (<span id="nSign">0</span>)</button>
          </div>
        </div>

        <div class="td-list" id="tdList">
<?php foreach ($items as $it):
    $ev = $it['ev']; $k = $it['kind'];
    $prev = mb_strimwidth(preg_replace('/\s+/u', ' ', (string)$ev['content']), 0, 160, '…', 'UTF-8');
    $label = $k === 'read' ? '已閱' : ($k === 'sign' ? '需回簽' : ($it['need'] === 3 ? '需回覆' : ($it['need'] === 2 ? '需回簽' : '請開啟處理')));
    $cls = $k === 'read' ? 'tg-read' : ($k === 'sign' ? 'tg-sign' : 'tg-open');
?>
          <div class="td-row" id="td_<?= (int)$ev['id'] ?>" data-id="<?= (int)$ev['id'] ?>" data-kind="<?= $k ?>" data-source="<?= htmlspecialchars((string)($ev['source'] ?? ''), ENT_QUOTES) ?>">
            <div class="td-chk"><?php if ($k !== 'open'): ?><input type="checkbox" class="td-ck"><?php endif; ?></div>
            <div class="td-main">
              <div class="td-title"><span class="td-tag <?= $cls ?>"><?= $label ?></span><?php if (!empty($ev['for_uid'])): ?><span class="td-tag tg-open">給 <?= htmlspecialchars((string)($ev['for_name'] ?: ('員工#' . (int)$ev['for_uid'])), ENT_QUOTES) ?></span><?php endif; ?><?= htmlspecialchars((string)$ev['title'], ENT_QUOTES) ?></div>
              <div class="td-prev"><?= htmlspecialchars($prev, ENT_QUOTES) ?></div>
              <div class="td-meta"><i class="fa fa-clock-o"></i> <?= htmlspecialchars(eg_fmt_date($ev['eventdate']), ENT_QUOTES) ?><?php if (!empty($ev['source'])): ?>　來源：<?= htmlspecialchars((string)$ev['source'], ENT_QUOTES) ?><?php endif; ?><?php if (!empty($ev['reply_deadline'])): ?>　期限：<?= htmlspecialchars(eg_fmt_date($ev['reply_deadline']), ENT_QUOTES) ?><?php endif; ?></div>
            </div>
            <div class="td-side"><button type="button" class="td-open"><?= $k === 'open' ? '開啟處理' : '查看' ?></button></div>
          </div>
<?php endforeach; ?>
          <div class="td-empty" id="tdEmpty"<?= $items ? ' style="display:none;"' : '' ?>><i class="fa fa-check-circle"></i> 目前沒有待處理的事</div>
        </div>
        <div class="td-list" id="hList" style="display:none;"></div>
        <button type="button" class="td-more" id="hMore" style="display:none;">載入更多</button>
      </div>
    </div>
  </div>
</div>

<div class="td-toast" id="tdToast"></div>

<!-- 使用說明 -->
<div class="modal fade" id="helpUseMask" tabindex="-1" role="dialog">
  <div class="modal-dialog" style="width:720px;max-width:96vw;" role="document">
    <div class="modal-content">
      <div class="modal-header"><button type="button" class="close" data-dismiss="modal">&times;</button><h4 class="modal-title">我的待辦：使用說明</h4></div>
      <div class="modal-body help-doc">
        <h4>這頁是做什麼的</h4>
        <ul><li>把「通知給我、還沒處理的事」整頁列出，內容與右上角鈴鐺相同，但可以一次勾選多筆處理，不必再一則一則點。</li></ul>
        <h4>怎麼用</h4>
        <ol>
          <li>勾選要處理的列（或按「全選」）。上方篩選鈕可只看某一類。</li>
          <li>按「標示已閱」或「回簽」，系統會逐筆處理並顯示成功筆數；已處理的會從清單與鈴鐺消失。</li>
          <li>點某一列右側的按鈕可以開啟完整內容；需要審核、回覆或附件的事，請在開啟後處理。</li>
        </ol>
        <h4>三個分頁與來源篩選</h4>
        <ul>
          <li><b>待處理</b>：還沒做完的事（可批次）。<b>已處理</b>：我已經已閱／回簽／回覆過的。<b>全部收到的通知</b>：所有通知給我的，不管處理與否。</li>
          <li>右上「來源」可只看某一類通知（例如只看「訂單變更」）；已處理與全部分頁一次載入 20 則，按「載入更多」看更多。</li>
          <li>已處理與全部分頁的「查看」會另開頁面顯示完整內容與附件。</li>
        </ul>
        <h4>哪些可以批次？</h4>
        <ul>
          <li><b>可批次</b>：一般公告的「已閱」「回簽」，以及結果、資訊類通知。</li>
          <li><b>需開啟處理</b>：簽核（核准／退回）、需要文字回覆的通知、共用帳號代收（要輸入本人密碼）。這些要看過內容才能決定，所以沒有勾選框。</li>
          <li>已超過回簽期限的通知，只需要「已閱」。</li>
        </ul>
        <h4>相關入口與權限</h4>
        <ul><li>所有登入者都能使用，只會看到自己的待辦。發布、補簽、列印請到「公告／通知」管理頁（需對應角色）。</li></ul>
      </div>
    </div>
  </div>
</div>

<script src="../../resource/js/jquery.min.js"></script>
<script src="../../resource/js/bootstrap.min.js"></script>
<script src="../../resource/js/fastclick.js"></script>
<script src="../../resource/js/nprogress.js"></script>
<script src="../../resource/js/custom.min.js"></script>
<script src="../../resource/js/eg_input_rules.js?v=<?= @filemtime(__DIR__ . '/../../resource/js/eg_input_rules.js') ?>"></script>
<script>
$(function () {
    $('#sidebar-menu').css('visibility', 'visible');
    $('#btnPageHelp').on('click', function () { $('#helpUseMask').modal('show'); });

    var filter = 'all', busy = false, tab = 'todo', src = '';
    function toast(m) { var $t = $('#tdToast').text(m).fadeIn(120); clearTimeout($t.data('h')); $t.data('h', setTimeout(function () { $t.fadeOut(300); }, 3200)); }
    function srcOk($r) { return !src || String($r.attr('data-source') || '') === src; }
    function visibleRows() { return $('#tdList .td-row').filter(function () { return (filter === 'all' || $(this).data('kind') === filter) && srcOk($(this)); }); }
    function applyFilter() {
        $('.td-row').each(function () {
            var show = (filter === 'all' || $(this).data('kind') === filter) && srcOk($(this));
            $(this).toggle(show);
        });
        $('#tdEmpty').toggle($('#tdList .td-row:visible').length === 0);
        $('#tTodo').text($('#tdList .td-row').length);
        refresh();
    }
    function checkedRows(kind) { return $('#tdList .td-row').filter(function () { return $(this).data('kind') === kind && $(this).find('.td-ck').prop('checked'); }); }
    function refresh() {
        var r = checkedRows('read').length, s = checkedRows('sign').length;
        $('#nRead').text(r); $('#nSign').text(s);
        $('#btnRead').prop('disabled', busy || r === 0); $('#btnSign').prop('disabled', busy || s === 0);
        $('#tdList .td-row').each(function () { $(this).toggleClass('sel', $(this).find('.td-ck').prop('checked')); });
        var c = { all: 0, read: 0, sign: 0, open: 0 };
        $('#tdList .td-row').each(function () { if (!srcOk($(this))) return; c.all++; c[$(this).data('kind')]++; });
        $('#cAll').text(c.all); $('#cRead').text(c.read); $('#cSign').text(c.sign); $('#cOpen').text(c.open);
        var $vis = visibleRows().find('.td-ck:visible');
        $('#tdAll').prop('checked', $vis.length > 0 && $vis.filter(':checked').length === $vis.length);
    }

    // 鈴鐺同步：移除該則並重算紅色數字
    function dropFromBell(id) {
        $('#notice_item_' + id).remove();
        var remain = $('#menu1 li[id^="notice_item_"]').length;
        if (remain > 0) { $('#notice_badge').text(remain).show(); $('#notice_head_count').text(remain + ' 則未讀').show(); }
        else { $('#notice_badge').hide(); $('#notice_head_count').hide(); $('#notice_empty').show(); }
        $('a[href*="my_todo.php"] .td-menu-badge').text(remain).toggle(remain > 0);
    }
    function dropRow(id) { $('#td_' + id).remove(); dropFromBell(id); applyFilter(); }

    $('#tdFilter').on('click', 'button', function () {
        filter = $(this).data('f');
        $('#tdFilter button').removeClass('on'); $(this).addClass('on');
        applyFilter();
    });
    $('#tdAll').on('change', function () {
        var on = this.checked;
        visibleRows().find('.td-ck').prop('checked', on);
        refresh();
    });
    $('#tdList').on('change', '.td-ck', refresh);
    // 點列（非按鈕／勾選框）＝切換勾選，像收件匣
    $('#tdList').on('click', '.td-row', function (e) {
        if ($(e.target).closest('button, input, a').length) return;
        var $ck = $(this).find('.td-ck');
        if ($ck.length) { $ck.prop('checked', !$ck.prop('checked')); refresh(); }
    });
    // 開啟：沿用鈴鐺的點擊處理（含各種 ref_type 導頁、簽核跳窗），不在這裡重寫路由
    $('#tdList').on('click', '.td-open', function () {
        var id = $(this).closest('.td-row').data('id');
        var $a = $('#notice_item_' + id + ' .notice-open');
        if ($a.length) $a.trigger('click'); else toast('這則通知已不在未處理清單，請重新整理。');
    });
    // 鈴鐺跳窗處理完會把自己的列移除 → 這邊跟著移除
    var bell = document.getElementById('menu1');
    if (bell && window.MutationObserver) {
        new MutationObserver(function () {
            $('#tdList .td-row').each(function () {
                var id = $(this).data('id');
                if (!$('#notice_item_' + id).length) { $(this).remove(); }
            });
            applyFilter();
        }).observe(bell, { childList: true, subtree: true });
    }

    // 批次：逐筆呼叫既有端點，統計成功／失敗
    function runBatch(kind) {
        if (busy) return;
        var $rows = checkedRows(kind);
        if (!$rows.length) return;
        var label = kind === 'read' ? '標示已閱' : '回簽';
        if (kind === 'sign' && !confirm('確定對勾選的 ' + $rows.length + ' 則通知「回簽」？\n回簽代表您已確認，送出後無法自行撤回。')) return;
        var ids = $rows.map(function () { return $(this).data('id'); }).get();
        var ok = 0, fail = [], i = 0;
        busy = true; refresh();
        (function next() {
            if (i >= ids.length) {
                busy = false;
                toast(label + '完成：成功 ' + ok + ' 則' + (fail.length ? '，失敗 ' + fail.length + ' 則（' + fail.slice(0, 2).join('；') + (fail.length > 2 ? '…' : '') + '）' : ''));
                applyFilter();
                return;
            }
            var id = ids[i++];
            var url = kind === 'read' ? '../../src/store/_markEventRead.php' : '../../src/store/_eventRespond.php';
            var data = kind === 'read' ? { eventid: id } : { eventid: id, action: 'sign' };
            $.post(url, data, null, 'json').done(function (r) {
                if (r && r.ok) { ok++; dropRow(id); }
                else fail.push('#' + id + ' ' + ((r && r.msg) || '失敗'));
            }).fail(function () { fail.push('#' + id + ' 連線失敗'); }).always(next);
        })();
    }
    $('#btnRead').on('click', function () { runBatch('read'); });
    $('#btnSign').on('click', function () { runBatch('sign'); });

    // ---- 分頁（待處理／已處理／全部）與來源篩選 ----
    var API = '../../src/store/_myNotices.php', hPage = 1, hPages = 1;
    var esc = function (t) { return $('<i>').text(t == null ? '' : t).html(); };
    function fmt(d) { return d ? String(d).substr(0, 10).replace(/-/g, '.') : ''; }
    function fillSources(list) {
        list = (list || []).slice();
        $('#tdList .td-row').each(function () { var v = $(this).attr('data-source'); if (v) list.push(v); });
        list.forEach(function (v) {
            if (v && !$('#tdSrc option').filter(function () { return this.value === v; }).length) $('#tdSrc').append($('<option>').val(v).text(v));
        });
    }
    function histRow(r) {
        var tg = r.done ? '<span class="td-tag tg-done">已處理</span>' : '<span class="td-tag tg-undone">未處理</span>';
        var mode = { autoread: '已閱', read: '已閱', sign: '回簽', reply: '回覆+回簽' }[r.mode] || '';
        return '<div class="td-row" data-id="' + r.id + '"><div class="td-chk"></div><div class="td-main">'
            + '<div class="td-title">' + tg + '<span class="td-tag tg-read">' + esc(mode) + '</span>' + esc(r.title) + '</div>'
            + '<div class="td-prev">' + esc(r.snippet) + '</div>'
            + '<div class="td-meta"><i class="fa fa-clock-o"></i> ' + fmt(r.eventdate) + (r.source ? '　來源：' + esc(r.source) : '') + (r.creator ? '　發布：' + esc(r.creator) : '') + '</div>'
            + '</div><div class="td-side"><a class="td-open" target="_blank" style="text-decoration:none;display:inline-block;" href="mobile.php?event=' + r.id + '">查看</a></div></div>';
    }
    function loadHist(reset) {
        if (reset) { hPage = 1; $('#hList').empty(); } else hPage++;
        var q = { page: hPage, size: 20, source: src };
        if (tab === 'done') q.state = 'done';
        $('#hMore').prop('disabled', true).text('載入中…').show();
        $.get(API, q, null, 'json').done(function (res) {
            if (!res || !res.ok) { toast((res && res.msg) || '載入失敗'); return; }
            hPages = res.pages;
            fillSources(res.sources || []);
            res.rows.forEach(function (r) { $('#hList').append(histRow(r)); });
            if (!$('#hList .td-row').length) $('#hList').html('<div class="td-empty"><i class="fa fa-inbox"></i> 沒有符合的通知</div>');
            $('#hMore').prop('disabled', false).text('載入更多 (' + $('#hList .td-row').length + ' / ' + res.total + ')').toggle(hPage < hPages);
        }).fail(function () { toast('連線失敗'); $('#hMore').prop('disabled', false).text('載入更多'); });
    }
    function showTab(t) {
        tab = t;
        $('#tdTabs .tab').removeClass('on').filter('[data-t="' + t + '"]').addClass('on');
        var todo = t === 'todo';
        $('#tdBar, #tdList').toggle(todo);
        $('#hList').toggle(!todo); $('#hMore').hide();
        if (todo) applyFilter(); else loadHist(true);
    }
    $('#tdTabs').on('click', '.tab', function () { showTab($(this).data('t')); });
    $('#tdSrc').on('change', function () { src = $(this).val(); if (tab === 'todo') applyFilter(); else loadHist(true); });
    $('#hMore').on('click', function () { loadHist(false); });
    // 來源下拉：待處理清單的來源 + 我收到的所有通知的來源
    fillSources([]);
    $.get(API, { page: 1, size: 1 }, null, 'json').done(function (res) { if (res && res.ok) fillSources(res.sources || []); });

    applyFilter();
});
</script>
</body>
</html>
