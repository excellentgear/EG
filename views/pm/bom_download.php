<?php
session_start();
if (!isset($_SESSION['userName'])) {
    header('HTTP/1.1 403 Forbidden');
    exit('Access denied');
}

$path     = $_GET['path']     ?? '';
$filename = $_GET['filename'] ?? '';
$inline   = ($_GET['inline'] ?? '') === '1';   // 1=圖面查閱預覽用（inline+可快取），未帶=另存新檔（attachment+不快取，行為不變）

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
// /nas/xxx          → \\excellentnas\生產課\BOM\xxx
// /nas/ERP/xxx      → \\excellentnas\生產課\BOM\ERP\xxx
$relativePath = substr($path, strlen('/nas/')); // 去掉 /nas/ 前綴
$relativePath = urldecode($relativePath);       // 解 URL 編碼（中文）

// 實體讀取一律走 UNC 路徑，不用 Z: 磁碟機代號——Z: 是使用者session層級的持續連線，
// 曾實測 `net use` 顯示狀態「無法使用」（連線失效/需重新協商），造成圖面查閱時好時壞的慢；
// UNC 路徑（Z: 實際指向的位置）不吃這個持續連線，跟料號附件走的 part_attach_nas_dir 是同一種穩定做法。
$physPath_utf8 = '\\\\excellentnas\\生產課\\BOM\\' . $relativePath;

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
header('Content-Length: ' . filesize($physPath));
if ($inline) {
    // 圖面查閱預覽/列印用：inline 讓瀏覽器直接顯示，並允許快取
    // （切換圖面、按列印重讀同一張圖時不必再打一次 NAS）
    header('Content-Disposition: inline; filename="' . rawurlencode($filename) . '"');
    header('Cache-Control: private, max-age=3600');
} else {
    // 另存新檔：維持原行為，強制不快取確保拿到最新檔案
    header('Content-Disposition: attachment; filename="' . rawurlencode($filename) . '"');
    header('Cache-Control: no-cache, no-store, must-revalidate');
    header('Pragma: no-cache');
}
readfile($physPath);
exit;
?>
