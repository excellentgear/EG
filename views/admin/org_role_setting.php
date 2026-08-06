<?php
/**
 * 組織角色綁定設定（全站共用）
 * 「哪一個部門是人事部門／品管部門…」「誰是最高核准人員／管理代表／人事簽章人」統一在這裡設定，
 * 各頁面一律呼叫 src/common/org_role_lib.php 讀取，禁止自己寫死部門 id 或人名。
 */
session_start();
if (!isset($_SESSION['userName'])) {
    $_SESSION['lastpage'] = "../../views/admin/org_role_setting.php";
    header("Location:../../index.php");
    exit;
}
include_once '../../src/common/_config.php';
include_once '../../src/common/DBConnection.php';
include_once '../../src/common/org_role_lib.php';

$db = (new DBConnection())->getPDO();
eg_org_ensure_schema($db);
$st = $db->prepare("SELECT id, user_cname, user_status FROM user WHERE user_uname=?");
$st->execute([$_SESSION['userName']]);
$me = $st->fetch(PDO::FETCH_ASSOC) ?: [];
$isAdmin = in_array((int)($me['user_status'] ?? 0), [9, 90], true);
if (!$isAdmin && !empty($me['id'])) {
    $q = $db->prepare("SELECT 1 FROM user_roles ur JOIN roles r ON r.role_id=ur.role_id
                       WHERE ur.user_id=? AND r.role_code='admin' AND r.is_system=1 LIMIT 1");
    $q->execute([(int)$me['id']]);
    $isAdmin = (bool)$q->fetchColumn();
}
?>
<!DOCTYPE html>
<html lang="zh-Hant">
<head>
    <meta charset="utf-8">
    <meta http-equiv="X-UA-Compatible" content="IE=edge">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>組織角色綁定設定</title>
    <link href="../../resource/css/bootstrap.css" rel="stylesheet">
    <link href="../../resource/css/font-awesome.css" rel="stylesheet">
    <link href="../../resource/css/nprogress.css" rel="stylesheet">
    <link href="../../resource/css/custom.css" rel="stylesheet">
    <style>
        #sidebar-menu { visibility: hidden; }
        .right_col .page-title { margin:8px 0 4px; overflow:hidden; }
        .or-bar { display:flex; flex-wrap:wrap; gap:6px; align-items:center; clear:both;
            border:1.5px solid #E8D5B5; border-radius:8px; padding:8px 10px; margin-bottom:10px; background:#FDF8EF; }
        .or-bar button { height:30px; font-size:13px; padding:0 14px; border:1px solid #d98a33;
            border-radius:4px; background:#F0A24B; color:#fff; cursor:pointer; }
        .or-bar button:hover { background:#d98a33; }
        .or-bar .role-badge { margin-left:auto; font-size:13px; color:#5b3a1e; background:#F7E0BD; border-radius:12px; padding:4px 12px; }
        .or-wrap { border:1px solid #E8D5B5; border-radius:6px; background:#fff; overflow-x:auto; margin-bottom:14px; }
        table.or-tbl { width:100%; border-collapse:collapse; font-size:13px; }
        table.or-tbl th, table.or-tbl td { border:1px solid #EADFC8; padding:6px 9px; text-align:left; }
        table.or-tbl thead th { background:#F7E0BD; color:#5b3a1e; }
        table.or-tbl tbody tr:nth-child(even) { background:#FBF6EC; }
        table.or-tbl select { width:100%; max-width:280px; border:1px solid #D8BE93; border-radius:4px; padding:4px 6px; font-size:13px; }
        .or-sec { font-size:14px; font-weight:bold; color:#8A5A2B; margin:14px 0 6px; }
        .or-desc { font-size:11.5px; color:#8a6d45; }
        .or-mgr { font-size:12px; color:#8A5A2B; }
        .page-help-btn { margin-left:auto; height:30px; font-size:13px; padding:0 14px; border:1px solid #D8BE93;
            border-radius:4px; background:#fff; color:#5b3a1e; cursor:pointer; }
        .page-help-btn:hover { background:#F7E0BD; }
        .or-mask { display:none; position:fixed; inset:0; background:rgba(60,40,20,.45); z-index:1050; }
        .or-modal { background:#fff; border-radius:8px; max-width:640px; margin:48px auto; box-shadow:0 5px 25px rgba(0,0,0,.3);
            max-height:86vh; display:flex; flex-direction:column; }
        .or-modal .m-head { background:#F7E0BD; color:#5b3a1e; font-weight:bold; padding:10px 15px; border-radius:8px 8px 0 0;
            display:flex; justify-content:space-between; }
        .or-modal .m-head .m-close { cursor:pointer; color:#b5762a; }
        .or-modal .m-body { padding:15px; overflow-y:auto; font-size:13px; color:#5b3a1e; line-height:1.8; }
        .or-modal .m-foot { padding:10px 15px; border-top:1px solid #EADFC8; text-align:right; }
        .or-modal .m-foot button { height:30px; padding:0 16px; border-radius:4px; font-size:13px;
            border:1px solid #d98a33; background:#F0A24B; color:#fff; cursor:pointer; }
        .help-doc h4 { font-size:14px; color:#8A5A2B; margin:10px 0 4px; }
        .or-noperm { margin:40px auto; max-width:520px; text-align:center; border:1.5px solid #E8D5B5; border-radius:10px;
            padding:30px; background:#FDF8EF; color:#5b3a1e; }
        @media print { .or-bar, .page-help-btn, .nav_menu, .left_col, footer { display:none !important; } }
    </style>
</head>
<body class="nav-sm">
<div class="container body">
<div class="main_container">
    <?php include '../partPage/sideAndTopBarMenu.html' ?>
    <div class="right_col" role="main">
        <div class="page-title" style="display:flex;align-items:center;flex-wrap:wrap;">
            <h2 style="margin:6px 0;">組織角色綁定設定
                <small style="color:#8a6d45;">全站共用：系統認定的部門與關鍵簽章人員</small></h2>
            <button class="page-help-btn" id="btnPageHelp"><i class="fa fa-question-circle"></i> 使用說明</button>
        </div>
        <div class="clearfix"></div>
<?php if (!$isAdmin): ?>
        <div class="or-noperm"><h4><i class="fa fa-lock"></i> 僅系統管理者可設定</h4>
            <p>本頁設定會影響全站表單的部門判定與簽章人，請洽系統管理者。</p></div>
<?php else: ?>
        <div class="or-bar">
            <span style="font-size:12px;color:#8a6d45;">各頁面要判斷「品管部門是哪一個部門」「核准欄要蓋誰的章」時，一律讀這裡的設定，改組織只要改這一頁。</span>
            <button id="btnSave"><i class="fa fa-save"></i> 儲存設定</button>
            <span class="role-badge">目前角色：<b>系統管理者</b></span>
        </div>

        <div class="or-sec">一、部門綁定（系統認定的某某部門）</div>
        <div class="or-wrap">
            <table class="or-tbl">
                <thead><tr><th style="width:170px;">用途</th><th style="width:300px;">對應部門</th>
                    <th style="width:190px;">子部門認列</th><th>說明／目前部門主管</th></tr></thead>
                <tbody id="deptBody"><tr><td colspan="4">載入中…</td></tr></tbody>
            </table>
        </div>

        <div class="or-sec">二、關鍵人員綁定（表單簽章欄）</div>
        <div class="or-wrap">
            <table class="or-tbl">
                <thead><tr><th style="width:190px;">用途</th><th style="width:300px;">對應人員</th><th>說明</th></tr></thead>
                <tbody id="userBody"><tr><td colspan="3">載入中…</td></tr></tbody>
            </table>
        </div>
        <div style="font-size:11.5px;color:#8a6d45;">人員清單只列未離職者，依職稱排序並顯示部門與職稱（走共用的人員清單規則）。</div>

        <div class="or-sec">三、部門或人員擇一綁定（部門內任一主管皆可，或固定某關鍵人員）</div>
        <div class="or-wrap">
            <table class="or-tbl">
                <thead><tr><th style="width:170px;">用途</th><th style="width:260px;">對應部門（擇一）</th><th style="width:260px;">或對應人員（擇一）</th><th>說明</th></tr></thead>
                <tbody id="mixBody"><tr><td colspan="4">載入中…</td></tr></tbody>
            </table>
        </div>
        <div style="font-size:11.5px;color:#8a6d45;">兩欄只能擇一填；同時填了以「部門」為準。兩者都未設定時，各用途各自有其專屬的自動判斷規則（見說明欄）。</div>
<?php endif; ?>
    </div>
    <?php include '../partPage/footer.html' ?>
</div>
</div>

<div class="or-mask" id="helpUseMask"><div class="or-modal">
    <div class="m-head"><span>使用說明 — 組織角色綁定設定</span><span class="m-close" onclick="closeMask('helpUseMask')">✕</span></div>
    <div class="m-body help-doc">
        <h4>這一頁在做什麼</h4>
        很多表單需要知道「人事部門是哪一個部門」「核准欄要蓋誰的章」。過去每個頁面各自寫死，組織一調整就要一頁一頁改。
        本頁把這些綁定<b>集中在一處</b>，全站頁面一律讀這裡。
        <h4>操作步驟</h4>
        1. 在「部門綁定」把每個用途對應到實際部門（可留空＝未設定），並確認<b>含子部門</b>要不要勾。<br>
        2. 在「關鍵人員綁定」指定簽章人員；下拉上方有<b>篩選框</b>，直接打姓名即可過濾，不用在幾十個人裡找。<br>
        3. 按<b>儲存設定</b>。存檔後全站立即生效，不需要各頁另外設定。
        <h4>重要行為</h4>
        ・<b>含子部門（預設開啟）</b>：組織是樹狀的，綁「品管部」時預設連底下的<b>品管組</b>一起認列為品管部門；
        綁「資材部」則生管組／採購組／倉管組都算。只想認列該部門本身時才取消勾選。設定右側會列出實際會被一併認列的子部門。<br>
        ・<b>一個部門身兼多種職能時</b>（例：管理部同時管會計、人事、總務，底下沒再分組），
        「人事部門」和「會計部門」可以<b>同時綁到管理部</b>，這在系統裡沒有問題——部門綁定只回答「哪個部門負責」。
        但這樣兩者的部門主管會是<b>同一個人</b>；若人事表單和會計表單要蓋<b>不同人</b>的章，
        請到下方「關鍵人員綁定」直接指定該欄位的簽章人（人員綁定優先於部門主管推算）。<br>
        ・部門綁定右欄會顯示<b>目前該部門的部門主管</b>（職級最高者，同級優先取指定負責人；含子部門時會在整個子樹裡找職級最高者）——表單的「審核」欄多半就是這個人。<br>
        ・若該部門沒有設定職級（position_level）而抓不到主管，請改用「人事表單審核者」直接指定人員。<br>
        ・被綁定的人若離職，讀取時視同未設定（不會蓋到離職者的章），請回來重設。
        <h4>權限</h4>
        僅<b>系統管理者</b>可修改；其他人開啟只會看到權限提示。
    </div>
    <div class="m-foot"><button onclick="closeMask('helpUseMask')">關閉</button></div>
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
var API='../../src/store/OrgRole_API.php', ROLES={}, BIND={}, MGR={}, DEPTS=[], PEOPLE=[], SUBD={};
function esc(s){ return String(s==null?'':s).replace(/[&<>"']/g,function(c){
    return {'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#39;'}[c]; }); }
function closeMask(id){ document.getElementById(id).style.display='none'; }
$('#btnPageHelp').on('click', function(){ document.getElementById('helpUseMask').style.display='block'; });
$('.or-mask').on('click', function(e){ if (e.target===this) this.style.display='none'; });

function load(){
    $.getJSON(API, {action:'meta'}, function(res){
        if (!res.ok){ alert(res.error||'載入失敗'); return; }
        ROLES=res.roles; BIND=res.bindings||{}; MGR=res.managers||{}; DEPTS=res.departments||[];
        PEOPLE=res.people||[]; SUBD=res.sub_depts||{};
        render();
    });
}
function deptSel(key, cur, cls){
    // data-eg-filter：篩選框由共用檔 eg_input_rules.js 自動長出來（規則 7），本頁不自刻
    var h='<select data-key="'+key+'" class="'+(cls||'or-dept')+'" data-eg-filter="輸入部門名稱篩選…"><option value="">（未設定）</option>';
    DEPTS.forEach(function(d){
        var pad = new Array(Math.max(0,(parseInt(d.level,10)||1)-1)+1).join('　');   // 依組織階層縮排
        h+='<option value="'+d.id+'"'+(String(cur)===String(d.id)?' selected':'')+'>'+pad+esc(d.name)+'</option>';
    });
    return h+'</select>';
}
function userSel(key, cur, cls){
    var h='<select data-key="'+key+'" class="'+(cls||'or-user')+'" data-eg-filter="輸入人員姓名篩選…"><option value="">（未設定）</option>';
    PEOPLE.forEach(function(p){
        var label = p.user_cname + (p.position_name?'（'+p.position_name+'）':'') + (p.dept_name?'／'+p.dept_name:'')
                  + (p.leave_note?'　'+p.leave_note:'');
        h+='<option value="'+p.id+'"'+(String(cur)===String(p.id)?' selected':'')+'>'+esc(label)+'</option>';
    });
    return h+'</select>';
}
/** 該部門底下所有子部門的名稱（顯示「一併認列」哪些；與後端 eg_dept_subtree_ids 同一套遞迴規則） */
function subNames(deptId){
    if (!deptId) return [];
    var out=[], layer=[String(deptId)];
    while (layer.length){
        var next=[];
        DEPTS.forEach(function(d){
            if (layer.indexOf(String(d.parent_id))>=0){ out.push(d.name); next.push(String(d.id)); }
        });
        layer=next;
    }
    return out;
}
function render(){
    var dh='', uh='', mh='';
    $.each(ROLES, function(k, r){
        var b = BIND[k]||{};
        if (r.type==='dept'){
            var m = MGR[k], inc = (b.include_sub==null? 1 : parseInt(b.include_sub,10));
            var subs = subNames(b.dept_id);
            dh += '<tr><td><b>'+esc(r.label)+'</b></td><td>'+deptSel(k, b.dept_id||'')+'</td>'
               +  '<td style="text-align:center;"><label style="font-weight:normal;cursor:pointer;">'
               +  '<input type="checkbox" class="or-sub" data-key="'+k+'"'+(inc?' checked':'')+'> 含子部門</label>'
               +  '<div class="or-desc">'+(b.dept_id
                     ? (inc ? (subs.length?'一併認列：'+esc(subs.join('、')):'（此部門無子部門）') : '只認列該部門本身')
                     : '') + '</div></td>'
               +  '<td><div class="or-desc">'+esc(r.desc)+'</div>'
               +  '<div class="or-mgr">目前部門主管：'
               +  (m ? esc(m.user_cname)+(m.position_name?'（'+esc(m.position_name)+'）':'')
                     : '<span style="color:#DD5138;">抓不到（該部門無職級設定，請改用下方「人事表單審核者」指定）</span>')
               +  '</div></td></tr>';
        } else if (r.type==='user') {
            uh += '<tr><td><b>'+esc(r.label)+'</b></td><td>'+userSel(k, b.user_id||'')+'</td>'
               +  '<td class="or-desc">'+esc(r.desc)+'</td></tr>';
        } else if (r.type==='dept_or_user') {
            mh += '<tr><td><b>'+esc(r.label)+'</b></td>'
               +  '<td>'+deptSel(k, b.dept_id||'', 'or-mix-dept')+'</td>'
               +  '<td>'+userSel(k, b.user_id||'', 'or-mix-user')+'</td>'
               +  '<td class="or-desc">'+esc(r.desc)+'</td></tr>';
        }
    });
    $('#deptBody').html(dh||'<tr><td colspan="4">無資料</td></tr>');
    $('#userBody').html(uh||'<tr><td colspan="3">無資料</td></tr>');
    $('#mixBody').html(mh||'<tr><td colspan="4">無資料</td></tr>');
}
/* 改部門或切換「含子部門」時，右邊的認列說明即時跟著變（推導欄位鐵則：來源一改就重算） */
$(document).on('change', '.or-dept, .or-sub', function(){
    var $tr=$(this).closest('tr'), v=$tr.find('.or-dept').val(), subs=subNames(v);
    $tr.find('td:eq(2) .or-desc').text(!v ? ''
        : ($tr.find('.or-sub').prop('checked')
             ? (subs.length ? '一併認列：'+subs.join('、') : '（此部門無子部門）')
             : '只認列該部門本身'));
});
$('#btnSave').on('click', function(){
    var list=[];
    $('.or-dept').each(function(){
        var k=$(this).data('key');
        list.push({role_key:k, dept_id:this.value, user_id:'',
                   include_sub: $('.or-sub[data-key="'+k+'"]').prop('checked') ? 1 : 0});
    });
    $('.or-user').each(function(){ list.push({role_key:$(this).data('key'), dept_id:'', user_id:this.value}); });
    // 部門或人員擇一：兩個下拉共用同一個 role_key，合成同一筆(部門優先)，不能像上面兩段各自獨立push，
    // 否則後端依 role_key 覆寫時後寫的那段會蓋掉先寫的
    $('.or-mix-dept').each(function(){
        var k=$(this).data('key');
        var userVal = $('.or-mix-user[data-key="'+k+'"]').val();
        list.push({role_key:k, dept_id:this.value, user_id: this.value ? '' : userVal, include_sub:1});
    });
    $.post(API, {action:'save', bindings:JSON.stringify(list)}, function(res){
        if (!res.ok){ alert(res.error||'儲存失敗'); return; }
        BIND=res.bindings||{}; MGR=res.managers||{}; SUBD=res.sub_depts||{}; render();
        alert('已儲存，全站立即生效。');
    }, 'json').fail(function(x){ alert('儲存失敗：'+(x.responseJSON&&x.responseJSON.error||x.status)); });
});
<?php if ($isAdmin): ?>load();<?php endif; ?>
</script>
</body>
</html>
