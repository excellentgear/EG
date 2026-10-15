<?php
/**
 * 教育訓練「現場簽到」獨立頁（講師從通知點進來用）
 * 不登記進選單、不需要教育訓練模組角色：限「本場講師本人」或「具教育訓練登錄權限者」開啟。
 * 資料一律走 Training_API.php：checkin_meta（名單）／sign_attendee（學員輸入本人密碼簽到）／checkin_finish（簽到完畢）。
 */
session_start();
$sid = (int)($_GET['session_id'] ?? 0);
if (!isset($_SESSION['userName'])) {
    $_SESSION['lastpage'] = "../../views/ADM/training_checkin.php?session_id=" . $sid;
    header("Location:../../index.php");
    exit;
}
include_once '../../src/common/_config.php';
include_once '../../src/common/DBConnection.php';
include_once '../../src/common/training_lib.php';
$db = (new DBConnection())->getPDO();
training_ensure_schema($db);
$u = training_current_user($db);
$ok = $u && $sid > 0 && training_checkin_can($db, $u, $sid);
$av = @filemtime(__DIR__ . '/../../resource/js/eg_date_fmt.js');
?>
<!DOCTYPE html>
<html lang="zh-Hant">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>教育訓練現場簽到</title>
<style>
body{font-family:"Microsoft JhengHei",sans-serif;background:#FBF3E4;color:#5b3a1e;margin:0;padding:16px;}
.box{max-width:760px;margin:0 auto;background:#fff;border:1px solid #E3C79A;border-radius:8px;padding:16px;}
h2{margin:0 0 8px;font-size:18px;}
table{width:100%;border-collapse:collapse;margin-top:8px;}
th,td{border:1px solid #E3C79A;padding:8px 6px;text-align:center;font-size:14px;}
th{background:#F7E0BD;}
input[type=password]{width:100%;box-sizing:border-box;padding:6px;font-size:16px;border:1px solid #C9A16A;border-radius:4px;}
.ok{color:#5E7C3A;font-weight:bold;}
button{background:#DD7F2B;color:#fff;border:0;border-radius:4px;padding:8px 18px;font-size:15px;cursor:pointer;}
button.sub{background:#fff;color:#8A5A2B;border:1px solid #C9A16A;}
.hint{font-size:12px;color:#8a6d45;margin-top:8px;line-height:1.6;}
.err{color:#DD5138;}
</style>
</head>
<body>
<div class="box">
<?php if (!$ok): ?>
    <h2>教育訓練現場簽到</h2>
    <p class="err">您不是這堂課的講師，也沒有教育訓練登錄權限，無法開啟簽到頁。</p>
<?php else: ?>
    <h2>教育訓練現場簽到</h2>
    <div id="info">載入中…</div>
    <div id="dayBox" style="display:none;margin-top:6px;">上課日期　<select id="daySel"></select>
        <span class="hint">多天課程一天要簽一次</span></div>
    <table><thead><tr><th>部門</th><th>職稱</th><th>姓名</th><th style="width:210px;">簽到</th></tr></thead><tbody id="body"></tbody></table>
    <div class="hint">請學員在這台裝置上找到自己的姓名，輸入<b>本人登入密碼</b>後按 Enter 簽到（密碼只用來確認是本人，不會被記錄）。<br>
        簽到結果會即時記入這門課程的參加名單（簽到＋實到）。全部簽完請按下方「簽到完畢」，通知才會消失。</div>
    <div style="margin-top:12px;display:flex;gap:8px;">
        <button id="btnFinish" type="button">簽到完畢</button>
        <button class="sub" type="button" onclick="window.close()">先關閉（通知保留）</button>
    </div>
<?php endif; ?>
</div>
<?php if ($ok): ?>
<script src="../../resource/js/jquery.min.js"></script>
<script src="../../resource/js/eg_date_fmt.js?v=<?= (int)$av ?>"></script>
<script>
var API='../../src/store/Training_API.php', SID=<?= (int)$sid ?>, META=null, SIGNS={}, DAY='';
function esc(s){ return String(s==null?'':s).replace(/[&<>"']/g,function(c){return {'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#39;'}[c];}); }
function dd(d){ return (typeof egFmtDate==='function') ? egFmtDate(d) : d; }
function load(){
    $.getJSON(API,{action:'checkin_meta',session_id:SID},function(res){
        if(!res.ok){ $('#info').html('<span class="err">'+esc(res.error||'載入失敗')+'</span>'); return; }
        META=res; SIGNS={};
        (res.day_signs||[]).forEach(function(r){ SIGNS[r.user_id+'_'+String(r.day_date).substr(0,10)]=r.signed_at; });
        $('#info').html('<b>'+esc(res.session.course_name)+'</b>　'+res.session.year+' 年 '+res.session.plan_month+' 月');
        var days=res.days||[], n=new Date(), t=n.getFullYear()+'-'+('0'+(n.getMonth()+1)).slice(-2)+'-'+('0'+n.getDate()).slice(-2);
        var cur=days.filter(function(d){return d.day_date===t;})[0];
        DAY = cur?cur.day_date:(days[0]?days[0].day_date:'');
        var seen={}, uniq=days.filter(function(d){ if(seen[d.day_date]) return false; seen[d.day_date]=1; return true; });
        if(uniq.length>1){
            var h=''; uniq.forEach(function(d){ h+='<option value="'+d.day_date+'"'+(d.day_date===DAY?' selected':'')+'>'+dd(d.day_date)+'</option>'; });
            $('#daySel').html(h); $('#dayBox').show();
        }
        render();
    }).fail(function(x){ $('#info').html('<span class="err">'+esc(x.responseJSON&&x.responseJSON.error||'載入失敗')+'</span>'); });
}
function render(){
    var h='';
    (META.attendees||[]).forEach(function(a){
        var s=SIGNS[a.user_id+'_'+DAY];
        h+='<tr data-uid="'+a.user_id+'"><td>'+esc(a.dept_name||'')+'</td><td>'+esc(a.position_name||'')+'</td><td>'+esc(a.user_name||'')+'</td><td>'
          +(s?'<span class="ok">✓ 已簽到</span>':'<input type="password" id="pw'+a.user_id+'" placeholder="本人密碼，按 Enter 簽到" autocomplete="new-password">')+'</td></tr>';
    });
    $('#body').html(h||'<tr><td colspan="4">尚無名單</td></tr>');
}
$(document).on('change','#daySel',function(){ DAY=$(this).val(); render(); });
$(document).on('keydown','input[type=password]',function(e){
    if(e.key!=='Enter') return; e.preventDefault();
    var uid=$(this).closest('tr').data('uid'), pw=$(this).val();
    if(!pw){ alert('請輸入密碼'); return; }
    var $in=$(this);
    $.post(API,{action:'sign_attendee',session_id:SID,user_id:uid,day_date:DAY,password:pw},function(res){
        if(!res.ok){ alert(res.error||'簽到失敗'); $in.val('').focus(); return; }
        SIGNS[uid+'_'+DAY]=1; render();
        var nx=(META.attendees||[]).filter(function(a){return !SIGNS[a.user_id+'_'+DAY];})[0];
        if(nx) $('#pw'+nx.user_id).focus(); else alert('本日全員已簽到');
    },'json').fail(function(x){ alert(x.responseJSON&&x.responseJSON.error||'簽到失敗'); $in.val('').focus(); });
});
$('#btnFinish').on('click',function(){
    var un=(META&&META.attendees||[]).filter(function(a){return !SIGNS[a.user_id+'_'+DAY];}).length;
    if(!confirm((un?'本日還有 '+un+' 人未簽到（視為未到）。\n':'')+'確定結束簽到？結束後此通知會消失。')) return;
    $.post(API,{action:'checkin_finish',session_id:SID},function(res){
        if(!res.ok){ alert(res.error||'失敗'); return; }
        alert('簽到完畢：已簽到 '+res.signed+' / '+res.total+' 人，結果已記入課程。'); window.close();
    },'json').fail(function(x){ alert(x.responseJSON&&x.responseJSON.error||'失敗'); });
});
load();
</script>
<?php endif; ?>
</body>
</html>
