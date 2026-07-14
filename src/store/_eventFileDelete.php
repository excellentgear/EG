<?php
// 刪除單一公告附件（AJAX）；需 notice_edit 權限
header('Content-Type: application/json; charset=utf-8');
include("../../src/common/_config.php"); // session_start + $db
require_once __DIR__ . '/../common/rbac.php';
require_once __DIR__ . '/../common/notice_files.php';

if (!isset($_SESSION['id'])) { echo json_encode(['ok' => false, 'msg' => '尚未登入']); exit(); }
$features = rbac_user_features($db, (int)$_SESSION['id']);
if (!rbac_has($features, 'notice_edit')) { echo json_encode(['ok' => false, 'msg' => '無編輯權限']); exit(); }

$id = (int)($_POST['id'] ?? 0);
if ($id <= 0) { echo json_encode(['ok' => false, 'msg' => '參數錯誤']); exit(); }

try {
    $q = $db->prepare("SELECT * FROM live_event_file WHERE id = ?");
    $q->execute([$id]);
    $f = $q->fetch(PDO::FETCH_ASSOC);
    if (!$f) { echo json_encode(['ok' => false, 'msg' => '找不到附件']); exit(); }
    $abs = eg_notice_abs_path($f['file_path']);
    if ($abs && is_file($abs)) @unlink($abs);
    // 一併刪除角落標註檢視快取版（欄位不存在的舊資料自動略過）
    if (!empty($f['preview_path']) && is_file($f['preview_path'])) @unlink($f['preview_path']);
    $db->prepare("DELETE FROM live_event_file WHERE id = ?")->execute([$id]);
    // 若刪到空且不需回覆 → 一併刪除空資料夾
    eg_notice_cleanup_event_folder($db, (int)$f['live_event_id']);
    echo json_encode(['ok' => true]);
} catch (PDOException $e) {
    echo json_encode(['ok' => false, 'msg' => '資料庫錯誤']);
}
