<?php
/**
 * db_backup_run.php — 資料庫備份 CLI 工人
 * 由 db_backup_tick.php 以 start /B 背景啟動；也可手動執行。
 *   用法： php db_backup_run.php [auto|manual|pre-restore] [觸發者]
 *   - auto   ：由順路觸發呼叫，工人內部會檢查是否已達備份間隔，未達則略過
 *   - manual ：網頁「立即備份」呼叫，一律執行
 */
if (PHP_SAPI !== 'cli') { http_response_code(403); exit('CLI only'); }
mb_internal_encoding('UTF-8');
require_once __DIR__ . '/db_backup_lib.php';

$trigger = $argv[1] ?? 'auto';
$by      = $argv[2] ?? 'system';
if (!in_array($trigger, ['auto', 'manual', 'pre-restore'], true)) $trigger = 'auto';

try {
    $pdo = new PDO(
        'mysql:host=' . BK_DB_HOST . ';dbname=' . BK_DB_NAME . ';port=' . BK_DB_PORT . ';charset=utf8mb4',
        BK_DB_USER, BK_DB_PASS,
        [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]
    );
} catch (Throwable $e) {
    fwrite(STDERR, 'DB 連線失敗：' . $e->getMessage() . "\n");
    exit(1);
}

// 觸發者若以 uid 形式傳入（避免中文經 cmd 命令列亂碼）,在此換成使用者中文姓名
if (preg_match('/^uid(\d+)$/', $by, $m)) {
    try {
        $st = $pdo->prepare("SELECT user_cname FROM user WHERE id=? LIMIT 1");
        $st->execute([(int)$m[1]]);
        $cname = trim((string)$st->fetchColumn());
        if ($cname !== '') $by = $cname;
    } catch (Throwable $e) {}
}

$r = eg_bk_run($pdo, $trigger, $by);
fwrite(STDOUT, json_encode($r, JSON_UNESCAPED_UNICODE) . "\n");
exit(($r['ok'] || !empty($r['skipped'])) ? 0 : 1);
