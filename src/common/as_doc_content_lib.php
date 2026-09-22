<?php
/**
 * 二階（含各階）AS 文件「線上版內容」共用庫 ── 唯一實作，禁止各頁自刻 ──
 *
 * 做什麼：讓程序書這種整份文件直接在網頁上編輯（像 Word 一樣改字型字級顏色縮排、
 * 插入可縮放裁切的圖片、畫可再編輯的流程圖），取代「線下用 Word 編→上傳→轉 PDF 看」。
 *
 * ── 三個必須先講清楚的設計決定 ──────────────────────────────────────────
 *
 * ① 內容綁「版次」不綁「文件」（`as_doc_content.version_id`）
 *    AS9100 的舊版必須永遠印得出「當時的樣子」。內容若掛在文件上，改版就會把舊版的
 *    內容一起改掉，事後補不回來。所以一個版次一份內容，改版＝adc_version_fork()
 *    把內容與資產**連實體檔案一起複製**過去；之後在新版動流程圖，舊版印出來完全不變。
 *    （只複製資料列而共用實體檔是不夠的——改流程圖會覆寫那個檔，舊版就跟著變了。）
 *
 * ② 內容 HTML 裡只放 `<img data-asset="N">`，不存 src、也不存檔名
 *    src 由 adc_hydrate_html()／前端 hydrate() 依資產編號即時組出。這是鐵律5
 *    （DB 只存識別值、路徑即時組）＋安全（使用者送來的 src 不可信）兩件事一起解決。
 *
 * ③ 圖片裁切只存「裁切框參數」，不動原檔（`as_doc_asset.crop_json`）
 *    裁過頭可以還原、改版也能重新裁。實際裁切在輸出時用 GD 做並快取。
 *
 * 前端：resource/js/eg_richtext.js 的 profile 'doc'（工具列與白名單）
 *       views/ADM/_flow_editor_ui.php（Fabric 流程圖編輯器）
 * 後端清洗：src/common/richtext_lib.php 的 eg_richtext_sanitize($html, $max, 'doc')
 *       ⚠ 鐵律8：前端清過不算，**存檔前一定要再清一次**（內容會原樣輸出成 HTML）。
 */

