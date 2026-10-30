<?php
/**
 * 人員指派總覽（2026-10-30 使用者要求）
 * 記錄「哪些人在哪些頁面／設定裡被指名」；有人離職時可在這裡直接換成替代人員。
 * 邏輯：src/common/user_assign_lib.php（來源登記表）；資料：src/store/UserAssign_API.php。
 * 權限：全站管理員。
 */
session_start();
if (!isset($_SESSION['userName'])) {
    $_SESSION['lastpage'] = "../../views/admin/user_assignments.php";
    header("Location:../../index.php");
    exit;
}
include_once '../../src/common/_config.php';
include_once '../../src/common/DBConnection.php';
include_once '../../src/common/print_log_lib.php';

$db = (new DBConnection())->getPDO();
$u  = eg_printlog_current_user($db);
$perms = eg_printlog_perms($db, $u);
if (empty($perms['isAdmin'])) { http_response_code(403); echo '<meta charset="utf-8">此頁僅限全站管理員使用。'; exit; }
?>
<!DOCTYPE html>
<html lang="zh-Hant">
<head>
    <meta charset="utf-8">
    <meta http-equiv="X-UA-Compatible" content="IE=edge">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>人員指派總覽</title>
    <link href="../../resource/css/bootstrap.css" rel="stylesheet">
    <link href="../../resource/css/font-awesome.css" rel="stylesheet">
    <link href="../../resource/css/nprogress.css" rel="stylesheet">
    <link href="../../resource/css/custom.css" rel="stylesheet">
    <style>
        #sidebar-menu { visibility: hidden; }
        .right_col { color:#5b3a1e; }
        .right_col .page-title { margin:8px 0 4px; overflow:hidden; clear:both; }
        .page-help-btn { height:30px; font-size:13px; padding:0 12px; border:1px solid #d98a33; border-radius:15px;
            background:#F0A24B; color:#fff; cursor:pointer; margin-left:auto; }
        .page-help-btn:hover { background:#d98a33; }
        .help-doc { font-size:13px; line-height:1.75; }
        .help-doc h4 { color:#8A5A2B; border-bottom:2px solid #F7E0BD; padding-bottom:3px; margin:14px 0 6px; font-size:15px; }
        .help-doc h4:first-child { margin-top:0; }
        .help-doc li { margin:2px 0; }
        .ua-tools { clear:both; display:flex; flex-wrap:wrap; gap:6px 10px; align-items:center; border:1.5px solid #E8D5B5;
            border-radius:8px; padding:8px 10px; background:#FDF8EF; margin-bottom:10px; }
        .ua-tools input[type=text], .ua-tools select, .ua-tools button { height:30px; font-size:13px; line-height:1; padding:0 8px;
            border:1px solid #D8BE93; border-radius:4px; background:#fff; color:#5b3a1e; }
        .ua-tools button { cursor:pointer; }
        .ua-tools button.pri { background:#F0A24B; color:#fff; border-color:#d98a33; }
        .ua-tools label { margin:0; font-size:13px; }
        .ua-banner { clear:both; display:none; border:1.5px solid #DD5138; background:#FBE9E4; color:#8c2e1b;
            border-radius:8px; padding:8px 12px; margin-bottom:10px; font-size:13px; }
        table.ua { width:100%; border-collapse:collapse; background:#fff; font-size:13px; }
        table.ua th { background:#F7E0BD; color:#5b3a1e; text-align:left; padding:6px 8px; border:1px solid #E8D5B5; white-space:nowrap; }
        table.ua td { padding:6px 8px; border:1px solid #EFE2C8; vertical-align:middle; }
        table.ua tr.gone td { background:#FFF3EE; }
        .ua-tag { display:inline-block; font-size:11px; line-height:16px; padding:0 7px; border-radius:8px; color:#fff; background:#a8814f; }
        .ua-tag.bad { background:#DD5138; }
        .ua-tag.warn { background:#F0A24B; }
        .ua-btn { height:26px; font-size:12px; line-height:1; padding:0 9px; border:1px solid #d98a33; border-radius:4px;
            background:#F0A24B; color:#fff; cursor:pointer; }
        .ua-btn.o { background:#fff; color:#8A5A2B; }
        .ua-mask { display:none; position:fixed; inset:0; background:rgba(60,40,20,.45); z-index:10050; align-items:center; justify-content:center; }
        .ua-mask.on { display:flex; }
        .ua-box { background:#fff; border-radius:8px; width:520px; max-width:94vw; max-height:90vh; overflow:auto; padding:16px 18px; }
        .ua-box h3 { margin:0 0 10px; font-size:16px; color:#8A5A2B; }
        .ua-box select { width:100%; height:32px; margin:6px 0 10px; }
        .ua-err { color:#C0563A; font-size:12px; min-height:18px; }
    </style>
</head>
<body class="nav-md">
<div class="container body"><div class="main_container">
<?php include '../../src/common/sideAndTopBarMenu.html'; ?>
<div class="right_col" role="main">
    <div class="page-title" style="display:flex;align-items:center;">
        <h3 style="margin:0;color:#8A5A2B;">人員指派總覽</h3>
        <button type="button" class="page-help-btn" id="btnPageHelp">使用說明</button>
    </div>
    <div class="ua-banner" id="uaBanner"></div>
    <div class="ua-tools">
        <input type="text" id="kw" placeholder="搜尋人員／模組／設定項目" data-eg-hint="多個關鍵字用空白分隔" style="width:230px">
        <label><input type="checkbox" id="onlyInactive"> 只看已離職／停用者</label>
        <button type="button" id="btnReload">重新整理</button>
        <button type="button" class="pri" id="btnNotify" title="立即盤點離職者並通知管理員與主管">通知管理員與主管</button>
        <span id="summary" style="margin-left:auto;font-size:13px;"></span>
    </div>
    <table class="ua">
        <thead><tr><th>人員</th><th>狀態</th><th>模組</th><th>設定項目</th><th>指派內容</th><th style="width:150px">操作</th></tr></thead>
        <tbody id="tb"><tr><td colspan="6">載入中…</td></tr></tbody>
    </table>
</div>
</div></div>

<div class="ua-mask" id="mkReplace"><div class="ua-box">
    <h3>更換替代人員</h3>
    <div id="rpInfo" style="font-size:13px;"></div>
    <select id="rpPeople" data-eg-filter="輸入姓名或部門篩選…"></select>
    <div class="ua-err" id="rpErr"></div>
    <div style="text-align:right;margin-top:6px;">
        <button type="button" class="ua-btn o" id="rpCancel">取消</button>
        <button type="button" class="ua-btn" id="rpOk">確定更換</button>
    </div>
</div></div>

<div class="ua-mask" id="helpUseMask"><div class="ua-box help-doc" style="width:640px">
    <h3>人員指派總覽 使用說明</h3>
    <h4>這頁是做什麼的</h4>
    <ul><li>列出系統各處「指定了某個人」的設定（組織角色、部門負責人／代理人、稽核資格、通知名單、代理設定、輪值…）。</li>
        <li>人離職後權限會被封鎖，但這些設定還指著他——功能不報錯，只是單據送不到對的人。這頁就是把這種缺口列出來。</li></ul>
    <h4>離職時怎麼處理</h4>
    <ul><li>員工管理把人設為離職／留停後，會跳出提醒並連到本頁；系統也會通知全站管理員與其主管。</li>
        <li>勾「只看已離職／停用者」，按該列「更換」選替代人員；改的只有那一項設定，並寫入稽核紀錄。</li>
        <li>標「到原頁處理」的項目（輪值、設備保管人、BOM 關注群組、共用帳號）有日期歷程或已排好的班表，請點「前往設定」到原頁處理。</li></ul>
    <h4>沒涵蓋的</h4>
    <ul><li>存放在系統參數 JSON 的人員設定、簽核流程設計器中指定的人員步驟，目前不在清單內。新增模組的指派設定時，請在 <b>user_assign_lib.php 的 uas_sources()</b> 加一列。</li></ul>
    <h4>權限</h4><ul><li>僅全站管理員。</li></ul>
    <div style="text-align:right"><button type="button" class="ua-btn" id="helpClose">關閉</button></div>
</div></div>

<script src="../../resource/js/jquery.min.js"></script>
<script src="../../resource/js/bootstrap.min.js"></script>
<script src="../../resource/js/fastclick.js"></script>
<script src="../../resource/js/nprogress.js"></script>
<script src="../../resource/js/custom.min.js"></script>
<script src="../../resource/js/eg_input_rules.js?v=<?= @filemtime(__DIR__.'/../../resource/js/eg_input_rules.js') ?>"></script>
<script src="../../resource/js/eg_date_fmt.js?v=<?= @filemtime(__DIR__.'/../../resource/js/eg_date_fmt.js') ?>"></script>
<script>
$(function () {
    $('#sidebar-menu').css('visibility', 'visible');
    var API = '../../src/store/UserAssign_API.php', CSRF = '', ROWS = [], CUR = null, PEOPLE = null;
    var qs = new URLSearchParams(location.search);
    if (qs.get('inactive') === '1') $('#onlyInactive').prop('checked', true);
    if (qs.get('user_id')) $('#kw').data('uid', parseInt(qs.get('user_id'), 10) || 0);

    function esc(s) { return $('<div>').text(s == null ? '' : s).html(); }
    function stateTag(r) {
        if (r.state === 0) return '<span class="ua-tag bad">離職</span>';
        if (r.state === 2) return '<span class="ua-tag bad">留職停薪</span>';
        if (r.state === 3) return '<span class="ua-tag bad">育嬰留停</span>';
        if (r.inactive) return '<span class="ua-tag bad">已過離職日 ' + esc(window.egFmtDate ? egFmtDate(r.leave_date) : r.leave_date) + '</span>';
        if (r.leave_date) return '<span class="ua-tag warn">預定離職 ' + esc(window.egFmtDate ? egFmtDate(r.leave_date) : r.leave_date) + '</span>';
        return '<span class="ua-tag" style="background:#7a9a4b">在職</span>';
    }
    function load() {
        var uid = $('#kw').data('uid') || 0;
        $.getJSON(API, {action: 'list', only_inactive: $('#onlyInactive').is(':checked') ? 1 : 0,
                        kw: $('#kw').val(), user_id: uid}, function (res) {
            if (!res.ok) { $('#tb').html('<tr><td colspan="6">' + esc(res.error) + '</td></tr>'); return; }
            CSRF = res.csrf; ROWS = res.rows;
            var bad = ROWS.filter(function (r) { return r.inactive; });
            var who = {}; bad.forEach(function (r) { who[r.user_id] = 1; });
            if (bad.length) $('#uaBanner').show().html('<b>' + Object.keys(who).length + ' 位已離職／停用的人員仍被指派於 ' + bad.length + ' 項設定</b>，請更換替代人員（紅底列）。');
            else $('#uaBanner').hide();
            $('#summary').text('共 ' + ROWS.length + ' 項指派');
            if (!ROWS.length) { $('#tb').html('<tr><td colspan="6" style="text-align:center;color:#8a6d45">沒有符合的指派</td></tr>'); return; }
            $('#tb').html(ROWS.map(function (r, i) {
                var act = r.replace
                    ? '<button type="button" class="ua-btn" data-rp="' + i + '">更換</button> '
                    : '<span class="ua-tag" style="background:#a8814f">到原頁處理</span> ';
                act += '<a class="ua-btn o" style="text-decoration:none;display:inline-block;line-height:24px" target="_blank" href="' + esc(r.url) + '">前往設定</a>';
                return '<tr class="' + (r.inactive ? 'gone' : '') + '"><td>' + esc(r.user_name) + '</td><td>' + stateTag(r) + '</td><td>'
                    + esc(r.module) + '</td><td>' + esc(r.title) + '</td><td>' + esc(r.label) + '</td><td>' + act + '</td></tr>';
            }).join(''));
        });
    }
    function loadPeople(cb) {
        if (PEOPLE) return cb();
        $.getJSON(API, {action: 'people'}, function (res) { PEOPLE = res.people || []; cb(); });
    }
    $('#tb').on('click', '[data-rp]', function () {
        CUR = ROWS[parseInt($(this).attr('data-rp'), 10)];
        $('#rpInfo').html('<b>' + esc(CUR.module) + '／' + esc(CUR.title) + '</b>（' + esc(CUR.label) + '）<br>目前指派：<b>' + esc(CUR.user_name) + '</b>　請選擇替代人員：');
        $('#rpErr').text('');
        loadPeople(function () {
            $('#rpPeople').html('<option value="">請選擇…</option>' + PEOPLE.filter(function (p) { return p.id !== CUR.user_id; }).map(function (p) {
                return '<option value="' + p.id + '">' + esc(p.name + (p.posts.length ? '（' + p.posts.join('、') + '）' : '')) + '</option>';
            }).join(''));
            $('#mkReplace').addClass('on');
        });
    });
    $('#rpCancel').on('click', function () { $('#mkReplace').removeClass('on'); });
    $('#rpOk').on('click', function () {
        var nu = parseInt($('#rpPeople').val(), 10) || 0;
        if (!nu) { $('#rpErr').text('請先選擇替代人員'); return; }
        $.post(API, {action: 'replace', csrf: CSRF, src: CUR.src, pk: CUR.pk, old_uid: CUR.user_id, new_uid: nu}, function (res) {
            $('#mkReplace').removeClass('on'); load();
        }, 'json').fail(function (x) {
            var m = (x.responseJSON && x.responseJSON.error) || '更換失敗';
            $('#rpErr').text(m);
            if (x.responseJSON && x.responseJSON.code === 'CSRF') load();
        });
    });
    $('#btnReload, #onlyInactive').on('click change', load);
    $('#kw').on('input', function () { $(this).data('uid', 0); clearTimeout(window._t); window._t = setTimeout(load, 300); });
    $('#btnNotify').on('click', function () {
        $.post(API, {action: 'notify', csrf: CSRF}, function (res) {
            alert(res.sent > 0 ? '已通知 ' + res.sent + ' 位管理員／主管' : '目前沒有需要通知的項目（或同一組內容近期已通知過）');
        }, 'json');
    });
    $('#btnPageHelp').on('click', function () { $('#helpUseMask').addClass('on'); });
    $('#helpClose').on('click', function () { $('#helpUseMask').removeClass('on'); });
    load();
});
</script>
</body>
</html>
