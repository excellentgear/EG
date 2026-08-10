<?php
// 被通知者回應：已閱(read) / 回簽(sign) / 回覆+回簽(reply，含附件)
header('Content-Type: application/json; charset=utf-8');
include("../../src/common/_config.php"); // session_start + $db
require_once __DIR__ . '/../common/notice_files.php';

if (!isset($_SESSION['id'])) { echo json_encode(['ok' => false, 'msg' => '尚未登入']); exit(); }
$uid = (int)$_SESSION['id'];
$eid = (int)($_POST['eventid'] ?? 0);
$action = $_POST['action'] ?? '';
if ($eid <= 0 || !in_array($action, ['read', 'sign', 'reply'], true)) { echo json_encode(['ok' => false, 'msg' => '參數錯誤']); exit(); }

// 共用帳號代成員已閱/回簽/回覆（ai-rules/13）：須輸入該員工本人密碼；
// 紀錄一律記在員工本人身上，signed_via 留下「經由哪個共用帳號」。
require_once __DIR__ . '/../common/shared_account_lib.php';
$__actor = eg_shared_resolve_actor($db, $uid, (int)($_POST['for_uid'] ?? 0), (string)($_POST['member_password'] ?? ''));
if (!$__actor['ok']) {
    echo json_encode(['ok' => false, 'need_password' => ($__actor['msg'] === 'NEED_PASSWORD'), 'msg' => ($__actor['msg'] === 'NEED_PASSWORD' ? '請輸入本人密碼' : $__actor['msg'])]);
    exit();
}
$uid = $__actor['uid'];
$via = $__actor['via'];

