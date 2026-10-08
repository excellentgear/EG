<?php
/**
 * dwg_preview_lib.php — 把 DWG 轉成 PDF 供瀏覽器內檢視（唯一實作）。
 *
 * 背景（2026-10-08，使用者問「附件可不可以像 PDF 一樣檢視 DWG」）：DWG 是 AutoCAD
 * 私有二進位格式，瀏覽器與前端 JS 都讀不懂，不可能做到「純前端縮放平移」；本機
 * （虛擬主機）裝有完整版 AutoCAD 2025，使用者電腦沒有，所以一定要在伺服器端先轉成
 * PDF，之後直接沿用全站既有的 PDF 檢視器（iframe 內嵌），前端完全不必另外寫縮放
 * 平移邏輯。
 *
 * 做法：呼叫 AutoCAD 隨附的 `accoreconsole.exe`（無人值守批次引擎，不開 GUI、不跳
 * 授權視窗），用 -PLOT 指令腳本把圖轉存 PDF。實測單張（含冷啟動）約 1.1~1.2 秒，
 * 轉出來的 PDF 會快取，同一份圖第二次開啟直接讀快取、不再叫用 AutoCAD。
 *
 * 腳本檔編碼陷阱：這台機器是繁體中文版 AutoCAD，-PLOT 的提示值（如紙張尺寸字串
 * 「公釐」）一定要用系統內碼（Big5/cp950）寫腳本檔——UTF-8 會讓 AutoCAD 比對不到
 * 合法值，流程卡在某個提示上不動且不報錯（已實測踩過兩次才找出來）。
 *
 * 快取路徑一律「即時組出」（鐵律5）：DB 不記錄任何轉檔結果，快取檔名＝來源檔案
 * 真實路徑＋mtime 的雜湊，來源檔案換了（mtime 變）自動轉新的，不會讀到舊圖；換圖
 * 之後的舊快取在下一次轉檔時順手清掉。
 *
 * 失敗一律回 null（AutoCAD 忙線／逾時／找不到引擎／轉出空檔都算），呼叫端要退回
 * 既有「下載開啟」，不可以讓使用者看到比改之前更糟的狀況。
 */

