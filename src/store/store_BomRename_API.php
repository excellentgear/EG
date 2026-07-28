<?php
// store_BomRename_API.php — 叫料文件（BOM）自動改檔名工具 API
session_start();
if (!isset($_SESSION['userName'])) {
    http_response_code(403);
    echo json_encode(['success' => false, 'message' => '未登入']);
    exit;
}

$action = $_GET['action'] ?? $_POST['action'] ?? '';
if ($action !== 'preview_file') {
    header('Content-Type: application/json; charset=utf-8');
}

require_once __DIR__ . '/../common/DBConnection.php';
require_once __DIR__ . '/../common/rbac.php';
require_once __DIR__ . '/../../vendor/autoload.php';

$db  = new DBConnection();
$pdo = $db->getPDO();

$userId   = intval($_SESSION['id'] ?? 0);
$userName = $_SESSION['userName'] ?? '';
$features = rbac_user_features($pdo, $userId);

define('DRB_IMG_EXT', ['jpg', 'jpeg', 'png', 'bmp', 'tif', 'tiff']);
define('DRB_ALLOWED_EXT', ['jpg', 'jpeg', 'png', 'bmp', 'tif', 'tiff', 'pdf']);
define('DRB_ILLEGAL_CHARS', ['\\', '/', ':', '*', '?', '"', '<', '>', '|']);

