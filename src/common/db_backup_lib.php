<?php
/**
 * db_backup_lib.php — 資料庫備份/還原共用函式庫
 *
 * 被 3 個地方使用：
 *   - src/common/db_backup_run.php（CLI 備份工人，由順路觸發 db_backup_tick.php 背景啟動）
 *   - src/store/DBBackup_API.php（網頁後端：手動備份、設定、還原、下載）
 *
 * 設計重點：
 *   - 備份檔存在 web root 之外的私有 git repo（C:\EGsystem_dbbackup），網頁不可直接下載，
 *     一律走 API 權限檢查後由後端串流。
 *   - 用「官方」MySQL 9.4 的 mysqldump/mysql（MAMP 內建的是 5.7，對 9.4 太舊不可用）。
 *   - 密碼用 MYSQL_PWD 環境變數傳給子行程，不出現在行程命令列。
 *   - 因為是 git，即使 prune 把舊 dump 從工作區移除，仍保留在 git 歷史中可還原。
 *
 * 帳密與 DBConnection.php 相同（EG-TS2024）；若該處改密碼，本檔的 BK_DB_PASS 要同步修改。
 */

// ── 常數（換 MySQL/PHP 版本、搬備份庫位置時需同步修改）──────────────────────
if (!defined('BK_MYSQLDUMP')) {
    define('BK_MYSQLDUMP', 'C:\\Program Files\\MySQL\\MySQL Server 9.4\\bin\\mysqldump.exe');
    define('BK_MYSQL',     'C:\\Program Files\\MySQL\\MySQL Server 9.4\\bin\\mysql.exe');
    define('BK_GIT',       'C:\\Program Files\\Git\\cmd\\git.exe');
    define('BK_PHP',       'C:\\MAMP\\bin\\php\\php8.3.1\\php.exe');
    define('BK_REPO',      'C:\\EGsystem_dbbackup');           // 備份庫根目錄（web root 之外）
    define('BK_DUMPS_REL', 'dumps');                            // dump 存放子資料夾（相對 repo 根）
    define('BK_DUMPS',     BK_REPO . '\\' . BK_DUMPS_REL);
    define('BK_DB_HOST',   '127.0.0.1');
    define('BK_DB_PORT',   '3306');
    define('BK_DB_NAME',   'EGsystem');
    define('BK_DB_USER',   'EG-TS2024');
    define('BK_DB_PASS',   'excell30367593');
}

