<?php
/**
 * 批次修改通知對象（公告 / 通知管理頁）
 *
 * 使用者拍板的兩條規則：
 *   1. 只適用於「通知對象完全相同」的那幾則 —— 對象不同的一起改，等於把原本各自的設定洗掉，
 *      所以 action=check 會先比對，只要有一則不一樣就整批擋下並指出是哪幾則不同。
 *   2. 套用之後：新加進來的人要收到通知，被移除的人要把已經發出去的通知取消掉。
 *
 * 「相同」的定義＝對象清單與每個對象的通知方式都一樣（例如同樣是「業務部」，
 * 一則設回簽、一則設已閱，就不算相同——一起改會讓其中一則的義務等級被改掉）。
 *
 * action=check  傳 ids[]，回傳能不能一起改、目前共同的對象與通知方式
 * action=apply  傳 ids[] + targets(JSON) + modes(JSON)，逐則套用
 */
header('Content-Type: application/json; charset=utf-8');

include("../../src/common/_config.php"); // session_start + $db
require_once __DIR__ . '/../common/rbac.php';
require_once __DIR__ . '/../common/notice_mode_lib.php';
require_once __DIR__ . '/../common/notice_event_lib.php';   // eg_save_event_targets / 快照 / 修改歷史
require_once __DIR__ . '/../push/push_send.php';

if (!isset($_SESSION['id'])) { echo json_encode(['ok' => false, 'msg' => '尚未登入']); exit(); }
$uid      = (int)$_SESSION['id'];
$features = rbac_user_features($db, $uid);
$isAdmin  = rbac_has($features, 'all');
if (!$isAdmin && !rbac_has($features, 'notice_edit')) {
    echo json_encode(['ok' => false, 'msg' => '無修改公告 / 通知的權限']);
    exit();
}

$action = $_POST['action'] ?? $_GET['action'] ?? 'check';
$idsRaw = $_POST['ids'] ?? $_GET['ids'] ?? '[]';
$ids    = json_decode((string)$idsRaw, true);
$ids    = is_array($ids) ? array_values(array_unique(array_filter(array_map('intval', $ids)))) : [];
if (count($ids) < 2) { echo json_encode(['ok' => false, 'msg' => '請至少勾選 2 則才需要批次修改']); exit(); }
if (count($ids) > 200) { echo json_encode(['ok' => false, 'msg' => '一次最多處理 200 則']); exit(); }

/** 逐則權限：只有系統管理員可改任何公告；其他人僅本人建立或本人為共同編輯者（與單筆編輯同一套規則） */
function ebt_can_edit(PDO $db, array $ev, int $uid, bool $isAdmin, array $features): array
{
    if (($ev['source'] ?? '') === '訂單變更') {
        return [false, '來源「訂單變更」的通知已鎖定，請至訂單追蹤頁作廢該變更單'];
    }
    if (($ev['ref_type'] ?? '') === 'QA' && (int)($ev['ref_id'] ?? 0) > 0) {
        return [false, '來源「品質異常單」的通知請至異常單修改'];
    }
    if ($isAdmin) return [true, ''];
    if ((int)$ev['created_by'] === $uid && rbac_has($features, 'notice_edit')) return [true, ''];
    if (eg_user_is_event_editor($db, (int)$ev['id'], $uid)) return [true, ''];
    return [false, '您不是此公告的公告者或共同編輯者'];
}

/** 該則目前的對象＋通知方式，整理成可比對的字串（順序無關） */
function ebt_signature(PDO $db, int $eid): array
{
    $st = $db->prepare("SELECT target_type, target_id, mode FROM live_event_target WHERE live_event_id = ?");
    $st->execute([$eid]);
    $items = [];
    foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $t) {
        $code = ($t['target_type'] === 'all') ? 'all' : ($t['target_type'] . '-' . (int)$t['target_id']);
        $items[$code] = eg_notice_mode_valid($t['mode']);
    }
    ksort($items);
    $parts = [];
    foreach ($items as $c => $m) $parts[] = $c . ':' . $m;
    return ['items' => $items, 'sig' => implode('|', $parts)];
}

