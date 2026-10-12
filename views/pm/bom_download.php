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
// /nas/xxx      → <bom_scan_dir>\xxx
// /nas/ERP/xxx  → <bom_scan_dir>\ERP\xxx
// 實際位置一律走設定鍵 bom_scan_dir（2026-08-25 起預設 UNC `\\excellentnas\生產課\BOM\`），
// 不再寫死在這支裡；解析（含 rawurldecode 不誤轉字面 +、防路徑穿越、Big5 退路）的唯一實作
// 在 src/common/bom_view_file_lib.php，小畫家權杖與旋轉存檔共用同一份，換 NAS 只要改設定值。
require_once __DIR__ . '/../../src/common/DBConnection.php';
require_once __DIR__ . '/../../src/common/bom_view_file_lib.php';
try {
    $pdoDl = (new DBConnection())->getPDO();
    $resolved = eg_bvf_resolve($pdoDl, ['src' => 'nas', 'ref' => ['path' => $path]]);
} catch (Throwable $e) { $resolved = null; }

if (!$resolved) {
    header('HTTP/1.1 404 Not Found');
    exit('File not found');
}
$physPath = $resolved['fs'];

// ── 下載權限（唯一實作 attach_download_perm_lib）──────────────────────────
// 2026-10-12：這支先前只檢查「有沒有登入」，所以三支附件 API 都已經守住的下載權限，
// 在「圖面查閱 → 儲存」這條路徑上完全沒有擋——NAS 圖面（含 .stp/.ipt 這類 3D 原始檔）
// 只要登入就能另存帶走。現在比照 API 同一套規則補上。
//   ‧只擋真正的另存（未帶 inline）；inline=1 是畫面預覽／列印要用的，不受限制。
//   ‧NAS 掃描圖沒有附件紀錄、拿不到標籤 → 一律視為「公司內部」（與
//     eg_adp_compute_is_customer() 對「沒有任何客供標籤」的結論相同）；2D/3D 看副檔名。
if (!$inline) {
    try {
        require_once __DIR__ . '/../../src/common/attach_download_perm_lib.php';
        $dlUid = (int)($_SESSION['id'] ?? 0);
        if ($dlUid === 0 && ($_SESSION['userName'] ?? '') !== '') {
            $stU = $pdoDl->prepare("SELECT id FROM user WHERE user_uname = ? AND state != 0 LIMIT 1");
            $stU->execute([$_SESSION['userName']]);
            $dlUid = (int)($stU->fetchColumn() ?: 0);
        }
        $gate = eg_adp_gate_download($pdoDl, $dlUid, false, basename($physPath), null);
        if (!$gate['ok']) {
            header('HTTP/1.1 403 Forbidden');
            header('Content-Type: text/plain; charset=utf-8');
            exit($gate['msg']);
        }
    } catch (Throwable $e) { /* 判定失敗不擋下載，維持既有行為（fail-open，見共用庫檔頭） */ }
}

// ── MIME 類型 ──
$ext = $resolved['ext'];
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
    // 快取 60 秒（原本 3600）：圖面可以就地旋轉存檔之後，一小時的快取會讓其他人一直
    // 看到轉之前的舊圖；60 秒足夠讓切換／列印不必重打 NAS，又不會卡著舊圖太久。
    header('Content-Disposition: inline; filename="' . rawurlencode($filename) . '"');
    header('Cache-Control: private, max-age=60');
} else {
    // 另存新檔：維持原行為，強制不快取確保拿到最新檔案
    header('Content-Disposition: attachment; filename="' . rawurlencode($filename) . '"');
    header('Cache-Control: no-cache, no-store, must-revalidate');
    header('Pragma: no-cache');
}
readfile($physPath);
exit;
?>
