<?php
/**
 * mig_backup_lib.php — 移機快速備份（換主機用的一鍵打包）
 * 打包內容（依設定勾選）:
 *   1. 資料庫 dump（現場新鮮匯出,官方 mysqldump 9.4）
 *   2. 環境設定快照（php.ini×2 / httpd.conf+extra+ssl / my.ini / DBConnection.php / telegram_config.php）
 *   3. uploads/ 上傳附件
 *   4. NAS 文件（依「路徑設定值」逐項勾選要不要備份其資料夾內容,robocopy 只增不刪）
 *   5. 使用說明.txt（自動產生:內容清單+還原步驟）
 * 設定與狀態存 db_backup_config（鍵前綴 migbk_）;工人=mig_backup_run.php 背景執行,
 * 狀態 JSON 供前端輪詢畫進度條。mysqldump 用 --single-transaction、檔案複製唯讀,
 * 備份期間網頁可正常使用（僅磁碟/網路 IO 增加,尖峰時可能略慢）。
 */

require_once __DIR__ . '/db_backup_lib.php';

// ── 設定 ────────────────────────────────────────────────────────────────────
function eg_migbk_settings(PDO $pdo): array {
    $inc = json_decode(eg_bk_cfg_get($pdo, 'migbk_include_keys', '[]'), true);
    return [
        'dest'            => eg_bk_cfg_get($pdo, 'migbk_dest', 'C:\\EGsystem_migration\\quick_backup'),
        'include_docs'    => eg_bk_cfg_get($pdo, 'migbk_include_docs', '1') === '1', // 是否備份NAS文件
        'include_keys'    => is_array($inc) ? $inc : [],                              // 勾選的路徑設定鍵
        'include_uploads' => eg_bk_cfg_get($pdo, 'migbk_include_uploads', '1') === '1',
        'interval_days'   => (int)eg_bk_cfg_get($pdo, 'migbk_interval_days', '0'),    // 0=不自動(順路)備份
        'last_ok'         => eg_bk_cfg_get($pdo, 'migbk_last_ok', ''),
    ];
}
function eg_migbk_save_settings(PDO $pdo, array $s, string $by): void {
    if (isset($s['dest']))            eg_bk_cfg_set($pdo, 'migbk_dest', trim((string)$s['dest']), $by);
    if (isset($s['include_docs']))    eg_bk_cfg_set($pdo, 'migbk_include_docs', $s['include_docs'] ? '1' : '0', $by);
    if (isset($s['include_keys']))    eg_bk_cfg_set($pdo, 'migbk_include_keys', json_encode(array_values($s['include_keys']), JSON_UNESCAPED_UNICODE), $by);
    if (isset($s['include_uploads'])) eg_bk_cfg_set($pdo, 'migbk_include_uploads', $s['include_uploads'] ? '1' : '0', $by);
    if (isset($s['interval_days']))   eg_bk_cfg_set($pdo, 'migbk_interval_days', (string)max(0, (int)$s['interval_days']), $by);
}

// ── 狀態（JSON,前端輪詢用）──────────────────────────────────────────────────
function eg_migbk_status(PDO $pdo): array {
    $j = json_decode(eg_bk_cfg_get($pdo, 'migbk_status', '{}'), true);
    return is_array($j) ? $j : [];
}
function eg_migbk_set_status(PDO $pdo, array $st): void {
    eg_bk_cfg_set($pdo, 'migbk_status', json_encode($st, JSON_UNESCAPED_UNICODE), 'system');
}

