<?php
// =============================================================================
// src/common/attachment_lib.php — 附件標籤 / 格式驗證 / Excel·Word 轉 PDF / 輸出加註 共用函式庫
// 依據：attachment tag watermark spec.md（2026-07-07 實作）
//
// 功能分區：
//   1. schema ensure（沿用 qa_notify.php 的 runtime ALTER 模式，只新增欄位不刪改）
//   2. 上傳驗證（副檔名 + MIME 雙重檢查、20MB 上限）
//   3. 標籤查詢 / 預設標籤 / 異動 log
//   4. 待確認上傳（pending）：暫存目錄 + meta.json sidecar，Excel/Word 轉 PDF 流程用
//   5. 轉 PDF：主要方案 LibreOffice headless；備援 HTML → headless Chrome/Edge 列印
//   6. 輸出加註：角落標註（標籤名 + 備注）＋ 溯源浮水印（斜向平鋪）
//      PDF 用 FPDI+TCPDF（vendor 已安裝），圖片用 GD（Apache 端 php.ini 已啟用）
//   7. 檢視快取版（僅角落標註，無溯源浮水印）產生與更新
//
// 錯誤處理原則：全部 error_log，不 die()、不輸出到畫面；加註失敗時降級（保留原檔可看）。
// =============================================================================

if (defined('EG_ATT_LIB_LOADED')) return;
define('EG_ATT_LIB_LOADED', 1);

define('EG_ATT_MAX_SIZE', 20 * 1024 * 1024); // 20MB（規格：浮水印處理 CPU 成本考量）
define('EG_ATT_SOFFICE', 'C:\\Program Files\\LibreOffice\\program\\soffice.exe');
define('EG_ATT_NOTE_MAX', 100); // 備注顯示上限字數（超過截斷加…）
// 備援轉檔用 headless 瀏覽器（Office → HTML → PDF；LibreOffice 損毀/未裝時自動改走此路）
define('EG_ATT_CHROME', 'C:\\Program Files\\Google\\Chrome\\Application\\chrome.exe');
define('EG_ATT_EDGE',   'C:\\Program Files (x86)\\Microsoft\\Edge\\Application\\msedge.exe');

require_once __DIR__ . '/../../vendor/autoload.php';

/** 現在時刻（台北時區）。PHP 預設時區為 UTC（既有陷阱），加註/浮水印顯示時間一律用此函式，避免差 8 小時。 */
function eg_att_now(): string {
    try { return (new DateTime('now', new DateTimeZone('Asia/Taipei')))->format('Y-m-d H:i'); }
    catch (Throwable $e) { return date('Y-m-d H:i'); }
}

