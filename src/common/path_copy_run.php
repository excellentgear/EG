<?php
/**
 * path_copy_run.php — 路徑遷移的檔案複製 CLI 工人（robocopy）
 * 由 path_migration_lib.php 的 eg_pm_copy_start() 背景啟動。
 *   用法: php path_copy_run.php "來源資料夾" "目的資料夾"
 * robocopy /E:含子資料夾複製(不刪目的地既有檔) /R:1 /W:1:失敗重試1次
 * 結束碼 0-7 = 成功(含「無檔可複製」),>=8 = 有錯誤。狀態寫回 db_backup_config。
 */
if (PHP_SAPI !== 'cli') { http_response_code(403); exit('CLI only'); }
mb_internal_encoding('UTF-8');
require_once __DIR__ . '/db_backup_lib.php';

$src = $argv[1] ?? '';
$dst = $argv[2] ?? '';
if ($src === '' || $dst === '') { fwrite(STDERR, "用法: php path_copy_run.php 來源 目的\n"); exit(1); }

try {
    $pdo = new PDO('mysql:host=' . BK_DB_HOST . ';dbname=' . BK_DB_NAME . ';port=' . BK_DB_PORT . ';charset=utf8mb4',
                   BK_DB_USER, BK_DB_PASS, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
} catch (Throwable $e) { fwrite(STDERR, 'DB 連線失敗:' . $e->getMessage() . "\n"); exit(1); }

@mkdir($dst, 0777, true);
$logFile = tempnam(sys_get_temp_dir(), 'egpc_') . '.log';
$cmd = 'robocopy ' . escapeshellarg($src) . ' ' . escapeshellarg($dst)
     . ' /E /COPY:DAT /R:1 /W:1 /NP /NFL /NDL /UNILOG:' . escapeshellarg($logFile);
$out = []; $code = 1;
exec($cmd, $out, $code);

// 取 robocopy 摘要（log 末段）
$tail = '';
if (is_file($logFile)) {
    $txt = @file_get_contents($logFile);
    if ($txt !== false) {
        // UNILOG 是 UTF-16LE
        $txt = @mb_convert_encoding($txt, 'UTF-8', 'UTF-16LE');
        $lines = preg_split('/\r?\n/', trim((string)$txt));
        $tail = implode("\n", array_slice($lines, -12));
    }
    @unlink($logFile);
}
$ok = ($code < 8);
eg_bk_cfg_set($pdo, 'pathcopy_status', $ok ? 'done' : 'fail', 'system');
eg_bk_cfg_set($pdo, 'pathcopy_log', "robocopy 結束碼=$code(" . ($ok ? '成功' : '有錯誤') . ")\n" . mb_substr($tail, 0, 1500), 'system');
exit($ok ? 0 : 1);
