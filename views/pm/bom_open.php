<?php
/**
 * bom_open.php — 憑「一次性權杖」取檔（給用戶端的小畫家 VBScript 用，見 bom_open_token.php）
 *
 * 這支**刻意不檢查登入**：呼叫它的是用戶端的 wscript.exe，不會帶瀏覽器 cookie。
 * 安全性靠權杖本身——32 位元組隨機、3 分鐘到期、**讀完就刪（一次性）**，
 * 而且權杖裡存的是「來源種類＋識別鍵」不是實體路徑，實體位置在這裡才即時算出來（鐵律5）。
 *
 * 網址結尾要帶 `&x=.jpg` 這種副檔名：用戶端 VBScript 是用「網址最後一個點」判斷
 * 要把暫存檔存成什麼副檔名，沒有的話會存成 .php 而 mspaint 開不起來。
 */
require_once __DIR__ . '/../../src/common/DBConnection.php';
require_once __DIR__ . '/../../src/common/bom_view_file_lib.php';

$tok  = (string)($_GET['t'] ?? '');
$data = eg_bvf_token_consume($tok);
if (!$data) {
    header('HTTP/1.1 403 Forbidden');
    exit('link expired');   // 已用過或已過期；請回頁面重新按一次
}

try {
    $pdo = (new DBConnection())->getPDO();
    $r   = eg_bvf_resolve($pdo, ['src' => $data['src'] ?? '', 'ref' => $data['ref'] ?? []]);
} catch (Throwable $e) { $r = null; }

if (!$r) {
    header('HTTP/1.1 404 Not Found');
    exit('File not found');
}

$mimes = [
    'jpg'  => 'image/jpeg',  'jpeg' => 'image/jpeg',
    'png'  => 'image/png',   'gif'  => 'image/gif',
    'bmp'  => 'image/bmp',   'tif'  => 'image/tiff',
    'tiff' => 'image/tiff',  'pdf'  => 'application/pdf',
    'webp' => 'image/webp',
];
$mime = $mimes[$r['ext']] ?? 'application/octet-stream';

while (ob_get_level()) ob_end_clean();
header('Content-Type: ' . $mime);
header('Content-Length: ' . filesize($r['fs']));
header('Content-Disposition: inline; filename="' . rawurlencode($r['name']) . '"');
header('Cache-Control: no-cache, no-store, must-revalidate');
readfile($r['fs']);
exit;
