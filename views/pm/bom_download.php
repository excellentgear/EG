<?php
session_start();
if (!isset($_SESSION['userName'])) {
    header('HTTP/1.1 403 Forbidden');
    exit('Access denied');
}

$path     = $_GET['path']     ?? '';
$filename = $_GET['filename'] ?? '';

// ── 安全性：只允許 /nas/ 開頭的路徑 ──
if (empty($path) || strpos($path, '/nas/') !== 0) {
    header('HTTP/1.1 400 Bad Request');
    exit('Invalid path');
}
// 防路徑穿越
if (strpos($path, '..') !== false || strpos($path, "\0") !== false) {
    header('HTTP/1.1 400 Bad Request');
    exit('Invalid path');
}

// ── 清理下載檔名 ──
$filename = trim($filename);
if (empty($filename)) { $filename = 'download'; }
$filename = preg_replace('/[\\\\\/:\*\?"<>\|]/', '_', $filename);

// ── 網頁路徑 → 實體路徑 ──
// /nas/xxx          → Z:/BOM/xxx
// /nas/ERP/xxx      → Z:/BOM/ERP/xxx
$relativePath = substr($path, strlen('/nas/')); // 去掉 /nas/ 前綴
$relativePath = urldecode($relativePath);       // 解 URL 編碼（中文）

$physPath_utf8 = 'Z:/BOM/' . $relativePath;

// Windows 下 PHP 的 file_exists / readfile 需 Big5/系統編碼
if (strtoupper(substr(PHP_OS, 0, 3)) === 'WIN') {
    $physPath = mb_convert_encoding($physPath_utf8, 'Big5', 'UTF-8');
} else {
    $physPath = $physPath_utf8;
}

if (!file_exists($physPath) || is_dir($physPath)) {
    header('HTTP/1.1 404 Not Found');
    exit('File not found');
}

// ── MIME 類型 ──
$ext = strtolower(pathinfo($physPath_utf8, PATHINFO_EXTENSION));
$mimes = [
    'jpg'  => 'image/jpeg',  'jpeg' => 'image/jpeg',
    'png'  => 'image/png',   'gif'  => 'image/gif',
    'bmp'  => 'image/bmp',   'tif'  => 'image/tiff',
    'tiff' => 'image/tiff',  'pdf'  => 'application/pdf',
];
$mime = $mimes[$ext] ?? 'application/octet-stream';

// ── 輸出 ──
header('Content-Type: ' . $mime);
header('Content-Disposition: attachment; filename="' . rawurlencode($filename) . '"');
header('Content-Length: ' . filesize($physPath));
header('Cache-Control: no-cache, no-store, must-revalidate');
header('Pragma: no-cache');
readfile($physPath);
exit;
?>
