<?php
/**
 * 教育訓練需求申請單（2-MM-01-05）— 審核檢視頁（通知點進來的落地頁）
 * 依 ai-rules/17：通知要看得到完整內容、有核准/退回、退回必填原因。
 * 核准人＝申請單位的部門主管，多半**沒有**教育訓練模組的任何角色，故本頁獨立於 training_perms() 的 canView 之外，
 * 只要登入、且是被指派的核准人（或系統管理者）即可決行；其他人可檢視內容但看不到決行按鈕。
 * 進入方式：live_event ref_type='TRAINING_REQUEST_APPROVAL' → ?event=live_event.id（或 ?request_id=）
 */
session_start();
if (!isset($_SESSION['userName'])) {
    $_SESSION['lastpage'] = "../../views/ADM/training_request_approval_view.php" . (isset($_GET['request_id']) ? "?request_id=".(int)$_GET['request_id'] : '');
    header("Location:../../index.php");
    exit;
}
include_once '../../src/common/_config.php';
include_once '../../src/common/DBConnection.php';
include_once '../../src/common/training_lib.php';

$db = (new DBConnection())->getPDO();
training_ensure_schema($db);
$trUser = training_current_user($db);
if (!$trUser) { header("Location:../../index.php"); exit; }
$uid = (int)$trUser['id'];

$rid = (int)($_GET['request_id'] ?? 0);
if (!$rid && isset($_GET['event'])) {
    $st = $db->prepare("SELECT ref_id FROM live_event WHERE id=? AND ref_type LIKE 'TRAINING_REQUEST%'");
    $st->execute([(int)$_GET['event']]);
    $rid = (int)$st->fetchColumn();
}
$deptMap = [];
foreach ($db->query("SELECT id, name FROM department")->fetchAll(PDO::FETCH_ASSOC) as $d) $deptMap[(int)$d['id']] = $d['name'];
$st = $db->prepare("SELECT * FROM training_request WHERE request_id=?");
$st->execute([$rid]);
$req = $st->fetch(PDO::FETCH_ASSOC);
if (!$req) { echo '<div style="padding:40px;font-family:sans-serif;">找不到此申請單（可能已被刪除）。</div>'; exit; }
$appr = eg_approval_latest($db, 'training_request', $rid, 'manager');

