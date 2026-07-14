<?php
// =============================================================================
// views/Sales/quotation_approval_view.php
// 報價單審核／通知回覆頁（RWD，桌機與手機皆可用；比照 views/QA/qa_abnormal_view.php 架構）。
//  - 鈴鐺通知、手機通知列表點到「報價單待簽核」通知時導向本頁。
//  - 左欄（手機版為上半部）：報價單完整資訊（唯讀）。
//  - 右欄（手機版為下半部）：簽核處理（核准／駁回，駁回必填意見）。
// 進入參數：?event=live_event id（通知點入，優先）或 ?quote_id=報價單id。
// =============================================================================
session_start();
if (!isset($_SESSION['id'])) {
    $qs = $_SERVER['QUERY_STRING'] ? ('?' . $_SERVER['QUERY_STRING']) : '';
    $_SESSION['lastpage'] = '/EGsystem/views/Sales/quotation_approval_view.php' . $qs;
    header('Location: /EGsystem/index.php');
    exit();
}
require_once '../../src/common/DBConnection.php';
require_once '../../src/common/quotation_approval.php';

$conn = new DBConnection();
$pdo  = $conn->getPDO();
$uid  = (int)$_SESSION['id'];
$myName = eg_quotation_current_user_name($pdo, $uid);

function h($s) { return htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8'); }
function fmtNum2($n) { return number_format((float)$n, 0); }

// ── 解析參數：event 優先（通知點入），否則 quote_id ──
$eventId = (int)($_GET['event'] ?? 0);
$quoteId = (int)($_GET['quote_id'] ?? 0);
if ($eventId && !$quoteId) {
    $st = $pdo->prepare("SELECT ref_type, ref_id FROM live_event WHERE id=?");
    $st->execute([$eventId]);
    $ev = $st->fetch(PDO::FETCH_ASSOC);
    if ($ev && strpos($ev['ref_type'] ?? '', 'QUOTATION_APPROVAL') === 0) $quoteId = (int)$ev['ref_id'];
}
if (!$quoteId) {
    echo "<script>alert('找不到報價單');history.length>1?history.back():window.close();</script>";
    exit();
}

$qst = $pdo->prepare("SELECT ql.*, COALESCE(u1.user_cname, ql.created_by) AS created_by_name,
        COALESCE(u2.user_cname, ql.updated_by) AS updated_by_name
    FROM quotation_list ql
    LEFT JOIN user u1 ON u1.id = ql.created_by
    LEFT JOIN user u2 ON u2.id = ql.updated_by
    WHERE ql.quote_id=?");
$qst->execute([$quoteId]);
$quote = $qst->fetch(PDO::FETCH_ASSOC);
if (!$quote) {
    echo "<script>alert('找不到報價單');history.length>1?history.back():window.close();</script>";
    exit();
}
if (!$eventId) {
    // 未帶 event 時，取該單目前最新一筆簽核紀錄關聯的通知
    $la0 = eg_approval_latest($pdo, 'quotation', $quoteId);
    if ($la0 && !empty($la0['live_event_id'])) $eventId = (int)$la0['live_event_id'];
}

$ist = $pdo->prepare("SELECT qi.*, GROUP_CONCAT(DISTINCT qipm.process_no ORDER BY qipm.process_no) AS processes
    FROM quotation_item qi LEFT JOIN quotation_item_process_map qipm ON qi.item_id = qipm.quotation_item_id
    WHERE qi.quote_id = ? GROUP BY qi.item_id
    ORDER BY qi.product_id ASC, qi.process_notes ASC, qi.specification ASC, qi.quantity ASC, qi.item_id ASC");
$ist->execute([$quoteId]);
$items = $ist->fetchAll(PDO::FETCH_ASSOC);

// 依 process_notes（逗號分隔 sub_tag_id）解析出製程名稱（比照其他頁面的作法，用・連接）
$allSubTagIds = [];
foreach ($items as $it) {
    if (!empty($it['process_notes'])) {
        foreach (explode(',', $it['process_notes']) as $sid) {
            $sid = intval(trim($sid));
            if ($sid > 0) $allSubTagIds[$sid] = true;
        }
    }
}
$subTagMap = [];
if ($allSubTagIds) {
    try {
        $sids = array_keys($allSubTagIds);
        $ph = implode(',', array_fill(0, count($sids), '?'));
        $stq = $pdo->prepare("SELECT sub_tag_id, sub_tag_name FROM quotation_process_sub_tag WHERE sub_tag_id IN ($ph)");
        $stq->execute($sids);
        foreach ($stq->fetchAll(PDO::FETCH_ASSOC) as $r) $subTagMap[(int)$r['sub_tag_id']] = $r['sub_tag_name'];
    } catch (Throwable $e) { /* 忽略，製程名稱顯示為空 */ }
}
foreach ($items as &$it) {
    $names = [];
    if (!empty($it['process_notes'])) {
        foreach (explode(',', $it['process_notes']) as $sid) {
            $sid = intval(trim($sid));
            if ($sid > 0 && isset($subTagMap[$sid])) $names[] = $subTagMap[$sid];
        }
    }
    $it['process_names'] = implode('・', $names);
}
unset($it);

$latestApproval = eg_approval_latest($pdo, 'quotation', $quoteId);

// ── 是否為此通知的對象 ──
$isTarget = false;
if ($eventId) {
    $myStatus = [-1];
    foreach (['status', 'status2', 'status3'] as $sk) if (!empty($_SESSION[$sk])) $myStatus[] = (int)$_SESSION[$sk];
    $myDept = [-1];
    $ds = $pdo->prepare("SELECT department_id FROM user_department_position_map WHERE user_id=?");
    $ds->execute([$uid]);
    foreach ($ds->fetchAll(PDO::FETCH_COLUMN) as $d) if ($d !== null) $myDept[] = (int)$d;
    $ts = $pdo->prepare("SELECT target_type, target_id FROM live_event_target WHERE live_event_id=?");
    $ts->execute([$eventId]);
    foreach ($ts->fetchAll(PDO::FETCH_ASSOC) as $t) {
        if (($t['target_type'] === 'all')
         || ($t['target_type'] === 'status' && in_array((int)$t['target_id'], $myStatus, true))
         || ($t['target_type'] === 'dept'   && in_array((int)$t['target_id'], $myDept, true))
         || ($t['target_type'] === 'user'   && (int)$t['target_id'] === $uid)) { $isTarget = true; break; }
    }
}

$userCanSign = eg_quotation_user_can_sign($pdo, $uid);
$isSubmitter = $latestApproval && (int)($latestApproval['submitted_by'] ?? 0) === $uid;
$isCreator   = (int)($quote['created_by'] ?? 0) === $uid;

$isAdmin = false;
try {
    $achk = $pdo->prepare("SELECT 1 FROM user_roles ur JOIN role_features rf ON rf.role_id = ur.role_id WHERE ur.user_id=? AND rf.feature_code='all' LIMIT 1");
    $achk->execute([$uid]);
    $isAdmin = (bool)$achk->fetchColumn();
} catch (Throwable $e) { /* RBAC 表不存在時視為非管理者 */ }

// ── 存取限制：簽核人／送審人／建立者／通知對象／管理者才可檢視 ──
if (!$userCanSign && !$isSubmitter && !$isCreator && !$isAdmin && !$isTarget) {
    echo '<!DOCTYPE html><html lang="zh-TW"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1"><title>無權檢視</title></head>'
       . '<body style="font-family:Microsoft JhengHei,Arial;background:#f4f7f9;color:#34495e;text-align:center;padding:60px 20px;">'
       . '<h3>無權檢視此報價單</h3><p>此頁僅開放給簽核人員、送審人、建立者，或通知對象。</p>'
       . '<button onclick="history.length>1?history.back():window.close()" style="padding:8px 22px;border:none;border-radius:8px;background:#2A3F54;color:#fff;font-size:14px;">返回</button>'
       . '</body></html>';
    exit();
}

$canDecide = $userCanSign && ($quote['approval_status'] === 'pending');
$statusMap = [
    'none'     => ['尚未送審', '#8a9bab'],
    'pending'  => ['待審核', '#c77c1a'],
    'approved' => ['已核准', '#169a80'],
    'rejected' => ['已駁回', '#e74c3c'],
];
$sm = $statusMap[$quote['approval_status'] ?? 'none'] ?? ['未知', '#8a9bab'];
?>
<!DOCTYPE html>
<html lang="zh-TW">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1, viewport-fit=cover">
    <meta name="theme-color" content="#2A3F54">
    <title>報價單審核 <?= h($quote['quote_no']) ?></title>
    <link href="../../resource/css/font-awesome.css" rel="stylesheet">
    <style>
        :root{ --accent:#1ABB9C; --accent-d:#169a80; --dark:#2A3F54; --line:#e6ecf1; --text:#34495e; --muted:#8a9bab; --bg:#f4f7f9; }
        *{ box-sizing:border-box; }
        html,body{ margin:0; padding:0; background:var(--bg); color:var(--text); font-family:"Microsoft JhengHei","Helvetica Neue",Arial,sans-serif; font-size:15px; }
        .m-top{ position:sticky; top:0; z-index:20; background:var(--dark); color:#fff; display:flex; align-items:center; gap:10px; padding:calc(env(safe-area-inset-top) + 12px) 16px 12px; }
        .m-top h1{ font-size:17px; margin:0; font-weight:700; flex:1; }
        .m-top .who{ font-size:12px; opacity:.85; }
        .wrap{ max-width:1180px; margin:0 auto; padding:14px; }
        /* 桌機：左（報價單內容）右（簽核處理）分欄；手機：改上下堆疊 */
        .review-wrap{ display:grid; grid-template-columns:1.5fr 1fr; gap:16px; align-items:start; }
        @media (max-width:860px){ .review-wrap{ grid-template-columns:1fr; } }
        .sec{ background:#fff; border:1px solid var(--line); border-radius:12px; padding:16px; margin-bottom:14px; box-shadow:0 1px 4px rgba(42,63,84,.06); }
        .sec h3{ font-size:14px; color:var(--dark); margin:0 0 12px; font-weight:700; border-left:4px solid var(--accent); padding-left:9px; }
        .grid{ display:grid; grid-template-columns:repeat(auto-fit, minmax(200px, 1fr)); gap:8px 18px; }
        .kv{ font-size:13.5px; line-height:1.7; }
        .kv b{ color:var(--muted); font-weight:600; margin-right:6px; white-space:nowrap; }
        .longtext{ background:#f8fbfa; border:1px solid var(--line); border-radius:8px; padding:10px 12px; white-space:pre-line; word-break:break-word; margin-top:4px; font-size:13.5px; }
        .tag{ display:inline-block; font-size:12px; font-weight:700; padding:2px 10px; border-radius:12px; color:#fff; }
        table{ width:100%; border-collapse:collapse; font-size:12.5px; }
        th,td{ border:1px solid var(--line); padding:6px 8px; text-align:left; vertical-align:top; }
        th{ background:#f0f4f7; white-space:nowrap; }
        .tbl-scroll{ overflow-x:auto; }
        .r{ text-align:right; } .c{ text-align:center; }
        .totals{ margin-top:8px; text-align:right; font-size:13.5px; }
        .totals b{ color:var(--dark); font-size:16px; }
        .lbl{ font-size:13px; font-weight:600; display:block; margin:10px 0 6px; }
        textarea.m-ta{ width:100%; min-height:90px; border:1px solid var(--line); border-radius:10px; padding:11px; font-size:15px; font-family:inherit; }
        textarea.m-ta:focus{ border-color:var(--accent); outline:none; }
        .m-btn{ display:inline-block; border:none; border-radius:10px; padding:11px 22px; font-size:14.5px; font-weight:700; margin-top:10px; margin-right:8px; cursor:pointer; }
        .m-btn-approve{ background:var(--accent); color:#fff; } .m-btn-approve:disabled{ opacity:.6; }
        .m-btn-reject{ background:#fdecea; color:#e74c3c; } .m-btn-reject:disabled{ opacity:.6; }
        .doneok{ color:var(--accent-d); font-weight:700; } .todo{ color:#e74c3c; font-weight:600; }
        .toast{ position:fixed; left:50%; bottom:30px; transform:translateX(-50%); background:rgba(0,0,0,.85); color:#fff; padding:11px 20px; border-radius:24px; font-size:14px; z-index:100; display:none; }
        .m-loading{ text-align:center; color:var(--muted); padding:20px; }
    </style>
</head>
<body>
<div class="m-top">
    <h1><i class="fa fa-file-text-o"></i> 報價單審核 <?= h($quote['quote_no']) ?></h1>
    <span class="who"><?= h($myName) ?></span>
</div>
<div class="wrap">
<div class="review-wrap">

    <!-- 左欄：報價單內容（唯讀） -->
    <div>
        <div class="sec">
            <h3><i class="fa fa-info-circle"></i> 報價單資訊
                <span class="tag" style="background:<?= $sm[1] ?>;margin-left:6px;"><?= h($sm[0]) ?></span>
                <?php if ($quote['is_negotiation'] == 1): ?><span class="tag" style="background:#8e44ad;margin-left:4px;">議價</span><?php endif; ?>
                <?php if ($quote['is_draft'] == 1): ?><span class="tag" style="background:#e67e22;margin-left:4px;">草稿</span><?php endif; ?>
            </h3>
            <div class="grid">
                <div class="kv"><b>客戶名稱</b><?= h($quote['client_name']) ?></div>
                <div class="kv"><b>報價日期</b><?= h($quote['quote_date']) ?></div>
                <div class="kv"><b>有效日期</b><?= h($quote['valid_until'] ?: '-') ?></div>
                <div class="kv"><b>幣　　別</b><?= h($quote['currency']) ?></div>
                <div class="kv"><b>業務人員</b><?= h($quote['created_by_name'] ?: '-') ?></div>
                <div class="kv"><b>總　金　額</b><b style="color:var(--dark);"><?= fmtNum2($quote['total_amount']) ?></b></div>
            </div>
            <?php if ($quote['note']): ?><div class="kv" style="margin-top:8px;"><b>備　　註</b><div class="longtext"><?= h($quote['note']) ?></div></div><?php endif; ?>
        </div>

        <div class="sec">
            <h3><i class="fa fa-list"></i> 報價項目</h3>
            <div class="tbl-scroll"><table>
                <thead><tr><th>#</th><th>料號</th><th>製程</th><th>料號備註</th><th class="r">數量</th><th class="c">單位</th><th class="r">單價</th><th class="r">金額</th></tr></thead>
                <tbody>
                <?php foreach ($items as $i => $it): $amt = (float)($it['amount'] ?? ($it['quantity'] * $it['unit_price'])); ?>
                    <tr>
                        <td class="c"><?= $i + 1 ?></td>
                        <td><?= h($it['product_id']) ?></td>
                        <td><?= h($it['process_names'] ?: '-') ?></td>
                        <td><?= h($it['specification'] ?: '-') ?></td>
                        <td class="r"><?= fmtNum2($it['quantity']) ?></td>
                        <td class="c"><?= h($it['unit'] ?: 'PCS') ?></td>
                        <td class="r"><?= fmtNum2($it['unit_price']) ?></td>
                        <td class="r"><?= fmtNum2($amt) ?></td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table></div>
            <div class="totals">合計：<b><?= fmtNum2($quote['total_amount']) ?></b> <?= h($quote['currency']) ?></div>
        </div>
    </div>

    <!-- 右欄：簽核處理 -->
    <div>
        <div class="sec" id="sec-action">
            <h3><i class="fa fa-pencil-square-o"></i> 簽核處理</h3>
            <div id="act-body">
                <?php if ($quote['approval_status'] === 'approved'): ?>
                    <div class="doneok"><i class="fa fa-check-circle"></i> 已核准</div>
                    <div class="kv" style="margin-top:6px;"><b>核准人</b><?= h($quote['approved_by_name'] ?: '-') ?></div>
                    <div class="kv"><b>核准時間</b><?= h($quote['approved_at'] ?: '-') ?></div>
                    <?php if ($latestApproval && $latestApproval['note']): ?><div class="lbl">審核意見</div><div class="longtext"><?= h($latestApproval['note']) ?></div><?php endif; ?>
                <?php elseif ($quote['approval_status'] === 'rejected'): ?>
                    <div class="todo"><i class="fa fa-times-circle"></i> 已駁回</div>
                    <?php if ($latestApproval): ?>
                    <div class="kv" style="margin-top:6px;"><b>駁回人</b><?= h($latestApproval['approver_name'] ?: '-') ?></div>
                    <div class="kv"><b>駁回時間</b><?= h($latestApproval['decided_at'] ?: '-') ?></div>
                    <?php if ($latestApproval['note']): ?><div class="lbl">駁回原因</div><div class="longtext"><?= h($latestApproval['note']) ?></div><?php endif; ?>
                    <?php endif; ?>
                    <div class="kv" style="margin-top:8px;color:var(--muted);font-size:12.5px;">請聯繫送審人修改內容後重新送出審核（需回原系統操作）。</div>
                <?php elseif ($quote['approval_status'] === 'pending'): ?>
                    <?php if ($canDecide): ?>
                    <div class="kv" style="margin-bottom:6px;"><?= h($myName) ?>，此單待您審核：</div>
                    <label class="lbl">審核意見 <span style="color:var(--muted);font-weight:400;">（駁回必填，核准選填）</span></label>
                    <textarea id="note-text" class="m-ta" placeholder="請輸入審核意見…"></textarea>
                    <div>
                        <button class="m-btn m-btn-approve act" data-decision="approved"><i class="fa fa-check"></i> 核准</button>
                        <button class="m-btn m-btn-reject act" data-decision="rejected"><i class="fa fa-times"></i> 駁回</button>
                    </div>
                    <?php else: ?>
                    <div class="kv" style="color:var(--muted);">此單待主管審核中，您不是簽核人員，僅供檢視。</div>
                    <?php endif; ?>
                <?php else: ?>
                    <div class="kv" style="color:var(--muted);">草稿尚未送審。</div>
                <?php endif; ?>
            </div>
        </div>
    </div>

</div>
</div>
<div class="toast" id="toast"></div>

<script src="../../resource/js/jquery.min.js"></script>
<script>
$(function(){
    'use strict';
    var QUOTE_ID = <?= (int)$quoteId ?>;
    var EVENT_ID = <?= (int)$eventId ?>;
    var IS_TARGET = <?= $isTarget ? 'true' : 'false' ?>;
    var API_URL = '../../src/store/Quotation_API.php';
    var API_RESPOND = '../../src/store/_eventRespond.php';
    $.ajaxSetup({ cache: false });
    function toast(m) { var $t = $('#toast').text(m).fadeIn(120); setTimeout(function(){ $t.fadeOut(300); }, 2200); }

    // 對象進頁自動標記已閱（讓鈴鐺消失；核准/駁回義務不受影響，由 quotation_approval_decide 自行解除）
    if (IS_TARGET && EVENT_ID) {
        $.post(API_RESPOND, { eventid: EVENT_ID, action: 'read' });
    }

    $(document).on('click', '.act', function () {
        var decision = $(this).data('decision');
        var note = ($('#note-text').val() || '').trim();
        if (decision === 'rejected' && !note) { toast('駁回必須填寫審核意見'); return; }
        var $btns = $('.act').prop('disabled', true);
        $.post(API_URL, { action: 'quotation_approval_decide', quote_id: QUOTE_ID, decision: decision, note: note }, function (res) {
            if (res && res.success) {
                toast(res.message || '已送出');
                // 桌機是由列表頁 window.open 開啟這個獨立頁面，該分頁的資料不會自動變新——
                // 用 postMessage 通知 opener（quotation_list_test.php）核准/駁回已完成，讓它主動重新整理清單/檢視畫面
                try {
                    if (window.opener && !window.opener.closed) {
                        window.opener.postMessage({ type: 'quotation_approval_done', quote_id: QUOTE_ID }, '*');
                    }
                } catch (e) {}
                setTimeout(function () { location.reload(); }, 600);
            } else {
                $btns.prop('disabled', false);
                toast((res && res.message) || '處理失敗');
            }
        }, 'json').fail(function () {
            $btns.prop('disabled', false);
            toast('連線失敗');
        });
    });
});
</script>
</body>
</html>