/** 對象代碼 → 顯示名稱（跳窗上要看得懂改的是誰） */
function ebt_labels(PDO $db, array $codes): array
{
    $out = [];
    $deptIds = []; $statIds = []; $userIds = [];
    foreach ($codes as $c) {
        if ($c === 'all') { $out['all'] = '全體'; continue; }
        if (strpos($c, 'dept-') === 0)   $deptIds[] = (int)substr($c, 5);
        if (strpos($c, 'status-') === 0) $statIds[] = (int)substr($c, 7);
        if (strpos($c, 'user-') === 0)   $userIds[] = (int)substr($c, 5);
    }
    if ($deptIds) foreach ($db->query("SELECT id,name FROM department WHERE id IN (" . implode(',', $deptIds) . ")")->fetchAll(PDO::FETCH_ASSOC) as $r) $out['dept-' . $r['id']] = $r['name'];
    if ($statIds) foreach ($db->query("SELECT id,title FROM user_status WHERE id IN (" . implode(',', $statIds) . ")")->fetchAll(PDO::FETCH_ASSOC) as $r) $out['status-' . $r['id']] = $r['title'];
    if ($userIds) foreach ($db->query("SELECT id,user_cname FROM `user` WHERE id IN (" . implode(',', $userIds) . ")")->fetchAll(PDO::FETCH_ASSOC) as $r) $out['user-' . $r['id']] = $r['user_cname'];
    return $out;
}