$isAdmin = training_perms($db, $trUser)['isAdmin'];
$signer  = training_request_signer($db, $req['dept_id'] !== null ? (int)$req['dept_id'] : null, (int)$req['user_id']);
$canSign = $req['status'] === 'submitted' && $appr && $appr['status'] === 'pending' && ($isAdmin || ($signer && (int)$signer['id'] === $uid));
$company = eg_company_full_name($db);
$docNo   = training_as_doc_no($db, 'request', $req['apply_date'] ?: null);
$statusLabel = ['draft'=>'草稿','submitted'=>'待核准','approved'=>'已核准','rejected'=>'已駁回','converted'=>'已核准（已轉為訓練計畫）'];
function trv_esc($s){ return htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8'); }
?>
<!DOCTYPE html>
<html lang="zh-Hant">
<head>
    <meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1">
    <title>教育訓練需求申請單 — 審核</title>
    <link href="../../resource/css/bootstrap.css" rel="stylesheet">
    <link href="../../resource/css/font-awesome.css" rel="stylesheet">
    <link href="../../resource/css/nprogress.css" rel="stylesheet">
    <link href="../../resource/css/custom.css" rel="stylesheet">
    <style>
        #sidebar-menu { visibility: hidden; }
        .right_col .page-title { margin:8px 0 4px; overflow:hidden; clear:both; }
        .ap-bar { display:flex; flex-wrap:wrap; gap:8px; align-items:center; clear:both;
            border:1.5px solid #E8D5B5; border-radius:8px; padding:9px 12px; margin-bottom:10px; background:#FDF8EF; }
        .ap-bar button { height:32px; font-size:13px; padding:0 18px; border-radius:4px; cursor:pointer; border:1px solid #d98a33; }
        .b-ok { background:#F0A24B; color:#fff; } .b-no { background:#fff; color:#DD5138; border-color:#DD5138 !important; }
        .rq-wrap { border:1px solid #E8D5B5; border-radius:6px; background:#fff; padding:14px; margin-bottom:12px; }
        .rq-kv { display:grid; grid-template-columns:120px 1fr; gap:6px 10px; font-size:13px; color:#5b3a1e; line-height:1.8; }
        .rq-kv b { color:#8A5A2B; }
        .ap-note { border:1px dashed #E8D5B5; background:#FDF8EF; border-radius:6px; padding:8px 10px; font-size:12.5px; color:#5b3a1e; }
        .ap-mask { display:none; position:fixed; inset:0; background:rgba(60,40,20,.45); z-index:1050; }
        .ap-modal { background:#fff; border-radius:8px; max-width:520px; margin:60px auto; box-shadow:0 5px 25px rgba(0,0,0,.3); }
        .ap-modal .m-head { background:#F7E0BD; color:#5b3a1e; font-weight:bold; padding:10px 15px; border-radius:8px 8px 0 0; display:flex; justify-content:space-between; }
        .ap-modal .m-body { padding:15px; font-size:13px; color:#5b3a1e; line-height:1.8; }
        .ap-modal .m-body textarea { width:100%; border:1px solid #D8BE93; border-radius:4px; padding:6px 8px; font-size:13px; }
        .ap-modal .m-foot { padding:10px 15px; border-top:1px solid #EADFC8; text-align:right; }
        .ap-modal .m-foot button { height:30px; padding:0 16px; border-radius:4px; font-size:13px; border:1px solid #d98a33; cursor:pointer; }
        @media print { .ap-bar, .nav_menu, .left_col, footer { display:none !important; } }
    </style>
</head>
<body class="nav-sm">
<div class="container body"><div class="main_container">
    <?php include '../partPage/sideAndTopBarMenu.html' ?>
    <div class="right_col" role="main">
        <div class="page-title"><h2 style="margin:6px 0;">教育訓練需求申請單 — 審核
            <small style="color:#8a6d45;"><?= trv_esc($company) ?><?= $docNo ? '　'.trv_esc($docNo) : '' ?></small></h2></div>
        <div class="clearfix"></div>

        <div class="ap-bar">
            <span>目前狀態：<b><?= trv_esc($statusLabel[$req['status']] ?? $req['status']) ?></b></span>
            <span style="font-size:12px;color:#8a6d45;">核准人：<?= trv_esc($signer['name'] ?? '（找不到部門主管，將自動核准）') ?></span>
            <?php if ($canSign): ?>
                <button class="b-ok" id="btnAppr"><i class="fa fa-check"></i> 核准</button>
                <button class="b-no" id="btnRej"><i class="fa fa-undo"></i> 退回</button>
            <?php elseif ($req['status']==='submitted'): ?>
                <span style="color:#DD5138;font-size:12px;">此申請單由 <?= trv_esc($signer['name'] ?? '（未設定）') ?> 核准，您無法決行</span>
            <?php endif; ?>
            <a href="training_record.php" style="margin-left:auto;color:#b5762a;">回教育訓練管理</a>
        </div>

        <?php if ($appr && $appr['status']==='rejected' && $appr['note']): ?>
        <div class="ap-note" style="margin-bottom:10px;">退回原因（<?= trv_esc($appr['approver_name']) ?>）：<?= trv_esc($appr['note']) ?></div>
        <?php endif; ?>

        <div class="rq-wrap"><div class="rq-kv">
            <b>申請單位</b><span><?= trv_esc($req['dept_id']!==null ? ($deptMap[(int)$req['dept_id']] ?? '') : '') ?></span>
            <b>申請人</b><span><?= trv_esc($req['user_name']) ?>　<b>申請日期</b> <?= trv_esc($req['apply_date']) ?></span>
            <b>主旨</b><span><?= trv_esc($req['subject']) ?></span>
            <b>簡述內容</b><span style="white-space:pre-wrap;"><?= trv_esc($req['content']) ?></span>
            <b>主管要求學習重點</b><span style="white-space:pre-wrap;"><?= trv_esc($req['focus']) ?></span>
            <b>受訓人員</b><span><?= trv_esc($req['trainees']) ?></span>
            <b>受訓時間</b><span><?= trv_esc($req['start_date']) ?> ~ <?= trv_esc($req['end_date']) ?>（共 <?= (int)$req['days'] ?> 天，<?= $req['hours']!==null?trv_esc($req['hours']):'—' ?> 小時）</span>
            <b>受訓地點</b><span><?= trv_esc($req['location']) ?></span>
            <b>受訓費用</b><span><?= trv_esc($req['cost']) ?></span>
            <b>簡章份數</b><span><?= $req['brochure_count']!==null?(int)$req['brochure_count']:'—' ?></span>
        </div></div>
    </div>
    <?php include '../partPage/footer.html' ?>
</div></div>

<div class="ap-mask" id="rejMask"><div class="ap-modal">
    <div class="m-head"><span>退回申請單</span><span style="cursor:pointer;color:#b5762a;" onclick="closeMask('rejMask')">✕</span></div>
    <div class="m-body">
        <div>退回<b>必須填寫原因</b>，原因會通知申請人。</div>
        <textarea id="rejNote" rows="4" maxlength="500" placeholder="例如：受訓費用未列明，請補充後再送審"></textarea>
        <div id="rejErr" style="color:#DD5138;font-size:12px;"></div>
    </div>
    <div class="m-foot">
        <button style="background:#fff;color:#5b3a1e;border-color:#D8BE93;" onclick="closeMask('rejMask')">取消</button>
        <button class="b-ok" id="btnRejOk">確定退回</button>
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
var API='../../src/store/Training_API.php', RID=<?= (int)$rid ?>;
function closeMask(id){ document.getElementById(id).style.display='none'; }
$('.ap-mask').on('click', function(e){ if (e.target===this) this.style.display='none'; });
function decide(d, note){
    $.post(API, {action:'request_decide', request_id:RID, decision:d, note:note||''}, function(res){
        if (!res.ok){ alert(res.error||'處理失敗'); return; }
        alert(d==='approved' ? '已核准。' : '已退回，並已通知申請人。');
        location.reload();
    }, 'json').fail(function(x){ alert('處理失敗：'+(x.responseJSON&&x.responseJSON.error||x.status)); });
}
$('#btnAppr').on('click', function(){ if (confirm('確定核准此教育訓練需求申請單？')) decide('approved',''); });
$('#btnRej').on('click', function(){ $('#rejNote').val(''); $('#rejErr').text(''); document.getElementById('rejMask').style.display='block'; });
$('#btnRejOk').on('click', function(){
    var n=$.trim($('#rejNote').val());
    if (!n){ $('#rejErr').text('請填寫退回原因（必填）'); $('#rejNote').focus(); return; }
    decide('rejected', n);
});
</script>
</body>
</html>
