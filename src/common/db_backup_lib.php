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
        // stderr 一律導到獨立檔案（勿用 2>&1——會混進 dump 檔，失敗時看不到錯誤原因）
        $errPath = $absPath . '.stderr.txt';
        putenv('MYSQL_PWD=' . BK_DB_PASS);
        $cmd = escapeshellarg(BK_MYSQLDUMP)
             . ' --host=' . escapeshellarg(BK_DB_HOST)
             . ' --port=' . escapeshellarg(BK_DB_PORT)
             . ' --user=' . escapeshellarg(BK_DB_USER)
             . ' --single-transaction --default-character-set=utf8mb4 --set-gtid-purged=OFF'
             . ' --no-tablespaces --routines --triggers --events --hex-blob'
             . ' ' . escapeshellarg(BK_DB_NAME)
             . ' > ' . escapeshellarg($absPath)
             . ' 2> ' . escapeshellarg($errPath);
        $out = [];
        $code = 1;
        exec($cmd, $out, $code); // 不再附加 2>&1（stderr 已導檔）
        putenv('MYSQL_PWD');
        $errTxt = is_file($errPath) ? trim((string)@file_get_contents($errPath)) : '';

        if ($code !== 0 || !is_file($absPath) || filesize($absPath) < 1024) {
            // 診斷輔助：記錄實際執行身分與 dump 檔大小，錯誤檔保留供調查（.stderr.txt 不會被 git add）
            $who = trim((string)@shell_exec('whoami 2>&1'));
            $sz  = is_file($absPath) ? filesize($absPath) : -1;
            $err = "匯出失敗(exit=$code,size=$sz,run_as=$who)：" . mb_substr($errTxt, 0, 400);
            @unlink($absPath);
            $pdo->prepare("UPDATE db_backup_log SET status='fail', note=?, finished_at=NOW() WHERE id=?")
                ->execute([$err, $logId]);
            eg_bk_cfg_set($pdo, 'last_error', $err, 'system');
            @unlink($lock);
            return ['ok'=>false,'skipped'=>false,'msg'=>$err,'log_id'=>$logId,'filename'=>$filename,'commit'=>''];
        }
        @unlink($errPath); // 成功時清掉空的 stderr 檔
        $size = filesize($absPath);

        // ── 複製到 NAS（best-effort；不可用 is_writable 預檢——Windows UNC 上會誤報，直接嘗試複製）──
        $nas = trim(eg_bk_cfg_get($pdo, 'nas_path', ''));
        $nasNote = '';
        if ($nas !== '') {
            $nasDir = rtrim($nas, "\\/");
            if (@is_dir($nasDir)) {
                if (!@copy($absPath, $nasDir . DIRECTORY_SEPARATOR . $filename)) $nasNote = 'NAS 複製失敗';
            } else {
                $nasNote = 'NAS 路徑不存在';
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

// ── 刪除單筆備份（工作區檔 + git rm + commit + push；NAS 上的複本不動）──
// 注意：git rm 只移除最新版，檔案仍留在 git 歷史；要連歷史一併清除須用 eg_bk_purge_history()
function eg_bk_delete_backup(PDO $pdo, int $logId, string $by): array {
    $st = $pdo->prepare("SELECT rel_path, filename, status FROM db_backup_log WHERE id=? LIMIT 1");
    $st->execute([$logId]);
    $row = $st->fetch(PDO::FETCH_ASSOC);
    if (!$row) return ['ok'=>false,'msg'=>'找不到該筆備份'];
    if ($row['status'] === 'deleted') return ['ok'=>false,'msg'=>'該備份已刪除'];

    $abs = BK_REPO . '\\' . str_replace('/', '\\', $row['rel_path']);
    $inGit = false;
    if (is_file($abs)) {
        [$rc] = eg_bk_git(['rm', '-q', '--', $row['rel_path']]);   // git rm 會一併刪工作區檔
        if ($rc !== 0) { @unlink($abs); }                            // 不在 git 索引就直接刪檔
        else $inGit = true;
    } else {
        // 工作區已被 prune,但可能仍在 git 歷史（僅能靠 purge 清除）
    }
    if ($inGit) {
        eg_bk_git(['commit', '-m', "delete: {$row['filename']}（{$by} 手動刪除）"]);
        // 刪除的目的是防資料外流 → 不看 auto_push 設定,一律立即推送雲端
        eg_bk_git(['push', 'origin', 'HEAD']);
    }
    $pdo->prepare("UPDATE db_backup_log SET status='deleted', note=CONCAT(COALESCE(note,''),' [', ?, ' 刪除;歷史仍在git,徹底清除請用清除歷史]') WHERE id=?")
        ->execute([$by, $logId]);
    return ['ok'=>true,'msg'=>'已刪除備份 ' . $row['filename'] . '（Git 歷史中仍有殘留，要徹底清除請執行「徹底清除Git歷史」）'];
}

// ── 徹底清除 Git 歷史：把備份庫壓縮成「只含目前工作區」的單一新版本並強制推送 ──
// 原理：orphan branch squash——舊 commit 全數變成無法到達,本地 gc 立即回收,
// GitHub 端舊資料失去參照後由其垃圾回收清除（非即時,一般數週內;急件需向 GitHub 客服申請）。
function eg_bk_purge_history(PDO $pdo, string $by): array {
    // 先確認工作區乾淨（避免壓縮時把未提交狀態弄丟）
    [$sc, $sout] = eg_bk_git(['status', '--porcelain']);
    if (trim($sout) !== '') {
        eg_bk_git(['add', '-A']);
        eg_bk_git(['commit', '-m', 'auto: 清除歷史前先提交工作區']);
    }
    [$c1, $o1] = eg_bk_git(['checkout', '--orphan', '_purge_tmp']);
    if ($c1 !== 0) return ['ok'=>false,'msg'=>'建立壓縮分支失敗：' . mb_substr($o1, 0, 300)];
    eg_bk_git(['add', '-A']);
    [$c2, $o2] = eg_bk_git(['commit', '-m', "purge: 歷史壓縮為單一版本（{$by} 執行,防資料外流）"]);
    if ($c2 !== 0) { eg_bk_git(['checkout', 'master']); eg_bk_git(['branch', '-D', '_purge_tmp']); return ['ok'=>false,'msg'=>'壓縮提交失敗：' . mb_substr($o2, 0, 300)]; }
    eg_bk_git(['branch', '-M', 'master']);                            // 取代 master
    [$c3, $o3] = eg_bk_git(['push', '-f', 'origin', 'master']);       // 強制推送(清雲端歷史一定要推,不看 auto_push)
    // 本地立即回收舊物件
    eg_bk_git(['reflog', 'expire', '--expire=now', '--all']);
    eg_bk_git(['gc', '--prune=now', '--aggressive']);
    // 歷史沒了 → 已 prune 的備份無法再從歷史取回,把 log 上的 git_commit 全部清空並註記
    $pdo->exec("UPDATE db_backup_log SET git_commit=NULL WHERE status IN ('success','deleted')");
    // 已刪除列的備註同步更新（不再顯示「歷史仍在git」的過期提示）
    $pdo->exec("UPDATE db_backup_log
                SET note = REPLACE(note, '刪除;歷史仍在git,徹底清除請用清除歷史]', '刪除;歷史已徹底清除]')
                WHERE status='deleted' AND note LIKE '%歷史仍在git%'");
    $pushMsg = ($c3 === 0) ? '雲端歷史已強制覆蓋' : ('雲端推送失敗(' . mb_substr($o3, 0, 200) . '),本地歷史已清');
    return ['ok'=>true,'msg'=>'Git 歷史已壓縮成單一版本。' . $pushMsg . '。GitHub 端舊資料將由其垃圾回收機制清除(非即時)。'];
}

// ═════════════════════════ Phase 2：誤刪救援（部分還原） ═════════════════════════
// 把選定備份載入獨立暫存庫 BK_VIEW_DB，之後在其上做值搜尋 / 差異比對 / 逐列還原。
// 載入約需 20 秒（66MB dump），一律由 CLI 工人背景執行，狀態記在 db_backup_config。

if (!defined('BK_VIEW_DB')) define('BK_VIEW_DB', 'egsystem_bkview');

// 檢視區狀態：view_status = none|loading|ready|fail
function eg_bk_view_status(PDO $pdo): array {
    return [
        'status'    => eg_bk_cfg_get($pdo, 'view_status', 'none'),
        'backup_id' => (int)eg_bk_cfg_get($pdo, 'view_backup_id', '0'),
        'loaded_at' => eg_bk_cfg_get($pdo, 'view_loaded_at', ''),
        'error'     => eg_bk_cfg_get($pdo, 'view_error', ''),
    ];
}

// 載入備份到檢視暫存庫（重活，只給 CLI 工人呼叫）
function eg_bk_view_load(PDO $pdo, int $logId): array {
    try {
        eg_bk_cfg_set($pdo, 'view_status', 'loading', 'system');
        eg_bk_cfg_set($pdo, 'view_backup_id', (string)$logId, 'system');
        eg_bk_cfg_set($pdo, 'view_error', '', 'system');

        $r = eg_bk_resolve_sql($pdo, $logId);
        if (!$r['ok']) throw new RuntimeException($r['msg']);

        $pdo->exec("DROP DATABASE IF EXISTS `" . BK_VIEW_DB . "`");
        $pdo->exec("CREATE DATABASE `" . BK_VIEW_DB . "` DEFAULT CHARSET=utf8mb4");

        putenv('MYSQL_PWD=' . BK_DB_PASS);
        $cmd = escapeshellarg(BK_MYSQL)
             . ' --host=' . escapeshellarg(BK_DB_HOST)
             . ' --port=' . escapeshellarg(BK_DB_PORT)
             . ' --user=' . escapeshellarg(BK_DB_USER)
             . ' --default-character-set=utf8mb4'
             . ' ' . escapeshellarg(BK_VIEW_DB)
             . ' < ' . escapeshellarg($r['path']);
        [$code, $out] = eg_bk_exec($cmd);
        putenv('MYSQL_PWD');
        if ($r['temp']) @unlink($r['path']);
        if ($code !== 0) throw new RuntimeException('匯入檢視庫失敗：' . mb_substr($out, 0, 500));

        eg_bk_cfg_set($pdo, 'view_status', 'ready', 'system');
        eg_bk_cfg_set($pdo, 'view_loaded_at', date('Y-m-d H:i:s'), 'system');
        return ['ok'=>true,'msg'=>'檢視庫載入完成'];
    } catch (Throwable $e) {
        eg_bk_cfg_set($pdo, 'view_status', 'fail', 'system');
        eg_bk_cfg_set($pdo, 'view_error', $e->getMessage(), 'system');
        return ['ok'=>false,'msg'=>$e->getMessage()];
    }
}

// 取得某 schema 中所有「基表」清單
function eg_bk_base_tables(PDO $pdo, string $schema): array {
    $st = $pdo->prepare("SELECT table_name FROM information_schema.tables WHERE table_schema=? AND table_type='BASE TABLE' ORDER BY table_name");
    $st->execute([$schema]);
    return $st->fetchAll(PDO::FETCH_COLUMN);
}

// 取得某表的主鍵欄位（依序）；無 PK 回空陣列
function eg_bk_pk_cols(PDO $pdo, string $schema, string $table): array {
    $st = $pdo->prepare("SELECT column_name FROM information_schema.key_column_usage
                         WHERE table_schema=? AND table_name=? AND constraint_name='PRIMARY' ORDER BY ordinal_position");
    $st->execute([$schema, $table]);
    return $st->fetchAll(PDO::FETCH_COLUMN);
}

// 取得某表的欄位清單
function eg_bk_cols(PDO $pdo, string $schema, string $table): array {
    $st = $pdo->prepare("SELECT column_name, data_type FROM information_schema.columns
                         WHERE table_schema=? AND table_name=? ORDER BY ordinal_position");
    $st->execute([$schema, $table]);
    return $st->fetchAll(PDO::FETCH_KEY_PAIR);
}

// 值搜尋：在檢視庫全部基表的文字欄位找關鍵字；回傳各表命中列（含 PK 值與是否仍存在於正式庫）
// $limitPerTable / $limitTotal 防爆量
function eg_bk_view_search(PDO $pdo, string $q, int $limitPerTable = 20, int $limitTotal = 300): array {
    $qLike   = '%' . $q . '%';
    $isNum   = is_numeric($q);
    $results = [];
    $total   = 0;
    $textTypes = ['char','varchar','text','tinytext','mediumtext','longtext','enum','set'];
    $numTypes  = ['int','bigint','smallint','tinyint','mediumint','decimal','float','double'];

    foreach (eg_bk_base_tables($pdo, BK_VIEW_DB) as $t) {
        if ($total >= $limitTotal) break;
        $cols = eg_bk_cols($pdo, BK_VIEW_DB, $t);
        $pk   = eg_bk_pk_cols($pdo, BK_VIEW_DB, $t);
        $conds = []; $binds = [];
        foreach ($cols as $c => $type) {
            if (in_array($type, $textTypes, true)) {
                // CONVERT 統一成 utf8mb4 再比對（處理舊 latin1 欄位中文 LIKE 撈不到的問題）
                $conds[] = "CONVERT(`$c` USING utf8mb4) LIKE ?";
                $binds[] = $qLike;
            } elseif ($isNum && in_array($type, $numTypes, true)) {
                $conds[] = "`$c` = ?";
                $binds[] = $q;
            }
        }
        if (!$conds) continue;
        $sql = "SELECT * FROM `" . BK_VIEW_DB . "`.`$t` WHERE " . implode(' OR ', $conds) . " LIMIT " . (int)$limitPerTable;
        try {
            $st = $pdo->prepare($sql);
            $st->execute($binds);
            $rows = $st->fetchAll(PDO::FETCH_ASSOC);
        } catch (Throwable $e) { continue; }
        if (!$rows) continue;

        foreach ($rows as $row) {
            if ($total >= $limitTotal) break;
            $pkVals = [];
            foreach ($pk as $pc) $pkVals[] = $row[$pc] ?? null;
            // 該列是否仍存在於正式庫（有 PK 才能判斷）
            $existsLive = null;
            if ($pk && !in_array(null, $pkVals, true)) {
                $w = implode(' AND ', array_map(fn($c) => "`$c`=?", $pk));
                try {
                    $chk = $pdo->prepare("SELECT 1 FROM `" . BK_DB_NAME . "`.`$t` WHERE $w LIMIT 1");
                    $chk->execute($pkVals);
                    $existsLive = (bool)$chk->fetchColumn();
                } catch (Throwable $e) { $existsLive = null; }
            }
            // 預覽只留前 8 個非空欄位（避免大 blob 撐爆回應）
            $preview = [];
            foreach ($row as $c => $v) {
                if ($v === null || $v === '') continue;
                $preview[$c] = mb_substr((string)$v, 0, 120);
                if (count($preview) >= 8) break;
            }
            $results[] = ['table'=>$t, 'pk_cols'=>$pk, 'pk_vals'=>$pkVals, 'exists_live'=>$existsLive, 'preview'=>$preview];
            $total++;
        }
    }
    return $results;
}

// 差異掃描：每張有 PK 的基表，計算「備份有、正式庫沒有」的列數（疑似誤刪概覽）
function eg_bk_view_diff_overview(PDO $pdo): array {
    $out = [];
    foreach (eg_bk_base_tables($pdo, BK_VIEW_DB) as $t) {
        $pk = eg_bk_pk_cols($pdo, BK_VIEW_DB, $t);
        if (!$pk) continue;
        // 正式庫也要有這張表才能比
        try {
            $on = implode(' AND ', array_map(fn($c) => "b.`$c` <=> l.`$c`", $pk));
            $sql = "SELECT COUNT(*) FROM `" . BK_VIEW_DB . "`.`$t` b
                    LEFT JOIN `" . BK_DB_NAME . "`.`$t` l ON $on
                    WHERE l.`" . $pk[0] . "` IS NULL";
            $cnt = (int)$pdo->query($sql)->fetchColumn();
        } catch (Throwable $e) { continue; }
        if ($cnt > 0) $out[] = ['table'=>$t, 'missing'=>$cnt];
    }
    return $out;
}

// 差異明細：某表「備份有、正式庫沒有」的列（最多 $limit 列，含 PK 與預覽）
function eg_bk_view_diff_table(PDO $pdo, string $table, int $limit = 200): array {
    if (!preg_match('/^[A-Za-z0-9_]+$/', $table)) return [];
    $pk = eg_bk_pk_cols($pdo, BK_VIEW_DB, $table);
    if (!$pk) return [];
    $on = implode(' AND ', array_map(fn($c) => "b.`$c` <=> l.`$c`", $pk));
    $sql = "SELECT b.* FROM `" . BK_VIEW_DB . "`.`$table` b
            LEFT JOIN `" . BK_DB_NAME . "`.`$table` l ON $on
            WHERE l.`" . $pk[0] . "` IS NULL LIMIT " . (int)$limit;
    try { $rows = $pdo->query($sql)->fetchAll(PDO::FETCH_ASSOC); } catch (Throwable $e) { return []; }
    $out = [];
    foreach ($rows as $row) {
        $pkVals = [];
        foreach ($pk as $pc) $pkVals[] = $row[$pc] ?? null;
        $preview = [];
        foreach ($row as $c => $v) {
            if ($v === null || $v === '') continue;
            $preview[$c] = mb_substr((string)$v, 0, 120);
            if (count($preview) >= 8) break;
        }
        $out[] = ['table'=>$table, 'pk_cols'=>$pk, 'pk_vals'=>$pkVals, 'exists_live'=>false, 'preview'=>$preview];
    }
    return $out;
}

// 逐列還原：把檢視庫中選定 PK 的列寫回正式庫
// $mode: 'insert'（只補回不存在的列）| 'replace'（存在則覆蓋成備份版本）
// $pkList: [[pk值...], ...]（與該表 PK 欄位順序一致）
function eg_bk_view_restore_rows(PDO $pdo, string $table, array $pkList, string $mode = 'insert'): array {
    if (!preg_match('/^[A-Za-z0-9_]+$/', $table)) return ['ok'=>false,'msg'=>'表名不合法','restored'=>0];
    if (!$pkList) return ['ok'=>false,'msg'=>'未選取任何列','restored'=>0];
    if (count($pkList) > 500) return ['ok'=>false,'msg'=>'一次最多還原 500 列','restored'=>0];
    $pk = eg_bk_pk_cols($pdo, BK_VIEW_DB, $table);
    if (!$pk) return ['ok'=>false,'msg'=>'此表無主鍵，無法逐列還原（請改用整表還原）','restored'=>0];

    // 欄位交集（防備份後 schema 增減欄位造成 INSERT 失敗）
    $bCols = array_keys(eg_bk_cols($pdo, BK_VIEW_DB, $table));
    $lCols = array_keys(eg_bk_cols($pdo, BK_DB_NAME, $table));
    $cols  = array_values(array_intersect($bCols, $lCols));
    if (!$cols) return ['ok'=>false,'msg'=>'正式庫無此表或欄位完全不符','restored'=>0];
    $colSql = implode(',', array_map(fn($c) => "`$c`", $cols));

    // (pk1,pk2) IN ((?,?),(?,?)...) 行建構式
    $tuple  = '(' . implode(',', array_map(fn($c) => "b.`$c`", $pk)) . ')';
    $ph     = '(' . implode(',', array_fill(0, count($pk), '?')) . ')';
    $inSql  = implode(',', array_fill(0, count($pkList), $ph));
    $binds  = [];
    foreach ($pkList as $vals) {
        if (count($vals) !== count($pk)) return ['ok'=>false,'msg'=>'PK 值數量不符','restored'=>0];
        foreach ($vals as $v) $binds[] = $v;
    }

    $verb = ($mode === 'replace') ? 'REPLACE' : 'INSERT IGNORE';
    $sql  = "$verb INTO `" . BK_DB_NAME . "`.`$table` ($colSql)
             SELECT $colSql FROM `" . BK_VIEW_DB . "`.`$table` b WHERE $tuple IN ($inSql)";
    try {
        $pdo->exec("SET FOREIGN_KEY_CHECKS=0");
        $pdo->beginTransaction();
        $st = $pdo->prepare($sql);
        $st->execute($binds);
        $n = $st->rowCount();
        $pdo->commit();
        $pdo->exec("SET FOREIGN_KEY_CHECKS=1");
        return ['ok'=>true,'msg'=>"已還原 $n 列",'restored'=>$n];
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        try { $pdo->exec("SET FOREIGN_KEY_CHECKS=1"); } catch (Throwable $e2) {}
        return ['ok'=>false,'msg'=>'還原失敗：' . $e->getMessage(),'restored'=>0];
    }
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