// ── 建表（比照 Part_Attachment_API.php 的 initPartAttachTables 寫法） ──────────
function drbInitTables(PDO $pdo): void {
    static $done = false;
    if ($done) return;
    $done = true;
    $pdo->exec("CREATE TABLE IF NOT EXISTS bom_rename_process_log (
        id           BIGINT AUTO_INCREMENT PRIMARY KEY,
        batch_id     VARCHAR(40)  NOT NULL,
        src_name     VARCHAR(255) NOT NULL,
        bom          CHAR(12)     NOT NULL,
        out_name     VARCHAR(255) NOT NULL,
        out_type     VARCHAR(20)  NOT NULL,
        bom_found_in_erp TINYINT(1) NOT NULL DEFAULT 0,
        operator_id  INT, operator VARCHAR(50),
        created_at   DATETIME DEFAULT CURRENT_TIMESTAMP,
        INDEX idx_batch (batch_id), INDEX idx_bom (bom)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COMMENT='叫料文件改檔名：每份產出檔一筆'");
    $pdo->exec("CREATE TABLE IF NOT EXISTS bom_rename_archive_map (
        id          BIGINT AUTO_INCREMENT PRIMARY KEY,
        batch_id    VARCHAR(40) NOT NULL,
        old_name    VARCHAR(255) NOT NULL,
        new_name    VARCHAR(255) NOT NULL,
        created_at  DATETIME DEFAULT CURRENT_TIMESTAMP,
        INDEX idx_batch (batch_id)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COMMENT='叫料文件改檔名：原始檔歸檔對應'");
}
drbInitTables($pdo);

// ── 設定值存取 ──────────────────────────────────────────────────────────
function drbGetSetting(PDO $pdo, string $key, string $default = ''): string {
    try {
        $s = $pdo->prepare("SELECT setting_value FROM system_settings WHERE setting_key = ? LIMIT 1");
        $s->execute([$key]);
        $v = $s->fetchColumn();
        return ($v !== false && $v !== null && $v !== '') ? (string)$v : $default;
    } catch (Exception $e) { return $default; }
}
function drbSaveSetting(PDO $pdo, string $key, string $value, int $uid, string $uname): void {
    $stmt = $pdo->prepare("INSERT INTO system_settings (setting_key, setting_value, updated_by_id, updated_by)
                            VALUES (?, ?, ?, ?)
                            ON DUPLICATE KEY UPDATE setting_value = VALUES(setting_value),
                                updated_by_id = VALUES(updated_by_id), updated_by = VALUES(updated_by)");
    $stmt->execute([$key, $value, $uid, $uname]);
}
// 這兩個執行檔都在「伺服器主機」上（不是操作者自己的電腦）。預設值不寫死特定使用者帳號路徑，
// 而是現場自動偵測：先試 PATH，再用萬用字元掃「任何使用者」的常見安裝位置，找不到才留空
// （留空時 UI 會提示尚未設定，並非卡住——scan 動作找不到執行檔一樣會優雅退化成純人工輸入）。
// Windows 常見地雷：「where python」常會先列出 System32\python 這種 Microsoft Store 假替身
// （檔案存在、但一執行就跳去開 Store，等於不能用）。所以候選路徑一律用 drbCheckExe() 真的跑一次
// --version 驗證能不能用，不能只檢查 is_file()；選第一個「真的能跑」的，而非第一個「存在」的。
function drbFirstWorkingExe(array $candidates): string {
    foreach ($candidates as $c) {
        // 頁面每次載入都可能跑到這裡（設定留空＝每次都自動偵測），逾時給短一點，避免假替身拖慢整頁
        if ($c !== '' && is_file($c) && drbCheckExe($c, 4)['ok']) return $c;
    }
    return '';
}
function drbAutoDetectPythonExe(): string {
    $fromPath = [];
    $viaPath = @shell_exec('where python 2>NUL');
    if (is_string($viaPath)) {
        foreach (preg_split('/\r?\n/', trim($viaPath)) as $line) {
            $line = trim($line);
            if ($line !== '') $fromPath[] = $line;
        }
    }
    // glob 找到的實際安裝路徑優先試（較可靠），PATH 常會先列出 Store 假替身，放最後當備援
    $candidates = array_merge(
        glob('C:/Users/*/AppData/Local/Programs/Python/Python3*/python.exe') ?: [],
        glob('C:/Program Files/Python3*/python.exe') ?: [],
        glob('C:/Python3*/python.exe') ?: [],
        $fromPath
    );
    return drbFirstWorkingExe($candidates);
}
function drbAutoDetectTesseractExe(): string {
    $fromPath = [];
    $viaPath = @shell_exec('where tesseract 2>NUL');
    if (is_string($viaPath)) {
        foreach (preg_split('/\r?\n/', trim($viaPath)) as $line) {
            $line = trim($line);
            if ($line !== '') $fromPath[] = $line;
        }
    }
    $candidates = array_merge(
        $fromPath,
        glob('C:/Program Files/Tesseract-OCR/tesseract.exe') ?: [],
        glob('C:/Program Files (x86)/Tesseract-OCR/tesseract.exe') ?: []
    );
    return drbFirstWorkingExe($candidates);
}
// 執行 exe --version 驗證真的能跑起來（不是只檢查檔案存在），逾時/失敗都不丟例外，回傳可讀訊息
function drbCheckExe(string $exe, int $timeoutSec = 10): array {
    if ($exe === '') return ['ok' => false, 'message' => '尚未設定路徑'];
    if (!is_file($exe)) return ['ok' => false, 'message' => '找不到檔案：' . $exe];
    $proc = @proc_open([$exe, '--version'], [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes);
    if (!is_resource($proc)) return ['ok' => false, 'message' => '無法啟動執行檔'];
    stream_set_blocking($pipes[1], false);
    stream_set_blocking($pipes[2], false);
    $t0 = time(); $out = '';
    while (true) {
        $st = proc_get_status($proc);
        $out .= (string)stream_get_contents($pipes[1]) . (string)stream_get_contents($pipes[2]);
        if (!$st['running']) break;
        if (time() - $t0 > $timeoutSec) { proc_terminate($proc); $out .= "\n逾時終止"; break; }
        usleep(150000);
    }
    fclose($pipes[1]); fclose($pipes[2]);
    $code = proc_close($proc);
    $firstLine = trim(explode("\n", trim($out))[0] ?? '');
    if ($code !== 0 && $firstLine === '') return ['ok' => false, 'message' => '執行失敗（exit=' . $code . '）'];
    return ['ok' => true, 'message' => $firstLine !== '' ? $firstLine : '可執行'];
}
function drbGetAllSettings(PDO $pdo): array {
    $pyRaw   = drbGetSetting($pdo, 'bomrename_python_exe', '');
    $tessRaw = drbGetSetting($pdo, 'bomrename_tesseract_exe', '');
    return [
        // 來源資料夾與「圖面自動改檔名工具」共用同一把設定鍵
        'source_dir'      => drbGetSetting($pdo, 'drawingrename_source_dir', ''),
        'crop_left'       => (float)drbGetSetting($pdo, 'bomrename_crop_left', '0'),
        'crop_top'        => (float)drbGetSetting($pdo, 'bomrename_crop_top', '0'),
        'crop_width'      => (float)drbGetSetting($pdo, 'bomrename_crop_width', '35'),
        'crop_height'     => (float)drbGetSetting($pdo, 'bomrename_crop_height', '100'),
        'python_exe'      => $pyRaw !== '' ? $pyRaw : drbAutoDetectPythonExe(),
        'tesseract_exe'   => $tessRaw !== '' ? $tessRaw : drbAutoDetectTesseractExe(),
        'python_is_auto'    => $pyRaw === '',
        'tesseract_is_auto' => $tessRaw === '',
    ];
}
function drbTmpDir(): string {
    $dir = __DIR__ . '/../../uploads/bom_rename_tmp';
    if (!is_dir($dir)) @mkdir($dir, 0777, true);
    return $dir;
}
function drbPreviewDir(int $uid): string {
    $dir = drbTmpDir() . '/preview_u' . $uid;
    if (!is_dir($dir)) @mkdir($dir, 0777, true);
    return $dir;
}
function drbClearDir(string $dir): void {
    if (!is_dir($dir)) return;
    foreach (scandir($dir) as $f) {
        if ($f === '.' || $f === '..') continue;
        $p = $dir . DIRECTORY_SEPARATOR . $f;
        if (is_file($p)) @unlink($p);
    }
}

// ── 掃描檔名規則 ────────────────────────────────────────────────────────
function drbListSourceFiles(string $sourceDir): array {
    $entries = @scandir($sourceDir);
    if ($entries === false) return [];
    $out = [];
    foreach ($entries as $e) {
        if ($e === '.' || $e === '..') continue;
        $full = $sourceDir . DIRECTORY_SEPARATOR . $e;
        if (!is_file($full)) continue;
        $ext = strtolower(pathinfo($e, PATHINFO_EXTENSION));
        if (!in_array($ext, DRB_ALLOWED_EXT, true)) continue;
        $body = pathinfo($e, PATHINFO_FILENAME);
        if (strpos($body, '-$M') !== false) continue; // 已產出的成品，排除
        $out[] = $e;
    }
    usort($out, 'strnatcasecmp');
    return $out;
}

// ── 呼叫 Python worker（比照 attachment_lib.php::eg_att_soffice_convert 的 array-arg proc_open + 輪詢逾時寫法） ──
function drbRunWorker(string $pythonExe, array $absFiles, array $crop, string $tesseractCmd, string $previewDir, int $timeoutSec = 180): array {
    if ($pythonExe === '' || !is_file($pythonExe)) return ['ok' => false, 'message' => 'Python 執行檔不存在：' . $pythonExe];
    $workerScript = __DIR__ . '/../python/bom_rename_worker.py';
    if (!is_file($workerScript)) return ['ok' => false, 'message' => 'worker.py 不存在'];

    $ioDir   = drbTmpDir();
    $inFile  = $ioDir . '/in_' . bin2hex(random_bytes(6)) . '.json';
    $outFile = $ioDir . '/out_' . bin2hex(random_bytes(6)) . '.json';
    file_put_contents($inFile, json_encode(['files' => $absFiles, 'crop' => $crop, 'tesseract_cmd' => $tesseractCmd], JSON_UNESCAPED_UNICODE));

    $cmd = [$pythonExe, $workerScript, '--input', $inFile, '--previewdir', $previewDir, '--output', $outFile];
    $proc = @proc_open($cmd, [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes);
    if (!is_resource($proc)) { @unlink($inFile); return ['ok' => false, 'message' => '無法啟動 Python 行程']; }
    stream_set_blocking($pipes[1], false);
    stream_set_blocking($pipes[2], false);
    $t0 = time(); $errOut = '';
    while (true) {
        $st = proc_get_status($proc);
        $errOut .= (string)stream_get_contents($pipes[2]);
        stream_get_contents($pipes[1]);
        if (!$st['running']) break;
        if (time() - $t0 > $timeoutSec) { proc_terminate($proc); $errOut .= "\n逾時終止"; break; }
        usleep(200000);
    }
    fclose($pipes[1]); fclose($pipes[2]);
    $exitCode = proc_close($proc);
    @unlink($inFile);

    if ($exitCode !== 0 || !is_file($outFile)) {
        @unlink($outFile);
        error_log('[bom_rename] worker failed exit=' . $exitCode . ' err=' . substr($errOut, 0, 500));
        return ['ok' => false, 'message' => 'Python 分析失敗：' . substr($errOut, 0, 300)];
    }
    $json = json_decode((string)file_get_contents($outFile), true);
    @unlink($outFile);
    if (!is_array($json) || !isset($json['files'])) return ['ok' => false, 'message' => 'worker 輸出格式錯誤'];
    return ['ok' => true, 'files' => $json['files']];
}

// ── 圖片合併 PDF（TCPDF；jpg/png 直接嵌入，bmp 先轉PNG，tif/tiff 不支援） ──────
function drbMergeImagesToPdf(array $absPaths, string $dest): array {
    // $usable 存 [轉檔後可嵌入的實際路徑, 原始檔名(basename)]，避免略過時誤報成暫存轉檔檔名
    $usable = []; $skipped = [];
    foreach ($absPaths as $p) {
        $origName = basename($p);
        $ext = strtolower(pathinfo($p, PATHINFO_EXTENSION));
        if (in_array($ext, ['jpg', 'jpeg', 'png'], true)) { $usable[] = [$p, $origName]; continue; }
        if ($ext === 'bmp' && function_exists('imagecreatefrombmp')) {
            $tmp = drbTmpDir() . '/conv_' . bin2hex(random_bytes(4)) . '.png';
            $im = @imagecreatefrombmp($p);
            if ($im && imagepng($im, $tmp)) { imagedestroy($im); $usable[] = [$tmp, $origName]; continue; }
            if ($im) imagedestroy($im);
        }
        $skipped[] = $origName;
    }
    if (empty($usable)) return ['ok' => false, 'skipped' => $skipped];
    try {
        $pdf = new \TCPDF();
        $pdf->setPrintHeader(false);
        $pdf->setPrintFooter(false);
        $pdf->SetMargins(0, 0, 0);
        $pdf->SetAutoPageBreak(false, 0);
        foreach ($usable as [$img, $origName]) {
            $size = @getimagesize($img);
            if (!$size) { $skipped[] = $origName; continue; }
            [$w, $h] = $size;
            $orient = $w > $h ? 'L' : 'P';
            $pdf->AddPage($orient, 'A4');
            $pageW = $pdf->getPageWidth(); $pageH = $pdf->getPageHeight();
            $imgRatio = $w / $h; $pageRatio = $pageW / $pageH;
            if ($imgRatio > $pageRatio) { $drawW = $pageW; $drawH = $pageW / $imgRatio; }
            else { $drawH = $pageH; $drawW = $pageH * $imgRatio; }
            $x = ($pageW - $drawW) / 2; $y = ($pageH - $drawH) / 2;
            $pdf->Image($img, $x, $y, $drawW, $drawH);
        }
        @file_put_contents($dest, $pdf->Output('', 'S'));
        return ['ok' => is_file($dest) && filesize($dest) > 0, 'skipped' => $skipped];
    } catch (Throwable $e) {
        error_log('[bom_rename] merge pdf failed: ' . $e->getMessage());
        return ['ok' => false, 'skipped' => $skipped];
    }
}

function drbUniqueArchiveName(string $archiveDir, string $batchTs, int $counter, string $ext): string {
    $name = $batchTs . '_' . str_pad((string)$counter, 2, '0', STR_PAD_LEFT) . '.' . $ext;
    $n = $counter;
    while (is_file($archiveDir . DIRECTORY_SEPARATOR . $name)) {
        $n++;
        $name = $batchTs . '_' . str_pad((string)$n, 2, '0', STR_PAD_LEFT) . '.' . $ext;
    }
    return $name;
}

switch ($action) {

    // ── 設定值 ──────────────────────────────────────────────────────
    case 'get_settings':
        if (!rbac_has($features, 'bom_rename_view')) { http_response_code(403); echo json_encode(['success' => false, 'message' => '無權限']); break; }
        echo json_encode(['success' => true, 'settings' => drbGetAllSettings($pdo)]);
        break;

    // 測試連線：檢查「伺服器主機」上目前設定/自動偵測到的 Python、Tesseract 是否真的能執行
    case 'check_env':
        if (!rbac_has($features, 'bom_rename_manage_settings')) { http_response_code(403); echo json_encode(['success' => false, 'message' => '無權限']); break; }
        $pyExe = trim($_POST['python_exe'] ?? '');
        $tessExe = trim($_POST['tesseract_exe'] ?? '');
        if ($pyExe === '') $pyExe = drbAutoDetectPythonExe();
        if ($tessExe === '') $tessExe = drbAutoDetectTesseractExe();
        echo json_encode([
            'success' => true,
            'python' => array_merge(['path' => $pyExe], drbCheckExe($pyExe)),
            'tesseract' => array_merge(['path' => $tessExe], drbCheckExe($tessExe)),
        ]);
        break;

    case 'save_settings':
        if (!rbac_has($features, 'bom_rename_manage_settings')) { http_response_code(403); echo json_encode(['success' => false, 'message' => '無權限']); break; }
        $sourceDir = trim($_POST['source_dir'] ?? '');
        $cl = (float)($_POST['crop_left'] ?? 0);
        $ct = (float)($_POST['crop_top'] ?? 0);
        $cw = (float)($_POST['crop_width'] ?? 35);
        $ch = (float)($_POST['crop_height'] ?? 100);
        $pyExe = trim($_POST['python_exe'] ?? '');
        $tessExe = trim($_POST['tesseract_exe'] ?? '');
        // 來源資料夾與圖面工具共用同一把鍵，這裡存的也是那一把
        drbSaveSetting($pdo, 'drawingrename_source_dir', $sourceDir, $userId, $userName);
        drbSaveSetting($pdo, 'bomrename_crop_left', (string)$cl, $userId, $userName);
        drbSaveSetting($pdo, 'bomrename_crop_top', (string)$ct, $userId, $userName);
        drbSaveSetting($pdo, 'bomrename_crop_width', (string)$cw, $userId, $userName);
        drbSaveSetting($pdo, 'bomrename_crop_height', (string)$ch, $userId, $userName);
        drbSaveSetting($pdo, 'bomrename_python_exe', $pyExe, $userId, $userName);
        drbSaveSetting($pdo, 'bomrename_tesseract_exe', $tessExe, $userId, $userName);
        echo json_encode(['success' => true, 'settings' => drbGetAllSettings($pdo)]);
        break;

    // ── 掃描 ────────────────────────────────────────────────────────
    case 'scan':
        if (!rbac_has($features, 'bom_rename_view')) { http_response_code(403); echo json_encode(['success' => false, 'message' => '無權限']); break; }
        $settings = drbGetAllSettings($pdo);
        $sourceDir = rtrim($settings['source_dir'], '\\/');
        if ($sourceDir === '') { echo json_encode(['success' => false, 'message' => '尚未設定來源資料夾']); break; }

        $names = drbListSourceFiles($sourceDir);
        if (empty($names)) { echo json_encode(['success' => true, 'ocr_available' => true, 'files' => []]); break; }

        $previewDir = drbPreviewDir($userId);
        drbClearDir($previewDir);
        $absFiles = array_map(fn($n) => $sourceDir . DIRECTORY_SEPARATOR . $n, $names);

        $crop = ['left' => $settings['crop_left'], 'top' => $settings['crop_top'], 'width' => $settings['crop_width'], 'height' => $settings['crop_height']];
        $result = drbRunWorker($settings['python_exe'], $absFiles, $crop, $settings['tesseract_exe'], $previewDir);

        if (!$result['ok']) {
            // Python/OCR 不可用 → 退化為純人工輸入，不擋流程
            $files = [];
            foreach ($names as $n) {
                $ext = strtolower(pathinfo($n, PATHINFO_EXTENSION));
                $files[] = ['file' => $n, 'kind' => ($ext === 'pdf' ? 'pdf' : 'image'), 'preview' => null,
                    'text_chars' => 0, 'ocr_used' => false, 'bom_drafts' => [], 'error' => null];
            }
            echo json_encode(['success' => true, 'ocr_available' => false, 'ocr_message' => $result['message'], 'files' => $files]);
            break;
        }

        $files = [];
        foreach ($result['files'] as $f) {
            $files[] = [
                'file' => $f['file'] ?? '', 'kind' => $f['kind'] ?? 'image',
                'preview' => $f['preview'] ?? null, // 只回檔名，前端自行組出完整 API 路徑
                'text_chars' => $f['text_chars'] ?? 0, 'ocr_used' => !empty($f['ocr_used']),
                'bom_drafts' => $f['bom_drafts'] ?? [], 'error' => $f['error'] ?? null,
            ];
        }
        echo json_encode(['success' => true, 'ocr_available' => true, 'files' => $files]);
        break;

    // ── 預覽圖 ──────────────────────────────────────────────────────
    case 'preview_file':
        if (!rbac_has($features, 'bom_rename_view')) { http_response_code(403); echo '無權限'; break; }
        $name = basename($_GET['file'] ?? '');
        $path = drbPreviewDir($userId) . DIRECTORY_SEPARATOR . $name;
        if ($name === '' || !is_file($path)) { http_response_code(404); echo '找不到預覽圖'; break; }
        header('Content-Type: image/png');
        header('Content-Length: ' . filesize($path));
        readfile($path);
        break;

    // ── 製令單號自動完成／驗證 ──────────────────────────────────────
    case 'search_bom':
        if (!rbac_has($features, 'bom_rename_view')) { http_response_code(403); echo json_encode(['success' => false, 'message' => '無權限']); break; }
        $q = trim($_GET['q'] ?? '');
        if ($q === '') { echo json_encode(['success' => true, 'data' => []]); break; }
        try {
            $stmt = $pdo->prepare("SELECT bom, d_id, Client_Name FROM bom WHERE bom LIKE ? ORDER BY bom LIMIT 20");
            $stmt->execute([$q . '%']);
            echo json_encode(['success' => true, 'data' => $stmt->fetchAll(PDO::FETCH_ASSOC)]);
        } catch (Exception $e) {
            echo json_encode(['success' => true, 'data' => []]);
        }
        break;

    // ── 確認並產檔（整批） ──────────────────────────────────────────
    case 'commit':
        if (!rbac_has($features, 'bom_rename_operate')) { http_response_code(403); echo json_encode(['success' => false, 'message' => '無權限']); break; }
        $settings = drbGetAllSettings($pdo);
        $sourceDir = rtrim($settings['source_dir'], '\\/');
        if ($sourceDir === '') { echo json_encode(['success' => false, 'message' => '尚未設定來源資料夾']); break; }

        $filesInput = json_decode($_POST['files'] ?? '[]', true);
        if (!is_array($filesInput)) { echo json_encode(['success' => false, 'message' => 'files 格式錯誤']); break; }

        $okDir = $sourceDir . DIRECTORY_SEPARATOR . 'OK-修改後檔案';
        $archiveDir = $sourceDir . DIRECTORY_SEPARATOR . '已整理';
        if (!is_dir($okDir)) @mkdir($okDir, 0777, true);
        if (!is_dir($archiveDir)) @mkdir($archiveDir, 0777, true);

        $batchId = date('YmdHis') . '_' . substr(uniqid(), -4);
        $batchTs = date('Ymd_His');

        // 整理輸入：每檔的合法 BOM 清單（去重、格式驗證，不合法/空白忽略）
        $fileEntries = []; // name => ['ext'=>, 'kind'=>, 'boms'=>[]]
        $invalidBomFormat = [];
        foreach ($filesInput as $it) {
            $name = basename((string)($it['name'] ?? ''));
            if ($name === '' || !is_file($sourceDir . DIRECTORY_SEPARATOR . $name)) continue;
            $ext = strtolower(pathinfo($name, PATHINFO_EXTENSION));
            if (!in_array($ext, DRB_ALLOWED_EXT, true)) continue;
            $boms = [];
            foreach ((array)($it['boms'] ?? []) as $b) {
                $b = trim((string)$b);
                if ($b === '') continue;
                if (!preg_match('/^B-\d{10}$/', $b)) { $invalidBomFormat[] = $name . ':' . $b; continue; }
                if (!in_array($b, $boms, true)) $boms[] = $b;
            }
            $fileEntries[$name] = ['ext' => $ext, 'kind' => ($ext === 'pdf' ? 'pdf' : 'image'), 'boms' => $boms];
        }

        // 完全相符驗證（不模糊更正，只標記提醒）
        $allBoms = [];
        foreach ($fileEntries as $fe) foreach ($fe['boms'] as $b) $allBoms[$b] = true;
        $foundInErp = [];
        if (!empty($allBoms)) {
            $placeholders = implode(',', array_fill(0, count($allBoms), '?'));
            $stmt = $pdo->prepare("SELECT bom FROM bom WHERE bom IN ($placeholders)");
            $stmt->execute(array_keys($allBoms));
            foreach ($stmt->fetchAll(PDO::FETCH_COLUMN) as $b) $foundInErp[$b] = true;
        }
        $notFoundInErp = array_values(array_diff(array_keys($allBoms), array_keys($foundInErp)));

        // 每檔處理結果追蹤（決定是否歸檔）
        $outcome = []; // name => ['attempted'=>0,'success'=>0]
        $mergedCount = 0; $imageCopyCount = 0; $pdfCopyCount = 0;
        $skippedExists = []; $unsupportedFormat = [];

        function drbLog(PDO $pdo, string $batchId, string $srcName, string $bom, string $outName, string $outType, bool $foundErp, int $uid, string $uname): void {
            $pdo->prepare("INSERT INTO bom_rename_process_log (batch_id, src_name, bom, out_name, out_type, bom_found_in_erp, operator_id, operator)
                           VALUES (?,?,?,?,?,?,?,?)")
                ->execute([$batchId, $srcName, $bom, $outName, $outType, $foundErp ? 1 : 0, $uid, $uname]);
        }

        // 1) 圖片：依 BOM 分組
        $imageGroups = []; // bom => [names...]
        foreach ($fileEntries as $name => $fe) {
            if ($fe['kind'] !== 'image') continue;
            foreach ($fe['boms'] as $b) $imageGroups[$b][] = $name;
        }
        foreach ($imageGroups as $bom => $names) {
            $ext0 = strtolower(pathinfo($names[0], PATHINFO_EXTENSION));
            foreach ($names as $n) { $outcome[$n]['attempted'] = ($outcome[$n]['attempted'] ?? 0) + 1; }
            if (count($names) === 1) {
                $n = $names[0];
                $outName = $bom . '-$M.' . $ext0;
                $dest = $okDir . DIRECTORY_SEPARATOR . $outName;
                if (is_file($dest)) { $skippedExists[] = $outName; $outcome[$n]['success'] = ($outcome[$n]['success'] ?? 0) + 1; continue; }
                if (copy($sourceDir . DIRECTORY_SEPARATOR . $n, $dest)) {
                    $imageCopyCount++;
                    $outcome[$n]['success'] = ($outcome[$n]['success'] ?? 0) + 1;
                    drbLog($pdo, $batchId, $n, $bom, $outName, 'image_copy', isset($foundInErp[$bom]), $userId, $userName);
                }
            } else {
                usort($names, 'strnatcasecmp');
                $outName = $bom . '-$M.pdf';
                $dest = $okDir . DIRECTORY_SEPARATOR . $outName;
                if (is_file($dest)) {
                    $skippedExists[] = $outName;
                    foreach ($names as $n) $outcome[$n]['success'] = ($outcome[$n]['success'] ?? 0) + 1;
                    continue;
                }
                $absPaths = array_map(fn($n) => $sourceDir . DIRECTORY_SEPARATOR . $n, $names);
                $mergeResult = drbMergeImagesToPdf($absPaths, $dest);
                if (!empty($mergeResult['skipped'])) {
                    foreach ($mergeResult['skipped'] as $sf) $unsupportedFormat[] = $sf . '（BOM ' . $bom . '，格式不支援自動合併）';
                }
                if ($mergeResult['ok']) {
                    $mergedCount++;
                    $skippedNames = $mergeResult['skipped'] ?? [];
                    foreach ($names as $n) {
                        if (!in_array($n, $skippedNames, true)) $outcome[$n]['success'] = ($outcome[$n]['success'] ?? 0) + 1;
                    }
                    drbLog($pdo, $batchId, implode(';', $names), $bom, $outName, 'image_merge', isset($foundInErp[$bom]), $userId, $userName);
                }
            }
        }

        // 2) PDF：每個 BOM 各複製一份整份 PDF
        foreach ($fileEntries as $name => $fe) {
            if ($fe['kind'] !== 'pdf') continue;
            foreach ($fe['boms'] as $bom) {
                $outcome[$name]['attempted'] = ($outcome[$name]['attempted'] ?? 0) + 1;
                $outName = $bom . '-$M.pdf';
                $dest = $okDir . DIRECTORY_SEPARATOR . $outName;
                if (is_file($dest)) { $skippedExists[] = $outName; $outcome[$name]['success'] = ($outcome[$name]['success'] ?? 0) + 1; continue; }
                if (copy($sourceDir . DIRECTORY_SEPARATOR . $name, $dest)) {
                    $pdfCopyCount++;
                    $outcome[$name]['success'] = ($outcome[$name]['success'] ?? 0) + 1;
                    drbLog($pdo, $batchId, $name, $bom, $outName, 'pdf_copy', isset($foundInErp[$bom]), $userId, $userName);
                }
            }
        }

        // 3) 原始檔歸檔：有確認 BOM 且至少一次成功（含「已存在」視為成功）才搬移；完全沒 BOM 或全部失敗則留在原地
        $archivedCount = 0; $counter = 0;
        foreach ($fileEntries as $name => $fe) {
            if (empty($fe['boms'])) continue; // 沒勾 BOM，留在原地
            $o = $outcome[$name] ?? ['attempted' => 0, 'success' => 0];
            if ($o['attempted'] > 0 && $o['success'] === 0) continue; // 全部失敗，留在原地供人工處理
            $counter++;
            $ext = $fe['ext'];
            $newName = drbUniqueArchiveName($archiveDir, $batchTs, $counter, $ext);
            if (@rename($sourceDir . DIRECTORY_SEPARATOR . $name, $archiveDir . DIRECTORY_SEPARATOR . $newName)) {
                $archivedCount++;
                $pdo->prepare("INSERT INTO bom_rename_archive_map (batch_id, old_name, new_name) VALUES (?,?,?)")
                    ->execute([$batchId, $name, $newName]);
            }
        }

        echo json_encode([
            'success' => true,
            'batchId' => $batchId,
            'mergedCount' => $mergedCount,
            'imageCopyCount' => $imageCopyCount,
            'pdfCopyCount' => $pdfCopyCount,
            'archivedCount' => $archivedCount,
            'skippedExists' => array_values(array_unique($skippedExists)),
            'unsupportedFormat' => array_values(array_unique($unsupportedFormat)),
            'notFoundInErp' => $notFoundInErp,
            'invalidBomFormat' => $invalidBomFormat,
        ]);
        break;

    default:
        http_response_code(400);
        echo json_encode(['success' => false, 'message' => '未知的操作']);
}
