<?php
/**
 * SalesImage_API.php — 業務追蹤圖片（sales_track_images）讀檔端點
 *
 * 原本讓瀏覽器直連 Apache 的 /nas 別名（sales_url_dir 前綴），存放位置因此被綁死在
 * 磁碟機代號 Z: 上——瀏覽器讀不到 UNC 路徑。改由 PHP 讀檔後，位置只由 sales_nas_dir
 * 一個設定決定，換 NAS 不必改 httpd.conf、也不必重啟 Apache
 * （CLAUDE.md 鐵律5：附件下載一律走模組 API，不要另設 URL 前綴）。
 *
 * 用法：SalesImage_API.php?f=<file_name>   （inline 輸出，可直接給 <img src>）
 * 安全性：只取 basename，且檔名必須在 sales_track_images 查得到才給。
 */
session_start();
require_once __DIR__ . '/../common/api_guard.php';   // 在職狀態守門（離職/留停者一律 403）
if (!isset($_SESSION['userName'])) { http_response_code(403); exit; }

require_once __DIR__ . '/../common/DBConnection.php';
$pdo = (new DBConnection())->getPDO();

$f = basename(trim((string)($_GET['f'] ?? '')));
if ($f === '') { http_response_code(404); exit; }

$st = $pdo->prepare("SELECT original_name FROM sales_track_images WHERE file_name = ? LIMIT 1");
$st->execute([$f]);
$rec = $st->fetch(PDO::FETCH_ASSOC);
if (!$rec) { http_response_code(404); exit; }

// 路徑即時組（鐵律5）：DB 只存檔名
$base = '';
try {
    $base = (string)$pdo->query("SELECT setting_value FROM system_settings WHERE setting_key='sales_nas_dir'")->fetchColumn();
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
