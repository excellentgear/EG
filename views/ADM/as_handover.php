<?php
/**
 * AS 負責人交接與權限設定
 * 一頁設定「AS 管理員」與「AS 負責人」各自該有的角色／頁面權限，可換人、暫停負責人、看他近期的簽核動作、還原。
 * 預設定義唯一來源：src/common/as_handover_lib.php 的 ash_presets()。
 */
session_start();
if (!isset($_SESSION['userName'])) {
    $_SESSION['lastpage'] = "../../views/ADM/as_handover.php";
    header("Location:../../index.php");
    exit;
}
include_once '../../src/common/_config.php';
include_once '../../src/common/DBConnection.php';
require_once '../../src/common/asdoc_lib.php';
require_once '../../src/common/as_handover_lib.php';

$db = (new DBConnection())->getPDO();
$st = $db->prepare("SELECT id FROM `user` WHERE user_uname=?");
$st->execute([$_SESSION['userName']]);
$uid = (int)$st->fetchColumn();
$canUse = ash_is_sys_admin($db, $uid) || eg_asdoc_is_admin($db, $uid);
?>
<!DOCTYPE html>
<html lang="zh-Hant">
<head>
    <meta charset="utf-8">
    <meta http-equiv="X-UA-Compatible" content="IE=edge">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>AS負責人交接與權限</title>
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
        @media print { .page-help-btn, .ah-act { display:none !important; } }
        .ah { color:#5b3a1e; font-size:14px; }
        .ah-card { background:#fff; border:1px solid #E8D5B5; border-radius:8px; margin-bottom:12px; }
        .ah-card > h4 { margin:0; padding:9px 14px; background:#F7E0BD; border-radius:8px 8px 0 0; color:#5b3a1e; font-size:15px; }
        .ah-card .bd { padding:12px 14px; }
        .ah-grid { display:grid; grid-template-columns:repeat(auto-fit,minmax(340px,1fr)); gap:12px; }
        .ah-who { display:flex; flex-wrap:wrap; gap:6px; align-items:center; margin:4px 0 8px; }
        .ah-chip { display:inline-block; padding:2px 10px; border-radius:12px; background:#F7E0BD; color:#5b3a1e; font-size:13px; }
        .ah-chip.warn { background:#DD5138; color:#fff; }
        .ah-chip.pause { background:#F0A24B; color:#fff; }
        .ah-chip.ok { background:#E9C79A; }
        .ah-btn { height:30px; padding:0 12px; font-size:13px; border:1px solid #D8BE93; border-radius:4px; background:#fff; color:#5b3a1e; cursor:pointer; }
        .ah-btn:hover { background:#F7E0BD; }
        .ah-btn.pri { background:#F0A24B; border-color:#d98a33; color:#fff; }
        .ah-btn.dan { background:#DD5138; border-color:#b83f2a; color:#fff; }
        .ah-btn:disabled { opacity:.5; cursor:not-allowed; }
        .ah select, .ah input[type=text], .ah input[type=number] { height:30px; font-size:13px; padding:0 8px; border:1px solid #D8BE93; border-radius:4px; background:#fff; color:#5b3a1e; max-width:100%; }
        .ah table { width:100%; border-collapse:collapse; font-size:13px; }
        .ah th { background:#FBF3E5; padding:5px 8px; text-align:left; border-bottom:2px solid #E8D5B5; }
        .ah td { padding:5px 8px; border-bottom:1px solid #F0E3CB; vertical-align:top; line-height:1.5; }
        .ah td span { line-height:1.5; }
        .ah .no { color:#DD5138; font-weight:bold; } .ah .yes { color:#8A5A2B; }
        .ah-note { font-size:12px; color:#8a6d45; margin:4px 0; }
        .ah-flag { display:inline-block; padding:0 7px; border-radius:9px; font-size:12px; margin-right:3px; background:#DD5138; color:#fff; line-height:1.6; }
        .ah-plan { background:#FBF3E5; border:1px dashed #D8BE93; border-radius:6px; padding:8px 12px; margin:8px 0; font-size:13px; }
        .ah-plan b { color:#8A5A2B; } .ah-plan .rm { color:#DD5138; }
        .ah-alert { background:#FBE3DE; border:1px solid #DD5138; border-radius:6px; padding:8px 12px; margin-bottom:12px; color:#8a2a1a; }
        .ah-mask { position:fixed; inset:0; background:rgba(60,40,20,.45); display:none; z-index:10050; align-items:center; justify-content:center; }
        .ah-mask.on { display:flex; }
        .ah-modal { background:#fff; width:680px; max-width:96vw; max-height:90vh; overflow:auto; border-radius:8px; }
        .ah-modal .m-head { background:#F7E0BD; padding:10px 14px; font-weight:bold; display:flex; justify-content:space-between; }
        .ah-modal .m-body { padding:12px 16px; } .ah-modal .m-foot { padding:8px 14px; text-align:right; border-top:1px solid #EADFC8; }
        .help-doc { font-size:13px; line-height:1.75; }
        .help-doc h4 { color:#8A5A2B; border-bottom:2px solid #F7E0BD; padding-bottom:3px; margin:14px 0 6px; font-size:15px; }
    </style>
</head>
<body class="nav-sm">
<div class="container body">
<div class="main_container">
    <?php include '../partPage/sideAndTopBarMenu.html' ?>
    <div class="right_col" role="main">
        <div class="page-title">
            <h3><i class="fa fa-exchange"></i> AS負責人交接與權限</h3>
            <a class="ah-btn" href="flow_map.php" style="line-height:28px;text-decoration:none"><i class="fa fa-sitemap"></i> AS 流程地圖</a>
            <button class="page-help-btn" id="btnPageHelp" style="margin-left:0"><i class="fa fa-question-circle"></i> 使用說明</button>
        </div>
<?php if (!$canUse): ?>
        <div class="ah"><div class="ah-alert">只有「AS 管理員」或系統管理員可以使用本頁。</div></div>
<?php else: ?>
        <div class="ah">
            <div id="alerts"></div>
            <div class="ah-grid">
                <div class="ah-card"><h4><i class="fa fa-user"></i> 目前 AS 負責人</h4><div class="bd" id="ownerBox">載入中…</div></div>
                <div class="ah-card"><h4><i class="fa fa-shield"></i> 目前 AS 管理員</h4><div class="bd" id="adminBox">載入中…</div></div>
            </div>

            <div class="ah-card ah-act"><h4><i class="fa fa-sliders"></i> 設定身分（一次套用整組角色與頁面權限）</h4><div class="bd">
                <div style="display:flex;flex-wrap:wrap;gap:8px;align-items:center">
                    <select id="selPerson" data-eg-filter="輸入姓名篩選…" style="min-width:240px"></select>
                    <select id="selMode">
                        <option value="handover">設為 AS負責人（取代現任，現任轉為管理員）</option>
                        <option value="set_admin">設為 AS管理員（系統管理員限定）</option>
                        <option value="remove">移除其 AS 權限（系統管理員限定）</option>
                    </select>
                    <input type="text" id="txtNote" placeholder="備註（選填，例如：2026/11 新舊交接）" style="width:260px">
                    <button class="ah-btn" id="btnPreview">預覽變更</button>
                </div>
                <div id="planBox"></div>
                <div class="ah-note">按「預覽」才會看到每個人會增加／移除哪些角色；確認後再按「套用」。所有變更都會留紀錄並可還原。</div>
            </div></div>

            <div class="ah-card"><h4><i class="fa fa-th-list"></i> 兩種身分各自有什麼權限</h4><div class="bd" id="presetBox"></div></div>

            <div class="ah-card"><h4><i class="fa fa-eye"></i> 活動紀錄（看他近期做了什麼簽核、改版、申請）</h4><div class="bd">
                <div style="display:flex;flex-wrap:wrap;gap:8px;align-items:center">
                    <select id="actPerson" data-eg-filter="輸入姓名篩選…" style="min-width:220px"></select>
                    <select id="actDays"><option value="7">近 7 天</option><option value="30" selected>近 30 天</option><option value="90">近 90 天</option></select>
                    <button class="ah-btn" id="btnAct">查詢</button>
                </div>
                <div id="actBox" style="margin-top:8px"></div>
            </div></div>

            <div class="ah-card"><h4><i class="fa fa-history"></i> 異動紀錄</h4><div class="bd" id="logBox">載入中…</div></div>
        </div>
<?php endif; ?>
    </div>
</div>
</div>

<div class="ah-mask" id="helpUseMask"><div class="ah-modal">
    <div class="m-head"><span>使用說明：AS負責人交接與權限</span><a href="#" class="ah-close" style="color:#5b3a1e">✕</a></div>
    <div class="m-body help-doc">
        <h4>這頁做什麼</h4>
        <p>把「AS 管理員」與「AS 負責人」兩種身分各自該有的角色與頁面權限<b>一次設好</b>，不必到權限設定頁逐項勾。</p>
        <ul>
            <li><b>AS 管理員</b>：完整權限，含自動簽核、補登免附件、各模組管理員，可多人。</li>
            <li><b>AS 負責人</b>：日常維護文件，<b>拿不到</b>自動簽核、補登、管理員功能；只能一人。</li>
        </ul>
        <h4>換負責人</h4>
        <ol>
            <li>選新的負責人 → 動作選「設為 AS負責人」→ 按「預覽變更」。</li>
            <li>預覽會列出：新人增加哪些角色、<b>現任負責人會轉為管理員</b>（交接期間可繼續教導，也能隨時接手）。</li>
            <li>確認後按「套用」，全部在同一個交易內完成，失敗會整批還原。</li>
        </ol>
        <h4>新負責人擺爛、要先由舊負責人接手</h4>
        <p>在「目前 AS 負責人」按<b>「暫停負責人」</b>（要填原因）：他降為唯讀，管理員照常處理；之後按「恢復」就照暫停前的設定還原。</p>
        <h4>新負責人亂簽核</h4>
        <p>「活動紀錄」列出他近期所有簽核、改版、申請單；<span class="ah-flag">自己送自己簽</span>、<span class="ah-flag">短時間連簽</span>、<span class="ah-flag">退回未寫原因</span>會標紅。發現異常可立刻暫停。簽核流程設定、結構總覽自動核准這類功能，負責人本來就按不了。</p>
        <h4>還原</h4>
        <p>「異動紀錄」每一筆都存了變更前的角色，系統管理員按「還原」即可回到那一筆之前。</p>
        <h4>權限</h4>
        <p>本頁限 AS 管理員與系統管理員。改動管理員身分（含交接時把舊負責人轉為管理員）限系統管理員。</p>
    </div>
    <div class="m-foot"><button class="ah-btn ah-close">關閉</button></div>
</div></div>

<script src="../../resource/js/jquery.min.js"></script>
<script src="../../resource/js/bootstrap.min.js"></script>
<script src="../../resource/js/fastclick.js"></script>
<script src="../../resource/js/nprogress.js"></script>
<script src="../../resource/js/custom.min.js"></script>
<script src="../../resource/js/eg_input_rules.js?v=<?= @filemtime(__DIR__.'/../../resource/js/eg_input_rules.js') ?>"></script>
<script src="../../resource/js/eg_date_fmt.js?v=<?= @filemtime(__DIR__.'/../../resource/js/eg_date_fmt.js') ?>"></script>
<script>
$(function(){
    $('#sidebar-menu').css('visibility','visible');
    $('#btnPageHelp').on('click', function(){ $('#helpUseMask').addClass('on'); });
    $(document).on('click', '.ah-close', function(e){ e.preventDefault(); $(this).closest('.ah-mask').removeClass('on'); });
<?php if ($canUse): ?>
    var API = '../../src/store/AsHandover_API.php', S = null;
    function esc(s){ return $('<div>').text(s == null ? '' : s).html(); }
    function dt(s){ return s ? (egFmtDate(String(s).substr(0,10)) + ' ' + String(s).substr(11,5)) : ''; }
    function get(a, p){ return $.getJSON(API, $.extend({action:a}, p||{})); }
    function post(a, p){ return $.post(API, $.extend({action:a, csrf:S && S.csrf}, p||{}), null, 'json'); }
    function fail(x){ var m = (x.responseJSON && x.responseJSON.error) || '操作失敗'; alert(m); }

    function chips(arr, cls){ return arr.length ? arr.map(function(p){ return '<span class="ah-chip '+(cls||'')+'">'+esc(p.name)+'</span>'; }).join('') : '<span class="ah-note">（無）</span>'; }

    function render(){
        var alerts = [];
        S.strays.forEach(function(s){
            alerts.push('<b>'+esc(s.name)+'</b> 持有「'+esc(s.role)+'」'+(s.is_owner?'，但他是 AS 負責人（負責人不該有管理員角色）':'，但不是 AS 管理員身分（建議用本頁設定，避免權限散落各處）'));
        });
        Object.keys(S.health).forEach(function(k){
            var h = S.health[k];
            h.bad.forEach(function(b){ alerts.push('AS 負責人被給了不該有的權限：'+esc(b.code)+'（'+esc(b.why)+'）'); });
        });
        if (S.owners.length > 1) alerts.push('目前有 '+S.owners.length+' 位 AS 負責人，負責人應只有一人。');
        if (S.owners.length === 0 && !S.state.paused_user_id) alerts.push('目前沒有 AS 負責人。');
        $('#alerts').html(alerts.length ? '<div class="ah-alert"><i class="fa fa-warning"></i> '+alerts.join('<br>')+'</div>' : '');

        var ob = '<div class="ah-who">' + chips(S.owners) + '</div>';
        if (S.state.paused_user_id) {
            ob += '<div class="ah-who"><span class="ah-chip pause">已暫停：'+esc(S.state.paused_name)+'</span><span class="ah-note">'+dt(S.state.paused_at)+'　原因：'+esc(S.state.paused_reason)+'</span></div>'
                + '<button class="ah-btn pri ah-act" id="btnResume">恢復負責人權限</button>';
        } else if (S.owners.length === 1) {
            var h = S.health['o'+S.owners[0].id];
            ob += '<div class="ah-who">'+(h.ok?'<span class="ah-chip ok">權限符合負責人預設</span>':'<span class="ah-chip warn">權限與預設不符（'+(h.bad.length+h.missing.length)+' 項）</span>')+'</div>'
               + '<button class="ah-btn dan ah-act" id="btnPause">暫停負責人（降為唯讀）</button>';
        }
        $('#ownerBox').html(ob);
        $('#adminBox').html('<div class="ah-who">'+chips(S.admins)+'</div><div class="ah-note">交接期間，舊負責人在這裡，可隨時接手；管理員可多人。</div>');

        var opts = '<option value="">（請選人）</option>' + S.people.map(function(p){
            return '<option value="'+p.id+'">'+esc(p.dept+'　'+p.pos+'　'+p.name)+'</option>'; }).join('');
        $('#selPerson,#actPerson').each(function(){ var v = $(this).val(); $(this).html(opts).val(v); });

        var t = '<table><tr><th style="width:50%">項目</th><th>AS負責人</th><th>AS管理員</th></tr>';
        var P = S.presets, rolesOwn = {}, rolesAdm = {};
        P.owner.roles.forEach(function(r){ rolesOwn[r.code] = 1; }); P.admin.roles.forEach(function(r){ rolesAdm[r.code] = 1; });
        t += '<tr><td colspan="3" style="background:#FBF3E5"><b>角色</b></td></tr>';
        P.owner.roles.concat(P.admin.roles).forEach(function(r, i, all){
            if (all.findIndex(function(x){ return x.code===r.code; }) !== i) return;
            t += '<tr><td>'+esc(r.name)+(r.exists?'':' <span class="no">（角色不存在）</span>')+'</td><td>'+(rolesOwn[r.code]?'<span class="yes">✔ 有</span>':'<span class="no">✘ 不給</span>')+'</td><td>'+(rolesAdm[r.code]?'<span class="yes">✔ 有</span>':'<span class="no">✘ 不給</span>')+'</td></tr>';
        });
        t += '<tr><td colspan="3" style="background:#FBF3E5"><b>頁面（至少有唯讀 R）</b></td></tr>';
        var po = {}, pa = {}; P.owner.pages.forEach(function(p){ po[p.file]=p; }); P.admin.pages.forEach(function(p){ pa[p.file]=p; });
        Object.keys($.extend({}, po, pa)).forEach(function(f){
            t += '<tr><td>'+esc((po[f]||pa[f]).name)+'</td><td>'+(po[f]?'<span class="yes">✔ R（只給檢視，細部看角色）</span>':'<span class="no">✘</span>')+'</td><td>'+(pa[f]?'<span class="yes">✔ R</span>':'<span class="no">✘</span>')+'</td></tr>';
        });
        t += '</table><div class="ah-note">負責人另外會被拿掉：自動簽核（文件制修申請單批次自動簽核、SOP/SIP 自動簽核設定）、結構總覽自動核准、簽核流程設定、補登免附件、補案件。若 AS 頁面被給了 A（管理）權限也會一併拿掉。</div>';
        $('#presetBox').html(t);
        $('#selMode option[value=set_admin],#selMode option[value=remove]').prop('disabled', !S.is_sys);
    }

    function load(){
        get('status').done(function(r){ S = r; render(); }).fail(fail);
        loadLog();
    }
    function loadLog(){
        get('log').done(function(r){
            var t = '<table><tr><th>時間</th><th>對象</th><th>動作</th><th>增加</th><th>移除</th><th>操作人／備註</th><th></th></tr>';
            var lab = {apply:'套用身分', pause:'暫停負責人', resume:'恢復負責人', restore:'還原'}, pre = {owner:'AS負責人', admin:'AS管理員', none:'移除'};
            r.rows.forEach(function(x){
                function nm(a){ return a.map(function(c){ return esc(r.role_names[c]||c); }).join('、')||'—'; }
                t += '<tr><td>'+dt(x.created_at)+'</td><td>'+esc(x.target_name)+'</td><td>'+(lab[x.action]||x.action)+(x.preset?'（'+(pre[x.preset]||x.preset)+'）':'')+'</td><td>'+nm(x.added)+'</td><td class="no">'+nm(x.removed)+'</td><td>'+esc(x.actor_name)+(x.note?'<br><span class="ah-note">'+esc(x.note)+'</span>':'')+'</td>'
                   +'<td>'+(S && S.is_sys ? '<button class="ah-btn ah-act btnRestore" data-id="'+x.id+'">還原到此前</button>' : '')+'</td></tr>';
            });
            $('#logBox').html(r.rows.length ? t+'</table>' : '<span class="ah-note">尚無異動紀錄</span>');
        });
    }

    $('#btnPreview').on('click', function(){
        var u = $('#selPerson').val(); if (!u) { alert('請先選人'); return; }
        post('preview', {user_id:u, mode:$('#selMode').val()}).done(function(r){
            var h = '<div class="ah-plan">';
            r.plans.forEach(function(p){
                var none = !p.add.length && !p.remove.length && !p.page_add.length && !p.page_strip_a.length;
                h += '<div style="margin-bottom:6px"><b>'+esc(p.name)+'</b> → '+({owner:'AS負責人',admin:'AS管理員',none:'移除 AS 權限'}[p.preset])+'<br>'
                  + (none ? '<span class="ah-note">已經符合，不需變更</span>' : (
                    (p.add.length?'增加角色：'+p.add.map(esc).join('、')+'<br>':'')
                  + (p.remove.length?'<span class="rm">移除角色：'+p.remove.map(esc).join('、')+'</span><br>':'')
                  + (p.page_add.length?'補頁面 R：'+p.page_add.map(esc).join('、')+'<br>':'')
                  + (p.page_strip_a.length?'<span class="rm">拿掉頁面 A（管理）權限：'+p.page_strip_a.map(esc).join('、')+'</span>':''))) + '</div>';
            });
            h += '<button class="ah-btn pri" id="btnApply">確認套用</button></div>';
            $('#planBox').html(h);
        }).fail(fail);
    });
    $(document).on('click', '#btnApply', function(){
        if (!confirm('確定套用以上變更？')) return;
        post('apply', {user_id:$('#selPerson').val(), mode:$('#selMode').val(), note:$('#txtNote').val()}).done(function(r){
            alert(r.msg); $('#planBox').empty(); $('#txtNote').val(''); load();
        }).fail(fail);
    });
    $(document).on('click', '#btnPause', function(){
        var why = prompt('暫停原因（必填，例如：久未處理待簽核單據）'); if (!why) return;
        post('pause', {reason:why}).done(function(r){ alert(r.msg); load(); }).fail(fail);
    });
    $(document).on('click', '#btnResume', function(){
        if (!confirm('恢復負責人權限？')) return;
        post('resume').done(function(r){ alert(r.msg); load(); }).fail(fail);
    });
    $(document).on('click', '.btnRestore', function(){
        if (!confirm('把這個人的 AS 角色還原到這筆紀錄「變更前」的樣子？（只動 AS 相關角色）')) return;
        post('restore', {log_id:$(this).data('id')}).done(function(r){ alert(r.msg); load(); }).fail(fail);
    });
    $('#btnAct').on('click', function(){
        var u = $('#actPerson').val(); if (!u) { alert('請先選人'); return; }
        get('activity', {user_id:u, days:$('#actDays').val()}).done(function(r){
            var s = r.summary;
            var h = '<div class="ah-who"><span class="ah-chip">'+esc(r.name)+'　自 '+egFmtDate(r.since)+' 起</span>'
              + '<span class="ah-chip">簽核 '+s.sign+'（核准 '+s.approved+'／退回 '+s.rejected+'）</span>'
              + '<span class="ah-chip'+(s.self?' warn':'')+'">自己送自己簽 '+s.self+'</span>'
              + '<span class="ah-chip'+(s.burst?' warn':'')+'">短時間連簽 '+s.burst+'</span>'
              + '<span class="ah-chip'+(s.noreason?' warn':'')+'">退回未寫原因 '+s.noreason+'</span></div>';
            var fl = {self:'自己送自己簽', burst:'短時間連簽', reject_noreason:'退回未寫原因'};
            h += '<table><tr><th style="width:150px">時間</th><th style="width:130px">模組</th><th>內容</th><th style="width:90px">送出人</th><th style="width:190px">提醒</th></tr>';
            r.rows.forEach(function(x){
                h += '<tr><td>'+dt(x.at)+'</td><td>'+esc(x.module)+'</td><td>'+esc(x.what)+'</td><td>'+esc(x.submitter)+'</td><td>'
                  + x.flags.map(function(f){ return '<span class="ah-flag">'+fl[f]+'</span>'; }).join('')+'</td></tr>';
            });
            $('#actBox').html(r.rows.length ? h+'</table>' : h+'<div class="ah-note">這段期間沒有紀錄</div>');
        }).fail(fail);
    });
    load();
<?php endif; ?>
});
</script>
</body>
</html>
