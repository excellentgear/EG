<?php
/**
 * 年度教育訓練計劃表 — 審核檢視頁（通知點進來的落地頁）
 * 依 ai-rules/17-審核通知標準：通知要看得到完整內容、有核准/退回按鈕、退回必填原因、附件含類別說明。
 * 進入方式：live_event ref_type='TRAINING_PLAN_APPROVAL' → ?year=YYYY（或 ?event=live_event.id）
 */
session_start();
if (!isset($_SESSION['userName'])) {
    $_SESSION['lastpage'] = "../../views/ADM/training_plan_approval_view.php" . (isset($_GET['year']) ? "?year=".(int)$_GET['year'] : '');
    header("Location:../../index.php");
    exit;
}
include_once '../../src/common/_config.php';
include_once '../../src/common/DBConnection.php';
include_once '../../src/common/training_lib.php';

$db = (new DBConnection())->getPDO();
training_ensure_schema($db);
$trUser = training_current_user($db);
$perms  = training_perms($db, $trUser);

$year = (int)($_GET['year'] ?? 0);
if (!$year && isset($_GET['event'])) {
    $st = $db->prepare("SELECT ref_id FROM live_event WHERE id=? AND ref_type LIKE 'TRAINING_PLAN%'");
    $st->execute([(int)$_GET['event']]);
    $year = (int)$st->fetchColumn();
}
if (!$year) $year = (int)date('Y');

$appr    = training_plan_approval($db, $year);
$signers = training_plan_signers($db);
$company = eg_company_full_name($db);
$docNo   = training_as_doc_no($db, 'plan');
$uid     = (int)($trUser['id'] ?? 0);
$stage   = $appr['status'] === 'review_pending' ? 'review' : ($appr['status'] === 'approve_pending' ? 'approve' : '');
$who     = $stage === 'review' ? $signers['reviewer'] : $signers['approver'];
$canSign = $stage !== '' && ($perms['isAdmin'] || ($who && (int)$who['id'] === $uid));

