<?php
// =============================================================================
// views/QA/qa_abnormal_view.php
// 品質異常單 檢視／通知回覆頁（RWD，桌機與手機皆可用）。
//  - 鈴鐺通知、Web Push、手機通知列表點到「異常單通知」時導向本頁。
//  - 上半部：異常單完整資訊（唯讀）＋流程狀態。
//  - 下半部：沿用公告系統的回覆回簽介面(_eventDetail/_eventRespond)，
//            回覆會同步回寫異常單流程(qa_abnormal_order_flow)。
// 進入參數：?id=異常單id 或 ?event=live_event id（通知點入）。
// =============================================================================
session_start();
if (!isset($_SESSION['id'])) {
    $qs = $_SERVER['QUERY_STRING'] ? ('?' . $_SERVER['QUERY_STRING']) : '';
    $_SESSION['lastpage'] = '/EGsystem/views/QA/qa_abnormal_view.php' . $qs;
    header('Location: /EGsystem/index.php');
    exit();
}
include_once '../../src/common/DBConnection.php';
require_once '../../src/common/qa_notify.php';

$conn = new DBConnection();
$db   = $conn->getPDO();
$uid  = (int)$_SESSION['id'];
$myName = $_SESSION['user_cname'] ?? '';

eg_qa_notify_schema($db);

// ── 解析參數：event 優先（通知點入），否則 id ──
$eventId = (int)($_GET['event'] ?? 0);
$orderId = (int)($_GET['id'] ?? 0);
if ($eventId && !$orderId) {
    $st = $db->prepare("SELECT ref_type, ref_id FROM live_event WHERE id=?");
    $st->execute([$eventId]);
    $ev = $st->fetch(PDO::FETCH_ASSOC);
    if ($ev && $ev['ref_type'] === 'QA') $orderId = (int)$ev['ref_id'];
}
$order = $orderId ? eg_qa_order_brief($db, $orderId) : null;
if (!$order) {
    echo "<script>alert('找不到異常單');history.length>1?history.back():window.close();</script>";
    exit();
}
// 未帶 event 時用主通知（回覆回簽介面掛在主通知上）
if (!$eventId) $eventId = (int)($order['notify_event_id'] ?? 0);

// ── 是否為此通知的對象（決定是否自動已閱與顯示回覆介面）──
$isTarget = false;
if ($eventId) {
    $myStatus = [-1];
    foreach (['status','status2','status3'] as $sk) if (!empty($_SESSION[$sk])) $myStatus[] = (int)$_SESSION[$sk];
    $myDept = [-1];
    $ds = $db->prepare("SELECT department_id FROM user_department_position_map WHERE user_id=?");
    $ds->execute([$uid]);
    foreach ($ds->fetchAll(PDO::FETCH_COLUMN) as $d) if ($d !== null) $myDept[] = (int)$d;
    $ts = $db->prepare("SELECT target_type, target_id FROM live_event_target WHERE live_event_id=?");
    $ts->execute([$eventId]);
    foreach ($ts->fetchAll(PDO::FETCH_ASSOC) as $t) {
        if (($t['target_type']==='all')
         || ($t['target_type']==='status' && in_array((int)$t['target_id'], $myStatus, true))
         || ($t['target_type']==='dept'   && in_array((int)$t['target_id'], $myDept, true))
         || ($t['target_type']==='user'   && (int)$t['target_id'] === $uid)) { $isTarget = true; break; }
    }
}

