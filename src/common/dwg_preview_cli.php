<?php
/**
 * dwg_preview_cli.php — 背景轉檔用的獨立 CLI 進入點，不透過瀏覽器呼叫。
 *
 * 只給 `eg_dwg_preview_trigger_async()` 用 proc_open 背景啟動（fire-and-forget，
 * 不等它跑完）：上傳 DWG 成功後立刻觸發一次，轉檔與逾時保護的邏輯完全沿用
 * `eg_dwg_preview_pdf()`（唯一實作），這裡只是換一個不會被使用者等待的進入點。
 *
 * 用法：php.exe dwg_preview_cli.php <dwg檔案絕對路徑>
 */

if (PHP_SAPI !== 'cli') { exit(1); }   // 只能從命令列執行，不開放當網頁端點

$path = $argv[1] ?? '';
if ($path === '' || !is_file($path)) { exit(1); }

require_once __DIR__ . '/DBConnection.php';
require_once __DIR__ . '/dwg_preview_lib.php';

try {
    $pdo = (new DBConnection())->getPDO();
    eg_dwg_preview_pdf($pdo, $path);   // 結果寫進快取，這支進入點本身不輸出任何東西
} catch (Throwable $e) {
    exit(1);
}
exit(0);
