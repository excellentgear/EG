<?php
// store_DrawingRename_API.php — 圖面自動改檔名工具 API
session_start();
if (!isset($_SESSION['userName'])) {
    http_response_code(403);
    echo json_encode(['success' => false, 'message' => '未登入']);
    exit;
}

$action = $_GET['action'] ?? $_POST['action'] ?? '';
if ($action !== 'stream_file') {
    header('Content-Type: application/json; charset=utf-8');
}

require_once __DIR__ . '/../common/DBConnection.php';
require_once __DIR__ . '/../common/rbac.php';
require_once __DIR__ . '/../common/attachment_lib.php';

$db  = new DBConnection();
$pdo = $db->getPDO();

$userId   = intval($_SESSION['id'] ?? 0);
$userName = $_SESSION['userName'] ?? '';
$features = rbac_user_features($pdo, $userId);

define('DR_ALLOWED_EXT', ['jpg', 'jpeg', 'png', 'bmp', 'tif', 'tiff', 'pdf']);
define('DR_ILLEGAL_CHARS', ['\\', '/', ':', '*', '?', '"', '<', '>', '|']);

// ── 設定值存取（比照專案既有 system_settings upsert 慣例） ──────────────────
function drGetSetting(PDO $pdo, string $key, string $default = ''): string {
    try {
        $s = $pdo->prepare("SELECT setting_value FROM system_settings WHERE setting_key = ? LIMIT 1");
        $s->execute([$key]);
        $v = $s->fetchColumn();
        return ($v !== false && $v !== null && $v !== '') ? (string)$v : $default;
    } catch (Exception $e) { return $default; }
}
function drSaveSetting(PDO $pdo, string $key, string $value, int $uid, string $uname): void {
    $stmt = $pdo->prepare("INSERT INTO system_settings (setting_key, setting_value, updated_by_id, updated_by)
                            VALUES (?, ?, ?, ?)
                            ON DUPLICATE KEY UPDATE setting_value = VALUES(setting_value),
                                updated_by_id = VALUES(updated_by_id), updated_by = VALUES(updated_by)");
    $stmt->execute([$key, $value, $uid, $uname]);
}
// 作廢版/覆蓋版是「個人操作偏好」而非共用設定：每位使用者各自記住自己上次選的模式，
// 互不影響（例如甲慣用覆蓋版處理某批圖，不會連帶把乙畫面上的預設模式也切走）。
function drModeSettingKey(int $uid): string { return 'drawingrename_mode_u' . $uid; }
function drGetUserMode(PDO $pdo, int $uid): string {
    $m = drGetSetting($pdo, drModeSettingKey($uid), 'void');
    return $m === 'overwrite' ? 'overwrite' : 'void';
}
function drGetAllSettings(PDO $pdo, int $uid): array {
    return [
        'source_dir' => drGetSetting($pdo, 'drawingrename_source_dir', ''),
        'output_dir' => drGetSetting($pdo, 'drawingrename_output_dir', ''),
        'prefix'     => drGetSetting($pdo, 'drawingrename_prefix', ''),
        'suffix'     => drGetSetting($pdo, 'drawingrename_suffix', ''),
        'mode'       => drGetUserMode($pdo, $uid),
    ];
}
function drSanitizeAffix(string $s): string {
    $s = str_replace(DR_ILLEGAL_CHARS, '', $s);
    if (mb_strlen($s, 'UTF-8') > 30) $s = mb_substr($s, 0, 30, 'UTF-8');
    return $s;
}
function drTmpDir(): string {
    $dir = __DIR__ . '/../../uploads/drawing_rename_tmp';
    if (!is_dir($dir)) @mkdir($dir, 0777, true);
    return $dir;
}

// ── 檔名規則 ────────────────────────────────────────────────────────────
function drBuildBody(string $prefix, string $bomNumber, bool $isBoss, string $suffix): string {
    $core = 'B-' . $bomNumber;
    return $isBoss ? ($prefix . $core . $suffix) : ($prefix . $core . ' ++' . $suffix);
}
function drIsSameType(string $existingBody, string $prefix, string $bomNumber, string $suffix, bool $isBoss): bool {
    $core = 'B-' . $bomNumber;
    if ($isBoss) {
        return strcasecmp($existingBody, $prefix . $core . $suffix) === 0;
    }
    $withSpace = $prefix . $core . ' ++' . $suffix;
    $noSpace   = $prefix . $core . '++' . $suffix;
    return strcasecmp($existingBody, $withSpace) === 0 || strcasecmp($existingBody, $noSpace) === 0;
}
function drValidateBomNumber(string $number): ?string {
    if (!preg_match('/^\d{10}$/', $number)) return null; // 交由呼叫端擋下（非 warning，是硬性錯誤）
    $month = (int)substr($number, 3, 2);
    $day   = (int)substr($number, 5, 2);
    if ($month < 1 || $month > 12 || $day < 1 || $day > 31) {
        return "月份或日期看起來不合理（月:{$month} 日:{$day}），請再次確認號碼是否看對";
    }
    return '';
}
// 判斷檔名是否已經是「本工具產生的標準命名」（含已作廢者），用於清單掃描時排除——
// 適用來源資料夾與輸出資料夾設同一個路徑（或留空 fallback 同一個）的情境：避免已改完名、
// 留在原地的檔案每次「重新整理清單」又被當成新掃描檔重複跑一次。
function drIsCanonicalName(string $body, string $prefix, string $suffix): bool {
    if (mb_strpos($body, '作廢') !== false) return true;
    $pattern = '/^' . preg_quote($prefix, '/') . 'B-\d{10}(\s?\+\+)?' . preg_quote($suffix, '/') . '/i';
    return preg_match($pattern, $body) === 1;
}
function drUniqueVoidName(string $outputDir, string $body, string $ext): string {
    $today = date('Ymd');
    $base  = $body . '作廢' . $today;
    $name  = $base . '.' . $ext;
    $n = 2;
    while (is_file($outputDir . DIRECTORY_SEPARATOR . $name)) {
        $name = $base . '_' . str_pad((string)$n, 2, '0', STR_PAD_LEFT) . '.' . $ext;
        $n++;
    }
    return $name;
}

// ── 旋轉（best-effort；失敗則沿用原始檔案角度） ──────────────────────────
function drRotateImage(string $src, string $dst, int $angleCW): bool {
    $ext = strtolower(pathinfo($src, PATHINFO_EXTENSION));
    $loader = ['jpg' => 'imagecreatefromjpeg', 'jpeg' => 'imagecreatefromjpeg', 'png' => 'imagecreatefrompng', 'bmp' => 'imagecreatefrombmp'][$ext] ?? null;
    if (!$loader || !function_exists($loader)) return false;
    $im = @$loader($src);
    if (!$im) return false;
    if ($ext === 'png') { imagealphablending($im, true); imagesavealpha($im, true); }
    $transparent = ($ext === 'png') ? imagecolorallocatealpha($im, 0, 0, 0, 127) : imagecolorallocate($im, 255, 255, 255);
    $rotated = imagerotate($im, -$angleCW, $transparent); // GD 正角度為逆時針，取負值得順時針
    imagedestroy($im);
    if (!$rotated) return false;
    if ($ext === 'png') { imagealphablending($rotated, false); imagesavealpha($rotated, true); }
    $ok = ($ext === 'png') ? imagepng($rotated, $dst)
        : (($ext === 'bmp') ? imagebmp($rotated, $dst) : imagejpeg($rotated, $dst, 92));
    imagedestroy($rotated);
    return $ok && is_file($dst);
}
function drRotatePdf(string $src, string $dst, int $angleCW): bool {
    try {
        $pdf = new \setasign\Fpdi\Tcpdf\Fpdi();
        $pdf->setPrintHeader(false);
        $pdf->setPrintFooter(false);
        $pdf->SetAutoPageBreak(false, 0);
        $pageCount = $pdf->setSourceFile($src);
        for ($i = 1; $i <= $pageCount; $i++) {
            $tpl  = $pdf->importPage($i);
            $size = $pdf->getTemplateSize($tpl);
            $w = $size['width']; $h = $size['height'];
            $swapped = ($angleCW == 90 || $angleCW == 270);
            $pageW = $swapped ? $h : $w;
            $pageH = $swapped ? $w : $h;
            $pdf->AddPage($pageW > $pageH ? 'L' : 'P', [$pageW, $pageH]);
            $pdf->StartTransform();
            $pdf->Rotate(-$angleCW, $pageW / 2, $pageH / 2); // TCPDF 正角度為逆時針，取負值得順時針
            $pdf->useTemplate($tpl, ($pageW - $w) / 2, ($pageH - $h) / 2, $w, $h);
            $pdf->StopTransform();
        }
        @file_put_contents($dst, $pdf->Output('', 'S'));
        return is_file($dst) && filesize($dst) > 0;
    } catch (Throwable $e) {
        error_log('[drawing_rename] rotate pdf failed: ' . $e->getMessage());
        return false;
    }
}

switch ($action) {

    // ── 設定值 ──────────────────────────────────────────────────────
    case 'get_settings':
        if (!rbac_has($features, 'drawing_rename_view')) { http_response_code(403); echo json_encode(['success' => false, 'message' => '無權限']); break; }
        echo json_encode(['success' => true, 'settings' => drGetAllSettings($pdo, $userId)]);
        break;

    case 'save_settings':
        if (!rbac_has($features, 'drawing_rename_manage_settings')) { http_response_code(403); echo json_encode(['success' => false, 'message' => '無權限']); break; }
        $sourceDir = trim($_POST['source_dir'] ?? '');
        $outputDir = trim($_POST['output_dir'] ?? '');
        $prefix    = drSanitizeAffix(trim($_POST['prefix'] ?? ''));
        $suffix    = drSanitizeAffix(trim($_POST['suffix'] ?? ''));
        drSaveSetting($pdo, 'drawingrename_source_dir', $sourceDir, $userId, $userName);
        drSaveSetting($pdo, 'drawingrename_output_dir', $outputDir, $userId, $userName);
        drSaveSetting($pdo, 'drawingrename_prefix', $prefix, $userId, $userName);
        drSaveSetting($pdo, 'drawingrename_suffix', $suffix, $userId, $userName);
        echo json_encode(['success' => true, 'settings' => drGetAllSettings($pdo, $userId)]);
        break;

    // 作廢版/覆蓋版：個人偏好，任何有操作權限的人都能切換自己的（不需管理設定權限）
    case 'save_mode':
        if (!rbac_has($features, 'drawing_rename_operate')) { http_response_code(403); echo json_encode(['success' => false, 'message' => '無權限']); break; }
        $mode = ($_POST['mode'] ?? 'void') === 'overwrite' ? 'overwrite' : 'void';
        drSaveSetting($pdo, drModeSettingKey($userId), $mode, $userId, $userName);
        echo json_encode(['success' => true, 'mode' => $mode]);
        break;

    // ── 清單 ────────────────────────────────────────────────────────
    case 'list_files':
        if (!rbac_has($features, 'drawing_rename_view')) { http_response_code(403); echo json_encode(['success' => false, 'message' => '無權限']); break; }
        $settings = drGetAllSettings($pdo, $userId);
        $sourceDir = rtrim($settings['source_dir'], '\\/');
        if ($sourceDir === '') { echo json_encode(['success' => false, 'message' => '尚未設定來源資料夾']); break; }
        try {
            $entries = @scandir($sourceDir);
            if ($entries === false) { echo json_encode(['success' => false, 'message' => '無法讀取來源資料夾，請確認路徑是否正確、伺服器是否連得到（NAS 需確認分享權限）']); break; }
            $files = [];
            foreach ($entries as $e) {
                if ($e === '.' || $e === '..') continue;
                $full = $sourceDir . DIRECTORY_SEPARATOR . $e;
                if (!is_file($full)) continue;
                $ext = strtolower(pathinfo($e, PATHINFO_EXTENSION));
                if (!in_array($ext, DR_ALLOWED_EXT, true)) continue;
                $body = pathinfo($e, PATHINFO_FILENAME);
                if (drIsCanonicalName($body, $settings['prefix'], $settings['suffix'])) continue; // 已是本工具改過名的檔案，不重複列入待處理
                $files[] = ['name' => $e, 'ext' => $ext, 'size' => filesize($full), 'mtime' => filemtime($full)];
            }
            usort($files, fn($a, $b) => strnatcasecmp($a['name'], $b['name']));
            echo json_encode(['success' => true, 'files' => $files]);
        } catch (Throwable $ex) {
            echo json_encode(['success' => false, 'message' => '讀取來源資料夾發生錯誤：' . $ex->getMessage()]);
        }
        break;

    // ── 串流檔案內容（給 <img>/<embed> 用） ────────────────────────────
    case 'stream_file':
        if (!rbac_has($features, 'drawing_rename_view')) { http_response_code(403); echo '無權限'; break; }
        $settings = drGetAllSettings($pdo, $userId);
        $sourceDir = rtrim($settings['source_dir'], '\\/');
        $name = basename($_GET['file'] ?? '');
        $path = $sourceDir . DIRECTORY_SEPARATOR . $name;
        $ext  = strtolower(pathinfo($name, PATHINFO_EXTENSION));
        if ($name === '' || !in_array($ext, DR_ALLOWED_EXT, true) || !is_file($path)) { http_response_code(404); echo '找不到檔案'; break; }
        $mime = ['jpg' => 'image/jpeg', 'jpeg' => 'image/jpeg', 'png' => 'image/png', 'bmp' => 'image/bmp', 'tif' => 'image/tiff', 'tiff' => 'image/tiff', 'pdf' => 'application/pdf'][$ext];
        header('Content-Type: ' . $mime);
        header('Content-Disposition: inline; filename="' . rawurlencode($name) . '"');
        header('Content-Length: ' . filesize($path));
        readfile($path);
        break;

    // ── 確認並改名 ──────────────────────────────────────────────────
    case 'confirm':
        if (!rbac_has($features, 'drawing_rename_operate')) { http_response_code(403); echo json_encode(['success' => false, 'message' => '無權限']); break; }

        $file      = basename($_POST['file'] ?? '');
        $bomNumber = trim($_POST['bomNumber'] ?? '');
        $isBoss    = ($_POST['isBoss'] ?? '0') === '1';
        $rotate    = (int)($_POST['rotate'] ?? 0);
        $mode      = ($_POST['mode'] ?? 'void') === 'overwrite' ? 'overwrite' : 'void';

        if (!preg_match('/^\d{10}$/', $bomNumber)) {
            echo json_encode(['success' => false, 'message' => 'BOM 號碼必須剛好 10 碼數字']);
            break;
        }
        if (!in_array($rotate, [0, 90, 180, 270], true)) $rotate = 0;

        $settings  = drGetAllSettings($pdo, $userId);
        $sourceDir = rtrim($settings['source_dir'], '\\/');
        $outputDir = rtrim($settings['output_dir'], '\\/');
        if ($outputDir === '') $outputDir = $sourceDir; // 輸出資料夾留空＝等於來源資料夾（呼應設定區塊 placeholder 的承諾）
        $prefix    = $settings['prefix'];
        $suffix    = $settings['suffix'];

        if ($sourceDir === '') { echo json_encode(['success' => false, 'message' => '尚未設定來源資料夾']); break; }

        $srcPath = $sourceDir . DIRECTORY_SEPARATOR . $file;
        $ext     = strtolower(pathinfo($file, PATHINFO_EXTENSION));
        if ($file === '' || !in_array($ext, DR_ALLOWED_EXT, true) || !is_file($srcPath)) {
            echo json_encode(['success' => false, 'message' => '來源檔案不存在，可能已被處理過，請重新整理清單']);
            break;
        }

        $warning = drValidateBomNumber($bomNumber);
        $body    = drBuildBody($prefix, $bomNumber, $isBoss, $suffix);
        $newName = $body . '.' . $ext;

        // 旋轉（best-effort，失敗則沿用原始角度繼續）
        $workSrc  = $srcPath;
        $rotTmp   = null;
        if ($rotate !== 0) {
            $rotTmp = drTmpDir() . '/rot_' . uniqid() . '.' . $ext;
            $rotOk  = ($ext === 'pdf') ? drRotatePdf($srcPath, $rotTmp, $rotate) : drRotateImage($srcPath, $rotTmp, $rotate);
            if ($rotOk) { $workSrc = $rotTmp; } else { @unlink($rotTmp); $rotTmp = null; }
        }

        $voided = [];
        $voidErrors = [];

        try {
            if ($mode === 'void') {
                $entries = @scandir($outputDir) ?: [];
                foreach ($entries as $e) {
                    if ($e === '.' || $e === '..') continue;
                    $fullOld = $outputDir . DIRECTORY_SEPARATOR . $e;
                    if (!is_file($fullOld)) continue;
                    $oldExt = strtolower(pathinfo($e, PATHINFO_EXTENSION));
                    if (!in_array($oldExt, DR_ALLOWED_EXT, true)) continue; // 只處理圖片/PDF，避免誤動同名的Excel等其他檔案
                    $oldBody = pathinfo($e, PATHINFO_FILENAME);
                    if (mb_strpos($oldBody, '作廢') !== false) continue;
                    if (!drIsSameType($oldBody, $prefix, $bomNumber, $suffix, $isBoss)) continue;

                    try {
                        $voidName = drUniqueVoidName($outputDir, $oldBody, $oldExt);
                        $tmpAnnotated = drTmpDir() . '/void_' . uniqid() . '.' . $oldExt;
                        $wmOk = false;
                        try { $wmOk = eg_att_annotate_file($fullOld, $tmpAnnotated, ['watermark' => '作廢 ' . date('Y.m.d')]); }
                        catch (Throwable $ie) { $wmOk = false; }

                        if ($wmOk && is_file($tmpAnnotated)) {
                            if (copy($tmpAnnotated, $outputDir . DIRECTORY_SEPARATOR . $voidName)) {
                                @unlink($fullOld);
                                $voided[] = ['file' => $e, 'newFile' => $voidName, 'watermarked' => true];
                            } else {
                                $voidErrors[] = ['file' => $e, 'message' => '蓋章後搬移失敗'];
                            }
                            @unlink($tmpAnnotated);
                        } else {
                            // 蓋章失敗（例如格式不支援）→ 僅改名，不擋住作廢
                            if (@rename($fullOld, $outputDir . DIRECTORY_SEPARATOR . $voidName)) {
                                $voided[] = ['file' => $e, 'newFile' => $voidName, 'watermarked' => false];
                            } else {
                                $voidErrors[] = ['file' => $e, 'message' => '改名失敗'];
                            }
                        }
                    } catch (Throwable $ex2) {
                        $voidErrors[] = ['file' => $e, 'message' => $ex2->getMessage()];
                    }
                }

                $destPath = $outputDir . DIRECTORY_SEPARATOR . $newName;
                if (!copy($workSrc, $destPath)) throw new Exception('新檔搬移失敗（目標資料夾是否可寫入？）');
                @unlink($srcPath);
            } else {
                $destPath = $outputDir . DIRECTORY_SEPARATOR . $newName;
                if (is_file($destPath)) @unlink($destPath);
                if (!copy($workSrc, $destPath)) throw new Exception('新檔搬移失敗（目標資料夾是否可寫入？）');
                @unlink($srcPath);
            }

            if ($rotTmp) @unlink($rotTmp);

            echo json_encode([
                'success'     => true,
                'newName'     => $newName,
                'warning'     => $warning,
                'voidedFiles' => $voided,
                'voidErrors'  => $voidErrors,
            ]);
        } catch (Throwable $ex) {
            if ($rotTmp) @unlink($rotTmp);
            echo json_encode(['success' => false, 'message' => $ex->getMessage()]);
        }
        break;

    default:
        http_response_code(400);
        echo json_encode(['success' => false, 'message' => '未知的操作']);
}
