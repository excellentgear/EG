<?php
// 公告/回覆 附件的預覽或下載
// GET t=e|r|p (event附件 / reply附件 / event附件的「角落標註檢視快取版」)  id=檔案id  [dl=1 下載]
include("../../src/common/_config.php"); // session_start + $db
require_once __DIR__ . '/../common/notice_files.php';

if (!isset($_SESSION['id'])) { http_response_code(403); echo '尚未登入'; exit(); }

$t  = in_array(($_GET['t'] ?? 'e'), ['e', 'r', 'p'], true) ? $_GET['t'] : 'e';
$id = (int)($_GET['id'] ?? 0);
$dl = !empty($_GET['dl']);
if ($id <= 0) { http_response_code(400); echo '參數錯誤'; exit(); }

try {
    if ($t === 'r') {
        $st = $db->prepare("SELECT file_name, file_path FROM live_event_resp_file WHERE id = ?");
    } else {
        $st = $db->prepare("SELECT * FROM live_event_file WHERE id = ?");
    }
    $st->execute([$id]);
    $f = $st->fetch(PDO::FETCH_ASSOC);
} catch (PDOException $e) { http_response_code(500); echo '資料庫錯誤'; exit(); }

if (!$f) { http_response_code(404); echo '找不到附件'; exit(); }

// t=p：優先用角落標註快取版（標籤+備注已印在檔上）；沒有快取版就退回原檔
if ($t === 'p' && !empty($f['preview_path']) && is_file($f['preview_path'])) {
    $f['file_path'] = $f['preview_path'];
}

$abs = eg_notice_abs_path($f['file_path']);
if (!$abs || !is_file($abs)) { http_response_code(404); echo '檔案不存在'; exit(); }

$ext = strtolower(pathinfo($f['file_name'], PATHINFO_EXTENSION));
$inline = !$dl && eg_notice_is_previewable($ext);

header('Content-Type: ' . eg_notice_mime($ext));
header('Content-Length: ' . filesize($abs));
$dispo = $inline ? 'inline' : 'attachment';
// 檔名支援中文
$fn = rawurlencode($f['file_name']);
header("Content-Disposition: $dispo; filename=\"" . $f['file_name'] . "\"; filename*=UTF-8''" . $fn);
header('X-Content-Type-Options: nosniff');
readfile($abs);
exit();
