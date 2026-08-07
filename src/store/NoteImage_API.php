<?php
/**
 * NoteImage_API.php — 備註圖片（note_images）讀檔端點
 *
 * 為什麼要有這支：這些圖原本是讓瀏覽器直連 Apache 的 /nas 別名（notes_url_dir 前綴），
 * 因此存放位置永遠被綁死在磁碟機代號 Z: 上——瀏覽器讀不到 UNC 路徑。
 * 改由 PHP 讀檔後，位置只由 notes_nas_dir 一個設定決定，換 NAS 不必改 httpd.conf、
 * 也不必重啟 Apache（CLAUDE.md 鐵律5：附件下載一律走模組 API，不要另設 URL 前綴）。
 *
 * 用法：NoteImage_API.php?f=<file_name>   （inline 輸出，可直接給 <img src>）
 *
 * 安全性：檔名一定要在 note_images 查得到才給，且只取 basename，
 * 避免被拿來當成任意檔案讀取的跳板（原本的直連 URL 反而是誰都能抓）。
 */
session_start();
if (!isset($_SESSION['userName'])) { http_response_code(403); exit; }

require_once __DIR__ . '/../common/DBConnection.php';
$pdo = (new DBConnection())->getPDO();

$f = basename(trim((string)($_GET['f'] ?? '')));
if ($f === '') { http_response_code(404); exit; }

// 必須是登記在案的備註圖片，否則不給
$st = $pdo->prepare("SELECT original_name FROM note_images WHERE file_name = ? LIMIT 1");
$st->execute([$f]);
$rec = $st->fetch(PDO::FETCH_ASSOC);
if (!$rec) { http_response_code(404); exit; }

// 路徑即時組（鐵律5）：DB 只存檔名，完整路徑用目前設定值現場算
$base = '';
try {
    $base = (string)$pdo->query("SELECT setting_value FROM system_settings WHERE setting_key='notes_nas_dir'")->fetchColumn();
} catch (Throwable $e) {}
if ($base === '') { http_response_code(500); exit; }
$fp = rtrim($base, "/\\") . DIRECTORY_SEPARATOR . $f;
if (!is_file($fp)) { http_response_code(404); exit; }

$mime = match (strtolower(pathinfo($fp, PATHINFO_EXTENSION))) {
    'jpg', 'jpeg' => 'image/jpeg',
    'png'         => 'image/png',
    'gif'         => 'image/gif',
    'webp'        => 'image/webp',
    'bmp'         => 'image/bmp',
    'pdf'         => 'application/pdf',
    default       => 'application/octet-stream',
};
header('Content-Type: ' . $mime);
header('Content-Disposition: inline; filename="' . rawurlencode($rec['original_name'] ?: $f) . '"');
header('Content-Length: ' . filesize($fp));
header('Cache-Control: private, max-age=600');
readfile($fp);
