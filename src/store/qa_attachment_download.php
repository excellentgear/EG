<?php
// 品質異常單附件下載（qa_abnormal_attachments，實體檔存於內網路徑）
// 存取限制與 qa_abnormal_view.php 相同：開單人／追蹤人員／通知對象／管理者
session_start();
if (!isset($_SESSION['id'])) { http_response_code(401); exit('未登入'); }

include_once '../common/DBConnection.php';
$conn = new DBConnection();
$db   = $conn->getPDO();
$uid  = (int)$_SESSION['id'];

$id = (int)($_GET['id'] ?? 0);
if (!$id) { http_response_code(400); exit('缺少 id'); }

$st = $db->prepare("SELECT a.file_name, a.file_path, a.abnormal_order_id, a.created_by AS att_created_by,
                           o.created_by, o.notify_event_id
                    FROM qa_abnormal_attachments a
                    LEFT JOIN qa_abnormal_order o ON o.id = a.abnormal_order_id
                    WHERE a.id=?");
$st->execute([$id]);
$f = $st->fetch(PDO::FETCH_ASSOC);
if (!$f) { http_response_code(404); exit('找不到附件'); }

// ── 權限：開單人／上傳者(暫存附件)／追蹤人員／通知對象／管理者 ──
$allowed = ((int)$f['created_by'] === $uid) || ((int)$f['att_created_by'] === $uid);
if (!$allowed && $f['abnormal_order_id']) {
    try {
        $q = $db->prepare("SELECT 1 FROM qa_abnormal_follower WHERE abnormal_order_id=? AND user_id=? LIMIT 1");
        $q->execute([(int)$f['abnormal_order_id'], $uid]);
        $allowed = (bool)$q->fetchColumn();
    } catch (Throwable $e) {}
}
if (!$allowed && !empty($f['notify_event_id'])) {
    $myStatus = [-1];
    foreach (['status','status2','status3'] as $sk) if (!empty($_SESSION[$sk])) $myStatus[] = (int)$_SESSION[$sk];
    $myDept = [-1];
    $ds = $db->prepare("SELECT department_id FROM user_department_position_map WHERE user_id=?");
    $ds->execute([$uid]);
    foreach ($ds->fetchAll(PDO::FETCH_COLUMN) as $d) if ($d !== null) $myDept[] = (int)$d;
    $ts = $db->prepare("SELECT target_type, target_id FROM live_event_target WHERE live_event_id=?");
    $ts->execute([(int)$f['notify_event_id']]);
    foreach ($ts->fetchAll(PDO::FETCH_ASSOC) as $t) {
        if (($t['target_type']==='all')
         || ($t['target_type']==='status' && in_array((int)$t['target_id'], $myStatus, true))
         || ($t['target_type']==='dept'   && in_array((int)$t['target_id'], $myDept, true))
         || ($t['target_type']==='user'   && (int)$t['target_id'] === $uid)) { $allowed = true; break; }
    }
}
if (!$allowed) {
    try {
        $q = $db->prepare("SELECT 1 FROM user_roles ur JOIN role_features rf ON rf.role_id = ur.role_id
                           WHERE ur.user_id=? AND rf.feature_code='all' LIMIT 1");
        $q->execute([$uid]);
        $allowed = (bool)$q->fetchColumn();
    } catch (Throwable $e) {}
}
if (!$allowed) { http_response_code(403); exit('無權下載此附件（僅開單人、追蹤人員與通知對象）'); }

if (!is_file($f['file_path'])) { http_response_code(404); exit('找不到附件（附件存於內網，外網需連 VPN）'); }

$ext = strtolower(pathinfo($f['file_name'], PATHINFO_EXTENSION));
$inlineTypes = ['jpg'=>'image/jpeg','jpeg'=>'image/jpeg','png'=>'image/png','gif'=>'image/gif','webp'=>'image/webp','bmp'=>'image/bmp','pdf'=>'application/pdf'];
$mime = $inlineTypes[$ext] ?? 'application/octet-stream';
$disp = isset($inlineTypes[$ext]) && empty($_GET['dl']) ? 'inline' : 'attachment';

header('Content-Type: ' . $mime);
header('Content-Length: ' . filesize($f['file_path']));
header("Content-Disposition: $disp; filename*=UTF-8''" . rawurlencode($f['file_name']));
header('Cache-Control: private, max-age=0');
readfile($f['file_path']);