try {
    $ev = $db->prepare("SELECT * FROM live_event WHERE id = ?");
    $ev->execute([$eid]);
    $event = $ev->fetch(PDO::FETCH_ASSOC);
    if (!$event) { echo json_encode(['ok' => false, 'msg' => '找不到公告']); exit(); }

    $deadlinePassed = !empty($event['reply_deadline']) && $event['reply_deadline'] < date('Y-m-d');
    if ($deadlinePassed && ($action === 'sign' || $action === 'reply')) {
        echo json_encode(['ok' => false, 'msg' => '已超過回覆/回簽期限（' . $event['reply_deadline'] . '），無法再' . ($action === 'reply' ? '回覆' : '回簽')]);
        exit();
    }
    if ($action === 'reply' && trim($_POST['reply_content'] ?? '') === '') {
        echo json_encode(['ok' => false, 'msg' => '請輸入回覆內容']);
        exit();
    }

    // 取得/建立回應列
    $rs = $db->prepare("SELECT * FROM live_event_response WHERE live_event_id = ? AND user_id = ?");
    $rs->execute([$eid, $uid]);
    $row = $rs->fetch(PDO::FETCH_ASSOC);
    if (!$row) {
        $db->prepare("INSERT INTO live_event_response (live_event_id, user_id, read_at, signed_via) VALUES (?,?,NOW(),?)")->execute([$eid, $uid, $via]);
        $rid = (int)$db->lastInsertId();
        $row = ['id' => $rid, 'read_at' => date('Y-m-d H:i:s'), 'reply_folder' => null];
    } else {
        $rid = (int)$row['id'];
        if (empty($row['read_at'])) $db->prepare("UPDATE live_event_response SET read_at=NOW() WHERE id=?")->execute([$rid]);
        if ($via !== null) $db->prepare("UPDATE live_event_response SET signed_via = COALESCE(signed_via, ?) WHERE id=?")->execute([$via, $rid]);
    }

    // 同步標記置頂列鈴鐺已讀（live_event_for_user）：
    // 鈴鐺未讀判定看此表，若只寫 live_event_response，回覆/回簽後鈴鐺通知不會消失
    $chk = $db->prepare("SELECT id FROM live_event_for_user WHERE user_id = ? AND live_event_id = ? LIMIT 1");
    $chk->execute([$uid, $eid]);
    $frId = $chk->fetchColumn();
    if ($frId) {
        $db->prepare("UPDATE live_event_for_user SET oready_read = 1, read_at = COALESCE(read_at, NOW()), signed_via = COALESCE(signed_via, ?) WHERE id = ?")->execute([$via, $frId]);
    } else {
        $db->prepare("INSERT INTO live_event_for_user (user_id, live_event_id, oready_read, read_at, signed_via) VALUES (?,?,1,NOW(),?)")->execute([$uid, $eid, $via]);
    }

    if ($action === 'sign' || $action === 'reply') {
        $db->prepare("UPDATE live_event_response SET signed_at = COALESCE(signed_at, NOW()) WHERE id = ?")->execute([$rid]);
    }

    if ($action === 'reply') {
        $content = trim($_POST['reply_content']);
        $db->prepare("UPDATE live_event_response SET reply_content = ?, replied_at = NOW() WHERE id = ?")->execute([$content, $rid]);

        // 回覆附件：存到 {公告}\回覆附件\{公告編號}-{回覆人}-{流水號}
        if (!empty($_FILES['reply_files']['name'][0])) {
            $replyDir = $row['reply_folder'] ?? null;
            if (!$replyDir) {
                $replier = $_SESSION['user_cname'] ?? ('U' . $uid);
                $seq = (int)$db->query("SELECT COUNT(*) FROM live_event_response WHERE live_event_id = " . (int)$eid . " AND reply_folder IS NOT NULL")->fetchColumn() + 1;
                $replyDir = eg_notice_reply_dir($db, $event['event_no'] ?: ('EV' . $eid), $replier, $seq);
                $db->prepare("UPDATE live_event_response SET reply_folder = ? WHERE id = ?")->execute([$replyDir, $rid]);
            }
            foreach (eg_notice_save_files($_FILES['reply_files'], $replyDir) as $sf) {
                $db->prepare("INSERT INTO live_event_resp_file (response_id, file_name, file_path) VALUES (?,?,?)")->execute([$rid, $sf['name'], $sf['path']]);
            }
        }
    }

    // 品質異常單通知(ref_type='QA')的回簽/回覆 →
    //   1) 回覆內容同步回寫異常單流程(qa_abnormal_order_flow)，資料保存在異常單內
    //   2) 通知開單人＋追蹤人員（含回覆內容；失敗不影響回覆本身）
    if (($action === 'sign' || $action === 'reply')
        && ($event['ref_type'] ?? '') === 'QA' && (int)($event['ref_id'] ?? 0) > 0) {
        try {
            require_once __DIR__ . '/../common/qa_notify.php';
            $orderId = (int)$event['ref_id'];
            $replyContent = $action === 'reply' ? trim($_POST['reply_content'] ?? '') : '';
            eg_qa_sync_flow_reply($db, $orderId, $uid, $replyContent !== '' ? $replyContent : null);
            eg_qa_notify_reply($db, $orderId, $uid, $action, $replyContent);
        } catch (Throwable $e) {
            error_log('[qa_notify] respond hook failed: ' . $e->getMessage());
        }
    }

    // 會議記錄項目確認通知(ref_type='MEETING_ITEM_CONFIRM')的回簽/回覆 → 寫回 meeting_item 的確認簽名(任一人回覆即完成)。
    // 2026-08-06改版(使用者明確要求)：通知一律用 reply 模式(見 meeting_notify_item_owners)，讓對方留下回覆內容，
    // 顯示在會議記錄項目下方；仍相容 sign(舊資料或其他呼叫路徑)，此時無回覆內容。
    if (($action === 'sign' || $action === 'reply') && ($event['ref_type'] ?? '') === 'MEETING_ITEM_CONFIRM' && (int)($event['ref_id'] ?? 0) > 0) {
        try {
            require_once __DIR__ . '/../common/meeting_lib.php';
            // 2026-08-10使用者實測回報：不同帳號登入回覆時，「確認簽名/回簽狀態」顯示的姓名都不對，查出來是
            // $_SESSION['user_cname'] 這個鍵只有走過少數幾支舊版角色切換頁(_setupUser.php等)才會被寫入，
            // 不是每個登入流程都會設定，沒設定時就會落到 'U'.$uid 這種假名字。$uid 本身(來自$_SESSION['id'])
            // 是可靠的，改用它現查 user 表拿真實姓名，不要信任 user_cname 這個不可靠的 session 值。
            $uname = 'U' . $uid;
            try {
                $unSt = $db->prepare("SELECT user_cname FROM `user` WHERE id=?");
                $unSt->execute([$uid]);
                $real = $unSt->fetchColumn();
                if ($real !== false && $real !== null && $real !== '') $uname = (string)$real;
            } catch (Throwable $e2) {}
            $replyContent = $action === 'reply' ? trim($_POST['reply_content'] ?? '') : null;
            meeting_item_confirm_via_notify($db, (int)$event['ref_id'], $uid, $uname, $replyContent !== '' ? $replyContent : null);
        } catch (Throwable $e) {
            error_log('[meeting_notify] respond hook failed: ' . $e->getMessage());
        }
    }

    echo json_encode(['ok' => true]);
} catch (PDOException $e) {
    echo json_encode(['ok' => false, 'msg' => '資料庫錯誤']);
}