// ── 存取限制：僅開單人／追蹤人員／通知對象／管理者可檢視 ──
// 本頁不設 RBAC 角色（檢視權限由通知對象決定）；通知對象保有回覆/回簽功能，其餘為唯讀。
$isCreator = ((int)($order['created_by'] ?? 0) === $uid);
$fchk = $db->prepare("SELECT 1 FROM qa_abnormal_follower WHERE abnormal_order_id=? AND user_id=? LIMIT 1");
$fchk->execute([$orderId, $uid]);
$isFollower = (bool)$fchk->fetchColumn();
$isAdmin = false;
$isReadonlyViewer = false; // 具「唯讀檢閱」(qc_view_readonly) 者可檢視；非通知對象仍為唯讀（無回覆/回簽）
try {
    $achk = $db->prepare("SELECT 1 FROM user_roles ur JOIN role_features rf ON rf.role_id = ur.role_id
                          WHERE ur.user_id=? AND rf.feature_code='all' LIMIT 1");
    $achk->execute([$uid]);
    $isAdmin = (bool)$achk->fetchColumn();
    $rchk = $db->prepare("SELECT 1 FROM user_roles ur JOIN role_features rf ON rf.role_id = ur.role_id
                          WHERE ur.user_id=? AND rf.feature_code='qc_view_readonly' LIMIT 1");
    $rchk->execute([$uid]);
    $isReadonlyViewer = (bool)$rchk->fetchColumn();
} catch (Throwable $e) { /* RBAC 表不存在時視為非管理者 */ }
if (!$isCreator && !$isFollower && !$isTarget && !$isAdmin && !$isReadonlyViewer) {
    // 注意：無權限時只顯示訊息，不設 lastpage、不導回登入頁（避免登入死循環）
    echo '<!DOCTYPE html><html lang="zh-TW"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1"><title>無權檢視</title></head>'
       . '<body style="font-family:Microsoft JhengHei,Arial;background:#f4f7f9;color:#34495e;text-align:center;padding:60px 20px;">'
       . '<h3>無權檢視此異常單</h3><p>此頁僅開放給開單人、追蹤人員、通知對象，或具「唯讀檢閱」權限者。</p>'
       . '<button onclick="history.length>1?history.back():window.close()" style="padding:8px 22px;border:none;border-radius:8px;background:#2A3F54;color:#fff;font-size:14px;">返回</button>'
       . '</body></html>';
    exit();
}

// ── 流程、附件、追蹤人員 ──
$fs = $db->prepare("SELECT f.*, d.name AS dept_name, u.user_cname AS receiver_name
                    FROM qa_abnormal_order_flow f
                    LEFT JOIN department d ON d.id = f.dept_id
                    LEFT JOIN user u ON u.id = f.user_id
                    WHERE f.abnormal_order_id=? ORDER BY f.sort_order, f.flow_id");
$fs->execute([$orderId]);
$flows = $fs->fetchAll(PDO::FETCH_ASSOC);

$as = $db->prepare("SELECT id, field_type, file_name FROM qa_abnormal_attachments WHERE abnormal_order_id=? ORDER BY id");
$as->execute([$orderId]);
$atts = $as->fetchAll(PDO::FETCH_ASSOC);

$fw = $db->prepare("SELECT u.user_cname FROM qa_abnormal_follower f JOIN user u ON u.id=f.user_id WHERE f.abnormal_order_id=?");
$fw->execute([$orderId]);
$followers = $fw->fetchAll(PDO::FETCH_COLUMN);

function h($s){ return htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8'); }
$fieldNames = ['phenomenon'=>'異常現象', 'defect_detail'=>'原因說明', 'qa_ps'=>'品管備註'];
$statusMap  = ['Pending'=>['待送交','#8a9bab'], 'Received'=>['處理中','#c77c1a'], 'Returned'=>['已回覆','#169a80']];
?>
<!DOCTYPE html>
<html lang="zh-TW">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1, viewport-fit=cover">
    <meta name="theme-color" content="#2A3F54">
    <title>品質異常單 <?= h($order['abnormal_order_no']) ?></title>
    <link href="../../resource/css/font-awesome.css" rel="stylesheet">
    <style>
        :root{ --accent:#1ABB9C; --accent-d:#169a80; --dark:#2A3F54; --line:#e6ecf1; --text:#34495e; --muted:#8a9bab; --bg:#f4f7f9; }
        *{ box-sizing:border-box; }
        html,body{ margin:0; padding:0; background:var(--bg); color:var(--text); font-family:"Microsoft JhengHei","Helvetica Neue",Arial,sans-serif; font-size:15px; }
        .m-top{ position:sticky; top:0; z-index:20; background:var(--dark); color:#fff; display:flex; align-items:center; gap:10px; padding:calc(env(safe-area-inset-top) + 12px) 16px 12px; }
        .m-top h1{ font-size:17px; margin:0; font-weight:700; flex:1; }
        .m-top .who{ font-size:12px; opacity:.85; }
        .wrap{ max-width:960px; margin:0 auto; padding:14px; }
        .sec{ background:#fff; border:1px solid var(--line); border-radius:12px; padding:16px; margin-bottom:14px; box-shadow:0 1px 4px rgba(42,63,84,.06); }
        .sec h3{ font-size:14px; color:var(--dark); margin:0 0 12px; font-weight:700; border-left:4px solid var(--accent); padding-left:9px; }
        .grid{ display:grid; grid-template-columns:repeat(auto-fit, minmax(240px, 1fr)); gap:8px 18px; }
        .kv{ font-size:14px; line-height:1.7; }
        .kv b{ color:var(--muted); font-weight:600; margin-right:6px; white-space:nowrap; }
        .longtext{ background:#f8fbfa; border:1px solid var(--line); border-radius:8px; padding:10px 12px; white-space:pre-line; word-break:break-word; margin-top:4px; font-size:14px; }
        .tag{ display:inline-block; font-size:12px; font-weight:700; padding:2px 10px; border-radius:12px; color:#fff; }
        table{ width:100%; border-collapse:collapse; font-size:13.5px; }
        th,td{ border:1px solid var(--line); padding:7px 9px; text-align:left; vertical-align:top; }
        th{ background:#f0f4f7; white-space:nowrap; }
        .tbl-scroll{ overflow-x:auto; }
        .d-file{ display:inline-flex; align-items:center; gap:7px; text-decoration:none; background:#f0f4f7; border:1px solid var(--line); border-radius:8px; padding:8px 12px; font-size:13.5px; color:var(--accent-d); margin:3px 4px 3px 0; }
        .modebadge{ display:inline-block; font-size:12.5px; font-weight:700; padding:4px 12px; border-radius:20px; margin-bottom:10px; }
        .b-mode-sign{ background:#fff3df; color:#c77c1a; } .b-mode-reply{ background:#f0eafc; color:#7a4fc0; } .b-mode-read{ background:#eef2f5; color:#5a6b7b; }
        .mstatus{ font-size:13.5px; line-height:1.9; margin-bottom:8px; }
        .mstatus .fa{ width:16px; color:var(--accent-d); }
        .todo{ color:#e74c3c; font-weight:600; } .doneok{ color:var(--accent-d); font-weight:700; } .expired{ color:#e67e22; font-weight:600; margin-bottom:8px; }
        .lbl{ font-size:13px; font-weight:600; display:block; margin:10px 0 6px; }
        textarea.m-ta{ width:100%; min-height:90px; border:1px solid var(--line); border-radius:10px; padding:11px; font-size:15px; font-family:inherit; }
        textarea.m-ta:focus{ border-color:var(--accent); outline:none; }
        .m-btn{ display:inline-block; border:none; border-radius:10px; padding:12px 26px; font-size:15px; font-weight:700; margin-top:12px; cursor:pointer; }
        .m-btn-primary{ background:var(--accent); color:#fff; } .m-btn-primary:disabled{ opacity:.6; }
        .other{ border-top:1px dashed var(--line); padding:9px 0; font-size:13.5px; }
        .other .nm{ font-weight:700; color:var(--dark); } .other .tg{ float:right; font-size:12px; color:var(--muted); }
        .replybox{ color:var(--text); margin-top:4px; white-space:pre-line; }
        .toast{ position:fixed; left:50%; bottom:30px; transform:translateX(-50%); background:rgba(0,0,0,.85); color:#fff; padding:11px 20px; border-radius:24px; font-size:14px; z-index:100; display:none; }
        .m-loading{ text-align:center; color:var(--muted); padding:20px; }
    </style>
</head>
<body>
<div class="m-top">
    <h1><i class="fa fa-exclamation-triangle"></i> 品質異常單 <?= h($order['abnormal_order_no']) ?></h1>
    <span id="qa-ops" style="display:none;margin-left:10px;">
        <a class="m-btn" id="qa-op-edit" style="text-decoration:none;background:#fff3df;color:#c77c1a;padding:5px 12px;border-radius:6px;font-size:13px;" href="../QC/inspection_combined_prototype.php?edit_abnormal=<?= (int)$order['id'] ?>"><i class="fa fa-pencil"></i> 修改</a>
        <button type="button" id="qa-op-close" style="border:none;background:#fdecea;color:#e74c3c;padding:5px 12px;border-radius:6px;font-size:13px;cursor:pointer;"><i class="fa fa-archive"></i> 結案</button>
    </span>
    <span class="who"><?= h($myName) ?></span>
</div>
<div class="wrap">

    <!-- 異常單資訊 -->
    <div class="sec">
        <h3><i class="fa fa-file-text-o"></i> 異常單資訊</h3>
        <div class="grid">
            <div class="kv"><b>異常單號</b><?= h($order['abnormal_order_no']) ?></div>
            <div class="kv"><b>來　　源</b><?= h($order['source_desc'] ?: $order['source_type']) ?></div>
            <div class="kv"><b>異常種類</b><?= h($order['abnormal_type_name'] ?: '-') ?></div>
            <div class="kv"><b>發生日期</b><?= h($order['occurrence_date'] ?: '-') ?></div>
            <div class="kv"><b>異常數量</b><?= $order['sqty'] !== null ? h($order['sqty']) : '-' ?></div>
            <div class="kv"><b>發現單位</b><?= h($order['found_unit'] ?: '-') ?></div>
            <div class="kv"><b>責任單位</b><?= h($order['resp_desc'] ?: '未指定') ?></div>
            <div class="kv"><b>5M+T 分類</b><?= h($order['defect_category'] ?: '-') ?></div>
            <div class="kv"><b>處置方式</b><?= h($order['disposition'] ?: '-') ?></div>
            <div class="kv"><b>開 單 人</b><?= h($order['created_by_name'] ?: '-') ?><?= $order['created_at'] ? '（' . h($order['created_at']) . '）' : '' ?></div>
            <?php if ($followers): ?><div class="kv"><b>追蹤人員</b><?= h(implode('、', $followers)) ?></div><?php endif; ?>
            <?php if (!empty($order['is_closed'])): ?><div class="kv"><b>狀　　態</b><span class="tag" style="background:#8a9bab;">已結案</span></div><?php endif; ?>
        </div>
        <?php if ($order['abnormal_phenomenon']): ?><div class="kv" style="margin-top:10px;"><b>異常現象</b><div class="longtext"><?= h($order['abnormal_phenomenon']) ?></div></div><?php endif; ?>
        <?php if ($order['defect_detail']): ?><div class="kv" style="margin-top:8px;"><b>原因說明</b><div class="longtext"><?= h($order['defect_detail']) ?></div></div><?php endif; ?>
        <?php if ($order['disposition_note']): ?><div class="kv" style="margin-top:8px;"><b>處置說明</b><div class="longtext"><?= h($order['disposition_note']) ?></div></div><?php endif; ?>
        <?php if ($order['qa_ps']): ?><div class="kv" style="margin-top:8px;"><b>品管備註</b><div class="longtext"><?= h($order['qa_ps']) ?></div></div><?php endif; ?>
        <?php if ($atts): ?>
        <div class="kv" style="margin-top:10px;"><b>附　　件</b><div>
            <?php foreach ($atts as $a): ?>
            <a class="d-file" href="../../src/store/qa_attachment_download.php?id=<?= (int)$a['id'] ?>"><i class="fa fa-paperclip"></i> <?= h(($fieldNames[$a['field_type']] ?? '') . '｜' . $a['file_name']) ?></a>
            <?php endforeach; ?>
            <div style="font-size:12px;color:var(--muted);margin-top:3px;">附件存於公司內網，外網需連 VPN 才能開啟。</div>
        </div></div>
        <?php endif; ?>
    </div>

    <!-- 流程狀態 -->
    <?php if ($flows): ?>
    <div class="sec">
        <h3><i class="fa fa-sitemap"></i> 回覆部門流程狀態 <small style="color:var(--muted);font-weight:400;">（於下方回覆後自動更新）</small></h3>
        <div class="tbl-scroll"><table>
            <thead><tr><th>部門</th><th>指定人員</th><th>狀態</th><th>回覆內容</th><th>回覆時間</th></tr></thead>
            <tbody>
            <?php foreach ($flows as $f): $sm = $statusMap[$f['status']] ?? [$f['status'], '#8a9bab']; ?>
                <tr>
                    <td><?= h($f['dept_name'] ?: '-') ?></td>
                    <td><?= h($f['receiver_name'] ?: '（整個部門）') ?></td>
                    <td><span class="tag" style="background:<?= $sm[1] ?>;"><?= h($sm[0]) ?></span></td>
                    <td style="white-space:pre-line;"><?= h($f['reply_content'] ?: '') ?></td>
                    <td><?= h($f['return_date'] ?: '') ?></td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table></div>
    </div>
    <?php endif; ?>

    <!-- 我的處理（通知回覆回簽） -->
    <?php if ($eventId): ?>
    <div class="sec" id="sec-action">
        <h3><i class="fa fa-pencil-square-o"></i> 我的處理</h3>
        <div class="m-loading" id="act-loading"><i class="fa fa-spinner fa-spin"></i> 載入中…</div>
        <div id="act-body" style="display:none;"></div>
    </div>

    <!-- 各對象回覆/回簽狀態 -->
    <div class="sec" id="sec-others" style="display:none;">
        <h3><i class="fa fa-users"></i> 各對象回覆／回簽狀態</h3>
        <div id="others-body"></div>
    </div>
    <?php else: ?>
    <div class="sec"><span style="color:var(--muted);">此異常單尚未發出通知（無回覆回簽需求）。</span></div>
    <?php endif; ?>
</div>
<div class="toast" id="toast"></div>

<script src="../../resource/js/jquery.min.js"></script>
<script>
$(function(){
    'use strict';
    var EVENT_ID = <?= (int)$eventId ?>;
    var IS_TARGET = <?= $isTarget ? 'true' : 'false' ?>;
    var API_DETAIL = '../../src/store/_eventDetail.php';
    var API_RESPOND = '../../src/store/_eventRespond.php';
    function fileUrl(t, id, dl){ return '../../src/store/_eventFile.php?t=' + t + '&id=' + id + (dl ? '&dl=1' : ''); }
    var esc = function(s){ return $('<i>').text(s==null?'':s).html(); };
    var modeName = { read:'確認已閱', sign:'回簽', reply:'回覆＋回簽' };
    var mbcls = { read:'b-mode-read', sign:'b-mode-sign', reply:'b-mode-reply' };
    $.ajaxSetup({ cache:false });
    function toast(m){ var $t=$('#toast').text(m).fadeIn(120); setTimeout(function(){ $t.fadeOut(300); }, 2200); }

    // 修改 / 結案 按鈕：具修改權限（管理員/主管/開單人/共同編輯者/經核准者）才顯示
    var ORDER_ID = <?= (int)$order['id'] ?>;
    var IS_CLOSED = <?= !empty($order['is_closed']) ? 'true' : 'false' ?>;
    var QA_API = '../../src/store/store_QA_Abnormal_API.php';
    function setCloseBtn(){
        $('#qa-op-close').html(IS_CLOSED ? '<i class="fa fa-undo"></i> 取消結案' : '<i class="fa fa-archive"></i> 結案');
    }
    $.post(QA_API, { action:'check_edit_perm', id: ORDER_ID }, function(r){
        if (r && r.success && r.can_edit){ setCloseBtn(); $('#qa-ops').show(); }
    }, 'json');
    $('#qa-op-close').on('click', function(){
        var act = IS_CLOSED ? 'reopen_order' : 'close_order';
        if (!confirm(IS_CLOSED ? '確認取消結案？此單將重新列入未結案追蹤。' : '確認結案？結案後可於追蹤功能以「未結案」篩選排除此單。')) return;
        var $b = $(this).prop('disabled', true);
        $.post(QA_API, { action: act, id: ORDER_ID }, function(r){
            $b.prop('disabled', false);
            if (!r || !r.success){ alert((r && r.message) || '操作失敗'); return; }
            IS_CLOSED = parseInt(r.is_closed, 10) === 1;
            setCloseBtn();
            toast(IS_CLOSED ? '已結案' : '已取消結案');
        }, 'json').fail(function(){ $b.prop('disabled', false); alert('連線失敗'); });
    });

    if (!EVENT_ID) return;

    function load(){
        $.get(API_DETAIL, { eventid: EVENT_ID }, render, 'json')
         .fail(function(){ $('#act-loading').text('載入失敗'); });
    }

    function render(res){
        $('#act-loading').hide();
        if (!res || !res.ok){ $('#act-body').show().html('<span class="todo">' + esc(res && res.msg ? res.msg : '載入失敗') + '</span>'); return; }

        // 非對象：只顯示說明，不給回覆介面
        if (!IS_TARGET){
            $('#act-body').show().html('<span style="color:var(--muted);">您不是此異常單通知的對象，以下狀態僅供檢視。</span>');
        } else {
            $('#act-body').show().html(buildAction(res));
        }

        // 各對象狀態（show_status_to_others 預設開啟）
        if (res.show_status){
            var oh = '';
            var mine = res.my_status;
            if (IS_TARGET){
                var tag = mine && mine.replied_at ? '已回覆' : (mine && mine.signed_at ? '已回簽' : (mine && mine.read_at ? '已閱' : '未處理'));
                oh += '<div class="other"><span class="tg">' + tag + '</span><span class="nm">我（<?= h($myName) ?>）</span>'
                    + (mine && mine.reply_content ? '<div class="replybox">' + esc(mine.reply_content) + '</div>' : '') + '</div>';
            }
            (res.others || []).forEach(function(o){
                var tag = o.replied_at ? '已回覆 ' + o.replied_at : (o.signed_at ? '已回簽 ' + o.signed_at : (o.read_at ? '已閱 ' + o.read_at : '未處理'));
                oh += '<div class="other"><span class="tg">' + esc(tag) + '</span><span class="nm">' + esc(o.name) + '</span>';
                if (o.reply_content) oh += '<div class="replybox">' + esc(o.reply_content) + '</div>';
                // 他人回覆附件：只有最高管理者可刪
                if (o.files && o.files.length) o.files.forEach(function(f){
                    oh += '<span style="display:inline-flex;align-items:center;"><a class="d-file" href="' + fileUrl('r', f.id, 1) + '"><i class="fa fa-paperclip"></i> ' + esc(f.file_name) + '</a>'
                        + (res.is_admin ? '<button type="button" class="rf-del" data-id="' + f.id + '" title="刪除附件（管理者）" style="background:none;border:none;color:#e74c3c;cursor:pointer;padding:2px 6px;"><i class="fa fa-trash-o"></i></button>' : '')
                        + '</span>';
                });
                oh += '</div>';
            });
            $('#sec-others').show();
            $('#others-body').html(oh || '<span style="color:var(--muted);">尚無回覆</span>');
        }

        // 對象進頁自動標記已閱（讓鈴鐺消失；回簽/回覆義務不受影響）
        if (IS_TARGET && !(res.my_status && res.my_status.read_at)){
            $.post(API_RESPOND, { eventid: EVENT_ID, action: 'read' });
        }
    }

    function buildAction(res){
        var mode = res.my_mode, s = res.my_status, h = '';
        h += '<span class="modebadge ' + mbcls[mode] + '">我的義務：' + modeName[mode] + '</span>';
        if (res.event && res.event.reply_deadline) h += '<span style="font-size:13px;color:var(--muted);margin-left:8px;">回覆期限：' + esc(res.event.reply_deadline) + '</span>';
        var line = '';
        if (s){
            if (s.read_at)    line += '<div><i class="fa fa-eye"></i> 已閱：' + esc(s.read_at) + '</div>';
            if (s.signed_at)  line += '<div><i class="fa fa-pencil-square-o"></i> 已回簽：' + esc(s.signed_at) + '</div>';
            if (s.replied_at) line += '<div><i class="fa fa-comments-o"></i> 已回覆：' + esc(s.replied_at) + '</div>';
        }
        if (!line) line = '<span class="todo">尚未處理</span>';
        h += '<div class="mstatus">' + line + '</div>';

        var done = s && ((mode==='read' && s.read_at) || (mode==='sign' && s.signed_at) || (mode==='reply' && s.replied_at));
        if (res.deadline_passed && (mode==='sign' || mode==='reply')){
            h += '<div class="expired"><i class="fa fa-clock-o"></i> 已超過回覆／回簽期限</div>';
        } else if (mode === 'read'){
            if (done) h += '<div class="doneok"><i class="fa fa-check-circle"></i> 已完成</div>';
            else h += '<button class="m-btn m-btn-primary act" data-act="read"><i class="fa fa-check"></i> 確認已閱</button>';
        } else if (mode === 'sign'){
            if (done) h += '<div class="doneok"><i class="fa fa-check-circle"></i> 已回簽</div>';
            else h += '<button class="m-btn m-btn-primary act" data-act="sign"><i class="fa fa-pencil-square-o"></i> 回簽</button>';
        } else {
            if (done){
                h += '<div class="doneok"><i class="fa fa-check-circle"></i> 已回覆</div>';
                if (s.reply_content) h += '<div class="lbl">我的回覆：</div><div class="longtext">' + esc(s.reply_content) + '</div>';
                // 我的回覆附件：本人可刪（期限內；管理者不受限，權限由後端把關）
                if (s.files && s.files.length) s.files.forEach(function(f){
                    h += '<span style="display:inline-flex;align-items:center;"><a class="d-file" href="' + fileUrl('r', f.id, 1) + '"><i class="fa fa-paperclip"></i> ' + esc(f.name) + '</a>'
                       + (res.can_del_my ? '<button type="button" class="rf-del" data-id="' + f.id + '" title="刪除此附件" style="background:none;border:none;color:#e74c3c;cursor:pointer;padding:2px 6px;"><i class="fa fa-trash-o"></i></button>' : '')
                       + '</span>';
                });
            } else {
                h += '<label class="lbl">回覆內容 <span style="color:#e74c3c;">*</span>（回覆後將通知開單人與追蹤人員，並回寫異常單流程）</label>';
                h += '<textarea id="reply-text" class="m-ta" placeholder="請輸入處理情形／改善對策…"></textarea>';
                h += '<label class="lbl">附件 <span style="color:var(--muted);font-weight:400;">可多檔</span></label>';
                h += '<input type="file" id="reply-files" multiple accept=".jpg,.jpeg,.png,.gif,.webp,.bmp,.pdf,.doc,.docx,.xls,.xlsx,.ppt,.pptx,.txt,.csv,.zip,.rar,.7z">';
                h += '<br><button class="m-btn m-btn-primary act" data-act="reply"><i class="fa fa-paper-plane"></i> 送出回覆＋回簽</button>';
            }
        }
        return h;
    }

    // 刪除回覆附件（本人或管理者；權限由後端 _respFileDelete.php 把關）
    $(document).on('click', '.rf-del', function(){
        var $b = $(this), id = $b.data('id');
        var name = ($b.prev('a.d-file').text() || '').trim();
        if (!confirm('確定刪除附件「' + name + '」？刪除後無法復原。')) return;
        $b.prop('disabled', true);
        $.post('../../src/store/_respFileDelete.php', { id: id }, function(res){
            if (res && res.ok){ toast('附件已刪除'); load(); }
            else { $b.prop('disabled', false); toast(res && res.msg ? res.msg : '刪除失敗'); }
        }, 'json').fail(function(){ $b.prop('disabled', false); toast('連線失敗'); });
    });

    $(document).on('click', '.act', function(){
        var act = $(this).data('act'), $btn = $(this);
        var fd = new FormData();
        fd.append('eventid', EVENT_ID);
        fd.append('action', act);
        if (act === 'reply'){
            var txt = ($('#reply-text').val() || '').trim();
            if (!txt){ toast('請輸入回覆內容'); return; }
            fd.append('reply_content', txt);
            var fi = $('#reply-files')[0];
            if (fi && fi.files) for (var i=0;i<fi.files.length;i++) fd.append('reply_files[]', fi.files[i]);
        }
        $btn.prop('disabled', true).html('<i class="fa fa-spinner fa-spin"></i> 處理中…');
        $.ajax({ url: API_RESPOND, type:'POST', data: fd, processData:false, contentType:false, dataType:'json',
            success: function(res){
                if (res && res.ok){ toast('已送出'); location.reload(); }
                else { $btn.prop('disabled', false).text('重試'); toast(res && res.msg ? res.msg : '送出失敗'); }
            },
            error: function(){ $btn.prop('disabled', false).text('重試'); toast('連線失敗'); }
        });
    });

    load();
});
</script>
</body>
</html>
