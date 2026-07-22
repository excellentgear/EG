<?php
// =============================================================================
// views/Sales/quotation_supplement_view.php
// 報價單「補件」審核／結果檢視頁（RWD，比照 quotation_approval_view.php）。
//  - 補件待審通知(QUOTATION_SUPP)點入 → 左欄顯示報價單原有內容＋原有附件＋本次補件的附件與標籤，
//    右欄核准／駁回（駁回必填原因，呼叫 Quotation_File_API decide_supplement）。
//  - 補件結果通知(QUOTATION_SUPP_RESULT)點入 → 顯示核准/駁回結果。
// 進入參數：?event=live_event id（ref_id=附件id）或 ?att=附件id。
// =============================================================================
session_start();
if (!isset($_SESSION['id'])) {
    $qs = $_SERVER['QUERY_STRING'] ? ('?' . $_SERVER['QUERY_STRING']) : '';
    $_SESSION['lastpage'] = '/EGsystem/views/Sales/quotation_supplement_view.php' . $qs;
    header('Location: /EGsystem/index.php');
    exit();
}
require_once '../../src/common/DBConnection.php';
require_once '../../src/common/quotation_supplement.php'; // 內含 quotation_approval.php + approval_lib.php

$conn = new DBConnection();
$pdo  = $conn->getPDO();
$uid  = (int)$_SESSION['id'];
$myName = eg_quotation_current_user_name($pdo, $uid);

