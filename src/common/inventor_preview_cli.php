<?php
/**
 * inventor_preview_cli.php — 背景轉檔用的獨立 CLI 進入點，不透過瀏覽器呼叫。
 *
 * 只給 `eg_inventor_preview_trigger_async()` 用 proc_open 背景啟動（fire-and-forget，
 * 不等它跑完）：上傳 IPT/X_T 成功後立刻觸發一次，轉檔與安全防護的邏輯完全沿用
 * `eg_inventor_preview_step()`（唯一實作），這裡只是換一個不會被使用者等待的進入點。
 *
 * 用法：php.exe inventor_preview_cli.php <ipt/x_t/iam檔案絕對路徑> [零件對照表JSON檔] [來源原始檔名]
 *       第 2、3 個參數只有組合檔（.iam）才會帶：組合檔要靠原始檔名才找得到零件，
 *       對照表走暫存檔傳遞（檔名常含中文，走命令列參數會被編碼打壞）。
 */

if (PHP_SAPI !== 'cli') { exit(1); }   // 只能從命令列執行，不開放當網頁端點

$path = $argv[1] ?? '';
if ($path === '' || !is_file($path)) { exit(1); }
$compFile = $argv[2] ?? '';
$srcOrig  = $argv[3] ?? '';
$companions = [];
if ($compFile !== '' && is_file($compFile)) {
    $d = json_decode((string)file_get_contents($compFile), true);
    if (is_array($d)) $companions = $d;
    @unlink($compFile);
}

require_once __DIR__ . '/DBConnection.php';
require_once __DIR__ . '/inventor_preview_lib.php';

try {
    $pdo = (new DBConnection())->getPDO();
    eg_inventor_preview_step($pdo, $path, $companions, $srcOrig);   // 結果寫進快取，這支進入點本身不輸出任何東西
} catch (Throwable $e) {
    exit(1);
}
exit(0);