// 計畫內容（同列印版的資料來源）
$deptMap = [];
foreach ($db->query("SELECT id, name FROM department")->fetchAll(PDO::FETCH_ASSOC) as $d) $deptMap[(int)$d['id']] = $d['name'];
$st = $db->prepare("SELECT * FROM training_session WHERE year=? AND status<>'cancelled' ORDER BY plan_month, session_id");
$st->execute([$year]);
$rows = $st->fetchAll(PDO::FETCH_ASSOC);
$sdMap = training_session_depts($db, array_column($rows, 'session_id'));
$dq = $db->prepare("SELECT d.session_id, d.day_date FROM training_session_day d
                    JOIN training_session s ON s.session_id=d.session_id WHERE s.year=?");
$dq->execute([$year]);
$dayMap = [];
foreach ($dq->fetchAll(PDO::FETCH_ASSOC) as $d) $dayMap[(int)$d['session_id']][] = $d['day_date'];
// 全年度的附件（依 ai-rules/17：附件要附在通知/審核頁上，且顯示類別與說明）
$atts = $db->prepare("SELECT a.*, s.course_name FROM training_attachment a
                      JOIN training_session s ON s.session_id=a.session_id
                      WHERE s.year=? AND a.status='active' ORDER BY s.plan_month, a.att_id");
$atts->execute([$year]);
$attRows = $atts->fetchAll(PDO::FETCH_ASSOC);
function tv_esc($s){ return htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8'); }
?>
<!DOCTYPE html>
<html lang="zh-Hant">
<head>
    <meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1">
    <title><?= $year ?> 年度教育訓練計畫表 — 審核</title>
    <link href="../../resource/css/bootstrap.css" rel="stylesheet">
    <link href="../../resource/css/font-awesome.css" rel="stylesheet">
    <link href="../../resource/css/nprogress.css" rel="stylesheet">
    <link href="../../resource/css/custom.css" rel="stylesheet">
    <style>
        #sidebar-menu { visibility: hidden; }
        .right_col .page-title { margin:8px 0 4px; overflow:hidden; clear:both; }
        .ap-bar { display:flex; flex-wrap:wrap; gap:8px; align-items:center; clear:both;
            border:1.5px solid #E8D5B5; border-radius:8px; padding:9px 12px; margin-bottom:10px; background:#FDF8EF; }
        .ap-bar b { color:#8A5A2B; }
        .ap-bar button { height:32px; font-size:13px; padding:0 18px; border-radius:4px; cursor:pointer; border:1px solid #d98a33; }
        .b-ok { background:#F0A24B; color:#fff; }
        .b-no { background:#fff; color:#DD5138; border-color:#DD5138 !important; }
        .ap-wrap { border:1px solid #E8D5B5; border-radius:6px; background:#fff; overflow-x:auto; margin-bottom:12px; }
        table.ap { width:100%; border-collapse:collapse; font-size:12.5px; }
        table.ap th, table.ap td { border:1px solid #EADFC8; padding:4px 6px; text-align:center; }
        table.ap thead th { background:#F7E0BD; color:#5b3a1e; }
        table.ap td.l { text-align:left; }
        .ap-sec { font-size:14px; font-weight:bold; color:#8A5A2B; margin:12px 0 5px; }
        .ap-att { font-size:12.5px; color:#5b3a1e; line-height:1.9; }
        .ap-att .cat { background:#F7E0BD; color:#7a5217; border-radius:9px; padding:1px 8px; font-size:11.5px; margin-left:4px; }
        .ap-note { border:1px dashed #E8D5B5; background:#FDF8EF; border-radius:6px; padding:8px 10px; font-size:12.5px; color:#5b3a1e; }
        .page-help-btn { margin-left:auto; height:30px; font-size:13px; padding:0 14px; border:1px solid #D8BE93;
            border-radius:4px; background:#fff; color:#5b3a1e; cursor:pointer; }
        .ap-mask { display:none; position:fixed; inset:0; background:rgba(60,40,20,.45); z-index:1050; }
        .ap-modal { background:#fff; border-radius:8px; max-width:560px; margin:60px auto; box-shadow:0 5px 25px rgba(0,0,0,.3); }
        .ap-modal .m-head { background:#F7E0BD; color:#5b3a1e; font-weight:bold; padding:10px 15px; border-radius:8px 8px 0 0;
            display:flex; justify-content:space-between; }
        .ap-modal .m-body { padding:15px; font-size:13px; color:#5b3a1e; line-height:1.8; }
        .ap-modal .m-body textarea { width:100%; border:1px solid #D8BE93; border-radius:4px; padding:6px 8px; font-size:13px; }
        .ap-modal .m-foot { padding:10px 15px; border-top:1px solid #EADFC8; text-align:right; }
        .ap-modal .m-foot button { height:30px; padding:0 16px; border-radius:4px; font-size:13px; border:1px solid #d98a33; cursor:pointer; }
        .help-doc h4 { font-size:14px; color:#8A5A2B; margin:10px 0 4px; }
        @media print { .ap-bar, .page-help-btn, .nav_menu, .left_col, footer { display:none !important; } }
    </style>
</head>
<body class="nav-sm">
<div class="container body"><div class="main_container">
    <?php include '../partPage/sideAndTopBarMenu.html' ?>
    <div class="right_col" role="main">
        <div class="page-title" style="display:flex;align-items:center;flex-wrap:wrap;">
            <h2 style="margin:6px 0;"><?= $year ?> 年度教育訓練計畫表 — 審核
                <small style="color:#8a6d45;"><?= tv_esc($company) ?><?= $docNo ? '　'.tv_esc($docNo) : '' ?></small></h2>
            <button class="page-help-btn" id="btnPageHelp"><i class="fa fa-question-circle"></i> 使用說明</button>
        </div>
        <div class="clearfix"></div>

        <div class="ap-bar">
            <span>目前狀態：<b><?php
                $lbl = ['none'=>'尚未送審','review_pending'=>'待審核','reviewed'=>'審核通過，待送核准',
                        'approve_pending'=>'待核准','approved'=>'已核准','rejected'=>'已退回'];
                echo tv_esc($lbl[$appr['status']] ?? $appr['status']); ?></b></span>
            <span style="font-size:12px;color:#8a6d45;">審核：<?= tv_esc($signers['reviewer']['name'] ?? '（未設定）') ?>
                ／核准：<?= tv_esc($signers['approver']['name'] ?? '（未設定）') ?>
                ／人事：<?= tv_esc($signers['hr_signer']['name'] ?? '（未設定）') ?></span>
            <?php if ($canSign): ?>
                <button class="b-ok" id="btnAppr"><i class="fa fa-check"></i> 核准</button>
                <button class="b-no" id="btnRej"><i class="fa fa-undo"></i> 退回</button>
            <?php elseif ($stage !== ''): ?>
                <span style="color:#DD5138;font-size:12px;">目前這關由 <?= tv_esc($who['name'] ?? '（未設定簽核人）') ?> 處理，您無法決行</span>
            <?php endif; ?>
            <a href="training_record.php" style="margin-left:auto;color:#b5762a;">回教育訓練管理</a>
        </div>

        <?php if (!empty($appr['review']['note']) || !empty($appr['approve']['note'])): ?>
        <div class="ap-note" style="margin-bottom:10px;">
            <?php if (!empty($appr['review']['note'])): ?>審核意見（<?= tv_esc($appr['review']['approver_name']) ?>）：<?= tv_esc($appr['review']['note']) ?><br><?php endif; ?>
            <?php if (!empty($appr['approve']['note'])): ?>核准意見（<?= tv_esc($appr['approve']['approver_name']) ?>）：<?= tv_esc($appr['approve']['note']) ?><?php endif; ?>
        </div>
        <?php endif; ?>

        <div class="ap-sec">計畫內容（◎＝預計實施月份、✔＝已實施）</div>
        <div class="ap-wrap"><table class="ap">
            <thead><tr><th style="width:34px;">NO.</th><th>課程名稱</th><th style="width:140px;">訓練對象</th>
                <th style="width:56px;">實施方式</th><?php for ($m=1;$m<=12;$m++) echo '<th style="width:26px;">'.$m.'</th>'; ?>
                <th style="width:60px;">狀態</th></tr></thead>
            <tbody>
            <?php if (!$rows): ?><tr><td colspan="18" style="padding:14px;color:#8a6d45;">本年度尚無訓練計畫</td></tr><?php endif; ?>
            <?php foreach ($rows as $i => $r):
                $sid = (int)$r['session_id'];
                $ids = $sdMap[$sid] ?? ($r['dept_id'] !== null ? [(int)$r['dept_id']] : []);
                $dn  = $ids ? implode('、', array_map(fn($d) => $deptMap[$d] ?? '', $ids)) : '全公司';
                $doneM = [];
                if ($r['status'] === 'done') {
                    foreach (($dayMap[$sid] ?? []) as $d) $doneM[(int)substr($d, 5, 2)] = 1;
                    if (!$doneM) $doneM[(int)$r['plan_month']] = 1;
                }
            ?>
                <tr><td><?= $i+1 ?></td><td class="l"><?= tv_esc($r['course_name']) ?></td><td class="l"><?= tv_esc($dn) ?></td>
                    <td><?= $r['train_type'] === 'external' ? '外訓' : '內訓' ?></td>
                    <?php for ($m=1;$m<=12;$m++): ?>
                        <td><?= ($m === (int)$r['plan_month'] ? '◎' : '') . (!empty($doneM[$m]) ? '✔' : '') ?></td>
                    <?php endfor; ?>
                    <td><?= ['planned'=>'計畫中','scheduled'=>'已排定','done'=>'已完成'][$r['status']] ?? tv_esc($r['status']) ?></td></tr>
            <?php endforeach; ?>
            </tbody>
        </table></div>

        <div class="ap-sec">附件（<?= count($attRows) ?>）</div>
        <div class="ap-att">
        <?php if (!$attRows): ?><span style="color:#8a6d45;">本年度計畫的場次尚無附件</span><?php endif; ?>
        <?php foreach ($attRows as $a):
            $cats = array_map(fn($c) => TRAINING_ATT_CATS[$c] ?? $c, array_filter(explode(',', (string)$a['cat']))); ?>
            <div><i class="fa fa-paperclip" style="color:#b5762a;"></i>
                <a href="../../src/store/Training_API.php?action=download_attach&att_id=<?= (int)$a['att_id'] ?>" target="_blank" style="color:#b5762a;">
                    <?= tv_esc($a['original_name'] ?: $a['file_name']) ?></a>
                <span class="cat"><?= tv_esc(implode('、', $cats)) ?></span>
                <span style="color:#8a6d45;font-size:11.5px;">　課程：<?= tv_esc($a['course_name']) ?>　上傳：<?= tv_esc($a['user_name']) ?>
                    <?= tv_esc(substr((string)$a['created_at'], 0, 16)) ?>　<?= number_format(((int)$a['file_size'])/1024, 1) ?> KB</span>
            </div>
        <?php endforeach; ?>
        </div>
        <div style="font-size:11.5px;color:#8a6d45;margin-top:8px;">
            需要紙本格式（含簽章欄）請回教育訓練管理頁按「訓練計劃表」列印。
        </div>
    </div>
    <?php include '../partPage/footer.html' ?>
</div></div>

<div class="ap-mask" id="rejMask"><div class="ap-modal">
    <div class="m-head"><span>退回計劃表</span><span style="cursor:pointer;color:#b5762a;" onclick="closeMask('rejMask')">✕</span></div>
    <div class="m-body">
        <div>退回<b>必須填寫原因</b>，原因會通知送審者並顯示在本頁。</div>
        <textarea id="rejNote" rows="4" maxlength="500" placeholder="例如：3 月的 ISO 內稽訓練對象漏了品管組，請補上後再送審"></textarea>
        <div id="rejErr" style="color:#DD5138;font-size:12px;"></div>
    </div>
    <div class="m-foot">
        <button style="background:#fff;color:#5b3a1e;border-color:#D8BE93;" onclick="closeMask('rejMask')">取消</button>
        <button class="b-ok" id="btnRejOk">確定退回</button>
    </div>
</div></div>

<div class="ap-mask" id="helpUseMask"><div class="ap-modal">
    <div class="m-head"><span>使用說明 — 訓練計劃表審核</span><span style="cursor:pointer;color:#b5762a;" onclick="closeMask('helpUseMask')">✕</span></div>
    <div class="m-body help-doc">
        <h4>功能說明</h4>年度教育訓練計劃表的審核頁：一頁看完整年度計畫、附件，並直接核准或退回。
        <h4>操作步驟</h4>1. 檢視計畫內容（◎＝預計實施月份、✔＝已實施）與附件。<br>
        2. 沒問題按<b>核准</b>；有問題按<b>退回</b>並<b>填寫原因</b>。<br>
        3. 審核通過會自動通知下一關（核准人員）；退回會通知送審者。
        <h4>重要行為</h4>・只有本關的簽核人（或系統管理者）看得到決行按鈕。<br>
        ・決行後原通知會自動結束，不會一直掛在置頂欄。<br>
        ・簽核人是誰取自「組織角色綁定設定」，不在本頁設定。
        <h4>權限</h4>檢視：訓練檢閱以上；決行：本關指定簽核人或系統管理者。
    </div>
    <div class="m-foot"><button class="b-ok" onclick="closeMask('helpUseMask')">關閉</button></div>
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
var API='../../src/store/Training_API.php', YEAR=<?= (int)$year ?>;
function closeMask(id){ document.getElementById(id).style.display='none'; }
$('#btnPageHelp').on('click', function(){ document.getElementById('helpUseMask').style.display='block'; });
$('.ap-mask').on('click', function(e){ if (e.target===this) this.style.display='none'; });
function decide(d, note){
    $.post(API, {action:'plan_decide', year:YEAR, decision:d, note:note||''}, function(res){
        if (!res.ok){ alert(res.error||'處理失敗'); return; }
        alert(d==='approved'
            ? (res.status==='approve_pending' ? '已審核通過，已通知核准人員。' : '已核准。')
            : '已退回，並已通知送審者。');
        location.reload();
    }, 'json').fail(function(x){ alert('處理失敗：'+(x.responseJSON&&x.responseJSON.error||x.status)); });
}
$('#btnAppr').on('click', function(){ if (confirm('確定核准本年度教育訓練計畫表？')) decide('approved',''); });
$('#btnRej').on('click', function(){ $('#rejNote').val(''); $('#rejErr').text(''); document.getElementById('rejMask').style.display='block'; });
$('#btnRejOk').on('click', function(){
    var n=$.trim($('#rejNote').val());
    if (!n){ $('#rejErr').text('請填寫退回原因（必填）'); $('#rejNote').focus(); return; }
    decide('rejected', n);
});
</script>
</body>
</html>