try {
    $in = implode(',', $ids);
    $events = $db->query("SELECT id, title, source, created_by, ref_type, ref_id, eventdate FROM live_event WHERE id IN ($in)")->fetchAll(PDO::FETCH_ASSOC);
    $byId = [];
    foreach ($events as $e) $byId[(int)$e['id']] = $e;

    // 逐則檢查權限與鎖定
    $blocked = [];
    foreach ($ids as $eid) {
        if (!isset($byId[$eid])) { $blocked[] = ['id' => $eid, 'title' => '（已不存在）', 'why' => '找不到此公告 / 通知']; continue; }
        [$ok, $why] = ebt_can_edit($db, $byId[$eid], $uid, $isAdmin, $features);
        if (!$ok) $blocked[] = ['id' => $eid, 'title' => $byId[$eid]['title'], 'why' => $why];
    }
    if ($blocked) {
        echo json_encode(['ok' => false, 'code' => 'BLOCKED', 'msg' => '有 ' . count($blocked) . ' 則不能批次修改', 'blocked' => $blocked], JSON_UNESCAPED_UNICODE);
        exit();
    }

    // 對象是否完全相同
    $sigs = []; $first = null; $diff = [];
    foreach ($ids as $eid) {
        $s = ebt_signature($db, $eid);
        $sigs[$eid] = $s;
        if ($first === null) $first = $s['sig'];
        elseif ($s['sig'] !== $first) $diff[] = ['id' => $eid, 'title' => $byId[$eid]['title']];
    }
    if ($diff) {
        echo json_encode([
            'ok' => false, 'code' => 'NOT_SAME',
            'msg' => '批次修改只適用於「通知對象完全相同」的公告 / 通知；勾選的這幾則對象不一致（含通知方式），請分開修改。',
            'diff' => $diff,
        ], JSON_UNESCAPED_UNICODE);
        exit();
    }

    $common = $sigs[$ids[0]]['items'];

    if ($action === 'check') {
        $labels = ebt_labels($db, array_keys($common));
        $rows = [];
        foreach ($common as $code => $mode) {
            $rows[] = ['code' => $code, 'label' => $labels[$code] ?? $code, 'mode' => $mode, 'mode_label' => eg_notice_mode_label($mode)];
        }
        echo json_encode([
            'ok' => true,
            'count' => count($ids),
            'targets' => $rows,
            'titles' => array_map(function ($e) { return ['id' => (int)$e['id'], 'title' => $e['title'], 'eventdate' => $e['eventdate']]; }, array_values($byId)),
        ], JSON_UNESCAPED_UNICODE);
        exit();
    }

    if ($action === 'apply') {
        $targets = json_decode((string)($_POST['targets'] ?? '[]'), true);
        $modes   = json_decode((string)($_POST['modes'] ?? '{}'), true);
        $targets = is_array($targets) ? array_values(array_filter(array_map('strval', $targets))) : [];
        $modes   = is_array($modes) ? $modes : [];
        if (empty($targets)) { echo json_encode(['ok' => false, 'msg' => '請至少選擇一個通知對象']); exit(); }

        $addedAll = []; $removedAll = [];   // event_id => [uid,…]
        $db->beginTransaction();
        foreach ($ids as $eid) {
            $before    = eg_event_snapshot($db, $eid);
            $oldPeople = eg_push_event_recipients($db, $eid);

            // 對象寫入一律走共用函式（與單筆編輯同一份實作，不另寫一套）
            $primaryStatus = eg_save_event_targets($db, $eid, $targets, $modes);
            $db->prepare("UPDATE live_event SET status = ? WHERE id = ?")->execute([$primaryStatus, $eid]);

            $newPeople = eg_push_event_recipients($db, $eid);
            $addedAll[$eid]   = array_values(array_diff($newPeople, $oldPeople));
            $removedAll[$eid] = array_values(array_diff($oldPeople, $newPeople));

            eg_log_event_history($db, $eid, 'update', $uid, $before, eg_event_snapshot($db, $eid));
        }
        $db->commit();

        // 推播與 Telegram 一律在 commit 之後才發：交易還沒成功就先通知，回滾了收件人也收回不來
        $sent = 0; $cancelled = 0;
        foreach ($ids as $eid) {
            try {
                if (!empty($addedAll[$eid]))   { eg_push_event_notify($db, $eid, $addedAll[$eid], false); $sent += count($addedAll[$eid]); }
                if (!empty($removedAll[$eid])) { eg_push_event_cancel($db, $eid, $removedAll[$eid]);      $cancelled += count($removedAll[$eid]); }
            } catch (Throwable $e) { error_log('[batchTargets] push failed: ' . $e->getMessage()); }
            try {
                require_once __DIR__ . '/../../telegram/notify_event.php';
                if (!empty($addedAll[$eid]))   eg_telegram_event_notify($db, $eid, $addedAll[$eid], false);
                if (!empty($removedAll[$eid])) eg_telegram_retract_event_for($db, $eid, $removedAll[$eid]);
            } catch (Throwable $e) { error_log('[batchTargets] telegram failed: ' . $e->getMessage()); }
        }

        // 稽核：一次改很多則，一定要留下改了哪幾則、誰被加進來、誰被移除
        try {
            $db->prepare("INSERT INTO audit_log (action_type, target_type, target_id, target_name, changes, user_id, operator, created_at)
                          VALUES ('update','notice_batch_targets',?,?,?,?,?,NOW())")
               ->execute([
                   implode(',', $ids),
                   '批次修改通知對象（' . count($ids) . ' 則）',
                   json_encode(['targets' => $targets, 'modes' => $modes, 'added' => $addedAll, 'removed' => $removedAll], JSON_UNESCAPED_UNICODE),
                   $uid,
                   (string)($_SESSION['user_cname'] ?? $_SESSION['userName'] ?? ('user#' . $uid)),
               ]);
        } catch (Throwable $e) { error_log('[batchTargets] audit failed: ' . $e->getMessage()); }

        echo json_encode([
            'ok' => true, 'updated' => count($ids),
            'notified' => $sent, 'cancelled' => $cancelled,
        ], JSON_UNESCAPED_UNICODE);
        exit();
    }

    echo json_encode(['ok' => false, 'msg' => '無效的操作']);
} catch (Throwable $e) {
    if ($db->inTransaction()) $db->rollBack();
    error_log('[batchTargets] ' . $e->getMessage());
    echo json_encode(['ok' => false, 'msg' => '資料庫錯誤']);
}
