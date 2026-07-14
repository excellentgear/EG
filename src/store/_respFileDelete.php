<?php
// 刪除單一「回覆附件」（AJAX）
// 權限規則（2026-07-07 使用者定案）：只有「上傳者本人」與「最高管理者（RBAC 'all'，系統預設）」可刪除。
//   - 上傳者本人：回覆期限已過則不可刪（與「期限後不可再修改回覆」一致）；管理者不受期限限制。
header('Content-Type: application/json; charset=utf-8');
include("../../src/common/_config.php"); // session_start + $db
require_once __DIR__ . '/../common/rbac.php';
require_once __DIR__ . '/../common/notice_files.php';

if (!isset($_SESSION['id'])) { echo json_encode(['ok' => false, 'msg' => '尚未登入']); exit(); }
$uid = (int)$_SESSION['id'];

$id = (int)($_POST['id'] ?? 0);
if ($id <= 0) { echo json_encode(['ok' => false, 'msg' => '參數錯誤']); exit(); }

try {
    $q = $db->prepare("SELECT rf.id, rf.file_name, rf.file_path, r.user_id AS owner_id, r.live_event_id,
                              le.reply_deadline
                       FROM live_event_resp_file rf
                       JOIN live_event_response r ON r.id = rf.response_id
                       JOIN live_event le ON le.id = r.live_event_id
                       WHERE rf.id = ?");
    $q->execute([$id]);
    $f = $q->fetch(PDO::FETCH_ASSOC);
    if (!$f) { echo json_encode(['ok' => false, 'msg' => '找不到附件（可能已被刪除）']); exit(); }

    $features = rbac_user_features($db, $uid);
    $isAdmin  = in_array('all', $features, true);
    $isOwner  = ((int)$f['owner_id'] === $uid);

    if (!$isAdmin && !$isOwner) { echo json_encode(['ok' => false, 'msg' => '只有上傳者本人或系統管理者可刪除此附件']); exit(); }
    if (!$isAdmin && !empty($f['reply_deadline']) && $f['reply_deadline'] < date('Y-m-d')) {
        echo json_encode(['ok' => false, 'msg' => '已超過回覆期限（' . $f['reply_deadline'] . '），無法刪除附件，如需刪除請洽系統管理者']);
        exit();
    }

    $abs = eg_notice_abs_path($f['file_path']);
    if ($abs && is_file($abs)) @unlink($abs);
    $db->prepare("DELETE FROM live_event_resp_file WHERE id = ?")->execute([$id]);
    // 若整份公告已無任何檔案且不需回覆 → 一併清掉空資料夾
    eg_notice_cleanup_event_folder($db, (int)$f['live_event_id']);
    echo json_encode(['ok' => true]);
} catch (PDOException $e) {
    echo json_encode(['ok' => false, 'msg' => '資料庫錯誤']);
}
