<?php
/**
 * as_doc_pdf.php — AS 文件線上版「列印」＝先產生 PDF 再給使用者印（2026-11-24 使用者要求）
 *
 * 為什麼：瀏覽器的列印對話框會自己決定邊界／縮放（邊界「無」會蓋掉 @page margin），
 * 同一份文件在不同電腦、不同印表機印出來會多出空白頁或被切到；PDF 由伺服器上的無頭 Chrome
 * 用「列印版面」量過、超出一點點的頁自動縮到剛好、長表格每一頁都有抬頭之後才輸出，
 * 使用者在 PDF 檢視器按列印看到什麼就印出什麼。實作在 ai-rules/tools/as_doc_pdf.js（唯一一份）。
 * 暫存：產生的 PDF 放系統暫存資料夾，超過 1 小時的檔案每次呼叫時自動刪除。
 * 失敗（沒裝 Chrome／Node）時顯示原因，並提供「用瀏覽器原生列印」的備援連結。
 */
session_start();
if (!isset($_SESSION['userName'])) { header('Location:../../index.php'); exit; }
include_once '../../src/common/_config.php';
include_once '../../src/common/DBConnection.php';
include_once '../../src/common/as_doc_content_lib.php';

$db  = (new DBConnection())->getPDO();
adc_ensure_schema($db);
$uid = (int)($_SESSION['id'] ?? 0);
$vid = (int)($_GET['version_id'] ?? 0);
$V   = $vid > 0 ? adc_version_info($db, $vid) : null;
$P   = $V ? adc_perms($db, $uid, (int)$V['doc_id']) : adc_perms($db, $uid);

function pdf_fail(string $msg, int $vid): void {
    http_response_code(500);
    echo '<!doctype html><meta charset="utf-8"><body style="font-family:sans-serif;padding:24px;color:#6B4423">'
       . '<h3>無法產生 PDF</h3><p>' . htmlspecialchars($msg) . '</p>'
       . '<p><a href="as_doc_print.php?version_id=' . (int)$vid . '&native=1">改用瀏覽器原生列印</a></p></body>';
    exit;
}
if (!$V)                                   { http_response_code(404); exit('找不到這個版次'); }
if (!$P['view'])                           { http_response_code(403); exit('沒有 AS 文件的檢視權限'); }
if (empty($P['print']))                    { http_response_code(403); exit('沒有列印線上版的權限'); }
if ((int)$V['is_obsolete'] === 1 && !$P['admin']) { http_response_code(403); exit('這份文件已廢止，只有管理員能開啟'); }

$dir = rtrim(sys_get_temp_dir(), '\/') . DIRECTORY_SEPARATOR . 'eg_asdoc_pdf';
if (!is_dir($dir)) @mkdir($dir, 0777, true);
// 超過 1 小時的暫存 PDF 一律刪除
foreach (glob($dir . DIRECTORY_SEPARATOR . '*.pdf') ?: [] as $f) { if (@filemtime($f) < time() - 3600) @unlink($f); }

$node = null;
foreach (['C:/Program Files/nodejs/node.exe', 'C:/Program Files (x86)/nodejs/node.exe'] as $c) { if (is_file($c)) { $node = $c; break; } }
if (!$node) pdf_fail('伺服器找不到 Node.js，無法產生 PDF。', $vid);
$script = realpath(__DIR__ . '/../../ai-rules/tools/as_doc_pdf.js');
if (!$script) pdf_fail('找不到產生 PDF 的程式（ai-rules/tools/as_doc_pdf.js）。', $vid);

$sid = session_id();
session_write_close();                       // 無頭 Chrome 要用同一個 session 載頁面，先放掉鎖，否則會互相卡死
@set_time_limit(150);
$scheme = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http';
$base   = $scheme . '://' . $_SERVER['HTTP_HOST'];
$out    = $dir . DIRECTORY_SEPARATOR . 'v' . $vid . '_' . $uid . '_' . date('His') . '_' . bin2hex(random_bytes(3)) . '.pdf';

$cmd = '"' . $node . '" "' . $script . '" ' . $vid . ' ' . escapeshellarg($sid) . ' "' . $out . '" ' . escapeshellarg($base) . ' 2>&1';
$lines = []; $code = 0;
exec($cmd, $lines, $code);
if ($code !== 0 || !is_file($out) || filesize($out) < 1000) {
    pdf_fail('產生失敗（' . trim(implode(' ', array_slice($lines, -3))) . '）', $vid);
}
header('Content-Type: application/pdf');
header('Content-Disposition: inline; filename="' . rawurlencode(($V['doc_no'] ?? 'doc') . '_' . ($V['version'] ?? '') . '.pdf') . '"');
header('Content-Length: ' . filesize($out));
header('Cache-Control: private, no-store');
readfile($out);
