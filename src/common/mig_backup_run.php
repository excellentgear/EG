<?php
/**
 * mig_backup_run.php — 移機快速備份 CLI 工人
 * 由 eg_migbk_start()（手動）或 mig_backup_tick.php（順路自動）以 start /B 背景啟動。
 *   用法: php mig_backup_run.php [manual|auto]
 * 流程: 估算大小(scanning) → DB dump → 設定快照 → uploads → NAS文件(robocopy逐夾) → 使用說明.txt → done
 * 進度以 JSON 寫回 db_backup_config.migbk_status,前端每 3 秒輪詢畫進度條。
 */
if (PHP_SAPI !== 'cli') { http_response_code(403); exit('CLI only'); }
mb_internal_encoding('UTF-8');
set_time_limit(0);
require_once __DIR__ . '/mig_backup_lib.php';

$trigger = ($argv[1] ?? 'manual') === 'auto' ? 'auto' : 'manual';

try {
    $pdo = new PDO('mysql:host=' . BK_DB_HOST . ';dbname=' . BK_DB_NAME . ';port=' . BK_DB_PORT . ';charset=utf8mb4',
                   BK_DB_USER, BK_DB_PASS, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
} catch (Throwable $e) { fwrite(STDERR, 'DB 連線失敗:' . $e->getMessage() . "\n"); exit(1); }

$cfg = eg_migbk_settings($pdo);

// auto:距上次成功未達間隔則跳過
if ($trigger === 'auto') {
    $days = $cfg['interval_days'];
    if ($days <= 0) exit(0);
    if ($cfg['last_ok'] !== '' && (time() - strtotime($cfg['last_ok'])) < $days * 86400) exit(0);
}

$dest = rtrim($cfg['dest'], "\\/");
$startTs = time();
$state = ['state'=>'scanning','trigger'=>$trigger,'started_at'=>date('Y-m-d H:i:s'),
          'total_bytes'=>0,'done_bytes'=>0,'current'=>'估算備份大小中…','msg'=>''];
$push = function() use (&$state, $pdo, $startTs) {
    $state['elapsed_sec'] = time() - $startTs;
    eg_migbk_set_status($pdo, $state);
};
$fail = function(string $why) use (&$state, $push) {
    $state['state'] = 'fail'; $state['msg'] = $why; $state['finished_at'] = date('Y-m-d H:i:s');
    $push(); exit(1);
};
$push();

// ── 0. 目的地可寫檢查（實際寫探測檔,勿信 is_writable/UNC 陷阱）──
@mkdir($dest, 0777, true);
$probe = $dest . '\\.migbk_probe.tmp';
if (@file_put_contents($probe, 't') === false) $fail('備份存放位置無法寫入:' . $dest);
@unlink($probe);

// ── 1. 估算大小 ──
$uploadsDir = 'C:\\MAMP\\htdocs\\EGsystem\\uploads';
$docs = eg_migbk_doc_sources($pdo);
$plan = []; // [type, label, src, dstRel, bytes]
$dbEstimate = 75 * 1048576; // DB dump 估 75MB(依近況);實際大小 dump 完以真值入帳
$plan[] = ['type'=>'db', 'label'=>'資料庫匯出', 'src'=>'', 'dst'=>'db', 'bytes'=>$dbEstimate];
$cfgBytes = 0;
foreach (eg_migbk_config_files() as $src => $rel) { if (is_file($src)) $cfgBytes += (int)@filesize($src); }
foreach (eg_migbk_config_dirs() as $src => $rel)  { if (is_dir($src))  $cfgBytes += eg_migbk_dir_size($src); }
$plan[] = ['type'=>'config', 'label'=>'環境設定快照', 'src'=>'', 'dst'=>'config_snapshot', 'bytes'=>max($cfgBytes, 1)];
if ($cfg['include_uploads'] && is_dir($uploadsDir)) {
    $state['current'] = '估算 uploads 大小…'; $push();
    $plan[] = ['type'=>'dir', 'label'=>'uploads 上傳附件', 'src'=>$uploadsDir, 'dst'=>'EGsystem_uploads', 'bytes'=>eg_migbk_dir_size($uploadsDir)];
}
foreach ($docs as $d) {
    if (!@is_dir($d['path'])) { $state['msg'] .= "[略過:{$d['label']}(路徑不存在)] "; continue; }
    $state['current'] = '估算 ' . $d['label'] . ' 大小…'; $push();
    $plan[] = ['type'=>'dir', 'label'=>'NAS文件:' . $d['label'], 'src'=>$d['path'],
               'dst'=>'nas_files\\' . preg_replace('/[^A-Za-z0-9_]/', '_', $d['key']), 'bytes'=>eg_migbk_dir_size($d['path'])];
}
$state['total_bytes'] = array_sum(array_column($plan, 'bytes'));
$state['state'] = 'running'; $state['current'] = '開始備份…'; $push();

// ── 2. 逐項執行 ──
foreach ($plan as $p) {
    $state['current'] = $p['label']; $push();
    $dstAbs = $dest . '\\' . $p['dst'];
    if ($p['type'] === 'db') {
        @mkdir($dstAbs, 0777, true);
        $sqlFile = $dstAbs . '\\EGsystem_' . date('Ymd_His') . '.sql';
        $errFile = $sqlFile . '.err';
        putenv('MYSQL_PWD=' . BK_DB_PASS);
        $cmd = escapeshellarg(BK_MYSQLDUMP)
             . ' --host=' . escapeshellarg(BK_DB_HOST) . ' --port=' . escapeshellarg(BK_DB_PORT)
             . ' --user=' . escapeshellarg(BK_DB_USER)
             . ' --single-transaction --default-character-set=utf8mb4 --set-gtid-purged=OFF'
             . ' --no-tablespaces --routines --triggers --events --hex-blob '
             . escapeshellarg(BK_DB_NAME) . ' > ' . escapeshellarg($sqlFile) . ' 2> ' . escapeshellarg($errFile);
        $o=[]; $c=1; exec($cmd, $o, $c);
        putenv('MYSQL_PWD');
        if ($c !== 0 || !is_file($sqlFile) || filesize($sqlFile) < 1024) {
            $err = is_file($errFile) ? trim((string)@file_get_contents($errFile)) : '';
            $fail('資料庫匯出失敗(exit=' . $c . '):' . mb_substr($err, 0, 300));
        }
        @unlink($errFile);
        $state['done_bytes'] += (int)filesize($sqlFile);
    } elseif ($p['type'] === 'config') {
        foreach (eg_migbk_config_files() as $src => $rel) {
            if (!is_file($src)) continue;
            $to = $dest . '\\' . $rel;
            @mkdir(dirname($to), 0777, true);
            @copy($src, $to);
        }
        foreach (eg_migbk_config_dirs() as $src => $rel) {
            if (!is_dir($src)) continue;
            eg_bk_exec('robocopy ' . escapeshellarg($src) . ' ' . escapeshellarg($dest . '\\' . $rel) . ' /E /R:1 /W:1 /NP /NFL /NDL');
        }
        $state['done_bytes'] += $p['bytes'];
    } else { // dir
        [$c] = eg_bk_exec('robocopy ' . escapeshellarg($p['src']) . ' ' . escapeshellarg($dstAbs) . ' /E /COPY:DAT /R:1 /W:1 /NP /NFL /NDL');
        if ($c >= 8) { $state['msg'] .= "[{$p['label']} 複製有錯(robocopy=$c)] "; }
        $state['done_bytes'] += $p['bytes'];
    }
    $push();
}

// ── 3. 使用說明.txt ──
$readme = "EGsystem 移機快速備份 使用說明\n"
        . "建立時間: " . date('Y-m-d H:i:s') . "（觸發:" . ($trigger === 'auto' ? '順路自動' : '手動') . "）\n"
        . str_repeat('=', 50) . "\n\n"
        . "【內容清單】\n"
        . "  db\\EGsystem_*.sql        … 資料庫完整匯出(utf8mb4,官方 mysqldump 9.4)\n"
        . "  config_snapshot\\         … 環境設定快照(php.ini×2/httpd.conf+extra+ssl/my.ini/DBConnection.php/telegram_config.php)\n"
        . ($cfg['include_uploads'] ? "  EGsystem_uploads\\        … 網站 uploads 上傳附件(還原到 C:\\MAMP\\htdocs\\EGsystem\\uploads)\n" : '')
        . (count($docs) ? "  nas_files\\<設定鍵>\\      … NAS 文件(對應各路徑設定,還原時放到新位置後用「路徑遷移工具」把設定指過去)\n" : '')
        . "\n【還原步驟(新主機)】\n"
        . "  1. 先照 C:\\EGsystem_migration\\README-遷移包說明.md 安裝七套軟體(或直接跑 restore_new_pc.ps1)\n"
        . "  2. 還原 config_snapshot 內設定檔到相同位置\n"
        . "  3. 建 DB 帳號(config_snapshot\\MySQL\\create_user.sql 或遷移包內版本)後匯入 db\\ 的 .sql:\n"
        . "     \"C:\\Program Files\\MySQL\\MySQL Server 9.4\\bin\\mysql.exe\" -u EG-TS2024 -p EGsystem < EGsystem_xxx.sql\n"
        . "  4. EGsystem_uploads 內容放回 C:\\MAMP\\htdocs\\EGsystem\\uploads\n"
        . "  5. nas_files 各資料夾放到新 NAS/硬碟位置 → 開「資料庫備份管理→路徑遷移工具」批次把舊前綴換成新位置\n"
        . "  6. 若沿用原 NAS(excellentnas/Z:),第 5 步免做\n"
        . "\n【注意】\n"
        . "  - 本備份不含程式碼(在 GitHub: ellentravel1003/EGsystem)與 DB 備份庫(EGsystem-dbbackup)\n"
        . "  - DBConnection.php/telegram_config.php/ssl 私鑰為機密,本資料夾不可外流\n"
        . ($state['msg'] ? "\n【本次警告】" . $state['msg'] . "\n" : '');
file_put_contents($dest . '\\使用說明.txt', "\xEF\xBB\xBF" . $readme); // BOM 讓記事本正確顯示中文

$state['state'] = 'done';
$state['finished_at'] = date('Y-m-d H:i:s');
$state['current'] = '完成';
$state['dest'] = $dest;
$push();
eg_bk_cfg_set($pdo, 'migbk_last_ok', date('Y-m-d H:i:s'), 'system');
exit(0);
