<?php
/**
 * PtaskImage_API.php — 個人工作紀錄附圖（personal_task_image）讀檔端點
 *
 * 原本讓瀏覽器直連 Apache 的 /nas 別名（ptask_url_dir 前綴），存放位置因此被綁死在
 * 磁碟機代號 Z: 上——瀏覽器讀不到 UNC 路徑。改由 PHP 讀檔後，位置只由 ptask_nas_dir
 * 一個設定決定，換 NAS 不必改 httpd.conf、也不必重啟 Apache
 * （CLAUDE.md 鐵律5：附件下載一律走模組 API，不要另設 URL 前綴）。
 *
 * 用法：PtaskImage_API.php?f=<file_name>   （inline 輸出，可直接給 <img src>）
 *
 * 權限：個人工作紀錄「僅本人可見」，所以除了檔名要查得到，還要確認這張圖屬於
 * 目前登入者（temp 暫存列以 user_id 判定擁有者）。原本的直連 URL 完全沒有這層把關，
 * 任何人知道網址就能看到別人的工作紀錄附圖。
 */
session_start();
if (!isset($_SESSION['userName'])) { http_response_code(403); exit; }

require_once __DIR__ . '/../common/DBConnection.php';
$pdo = (new DBConnection())->getPDO();
$uid = (int)($_SESSION['id'] ?? 0);

$f = basename(trim((string)($_GET['f'] ?? '')));
if ($f === '' || $uid <= 0) { http_response_code(404); exit; }

// 只給自己的圖：直接掛在自己名下（temp），或屬於自己的工作紀錄
$st = $pdo->prepare("SELECT i.original_name
                     FROM personal_task_image i
                     LEFT JOIN personal_task t ON t.id = i.task_id
                     WHERE i.file_name = ? AND (i.user_id = ? OR t.user_id = ?)
                     LIMIT 1");
$st->execute([$f, $uid, $uid]);
$rec = $st->fetch(PDO::FETCH_ASSOC);
if (!$rec) { http_response_code(404); exit; }

// 路徑即時組（鐵律5）：DB 只存檔名
$base = '';
try {
    $base = (string)$pdo->query("SELECT setting_value FROM system_settings WHERE setting_key='ptask_nas_dir'")->fetchColumn();
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