// ── 設定值存取（db_backup_config 表）────────────────────────────────────────
function eg_bk_cfg_get(PDO $pdo, string $key, string $default = ''): string {
    try {
        $st = $pdo->prepare("SELECT cfg_value FROM db_backup_config WHERE cfg_key=? LIMIT 1");
        $st->execute([$key]);
        $v = $st->fetchColumn();
        return ($v === false || $v === null) ? $default : (string)$v;
    } catch (Throwable $e) { return $default; }
}
function eg_bk_cfg_all(PDO $pdo): array {
    try {
        $rows = $pdo->query("SELECT cfg_key, cfg_value FROM db_backup_config")->fetchAll(PDO::FETCH_KEY_PAIR);
        return $rows ?: [];
    } catch (Throwable $e) { return []; }
}
function eg_bk_cfg_set(PDO $pdo, string $key, string $value, string $by): void {
    $st = $pdo->prepare("INSERT INTO db_backup_config (cfg_key,cfg_value,updated_by) VALUES (?,?,?)
                         ON DUPLICATE KEY UPDATE cfg_value=VALUES(cfg_value), updated_by=VALUES(updated_by)");
    $st->execute([$key, $value, $by]);
}

// ── 執行外部命令，回傳 [exitCode, 合併輸出字串]（Windows 走 cmd.exe）──────────
function eg_bk_exec(string $cmd): array {
    $out = [];
    $code = 1;
    exec($cmd . ' 2>&1', $out, $code);
    return [$code, implode("\n", $out)];
}

// ── 在備份庫執行 git（陣列參數，各自 escape）─────────────────────────────────
function eg_bk_git(array $args): array {
    $parts = [escapeshellarg(BK_GIT), '-C', escapeshellarg(BK_REPO)];
    foreach ($args as $a) $parts[] = escapeshellarg($a);
    return eg_bk_exec(implode(' ', $parts));
}

// ── 產生 dump 檔名（EGsystem_YYYYmmdd_HHMMSS.sql）────────────────────────────
function eg_bk_new_filename(): string {
    return 'EGsystem_' . date('Ymd_His') . '.sql';
}

// ── 執行一次備份 ────────────────────────────────────────────────────────────
// $trigger: 'auto' | 'manual' | 'pre-restore'
// 回傳 ['ok'=>bool, 'skipped'=>bool, 'msg'=>string, 'log_id'=>int|null, 'filename'=>string, 'commit'=>string]
function eg_bk_run(PDO $pdo, string $trigger, string $by): array {
    @mkdir(BK_DUMPS, 0700, true);

    // 併發鎖（避免多個順路觸發同時 dump）
    $lock = BK_REPO . '\\.backup.lock';
    $lf = @filemtime($lock);
    if ($lf && (time() - $lf) < 600) {
        return ['ok'=>false,'skipped'=>true,'msg'=>'另一個備份正在進行中，略過','log_id'=>null,'filename'=>'','commit'=>''];
    }
    @touch($lock);

    try {
        // auto：距上次成功備份未達設定間隔則略過
        if ($trigger === 'auto') {
            $days = max(1, (int)eg_bk_cfg_get($pdo, 'interval_days', '7'));
            $st = $pdo->query("SELECT MAX(created_at) FROM db_backup_log WHERE status='success'");
            $lastOk = $st->fetchColumn();
            if ($lastOk && (time() - strtotime($lastOk)) < $days * 86400) {
                @unlink($lock);
                return ['ok'=>false,'skipped'=>true,'msg'=>'尚未到備份間隔，略過','log_id'=>null,'filename'=>'','commit'=>''];
            }
        }

        $filename = eg_bk_new_filename();
        $relPath  = BK_DUMPS_REL . '/' . $filename;      // git 內一律用正斜線
        $absPath  = BK_DUMPS . '\\' . $filename;

        // 開一筆 running 紀錄
        $ins = $pdo->prepare("INSERT INTO db_backup_log (filename,rel_path,trigger_type,status,created_by)
                              VALUES (?,?,?, 'running', ?)");
        $ins->execute([$filename, $relPath, $trigger, $by]);
        $logId = (int)$pdo->lastInsertId();

        // ── mysqldump（保留註解標記，供整表還原時定位表區塊）──
        putenv('MYSQL_PWD=' . BK_DB_PASS);
        $cmd = escapeshellarg(BK_MYSQLDUMP)
             . ' --host=' . escapeshellarg(BK_DB_HOST)
             . ' --port=' . escapeshellarg(BK_DB_PORT)
             . ' --user=' . escapeshellarg(BK_DB_USER)
             . ' --single-transaction --default-character-set=utf8mb4 --set-gtid-purged=OFF'
             . ' --no-tablespaces --routines --triggers --events --hex-blob'
             . ' ' . escapeshellarg(BK_DB_NAME)
             . ' > ' . escapeshellarg($absPath);
        [$code, $out] = eg_bk_exec($cmd);
        putenv('MYSQL_PWD');

        if ($code !== 0 || !is_file($absPath) || filesize($absPath) < 1024) {
            $err = '匯出失敗：' . mb_substr($out, 0, 500);
            @unlink($absPath);
            $pdo->prepare("UPDATE db_backup_log SET status='fail', note=?, finished_at=NOW() WHERE id=?")
                ->execute([$err, $logId]);
            eg_bk_cfg_set($pdo, 'last_error', $err, 'system');
            @unlink($lock);
            return ['ok'=>false,'skipped'=>false,'msg'=>$err,'log_id'=>$logId,'filename'=>$filename,'commit'=>''];
        }
        $size = filesize($absPath);

        // ── 複製到 NAS（best-effort）──
        $nas = trim(eg_bk_cfg_get($pdo, 'nas_path', ''));
        $nasNote = '';
        if ($nas !== '') {
            $nasDir = rtrim($nas, "\\/");
            if (@is_dir($nasDir) && @is_writable($nasDir)) {
                if (!@copy($absPath, $nasDir . DIRECTORY_SEPARATOR . $filename)) $nasNote = 'NAS 複製失敗';
            } else {
                $nasNote = 'NAS 路徑不存在或不可寫';
            }
        }

        // ── git add + commit ──
        eg_bk_git(['add', '--', $relPath]);
        [$cc, $cout] = eg_bk_git(['commit', '-m', "backup: {$filename} ({$trigger})"]);
        [, $hash] = eg_bk_git(['rev-parse', 'HEAD']);
        $hash = trim($hash);

        // ── push（best-effort，可設定關閉）──
        $pushed = 0;
        if (eg_bk_cfg_get($pdo, 'auto_push', '1') === '1') {
            [$pc, $pout] = eg_bk_git(['push', 'origin', 'HEAD']);
            $pushed = ($pc === 0) ? 1 : 0;
            if (!$pushed) $nasNote = trim($nasNote . ' / push 失敗（本機已保留）');
        }

        // ── 完成 ──
        $pdo->prepare("UPDATE db_backup_log SET status='success', size_bytes=?, git_commit=?, pushed=?, note=?, finished_at=NOW() WHERE id=?")
            ->execute([$size, $hash, $pushed, ($nasNote ?: null), $logId]);
        eg_bk_cfg_set($pdo, 'last_error', '', 'system');

        // ── prune 舊備份（工作區保留 keep_count 個，歷史仍留）──
        eg_bk_prune($pdo);

        @unlink($lock);
        return ['ok'=>true,'skipped'=>false,'msg'=>('備份完成：' . $filename . ($nasNote ? "（{$nasNote}）" : '')),
                'log_id'=>$logId,'filename'=>$filename,'commit'=>$hash];
    } catch (Throwable $e) {
        @unlink($lock);
        return ['ok'=>false,'skipped'=>false,'msg'=>'例外：' . $e->getMessage(),'log_id'=>null,'filename'=>'','commit'=>''];
    }
}

// ── prune：工作區只保留最新 keep_count 個 dump 檔（git rm 其餘，歷史保留可還原）──
function eg_bk_prune(PDO $pdo): void {
    try {
        $keep = max(1, (int)eg_bk_cfg_get($pdo, 'keep_count', '10'));
        $files = glob(BK_DUMPS . '\\EGsystem_*.sql') ?: [];
        if (count($files) <= $keep) return;
        usort($files, fn($a, $b) => filemtime($b) <=> filemtime($a)); // 新→舊
        $old = array_slice($files, $keep);
        foreach ($old as $f) {
            $rel = BK_DUMPS_REL . '/' . basename($f);
            eg_bk_git(['rm', '-q', '--', $rel]);
            $pdo->prepare("UPDATE db_backup_log SET note=CONCAT(COALESCE(note,''),' [已從工作區清除,仍可從git歷史還原]') WHERE rel_path=? AND status='success'")
                ->execute([$rel]);
        }
        eg_bk_git(['commit', '-m', 'prune: 保留最新 ' . $keep . ' 個備份']);
        if (eg_bk_cfg_get($pdo, 'auto_push', '1') === '1') eg_bk_git(['push', 'origin', 'HEAD']);
    } catch (Throwable $e) { /* prune 失敗不影響備份本身 */ }
}

// ── 取得某筆備份的 SQL 檔路徑（工作區有就用；否則從 git 歷史還原到暫存檔）──
// 回傳 ['ok'=>bool,'path'=>string,'temp'=>bool,'msg'=>string]
function eg_bk_resolve_sql(PDO $pdo, int $logId): array {
    $st = $pdo->prepare("SELECT rel_path, git_commit, status FROM db_backup_log WHERE id=? LIMIT 1");
    $st->execute([$logId]);
    $row = $st->fetch(PDO::FETCH_ASSOC);
    if (!$row || $row['status'] !== 'success') return ['ok'=>false,'path'=>'','temp'=>false,'msg'=>'找不到有效備份'];

    $abs = BK_REPO . '\\' . str_replace('/', '\\', $row['rel_path']);
    if (is_file($abs) && filesize($abs) > 0) return ['ok'=>true,'path'=>$abs,'temp'=>false,'msg'=>''];

    // 工作區已 prune → 從 git 歷史取出
    if (!empty($row['git_commit'])) {
        $tmp = tempnam(sys_get_temp_dir(), 'egbk_') . '.sql';
        $spec = $row['git_commit'] . ':' . $row['rel_path'];
        [$code, $out] = eg_bk_exec(implode(' ', [escapeshellarg(BK_GIT), '-C', escapeshellarg(BK_REPO),
                                    'show', escapeshellarg($spec)]) . ' > ' . escapeshellarg($tmp));
        if ($code === 0 && is_file($tmp) && filesize($tmp) > 0) return ['ok'=>true,'path'=>$tmp,'temp'=>true,'msg'=>''];
        @unlink($tmp);
    }
    return ['ok'=>false,'path'=>'','temp'=>false,'msg'=>'備份檔已不存在且無法從 git 歷史取回'];
}

// ── 用 mysql.exe 匯入整個 SQL 檔（整庫還原）──
function eg_bk_import_file(string $sqlPath): array {
    if (!is_file($sqlPath)) return ['ok'=>false,'msg'=>'SQL 檔不存在'];
    putenv('MYSQL_PWD=' . BK_DB_PASS);
    $cmd = escapeshellarg(BK_MYSQL)
         . ' --host=' . escapeshellarg(BK_DB_HOST)
         . ' --port=' . escapeshellarg(BK_DB_PORT)
         . ' --user=' . escapeshellarg(BK_DB_USER)
         . ' --default-character-set=utf8mb4'
         . ' ' . escapeshellarg(BK_DB_NAME)
         . ' < ' . escapeshellarg($sqlPath);
    [$code, $out] = eg_bk_exec($cmd);
    putenv('MYSQL_PWD');
    if ($code !== 0) return ['ok'=>false,'msg'=>'匯入失敗：' . mb_substr($out, 0, 800)];
    return ['ok'=>true,'msg'=>'還原完成'];
}

// ── 從完整 dump 抽出「單一資料表」的 DROP/CREATE/INSERT 區塊 ──
// mysqldump 以「-- Table structure for table `X`」為每張表的起點，據此切段
function eg_bk_extract_table_sql(string $sqlPath, string $table): ?string {
    $fh = @fopen($sqlPath, 'r');
    if (!$fh) return null;
    $marker    = "-- Table structure for table `";
    $target    = $marker . $table . "`";
    $capturing = false;
    $buf       = '';
    while (($line = fgets($fh)) !== false) {
        // 進入目標表區塊
        if (strpos($line, $target) === 0) { $capturing = true; $buf .= $line; continue; }
        if ($capturing) {
            // 遇到「下一張表」的結構標記 → 結束
            if (strpos($line, $marker) === 0) break;
            $buf .= $line;
        }
    }
    fclose($fh);
    if ($buf === '') return null;
    // 包上 session 設定，避免 FK/charset 問題
    return "SET NAMES utf8mb4;\nSET FOREIGN_KEY_CHECKS=0;\n" . $buf . "\nSET FOREIGN_KEY_CHECKS=1;\n";
}

// ── 整表還原：從備份抽出單表 SQL → 匯入 ──
function eg_bk_restore_table(PDO $pdo, int $logId, string $table): array {
    if (!preg_match('/^[A-Za-z0-9_]+$/', $table)) return ['ok'=>false,'msg'=>'表名不合法'];
    $r = eg_bk_resolve_sql($pdo, $logId);
    if (!$r['ok']) return ['ok'=>false,'msg'=>$r['msg']];
    $sql = eg_bk_extract_table_sql($r['path'], $table);
    if ($r['temp']) @unlink($r['path']);
    if ($sql === null) return ['ok'=>false,'msg'=>"備份中找不到資料表 {$table}"];
    $tmp = tempnam(sys_get_temp_dir(), 'egbkt_') . '.sql';
    file_put_contents($tmp, $sql);
    $res = eg_bk_import_file($tmp);
    @unlink($tmp);
    return $res;
}

// ── 列出備份中包含哪些資料表（給整表還原下拉用）──
function eg_bk_list_tables_in_backup(PDO $pdo, int $logId): array {
    $r = eg_bk_resolve_sql($pdo, $logId);
    if (!$r['ok']) return [];
    $tables = [];
    $fh = @fopen($r['path'], 'r');
    if ($fh) {
        $marker = "-- Table structure for table `";
        while (($line = fgets($fh)) !== false) {
            if (strpos($line, $marker) === 0) {
                if (preg_match('/`([^`]+)`/', $line, $m)) $tables[] = $m[1];
            }
        }
        fclose($fh);
    }
    if ($r['temp']) @unlink($r['path']);
    sort($tables);
    return $tables;
}
