<?php
/**
 * inventor_preview_lib.php — 把 IPT（Inventor 零件檔）／X_T（Parasolid 文字交換檔）
 * 轉成 STEP 供瀏覽器內 3D 檢視（唯一實作）。
 *
 * 背景（2026-10-08，接續 DWG 線上預覽之後，使用者問 IPT／X_T 能不能做到一樣的事）：
 * 這兩種都是專屬二進位/交換格式，開源 3D 檢視器（o3dv.min.js）讀不懂；本機裝有
 * 完整版 Autodesk Inventor 2025，可以把它們轉成 STEP，STEP 這條路線已經在
 * bom_viewer.php／master_data_management.php 的 3D 檢視器裡驗證過可正常顯示，所以
 * 轉檔的目的地固定是 STEP，不是最終呈現格式。
 *
 * 跟 DWG（AutoCAD accoreconsole.exe）完全不同的技術路線：Inventor 沒有無人值守的
 * 命令列批次引擎，自動化只能走 COM（Inventor.Application）。COM 呼叫邏輯寫在
 * 同目錄的 `inventor_preview_convert.ps1`（唯一轉檔邏輯，含已踩過的陷阱說明）。
 *
 * 安全機制（比 DWG 更進一步，因為 Inventor 是真正的桌面應用、使用者可能同時開著）：
 *  - 每次呼叫前先拍一張「現在有哪些 Inventor.exe 行程」的快照，呼叫後再拍一張，
 *    只處理這次新長出來的 pid；使用者自己開著的 Inventor 視窗（不論是不是跟這次
 *    轉檔同時發生）絕對不會被動到（鐵律9 的精神延伸：不是「只殺特定 pid」而是
 *    「連判斷要不要殺之前，都先確認那個 pid 不是使用者自己的」）。
 *  - PowerShell 行程本身：陣列命令＋bypass_shell、stdout/stderr 導向檔案、逾時用
 *    精確 pid 的 taskkill（與 dwg_preview_lib.php 同一套，避免同樣的 proc_open 陷阱）。
 *  - COM 自動化比 accoreconsole 慢很多（實測開啟+轉檔約 9~10 秒，不是 1.2 秒），
 *    逾時門檻拉長到 45 秒；上傳後背景預轉檔（見 eg_inventor_preview_trigger_async）
 *    因此格外重要，不然每次點開預覽都要先等將近 10 秒。
 *
 * 快取路徑一律「即時組出」（鐵律5），規則與 dwg_preview_lib.php 相同：DB 不記錄
 * 轉檔結果，快取檔名＝來源真實路徑＋mtime＋轉檔配方版號的雜湊。
 */

