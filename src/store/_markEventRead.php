<?php
// 將某筆公告/通知標記為已讀（供置頂列跳窗「確認」以 Ajax 呼叫，不跳頁）
header('Content-Type: application/json; charset=utf-8');

include("../../src/common/_config.php"); // 內含 session_start 與 $db

$user_id  = $_SESSION['id'] ?? null;
$event_id = isset($_POST['eventid']) ? (int)$_POST['eventid'] : 0;

if (!$user_id || $event_id <= 0) {
    echo json_encode(['ok' => false, 'msg' => '參數錯誤']);
    exit();
}

// 共用帳號代成員標已閱（ai-rules/13）：須輸入該員工本人密碼，紀錄記在員工身上、留 signed_via。
// 不輸密碼＝只是看過，不寫任何已閱紀錄（否則已讀名單會變成「共用帳號已閱」而失真）。
require_once __DIR__ . '/../common/shared_account_lib.php';
$for_uid  = isset($_POST['for_uid']) ? (int)$_POST['for_uid'] : 0;
$actor = eg_shared_resolve_actor($db, (int)$user_id, $for_uid, (string)($_POST['member_password'] ?? ''));
if (!$actor['ok']) {
    echo json_encode(['ok' => false, 'need_password' => ($actor['msg'] === 'NEED_PASSWORD'), 'msg' => ($actor['msg'] === 'NEED_PASSWORD' ? '請輸入本人密碼' : $actor['msg'])]);
    exit();
}
$user_id = $actor['uid'];
$via     = $actor['via'];

try {
    // 先檢查是否已有已讀紀錄，避免重複 INSERT
    $check = $db->prepare("SELECT id FROM live_event_for_user WHERE user_id = :uid AND live_event_id = :eid LIMIT 1");
    $check->execute([':uid' => $user_id, ':eid' => $event_id]);
    $row = $check->fetch(PDO::FETCH_ASSOC);

    if ($row) {
        // 已存在則確保標記為已讀（補上已讀時間，若原本沒有）
        $upd = $db->prepare("UPDATE live_event_for_user SET oready_read = 1, read_at = COALESCE(read_at, NOW()), signed_via = COALESCE(signed_via, :via) WHERE id = :id");
        $upd->execute([':id' => $row['id'], ':via' => $via]);
    } else {
        $ins = $db->prepare("INSERT INTO live_event_for_user (user_id, live_event_id, oready_read, read_at, signed_via) VALUES (:uid, :eid, 1, NOW(), :via)");
        $ins->execute([':uid' => $user_id, ':eid' => $event_id, ':via' => $via]);
    }

    echo json_encode(['ok' => true]);
} catch (PDOException $e) {
    echo json_encode(['ok' => false, 'msg' => '資料庫錯誤']);
}
