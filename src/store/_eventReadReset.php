<?php
// （系統管理員限定）把某人員對某公告的「已閱」重設為未閱 — 供測試通知/已讀流程使用
// 僅允許「純已閱」狀態的人員；已回簽/已回覆（或有回覆附件）者一律拒絕，避免誤刪回覆資料
header('Content-Type: application/json; charset=utf-8');

include("../../src/common/_config.php"); // session_start + $db
require_once __DIR__ . '/../common/rbac.php';

if (!isset($_SESSION['id'])) {
    echo json_encode(['ok' => false, 'msg' => '尚未登入']);
    exit();
}
$isAdmin = in_array('all', rbac_user_features($db, (int)$_SESSION['id']), true); // 最高管理者（系統預設）
if (!$isAdmin) {
    echo json_encode(['ok' => false, 'msg' => '僅系統管理員可執行']);
    exit();
}

$eid = (int)($_POST['eventid'] ?? 0);
$uid = (int)($_POST['userid'] ?? 0);
if ($eid <= 0 || $uid <= 0) {
    echo json_encode(['ok' => false, 'msg' => '參數錯誤']);
    exit();
}

try {
    $db->beginTransaction();

    // 有回簽/回覆（或回覆附件）者不可重設
    $rs = $db->prepare("SELECT id, signed_at, replied_at, reply_content FROM live_event_response WHERE live_event_id = ? AND user_id = ? FOR UPDATE");
    $rs->execute([$eid, $uid]);
    $resp = $rs->fetch(PDO::FETCH_ASSOC);
    if ($resp) {
        $hasFiles = (int)$db->query("SELECT COUNT(*) FROM live_event_resp_file WHERE response_id = " . (int)$resp['id'])->fetchColumn() > 0;
        if (!empty($resp['signed_at']) || !empty($resp['replied_at']) || trim((string)$resp['reply_content']) !== '' || $hasFiles) {
            $db->rollBack();
            echo json_encode(['ok' => false, 'msg' => '該人員已回簽/回覆，不可改為未閱']);
            exit();
        }
        // 純已閱的回應列直接刪除（無回簽/回覆內容/附件）
        $db->prepare("DELETE FROM live_event_response WHERE id = ?")->execute([(int)$resp['id']]);
    }

    // 重設鈴鐺已讀旗標（保留列本身，僅清旗標與時間）
    $db->prepare("UPDATE live_event_for_user SET oready_read = 0, read_at = NULL WHERE live_event_id = ? AND user_id = ?")->execute([$eid, $uid]);

    $db->commit();
    echo json_encode(['ok' => true]);
} catch (PDOException $e) {
    if ($db->inTransaction()) $db->rollBack();
    echo json_encode(['ok' => false, 'msg' => '資料庫錯誤']);
}
