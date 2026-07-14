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

try {
    // 先檢查是否已有已讀紀錄，避免重複 INSERT
    $check = $db->prepare("SELECT id FROM live_event_for_user WHERE user_id = :uid AND live_event_id = :eid LIMIT 1");
    $check->execute([':uid' => $user_id, ':eid' => $event_id]);
    $row = $check->fetch(PDO::FETCH_ASSOC);

    if ($row) {
        // 已存在則確保標記為已讀（補上已讀時間，若原本沒有）
        $upd = $db->prepare("UPDATE live_event_for_user SET oready_read = 1, read_at = COALESCE(read_at, NOW()) WHERE id = :id");
        $upd->execute([':id' => $row['id']]);
    } else {
        $ins = $db->prepare("INSERT INTO live_event_for_user (user_id, live_event_id, oready_read, read_at) VALUES (:uid, :eid, 1, NOW())");
        $ins->execute([':uid' => $user_id, ':eid' => $event_id]);
    }

    echo json_encode(['ok' => true]);
} catch (PDOException $e) {
    echo json_encode(['ok' => false, 'msg' => '資料庫錯誤']);
}