if (!function_exists('eg_inventor_preview_interop_dll')) {
if (!defined('EG_INVENTOR_PREVIEW_RENDER_VER')) define('EG_INVENTOR_PREVIEW_RENDER_VER', 1);

/** Inventor .NET Interop 組件路徑，可在 system_settings 覆寫（設定鍵 inventor_interop_dll） */
function eg_inventor_preview_interop_dll(PDO $db): string {
    try {
        $st = $db->prepare("SELECT setting_value FROM system_settings WHERE setting_key='inventor_interop_dll'");
        $st->execute();
        $v = $st->fetchColumn();
        if ($v !== false && $v !== null && trim((string)$v) !== '') return trim((string)$v);
    } catch (Throwable $e) {}
    return 'C:\\Program Files\\Autodesk\\Inventor 2025\\Bin\\Autodesk.Inventor.Interop.dll';
}

/** PowerShell 執行檔路徑，可在 system_settings 覆寫（設定鍵 inventor_powershell_exe） */
function eg_inventor_preview_powershell_exe(PDO $db): string {
    try {
        $st = $db->prepare("SELECT setting_value FROM system_settings WHERE setting_key='inventor_powershell_exe'");
        $st->execute();
        $v = $st->fetchColumn();
        if ($v !== false && $v !== null && trim((string)$v) !== '') return trim((string)$v);
    } catch (Throwable $e) {}
    return 'C:\\Windows\\System32\\WindowsPowerShell\\v1.0\\powershell.exe';
}

/** 這台機器有沒有裝 Inventor（供畫面判斷要不要顯示 IPT/X_T 預覽入口） */
function eg_inventor_preview_available(PDO $db): bool {
    return is_file(eg_inventor_preview_interop_dll($db)) && is_file(eg_inventor_preview_powershell_exe($db));
}

/** 轉檔快取目錄（系統暫存區，不放 NAS；與 DWG 分開避免檔名雜湊萬一撞在一起） */
function eg_inventor_preview_cache_dir(): string {
    $d = rtrim(sys_get_temp_dir(), '/\\') . DIRECTORY_SEPARATOR . 'eg_inventor_step_cache';
    if (!is_dir($d)) @mkdir($d, 0777, true);
    return $d;
}

/** 目前有哪些 Inventor.exe 行程（pid 陣列）——呼叫前後各拍一次，只處理新長出來的 */
function eg_inventor_preview_running_pids(): array {
    $out = [];
    @exec('wmic process where "name=\'Inventor.exe\'" get ProcessId 2>NUL', $out);
    $pids = [];
    foreach ($out as $line) {
        $line = trim($line);
        if ($line !== '' && ctype_digit($line)) $pids[] = (int)$line;
    }
    return $pids;
}

/**
 * 把 IPT/X_T 轉成 STEP 並回傳快取後的 STEP 實體路徑；已有有效快取（來源 mtime 相同）
 * 就直接回傳不重轉。失敗回 null。
 */
function eg_inventor_preview_step(PDO $db, string $srcFsPath): ?string {
    if (!is_file($srcFsPath)) return null;
    $interop = eg_inventor_preview_interop_dll($db);
    $pwsh    = eg_inventor_preview_powershell_exe($db);
    if (!is_file($interop) || !is_file($pwsh)) return null;

    $mtime = @filemtime($srcFsPath) ?: 0;
    $sig   = sha1($srcFsPath);
    $key   = $sig . '_' . $mtime . '_v' . EG_INVENTOR_PREVIEW_RENDER_VER;
    $dir   = eg_inventor_preview_cache_dir();
    $step  = $dir . DIRECTORY_SEPARATOR . $key . '.stp';
    if (is_file($step) && filesize($step) > 0) return $step;

    // 同一份檔案不要讓多個請求同時各自叫一次 Inventor：用 lock 檔序列化
    $lockFile = $dir . DIRECTORY_SEPARATOR . $sig . '.lock';
    $lh = @fopen($lockFile, 'c');
    if (!$lh) return null;
    try {
        if (!flock($lh, LOCK_EX)) return null;
        if (is_file($step) && filesize($step) > 0) return $step;   // 拿到鎖時可能別人剛轉完
        foreach (@glob($dir . DIRECTORY_SEPARATOR . $sig . '_*.stp') ?: [] as $old) {
            if ($old !== $step) @unlink($old);
        }
        $ok = eg_inventor_preview_run($pwsh, $interop, $srcFsPath, $step);
        return ($ok && is_file($step) && filesize($step) > 0) ? $step : null;
    } finally {
        flock($lh, LOCK_UN);
        fclose($lh);
        @unlink($lockFile);
    }
}

/** 真正呼叫 PowerShell + Inventor COM 轉檔（含行程安全防護） */
function eg_inventor_preview_run(string $pwsh, string $interop, string $srcFsPath, string $outStep): bool {
    $dir    = eg_inventor_preview_cache_dir();
    $script = __DIR__ . DIRECTORY_SEPARATOR . 'inventor_preview_convert.ps1';
    $outLog = $dir . DIRECTORY_SEPARATOR . 'p_' . bin2hex(random_bytes(4)) . '.out.log';
    $errLog = $outLog . '.err';

    // 呼叫前先拍一張「現在已經在跑的 Inventor.exe」快照——不論是不是使用者自己開的
    // 視窗，這次呼叫新長出來的 pid 之外一律不碰（見本檔案最上方說明）
    $beforePids = eg_inventor_preview_running_pids();

    @unlink($outStep);
    $cmdArr = [
        $pwsh, '-NoProfile', '-NonInteractive', '-ExecutionPolicy', 'Bypass',
        '-File', $script, '-InPath', $srcFsPath, '-OutPath', $outStep, '-InteropDll', $interop,
    ];
    $descriptors = [
        0 => ['file', 'NUL', 'r'],
        1 => ['file', $outLog, 'w'],
        2 => ['file', $errLog, 'w'],
    ];
    $pipes = [];
    $proc = @proc_open($cmdArr, $descriptors, $pipes, $dir, null, ['bypass_shell' => true]);
    $ok = false;
    if (is_resource($proc)) {
        $start = time();
        $pshPid = 0;
        while (true) {
            $status = proc_get_status($proc);
            if (!$pshPid && !empty($status['pid'])) $pshPid = (int)$status['pid'];
            if (!$status['running']) { $ok = true; break; }
            if (time() - $start > 45) {   // COM 啟動+轉檔實測約9~10秒，45秒逾時留足夠餘裕
                if ($pshPid) { @exec('taskkill /F /T /PID ' . $pshPid . ' 2>&1'); }
                $killStart = time();
                while (time() - $killStart < 3) {
                    $s2 = @proc_get_status($proc);
                    if (empty($s2['running'])) { $proc = null; break; }
                    usleep(200000);
                }
                break;
            }
            usleep(300000);
        }
        if ($proc) { @proc_close($proc); }
    }

    // 收尾安全網：不論成功/逾時/例外，都比對「這次呼叫新長出來的 Inventor.exe」，
    // 正常情況下 .ps1 自己的 finally 已經 Quit() 過、這裡不會抓到東西；只有在
    // PowerShell 本身被逾時強制終止、來不及執行自己的 finally 時才會補一刀，而且
    // 只會殺呼叫前快照裡沒有的 pid——使用者自己開的視窗一定在 beforePids 裡，不會誤殺
    $afterPids = eg_inventor_preview_running_pids();
    foreach ($afterPids as $p) {
        if (!in_array($p, $beforePids, true)) {
            @exec('taskkill /F /T /PID ' . $p . ' 2>&1');
        }
    }

    $result = null;
    if (is_file($outLog)) $result = trim((string)@file_get_contents($outLog));
    $success = ($ok && $result === 'OK' && is_file($outStep) && filesize($outStep) > 0);
    if ($success) {
        @unlink($outLog); @unlink($errLog);
    }
    return $success;
}

/**
 * 上傳成功後立刻背景觸發一次轉檔（fire-and-forget，不等它跑完、不影響上傳回應速度）。
 * 做法與 dwg_preview_lib.php 的 eg_dwg_preview_trigger_async() 完全相同：proc_open
 * 一個獨立 PHP CLI 子行程、刻意不呼叫 proc_close()，子行程在 Windows 上會在父行程
 * （這支 HTTP 請求）結束後繼續跑完（已用對照測試驗證過）。COM 自動化比 accoreconsole
 * 慢很多（約9~10秒），背景預轉檔因此格外重要——不然每次點開 IPT/X_T 預覽都要先等。
 */
function eg_inventor_preview_trigger_async(PDO $db, string $srcFsPath): void {
    if (!is_file($srcFsPath)) return;
    if (!eg_inventor_preview_available($db)) return;
    try {
        // PHP CLI 路徑與 DWG 背景觸發共用同一個設定鍵（同一台機器只有一個 php.exe，
        // 沒理由分開存兩次，見 dwg_preview_lib.php 的 eg_dwg_preview_php_cli()）
        require_once __DIR__ . '/dwg_preview_lib.php';
        $php = eg_dwg_preview_php_cli($db);
        if (!is_file($php)) return;
        $script = __DIR__ . DIRECTORY_SEPARATOR . 'inventor_preview_cli.php';
        $descriptors = [0 => ['file', 'NUL', 'r'], 1 => ['file', 'NUL', 'w'], 2 => ['file', 'NUL', 'w']];
        $pipes = [];
        @proc_open([$php, $script, $srcFsPath], $descriptors, $pipes, null, null, ['bypass_shell' => true]);
    } catch (Throwable $e) {}
}

}