function h($s) { return htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8'); }
function fmtNum2($n) { return number_format((float)$n, 0); }
function suppPartLabel($lp) {
    if ($lp === null || $lp === '') return '共用';
    $a = json_decode($lp, true);
    return (is_array($a) && $a) ? implode('、', array_map('strval', $a)) : '共用';
}

// ── 解析參數：event 優先（ref_id=附件id），否則 att ──
$eventId = (int)($_GET['event'] ?? 0);
$attId   = (int)($_GET['att'] ?? 0);
if ($eventId && !$attId) {
    $st = $pdo->prepare("SELECT ref_type, ref_id FROM live_event WHERE id=?");
    $st->execute([$eventId]);
    $ev = $st->fetch(PDO::FETCH_ASSOC);
    if ($ev && strpos($ev['ref_type'] ?? '', 'QUOTATION_SUPP') === 0) $attId = (int)$ev['ref_id'];
}
if (!$attId) { echo "<script>alert('找不到補件附件');history.length>1?history.back():window.close();</script>"; exit(); }

// 補件附件
$ast = $pdo->prepare("SELECT a.*, COALESCE(u.user_cname, a.uploaded_by) AS uploader_name
    FROM quotation_attachments a LEFT JOIN user u ON u.id = CAST(a.uploaded_by AS UNSIGNED)
    WHERE a.id=?");
$ast->execute([$attId]);
$att = $ast->fetch(PDO::FETCH_ASSOC);
if (!$att) { echo "<script>alert('找不到補件附件');history.length>1?history.back():window.close();</script>"; exit(); }
$quoteNo = $att['quote_no'];

// 報價單
$qst = $pdo->prepare("SELECT ql.*, COALESCE(u1.user_cname, ql.created_by) AS created_by_name
    FROM quotation_list ql LEFT JOIN user u1 ON u1.id = ql.created_by WHERE ql.quote_no=? LIMIT 1");
$qst->execute([$quoteNo]);
$quote = $qst->fetch(PDO::FETCH_ASSOC);

// 報價項目（含製程名稱解析，比照審核頁）
$items = [];
if ($quote) {
    $ist = $pdo->prepare("SELECT qi.* FROM quotation_item qi WHERE qi.quote_id=?
        ORDER BY qi.product_id ASC, qi.process_notes ASC, qi.specification ASC, qi.item_id ASC");
    $ist->execute([(int)$quote['quote_id']]);
    $items = $ist->fetchAll(PDO::FETCH_ASSOC);
    $subIds = [];
    foreach ($items as $it) foreach (explode(',', (string)$it['process_notes']) as $s) { $s = (int)trim($s); if ($s > 0) $subIds[$s] = 1; }
    $subMap = [];
    if ($subIds) {
        try {
            $ph = implode(',', array_fill(0, count($subIds), '?'));
            $stq = $pdo->prepare("SELECT sub_tag_id, sub_tag_name FROM quotation_process_sub_tag WHERE sub_tag_id IN ($ph)");
            $stq->execute(array_keys($subIds));
            foreach ($stq->fetchAll(PDO::FETCH_ASSOC) as $r) $subMap[(int)$r['sub_tag_id']] = $r['sub_tag_name'];
        } catch (Throwable $e) {}
    }
    foreach ($items as &$it) {
        $ns = [];
        foreach (explode(',', (string)$it['process_notes']) as $s) { $s = (int)trim($s); if ($s > 0 && isset($subMap[$s])) $ns[] = $subMap[$s]; }
        $it['process_names'] = implode('・', $ns);
    }
    unset($it);
}

// 類別名稱對照
$catMap = [];
foreach ($pdo->query("SELECT id, category_name FROM quotation_file_categories")->fetchAll(PDO::FETCH_ASSOC) as $c) $catMap[(int)$c['id']] = $c['category_name'];
$catLabel = function ($cids) use ($catMap) {
    $a = array_values(array_filter(array_map('intval', explode(',', (string)$cids))));
    return implode('、', array_map(fn($i) => $catMap[$i] ?? ('#' . $i), $a)) ?: '—';
};

// 報價單原有正式附件（active）
$origAtts = [];
if ($quote) {
    $ost = $pdo->prepare("SELECT id, filename, original_name, category_ids, linked_parts,
            DATE_FORMAT(uploaded_at,'%Y-%m-%d %H:%i') AS uploaded_at
        FROM quotation_attachments WHERE quote_no=? AND status='active' ORDER BY uploaded_at DESC");
    $ost->execute([$quoteNo]);
    $origAtts = $ost->fetchAll(PDO::FETCH_ASSOC);
}

$latest = eg_approval_latest($pdo, 'quotation_attach', $attId, 'manager');

// 通知對象判定
$isTarget = false;
if ($eventId) {
    $ts = $pdo->prepare("SELECT target_type, target_id FROM live_event_target WHERE live_event_id=?");
    $ts->execute([$eventId]);
    foreach ($ts->fetchAll(PDO::FETCH_ASSOC) as $t) {
        if ($t['target_type'] === 'all' || ($t['target_type'] === 'user' && (int)$t['target_id'] === $uid)) { $isTarget = true; break; }
    }
}
$userCanSign = eg_quotation_user_can_sign($pdo, $uid);
$isUploader  = is_numeric($att['uploaded_by']) && (int)$att['uploaded_by'] === $uid;
$isCreator   = $quote && (int)($quote['created_by'] ?? 0) === $uid;
$isAdmin = false;
try {
    $achk = $pdo->prepare("SELECT 1 FROM user_roles ur JOIN role_features rf ON rf.role_id=ur.role_id WHERE ur.user_id=? AND rf.feature_code='all' LIMIT 1");
    $achk->execute([$uid]); $isAdmin = (bool)$achk->fetchColumn();
} catch (Throwable $e) {}

if (!$userCanSign && !$isUploader && !$isCreator && !$isAdmin && !$isTarget) {
    echo '<!DOCTYPE html><html lang="zh-TW"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1"><title>無權檢視</title></head>'
       . '<body style="font-family:Microsoft JhengHei,Arial;background:#f4f7f9;color:#34495e;text-align:center;padding:60px 20px;">'
       . '<h3>無權檢視此補件</h3><p>此頁僅開放給簽核人員、上傳者、報價單建立者，或通知對象。</p>'
       . '<button onclick="history.length>1?history.back():window.close()" style="padding:8px 22px;border:none;border-radius:8px;background:#2A3F54;color:#fff;font-size:14px;">返回</button></body></html>';
    exit();
}

// 補件狀態：pending待審 / active已核准 / trash已駁回
$suppStatus = $att['status'];
$canDecide  = $userCanSign && $suppStatus === 'pending' && $latest && $latest['status'] === 'pending';
$statusMap = [
    'pending' => ['待審核', '#c77c1a'],
    'active'  => ['已核准（已放入報價單）', '#169a80'],
    'trash'   => ['已駁回（附件已刪除）', '#e74c3c'],
];
$sm = $statusMap[$suppStatus] ?? ['—', '#8a9bab'];
$dlBase = '../../src/store/Quotation_File_API.php?action=download&quote_no=' . rawurlencode($quoteNo) . '&filename=';
?>
<!DOCTYPE html>
<html lang="zh-TW">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1, viewport-fit=cover">
    <meta name="theme-color" content="#2A3F54">
    <title>報價單補件審核 <?= h($quoteNo) ?></title>
    <link href="../../resource/css/font-awesome.css" rel="stylesheet">
    <style>
        :root{ --accent:#1ABB9C; --accent-d:#169a80; --dark:#2A3F54; --line:#e6ecf1; --text:#34495e; --muted:#8a9bab; --bg:#f4f7f9; --warm:#F0A24B; --warm-d:#a86a1e; }
        *{ box-sizing:border-box; }
        html,body{ margin:0; padding:0; background:var(--bg); color:var(--text); font-family:"Microsoft JhengHei","Helvetica Neue",Arial,sans-serif; font-size:15px; }
        .m-top{ position:sticky; top:0; z-index:20; background:var(--dark); color:#fff; display:flex; align-items:center; gap:10px; padding:calc(env(safe-area-inset-top) + 12px) 16px 12px; }
        .m-top h1{ font-size:17px; margin:0; font-weight:700; flex:1; }
        .m-top .who{ font-size:12px; opacity:.85; }
        .wrap{ max-width:1180px; margin:0 auto; padding:14px; }
        .review-wrap{ display:grid; grid-template-columns:1.5fr 1fr; gap:16px; align-items:start; }
        @media (max-width:860px){ .review-wrap{ grid-template-columns:1fr; } }
        .sec{ background:#fff; border:1px solid var(--line); border-radius:12px; padding:16px; margin-bottom:14px; box-shadow:0 1px 4px rgba(42,63,84,.06); }
        .sec h3{ font-size:14px; color:var(--dark); margin:0 0 12px; font-weight:700; border-left:4px solid var(--accent); padding-left:9px; }
        .sec.supp h3{ border-left-color:var(--warm); }
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
        .att-row{ display:flex; align-items:center; gap:8px; padding:8px 10px; border:1px solid var(--line); border-radius:8px; margin-bottom:6px; font-size:13px; flex-wrap:wrap; }
        .att-row .fn{ font-weight:600; }
        .chip{ font-size:11px; padding:1px 7px; border-radius:10px; background:#eef3f6; color:#5a6b78; }
        .chip.cat{ background:#f5eaddff; color:#8a5a00; } .chip.part{ background:#eee7fb; color:#7a4bb8; }
        .supp-file-box{ background:#fdf6ec; border:1px solid #f0d9b8; border-radius:10px; padding:12px; }
        .lbl{ font-size:13px; font-weight:600; display:block; margin:10px 0 6px; }
        textarea.m-ta{ width:100%; min-height:90px; border:1px solid var(--line); border-radius:10px; padding:11px; font-size:15px; font-family:inherit; }
        textarea.m-ta:focus{ border-color:var(--accent); outline:none; }
        .m-btn{ display:inline-block; border:none; border-radius:10px; padding:11px 22px; font-size:14.5px; font-weight:700; margin-top:10px; margin-right:8px; cursor:pointer; }
        .m-btn-approve{ background:var(--accent); color:#fff; } .m-btn-approve:disabled{ opacity:.6; }
        .m-btn-reject{ background:#fdecea; color:#e74c3c; } .m-btn-reject:disabled{ opacity:.6; }
        .doneok{ color:var(--accent-d); font-weight:700; } .todo{ color:#e74c3c; font-weight:600; }
        .toast{ position:fixed; left:50%; bottom:30px; transform:translateX(-50%); background:rgba(0,0,0,.85); color:#fff; padding:11px 20px; border-radius:24px; font-size:14px; z-index:100; display:none; }
    </style>
</head>
<body>
<div class="m-top">
    <h1><i class="fa fa-paperclip"></i> 報價單補件審核 <?= h($quoteNo) ?></h1>
    <span class="who"><?= h($myName) ?></span>
</div>
<div class="wrap">
<div class="review-wrap">

    <!-- 左欄：報價單原有內容＋原有附件（供確認補什麼） -->
    <div>
        <?php if ($quote): ?>
        <div class="sec">
            <h3><i class="fa fa-info-circle"></i> 報價單資訊</h3>
            <div class="grid">
                <div class="kv"><b>客戶名稱</b><?= h($quote['client_name']) ?></div>
                <div class="kv"><b>報價日期</b><?= h($quote['quote_date']) ?></div>
                <div class="kv"><b>幣　　別</b><?= h($quote['currency']) ?></div>
                <div class="kv"><b>業務人員</b><?= h($quote['created_by_name'] ?: '-') ?></div>
                <div class="kv"><b>總　金　額</b><b style="color:var(--dark);"><?= fmtNum2($quote['total_amount']) ?></b></div>
            </div>
            <?php if ($quote['note']): ?><div class="kv" style="margin-top:8px;"><b>備　　註</b><div class="longtext"><?= h($quote['note']) ?></div></div><?php endif; ?>
        </div>

        <div class="sec">
            <h3><i class="fa fa-list"></i> 報價項目</h3>
            <div class="tbl-scroll"><table>
                <thead><tr><th>#</th><th>料號</th><th>製程</th><th>料號備註</th><th class="r">數量</th><th class="c">單位</th><th class="r">單價</th></tr></thead>
                <tbody>
                <?php foreach ($items as $i => $it): ?>
                    <tr>
                        <td class="c"><?= $i + 1 ?></td>
                        <td><?= h($it['product_id']) ?></td>
                        <td><?= h($it['process_names'] ?: '-') ?></td>
                        <td><?= h($it['specification'] ?: '-') ?></td>
                        <td class="r"><?= fmtNum2($it['quantity']) ?></td>
                        <td class="c"><?= h($it['unit'] ?: 'PCS') ?></td>
                        <td class="r"><?= fmtNum2($it['unit_price']) ?></td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table></div>
        </div>
        <?php else: ?>
        <div class="sec"><div class="kv" style="color:var(--muted);">（找不到對應報價單資料）</div></div>
        <?php endif; ?>

        <div class="sec">
            <h3><i class="fa fa-folder-open-o"></i> 報價單原有附件（<?= count($origAtts) ?>）</h3>
            <?php if ($origAtts): foreach ($origAtts as $oa): ?>
                <div class="att-row">
                    <i class="fa fa-file-o"></i>
                    <a class="fn" href="<?= $dlBase . rawurlencode($oa['filename']) ?>" target="_blank"><?= h($oa['original_name'] ?: $oa['filename']) ?></a>
                    <span class="chip cat"><?= h($catLabel($oa['category_ids'])) ?></span>
                    <span class="chip part">料號：<?= h(suppPartLabel($oa['linked_parts'])) ?></span>
                </div>
            <?php endforeach; else: ?>
                <div class="kv" style="color:var(--muted);">此報價單原本沒有附件。</div>
            <?php endif; ?>
        </div>
    </div>

    <!-- 右欄：本次補件 + 審核處理 -->
    <div>
        <div class="sec supp">
            <h3><i class="fa fa-plus-circle"></i> 本次補件附件
                <span class="tag" style="background:<?= $sm[1] ?>;margin-left:6px;"><?= h($sm[0]) ?></span>
            </h3>
            <div class="supp-file-box">
                <div class="att-row" style="border:none;background:transparent;padding:0;margin-bottom:8px;">
                    <i class="fa fa-file-text-o" style="color:var(--warm-d);"></i>
                    <?php if ($suppStatus === 'trash'): ?>
                        <span class="fn" style="color:var(--muted);text-decoration:line-through;"><?= h($att['original_name'] ?: $att['filename']) ?></span><span class="chip">已刪除</span>
                    <?php else: ?>
                        <a class="fn" href="<?= $dlBase . rawurlencode($att['filename']) ?>" target="_blank"><?= h($att['original_name'] ?: $att['filename']) ?></a>
                    <?php endif; ?>
                </div>
                <div class="kv"><b>類　　別</b><span class="chip cat"><?= h($catLabel($att['category_ids'])) ?></span></div>
                <div class="kv"><b>連結料號</b><span class="chip part"><?= h(suppPartLabel($att['linked_parts'])) ?></span></div>
                <div class="kv"><b>上傳者</b><?= h($att['uploader_name']) ?></div>
            </div>
        </div>

        <div class="sec" id="sec-action">
            <h3><i class="fa fa-pencil-square-o"></i> 審核處理</h3>
            <div id="act-body">
                <?php if ($suppStatus === 'active'): ?>
                    <div class="doneok"><i class="fa fa-check-circle"></i> 已核准，附件已正式放入報價單</div>
                    <?php if ($latest && $latest['approver_name']): ?><div class="kv" style="margin-top:6px;"><b>核准人</b><?= h($latest['approver_name']) ?>　<?= h($latest['decided_at']) ?></div><?php endif; ?>
                <?php elseif ($suppStatus === 'trash'): ?>
                    <div class="todo"><i class="fa fa-times-circle"></i> 已駁回，附件已刪除</div>
                    <?php if ($latest && $latest['approver_name']): ?><div class="kv" style="margin-top:6px;"><b>駁回人</b><?= h($latest['approver_name']) ?>　<?= h($latest['decided_at']) ?></div><?php endif; ?>
                    <?php if (!empty($att['trashed_reason'])): ?><div class="lbl">駁回原因</div><div class="longtext"><?= h($att['trashed_reason']) ?></div><?php endif; ?>
                <?php elseif ($canDecide): ?>
                    <div class="kv" style="margin-bottom:6px;"><?= h($myName) ?>，請審核是否允許此附件放入報價單 <?= h($quoteNo) ?>：</div>
                    <label class="lbl">審核意見 / 駁回原因 <span style="color:var(--muted);font-weight:400;">（駁回必填）</span></label>
                    <textarea id="note-text" class="m-ta" placeholder="駁回請填寫原因，將通知上傳者…"></textarea>
                    <div>
                        <button class="m-btn m-btn-approve act" data-decision="approve"><i class="fa fa-check"></i> 核准</button>
                        <button class="m-btn m-btn-reject act" data-decision="reject"><i class="fa fa-times"></i> 駁回</button>
                    </div>
                <?php else: ?>
                    <div class="kv" style="color:var(--muted);">此補件待簽核人員審核中，您不是簽核人員，僅供檢視。</div>
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
    var ATT_ID   = <?= (int)$attId ?>;
    var EVENT_ID = <?= (int)$eventId ?>;
    var IS_TARGET = <?= $isTarget ? 'true' : 'false' ?>;
    var API_URL = '../../src/store/Quotation_File_API.php';
    var API_RESPOND = '../../src/store/_eventRespond.php';
    $.ajaxSetup({ cache: false });
    function toast(m){ var $t=$('#toast').text(m).fadeIn(120); setTimeout(function(){ $t.fadeOut(300); }, 2200); }

    if (IS_TARGET && EVENT_ID) { $.post(API_RESPOND, { eventid: EVENT_ID, action: 'read' }); }

    $(document).on('click', '.act', function(){
        var decision = $(this).data('decision');
        var note = ($('#note-text').val() || '').trim();
        if (decision === 'reject' && !note) { toast('駁回必須填寫原因'); return; }
        var $btns = $('.act').prop('disabled', true);
        $.post(API_URL, { action:'decide_supplement', attachment_id: ATT_ID, decision: decision, note: note }, function(res){
            if (res && res.success) {
                toast(res.message || '已送出');
                try { if (window.opener && !window.opener.closed) window.opener.postMessage({ type:'quotation_supplement_done', att_id: ATT_ID }, '*'); } catch(e){}
                setTimeout(function(){ location.reload(); }, 700);
            } else {
                $btns.prop('disabled', false);
                toast((res && res.message) || '處理失敗');
            }
        }, 'json').fail(function(){ $btns.prop('disabled', false); toast('連線失敗'); });
    });
});
</script>
</body>
</html>
