<?php
/**
 * 分類鈴鐺設定（限系統管理者）
 * 主鈴鐺（公告／通知）固定存在；這裡管理它左側的「分類鈴鐺」：名稱、圖示、收哪些頁面的通知、收待辦或通知。
 * 只影響顯示，不改任何通知的對象與已讀狀態。
 */
session_start();
if (!isset($_SESSION['userName'])) {
    $_SESSION['lastpage'] = "../../views/admin/notice_bell_setting.php";
    header("Location:../../index.php");
    exit;
}
include_once '../../src/common/_config.php';
include_once '../../src/common/DBConnection.php';
include_once '../../src/common/notice_bell_lib.php';

$db = (new DBConnection())->getPDO();
$st = $db->prepare("SELECT id, user_cname, user_status FROM user WHERE user_uname=?");
$st->execute([$_SESSION['userName']]);
$me = $st->fetch(PDO::FETCH_ASSOC) ?: [];
$isAdmin = in_array((int)($me['user_status'] ?? 0), [9, 90], true);
if (!$isAdmin && !empty($me['id'])) {
    $q = $db->prepare("SELECT 1 FROM user_roles ur JOIN roles r ON r.role_id=ur.role_id WHERE ur.user_id=? AND r.role_code='admin' AND r.is_system=1 LIMIT 1");
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
    <title>分類鈴鐺設定</title>
    <link href="../../resource/css/bootstrap.css" rel="stylesheet">
    <link href="../../resource/css/font-awesome.css" rel="stylesheet">
    <link href="../../resource/css/nprogress.css" rel="stylesheet">
    <link href="../../resource/css/custom.css" rel="stylesheet">
    <style>
        #sidebar-menu { visibility: hidden; }
        .right_col .page-title { margin:8px 0 4px; overflow:hidden; }
        .nb-bar { display:flex; flex-wrap:wrap; gap:6px; align-items:center; clear:both;
            border:1.5px solid #E8D5B5; border-radius:8px; padding:8px 10px; margin-bottom:10px; background:#FDF8EF; }
        .nb-btn { height:30px; font-size:13px; padding:0 14px; border:1px solid #d98a33; border-radius:4px; background:#F0A24B; color:#fff; cursor:pointer; }
        .nb-btn:hover { background:#d98a33; }
        .nb-btn.o { background:#fff; color:#8A5A2B; border-color:#D8BE93; }
        .nb-btn.o:hover { background:#F7E0BD; }
        .nb-btn.sm { height:26px; padding:0 9px; font-size:12px; }
        .nb-btn.dn { background:#fff; color:#DD5138; border-color:#DD5138; }
        .nb-wrap { border:1px solid #E8D5B5; border-radius:6px; background:#fff; overflow-x:auto; margin-bottom:14px; }
        table.nb-tbl { width:100%; border-collapse:collapse; font-size:13px; }
        table.nb-tbl th, table.nb-tbl td { border:1px solid #EADFC8; padding:7px 9px; text-align:left; vertical-align:middle; }
        table.nb-tbl thead th { background:#F7E0BD; color:#5b3a1e; }
        table.nb-tbl tbody tr.fixed { background:#FDF8EF; }
        .nb-ico { display:inline-flex; width:34px; height:34px; align-items:center; justify-content:center; border-radius:50%;
            background:#F7E0BD; color:#8A5A2B; font-size:17px; }
        .nb-tag { display:inline-block; font-size:11.5px; padding:1px 8px; border-radius:10px; background:#F7E0BD; color:#5b3a1e; margin:1px 3px 1px 0; }
        .nb-tag.k { background:#F0A24B; color:#fff; }
        .nb-lock { color:#8A5A2B; font-size:12px; }
        .nb-sw { cursor:pointer; }
        .nb-off { opacity:.5; }
        .nb-mask { display:none; position:fixed; inset:0; background:rgba(60,40,20,.45); z-index:1050; }
        .nb-modal { background:#fff; border-radius:8px; max-width:760px; margin:36px auto; box-shadow:0 5px 25px rgba(0,0,0,.3);
            max-height:90vh; display:flex; flex-direction:column; }
        .nb-modal .m-head { background:#F7E0BD; color:#5b3a1e; font-weight:bold; padding:10px 15px; border-radius:8px 8px 0 0; display:flex; justify-content:space-between; }
        .nb-modal .m-head .m-close { cursor:pointer; color:#b5762a; }
        .nb-modal .m-body { padding:15px; overflow-y:auto; font-size:13px; color:#5b3a1e; line-height:1.7; }
        .nb-modal .m-foot { padding:10px 15px; border-top:1px solid #EADFC8; text-align:right; display:flex; gap:8px; justify-content:flex-end; align-items:center; }
        .nb-fld { margin-bottom:12px; }
        .nb-fld > label.t { display:block; font-weight:bold; color:#8A5A2B; margin-bottom:3px; }
        .nb-fld input[type=text] { width:100%; max-width:320px; border:1px solid #D8BE93; border-radius:4px; padding:5px 8px; font-size:13px; }
        .nb-err { color:#DD5138; font-size:12px; min-height:16px; }
        .nb-icons { display:grid; grid-template-columns:repeat(10, 1fr); gap:6px; max-width:560px; }
        .nb-icons button { height:38px; border:1.5px solid #E8D5B5; border-radius:6px; background:#fff; color:#8A5A2B; font-size:17px; cursor:pointer; }
        .nb-icons button:hover { background:#FDF8EF; }
        .nb-icons button.on { background:#F0A24B; color:#fff; border-color:#d98a33; }
        .nb-src { display:grid; grid-template-columns:1fr 1fr; gap:2px 14px; }
        .nb-src label { font-weight:normal; display:flex; gap:6px; align-items:flex-start; margin:2px 0; cursor:pointer; }
        .nb-src .cnt { color:#8a6d45; font-size:11.5px; }
        .nb-prev { display:inline-flex; align-items:center; gap:8px; border:1px dashed #D8BE93; border-radius:8px; padding:6px 12px; background:#FDF8EF; }
        .nb-prev .bell { position:relative; font-size:19px; color:#8A5A2B; }
        .nb-prev .bd { position:absolute; top:-6px; right:-9px; min-width:16px; height:16px; line-height:16px; font-size:10.5px; text-align:center; color:#fff; background:#e74c3c; border-radius:8px; padding:0 3px; }
        .page-help-btn { margin-left:auto; height:30px; font-size:13px; padding:0 14px; border:1px solid #D8BE93; border-radius:4px; background:#fff; color:#5b3a1e; cursor:pointer; }
        .page-help-btn:hover { background:#F7E0BD; }
        .help-doc h4 { font-size:14px; color:#8A5A2B; margin:10px 0 4px; }
        .nb-noperm { margin:40px auto; max-width:520px; text-align:center; border:1.5px solid #E8D5B5; border-radius:10px; padding:30px; background:#FDF8EF; color:#5b3a1e; }
        @media (max-width:700px) { .nb-src { grid-template-columns:1fr; } .nb-icons { grid-template-columns:repeat(6,1fr); } }
        @media print { .nb-bar, .page-help-btn, .nav_menu, .left_col, footer { display:none !important; } }
    </style>
</head>
<body class="nav-sm">
<div class="container body">
<div class="main_container">
    <?php include '../partPage/sideAndTopBarMenu.html' ?>
    <div class="right_col" role="main" style="clear:both;">
        <div class="page-title" style="display:flex;align-items:center;flex-wrap:wrap;clear:both;">
            <h2 style="margin:6px 0;">分類鈴鐺設定
                <small style="color:#8a6d45;">在主鈴鐺左側加分類鈴鐺，把不同頁面的通知／待辦分開收</small></h2>
            <button class="page-help-btn" id="btnPageHelp"><i class="fa fa-question-circle"></i> 使用說明</button>
        </div>
        <div class="clearfix"></div>
<?php if (!$isAdmin): ?>
        <div class="nb-noperm"><h4><i class="fa fa-lock"></i> 僅系統管理者可設定</h4>
            <p>本頁設定會改變全公司置頂列的鈴鐺顯示，請洽系統管理者。</p></div>
<?php else: ?>
        <div class="nb-bar">
            <span style="font-size:12px;color:#8a6d45;">只改變「鈴鐺怎麼分類顯示」；通知的對象、已讀、回覆、回簽都不受影響，主鈴鐺永遠收全部通知。</span>
            <button class="nb-btn" id="btnAdd"><i class="fa fa-plus"></i> 新增分類鈴鐺</button>
        </div>
        <div class="nb-wrap">
            <table class="nb-tbl">
                <thead><tr><th style="width:96px;">順序（左→右）</th><th style="width:56px;">圖示</th><th style="width:150px;">名稱</th>
                    <th>收哪些通知</th><th style="width:70px;">啟用</th><th style="width:150px;">操作</th></tr></thead>
                <tbody id="catBody"><tr><td colspan="6">載入中…</td></tr></tbody>
            </table>
        </div>
<?php endif; ?>
    </div>
    <?php include '../partPage/footer.html' ?>
</div>
</div>

<div class="nb-mask" id="editMask"><div class="nb-modal">
    <div class="m-head"><span id="editTitle">新增分類鈴鐺</span><span class="m-close" onclick="closeMask('editMask')">✕</span></div>
    <div class="m-body">
        <div class="nb-fld"><label class="t">名稱（最多 12 字）</label>
            <input type="text" id="fName" maxlength="12" data-eg-hint="例如：BOM／品質">
            <div class="nb-err" id="eName"></div></div>
        <div class="nb-fld"><label class="t">圖示</label>
            <div class="nb-icons" id="iconGrid"></div>
            <div class="nb-err" id="eIcon"></div></div>
        <div class="nb-fld"><label class="t">收哪一類</label>
            <label style="font-weight:normal;margin-right:14px;"><input type="radio" name="fKind" value="all"> 全部</label>
            <label style="font-weight:normal;margin-right:14px;"><input type="radio" name="fKind" value="todo"> 只看待辦（要回覆／回簽）</label>
            <label style="font-weight:normal;"><input type="radio" name="fKind" value="info"> 只看通知（讀了就好）</label></div>
        <div class="nb-fld"><label class="t">來源頁面（可複選；一個都不勾＝不限頁面，只依上面的類型篩）</label>
            <div class="nb-src" id="srcGrid"></div>
            <details style="margin-top:8px;"><summary style="cursor:pointer;color:#8A5A2B;">進階：指定個別通知代碼</summary>
                <div class="nb-src" id="rawGrid" style="margin-top:6px;"></div></details>
            <div class="nb-err" id="eSrc"></div></div>
        <div class="nb-fld"><label class="t">啟用</label>
            <label style="font-weight:normal;"><input type="checkbox" id="fActive" checked> 顯示在置頂列</label></div>
        <div class="nb-fld"><label class="t">預覽</label>
            <div class="nb-prev"><span class="bell"><i class="fa fa-bell-o" id="pvIcon"></i><span class="bd">3</span></span><b id="pvName">名稱</b></div></div>
    </div>
    <div class="m-foot"><span class="nb-err" id="eSave" style="margin-right:auto;"></span>
        <button class="nb-btn o" onclick="closeMask('editMask')">取消</button><button class="nb-btn" id="btnSave"><i class="fa fa-save"></i> 儲存</button></div>
</div></div>

<div class="nb-mask" id="helpUseMask"><div class="nb-modal">
    <div class="m-head"><span>使用說明 — 分類鈴鐺設定</span><span class="m-close" onclick="closeMask('helpUseMask')">✕</span></div>
    <div class="m-body help-doc">
        <h4>這一頁在做什麼</h4>
        通知一多，所有東西擠在同一顆鈴鐺裡，真正要處理的事容易被淹沒。這裡可以在<b>主鈴鐺左側</b>加上「分類鈴鐺」，例如「BOM／品質」「簽核待辦」，每顆各自顯示未讀數。
        <h4>設定方式</h4>
        ・<b>新增分類鈴鐺</b>：取名、選圖示，再選「收哪一類」與「來源頁面」。<br>
        ・<b>收哪一類</b>：全部／只看待辦（需要回覆或回簽的）／只看通知（讀了就好）。<br>
        ・<b>來源頁面</b>：依通知是從哪一頁發出來的分組（品質異常處理單、報價單、會議紀錄…），可複選；也可在「進階」指定個別通知代碼。後面的數字是近 90 天的通知量。<br>
        ・<b>順序</b>用上下箭頭調整，顯示時由左到右；<b>停用</b>＝暫時不顯示但保留設定。
        <h4>重要行為</h4>
        ・<b>主鈴鐺不可移除</b>，永遠在最右側收全部通知；分類鈴鐺只是同一批通知的分類檢視。<br>
        ・點分類鈴鐺裡的通知，行為和主鈴鐺完全一樣；已讀、回覆、回簽後，主鈴鐺與分類鈴鐺的數字一起更新。<br>
        ・同一則通知可以同時出現在好幾顆分類鈴鐺（只要符合各自的條件）。<br>
        ・分類鈴鐺最多 8 個。設定存檔後，使用者換頁或重新整理即生效。
        <h4>權限</h4>
        僅<b>系統管理者</b>可修改。
    </div>
    <div class="m-foot"><button class="nb-btn" onclick="closeMask('helpUseMask')">關閉</button></div>
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
var API='../../src/store/NoticeBell_API.php', CATS=[], GROUPS=[], RAW=[], ICONS=[], CSRF='', MAXN=8, EDIT=null, PICK='';
function esc(s){ return String(s==null?'':s).replace(/[&<>"']/g,function(c){ return {'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#39;'}[c]; }); }
function closeMask(id){ document.getElementById(id).style.display='none'; }
function openMask(id){ document.getElementById(id).style.display='block'; }
$('#btnPageHelp').on('click', function(){ openMask('helpUseMask'); });
$('.nb-mask').on('click', function(e){ if (e.target===this) this.style.display='none'; });
var KIND={all:'全部',todo:'只看待辦',info:'只看通知'};
function labelOf(k){ var g=GROUPS.filter(function(x){return x.key===k;})[0]; return g ? g.label.replace(/（.*$/,'') : k; }
function load(){
    $.get(API, {action:'list'}, function(res){
        if (!res.ok){ $('#catBody').html('<tr><td colspan="6">'+esc(res.error||'載入失敗')+'</td></tr>'); return; }
        CATS=res.cats||[]; GROUPS=res.groups||[]; RAW=res.raw||[]; ICONS=res.icons||[]; CSRF=res.csrf; MAXN=res.max||8;
        render();
    }, 'json').fail(function(x){ $('#catBody').html('<tr><td colspan="6">載入失敗：'+esc(x.responseJSON&&x.responseJSON.error||x.status)+'</td></tr>'); });
}
function render(){
    var h='<tr class="fixed"><td class="nb-lock" colspan="1"><i class="fa fa-lock"></i> 固定</td><td><span class="nb-ico"><i class="fa fa-bell-o"></i></span></td>'
        +'<td><b>公告／通知</b><br><span class="nb-lock">主鈴鐺</span></td><td><span class="nb-tag k">全部</span> 所有通知（不論來源與類型）</td>'
        +'<td><span class="nb-lock">永遠顯示</span></td><td><span class="nb-lock">不可移除或停用</span></td></tr>';
    CATS.forEach(function(c,i){
        var tags='<span class="nb-tag k">'+esc(KIND[c.kind]||c.kind)+'</span>';
        if (c.src.length) c.src.forEach(function(s){ tags+='<span class="nb-tag">'+esc(labelOf(s))+'</span>'; }); else tags+='<span class="nb-tag">不限頁面</span>';
        h+='<tr class="'+(c.active?'':'nb-off')+'"><td>'
          +'<button class="nb-btn o sm" data-mv="up" data-id="'+c.id+'" '+(i===0?'disabled':'')+'><i class="fa fa-arrow-left"></i></button> '
          +'<button class="nb-btn o sm" data-mv="down" data-id="'+c.id+'" '+(i===CATS.length-1?'disabled':'')+'><i class="fa fa-arrow-right"></i></button></td>'
          +'<td><span class="nb-ico"><i class="fa '+esc(c.icon)+'"></i></span></td><td><b>'+esc(c.name)+'</b></td><td>'+tags+'</td>'
          +'<td><label class="nb-sw"><input type="checkbox" class="nb-act" data-id="'+c.id+'" '+(c.active?'checked':'')+'> 啟用</label></td>'
          +'<td><button class="nb-btn o sm" data-edit="'+c.id+'"><i class="fa fa-pencil"></i> 編輯</button> '
          +'<button class="nb-btn dn sm" data-del="'+c.id+'"><i class="fa fa-trash-o"></i> 刪除</button></td></tr>';
    });
    if (!CATS.length) h+='<tr><td colspan="6" style="color:#8a6d45;">還沒有分類鈴鐺。按右上「新增分類鈴鐺」建立第一顆。</td></tr>';
    $('#catBody').html(h);
}
function post(a, d, cb, errCb){ d=d||{}; d.action=a; d.csrf=CSRF;
    $.post(API, d, function(res){ if(!res.ok){ (errCb||alert)(res.error||'失敗'); return; } cb&&cb(res); }, 'json')
     .fail(function(x){ (errCb||alert)(x.responseJSON&&x.responseJSON.error||('連線失敗 '+x.status)); }); }
$(document).on('click','[data-mv]',function(){ post('move',{id:$(this).data('id'),dir:$(this).data('mv')},load); });
$(document).on('change','.nb-act',function(){ post('toggle',{id:$(this).data('id'),active:this.checked?1:0},load); });
$(document).on('click','[data-del]',function(){
    var c=CATS.filter(function(x){return x.id==$(this).data('del');}.bind(this))[0]; if(!c) return;
    if (confirm('確定刪除分類鈴鐺「'+c.name+'」？\n只會移除這顆分類鈴鐺，通知本身與主鈴鐺不受影響。')) post('delete',{id:c.id},load);
});
function pvUpdate(){ $('#pvIcon').attr('class','fa '+(PICK||'fa-bell-o')); $('#pvName').text($('#fName').val()||'名稱'); }
function buildForm(c){
    EDIT=c; PICK=c?c.icon:'';
    $('#editTitle').text(c?'編輯分類鈴鐺':'新增分類鈴鐺');
    $('#fName').val(c?c.name:''); $('#fActive').prop('checked', c?!!c.active:true);
    $('input[name=fKind][value='+(c?c.kind:'todo')+']').prop('checked',true);
    $('#iconGrid').html(ICONS.map(function(i){ return '<button type="button" data-icon="'+esc(i)+'" class="'+(i===PICK?'on':'')+'" title="'+esc(i)+'"><i class="fa '+esc(i)+'"></i></button>'; }).join(''));
    var sel=c?c.src:[];
    $('#srcGrid').html(GROUPS.map(function(g){ return '<label><input type="checkbox" class="nb-src-cb" value="'+esc(g.key)+'" '+(sel.indexOf(g.key)>=0?'checked':'')+'>'
        +'<span>'+esc(g.label)+' <span class="cnt">近90天 '+g.count90+' 則</span></span></label>'; }).join(''));
    $('#rawGrid').html(RAW.map(function(r){ return '<label><input type="checkbox" class="nb-src-cb" value="'+esc(r.key)+'" '+(sel.indexOf(r.key)>=0?'checked':'')+'>'
        +'<span>'+esc(r.key)+' <span class="cnt">'+r.count90+' 則</span></span></label>'; }).join('') || '<span class="cnt">目前沒有通知代碼</span>');
    $('.nb-err').text(''); pvUpdate(); openMask('editMask');
}
$('#btnAdd').on('click', function(){
    if (CATS.length >= MAXN){ alert('分類鈴鐺最多 '+MAXN+' 個，請先刪除不用的。'); return; }
    buildForm(null);
});
$(document).on('click','[data-edit]',function(){ var id=$(this).data('edit'); buildForm(CATS.filter(function(x){return x.id==id;})[0]); });
$(document).on('click','#iconGrid button',function(){ PICK=$(this).data('icon'); $('#iconGrid button').removeClass('on'); $(this).addClass('on'); $('#eIcon').text(''); pvUpdate(); });
$('#fName').on('input', function(){ pvUpdate(); $('#eName').text(this.value.trim()?'':'請輸入分類名稱'); });
function checkForm(){
    var ok=true, name=$('#fName').val().trim();
    $('#eName').text(name?'':'請輸入分類名稱'); if(!name) ok=false;
    $('#eIcon').text(PICK?'':'請選一個圖示'); if(!PICK) ok=false;
    var kind=$('input[name=fKind]:checked').val(), n=$('.nb-src-cb:checked').length;
    var bad=(kind==='all' && n===0);
    $('#eSrc').text(bad?'類型是「全部」時請至少勾一個來源（否則和主鈴鐺一模一樣）；或把類型改成只看待辦／只看通知':''); if(bad) ok=false;
    return ok;
}
$(document).on('change','.nb-src-cb, input[name=fKind]', checkForm);
$('#btnSave').on('click', function(){
    if(!checkForm()) return;
    var src=$('.nb-src-cb:checked').map(function(){return this.value;}).get();
    post('save',{id:EDIT?EDIT.id:0,name:$('#fName').val().trim(),icon:PICK,kind:$('input[name=fKind]:checked').val(),
        src:JSON.stringify(src),active:$('#fActive').prop('checked')?1:0}, function(){ closeMask('editMask'); load(); },
        function(m){ $('#eSave').text(m); });
});
<?php if ($isAdmin): ?>load();<?php endif; ?>
</script>
</body>
</html>