if (!function_exists('eg_dwg_preview_accoreconsole')) {

// 轉檔配方（PLOT 腳本內容、樣式）有實質改動時遞增——快取鍵帶著這個版號，
// 舊配方轉出的快取會自動被視為過期重轉，不必手動清快取資料夾。
// （const 在條件區塊內不合法，這裡一律用 define）
if (!defined('EG_DWG_PREVIEW_RENDER_VER')) define('EG_DWG_PREVIEW_RENDER_VER', 2);   // v2：改用 monochrome.ctb 全黑出圖

/** accoreconsole.exe 路徑，可在 system_settings 覆寫（設定鍵 dwg_accoreconsole_exe） */
function eg_dwg_preview_accoreconsole(PDO $db): string {
    try {
        $st = $db->prepare("SELECT setting_value FROM system_settings WHERE setting_key='dwg_accoreconsole_exe'");
        $st->execute();
        $v = $st->fetchColumn();
        if ($v !== false && $v !== null && trim((string)$v) !== '') return trim((string)$v);
    } catch (Throwable $e) {}
    return 'C:\\Program Files\\Autodesk\\AutoCAD 2025\\accoreconsole.exe';
}

/** 這台機器有沒有裝轉檔引擎（供畫面判斷要不要顯示 DWG 預覽入口） */
function eg_dwg_preview_available(PDO $db): bool {
    return is_file(eg_dwg_preview_accoreconsole($db));
}

/** 全黑出圖樣式表路徑，可在 system_settings 覆寫（設定鍵 dwg_monochrome_ctb）——
 *  圖面原本的圖層顏色（黃/綠/青等 ACI 色）拿來螢幕上看沒問題，直接印成 PDF 卻會
 *  淺色線條在白底幾乎看不見；monochrome.ctb 是 AutoCAD 內建、把所有顏色統一印成
 *  黑色的出圖樣式表，各版 AutoCAD 安裝都會自動產生一份，一般不必調整這個設定。
 *  找不到就退回不套用（沿用原圖層顏色），不會因此讓轉檔整個失敗。 */
function eg_dwg_preview_monochrome_ctb(PDO $db): string {
    try {
        $st = $db->prepare("SELECT setting_value FROM system_settings WHERE setting_key='dwg_monochrome_ctb'");
        $st->execute();
        $v = $st->fetchColumn();
        if ($v !== false && $v !== null && trim((string)$v) !== '') return trim((string)$v);
    } catch (Throwable $e) {}
    return 'C:\\Users\\Ellen\\AppData\\Roaming\\Autodesk\\AutoCAD 2025\\R25.0\\cht\\Plotters\\Plot Styles\\monochrome.ctb';
}

/** PHP CLI 執行檔路徑，可在 system_settings 覆寫（設定鍵 dwg_php_cli_exe） */
function eg_dwg_preview_php_cli(PDO $db): string {
    try {
        $st = $db->prepare("SELECT setting_value FROM system_settings WHERE setting_key='dwg_php_cli_exe'");
        $st->execute();
        $v = $st->fetchColumn();
        if ($v !== false && $v !== null && trim((string)$v) !== '') return trim((string)$v);
    } catch (Throwable $e) {}
    return 'C:\\MAMP\\bin\\php\\php8.3.1\\php.exe';
}

/**
 * 上傳成功後立刻背景觸發一次轉檔（fire-and-forget，不等它跑完、不影響上傳回應速度）。
 * 這樣使用者之後點開預覽時多半早就轉好、直接命中快取；就算背景觸發失敗或還沒轉完，
 * 點開當下仍會照 eg_dwg_preview_pdf() 原本的路徑補轉一次，兩條路徑共用同一份快取鍵，
 * 不會轉兩次也不會互相影響。
 *
 * 做法：proc_open 一個獨立的 PHP CLI 子行程（陣列命令＋bypass_shell，三個輸出入口
 * 全部導向 NUL），**刻意不呼叫 proc_close()**——子行程在 Windows 上是獨立行程，父行程
 * （這支 HTTP 請求）結束後它會繼續跑完，已用對照測試驗證過（父請求 46ms 內返回、
 * 子行程仍在之後第 5 秒正常完成並寫出結果）。子行程本身呼叫的 eg_dwg_preview_pdf()
 * 自帶 25 秒逾時與強制關閉保護，不會因為沒人等它就失控佔用資源。
 */
function eg_dwg_preview_trigger_async(PDO $db, string $dwgFsPath): void {
    if (!is_file($dwgFsPath)) return;
    if (!eg_dwg_preview_available($db)) return;   // 這台機器沒裝轉檔引擎就不要白觸發
    try {
        $php = eg_dwg_preview_php_cli($db);
        if (!is_file($php)) return;
        $script = __DIR__ . DIRECTORY_SEPARATOR . 'dwg_preview_cli.php';
        $descriptors = [0 => ['file', 'NUL', 'r'], 1 => ['file', 'NUL', 'w'], 2 => ['file', 'NUL', 'w']];
        $pipes = [];
        @proc_open([$php, $script, $dwgFsPath], $descriptors, $pipes, null, null, ['bypass_shell' => true]);
        // 刻意不呼叫 proc_close()／不等待：讓子行程獨立存活，父請求立刻往下送出回應
    } catch (Throwable $e) {}
}

/** 轉檔快取目錄（系統暫存區，不放 NAS） */
function eg_dwg_preview_cache_dir(): string {
    $d = rtrim(sys_get_temp_dir(), '/\\') . DIRECTORY_SEPARATOR . 'eg_dwg_pdf_cache';
    if (!is_dir($d)) @mkdir($d, 0777, true);
    return $d;
}

/**
 * 把 DWG 轉成 PDF 並回傳快取後的 PDF 實體路徑；已有有效快取（來源 mtime 相同）
 * 就直接回傳不重轉。失敗回 null。
 */
function eg_dwg_preview_pdf(PDO $db, string $dwgFsPath): ?string {
    if (!is_file($dwgFsPath)) return null;
    $exe = eg_dwg_preview_accoreconsole($db);
    if (!is_file($exe)) return null;

    $mtime = @filemtime($dwgFsPath) ?: 0;
    $sig   = sha1($dwgFsPath);
    $key   = $sig . '_' . $mtime . '_v' . EG_DWG_PREVIEW_RENDER_VER;
    $dir   = eg_dwg_preview_cache_dir();
    $pdf   = $dir . DIRECTORY_SEPARATOR . $key . '.pdf';
    if (is_file($pdf) && filesize($pdf) > 0) return $pdf;

    // 同一份檔案不要讓多個請求同時各自叫一次 AutoCAD：用 lock 檔序列化
    $lockFile = $dir . DIRECTORY_SEPARATOR . $sig . '.lock';
    $lh = @fopen($lockFile, 'c');
    if (!$lh) return null;
    try {
        if (!flock($lh, LOCK_EX)) return null;
        // 拿到鎖之後有可能別人剛好轉完了
        if (is_file($pdf) && filesize($pdf) > 0) return $pdf;
        // 來源檔換版（mtime 不同）或轉檔配方改版（EG_DWG_PREVIEW_RENDER_VER 不同）
        // 的舊快取一律清掉，避免累積，也避免配方改了還讀到舊樣式的快取
        foreach (@glob($dir . DIRECTORY_SEPARATOR . $sig . '_*.pdf') ?: [] as $old) {
            if ($old !== $pdf) @unlink($old);
        }
        $ctb = eg_dwg_preview_monochrome_ctb($db);
        $ok = eg_dwg_preview_run($exe, $dwgFsPath, $pdf, $ctb);
        return ($ok && is_file($pdf) && filesize($pdf) > 0) ? $pdf : null;
    } finally {
        flock($lh, LOCK_UN);
        fclose($lh);
        @unlink($lockFile);
    }
}

/**
 * 真正呼叫 accoreconsole 轉檔。PLOT 腳本固定用「ISO A4 公釐／橫式／實際範圍／
 * 佈滿」——不管圖面原始尺寸多大，佈滿(Fit)都會自動縮放塞進紙張，不必逐張判斷
 * 原始尺寸；每一步提示的回答順序與確切字串是用這台機器實測對過的，換了
 * AutoCAD 版本或語系要重新核對（見本檔案最上方說明）。
 */
function eg_dwg_preview_run(string $exe, string $dwgFsPath, string $outPdf, string $ctb = ''): bool {
    $dir = eg_dwg_preview_cache_dir();
    $scr = $dir . DIRECTORY_SEPARATOR . 'p_' . bin2hex(random_bytes(6)) . '.scr';
    // 出圖型式表（第 13 個答案）：圖面原本的圖層顏色（黃/綠/青等）直接印出來在白底
    // PDF 上太淺看不清楚，給 monochrome.ctb 會把所有顏色統一印成黑色；找不到檔案
    // 就退回「.」＝不套用、維持原圖層顏色（寧可可讀性差一點也不要讓轉檔整個失敗）。
    $plotStyle = ($ctb !== '' && is_file($ctb)) ? $ctb : '.';
    $lines = [
        'FILEDIA 0', '-PLOT', 'Y', 'Model', 'DWG To PDF.pc3',
        'ISO A4 (210.00 x 297.00 公釐)', 'M', 'L', 'N', 'Extents',
        'F', 'C', 'Y', $plotStyle, 'Y', 'A', $outPdf, 'Y',
    ];
    // 結尾一定要有一個空白行（Enter）給最後「繼續出圖 <Y>」那個預設值提示——
    // implode() 不會像逐行寫檔那樣幫最後一個空字串元素補換行，少了這一行腳本檔
    // 會在這裡卡住等輸入，使用者看到的是「轉換中」永遠轉不出來（已實測踩到）。
    $content = implode("\r\n", $lines) . "\r\n\r\n";
    // AutoCAD 腳本檔要用系統內碼（繁中 Windows=Big5），UTF-8 會讓中文提示值比對不到
    $big5 = @mb_convert_encoding($content, 'BIG5', 'UTF-8');
    if ($big5 === false || $big5 === '') $big5 = $content;
    if (@file_put_contents($scr, $big5) === false) return false;

    @unlink($outPdf);
    $outLog = $dir . DIRECTORY_SEPARATOR . 'p_' . bin2hex(random_bytes(4)) . '.out.log';
    $errLog = $outLog . '.err';
    // 命令一律用陣列形式：Windows 下 proc_open 收到字串命令會經過 cmd.exe 包一層，
    // proc_get_status() 拿到的 pid 是那層外殼，殺不到真正的 accoreconsole.exe；
    // 陣列形式＋bypass_shell 直接呼叫，pid 才是本尊。
    // stdout/stderr 刻意導向「檔案」不是管線——Windows 下 proc_open 的管線無法可靠設成
    // 非阻塞（PHP 在 Windows 上行之有年的已知限制），拿管線邊轉邊讀會讓 fread() 整個
    // 卡住、逾時檢查永遠輪不到執行（實測踩到：卡了快 4 分鐘逾時保護完全沒反應）；
    // 檔案不會有這個問題，AutoCAD 開場那堆訊息直接寫檔，迴圈只需要輪詢行程狀態。
    // 工作目錄明確指到暫存快取資料夾（AutoCAD 會在工作目錄寫自己的 log/暫存檔，
    // 指到網站原始碼目錄下可能沒有寫入權限）。
    // stdin 導向 NUL（不是管線）：管線在 proc_open 開好之後立刻 fclose 會送出 EOF，
    // 實測這會讓 -PLOT 腳本跑到最後一個「按 Enter 接受預設值」的提示卡住不動——
    // PowerShell 的 Start-Process 完全沒有重導 stdin（維持原生終端機語意）就正常，
    // 換成 NUL 既不會真的被讀到任何輸入，也不會有「輸入端已關閉」這種额外訊號。
    $cmdArr = [$exe, '/i', $dwgFsPath, '/s', $scr];
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
        $pid = 0;
        while (true) {
            $status = proc_get_status($proc);
            if (!$pid && !empty($status['pid'])) $pid = (int)$status['pid'];
            if (!$status['running']) { $ok = true; break; }
            if (time() - $start > 25) {   // 逾時保護，正常每張約1.2秒
                // Windows 下 proc_terminate() 不可靠、proc_close() 會卡住等一個永遠不會
                // 結束的行程；改用精確指定 pid 的 taskkill（只殺這支自己剛啟動的行程，
                // 不是整個程式名稱，不影響別人正在用的 AutoCAD／見鐵律9）。
                if ($pid) { @exec('taskkill /F /T /PID ' . $pid . ' 2>&1'); }
                // 確認真的死了才敢呼叫 proc_close()（它會等到行程結束才返回）；
                // 給 3 秒緩衝，還沒死就直接放棄、不呼叫 proc_close，寧可讓這個
                // PHP 處理序正常結束，也不要讓它被卡死的子行程拖著一起卡住。
                $killStart = time();
                while (time() - $killStart < 3) {
                    $s2 = @proc_get_status($proc);
                    if (empty($s2['running'])) { $proc = null; break; }
                    usleep(200000);
                }
                break;
            }
            usleep(200000);
        }
        if ($proc) { @proc_close($proc); }
    }
    @unlink($scr);
    // 成功就不留偵錯檔；失敗留著方便查是卡在哪一步（下次成功時會被上面這段清掉）
    if ($ok && is_file($outPdf) && filesize($outPdf) > 0) {
        @unlink($outLog); @unlink($errLog);
        return true;
    }
    return false;
}

}