// ── 環境設定快照的固定來源清單 ───────────────────────────────────────────────
function eg_migbk_config_files(): array {
    return [
        'C:\\MAMP\\conf\\php8.3.1\\php.ini'                       => 'config_snapshot\\MAMP\\conf\\php8.3.1\\php.ini',
        'C:\\MAMP\\bin\\php\\php8.3.1\\php.ini'                   => 'config_snapshot\\MAMP\\bin\\php\\php8.3.1\\php.ini',
        'C:\\MAMP\\conf\\apache\\httpd.conf'                      => 'config_snapshot\\MAMP\\conf\\apache\\httpd.conf',
        'C:\\ProgramData\\MySQL\\MySQL Server 9.4\\my.ini'        => 'config_snapshot\\MySQL\\my.ini',
        'C:\\MAMP\\htdocs\\EGsystem\\src\\common\\DBConnection.php' => 'config_snapshot\\EGsystem\\src\\common\\DBConnection.php',
        'C:\\MAMP\\htdocs\\EGsystem\\config\\telegram_config.php'   => 'config_snapshot\\EGsystem\\config\\telegram_config.php',
    ];
}
function eg_migbk_config_dirs(): array {
    return [
        'C:\\MAMP\\conf\\apache\\extra' => 'config_snapshot\\MAMP\\conf\\apache\\extra',
        'C:\\MAMP\\conf\\apache\\ssl'   => 'config_snapshot\\MAMP\\conf\\apache\\ssl',
    ];
}

// ── 資料夾大小估算（估預計時間用;NAS 大目錄可能要掃幾十秒,只在工人內跑）──────
function eg_migbk_dir_size(string $dir): int {
    $total = 0;
    try {
        $it = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($dir, FilesystemIterator::SKIP_DOTS),
            RecursiveIteratorIterator::LEAVES_ONLY,
            RecursiveIteratorIterator::CATCH_GET_CHILD // 個別子夾讀不到就跳過
        );
        foreach ($it as $f) { if ($f->isFile()) $total += $f->getSize(); }
    } catch (Throwable $e) {}
    return $total;
}

// ── 取得要備份的 NAS 文件來源清單（依勾選的設定鍵 → 目前設定值路徑）──────────
function eg_migbk_doc_sources(PDO $pdo): array {
    require_once __DIR__ . '/path_migration_lib.php';
    $s = eg_migbk_settings($pdo);
    if (!$s['include_docs'] || !$s['include_keys']) return [];
    $out = [];
    $inv = eg_pm_inventory($pdo);
    foreach ($inv['settings'] as $it) {
        if ($it['kind'] !== 'fs' || $it['value'] === '') continue;
        if (!in_array($it['key'], $s['include_keys'], true)) continue;
        $out[] = ['key'=>$it['key'], 'label'=>$it['label'], 'path'=>rtrim($it['value'], "\\/")];
    }
    return $out;
}

// ── 啟動（背景工人）────────────────────────────────────────────────────────
function eg_migbk_start(PDO $pdo, string $by, string $trigger = 'manual'): array {
    $st = eg_migbk_status($pdo);
    if (($st['state'] ?? '') === 'scanning' || ($st['state'] ?? '') === 'running') {
        return ['ok'=>false, 'msg'=>'已有一個移機備份進行中'];
    }
    $s = eg_migbk_settings($pdo);
    if (trim($s['dest']) === '') return ['ok'=>false, 'msg'=>'請先設定備份存放位置'];
    eg_migbk_set_status($pdo, ['state'=>'scanning', 'trigger'=>$trigger, 'by'=>$by,
                               'started_at'=>date('Y-m-d H:i:s'), 'total_bytes'=>0, 'done_bytes'=>0,
                               'current'=>'佇列中…', 'msg'=>'']);
    $script = realpath(__DIR__ . '/mig_backup_run.php');
    if (!is_file(BK_PHP) || !$script) return ['ok'=>false,'msg'=>'找不到備份工人程式'];
    $cmd = 'start /B "" "' . BK_PHP . '" "' . $script . '" ' . escapeshellarg($trigger) . ' >NUL 2>&1';
    $h = @popen($cmd, 'r'); if ($h) @pclose($h);
    return ['ok'=>true,'msg'=>'移機備份已開始(先估算大小,再複製並顯示進度)。備份期間網頁可正常使用。'];
}
