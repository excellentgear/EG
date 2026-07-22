<?php
/**
 * db_backup_view_load.php — 把選定備份載入檢視暫存庫（CLI 工人）
 * 由 DBBackup_API.php 的 view_load 以 start /B 背景啟動（載入約 20 秒，不能卡網頁請求）。
 *   用法： php db_backup_view_load.php <db_backup_log.id>
 * 狀態記於 db_backup_config：view_status(loading/ready/fail)、view_backup_id、view_loaded_at、view_error
 */
if (PHP_SAPI !== 'cli') { http_response_code(403); exit('CLI only'); }
mb_internal_encoding('UTF-8');
require_once __DIR__ . '/db_backup_lib.php';

$logId = (int)($argv[1] ?? 0);
if (!$logId) { fwrite(STDERR, "用法: php db_backup_view_load.php <log_id>\n"); exit(1); }

try {
    $pdo = new PDO(
        'mysql:host=' . BK_DB_HOST . ';dbname=' . BK_DB_NAME . ';port=' . BK_DB_PORT . ';charset=utf8mb4',
        BK_DB_USER, BK_DB_PASS,
        [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]
    );
} catch (Throwable $e) { fwrite(STDERR, 'DB 連線失敗：' . $e->getMessage() . "\n"); exit(1); }

$r = eg_bk_view_load($pdo, $logId);
fwrite(STDOUT, json_encode($r, JSON_UNESCAPED_UNICODE) . "\n");
exit($r['ok'] ? 0 : 1);