if (!defined('EG_AS_DOC_CONTENT_LIB')) {
define('EG_AS_DOC_CONTENT_LIB', 1);

require_once __DIR__ . '/richtext_lib.php';
require_once __DIR__ . '/attach_lib.php';

/** 內容長度上限（字元）。程序書實測純文字約 4,600 字，含標籤的 HTML 約 100KB，故給 60 萬 */
define('ADC_MAX_LEN', 600000);
/** 單一資產檔案大小上限 */
define('ADC_MAX_BYTES', 20 * 1024 * 1024);
/** 允許的圖檔副檔名（只收圖，不必再擋 .php/.exe 這種——不在清單內一律拒收） */
define('ADC_IMG_EXT', ['png', 'jpg', 'jpeg', 'gif']);

/* ════════════════════════════════════════════════════════════════════════
   建表
   ⚠ DDL 在 MySQL 會造成隱式 commit，本專案已為此踩過兩次
     （eg_org_save 2026-08-03、資料稽核 2026-09-21：外層 commit() 爆
      "There is no active transaction"，而資料其實已經寫進去了＝畫面說失敗其實成功）。
     所以：先 SHOW TABLES 確認不存在、且確認目前不在交易中，才下 DDL。
   ════════════════════════════════════════════════════════════════════════ */
function adc_ensure_schema(PDO $db): void
{
    static $done = false;
    if ($done) return;

    $need = [];
    foreach (['as_doc_content', 'as_doc_asset'] as $t) {
        try {
            $st = $db->prepare("SHOW TABLES LIKE ?");
            $st->execute([$t]);
            if ($st->fetchColumn() === false) $need[] = $t;
        } catch (Throwable $e) { return; }
    }
    if (!$need) { $done = true; return; }
    if ($db->inTransaction()) return;   // 交易中不下 DDL（隱式 commit），下一次請求再建

    try {
        if (in_array('as_doc_content', $need, true)) {
            $db->exec("CREATE TABLE IF NOT EXISTS as_doc_content (
                id INT AUTO_INCREMENT PRIMARY KEY,
                version_id INT NOT NULL COMMENT 'as_document_version.id：內容綁版次，舊版才凍結得住',
                doc_id INT NOT NULL COMMENT 'as_document.id（冗餘，查詢方便）',
                content_html MEDIUMTEXT NULL COMMENT '線上內容（已過 doc profile 清洗）',
                is_primary TINYINT(1) NOT NULL DEFAULT 0 COMMENT '1=此版次以線上內容為正本（檢視與列印走線上）',
                page_size VARCHAR(10) NOT NULL DEFAULT 'A4',
                orientation VARCHAR(12) NOT NULL DEFAULT 'portrait',
                import_src VARCHAR(255) NULL COMMENT '從哪個 Word 檔匯入的',
                import_report_json MEDIUMTEXT NULL COMMENT '匯入後的未轉換項目清單',
                imported_at DATETIME NULL,
                created_by INT NULL, created_at DATETIME NOT NULL,
                updated_by INT NULL, updated_at DATETIME NULL,
                UNIQUE KEY uk_ver (version_id),
                KEY idx_doc (doc_id)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COMMENT='AS文件線上版內容（一個版次一份）'");
        }
        if (in_array('as_doc_asset', $need, true)) {
            $db->exec("CREATE TABLE IF NOT EXISTS as_doc_asset (
                id INT AUTO_INCREMENT PRIMARY KEY,
                content_id INT NOT NULL COMMENT 'as_doc_content.id',
                kind VARCHAR(10) NOT NULL DEFAULT 'image' COMMENT 'image=圖片／flow=流程圖(另存fabric工作檔)',
                file_name VARCHAR(190) NOT NULL COMMENT '只存檔名，完整路徑即時組（鐵律5）',
                orig_name VARCHAR(255) NULL,
                flow_json VARCHAR(190) NULL COMMENT 'kind=flow 的 fabric 工作檔檔名，所以之後還能再編輯',
                crop_json VARCHAR(255) NULL COMMENT '裁切框 {x,y,w,h} 0~1 比例；NULL=不裁切。只存參數不動原檔',
                mime VARCHAR(60) NULL,
                bytes INT NULL,
                created_by INT NULL, created_at DATETIME NOT NULL,
                updated_at DATETIME NULL,
                KEY idx_content (content_id)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COMMENT='AS文件線上版的圖片與流程圖'");
        }
        $done = true;
    } catch (Throwable $e) { error_log('[adc] ensure_schema: ' . $e->getMessage()); }
}

/** 檔案目錄（保證存在）。整批搬家改設定值 as_doc_content_dir 一處即可（鐵律5） */
function adc_dir(PDO $db): string
{
    $dir = eg_attach_dir($db, 'as_doc_content_dir', 'AS文件線上版');
    eg_attach_ensure_dir($dir);
    return $dir;
}

/* ════════════════════════════════════════════════════════════════════════
   權限
   刻意**不另開角色模組**：這是 AS 文件管理的一部分（編輯器是帶參數的子頁，
   依鐵律6 不登記進選單），所以沿用 as_doc 的角色與頁面權限，只多一個功能碼
   asdoc_edit_content。不要挪用既有的 asdoc_edit_online——那是「用本機 Word 開檔」。
   ════════════════════════════════════════════════════════════════════════ */
function adc_perms(PDO $db, int $uid): array
{
    require_once __DIR__ . '/asdoc_lib.php';
    $view  = eg_asdoc_user_can($db, $uid, 'view');
    $admin = eg_asdoc_user_can($db, $uid, 'settings');   // 頁面A權或 as_doc 管理者
    $edit  = $admin || eg_asdoc_user_can($db, $uid, 'edit_content');
    return ['view' => $view, 'edit' => $edit, 'admin' => $admin];
}

/* ════════════════════════════════════════════════════════════════════════
   內容
   ════════════════════════════════════════════════════════════════════════ */

/** 取某版次的線上內容（沒有回 null） */
function adc_content_by_version(PDO $db, int $versionId): ?array
{
    if ($versionId <= 0) return null;
    adc_ensure_schema($db);
    try {
        $st = $db->prepare("SELECT * FROM as_doc_content WHERE version_id=?");
        $st->execute([$versionId]);
        $r = $st->fetch(PDO::FETCH_ASSOC);
        return $r ?: null;
    } catch (Throwable $e) { return null; }
}

/** 版次的基本資料（文件編號、名稱、版次、階別、修訂日）——列印表頭表尾與編號都靠它 */
function adc_version_info(PDO $db, int $versionId): ?array
{
    try {
        $st = $db->prepare("SELECT v.id version_id, v.doc_id, v.version, v.revised_date,
                                   v.change_status, v.file_name, v.original_name,
                                   d.doc_no, d.doc_name, d.doc_level, d.is_obsolete, d.is_deleted,
                                   d.current_version_id, d.department_id
                            FROM as_document_version v
                            JOIN as_document d ON d.id = v.doc_id
                            WHERE v.id=?");
        $st->execute([$versionId]);
        $r = $st->fetch(PDO::FETCH_ASSOC);
        if (!$r || (int)$r['is_deleted'] === 1) return null;
        return $r;
    } catch (Throwable $e) { return null; }
}

/** 取或建立內容列（第一次開編輯器時建空的） */
function adc_content_ensure(PDO $db, int $versionId, int $uid): ?array
{
    $c = adc_content_by_version($db, $versionId);
    if ($c) return $c;
    $v = adc_version_info($db, $versionId);
    if (!$v) return null;
    try {
        $st = $db->prepare("INSERT INTO as_doc_content (version_id, doc_id, content_html, created_by, created_at)
                            VALUES (?,?,?,?,NOW())");
        $st->execute([$versionId, (int)$v['doc_id'], null, $uid ?: null]);
    } catch (Throwable $e) { error_log('[adc] content_ensure: ' . $e->getMessage()); return null; }
    return adc_content_by_version($db, $versionId);
}

/**
 * 存內容。$html 一律在這裡再清洗一次（鐵律8），呼叫端不必也不該自己清。
 * @param array $opt is_primary / page_size / orientation / used_assets（內容目前引用到的資產編號）
 * @return array ['ok'=>bool,'msg'=>string,'gc'=>刪掉幾個沒在用的資產]
 */
function adc_content_save(PDO $db, int $versionId, string $html, array $opt, int $uid): array
{
    $c = adc_content_ensure($db, $versionId, $uid);
    if (!$c) return ['ok' => false, 'msg' => '找不到這個版次'];

    $clean = eg_richtext_sanitize($html, ADC_MAX_LEN, 'doc');

    // 引用不到的資產一律不留在內容裡：清洗後實際還在的 data-asset 才算「有在用」，
    // 而且**只能引用自己這份內容的資產**（別份文件的資產編號一律剝掉，
    //  否則把編號改一改就能把別份文件的圖偷過來顯示）。
    $mine = [];
    foreach (adc_assets($db, (int)$c['id']) as $a) $mine[(int)$a['id']] = true;
    $clean = adc_strip_foreign_assets($clean, $mine);

    $used = [];
    if (preg_match_all('/data-asset="(\d+)"/', $clean, $m)) {
        foreach ($m[1] as $x) $used[(int)$x] = true;
    }

    try {
        $sets = ['content_html=?', 'updated_by=?', 'updated_at=NOW()'];
        $args = [$clean !== '' ? $clean : null, $uid ?: null];
        if (array_key_exists('is_primary', $opt)) {     // 沒送＝不要動它（本專案踩過多次）
            $sets[] = 'is_primary=?'; $args[] = !empty($opt['is_primary']) ? 1 : 0;
        }
        if (array_key_exists('page_size', $opt)) {
            $sets[] = 'page_size=?'; $args[] = in_array($opt['page_size'], ['A4', 'A3'], true) ? $opt['page_size'] : 'A4';
        }
        if (array_key_exists('orientation', $opt)) {
            $sets[] = 'orientation=?';
            $args[] = in_array($opt['orientation'], ['portrait', 'landscape'], true) ? $opt['orientation'] : 'portrait';
        }
        $args[] = (int)$c['id'];
        $st = $db->prepare("UPDATE as_doc_content SET " . implode(',', $sets) . " WHERE id=?");
        $st->execute($args);
    } catch (Throwable $e) {
        error_log('[adc] content_save: ' . $e->getMessage());
        return ['ok' => false, 'msg' => '存檔失敗：' . $e->getMessage()];
    }

    $gc = adc_asset_gc($db, (int)$c['id'], array_keys($used));
    return ['ok' => true, 'msg' => '已存檔', 'gc' => $gc, 'content_id' => (int)$c['id']];
}

/** 把「不屬於這份內容」的 <img data-asset> 整個移掉 */
function adc_strip_foreign_assets(string $html, array $ownIds): string
{
    if ($html === '' || strpos($html, 'data-asset') === false) return $html;
    return (string)preg_replace_callback('/<img\b[^>]*data-asset="(\d+)"[^>]*>/i',
        function ($m) use ($ownIds) {
            return isset($ownIds[(int)$m[1]]) ? $m[0] : '';
        }, $html);
}

/* ════════════════════════════════════════════════════════════════════════
   資產（圖片／流程圖）
   ════════════════════════════════════════════════════════════════════════ */

function adc_assets(PDO $db, int $contentId): array
{
    if ($contentId <= 0) return [];
    adc_ensure_schema($db);
    try {
        $st = $db->prepare("SELECT * FROM as_doc_asset WHERE content_id=? ORDER BY id");
        $st->execute([$contentId]);
        return $st->fetchAll(PDO::FETCH_ASSOC) ?: [];
    } catch (Throwable $e) { return []; }
}

function adc_asset(PDO $db, int $assetId): ?array
{
    if ($assetId <= 0) return null;
    adc_ensure_schema($db);
    try {
        $st = $db->prepare("SELECT a.*, c.version_id, c.doc_id
                            FROM as_doc_asset a JOIN as_doc_content c ON c.id = a.content_id
                            WHERE a.id=?");
        $st->execute([$assetId]);
        $r = $st->fetch(PDO::FETCH_ASSOC);
        return $r ?: null;
    } catch (Throwable $e) { return null; }
}

/** 實體檔路徑（即時組，鐵律5） */
function adc_asset_path(PDO $db, array $asset): string
{
    return rtrim(adc_dir($db), '\\/') . DIRECTORY_SEPARATOR . (string)$asset['file_name'];
}

/** fabric 工作檔路徑（流程圖之後還要能再編輯就靠它） */
function adc_flow_path(PDO $db, array $asset): ?string
{
    if (empty($asset['flow_json'])) return null;
    return rtrim(adc_dir($db), '\\/') . DIRECTORY_SEPARATOR . (string)$asset['flow_json'];
}

/** 寫入一個新資產（圖片或流程圖）。$bytes＝檔案內容 */
function adc_asset_store(PDO $db, int $contentId, string $kind, string $bytes, string $ext,
                         ?string $origName, ?string $flowJson, int $uid): array
{
    adc_ensure_schema($db);
    $ext  = strtolower(ltrim($ext, '.'));
    $kind = ($kind === 'flow') ? 'flow' : 'image';
    if (!in_array($ext, ADC_IMG_EXT, true)) return ['ok' => false, 'msg' => '只接受 PNG／JPG／GIF 圖檔'];
    if ($bytes === '')                      return ['ok' => false, 'msg' => '檔案是空的'];
    if (strlen($bytes) > ADC_MAX_BYTES)     return ['ok' => false, 'msg' => '單一檔案不可超過 20MB'];
    // 副檔名對了不代表內容是圖：真的解析一次（擋掉改了副檔名的可執行檔）
    $info = @getimagesizefromstring($bytes);
    if ($info === false)                    return ['ok' => false, 'msg' => '這個檔案不是有效的圖片'];

    $dir = adc_dir($db);
    $name = 'adc' . $contentId . '_' . bin2hex(random_bytes(6)) . '.' . $ext;
    if (@file_put_contents($dir . $name, $bytes) === false) {
        return ['ok' => false, 'msg' => '寫入檔案失敗，請確認附件資料夾可寫入'];
    }
    $flowName = null;
    if ($kind === 'flow' && $flowJson !== null) {
        $flowName = 'adc' . $contentId . '_' . bin2hex(random_bytes(6)) . '.flow.json';
        if (@file_put_contents($dir . $flowName, $flowJson) === false) $flowName = null;
    }
    try {
        $st = $db->prepare("INSERT INTO as_doc_asset
            (content_id, kind, file_name, orig_name, flow_json, mime, bytes, created_by, created_at)
            VALUES (?,?,?,?,?,?,?,?,NOW())");
        $st->execute([$contentId, $kind, $name, $origName ?: null, $flowName,
                      $info['mime'] ?? null, strlen($bytes), $uid ?: null]);
        return ['ok' => true, 'id' => (int)$db->lastInsertId(), 'w' => $info[0], 'h' => $info[1]];
    } catch (Throwable $e) {
        // DB 寫不進去就把剛落地的實體檔刪掉，不然資料夾會留一個沒人認得的孤兒檔
        @unlink($dir . $name);
        if ($flowName) @unlink($dir . $flowName);
        error_log('[adc] asset_store: ' . $e->getMessage());
        return ['ok' => false, 'msg' => '寫入資料庫失敗'];
    }
}

/** 覆寫既有資產的圖檔（流程圖改完重存）。工作檔一起換，舊檔立刻刪掉不累積 */
function adc_asset_update_file(PDO $db, int $assetId, string $bytes, ?string $flowJson): array
{
    $a = adc_asset($db, $assetId);
    if (!$a) return ['ok' => false, 'msg' => '找不到這個資產'];
    if (strlen($bytes) > ADC_MAX_BYTES) return ['ok' => false, 'msg' => '檔案過大'];
    if (@getimagesizefromstring($bytes) === false) return ['ok' => false, 'msg' => '不是有效的圖片'];

    $dir = adc_dir($db);
    if (@file_put_contents($dir . $a['file_name'], $bytes) === false) {
        return ['ok' => false, 'msg' => '寫入檔案失敗'];
    }
    $flowName = $a['flow_json'];
    if ($flowJson !== null) {
        if (!$flowName) $flowName = 'adc' . (int)$a['content_id'] . '_' . bin2hex(random_bytes(6)) . '.flow.json';
        @file_put_contents($dir . $flowName, $flowJson);
    }
    try {
        $st = $db->prepare("UPDATE as_doc_asset SET flow_json=?, bytes=?, updated_at=NOW() WHERE id=?");
        $st->execute([$flowName, strlen($bytes), $assetId]);
    } catch (Throwable $e) { return ['ok' => false, 'msg' => '更新資料庫失敗']; }
    adc_crop_cache_clear($assetId);
    return ['ok' => true];
}

/** 設定／清除裁切框（只存參數，原檔不動，所以隨時可以還原或重裁） */
function adc_asset_crop(PDO $db, int $assetId, ?array $crop): array
{
    $a = adc_asset($db, $assetId);
    if (!$a) return ['ok' => false, 'msg' => '找不到這個資產'];
    $json = null;
    if ($crop !== null) {
        $x = (float)($crop['x'] ?? 0); $y = (float)($crop['y'] ?? 0);
        $w = (float)($crop['w'] ?? 1); $h = (float)($crop['h'] ?? 1);
        // 一律夾回 0~1，並要求裁切後至少還有 2% 寬高（避免裁成 0 像素，GD 會直接失敗）
        $x = max(0, min(0.98, $x)); $y = max(0, min(0.98, $y));
        $w = max(0.02, min(1 - $x, $w)); $h = max(0.02, min(1 - $y, $h));
        if ($x <= 0.0001 && $y <= 0.0001 && $w >= 0.9999 && $h >= 0.9999) $json = null;  // 等於沒裁
        else $json = json_encode(['x' => round($x, 4), 'y' => round($y, 4), 'w' => round($w, 4), 'h' => round($h, 4)]);
    }
    try {
        $st = $db->prepare("UPDATE as_doc_asset SET crop_json=?, updated_at=NOW() WHERE id=?");
        $st->execute([$json, $assetId]);
    } catch (Throwable $e) { return ['ok' => false, 'msg' => '更新失敗']; }
    adc_crop_cache_clear($assetId);
    return ['ok' => true, 'crop' => $json ? json_decode($json, true) : null];
}

function adc_crop_cache_file(int $assetId, string $sig): string
{
    return rtrim(sys_get_temp_dir(), '\\/') . DIRECTORY_SEPARATOR . 'adc_crop_' . $assetId . '_' . $sig . '.png';
}
function adc_crop_cache_clear(int $assetId): void
{
    foreach (glob(rtrim(sys_get_temp_dir(), '\\/') . DIRECTORY_SEPARATOR . 'adc_crop_' . $assetId . '_*.png') ?: [] as $f) {
        @unlink($f);
    }
}

/**
 * 輸出用的實體檔路徑：沒裁切就是原檔；有裁切則用 GD 裁一份並快取。
 * 快取鍵含原檔 mtime 與裁切參數，所以流程圖改過或重新裁過會自動失效。
 */
function adc_asset_display_path(PDO $db, array $asset): string
{
    $src = adc_asset_path($db, $asset);
    if (empty($asset['crop_json']) || !is_file($src)) return $src;
    $crop = json_decode((string)$asset['crop_json'], true);
    if (!is_array($crop)) return $src;

    $sig = substr(md5(((string)@filemtime($src)) . '|' . $asset['crop_json']), 0, 12);
    $cache = adc_crop_cache_file((int)$asset['id'], $sig);
    if (is_file($cache) && filesize($cache) > 0) return $cache;

    $info = @getimagesize($src);
    if (!$info) return $src;
    // 大圖要先把記憶體拉高，否則 GD 直接 fatal（白畫面不是回錯誤）
    $need = (int)($info[0] * $info[1] * 4 * 2.2) + 32 * 1024 * 1024;
    if ($need > 128 * 1024 * 1024) @ini_set('memory_limit', (string)min($need, 1536 * 1024 * 1024));

    $im = null;
    switch ($info[2]) {
        case IMAGETYPE_PNG:  $im = @imagecreatefrompng($src);  break;
        case IMAGETYPE_JPEG: $im = @imagecreatefromjpeg($src); break;
        case IMAGETYPE_GIF:  $im = @imagecreatefromgif($src);  break;
    }
    if (!$im) return $src;
    $sw = imagesx($im); $sh = imagesy($im);
    $cx = (int)round($crop['x'] * $sw); $cy = (int)round($crop['y'] * $sh);
    $cw = max(1, (int)round($crop['w'] * $sw)); $ch = max(1, (int)round($crop['h'] * $sh));
    $dst = @imagecreatetruecolor($cw, $ch);
    if (!$dst) { imagedestroy($im); return $src; }
    imagealphablending($dst, false); imagesavealpha($dst, true);
    $tr = imagecolorallocatealpha($dst, 0, 0, 0, 127);
    imagefilledrectangle($dst, 0, 0, $cw, $ch, $tr);
    imagecopy($dst, $im, 0, 0, $cx, $cy, $cw, $ch);
    @imagepng($dst, $cache);
    imagedestroy($im); imagedestroy($dst);
    return (is_file($cache) && filesize($cache) > 0) ? $cache : $src;
}

/** 刪一個資產（連實體檔） */
function adc_asset_delete(PDO $db, int $assetId): bool
{
    $a = adc_asset($db, $assetId);
    if (!$a) return false;
    $dir = adc_dir($db);
    @unlink($dir . $a['file_name']);
    if (!empty($a['flow_json'])) @unlink($dir . $a['flow_json']);
    adc_crop_cache_clear($assetId);
    try {
        $st = $db->prepare("DELETE FROM as_doc_asset WHERE id=?");
        $st->execute([$assetId]);
        return true;
    } catch (Throwable $e) { return false; }
}

/**
 * 回收沒在內容裡被引用的資產（存檔時呼叫）。
 * 刻意在「存檔成功之後」才做：先刪後存的話，存檔失敗就把圖都弄丟了。
 */
function adc_asset_gc(PDO $db, int $contentId, array $usedIds): int
{
    $used = [];
    foreach ($usedIds as $u) { $u = (int)$u; if ($u > 0) $used[$u] = true; }
    $n = 0;
    foreach (adc_assets($db, $contentId) as $a) {
        if (!isset($used[(int)$a['id']])) { if (adc_asset_delete($db, (int)$a['id'])) $n++; }
    }
    return $n;
}

/**
 * 顯示／列印用：把 <img data-asset="N"> 的 src 依資產編號組回來。
 * @param string $urlBase 取圖的網址前綴，呼叫端給（例：'../../src/store/AsDocContent_API.php?action=asset&id='）
 *                        給空字串＝改成內嵌 data: URI（列印成 PDF 時用，免得抓不到 cookie）
 */
function adc_hydrate_html(PDO $db, string $html, int $contentId, string $urlBase): string
{
    if ($html === '' || strpos($html, 'data-asset') === false) return $html;
    $map = [];
    foreach (adc_assets($db, $contentId) as $a) $map[(int)$a['id']] = $a;

    return (string)preg_replace_callback('/<img\b([^>]*)data-asset="(\d+)"([^>]*)>/i',
        function ($m) use ($map, $db, $urlBase) {
            $id = (int)$m[2];
            if (!isset($map[$id])) return '';          // 引用不到的資產不輸出破圖
            $src = '';
            if ($urlBase === '') {
                $p = adc_asset_display_path($db, $map[$id]);
                if (is_file($p)) {
                    $mime = (string)(@getimagesize($p)['mime'] ?? 'image/png');
                    $src = 'data:' . $mime . ';base64,' . base64_encode((string)file_get_contents($p));
                }
            } else {
                $src = $urlBase . $id;
            }
            if ($src === '') return '';
            return '<img' . $m[1] . 'data-asset="' . $id . '"' . $m[3]
                 . ' src="' . htmlspecialchars($src, ENT_QUOTES) . '" alt="">';
        }, $html);
}

/* ════════════════════════════════════════════════════════════════════════
   版次凍結：改版時把內容複製到新版次
   ════════════════════════════════════════════════════════════════════════ */
/**
 * 把 $fromVid 的線上內容複製一份給 $toVid（含資產與**實體檔案**）。
 * 一定要連檔案一起複製：只複製資料列而共用同一個實體檔的話，
 * 在新版改流程圖會直接覆寫那個檔，**舊版印出來的內容就跟著變了**，
 * 而且完全看不出來——AS9100 的舊版紀錄從此不可信。
 */
function adc_version_fork(PDO $db, int $fromVid, int $toVid, int $uid): array
{
    $src = adc_content_by_version($db, $fromVid);
    if (!$src) return ['ok' => false, 'msg' => '來源版次沒有線上內容'];
    if (adc_content_by_version($db, $toVid)) return ['ok' => false, 'msg' => '目標版次已經有線上內容了'];
    $tv = adc_version_info($db, $toVid);
    if (!$tv) return ['ok' => false, 'msg' => '找不到目標版次'];

    adc_ensure_schema($db);
    $dir = adc_dir($db);
    try {
        $st = $db->prepare("INSERT INTO as_doc_content
            (version_id, doc_id, content_html, is_primary, page_size, orientation, created_by, created_at)
            VALUES (?,?,?,?,?,?,?,NOW())");
        $st->execute([$toVid, (int)$tv['doc_id'], $src['content_html'], (int)$src['is_primary'],
                      $src['page_size'], $src['orientation'], $uid ?: null]);
        $newId = (int)$db->lastInsertId();
    } catch (Throwable $e) { return ['ok' => false, 'msg' => '複製內容失敗：' . $e->getMessage()]; }

    // 資產：實體檔複製成新檔名，並把內容裡的 data-asset 換成新編號
    $idMap = [];
    foreach (adc_assets($db, (int)$src['id']) as $a) {
        $ext = strtolower(pathinfo($a['file_name'], PATHINFO_EXTENSION)) ?: 'png';
        $nn  = 'adc' . $newId . '_' . bin2hex(random_bytes(6)) . '.' . $ext;
        if (!@copy($dir . $a['file_name'], $dir . $nn)) continue;
        $nf = null;
        if (!empty($a['flow_json'])) {
            $nf = 'adc' . $newId . '_' . bin2hex(random_bytes(6)) . '.flow.json';
            if (!@copy($dir . $a['flow_json'], $dir . $nf)) $nf = null;
        }
        try {
            $st = $db->prepare("INSERT INTO as_doc_asset
                (content_id, kind, file_name, orig_name, flow_json, crop_json, mime, bytes, created_by, created_at)
                VALUES (?,?,?,?,?,?,?,?,?,NOW())");
            $st->execute([$newId, $a['kind'], $nn, $a['orig_name'], $nf, $a['crop_json'],
                          $a['mime'], $a['bytes'], $uid ?: null]);
            $idMap[(int)$a['id']] = (int)$db->lastInsertId();
        } catch (Throwable $e) { @unlink($dir . $nn); if ($nf) @unlink($dir . $nf); }
    }
    if ($idMap && !empty($src['content_html'])) {
        $html = preg_replace_callback('/data-asset="(\d+)"/', function ($m) use ($idMap) {
            $old = (int)$m[1];
            return 'data-asset="' . ($idMap[$old] ?? $old) . '"';
        }, (string)$src['content_html']);
        try {
            $st = $db->prepare("UPDATE as_doc_content SET content_html=? WHERE id=?");
            $st->execute([$html, $newId]);
        } catch (Throwable $e) {}
    }
    return ['ok' => true, 'content_id' => $newId, 'assets' => count($idMap)];
}

} // EG_AS_DOC_CONTENT_LIB