// ---------------------------------------------------------------------------
// 1. schema ensure（首次使用自動補欄位；以 system_settings 記版本避免每次檢查）
// ---------------------------------------------------------------------------
function eg_att_ensure_schema(PDO $db): void {
    static $done = false;
    if ($done) return;
    $done = true;
    try {
        $st = $db->prepare("SELECT setting_value FROM system_settings WHERE setting_key='att_schema_ver' LIMIT 1");
        $st->execute();
        if ($st->fetchColumn() === '1') return;
    } catch (Throwable $e) { /* system_settings 不存在時直接跑檢查 */ }

    $addCol = function (string $table, string $col, string $ddl) use ($db) {
        try {
            $cols = $db->query("SHOW COLUMNS FROM `$table`")->fetchAll(PDO::FETCH_COLUMN);
            if (!in_array($col, $cols, true)) $db->exec("ALTER TABLE `$table` ADD COLUMN $ddl");
        } catch (Throwable $e) { error_log("[att_lib] ensure column $table.$col failed: " . $e->getMessage()); }
    };
    $addCol('live_event_file', 'tag_id',            "`tag_id` INT NULL COMMENT 'attachment_tags.id' AFTER `file_path`");
    $addCol('live_event_file', 'description',       "`description` VARCHAR(255) NULL COMMENT '附件說明(非必填)' AFTER `tag_id`");
    $addCol('live_event_file', 'original_filename', "`original_filename` VARCHAR(255) NULL COMMENT '原始檔名(轉PDF前)' AFTER `description`");
    $addCol('live_event_file', 'file_size',         "`file_size` INT NULL AFTER `original_filename`");
    $addCol('live_event_file', 'uploaded_by',       "`uploaded_by` INT NULL AFTER `file_size`");
    $addCol('live_event_file', 'preview_path',      "`preview_path` VARCHAR(500) NULL COMMENT '角落標註檢視快取版' AFTER `uploaded_by`");
    $addCol('qa_abnormal_attachments', 'tag_id',            "`tag_id` INT NULL COMMENT 'attachment_tags.id' AFTER `file_path`");
    $addCol('qa_abnormal_attachments', 'description',       "`description` VARCHAR(255) NULL COMMENT '附件說明(非必填)' AFTER `tag_id`");
    $addCol('qa_abnormal_attachments', 'original_filename', "`original_filename` VARCHAR(255) NULL COMMENT '原始檔名(轉PDF前)' AFTER `description`");
    $addCol('qa_abnormal_attachments', 'file_size',         "`file_size` INT NULL AFTER `original_filename`");
    $addCol('qa_abnormal_attachments', 'preview_path',      "`preview_path` VARCHAR(500) NULL COMMENT '角落標註檢視快取版' AFTER `file_size`");
    $addCol('live_event',        'show_attach_inline', "`show_attach_inline` TINYINT(1) NOT NULL DEFAULT 0 COMMENT '附件2件(含)以下時檢視畫面直接顯示(僅電腦版)'");
    $addCol('qa_abnormal_order', 'show_attach_inline', "`show_attach_inline` TINYINT(1) NOT NULL DEFAULT 0 COMMENT '附件2件(含)以下時檢視畫面直接顯示(僅電腦版)'");

    try {
        $db->prepare("INSERT INTO system_settings (setting_key, setting_value) VALUES ('att_schema_ver','1')
                      ON DUPLICATE KEY UPDATE setting_value='1'")->execute();
    } catch (Throwable $e) { /* 無 system_settings 表則每次檢查，可接受 */ }
}

// ---------------------------------------------------------------------------
// 2. 上傳驗證：只允許 圖片(jpg/jpeg/png)、PDF、Excel(xlsx/xls)、Word(docx/doc)
// ---------------------------------------------------------------------------
function eg_att_allowed_ext(): array { return ['jpg','jpeg','png','pdf','xlsx','xls','docx','doc']; }
function eg_att_office_ext(): array  { return ['xlsx','xls','docx','doc']; }
function eg_att_is_image(string $ext): bool { return in_array(strtolower($ext), ['jpg','jpeg','png'], true); }

// MIME 白名單（副檔名 → 可接受的 MIME 清單；finfo 對 office 舊格式常回 application/x-ole-storage）
function eg_att_mime_whitelist(): array {
    return [
        'jpg'  => ['image/jpeg'],
        'jpeg' => ['image/jpeg'],
        'png'  => ['image/png'],
        'pdf'  => ['application/pdf'],
        'xlsx' => ['application/vnd.openxmlformats-officedocument.spreadsheetml.sheet', 'application/zip', 'application/octet-stream'],
        'xls'  => ['application/vnd.ms-excel', 'application/x-ole-storage', 'application/CDFV2', 'application/octet-stream'],
        'docx' => ['application/vnd.openxmlformats-officedocument.wordprocessingml.document', 'application/zip', 'application/octet-stream'],
        'doc'  => ['application/msword', 'application/x-ole-storage', 'application/CDFV2', 'application/octet-stream'],
    ];
}

/**
 * 驗證 $_FILES['x'] 單檔。回傳 ['ok'=>bool, 'msg'=>string, 'ext'=>string]
 */
function eg_att_validate_upload(array $file): array {
    if (!isset($file['tmp_name']) || !is_uploaded_file($file['tmp_name'])) return ['ok'=>false,'msg'=>'無上傳檔案','ext'=>''];
    if (($file['error'] ?? -1) !== UPLOAD_ERR_OK) return ['ok'=>false,'msg'=>'上傳失敗(錯誤碼 '.$file['error'].')','ext'=>''];
    $size = (int)($file['size'] ?? 0);
    if ($size <= 0) return ['ok'=>false,'msg'=>'檔案為空','ext'=>''];
    if ($size > EG_ATT_MAX_SIZE) return ['ok'=>false,'msg'=>'檔案超過 20MB 上限','ext'=>''];
    $ext = strtolower(pathinfo((string)$file['name'], PATHINFO_EXTENSION));
    if (!in_array($ext, eg_att_allowed_ext(), true)) {
        return ['ok'=>false,'msg'=>'只允許 圖片(jpg/png)、PDF、Excel、Word 格式','ext'=>$ext];
    }
    // MIME 雙重檢查
    $mime = '';
    try {
        $fi = new finfo(FILEINFO_MIME_TYPE);
        $mime = (string)$fi->file($file['tmp_name']);
    } catch (Throwable $e) { error_log('[att_lib] finfo failed: ' . $e->getMessage()); }
    $wl = eg_att_mime_whitelist()[$ext] ?? [];
    if ($mime !== '' && $wl && !in_array($mime, $wl, true)) {
        return ['ok'=>false,'msg'=>"檔案內容($mime)與副檔名 .$ext 不符",'ext'=>$ext];
    }
    return ['ok'=>true,'msg'=>'','ext'=>$ext];
}

// ---------------------------------------------------------------------------
// 3. 標籤查詢 / 預設標籤 / 異動 log
// ---------------------------------------------------------------------------
function eg_att_tags(PDO $db, string $scope, bool $activeOnly = true): array {
    $sql = "SELECT id, scope, name, allow_webpush, allow_telegram, require_watermark, is_default, is_active
            FROM attachment_tags WHERE scope = ?" . ($activeOnly ? " AND is_active = 1" : "") . " ORDER BY is_default DESC, name";
    $st = $db->prepare($sql);
    $st->execute([$scope]);
    return $st->fetchAll(PDO::FETCH_ASSOC);
}

function eg_att_default_tag_id(PDO $db, string $scope): ?int {
    $st = $db->prepare("SELECT id FROM attachment_tags WHERE scope=? AND is_default=1 AND is_active=1 LIMIT 1");
    $st->execute([$scope]);
    $id = $st->fetchColumn();
    return $id ? (int)$id : null;
}

function eg_att_tag_row(PDO $db, int $tagId): ?array {
    $st = $db->prepare("SELECT * FROM attachment_tags WHERE id=?");
    $st->execute([$tagId]);
    $r = $st->fetch(PDO::FETCH_ASSOC);
    return $r ?: null;
}

/** 標籤/附件異動紀錄（誰、何時、改了什麼 舊值→新值） */
function eg_att_log(PDO $db, ?int $actorId, string $targetType, int $targetId, string $field, $old, $new): void {
    try {
        $db->prepare("INSERT INTO tag_change_logs (actor_id, target_type, target_id, field, old_value, new_value) VALUES (?,?,?,?,?,?)")
           ->execute([$actorId, $targetType, $targetId, $field, $old === null ? null : (string)$old, $new === null ? null : (string)$new]);
    } catch (Throwable $e) { error_log('[att_lib] log failed: ' . $e->getMessage()); }
}

// ---------------------------------------------------------------------------
// 4. 待確認上傳（pending）：{tempRoot}/att_pending/{uploadId}/ + meta.json
//    Excel/Word：上傳 → (選工作表) → 轉 PDF → 預覽 → 確認(commit) / 取消(discard)
// ---------------------------------------------------------------------------
function eg_att_pending_dir(string $tempRoot, string $uploadId = ''): string {
    $d = rtrim($tempRoot, '\\/') . DIRECTORY_SEPARATOR . 'att_pending';
    return $uploadId === '' ? $d : $d . DIRECTORY_SEPARATOR . $uploadId;
}

function eg_att_pending_id_ok(string $id): bool { return (bool)preg_match('/^[a-f0-9]{32}$/', $id); }

/**
 * 建立 pending 上傳（$file 需已通過 eg_att_validate_upload）。
 * 回傳 ['upload_id','orig_name','ext','size','need_sheets'=>bool,'sheets'=>[],'need_convert'=>bool]
 */
function eg_att_pending_create(string $tempRoot, array $file, string $ext): ?array {
    $uid = bin2hex(random_bytes(16));
    $dir = eg_att_pending_dir($tempRoot, $uid);
    if (!is_dir($dir) && !@mkdir($dir, 0775, true)) { error_log("[att_lib] mkdir failed: $dir"); return null; }
    $src = $dir . DIRECTORY_SEPARATOR . 'orig.' . $ext;
    if (!@move_uploaded_file($file['tmp_name'], $src)) { error_log("[att_lib] move_uploaded_file failed: $src"); return null; }
    $meta = [
        'orig_name' => (string)$file['name'],
        'ext'       => $ext,
        'size'      => (int)$file['size'],
        'src'       => $src,
        'pdf'       => null,
        'created'   => time(),
    ];
    $sheets = [];
    $needSheets = false;
    if (in_array($ext, ['xlsx','xls'], true)) {
        try {
            $reader = \PhpOffice\PhpSpreadsheet\IOFactory::createReaderForFile($src);
            if (method_exists($reader, 'listWorksheetNames')) {
                $sheets = $reader->listWorksheetNames($src);
                $needSheets = count($sheets) > 1;
            }
        } catch (Throwable $e) { error_log('[att_lib] list sheets failed: ' . $e->getMessage()); }
    }
    $meta['sheets'] = $sheets;
    @file_put_contents($dir . DIRECTORY_SEPARATOR . 'meta.json', json_encode($meta, JSON_UNESCAPED_UNICODE));
    return [
        'upload_id'    => $uid,
        'orig_name'    => $meta['orig_name'],
        'ext'          => $ext,
        'size'         => $meta['size'],
        'need_sheets'  => $needSheets,
        'sheets'       => $sheets,
        'need_convert' => in_array($ext, eg_att_office_ext(), true),
    ];
}

function eg_att_pending_get(string $tempRoot, string $uploadId): ?array {
    if (!eg_att_pending_id_ok($uploadId)) return null;
    $f = eg_att_pending_dir($tempRoot, $uploadId) . DIRECTORY_SEPARATOR . 'meta.json';
    if (!is_file($f)) return null;
    $m = json_decode((string)@file_get_contents($f), true);
    return is_array($m) ? $m : null;
}

function eg_att_pending_discard(string $tempRoot, string $uploadId): void {
    if (!eg_att_pending_id_ok($uploadId)) return;
    $dir = eg_att_pending_dir($tempRoot, $uploadId);
    if (!is_dir($dir)) return;
    foreach ((array)@scandir($dir) as $f) {
        if ($f === '.' || $f === '..') continue;
        @unlink($dir . DIRECTORY_SEPARATOR . $f);
    }
    @rmdir($dir);
}

/** 清超過 24 小時的 pending 暫存（各 API 載入時順手呼叫） */
function eg_att_pending_sweep(string $tempRoot): void {
    $base = eg_att_pending_dir($tempRoot);
    if (!is_dir($base)) return;
    foreach ((array)@scandir($base) as $d) {
        if (!eg_att_pending_id_ok((string)$d)) continue;
        $meta = eg_att_pending_get($tempRoot, $d);
        if (!$meta || (time() - (int)($meta['created'] ?? 0)) > 86400) eg_att_pending_discard($tempRoot, $d);
    }
}

/**
 * 將 pending 的 office 檔轉成 PDF（Excel 可指定工作表子集合）。
 * 回傳 PDF 絕對路徑，失敗回 null。
 */
function eg_att_pending_convert(string $tempRoot, string $uploadId, ?array $sheets = null): ?string {
    $meta = eg_att_pending_get($tempRoot, $uploadId);
    if (!$meta) return null;
    $dir = eg_att_pending_dir($tempRoot, $uploadId);
    $src = $meta['src'];
    $ext = $meta['ext'];

    // Excel 勾選部分工作表：先產生只含選取工作表的中間檔，再轉 PDF（中間檔用完即刪）
    $convSrc = $src;
    $subset  = null;
    if (in_array($ext, ['xlsx','xls'], true) && $sheets && count($meta['sheets'] ?? []) > 1
        && count($sheets) < count($meta['sheets'])) {
        try {
            $reader = \PhpOffice\PhpSpreadsheet\IOFactory::createReaderForFile($src);
            $reader->setLoadSheetsOnly($sheets);
            $wb = $reader->load($src);
            $subset = $dir . DIRECTORY_SEPARATOR . 'subset.xlsx';
            $writer = new \PhpOffice\PhpSpreadsheet\Writer\Xlsx($wb);
            $writer->save($subset);
            $wb->disconnectWorksheets();
            $convSrc = $subset;
        } catch (Throwable $e) {
            error_log('[att_lib] excel subset failed: ' . $e->getMessage());
            $convSrc = $src; // 降級：整本轉
        }
    }

    // 主要方案：LibreOffice headless；失敗（未安裝/安裝損毀）→ 備援：HTML → headless Chrome/Edge 列印 PDF
    $pdf = eg_att_soffice_convert($convSrc, $dir);
    if (!$pdf) {
        $convExt = $subset ? 'xlsx' : $ext;
        $pdf = eg_att_fallback_convert($convSrc, $convExt, $dir);
    }
    if ($subset && is_file($subset)) @unlink($subset); // 刪中間檔
    if (!$pdf) return null;

    // 統一命名為 converted.pdf，避免與原檔名混淆
    $final = $dir . DIRECTORY_SEPARATOR . 'converted.pdf';
    if ($pdf !== $final) { @unlink($final); @rename($pdf, $final); }
    $meta['pdf'] = $final;
    @file_put_contents($dir . DIRECTORY_SEPARATOR . 'meta.json', json_encode($meta, JSON_UNESCAPED_UNICODE));
    return $final;
}

/**
 * 確認 pending：把最終檔（office→轉好的 PDF；圖片/PDF→原檔）搬到正式目錄，
 * 並刪除整個 pending 資料夾（原始 office 檔與中間檔一併刪除，系統只保存 PDF）。
 * 回傳 ['path','stored_name','orig_name','size','ext'] 或 null。
 */
function eg_att_pending_commit(string $tempRoot, string $uploadId, string $destDir): ?array {
    $meta = eg_att_pending_get($tempRoot, $uploadId);
    if (!$meta) return null;
    $isOffice = in_array($meta['ext'], eg_att_office_ext(), true);
    $srcFile  = $isOffice ? ($meta['pdf'] ?? null) : $meta['src'];
    if (!$srcFile || !is_file($srcFile)) return null;
    if (!is_dir($destDir) && !@mkdir($destDir, 0775, true)) { error_log("[att_lib] mkdir failed: $destDir"); return null; }
    $ext  = $isOffice ? 'pdf' : $meta['ext'];
    $base = preg_replace('/[^\w\x{4e00}-\x{9fff}]/u', '_', pathinfo($meta['orig_name'], PATHINFO_FILENAME));
    $storedName = $base . '_' . time() . '_' . substr($uploadId, 0, 6) . '.' . $ext;
    $dest = rtrim($destDir, '\\/') . DIRECTORY_SEPARATOR . $storedName;
    if (!@rename($srcFile, $dest)) {
        if (!@copy($srcFile, $dest)) { error_log("[att_lib] commit move failed: $dest"); return null; }
        @unlink($srcFile);
    }
    $size = (int)@filesize($dest);
    eg_att_pending_discard($tempRoot, $uploadId); // 刪原始檔與所有中間檔
    return ['path'=>$dest, 'stored_name'=>$storedName, 'orig_name'=>$meta['orig_name'], 'size'=>$size, 'ext'=>$ext];
}

// ---------------------------------------------------------------------------
// 5. LibreOffice headless 轉 PDF
// ---------------------------------------------------------------------------
/** 轉出 PDF 至 $outDir，回傳 PDF 路徑或 null。用 proc_open 陣列參數避開 cmd 引號地雷。 */
function eg_att_soffice_convert(string $src, string $outDir, int $timeoutSec = 120): ?string {
    if (!is_file(EG_ATT_SOFFICE)) { error_log('[att_lib] LibreOffice not found: ' . EG_ATT_SOFFICE); return null; }
    // 先清掉舊的同名輸出檔，避免殘留檔被誤判為轉檔成功
    $expect = rtrim($outDir, '\\/') . DIRECTORY_SEPARATOR . pathinfo($src, PATHINFO_FILENAME) . '.pdf';
    if (is_file($expect)) @unlink($expect);
    // 每次獨立 profile，避免與桌面版/併發轉檔互鎖
    $profile = rtrim(sys_get_temp_dir(), '\\/') . DIRECTORY_SEPARATOR . 'lo_' . bin2hex(random_bytes(6));
    @mkdir($profile, 0775, true);
    $profileUrl = 'file:///' . str_replace('\\', '/', $profile);
    // SelectPdfVersion=14 → 輸出 PDF 1.4（傳統 xref），FPDI 免費版才能解析後續加註
    $cmd = [
        EG_ATT_SOFFICE, '--headless', '--norestore', '--nolockcheck',
        '-env:UserInstallation=' . $profileUrl,
        '--convert-to', 'pdf:writer_pdf_Export:{"SelectPdfVersion":{"type":"long","value":14}}',
        '--outdir', $outDir, $src,
    ];
    $proc = @proc_open($cmd, [1 => ['pipe','w'], 2 => ['pipe','w']], $pipes);
    if (!is_resource($proc)) { error_log('[att_lib] proc_open soffice failed'); return null; }
    stream_set_blocking($pipes[1], false);
    stream_set_blocking($pipes[2], false);
    $t0 = time(); $out = '';
    while (true) {
        $st = proc_get_status($proc);
        $out .= (string)stream_get_contents($pipes[1]) . (string)stream_get_contents($pipes[2]);
        if (!$st['running']) break;
        if (time() - $t0 > $timeoutSec) { proc_terminate($proc); error_log('[att_lib] soffice timeout: ' . $src); break; }
        usleep(200000);
    }
    fclose($pipes[1]); fclose($pipes[2]);
    proc_close($proc);
    // 清 profile（盡力而為）
    eg_att_rrmdir($profile);
    $pdf = rtrim($outDir, '\\/') . DIRECTORY_SEPARATOR . pathinfo($src, PATHINFO_FILENAME) . '.pdf';
    if (is_file($pdf) && filesize($pdf) > 0) return $pdf;
    error_log('[att_lib] soffice convert failed: ' . $src . ' output=' . substr($out, 0, 500));
    return null;
}

// ---------------------------------------------------------------------------
// 5b. 備援轉檔：Office → HTML（PhpSpreadsheet / PHPWord）→ headless Chrome/Edge 列印 PDF
//     已知限制：圖表會遺失、複雜合併儲存格還原度較差（規格已載明並接受）
// ---------------------------------------------------------------------------
function eg_att_fallback_convert(string $src, string $ext, string $outDir): ?string {
    $html = rtrim($outDir, '\\/') . DIRECTORY_SEPARATOR . 'conv_' . bin2hex(random_bytes(4)) . '.html';
    try {
        if (in_array($ext, ['xlsx', 'xls'], true)) {
            $wb = \PhpOffice\PhpSpreadsheet\IOFactory::load($src);
            $writer = new \PhpOffice\PhpSpreadsheet\Writer\Html($wb);
            $writer->writeAllSheets();
            $writer->save($html);
            $wb->disconnectWorksheets();
        } elseif (in_array($ext, ['docx', 'doc'], true)) {
            $word = \PhpOffice\PhpWord\IOFactory::load($src, $ext === 'doc' ? 'MsDoc' : 'Word2007');
            $writer = \PhpOffice\PhpWord\IOFactory::createWriter($word, 'HTML');
            $writer->save($html);
        } else {
            return null;
        }
        // 補 UTF-8 標記，避免瀏覽器以錯誤編碼渲染中文
        $h = (string)@file_get_contents($html);
        if ($h !== '' && stripos($h, 'charset') === false) {
            $h = preg_replace('/<head([^>]*)>/i', '<head$1><meta charset="utf-8">', $h, 1) ?: $h;
            @file_put_contents($html, $h);
        }
    } catch (Throwable $e) {
        error_log('[att_lib] fallback to-html failed: ' . $e->getMessage());
        @unlink($html);
        return null;
    }
    $pdf = rtrim($outDir, '\\/') . DIRECTORY_SEPARATOR . pathinfo($src, PATHINFO_FILENAME) . '.pdf';
    $ok = eg_att_browser_pdf($html, $pdf);
    @unlink($html); // 刪中間檔
    return ($ok && is_file($pdf) && filesize($pdf) > 0) ? $pdf : null;
}

/** headless Chrome / Edge 將本機 HTML 印成 PDF */
function eg_att_browser_pdf(string $htmlPath, string $outPdf, int $timeoutSec = 90): bool {
    $exe = null;
    foreach ([EG_ATT_CHROME, EG_ATT_EDGE] as $c) { if (is_file($c)) { $exe = $c; break; } }
    if (!$exe) { error_log('[att_lib] no headless browser found for fallback'); return false; }
    $profile = rtrim(sys_get_temp_dir(), '\\/') . DIRECTORY_SEPARATOR . 'hb_' . bin2hex(random_bytes(6));
    $url = 'file:///' . str_replace('\\', '/', $htmlPath);
    $cmd = [
        $exe, '--headless', '--disable-gpu', '--no-first-run', '--disable-extensions',
        '--user-data-dir=' . $profile,
        '--no-pdf-header-footer',
        '--print-to-pdf=' . $outPdf,
        $url,
    ];
    $proc = @proc_open($cmd, [1 => ['pipe','w'], 2 => ['pipe','w']], $pipes);
    if (!is_resource($proc)) { error_log('[att_lib] proc_open browser failed'); return false; }
    stream_set_blocking($pipes[1], false);
    stream_set_blocking($pipes[2], false);
    $t0 = time(); $out = '';
    while (true) {
        $st = proc_get_status($proc);
        $out .= (string)stream_get_contents($pipes[1]) . (string)stream_get_contents($pipes[2]);
        if (!$st['running']) break;
        if (time() - $t0 > $timeoutSec) { proc_terminate($proc); error_log('[att_lib] browser pdf timeout'); break; }
        usleep(200000);
    }
    fclose($pipes[1]); fclose($pipes[2]);
    proc_close($proc);
    eg_att_rrmdir($profile);
    if (is_file($outPdf) && filesize($outPdf) > 0) return true;
    error_log('[att_lib] browser pdf failed: ' . substr($out, 0, 400));
    return false;
}

function eg_att_rrmdir(string $dir): void {
    if (!is_dir($dir)) return;
    foreach ((array)@scandir($dir) as $f) {
        if ($f === '.' || $f === '..') continue;
        $p = $dir . DIRECTORY_SEPARATOR . $f;
        is_dir($p) ? eg_att_rrmdir($p) : @unlink($p);
    }
    @rmdir($dir);
}

// ---------------------------------------------------------------------------
// 6. 輸出加註：$opt = [
//      'tag_name' => '標籤名',                    // 左上角標貼（一律顯示）
//      'note' => '備注', 'note_by' => '填寫人', 'note_at' => 'Y-m-d H:i',  // 頁面下緣（有填才顯示）
//      'watermark' => null | '員工代碼 時間 單號', // 半透明斜向平鋪（溯源層，快取版不帶）
//    ]
// ---------------------------------------------------------------------------
function eg_att_note_line(array $opt): string {
    $note = trim((string)($opt['note'] ?? ''));
    if ($note === '') return '';
    if (mb_strlen($note, 'UTF-8') > EG_ATT_NOTE_MAX) $note = mb_substr($note, 0, EG_ATT_NOTE_MAX, 'UTF-8') . '…';
    $by = trim((string)($opt['note_by'] ?? ''));
    $at = trim((string)($opt['note_at'] ?? ''));
    $suffix = trim($by . ' ' . $at);
    return '備注：' . $note . ($suffix !== '' ? '（' . $suffix . '）' : '');
}

/**
 * PDF 加註用中文字型：優先把 Windows 標楷體(kaiu.ttf)轉為 TCPDF 內嵌字型（首次自動轉換並快取），
 * 讓任何檢視器（含手機/Telegram 內建閱讀器）都能顯示中文；失敗時退回內建 cid0ct（不內嵌，部分檢視器缺字）。
 */
function eg_att_pdf_font(): string {
    static $font = null;
    if ($font !== null) return $font;
    $font = 'cid0ct';
    try {
        $src = 'C:\\Windows\\Fonts\\kaiu.ttf';
        if (is_file($src)) {
            $name = \TCPDF_FONTS::addTTFfont($src, 'TrueTypeUnicode', '', 32);
            if (is_string($name) && $name !== '') $font = $name;
        }
    } catch (Throwable $e) { error_log('[att_lib] embed pdf font failed: ' . $e->getMessage()); }
    return $font;
}

/** PDF 加註（FPDI+TCPDF）。回傳 bool。來源為壓縮交叉參照表的 PDF 會失敗 → 呼叫端降級。 */
function eg_att_annotate_pdf(string $src, string $dst, array $opt): bool {
    try {
        $pdf = new \setasign\Fpdi\Tcpdf\Fpdi();
        $pdf->setPrintHeader(false);
        $pdf->setPrintFooter(false);
        $pdf->SetAutoPageBreak(false, 0);
        $pageCount = $pdf->setSourceFile($src);
        $tagName  = trim((string)($opt['tag_name'] ?? ''));
        $noteLine = eg_att_note_line($opt);
        $wm       = trim((string)($opt['watermark'] ?? ''));
        for ($i = 1; $i <= $pageCount; $i++) {
            $tpl  = $pdf->importPage($i);
            $size = $pdf->getTemplateSize($tpl);
            $w = $size['width']; $h = $size['height'];
            $pdf->AddPage($w > $h ? 'L' : 'P', [$w, $h]);
            $pdf->useTemplate($tpl);
            // 溯源浮水印：半透明、斜向、平鋪整頁
            if ($wm !== '') {
                $pdf->SetAlpha(0.14);
                $pdf->SetFont(eg_att_pdf_font(), '', 16);
                $pdf->SetTextColor(80, 80, 80);
                $stepX = 85; $stepY = 42;
                for ($y = 10; $y < $h + 40; $y += $stepY) {
                    for ($x = -30; $x < $w + 30; $x += $stepX) {
                        $pdf->StartTransform();
                        $pdf->Rotate(35, $x, $y);
                        $pdf->Text($x, $y, $wm);
                        $pdf->StopTransform();
                    }
                }
                $pdf->SetAlpha(1);
            }
            // 左上角：標籤名稱標貼（淡色底、深色字）
            if ($tagName !== '') {
                $pdf->SetFont(eg_att_pdf_font(), '', 8);
                $tw = $pdf->GetStringWidth($tagName) + 4;
                $pdf->SetFillColor(226, 238, 254);
                $pdf->SetDrawColor(160, 195, 235);
                $pdf->RoundedRect(3, 3, $tw, 5.5, 1.2, '1111', 'DF');
                $pdf->SetTextColor(30, 80, 140);
                $pdf->SetXY(3, 3.6);
                $pdf->Cell($tw, 4.2, $tagName, 0, 0, 'C');
            }
            // 頁面下緣：附件備注 + 填寫人 + 時間（字體小、不遮主要內容）
            if ($noteLine !== '') {
                $pdf->SetFont(eg_att_pdf_font(), '', 7.5);
                $pdf->SetTextColor(110, 110, 110);
                $pdf->SetXY(3, $h - 7);
                $pdf->Cell($w - 6, 4, $noteLine, 0, 0, 'R');
            }
        }
        // TCPDF Output('F') 對含中文路徑 OK（直接 file_put_contents）
        @file_put_contents($dst, $pdf->Output('', 'S'));
        return is_file($dst) && filesize($dst) > 0;
    } catch (Throwable $e) {
        error_log('[att_lib] annotate pdf failed (' . basename($src) . '): ' . $e->getMessage());
        @unlink($dst);
        return false;
    }
}

/** 圖片加註（GD；jpg/png）。回傳 bool。 */
function eg_att_annotate_image(string $src, string $dst, array $opt): bool {
    try {
        if (!function_exists('imagecreatefromjpeg')) { error_log('[att_lib] GD not loaded'); return false; }
        $ext = strtolower(pathinfo($src, PATHINFO_EXTENSION));
        $im = ($ext === 'png') ? @imagecreatefrompng($src) : @imagecreatefromjpeg($src);
        if (!$im) { error_log('[att_lib] image open failed: ' . $src); return false; }
        if ($ext === 'png') { imagealphablending($im, true); imagesavealpha($im, true); }
        $w = imagesx($im); $h = imagesy($im);
        $font = eg_att_cjk_font();
        $tagName  = trim((string)($opt['tag_name'] ?? ''));
        $noteLine = eg_att_note_line($opt);
        $wm       = trim((string)($opt['watermark'] ?? ''));
        $base = max(12, (int)round(min($w, $h) / 55)); // 字級隨圖片尺寸縮放

        if ($wm !== '' && $font) {
            $wmColor = imagecolorallocatealpha($im, 90, 90, 90, 95); // 高透明
            $wmSize  = $base * 1.5;
            $bbox = imagettfbbox($wmSize, 0, $font, $wm);
            $textW = abs($bbox[4] - $bbox[0]);
            $stepX = (int)max($textW + $wmSize * 4, $w / 4);
            $stepY = (int)max($wmSize * 7, $h / 8);
            for ($y = (int)$wmSize * 2; $y < $h + $stepY; $y += $stepY) {
                for ($x = -$stepX; $x < $w + $stepX; $x += $stepX) {
                    imagettftext($im, $wmSize, 35, $x, $y, $wmColor, $font, $wm);
                }
            }
        }
        if ($tagName !== '' && $font) {
            $sz = $base;
            $bbox = imagettfbbox($sz, 0, $font, $tagName);
            $tw = abs($bbox[4] - $bbox[0]); $th = abs($bbox[5] - $bbox[1]);
            $pad = (int)($sz * 0.5);
            $bg = imagecolorallocatealpha($im, 226, 238, 254, 25);
            $bd = imagecolorallocate($im, 160, 195, 235);
            $tx = imagecolorallocate($im, 30, 80, 140);
            imagefilledrectangle($im, 8, 8, 8 + $tw + $pad * 2, 8 + $th + $pad * 2, $bg);
            imagerectangle($im, 8, 8, 8 + $tw + $pad * 2, 8 + $th + $pad * 2, $bd);
            imagettftext($im, $sz, 0, 8 + $pad, 8 + $pad + $th, $tx, $font, $tagName);
        }
        if ($noteLine !== '' && $font) {
            $sz = max(10, (int)($base * 0.8));
            $bbox = imagettfbbox($sz, 0, $font, $noteLine);
            $tw = abs($bbox[4] - $bbox[0]); $th = abs($bbox[5] - $bbox[1]);
            $x = max(8, $w - $tw - 10);
            $y = $h - 8;
            $bg = imagecolorallocatealpha($im, 255, 255, 255, 45);
            imagefilledrectangle($im, $x - 4, $y - $th - 4, min($w - 2, $x + $tw + 4), $y + 4, $bg);
            $tx = imagecolorallocate($im, 90, 90, 90);
            imagettftext($im, $sz, 0, $x, $y, $tx, $font, $noteLine);
        }
        $ok = ($ext === 'png') ? imagepng($im, $dst) : imagejpeg($im, $dst, 88);
        imagedestroy($im);
        return $ok && is_file($dst);
    } catch (Throwable $e) {
        error_log('[att_lib] annotate image failed: ' . $e->getMessage());
        return false;
    }
}

/** 可用的中文字型（GD 用；msjh.ttc 微軟正黑優先） */
function eg_att_cjk_font(): ?string {
    foreach (['C:\\Windows\\Fonts\\msjh.ttc', 'C:\\Windows\\Fonts\\msjhbd.ttc', 'C:\\Windows\\Fonts\\mingliu.ttc', 'C:\\Windows\\Fonts\\simsun.ttc'] as $f) {
        if (is_file($f)) return $f;
    }
    error_log('[att_lib] no CJK font found');
    return null;
}

/** 依副檔名分派加註。回傳 bool。 */
function eg_att_annotate_file(string $src, string $dst, array $opt): bool {
    $ext = strtolower(pathinfo($src, PATHINFO_EXTENSION));
    if ($ext === 'pdf') return eg_att_annotate_pdf($src, $dst, $opt);
    if (eg_att_is_image($ext)) return eg_att_annotate_image($src, $dst, $opt);
    return false; // 其他格式不加註（office 已於上傳階段轉 PDF，理論上不會到這）
}

// ---------------------------------------------------------------------------
// 7. 檢視快取版（僅角落標註，無溯源浮水印）
// ---------------------------------------------------------------------------
/**
 * 為附件產生/更新角落標註快取版並回寫 preview_path。
 * $scope: 'announcement'|'abnormal'；$row 需含 id, file_path, tag_id, description, uploaded_by(或 created_by), uploaded_at(或 created_at)
 * 失敗回 null（呼叫端降級顯示原檔），成功回快取版路徑。
 */
function eg_att_make_preview(PDO $db, string $scope, array $row): ?string {
    $src = (string)($row['file_path'] ?? '');
    if ($src === '' || !is_file($src)) return null;
    $ext = strtolower(pathinfo($src, PATHINFO_EXTENSION));
    if ($ext !== 'pdf' && !eg_att_is_image($ext)) return null;

    $tagName = '';
    if (!empty($row['tag_id'])) {
        $t = eg_att_tag_row($db, (int)$row['tag_id']);
        if ($t) $tagName = $t['name'];
    }
    $uploaderName = '';
    $uploaderId = (int)($row['uploaded_by'] ?? $row['created_by'] ?? 0);
    if ($uploaderId) {
        try {
            $st = $db->prepare("SELECT user_cname FROM user WHERE id=?");
            $st->execute([$uploaderId]);
            $uploaderName = (string)$st->fetchColumn();
        } catch (Throwable $e) {}
    }
    $noteAt = (string)($row['uploaded_at'] ?? $row['created_at'] ?? date('Y-m-d H:i'));
    $dst = preg_replace('/\.' . preg_quote($ext, '/') . '$/i', '', $src) . '__view.' . $ext;
    $ok = eg_att_annotate_file($src, $dst, [
        'tag_name' => $tagName,
        'note'     => (string)($row['description'] ?? ''),
        'note_by'  => $uploaderName,
        'note_at'  => mb_substr($noteAt, 0, 16, 'UTF-8'),
        'watermark'=> null, // 檢視快取版不帶溯源浮水印
    ]);
    if (!$ok) return null;

    $table = $scope === 'announcement' ? 'live_event_file' : 'qa_abnormal_attachments';
    try {
        $db->prepare("UPDATE `$table` SET preview_path=? WHERE id=?")->execute([$dst, (int)$row['id']]);
    } catch (Throwable $e) { error_log('[att_lib] save preview_path failed: ' . $e->getMessage()); }
    return $dst;
}

/** 依 id 重新產生快取版（標籤或備注變更時呼叫） */
function eg_att_refresh_preview(PDO $db, string $scope, int $attId): ?string {
    $table = $scope === 'announcement' ? 'live_event_file' : 'qa_abnormal_attachments';
    try {
        $st = $db->prepare("SELECT * FROM `$table` WHERE id=?");
        $st->execute([$attId]);
        $row = $st->fetch(PDO::FETCH_ASSOC);
        if (!$row) return null;
        // 先刪舊快取
        if (!empty($row['preview_path']) && is_file($row['preview_path'])) @unlink($row['preview_path']);
        return eg_att_make_preview($db, $scope, $row);
    } catch (Throwable $e) {
        error_log('[att_lib] refresh preview failed: ' . $e->getMessage());
        return null;
    }
}
